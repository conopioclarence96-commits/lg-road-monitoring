<?php
// Security functions
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// User authentication functions
function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/** Idle window (seconds) after which a stored session lock is treated as expired. */
function lgu_session_idle_seconds() {
    return 30 * 60;
}

/** Resolve the on-disk path for a PHP session id (default file handler). */
function lgu_php_session_file_path($session_id) {
    $session_id = trim((string)$session_id);
    if ($session_id === '' || !preg_match('/^[a-zA-Z0-9,-]{1,128}$/', $session_id)) {
        return null;
    }

    $save_path = session_save_path();
    if ($save_path === '') {
        $save_path = sys_get_temp_dir();
    } elseif (preg_match('/^\d+;(.+)$/', $save_path, $matches)) {
        $save_path = $matches[1];
    }

    return rtrim($save_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sess_' . $session_id;
}

/**
 * True when the stored PHP session file still exists and belongs to $user_id.
 * Browsers that close without logout destroy the session file while the DB lock
 * can remain — treating a missing file as stale prevents false "already logged in".
 */
function lgu_stored_session_is_alive($session_id, $user_id = null) {
    $path = lgu_php_session_file_path($session_id);
    if ($path === null || !is_file($path)) {
        return false;
    }
    if ($user_id === null || (int)$user_id <= 0) {
        return true;
    }

    $data = @file_get_contents($path);
    if ($data === false || $data === '') {
        return false;
    }

    $uid = (int)$user_id;
    return strpos($data, 'user_id|i:' . $uid . ';') !== false
        || (bool)preg_match('/user_id[|;]"i:' . $uid . '[;"]/', $data);
}

/**
 * True when another device/browser already holds an active session for this account.
 * Same PHP session id is allowed (re-login / refresh). Stale locks (no activity within
 * the idle window, or missing PHP session file) are cleared so accounts are not locked.
 */
function lgu_account_has_active_session($user_id, $current_session_id = null) {
    global $conn;
    $user_id = (int)$user_id;
    if ($user_id <= 0 || !$conn) {
        return false;
    }
    if ($current_session_id === null && session_status() === PHP_SESSION_ACTIVE) {
        $current_session_id = session_id();
    }

    $idle = (int)lgu_session_idle_seconds();
    try {
        // Drop expired locks so accounts become available again after inactivity.
        $cleanup = $conn->prepare(
            "UPDATE users
             SET active_session_id = NULL
             WHERE id = ?
               AND active_session_id IS NOT NULL
               AND active_session_id != ''
               AND (last_activity IS NULL OR TIMESTAMPDIFF(SECOND, last_activity, NOW()) >= ?)"
        );
        if ($cleanup) {
            $cleanup->bind_param('ii', $user_id, $idle);
            $cleanup->execute();
            $cleanup->close();
        }

        $stmt = $conn->prepare(
            "SELECT active_session_id
             FROM users
             WHERE id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $active_sid = trim((string)($row['active_session_id'] ?? ''));
        if ($active_sid === '') {
            return false;
        }
        if ($current_session_id !== null && $current_session_id !== '' && hash_equals($active_sid, (string)$current_session_id)) {
            return false; // same browser/session
        }
        if (!lgu_stored_session_is_alive($active_sid, $user_id)) {
            lgu_release_user_session($user_id, $active_sid);
            return false;
        }
        return true;
    } catch (Exception $e) {
        error_log('lgu_account_has_active_session: ' . $e->getMessage());
        return false;
    }
}

/** Bind the account to the current PHP session (call after successful auth). */
function lgu_claim_user_session($user_id) {
    global $conn;
    $user_id = (int)$user_id;
    if ($user_id <= 0 || !$conn || session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    $sid = session_id();
    if ($sid === '') {
        return false;
    }
    try {
        $stmt = $conn->prepare(
            "UPDATE users
             SET active_session_id = ?, last_login = NOW(), last_activity = NOW()
             WHERE id = ?"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('si', $sid, $user_id);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    } catch (Exception $e) {
        error_log('lgu_claim_user_session: ' . $e->getMessage());
        return false;
    }
}

/**
 * Clear the account session lock. If $only_session_id is provided, only clears
 * when it still matches (so a newer session is not wiped by an old logout).
 */
function lgu_release_user_session($user_id, $only_session_id = null) {
    global $conn;
    $user_id = (int)$user_id;
    if ($user_id <= 0 || !$conn) {
        return false;
    }
    try {
        if ($only_session_id !== null && $only_session_id !== '') {
            $stmt = $conn->prepare(
                "UPDATE users SET active_session_id = NULL WHERE id = ? AND active_session_id = ?"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('is', $user_id, $only_session_id);
        } else {
            $stmt = $conn->prepare(
                "UPDATE users SET active_session_id = NULL WHERE id = ?"
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('i', $user_id);
        }
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    } catch (Exception $e) {
        error_log('lgu_release_user_session: ' . $e->getMessage());
        return false;
    }
}

/** Release the current request's account lock (if any), then destroy the PHP session. */
function lgu_logout_current_session() {
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $sid = (session_status() === PHP_SESSION_ACTIVE) ? session_id() : '';
    if ($user_id > 0) {
        lgu_release_user_session($user_id, $sid !== '' ? $sid : null);
    }
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}

/**
 * If the PHP session has been idle longer than $timeout_seconds, release the
 * single-session lock and redirect to login. Otherwise refresh last_activity.
 */
function lgu_enforce_idle_timeout($timeout_seconds, $redirect_url) {
    $timeout_seconds = (int)$timeout_seconds;
    if ($timeout_seconds > 0
        && isset($_SESSION['last_activity'])
        && (time() - (int)$_SESSION['last_activity'] > $timeout_seconds)
    ) {
        lgu_logout_current_session();
        header('Location: ' . $redirect_url);
        exit;
    }
    $_SESSION['last_activity'] = time();
}

function get_user_role($user_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc()['role'] ?? null;
}

function has_permission($user_role, $required_role) {
    $role_hierarchy = [
        'system_admin' => 4,
        'lgu_staff' => 3,
        'supervisor' => 2,
        'citizen' => 1
    ];
    
    return ($role_hierarchy[$user_role] ?? 0) >= ($role_hierarchy[$required_role] ?? 0);
}

// The Road & Transportation unit roles are LGU staff of that unit, so they
// are granted the same staff-level access as 'lgu_staff'.
function is_staff_role($role) {
    return in_array($role, [
        'lgu_staff',
        'road_ops_supervisor',
        'trans_ops_supervisor',
        'road_monitoring_officer',
        'trans_monitoring_officer',
    ], true);
}

// True for system_admin and every LGU staff-level role.
function is_admin_or_staff_role($role) {
    return $role === 'system_admin' || is_staff_role($role);
}

// Database helper functions
function execute_query($query, $params = [], $types = '') {
    global $conn;
    $stmt = $conn->prepare($query);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    return $stmt;
}

function fetch_all($query, $params = [], $types = '') {
    $result = execute_query($query, $params, $types);
    return $result->get_result()->fetch_all(MYSQLI_ASSOC);
}

function fetch_one($query, $params = [], $types = '') {
    $result = execute_query($query, $params, $types);
    return $result->get_result()->fetch_assoc();
}

// Audit trail functions
function log_audit_action($user_id, $action, $details = '') {
    global $conn;
    
    try {
        // Ensure audit_logs table exists
        $conn->query("CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        $stmt->bind_param("issss", $user_id, $action, $details, $ip, $user_agent);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        // Audit logging should never block the main operation
        error_log("Audit log failed: " . $e->getMessage());
    }
}

// File upload functions
function handle_file_upload($file, $upload_dir, $allowed_types = null) {
    if ($allowed_types === null) {
        $allowed_types = ALLOWED_FILE_TYPES;
    }
    
    // Check if file was uploaded
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        error_log("Upload rejected: tmp_name missing or not an uploaded file");
        return ['success' => false, 'error' => 'No file uploaded or upload error'];
    }
    
    // Check file size
    if (isset($file['error']) && $file['error'] === UPLOAD_ERR_INI_SIZE) {
        error_log("Upload rejected: file exceeds upload_max_filesize (PHP INI limit)");
        return ['success' => false, 'error' => 'File size exceeds the server limit'];
    }
    if ($file['size'] > MAX_FILE_SIZE) {
        error_log("Upload rejected: file too large ({$file['size']} bytes, limit " . MAX_FILE_SIZE . ")");
        return ['success' => false, 'error' => 'File size exceeds maximum limit'];
    }
    
    // Extension whitelist check
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file_ext, $allowed_types)) {
        error_log("Upload rejected: disallowed extension '{$file_ext}' for file '{$file['name']}'");
        return ['success' => false, 'error' => 'File type not allowed'];
    }
    
    // Strict content validation for images: verify the real MIME type with
    // getimagesize() instead of trusting the client-provided type/extension,
    // preventing polyglot/arbitrary-file uploads disguised as images.
    $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (in_array($file_ext, $image_exts)) {
        $img_info = @getimagesize($file['tmp_name']);
        if ($img_info === false) {
            error_log("Upload rejected: '{$file['name']}' is not a valid image (getimagesize failed)");
            return ['success' => false, 'error' => 'Invalid image file'];
        }
        $mime_to_ext = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        $detected_ext = $mime_to_ext[$img_info['mime']] ?? null;
        if ($detected_ext === null) {
            error_log("Upload rejected: '{$file['name']}' reports unhandled image MIME '{$img_info['mime']}'");
            return ['success' => false, 'error' => 'Invalid image type'];
        }
        // Re-anchor the stored extension to what the file actually contains so
        // the final filename always matches the true image format.
        $file_ext = $detected_ext;
    }
    
    // Generate a unique, sanitized filename. The name is fully server-generated
    // (never derived from the client filename) to avoid path traversal / name
    // collisions; only the whitelisted extension comes from the upload.
    $filename = uniqid('', true) . bin2hex(random_bytes(4)) . '.' . $file_ext;
    $filepath = $upload_dir . '/' . $filename;
    
    // Create upload directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0777, true)) {
            error_log("Upload failed: could not create upload directory {$upload_dir}");
            return ['success' => false, 'error' => 'Upload directory is not writable'];
        }
        // Try to set permissions
        chmod($upload_dir, 0777);
    }
    
    // Check if directory is writable
    if (!is_writable($upload_dir)) {
        error_log("Upload failed: upload directory is not writable ({$upload_dir})");
        return ['success' => false, 'error' => 'Upload directory is not writable'];
    }
    
    // Move file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        // Set file permissions
        chmod($filepath, 0644);
        return ['success' => true, 'filename' => $filename, 'filepath' => $filepath];
    } else {
        error_log("Upload failed: move_uploaded_file could not write to {$filepath}");
        return ['success' => false, 'error' => 'Failed to save the uploaded file. Please try again.'];
    }
}

// Date/time functions
function format_date($date, $format = 'M d, Y') {
    return date($format, strtotime($date));
}

function format_datetime($datetime, $format = 'M d, Y h:i A') {
    return date($format, strtotime($datetime));
}

function time_ago($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . ' minutes ago';
    } elseif ($diff < 86400) {
        return floor($diff / 3600) . ' hours ago';
    } elseif ($diff < 604800) {
        return floor($diff / 86400) . ' days ago';
    } else {
        return format_date($datetime);
    }
}

