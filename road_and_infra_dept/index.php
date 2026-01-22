<?php
// Main Router and Entry Point for Road and Infrastructure Department
session_start();
require_once 'config/auth.php';

// Redirect to dashboard if logged in, otherwise to the unified login page
if ($auth->isLoggedIn()) {
    $auth->redirectToDashboard();
} else {
    header('Location: user_and_access_management_module/login.php');
}
exit;
?>
