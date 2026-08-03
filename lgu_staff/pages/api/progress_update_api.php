<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => 'Unauthorized'], 401);
}

$user_id = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'get_updates' || $action === 'get_update') {
        // Allow read-only access without login for public timeline
        if ($action === 'get_updates') {
            $report_id = intval($_GET['report_id'] ?? 0);
            if ($report_id <= 0) {
                json_response(['success' => false, 'message' => 'Invalid report ID']);
            }
            $report = fetch_one("SELECT id, assigned_to FROM road_transportation_reports WHERE id = ?", [$report_id], "i");
            if (!$report) {
                $report = fetch_one("SELECT id, maintenance_team as assigned_to FROM road_maintenance_reports WHERE id = ?", [$report_id], "i");
            }
            if (!$report) {
                $report = fetch_one("SELECT id, cprf_facility_name as assigned_to FROM cimm_verification_reports WHERE id = ?", [$report_id], "i");
            }
            if (!$report) {
                json_response(['success' => false, 'message' => 'Report not found']);
            }
            
            // Check permission for adding updates
            $user_role = $_SESSION['role'] ?? '';
            $privileged_roles = ['system_admin', 'road_ops_supervisor', 'transpo_ops_supervisor'];
            $is_privileged = in_array($user_role, $privileged_roles);
            
            $assigned_user_id = null;
            if (!empty($report['assigned_to'])) {
                $assigned_user = fetch_one("SELECT id FROM users WHERE username = ? OR id = ?", [$report['assigned_to'], $report['assigned_to']], "ss");
                if ($assigned_user) {
                    $assigned_user_id = $assigned_user['id'];
                }
            }
            
            $can_add_update = $is_privileged || !$assigned_user_id || $assigned_user_id == $user_id;
            
            $updates = [];
            $q = "SELECT u.*, COALESCE(us.full_name, 'LGU Staff') as admin_name 
                  FROM report_updates u 
                  LEFT JOIN users us ON u.user_id = us.id 
                  WHERE u.report_id = ? 
                  ORDER BY u.created_at ASC";
            $stmt = $conn->prepare($q);
            $stmt->bind_param("i", $report_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $row['created_at_formatted'] = date('M d, Y h:i A', strtotime($row['created_at']));
                $media = [];
                $m_stmt = $conn->prepare("SELECT id, file_path, file_type FROM report_update_media WHERE update_id = ? ORDER BY id ASC");
                $m_stmt->bind_param("i", $row['id']);
                $m_stmt->execute();
                $m_res = $m_stmt->get_result();
                while ($m = $m_res->fetch_assoc()) $media[] = $m;
                $row['media'] = $media;
                $updates[] = $row;
            }
            json_response(['success' => true, 'updates' => $updates, 'can_add_update' => $can_add_update]);
        } elseif ($action === 'get_update') {
            if (!is_logged_in()) json_response(['success' => false, 'message' => 'Unauthorized'], 401);
            $id = intval($_GET['id'] ?? 0);
            if ($id <= 0) json_response(['success' => false, 'message' => 'Invalid ID']);
            $q = "SELECT u.*, us.full_name as admin_name FROM report_updates u LEFT JOIN users us ON u.user_id = us.id WHERE u.id = ?";
            $stmt = $conn->prepare($q);
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $update = $res->fetch_assoc();
            if (!$update) json_response(['success' => false, 'message' => 'Update not found']);
            $media = [];
            $m_stmt = $conn->prepare("SELECT id, file_path, file_type FROM report_update_media WHERE update_id = ? ORDER BY id ASC");
            $m_stmt->bind_param("i", $update['id']);
            $m_stmt->execute();
            $m_res = $m_stmt->get_result();
            while ($m = $m_res->fetch_assoc()) $media[] = $m;
            $update['media'] = $media;
            json_response(['success' => true, 'update' => $update]);
        }
    } else {
        json_response(['success' => false, 'message' => 'Unknown action']);
    }
} elseif ($method === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_update') {
        $report_id = intval($_POST['report_id'] ?? 0);
        $report_type = sanitize_input($_POST['report_type'] ?? 'transportation');
        $title = sanitize_input($_POST['title'] ?? '');
        $description = sanitize_input($_POST['description'] ?? '');

        if ($report_id <= 0) json_response(['success' => false, 'message' => 'Invalid report ID']);
        if (empty($description)) json_response(['success' => false, 'message' => 'Description is required']);

        $report = fetch_one("SELECT id, report_id, assigned_to FROM road_transportation_reports WHERE id = ?", [$report_id], "i");
        if (!$report) {
            $report = fetch_one("SELECT id, report_id, maintenance_team as assigned_to FROM road_maintenance_reports WHERE id = ?", [$report_id], "i");
        }
        if (!$report) {
            $report = fetch_one("SELECT id, reference_code AS report_id, cprf_facility_name as assigned_to FROM cimm_verification_reports WHERE id = ?", [$report_id], "i");
        }
        if (!$report) json_response(['success' => false, 'message' => 'Report not found']);

        // Check permission: only assigned user or privileged roles can add updates
        $user_role = $_SESSION['role'] ?? '';
        $privileged_roles = ['system_admin', 'road_ops_supervisor', 'transpo_ops_supervisor'];
        $is_privileged = in_array($user_role, $privileged_roles);
        
        // Get assigned user ID from the assigned_to field (which may be a username or user ID)
        $assigned_user_id = null;
        if (!empty($report['assigned_to'])) {
            // Try to get user ID from username or check if it's already an ID
            $assigned_user = fetch_one("SELECT id FROM users WHERE username = ? OR id = ?", [$report['assigned_to'], $report['assigned_to']], "ss");
            if ($assigned_user) {
                $assigned_user_id = $assigned_user['id'];
            }
        }
        
        // Allow if: privileged role OR user is the assigned user OR no one is assigned yet
        if (!$is_privileged && $assigned_user_id && $assigned_user_id != $user_id) {
            json_response(['success' => false, 'message' => 'You do not have permission to add updates to this report. Only the assigned user can add progress updates.'], 403);
        }

        try {
            // Insert update
            $stmt = $conn->prepare("INSERT INTO report_updates (report_id, user_id, title, description) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiss", $report_id, $user_id, $title, $description);
            $stmt->execute();
            $update_id = $conn->insert_id;

            // Handle media uploads
            $upload_dir = __DIR__ . '/../../uploads/progress_updates';
            $uploaded = handleProgressMediaUpload($_FILES['media'] ?? [], $upload_dir, $update_id);

            // Create notification
            createReportNotification($report_id, $update_id, $title ?: 'Progress Update', $report);

            // Audit log
            log_audit_action($user_id, "Created progress update", "Report ID: {$report['report_id']}, Update ID: {$update_id}");

            json_response(['success' => true, 'message' => 'Progress update posted successfully', 'update_id' => $update_id, 'photos' => $uploaded]);
        } catch (Exception $e) {
            error_log("Create progress update error: " . $e->getMessage());
            json_response(['success' => false, 'message' => 'Failed to save update: ' . $e->getMessage()], 500);
        }
    } elseif ($action === 'edit_update') {
        $update_id = intval($_POST['update_id'] ?? 0);
        $title = sanitize_input($_POST['title'] ?? '');
        $description = sanitize_input($_POST['description'] ?? '');

        if ($update_id <= 0) json_response(['success' => false, 'message' => 'Invalid update ID']);
        if (empty($description)) json_response(['success' => false, 'message' => 'Description is required']);

        // Verify ownership/permission — check report tables
        $update = fetch_one("SELECT u.*, r.report_id, r.assigned_to FROM report_updates u JOIN road_transportation_reports r ON u.report_id = r.id WHERE u.id = ?", [$update_id], "i");
        if (!$update) {
            $update = fetch_one("SELECT u.*, r.report_id, r.maintenance_team as assigned_to FROM report_updates u JOIN road_maintenance_reports r ON u.report_id = r.id WHERE u.id = ?", [$update_id], "i");
        }
        if (!$update) {
            $update = fetch_one("SELECT u.*, cr.reference_code AS report_id, cr.cprf_facility_name as assigned_to FROM report_updates u JOIN cimm_verification_reports cr ON u.report_id = cr.id WHERE u.id = ?", [$update_id], "i");
        }
        if (!$update) json_response(['success' => false, 'message' => 'Update not found']);

        // Check permission: only assigned user or privileged roles can edit updates
        $user_role = $_SESSION['role'] ?? '';
        $privileged_roles = ['system_admin', 'road_ops_supervisor', 'transpo_ops_supervisor'];
        $is_privileged = in_array($user_role, $privileged_roles);
        
        // Get assigned user ID from the assigned_to field
        $assigned_user_id = null;
        if (!empty($update['assigned_to'])) {
            $assigned_user = fetch_one("SELECT id FROM users WHERE username = ? OR id = ?", [$update['assigned_to'], $update['assigned_to']], "ss");
            if ($assigned_user) {
                $assigned_user_id = $assigned_user['id'];
            }
        }
        
        // Allow if: privileged role OR user is the assigned user OR no one is assigned yet
        if (!$is_privileged && $assigned_user_id && $assigned_user_id != $user_id) {
            json_response(['success' => false, 'message' => 'You do not have permission to edit updates on this report. Only the assigned user can edit progress updates.'], 403);
        }

        try {
            $stmt = $conn->prepare("UPDATE report_updates SET title = ?, description = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("ssi", $title, $description, $update_id);
            $stmt->execute();

            // Handle new media uploads
            $upload_dir = __DIR__ . '/../../uploads/progress_updates';
            handleProgressMediaUpload($_FILES['media'] ?? [], $upload_dir, $update_id);

            // Handle removed media
            if (!empty($_POST['remove_media'])) {
                $remove_ids = array_map('intval', (array)$_POST['remove_media']);
                foreach ($remove_ids as $rid) {
                    $m = fetch_one("SELECT file_path FROM report_update_media WHERE id = ? AND update_id = ?", [$rid, $update_id], "ii");
                    if ($m) {
                        $full = $upload_dir . '/' . basename($m['file_path']);
                        if (file_exists($full)) @unlink($full);
                        $conn->query("DELETE FROM report_update_media WHERE id = {$rid}");
                    }
                }
            }

            log_audit_action($user_id, "Edited progress update", "Update ID: {$update_id}");
            json_response(['success' => true, 'message' => 'Update edited successfully']);
        } catch (Exception $e) {
            error_log("Edit progress update error: " . $e->getMessage());
            json_response(['success' => false, 'message' => 'Failed to edit update: ' . $e->getMessage()], 500);
        }
    } elseif ($action === 'delete_update') {
        $update_id = intval($_POST['update_id'] ?? 0);
        if ($update_id <= 0) json_response(['success' => false, 'message' => 'Invalid update ID']);

        $update = fetch_one("SELECT * FROM report_updates WHERE id = ?", [$update_id], "i");
        if (!$update) json_response(['success' => false, 'message' => 'Update not found']);

        try {
            // Delete media files
            $upload_dir = __DIR__ . '/../../uploads/progress_updates';
            $media = $conn->query("SELECT file_path FROM report_update_media WHERE update_id = {$update_id}");
            while ($m = $media->fetch_assoc()) {
                $full = $upload_dir . '/' . basename($m['file_path']);
                if (file_exists($full)) @unlink($full);
            }

            // CASCADE deletes media rows automatically, but also delete notification reference
            $conn->query("DELETE FROM report_notifications WHERE update_id = {$update_id}");
            $stmt = $conn->prepare("DELETE FROM report_updates WHERE id = ?");
            $stmt->bind_param("i", $update_id);
            $stmt->execute();

            log_audit_action($user_id, "Deleted progress update", "Update ID: {$update_id}");
            json_response(['success' => true, 'message' => 'Update deleted']);
        } catch (Exception $e) {
            error_log("Delete progress update error: " . $e->getMessage());
            json_response(['success' => false, 'message' => 'Failed to delete update: ' . $e->getMessage()], 500);
        }
    } elseif ($action === 'update_status') {
        $report_id = intval($_POST['report_id'] ?? 0);
        $report_type = sanitize_input($_POST['report_type'] ?? '');
        $status = sanitize_input($_POST['status'] ?? '');
        $source = sanitize_input($_POST['source'] ?? '');

        if ($report_id <= 0) json_response(['success' => false, 'message' => 'Invalid report ID']);
        if (empty($status)) json_response(['success' => false, 'message' => 'Status is required']);

        try {
            if ($source === 'cimm') {
                // Update cimm_verification_reports table
                $stmt = $conn->prepare("UPDATE cimm_verification_reports SET verification_status = ? WHERE id = ?");
                $stmt->bind_param("si", $status, $report_id);
                $stmt->execute();
                log_audit_action($user_id, "Updated CIMM report status", "Report ID: {$report_id}, Status: {$status}");
                json_response(['success' => true, 'message' => 'Status updated successfully']);
            } else {
                // Update road_transportation_reports table
                $stmt = $conn->prepare("UPDATE road_transportation_reports SET status = ? WHERE id = ?");
                $stmt->bind_param("si", $status, $report_id);
                $stmt->execute();
                log_audit_action($user_id, "Updated report status", "Report ID: {$report_id}, Status: {$status}");
                
                // If status is 'completed' or 'cancelled', archive the report
                if ($status === 'completed' || $status === 'cancelled') {
                    archive_completed_report($conn, 'road_transportation_reports', $report_id);
                }
                
                json_response(['success' => true, 'message' => 'Status updated successfully']);
            }
        } catch (Exception $e) {
            error_log("Update status error: " . $e->getMessage());
            json_response(['success' => false, 'message' => 'Failed to update status: ' . $e->getMessage()], 500);
        }
    } else {
        json_response(['success' => false, 'message' => 'Unknown action']);
    }
} else {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

// --- Helper functions ---

function handleProgressMediaUpload($files, $upload_dir, $update_id) {
    global $conn;
    $uploaded = [];
    if (empty($files) || !is_array($files['name'])) return $uploaded;

    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
        chmod($upload_dir, 0777);
    }

    foreach ($files['name'] as $i => $name) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        $file = [
            'name' => $files['name'][$i],
            'type' => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error' => $files['error'][$i],
            'size' => $files['size'][$i]
        ];

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $image_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $video_types = ['mp4', 'webm'];
        $allowed = array_merge($image_types, $video_types);

        if (!in_array($ext, $allowed)) continue;
        if ($file['size'] > 10 * 1024 * 1024) continue;

        $filename = uniqid('upd_') . '.' . $ext;
        $dest = $upload_dir . '/' . $filename;
        if (move_uploaded_file($file['tmp_name'], $dest)) {
            chmod($dest, 0644);
            $relative = 'uploads/progress_updates/' . $filename;
            $file_type = in_array($ext, $image_types) ? 'image' : 'video';

            $stmt = $conn->prepare("INSERT INTO report_update_media (update_id, file_path, file_type) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $update_id, $relative, $file_type);
            $stmt->execute();
            $uploaded[] = ['id' => $conn->insert_id, 'path' => $relative, 'type' => $file_type];
        }
    }
    return $uploaded;
}

