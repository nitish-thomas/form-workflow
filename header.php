<?php
/**
 * includes/header.php
 * Shared HTML head + top navigation bar.
 *
 * Before including this file, set:
 *   $pageTitle  — browser tab title (e.g. "Forms")
 *   $activePage — nav highlight key: 'dashboard' | 'forms' | 'groups'
 */

$pageTitle  = $pageTitle  ?? 'Aurora Approvals';
$activePage = $activePage ?? '';
$userName   = htmlspecialchars($currentUser['display_name'] ?? 'User');
$avatarUrl  = htmlspecialchars($currentUser['avatar_url'] ?? '');
$userRole   = $currentUser['role'] ?? 'user';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — Aurora Approvals</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50:  '#eef5ff',
                            100: '#d9e8ff',
                            200: '#bcd8ff',
                            300: '#8ec1ff',
                            400: '#599fff',
                            500: '#3378fc',
                            600: '#1d5af1',
                            700: '#1545de',
                            800: '#1839b4',
                            900: '#19348d',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap');
        body { font-family: 'DM Sans', system-ui, sans-serif; }
        .nav-link { @apply px-3 py-2 rounded-md text-sm font-medium transition-colors; }
        .nav-link.active { @apply bg-brand-700 text-white; }
        .nav-link:not(.active) { @apply text-brand-100 hover:bg-brand-700/50 hover:text-white; }

        /* Toast notification */
        .toast {
            animation: toast-in 0.3s ease-out, toast-out 0.3s ease-in 2.7s forwards;
        }
        @keyframes toast-in { from { transform: translateY(-1rem); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        @keyframes toast-out { from { opacity: 1; } to { opacity: 0; } }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">

<!-- Top Nav -->
<nav class="bg-brand-800 shadow-lg">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
            <!-- Left: Logo + Links -->
            <div class="flex items-center gap-1">
                <a href="/dashboard.php" class="flex items-center gap-2 mr-6">
                    <svg class="w-8 h-8 text-brand-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                    <span class="text-white font-bold text-lg hidden sm:inline">Aurora Approvals</span>
                </a>

                <a href="/dashboard.php" class="nav-link <?= $activePage === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
                <a href="/forms.php" class="nav-link <?= $activePage === 'forms' ? 'active' : '' ?>">Forms</a>
                <a href="/groups.php" class="nav-link <?= $activePage === 'groups' ? 'active' : '' ?>">Groups</a>
            </div>

            <!-- Right: User Menu -->
            <div class="flex items-center gap-3">
                <div class="text-right hidden sm:block">
                    <div class="text-sm font-medium text-white"><?= $userName ?></div>
                    <div class="text-xs text-brand-300"><?= htmlspecialchars($currentUser['email'] ?? '') ?></div>
                </div>
                <?php if ($avatarUrl): ?>
                    <img src="<?= $avatarUrl ?>" alt="" class="w-9 h-9 rounded-full border-2 border-brand-400">
                <?php else: ?>
                    <div class="w-9 h-9 rounded-full bg-brand-600 flex items-center justify-center text-white font-semibold text-sm">
                        <?= strtoupper(substr($currentUser['display_name'] ?? 'U', 0, 1)) ?>
                    </div>
                <?php endif; ?>
                <a href="/logout.php" class="text-brand-200 hover:text-white transition-colors ml-2" title="Sign out">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                </a>
            </div>
        </div>
    </div>
</nav>

<!-- Toast container -->
<div id="toast-container" class="fixed top-4 right-4 z-50 flex flex-col gap-2"></div>

<!-- Main content wrapper -->
<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
