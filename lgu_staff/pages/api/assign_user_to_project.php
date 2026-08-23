<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => 'Unauthorized'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

$report_id = intval($_POST['report_id'] ?? 0);
$report_type = sanitize_input($_POST['report_type'] ?? '');
$user_id = intval($_POST['user_id'] ?? 0);
$notes = sanitize_input($_POST['notes'] ?? '');

error_log("Assign user debug: report_id=$report_id, report_type=$report_type, user_id=$user_id");

if ($report_id <= 0 || empty($report_type) || $user_id <= 0) {
    json_response(['success' => false, 'message' => 'Invalid parameters']);
}

// Verify user exists
$user = fetch_one("SELECT id, full_name, role FROM users WHERE id = ?", [$user_id], "i");
if (!$user) {
    json_response(['success' => false, 'message' => 'User not found']);
}

// Verify report exists
$report_exists = false;
if ($report_type === 'road_transportation_reports') {
    $report_exists = fetch_one("SELECT id FROM road_transportation_reports WHERE id = ?", [$report_id], "i");
} elseif ($report_type === 'road_maintenance_reports') {
    $report_exists = fetch_one("SELECT id FROM road_maintenance_reports WHERE id = ?", [$report_id], "i");
} elseif ($report_type === 'cimm_verification_reports') {
    $report_exists = fetch_one("SELECT id FROM cimm_verification_reports WHERE id = ?", [$report_id], "i");
} elseif ($report_type === 'ipms_road_projects') {
    $report_exists = fetch_one("SELECT project_id FROM ipms_road_projects WHERE project_id = ?", [$report_id], "i");
}

if (!$report_exists) {
    json_response(['success' => false, 'message' => 'Report not found']);
}

// Check if already assigned
$existing = fetch_one("SELECT id FROM report_assignments WHERE report_id = ? AND report_type = ? AND user_id = ? AND status = 'active'", 
    [$report_id, $report_type, $user_id], "isi");
if ($existing) {
    json_response(['success' => false, 'message' => 'User is already assigned to this project']);
}

try {
    // Check if report_assignments table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'report_assignments'");
    if ($table_check->num_rows === 0) {
        error_log("report_assignments table does not exist");
        json_response(['success' => false, 'message' => 'Database table report_assignments does not exist. Please run the SQL file to create it.']);
    }

    // Insert assignment
    $stmt = $conn->prepare("INSERT INTO report_assignments (report_id, report_type, user_id, assigned_by, notes) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("isiis", $report_id, $report_type, $user_id, $_SESSION['user_id'], $notes);
    $stmt->execute();
    $assignment_id = $conn->insert_id;

    // Road Monitoring Officers: file a targeted assignment notice so only the
    // assigned officer is notified. Other roles keep always-on assignment cards.
    if (($user['role'] ?? '') === 'road_monitoring_officer') {
        try {
            $officer = fetch_one("SELECT email FROM users WHERE id = ?", [$user_id], "i");
            $officer_email = trim((string)($officer['email'] ?? ''));
            if ($officer_email !== '') {
                $msg = 'You have been assigned a new report.';
                if ($notes !== '') {
                    $msg .= ' Notes: ' . $notes;
                }
                $nstmt = $conn->prepare(
                    "INSERT INTO report_notifications (report_id, type, message, recipient_email, recipient_role)
                     VALUES (?, 'project_assignment', ?, ?, 'road_monitoring_officer')"
                );
                $nstmt->bind_param("iss", $report_id, $msg, $officer_email);
                $nstmt->execute();
                $nstmt->close();
            }
        } catch (Exception $e) {
            error_log('Assignment notification error: ' . $e->getMessage());
        }
    }
    
    // Audit log
    log_audit_action($_SESSION['user_id'], "Assigned user to project", "Report ID: {$report_id}, User ID: {$user_id}, Assignment ID: {$assignment_id}");
    
    json_response(['success' => true, 'message' => 'User assigned successfully', 'assignment_id' => $assignment_id]);
} catch (Exception $e) {
    error_log("Assign user to project error: " . $e->getMessage());
    json_response(['success' => false, 'message' => 'Failed to assign user: ' . $e->getMessage()], 500);
}
