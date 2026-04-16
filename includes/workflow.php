<?php
/**
 * workflow.php — Stage engine for Aurora Form Workflow
 *
 * This file contains all the logic for moving a submission through
 * its approval stages. It is required by webhook.php and approve.php.
 *
 * Public functions (prefix wf_ to avoid naming conflicts):
 *
 *   wf_kickOffStage($submissionId, $stage)
 *     Called when a submission enters a stage for the first time.
 *     Resolves recipients, creates tokens, sends emails.
 *
 *   wf_checkStageCompletion($submissionStageId)
 *     Called after every approval decision.
 *     Checks whether the stage is now complete and advances if so.
 *
 *   wf_advanceSubmission($submissionId)
 *     Finds and kicks off the next stage, or marks the submission done.
 *
 * Internal helpers (not called externally):
 *   _wf_resolveRecipients($stageId, $formData)
 *   _wf_evaluateRoutingRules($formId, $formData, $currentStageOrder)
 *   _wf_evaluateCondition($condition, $formData)
 *
 * Requires: config.php, supabase.php, includes/email.php
 * Requires $sb (Supabase instance) to be in scope via global.
 */

require_once __DIR__ . '/email.php';

// ─────────────────────────────────────────────────────────────────────────────
// INTERNAL: Resolve the list of users who should act on a stage
//
// Handles all three recipient types:
//   user_id   → direct user lookup
//   group_id  → expand group to individual members
//   field_key → resolve from form_data by key, find user by email
//
// Then applies any active delegations (swaps delegator → delegate).
// Returns a flat, deduplicated array of user rows.
// Each row may have an extra '_delegated_from' key for audit logging.
// ─────────────────────────────────────────────────────────────────────────────

function _wf_resolveRecipients(string $stageId, array $formData): array
{
    global $sb;

    $recipients = $sb->from('stage_recipients')
        ->select('*')
        ->eq('stage_id', $stageId)
        ->execute();

    if (!$recipients) return [];

    $resolvedUsers = []; // keyed by user_id to deduplicate early

    foreach ($recipients as $r) {

        // ── Direct user ──────────────────────────────────────
        if (!empty($r['user_id'])) {
            $rows = $sb->from('users')->select('*')->eq('id', $r['user_id'])->execute();
            if ($rows && isset($rows[0]) && $rows[0]['is_active']) {
                $resolvedUsers[$r['user_id']] = $rows[0];
            }

        // ── Group: expand to members ─────────────────────────
        } elseif (!empty($r['group_id'])) {
            $members = $sb->from('group_members')
                ->select('*')
                ->eq('group_id', $r['group_id'])
                ->execute();
            if ($members) {
                foreach ($members as $m) {
                    $rows = $sb->from('users')->select('*')->eq('id', $m['user_id'])->execute();
                    if ($rows && isset($rows[0]) && $rows[0]['is_active']) {
                        $resolvedUsers[$m['user_id']] = $rows[0];
                    }
                }
            }

        // ── Dynamic: resolve from form data ──────────────────
        } elseif (!empty($r['field_key'])) {
            $email = trim((string)($formData[$r['field_key']] ?? ''));
            if ($email) {
                $rows = $sb->from('users')->select('*')->eq('email', $email)->execute();
                if ($rows && isset($rows[0]) && $rows[0]['is_active']) {
                    $resolvedUsers[$rows[0]['id']] = $rows[0];
                }
                // If no user found, log and skip (can't send without a users record)
                if (!$rows || !isset($rows[0])) {
                    error_log("[Aurora Workflow] Dynamic recipient email '$email' from field '{$r['field_key']}' not found in users table.");
                }
            }
        }
    }

    // ── Apply delegations ────────────────────────────────────
    // For each resolved user, check if they have an active delegation today.
    // If yes, route to their delegate instead.

    $now        = date('c'); // ISO 8601 — same format Supabase stores
    $finalUsers = [];

    foreach ($resolvedUsers as $userId => $user) {
        $delegations = $sb->from('delegations')
            ->select('*')
            ->eq('delegator_id', $userId)
            ->eq('is_active', 'true')
            ->execute();

        $delegated = false;
        if ($delegations) {
            foreach ($delegations as $d) {
                // Check window in PHP (REST filter can't do compound AND/OR easily)
                if ($d['starts_at'] <= $now && $d['ends_at'] >= $now) {
                    $delegateRows = $sb->from('users')
                        ->select('*')
                        ->eq('id', $d['delegate_id'])
                        ->execute();
                    if ($delegateRows && isset($delegateRows[0]) && $delegateRows[0]['is_active']) {
                        $delegate = $delegateRows[0];
                        $delegate['_delegated_from'] = $user; // carry original for audit log
                        $finalUsers[$d['delegate_id']] = $delegate;
                        $delegated = true;
                    }
                    break; // first matching delegation wins
                }
            }
        }

        if (!$delegated) {
            $finalUsers[$userId] = $user;
        }
    }

    return array_values($finalUsers);
}

