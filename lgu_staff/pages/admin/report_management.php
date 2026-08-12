<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../../pages/api/cimm_verification_data.php';

// Session timeout configuration
$session_timeout = 30 * 60; // 30 minutes in seconds

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
$allowed_roles = ['system_admin', 'road_ops_supervisor', 'trans_ops_supervisor'];
if (!is_logged_in() || !in_array($_SESSION['role'] ?? '', $allowed_roles)) {
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
    echo '<div class="t-alert-error t-text-white" style="padding: 20px; text-align: center; border-radius: 8px; margin: 20px;">
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
    echo '<div class="t-alert-warning" style="padding: 15px; text-align: center; border-radius: 8px; margin: 20px;">
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
        case 'archive_report':
            handle_archive_report();
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
            handle_delete_cimm_report();
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

    // Edit / Save Changes is restricted to the Road and Transportation
    // Operations Supervisors (and the system admin).
    if (!in_array($_SESSION['role'] ?? '', ['road_ops_supervisor', 'trans_ops_supervisor', 'system_admin'], true)) {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'You are not authorized to edit reports. Only the Road/Transportation Operations Supervisors may do this.']);
            exit;
        }
        set_flash_message('error', 'You are not authorized to edit reports. Only the Road/Transportation Operations Supervisors may do this.');
        return;
    }

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
    $transport_types = ['potholes', 'road_damage', 'shoulder_damage', 'traffic_jam', 'accident', 'congestion', 'traffic_light_outage', 'vehicle_breakdown', 'traffic_sign_issue', 'transportation', 'infrastructure_issue', 'road_closure', 'parking_violation', 'public_transport_issue'];
    $table = in_array($report_type, $transport_types) ? 'road_transportation_reports' : 'road_maintenance_reports';

    // The edit form also sends the row's source table explicitly (derived from
    // the same query that rendered the row), so honor it whenever it is valid.
    $report_table = sanitize_input($_POST['report_table'] ?? '');
    if (in_array($report_table, ['road_transportation_reports', 'road_maintenance_reports'], true)) {
        $table = $report_table;
    }
    
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

        // Duration tracking & analytics recording on completion
        $analytics_data = null;
        if ($status === 'completed') {
            try {
                $report_row = fetch_one("SELECT created_at, approved_at, priority, department FROM {$table} WHERE id = ?", [$report_id], "i");
                $start_time = !empty($report_row['approved_at']) ? $report_row['approved_at'] : ($report_row['created_at'] ?? null);
                $completed_at = date('Y-m-d H:i:s');
                
                if (!empty($start_time)) {
                    $start_ts = strtotime($start_time);
                    $end_ts = strtotime($completed_at);
                    if ($end_ts > $start_ts) {
                        $duration_seconds = $end_ts - $start_ts;
                        $duration_days = round($duration_seconds / 86400, 2);
                        
                        $upd_comp_stmt = $conn->prepare("UPDATE {$table} SET completed_at = ? WHERE id = ?");
                        $upd_comp_stmt->bind_param("si", $completed_at, $report_id);
                        $upd_comp_stmt->execute();
                        
                        $ins = $conn->prepare("INSERT INTO project_analytics (report_id, report_table, user_id, started_at, completed_at, duration_seconds, duration_days, priority, department) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $ins->bind_param("isissidss",
                            $report_id, $table, $user_id, $start_time, $completed_at,
                            $duration_seconds, $duration_days, $report_row['priority'], $report_row['department']
                        );
                        $ins->execute();
                        
                        $hours = floor($duration_seconds / 3600);
                        $mins = floor(($duration_seconds % 3600) / 60);
                        $analytics_data = [
                            'duration_days' => $duration_days,
                            'duration_hours' => $hours,
                            'duration_minutes' => $mins,
                            'completed_at' => $completed_at
                        ];
                    }
                }
            } catch (Exception $e) {
                error_log("Duration tracking failed for report {$report_id}: " . $e->getMessage());
            }
        }

        // Create a progress update entry so photos and changes appear in the Updates timeline
        $update_title = 'Report Updated';
        $update_desc_parts = [];
        if (!empty($notes)) $update_desc_parts[] = $notes;
        $update_desc_parts[] = "Status: " . ucfirst(str_replace('-', ' ', $status));
        $update_desc_parts[] = "Priority: " . ucfirst($priority);
        if (!empty($analytics_data)) {
            $update_desc_parts[] = "Completed in {$analytics_data['duration_days']} days ({$analytics_data['duration_hours']}h {$analytics_data['duration_minutes']}m)";
        }
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
                'photos_added' => count($uploaded_photos),
                'analytics' => $analytics_data
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

