<?php
/**
 * cron.php — Daily reminder & escalation processor
 *
 * Runs once per day via SiteGround cPanel cron job.
 *
 * Recommended cron command (runs at 8 AM Sydney time daily):
 *   0 8 * * * /usr/bin/php /home/USERNAME/public_html/cron.php >> /home/USERNAME/logs/cron.log 2>&1
 *
 * Or, if your host runs cron in UTC (SiteGround does), adjust the hour:
 *   0 22 * * * /usr/bin/php /home/USERNAME/public_html/cron.php ...
 *   (22:00 UTC = 08:00 AEDT / 07:00 AEST depending on DST)
 *
 * Can also be triggered via HTTP with a secret key for testing:
 *   https://yourdomain.com/cron.php?key=YOUR_CRON_SECRET
 *
 * Set CRON_SECRET in config.php:
 *   define('CRON_SECRET', 'some-long-random-string');
 *
 * What this script does:
 *   1. Loads all PENDING submission_stages
 *   2. For each, loads the parent form_stage to check reminder_days / escalation_days
 *   3. Reminder logic : if days_elapsed >= reminder_days AND
 *                       (never reminded OR days_since_last_reminder >= reminder_days)
 *                       → re-send approve/reject emails, update last_reminder_sent_at + reminder_count
 *   4. Escalation logic: if days_elapsed >= escalation_days AND
 *                        no 'escalation_sent' audit_log entry for this submission_stage
 *                        → for each pending approver, look up their escalation_manager_id;
 *                          email that contact with 'you are listed as escalation contact for X' wording.
 *                          If no contact set for an approver → fall back to all admin users.
 *                          Also re-notifies pending approvers. Logs to audit_log.
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────
define('CRON_CONTEXT', true); // Lets included files know we're in CLI/cron context

$rootDir = __DIR__;
require_once $rootDir . '/config.php';
require_once $rootDir . '/supabase.php';
require_once $rootDir . '/includes/email.php';

// ── Auth: allow CLI or HTTP with secret key ────────────────────────────────────
$isCli  = (php_sapi_name() === 'cli');
$isHttp = !$isCli;

if ($isHttp) {
    if (!defined('CRON_SECRET') || empty($_GET['key']) || $_GET['key'] !== CRON_SECRET) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

// ── Logging helper ─────────────────────────────────────────────────────────────
function cronLog(string $msg): void
{
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] {$msg}\n";
}

cronLog('=== Aurora Cron START ===');

// ── Initialise Supabase client ─────────────────────────────────────────────────
$sb = new Supabase();

// ── 1. Load all pending submission_stages ─────────────────────────────────────
$pendingStages = $sb->from('submission_stages')
    ->select('*')
    ->eq('status', 'pending')
    ->execute() ?? [];

cronLog('Pending submission_stages found: ' . count($pendingStages));

if (empty($pendingStages)) {
    cronLog('Nothing to process. Exiting.');
    cronLog('=== Aurora Cron END ===');
    exit;
}

// ── 2. Batch load related data ─────────────────────────────────────────────────

// Unique form_stage IDs → load form_stages for reminder/escalation config
$formStageIds = array_values(array_unique(array_column($pendingStages, 'stage_id')));
$formStageRows = !empty($formStageIds)
    ? ($sb->from('form_stages')->select('*')->in('id', $formStageIds)->execute() ?? [])
    : [];
$formStageMap = []; // [stage_id => form_stage row]
foreach ($formStageRows as $fs) {
    $formStageMap[$fs['id']] = $fs;
}

// Unique submission IDs → load submissions
$submissionIds = array_values(array_unique(array_column($pendingStages, 'submission_id')));
$submissionRows = !empty($submissionIds)
    ? ($sb->from('submissions')->select('*')->in('id', $submissionIds)->execute() ?? [])
    : [];
$submissionMap = []; // [submission_id => submission row]
foreach ($submissionRows as $s) {
    $submissionMap[$s['id']] = $s;
}

// Unique form IDs from submissions → load forms
$formIds = array_values(array_unique(array_column($submissionRows, 'form_id')));
$formRows = !empty($formIds)
    ? ($sb->from('forms')->select('*')->in('id', $formIds)->execute() ?? [])
    : [];
$formMap = []; // [form_id => form row]
foreach ($formRows as $f) {
    $formMap[$f['id']] = $f;
}

// Load all pending (unused) approval_tokens for these submission_stage IDs
$subStageIds = array_column($pendingStages, 'id');
$tokenRows = !empty($subStageIds)
    ? ($sb->from('approval_tokens')->select('*')->in('submission_stage_id', $subStageIds)->execute() ?? [])
    : [];

// Index tokens: [submission_stage_id][user_id][action] = token row
$tokenIndex = [];
foreach ($tokenRows as $t) {
    if ($t['is_used']) continue; // skip used tokens
    $tokenIndex[$t['submission_stage_id']][$t['recipient_user_id']][$t['action']] = $t;
}

// Load users for those recipient IDs (to get email + display_name)
$recipientIds = array_values(array_unique(array_column($tokenRows, 'recipient_user_id')));
$userRows = !empty($recipientIds)
    ? ($sb->from('users')->select('*')->in('id', $recipientIds)->execute() ?? [])
    : [];
$userMap = []; // [user_id => user row]
foreach ($userRows as $u) {
    $userMap[$u['id']] = $u;
}

// Load all admin users (for escalation emails)
$adminRows = $sb->from('users')->select('*')->eq('role', 'admin')->execute() ?? [];

// Load audit_log entries for escalation checks (action = 'escalation_sent')
$escalationLogs = $sb->from('audit_log')->select('*')->eq('action', 'escalation_sent')->execute() ?? [];
$escalatedStageIds = []; // set of submission_stage_id values that already had escalation sent
foreach ($escalationLogs as $log) {
    $escalatedStageIds[$log['submission_stage_id']] = true;
}

$now = new DateTimeImmutable('now', new DateTimeZone('Australia/Sydney'));

// ── 3. Process each pending submission_stage ───────────────────────────────────
$remindersSent   = 0;
$escalationsSent = 0;

foreach ($pendingStages as $ss) {
    $ssId          = $ss['id'];
    $stageId       = $ss['stage_id'];
    $submissionId  = $ss['submission_id'];
    $formStage     = $formStageMap[$stageId]     ?? null;
    $submission    = $submissionMap[$submissionId] ?? null;
    $form          = $submission ? ($formMap[$submission['form_id']] ?? null) : null;

    if (!$formStage || !$submission) {
        cronLog("  SKIP ss={$ssId}: missing form_stage or submission");
        continue;
    }

    $reminderDays   = $formStage['reminder_days']   ?? null;
    $escalationDays = $formStage['escalation_days'] ?? null;

    // Days since the stage started (use started_at if available, else submission submitted_at)
    $startedRef = $ss['started_at'] ?? $submission['submitted_at'] ?? null;
    if (!$startedRef) continue;

    $startedAt   = new DateTimeImmutable($startedRef);
    $daysElapsed = (int)$startedAt->diff($now)->days;

    cronLog("Processing ss={$ssId} | stage=" . ($formStage['stage_name'] ?? '?') . " | elapsed={$daysElapsed}d | reminder={$reminderDays} | escalate={$escalationDays}");

    // Collect pending approvers for this stage (users with unused approve+reject token pair)
    $pendingApprovers = [];
    $pendingEmails    = [];
    if (isset($tokenIndex[$ssId])) {
        foreach ($tokenIndex[$ssId] as $userId => $actions) {
            if (isset($actions['approve'], $actions['reject'])) {
                $user = $userMap[$userId] ?? null;
                if ($user) {
                    $pendingApprovers[] = [
                        'user'         => $user,
                        'approveToken' => $actions['approve']['token'],
                        'rejectToken'  => $actions['reject']['token'],
                    ];
                    $pendingEmails[] = $user['email'];
                }
            }
        }
    }

    if (empty($pendingApprovers)) {
        cronLog("  SKIP ss={$ssId}: no pending approvers with active tokens");
        continue;
    }

    // ── REMINDER ──────────────────────────────────────────────────────────────
    if ($reminderDays && $daysElapsed >= $reminderDays) {
        $lastReminderAt  = $ss['last_reminder_sent_at'] ?? null;
        $currentCount    = (int)($ss['reminder_count'] ?? 0);
        $shouldRemind    = false;

        if (!$lastReminderAt) {
            // Never reminded — send first reminder
            $shouldRemind = true;
        } else {
            $lastReminder      = new DateTimeImmutable($lastReminderAt);
            $daysSinceReminder = (int)$lastReminder->diff($now)->days;
            if ($daysSinceReminder >= $reminderDays) {
                $shouldRemind = true;
            }
        }

        if ($shouldRemind) {
            cronLog("  → Sending reminder #{$currentCount} to " . count($pendingApprovers) . " approver(s)");
            foreach ($pendingApprovers as $pa) {
                sendReminderEmail(
                    $pa['user'],
                    $submission,
                    $formStage,
                    $form,
                    $pa['approveToken'],
                    $pa['rejectToken'],
                    $currentCount + 1,
                    $daysElapsed          // 8th param: days overdue
                );
            }

            // Update last_reminder_sent_at and reminder_count
            $sb->from('submission_stages')
                ->eq('id', $ssId)
                ->update([
                    'last_reminder_sent_at' => $now->format('c'),
                    'reminder_count'        => $currentCount + 1,
                ]);

            $remindersSent++;
        }
    }

    // ── ESCALATION ────────────────────────────────────────────────────────────
    if ($escalationDays && $daysElapsed >= $escalationDays && empty($escalatedStageIds[$ssId])) {

        // Build a map: escalation_manager_id → [approver user rows]
        // If an approver has no escalation contact set, they go into the fallback bucket.
        $managerToApprovers = []; // [manager_user_id => [approver user row, ...]]
        $fallbackApprovers  = []; // approvers with no escalation contact → email all admins

        foreach ($pendingApprovers as $pa) {
            $approverUser = $pa['user'];
            $managerId    = $approverUser['escalation_manager_id'] ?? null;
            if ($managerId) {
                $managerToApprovers[$managerId][] = $approverUser;
            } else {
                $fallbackApprovers[] = $approverUser;
            }
        }

        // Load any manager users not already in $userMap
        $managerIdsNeeded = array_diff(array_keys($managerToApprovers), array_keys($userMap));
        if (!empty($managerIdsNeeded)) {
            $extraUsers = $sb->from('users')->select('*')->in('id', $managerIdsNeeded)->execute() ?? [];
            foreach ($extraUsers as $eu) {
                $userMap[$eu['id']] = $eu;
            }
        }

        // Send to each assigned escalation contact
        foreach ($managerToApprovers as $managerId => $approverUsers) {
            $manager = $userMap[$managerId] ?? null;
            if (!$manager) {
                cronLog("  WARN: escalation contact {$managerId} not found — routing to fallback");
                $fallbackApprovers = array_merge($fallbackApprovers, $approverUsers);
                continue;
            }
            $approverList = array_map(fn($u) => [
                'name'  => $u['display_name'] ?? $u['email'],
                'email' => $u['email'],
            ], $approverUsers);
            cronLog("  → Escalation: notifying contact " . ($manager['display_name'] ?? $manager['email'])
                  . " for " . count($approverList) . " approver(s)");
            sendEscalationEmail($manager, $submission, $formStage, $form, $daysElapsed, $approverList, false);
        }

        // Fallback: no escalation contact set → email all admins
        if (!empty($fallbackApprovers)) {
            $fallbackList = array_map(fn($u) => [
                'name'  => $u['display_name'] ?? $u['email'],
                'email' => $u['email'],
            ], $fallbackApprovers);
            cronLog("  → Escalation fallback: notifying " . count($adminRows) . " admin(s) for "
                  . count($fallbackList) . " approver(s) with no contact set");
            foreach ($adminRows as $admin) {
                sendEscalationEmail($admin, $submission, $formStage, $form, $daysElapsed, $fallbackList, true);
            }
        }

        // Re-notify pending approvers (same as a reminder)
        foreach ($pendingApprovers as $pa) {
            sendReminderEmail(
                $pa['user'],
                $submission,
                $formStage,
                $form,
                $pa['approveToken'],
                $pa['rejectToken'],
                (int)($ss['reminder_count'] ?? 0) + 1,
                $daysElapsed
            );
        }

        // Log to audit_log so we don't escalate again
        $allEscalatedEmails = [];
        foreach ($managerToApprovers as $managerId => $approverUsers) {
            if (isset($userMap[$managerId])) {
                $allEscalatedEmails[] = $userMap[$managerId]['email'];
            }
        }
        foreach ($adminRows as $admin) {
            if (!empty($fallbackApprovers)) {
                $allEscalatedEmails[] = $admin['email'];
            }
        }

        $sb->from('audit_log')->insert([
            'submission_id'       => $submissionId,
            'submission_stage_id' => $ssId,
            'action'              => 'escalation_sent',
            'via'                 => 'cron',
            'meta'                => json_encode([
                'days_elapsed'       => $daysElapsed,
                'pending_emails'     => $pendingEmails,
                'escalated_contacts' => $allEscalatedEmails,
                'fallback_used'      => !empty($fallbackApprovers),
            ]),
        ]);

        $escalatedStageIds[$ssId] = true; // prevent double-send in same run
        $escalationsSent++;
    }
}

cronLog("Reminders sent: {$remindersSent}");
cronLog("Escalations sent: {$escalationsSent}");
cronLog('=== Aurora Cron END ===');
