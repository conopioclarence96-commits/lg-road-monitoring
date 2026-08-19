<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

// Archive helpers (rgmap_archive_report, rgmap_archive_cimm_report,
// rgmap_auto_archive_completed, ...) — shared with
// road_transportation_monitoring.php so the portal can run the 7-day
// auto-archive sweep on page load.
require_once __DIR__ . '/progress_archive_helpers.php';

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => 'Unauthorized'], 401);
}

$user_id = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

/**
 * Resolve which live table a progress-update report_id belongs to.
 * Prefer explicit $source so the same numeric id in transport / IPMS / CIMM
 * does not collide (first-match would pick the wrong row).
 *
 * @return array{table:string,id_column:string}|null
 */
function rgmap_progress_resolve_table(string $source = ''): ?array {
    $source = strtolower(trim($source));
    if ($source === 'cimm' || $source === 'external') {
        return ['table' => 'cimm_verification_reports', 'id_column' => 'id'];
    }
    if ($source === 'infrastructure' || $source === 'maintenance' || $source === 'ipms') {
        return ['table' => 'ipms_road_projects', 'id_column' => 'project_id'];
    }
    if ($source === '') {
        return null;
    }
    return ['table' => 'road_transportation_reports', 'id_column' => 'id'];
}

function rgmap_progress_is_ipms_source(string $source): bool {
    $resolved = rgmap_progress_resolve_table($source);
    return ($resolved['table'] ?? '') === 'ipms_road_projects';
}

/**
 * Confirm the report exists. When $source is set, only that table is checked.
 * When empty, probe transport → IPMS → CIMM (no road_maintenance_reports).
 */
function rgmap_progress_find_report($conn, int $report_id, string $source = ''): ?array {
    $resolved = rgmap_progress_resolve_table($source);
    $probe = [];
    if ($resolved) {
        $probe[] = $resolved['table'];
    } else {
        $probe = ['road_transportation_reports', 'ipms_road_projects', 'cimm_verification_reports'];
    }

    foreach ($probe as $table) {
        if ($table === 'ipms_road_projects') {
            $row = fetch_one(
                "SELECT project_id AS id, CAST(project_id AS CHAR) AS report_id FROM ipms_road_projects WHERE project_id = ?",
                [$report_id],
                'i'
            );
        } elseif ($table === 'cimm_verification_reports') {
            $row = fetch_one(
                "SELECT id, reference_code AS report_id FROM cimm_verification_reports WHERE id = ?",
                [$report_id],
                'i'
            );
        } else {
            $row = fetch_one(
                "SELECT id, report_id FROM road_transportation_reports WHERE id = ?",
                [$report_id],
                'i'
            );
        }
        if ($row) {
            $row['_source_table'] = $table;
            return $row;
        }
    }
    return null;
}

/**
 * Role-based assignment restriction for posting progress updates.
 *
 * When a staff member is assigned to a report via the "Assign Staff" feature,
 * only that assigned user may post progress updates IF they hold the Road
 * Monitoring Officer or Transportation Monitoring Officer role. Users holding
 * either of those roles may ONLY post to reports they are actively assigned
 * to — an unassigned officer is blocked even if the report has no assignment
 * at all. For all other roles the existing behavior is kept: any authorized
 * user may post regardless of assignment.
 *
 * Used by both report_management.php and road_transportation_monitoring.php
 * (they share this endpoint for posting), and by the can_post_update action
 * that drives the UI button visibility.
 */
function rgmap_can_post_progress_update($conn, $report_id, $source, $user_id, $current_role) {
    // Restriction applies only to Road/Transportation Monitoring Officers.
    if (!in_array($current_role, ['road_monitoring_officer', 'trans_monitoring_officer'], true)) {
        return true;
    }

    $source = strtolower(trim($source));
    $resolved = rgmap_progress_resolve_table($source !== '' ? $source : 'lgu');
    $table = $resolved['table'] ?? 'road_transportation_reports';
    if ($table === 'ipms_road_projects') {
        // IPMS projects are not assigned via report_assignments today.
        return true;
    }

    try {
        $check = $conn->query("SHOW TABLES LIKE 'report_assignments'");
        if (!$check || $check->num_rows === 0) {
            return true;
        }

        // An officer may post ONLY when they are the actively assigned user.
        $assigned = fetch_one(
            "SELECT id FROM report_assignments WHERE report_id = ? AND report_type = ? AND user_id = ? AND status = 'active'",
            [$report_id, $table, $user_id], "isi"
        );
        return (bool)$assigned;
    } catch (Exception $e) {
        error_log("can_post_progress_update error: " . $e->getMessage());
        return true;
    }
}