function createReportNotification($report_id, $update_id, $title, $report) {
    global $conn;
    $report_label = $report['report_id'] ?? "#{$report_id}";
    $message = "New progress update on report {$report_label}: {$title}";
    try {
        $stmt = $conn->prepare("INSERT INTO report_notifications (report_id, update_id, type, message) VALUES (?, ?, 'progress_update', ?)");
        $stmt->bind_param("iis", $report_id, $update_id, $message);
        $stmt->execute();
    } catch (Exception $e) {
        error_log("Create notification error: " . $e->getMessage());
    }
}

// Archive a completed report by copying it to the archive table and removing from the active table
function archive_completed_report($conn, $table, $report_id) {
    try {
        // Ensure archive table exists
        $conn->query("CREATE TABLE IF NOT EXISTS road_transportation_reports_archive LIKE road_transportation_reports");
        
        // Sync columns
        foreach (['road_transportation_reports', 'road_maintenance_reports'] as $src_table) {
            $arch_cols = [];
            $arch = $conn->query("SHOW COLUMNS FROM road_transportation_reports_archive");
            if ($arch) { while ($row = $arch->fetch_assoc()) { $arch_cols[$row['Field']] = true; } }
            $src = $conn->query("SHOW COLUMNS FROM $src_table");
            if ($src) { while ($row = $src->fetch_assoc()) {
                if (!isset($arch_cols[$row['Field']])) {
                    $conn->query("ALTER TABLE road_transportation_reports_archive ADD COLUMN `{$row['Field']}` {$row['Type']} NULL");
                }
            } }
        }
        
        $conn->begin_transaction();
        
        // Get all columns from the source table
        $fields = [];
        $col_res = $conn->query("SHOW COLUMNS FROM $table");
        if ($col_res) { while ($col_row = $col_res->fetch_assoc()) { $fields[] = "`{$col_row['Field']}`"; } }
        if (empty($fields)) { throw new Exception("No columns found for table $table"); }
        $cols = implode(', ', $fields);
        
        // Copy the report to archive with status 'completed'
        $stmt = $conn->prepare("INSERT INTO road_transportation_reports_archive ($cols) SELECT $cols FROM $table WHERE id = ?");
        $stmt->bind_param('i', $report_id);
        $stmt->execute();
        
        // Set the status to 'completed' in the archive
        $conn->query("UPDATE road_transportation_reports_archive SET status = 'completed' WHERE id = $report_id");
        
        // Remove related active records
        $del = $conn->prepare("DELETE FROM report_notifications WHERE report_id = ?");
        $del->bind_param('i', $report_id);
        $del->execute();
        
        $del = $conn->prepare("DELETE FROM report_updates WHERE report_id = ?");
        $del->bind_param('i', $report_id);
        $del->execute();
        
        $del = $conn->prepare("DELETE FROM project_analytics WHERE report_id = ? AND report_table = ?");
        $del->bind_param('is', $report_id, $table);
        $del->execute();
        
        // Remove the report from the active table
        $del = $conn->prepare("DELETE FROM $table WHERE id = ?");
        $del->bind_param('i', $report_id);
        $del->execute();
        
        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        error_log('archive_completed_report failed: ' . $e->getMessage());
        return false;
    }
}
