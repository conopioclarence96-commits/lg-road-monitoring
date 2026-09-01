<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => 'Unauthorized'], 401);
}

$report_id = intval($_GET['report_id'] ?? 0);
$report_type = sanitize_input($_GET['report_type'] ?? '');

if ($report_id <= 0 || empty($report_type)) {
    json_response(['success' => false, 'message' => 'Invalid parameters']);
}

// Determine the report category based on report_type and report_id
$report_category = '';

if ($report_type === 'road_transportation_reports') {
    $stmt = $conn->prepare("SELECT report_category FROM road_transportation_reports WHERE id = ?");
    $stmt->bind_param("i", $report_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $report_category = $row['report_category'];
    }
    error_log("Debug get_assignable_users: report_id=$report_id, report_type=$report_type, report_category='$report_category'");
} elseif ($report_type === 'road_maintenance_reports') {
    $report_category = 'road';
    error_log("Debug get_assignable_users: report_id=$report_id, report_type=$report_type, report_category='$report_category' (maintenance)");
} elseif ($report_type === 'cimm_verification_reports') {
    $report_category = 'road';
    error_log("Debug get_assignable_users: report_id=$report_id, report_type=$report_type, report_category='$report_category' (cimm)");
} elseif ($report_type === 'ipms_road_projects') {
    $report_category = 'road';
    error_log("Debug get_assignable_users: report_id=$report_id, report_type=$report_type, report_category='$report_category' (ipms)");
} else {
    error_log("Debug get_assignable_users: report_id=$report_id, report_type=$report_type, report_category='' (unknown type)");
}

// If report_category is empty, return error
if (empty($report_category)) {
    json_response(['success' => false, 'message' => 'Report category not found in database']);
}

// Determine target role based on report category
if ($report_category === 'road') {
    $target_role = 'road_monitoring_officer';
} elseif ($report_category === 'transportation') {
    $target_role = 'trans_monitoring_officer';
} else {
    json_response(['success' => false, 'message' => 'Unknown report category: ' . $report_category]);
}

// Get users with the target role
$users = [];
try {
    $stmt = $conn->prepare("SELECT id, full_name, email, role, profile_picture FROM users WHERE role = ? ORDER BY full_name ASC");
    $stmt->bind_param("s", $target_role);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        // Active count = reports still assigned to this user that would appear
        // on Active Monitoring (road_transportation_monitoring.php), i.e.
        // assignment is active AND the linked report is still live/in progress.
        // Recomputed on every request so it tracks status changes automatically.
        $active_count = 0;
        try {
            $count_stmt = $conn->prepare("
                SELECT COUNT(*) AS active_count
                FROM report_assignments ra
                LEFT JOIN road_transportation_reports r
                    ON ra.report_id = r.id AND ra.report_type = 'road_transportation_reports'
                LEFT JOIN cimm_verification_reports c
                    ON ra.report_id = c.id AND ra.report_type = 'cimm_verification_reports'
                LEFT JOIN ipms_road_projects ip
                    ON ra.report_id = ip.project_id AND ra.report_type = 'ipms_road_projects'
                WHERE ra.user_id = ?
                  AND ra.status = 'active'
                  AND (
                    (
                        ra.report_type = 'road_transportation_reports'
                        AND r.id IS NOT NULL
                        AND LOWER(REPLACE(TRIM(r.status), ' ', '-')) IN ('approved', 'in-progress')
                    )
                    OR (
                        ra.report_type = 'cimm_verification_reports'
                        AND c.id IS NOT NULL
                        AND c.infrastructure = 'Roads'
                        AND c.verification_status IN ('Approved', 'In Progress')
                    )
                    OR (
                        ra.report_type = 'ipms_road_projects'
                        AND ip.project_id IS NOT NULL
                        AND LOWER(TRIM(ip.status)) = 'approved'
                    )
                  )
            ");
            $count_stmt->bind_param("i", $row['id']);
            $count_stmt->execute();
            $count_result = $count_stmt->get_result();
            $count_row = $count_result->fetch_assoc();
            $active_count = (int)($count_row['active_count'] ?? 0);
            $count_stmt->close();
        } catch (Exception $e) {
            error_log('get_assignable_users active_count error: ' . $e->getMessage());
            $active_count = 0;
        }
        $row['active_assignments'] = $active_count;
        
        // Check if already assigned to this project
        $already_assigned = false;
        try {
            $assigned_stmt = $conn->prepare("SELECT id FROM report_assignments WHERE report_id = ? AND report_type = ? AND user_id = ? AND status = 'active'");
            $assigned_stmt->bind_param("isi", $report_id, $report_type, $row['id']);
            $assigned_stmt->execute();
            $assigned_result = $assigned_stmt->get_result();
            $already_assigned = $assigned_result->num_rows > 0;
        } catch (Exception $e) {
            // Table might not exist yet, default to false
            $already_assigned = false;
        }
        $row['already_assigned'] = $already_assigned;

        // Same profile_picture storage/display as settings.php (users.profile_picture).
        $profile_picture = trim((string)($row['profile_picture'] ?? ''));
        $row['profile_picture_url'] = '';
        if ($profile_picture !== ''
            && strpos($profile_picture, '..') === false
            && strpos($profile_picture, '/') === false
            && strpos($profile_picture, '\\') === false) {
            $profile_fs = __DIR__ . '/../../uploads/profile_pictures/' . $profile_picture;
            if (is_file($profile_fs)) {
                $row['profile_picture_url'] = '../../uploads/profile_pictures/' . rawurlencode($profile_picture);
            }
        }
        unset($row['profile_picture']);
        
        $users[] = $row;
    }
    
    json_response(['success' => true, 'users' => $users, 'target_role' => $target_role, 'report_category' => $report_category]);
} catch (Exception $e) {
    error_log("Get assignable users error: " . $e->getMessage());
    json_response(['success' => false, 'message' => 'Failed to load users']);
}
