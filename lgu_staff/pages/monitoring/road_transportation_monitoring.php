<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session settings (must be set before session_start)
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS

session_start();
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

// Check if user is logged in
if (
    !isset($_SESSION['user_id']) ||
    !in_array($_SESSION['role'], ['lgu_staff', 'system_admin'])
) {
    header('Location: ../../login.php');
    exit();
}

// Function to get enhanced dashboard stats
function getEnhancedStats() {
    global $conn;
    $stats = ['total' => 0, 'active' => 0, 'critical' => 0, 'resolved_month' => 0];
    if ($conn) {
        try {
            $r = $conn->query("SELECT COUNT(*) as c FROM road_transportation_reports");
            if ($r) $stats['total'] = (int)$r->fetch_assoc()['c'];
            $r = $conn->query("SELECT COUNT(*) as c FROM road_transportation_reports WHERE status IN ('pending','in-progress')");
            if ($r) $stats['active'] = (int)$r->fetch_assoc()['c'];
            $r = $conn->query("SELECT COUNT(*) as c FROM road_transportation_reports WHERE priority IN ('high','critical') AND status != 'completed'");
            if ($r) $stats['critical'] = (int)$r->fetch_assoc()['c'];
            $r = $conn->query("SELECT COUNT(*) as c FROM road_transportation_reports WHERE status='completed' AND MONTH(updated_at)=MONTH(CURDATE()) AND YEAR(updated_at)=YEAR(CURDATE())");
            if ($r) $stats['resolved_month'] = (int)$r->fetch_assoc()['c'];
        } catch (Exception $e) { error_log("Enhanced stats error: ".$e->getMessage()); }
    }
    return $stats;
}

// Function to get recent reports for the table
function getRecentTransportReports($limit = 10) {
    global $conn;
    $reports = [];
    if ($conn) {
        try {
            $q = "SELECT id, report_id, title, report_type, status, priority, severity, created_at 
                  FROM road_transportation_reports 
                  ORDER BY created_at DESC LIMIT ?";
            $stmt = $conn->prepare($q);
            $stmt->bind_param("i", $limit);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $reports[] = $row;
        } catch (Exception $e) { error_log("Recent reports error: ".$e->getMessage()); }
    }
    return $reports;
}

// Function to get monitoring statistics
function getMonitoringStatistics() {
    global $conn;
    $stats = [];
    
    if ($conn) {
        try {
            // Get active roads count
            $result = $conn->query("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status != 'completed'");
            if ($result) {
                $stats['active_roads'] = $result->fetch_assoc()['count'];
            } else {
                $stats['active_roads'] = 0;
            }
            
            // Get incident count
            $result = $conn->query("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status = 'pending' AND priority = 'high'");
            if ($result) {
                $stats['incidents'] = $result->fetch_assoc()['count'];
            } else {
                $stats['incidents'] = 0;
            }
            
            // Get under repair count
            $result = $conn->query("SELECT COUNT(*) as count FROM road_maintenance_reports WHERE status = 'in-progress'");
            if ($result) {
                $stats['under_repair'] = $result->fetch_assoc()['count'];
            } else {
                $stats['under_repair'] = 0;
            }
            
            // Calculate clear flow percentage
            $total_result = $conn->query("SELECT COUNT(*) as count FROM road_transportation_reports");
            if ($total_result) {
                $total = $total_result->fetch_assoc()['count'];
                
                $clear_result = $conn->query("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status = 'completed'");
                if ($clear_result) {
                    $clear = $clear_result->fetch_assoc()['count'];
                    $stats['clear_flow'] = $total > 0 ? round(($clear / $total) * 100, 0) : 94;
                } else {
                    $stats['clear_flow'] = 94;
                }
            } else {
                $stats['clear_flow'] = 94;
            }
            
        } catch (Exception $e) {
            error_log("Statistics query error: " . $e->getMessage());
            // Return sample data if database queries fail
            $stats = [
                'active_roads' => 142,
                'incidents' => 8,
                'under_repair' => 23,
                'clear_flow' => 94
            ];
        }
    } else {
        // Return sample data if database is not available
        $stats = [
            'active_roads' => 142,
            'incidents' => 8,
            'under_repair' => 23,
            'clear_flow' => 94
        ];
    }
    
    return $stats;
}

// Function to get active alerts
function getActiveAlerts() {
    global $conn;
    $alerts = [];
    
    if ($conn) {
        $query = "SELECT title, created_at, priority FROM road_transportation_reports 
                  WHERE status = 'pending' AND priority IN ('high', 'medium') 
                  ORDER BY created_at DESC LIMIT 5";
        $result = $conn->query($query);
        
        while ($row = $result->fetch_assoc()) {
            $alerts[] = [
                'title' => $row['title'],
                'time' => getTimeAgo($row['created_at']),
                'priority' => $row['priority']
            ];
        }
        
    } else {
        // Return sample alerts
        $alerts = [
            ['title' => 'Multi-vehicle accident on Highway 101', 'time' => '5 minutes ago', 'priority' => 'high'],
            ['title' => 'Road maintenance on Main Street', 'time' => '15 minutes ago', 'priority' => 'medium'],
            ['title' => 'Traffic light malfunction at Oak Avenue', 'time' => '30 minutes ago', 'priority' => 'high']
        ];
    }
    
    return $alerts;
}

// Function to get road status
function getRoadStatus() {
    global $conn;
    $roads = [];
    
    if ($conn) {
        $query = "SELECT title, status, description, created_at FROM road_transportation_reports 
                  WHERE status IN ('pending', 'in-progress', 'completed') 
                  ORDER BY created_at DESC LIMIT 10";
        $result = $conn->query($query);
        
        while ($row = $result->fetch_assoc()) {
            $roads[] = [
                'name' => $row['title'],
                'status' => $row['status'],
                'condition' => $row['description'] ?: 'No specific condition reported',
                'traffic' => getTrafficLevel($row['status'])
            ];
        }
        
    } else {
        // Return sample road data
        $roads = [
            ['name' => 'Highway 101', 'status' => 'completed', 'condition' => 'Clear - Normal traffic flow', 'traffic' => 'Light traffic'],
            ['name' => 'Main Street', 'status' => 'pending', 'condition' => 'Heavy congestion - Accident reported', 'traffic' => 'Heavy traffic'],
            ['name' => 'Oak Avenue', 'status' => 'in-progress', 'condition' => 'Moderate - Road maintenance', 'traffic' => 'Moderate traffic'],
            ['name' => 'Elm Street', 'status' => 'completed', 'condition' => 'Clear - No issues reported', 'traffic' => 'Light traffic']
        ];
    }
    
    return $roads;
}

// Helper function to get time ago
function getTimeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' minutes ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hours ago';
    } else {
        $days = floor($diff / 86400);
        return $days . ' days ago';
    }
}

