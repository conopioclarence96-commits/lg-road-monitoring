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
    
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    $stmt->bind_param("issss", $user_id, $action, $details, $ip, $user_agent);
    $stmt->execute();
}

// File upload functions
function handle_file_upload($file, $upload_dir, $allowed_types = null) {
    if ($allowed_types === null) {
        $allowed_types = ALLOWED_FILE_TYPES;
    }
    
    // Check if file was uploaded
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'error' => 'No file uploaded or upload error'];
    }
    
    // Check file size
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success' => false, 'error' => 'File size exceeds maximum limit'];
    }
    
    // Check file type
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file_ext, $allowed_types)) {
        return ['success' => false, 'error' => 'File type not allowed'];
    }
    
    // Generate unique filename
    $filename = uniqid() . '.' . $file_ext;
    $filepath = $upload_dir . '/' . $filename;
    
    // Create upload directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0777, true)) {
            return ['success' => false, 'error' => 'Failed to create upload directory: ' . $upload_dir];
        }
        // Try to set permissions
        chmod($upload_dir, 0777);
    }
    
    // Check if directory is writable
    if (!is_writable($upload_dir)) {
        return ['success' => false, 'error' => 'Upload directory is not writable: ' . $upload_dir];
    }
    
    // Move file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        // Set file permissions
        chmod($filepath, 0644);
        return ['success' => true, 'filename' => $filename, 'filepath' => $filepath];
    } else {
        return ['success' => false, 'error' => 'Failed to move uploaded file to: ' . $filepath];
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
    header('Content-Type: application/json');
    http_response_code($status_code);
    echo json_encode($data);
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
?>
