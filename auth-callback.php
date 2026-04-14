<?php
/**
 * auth-callback.php — Supabase OAuth callback
 *
 * Flow:
 *   1. Supabase redirects here with ?code=…
 *   2. We exchange the code for an access_token + user profile
 *   3. Upsert the user into our `users` table
 *   4. Store session and redirect to dashboard
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/supabase.php';

// ── 1. Grab the auth code ───────────────────────────────
$code = $_GET['code'] ?? null;

if (!$code) {
    http_response_code(400);
    die('Missing authorisation code. <a href="/">Try again</a>.');
}

// ── 2. Retrieve PKCE verifier from session ──────────────
$codeVerifier = $_SESSION['pkce_code_verifier'] ?? null;
unset($_SESSION['pkce_code_verifier']); // one-time use

if (!$codeVerifier) {
    http_response_code(400);
    die('<h3>Debug: PKCE verifier missing</h3>'
      . '<p>The session did not contain a code verifier. This means the session was lost between login and callback.</p>'
      . '<p>Session ID: ' . session_id() . '</p>'
      . '<p><a href="/">Try again</a></p>');
}

// ── 3. Exchange code + verifier for session ─────────────
$session = Supabase::exchangeCodeForSession($code, $codeVerifier);

if (!$session || empty($session['access_token'])) {
    // ── TEMPORARY DEBUG — remove after fixing ───────────
    echo '<h3>Debug: Token exchange failed</h3>';
    echo '<pre>';
    echo 'Code: '     . htmlspecialchars(substr($code, 0, 20)) . '…' . "\n";
    echo 'Verifier: ' . htmlspecialchars(substr($codeVerifier, 0, 10)) . '…' . "\n\n";
    echo 'Supabase response:' . "\n";
    print_r($session);
    echo '</pre>';
    echo '<p><a href="/">Try again</a></p>';
    exit;
}

$accessToken = $session['access_token'];

// ── 4. Fetch user profile from Supabase Auth ───────────
$authUser = Supabase::getAuthUser($accessToken);

if (!$authUser || empty($authUser['id'])) {
    http_response_code(401);
    error_log('OAuth callback: getAuthUser failed');
    die('Could not retrieve user profile. <a href="/">Try again</a>.');
}

$authId      = $authUser['id'];
$email       = $authUser['email'] ?? '';
$displayName = $authUser['user_metadata']['full_name']
            ?? $authUser['user_metadata']['name']
            ?? explode('@', $email)[0];
$avatarUrl   = $authUser['user_metadata']['avatar_url']
            ?? $authUser['user_metadata']['picture']
            ?? '';

// ── 5. Upsert into our users table ─────────────────────
$sb = new Supabase();

// Check if user already exists
$existing = $sb->from('users')
    ->select('id,role')
    ->eq('auth_id', $authId)
    ->execute();

if ($existing && count($existing) > 0) {
    // Update name/avatar in case they changed
    $sb->from('users')
        ->eq('auth_id', $authId)
        ->update([
            'display_name' => $displayName,
            'avatar_url'   => $avatarUrl,
        ]);
    $userId = $existing[0]['id'];
    $role   = $existing[0]['role'];
} else {
    // First login — create user record
    $inserted = $sb->from('users')->insert([
        'auth_id'      => $authId,
        'email'        => $email,
        'display_name' => $displayName,
        'avatar_url'   => $avatarUrl,
        'role'         => 'user',   // default; promote manually in DB
    ]);

    if (!$inserted || count($inserted) === 0) {
        http_response_code(500);
        error_log('OAuth callback: user insert failed');
        die('Account creation failed. Please contact an administrator.');
    }

    $userId = $inserted[0]['id'];
    $role   = 'user';
}

// ── 6. Store session ────────────────────────────────────
$_SESSION['user'] = [
    'id'           => $userId,
    'auth_id'      => $authId,
    'email'        => $email,
    'display_name' => $displayName,
    'avatar_url'   => $avatarUrl,
    'role'         => $role,
];

$_SESSION['access_token']  = $accessToken;
$_SESSION['refresh_token'] = $session['refresh_token'] ?? null;

// ── 7. Redirect to dashboard ───────────────────────────
header('Location: /dashboard.php');
exit;
