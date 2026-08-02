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

try {
    $assignments = [];
    $stmt = $conn->prepare("SELECT ra.*, u.full_name, u.email, u.role 
                           FROM report_assignments ra 
                           JOIN users u ON ra.user_id = u.id 
                           WHERE ra.report_id = ? AND ra.report_type = ? AND ra.status = 'active'
                           ORDER BY ra.assigned_at DESC");
    $stmt->bind_param("is", $report_id, $report_type);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $assignments[] = $row;
    }
    
    json_response(['success' => true, 'assignments' => $assignments]);
} catch (Exception $e) {
    error_log("Get assigned users error: " . $e->getMessage());
    json_response(['success' => false, 'message' => 'Failed to load assigned users']);
}