// Ensure the archive table exists and carries every column of BOTH live report
// tables (so a missing table or a schema mismatch never silently skips the
// archive step on delete). Mirroring maintenance columns (e.g. maintenance_team,
// estimation) means rows from either source table can be archived as-is.
function ensure_archive_table() {
    global $conn;

    $conn->query("CREATE TABLE IF NOT EXISTS road_transportation_reports_archive LIKE road_transportation_reports");

    try {
        foreach (['road_transportation_reports', 'road_maintenance_reports'] as $src_table) {
            $arch_cols = [];
            $arch = $conn->query("SHOW COLUMNS FROM road_transportation_reports_archive");
            if ($arch) {
                while ($row = $arch->fetch_assoc()) {
                    $arch_cols[$row['Field']] = true;
                }
            }

            $src = $conn->query("SHOW COLUMNS FROM $src_table");
            if ($src) {
                while ($row = $src->fetch_assoc()) {
                    $field = $row['Field'];
                    if (!isset($arch_cols[$field])) {
                        // Add missing columns as nullable so an explicit
                        // column-list insert from either source table works.
                        $conn->query("ALTER TABLE road_transportation_reports_archive ADD COLUMN `$field` {$row['Type']} NULL");
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log('ensure_archive_table sync warning: ' . $e->getMessage());
    }

    // The archive is cloned from the transport table, whose report_type ENUM
    // does not include the maintenance table's values ('routine', 'emergency',
    // 'preventive', 'corrective', 'scheduled'). Widen it so maintenance rows
    // can be archived without "Data truncated for column 'report_type'".
    try {
        $conn->query("ALTER TABLE road_transportation_reports_archive MODIFY report_type VARCHAR(255) NULL DEFAULT NULL");
    } catch (Exception $e) {
        error_log('ensure_archive_table report_type widen warning: ' . $e->getMessage());
    }

    // Columns used by Restore: the report's status before it was trashed
    // (so it can be brought back with its previous status) and the exact live
    // table it was moved out of (so it lands back in the same module).
    foreach (['previous_status' => "VARCHAR(50) DEFAULT NULL",
              'archived_from' => "VARCHAR(100) DEFAULT NULL"] as $col => $def) {
        $chk = $conn->query("SHOW COLUMNS FROM road_transportation_reports_archive LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE road_transportation_reports_archive ADD COLUMN $col $def");
        }
    }
}

function handle_delete_report() {
    global $conn, $user_id;
    
    try {
        $report_id = intval($_POST['report_id'] ?? 0);
        $report_type = sanitize_input($_POST['report_type'] ?? '');
        
        if ($report_id <= 0 || empty($report_type)) {
            set_flash_message('error', 'Invalid report data');
            return;
        }
        
        $transport_types = ['potholes', 'road_damage', 'shoulder_damage', 'traffic_jam', 'accident', 'congestion', 'traffic_light_outage', 'vehicle_breakdown', 'traffic_sign_issue', 'transportation', 'infrastructure_issue', 'road_closure', 'parking_violation', 'public_transport_issue'];
        $table = in_array($report_type, $transport_types) ? 'road_transportation_reports' : 'road_maintenance_reports';
        $stmt = $conn->prepare("SELECT title, location FROM {$table} WHERE id = ?");
        $stmt->bind_param("i", $report_id);
        $stmt->execute();
        $report_info = $stmt->get_result()->fetch_assoc();
        
        $archived = false;
        try {
            ensure_archive_table();

            // Capture the report's status BEFORE it is soft-deleted so Restore
            // can bring it back with its previous status.
            $pstmt = $conn->prepare("SELECT status FROM {$table} WHERE id = ?");
            $pstmt->bind_param("i", $report_id);
            $pstmt->execute();
            $prev_status = $pstmt->get_result()->fetch_assoc()['status'] ?? null;
            $pstmt->close();

            // The Delete/Trash action soft-deletes: set the report to cancelled
            // first so the archived copy below carries status 'cancelled' (all
            // other columns — including category/source — are preserved).
            $stmt = $conn->prepare("UPDATE {$table} SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("i", $report_id);
            $stmt->execute();

            // Build an explicit column list from the source table so the archive
            // copy works regardless of schema drift (a plain "SELECT *" breaks
            // as soon as the archive gains columns the source lacks).
            $fields = [];
            $col_res = $conn->query("SHOW COLUMNS FROM {$table}");
            if ($col_res) {
                while ($col_row = $col_res->fetch_assoc()) {
                    $fields[] = "`{$col_row['Field']}`";
                }
            }
            if (empty($fields)) {
                throw new Exception("No columns found for table {$table}");
            }
            $cols = implode(', ', $fields);
            $insert = "INSERT INTO road_transportation_reports_archive ({$cols}) SELECT {$cols} FROM {$table} WHERE id = ?";
            $stmt = $conn->prepare($insert);
            $stmt->bind_param("i", $report_id);
            $stmt->execute();
            $archived = true;

            // The archive row keeps the live row's id, so stamp it with the
            // restore metadata right here.
            $ps = $conn->prepare("UPDATE road_transportation_reports_archive SET previous_status = ?, archived_from = ? WHERE id = ?");
            $ps->bind_param("ssi", $prev_status, $table, $report_id);
            $ps->execute();
        } catch (Exception $e) {
            error_log('Archive failed for report ' . $report_id . ': ' . $e->getMessage());
        }
        
        $stmt = $conn->prepare("DELETE FROM {$table} WHERE id = ?");
        $stmt->bind_param("i", $report_id);
        
        if ($stmt->execute()) {
            $report_title = $report_info['title'] ?? 'Unknown Report';
            log_audit_action($user_id, "Deleted {$report_type} report", "Report ID: {$report_id}, Title: {$report_title}");
            $msg = $archived ? 'Report deleted successfully and moved to archive.' : 'Report deleted successfully.';
            set_flash_message('success', $msg);
        } else {
            set_flash_message('error', 'Failed to delete report: ' . $conn->error);
        }
    } catch (Exception $e) {
        error_log('Delete report error: ' . $e->getMessage());
        set_flash_message('error', 'Failed to delete report. Please try again.');
    }
}

function handle_archive_report() {
    global $conn, $user_id;

    // The Archive button on completed transportation reports is only available
    // to the Transportation Operations Supervisor.
    if (($_SESSION['role'] ?? '') !== 'trans_ops_supervisor') {
        set_flash_message('error', 'You are not authorized to archive reports.');
        return;
    }

    $report_id = intval($_POST['report_id'] ?? 0);
    $report_type = sanitize_input($_POST['report_type'] ?? '');

    if ($report_id <= 0 || empty($report_type)) {
        set_flash_message('error', 'Invalid report data');
        return;
    }

    // Only transportation reports are archived from this page.
    $transport_types = ['potholes', 'road_damage', 'shoulder_damage', 'traffic_jam', 'accident', 'congestion', 'traffic_light_outage', 'vehicle_breakdown', 'traffic_sign_issue', 'transportation', 'infrastructure_issue', 'road_closure', 'parking_violation', 'public_transport_issue'];
    if (!in_array($report_type, $transport_types)) {
        set_flash_message('error', 'Only transportation reports can be archived from report management.');
        return;
    }

    $table = 'road_transportation_reports';

    try {
        // Only completed reports can be archived via this button.
        $stmt = $conn->prepare("SELECT title, status FROM {$table} WHERE id = ?");
        $stmt->bind_param("i", $report_id);
        $stmt->execute();
        $report_info = $stmt->get_result()->fetch_assoc();
        if (!$report_info) {
            set_flash_message('error', 'Report not found');
            return;
        }
        if (($report_info['status'] ?? '') !== 'completed') {
            set_flash_message('error', 'Only completed reports can be archived.');
            return;
        }

        ensure_archive_table();

        // Copy the completed report into the archive (keeping its status), then
        // remove it from the live table. Mirrors handle_delete_report's archive
        // insert but preserves 'completed' instead of switching to 'cancelled'.
        $fields = [];
        $col_res = $conn->query("SHOW COLUMNS FROM {$table}");
        if ($col_res) {
            while ($col_row = $col_res->fetch_assoc()) {
                $fields[] = "`{$col_row['Field']}`";
            }
        }
        if (empty($fields)) {
            throw new Exception("No columns found for table {$table}");
        }
        $cols = implode(', ', $fields);
        $insert = "INSERT INTO road_transportation_reports_archive ({$cols}) SELECT {$cols} FROM {$table} WHERE id = ?";
        $stmt = $conn->prepare($insert);
        $stmt->bind_param("i", $report_id);
        $stmt->execute();

        $ps = $conn->prepare("UPDATE road_transportation_reports_archive SET previous_status = 'completed', archived_from = ? WHERE id = ?");
        $ps->bind_param("si", $table, $report_id);
        $ps->execute();

        $stmt = $conn->prepare("DELETE FROM {$table} WHERE id = ?");
        $stmt->bind_param("i", $report_id);

        if ($stmt->execute()) {
            $report_title = $report_info['title'] ?? 'Unknown Report';
            log_audit_action($user_id, "Archived completed report", "Report ID: {$report_id}, Title: {$report_title}");
            set_flash_message('success', 'Report archived successfully.');
        } else {
            set_flash_message('error', 'Failed to archive report: ' . $conn->error);
        }
    } catch (Exception $e) {
        error_log('Archive report error: ' . $e->getMessage());
        set_flash_message('error', 'Failed to archive report. Please try again.');
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
        $update_fields .= ", cprf_facility_name = ?, engineer = ?";
        $params[] = $assigned_to;
        $params[] = $assigned_to;
        $types .= "ss";
    }

    $update_fields .= ", priority = ?";
    $params[] = $priority;
    $types .= "s";

    if ($estimation > 0) {
        $update_fields .= ", budget = ?, budget_allocation = ?";
        $params[] = $estimation;
        $params[] = $estimation;
        $types .= "dd";
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

    try {
        $report_id = intval($_POST['report_id'] ?? 0);

        if ($report_id <= 0) {
            set_flash_message('error', 'Invalid report ID');
            return;
        }

        // The Delete/Trash action soft-deletes: copy the CIMM report into the
        // archive as 'cancelled' (preserving all report data so it can be
        // restored) BEFORE removing it from cimm_verification_reports.
        $row = fetch_one("SELECT * FROM cimm_verification_reports WHERE id = ?", [$report_id], "i");

        $archived = false;
        if ($row) {
            try {
                ensure_archive_table();

                $now = date('Y-m-d H:i:s');
                $reference_code = $row['reference_code'] ?? ('CIMM-' . $report_id);
                $insert_fields = [
                    'report_id'       => $reference_code,
                    'title'           => $row['infrastructure'] ?? 'CIMM Report',
                    'report_type'     => 'infrastructure_issue',
                    'report_category' => 'road',
                    'report_source'   => 'external',
                    'department'      => 'engineering',
                    'priority'        => $row['priority'] ?? 'medium',
                    'status'          => 'cancelled',
                    'previous_status' => $row['verification_status'] ?? null,
                    'archived_from'   => 'cimm_verification_reports',
                    'created_date'    => (!empty($row['submitted_at'])) ? date('Y-m-d', strtotime($row['submitted_at'])) : date('Y-m-d'),
                    'description'     => $row['issue'] ?? '',
                    'location'        => $row['location'] ?? '',
                    'latitude'        => $row['coord_lat'] ?? null,
                    'longitude'       => $row['coord_lng'] ?? null,
                    'created_at'      => $row['submitted_at'] ?? $now,
                    'updated_at'      => $now,
                    'rejected_at'     => $now,
                    'completed_at'    => null,
                    'approved_at'     => null,
                    'engineer'        => $row['engineer'] ?? null,
                    'budget_allocation' => $row['budget_allocation'] ?? null,
                ];

                $fields = array_keys($insert_fields);
                $placeholders = array_fill(0, count($fields), '?');
                $field_list = '`' . implode('`, `', $fields) . '`';
                $insert = "INSERT INTO road_transportation_reports_archive ($field_list) VALUES (" . implode(', ', $placeholders) . ")";
                $stmt = $conn->prepare($insert);
                $stmt->execute(array_values($insert_fields));
                $archived = true;
            } catch (Exception $e) {
                error_log('Archive failed for CIMM report ' . $report_id . ': ' . $e->getMessage());
            }
        }

        $stmt = $conn->prepare("DELETE FROM cimm_verification_reports WHERE id = ?");
        $stmt->bind_param("i", $report_id);

        if ($stmt->execute()) {
            $label = $row ? ($row['reference_code'] ?? $row['infrastructure'] ?? 'Unknown') : 'Unknown';
            log_audit_action($user_id, "Deleted CIMM report", "Report ID: {$report_id}, Label: {$label}");
            $msg = $archived ? 'CIMM report moved to archive as cancelled.' : 'CIMM report deleted.';
            set_flash_message('success', $msg);
        } else {
            set_flash_message('error', 'Failed to delete CIMM report: ' . $conn->error);
        }
    } catch (Exception $e) {
        error_log('Delete CIMM report error: ' . $e->getMessage());
        set_flash_message('error', 'Failed to delete CIMM report. Please try again.');
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

    // Same fix as rgmap_map_cimm_row_for_display() in verification_monitoring.php:
    // 'Pending Review' / 'Flagged' / 'Verified' only describe RGMAO's own
    // admin-review state, not the real repair progress — 'Verified' used to
    // map straight to 'completed' here, so a report showed as Completed the
    // moment it was verified and never changed again. 'Dismissed' and the
    // manual overrides handle_update_cimm_report() (below) writes into this
    // same column are still honored directly; everything else falls back to
    // CIMM's real, continuously-synced resolution_status.
    $localOverrideMap = [
        'Pending'     => 'pending',
        'Approved'    => 'approved',
        'In Progress' => 'in-progress',
        'Completed'   => 'completed',
        'Cancelled'   => 'cancelled',
    ];

    if ($verification === 'Dismissed') {
        $status = 'cancelled';
    } elseif (isset($localOverrideMap[$verification])) {
        $status = $localOverrideMap[$verification];
    } else {
        $status = cimm_resolution_status_to_display($row['resolution_status'] ?? null, $row['approval_status'] ?? null);
    }

    return [
        'id'            => $row['id'] ?? $row['cimm_req_id'] ?? 0,
        'report_id'     => $row['reference_code'] ?? ('REQ-' . ($row['cimm_req_id'] ?? '')),
        'title'         => $row['infrastructure'] ?? 'CIMM Report',
        'description'   => $row['issue'] ?? '',
        'location'      => $row['location'] ?? '',
        'latitude'      => $row['coord_lat'] ?? null,
        'longitude'     => $row['coord_lng'] ?? null,
        'priority'      => strtolower((string)($row['priority'] ?? 'medium')),
        'status'        => $status,
        'assigned_to'   => $row['engineer'] ?? $row['cprf_facility_name'] ?? null,
        'estimation'    => $row['budget_allocation'] ?? $row['budget'] ?? 0,
        'engineer'      => $row['engineer'] ?? null,
        'budget_allocation'=> $row['budget_allocation'] ?? null,
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

    $opts = [
        'verification_status' => ['Approved', 'In Progress'],
        'infrastructure' => 'Roads'
    ];
    $rows = rgmap_fetch_cimm_verification_reports($pdo, $opts);

    $mapped = array_map('mapCimmToReportManagement', $rows);

    // CIMM reports that are still pending, cancelled or rejected are not
    // shown in report management. Rejected CIMM reports map to the 'cancelled'
    // status here, so excluding both 'pending' and 'cancelled' covers pending,
    // cancelled and rejected reports; only Approved, In Progress and Completed
    // CIMM reports appear in this panel.
    $mapped = array_values(array_filter($mapped, function ($r) {
        return !in_array(strtolower($r['status'] ?? ''), ['pending', 'cancelled'], true);
    }));

    if ($status_filter !== 'all') {
        $mapped = array_values(array_filter($mapped, function ($r) use ($status_filter) {
            return $r['status'] === $status_filter;
        }));
    }

    return $mapped;
}

// Get reports for display
function get_reports($status_filter = 'all', $source_filter = 'all', $limit = 50, $offset = 0, $road_only = false, $include_completed = false) {
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
    $lgu_active_statuses = $include_completed ? "'approved', 'in-progress', 'completed'" : "'approved', 'in-progress'";
    if ($transport_estimation_exists) {
        $transport_query = "SELECT id, report_id, title, description, location, latitude, longitude, priority, status, assigned_to, engineer, budget_allocation, cimm_engineer_name, cimm_budget, estimation, resolution_notes as notes, department, created_date, created_at, updated_at, approved_at, attachments, image_path, report_type, report_category, report_source, created_by, CASE WHEN report_type = 'infrastructure_issue' THEN 'maintenance' WHEN report_source = 'local' AND created_by != 0 AND status IN ({$lgu_active_statuses}) THEN 'lgu_reports' WHEN report_source = 'local' AND created_by != 0 THEN 'hidden' ELSE 'transport' END as source_system FROM road_transportation_reports";
    } else {
        $transport_query = "SELECT id, report_id, title, description, location, latitude, longitude, priority, status, assigned_to, engineer, budget_allocation, cimm_engineer_name, cimm_budget, 0 as estimation, resolution_notes as notes, department, created_date, created_at, updated_at, approved_at, attachments, image_path, report_type, report_category, report_source, created_by, CASE WHEN report_type = 'infrastructure_issue' THEN 'maintenance' WHEN report_source = 'local' AND created_by != 0 AND status IN ({$lgu_active_statuses}) THEN 'lgu_reports' WHEN report_source = 'local' AND created_by != 0 THEN 'hidden' ELSE 'transport' END as source_system FROM road_transportation_reports";
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
    
    $include_transport = ($source_filter === 'all' || $source_filter === 'transport' || $source_filter === 'lgu_reports');
    $include_maintenance = ($source_filter === 'all' || $source_filter === 'maintenance');
    
    $is_lgu_filter = ($source_filter === 'lgu_reports');

    // Road Operations Supervisors see only Road reports — Transportation
    // reports are excluded at the query level using the existing
    // report_category classification.
    $road_cond = $road_only ? 'report_category = \'road\'' : '';

    if (!$include_transport && !$include_maintenance) {
        $transport_query = "SELECT NULL FROM road_transportation_reports WHERE 1=0";
    } elseif (!$include_transport && $include_maintenance) {
        // When only maintenance is selected, include infrastructure issues from transport table
        $transport_query .= " WHERE report_type = 'infrastructure_issue'";
        if ($road_cond !== '') {
            $transport_query .= " AND {$road_cond}";
        }
        if (!empty($where_conditions)) {
            $transport_query .= " AND " . implode(' AND ', $where_conditions);
        }
        $transport_params = $params ?? [];
    } elseif ($include_transport && !$include_maintenance) {
        // When only transport is selected, exclude infrastructure issues (they belong in infra panel)
        $transport_query .= " WHERE report_type != 'infrastructure_issue'";
        if ($road_cond !== '') {
            $transport_query .= " AND {$road_cond}";
        }
        if ($is_lgu_filter) {
            $transport_query .= " AND report_source = 'local' AND created_by != 0 AND status IN ({$lgu_active_statuses})";
        } else {
            // 'transport' (Citizen Reports) filter: only citizen-submitted
            // reports. LGU staff-created reports (created_by != 0) must never
            // be fetched here so the Citizen Reports panel shows citizens only.
            $transport_query .= " AND created_by = 0";
        }
        if (!empty($where_conditions)) {
            $transport_query .= " AND " . implode(' AND ', $where_conditions);
        }
        $transport_params = $params ?? [];
    } else {
        // Both transport and maintenance included.
        $where_parts = $where_conditions;
        if ($road_cond !== '') {
            $where_parts[] = $road_cond;
        }
        if (!empty($where_parts)) {
            $transport_query .= " WHERE " . implode(' AND ', $where_parts);
            $transport_params = $params ?? [];
        }
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
    
    // Note: CIMM reports are fetched independently in the caller (see below)
    // to avoid being crowded out by the 20-row combined limit.
    
    // Combine and sort
    $all_reports = array_merge($transport_reports ?: [], $maintenance_reports ?: []);

    // Report management only lists active reports: Approved and In Progress.
    // Pending, Rejected and Cancelled reports are excluded from every list;
    // only the Transportation Operations Supervisor can keep Completed reports
    // visible here (so they can be archived on demand instead of auto-moved).
    $active_statuses = $include_completed ? ['approved', 'in-progress', 'completed'] : ['approved', 'in-progress'];
    $all_reports = array_values(array_filter($all_reports, function ($r) use ($active_statuses) {
        return in_array(($r['status'] ?? ''), $active_statuses, true);
    }));

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

// Normalize the source aliases used by notification deep-links so the filter
// logic, panel classification and get_reports() all agree on one value:
//   'citizen' -> 'transport'  (Citizen Reports)
//   'lgu'     -> 'lgu_reports' (LGU Monitoring Reports)
//   'cimm'    -> 'cimm'
//   'maintenance' -> 'maintenance'
$source_aliases = [
    'all'          => 'all',
    'citizen'      => 'transport',
    'transport'    => 'transport',
    'lgu'          => 'lgu_reports',
    'lgu_reports'  => 'lgu_reports',
    'cimm'         => 'cimm',
    'maintenance'  => 'maintenance',
];
$source_filter = $source_aliases[$source_filter] ?? 'all';
$_GET['source'] = $source_filter;

// Road Operations Supervisors see only Road-relevant reports: Road reports in
// the LGU Monitoring panel, all CIMM reports, and no Transportation reports.
$is_road_supervisor = ($user_role === 'road_ops_supervisor');

// Get data
// The Transportation Operations Supervisor keeps Completed reports visible in
// report_management.php (with an on-demand Archive button) instead of having
// them auto-archived, so the shared get_reports() is asked to include them.
$reports = get_reports($status_filter, $source_filter, $per_page, $offset, $is_road_supervisor, ($user_role === 'trans_ops_supervisor'));
$stats = get_report_stats();
$csrf_token = generate_csrf_token();
$flash_message = get_flash_message();

// Transportation Operations supervisors only see LGU Monitoring
// Transportation reports and Citizen reports (no CIMM, infrastructure,
// or LGU Road reports).
$is_transport_supervisor = ($user_role === 'trans_ops_supervisor');

// Separate reports by source system for panel display
$citizen_reports = [];
$lgu_reports_list = [];
$cimm_reports_list = [];
$infra_reports_list = [];
// Citizen reports only appear in the Citizen Reports panel after they have
// been verified and approved in verification_monitoring.php. Any pre-verification
// status keeps them out of this panel (they remain in verification_monitoring.php).
$citizen_unverified_statuses = ['pending', 'awaiting verification', 'for verification', 'under review', 'submitted', 'new'];
foreach ($reports as $report) {
    $src = $report['source_system'] ?? 'transport';

    if ($is_transport_supervisor) {
        // 'lgu_reports' is already restricted to approved LGU Monitoring
        // Transportation reports by get_reports(); LGU Road reports land in
        // 'transport' with created_by != 0 and are not shown here.
        if ($src === 'lgu_reports') {
            $lgu_reports_list[] = $report;
        } elseif ($src !== 'maintenance' && ($report['created_by'] ?? 0) == 0 && !in_array(strtolower($report['status'] ?? ''), $citizen_unverified_statuses)) {
            $citizen_reports[] = $report;
        }
        continue;
    }

    if ($src === 'maintenance') {
        $infra_reports_list[] = $report;
    } elseif ($src === 'lgu_reports') {
        $lgu_reports_list[] = $report;
    } elseif ($src === 'hidden') {
        // Skip unapproved LGU reports
    } else {
        // Only include verified citizen reports where created_by = 0
        if (($report['created_by'] ?? 0) == 0 && !in_array(strtolower($report['status'] ?? ''), $citizen_unverified_statuses)) {
            $citizen_reports[] = $report;
        }
    }
}

// Transportation Operations Supervisors see the assigned staff member's name
// in the LGU Monitoring and Citizen Reports tables.
if ($is_transport_supervisor) {
    annotate_report_assignment_status($conn, $lgu_reports_list);
    annotate_report_assignment_status($conn, $citizen_reports);
}

// Fetch CIMM reports independently (not through the combined get_reports pipeline
// which limits all sources to 20 total, crowding out CIMM reports)
$include_cimm = ($source_filter === 'all' || $source_filter === 'cimm');
if ($include_cimm && !$is_transport_supervisor) {
    try {
        $cimm_reports_list = getCimmReportsForManagement($status_filter);
    } catch (Exception $e) {
        error_log("CIMM panel fetch failed: " . $e->getMessage());
        $cimm_reports_list = [];
    }
}

// Deep-link focus: when a specific report is requested via ?source= and ?id=
// (e.g. from a notification "View" button), make sure it is rendered so the
// frontend can scroll to and highlight it — even when pagination, filters or
// source classification ('hidden') would otherwise exclude it. The backend
// fetches the record from the correct table before any JS highlight runs, so
// this is never a JavaScript-only lookup.
$focus_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$focus_source = $_GET['source'] ?? '';
$focus_target = [
    'found'       => false,
    'id'          => $focus_id,
    'source'      => $focus_source,
    'panel'       => '',
    'panelSource' => '',
];

if ($focus_id > 0) {
    try {
        $focus_report = null;
        $focus_panel = ''; // one of: citizen | lgu | cimm | maintenance

        $est_result = $conn->query("SHOW COLUMNS FROM road_transportation_reports LIKE 'estimation'");
        $transport_est = $est_result && $est_result->num_rows > 0;
        $transport_est_col = $transport_est ? 'estimation' : '0 as estimation';

        $transport_focus_query = function ($id) use ($conn, $transport_est_col, $is_road_supervisor) {
            $row = fetch_one("SELECT id, report_id, title, description, location, latitude, longitude, priority, status, assigned_to, {$transport_est_col}, resolution_notes as notes, department, created_date, created_at, updated_at, approved_at, attachments, image_path, report_type, report_category, report_source, created_by, CASE WHEN report_type = 'infrastructure_issue' THEN 'maintenance' WHEN report_category = 'transportation' AND report_source = 'local' AND created_by != 0 AND status = 'approved' THEN 'lgu_reports' WHEN report_category = 'transportation' AND report_source = 'local' AND created_by != 0 THEN 'hidden' ELSE 'transport' END as source_system FROM road_transportation_reports WHERE id = ?", [$id], 'i');
            // Road Operations Supervisors never see Transportation reports —
            // do not reveal them even via a deep-link.
            if ($is_road_supervisor && ($row['report_category'] ?? '') === 'transportation') {
                return null;
            }
            return $row;
        };

        $src_key = $source_aliases[$focus_source] ?? '';

        switch ($src_key) {
            case 'cimm':
                // CIMM reports live in their own table.
                $focus_panel = 'cimm';
                foreach ($cimm_reports_list as $r) {
                    if ((int)$r['id'] === $focus_id) { $focus_report = $r; break; }
                }
                if (!$focus_report) {
                    $pdo = rgmap_verification_pdo();
                    rgmap_ensure_cimm_verification_table($pdo);
                    $cimm_stmt = $pdo->prepare("SELECT * FROM cimm_verification_reports WHERE id = ?");
                    $cimm_stmt->execute([$focus_id]);
                    $cimm_row = $cimm_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($cimm_row) {
                        $focus_report = mapCimmToReportManagement($cimm_row);
                        $cimm_reports_list[] = $focus_report;
                    }
                }
                break;

            case 'maintenance':
                // Infrastructure projects come from road_maintenance_reports,
                // and infrastructure issues from road_transportation_reports.
                $focus_panel = 'maintenance';
                foreach ($infra_reports_list as $r) {
                    if ((int)$r['id'] === $focus_id) { $focus_report = $r; break; }
                }
                if (!$focus_report) {
                    $maint_result = $conn->query("SHOW COLUMNS FROM road_maintenance_reports LIKE 'estimation'");
                    $maint_est = $maint_result && $maint_result->num_rows > 0;
                    $maint_est_col = $maint_est ? 'estimation' : '0 as estimation';
                    $focus_report = fetch_one("SELECT id, report_id, title, description, location, priority, status, maintenance_team as assigned_to, {$maint_est_col}, department, created_date, created_at, updated_at, approved_at, NULL as attachments, NULL as image_path, 'maintenance' as report_type, 'maintenance' as source_system FROM road_maintenance_reports WHERE id = ?", [$focus_id], 'i');
                    if (!$focus_report) {
                        $infra_road = $is_road_supervisor ? " AND report_category = 'road'" : '';
                        $focus_report = fetch_one("SELECT id, report_id, title, description, location, latitude, longitude, priority, status, assigned_to, {$transport_est_col}, resolution_notes as notes, department, created_date, created_at, updated_at, approved_at, attachments, image_path, report_type, report_category, report_source, created_by, 'maintenance' as source_system FROM road_transportation_reports WHERE id = ? AND report_type = 'infrastructure_issue'{$infra_road}", [$focus_id], 'i');
                    }
                    if ($focus_report) $infra_reports_list[] = $focus_report;
                }
                break;

            case 'lgu_reports':
                // LGU Monitoring Reports are LGU-staff-created reports. Even
                // unapproved ones ('hidden' classification) must be injected
                // into the LGU panel so the notification link always lands.
                $focus_panel = 'lgu';
                foreach ($lgu_reports_list as $r) {
                    if ((int)$r['id'] === $focus_id) { $focus_report = $r; break; }
                }
                if (!$focus_report) {
                    $focus_report = $transport_focus_query($focus_id);
                    if ($focus_report) $lgu_reports_list[] = $focus_report;
                }
                break;

            case 'transport':
                // Citizen Reports.
                $focus_panel = 'citizen';
                foreach ($citizen_reports as $r) {
                    if ((int)$r['id'] === $focus_id) { $focus_report = $r; break; }
                }
                if (!$focus_report) {
                    $focus_report = $transport_focus_query($focus_id);
                    if ($focus_report) $citizen_reports[] = $focus_report;
                }
                break;

            default:
                // Legacy deep-links (?id= without ?source=): look up whichever
                // table actually contains the report and place it in the panel
                // that matches its classification.
                foreach ([$citizen_reports, $lgu_reports_list, $infra_reports_list, $cimm_reports_list] as $list) {
                    foreach ($list as $r) {
                        if ((int)$r['id'] === $focus_id) { $focus_report = $r; break 2; }
                    }
                }
                if ($focus_report) {
                    $cls = $focus_report['source_system'] ?? 'transport';
                    if ($cls === 'maintenance') $focus_panel = 'maintenance';
                    elseif ($cls === 'lgu_reports' || $cls === 'hidden') $focus_panel = 'lgu';
                    else $focus_panel = 'citizen';
                } else {
                    $focus_report = $transport_focus_query($focus_id);
                    if ($focus_report) {
                        $cls = $focus_report['source_system'] ?? 'transport';
                        if ($cls === 'maintenance') $focus_panel = 'maintenance';
                        elseif ($cls === 'lgu_reports' || $cls === 'hidden') $focus_panel = 'lgu';
                        else $focus_panel = 'citizen';
                    } else {
                        $maint_result = $conn->query("SHOW COLUMNS FROM road_maintenance_reports LIKE 'estimation'");
                        $maint_est = $maint_result && $maint_result->num_rows > 0;
                        $maint_est_col = $maint_est ? 'estimation' : '0 as estimation';
                        $focus_report = fetch_one("SELECT id, report_id, title, description, location, priority, status, maintenance_team as assigned_to, {$maint_est_col}, department, created_date, created_at, updated_at, approved_at, NULL as attachments, NULL as image_path, 'maintenance' as report_type, 'maintenance' as source_system FROM road_maintenance_reports WHERE id = ?", [$focus_id], 'i');
                        if ($focus_report) $focus_panel = 'maintenance';
                    }
                }
                if ($focus_report) {
                    if ($focus_panel === 'maintenance') $infra_reports_list[] = $focus_report;
                    elseif ($focus_panel === 'lgu') $lgu_reports_list[] = $focus_report;
                    else $citizen_reports[] = $focus_report;
                }
                break;
        }

        if ($focus_report) {
            $focus_target['found'] = true;
            $focus_target['panel'] = $focus_panel;
            $panel_sources = [
                'citizen'     => 'transport',
                'lgu'         => 'lgu_reports',
                'cimm'        => 'cimm',
                'maintenance' => 'maintenance',
            ];
            $focus_target['panelSource'] = $panel_sources[$focus_panel] ?? '';
        }
    } catch (Exception $e) {
        error_log("Deep-link focus report fetch failed: " . $e->getMessage());
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Report Management - LGU Road Monitoring</title>
    <link rel="icon" type="image/png" href="../../assets/img/logocityhall.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/theme-tokens.css">
    <link rel="stylesheet" href="../../css/theme-utilities.css">
    <link rel="stylesheet" href="../../css/sidebar.css">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <link rel="stylesheet" href="../../css/progress-updates.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="../../js/progress-updates.js"></script>
    <script src="../../js/progress-updates-common.js"></script>
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
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .filter-group > div {
            flex: 1;
            min-width: 180px;
        }

        .filter-group .btn-wrapper {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            background: white;
            min-width: 150px;
            width: 100%;
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
            padding: 8px 20px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-success-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.3);
        }

        .btn-danger-custom {
            padding: 10px 20px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-danger-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.3);
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
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }

        /* Add/Edit Update Modal form styles */
        #addUpdateModal .form-group { margin-bottom: 16px; }
        #addUpdateModal .form-label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #333;
            margin-bottom: 5px;
        }
        #addUpdateModal .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid rgba(55,98,200,0.15);
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s;
            background: white;
            color: #333;
            box-sizing: border-box;
        }
        #addUpdateModal .form-control:focus {
            border-color: #3762c8;
            outline: none;
            box-shadow: 0 0 0 3px rgba(55,98,200,0.1);
        }
        #addUpdateModal textarea.form-control { resize: vertical; }
        #addUpdateModal .file-previews {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }
        #addUpdateModal .file-preview-item {
            width: 80px;
            height: 80px;
            border-radius: 6px;
            overflow: hidden;
            border: 1px solid #ddd;
            position: relative;
            background: #f8f9fa;
            flex-shrink: 0;
        }
        #addUpdateModal .file-preview-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        #addUpdateModal .file-preview-item .remove-preview {
            position: absolute;
            top: 2px;
            right: 2px;
            width: 20px;
            height: 20px;
            background: rgba(220,53,69,0.9);
            color: #fff;
            border: none;
            border-radius: 50%;
            font-size: 14px;
            line-height: 1;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }
        #addUpdateModal .file-preview-item .remove-preview:hover { background: #dc3545; }
        body.dark-mode #addUpdateModal .form-label { color: #e4e6ea; }
        body.dark-mode #addUpdateModal .form-control {
            background: #1a1d23;
            color: #e4e6ea;
            border-color: #3a3f4a;
        }
        body.dark-mode #addUpdateModal .file-preview-item { background: #2a2e36; border-color: #3a3f4a; }
        #updatesModal .btn-action, #addUpdateModal .btn-action {
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 600;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: white;
            border-radius: 8px;
        }
        #updatesModal .btn-action:hover, #addUpdateModal .btn-action:hover {
            background: linear-gradient(135deg, #1e3c72, #3762c8);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(55,98,200,0.3);
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
            background: linear-gradient(135deg, #16a34a, #15803d);
        }

        .rm-panel-icon.lgu {
            background: linear-gradient(135deg, #3762c8, #1e3c72);
        }

        .rm-panel-icon.cimm {
            background: linear-gradient(135deg, #3762c8, #1e3c72);
        }

        .rm-panel-icon.infra {
            background: linear-gradient(135deg, #f97316, #ea580c);
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

        .rm-panel-badge.citizen { background: #16a34a; }
        .rm-panel-badge.lgu { background: #3762c8; }
        .rm-panel-badge.cimm { background: #3762c8; }
        .rm-panel-badge.infra { background: #f97316; }

        #citizenReportsPanel.rm-panel {
            background: #f0f8f4;
            border-color: #cce0d4;
        }
        body.dark-mode #citizenReportsPanel.rm-panel {
            background: #1e2229;
            border-color: #1a3d2a;
        }
        #citizenReportsPanel .rm-panel-header {
            border-bottom-color: rgba(22, 163, 74, 0.15);
        }
        #citizenReportsPanel .rm-panel-title {
            color: #15803d;
        }
        body.dark-mode #citizenReportsPanel .rm-panel-title {
            color: #86efac;
        }
        #citizenReportsPanel .rm-panel-subtitle {
            color: #166534;
        }
        body.dark-mode #citizenReportsPanel .rm-panel-subtitle {
            color: #6ee7b7;
        }
        #citizenReportsPanel .rm-panel-search {
            border-bottom-color: rgba(22, 163, 74, 0.08);
        }
        #citizenReportsPanel .rm-search-input:focus {
            border-color: #16a34a;
            box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.1);
        }
        #citizenReportsPanel .rm-sort-btn {
            background: linear-gradient(135deg, #16a34a, #15803d);
        }
        #citizenReportsPanel .rm-sort-btn:hover {
            box-shadow: 0 4px 12px rgba(22, 163, 74, 0.3);
        }
        #citizenReportsPanel .rm-table thead th {
            background: linear-gradient(135deg, #16a34a, #15803d);
        }
        #citizenReportsPanel .rm-table tbody tr {
            border-bottom-color: rgba(22, 163, 74, 0.08);
        }
        #citizenReportsPanel .rm-table tbody tr:hover {
            background: rgba(22, 163, 74, 0.05);
        }
        body.dark-mode #citizenReportsPanel .rm-table tbody tr:hover {
            background: rgba(22, 163, 74, 0.08);
        }

        #infraReportsPanel.rm-panel {
            background: #fff8f0;
            border-color: #f0e0cc;
        }
        body.dark-mode #infraReportsPanel.rm-panel {
            background: #1e2229;
            border-color: #3d3226;
        }
        #infraReportsPanel .rm-panel-header {
            border-bottom-color: rgba(249, 115, 22, 0.15);
        }
        #infraReportsPanel .rm-panel-title {
            color: #c2410c;
        }
        body.dark-mode #infraReportsPanel .rm-panel-title {
            color: #fdba74;
        }
        #infraReportsPanel .rm-panel-subtitle {
            color: #92400e;
        }
        body.dark-mode #infraReportsPanel .rm-panel-subtitle {
            color: #d6a564;
        }
        #infraReportsPanel .rm-panel-search {
            border-bottom-color: rgba(249, 115, 22, 0.08);
        }
        #infraReportsPanel .rm-search-input:focus {
            border-color: #f97316;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
        }
        #infraReportsPanel .rm-sort-btn {
            background: linear-gradient(135deg, #f97316, #ea580c);
        }
        #infraReportsPanel .rm-sort-btn:hover {
            box-shadow: 0 4px 12px rgba(249, 115, 22, 0.3);
        }
        #infraReportsPanel .rm-table thead th {
            background: linear-gradient(135deg, #f97316, #ea580c);
        }
        #infraReportsPanel .rm-table tbody tr {
            border-bottom-color: rgba(249, 115, 22, 0.08);
        }
        #infraReportsPanel .rm-table tbody tr:hover {
            background: rgba(249, 115, 22, 0.05);
        }
        body.dark-mode #infraReportsPanel .rm-table tbody tr:hover {
            background: rgba(249, 115, 22, 0.08);
        }

        #lguReportsPanel.rm-panel {
            background: #f0f4fa;
            border-color: #c8d4e6;
        }
        body.dark-mode #lguReportsPanel.rm-panel {
            background: #1e2229;
            border-color: #1a2a44;
        }
        #lguReportsPanel .rm-panel-header {
            border-bottom-color: rgba(55, 98, 200, 0.15);
        }
        #lguReportsPanel .rm-panel-title {
            color: #1e3c72;
        }
        body.dark-mode #lguReportsPanel .rm-panel-title {
            color: #93c5fd;
        }
        #lguReportsPanel .rm-panel-subtitle {
            color: #1e40af;
        }
        body.dark-mode #lguReportsPanel .rm-panel-subtitle {
            color: #90b4e3;
        }
        #lguReportsPanel .rm-panel-search {
            border-bottom-color: rgba(55, 98, 200, 0.08);
        }
        #lguReportsPanel .rm-search-input:focus {
            border-color: #3762c8;
            box-shadow: 0 0 0 3px rgba(55, 98, 200, 0.1);
        }
        #lguReportsPanel .rm-sort-btn {
            background: linear-gradient(135deg, #3762c8, #1e3c72);
        }
        #lguReportsPanel .rm-sort-btn:hover {
            box-shadow: 0 4px 12px rgba(55, 98, 200, 0.3);
        }
        #lguReportsPanel .rm-table thead th {
            background: linear-gradient(135deg, #3762c8, #1e3c72);
        }
        #lguReportsPanel .rm-table tbody tr {
            border-bottom-color: rgba(55, 98, 200, 0.08);
        }
        #lguReportsPanel .rm-table tbody tr:hover {
            background: rgba(55, 98, 200, 0.05);
        }
        body.dark-mode #lguReportsPanel .rm-table tbody tr:hover {
            background: rgba(55, 98, 200, 0.08);
        }

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

        .rm-row-focus {
            animation: rmFocusPulse 1.2s ease-in-out 4;
            box-shadow: 0 0 0 3px #3762c8, 0 8px 32px rgba(55, 98, 200, 0.35);
            border-left: 4px solid #3762c8;
            background: rgba(55, 98, 200, 0.12);
        }

        @keyframes rmFocusPulse {
            0%, 100% { background-color: rgba(55, 98, 200, 0.12); }
            50% { background-color: rgba(55, 98, 200, 0.28); }
        }

        body.dark-mode .rm-row-focus {
            box-shadow: 0 0 0 3px #6a9bff, 0 8px 32px rgba(106, 155, 255, 0.35);
            border-left: 4px solid #6a9bff;
            background: rgba(106, 155, 255, 0.14);
        }

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
        .rm-status-badge.cancelled, .rm-status-badge.cancelled { background: rgba(220, 53, 69, 0.15); color: #ef4444; }

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

        .assignment-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
        }
        .assignment-badge.assignment-assigned { background: #d1fae5; color: #065f46; }
        .assignment-badge.assignment-unassigned { background: #e2e3e5; color: #495057; }

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

        /* Report Detail Modal (mirrors verification_monitoring lgu modal) */
        .rm-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            box-sizing: border-box;
            overflow-y: auto;
        }

        .rm-modal-overlay.active {
            display: flex;
        }

        .rm-modal-content {
            background: #f0f4fa;
            border-radius: 16px;
            max-width: 860px;
            width: 100%;
            max-height: calc(100vh - 40px);
            position: relative;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            margin: auto;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
            border: 1px solid #c8d0e0;
        }

        .rm-modal-header {
            background: white;
            border-radius: 16px 16px 0 0;
            padding: 24px 28px 18px;
            border-bottom: 2px solid rgba(55, 98, 200, 0.15);
            flex-shrink: 0;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .rm-modal-header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .rm-modal-title-area {
            flex: 1;
            min-width: 0;
        }

        .rm-modal-report-id {
            font-size: 13px;
            color: #3762c8;
            font-weight: 600;
            letter-spacing: 0.3px;
            margin-bottom: 4px;
        }

        .rm-modal-title {
            font-size: 22px;
            font-weight: 700;
            color: #1e3c72;
            margin: 0 0 10px 0;
            line-height: 1.3;
        }

        .rm-modal-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .rm-modal-close {
            background: none;
            border: none;
            font-size: 28px;
            color: #666;
            cursor: pointer;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s;
            flex-shrink: 0;
            margin-left: 15px;
        }

        .rm-modal-close:hover {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .rm-modal-body {
            overflow-y: auto;
            flex: 1;
            min-height: 0;
            padding: 24px 28px;
        }

        .rm-modal-body::-webkit-scrollbar {
            width: 8px;
        }

        .rm-modal-body::-webkit-scrollbar-track {
            background: rgba(55, 98, 200, 0.08);
            border-radius: 4px;
        }

        .rm-modal-body::-webkit-scrollbar-thumb {
            background: rgba(55, 98, 200, 0.2);
            border-radius: 4px;
        }

        .rm-modal-body::-webkit-scrollbar-thumb:hover {
            background: rgba(55, 98, 200, 0.35);
        }

        .rm-modal-section {
            background: white;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(55, 98, 200, 0.1);
        }

        .rm-modal-section-title {
            font-size: 14px;
            font-weight: 700;
            color: #1e3c72;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(55, 98, 200, 0.1);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .rm-modal-section-title i {
            color: #3762c8;
            font-size: 15px;
        }

        .rm-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .rm-info-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 6px 0;
        }

        .rm-info-icon {
            width: 28px;
            height: 28px;
            background: rgba(55, 98, 200, 0.1);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #3762c8;
            font-size: 13px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .rm-info-label {
            font-size: 11px;
            color: #6b7280;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 2px;
        }

        .rm-info-value {
            font-size: 14px;
            color: #1f2937;
            font-weight: 500;
            line-height: 1.4;
            word-break: break-word;
        }

        .rm-info-value-full {
            grid-column: 1 / -1;
        }

        .rm-description-text {
            font-size: 14px;
            color: #374151;
            line-height: 1.7;
            padding: 8px 0;
            white-space: pre-wrap;
        }

        .rm-modal-footer {
            background: white;
            border-radius: 0 0 16px 16px;
            padding: 16px 28px;
            border-top: 1px solid rgba(55, 98, 200, 0.1);
            flex-shrink: 0;
            display: flex;
            justify-content: flex-end;
        }

        .rm-modal-btn-close {
            padding: 10px 24px;
            background: rgba(55, 98, 200, 0.1);
            color: #3762c8;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: all 0.2s;
        }

        .rm-modal-btn-close:hover {
            background: rgba(55, 98, 200, 0.2);
        }

        @media (max-width: 640px) {
            .rm-info-grid {
                grid-template-columns: 1fr;
            }
            .rm-modal-header {
                padding: 18px 16px;
            }
            .rm-modal-body {
                padding: 16px;
            }
            .rm-modal-content {
                max-width: 100%;
                border-radius: 0;
            }
            .rm-modal-overlay {
                padding: 0;
            }
        }

        /* Report Detail Modal Dark Mode */
        body.dark-mode .rm-modal-content {
            background: #1a1d24 !important;
            border-color: #2d323b !important;
        }
        body.dark-mode .rm-modal-header {
            background: #1a1d24 !important;
            border-bottom-color: #2d323b !important;
        }
        body.dark-mode .rm-modal-title {
            color: #60a5fa !important;
        }
        body.dark-mode .rm-modal-report-id {
            color: #60a5fa !important;
        }
        body.dark-mode .rm-modal-close {
            color: #9ca3af !important;
        }
        body.dark-mode .rm-modal-section {
            background: #22262e !important;
            border-color: #2d323b !important;
        }
        body.dark-mode .rm-modal-section-title {
            color: #60a5fa !important;
            border-bottom-color: #2d323b !important;
        }
        body.dark-mode .rm-info-icon {
            background: rgba(55,98,200,0.15) !important;
        }
        body.dark-mode .rm-info-label {
            color: #9ca3af !important;
        }
        body.dark-mode .rm-info-value {
            color: #e4e6ea !important;
        }
        body.dark-mode .rm-description-text {
            color: #c0c8d8 !important;
        }
        body.dark-mode .rm-modal-footer {
            background: #1a1d24 !important;
            border-top-color: #2d323b !important;
        }
        body.dark-mode .rm-modal-btn-close {
            background: rgba(55,98,200,0.15) !important;
            color: #60a5fa !important;
        }
        body.dark-mode .rm-modal-body::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.05) !important;
        }
        body.dark-mode .rm-modal-body::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.15) !important;
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

        .rm-archive-btn {
            padding: 5px 10px;
            background: rgba(34, 197, 94, 0.1);
            color: #16a34a;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .rm-archive-btn:hover { background: rgba(34, 197, 94, 0.2); }

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
                        <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="in-progress" <?php echo $status_filter === 'in-progress' ? 'selected' : ''; ?>>In Progress</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Source System</label>
                    <select class="filter-select" id="sourceFilter" onchange="filterReports()">
                        <option value="all" <?php echo $source_filter === 'all' ? 'selected' : ''; ?>>All Sources</option>
                        <option value="transport" <?php echo $source_filter === 'transport' ? 'selected' : ''; ?>>Citizen Reports</option>
                        <option value="lgu_reports" <?php echo $source_filter === 'lgu_reports' ? 'selected' : ''; ?>>LGU Monitoring Reports</option>
                        <?php if (!$is_transport_supervisor): ?>
                        <option value="cimm" <?php echo $source_filter === 'cimm' ? 'selected' : ''; ?>>CIMM Reports</option>
                        <?php endif; ?>
                        <?php if (!$is_transport_supervisor && !$is_road_supervisor): ?>
                        <option value="maintenance" <?php echo $source_filter === 'maintenance' ? 'selected' : ''; ?>>Infrastructure Projects</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">&nbsp;</label>
                    <div class="btn-wrapper">
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

        <!-- LGU Monitoring Reports Panel -->
        <div class="rm-panel" id="lguReportsPanel">
            <div class="rm-panel-header">
                <div class="rm-panel-header-left">
                    <div class="rm-panel-icon lgu">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div>
                        <div class="rm-panel-title-group">
                            <h2 class="rm-panel-title">LGU Monitoring Reports</h2>
                            <span class="rm-panel-badge lgu"><?php echo count($lgu_reports_list); ?> Reports</span>
                        </div>
                        <p class="rm-panel-subtitle">Reports created by LGU staff via the road monitoring system</p>
                    </div>
                </div>
            </div>

            <div class="rm-panel-search">
                <div class="rm-search-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" class="rm-search-input" id="lguSearchInput" placeholder="Search by Report #..." oninput="panelSearch('lguSearchInput', 'lguTable')">
                </div>
                <button class="rm-sort-btn" onclick="toggleLguSort()">
                    <i class="fas fa-sort"></i> Sort
                </button>
            </div>

            <div class="rm-table-wrapper">
                <table class="rm-table" id="lguTable">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Report #</th>
                            <th>Title</th>
                            <th>Type</th>
                            <th>Location</th>
                            <th>Department</th>
                            <th>Priority</th>
                            <?php if ($is_transport_supervisor): ?>
                            <th>Assignment</th>
                            <?php endif; ?>
                            <?php if ($is_road_supervisor || $user_role === 'system_admin'): ?>
                            <th>Engineer</th>
                            <th>Budget Allocation</th>
                            <?php endif; ?>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $hasLgu = false;
                        if (!empty($lgu_reports_list)):
                            foreach ($lgu_reports_list as $report):
                                $hasLgu = true;
                        ?>
                        <tr data-id="<?php echo (int)$report['id']; ?>" data-source="lgu_reports">
                            <td>
                                <div class="rm-action-group">
                                    <button class="rm-action-btn" onclick="viewReport(<?php echo (int)$report['id']; ?>, '<?php echo htmlspecialchars($report['report_type'], ENT_QUOTES); ?>', 'road_transportation_reports')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($is_road_supervisor || $is_transport_supervisor || $user_role === 'system_admin'): ?>
                                    <button class="rm-edit-btn" onclick="editReport(<?php echo (int)$report['id']; ?>, '<?php echo htmlspecialchars($report['report_type'], ENT_QUOTES); ?>', 'road_transportation_reports')">
                                        <i class="fas fa-pencil"></i>
                                    </button>
                                    <?php endif; ?>
                                    <button class="rm-delete-btn" onclick="deleteReport(<?php echo (int)$report['id']; ?>, '<?php echo htmlspecialchars($report['report_type'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <button class="rm-action-btn t-badge t-badge-approved" onclick="viewReportUpdates(<?php echo (int)$report['id']; ?>, '<?php echo htmlspecialchars($report['report_type'], ENT_QUOTES); ?>', 'lgu')">
                                        <i class="fas fa-clock"></i>
                                    </button>
                                    <?php if ($is_transport_supervisor && (($report['status'] ?? '') === 'completed')): ?>
                                    <button class="rm-archive-btn" onclick="archiveReport(<?php echo (int)$report['id']; ?>, '<?php echo htmlspecialchars($report['report_type'], ENT_QUOTES); ?>')" title="Archive">
                                        <i class="fas fa-archive"></i>
                                    </button>
                                    <?php endif; ?>
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
                            <?php if ($is_transport_supervisor): ?>
                            <td>
                                <?php if (($report['assignment_status'] ?? 'unassigned') === 'assigned' && !empty($report['assignment_officer'])): ?>
                                <span class="assignment-badge assignment-assigned"><?php echo htmlspecialchars($report['assignment_officer']); ?></span>
                                <?php else: ?>
                                <span class="assignment-badge assignment-unassigned">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <?php if ($is_road_supervisor || $user_role === 'system_admin'): ?>
                            <td>
                                <?php $lgu_engineer = (trim((string)($report['cimm_engineer_name'] ?? '')) !== '') ? $report['cimm_engineer_name'] : ($report['engineer'] ?? ''); ?>
                                <?php if (!empty($lgu_engineer) && ($report['report_category'] ?? '') === 'road'): ?>
                                <span class="rm-badge lgu" title="CIMM Assigned Engineer"><?php echo htmlspecialchars($lgu_engineer); ?></span>
                                <?php else: ?>
                                <span class="t-text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $lgu_budget = (!empty($report['cimm_budget']) && (float)$report['cimm_budget'] > 0) ? $report['cimm_budget'] : ($report['budget_allocation'] ?? null); ?>
                                <?php if (!empty($lgu_budget) && (float)$lgu_budget > 0 && ($report['report_category'] ?? '') === 'road'): ?>
                                <span class="t-text-success" title="CIMM Budget Allocation">₱ <?php echo number_format((float)$lgu_budget, 2); ?></span>
                                <?php else: ?>
                                <span class="t-text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td><span class="rm-status-badge <?php echo htmlspecialchars(strtolower($report['status'])); ?>"><?php echo ucfirst(htmlspecialchars(str_replace('-', ' ', $report['status']))); ?></span></td>
                            <td>
                                <?php echo $report['created_at'] ? date('M d, Y', strtotime($report['created_at'])) : '—'; ?>
                                <?php if (($report['status'] ?? '') === 'approved' && !empty($report['approved_at'])): ?>
                                    <br><small class="t-text-success" style="font-weight:600;">Approved: <?php echo date('M d, Y', strtotime($report['approved_at'])); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php
                            endforeach;
                        endif;
                        ?>

                        <?php if (!$hasLgu): ?>
                        <tr>
                            <td colspan="<?php echo (($is_road_supervisor || $user_role === 'system_admin') ? 11 : 9) + ($is_transport_supervisor ? 1 : 0); ?>">
                                <div class="rm-empty-state">
                                    <div class="rm-empty-icon" style="background: rgba(55, 98, 200, 0.12);">
                                        <i class="fas fa-clipboard-list t-text-link"></i>
                                    </div>
                                    <h4>No LGU Monitoring Reports</h4>
                                    <p>No LGU-created monitoring reports found.</p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Citizen Reports Panel (hidden for Road Operations Supervisors) -->
        <?php if (!$is_road_supervisor): ?>
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
                    <input type="text" class="rm-search-input" id="citizenSearchInput" placeholder="Search by Report #..." oninput="panelSearch('citizenSearchInput', 'citizenTable')">
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
                            <?php if ($is_transport_supervisor): ?>
                            <th>Assignment</th>
                            <?php endif; ?>
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
                        <tr data-id="<?php echo (int)$report['id']; ?>" data-source="citizen">
                            <td>
                                <div class="rm-action-group">
                                    <button class="rm-action-btn" onclick="viewReport(<?php echo (int)$report['id']; ?>, '<?php echo htmlspecialchars($report['report_type'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($is_road_supervisor || $is_transport_supervisor): ?>
                                    <button class="rm-edit-btn" onclick="editReport(<?php echo (int)$report['id']; ?>, '<?php echo htmlspecialchars($report['report_type'], ENT_QUOTES); ?>', 'road_transportation_reports')">
                                        <i class="fas fa-pencil"></i>
                                    </button>
                                    <?php endif; ?>
                                    <button class="rm-delete-btn" onclick="deleteReport(<?php echo (int)$report['id']; ?>, '<?php echo htmlspecialchars($report['report_type'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <button class="rm-action-btn t-badge t-badge-approved" onclick="viewReportUpdates(<?php echo (int)$report['id']; ?>, '<?php echo htmlspecialchars($report['report_type'], ENT_QUOTES); ?>', 'citizen')">
                                        <i class="fas fa-clock"></i>
                                    </button>
                                    <?php if ($is_transport_supervisor && (($report['status'] ?? '') === 'completed')): ?>
                                    <button class="rm-archive-btn" onclick="archiveReport(<?php echo (int)$report['id']; ?>, '<?php echo htmlspecialchars($report['report_type'], ENT_QUOTES); ?>')" title="Archive">
                                        <i class="fas fa-archive"></i>
                                    </button>
                                    <?php endif; ?>
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
                            <?php if ($is_transport_supervisor): ?>
                            <td>
                                <?php if (($report['assignment_status'] ?? 'unassigned') === 'assigned' && !empty($report['assignment_officer'])): ?>
                                <span class="assignment-badge assignment-assigned"><?php echo htmlspecialchars($report['assignment_officer']); ?></span>
                                <?php else: ?>
                                <span class="assignment-badge assignment-unassigned">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td><span class="rm-status-badge <?php echo htmlspecialchars(strtolower($report['status'])); ?>"><?php echo ucfirst(htmlspecialchars(str_replace('-', ' ', $report['status']))); ?></span></td>
                            <td>
                                <?php echo $report['created_at'] ? date('M d, Y', strtotime($report['created_at'])) : '—'; ?>
                                <?php if (($report['status'] ?? '') === 'approved' && !empty($report['approved_at'])): ?>
                                    <br><small class="t-text-success" style="font-weight:600;">Approved: <?php echo date('M d, Y', strtotime($report['approved_at'])); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php
                            endforeach;
                        endif;
                        ?>

                        <?php if (!$hasCitizen): ?>
                        <tr>
                            <td colspan="<?php echo 9 + ($is_transport_supervisor ? 1 : 0); ?>">
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
        <?php endif; ?>

        <!-- CIMM Reports Panel -->
        <?php if (!$is_transport_supervisor): ?>
        <div class="rm-panel" id="cimmReportsPanel">
            <div class="rm-panel-header">
                <div class="rm-panel-header-left">
                    <div class="rm-panel-icon cimm">
                        <i class="fas fa-building"></i>
                    </div>
                    <div>
                        <div class="rm-panel-title-group">
                            <h2 class="rm-panel-title">CIMM Reports</h2>
                            <span class="rm-panel-badge cimm"><?php echo count($cimm_reports_list); ?> Reports</span>
                        </div>
                        <p class="rm-panel-subtitle">External reports from the CIMM system — managed via Verification Monitoring</p>
                    </div>
                </div>
            </div>

            <div class="rm-panel-search">
                <div class="rm-search-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" class="rm-search-input" id="cimmSearchInput" placeholder="Search by Rep #..." oninput="panelSearch('cimmSearchInput', 'cimmTable')">
                </div>
                <button class="rm-sort-btn" onclick="toggleCimmSort()">
                    <i class="fas fa-sort"></i> Sort
                </button>
            </div>

            <div class="rm-table-wrapper">
                <table class="rm-table" id="cimmTable">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Rep #</th>
                            <th>Infrastructure</th>
                            <th>Location</th>
                            <th>Issue / Notes</th>
                            <th>Engineer</th>
                            <th>Priority</th>
                            <th>Budget</th>
                            <th>Status</th>
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
                        <tr data-id="<?php echo (int)$row['id']; ?>" data-source="cimm">
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
                                    <button class="rm-action-btn t-badge t-badge-approved" onclick="viewReportUpdates(<?php echo (int)$row['id']; ?>, '<?php echo htmlspecialchars($row['report_type'], ENT_QUOTES); ?>', 'cimm')">
                                        <i class="fas fa-clock"></i>
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
                            <td><span class="rm-status-badge <?php echo htmlspecialchars(strtolower($row['status'])); ?>"><?php echo ucfirst(htmlspecialchars(str_replace('-', ' ', $row['status']))); ?></span></td>
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
                                        <i class="fas fa-building t-text-cimm"></i>
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
        <?php endif; ?>

        <!-- Infrastructure Projects Panel (hidden for Road Operations Supervisors) -->
        <?php if (!$is_transport_supervisor && !$is_road_supervisor): ?>
        <div class="rm-panel" id="infraReportsPanel">
            <div class="rm-panel-header">
                <div class="rm-panel-header-left">
                    <div class="rm-panel-icon infra">
                        <i class="fas fa-hard-hat"></i>
                    </div>
                    <div>
                        <div class="rm-panel-title-group">
                            <h2 class="rm-panel-title">Infrastructure Projects</h2>
                            <span class="rm-panel-badge infra"><?php echo count($infra_reports_list); ?> Projects</span>
                        </div>
                        <p class="rm-panel-subtitle">Infrastructure maintenance and project records</p>
                    </div>
                </div>
            </div>

            <div class="rm-panel-search">
                <div class="rm-search-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" class="rm-search-input" id="infraSearchInput" placeholder="Search by Report #..." oninput="panelSearch('infraSearchInput', 'infraTable')">
                </div>
                <button class="rm-sort-btn" onclick="toggleInfraSort()">
                    <i class="fas fa-sort"></i> Sort
                </button>
            </div>

            <div class="rm-table-wrapper">
                <table class="rm-table" id="infraTable">
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
                        $hasInfra = false;
                        if (!empty($infra_reports_list)):
                            foreach ($infra_reports_list as $report):
                                $hasInfra = true;
                        ?>
                        <tr data-id="<?php echo (int)$report['id']; ?>" data-source="maintenance">
                            <td>
                                <div class="rm-action-group">
                                    <button class="rm-action-btn" onclick="viewReport(<?php echo (int)$report['id']; ?>, '<?php echo htmlspecialchars($report['report_type'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($is_road_supervisor): ?>
                                    <button class="rm-edit-btn" onclick="editReport(<?php echo (int)$report['id']; ?>, '<?php echo htmlspecialchars($report['report_type'], ENT_QUOTES); ?>', 'road_maintenance_reports')">
                                        <i class="fas fa-pencil"></i>
                                    </button>
                                    <?php endif; ?>
                                    <button class="rm-delete-btn" onclick="deleteReport(<?php echo (int)$report['id']; ?>, '<?php echo htmlspecialchars($report['report_type'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <button class="rm-action-btn t-badge t-badge-approved" onclick="viewReportUpdates(<?php echo (int)$report['id']; ?>, '<?php echo htmlspecialchars($report['report_type'], ENT_QUOTES); ?>', 'infrastructure')">
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
                            <td><span class="rm-status-badge <?php echo htmlspecialchars(strtolower($report['status'])); ?>"><?php echo ucfirst(htmlspecialchars(str_replace('-', ' ', $report['status']))); ?></span></td>
                            <td>
                                <?php echo $report['created_at'] ? date('M d, Y', strtotime($report['created_at'])) : '—'; ?>
                                <?php if (($report['status'] ?? '') === 'approved' && !empty($report['approved_at'])): ?>
                                    <br><small class="t-text-success" style="font-weight:600;">Approved: <?php echo date('M d, Y', strtotime($report['approved_at'])); ?></small>
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
                                    <div class="rm-empty-icon" style="background: rgba(249, 115, 22, 0.12);">
                                        <i class="fas fa-hard-hat t-text-cimm"></i>
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
        <?php endif; ?>

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
                        <div class="t-text-secondary" style="text-align: center; padding: 20px;">
                            <i class="fas fa-download" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                            <p>Loading reports from external systems...</p>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h6>Department Reports</h6>
                    <div id="departmentReportsList">
                        <div class="t-text-secondary" style="text-align: center; padding: 20px;">
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
                    <input type="hidden" name="report_table" id="editReportTable">

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
                                    <option value="approved">Approved</option>
                                    <option value="in-progress">In Progress</option>
                                </select>
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label for="editPriority" class="form-label">Priority *</label>
                                <select class="form-control" name="priority" id="editPriority" required>
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                </select>
                            </div>
                        </div>
                        <div style="margin-top: 15px;">
                            <button type="button" class="btn-action" onclick="openAssignUserModal()">
                                <i class="fas fa-user-plus"></i> Assign Staff to Project
                            </button>
                        </div>
                        <div style="margin-top: 15px;">
                            <label class="form-label">Assigned Staff</label>
                            <div id="assignedUsersListRegular" style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 8px;">
                                <div style="color: #6b7280; font-size: 13px;">Loading assigned staff...</div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h6><i class="fas fa-images"></i> Report Photos</h6>
                        <div id="existingPhotos" style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 12px;"></div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="editPhotos" class="form-label">Add New Photos</label>
                            <button type="button" id="add-edit-photos-btn" class="t-gradient-primary" style="padding:8px 16px;border:none;border-radius:6px;cursor:pointer;font-size:13px;"><i class="fas fa-camera"></i> Add Photos</button>
                            <input type="file" name="report_photos[]" id="editPhotos" 
                                   accept="image/jpeg,image/png,image/gif,image/webp" multiple
                                   style="display:none;">
                            <small class="t-text-secondary" style="font-size: 12px;">Accepted: JPG, PNG, GIF, WebP | Max: 5MB each</small>
                            <div id="photoPreview" style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px;"></div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h6><i class="fas fa-sticky-note"></i> Progress Notes</h6>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="editNotes" class="form-label">Update Notes / Resolution Details</label>
                            <textarea class="form-control" name="notes" id="editNotes" rows="4" 
                                      placeholder="Describe the current status, actions taken, or resolution details..."></textarea>
                            <small class="t-text-secondary" style="font-size: 12px;">
                                <i class="fas fa-info-circle"></i> These notes will be visible to other staff members
                            </small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="justify-content: space-between;">
                    <div>
                        <span id="updateStatusIndicator" class="t-text-secondary" style="font-size: 12px;"></span>
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
            <div class="modal-header t-cimm-header">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Edit CIMM Report</h5>
                <button class="close t-text-white" onclick="closeModal('editCimmModal')">&times;</button>
            </div>
            <form method="POST" id="editCimmForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="update_cimm_report">
                    <input type="hidden" name="report_id" id="editCimmReportId">
                    <input type="hidden" name="report_table" id="editCimmReportTable">

                    <div class="form-section">
                        <h6><i class="fas fa-info-circle"></i> CIMM Report Details</h6>
                        <div class="form-group">
                            <label class="form-label">Report #</label>
                            <input type="text" class="form-control t-bg-input-readonly" id="editCimmRepNumber" readonly>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Infrastructure</label>
                            <input type="text" class="form-control t-bg-input-readonly" id="editCimmInfrastructure" readonly>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Location</label>
                            <input type="text" class="form-control t-bg-input-readonly" id="editCimmLocation" readonly>
                        </div>
                    </div>

                    <div class="form-section">
                        <h6><i class="fas fa-tasks"></i> Editable Fields</h6>
                        <div style="display: flex; gap: 15px;">
                            <div class="form-group" style="flex: 1;">
                                <label class="form-label">Status *</label>
                                <select class="form-control" name="status" id="editCimmStatus" required>
                                    <?php if (!$is_road_supervisor): ?>
                                    <option value="pending">Pending</option>
                                    <?php endif; ?>
                                    <option value="approved">Approved</option>
                                    <option value="in-progress">In Progress</option>
                                    <?php if (!$is_road_supervisor): ?>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label class="form-label">Priority *</label>
                                <select class="form-control" name="priority" id="editCimmPriority" required>
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                </select>
                            </div>
                        </div>
                        <div style="margin-top: 15px;">
                            <button type="button" class="btn-action" onclick="openAssignUserModal()">
                                <i class="fas fa-user-plus"></i> Assign Staff to Project
                            </button>
                        </div>
                        <div style="margin-top: 15px;">
                            <label class="form-label">Assigned Staff</label>
                            <div id="assignedUsersListCimm" style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 8px;">
                                <div style="color: #6b7280; font-size: 13px;">Loading assigned staff...</div>
                            </div>
                        </div>
                        <div class="form-group" style="margin-top: 15px;">
                            <label class="form-label">Budget (₱)</label>
                            <input type="number" class="form-control t-bg-input-readonly" name="estimation" id="editCimmEstimation" step="0.01" min="0" placeholder="0.00" readonly>
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
                    <span id="cimmEditIndicator" class="t-text-secondary" style="font-size: 12px;"></span>
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

    <!-- View Report Modal (rm-action-btn viewport) -->
    <div id="viewReportModal" class="rm-modal-overlay" onclick="if(event.target===this)closeViewReportModal()">
        <div class="rm-modal-content">
            <div class="rm-modal-header">
                <div class="rm-modal-header-top">
                    <div class="rm-modal-title-area">
                        <div class="rm-modal-report-id" id="rm-report-id">—</div>
                        <h3 class="rm-modal-title" id="rm-title">—</h3>
                        <div class="rm-modal-badges" id="rm-badges"></div>
                    </div>
                    <button class="rm-modal-close" onclick="closeViewReportModal()">&times;</button>
                </div>
            </div>
            <div class="rm-modal-body">
                <!-- Report Information -->
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-info-circle"></i> Report Information</div>
                    <div class="rm-info-grid" id="rm-report-grid"></div>
                </div>
                <!-- Source & Department -->
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-building"></i> Source &amp; Department</div>
                    <div class="rm-info-grid" id="rm-source-grid"></div>
                </div>
                <!-- Location -->
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-map-marker-alt"></i> Location</div>
                    <div class="rm-info-grid" id="rm-location-grid"></div>
                </div>
                <!-- Description -->
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-align-left"></i> Description</div>
                    <div class="rm-description-text" id="rm-description">—</div>
                </div>
                <!-- Attachments -->
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-paperclip"></i> Attachments</div>
                    <div id="rm-attachments"></div>
                </div>
                <!-- Timeline -->
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-clock"></i> Timeline &amp; Updates</div>
                    <div class="rm-info-grid" id="rm-timeline-grid"></div>
                </div>
            </div>
            <div class="rm-modal-footer">
                <button type="button" class="rm-modal-btn-close" onclick="closeViewReportModal()">
                    <i class="fas fa-times"></i> Close
                </button>
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
                    <div class="timeline-empty"><i class="fas fa-spinner fa-spin fa-2x t-text-link"></i></div>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; flex-direction: column; gap: 16px;">
                <span id="updateReportInfo" class="t-text-secondary" style="font-size: 13px;"></span>
                <div id="actionButtons" style="display: flex; justify-content: space-between;">
                    <div style="display: flex; gap: 8px;">
                        <button class="btn-success-custom" onclick="completeReport()">Complete</button>
                        <button class="btn-danger-custom" onclick="cancelReport()">Cancel</button>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn-action" id="addUpdateBtn" onclick="showAddUpdateModal()">+ Add Update</button>
                        <button class="btn-secondary-custom" onclick="closeModal('updatesModal')">Close</button>
                    </div>
                </div>
                <div id="exportButtons" style="display: none; justify-content: flex-end; gap: 8px;">
                    <button class="btn-action" onclick="exportUpdatesToExcel()">
                        <i class="fas fa-file-excel"></i> Export
                    </button>
                    <button class="btn-secondary-custom" onclick="closeModalAndRefresh('updatesModal')">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit Update Modal -->
    <div id="addUpdateModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle"></i> <span id="addUpdateModalTitle">Add Progress Update</span></h5>
                <button class="close" onclick="cancelUpdateForm()">&times;</button>
            </div>
            <form id="addUpdateForm" enctype="multipart/form-data" onsubmit="return false;">
                <div class="modal-body">
                    <input type="hidden" name="action" id="addUpdateAction" value="create_update">
                    <input type="hidden" name="update_id" id="addUpdateId" value="">
                    <input type="hidden" name="report_id" id="addUpdateReportId" value="">
                    <input type="hidden" name="report_type" id="addUpdateReportType" value="">
                    <div class="form-group">
                        <label class="form-label">Title *</label>
                        <input type="text" name="title" id="addUpdateTitle" class="form-control" placeholder="e.g., Inspection completed" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description *</label>
                        <textarea name="description" id="addUpdateDescription" class="form-control" rows="4" placeholder="Describe the progress made..." required></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Photos / Video</label>
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                            <button type="button" id="addUpdatePhotosBtn" onclick="triggerFileUpload()" style="padding:8px 16px;background:#3762c8;color:white;border:none;border-radius:6px;cursor:pointer;font-size:13px;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-camera"></i> Add Photos</button>
                            <small class="t-text-secondary" style="font-size:11px;">Accepted: JPG, PNG, GIF, WebP, MP4, WebM</small>
                        </div>
                        <input type="file" name="media[]" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm" multiple style="display:none;">
                        <div class="file-previews" id="updateFilePreviews"></div>
                    </div>
                    <div id="existingUpdateMediaSection" style="display:none;">
                        <div class="form-group">
                            <label class="form-label">Current media (check to remove)</label>
                            <div id="existingUpdateMedia" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="justify-content: space-between;">
                    <span class="t-text-secondary" style="font-size:12px;">Updates are visible to all staff</span>
                    <div style="display:flex;gap:10px;">
                        <button type="button" class="btn-secondary-custom" onclick="cancelUpdateForm()">Cancel</button>
                        <button type="button" class="btn-action" id="addUpdateSubmitBtn" onclick="handleUpdateFormSubmit({ preventDefault: function(){}, stopPropagation: function(){}})"><i class="fas fa-save"></i> Post Update</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Lightbox -->
    <div class="lightbox-overlay" id="lightboxOverlay" onclick="closeLightbox()">
        <button class="lightbox-close" onclick="closeLightbox()">&times;</button>
        <img id="lightboxImage" src="" alt="Enlarged photo">
    </div>

    <!-- Assign User Modal -->
    <div id="assignUserModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus"></i> Assign Staff to Project</h5>
                <button class="close" onclick="closeModal('assignUserModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Available Staff</label>
                    <div id="availableUsersList" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; border-radius: 6px; padding: 10px;">
                        <div style="text-align: center; padding: 20px;">
                            <i class="fas fa-spinner fa-spin"></i> Loading staff...
                        </div>
                    </div>
                </div>
                <div class="form-group" style="margin-top: 15px;">
                    <label class="form-label">Notes (optional)</label>
                    <textarea class="form-control" id="assignmentNotes" rows="3" placeholder="Add notes about this assignment..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary-custom" onclick="closeModal('assignUserModal')">Cancel</button>
                <button type="button" class="btn-action" onclick="assignUserToProject()">
                    <i class="fas fa-check"></i> Assign
                </button>
            </div>
        </div>
    </div>

    <script>
        // CIMM data for detail viewing (read-only)
        const cimmData = <?php echo json_encode(array_values($cimm_reports_list), JSON_HEX_TAG | JSON_HEX_AMP); ?>;

        // Sort toggle state. Declared at the very top of this script so it is
        // initialized before anything below can throw — the sort functions are
        // hoisted (so onclick still fires even if a later line fails), and a
        // bare `const sortState` further down would otherwise sit in the
        // temporal dead zone and throw "Cannot access 'sortState' before
        // initialization" when the button is clicked. `var` is used (not
        // `const`) so a throw even before this line leaves sortState as
        // `undefined` instead of unreachable, which toggleSort lazily fixes.
        var sortState = { citizen: 'asc', lgu: 'asc', cimm: 'asc', infra: 'asc' };

        // Global variables for progress updates
        let updateSelectedFiles = [];
        let updatePreviewCounter = 0;
        let selectedUserForAssignment = null;
        let originalModalBeforeAssign = null;

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // The delete-confirm modal markup is rendered AFTER this script block,
        // so its input does not exist yet — bind after DOM ready and guard with
        // a null check so a missing element cannot abort the rest of the script.
        document.addEventListener('DOMContentLoaded', function() {
            const deleteInput = document.getElementById('deleteConfirmInput');
            if (!deleteInput) return;
            deleteInput.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    cancelDeleteConfirm();
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (document.getElementById('deleteConfirmBtn').classList.contains('enabled')) {
                        confirmDeleteAction();
                    }
                }
            });
        });

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                if (event.target.id === 'addUpdateModal') {
                    cancelUpdateForm();
                } else {
                    event.target.style.display = 'none';
                    document.body.style.overflow = 'auto';
                }
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

        // Report Detail Modal helpers (mirrors verification_monitoring lgu modal)
        function formatDate(dateStr) {
            if (!dateStr) return '—';
            var d = new Date(dateStr);
            if (isNaN(d.getTime())) return dateStr;
            var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
        }

        function rmBadge(text, bg, color) {
            return '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:' + bg + ';color:' + color + ';">' + text + '</span>';
        }

        function rmInfoItem(icon, label, value) {
            var displayVal = (value && value !== '—' && value !== null) ? value : '—';
            return '<div class="rm-info-item"><div class="rm-info-icon"><i class="fas fa-' + icon + '"></i></div><div><div class="rm-info-label">' + label + '</div><div class="rm-info-value">' + displayVal + '</div></div></div>';
        }

        function openViewReportModal() {
            var modal = document.getElementById('viewReportModal');
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeViewReportModal() {
            var modal = document.getElementById('viewReportModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        function openLightbox(src) {
            var lb = document.getElementById('lightboxOverlay');
            var img = document.getElementById('lightboxImage');
            img.src = src;
            lb.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function viewReport(id, type, table) {
            var url = `../api/get_report_details.php?id=${id}&type=${encodeURIComponent(type)}`;
            if (table) url += `&table=${encodeURIComponent(table)}`;
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const r = data.report;

                        var typeLabels = {
                            'traffic_jam': 'Traffic Jam',
                            'accident': 'Accident',
                            'road_damage': 'Road Damage',
                            'flooding': 'Flooding',
                            'potholes': 'Potholes',
                            'road_closure': 'Road Closure',
                            'infrastructure_issue': 'Infrastructure Issue',
                            'street_light': 'Street Light',
                            'maintenance': 'Maintenance',
                            'other': 'Other'
                        };

                        var statusStyles = {
                            'pending':    {bg:'rgba(251,191,36,0.15)', color:'#f59e0b'},
                            'approved':   {bg:'rgba(34,197,94,0.15)',  color:'#22c55e'},
                            'completed':  {bg:'rgba(34,197,94,0.15)',  color:'#22c55e'},
                            'cancelled':  {bg:'rgba(220,53,69,0.15)',  color:'#ef4444'},
                            'in-progress':{bg:'rgba(59,130,246,0.15)', color:'#3b82f6'},
                            'resolved':   {bg:'rgba(34,197,94,0.15)',  color:'#22c55e'}
                        };
                        var pStyles = {
                            'high':   {bg:'rgba(220,53,69,0.15)', color:'#ef4444'},
                            'medium': {bg:'rgba(251,191,36,0.15)', color:'#f59e0b'},
                            'low':    {bg:'rgba(34,197,94,0.15)',  color:'#22c55e'}
                        };

                        // Header
                        document.getElementById('rm-report-id').textContent = 'Report #' + (r.report_id || '—');
                        document.getElementById('rm-title').textContent = r.title || '—';

                        var st = (r.status || 'pending').toLowerCase();
                        var ss = statusStyles[st] || {bg:'rgba(107,114,128,0.15)', color:'#6b7280'};
                        var pp = (r.priority || 'medium').toLowerCase();
                        var ps = pStyles[pp] || {bg:'rgba(107,114,128,0.15)', color:'#6b7280'};

                        var badgesHtml = rmBadge(r.status || '—', ss.bg, ss.color);
                        badgesHtml += rmBadge(r.priority || '—', ps.bg, ps.color);
                        var reportType = typeLabels[r.report_type] || r.report_type || '—';
                        if (reportType !== '—') {
                            badgesHtml += '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:rgba(55,98,200,0.12);color:#3762c8;">' + reportType + '</span>';
                        }
                        document.getElementById('rm-badges').innerHTML = badgesHtml;

                        // Report Information
                        var reportGrid = '';
                        reportGrid += rmInfoItem('folder', 'Report Type', reportType);
                        reportGrid += rmInfoItem('calendar-alt', 'Created Date', formatDate(r.created_at));
                        reportGrid += rmInfoItem('sync-alt', 'Last Updated', formatDate(r.updated_at));
                        if (r.due_date) {
                            reportGrid += rmInfoItem('clock', 'Due Date', formatDate(r.due_date));
                        }
                        if (r.severity) {
                            reportGrid += rmInfoItem('exclamation-circle', 'Severity', r.severity);
                        }
                        document.getElementById('rm-report-grid').innerHTML = reportGrid;

                        // Source & Department
                        var sourceGrid = '';
                        var sourceLabel = (r.source_system === 'cimm') ? 'CIMM' : (r.source_system === 'maintenance') ? 'Maintenance' : (r.source_system === 'lgu_reports') ? 'LGU Staff' : (r.source_system === 'transport') ? 'Citizen' : (r.report_source === 'local') ? 'LGU Staff' : 'Citizen';
                        sourceGrid += rmInfoItem('server', 'Source', sourceLabel);
                        sourceGrid += rmInfoItem('building', 'Department', r.department);
                        if (r.assigned_to) {
                            sourceGrid += rmInfoItem('user-cog', 'Assigned To', r.assigned_to);
                        }
                        if (r.reporter_name) {
                            sourceGrid += rmInfoItem('user', 'Reported By', r.reporter_name);
                        }
                        if (r.approved_at) {
                            sourceGrid += rmInfoItem('thumbs-up', 'Approved At', formatDate(r.approved_at));
                        }
                        if (r.rejected_at) {
                            sourceGrid += rmInfoItem('thumbs-down', 'Rejected At', formatDate(r.rejected_at));
                        }
                        if (r.report_category === 'road') {
                            var engName = r.cimm_engineer_name || r.engineer || '';
                            if (engName) {
                                sourceGrid += rmInfoItem('hard-hat', 'CIMM Engineer', engName);
                            }
                            var budgetRaw = (r.cimm_budget && Number(r.cimm_budget) > 0)
                                ? r.cimm_budget
                                : (r.budget_allocation && Number(r.budget_allocation) > 0 ? r.budget_allocation : 0);
                            if (budgetRaw) {
                                sourceGrid += rmInfoItem('money-bill-wave', 'CIMM Budget Allocation', '₱ ' + Number(budgetRaw).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
                            }
                        }
                        document.getElementById('rm-source-grid').innerHTML = sourceGrid;

                        // Location
                        var locationGrid = '';
                        var locVal = r.location || '—';
                        if (r.latitude && r.longitude && r.latitude != 0 && r.longitude != 0) {
                            locVal += '<br><a href="https://www.openstreetmap.org/?mlat=' + r.latitude + '&mlon=' + r.longitude + '&zoom=15" target="_blank" style="color:#3762c8;font-size:12px;text-decoration:none;"><i class="fas fa-external-link-alt" style="font-size:10px;"></i> View on Map</a>';
                        }
                        locationGrid += '<div class="rm-info-item rm-info-value-full"><div class="rm-info-icon"><i class="fas fa-map-marker-alt"></i></div><div><div class="rm-info-label">Location</div><div class="rm-info-value">' + locVal + '</div></div></div>';
                        document.getElementById('rm-location-grid').innerHTML = locationGrid;

                        // Description
                        document.getElementById('rm-description').textContent = r.description || 'No description provided.';

                        // Attachments
                        var images = [];
                        var seenPaths = new Set();
                        if (r.image_path && r.image_path !== '0' && r.image_path !== 'null') {
                            images.push('../../' + r.image_path);
                            seenPaths.add(r.image_path);
                        }
                        if (r.attachments && typeof r.attachments === 'string') {
                            try {
                                var parsed = JSON.parse(r.attachments);
                                if (Array.isArray(parsed)) {
                                    parsed.forEach(function(a) {
                                        var p = a.file_path || a.file || '';
                                        if (p && (a.type === 'image' || !a.type) && !seenPaths.has(p)) {
                                            images.push('../../' + p);
                                            seenPaths.add(p);
                                        }
                                    });
                                }
                            } catch(e) {}
                        }
                        if (r.update_media && Array.isArray(r.update_media)) {
                            r.update_media.forEach(function(m) {
                                var p = m.file_path || '';
                                if (p && !seenPaths.has(p) && m.file_type !== 'video') {
                                    images.push('../../' + p);
                                    seenPaths.add(p);
                                }
                            });
                        }
                        var attachHtml = '';
                        if (images.length > 0) {
                            attachHtml = '<div style="display:flex;gap:12px;flex-wrap:wrap;">';
                            images.forEach(function(path) {
                                attachHtml += '<div style="border-radius:8px;overflow:hidden;max-width:200px;"><img src="' + path + '" alt="Report Photo" style="width:100%;height:auto;cursor:pointer;" onclick="openLightbox(this.src)" loading="lazy" onerror="this.style.display=\'none\'"></div>';
                            });
                            attachHtml += '</div>';
                        } else {
                            attachHtml = '<div style="padding:8px 0;color:#9ca3af;font-size:14px;">No attachments.</div>';
                        }
                        document.getElementById('rm-attachments').innerHTML = attachHtml;

                        // Timeline
                        var timelineGrid = '';
                        timelineGrid += rmInfoItem('calendar-check', 'Created', formatDate(r.created_at));
                        if (r.approved_at) {
                            timelineGrid += rmInfoItem('thumbs-up', 'Approved', formatDate(r.approved_at));
                        }
                        if (r.rejected_at) {
                            timelineGrid += rmInfoItem('thumbs-down', 'Rejected', formatDate(r.rejected_at));
                        }
                        if (r.completed_at) {
                            timelineGrid += rmInfoItem('check-circle', 'Completed', formatDate(r.completed_at));
                        }
                        if (r.updated_at) {
                            timelineGrid += rmInfoItem('edit', 'Last Updated', formatDate(r.updated_at));
                        }
                        document.getElementById('rm-timeline-grid').innerHTML = timelineGrid;

                        openViewReportModal();
                    } else {
                        showNotification('Failed to load report details', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Error loading report details', 'error');
                });
        }

        function closeLightbox() {
            document.getElementById('lightboxOverlay').classList.remove('show');
        }

        function escapeHtml(text) {
            if (!text) return '';
            return text
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function loadAssignedUsers() {
            // Try to get values from both modals, use whichever has a valid reportId
            let reportId = document.getElementById('editCimmReportId')?.value || document.getElementById('editReportId')?.value;
            let reportType = document.getElementById('editCimmReportTable')?.value || document.getElementById('editReportTable')?.value;
            
            // Determine which container to use based on which modal is open
            const cimmModal = document.getElementById('editCimmModal');
            const isCimmModal = cimmModal && cimmModal.style.display === 'block';
            const container = document.getElementById(isCimmModal ? 'assignedUsersListCimm' : 'assignedUsersListRegular');
            
            console.log('loadAssignedUsers: reportId=', reportId, 'reportType=', reportType, 'container:', container, 'isCimmModal:', isCimmModal);
            
            if (!container) {
                console.error('assignedUsersList container not found!');
                return;
            }
            
            if (!reportId || !reportType) {
                container.innerHTML = '<div style="color: #6b7280; font-size: 13px;">No report selected</div>';
                return;
            }
            
            container.innerHTML = '<div style="color: #6b7280; font-size: 13px;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
            
            fetch(`../api/get_assigned_users.php?report_id=${reportId}&report_type=${encodeURIComponent(reportType)}`)
                .then(r => {
                    console.log('Response status:', r.status);
                    return r.json();
                })
                .then(data => {
                    console.log('Assigned users data:', data);
                    if (data.success) {
                        console.log('Assignments count:', data.assignments.length);
                        if (data.assignments.length === 0) {
                            container.innerHTML = `
                                <div style="display: flex; align-items: center; gap: 8px; padding: 12px; background: #f9fafb; border-radius: 6px; border: 1px dashed #d1d5db;">
                                    <i class="fas fa-user-slash" style="color: #9ca3af; font-size: 16px;"></i>
                                    <span style="color: #6b7280; font-size: 13px;">No staff assigned yet</span>
                                </div>
                            `;
                        } else {
                            console.log('Rendering', data.assignments.length, 'assignments');
                            container.innerHTML = '';
                            console.log('Container cleared, innerHTML:', container.innerHTML);
                            try {
                                data.assignments.forEach(assignment => {
                                    console.log('Rendering assignment:', assignment);
                                    const userDiv = document.createElement('div');
                                    userDiv.style.cssText = 'display: flex; align-items: center; gap: 12px; padding: 12px 16px; background: linear-gradient(135deg, #f0f4fa 0%, #e8f0fe 100%); border-radius: 8px; border: 1px solid #dbeafe; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: all 0.2s;';
                                    userDiv.onmouseover = function() { this.style.boxShadow = '0 4px 6px rgba(0,0,0,0.1)'; this.style.transform = 'translateY(-1px)'; };
                                    userDiv.onmouseout = function() { this.style.boxShadow = '0 1px 3px rgba(0,0,0,0.05)'; this.style.transform = 'translateY(0)'; };
                                    
                                    // Determine role icon and color
                                    let roleIcon = 'fa-user';
                                    let roleColor = '#3762c8';
                                    if (assignment.role === 'road_monitoring_officer') {
                                        roleIcon = 'fa-road';
                                        roleColor = '#f59e0b';
                                    } else if (assignment.role === 'trans_monitoring_officer') {
                                        roleIcon = 'fa-bus';
                                        roleColor = '#10b981';
                                    }
                                    
                                    userDiv.innerHTML = `
                                        <div style="width: 40px; height: 40px; background: ${roleColor}20; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                            <i class="fas ${roleIcon}" style="color: ${roleColor}; font-size: 18px;"></i>
                                        </div>
                                        <div style="flex: 1;">
                                            <div style="font-weight: 600; font-size: 14px; color: #1f2937;">${escapeHtml(assignment.full_name)}</div>
                                            <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">
                                                <i class="fas fa-calendar-alt" style="margin-right: 4px;"></i>
                                                Assigned: ${new Date(assignment.assigned_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                                            </div>
                                            <div style="font-size: 11px; color: #9ca3af; margin-top: 2px;">
                                                <i class="fas fa-id-badge" style="margin-right: 4px;"></i>
                                                ${escapeHtml(assignment.role.replace('_', ' ').toUpperCase())}
                                            </div>
                                        </div>
                                        <button type="button" onclick="removeAssignment(${assignment.id}, '${escapeHtml(assignment.full_name)}')" 
                                                style="padding: 6px 12px; background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 500; display: flex; align-items: center; gap: 6px; transition: all 0.2s; box-shadow: 0 2px 4px rgba(220, 53, 69, 0.2);"
                                                onmouseover="this.style.boxShadow='0 4px 8px rgba(220, 53, 69, 0.3)'; this.style.transform='translateY(-1px)';"
                                                onmouseout="this.style.boxShadow='0 2px 4px rgba(220, 53, 69, 0.2)'; this.style.transform='translateY(0)';">
                                            <i class="fas fa-user-minus"></i> Remove
                                        </button>
                                    `;
                                    console.log('User div created, appending to container');
                                    container.appendChild(userDiv);
                                    console.log('User div appended, container children count:', container.children.length);
                                });
                                console.log('Rendering complete, container children count:', container.children.length);
                            } catch (e) {
                                console.error('Error rendering assignments:', e);
                                container.innerHTML = `<div style="color: #dc3545; font-size: 13px;">Error rendering: ${e.message}</div>`;
                            }
                        }
                    } else {
                        container.innerHTML = `
                            <div style="display: flex; align-items: center; gap: 8px; padding: 12px; background: #fef2f2; border-radius: 6px; border: 1px solid #fecaca;">
                                <i class="fas fa-exclamation-circle" style="color: #dc3545; font-size: 16px;"></i>
                                <span style="color: #dc3545; font-size: 13px;">${escapeHtml(data.message)}</span>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error loading assigned users:', error);
                    container.innerHTML = `
                        <div style="display: flex; align-items: center; gap: 8px; padding: 12px; background: #fef2f2; border-radius: 6px; border: 1px solid #fecaca;">
                            <i class="fas fa-exclamation-circle" style="color: #dc3545; font-size: 16px;"></i>
                            <span style="color: #dc3545; font-size: 13px;">Failed to load assigned staff</span>
                        </div>
                    `;
                });
        }

        function removeAssignment(assignmentId, userName) {
            if (!confirm(`Are you sure you want to unassign ${userName} from this project?`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('assignment_id', assignmentId);
            
            fetch('../api/remove_assignment.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showNotification(`${userName} unassigned successfully`, 'success');
                    loadAssignedUsers();
                } else {
                    showNotification(data.message || 'Failed to unassign user', 'error');
                }
            })
            .catch(error => {
                console.error('Error removing assignment:', error);
                showNotification('Failed to unassign user', 'error');
            });
        }

        function openAssignUserModal() {
            // Store which modal is currently open
            const cimmModal = document.getElementById('editCimmModal');
            const regularModal = document.getElementById('editReportModal');
            
            if (cimmModal && cimmModal.style.display === 'block') {
                originalModalBeforeAssign = 'cimm';
            } else if (regularModal && regularModal.style.display === 'block') {
                originalModalBeforeAssign = 'regular';
            } else {
                originalModalBeforeAssign = null;
            }
            
            let reportId, reportType;
            
            if (originalModalBeforeAssign === 'cimm') {
                // CIMM modal
                reportId = document.getElementById('editCimmReportId').value;
                reportType = document.getElementById('editCimmReportTable').value;
            } else {
                // Regular edit modal
                reportId = document.getElementById('editReportId').value;
                reportType = document.getElementById('editReportTable').value;
            }
            
            console.log('openAssignUserModal called with reportId:', reportId, 'reportType:', reportType, 'originalModal:', originalModalBeforeAssign);
            
            if (!reportId || !reportType) {
                showNotification('Please save the report first before assigning users', 'error');
                return;
            }
            
            closeModal('editReportModal');
            openModal('assignUserModal');
            
            // Load available users
            const usersList = document.getElementById('availableUsersList');
            usersList.innerHTML = '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading staff...</div>';
            
            fetch(`../api/get_assignable_users.php?report_id=${reportId}&report_type=${encodeURIComponent(reportType)}`)
                .then(r => r.json())
                .then(data => {
                    console.log('Report category debug:', data.report_category, 'Target role:', data.target_role);
                    if (data.success) {
                        if (data.users.length === 0) {
                            usersList.innerHTML = '<div style="text-align: center; padding: 20px; color: #666;">No staff available for this project type</div>';
                        } else {
                            usersList.innerHTML = '';
                            data.users.forEach(user => {
                                const userDiv = document.createElement('div');
                                userDiv.style.cssText = 'display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-bottom: 1px solid #e5e7eb; cursor: pointer; transition: all 0.2s;';
                                userDiv.style.backgroundColor = user.already_assigned ? '#f3f4f6' : '#fff';
                                userDiv.onclick = function() {
                                    if (!user.already_assigned) {
                                        selectUserForAssignment(user.id, user.full_name);
                                    }
                                };
                                
                                // Determine role icon and color
                                let roleIcon = 'fa-user';
                                let roleColor = '#3762c8';
                                if (user.role === 'road_monitoring_officer') {
                                    roleIcon = 'fa-road';
                                    roleColor = '#f59e0b';
                                } else if (user.role === 'trans_monitoring_officer') {
                                    roleIcon = 'fa-bus';
                                    roleColor = '#10b981';
                                }
                                
                                userDiv.innerHTML = `
                                    <div style="width: 44px; height: 44px; background: ${roleColor}15; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid ${roleColor}30;">
                                        <i class="fas ${roleIcon}" style="color: ${roleColor}; font-size: 20px;"></i>
                                    </div>
                                    <div style="flex: 1;">
                                        <div style="font-weight: 600; font-size: 14px; color: #1f2937;">${escapeHtml(user.full_name)}</div>
                                        <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">
                                            <i class="fas fa-envelope" style="margin-right: 4px;"></i>
                                            ${escapeHtml(user.email)}
                                        </div>
                                        <div style="font-size: 11px; color: #9ca3af; margin-top: 2px;">
                                            <i class="fas fa-id-badge" style="margin-right: 4px;"></i>
                                            ${escapeHtml(user.role.replace('_', ' ').toUpperCase())}
                                        </div>
                                    </div>
                                    <div style="text-align: right; min-width: 80px;">
                                        <div style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">
                                            <i class="fas fa-tasks" style="margin-right: 4px;"></i>
                                            Active: <strong>${user.active_assignments}</strong>
                                        </div>
                                        ${user.already_assigned 
                                            ? '<span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; background: #d1fae5; color: #059669; border-radius: 12px; font-size: 11px; font-weight: 500;"><i class="fas fa-check-circle"></i> Assigned</span>' 
                                            : '<span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; background: #dbeafe; color: #2563eb; border-radius: 12px; font-size: 11px; font-weight: 500;"><i class="fas fa-plus-circle"></i> Assign</span>'}
                                    </div>
                                `;
                                usersList.appendChild(userDiv);
                            });
                        }
                    } else {
                        usersList.innerHTML = `<div style="text-align: center; padding: 20px; color: #dc3545;">${escapeHtml(data.message)}</div>`;
                    }
                })
                .catch(error => {
                    console.error('Error loading users:', error);
                    usersList.innerHTML = '<div style="text-align: center; padding: 20px; color: #dc3545;">Failed to load staff</div>';
                });
        }

        function selectUserForAssignment(userId, userName) {
            // Check if already selected, if so deselect
            if (selectedUserForAssignment && selectedUserForAssignment.id === userId) {
                selectedUserForAssignment = null;
                // Update UI to remove selection
                const usersList = document.getElementById('availableUsersList');
                Array.from(usersList.children).forEach(child => {
                    child.style.backgroundColor = '#fff';
                });
                return;
            }

            selectedUserForAssignment = { id: userId, name: userName };
            
            // Update UI to show selected user
            const usersList = document.getElementById('availableUsersList');
            Array.from(usersList.children).forEach(child => {
                child.style.backgroundColor = '#fff';
            });
            event.currentTarget.style.backgroundColor = '#e3f2fd';
        }

        function assignUserToProject() {
            if (!selectedUserForAssignment) {
                showNotification('Please select a staff member to assign', 'error');
                return;
            }
            
            // Check which modal is currently open
            const cimmModal = document.getElementById('editCimmModal');
            const isCimmModal = cimmModal && cimmModal.style.display === 'block';
            
            let reportId, reportType;
            
            if (isCimmModal) {
                // CIMM modal
                reportId = document.getElementById('editCimmReportId').value;
                reportType = document.getElementById('editCimmReportTable').value;
            } else {
                // Regular edit modal
                reportId = document.getElementById('editReportId').value;
                reportType = document.getElementById('editReportTable').value;
            }
            
            const notes = document.getElementById('assignmentNotes').value;
            
            const formData = new FormData();
            formData.append('report_id', reportId);
            formData.append('report_type', reportType);
            formData.append('user_id', selectedUserForAssignment.id);
            formData.append('notes', notes);
            
            fetch('../api/assign_user_to_project.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showNotification(`${selectedUserForAssignment.name} assigned successfully`, 'success');
                    closeModal('assignUserModal');
                    document.getElementById('assignmentNotes').value = '';
                    selectedUserForAssignment = null;
                    
                    // Reopen the original modal
                    if (originalModalBeforeAssign === 'cimm') {
                        openModal('editCimmModal');
                    } else {
                        openModal('editReportModal');
                    }
                    
                    loadAssignedUsers();
                } else {
                    showNotification(data.message || 'Failed to assign user', 'error');
                }
            })
            .catch(error => {
                console.error('Error assigning user:', error);
                showNotification('Failed to assign user', 'error');
            });
        }

        function triggerFileUpload() {
            var fileInput = document.querySelector('#addUpdateForm input[type="file"]');
            if (fileInput) {
                var newInput = fileInput.cloneNode(true);
                fileInput.parentNode.replaceChild(newInput, fileInput);
                newInput.addEventListener('change', function(e) {
                    var newFiles = Array.from(e.target.files);
                    newFiles.forEach(function(f) {
                        if (updateSelectedFiles.indexOf(f) === -1) {
                            updateSelectedFiles.push(f);
                        }
                    });
                    e.target.value = '';
                    renderUpdateFilePreviews();
                });
                newInput.click();
            }
        }

        function viewReportUpdates(id, type, source) {
            currentUpdatesReportId = id;
            currentUpdatesReportType = type;
            currentUpdatesReportSource = source;
            document.getElementById('updateReportInfo').textContent = 'Report #' + id;
            openModal('updatesModal');
            if (typeof loadUpdates === 'function') {
                loadUpdates(id, type);
            }
            // Check if user can add updates and show/hide the Add Update button accordingly
            checkUpdatePermission();
        }

        function checkUpdatePermission() {
            // Server-authoritative check (role + assignment): Road/Transportation
            // Monitoring Officers can only post updates to reports assigned to
            // them. The same rule is enforced server-side in progress_update_api.php.
            // Officers are fail-closed: the button stays hidden until the server
            // confirms an active assignment for this report.
            var btn = document.getElementById('addUpdateBtn');
            if (!btn) return;
            var role = '';
            var tag = document.getElementById('sessionTimeoutData');
            if (tag) role = tag.getAttribute('data-role') || '';
            var isOfficer = (role === 'road_monitoring_officer' || role === 'trans_monitoring_officer');

            if (!currentUpdatesReportId) {
                btn.style.display = isOfficer ? 'none' : 'inline-flex';
                return;
            }
            if (isOfficer) {
                btn.style.display = 'none';
            } else {
                btn.style.display = 'inline-flex';
            }

            fetch('../api/progress_update_api.php?action=can_post_update&report_id=' + currentUpdatesReportId)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.success && data.can_post) {
                        btn.style.display = 'inline-flex';
                    } else {
                        btn.style.display = 'none';
                    }
                })
                .catch(function() {});
        }

        function showAddUpdateModal() {
            document.getElementById('addUpdateAction').value = 'create_update';
            document.getElementById('addUpdateId').value = '';
            document.getElementById('addUpdateReportId').value = currentUpdatesReportId;
            document.getElementById('addUpdateReportType').value = currentUpdatesReportType;
            document.getElementById('addUpdateTitle').value = '';
            document.getElementById('addUpdateDescription').value = '';
            document.getElementById('updateFilePreviews').innerHTML = '';
            document.getElementById('existingUpdateMediaSection').style.display = 'none';
            document.getElementById('existingUpdateMedia').innerHTML = '';
            document.getElementById('addUpdateModalTitle').textContent = 'Add Progress Update';
            document.getElementById('addUpdateSubmitBtn').innerHTML = '<i class="fas fa-save"></i> Post Update';
            updateSelectedFiles = [];
            updatePreviewCounter = 0;
            closeModal('updatesModal');
            openModal('addUpdateModal');
        }

        function cancelUpdateForm() {
            closeModal('addUpdateModal');
            openModal('updatesModal');
            if (typeof loadUpdates === 'function') {
                loadUpdates(currentUpdatesReportId, currentUpdatesReportType);
            }
        }

        // Override showUpdateForm from progress-updates.js to use modal
        function showUpdateForm(reportId, reportType, updateData) {
            const isEdit = updateData && updateData.id;
            document.getElementById('addUpdateAction').value = isEdit ? 'edit_update' : 'create_update';
            document.getElementById('addUpdateId').value = isEdit ? updateData.id : '';
            document.getElementById('addUpdateReportId').value = reportId;
            document.getElementById('addUpdateReportType').value = reportType;
            document.getElementById('addUpdateTitle').value = isEdit ? (updateData.title || '') : '';
            document.getElementById('addUpdateDescription').value = isEdit ? (updateData.description || '') : '';
            document.getElementById('updateFilePreviews').innerHTML = '';
            document.getElementById('addUpdateModalTitle').textContent = isEdit ? 'Edit Update' : 'Add Progress Update';
            document.getElementById('addUpdateSubmitBtn').innerHTML = isEdit ? '<i class="fas fa-save"></i> Save Changes' : '<i class="fas fa-save"></i> Post Update';
            updateSelectedFiles = [];
            updatePreviewCounter = 0;

            var removedMediaIds = [];

            if (isEdit && updateData.media) {
                const mediaContainer = document.getElementById('existingUpdateMedia');
                mediaContainer.innerHTML = '';
                document.getElementById('existingUpdateMediaSection').style.display = '';
                updateData.media.forEach(function(m) {
                    const div = document.createElement('div');
                    div.style.cssText = 'position:relative;width:80px;height:60px;border-radius:6px;overflow:hidden;border:1px solid rgba(55,98,200,0.15);flex-shrink:0;';
                    const isVideo = m.file_type === 'video';
                    div.innerHTML = isVideo
                        ? '<i class="fas fa-video" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:20px;color:#3762c8;opacity:0.5;"></i>'
                        : '<img src="../../' + m.file_path.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;') + '" style="width:100%;height:100%;object-fit:cover;">';
                    var removeBtn = document.createElement('button');
                    removeBtn.type = 'button';
                    removeBtn.style.cssText = 'position:absolute;top:2px;right:2px;width:18px;height:18px;border-radius:50%;border:none;background:rgba(220,53,69,0.9);color:#fff;font-size:14px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;z-index:2;';
                    removeBtn.innerHTML = '&times;';
                    removeBtn.title = 'Remove this photo';
                    removeBtn.addEventListener('click', function(mediaId) {
                        return function() {
                            if (removedMediaIds.indexOf(mediaId) === -1) {
                                removedMediaIds.push(mediaId);
                            }
                            div.style.transition = 'transform 0.2s ease, opacity 0.2s ease';
                            div.style.transform = 'scale(0.5)';
                            div.style.opacity = '0';
                            setTimeout(function() { div.remove(); }, 200);
                        };
                    }(m.id));
                    div.appendChild(removeBtn);
                    mediaContainer.appendChild(div);
                });
            } else {
                document.getElementById('existingUpdateMediaSection').style.display = 'none';
                document.getElementById('existingUpdateMedia').innerHTML = '';
            }

            // Store removedMediaIds on the form for later use during submit
            var form = document.getElementById('addUpdateForm');
            form._removedMediaIds = removedMediaIds;

            closeModal('updatesModal');
            openModal('addUpdateModal');
        }

        // Override handleUpdateFormSubmit from progress-updates.js for modal flow
        function handleUpdateFormSubmit(e) {
            e.preventDefault();
            e.stopPropagation();
            const btn = document.getElementById('addUpdateSubmitBtn');
            const orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            var form = document.getElementById('addUpdateForm');

            var removedIds = form._removedMediaIds || [];
            document.querySelectorAll('#addUpdateForm input[name="remove_media[]"]').forEach(function(el) { el.remove(); });
            removedIds.forEach(function(id) {
                var h = document.createElement('input');
                h.type = 'hidden';
                h.name = 'remove_media[]';
                h.value = id;
                form.appendChild(h);
            });

            var dt = new DataTransfer();
            updateSelectedFiles.forEach(function(f) { dt.items.add(f); });
            var fileInput = form.querySelector('input[type="file"]');
            if (fileInput) fileInput.files = dt.files;

            const fd = new FormData(form);
            fetch('../api/progress_update_api.php', {
                method: 'POST',
                body: fd
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    showNotification(data.message, 'success');
                    closeModal('addUpdateModal');
                    openModal('updatesModal');
                    if (typeof loadUpdates === 'function') {
                        loadUpdates(currentUpdatesReportId, currentUpdatesReportType);
                    }
                } else {
                    showNotification(data.message || 'Failed to save update', 'error');
                }
            })
            .catch(function(e) {
                showNotification('Network error', 'error');
                console.error(e);
            })
            .finally(function() { btn.disabled = false; btn.innerHTML = orig; });
        }

        // Attach submit handler to add update form directly
        window.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('addUpdateForm');
            if (form) {
                form.addEventListener('submit', handleUpdateFormSubmit);
                console.log('Submit handler attached to addUpdateForm');
            } else {
                console.log('addUpdateForm not found on DOMContentLoaded');
            }
        });

        // Add Photos button triggers hidden file input
        document.addEventListener('click', function(e) {
            if (e.target && e.target.id === 'addUpdatePhotosBtn') {
                var fileInput = document.querySelector('#addUpdateForm input[type="file"]');
                if (fileInput) fileInput.click();
            }
        });

        var isCompleting = false; // Flag to prevent multiple clicks

        function validateSupervisorComplete(reportId, reportType, source, callback) {
            // The completion gate applies only to the Road Operations Supervisor.
            var role = '';
            var tag = document.getElementById('sessionTimeoutData');
            if (tag) role = tag.getAttribute('data-role') || '';
            if (role !== 'road_ops_supervisor') { callback(true); return; }

            fetch('../api/progress_update_api.php?action=can_complete_report&report_id=' + encodeURIComponent(reportId) + '&report_type=' + encodeURIComponent(reportType) + '&source=' + encodeURIComponent(source))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!(data && data.success)) {
                        showNotification(data && data.message ? data.message : 'Unable to verify completion eligibility', 'error');
                        callback(false); return;
                    }
                    if (!data.can_complete) {
                        showNotification(data.message || 'Cannot complete: an officer must be assigned or progress updates must be added first', 'error');
                        callback(false); return;
                    }
                    callback(true);
                })
                .catch(function() { showNotification('Unable to verify completion eligibility', 'error'); callback(false); });
        }

        function completeReport() {
            if (!currentUpdatesReportId) return;
            if (isCompleting) return; // Prevent multiple clicks
            isCompleting = true;
            validateSupervisorComplete(currentUpdatesReportId, currentUpdatesReportType, currentUpdatesReportSource, function(allowed) {
                if (!allowed) { isCompleting = false; return; }
                finishCompleteReport();
            });
        }

        function finishCompleteReport() {
            
            var today = new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
            
            // Check if there's already a "Completed" update to prevent duplicates
            const timelineEntries = document.querySelectorAll('.timeline-entry');
            var alreadyCompleted = false;
            timelineEntries.forEach(function(entry) {
                const title = entry.querySelector('.timeline-title')?.textContent.trim() || '';
                if (title.toLowerCase() === 'completed') {
                    alreadyCompleted = true;
                }
            });

            if (alreadyCompleted) {
                showNotification('Report is already marked as completed', 'info');
                // Just update the status and hide buttons
                updateStatusOnly();
                isCompleting = false;
                return;
            }
            
            // First add the completion update
            var updateFormData = new FormData();
            updateFormData.append('action', 'create_update');
            updateFormData.append('report_id', currentUpdatesReportId);
            updateFormData.append('report_type', currentUpdatesReportType);
            updateFormData.append('source', currentUpdatesReportSource);
            updateFormData.append('title', 'Completed');
            updateFormData.append('description', 'completed on ' + today);

            fetch('../api/progress_update_api.php', {
                method: 'POST',
                body: updateFormData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    // Now update the status
                    updateStatusOnly();
                } else {
                    throw new Error(data.message || 'Failed to add completion update');
                }
            })
            .catch(function(e) {
                showNotification('Network error', 'error');
                console.error(e);
                isCompleting = false;
            });
        }

        function updateStatusOnly() {
            var newStatus = (currentUpdatesReportSource === 'cimm') ? 'Completed' : 'completed';
            var statusFormData = new FormData();
            // Same behavior as road_transportation_monitoring.php: update the
            // live row to completed (it stays in place), then file a completed
            // copy into the archive. Nothing is moved or deleted.
            statusFormData.append('action', 'update_status');
            statusFormData.append('report_id', currentUpdatesReportId);
            statusFormData.append('report_type', currentUpdatesReportType);
            statusFormData.append('status', newStatus);
            statusFormData.append('source', currentUpdatesReportSource);

            fetch('../api/progress_update_api.php', {
                method: 'POST',
                body: statusFormData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    showNotification('Report completed successfully', 'success');
                    // Hide action buttons, show export button
                    document.getElementById('actionButtons').style.display = 'none';
                    document.getElementById('exportButtons').style.display = 'flex';
                    // Reload updates timeline
                    if (typeof loadUpdates === 'function') {
                        loadUpdates(currentUpdatesReportId, currentUpdatesReportType);
                    }
                    // File a completed copy of the report into the archive.
                    // Purely additive — the live report is not moved or deleted.
                    var archiveFormData = new FormData();
                    archiveFormData.append('action', 'complete_archive');
                    archiveFormData.append('report_id', currentUpdatesReportId);
                    archiveFormData.append('report_type', currentUpdatesReportType);
                    archiveFormData.append('source', currentUpdatesReportSource);
                    fetch('../api/progress_update_api.php', {
                        method: 'POST',
                        body: archiveFormData
                    }).catch(function(e) { console.error('Archive copy failed', e); });
                } else {
                    showNotification(data.message || 'Failed to update status', 'error');
                }
                isCompleting = false; // Reset flag
            })
            .catch(function(e) {
                showNotification('Network error', 'error');
                console.error(e);
                isCompleting = false; // Reset flag
            });
        }

        function cancelReport() {
            if (!currentUpdatesReportId) return;
            
            var newStatus = (currentUpdatesReportSource === 'cimm') ? 'Cancelled' : 'cancelled';
            var formData = new FormData();
            // Cancel MOVES the report into the archive as 'cancelled' and
            // removes it from the live table, so it disappears from
            // report_management.php and appears only in archive.php (no
            // duplicate across the two pages).
            formData.append('action', 'cancel_archive');
            formData.append('report_id', currentUpdatesReportId);
            formData.append('report_type', currentUpdatesReportType);
            formData.append('status', newStatus);
            formData.append('source', currentUpdatesReportSource);

            fetch('../api/progress_update_api.php', {
                method: 'POST',
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    showNotification(data.message, 'success');
                    closeModal('updatesModal');
                    location.reload();
                } else {
                    showNotification(data.message || 'Failed to update status', 'error');
                }
            })
            .catch(function(e) {
                showNotification('Network error', 'error');
                console.error(e);
            });
        }

        function closeModalAndRefresh(modalId) {
            closeModal(modalId);
            location.reload();
        }

        // File preview for add update modal — maintains persistent upload queue
        document.addEventListener('change', function(e) {
            if (e.target && e.target.matches && e.target.matches('#addUpdateForm input[type="file"]')) {
                var newFiles = Array.from(e.target.files);
                newFiles.forEach(function(f) {
                    if (updateSelectedFiles.indexOf(f) === -1) {
                        updateSelectedFiles.push(f);
                    }
                });
                e.target.value = '';
                renderUpdateFilePreviews();
            }
        });

        function renderUpdateFilePreviews() {
            var preview = document.getElementById('updateFilePreviews');
            if (!preview) return;
            updatePreviewCounter++;
            var currentRender = updatePreviewCounter;
            preview.innerHTML = '';
            if (updateSelectedFiles.length === 0) return;
            updateSelectedFiles.forEach(function(f, index) {
                var item = document.createElement('div');
                item.className = 'file-preview-item';
                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'remove-preview';
                removeBtn.innerHTML = '&times;';
                removeBtn.addEventListener('click', function(idx) {
                    return function() {
                        if (idx >= 0 && idx < updateSelectedFiles.length) {
                            updateSelectedFiles.splice(idx, 1);
                        }
                        renderUpdateFilePreviews();
                    };
                }(index));
                if (f.type.startsWith('image/')) {
                    var reader = new FileReader();
                    reader.onload = function(ev) {
                        if (currentRender !== updatePreviewCounter) return;
                        item.innerHTML = '<img src="' + ev.target.result + '">';
                        item.appendChild(removeBtn);
                    };
                    reader.readAsDataURL(f);
                } else if (f.type.startsWith('video/')) {
                    item.innerHTML = '<i class="fas fa-video"></i>';
                    item.appendChild(removeBtn);
                }
                preview.appendChild(item);
            });
        }

        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = 'notification ' + type;
            notification.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle') + '"></i> ' + message;
            notification.style.cssText = 'position:fixed;top:20px;right:20px;padding:15px 20px;background:' + (type === 'success' ? '#10b981' : type === 'error' ? '#dc3545' : '#3762c8') + ';color:white;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);z-index:10000;animation:slideIn 0.3s ease;';
            document.body.appendChild(notification);
            setTimeout(function() {
                notification.style.opacity = '0';
                notification.style.transition = 'opacity 0.3s ease';
                setTimeout(function() { notification.remove(); }, 300);
            }, 3000);
        }

        var editSelectedFiles = [];

        function editReport(id, type, table) {
            // Save Changes (edit) is restricted to the Road and Transportation
            // Operations Supervisors.
            var role = '';
            var tag = document.getElementById('sessionTimeoutData');
            if (tag) role = tag.getAttribute('data-role') || '';
            if (role !== 'road_ops_supervisor' && role !== 'trans_ops_supervisor' && role !== 'system_admin') {
                showNotification('Only the Road/Transportation Operations Supervisors can edit reports.', 'error');
                return;
            }
            fetch(`../api/get_report_details.php?id=${id}&type=${encodeURIComponent(type)}&table=${encodeURIComponent(table)}&_=${Date.now()}`)
                .then(response => {
                    if (!response.ok) {
                        return response.text().then(text => {
                            throw new Error(`HTTP ${response.status}: ${text.substring(0, 200)}`);
                        });
                    }
                    return response.text().then(text => {
                        try {
                            return JSON.parse(text);
                        } catch(e) {
                            throw new Error(`Invalid JSON (${text.substring(0, 200)})`);
                        }
                    });
                })
                .then(data => {
                    if (data.success) {
                        document.getElementById('editReportId').value = data.report.id;
                        document.getElementById('editReportType').value = type;
                        document.getElementById('editReportTypeFromDB').value = data.report.report_type;
                        document.getElementById('editReportTable').value = table || 'road_transportation_reports';
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
                        
                        // Load assigned users
                        loadAssignedUsers();
                    } else {
                        showNotification('Failed to load report details', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Error loading report details: ' + (error.message || 'Unknown error'), 'error');
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

        function archiveReport(id, type) {
            if (!confirm('Archive this completed report? It will be moved to the archive and removed from this page.')) return;
            var form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML =
                '<input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">' +
                '<input type="hidden" name="action" value="archive_report">' +
                '<input type="hidden" name="report_id" value="' + id + '">' +
                '<input type="hidden" name="report_type" value="' + type + '">';
            document.body.appendChild(form);
            form.submit();
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
        editSelectedFiles = [];
        
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
                    if (data.analytics) {
                        const a = data.analytics;
                        msg += `. Completed in ${a.duration_days} days (${a.duration_hours}h ${a.duration_minutes}m). Analytics recorded.`;
                    }
                    showNotification(msg, 'success');
                    closeModal('editReportModal');
                    indicator.textContent = 'Changes saved.';
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

        // Panel search — matches only the Report ID / Report # column (index 1;
        // index 0 is the Action buttons column). It is wired via each input's
        // inline 'oninput' attribute so it works even if any earlier part of
        // this script fails before the addEventListener lines below run (the
        // function is hoisted to global scope, so the inline handler can always
        // call it).
        function panelSearch(inputId, tableId) {
            const query = document.getElementById(inputId).value.trim().toLowerCase();
            const tbody = document.querySelector('#' + tableId + ' tbody');
            if (!tbody) return;
            const rows = tbody.querySelectorAll('tr');
            rows.forEach(row => {
                if (row.querySelector('.rm-empty-state')) return;
                const cell = row.cells[1];
                const id = (cell ? cell.textContent : '').trim().toLowerCase();
                // Empty box → show all reports; otherwise only the matching ID.
                row.style.display = (query === '' || id.includes(query)) ? '' : 'none';
            });
        }

        // Search is wired BOTH via each input's inline 'oninput' attribute (guaranteed
        // to work even if this script throws before reaching here, since
        // panelSearch is hoisted to global scope) AND via these listeners (in
        // case a browser ignores the attribute). Filtering is idempotent, so
        // having both is harmless. Null-checks keep this from throwing when a
        // panel is not rendered for the current role (e.g. the Citizen panel is
        // hidden for Road Operations Supervisors).
        [['citizenSearchInput', 'citizenTable'], ['lguSearchInput', 'lguTable'], ['cimmSearchInput', 'cimmTable'], ['infraSearchInput', 'infraTable']].forEach(function(pair) {
            var input = document.getElementById(pair[0]);
            if (input) input.addEventListener('input', function() { panelSearch(pair[0], pair[1]); });
        });

        // Compare two cell values using "natural" ordering — each embedded number
        // group is compared numerically (so RPT-9 sorts before RPT-100), while text
        // parts compare as strings (so timestamped IDs like RPT-20260804-222519…
        // sort by their time portion).
        function cellCompare(a, b) {
            const tokensA = a.toLowerCase().match(/\d+|\D+/g) || [];
            const tokensB = b.toLowerCase().match(/\d+|\D+/g) || [];
            const count = Math.max(tokensA.length, tokensB.length);
            for (let i = 0; i < count; i++) {
                const ta = tokensA[i] === undefined ? '' : tokensA[i];
                const tb = tokensB[i] === undefined ? '' : tokensB[i];
                if (ta === tb) continue;
                const na = /^\d+$/.test(ta);
                const nb = /^\d+$/.test(tb);
                if (na && nb) {
                    const diff = ta.length - tb.length;
                    if (diff !== 0) return diff;
                    const cmp = ta.localeCompare(tb);
                    if (cmp !== 0) return cmp;
                } else {
                    const cmp = ta.localeCompare(tb);
                    if (cmp !== 0) return cmp;
                }
            }
            return 0;
        }

        function toggleSort(tableId, key) {
            const tbody = document.querySelector('#' + tableId + ' tbody');
            if (!tbody) return;
            // Lazy re-init: if an earlier script error prevented the top-level
            // `var sortState` line from running, sortState is `undefined` here
            // (not in a TDZ), so restore it before use.
            if (!sortState) { sortState = { citizen: 'asc', lgu: 'asc', cimm: 'asc', infra: 'asc' }; }
            sortState[key] = sortState[key] === 'asc' ? 'desc' : 'asc';
            const dir = sortState[key] === 'asc' ? 1 : -1;
            const all = Array.from(tbody.querySelectorAll('tr'));
            const dataRows = all.filter(r => !r.querySelector('.rm-empty-state'));
            const emptyRow = all.find(r => r && r.querySelector('.rm-empty-state'));
            // Sort only the currently visible rows (keeps any active search
            // filter intact), by the Report ID column (index 1 — index 0 is
            // the Action buttons column).
            const visible = dataRows.filter(r => r.style.display !== 'none');
            visible.sort((a, b) => {
                const cellText = row => (row.cells[1] ? row.cells[1].textContent : '').trim();
                return cellCompare(cellText(a).toLowerCase(), cellText(b).toLowerCase()) * dir;
            });
            // Rewrite the body in a stable order — visible (sorted) first, then
            // hidden, then the empty-state row — so search and sort stay applied
            // together.
            visible.forEach(r => tbody.appendChild(r));
            dataRows.filter(r => r.style.display === 'none').forEach(r => tbody.appendChild(r));
            if (emptyRow) tbody.appendChild(emptyRow);
        }

        function toggleCitizenSort() { toggleSort('citizenTable', 'citizen'); }
        function toggleLguSort()     { toggleSort('lguTable', 'lgu'); }
        function toggleCimmSort()   { toggleSort('cimmTable', 'cimm'); }
        function toggleInfraSort()   { toggleSort('infraTable', 'infra'); }

        // CIMM detail viewer (read-only)
        function viewCimmReport(idx) {
            var r = cimmData[idx];
            if (!r) return;

            var statusLabels = { 'pending': 'Pending Review', 'in-progress': 'Flagged', 'completed': 'Verified', 'cancelled': 'Dismissed' };
            var statusStyles = {
                'pending':    {bg:'rgba(251,191,36,0.15)', color:'#f59e0b'},
                'approved':   {bg:'rgba(34,197,94,0.15)',  color:'#22c55e'},
                'completed':  {bg:'rgba(34,197,94,0.15)',  color:'#22c55e'},
                'cancelled':  {bg:'rgba(220,53,69,0.15)',  color:'#ef4444'},
                'in-progress':{bg:'rgba(59,130,246,0.15)', color:'#3b82f6'}
            };
            var pStyles = {
                'high':   {bg:'rgba(220,53,69,0.15)', color:'#ef4444'},
                'medium': {bg:'rgba(251,191,36,0.15)', color:'#f59e0b'},
                'low':    {bg:'rgba(34,197,94,0.15)',  color:'#22c55e'}
            };

            // Header
            document.getElementById('rm-report-id').textContent = 'Report #' + (r.report_id || '—');
            document.getElementById('rm-title').textContent = r.title || '—';

            var st = (r.status || 'pending').toLowerCase();
            var ss = statusStyles[st] || {bg:'rgba(107,114,128,0.15)', color:'#6b7280'};
            var pp = (r.priority || 'medium').toLowerCase();
            var ps = pStyles[pp] || {bg:'rgba(107,114,128,0.15)', color:'#6b7280'};

            var badgesHtml = rmBadge(statusLabels[r.status] || r.status || '—', ss.bg, ss.color);
            badgesHtml += rmBadge(r.priority || '—', ps.bg, ps.color);
            badgesHtml += '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:rgba(249,115,22,0.12);color:#c2410c;">CIMM</span>';
            document.getElementById('rm-badges').innerHTML = badgesHtml;

            // Report Information
            var reportGrid = '';
            reportGrid += rmInfoItem('building', 'Infrastructure', r.title);
            reportGrid += rmInfoItem('calendar-alt', 'Start Date', formatDate(r.start_date));
            reportGrid += rmInfoItem('calendar-check', 'End Date', formatDate(r.end_date));
            reportGrid += rmInfoItem('dollar-sign', 'Budget', r.estimation ? '₱' + parseFloat(r.estimation).toLocaleString('en-PH', {minimumFractionDigits:2}) : '—');
            if (r.budget_allocation) {
                reportGrid += rmInfoItem('dollar-sign', 'Budget Allocation', '₱' + parseFloat(r.budget_allocation).toLocaleString('en-PH', {minimumFractionDigits:2}));
            }
            document.getElementById('rm-report-grid').innerHTML = reportGrid;

            // Source & Department
            var sourceGrid = '';
            sourceGrid += rmInfoItem('server', 'Source', 'CIMM');
            if (r.assigned_to) {
                sourceGrid += rmInfoItem('user-cog', 'Engineer', r.assigned_to);
            }
            if (r.reporter_name) {
                sourceGrid += rmInfoItem('user', 'Reported By', r.reporter_name);
            }
            document.getElementById('rm-source-grid').innerHTML = sourceGrid;

            // Location
            var locationGrid = '';
            var locVal = r.location || '—';
            if (r.latitude && r.longitude && r.latitude != 0 && r.longitude != 0) {
                locVal += '<br><a href="https://www.google.com/maps?q=' + r.latitude + ',' + r.longitude + '" target="_blank" style="color:#3762c8;font-size:12px;text-decoration:none;"><i class="fas fa-external-link-alt" style="font-size:10px;"></i> View on Map</a>';
            }
            locationGrid += '<div class="rm-info-item rm-info-value-full"><div class="rm-info-icon"><i class="fas fa-map-marker-alt"></i></div><div><div class="rm-info-label">Location</div><div class="rm-info-value">' + locVal + '</div></div></div>';
            document.getElementById('rm-location-grid').innerHTML = locationGrid;

            // Description
            document.getElementById('rm-description').textContent = r.description || 'No description provided.';

            // Attachments (none available on CIMM cards)
            var attachHtml = '<div style="padding:8px 0;color:#9ca3af;font-size:14px;">No attachments.</div>';
            document.getElementById('rm-attachments').innerHTML = attachHtml;

            // Timeline
            var timelineGrid = '';
            timelineGrid += rmInfoItem('calendar-check', 'Created', formatDate(r.start_date));
            if (r.end_date) {
                timelineGrid += rmInfoItem('flag-checkered', 'Target End', formatDate(r.end_date));
            }
            document.getElementById('rm-timeline-grid').innerHTML = timelineGrid;

            openViewReportModal();
        }

        // CIMM edit
        function editCimmReport(idx) {
            var r = cimmData[idx];
            if (!r) return;
            document.getElementById('editCimmReportId').value = r.id;
            document.getElementById('editCimmReportTable').value = 'cimm_verification_reports';
            document.getElementById('editCimmRepNumber').value = r.report_id || '';
            document.getElementById('editCimmInfrastructure').value = r.title || '';
            document.getElementById('editCimmLocation').value = r.location || '';
            document.getElementById('editCimmStatus').value = r.status || 'pending';
            document.getElementById('editCimmPriority').value = r.priority || 'medium';
            document.getElementById('editCimmEstimation').value = r.estimation || '';
            document.getElementById('editCimmNotes').value = r.notes || '';
            document.getElementById('cimmEditIndicator').textContent = '';
            document.getElementById('cimmEditSubmitBtn').disabled = false;
            document.getElementById('cimmEditSubmitBtn').innerHTML = '<i class="fas fa-save"></i> Save Changes';
            openModal('editCimmModal');
            loadAssignedUsers();
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
            const lgu     = document.getElementById('lguReportsPanel');
            const cimm    = document.getElementById('cimmReportsPanel');
            const infra   = document.getElementById('infraReportsPanel');

            if (source === 'cimm') {
                if (citizen) citizen.style.display = 'none';
                if (lgu) lgu.style.display = 'none';
                if (cimm) cimm.style.display = '';
                if (infra) infra.style.display = 'none';
            } else if (source === 'maintenance') {
                if (citizen) citizen.style.display = 'none';
                if (lgu) lgu.style.display = 'none';
                if (cimm) cimm.style.display = 'none';
                if (infra) infra.style.display = '';
            } else if (source === 'lgu_reports') {
                if (citizen) citizen.style.display = 'none';
                if (lgu) lgu.style.display = '';
                if (cimm) cimm.style.display = 'none';
                if (infra) infra.style.display = 'none';
            } else if (source === 'transport') {
                // Citizen Reports filter: show ONLY the Citizen Reports panel.
                // The LGU Monitoring panel, CIMM panel, and Infrastructure
                // panel are all hidden so only citizen-submitted reports are
                // shown.
                if (citizen) citizen.style.display = '';
                if (lgu) lgu.style.display = 'none';
                if (cimm) cimm.style.display = 'none';
                if (infra) infra.style.display = 'none';
            } else {
                // 'all' or unset — show everything
                if (citizen) citizen.style.display = '';
                if (lgu) lgu.style.display = '';
                if (cimm) cimm.style.display = '';
                if (infra) infra.style.display = '';
            }
        }

        // Sync source filter dropdown with panels on page load
        const sourceFilter = document.getElementById('sourceFilter');
        if (sourceFilter) {
            filterSource(sourceFilter.value);
            sourceFilter.addEventListener('change', function() { filterSource(this.value); });
        }

        // Deep-link focus: ?source= + ?id= from a notification "View" button.
        // The backend already fetched the report and injected it into the
        // correct panel (see the $focus_target PHP block above), so this only
        // reveals the panel, scrolls to the row and briefly highlights it.
        var focusTarget = <?php echo json_encode($focus_target); ?>;
        if (focusTarget && focusTarget.id) {
            setTimeout(function() {
                var row = null;
                if (focusTarget.panelSource) {
                    // Source is known — match by source + id so rows with the
                    // same id in different tables never collide.
                    row = document.querySelector('tr[data-id="' + focusTarget.id + '"][data-source="' + focusTarget.panelSource + '"]');
                } else {
                    // Legacy ?id= deep-link without a source.
                    row = document.querySelector('tr[data-id="' + focusTarget.id + '"]');
                }
                if (row && focusTarget.found) {
                    var panel = row.closest('.rm-panel');
                    if (panel) panel.style.display = '';
                    var sf = document.getElementById('sourceFilter');
                    if (sf && focusTarget.panelSource) {
                        sf.value = focusTarget.panelSource;
                        filterSource(sf.value);
                    }
                    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    row.classList.add('rm-row-focus');
                    setTimeout(function() { row.classList.remove('rm-row-focus'); }, 5000);
                } else {
                    showNotification('The report referenced by this notification could not be found.', 'error');
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
        <p class="t-text-secondary" style="margin:0 0 20px; font-size:14px;">
            Your session will expire in <strong><span id="sessionCountdown">60</span></strong> seconds due to inactivity.
        </p>
        <div style="display:flex; gap:12px; justify-content:center;">
            <button id="extendSessionBtn" class="t-gradient-primary" style="padding:10px 24px; border:none; border-radius:8px; font-size:14px; cursor:pointer; font-weight:600;">Extend Session</button>
            <button id="logoutSessionBtn" style="padding:10px 24px; background:#e74c3c; color:#fff; border:none; border-radius:8px; font-size:14px; cursor:pointer; font-weight:600;">Log Out</button>
        </div>
    </div>

    <!-- Session timeout data -->
    <script id="sessionTimeoutData" data-timeout="<?php echo $session_timeout; ?>" data-role="<?php echo htmlspecialchars($_SESSION['role'] ?? ''); ?>"></script>
    <script src="../../js/session-timeout.js"></script>
</body>
</html>