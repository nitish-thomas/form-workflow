<?php
/**
 * dashboard.php — Phase 2 placeholder dashboard
 * Shows quick stats and links to form management.
 * Full dashboard with submissions/approvals comes in Phase 5.
 */

require_once __DIR__ . '/includes/auth-check.php';

// Fetch quick counts
$forms  = $sb->from('forms')->select('id')->execute() ?? [];
$groups = $sb->from('recipient_groups')->select('id')->execute() ?? [];
$users  = $sb->from('users')->select('id')->execute() ?? [];

$formCount  = count($forms);
$groupCount = count($groups);
$userCount  = count($users);

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Page heading -->
<div class="mb-8">
    <h1 class="text-2xl font-bold text-gray-900">Welcome back, <?= htmlspecialchars($currentUser['display_name'] ?? 'there') ?></h1>
    <p class="mt-1 text-sm text-gray-500">Aurora Approvals administration panel</p>
</div>

<!-- Stat cards -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-10">
    <!-- Forms -->
    <a href="/forms.php" class="block bg-white rounded-xl border border-gray-200 p-6 hover:shadow-md hover:border-brand-300 transition-all group">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-500">Approval Forms</p>
                <p class="mt-1 text-3xl font-bold text-gray-900"><?= $formCount ?></p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-brand-50 flex items-center justify-center group-hover:bg-brand-100 transition-colors">
                <svg class="w-6 h-6 text-brand-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
        </div>
        <p class="mt-3 text-xs text-brand-600 font-medium group-hover:underline">Manage forms →</p>
    </a>

    <!-- Groups -->
    <a href="/groups.php" class="block bg-white rounded-xl border border-gray-200 p-6 hover:shadow-md hover:border-brand-300 transition-all group">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-500">Recipient Groups</p>
                <p class="mt-1 text-3xl font-bold text-gray-900"><?= $groupCount ?></p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-emerald-50 flex items-center justify-center group-hover:bg-emerald-100 transition-colors">
                <svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </div>
        </div>
        <p class="mt-3 text-xs text-emerald-600 font-medium group-hover:underline">Manage groups →</p>
    </a>

    <!-- Users -->
    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-500">Registered Users</p>
                <p class="mt-1 text-3xl font-bold text-gray-900"><?= $userCount ?></p>
            </div>
            <div class="w-12 h-12 rounded-lg bg-amber-50 flex items-center justify-center">
                <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
            </div>
        </div>
        <p class="mt-3 text-xs text-gray-400">Users who have signed in via Google</p>
    </div>
</div>

<!-- Quick actions -->
<div class="bg-white rounded-xl border border-gray-200 p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">Quick Actions</h2>
    <div class="flex flex-wrap gap-3">
        <a href="/forms.php?action=new"
           class="inline-flex items-center gap-2 px-4 py-2 bg-brand-600 text-white text-sm font-medium rounded-lg hover:bg-brand-700 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            New Approval Form
        </a>
        <a href="/groups.php?action=new"
           class="inline-flex items-center gap-2 px-4 py-2 bg-white text-gray-700 text-sm font-medium rounded-lg border border-gray-300 hover:bg-gray-50 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            New Recipient Group
        </a>
    </div>
</div>

<!-- Phase notice -->
<div class="mt-8 bg-amber-50 border border-amber-200 rounded-lg p-4">
    <div class="flex items-start gap-3">
        <svg class="w-5 h-5 text-amber-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <div>
            <p class="text-sm font-medium text-amber-800">Phase 2 — Form Configuration</p>
            <p class="text-sm text-amber-700 mt-1">
                You're currently in the setup phase. Use the navigation above to create forms,
                define approval stages, assign recipients, and configure routing rules.
                Submissions, email notifications, and the full dashboard arrive in later phases.
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
