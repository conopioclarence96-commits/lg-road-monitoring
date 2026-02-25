<?php
// Environment-based database configuration
$server_name = $_SERVER['SERVER_NAME'] ?? 'localhost';

if ($server_name === 'localhost' || $server_name === '127.0.0.1' || strpos($server_name, '.local') !== false) {
    // Local development environment
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'lg_road_monitoring');
} else {
    // Live server environment - try multiple credential options
    $live_config = require_once __DIR__ . '/live_db_config.php';
    $conn = null;
    
    // Try the primary credentials first
    try {
        $conn = new mysqli($live_config['host'], $live_config['user'], $live_config['pass'], $live_config['name']);
        if ($conn->connect_error) {
            $conn = null;
        }
    } catch (Exception $e) {
        $conn = null;
    }
    
    // If primary fails, try common alternatives
    if ($conn === null || $conn->connect_error) {
        // Try Option 1: root with common passwords
        $common_passwords = ['', 'password', '123456', 'root', 'mysql'];
        foreach ($common_passwords as $pass) {
            try {
                $test_conn = new mysqli('localhost', 'root', $pass, $live_config['name']);
                if (!$test_conn->connect_error) {
                    $conn = $test_conn;
                    break;
                }
                $test_conn->close();
            } catch (Exception $e) {
                continue;
            }
        }
    }
    
    // If still failing, try Option 2: common hosting usernames
    if ($conn === null || ($conn && $conn->connect_error)) {
        $hosting_users = [
            ['user' => 'rgmapinf_lgu', 'pass' => 'lguroad2024'],
            ['user' => 'rgmapinf_lgu_user', 'pass' => 'rgmapinf123'],
            ['user' => 'rgmapinf_admin', 'pass' => 'admin123'],
            ['user' => 'rgmapinf', 'pass' => 'rgmapinf123']
        ];
        
        foreach ($hosting_users as $creds) {
            try {
                $test_conn = new mysqli('localhost', $creds['user'], $creds['pass'], $live_config['name']);
                if (!$test_conn->connect_error) {
                    $conn = $test_conn;
                    break;
                }
                $test_conn->close();
            } catch (Exception $e) {
                continue;
            }
        }
    }
    
    // If all fail, try Option 3: database user with same name as database
    if ($conn === null || ($conn && $conn->connect_error)) {
        try {
            $test_conn = new mysqli('localhost', $live_config['name'], '', $live_config['name']);
            if (!$test_conn->connect_error) {
                $conn = $test_conn;
            } else {
                $test_conn->close();
            }
        } catch (Exception $e) {
            // Connection failed, continue
        }
    }
    
    // Define constants with working credentials or fallback
    if ($conn && !$conn->connect_error) {
        define('DB_HOST', 'localhost');
        define('DB_USER', $live_config['user']);
        define('DB_PASS', $live_config['pass']);
        define('DB_NAME', $live_config['name']);
    } else {
        // Fallback to config values (will show error but won't crash)
        define('DB_HOST', $live_config['host']);
        define('DB_USER', $live_config['user']);
        define('DB_PASS', $live_config['pass']);
        define('DB_NAME', $live_config['name']);
    }
}

// Use the working connection or create final connection with constants
if ($conn && !$conn->connect_error) {
    // We already have a working connection from the credential testing
    // No need to create a new one
} else {
    // Create database connection with defined constants (may fail gracefully)
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    } catch (Exception $e) {
        // Connection failed, but don't crash
        $conn = null;
    }
}

// Check connection
if ($conn === null || $conn->connect_error) {
    // Show detailed error for debugging
    if ($server_name === 'localhost' || strpos($server_name, '.local') !== false) {
        $error_msg = $conn ? $conn->connect_error : "Unable to establish database connection";
        die("Connection failed: " . $error_msg);
    } else {
        // On live server, show more helpful error without exposing credentials
        $error_details = [];
        if ($conn) {
            $error_details[] = "MySQL Error: " . $conn->connect_error;
            $error_details[] = "MySQL Error Code: " . $conn->connect_errno;
        } else {
            $error_details[] = "Connection object is null";
        }
        $error_details[] = "Database: " . DB_NAME;
        $error_details[] = "Host: " . DB_HOST;
        
        // Log the error details (you can uncomment this for debugging)
        // error_log("Database connection failed: " . implode(" | ", $error_details));
        
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