// Pagination functions
function get_pagination_links($current_page, $total_pages, $base_url) {
    $links = [];
    
    // Previous
    if ($current_page > 1) {
        $links[] = '<a href="' . $base_url . '?page=' . ($current_page - 1) . '" class="pagination-link">&laquo; Previous</a>';
    }
    
    // Page numbers
    $start = max(1, $current_page - 2);
    $end = min($total_pages, $current_page + 2);
    
    for ($i = $start; $i <= $end; $i++) {
        $class = ($i == $current_page) ? 'pagination-link active' : 'pagination-link';
        $links[] = '<a href="' . $base_url . '?page=' . $i . '" class="' . $class . '">' . $i . '</a>';
    }
    
    // Next
    if ($current_page < $total_pages) {
        $links[] = '<a href="' . $base_url . '?page=' . ($current_page + 1) . '" class="pagination-link">Next &raquo;</a>';
    }
    
    return implode(' ', $links);
}

// Validation functions
function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validate_phone($phone) {
    // Philippine phone number format
    return preg_match('/^(09|\+639)\d{9}$/', $phone);
}

function validate_required($fields) {
    $errors = [];
    foreach ($fields as $field => $value) {
        if (empty($value) || trim($value) === '') {
            $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
        }
    }
    return $errors;
}

// Returns an array of unmet password strength requirements (empty when valid).
// Rules: min 8 chars, uppercase, lowercase, number, special char, no spaces.
function validate_password_strength($password) {
    $errors = [];

    if (strlen($password) < 8) {
        $errors[] = 'at least 8 characters';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'at least one uppercase letter';
    }

    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'at least one lowercase letter';
    }

    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'at least one number';
    }

    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'at least one special character';
    }

    if (preg_match('/\s/', $password)) {
        $errors[] = 'no spaces';
    }

    return $errors;
}

// Notification functions
function set_flash_message($type, $message) {
    $_SESSION['flash_message'] = [
        'type' => $type,
        'message' => $message
    ];
}

function get_flash_message() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $message;
    }
    return null;
}

// Export functions
function export_to_csv($data, $filename, $headers = []) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Add headers if provided
    if (!empty($headers)) {
        fputcsv($output, $headers);
    }
    
    // Add data
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit();
}

// Utility functions
function redirect($url, $status_code = 302) {
    header("Location: $url", true, $status_code);
    exit();
}

function get_current_url() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    return $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
}

function generate_unique_id($prefix = '') {
    return $prefix . uniqid() . '_' . bin2hex(random_bytes(4));
}

// API response functions
function json_response($data, $status_code = 200) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    http_response_code($status_code);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = json_encode(['success' => false, 'error' => 'JSON encoding failed: ' . json_last_error_msg()]);
    }
    echo $json;
    exit();
}

function json_error($message, $status_code = 400) {
    json_response([
        'success' => false,
        'error' => $message
    ], $status_code);
}

function json_success($data = null, $message = 'Success') {
    json_response([
        'success' => true,
        'message' => $message,
        'data' => $data
    ]);
}

// OTP handling functions
function generate_otp($length = 6) {
    return str_pad(rand(0, (int)str_repeat('9', $length)), $length, '0', STR_PAD_LEFT);
}

function store_otp($email, $otpCode, $purpose = 'registration') {
    $_SESSION['otp_data'] = [
        'code' => $otpCode,
        'expiry' => time() + 300,
        'purpose' => $purpose,
        'email' => $email,
        'used' => false
    ];

    $_SESSION['debug_otp'] = [
        'email' => $email,
        'code' => $otpCode,
        'timestamp' => time()
    ];
}

function otp_code_fingerprint($email, $purpose, $otpCode) {
    return hash('sha256', strtolower(trim((string)$email)) . '|' . (string)$purpose . '|' . (string)$otpCode);
}

function mark_otp_used($email, $purpose, $otpCode) {
    if (!isset($_SESSION['otp_used_codes']) || !is_array($_SESSION['otp_used_codes'])) {
        $_SESSION['otp_used_codes'] = [];
    }
    // Drop fingerprints older than 1 hour
    $cutoff = time() - 3600;
    foreach ($_SESSION['otp_used_codes'] as $fp => $usedAt) {
        if ((int)$usedAt < $cutoff) {
            unset($_SESSION['otp_used_codes'][$fp]);
        }
    }
    $_SESSION['otp_used_codes'][otp_code_fingerprint($email, $purpose, $otpCode)] = time();
}

function is_otp_already_used($email, $purpose, $otpCode) {
    $fp = otp_code_fingerprint($email, $purpose, $otpCode);
    return !empty($_SESSION['otp_used_codes'][$fp]);
}

