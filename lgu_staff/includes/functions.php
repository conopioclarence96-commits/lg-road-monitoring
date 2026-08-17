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
        'email' => $email
    ];

    $_SESSION['debug_otp'] = [
        'email' => $email,
        'code' => $otpCode,
        'timestamp' => time()
    ];
}

function verify_otp_code($enteredOTP, $purpose = null) {
    $storedOTP = $_SESSION['otp_data']['code'] ?? '';
    $otpExpiry = $_SESSION['otp_data']['expiry'] ?? 0;
    $otpPurpose = $_SESSION['otp_data']['purpose'] ?? '';

    if (empty($enteredOTP)) {
        return ['success' => false, 'message' => 'Please enter the OTP code'];
    }

    if (time() > $otpExpiry) {
        unset($_SESSION['otp_data']);
        return ['success' => false, 'message' => 'OTP has expired. Please try again.'];
    }

    if ($purpose !== null && $otpPurpose !== $purpose) {
        return ['success' => false, 'message' => 'Invalid OTP session.'];
    }

    if ($enteredOTP !== $storedOTP) {
        return ['success' => false, 'message' => 'Invalid OTP code. Please try again.'];
    }

    unset($_SESSION['otp_data']);
    return ['success' => true, 'message' => 'OTP verified successfully!'];
}

function send_otp_to_email($email, $otpCode) {
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

    $htmlContent = "
    <html>
        <body style='font-family: Arial, sans-serif; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 8px;'>
                <h2 style='color: #0066cc;'>Hello from Road and Transportation Department!</h2>
                <p>You requested to sign in or register on the LGU Portal. Use the verification code below to complete your process.</p>
                <div style='background-color: #f4f4f4; padding: 15px; text-align: center; border-radius: 5px; margin: 20px 0;'>
                    <span style='font-size: 24px; font-weight: bold; letter-spacing: 5px;'>" . $otpCode . "</span>
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
        'subject' => 'Hello from Road and Transportation Department!',
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
    send_otp_to_email($email, $otpCode);
    return $otpCode;
}

function handle_login_otp($email) {
    $otpCode = generate_otp();
    store_otp($email, $otpCode, 'login');
    send_otp_to_email($email, $otpCode);
    return $otpCode;
}

function handle_password_reset_otp($email) {
    $otpCode = generate_otp();
    store_otp($email, $otpCode, 'password_reset');
    send_otp_to_email($email, $otpCode);
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

function ensure_receive_email_updates_column($conn) {
    if (!$conn) {
        return;
    }
    $check = $conn->query("SHOW COLUMNS FROM road_transportation_reports LIKE 'receive_email_updates'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN receive_email_updates TINYINT(1) NOT NULL DEFAULT 0 AFTER reporter_phone");
    }
}

function send_citizen_report_approved_email($toEmail, $report) {
    $apiKey = env_get('BREVO_API_KEY');
    $senderName = env_get('BREVO_SENDER_NAME');
    $senderEmail = env_get('BREVO_SENDER_EMAIL');

    $nameSafe = htmlspecialchars((string)($report['reporter_name'] ?? 'Citizen'));
    $reportIdSafe = htmlspecialchars((string)($report['report_id'] ?? ''));
    $titleSafe = htmlspecialchars((string)($report['title'] ?? 'Citizen Report'));
    $typeSafe = htmlspecialchars(ucwords(str_replace('_', ' ', (string)($report['report_type'] ?? ''))));
    $locationSafe = htmlspecialchars((string)($report['location'] ?? 'Pinned location'));
    $description = trim((string)($report['description'] ?? ''));
    if (strlen($description) > 280) {
        $description = substr($description, 0, 277) . '...';
    }
    $descriptionSafe = nl2br(htmlspecialchars($description));

    $htmlContent = "
    <html>
        <body style='font-family: Arial, sans-serif; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 8px;'>
                <h2 style='color: #1e3c72;'>Your citizen report has been approved</h2>
                <p>Hello {$nameSafe},</p>
                <p>Your submitted citizen report has been <strong>approved</strong> by the Road and Transportation Department.</p>
                <table style='background-color: #f4f4f4; padding: 15px; text-align: left; border-radius: 5px; margin: 20px 0; width: 100%;'>
                    <tr><td style='padding: 4px 8px;'><strong>Report ID:</strong></td><td style='padding: 4px 8px;'>{$reportIdSafe}</td></tr>
                    <tr><td style='padding: 4px 8px;'><strong>Title:</strong></td><td style='padding: 4px 8px;'>{$titleSafe}</td></tr>
                    <tr><td style='padding: 4px 8px;'><strong>Issue Type:</strong></td><td style='padding: 4px 8px;'>{$typeSafe}</td></tr>
                    <tr><td style='padding: 4px 8px;'><strong>Location:</strong></td><td style='padding: 4px 8px;'>{$locationSafe}</td></tr>
                    <tr><td style='padding: 4px 8px; vertical-align: top;'><strong>Description:</strong></td><td style='padding: 4px 8px;'>{$descriptionSafe}</td></tr>
                </table>
                <p>You are receiving this message because you chose to receive email updates about this report.</p>
                <p style='font-size: 12px; color: #999; margin-top: 30px;'>Regards,<br>LGU Road and Transportation Monitoring</p>
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
                'name' => $report['reporter_name'] ?? $toEmail
            ]
        ],
        'subject' => 'Your citizen report ' . ($report['report_id'] ?? '') . ' has been approved',
        'htmlContent' => $htmlContent
    ]));

    $response = curl_exec($ch);
    curl_close($ch);

    error_log("Citizen report approval email response: " . $response);

    return json_decode($response, true);
}

