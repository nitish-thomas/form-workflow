<?php
/**
 * dashboard.php — Main dashboard
 *
 * Shows a time-aware greeting, three stat cards, a "My pending approvals"
 * action queue, and a recent submissions table.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/includes/auth-check.php';  // sets $currentUser, $sb
require_once __DIR__ . '/includes/view-helpers.php'; // vh_renderFormDataList()

$isAdmin = ($currentUser['role'] === 'admin');

// ── Time-aware greeting ───────────────────────────────────────────────────────
$hour      = (int)date('G');
$greeting  = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$firstName = trim(explode(' ', $currentUser['display_name'] ?? '')[0]) ?: 'there';

// ── 1. All forms (for stat card + form name lookup) ───────────────────────────
$allForms = $sb->from('forms')->select('*')->execute() ?? [];
$formMap  = [];
$activeFormCount = 0;
foreach ($allForms as $f) {
    $formMap[$f['id']] = $f['title'] ?? $f['name'] ?? '—';
    if (($f['status'] ?? '') === 'active') $activeFormCount++;
}

// ── 2. My pending approval tokens ────────────────────────────────────────────
// Each pending stage generates 2 tokens (approve + reject) for the approver.
// We deduplicate by submission_stage_id to get the true pending count.
$myTokens = $sb->from('approval_tokens')
    ->select('*')
    ->eq('recipient_user_id', $currentUser['id'])
    ->execute() ?? [];

$now          = time();
$activeTokens = array_filter(
    $myTokens,
    fn($t) => !$t['is_used'] && strtotime($t['expires_at'] ?? '1970-01-01') > $now
);

$pendingStageIds = array_values(array_unique(
    array_column(array_values($activeTokens), 'submission_stage_id')
));
$pendingCount = count($pendingStageIds);

// ── 3. Load pending approval details for the action list ─────────────────────
$pendingItems = [];

if (!empty($pendingStageIds)) {

    // submission_stages rows
    $subStageRows = $sb->from('submission_stages')
        ->select('*')
        ->in('id', $pendingStageIds)
        ->execute() ?? [];

    $pendingSubmissionIds = array_values(array_unique(array_column($subStageRows, 'submission_id')));
    $pendingFormStageIds  = array_values(array_unique(array_column($subStageRows, 'stage_id')));

    // Batch: submissions
    $pendingSubMap = [];
    if (!empty($pendingSubmissionIds)) {
        $rows = $sb->from('submissions')->select('*')->in('id', $pendingSubmissionIds)->execute() ?? [];
        foreach ($rows as $s) $pendingSubMap[$s['id']] = $s;
    }

    // Batch: stage names
    $formStageNameMap = [];
    if (!empty($pendingFormStageIds)) {
        $rows = $sb->from('form_stages')->select('*')->in('id', $pendingFormStageIds)->execute() ?? [];
        foreach ($rows as $fs) {
            $formStageNameMap[$fs['id']] = $fs['stage_name'] ?? $fs['name'] ?? '—';
        }
    }

    // Assemble — one entry per submission_stage
    foreach ($subStageRows as $ss) {
        $sub = $pendingSubMap[$ss['submission_id']] ?? null;
        if (!$sub) continue;
        $pendingItems[] = [
            'submission' => $sub,
            'stage_name' => $formStageNameMap[$ss['stage_id']] ?? '—',
            'form_name'  => $formMap[$sub['form_id']] ?? '—',
        ];
    }
}

// ── 4. All submissions (for month count + recent table) ───────────────────────
$subQuery = $sb->from('submissions')->select('*');
if (!$isAdmin) {
    $subQuery = $subQuery->eq('submitter_email', $currentUser['email']);
}
$allMySubmissions = $subQuery->order('submitted_at', false)->limit(500)->execute() ?? [];

// This-month count (compare ISO date strings — works because Supabase returns UTC timestamps)
$monthStart           = date('Y-m-01');
$submissionsThisMonth = count(array_filter(
    $allMySubmissions,
    fn($s) => substr($s['submitted_at'] ?? '', 0, 10) >= $monthStart
));

// Recent 10 for table
$recentSubmissions = array_slice($allMySubmissions, 0, 10);

// ── Batch load submitter display names for recent table ───────────────────────
$recentSubmitterIds = array_values(array_filter(array_unique(array_column($recentSubmissions, 'submitted_by'))));
$dashSubmitterNameMap = [];
if (!empty($recentSubmitterIds)) {
    $rows = $sb->from('users')->select('id,display_name,email')->in('id', $recentSubmitterIds)->execute() ?? [];
    foreach ($rows as $u) {
        $dashSubmitterNameMap[$u['id']] = $u['display_name'] ?? $u['email'] ?? null;
    }
}

// ── Helper ────────────────────────────────────────────────────────────────────
function dashStatusBadge(string $status): string
{
    $map = [
        'pending'     => ['bg-yellow-100 text-yellow-800', 'Pending'],
        'in_progress' => ['bg-blue-100 text-blue-800',     'In Progress'],
        'approved'    => ['bg-green-100 text-green-800',   'Approved'],
        'rejected'    => ['bg-red-100 text-red-800',       'Rejected'],
        'cancelled'   => ['bg-gray-100 text-gray-500',     'Cancelled'],
    ];
    [$cls, $label] = $map[$status] ?? ['bg-gray-100 text-gray-500', ucfirst(str_replace('_', ' ', $status))];
    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold whitespace-nowrap ' . $cls . '">' . $label . '</span>';
}

// ── Page setup ────────────────────────────────────────────────────────────────
$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<!-- ── Greeting ──────────────────────────────────────────────────────────── -->
<div class="mb-8">
    <h1 class="text-2xl font-bold text-gray-900">
        <?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($firstName) ?>
    </h1>
    <p class="mt-1 text-sm text-gray-400"><?= date('l, j F Y') ?></p>
</div>

<!-- ── Stat cards ───────────────────────────────────────────────────────── -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-8">

    <!-- Submissions this month -->
    <a href="/submissions.php"
       class="block bg-white rounded-xl border border-gray-200 p-6 hover:shadow-md hover:border-brand-200 transition-all group">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-sm font-medium text-gray-500">Submissions this month</p>
                <p class="mt-2 text-3xl font-bold text-gray-900"><?= $submissionsThisMonth ?></p>
            </div>
            <div class="w-11 h-11 rounded-lg bg-brand-50 flex items-center justify-center group-hover:bg-brand-100 transition-colors shrink-0">
                <svg class="w-5 h-5 text-brand-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
        </div>
        <p class="mt-4 text-xs text-brand-600 font-medium group-hover:underline">View all submissions →</p>
    </a>

    <!-- Pending approvals -->
    <?php $pendingTag = $pendingCount > 0 ? 'a' : 'div'; $pendingHref = $pendingCount > 0 ? ' href="/submissions.php?status=in_progress"' : ''; ?>
    <<?= $pendingTag . $pendingHref ?> class="<?= $pendingCount > 0 ? 'block' : '' ?> bg-white rounded-xl border p-6 transition-all
                <?= $pendingCount > 0 ? 'border-amber-200 bg-amber-50/20 hover:shadow-md cursor-pointer group' : 'border-gray-200' ?>">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-sm font-medium text-gray-500">Pending approvals</p>
                <p class="mt-2 text-3xl font-bold <?= $pendingCount > 0 ? 'text-amber-700' : 'text-gray-900' ?>">
                    <?= $pendingCount ?>
                </p>
            </div>
            <div class="w-11 h-11 rounded-lg flex items-center justify-center shrink-0
                        <?= $pendingCount > 0 ? 'bg-amber-100' : 'bg-gray-50' ?>">
                <svg class="w-5 h-5 <?= $pendingCount > 0 ? 'text-amber-600' : 'text-gray-400' ?>"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
        </div>
        <p class="mt-4 text-xs font-medium <?= $pendingCount > 0 ? 'text-amber-600 group-hover:underline' : 'text-gray-400' ?>">
            <?= $pendingCount > 0 ? 'Action required — see below ↓' : 'Nothing awaiting your action' ?>
        </p>
    </<?= $pendingTag ?>>

    <!-- Approval forms -->
    <a href="/forms.php"
       class="block bg-white rounded-xl border border-gray-200 p-6 hover:shadow-md hover:border-brand-200 transition-all group">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-sm font-medium text-gray-500">Approval forms</p>
                <p class="mt-2 text-3xl font-bold text-gray-900"><?= $activeFormCount ?></p>
            </div>
            <div class="w-11 h-11 rounded-lg bg-emerald-50 flex items-center justify-center group-hover:bg-emerald-100 transition-colors shrink-0">
                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                </svg>
            </div>
        </div>
        <p class="mt-4 text-xs text-emerald-600 font-medium group-hover:underline">
            <?= count($allForms) ?> total · <?= $activeFormCount ?> active · Manage →
        </p>
    </a>

</div>

<!-- ── My pending approvals ──────────────────────────────────────────────── -->
<?php if (!empty($pendingItems)): ?>
<div class="bg-white rounded-xl border border-amber-200 shadow-sm mb-6 overflow-hidden">

    <div class="flex items-center justify-between px-6 py-4 border-b border-amber-100 bg-amber-50/50">
        <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-amber-500 text-white text-xs font-bold leading-none">
                <?= $pendingCount ?>
            </span>
            <h2 class="text-base font-semibold text-gray-900">My pending approvals</h2>
        </div>
        <span class="text-xs font-semibold text-amber-600 uppercase tracking-wide">Action required</span>
    </div>

    <div class="divide-y divide-gray-100">
        <?php foreach ($pendingItems as $item):
            $sub = $item['submission'];
        ?>
        <div class="px-6 py-4 hover:bg-gray-50/50 transition-colors">

            <div class="flex items-center gap-4">

                <!-- Icon -->
                <div class="shrink-0 w-9 h-9 rounded-lg bg-amber-50 border border-amber-100 flex items-center justify-center">
                    <svg class="w-4 h-4 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>

                <!-- Details -->
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-gray-900 truncate">
                        <?= htmlspecialchars($item['form_name']) ?>
                    </p>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Stage: <span class="font-medium text-gray-700"><?= htmlspecialchars($item['stage_name']) ?></span>
                        &nbsp;·&nbsp;
                        Submitted by <span class="font-medium text-gray-700"><?= htmlspecialchars($sub['submitter_email'] ?? '—') ?></span>
                        &nbsp;·&nbsp;
                        <?= $sub['submitted_at'] ? date('j M Y', strtotime($sub['submitted_at'])) : '—' ?>
                    </p>
                </div>

                <!-- CTA -->
                <a href="/status.php?id=<?= urlencode($sub['id']) ?>"
                   class="shrink-0 inline-flex items-center gap-1.5 px-4 py-2 bg-brand-600 text-white text-sm font-medium rounded-lg hover:bg-brand-700 transition-colors">
                    Review
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
            </div>

            <!-- Expandable form data preview -->
            <details class="mt-2 ml-[52px] group">
                <summary class="list-none cursor-pointer inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-800 select-none">
                    <svg class="w-3 h-3 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                    </svg>
                    <span class="group-open:hidden">Show form submission</span>
                    <span class="hidden group-open:inline">Hide form submission</span>
                </summary>
                <div class="mt-3 p-4 bg-gray-50 border border-gray-200 rounded-lg">
                    <?= vh_renderFormDataList($sub['form_data'] ?? null) ?>
                </div>
            </details>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Recent submissions ────────────────────────────────────────────────── -->
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">

    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
        <h2 class="text-base font-semibold text-gray-900">Recent submissions</h2>
        <a href="/submissions.php" class="text-xs text-brand-600 font-medium hover:underline">View all →</a>
    </div>

    <?php if (empty($recentSubmissions)): ?>
    <div class="py-14 text-center">
        <svg class="mx-auto w-10 h-10 text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                  d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        <p class="text-sm text-gray-400 font-medium">No submissions yet</p>
        <p class="text-xs text-gray-400 mt-1">Submissions will appear here once a Google Form is submitted.</p>
    </div>

    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-100">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 sm:px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Form</th>
                    <?php if ($isAdmin): ?>
                    <th class="hidden sm:table-cell px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Submitter</th>
                    <?php endif; ?>
                    <th class="px-4 sm:px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="hidden sm:table-cell px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Submitted</th>
                    <th class="px-4 sm:px-6 py-3"></th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-100">
                <?php foreach ($recentSubmissions as $sub): ?>
                <tr class="hover:bg-gray-50/60 transition-colors">

                    <td class="px-4 sm:px-6 py-3.5">
                        <span class="text-sm font-medium text-gray-900">
                            <?= htmlspecialchars($formMap[$sub['form_id']] ?? '—') ?>
                        </span>
                    </td>

                    <?php if ($isAdmin): ?>
                    <td class="hidden sm:table-cell px-6 py-3.5">
                        <?php
                            $dName = $sub['submitted_by'] ? ($dashSubmitterNameMap[$sub['submitted_by']] ?? null) : null;
                        ?>
                        <?php if ($dName): ?>
                            <span class="text-sm font-medium text-gray-900 block"><?= htmlspecialchars($dName) ?></span>
                            <span class="text-xs text-gray-500"><?= htmlspecialchars($sub['submitter_email'] ?? '') ?></span>
                        <?php else: ?>
                            <span class="text-sm text-gray-600"><?= htmlspecialchars($sub['submitter_email'] ?? '—') ?></span>
                            <span class="block text-xs text-amber-500 mt-0.5">Not registered</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>

                    <td class="px-4 sm:px-6 py-3.5">
                        <?= dashStatusBadge($sub['status'] ?? 'pending') ?>
                    </td>

                    <td class="hidden sm:table-cell px-6 py-3.5 whitespace-nowrap">
                        <span class="text-sm text-gray-600">
                            <?= $sub['submitted_at'] ? date('j M Y', strtotime($sub['submitted_at'])) : '—' ?>
                        </span>
                        <span class="block text-xs text-gray-400">
                            <?= $sub['submitted_at'] ? date('g:i a', strtotime($sub['submitted_at'])) : '' ?>
                        </span>
                    </td>

                    <td class="px-4 sm:px-6 py-3.5 text-right">
                        <a href="/status.php?id=<?= urlencode($sub['id']) ?>"
                           class="inline-flex items-center gap-1 text-sm font-medium text-brand-600 hover:text-brand-800 transition-colors">
                            View
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Footer -->
    <div class="px-6 py-3 border-t border-gray-100 bg-gray-50/50">
        <p class="text-xs text-gray-400">
            Showing <?= count($recentSubmissions) ?> most recent
            <?= count($recentSubmissions) === 1 ? 'submission' : 'submissions' ?>
            <?php if (count($allMySubmissions) > 10): ?>
            · <a href="/submissions.php" class="text-brand-600 hover:underline"><?= count($allMySubmissions) ?> total</a>
            <?php endif; ?>
        </p>
    </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