function verify_otp_code($enteredOTP, $purpose = null) {
    $storedOTP = $_SESSION['otp_data']['code'] ?? '';
    $otpExpiry = $_SESSION['otp_data']['expiry'] ?? 0;
    $otpPurpose = $_SESSION['otp_data']['purpose'] ?? '';
    $otpEmail = $_SESSION['otp_data']['email'] ?? '';
    $otpUsed = !empty($_SESSION['otp_data']['used']);

    if (empty($enteredOTP)) {
        return ['success' => false, 'message' => 'Please enter the OTP code'];
    }

    if (empty($storedOTP) || empty($_SESSION['otp_data'])) {
        return ['success' => false, 'message' => 'No active verification code. Please request a new one.'];
    }

    if ($otpUsed || is_otp_already_used($otpEmail, $otpPurpose, $enteredOTP)) {
        unset($_SESSION['otp_data']);
        return ['success' => false, 'message' => 'This verification code has already been used. Please request a new one.'];
    }

    if (time() > $otpExpiry) {
        unset($_SESSION['otp_data']);
        return ['success' => false, 'message' => 'OTP has expired. Please try again.'];
    }

    if ($purpose !== null && $otpPurpose !== $purpose) {
        return ['success' => false, 'message' => 'Invalid OTP session.'];
    }

    if (!hash_equals((string)$storedOTP, (string)$enteredOTP)) {
        return ['success' => false, 'message' => 'Invalid OTP code. Please try again.'];
    }

    // Mark used before clearing so the same code cannot be replayed
    $_SESSION['otp_data']['used'] = true;
    mark_otp_used($otpEmail, $otpPurpose, $storedOTP);
    unset($_SESSION['otp_data']);
    return ['success' => true, 'message' => 'OTP verified successfully!'];
}

function send_otp_to_email($email, $otpCode, $purpose = null) {
    $apiKey = env_get('BREVO_API_KEY');
    $senderName = env_get('BREVO_SENDER_NAME');
    $senderEmail = env_get('BREVO_SENDER_EMAIL');

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'accept: application/json',
        'api-key: ' . $apiKey,
        'content-type: application/json'
    ]);

    if ($purpose === 'create_admin_account') {
        $intro = 'You are creating a new <strong>Admin</strong> account in the LGU Road Monitoring system. Use the verification code below to confirm this action.';
        $subject = 'Admin account creation verification code';
    } else {
        $intro = 'You requested to sign in or register on the LGU Portal. Use the verification code below to complete your process.';
        $subject = 'Hello from Road and Transportation Department!';
    }

    $htmlContent = "
    <html>
        <body style='font-family: Arial, sans-serif; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 8px;'>
                <h2 style='color: #0066cc;'>Hello from Road and Transportation Department!</h2>
                <p>" . $intro . "</p>
                <div style='background-color: #f4f4f4; padding: 15px; text-align: center; border-radius: 5px; margin: 20px 0;'>
                    <span style='font-size: 24px; font-weight: bold; letter-spacing: 5px;'>" . htmlspecialchars((string)$otpCode, ENT_QUOTES, 'UTF-8') . "</span>
                </div>
                <p>This code will expire in <strong>5 minutes</strong>.</p>
                <p style='font-size: 12px; color: #999; margin-top: 30px;'>If you did not request this email, please ignore it.</p>
            </div>
        </body>
    </html>";

    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'sender' => [
            'name' => $senderName,
            'email' => $senderEmail
        ],
        'to' => [
            [
                'email' => $email,
                'name' => $email
            ]
        ],
        'subject' => $subject,
        'htmlContent' => $htmlContent
    ]));

    $response = curl_exec($ch);
    curl_close($ch);

    error_log("OTP API Response: " . $response);

    return json_decode($response, true);
}

function handle_registration_otp($email) {
    $otpCode = generate_otp();
    store_otp($email, $otpCode, 'registration');
    send_otp_to_email($email, $otpCode, 'registration');
    return $otpCode;
}

function handle_login_otp($email) {
    $otpCode = generate_otp();
    store_otp($email, $otpCode, 'login');
    send_otp_to_email($email, $otpCode, 'login');
    return $otpCode;
}

function handle_password_reset_otp($email) {
    $otpCode = generate_otp();
    store_otp($email, $otpCode, 'password_reset');
    send_otp_to_email($email, $otpCode, 'password_reset');
    return $otpCode;
}

function handle_admin_create_otp($email) {
    $otpCode = generate_otp();
    store_otp($email, $otpCode, 'create_admin_account');
    send_otp_to_email($email, $otpCode, 'create_admin_account');
    return $otpCode;
}

// Generates a secure random temporary password with at least one uppercase,
// lowercase, digit, and special character. Minimum length is 12 characters.
function generate_secure_temporary_password($length = 12) {
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnopqrstuvwxyz';
    $digits = '23456789';
    $special = '!@#$%^&*()-_=+[]{};:,.?';

    if ($length < 12) {
        $length = 12;
    }

    $pool = str_split($upper . $lower . $digits . $special);
    $poolSize = count($pool);

    $password = '';
    $password .= $upper[random_int(0, strlen($upper) - 1)];
    $password .= $lower[random_int(0, strlen($lower) - 1)];
    $password .= $digits[random_int(0, strlen($digits) - 1)];
    $password .= $special[random_int(0, strlen($special) - 1)];

    for ($i = 4; $i < $length; $i++) {
        $password .= $pool[random_int(0, $poolSize - 1)];
    }

    return str_shuffle($password);
}

// Sends the "staff account created" email with the temporary password and login link.
function send_staff_account_email($toEmail, $firstName, $temporaryPassword, $loginUrl) {
    $apiKey = env_get('BREVO_API_KEY');
    $senderName = env_get('BREVO_SENDER_NAME');
    $senderEmail = env_get('BREVO_SENDER_EMAIL');

    $firstNameSafe = htmlspecialchars($firstName);
    $emailSafe = htmlspecialchars($toEmail);
    $passwordSafe = htmlspecialchars($temporaryPassword);
    $loginUrlSafe = htmlspecialchars($loginUrl);

    $htmlContent = "
    <html>
        <body style='font-family: Arial, sans-serif; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 8px;'>
                <h2 style='color: #0066cc;'>Hello {$firstNameSafe},</h2>
                <p>Your staff account has been successfully created by the system administrator.</p>
                <p>You may now log in using the following credentials.</p>
                <table style='background-color: #f4f4f4; padding: 15px; text-align: left; border-radius: 5px; margin: 20px 0; width: 100%;'>
                    <tr><td style='padding: 4px 8px;'><strong>Email:</strong></td><td style='padding: 4px 8px;'>{$emailSafe}</td></tr>
                    <tr><td style='padding: 4px 8px;'><strong>Temporary Password:</strong></td><td style='padding: 4px 8px;'><span style='font-family: monospace; font-weight: bold;'>{$passwordSafe}</span></td></tr>
                    <tr><td style='padding: 4px 8px;'><strong>Login Link:</strong></td><td style='padding: 4px 8px;'><a href='{$loginUrlSafe}'>{$loginUrlSafe}</a></td></tr>
                </table>
                <p>For security reasons, you will be required to change your temporary password immediately after your first login before you can access the dashboard.</p>
                <p style='font-size: 12px; color: #999; margin-top: 30px;'>If you did not expect this email, please contact your system administrator.</p>
                <p style='font-size: 12px; color: #999;'>Regards,<br>LGU Monitoring System</p>
            </div>
        </body>
    </html>";

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'accept: application/json',
        'api-key: ' . $apiKey,
        'content-type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'sender' => [
            'name' => $senderName,
            'email' => $senderEmail
        ],
        'to' => [
            [
                'email' => $toEmail,
                'name' => $firstName
            ]
        ],
        'subject' => 'Your LGU Staff Account Has Been Created',
        'htmlContent' => $htmlContent
    ]));

    $response = curl_exec($ch);
    curl_close($ch);

    error_log("Staff account email response: " . $response);

    return json_decode($response, true);
}

