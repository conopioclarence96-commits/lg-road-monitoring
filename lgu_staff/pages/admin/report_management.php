<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

// Session timeout configuration
$session_timeout = 5 * 60; // 5 minutes in seconds

// Check if session has expired
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
    header('Location: ../../login.php?timeout=1');
    exit();
}

// Update last activity time
$_SESSION['last_activity'] = time();

// Check if user is logged in and check role (logout if invalid role)
if (!is_logged_in() || $_SESSION['role'] !== 'system_admin') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    header('Location: ../../login.php');
    exit();
}

// Get user information
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'citizen';

// Get user details for reporting
$user_info = fetch_one("SELECT username, full_name, email FROM users WHERE id = ?", [$user_id], "i");
if (!$user_info) {
    $user_info = ['username' => 'Staff', 'full_name' => 'LGU Staff', 'email' => 'staff@lgu.gov.ph'];
}

// Check database connection and required tables
if (!$conn) {
    echo '<div style="background: #f8d7da; color: white; padding: 20px; text-align: center; border-radius: 8px; margin: 20px;">
        <h3>⚠️ Database Connection Required</h3>
        <p>Please ensure the database is properly configured and the following tables exist:</p>
        <ul style="text-align: left; margin: 20px 0;">
            <li><strong>road_transportation_reports</strong> - with estimation column</li>
            <li><strong>road_maintenance_reports</strong> - with estimation column</li>
        </ul>
        <p><strong>Required SQL:</strong></p>
        <pre style="background: #fff; padding: 15px; border-radius: 4px; text-align: left;">
-- Add estimation column if it doesn\'t exist
ALTER TABLE road_transportation_reports 
ADD COLUMN IF NOT EXISTS estimation DECIMAL(10,2) DEFAULT 0.00 
AFTER resolution_notes;

ALTER TABLE road_maintenance_reports 
ADD COLUMN IF NOT EXISTS estimation DECIMAL(10,2) DEFAULT 0.00 
AFTER updated_at;
        </pre>
        <p style="margin-top: 15px;">After running the SQL, refresh this page.</p>
    </div>';
    exit;
}

// Check if estimation column exists
$estimation_column_exists = false;
$result = $conn->query("SHOW COLUMNS FROM road_transportation_reports LIKE 'estimation'");
if ($result && $result->num_rows > 0) {
    $estimation_column_exists = true;
}

$result = $conn->query("SHOW COLUMNS FROM road_maintenance_reports LIKE 'estimation'");
if ($result && $result->num_rows > 0) {
    $maintenance_estimation_exists = true;
}

// Show warning if estimation columns don't exist
if (!$estimation_column_exists || !$maintenance_estimation_exists) {
    echo '<div style="background: #fff3cd; color: #856404; padding: 15px; text-align: center; border-radius: 8px; margin: 20px;">
        <h3>⚠️ Database Update Required</h3>
        <p>The <strong>estimation</strong> column is missing from one or both database tables.</p>
        <p><strong>Current Status:</strong></p>
        <ul style="text-align: left; margin: 20px 0;">
            <li>Transportation Reports: ' . ($estimation_column_exists ? '✅ Available' : '❌ Missing') . '</li>
            <li>Maintenance Reports: ' . ($maintenance_estimation_exists ? '✅ Available' : '❌ Missing') . '</li>
        </ul>
        <p style="margin-top: 15px;"><strong>Required SQL:</strong></p>
        <pre style="background: #fff; padding: 15px; border-radius: 4px; text-align: left;">
-- Add estimation column if it doesn\'t exist
ALTER TABLE road_transportation_reports 
ADD COLUMN IF NOT EXISTS estimation DECIMAL(10,2) DEFAULT 0.00 
AFTER resolution_notes;

ALTER TABLE road_maintenance_reports 
ADD COLUMN IF NOT EXISTS estimation DECIMAL(10,2) DEFAULT 0.00 
AFTER updated_at;
        </pre>
        <p style="margin-top: 15px;">After running the SQL, refresh this page.</p>
        <button onclick="location.reload()" style="background: #007bff; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer;">Refresh Page</button>
    </div>';
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Invalid CSRF token');
        header('Location: ../admin/report_management.php');
        exit();
    }
    
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'receive_report':
            handle_receive_report();
            break;
        case 'update_report':
            handle_update_report();
            break;
        case 'delete_report':
            handle_delete_report();
            break;
        case 'accept_external_report':
            handle_accept_external_report();
            break;
        case 'accept_department_report':
            handle_accept_department_report();
            break;
    }
}

function handle_receive_report() {
    global $conn, $user_id;
    
    $report_type = sanitize_input($_POST['report_type'] ?? '');
    $title = sanitize_input($_POST['title'] ?? '');
    $description = sanitize_input($_POST['description'] ?? '');
    $location = sanitize_input($_POST['location'] ?? '');
    $priority = sanitize_input($_POST['priority'] ?? 'medium');
    $latitude = floatval($_POST['latitude'] ?? 0);
    $longitude = floatval($_POST['longitude'] ?? 0);
    
    // Validation
    $errors = validate_required([
        'report_type' => $report_type,
        'title' => $title,
        'description' => $description,
        'location' => $location
    ]);
    
    if (!empty($errors)) {
        set_flash_message('error', 'Please fill in all required fields');
        return;
    }
    
    // Insert into appropriate table
    if ($report_type === 'transportation') {
        $report_id = generate_unique_id('RTR-');
        $department = 'engineering'; // Default department
        $stmt = $conn->prepare("INSERT INTO road_transportation_reports (report_id, report_type, title, department, priority, status, created_date, description, location, latitude, longitude, reporter_name, reporter_email, created_by, created_at) VALUES (?, 'infrastructure_issue', ?, ?, ?, 'pending', CURDATE(), ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("sssssdssssi", $report_id, $title, $department, $priority, $description, $location, $latitude, $longitude, $user_info['full_name'], $user_info['email'], $user_id);
    } else {
        $report_id = generate_unique_id('MNT-');
        $department = 'maintenance'; // Default department
        $stmt = $conn->prepare("INSERT INTO road_maintenance_reports (report_id, report_type, title, department, priority, status, created_date, description, location, created_by, created_at) VALUES (?, 'emergency', ?, ?, ?, 'pending', CURDATE(), ?, ?, ?, NOW())");
        $stmt->bind_param("ssssssi", $report_id, $title, $department, $priority, $description, $location, $user_id);
    }
    
    if ($stmt->execute()) {
        log_audit_action($user_id, "Received {$report_type} report", "Title: {$title}, Location: {$location}");
        set_flash_message('success', 'Report received successfully');
    } else {
        set_flash_message('error', 'Failed to receive report: ' . $conn->error);
    }
}

function handle_update_report() {
    global $conn, $user_id;
    
    $report_id = intval($_POST['report_id'] ?? 0);
    $report_type = sanitize_input($_POST['report_type'] ?? '');
    $report_type_from_db = sanitize_input($_POST['report_type_from_db'] ?? '');
    $status = sanitize_input($_POST['status'] ?? '');
    $priority = sanitize_input($_POST['priority'] ?? '');
    $notes = sanitize_input($_POST['notes'] ?? '');
    $title = sanitize_input($_POST['title'] ?? '');
    $description = sanitize_input($_POST['description'] ?? '');
    $location = sanitize_input($_POST['location'] ?? '');
    
    if ($report_id <= 0 || empty($report_type) || empty($status)) {
        set_flash_message('error', 'Invalid report data');
        return;
    }
    
    // Update the report
    $table = ($report_type === 'transportation') ? 'road_transportation_reports' : 'road_maintenance_reports';
    
    $update_fields = [];
    $params = [];
    $types = '';
    
    $update_fields[] = "status = ?"; $params[] = $status; $types .= "s";
    $update_fields[] = "priority = ?"; $params[] = $priority; $types .= "s";
    $update_fields[] = "updated_at = NOW()";
    
    if (!empty($title)) { $update_fields[] = "title = ?"; $params[] = $title; $types .= "s"; }
    if (!empty($description)) { $update_fields[] = "description = ?"; $params[] = $description; $types .= "s"; }
    if (!empty($location)) { $update_fields[] = "location = ?"; $params[] = $location; $types .= "s"; }
    
    if ($table === 'road_transportation_reports') {
        $update_fields[] = "resolution_notes = ?";
        $params[] = $notes;
        $types .= "s";
    }
    
    $params[] = $report_id;
    $types .= "i";
    
    // Handle photo uploads
    $uploaded_photos = [];
    if (!empty($_FILES['report_photos']) && is_array($_FILES['report_photos']['name'])) {
        $upload_dir = __DIR__ . '/../../uploads/report_images';
        foreach ($_FILES['report_photos']['name'] as $i => $name) {
            if ($_FILES['report_photos']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $file = [
                'name' => $_FILES['report_photos']['name'][$i],
                'type' => $_FILES['report_photos']['type'][$i],
                'tmp_name' => $_FILES['report_photos']['tmp_name'][$i],
                'error' => $_FILES['report_photos']['error'][$i],
                'size' => $_FILES['report_photos']['size'][$i]
            ];
            $result = handle_file_upload($file, $upload_dir, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
            if ($result['success']) {
                $uploaded_photos[] = [
                    'type' => 'image',
                    'filename' => $result['filename'],
                    'original_name' => $file['name'],
                    'file_path' => 'uploads/report_images/' . $result['filename'],
                    'uploaded_at' => date('Y-m-d H:i:s'),
                    'uploaded_by' => $user_id
                ];
            }
        }
    }
    
    if (!empty($uploaded_photos) && $table === 'road_transportation_reports') {
        $existing = fetch_one("SELECT attachments, image_path FROM {$table} WHERE id = ?", [$report_id], "i");
        $existing_attachments = [];
        if ($existing && !empty($existing['attachments'])) {
            $existing_attachments = json_decode($existing['attachments'], true) ?: [];
        }
        $all_attachments = array_merge($existing_attachments, $uploaded_photos);
        
        $update_fields[] = "attachments = ?";
        $update_fields[] = "image_path = ?";
        $params[] = json_encode($all_attachments);
        $params[] = $uploaded_photos[0]['file_path'];
        $types .= "ss";
    }
    
    $query = "UPDATE {$table} SET " . implode(', ', $update_fields) . " WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        $change_log = "Report ID: {$report_id}, New Status: {$status}";
        if (!empty($uploaded_photos)) $change_log .= ", Photos added: " . count($uploaded_photos);
        
        log_audit_action($user_id, "Updated {$report_type_from_db} report", $change_log);

        // Create a progress update entry so photos and changes appear in the Updates timeline
        $update_title = 'Report Updated';
        $update_desc_parts = [];
        if (!empty($notes)) $update_desc_parts[] = $notes;
        $update_desc_parts[] = "Status: " . ucfirst(str_replace('-', ' ', $status));
        $update_desc_parts[] = "Priority: " . ucfirst($priority);
        $update_desc = implode('. ', $update_desc_parts);

        try {
            $upd_stmt = $conn->prepare("INSERT INTO report_updates (report_id, user_id, title, description) VALUES (?, ?, ?, ?)");
            $upd_stmt->bind_param("iiss", $report_id, $user_id, $update_title, $update_desc);
            $upd_stmt->execute();
            $new_update_id = $conn->insert_id;

            // Save uploaded photos to report_update_media
            if (!empty($uploaded_photos) && $new_update_id > 0) {
                foreach ($uploaded_photos as $photo) {
                    $media_stmt = $conn->prepare("INSERT INTO report_update_media (update_id, file_path, file_type) VALUES (?, ?, ?)");
                    $media_stmt->bind_param("iss", $new_update_id, $photo['file_path'], $photo['type']);
                    $media_stmt->execute();
                }
            }
        } catch (Exception $e) {
            error_log("Failed to create progress update entry: " . $e->getMessage());
        }

        set_flash_message('success', 'Report updated successfully');
        
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Report updated successfully',
                'photos_added' => count($uploaded_photos)
            ]);
            exit;
        }
    } else {
        set_flash_message('error', 'Failed to update report: ' . $conn->error);
        
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Failed to update report: ' . $conn->error]);
            exit;
        }
    }
}

