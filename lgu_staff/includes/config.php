<?php
// Enable mysqli error reporting for proper exception handling
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Environment detection
$server_name = $_SERVER['SERVER_NAME'] ?? 'localhost';
$is_local = ($server_name === 'localhost' || $server_name === '127.0.0.1' || strpos($server_name, '.local') !== false);

// Database configuration based on environment
if ($is_local) {
    // Local development environment
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', 'root123');
    define('DB_NAME', 'rgmap_lg_road_monitoring');
} else {
    // Live server environment
    $live_config = require_once __DIR__ . '/live_db_config.php';
    define('DB_HOST', $live_config['host']);
    define('DB_USER', $live_config['user']);
    define('DB_PASS', $live_config['pass']);
    define('DB_NAME', $live_config['name']);
}

// Initialize connection variable
$conn = null;

// Create database connection with proper error handling
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    // Set charset to UTF-8
    $conn->set_charset("utf8mb4");
    
} catch (mysqli_sql_exception $e) {
    // Log error without exposing credentials
    $error_details = [
        'timestamp' => date('Y-m-d H:i:s'),
        'error_code' => $e->getCode(),
        'error_msg' => $e->getMessage(),
        'database' => DB_NAME,
        'host' => DB_HOST
    ];
    
    error_log("Database connection failed: " . json_encode($error_details));
    
    // Show appropriate error message
    if ($is_local) {
        die("Database connection failed: " . $e->getMessage() . " (Error Code: " . $e->getCode() . ")");
    } else {
        die("Database connection failed. Please contact administrator. (Error Code: " . $e->getCode() . ")");
    }
} catch (Exception $e) {
    // Handle other exceptions
    error_log("Unexpected database error: " . $e->getMessage());
    
    if ($is_local) {
        die("Unexpected database error: " . $e->getMessage());
    } else {
        die("Database error occurred. Please contact administrator.");
    }
}

// Error reporting configuration
if ($is_local) {
    // Show all errors in development
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    // Hide errors in production
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Application settings
define('APP_NAME', 'LGU Road Monitoring System');
define('APP_VERSION', '1.0.0');

// Timezone
date_default_timezone_set('Asia/Manila');

// Security settings
define('HASH_ALGO', 'sha256');
define('SALT_LENGTH', 32);

// File upload settings
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_FILE_TYPES', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'jpg', 'jpeg', 'png']);

// Pagination settings
define('ITEMS_PER_PAGE', 20);

// Email settings
define('SMTP_HOST', 'localhost');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', '');
define('SMTP_PASSWORD', '');
define('FROM_EMAIL', 'noreply@lgu.gov.ph');
define('FROM_NAME', APP_NAME);
?>
