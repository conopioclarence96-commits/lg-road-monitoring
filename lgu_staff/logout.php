<?php
// Session settings (must be set before session_start)
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// If this session originated from a Main LGU SSO launch, send the admin
// back to the SSO hub instead of this system's own login page.
$returnToMainLgu = !empty($_SESSION['sso_from_mainlgu']);

// Release single-session lock for this account, then destroy PHP session.
lgu_logout_current_session();

// Add cache-busting headers
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Redirect to login page with timestamp to prevent caching
if ($returnToMainLgu) {
    $mainLguUrl = ($_SERVER['SERVER_NAME'] ?? '') === 'localhost'
        ? 'http://localhost/Main%20LGU/admin/dashboard.php'
        : 'https://infragovservices.com/admin/dashboard.php';
    header("Location: {$mainLguUrl}");
    exit();
}

$timestamp = time();
header("Location: " . rgmap_url('login', ['t' => $timestamp]));
exit();
?>
