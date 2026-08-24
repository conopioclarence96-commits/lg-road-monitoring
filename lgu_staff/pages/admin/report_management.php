<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../../pages/api/cimm_verification_data.php';
require_once __DIR__ . '/../api/ipms_road_projects_data.php';
// Archive helpers (rgmap_archive_report, rgmap_auto_archive_completed, ...) —
// shared with progress_update_api.php. Required so the 7-day auto-archive
// sweep runs on this page for completed LGU reports.
require_once __DIR__ . '/../api/progress_archive_helpers.php';

// Ensure the restored_from_archive marker column exists so restored-cancelled
// reports can be shown again on the panels (normally-cancelled stay hidden).
rgmap_ensure_restored_from_archive_column();

// Session timeout configuration
$session_timeout = 30 * 60; // 30 minutes in seconds
lgu_enforce_idle_timeout($session_timeout, '../../login.php?timeout=1');

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

// Auto-archive sweep: move any completed report whose 7-day retention window
// (measured from completed_at) has passed into the archive. Runs once per page
// load; it only touches reports carrying auto_archive_at, so completed LGU
// reports stay visible in the LGU Monitoring panel until the deadline passes.
try {
    rgmap_auto_archive_completed($conn);
} catch (Exception $e) {
    error_log('report_management.php auto-archive sweep: ' . $e->getMessage());
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
        case 'update_ipms_project':
            handle_update_ipms_project();
            break;
        case 'delete_ipms_project':
            handle_delete_ipms_project();
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
    if (in_array($report_table, ['road_transportation_reports', 'road_maintenance_reports', 'cimm_verification_reports', 'ipms_road_projects'], true)) {
        $table = $report_table;
    }

    // Only the supervisor who first assigned this report may edit it.
    $is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if (!rgmap_supervisor_can_manage_report($conn, $report_id, $table)) {
        $owner = rgmap_get_report_owner_supervisor($conn, $report_id, $table);
        $owner_name = trim((string)($owner['name'] ?? '')) ?: 'another supervisor';
        $msg = "This report is managed by {$owner_name}. Only the supervisor who assigned it can edit it.";
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }
        set_flash_message('error', $msg);
        return;
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
              'archived_from' => "VARCHAR(100) DEFAULT NULL",
              'source_pk' => "INT NULL DEFAULT NULL"] as $col => $def) {
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
        
        // Determine the correct source table from the report type, mirroring the
        // type-based routing the rest of the page uses. Transportation types and
        // Road LGU issue types (debris, cracks, erosion, ...) all live in
        // road_transportation_reports; only pure maintenance types fall through
        // to road_maintenance_reports.
        $transport_types = ['potholes', 'road_damage', 'shoulder_damage', 'traffic_jam', 'accident', 'congestion', 'traffic_light_outage', 'vehicle_breakdown', 'traffic_sign_issue', 'transportation', 'infrastructure_issue', 'road_closure', 'parking_violation', 'public_transport_issue'];
        $road_types = ['debris', 'cracks', 'erosion', 'flooding', 'marking_fade'];
        $table = (in_array($report_type, $transport_types, true) || in_array($report_type, $road_types, true)) ? 'road_transportation_reports' : 'road_maintenance_reports';

        // The Delete button sends the row's source table explicitly (same
        // mechanism Edit uses), so honor it whenever it is valid.
        $report_table = sanitize_input($_POST['report_table'] ?? '');
        if (in_array($report_table, ['road_transportation_reports', 'road_maintenance_reports'], true)) {
            $table = $report_table;
        }

        if (!rgmap_supervisor_can_manage_report($conn, $report_id, $table)) {
            $owner = rgmap_get_report_owner_supervisor($conn, $report_id, $table);
            $owner_name = trim((string)($owner['name'] ?? '')) ?: 'another supervisor';
            set_flash_message('error', "This report is managed by {$owner_name}. Only the supervisor who assigned it can delete it.");
            return;
        }

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
        if (!rgmap_supervisor_can_manage_report($conn, $report_id, $table)) {
            $owner = rgmap_get_report_owner_supervisor($conn, $report_id, $table);
            $owner_name = trim((string)($owner['name'] ?? '')) ?: 'another supervisor';
            set_flash_message('error', "This report is managed by {$owner_name}. Only the supervisor who assigned it can archive it.");
            return;
        }

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

    if (!rgmap_require_supervisor_report_ownership(
        $conn,
        $report_id,
        'cimm_verification_reports',
        isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    )) {
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

        if (!rgmap_require_supervisor_report_ownership($conn, $report_id, 'cimm_verification_reports', false)) {
            return;
        }

        // The Delete/Trash action soft-deletes: copy the CIMM report into the
        // archive as 'cancelled' (preserving all report data so it can be
        // restored) BEFORE removing it from cimm_verification_reports.
        $row = fetch_one("SELECT * FROM cimm_verification_reports WHERE id = ?", [$report_id], "i");

        $archived = rgmap_archive_cimm_report($conn, $report_id, 'cancelled');
        if ($archived) {
            $row = $row ?: ['reference_code' => '', 'infrastructure' => 'Unknown'];
            $label = $row['reference_code'] ?? $row['infrastructure'] ?? 'Unknown';
            log_audit_action($user_id, "Deleted CIMM report", "Report ID: {$report_id}, Label: {$label}");
            set_flash_message('success', 'CIMM report moved to archive as cancelled.');
        } else {
            set_flash_message('error', 'Failed to archive CIMM report.');
        }
    } catch (Exception $e) {
        error_log('Delete CIMM report error: ' . $e->getMessage());
        set_flash_message('error', 'Failed to delete CIMM report. Please try again.');
    }
}

// Edit an approved IPMS infrastructure project (local bookkeeping fields in
// the ipms_road_projects mirror). Status and priority are editable from the
// modal; schedule, budget and addresses are read-only (they mirror the IPMS
// feed) and are never updated here.
function handle_update_ipms_project() {
    global $conn, $user_id;

    $report_id = intval($_POST['report_id'] ?? 0);
    if ($report_id <= 0) {
        $msg = 'Invalid project ID';
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }
        set_flash_message('error', $msg);
        return;
    }

    $is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if (!rgmap_supervisor_can_manage_report($conn, $report_id, 'ipms_road_projects')) {
        $owner = rgmap_get_report_owner_supervisor($conn, $report_id, 'ipms_road_projects');
        $owner_name = trim((string)($owner['name'] ?? '')) ?: 'another supervisor';
        $msg = "This report is managed by {$owner_name}. Only the supervisor who assigned it can edit it.";
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }
        set_flash_message('error', $msg);
        return;
    }

    try {
        $pdo = rgmap_ipms_pdo();
        $stmt = $pdo->prepare("SELECT project_id FROM ipms_road_projects WHERE project_id = ?");
        $stmt->execute([$report_id]);
        if (!$stmt->fetch()) {
            $msg = 'Infrastructure project not found.';
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $msg]);
                exit;
            }
            set_flash_message('error', $msg);
            return;
        }

        $update_fields = [];
        $params = [];

        $title = sanitize_input($_POST['project_name'] ?? '');
        if ($title !== '') { $update_fields[] = "project_name = ?"; $params[] = $title; }

        $road_status = sanitize_input($_POST['road_status'] ?? '');
        if ($road_status !== '') { $update_fields[] = "road_status = ?"; $params[] = $road_status; }

        $status = sanitize_input($_POST['status'] ?? '');
        $allowed_statuses = ['approved', 'in-progress'];
        if ($status !== '' && in_array($status, $allowed_statuses, true)) {
            $update_fields[] = "status = ?"; $params[] = $status;
        }

        $priority = sanitize_input($_POST['priority'] ?? '');
        $allowed_priorities = ['low', 'medium', 'high'];
        if ($priority !== '' && in_array($priority, $allowed_priorities, true)) {
            $update_fields[] = "priority = ?"; $params[] = $priority;
        }

        // Schedule, budget and addresses are read-only in the edit modal (the
        // values come from the IPMS mirror), so they are never updated here.

        if (empty($update_fields)) {
            $msg = 'No changes to save.';
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $msg]);
                exit;
            }
            set_flash_message('error', $msg);
            return;
        }

        $params[] = $report_id;
        $query = "UPDATE ipms_road_projects SET " . implode(', ', $update_fields) . " WHERE project_id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        log_audit_action($user_id, "Updated infrastructure project", "Project ID: {$report_id}");

        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Infrastructure project updated successfully']);
            exit;
        }
        set_flash_message('success', 'Infrastructure project updated successfully.');
    } catch (Exception $e) {
        error_log('Update IPMS project error: ' . $e->getMessage());
        $msg = 'Failed to update infrastructure project. Please try again.';
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }
        set_flash_message('error', $msg);
    }
}

// Trash/Delete an approved IPMS infrastructure project. Follows the same
// archive-first flow the other report panels use: copy the full project into
// road_transportation_reports_archive as 'cancelled' (previous_status and
// archived_from are stamped for a later restore) BEFORE removing the row from
// ipms_road_projects. No IPMS write-back, and the approval/verification flow
// in verification_monitoring.php is untouched.
function handle_delete_ipms_project() {
    global $conn, $user_id;

    try {
        $report_id = intval($_POST['report_id'] ?? 0);
        if ($report_id <= 0) {
            set_flash_message('error', 'Invalid project ID');
            return;
        }

        if (!rgmap_require_supervisor_report_ownership($conn, $report_id, 'ipms_road_projects', false)) {
            return;
        }

        $pdo = rgmap_ipms_pdo();
        $stmt = $pdo->prepare("SELECT project_name FROM ipms_road_projects WHERE project_id = ?");
        $stmt->execute([$report_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            set_flash_message('error', 'Infrastructure project not found.');
            return;
        }

        $archived = rgmap_archive_ipms_project($conn, $report_id, 'cancelled');

        $label = $row['project_name'] ?? 'Unknown';
        log_audit_action($user_id, "Deleted infrastructure project", "Project ID: {$report_id}, Title: {$label}");
        $msg = $archived ? 'Infrastructure project deleted successfully and moved to archive.' : 'Infrastructure project deleted successfully.';
        set_flash_message('success', $msg);
    } catch (Exception $e) {
        error_log('Delete IPMS project error: ' . $e->getMessage());
        set_flash_message('error', 'Failed to delete infrastructure project. Please try again.');
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
        'start_date'    => $row['starting_date'] ?? null,
        'end_date'      => $row['estimated_end_date'] ?? null,
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
        'restored_from_archive' => (int)($row['restored_from_archive'] ?? 0),
        'updated_at'    => $row['verified_at'] ?? $row['synced_at'] ?? null,
        'attachments'   => null,
        'image_path'    => null,
        'report_type'   => 'infrastructure_issue',
        'source_system' => 'cimm',
    ];
}

/**
 * Reusable panel pagination helpers.
 * Panel keys become query params: lgu_page, citizen_page, cimm_page, etc.
 */
function rm_panel_page(string $panel): int {
    return max(1, (int)($_GET[$panel . '_page'] ?? 1));
}

function rm_panel_offset(string $panel, int $perPage): int {
    return (rm_panel_page($panel) - 1) * max(1, $perPage);
}

function rm_panel_page_url(string $panel, int $page): string {
    $params = $_GET;
    $params[$panel . '_page'] = max(1, $page);
    return '?' . http_build_query($params);
}

/**
 * @return array{html:string,total_pages:int,page:int,from:int,to:int}
 */
function rm_build_panel_pagination(string $panel, int $page, int $perPage, int $total): array {
    $perPage = max(1, $perPage);
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min(max(1, $page), $totalPages);
    $from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $to = min($total, $page * $perPage);

    $html = '<div class="rm-panel-pagination" data-panel="' . htmlspecialchars($panel) . '" data-page="' . $page . '" data-total="' . $total . '" data-per-page="' . $perPage . '">';
    $html .= '<div class="rm-panel-pagination-info">Showing ' . $from . '–' . $to . ' of ' . $total . '</div>';
    $html .= '<div class="rm-panel-pagination-controls">';

    $prevDisabled = $page <= 1;
    $nextDisabled = $page >= $totalPages;

    $html .= '<button type="button" class="rm-page-btn' . ($prevDisabled ? ' disabled' : '') . '" data-panel="' . htmlspecialchars($panel) . '" data-page="' . ($page - 1) . '"' . ($prevDisabled ? ' disabled aria-disabled="true"' : '') . '><i class="fas fa-chevron-left"></i></button>';
    $html .= '<span class="rm-page-label">Page ' . $page . ' / ' . $totalPages . '</span>';
    $html .= '<button type="button" class="rm-page-btn' . ($nextDisabled ? ' disabled' : '') . '" data-panel="' . htmlspecialchars($panel) . '" data-page="' . ($page + 1) . '"' . ($nextDisabled ? ' disabled aria-disabled="true"' : '') . '><i class="fas fa-chevron-right"></i></button>';
    $html .= '</div></div>';

    return [
        'html' => $html,
        'total_pages' => $totalPages,
        'page' => $page,
        'from' => $from,
        'to' => $to,
    ];
}

/**
 * Fetch LGU Monitoring reports independently (same idea as CIMM):
 * own LIMIT/OFFSET so Citizen rows cannot crowd them out.
 *
 * @return array{rows:array<int,array>,total:int}
 */
function getLguReportsForManagement(
    string $status_filter = 'all',
    bool $road_only = false,
    bool $transport_only = false,
    int $limit = 10,
    int $offset = 0,
    string $search = ''
): array {
    global $conn;

    $limit = max(1, $limit);
    $offset = max(0, $offset);
    $est = $conn->query("SHOW COLUMNS FROM road_transportation_reports LIKE 'estimation'");
    $est_col = ($est && $est->num_rows > 0) ? 'estimation' : '0 as estimation';

    $where = "report_source = 'local'
              AND created_by != 0
              AND report_type != 'infrastructure_issue'";

    if ($road_only) {
        $where .= " AND report_category = 'road'";
    } elseif ($transport_only) {
        $where .= " AND report_category = 'transportation'";
    }

    // Completed projects live on the Completed Projects monitoring page.
    if ($status_filter === 'all') {
        $where .= " AND (
            status IN ('approved', 'in-progress')
            OR (status = 'cancelled' AND restored_from_archive = 1)
        )";
    } elseif ($status_filter === 'completed') {
        $where .= " AND 1=0";
    } elseif ($status_filter === 'cancelled') {
        $where .= " AND status = 'cancelled' AND restored_from_archive = 1";
    } else {
        $where .= " AND status = '" . $conn->real_escape_string($status_filter) . "'";
    }

    $search = trim($search);
    if ($search !== '') {
        $like = $conn->real_escape_string($search);
        $like = str_replace(['%', '_'], ['\\%', '\\_'], $like);
        $where .= " AND report_id LIKE '%{$like}%'";
    }

    $countRow = fetch_one("SELECT COUNT(*) AS c FROM road_transportation_reports WHERE {$where}");
    $total = (int)($countRow['c'] ?? 0);

    $sql = "SELECT id, report_id, title, description, location, latitude, longitude, priority, status,
                   assigned_to, engineer, budget_allocation, cimm_engineer_name, cimm_budget,
                   {$est_col}, resolution_notes as notes, department, created_date, created_at,
                   updated_at, approved_at, attachments, image_path, report_type, report_category,
                   report_source, created_by, restored_from_archive,
                   'lgu_reports' as source_system
            FROM road_transportation_reports
            WHERE {$where}
            ORDER BY created_at DESC
            LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

    $rows = fetch_all($sql);
    return [
        'rows' => is_array($rows) ? $rows : [],
        'total' => $total,
    ];
}

/**
 * Render LGU Monitoring table body rows (shared by initial page + AJAX pagination).
 */
function rm_render_lgu_panel_tbody(
    array $reports,
    bool $is_road_supervisor,
    bool $is_transport_supervisor,
    string $user_role
): string {
    $type_labels = [
        'infrastructure_issue' => 'Infrastructure Issue',
        'traffic_jam' => 'Traffic Jam',
        'accident' => 'Vehicle Accident',
        'road_closure' => 'Road Closure',
        'potholes' => 'Potholes',
        'road_damage' => 'Road Damage',
    ];
    $lgu_transport_types = ['potholes', 'road_damage', 'shoulder_damage', 'traffic_jam', 'accident', 'congestion', 'traffic_light_outage', 'vehicle_breakdown', 'traffic_sign_issue', 'transportation', 'infrastructure_issue', 'road_closure', 'parking_violation', 'public_transport_issue'];
    $lgu_road_types = ['debris', 'cracks', 'erosion', 'flooding', 'marking_fade'];
    $colspan = (($is_road_supervisor || $user_role === 'system_admin') ? 9 : 7)
        + ($is_transport_supervisor ? 1 : 0)
        + ($user_role === 'system_admin' ? 1 : 0);
    $can_edit_role = $is_road_supervisor || $is_transport_supervisor || $user_role === 'system_admin';
    $show_engineer = $is_road_supervisor || $user_role === 'system_admin';
    $show_category = ($user_role === 'system_admin');

    ob_start();
    if (!empty($reports)):
        foreach ($reports as $report):
            $lgu_delete_table = (in_array($report['report_type'], $lgu_transport_types, true) || in_array($report['report_type'], $lgu_road_types, true)) ? 'road_transportation_reports' : 'road_maintenance_reports';
            $rtype = htmlspecialchars((string)($report['report_type'] ?? ''), ENT_QUOTES);
            $src = htmlspecialchars((string)($report['source_system'] ?? 'transport'), ENT_QUOTES);
            $row_can_manage = $user_role === 'system_admin'
                || (!$is_road_supervisor && !$is_transport_supervisor)
                || !empty($report['can_manage_as_supervisor']);
            $can_edit = $can_edit_role && $row_can_manage;
            ?>
                        <tr data-id="<?php echo (int)$report['id']; ?>" data-source="lgu_reports">
                            <td>
                                <div class="rm-action-group">
                                    <button class="rm-action-btn" onclick="viewReport(<?php echo (int)$report['id']; ?>, '<?php echo $rtype; ?>', 'road_transportation_reports')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($can_edit): ?>
                                    <button class="rm-edit-btn" onclick="editReport(<?php echo (int)$report['id']; ?>, '<?php echo $rtype; ?>', 'road_transportation_reports')">
                                        <i class="fas fa-pencil"></i>
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($row_can_manage && ($report['status'] ?? '') === 'completed'): ?>
                                    <button class="rm-archive-btn" onclick="archiveReport(<?php echo (int)$report['id']; ?>, '<?php echo $src; ?>')" title="Archive">
                                        <i class="fas fa-archive"></i>
                                    </button>
                                    <?php elseif ($row_can_manage): ?>
                                    <button class="rm-delete-btn" onclick="deleteReport(<?php echo (int)$report['id']; ?>, '<?php echo $rtype; ?>', '<?php echo $lgu_delete_table; ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($report['report_id'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars(strlen($report['title'] ?? '') > 35 ? substr($report['title'], 0, 35) . '...' : ($report['title'] ?? '')); ?></td>
                            <?php if ($show_category): ?>
                            <td><?php
                                $lgu_cat = strtolower(trim((string)($report['report_category'] ?? '')));
                                if ($lgu_cat === 'transportation') {
                                    echo '<span class="rm-category-badge rm-cat-transportation">Transportation</span>';
                                } elseif ($lgu_cat === 'road') {
                                    echo '<span class="rm-category-badge rm-cat-road">Road</span>';
                                } else {
                                    echo '—';
                                }
                            ?></td>
                            <?php endif; ?>
                            <td><?php echo htmlspecialchars($report['location'] ?? '—'); ?></td>
                            <td><span class="rm-priority-badge <?php echo htmlspecialchars((string)($report['priority'] ?? '')); ?>"><?php echo ucfirst(htmlspecialchars((string)($report['priority'] ?? ''))); ?></span></td>
                            <?php if ($is_transport_supervisor): ?>
                            <td>
                                <?php if (($report['assignment_status'] ?? 'unassigned') === 'assigned' && !empty($report['assignment_officer'])): ?>
                                <span class="assignment-badge assignment-assigned"><?php echo htmlspecialchars($report['assignment_officer']); ?></span>
                                <?php else: ?>
                                <span class="assignment-badge assignment-unassigned">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <?php if ($show_engineer): ?>
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
                            <td><span class="rm-status-badge <?php echo htmlspecialchars(strtolower((string)($report['status'] ?? ''))); ?>"><?php echo ucfirst(htmlspecialchars(str_replace('-', ' ', (string)($report['status'] ?? '')))); ?></span></td>
                            <td>
                                <?php echo !empty($report['created_at']) ? date('M d, Y', strtotime($report['created_at'])) : '—'; ?>
                                <?php if (($report['status'] ?? '') === 'approved' && !empty($report['approved_at'])): ?>
                                    <br><small class="t-text-success" style="font-weight:600;">Approved: <?php echo date('M d, Y', strtotime($report['approved_at'])); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
            <?php
        endforeach;
    else:
        ?>
                        <tr>
                            <td colspan="<?php echo (int)$colspan; ?>">
                                <div class="rm-empty-state">
                                    <div class="rm-empty-icon" style="background: rgba(55, 98, 200, 0.12);">
                                        <i class="fas fa-clipboard-list t-text-link"></i>
                                    </div>
                                    <h4>No LGU Monitoring Reports</h4>
                                    <p>No LGU-created monitoring reports found.</p>
                                </div>
                            </td>
                        </tr>
        <?php
    endif;
    return (string)ob_get_clean();
}

