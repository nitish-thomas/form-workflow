<?php
/**
 * includes/auth-check.php
 * Include at the top of every authenticated page.
 * - Starts session (via config.php)
 * - Redirects to login if no session
 * - Provides $sb (Supabase client) and $currentUser
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../supabase.php';

// Gate: must have a valid session
if (empty($_SESSION['user']['id'])) {
    header('Location: /index.php');
    exit;
}

$currentUser = $_SESSION['user'];
$sb = new Supabase();
