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
            $report = fetch_one("SELECT id FROM road_transportation_reports WHERE id = ?", [$report_id], "i");
            if (!$report) {
                $report = fetch_one("SELECT id FROM road_maintenance_reports WHERE id = ?", [$report_id], "i");
            }
            if (!$report) {
                $report = fetch_one("SELECT id FROM cimm_verification_reports WHERE id = ?", [$report_id], "i");
            }
            if (!$report) {
                json_response(['success' => false, 'message' => 'Report not found']);
            }
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
            json_response(['success' => true, 'updates' => $updates]);
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

        $report = fetch_one("SELECT id, report_id FROM road_transportation_reports WHERE id = ?", [$report_id], "i");
        if (!$report) {
            $report = fetch_one("SELECT id, report_id FROM road_maintenance_reports WHERE id = ?", [$report_id], "i");
        }
        if (!$report) {
            $report = fetch_one("SELECT id, reference_code AS report_id FROM cimm_verification_reports WHERE id = ?", [$report_id], "i");
        }
        if (!$report) json_response(['success' => false, 'message' => 'Report not found']);

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
        $update = fetch_one("SELECT u.*, r.report_id FROM report_updates u JOIN road_transportation_reports r ON u.report_id = r.id WHERE u.id = ?", [$update_id], "i");
        if (!$update) {
            $update = fetch_one("SELECT u.*, r.report_id FROM report_updates u JOIN road_maintenance_reports r ON u.report_id = r.id WHERE u.id = ?", [$update_id], "i");
        }
        if (!$update) {
            $update = fetch_one("SELECT u.*, cr.reference_code AS report_id FROM report_updates u JOIN cimm_verification_reports cr ON u.report_id = cr.id WHERE u.id = ?", [$update_id], "i");
        }
        if (!$update) json_response(['success' => false, 'message' => 'Update not found']);

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
                json_response(['success' => true, 'message' => 'Status updated successfully']);
            }
        } catch (Exception $e) {
            error_log("Update status error: " . $e->getMessage());
            json_response(['success' => false, 'message' => 'Failed to update status: ' . $e->getMessage()], 500);
        }
    } elseif ($action === 'cancel_archive') {
        // Cancel a report AND move it to the archive (road_transportation_monitoring.php).
        // Dedicated action so it never affects the plain update_status behavior
        // used by other pages. The row is copied into the archive table with
        // status 'cancelled', then removed from the live table.
        $report_id = intval($_POST['report_id'] ?? 0);
        $source = sanitize_input($_POST['source'] ?? '');

        if ($report_id <= 0) json_response(['success' => false, 'message' => 'Invalid report ID']);

        try {
            if ($source === 'cimm') {
                $archived = rgmap_archive_cimm_report($conn, $report_id, 'cancelled');
            } else {
                $table = rgmap_resolve_report_table($conn, $report_id);
                $archived = $table ? rgmap_archive_report($conn, $table, $report_id, 'cancelled') : false;
            }
            if (!$archived) {
                json_response(['success' => false, 'message' => 'Failed to cancel and archive the report'], 500);
            }
            log_audit_action($user_id, "Cancelled and archived report", "Report ID: {$report_id}, Status: cancelled");
            json_response(['success' => true, 'message' => 'Report cancelled and moved to archive']);
        } catch (Exception $e) {
            error_log("Cancel archive error: " . $e->getMessage());
            json_response(['success' => false, 'message' => 'Failed to cancel and archive the report: ' . $e->getMessage()], 500);
        }
    } elseif ($action === 'complete_archive') {
        // File a COMPLETED COPY of a report into the archive
        // (road_transportation_monitoring.php). This is purely additive: the
        // live report is left exactly as it is (still completed and present) —
        // nothing is moved or deleted.
        $report_id = intval($_POST['report_id'] ?? 0);
        $source = sanitize_input($_POST['source'] ?? '');

        if ($report_id <= 0) json_response(['success' => false, 'message' => 'Invalid report ID']);

        try {
            if ($source === 'cimm') {
                $archived = rgmap_archive_copy_cimm_report($conn, $report_id, 'completed');
            } else {
                $table = rgmap_resolve_report_table($conn, $report_id);
                $archived = $table ? rgmap_archive_report_copy($conn, $table, $report_id, 'completed') : false;
            }
            if (!$archived) {
                json_response(['success' => false, 'message' => 'Failed to file archive copy'], 500);
            }
            json_response(['success' => true, 'message' => 'Report filed in archive as completed']);
        } catch (Exception $e) {
            error_log("Complete archive error: " . $e->getMessage());
            json_response(['success' => false, 'message' => 'Failed to file archive copy: ' . $e->getMessage()], 500);
        }
    } else {
        json_response(['success' => false, 'message' => 'Unknown action']);
    }
} else {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

// --- Helper functions ---

// Make sure the archive table exists and carries every column of both live
// report tables, so copying ANY report row preserves all its data. Widens
// report_type so maintenance rows ('routine','emergency', etc.) can be archived
// without "Data truncated" errors. Mirrors ensure_archive_table() used by
// report_management.php.
function rgmap_archive_ensure_table() {
    global $conn;
    $conn->query("CREATE TABLE IF NOT EXISTS road_transportation_reports_archive LIKE road_transportation_reports");
    try {
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
    } catch (Exception $e) { error_log('rgmap_archive_ensure_table sync: ' . $e->getMessage()); }
    try {
        $conn->query("ALTER TABLE road_transportation_reports_archive MODIFY report_type VARCHAR(255) NULL DEFAULT NULL");
    } catch (Exception $e) { error_log('rgmap_archive_ensure_table report_type widen: ' . $e->getMessage()); }
    try {
        $chk = $conn->query("SHOW COLUMNS FROM road_transportation_reports_archive LIKE 'previous_status'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE road_transportation_reports_archive ADD COLUMN previous_status VARCHAR(50) NULL DEFAULT NULL");
        }
    } catch (Exception $e) { error_log('rgmap_archive_ensure_table previous_status: ' . $e->getMessage()); }
}

