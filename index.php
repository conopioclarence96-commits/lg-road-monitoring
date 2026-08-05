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

// Get upcoming/ongoing road projects synced from IPMS (see
// lgu_staff/pages/api/ipms-road-projects-pull.php for the poller that keeps
// this cache fresh, and ipms_road_projects_data.php for the schema). This is
// IPMS's own project data, kept intentionally separate from this app's
// citizen-reported incident tables.
require_once __DIR__ . '/lgu_staff/pages/api/ipms_road_projects_data.php';
$ipms_road_projects = [];
if ($database_available && $conn) {
    try {
        $ipms_road_projects = rgmap_fetch_ipms_road_projects(rgmap_ipms_pdo());
    } catch (Exception $e) {
        $ipms_road_projects = [];
    }
}

// Display metadata (badge label/class, human status) for an IPMS project
// status. IPMS sends the raw status string (approved/bidding/active/... —
// see ipms-road-projects-pull.php doc comment for the full list); this just
// maps it to something citizen-friendly.
function ipms_status_meta(string $status): array {
    $map = [
        'approved'              => ['label' => 'Upcoming — Approved',        'class' => 'rp-upcoming'],
        'bidding'               => ['label' => 'Upcoming — Bidding',         'class' => 'rp-upcoming'],
        'awarded'               => ['label' => 'Upcoming — Awarded',        'class' => 'rp-upcoming'],
        'assigned'              => ['label' => 'Upcoming — Assigned',       'class' => 'rp-upcoming'],
        'active'                => ['label' => 'Ongoing',                    'class' => 'rp-active'],
        'delayed'               => ['label' => 'Ongoing — Delayed',         'class' => 'rp-delayed'],
        'on_hold'               => ['label' => 'On Hold',                    'class' => 'rp-on_hold'],
        'completion_inspection' => ['label' => 'Final Inspection',          'class' => 'rp-completion_inspection'],
    ];
    return $map[$status] ?? ['label' => ucfirst(str_replace('_', ' ', $status)), 'class' => 'rp-upcoming'];
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
    <?php include __DIR__ . '/includes/a11y_head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Road and Transportation Department Monitoring System</title>
    <link rel="icon" type="image/png" href="assets/img/logocityhall.png">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Transition CSS -->
    <link rel="stylesheet" href="styles/transition.css">
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <!-- Turf.js for point-in-polygon -->
    <script src="https://cdn.jsdelivr.net/npm/@turf/turf@6/turf.min.js"></script>
    
    <style>
        :root {
            /* QC E-Services palette mapped to existing variable names
               (shared components such as the side menu / a11y read these) */
            --primary-color: #115272;
            --secondary-color: #1d698b;
            --accent-color: #d93939;
            --light-bg: #f4f6f7;
            --dark-text: #3e454c;

            /* Full QC E-Services color palette */
            --qc-primary-50: #f1f9fe;
            --qc-primary-100: #e3f1fb;
            --qc-primary-200: #c0e5f7;
            --qc-primary-300: #88d1f1;
            --qc-primary-400: #49b9e7;
            --qc-primary-500: #21a1d6;
            --qc-primary-600: #1381b6;
            --qc-primary-700: #116893;
            --qc-primary-800: #115272;
            --qc-primary-900: #154a65;
            --qc-primary-950: #0e2f43;

            --qc-shades-50: #f4f6f7;
            --qc-shades-100: #e3e7ea;
            --qc-shades-200: #cbd3d6;
            --qc-shades-300: #a6b4ba;
            --qc-shades-400: #7a8c96;
            --qc-shades-500: #5f717b;
            --qc-shades-600: #515f69;
            --qc-shades-700: #465058;
            --qc-shades-800: #3e454c;
            --qc-shades-900: #373d42;
            --qc-shades-950: #212529;

            --qc-icon-bg: #d6e9f8;
            --qc-card-border: #dbe7f0;
            --qc-red: #d93939;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Montserrat', -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
            color: var(--dark-text);
            line-height: 1.6;
            background-color: #ffffff;
        }

        h1, h2, h3, h4, h5, h6 {
            font-family: 'Montserrat', -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
        }

        .container {
            max-width: 1320px;
        }

        section[id] {
            scroll-margin-top: 86px;
        }

        /* QC E-Services style buttons */
        .btn-qc {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 8px;
            font-weight: 700;
            transition: all 0.2s ease;
        }
        .btn-qc-primary {
            background: var(--qc-primary-950);
            border: 1px solid var(--qc-primary-950);
            color: #fff;
        }
        .btn-qc-primary:hover {
            background: var(--qc-primary-500);
            border-color: var(--qc-primary-500);
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 6px 14px rgba(17, 82, 114, 0.3);
        }
        .btn-qc-outline {
            background: transparent;
            border: 2px solid #fff;
            color: #fff;
        }
        .btn-qc-outline:hover {
            background: #fff;
            color: var(--qc-primary-800);
            transform: translateY(-2px);
        }

        /* Navigation — QC E-Services white header */
        .qc-navbar {
            background: #ffffff !important;
            border-bottom: 1px solid var(--qc-shades-100);
            box-shadow: 0 1px 3px rgba(17, 82, 114, 0.06);
            padding: 0.55rem 0;
        }

        .qc-navbar.scrolled {
            box-shadow: 0 4px 16px rgba(17, 82, 114, 0.12);
        }

        /* Hamburger button override for the white navbar */
        .qc-navbar .container-fluid { padding-right: 76px; }
        .hamburger-btn {
            top: 16px !important;
            right: 18px !important;
            width: 42px !important;
            height: 42px !important;
            border: 2px solid rgba(17, 82, 114, 0.25) !important;
            background: rgba(17, 82, 114, 0.06) !important;
            box-shadow: 0 4px 12px rgba(17, 82, 114, 0.12) !important;
        }

        .hamburger-btn:hover {
            background: rgba(17, 82, 114, 0.14) !important;
        }

        .hamburger-btn .bar {
            background: var(--qc-primary-800) !important;
        }

        .qc-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            padding: 0;
        }

        .qc-brand img {
            height: 46px;
            width: auto;
            border-radius: 6px;
        }

        .qc-brand-text {
            line-height: 1.15;
            text-align: left;
        }

        .qc-brand-text strong {
            display: block;
            font-size: 1.02rem;
            font-weight: 800;
            color: var(--qc-primary-800);
        }

        .qc-brand-text small {
            display: block;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            color: var(--qc-primary-600);
        }

        .qc-nav-links {
            gap: 4px;
        }

        .qc-nav-links .nav-link {
            color: #38414a;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            padding: 0.5rem 0.7rem;
            border-radius: 6px;
            transition: color 0.2s ease, background-color 0.2s ease;
        }

        .qc-nav-links .nav-link:hover,
        .qc-nav-links .nav-link.active {
            color: var(--qc-primary-800);
            background-color: var(--qc-primary-50);
        }

        .qc-login-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--qc-primary-800);
            color: #fff !important;
            font-weight: 700;
            font-size: 14px;
            text-transform: none;
            letter-spacing: normal;
            border-radius: 8px;
            padding: 10px 22px !important;
            margin-left: 8px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 10px rgba(17, 82, 114, 0.22);
        }

        .qc-login-btn:hover {
            background: var(--qc-primary-600);
            color: #fff !important;
            transform: translateY(-2px);
            box-shadow: 0 6px 14px rgba(17, 82, 114, 0.3);
        }

        .btn-login {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--qc-primary-800);
            color: #fff;
            border: none;
            padding: 10px 22px;
            border-radius: 8px;
            font-weight: 700;
            transition: all 0.2s ease;
        }

        .btn-login:hover {
            background: var(--qc-primary-600);
            color: #fff;
            transform: translateY(-2px);
        }

        /* Hero Section — QC E-Services style */
        .hero {
            position: relative;
            background:
                linear-gradient(115deg, rgba(11, 42, 62, 0.96) 0%, rgba(17, 82, 114, 0.9) 55%, rgba(19, 129, 182, 0.78) 100%),
                url('assets/img/cityhall.jpeg') center/cover no-repeat;
            color: #fff;
            padding: 170px 0 120px;
        }

        .hero-eyebrow {
            font-size: 0.95rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--qc-primary-200);
            margin-bottom: 0.6rem;
        }

        .hero h1 {
            font-size: 3rem;
            font-weight: 800;
            margin-bottom: 18px;
            line-height: 1.15;
            color: #fff;
            text-shadow: 0 2px 12px rgba(0, 0, 0, 0.25);
        }

        .hero p.lead {
            font-size: 1.12rem;
            font-weight: 400;
            margin-bottom: 30px;
            max-width: 640px;
            color: rgba(255, 255, 255, 0.92);
        }

        .hero-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .btn-hero {
            padding: 13px 26px;
            font-size: 0.98rem;
            font-weight: 700;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .btn-primary-hero {
            background: var(--qc-primary-500);
            border: none;
            color: #fff;
        }

        .btn-primary-hero:hover {
            background: var(--qc-primary-400);
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.25);
        }

        .btn-secondary-hero {
            background: transparent;
            border: 2px solid rgba(255, 255, 255, 0.85);
            color: #fff;
        }

        .btn-secondary-hero:hover {
            background: #fff;
            color: var(--qc-primary-800);
            transform: translateY(-2px);
        }

        #makeReportBtn.btn-hero {
            background: var(--accent-color);
            border: none;
            color: #fff;
        }

        #makeReportBtn.btn-hero:hover {
            background: #c02a2a;
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.25);
        }

        /* Section Styles */
        .section {
            padding: 70px 0 60px;
            background: #ffffff;
        }

        .section-title {
            text-align: center;
            font-size: 1.7rem;
            font-weight: 800;
            color: var(--qc-primary-800);
            margin-bottom: 12px;
            line-height: 1.25;
        }

        .section-title::after {
            content: '';
            display: block;
            width: 56px;
            height: 4px;
            margin: 12px auto 0;
            border-radius: 4px;
            background: var(--qc-primary-500);
        }

        .section-subtitle {
            text-align: center;
            font-size: 1.02rem;
            color: var(--qc-shades-500);
            margin-bottom: 48px;
            max-width: 620px;
            margin-left: auto;
            margin-right: auto;
        }

        /* Road Updates Cards — flat QC style */
        .update-card {
            border: 1px solid var(--qc-card-border);
            border-radius: 12px;
            box-shadow: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            height: 100%;
            overflow: hidden;
            background: #fff;
        }

        .update-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 24px rgba(17, 82, 114, 0.1);
        }

        .update-card .card-header {
            background: #ffffff;
            border-bottom: 1px solid #eef3f6;
            color: var(--qc-primary-900);
            border-radius: 12px 12px 0 0 !important;
            font-weight: 700;
            font-size: 1.02rem;
            line-height: 1.35;
            padding: 16px 118px 14px 20px;
        }

        .update-badge {
            position: absolute;
            top: 14px;
            right: 14px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .badge-maintenance {
            background: #fff3cd;
            color: #8a6d1a;
        }

        .badge-advisory {
            background: #d6e9f8;
            color: #0f4762;
        }

        .badge-closure {
            background: #fde3e3;
            color: #951f1f;
        }

        /* Statistics Cards — flat QC style */
        .stat-card {
            background: #ffffff;
            border: 1px solid var(--qc-card-border);
            border-radius: 12px;
            padding: 28px 18px;
            text-align: center;
            box-shadow: none;
            height: 100%;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 24px rgba(17, 82, 114, 0.1);
        }

        .stat-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 16px;
            background: var(--qc-icon-bg);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.55rem;
            color: var(--qc-primary-800);
        }

        .stat-number {
            font-size: 2.2rem;
            font-weight: 800;
            color: var(--qc-primary-800);
            margin-bottom: 6px;
        }

        .stat-label {
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: var(--qc-shades-500);
        }

        /* Quick Access / Service Cards — QC E-Services style */
        .qc-quick-section {
            padding: 46px 0 54px;
            background: #ffffff;
        }

        .service-card {
            background: #ffffff;
            border: 1px solid var(--qc-primary-300);
            border-radius: 12px;
            padding: 1rem 0.5rem;
            text-align: center;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-around;
            text-decoration: none;
            color: #1F2937;
            min-height: 120px;
            box-shadow: none;
            font-family: inherit;
            width: 100%;
        }

        .service-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 16px rgba(17, 82, 114, 0.12);
            text-decoration: none;
            color: #1F2937;
        }

        .service-icon {
            width: 72px;
            height: 72px;
            background-color: var(--qc-icon-bg);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.75rem;
            flex-shrink: 0;
        }

        .service-icon i {
            font-size: 1.8rem;
            color: var(--qc-primary-800);
        }

        .service-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--qc-primary-900);
            margin: 0;
            line-height: 1.25;
            text-align: center;
        }

        .qc-service-more {
            background: var(--qc-primary-700);
            border-color: var(--qc-primary-700);
        }

        .qc-service-more .service-icon {
            background: rgba(255, 255, 255, 0.16);
        }

        .qc-service-more .service-icon i {
            color: #fff;
        }

        .qc-service-more .service-title {
            color: #fff;
        }

        /* Report Form */
        .report-form {
            background: #ffffff;
            border-radius: 12px;
            padding: 40px;
            border: 1px solid var(--qc-card-border);
            box-shadow: 0 8px 24px rgba(17, 82, 114, 0.08);
        }

        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #cbd3d6;
            padding: 11px 14px;
            font-family: 'Montserrat', sans-serif;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--qc-primary-500);
            box-shadow: 0 0 0 0.2rem rgba(33, 161, 214, 0.2);
        }

        /* Contact Section */
        .contact-section {
            background: var(--light-bg);
        }

        .contact-info {
            text-align: center;
            padding: 20px;
        }

        .contact-icon {
            width: 72px;
            height: 72px;
            margin: 0 auto 16px;
            background: var(--qc-icon-bg);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.7rem;
            color: var(--qc-primary-800);
        }

        .contact-info h4 {
            font-weight: 700;
            color: var(--qc-primary-900);
            margin-bottom: 10px;
        }

        .contact-info p {
            color: var(--qc-shades-500);
            margin-bottom: 0;
        }

        /* Footer — QC E-Services style */
        footer.qc-footer {
            background: linear-gradient(135deg, var(--qc-primary-800) 0%, #1d698b 100%);
            color: #fff;
            padding: 34px 0 20px;
        }

        footer.qc-footer a {
            color: #fff;
            text-decoration: none;
        }

        .footer-top-row {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
        }

        .footer-follow-label {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 8px;
            color: rgba(255, 255, 255, 0.95);
        }

        .footer-social-row {
            display: flex;
            gap: 10px;
        }

        .footer-social-circle {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.92);
            color: #165b79;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            transition: transform 0.2s ease, color 0.2s ease;
        }

        .footer-social-circle:hover {
            transform: translateY(-2px);
            color: #0e2f43;
        }

        .footer-contact-row {
            display: flex;
            align-items: center;
            gap: 14px;
            font-size: 14px;
        }

        .footer-contact-item {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #fff;
        }

        .footer-contact-item:hover {
            color: #fff;
        }

        .footer-contact-item i {
            color: #fff;
            font-size: 15px;
        }

        .contact-separator {
            width: 1px;
            height: 18px;
            background: rgba(255, 255, 255, 0.4);
        }

        .footer-links-row {
            display: flex;
            flex-wrap: wrap;
            gap: 22px;
        }

        .footer-links-row a {
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.2px;
        }

        .footer-links-row a:hover {
            color: #eaf3f9;
            text-decoration: underline;
        }

        .footer-divider {
            height: 1px;
            background: rgba(255, 255, 255, 0.34);
            margin: 22px 0 14px;
        }

        .footer-bottom-row {
            text-align: center;
        }

        .footer-copyright {
            font-size: 13px;
            color: rgba(244, 248, 251, 0.85);
        }

        .footer-copyright i {
            margin-right: 4px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .hero {
                padding: 130px 0 90px;
            }

            .hero h1 {
                font-size: 2.1rem;
            }

            .hero p.lead {
                font-size: 1rem;
            }

            .section {
                padding: 54px 0 46px;
            }

            .section-title {
                font-size: 1.4rem;
            }

            .stat-number {
                font-size: 1.9rem;
            }

            .footer-top-row {
                flex-direction: column;
                text-align: center;
            }

            .footer-social-row {
                justify-content: center;
            }

            .footer-contact-row {
                justify-content: center;
                flex-wrap: wrap;
            }

            .footer-links-row {
                justify-content: center;
                gap: 16px;
            }
        }

        /* Before & After Projects Section */
        .before-after-section {
            background: var(--light-bg);
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
            border: 1px solid var(--qc-card-border);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .before-after-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 24px rgba(17, 82, 114, 0.1);
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
            color: var(--qc-primary-800);
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
            background: rgba(217, 57, 57, 0.92);
            color: white;
        }

        .label-after {
            right: 12px;
            background: rgba(40, 167, 69, 0.92);
            color: white;
        }

        .before-after-info {
            padding: 20px 24px;
        }

        .before-after-info h4 {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--qc-primary-900);
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
            color: var(--qc-shades-500);
        }

        .before-after-meta i {
            color: var(--qc-primary-500);
            margin-right: 4px;
        }

        .before-after-cost {
            display: inline-block;
            background: var(--qc-primary-800);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .before-after-empty {
            text-align: center;
            padding: 60px 20px;
            color: var(--qc-shades-400);
        }

        .before-after-empty i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: var(--qc-shades-200);
        }

        /* Upcoming & Ongoing Road Projects Section (IPMS feed) */
        #roadProjectsMap {
            height: 420px;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--qc-card-border);
            box-shadow: 0 8px 24px rgba(17, 82, 114, 0.08);
            margin-bottom: 30px;
        }

        /* GIS Map Wrapper & Toolbar */
        .gis-map-wrapper {
            position: relative;
            margin-bottom: 30px;
        }
        .gis-map-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            padding: 10px 16px;
            background: #fff;
            border: 1px solid var(--qc-card-border);
            border-bottom: none;
            border-radius: 12px 12px 0 0;
            box-shadow: 0 2px 12px rgba(17, 82, 114, 0.06);
            z-index: 10;
            position: relative;
        }
        .gis-toolbar-left, .gis-toolbar-right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .gis-map-legend {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 12px;
            color: var(--qc-shades-500);
        }
        .gis-legend-item {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }
        .gis-legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            flex-shrink: 0;
        }
        .gis-map-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            border: 1px solid rgba(17, 82, 114, 0.3);
            background: rgba(17, 82, 114, 0.06);
            color: var(--qc-primary-800);
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        .gis-map-btn:hover {
            background: var(--qc-primary-800);
            border-color: var(--qc-primary-800);
            color: #fff;
        }
        .gis-map-btn.active-toggle {
            background: rgba(17, 82, 114, 0.15);
            color: var(--qc-primary-800);
        }
        .gis-map-btn.inactive-toggle {
            background: #6c757d;
            color: #fff;
            border-color: #6c757d;
        }
        .gis-map-search-box {
            position: relative;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .gis-search-input {
            padding: 6px 10px;
            border: 1px solid rgba(17, 82, 114, 0.3);
            border-radius: 8px;
            font-size: 12px;
            width: 170px;
            outline: none;
            transition: border-color 0.2s;
        }
        .gis-search-input:focus {
            border-color: var(--qc-primary-500);
            box-shadow: 0 0 0 2px rgba(33, 161, 214, 0.15);
        }
        .gis-search-btn {
            padding: 6px 10px;
        }
        .gis-search-results {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid var(--qc-card-border);
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(17, 82, 114, 0.12);
            z-index: 1000;
            max-height: 250px;
            overflow-y: auto;
            margin-top: 4px;
        }
        .gis-search-result-item {
            padding: 10px 12px;
            cursor: pointer;
            font-size: 13px;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.15s;
        }
        .gis-search-result-item:last-child { border-bottom: none; }
        .gis-search-result-item:hover { background: #eaf3f9; }
        .gis-search-result-item small { display: block; color: var(--qc-shades-400); font-size: 11px; margin-top: 2px; }
        .gis-dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            background: #fff;
            border: 1px solid var(--qc-card-border);
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(17, 82, 114, 0.12);
            z-index: 1000;
            min-width: 200px;
            padding: 6px 0;
            margin-top: 4px;
        }
        .gis-dropdown-item {
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            padding: 9px 16px;
            border: none;
            background: none;
            font-size: 13px;
            color: #333;
            cursor: pointer;
            text-align: left;
            transition: background 0.15s;
        }
        .gis-dropdown-item:hover { background: #eaf3f9; color: var(--qc-primary-800); }
        .gis-dropdown-item i { width: 16px; text-align: center; color: var(--qc-primary-800); }
        .gis-district-tooltip {
            background: var(--qc-primary-800);
            color: #fff;
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 500;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            border: none;
        }
        .gis-district-tooltip::before { border-top-color: var(--qc-primary-800); }

        /* Fullscreen mode */
        body.gis-map-fullscreen-active .gis-map-wrapper {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            z-index: 9999;
            border-radius: 0;
            margin: 0;
        }
        body.gis-map-fullscreen-active .gis-map-toolbar {
            border-radius: 0;
            position: fixed;
            top: 0; left: 0; right: 0;
            z-index: 10000;
        }
        body.gis-map-fullscreen-active #roadProjectsMap {
            position: fixed;
            top: 52px; left: 0; right: 0; bottom: 0;
            height: auto;
            border-radius: 0;
            z-index: 9999;
        }

        .road-projects-empty {
            text-align: center;
            padding: 60px 20px;
            color: var(--qc-shades-400);
        }

        .road-projects-empty i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: var(--qc-shades-200);
        }

        .road-projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }

        .road-project-card {
            background: white;
            border: 1px solid var(--qc-card-border);
            border-radius: 12px;
            padding: 20px;
            box-shadow: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            cursor: pointer;
        }

        .road-project-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 24px rgba(17, 82, 114, 0.1);
        }

        .road-project-card h4 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--qc-primary-900);
            margin-bottom: 8px;
        }

        .rp-status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            color: white;
            margin-bottom: 10px;
        }

        .rp-status-badge.rp-upcoming { background: #2196f3; }
        .rp-status-badge.rp-active { background: #28a745; }
        .rp-status-badge.rp-delayed { background: #dc3545; }
        .rp-status-badge.rp-on_hold { background: #6c757d; }
        .rp-status-badge.rp-completion_inspection { background: #17a2b8; }

        .rp-meta {
            font-size: 0.85rem;
            color: var(--qc-shades-500);
            margin-bottom: 4px;
        }

        .rp-meta i {
            color: var(--qc-primary-500);
            width: 16px;
            text-align: center;
            margin-right: 4px;
        }

        .rp-progress-track {
            background: #eef1f3;
            border-radius: 10px;
            height: 8px;
            overflow: hidden;
            margin: 10px 0 6px;
        }

        .rp-progress-fill {
            height: 100%;
            background: var(--qc-primary-800);
        }

        .rp-progress-label {
            font-size: 0.75rem;
            color: var(--qc-shades-400);
        }

        /* Citizen Report Modal Styles */
        .modal-header.bg-primary {
            background: var(--qc-primary-800) !important;
            border-bottom: none;
        }
        .modal-content {
            border: 1px solid var(--qc-card-border);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 12px 32px rgba(17, 82, 114, 0.15);
        }
        .modal-body {
            padding: 24px;
        }
        .citizen-report-map {
            height: 300px;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 12px;
            border: 2px dashed var(--qc-shades-300);
        }
        .citizen-report-map.has-pin {
            border-color: var(--accent-color);
        }
        .citizen-report-hint {
            text-align: center;
            color: var(--qc-shades-400);
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
            color: var(--qc-primary-900);
            margin-bottom: 6px;
            font-size: 0.9rem;
        }
        .cr-form-group select,
        .cr-form-group input,
        .cr-form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #cbd3d6;
            border-radius: 8px;
            font-size: 0.95rem;
            font-family: inherit;
            transition: border-color 0.3s;
        }
        .cr-form-group select:focus,
        .cr-form-group input:focus,
        .cr-form-group textarea:focus {
            outline: none;
            border-color: var(--qc-primary-500);
            box-shadow: 0 0 0 3px rgba(33, 161, 214, 0.15);
        }
        .cr-form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        .cr-verification-box {
            background: #f4f8fb;
            border: 1px solid var(--qc-card-border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .cr-verification-box h4 {
            font-size: 1rem;
            color: var(--qc-primary-900);
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
            border: 1px solid #cbd3d6;
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
            transition: all 0.2s;
            font-family: inherit;
        }
        .cr-btn-primary {
            background: var(--qc-primary-800);
            color: white;
        }
        .cr-btn-primary:hover {
            background: var(--qc-primary-950);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(17, 82, 114, 0.25);
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
            border: 2px solid var(--qc-primary-800);
            color: var(--qc-primary-800);
        }
        .cr-btn-outline:hover {
            background: var(--qc-primary-800);
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
        .cr-form-group .field-error {
            display: none;
            color: #dc3545;
            font-size: 0.82rem;
            margin-top: 4px;
        }
        .cr-form-group .field-error.show {
            display: block;
        }
        .cr-form-group input.error {
            border-color: #dc3545;
        }
        .cr-form-group input.error:focus {
            border-color: #dc3545;
            box-shadow: 0 0 0 3px rgba(220,53,69,0.15);
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
    <?php include __DIR__ . '/includes/hamburger_menu_css.php'; ?>
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
        .restricted-card h2 { font-size: 24px; color: #115272; margin-bottom: 10px; }
        .restricted-card p { color: #666; margin-bottom: 25px; line-height: 1.6; }
        .restricted-card .btn-login {
            display: inline-block; background: #115272; color: white;
            padding: 12px 32px; border-radius: 8px; text-decoration: none;
            font-weight: 700; transition: all 0.2s;
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
    <nav class="navbar navbar-light fixed-top qc-navbar">
        <div class="container-fluid">
            <a class="navbar-brand qc-brand" href="#home">
                <img src="assets/img/logocityhall.png" alt="Quezon City Hall Logo">
                <span class="qc-brand-text">
                    <strong>Road &amp; Transportation Department</strong>
                    <small>Quezon City Government</small>
                </span>
            </a>
        </div>
    </nav>

    <?php include __DIR__ . '/includes/hamburger_menu.php'; ?>

    <!-- Hero Section -->
    <section class="hero" id="home" <?php echo ($access_settings['hide_hero'] ?? '0') === '1' ? 'style="display:none"' : ''; ?>>
        <div class="container">
            <p class="hero-eyebrow"><i class="fas fa-road"></i> Road &amp; Transportation Department</p>
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
                <button id="makeReportBtn" class="btn btn-primary-hero btn-hero">
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

                    <form id="citizenReportForm" enctype="multipart/form-data">
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
                                <input type="tel" name="phone" id="crPhone" required placeholder="0917 123 4567 or +639 17 123 4567" inputmode="numeric" autocomplete="tel">
                                <div class="field-error" id="crPhoneError">Please enter a valid Philippine mobile number.</div>
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

    <!-- Upcoming & Ongoing Road Projects Section (IPMS feed) -->
    <section class="section" id="road-projects" <?php echo ($access_settings['hide_road_projects'] ?? '0') === '1' ? 'style="display:none"' : ''; ?>>
        <div class="container">
            <h2 class="section-title">Upcoming &amp; Ongoing Road Projects</h2>
            <p class="section-subtitle">See which roads are about to be, or currently being, worked on across the city</p>

            <?php if (!empty($ipms_road_projects)): ?>
            <div class="gis-map-wrapper">
                <div class="gis-map-toolbar">
                    <div class="gis-toolbar-left">
                        <div class="gis-map-legend">
                            <span class="gis-legend-item"><span class="gis-legend-dot" style="background:#28a745;"></span> Active</span>
                            <span class="gis-legend-item"><span class="gis-legend-dot" style="background:#dc3545;"></span> Delayed</span>
                            <span class="gis-legend-item"><span class="gis-legend-dot" style="background:#6c757d;"></span> On Hold</span>
                            <span class="gis-legend-item"><span class="gis-legend-dot" style="background:#17a2b8;"></span> Final Inspection</span>
                        </div>
                        <div class="gis-map-search-box">
                            <input type="text" id="gisMapSearchInput" placeholder="Search places..." class="gis-search-input">
                            <button class="gis-map-btn gis-search-btn" onclick="gisMapSearch()" title="Search"><i class="fas fa-search"></i></button>
                            <div id="gisMapSearchResults" class="gis-search-results"></div>
                        </div>
                    </div>
                    <div class="gis-toolbar-right">
                        <div class="gis-dropdown" style="position:relative;display:inline-block;">
                            <button class="gis-map-btn" onclick="toggleGisToolsDropdown()" id="gisToolsDropdownBtn">
                                <i class="fas fa-tools"></i> Tools
                            </button>
                            <div id="gisToolsDropdownMenu" class="gis-dropdown-menu" style="display:none;">
                                <button class="gis-dropdown-item" onclick="toggleGisSatelliteLayer()"><i class="fas fa-satellite"></i> Satellite View</button>
                                <button class="gis-dropdown-item" onclick="toggleGisTrafficIncidentsLayer()" id="gisToggleIncidentsBtn"><i class="fas fa-exclamation-triangle"></i> Traffic Incidents</button>
                            </div>
                        </div>
                        <button class="gis-map-btn" id="gisToggleTrafficBtn" onclick="toggleGisTrafficLayer()">
                            <i class="fas fa-car"></i> Traffic
                        </button>
                        <button class="gis-map-btn" onclick="toggleGisMapFullscreen()" id="gisFullscreenMapBtn">
                            <i class="fas fa-expand"></i> Fullscreen
                        </button>
                    </div>
                </div>
                <div id="roadProjectsMap"></div>
            </div>
            <div class="road-projects-grid">
                <?php foreach ($ipms_road_projects as $proj):
                    $meta = ipms_status_meta($proj['project_status']);
                    $progress = max(0, min(100, (int)$proj['progress_percent']));
                ?>
                <div class="road-project-card" onclick="focusRoadProjectOnMap(<?php echo (int)$proj['project_id']; ?>)">
                    <span class="rp-status-badge <?php echo htmlspecialchars($meta['class']); ?>"><?php echo htmlspecialchars($meta['label']); ?></span>
                    <h4><?php echo htmlspecialchars($proj['project_name']); ?></h4>
                    <div class="rp-meta"><i class="fas fa-road"></i> <?php echo htmlspecialchars($proj['road_type']); ?> &middot; <?php echo htmlspecialchars($proj['road_status']); ?></div>
                    <?php if (!empty($proj['barangays_covered'])): ?>
                    <div class="rp-meta"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars(implode(', ', $proj['barangays_covered'])); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($proj['start_date']) || !empty($proj['end_date'])): ?>
                    <div class="rp-meta">
                        <i class="fas fa-calendar"></i>
                        <?php echo !empty($proj['start_date']) ? htmlspecialchars(date('M Y', strtotime($proj['start_date']))) : '—'; ?>
                        &ndash;
                        <?php echo !empty($proj['end_date']) ? htmlspecialchars(date('M Y', strtotime($proj['end_date']))) : '—'; ?>
                    </div>
                    <?php endif; ?>
                    <div class="rp-progress-track">
                        <div class="rp-progress-fill" style="width: <?php echo $progress; ?>%;"></div>
                    </div>
                    <div class="rp-progress-label"><?php echo $progress; ?>% complete</div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="road-projects-empty">
                <i class="fas fa-road"></i>
                <h5>No Upcoming Projects Right Now</h5>
                <p>Planned and ongoing road projects from IPMS will appear here once available.</p>
            </div>
            <?php endif; ?>
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
            <h2 class="section-title">Contact Us</h2>
            <p class="section-subtitle">Get in touch with our team for assistance and inquiries</p>
            
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
    <footer class="qc-footer">
        <div class="container">
            <div class="footer-top-row">
                <div>
                    <div class="footer-follow-label">FOLLOW US</div>
                    <div class="footer-social-row">
                        <a href="#" aria-label="Facebook" class="footer-social-circle"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" aria-label="X" class="footer-social-circle"><i class="fab fa-twitter"></i></a>
                        <a href="#" aria-label="YouTube" class="footer-social-circle"><i class="fab fa-youtube"></i></a>
                        <a href="#" aria-label="Instagram" class="footer-social-circle"><i class="fab fa-instagram"></i></a>
                    </div>
                </div>
                <div class="footer-contact-row">
                    <a href="tel:+63289881234" class="footer-contact-item"><i class="fas fa-phone-alt"></i> (02) 8988-1234</a>
                    <span class="contact-separator"></span>
                    <a href="mailto:roads@lgu.gov.ph" class="footer-contact-item"><i class="fas fa-envelope"></i> roads@lgu.gov.ph</a>
                    <span class="contact-separator"></span>
                    <a href="contact.php" class="footer-contact-item"><i class="fas fa-map-marker-alt"></i> Quezon City Hall</a>
                </div>
            </div>
            <div class="footer-links-row">
                <a href="#home">Home</a>
                <a href="road-updates.php">Road Updates</a>
                <a href="#road-projects">Road Projects</a>
                <a href="public_reports.php">Road Status</a>
                <a href="about.php">About</a>
                <a href="contact.php">Contact</a>
            </div>
            <div class="footer-divider"></div>
            <div class="footer-bottom-row">
                <p class="footer-copyright"><i class="fas fa-copyright"></i> 2026 Road and Transportation Department. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <?php include __DIR__ . '/includes/a11y_html.php'; ?>

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <!-- TomTom Services (JS client for API proxy) -->
    <script>window.TOMTOM_API_PROXY = 'lgu_staff/pages/api/tomtom/proxy.php';</script>
    <script src="lgu_staff/js/tomtom-services.js"></script>

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
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
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


    <?php include __DIR__ . '/includes/hamburger_menu_js.php'; ?>

    <script src="lgu_staff/js/page-transition.js"></script>
    <?php include __DIR__ . '/includes/a11y_js.php'; ?>

    <script>
        const TOMTOM_API_KEY = '<?php echo TOMTOM_API_KEY; ?>';
        const CITIZEN_API = 'lgu_staff/pages/api/citizen_report.php';

        // Upcoming/ongoing road projects synced from IPMS (read-only cache —
        // see lgu_staff/pages/api/ipms-road-projects-pull.php). Each
        // polyline_coordinates pair is [lat, lng], start -> end.
        const IPMS_ROAD_PROJECTS = <?php echo json_encode(array_map(function ($p) {
            return [
                'project_id' => (int)$p['project_id'],
                'project_name' => $p['project_name'],
                'project_status' => $p['project_status'],
                'progress_percent' => (int)$p['progress_percent'],
                'road_type' => $p['road_type'],
                'road_status' => $p['road_status'],
                'polyline' => $p['polyline_coordinates'],
                'scope_bucket' => $p['scope_bucket'],
            ];
        }, $ipms_road_projects), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

        const ROAD_PROJECT_COLORS = {
            'active': '#28a745',
            'delayed': '#dc3545',
            'on_hold': '#6c757d',
            'completion_inspection': '#17a2b8'
        };
        const roadProjectLayers = {};
        let roadProjectsMap = null;
        let gisTrafficLayer = null;
        let gisSatelliteLayer = null;
        let gisIncidentsLayer = null;
        let gisDistrictsLayer = null;
        let gisMapFullscreen = false;
        let gisToolsDropdownOpen = false;

        const GIS_QC_CENTER = [14.6760, 121.0437];
        const GIS_QC_POLYGON_COORDS = [
            [14.605, 120.982],[14.620, 120.985],[14.640, 120.988],[14.660, 120.990],
            [14.680, 120.995],[14.700, 121.005],[14.715, 121.020],[14.730, 121.035],
            [14.745, 121.050],[14.755, 121.065],[14.765, 121.080],[14.773, 121.095],
            [14.770, 121.110],[14.762, 121.125],[14.750, 121.135],[14.735, 121.142],
            [14.718, 121.146],[14.700, 121.148],[14.682, 121.142],[14.665, 121.135],
            [14.650, 121.125],[14.638, 121.112],[14.628, 121.098],[14.618, 121.080],
            [14.612, 121.062],[14.607, 121.045],[14.605, 121.028],[14.603, 121.010],
            [14.602, 121.000],[14.603, 120.990]
        ];

        function initRoadProjectsMap() {
            const mapEl = document.getElementById('roadProjectsMap');
            if (!mapEl || typeof L === 'undefined') return;

            roadProjectsMap = L.map('roadProjectsMap').setView(GIS_QC_CENTER, 12);

            L.tileLayer('https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=' + TOMTOM_API_KEY, {
                attribution: '© TomTom',
                maxZoom: 18
            }).addTo(roadProjectsMap);

            gisTrafficLayer = L.tileLayer('https://api.tomtom.com/traffic/map/4/tile/flow/relative0/{z}/{x}/{y}.png?view=Unified&key=' + TOMTOM_API_KEY, {
                attribution: '© TomTom Traffic',
                opacity: 0.7
            }).addTo(roadProjectsMap);

            L.polygon(GIS_QC_POLYGON_COORDS, {
                color: '#3762c8',
                weight: 2,
                opacity: 0.8,
                fillOpacity: 0.06,
                fillColor: '#3762c8'
            }).addTo(roadProjectsMap);

            const qcBounds = L.latLngBounds(GIS_QC_POLYGON_COORDS);
            roadProjectsMap.setMaxBounds(qcBounds.pad(0.15));
            roadProjectsMap.setMinZoom(11);
            roadProjectsMap.setMaxZoom(18);
            roadProjectsMap.on('moveend', function() {
                const center = roadProjectsMap.getCenter();
                if (!qcBounds.contains(center)) {
                    roadProjectsMap.setView(GIS_QC_CENTER, 12);
                }
            });

            fetch('lgu_staff/pages/api/qc_districts.geojson')
                .then(r => r.json())
                .then(data => {
                    const colors = { 1: '#3b82f6', 2: '#8b5cf6', 3: '#10b981', 4: '#f59e0b', 5: '#ef4444', 6: '#06b6d4' };
                    gisDistrictsLayer = L.geoJSON(data, {
                        style: function(feature) {
                            const dNum = parseInt((feature.properties.district_number || feature.properties.district || '').replace(/\D/g, '')) || 1;
                            return {
                                color: colors[dNum] || '#3762c8',
                                weight: 1.5,
                                opacity: 0.6,
                                fillOpacity: 0.04,
                                fillColor: colors[dNum] || '#3762c8',
                                dashArray: '5,5'
                            };
                        },
                        onEachFeature: function(feature, layer) {
                            layer.bindTooltip(feature.properties.district_name || feature.properties.district, {
                                sticky: true,
                                className: 'gis-district-tooltip'
                            });
                        }
                    }).addTo(roadProjectsMap);
                })
                .catch(e => console.warn('Could not load QC districts GeoJSON:', e));

            const allPoints = [];
            IPMS_ROAD_PROJECTS.forEach(proj => {
                if (!Array.isArray(proj.polyline) || proj.polyline.length < 2) return;
                const latlngs = proj.polyline.map(pt => [pt[0], pt[1]]);
                const color = ROAD_PROJECT_COLORS[proj.project_status] || '#2196f3';
                const line = L.polyline(latlngs, {
                    color: color,
                    weight: 5,
                    opacity: 0.85
                }).addTo(roadProjectsMap);
                line.bindPopup(
                    '<strong>' + escapeRoadProjectHtml(proj.project_name) + '</strong><br>' +
                    escapeRoadProjectHtml(proj.road_type) + ' &middot; ' + escapeRoadProjectHtml(proj.road_status) + '<br>' +
                    proj.progress_percent + '% complete'
                );
                roadProjectLayers[proj.project_id] = line;
                allPoints.push(...latlngs);
            });

            if (allPoints.length > 0) {
                roadProjectsMap.fitBounds(L.latLngBounds(allPoints).pad(0.15));
            } else {
                roadProjectsMap.fitBounds(qcBounds.pad(0.1));
            }
        }

        function escapeRoadProjectHtml(text) {
            const d = document.createElement('div');
            d.textContent = text || '';
            return d.innerHTML;
        }

        function focusRoadProjectOnMap(projectId) {
            const line = roadProjectLayers[projectId];
            if (!line || !roadProjectsMap) return;
            document.getElementById('roadProjectsMap').scrollIntoView({ behavior: 'smooth', block: 'center' });
            roadProjectsMap.fitBounds(line.getBounds().pad(0.3));
            line.openPopup(line.getBounds().getCenter());
        }

        let gisTrafficVisible = true;
        function toggleGisTrafficLayer() {
            gisTrafficVisible = !gisTrafficVisible;
            const btn = document.getElementById('gisToggleTrafficBtn');
            if (gisTrafficVisible) {
                gisTrafficLayer.addTo(roadProjectsMap);
                btn.classList.remove('inactive-toggle');
                btn.classList.add('active-toggle');
            } else {
                roadProjectsMap.removeLayer(gisTrafficLayer);
                btn.classList.remove('active-toggle');
                btn.classList.add('inactive-toggle');
            }
        }

        function toggleGisSatelliteLayer() {
            if (gisSatelliteLayer) {
                roadProjectsMap.removeLayer(gisSatelliteLayer);
                gisSatelliteLayer = null;
                return;
            }
            gisSatelliteLayer = L.tileLayer('https://api.tomtom.com/map/1/tile/satellite/main/{z}/{x}/{y}.png?view=Unified&key=' + TOMTOM_API_KEY, {
                attribution: '© TomTom',
                maxZoom: 18
            }).addTo(roadProjectsMap);
        }

        function toggleGisTrafficIncidentsLayer() {
            if (gisIncidentsLayer) {
                roadProjectsMap.removeLayer(gisIncidentsLayer);
                gisIncidentsLayer = null;
                document.getElementById('gisToggleIncidentsBtn').innerHTML = '<i class="fas fa-exclamation-triangle"></i> Traffic Incidents';
                return;
            }
            const center = roadProjectsMap.getCenter();
            TomTomServices.trafficIncidents(center.lat, center.lng, 15).then(data => {
                if (data.success && data.data && data.data.incidents && data.data.incidents.length > 0) {
                    gisIncidentsLayer = L.layerGroup().addTo(roadProjectsMap);
                    data.data.incidents.forEach(inc => {
                        const pos = inc.geometry?.point || inc.properties?.geometryCoordinates;
                        if (pos) {
                            const icon = L.divIcon({
                                html: '<div style="background:#ef4444;color:#fff;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.3);"><i class="fas fa-exclamation"></i></div>',
                                className: '', iconSize: [22, 22]
                            });
                            const ev = inc.properties || inc.event;
                            L.marker([pos.lat || pos.latitude, pos.lon || pos.longitude], { icon })
                                .bindPopup('<b>' + (ev?.type || 'Traffic Incident') + '</b><br>' + (ev?.description || ev?.iconCategory || ''))
                                .addTo(gisIncidentsLayer);
                        }
                    });
                    document.getElementById('gisToggleIncidentsBtn').innerHTML = '<i class="fas fa-exclamation-triangle"></i> Hide Incidents';
                } else {
                    gisIncidentsLayer = L.tileLayer('https://api.tomtom.com/traffic/map/4/tile/incidents/absolute/{z}/{x}/{y}.png?view=Unified&key=' + TOMTOM_API_KEY, {
                        attribution: '© TomTom Incidents', opacity: 0.7
                    }).addTo(roadProjectsMap);
                    document.getElementById('gisToggleIncidentsBtn').innerHTML = '<i class="fas fa-exclamation-triangle"></i> Hide Incidents';
                }
            });
        }

        function toggleGisToolsDropdown() {
            const menu = document.getElementById('gisToolsDropdownMenu');
            gisToolsDropdownOpen = !gisToolsDropdownOpen;
            menu.style.display = gisToolsDropdownOpen ? 'block' : 'none';
        }
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.gis-dropdown')) {
                const menu = document.getElementById('gisToolsDropdownMenu');
                if (menu) { menu.style.display = 'none'; gisToolsDropdownOpen = false; }
            }
        });

        function toggleGisMapFullscreen() {
            gisMapFullscreen = !gisMapFullscreen;
            document.body.classList.toggle('gis-map-fullscreen-active', gisMapFullscreen);
            const btn = document.getElementById('gisFullscreenMapBtn');
            btn.innerHTML = gisMapFullscreen ? '<i class="fas fa-compress"></i> Exit' : '<i class="fas fa-expand"></i> Fullscreen';
            setTimeout(() => roadProjectsMap?.invalidateSize(), 300);
        }

        function gisMapSearch() {
            const q = document.getElementById('gisMapSearchInput').value.trim();
            if (!q) return;
            const resultsDiv = document.getElementById('gisMapSearchResults');
            TomTomServices.poiSearch(q, { limit: 8 }).then(data => {
                if (!data.success || !data.data || !data.data.results) {
                    resultsDiv.style.display = 'none';
                    return;
                }
                const results = data.data.results;
                if (results.length > 0 && results[0].position) {
                    roadProjectsMap.setView([results[0].position.lat, results[0].position.lon], 15);
                }
                resultsDiv.innerHTML = results.map(r => {
                    const pos = r.position || {};
                    return '<div class="gis-search-result-item" onclick="gisMapFlyTo(' + (pos.lat || 0) + ',' + (pos.lon || 0) + ')">' +
                        '<i class="fas fa-map-pin" style="color:#3762c8;margin-right:6px;"></i>' + (r.poi?.name || r.address?.freeformAddress || 'Unknown') +
                        '<small>' + (r.address?.freeformAddress || '') + '</small></div>';
                }).join('');
                resultsDiv.style.display = 'block';
            });
        }

        function gisMapFlyTo(lat, lng) {
            roadProjectsMap.setView([lat, lng], 15);
            document.getElementById('gisMapSearchResults').style.display = 'none';
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.gis-map-search-box')) {
                const results = document.getElementById('gisMapSearchResults');
                if (results) results.style.display = 'none';
            }
        });

        document.getElementById('gisMapSearchInput')?.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') gisMapSearch();
        });

        if (IPMS_ROAD_PROJECTS.length > 0) {
            initRoadProjectsMap();
        }

        let citizenMap = null;
        let citizenPin = null;
        let otpVerified = false;
        let photoFiles = [];

        const QC_GEOJSON = {"type":"MultiPolygon","coordinates":[[[[120.9896951,14.6260342],[120.9897783,14.6261549],[120.9898201,14.6262495],[120.9912426,14.6305282],[120.9925998,14.634629],[120.9927488,14.6350728],[120.9930436,14.6359804],[120.9921888,14.6362678],[120.9925993,14.6374219],[120.9923657,14.6379133],[120.9920194,14.6385471],[120.9913141,14.6398144],[120.9912629,14.6398421],[120.9913863,14.6402168],[120.9915513,14.6406945],[120.9917593,14.6410924],[120.9919297,14.6415352],[120.9921201,14.6419929],[120.9923392,14.642419],[120.9925111,14.6428751],[120.9926892,14.6433075],[120.9928758,14.6436994],[120.9928964,14.6438027],[120.9928787,14.6442386],[120.9928718,14.644469],[120.9931041,14.6450106],[120.9933546,14.6455495],[120.9934932,14.645824],[120.9940588,14.6468884],[120.9941546,14.647084],[120.994172,14.6471239],[120.9943354,14.6474992],[120.9945753,14.6480502],[120.9951615,14.6497136],[120.9955689,14.6507248],[120.9962495,14.6521912],[120.9965706,14.6528858],[120.9970642,14.6539536],[120.9972619,14.6543814],[120.9976659,14.6551673],[120.9984358,14.6561778],[120.9985902,14.6563956],[120.9987949,14.6566755],[120.9989016,14.6568231],[120.9991025,14.6573354],[120.9992982,14.6581072],[120.999302,14.6581224],[120.9993861,14.661943],[120.9994033,14.6634339],[120.9994138,14.663877],[120.9994174,14.6643627],[120.9997577,14.664741],[121.0003125,14.6653244],[121.0022246,14.667334],[121.0058529,14.6710675],[121.014895,14.6806545],[121.0192022,14.6851812],[121.0223396,14.6884807],[121.0216672,14.6903804],[121.0221324,14.6903954],[121.0229565,14.6905977],[121.0235056,14.6907147],[121.0237582,14.6909064],[121.0238923,14.6911428],[121.0239012,14.691906],[121.0240538,14.6925147],[121.0243684,14.6930298],[121.0244819,14.6934312],[121.0245216,14.6936771],[121.0244945,14.6938242],[121.0244036,14.6939703],[121.0242791,14.6940888],[121.0238403,14.6943388],[121.0237035,14.6944269],[121.0235877,14.6945374],[121.0234997,14.6946994],[121.0234332,14.6949622],[121.0232403,14.6956412],[121.0231926,14.6957695],[121.0231054,14.6958487],[121.0223848,14.6962489],[121.0221059,14.6965265],[121.0214981,14.6973489],[121.0212587,14.6975987],[121.0209106,14.6978548],[121.020637,14.6979778],[121.0203162,14.6981101],[121.0201167,14.6982726],[121.0198162,14.6987914],[121.0194826,14.6992014],[121.0192923,14.6994167],[121.0189767,14.6996771],[121.0183257,14.7000777],[121.0181346,14.7002392],[121.0180226,14.7003683],[121.0179367,14.7005187],[121.0177858,14.7008239],[121.0176954,14.7010947],[121.017515,14.7015545],[121.0172752,14.7021269],[121.0172537,14.7022965],[121.0172773,14.7024392],[121.0173735,14.7027291],[121.0175166,14.7029124],[121.0178529,14.7032927],[121.0179256,14.7034017],[121.0161294,14.708755],[121.0136441,14.7159085],[121.0183472,14.7204784],[121.0205352,14.7225911],[121.0224236,14.7243718],[121.0257601,14.7275181],[121.0273872,14.7292097],[121.0280557,14.7298826],[121.0308457,14.732682],[121.0362582,14.7380574],[121.0385103,14.740294],[121.0404931,14.7421201],[121.0464397,14.7422036],[121.0531742,14.742157],[121.0587677,14.7421837],[121.0663291,14.7421927],[121.075878,14.7423099],[121.0769046,14.7423243],[121.0770302,14.7420002],[121.0772585,14.7420616],[121.0773718,14.7420979],[121.0774749,14.7421411],[121.0775529,14.7421861],[121.0776449,14.7422779],[121.0777091,14.7423599],[121.0777577,14.7424549],[121.0778078,14.7425895],[121.0778258,14.742725],[121.0778333,14.7428592],[121.0779129,14.7444754],[121.0779317,14.7447783],[121.0779571,14.7449288],[121.0779908,14.7450374],[121.0780318,14.7451322],[121.0780846,14.7452281],[121.0781445,14.7453116],[121.0782561,14.7454372],[121.0783473,14.7455143],[121.0784592,14.7455823],[121.0785924,14.7456529],[121.0802603,14.7461772],[121.0802811,14.7461923],[121.0805133,14.7463022],[121.0806645,14.7464257],[121.082152,14.7479453],[121.0824692,14.7483083],[121.0826085,14.7484806],[121.082698,14.748611],[121.0833299,14.7495766],[121.0842517,14.7508641],[121.0844538,14.7511728],[121.0846162,14.7514516],[121.0846896,14.7516349],[121.0847244,14.7517425],[121.0847557,14.7518499],[121.0847854,14.7520288],[121.0848696,14.7533543],[121.0849007,14.753781],[121.0850078,14.7552569],[121.08507,14.7556543],[121.0851033,14.7558102],[121.0853354,14.7566921],[121.0856433,14.7578089],[121.0857106,14.758085],[121.0887985,14.7579696],[121.089366,14.7582657],[121.0896539,14.7582575],[121.0907068,14.7579449],[121.091745,14.7585362],[121.0925497,14.7591997],[121.0934468,14.7598163],[121.0948137,14.7609386],[121.0956111,14.7615413],[121.0964583,14.7615898],[121.0984063,14.7623292],[121.0990606,14.7626015],[121.0997537,14.7640376],[121.0997995,14.7651862],[121.1012409,14.7654178],[121.1016249,14.7655348],[121.1025355,14.7638675],[121.104773,14.7618357],[121.105793,14.7622963],[121.1073723,14.7627981],[121.1090833,14.7631436],[121.1093054,14.7639251],[121.1095933,14.7646242],[121.1099963,14.7651342],[121.1113289,14.7665244],[121.112048,14.7673232],[121.112593,14.7679537],[121.1134127,14.7693916],[121.1139187,14.7712492],[121.116914,14.772087],[121.1175027,14.7723201],[121.1191841,14.7740299],[121.1204059,14.774863],[121.1227424,14.7743387],[121.123635,14.7733002],[121.1253473,14.7758945],[121.126301,14.7757419],[121.1272065,14.7760592],[121.1282731,14.7763691],[121.1289228,14.7762065],[121.1298201,14.7752879],[121.1309266,14.7751283],[121.1311391,14.7758509],[121.1317064,14.7764085],[121.1332033,14.7764137],[121.1331762,14.7756687],[121.1337681,14.7752992],[121.13332,14.7748],[121.1327295,14.7741422],[121.132411,14.7720049],[121.1322758,14.771775],[121.1308227,14.7714603],[121.1297934,14.7713221],[121.1290096,14.7714835],[121.1278939,14.7700103],[121.127839,14.7691148],[121.1272269,14.7693315],[121.1267174,14.7687146],[121.1269178,14.7681074],[121.1259981,14.7668581],[121.1247996,14.7658129],[121.1237838,14.7653683],[121.1239254,14.7645778],[121.1246215,14.764273],[121.1251752,14.7631133],[121.125776,14.7626983],[121.1252233,14.7610973],[121.1253091,14.7608898],[121.124262,14.7598523],[121.1235239,14.7598938],[121.123069,14.7579018],[121.1215498,14.7578437],[121.1211807,14.7568643],[121.1213609,14.7559513],[121.1207944,14.7550217],[121.1210519,14.7539178],[121.1208202,14.7527807],[121.1206314,14.7520088],[121.1196186,14.7509132],[121.1181479,14.7495936],[121.1186965,14.7475179],[121.1177821,14.7464168],[121.1177004,14.7462763],[121.1176619,14.746133],[121.1176944,14.745882],[121.1181852,14.74502],[121.1183029,14.7434952],[121.1180428,14.7428784],[121.1178619,14.7420636],[121.117651,14.7413675],[121.1175255,14.7406808],[121.117859,14.739857],[121.1167681,14.7398421],[121.1166398,14.7396788],[121.1157523,14.7385508],[121.1151634,14.7379454],[121.1145497,14.7377214],[121.1141598,14.7376302],[121.1138032,14.737456],[121.1137369,14.7372321],[121.1141681,14.7360875],[121.1144336,14.735565],[121.1148897,14.7350341],[121.1153542,14.7346858],[121.1156528,14.7346858],[121.1157523,14.7344121],[121.1160177,14.7343126],[121.1166812,14.7340306],[121.1176351,14.7332343],[121.1183484,14.7327367],[121.118638,14.7327323],[121.1184252,14.7321399],[121.1184868,14.7307439],[121.1183676,14.7298888],[121.1171018,14.7208067],[121.1139303,14.6980488],[121.1134183,14.6979009],[121.1129406,14.6977012],[121.112502,14.6973898],[121.1121494,14.696915],[121.1121743,14.6964194],[121.1114141,14.6957288],[121.1114034,14.6951533],[121.1115761,14.693783],[121.1115295,14.6930258],[121.1113873,14.6912424],[121.1113484,14.6894359],[121.1113444,14.6892498],[121.1121855,14.6852978],[121.1121169,14.6846502],[121.1119916,14.6844409],[121.1116706,14.6834048],[121.1101685,14.6808973],[121.1088846,14.6787885],[121.1079596,14.6772824],[121.1066178,14.6757895],[121.105877,14.6752513],[121.1050187,14.6744874],[121.1036883,14.6727604],[121.103246,14.6723195],[121.1002379,14.6700618],[121.0993176,14.6692092],[121.0989231,14.66828],[121.0987592,14.6678005],[121.0986737,14.6673511],[121.0987996,14.667012],[121.0983993,14.6651508],[121.0983915,14.664832],[121.0981473,14.6642649],[121.0980176,14.6639866],[121.0979213,14.6637413],[121.0967374,14.6642299],[121.0966408,14.6645002],[121.0965238,14.6645363],[121.096494,14.6646002],[121.0964764,14.6648531],[121.0963356,14.664908],[121.0961861,14.6648805],[121.0956829,14.6652424],[121.0952371,14.6652695],[121.095218,14.6652617],[121.0951488,14.6652335],[121.0948585,14.6649347],[121.0941136,14.6646918],[121.0938826,14.6645004],[121.0936995,14.6643486],[121.0936321,14.6639892],[121.0935248,14.6634173],[121.0920319,14.6617729],[121.0914765,14.6609324],[121.0911456,14.6605249],[121.0912009,14.6596216],[121.0882081,14.6566672],[121.0874608,14.6573361],[121.0867891,14.6566853],[121.0865123,14.6557911],[121.0859908,14.6562612],[121.0857081,14.6554682],[121.0854564,14.6547612],[121.0861472,14.6545518],[121.0857806,14.6532691],[121.0857761,14.6529528],[121.0858927,14.6527812],[121.0866746,14.652202],[121.0874307,14.651506],[121.0874186,14.651271],[121.0867363,14.6514588],[121.0865934,14.6514982],[121.0868934,14.6493282],[121.0877308,14.6489835],[121.0877901,14.6485394],[121.0896603,14.6468726],[121.0889727,14.6464517],[121.0881572,14.6459452],[121.0874867,14.6458583],[121.0876123,14.6448987],[121.0855999,14.6444918],[121.0853712,14.6437206],[121.0847489,14.6436375],[121.084572,14.6436446],[121.0835988,14.6439511],[121.083191,14.6439884],[121.0831645,14.6436992],[121.0831803,14.6433858],[121.0823549,14.6424372],[121.0822937,14.6419772],[121.0824574,14.6413518],[121.0823287,14.6410846],[121.0823068,14.640833],[121.0819886,14.6401248],[121.0817834,14.6400111],[121.0814819,14.6395869],[121.0814591,14.6391565],[121.0809909,14.638754],[121.0807133,14.6384576],[121.0811626,14.638401],[121.0816883,14.6383388],[121.0819852,14.6383165],[121.0819219,14.6379116],[121.0818386,14.6373806],[121.0817386,14.6369035],[121.0813323,14.636861],[121.0808709,14.6368195],[121.0806778,14.6365807],[121.0806885,14.6362589],[121.0803023,14.635823],[121.0799697,14.6355115],[121.0797189,14.6346416],[121.0802379,14.6345357],[121.0799374,14.6339782],[121.0795619,14.6336149],[121.0787821,14.6333002],[121.0783354,14.6331595],[121.0781921,14.6331024],[121.0781852,14.6327038],[121.0777695,14.6328058],[121.0777259,14.6325722],[121.0777748,14.6324289],[121.0776147,14.6322159],[121.0775373,14.6316817],[121.077469,14.6314838],[121.0774626,14.6309563],[121.0771695,14.6303523],[121.0769013,14.6296256],[121.0758175,14.629031],[121.0751483,14.628847],[121.074425,14.6286421],[121.0744066,14.6280696],[121.0744536,14.6279073],[121.0747689,14.6264184],[121.075037,14.6252375],[121.0750843,14.6249965],[121.0751135,14.6247014],[121.0752906,14.6239809],[121.0750915,14.6237732],[121.0758256,14.623032],[121.0759409,14.6228017],[121.0764557,14.6218147],[121.0765189,14.6213886],[121.0765039,14.6208781],[121.0762267,14.6203305],[121.0758218,14.6195429],[121.0779778,14.6182727],[121.0781009,14.6182005],[121.0781522,14.6181704],[121.0782067,14.6181381],[121.0788822,14.6177381],[121.0786891,14.6173291],[121.0784541,14.616765],[121.078399,14.6160455],[121.0784392,14.6155269],[121.0788997,14.6141584],[121.079012,14.6138249],[121.0799561,14.6124462],[121.0810672,14.6115981],[121.082733,14.6104304],[121.0846661,14.6090753],[121.0866938,14.6076539],[121.0869916,14.607435],[121.0873671,14.6069989],[121.0876246,14.6066771],[121.0880242,14.6060925],[121.0883546,14.6054058],[121.0889512,14.6041655],[121.0900155,14.6024379],[121.0904275,14.6011754],[121.0904543,14.6001564],[121.0902434,14.5996752],[121.0899858,14.5992902],[121.0895263,14.599072],[121.0884909,14.5990613],[121.0879024,14.599318],[121.0874234,14.6003282],[121.0870479,14.6014334],[121.0863878,14.6022288],[121.0856732,14.6028411],[121.0846416,14.6033011],[121.083786,14.6033745],[121.0832517,14.6032684],[121.0828519,14.6030984],[121.0824594,14.6026332],[121.0823594,14.6023855],[121.0823531,14.6017929],[121.082531,14.5989494],[121.0824407,14.5972293],[121.0823855,14.596288],[121.0823165,14.5951453],[121.0827285,14.5921634],[121.0830518,14.5903997],[121.0826503,14.5905369],[121.0799433,14.5915772],[121.0798384,14.5916175],[121.0797276,14.5916496],[121.0796285,14.5916782],[121.0782473,14.5921938],[121.0782408,14.5921739],[121.0774851,14.5924783],[121.0760398,14.5926956],[121.0738133,14.5930164],[121.0723414,14.5932389],[121.0706848,14.593464],[121.0704484,14.5934856],[121.0698755,14.5933839],[121.0695316,14.5930667],[121.0680469,14.5919521],[121.0617941,14.5905758],[121.0616432,14.5905503],[121.0614237,14.5904899],[121.0596451,14.5900235],[121.0582621,14.5896463],[121.0572211,14.589369],[121.0577585,14.5911365],[121.0578276,14.591349],[121.0581667,14.592365],[121.0583341,14.5927896],[121.058484,14.5932156],[121.0587576,14.5940416],[121.0592133,14.5953564],[121.0594743,14.5962171],[121.0595967,14.5969132],[121.0596363,14.5971525],[121.0596703,14.5973922],[121.0596993,14.5976371],[121.0597212,14.5978708],[121.0597365,14.5981082],[121.0597432,14.5983444],[121.0597438,14.5986502],[121.0597399,14.5988943],[121.0597277,14.5991796],[121.0597074,14.599452],[121.059688,14.5999107],[121.059654,14.6001755],[121.0595855,14.6005855],[121.0595051,14.6009766],[121.0594012,14.6013798],[121.0593019,14.6017279],[121.0592271,14.6017034],[121.0591491,14.601912],[121.0590045,14.602265],[121.0569959,14.6066534],[121.0569881,14.6066703],[121.0567956,14.6065867],[121.0521673,14.6045402],[121.0517929,14.6043748],[121.0516597,14.6046499],[121.051977,14.6048031],[121.0514962,14.6058821],[121.051396,14.6062072],[121.0513718,14.6063175],[121.0510734,14.607049],[121.0500514,14.6096748],[121.049922,14.6096421],[121.0494407,14.6095204],[121.0493306,14.6094959],[121.0489723,14.6094162],[121.0488039,14.609382],[121.0484539,14.6093052],[121.0483294,14.6092755],[121.048233,14.6092525],[121.0477992,14.6091441],[121.0477546,14.6091336],[121.0475866,14.609092],[121.0471288,14.6089875],[121.0470529,14.6089712],[121.0469987,14.6089577],[121.0469243,14.608939],[121.0466595,14.6088721],[121.0462801,14.6087762],[121.0462033,14.6087584],[121.0461319,14.6087435],[121.0459073,14.6086864],[121.0458646,14.6086757],[121.0458196,14.6086706],[121.0457618,14.6086687],[121.0456728,14.6086802],[121.0451532,14.6087612],[121.0450626,14.6087768],[121.0447027,14.6088322],[121.0445993,14.6088481],[121.0442439,14.6088989],[121.0441123,14.6089231],[121.0440186,14.6089572],[121.0433434,14.6095191],[121.043249,14.6095823],[121.043084,14.6096476],[121.0430551,14.609655],[121.0430241,14.6096537],[121.0429708,14.6096503],[121.0429556,14.609642],[121.0428448,14.6095839],[121.0421317,14.6093009],[121.0413743,14.609006],[121.0410559,14.6088588],[121.0408955,14.6087829],[121.0407498,14.6086936],[121.040266,14.6083382],[121.0396159,14.6078858],[121.0393201,14.6076583],[121.0391728,14.6075514],[121.0388707,14.6073116],[121.0387121,14.6071658],[121.0386965,14.6071521],[121.0386585,14.6071185],[121.038506,14.6069836],[121.038087,14.6066134],[121.0380076,14.6065489],[121.0378158,14.6063813],[121.0377321,14.6063141],[121.0376133,14.6064446],[121.0372834,14.6067543],[121.0370345,14.6076347],[121.0368916,14.6079957],[121.0368975,14.608417],[121.0368149,14.608499],[121.0363622,14.6086678],[121.0362119,14.6086815],[121.0359383,14.6086822],[121.0356082,14.6086928],[121.0353238,14.6088145],[121.0352143,14.6089671],[121.0348568,14.6094131],[121.0346766,14.609438],[121.0343474,14.6094571],[121.0339011,14.6095485],[121.0336672,14.6096889],[121.0334107,14.6099756],[121.0328741,14.6107088],[121.0327126,14.6108919],[121.0325136,14.6110659],[121.0320248,14.6115037],[121.0316733,14.611683],[121.0314635,14.6118989],[121.0313434,14.6121154],[121.0312621,14.6122656],[121.0310722,14.6126096],[121.0308805,14.6129253],[121.0304507,14.6129786],[121.0301013,14.6131495],[121.0298162,14.6131828],[121.0294838,14.6130842],[121.0293482,14.6129809],[121.0292359,14.6128516],[121.0292577,14.612472],[121.0289744,14.6121481],[121.0288587,14.6120778],[121.0286223,14.6120554],[121.0281632,14.6122481],[121.0275067,14.6123474],[121.0269145,14.6123391],[121.0259875,14.6123059],[121.0255013,14.6123868],[121.0253272,14.6125184],[121.0252071,14.6127011],[121.0250526,14.6131828],[121.0249404,14.6134936],[121.0243076,14.6137399],[121.0237484,14.6138698],[121.0236239,14.6138471],[121.0235014,14.6138021],[121.0234014,14.6137345],[121.0232946,14.6135373],[121.0232654,14.6133529],[121.0232341,14.613178],[121.0230435,14.6120983],[121.0225558,14.6113174],[121.0219307,14.6104505],[121.0214352,14.6095448],[121.0212748,14.6094049],[121.0214532,14.6092493],[121.0217177,14.609016],[121.0219135,14.6088317],[121.0220838,14.6085433],[121.0222199,14.6082751],[121.022237,14.6077435],[121.0219474,14.6071501],[121.0213826,14.6064302],[121.0209541,14.6058371],[121.0205743,14.6052367],[121.0201956,14.6045802],[121.0198942,14.603941],[121.0196633,14.6031741],[121.0195915,14.6028204],[121.0193839,14.6029514],[121.0185805,14.6036079],[121.0176183,14.6043722],[121.0175128,14.6044948],[121.0163648,14.6053799],[121.0153858,14.6061298],[121.0139822,14.607205],[121.0104299,14.6098411],[121.0092936,14.6107331],[121.0081408,14.6115939],[121.0069471,14.6125167],[121.0052731,14.6139723],[121.003646,14.6150944],[121.0009647,14.6170829],[120.9978929,14.6193355],[120.9976689,14.619578],[120.9974816,14.6197598],[120.997321,14.6198882],[120.9972545,14.6199419],[120.997134,14.6200392],[120.9963828,14.6206206],[120.9962633,14.6207132],[120.9961245,14.6208263],[120.9960606,14.62087],[120.9959383,14.6209647],[120.9953714,14.6214035],[120.9949749,14.6217104],[120.9938057,14.6226129],[120.9926137,14.6235329],[120.9917968,14.6241634],[120.9914942,14.624397],[120.991401,14.624469],[120.9913147,14.6245355],[120.9905417,14.6251302],[120.9905112,14.6251559],[120.9903521,14.6252791],[120.9899835,14.6255638],[120.9898287,14.6256977],[120.9897691,14.6257597],[120.989722,14.625838],[120.9897026,14.6258983],[120.9896983,14.625934],[120.9896955,14.6259579],[120.9896951,14.6260342]]]]};

        function isInsideQC(lat, lng) {
            try {
                const pt = turf.point([lng, lat]);
                const poly = turf.multiPolygon(QC_GEOJSON.coordinates);
                return turf.booleanPointInPolygon(pt, poly);
            } catch (e) {
                return false;
            }
        }

        let qcVisiblePolygon = null;

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
            const latlngs = QC_GEOJSON.coordinates[0][0].map(c => [c[1], c[0]]);
            const bounds = L.latLngBounds(latlngs);

            citizenMap = L.map('citizenMap', {
                maxBounds: bounds.pad(0.05),
                maxBoundsViscosity: 1.0
            }).fitBounds(bounds);

            L.tileLayer('https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=' + TOMTOM_API_KEY, {
                attribution: '&copy; TomTom',
                maxZoom: 18
            }).addTo(citizenMap);

            qcVisiblePolygon = L.polygon(latlngs, {
                color: '#2a5298',
                weight: 2,
                fill: true,
                fillColor: '#2a5298',
                fillOpacity: 0.08,
                opacity: 0.7
            }).addTo(citizenMap);

            citizenMap.on('click', function(e) {
                const { lat, lng } = e.latlng;
                if (!isInsideQC(lat, lng)) {
                    showCrStatus('Reports can only be submitted within Quezon City.', 'error');
                    return;
                }
                placeCitizenPin(lat, lng);
            });
        }

        function placeCitizenPin(lat, lng) {
            if (!isInsideQC(lat, lng)) {
                showCrStatus('Reports can only be submitted within Quezon City.', 'error');
                return;
            }
            if (citizenPin) citizenMap.removeLayer(citizenPin);
            citizenPin = L.marker([lat, lng], { draggable: true }).addTo(citizenMap);
            document.getElementById('crLat').value = lat.toFixed(6);
            document.getElementById('crLng').value = lng.toFixed(6);
            document.getElementById('citizenMap').classList.add('has-pin');
            document.getElementById('crOtpStatus').style.display = 'none';

            TomTomServices?.reverseGeocode(lat, lng).then(data => {
                if (data?.success && data?.data?.address?.freeformAddress) {
                    document.getElementById('crAddress').value = data.data.address.freeformAddress;
                }
            }).catch(() => {});

            citizenPin.on('dragend', function() {
                const pos = citizenPin.getLatLng();
                if (!isInsideQC(pos.lat, pos.lng)) {
                    citizenPin.setLatLng([lat, lng]);
                    showCrStatus('Reports can only be submitted within Quezon City.', 'error');
                    return;
                }
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
            syncPhotoFiles();
            this.value = '';
            syncPhotoFiles();
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
                    document.getElementById('fileCount').textContent = photoFiles.length + ' file(s) selected';
                    syncPhotoFiles();
                });
            });
        }

        function syncPhotoFiles() {
            const dt = new DataTransfer();
            photoFiles.forEach(file => dt.items.add(file));
            const input = document.getElementById('crPhotos');
            input.files = dt.files;
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

        // Philippine mobile number validation
        function normalizePhone(val) {
            return val.replace(/\s/g, '');
        }

        function validatePhone(val) {
            const clean = normalizePhone(val);
            const localRe = /^09[0-9]{9}$/;
            const intlRe = /^\+639[0-9]{9}$/;
            if (localRe.test(clean)) return { valid: true, normalized: clean, format: 'local' };
            if (intlRe.test(clean)) return { valid: true, normalized: '09' + clean.slice(3), format: 'intl' };
            return { valid: false, normalized: clean, format: null };
        }

        function applyPhoneFormat(raw) {
            if (raw.startsWith('+')) {
                if (!raw.startsWith('+63')) return '+63';
                let after = raw.slice(3).replace(/[^0-9]/g, '').slice(0, 9);
                let r = '+63';
                if (after.length > 0) r += ' ' + after.slice(0, 2);
                if (after.length > 2) r += ' ' + after.slice(2, 5);
                if (after.length > 5) r += ' ' + after.slice(5);
                return r;
            }
            let d = raw.replace(/[^0-9]/g, '').slice(0, 11);
            let r = d;
            if (d.length > 4) r = d.slice(0, 4) + ' ' + d.slice(4, 7) + ' ' + d.slice(7);
            else if (d.length > 2) r = d.slice(0, 4) + ' ' + d.slice(4);
            return r;
        }

        function showPhoneError(input, show) {
            const errEl = document.getElementById('crPhoneError');
            if (show) {
                input.classList.add('error');
                errEl.classList.add('show');
            } else {
                input.classList.remove('error');
                errEl.classList.remove('show');
            }
        }

        const crPhoneInput = document.getElementById('crPhone');
        crPhoneInput.addEventListener('input', function() {
            let raw = this.value.replace(/[^0-9+]/g, '');
            // Enforce exactly one leading +
            let plusCount = (raw.match(/\+/g) || []).length;
            if (plusCount > 1) {
                raw = '+' + raw.replace(/\+/g, '');
            } else if (plusCount === 1 && !raw.startsWith('+')) {
                raw = '+' + raw.replace(/\+/g, '');
            }
            // Determine raw digit count before reformatting for cursor adjustment
            const cursorPos = this.selectionStart;
            const rawBefore = this.value.slice(0, cursorPos).replace(/[^0-9+]/g, '').length;

            const formatted = applyPhoneFormat(raw);
            this.value = formatted;

            // Restore cursor position
            const rawAfter = formatted.replace(/[^0-9+]/g, '');
            let newPos = 0, digitCount = 0;
            for (let i = 0; i < formatted.length && digitCount < rawBefore; i++) {
                if (/[0-9+]/.test(formatted[i])) digitCount++;
                newPos = i + 1;
            }
            // If we ran out of digits before reaching rawBefore, put cursor at end
            if (digitCount < rawBefore) newPos = formatted.length;
            this.setSelectionRange(newPos, newPos);

            // Real-time validation
            const result = validatePhone(normalizePhone(formatted));
            showPhoneError(this, formatted.length > 0 && !result.valid);
        });

        crPhoneInput.addEventListener('blur', function() {
            const val = normalizePhone(this.value);
            if (val.length > 0) {
                const result = validatePhone(val);
                showPhoneError(this, !result.valid);
                if (result.valid) {
                    this.value = applyPhoneFormat(result.normalized);
                }
            } else {
                showPhoneError(this, false);
            }
        });

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

            const phoneInput = document.getElementById('crPhone');
            const phone = normalizePhone(phoneInput.value);
            if (!phone) {
                errors.push('Please enter your phone number.');
                showPhoneError(phoneInput, true);
            } else {
                const phoneResult = validatePhone(phone);
                if (!phoneResult.valid) {
                    errors.push('Please enter a valid Philippine mobile number.');
                    showPhoneError(phoneInput, true);
                } else {
                    showPhoneError(phoneInput, false);
                }
            }

            const desc = document.getElementById('crDescription').value.trim();
            if (!desc) errors.push('Please describe the issue.');

            if (photoFiles.length < 2) errors.push('Please upload at least 2 photos before submitting your report.');

            if (!otpVerified) errors.push('Please verify your email first.');

            const subLat = parseFloat(document.getElementById('crLat').value);
            const subLng = parseFloat(document.getElementById('crLng').value);
            if (subLat && subLng && !isInsideQC(subLat, subLng)) {
                errors.push('Reports can only be submitted within Quezon City.');
            }

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
            fd.append('phone', normalizePhone(document.getElementById('crPhone').value));
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
            document.getElementById('crPhone').classList.remove('error');
            document.getElementById('crPhoneError').classList.remove('show');
            document.getElementById('crEmail').readOnly = false;
            document.getElementById('citizenMap').classList.remove('has-pin');
            document.getElementById('crAddress').value = '';
            document.getElementById('photoPreview').innerHTML = '';
            photoFiles = [];
            syncPhotoFiles();
            document.getElementById('fileCount').textContent = 'No files selected';
            otpVerified = false;
            if (citizenPin) { citizenMap.removeLayer(citizenPin); citizenPin = null; }
        }
    </script>

    <!-- Terms and Conditions Modal -->
    <div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="termsModalLabel">
                        <i class="fas fa-file-contract"></i> Terms and Conditions
                    </h5>
                </div>
                <div class="modal-body">
                    <h6 style="color:#115272;font-weight:600;margin-bottom:16px;">Terms and Conditions for Citizen Reporting</h6>
                    <p style="font-weight:500;margin-bottom:12px;">By submitting a report through this system, you agree to the following:</p>
                    <div class="terms-scroll" style="max-height:340px;overflow-y:auto;border:1px solid #e0e4f0;border-radius:8px;padding:16px;background:#fafbfc;font-size:0.9rem;line-height:1.7;color:#333;">
                        <ul style="padding-left:20px;margin:0;">
                            <li style="margin-bottom:10px;">All information you provide should be truthful, accurate, and submitted in good faith.</li>
                            <li style="margin-bottom:10px;">False, misleading, duplicated, or malicious reports may be rejected and may result in appropriate action by the LGU.</li>
                            <li style="margin-bottom:10px;">Uploaded photos, videos, and other evidence should relate only to the reported incident and must not violate the privacy or rights of other individuals.</li>
                            <li style="margin-bottom:10px;">Reports are subject to verification by authorized LGU personnel before any action is taken.</li>
                            <li style="margin-bottom:10px;">Submission of a report does not guarantee immediate response or resolution, as reports are prioritized based on urgency and available resources.</li>
                            <li style="margin-bottom:10px;">Personal information collected through the system will be used solely for report verification, communication, and processing in accordance with the Data Privacy Act of 2012 (Republic Act No. 10173).</li>
                            <li style="margin-bottom:10px;">The LGU reserves the right to reject, archive, or request additional information for incomplete, duplicate, or invalid reports.</li>
                            <li style="margin-bottom:0;">By proceeding, you confirm that you understand and accept these Terms and Conditions.</li>
                        </ul>
                    </div>
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" id="termsCheckbox">
                        <label class="form-check-label" for="termsCheckbox" style="font-weight:500;font-size:0.9rem;">
                            I have read and agree to the Terms and Conditions.
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="cr-btn cr-btn-secondary" id="termsCancelBtn">Cancel</button>
                    <button type="button" class="cr-btn cr-btn-primary" id="termsContinueBtn" disabled>
                        <i class="fas fa-arrow-right"></i> Continue
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Terms and Conditions modal logic
        (function() {
            var termsModalEl = document.getElementById('termsModal');
            var termsModal = null;
            var termsCheckbox = document.getElementById('termsCheckbox');
            var termsContinueBtn = document.getElementById('termsContinueBtn');
            var termsCancelBtn = document.getElementById('termsCancelBtn');
            var makeReportBtn = document.getElementById('makeReportBtn');
            var citizenModalEl = document.getElementById('citizenReportModal');

            if (typeof bootstrap !== 'undefined' && termsModalEl) {
                termsModal = new bootstrap.Modal(termsModalEl, {
                    backdrop: 'static',
                    keyboard: false
                });
            }

            function openTermsModal() {
                termsCheckbox.checked = false;
                termsContinueBtn.disabled = true;
                if (termsModal) termsModal.show();
            }

            function closeTermsModal() {
                if (termsModal) termsModal.hide();
            }

            function acceptTermsAndOpenReport() {
                sessionStorage.setItem('tc_accepted', 'true');
                closeTermsModal();
                var reportModal = bootstrap.Modal.getInstance(citizenModalEl);
                if (!reportModal) reportModal = new bootstrap.Modal(citizenModalEl);
                reportModal.show();
            }

            // Check sessionStorage and either open T&C or go directly to report
            function handleMakeReport() {
                if (sessionStorage.getItem('tc_accepted') === 'true') {
                    var reportModal = bootstrap.Modal.getInstance(citizenModalEl);
                    if (!reportModal) reportModal = new bootstrap.Modal(citizenModalEl);
                    reportModal.show();
                } else {
                    openTermsModal();
                }
            }

            if (makeReportBtn) {
                makeReportBtn.addEventListener('click', handleMakeReport);
            }

            if (termsCheckbox) {
                termsCheckbox.addEventListener('change', function() {
                    termsContinueBtn.disabled = !this.checked;
                });
            }

            if (termsContinueBtn) {
                termsContinueBtn.addEventListener('click', acceptTermsAndOpenReport);
            }

            if (termsCancelBtn) {
                termsCancelBtn.addEventListener('click', closeTermsModal);
            }

            // Focus trapping within the T&C modal
            if (termsModalEl) {
                termsModalEl.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        closeTermsModal();
                        return;
                    }
                    if (e.key === 'Tab') {
                        var focusable = termsModalEl.querySelectorAll(
                            'button, input, select, textarea, [tabindex]:not([tabindex="-1"])'
                        );
                        var first = focusable[0];
                        var last = focusable[focusable.length - 1];
                        if (e.shiftKey) {
                            if (document.activeElement === first) {
                                e.preventDefault();
                                last.focus();
                            }
                        } else {
                            if (document.activeElement === last) {
                                e.preventDefault();
                                first.focus();
                            }
                        }
                    }
                });

                // Focus the first focusable element when the modal opens
                termsModalEl.addEventListener('shown.bs.modal', function() {
                    setTimeout(function() {
                        termsCheckbox.focus();
                    }, 100);
                });
            }
        })();
    </script>
</body>
</html>