// Create (or rotate) the access tokens for an email. The login token never
// expires (only deactivated via login_token_active); the register token expires
// after 1 day and is marked used once consumed.
//
// When $withRegister is false a login-only token is produced: a register token
// is still stored (the column is NOT NULL) but is immediately marked as used,
// so the login page never offers registration for that email. No DB schema
// changes are required.
function create_user_login_tokens($email, $withRegister = true) {
    global $conn;

    $loginToken = bin2hex(random_bytes(32));   // 64 hex chars
    $registerToken = bin2hex(random_bytes(32)); // 64 hex chars

    if ($withRegister) {
        $sql = "INSERT INTO user_tokens (email, login_token, login_token_active, register_token, register_token_expires_at, register_token_used_at)
                VALUES (?, ?, 1, ?, DATE_ADD(NOW(), INTERVAL 1 DAY), NULL)
                ON DUPLICATE KEY UPDATE
                    login_token = VALUES(login_token),
                    login_token_active = 1,
                    register_token = VALUES(register_token),
                    register_token_expires_at = VALUES(register_token_expires_at),
                    register_token_used_at = NULL";
    } else {
        $sql = "INSERT INTO user_tokens (email, login_token, login_token_active, register_token, register_token_expires_at, register_token_used_at)
                VALUES (?, ?, 1, ?, DATE_ADD(NOW(), INTERVAL 1 DAY), NOW())
                ON DUPLICATE KEY UPDATE
                    login_token = VALUES(login_token),
                    login_token_active = 1,
                    register_token = VALUES(register_token),
                    register_token_expires_at = VALUES(register_token_expires_at),
                    register_token_used_at = NOW()";
    }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $email, $loginToken, $registerToken);
    if (!$stmt->execute()) {
        error_log("create_user_login_tokens failed: " . $stmt->error);
        $stmt->close();
        return false;
    }
    $stmt->close();

    return [
        'email' => $email,
        'login_token' => $loginToken,
        'register_token' => $registerToken,
    ];
}

// Return the set of report keys (report_type:report_id) with an active
// assignment to the given user. Used so Road Monitoring Officers only see the
// reports assigned to them. Matches the report_type / report_id naming used by
// annotate_report_assignment_status() below ('_source_table' + 'id').
function get_assigned_report_keys($conn, $user_id) {
    $keys = [];
    if (!$conn || !$user_id) return $keys;
    try {
        $stmt = $conn->prepare(
            "SELECT report_type, report_id FROM report_assignments
             WHERE user_id = ? AND status = 'active'"
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $keys[$row['report_type'] . ':' . $row['report_id']] = true;
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("get_assigned_report_keys error: " . $e->getMessage());
    }
    return $keys;
}

// Keep only report rows actively assigned to $user_id. Rows must carry their
// source table in '_source_table' and their primary key in 'id' (same contract
// as annotate_report_assignment_status()). Pass $user_id = 0/null to disable.
function filter_reports_assigned_to_user($conn, array $reports, $user_id) {
    if (!$user_id) return $reports;
    $assigned = get_assigned_report_keys($conn, $user_id);
    if (empty($assigned)) return [];
    $filtered = array_filter($reports, function ($r) use ($assigned) {
        $table = $r['_source_table'] ?? 'road_transportation_reports';
        $id = $r['id'] ?? 0;
        return isset($assigned[$table . ':' . $id]);
    });
    return array_values($filtered);
}

// Keys of reports that currently have any active assignment (any officer).
function get_active_assignment_keys($conn) {
    $keys = [];
    if (!$conn) {
        return $keys;
    }
    try {
        $chk = $conn->query("SHOW TABLES LIKE 'report_assignments'");
        if (!$chk || $chk->num_rows === 0) {
            return $keys;
        }
        $res = $conn->query("SELECT report_type, report_id FROM report_assignments WHERE status = 'active'");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $keys[(string)$row['report_type'] . ':' . (int)$row['report_id']] = true;
            }
        }
    } catch (Exception $e) {
        error_log('get_active_assignment_keys error: ' . $e->getMessage());
    }
    return $keys;
}

// Keep only reports that have an active assigned monitoring officer.
// Same '_ as filter_reports_assigned_to_user() ('_source_table' + 'id').
function filter_reports_with_active_assignment($conn, array $reports) {
    if (empty($reports)) {
        return $reports;
    }
    $assigned = get_active_assignment_keys($conn);
    if (empty($assigned)) {
        return [];
    }
    $filtered = array_filter($reports, function ($r) use ($assigned) {
        $table = $r['_source_table'] ?? 'road_transportation_reports';
        $id = $r['id'] ?? 0;
        return isset($assigned[$table . ':' . $id]);
    });
    return array_values($filtered);
}

/**
 * Report keys (report_type:id) owned by $user_id as the first assigner (assigned_by).
 * Ownership is permanent across active/cancelled/completed assignment rows.
 *
 * @return array<string,true>
 */
function rgmap_get_owned_report_keys($conn, $user_id) {
    $user_id = (int)$user_id;
    $keys = [];
    if (!$conn || $user_id <= 0) {
        return $keys;
    }
    try {
        $chk = $conn->query("SHOW TABLES LIKE 'report_assignments'");
        if (!$chk || $chk->num_rows === 0) {
            return $keys;
        }
        $res = $conn->query(
            "SELECT report_type, report_id, assigned_by
             FROM report_assignments
             WHERE assigned_by IS NOT NULL AND assigned_by > 0
             ORDER BY assigned_at ASC, id ASC"
        );
        if (!$res) {
            return $keys;
        }
        $seen = [];
        while ($row = $res->fetch_assoc()) {
            $k = (string)$row['report_type'] . ':' . (int)$row['report_id'];
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            if ((int)$row['assigned_by'] === $user_id) {
                $keys[$k] = true;
            }
        }
    } catch (Exception $e) {
        error_log('rgmap_get_owned_report_keys error: ' . $e->getMessage());
    }
    return $keys;
}

/** Resolve _source_table for a list row when missing (after annotate / API). */
function rgmap_report_row_source_table(array $r): string {
    if (!empty($r['_source_table'])) {
        return (string)$r['_source_table'];
    }
    $src = strtolower(trim((string)($r['source'] ?? ($r['source_system'] ?? ''))));
    if ($src === 'cimm' || $src === 'external') {
        return 'cimm_verification_reports';
    }
    if (in_array($src, ['infrastructure', 'maintenance', 'ipms'], true)) {
        return 'ipms_road_projects';
    }
    return 'road_transportation_reports';
}

/**
 * Keep only reports the current user is handling:
 * - Supervisors / system_admin: first assigned_by = them
 * - Monitoring officers: actively assigned to them
 *
 * @return array
 */
function rgmap_filter_reports_you_handle($conn, array $reports, $user_id = null, $role = null) {
    $user_id = (int)($user_id ?? ($_SESSION['user_id'] ?? 0));
    $role = (string)($role ?? ($_SESSION['role'] ?? ''));
    if ($user_id <= 0 || empty($reports)) {
        return [];
    }

    if (in_array($role, ['road_monitoring_officer', 'trans_monitoring_officer'], true)) {
        // Ensure _source_table for the officer filter helper.
        foreach ($reports as &$rr) {
            if (empty($rr['_source_table'])) {
                $rr['_source_table'] = rgmap_report_row_source_table($rr);
            }
        }
        unset($rr);
        return filter_reports_assigned_to_user($conn, $reports, $user_id);
    }

    $keys = rgmap_get_owned_report_keys($conn, $user_id);
    if (empty($keys)) {
        return [];
    }
    $filtered = array_filter($reports, static function ($r) use ($keys) {
        $table = rgmap_report_row_source_table($r);
        return isset($keys[$table . ':' . (int)($r['id'] ?? 0)]);
    });
    return array_values($filtered);
}

/**
 * SQL fragment (no leading AND) limiting archive_rows to reports the user handles.
 * Empty string when the filter should not apply.
 */