// Helper function to get traffic level based on status
function getTrafficLevel($status) {
    switch ($status) {
        case 'pending':
            return 'Heavy traffic';
        case 'in-progress':
            return 'Moderate traffic';
        case 'completed':
            return 'Light traffic';
        default:
            return 'Normal traffic';
    }
}

// Quezon City center for map
define('QC_LAT', 14.6500);
define('QC_LNG', 121.0500);

// Handle AJAX: get map markers (reports with lat/lng)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_markers') {
    header('Content-Type: application/json');
    $markers = [];
    if ($conn) {
        $sql = "SELECT id, report_id, title, report_type, description, status, priority, severity, latitude, longitude, created_at 
                FROM road_transportation_reports 
                WHERE latitude IS NOT NULL AND longitude IS NOT NULL 
                ORDER BY created_at DESC";
        $res = $conn->query($sql);
        while ($row = $res->fetch_assoc()) {
            $markers[] = $row;
        }
    }
    echo json_encode($markers);
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'submit_report':
            // Start output buffering to catch any errors
            ob_start();
            header('Content-Type: application/json');
            try {
                
                $lat = isset($_POST['latitude']) ? (float)$_POST['latitude'] : null;
                $lng = isset($_POST['longitude']) ? (float)$_POST['longitude'] : null;
                $issue_type = sanitize_input($_POST['issue_type'] ?? '');
                $specific_type = sanitize_input($_POST['specific_type'] ?? '');
                $severity = sanitize_input($_POST['severity'] ?? '');
                $description = sanitize_input($_POST['description'] ?? '');

                // Combine issue type and specific type for detailed reporting
                $full_issue_type = $specific_type ? $specific_type : $issue_type;

                if ($lat === null || $lng === null || $issue_type === '' || $description === '') {
                    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
                    exit;
                }
                
                // Server-side validation: ensure pin is within Quezon City
                $qc_polygon = [
                    [14.605, 120.982], [14.620, 120.985], [14.640, 120.988], [14.660, 120.990],
                    [14.680, 120.995], [14.700, 121.005], [14.715, 121.020], [14.730, 121.035],
                    [14.745, 121.050], [14.755, 121.065], [14.765, 121.080], [14.773, 121.095],
                    [14.770, 121.110], [14.762, 121.125], [14.750, 121.135], [14.735, 121.142],
                    [14.718, 121.146], [14.700, 121.148], [14.682, 121.142], [14.665, 121.135],
                    [14.650, 121.125], [14.638, 121.112], [14.628, 121.098], [14.618, 121.080],
                    [14.612, 121.062], [14.607, 121.045], [14.605, 121.028], [14.603, 121.010],
                    [14.602, 121.000], [14.603, 120.990]
                ];
                $inside_qc = false;
                $n = count($qc_polygon);
                for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                    $yi = $qc_polygon[$i][1]; $xi = $qc_polygon[$i][0];
                    $yj = $qc_polygon[$j][1]; $xj = $qc_polygon[$j][0];
                    if ((($yi > $lng) !== ($yj > $lng)) && ($lat < ($xj - $xi) * ($lng - $yi) / ($yj - $yi) + $xi)) {
                        $inside_qc = !$inside_qc;
                    }
                }
                if (!$inside_qc) {
                    echo json_encode(['success' => false, 'message' => 'Location must be within Quezon City boundaries.']);
                    exit;
                }
                
                // Handle multiple image uploads
                $attachments = [];
                $upload_dir = __DIR__ . '/../../uploads/report_images';
                $upload_dir = str_replace('\\', '/', $upload_dir);
                
                if (!empty($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
                    $file_count = count($_FILES['photos']['name']);
                    for ($i = 0; $i < $file_count; $i++) {
                        if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                        if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) {
                            $upload_errors = [
                                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
                                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
                                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                                UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
                            ];
                            $error_code = $_FILES['photos']['error'][$i];
                            $error_msg = $upload_errors[$error_code] ?? 'Unknown error (code: ' . $error_code . ')';
                            echo json_encode(['success' => false, 'message' => "Upload failed for '" . $_FILES['photos']['name'][$i] . "': " . $error_msg]);
                            exit;
                        }
                        
                        $file = [
                            'name' => $_FILES['photos']['name'][$i],
                            'type' => $_FILES['photos']['type'][$i],
                            'tmp_name' => $_FILES['photos']['tmp_name'][$i],
                            'error' => $_FILES['photos']['error'][$i],
                            'size' => $_FILES['photos']['size'][$i]
                        ];
                        
                        $upload_result = handle_file_upload($file, $upload_dir, ['jpg', 'jpeg', 'png']);
                        
                        if ($upload_result['success']) {
                            $attachments[] = [
                                'type' => 'image',
                                'filename' => $upload_result['filename'],
                                'original_name' => $file['name'],
                                'file_path' => 'uploads/report_images/' . $upload_result['filename'],
                                'uploaded_at' => date('Y-m-d H:i:s')
                            ];
                        } else {
                            $error_msg = $upload_result['error'] ?? 'Unknown upload error';
                            echo json_encode(['success' => false, 'message' => "Upload failed for '" . $file['name'] . "': " . $error_msg]);
                            exit;
                        }
                    }
                }
                
                // Use the specific type if provided, otherwise use general type
                $report_type = $full_issue_type; // This contains the specific type from the form
                // Map severity: severe -> critical
                $severity_db = ($severity === 'severe') ? 'critical' : $severity;
                $priority = ($severity_db === 'critical' || $severity_db === 'high') ? 'high' : ($severity_db === 'medium' ? 'medium' : 'low');
                $report_id = 'RPT-' . date('Ymd-His') . '-' . substr(uniqid(), -5);
                $title = ucfirst($full_issue_type) . ' issue at pinned location';
                $user_id = $_SESSION['user_id'] ?? null;
                // Set department explicitly to prevent truncation
                $department = 'Road and Transportation';
                
                // Validate department is not empty
                if (empty($department)) {
                    $department = 'Road and Transportation';
                }
                $location_str = 'Quezon City (GIS)';
                $attachments_json = !empty($attachments) ? json_encode($attachments) : null;
                // Extract image path for the new image_path column
                $image_path = !empty($attachments) ? $attachments[0]['file_path'] : null;
                
                $stmt = $conn->prepare("INSERT INTO road_transportation_reports 
                    (report_id, report_type, title, department, priority, status, created_date, description, location, latitude, longitude, severity, attachments, image_path, created_by) 
                    VALUES (?, ?, ?, ?, ?, 'pending', CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?)");
                
                if (!$stmt) {
                    echo json_encode(['success' => false, 'message' => 'Database prepare failed: ' . $conn->error]);
                    exit;
                }
                
                // Parameters: report_id, report_type, title, department, priority, description, location, lat, lng, severity, attachments, image_path, user_id
                $stmt->bind_param("sssssssddssis", $report_id, $report_type, $title, $department, $priority, $description, $location_str, $lat, $lng, $severity_db, $attachments_json, $image_path, $user_id);
                
                if ($stmt->execute()) {
                    ob_end_clean(); // Clear any output before JSON
                    echo json_encode(['success' => true, 'message' => (!empty($attachments) ? 'Report submitted with image' : 'Report submitted') . '. It will appear in Verification and Monitoring.', 'report_id' => $report_id]);
                } else {
                    ob_end_clean();
                    $error_details = $stmt->error;
                    error_log("Statement execution failed: " . $error_details);
                    error_log("Bound parameters: report_id=$report_id, report_type=$report_type, title=$title, department=$department, priority=$priority, description=$description, location=$location_str, lat=$lat, lng=$lng, severity=$severity_db, attachments=$attachments_json, image_path=$image_path, user_id=$user_id");
                    echo json_encode(['success' => false, 'message' => 'Failed to save report: ' . $error_details]);
                }
            } catch (Exception $e) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            } catch (Error $e) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Fatal error: ' . $e->getMessage()]);
            }
            exit;
    }
}

