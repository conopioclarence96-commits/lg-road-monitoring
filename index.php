<?php
/**
 * Public landing page – no login required.
 * This is the main domain root file that includes the home page
 */

// Session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();

// Dynamic base path detection
$basePath = '';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';

// Detect if we're in a subdirectory
if (strpos($scriptName, '/lgu_staff/') !== false) {
    $basePath = '../';
} elseif (strpos($scriptName, '/public/') !== false) {
    $basePath = '../';
} elseif (strpos($requestUri, '/lgu-portal/') !== false) {
    $basePath = '';
}

// Try to include database files with error handling
$database_available = false;
$conn = null;

require_once 'lgu_staff/includes/config.php';
require_once 'lgu_staff/includes/functions.php';
$database_available = true;

// AJAX endpoint for map markers
if (isset($_GET['action']) && $_GET['action'] === 'get_markers' && $database_available && $conn) {
    header('Content-Type: application/json');
    try {
        $markers = [];
        $result = $conn->query("SELECT id, title, description, report_type, status, priority, severity, latitude, longitude, location, created_at FROM road_transportation_reports WHERE latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY created_at DESC");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $markers[] = $row;
            }
        }
        echo json_encode($markers);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

// Get latest road updates for display
$road_updates = [];
if ($database_available && $conn) {
    try {
        // Check if the expected columns exist
        $stmt = $conn->prepare("DESCRIBE road_transportation_reports");
        $stmt->execute();
        $result = $stmt->get_result();
        
        $has_attachments = false;
        $has_title = false;
        $has_description = false;
        $has_reported_date = false;
        
        while ($row = $result->fetch_assoc()) {
            if ($row['Field'] === 'attachments') $has_attachments = true;
            if ($row['Field'] === 'title') $has_title = true;
            if ($row['Field'] === 'description') $has_description = true;
            if ($row['Field'] === 'reported_date') $has_reported_date = true;
        }
        $stmt->close();
        
        // Build query based on available columns
        $select_fields = "id";
        if ($has_title) $select_fields .= ", title";
        if ($has_description) $select_fields .= ", description";
        if ($has_reported_date) $select_fields .= ", reported_date";
        if ($has_attachments) $select_fields .= ", attachments";
        $select_fields .= ", image_path";
        if ($has_title) $select_fields .= ", report_type, priority, status, location";
        
        $order_field = $has_reported_date ? "reported_date" : "created_at";
        
        $stmt = $conn->prepare("SELECT $select_fields FROM road_transportation_reports ORDER BY $order_field DESC LIMIT 3");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $road_updates[] = $row;
        }
        $stmt->close();

        if (!empty($road_updates)) {
            $ids = array_column($road_updates, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $types = str_repeat('i', count($ids));
            $media_stmt = $conn->prepare(
                "SELECT rum.file_path, rum.file_type, ru.report_id
                 FROM report_update_media rum
                 INNER JOIN report_updates ru ON rum.update_id = ru.id
                 WHERE ru.report_id IN ($placeholders) AND rum.file_type = 'image'
                 ORDER BY rum.id ASC"
            );
            $media_stmt->bind_param($types, ...$ids);
            $media_stmt->execute();
            $media_result = $media_stmt->get_result();
            $media_by_report = [];
            while ($m = $media_result->fetch_assoc()) {
                $rid = $m['report_id'];
                if (!isset($media_by_report[$rid])) {
                    $media_by_report[$rid] = $m['file_path'];
                }
            }
            $media_stmt->close();
            foreach ($road_updates as &$upd) {
                if (empty($upd['_first_image']) && !empty($media_by_report[$upd['id']])) {
                    $upd['_first_image'] = $media_by_report[$upd['id']];
                }
            }
            unset($upd);
        }
    } catch (Exception $e) {
        // Handle database errors gracefully
        $road_updates = [];
    }
}

// Get statistics
$stats = [
    'total_reports' => 0,
    'ongoing_repairs' => 0,
    'resolved_issues' => 0,
    'pending_reports' => 0
];

if ($database_available && $conn) {
    try {
        // Total reports
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM road_transportation_reports");
        $stmt->execute();
        $result = $stmt->get_result();
        $stats['total_reports'] = $result->fetch_assoc()['count'];
        $stmt->close();
        
        // Ongoing repairs (in-progress status)
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status = 'in-progress'");
        $stmt->execute();
        $result = $stmt->get_result();
        $stats['ongoing_repairs'] = $result->fetch_assoc()['count'];
        $stmt->close();
        
        // Resolved issues (completed status)
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status = 'completed'");
        $stmt->execute();
        $result = $stmt->get_result();
        $stats['resolved_issues'] = $result->fetch_assoc()['count'];
        $stmt->close();
        
        // Pending reports
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status = 'pending'");
        $stmt->execute();
        $result = $stmt->get_result();
        $stats['pending_reports'] = $result->fetch_assoc()['count'];
        $stmt->close();
    } catch (Exception $e) {
        // Handle database errors gracefully
    }
}

// Get completed projects for Before & After section
$before_after_projects = [];
if ($database_available && $conn) {
    try {
        $stmt = $conn->prepare("SELECT id, title, description, location, completed_date, cost, completed_by, photo, before_photo FROM published_completed_projects WHERE photo IS NOT NULL AND photo != '' AND is_published = 1 ORDER BY completed_date DESC LIMIT 6");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $before_after_projects[] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        $before_after_projects = [];
    }
}

// Load access control settings
$access_settings = [];
if ($database_available && $conn) {
    try {
        $result = $conn->query("SELECT setting_key, setting_value FROM site_settings");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $access_settings[$row['setting_key']] = $row['setting_value'];
            }
        }
    } catch (Exception $e) {
        // Settings table may not exist yet
    }
}

