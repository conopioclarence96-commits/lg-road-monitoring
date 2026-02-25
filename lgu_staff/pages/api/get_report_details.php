<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

// Check if user is logged in
if (!is_logged_in()) {
    json_response(['success' => false, 'error' => 'Unauthorized - Please log in again'], 401);
}

$report_id = intval($_GET['id'] ?? 0);
$report_type = sanitize_input($_GET['type'] ?? '');

if ($report_id <= 0 || empty($report_type)) {
    json_response(['success' => false, 'error' => 'Invalid report parameters']);
}

$table = ($report_type === 'transportation') ? 'road_transportation_reports' : 'road_maintenance_reports';

try {
    if ($report_type === 'transportation') {
        $query = "SELECT id, title, description, location, latitude, longitude, priority, status, 
                         assigned_to, estimation, resolution_notes as notes, reporter_name, reporter_email, 
                         created_at, updated_at 
                  FROM road_transportation_reports WHERE id = ?";
    } else {
        $query = "SELECT id, title, description, location, priority, status, estimation,
                         maintenance_team as assigned_to, created_at, updated_at,
                         '' as notes, '' as reporter_name, '' as reporter_email,
                         0 as latitude, 0 as longitude
                  FROM road_maintenance_reports WHERE id = ?";
    }
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $report_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($report = $result->fetch_assoc()) {
        // Format dates
        $report['created_at'] = format_datetime($report['created_at']);
        $report['updated_at'] = $report['updated_at'] ? format_datetime($report['updated_at']) : null;
        
        json_response([
            'success' => true,
            'report' => $report
        ]);
    } else {
        json_response(['success' => false, 'error' => 'Report not found']);
    }
} catch (Exception $e) {
    json_response(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