function send_citizen_approval_email_if_opted_in($conn, $numericId) {
    try {
        ensure_receive_email_updates_column($conn);
        $stmt = $conn->prepare("SELECT report_id, title, report_type, description, location, reporter_name, reporter_email, receive_email_updates, report_source, report_category, created_by
            FROM road_transportation_reports WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return;
        }
        $id = (int)$numericId;
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return;
        }
        $isCitizen = (($row['report_source'] ?? '') === 'local')
            && (($row['report_category'] ?? '') === 'transportation')
            && ((int)($row['created_by'] ?? -1) === 0);
        if (!$isCitizen) {
            return;
        }
        if ((int)($row['receive_email_updates'] ?? 0) !== 1) {
            return;
        }
        $email = trim((string)($row['reporter_email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        send_citizen_report_approved_email($email, $row);
    } catch (Exception $e) {
        error_log('citizen approval email: ' . $e->getMessage());
    }
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
    try {
        $res = $conn->query(
            "SELECT ra.report_id, ra.report_type, u.full_name AS officer_name, ab.full_name AS assigner_name
             FROM report_assignments ra
             LEFT JOIN users u ON u.id = ra.user_id
             LEFT JOIN users ab ON ab.id = ra.assigned_by
             WHERE ra.status = 'active'"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $assigned[$row['report_type'] . ':' . $row['report_id']] = [
                    'officer' => $row['officer_name'] ?? '',
                    'assigner' => $row['assigner_name'] ?? '',
                ];
            }
        }
    } catch (Exception $e) {
        error_log("annotate_report_assignment_status error: " . $e->getMessage());
    }
    foreach ($reports as &$rr) {
        $table = $rr['_source_table'] ?? 'road_transportation_reports';
        $key = $table . ':' . ($rr['id'] ?? 0);
        $rr['assignment_status'] = isset($assigned[$key]) ? 'assigned' : 'unassigned';
        $rr['assignment_officer'] = $assigned[$key]['officer'] ?? '';
        $rr['assigned_by'] = $assigned[$key]['assigner'] ?? '';
        unset($rr['_source_table']);
    }
    unset($rr);
}

// Annotate a list of report rows with the state of their latest Transparency
// Upload Request, so the Completed Projects table can flag the projects an
// administrator still has to act on. Each row must carry its primary key in
// 'id' and its source in 'source' ('lgu', 'citizen' or 'cimm'), which together
// are how transparency_upload_requests identifies a report. Sets
// 'transparency_request_status' to '' (never requested), 'pending', 'approved'
// or 'rejected'. Display-only — it never touches a report's own status.
function annotate_transparency_request_status($conn, array &$reports) {
    if (empty($reports)) {
        return;
    }
    $latest = [];
    try {
        $exists = $conn->query("SHOW TABLES LIKE 'transparency_upload_requests'");
        if ($exists && $exists->num_rows > 0) {
            // Highest id per report/source wins, matching the single-report
            // lookup the transparency request API uses.
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
    } catch (Exception $e) {
        error_log('annotate_transparency_request_status error: ' . $e->getMessage());
    }
    foreach ($reports as &$rr) {
        $key = strtolower((string)($rr['source'] ?? '')) . ':' . (int)($rr['id'] ?? 0);
        $rr['transparency_request_status'] = $latest[$key] ?? '';
    }
    unset($rr);
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
?>
