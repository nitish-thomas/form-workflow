<?php
/**
 * sign.php — Signature capture page
 *
 * Linked directly from signature request emails.
 * Requires NO login — the token in the URL is the authentication.
 *
 * GET  /sign.php?token=xxx
 *   Validates the token and shows the form summary + signature canvas.
 *
 * POST /sign.php
 *   Receives the base64 PNG signature, stores it in submission_stages,
 *   records an 'approved' decision in approvals, marks token used,
 *   generates a PDF (if available), emails PDF to previous-stage approvers,
 *   then calls wf_checkStageCompletion().
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/includes/workflow.php'; // also loads email.php

$sb = new Supabase();

// ─────────────────────────────────────────────────────────────────────────────
// SHARED: load and validate a 'sign' token
// ─────────────────────────────────────────────────────────────────────────────

function loadSignToken(string $tokenStr): ?array
{
    global $sb;
    if (!$tokenStr) return null;

    $tokenRows = $sb->from('approval_tokens')
        ->select('*')
        ->eq('token', $tokenStr)
        ->execute();

    if (!$tokenRows || !isset($tokenRows[0])) return null;
    $tokenRow = $tokenRows[0];

    // Must be a sign token
    if (($tokenRow['action'] ?? '') !== 'sign') return null;

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

    // Load form_stage
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

    // Load signer user (may be null if not a registered user)
    $signerRows = $sb->from('users')->select('*')->eq('id', $tokenRow['recipient_user_id'])->execute();
    $signer     = $signerRows[0] ?? null;

    return [
        'token_row'        => $tokenRow,
        'submission_stage' => $submissionStage,
        'stage'            => $stage,
        'submission'       => $submission,
        'form'             => $form,
        'signer'           => $signer,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// POST — record signature
// ─────────────────────────────────────────────────────────────────────────────

$tokenStr = trim($_GET['token'] ?? $_POST['token'] ?? '');
$method   = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $tokenStr    = trim($_POST['token'] ?? '');
    $signatureB64 = trim($_POST['signature_data'] ?? '');

    $data = loadSignToken($tokenStr);

    if (!$data || isset($data['expired']) || isset($data['used']) || isset($data['stage_closed'])) {
        $pageState = isset($data['expired']) ? 'expired' : (isset($data['used']) ? 'used' : (isset($data['stage_closed']) ? 'stage_closed' : 'invalid'));
    } elseif (!$signatureB64) {
        $pageState  = 'confirm';
        $signError  = 'Please draw your signature before submitting.';
        $tokenRow        = $data['token_row'];
        $submissionStage = $data['submission_stage'];
        $stage           = $data['stage'];
        $submission      = $data['submission'];
        $form            = $data['form'];
        $signer          = $data['signer'];
    } else {
        $tokenRow        = $data['token_row'];
        $submissionStage = $data['submission_stage'];
        $stage           = $data['stage'];
        $submission      = $data['submission'];
        $form            = $data['form'];
        $signer          = $data['signer'];
        $signerEmail     = $signer['email'] ?? $submission['submitter_email'] ?? 'unknown';
        $now             = date('c');

        // 1. Store signature in submission_stages
        $sb->from('submission_stages')
            ->eq('id', $submissionStage['id'])
            ->update([
                'signature_data' => $signatureB64,
                'signed_at'      => $now,
                'signer_email'   => $signerEmail,
            ]);

        // 2. Insert approved decision in approvals
        $sb->from('approvals')->insert([
            'submission_stage_id' => $submissionStage['id'],
            'approver_id'         => $tokenRow['recipient_user_id'],
            'decision'            => 'approved',
            'comments'            => 'Signed via sign.php',
            'decided_at'          => $now,
        ]);

        // 3. Mark token used
        $sb->from('approval_tokens')
            ->eq('token', $tokenStr)
            ->update(['is_used' => true]);

        // 4. Audit log
        $sb->from('audit_log')->insert([
            'submission_id'       => $submissionStage['submission_id'],
            'submission_stage_id' => $submissionStage['id'],
            'actor_id'            => $tokenRow['recipient_user_id'],
            'action'              => 'signed',
            'via'                 => 'sign_page',
            'meta'                => json_encode([
                'stage_id'     => $submissionStage['stage_id'],
                'stage_name'   => $stage['stage_name'] ?? '',
                'signer_email' => $signerEmail,
            ]),
        ]);

        // 5. Generate PDF + email to previous-stage approvers
        //    Find all users who approved earlier stages for this submission
        $allSubStages = $sb->from('submission_stages')
            ->select('*')
            ->eq('submission_id', $submissionStage['submission_id'])
            ->execute() ?? [];

        // Get the order of the current stage
        $currentStageOrder = $stage['stage_order'] ?? 999;
        $stageIdsByOrder   = [];
        foreach ($allSubStages as $ss) {
            $stageIdsByOrder[] = $ss['id'];
        }

        // Fetch form_stages to know order of each
        $fsRows = !empty($stageIdsByOrder)
            ? ($sb->from('form_stages')->select('*')->in('id', array_column($allSubStages, 'stage_id'))->execute() ?? [])
            : [];
        $fsOrderMap = []; // [stage_id => stage_order]
        foreach ($fsRows as $fs) {
            $fsOrderMap[$fs['id']] = $fs['stage_order'] ?? 0;
        }

        // Collect submission_stage IDs for earlier stages
        $earlierSsIds = [];
        foreach ($allSubStages as $ss) {
            $order = $fsOrderMap[$ss['stage_id']] ?? 0;
            if ($order < $currentStageOrder) {
                $earlierSsIds[] = $ss['id'];
            }
        }

        $prevApproverIds = [];
        if (!empty($earlierSsIds)) {
            $prevApprovals = $sb->from('approvals')->select('*')->in('submission_stage_id', $earlierSsIds)->execute() ?? [];
            $prevApproverIds = array_values(array_unique(array_column($prevApprovals, 'approver_id')));
        }

        $prevApproverUsers = [];
        if (!empty($prevApproverIds)) {
            $prevApproverUsers = $sb->from('users')->select('*')->in('id', $prevApproverIds)->execute() ?? [];
        }

        // Generate PDF
        $pdfPath = '';
        $pdfFile = __DIR__ . '/includes/pdf.php';
        if (file_exists($pdfFile)) {
            require_once $pdfFile;
            // Build signature image from base64
            $sigImageData = $signatureB64;
            if (strpos($sigImageData, 'data:image') === 0) {
                $sigImageData = preg_replace('/^data:image\/\w+;base64,/', '', $sigImageData);
            }
            $tmpSigPath = sys_get_temp_dir() . '/sig_' . uniqid() . '.png';
            file_put_contents($tmpSigPath, base64_decode($sigImageData));

            $pdfPath = generateSubmissionPDF($submission, $form, $stage, $prevApproverUsers, $tmpSigPath);
            if (file_exists($tmpSigPath)) @unlink($tmpSigPath);
        }

        // Email PDF to previous-stage approvers
        if (!empty($prevApproverUsers)) {
            sendSignedPDFEmail($prevApproverUsers, $submission, $stage, $form, $pdfPath, $signerEmail);
        }

        // Clean up temp PDF
        if ($pdfPath && file_exists($pdfPath)) {
            @unlink($pdfPath);
        }

        // 6. Advance workflow
        wf_checkStageCompletion($submissionStage['id']);

        $pageState = 'success';
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Load token data for GET (or re-display after error)
// ─────────────────────────────────────────────────────────────────────────────

if (!isset($pageState)) {
    $data = loadSignToken($tokenStr);

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
        $signer          = $data['signer'];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Aurora Form Workflow — Signature</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- signature_pad.js — lightweight canvas signature library -->
  <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
  <style>
    body { font-family: system-ui, -apple-system, sans-serif; }
    #sig-canvas { touch-action: none; }
  </style>
</head>
<body class="bg-gray-100 min-h-screen flex items-start justify-center pt-12 pb-16 px-4">

  <div class="w-full max-w-lg">

    <!-- Header -->
    <div class="bg-[#1e3a5f] rounded-t-xl px-8 py-6">
      <p class="text-white font-bold text-lg">Aurora Early Education</p>
      <p class="text-blue-300 text-sm">Form Workflow — Signature</p>
    </div>

    <!-- Card -->
    <div class="bg-white rounded-b-xl shadow-lg px-8 py-8">

      <?php if ($pageState === 'confirm'): ?>
      <!-- ─── Signature capture page ────────────────────────────── -->

      <div class="flex items-start gap-3 mb-6">
        <div class="w-10 h-10 rounded-lg bg-purple-100 flex items-center justify-center shrink-0">
          <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
          </svg>
        </div>
        <div>
          <p class="text-gray-900 font-bold text-xl">Signature Required</p>
          <p class="text-gray-500 text-sm mt-0.5">
            Hi <?= htmlspecialchars($signer['display_name'] ?? $signer['email'] ?? 'there') ?>,
            please review the details below and sign to confirm.
          </p>
        </div>
      </div>

      <?php if (!empty($signError ?? '')): ?>
      <div class="mb-4 bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-sm text-red-700">
          <?= htmlspecialchars($signError) ?>
      </div>
      <?php endif; ?>

      <!-- Submission summary -->
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
            <p class="text-gray-900"><?= date('j M Y', strtotime($submission['submitted_at'])) ?></p>
          </div>
        </div>

        <?php if (!empty($submission['form_data'])): ?>
        <div class="mt-4 pt-4 border-t border-gray-200">
          <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Form data</p>
          <dl class="space-y-1.5">
            <?php foreach ($submission['form_data'] as $key => $val): ?>
            <div class="flex gap-2 text-sm">
              <dt class="text-gray-500 shrink-0 w-36 truncate"><?= htmlspecialchars($key) ?></dt>
              <dd class="text-gray-900 break-words">
                <?php if (is_array($val) && isset($val['type']) && $val['type'] === 'files'): ?>
                  <?php foreach ($val['files'] ?? [] as $f): ?>
                    <a href="<?= htmlspecialchars($f['url'] ?? '#') ?>" target="_blank" rel="noopener" class="text-blue-600 hover:underline block"><?= htmlspecialchars($f['name'] ?? 'File') ?></a>
                  <?php endforeach; ?>
                  <?php if (empty($val['files'])): ?><span class="text-gray-400">(no files)</span><?php endif; ?>
                <?php else: ?>
                  <?= nl2br(htmlspecialchars(is_array($val) ? implode(', ', array_map(fn($v)=>is_array($v)?implode(', ',$v):(string)$v, $val)) : (string)$val)) ?>
                <?php endif; ?>
              </dd>
            </div>
            <?php endforeach; ?>
          </dl>
        </div>
        <?php endif; ?>
      </div>

      <!-- Signature pad -->
      <form method="POST" action="/sign.php" id="sign-form">
        <input type="hidden" name="token" value="<?= htmlspecialchars($tokenStr) ?>">
        <input type="hidden" name="signature_data" id="signature-input">

        <div class="mb-5">
          <div class="flex items-center justify-between mb-2">
            <label class="text-sm font-medium text-gray-700">Your Signature <span class="text-red-500">*</span></label>
            <button type="button" onclick="clearSignature()"
                    class="text-xs text-gray-400 hover:text-gray-600 transition-colors underline">Clear</button>
          </div>
          <div class="relative border-2 border-dashed border-gray-300 rounded-xl bg-gray-50 hover:border-purple-400 transition-colors"
               id="sig-container">
            <canvas id="sig-canvas" class="w-full rounded-xl" height="200"></canvas>
            <p id="sig-placeholder" class="absolute inset-0 flex items-center justify-center text-gray-400 text-sm pointer-events-none select-none">
              Draw your signature here
            </p>
          </div>
          <p class="mt-1.5 text-xs text-gray-400">By signing you confirm the above information is correct and authorised.</p>
        </div>

        <button type="button" onclick="submitSignature()"
                class="w-full py-3 bg-purple-600 hover:bg-purple-700 text-white font-semibold rounded-lg text-sm transition-colors">
          ✍ Submit Signature
        </button>
      </form>

      <p class="text-xs text-gray-400 mt-5 text-center">
        This link is unique to you and can only be used once.
      </p>

      <?php elseif ($pageState === 'success'): ?>
      <!-- ─── Success ───────────────────────────────────────────── -->

      <div class="text-center py-4">
        <div class="w-16 h-16 rounded-full bg-purple-100 flex items-center justify-center mx-auto mb-4">
          <svg class="w-8 h-8 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
          </svg>
        </div>
        <p class="text-gray-900 font-bold text-xl mb-2">Signature Recorded</p>
        <p class="text-gray-500 text-sm">
          Your signature has been saved. A copy of the signed document will be
          sent to the relevant approvers.
        </p>
        <p class="mt-6 text-xs text-gray-400">You can safely close this tab.</p>
      </div>

      <?php elseif ($pageState === 'expired'): ?>
      <div class="text-center py-4">
        <div class="w-16 h-16 bg-yellow-50 rounded-full flex items-center justify-center mx-auto mb-4">
          <span class="text-3xl">⏱</span>
        </div>
        <p class="text-gray-900 font-bold text-xl mb-2">Link has expired</p>
        <p class="text-gray-500 text-sm">
          Signature links are valid for <?= TOKEN_EXPIRY_HOURS ?> hours.<br>
          Please contact your administrator to request a new link.
        </p>
      </div>

      <?php elseif ($pageState === 'used'): ?>
      <div class="text-center py-4">
        <div class="w-16 h-16 bg-purple-50 rounded-full flex items-center justify-center mx-auto mb-4">
          <span class="text-3xl">✓</span>
        </div>
        <p class="text-gray-900 font-bold text-xl mb-2">Already signed</p>
        <p class="text-gray-500 text-sm">Your signature has already been recorded for this request.</p>
      </div>

      <?php elseif ($pageState === 'stage_closed'): ?>
      <div class="text-center py-4">
        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
          <span class="text-3xl">🔒</span>
        </div>
        <p class="text-gray-900 font-bold text-xl mb-2">Stage no longer open</p>
        <p class="text-gray-500 text-sm">This signature stage has already been resolved. No further action is needed.</p>
      </div>

      <?php else: ?>
      <div class="text-center py-4">
        <div class="w-16 h-16 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-4">
          <span class="text-3xl">⚠️</span>
        </div>
        <p class="text-gray-900 font-bold text-xl mb-2">Invalid link</p>
        <p class="text-gray-500 text-sm">
          This signature link is not valid. Please use the link directly from your email.
        </p>
      </div>
      <?php endif; ?>

    </div><!-- /card -->

    <p class="text-center text-xs text-gray-400 mt-6">Aurora Early Education — Form Workflow System</p>
  </div>

<?php if ($pageState === 'confirm'): ?>
<script>
// Initialise signature_pad
const canvas      = document.getElementById('sig-canvas');
const placeholder = document.getElementById('sig-placeholder');
const sigPad      = new SignaturePad(canvas, {
    backgroundColor: 'rgb(249, 250, 251)', // matches bg-gray-50
    penColor:        'rgb(17, 24, 39)',
});

// Resize canvas properly so it doesn't look blurry
function resizeCanvas() {
    const ratio  = Math.max(window.devicePixelRatio || 1, 1);
    const width  = canvas.offsetWidth;
    canvas.width  = width * ratio;
    canvas.height = 200 * ratio;
    canvas.getContext('2d').scale(ratio, ratio);
    sigPad.clear(); // clear after resize to avoid distortion
}
resizeCanvas();
window.addEventListener('resize', resizeCanvas);

// Hide placeholder once user starts drawing
sigPad.addEventListener('beginStroke', () => {
    placeholder.style.display = 'none';
});

function clearSignature() {
    sigPad.clear();
    placeholder.style.display = '';
}

function submitSignature() {
    if (sigPad.isEmpty()) {
        alert('Please draw your signature before submitting.');
        return;
    }
    // Export as PNG data URL (base64)
    document.getElementById('signature-input').value = sigPad.toDataURL('image/png');
    document.getElementById('sign-form').submit();
}
</script>
<?php endif; ?>

</body>
</html>
