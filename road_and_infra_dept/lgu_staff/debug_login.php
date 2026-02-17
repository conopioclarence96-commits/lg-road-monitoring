<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Session settings
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', 0);
    
    session_start();
    
    // Check if user is already logged in
    if (isset($_SESSION['user_id'])) {
        echo "Debug: User already logged in with ID: " . $_SESSION['user_id'] . "<br>";
        echo "Debug: <a href='?logout=1'>Click here to logout</a><br>";
        
        // Handle logout
        if (isset($_GET['logout'])) {
            session_destroy();
            setcookie(session_name(), '', time() - 3600, '/');
            header('Location: login.php');
            exit();
        }
    } else {
        echo "Debug: No active session found...<br>";
        echo "Debug: <a href='login.php'>Go to login page</a><br>";
    }
    
} catch (Exception $e) {
    echo "Debug: Error occurred - " . $e->getMessage() . "<br>";
}
?>
