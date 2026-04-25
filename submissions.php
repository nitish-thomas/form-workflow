<?php
/**
 * submissions.php — Submissions list
 *
 * Admin view : all submissions, filterable by form and status.
 * Non-admin  : only own submissions (submitter_email = session email).
 *
 * GET params:
 *   status   — 'all' | 'pending' | 'in_progress' | 'approved' | 'rejected'
 *   form_id  — UUID of a specific form (optional)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/includes/auth-check.php';   // sets $currentUser, $sb
require_once __DIR__ . '/includes/view-helpers.php'; // vh_renderFormDataList()

$isAdmin = ($currentUser['role'] === 'admin');

// ── Filters ───────────────────────────────────────────────────────────────────
$filterStatus = $_GET['status']  ?? 'all';
$filterFormId = trim($_GET['form_id'] ?? '');

// Sanitise status value
$validStatuses = ['all', 'pending', 'in_progress', 'approved', 'rejected'];
if (!in_array($filterStatus, $validStatuses)) $filterStatus = 'all';

// ── Load all forms for the filter dropdown ────────────────────────────────────
$allForms = $sb->from('forms')->select('*')->order('created_at', false)->execute() ?? [];
$formMap  = [];
foreach ($allForms as $f) {
    $formMap[$f['id']] = $f['title'] ?? $f['name'] ?? '—';
}

// ── Fetch submissions (admin/non-admin + form filter, NOT status filter yet) ──
// We fetch without the status filter so we can compute tab counts in PHP.
$query = $sb->from('submissions')->select('*');

if (!$isAdmin) {
    // Non-admins only see their own
    $query = $query->eq('submitter_email', $currentUser['email']);
}
if ($filterFormId) {
    $query = $query->eq('form_id', $filterFormId);
}

$allSubmissions = $query->order('submitted_at', false)->limit(500)->execute() ?? [];

// ── Compute status tab counts ─────────────────────────────────────────────────
$statusCounts = ['all' => count($allSubmissions)];
foreach ($allSubmissions as $s) {
    $st = $s['status'] ?? 'pending';
    $statusCounts[$st] = ($statusCounts[$st] ?? 0) + 1;
}

// ── Apply status filter in PHP ────────────────────────────────────────────────
$submissions = $allSubmissions;
if ($filterStatus !== 'all') {
    $submissions = array_values(array_filter(
        $allSubmissions,
        fn($s) => ($s['status'] ?? '') === $filterStatus
    ));
}

// ── Batch load current stage names ───────────────────────────────────────────
$stageIds = array_values(array_filter(array_unique(array_column($submissions, 'current_stage_id'))));
$stageMap = [];
if (!empty($stageIds)) {
    $stageRows = $sb->from('form_stages')->select('*')->in('id', $stageIds)->execute() ?? [];
    foreach ($stageRows as $s) {
        $stageMap[$s['id']] = $s['stage_name'] ?? $s['name'] ?? '—';
    }
}

// ── Batch load submitter display names ───────────────────────────────────────
$submitterIds = array_values(array_filter(array_unique(array_column($submissions, 'submitted_by'))));
$submitterNameMap = [];
if (!empty($submitterIds)) {
    $submitterRows = $sb->from('users')->select('id,display_name,email')->in('id', $submitterIds)->execute() ?? [];
    foreach ($submitterRows as $u) {
        $submitterNameMap[$u['id']] = $u['display_name'] ?? $u['email'] ?? null;
    }
}

// ── Batch load approver decisions for visible submissions ─────────────────────
$subIds = array_values(array_unique(array_column($submissions, 'id')));
$allSubStageRows = [];
if (!empty($subIds)) {
    $allSubStageRows = $sb->from('submission_stages')->select('*')->in('submission_id', $subIds)->execute() ?? [];
}

$subStageIndex  = []; // [submission_id][stage_id] => submission_stage row
$allSubStageIds = [];
foreach ($allSubStageRows as $ss) {
    $subStageIndex[$ss['submission_id']][$ss['stage_id']] = $ss;
    $allSubStageIds[] = $ss['id'];
}

$approvalsBySubStageId = [];
if (!empty($allSubStageIds)) {
    $approvalRows = $sb->from('approvals')->select('*')->in('submission_stage_id', $allSubStageIds)->execute() ?? [];
    foreach ($approvalRows as $a) {
        $approvalsBySubStageId[$a['submission_stage_id']][] = $a;
    }
}

$flatApprovals = !empty($approvalsBySubStageId) ? array_merge(...array_values($approvalsBySubStageId)) : [];
$approverIds   = array_values(array_unique(array_column($flatApprovals, 'approver_id')));
$approverNameMap = [];
if (!empty($approverIds)) {
    $approverUserRows = $sb->from('users')->select('*')->in('id', $approverIds)->execute() ?? [];
    foreach ($approverUserRows as $u) {
        $approverNameMap[$u['id']] = $u['display_name'] ?? $u['email'] ?? '—';
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function submissionStatusBadge(string $status): string
{
    $map = [
        'pending'     => ['bg-yellow-100 text-yellow-800 ring-1 ring-yellow-200',  'Pending'],
        'in_progress' => ['bg-blue-100 text-blue-800 ring-1 ring-blue-200',        'In Progress'],
        'approved'    => ['bg-green-100 text-green-800 ring-1 ring-green-200',     'Approved'],
        'rejected'    => ['bg-red-100 text-red-800 ring-1 ring-red-200',           'Rejected'],
        'cancelled'   => ['bg-gray-100 text-gray-500 ring-1 ring-gray-200',        'Cancelled'],
    ];
    [$cls, $label] = $map[$status] ?? ['bg-gray-100 text-gray-500', ucfirst(str_replace('_', ' ', $status))];
    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold whitespace-nowrap ' . $cls . '">' . $label . '</span>';
}

function tabCount(array $counts, string $key): string
{
    $n = $counts[$key] ?? 0;
    if ($n === 0) return '';
    return '<span class="ml-1.5 inline-flex items-center justify-center px-2 py-0.5 rounded-full text-xs font-bold bg-white/30">' . $n . '</span>';
}

// ── Page setup ────────────────────────────────────────────────────────────────
$pageTitle  = 'Submissions';
$activePage = 'submissions';
require_once __DIR__ . '/includes/header.php';

// Build base URL for filter links (preserves form_id when switching tabs)
function filterUrl(string $status, string $formId = ''): string
{
    $params = ['status' => $status];
    if ($formId) $params['form_id'] = $formId;
    return '/submissions.php?' . http_build_query($params);
}
?>

<!-- ── Page heading ──────────────────────────────────────────────────────── -->
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Submissions</h1>
        <p class="mt-1 text-sm text-gray-500">
            <?= $isAdmin ? 'All form submissions across your organisation' : 'Your submitted forms and their approval status' ?>
        </p>
    </div>
</div>

<!-- ── Filters bar ───────────────────────────────────────────────────────── -->
<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6">

    <!-- Status tabs -->
    <div class="flex items-center gap-0 border-b border-gray-200 px-1 pt-1 overflow-x-auto">
        <?php
        $tabs = [
            'all'         => 'All',
            'pending'     => 'Pending',
            'in_progress' => 'In Progress',
            'approved'    => 'Approved',
            'rejected'    => 'Rejected',
        ];
        foreach ($tabs as $key => $label):
            $isActive = ($filterStatus === $key);
            $tabCls   = $isActive
                ? 'border-brand-600 text-brand-700 bg-brand-50/60'
                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300';
        ?>
        <a href="<?= filterUrl($key, $filterFormId) ?>"
           class="inline-flex items-center whitespace-nowrap px-4 py-2.5 border-b-2 text-sm font-medium transition-colors <?= $tabCls ?>">
            <?= $label ?><?= tabCount($statusCounts, $key) ?>
        </a>
        <?php endforeach; ?>

        <!-- Form filter + Export (pushed to the right) -->
        <?php if ($isAdmin): ?>
        <div class="ml-auto flex items-center gap-2 pr-3 pb-1 shrink-0">
            <?php if (!empty($allForms)): ?>
            <label for="form-filter" class="text-xs text-gray-400 font-medium whitespace-nowrap">Filter by form</label>
            <select id="form-filter"
                    onchange="applyFormFilter(this.value)"
                    class="text-sm border border-gray-200 rounded-lg px-3 py-1.5 text-gray-700 bg-white focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                <option value="">All forms</option>
                <?php foreach ($allForms as $f):
                    $fid = $f['id'];
                    $ftitle = htmlspecialchars($f['title'] ?? $f['name'] ?? '—');
                    $sel = ($filterFormId === $fid) ? 'selected' : '';
                ?>
                <option value="<?= $fid ?>" <?= $sel ?>><?= $ftitle ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <?php
                $exportParams = ['status' => $filterStatus];
                if ($filterFormId) $exportParams['form_id'] = $filterFormId;
                $exportUrl = '/export-submissions.php?' . http_build_query($exportParams);
            ?>
            <a href="<?= $exportUrl ?>"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors whitespace-nowrap"
               title="Export current view to CSV">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Export CSV
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Table ─────────────────────────────────────────────────────────── -->
    <?php if (empty($submissions)): ?>
    <div class="py-16 text-center">
        <svg class="mx-auto w-10 h-10 text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                  d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        <p class="text-gray-400 text-sm font-medium">No submissions found</p>
        <?php if ($filterStatus !== 'all' || $filterFormId): ?>
        <a href="/submissions.php" class="mt-2 inline-block text-sm text-brand-600 hover:underline">Clear filters</a>
        <?php endif; ?>
    </div>

    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-100">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Form</th>
                    <?php if ($isAdmin): ?>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Submitter</th>
                    <?php endif; ?>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Current Stage</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Submitted</th>
                    <th class="px-6 py-3"></th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-100">
                <?php
                // Total columns: Form, (Submitter — admin only), Status, Current Stage, Submitted, View = 5 or 6
                $colspan = $isAdmin ? 6 : 5;
                foreach ($submissions as $sub):
                    $formName    = htmlspecialchars($formMap[$sub['form_id']] ?? '—');
                    $subEmail    = htmlspecialchars($sub['submitter_email'] ?? '—');
                    $subStatus   = $sub['status'] ?? 'pending';
                    $stageId     = $sub['current_stage_id'] ?? null;
                    $stageName   = $stageId ? ($stageMap[$stageId] ?? '—') : null;
                    $submittedAt = $sub['submitted_at'] ?? null;
                    $subId       = $sub['id'];

                    // Approver decisions for current stage
                    $activeSS       = ($stageId && isset($subStageIndex[$subId][$stageId])) ? $subStageIndex[$subId][$stageId] : null;
                    $stageApprovals = $activeSS ? ($approvalsBySubStageId[$activeSS['id']] ?? []) : [];

                    // Current stage display
                    if ($stageName) {
                        $stageDisplay = '<span class="text-gray-900 text-sm font-medium">' . htmlspecialchars($stageName) . '</span>';
                        if (!empty($stageApprovals)) {
                            $stageDisplay .= '<div class="flex flex-wrap gap-x-3 gap-y-0.5 mt-1">';
                            foreach ($stageApprovals as $a) {
                                $dName  = htmlspecialchars($approverNameMap[$a['approver_id']] ?? '—');
                                $isApp  = ($a['decision'] === 'approved');
                                $colour = $isApp ? 'text-green-700' : 'text-red-700';
                                $icon   = $isApp
                                    ? '<svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>'
                                    : '<svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>';
                                $stageDisplay .= '<span class="inline-flex items-center gap-1 text-xs ' . $colour . '">' . $icon . $dName . '</span>';
                            }
                            $stageDisplay .= '</div>';
                        } elseif (in_array($subStatus, ['in_progress', 'pending'])) {
                            $stageDisplay .= '<p class="text-xs text-gray-400 mt-0.5">Awaiting response</p>';
                        }
                    } elseif (in_array($subStatus, ['approved', 'rejected'])) {
                        $stageDisplay = '<span class="text-gray-400 text-sm italic">Complete</span>';
                    } else {
                        $stageDisplay = '<span class="text-gray-400 text-sm">—</span>';
                    }

                    // Relative date
                    $dateDisplay = $submittedAt ? date('j M Y', strtotime($submittedAt)) : '—';
                    $timeDisplay = $submittedAt ? date('g:i a', strtotime($submittedAt)) : '';
                ?>
                <tr class="hover:bg-gray-50/60 transition-colors">

                    <!-- Form name + inline expand -->
                    <td class="px-6 py-4">
                        <span class="text-sm font-medium text-gray-900"><?= $formName ?></span>
                        <details class="group mt-1">
                            <summary class="list-none cursor-pointer inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-800 select-none">
                                <svg class="w-3 h-3 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                                </svg>
                                <span class="group-open:hidden">Show submission</span>
                                <span class="hidden group-open:inline">Hide submission</span>
                            </summary>
                            <div class="mt-2 p-3 bg-gray-50 border border-gray-200 rounded-lg">
                                <?= vh_renderFormDataList($sub['form_data'] ?? null) ?>
                            </div>
                        </details>
                    </td>

                    <!-- Submitter (admin only) -->
                    <?php if ($isAdmin): ?>
                    <td class="px-6 py-4">
                        <?php
                            $displayName = $sub['submitted_by'] ? ($submitterNameMap[$sub['submitted_by']] ?? null) : null;
                        ?>
                        <?php if ($displayName): ?>
                            <span class="text-sm font-medium text-gray-900 block"><?= htmlspecialchars($displayName) ?></span>
                            <span class="text-xs text-gray-500"><?= $subEmail ?></span>
                        <?php else: ?>
                            <span class="text-sm text-gray-700"><?= $subEmail ?></span>
                            <span class="block text-xs text-amber-500 mt-0.5">Not registered</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>

                    <!-- Status badge -->
                    <td class="px-6 py-4">
                        <?= submissionStatusBadge($subStatus) ?>
                    </td>

                    <!-- Current stage -->
                    <td class="px-6 py-4">
                        <?= $stageDisplay ?>
                    </td>

                    <!-- Submitted date -->
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="text-sm text-gray-700"><?= $dateDisplay ?></span>
                        <?php if ($timeDisplay): ?>
                        <span class="block text-xs text-gray-400"><?= $timeDisplay ?></span>
                        <?php endif; ?>
                    </td>

                    <!-- View link -->
                    <td class="px-6 py-4 text-right">
                        <a href="/status.php?id=<?= urlencode($subId) ?>"
                           class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-800 transition-colors">
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

    <!-- Row count footer -->
    <div class="px-6 py-3 border-t border-gray-100 bg-gray-50/50">
        <p class="text-xs text-gray-400">
            Showing <?= count($submissions) ?> <?= count($submissions) === 1 ? 'submission' : 'submissions' ?>
            <?php if ($filterStatus !== 'all'): ?>
            · filtered by <strong class="text-gray-600"><?= htmlspecialchars(str_replace('_', ' ', $filterStatus)) ?></strong>
            <?php endif; ?>
            <?php if ($filterFormId && isset($formMap[$filterFormId])): ?>
            · form: <strong class="text-gray-600"><?= htmlspecialchars($formMap[$filterFormId]) ?></strong>
            <?php endif; ?>
        </p>
    </div>
    <?php endif; ?>

</div><!-- /card -->

<script>
function applyFormFilter(formId) {
    const url = new URL(window.location.href);
    if (formId) {
        url.searchParams.set('form_id', formId);
    } else {
        url.searchParams.delete('form_id');
    }
    window.location.href = url.toString();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
