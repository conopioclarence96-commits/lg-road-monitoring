<?php
// Root Router for LG Road Monitoring
session_start();

$basePath = '';
$loginUrl = 'login.php';

// Implementing path rooting logic
if (isset($_SERVER['SCRIPT_NAME']) && basename($_SERVER['SCRIPT_NAME']) === 'index.php') {
    $basePath = 'lg-road-monitoring/road_and_infra_dept/';
    $loginUrl = 'index.php';
}

// Include database to verify connectivity early
require_once __DIR__ . '/road_and_infra_dept/config/database.php';

try {
    $db = new Database();
    $db->getConnection();
} catch (Exception $e) {
    // Optionally log or handle early DB failure
    error_log("Root Index DB Error: " . $e->getMessage());
}

// Route to the primary entry point
require_once __DIR__ . '/road_and_infra_dept/user_and_access_management_module/login.php';
?>