function rgmap_your_reports_archive_sql($user_id, $role) {
    $user_id = (int)$user_id;
    $role = (string)$role;
    if ($user_id <= 0) {
        return '';
    }

    $report_id_expr = 'COALESCE(NULLIF(archive_rows.source_pk, 0), archive_rows.id)';
    $report_type_expr = "CASE
        WHEN archive_rows.archive_table = 'cimm_verification_reports_archive'
          OR archive_rows.archived_from = 'cimm_verification_reports'
          THEN 'cimm_verification_reports'
        WHEN archive_rows.archive_table = 'ipms_road_projects_archive'
          OR archive_rows.archived_from = 'ipms_road_projects'
          THEN 'ipms_road_projects'
        WHEN archive_rows.archived_from = 'road_maintenance_reports'
          THEN 'road_maintenance_reports'
        ELSE 'road_transportation_reports'
      END";

    if (in_array($role, ['road_monitoring_officer', 'trans_monitoring_officer'], true)) {
        return "EXISTS (
            SELECT 1 FROM report_assignments ra
            WHERE ra.user_id = {$user_id}
              AND ra.status = 'active'
              AND ra.report_id = {$report_id_expr}
              AND ra.report_type = ({$report_type_expr})
        )";
    }

    if (!in_array($role, ['road_ops_supervisor', 'trans_ops_supervisor', 'system_admin'], true)) {
        return '';
    }

    // First chronological assigner must be this user (ownership).
    return "EXISTS (
        SELECT 1 FROM report_assignments ra
        WHERE ra.assigned_by = {$user_id}
          AND ra.report_id = {$report_id_expr}
          AND ra.report_type = ({$report_type_expr})
          AND NOT EXISTS (
              SELECT 1 FROM report_assignments earlier
              WHERE earlier.report_id = ra.report_id
                AND earlier.report_type = ra.report_type
                AND earlier.assigned_by IS NOT NULL
                AND earlier.assigned_by > 0
                AND (
                    earlier.assigned_at < ra.assigned_at
                    OR (earlier.assigned_at = ra.assigned_at AND earlier.id < ra.id)
                )
          )
    )";
}

// Annotate a list of report rows with a display-only "Assignment Status".
// The report_assignments table is the single source of truth updated by the
// Assign/Unassign Staff features, so this reflects assignments live on every
// page load. Each report row must carry its source table in '_source_table'
// (set by the caller's SELECT) and its primary key in 'id'. Sets
// 'assignment_status' to 'assigned' or 'unassigned' and removes the helper
// key. Purely informational — it never affects the report workflow or the
// existing report statuses.
function annotate_report_assignment_status($conn, array &$reports) {
    if (empty($reports)) {
        return;
    }
    $assigned = [];
    $owners = [];
    try {
        $res = $conn->query(
            "SELECT ra.report_id, ra.report_type, ra.assigned_by, ra.assigned_at, ra.status,
                    u.full_name AS officer_name, ab.full_name AS assigner_name
             FROM report_assignments ra
             LEFT JOIN users u ON u.id = ra.user_id
             LEFT JOIN users ab ON ab.id = ra.assigned_by
             ORDER BY ra.assigned_at ASC, ra.id ASC"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $key = $row['report_type'] . ':' . $row['report_id'];
                // First assignment chronologically claims ownership permanently.
                if (!isset($owners[$key]) && !empty($row['assigned_by'])) {
                    $owners[$key] = [
                        'id' => (int)$row['assigned_by'],
                        'name' => (string)($row['assigner_name'] ?? ''),
                    ];
                }
                if (($row['status'] ?? '') === 'active') {
                    $assigned[$key] = [
                        'officer' => $row['officer_name'] ?? '',
                        'assigner' => $row['assigner_name'] ?? '',
                        'assigner_id' => (int)($row['assigned_by'] ?? 0),
                    ];
                }
            }
        }
    } catch (Exception $e) {
        error_log("annotate_report_assignment_status error: " . $e->getMessage());
    }

    $uid = (int)($_SESSION['user_id'] ?? 0);
    $role = (string)($_SESSION['role'] ?? '');
    $is_supervisor = in_array($role, ['road_ops_supervisor', 'trans_ops_supervisor'], true);

    foreach ($reports as &$rr) {
        $table = $rr['_source_table'] ?? 'road_transportation_reports';
        $key = $table . ':' . ($rr['id'] ?? 0);
        $rr['assignment_status'] = isset($assigned[$key]) ? 'assigned' : 'unassigned';
        $rr['assignment_officer'] = $assigned[$key]['officer'] ?? '';
        // Prefer live active assigner name; fall back to first-claim owner name.
        $rr['assigned_by'] = $assigned[$key]['assigner']
            ?? ($owners[$key]['name'] ?? '');
        $owner_id = (int)($owners[$key]['id'] ?? ($assigned[$key]['assigner_id'] ?? 0));
        $rr['assigned_by_id'] = $owner_id;
        if ($role === 'system_admin' || !$is_supervisor) {
            $rr['can_manage_as_supervisor'] = true;
        } elseif ($owner_id <= 0) {
            $rr['can_manage_as_supervisor'] = true; // unclaimed — any matching-role supervisor may claim
        } else {
            $rr['can_manage_as_supervisor'] = ($owner_id === $uid);
        }
        // Keep _source_table for callers that still need the live table name
        // (pagination APIs, archive helpers). Ownership fields are already set.
    }
    unset($rr);
}

/**
 * Resolve the supervisor who first claimed/assigned a report.
 * Ownership is permanent (earliest assignment by assigned_at), including
 * cancelled/completed assignment rows so Archive still knows the owner.
 *
 * @return array{id:int,name:string,email:string,role:string}|null
 */
function rgmap_get_report_owner_supervisor($conn, $report_id, $report_type = null) {
    $report_id = (int)$report_id;
    if (!$conn || $report_id <= 0) {
        return null;
    }

    $types = [];
    $rt = trim((string)$report_type);
    if ($rt !== '') {
        $types[] = $rt;
    }
    foreach (['road_transportation_reports', 'cimm_verification_reports', 'road_maintenance_reports', 'ipms_road_projects'] as $t) {
        if (!in_array($t, $types, true)) {
            $types[] = $t;
        }
    }

    try {
        $check = $conn->query("SHOW TABLES LIKE 'report_assignments'");
        if (!$check || $check->num_rows === 0) {
            return null;
        }

        $best = null;
        foreach ($types as $rtype) {
            $row = fetch_one(
                "SELECT ra.assigned_by, ra.assigned_at, ra.id,
                        u.full_name, u.email, u.role
                   FROM report_assignments ra
                   INNER JOIN users u ON u.id = ra.assigned_by
                  WHERE ra.report_id = ? AND ra.report_type = ?
                  ORDER BY ra.assigned_at ASC, ra.id ASC
                  LIMIT 1",
                [$report_id, $rtype],
                "is"
            );
            if (!$row || empty($row['assigned_by'])) {
                continue;
            }
            $candidate = [
                'id' => (int)$row['assigned_by'],
                'name' => (string)($row['full_name'] ?? ''),
                'email' => (string)($row['email'] ?? ''),
                'role' => (string)($row['role'] ?? ''),
                '_at' => (string)($row['assigned_at'] ?? ''),
                '_id' => (int)($row['id'] ?? 0),
            ];
            if ($best === null
                || strcmp($candidate['_at'], $best['_at']) < 0
                || ($candidate['_at'] === $best['_at'] && $candidate['_id'] < $best['_id'])) {
                $best = $candidate;
            }
            // Exact type match preferred when caller passed one — still compare
            // timestamps across types only when type was unknown.
            if ($rt !== '' && $rtype === $rt) {
                break;
            }
        }
        if ($best) {
            unset($best['_at'], $best['_id']);
            return $best;
        }
    } catch (Exception $e) {
        error_log('rgmap_get_report_owner_supervisor error: ' . $e->getMessage());
    }
    return null;
}

