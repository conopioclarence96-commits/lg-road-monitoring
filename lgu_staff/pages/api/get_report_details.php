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
    // Check if estimation column exists
    $estimation_column_exists = false;
    $table = ($report_type === 'transportation') ? 'road_transportation_reports' : 'road_maintenance_reports';
    $result = $conn->query("SHOW COLUMNS FROM {$table} LIKE 'estimation'");
    if ($result && $result->num_rows > 0) {
        $estimation_column_exists = true;
    }
    
    if ($report_type === 'transportation') {
        alert($estimation_column_exists);
        if ($estimation_column_exists) {
            $query = "SELECT id, report_id, title, description, location, latitude, longitude, priority, status, 
                             assigned_to, estimation, resolution_notes as notes, reporter_name, reporter_email, 
                             department, created_date, created_at, updated_at 
                      FROM road_transportation_reports WHERE id = ?";
        } else {
            $query = "SELECT id, report_id, title, description, location, latitude, longitude, priority, status, 
                             assigned_to, resolution_notes as notes, reporter_name, reporter_email, 
                             department, created_date, created_at, updated_at 
                      FROM road_transportation_reports WHERE id = ?";
        }
    } else {
        if ($estimation_column_exists) {
            $query = "SELECT id, report_id, title, description, location, priority, status, estimation,
                             maintenance_team as assigned_to, created_at, updated_at,
                             '' as notes, '' as reporter_name, '' as reporter_email,
                             department, created_date, 0 as latitude, 0 as longitude
                      FROM road_maintenance_reports WHERE id = ?";
        } else {
            $query = "SELECT id, report_id, title, description, location, priority, status,
                             maintenance_team as assigned_to, created_at, updated_at,
                             '' as notes, '' as reporter_name, '' as reporter_email,
                             department, created_date, 0 as latitude, 0 as longitude
                      FROM road_maintenance_reports WHERE id = ?";
        }
    }
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $report_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($report = $result->fetch_assoc()) {
        // Add default estimation if column doesn't exist
        if (!isset($report['estimation'])) {
            $report['estimation'] = 0;
        }
        
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
