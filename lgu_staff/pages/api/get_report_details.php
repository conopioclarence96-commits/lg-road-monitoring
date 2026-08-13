<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

// Re-suppress display_errors after config.php (which re-enables it on localhost)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Check if user is logged in
if (!is_logged_in()) {
    json_response(['success' => false, 'error' => 'Unauthorized - Please log in again'], 401);
}

$report_id = intval($_GET['id'] ?? 0);
$report_type = sanitize_input($_GET['type'] ?? '');

if ($report_id <= 0 || empty($report_type)) {
    json_response(['success' => false, 'error' => 'Invalid report parameters']);
}

$transport_types = ['potholes', 'road_damage', 'debris', 'shoulder_damage', 'traffic_jam', 'accident', 'congestion', 'traffic_light_outage', 'vehicle_breakdown', 'traffic_sign_issue', 'transportation', 'infrastructure_issue', 'road_closure', 'parking_violation', 'public_transport_issue'];
$table = in_array($report_type, $transport_types) ? 'road_transportation_reports' : 'road_maintenance_reports';

// Optional explicit table from the caller. Road Operations Supervisor reports
// (debris / erosion / flooding / ...) live in road_transportation_reports but
// their report_type is not in $transport_types, so the type-based guess alone
// would point at road_maintenance_reports and fail. Honor a validated table
// param whenever the caller knows the real source table.
$explicit_table = sanitize_input($_GET['table'] ?? '');
if (in_array($explicit_table, ['road_transportation_reports', 'road_maintenance_reports', 'cimm_verification_reports'], true)) {
    $table = $explicit_table;
}

try {
    // Check if estimation column exists
    $estimation_column_exists = false;
    $result = $conn->query("SHOW COLUMNS FROM {$table} LIKE 'estimation'");
    if ($result && $result->num_rows > 0) {
        $estimation_column_exists = true;
    }
    
    // Check if engineer and budget_allocation columns exist
    $engineer_column_exists = false;
    $budget_column_exists = false;
    $check_eng = $conn->query("SHOW COLUMNS FROM {$table} LIKE 'engineer'");
    if ($check_eng && $check_eng->num_rows > 0) {
        $engineer_column_exists = true;
    }
    $check_bud = $conn->query("SHOW COLUMNS FROM {$table} LIKE 'budget_allocation'");
    if ($check_bud && $check_bud->num_rows > 0) {
        $budget_column_exists = true;
    }
    
    $cimm_extra_cols = '';
    if ($engineer_column_exists) $cimm_extra_cols .= ', engineer';
    if ($budget_column_exists) $cimm_extra_cols .= ', budget_allocation';

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
    
    try {
        $conn->query("SELECT 1 FROM road_transportation_reports LIMIT 0");
        $completed_at_exists = $conn->query("SHOW COLUMNS FROM road_transportation_reports LIKE 'completed_at'")->num_rows > 0;
    } catch (Exception $e) { $completed_at_exists = false; }
    if ($completed_at_exists) $extra_cols .= ', completed_at';
    
    if ($table === 'cimm_verification_reports') {
        $query = "SELECT id, reference_code AS report_id, infrastructure AS title, issue AS description,
                    location, coord_lat AS latitude, coord_lng AS longitude, priority,
                    verification_status AS status, approval_status, reporter_name,
                    COALESCE(submitted_at, verified_at, synced_at) AS created_at, verified_at,
                    engineer, budget_allocation, 'cimm' AS source, 'road' AS report_category
                    FROM cimm_verification_reports WHERE id = ?";
    } elseif ($table === 'road_transportation_reports') {
        $query = "SELECT id, report_id, report_type, title, department, priority, status, created_date, due_date, description,
                    location, latitude, longitude, reporter_name, reporter_email, severity, reported_date, resolved_date, assigned_to,
                    resolution_notes as notes, estimation, attachments, created_by, created_at, updated_at, image_path,
                    report_category, report_source, reporter_phone, cimm_engineer_name, cimm_budget
                    {$extra_cols} {$cimm_extra_cols}
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
        $report['completed_at'] = isset($report['completed_at']) && $report['completed_at'] ? format_datetime($report['completed_at']) : null;

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