/**
 * Map a UI/API source hint to report_assignments.report_type.
 */
function rgmap_assignment_type_from_source($source) {
    if (function_exists('rgmap_assignment_report_type_from_source')) {
        return rgmap_assignment_report_type_from_source($source);
    }
    $source = strtolower(trim((string)$source));
    if ($source === 'cimm' || $source === 'external') {
        return 'cimm_verification_reports';
    }
    if ($source === 'maintenance' || $source === 'road_maintenance') {
        return 'road_maintenance_reports';
    }
    if (strpos($source, 'ipms') !== false || $source === 'infrastructure') {
        return 'ipms_road_projects';
    }
    return 'road_transportation_reports';
}

/**
 * Whether the current (or given) user may perform supervisor actions on a report.
 * - system_admin: always
 * - non-supervisors: true (other role gates still apply)
 * - supervisors: true only when unclaimed OR they are the first assigner
 */
function rgmap_supervisor_can_manage_report($conn, $report_id, $report_type = null, $user_id = null, $user_role = null) {
    $user_id = (int)($user_id ?? ($_SESSION['user_id'] ?? 0));
    $user_role = (string)($user_role ?? ($_SESSION['role'] ?? ''));

    if ($user_role === 'system_admin') {
        return true;
    }
    if (!in_array($user_role, ['road_ops_supervisor', 'trans_ops_supervisor'], true)) {
        return true;
    }
    if ($user_id <= 0) {
        return false;
    }

    $owner = rgmap_get_report_owner_supervisor($conn, $report_id, $report_type);
    if (!$owner || empty($owner['id'])) {
        return true; // not yet claimed
    }
    return ((int)$owner['id'] === $user_id);
}

/**
 * Deny API/POST actions when another supervisor owns the report.
 * Returns true if allowed; sends JSON or sets flash and returns false when denied.
 */
function rgmap_require_supervisor_report_ownership($conn, $report_id, $report_type = null, $as_json = true) {
    if (rgmap_supervisor_can_manage_report($conn, $report_id, $report_type)) {
        return true;
    }
    $owner = rgmap_get_report_owner_supervisor($conn, $report_id, $report_type);
    $owner_name = trim((string)($owner['name'] ?? '')) ?: 'another supervisor';
    $msg = "This report is managed by {$owner_name}. Only the supervisor who assigned it can perform actions.";
    if ($as_json) {
        if (function_exists('json_response')) {
            json_response(['success' => false, 'message' => $msg], 403);
        }
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }
    if (function_exists('set_flash_message')) {
        set_flash_message('error', $msg);
    }
    return false;
}

/**
 * Resolve live report id + report_assignments.report_type from an archive row.
 * @return array{0:int,1:?string} [report_id, report_type]
 */
function rgmap_archive_row_assignment_key(array $row): array {
    $archive_table = (string)($row['archive_table'] ?? 'road_transportation_reports_archive');
    $archived_from = (string)($row['archived_from'] ?? '');
    $source_pk = (int)($row['source_pk'] ?? 0);
    $id = (int)($row['id'] ?? 0);

    if ($archive_table === 'cimm_verification_reports_archive' || $archived_from === 'cimm_verification_reports') {
        return [$source_pk > 0 ? $source_pk : $id, 'cimm_verification_reports'];
    }
    if ($archive_table === 'ipms_road_projects_archive' || $archived_from === 'ipms_road_projects') {
        return [$source_pk > 0 ? $source_pk : $id, 'ipms_road_projects'];
    }
    if ($archived_from === 'road_maintenance_reports') {
        return [$source_pk > 0 ? $source_pk : $id, 'road_maintenance_reports'];
    }
    return [$source_pk > 0 ? $source_pk : $id, 'road_transportation_reports'];
}

/**
 * Annotate archive rows with owning supervisor info for UI + permission checks.
 */
function rgmap_annotate_archive_supervisor_ownership($conn, array &$rows): void {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $role = (string)($_SESSION['role'] ?? '');
    $is_supervisor = in_array($role, ['road_ops_supervisor', 'trans_ops_supervisor'], true);

    foreach ($rows as &$row) {
        [$rid, $rtype] = rgmap_archive_row_assignment_key($row);
        $owner = rgmap_get_report_owner_supervisor($conn, $rid, $rtype);
        $row['assigned_by_id'] = (int)($owner['id'] ?? 0);
        $row['assigned_by'] = (string)($owner['name'] ?? '');
        if ($role === 'system_admin' || !$is_supervisor) {
            $row['can_manage_as_supervisor'] = true;
        } elseif ($row['assigned_by_id'] <= 0) {
            $row['can_manage_as_supervisor'] = true;
        } else {
            $row['can_manage_as_supervisor'] = ($row['assigned_by_id'] === $uid);
        }
    }
    unset($row);
}

/**
 * Normalize Public Transparency / Completed Projects source keys so a published
 * row linked as "transport" still matches a Completed Projects row sourced as
 * "citizen" or "lgu" (same underlying road_transportation_reports id).
 *
 * @return string[]
 */
function public_transparency_source_aliases($source) {
    $s = strtolower(trim((string)$source));
    $road = ['lgu', 'citizen', 'transport', 'transportation', 'local', 'road', 'road_transportation'];
    if ($s === '' || in_array($s, $road, true)) {
        return $road;
    }
    if ($s === 'cimm') {
        return ['cimm'];
    }
    if (in_array($s, ['infrastructure', 'maintenance'], true)) {
        return ['infrastructure', 'maintenance'];
    }
    return [$s];
}

/**
 * Fingerprint used to match a Completed Projects row to a published project
 * when source_report_id was not stored (common after manual Progress Updates import).
 */
function public_transparency_publish_fingerprint($title, $location) {
    $t = strtolower(trim(preg_replace('/\s+/u', ' ', (string)$title)));
    $l = strtolower(trim(preg_replace('/\s+/u', ' ', (string)$location)));
    if ($t === '' || $l === '') {
        return '';
    }
    return $t . "\0" . $l;
}

/**
 * True when a published_completed_projects row (is_published=1) is linked to
 * this report id under a compatible source alias.
 */
function public_transparency_is_posted_for_report(array $posted_index, $report_id, $report_source) {
    $id = (int)$report_id;
    if ($id <= 0 || empty($posted_index)) {
        return false;
    }
    foreach (public_transparency_source_aliases($report_source) as $alias) {
        if (!empty($posted_index[$alias . ':' . $id])) {
            return true;
        }
    }
    return false;
}