// Return the live report table that actually contains the given id, or null.
function rgmap_resolve_report_table($conn, $report_id) {
    foreach (['road_transportation_reports', 'road_maintenance_reports'] as $table) {
        $stmt = $conn->prepare("SELECT id FROM $table WHERE id = ?");
        $stmt->bind_param("i", $report_id);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($r) return $table;
    }
    return null;
}

// Move a report into the archive — copy every column into
// road_transportation_reports_archive, then remove it from the live table —
// carrying the given terminal status. Works for rows in either
// road_transportation_reports or road_maintenance_reports.
function rgmap_archive_report($conn, $table, $report_id, $status) {
    try {
        rgmap_archive_ensure_table();
        $conn->begin_transaction();

        // Capture the report's status BEFORE applying the terminal status so it
        // can be restored exactly where its last action happened.
        $prev_stmt = $conn->prepare("SELECT status FROM $table WHERE id = ?");
        $prev_stmt->bind_param("i", $report_id);
        $prev_stmt->execute();
        $prev_row = $prev_stmt->get_result()->fetch_assoc();
        $previous_status = ($prev_row && isset($prev_row['status'])) ? $prev_row['status'] : null;
        $prev_stmt->close();

        // Mark the live row with the terminal status first so the archived copy
        // below carries that status while preserving all other columns.
        $stmt = $conn->prepare("UPDATE $table SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $status, $report_id);
        $stmt->execute();

        $fields = [];
        $col_res = $conn->query("SHOW COLUMNS FROM $table");
        if ($col_res) { while ($col_row = $col_res->fetch_assoc()) { $fields[] = "`{$col_row['Field']}`"; } }
        if (empty($fields)) { throw new Exception("No columns found for table $table"); }
        $cols = implode(', ', $fields);

        $stmt = $conn->prepare("INSERT INTO road_transportation_reports_archive ($cols) SELECT $cols FROM $table WHERE id = ?");
        $stmt->bind_param("i", $report_id);
        $stmt->execute();

        if ($previous_status !== null) {
            $ps = $conn->prepare("UPDATE road_transportation_reports_archive SET previous_status = ? WHERE id = ?");
            $ps->bind_param("si", $previous_status, $report_id);
            $ps->execute();
        }

        $stmt = $conn->prepare("DELETE FROM $table WHERE id = ?");
        $stmt->bind_param("i", $report_id);
        $stmt->execute();

        $conn->commit();
        return true;
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollback();
        error_log("rgmap_archive_report failed: " . $e->getMessage());
        return false;
    }
}

// Archive a CIMM report (stored in cimm_verification_reports). The row is
// mapped into the archive table with report_source 'external' (so archive.php
// labels it CIMM) and the given terminal status, then removed from the CIMM
// table — mirroring archive_cimm_rejected_report().
function rgmap_archive_cimm_report($conn, $cimm_req_id, $status) {
    try {
        rgmap_archive_ensure_table();

        $stmt = $conn->prepare("SELECT * FROM cimm_verification_reports WHERE id = ?");
        $stmt->bind_param("i", $cimm_req_id);
        $stmt->execute();
        $cimm_report = $stmt->get_result()->fetch_assoc();
        if (!$cimm_report) return false;

        $now = date('Y-m-d H:i:s');
        $conn->begin_transaction();

        $timestamp_key = ($status === 'cancelled') ? 'rejected_at' : 'completed_at';
        $insert_fields = [
            'report_id' => $cimm_report['reference_code'] ?? ('CIMM-' . $cimm_req_id),
            'title' => $cimm_report['infrastructure'] ?? 'CIMM Report',
            'report_type' => 'infrastructure_issue',
            'report_category' => 'road',
            'report_source' => 'external',
            'department' => 'engineering',
            'priority' => $cimm_report['priority'] ?? 'medium',
            'status' => $status,
            'previous_status' => $cimm_report['verification_status'] ?? null,
            'created_date' => (!empty($cimm_report['submitted_at'])) ? date('Y-m-d', strtotime($cimm_report['submitted_at'])) : date('Y-m-d'),
            'description' => $cimm_report['issue'] ?? '',
            'location' => $cimm_report['location'] ?? '',
            'latitude' => $cimm_report['coord_lat'] ?? null,
            'longitude' => $cimm_report['coord_lng'] ?? null,
            'created_at' => $cimm_report['submitted_at'] ?? $now,
            'updated_at' => $now,
            $timestamp_key => $now,
            'approved_at' => null,
        ];

        $fields = array_keys($insert_fields);
        $placeholders = array_fill(0, count($fields), '?');
        $field_list = '`' . implode('`, `', $fields) . '`';
        $placeholder_list = implode(', ', $placeholders);

        $insert = "INSERT INTO road_transportation_reports_archive ($field_list) VALUES ($placeholder_list)";
        $stmt = $conn->prepare($insert);
        $stmt->execute(array_values($insert_fields));

        $delete = $conn->prepare("DELETE FROM cimm_verification_reports WHERE id = ?");
        $delete->execute([$cimm_req_id]);

        $conn->commit();
        return true;
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollback();
        error_log("rgmap_archive_cimm_report failed: " . $e->getMessage());
        return false;
    }
}

// File a COPY of a report into the archive with the given status, WITHOUT
// moving or deleting anything — the live row stays exactly as it is. The
// archive auto-generates its own id so repeated copies never collide.
function rgmap_archive_report_copy($conn, $table, $report_id, $status) {
    try {
        rgmap_archive_ensure_table();

        $fields = [];
        $col_res = $conn->query("SHOW COLUMNS FROM $table");
        if ($col_res) {
            while ($col_row = $col_res->fetch_assoc()) {
                if (strtolower($col_row['Field']) === 'id') continue;
                $fields[] = "`{$col_row['Field']}`";
            }
        }
        if (empty($fields)) { throw new Exception("No columns found for table $table"); }
        $cols = implode(', ', $fields);

        $prev_stmt = $conn->prepare("SELECT status FROM $table WHERE id = ?");
        $prev_stmt->bind_param("i", $report_id);
        $prev_stmt->execute();
        $prev_row = $prev_stmt->get_result()->fetch_assoc();
        $previous_status = ($prev_row && isset($prev_row['status'])) ? $prev_row['status'] : null;
        $prev_stmt->close();

        $conn->begin_transaction();
        $stmt = $conn->prepare("INSERT INTO road_transportation_reports_archive ($cols) SELECT $cols FROM $table WHERE id = ?");
        $stmt->bind_param("i", $report_id);
        $stmt->execute();

        // Set the archived copy to the terminal status and bump its updated_at.
        $arch_id = $conn->insert_id;
        $stmt = $conn->prepare("UPDATE road_transportation_reports_archive SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $status, $arch_id);
        $stmt->execute();

        if ($previous_status !== null) {
            $ps = $conn->prepare("UPDATE road_transportation_reports_archive SET previous_status = ? WHERE id = ?");
            $ps->bind_param("si", $previous_status, $arch_id);
            $ps->execute();
        }

        $conn->commit();
        return true;
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollback();
        error_log("rgmap_archive_report_copy failed: " . $e->getMessage());
        return false;
    }
}

// File a COPY of a CIMM report into the archive with the given status, WITHOUT
// deleting it from cimm_verification_reports.
function rgmap_archive_copy_cimm_report($conn, $cimm_req_id, $status) {
    try {
        rgmap_archive_ensure_table();

        $stmt = $conn->prepare("SELECT * FROM cimm_verification_reports WHERE id = ?");
        $stmt->bind_param("i", $cimm_req_id);
        $stmt->execute();
        $cimm_report = $stmt->get_result()->fetch_assoc();
        if (!$cimm_report) return false;

        $now = date('Y-m-d H:i:s');
        $timestamp_key = ($status === 'cancelled') ? 'rejected_at' : 'completed_at';
        $insert_fields = [
            'report_id' => $cimm_report['reference_code'] ?? ('CIMM-' . $cimm_req_id),
            'title' => $cimm_report['infrastructure'] ?? 'CIMM Report',
            'report_type' => 'infrastructure_issue',
            'report_category' => 'road',
            'report_source' => 'external',
            'department' => 'engineering',
            'priority' => $cimm_report['priority'] ?? 'medium',
            'status' => $status,
            'previous_status' => $cimm_report['verification_status'] ?? null,
            'created_date' => (!empty($cimm_report['submitted_at'])) ? date('Y-m-d', strtotime($cimm_report['submitted_at'])) : date('Y-m-d'),
            'description' => $cimm_report['issue'] ?? '',
            'location' => $cimm_report['location'] ?? '',
            'latitude' => $cimm_report['coord_lat'] ?? null,
            'longitude' => $cimm_report['coord_lng'] ?? null,
            'created_at' => $cimm_report['submitted_at'] ?? $now,
            'updated_at' => $now,
            $timestamp_key => $now,
            'approved_at' => null,
        ];

        $fields = array_keys($insert_fields);
        $placeholders = array_fill(0, count($fields), '?');
        $field_list = '`' . implode('`, `', $fields) . '`';
        $placeholder_list = implode(', ', $placeholders);

        $conn->begin_transaction();
        $insert = "INSERT INTO road_transportation_reports_archive ($field_list) VALUES ($placeholder_list)";
        $stmt = $conn->prepare($insert);
        $stmt->execute(array_values($insert_fields));
        $conn->commit();
        return true;
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollback();
        error_log("rgmap_archive_copy_cimm_report failed: " . $e->getMessage());
        return false;
    }
}

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
