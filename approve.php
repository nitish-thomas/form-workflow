<?php
/**
 * approve.php — Email approval handler
 *
 * This page is linked directly from approval request emails.
 * It requires NO login — the token in the URL is the authentication.
 *
 * GET  /approve.php?token=xxx
 *   Validates the token and shows a confirmation page with a comments field.
 *   The page shows: what form, which stage, the action (Approve / Reject),
 *   and a summary of the submission data.
 *
 * POST /approve.php
 *   Re-validates the token, records the decision in approvals,
 *   marks the token used, then calls wf_checkStageCompletion().
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/includes/workflow.php'; // also loads email.php

$sb = new Supabase(SUPABASE_SECRET_KEY);

// ─────────────────────────────────────────────────────────────────────────────
// SHARED: load and validate a token
// Returns an array with keys: token_row, submission_stage, stage, submission, form
// Returns null and sets $error on any failure.
// ─────────────────────────────────────────────────────────────────────────────

function loadToken(string $tokenStr): ?array
{
    global $sb;

    if (!$tokenStr) return null;

    // Load token
    $tokenRows = $sb->from('approval_tokens')
        ->select('*')
        ->eq('token', $tokenStr)
        ->execute();

    if (!$tokenRows || !isset($tokenRows[0])) return null;
    $tokenRow = $tokenRows[0];

    // Check expiry
    if (strtotime($tokenRow['expires_at']) < time()) {
        return ['expired' => true, 'token_row' => $tokenRow];
    }

    // Check already used
    if ($tokenRow['is_used']) {
        return ['used' => true, 'token_row' => $tokenRow];
    }

    // Load submission_stage
    $ssRows = $sb->from('submission_stages')
        ->select('*')
        ->eq('id', $tokenRow['submission_stage_id'])
        ->execute();
    if (!$ssRows || !isset($ssRows[0])) return null;
    $submissionStage = $ssRows[0];

    // Check stage is still pending (e.g. someone else already rejected it)
    if ($submissionStage['status'] !== 'pending') {
        return ['stage_closed' => true, 'token_row' => $tokenRow, 'submission_stage' => $submissionStage];
    }

    // Load stage
    $stageRows = $sb->from('form_stages')->select('*')->eq('id', $submissionStage['stage_id'])->execute();
    $stage     = $stageRows[0] ?? null;

    // Load submission
    $subRows    = $sb->from('submissions')->select('*')->eq('id', $submissionStage['submission_id'])->execute();
    $submission = $subRows[0] ?? null;
    if (!$submission) return null;

    $formData = $submission['form_data'] ?? [];
    if (is_string($formData)) {
        $formData = json_decode($formData, true) ?? [];
    }
    $submission['form_data'] = $formData;

    // Load form
    $formRows = $sb->from('forms')->select('*')->eq('id', $submission['form_id'])->execute();
    $form     = $formRows[0] ?? null;

    // Load approver user
    $userRows = $sb->from('users')->select('*')->eq('id', $tokenRow['recipient_user_id'])->execute();
    $approver = $userRows[0] ?? null;

    return [
        'token_row'        => $tokenRow,
        'submission_stage' => $submissionStage,
        'stage'            => $stage,
        'submission'       => $submission,
        'form'             => $form,
        'approver'         => $approver,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// GET — show confirmation page
// ─────────────────────────────────────────────────────────────────────────────

$tokenStr = trim($_GET['token'] ?? $_POST['token'] ?? '');
$method   = $_SERVER['REQUEST_METHOD'];

// ─────────────────────────────────────────────────────────────────────────────
// POST — record decision
// ─────────────────────────────────────────────────────────────────────────────

if ($method === 'POST') {
    $tokenStr = trim($_POST['token'] ?? '');
    $comments = trim($_POST['comments'] ?? '');

    $data = loadToken($tokenStr);

    if (!$data || isset($data['expired']) || isset($data['used']) || isset($data['stage_closed'])) {
        // Show error — fall through to the page render below with a flag
        $postError = true;
    } else {
        $tokenRow        = $data['token_row'];
        $submissionStage = $data['submission_stage'];
        $approver        = $data['approver'];
        $decision        = $tokenRow['action']; // 'approve' or 'reject'

        // Map token action to approvals.decision value
        $decisionMap = [
            'approve' => 'approved',
            'reject'  => 'rejected',
        ];
        $approvalDecision = $decisionMap[$decision] ?? 'approved';

        // Record the approval
        $approvalRows = $sb->from('approvals')->insert([
            'submission_stage_id' => $submissionStage['id'],
            'approver_id'         => $approver['id'],
            'decision'            => $approvalDecision,
            'comments'            => $comments ?: null,
            'decided_at'          => date('c'),
        ]);

        // Mark token used
        $sb->from('approval_tokens')
            ->eq('token', $tokenStr)
            ->update(['is_used' => true]);

        // Mark the paired token (same user, same stage, opposite action) as used too
        // so it can't be used after the fact
        $pairedAction = ($decision === 'approve') ? 'reject' : 'approve';
        $pairedTokens = $sb->from('approval_tokens')
            ->select('*')
            ->eq('submission_stage_id', $submissionStage['id'])
            ->eq('recipient_user_id', $approver['id'])
            ->eq('action', $pairedAction)
            ->execute();
        if ($pairedTokens && isset($pairedTokens[0])) {
            $sb->from('approval_tokens')
                ->eq('id', $pairedTokens[0]['id'])
                ->update(['is_used' => true]);
        }

        // Audit log
        $sb->from('audit_log')->insert([
            'submission_id' => $submissionStage['submission_id'],
            'actor_id'      => $approver['id'],
            'action'        => $approvalDecision, // 'approved' or 'rejected'
            'detail'        => json_encode([
                'stage_id'    => $submissionStage['stage_id'],
                'stage_name'  => $data['stage']['name'] ?? '',
                'comments'    => $comments ?: null,
                'token'       => substr($tokenStr, 0, 8) . '…', // truncated for log
            ]),
            'ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        // Advance workflow
        wf_checkStageCompletion($submissionStage['id']);

        // Show success page
        $pageState = 'success';
        $action    = $decision; // 'approve' or 'reject'
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Load token data for GET (or re-display after POST error)
// ─────────────────────────────────────────────────────────────────────────────

if (!isset($pageState)) {
    $data = loadToken($tokenStr);

    if (!$data) {
        $pageState = 'invalid';
    } elseif (isset($data['expired'])) {
        $pageState = 'expired';
    } elseif (isset($data['used'])) {
        $pageState = 'used';
    } elseif (isset($data['stage_closed'])) {
        $pageState = 'stage_closed';
    } else {
        $pageState = 'confirm';
        $tokenRow  = $data['token_row'];
        $stage     = $data['stage'];
        $form      = $data['form'];
        $submission = $data['submission'];
        $approver  = $data['approver'];
        $action    = $tokenRow['action']; // 'approve' or 'reject'
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Helpers for the page render
// ─────────────────────────────────────────────────────────────────────────────

$isApprove    = ($action ?? '') === 'approve';
$actionLabel  = $isApprove ? 'Approve'  : 'Reject';
$actionColor  = $isApprove ? '#16a34a'  : '#dc2626';
$actionBorder = $isApprove ? '#15803d'  : '#b91c1c';
$actionIcon   = $isApprove ? '✓'        : '✗';

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Aurora Form Workflow — <?= htmlspecialchars($actionLabel ?? 'Decision') ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body { font-family: 'Inter', system-ui, sans-serif; }
  </style>
</head>
<body class="bg-gray-100 min-h-screen flex items-start justify-center pt-16 pb-16 px-4">

  <div class="w-full max-w-lg">

    <!-- Header -->
    <div class="bg-[#1e3a5f] rounded-t-xl px-8 py-6">
      <p class="text-white font-bold text-lg">Aurora Early Education</p>
      <p class="text-blue-300 text-sm">Form Workflow</p>
    </div>

    <!-- Card -->
    <div class="bg-white rounded-b-xl shadow-lg px-8 py-8">

      <?php if ($pageState === 'confirm'): ?>
      <!-- ─── Confirmation page ─────────────────────────────────── -->

      <div class="flex items-center gap-3 mb-6">
        <span class="text-2xl font-bold" style="color:<?= $actionColor ?>"><?= $actionIcon ?></span>
        <div>
          <p class="text-gray-900 font-bold text-xl"><?= htmlspecialchars($actionLabel) ?> this request?</p>
          <p class="text-gray-500 text-sm mt-0.5">Hi <?= htmlspecialchars($approver['display_name'] ?? $approver['email'] ?? '') ?>, please confirm your decision below.</p>
        </div>
      </div>

      <!-- Submission summary card -->
      <div class="bg-gray-50 rounded-lg border border-gray-200 p-5 mb-6">
        <div class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
          <div>
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-0.5">Form</p>
            <p class="text-gray-900 font-semibold"><?= htmlspecialchars($form['title'] ?? $form['name'] ?? '—') ?></p>
          </div>
          <div>
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-0.5">Stage</p>
            <p class="text-gray-900"><?= htmlspecialchars($stage['stage_name'] ?? $stage['name'] ?? '—') ?></p>
          </div>
          <div class="min-w-0">
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-0.5">Submitted by</p>
            <p class="text-gray-900 break-all"><?= htmlspecialchars($submission['submitter_email'] ?? '—') ?></p>
          </div>
          <div>
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-0.5">Submitted at</p>
            <p class="text-gray-900"><?= date('j M Y, g:i a', strtotime($submission['submitted_at'])) ?></p>
          </div>
        </div>

        <?php if (!empty($submission['form_data'])): ?>
        <div class="mt-4 pt-4 border-t border-gray-200">
          <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Form data</p>
          <dl class="space-y-1.5">
            <?php $count = 0; foreach ($submission['form_data'] as $key => $val): if ($count >= 6) break; $count++; ?>
            <div class="flex gap-2 text-sm">
              <dt class="text-gray-500 shrink-0 w-36 truncate"><?= htmlspecialchars($key) ?></dt>
              <dd class="text-gray-900"><?= htmlspecialchars((string)$val) ?></dd>
            </div>
            <?php endforeach; ?>
            <?php if (count($submission['form_data']) > 6): ?>
            <p class="text-xs text-gray-400">…and <?= count($submission['form_data']) - 6 ?> more fields.</p>
            <?php endif; ?>
          </dl>
        </div>
        <?php endif; ?>
      </div>

      <!-- Decision form -->
      <form method="POST" action="/approve.php">
        <input type="hidden" name="token" value="<?= htmlspecialchars($tokenStr) ?>">

        <div class="mb-5">
          <label for="comments" class="block text-sm font-medium text-gray-700 mb-1.5">
            Comments
            <?php if (!$isApprove): ?>
            <span class="text-red-500">*</span>
            <?php else: ?>
            <span class="text-gray-400 font-normal">(optional)</span>
            <?php endif; ?>
          </label>
          <textarea
            id="comments"
            name="comments"
            rows="3"
            <?php if (!$isApprove): ?>required<?php endif; ?>
            placeholder="<?= $isApprove ? 'Add any notes for the record…' : 'Please provide a reason for rejection…' ?>"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent resize-none"
          ></textarea>
        </div>

        <div class="flex gap-3">
          <button
            type="submit"
            class="flex-1 text-white font-semibold py-3 rounded-lg text-sm transition-opacity hover:opacity-90"
            style="background:<?= $actionColor ?>;"
          >
            <?= $actionIcon ?> Confirm <?= $actionLabel ?>
          </button>
          <a
            href="<?= APP_URL . '/status.php?id=' . urlencode($submission['id']) ?>"
            class="px-4 py-3 rounded-lg border border-gray-300 text-gray-700 text-sm font-medium hover:bg-gray-50 transition-colors"
          >
            View Details
          </a>
        </div>
      </form>

      <p class="text-xs text-gray-400 mt-5 text-center">
        This link expires <?= date('j M Y \a\t g:i a', strtotime($tokenRow['expires_at'])) ?>.
      </p>

      <?php elseif ($pageState === 'success'): ?>
      <!-- ─── Success ───────────────────────────────────────────── -->

      <div class="text-center py-4">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4" style="background:<?= $actionColor ?>1a;">
          <span class="text-3xl" style="color:<?= $actionColor ?>"><?= $actionIcon ?></span>
        </div>
        <p class="text-gray-900 font-bold text-xl mb-2">Decision recorded</p>
        <p class="text-gray-500 text-sm">
          Your <?= strtolower($actionLabel) ?> has been saved.
          <?php if ($isApprove): ?>
            The workflow will continue to the next stage automatically.
          <?php else: ?>
            The submitter will be notified that their request was not approved.
          <?php endif; ?>
        </p>
        <p class="mt-6 text-xs text-gray-400">You can safely close this tab.</p>
      </div>

      <?php elseif ($pageState === 'expired'): ?>
      <!-- ─── Expired ───────────────────────────────────────────── -->

      <div class="text-center py-4">
        <div class="w-16 h-16 bg-yellow-50 rounded-full flex items-center justify-center mx-auto mb-4">
          <span class="text-3xl">⏱</span>
        </div>
        <p class="text-gray-900 font-bold text-xl mb-2">Link has expired</p>
        <p class="text-gray-500 text-sm">
          Approval links are valid for <?= TOKEN_EXPIRY_HOURS ?> hours. This one has expired.<br>
          Please contact your administrator to request a new link.
        </p>
      </div>

      <?php elseif ($pageState === 'used'): ?>
      <!-- ─── Already used ─────────────────────────────────────── -->

      <div class="text-center py-4">
        <div class="w-16 h-16 bg-blue-50 rounded-full flex items-center justify-center mx-auto mb-4">
          <span class="text-3xl">✓</span>
        </div>
        <p class="text-gray-900 font-bold text-xl mb-2">Already responded</p>
        <p class="text-gray-500 text-sm">
          Your decision has already been recorded for this request.<br>
          Each link can only be used once.
        </p>
      </div>

      <?php elseif ($pageState === 'stage_closed'): ?>
      <!-- ─── Stage closed (rejected by someone else, etc.) ────── -->

      <div class="text-center py-4">
        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
          <span class="text-3xl">🔒</span>
        </div>
        <p class="text-gray-900 font-bold text-xl mb-2">This stage is no longer open</p>
        <p class="text-gray-500 text-sm">
          The approval stage for this request has already been resolved —
          either by another approver or by the system. No further action is needed.
        </p>
      </div>

      <?php else: ?>
      <!-- ─── Invalid / missing token ──────────────────────────── -->

      <div class="text-center py-4">
        <div class="w-16 h-16 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-4">
          <span class="text-3xl">⚠️</span>
        </div>
        <p class="text-gray-900 font-bold text-xl mb-2">Invalid link</p>
        <p class="text-gray-500 text-sm">
          This approval link is not valid. It may have been copied incorrectly
          or the request may no longer exist. Please use the link directly from your email.
        </p>
      </div>

      <?php endif; ?>

    </div><!-- /card -->

    <p class="text-center text-xs text-gray-400 mt-6">Aurora Early Education — Form Workflow System</p>

  </div>

</body>
</html>