$is_private = ($access_settings['landing_page_private'] ?? '0') === '1';
$is_logged_in = isset($_SESSION['user_id']);
$restricted = $is_private && !$is_logged_in;
$custom_message = $access_settings['custom_message'] ?? '';
$redirect_url = $access_settings['redirect_url'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Road and Transportation Department Monitoring System</title>
    <link rel="icon" type="image/png" href="assets/img/logocityhall.png">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Transition CSS -->
    <link rel="stylesheet" href="styles/transition.css">
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    
    <style>
        :root {
            --primary-color: #1e3c72;
            --secondary-color: #2a5298;
            --accent-color: #4CAF50;
            --light-bg: #f8f9fa;
            --dark-text: #2c3e50;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            color: var(--dark-text);
            line-height: 1.6;
        }

        /* Navigation */
        .navbar {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 1rem 0;
        }

        .navbar-brand {
            font-weight: 600;
            font-size: 1.5rem;
            color: white !important;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .navbar-nav .nav-link {
            color: white !important;
            font-weight: 500;
            margin: 0 10px;
            transition: color 0.3s ease;
        }

        .navbar-nav .nav-link:hover {
            color: var(--accent-color) !important;
        }

        .btn-login {
            background: var(--accent-color);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-login:hover {
            background: #45a049;
            transform: translateY(-2px);
        }

        /* Hero Section */
        .hero {
            background: linear-gradient(rgba(30, 60, 114, 0.8), rgba(42, 82, 152, 0.8)),
                        url('assets/img/cityhall.jpeg') center/cover no-repeat;
            color: white;
            padding: 120px 0 80px;
            text-align: center;
        }

        .hero h1 {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 20px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }

        .hero p {
            font-size: 1.2rem;
            margin-bottom: 30px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .btn-hero {
            padding: 15px 30px;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 30px;
            margin: 10px;
            transition: all 0.3s ease;
        }

        .btn-primary-hero {
            background: var(--accent-color);
            border: none;
            color: white;
        }

        .btn-primary-hero:hover {
            background: #45a049;
            transform: translateY(-3px);
        }

        .btn-secondary-hero {
            background: transparent;
            border: 2px solid white;
            color: white;
        }

        .btn-secondary-hero:hover {
            background: white;
            color: var(--primary-color);
        }

        /* Section Styles */
        .section {
            padding: 80px 0;
        }

        .section-title {
            text-align: center;
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 20px;
        }

        .section-subtitle {
            text-align: center;
            font-size: 1.1rem;
            color: #666;
            margin-bottom: 60px;
        }

        /* Road Updates Cards */
        .update-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
        }

        .update-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }

        .update-card .card-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 15px 15px 0 0 !important;
            font-weight: 600;
        }

        .update-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .badge-maintenance {
            background: #ff9800;
            color: white;
        }

        .badge-advisory {
            background: #2196f3;
            color: white;
        }

        .badge-closure {
            background: #f44336;
            color: white;
        }

        /* Statistics Cards */
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            font-size: 3rem;
            color: var(--accent-color);
            margin-bottom: 20px;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 10px;
        }

        .stat-label {
            font-size: 1.1rem;
            color: #666;
        }

        /* Services Section */
        .service-card {
            text-align: center;
            padding: 30px;
            border-radius: 15px;
            background: white;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            height: 100%;
        }

        .service-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }

        .service-icon {
            font-size: 3rem;
            color: var(--primary-color);
            margin-bottom: 20px;
        }

        .service-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 15px;
        }

        /* Report Form */
        .report-form {
            background: white;
            border-radius: 15px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .form-control, .form-select {
            border-radius: 10px;
            border: 1px solid #ddd;
            padding: 12px;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(30, 60, 114, 0.25);
        }

        /* Contact Section */
        .contact-section {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }

        .contact-info {
            text-align: center;
            padding: 20px;
        }

        .contact-icon {
            font-size: 2rem;
            margin-bottom: 15px;
            color: var(--accent-color);
        }

        /* Footer */
        footer {
            background: #0f2341;
            color: white;
            padding: 40px 0 20px;
        }

        footer .btn-login {
            margin-left: 15px;
            vertical-align: middle;
            padding: 6px 18px;
            font-size: 0.9rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .hero h1 {
                font-size: 2rem;
            }
            
            .hero p {
                font-size: 1rem;
            }
            
            .section-title {
                font-size: 2rem;
            }
            
            .stat-number {
                font-size: 2rem;
            }
        }

        /* Before & After Projects Section */
        .before-after-section {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
        }

        .before-after-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(480px, 1fr));
            gap: 30px;
        }

        @media (max-width: 576px) {
            .before-after-grid {
                grid-template-columns: 1fr;
            }
        }

        .before-after-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .before-after-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.18);
        }

        .comparison-slider {
            position: relative;
            width: 100%;
            aspect-ratio: 16 / 10;
            overflow: hidden;
            cursor: ew-resize;
            user-select: none;
            -webkit-user-select: none;
        }

        .comparison-slider img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            pointer-events: none;
        }

        .comparison-slider .img-before {
            z-index: 2;
            clip-path: inset(0 50% 0 0);
        }

        .comparison-slider .img-after {
            z-index: 1;
        }

        .comparison-handle {
            position: absolute;
            top: 0;
            bottom: 0;
            left: 50%;
            width: 4px;
            background: white;
            z-index: 3;
            transform: translateX(-50%);
            box-shadow: 0 0 12px rgba(0, 0, 0, 0.4);
            pointer-events: none;
        }

        .comparison-handle::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 44px;
            height: 44px;
            background: white;
            border-radius: 50%;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        .comparison-handle::after {
            content: '◂ ▸';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 14px;
            font-weight: 700;
            color: var(--primary-color);
            z-index: 4;
            letter-spacing: -2px;
            white-space: nowrap;
        }

        .comparison-label {
            position: absolute;
            top: 12px;
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            z-index: 4;
            pointer-events: none;
        }

        .label-before {
            left: 12px;
            background: rgba(244, 67, 54, 0.9);
            color: white;
        }

        .label-after {
            right: 12px;
            background: rgba(76, 175, 80, 0.9);
            color: white;
        }

        .before-after-info {
            padding: 20px 24px;
        }

        .before-after-info h4 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 8px;
        }

        .before-after-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 10px;
        }

        .before-after-meta span {
            font-size: 0.85rem;
            color: #666;
        }

        .before-after-meta i {
            color: var(--accent-color);
            margin-right: 4px;
        }

        .before-after-cost {
            display: inline-block;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .before-after-empty {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .before-after-empty i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #ccc;
        }

        /* GIS Live Map Section */
        .gis-map-section {
            background: linear-gradient(135deg, #0f1923 0%, #1a2a3a 100%);
            color: white;
            padding: 60px 0;
        }

        .gis-map-section .section-title {
            color: white;
        }

        .gis-map-section .section-subtitle {
            color: rgba(255,255,255,0.7);
        }

        .gis-container {
            background: #1a2a3a;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 12px 40px rgba(0,0,0,0.4);
            border: 1px solid rgba(255,255,255,0.1);
        }

        .gis-map-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            background: rgba(0,0,0,0.3);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            flex-wrap: wrap;
            gap: 10px;
        }

        .gis-map-header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .gis-live-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            background: rgba(16, 185, 129, 0.2);
            border: 1px solid rgba(16, 185, 129, 0.4);
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: #10b981;
        }

        .gis-live-dot {
            width: 8px;
            height: 8px;
            background: #10b981;
            border-radius: 50%;
            animation: livePulse 1.5s ease-in-out infinite;
        }

        @keyframes livePulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.3); }
        }

        .gis-map-title {
            font-size: 16px;
            font-weight: 600;
        }

        .gis-map-filters {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .gis-filter-btn {
            padding: 6px 14px;
            background: rgba(255,255,255,0.08);
            color: rgba(255,255,255,0.7);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 8px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .gis-filter-btn:hover,
        .gis-filter-btn.active {
            background: #3762c8;
            color: white;
            border-color: #3762c8;
        }

        .gis-filter-btn i {
            margin-right: 4px;
        }

        #publicMap {
            height: 500px;
            width: 100%;
            transition: height 0.3s ease;
        }

        .gis-map-body.expanded #publicMap {
            height: 70vh;
        }

        /* Traffic Alert Banner */
        .traffic-alert-banner {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.15) 0%, rgba(245, 158, 11, 0.15) 100%);
            border-bottom: 1px solid rgba(239, 68, 68, 0.3);
            padding: 10px 20px;
            display: none;
        }

        .traffic-alert-banner.active {
            display: block;
        }

        .traffic-alert-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .traffic-alert-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .traffic-alert-icon {
            font-size: 18px;
            color: #ef4444;
            animation: alertBlink 1s ease-in-out infinite;
        }

        @keyframes alertBlink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }

        .traffic-alert-text {
            font-size: 13px;
            font-weight: 500;
            color: #fca5a5;
        }

        .traffic-alert-text strong {
            color: #ef4444;
        }

        .traffic-alert-count {
            font-size: 12px;
            color: rgba(255,255,255,0.5);
        }

        .traffic-alert-dismiss {
            background: none;
            border: none;
            color: rgba(255,255,255,0.5);
            cursor: pointer;
            font-size: 16px;
            padding: 4px;
            transition: color 0.2s;
        }

        .traffic-alert-dismiss:hover {
            color: white;
        }

        /* Map Legend */
        .gis-map-legend {
            display: flex;
            gap: 16px;
            padding: 12px 20px;
            background: rgba(0,0,0,0.2);
            border-top: 1px solid rgba(255,255,255,0.1);
            flex-wrap: wrap;
            align-items: center;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: rgba(255,255,255,0.7);
        }

        .legend-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.3);
        }

        .legend-dot.critical { background: #dc2626; }
        .legend-dot.high { background: #ef4444; }
        .legend-dot.medium { background: #f59e0b; }
        .legend-dot.low { background: #6b7280; }
        .legend-dot.construction { background: #f97316; }
        .legend-dot.traffic { background: #dc2626; animation: legendPulse 2s ease-in-out infinite; }

        @keyframes legendPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(220,38,38,0.4); }
            50% { box-shadow: 0 0 0 6px rgba(220,38,38,0); }
        }

        /* Map Stats Bar */
        .gis-stats-bar {
            display: flex;
            gap: 20px;
            padding: 14px 20px;
            background: rgba(0,0,0,0.15);
            border-bottom: 1px solid rgba(255,255,255,0.05);
            flex-wrap: wrap;
        }

        .gis-stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .gis-stat-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .gis-stat-icon.traffic { background: rgba(239,68,68,0.2); color: #ef4444; }
        .gis-stat-icon.construction { background: rgba(249,115,22,0.2); color: #f97316; }
        .gis-stat-icon.total { background: rgba(55,98,200,0.2); color: #3762c8; }
        .gis-stat-icon.clear { background: rgba(16,185,129,0.2); color: #10b981; }

        .gis-stat-info h4 {
            font-size: 18px;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 2px;
        }

        .gis-stat-info p {
            font-size: 11px;
            color: rgba(255,255,255,0.5);
            margin: 0;
        }

        /* Map Tooltip Styles */
        .map-tooltip {
            background: #1a2a3a;
            color: white;
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 10px;
            padding: 12px 16px;
            font-family: 'Poppins', sans-serif;
            min-width: 220px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.4);
        }

        .map-tooltip-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 6px;
            color: white;
        }

        .map-tooltip-desc {
            font-size: 12px;
            color: rgba(255,255,255,0.7);
            margin-bottom: 8px;
            line-height: 1.4;
        }

        .map-tooltip-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .map-tooltip-badge.critical { background: rgba(220,38,38,0.2); color: #fca5a5; }
        .map-tooltip-badge.high { background: rgba(239,68,68,0.2); color: #fca5a5; }
        .map-tooltip-badge.medium { background: rgba(245,158,11,0.2); color: #fcd34d; }
        .map-tooltip-badge.low { background: rgba(107,114,128,0.2); color: #9ca3af; }

        /* Traffic marker pulsing animation */
        .traffic-marker-pulse {
            animation: markerPulse 2s ease-in-out infinite;
        }

        @keyframes markerPulse {
            0%, 100% { box-shadow: 0 2px 6px rgba(0,0,0,0.3); }
            50% { box-shadow: 0 2px 20px rgba(220,38,60,0.6); }
        }

        /* Construction marker glow */
        .construction-marker-glow {
            animation: constructionGlow 2.5s ease-in-out infinite;
        }

        @keyframes constructionGlow {
            0%, 100% { box-shadow: 0 2px 6px rgba(0,0,0,0.3); }
            50% { box-shadow: 0 2px 20px rgba(249,115,22,0.6); }
        }

        /* Fullscreen button */
        .gis-fullscreen-btn {
            padding: 6px 14px;
            background: rgba(255,255,255,0.08);
            color: rgba(255,255,255,0.7);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 8px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .gis-fullscreen-btn:hover {
            background: rgba(255,255,255,0.15);
            color: white;
        }

        /* Map loading overlay */
        .map-loading-overlay {
            position: absolute;
            inset: 0;
            background: rgba(15,25,35,0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 500;
            border-radius: 12px;
        }

        .map-loading-spinner {
            text-align: center;
            color: white;
        }

        .map-loading-spinner i {
            font-size: 36px;
            color: #3762c8;
            animation: spin 1s linear infinite;
        }

        .map-loading-spinner p {
            margin-top: 12px;
            font-size: 14px;
            color: rgba(255,255,255,0.7);
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Toast notifications for traffic alerts */
        .traffic-toast-container {
            position: fixed;
            top: 80px;
            right: 20px;
            z-index: 99998;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }

        .traffic-toast {
            background: #1a2a3a;
            border: 1px solid rgba(239,68,68,0.3);
            border-left: 4px solid #ef4444;
            border-radius: 10px;
            padding: 14px 18px;
            min-width: 300px;
            max-width: 380px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.4);
            transform: translateX(120%);
            transition: transform 0.4s ease;
            pointer-events: all;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .traffic-toast.show {
            transform: translateX(0);
        }

        .traffic-toast-icon {
            font-size: 20px;
            color: #ef4444;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .traffic-toast-body {
            flex: 1;
        }

        .traffic-toast-title {
            font-size: 13px;
            font-weight: 600;
            color: white;
            margin-bottom: 4px;
        }

        .traffic-toast-message {
            font-size: 12px;
            color: rgba(255,255,255,0.6);
        }

        .traffic-toast-close {
            background: none;
            border: none;
            color: rgba(255,255,255,0.4);
            cursor: pointer;
            font-size: 14px;
            padding: 2px;
            flex-shrink: 0;
        }

        .traffic-toast-close:hover {
            color: white;
        }

        .traffic-toast.construction {
            border-left-color: #f97316;
        }

        .traffic-toast.construction .traffic-toast-icon {
            color: #f97316;
        }

        /* Leaflet popup override for dark theme */
        .gis-map-section .leaflet-popup-content-wrapper {
            background: #1a2a3a;
            color: white;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.5);
        }

        .gis-map-section .leaflet-popup-tip {
            background: #1a2a3a;
        }

        .gis-map-section .leaflet-popup-close-button {
            color: rgba(255,255,255,0.6);
        }

        .gis-map-section .leaflet-popup-close-button:hover {
            color: white;
        }

        /* Road line labels on map */
        .road-line-label {
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            white-space: nowrap;
            border: 1px solid rgba(255,255,255,0.2);
            pointer-events: none;
        }

        /* Road status indicator in legend */
        .legend-line {
            width: 30px;
            height: 4px;
            border-radius: 2px;
        }

        .legend-line.clear { background: #22c55e; }
        .legend-line.moderate { background: #eab308; }
        .legend-line.heavy { background: #dc2626; }
        .legend-line.construction { background: #f97316; }

        /* Responsive map styles */
        @media (max-width: 768px) {
            #publicMap {
                height: 350px;
            }

            .gis-map-body.expanded #publicMap {
                height: 50vh;
            }

            .gis-map-header {
                padding: 12px 14px;
            }

            .gis-map-filters {
                width: 100%;
            }

            .gis-filter-btn {
                flex: 1;
                text-align: center;
                font-size: 11px;
                padding: 6px 8px;
            }

            .gis-stats-bar {
                gap: 12px;
                padding: 12px 14px;
            }

            .gis-stat-item {
                flex: 1;
                min-width: calc(50% - 12px);
            }

            .gis-stat-icon {
                width: 30px;
                height: 30px;
                font-size: 14px;
            }

            .gis-stat-info h4 {
                font-size: 15px;
            }

            .gis-map-legend {
                gap: 10px;
                padding: 10px 14px;
            }

            .legend-item {
                font-size: 11px;
            }

            .traffic-toast {
                min-width: unset;
                max-width: calc(100vw - 40px);
            }

            .gis-map-section {
                padding: 40px 0;
            }
        }

        @media (max-width: 480px) {
            #publicMap {
                height: 280px;
            }

            .gis-filter-btn {
                font-size: 10px;
                padding: 5px 6px;
            }

            .gis-stat-info h4 {
                font-size: 14px;
            }

            .gis-stat-info p {
                font-size: 10px;
            }
        }

    </style>
    <?php include __DIR__ . '/includes/a11y_css.php'; ?>
</head>
<body>
    <?php if ($restricted): ?>
    <style>
        .restricted-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,0.85);
            display: flex; align-items: center; justify-content: center;
            z-index: 9999; backdrop-filter: blur(8px);
        }
        .restricted-card {
            background: white; border-radius: 16px; padding: 40px;
            max-width: 480px; width: 90%; text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .restricted-card i { font-size: 48px; color: #dc3545; margin-bottom: 20px; }
        .restricted-card h2 { font-size: 24px; color: #1e3c72; margin-bottom: 10px; }
        .restricted-card p { color: #666; margin-bottom: 25px; line-height: 1.6; }
        .restricted-card .btn-login {
            display: inline-block; background: #3762c8; color: white;
            padding: 12px 32px; border-radius: 25px; text-decoration: none;
            font-weight: 600; transition: all 0.3s;
        }
        .restricted-card .btn-login:hover { background: #2a4fa8; transform: translateY(-2px); }
    </style>
    <div class="restricted-overlay" id="restrictedOverlay">
        <div class="restricted-card">
            <i class="fas fa-lock"></i>
            <h2>Access Restricted</h2>
            <p><?php echo !empty($custom_message) ? htmlspecialchars($custom_message) : 'This page is currently private. Please log in to continue.'; ?></p>
            <?php if (!empty($redirect_url)): ?>
                <a href="<?php echo htmlspecialchars($redirect_url); ?>" class="btn-login"><i class="fas fa-external-link-alt"></i> Go to Redirect</a>
            <?php else: ?>
                <a href="lgu_staff/login.php" class="btn-login"><i class="fas fa-sign-in-alt"></i> Login to Access</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark fixed-top">
        <div class="container">
            <a class="navbar-brand" href="#home">
                <i class="fas fa-road"></i>
                Road & Transportation Department
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="#home">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="road-updates.php">Road Updates</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="public_reports.php">Road Status</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="about.php">About</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="contact.php">Contact</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="public_transparency_view.php">Transparency</a>
                    </li>

                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero" id="home" <?php echo ($access_settings['hide_hero'] ?? '0') === '1' ? 'style="display:none"' : ''; ?>>
        <div class="container">
            <h1>Road and Transportation Monitoring System</h1>
            <p class="lead">
                Monitor road conditions in real-time and report road problems to help us maintain safe and efficient transportation infrastructure for our community.
            </p>
            <div class="hero-buttons">
                <a href="public_reports.php" class="btn btn-primary-hero btn-hero">
                    <i class="fas fa-map-marked-alt"></i> Browse All Reports
                </a>
                <a href="road-updates.php" class="btn btn-secondary-hero btn-hero">
                    <i class="fas fa-newspaper"></i> Latest Updates
                </a>
            </div>
        </div>
    </section>

    <!-- GIS Live Traffic Map Section -->
    <section class="gis-map-section" id="live-map">
        <div class="container">
            <h2 class="section-title">Live Road Traffic Map</h2>
            <p class="section-subtitle">Real-time traffic conditions and road incidents across Quezon City</p>

            <div class="gis-container" style="position:relative;">
                <!-- Traffic Alert Banner -->
                <div class="traffic-alert-banner active" id="trafficAlertBanner">
                    <div class="traffic-alert-content">
                        <div class="traffic-alert-left">
                            <i class="fas fa-exclamation-triangle traffic-alert-icon"></i>
                            <span class="traffic-alert-text" id="trafficAlertText">
                                <strong>TRAFFIC ALERT:</strong> Loading current road conditions...
                            </span>
                        </div>
                        <button class="traffic-alert-dismiss" onclick="document.getElementById('trafficAlertBanner').classList.remove('active')" title="Dismiss">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>

                <!-- Map Stats Bar -->
                <div class="gis-stats-bar" id="gisStatsBar">
                    <div class="gis-stat-item">
                        <div class="gis-stat-icon traffic"><i class="fas fa-car-crash"></i></div>
                        <div class="gis-stat-info">
                            <h4 id="statTrafficCount">0</h4>
                            <p>Traffic Incidents</p>
                        </div>
                    </div>
                    <div class="gis-stat-item">
                        <div class="gis-stat-icon construction"><i class="fas fa-hard-hat"></i></div>
                        <div class="gis-stat-info">
                            <h4 id="statConstructionCount">0</h4>
                            <p>Construction Zones</p>
                        </div>
                    </div>
                    <div class="gis-stat-item">
                        <div class="gis-stat-icon total"><i class="fas fa-map-marker-alt"></i></div>
                        <div class="gis-stat-info">
                            <h4 id="statTotalReports">0</h4>
                            <p>Total Reports</p>
                        </div>
                    </div>
                    <div class="gis-stat-item">
                        <div class="gis-stat-icon clear"><i class="fas fa-check-circle"></i></div>
                        <div class="gis-stat-info">
                            <h4 id="statClearRoads">0</h4>
                            <p>Clear Roads</p>
                        </div>
                    </div>
                </div>

                <!-- Map Header -->
                <div class="gis-map-header">
                    <div class="gis-map-header-left">
                        <div class="gis-live-badge">
                            <span class="gis-live-dot"></span>
                            LIVE
                        </div>
                        <span class="gis-map-title"><i class="fas fa-map-marked-alt"></i> Quezon City Road Monitor</span>
                    </div>
                    <div class="gis-map-filters">
                        <button class="gis-filter-btn active" data-filter="all" onclick="filterPublicMap('all')">
                            <i class="fas fa-layer-group"></i> All
                        </button>
                        <button class="gis-filter-btn" data-filter="traffic" onclick="filterPublicMap('traffic')">
                            <i class="fas fa-car"></i> Traffic
                        </button>
                        <button class="gis-filter-btn" data-filter="construction" onclick="filterPublicMap('construction')">
                            <i class="fas fa-hard-hat"></i> Construction
                        </button>
                        <button class="gis-filter-btn" data-filter="critical" onclick="filterPublicMap('critical')">
                            <i class="fas fa-exclamation-circle"></i> Critical
                        </button>
                    </div>
                    <button class="gis-fullscreen-btn" onclick="togglePublicMapFullscreen()" id="publicFullscreenBtn">
                        <i class="fas fa-expand"></i> Fullscreen
                    </button>
                </div>

                <!-- Map Container -->
                <div class="gis-map-body" id="publicMapBody">
                    <div id="publicMap"></div>
                    <div class="map-loading-overlay" id="publicMapLoading">
                        <div class="map-loading-spinner">
                            <i class="fas fa-map-marked-alt"></i>
                            <p>Loading map data...</p>
                        </div>
                    </div>
                </div>

                <!-- Map Legend -->
                <div class="gis-map-legend">
                    <div class="legend-item">
                        <span class="legend-line clear"></span> Clear Road
                    </div>
                    <div class="legend-item">
                        <span class="legend-line moderate"></span> Moderate Traffic
                    </div>
                    <div class="legend-item">
                        <span class="legend-line heavy"></span> Heavy Traffic
                    </div>
                    <div class="legend-item">
                        <span class="legend-line construction"></span> Construction
                    </div>
                    <div class="legend-item">
                        <span class="legend-dot critical"></span> Critical Incident
                    </div>
                    <div class="legend-item">
                        <span class="legend-dot traffic"></span> Traffic Marker
                    </div>
                    <div class="legend-item" style="margin-left:auto;color:rgba(255,255,255,0.4);font-size:11px;">
                        <i class="fas fa-sync-alt"></i> Auto-refresh: 5s
                    </div>
                </div>
            </div>
        </div>

        <!-- Toast Notification Container -->
        <div class="traffic-toast-container" id="trafficToastContainer"></div>
    </section>

    <!-- Road Updates Section -->
    <section class="section" id="updates" <?php echo ($access_settings['hide_updates'] ?? '0') === '1' ? 'style="display:none"' : ''; ?>>
        <div class="container">
            <h2 class="section-title">Road Updates & Announcements</h2>
            <p class="section-subtitle">Stay informed about the latest road conditions and maintenance activities</p>
            
            <div class="row g-4">
                <?php if (!empty($road_updates)): ?>
                    <?php foreach ($road_updates as $update): ?>
                        <div class="col-md-4">
                            <div class="card update-card">
                                <div class="card-header position-relative">
                                    <?php echo htmlspecialchars($update['title'] ?? 'Road Update'); ?>
                                    <span class="update-badge badge-<?php echo strtolower($update['report_type'] ?? 'advisory'); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $update['report_type'] ?? 'Advisory')); ?>
                                    </span>
                                </div>
                                <div class="card-body">
                                    <p class="card-text">
                                        <?php echo htmlspecialchars(substr($update['description'] ?? 'No description available', 0, 100)) . '...'; ?>
                                    </p>
                                    
                                    <?php
                                    $display_image = null;
                                    if (!empty($update['attachments'])):
                                        $attachments = json_decode($update['attachments'], true);
                                        if (is_array($attachments) && !empty($attachments)):
                                            foreach ($attachments as $attachment):
                                                if (isset($attachment['type']) && $attachment['type'] === 'image' && isset($attachment['file_path'])):
                                                    $display_image = $attachment['file_path'];
                                                    break;
                                                endif;
                                            endforeach;
                                        endif;
                                    endif;
                                    if (empty($display_image) && !empty($update['image_path']) && $update['image_path'] !== '0' && $update['image_path'] !== 'null'):
                                        $display_image = $update['image_path'];
                                    endif;
                                    if (empty($display_image) && !empty($update['_first_image'])):
                                        $display_image = $update['_first_image'];
                                    endif;
                                    if ($display_image): ?>
                                        <div class="mt-3">
                                            <img src="<?php echo htmlspecialchars($display_image); ?>" 
                                                 alt="Report Image" 
                                                 class="img-fluid rounded shadow-sm"
                                                 style="max-height: 200px; object-fit: cover; width: 100%; cursor: pointer;"
                                                 onclick="window.open(this.src, '_blank')"
                                                 title="Click to view full size"
                                                 onerror="this.onerror=null;this.src='https://via.placeholder.com/400x200/6c757d/ffffff?text=Image+Not+Available';">
                                        </div>
                                    <?php endif; ?>
                                    
                                    <small class="text-muted">
                                        <i class="fas fa-calendar"></i> 
                                        <?php echo date('M d, Y', strtotime($update['reported_date'] ?? 'now')); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <!-- No database connection - show empty state -->
                    <div class="col-12">
                        <div class="alert alert-warning text-center">
                            <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                            <h5>No Upload Reports</h5>
                            <p class="mb-0">No reports have been uploaded yet. Please check back later.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="text-center mt-4">
                <a href="public_reports.php" class="btn btn-primary-hero btn-hero" style="font-size: 1rem; padding: 12px 28px;">
                    <i class="fas fa-list"></i> View All Road Reports
                </a>
            </div>
        </div>
    </section>

    <!-- Statistics Section -->
    <section class="section bg-light" <?php echo ($access_settings['hide_stats'] ?? '0') === '1' ? 'style="display:none"' : ''; ?>>
        <div class="container">
            <h2 class="section-title">Monitoring Statistics</h2>
            <p class="section-subtitle">Real-time overview of road monitoring activities</p>
            
            <div class="row g-4">
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['total_reports']); ?></div>
                        <div class="stat-label">Total Reports</div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-tools"></i>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['ongoing_repairs']); ?></div>
                        <div class="stat-label">Ongoing Repairs</div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['resolved_issues']); ?></div>
                        <div class="stat-label">Resolved Issues</div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['pending_reports']); ?></div>
                        <div class="stat-label">Pending Reports</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Before & After Projects Section -->
    <section class="section before-after-section" id="projects" <?php echo ($access_settings['hide_before_after'] ?? '0') === '1' ? 'style="display:none"' : ''; ?>>
        <div class="container">
            <h2 class="section-title">See the Transformation</h2>
            <p class="section-subtitle">Drag the slider to compare before and after our completed road projects</p>

            <?php if (!empty($before_after_projects)): ?>
            <div class="before-after-grid">
                <?php foreach ($before_after_projects as $proj):
                    $after_img = htmlspecialchars(ltrim(str_replace(['../', '..\\'], '', $proj['photo']), '/\\'));
                    $before_img = !empty($proj['before_photo']) 
                        ? htmlspecialchars(ltrim(str_replace(['../', '..\\'], '', $proj['before_photo']), '/\\'))
                        : $after_img;
                    $has_before = !empty($proj['before_photo']);
                ?>
                <div class="before-after-card">
                    <div class="comparison-slider" data-slider>
                        <img src="<?php echo $before_img; ?>" alt="Before" class="img-before" loading="lazy"
                             onerror="this.onerror=null;this.src='https://via.placeholder.com/600x375/dc3545/ffffff?text=Before+Image';">
                        <img src="<?php echo $after_img; ?>" alt="After" class="img-after" loading="lazy"
                             onerror="this.onerror=null;this.src='https://via.placeholder.com/600x375/4CAF50/ffffff?text=After+Image';">
                        <div class="comparison-handle" data-handle></div>
                        <span class="comparison-label label-before">Before</span>
                        <span class="comparison-label label-after">After</span>
                    </div>
                    <div class="before-after-info">
                        <h4><?php echo htmlspecialchars($proj['title']); ?></h4>
                        <div class="before-after-meta">
                            <?php if (!empty($proj['location'])): ?>
                            <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($proj['location']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($proj['completed_date'])): ?>
                            <span><i class="fas fa-calendar-check"></i> <?php echo date('M d, Y', strtotime($proj['completed_date'])); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($proj['cost'])): ?>
                        <span class="before-after-cost">₱<?php echo number_format($proj['cost'], 0); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="before-after-empty">
                <i class="fas fa-images"></i>
                <h5>Projects Coming Soon</h5>
                <p>Before and after comparisons of our completed road projects will be displayed here.</p>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- About Section -->
    <section class="section" id="about" <?php echo ($access_settings['hide_about'] ?? '0') === '1' ? 'style="display:none"' : ''; ?>>
        <div class="container">
            <h2 class="section-title">About Road and Transportation Department</h2>
            <p class="section-subtitle">Our commitment to safe and efficient transportation infrastructure</p>
            
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="text-center">
                        <p class="lead">
                            The Road and Transportation Department is dedicated to maintaining and improving our community's transportation infrastructure. 
                            Through advanced monitoring systems and citizen engagement, we ensure safe, reliable, and efficient road networks for all users.
                        </p>
                        <p>
                            Our monitoring system leverages technology to track road conditions, manage maintenance schedules, and respond quickly to emerging issues. 
                            By combining professional expertise with community participation, we create a comprehensive approach to road management that serves 
                            the needs of our growing community.
                        </p>
                        <p>
                            We are committed to transparency, accountability, and excellence in public service. Every report we receive helps us identify and address 
                            issues faster, preventing accidents and improving the quality of life for all residents.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section class="section contact-section" id="contact" <?php echo ($access_settings['hide_contact'] ?? '0') === '1' ? 'style="display:none"' : ''; ?>>
        <div class="container">
            <h2 class="section-title text-white">Contact Us</h2>
            <p class="section-subtitle text-white">Get in touch with our team for assistance and inquiries</p>
            
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="contact-info">
                        <div class="contact-icon">
                            <i class="fas fa-phone"></i>
                        </div>
                        <h4>Phone</h4>
                        <p>Main Office: (123) 456-7890<br>
                           Emergency Hotline: (123) 456-9999</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="contact-info">
                        <div class="contact-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <h4>Email</h4>
                        <p>General: roads@lgu.gov.ph<br>
                           Emergency: emergency@lgu.gov.ph</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="contact-info">
                        <div class="contact-icon">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <h4>Office Location</h4>
                        <p>Road & Transportation Dept.<br>
                           City Hall Building<br>
                           Quezon City, Philippines</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <p>&copy; 2026 Road and Transportation Department. All rights reserved.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <div class="social-icons d-inline-block">
                        <a href="<?php echo $basePath; ?>lgu_staff/login.php" class="btn btn-login">Login</a>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <?php include __DIR__ . '/includes/a11y_html.php'; ?>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
        // Smooth scrolling for navigation links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        
        
        // Navbar background on scroll
        window.addEventListener('scroll', function() {
            const navbar = document.querySelector('.navbar');
            if (window.scrollY > 100) {
                navbar.style.background = 'linear-gradient(135deg, rgba(30, 60, 114, 0.95) 0%, rgba(42, 82, 152, 0.95) 100%)';
            } else {
                navbar.style.background = 'linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%)';
            }
        });

        // Animate elements on scroll - using class toggle to prevent flash on refresh
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver(function(entries) {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animate-in');
                }
            });
        }, observerOptions);

        document.querySelectorAll('.update-card, .stat-card, .service-card, .before-after-card').forEach(card => {
            card.classList.add('scroll-animate');
            observer.observe(card);
        });

        // Page transition for login button
        document.querySelectorAll('a[href*="login.php"]').forEach(link => {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                const overlay = document.getElementById('pageTransitionOverlay');
                overlay.classList.add('active');
                
                setTimeout(() => {
                    window.location.href = this.href;
                }, 1000);
            });
        });

        // Before & After Comparison Slider
        document.querySelectorAll('[data-slider]').forEach(slider => {
            const imgBefore = slider.querySelector('.img-before');
            const handle = slider.querySelector('[data-handle]');
            let isDragging = false;

            function updateSlider(x) {
                const rect = slider.getBoundingClientRect();
                let pos = ((x - rect.left) / rect.width) * 100;
                pos = Math.max(0, Math.min(100, pos));

                imgBefore.style.clipPath = `inset(0 ${100 - pos}% 0 0)`;
                handle.style.left = pos + '%';
            }

            // Mouse events
            slider.addEventListener('mousedown', (e) => {
                isDragging = true;
                updateSlider(e.clientX);
                slider.style.cursor = 'grabbing';
            });

            document.addEventListener('mousemove', (e) => {
                if (!isDragging) return;
                e.preventDefault();
                updateSlider(e.clientX);
            });

            document.addEventListener('mouseup', () => {
                if (isDragging) {
                    isDragging = false;
                    slider.style.cursor = 'ew-resize';
                }
            });

            // Touch events
            slider.addEventListener('touchstart', (e) => {
                isDragging = true;
                updateSlider(e.touches[0].clientX);
            }, { passive: true });

            slider.addEventListener('touchmove', (e) => {
                if (!isDragging) return;
                e.preventDefault();
                updateSlider(e.touches[0].clientX);
            }, { passive: false });

            slider.addEventListener('touchend', () => {
                isDragging = false;
            });

            // Animate handle on load
            setTimeout(() => {
                let start = 0;
                const target = 50;
                const duration = 800;
                const startTime = performance.now();

                function animate(time) {
                    const elapsed = time - startTime;
                    const progress = Math.min(elapsed / duration, 1);
                    const eased = 1 - Math.pow(1 - progress, 3);
                    const current = start + (target - start) * eased;

                    imgBefore.style.clipPath = `inset(0 ${100 - current}% 0 0)`;
                    handle.style.left = current + '%';

                    if (progress < 1) {
                        requestAnimationFrame(animate);
                    }
                }
                requestAnimationFrame(animate);
            }, 300);
        });
    </script>

    <!-- GIS Live Map JavaScript -->
    <script>
    (function() {
        const QC_CENTER = [14.6500, 121.0500];

        const publicMap = L.map('publicMap', {
            center: QC_CENTER,
            zoom: 13,
            zoomControl: true,
            attributionControl: true
        });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            maxZoom: 19
        }).addTo(publicMap);

        const QC_POLYGON_COORDS = [
            [14.605, 120.982], [14.620, 120.985], [14.640, 120.988],
            [14.660, 120.990], [14.680, 120.995], [14.700, 121.005],
            [14.715, 121.020], [14.730, 121.035], [14.745, 121.050],
            [14.755, 121.065], [14.765, 121.080], [14.773, 121.095],
            [14.770, 121.110], [14.762, 121.125], [14.750, 121.135],
            [14.735, 121.142], [14.718, 121.146], [14.700, 121.148],
            [14.682, 121.142], [14.665, 121.135], [14.650, 121.125],
            [14.638, 121.112], [14.628, 121.098], [14.618, 121.080],
            [14.612, 121.062], [14.607, 121.045], [14.605, 121.028],
            [14.603, 121.010], [14.602, 121.000], [14.603, 120.990]
        ];

        const QC_POLYGON = L.polygon(QC_POLYGON_COORDS, {
            color: '#3762c8', weight: 2, opacity: 0.6, fillOpacity: 0.05,
            fillColor: '#3762c8', dashArray: '8, 4'
        }).addTo(publicMap);

        const QC_BBOX = L.latLngBounds(QC_POLYGON_COORDS);
        publicMap.setMaxBounds(QC_BBOX.pad(0.15));
        publicMap.setMinZoom(11);
        publicMap.setMaxZoom(18);

        publicMap.on('moveend', function() {
            if (!QC_BBOX.contains(publicMap.getCenter())) publicMap.setView(QC_CENTER, 13);
        });

        const reportLayer = L.layerGroup().addTo(publicMap);
        const roadLinesLayer = L.layerGroup().addTo(publicMap);
        let allMarkers = [];
        let publicActiveFilter = 'all';
        let publicAutoRefreshInterval = null;
        let publicFullscreen = false;

        const trafficTypes = ['traffic_jam', 'accident', 'road_closure', 'traffic_light_outage', 'congestion', 'parking_violation', 'public_transport_issue'];
        const constructionTypes = ['potholes', 'road_damage', 'cracks', 'erosion', 'flooding', 'debris', 'shoulder_damage', 'marking_fade'];

        // Store for real road data fetched from Overpass API
        let realRoads = [];
        let roadsLoaded = false;
        let roadsLoading = false;

        // QC bounding box for Overpass query: south,west,north,east
        const OVERPASS_BOX = '14.55,120.95,14.78,121.15';

        // Fetch real road geometry from OpenStreetMap Overpass API
        function fetchRealRoads() {
            if (roadsLoading || roadsLoaded) return;
            roadsLoading = true;

            const query = `[out:json][timeout:30];
(
  way["highway"~"motorway|primary|secondary|tertiary|trunk"]["name"](${OVERPASS_BOX});
);
out body;
>;
out skel qt;`;

            fetch('https://overpass-api.de/api/interpreter', {
                method: 'POST',
                body: 'data=' + encodeURIComponent(query),
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
            })
            .then(r => r.json())
            .then(data => {
                // Build node lookup
                const nodes = {};
                data.elements.forEach(el => {
                    if (el.type === 'node') nodes[el.id] = [el.lat, el.lon];
                });

                // Extract road ways with coordinates
                const roads = [];
                data.elements.filter(el => el.type === 'way' && el.tags && el.tags.name).forEach(way => {
                    const coords = way.nodes
                        .map(nid => nodes[nid])
                        .filter(c => c);
                    if (coords.length >= 2) {
                        roads.push({
                            id: way.id,
                            name: way.tags.name,
                            highway: way.tags.highway,
                            coords: coords
                        });
                    }
                });

                realRoads = roads;
                roadsLoaded = true;
                roadsLoading = false;

                // Cache in localStorage
                try {
                    localStorage.setItem('qc_roads_cache', JSON.stringify(roads));
                    localStorage.setItem('qc_roads_cache_time', Date.now().toString());
                } catch(e) {}

                console.log('Loaded ' + roads.length + ' real roads from OpenStreetMap');
            })
            .catch(err => {
                console.error('Overpass API error:', err);
                roadsLoading = false;
                // Try loading from cache
                loadCachedRoads();
            });
        }

        // Load roads from localStorage cache
        function loadCachedRoads() {
            try {
                const cached = localStorage.getItem('qc_roads_cache');
                const cacheTime = parseInt(localStorage.getItem('qc_roads_cache_time') || '0');
                if (cached && (Date.now() - cacheTime < 86400000)) { // 24h cache
                    realRoads = JSON.parse(cached);
                    roadsLoaded = true;
                    console.log('Loaded ' + realRoads.length + ' roads from cache');
                }
            } catch(e) {}
        }

        // Find nearest road segment to a point
        function findNearestRoad(lat, lng) {
            let bestDist = Infinity;
            let bestRoad = null;

            for (const road of realRoads) {
                for (let i = 0; i < road.coords.length - 1; i++) {
                    const dist = pointToSegmentDistance(lat, lng, road.coords[i], road.coords[i + 1]);
                    if (dist < bestDist) {
                        bestDist = dist;
                        bestRoad = road;
                    }
                }
            }
            return { road: bestRoad, distance: bestDist };
        }

        function pointToSegmentDistance(px, py, a, b) {
            const dx = b[0] - a[0];
            const dy = b[1] - a[1];
            const lenSq = dx * dx + dy * dy;
            if (lenSq === 0) return Math.sqrt((px - a[0]) ** 2 + (py - a[1]) ** 2);
            let t = ((px - a[0]) * dx + (py - a[1]) * dy) / lenSq;
            t = Math.max(0, Math.min(1, t));
            const projX = a[0] + t * dx;
            const projY = a[1] + t * dy;
            return Math.sqrt((px - projX) ** 2 + (py - projY) ** 2);
        }

        // Draw colored road lines using real OSM data
        function drawRoadLines(markers) {
            roadLinesLayer.clearLayers();

            if (!roadsLoaded || realRoads.length === 0) return;

            // Track worst status per road
            const roadStatus = {};
            realRoads.forEach(r => { roadStatus[r.id] = { level: 'clear', reports: [], name: r.name }; });

            // Match reports to nearest roads (within ~300m)
            const THRESHOLD = 0.0027; // ~300m in lat/lng degrees
            markers.forEach(m => {
                if (!m.latitude || !m.longitude) return;
                const lat = parseFloat(m.latitude);
                const lng = parseFloat(m.longitude);
                if (isNaN(lat) || isNaN(lng)) return;

                const status = (m.status || '').toLowerCase();
                if (status === 'completed') return;

                const nearest = findNearestRoad(lat, lng);
                if (nearest.road && nearest.distance < THRESHOLD) {
                    const roadId = nearest.road.id;
                    const type = (m.report_type || '').toLowerCase();
                    const severity = (m.severity || m.priority || 'low').toLowerCase();
                    const isTraffic = trafficTypes.includes(type);
                    const isConstruction = constructionTypes.includes(type);

                    let level = 'clear';
                    if (isConstruction) level = 'construction';
                    else if (isTraffic && (severity === 'critical' || severity === 'high')) level = 'heavy';
                    else if (isTraffic) level = 'moderate';

                    const priority = { clear: 0, moderate: 1, heavy: 2, construction: 3 };
                    if (priority[level] > priority[roadStatus[roadId].level]) {
                        roadStatus[roadId].level = level;
                    }
                    roadStatus[roadId].reports.push(m);
                }
            });

            // Color scheme
            const roadColors = { clear: '#22c55e', moderate: '#eab308', heavy: '#dc2626', construction: '#f97316' };
            const roadWeights = { clear: 3, moderate: 4, heavy: 5, construction: 5 };

            // Group road segments by name to merge them
            const roadsByName = {};
            realRoads.forEach(r => {
                const name = r.name;
                if (!roadsByName[name]) roadsByName[name] = [];
                roadsByName[name].push(r);
            });

            // Determine worst status across all segments with same name
            const nameStatus = {};
            Object.keys(roadsByName).forEach(name => {
                let worstLevel = 'clear';
                let allReports = [];
                const priority = { clear: 0, moderate: 1, heavy: 2, construction: 3 };
                roadsByName[name].forEach(road => {
                    const st = roadStatus[road.id];
                    if (priority[st.level] > priority[worstLevel]) worstLevel = st.level;
                    allReports = allReports.concat(st.reports);
                });
                nameStatus[name] = { level: worstLevel, reports: allReports };
            });

            // Draw each road segment
            realRoads.forEach(road => {
                const st = roadStatus[road.id];
                const color = roadColors[st.level];
                const weight = roadWeights[st.level];

                // Main road line
                const polyline = L.polyline(road.coords, {
                    color: color,
                    weight: weight,
                    opacity: 0.85,
                    lineCap: 'round',
                    lineJoin: 'round'
                }).addTo(roadLinesLayer);

                // Glow for heavy/construction
                if (st.level === 'heavy' || st.level === 'construction') {
                    L.polyline(road.coords, {
                        color: color,
                        weight: weight + 5,
                        opacity: 0.2,
                        lineCap: 'round',
                        lineJoin: 'round'
                    }).addTo(roadLinesLayer);
                }

                // Popup
                const ns = nameStatus[road.name] || st;
                let popupHtml = '<div class="map-tooltip">' +
                    '<div class="map-tooltip-title"><i class="fas fa-road"></i> ' + escHtml(road.name) + '</div>' +
                    '<div style="margin:6px 0;"><span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:' + roadColors[ns.level] + ';margin-right:6px;vertical-align:middle;"></span>' +
                    '<span style="font-size:12px;text-transform:uppercase;font-weight:600;">' + ns.level + '</span></div>';

                if (ns.reports.length > 0) {
                    popupHtml += '<div style="font-size:11px;color:rgba(255,255,255,0.6);">' + ns.reports.length + ' report(s)</div>';
                    ns.reports.slice(0, 3).forEach(r => {
                        popupHtml += '<div style="font-size:11px;color:rgba(255,255,255,0.5);margin-top:4px;border-left:2px solid ' + roadColors[ns.level] + ';padding-left:6px;">' +
                            escHtml(r.title || 'Report') + '</div>';
                    });
                } else {
                    popupHtml += '<div style="font-size:11px;color:rgba(255,255,255,0.5);">Clear - No active reports</div>';
                }
                popupHtml += '</div>';

                polyline.bindPopup(popupHtml, { maxWidth: 280 });

                // Invisible wider line for easier clicking
                L.polyline(road.coords, {
                    color: 'transparent', weight: 14, opacity: 0
                }).addTo(roadLinesLayer).bindPopup(popupHtml, { maxWidth: 280 });
            });
        }

        function escHtml(t) {
            const d = document.createElement('div');
            d.textContent = t || '';
            return d.innerHTML;
        }

        function getMarkerStyle(report) {
            const type = (report.report_type || '').toLowerCase();
            const severity = (report.severity || report.priority || 'low').toLowerCase();
            const status = (report.status || '').toLowerCase();
            let color, iconClass, pulseClass, labelText;

            if (trafficTypes.includes(type)) {
                if (severity === 'critical' || severity === 'high') { color = '#dc2626'; iconClass = 'fa-car-crash'; pulseClass = 'traffic-marker-pulse'; labelText = 'Heavy Traffic'; }
                else if (severity === 'medium') { color = '#ef4444'; iconClass = 'fa-car'; pulseClass = 'traffic-marker-pulse'; labelText = 'Traffic Alert'; }
                else { color = '#f59e0b'; iconClass = 'fa-car'; pulseClass = ''; labelText = 'Minor Traffic'; }
            } else if (constructionTypes.includes(type)) {
                color = '#f97316'; iconClass = 'fa-hard-hat'; pulseClass = 'construction-marker-glow'; labelText = 'Construction Zone';
            } else {
                if (severity === 'critical' || severity === 'high') { color = '#dc2626'; iconClass = 'fa-exclamation-triangle'; pulseClass = ''; labelText = 'Critical Issue'; }
                else if (severity === 'medium') { color = '#ffc107'; iconClass = 'fa-info-circle'; pulseClass = ''; labelText = 'Report'; }
                else { color = '#6b757d'; iconClass = 'fa-map-pin'; pulseClass = ''; labelText = 'Low Priority'; }
            }

            if (status === 'completed') { color = '#4b5563'; pulseClass = ''; labelText += ' (Resolved)'; }
            return { color, iconClass, pulseClass, labelText };
        }

        function loadPublicMarkers(filter) {
            filter = filter || publicActiveFilter;
            reportLayer.clearLayers();
            allMarkers = [];

            fetch('index.php?action=get_markers')
                .then(r => r.json())
                .then(markers => {
                    let trafficCount = 0;
                    let constructionCount = 0;

                    markers.forEach(m => {
                        if (!m.latitude || !m.longitude) return;
                        const lat = parseFloat(m.latitude);
                        const lng = parseFloat(m.longitude);
                        if (isNaN(lat) || isNaN(lng)) return;

                        const type = (m.report_type || '').toLowerCase();
                        const severity = (m.severity || m.priority || 'low').toLowerCase();
                        const isTraffic = trafficTypes.includes(type);
                        const isConstruction = constructionTypes.includes(type);

                        if (isTraffic) trafficCount++;
                        if (isConstruction) constructionCount++;

                        if (filter !== 'all') {
                            if (filter === 'traffic' && !isTraffic) return;
                            if (filter === 'construction' && !isConstruction) return;
                            if (filter === 'critical' && !(severity === 'critical' || severity === 'high')) return;
                        }

                        const style = getMarkerStyle(m);
                        const markerIcon = L.divIcon({
                            html: '<div class="' + style.pulseClass + '" style="background:' + style.color + ';color:#fff;border-radius:50%;width:30px;height:30px;display:flex;align-items:center;justify-content:center;font-size:13px;border:2.5px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.35);cursor:pointer;"><i class="fas ' + style.iconClass + '"></i></div>',
                            className: '', iconSize: [30, 30], iconAnchor: [15, 15], popupAnchor: [0, -18]
                        });

                        const popupContent = '<div class="map-tooltip">' +
                            '<div class="map-tooltip-title">' + escHtml(m.title || 'Road Report') + '</div>' +
                            '<div class="map-tooltip-desc">' + escHtml((m.description || '').substring(0, 120)) + '</div>' +
                            '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px;">' +
                            '<span class="map-tooltip-badge ' + severity + '">' + escHtml(severity.toUpperCase()) + '</span>' +
                            (isTraffic ? '<span class="map-tooltip-badge" style="background:rgba(220,38,38,0.2);color:#fca5a5;"><i class="fas fa-car"></i> TRAFFIC</span>' : '') +
                            (isConstruction ? '<span class="map-tooltip-badge" style="background:rgba(249,115,22,0.2);color:#fed7aa;"><i class="fas fa-hard-hat"></i> CONSTRUCTION</span>' : '') +
                            '<span style="font-size:11px;color:rgba(255,255,255,0.5);margin-left:auto;">' + escHtml((m.status || '')) + '</span>' +
                            '</div>' +
                            (m.location ? '<div style="margin-top:6px;font-size:11px;color:rgba(255,255,255,0.4);"><i class="fas fa-map-marker-alt"></i> ' + escHtml(m.location) + '</div>' : '') +
                            '</div>';

                        const marker = L.marker([lat, lng], { icon: markerIcon })
                            .addTo(reportLayer)
                            .bindPopup(popupContent, { maxWidth: 300 });

                        allMarkers.push({ marker, data: m, isTraffic, isConstruction, severity });
                    });

                    drawRoadLines(markers);

                    document.getElementById('statTrafficCount').textContent = trafficCount;
                    document.getElementById('statConstructionCount').textContent = constructionCount;
                    document.getElementById('statTotalReports').textContent = markers.length;
                    const totalActive = markers.filter(m => m.status !== 'completed').length;
                    document.getElementById('statClearRoads').textContent = Math.max(0, markers.length - totalActive);

                    updateTrafficAlertBanner(markers);
                    showTrafficToasts(markers);
                    document.getElementById('publicMapLoading').style.display = 'none';
                })
                .catch(e => {
                    console.error('Map load error:', e);
                    document.getElementById('publicMapLoading').innerHTML =
                        '<div class="map-loading-spinner"><i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i><p>Failed to load map data</p></div>';
                });
        }

        function updateTrafficAlertBanner(markers) {
            const banner = document.getElementById('trafficAlertBanner');
            const text = document.getElementById('trafficAlertText');

            const criticalTraffic = markers.filter(m => {
                const type = (m.report_type || '').toLowerCase();
                const sev = (m.severity || m.priority || '').toLowerCase();
                return trafficTypes.includes(type) && (sev === 'critical' || sev === 'high');
            });

            const activeConstructions = markers.filter(m => {
                const type = (m.report_type || '').toLowerCase();
                const status = (m.status || '').toLowerCase();
                return constructionTypes.includes(type) && status !== 'completed';
            });

            if (criticalTraffic.length > 0 || activeConstructions.length > 2) {
                banner.classList.add('active');
                let msg = '<strong>TRAFFIC ALERT:</strong> ';
                const parts = [];
                if (criticalTraffic.length > 0) parts.push(criticalTraffic.length + ' critical traffic incident' + (criticalTraffic.length > 1 ? 's' : '') + ' detected');
                if (activeConstructions.length > 0) parts.push(activeConstructions.length + ' active construction zone' + (activeConstructions.length > 1 ? 's' : ''));
                msg += parts.join(' &bull; ');
                text.innerHTML = msg;
            } else {
                banner.classList.remove('active');
            }
        }

        let lastToastTime = 0;
        const TOAST_COOLDOWN = 30000;

        function showTrafficToasts(markers) {
            const now = Date.now();
            if (now - lastToastTime < TOAST_COOLDOWN) return;

            const criticalTraffic = markers.filter(m => {
                const type = (m.report_type || '').toLowerCase();
                const severity = (m.severity || m.priority || '').toLowerCase();
                return trafficTypes.includes(type) && (severity === 'critical' || severity === 'high') && m.status !== 'completed';
            });

            if (criticalTraffic.length === 0) return;
            lastToastTime = now;

            const container = document.getElementById('trafficToastContainer');
            container.innerHTML = '';

            criticalTraffic.slice(0, 3).forEach((m, i) => {
                const toast = document.createElement('div');
                toast.className = 'traffic-toast';
                toast.innerHTML =
                    '<i class="fas fa-exclamation-circle traffic-toast-icon"></i>' +
                    '<div class="traffic-toast-body">' +
                    '<div class="traffic-toast-title">' + escHtml(m.title || 'Traffic Incident') + '</div>' +
                    '<div class="traffic-toast-message">' + escHtml((m.description || '').substring(0, 80)) + '</div>' +
                    '</div>' +
                    '<button class="traffic-toast-close" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>';
                container.appendChild(toast);
                setTimeout(() => toast.classList.add('show'), 100 + (i * 200));
                setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 400); }, 5000 + (i * 1000));
            });
        }

        window.filterPublicMap = function(filter) {
            publicActiveFilter = filter;
            document.querySelectorAll('.gis-filter-btn').forEach(b => b.classList.remove('active'));
            const activeBtn = document.querySelector('.gis-filter-btn[data-filter="' + filter + '"]');
            if (activeBtn) activeBtn.classList.add('active');
            loadPublicMarkers(filter);
        };

        window.togglePublicMapFullscreen = function() {
            publicFullscreen = !publicFullscreen;
            document.getElementById('publicMapBody').classList.toggle('expanded', publicFullscreen);
            const btn = document.getElementById('publicFullscreenBtn');
            btn.innerHTML = publicFullscreen ? '<i class="fas fa-compress"></i> Exit' : '<i class="fas fa-expand"></i> Fullscreen';
            setTimeout(() => publicMap.invalidateSize(), 350);
        };

        function startPublicAutoRefresh() {
            if (publicAutoRefreshInterval) clearInterval(publicAutoRefreshInterval);
            publicAutoRefreshInterval = setInterval(() => {
                loadPublicMarkers(publicActiveFilter);
            }, 5000);
        }

        // Init: try cached roads first, then fetch fresh
        loadCachedRoads();
        fetchRealRoads();
        loadPublicMarkers('all');
        startPublicAutoRefresh();

        const mapObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => { if (entry.isIntersecting) setTimeout(() => publicMap.invalidateSize(), 200); });
        }, { threshold: 0.1 });
        mapObserver.observe(document.getElementById('publicMap'));
    })();
    </script>
    
    <!-- Page Transition Overlay -->
    <div class="page-transition-overlay" id="pageTransitionOverlay">
        <div class="transition-content">
            <div class="transition-spinner">
                <i class="fas fa-spinner"></i>
            </div>
            <div class="transition-text">Loading...</div>
        </div>
    </div>
    <?php include __DIR__ . '/includes/a11y_js.php'; ?>
</body>
</html>