// Annotate report rows with Transparency Upload Request state and the
// Public column status used on Completed Projects.
// Each row needs 'id' + 'source' ('lgu', 'citizen', or 'cimm').
// Sets:
//   transparency_request_status — raw request status: '', 'pending', 'approved', 'rejected'
//   public_transparency_status  — display: awaiting|pending|approved|posted|rejected
// Publication in published_completed_projects (is_published=1) always wins over
// the request status — e.g. Rejected → later published → Posted.
// Display-only — never changes a report's own completion status.
function annotate_transparency_request_status($conn, array &$reports) {
    if (empty($reports)) {
        return;
    }
    $latest = [];
    $posted = [];
    $posted_by_fingerprint = [];
    try {
        $exists = $conn->query("SHOW TABLES LIKE 'transparency_upload_requests'");
        if ($exists && $exists->num_rows > 0) {
            $res = $conn->query(
                "SELECT t.report_id, t.report_source, t.status
                 FROM transparency_upload_requests t
                 JOIN (SELECT report_id, report_source, MAX(id) AS id
                         FROM transparency_upload_requests
                        GROUP BY report_id, report_source) latest
                   ON latest.id = t.id"
            );
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $key = strtolower((string)($row['report_source'] ?? '')) . ':' . (int)($row['report_id'] ?? 0);
                    $latest[$key] = (string)($row['status'] ?? '');
                }
            }
        }

        // Posted when actually published on the Public Transparency portal.
        // Prefer source_report_id linkage; fall back to title+location for rows
        // imported/published without that link (manual Progress Updates import).
        $pub_exists = $conn->query("SHOW TABLES LIKE 'published_completed_projects'");
        if ($pub_exists && $pub_exists->num_rows > 0) {
            $pub_res = $conn->query(
                "SELECT source_report_id, source_report_source, title, location
                   FROM published_completed_projects
                  WHERE is_published = 1"
            );
            if ($pub_res) {
                while ($row = $pub_res->fetch_assoc()) {
                    $id = (int)($row['source_report_id'] ?? 0);
                    if ($id > 0) {
                        foreach (public_transparency_source_aliases($row['source_report_source'] ?? '') as $alias) {
                            $posted[$alias . ':' . $id] = true;
                        }
                    } else {
                        $fp = public_transparency_publish_fingerprint(
                            $row['title'] ?? '',
                            $row['location'] ?? ''
                        );
                        if ($fp !== '') {
                            $posted_by_fingerprint[$fp] = true;
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log('annotate_transparency_request_status error: ' . $e->getMessage());
    }
    foreach ($reports as &$rr) {
        $id = (int)($rr['id'] ?? 0);
        $src = strtolower((string)($rr['source'] ?? ''));
        $key = $src . ':' . $id;
        $req = $latest[$key] ?? '';
        // Also accept request rows stored under a road-family alias.
        if ($req === '') {
            foreach (public_transparency_source_aliases($src) as $alias) {
                if (isset($latest[$alias . ':' . $id])) {
                    $req = $latest[$alias . ':' . $id];
                    break;
                }
            }
        }
        $rr['transparency_request_status'] = $req;

        $fp = public_transparency_publish_fingerprint($rr['title'] ?? '', $rr['location'] ?? '');
        $is_posted = public_transparency_is_posted_for_report($posted, $id, $src)
            || ($fp !== '' && !empty($posted_by_fingerprint[$fp]));

        // Actual publication state overrides prior request status (including Rejected).
        if ($is_posted) {
            $rr['public_transparency_status'] = 'posted';
        } elseif ($req === 'pending') {
            $rr['public_transparency_status'] = 'pending';
        } elseif ($req === 'approved') {
            $rr['public_transparency_status'] = 'approved';
        } elseif ($req === 'rejected') {
            $rr['public_transparency_status'] = 'rejected';
        } else {
            $rr['public_transparency_status'] = 'awaiting';
        }
    }
    unset($rr);
}

/** Human label for Completed Projects → Public column. */
function public_transparency_status_label($status) {
    $map = [
        'awaiting' => 'Awaiting',
        'pending' => 'Pending',
        'approved' => 'Approved',
        'posted' => 'Posted',
        'rejected' => 'Rejected',
    ];
    $key = strtolower(trim((string)$status));
    return $map[$key] ?? 'Awaiting';
}

/** CSS modifier class for Public column badges. */
function public_transparency_status_class($status) {
    $key = strtolower(trim((string)$status));
    if (!in_array($key, ['awaiting', 'pending', 'approved', 'posted', 'rejected'], true)) {
        $key = 'awaiting';
    }
    return 'pt-status-' . $key;
}

// Display-only: when a report last received a progress update (or never has).
// Sets last_progress_update_at and no_update_stale (true when 10+ days have
// passed since the last update, or since the report was created if none exist).
// Does not write to the database or change report status.
function annotate_last_progress_update($conn, array &$reports) {
    if (empty($reports)) {
        return;
    }
    $latest = [];
    try {
        $exists = $conn->query("SHOW TABLES LIKE 'report_updates'");
        if ($exists && $exists->num_rows > 0) {
            $res = $conn->query(
                "SELECT report_id, MAX(created_at) AS last_at
                   FROM report_updates
               GROUP BY report_id"
            );
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $latest[(int)($row['report_id'] ?? 0)] = (string)($row['last_at'] ?? '');
                }
            }
        }
    } catch (Exception $e) {
        error_log('annotate_last_progress_update error: ' . $e->getMessage());
    }
    $threshold = 10 * 24 * 60 * 60;
    $now = time();
    foreach ($reports as &$rr) {
        $id = (int)($rr['id'] ?? 0);
        $last = $latest[$id] ?? '';
        $rr['last_progress_update_at'] = $last;
        $anchor = $last !== '' ? $last : (string)($rr['created_at'] ?? '');
        $ts = $anchor !== '' ? strtotime($anchor) : false;
        $rr['no_update_stale'] = ($ts !== false) && (($now - $ts) >= $threshold);
    }
    unset($rr);
}

/**
 * When an in-progress/approved report has had no progress update for $days
 * days, notify the System Admin, the supervisor who assigned the officer, and
 * the assigned monitoring officer. Uses the existing report_notifications
 * table (type 'no_update_stale') — no schema change.
 *
 * Duplicate-safe for the current 10-day window: a row already sent to the same
 * email since the last progress update (or report created_at if none) is not
 * sent again. Posting a new progress update moves that window, so the next
 * gap of 10 days can alert once more. Never changes report status.
 */
function dispatch_no_update_stale_notifications($conn, $days = 10) {
    static $ran = false;
    if ($ran || !$conn) {
        return;
    }
    $ran = true;
    $days = max(1, (int)$days);

    try {
        $has_n = $conn->query("SHOW TABLES LIKE 'report_notifications'");
        if (!$has_n || $has_n->num_rows === 0) {
            return;
        }

        $stale = [];
        $res = $conn->query(
            "SELECT t.id,
                    t.report_id AS report_code,
                    t.title,
                    t.location,
                    'road_transportation_reports' AS report_table,
                    COALESCE(u.last_at, t.created_at) AS last_activity
               FROM road_transportation_reports t
          LEFT JOIN (
                    SELECT report_id, MAX(created_at) AS last_at
                      FROM report_updates
                  GROUP BY report_id
                    ) u ON u.report_id = t.id
              WHERE LOWER(t.status) IN ('approved', 'in-progress')
                AND COALESCE(u.last_at, t.created_at) <= DATE_SUB(NOW(), INTERVAL {$days} DAY)"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $stale[] = $row;
            }
        }

        $has_maint = $conn->query("SHOW TABLES LIKE 'road_maintenance_reports'");
        if ($has_maint && $has_maint->num_rows > 0) {
            $mres = $conn->query(
                "SELECT t.id,
                        t.report_id AS report_code,
                        t.title,
                        t.location,
                        'road_maintenance_reports' AS report_table,
                        COALESCE(u.last_at, t.created_at) AS last_activity
                   FROM road_maintenance_reports t
              LEFT JOIN (
                        SELECT report_id, MAX(created_at) AS last_at
                          FROM report_updates
                      GROUP BY report_id
                        ) u ON u.report_id = t.id
                  WHERE LOWER(t.status) IN ('approved', 'in-progress')
                    AND COALESCE(u.last_at, t.created_at) <= DATE_SUB(NOW(), INTERVAL {$days} DAY)"
            );
            if ($mres) {
                while ($row = $mres->fetch_assoc()) {
                    $stale[] = $row;
                }
            }
        }

        $has_cimm = $conn->query("SHOW TABLES LIKE 'cimm_verification_reports'");
        if ($has_cimm && $has_cimm->num_rows > 0) {
            $cres = $conn->query(
                "SELECT t.id,
                        t.reference_code AS report_code,
                        t.infrastructure AS title,
                        t.location,
                        'cimm_verification_reports' AS report_table,
                        COALESCE(u.last_at, COALESCE(t.submitted_at, t.verified_at, t.synced_at, NOW())) AS last_activity
                   FROM cimm_verification_reports t
              LEFT JOIN (
                        SELECT report_id, MAX(created_at) AS last_at
                          FROM report_updates
                      GROUP BY report_id
                        ) u ON u.report_id = t.id
                  WHERE t.verification_status IN ('Approved', 'In Progress')
                    AND COALESCE(u.last_at, COALESCE(t.submitted_at, t.verified_at, t.synced_at, NOW())) <= DATE_SUB(NOW(), INTERVAL {$days} DAY)"
            );
            if ($cres) {
                while ($row = $cres->fetch_assoc()) {
                    $stale[] = $row;
                }
            }
        }

        if (empty($stale)) {
            return;
        }

        $admins = [];
        $ares = $conn->query(
            "SELECT id, email, role FROM users
              WHERE role = 'system_admin'
                AND email IS NOT NULL AND TRIM(email) <> ''"
        );
        if ($ares) {
            while ($row = $ares->fetch_assoc()) {
                $email = trim((string)($row['email'] ?? ''));
                if ($email !== '') {
                    $admins[$email] = (string)($row['role'] ?? 'system_admin');
                }
            }
        }

        $has_asg = $conn->query("SHOW TABLES LIKE 'report_assignments'");

        foreach ($stale as $report) {
            $report_id = (int)($report['id'] ?? 0);
            if ($report_id <= 0) {
                continue;
            }
            $table = (string)($report['report_table'] ?? 'road_transportation_reports');
            $last_activity = (string)($report['last_activity'] ?? '');
            if ($last_activity === '') {
                continue;
            }

            $code = trim((string)($report['report_code'] ?? '')) ?: ('#' . $report_id);
            $title = trim((string)($report['title'] ?? 'Untitled')) ?: 'Untitled';
            $location = trim((string)($report['location'] ?? ''));
            $message = 'No progress update for 10 days — Report: ' . $code
                . ' | Title: ' . $title
                . ($location !== '' ? (' | Location: ' . $location) : '');

            $recipients = $admins;

            if ($has_asg && $has_asg->num_rows > 0) {
                $asg = $conn->prepare(
                    "SELECT ou.email AS officer_email, ou.role AS officer_role,
                            su.email AS supervisor_email, su.role AS supervisor_role
                       FROM report_assignments ra
                  LEFT JOIN users ou ON ou.id = ra.user_id
                  LEFT JOIN users su ON su.id = ra.assigned_by
                      WHERE ra.report_id = ? AND ra.report_type = ? AND ra.status = 'active'"
                );
                $asg->bind_param('is', $report_id, $table);
                $asg->execute();
                $asg_res = $asg->get_result();
                while ($asg_res && ($ar = $asg_res->fetch_assoc())) {
                    $off = trim((string)($ar['officer_email'] ?? ''));
                    if ($off !== '') {
                        $recipients[$off] = (string)($ar['officer_role'] ?? 'road_monitoring_officer');
                    }
                    $sup = trim((string)($ar['supervisor_email'] ?? ''));
                    if ($sup !== '') {
                        $recipients[$sup] = (string)($ar['supervisor_role'] ?? 'road_ops_supervisor');
                    }
                }
                $asg->close();
            }

            foreach ($recipients as $email => $role) {
                $dup = $conn->prepare(
                    "SELECT id FROM report_notifications
                      WHERE report_id = ? AND type = 'no_update_stale'
                        AND recipient_email = ? AND created_at >= ?
                      LIMIT 1"
                );
                $dup->bind_param('iss', $report_id, $email, $last_activity);
                $dup->execute();
                $already = $dup->get_result()->fetch_assoc();
                $dup->close();
                if ($already) {
                    continue;
                }

                $ins = $conn->prepare(
                    "INSERT INTO report_notifications (report_id, type, message, recipient_email, recipient_role, is_read)
                     VALUES (?, 'no_update_stale', ?, ?, ?, 0)"
                );
                $ins->bind_param('isss', $report_id, $message, $email, $role);
                $ins->execute();
                $ins->close();
            }
        }
    } catch (Exception $e) {
        error_log('dispatch_no_update_stale_notifications error: ' . $e->getMessage());
    }
}

