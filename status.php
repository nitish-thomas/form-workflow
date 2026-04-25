<?php
/**
 * status.php — Submission status page
 *
 * Shows the full approval timeline for a single submission.
 * Requires login. Accessible to:
 *   - The submitter (matched by submitter_email = session email)
 *   - Any admin
 *
 * URL: /status.php?id=<submission_uuid>
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/includes/auth-check.php'; // sets $currentUser, $sb
require_once __DIR__ . '/includes/workflow.php';  // wf_checkStageCompletion()

$submissionId = trim($_GET['id'] ?? '');

// ── Load submission ───────────────────────────────────────────────────────────
$subRows = $sb->from('submissions')->select('*')->eq('id', $submissionId)->execute();
$submission = $subRows[0] ?? null;

if (!$submission) {
    http_response_code(404);
    $pageTitle  = 'Not Found';
    $activePage = '';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="p-8 text-center text-gray-500">Submission not found.</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// ── Access control ────────────────────────────────────────────────────────────
// Admins can view all submissions.
// Non-admins can only view their own (matched by submitter_email).
$isAdmin    = ($currentUser['role'] === 'admin');
$isOwner    = (strtolower($submission['submitter_email'] ?? '') === strtolower($currentUser['email']));

if (!$isAdmin && !$isOwner) {
    http_response_code(403);
    $pageTitle  = 'Access Denied';
    $activePage = '';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="p-8 text-center text-gray-500">You do not have permission to view this submission.</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// ── Inline approval POST handler ──────────────────────────────────────────────
// Handles approve/reject submitted from the portal (no email token required).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['_inline_approval'])) {
    $decision = $_POST['decision'] ?? '';   // 'approve' or 'reject'
    $comments = trim($_POST['comments'] ?? '');

    if (in_array($decision, ['approve', 'reject'])) {
        // Find the active pending submission_stage for this submission
        $activeSStageRows = $sb->from('submission_stages')
            ->select('*')
            ->eq('submission_id', $submissionId)
            ->eq('status', 'pending')
            ->execute();
        $activeSStage = $activeSStageRows[0] ?? null;

        if ($activeSStage) {
            // Find this user's unused tokens for the active stage
            $myTokenRows = $sb->from('approval_tokens')
                ->select('*')
                ->eq('submission_stage_id', $activeSStage['id'])
                ->eq('recipient_user_id', $currentUser['id'])
                ->execute();
            $myUnusedTokens = array_filter($myTokenRows ?? [], fn($t) => !$t['is_used']);

            if (!empty($myUnusedTokens)) {
                $approvalDecision = ($decision === 'approve') ? 'approved' : 'rejected';

                // Record the decision
                $sb->from('approvals')->insert([
                    'submission_stage_id' => $activeSStage['id'],
                    'approver_id'         => $currentUser['id'],
                    'decision'            => $approvalDecision,
                    'comments'            => $comments ?: null,
                    'decided_at'          => date('c'),
                ]);

                // Mark all this user's tokens for this stage as used
                foreach ($myUnusedTokens as $tok) {
                    $sb->from('approval_tokens')
                        ->eq('id', $tok['id'])
                        ->update(['is_used' => true]);
                }

                // Audit log
                $sb->from('audit_log')->insert([
                    'submission_id' => $submissionId,
                    'actor_id'      => $currentUser['id'],
                    'action'        => $approvalDecision,
                    'detail'        => json_encode([
                        'stage_id' => $activeSStage['stage_id'],
                        'comments' => $comments ?: null,
                        'via'      => 'portal_inline',
                    ]),
                    'ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);

                // Advance the workflow
                wf_checkStageCompletion($activeSStage['id']);
            }
        }
    }

    // Redirect back so the page reloads with updated state
    header('Location: /status.php?id=' . urlencode($submissionId) . '&decision=' . urlencode($decision));
    exit;
}

// ── Load related data ─────────────────────────────────────────────────────────
$formRows   = $sb->from('forms')->select('*')->eq('id', $submission['form_id'])->execute();
$form       = $formRows[0] ?? null;

$allStageRows = $sb->from('form_stages')
    ->select('*')
    ->eq('form_id', $submission['form_id'])
    ->order('stage_order', true)
    ->execute();
$allStages = $allStageRows ?? [];

// Load all submission_stages for this submission
$subStageRows = $sb->from('submission_stages')
    ->select('*')
    ->eq('submission_id', $submissionId)
    ->order('started_at', true)
    ->execute();
$subStages = $subStageRows ?? [];

// Index submission_stages by stage_id for easy lookup
$subStageByStageId = [];
foreach ($subStages as $ss) {
    $subStageByStageId[$ss['stage_id']] = $ss;
}

// Load approvals for all submission_stages
$subStageIds = array_column($subStages, 'id');
$allApprovals = [];
if (!empty($subStageIds)) {
    $approvalRows = $sb->from('approvals')
        ->select('*')
        ->in('submission_stage_id', $subStageIds)
        ->order('decided_at', true)
        ->execute();
    foreach (($approvalRows ?? []) as $a) {
        $allApprovals[$a['submission_stage_id']][] = $a;
    }
}

// Load audit log for this submission (most recent first, limit 50)
$auditRows = $sb->from('audit_log')
    ->select('*')
    ->eq('submission_id', $submissionId)
    ->order('created_at', false)
    ->limit(50)
    ->execute();
$auditLog = $auditRows ?? [];

// Load approver names for the approvals (batch by user ids)
$approverIds = array_unique(array_column(
    array_merge(...array_values($allApprovals ?: [[]])),
    'approver_id'
));
$approverMap = [];
if (!empty($approverIds)) {
    $approverRows = $sb->from('users')->select('*')->in('id', $approverIds)->execute();
    foreach (($approverRows ?? []) as $u) {
        $approverMap[$u['id']] = $u;
    }
}

// ── My inline approval tokens for the active stage ───────────────────────────
// Used to show the approve/reject panel when the current user is an approver.
$myInlineTokens = [];
$activeSStageForInline = null;
if ($submission['current_stage_id'] && $submission['status'] === 'in_progress') {
    $activeSStageForInline = $subStageByStageId[$submission['current_stage_id']] ?? null;
    if ($activeSStageForInline && $activeSStageForInline['status'] === 'pending') {
        $inlineTokenRows = $sb->from('approval_tokens')
            ->select('*')
            ->eq('submission_stage_id', $activeSStageForInline['id'])
            ->eq('recipient_user_id', $currentUser['id'])
            ->execute();
        $myInlineTokens = array_filter($inlineTokenRows ?? [], fn($t) => !$t['is_used']);
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function statusBadge(string $status): string
{
    $map = [
        'pending'     => ['bg-yellow-100 text-yellow-800',  'Pending'],
        'in_progress' => ['bg-blue-100 text-blue-800',      'In Progress'],
        'approved'    => ['bg-green-100 text-green-800',    'Approved'],
        'rejected'    => ['bg-red-100 text-red-800',        'Rejected'],
        'more_info'   => ['bg-purple-100 text-purple-800',  'More Info'],
        'cancelled'   => ['bg-gray-100 text-gray-600',      'Cancelled'],
        'skipped'     => ['bg-gray-100 text-gray-500',      'Skipped'],
    ];
    [$cls, $label] = $map[$status] ?? ['bg-gray-100 text-gray-500', ucfirst($status)];
    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold whitespace-nowrap ' . $cls . '">' . $label . '</span>';
}

function decisionIcon(string $decision): string
{
    return match($decision) {
        'approved'  => '<span class="text-green-600 font-bold">✓</span>',
        'rejected'  => '<span class="text-red-600 font-bold">✗</span>',
        'more_info' => '<span class="text-purple-600 font-bold">?</span>',
        default     => '—',
    };
}

// ── Form data for display ─────────────────────────────────────────────────────
$formData = $submission['form_data'] ?? [];
if (is_string($formData)) {
    $formData = json_decode($formData, true) ?? [];
}

// ── Page setup ────────────────────────────────────────────────────────────────
$pageTitle  = 'Submission — ' . ($form['title'] ?? $form['name'] ?? 'Unknown Form');
$activePage = '';

// Toast to show after inline approval redirect
$decisionFlash = $_GET['decision'] ?? '';

require_once __DIR__ . '/includes/header.php';

// Trigger toast immediately if redirected after a decision
if ($decisionFlash === 'approve') {
    echo '<script>document.addEventListener("DOMContentLoaded",()=>showToast("Decision recorded — approved ✓","success"))</script>';
} elseif ($decisionFlash === 'reject') {
    echo '<script>document.addEventListener("DOMContentLoaded",()=>showToast("Decision recorded — rejected","info"))</script>';
}
?>

<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">

  <!-- ── Back link ─────────────────────────────────────────── -->
  <a href="/dashboard.php" class="inline-flex items-center gap-2 text-sm text-gray-500 hover:text-gray-800 transition-colors">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    Back to Dashboard
  </a>

  <!-- ── Header card ───────────────────────────────────────── -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0 flex-1">
        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Form</p>
        <h1 class="text-xl sm:text-2xl font-bold text-gray-900 break-words"><?= htmlspecialchars($form['title'] ?? $form['name'] ?? 'Unknown Form') ?></h1>
        <?php if ($form['description'] ?? ''): ?>
        <p class="text-gray-500 text-sm mt-1"><?= htmlspecialchars($form['description']) ?></p>
        <?php endif; ?>
      </div>
      <div class="shrink-0 mt-0.5">
        <?= statusBadge($submission['status']) ?>
      </div>
    </div>

    <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-6 pt-6 border-t border-gray-100 text-sm">
      <div class="min-w-0">
        <dt class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-0.5">Submitted by</dt>
        <dd class="text-gray-900 font-medium break-all"><?= htmlspecialchars($submission['submitter_email'] ?? '—') ?></dd>
        <?php if (!$submission['submitted_by']): ?>
        <dd class="text-xs text-amber-600 mt-0.5">Not registered in system</dd>
        <?php endif; ?>
      </div>
      <div>
        <dt class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-0.5">Submitted</dt>
        <dd class="text-gray-900"><?= date('j M Y, g:i a', strtotime($submission['submitted_at'])) ?></dd>
      </div>
      <div>
        <dt class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-0.5">Completed</dt>
        <dd class="text-gray-900"><?= $submission['completed_at'] ? date('j M Y, g:i a', strtotime($submission['completed_at'])) : '—' ?></dd>
      </div>
      <div>
        <dt class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-0.5">Submission ID</dt>
        <dd class="text-gray-400 font-mono text-xs"><?= htmlspecialchars(substr($submissionId, 0, 8)) ?>…</dd>
      </div>
    </dl>
  </div>

  <!-- ── Stage timeline ────────────────────────────────────── -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-6">Approval Stages</h2>

    <div class="space-y-0">
      <?php foreach ($allStages as $i => $stage):
        $ss          = $subStageByStageId[$stage['id']] ?? null;
        $stageStatus = $ss['status'] ?? 'pending';
        $isActive    = ($stage['id'] === $submission['current_stage_id']);
        $isFuture    = !$ss; // not yet started
        $stageApprovals = $allApprovals[$ss['id'] ?? ''] ?? [];

        // Circle colour
        if ($stageStatus === 'approved')        $circleClass = 'bg-green-500 border-green-500';
        elseif ($stageStatus === 'rejected')    $circleClass = 'bg-red-500 border-red-500';
        elseif ($isActive)                      $circleClass = 'bg-blue-500 border-blue-500 animate-pulse';
        else                                    $circleClass = 'bg-gray-200 border-gray-200';
      ?>
      <div class="flex gap-5 <?= $i < count($allStages) - 1 ? 'pb-8' : '' ?> relative">

        <!-- Timeline line -->
        <?php if ($i < count($allStages) - 1): ?>
        <div class="absolute left-4 top-8 bottom-0 w-0.5 <?= $stageStatus === 'approved' ? 'bg-green-200' : 'bg-gray-200' ?>"></div>
        <?php endif; ?>

        <!-- Circle -->
        <div class="shrink-0 w-8 h-8 rounded-full border-2 <?= $circleClass ?> flex items-center justify-center z-10">
          <?php if ($stageStatus === 'approved'): ?>
            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
          <?php elseif ($stageStatus === 'rejected'): ?>
            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
          <?php elseif ($isActive): ?>
            <div class="w-2 h-2 bg-white rounded-full"></div>
          <?php else: ?>
            <div class="w-2 h-2 bg-gray-400 rounded-full"></div>
          <?php endif; ?>
        </div>

        <!-- Content -->
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-3 mb-1">
            <p class="font-semibold text-gray-900 <?= $isFuture ? 'text-gray-400' : '' ?>">
              <?= htmlspecialchars($stage['stage_name'] ?? $stage['name'] ?? '—') ?>
            </p>
            <span class="text-xs text-gray-400 font-mono"><?= htmlspecialchars($stage['stage_type']) ?></span>
            <?php if ($isActive): ?>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-700">Current</span>
            <?php endif; ?>
            <?php if ($ss): echo statusBadge($stageStatus); endif; ?>
          </div>

          <?php if ($ss): ?>
          <p class="text-xs text-gray-400 mb-3">
            Started <?= date('j M Y, g:i a', strtotime($ss['started_at'])) ?>
            <?php if ($ss['completed_at']): ?>
              · Completed <?= date('j M Y, g:i a', strtotime($ss['completed_at'])) ?>
            <?php endif; ?>
          </p>
          <?php endif; ?>

          <!-- Approvals list -->
          <?php if (!empty($stageApprovals)): ?>
          <div class="space-y-2">
            <?php foreach ($stageApprovals as $a):
              $approver = $approverMap[$a['approver_id']] ?? null;
              $approverName = $approver ? ($approver['display_name'] ?? $approver['email']) : 'Unknown approver';
            ?>
            <div class="flex items-start gap-3 bg-gray-50 rounded-lg px-3 py-2 text-sm">
              <div class="shrink-0 mt-0.5"><?= decisionIcon($a['decision']) ?></div>
              <div class="min-w-0">
                <p class="text-gray-900 font-medium"><?= htmlspecialchars($approverName) ?></p>
                <?php if ($a['comments']): ?>
                <p class="text-gray-500 text-xs mt-0.5">"<?= htmlspecialchars($a['comments']) ?>"</p>
                <?php endif; ?>
                <p class="text-gray-400 text-xs mt-0.5"><?= date('j M Y, g:i a', strtotime($a['decided_at'])) ?></p>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <?php if ($isFuture): ?>
          <p class="text-xs text-gray-400 italic">Not yet started</p>
          <?php endif; ?>

          <?php if ($isActive && !empty($myInlineTokens) && $stageStatus === 'pending'): ?>
          <!-- ── Inline approval panel ───────────────────────── -->
          <div class="mt-4 bg-amber-50 border border-amber-200 rounded-xl p-5">
            <p class="text-sm font-semibold text-gray-900 mb-1">Your approval is required</p>
            <p class="text-xs text-gray-500 mb-4">
              You are listed as an approver for this stage.
              Add optional comments and confirm your decision.
            </p>
            <form method="POST" action="/status.php?id=<?= urlencode($submissionId) ?>"
                  onsubmit="return confirmDecision(event)">
              <input type="hidden" name="_inline_approval" value="1">
              <div class="mb-4">
                <label for="inline-comments" class="block text-xs font-semibold text-gray-600 mb-1.5">
                  Comments <span id="inline-comments-req" class="text-red-500 hidden">*</span>
                  <span id="inline-comments-opt" class="font-normal text-gray-400">(optional)</span>
                </label>
                <textarea id="inline-comments" name="comments" rows="3"
                          placeholder="Add any notes for the record…"
                          class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-900 placeholder-gray-400
                                 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent resize-none"></textarea>
              </div>
              <div class="flex gap-3">
                <button type="submit" name="decision" value="approve"
                        onclick="setPendingDecision('approve')"
                        class="inline-flex items-center gap-2 px-5 py-2.5 bg-green-600 text-white text-sm font-semibold rounded-lg hover:bg-green-700 transition-colors">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                  </svg>
                  Approve
                </button>
                <button type="submit" name="decision" value="reject"
                        onclick="setPendingDecision('reject')"
                        class="inline-flex items-center gap-2 px-5 py-2.5 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700 transition-colors">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
                  </svg>
                  Reject
                </button>
              </div>
            </form>
          </div>
          <script>
          let _pendingDecision = '';
          function setPendingDecision(d) {
            _pendingDecision = d;
            // Toggle required marker on comments when rejecting
            document.getElementById('inline-comments-req').classList.toggle('hidden', d !== 'reject');
            document.getElementById('inline-comments-opt').classList.toggle('hidden', d === 'reject');
          }
          function confirmDecision(e) {
            const comments = document.getElementById('inline-comments').value.trim();
            if (_pendingDecision === 'reject' && !comments) {
              alert('Please provide a reason when rejecting.');
              e.preventDefault();
              return false;
            }
            const label = _pendingDecision === 'approve' ? 'approve' : 'reject';
            return confirm(`Confirm: ${label} this request?`);
          }
          </script>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ── Form data ─────────────────────────────────────────── -->
  <?php if (!empty($formData)): ?>
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">Form Data</h2>
    <dl class="divide-y divide-gray-100">
      <?php foreach ($formData as $key => $val): ?>
      <div class="py-3 sm:grid sm:grid-cols-3 sm:gap-4">
        <dt class="text-sm font-medium text-gray-500"><?= htmlspecialchars($key) ?></dt>
        <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2"><?= htmlspecialchars((string)$val) ?></dd>
      </div>
      <?php endforeach; ?>
    </dl>
  </div>
  <?php endif; ?>

  <!-- ── Audit log (admin only) ────────────────────────────── -->
  <?php if ($isAdmin && !empty($auditLog)): ?>
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">Audit Log</h2>
    <div class="space-y-2">
      <?php foreach ($auditLog as $entry): ?>
      <div class="flex gap-4 text-sm py-2 border-b border-gray-50 last:border-0">
        <span class="text-gray-400 text-xs whitespace-nowrap pt-0.5 w-36 shrink-0">
          <?= date('j M, g:i a', strtotime($entry['created_at'])) ?>
        </span>
        <div class="min-w-0">
          <span class="font-mono text-xs bg-gray-100 text-gray-700 px-2 py-0.5 rounded"><?= htmlspecialchars($entry['action']) ?></span>
          <?php if ($entry['detail'] && $entry['detail'] !== '{}'): ?>
          <?php $detail = is_string($entry['detail']) ? json_decode($entry['detail'], true) : $entry['detail']; ?>
          <?php if ($detail): ?>
          <p class="text-gray-500 text-xs mt-1"><?= htmlspecialchars(json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></p>
          <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /max-w-4xl -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>
