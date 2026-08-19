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
    $stmt = $conn->prepare("SELECT id, full_name, email, role FROM users WHERE role = ? ORDER BY full_name ASC");
    $stmt->bind_param("s", $target_role);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        // Get count of active assignments for this user
        // Only count currently active/in-progress assignments: the report must
        // still exist in its live table (not archived) and not be in a terminal
        // state (completed / cancelled / rejected).
        $active_count = 0;
        try {
            $count_stmt = $conn->prepare("
                SELECT COUNT(*) AS active_count
                FROM report_assignments ra
                LEFT JOIN road_transportation_reports r
                    ON ra.report_id = r.id AND ra.report_type = 'road_transportation_reports'
                LEFT JOIN cimm_verification_reports c
                    ON ra.report_id = c.id AND ra.report_type = 'cimm_verification_reports'
                LEFT JOIN road_maintenance_reports m
                    ON ra.report_id = m.id AND ra.report_type = 'road_maintenance_reports'
                LEFT JOIN ipms_road_projects ip
                    ON ra.report_id = ip.project_id AND ra.report_type = 'ipms_road_projects'
                WHERE ra.user_id = ? AND ra.status = 'active'
                  AND (
                    (ra.report_type = 'road_transportation_reports'
                        AND r.id IS NOT NULL
                        AND r.status NOT IN ('completed','cancelled','rejected'))
                    OR (ra.report_type = 'cimm_verification_reports'
                        AND c.id IS NOT NULL
                        AND c.approval_status <> 'Rejected'
                        AND COALESCE(c.resolution_status,'') NOT IN ('Completed','Cancelled','Rejected'))
                    OR (ra.report_type = 'road_maintenance_reports'
                        AND m.id IS NOT NULL
                        AND m.status NOT IN ('completed','cancelled','rejected'))
                    OR (ra.report_type = 'ipms_road_projects'
                        AND ip.project_id IS NOT NULL
                        AND COALESCE(ip.status,'') NOT IN ('completed','cancelled','rejected'))
                  )
            ");
            $count_stmt->bind_param("i", $row['id']);
            $count_stmt->execute();
            $count_result = $count_stmt->get_result();
            $count_row = $count_result->fetch_assoc();
            $active_count = (int)($count_row['active_count'] ?? 0);
        } catch (Exception $e) {
            // Table might not exist yet, default to 0
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
        
        $users[] = $row;
    }
    
    json_response(['success' => true, 'users' => $users, 'target_role' => $target_role, 'report_category' => $report_category]);
} catch (Exception $e) {
    error_log("Get assignable users error: " . $e->getMessage());
    json_response(['success' => false, 'message' => 'Failed to load users']);
}
