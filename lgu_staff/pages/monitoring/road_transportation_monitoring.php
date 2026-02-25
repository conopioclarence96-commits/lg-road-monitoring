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
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
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
                
                // Handle image upload
                $attachments = [];
                if (isset($_FILES['report_image']) && $_FILES['report_image']['error'] === UPLOAD_ERR_OK) {
                    // Use absolute path from script location
                    $upload_dir = __DIR__ . '/../../uploads/report_images';
                    // Normalize path separators for Windows
                    $upload_dir = str_replace('\\', '/', $upload_dir);
                    $upload_result = handle_file_upload($_FILES['report_image'], $upload_dir, ['jpg', 'jpeg', 'png']);
                    
                    if ($upload_result['success']) {
                        // Store relative path for web access (from project root)
                        $attachments[] = [
                            'type' => 'image',
                            'filename' => $upload_result['filename'],
                            'original_name' => $_FILES['report_image']['name'],
                            'file_path' => 'uploads/report_images/' . $upload_result['filename'],
                            'uploaded_at' => date('Y-m-d H:i:s')
                        ];
                    } else {
                        $error_msg = $upload_result['error'] ?? 'Unknown upload error';
                        echo json_encode(['success' => false, 'message' => 'Image upload failed: ' . $error_msg]);
                        exit;
                    }
                } elseif (isset($_FILES['report_image']) && $_FILES['report_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                    // File upload error (but not "no file")
                    $upload_errors = [
                        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
                        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
                        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                        UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
                    ];
                    $error_code = $_FILES['report_image']['error'];
                    $error_msg = $upload_errors[$error_code] ?? 'Unknown upload error (code: ' . $error_code . ')';
                    echo json_encode(['success' => false, 'message' => 'Image upload error: ' . $error_msg]);
                    exit;
                }
                
                // Map issue_type to report_type: transportation -> traffic, roads -> road_damage
                $report_type = ($issue_type === 'roads') ? 'road_damage' : 'traffic';
                // Map severity: severe -> critical
                $severity_db = ($severity === 'severe') ? 'critical' : $severity;
                $priority = ($severity_db === 'critical' || $severity_db === 'high') ? 'high' : ($severity_db === 'medium' ? 'medium' : 'low');
                $report_id = 'RPT-' . date('Ymd-His') . '-' . substr(uniqid(), -5);
                $title = ucfirst($issue_type) . ' issue at pinned location';
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
                    echo json_encode(['success' => false, 'message' => 'Failed to save report: ' . $stmt->error]);
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Road and Transportation Monitoring | LGU Staff</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        body {
            background: url("../../../assets/img/cityhall.jpeg") center/cover no-repeat fixed;
            position: relative;
            min-height: 100vh;
        }
        
        body::before {
            content: "";
            position: absolute;
            inset: 0;
            backdrop-filter: blur(6px);
            background: rgba(0, 0, 0, 0.35);
            z-index: -1;
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
            background: rgba(255, 255, 255, 0.95);
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
            background: rgba(255, 255, 255, 0.95);
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
            background: rgba(255,255,255,0.95);
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
            background: rgba(255, 255, 255, 0.95);
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
    </style>
</head>
<body>
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
                    <h1>Road and Transportation Monitoring</h1>
                    <p>Real-time monitoring of road conditions and traffic flow</p>
                </div>
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
                <div id="map"></div>
                <!-- Report form (shown after pinning) -->
                <div id="report-form-panel" class="report-form-panel" style="display: none;">
                    <h4><i class="fas fa-map-pin"></i> Report issue at pinned location</h4>
                    <form id="report-form">
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
                        <label>Upload Photo (Optional)</label>
                        <input type="file" id="report-image" name="report_image" accept="image/*" />
                        <small style="color: #666; font-size: 12px; display: block; margin-top: 4px;">Max size: 5MB. Formats: JPG, PNG</small>
                        <div id="image-preview" style="margin-top: 10px; display: none;">
                            <img id="preview-img" src="" alt="Preview" style="max-width: 100%; max-height: 200px; border-radius: 8px; border: 1px solid rgba(55, 98, 200, 0.3);" />
                            <button type="button" id="remove-image-btn" style="margin-top: 8px; padding: 4px 12px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                <i class="fas fa-times"></i> Remove Image
                            </button>
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

    </div>

    <script>
        // Quezon City center
        const QC_CENTER = [14.6500, 121.0500];
        const map = L.map('map').setView(QC_CENTER, 13);

        // Define Quezon City boundaries (approximate)
        const QC_BOUNDS = [
            [14.35, 120.85], // Southwest corner
            [14.95, 121.25]  // Northeast corner
        ];

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        // Add visual boundary rectangle for Quezon City
        const boundaryRectangle = L.rectangle(QC_BOUNDS, {
            color: '#3762c8',
            weight: 2,
            opacity: 0.8,
            fillOpacity: 0.1,
            fillColor: '#3762c8'
        }).addTo(map);

        // Restrict map to Quezon City bounds with padding
        map.setMaxBounds([
            [14.30, 120.80], // Slightly padded bounds
            [15.00, 121.30]
        ]);
        map.setMinZoom(11);
        map.setMaxZoom(18);

        // Force map back to Quezon City if user tries to pan out
        map.on('moveend', function() {
            const center = map.getCenter();
            if (center.lat < 14.30 || center.lat > 15.00 || 
                center.lng < 120.80 || center.lng > 121.30) {
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

        // Load existing report markers
        function loadMarkers() {
            reportMarkersLayer.clearLayers();
            fetch('?action=get_markers')
                .then(r => r.json())
                .then(markers => {
                    markers.forEach(m => {
                        const sev = (m.severity || m.priority || 'low').toLowerCase();
                        const color = (sev === 'critical' || sev === 'high') ? '#dc3545' : sev === 'medium' ? '#ffc107' : '#6c757d';
                        const icon = L.divIcon({
                            html: `<div style="background:${color};color:#fff;border-radius:50%;width:28px;height:28px;display:flex;align-items:center;justify-content:center;font-size:12px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.3);"><i class="fas fa-${m.report_type === 'road_damage' ? 'road' : 'traffic-light'}"></i></div>`,
                            className: '',
                            iconSize: [28, 28]
                        });
                        const sevLabel = m.severity || m.priority || 'low';
                        L.marker([parseFloat(m.latitude), parseFloat(m.longitude)], { icon })
                            .addTo(reportMarkersLayer)
                            .bindPopup(`<b>${escapeHtml(m.title)}</b><br><small>${escapeHtml(m.description || '')}</small><br><span style="color:${color}">${sevLabel} • ${m.status}</span>`);
                    });
                })
                .catch(e => console.error('Load markers error', e));
        }
        function escapeHtml(t) { const d = document.createElement('div'); d.textContent = t || ''; return d.innerHTML; }

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
            
            // Check if clicked location is within Quezon City bounds
            if (lat < QC_BOUNDS[0][0] || lat > QC_BOUNDS[1][0] || 
                lng < QC_BOUNDS[0][1] || lng > QC_BOUNDS[1][1]) {
                showNotification('Please select a location within Quezon City boundaries', 'error');
                return;
            }
            
            if (pinMarker) map.removeLayer(pinMarker);
            pinMarker = L.marker([lat, lng], {
                draggable: true
            }).addTo(map);
            pinMarker.on('dragend', function() {
                const pos = pinMarker.getLatLng();
                
                // Validate dragged position is still within bounds
                if (pos.lat < QC_BOUNDS[0][0] || pos.lat > QC_BOUNDS[1][0] || 
                    pos.lng < QC_BOUNDS[0][1] || pos.lng > QC_BOUNDS[1][1]) {
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

        // Image preview
        const imageInput = document.getElementById('report-image');
        const imagePreview = document.getElementById('image-preview');
        const previewImg = document.getElementById('preview-img');
        const removeImageBtn = document.getElementById('remove-image-btn');
        
        imageInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                if (file.size > 5 * 1024 * 1024) {
                    alert('Image size exceeds 5MB limit.');
                    e.target.value = '';
                    return;
                }
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    imagePreview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            }
        });
        
        removeImageBtn.addEventListener('click', function() {
            imageInput.value = '';
            previewImg.src = '';
            imagePreview.style.display = 'none';
        });

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('submit-report-btn');
            btn.disabled = true;
            const fd = new FormData(form);
            fd.set('action', 'submit_report');
            fetch('', { method: 'POST', body: fd })
                .then(r => {
                    if (!r.ok) {
                        throw new Error('HTTP error: ' + r.status);
                    }
                    return r.text();
                })
                .then(text => {
                    try {
                        const data = JSON.parse(text);
                        if (data.success) {
                            alert(data.message);
                            if (pinMarker) { map.removeLayer(pinMarker); pinMarker = null; }
                            reportPanel.style.display = 'none';
                            form.reset();
                            imagePreview.style.display = 'none';
                            loadMarkers();
                        } else {
                            alert(data.message || 'Failed to submit.');
                        }
                    } catch (e) {
                        console.error('Response:', text);
                        alert('Server error. Check console for details.');
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    alert('Network error: ' + error.message);
                })
                .finally(() => { btn.disabled = false; });
        });

        loadMarkers();
    </script>
</body>
</html>
