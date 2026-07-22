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

$transport_types = ['transportation', 'infrastructure_issue', 'traffic_jam', 'accident', 'road_closure', 'potholes', 'road_damage'];
$table = in_array($report_type, $transport_types) ? 'road_transportation_reports' : 'road_maintenance_reports';

try {
    // Check if estimation column exists
    $estimation_column_exists = false;
    $result = $conn->query("SHOW COLUMNS FROM {$table} LIKE 'estimation'");
    if ($result && $result->num_rows > 0) {
        $estimation_column_exists = true;
    }

    // Check if approved_at and rejected_at columns exist
    $approved_at_exists = false;
    $rejected_at_exists = false;
    $check_approved = $conn->query("SHOW COLUMNS FROM {$table} LIKE 'approved_at'");
    if ($check_approved && $check_approved->num_rows > 0) {
        $approved_at_exists = true;
    }
    $check_rejected = $conn->query("SHOW COLUMNS FROM {$table} LIKE 'rejected_at'");
    if ($check_rejected && $check_rejected->num_rows > 0) {
        $rejected_at_exists = true;
    }
    
    $extra_cols = '';
    if ($approved_at_exists) $extra_cols .= ', approved_at';
    if ($rejected_at_exists) $extra_cols .= ', rejected_at';
    
    if ($table === 'road_transportation_reports') {
        $query = "SELECT id, report_id, report_type, title, department, priority, status, created_date, due_date, description,
                    location, latitude, longitude, reporter_name, reporter_email, severity, reported_date, resolved_date, assigned_to,
                    resolution_notes as notes, estimation, attachments, created_by, created_at, updated_at, image_path 
                    {$extra_cols}
                    FROM road_transportation_reports WHERE id = ?";
        
    } else {
        if ($estimation_column_exists) {
            $query = "SELECT id, report_id, title, description, location, priority, status, estimation,
                             maintenance_team as assigned_to, created_at, updated_at,
                             '' as notes, '' as reporter_name, '' as reporter_email,
                             department, created_date, 0 as latitude, 0 as longitude
                             {$extra_cols}
                      FROM road_maintenance_reports WHERE id = ?";
        } else {
            $query = "SELECT id, report_id, title, description, location, priority, status,
                             maintenance_team as assigned_to, created_at, updated_at,
                             '' as notes, '' as reporter_name, '' as reporter_email,
                             department, created_date, 0 as latitude, 0 as longitude
                             {$extra_cols}
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
        $report['created_at'] = isset($report['created_at']) ? format_datetime($report['created_at']) : null;
        $report['updated_at'] = $report['updated_at'] ? format_datetime($report['updated_at']) : null;
        $report['approved_at'] = isset($report['approved_at']) && $report['approved_at'] ? format_datetime($report['approved_at']) : null;
        $report['rejected_at'] = isset($report['rejected_at']) && $report['rejected_at'] ? format_datetime($report['rejected_at']) : null;

        // Gather photos from report_update_media (progress updates)
        try {
            $media_stmt = $conn->prepare(
                "SELECT rum.file_path, rum.file_type
                 FROM report_update_media rum
                 INNER JOIN report_updates ru ON rum.update_id = ru.id
                 WHERE ru.report_id = ?
                 ORDER BY rum.id ASC"
            );
            $media_stmt->bind_param("i", $report_id);
            $media_stmt->execute();
            $media_result = $media_stmt->get_result();
            $update_media = [];
            while ($m = $media_result->fetch_assoc()) {
                $update_media[] = $m;
            }
            $report['update_media'] = $update_media;
        } catch (Exception $e) {
            $report['update_media'] = [];
        }
        
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