// Send an email containing a magic login URL carrying the login token.
function send_login_link_email($toEmail, $loginUrl) {
    $apiKey = env_get('BREVO_API_KEY');
    $senderName = env_get('BREVO_SENDER_NAME');
    $senderEmail = env_get('BREVO_SENDER_EMAIL');

    $emailSafe = htmlspecialchars($toEmail);
    $urlSafe = htmlspecialchars($loginUrl);

    $htmlContent = "
    <html>
        <body style='font-family: Arial, sans-serif; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 8px;'>
                <h2 style='color: #0066cc;'>Access Your LGU Account</h2>
                <p>Use the link below to sign in to your account.</p>
                <p style='text-align: center; margin: 25px 0;'>
                    <a href='{$urlSafe}' style='background-color: #0066cc; color: #fff; padding: 12px 28px; text-decoration: none; border-radius: 6px; display: inline-block;'>Sign In</a>
                </p>
                <p>Or copy this link into your browser:</p>
                <p style='font-family: monospace; font-size: 12px; word-break: break-all; background: #f4f4f4; padding: 10px; border-radius: 5px;'>{$urlSafe}</p>
                <p style='font-size: 12px; color: #999; margin-top: 30px;'>If you did not request this link, you can safely ignore this email.</p>
                <p style='font-size: 12px; color: #999;'>Regards,<br>LGU Monitoring System</p>
            </div>
        </body>
    </html>";

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'accept: application/json',
        'api-key: ' . $apiKey,
        'content-type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'sender' => [
            'name' => $senderName,
            'email' => $senderEmail
        ],
        'to' => [
            [
                'email' => $toEmail,
                'name' => $emailSafe
            ]
        ],
        'subject' => 'Your LGU Account Access Link',
        'htmlContent' => $htmlContent
    ]));

    $response = curl_exec($ch);
    curl_close($ch);

    error_log("Login link email response: " . $response);

    return json_decode($response, true);
}

// Notify a citizen reporter that their completed report appears on the public transparency page.
function send_transparency_published_email($toEmail, $reporterName, $transparencyUrl, $projectTitle) {
    $apiKey = env_get('BREVO_API_KEY');
    $senderName = env_get('BREVO_SENDER_NAME');
    $senderEmail = env_get('BREVO_SENDER_EMAIL');

    if ($apiKey === '' || $senderEmail === '') {
        error_log('Transparency published email: Brevo is not configured (BREVO_API_KEY / BREVO_SENDER_EMAIL).');
        return false;
    }

    $nameSafe = htmlspecialchars(trim($reporterName) !== '' ? $reporterName : 'Citizen Reporter');
    $emailSafe = htmlspecialchars($toEmail);
    $titleSafe = htmlspecialchars(trim($projectTitle) !== '' ? $projectTitle : 'your report');
    $urlSafe = htmlspecialchars($transparencyUrl);

    $htmlContent = "
    <html>
        <body style='font-family: Arial, sans-serif; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 8px;'>
                <h2 style='color: #0066cc;'>Hello {$nameSafe},</h2>
                <p>Thank you for submitting your road report. We are pleased to let you know that work related to <strong>{$titleSafe}</strong> has been completed and is now featured on our public transparency page.</p>
                <p>You can view the before-and-after progress and other completed projects here:</p>
                <p style='text-align: center; margin: 25px 0;'>
                    <a href='{$urlSafe}' style='background-color: #0066cc; color: #fff; padding: 12px 28px; text-decoration: none; border-radius: 6px; display: inline-block;'>View Public Transparency Page</a>
                </p>
                <p>Or copy this link into your browser:</p>
                <p style='font-family: monospace; font-size: 12px; word-break: break-all; background: #f4f4f4; padding: 10px; border-radius: 5px;'>{$urlSafe}</p>
                <p style='font-size: 12px; color: #999; margin-top: 30px;'>Regards,<br>Road and Transportation Department</p>
            </div>
        </body>
    </html>";

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'accept: application/json',
        'api-key: ' . $apiKey,
        'content-type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'sender' => [
            'name' => $senderName,
            'email' => $senderEmail
        ],
        'to' => [
            [
                'email' => $toEmail,
                'name' => $emailSafe
            ]
        ],
        'subject' => 'Your report is now on the Public Transparency page',
        'htmlContent' => $htmlContent
    ]));

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log('Transparency published email response (' . $httpCode . '): ' . $response);

    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    }

    return false;
}
?>
