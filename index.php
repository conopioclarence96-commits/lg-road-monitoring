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



        /* Citizen Report Modal Styles */
        .modal-header.bg-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%) !important;
        }
        .modal-content {
            border: none;
            border-radius: 16px;
            overflow: hidden;
        }
        .modal-body {
            padding: 24px;
        }
        .citizen-report-map {
            height: 300px;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 12px;
            border: 2px dashed #ddd;
        }
        .citizen-report-map.has-pin {
            border-color: var(--accent-color);
        }
        .citizen-report-hint {
            text-align: center;
            color: #999;
            font-size: 0.85rem;
            margin-bottom: 16px;
        }
        .cr-form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }
        .cr-form-group {
            margin-bottom: 16px;
        }
        .cr-form-group label {
            display: block;
            font-weight: 600;
            color: var(--dark-text);
            margin-bottom: 6px;
            font-size: 0.9rem;
        }
        .cr-form-group select,
        .cr-form-group input,
        .cr-form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.95rem;
            font-family: inherit;
            transition: border-color 0.3s;
        }
        .cr-form-group select:focus,
        .cr-form-group input:focus,
        .cr-form-group textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30,60,114,0.1);
        }
        .cr-form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        .cr-verification-box {
            background: #f8f9ff;
            border: 1px solid #e0e4f0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .cr-verification-box h4 {
            font-size: 1rem;
            color: var(--primary-color);
            margin-bottom: 12px;
        }
        .cr-otp-row {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .cr-otp-row input {
            flex: 1;
            padding: 10px 14px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.95rem;
            font-family: inherit;
        }
        .cr-btn {
            padding: 10px 22px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s;
            font-family: inherit;
        }
        .cr-btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }
        .cr-btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(30,60,114,0.3);
        }
        .cr-btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        .cr-btn-secondary {
            background: #6c757d;
            color: white;
        }
        .cr-btn-secondary:hover {
            background: #5a6268;
        }
        .cr-btn-success {
            background: #28a745;
            color: white;
        }
        .cr-btn-success:hover {
            background: #218838;
        }
        .cr-btn-success:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .cr-btn-outline {
            background: transparent;
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
        }
        .cr-btn-outline:hover {
            background: var(--primary-color);
            color: white;
        }
        .cr-status {
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 0.9rem;
            margin-top: 10px;
            display: none;
        }
        .cr-status.success {
            display: block;
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .cr-status.error {
            display: block;
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .cr-status.info {
            display: block;
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        .photo-preview-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 10px;
        }
        .photo-preview-item {
            position: relative;
            width: 100px;
            height: 100px;
            border-radius: 8px;
            overflow: hidden;
            border: 2px solid #e0e0e0;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }
        .photo-preview-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .photo-delete-btn {
            position: absolute;
            top: 4px;
            right: 4px;
            width: 24px;
            height: 24px;
            background: rgba(220, 53, 69, 0.9);
            color: white;
            border: none;
            border-radius: 50%;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
            z-index: 5;
            line-height: 1;
        }
        .photo-delete-btn:hover {
            background: #dc3545;
            transform: scale(1.1);
        }
        .file-upload-area {
            position: relative;
            margin-bottom: 8px;
        }
        .file-upload-area input[type="file"] {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            border: 0;
        }
        .file-upload-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px 20px;
            border: 2px dashed #ccc;
            border-radius: 12px;
            background: #fafbfc;
            cursor: pointer;
            transition: all 0.3s;
        }
        .file-upload-label:hover {
            border-color: var(--primary-color);
            background: #f0f2f8;
        }
        .file-upload-label i {
            font-size: 2rem;
            color: var(--primary-color);
            margin-bottom: 8px;
        }
        .file-upload-text {
            font-size: 1rem;
            font-weight: 600;
            color: var(--primary-color);
        }
        .file-upload-hint {
            font-size: 0.8rem;
            color: #999;
            margin-top: 4px;
        }
        .file-count {
            display: block;
            font-size: 0.85rem;
            color: #666;
            margin-top: 6px;
            text-align: center;
        }
        @media (max-width: 768px) {
            .cr-form-row {
                grid-template-columns: 1fr;
            }
            .cr-otp-row {
                flex-direction: column;
            }
            .photo-preview-item {
                width: 80px;
                height: 80px;
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
                <button data-bs-toggle="modal" data-bs-target="#citizenReportModal" class="btn btn-primary-hero btn-hero">
                    <i class="fas fa-pen-alt"></i> Make a Report
                </button>
            </div>
        </div>
    </section>

    <!-- Citizen Report Modal -->
    <div class="modal fade" id="citizenReportModal" tabindex="-1" aria-labelledby="citizenReportModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="citizenReportModalLabel">
                        <i class="fas fa-pen-alt"></i> Report a Transportation Issue
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="citizen-report-map" id="citizenMap"></div>
                    <p class="citizen-report-hint">
                        <i class="fas fa-mouse-pointer"></i> Click on the map to pin the exact location of the issue
                        <br><small class="text-muted">Map is restricted to Quezon City area</small>
                    </p>

                    <form id="citizenReportForm">
                        <input type="hidden" name="latitude" id="crLat">
                        <input type="hidden" name="longitude" id="crLng">
                        <input type="hidden" name="address" id="crAddress">

                        <div class="cr-form-row">
                            <div class="cr-form-group">
                                <label><i class="fas fa-exclamation-triangle"></i> Issue Type <span class="text-danger">*</span></label>
                                <select name="issue_type" id="crIssueType" required>
                                    <option value="">-- Select Issue Type --</option>
                                    <option value="traffic_jam">Traffic Jam</option>
                                    <option value="accident">Vehicle Accident</option>
                                    <option value="road_closure">Road Closure</option>
                                    <option value="traffic_light_outage">Traffic Light Outage</option>
                                    <option value="congestion">Heavy Congestion</option>
                                    <option value="parking_violation">Illegal Parking</option>
                                    <option value="public_transport_issue">Public Transport Issue</option>
                                </select>
                            </div>
                            <div class="cr-form-group">
                                <label><i class="fas fa-exclamation-circle"></i> Severity <span class="text-danger">*</span></label>
                                <select name="severity" id="crSeverity" required>
                                    <option value="">-- Select Severity --</option>
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                    <option value="severe">Severe</option>
                                </select>
                            </div>
                        </div>

                        <div class="cr-form-group">
                            <label><i class="fas fa-user"></i> Reporter Name <span class="text-danger">*</span></label>
                            <input type="text" name="reporter_name" id="crName" required placeholder="Enter your full name">
                        </div>

                        <div class="cr-form-row">
                            <div class="cr-form-group">
                                <label><i class="fas fa-phone"></i> Phone Number <span class="text-danger">*</span></label>
                                <input type="tel" name="phone" id="crPhone" required placeholder="e.g. 09171234567" pattern="[0-9]{11,}" title="Please enter a valid phone number (at least 11 digits)">
                            </div>
                            <div class="cr-form-group">
                                <label><i class="fas fa-comment"></i> Description <span class="text-danger">*</span></label>
                                <textarea name="description" id="crDescription" rows="3" required placeholder="Describe what you observed..."></textarea>
                            </div>
                        </div>

                        <div class="cr-form-group">
                            <label><i class="fas fa-camera"></i> Add Photos <span class="text-danger">*</span></label>
                            <div class="file-upload-area">
                                <input type="file" name="photos[]" id="crPhotos" multiple accept="image/jpeg,image/jpg,image/png" required>
                                <label for="crPhotos" class="file-upload-label">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <span class="file-upload-text">Add Files</span>
                                    <span class="file-upload-hint">Click here to select multiple photos</span>
                                </label>
                                <span class="file-count" id="fileCount">No files selected</span>
                            </div>
                            <div id="photoPreview" class="photo-preview-grid"></div>
                            <small class="text-muted">Click <strong>Add Files</strong> to choose photos. You can select multiple at once. Click the <strong>X</strong> on a photo to remove it.</small>
                        </div>

                        <div class="cr-verification-box">
                            <h4><i class="fas fa-shield-alt"></i> Gmail Verification</h4>
                            <p style="font-size:0.85rem;color:#666;margin-bottom:12px;">
                                Enter your Gmail to receive a verification code. Limit of <strong>2 reports per day</strong>.
                            </p>
                            <div class="cr-otp-row">
                                <input type="email" id="crEmail" placeholder="your.email@gmail.com" required>
                                <button type="button" class="cr-btn cr-btn-primary" id="sendOtpBtn" onclick="sendOtp()"><i class="fas fa-paper-plane"></i> Send Code</button>
                            </div>
                            <div class="cr-otp-row" style="margin-top:10px;">
                                <input type="text" id="crOtp" placeholder="Enter 6-digit code" maxlength="6" inputmode="numeric" pattern="[0-9]*">
                                <button type="button" class="cr-btn cr-btn-success" id="verifyOtpBtn" onclick="verifyOtp()" disabled><i class="fas fa-check"></i> Verify</button>
                            </div>
                            <div id="crOtpStatus" class="cr-status"></div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="cr-btn cr-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="cr-btn cr-btn-primary" id="submitReportBtn" disabled form="citizenReportForm">
                        <i class="fas fa-paper-plane"></i> Submit Report
                    </button>
                </div>
            </div>
        </div>
    </div>

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

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

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


    <script src="lgu_staff/js/page-transition.js"></script>
    <?php include __DIR__ . '/includes/a11y_js.php'; ?>

    <script>
        const TOMTOM_API_KEY = '<?php echo TOMTOM_API_KEY; ?>';
        const CITIZEN_API = 'lgu_staff/pages/api/citizen_report.php';

        let citizenMap = null;
        let citizenPin = null;
        let otpVerified = false;
        let photoFiles = [];

        const QC_BOUNDS = [[14.58, 120.92], [14.78, 121.12]];
        const QC_CENTER = [14.6500, 121.0500];

        document.getElementById('citizenReportModal').addEventListener('shown.bs.modal', function () {
            if (!citizenMap) {
                initCitizenMap();
            }
            setTimeout(() => citizenMap?.invalidateSize(), 300);
        });

        document.getElementById('citizenReportModal').addEventListener('hidden.bs.modal', function () {
            resetCitizenForm();
        });

        function initCitizenMap() {
            citizenMap = L.map('citizenMap', {
                maxBounds: QC_BOUNDS,
                maxBoundsViscosity: 1.0,
                minZoom: 12
            }).setView(QC_CENTER, 13);

            L.tileLayer('https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=' + TOMTOM_API_KEY, {
                attribution: '&copy; TomTom',
                maxZoom: 18
            }).addTo(citizenMap);

            L.rectangle(QC_BOUNDS, {
                color: '#2a5298',
                weight: 2,
                fill: false,
                dashArray: '5, 10',
                opacity: 0.7
            }).addTo(citizenMap);

            citizenMap.on('click', function(e) {
                const { lat, lng } = e.latlng;
                placeCitizenPin(lat, lng);
            });
        }

        function placeCitizenPin(lat, lng) {
            if (citizenPin) citizenMap.removeLayer(citizenPin);
            citizenPin = L.marker([lat, lng], { draggable: true }).addTo(citizenMap);
            document.getElementById('crLat').value = lat.toFixed(6);
            document.getElementById('crLng').value = lng.toFixed(6);
            document.getElementById('citizenMap').classList.add('has-pin');

            TomTomServices?.reverseGeocode(lat, lng).then(data => {
                if (data?.success && data?.data?.address?.freeformAddress) {
                    document.getElementById('crAddress').value = data.data.address.freeformAddress;
                }
            }).catch(() => {});

            citizenPin.on('dragend', function() {
                const pos = citizenPin.getLatLng();
                document.getElementById('crLat').value = pos.lat.toFixed(6);
                document.getElementById('crLng').value = pos.lng.toFixed(6);
            });
        }

        document.getElementById('crPhotos').addEventListener('change', function(e) {
            const files = Array.from(e.target.files);
            files.forEach(file => {
                if (!photoFiles.some(f => f.name === file.name && f.size === file.size && f.lastModified === file.lastModified)) {
                    photoFiles.push(file);
                }
            });
            renderPhotoPreviews();
            document.getElementById('fileCount').textContent = photoFiles.length + ' file(s) selected';
            this.value = '';
        });

        function renderPhotoPreviews() {
            const container = document.getElementById('photoPreview');
            container.innerHTML = '';
            photoFiles.forEach((file, index) => {
                const reader = new FileReader();
                const wrapper = document.createElement('div');
                wrapper.className = 'photo-preview-item';
                wrapper.innerHTML = '<button type="button" class="photo-delete-btn" data-index="' + index + '">&times;</button>';
                const img = document.createElement('img');
                reader.onload = function(e) {
                    img.src = e.target.result;
                };
                reader.readAsDataURL(file);
                wrapper.prepend(img);
                container.appendChild(wrapper);
            });

            document.getElementById('fileCount').textContent = photoFiles.length + ' file(s) selected';

            document.querySelectorAll('.photo-delete-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const idx = parseInt(this.dataset.index);
                    photoFiles.splice(idx, 1);
                    renderPhotoPreviews();
                    document.getElementById('crPhotos').required = photoFiles.length === 0;
                    document.getElementById('fileCount').textContent = photoFiles.length + ' file(s) selected';
                });
            });
        }

        function sendOtp() {
            const email = document.getElementById('crEmail').value.trim();
            if (!email || !email.includes('@')) {
                showCrStatus('Please enter a valid email address.', 'error');
                return;
            }
            if (!email.toLowerCase().endsWith('@gmail.com')) {
                showCrStatus('Please use a Gmail address (@gmail.com) for verification.', 'error');
                return;
            }

            const btn = document.getElementById('sendOtpBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

            const fd = new FormData();
            fd.append('action', 'send_otp');
            fd.append('email', email);

            fetch(CITIZEN_API, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Code';
                    if (data.success) {
                        showCrStatus(data.message, 'success');
                        document.getElementById('verifyOtpBtn').disabled = false;
                        document.getElementById('crOtp').focus();
                    } else {
                        showCrStatus(data.message, 'error');
                    }
                })
                .catch(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Code';
                    showCrStatus('Failed to send code. Please try again.', 'error');
                });
        }

        function verifyOtp() {
            const otp = document.getElementById('crOtp').value.trim();
            if (!otp || otp.length < 6) {
                showCrStatus('Please enter the 6-digit verification code.', 'error');
                return;
            }

            const btn = document.getElementById('verifyOtpBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';

            const fd = new FormData();
            fd.append('action', 'verify_otp');
            fd.append('otp', otp);

            fetch(CITIZEN_API, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check"></i> Verify';
                    if (data.success) {
                        otpVerified = true;
                        showCrStatus('Email verified! You can now submit your report.', 'success');
                        document.getElementById('submitReportBtn').disabled = false;
                        document.getElementById('crEmail').readOnly = true;
                        document.getElementById('sendOtpBtn').disabled = true;
                        document.getElementById('verifyOtpBtn').disabled = true;
                    } else {
                        showCrStatus(data.message, 'error');
                    }
                })
                .catch(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check"></i> Verify';
                    showCrStatus('Verification failed. Please try again.', 'error');
                });
        }

        document.getElementById('citizenReportForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const errors = [];

            const lat = document.getElementById('crLat').value;
            const lng = document.getElementById('crLng').value;
            if (!lat || !lng) errors.push('Please pin a location on the map.');

            const issueType = document.getElementById('crIssueType').value;
            if (!issueType) errors.push('Please select an issue type.');

            const severity = document.getElementById('crSeverity').value;
            if (!severity) errors.push('Please select a severity level.');

            const name = document.getElementById('crName').value.trim();
            if (!name) errors.push('Please enter your full name.');

            const phone = document.getElementById('crPhone').value.trim();
            if (!phone) errors.push('Please enter your phone number.');
            else if (!/^[0-9]{11,}$/.test(phone)) errors.push('Please enter a valid phone number (at least 11 digits).');

            const desc = document.getElementById('crDescription').value.trim();
            if (!desc) errors.push('Please describe the issue.');

            if (photoFiles.length === 0) errors.push('Please upload at least one photo.');

            if (!otpVerified) errors.push('Please verify your email first.');

            if (errors.length > 0) {
                showCrStatus(errors.join('<br>'), 'error');
                return;
            }

            const btn = document.getElementById('submitReportBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

            const fd = new FormData();
            fd.append('latitude', document.getElementById('crLat').value);
            fd.append('longitude', document.getElementById('crLng').value);
            fd.append('address', document.getElementById('crAddress').value);
            fd.append('issue_type', document.getElementById('crIssueType').value);
            fd.append('severity', document.getElementById('crSeverity').value);
            fd.append('reporter_name', document.getElementById('crName').value.trim());
            fd.append('phone', document.getElementById('crPhone').value.trim());
            fd.append('description', document.getElementById('crDescription').value.trim());

            photoFiles.forEach(file => {
                fd.append('photos[]', file);
            });

            fd.append('action', 'submit_report');

            fetch(CITIZEN_API, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Report';
                    if (data.success) {
                        showCrStatus(data.message, 'success');
                        setTimeout(() => {
                            const modal = bootstrap.Modal.getInstance(document.getElementById('citizenReportModal'));
                            if (modal) modal.hide();
                            resetCitizenForm();
                        }, 2000);
                    } else {
                        showCrStatus(data.message, 'error');
                    }
                })
                .catch(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Report';
                    showCrStatus('Submission failed. Please try again.', 'error');
                });
        });

        function showCrStatus(msg, type) {
            const el = document.getElementById('crOtpStatus');
            el.innerHTML = msg;
            el.className = 'cr-status ' + type;
            el.style.display = 'block';
        }

        function resetCitizenForm() {
            document.getElementById('citizenReportForm').reset();
            document.getElementById('crOtpStatus').style.display = 'none';
            document.getElementById('submitReportBtn').disabled = true;
            document.getElementById('verifyOtpBtn').disabled = true;
            document.getElementById('sendOtpBtn').disabled = false;
            document.getElementById('crEmail').readOnly = false;
            document.getElementById('citizenMap').classList.remove('has-pin');
            document.getElementById('crAddress').value = '';
            document.getElementById('photoPreview').innerHTML = '';
            photoFiles = [];
            document.getElementById('crPhotos').required = true;
            document.getElementById('fileCount').textContent = 'No files selected';
            otpVerified = false;
            if (citizenPin) { citizenMap.removeLayer(citizenPin); citizenPin = null; }
        }
    </script>
</body>
</html>
