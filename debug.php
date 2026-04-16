<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h3>1. Config</h3>";
require_once __DIR__ . '/config.php';
echo "OK<br>";

echo "<h3>2. Supabase class</h3>";
require_once __DIR__ . '/supabase.php';
echo "OK<br>";

echo "<h3>3. Session</h3>";
echo "Session user: " . (isset($_SESSION['user']) ? 'exists' : 'not set') . "<br>";

echo "<h3>4. Includes directory</h3>";
echo "auth-check.php exists: " . (file_exists(__DIR__ . '/includes/auth-check.php') ? 'YES' : 'NO') . "<br>";
echo "header.php exists: " . (file_exists(__DIR__ . '/includes/header.php') ? 'YES' : 'NO') . "<br>";
echo "footer.php exists: " . (file_exists(__DIR__ . '/includes/footer.php') ? 'YES' : 'NO') . "<br>";

echo "<h3>5. Test auth-check include</h3>";
require_once __DIR__ . '/includes/auth-check.php';
echo "OK — logged in as: " . htmlspecialchars($currentUser['display_name'] ?? '?');