/**
 * Role-based assignment restriction for submitting completion/cancellation
 * requests. Road/Transportation Monitoring Officers may ONLY request
 * completion or cancellation for reports they are actively assigned to (via
 * report_assignments). All other roles are unaffected because they use the
 * direct Complete/Cancel path, not the review-request flow.
 *
 * Used by the can_request_review GET action that drives the UI button
 * visibility, and by the submit_review_request POST handler as the
 * authoritative backend check.
 *
 * Officers are fail-closed: if the report cannot be resolved or the
 * report_assignments table is missing, the request is denied.
 */
function rgmap_can_request_review($conn, $report_id, $source, $user_id, $current_role) {
    // Restriction applies only to Road/Transportation Monitoring Officers.
    if (!in_array($current_role, ['road_monitoring_officer', 'trans_monitoring_officer'], true)) {
        return true;
    }

    try {
        $check = $conn->query("SHOW TABLES LIKE 'report_assignments'");
        if (!$check || $check->num_rows === 0) {
            return false;
        }

        $source = strtolower(trim($source));
        $table = $source === 'cimm'
            ? 'cimm_verification_reports'
            : 'road_transportation_reports';
        if (!$table) {
            return false;
        }

        // An officer may request ONLY when they are the actively assigned user.
        $assigned = fetch_one(
            "SELECT id FROM report_assignments WHERE report_id = ? AND report_type = ? AND user_id = ? AND status = 'active'",
            [$report_id, $table, $user_id], "isi"
        );
        return (bool)$assigned;
    } catch (Exception $e) {
        error_log("can_request_review error: " . $e->getMessage());
        return false;
    }
}

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'get_updates' || $action === 'get_update' || $action === 'can_post_update' || $action === 'can_request_review' || $action === 'can_complete_report') {
        if ($action === 'can_post_update') {
            $report_id = intval($_GET['report_id'] ?? 0);
            $source = sanitize_input($_GET['source'] ?? '');
            if ($report_id <= 0) {
                json_response(['success' => false, 'message' => 'Invalid report ID']);
            }
            json_response([
                'success'  => true,
                'can_post' => rgmap_can_post_progress_update($conn, $report_id, $source, $user_id, $_SESSION['role'] ?? ''),
            ]);
        }
        if ($action === 'can_request_review') {
            $report_id = intval($_GET['report_id'] ?? 0);
            $source = sanitize_input($_GET['source'] ?? '');
            if ($report_id <= 0) {
                json_response(['success' => false, 'message' => 'Invalid report ID']);
            }
            json_response([
                'success'     => true,
                'can_request' => rgmap_can_request_review($conn, $report_id, $source, $user_id, $_SESSION['role'] ?? ''),
            ]);
        }

        // Road Operations Supervisor completion gate (report_management.php
        // Updates modal -> Complete button). For this role only, a report may
        // NOT be marked Completed when there is no active officer assignment
        // for it AND no progress update has been added yet — i.e. no work has
        // been claimed or recorded against the report. All other roles are
        // unaffected and resolve to allowed (true).
        if ($action === 'can_complete_report') {
            $report_id = intval($_GET['report_id'] ?? 0);
            $source = sanitize_input($_GET['source'] ?? '');
            if ($report_id <= 0) {
                json_response(['success' => false, 'message' => 'Invalid report ID']);
            }

            $source_lower = strtolower(trim($source));
            $table = ($source_lower === 'cimm') ? 'cimm_verification_reports' : 'road_transportation_reports';

            $has_assignment = false;
            $has_updates = false;

            try {
                $check = $conn->query("SHOW TABLES LIKE 'report_assignments'");
                if ($check && $check->num_rows > 0 && $table) {
                    $assigned = fetch_one(
                        "SELECT id FROM report_assignments WHERE report_id = ? AND report_type = ? AND status = 'active'",
                        [$report_id, $table], "is"
                    );
                    $has_assignment = (bool)$assigned;
                }
            } catch (Exception $e) {
                error_log("can_complete_report assignment check error: " . $e->getMessage());
            }

            try {
                $upd = fetch_one("SELECT id FROM report_updates WHERE report_id = ? LIMIT 1", [$report_id], "i");
                $has_updates = (bool)$upd;
            } catch (Exception $e) {
                error_log("can_complete_report updates check error: " . $e->getMessage());
            }

            $current_role = $_SESSION['role'] ?? '';
            if ($current_role === 'road_ops_supervisor') {
                $can_complete = $has_assignment || $has_updates;
                $message = $can_complete
                    ? ''
                    : 'Complete blocked: assign an officer to this report or add a progress update first.';
            } else {
                $can_complete = true;
                $message = '';
            }

            json_response([
                'success'       => true,
                'can_complete'  => $can_complete,
                'has_assignment'=> $has_assignment,
                'has_updates'   => $has_updates,
                'message'       => $message,
            ]);
        }

        // Allow read-only access without login for public timeline
        if ($action === 'get_updates') {
            $report_id = intval($_GET['report_id'] ?? 0);
            $source = sanitize_input($_GET['source'] ?? '');
            if ($report_id <= 0) {
                json_response(['success' => false, 'message' => 'Invalid report ID']);
            }
            // Prefer source so id collisions across transport / IPMS / CIMM
            // do not resolve to the wrong report.
            $report = rgmap_progress_find_report($conn, $report_id, $source);
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
        $source = sanitize_input($_POST['source'] ?? '');
        $title = sanitize_input($_POST['title'] ?? '');
        $description = sanitize_input($_POST['description'] ?? '');

        if ($report_id <= 0) json_response(['success' => false, 'message' => 'Invalid report ID']);
        if (empty($description)) json_response(['success' => false, 'message' => 'Description is required']);

        $report = null;
        $report_table = 'road_transportation_reports';
        if ($source === 'cimm' || $source === 'external') {
            $report = fetch_one("SELECT id, reference_code AS report_id FROM cimm_verification_reports WHERE id = ?", [$report_id], "i");
            $report_table = 'cimm_verification_reports';
        } elseif ($source === 'infrastructure' || $source === 'maintenance' || $source === 'ipms') {
            $report = fetch_one(
                "SELECT project_id AS id, CAST(project_id AS CHAR) AS report_id FROM ipms_road_projects WHERE project_id = ?",
                [$report_id],
                "i"
            );
            $report_table = 'ipms_road_projects';
        } else {
            $report = fetch_one("SELECT id, report_id FROM road_transportation_reports WHERE id = ?", [$report_id], "i");
            $report_table = 'road_transportation_reports';
        }
        if (!$report) json_response(['success' => false, 'message' => 'Report not found']);

        // The report's status column depends on which table it lives in.
        $status_column = ($report_table === 'cimm_verification_reports') ? 'verification_status' : 'status';
        $status_id_column = ($report_table === 'ipms_road_projects') ? 'project_id' : 'id';

        // Role-based assignment restriction — enforced in the backend so it
        // covers report_management.php and road_transportation_monitoring.php
        // alike (both post progress updates through this endpoint).
        if (!rgmap_can_post_progress_update($conn, $report_id, $source, $user_id, $_SESSION['role'] ?? '')) {
            json_response(['success' => false, 'message' => 'You can only post progress updates to reports assigned to you.'], 403);
        }

        try {
            // Insert update
            $stmt = $conn->prepare("INSERT INTO report_updates (report_id, user_id, title, description) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiss", $report_id, $user_id, $title, $description);
            $stmt->execute();
            $update_id = $conn->insert_id;

            // Automatically advance an Approved report to In Progress once an
            // update is added. Skip IPMS projects — their local workflow
            // status stays approved/rejected/completed and is not driven by
            // progress posts the same way.
            if ($report_table !== 'ipms_road_projects') {
                $cur = $conn->query("SELECT `{$status_column}` AS st FROM `{$report_table}` WHERE `{$status_id_column}` = {$report_id}")->fetch_assoc();
                $current_status = strtolower((string)($cur['st'] ?? ''));
                if ($current_status === 'approved') {
                    $target_status = ($report_table === 'cimm_verification_reports') ? 'In Progress' : 'in-progress';
                    $s_stmt = $conn->prepare("UPDATE `{$report_table}` SET `{$status_column}` = ? WHERE `{$status_id_column}` = ?");
                    $s_stmt->bind_param("si", $target_status, $report_id);
                    $s_stmt->execute();
                }
            }

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
                // Update cimm_verification_reports table. When the status is set
                // to Completed (the report_management.php Complete flow), stamp
                // the same 7-day auto-archive marker used by the supervisor
                // portal's Complete button (complete_status) so the existing
                // sweep (rgmap_auto_archive_completed) moves the report to the
                // archive after 7 days. CIMM reports have no completed_at
                // column, so auto_archive_at doubles as the completion marker.
                rgmap_ensure_auto_archive_column();
                if (strtolower($status) === 'completed') {
                    $stmt = $conn->prepare("UPDATE cimm_verification_reports SET verification_status = ?, auto_archive_at = COALESCE(auto_archive_at, DATE_ADD(NOW(), INTERVAL 7 DAY)) WHERE id = ?");
                    $stmt->bind_param("si", $status, $report_id);
                } else {
                    $stmt = $conn->prepare("UPDATE cimm_verification_reports SET verification_status = ? WHERE id = ?");
                    $stmt->bind_param("si", $status, $report_id);
                }
                $stmt->execute();
                log_audit_action($user_id, "Updated CIMM report status", "Report ID: {$report_id}, Status: {$status}");
                json_response(['success' => true, 'message' => 'Status updated successfully']);
            } else {
                // Update road_transportation_reports table. When the status is
                // set to completed (the report_management.php Complete flow),
                // stamp the same 7-day auto-archive marker used by the
                // supervisor portal's Complete button (complete_status) so the
                // existing sweep (rgmap_auto_archive_completed) moves the
                // report to the archive after 7 days.
                rgmap_ensure_auto_archive_column();
                if (strtolower($status) === 'completed') {
                    $stmt = $conn->prepare("UPDATE road_transportation_reports SET status = ?, completed_at = COALESCE(completed_at, NOW()), auto_archive_at = COALESCE(auto_archive_at, DATE_ADD(NOW(), INTERVAL 7 DAY)) WHERE id = ?");
                    $stmt->bind_param("si", $status, $report_id);
                } else {
                    $stmt = $conn->prepare("UPDATE road_transportation_reports SET status = ? WHERE id = ?");
                    $stmt->bind_param("si", $status, $report_id);
                }
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
            } elseif (rgmap_progress_is_ipms_source($source)) {
                $archived = rgmap_archive_ipms_project($conn, $report_id, 'cancelled');
            } else {
                $table = rgmap_resolve_report_table($conn, $report_id);
                $archived = $table ? rgmap_archive_report($conn, $table, $report_id, 'cancelled') : false;
            }
            if (!$archived) {
                json_response(['success' => false, 'message' => 'Failed to cancel and archive the report'], 500);
            }
            // Notify the officer who submitted the original review request.
            $report_row = fetch_one("SELECT report_id FROM road_transportation_reports WHERE id = ?", [$report_id], "i");
            if (!$report_row) {
                $report_row = fetch_one("SELECT report_id FROM road_maintenance_reports WHERE id = ?", [$report_id], "i");
            }
            if (!$report_row) {
                $report_row = fetch_one("SELECT reference_code AS report_id FROM cimm_verification_reports WHERE id = ?", [$report_id], "i");
            }
            rgmap_notify_requestor($conn, $report_id, 'cancel', $user_id, $report_row['report_id'] ?? null);
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
            // Notify the officer who submitted the original review request.
            $report_row = fetch_one("SELECT report_id FROM road_transportation_reports WHERE id = ?", [$report_id], "i");
            if (!$report_row) {
                $report_row = fetch_one("SELECT report_id FROM road_maintenance_reports WHERE id = ?", [$report_id], "i");
            }
            if (!$report_row) {
                $report_row = fetch_one("SELECT reference_code AS report_id FROM cimm_verification_reports WHERE id = ?", [$report_id], "i");
            }
            rgmap_notify_requestor($conn, $report_id, 'complete', $user_id, $report_row['report_id'] ?? null);
            json_response(['success' => true, 'message' => 'Report filed in archive as completed']);
        } catch (Exception $e) {
            error_log("Complete archive error: " . $e->getMessage());
            json_response(['success' => false, 'message' => 'Failed to file archive copy: ' . $e->getMessage()], 500);
        }
    } elseif ($action === 'complete_archive_move') {
        // Complete a report AND move it to the archive (road_transportation_monitoring.php).
        // Mirrors cancel_archive: the row is copied into the archive table with
        // status 'completed', then removed from the live table. The live report
        // no longer appears on the monitoring page — it lives in the archive.
        $report_id = intval($_POST['report_id'] ?? 0);
        $source = sanitize_input($_POST['source'] ?? '');

        if ($report_id <= 0) json_response(['success' => false, 'message' => 'Invalid report ID']);

        try {
            if ($source === 'cimm') {
                $archived = rgmap_archive_cimm_report($conn, $report_id, 'completed');
            } else {
                $table = rgmap_resolve_report_table($conn, $report_id);
                $archived = $table ? rgmap_archive_report($conn, $table, $report_id, 'completed') : false;
            }
            if (!$archived) {
                json_response(['success' => false, 'message' => 'Failed to complete and archive the report'], 500);
            }
            // Notify the officer who submitted the original review request.
            $report_row = fetch_one("SELECT report_id FROM road_transportation_reports WHERE id = ?", [$report_id], "i");
            if (!$report_row) {
                $report_row = fetch_one("SELECT report_id FROM road_maintenance_reports WHERE id = ?", [$report_id], "i");
            }
            if (!$report_row) {
                $report_row = fetch_one("SELECT reference_code AS report_id FROM cimm_verification_reports WHERE id = ?", [$report_id], "i");
            }
            rgmap_notify_requestor($conn, $report_id, 'complete', $user_id, $report_row['report_id'] ?? null);
            log_audit_action($user_id, "Completed and archived report", "Report ID: {$report_id}, Status: completed");
            json_response(['success' => true, 'message' => 'Report completed and moved to archive']);
        } catch (Exception $e) {
            error_log("Complete archive move error: " . $e->getMessage());
            json_response(['success' => false, 'message' => 'Failed to complete and archive the report: ' . $e->getMessage()], 500);
        }
    } elseif ($action === 'complete_status') {
        // Supervisor Complete button on road_transportation_monitoring.php.
        // Marks the report completed and keeps it on Completed Projects until
        // Archive is clicked. It does not move the row to the archive.
        $report_id = intval($_POST['report_id'] ?? 0);
        $source = sanitize_input($_POST['source'] ?? '');

        if ($report_id <= 0) json_response(['success' => false, 'message' => 'Invalid report ID']);

        try {
            $ipms_complete = rgmap_progress_is_ipms_source($source);
            if ($source === 'cimm') {
                rgmap_ensure_auto_archive_column();
                $stmt = $conn->prepare("UPDATE cimm_verification_reports SET verification_status = 'Completed', auto_archive_at = COALESCE(auto_archive_at, DATE_ADD(NOW(), INTERVAL 7 DAY)) WHERE id = ?");
                $stmt->bind_param("i", $report_id);
                $stmt->execute();
            } elseif ($ipms_complete) {
                require_once __DIR__ . '/ipms_road_projects_data.php';
                $pdo = rgmap_ipms_pdo();
                $stmt = $pdo->prepare("UPDATE ipms_road_projects SET status = 'completed' WHERE project_id = ?");
                $stmt->execute([$report_id]);
                if ($stmt->rowCount() === 0) {
                    $chk = $pdo->prepare("SELECT project_id FROM ipms_road_projects WHERE project_id = ?");
                    $chk->execute([$report_id]);
                    if (!$chk->fetch()) {
                        json_response(['success' => false, 'message' => 'Report not found'], 404);
                    }
                }
            } else {
                rgmap_ensure_auto_archive_column();
                $table = rgmap_resolve_report_table($conn, $report_id);
                if (!$table) json_response(['success' => false, 'message' => 'Report not found'], 404);
                $stmt = $conn->prepare("UPDATE $table SET status = 'completed', completed_at = COALESCE(completed_at, NOW()), auto_archive_at = COALESCE(auto_archive_at, DATE_ADD(NOW(), INTERVAL 7 DAY)) WHERE id = ?");
                $stmt->bind_param("i", $report_id);
                $stmt->execute();
            }

            // Notify the officer who submitted the original review request.
            $report_row = fetch_one("SELECT report_id FROM road_transportation_reports WHERE id = ?", [$report_id], "i");
            if (!$report_row) {
                $report_row = fetch_one("SELECT report_id FROM road_maintenance_reports WHERE id = ?", [$report_id], "i");
            }
            if (!$report_row) {
                $report_row = fetch_one("SELECT reference_code AS report_id FROM cimm_verification_reports WHERE id = ?", [$report_id], "i");
            }
            if (!$report_row && $ipms_complete) {
                $report_row = ['report_id' => (string)$report_id];
            }
            rgmap_notify_requestor($conn, $report_id, 'complete', $user_id, $report_row['report_id'] ?? null);
            // Notify the acting supervisor so the completion result appears in
            // their notifications feed (notifications.php).
            rgmap_notify_supervisor_action($conn, $report_id, 'complete', $user_id, $report_row['report_id'] ?? null);
            log_audit_action($user_id, "Completed report", "Report ID: {$report_id}, Status: completed");
            $complete_msg = $ipms_complete
                ? 'Infrastructure project marked as completed.'
                : 'Report completed. It will stay in Completed Projects until it is archived.';
            json_response(['success' => true, 'message' => $complete_msg]);
        } catch (Exception $e) {
            error_log("Complete status error: " . $e->getMessage());
            json_response(['success' => false, 'message' => 'Failed to complete the report: ' . $e->getMessage()], 500);
        }
    } elseif ($action === 'archive_report') {
        // Archive button on the Recent Submissions panel of
        // road_transportation_monitoring.php. The button is only rendered for
        // reports whose status is COMPLETED (all other statuses hide it), and
        // it moves the report into road_transportation_reports_archive keeping
        // its current status — the report leaves Completed Projects only when
        // Archive is clicked.
        // Available to any admin/staff role that can access the monitoring page.
        if (!is_admin_or_staff_role($_SESSION['role'] ?? '')) {
            json_response(['success' => false, 'message' => 'You are not authorized to archive reports.'], 403);
        }
        $report_id = intval($_POST['report_id'] ?? 0);
        $source = sanitize_input($_POST['source'] ?? '');

        if ($report_id <= 0) json_response(['success' => false, 'message' => 'Invalid report ID']);

        try {
            if ($source === 'cimm') {
                // CIMM reports store their status in verification_status
                // (title case, e.g. 'Approved'); normalise to the lowercase form
                // the archive page filters on.
                $row = fetch_one("SELECT verification_status AS status FROM cimm_verification_reports WHERE id = ?", [$report_id], "i");
                if (!$row) json_response(['success' => false, 'message' => 'Report not found'], 404);
                $status = strtolower(trim((string)($row['status'] ?? 'approved')));
                $archived = rgmap_archive_cimm_report($conn, $report_id, $status);
            } elseif (rgmap_progress_is_ipms_source($source)) {
                require_once __DIR__ . '/ipms_road_projects_data.php';
                $pdo = rgmap_ipms_pdo();
                $chk = $pdo->prepare("SELECT status FROM ipms_road_projects WHERE project_id = ?");
                $chk->execute([$report_id]);
                $ipms_row = $chk->fetch(PDO::FETCH_ASSOC);
                if (!$ipms_row) json_response(['success' => false, 'message' => 'Report not found'], 404);
                $status = strtolower(trim((string)($ipms_row['status'] ?? 'completed')));
                $archived = rgmap_archive_ipms_project($conn, $report_id, $status);
            } else {
                $table = rgmap_resolve_report_table($conn, $report_id);
                if (!$table) json_response(['success' => false, 'message' => 'Report not found'], 404);
                $row = fetch_one("SELECT status FROM `$table` WHERE id = ?", [$report_id], "i");
                $status = strtolower(trim((string)($row['status'] ?? 'approved')));
                $archived = rgmap_archive_report($conn, $table, $report_id, $status);
            }
            if (!$archived) {
                json_response(['success' => false, 'message' => 'Failed to archive the report'], 500);
            }
            log_audit_action($user_id, "Archived report", "Report ID: {$report_id}, Status kept: {$status}");
            json_response(['success' => true, 'message' => 'Report archived. It is no longer listed in Recent Submissions.']);
        } catch (Exception $e) {
            error_log("Archive report error: " . $e->getMessage());
            json_response(['success' => false, 'message' => 'Failed to archive the report: ' . $e->getMessage()], 500);
        }
    } elseif ($action === 'auto_archive_completed') {
        // Automatic archive of completed projects is disabled. They remain on
        // Completed Projects until Archive is clicked.
        json_response(['success' => true, 'archived' => 0, 'message' => 'Automatic archive of completed projects is disabled.']);
    } elseif ($action === 'submit_review_request') {
        // Road/Transportation Monitoring Officers request a completion or
        // cancellation of a project. This ONLY creates a role-targeted
        // notification for the appropriate supervisor (road requests go to
        // Road Operations Supervisors, transportation requests go to
        // Transportation Operations Supervisors). It does NOT change the report
        // status and does NOT archive the report — that happens only after the
        // supervisor reviews the request.
        $report_id = intval($_POST['report_id'] ?? 0);
        $request_type = sanitize_input($_POST['request_type'] ?? '');

        if ($report_id <= 0) json_response(['success' => false, 'message' => 'Invalid report ID']);
        if (!in_array($request_type, ['completion', 'cancellation'], true)) {
            json_response(['success' => false, 'message' => 'Invalid request type']);
        }

        // Only Road/Transportation Monitoring Officers may submit these requests.
        $requestor_role = $_SESSION['role'] ?? '';
        if (!in_array($requestor_role, ['road_monitoring_officer', 'trans_monitoring_officer'], true)) {
            json_response(['success' => false, 'message' => 'You are not authorized to submit completion/cancellation requests.'], 403);
        }

        $source = sanitize_input($_POST['source'] ?? '');

        // Resolve the report from the correct table based on the source hint
        // sent by the frontend.  CIMM reports live in cimm_verification_reports;
        // infrastructure/maintenance reports live in road_maintenance_reports;
        // everything else comes from road_transportation_reports.
        if ($source === 'cimm') {
            require_once __DIR__ . '/cimm_verification_data.php';
            $pdo = rgmap_verification_pdo();
            rgmap_ensure_cimm_verification_table($pdo);
            $stmt = $pdo->prepare("SELECT id, reference_code AS report_id, infrastructure AS title, 'road' AS report_category, location, issue AS description FROM cimm_verification_reports WHERE id = ?");
            $stmt->execute([$report_id]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif ($source === 'infrastructure') {
            $report = fetch_one(
                "SELECT id, report_id, title, description, location, report_category FROM road_maintenance_reports WHERE id = ?",
                [$report_id], "i"
            );
        } else {
            $report = fetch_one(
                "SELECT id, report_id, title, description, location, report_category FROM road_transportation_reports WHERE id = ?",
                [$report_id], "i"
            );
        }
        if (!$report) {
            // Fallback: try all tables in case the source hint was missing.
            $report = fetch_one(
                "SELECT id, report_id, title, description, location, report_category FROM road_transportation_reports WHERE id = ?",
                [$report_id], "i"
            );
            if (!$report) {
                $report = fetch_one(
                    "SELECT id, report_id, title, description, location, report_category FROM road_maintenance_reports WHERE id = ?",
                    [$report_id], "i"
                );
            }
            if (!$report) {
                $pdo = rgmap_verification_pdo();
                rgmap_ensure_cimm_verification_table($pdo);
                $stmt = $pdo->prepare("SELECT id, reference_code AS report_id, infrastructure AS title, 'road' AS report_category, location, issue AS description FROM cimm_verification_reports WHERE id = ?");
                $stmt->execute([$report_id]);
                $report = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }
        if (!$report) {
            json_response(['success' => false, 'message' => 'Report not found']);
        }

        $category = strtolower((string)($report['report_category'] ?? ''));
        if (!in_array($category, ['road', 'transportation'], true)) {
            json_response(['success' => false, 'message' => 'This report does not support completion/cancellation requests.']);
        }

        // Backend validation: an officer may only request for their own module.
        if (($requestor_role === 'road_monitoring_officer' && $category !== 'road') ||
            ($requestor_role === 'trans_monitoring_officer' && $category !== 'transportation')) {
            json_response(['success' => false, 'message' => 'You are not authorized to submit a request for this project.'], 403);
        }

        // Backend enforcement of the assignment restriction: an officer may only
        // request completion/cancellation for a report that is actively assigned
        // to them (report_assignments). This is the authoritative check and must
        // not be bypassed by submitting the request directly, so the frontend
        // button visibility is not the only safeguard.
        if (!rgmap_can_request_review($conn, $report_id, $source, $user_id, $requestor_role)) {
            json_response(['success' => false, 'message' => 'You can only request completion or cancellation for reports assigned to you.'], 403);
        }

        // Route to the supervisor responsible for this module.
        $recipient_role = ($category === 'road') ? 'road_ops_supervisor' : 'trans_ops_supervisor';
        $request_label = ($request_type === 'completion') ? 'Request Completion' : 'Request Cancellation';

        try {
            // Ensure the role-targeting column exists (idempotent).
            $conn->query("ALTER TABLE report_notifications ADD COLUMN IF NOT EXISTS recipient_role VARCHAR(50) DEFAULT NULL AFTER recipient_email");

            $requestor = fetch_one("SELECT full_name FROM users WHERE id = ?", [$user_id], "i");
            $requestor_name = $requestor['full_name'] ?? 'Monitoring Officer';
            $report_code = $report['report_id'] ?? ('#' . $report_id);
            $details = [
                'Report: ' . $report_code,
                'Title: ' . ($report['title'] ?? 'Untitled'),
                'Location: ' . ($report['location'] ?? 'N/A'),
                'Requested by: ' . $requestor_name,
            ];
            $message = "{$request_label} — " . implode(' | ', $details);

            // Road and transportation requests both guard against duplicates: a
            // monitoring officer re-submitting the same completion/cancellation
            // request (double-click, page refresh, or reopening the monitoring
            // page) must not spawn a duplicate notification for the matching
            // supervisor (road_ops_supervisor for road projects,
            // trans_ops_supervisor for transportation projects). The request is
            // uniquely identified by report ID + request type + requesting
            // officer, and is only blocked while the previous request is still
            // pending review (unread).
            $dup = fetch_one(
                "SELECT id FROM report_notifications
                 WHERE report_id = ? AND type = ? AND recipient_role = ? AND recipient_email = ? AND is_read = 0
                 ORDER BY id DESC LIMIT 1",
                [$report_id, $request_type, $recipient_role, $user_id],
                "isss"
            );
            if ($dup) {
                log_audit_action($user_id, "Duplicate {$request_label} blocked", "Report ID: {$report_id}, Category: {$category}");
                json_response(['success' => true, 'message' => "{$request_label} already submitted for review. The report status is unchanged."]);
            }

            // Role-targeted notification (visible only to the matching supervisor
            // role and to administrators).  The requestor's user_id is stored in
            // recipient_email (a dead column for role-targeted notifications) so
            // that when the supervisor processes the request we can look up who
            // submitted it and notify them of the outcome.
            $stmt = $conn->prepare("INSERT INTO report_notifications (report_id, type, message, recipient_role, recipient_email) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issss", $report_id, $request_type, $message, $recipient_role, $user_id);
            $stmt->execute();

            log_audit_action($user_id, "Submitted {$request_label}", "Report ID: {$report_id}, Category: {$category}, Recipient role: {$recipient_role}");

            json_response(['success' => true, 'message' => "{$request_label} submitted for review. The report status is unchanged."]);
        } catch (Exception $e) {
            error_log("Submit review request error: " . $e->getMessage());
            json_response(['success' => false, 'message' => 'Failed to submit request: ' . $e->getMessage()], 500);
        }
    } else {
        json_response(['success' => false, 'message' => 'Unknown action']);
    }
} else {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

// --- Helper functions ---
// Archive helpers (rgmap_archive_ensure_table, rgmap_resolve_report_table,
// rgmap_archive_report, rgmap_archive_cimm_report, rgmap_archive_report_copy,
// rgmap_archive_copy_cimm_report, rgmap_notify_requestor,
// rgmap_ensure_auto_archive_column, rgmap_auto_archive_completed) live in
// progress_archive_helpers.php so both this endpoint and the supervisor
// monitoring portal share the same routines.

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