/**
 * Fetch Citizen Reports independently (same pagination pattern as LGU).
 * Only post-verification citizen rows (created_by = 0).
 *
 * @return array{rows:array<int,array>,total:int}
 */
function getCitizenReportsForManagement(
    string $status_filter = 'all',
    bool $transport_only = false,
    int $limit = 10,
    int $offset = 0,
    string $search = ''
): array {
    global $conn;

    $limit = max(1, $limit);
    $offset = max(0, $offset);
    $est = $conn->query("SHOW COLUMNS FROM road_transportation_reports LIKE 'estimation'");
    $est_col = ($est && $est->num_rows > 0) ? 'estimation' : '0 as estimation';

    $unverified = "('pending', 'awaiting verification', 'for verification', 'under review', 'submitted', 'new')";
    $where = "created_by = 0
              AND report_type != 'infrastructure_issue'
              AND LOWER(status) NOT IN {$unverified}";

    if ($transport_only) {
        $where .= " AND report_category = 'transportation'";
    }

    if ($status_filter === 'all') {
        $where .= " AND status != 'completed'";
    } elseif ($status_filter === 'completed') {
        $where .= " AND 1=0";
    } elseif ($status_filter !== 'all') {
        $where .= " AND status = '" . $conn->real_escape_string($status_filter) . "'";
    }

    $search = trim($search);
    if ($search !== '') {
        $like = $conn->real_escape_string($search);
        $like = str_replace(['%', '_'], ['\\%', '\\_'], $like);
        $where .= " AND report_id LIKE '%{$like}%'";
    }

    $countRow = fetch_one("SELECT COUNT(*) AS c FROM road_transportation_reports WHERE {$where}");
    $total = (int)($countRow['c'] ?? 0);

    $sql = "SELECT id, report_id, title, description, location, latitude, longitude, priority, status,
                   assigned_to, engineer, budget_allocation, cimm_engineer_name, cimm_budget,
                   {$est_col}, resolution_notes as notes, department, created_date, created_at,
                   updated_at, approved_at, attachments, image_path, report_type, report_category,
                   report_source, created_by, restored_from_archive,
                   'transport' as source_system
            FROM road_transportation_reports
            WHERE {$where}
            ORDER BY created_at DESC
            LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

    $rows = fetch_all($sql);
    return [
        'rows' => is_array($rows) ? $rows : [],
        'total' => $total,
    ];
}

/**
 * Render Citizen Reports table body (initial page + AJAX pagination).
 */
function rm_render_citizen_panel_tbody(
    array $reports,
    bool $is_road_supervisor,
    bool $is_transport_supervisor,
    string $user_role
): string {
    $type_labels = [
        'infrastructure_issue' => 'Infrastructure Issue',
        'traffic_jam' => 'Traffic Jam',
        'accident' => 'Vehicle Accident',
        'road_closure' => 'Road Closure',
        'potholes' => 'Potholes',
        'road_damage' => 'Road Damage',
    ];
    $colspan = 7 + ($is_transport_supervisor ? 1 : 0);
    $can_edit_role = $is_road_supervisor || $is_transport_supervisor || $user_role === 'system_admin';

    ob_start();
    if (!empty($reports)):
        foreach ($reports as $report):
            $rtype = htmlspecialchars((string)($report['report_type'] ?? ''), ENT_QUOTES);
            $src = htmlspecialchars((string)($report['source_system'] ?? 'transport'), ENT_QUOTES);
            $row_can_manage = $user_role === 'system_admin'
                || (!$is_road_supervisor && !$is_transport_supervisor)
                || !empty($report['can_manage_as_supervisor']);
            $can_edit = $can_edit_role && $row_can_manage;
            ?>
                        <tr data-id="<?php echo (int)$report['id']; ?>" data-source="citizen">
                            <td>
                                <div class="rm-action-group">
                                    <button class="rm-action-btn" onclick="viewReport(<?php echo (int)$report['id']; ?>, '<?php echo $rtype; ?>')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($can_edit): ?>
                                    <button class="rm-edit-btn" onclick="editReport(<?php echo (int)$report['id']; ?>, '<?php echo $rtype; ?>', 'road_transportation_reports')">
                                        <i class="fas fa-pencil"></i>
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($row_can_manage && ($report['status'] ?? '') === 'completed'): ?>
                                    <button class="rm-archive-btn" onclick="archiveReport(<?php echo (int)$report['id']; ?>, '<?php echo $src; ?>')" title="Archive">
                                        <i class="fas fa-archive"></i>
                                    </button>
                                    <?php elseif ($row_can_manage): ?>
                                    <button class="rm-delete-btn" onclick="deleteReport(<?php echo (int)$report['id']; ?>, '<?php echo $rtype; ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($report['report_id'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($type_labels[$report['report_type']] ?? ucfirst((string)($report['report_type'] ?? ''))); ?></td>
                            <td><?php if (($report['location'] ?? '') !== ''): ?><span title="<?php echo htmlspecialchars($report['location']); ?>"><?php echo htmlspecialchars(strlen($report['location']) > 40 ? substr($report['location'], 0, 40) . '...' : $report['location']); ?></span><?php else: ?>—<?php endif; ?></td>
                            <td><span class="rm-priority-badge <?php echo htmlspecialchars((string)($report['priority'] ?? '')); ?>"><?php echo ucfirst(htmlspecialchars((string)($report['priority'] ?? ''))); ?></span></td>
                            <?php if ($is_transport_supervisor): ?>
                            <td>
                                <?php if (($report['assignment_status'] ?? 'unassigned') === 'assigned' && !empty($report['assignment_officer'])): ?>
                                <span class="assignment-badge assignment-assigned"><?php echo htmlspecialchars($report['assignment_officer']); ?></span>
                                <?php else: ?>
                                <span class="assignment-badge assignment-unassigned">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td><span class="rm-status-badge <?php echo htmlspecialchars(strtolower((string)($report['status'] ?? ''))); ?>"><?php echo ucfirst(htmlspecialchars(str_replace('-', ' ', (string)($report['status'] ?? '')))); ?></span></td>
                            <td>
                                <?php echo !empty($report['created_at']) ? date('M d, Y', strtotime($report['created_at'])) : '—'; ?>
                                <?php if (($report['status'] ?? '') === 'approved' && !empty($report['approved_at'])): ?>
                                    <br><small class="t-text-success" style="font-weight:600;">Approved: <?php echo date('M d, Y', strtotime($report['approved_at'])); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
            <?php
        endforeach;
    else:
        ?>
                        <tr>
                            <td colspan="<?php echo (int)$colspan; ?>">
                                <div class="rm-empty-state">
                                    <div class="rm-empty-icon">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <h4>No Citizen Reports</h4>
                                    <p>No citizen-submitted reports found.</p>
                                </div>
                            </td>
                        </tr>
        <?php
    endif;
    return (string)ob_get_clean();
}

// Get CIMM reports for report management display (paginated + searchable).
/**
 * @return array{rows:array<int,array>,total:int}
 */
function getCimmReportsForManagement(
    string $status_filter = 'all',
    int $limit = 10,
    int $offset = 0,
    string $search = ''
): array {
    $pdo = rgmap_verification_pdo();

    // 'Cancelled' is included so a CANCELLED report that was restored from the
    // Archive (restored_from_archive = 1) can come back to this panel and be
    // reopened to Approved/In Progress via Edit. Normally-cancelled CIMM
    // reports keep the 0 flag and stay excluded below, so they do not return.
    $opts = [
        'verification_status' => ['Approved', 'In Progress', 'Completed', 'Cancelled'],
        'infrastructure' => 'Roads'
    ];
    $rows = rgmap_fetch_cimm_verification_reports($pdo, $opts);

    $mapped = array_map('mapCimmToReportManagement', $rows);

    // CIMM reports that are still pending or rejected are not shown in report
    // management. Rejected CIMM reports map to the 'cancelled' status here, so
    // excluding 'pending' keeps pending and rejected reports out. 'cancelled'
    // is only kept when the row carries restored_from_archive = 1 — i.e. it is
    // a CANCELLED report that was restored from the Archive and should be
    // visible again so it can be reopened to Approved / In Progress through
    // Edit. Only Approved, In Progress, Completed and restored-cancelled CIMM
    // reports appear in this panel. Completed reports stay for their 7-day
    // retention window and are then moved by the auto-archive sweep.
    $mapped = array_values(array_filter($mapped, function ($r) {
        $status = strtolower($r['status'] ?? '');
        if ($status === 'cancelled') {
            return (int)($r['restored_from_archive'] ?? 0) === 1;
        }
        return !in_array($status, ['pending'], true);
    }));

    if ($status_filter === 'completed') {
        return ['rows' => [], 'total' => 0];
    }

    if ($status_filter === 'all') {
        $mapped = array_values(array_filter($mapped, static function ($r) {
            return strtolower((string)($r['status'] ?? '')) !== 'completed';
        }));
    }

    if ($status_filter !== 'all') {
        $mapped = array_values(array_filter($mapped, function ($r) use ($status_filter) {
            return $r['status'] === $status_filter;
        }));
    }

    $search = trim($search);
    if ($search !== '') {
        $mapped = array_values(array_filter($mapped, static function ($r) use ($search) {
            return stripos((string)($r['report_id'] ?? ''), $search) !== false;
        }));
    }

    $total = count($mapped);
    $limit = max(1, $limit);
    $offset = max(0, $offset);
    $pageRows = array_slice($mapped, $offset, $limit);

    return [
        'rows' => $pageRows,
        'total' => $total,
    ];
}

/**
 * Render CIMM Reports table body (initial page + AJAX pagination).
 * Action handlers use 0-based indices within the current page (cimmData).
 */
function rm_render_cimm_panel_tbody(array $reports): string {
    global $user_role, $is_road_supervisor;
    $role = (string)($user_role ?? ($_SESSION['role'] ?? ''));
    $is_admin = ($role === 'system_admin');
    $is_road_sup = !empty($is_road_supervisor) || ($role === 'road_ops_supervisor');

    ob_start();
    if (!empty($reports)):
        $cimmIdx = 0;
        foreach ($reports as $row):
            $row_can_manage = $is_admin
                || !$is_road_sup
                || !empty($row['can_manage_as_supervisor']);
            ?>
                        <tr data-id="<?php echo (int)$row['id']; ?>" data-source="cimm">
                            <td>
                                <div class="rm-action-group">
                                    <button class="rm-action-btn" onclick="viewCimmReport(<?php echo $cimmIdx; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($row_can_manage): ?>
                                    <button class="rm-edit-btn" onclick="editCimmReport(<?php echo $cimmIdx; ?>)">
                                        <i class="fas fa-pencil"></i>
                                    </button>
                                    <?php if (($row['status'] ?? '') === 'completed'): ?>
                                    <button class="rm-archive-btn" onclick="archiveReport(<?php echo (int)$row['id']; ?>, 'cimm')" title="Archive">
                                        <i class="fas fa-archive"></i>
                                    </button>
                                    <?php else: ?>
                                    <button class="rm-delete-btn" onclick="deleteCimmReport(<?php echo $cimmIdx; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($row['report_id'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($row['title'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($row['location'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars(strlen($row['description'] ?? '') > 40 ? substr($row['description'], 0, 40) . '...' : ($row['description'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars($row['assigned_to'] ?? '—'); ?></td>
                            <td><span class="rm-priority-badge <?php echo htmlspecialchars((string)($row['priority'] ?? '')); ?>"><?php echo ucfirst(htmlspecialchars((string)($row['priority'] ?? ''))); ?></span></td>
                            <td><?php echo !empty($row['estimation']) ? '₱' . number_format((float)$row['estimation'], 2) : '—'; ?></td>
                            <td><span class="rm-status-badge <?php echo htmlspecialchars(strtolower((string)($row['status'] ?? ''))); ?>"><?php echo ucfirst(htmlspecialchars(str_replace('-', ' ', (string)($row['status'] ?? '')))); ?></span></td>
                        </tr>
            <?php
            $cimmIdx++;
        endforeach;
    else:
        ?>
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
        <?php
    endif;
    return (string)ob_get_clean();
}