function handle_delete_report() {
    global $conn, $user_id;
    
    $report_id = intval($_POST['report_id'] ?? 0);
    $report_type = sanitize_input($_POST['report_type'] ?? '');
    
    if ($report_id <= 0 || empty($report_type)) {
        set_flash_message('error', 'Invalid report data');
        return;
    }
    
    // Get report info for logging
    $table = ($report_type === 'transportation') ? 'road_transportation_reports' : 'road_maintenance_reports';
    $stmt = $conn->prepare("SELECT title, location FROM {$table} WHERE id = ?");
    $stmt->bind_param("i", $report_id);
    $stmt->execute();
    $report_info = $stmt->get_result()->fetch_assoc();
    
    // Archive the report first
    if ($report_type === 'transportation') {
        $insert = "INSERT INTO road_transportation_reports_archive SELECT * FROM {$table} WHERE id = ?";
    } else {
        $insert = "INSERT INTO road_transportation_reports_archive (id, report_id, title, report_type, department, priority, status, created_date, due_date, description, location, attachments, latitude, longitude, created_at, updated_at, approved_at, rejected_at) SELECT id, report_id, title, report_type, department, priority, status, created_date, due_date, description, location, NULL, NULL, NULL, created_at, updated_at, approved_at, rejected_at FROM {$table} WHERE id = ?";
    }
    $stmt = $conn->prepare($insert);
    $stmt->bind_param("i", $report_id);
    $stmt->execute();
    
    // Delete the report from the active table
    $stmt = $conn->prepare("DELETE FROM {$table} WHERE id = ?");
    $stmt->bind_param("i", $report_id);
    
    if ($stmt->execute()) {
        $report_title = $report_info['title'] ?? 'Unknown Report';
        log_audit_action($user_id, "Archived {$report_type} report", "Report ID: {$report_id}, Title: {$report_title}");
    } else {
        set_flash_message('error', 'Failed to archive report: ' . $conn->error);
    }
}

function handle_accept_external_report() {
    global $conn, $user_id;
    
    $external_id = sanitize_input($_POST['report_id'] ?? '');
    $title = sanitize_input($_POST['title'] ?? '');
    $description = sanitize_input($_POST['description'] ?? '');
    $location = sanitize_input($_POST['location'] ?? '');
    $priority = sanitize_input($_POST['priority'] ?? 'medium');
    $report_type = sanitize_input($_POST['report_type'] ?? '');
    $source = sanitize_input($_POST['source'] ?? '');
    
    if (empty($title) || empty($description) || empty($location)) {
        set_flash_message('error', 'Missing required report data');
        return;
    }
    
    // Insert into appropriate table
    if ($report_type === 'transportation') {
        $report_id = generate_unique_id('RTR-');
        $department = 'engineering';
        $stmt = $conn->prepare("INSERT INTO road_transportation_reports (report_id, report_type, title, department, priority, status, created_date, description, location, reporter_name, reporter_email, created_by, created_at) VALUES (?, 'infrastructure_issue', ?, ?, ?, 'pending', CURDATE(), ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssssssssi", $report_id, $title, $department, $priority, $description, $location, $source, $source, $user_id);
    } else {
        $report_id = generate_unique_id('MNT-');
        $department = 'maintenance';
        $stmt = $conn->prepare("INSERT INTO road_maintenance_reports (report_id, report_type, title, department, priority, status, created_date, description, location, created_by, created_at) VALUES (?, 'emergency', ?, ?, ?, 'pending', CURDATE(), ?, ?, ?, NOW())");
        $stmt->bind_param("ssssssi", $report_id, $title, $department, $priority, $description, $location, $user_id);
    }
    
    if ($stmt->execute()) {
        log_audit_action($user_id, "Accepted external report", "External ID: {$external_id}, Title: {$title}, Source: {$source}");
        set_flash_message('success', 'External report accepted and added to system');
    } else {
        set_flash_message('error', 'Failed to accept external report: ' . $conn->error);
    }
}

function handle_accept_department_report() {
    global $conn, $user_id;
    
    $dept_id = sanitize_input($_POST['report_id'] ?? '');
    $title = sanitize_input($_POST['title'] ?? '');
    $description = sanitize_input($_POST['description'] ?? '');
    $location = sanitize_input($_POST['location'] ?? '');
    $priority = sanitize_input($_POST['priority'] ?? 'medium');
    $report_type = sanitize_input($_POST['report_type'] ?? '');
    $source = sanitize_input($_POST['source'] ?? '');
    
    if (empty($title) || empty($description) || empty($location)) {
        set_flash_message('error', 'Missing required report data');
        return;
    }
    
    // Insert into appropriate table
    if ($report_type === 'transportation') {
        $report_id = generate_unique_id('RTR-');
        $department = 'engineering';
        $stmt = $conn->prepare("INSERT INTO road_transportation_reports (report_id, report_type, title, department, priority, status, created_date, description, location, reporter_name, reporter_email, created_by, created_at) VALUES (?, 'infrastructure_issue', ?, ?, ?, 'pending', CURDATE(), ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssssssssi", $report_id, $title, $department, $priority, $description, $location, $source, $source, $user_id);
    } else {
        $report_id = generate_unique_id('MNT-');
        $department = 'maintenance';
        $stmt = $conn->prepare("INSERT INTO road_maintenance_reports (report_id, report_type, title, department, priority, status, created_date, description, location, created_by, created_at) VALUES (?, 'emergency', ?, ?, ?, 'pending', CURDATE(), ?, ?, ?, NOW())");
        $stmt->bind_param("ssssssi", $report_id, $title, $department, $priority, $description, $location, $user_id);
    }
    
    if ($stmt->execute()) {
        log_audit_action($user_id, "Accepted department report", "Dept ID: {$dept_id}, Title: {$title}, Source: {$source}");
        set_flash_message('success', 'Department report accepted and added to system');
    } else {
        set_flash_message('error', 'Failed to accept department report: ' . $conn->error);
    }
}

