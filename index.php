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

// Get road reports with coordinates for GIS map
$map_reports = [];
if ($database_available && $conn) {
    try {
        $map_stmt = $conn->prepare("SELECT id, title, description, report_type, status, priority, latitude, longitude, location, reported_date, image_path, attachments FROM road_transportation_reports WHERE latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY reported_date DESC LIMIT 50");
        $map_stmt->execute();
        $map_result = $map_stmt->get_result();
        while ($row = $map_result->fetch_assoc()) {
            $map_reports[] = $row;
        }
        $map_stmt->close();
    } catch (Exception $e) {
        $map_reports = [];
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

        /* GIS Map Section */
        .gis-map-section {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            padding: 80px 0;
        }

        #gis-map-container {
            position: relative;
            width: 100%;
            height: 550px;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.18);
            border: 3px solid var(--primary-color);
        }

        #gis-map {
            width: 100%;
            height: 100%;
        }

        .map-controls-panel {
            position: absolute;
            top: 15px;
            left: 15px;
            z-index: 10;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .map-control-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            background: white;
            border: 2px solid #ddd;
            border-radius: 10px;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--primary-color);
            box-shadow: 0 3px 10px rgba(0,0,0,0.12);
            transition: all 0.25s ease;
            white-space: nowrap;
        }

        .map-control-btn:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            transform: translateY(-1px);
            box-shadow: 0 5px 15px rgba(30, 60, 114, 0.3);
        }

        .map-control-btn.active {
            background: var(--accent-color);
            color: white;
            border-color: var(--accent-color);
        }

        .map-control-btn i {
            font-size: 1rem;
            width: 18px;
            text-align: center;
        }

        .map-legend {
            position: absolute;
            bottom: 15px;
            left: 15px;
            z-index: 10;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 10px;
            padding: 12px 16px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.12);
            font-size: 0.8rem;
        }

        .map-legend h6 {
            margin: 0 0 8px 0;
            font-weight: 600;
            color: var(--primary-color);
            font-size: 0.85rem;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }

        .legend-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            flex-shrink: 0;
            border: 2px solid white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.3);
        }

        .map-info-panel {
            position: absolute;
            top: 15px;
            right: 15px;
            z-index: 10;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 10px;
            padding: 12px 16px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.12);
            max-width: 220px;
            font-size: 0.8rem;
        }

        .map-info-panel h6 {
            margin: 0 0 6px 0;
            font-weight: 600;
            color: var(--primary-color);
        }

        .map-info-panel p {
            margin: 0;
            color: #666;
        }

        .pin-form-overlay {
            display: none;
            position: absolute;
            bottom: 15px;
            right: 15px;
            z-index: 20;
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.2);
            width: 300px;
            max-height: 400px;
            overflow-y: auto;
        }

        .pin-form-overlay.show {
            display: block;
        }

        .pin-form-overlay h5 {
            margin: 0 0 12px 0;
            font-size: 1rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        .pin-form-overlay .form-control,
        .pin-form-overlay .form-select {
            font-size: 0.85rem;
            padding: 8px 10px;
            border-radius: 8px;
        }

        .pin-form-overlay .btn-submit-pin {
            background: var(--accent-color);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.85rem;
            width: 100%;
        }

        .pin-form-overlay .btn-submit-pin:hover {
            background: #45a049;
        }

        .streetview-overlay {
            display: none;
            position: absolute;
            inset: 0;
            z-index: 25;
            background: #000;
            border-radius: 16px;
        }

        .streetview-overlay.show {
            display: block;
        }

        .streetview-close-btn {
            position: absolute;
            top: 15px;
            right: 15px;
            z-index: 30;
            background: white;
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            font-size: 1.2rem;
            cursor: pointer;
            box-shadow: 0 3px 10px rgba(0,0,0,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        @media (max-width: 768px) {
            #gis-map-container {
                height: 400px;
                border-radius: 12px;
            }

            .map-controls-panel {
                top: 10px;
                left: 10px;
            }

            .map-control-btn {
                padding: 8px 12px;
                font-size: 0.75rem;
            }

            .map-legend {
                bottom: 10px;
                left: 10px;
                padding: 8px 12px;
            }

            .pin-form-overlay {
                width: calc(100% - 20px);
                left: 10px;
                right: 10px;
                bottom: 10px;
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

    <!-- GIS Road Map Section -->
    <section class="gis-map-section" id="gis-map-section">
        <div class="container">
            <h2 class="section-title">Interactive Road Map</h2>
            <p class="section-subtitle">Explore road conditions with live traffic, terrain view, and street-level inspection</p>

            <div id="gis-map-container">
                <div id="gis-map"></div>

                <!-- Map Control Panel -->
                <div class="map-controls-panel">
                    <button class="map-control-btn active" id="btn-traffic" onclick="toggleTraffic()" title="Toggle live traffic layer">
                        <i class="fas fa-traffic-light"></i> Live Traffic
                    </button>
                    <button class="map-control-btn" id="btn-terrain" onclick="toggleTerrain()" title="Switch to terrain view">
                        <i class="fas fa-mountain"></i> Terrain
                    </button>
                    <button class="map-control-btn" id="btn-streetview" onclick="activateStreetView()" title="Enter first-person street view">
                        <i class="fas fa-street-view"></i> Street View
                    </button>
                    <button class="map-control-btn" id="btn-pinning" onclick="togglePinningMode()" title="Click on road to pin a location">
                        <i class="fas fa-map-pin"></i> Pin Road
                    </button>
                    <button class="map-control-btn" id="btn-satellite" onclick="toggleSatellite()" title="Toggle satellite view">
                        <i class="fas fa-satellite"></i> Satellite
                    </button>
                </div>

                <!-- Map Legend -->
                <div class="map-legend">
                    <h6><i class="fas fa-info-circle"></i> Report Status</h6>
                    <div class="legend-item">
                        <span class="legend-dot" style="background: #dc3545;"></span>
                        <span>Critical / High</span>
                    </div>
                    <div class="legend-item">
                        <span class="legend-dot" style="background: #ffc107;"></span>
                        <span>Medium</span>
                    </div>
                    <div class="legend-item">
                        <span class="legend-dot" style="background: #28a745;"></span>
                        <span>Low / Resolved</span>
                    </div>
                    <div class="legend-item">
                        <span class="legend-dot" style="background: #6c757d;"></span>
                        <span>Pending</span>
                    </div>
                </div>

                <!-- Map Info Panel -->
                <div class="map-info-panel" id="map-info-panel">
                    <h6><i class="fas fa-map-marked-alt"></i> Click Actions</h6>
                    <p id="map-click-hint">Click "Pin Road" then click anywhere on the map to pin a road issue.</p>
                </div>

                <!-- Pin Form Overlay -->
                <div class="pin-form-overlay" id="pin-form-overlay">
                    <h5><i class="fas fa-map-pin"></i> Pin Road Issue</h5>
                    <form id="pin-form" onsubmit="submitPin(event)">
                        <input type="hidden" id="pin-lat" name="pin-lat">
                        <input type="hidden" id="pin-lng" name="pin-lng">
                        <div class="mb-2">
                            <label class="form-label fw-semibold">Issue Type</label>
                            <select class="form-select" id="pin-issue-type" required>
                                <option value="">Select type...</option>
                                <option value="pothole">Pothole</option>
                                <option value="road_damage">Road Damage</option>
                                <option value="flooding">Flooding</option>
                                <option value="traffic_jam">Traffic Jam</option>
                                <option value="accident">Accident</option>
                                <option value="construction">Construction</option>
                                <option value="debris">Debris / Obstruction</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label fw-semibold">Severity</label>
                            <select class="form-select" id="pin-severity" required>
                                <option value="">Select severity...</option>
                                <option value="critical">Critical</option>
                                <option value="high">High</option>
                                <option value="medium">Medium</option>
                                <option value="low">Low</option>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea class="form-control" id="pin-description" rows="2" placeholder="Brief description..."></textarea>
                        </div>
                        <div class="mb-2">
                            <label class="form-label fw-semibold">Your Name (optional)</label>
                            <input type="text" class="form-control" id="pin-name" placeholder="Your name...">
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn-submit-pin"><i class="fas fa-paper-plane"></i> Submit Pin</button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="cancelPin()" style="border-radius:8px;">Cancel</button>
                        </div>
                    </form>
                </div>

                <!-- Street View Overlay -->
                <div class="streetview-overlay" id="streetview-overlay">
                    <button class="streetview-close-btn" onclick="closeStreetView()" title="Close Street View">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        </div>
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

    <!-- Google Maps JavaScript API -->
    <script>
        // Road reports data from PHP
        const mapReports = <?php echo json_encode(array_map(function($r) {
            return [
                'id' => $r['id'],
                'title' => $r['title'] ?? 'Road Report',
                'description' => $r['description'] ?? '',
                'report_type' => $r['report_type'] ?? 'other',
                'status' => $r['status'] ?? 'pending',
                'priority' => $r['priority'] ?? 'medium',
                'latitude' => (float)$r['latitude'],
                'longitude' => (float)$r['longitude'],
                'location' => $r['location'] ?? '',
                'reported_date' => $r['reported_date'] ?? '',
                'image_path' => $r['image_path'] ?? '',
                'attachments' => $r['attachments'] ?? ''
            ];
        }, $map_reports)); ?>;
    </script>
    <script async defer
        src="https://maps.googleapis.com/maps/api/js?key=YOUR_GOOGLE_MAPS_API_KEY&callback=initGISMap&libraries=geometry,places,visualization&v=weekly">
    </script>
    <script>
        let gisMap, trafficLayer, streetViewPanorama, activeInfoWindow;
        let terrainEnabled = false, satelliteEnabled = false, trafficEnabled = true;
        let pinningMode = false, streetViewActive = false;
        let pinMarker = null;
        const QC_CENTER = { lat: 14.6500, lng: 121.0500 };

        function initGISMap() {
            // Default map type
            const mapTypeIds = {
                roadmap: 'roadmap',
                satellite: 'satellite',
                terrain: 'terrain',
                hybrid: 'hybrid'
            };

            gisMap = new google.maps.Map(document.getElementById('gis-map'), {
                center: QC_CENTER,
                zoom: 13,
                mapTypeControl: false,
                streetViewControl: false,
                zoomControl: true,
                fullscreenControl: true,
                mapTypeId: 'roadmap',
                styles: [
                    { featureType: 'poi', stylers: [{ visibility: 'off' }] },
                    { featureType: 'transit', stylers: [{ visibility: 'off' }] }
                ]
            });

            // Initialize traffic layer (on by default)
            trafficLayer = new google.maps.TrafficLayer();
            trafficLayer.setMap(gisMap);

            // Add road reports as markers
            addReportMarkers();

            // Click handler for pinning
            gisMap.addListener('click', function(e) {
                if (pinningMode) {
                    placePin(e.latLng);
                }
            });
        }

        function getStatusColor(priority, status) {
            if (status === 'completed') return '#28a745';
            if (status === 'pending') return '#6c757d';
            switch ((priority || '').toLowerCase()) {
                case 'critical': case 'high': return '#dc3545';
                case 'medium': return '#ffc107';
                case 'low': default: return '#28a745';
            }
        }

        function addReportMarkers() {
            const bounds = new google.maps.LatLngBounds();
            mapReports.forEach(function(report) {
                if (!report.latitude || !report.longitude) return;

                const pos = { lat: report.latitude, lng: report.longitude };
                const color = getStatusColor(report.priority, report.status);

                // Custom marker with colored pin
                const marker = new google.maps.Marker({
                    position: pos,
                    map: gisMap,
                    title: report.title,
                    icon: {
                        path: google.maps.SymbolPath.CIRCLE,
                        scale: 10,
                        fillColor: color,
                        fillOpacity: 0.9,
                        strokeColor: '#fff',
                        strokeWeight: 2
                    },
                    animation: google.maps.Animation.DROP
                });

                // Build info window content
                let imageUrl = '';
                if (report.image_path && report.image_path !== '0' && report.image_path !== 'null') {
                    imageUrl = '<img src="' + report.image_path + '" style="width:100%;max-height:120px;object-fit:cover;border-radius:8px;margin-top:8px;" onerror="this.style.display=\'none\'">';
                } else if (report.attachments) {
                    try {
                        const atts = JSON.parse(report.attachments);
                        const imgAtt = atts.find(a => a.type === 'image' && a.file_path);
                        if (imgAtt) {
                            imageUrl = '<img src="' + imgAtt.file_path + '" style="width:100%;max-height:120px;object-fit:cover;border-radius:8px;margin-top:8px;" onerror="this.style.display=\'none\'">';
                        }
                    } catch(e) {}
                }

                const statusLabel = (report.status || 'pending').replace('-', ' ');
                const content = `
                    <div style="font-family:Poppins,sans-serif;max-width:280px;padding:4px;">
                        <h4 style="margin:0 0 6px;font-size:0.95rem;color:#1e3c72;">${escapeHtml(report.title)}</h4>
                        <div style="display:flex;gap:6px;margin-bottom:6px;">
                            <span style="background:${color};color:white;padding:2px 8px;border-radius:10px;font-size:0.7rem;font-weight:600;">${escapeHtml(report.priority || 'N/A')}</span>
                            <span style="background:#e9ecef;padding:2px 8px;border-radius:10px;font-size:0.7rem;color:#555;">${escapeHtml(statusLabel)}</span>
                        </div>
                        <p style="margin:0 0 6px;font-size:0.8rem;color:#555;">${escapeHtml((report.description || '').substring(0, 100))}${(report.description || '').length > 100 ? '...' : ''}</p>
                        ${imageUrl}
                        <div style="margin-top:8px;font-size:0.75rem;color:#888;">
                            <i class="fas fa-calendar" style="margin-right:4px;"></i>${escapeHtml(report.reported_date || 'N/A')}
                            <a href="https://www.google.com/maps?q=${report.latitude},${report.longitude}" target="_blank" style="color:#3762c8;margin-left:8px;text-decoration:none;">
                                <i class="fas fa-external-link-alt"></i> Google Maps
                            </a>
                        </div>
                    </div>
                `;

                marker.addListener('click', function() {
                    if (activeInfoWindow) activeInfoWindow.close();
                    activeInfoWindow = new google.maps.InfoWindow({ content: content });
                    activeInfoWindow.open(gisMap, marker);
                });

                bounds.extend(pos);
            });

            if (mapReports.length > 1) {
                gisMap.fitBounds(bounds, 50);
            } else if (mapReports.length === 1) {
                gisMap.setCenter({ lat: mapReports[0].latitude, lng: mapReports[0].longitude });
                gisMap.setZoom(15);
            }
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Traffic Toggle
        function toggleTraffic() {
            trafficEnabled = !trafficEnabled;
            const btn = document.getElementById('btn-traffic');
            if (trafficEnabled) {
                trafficLayer.setMap(gisMap);
                btn.classList.add('active');
            } else {
                trafficLayer.setMap(null);
                btn.classList.remove('active');
            }
        }

        // Terrain Toggle
        function toggleTerrain() {
            terrainEnabled = !terrainEnabled;
            satelliteEnabled = false;
            const btn = document.getElementById('btn-terrain');
            const satBtn = document.getElementById('btn-satellite');
            if (terrainEnabled) {
                gisMap.setMapTypeId('terrain');
                btn.classList.add('active');
                satBtn.classList.remove('active');
            } else {
                gisMap.setMapTypeId('roadmap');
                btn.classList.remove('active');
            }
        }

        // Satellite Toggle
        function toggleSatellite() {
            satelliteEnabled = !satelliteEnabled;
            terrainEnabled = false;
            const btn = document.getElementById('btn-satellite');
            const terrBtn = document.getElementById('btn-terrain');
            if (satelliteEnabled) {
                gisMap.setMapTypeId('hybrid');
                btn.classList.add('active');
                terrBtn.classList.remove('active');
            } else {
                gisMap.setMapTypeId('roadmap');
                btn.classList.remove('active');
            }
        }

        // Street View (First Person)
        function activateStreetView() {
            const overlay = document.getElementById('streetview-overlay');
            const mapContainer = document.getElementById('gis-map-container');
            
            if (!streetViewPanorama) {
                streetViewPanorama = new google.maps.StreetViewPanorama(
                    document.getElementById('streetview-overlay'), {
                        position: QC_CENTER,
                        pov: { heading: 165, pitch: 0 },
                        zoom: 1,
                        addressControl: true,
                        linksControl: true,
                        panControl: true,
                        zoomControl: true,
                        fullscreenControl: false
                    }
                );
            }

            overlay.classList.add('show');
            streetViewActive = true;
            google.maps.event.trigger(streetViewPanorama, 'resize');
        }

        function closeStreetView() {
            document.getElementById('streetview-overlay').classList.remove('show');
            streetViewActive = false;
        }

        // Pin Mode
        function togglePinningMode() {
            pinningMode = !pinningMode;
            const btn = document.getElementById('btn-pinning');
            const hint = document.getElementById('map-click-hint');
            const form = document.getElementById('pin-form-overlay');

            if (pinningMode) {
                btn.classList.add('active');
                hint.innerHTML = '<strong style="color:var(--accent-color);">Pinning mode ON</strong> — Click anywhere on the map to place a pin.';
                gisMap.setOptions({ cursor: 'crosshair' });
            } else {
                btn.classList.remove('active');
                hint.innerHTML = 'Click "Pin Road" then click anywhere on the map to pin a road issue.';
                gisMap.setOptions({ cursor: '' });
                form.classList.remove('show');
                if (pinMarker) {
                    pinMarker.setMap(null);
                    pinMarker = null;
                }
            }
        }

        function placePin(latLng) {
            if (pinMarker) pinMarker.setMap(null);

            pinMarker = new google.maps.Marker({
                position: latLng,
                map: gisMap,
                draggable: true,
                animation: google.maps.Animation.DROP,
                icon: {
                    path: google.maps.SymbolPath.BACKWARD_CLOSED_ARROW,
                    scale: 7,
                    fillColor: '#dc3545',
                    fillOpacity: 1,
                    strokeColor: '#fff',
                    strokeWeight: 2
                }
            });

            document.getElementById('pin-lat').value = latLng.lat().toFixed(6);
            document.getElementById('pin-lng').value = latLng.lng().toFixed(6);
            document.getElementById('pin-form-overlay').classList.add('show');

            // Allow dragging pin to reposition
            pinMarker.addListener('dragend', function(e) {
                document.getElementById('pin-lat').value = e.latLng.lat().toFixed(6);
                document.getElementById('pin-lng').value = e.latLng.lng().toFixed(6);
            });
        }

        function cancelPin() {
            document.getElementById('pin-form-overlay').classList.remove('show');
            if (pinMarker) {
                pinMarker.setMap(null);
                pinMarker = null;
            }
            document.getElementById('pin-form').reset();
        }

        function submitPin(e) {
            e.preventDefault();
            const lat = document.getElementById('pin-lat').value;
            const lng = document.getElementById('pin-lng').value;
            const issueType = document.getElementById('pin-issue-type').value;
            const severity = document.getElementById('pin-severity').value;
            const description = document.getElementById('pin-description').value;
            const name = document.getElementById('pin-name').value;

            if (!lat || !lng || !issueType || !severity) {
                alert('Please fill in all required fields.');
                return;
            }

            // Build Google Maps link for the pinned location
            const gmapsLink = `https://www.google.com/maps?q=${lat},${lng}`;
            const message = `Road Issue Pinned!\n\n` +
                `Type: ${issueType}\n` +
                `Severity: ${severity}\n` +
                `Location: ${lat}, ${lng}\n` +
                `Description: ${description || 'N none'}\n` +
                `Reported by: ${name || 'Anonymous'}\n\n` +
                `View on Google Maps:\n${gmapsLink}\n\n` +
                `To submit this report officially, please visit our Report page.`;

            alert(message);

            // Reset form and exit pinning mode
            cancelPin();
            togglePinningMode();
        }
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
