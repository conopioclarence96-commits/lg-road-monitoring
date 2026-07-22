<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../../pages/api/cimm_verification_data.php';

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
            ob_start();
            handle_delete_report();
            ob_end_clean();
            header('Location: report_management.php');
            exit();
            break;
        case 'accept_external_report':
            handle_accept_external_report();
            break;
        case 'accept_department_report':
            handle_accept_department_report();
            break;
        case 'update_cimm_report':
            handle_update_cimm_report();
            break;
        case 'delete_cimm_report':
            ob_start();
            handle_delete_cimm_report();
            ob_end_clean();
            header('Location: report_management.php');
            exit();
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
    $transport_types = ['transportation', 'infrastructure_issue', 'traffic_jam', 'accident', 'road_closure', 'potholes', 'road_damage'];
    $table = in_array($report_type, $transport_types) ? 'road_transportation_reports' : 'road_maintenance_reports';
    
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
    $transport_types = ['transportation', 'infrastructure_issue', 'traffic_jam', 'accident', 'road_closure', 'potholes', 'road_damage'];
    $table = in_array($report_type, $transport_types) ? 'road_transportation_reports' : 'road_maintenance_reports';
    $stmt = $conn->prepare("SELECT title, location FROM {$table} WHERE id = ?");
    $stmt->bind_param("i", $report_id);
    $stmt->execute();
    $report_info = $stmt->get_result()->fetch_assoc();
    
    // Archive the report first
    if ($table === 'road_transportation_reports') {
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
        set_flash_message('success', 'Report deleted successfully and moved to archive.');
    } else {
        set_flash_message('error', 'Failed to archive report: ' . $conn->error);
    }
}

function handle_update_cimm_report() {
    global $conn, $user_id;

    $report_id = intval($_POST['report_id'] ?? 0);
    $status = sanitize_input($_POST['status'] ?? '');
    $priority = sanitize_input($_POST['priority'] ?? '');
    $notes = sanitize_input($_POST['notes'] ?? '');
    $assigned_to = sanitize_input($_POST['assigned_to'] ?? '');
    $estimation = floatval($_POST['estimation'] ?? 0);

    if ($report_id <= 0) {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid report ID']);
            exit;
        }
        set_flash_message('error', 'Invalid report ID');
        return;
    }

    $statusMap = [
        'pending'     => 'Pending',
        'approved'    => 'Approved',
        'in-progress' => 'In Progress',
        'completed'   => 'Completed',
        'cancelled'   => 'Cancelled',
    ];
    $verification_status = $statusMap[$status] ?? 'Pending';

    $update_fields = "verification_status = ?, verification_note = ?, verified_by = ?";
    $params = [$verification_status, $notes, $user_id];
    $types = "ssi";

    if (!empty($assigned_to)) {
        $update_fields .= ", cprf_facility_name = ?";
        $params[] = $assigned_to;
        $types .= "s";
    }

    $update_fields .= ", priority = ?";
    $params[] = $priority;
    $types .= "s";

    if ($estimation > 0) {
        $update_fields .= ", budget = ?";
        $params[] = $estimation;
        $types .= "d";
    }

    $params[] = $report_id;
    $types .= "i";

    $query = "UPDATE cimm_verification_reports SET {$update_fields} WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);

    if ($stmt->execute()) {
        log_audit_action($user_id, "Updated CIMM report", "Report ID: {$report_id}, Status: {$verification_status}");

        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'CIMM report updated successfully']);
            exit;
        }
        set_flash_message('success', 'CIMM report updated successfully');
    } else {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Failed to update CIMM report: ' . $conn->error]);
            exit;
        }
        set_flash_message('error', 'Failed to update CIMM report: ' . $conn->error);
    }
}

function handle_delete_cimm_report() {
    global $conn, $user_id;

    $report_id = intval($_POST['report_id'] ?? 0);

    if ($report_id <= 0) {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid report ID']);
            exit;
        }
        set_flash_message('error', 'Invalid report ID');
        return;
    }

    $info = fetch_one("SELECT reference_code, infrastructure FROM cimm_verification_reports WHERE id = ?", [$report_id], "i");

    $stmt = $conn->prepare("DELETE FROM cimm_verification_reports WHERE id = ?");
    $stmt->bind_param("i", $report_id);

    if ($stmt->execute()) {
        $label = $info ? ($info['reference_code'] ?? $info['infrastructure'] ?? 'Unknown') : 'Unknown';
        log_audit_action($user_id, "Deleted CIMM report", "Report ID: {$report_id}, Label: {$label}");

        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'CIMM report deleted successfully']);
            exit;
        }
        set_flash_message('success', 'CIMM report deleted successfully');
    } else {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Failed to delete CIMM report: ' . $conn->error]);
            exit;
        }
        set_flash_message('error', 'Failed to delete CIMM report: ' . $conn->error);
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

// Map a CIMM verification report row into the flat report format used by report_management
function mapCimmToReportManagement(array $row): array {
    $verification = $row['verification_status'] ?? 'Pending Review';
    $statusMap = [
        'Pending Review' => 'pending',
        'Flagged'        => 'in-progress',
        'Verified'       => 'completed',
        'Dismissed'      => 'cancelled',
        'Pending'        => 'pending',
        'Approved'       => 'approved',
        'In Progress'    => 'in-progress',
        'Completed'      => 'completed',
        'Cancelled'      => 'cancelled',
    ];

    return [
        'id'            => $row['id'] ?? $row['cimm_req_id'] ?? 0,
        'report_id'     => $row['reference_code'] ?? ('REQ-' . ($row['cimm_req_id'] ?? '')),
        'title'         => $row['infrastructure'] ?? 'CIMM Report',
        'description'   => $row['issue'] ?? '',
        'location'      => $row['location'] ?? '',
        'latitude'      => $row['coord_lat'] ?? null,
        'longitude'     => $row['coord_lng'] ?? null,
        'priority'      => strtolower((string)($row['priority'] ?? 'medium')),
        'status'        => $statusMap[$verification] ?? 'pending',
        'assigned_to'   => $row['cprf_facility_name'] ?? null,
        'estimation'    => $row['budget'] ?? 0,
        'notes'         => $row['issue'] ?? '',
        'department'    => 'cimm',
        'created_date'  => $row['starting_date'] ?? date('Y-m-d'),
        'created_at'    => $row['submitted_at'] ?? $row['created_at'] ?? date('Y-m-d H:i:s'),
        'updated_at'    => $row['verified_at'] ?? $row['synced_at'] ?? null,
        'attachments'   => null,
        'image_path'    => null,
        'report_type'   => 'infrastructure_issue',
        'source_system' => 'cimm',
    ];
}

// Get CIMM reports for report management display
function getCimmReportsForManagement($status_filter = 'all') {
    $pdo = rgmap_verification_pdo();

    $opts = ['limit' => 500, 'status' => 'Verified'];
    $rows = rgmap_fetch_cimm_verification_reports($pdo, $opts);

    $mapped = array_map('mapCimmToReportManagement', $rows);

    if ($status_filter !== 'all') {
        $mapped = array_values(array_filter($mapped, function ($r) use ($status_filter) {
            return $r['status'] === $status_filter;
        }));
    }

    return $mapped;
}

