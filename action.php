<?php
/**
 * action.php — Action stage completion handler
 *
 * Token-gated page (no login required). Linked from action-request emails.
 *
 * GET  /action.php?token=xxx
 *   Shows a confirmation page with submission summary and a single
 *   "Mark as Done" button.
 *
 * POST /action.php
 *   Re-validates token, records an 'approved' decision in approvals,
 *   marks token used, calls wf_checkStageCompletion() to advance workflow.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/includes/workflow.php';    // also loads email.php
require_once __DIR__ . '/includes/view-helpers.php'; // vh_requestRef()

$sb = new Supabase(SUPABASE_SECRET_KEY);

// ─────────────────────────────────────────────────────────────────────────────
// SHARED: load and validate an 'action' token
// Returns data array or special flags (expired / used / stage_closed / invalid)
// ─────────────────────────────────────────────────────────────────────────────

function loadActionToken(string $tokenStr): ?array
{
    global $sb;

    if (!$tokenStr) return null;

    $tokenRows = $sb->from('approval_tokens')
        ->select('*')
        ->eq('token', $tokenStr)
        ->execute();

    if (!$tokenRows || !isset($tokenRows[0])) return null;
    $tokenRow = $tokenRows[0];

    // Must be a 'complete' action token
    if ($tokenRow['action'] !== 'complete') {
        return null;
    }

    if (strtotime($tokenRow['expires_at']) < time()) {
        return ['expired' => true, 'token_row' => $tokenRow];
    }

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

    // Load recipient user (may be null for raw-email recipients)
    $recipient = null;
    if (!empty($tokenRow['recipient_user_id'])) {
        $userRows  = $sb->from('users')->select('*')->eq('id', $tokenRow['recipient_user_id'])->execute();
        $recipient = $userRows[0] ?? null;
    }

    return [
        'token_row'        => $tokenRow,
        'submission_stage' => $submissionStage,
        'stage'            => $stage,
        'submission'       => $submission,
        'form'             => $form,
        'recipient'        => $recipient,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// POST — record completion
// ─────────────────────────────────────────────────────────────────────────────

$tokenStr = trim($_GET['token'] ?? $_POST['token'] ?? '');
$method   = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $tokenStr = trim($_POST['token'] ?? '');
    $data     = loadActionToken($tokenStr);

    if (!$data || isset($data['expired']) || isset($data['used']) || isset($data['stage_closed'])) {
        $postError = true;
    } else {
        $tokenRow        = $data['token_row'];
        $submissionStage = $data['submission_stage'];
        $recipient       = $data['recipient'];

        // Record the completion as 'approved'
        $approvalInsert = [
            'submission_stage_id' => $submissionStage['id'],
            'decision'            => 'approved',
            'comments'            => 'Action completed via email link',
            'decided_at'          => date('c'),
        ];
        if ($recipient) {
            $approvalInsert['approver_id'] = $recipient['id'];
        }
        $sb->from('approvals')->insert($approvalInsert);

        // Mark token used
        $sb->from('approval_tokens')
            ->eq('token', $tokenStr)
            ->update(['is_used' => true]);

        // Audit log
        $sb->from('audit_log')->insert([
            'submission_id' => $submissionStage['submission_id'],
            'actor_id'      => $recipient['id'] ?? null,
            'action'        => 'action_completed',
            'detail'        => json_encode([
                'stage_id'   => $submissionStage['stage_id'],
                'stage_name' => $data['stage']['stage_name'] ?? $data['stage']['name'] ?? '',
                'token'      => substr($tokenStr, 0, 8) . '…',
            ]),
            'ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        // Advance workflow
        wf_checkStageCompletion($submissionStage['id']);

        $pageState = 'success';
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// GET — load token and show confirm page
// ─────────────────────────────────────────────────────────────────────────────

if (!isset($pageState)) {
    $data = loadActionToken($tokenStr);

    if (!$data) {
        $pageState = 'invalid';
    } elseif (isset($data['expired'])) {
        $pageState = 'expired';
    } elseif (isset($data['used'])) {
        $pageState = 'used';
    } elseif (isset($data['stage_closed'])) {
        $pageState = 'stage_closed';
    } else {
        $pageState       = 'confirm';
        $tokenRow        = $data['token_row'];
        $stage           = $data['stage'];
        $form            = $data['form'];
        $submission      = $data['submission'];
        $recipient       = $data['recipient'];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Aurora Form Workflow — Mark as Actioned</title>
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
      <!-- ─── Confirm page ─────────────────────────────────────── -->

      <?php
        $recipientName = htmlspecialchars($recipient['display_name'] ?? $recipient['email'] ?? 'there');
        $stageName     = htmlspecialchars($stage['stage_name'] ?? $stage['name'] ?? 'Action');
        $stageDesc     = htmlspecialchars($stage['description'] ?? '');
      ?>

      <div class="flex items-center gap-3 mb-6">
        <span class="text-2xl font-bold text-orange-500">☑</span>
        <div>
          <p class="text-gray-900 font-bold text-xl">Action Required</p>
          <p class="text-gray-500 text-sm mt-0.5">Hi <?= $recipientName ?>, please confirm that you have completed the task below.</p>
        </div>
      </div>

      <!-- Submission summary -->
      <div class="bg-orange-50 rounded-lg border border-orange-200 p-5 mb-6">
        <?php $actionReqRef = vh_requestRef($submission, $form ?? []); ?>
        <?php if ($actionReqRef): ?>
        <div class="flex items-center gap-2 mb-3 pb-3 border-b border-orange-200">
          <span class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Request</span>
          <span class="font-mono font-bold text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded text-sm"><?= htmlspecialchars($actionReqRef) ?></span>
        </div>
        <?php endif; ?>
        <div class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm mb-4">
          <div>
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-0.5">Form</p>
            <p class="text-gray-900 font-semibold"><?= htmlspecialchars($form['title'] ?? $form['name'] ?? '—') ?></p>
          </div>
          <div>
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-0.5">Action Stage</p>
            <p class="text-gray-900"><?= $stageName ?></p>
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

        <?php if ($stageDesc): ?>
        <div class="border-t border-orange-200 pt-3">
          <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Instructions</p>
          <p class="text-sm text-gray-700"><?= $stageDesc ?></p>
        </div>
        <?php endif; ?>

        <?php if (!empty($submission['form_data'])): ?>
        <div class="mt-4 pt-4 border-t border-orange-200">
          <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Form data</p>
          <dl class="space-y-1.5">
            <?php foreach ($submission['form_data'] as $key => $val): ?>
            <?php
              // Handle file upload fields
              if (is_array($val) && isset($val['type']) && $val['type'] === 'files') {
                  $files = $val['files'] ?? [];
                  ob_start();
                  echo '<div class="flex gap-2 text-sm">';
                  echo '<dt class="text-gray-500 shrink-0 w-36 truncate">' . htmlspecialchars($key) . '</dt>';
                  echo '<dd class="text-gray-900 break-words">';
                  if ($files) {
                      foreach ($files as $f) {
                          echo '<a href="' . htmlspecialchars($f['url'] ?? '#') . '" target="_blank" rel="noopener"'
                             . ' class="text-blue-600 hover:underline block">' . htmlspecialchars($f['name'] ?? 'File') . '</a>';
                      }
                  } else {
                      echo '<span class="text-gray-400">(no files)</span>';
                  }
                  echo '</dd></div>';
                  echo ob_get_clean();
                  continue;
              }
            ?>
            <div class="flex gap-2 text-sm">
              <dt class="text-gray-500 shrink-0 w-36 truncate"><?= htmlspecialchars($key) ?></dt>
              <dd class="text-gray-900 break-words"><?= nl2br(htmlspecialchars(is_array($val) ? implode(', ', array_map(fn($v)=>is_array($v)?implode(', ',$v):(string)$v, $val)) : (string)$val)) ?></dd>
            </div>
            <?php endforeach; ?>
          </dl>
        </div>
        <?php endif; ?>
      </div>

      <!-- Completion form -->
      <form method="POST" action="/action.php">
        <input type="hidden" name="token" value="<?= htmlspecialchars($tokenStr) ?>">

        <div class="flex gap-3">
          <button
            type="submit"
            class="flex-1 bg-orange-600 hover:bg-orange-700 text-white font-semibold py-3 rounded-lg text-sm transition-colors"
          >
            ✓ Mark as Actioned
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
        Each link can only be used once.
      </p>

      <?php elseif ($pageState === 'success'): ?>
      <!-- ─── Success ───────────────────────────────────────────── -->

      <div class="text-center py-4">
        <div class="w-16 h-16 rounded-full bg-orange-50 flex items-center justify-center mx-auto mb-4">
          <span class="text-3xl text-orange-500">✓</span>
        </div>
        <p class="text-gray-900 font-bold text-xl mb-2">Done — thank you!</p>
        <p class="text-gray-500 text-sm">
          Your completion has been recorded. The workflow will continue to the next stage automatically.
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
          Action links are valid for <?= TOKEN_EXPIRY_HOURS ?> hours. This one has expired.<br>
          Please contact your administrator for assistance.
        </p>
      </div>

      <?php elseif ($pageState === 'used'): ?>
      <!-- ─── Already used ─────────────────────────────────────── -->

      <div class="text-center py-4">
        <div class="w-16 h-16 bg-blue-50 rounded-full flex items-center justify-center mx-auto mb-4">
          <span class="text-3xl">✓</span>
        </div>
        <p class="text-gray-900 font-bold text-xl mb-2">Already completed</p>
        <p class="text-gray-500 text-sm">
          This action has already been marked as done.<br>
          Each link can only be used once.
        </p>
      </div>

      <?php elseif ($pageState === 'stage_closed'): ?>
      <!-- ─── Stage closed ──────────────────────────────────────── -->

      <div class="text-center py-4">
        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
          <span class="text-3xl">🔒</span>
        </div>
        <p class="text-gray-900 font-bold text-xl mb-2">This stage is no longer open</p>
        <p class="text-gray-500 text-sm">
          The action stage for this request has already been resolved. No further action is needed.
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
          This link is not valid. It may have been copied incorrectly or the request no longer exists.
          Please use the link directly from your email.
        </p>
      </div>

      <?php endif; ?>

    </div><!-- /card -->

    <p class="text-center text-xs text-gray-400 mt-6">Aurora Early Education — Form Workflow System</p>

  </div>

</body>
</html>