// Get reports for display
function get_reports($status_filter = 'all', $source_filter = 'all', $limit = 50, $offset = 0, $road_only = false, $include_completed = false, $transport_only = false) {
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
    // A report restored from the Archive with status 'cancelled' carries
    // restored_from_archive = 1, so it stays visible on the LGU Monitoring /
    // Citizen panels it returned to. Normally-cancelled reports (flag 0) keep
    // the existing behaviour and stay excluded.
    $restored_cancelled = "(status = 'cancelled' AND restored_from_archive = 1)";
    if ($transport_estimation_exists) {
        $transport_query = "SELECT id, report_id, title, description, location, latitude, longitude, priority, status, assigned_to, engineer, budget_allocation, cimm_engineer_name, cimm_budget, estimation, resolution_notes as notes, department, created_date, created_at, updated_at, approved_at, attachments, image_path, report_type, report_category, report_source, created_by, restored_from_archive, CASE WHEN report_type = 'infrastructure_issue' THEN 'maintenance' WHEN report_source = 'local' AND created_by != 0 AND (status IN ({$lgu_active_statuses}) OR {$restored_cancelled}) THEN 'lgu_reports' WHEN report_source = 'local' AND created_by != 0 THEN 'hidden' ELSE 'transport' END as source_system FROM road_transportation_reports";
    } else {
        $transport_query = "SELECT id, report_id, title, description, location, latitude, longitude, priority, status, assigned_to, engineer, budget_allocation, cimm_engineer_name, cimm_budget, 0 as estimation, resolution_notes as notes, department, created_date, created_at, updated_at, approved_at, attachments, image_path, report_type, report_category, report_source, created_by, restored_from_archive, CASE WHEN report_type = 'infrastructure_issue' THEN 'maintenance' WHEN report_source = 'local' AND created_by != 0 AND (status IN ({$lgu_active_statuses}) OR {$restored_cancelled}) THEN 'lgu_reports' WHEN report_source = 'local' AND created_by != 0 THEN 'hidden' ELSE 'transport' END as source_system FROM road_transportation_reports";
    }
    $transport_params = [];
    
    // Get maintenance reports (Infrastructure Projects)
    if ($maintenance_estimation_exists) {
        $maintenance_query = "SELECT id, report_id, title, description, location, priority, status, maintenance_team as assigned_to, estimation, department, created_date, created_at, updated_at, approved_at, NULL as attachments, NULL as image_path, 'maintenance' as report_type, 'maintenance' as source_system, restored_from_archive FROM road_maintenance_reports";
    } else {
        $maintenance_query = "SELECT id, report_id, title, description, location, priority, status, maintenance_team as assigned_to, 0 as estimation, department, created_date, created_at, updated_at, approved_at, NULL as attachments, NULL as image_path, 'maintenance' as report_type, 'maintenance' as source_system, restored_from_archive FROM road_maintenance_reports";
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

    // Transportation Operations Supervisors see only Transportation reports.
    // Road LGU monitoring reports (report_category = 'road') must never reach
    // their LGU Monitoring panel, so exclude them at the query level too.
    $transport_cond = $transport_only ? "report_category = 'transportation'" : '';

    if (!$include_transport && !$include_maintenance) {
        $transport_query = "SELECT NULL FROM road_transportation_reports WHERE 1=0";
    } elseif (!$include_transport && $include_maintenance) {
        // When only maintenance is selected, include infrastructure issues from transport table
        $transport_query .= " WHERE report_type = 'infrastructure_issue'";
        if ($road_cond !== '') {
            $transport_query .= " AND {$road_cond}";
        }
        if ($transport_cond !== '') {
            $transport_query .= " AND {$transport_cond}";
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
        if ($transport_cond !== '') {
            $transport_query .= " AND {$transport_cond}";
        }
        if ($is_lgu_filter) {
            $transport_query .= " AND report_source = 'local' AND created_by != 0 AND (status IN ({$lgu_active_statuses}) OR {$restored_cancelled})";
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
        // Both transport and maintenance included (source=all).
        // LGU Monitoring is fetched separately via getLguReportsForManagement(),
        // so exclude staff-created LGU rows here — otherwise they eat the
        // shared LIMIT and crowd out Citizen reports (and vice versa).
        $where_parts = $where_conditions;
        $where_parts[] = 'created_by = 0';
        $where_parts[] = "report_type != 'infrastructure_issue'";
        if ($road_cond !== '') {
            $where_parts[] = $road_cond;
        }
        if ($transport_cond !== '') {
            $where_parts[] = $transport_cond;
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
    // The single exception is a CANCELLED report restored from the Archive
    // (restored_from_archive = 1): it stays visible on the panel it returned
    // to. Normally-cancelled reports (flag 0) remain excluded.
    $active_statuses = $include_completed ? ['approved', 'in-progress', 'completed'] : ['approved', 'in-progress'];
    $all_reports = array_values(array_filter($all_reports, function ($r) use ($active_statuses) {
        $status = $r['status'] ?? '';
        if (in_array($status, $active_statuses, true)) return true;
        if ($status === 'cancelled' && (int)($r['restored_from_archive'] ?? 0) === 1) return true;
        return false;
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
        $cimm_all = getCimmReportsForManagement('all', 100000, 0, '')['rows'];
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
$panel_per_page = 10; // LGU (and future panel) AJAX pagination page size
$offset = ($page - 1) * $per_page;

// Filters
$status_filter = $_GET['status'] ?? 'all';
$source_filter = $_GET['source'] ?? 'all';
$is_supervisor_role = in_array($_SESSION['role'] ?? '', ['road_ops_supervisor', 'trans_ops_supervisor'], true);
$your_reports_only = isset($_GET['mine'])
    ? ((string)$_GET['mine'] === '1')
    : $is_supervisor_role;

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
$is_system_admin = ($user_role === 'system_admin');
$is_transport_supervisor = ($user_role === 'trans_ops_supervisor');

/**
 * When "Your Reports" is on: annotate ownership, keep only handled rows, then
 * re-paginate in PHP. Expects $all_rows already loaded (large batch, offset 0).
 */
function rm_paginate_your_reports($conn, array $all_rows, int $page, int $per_page, string $source_table = 'road_transportation_reports'): array {
    foreach ($all_rows as &$r) {
        if (empty($r['_source_table'])) {
            $r['_source_table'] = $source_table;
        }
    }
    unset($r);
    annotate_report_assignment_status($conn, $all_rows);
    $filtered = rgmap_filter_reports_you_handle($conn, $all_rows);
    $total = count($filtered);
    $page = max(1, $page);
    $max_page = max(1, (int)ceil($total / max(1, $per_page)));
    if ($page > $max_page) {
        $page = $max_page;
    }
    $offset = ($page - 1) * $per_page;
    return [
        'rows' => array_slice($filtered, $offset, $per_page),
        'total' => $total,
        'page' => $page,
    ];
}

// AJAX panel pagination — return rows + controls without a full page reload.
if (($_GET['ajax'] ?? '') === 'panel_page') {
    header('Content-Type: application/json; charset=utf-8');
    $panel = preg_replace('/[^a-z_]/', '', strtolower((string)($_GET['panel'] ?? '')));
    $ajax_page = max(1, (int)($_GET['page'] ?? 1));

    if ($panel === 'lgu') {
        $search_q = trim((string)($_GET['q'] ?? ''));
        if ($your_reports_only) {
            $lgu_result = getLguReportsForManagement(
                $status_filter,
                $is_road_supervisor,
                $is_transport_supervisor,
                500,
                0,
                $search_q
            );
            $paged = rm_paginate_your_reports($conn, $lgu_result['rows'], $ajax_page, $panel_per_page);
            $rows = $paged['rows'];
            $total = (int)$paged['total'];
            $ajax_page = (int)$paged['page'];
        } else {
            $lgu_result = getLguReportsForManagement(
                $status_filter,
                $is_road_supervisor,
                $is_transport_supervisor,
                $panel_per_page,
                ($ajax_page - 1) * $panel_per_page,
                $search_q
            );
            $total = (int)$lgu_result['total'];
            $max_page = max(1, (int)ceil($total / max(1, $panel_per_page)));
            if ($ajax_page > $max_page) {
                $ajax_page = $max_page;
                $lgu_result = getLguReportsForManagement(
                    $status_filter,
                    $is_road_supervisor,
                    $is_transport_supervisor,
                    $panel_per_page,
                    ($ajax_page - 1) * $panel_per_page,
                    $search_q
                );
                $total = (int)$lgu_result['total'];
            }
            $rows = $lgu_result['rows'];
            if ($is_transport_supervisor || $is_road_supervisor) {
                foreach ($rows as &$__r) {
                    if (empty($__r['_source_table'])) {
                        $__r['_source_table'] = 'road_transportation_reports';
                    }
                }
                unset($__r);
                annotate_report_assignment_status($conn, $rows);
            }
        }
        $pagination_html = ($total > $panel_per_page)
            ? rm_build_panel_pagination('lgu', $ajax_page, $panel_per_page, $total)['html']
            : '';
        echo json_encode([
            'success' => true,
            'panel' => 'lgu',
            'page' => $ajax_page,
            'total' => $total,
            'per_page' => $panel_per_page,
            'q' => $search_q,
            'rows_html' => rm_render_lgu_panel_tbody($rows, $is_road_supervisor, $is_transport_supervisor, $user_role),
            'pagination_html' => $pagination_html,
            'badge_text' => $total . ' Reports',
        ]);
        exit;
    }

    if ($panel === 'citizen') {
        if ($is_road_supervisor) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden']);
            exit;
        }
        $search_q = trim((string)($_GET['q'] ?? ''));
        if ($your_reports_only) {
            $citizen_result = getCitizenReportsForManagement(
                $status_filter,
                $is_transport_supervisor,
                500,
                0,
                $search_q
            );
            $paged = rm_paginate_your_reports($conn, $citizen_result['rows'], $ajax_page, $panel_per_page);
            $rows = $paged['rows'];
            $total = (int)$paged['total'];
            $ajax_page = (int)$paged['page'];
        } else {
            $citizen_result = getCitizenReportsForManagement(
                $status_filter,
                $is_transport_supervisor,
                $panel_per_page,
                ($ajax_page - 1) * $panel_per_page,
                $search_q
            );
            $total = (int)$citizen_result['total'];
            $max_page = max(1, (int)ceil($total / max(1, $panel_per_page)));
            if ($ajax_page > $max_page) {
                $ajax_page = $max_page;
                $citizen_result = getCitizenReportsForManagement(
                    $status_filter,
                    $is_transport_supervisor,
                    $panel_per_page,
                    ($ajax_page - 1) * $panel_per_page,
                    $search_q
                );
                $total = (int)$citizen_result['total'];
            }
            $rows = $citizen_result['rows'];
            if ($is_transport_supervisor || $is_road_supervisor) {
                foreach ($rows as &$__r) {
                    if (empty($__r['_source_table'])) {
                        $__r['_source_table'] = 'road_transportation_reports';
                    }
                }
                unset($__r);
                annotate_report_assignment_status($conn, $rows);
            }
        }
        $pagination_html = ($total > $panel_per_page)
            ? rm_build_panel_pagination('citizen', $ajax_page, $panel_per_page, $total)['html']
            : '';
        echo json_encode([
            'success' => true,
            'panel' => 'citizen',
            'page' => $ajax_page,
            'total' => $total,
            'per_page' => $panel_per_page,
            'q' => $search_q,
            'rows_html' => rm_render_citizen_panel_tbody($rows, $is_road_supervisor, $is_transport_supervisor, $user_role),
            'pagination_html' => $pagination_html,
            'badge_text' => $total . ' Reports',
        ]);
        exit;
    }

    if ($panel === 'cimm') {
        if ($is_transport_supervisor) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden']);
            exit;
        }
        $search_q = trim((string)($_GET['q'] ?? ''));
        if ($your_reports_only) {
            $cimm_result = getCimmReportsForManagement($status_filter, 500, 0, $search_q);
            foreach ($cimm_result['rows'] as &$__cim) {
                $__cim['_source_table'] = 'cimm_verification_reports';
            }
            unset($__cim);
            $paged = rm_paginate_your_reports(
                $conn,
                $cimm_result['rows'],
                $ajax_page,
                $panel_per_page,
                'cimm_verification_reports'
            );
            $rows = $paged['rows'];
            $total = (int)$paged['total'];
            $ajax_page = (int)$paged['page'];
        } else {
            $cimm_result = getCimmReportsForManagement(
                $status_filter,
                $panel_per_page,
                ($ajax_page - 1) * $panel_per_page,
                $search_q
            );
            $total = (int)$cimm_result['total'];
            $max_page = max(1, (int)ceil($total / max(1, $panel_per_page)));
            if ($ajax_page > $max_page) {
                $ajax_page = $max_page;
                $cimm_result = getCimmReportsForManagement(
                    $status_filter,
                    $panel_per_page,
                    ($ajax_page - 1) * $panel_per_page,
                    $search_q
                );
                $total = (int)$cimm_result['total'];
            }
            $rows = $cimm_result['rows'];
            if ($is_road_supervisor || $user_role === 'system_admin') {
                foreach ($rows as &$__cim) {
                    $__cim['_source_table'] = 'cimm_verification_reports';
                }
                unset($__cim);
                annotate_report_assignment_status($conn, $rows);
            }
        }
        $pagination_html = ($total > $panel_per_page)
            ? rm_build_panel_pagination('cimm', $ajax_page, $panel_per_page, $total)['html']
            : '';
        echo json_encode([
            'success' => true,
            'panel' => 'cimm',
            'page' => $ajax_page,
            'total' => $total,
            'per_page' => $panel_per_page,
            'q' => $search_q,
            'rows_html' => rm_render_cimm_panel_tbody($rows),
            'rows_json' => array_values($rows),
            'pagination_html' => $pagination_html,
            'badge_text' => $total . ' Reports',
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown panel']);
    exit;
}

// Get data — panel lists use dedicated queries; get_reports() no longer feeds Citizen.
$stats = get_report_stats();
$csrf_token = generate_csrf_token();
$flash_message = get_flash_message();

// Transportation Operations supervisors only see LGU Monitoring
// Transportation reports and Citizen reports (no CIMM, infrastructure,
// or LGU Road reports).
// $is_transport_supervisor set above (also used by AJAX panel_page).

// Separate reports by source system for panel display
$citizen_reports = [];
$citizen_reports_total = 0;
$citizen_page = rm_panel_page('citizen');
$citizen_pagination_html = '';
$citizen_search = trim((string)($_GET['citizen_q'] ?? ''));
$lgu_reports_list = [];
$lgu_reports_total = 0;
$lgu_page = rm_panel_page('lgu');
$lgu_pagination_html = '';
$lgu_search = trim((string)($_GET['lgu_q'] ?? ''));
$cimm_reports_list = [];
$cimm_reports_total = 0;
$cimm_page = rm_panel_page('cimm');
$cimm_pagination_html = '';
$cimm_search = trim((string)($_GET['cimm_q'] ?? ''));
// Infrastructure Projects panel reads IPMS rows that are approved,
// cancelled, or in-progress (not pending verification).
$infra_reports_list = [];
if (!$is_transport_supervisor && ($source_filter === 'all' || $source_filter === 'maintenance')) {
    try {
        $infra_reports_list = rgmap_infra_panel_rows(null, ['approved', 'cancelled', 'in-progress']);
        if ($status_filter !== 'all') {
            $infra_reports_list = array_values(array_filter(
                $infra_reports_list,
                static fn($r) => strtolower((string)($r['status'] ?? '')) === strtolower($status_filter)
            ));
        }
    } catch (Exception $e) {
        error_log('IPMS approved projects fetch failed: ' . $e->getMessage());
        $infra_reports_list = [];
    }
}

// LGU Monitoring: own query + LIMIT/OFFSET (reusable panel pagination).
if ($source_filter === 'all' || $source_filter === 'lgu_reports') {
    $lgu_page = rm_panel_page('lgu');
    if ($your_reports_only) {
        $lgu_result = getLguReportsForManagement(
            $status_filter,
            $is_road_supervisor,
            $is_transport_supervisor,
            500,
            0,
            $lgu_search
        );
        $paged = rm_paginate_your_reports($conn, $lgu_result['rows'], $lgu_page, $panel_per_page);
        $lgu_reports_list = $paged['rows'];
        $lgu_reports_total = (int)$paged['total'];
        $lgu_page = (int)$paged['page'];
    } else {
        $lgu_result = getLguReportsForManagement(
            $status_filter,
            $is_road_supervisor,
            $is_transport_supervisor,
            $panel_per_page,
            rm_panel_offset('lgu', $panel_per_page),
            $lgu_search
        );
        $lgu_reports_total = $lgu_result['total'];
        $lgu_max_page = max(1, (int)ceil($lgu_reports_total / max(1, $panel_per_page)));
        if ($lgu_page > $lgu_max_page) {
            $lgu_page = $lgu_max_page;
            $lgu_result = getLguReportsForManagement(
                $status_filter,
                $is_road_supervisor,
                $is_transport_supervisor,
                $panel_per_page,
                ($lgu_page - 1) * $panel_per_page,
                $lgu_search
            );
            $lgu_reports_total = $lgu_result['total'];
        }
        $lgu_reports_list = $lgu_result['rows'];
    }
    if ($lgu_reports_total > $panel_per_page) {
        $lgu_pagination_html = rm_build_panel_pagination('lgu', $lgu_page, $panel_per_page, $lgu_reports_total)['html'];
    }
}

// Citizen Reports: independent query (post-verification only). Hidden for road supervisors.
if (!$is_road_supervisor && ($source_filter === 'all' || $source_filter === 'transport')) {
    $citizen_page = rm_panel_page('citizen');
    if ($your_reports_only) {
        $citizen_result = getCitizenReportsForManagement(
            $status_filter,
            $is_transport_supervisor,
            500,
            0,
            $citizen_search
        );
        $paged = rm_paginate_your_reports($conn, $citizen_result['rows'], $citizen_page, $panel_per_page);
        $citizen_reports = $paged['rows'];
        $citizen_reports_total = (int)$paged['total'];
        $citizen_page = (int)$paged['page'];
    } else {
        $citizen_result = getCitizenReportsForManagement(
            $status_filter,
            $is_transport_supervisor,
            $panel_per_page,
            rm_panel_offset('citizen', $panel_per_page),
            $citizen_search
        );
        $citizen_reports_total = $citizen_result['total'];
        $citizen_max_page = max(1, (int)ceil($citizen_reports_total / max(1, $panel_per_page)));
        if ($citizen_page > $citizen_max_page) {
            $citizen_page = $citizen_max_page;
            $citizen_result = getCitizenReportsForManagement(
                $status_filter,
                $is_transport_supervisor,
                $panel_per_page,
                ($citizen_page - 1) * $panel_per_page,
                $citizen_search
            );
            $citizen_reports_total = $citizen_result['total'];
        }
        $citizen_reports = $citizen_result['rows'];
    }
    if ($citizen_reports_total > $panel_per_page) {
        $citizen_pagination_html = rm_build_panel_pagination('citizen', $citizen_page, $panel_per_page, $citizen_reports_total)['html'];
    }
}

// Completed Infrastructure Projects previously came from
// road_transportation_reports (infrastructure_issue). That panel now uses
// ipms_road_projects with status = approved only, so the completed append
// from the transport table is no longer applied.

// Road/Transportation Operations Supervisors need assignment ownership
// annotations so action buttons and ownership checks can use assigned_by.
if ($is_transport_supervisor || $is_road_supervisor) {
    foreach ($lgu_reports_list as &$__lr) {
        if (empty($__lr['_source_table'])) {
            $__lr['_source_table'] = 'road_transportation_reports';
        }
    }
    unset($__lr);
    foreach ($citizen_reports as &$__cr) {
        if (empty($__cr['_source_table'])) {
            $__cr['_source_table'] = 'road_transportation_reports';
        }
    }
    unset($__cr);
    annotate_report_assignment_status($conn, $lgu_reports_list);
    annotate_report_assignment_status($conn, $citizen_reports);
}

// Fetch CIMM reports independently (paginated; not through get_reports).
$include_cimm = ($source_filter === 'all' || $source_filter === 'cimm');
if ($include_cimm && !$is_transport_supervisor) {
    try {
        $cimm_page = rm_panel_page('cimm');
        $cimm_result = getCimmReportsForManagement(
            $status_filter,
            $panel_per_page,
            rm_panel_offset('cimm', $panel_per_page),
            $cimm_search
        );
        $cimm_reports_total = $cimm_result['total'];
        $cimm_max_page = max(1, (int)ceil($cimm_reports_total / max(1, $panel_per_page)));
        if ($cimm_page > $cimm_max_page) {
            $cimm_page = $cimm_max_page;
            $cimm_result = getCimmReportsForManagement(
                $status_filter,
                $panel_per_page,
                ($cimm_page - 1) * $panel_per_page,
                $cimm_search
            );
            $cimm_reports_total = $cimm_result['total'];
        }
        $cimm_reports_list = $cimm_result['rows'];
        if ($cimm_reports_total > $panel_per_page) {
            $cimm_pagination_html = rm_build_panel_pagination('cimm', $cimm_page, $panel_per_page, $cimm_reports_total)['html'];
        }
    } catch (Exception $e) {
        error_log("CIMM panel fetch failed: " . $e->getMessage());
        $cimm_reports_list = [];
        $cimm_reports_total = 0;
        $cimm_pagination_html = '';
    }
}

// Annotate CIMM / IPMS ownership for Road Ops Supervisors (and admins for UI consistency).
if ($is_road_supervisor || ($user_role ?? '') === 'system_admin') {
    if (!empty($cimm_reports_list)) {
        foreach ($cimm_reports_list as &$__cim) {
            $__cim['_source_table'] = 'cimm_verification_reports';
        }
        unset($__cim);
        annotate_report_assignment_status($conn, $cimm_reports_list);
    }
    if (!empty($infra_reports_list)) {
        foreach ($infra_reports_list as &$__inf) {
            $__inf['_source_table'] = 'ipms_road_projects';
        }
        unset($__inf);
        annotate_report_assignment_status($conn, $infra_reports_list);
    }
}

if ($your_reports_only) {
    if (!empty($cimm_reports_list)) {
        $cimm_reports_list = rgmap_filter_reports_you_handle($conn, $cimm_reports_list);
        $cimm_reports_total = count($cimm_reports_list);
        $cimm_pagination_html = '';
    }
    if (!empty($infra_reports_list)) {
        $infra_reports_list = rgmap_filter_reports_you_handle($conn, $infra_reports_list);
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

        $transport_focus_query = function ($id) use ($conn, $transport_est_col, $is_road_supervisor, $is_transport_supervisor) {
            $row = fetch_one("SELECT id, report_id, title, description, location, latitude, longitude, priority, status, assigned_to, {$transport_est_col}, resolution_notes as notes, department, created_date, created_at, updated_at, approved_at, attachments, image_path, report_type, report_category, report_source, created_by, CASE WHEN report_type = 'infrastructure_issue' THEN 'maintenance' WHEN report_category = 'transportation' AND report_source = 'local' AND created_by != 0 AND status = 'approved' THEN 'lgu_reports' WHEN report_category = 'transportation' AND report_source = 'local' AND created_by != 0 THEN 'hidden' ELSE 'transport' END as source_system FROM road_transportation_reports WHERE id = ?", [$id], 'i');
            // Road Operations Supervisors never see Transportation reports —
            // do not reveal them even via a deep-link.
            if ($is_road_supervisor && ($row['report_category'] ?? '') === 'transportation') {
                return null;
            }
            // Transportation Operations Supervisors never see Road LGU reports —
            // do not reveal them even via a deep-link.
            if ($is_transport_supervisor && ($row['report_category'] ?? '') === 'road') {
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
            if (strtolower((string)($focus_report['status'] ?? '')) === 'completed') {
                $redirect = '../shared/completed_projects.php?focus_report_id=' . (int)$focus_id;
                if ($focus_source !== '') {
                    $redirect .= '&source=' . urlencode($focus_source);
                }
                header('Location: ' . $redirect);
                exit;
            }
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
    <link rel="icon" type="image/png" href="../../assets/img/infra-gov-logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/theme-tokens.css">
    <link rel="stylesheet" href="../../css/theme-utilities.css">
    <link rel="stylesheet" href="../../css/sidebar.css?v=6">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <link rel="stylesheet" href="../../css/progress-updates.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="../../js/progress-updates.js?v=<?php echo filemtime(__DIR__ . '/../../js/progress-updates.js'); ?>"></script>
    <script src="../../js/progress-updates-common.js?v=<?php echo filemtime(__DIR__ . '/../../js/progress-updates-common.js'); ?>"></script>
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

        .dt-chip {
            display: flex; align-items: center; gap: 10px;
            background: var(--color-primary-bg, #eef2ff);
            border: 1px solid var(--border-default, #d5dce8);
            border-radius: 14px; padding: 10px 14px;
            flex-shrink: 0;
        }
        .dt-chip i {
            color: #fff; font-size: 16px; width: 28px; height: 28px; border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #1e3c72, #0f274a);
        }
        .dt-chip #currentDate { font-weight: 600; color: var(--text-primary, #0f172a); font-size: 13px; }
        .dt-chip #currentTime { color: var(--text-secondary, #64748b); font-size: 12px; margin-top: 1px; }

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

        .btn-your-reports {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 16px;
            min-height: 38px;
            border: 1px solid #3762c8;
            border-radius: 8px;
            background: #3762c8;
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            line-height: 1.2;
            cursor: pointer;
            white-space: nowrap;
            box-sizing: border-box;
            transition: background 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .btn-your-reports:hover {
            background: #1e3c72;
            border-color: #1e3c72;
            color: #fff;
        }
        .btn-your-reports i { pointer-events: none; }

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

        /* ── Update Report / Assign To modal polish (UI only) ── */
        #editReportModal.urm-modal .urm-content,
        #assignUserModal.urm-modal .urm-content {
            width: min(720px, 94vw);
            max-width: 720px;
            max-height: min(92vh, 900px);
            margin: 2.5vh auto;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            border: 1px solid rgba(30, 60, 114, 0.12);
            box-shadow: 0 24px 64px rgba(15, 39, 74, 0.22);
        }
        #editReportModal.urm-modal .urm-content > form {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }
        #assignUserModal.urm-modal .asm-content {
            width: min(640px, 94vw);
            max-width: 640px;
        }
        #editReportModal .urm-header,
        #assignUserModal .urm-header {
            padding: 18px 22px;
            background: linear-gradient(135deg, #1e3c72 0%, #3762c8 100%);
            align-items: flex-start;
        }
        #editReportModal .urm-kicker,
        #assignUserModal .urm-kicker {
            margin: 0 0 4px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.72);
        }
        #editReportModal .urm-header .modal-title,
        #assignUserModal .urm-header .modal-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.15rem;
            font-weight: 700;
            letter-spacing: -0.01em;
            color: #fff !important;
        }
        #editReportModal .urm-header .modal-title i,
        #assignUserModal .urm-header .modal-title i {
            color: #fff !important;
        }
        #editReportModal .urm-close,
        #assignUserModal .urm-close {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.12);
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        #editReportModal .urm-close:hover,
        #assignUserModal .urm-close:hover {
            background: rgba(255, 255, 255, 0.22);
            opacity: 1;
        }
        #editReportModal .urm-body,
        #assignUserModal .urm-body {
            padding: 20px 22px 8px;
            max-height: none;
            flex: 1;
            overflow-y: auto;
            background: #f7f9fc;
        }
        #editReportModal .urm-section,
        #assignUserModal .urm-section {
            background: #fff;
            border: 1px solid #e6ebf2;
            border-radius: 14px;
            padding: 16px 18px;
            margin-bottom: 14px;
            box-shadow: 0 1px 2px rgba(15, 39, 74, 0.04);
        }
        #editReportModal .urm-section h6 {
            margin: 0 0 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eef2f7;
            color: #1e3c72;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }
        #editReportModal .urm-section-head h6 {
            margin-bottom: 6px;
            padding-bottom: 0;
            border-bottom: none;
        }
        #editReportModal .urm-section-hint,
        #assignUserModal .urm-section-hint {
            margin: 0 0 12px;
            color: #64748b;
            font-size: 12.5px;
            line-height: 1.45;
        }
        #editReportModal .urm-section-readonly .form-control.urm-readonly,
        #editReportModal .urm-section-readonly textarea.urm-readonly {
            background: #eef2f7;
            color: #475569;
            border-color: #d5dde8;
            cursor: default;
            box-shadow: none;
            resize: none;
        }
        #editReportModal .urm-section-readonly .form-control.urm-readonly:focus,
        #editReportModal .urm-section-readonly textarea.urm-readonly:focus {
            border-color: #d5dde8;
            box-shadow: none;
            outline: none;
        }
        #editReportModal .urm-section-readonly .form-label::after {
            content: ' (read-only)';
            font-weight: 500;
            color: #94a3b8;
            font-size: 11px;
        }
        #editReportModal .urm-field-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
        #editReportModal .urm-section .form-label,
        #assignUserModal .form-label {
            font-size: 12px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 6px;
        }
        #editReportModal .urm-section .form-control,
        #assignUserModal .form-control {
            border: 1px solid #d7dee8;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 14px;
            background: #fff;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        #editReportModal .urm-section .form-control:focus,
        #assignUserModal .form-control:focus {
            border-color: #3762c8;
            box-shadow: 0 0 0 3px rgba(55, 98, 200, 0.14);
        }
        #editReportModal .urm-select-wrap {
            position: relative;
        }
        #editReportModal .urm-select {
            appearance: none;
            -webkit-appearance: none;
            width: 100%;
            cursor: pointer;
            padding-right: 2.4rem;
            background-color: #fff;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%231e3c72' viewBox='0 0 16 16'%3E%3Cpath d='M4.646 6.646a.5.5 0 0 1 .708 0L8 9.293l2.646-2.647a.5.5 0 0 1 .708.708l-3 3a.5.5 0 0 1-.708 0l-3-3a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.85rem center;
            background-size: 14px;
        }
        #editReportModal .urm-assign-section {
            background: linear-gradient(180deg, #f8fbff 0%, #ffffff 42%);
            border-color: #d9e5f7;
        }
        #editReportModal .urm-assign-actions {
            margin-bottom: 14px;
        }
        #editReportModal .urm-btn-assign {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border: 1px solid #c5d4ef;
            border-radius: 10px;
            background: #fff;
            color: #1e3c72;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
        }
        #editReportModal .urm-btn-assign:hover {
            background: #eef4ff;
            border-color: #3762c8;
            box-shadow: 0 4px 12px rgba(55, 98, 200, 0.12);
        }
        #editReportModal .urm-assigned-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 8px;
            min-height: 48px;
        }
        #editReportModal .asg-card,
        #editCimmModal .asg-card,
        #editIpmsModal .asg-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        }
        #editReportModal .asg-avatar,
        #editCimmModal .asg-avatar,
        #editIpmsModal .asg-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 16px;
        }
        #editReportModal .asg-meta,
        #editCimmModal .asg-meta,
        #editIpmsModal .asg-meta { flex: 1; min-width: 0; }
        #editReportModal .asg-name,
        #editCimmModal .asg-name,
        #editIpmsModal .asg-name {
            font-weight: 700;
            font-size: 14px;
            color: #0f172a;
            line-height: 1.3;
        }
        #editReportModal .asg-role,
        #editCimmModal .asg-role,
        #editIpmsModal .asg-role {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 4px;
            padding: 3px 9px;
            border-radius: 999px;
            background: rgba(55, 98, 200, 0.1);
            color: #1e3c72;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }
        #editReportModal .asg-date,
        #editCimmModal .asg-date,
        #editIpmsModal .asg-date {
            margin-top: 4px;
            font-size: 12px;
            color: #64748b;
        }
        #editReportModal .asg-remove-btn,
        #editCimmModal .asg-remove-btn,
        #editIpmsModal .asg-remove-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 12px;
            border: none;
            border-radius: 8px;
            background: #fee2e2;
            color: #b91c1c;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            flex-shrink: 0;
        }
        #editReportModal .asg-remove-btn:hover,
        #editCimmModal .asg-remove-btn:hover,
        #editIpmsModal .asg-remove-btn:hover {
            background: #fecaca;
        }
        #editReportModal .asg-empty {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 16px;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
            color: #64748b;
            font-size: 13px;
        }
        #editReportModal .urm-photo-grid {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        #editReportModal .urm-photo-preview { margin-top: 10px; margin-bottom: 0; }
        #editReportModal .urm-btn-photo {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 14px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }
        #editReportModal .urm-help {
            display: block;
            margin-top: 8px;
            font-size: 12px;
        }
        #editReportModal .urm-footer,
        #assignUserModal .urm-footer {
            padding: 14px 22px;
            background: #fff;
            border-top: 1px solid #e6ebf2;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        #editReportModal .urm-footer-actions,
        #assignUserModal .urm-footer-actions {
            display: flex;
            gap: 10px;
            margin-left: auto;
        }
        #assignUserModal .urm-footer-actions-end { margin-left: auto; }
        #editReportModal .urm-btn-cancel,
        #assignUserModal .urm-btn-cancel {
            min-width: 104px;
            padding: 10px 18px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
        }
        #editReportModal .urm-btn-save,
        #assignUserModal .urm-btn-save {
            min-width: 140px;
            padding: 10px 18px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        #editReportModal .urm-status-indicator { font-size: 12px; }
        #editReportModal .urm-optional,
        #assignUserModal .urm-optional {
            font-weight: 500;
            color: #94a3b8;
        }

        /* Assign staff picker */
        #assignUserModal .asm-selected-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            margin-bottom: 14px;
            border-radius: 12px;
            border: 1px solid #bfdbfe;
            background: #eff6ff;
        }
        #assignUserModal .asm-selected-bar[hidden] { display: none !important; }
        #assignUserModal .asm-selected-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(55, 98, 200, 0.15);
            color: #1e3c72;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        #assignUserModal .asm-selected-meta {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        #assignUserModal .asm-selected-label {
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        #assignUserModal .asm-selected-meta strong {
            font-size: 14px;
            color: #0f172a;
        }
        #assignUserModal .asm-selected-role {
            font-size: 12px;
            color: #1e3c72;
            font-weight: 600;
        }
        #assignUserModal .asm-clear-btn {
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 8px;
            background: rgba(15, 23, 42, 0.06);
            color: #475569;
            cursor: pointer;
        }
        #assignUserModal .asm-staff-list {
            max-height: min(340px, 42vh);
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #fff;
            padding: 6px;
        }
        #assignUserModal .usr-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-radius: 10px;
            border: 1px solid transparent;
            cursor: pointer;
            transition: background 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
            margin-bottom: 4px;
        }
        #assignUserModal .usr-card:last-child { margin-bottom: 0; }
        #assignUserModal .usr-card:hover:not(.usr-disabled) {
            background: #f8fafc;
            border-color: #e2e8f0;
        }
        #assignUserModal .usr-card.usr-selected {
            background: #eff6ff;
            border-color: #93c5fd;
            box-shadow: 0 0 0 2px rgba(55, 98, 200, 0.12);
        }
        #assignUserModal .usr-card.usr-disabled {
            opacity: 0.72;
            cursor: default;
            background: #f8fafc;
        }
        #assignUserModal .usr-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 18px;
            border: 2px solid transparent;
        }
        #assignUserModal .usr-meta { flex: 1; min-width: 0; }
        #assignUserModal .usr-name {
            font-weight: 700;
            font-size: 14px;
            color: #0f172a;
        }
        #assignUserModal .usr-email {
            margin-top: 2px;
            font-size: 12px;
            color: #64748b;
        }
        #assignUserModal .usr-role {
            display: inline-flex;
            margin-top: 6px;
            padding: 3px 9px;
            border-radius: 999px;
            background: rgba(55, 98, 200, 0.1);
            color: #1e3c72;
            font-size: 11px;
            font-weight: 700;
        }
        #assignUserModal .usr-side {
            text-align: right;
            flex-shrink: 0;
        }
        #assignUserModal .usr-active {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 6px;
        }
        #assignUserModal .usr-badge-assigned,
        #assignUserModal .usr-badge-assign {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
        }
        #assignUserModal .usr-badge-assigned {
            background: #d1fae5;
            color: #047857;
        }
        #assignUserModal .usr-badge-assign {
            background: #dbeafe;
            color: #1d4ed8;
        }
        #assignUserModal .asm-list-loading,
        #assignUserModal .usr-muted,
        #assignUserModal .usr-error {
            text-align: center;
            padding: 28px 16px;
            color: #64748b;
            font-size: 13px;
        }
        #assignUserModal .usr-error { color: #dc2626; }

        @media (max-width: 640px) {
            #editReportModal.urm-modal .urm-content,
            #assignUserModal.urm-modal .urm-content {
                width: 100vw;
                max-width: 100vw;
                margin: 0;
                max-height: 100vh;
                border-radius: 0;
                min-height: 100vh;
            }
            #editReportModal .urm-field-row {
                grid-template-columns: 1fr;
            }
            #editReportModal .urm-footer,
            #assignUserModal .urm-footer {
                flex-direction: column;
                align-items: stretch;
            }
            #editReportModal .urm-footer-actions,
            #assignUserModal .urm-footer-actions {
                width: 100%;
                margin-left: 0;
            }
            #editReportModal .urm-btn-cancel,
            #editReportModal .urm-btn-save,
            #assignUserModal .urm-btn-cancel,
            #assignUserModal .urm-btn-save {
                flex: 1;
            }
            #assignUserModal .usr-card {
                flex-wrap: wrap;
            }
            #assignUserModal .usr-side {
                width: 100%;
                text-align: left;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 8px;
            }
        }

        /* Dark-mode compatibility for the rm-edit-btn modals (Update Report /
           Edit CIMM Report / Assign Staff to Project) — Road / Transportation
           Operations Supervisors and System Admin only */
        <?php if ($is_transport_supervisor || $is_road_supervisor || $is_system_admin): ?>
        body.dark-mode #editReportModal .modal-content,
        body.dark-mode #editCimmModal .modal-content,
        body.dark-mode #assignUserModal .modal-content {
            background: #1e2229 !important;
            border: 1px solid #2d323b !important;
        }
        body.dark-mode #editReportModal .modal-body,
        body.dark-mode #editCimmModal .modal-body,
        body.dark-mode #assignUserModal .modal-body {
            background: #1e2229 !important;
        }
        body.dark-mode #editReportModal .form-section,
        body.dark-mode #editCimmModal .form-section {
            background: #22262e !important;
            border-color: #2d323b !important;
        }
        body.dark-mode #editReportModal .form-section h6,
        body.dark-mode #editCimmModal .form-section h6 { color: #93c5fd !important; }
        body.dark-mode #editReportModal .form-label,
        body.dark-mode #editCimmModal .form-label,
        body.dark-mode #assignUserModal .form-label { color: #e4e6ea !important; }
        body.dark-mode #editReportModal .form-control,
        body.dark-mode #editCimmModal .form-control,
        body.dark-mode #assignUserModal .form-control {
            background: #1a1d23 !important;
            color: #e4e6ea !important;
            border-color: #3a3f4a !important;
        }
        body.dark-mode #editReportModal .modal-footer,
        body.dark-mode #editCimmModal .modal-footer,
        body.dark-mode #assignUserModal .modal-footer {
            background: #1e2229 !important;
            border-top-color: #2d323b !important;
        }
        body.dark-mode #editReportModal .btn-secondary-custom,
        body.dark-mode #editCimmModal .btn-secondary-custom,
        body.dark-mode #assignUserModal .btn-secondary-custom {
            background: rgba(148, 163, 184, 0.12) !important;
            color: #cbd5e1 !important;
            border-color: #475569 !important;
        }
        body.dark-mode #editReportModal .btn-secondary-custom:hover,
        body.dark-mode #editCimmModal .btn-secondary-custom:hover,
        body.dark-mode #assignUserModal .btn-secondary-custom:hover {
            background: #475569 !important;
            color: #fff !important;
        }
        body.dark-mode #editReportModal .t-text-secondary,
        body.dark-mode #editCimmModal .t-text-secondary,
        body.dark-mode #assignUserModal .t-text-secondary { color: #94a3b8 !important; }

        /* Assigned staff cards injected by loadAssignedUsers() */
        body.dark-mode #editReportModal .asg-card,
        body.dark-mode #editCimmModal .asg-card {
            background: linear-gradient(135deg, #263449 0%, #1e293b 100%) !important;
            border-color: #334155 !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.35) !important;
        }
        body.dark-mode #editReportModal .asg-card div,
        body.dark-mode #editCimmModal .asg-card div { color: #cbd5e1 !important; }
        body.dark-mode #editReportModal .asg-card div div:first-child,
        body.dark-mode #editCimmModal .asg-card div div:first-child { color: #e2e8f0 !important; }
        body.dark-mode #editReportModal .asg-empty,
        body.dark-mode #editCimmModal .asg-empty {
            background: #1e293b !important;
            border-color: #334155 !important;
        }
        body.dark-mode #editReportModal .asg-empty span,
        body.dark-mode #editCimmModal .asg-empty span,
        body.dark-mode #editReportModal .asg-empty i,
        body.dark-mode #editCimmModal .asg-empty i { color: #94a3b8 !important; }
        body.dark-mode #editReportModal .asg-error,
        body.dark-mode #editCimmModal .asg-error {
            background: rgba(127, 29, 29, 0.25) !important;
            border-color: #7f1d1d !important;
        }
        body.dark-mode #editReportModal .asg-error span,
        body.dark-mode #editCimmModal .asg-error span,
        body.dark-mode #editReportModal .asg-error i,
        body.dark-mode #editCimmModal .asg-error i { color: #fca5a5 !important; }
        body.dark-mode #editReportModal .asg-muted,
        body.dark-mode #editCimmModal .asg-muted { color: #94a3b8 !important; }

        /* Available staff list injected by openAssignUserModal() */
        body.dark-mode #assignUserModal #availableUsersList {
            background: #1a1d23 !important;
            border-color: #3a3f4a !important;
        }
        body.dark-mode #assignUserModal .usr-card {
            background-color: #1e293b !important;
            border-bottom-color: #334155 !important;
        }
        body.dark-mode #assignUserModal .usr-card.usr-selected {
            background-color: rgba(55, 98, 200, 0.25) !important;
        }
        body.dark-mode #assignUserModal .usr-card div { color: #cbd5e1 !important; }
        body.dark-mode #assignUserModal .usr-card div div:first-child { color: #e2e8f0 !important; }
        body.dark-mode #assignUserModal .usr-muted { color: #94a3b8 !important; }
        body.dark-mode #assignUserModal .usr-error { color: #fca5a5 !important; }
        body.dark-mode #assignUserModal .usr-badge-assigned {
            background: rgba(5, 150, 105, 0.28) !important;
            color: #6ee7b7 !important;
        }
        body.dark-mode #assignUserModal .usr-badge-assign {
            background: rgba(30, 64, 175, 0.35) !important;
            color: #93c5fd !important;
        }
        body.dark-mode #editReportModal .modal-content,
        body.dark-mode #editCimmModal .modal-content,
        body.dark-mode #assignUserModal .modal-content {
            background: #1e2229 !important;
            border: 1px solid #2d323b !important;
        }
        body.dark-mode #editReportModal .modal-body,
        body.dark-mode #editCimmModal .modal-body,
        body.dark-mode #assignUserModal .modal-body {
            background: #1e2229 !important;
        }
        body.dark-mode #editReportModal .form-section,
        body.dark-mode #editCimmModal .form-section {
            background: #22262e !important;
            border-color: #2d323b !important;
        }
        body.dark-mode #editReportModal .form-section h6,
        body.dark-mode #editCimmModal .form-section h6 { color: #93c5fd !important; }
        body.dark-mode #editReportModal .form-label,
        body.dark-mode #editCimmModal .form-label,
        body.dark-mode #assignUserModal .form-label { color: #e4e6ea !important; }
        body.dark-mode #editReportModal .form-control,
        body.dark-mode #editCimmModal .form-control,
        body.dark-mode #assignUserModal .form-control {
            background: #1a1d23 !important;
            color: #e4e6ea !important;
            border-color: #3a3f4a !important;
        }
        body.dark-mode #editReportModal .modal-footer,
        body.dark-mode #editCimmModal .modal-footer,
        body.dark-mode #assignUserModal .modal-footer {
            background: #1e2229 !important;
            border-top-color: #2d323b !important;
        }
        body.dark-mode #editReportModal .btn-secondary-custom,
        body.dark-mode #editCimmModal .btn-secondary-custom,
        body.dark-mode #assignUserModal .btn-secondary-custom {
            background: rgba(148, 163, 184, 0.12) !important;
            color: #cbd5e1 !important;
            border-color: #475569 !important;
        }
        body.dark-mode #editReportModal .btn-secondary-custom:hover,
        body.dark-mode #editCimmModal .btn-secondary-custom:hover,
        body.dark-mode #assignUserModal .btn-secondary-custom:hover {
            background: #475569 !important;
            color: #fff !important;
        }
        body.dark-mode #editReportModal .t-text-secondary,
        body.dark-mode #editCimmModal .t-text-secondary,
        body.dark-mode #assignUserModal .t-text-secondary { color: #94a3b8 !important; }

        /* Assigned staff cards injected by loadAssignedUsers() */
        body.dark-mode #editReportModal .asg-card,
        body.dark-mode #editCimmModal .asg-card {
            background: linear-gradient(135deg, #263449 0%, #1e293b 100%) !important;
            border-color: #334155 !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.35) !important;
        }
        body.dark-mode #editReportModal .asg-card div,
        body.dark-mode #editCimmModal .asg-card div { color: #cbd5e1 !important; }
        body.dark-mode #editReportModal .asg-card div div:first-child,
        body.dark-mode #editCimmModal .asg-card div div:first-child { color: #e2e8f0 !important; }
        body.dark-mode #editReportModal .asg-empty,
        body.dark-mode #editCimmModal .asg-empty {
            background: #1e293b !important;
            border-color: #334155 !important;
        }
        body.dark-mode #editReportModal .asg-empty span,
        body.dark-mode #editCimmModal .asg-empty span,
        body.dark-mode #editReportModal .asg-empty i,
        body.dark-mode #editCimmModal .asg-empty i { color: #94a3b8 !important; }
        body.dark-mode #editReportModal .asg-error,
        body.dark-mode #editCimmModal .asg-error {
            background: rgba(127, 29, 29, 0.25) !important;
            border-color: #7f1d1d !important;
        }
        body.dark-mode #editReportModal .asg-error span,
        body.dark-mode #editCimmModal .asg-error span,
        body.dark-mode #editReportModal .asg-error i,
        body.dark-mode #editCimmModal .asg-error i { color: #fca5a5 !important; }
        body.dark-mode #editReportModal .asg-muted,
        body.dark-mode #editCimmModal .asg-muted { color: #94a3b8 !important; }

        /* Available staff list injected by openAssignUserModal() */
        body.dark-mode #assignUserModal #availableUsersList {
            background: #1a1d23 !important;
            border-color: #3a3f4a !important;
        }
        body.dark-mode #assignUserModal .usr-card {
            background-color: #1e293b !important;
            border-bottom-color: #334155 !important;
        }
        body.dark-mode #assignUserModal .usr-card.usr-selected {
            background-color: rgba(55, 98, 200, 0.25) !important;
        }
        body.dark-mode #assignUserModal .usr-card div { color: #cbd5e1 !important; }
        body.dark-mode #assignUserModal .usr-card div div:first-child { color: #e2e8f0 !important; }
        body.dark-mode #assignUserModal .usr-muted { color: #94a3b8 !important; }
        body.dark-mode #assignUserModal .usr-error { color: #fca5a5 !important; }
        body.dark-mode #assignUserModal .usr-badge-assigned {
            background: rgba(5, 150, 105, 0.28) !important;
            color: #6ee7b7 !important;
        }
        body.dark-mode #assignUserModal .usr-badge-assign {
            background: rgba(30, 64, 175, 0.35) !important;
            color: #93c5fd !important;
        }
        body.dark-mode #editIpmsModal .modal-content {
            background: #1e2229 !important;
            border: 1px solid #2d323b !important;
        }
        body.dark-mode #editIpmsModal .modal-body,
        body.dark-mode #editIpmsModal .modal-footer {
            background: #1e2229 !important;
        }
        body.dark-mode #editIpmsModal .modal-footer { border-top-color: #2d323b !important; }
        body.dark-mode #editIpmsModal .form-section {
            background: #22262e !important;
            border-color: #2d323b !important;
        }
        body.dark-mode #editIpmsModal .form-section h6 { color: #93c5fd !important; }
        body.dark-mode #editIpmsModal .form-label { color: #e4e6ea !important; }
        body.dark-mode #editIpmsModal .form-control {
            background: #1a1d23 !important;
            color: #e4e6ea !important;
            border-color: #3a3f4a !important;
        }
        body.dark-mode #editIpmsModal .btn-secondary-custom {
            background: rgba(148, 163, 184, 0.12) !important;
            color: #cbd5e1 !important;
            border-color: #475569 !important;
        }
        body.dark-mode #editIpmsModal .t-text-secondary,
        body.dark-mode #editIpmsModal .asg-muted { color: #94a3b8 !important; }
        body.dark-mode #editIpmsModal .asg-card {
            background: linear-gradient(135deg, #263449 0%, #1e293b 100%) !important;
            border-color: #334155 !important;
        }
        body.dark-mode #editIpmsModal .asg-empty {
            background: #1e293b !important;
            border-color: #334155 !important;
        }
        <?php endif; ?>
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

        .rm-panel-pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 16px;
            border-top: 1px solid rgba(55, 98, 200, 0.12);
            flex-wrap: wrap;
        }
        .rm-panel-pagination-info {
            font-size: 13px;
            color: #4b5563;
        }
        body.dark-mode .rm-panel-pagination-info {
            color: #9ca3af;
        }
        .rm-panel-pagination-controls {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .rm-page-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border: none;
            border-radius: 8px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: #fff;
            text-decoration: none;
            font-size: 12px;
            cursor: pointer;
            padding: 0;
        }
        .rm-page-btn:hover:not(.disabled) {
            box-shadow: 0 4px 12px rgba(55, 98, 200, 0.3);
        }
        .rm-page-btn.disabled {
            opacity: 0.4;
            pointer-events: none;
            cursor: default;
        }
        .rm-panel-pagination-slot.is-loading {
            opacity: 0.55;
            pointer-events: none;
        }
        .rm-page-label {
            font-size: 13px;
            font-weight: 600;
            color: #1e3c72;
        }
        body.dark-mode .rm-page-label {
            color: #93c5fd;
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

        .rm-category-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.2px;
            white-space: nowrap;
        }
        .rm-cat-road {
            background: rgba(55, 98, 200, 0.12);
            color: #1e3c72;
        }
        .rm-cat-transportation {
            background: rgba(14, 165, 233, 0.16);
            color: #0369a1;
        }
        body.dark-mode .rm-cat-road {
            background: rgba(96, 165, 250, 0.18);
            color: #93c5fd;
        }
        body.dark-mode .rm-cat-transportation {
            background: rgba(56, 189, 248, 0.2);
            color: #7dd3fc;
        }

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
            flex-direction: row;
            flex-wrap: nowrap;
            gap: 4px;
            align-items: center;
            white-space: nowrap;
        }

        .rm-action-group > * {
            flex-shrink: 0;
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

        .rm-view-map-btn {
            margin-left: auto;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            background: rgba(249, 115, 22, 0.1);
            color: #f97316;
            border: 1px solid rgba(249, 115, 22, 0.3);
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: all 0.2s;
        }

        .rm-view-map-btn:hover {
            background: rgba(249, 115, 22, 0.2);
        }

        .road-map-container {
            display: none;
            margin-top: 12px;
            height: 320px;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid rgba(249, 115, 22, 0.15);
        }

        .road-map-container.road-map-visible {
            display: block;
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
            .rm-panel-search { flex-direction: row; }
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

        /* ── Match verification_monitoring (theme-aware, UI only) ── */
        body { background: #f5f3ee; color: var(--text-primary); }
        body.dark-mode { background: var(--bg-page); }
        .rm-dash { padding: 24px 28px; max-width: 100%; overflow-x: hidden; }

        .rm-dash .dashboard-header,
        .rm-dash .filters-section,
        .rm-dash .rm-panel {
            background: #f4f7fb;
            border: 1px solid #d5dce8;
            border-radius: 14px;
            box-shadow: var(--shadow-card);
            overflow: hidden;
            margin-bottom: 16px;
        }
        .rm-dash .dashboard-header {
            padding: 20px 22px;
            backdrop-filter: none;
        }
        .rm-dash .welcome-section { margin-bottom: 0; gap: 12px; }
        .rm-dash .welcome-text h1 {
            color: var(--text-primary);
            font-size: 22px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 4px;
        }
        .rm-dash .header-icon {
            width: 40px; height: 40px; border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--color-primary-bg);
            color: var(--color-primary);
            font-size: 16px;
            flex-shrink: 0;
        }
        .rm-dash .welcome-text p { color: var(--text-secondary); font-size: 13px; margin: 0; }

        .rm-dash .quick-stats {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 16px;
        }
        .rm-dash .stat-card {
            position: relative;
            overflow: hidden;
            min-width: 0;
            background: #f4f7fb;
            border: 1px solid #d5dce8;
            box-shadow: var(--shadow-card);
            border-radius: 14px;
            padding: 16px 18px;
            backdrop-filter: none;
            transform: none;
        }
        .rm-dash .stat-card:hover { transform: none; box-shadow: var(--shadow-card); }
        .rm-dash .stat-card::before { height: 3px; background: var(--border-default); }
        .rm-dash .stat-card:nth-child(1)::before { background: var(--color-primary); }
        .rm-dash .stat-card:nth-child(2)::before { background: var(--color-warning); }
        .rm-dash .stat-card:nth-child(3)::before { background: var(--color-primary); }
        .rm-dash .stat-card:nth-child(4)::before { background: var(--color-success); }
        .rm-dash .stat-card:nth-child(5)::before { background: var(--color-success); }
        .rm-dash .stat-card:nth-child(6)::before { background: var(--color-danger); }
        .rm-dash .stat-icon {
            width: 40px; height: 40px; border-radius: 10px; font-size: 15px;
            margin-bottom: 10px;
            background: var(--color-primary-bg) !important;
            color: var(--color-primary) !important;
        }
        .rm-dash .stat-card:nth-child(2) .stat-icon { background: var(--color-warning-bg) !important; color: var(--color-warning) !important; }
        .rm-dash .stat-card:nth-child(3) .stat-icon { background: var(--color-primary-bg) !important; color: var(--color-primary) !important; }
        .rm-dash .stat-card:nth-child(4) .stat-icon { background: var(--color-success-bg) !important; color: var(--color-success) !important; }
        .rm-dash .stat-card:nth-child(5) .stat-icon { background: var(--color-success-bg) !important; color: var(--color-success) !important; }
        .rm-dash .stat-card:nth-child(6) .stat-icon { background: var(--color-danger-bg) !important; color: var(--color-danger) !important; }
        .rm-dash .stat-number {
            font-size: 22px; font-weight: 700; color: var(--text-primary);
            letter-spacing: -0.03em; margin-bottom: 2px;
        }
        .rm-dash .stat-label {
            color: var(--text-secondary); font-size: 12px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.3px;
        }

        .rm-dash .filters-section { padding: 16px 20px; backdrop-filter: none; }
        .rm-dash .chart-header { margin-bottom: 12px; }
        .rm-dash .chart-title { color: var(--text-primary); font-size: 15px; }
        .rm-dash .form-label { color: var(--text-secondary); font-weight: 600; font-size: 12px; }
        .rm-dash .filter-select {
            background: var(--bg-input);
            color: var(--text-primary);
            border: 1px solid var(--border-input);
            border-radius: 8px;
            min-width: 0;
            width: 100%;
        }
        .rm-dash .filter-group { gap: 14px; }
        .rm-dash .filter-group > div { min-width: 160px; }
        .rm-dash .btn-wrapper { flex-wrap: wrap; }
        .rm-dash .btn-secondary-custom {
            background: #3762c8;
            color: #fff;
            border: 1px solid #3762c8;
            border-radius: 8px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 16px;
            min-height: 38px;
            font-size: 13px;
            box-sizing: border-box;
            white-space: nowrap;
        }
        .rm-dash .btn-secondary-custom:hover {
            background: #1e3c72;
            color: #fff;
            border-color: #1e3c72;
            transform: none;
        }
        .rm-dash .btn-your-reports {
            background: #3762c8;
            color: #fff;
            border: 1px solid #3762c8;
            border-radius: 8px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 16px;
            min-height: 38px;
            font-size: 13px;
            box-sizing: border-box;
            white-space: nowrap;
        }
        .rm-dash .btn-your-reports:hover {
            background: #1e3c72;
            border-color: #1e3c72;
            color: #fff;
        }
        .rm-dash .btn-success-custom {
            background: #3762c8;
            color: #fff;
            border: 1px solid #3762c8;
            border-radius: 8px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 16px;
            min-height: 38px;
            font-size: 13px;
            box-sizing: border-box;
            white-space: nowrap;
            transform: none;
        }
        .rm-dash .btn-success-custom:hover {
            background: #1e3c72;
            border-color: #1e3c72;
            color: #fff;
        }

        .rm-dash #lguReportsPanel.rm-panel {
            background: #f4f7fb;
            border-color: #c8d0e0;
            border-left: 3px solid #1e3c72;
            box-shadow: 0 2px 10px rgba(30, 60, 114, 0.07);
        }
        .rm-dash #citizenReportsPanel.rm-panel {
            background: #f4faf6;
            border-color: #cce0d4;
            border-left: 3px solid #16a34a;
            box-shadow: 0 2px 10px rgba(22, 163, 74, 0.07);
        }
        .rm-dash #cimmReportsPanel.rm-panel {
            background: #f5f3f8;
            border-color: #d4cfe0;
            border-left: 3px solid #4f4568;
            box-shadow: 0 2px 10px rgba(79, 69, 104, 0.08);
        }
        .rm-dash #infraReportsPanel.rm-panel {
            background: #fff9f4;
            border-color: #f0e0cc;
            border-left: 3px solid #f97316;
            box-shadow: 0 2px 10px rgba(249, 115, 22, 0.07);
        }

        .rm-dash .rm-panel-header { padding: 16px 20px; background: transparent; }
        .rm-dash #lguReportsPanel .rm-panel-header { border-bottom: 1px solid rgba(30, 60, 114, 0.12); }
        .rm-dash #citizenReportsPanel .rm-panel-header { border-bottom: 1px solid rgba(22, 163, 74, 0.14); }
        .rm-dash #cimmReportsPanel .rm-panel-header { border-bottom: 1px solid rgba(79, 69, 104, 0.14); }
        .rm-dash #infraReportsPanel .rm-panel-header { border-bottom: 1px solid rgba(249, 115, 22, 0.16); }

        .rm-dash .rm-panel-title { font-size: 18px; font-weight: 700; letter-spacing: -0.01em; }
        .rm-dash #lguReportsPanel .rm-panel-title { color: #1e3c72; }
        .rm-dash #citizenReportsPanel .rm-panel-title { color: #15803d; }
        .rm-dash #cimmReportsPanel .rm-panel-title { color: #3f3658; }
        .rm-dash #infraReportsPanel .rm-panel-title { color: #c2410c; }
        .rm-dash #lguReportsPanel .rm-panel-subtitle { color: #4a5b82; }
        .rm-dash #citizenReportsPanel .rm-panel-subtitle { color: #166534; }
        .rm-dash #cimmReportsPanel .rm-panel-subtitle { color: #6b6380; }
        .rm-dash #infraReportsPanel .rm-panel-subtitle { color: #92400e; }

        .rm-dash .rm-panel-icon { width: 40px; height: 40px; border-radius: 10px; color: #fff !important; }
        .rm-dash .rm-panel-icon.lgu { background: linear-gradient(135deg, #1e3c72, #0f274a) !important; }
        .rm-dash .rm-panel-icon.citizen { background: linear-gradient(135deg, #16a34a, #15803d) !important; }
        .rm-dash .rm-panel-icon.cimm { background: linear-gradient(135deg, #5a4e78, #3f3658) !important; }
        .rm-dash .rm-panel-icon.infra { background: linear-gradient(135deg, #f97316, #ea580c) !important; }

        .rm-dash .rm-panel-badge.lgu { background: #3762c8 !important; color: #fff !important; }
        .rm-dash .rm-panel-badge.citizen { background: #16a34a !important; color: #fff !important; }
        .rm-dash .rm-panel-badge.cimm { background: #5a4e78 !important; color: #fff !important; }
        .rm-dash .rm-panel-badge.infra { background: #f97316 !important; color: #fff !important; }

        .rm-dash .rm-panel-search { padding: 12px 20px; gap: 10px; align-items: center; }
        .rm-dash .rm-search-input {
            background: var(--bg-input);
            color: var(--text-primary);
            border: 1px solid var(--border-input);
            border-radius: 8px;
        }
        .rm-dash #lguReportsPanel .rm-search-input:focus { border-color: #1e3c72; box-shadow: 0 0 0 3px rgba(30, 60, 114, 0.12); }
        .rm-dash #citizenReportsPanel .rm-search-input:focus { border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.12); }
        .rm-dash #cimmReportsPanel .rm-search-input:focus { border-color: #5a4e78; box-shadow: 0 0 0 3px rgba(90, 78, 120, 0.14); }
        .rm-dash #infraReportsPanel .rm-search-input:focus { border-color: #f97316; box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.12); }

        .rm-dash .rm-sort-btn {
            border: none; border-radius: 8px; font-weight: 600;
            color: #fff !important; transform: none; flex-shrink: 0;
        }
        .rm-dash .rm-sort-btn:hover { transform: none; }
        .rm-dash #lguReportsPanel .rm-sort-btn { background: linear-gradient(135deg, #1e3c72, #0f274a) !important; }
        .rm-dash #citizenReportsPanel .rm-sort-btn { background: linear-gradient(135deg, #16a34a, #15803d) !important; }
        .rm-dash #cimmReportsPanel .rm-sort-btn { background: linear-gradient(135deg, #5a4e78, #3f3658) !important; }
        .rm-dash #infraReportsPanel .rm-sort-btn { background: linear-gradient(135deg, #f97316, #ea580c) !important; }

        .rm-dash .rm-table-wrapper { overflow-x: auto; max-width: 100%; -webkit-overflow-scrolling: touch; }
        .rm-dash .rm-table { min-width: 720px; }
        .rm-dash .rm-table thead th { font-size: 11px; letter-spacing: 0.4px; padding: 12px 16px; }
        .rm-dash #lguReportsPanel .rm-table thead th { background: linear-gradient(135deg, #1e3c72, #0f274a) !important; color: #fff !important; }
        .rm-dash #citizenReportsPanel .rm-table thead th { background: linear-gradient(135deg, #16a34a, #15803d) !important; color: #fff !important; }
        .rm-dash #cimmReportsPanel .rm-table thead th { background: linear-gradient(135deg, #5a4e78, #3f3658) !important; color: #fff !important; }
        .rm-dash #infraReportsPanel .rm-table thead th { background: linear-gradient(135deg, #f97316, #ea580c) !important; color: #fff !important; }
        .rm-dash .rm-table tbody td {
            color: var(--text-primary); padding: 12px 16px; font-size: 13px; vertical-align: middle;
        }
        .rm-dash .rm-table td:first-child { white-space: nowrap; }
        .rm-dash .rm-table td:nth-child(2) {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 12px; color: var(--text-secondary);
        }
        .rm-dash .rm-table td:nth-child(3) { white-space: normal; max-width: 240px; }
        .rm-dash .rm-action-group { flex-wrap: nowrap; gap: 6px; }

        .rm-dash .rm-action-btn {
            background: var(--color-primary-bg); color: var(--color-primary);
            border-radius: 8px; padding: 6px 12px; font-size: 12px;
        }
        .rm-dash .rm-action-btn:hover { background: var(--color-primary); color: #fff; }
        .rm-dash .rm-edit-btn {
            background: var(--color-warning-bg); color: var(--color-warning-text);
            border-radius: 8px; padding: 6px 10px; font-size: 12px;
        }
        .rm-dash .rm-edit-btn:hover { background: var(--color-warning); color: #fff; }
        .rm-dash .rm-archive-btn {
            background: var(--color-success-bg); color: var(--color-success-text);
            border-radius: 8px; padding: 6px 10px; font-size: 12px;
        }
        .rm-dash .rm-archive-btn:hover { background: var(--color-success); color: #fff; }
        .rm-dash .rm-delete-btn {
            background: var(--color-danger-bg); color: var(--color-danger-text);
            border-radius: 8px; padding: 6px 10px; font-size: 12px;
        }
        .rm-dash .rm-delete-btn:hover { background: var(--color-danger); color: #fff; }

        .rm-dash .rm-status-badge,
        .rm-dash .rm-priority-badge,
        .rm-dash .assignment-badge {
            border-radius: 999px; padding: 4px 10px; font-size: 11px; font-weight: 600; border: none;
        }
        .rm-dash .rm-status-badge.pending,
        .rm-dash .rm-priority-badge.medium { background: var(--badge-pending-bg) !important; color: var(--badge-pending-text) !important; }
        .rm-dash .rm-status-badge.in-progress { background: var(--badge-in-progress-bg) !important; color: var(--badge-in-progress-text) !important; }
        .rm-dash .rm-status-badge.approved,
        .rm-dash .rm-status-badge.completed,
        .rm-dash .rm-status-badge.resolved,
        .rm-dash .rm-priority-badge.low { background: var(--badge-approved-bg) !important; color: var(--badge-approved-text) !important; }
        .rm-dash .rm-status-badge.cancelled,
        .rm-dash .rm-priority-badge.high { background: var(--badge-cancelled-bg) !important; color: var(--badge-cancelled-text) !important; }

        .rm-dash .rm-empty-state { padding: 40px 16px; color: var(--text-secondary); }
        .rm-dash .rm-empty-state h4 { color: var(--text-primary); font-size: 15px; }
        .rm-dash #lguReportsPanel .rm-empty-icon { background: rgba(30, 60, 114, 0.10) !important; }
        .rm-dash #lguReportsPanel .rm-empty-icon i { color: #1e3c72 !important; }
        .rm-dash #citizenReportsPanel .rm-empty-icon { background: rgba(22, 163, 74, 0.10) !important; }
        .rm-dash #citizenReportsPanel .rm-empty-icon i { color: #16a34a !important; }
        .rm-dash #cimmReportsPanel .rm-empty-icon { background: rgba(90, 78, 120, 0.10) !important; }
        .rm-dash #cimmReportsPanel .rm-empty-icon i { color: #5a4e78 !important; }
        .rm-dash #infraReportsPanel .rm-empty-icon { background: rgba(249, 115, 22, 0.10) !important; }
        .rm-dash #infraReportsPanel .rm-empty-icon i { color: #f97316 !important; }

        .rm-dash .rm-panel-pagination { padding: 12px 16px; }
        .rm-dash #lguReportsPanel .rm-page-btn { background: linear-gradient(135deg, #1e3c72, #0f274a); }
        .rm-dash #citizenReportsPanel .rm-page-btn { background: linear-gradient(135deg, #16a34a, #15803d); }
        .rm-dash #cimmReportsPanel .rm-page-btn { background: linear-gradient(135deg, #5a4e78, #3f3658); }
        .rm-dash #infraReportsPanel .rm-page-btn { background: linear-gradient(135deg, #f97316, #ea580c); }

        .rm-modal-content {
            background: var(--bg-card) !important;
            color: var(--text-primary);
            border: 1px solid var(--border-default) !important;
            max-width: min(860px, 94vw);
            max-height: 86vh;
            box-shadow: var(--shadow-lg);
        }
        .rm-modal-header {
            background: var(--bg-card) !important;
            padding: 16px 20px 14px !important;
            border-bottom: 1px solid var(--border-light) !important;
        }
        .rm-modal-title { color: var(--text-primary) !important; font-size: 18px !important; }
        .rm-modal-report-id { color: var(--text-secondary) !important; }
        .rm-modal-body { padding: 14px 20px !important; }
        .rm-modal-section {
            background: var(--bg-hover) !important;
            border: 1px solid var(--border-light) !important;
            box-shadow: none; border-radius: 10px;
        }
        .rm-modal-section-title { color: var(--text-primary) !important; }
        .rm-view-map-btn {
            background: var(--color-primary-bg) !important;
            color: var(--color-primary) !important;
            border-color: transparent !important;
        }
        .rm-view-map-btn:hover { background: var(--color-primary) !important; color: #fff !important; }
        .modal-content {
            background: var(--bg-card);
            color: var(--text-primary);
            max-width: min(600px, 94vw);
            max-height: 90vh;
            overflow: auto;
        }
        .modal-header { background: var(--bg-card); border-bottom: 1px solid var(--border-light); }
        .modal-title { color: var(--text-primary); }
        .modal-footer { background: var(--bg-hover); border-top: 1px solid var(--border-light); }
        .form-section { background: var(--bg-hover); border: 1px solid var(--border-light); }
        .form-section h6 { color: var(--text-primary); }
        .delete-confirm-box { max-width: min(420px, 94vw); }

        body.dark-mode { background: var(--bg-page); }
        body.dark-mode .rm-dash .dashboard-header,
        body.dark-mode .rm-dash .filters-section,
        body.dark-mode .rm-dash .rm-panel,
        body.dark-mode .rm-dash .stat-card {
            background: #1c2432 !important;
        }
        body.dark-mode .rm-dash .dashboard-header,
        body.dark-mode .rm-dash .filters-section,
        body.dark-mode .rm-dash .stat-card {
            border-color: rgba(147, 179, 224, 0.22) !important;
        }
        body.dark-mode .rm-dash #lguReportsPanel.rm-panel {
            border-color: rgba(147, 179, 224, 0.28) !important;
            border-left-color: #93b3e0 !important;
        }
        body.dark-mode .rm-dash #citizenReportsPanel.rm-panel {
            border-color: rgba(74, 222, 128, 0.28) !important;
            border-left-color: #4ade80 !important;
        }
        body.dark-mode .rm-dash #cimmReportsPanel.rm-panel {
            border-color: rgba(167, 154, 196, 0.30) !important;
            border-left-color: #a79ac4 !important;
        }
        body.dark-mode .rm-dash #infraReportsPanel.rm-panel {
            border-color: rgba(251, 146, 60, 0.30) !important;
            border-left-color: #fb923c !important;
        }
        body.dark-mode .rm-dash .welcome-text h1,
        body.dark-mode .rm-dash .stat-number,
        body.dark-mode .rm-dash .chart-title { color: var(--text-primary) !important; }
        body.dark-mode .rm-dash .welcome-text p,
        body.dark-mode .rm-dash .stat-label { color: var(--text-secondary) !important; }
        body.dark-mode .rm-dash #lguReportsPanel .rm-panel-title { color: #93b3e0 !important; }
        body.dark-mode .rm-dash #citizenReportsPanel .rm-panel-title { color: #86efac !important; }
        body.dark-mode .rm-dash #cimmReportsPanel .rm-panel-title { color: #c5bdd8 !important; }
        body.dark-mode .rm-dash #infraReportsPanel .rm-panel-title { color: #fdba74 !important; }
        body.dark-mode .rm-dash #lguReportsPanel .rm-panel-subtitle { color: #8aa3c8 !important; }
        body.dark-mode .rm-dash #citizenReportsPanel .rm-panel-subtitle { color: #6ee7b7 !important; }
        body.dark-mode .rm-dash #cimmReportsPanel .rm-panel-subtitle { color: #a39bb8 !important; }
        body.dark-mode .rm-dash #infraReportsPanel .rm-panel-subtitle { color: #fdba74 !important; }
        body.dark-mode .rm-dash .rm-table tbody td { color: var(--text-primary) !important; }
        body.dark-mode .rm-dash .rm-action-btn {
            background: var(--color-primary-bg) !important;
            color: var(--color-primary) !important;
        }
        body.dark-mode .rm-dash .filter-select {
            background: var(--bg-input) !important;
            color: var(--text-primary) !important;
            border-color: var(--border-input) !important;
        }
        body.dark-mode .rm-modal-content,
        body.dark-mode .rm-modal-header,
        body.dark-mode .modal-content { background: var(--bg-card) !important; }
        body.dark-mode .rm-modal-title,
        body.dark-mode .modal-title { color: var(--text-primary) !important; }
        body.dark-mode .rm-modal-report-id { color: var(--text-secondary) !important; }

        @media (max-width: 1200px) {
            .rm-dash .quick-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (max-width: 768px) {
            .main-content.rm-dash { margin-left: 0; padding: 16px; }
            .rm-dash .welcome-text h1 { font-size: 20px; flex-wrap: wrap; }
            .rm-dash .quick-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
            .rm-dash .stat-card { padding: 14px; }
            .rm-dash .filter-group,
            .rm-dash .filter-group > div { width: 100%; min-width: 0; }
            .rm-dash .btn-wrapper { width: 100%; }
            .rm-dash .btn-wrapper button { flex: 1 1 auto; justify-content: center; }
            .rm-dash .rm-panel-header { flex-direction: column; align-items: flex-start; }
            .rm-dash .rm-panel-header-left { width: 100%; }
            .rm-dash .rm-panel-title-group { flex-wrap: wrap; }
            .rm-dash .rm-panel-search { flex-direction: column; align-items: stretch; }
            .rm-dash .rm-sort-btn { width: 100%; justify-content: center; }
            .rm-dash .rm-panel-pagination { flex-direction: column; align-items: flex-start; }
            .rm-modal-overlay { padding: 8px; align-items: flex-start; }
            .rm-modal-content { max-width: 96vw; max-height: 96vh; }
            .modal-content { width: 96%; max-width: 96vw; margin: 8px auto; max-height: 92vh; }
            .delete-confirm-box { width: 94vw; }
            .modal-body [style*="display: flex"] { flex-wrap: wrap; }
        }
        @media (max-width: 480px) {
            .rm-dash .quick-stats { grid-template-columns: 1fr; }
            .rm-dash .stat-number { font-size: 20px; }
            .rm-dash .header-icon { width: 36px; height: 36px; }
        }

        /* Update Report / Assign To — dark mode polish */
        body.dark-mode #editReportModal .urm-body,
        body.dark-mode #assignUserModal .urm-body { background: #1a1d23 !important; }
        body.dark-mode #editReportModal .urm-section {
            background: #22262e !important;
            border-color: #2d323b !important;
            box-shadow: none !important;
        }
        body.dark-mode #editReportModal .urm-assign-section {
            background: linear-gradient(180deg, #1e293b 0%, #22262e 45%) !important;
            border-color: #334155 !important;
        }
        body.dark-mode #editReportModal .urm-section-hint,
        body.dark-mode #assignUserModal .urm-section-hint { color: #94a3b8 !important; }
        body.dark-mode #editReportModal .urm-btn-assign {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #93c5fd !important;
        }
        body.dark-mode #editReportModal .asg-name { color: #e2e8f0 !important; }
        body.dark-mode #editReportModal .asg-date { color: #94a3b8 !important; }
        body.dark-mode #editReportModal .asg-role {
            background: rgba(147, 197, 253, 0.12) !important;
            color: #93c5fd !important;
        }
        body.dark-mode #editReportModal .asg-remove-btn {
            background: rgba(127, 29, 29, 0.35) !important;
            color: #fca5a5 !important;
        }
        body.dark-mode #editReportModal .urm-footer,
        body.dark-mode #assignUserModal .urm-footer {
            background: #1e2229 !important;
            border-top-color: #2d323b !important;
        }
        body.dark-mode #assignUserModal .asm-selected-bar {
            background: #1e293b !important;
            border-color: #334155 !important;
        }
        body.dark-mode #assignUserModal .asm-selected-meta strong { color: #e2e8f0 !important; }
        body.dark-mode #assignUserModal .asm-selected-role { color: #93c5fd !important; }
        body.dark-mode #assignUserModal .asm-staff-list {
            background: #1a1d23 !important;
            border-color: #3a3f4a !important;
        }
        body.dark-mode #assignUserModal .usr-name { color: #e2e8f0 !important; }
        body.dark-mode #assignUserModal .usr-email,
        body.dark-mode #assignUserModal .usr-active { color: #94a3b8 !important; }
        body.dark-mode #assignUserModal .usr-role {
            background: rgba(147, 197, 253, 0.12) !important;
            color: #93c5fd !important;
        }
        body.dark-mode #editReportModal .urm-section-readonly .form-control.urm-readonly,
        body.dark-mode #editReportModal .urm-section-readonly textarea.urm-readonly {
            background: #1a1d23 !important;
            color: #94a3b8 !important;
            border-color: #3a3f4a !important;
        }
    </style>
    <?php if (!empty($is_transport_supervisor)): ?>
    <!-- Transport Operations Supervisor only: keep all six quick-stats cards
         in ONE row on phones so they fit on screen instead of collapsing to
         2x3 / a tall single-column stack. Compact tiles (smaller icon/type,
         tighter padding) make the 6-column grid work at phone widths.
         UI-only CSS scoping — other portals are unaffected and no behaviour
         changes. -->
    <style>
        @media (max-width: 768px) {
            .trans-supervisor-view .rm-dash .quick-stats {
                grid-template-columns: repeat(6, minmax(0, 1fr));
                gap: 6px;
                margin-bottom: 12px;
            }
            .trans-supervisor-view .rm-dash .stat-card {
                padding: 8px 5px;
                border-radius: 10px;
                min-width: 0;
                max-width: 100%;
                box-sizing: border-box;
            }
            .trans-supervisor-view .rm-dash .stat-card::before { height: 2px; }
            .trans-supervisor-view .rm-dash .stat-icon {
                width: 20px;
                height: 20px;
                border-radius: 6px;
                font-size: 9px;
                margin-bottom: 4px;
            }
            .trans-supervisor-view .rm-dash .stat-number { font-size: 13px; }
            .trans-supervisor-view .rm-dash .stat-label {
                font-size: 6.8px;
                letter-spacing: 0;
                line-height: 1.25;
                overflow-wrap: anywhere;
                word-break: break-word;
            }
        }
    </style>
    <?php endif; ?>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?><?php echo !empty($is_transport_supervisor) ? ' trans-supervisor-view' : ''; ?>">
    <!-- SIDEBAR -->
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="main-content rm-dash">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="welcome-section">
                <div class="welcome-text">
                    <h1><span class="header-icon"><i class="fas fa-clipboard-list"></i></span> Report Management</h1>
                    <p>Receive, update, and monitor road reports all in one place</p>
                </div>
                <div class="dt-chip">
                    <i class="fas fa-calendar-day"></i>
                    <div>
                        <div id="currentDate"></div>
                        <div id="currentTime"></div>
                    </div>
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
                        <?php if (!$is_transport_supervisor): ?>
                        <option value="maintenance" <?php echo $source_filter === 'maintenance' ? 'selected' : ''; ?>>Infrastructure Projects</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">&nbsp;</label>
                    <div class="btn-wrapper">
                        <button type="button"
                            class="btn-your-reports"
                            id="yourReportsBtn"
                            onclick="toggleYourReports()"
                            title="<?php echo !empty($your_reports_only) ? 'Show all reports' : 'Show only reports you are handling'; ?>">
                            <?php if (!empty($your_reports_only)): ?>
                            <i class="fas fa-list"></i> All Reports
                            <?php else: ?>
                            <i class="fas fa-user-check"></i> Your Reports
                            <?php endif; ?>
                        </button>
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
                            <span class="rm-panel-badge lgu" id="lguReportsBadge"><?php echo (int)$lgu_reports_total; ?> Reports</span>
                        </div>
                        <p class="rm-panel-subtitle">Reports created by LGU staff via the road monitoring system</p>
                    </div>
                </div>
            </div>

            <div class="rm-panel-search">
                <div class="rm-search-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" class="rm-search-input" id="lguSearchInput" placeholder="Search by Report #..." value="<?php echo htmlspecialchars($lgu_search); ?>" oninput="onPanelServerSearch('lgu')">
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
                            <?php if ($is_system_admin): ?>
                            <th>Category</th>
                            <?php endif; ?>
                            <th>Location</th>
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
                        <?php echo rm_render_lgu_panel_tbody($lgu_reports_list, $is_road_supervisor, $is_transport_supervisor, $user_role); ?>
                    </tbody>
                </table>
            </div>
            <div id="lguPagination" class="rm-panel-pagination-slot">
                <?php echo $lgu_pagination_html; ?>
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
                            <span class="rm-panel-badge citizen" id="citizenReportsBadge"><?php echo (int)$citizen_reports_total; ?> Reports</span>
                        </div>
                        <p class="rm-panel-subtitle">Reports submitted by citizens via the road monitoring system</p>
                    </div>
                </div>
            </div>

            <div class="rm-panel-search">
                <div class="rm-search-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" class="rm-search-input" id="citizenSearchInput" placeholder="Search by Report #..." value="<?php echo htmlspecialchars($citizen_search); ?>" oninput="onPanelServerSearch('citizen')">
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
                            <th>Type</th>
                            <th>Location</th>
                            <th>Priority</th>
                            <?php if ($is_transport_supervisor): ?>
                            <th>Assignment</th>
                            <?php endif; ?>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php echo rm_render_citizen_panel_tbody($citizen_reports, $is_road_supervisor, $is_transport_supervisor, $user_role); ?>
                    </tbody>
                </table>
            </div>
            <div id="citizenPagination" class="rm-panel-pagination-slot">
                <?php echo $citizen_pagination_html; ?>
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
                            <span class="rm-panel-badge cimm" id="cimmReportsBadge"><?php echo (int)$cimm_reports_total; ?> Reports</span>
                        </div>
                        <p class="rm-panel-subtitle">External reports from the CIMM system — managed via Verification Monitoring</p>
                    </div>
                </div>
            </div>

            <div class="rm-panel-search">
                <div class="rm-search-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" class="rm-search-input" id="cimmSearchInput" placeholder="Search by Rep #..." value="<?php echo htmlspecialchars($cimm_search); ?>" oninput="onPanelServerSearch('cimm')">
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
                        <?php echo rm_render_cimm_panel_tbody($cimm_reports_list); ?>
                    </tbody>
                </table>
            </div>
            <div id="cimmPagination" class="rm-panel-pagination-slot">
                <?php echo $cimm_pagination_html; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Infrastructure Projects Panel (visible to Road Operations Supervisors) -->
        <?php if (!$is_transport_supervisor): ?>
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
                            <th>Engineer</th>
                            <th>Budget</th>
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
                        <tr data-id="<?php echo (int)$report['id']; ?>" data-source="maintenance" data-ipms="1">
                            <td>
                                <div class="rm-action-group">
                                    <button class="rm-action-btn" onclick="viewIpmsInfraProject(<?php echo (int)$report['id']; ?>)" title="View">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php
                                    $infra_can_manage = ($user_role === 'system_admin')
                                        || (!$is_road_supervisor && !$is_transport_supervisor)
                                        || !empty($report['can_manage_as_supervisor']);
                                    ?>
                                    <?php if (!empty($report['from_ipms']) && $infra_can_manage): ?>
                                    <button type="button" class="rm-edit-btn" onclick="editIpmsProject(<?php echo (int)$report['id']; ?>)" title="Edit">
                                        <i class="fas fa-pencil"></i>
                                    </button>
                                    <button class="rm-delete-btn" onclick="deleteIpmsProject(<?php echo (int)$report['id']; ?>)" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
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
                                $rtype = $report['report_type'] ?? '';
                                echo htmlspecialchars($type_labels[$rtype] ?? (ucfirst(str_replace(['_', '-'], ' ', (string)$rtype)) ?: 'Infrastructure Project'));
                            ?></td>
                            <td><?php echo htmlspecialchars($report['location'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars(ucfirst($report['department'] ?? 'Engineering')); ?></td>
                            <td><?php echo htmlspecialchars(trim((string)($report['engineer'] ?? '')) ?: '—'); ?></td>
                            <td><?php echo (!empty($report['budget']) && (float)$report['budget'] > 0) ? '₱' . number_format((float)$report['budget'], 2) : '—'; ?></td>
                            <td><span class="rm-priority-badge"><?php echo htmlspecialchars($report['priority'] ?? '—'); ?></span></td>
                            <td><span class="rm-status-badge <?php echo htmlspecialchars(strtolower($report['status'] ?? 'approved')); ?>"><?php echo ucfirst(htmlspecialchars(str_replace(['-', '_'], ' ', (string)($report['status'] ?? 'approved')))); ?></span></td>
                            <td>
                                <?php echo !empty($report['created_at']) ? date('M d, Y', strtotime($report['created_at'])) : '—'; ?>
                            </td>
                        </tr>
                        <?php
                            endforeach;
                        endif;
                        ?>

                        <?php if (!$hasInfra): ?>
                        <tr>
                            <td colspan="11">
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
    <div id="editReportModal" class="modal urm-modal">
        <div class="modal-content urm-content">
            <div class="modal-header urm-header">
                <div class="urm-header-text">
                    <p class="urm-kicker">Report Management</p>
                    <h5 class="modal-title"><i class="fas fa-pen-to-square"></i> Update Report</h5>
                </div>
                <button type="button" class="close urm-close" onclick="closeModal('editReportModal')" aria-label="Close">&times;</button>
            </div>
            <form method="POST" id="editReportForm" enctype="multipart/form-data">
                <div class="modal-body urm-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="update_report">
                    <input type="hidden" name="report_id" id="editReportId">
                    <input type="hidden" name="report_type" id="editReportType">
                    <input type="hidden" name="report_type_from_db" id="editReportTypeFromDB">
                    <input type="hidden" name="report_table" id="editReportTable">

                    <section class="form-section urm-section urm-section-readonly">
                        <h6><i class="fas fa-info-circle"></i> Basic Information</h6>
                        <div class="form-group">
                            <label for="editTitle" class="form-label">Title</label>
                            <input type="text" class="form-control urm-readonly" name="title" id="editTitle" placeholder="Report title" readonly tabindex="-1">
                        </div>
                        <div class="form-group">
                            <label for="editDescription" class="form-label">Description</label>
                            <textarea class="form-control urm-readonly" name="description" id="editDescription" rows="3" placeholder="Report description" readonly tabindex="-1"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="editLocation" class="form-label">Location</label>
                            <input type="text" class="form-control urm-readonly" name="location" id="editLocation" placeholder="Report location" readonly tabindex="-1">
                        </div>
                    </section>

                    <section class="form-section urm-section">
                        <h6><i class="fas fa-sliders"></i> Status &amp; Priority</h6>
                        <div class="urm-field-row">
                            <div class="form-group">
                                <label for="editStatus" class="form-label">Status *</label>
                                <div class="urm-select-wrap">
                                    <select class="form-control urm-select" name="status" id="editStatus" required>
                                        <option value="approved">Approved</option>
                                        <option value="in-progress">In Progress</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="editPriority" class="form-label">Priority *</label>
                                <div class="urm-select-wrap">
                                    <select class="form-control urm-select" name="priority" id="editPriority" required>
                                        <option value="low">Low</option>
                                        <option value="medium">Medium</option>
                                        <option value="high">High</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="form-section urm-section urm-assign-section">
                        <div class="urm-section-head">
                            <h6><i class="fas fa-user-check"></i> Assign To</h6>
                            <p class="urm-section-hint">Assign monitoring staff by name and role. Current assignees appear below.</p>
                        </div>
                        <?php if ($user_role !== 'system_admin'): ?>
                        <div class="urm-assign-actions">
                            <button type="button" class="urm-btn-assign" onclick="openAssignUserModal()">
                                <i class="fas fa-user-plus"></i>
                                <span>Select Staff</span>
                            </button>
                        </div>
                        <?php endif; ?>
                        <div class="urm-assigned-block">
                            <label class="form-label">Assigned Staff</label>
                            <div id="assignedUsersListRegular" class="urm-assigned-list">
                                <div class="asg-muted urm-loading">Loading assigned staff...</div>
                            </div>
                        </div>
                    </section>

                    <section class="form-section urm-section">
                        <h6><i class="fas fa-images"></i> Report Photos</h6>
                        <div id="existingPhotos" class="urm-photo-grid"></div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="editPhotos" class="form-label">Add New Photos</label>
                            <button type="button" id="add-edit-photos-btn" class="urm-btn-photo"><i class="fas fa-camera"></i> Add Photos</button>
                            <input type="file" name="report_photos[]" id="editPhotos"
                                   accept="image/jpeg,image/png,image/gif,image/webp" multiple
                                   style="display:none;">
                            <small class="t-text-secondary urm-help">Accepted: JPG, PNG, GIF, WebP | Max: 5MB each</small>
                            <div id="photoPreview" class="urm-photo-grid urm-photo-preview"></div>
                        </div>
                    </section>

                    <section class="form-section urm-section">
                        <h6><i class="fas fa-sticky-note"></i> Progress Notes</h6>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="editNotes" class="form-label">Update Notes / Resolution Details</label>
                            <textarea class="form-control" name="notes" id="editNotes" rows="4"
                                      placeholder="Describe the current status, actions taken, or resolution details..."></textarea>
                            <small class="t-text-secondary urm-help">
                                <i class="fas fa-info-circle"></i> These notes will be visible to other staff members
                            </small>
                        </div>
                    </section>
                </div>
                <div class="modal-footer urm-footer">
                    <span id="updateStatusIndicator" class="t-text-secondary urm-status-indicator"></span>
                    <div class="urm-footer-actions">
                        <button type="button" class="btn-secondary-custom urm-btn-cancel" onclick="closeModal('editReportModal')">Cancel</button>
                        <button type="submit" class="btn-primary-custom urm-btn-save" id="updateSubmitBtn">
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
                                    <?php if (!$is_road_supervisor && !$is_system_admin): ?>
                                    <option value="pending">Pending</option>
                                    <?php endif; ?>
                                    <option value="approved">Approved</option>
                                    <option value="in-progress">In Progress</option>
                                    <?php if (!$is_road_supervisor && !$is_system_admin): ?>
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
                        <?php if ($user_role !== 'system_admin'): ?>
                        <div style="margin-top: 15px;">
                            <button type="button" class="btn-action" onclick="openAssignUserModal()">
                                <i class="fas fa-user-plus"></i> Assign Staff to Project
                            </button>
                        </div>
                        <?php endif; ?>
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

    <!-- Infrastructure (IPMS) Edit Project Modal -->
    <div id="editIpmsModal" class="modal">
        <div class="modal-content" style="max-width: 650px;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Infrastructure Project</h5>
                <button type="button" class="close" onclick="closeModal('editIpmsModal')">&times;</button>
            </div>
            <form method="POST" id="editIpmsForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="update_ipms_project">
                    <input type="hidden" name="report_id" id="editIpmsReportId">
                    <input type="hidden" name="report_table" id="editIpmsReportTable">

                    <div class="form-section">
                        <h6><i class="fas fa-info-circle"></i> Project Details</h6>
                        <div class="form-group">
                            <label class="form-label">Report #</label>
                            <input type="text" class="form-control t-bg-input-readonly" id="editIpmsRepNumber" readonly>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Title</label>
                            <input type="text" class="form-control t-bg-input-readonly" id="editIpmsTitle" readonly>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Description</label>
                            <textarea class="form-control t-bg-input-readonly" id="editIpmsDescription" rows="3" readonly></textarea>
                        </div>
                        <div style="display: flex; gap: 15px;">
                            <div class="form-group" style="flex: 1;">
                                <label class="form-label">Start Date</label>
                                <input type="date" class="form-control t-bg-input-readonly" id="editIpmsStartDate" readonly>
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label class="form-label">End Date</label>
                                <input type="date" class="form-control t-bg-input-readonly" id="editIpmsEndDate" readonly>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Budget (₱)</label>
                            <input type="text" class="form-control t-bg-input-readonly" id="editIpmsBudget" readonly>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Start Address</label>
                            <input type="text" class="form-control t-bg-input-readonly" id="editIpmsStartAddress" readonly>
                        </div>
                        <div class="form-group">
                            <label class="form-label">End Address</label>
                            <input type="text" class="form-control t-bg-input-readonly" id="editIpmsEndAddress" readonly>
                        </div>
                    </div>

                    <div class="form-section">
                        <h6><i class="fas fa-tasks"></i> Editable Fields</h6>
                        <div style="display: flex; gap: 15px;">
                            <div class="form-group" style="flex: 1;">
                                <label class="form-label">Status *</label>
                                <select class="form-control" name="status" id="editIpmsStatus" required>
                                    <option value="approved">Approved</option>
                                    <option value="in-progress">In Progress</option>
                                </select>
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label class="form-label">Priority</label>
                                <select class="form-control t-bg-input-readonly" id="editIpmsPriority" disabled>
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                </select>
                            </div>
                        </div>
                        <?php if ($user_role !== 'system_admin'): ?>
                        <div style="margin-top: 15px;">
                            <button type="button" class="btn-action" onclick="openAssignUserModal()">
                                <i class="fas fa-user-plus"></i> Assign Staff to Project
                            </button>
                        </div>
                        <?php endif; ?>
                        <div style="margin-top: 15px;">
                            <label class="form-label">Assigned Staff</label>
                            <div id="assignedUsersListIpms" style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 8px;">
                                <div style="color: #6b7280; font-size: 13px;">Loading assigned staff...</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="justify-content: space-between;">
                    <span id="ipmsEditIndicator" class="t-text-secondary" style="font-size: 12px;"></span>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="btn-secondary-custom" onclick="closeModal('editIpmsModal')">Cancel</button>
                        <button type="submit" class="btn-primary-custom" id="ipmsEditSubmitBtn">
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
                    <div class="rm-modal-section-title"><i class="fas fa-map-marker-alt"></i> Location
                        <button type="button" id="rm-view-map-btn" class="rm-view-map-btn" style="display:none;" onclick="openRmMap()">
                            <i class="fas fa-map-marked-alt"></i> View Map
                        </button>
                    </div>
                    <div class="rm-info-grid" id="rm-location-grid"></div>
                    <div class="road-map-container" id="rm-map-container"></div>
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
                        <button type="button" class="btn-success-custom" id="completeBtn" onclick="completeReport()">Complete</button>
                        <button type="button" class="btn-danger-custom" id="cancelBtn" onclick="cancelReport()">Cancel</button>
                        <button type="button" class="btn-action" id="exportWordBtn" onclick="exportUpdatesToExcel()"><i class="fas fa-file-word"></i> Export as Word</button>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="btn-action" id="addUpdateBtn" onclick="showAddUpdateModal()">+ Add Update</button>
                        <button type="button" class="btn-secondary-custom" onclick="closeModal('updatesModal')">Close</button>
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
                    <input type="hidden" name="report_type" id="addUpdateReportType" value=""><input type="hidden" name="source" id="addUpdateSource" value="lgu">
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
    <div id="assignUserModal" class="modal urm-modal asm-modal">
        <div class="modal-content urm-content asm-content">
            <div class="modal-header urm-header">
                <div class="urm-header-text">
                    <p class="urm-kicker">Staff Assignment</p>
                    <h5 class="modal-title"><i class="fas fa-user-plus"></i> Assign To</h5>
                </div>
                <button type="button" class="close urm-close" onclick="closeModal('assignUserModal')" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body urm-body">
                <div class="asm-selected-bar" id="assignSelectedBar" hidden>
                    <div class="asm-selected-avatar"><i class="fas fa-user"></i></div>
                    <div class="asm-selected-meta">
                        <span class="asm-selected-label">Selected</span>
                        <strong id="assignSelectedName">—</strong>
                        <span id="assignSelectedRole" class="asm-selected-role"></span>
                    </div>
                    <button type="button" class="asm-clear-btn" onclick="clearAssignSelection()" title="Clear selection">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="form-group">
                    <label class="form-label">Available Staff</label>
                    <p class="urm-section-hint">Choose a person below. Name and role are shown for each option.</p>
                    <div id="availableUsersList" class="asm-staff-list">
                        <div class="usr-muted asm-list-loading"><i class="fas fa-spinner fa-spin"></i> Loading staff...</div>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="assignmentNotes">Notes <span class="urm-optional">(optional)</span></label>
                    <textarea class="form-control" id="assignmentNotes" rows="3" placeholder="Add notes about this assignment..."></textarea>
                </div>
            </div>
            <div class="modal-footer urm-footer">
                <div class="urm-footer-actions urm-footer-actions-end">
                    <button type="button" class="btn-secondary-custom urm-btn-cancel" onclick="closeModal('assignUserModal')">Cancel</button>
                    <button type="button" class="btn-primary-custom urm-btn-save" onclick="assignUserToProject()">
                        <i class="fas fa-check"></i> Assign Staff
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // CIMM data for detail viewing (read-only)
        let cimmData = <?php echo json_encode(array_values($cimm_reports_list), JSON_HEX_TAG | JSON_HEX_AMP); ?>;

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
            _pendingDeleteTable = null;
            _pendingDeleteCimmIdx = null;
            _pendingDeleteIpmsId = null;
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
                    '<input type="hidden" name="report_type" value="' + type + '">' +
                    (_pendingDeleteTable ? '<input type="hidden" name="report_table" value="' + _pendingDeleteTable + '">' : '');
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
            } else if (_pendingDeleteIpmsId !== null) {
                var id = _pendingDeleteIpmsId;
                cancelDeleteConfirm();
                if (!id) return;
                var form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML =
                    '<input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">' +
                    '<input type="hidden" name="action" value="delete_ipms_project">' +
                    '<input type="hidden" name="report_id" value="' + id + '">';
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
            url.searchParams.set('lgu_page', '1');
            url.searchParams.set('citizen_page', '1');
            url.searchParams.set('cimm_page', '1');
            if (url.searchParams.get('mine') === '1') {
                url.searchParams.set('mine', '1');
            }
            window.location.href = url.toString();
        }

        function toggleYourReports() {
            const url = new URL(window.location);
            if (url.searchParams.get('mine') === '1' || url.searchParams.get('mine') === null) {
                url.searchParams.set('mine', '0');
            } else {
                url.searchParams.set('mine', '1');
            }
            url.searchParams.set('page', '1');
            url.searchParams.set('lgu_page', '1');
            url.searchParams.set('citizen_page', '1');
            url.searchParams.set('cimm_page', '1');
            window.location.href = url.toString();
        }

        function resetFilters() {
            const url = new URL(window.location);
            url.searchParams.delete('status');
            url.searchParams.delete('source');
            url.searchParams.delete('mine');
            url.searchParams.set('page', '1');
            url.searchParams.set('lgu_page', '1');
            url.searchParams.set('citizen_page', '1');
            url.searchParams.set('cimm_page', '1');
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

        // Approved IPMS projects for the Infrastructure Projects panel (view-only).
        var infraIpmsDataMap = {};
        <?php foreach ($infra_reports_list as $ir): ?>
        infraIpmsDataMap[<?php echo (int)$ir['id']; ?>] = <?php echo json_encode([
            'id' => (int)$ir['id'],
            'report_id' => $ir['report_id'] ?? '',
            'title' => $ir['title'] ?? '',
            'report_type' => $ir['report_type'] ?? 'infrastructure_issue',
            'department' => $ir['department'] ?? 'Engineering',
            'priority' => $ir['priority'] ?? '—',
            'status' => $ir['status'] ?? 'approved',
            'location' => $ir['location'] ?? '',
            'start_address' => $ir['start_address'] ?? null,
            'end_address' => $ir['end_address'] ?? null,
            'description' => $ir['description'] ?? '',
            'created_at' => $ir['created_at'] ?? null,
            'updated_at' => $ir['updated_at'] ?? null,
            'due_date' => $ir['due_date'] ?? null,
            'engineer' => $ir['engineer'] ?? '',
            'budget' => $ir['budget'] ?? null,
            'start_date' => $ir['start_date'] ?? null,
            'end_date' => $ir['end_date'] ?? null,
            'polyline' => $ir['polyline'] ?? null,
            'assigned_by' => $ir['assigned_by'] ?? '',
            'assignment_officer' => $ir['assignment_officer'] ?? '',
            'can_manage_as_supervisor' => !empty($ir['can_manage_as_supervisor']),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        <?php endforeach; ?>

        function viewIpmsInfraProject(id) {
            var r = infraIpmsDataMap[id];
            if (!r) {
                alert('Project data not found.');
                return;
            }

            var statusStyles = {
                'pending':    {bg:'rgba(251,191,36,0.15)', color:'#f59e0b'},
                'approved':   {bg:'rgba(34,197,94,0.15)',  color:'#22c55e'},
                'completed':  {bg:'rgba(34,197,94,0.15)',  color:'#22c55e'},
                'rejected':   {bg:'rgba(220,53,69,0.15)',  color:'#ef4444'},
                'cancelled':  {bg:'rgba(220,53,69,0.15)',  color:'#ef4444'}
            };

            document.getElementById('rm-report-id').textContent = 'Project #' + (r.report_id || '—');
            document.getElementById('rm-title').textContent = r.title || '—';

            var st = (r.status || 'approved').toLowerCase();
            var ss = statusStyles[st] || {bg:'rgba(107,114,128,0.15)', color:'#6b7280'};
            var badgesHtml = rmBadge(r.status || '—', ss.bg, ss.color);
            badgesHtml += '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:rgba(249,115,22,0.12);color:#f97316;">Infrastructure Project</span>';
            document.getElementById('rm-badges').innerHTML = badgesHtml;

            var reportGrid = '';
            reportGrid += rmInfoItem('folder', 'Road Type', r.report_type || '—');
            reportGrid += rmInfoItem('calendar-alt', 'Created', formatDate(r.created_at));
            reportGrid += rmInfoItem('sync-alt', 'Last Synced', formatDate(r.updated_at));
            reportGrid += rmInfoItem('calendar-check', 'Start Date', formatDate(r.start_date));
            reportGrid += rmInfoItem('clock', 'End Date', formatDate(r.end_date || r.due_date));
            document.getElementById('rm-report-grid').innerHTML = reportGrid;

            var sourceGrid = '';
            sourceGrid += rmInfoItem('server', 'Source', 'IPMS');
            sourceGrid += rmInfoItem('building', 'Department', r.department || 'Engineering');
            sourceGrid += rmInfoItem('hard-hat', 'Engineers', r.engineer || '—');
            if (r.assignment_officer) {
                sourceGrid += rmInfoItem('user-cog', 'Assigned To', r.assignment_officer);
            }
            if (r.assigned_by) {
                sourceGrid += rmInfoItem('user-tie', 'Assigned By', r.assigned_by);
            }
            if (r.budget != null && r.budget !== '' && Number(r.budget) !== 0) {
                var budgetLabel = '₱' + Number(r.budget).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                sourceGrid += rmInfoItem('wallet', 'Budget', budgetLabel);
            }
            document.getElementById('rm-source-grid').innerHTML = sourceGrid;

            var locationGrid = '';
            locationGrid += rmInfoItem('map-marker-alt', 'Start Address', r.start_address || '—');
            locationGrid += rmInfoItem('map-marker', 'End Address', r.end_address || '—');
            document.getElementById('rm-location-grid').innerHTML = locationGrid;

            var descEl = document.getElementById('rm-description');
            if (descEl) descEl.textContent = r.description || '—';

            var timelineEl = document.getElementById('rm-timeline-grid');
            if (timelineEl) timelineEl.innerHTML = '';

            // Clear attachments / map sections that belong to other report types
            var attachEl = document.getElementById('rm-attachments');
            if (attachEl) attachEl.innerHTML = '';
            var mapBtn = document.getElementById('rm-view-map-btn');
            var mapContainer = document.getElementById('rm-map-container');
            currentRmPoint = null;
            if (Array.isArray(r.polyline) && r.polyline.length >= 2) {
                currentRmPoint = r.polyline.map(function(pt) { return [pt[0], pt[1]]; });
                if (mapBtn) {
                    mapBtn.style.display = '';
                    mapBtn.onclick = function() { openRoadPathMap('rm-map-container', currentRmPoint, true); };
                }
            } else if (mapBtn) {
                mapBtn.style.display = 'none';
            }
            if (mapContainer) mapContainer.classList.remove('road-map-visible');

            openViewReportModal();
        }

        function openLightbox(src) {
            var lb = document.getElementById('lightboxOverlay');
            var img = document.getElementById('lightboxImage');
            img.src = src;
            lb.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        const TOMTOM_API_KEY = '<?php echo TOMTOM_API_KEY; ?>';
        let currentRmPoint = null;
        let roadMapInstances = {};

        function openRoadPathMap(containerId, points, asLine) {
            var container = document.getElementById(containerId);
            if (!container || !points || points.length === 0) return;
            if (typeof L === 'undefined') {
                alert('Map library failed to load.');
                return;
            }

            // Make the container visible first so Leaflet measures the correct
            // size when the map is created (display:none containers report 0x0).
            container.classList.add('road-map-visible');

            var map = roadMapInstances[containerId];
            if (!map) {
                map = L.map(containerId, { zoomControl: true })
                    .setView([14.6760, 121.0437], 12);
                L.tileLayer('https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=' + TOMTOM_API_KEY, {
                    attribution: '© TomTom',
                    maxZoom: 18
                }).addTo(map);
                roadMapInstances[containerId] = map;
            }

            // Remove any path/marker drawn for a previously-viewed report.
            map.eachLayer(function(layer) {
                if (layer instanceof L.Polyline || layer instanceof L.CircleMarker || layer instanceof L.Marker) {
                    map.removeLayer(layer);
                }
            });

            if (asLine && points.length >= 2) {
                L.polyline(points, { color: '#f97316', weight: 5, opacity: 0.9 }).addTo(map);
                map.fitBounds(L.latLngBounds(points).pad(0.25));
            } else {
                var pt = points[0];
                L.circleMarker(pt, { radius: 8, color: '#f97316', fillColor: '#f97316', fillOpacity: 0.85, weight: 2 }).addTo(map);
                map.setView(pt, 14);
            }

            // The modal animates open, which can leave the map with a stale
            // size; force a refresh once the transition has finished.
            setTimeout(function() {
                if (map) map.invalidateSize();
            }, 250);
        }

        function openRmMap() {
            openRoadPathMap('rm-map-container', currentRmPoint, false);
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
                        var sourceLabels = {
                            'lgu': 'LGU Monitoring',
                            'citizen': 'Citizen',
                            'cimm': 'CIMM',
                            'infrastructure': 'Infrastructure Projects'
                        };
                        var sourceLabel = sourceLabels[r.source] || (r.report_source === 'local' ? 'LGU Monitoring' : 'Citizen');
                        sourceGrid += rmInfoItem('server', 'Source', sourceLabel);
                        sourceGrid += rmInfoItem('building', 'Department', r.department);
                        if (r.assignment_officer || r.assigned_to) {
                            sourceGrid += rmInfoItem('user-cog', 'Assigned To', r.assignment_officer || r.assigned_to);
                        }
                        if (r.assigned_by) {
                            sourceGrid += rmInfoItem('user-tie', 'Assigned By', r.assigned_by);
                        }
                        if (r.created_by_name) {
                            sourceGrid += rmInfoItem('user', 'Created By', r.created_by_name);
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

                        // View Map button: only shown when the report has a saved
                        // coordinate point (latitude / longitude).
                        currentRmPoint = (r.latitude && r.longitude && r.latitude != 0 && r.longitude != 0)
                            ? [[parseFloat(r.latitude), parseFloat(r.longitude)]]
                            : null;
                        var rmMapBtn = document.getElementById('rm-view-map-btn');
                        if (rmMapBtn) rmMapBtn.style.display = currentRmPoint ? '' : 'none';
                        var rmMapContainer = document.getElementById('rm-map-container');
                        if (rmMapContainer) rmMapContainer.classList.remove('road-map-visible');

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

        function formatStaffRoleLabel(role) {
            return String(role || '')
                .replace(/_/g, ' ')
                .replace(/\b\w/g, (c) => c.toUpperCase())
                .trim() || 'Staff';
        }

        function staffRoleVisual(role) {
            let roleIcon = 'fa-user';
            let roleColor = '#3762c8';
            if (role === 'road_monitoring_officer') {
                roleIcon = 'fa-road';
                roleColor = '#f59e0b';
            } else if (role === 'trans_monitoring_officer') {
                roleIcon = 'fa-bus';
                roleColor = '#10b981';
            }
            return { roleIcon, roleColor };
        }

        function updateAssignSelectedBar(name, roleLabel) {
            const bar = document.getElementById('assignSelectedBar');
            const nameEl = document.getElementById('assignSelectedName');
            const roleEl = document.getElementById('assignSelectedRole');
            if (!bar || !nameEl || !roleEl) return;
            if (!name) {
                bar.hidden = true;
                nameEl.textContent = '—';
                roleEl.textContent = '';
                return;
            }
            bar.hidden = false;
            nameEl.textContent = name;
            roleEl.textContent = roleLabel || '';
        }

        function clearAssignSelection() {
            selectedUserForAssignment = null;
            updateAssignSelectedBar('', '');
            const usersList = document.getElementById('availableUsersList');
            if (!usersList) return;
            Array.from(usersList.children).forEach(child => {
                child.classList.remove('usr-selected');
            });
        }

        function loadAssignedUsers() {
            // Read the report id/table from whichever modal is currently open.
            const ipmsModal = document.getElementById('editIpmsModal');
            const cimmModal = document.getElementById('editCimmModal');

            let reportId = '';
            let reportType = '';
            let container;

            if (ipmsModal && ipmsModal.style.display === 'block') {
                reportId = document.getElementById('editIpmsReportId').value;
                reportType = document.getElementById('editIpmsReportTable').value;
                container = document.getElementById('assignedUsersListIpms');
            } else if (cimmModal && cimmModal.style.display === 'block') {
                reportId = document.getElementById('editCimmReportId').value;
                reportType = document.getElementById('editCimmReportTable').value;
                container = document.getElementById('assignedUsersListCimm');
            } else {
                reportId = document.getElementById('editReportId').value;
                reportType = document.getElementById('editReportTable').value;
                container = document.getElementById('assignedUsersListRegular');
            }

            // System Admin may only view assigned staff — no Remove button.
            let role = '';
            let roleTag = document.getElementById('sessionTimeoutData');
            if (roleTag) role = roleTag.getAttribute('data-role') || '';
            const canRemoveStaff = (role !== 'system_admin');
            
            console.log('loadAssignedUsers: reportId=', reportId, 'reportType=', reportType, 'container:', container);
            
            if (!container) {
                console.error('assignedUsersList container not found!');
                return;
            }
            
            if (!reportId || !reportType) {
                container.innerHTML = '<div class="asg-muted" style="color: #6b7280; font-size: 13px;">No report selected</div>';
                return;
            }
            
            container.innerHTML = '<div class="asg-muted" style="color: #6b7280; font-size: 13px;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
            
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
                                <div class="asg-empty">
                                    <i class="fas fa-user-slash"></i>
                                    <span>No staff assigned yet</span>
                                </div>
                            `;
                        } else {
                            container.innerHTML = '';
                            try {
                                data.assignments.forEach(assignment => {
                                    const userDiv = document.createElement('div');
                                    userDiv.className = 'asg-card';
                                    const { roleIcon, roleColor } = staffRoleVisual(assignment.role);
                                    const roleLabel = formatStaffRoleLabel(assignment.role);
                                    const assignedDate = new Date(assignment.assigned_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

                                    userDiv.innerHTML = `
                                        <div class="asg-avatar" style="background:${roleColor}20;color:${roleColor};">
                                            <i class="fas ${roleIcon}"></i>
                                        </div>
                                        <div class="asg-meta">
                                            <div class="asg-name">${escapeHtml(assignment.full_name)}</div>
                                            <div class="asg-role"><i class="fas fa-id-badge"></i> ${escapeHtml(roleLabel)}</div>
                                            <div class="asg-date"><i class="fas fa-calendar-alt"></i> Assigned: ${assignedDate}</div>
                                        </div>
                                        ${canRemoveStaff ? `
                                        <button type="button" class="asg-remove-btn" onclick="removeAssignment(${assignment.id}, '${escapeHtml(assignment.full_name)}')">
                                            <i class="fas fa-user-minus"></i> Remove
                                        </button>
                                        ` : ''}
                                    `;
                                    container.appendChild(userDiv);
                                });
                            } catch (e) {
                                console.error('Error rendering assignments:', e);
                                container.innerHTML = `<div class="asg-error">Error rendering: ${escapeHtml(e.message)}</div>`;
                            }
                        }
                    } else {
                        container.innerHTML = `
                            <div class="asg-error" style="display: flex; align-items: center; gap: 8px; padding: 12px; background: #fef2f2; border-radius: 6px; border: 1px solid #fecaca;">
                                <i class="fas fa-exclamation-circle" style="color: #dc3545; font-size: 16px;"></i>
                                <span style="color: #dc3545; font-size: 13px;">${escapeHtml(data.message)}</span>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error loading assigned users:', error);
                    container.innerHTML = `
                        <div class="asg-error" style="display: flex; align-items: center; gap: 8px; padding: 12px; background: #fef2f2; border-radius: 6px; border: 1px solid #fecaca;">
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
            const ipmsModal = document.getElementById('editIpmsModal');
            const cimmModal = document.getElementById('editCimmModal');
            const regularModal = document.getElementById('editReportModal');
            
            if (ipmsModal && ipmsModal.style.display === 'block') {
                originalModalBeforeAssign = 'ipms';
            } else if (cimmModal && cimmModal.style.display === 'block') {
                originalModalBeforeAssign = 'cimm';
            } else if (regularModal && regularModal.style.display === 'block') {
                originalModalBeforeAssign = 'regular';
            } else {
                originalModalBeforeAssign = null;
            }
            
            let reportId, reportType;
            
            if (originalModalBeforeAssign === 'ipms') {
                // Infrastructure (IPMS) modal
                reportId = document.getElementById('editIpmsReportId').value;
                reportType = document.getElementById('editIpmsReportTable').value;
            } else if (originalModalBeforeAssign === 'cimm') {
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
            selectedUserForAssignment = null;
            updateAssignSelectedBar('', '');
            usersList.innerHTML = '<div class="usr-muted asm-list-loading"><i class="fas fa-spinner fa-spin"></i> Loading staff...</div>';
            
            fetch(`../api/get_assignable_users.php?report_id=${reportId}&report_type=${encodeURIComponent(reportType)}`)
                .then(r => r.json())
                .then(data => {
                    console.log('Report category debug:', data.report_category, 'Target role:', data.target_role);
                    if (data.success) {
                        if (data.users.length === 0) {
                            usersList.innerHTML = '<div class="usr-muted">No staff available for this project type</div>';
                        } else {
                            usersList.innerHTML = '';
                            data.users.forEach(user => {
                                const userDiv = document.createElement('div');
                                userDiv.className = 'usr-card' + (user.already_assigned ? ' usr-disabled' : '');
                                userDiv.dataset.userId = String(user.id);
                                userDiv.dataset.userName = user.full_name || '';
                                userDiv.dataset.userRole = user.role || '';
                                userDiv.onclick = function() {
                                    if (!user.already_assigned) {
                                        selectUserForAssignment(user.id, user.full_name, user.role, userDiv);
                                    }
                                };

                                const { roleIcon, roleColor } = staffRoleVisual(user.role);
                                const roleLabel = formatStaffRoleLabel(user.role);

                                userDiv.innerHTML = `
                                    <div class="usr-avatar" style="background:${roleColor}15;color:${roleColor};border-color:${roleColor}30;">
                                        <i class="fas ${roleIcon}"></i>
                                    </div>
                                    <div class="usr-meta">
                                        <div class="usr-name">${escapeHtml(user.full_name)}</div>
                                        <div class="usr-email"><i class="fas fa-envelope"></i> ${escapeHtml(user.email)}</div>
                                        <div class="usr-role"><i class="fas fa-id-badge"></i> ${escapeHtml(roleLabel)}</div>
                                    </div>
                                    <div class="usr-side">
                                        <div class="usr-active"><i class="fas fa-tasks"></i> Active: <strong>${user.active_assignments}</strong></div>
                                        ${user.already_assigned
                                            ? '<span class="usr-badge-assigned"><i class="fas fa-check-circle"></i> Assigned</span>'
                                            : '<span class="usr-badge-assign"><i class="fas fa-plus-circle"></i> Select</span>'}
                                    </div>
                                `;
                                usersList.appendChild(userDiv);
                            });
                        }
                    } else {
                        usersList.innerHTML = `<div class="usr-error">${escapeHtml(data.message)}</div>`;
                    }
                })
                .catch(error => {
                    console.error('Error loading users:', error);
                    usersList.innerHTML = '<div class="usr-error">Failed to load staff</div>';
                });
        }

        function selectUserForAssignment(userId, userName, userRole, cardEl) {
            // Check if already selected, if so deselect
            if (selectedUserForAssignment && selectedUserForAssignment.id === userId) {
                clearAssignSelection();
                return;
            }

            selectedUserForAssignment = { id: userId, name: userName, role: userRole || '' };
            updateAssignSelectedBar(userName, formatStaffRoleLabel(userRole));

            const usersList = document.getElementById('availableUsersList');
            Array.from(usersList.children).forEach(child => {
                child.classList.remove('usr-selected');
            });
            const target = cardEl || (typeof event !== 'undefined' && event && event.currentTarget ? event.currentTarget : null);
            if (target) target.classList.add('usr-selected');
        }

        function assignUserToProject() {
            if (!selectedUserForAssignment) {
                showNotification('Please select a staff member to assign', 'error');
                return;
            }
            
            // Check which modal is currently open
            const ipmsModal = document.getElementById('editIpmsModal');
            const cimmModal = document.getElementById('editCimmModal');
            const isIpmsModal = ipmsModal && ipmsModal.style.display === 'block';
            const isCimmModal = cimmModal && cimmModal.style.display === 'block';
            
            let reportId, reportType;
            
            if (isIpmsModal) {
                // Infrastructure (IPMS) modal
                reportId = document.getElementById('editIpmsReportId').value;
                reportType = document.getElementById('editIpmsReportTable').value;
            } else if (isCimmModal) {
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
                    updateAssignSelectedBar('', '');
                    
                    // Reopen the original modal
                    if (originalModalBeforeAssign === 'ipms') {
                        openModal('editIpmsModal');
                    } else if (originalModalBeforeAssign === 'cimm') {
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

        function isTerminalUpdatesStatus() {
            var s = String(currentUpdatesReportStatus || '').toLowerCase().replace(/_/g, ' ').trim();
            return s === 'completed' || s === 'cancelled' || s === 'canceled';
        }

        function applyUpdatesFooterMode() {
            var actionButtons = document.getElementById('actionButtons');
            var exportButtons = document.getElementById('exportButtons');
            var exportWordBtn = document.getElementById('exportWordBtn');
            var completeBtn = document.getElementById('completeBtn');
            var cancelBtn = document.getElementById('cancelBtn');
            var addUpdateBtn = document.getElementById('addUpdateBtn');
            if (actionButtons) actionButtons.style.display = 'flex';
            if (exportWordBtn) exportWordBtn.style.display = 'inline-flex';
            if (exportButtons) exportButtons.style.display = 'none';
            if (isTerminalUpdatesStatus()) {
                if (completeBtn) completeBtn.style.display = 'none';
                if (cancelBtn) cancelBtn.style.display = 'none';
                if (addUpdateBtn) addUpdateBtn.style.display = 'none';
                return true;
            }
            if (completeBtn) completeBtn.style.display = 'inline-flex';
            if (cancelBtn) cancelBtn.style.display = 'inline-flex';
            if (addUpdateBtn) addUpdateBtn.style.display = 'inline-flex';
            return false;
        }

        function viewReportUpdates(id, type, source, status) {
            currentUpdatesReportId = id;
            currentUpdatesReportType = type;
            currentUpdatesReportSource = source;
            currentUpdatesReportStatus = status || '';
            currentUpdatesReportDetails = { id: id, source: source, report_type: type };
            var row = document.querySelector('tr[data-id="' + id + '"]');
            if (row) {
                var ths = row.closest('table') ? row.closest('table').querySelectorAll('thead th') : [];
                row.querySelectorAll('td').forEach(function(cell, idx) {
                    var label = ((ths[idx] && ths[idx].textContent) || '').trim().toLowerCase();
                    var text = (cell.textContent || '').trim();
                    if (!text || text === '—' || label === 'action' || idx === 0) return;
                    if (label.indexOf('report') !== -1) currentUpdatesReportDetails.report_id = text;
                    else if (label === 'title') currentUpdatesReportDetails.title = text;
                    else if (label === 'type') currentUpdatesReportDetails.report_type = text;
                    else if (label === 'location') currentUpdatesReportDetails.location = text;
                    else if (label === 'department') currentUpdatesReportDetails.department = text;
                    else if (label === 'priority') currentUpdatesReportDetails.priority = text;
                    else if (label === 'status') {
                        currentUpdatesReportDetails.status = text;
                        if (!currentUpdatesReportStatus) {
                            currentUpdatesReportStatus = text.toLowerCase().replace(/\s+/g, '-');
                        }
                    }
                    else if (label === 'created') currentUpdatesReportDetails.created_at = text;
                    else if (label === 'assignment') currentUpdatesReportDetails.assignment_officer = text;
                });
            }
            document.getElementById('updateReportInfo').textContent = 'Report #' + (currentUpdatesReportDetails.report_id || id);
            openModal('updatesModal');
            if (typeof loadUpdates === 'function') {
                loadUpdates(id, type);
            }
            if (applyUpdatesFooterMode()) return;
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
            if (isTerminalUpdatesStatus()) {
                btn.style.display = 'none';
                return;
            }
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
            document.getElementById('addUpdateSource').value = currentUpdatesReportSource;
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
            // Road Operations Supervisor uses the same completion flow as Admin:
            // bypass the can_complete_report gate and complete directly.
            callback(true);
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
                    var completeBtn = document.getElementById('completeBtn');
                    var cancelBtn = document.querySelector('#actionButtons .btn-danger-custom');
                    var addUpdateBtn = document.getElementById('addUpdateBtn');
                    if (completeBtn) completeBtn.style.display = 'none';
                    if (cancelBtn) cancelBtn.style.display = 'none';
                    if (addUpdateBtn) addUpdateBtn.style.display = 'none';
                    var actionButtons = document.getElementById('actionButtons');
                    var exportWordBtn = document.getElementById('exportWordBtn');
                    if (actionButtons) actionButtons.style.display = 'flex';
                    if (exportWordBtn) exportWordBtn.style.display = 'inline-flex';
                    var exportButtons = document.getElementById('exportButtons');
                    if (exportButtons) exportButtons.style.display = 'none';
                    // Reload updates timeline
                    if (typeof loadUpdates === 'function') {
                        loadUpdates(currentUpdatesReportId, currentUpdatesReportType);
                    }
                    // Completed LGU and CIMM reports now stay in place for the
                    // 7-day retention window: the server stamps auto_archive_at
                    // on completion and the auto-archive sweep moves the report
                    // to the archive afterwards, so no immediate archive copy
                    // is filed.
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
        var _pendingDeleteTable = null;

        function deleteReport(id, type, table) {
            _pendingDeleteReport = id;
            _pendingDeleteType = type;
            _pendingDeleteTable = table || '';
            _pendingDeleteCimmIdx = null;
            document.getElementById('deleteConfirmTitle').textContent = 'Delete Report';
            document.getElementById('deleteConfirmMsg').textContent = 'Are you sure you want to delete this report? It will be moved to the archive.';
            document.getElementById('deleteConfirmInput').value = '';
            document.getElementById('deleteConfirmInput').classList.remove('valid');
            document.getElementById('deleteConfirmBtn').classList.remove('enabled');
            document.getElementById('deleteConfirmOverlay').style.display = 'block';
            setTimeout(function() { document.getElementById('deleteConfirmInput').focus(); }, 100);
        }

        function archiveReport(id, source) {
            if (!id) return;
            if (!confirm('Archive this completed project? It will be moved out of report management into the Archive, keeping its current status.')) return;

            var fd = new FormData();
            fd.append('action', 'archive_report');
            fd.append('report_id', id);
            fd.append('source', source || '');

            fetch('../api/progress_update_api.php', {
                method: 'POST',
                body: fd
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    showNotification(data.message, 'success');
                    setTimeout(function() { location.reload(); }, 700);
                } else {
                    showNotification(data.message || 'Failed to archive the project', 'error');
                }
            })
            .catch(function() {
                showNotification('Network error', 'error');
            });
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

        // Panel search — client-side filter for panels that are not server-paginated
        // (CIMM / Infrastructure). LGU + Citizen use onPanelServerSearch() instead.
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

        // AJAX panel pagination (LGU + Citizen + CIMM).
        let rmPanelPageLoading = false;
        const rmPanelSearchTimers = {};
        const rmPanelDom = {
            lgu: { table: '#lguTable', pagination: 'lguPagination', badge: 'lguReportsBadge', search: 'lguSearchInput' },
            citizen: { table: '#citizenTable', pagination: 'citizenPagination', badge: 'citizenReportsBadge', search: 'citizenSearchInput' },
            cimm: { table: '#cimmTable', pagination: 'cimmPagination', badge: 'cimmReportsBadge', search: 'cimmSearchInput' }
        };
        function onPanelServerSearch(panel) {
            if (!rmPanelDom[panel]) return;
            clearTimeout(rmPanelSearchTimers[panel]);
            rmPanelSearchTimers[panel] = setTimeout(function trySearch() {
                if (rmPanelPageLoading) {
                    rmPanelSearchTimers[panel] = setTimeout(trySearch, 150);
                    return;
                }
                loadPanelPage(panel, 1);
            }, 300);
        }
        async function loadPanelPage(panel, page) {
            if (!panel || rmPanelPageLoading) return;
            const dom = rmPanelDom[panel];
            if (!dom) return;
            const pageNum = Math.max(1, parseInt(page, 10) || 1);
            const statusEl = document.getElementById('statusFilter');
            const sourceEl = document.getElementById('sourceFilter');
            const searchInput = document.getElementById(dom.search);
            const q = searchInput ? searchInput.value.trim() : '';
            const url = new URL(window.location.href);
            url.searchParams.set('ajax', 'panel_page');
            url.searchParams.set('panel', panel);
            url.searchParams.set('page', String(pageNum));
            if (statusEl) url.searchParams.set('status', statusEl.value || 'all');
            if (sourceEl) url.searchParams.set('source', sourceEl.value || 'all');
            // Always drive AJAX search from the input via `q` only — strip stale
            // panel_q params from the current URL so clearing the box returns
            // the unfiltered list.
            url.searchParams.delete('lgu_q');
            url.searchParams.delete('citizen_q');
            url.searchParams.delete('cimm_q');
            url.searchParams.delete('q');
            if (q) url.searchParams.set('q', q);

            const tbody = document.querySelector(dom.table + ' tbody');
            const pagSlot = document.getElementById(dom.pagination);
            const badge = document.getElementById(dom.badge);
            if (!tbody || !pagSlot) return;

            rmPanelPageLoading = true;
            pagSlot.classList.add('is-loading');
            try {
                const res = await fetch(url.toString(), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    credentials: 'same-origin'
                });
                const data = await res.json();
                if (!data || !data.success) throw new Error((data && data.message) || 'Failed to load page');
                tbody.innerHTML = data.rows_html || '';
                pagSlot.innerHTML = data.pagination_html || '';
                if (badge && data.badge_text) badge.textContent = data.badge_text;
                if (panel === 'cimm' && Array.isArray(data.rows_json)) {
                    cimmData = data.rows_json;
                }

                const hist = new URL(window.location.href);
                hist.searchParams.set(panel + '_page', String(data.page || pageNum));
                if (q) hist.searchParams.set(panel + '_q', q);
                else hist.searchParams.delete(panel + '_q');
                hist.searchParams.delete('ajax');
                hist.searchParams.delete('q');
                window.history.replaceState({}, '', hist.toString());
            } catch (err) {
                console.error('Panel pagination failed:', err);
            } finally {
                pagSlot.classList.remove('is-loading');
                rmPanelPageLoading = false;
            }
        }

        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.rm-page-btn[data-panel]');
            if (!btn || btn.disabled || btn.classList.contains('disabled')) return;
            e.preventDefault();
            loadPanelPage(btn.getAttribute('data-panel'), btn.getAttribute('data-page'));
        });

        // Client-side search only for Infrastructure (not AJAX-paginated).
        [['infraSearchInput', 'infraTable']].forEach(function(pair) {
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
            if (r.assignment_officer || r.assigned_to) {
                sourceGrid += rmInfoItem('user-cog', 'Assigned To', r.assignment_officer || r.assigned_to);
            }
            if (r.assigned_by) {
                sourceGrid += rmInfoItem('user-tie', 'Assigned By', r.assigned_by);
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

            // View Map button: only shown when the report has a saved
            // coordinate point (latitude / longitude).
            currentRmPoint = (r.latitude && r.longitude && r.latitude != 0 && r.longitude != 0)
                ? [[parseFloat(r.latitude), parseFloat(r.longitude)]]
                : null;
            var rmMapBtn = document.getElementById('rm-view-map-btn');
            if (rmMapBtn) rmMapBtn.style.display = currentRmPoint ? '' : 'none';
            var rmMapContainer = document.getElementById('rm-map-container');
            if (rmMapContainer) rmMapContainer.classList.remove('road-map-visible');

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

            // Admin-only: the Status dropdown only offers Approved / In
            // Progress. When the report's current status is outside that set
            // (e.g. Completed or Cancelled), show it as a disabled, non-editable
            // option so it is never silently coerced to "Approved". Other roles
            // keep their existing dropdown options and behavior untouched.
            var cimmStatusSelect = document.getElementById('editCimmStatus');
            var currentCimmStatus = r.status || 'pending';
            <?php if ($is_system_admin): ?>
            while (cimmStatusSelect.querySelector('option[data-current="1"]')) {
                cimmStatusSelect.removeChild(cimmStatusSelect.querySelector('option[data-current="1"]'));
            }
            var allowedCimmStatuses = ['approved', 'in-progress'];
            if (allowedCimmStatuses.indexOf(currentCimmStatus) === -1) {
                var curOpt = document.createElement('option');
                curOpt.value = currentCimmStatus;
                curOpt.textContent = currentCimmStatus.replace(/-/g, ' ').replace(/\b\w/g, function(m) { return m.toUpperCase(); });
                curOpt.disabled = true;
                curOpt.setAttribute('data-current', '1');
                cimmStatusSelect.appendChild(curOpt);
            }
            <?php endif; ?>
            cimmStatusSelect.value = currentCimmStatus;
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
            _pendingDeleteTable = null;
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

        // Infrastructure (IPMS) edit
        function editIpmsProject(id) {
            var r = infraIpmsDataMap[id];
            if (!r) {
                if (typeof showNotification === 'function') {
                    showNotification('Project data not found.', 'error');
                }
                return;
            }
            if (!document.getElementById('editIpmsModal') || !document.getElementById('editIpmsReportId')) {
                if (typeof showNotification === 'function') {
                    showNotification('Edit form is not available.', 'error');
                }
                return;
            }
            document.getElementById('editIpmsReportId').value = r.id;
            document.getElementById('editIpmsRepNumber').value = r.report_id || '';
            document.getElementById('editIpmsTitle').value = r.title || '';
            document.getElementById('editIpmsDescription').value = r.description || '';
            document.getElementById('editIpmsStartDate').value = r.start_date ? String(r.start_date).slice(0, 10) : '';
            document.getElementById('editIpmsEndDate').value = (r.end_date || r.due_date) ? String(r.end_date || r.due_date).slice(0, 10) : '';
            document.getElementById('editIpmsBudget').value = (r.budget != null && r.budget !== '' && Number(r.budget) !== 0) ? r.budget : '';
            document.getElementById('editIpmsStartAddress').value = r.start_address || '';
            document.getElementById('editIpmsEndAddress').value = r.end_address || '';
            var ipmsStatusSelect = document.getElementById('editIpmsStatus');
            var currentIpmsStatus = (r.status && r.status !== '—') ? r.status : 'approved';
            while (ipmsStatusSelect.querySelector('option[data-current="1"]')) {
                ipmsStatusSelect.removeChild(ipmsStatusSelect.querySelector('option[data-current="1"]'));
            }
            var allowedIpmsStatuses = ['approved', 'in-progress'];
            if (allowedIpmsStatuses.indexOf(currentIpmsStatus) === -1) {
                var curOpt = document.createElement('option');
                curOpt.value = currentIpmsStatus;
                curOpt.textContent = currentIpmsStatus.replace(/-/g, ' ').replace(/\b\w/g, function(m) { return m.toUpperCase(); });
                curOpt.disabled = true;
                curOpt.setAttribute('data-current', '1');
                ipmsStatusSelect.appendChild(curOpt);
            }
            ipmsStatusSelect.value = currentIpmsStatus;
            document.getElementById('editIpmsPriority').value = (r.priority && r.priority !== '—') ? r.priority : 'medium';
            document.getElementById('editIpmsReportTable').value = 'ipms_road_projects';
            document.getElementById('ipmsEditIndicator').textContent = '';
            document.getElementById('ipmsEditSubmitBtn').disabled = false;
            document.getElementById('ipmsEditSubmitBtn').innerHTML = '<i class="fas fa-save"></i> Save Changes';
            openModal('editIpmsModal');
            loadAssignedUsers();
        }

        // Infrastructure (IPMS) delete
        var _pendingDeleteIpmsId = null;

        function deleteIpmsProject(id) {
            var r = infraIpmsDataMap[id];
            if (!r) return;
            _pendingDeleteIpmsId = id;
            _pendingDeleteCimmIdx = null;
            _pendingDeleteReport = null;
            _pendingDeleteType = null;
            _pendingDeleteTable = null;
            document.getElementById('deleteConfirmTitle').textContent = 'Delete Infrastructure Project';
            document.getElementById('deleteConfirmMsg').textContent = 'Are you sure you want to delete infrastructure project "' + (r.report_id || '') + '"? It will be moved to the archive.';
            document.getElementById('deleteConfirmInput').value = '';
            document.getElementById('deleteConfirmInput').classList.remove('valid');
            document.getElementById('deleteConfirmBtn').classList.remove('enabled');
            document.getElementById('deleteConfirmOverlay').style.display = 'block';
            setTimeout(function() { document.getElementById('deleteConfirmInput').focus(); }, 100);
        }

        // Infrastructure (IPMS) edit form submission
        var editIpmsFormEl = document.getElementById('editIpmsForm');
        if (editIpmsFormEl) editIpmsFormEl.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);
            var submitBtn = document.getElementById('ipmsEditSubmitBtn');
            var indicator = document.getElementById('ipmsEditIndicator');
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
                    showNotification(data.message || 'Infrastructure project updated successfully', 'success');
                    closeModal('editIpmsModal');
                    indicator.textContent = '';
                    setTimeout(function() { window.location.reload(); }, 800);
                } else {
                    showNotification(data.message || 'Failed to update infrastructure project', 'error');
                    indicator.textContent = 'Failed to save changes';
                }
            })
            .catch(function(err) {
                console.error('Error:', err);
                showNotification('Error updating infrastructure project', 'error');
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
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="../../js/session-timeout.js"></script>
    <script>
        function updateDateTime() {
            const now = new Date();
            const dateOptions = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const timeOptions = { hour: '2-digit', minute: '2-digit', second: '2-digit' };
            const dateEl = document.getElementById('currentDate');
            const timeEl = document.getElementById('currentTime');
            if (dateEl) dateEl.textContent = now.toLocaleDateString('en-US', dateOptions);
            if (timeEl) timeEl.textContent = now.toLocaleTimeString('en-US', timeOptions);
        }
        updateDateTime();
        setInterval(updateDateTime, 1000);
    </script>
</body>
</html>