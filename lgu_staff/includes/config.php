<?php
// Database Configuration
$server_name = $_SERVER['SERVER_NAME'] ?? 'localhost';

if ($server_name === 'localhost' || $server_name === '127.0.0.1' || strpos($server_name, '.local') !== false) {
    // Local development environment
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'lg_road_monitoring');
} else {
    // Live server environment
    $live_config = require_once __DIR__ . '/live_db_config.php';
    define('DB_HOST', $live_config['host']);
    define('DB_USER', $live_config['user']);
    define('DB_PASS', $live_config['pass']);
    define('DB_NAME', $live_config['name']);
}

// Create database connection
try {
    // Temporary debugging - remove after fixing
    if ($server_name !== 'localhost' && strpos($server_name, '.local') === false) {
        echo "<!-- DEBUG: Host=" . DB_HOST . ", User=" . DB_USER . ", DB=" . DB_NAME . " -->";
    }
    
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
} catch (Exception $e) {
    // Temporary debugging - remove after fixing
    if ($server_name !== 'localhost' && strpos($server_name, '.local') === false) {
        echo "<!-- DEBUG Exception: " . $e->getMessage() . " -->";
    }
    $conn = null;
}

// Check connection
if ($conn === null || $conn->connect_error) {
    // Temporary full error output for debugging - REMOVE AFTER FIXING
    if ($server_name !== 'localhost' && strpos($server_name, '.local') === false) {
        echo "<div style='background:#ffebee;padding:10px;margin:10px;border:1px solid #f44336;'>";
        echo "<h3>Database Connection Debug Info:</h3>";
        echo "<p><strong>Error Code:</strong> " . ($conn ? $conn->connect_errno : 'UNKNOWN') . "</p>";
        echo "<p><strong>Error Message:</strong> " . ($conn ? $conn->connect_error : 'Connection object is null') . "</p>";
        echo "<p><strong>Host:</strong> " . DB_HOST . "</p>";
        echo "<p><strong>Database:</strong> " . DB_NAME . "</p>";
        echo "<p><strong>User:</strong> " . DB_USER . "</p>";
        echo "</div>";
        die("Debug mode enabled - Fix the issue above and remove debug code");
    }
    
    // Log detailed error for debugging (safe - doesn't expose credentials)
    $error_details = [
        'timestamp' => date('Y-m-d H:i:s'),
        'error_code' => $conn ? $conn->connect_errno : 'UNKNOWN',
        'error_msg' => $conn ? $conn->connect_error : 'Connection object is null',
        'database' => DB_NAME,
        'host' => DB_HOST
    ];
    
    // Log to file (check this file for debugging)
    error_log("Database connection failed: " . json_encode($error_details));
    
    // Show user-friendly error
    if ($server_name === 'localhost' || strpos($server_name, '.local') !== false) {
        $error_msg = $conn ? $conn->connect_error : "Unable to establish database connection";
        die("Connection failed: " . $error_msg);
    } else {
        die("Database connection failed. Please contact administrator. (Error: " . ($conn ? $conn->connect_errno : 'UNKNOWN') . ")");
    }
}

// Set charset to utf8mb4
$conn->set_charset("utf8mb4");

// Application settings
define('APP_NAME', 'LGU Road Monitoring System');
define('APP_VERSION', '1.0.0');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// Email settings (configure as needed)
define('SMTP_HOST', 'localhost');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', '');
define('SMTP_PASSWORD', '');
define('FROM_EMAIL', 'noreply@lgu.gov.ph');
define('FROM_NAME', APP_NAME);
?>
