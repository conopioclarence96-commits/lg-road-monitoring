<?php
// Session settings (must be set before session_start)
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS

// Start session
session_start();

// If this session originated from a Main LGU SSO launch, send the admin
// back to the SSO hub instead of this system's own login page.
$returnToMainLgu = !empty($_SESSION['sso_from_mainlgu']);

// Clear all session variables
$_SESSION = array();

// Destroy session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy all session data
session_destroy();

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
header("Location: login.php?t=$timestamp");
exit();
?>
