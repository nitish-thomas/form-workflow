<?php
/**
 * index.php — Landing page / Login gate
 *
 * If the user has an active session → redirect to dashboard.
 * Otherwise → show the Google sign-in button.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/supabase.php';

// ── Already logged in? ──────────────────────────────────
if (!empty($_SESSION['user'])) {
    header('Location: /dashboard.php');
    exit;
}

// ── Auth code returned? Hand off to callback ────────────
if (!empty($_GET['code'])) {
    // Supabase sometimes redirects here instead of auth-callback.php.
    // Forward the code — the PKCE verifier is already in the session.
    header('Location: /auth-callback.php?code=' . urlencode($_GET['code']));
    exit;
}

// ── Build the Google OAuth URL ──────────────────────────
$loginUrl = Supabase::getGoogleOAuthURL();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(APP_NAME) ?> — Sign In</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen,
                         Ubuntu, Cantarell, 'Helvetica Neue', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f0f2f5;
            color: #1a1a2e;
        }

        .login-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            padding: 3rem 2.5rem;
            max-width: 400px;
            width: 90%;
            text-align: center;
        }

        .login-card h1 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .login-card p {
            color: #6b7280;
            margin-bottom: 2rem;
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .google-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1.5rem;
            border: 1px solid #dadce0;
            border-radius: 8px;
            background: #fff;
            color: #3c4043;
            font-size: 0.95rem;
            font-weight: 500;
            text-decoration: none;
            transition: background 0.15s, box-shadow 0.15s;
        }

        .google-btn:hover {
            background: #f7f8f8;
            box-shadow: 0 1px 3px rgba(0,0,0,0.12);
        }

        .google-btn svg {
            width: 20px;
            height: 20px;
        }

        .footer-note {
            margin-top: 2rem;
            font-size: 0.8rem;
            color: #9ca3af;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <h1><?= htmlspecialchars(APP_NAME) ?></h1>
        <p>Sign in with your Aurora Google Workspace account to continue.</p>

        <a href="<?= htmlspecialchars($loginUrl) ?>" class="google-btn">
            <!-- Google "G" logo (inline SVG) -->
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/>
                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18A10.96 10.96 0 0 0 1 12c0 1.77.42 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05"/>
                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
            </svg>
            Sign in with Google
        </a>

        <p class="footer-note">
            Restricted to <strong>@aurora…</strong> workspace accounts.
        </p>
    </div>
</body>
</html>