// Get data for the page
$alerts = getActiveAlerts();
$roads = getRoadStatus();
$enhanced_stats = getEnhancedStats();
$recent_reports = getRecentTransportReports(10);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Road and Transportation Monitoring | LGU Staff</title>
    <link rel="icon" type="image/png" href="../../assets/img/logocityhall.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="../../css/progress-updates.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="../../js/progress-updates.js"></script>
    <style>
        body {
            background: #f7f5f0;
            min-height: 100vh;
        }
        
        .main-content {
            position: relative;
            z-index: 1;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        .main-content {
            margin-left: 250px;
            padding: 20px;
            position: relative;
            z-index: 1;
        }

        .monitoring-header {
            background: #f0f4fa;
            backdrop-filter: blur(15px);
            padding: 25px 30px;
            border-radius: 16px;
            margin-bottom: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .header-title h1 {
            color: #1e3c72;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .header-title p {
            color: #666;
            font-size: 14px;
        }

        .btn-action {
            padding: 10px 20px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(55, 98, 200, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #495057);
        }

        .btn-secondary:hover {
            box-shadow: 0 6px 20px rgba(108, 117, 125, 0.3);
        }

        .monitoring-layout {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 25px;
            margin-bottom: 25px;
        }

        .map-section {
            background: #f0f4fa;
            backdrop-filter: blur(15px);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .map-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .map-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e3c72;
        }

        .map-filters {
            display: flex;
            gap: 10px;
        }

        .filter-btn {
            padding: 6px 12px;
            background: rgba(55, 98, 200, 0.1);
            color: #3762c8;
            border: 1px solid rgba(55, 98, 200, 0.3);
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filter-btn:hover,
        .filter-btn.active {
            background: #3762c8;
            color: white;
        }

        .map-hint {
            font-size: 13px;
            color: #666;
            margin-top: 4px;
        }
        #map {
            height: 500px;
            border-radius: 12px;
            overflow: hidden;
        }
        .report-form-panel {
            margin-top: 16px;
            padding: 20px;
            background: #f0f4fa;
            border-radius: 12px;
            border: 1px solid rgba(55, 98, 200, 0.2);
        }
        .report-form-panel h4 {
            color: #1e3c72;
            margin-bottom: 16px;
            font-size: 16px;
        }
        .report-form-panel label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #333;
            margin-top: 10px;
            margin-bottom: 4px;
        }
        .report-form-panel select,
        .report-form-panel textarea,
        .report-form-panel input[type="file"] {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid rgba(55, 98, 200, 0.3);
            border-radius: 8px;
            font-size: 14px;
        }
        .report-form-panel input[type="file"] {
            cursor: pointer;
        }
        .report-form-panel .form-actions {
            margin-top: 16px;
            display: flex;
            gap: 10px;
        }

        .sidebar-section {
            display: flex;
            flex-direction: column;
            gap: 25px;
        }

        .info-card {
            background: #f0f4fa;
            backdrop-filter: blur(15px);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .info-card-title {
            font-size: 16px;
            font-weight: 600;
            color: #1e3c72;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-list {
            max-height: 200px;
            overflow-y: auto;
        }

        .alert-item {
            display: flex;
            align-items: flex-start;
            padding: 12px;
            margin-bottom: 10px;
            background: rgba(220, 53, 69, 0.05);
            border-left: 3px solid #dc3545;
            border-radius: 6px;
            transition: all 0.3s ease;
        }

        .alert-item:hover {
            background: rgba(220, 53, 69, 0.1);
        }

        .alert-item.warning {
            background: rgba(255, 193, 7, 0.05);
            border-left-color: #ffc107;
        }

        .alert-item.warning:hover {
            background: rgba(255, 193, 7, 0.1);
        }

        .alert-icon {
            margin-right: 12px;
            color: #dc3545;
            font-size: 16px;
        }

        .alert-item.warning .alert-icon {
            color: #ffc107;
        }

        .alert-content {
            flex: 1;
        }

        .alert-title {
            font-size: 13px;
            font-weight: 500;
            color: #333;
            margin-bottom: 3px;
        }

        .alert-time {
            font-size: 11px;
            color: #999;
        }

        .road-status-list {
            max-height: 250px;
            overflow-y: auto;
        }

        .road-item {
            display: flex;
            align-items: center;
            padding: 12px;
            margin-bottom: 10px;
            background: rgba(255, 255, 255, 0.7);
            border-radius: 8px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .road-item:hover {
            background: rgba(55, 98, 200, 0.1);
            transform: translateX(5px);
        }

        .road-status {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 12px;
            flex-shrink: 0;
        }

        .status-clear {
            background: #28a745;
        }

        .status-moderate {
            background: #ffc107;
        }

        .status-heavy {
            background: #dc3545;
        }

        .road-info {
            flex: 1;
        }

        .road-name {
            font-size: 14px;
            font-weight: 500;
            color: #333;
            margin-bottom: 3px;
        }

        .road-condition {
            font-size: 12px;
            color: #666;
        }

        .traffic-indicator {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            color: #666;
        }


        /* ========== Enhanced Features ========== */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: #f0f4fa;
            border-radius: 14px;
            padding: 20px 18px;
            border: 1px solid rgba(55, 98, 200, 0.1);
            transition: all 0.25s ease;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
        .stat-card .stat-icon {
            width: 44px; height: 44px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; color: #fff; margin-bottom: 12px;
        }
        .stat-card .stat-icon.blue { background: linear-gradient(135deg,#3762c8,#1e3c72); }
        .stat-card .stat-icon.orange { background: linear-gradient(135deg,#f59e0b,#d97706); }
        .stat-card .stat-icon.red { background: linear-gradient(135deg,#ef4444,#dc2626); }
        .stat-card .stat-icon.green { background: linear-gradient(135deg,#10b981,#059669); }
        .stat-card .stat-number { font-size: 26px; font-weight: 700; color: #1e3c72; }
        .stat-card .stat-label { font-size: 13px; color: #6b7280; font-weight: 500; margin-top: 2px; }

        .map-toolbar {
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 10px; margin-bottom: 12px;
        }
        .map-toolbar-left { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .map-toolbar-right { display: flex; gap: 8px; }
        .map-legend {
            display: flex; align-items: center; gap: 14px;
            font-size: 12px; color: #555; padding: 6px 12px;
            background: rgba(255,255,255,0.7); border-radius: 8px;
        }
        .map-legend-item { display: flex; align-items: center; gap: 5px; }
        .map-legend-dot {
            width: 12px; height: 12px; border-radius: 50%;
            border: 2px solid #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .map-fullscreen-btn {
            padding: 6px 14px; background: rgba(55,98,200,0.1); color: #3762c8;
            border: 1px solid rgba(55,98,200,0.3); border-radius: 6px;
            font-size: 12px; cursor: pointer; transition: all 0.2s;
        }
        .map-fullscreen-btn:hover { background: #3762c8; color: #fff; }

        .reports-table-section {
            background: #f0f4fa;
            border-radius: 16px; padding: 20px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            margin-bottom: 25px;
        }
        .reports-table-section .table-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 16px; flex-wrap: wrap; gap: 10px;
        }
        .reports-table-section .table-header h3 {
            font-size: 18px; font-weight: 600; color: #1e3c72;
            display: flex; align-items: center; gap: 10px; margin: 0;
        }
        .reports-table-section .table-header a {
            font-size: 13px; color: #3762c8; text-decoration: none; font-weight: 500;
        }
        .reports-table-section .table-header a:hover { text-decoration: underline; }
        .reports-table-wrap { overflow-x: auto; }
        .reports-table-section table {
            width: 100%; border-collapse: collapse; font-size: 13px;
        }
        .reports-table-section th {
            background: rgba(55,98,200,0.08); padding: 10px 12px;
            text-align: left; font-weight: 600; color: #1e3c72;
            border-bottom: 2px solid rgba(55,98,200,0.15); white-space: nowrap;
        }
        .reports-table-section td {
            padding: 10px 12px; border-bottom: 1px solid rgba(55,98,200,0.08);
            color: #333;
        }
        .reports-table-section tr:hover td { background: rgba(55,98,200,0.03); }
        .reports-table-section .badge {
            display: inline-block; padding: 2px 10px; border-radius: 12px;
            font-size: 11px; font-weight: 600; text-transform: uppercase;
        }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-in-progress { background: #cce5ff; color: #004085; }
        .badge-completed { background: #d4edda; color: #155724; }
        .badge-high, .badge-critical { background: #f8d7da; color: #721c24; }
        .badge-medium { background: #fff3cd; color: #856404; }
        .badge-low { background: #e2e3e5; color: #383d41; }
        .table-action-btn {
            padding: 4px 10px; border-radius: 5px; border: none;
            font-size: 11px; cursor: pointer; transition: all 0.2s;
        }
        .table-action-btn.view-map {
            background: rgba(55,98,200,0.12); color: #3762c8;
        }
        .table-action-btn.view-map:hover { background: #3762c8; color: #fff; }

        .road-search {
            padding: 6px 12px; border: 1px solid rgba(55,98,200,0.3);
            border-radius: 8px; font-size: 13px; width: 200px;
        }

        .map-fullscreen-active #map { height: 70vh; }
        .map-fullscreen-active .monitoring-layout { grid-template-columns: 1fr; }
        .map-fullscreen-active .sidebar-section { display: none; }

        body.dark-mode .stat-card { background: #1e2229; border-color: rgba(255,255,255,0.08); }
        body.dark-mode .stat-card .stat-number { color: #e4e6ea; }
        body.dark-mode .stat-card .stat-label { color: #9ca3af; }
        body.dark-mode .map-legend { background: rgba(30,34,41,0.85); color: #9ca3af; }
        body.dark-mode .reports-table-section { background: #1e2229; border-color: rgba(255,255,255,0.08); }
        body.dark-mode .reports-table-section th { background: rgba(30,34,41,0.8); color: #e4e6ea; }
        body.dark-mode .reports-table-section td { color: #d1d5db; border-bottom-color: rgba(255,255,255,0.06); }
        body.dark-mode .reports-table-section tr:hover td { background: rgba(255,255,255,0.03); }
        body.dark-mode .reports-table-section .table-header h3 { color: #e4e6ea; }
        body.dark-mode .road-search { background: #1a1d23; color: #e4e6ea; border-color: #2d323b; }

        @media (max-width: 1200px) {
            .monitoring-layout {
                grid-template-columns: 1fr;
            }
            
            .sidebar-section {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .sidebar-section {
                grid-template-columns: 1fr;
            }
            
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: white;
            margin: 3% auto;
            padding: 0;
            border-radius: 16px;
            width: 92%;
            max-width: 600px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from { opacity: 0; transform: translateY(-50px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .modal-header {
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: white;
            padding: 20px 25px;
            border-radius: 16px 16px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 600;
            margin: 0;
        }

        .close {
            color: white;
            font-size: 24px;
            cursor: pointer;
            background: none;
            border: none;
        }

        .close:hover { opacity: 0.7; }

        .modal-body {
            padding: 25px;
            max-height: 70vh;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 20px 25px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .btn-secondary-custom {
            padding: 10px 20px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-secondary-custom:hover { background: #5a6268; }

        body.dark-mode .modal-content {
            background: #22262e;
        }

        body.dark-mode .modal-header {
            border-color: #2d323b;
        }

        body.dark-mode .modal-footer {
            border-color: #2d323b;
        }

        body.dark-mode .modal-title {
            color: #e4e6ea;
        }
    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <!-- SIDEBAR -->
    <iframe src="../../includes/sidebar.php" 
            style="position: fixed; width: 250px; height: 100vh; border: none; z-index: 1000;" 
            frameborder="0"
            name="sidebar-frame"
            scrolling="no">
    </iframe>

    <div class="main-content">
        <!-- Monitoring Header -->
        <div class="monitoring-header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Road and Transportation Reporting</h1>
                    <p>Real-time monitoring of road conditions and traffic flow</p>
                </div>
            </div>
        </div>

        <!-- Stats Row -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-road"></i></div>
                <div class="stat-number"><?php echo number_format($enhanced_stats['total']); ?></div>
                <div class="stat-label">Total Reports</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="stat-number"><?php echo number_format($enhanced_stats['active']); ?></div>
                <div class="stat-label">Active Issues</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon red"><i class="fas fa-bolt"></i></div>
                <div class="stat-number"><?php echo number_format($enhanced_stats['critical']); ?></div>
                <div class="stat-label">High / Critical</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number"><?php echo number_format($enhanced_stats['resolved_month']); ?></div>
                <div class="stat-label">Resolved This Month</div>
            </div>
        </div>

        <!-- Main Monitoring Layout -->
        <div class="monitoring-layout">
            <!-- Map Section -->
            <div class="map-section">
                <div class="map-header">
                    <h3 class="map-title">Live Road Map — Quezon City</h3>
                    <p class="map-hint">Click on the map to pin a location, then fill the form and submit your report.</p>
                </div>
                <div class="map-toolbar">
                    <div class="map-toolbar-left">
                        <div class="map-filters">
                            <button class="filter-btn active" data-filter="all" onclick="filterMapMarkers('all')">All</button>
                            <button class="filter-btn" data-filter="pending" onclick="filterMapMarkers('pending')">Pending</button>
                            <button class="filter-btn" data-filter="in-progress" onclick="filterMapMarkers('in-progress')">In Progress</button>
                            <button class="filter-btn" data-filter="completed" onclick="filterMapMarkers('completed')">Completed</button>
                            <button class="filter-btn" data-filter="high" onclick="filterMapMarkers('high')"><i class="fas fa-exclamation"></i> Critical</button>
                        </div>
                        <div class="map-legend">
                            <span class="map-legend-item"><span class="map-legend-dot" style="background:#dc3545;"></span> High</span>
                            <span class="map-legend-item"><span class="map-legend-dot" style="background:#ffc107;"></span> Medium</span>
                            <span class="map-legend-item"><span class="map-legend-dot" style="background:#6c757d;"></span> Low</span>
                        </div>
                    </div>
                    <div class="map-toolbar-right">
                        <button class="map-fullscreen-btn" onclick="toggleMapFullscreen()" id="fullscreenMapBtn">
                            <i class="fas fa-expand"></i> Fullscreen
                        </button>
                    </div>
                </div>
                <div id="map"></div>
                <!-- Report form (shown after pinning) -->
                <div id="report-form-panel" class="report-form-panel" style="display: none;">
                    <h4><i class="fas fa-map-pin"></i> Report issue at pinned location</h4>
                    <form id="report-form" enctype="multipart/form-data">
                        <input type="hidden" id="pin-lat" name="latitude">
                        <input type="hidden" id="pin-lng" name="longitude">
                        <label>Issue type</label>
                        <select id="issue-type" name="issue_type" required onchange="updateSpecificTypes()">
                            <option value="">— Select —</option>
                            <option value="transportation">Transportation</option>
                            <option value="roads">Roads</option>
                        </select>
                        
                        <label id="specific-type-label" style="display: none; margin-top: 10px;">Specific Issue Type</label>
                        <select id="specific-type" name="specific_type" style="display: none;" required>
                            <!-- Transportation specific types -->
                            <optgroup id="transportation-options" label="Transportation Issues" style="display: none;">
                                <option value="traffic_jam">Traffic Jam</option>
                                <option value="accident">Vehicle Accident</option>
                                <option value="road_closure">Road Closure</option>
                                <option value="traffic_light_outage">Traffic Light Outage</option>
                                <option value="congestion">Heavy Congestion</option>
                                <option value="parking_violation">Illegal Parking</option>
                                <option value="public_transport_issue">Public Transport Issue</option>
                            </optgroup>
                            
                            <!-- Roads specific types -->
                            <optgroup id="roads-options" label="Road Issues" style="display: none;">
                                <option value="potholes">Potholes</option>
                                <option value="road_damage">Road Damage</option>
                                <option value="cracks">Road Cracks</option>
                                <option value="erosion">Road Erosion</option>
                                <option value="flooding">Street Flooding</option>
                                <option value="debris">Road Debris</option>
                                <option value="shoulder_damage">Shoulder Damage</option>
                                <option value="marking_fade">Faded Road Markings</option>
                            </optgroup>
                        </select>
                        <label>Severity</label>
                        <select id="severity" name="severity" required>
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="severe">Severe</option>
                        </select>
                        <label>Description</label>
                        <textarea id="description" name="description" rows="3" required placeholder="Describe the issue..."></textarea>
                        <label>Upload Photos (Optional)</label>
                        <button type="button" id="add-photos-btn" style="padding:8px 16px;background:#3762c8;color:white;border:none;border-radius:6px;cursor:pointer;font-size:13px;"><i class="fas fa-camera"></i> Add Photos</button>
                        <input type="file" id="report-images" name="photos[]" multiple accept="image/jpeg,image/jpg,image/png" style="display:none;" />
                        <small style="color: #666; font-size: 12px; display: block; margin-top: 4px;">Max size: 5MB each. Formats: JPG, PNG.</small>
                        <div id="image-preview" style="margin-top: 10px; display: none;">
                            <div id="image-gallery" style="display: flex; flex-wrap: wrap; gap: 8px;"></div>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn-action btn-secondary" id="cancel-pin-btn">Cancel</button>
                            <button type="submit" class="btn-action" id="submit-report-btn"><i class="fas fa-paper-plane"></i> Send report</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="sidebar-section">
                <!-- Active Alerts -->
                <div class="info-card">
                    <h3 class="info-card-title">
                        <i class="fas fa-exclamation-triangle"></i>
                        Active Alerts
                    </h3>
                    <div class="alert-list">
                        <?php foreach ($alerts as $alert): ?>
                        <div class="alert-item <?php echo $alert['priority'] == 'medium' ? 'warning' : ''; ?>">
                            <div class="alert-icon">
                                <i class="fas fa-<?php echo $alert['priority'] == 'high' ? 'car-crash' : 'tools'; ?>"></i>
                            </div>
                            <div class="alert-content">
                                <div class="alert-title"><?php echo htmlspecialchars($alert['title']); ?></div>
                                <div class="alert-time"><?php echo htmlspecialchars($alert['time']); ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Road Status List -->
        <div class="info-card">
            <h3 class="info-card-title">
                <i class="fas fa-road"></i>
                Major Road Status
            </h3>
            <div class="road-status-list">
                <?php foreach ($roads as $road): ?>
                <div class="road-item">
                    <div class="road-status status-<?php 
                        echo $road['status'] == 'completed' ? 'clear' : 
                             ($road['status'] == 'in-progress' ? 'moderate' : 'heavy'); 
                    ?>"></div>
                    <div class="road-info">
                        <div class="road-name"><?php echo htmlspecialchars($road['name']); ?></div>
                        <div class="road-condition"><?php echo htmlspecialchars($road['condition']); ?></div>
                        <div class="traffic-indicator">
                            <i class="fas fa-car"></i>
                            <span><?php echo htmlspecialchars($road['traffic']); ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Recent Reports Table -->
        <div class="reports-table-section">
            <div class="table-header">
                <h3><i class="fas fa-list"></i> Recent Submissions</h3>
                <input type="text" class="road-search" placeholder="Search by title or ID..." id="reportSearchInput" oninput="filterReportsTable(this.value)">
            </div>
            <div class="reports-table-wrap">
                <table id="recentReportsTable">
                    <thead>
                        <tr>
                            <th>Report ID</th>
                            <th>Title</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th>Date</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_reports)): ?>
                        <tr><td colspan="7" style="text-align:center;padding:30px;color:#6b7280;">No reports yet.</td></tr>
                        <?php else: ?>
                        <?php foreach ($recent_reports as $rr): ?>
                         <tr class="report-table-row" data-id="<?php echo $rr['id']; ?>" data-title="<?php echo htmlspecialchars(strtolower($rr['title'] ?? '')); ?>" data-report-id="<?php echo htmlspecialchars(strtolower($rr['report_id'] ?? '')); ?>">
                            <td style="font-family:monospace;font-size:12px;"><?php echo htmlspecialchars($rr['report_id'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($rr['title'] ?? 'Untitled'); ?></td>
                            <td><?php echo htmlspecialchars($rr['report_type'] ?? '—'); ?></td>
                            <td><span class="badge badge-<?php echo $rr['status'] ?? 'pending'; ?>"><?php echo ucfirst(str_replace('-',' ',$rr['status'] ?? 'pending')); ?></span></td>
                            <td><span class="badge badge-<?php echo $rr['priority'] ?? 'low'; ?>"><?php echo ucfirst($rr['priority'] ?? 'low'); ?></span></td>
                            <td><?php echo date('M d, Y H:i', strtotime($rr['created_at'] ?? 'now')); ?></td>
                            <td style="white-space:nowrap;">
                                <button class="table-action-btn view-map" onclick="focusReportOnMap(<?php echo $rr['id']; ?>)"><i class="fas fa-map-pin"></i> Map</button>
                                <?php if ($_SESSION['role'] === 'lgu_staff'): ?><button class="table-action-btn" style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;margin-left:4px;" onclick="viewReportUpdates(<?php echo $rr['id']; ?>, '<?php echo $rr['report_type']; ?>')"><i class="fas fa-clock"></i> Updates</button><?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <script>
        // Quezon City center
        const QC_CENTER = [14.6500, 121.0500];
        const map = L.map('map').setView(QC_CENTER, 13);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        // Define Quezon City approximate boundary polygon
        const QC_POLYGON_COORDS = [
            [14.605, 120.982],
            [14.620, 120.985],
            [14.640, 120.988],
            [14.660, 120.990],
            [14.680, 120.995],
            [14.700, 121.005],
            [14.715, 121.020],
            [14.730, 121.035],
            [14.745, 121.050],
            [14.755, 121.065],
            [14.765, 121.080],
            [14.773, 121.095],
            [14.770, 121.110],
            [14.762, 121.125],
            [14.750, 121.135],
            [14.735, 121.142],
            [14.718, 121.146],
            [14.700, 121.148],
            [14.682, 121.142],
            [14.665, 121.135],
            [14.650, 121.125],
            [14.638, 121.112],
            [14.628, 121.098],
            [14.618, 121.080],
            [14.612, 121.062],
            [14.607, 121.045],
            [14.605, 121.028],
            [14.603, 121.010],
            [14.602, 121.000],
            [14.603, 120.990]
        ];
        const QC_POLYGON = L.polygon(QC_POLYGON_COORDS, {
            color: '#3762c8',
            weight: 2,
            opacity: 0.8,
            fillOpacity: 0.08,
            fillColor: '#3762c8'
        }).addTo(map);

        // Point-in-polygon check using ray casting
        function isInsideQCBounds(lat, lng) {
            const polygon = QC_POLYGON.getLatLngs()[0];
            let inside = false;
            for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
                const xi = polygon[i].lat, yi = polygon[i].lng;
                const xj = polygon[j].lat, yj = polygon[j].lng;
                if ((yi > lng) !== (yj > lng) && lat < (xj - xi) * (lng - yi) / (yj - yi) + xi) {
                    inside = !inside;
                }
            }
            return inside;
        }

        // Restrict map panning with a padded bounding box of QC
        const QC_BBOX = L.latLngBounds(QC_POLYGON_COORDS);
        map.setMaxBounds(QC_BBOX.pad(0.15));
        map.setMinZoom(11);
        map.setMaxZoom(18);

        // Force map back to Quezon City if user tries to pan out
        map.on('moveend', function() {
            const center = map.getCenter();
            if (!QC_BBOX.contains(center)) {
                map.setView(QC_CENTER, 13);
                showNotification('Map view restricted to Quezon City area', 'info');
            }
        });

        let pinMarker = null;
        const reportMarkersLayer = L.layerGroup().addTo(map);
        const reportPanel = document.getElementById('report-form-panel');
        const form = document.getElementById('report-form');
        const pinLat = document.getElementById('pin-lat');
        const pinLng = document.getElementById('pin-lng');
        let allMarkerData = [];
        let allMarkerObjects = [];
        let mapFullscreen = false;
        let activeFilter = 'all';
        let autoRefreshInterval = null;

        // Load existing report markers
        function loadMarkers(filter, callback) {
            filter = filter || activeFilter;
            reportMarkersLayer.clearLayers();
            allMarkerObjects = [];
            fetch('?action=get_markers')
                .then(r => r.json())
                .then(markers => {
                    allMarkerData = markers;
                    markers.forEach(m => {
                        if (filter !== 'all') {
                            if (filter === 'high' && !['high','critical'].includes((m.severity || m.priority || '').toLowerCase())) return;
                            else if (filter !== 'high' && (m.status || '') !== filter) return;
                        }
                        const sev = (m.severity || m.priority || 'low').toLowerCase();
                        const color = (sev === 'critical' || sev === 'high') ? '#dc3545' : sev === 'medium' ? '#ffc107' : '#6c757d';
                        const icon = L.divIcon({
                            html: `<div style="background:${color};color:#fff;border-radius:50%;width:28px;height:28px;display:flex;align-items:center;justify-content:center;font-size:12px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.3);"><i class="fas fa-${m.report_type === 'road_damage' ? 'road' : 'traffic-light'}"></i></div>`,
                            className: '',
                            iconSize: [28, 28]
                        });
                        const sevLabel = m.severity || m.priority || 'low';
                        const marker = L.marker([parseFloat(m.latitude), parseFloat(m.longitude)], { icon })
                            .addTo(reportMarkersLayer)
                            .bindPopup(`<b>${escapeHtml(m.title)}</b><br><small>${escapeHtml(m.description || '')}</small><br><span style="color:${color}">${sevLabel} • ${m.status}</span>`);
                        marker._reportId = m.id;
                        allMarkerObjects.push(marker);
                    });
                    if (callback) callback();
                })
                .catch(e => console.error('Load markers error', e));
        }
        function escapeHtml(t) { const d = document.createElement('div'); d.textContent = t || ''; return d.innerHTML; }

        // Filter map markers
        function filterMapMarkers(filter) {
            activeFilter = filter;
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            document.querySelector(`.filter-btn[data-filter="${filter}"]`).classList.add('active');
            loadMarkers(filter);
        }

        // Toggle map fullscreen
        function toggleMapFullscreen() {
            mapFullscreen = !mapFullscreen;
            document.body.classList.toggle('map-fullscreen-active', mapFullscreen);
            const btn = document.getElementById('fullscreenMapBtn');
            btn.innerHTML = mapFullscreen ? '<i class="fas fa-compress"></i> Exit' : '<i class="fas fa-expand"></i> Fullscreen';
            setTimeout(() => map.invalidateSize(), 300);
        }

        // Focus map on a specific report by ID
        function focusReportOnMap(reportId) {
            // First try to find in existing markers (fast path)
            const found = allMarkerObjects.find(m => m._reportId === reportId);
            if (found) {
                map.setView(found.getLatLng(), 16);
                found.openPopup();
                return;
            }
            // Not in current markers — fetch all markers directly and locate it
            activeFilter = 'all';
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            const allBtn = document.querySelector('.filter-btn[data-filter="all"]');
            if (allBtn) allBtn.classList.add('active');
            fetch('?action=get_markers')
                .then(r => r.json())
                .then(markers => {
                    const report = markers.find(m => m.id == reportId);
                    if (report && report.latitude && report.longitude) {
                        const lat = parseFloat(report.latitude);
                        const lng = parseFloat(report.longitude);
                        map.setView([lat, lng], 16);
                        // Also refresh markers on map with all filter
                        loadMarkers('all');
                    } else {
                        showNotification('Report has no location data on the map.', 'info');
                    }
                })
                .catch(() => showNotification('Could not load map data.', 'error'));
        }

        // Search reports table
        function filterReportsTable(query) {
            const q = query.toLowerCase().trim();
            document.querySelectorAll('#recentReportsTable .report-table-row').forEach(row => {
                const title = row.dataset.title || '';
                const rid = row.dataset.reportId || '';
                row.style.display = (!q || title.includes(q) || rid.includes(q)) ? '' : 'none';
            });
        }

        // Start auto-refresh
        function startAutoRefresh() {
            if (autoRefreshInterval) clearInterval(autoRefreshInterval);
            autoRefreshInterval = setInterval(() => {
                loadMarkers(activeFilter);
            }, 30000);
        }

        // Function to update specific issue types based on main category
        function updateSpecificTypes() {
            const issueType = document.getElementById('issue-type').value;
            const specificTypeLabel = document.getElementById('specific-type-label');
            const specificType = document.getElementById('specific-type');
            const transportOptions = document.getElementById('transportation-options');
            const roadOptions = document.getElementById('roads-options');
            
            // Hide all options first
            transportOptions.style.display = 'none';
            roadOptions.style.display = 'none';
            
            if (issueType === 'transportation') {
                specificTypeLabel.style.display = 'block';
                specificType.style.display = 'block';
                transportOptions.style.display = 'block';
                specificType.required = true;
            } else if (issueType === 'roads') {
                specificTypeLabel.style.display = 'block';
                specificType.style.display = 'block';
                roadOptions.style.display = 'block';
                specificType.required = true;
            } else {
                specificTypeLabel.style.display = 'none';
                specificType.style.display = 'none';
                specificType.required = false;
                specificType.value = '';
            }
        }

        // Map click: place pin and show form
        map.on('click', function(e) {
            const { lat, lng } = e.latlng;
            
            // Check if clicked location is within Quezon City polygon
            if (!isInsideQCBounds(lat, lng)) {
                showNotification('Please select a location within Quezon City boundaries', 'error');
                return;
            }
            
            if (pinMarker) map.removeLayer(pinMarker);
            pinMarker = L.marker([lat, lng], {
                draggable: true
            }).addTo(map);
            pinMarker.on('dragend', function() {
                const pos = pinMarker.getLatLng();
                
                // Validate dragged position is still within QC polygon
                if (!isInsideQCBounds(pos.lat, pos.lng)) {
                    showNotification('Please keep the marker within Quezon City boundaries', 'error');
                    // Reset to previous valid position
                    pinMarker.setLatLng([lat, lng]);
                    return;
                }
                
                pinLat.value = pos.lat;
                pinLng.value = pos.lng;
            });
            pinLat.value = lat;
            pinLng.value = lng;
            reportPanel.style.display = 'block';
            form.reset();
            pinLat.value = lat;
            pinLng.value = lng;
            document.getElementById('severity').value = 'medium';
            // Reset specific type dropdown
            updateSpecificTypes();
        });

        document.getElementById('cancel-pin-btn').addEventListener('click', function() {
            if (pinMarker) { map.removeLayer(pinMarker); pinMarker = null; }
            reportPanel.style.display = 'none';
        });

        // Multi-photo upload with add button and per-image delete
        const imageInput = document.getElementById('report-images');
        const imagePreview = document.getElementById('image-preview');
        const imageGallery = document.getElementById('image-gallery');
        const addPhotosBtn = document.getElementById('add-photos-btn');
        let selectedFiles = [];
        
        addPhotosBtn.addEventListener('click', function() {
            imageInput.click();
        });
        
        function renderGallery() {
            imageGallery.innerHTML = '';
            if (selectedFiles.length === 0) {
                imagePreview.style.display = 'none';
                return;
            }
            imagePreview.style.display = 'block';
            selectedFiles.forEach((file, index) => {
                const reader = new FileReader();
                reader.onload = function(ev) {
                    const wrapper = document.createElement('div');
                    wrapper.style.position = 'relative';
                    wrapper.style.display = 'inline-block';
                    const img = document.createElement('img');
                    img.src = ev.target.result;
                    img.style.width = '100px';
                    img.style.height = '100px';
                    img.style.objectFit = 'cover';
                    img.style.borderRadius = '8px';
                    img.style.border = '1px solid rgba(55, 98, 200, 0.3)';
                    wrapper.appendChild(img);
                    const del = document.createElement('button');
                    del.type = 'button';
                    del.innerHTML = '&times;';
                    del.style.position = 'absolute';
                    del.style.top = '-6px';
                    del.style.right = '-6px';
                    del.style.width = '22px';
                    del.style.height = '22px';
                    del.style.borderRadius = '50%';
                    del.style.border = 'none';
                    del.style.background = '#dc3545';
                    del.style.color = 'white';
                    del.style.fontSize = '14px';
                    del.style.lineHeight = '22px';
                    del.style.textAlign = 'center';
                    del.style.cursor = 'pointer';
                    del.style.padding = '0';
                    del.addEventListener('click', function(ev2) {
                        ev2.stopPropagation();
                        selectedFiles.splice(index, 1);
                        renderGallery();
                    });
                    wrapper.appendChild(del);
                    wrapper.dataset.index = index;
                    imageGallery.appendChild(wrapper);
                };
                reader.readAsDataURL(file);
            });
        }
        
        imageInput.addEventListener('change', function(e) {
            const newFiles = Array.from(e.target.files);
            const valid = [];
            newFiles.forEach(file => {
                if (file.size > 5 * 1024 * 1024) {
                    showNotification(`"${file.name}" exceeds 5MB limit.`, 'error');
                } else {
                    valid.push(file);
                }
            });
            selectedFiles = selectedFiles.concat(valid);
            renderGallery();
            imageInput.value = '';
        });

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const dt = new DataTransfer();
            selectedFiles.forEach(f => dt.items.add(f));
            imageInput.files = dt.files;
            const btn = document.getElementById('submit-report-btn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            const fd = new FormData(form);
            fd.set('action', 'submit_report');
            fetch('', { method: 'POST', body: fd })
                .then(r => {
                    if (!r.ok) throw new Error('HTTP error: ' + r.status);
                    return r.text();
                })
                .then(text => {
                    try {
                        const data = JSON.parse(text);
                        if (data.success) {
                            showNotification(data.message, 'success');
                            if (pinMarker) { map.removeLayer(pinMarker); pinMarker = null; }
                            reportPanel.style.display = 'none';
                            form.reset();
                            selectedFiles = [];
                            imageGallery.innerHTML = '';
                            imagePreview.style.display = 'none';
                            loadMarkers(activeFilter);
                        } else {
                            showNotification(data.message || 'Failed to submit.', 'error');
                        }
                    } catch (e) {
                        console.error('Response:', text);
                        showNotification('Server error. Check console for details.', 'error');
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    showNotification('Network error: ' + error.message, 'error');
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send report';
                });
        });

        loadMarkers(activeFilter);
        startAutoRefresh();

        function showNotification(message, type) {
            type = type || 'info';
            const colors = { success: '#10b981', error: '#ef4444', info: '#3762c8', warning: '#f59e0b' };
            const c = colors[type] || colors.info;
            const el = document.createElement('div');
            el.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;padding:14px 20px;border-radius:10px;color:#fff;font-size:14px;font-weight:500;max-width:380px;box-shadow:0 8px 30px rgba(0,0,0,0.2);transform:translateX(120%);transition:transform 0.35s ease;background:'+c;
            el.textContent = message;
            document.body.appendChild(el);
            requestAnimationFrame(() => { el.style.transform = 'translateX(0)'; });
            setTimeout(() => {
                el.style.transform = 'translateX(120%)';
                setTimeout(() => el.remove(), 400);
            }, 4000);
        }

        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        };

        function closeLightbox() {
            document.getElementById('lightboxOverlay').classList.remove('show');
        }

        function viewReportUpdates(id, type) {
            currentUpdatesReportId = id;
            currentUpdatesReportType = type;
            document.getElementById('updateReportInfo').textContent = 'Report #' + id;
            openModal('updatesModal');
            if (typeof loadUpdates === 'function') {
                loadUpdates(id, type);
            }
        }
    </script>
    
    <!-- Progress Updates Modal -->
    <div id="updatesModal" class="modal">
        <div class="modal-content" style="max-width: 750px;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-clock"></i> Progress Updates</h5>
                <button class="close" onclick="closeModal('updatesModal')">&times;</button>
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                <div class="timeline-container" id="updatesTimeline">
                    <div class="timeline-empty"><i class="fas fa-spinner fa-spin fa-2x" style="color:#3762c8;"></i></div>
                </div>
            </div>
            <div class="modal-footer" style="justify-content: space-between;">
                <span id="updateReportInfo" style="font-size: 13px; color: #6b7280;"></span>
                <div>
                    <button type="button" class="btn-action" id="addUpdateBtn" onclick="showUpdateForm(currentUpdatesReportId, currentUpdatesReportType)">+ Add Update</button>
                    <button type="button" class="btn-secondary-custom" onclick="closeModal('updatesModal')">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Update Form Modal -->
    <div id="updateFormModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h5 class="modal-title" id="updateFormModalTitle"><i class="fas fa-plus-circle"></i> Add Progress Update</h5>
                <button class="close" onclick="cancelUpdateForm()">&times;</button>
            </div>
            <form id="addUpdateForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="action" id="ufAction" value="create_update">
                    <input type="hidden" name="update_id" id="ufUpdateId" value="">
                    <input type="hidden" name="report_id" id="ufReportId" value="">
                    <input type="hidden" name="report_type" id="ufReportType" value="">
                    <div class="form-group">
                        <label>Title (optional)</label>
                        <input type="text" name="title" id="ufTitle" placeholder="e.g., Inspection completed" style="width:100%;padding:9px 12px;border:1px solid rgba(55,98,200,0.25);border-radius:8px;font-size:13px;font-family:'Poppins',sans-serif;">
                    </div>
                    <div class="form-group" style="margin-top:12px;">
                        <label>Description *</label>
                        <textarea name="description" id="ufDescription" placeholder="Describe the progress made..." required style="width:100%;padding:9px 12px;border:1px solid rgba(55,98,200,0.25);border-radius:8px;font-size:13px;font-family:'Poppins',sans-serif;resize:vertical;min-height:80px;"></textarea>
                    </div>
                    <div class="form-group" style="margin-top:12px;">
                        <label>Photos / Video</label>
                        <input type="file" name="media[]" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm" multiple style="font-size:13px;padding:6px 0;">
                        <small style="color:#666;font-size:11px;">Accepted: JPG, PNG, GIF, WebP, MP4, WebM</small>
                        <div class="file-previews" id="updateFilePreviews"></div>
                    </div>
                    <div class="form-group" style="margin-top:12px;display:none;" id="existingMediaGroup">
                        <label>Current media (check to remove)</label>
                        <div id="existingUpdateMedia" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;"></div>
                    </div>
                </div>
                <div class="modal-footer" style="justify-content:flex-end;gap:10px;">
                    <button type="button" class="btn-secondary-custom" onclick="cancelUpdateForm()">Cancel</button>
                    <button type="submit" class="btn-action" id="ufSubmitBtn"><i class="fas fa-save"></i> Post Update</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Lightbox -->
    <div class="lightbox-overlay" id="lightboxOverlay" onclick="closeLightbox()">
        <button class="lightbox-close" onclick="closeLightbox()">&times;</button>
        <img id="lightboxImage" src="" alt="Enlarged photo">
    </div>

    <!-- Page Transition Overlay -->
    <div class="page-transition-overlay" id="pageTransitionOverlay">
        <div class="transition-content">
            <div class="transition-spinner">
                <i class="fas fa-spinner"></i>
            </div>
            <div class="transition-text">Loading...</div>
        </div>
    </div>
</body>
</html>