// ─────────────────────────────────────────────────────────────────────────────
// INTERNAL: Evaluate routing rules for a form after a stage completes
//
// Returns a target stage_id to jump to, or null for sequential progression.
// Rules are checked in descending priority order (highest priority first).
// Only forward jumps are allowed (target stage_order > current stage_order).
// ─────────────────────────────────────────────────────────────────────────────

function _wf_evaluateRoutingRules(string $formId, array $formData, int $currentStageOrder): ?string
{
    global $sb;

    $rules = $sb->from('routing_rules')
        ->select('*')
        ->eq('form_id', $formId)
        ->eq('is_active', 'true')
        ->order('priority', false) // descending
        ->execute();

    if (!$rules) return null;

    foreach ($rules as $rule) {
        $condition = $rule['condition_json'];
        if (is_string($condition)) {
            $condition = json_decode($condition, true) ?? [];
        }

        if (!empty($condition) && _wf_evaluateCondition($condition, $formData)) {
            $targetId = $rule['target_stage_id'] ?? null;
            if ($targetId) {
                // Only jump forward
                $targetRows = $sb->from('form_stages')
                    ->select('*')
                    ->eq('id', $targetId)
                    ->execute();
                if ($targetRows && isset($targetRows[0])) {
                    if ((int)$targetRows[0]['stage_order'] > $currentStageOrder) {
                        return $targetId;
                    }
                }
            }
        }
    }

    return null;
}

// ─────────────────────────────────────────────────────────────────────────────
// INTERNAL: Evaluate a single condition object against form data
//
// Supported formats:
//   Simple:   { "field": "Amount", "op": ">", "value": "5000" }
//   Compound: { "and": [ ...conditions ] }
//             { "or":  [ ...conditions ] }
//
// Supported ops: = != > < >= <= contains not_empty
// ─────────────────────────────────────────────────────────────────────────────