// Get reports for display
function get_reports($status_filter = 'all', $type_filter = 'all', $limit = 50, $offset = 0) {
    global $conn;
    
    $reports = [];
    
    // Check if estimation column exists
    $transport_estimation_exists = false;
    $maintenance_estimation_exists = false;
    
    $result = $conn->query("SHOW COLUMNS FROM road_transportation_reports LIKE 'estimation'");
    if ($result && $result->num_rows > 0) {
        $transport_estimation_exists = true;
    }
    
    $result = $conn->query("SHOW COLUMNS FROM road_maintenance_reports LIKE 'estimation'");
    if ($result && $result->num_rows > 0) {
        $maintenance_estimation_exists = true;
    }
    
    // Get transportation reports
    if ($transport_estimation_exists) {
        $transport_query = "SELECT id, report_id, title, description, location, latitude, longitude, priority, status, assigned_to, estimation, resolution_notes as notes, department, created_date, created_at, updated_at, attachments, image_path, 'transportation' as report_type FROM road_transportation_reports";
    } else {
        $transport_query = "SELECT id, report_id, title, description, location, latitude, longitude, priority, status, assigned_to, 0 as estimation, resolution_notes as notes, department, created_date, created_at, updated_at, attachments, image_path, 'transportation' as report_type FROM road_transportation_reports";
    }
    $transport_params = [];
    
    // Get maintenance reports
    if ($maintenance_estimation_exists) {
        $maintenance_query = "SELECT id, report_id, title, description, location, priority, status, maintenance_team as assigned_to, estimation, department, created_date, created_at, updated_at, NULL as attachments, NULL as image_path, 'maintenance' as report_type FROM road_maintenance_reports";
    } else {
        $maintenance_query = "SELECT id, report_id, title, description, location, priority, status, maintenance_team as assigned_to, 0 as estimation, department, created_date, created_at, updated_at, NULL as attachments, NULL as image_path, 'maintenance' as report_type FROM road_maintenance_reports";
    }
    $maintenance_params = [];
    
    // Apply filters
    $where_conditions = [];
    
    $status_filter = $_GET['status'] ?? 'all';
    $type_filter = $_GET['type'] ?? 'all';
    
    if ($status_filter !== 'all') {
        $where_conditions[] = "status = ?";
        $params[] = $status_filter;
    }
    
    if ($type_filter !== 'all') {
        if ($type_filter === 'transportation') {
            $transport_query .= " WHERE " . implode(' AND ', $where_conditions);
            $maintenance_query = "SELECT NULL FROM road_maintenance_reports WHERE 1=0"; // Empty result
        } else {
            $transport_query = "SELECT NULL FROM road_transportation_reports WHERE 1=0"; // Empty result
            $maintenance_query .= " WHERE " . implode(' AND ', $where_conditions);
        }
    } elseif (!empty($where_conditions)) {
        $transport_query .= " WHERE " . implode(' AND ', $where_conditions);
        $maintenance_query .= " WHERE " . implode(' AND ', $where_conditions);
        $transport_params = $params;
        $maintenance_params = $params;
    }
    
    $transport_query .= " ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";
    $maintenance_query .= " ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";
    
    // Execute queries
    if (!empty($transport_params)) {
        $stmt = $conn->prepare($transport_query);
        $stmt->bind_param(str_repeat('s', count($transport_params)), ...$transport_params);
        $stmt->execute();
        $transport_reports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        $stmt = $conn->prepare($maintenance_query);
        $stmt->bind_param(str_repeat('s', count($maintenance_params)), ...$maintenance_params);
        $stmt->execute();
        $maintenance_reports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $transport_reports = fetch_all($transport_query);
        $maintenance_reports = fetch_all($maintenance_query);
    }
    
    // Combine and sort
    $all_reports = array_merge($transport_reports ?: [], $maintenance_reports ?: []);
    usort($all_reports, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    return array_slice($all_reports, 0, $limit);
}

// Get report statistics
function get_report_stats() {
    global $conn;
    
    $stats = [
        'total_reports' => 0,
        'pending_reports' => 0,
        'in_progress_reports' => 0,
        'completed_reports' => 0,
        'high_priority_reports' => 0
    ];
    
    // Transportation stats
    $transport_stats = fetch_one("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'in-progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN priority = 'high' THEN 1 ELSE 0 END) as high_priority_count
        FROM road_transportation_reports");
    
    // Maintenance stats
    $maintenance_stats = fetch_one("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'in-progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN priority = 'high' THEN 1 ELSE 0 END) as high_priority_count
        FROM road_maintenance_reports");
    
    if ($transport_stats) {
        $stats['total_reports'] += $transport_stats['total'];
        $stats['pending_reports'] += $transport_stats['pending'];
        $stats['in_progress_reports'] += $transport_stats['in_progress'];
        $stats['completed_reports'] += $transport_stats['completed'];
        $stats['high_priority_reports'] += $transport_stats['high_priority_count'];
    }
    
    if ($maintenance_stats) {
        $stats['total_reports'] += $maintenance_stats['total'];
        $stats['pending_reports'] += $maintenance_stats['pending'];
        $stats['in_progress_reports'] += $maintenance_stats['in_progress'];
        $stats['completed_reports'] += $maintenance_stats['completed'];
        $stats['high_priority_reports'] += $maintenance_stats['high_priority_count'];
    }
    
    return $stats;
}

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Filters
$status_filter = $_GET['status'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';

// Get data
$reports = get_reports($status_filter, $type_filter, $per_page, $offset);
$stats = get_report_stats();
$csrf_token = generate_csrf_token();
$flash_message = get_flash_message();

// Debug: Check if estimation values are present
if (!empty($reports)) {
    foreach ($reports as $index => $report) {
        if (isset($report['estimation']) && $report['estimation'] > 0) {
            // Found a report with estimation
            error_log("Report ID {$report['id']} has estimation: {$report['estimation']}");
        }
    }
}

// Ensure infrastructure_projects table exists
$conn->query("CREATE TABLE IF NOT EXISTS infrastructure_projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    location VARCHAR(255),
    budget DECIMAL(12,2),
    progress INT DEFAULT 0,
    status ENUM('active', 'completed', 'delayed', 'pending') DEFAULT 'active',
    start_date DATE,
    end_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Ensure citizen_feedback table exists
$conn->query("CREATE TABLE IF NOT EXISTS citizen_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    feedback_id VARCHAR(50) UNIQUE NOT NULL,
    feedback_type ENUM('complaint','suggestion','compliment','inquiry','service_rating') NOT NULL,
    category VARCHAR(100),
    department VARCHAR(50),
    service_area VARCHAR(100),
    rating INT,
    subject VARCHAR(255),
    message TEXT NOT NULL,
    citizen_name VARCHAR(100),
    citizen_email VARCHAR(100),
    citizen_phone VARCHAR(20),
    anonymous BOOLEAN DEFAULT FALSE,
    status ENUM('pending','in_review','responded','resolved','closed') DEFAULT 'pending',
    priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
    response TEXT,
    response_date TIMESTAMP NULL,
    responded_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Fetch approved citizen transportation reports (created_by = 0 = public submission)
$citizen_transport_reports = [];
$ct_result = $conn->query("SELECT id, report_id, title, description, location, latitude, longitude, priority, status, assigned_to, department, created_date, created_at, updated_at, attachments, image_path, reporter_name, reporter_email, severity, report_type, 'transportation' as report_type, approved_at, rejected_at FROM road_transportation_reports WHERE created_by = 0 AND status = 'approved' ORDER BY updated_at DESC LIMIT 200");
if ($ct_result && $ct_result->num_rows > 0) {
    $citizen_transport_reports = $ct_result->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Management - LGU Road Monitoring</title>
    <link rel="icon" type="image/png" href="../../assets/img/logocityhall.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/sidebar.css">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <link rel="stylesheet" href="../../css/progress-updates.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="../../js/progress-updates.js"></script>
    <style>
        body {
            background: #f7f5f0;
            min-height: 100vh;
        }
        
        .main-content {
            position: relative;
            z-index: 1;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        .main-content {
            margin-left: 250px;
            padding: 20px;
            position: relative;
            z-index: 1;
        }

        .dashboard-header {
            background: #f0f4fa;
            backdrop-filter: blur(15px);
            padding: 30px;
            border-radius: 16px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .welcome-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .welcome-text h1 {
            color: #1e3c72;
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .welcome-text p {
            color: #666;
            font-size: 16px;
        }

        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.9), rgba(255, 255, 255, 0.7));
            backdrop-filter: blur(10px);
            padding: 20px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #3762c8, #1e3c72);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(55, 98, 200, 0.2);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            margin-bottom: 15px;
        }

        .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: #1e3c72;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #666;
            font-size: 14px;
            font-weight: 500;
        }

        .main-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        .chart-container {
            background: #f0f4fa;
            backdrop-filter: blur(15px);
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .chart-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e3c72;
        }

        .report-card {
            background: #f0f4fa;
            backdrop-filter: blur(15px);
            padding: 20px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .report-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #3762c8, #1e3c72);
        }

        .report-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(55, 98, 200, 0.2);
        }

        .priority-high .report-card::before {
            background: linear-gradient(90deg, #dc3545, #c82333);
        }

        .priority-medium .report-card::before {
            background: linear-gradient(90deg, #ffc107, #e0a800);
        }

        .priority-low .report-card::before {
            background: linear-gradient(90deg, #28a745, #1e7e34);
        }

        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .report-title {
            font-size: 16px;
            font-weight: 600;
            color: #1e3c72;
            margin-bottom: 5px;
        }

        .report-meta {
            font-size: 12px;
            color: #666;
            line-height: 1.4;
        }

        .report-description {
            color: #333;
            font-size: 14px;
            margin-bottom: 15px;
            line-height: 1.5;
        }

        .report-actions {
            display: flex;
            gap: 10px;
            opacity: 0.7;
            transition: opacity 0.2s;
        }

        .report-card:hover .report-actions {
            opacity: 1;
        }

        .btn-action {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-view {
            background: rgba(55, 98, 200, 0.1);
            color: #3762c8;
        }

        .btn-view:hover {
            background: #3762c8;
            color: white;
        }

        .btn-edit {
            background: rgba(255, 193, 7, 0.1);
            color: #ffc107;
        }

        .btn-edit:hover {
            background: #ffc107;
            color: white;
        }

        .btn-delete {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .btn-delete:hover {
            background: #dc3545;
            color: white;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            text-transform: uppercase;
        }

        .status-pending {
            background: rgba(255, 193, 7, 0.2);
            color: #856404;
        }

        .status-in-progress {
            background: rgba(23, 162, 184, 0.2);
            color: #0c5460;
        }

        .status-completed {
            background: rgba(40, 167, 69, 0.2);
            color: #155724;
        }

        .filters-section {
            background: #f0f4fa;
            backdrop-filter: blur(15px);
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 30px;
        }

        .filter-group {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            background: white;
            min-width: 150px;
        }

        .btn-primary-custom {
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            font-weight: 500;
        }

        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(55, 98, 200, 0.3);
        }

        .btn-secondary-custom {
            background: rgba(108, 117, 125, 0.1);
            color: #6c757d;
            border: 1px solid #6c757d;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-secondary-custom:hover {
            background: #6c757d;
            color: white;
        }

        .btn-success-custom {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
            border: 1px solid #28a745;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-success-custom:hover {
            background: #28a745;
            color: white;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: white;
            margin: 3% auto;
            padding: 0;
            border-radius: 16px;
            width: 92%;
            max-width: 600px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease;
        }

        .form-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 18px 20px;
            margin-bottom: 16px;
            border: 1px solid #e9ecef;
        }

        .form-section h6 {
            color: #1e3c72;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-section h6 i {
            color: #3762c8;
            font-size: 14px;
        }

        .form-section .form-group {
            margin-bottom: 12px;
        }

        .form-section .form-group:last-child {
            margin-bottom: 0;
        }

        .form-section .form-label {
            font-size: 12px;
            font-weight: 500;
            color: #495057;
            margin-bottom: 4px;
        }

        .form-section .form-control {
            font-size: 13px;
            padding: 8px 12px;
        }

        input[type="file"].form-control {
            padding: 6px 10px;
            font-size: 12px;
        }

        #existingPhotos img,
        #photoPreview img {
            transition: transform 0.2s;
        }

        #existingPhotos img:hover,
        #photoPreview img:hover {
            transform: scale(1.05);
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(100px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .modal-header {
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: white;
            padding: 20px 25px;
            border-radius: 16px 16px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 600;
            margin: 0;
        }

        .close {
            color: white;
            font-size: 24px;
            cursor: pointer;
            background: none;
            border: none;
        }

        .close:hover {
            opacity: 0.7;
        }

        .modal-body {
            padding: 25px;
            max-height: 70vh;
            overflow-y: auto;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: #3762c8;
            box-shadow: 0 0 0 3px rgba(55, 98, 200, 0.1);
        }

        select.form-control {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23373c72'%3e%3cpath d='M7 10l5 5 5-5z'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 0.7rem center;
            background-size: 1.2em;
            padding-right: 2.5rem;
        }

        select.form-control option {
            background: white;
            color: #333;
        }

        .form-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .form-section h6 {
            color: #1e3c72;
            margin-bottom: 15px;
            font-weight: 600;
        }

        .modal-footer {
            padding: 20px 25px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: none;
        }

        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-error {
            background: rgba(220, 53, 69, 0.1);
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 10px;
            }
            
            .welcome-section {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .filter-group {
                flex-direction: column;
                align-items: stretch;
            }
            
            .report-actions {
                flex-wrap: wrap;
            }
        }

        body.dark-mode .report-card {
            background: #22262e;
            border-color: #2d323b;
        }
        body.dark-mode .report-card::before {
            opacity: 0.8;
        }
        body.dark-mode .report-title {
            color: #f0f2f5;
        }
        body.dark-mode .report-meta {
            color: #9ca3af;
        }
        body.dark-mode .report-description {
            color: #d1d5db;
        }
        body.dark-mode .filters-section {
            background: #1e2229;
            border-color: #2d323b;
        }
        body.dark-mode .filter-select {
            background: #1a1d23;
            border-color: #2d323b;
            color: #e4e6ea;
        }
        body.dark-mode .chart-title {
            color: #f0f2f5;
        }
        body.dark-mode .status-pending {
            background: rgba(217, 119, 6, 0.2);
            color: #fbbf24;
        }
        body.dark-mode .status-in-progress {
            background: rgba(37, 99, 235, 0.2);
            color: #60a5fa;
        }
        body.dark-mode .status-completed {
            background: rgba(5, 150, 105, 0.2);
            color: #34d399;
        }
        body.dark-mode .main-grid {
            background: transparent;
        }
        body.dark-mode .chart-container {
            background: #1e2229;
            border-color: #2d323b;
        }
        body.dark-mode .chart-header .text-muted {
            color: #9ca3af;
        }
        body.dark-mode .dashboard-header {
            background: #1e2229;
            border-color: #2d323b;
        }
        body.dark-mode .welcome-text h1 {
            color: #f0f2f5;
        }
        body.dark-mode .welcome-text p {
            color: #9ca3af;
        }
        body.dark-mode .stat-card {
            background: #1e2229;
            border-color: rgba(59, 130, 246, 0.2);
        }
        body.dark-mode .stat-card::before {
            opacity: 0.8;
        }
        body.dark-mode .stat-number {
            color: #f0f2f5;
        }
        body.dark-mode .stat-label {
            color: #9ca3af;
        }

        /* ── CIMM Reports Panel ── */
        .cimm-panel {
            background: #f0f4fa;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid #e0e0e0;
            margin-bottom: 25px;
            overflow: hidden;
        }
        body.dark-mode .cimm-panel {
            background: #1e2229;
            border-color: #2d323b;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }
        .cimm-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            background: linear-gradient(135deg, rgba(55, 98, 200, 0.08), rgba(30, 60, 114, 0.05));
            border-bottom: 1px solid rgba(55, 98, 200, 0.1);
        }
        .cimm-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .cimm-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 22px;
        }
        .cimm-title-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .cimm-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e3c72;
        }
        body.dark-mode .cimm-title { color: #f0f2f5; }
        .cimm-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .cimm-badge.pending { background: rgba(251, 191, 36, 0.15); color: #f59e0b; }
        .cimm-badge.in-progress { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
        .cimm-badge.completed,
        .cimm-badge.resolved { background: rgba(34, 197, 94, 0.15); color: #22c55e; }
        .cimm-subtitle {
            font-size: 13px;
            color: #666;
            margin: 2px 0 0;
        }
        body.dark-mode .cimm-subtitle { color: #9ca3af; }
        .cimm-search {
            display: flex;
            gap: 12px;
            padding: 16px 24px;
            border-bottom: 1px solid rgba(55, 98, 200, 0.08);
        }
        .cimm-search-wrapper {
            position: relative;
            flex: 1;
        }
        .cimm-search-wrapper i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }
        .cimm-search-input {
            width: 100%;
            padding: 10px 14px 10px 38px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 13px;
            background: #fff;
            outline: none;
            transition: border-color 0.2s;
        }
        .cimm-search-input:focus { border-color: #3762c8; }
        body.dark-mode .cimm-search-input { background: #2d323b; border-color: #3d424b; color: #e4e6ea; }
        .cimm-sort-btn {
            padding: 10px 16px;
            background: rgba(55, 98, 200, 0.1);
            color: #3762c8;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .cimm-sort-btn:hover { background: rgba(55, 98, 200, 0.2); }

        /* ── Infrastructure Projects Panel ── */
        .infra-panel {
            background: #fff8f0;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid #f0e0cc;
            margin-bottom: 25px;
            overflow: hidden;
        }
        body.dark-mode .infra-panel {
            background: #1e2229;
            border-color: #3d3226;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }
        .infra-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            background: linear-gradient(135deg, rgba(249, 115, 22, 0.08), rgba(234, 88, 12, 0.05));
            border-bottom: 1px solid rgba(249, 115, 22, 0.1);
        }
        .infra-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .infra-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #f97316, #ea580c);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 22px;
        }
        .infra-title-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .infra-title {
            font-size: 18px;
            font-weight: 600;
            color: #9a3412;
        }
        body.dark-mode .infra-title { color: #fdba74; }
        .infra-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .infra-badge.pending { background: rgba(251, 191, 36, 0.15); color: #f59e0b; }
        .infra-badge.active { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
        .infra-badge.in-progress,
        .infra-badge.completed { background: rgba(34, 197, 94, 0.15); color: #22c55e; }
        .infra-badge.delayed { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
        .infra-subtitle {
            font-size: 13px;
            color: #92400e;
            margin: 2px 0 0;
        }
        body.dark-mode .infra-subtitle { color: #9ca3af; }
        .infra-search {
            display: flex;
            gap: 12px;
            padding: 16px 24px;
            border-bottom: 1px solid rgba(249, 115, 22, 0.08);
        }
        .infra-search-wrapper {
            position: relative;
            flex: 1;
        }
        .infra-search-wrapper i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }
        .infra-search-input {
            width: 100%;
            padding: 10px 14px 10px 38px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 13px;
            background: #fff;
            outline: none;
            transition: border-color 0.2s;
        }
        .infra-search-input:focus { border-color: #f97316; }
        body.dark-mode .infra-search-input { background: #2d323b; border-color: #3d424b; color: #e4e6ea; }
        .infra-sort-btn {
            padding: 10px 16px;
            background: rgba(249, 115, 22, 0.1);
            color: #f97316;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .infra-sort-btn:hover { background: rgba(249, 115, 22, 0.2); }

        /* ── Citizen Reports Panel ── */
        .citizen-panel {
            background: #f0faf5;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid #d0e6d8;
            margin-bottom: 25px;
            overflow: hidden;
        }
        body.dark-mode .citizen-panel {
            background: #1e2229;
            border-color: #1f3d2e;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }
        .citizen-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.08), rgba(5, 150, 105, 0.05));
            border-bottom: 1px solid rgba(16, 185, 129, 0.1);
        }
        .citizen-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .citizen-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #10b981, #059669);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 22px;
        }
        .citizen-title-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .citizen-title {
            font-size: 18px;
            font-weight: 600;
            color: #065f46;
        }
        body.dark-mode .citizen-title { color: #6ee7b7; }
        .citizen-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .citizen-badge.pending { background: rgba(251, 191, 36, 0.15); color: #f59e0b; }
        .citizen-badge.in-review,
        .citizen-badge.responded { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
        .citizen-badge.resolved { background: rgba(34, 197, 94, 0.15); color: #22c55e; }
        .citizen-badge.closed { background: rgba(107, 114, 128, 0.15); color: #6b7280; }
        .citizen-subtitle {
            font-size: 13px;
            color: #065f46;
            margin: 2px 0 0;
        }
        body.dark-mode .citizen-subtitle { color: #9ca3af; }
        .citizen-search {
            display: flex;
            gap: 12px;
            padding: 16px 24px;
            border-bottom: 1px solid rgba(16, 185, 129, 0.08);
        }
        .citizen-search-wrapper {
            position: relative;
            flex: 1;
        }
        .citizen-search-wrapper i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }
        .citizen-search-input {
            width: 100%;
            padding: 10px 14px 10px 38px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 13px;
            background: #fff;
            outline: none;
            transition: border-color 0.2s;
        }
        .citizen-search-input:focus { border-color: #10b981; }
        body.dark-mode .citizen-search-input { background: #2d323b; border-color: #3d424b; color: #e4e6ea; }
        .citizen-sort-btn {
            padding: 10px 16px;
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .citizen-sort-btn:hover { background: rgba(16, 185, 129, 0.2); }
    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <!-- SIDEBAR -->
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="main-content">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="welcome-section">
                <div class="welcome-text">
                    <h1><i class="fas fa-clipboard-data"></i> Report Management</h1>
                    <p>Receive, update, and monitor road reports all in one place</p>
                </div>
                <div>
                    <button class="btn-primary-custom" onclick="openModal('receivedReportsModal')">
                        <i class="fas fa-inbox"></i> View Received Reports
                    </button>
                </div>
            </div>
        </div>

        <!-- Flash Message -->
        <?php if ($flash_message): ?>
            <div class="alert alert-<?php echo $flash_message['type']; ?>">
                <?php echo htmlspecialchars($flash_message['message']); ?>
                <button type="button" class="close" onclick="this.parentElement.style.display='none'">&times;</button>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="quick-stats">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['total_reports']); ?></div>
                <div class="stat-label">Total Reports</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['pending_reports']); ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-cogs"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['in_progress_reports']); ?></div>
                <div class="stat-label">In Progress</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['completed_reports']); ?></div>
                <div class="stat-label">Completed</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['high_priority_reports']); ?></div>
                <div class="stat-label">High Priority</div>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="filters-section">
            <div class="chart-header">
                <h3 class="chart-title">Filters</h3>
            </div>
            <div class="filter-group">
                <div>
                    <label class="form-label">Status Filter</label>
                    <select class="filter-select" id="statusFilter" onchange="filterReports()">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="in-progress" <?php echo $status_filter === 'in-progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Report Type</label>
                    <select class="filter-select" id="typeFilter" onchange="filterReports()">
                        <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                        <option value="transportation" <?php echo $type_filter === 'transportation' ? 'selected' : ''; ?>>Transportation</option>
                        <option value="maintenance" <?php echo $type_filter === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">&nbsp;</label>
                    <div>
                        <button class="btn-secondary-custom" onclick="resetFilters()">
                            <i class="fas fa-arrow-clockwise"></i> Reset
                        </button>
                        <button class="btn-success-custom" onclick="exportReports()">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reports List -->
        <div class="main-grid">
            <div class="chart-container">
                <div class="chart-header">
                    <h3 class="chart-title">Reports</h3>
                    <span class="text-muted"><?php echo count($reports); ?> reports found</span>
                </div>
                
                <?php if (empty($reports)): ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                        <p>No reports found matching your criteria.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($reports as $report): ?>
                        <div class="report-card priority-<?php echo $report['priority']; ?>" data-id="<?php echo $report['id']; ?>">
                            <div class="report-header">
                                <div>
                                    <div class="report-title"><?php echo htmlspecialchars($report['title']); ?></div>
                                    <div class="report-meta">
                                        <?php if (!empty($report['report_id'])): ?>
                                        <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($report['report_id']); ?> • 
                                        <?php endif; ?>
                                        <i class="fas fa-building"></i> <?php echo htmlspecialchars($report['department'] ?? 'Not specified'); ?> • 
                                        <i class="fas fa-tag"></i> <?php echo ucfirst($report['report_type']); ?> • 
                                        <i class="fas fa-geo-alt"></i> <?php echo htmlspecialchars($report['location']); ?> • 
                                        <i class="fas fa-flag"></i> Priority: <?php echo ucfirst($report['priority']); ?> • 
                                        <i class="fas fa-clock"></i> <?php echo format_datetime($report['created_at']); ?>
                                    </div>
                                </div>
                                <span class="status-badge status-<?php echo str_replace('_', '-', $report['status']); ?>">
                                    <?php echo ucwords(str_replace('-', ' ', str_replace('_', ' ', $report['status']))); ?>
                                </span>
                            </div>
                            
                            <div class="report-description">
                                <?php echo htmlspecialchars($report['description']); ?>
                            </div>
                            
                            <?php if (!empty($report['assigned_to'])): ?>
                            <div class="assignment-info" style="margin-bottom: 10px; padding: 8px 12px; background: rgba(55, 98, 200, 0.1); border-radius: 6px; border-left: 3px solid #3762c8;">
                                <i class="fas fa-user-hard-hat"></i> 
                                <strong>Assigned to:</strong> <?php echo htmlspecialchars($report['assigned_to']); ?>
                            </div>
                            <?php endif; ?>
                            
                            <div class="report-actions">
                                <button class="btn-action btn-view" onclick="viewReport(<?php echo $report['id']; ?>, '<?php echo $report['report_type']; ?>')">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button class="btn-action btn-edit" onclick="editReport(<?php echo $report['id']; ?>, '<?php echo $report['report_type']; ?>')">
                                    <i class="fas fa-pencil"></i> Edit
                                </button>
                                <button class="btn-action btn-delete" onclick="deleteReport(<?php echo $report['id']; ?>, '<?php echo $report['report_type']; ?>')">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                                <button class="btn-action btn-view" style="background:linear-gradient(135deg,#10b981,#059669);" onclick="viewReportUpdates(<?php echo $report['id']; ?>, '<?php echo $report['report_type']; ?>')">
                                    <i class="fas fa-clock"></i> Updates
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Approved Citizen Transportation Reports Panel -->
        <div class="citizen-panel" id="citizenTransportPanel">
            <div class="citizen-header">
                <div class="citizen-header-left">
                    <div class="citizen-icon" style="background:linear-gradient(135deg,#10b981,#059669);">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div>
                        <div class="citizen-title-group">
                            <h2 class="citizen-title">Approved Citizen Reports</h2>
                            <span class="citizen-badge pending" style="background:rgba(16,185,129,0.15);color:#10b981;"><?php echo count($citizen_transport_reports); ?> Reports</span>
                        </div>
                        <p class="citizen-subtitle">Public-submitted transportation reports that have been approved</p>
                    </div>
                </div>
            </div>
            <div class="citizen-search">
                <div class="citizen-search-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" class="citizen-search-input" id="citizenTransportSearchInput" placeholder="Search by Title, Location, Reporter...">
                </div>
            </div>
            <div class="main-grid">
                <div class="chart-container">
                    <div class="chart-header">
                        <h3 class="chart-title">Approved Reports</h3>
                        <span class="text-muted"><?php echo count($citizen_transport_reports); ?> reports found</span>
                    </div>
                    
                    <?php if (empty($citizen_transport_reports)): ?>
                        <div style="text-align: center; padding: 40px; color: #666;">
                            <i class="fas fa-check-circle" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                            <p>No approved citizen transportation reports yet.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($citizen_transport_reports as $ctr): ?>
                            <div class="report-card priority-<?php echo $ctr['priority']; ?>" data-id="<?php echo $ctr['id']; ?>">
                                <div class="report-header">
                                    <div>
                                        <div class="report-title"><?php echo htmlspecialchars($ctr['title']); ?></div>
                                        <div class="report-meta">
                                            <?php if (!empty($ctr['report_id'])): ?>
                                            <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($ctr['report_id']); ?> • 
                                            <?php endif; ?>
                                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($ctr['reporter_name'] ?: $ctr['reporter_email'] ?: 'Citizen'); ?> • 
                                            <i class="fas fa-tag"></i> <?php echo ucfirst($ctr['report_type']); ?> • 
                                            <i class="fas fa-geo-alt"></i> <?php echo htmlspecialchars($ctr['location']); ?> • 
                                            <i class="fas fa-flag"></i> Priority: <?php echo ucfirst($ctr['priority']); ?> • 
                                            <i class="fas fa-clock"></i> <?php echo format_datetime($ctr['created_at']); ?>
                                        </div>
                                    </div>
                                    <span class="status-badge status-approved">
                                        Approved
                                    </span>
                                </div>
                                
                                <div class="report-description">
                                    <?php echo htmlspecialchars($ctr['description']); ?>
                                </div>
                                
                                <?php if (!empty($ctr['approved_at'])): ?>
                                <div style="margin-bottom:10px;padding:8px 12px;background:rgba(16,185,129,0.1);border-radius:6px;border-left:3px solid #10b981;font-size:13px;color:#065f46;">
                                    <i class="fas fa-check-circle"></i> 
                                    <strong>Approved:</strong> <?php echo format_datetime($ctr['approved_at']); ?>
                                    <?php if (!empty($ctr['rejected_at'])): ?>
                                    &nbsp;•&nbsp; <strong>Rejected:</strong> <?php echo format_datetime($ctr['rejected_at']); ?>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="report-actions">
                                    <button class="btn-action btn-view" onclick="viewReport(<?php echo $ctr['id']; ?>, '<?php echo $ctr['report_type']; ?>')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <button class="btn-action btn-edit" onclick="editReport(<?php echo $ctr['id']; ?>, '<?php echo $ctr['report_type']; ?>')">
                                        <i class="fas fa-pencil"></i> Edit
                                    </button>
                                    <button class="btn-action btn-delete" onclick="deleteReport(<?php echo $ctr['id']; ?>, '<?php echo $ctr['report_type']; ?>')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                    <button class="btn-action btn-view" style="background:linear-gradient(135deg,#10b981,#059669);" onclick="viewReportUpdates(<?php echo $ctr['id']; ?>, '<?php echo $ctr['report_type']; ?>')">
                                        <i class="fas fa-clock"></i> Updates
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>

    <!-- Received Reports Modal -->
    <div id="receivedReportsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reports Received from Other Systems</h5>
                <button class="close" onclick="closeModal('receivedReportsModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-section">
                    <h6>External System Reports</h6>
                    <div id="externalReportsList">
                        <div style="text-align: center; padding: 20px; color: #666;">
                            <i class="fas fa-download" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                            <p>Loading reports from external systems...</p>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h6>Department Reports</h6>
                    <div id="departmentReportsList">
                        <div style="text-align: center; padding: 20px; color: #666;">
                            <i class="fas fa-building" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                            <p>Loading reports from other departments...</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary-custom" onclick="closeModal('receivedReportsModal')">Close</button>
                <button type="button" class="btn-primary-custom" onclick="refreshReceivedReports()">
                    <i class="fas fa-sync"></i> Refresh
                </button>
            </div>
        </div>
    </div>

    <!-- Edit Report Modal (Enhanced) -->
    <div id="editReportModal" class="modal">
        <div class="modal-content" style="max-width: 750px;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Update Report</h5>
                <button class="close" onclick="closeModal('editReportModal')">&times;</button>
            </div>
            <form method="POST" id="editReportForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="update_report">
                    <input type="hidden" name="report_id" id="editReportId">
                    <input type="hidden" name="report_type" id="editReportType">
                    <input type="hidden" name="report_type_from_db" id="editReportTypeFromDB">

                    <div class="form-section">
                        <h6><i class="fas fa-info-circle"></i> Basic Information</h6>
                        <div class="form-group">
                            <label for="editTitle" class="form-label">Title</label>
                            <input type="text" class="form-control" name="title" id="editTitle" placeholder="Report title">
                        </div>
                        <div class="form-group">
                            <label for="editDescription" class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="editDescription" rows="3" placeholder="Report description"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="editLocation" class="form-label">Location</label>
                            <input type="text" class="form-control" name="location" id="editLocation" placeholder="Report location">
                        </div>
                    </div>

                    <div class="form-section">
                        <h6><i class="fas fa-tasks"></i> Status & Assignment</h6>
                        <div style="display: flex; gap: 15px;">
                            <div class="form-group" style="flex: 1;">
                                <label for="editStatus" class="form-label">Status *</label>
                                <select class="form-control" name="status" id="editStatus" required>
                                    <option value="pending">Pending</option>
                                    <option value="approved">Approved</option>
                                    <option value="in-progress">In Progress</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label for="editPriority" class="form-label">Priority *</label>
                                <select class="form-control" name="priority" id="editPriority" required>
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                    <option value="critical">Critical</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h6><i class="fas fa-images"></i> Report Photos</h6>
                        <div id="existingPhotos" style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 12px;"></div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="editPhotos" class="form-label">Add New Photos</label>
                            <button type="button" id="add-edit-photos-btn" style="padding:8px 16px;background:#3762c8;color:white;border:none;border-radius:6px;cursor:pointer;font-size:13px;"><i class="fas fa-camera"></i> Add Photos</button>
                            <input type="file" name="report_photos[]" id="editPhotos" 
                                   accept="image/jpeg,image/png,image/gif,image/webp" multiple
                                   style="display:none;">
                            <small style="color: #666; font-size: 12px;">Accepted: JPG, PNG, GIF, WebP | Max: 5MB each</small>
                            <div id="photoPreview" style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px;"></div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h6><i class="fas fa-sticky-note"></i> Progress Notes</h6>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="editNotes" class="form-label">Update Notes / Resolution Details</label>
                            <textarea class="form-control" name="notes" id="editNotes" rows="4" 
                                      placeholder="Describe the current status, actions taken, or resolution details..."></textarea>
                            <small style="color: #666; font-size: 12px;">
                                <i class="fas fa-info-circle"></i> These notes will be visible to other staff members
                            </small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="justify-content: space-between;">
                    <div>
                        <span id="updateStatusIndicator" style="font-size: 12px; color: #666;"></span>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="btn-secondary-custom" onclick="closeModal('editReportModal')">Cancel</button>
                        <button type="submit" class="btn-primary-custom" id="updateSubmitBtn">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- View Report Modal -->
    <div id="viewReportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Report Details</h5>
                <button class="close" onclick="closeModal('viewReportModal')">&times;</button>
            </div>
            <div class="modal-body" id="viewReportContent">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary-custom" onclick="closeModal('viewReportModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Progress Updates Modal -->
    <div id="updatesModal" class="modal">
        <div class="modal-content" style="max-width: 750px;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-clock"></i> Progress Updates</h5>
                <button class="close" onclick="closeModal('updatesModal')">&times;</button>
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                <div class="timeline-container" id="updatesTimeline">
                    <div class="timeline-empty"><i class="fas fa-spinner fa-spin fa-2x" style="color:#3762c8;"></i></div>
                </div>
            </div>
            <div class="modal-footer" style="justify-content: space-between;">
                <span id="updateReportInfo" style="font-size: 13px; color: #6b7280;"></span>
                <div>
                    <button type="button" class="btn-action" id="addUpdateBtn" onclick="showUpdateForm(currentUpdatesReportId, currentUpdatesReportType)">+ Add Update</button>
                    <button type="button" class="btn-secondary-custom" onclick="closeModal('updatesModal')">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Lightbox -->
    <div class="lightbox-overlay" id="lightboxOverlay" onclick="closeLightbox()">
        <button class="lightbox-close" onclick="closeLightbox()">&times;</button>
        <img id="lightboxImage" src="" alt="Enlarged photo">
    </div>

    <script>
        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }

        function filterReports() {
            const status = document.getElementById('statusFilter').value;
            const type = document.getElementById('typeFilter').value;
            const url = new URL(window.location);
            url.searchParams.set('status', status);
            url.searchParams.set('type', type);
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        }

        function resetFilters() {
            const url = new URL(window.location);
            url.searchParams.delete('status');
            url.searchParams.delete('type');
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        }

        function exportReports() {
            const status = document.getElementById('statusFilter').value;
            const type = document.getElementById('typeFilter').value;
            const url = `../api/export_reports.php?status=${status}&type=${type}`;
            window.open(url, '_blank');
        }

        function imgFallback(el) {
            el.style.display = 'none';
            const fallback = document.createElement('div');
            fallback.style.cssText = 'width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:#f3f4f6;color:#9ca3af;font-size:11px;';
            fallback.textContent = 'No image';
            el.parentElement.insertBefore(fallback, el.nextSibling);
        }

        function viewReport(id, type) {
            fetch(`../api/get_report_details.php?id=${id}&type=${type}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const content = document.getElementById('viewReportContent');
                        content.innerHTML = `
                            <div style="line-height: 1.6;">
                                <h6 style="color: #1e3c72; margin-bottom: 15px;">${data.report.title}</h6>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                                    ${data.report.report_id ? `<div><strong>Report ID:</strong> ${data.report.report_id}</div>` : ''}
                                    ${data.report.department ? `<div><strong>Department:</strong> ${data.report.department}</div>` : ''}
                                    <div><strong>Type:</strong> ${data.report.report_type}</div>
                                    <div><strong>Status:</strong> <span class="status-badge status-${data.report.status.replace('_', '-')}">${data.report.status}</span></div>
                                    <div><strong>Priority:</strong> ${data.report.priority}</div>
                                    <div><strong>Location:</strong> ${data.report.location}</div>
                                    ${data.report.latitude && data.report.longitude && data.report.latitude != 0 && data.report.longitude != 0 ? `<div><a href="https://www.openstreetmap.org/?mlat=${data.report.latitude}&mlon=${data.report.longitude}&zoom=15" target="_blank" class="btn-map" style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:#e8f4f8;color:#1e3c72;border-radius:6px;text-decoration:none;font-size:13px;font-weight:500;margin-top:4px;"><i class="fas fa-map-marker-alt"></i> View on Map</a></div>` : ''}
                                </div>
                                <div style="margin-bottom: 20px;">
                                    <strong>Description:</strong>
                                    <p style="margin-top: 5px;">${data.report.description}</p>
                                </div>
                                ${(() => {
                                    let html = '';
                                    const pics = [];
                                    const seenPaths = new Set();
                                    if (data.report.image_path && data.report.image_path !== '0' && data.report.image_path !== 'null') {
                                        const p = '../../' + data.report.image_path;
                                        pics.push(p);
                                        seenPaths.add(data.report.image_path);
                                    }
                                    if (data.report.attachments) {
                                        let atts = data.report.attachments;
                                        if (typeof atts === 'string') { try { atts = JSON.parse(atts); } catch(e) { atts = []; } }
                                        if (Array.isArray(atts)) {
                                            atts.forEach(a => {
                                                const p = a.file_path || a.file || '';
                                                if (p && !seenPaths.has(p)) {
                                                    pics.push('../../' + p);
                                                    seenPaths.add(p);
                                                }
                                            });
                                        }
                                    }
                                    if (data.report.update_media && Array.isArray(data.report.update_media)) {
                                        data.report.update_media.forEach(m => {
                                            const p = m.file_path || '';
                                            if (p && !seenPaths.has(p) && m.file_type !== 'video') {
                                                pics.push('../../' + p);
                                                seenPaths.add(p);
                                            }
                                        });
                                    }
                                    if (pics.length) {
                                        html += '<div style="margin-bottom:20px;"><strong>Photos:</strong><div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;">';
                                        pics.forEach((p, i) => {
                                            html += '<div style="width:100px;height:100px;border-radius:8px;overflow:hidden;border:2px solid #e2e8f0;">' +
                                                '<img src="' + p + '" alt="Photo ' + (i+1) + '" style="width:100%;height:100%;object-fit:cover;cursor:pointer;" ' +
                                                'onclick="window.open(\'' + p + '\',\'_blank\')" onerror="imgFallback(this)"></div>';
                                        });
                                        html += '</div></div>';
                                    }
                                    return html;
                                })()}
                                ${data.report.assigned_to ? `<div style="margin-bottom: 10px;"><strong>Assigned To:</strong> ${data.report.assigned_to}</div>` : ''}
                                ${data.report.notes ? `<div style="margin-bottom: 10px;"><strong>Notes:</strong> ${data.report.notes}</div>` : ''}
                                ${data.report.reporter_name ? `<div style="margin-bottom: 10px;"><strong>Reporter:</strong> ${data.report.reporter_name}</div>` : ''}
                                <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee; font-size: 12px; color: #666;">
                                    <div><strong>Created:</strong> ${data.report.created_at || 'N/A'}</div>
                                    <div><strong>Updated:</strong> ${data.report.updated_at || 'Not updated'}</div>
                                    ${data.report.approved_at ? `<div style="color: #28a745;"><strong>Approved:</strong> ${data.report.approved_at}</div>` : ''}
                                    ${data.report.rejected_at ? `<div style="color: #dc3545;"><strong>Rejected:</strong> ${data.report.rejected_at}</div>` : ''}
                                </div>
                            </div>
                        `;
                        openModal('viewReportModal');
                    } else {
                        showNotification('Failed to load report details', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Error loading report details', 'error');
                });
        }

        function viewReportUpdates(id, type) {
            currentUpdatesReportId = id;
            currentUpdatesReportType = type;
            document.getElementById('updateReportInfo').textContent = 'Report #' + id;
            openModal('updatesModal');
            loadUpdates(id, type);
        }

        function editReport(id, type) {
            fetch(`../api/get_report_details.php?id=${id}&type=${type}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('editReportId').value = data.report.id;
                        document.getElementById('editReportType').value = type;
                        document.getElementById('editReportTypeFromDB').value = data.report.report_type;
                        document.getElementById('editStatus').value = data.report.status;
                        document.getElementById('editPriority').value = data.report.priority;
                        document.getElementById('editTitle').value = data.report.title || '';
                        document.getElementById('editDescription').value = data.report.description || '';
                        document.getElementById('editLocation').value = data.report.location || '';
                        
                        document.getElementById('editNotes').value = data.report.notes || '';
                        
                        // Show existing photos
                        const container = document.getElementById('existingPhotos');
                        container.innerHTML = '';
                        let hasPhotos = false;
                        
                        if (data.report.image_path && data.report.image_path !== '0' && data.report.image_path !== 'null') {
                            const imgUrl = '../../' + data.report.image_path;
                            container.innerHTML += `
                                <div style="position:relative;width:100px;height:100px;border-radius:8px;overflow:hidden;border:2px solid #e2e8f0;">
                                    <img src="${imgUrl}" alt="Report photo" style="width:100%;height:100%;object-fit:cover;cursor:pointer;" 
                                         onclick="window.open('${imgUrl}','_blank')"
                                         onerror="imgFallback(this)">
                                    <div style="position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,0.6);font-size:10px;color:white;text-align:center;padding:2px;">Current</div>
                                </div>`;
                            hasPhotos = true;
                        }
                        
                        if (data.report.attachments) {
                            let attachments = data.report.attachments;
                            if (typeof attachments === 'string') {
                                try { attachments = JSON.parse(attachments); } catch(e) { attachments = []; }
                            }
                            if (Array.isArray(attachments)) {
                                attachments.forEach((att, idx) => {
                                    const raw = att.file_path || att.file || '';
                                    const path = raw ? '../../' + raw : '';
                                    if (path) {
                                        container.innerHTML += `
                                            <div style="position:relative;width:100px;height:100px;border-radius:8px;overflow:hidden;border:2px solid #e2e8f0;">
                                                <img src="${path}" alt="Attachment ${idx+1}" style="width:100%;height:100%;object-fit:cover;cursor:pointer;" 
                                                     onclick="window.open('${path}','_blank')"
                                                     onerror="imgFallback(this)">
                                            </div>`;
                                        hasPhotos = true;
                                    }
                                });
                            }
                        }
                        
                        if (!hasPhotos) {
                            container.innerHTML = '<div style="color:#6b7280;font-size:13px;padding:8px 0;"><i class="fas fa-camera"></i> No photos yet</div>';
                        }
                        
                        // Clear photo preview
                        editSelectedFiles = [];
                        document.getElementById('photoPreview').innerHTML = '';
                        document.getElementById('editPhotos').value = '';
                        
                        // Update status indicator
                        document.getElementById('updateStatusIndicator').textContent = 
                            'Last updated: ' + (data.report.updated_at || 'N/A');
                        
                        openModal('editReportModal');
                    } else {
                        showNotification('Failed to load report details', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Error loading report details', 'error');
                });
        }

        function deleteReport(id, type) {
            if (confirm('Are you sure you want to delete this report? It will be moved to the archive.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="delete_report">
                    <input type="hidden" name="report_id" value="${id}">
                    <input type="hidden" name="report_type" value="${type}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function refreshReceivedReports() {
            loadExternalReports();
            loadDepartmentReports();
        }

        function loadExternalReports() {
            // Simulate loading reports from external systems
            const externalReports = [
                {
                    id: 'EXT-001',
                    title: 'Traffic Accident on Highway 1',
                    source: 'Traffic Management System',
                    priority: 'high',
                    status: 'pending',
                    received_at: '2024-02-24 10:30:00',
                    description: 'Multi-vehicle accident reported on Highway 1 near KM 45'
                },
                {
                    id: 'EXT-002', 
                    title: 'Road Damage Report',
                    source: 'Citizen Reporting App',
                    priority: 'medium',
                    status: 'pending',
                    received_at: '2024-02-24 09:15:00',
                    description: 'Large pothole reported on Main Street causing traffic disruption'
                }
            ];

            const container = document.getElementById('externalReportsList');
            if (externalReports.length === 0) {
                container.innerHTML = `
                    <div style="text-align: center; padding: 20px; color: #666;">
                        <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                        <p>No reports received from external systems</p>
                    </div>
                `;
            } else {
                container.innerHTML = externalReports.map(report => `
                    <div class="report-card" style="margin-bottom: 15px; border-left: 4px solid ${getPriorityColor(report.priority)};">
                        <div style="display: flex; justify-content: between; align-items: flex-start; margin-bottom: 10px;">
                            <div style="flex: 1;">
                                <div style="font-weight: 600; color: #1e3c72; margin-bottom: 5px;">${report.title}</div>
                                <div style="font-size: 12px; color: #666;">
                                    <i class="fas fa-download"></i> ${report.source} • 
                                    <i class="fas fa-clock"></i> ${report.received_at} • 
                                    <i class="fas fa-flag"></i> Priority: ${report.priority}
                                </div>
                            </div>
                            <span class="status-badge status-${report.status.replace('_', '-')}">${report.status}</span>
                        </div>
                        <div style="color: #333; font-size: 14px; margin-bottom: 10px;">${report.description}</div>
                        <div style="display: flex; gap: 10px;">
                            <button class="btn-action btn-view" onclick="acceptExternalReport('${report.id}')">
                                <i class="fas fa-check"></i> Accept
                            </button>
                            <button class="btn-action btn-edit" onclick="reviewExternalReport('${report.id}')">
                                <i class="fas fa-eye"></i> Review
                            </button>
                        </div>
                    </div>
                `).join('');
            }
        }

        function loadDepartmentReports() {
            // Simulate loading reports from other departments
            const departmentReports = [
                {
                    id: 'DEPT-001',
                    title: 'Bridge Inspection Required',
                    source: 'Engineering Department',
                    priority: 'high',
                    status: 'pending',
                    received_at: '2024-02-24 11:00:00',
                    description: 'Quarterly bridge inspection scheduled for City Bridge #3'
                },
                {
                    id: 'DEPT-002',
                    title: 'Street Light Maintenance',
                    source: 'Public Works Department',
                    priority: 'low',
                    status: 'pending',
                    received_at: '2024-02-24 08:45:00',
                    description: 'Routine maintenance request for street lights in District 2'
                }
            ];

            const container = document.getElementById('departmentReportsList');
            if (departmentReports.length === 0) {
                container.innerHTML = `
                    <div style="text-align: center; padding: 20px; color: #666;">
                        <i class="fas fa-building" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                        <p>No reports received from other departments</p>
                    </div>
                `;
            } else {
                container.innerHTML = departmentReports.map(report => `
                    <div class="report-card" style="margin-bottom: 15px; border-left: 4px solid ${getPriorityColor(report.priority)};">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                            <div style="flex: 1;">
                                <div style="font-weight: 600; color: #1e3c72; margin-bottom: 5px;">${report.title}</div>
                                <div style="font-size: 12px; color: #666;">
                                    <i class="fas fa-building"></i> ${report.source} • 
                                    <i class="fas fa-clock"></i> ${report.received_at} • 
                                    <i class="fas fa-flag"></i> Priority: ${report.priority}
                                </div>
                            </div>
                            <span class="status-badge status-${report.status.replace('_', '-')}">${report.status}</span>
                        </div>
                        <div style="color: #333; font-size: 14px; margin-bottom: 10px;">${report.description}</div>
                        <div style="display: flex; gap: 10px;">
                            <button class="btn-action btn-view" onclick="acceptDepartmentReport('${report.id}')">
                                <i class="fas fa-check"></i> Accept
                            </button>
                            <button class="btn-action btn-edit" onclick="reviewDepartmentReport('${report.id}')">
                                <i class="fas fa-eye"></i> Review
                            </button>
                        </div>
                    </div>
                `).join('');
            }
        }

        function getPriorityColor(priority) {
            switch(priority) {
                case 'high': return '#dc3545';
                case 'medium': return '#ffc107';
                case 'low': return '#28a745';
                default: return '#6c757d';
            }
        }

        function acceptExternalReport(reportId) {
            if (confirm('Accept this external report and add it to the system?')) {
                // Find the report data
                const externalReports = [
                    {
                        id: 'EXT-001',
                        title: 'Traffic Accident on Highway 1',
                        source: 'Traffic Management System',
                        priority: 'high',
                        status: 'pending',
                        received_at: '2024-02-24 10:30:00',
                        description: 'Multi-vehicle accident reported on Highway 1 near KM 45',
                        location: 'Highway 1, KM 45',
                        report_type: 'transportation'
                    },
                    {
                        id: 'EXT-002', 
                        title: 'Road Damage Report',
                        source: 'Citizen Reporting App',
                        priority: 'medium',
                        status: 'pending',
                        received_at: '2024-02-24 09:15:00',
                        description: 'Large pothole reported on Main Street causing traffic disruption',
                        location: 'Main Street',
                        report_type: 'maintenance'
                    }
                ];
                
                const report = externalReports.find(r => r.id === reportId);
                if (report) {
                    // Submit the accepted report to the server
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="accept_external_report">
                        <input type="hidden" name="report_id" value="${report.id}">
                        <input type="hidden" name="title" value="${report.title}">
                        <input type="hidden" name="description" value="${report.description}">
                        <input type="hidden" name="location" value="${report.location}">
                        <input type="hidden" name="priority" value="${report.priority}">
                        <input type="hidden" name="report_type" value="${report.report_type}">
                        <input type="hidden" name="source" value="${report.source}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            }
        }

        function acceptDepartmentReport(reportId) {
            if (confirm('Accept this department report and add it to the system?')) {
                // Find the report data
                const departmentReports = [
                    {
                        id: 'DEPT-001',
                        title: 'Bridge Inspection Required',
                        source: 'Engineering Department',
                        priority: 'high',
                        status: 'pending',
                        received_at: '2024-02-24 11:00:00',
                        description: 'Quarterly bridge inspection scheduled for City Bridge #3',
                        location: 'City Bridge #3',
                        report_type: 'maintenance'
                    },
                    {
                        id: 'DEPT-002',
                        title: 'Street Light Maintenance',
                        source: 'Public Works Department',
                        priority: 'low',
                        status: 'pending',
                        received_at: '2024-02-24 08:45:00',
                        description: 'Routine maintenance request for street lights in District 2',
                        location: 'District 2',
                        report_type: 'maintenance'
                    }
                ];
                
                const report = departmentReports.find(r => r.id === reportId);
                if (report) {
                    // Submit the accepted report to the server
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="accept_department_report">
                        <input type="hidden" name="report_id" value="${report.id}">
                        <input type="hidden" name="title" value="${report.title}">
                        <input type="hidden" name="description" value="${report.description}">
                        <input type="hidden" name="location" value="${report.location}">
                        <input type="hidden" name="priority" value="${report.priority}">
                        <input type="hidden" name="report_type" value="${report.report_type}">
                        <input type="hidden" name="source" value="${report.source}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            }
        }

        function reviewExternalReport(reportId) {
            showNotification('Opening external report details...', 'info');
            // In a real implementation, this would open a detailed view modal
        }

        function reviewDepartmentReport(reportId) {
            showNotification('Opening department report details...', 'info');
            // In a real implementation, this would open a detailed view modal
        }

        function showNotification(message, type = 'info') {
            // Create notification element
            const notification = document.createElement('div');
            notification.className = `alert alert-${type}`;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 10001;
                min-width: 300px;
                padding: 15px 20px;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                animation: slideIn 0.3s ease;
            `;
            
            // Set styles based on type
            switch(type) {
                case 'success':
                    notification.style.background = 'rgba(40, 167, 69, 0.9)';
                    notification.style.color = 'white';
                    notification.style.borderLeft = '4px solid #28a745';
                    break;
                case 'error':
                    notification.style.background = 'rgba(220, 53, 69, 0.9)';
                    notification.style.color = 'white';
                    notification.style.borderLeft = '4px solid #dc3545';
                    break;
                case 'info':
                    notification.style.background = 'rgba(23, 162, 184, 0.9)';
                    notification.style.color = 'white';
                    notification.style.borderLeft = '4px solid #17a2b8';
                    break;
                default:
                    notification.style.background = 'rgba(108, 117, 125, 0.9)';
                    notification.style.color = 'white';
                    notification.style.borderLeft = '4px solid #6c757d';
            }
            
            notification.innerHTML = `
                ${message}
                <button type="button" style="background: none; border: none; color: white; float: right; font-size: 16px; cursor: pointer;" onclick="this.parentElement.remove()">&times;</button>
            `;
            
            document.body.appendChild(notification);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.remove();
                }
            }, 5000);
        }

        // Auto-load reports when modal opens
        const originalOpenModal = openModal;
        openModal = function(modalId) {
            originalOpenModal(modalId);
            if (modalId === 'receivedReportsModal') {
                setTimeout(() => {
                    loadExternalReports();
                    loadDepartmentReports();
                }, 100);
            }
        }

        // Photo preview on file select with add button and per-image delete
        const editPhotosInput = document.getElementById('editPhotos');
        const photoPreview = document.getElementById('photoPreview');
        const addEditPhotosBtn = document.getElementById('add-edit-photos-btn');
        let editSelectedFiles = [];
        
        addEditPhotosBtn.addEventListener('click', function() {
            editPhotosInput.click();
        });
        
        function renderEditGallery() {
            photoPreview.innerHTML = '';
            if (editSelectedFiles.length === 0) return;
            editSelectedFiles.forEach((file, index) => {
                const reader = new FileReader();
                reader.onload = function(ev) {
                    const wrapper = document.createElement('div');
                    wrapper.style.position = 'relative';
                    wrapper.style.width = '90px';
                    wrapper.style.height = '90px';
                    wrapper.style.borderRadius = '8px';
                    wrapper.style.overflow = 'hidden';
                    wrapper.style.border = '2px solid #3762c8';
                    const img = document.createElement('img');
                    img.src = ev.target.result;
                    img.style.width = '100%';
                    img.style.height = '100%';
                    img.style.objectFit = 'cover';
                    wrapper.appendChild(img);
                    const del = document.createElement('button');
                    del.type = 'button';
                    del.innerHTML = '&times;';
                    del.style.position = 'absolute';
                    del.style.top = '-6px';
                    del.style.right = '-6px';
                    del.style.width = '22px';
                    del.style.height = '22px';
                    del.style.borderRadius = '50%';
                    del.style.border = 'none';
                    del.style.background = '#dc3545';
                    del.style.color = 'white';
                    del.style.fontSize = '14px';
                    del.style.lineHeight = '22px';
                    del.style.textAlign = 'center';
                    del.style.cursor = 'pointer';
                    del.style.padding = '0';
                    del.addEventListener('click', function(ev2) {
                        ev2.stopPropagation();
                        editSelectedFiles.splice(index, 1);
                        renderEditGallery();
                    });
                    wrapper.appendChild(del);
                    const label = document.createElement('div');
                    label.style.position = 'absolute';
                    label.style.bottom = '0';
                    label.style.left = '0';
                    label.style.right = '0';
                    label.style.background = 'rgba(55,98,200,0.8)';
                    label.style.fontSize = '10px';
                    label.style.color = 'white';
                    label.style.textAlign = 'center';
                    label.style.padding = '2px';
                    label.textContent = 'New';
                    wrapper.appendChild(label);
                    photoPreview.appendChild(wrapper);
                };
                reader.readAsDataURL(file);
            });
        }
        
        editPhotosInput.addEventListener('change', function() {
            const newFiles = Array.from(this.files);
            newFiles.forEach(file => {
                if (file.size > 5 * 1024 * 1024) {
                    showNotification(`"${file.name}" exceeds 5MB limit.`, 'error');
                } else {
                    editSelectedFiles.push(file);
                }
            });
            renderEditGallery();
            this.value = '';
        });

        // Handle edit report form submission
        document.getElementById('editReportForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const dt = new DataTransfer();
            editSelectedFiles.forEach(f => dt.items.add(f));
            editPhotosInput.files = dt.files;
            
            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            const indicator = document.getElementById('updateStatusIndicator');
            
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitBtn.disabled = true;
            indicator.textContent = 'Saving changes...';
            
            fetch('', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;

                if (data.success) {
                    let msg = 'Report updated successfully';
                    if (data.photos_added > 0) {
                        msg += ` (${data.photos_added} photo${data.photos_added > 1 ? 's' : ''} added)`;
                    }
                    showNotification(msg, 'success');
                    closeModal('editReportModal');
                    indicator.textContent = 'Changes saved. Loading report view...';

                    const updatedReportId = document.getElementById('editReportId').value;
                    const updatedReportType = document.getElementById('editReportType').value;

                    setTimeout(() => {
                        viewReport(parseInt(updatedReportId), updatedReportType);
                    }, 500);
                } else {
                    showNotification(data.message || 'Failed to update report', 'error');
                    indicator.textContent = 'Failed to save changes';
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error updating report', 'error');
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
                indicator.textContent = 'Error saving changes';
            });
        });

        // Scroll to specific report if id param is in URL
        const urlParams = new URLSearchParams(window.location.search);
        const focusId = urlParams.get('id');
        if (focusId) {
            setTimeout(() => {
                const card = document.querySelector(`.report-card[data-id="${focusId}"]`);
                if (card) {
                    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    card.style.boxShadow = '0 0 0 3px #3762c8, 0 8px 32px rgba(55,98,200,0.3)';
                    setTimeout(() => {
                        card.style.boxShadow = '';
                    }, 3000);
                }
            }, 500);
        }

        // ── Approved Citizen Transport Search ──
        const citizenTransportInput = document.getElementById('citizenTransportSearchInput');
        if (citizenTransportInput) {
            citizenTransportInput.addEventListener('keyup', function() {
                const q = this.value.toLowerCase();
                document.querySelectorAll('#citizenTransportPanel .report-card').forEach(function(card) {
                    card.style.display = card.textContent.toLowerCase().includes(q) ? '' : 'none';
                });
            });
        }
    </script>
    

    <!-- Session Timeout Modal -->
    <div id="sessionTimeoutOverlay" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.6); z-index:10000;"></div>
    <div id="sessionTimeoutModal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; border-radius:12px; padding:32px; z-index:10001; width:400px; max-width:90vw; box-shadow:0 16px 48px rgba(0,0,0,0.3); text-align:center;">
        <div style="font-size:48px; color:#e74c3c; margin-bottom:16px;">
            <i class="fas fa-clock"></i>
        </div>
        <h3 style="margin:0 0 8px; font-size:20px; color:#1a1a2e;">Session Expiring</h3>
        <p style="margin:0 0 20px; color:#666; font-size:14px;">
            Your session will expire in <strong><span id="sessionCountdown">60</span></strong> seconds due to inactivity.
        </p>
        <div style="display:flex; gap:12px; justify-content:center;">
            <button id="extendSessionBtn" style="padding:10px 24px; background:#3762c8; color:#fff; border:none; border-radius:8px; font-size:14px; cursor:pointer; font-weight:600;">Extend Session</button>
            <button id="logoutSessionBtn" style="padding:10px 24px; background:#e74c3c; color:#fff; border:none; border-radius:8px; font-size:14px; cursor:pointer; font-weight:600;">Log Out</button>
        </div>
    </div>

    <!-- Session timeout data -->
    <script id="sessionTimeoutData" data-timeout="<?php echo $session_timeout; ?>"></script>
    <script src="../../js/session-timeout.js"></script>
</body>
</html>
