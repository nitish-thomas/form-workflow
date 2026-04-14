<?php
/**
 * config.example.php — Aurora Approval Workflow
 *
 * Copy this file to config.php and fill in your real values.
 * config.php is gitignored — NEVER commit the real file.
 *
 * On SiteGround, keep config.php ABOVE public_html or protect it
 * with .htaccess so it's never served directly.
 */

// ── Supabase ────────────────────────────────────────────
// Use the NEW API keys from Supabase: Project Settings → API → "Publishable and secret API keys".
// Do NOT use the legacy anon / service_role JWTs (those start with "eyJ…").
define('SUPABASE_URL',              'https://YOUR-PROJECT.supabase.co');
define('SUPABASE_PUBLISHABLE_KEY',  'sb_publishable_XXXXXXXXXXXXXXXXXXXX');  // safe to expose (client-side OK)
define('SUPABASE_SECRET_KEY',       'sb_secret_XXXXXXXXXXXXXXXXXXXXXXXXX'); // server-side only — never commit

// ── App ─────────────────────────────────────────────────
define('APP_URL',  'https://formworkflow.auroraearlyeducation.com.au');   // no trailing slash, no trailing period
define('APP_NAME', 'Aurora Approvals');
define('APP_ENV',  'production');                          // 'development' for debug

// ── Session ─────────────────────────────────────────────
define('SESSION_LIFETIME', 86400);  // 24 hours in seconds

// ── Token expiry for email approve links ────────────────
define('TOKEN_EXPIRY_HOURS', 72);

// ── Timezone ────────────────────────────────────────────
date_default_timezone_set('Australia/Sydney');

// ── Error reporting (turn off in production) ────────────
if (APP_ENV === 'development') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// ── Start session ───────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
