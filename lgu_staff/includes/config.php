<?php
// Enable mysqli error reporting for proper exception handling
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/routes.php';

/**
 * Read a config value from the root .env file (same convention as the
 * TOMTOM_API_KEY / BREVO_* reads below) falling back to a real process env
 * var of the same name, then to $default. Parsing is cached so repeated calls
 * only read the file once per request.
 *
 * Note: .env comment lines must use ";" not "#" — parse_ini_file() throws on
 * "#" lines containing parentheses (see .env.example).
 */
function env_get(string $key, string $default = '') {
    static $envVariables = null;
    if ($envVariables === null) {
        $envFile = __DIR__ . '/../../.env';
        $envVariables = file_exists($envFile) ? (parse_ini_file($envFile) ?: []) : [];
    }
    $value = $envVariables[$key] ?? getenv($key);
    return ($value !== false && $value !== null && trim((string)$value) !== '') ? trim((string)$value) : $default;
}

// Environment detection
$server_name = $_SERVER['SERVER_NAME'] ?? 'localhost';
$is_local = ($server_name === 'localhost' || $server_name === '127.0.0.1' || strpos($server_name, '.local') !== false);

// Database configuration based on environment.
// Local: .env DB_* (or the previous zero-config defaults).
// Live: always live_db_config.php. A copied .env is needed on the server for
// API keys (TOMTOM/BREVO/IPMS), but those same DB_* values are local creds
// and must not override the live connection.
if ($is_local) {
    define('DB_HOST', env_get('DB_HOST', 'localhost'));
    define('DB_USER', env_get('DB_USER', 'root'));
    define('DB_PASS', env_get('DB_PASS', ''));
    define('DB_NAME', env_get('DB_NAME', 'rgmap_lg_road_monitoring'));
} else {
    $live_config = require __DIR__ . '/live_db_config.php';
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
    
    // Sync MySQL timezone with PHP timezone
    $conn->query("SET time_zone = '+08:00'");
    
    // Ensure last_login column exists
    try {
        $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login TIMESTAMP NULL AFTER updated_at");
    } catch (Exception $e) {
        // Column may already exist, ignore
    }

    // Ensure last_activity column exists (tracks live user activity, not just logins)
    try {
        $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_activity TIMESTAMP NULL AFTER last_login");
    } catch (Exception $e) {
        // Column may already exist, ignore
    }

    // Single-session login: tracks the PHP session id currently holding the account.
    try {
        $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS active_session_id VARCHAR(128) NULL DEFAULT NULL AFTER last_activity");
    } catch (Exception $e) {
        // Column may already exist, ignore
    }

    // Backfill last_activity from last_login for accounts with no activity tracked yet
    try {
        $conn->query("UPDATE users SET last_activity = last_login WHERE last_activity IS NULL AND last_login IS NOT NULL");
    } catch (Exception $e) {
        // Best-effort backfill, ignore
    }
    
    // Ensure report_updates table exists
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS report_updates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_id INT NOT NULL,
            user_id INT,
            title VARCHAR(255) DEFAULT NULL,
            description TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_report_id (report_id),
            INDEX idx_created_at (created_at),
            FOREIGN KEY (report_id) REFERENCES road_transportation_reports(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {
        error_log("report_updates table creation: " . $e->getMessage());
    }

    // Drop FK on report_updates.report_id so updates can reference reports from any table
    // (transportation, maintenance, or CIMM)
    try {
        $fk_rows = $conn->query("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_NAME = 'report_updates' AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND TABLE_SCHEMA = '" . DB_NAME . "'");
        if ($fk_rows) {
            while ($fk = $fk_rows->fetch_assoc()) {
                $conn->query("ALTER TABLE report_updates DROP FOREIGN KEY `{$fk['CONSTRAINT_NAME']}`");
            }
        }
    } catch (Exception $e) {
        // FK may already be dropped or not exist
    }

    try {
        $conn->query("ALTER TABLE report_updates ADD COLUMN IF NOT EXISTS completion_percentage TINYINT UNSIGNED NULL DEFAULT NULL AFTER description");
    } catch (Exception $e) {
        error_log("report_updates completion_percentage column: " . $e->getMessage());
    }
    
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS report_update_media (
            id INT AUTO_INCREMENT PRIMARY KEY,
            update_id INT NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_type ENUM('image','video') DEFAULT 'image',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_update_id (update_id),
            FOREIGN KEY (update_id) REFERENCES report_updates(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {
        error_log("report_update_media table creation: " . $e->getMessage());
    }
    
    // Same dump-restore damage as report_notifications below: without the key and
    // AUTO_INCREMENT, every progress-update image insert fails and updates end up
    // with no media at all.
    try {
        $rum_id = $conn->query("SELECT COLUMN_KEY, EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = 'report_update_media' AND COLUMN_NAME = 'id'");
        $rum_id_row = $rum_id ? $rum_id->fetch_assoc() : null;
        if ($rum_id_row && stripos((string)$rum_id_row['EXTRA'], 'auto_increment') === false) {
            if (strtoupper((string)$rum_id_row['COLUMN_KEY']) !== 'PRI') {
                $conn->query("ALTER TABLE report_update_media ADD PRIMARY KEY (id)");
            }
            $conn->query("ALTER TABLE report_update_media MODIFY id INT NOT NULL AUTO_INCREMENT");
        }
    } catch (Exception $e) {
        error_log("report_update_media.id auto_increment repair: " . $e->getMessage());
    }

    try {
        $conn->query("CREATE TABLE IF NOT EXISTS report_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_id INT NOT NULL,
            update_id INT DEFAULT NULL,
            type VARCHAR(50) DEFAULT 'progress_update',
            message TEXT NOT NULL,
            recipient_email VARCHAR(100) DEFAULT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_report_id (report_id),
            INDEX idx_is_read (is_read),
            FOREIGN KEY (report_id) REFERENCES road_transportation_reports(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {
        error_log("report_notifications table creation: " . $e->getMessage());
    }

    // Drop FK on report_notifications.report_id so notifications can reference reports from any table
    try {
        $fk_rows = $conn->query("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_NAME = 'report_notifications' AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND TABLE_SCHEMA = '" . DB_NAME . "'");
        if ($fk_rows) {
            while ($fk = $fk_rows->fetch_assoc()) {
                $conn->query("ALTER TABLE report_notifications DROP FOREIGN KEY `{$fk['CONSTRAINT_NAME']}`");
            }
        }
    } catch (Exception $e) {
        // FK may already be dropped or not exist
    }

    // Role-targeted notifications: NULL means a broadcast (seen by everyone with
    // access); a specific value (e.g. 'road_ops_supervisor') restricts visibility
    // to users holding that role. Used for completion/cancellation request reviews.
    try {
        $conn->query("ALTER TABLE report_notifications ADD COLUMN IF NOT EXISTS recipient_role VARCHAR(50) DEFAULT NULL AFTER recipient_email");
    } catch (Exception $e) {
        error_log("report_notifications.recipient_role migration: " . $e->getMessage());
    }

    // Databases restored from a dump can come back with `id` stripped of its key
    // and AUTO_INCREMENT, which makes every notification INSERT fail under strict
    // mode ("Field 'id' doesn't have a default value").
    try {
        $rn_id = $conn->query("SELECT COLUMN_KEY, EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = 'report_notifications' AND COLUMN_NAME = 'id'");
        $rn_id_row = $rn_id ? $rn_id->fetch_assoc() : null;
        if ($rn_id_row && stripos((string)$rn_id_row['EXTRA'], 'auto_increment') === false) {
            if (strtoupper((string)$rn_id_row['COLUMN_KEY']) !== 'PRI') {
                $conn->query("ALTER TABLE report_notifications ADD PRIMARY KEY (id)");
            }
            $conn->query("ALTER TABLE report_notifications MODIFY id INT NOT NULL AUTO_INCREMENT");
        }
    } catch (Exception $e) {
        error_log("report_notifications.id auto_increment repair: " . $e->getMessage());
    }
    
    // Ensure citizen report columns exist
    try {
        $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN IF NOT EXISTS report_category VARCHAR(50) AFTER report_type");
    } catch (Exception $e) {}
    try {
        $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN IF NOT EXISTS report_source VARCHAR(50) AFTER report_category");
    } catch (Exception $e) {}
    try {
        $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN IF NOT EXISTS image_path VARCHAR(500) AFTER attachments");
    } catch (Exception $e) {}
    try {
        $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN IF NOT EXISTS reporter_name VARCHAR(100) AFTER reporter_email");
    } catch (Exception $e) {}
    try {
        $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN IF NOT EXISTS reporter_phone VARCHAR(20) AFTER reporter_name");
    } catch (Exception $e) {}
    // GIS spatial columns for district/barangay detection
    try {
        $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN IF NOT EXISTS detected_district VARCHAR(50) NULL AFTER longitude");
    } catch (Exception $e) {}
    try {
        $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN IF NOT EXISTS barangay VARCHAR(100) NULL AFTER detected_district");
    } catch (Exception $e) {}
    try {
        $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN IF NOT EXISTS street_name VARCHAR(255) NULL AFTER barangay");
    } catch (Exception $e) {}
    
    // Ensure completed_at columns exist for duration tracking
    try {
        $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN IF NOT EXISTS completed_at TIMESTAMP NULL AFTER updated_at");
    } catch (Exception $e) {}
    try {
        $conn->query("ALTER TABLE road_maintenance_reports ADD COLUMN IF NOT EXISTS completed_at TIMESTAMP NULL AFTER updated_at");
    } catch (Exception $e) {}

    // Account lockout columns for brute-force protection
    try {
        $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS failed_attempts INT NOT NULL DEFAULT 0 AFTER last_login");
    } catch (Exception $e) {}
    try {
        $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS lock_until DATETIME NULL DEFAULT NULL AFTER failed_attempts");
    } catch (Exception $e) {}
    try {
        $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS lock_level TINYINT NOT NULL DEFAULT 0 AFTER lock_until");
    } catch (Exception $e) {}

    // Forced password change fields for admin-created accounts
    try {
        $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 1 AFTER lock_level");
    } catch (Exception $e) {}
    try {
        $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS temporary_password_created_at DATETIME NULL DEFAULT NULL AFTER must_change_password");
    } catch (Exception $e) {}
    try {
        $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL DEFAULT NULL AFTER temporary_password_created_at");
    } catch (Exception $e) {}
    // Existing accounts created before this feature must NOT be forced to change
    // passwords. New accounts created through create_staff_account.php always set
    // temporary_password_created_at so they are intentionally left at must_change_password = 1.
    try {
        $conn->query("UPDATE users SET must_change_password = 0 WHERE must_change_password = 1 AND temporary_password_created_at IS NULL AND password_changed_at IS NULL");
    } catch (Exception $e) {}

    // Email access tokens for magic-link login / registration (see database/create_user_token.sql).
    // login_token never expires (toggled via login_token_active); register_token expires after 1 day.
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS user_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            login_token CHAR(64) NOT NULL UNIQUE,
            login_token_active TINYINT(1) NOT NULL DEFAULT 1,
            register_token CHAR(64) NOT NULL UNIQUE,
            register_token_expires_at DATETIME NOT NULL,
            register_token_used_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {
        error_log("user_tokens table creation: " . $e->getMessage());
    }
    
    // Create project_analytics table for recording completion metrics
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS project_analytics (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_id INT NOT NULL,
            report_table VARCHAR(50) NOT NULL DEFAULT 'road_transportation_reports',
            user_id INT DEFAULT NULL,
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            duration_seconds BIGINT DEFAULT 0,
            duration_days DECIMAL(10,2) DEFAULT 0.00,
            priority VARCHAR(20) DEFAULT 'medium',
            department VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_report_id (report_id),
            INDEX idx_completed_at (completed_at),
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {
        error_log("project_analytics table creation: " . $e->getMessage());
    }
    
    // Ensure account_status supports 'deactivated' value
    try {
        $row = $conn->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'users' AND COLUMN_NAME = 'account_status' AND TABLE_SCHEMA = '" . DB_NAME . "'")->fetch_assoc();
        if ($row && strpos($row['COLUMN_TYPE'], 'deactivated') === false) {
            $conn->query("ALTER TABLE users MODIFY COLUMN account_status VARCHAR(20) DEFAULT 'pending'");
        }
    } catch (Exception $e) {
        // Ignore
    }
    
    // Ensure role supports the road/transportation roles
    try {
        $row = $conn->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'users' AND COLUMN_NAME = 'role' AND TABLE_SCHEMA = '" . DB_NAME . "'")->fetch_assoc();
        if ($row && strpos($row['COLUMN_TYPE'], 'road_ops_supervisor') === false) {
            $conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('system_admin', 'lgu_staff', 'citizen', 'road_ops_supervisor', 'trans_ops_supervisor', 'road_monitoring_officer', 'trans_monitoring_officer') DEFAULT 'citizen'");
        }
    } catch (Exception $e) {
        // Ignore
    }
    
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

// TomTom API Key - Load from .env or environment variable
define('TOMTOM_API_KEY', env_get('TOMTOM_API_KEY', ''));

// TomTom API Services
require_once __DIR__ . '/tomtom/autoload.php';

// Pagination settings
define('ITEMS_PER_PAGE', 20);

// Email settings
define('SMTP_HOST', 'localhost');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', '');
define('SMTP_PASSWORD', '');
define('FROM_EMAIL', 'noreply@lgu.gov.ph');
define('FROM_NAME', APP_NAME);

// Forced password change guard. Admin-created accounts start with a temporary
// password and must_change_password = 1, so they may only reach change_password.php
// until they set their own password. config.php is required before any output on
// every page, so this runs early enough for a clean redirect.

// Track live user activity: persist a lightweight last_activity timestamp in the
// DB on each page load, throttled to once per minute per session to avoid
// excessive writes. Runs for every authenticated request (regular pages and AJAX).
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
    $la_now = time();
    try {
        if (!isset($_SESSION['last_db_activity']) || ($la_now - $_SESSION['last_db_activity']) >= 60) {
            $la_stmt = $conn->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
            $la_stmt->bind_param("i", $_SESSION['user_id']);
            $la_stmt->execute();
            $la_stmt->close();
            $_SESSION['last_db_activity'] = $la_now;
        }
    } catch (Exception $e) {
        // Non-fatal; activity tracking is best-effort
        error_log("last_activity update: " . $e->getMessage());
    }

    // Opportunistic cleanup of expired single-session locks (all accounts).
    try {
        if (!isset($_SESSION['last_session_lock_cleanup']) || ($la_now - (int)$_SESSION['last_session_lock_cleanup']) >= 300) {
            $idle_secs = function_exists('lgu_session_idle_seconds') ? (int)lgu_session_idle_seconds() : 1800;
            $conn->query(
                "UPDATE users
                 SET active_session_id = NULL
                 WHERE active_session_id IS NOT NULL
                   AND active_session_id != ''
                   AND (last_activity IS NULL OR TIMESTAMPDIFF(SECOND, last_activity, NOW()) >= " . (int)$idle_secs . ")"
            );
            $_SESSION['last_session_lock_cleanup'] = $la_now;
        }
    } catch (Exception $e) {
        error_log("active_session_id cleanup: " . $e->getMessage());
    }
}

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
    $mcp_page = basename($_SERVER['PHP_SELF'] ?? '');
    if ($mcp_page !== 'change_password.php' && $mcp_page !== 'logout.php') {
        // Derive the app web root (everything before /lgu_staff/ in SCRIPT_NAME),
        // so the redirect works from any page depth and on the live server.
        $mcp_root = '';
        $mcp_script = $_SERVER['SCRIPT_NAME'] ?? '';
        $mcp_pos = strpos($mcp_script, '/lgu_staff/');
        if ($mcp_pos !== false) {
            $mcp_root = substr($mcp_script, 0, $mcp_pos);
        }
        try {
            $mcp_stmt = $conn->prepare("SELECT must_change_password FROM users WHERE id = ?");
            $mcp_stmt->bind_param("i", $_SESSION['user_id']);
            $mcp_stmt->execute();
            $mcp_row = $mcp_stmt->get_result()->fetch_assoc();
            $mcp_stmt->close();
            if ($mcp_row && !empty($mcp_row['must_change_password'])) {
                $is_api_req = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
                    || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                    || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);
                if ($is_api_req) {
                    http_response_code(403);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'message' => 'You must change your password before continuing.']);
                    exit;
                }
                header('Location: ' . rgmap_url('change-password'));
                exit;
            }
        } catch (Exception $e) {
            error_log("Password change guard: " . $e->getMessage());
        }
    }
}

rgmap_enforce_clean_url();
?>