// Get reports for display
function get_reports($status_filter = 'all', $source_filter = 'all', $limit = 50, $offset = 0) {
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
    
    // Get transportation reports (Citizen Reports + Infrastructure Issues from transport table)
    if ($transport_estimation_exists) {
        $transport_query = "SELECT id, report_id, title, description, location, latitude, longitude, priority, status, assigned_to, estimation, resolution_notes as notes, department, created_date, created_at, updated_at, approved_at, attachments, image_path, report_type, CASE WHEN report_type = 'infrastructure_issue' THEN 'maintenance' ELSE 'transport' END as source_system FROM road_transportation_reports";
    } else {
        $transport_query = "SELECT id, report_id, title, description, location, latitude, longitude, priority, status, assigned_to, 0 as estimation, resolution_notes as notes, department, created_date, created_at, updated_at, approved_at, attachments, image_path, report_type, CASE WHEN report_type = 'infrastructure_issue' THEN 'maintenance' ELSE 'transport' END as source_system FROM road_transportation_reports";
    }
    $transport_params = [];
    
    // Get maintenance reports (Infrastructure Projects)
    if ($maintenance_estimation_exists) {
        $maintenance_query = "SELECT id, report_id, title, description, location, priority, status, maintenance_team as assigned_to, estimation, department, created_date, created_at, updated_at, approved_at, NULL as attachments, NULL as image_path, 'maintenance' as report_type, 'maintenance' as source_system FROM road_maintenance_reports";
    } else {
        $maintenance_query = "SELECT id, report_id, title, description, location, priority, status, maintenance_team as assigned_to, 0 as estimation, department, created_date, created_at, updated_at, approved_at, NULL as attachments, NULL as image_path, 'maintenance' as report_type, 'maintenance' as source_system FROM road_maintenance_reports";
    }
    $maintenance_params = [];
    
    // Apply filters
    $where_conditions = [];
    $params = [];
    
    $status_filter = $_GET['status'] ?? 'all';
    $source_filter = $_GET['source'] ?? 'all';
    
    if ($status_filter !== 'all') {
        $where_conditions[] = "status = ?";
        $params[] = $status_filter;
    }
    
    $include_cimm = ($source_filter === 'all' || $source_filter === 'cimm');
    $include_transport = ($source_filter === 'all' || $source_filter === 'transport');
    $include_maintenance = ($source_filter === 'all' || $source_filter === 'maintenance');
    
    if (!$include_transport && !$include_maintenance) {
        $transport_query = "SELECT NULL FROM road_transportation_reports WHERE 1=0";
    } elseif (!$include_transport && $include_maintenance) {
        // When only maintenance is selected, include infrastructure issues from transport table
        $transport_query .= " WHERE report_type = 'infrastructure_issue'";
        if (!empty($where_conditions)) {
            $transport_query .= " AND " . implode(' AND ', $where_conditions);
        }
        $transport_params = $params ?? [];
    } elseif ($include_transport && !$include_maintenance) {
        // When only transport is selected, exclude infrastructure issues (they belong in infra panel)
        $transport_query .= " WHERE report_type != 'infrastructure_issue'";
        if (!empty($where_conditions)) {
            $transport_query .= " AND " . implode(' AND ', $where_conditions);
        }
        $transport_params = $params ?? [];
    } elseif (!empty($where_conditions)) {
        $transport_query .= " WHERE " . implode(' AND ', $where_conditions);
        $transport_params = $params;
    }
    
    if (!$include_maintenance) {
        $maintenance_query = "SELECT NULL FROM road_maintenance_reports WHERE 1=0";
    } elseif (!empty($where_conditions)) {
        $maintenance_query .= " WHERE " . implode(' AND ', $where_conditions);
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
    } elseif ($include_transport || $include_maintenance) {
        $transport_reports = fetch_all($transport_query);
    } else {
        $transport_reports = [];
    }
    
    if (!empty($maintenance_params)) {
        $stmt = $conn->prepare($maintenance_query);
        $stmt->bind_param(str_repeat('s', count($maintenance_params)), ...$maintenance_params);
        $stmt->execute();
        $maintenance_reports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } elseif ($include_maintenance) {
        $maintenance_reports = fetch_all($maintenance_query);
    } else {
        $maintenance_reports = [];
    }
    
    // Get CIMM reports if needed
    $cimm_reports = [];
    if ($include_cimm) {
        $cimm_reports = getCimmReportsForManagement($status_filter);
    }
    
    // Combine and sort
    $all_reports = array_merge($transport_reports ?: [], $maintenance_reports ?: [], $cimm_reports ?: []);
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
        'approved_reports' => 0,
        'completed_reports' => 0,
        'high_priority_reports' => 0
    ];
    
    // Transportation stats
    $transport_stats = fetch_one("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'in-progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN priority = 'high' THEN 1 ELSE 0 END) as high_priority_count
        FROM road_transportation_reports");
    
    // Maintenance stats
    $maintenance_stats = fetch_one("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'in-progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN priority = 'high' THEN 1 ELSE 0 END) as high_priority_count
        FROM road_maintenance_reports");
    
    if ($transport_stats) {
        $stats['total_reports'] += $transport_stats['total'];
        $stats['pending_reports'] += $transport_stats['pending'];
        $stats['in_progress_reports'] += $transport_stats['in_progress'];
        $stats['approved_reports'] += $transport_stats['approved'];
        $stats['completed_reports'] += $transport_stats['completed'];
        $stats['high_priority_reports'] += $transport_stats['high_priority_count'];
    }
    
    if ($maintenance_stats) {
        $stats['total_reports'] += $maintenance_stats['total'];
        $stats['pending_reports'] += $maintenance_stats['pending'];
        $stats['in_progress_reports'] += $maintenance_stats['in_progress'];
        $stats['approved_reports'] += $maintenance_stats['approved'];
        $stats['completed_reports'] += $maintenance_stats['completed'];
        $stats['high_priority_reports'] += $maintenance_stats['high_priority_count'];
    }
    
    // CIMM stats
    try {
        $cimm_all = getCimmReportsForManagement();
        $stats['total_reports'] += count($cimm_all);
        foreach ($cimm_all as $cimm_report) {
            if ($cimm_report['status'] === 'pending') $stats['pending_reports']++;
            elseif ($cimm_report['status'] === 'in-progress') $stats['in_progress_reports']++;
            elseif ($cimm_report['status'] === 'completed') {
                $stats['completed_reports']++;
                $stats['approved_reports']++;
            }
            if (($cimm_report['priority'] ?? '') === 'high') $stats['high_priority_reports']++;
        }
    } catch (Exception $e) {
        error_log("CIMM stats fetch failed: " . $e->getMessage());
    }
    
    return $stats;
}

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Filters
$status_filter = $_GET['status'] ?? 'all';
$source_filter = $_GET['source'] ?? 'all';

// Get data
$reports = get_reports($status_filter, $source_filter, $per_page, $offset);
$stats = get_report_stats();
$csrf_token = generate_csrf_token();
$flash_message = get_flash_message();

// Separate reports by source system for panel display
$citizen_reports = [];
$cimm_reports_list = [];
$infra_reports_list = [];
foreach ($reports as $report) {
    $src = $report['source_system'] ?? 'transport';
    if ($src === 'cimm') {
        $cimm_reports_list[] = $report;
    } elseif ($src === 'maintenance') {
        $infra_reports_list[] = $report;
    } else {
        $citizen_reports[] = $report;
    }
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

        .source-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .source-badge.source-transport {
            background: rgba(55, 98, 200, 0.12);
            color: #3762c8;
        }

        .source-badge.source-maintenance {
            background: rgba(23, 162, 184, 0.12);
            color: #17a2b8;
        }

        .source-badge.source-cimm {
            background: rgba(249, 115, 22, 0.12);
            color: #f97316;
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
        body.dark-mode .source-badge.source-transport {
            background: rgba(96, 165, 250, 0.15);
            color: #60a5fa;
        }
        body.dark-mode .source-badge.source-maintenance {
            background: rgba(45, 212, 191, 0.15);
            color: #2dd4bf;
        }
        body.dark-mode .source-badge.source-cimm {
            background: rgba(251, 146, 60, 0.15);
            color: #fb923c;
        }

        /* Section Panel Wrappers */
        .section-panel {
            background: #f0f4fa;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid #e0e0e0;
            margin-bottom: 25px;
            overflow: hidden;
        }

        body.dark-mode .section-panel {
            background: #1e2229;
            border-color: #2d323b;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        /* Report Panel Styles (shared) */
        .rm-panel {
            background: #f0f4fa;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid #e0e0e0;
            margin-bottom: 25px;
            overflow: hidden;
        }

        body.dark-mode .rm-panel {
            background: #1e2229;
            border-color: #2d323b;
        }

        .rm-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            border-bottom: 2px solid rgba(55, 98, 200, 0.1);
        }

        .rm-panel-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .rm-panel-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }

        .rm-panel-icon.citizen {
            background: linear-gradient(135deg, #3762c8, #1e3c72);
        }

        .rm-panel-icon.cimm {
            background: linear-gradient(135deg, #f97316, #ea580c);
        }

        .rm-panel-icon.infra {
            background: linear-gradient(135deg, #17a2b8, #138496);
        }

        .rm-panel-title {
            font-size: 20px;
            font-weight: 700;
            color: #1e3c72;
            margin: 0;
        }

        body.dark-mode .rm-panel-title {
            color: #f0f4fa;
        }

        .rm-panel-title-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .rm-panel-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: white;
        }

        .rm-panel-badge.citizen { background: #3762c8; }
        .rm-panel-badge.cimm { background: #f97316; }
        .rm-panel-badge.infra { background: #17a2b8; }

        .rm-panel-subtitle {
            font-size: 13px;
            color: #6b7280;
            margin: 2px 0 0 0;
        }

        body.dark-mode .rm-panel-subtitle {
            color: #9ca3af;
        }

        .rm-panel-search {
            display: flex;
            gap: 12px;
            padding: 18px 25px;
            border-bottom: 1px solid rgba(55, 98, 200, 0.08);
        }

        .rm-search-wrapper {
            position: relative;
            flex: 1;
        }

        .rm-search-wrapper i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
            font-size: 14px;
        }

        .rm-search-input {
            width: 100%;
            padding: 11px 16px 11px 40px;
            background: #ffffff;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            color: #333;
            font-size: 13px;
            font-family: 'Poppins', sans-serif;
            outline: none;
            transition: border-color 0.3s;
        }

        body.dark-mode .rm-search-input {
            background: #2d323b;
            border-color: rgba(255,255,255,0.1);
            color: #e4e6ea;
        }

        .rm-search-input::placeholder {
            color: #9ca3af;
        }

        .rm-search-input:focus {
            border-color: #3762c8;
            box-shadow: 0 0 0 3px rgba(55, 98, 200, 0.1);
        }

        .rm-sort-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 10px 20px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .rm-sort-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(55, 98, 200, 0.3);
        }

        .rm-table-wrapper {
            overflow-x: auto;
        }

        .rm-table {
            width: 100%;
            border-collapse: collapse;
        }

        .rm-table thead th {
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: white;
            padding: 14px 16px;
            font-size: 12px;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .rm-table thead th:first-child { border-radius: 0; }
        .rm-table thead th:last-child { border-radius: 0; }

        .rm-table tbody tr {
            border-bottom: 1px solid rgba(55, 98, 200, 0.08);
            transition: background 0.2s;
        }

        .rm-table tbody tr:hover {
            background: rgba(55, 98, 200, 0.05);
        }

        .rm-table tbody td {
            padding: 14px 16px;
            color: #333;
            font-size: 13px;
            white-space: nowrap;
        }

        body.dark-mode .rm-table tbody td { color: #c0c8d8; }
        body.dark-mode .rm-table tbody tr { border-bottom-color: rgba(255,255,255,0.05); }
        body.dark-mode .rm-table tbody tr:hover { background: rgba(55, 98, 200, 0.08); }

        .rm-action-btn {
            padding: 5px 10px;
            background: rgba(55, 98, 200, 0.1);
            color: #3762c8;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .rm-action-btn:hover {
            background: rgba(55, 98, 200, 0.2);
        }

        body.dark-mode .rm-action-btn {
            background: rgba(55, 98, 200, 0.15);
            color: #60a5fa;
        }

        .rm-status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .rm-status-badge.pending { background: rgba(251, 191, 36, 0.15); color: #f59e0b; }
        .rm-status-badge.in-progress { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
        .rm-status-badge.completed, .rm-status-badge.approved, .rm-status-badge.resolved { background: rgba(34, 197, 94, 0.15); color: #22c55e; }
        .rm-status-badge.cancelled { background: rgba(220, 53, 69, 0.15); color: #ef4444; }

        .rm-priority-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .rm-priority-badge.high { background: rgba(220, 53, 69, 0.15); color: #ef4444; }
        .rm-priority-badge.medium { background: rgba(251, 191, 36, 0.15); color: #f59e0b; }
        .rm-priority-badge.low { background: rgba(34, 197, 94, 0.15); color: #22c55e; }

        .rm-empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }

        .rm-empty-icon {
            width: 56px;
            height: 56px;
            background: rgba(55, 98, 200, 0.12);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }

        .rm-empty-icon i {
            font-size: 26px;
            color: #3762c8;
        }

        body.dark-mode .rm-empty-icon { background: rgba(96, 165, 250, 0.12); }
        body.dark-mode .rm-empty-icon i { color: #60a5fa; }
        .rm-empty-state h4 { font-size: 16px; font-weight: 600; color: #333; margin-bottom: 6px; }
        body.dark-mode .rm-empty-state h4 { color: #e4e6ea; }
        .rm-empty-state p { font-size: 14px; color: #9ca3af; font-weight: 500; }

        .rm-action-group {
            display: inline-flex;
            gap: 4px;
            align-items: center;
        }

        .rm-edit-btn {
            padding: 5px 10px;
            background: rgba(251, 191, 36, 0.1);
            color: #f59e0b;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .rm-edit-btn:hover { background: rgba(251, 191, 36, 0.2); }

        .rm-delete-btn {
            padding: 5px 10px;
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .rm-delete-btn:hover { background: rgba(220, 53, 69, 0.2); }

        @media (max-width: 768px) {
            .rm-panel-header { flex-direction: column; align-items: flex-start; gap: 12px; }
            .rm-panel-search { flex-direction: column; }
        }

        .delete-confirm-overlay {
            display: none;
            position: fixed;
            z-index: 10002;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
        }

        .delete-confirm-box {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            border-radius: 14px;
            padding: 0;
            width: 420px;
            max-width: 92vw;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35);
            animation: modalSlideIn 0.25s ease;
            overflow: hidden;
        }

        .dark-mode .delete-confirm-box { background: #1e293b; }

        .delete-confirm-header {
            background: linear-gradient(135deg, #dc3545, #a71d2a);
            color: white;
            padding: 18px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .delete-confirm-header i {
            font-size: 22px;
        }

        .delete-confirm-header h3 {
            margin: 0;
            font-size: 17px;
            font-weight: 700;
        }

        .delete-confirm-body {
            padding: 24px;
            text-align: center;
        }

        .delete-confirm-body p {
            margin: 0 0 6px;
            color: #495057;
            font-size: 14px;
        }

        .dark-mode .delete-confirm-body p { color: #cbd5e1; }

        .delete-confirm-body .delete-warning {
            color: #dc3545;
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 16px;
        }

        .delete-confirm-body .delete-type-label {
            font-size: 12px;
            font-weight: 600;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            text-align: left;
        }

        .dark-mode .delete-confirm-body .delete-type-label { color: #94a3b8; }

        .delete-confirm-body .delete-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 700;
            text-align: center;
            letter-spacing: 3px;
            outline: none;
            transition: border-color 0.2s;
            box-sizing: border-box;
            text-transform: uppercase;
        }

        .dark-mode .delete-confirm-body .delete-input {
            background: #0f172a;
            color: #f1f5f9;
            border-color: #334155;
        }

        .delete-confirm-body .delete-input:focus {
            border-color: #dc3545;
        }

        .delete-confirm-body .delete-input.valid {
            border-color: #28a745;
            background: #f0fff4;
        }

        .dark-mode .delete-confirm-body .delete-input.valid {
            background: #0d2818;
        }

        .delete-confirm-footer {
            padding: 0 24px 24px;
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }

        .delete-confirm-footer button {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .delete-confirm-footer .btn-cancel {
            background: #e9ecef;
            color: #495057;
        }

        .dark-mode .delete-confirm-footer .btn-cancel {
            background: #334155;
            color: #cbd5e1;
        }

        .delete-confirm-footer .btn-cancel:hover {
            background: #dee2e6;
        }

        .delete-confirm-footer .btn-confirm-delete {
            background: #dc3545;
            color: white;
            opacity: 0.4;
            pointer-events: none;
        }

        .delete-confirm-footer .btn-confirm-delete.enabled {
            opacity: 1;
            pointer-events: auto;
        }

        .delete-confirm-footer .btn-confirm-delete.enabled:hover {
            background: #a71d2a;
        }
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
                <div class="stat-icon" style="background: linear-gradient(135deg, #22c55e, #16a34a);">
                    <i class="fas fa-cogs"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['in_progress_reports']); ?></div>
                <div class="stat-label">In Progress</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #059669, #047857);">
                    <i class="fas fa-check-double"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['approved_reports']); ?></div>
                <div class="stat-label">Approved</div>
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
                    <label class="form-label">Source System</label>
                    <select class="filter-select" id="sourceFilter" onchange="filterReports()">
                        <option value="all" <?php echo $source_filter === 'all' ? 'selected' : ''; ?>>All Sources</option>
                        <option value="transport" <?php echo $source_filter === 'transport' ? 'selected' : ''; ?>>Citizen Reports</option>
                        <option value="cimm" <?php echo $source_filter === 'cimm' ? 'selected' : ''; ?>>CIMM Reports</option>
                        <option value="maintenance" <?php echo $source_filter === 'maintenance' ? 'selected' : ''; ?>>Infrastructure Projects</option>
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

        <!-- Citizen Reports Panel -->
        <div class="rm-panel" id="citizenReportsPanel">
            <div class="rm-panel-header">
                <div class="rm-panel-header-left">
                    <div class="rm-panel-icon citizen">
                        <i class="fas fa-users"></i>
                    </div>
                    <div>
                        <div class="rm-panel-title-group">
                            <h2 class="rm-panel-title">Citizen Reports</h2>
                            <span class="rm-panel-badge citizen"><?php echo count($citizen_reports); ?> Reports</span>
                        </div>
                        <p class="rm-panel-subtitle">Reports submitted by citizens via the road monitoring system</p>
                    </div>
                </div>
            </div>

            <div class="rm-panel-search">
                <div class="rm-search-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" class="rm-search-input" id="citizenSearchInput" placeholder="Search by Report #, Title, Type, Location, Department...">
                </div>
                <button class="rm-sort-btn" onclick="toggleCitizenSort()">
                    <i class="fas fa-sort"></i> Sort
                </button>
            </div>

            <div class="rm-table-wrapper">
                <table class="rm-table" id="citizenTable">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Report #</th>
                            <th>Title</th>
                            <th>Type</th>
                            <th>Location</th>
                            <th>Department</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $hasCitizen = false;
                        if (!empty($citizen_reports)):
                            foreach ($citizen_reports as $report):
                                $hasCitizen = true;
                        ?>
                        <tr data-id="<?php echo (int)$report['id']; ?>">
                            <td>
                                <div class="rm-action-group">
                                    <button class="rm-action-btn" onclick="viewReport(<?php echo (int)$report['id']; ?>, '<?php echo htmlspecialchars($report['report_type'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="rm-edit-btn" onclick="editReport(<?php echo (int)$report['id']; ?>, '<?php echo htmlspecialchars($report['report_type'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-pencil"></i>
                                    </button>
                                    <button class="rm-delete-btn" onclick="deleteReport(<?php echo (int)$report['id']; ?>, '<?php echo htmlspecialchars($report['report_type'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <button class="rm-action-btn" style="background:rgba(16,185,129,0.1);color:#10b981;" onclick="viewReportUpdates(<?php echo (int)$report['id']; ?>, '<?php echo htmlspecialchars($report['report_type'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-clock"></i>
                                    </button>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($report['report_id'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars(strlen($report['title'] ?? '') > 35 ? substr($report['title'], 0, 35) . '...' : ($report['title'] ?? '')); ?></td>
                            <td><?php
                                $type_labels = [
                                    'infrastructure_issue' => 'Infrastructure Issue',
                                    'traffic_jam' => 'Traffic Jam',
                                    'accident' => 'Vehicle Accident',
                                    'road_closure' => 'Road Closure',
                                    'potholes' => 'Potholes',
                                    'road_damage' => 'Road Damage',
                                ];
                                echo htmlspecialchars($type_labels[$report['report_type']] ?? ucfirst($report['report_type']));
                            ?></td>
                            <td><?php echo htmlspecialchars($report['location'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars(ucfirst($report['department'] ?? '')); ?></td>
                            <td><span class="rm-priority-badge <?php echo htmlspecialchars($report['priority']); ?>"><?php echo ucfirst(htmlspecialchars($report['priority'])); ?></span></td>
                            <td><span class="rm-status-badge <?php echo htmlspecialchars($report['status']); ?>"><?php echo ucfirst(htmlspecialchars(str_replace('-', ' ', $report['status']))); ?></span></td>
                            <td>
                                <?php echo $report['created_at'] ? date('M d, Y', strtotime($report['created_at'])) : '—'; ?>
                                <?php if (($report['status'] ?? '') === 'approved' && !empty($report['approved_at'])): ?>
                                    <br><small style="color:#059669;font-weight:600;">Approved: <?php echo date('M d, Y', strtotime($report['approved_at'])); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php
                            endforeach;
                        endif;
                        ?>

                        <?php if (!$hasCitizen): ?>
                        <tr>
                            <td colspan="9">
                                <div class="rm-empty-state">
                                    <div class="rm-empty-icon">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <h4>No Citizen Reports</h4>
                                    <p>No citizen-submitted reports found.</p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- CIMM Reports Panel -->
        <div class="rm-panel" id="cimmReportsPanel">
            <div class="rm-panel-header" style="border-bottom-color: rgba(249, 115, 22, 0.15);">
                <div class="rm-panel-header-left">
                    <div class="rm-panel-icon cimm">
                        <i class="fas fa-building"></i>
                    </div>
                    <div>
                        <div class="rm-panel-title-group">
                            <h2 class="rm-panel-title" style="color: #c2410c;">CIMM Reports</h2>
                            <span class="rm-panel-badge cimm"><?php echo count($cimm_reports_list); ?> Reports</span>
                        </div>
                        <p class="rm-panel-subtitle" style="color: #92400e;">External reports from the CIMM system — managed via Verification Monitoring</p>
                    </div>
                </div>
            </div>

            <div class="rm-panel-search" style="border-bottom-color: rgba(249, 115, 22, 0.08);">
                <div class="rm-search-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" class="rm-search-input" id="cimmSearchInput" placeholder="Search by Rep #, Infrastructure, Location, Engineer, Priority...">
                </div>
                <button class="rm-sort-btn" style="background: linear-gradient(135deg, #f97316, #ea580c);" onclick="toggleCimmSort()">
                    <i class="fas fa-sort"></i> Sort
                </button>
            </div>

            <div class="rm-table-wrapper">
                <table class="rm-table" id="cimmTable" style="--thead-bg: linear-gradient(135deg, #f97316, #ea580c);">
                    <thead>
                        <tr>
                            <th style="background: linear-gradient(135deg, #f97316, #ea580c);">Action</th>
                            <th style="background: linear-gradient(135deg, #f97316, #ea580c);">Rep #</th>
                            <th style="background: linear-gradient(135deg, #f97316, #ea580c);">Infrastructure</th>
                            <th style="background: linear-gradient(135deg, #f97316, #ea580c);">Location</th>
                            <th style="background: linear-gradient(135deg, #f97316, #ea580c);">Issue / Notes</th>
                            <th style="background: linear-gradient(135deg, #f97316, #ea580c);">Engineer</th>
                            <th style="background: linear-gradient(135deg, #f97316, #ea580c);">Priority</th>
                            <th style="background: linear-gradient(135deg, #f97316, #ea580c);">Budget</th>
                            <th style="background: linear-gradient(135deg, #f97316, #ea580c);">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $hasCimm = false;
                        $cimmIdx = 0;
                        if (!empty($cimm_reports_list)):
                            foreach ($cimm_reports_list as $row):
                                $hasCimm = true;
                        ?>
                        <tr>
                            <td>
                                <div class="rm-action-group">
                                    <button class="rm-action-btn" onclick="viewCimmReport(<?php echo $cimmIdx; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="rm-edit-btn" onclick="editCimmReport(<?php echo $cimmIdx; ?>)">
                                        <i class="fas fa-pencil"></i>
                                    </button>
                                    <button class="rm-delete-btn" onclick="deleteCimmReport(<?php echo $cimmIdx; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($row['report_id'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($row['title'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($row['location'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars(strlen($row['description'] ?? '') > 40 ? substr($row['description'], 0, 40) . '...' : ($row['description'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars($row['assigned_to'] ?? '—'); ?></td>
                            <td><span class="rm-priority-badge <?php echo htmlspecialchars($row['priority']); ?>"><?php echo ucfirst(htmlspecialchars($row['priority'])); ?></span></td>
                            <td><?php echo !empty($row['estimation']) ? '₱' . number_format($row['estimation'], 2) : '—'; ?></td>
                            <td><span class="rm-status-badge <?php echo htmlspecialchars($row['status']); ?>"><?php echo ucfirst(htmlspecialchars(str_replace('-', ' ', $row['status']))); ?></span></td>
                        </tr>
                        <?php
                            $cimmIdx++;
                            endforeach;
                        endif;
                        ?>

                        <?php if (!$hasCimm): ?>
                        <tr>
                            <td colspan="9">
                                <div class="rm-empty-state">
                                    <div class="rm-empty-icon" style="background: rgba(249, 115, 22, 0.12);">
                                        <i class="fas fa-building" style="color: #f97316;"></i>
                                    </div>
                                    <h4>No CIMM Reports</h4>
                                    <p>No reports from the CIMM system found.</p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Infrastructure Projects Panel -->
        <div class="rm-panel" id="infraReportsPanel">
            <div class="rm-panel-header" style="border-bottom-color: rgba(23, 162, 184, 0.15);">
                <div class="rm-panel-header-left">
                    <div class="rm-panel-icon infra">
                        <i class="fas fa-hard-hat"></i>
                    </div>
                    <div>
                        <div class="rm-panel-title-group">
                            <h2 class="rm-panel-title" style="color: #138496;">Infrastructure Projects</h2>
                            <span class="rm-panel-badge infra"><?php echo count($infra_reports_list); ?> Projects</span>
                        </div>
                        <p class="rm-panel-subtitle" style="color: #0c5460;">Infrastructure maintenance and project records</p>
                    </div>
                </div>
            </div>

            <div class="rm-panel-search" style="border-bottom-color: rgba(23, 162, 184, 0.08);">
                <div class="rm-search-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" class="rm-search-input" id="infraSearchInput" placeholder="Search by Report #, Title, Type, Location, Department...">
                </div>
                <button class="rm-sort-btn" style="background: linear-gradient(135deg, #17a2b8, #138496);" onclick="toggleInfraSort()">
                    <i class="fas fa-sort"></i> Sort
                </button>
            </div>

            <div class="rm-table-wrapper">
                <table class="rm-table" id="infraTable">
                    <thead>
                        <tr>
                            <th style="background: linear-gradient(135deg, #17a2b8, #138496);">Action</th>
                            <th style="background: linear-gradient(135deg, #17a2b8, #138496);">Report #</th>
                            <th style="background: linear-gradient(135deg, #17a2b8, #138496);">Title</th>
                            <th style="background: linear-gradient(135deg, #17a2b8, #138496);">Type</th>
                            <th style="background: linear-gradient(135deg, #17a2b8, #138496);">Location</th>
                            <th style="background: linear-gradient(135deg, #17a2b8, #138496);">Department</th>
                            <th style="background: linear-gradient(135deg, #17a2b8, #138496);">Priority</th>
                            <th style="background: linear-gradient(135deg, #17a2b8, #138496);">Status</th>
                            <th style="background: linear-gradient(135deg, #17a2b8, #138496);">Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $hasInfra = false;
                        if (!empty($infra_reports_list)):
                            foreach ($infra_reports_list as $report):
                                $hasInfra = true;
                        ?>
                        <tr data-id="<?php echo (int)$report['id']; ?>">
                            <td>
                                <div class="rm-action-group">
                                    <button class="rm-action-btn" onclick="viewReport(<?php echo (int)$report['id']; ?>, '<?php echo htmlspecialchars($report['report_type'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="rm-edit-btn" onclick="editReport(<?php echo (int)$report['id']; ?>, '<?php echo htmlspecialchars($report['report_type'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-pencil"></i>
                                    </button>
                                    <button class="rm-delete-btn" onclick="deleteReport(<?php echo (int)$report['id']; ?>, '<?php echo htmlspecialchars($report['report_type'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <button class="rm-action-btn" style="background:rgba(16,185,129,0.1);color:#10b981;" onclick="viewReportUpdates(<?php echo (int)$report['id']; ?>, '<?php echo htmlspecialchars($report['report_type'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-clock"></i>
                                    </button>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($report['report_id'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars(strlen($report['title'] ?? '') > 35 ? substr($report['title'], 0, 35) . '...' : ($report['title'] ?? '')); ?></td>
                            <td><?php
                                $type_labels = [
                                    'infrastructure_issue' => 'Infrastructure Issue',
                                    'routine' => 'Routine Maintenance',
                                    'emergency' => 'Emergency Repair',
                                    'preventive' => 'Preventive Maintenance',
                                    'corrective' => 'Corrective Maintenance',
                                    'scheduled' => 'Scheduled Maintenance',
                                ];
                                echo htmlspecialchars($type_labels[$report['report_type']] ?? ucfirst($report['report_type']));
                            ?></td>
                            <td><?php echo htmlspecialchars($report['location'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars(ucfirst($report['department'] ?? '')); ?></td>
                            <td><span class="rm-priority-badge <?php echo htmlspecialchars($report['priority']); ?>"><?php echo ucfirst(htmlspecialchars($report['priority'])); ?></span></td>
                            <td><span class="rm-status-badge <?php echo htmlspecialchars($report['status']); ?>"><?php echo ucfirst(htmlspecialchars(str_replace('-', ' ', $report['status']))); ?></span></td>
                            <td>
                                <?php echo $report['created_at'] ? date('M d, Y', strtotime($report['created_at'])) : '—'; ?>
                                <?php if (($report['status'] ?? '') === 'approved' && !empty($report['approved_at'])): ?>
                                    <br><small style="color:#059669;font-weight:600;">Approved: <?php echo date('M d, Y', strtotime($report['approved_at'])); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php
                            endforeach;
                        endif;
                        ?>

                        <?php if (!$hasInfra): ?>
                        <tr>
                            <td colspan="9">
                                <div class="rm-empty-state">
                                    <div class="rm-empty-icon" style="background: rgba(23, 162, 184, 0.12);">
                                        <i class="fas fa-hard-hat" style="color: #17a2b8;"></i>
                                    </div>
                                    <h4>No Infrastructure Projects</h4>
                                    <p>No infrastructure projects found.</p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
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

    <!-- CIMM Edit Report Modal -->
    <div id="editCimmModal" class="modal">
        <div class="modal-content" style="max-width: 650px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #f97316, #ea580c); color: white;">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Edit CIMM Report</h5>
                <button class="close" onclick="closeModal('editCimmModal')" style="color: white;">&times;</button>
            </div>
            <form method="POST" id="editCimmForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="update_cimm_report">
                    <input type="hidden" name="report_id" id="editCimmReportId">

                    <div class="form-section">
                        <h6><i class="fas fa-info-circle"></i> CIMM Report Details</h6>
                        <div class="form-group">
                            <label class="form-label">Report #</label>
                            <input type="text" class="form-control" id="editCimmRepNumber" readonly style="background: #f3f4f6;">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Infrastructure</label>
                            <input type="text" class="form-control" id="editCimmInfrastructure" readonly style="background: #f3f4f6;">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Location</label>
                            <input type="text" class="form-control" id="editCimmLocation" readonly style="background: #f3f4f6;">
                        </div>
                    </div>

                    <div class="form-section">
                        <h6><i class="fas fa-tasks"></i> Editable Fields</h6>
                        <div style="display: flex; gap: 15px;">
                            <div class="form-group" style="flex: 1;">
                                <label class="form-label">Status *</label>
                                <select class="form-control" name="status" id="editCimmStatus" required>
                                    <option value="pending">Pending</option>
                                    <option value="approved">Approved</option>
                                    <option value="in-progress">In Progress</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label class="form-label">Priority *</label>
                                <select class="form-control" name="priority" id="editCimmPriority" required>
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                    <option value="critical">Critical</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Engineer / Facility</label>
                            <input type="text" class="form-control" name="assigned_to" id="editCimmAssignedTo" placeholder="Assigned engineer or facility">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Budget (₱)</label>
                            <input type="number" class="form-control" name="estimation" id="editCimmEstimation" step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>

                    <div class="form-section">
                        <h6><i class="fas fa-sticky-note"></i> Notes</h6>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Verification Notes</label>
                            <textarea class="form-control" name="notes" id="editCimmNotes" rows="3" placeholder="Add verification notes or comments..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="justify-content: space-between;">
                    <span id="cimmEditIndicator" style="font-size: 12px; color: #666;"></span>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="btn-secondary-custom" onclick="closeModal('editCimmModal')">Cancel</button>
                        <button type="submit" class="btn-primary-custom" id="cimmEditSubmitBtn">
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
        // CIMM data for detail viewing (read-only)
        const cimmData = <?php echo json_encode(array_values($cimm_reports_list), JSON_HEX_TAG | JSON_HEX_AMP); ?>;

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        document.getElementById('deleteConfirmInput').addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                cancelDeleteConfirm();
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (document.getElementById('deleteConfirmBtn').classList.contains('enabled')) {
                    confirmDeleteAction();
                }
            }
        });

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }

        function validateDeleteInput() {
            var input = document.getElementById('deleteConfirmInput');
            var btn = document.getElementById('deleteConfirmBtn');
            if (input.value.toUpperCase() === 'DELETE') {
                input.classList.add('valid');
                btn.classList.add('enabled');
            } else {
                input.classList.remove('valid');
                btn.classList.remove('enabled');
            }
        }

        function cancelDeleteConfirm() {
            document.getElementById('deleteConfirmOverlay').style.display = 'none';
            document.getElementById('deleteConfirmInput').value = '';
            document.getElementById('deleteConfirmInput').classList.remove('valid');
            document.getElementById('deleteConfirmBtn').classList.remove('enabled');
            _pendingDeleteReport = null;
            _pendingDeleteType = null;
            _pendingDeleteCimmIdx = null;
        }

        function confirmDeleteAction() {
            if (_pendingDeleteReport !== null) {
                var id = _pendingDeleteReport;
                var type = _pendingDeleteType;
                cancelDeleteConfirm();
                var form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML =
                    '<input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">' +
                    '<input type="hidden" name="action" value="delete_report">' +
                    '<input type="hidden" name="report_id" value="' + id + '">' +
                    '<input type="hidden" name="report_type" value="' + type + '">';
                document.body.appendChild(form);
                form.submit();
            } else if (_pendingDeleteCimmIdx !== null) {
                var idx = _pendingDeleteCimmIdx;
                var r = cimmData[idx];
                cancelDeleteConfirm();
                if (!r) return;
                var form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML =
                    '<input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">' +
                    '<input type="hidden" name="action" value="delete_cimm_report">' +
                    '<input type="hidden" name="report_id" value="' + r.id + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }

        function filterReports() {
            const status = document.getElementById('statusFilter').value;
            const source = document.getElementById('sourceFilter').value;
            const url = new URL(window.location);
            url.searchParams.set('status', status);
            url.searchParams.set('source', source);
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        }

        function resetFilters() {
            const url = new URL(window.location);
            url.searchParams.delete('status');
            url.searchParams.delete('source');
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        }

        function exportReports() {
            const status = document.getElementById('statusFilter').value;
            const source = document.getElementById('sourceFilter').value;
            const url = `../api/export_reports.php?status=${status}&source=${source}`;
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

        var _pendingDeleteReport = null;
        var _pendingDeleteType = null;

        function deleteReport(id, type) {
            _pendingDeleteReport = id;
            _pendingDeleteType = type;
            _pendingDeleteCimmIdx = null;
            document.getElementById('deleteConfirmTitle').textContent = 'Delete Report';
            document.getElementById('deleteConfirmMsg').textContent = 'Are you sure you want to delete this report? It will be moved to the archive.';
            document.getElementById('deleteConfirmInput').value = '';
            document.getElementById('deleteConfirmInput').classList.remove('valid');
            document.getElementById('deleteConfirmBtn').classList.remove('enabled');
            document.getElementById('deleteConfirmOverlay').style.display = 'block';
            setTimeout(function() { document.getElementById('deleteConfirmInput').focus(); }, 100);
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

                    setTimeout(() => {
                        window.location.href = '../shared/road_transportation_monitoring.php';
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

        // Panel search filtering — client-side within each panel
        function panelSearch(inputId, tableId) {
            const query = document.getElementById(inputId).value.toLowerCase();
            const tbody = document.querySelector('#' + tableId + ' tbody');
            if (!tbody) return;
            const rows = tbody.querySelectorAll('tr');
            rows.forEach(row => {
                if (row.querySelector('.rm-empty-state')) return;
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        }

        document.getElementById('citizenSearchInput').addEventListener('input', function() { panelSearch('citizenSearchInput', 'citizenTable'); });
        document.getElementById('cimmSearchInput').addEventListener('input', function() { panelSearch('cimmSearchInput', 'cimmTable'); });
        document.getElementById('infraSearchInput').addEventListener('input', function() { panelSearch('infraSearchInput', 'infraTable'); });

        // Sort toggle state
        const sortState = { citizen: 'asc', cimm: 'asc', infra: 'asc' };

        function toggleSort(tableId, key) {
            const tbody = document.querySelector('#' + tableId + ' tbody');
            if (!tbody) return;
            const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => !r.querySelector('.rm-empty-state'));
            sortState[key] = sortState[key] === 'asc' ? 'desc' : 'asc';
            const dir = sortState[key] === 'asc' ? 1 : -1;
            rows.sort((a, b) => {
                const aText = a.cells[key === 'cimm' ? 0 : 1].textContent.trim().toLowerCase();
                const bText = b.cells[key === 'cimm' ? 0 : 1].textContent.trim().toLowerCase();
                return aText.localeCompare(bText) * dir;
            });
            rows.forEach(row => tbody.appendChild(row));
        }

        function toggleCitizenSort() { toggleSort('citizenTable', 'citizen'); }
        function toggleCimmSort()   { toggleSort('cimmTable', 'cimm'); }
        function toggleInfraSort()   { toggleSort('infraTable', 'infra'); }

        // CIMM detail viewer (read-only)
        function viewCimmReport(idx) {
            var r = cimmData[idx];
            if (!r) return;
            var statusLabels = { 'pending': 'Pending Review', 'in-progress': 'Flagged', 'completed': 'Verified', 'cancelled': 'Dismissed' };
            var priorityColors = { 'high': '#ef4444', 'medium': '#f59e0b', 'low': '#22c55e', 'critical': '#dc2626' };
            var content = document.getElementById('viewReportContent');
            content.innerHTML = '' +
                '<div style="line-height:1.6;">' +
                    '<h6 style="color:#c2410c;margin-bottom:15px;">CIMM Report — ' + (r.report_id || '—') + '</h6>' +
                    '<div style="background:rgba(249,115,22,0.08);border:1px solid rgba(249,115,22,0.2);border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:12px;color:#92400e;">' +
                        '<i class="fas fa-info-circle"></i> This report originates from the CIMM system and is managed via Verification Monitoring.' +
                    '</div>' +
                    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px;">' +
                        '<div><strong>Report #</strong><br>' + (r.report_id || '—') + '</div>' +
                        '<div><strong>Infrastructure</strong><br>' + (r.title || '—') + '</div>' +
                        '<div><strong>Location</strong><br>' + (r.location || '—') + '</div>' +
                        '<div><strong>Engineer</strong><br>' + (r.assigned_to || '—') + '</div>' +
                        '<div><strong>Reported By</strong><br>' + (r.reporter_name || '—') + '</div>' +
                        '<div><strong>Start Date</strong><br>' + (r.start_date || '—') + '</div>' +
                        '<div><strong>End Date</strong><br>' + (r.end_date || '—') + '</div>' +
                        '<div><strong>Budget</strong><br>' + (r.estimation ? '₱' + parseFloat(r.estimation).toLocaleString('en-PH', {minimumFractionDigits:2}) : '—') + '</div>' +
                        '<div><strong>Priority</strong><br><span style="display:inline-block;padding:3px 10px;border-radius:4px;font-size:12px;font-weight:600;color:white;background:' + (priorityColors[r.priority] || '#6b7280') + ';">' + (r.priority ? r.priority.charAt(0).toUpperCase() + r.priority.slice(1) : '—') + '</span></div>' +
                        '<div><strong>Status</strong><br><span style="display:inline-block;padding:3px 10px;border-radius:4px;font-size:12px;font-weight:600;color:white;background:' + (r.status === 'completed' ? '#22c55e' : r.status === 'in-progress' ? '#f59e0b' : r.status === 'cancelled' ? '#ef4444' : '#6b7280') + ';">' + (statusLabels[r.status] || r.status || '—') + '</span></div>' +
                    '</div>' +
                    '<div style="margin-bottom:20px;"><strong>Issue / Notes</strong><p style="margin-top:5px;">' + (r.description || '—') + '</p></div>' +
                '</div>';
            document.querySelector('#viewReportModal .modal-title').textContent = 'CIMM Report Details';
            openModal('viewReportModal');
        }

        // CIMM edit
        function editCimmReport(idx) {
            var r = cimmData[idx];
            if (!r) return;
            document.getElementById('editCimmReportId').value = r.id;
            document.getElementById('editCimmRepNumber').value = r.report_id || '';
            document.getElementById('editCimmInfrastructure').value = r.title || '';
            document.getElementById('editCimmLocation').value = r.location || '';
            document.getElementById('editCimmStatus').value = r.status || 'pending';
            document.getElementById('editCimmPriority').value = r.priority || 'medium';
            document.getElementById('editCimmAssignedTo').value = r.assigned_to || '';
            document.getElementById('editCimmEstimation').value = r.estimation || '';
            document.getElementById('editCimmNotes').value = r.notes || '';
            document.getElementById('cimmEditIndicator').textContent = '';
            document.getElementById('cimmEditSubmitBtn').disabled = false;
            document.getElementById('cimmEditSubmitBtn').innerHTML = '<i class="fas fa-save"></i> Save Changes';
            openModal('editCimmModal');
        }

        // CIMM delete
        var _pendingDeleteCimmIdx = null;

        function deleteCimmReport(idx) {
            var r = cimmData[idx];
            if (!r) return;
            _pendingDeleteCimmIdx = idx;
            _pendingDeleteReport = null;
            _pendingDeleteType = null;
            document.getElementById('deleteConfirmTitle').textContent = 'Delete CIMM Report';
            document.getElementById('deleteConfirmMsg').textContent = 'Are you sure you want to delete CIMM report "' + (r.report_id || '') + '"? This cannot be undone.';
            document.getElementById('deleteConfirmInput').value = '';
            document.getElementById('deleteConfirmInput').classList.remove('valid');
            document.getElementById('deleteConfirmBtn').classList.remove('enabled');
            document.getElementById('deleteConfirmOverlay').style.display = 'block';
            setTimeout(function() { document.getElementById('deleteConfirmInput').focus(); }, 100);
        }

        // CIMM edit form submission
        document.getElementById('editCimmForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);
            var submitBtn = document.getElementById('cimmEditSubmitBtn');
            var indicator = document.getElementById('cimmEditIndicator');
            var originalHTML = submitBtn.innerHTML;

            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitBtn.disabled = true;
            indicator.textContent = 'Saving changes...';

            fetch('', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                submitBtn.innerHTML = originalHTML;
                submitBtn.disabled = false;
                if (data.success) {
                    showNotification(data.message || 'CIMM report updated successfully', 'success');
                    closeModal('editCimmModal');
                    indicator.textContent = '';
                    setTimeout(function() { window.location.href = '../shared/road_transportation_monitoring.php'; }, 800);
                } else {
                    showNotification(data.message || 'Failed to update CIMM report', 'error');
                    indicator.textContent = 'Failed to save changes';
                }
            })
            .catch(function(err) {
                console.error('Error:', err);
                showNotification('Error updating CIMM report', 'error');
                submitBtn.innerHTML = originalHTML;
                submitBtn.disabled = false;
                indicator.textContent = 'Error saving changes';
            });
        });

        // Source filter — toggle panel visibility
        function filterSource(source) {
            const citizen = document.getElementById('citizenReportsPanel');
            const cimm    = document.getElementById('cimmReportsPanel');
            const infra   = document.getElementById('infraReportsPanel');
            citizen.style.display = (source === 'all' || source === 'transport') ? '' : 'none';
            cimm.style.display    = (source === 'all' || source === 'cimm')      ? '' : 'none';
            infra.style.display   = (source === 'all' || source === 'maintenance') ? '' : 'none';
        }

        // Sync source filter dropdown with panels on page load
        const sourceFilter = document.getElementById('sourceFilter');
        if (sourceFilter) {
            filterSource(sourceFilter.value);
            sourceFilter.addEventListener('change', function() { filterSource(this.value); });
        }

        // Scroll to specific report if id param is in URL
        const urlParams = new URLSearchParams(window.location.search);
        const focusId = urlParams.get('id');
        if (focusId) {
            setTimeout(() => {
                const row = document.querySelector(`tr[data-id="${focusId}"]`);
                if (row) {
                    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    row.style.boxShadow = '0 0 0 3px #3762c8, 0 8px 32px rgba(55,98,200,0.3)';
                    setTimeout(() => { row.style.boxShadow = ''; }, 3000);
                }
            }, 500);
        }
    </script>
    

    <!-- Session Timeout Modal -->
    <!-- Delete Confirmation Modal -->
    <div id="deleteConfirmOverlay" class="delete-confirm-overlay" onclick="cancelDeleteConfirm()">
        <div class="delete-confirm-box" onclick="event.stopPropagation()">
            <div class="delete-confirm-header">
                <i class="fas fa-exclamation-triangle"></i>
                <h3 id="deleteConfirmTitle">Confirm Deletion</h3>
            </div>
            <div class="delete-confirm-body">
                <p id="deleteConfirmMsg">Are you sure you want to delete this report?</p>
                <p class="delete-warning"><i class="fas fa-info-circle"></i> This action cannot be undone.</p>
                <div class="delete-type-label">Type <strong>DELETE</strong> to confirm:</div>
                <input type="text" id="deleteConfirmInput" class="delete-input" placeholder="DELETE" autocomplete="off" oninput="validateDeleteInput()">
            </div>
            <div class="delete-confirm-footer">
                <button class="btn-cancel" onclick="cancelDeleteConfirm()">Cancel</button>
                <button id="deleteConfirmBtn" class="btn-confirm-delete" onclick="confirmDeleteAction()"><i class="fas fa-trash"></i> Delete</button>
            </div>
        </div>
    </div>

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