function _wf_evaluateCondition(array $condition, array $formData): bool
{
    // Compound AND
    if (isset($condition['and']) && is_array($condition['and'])) {
        foreach ($condition['and'] as $c) {
            if (!_wf_evaluateCondition($c, $formData)) return false;
        }
        return true;
    }

    // Compound OR
    if (isset($condition['or']) && is_array($condition['or'])) {
        foreach ($condition['or'] as $c) {
            if (_wf_evaluateCondition($c, $formData)) return true;
        }
        return false;
    }

    // Simple comparison
    $field      = $condition['field'] ?? '';
    $op         = $condition['op']    ?? '=';
    $value      = (string)($condition['value'] ?? '');
    $fieldValue = (string)($formData[$field]   ?? '');

    switch ($op) {
        case '=':         return $fieldValue === $value;
        case '!=':        return $fieldValue !== $value;
        case '>':         return is_numeric($fieldValue) && is_numeric($value) && (float)$fieldValue >  (float)$value;
        case '<':         return is_numeric($fieldValue) && is_numeric($value) && (float)$fieldValue <  (float)$value;
        case '>=':        return is_numeric($fieldValue) && is_numeric($value) && (float)$fieldValue >= (float)$value;
        case '<=':        return is_numeric($fieldValue) && is_numeric($value) && (float)$fieldValue <= (float)$value;
        case 'contains':  return str_contains(strtolower($fieldValue), strtolower($value));
        case 'not_empty': return trim($fieldValue) !== '';
        default:          return false;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PUBLIC: Kick off a stage
//
// Called when a submission first enters a stage (from webhook.php for Stage 1,
// or from wf_advanceSubmission() for subsequent stages).
//
// What it does:
//   1. Creates a submission_stages row (status = pending)
//   2. Updates submissions.current_stage_id
//   3. Resolves recipients (groups, delegations, dynamic fields)
//   4. For 'notification' stages: sends info emails, immediately advances
//   5. For 'approval' stages: creates approval tokens, sends request emails
// ─────────────────────────────────────────────────────────────────────────────

function wf_kickOffStage(string $submissionId, array $stage): void
{
    global $sb;

    // Load submission (needed for form_data and form_id)
    $subRows = $sb->from('submissions')->select('*')->eq('id', $submissionId)->execute();
    if (!$subRows || !isset($subRows[0])) {
        error_log("[Aurora Workflow] wf_kickOffStage: submission $submissionId not found.");
        return;
    }
    $submission = $subRows[0];

    // form_data may come back as a JSON string depending on Supabase response
    $formData = $submission['form_data'] ?? [];
    if (is_string($formData)) {
        $formData = json_decode($formData, true) ?? [];
    }

    // Load form
    $formRows = $sb->from('forms')->select('*')->eq('id', $submission['form_id'])->execute();
    $form     = $formRows[0] ?? null;

    // ── 1. Create submission_stages row ──────────────────────
    $ssRows = $sb->from('submission_stages')->insert([
        'submission_id' => $submissionId,
        'stage_id'      => $stage['id'],
        'status'        => 'pending',
        'started_at'    => date('c'),
    ]);

    if (!$ssRows || !isset($ssRows[0])) {
        error_log("[Aurora Workflow] wf_kickOffStage: failed to create submission_stage for stage {$stage['id']}.");
        return;
    }
    $submissionStage = $ssRows[0];

    // ── 2. Update current_stage_id on the submission ─────────
    $sb->from('submissions')
        ->eq('id', $submissionId)
        ->update([
            'current_stage_id' => $stage['id'],
            'status'           => 'in_progress',
        ]);

    // ── 3. Audit log ─────────────────────────────────────────
    $sb->from('audit_log')->insert([
        'submission_id' => $submissionId,
        'action'        => 'stage_started',
        'detail'        => json_encode([
            'stage_id'   => $stage['id'],
            'stage_name' => $stage['name'],
            'stage_type' => $stage['stage_type'],
        ]),
    ]);

    // ── 4. Handle notification stage ─────────────────────────
    // Notification = send info emails, no approval needed, auto-advance.
    if ($stage['stage_type'] === 'notification') {
        $recipients = _wf_resolveRecipients($stage['id'], $formData);
        foreach ($recipients as $user) {
            sendNotificationEmail($user, $submission, $stage, $form);
        }
        // Mark stage complete immediately
        $sb->from('submission_stages')
            ->eq('id', $submissionStage['id'])
            ->update(['status' => 'approved', 'completed_at' => date('c')]);

        $sb->from('audit_log')->insert([
            'submission_id' => $submissionId,
            'action'        => 'stage_auto_approved',
            'detail'        => json_encode(['stage_id' => $stage['id'], 'reason' => 'notification stage']),
        ]);

        wf_advanceSubmission($submissionId);
        return;
    }

    // ── 5. Handle signature stage ─────────────────────────────
    // Signature = send a unique sign link to each recipient.
    // The stage stays pending until sign.php records an 'approved' decision.
    // wf_checkStageCompletion() will advance the submission once the stage is complete.
    if ($stage['stage_type'] === 'signature') {
        $recipients = _wf_resolveRecipients($stage['id'], $formData);

        if (empty($recipients)) {
            error_log("[Aurora Workflow] No recipients for signature stage {$stage['id']} (submission $submissionId). Auto-advancing.");
            $sb->from('submission_stages')
                ->eq('id', $submissionStage['id'])
                ->update(['status' => 'approved', 'completed_at' => date('c')]);
            $sb->from('audit_log')->insert([
                'submission_id' => $submissionId,
                'action'        => 'stage_auto_approved',
                'detail'        => json_encode(['stage_id' => $stage['id'], 'reason' => 'no recipients configured']),
            ]);
            wf_advanceSubmission($submissionId);
            return;
        }

        $expiresAt = date('c', strtotime('+' . TOKEN_EXPIRY_HOURS . ' hours'));

        foreach ($recipients as $user) {
            $signToken = bin2hex(random_bytes(32));

            $sb->from('approval_tokens')->insert([
                'submission_stage_id' => $submissionStage['id'],
                'recipient_user_id'   => $user['id'],
                'token'               => $signToken,
                'action'              => 'sign',
                'expires_at'          => $expiresAt,
            ]);

            sendSignatureRequestEmail($user, $submission, $stage, $form, $signToken);

            if (isset($user['_delegated_from'])) {
                $original = $user['_delegated_from'];
                $sb->from('audit_log')->insert([
                    'submission_id' => $submissionId,
                    'action'        => 'delegation_applied',
                    'detail'        => json_encode([
                        'stage_id'         => $stage['id'],
                        'original_user_id' => $original['id'],
                        'original_name'    => $original['display_name'] ?? $original['email'],
                        'delegate_user_id' => $user['id'],
                        'delegate_name'    => $user['display_name'] ?? $user['email'],
                    ]),
                ]);
            }
        }

        return; // Stage stays 'pending' until sign.php is submitted
    }

    // ── 6. Handle approval stage ──────────────────────────────
    $recipients = _wf_resolveRecipients($stage['id'], $formData);

    // Safety valve: if no recipients found, auto-approve and advance
    if (empty($recipients)) {
        error_log("[Aurora Workflow] No recipients found for stage {$stage['id']} (submission $submissionId). Auto-advancing.");
        $sb->from('submission_stages')
            ->eq('id', $submissionStage['id'])
            ->update(['status' => 'approved', 'completed_at' => date('c')]);
        $sb->from('audit_log')->insert([
            'submission_id' => $submissionId,
            'action'        => 'stage_auto_approved',
            'detail'        => json_encode(['stage_id' => $stage['id'], 'reason' => 'no recipients configured']),
        ]);
        wf_advanceSubmission($submissionId);
        return;
    }

    $expiresAt = date('c', strtotime('+' . TOKEN_EXPIRY_HOURS . ' hours'));

    foreach ($recipients as $user) {
        // Create an approve token and a reject token for this user
        $approveToken = bin2hex(random_bytes(32)); // 64-char hex
        $rejectToken  = bin2hex(random_bytes(32));

        $sb->from('approval_tokens')->insert([
            'submission_stage_id' => $submissionStage['id'],
            'recipient_user_id'   => $user['id'],
            'token'               => $approveToken,
            'action'              => 'approve',
            'expires_at'          => $expiresAt,
        ]);

        $sb->from('approval_tokens')->insert([
            'submission_stage_id' => $submissionStage['id'],
            'recipient_user_id'   => $user['id'],
            'token'               => $rejectToken,
            'action'              => 'reject',
            'expires_at'          => $expiresAt,
        ]);

        // Send the approval request email
        sendApprovalRequestEmail($user, $submission, $stage, $form, $approveToken, $rejectToken);

        // If delegation was applied, log it
        if (isset($user['_delegated_from'])) {
            $original = $user['_delegated_from'];
            $sb->from('audit_log')->insert([
                'submission_id' => $submissionId,
                'action'        => 'delegation_applied',
                'detail'        => json_encode([
                    'stage_id'          => $stage['id'],
                    'original_user_id'  => $original['id'],
                    'original_name'     => $original['display_name'] ?? $original['email'],
                    'delegate_user_id'  => $user['id'],
                    'delegate_name'     => $user['display_name'] ?? $user['email'],
                ]),
            ]);
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PUBLIC: Check whether a stage is now complete
//
// Called by approve.php after every approval decision is recorded.
//
// Logic:
//   - Any rejection → whole submission is rejected (stage fails immediately)
//   - approval_mode = 'any'    → 1 approval is enough
//   - approval_mode = 'all'    → everyone must approve
//   - approval_mode = 'quorum' → quorum_count approvals required
//
// If the stage is complete, calls wf_advanceSubmission().
// ─────────────────────────────────────────────────────────────────────────────

function wf_checkStageCompletion(string $submissionStageId): void
{
    global $sb;

    // Load submission_stage
    $ssRows = $sb->from('submission_stages')->select('*')->eq('id', $submissionStageId)->execute();
    if (!$ssRows || !isset($ssRows[0])) return;
    $submissionStage = $ssRows[0];

    // Don't re-process a stage that already has an outcome
    if ($submissionStage['status'] !== 'pending') return;

    $submissionId = $submissionStage['submission_id'];

    // Load stage configuration
    $stageRows = $sb->from('form_stages')->select('*')->eq('id', $submissionStage['stage_id'])->execute();
    if (!$stageRows || !isset($stageRows[0])) return;
    $stage = $stageRows[0];

    // Count decisions so far
    $approvals = $sb->from('approvals')
        ->select('*')
        ->eq('submission_stage_id', $submissionStageId)
        ->execute();
    $approvals = $approvals ?? [];

    $approvedCount = 0;
    $rejectedCount = 0;
    foreach ($approvals as $a) {
        if ($a['decision'] === 'approved') $approvedCount++;
        if ($a['decision'] === 'rejected') $rejectedCount++;
    }

    // Count total unique approvers (one 'approve' token per recipient)
    $tokenRows = $sb->from('approval_tokens')
        ->select('recipient_user_id')
        ->eq('submission_stage_id', $submissionStageId)
        ->eq('action', 'approve')
        ->execute();
    $totalRecipients = $tokenRows ? count($tokenRows) : 0;

    // ── Rejection: any rejection fails the whole submission ───
    if ($rejectedCount > 0) {
        $sb->from('submission_stages')
            ->eq('id', $submissionStageId)
            ->update(['status' => 'rejected', 'completed_at' => date('c')]);

        $sb->from('submissions')
            ->eq('id', $submissionId)
            ->update(['status' => 'rejected', 'completed_at' => date('c')]);

        $sb->from('audit_log')->insert([
            'submission_id' => $submissionId,
            'action'        => 'submission_rejected',
            'detail'        => json_encode([
                'stage_id'        => $stage['id'],
                'stage_name'      => $stage['name'],
                'rejected_count'  => $rejectedCount,
            ]),
        ]);

        // Notify submitter
        $subRows  = $sb->from('submissions')->select('*')->eq('id', $submissionId)->execute();
        $formRows = $subRows ? $sb->from('forms')->select('*')->eq('id', $subRows[0]['form_id'])->execute() : null;
        sendSubmissionOutcomeEmail($subRows[0] ?? [], $formRows[0] ?? null, 'rejected');

        return;
    }

    // ── Check stage completion based on approval_mode ─────────
    $stageComplete = false;
    $approvalMode  = $stage['approval_mode'] ?? 'any';

    switch ($approvalMode) {
        case 'any':
            $stageComplete = $approvedCount >= 1;
            break;
        case 'all':
            $stageComplete = $totalRecipients > 0 && $approvedCount >= $totalRecipients;
            break;
        case 'quorum':
            $quorum        = max(1, (int)($stage['quorum_count'] ?? 1));
            $stageComplete = $approvedCount >= $quorum;
            break;
    }

    if ($stageComplete) {
        $sb->from('submission_stages')
            ->eq('id', $submissionStageId)
            ->update(['status' => 'approved', 'completed_at' => date('c')]);

        $sb->from('audit_log')->insert([
            'submission_id' => $submissionId,
            'action'        => 'stage_approved',
            'detail'        => json_encode([
                'stage_id'       => $stage['id'],
                'stage_name'     => $stage['name'],
                'approval_mode'  => $approvalMode,
                'approved_count' => $approvedCount,
                'total'          => $totalRecipients,
            ]),
        ]);

        wf_advanceSubmission($submissionId);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PUBLIC: Advance a submission to its next stage
//
// Called when a stage completes (from wf_checkStageCompletion).
//
// Steps:
//   1. Load current stage_order
//   2. Evaluate routing rules — may skip to a non-sequential stage
//   3. Find next stage by stage_order (if no routing rule matched)
//   4. If another stage exists → wf_kickOffStage()
//   5. If no more stages → mark submission 'approved', email submitter
// ─────────────────────────────────────────────────────────────────────────────

function wf_advanceSubmission(string $submissionId): void
{
    global $sb;

    $subRows = $sb->from('submissions')->select('*')->eq('id', $submissionId)->execute();
    if (!$subRows || !isset($subRows[0])) return;
    $submission = $subRows[0];

    // Already in a terminal state — don't touch it
    if (in_array($submission['status'], ['approved', 'rejected', 'cancelled'])) return;

    $formData = $submission['form_data'] ?? [];
    if (is_string($formData)) {
        $formData = json_decode($formData, true) ?? [];
    }

    // Get current stage order
    $currentStageOrder = 0;
    if ($submission['current_stage_id']) {
        $curRows = $sb->from('form_stages')
            ->select('*')
            ->eq('id', $submission['current_stage_id'])
            ->execute();
        if ($curRows && isset($curRows[0])) {
            $currentStageOrder = (int)$curRows[0]['stage_order'];
        }
    }

    // Check routing rules first
    $nextStage   = null;
    $nextStageId = _wf_evaluateRoutingRules($submission['form_id'], $formData, $currentStageOrder);

    if ($nextStageId) {
        $nextRows  = $sb->from('form_stages')->select('*')->eq('id', $nextStageId)->execute();
        $nextStage = $nextRows[0] ?? null;
        if ($nextStage) {
            $sb->from('audit_log')->insert([
                'submission_id' => $submissionId,
                'action'        => 'routing_rule_applied',
                'detail'        => json_encode([
                    'jumped_to_stage_id'   => $nextStageId,
                    'jumped_to_stage_name' => $nextStage['name'],
                ]),
            ]);
        }
    }

    // No routing rule matched — find the next sequential stage
    if (!$nextStage) {
        $allStages = $sb->from('form_stages')
            ->select('*')
            ->eq('form_id', $submission['form_id'])
            ->order('stage_order', true)
            ->execute();

        if ($allStages) {
            foreach ($allStages as $s) {
                if ((int)$s['stage_order'] > $currentStageOrder) {
                    $nextStage = $s;
                    break;
                }
            }
        }
    }

    if ($nextStage) {
        wf_kickOffStage($submissionId, $nextStage);
    } else {
        // All stages done — submission fully approved
        $sb->from('submissions')
            ->eq('id', $submissionId)
            ->update(['status' => 'approved', 'completed_at' => date('c')]);

        $formRows = $sb->from('forms')->select('*')->eq('id', $submission['form_id'])->execute();
        $form     = $formRows[0] ?? null;

        $sb->from('audit_log')->insert([
            'submission_id' => $submissionId,
            'action'        => 'submission_approved',
            'detail'        => json_encode(['form_name' => $form['title'] ?? $form['name'] ?? '' ?? '']),
        ]);

        sendSubmissionOutcomeEmail($submission, $form, 'approved');
    }
}
