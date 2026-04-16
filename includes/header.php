<?php
/**
 * includes/header.php
 * Shared HTML head + top navigation bar.
 *
 * Before including this file, set:
 *   $pageTitle  — browser tab title (e.g. "Forms")
 *   $activePage — nav highlight key: 'dashboard' | 'forms' | 'groups' | 'delegations'
 */

$pageTitle  = $pageTitle  ?? 'Aurora Form Workflow';
$activePage = $activePage ?? '';
$userName   = htmlspecialchars($currentUser['display_name'] ?? 'User');
$avatarUrl  = htmlspecialchars($currentUser['avatar_url'] ?? '');

// Helper: nav link classes — inline so Tailwind CDN picks them up reliably
function navClass(string $page, string $active): string {
    $base = 'px-4 py-2 rounded-md text-sm font-semibold transition-colors duration-150 ';
    return $base . ($page === $active
        ? 'bg-white/20 text-white'
        : 'text-white/80 hover:text-white hover:bg-white/10');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — Aurora Form Workflow</title>
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
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&display=swap');
        body { font-family: 'DM Sans', system-ui, sans-serif; }

        /* Divider between logo and nav links */
        .nav-divider {
            width: 1px;
            height: 20px;
            background: rgba(255,255,255,0.25);
            margin: 0 12px;
            flex-shrink: 0;
        }

        /* Toast notification */
        .toast {
            animation: toast-in 0.3s ease-out, toast-out 0.3s ease-in 2.7s forwards;
        }
        @keyframes toast-in  { from { transform: translateY(-0.75rem); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        @keyframes toast-out { from { opacity: 1; } to { opacity: 0; pointer-events: none; } }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">

<!-- Top Nav -->
<nav class="bg-brand-800 shadow-md">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">

            <!-- Left: Logo + divider + nav links -->
            <div class="flex items-center">

                <!-- Logo + wordmark -->
                <a href="/dashboard.php" class="flex items-center gap-2.5 flex-shrink-0">
                    <img src="/assets/logo.png" class="h-14 w-auto flex-shrink-0" alt="Aurora Early Education">
                    <span class="text-white font-bold text-base tracking-tight hidden sm:inline leading-none">
                        Aurora<br><span class="font-normal text-white/75 text-xs tracking-wide uppercase">Form Workflow</span>
                    </span>
                </a>

                <!-- Divider -->
                <div class="nav-divider"></div>

                <!-- Nav links -->
                <div class="flex items-center gap-1">
                    <a href="/dashboard.php" class="<?= navClass('dashboard', $activePage) ?>">Dashboard</a>
                    <a href="/forms.php"     class="<?= navClass('forms',     $activePage) ?>">Forms</a>
                    <a href="/groups.php"    class="<?= navClass('groups',    $activePage) ?>">Groups</a>
                    <a href="/delegations.php" class="<?= navClass('delegations', $activePage) ?>">Delegations</a>
                </div>
            </div>

            <!-- Right: User info + avatar + sign out -->
            <div class="flex items-center gap-3">
                <div class="text-right hidden sm:block">
                    <div class="text-sm font-semibold text-white leading-tight"><?= $userName ?></div>
                    <div class="text-xs text-white/60 leading-tight"><?= htmlspecialchars($currentUser['email'] ?? '') ?></div>
                </div>

                <?php if ($avatarUrl): ?>
                    <img src="<?= $avatarUrl ?>" alt="" class="w-9 h-9 rounded-full border-2 border-white/30 object-cover">
                <?php else: ?>
                    <div class="w-9 h-9 rounded-full bg-brand-600 border-2 border-white/20 flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                        <?= strtoupper(substr($currentUser['display_name'] ?? 'U', 0, 1)) ?>
                    </div>
                <?php endif; ?>

                <a href="/logout.php"
                   class="w-8 h-8 rounded-md flex items-center justify-center text-white/60 hover:text-white hover:bg-white/10 transition-colors"
                   title="Sign out">
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
<div id="toast-container" class="fixed top-4 right-4 z-50 flex flex-col gap-2 pointer-events-none"></div>

<!-- Main content wrapper -->
<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
