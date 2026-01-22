<?php
// Logout script for Road and Infrastructure Department
require_once '../config/auth.php';
require_once '../config/database.php';

// Use Auth class to handle logout and database cleanup
$auth->logout();
?>
