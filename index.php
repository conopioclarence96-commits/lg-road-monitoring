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

// CSRF token for the citizen report endpoint (validated by citizen_report.php).
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Cache-busting version for custom assets (bump on deploy). APP_VERSION comes
// from lgu_staff/includes/config.php; fall back if it is not defined yet.
$asset_version = defined('APP_VERSION') ? APP_VERSION : '1.0.0';

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

/**
 * Resolve an image path stored in the DB (e.g. uploads/report_images/X.jpg) to a
 * URL that actually exists on disk. The staff module uploads report images into
 * lgu_staff/uploads/... while completed-project photos live in uploads/..., so
 * probe both candidates and return the first file that exists. Returns '' when
 * no candidate file is found so the caller can skip the broken image.
 */
function road_updates_resolve_image_url($path, $basePath) {
    if (empty($path) || $path === '0' || strtolower((string)$path) === 'null') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    if (strpos($path, 'data:') === 0) return $path;

    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#^\./+#', '', $path);
    $path = ltrim($path, '/');
    if ($path === '' || strpos($path, '../') !== false) return '';

    $candidates = [$path, 'lgu_staff/' . $path];
    foreach ($candidates as $candidate) {
        if (file_exists(__DIR__ . '/' . $candidate)) {
            return $basePath . $candidate;
        }
    }
    return '';
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
        // Log details internally, return a safe generic empty state to users
        error_log("index.php road updates query: " . $e->getMessage());
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
        // Log details internally, keep default zeroed stats for display
        error_log("index.php statistics query: " . $e->getMessage());
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
        // Log details internally, show the generic "projects coming soon" state
        error_log("index.php completed projects query: " . $e->getMessage());
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
        // Settings table may not exist yet - log and continue with defaults
        error_log("index.php site_settings query: " . $e->getMessage());
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous" referrerpolicy="no-referrer">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Transition CSS -->
    <link rel="stylesheet" href="styles/transition.css?v=<?php echo $asset_version; ?>">
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H" crossorigin="anonymous" />
    <!-- Turf.js for point-in-polygon -->
    <script src="https://cdn.jsdelivr.net/npm/@turf/turf@6/turf.min.js" integrity="sha384-82q0nm29xZzIo5BMtDYnh2/NxeO6FoaK1S/0nF84w3cEsqbBfun3JdMyDVYWfVY5" crossorigin="anonymous"></script>
    
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
            top: 11px !important;
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
        .citizen-report-map-wrap {
            position: relative;
        }
        .citizen-report-map-wrap .gis-map-search-box {
            position: absolute;
            top: 10px;
            left: 54px;
            right: 10px;
            z-index: 1000;
        }
        .citizen-report-map-wrap .gis-search-input {
            flex: 1;
            width: auto;
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
        .file-upload-label:focus-visible,
        .file-upload-area input[type="file"]:focus-visible + .file-upload-label {
            outline: 3px solid var(--qc-primary-500);
            outline-offset: 2px;
        }
        /* High-contrast error state for the dropzone (light mode) */
        .file-upload-label.has-error {
            border-color: #dc3545;
            background: #fdf1f1;
        }
        .file-upload-label.has-error i {
            color: #dc3545;
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

        /* Dark mode is controlled by the accessibility panel (html.dark-mode),
           matching the rest of the public pages. Default is light mode. */
        html.dark-mode body {
            background: #121212;
            color: #e0e0e0;
        }

        /* Hero — keep the cityhall background visible in dark mode (darker
           overlay so it still reads as dark mode). Higher specificity than the
           shared a11y_css .hero override so the image is not flattened away. */
        html.dark-mode section.hero {
            background:
                linear-gradient(115deg, rgba(8, 20, 30, 0.92) 0%, rgba(11, 42, 62, 0.88) 55%, rgba(17, 82, 114, 0.82) 100%),
                url('assets/img/cityhall.jpeg') center/cover no-repeat !important;
        }

        /* Navbar */
        html.dark-mode .qc-brand-text strong { color: #e4e6ea; }
        html.dark-mode .qc-brand-text small { color: #93c5fd; }
        html.dark-mode .qc-nav-links .nav-link { color: #c8cdd4; }
        html.dark-mode .qc-nav-links .nav-link:hover,
        html.dark-mode .qc-nav-links .nav-link.active {
            color: #fff;
            background-color: rgba(33, 161, 214, 0.18);
        }
        html.dark-mode .qc-services-dropdown .dropdown-toggle,
        html.dark-mode .qc-programs-dropdown .dropdown-toggle {
            color: #e4e6ea;
            border-color: rgba(255, 255, 255, 0.35);
        }
        html.dark-mode .qc-services-dropdown .dropdown-toggle:hover,
        html.dark-mode .qc-services-dropdown .dropdown-toggle:focus,
        html.dark-mode .qc-programs-dropdown .dropdown-toggle:hover,
        html.dark-mode .qc-programs-dropdown .dropdown-toggle:focus {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }
        html.dark-mode .qc-search-input {
            color: #e4e6ea;
            border-color: rgba(255, 255, 255, 0.35);
        }
        html.dark-mode .qc-search-input:focus {
            background: #1e2229;
            border-color: #93c5fd;
            box-shadow: 0 0 0 3px rgba(147, 197, 253, 0.15);
        }
        html.dark-mode .qc-search-input::placeholder { color: #7f8b99; }
        html.dark-mode .qc-search-results {
            background: #1e2229;
            border-color: #2d323b;
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.5);
        }
        html.dark-mode .qc-search-item { color: #e4e6ea; }
        html.dark-mode .qc-search-item:hover { background: #26313c; color: #fff; }
        html.dark-mode .qc-search-item small { color: #7f8b99; }
        html.dark-mode .qc-search-group-title,
        html.dark-mode .qc-search-empty,
        html.dark-mode .qc-search-loading { color: #9ca3af; }
        html.dark-mode .hamburger-btn {
            border-color: rgba(255, 255, 255, 0.3) !important;
            background: rgba(255, 255, 255, 0.08) !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.35) !important;
        }
        html.dark-mode .hamburger-btn .bar { background: #fff !important; }

        /* Cards & badges */
        html.dark-mode .update-card .card-header { background: #1e1e1e; }
        html.dark-mode .badge-maintenance { background: #4a3f13; color: #fde68a; }
        html.dark-mode .badge-advisory { background: #123044; color: #93c5fd; }
        html.dark-mode .badge-closure { background: #3f1d1d; color: #fca5a5; }
        html.dark-mode .stat-icon,
        html.dark-mode .service-icon,
        html.dark-mode .contact-icon { background: #26313c; }
        html.dark-mode .stat-icon i,
        html.dark-mode .service-icon i,
        html.dark-mode .contact-icon i { color: #93c5fd; }
        html.dark-mode .before-after-card { background: #1e1e1e; border-color: #333; }
        html.dark-mode .before-after-meta span { color: #9ca3af; }
        html.dark-mode .before-after-empty { color: #9ca3af; }
        html.dark-mode .before-after-empty i { color: #374151; }
        html.dark-mode .btn-outline-dark { color: #c8cdd4; border-color: #6b7280; }
        html.dark-mode .btn-outline-dark:hover,
        html.dark-mode .btn-check:checked + .btn-outline-dark { background: #343a40; color: #fff; border-color: #343a40; }
        html.dark-mode .btn-outline-secondary { color: #a6adb5; border-color: #6b7280; }
        html.dark-mode .btn-outline-secondary:hover,
        html.dark-mode .btn-check:checked + .btn-outline-secondary { background: #6b7280; color: #fff; border-color: #6b7280; }

        /* GIS search & dropdowns */
        html.dark-mode .gis-map-btn {
            border-color: rgba(147, 197, 253, 0.35);
            background: rgba(147, 197, 253, 0.08);
            color: #93c5fd;
        }
        html.dark-mode .gis-map-btn:hover {
            background: var(--qc-primary-800);
            border-color: var(--qc-primary-800);
            color: #fff;
        }
        html.dark-mode .gis-map-btn.active-toggle {
            background: rgba(147, 197, 253, 0.15);
            color: #93c5fd;
        }
        html.dark-mode .gis-search-input {
            background: #171a1f;
            border-color: rgba(147, 197, 253, 0.35);
            color: #e4e6ea;
        }
        html.dark-mode .gis-search-input:focus { box-shadow: 0 0 0 2px rgba(33, 161, 214, 0.25); }
        html.dark-mode .gis-search-results { background: #1e2229; border-color: #2d323b; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5); }
        html.dark-mode .gis-search-result-item { border-bottom-color: #2d323b; color: #e4e6ea; }
        html.dark-mode .gis-search-result-item:hover { background: #26313c; color: #fff; }
        html.dark-mode .gis-search-result-item small { color: #7f8b99; }

        /* Leaflet maps */
        html.dark-mode .leaflet-container { background: #171a1f; }
        html.dark-mode .leaflet-tile-pane { filter: brightness(0.6) contrast(1.15) grayscale(0.2); }
        html.dark-mode .leaflet-bar { border-color: #2d323b; }
        html.dark-mode .leaflet-control-zoom a {
            background: #1e2229; color: #e4e6ea; border-color: #2d323b;
        }
        html.dark-mode .leaflet-control-zoom a:hover { background: #2d323b; }
        html.dark-mode .leaflet-control-attribution {
            background: rgba(30, 34, 41, 0.85); color: #9ca3af;
        }
        html.dark-mode .leaflet-control-attribution a { color: #93c5fd; }
        html.dark-mode .leaflet-popup-content-wrapper,
        html.dark-mode .leaflet-popup-tip { background: #22262e; color: #e4e6ea; }
        html.dark-mode .leaflet-popup-content-wrapper a { color: #93c5fd; }

        /* Citizen report form (modal) */
        html.dark-mode .modal-content { border-color: #2d323b; }
        html.dark-mode .citizen-report-hint { color: #9ca3af; }
        html.dark-mode .cr-verification-box { background: #171a1f; border-color: #333; }
        html.dark-mode .cr-form-group select,
        html.dark-mode .cr-form-group input,
        html.dark-mode .cr-form-group textarea,
        html.dark-mode .cr-otp-row input {
            background: #171a1f; color: #e4e6ea; border-color: #444;
        }
        html.dark-mode .cr-form-group select:focus,
        html.dark-mode .cr-form-group input:focus,
        html.dark-mode .cr-form-group textarea:focus {
            border-color: #90caf9;
            box-shadow: 0 0 0 3px rgba(144, 202, 249, 0.2);
        }
        html.dark-mode .cr-form-group label { color: #cbd5e1; }
        /* High-contrast field error states (dark mode) */
        html.dark-mode .cr-form-group .field-error { color: #fca5a5; }
        html.dark-mode .cr-form-group input.error,
        html.dark-mode .cr-form-group select.error,
        html.dark-mode .cr-form-group textarea.error { border-color: #f87171; }
        html.dark-mode .cr-form-group input.error:focus,
        html.dark-mode .cr-form-group select.error:focus,
        html.dark-mode .cr-form-group textarea.error:focus {
            border-color: #f87171;
            box-shadow: 0 0 0 3px rgba(248, 113, 113, 0.25);
        }
        html.dark-mode .cr-btn-outline { border-color: #90caf9; color: #90caf9; }
        html.dark-mode .cr-btn-outline:hover { background: #90caf9; color: #000; }
        html.dark-mode .cr-status.success { background: #13251a; color: #6ee7b7; border-color: #1f4d33; }
        html.dark-mode .cr-status.error { background: #2a1416; color: #fda4af; border-color: #5c2228; }
        html.dark-mode .cr-status.info { background: #0e2430; color: #7dd3fc; border-color: #1f4a5e; }
        html.dark-mode .file-upload-label { background: #171a1f; border-color: #444; }
        html.dark-mode .file-upload-label:hover { background: #1f232b; }
        /* High-contrast error state for the dropzone (dark mode) */
        html.dark-mode .file-upload-label.has-error {
            border-color: #f87171;
            background: #2a1416;
        }
        html.dark-mode .file-upload-label.has-error i { color: #fca5a5; }
        html.dark-mode .file-upload-hint { color: #7f8b99; }
        html.dark-mode .file-count { color: #9ca3af; }
        html.dark-mode .photo-preview-item { border-color: #444; }

        /* Misc */
        html.dark-mode .alert-success { background: #13251a; color: #6ee7b7; border-color: #1f4d33; }
        html.dark-mode .alert-danger { background: #2a1416; color: #fda4af; border-color: #5c2228; }
        html.dark-mode .alert-info { background: #0e2430; color: #7dd3fc; border-color: #1f4a5e; }
        html.dark-mode .alert-primary { background: #122a44; color: #93c5fd; border-color: #1e3a5f; }
        html.dark-mode .alert-secondary { background: #262a30; color: #c8cdd4; border-color: #3a3f47; }
        html.dark-mode .alert-dark { background: #2a2d33; color: #e4e6ea; border-color: #3a3f47; }
        html.dark-mode .alert-light { background: #1e2229; color: #e4e6ea; border-color: #2d323b; }
        html.dark-mode .alert-link { color: inherit; font-weight: 700; }
        html.dark-mode .alert-warning {
            background: #3a3418; color: #fde68a; border-color: #4a411f;
        }
        html.dark-mode .alert-warning h5 { color: #fde68a; }
        html.dark-mode .terms-scroll {
            background: #171a1f !important;
            border-color: #444 !important;
            color: #cbd5e1 !important;
        }
        html.dark-mode .modal-body h6 { color: #90caf9 !important; }
        html.dark-mode .restricted-card { background: #1e2229; }
        html.dark-mode .restricted-card p { color: #9ca3af; }
        html.dark-mode .restricted-card h2 { color: #e4e6ea; }

        /* ============================================================
           LANDING PAGE MOBILE RESPONSIVENESS (scoped to index.php)
           ============================================================ */

        /* Stop fixed-width map elements from causing horizontal scroll */
        @media (max-width: 767.98px) {
            body { overflow-x: hidden; }
        }

        /* Small phones & narrow viewports */
        @media (max-width: 575.98px) {
            /* Navbar brand — compact, keeps clear of the hamburger button */
            .qc-navbar { padding: 0.4rem 0; }
            .qc-navbar .container-fluid { padding-right: 64px; padding-left: 14px; }
            .qc-brand { gap: 9px; }
            .qc-brand img { height: 38px; }
            .qc-brand-text strong { font-size: 0.9rem; line-height: 1.15; }
            .qc-brand-text small { font-size: 0.6rem; letter-spacing: 0.4px; }

            /* Hero */
            .hero { padding: 150px 0 74px; }
            .hero h1 { font-size: 1.72rem; margin-bottom: 12px; }
            .hero p.lead { font-size: 0.97rem; margin-bottom: 24px; }
            .hero-buttons { flex-direction: column; align-items: stretch; }
            .hero-buttons .btn-hero {
                width: 100%;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            /* Sections & cards */
            .section { padding: 48px 0 40px; }
            .section-subtitle { font-size: 0.95rem; margin-bottom: 36px; }
            .update-card .card-header { padding: 14px 96px 12px 16px; }

            /* Statistics — keep numbers from overflowing 2-col layout */
            .stat-card { padding: 22px 10px; }
            .stat-number { font-size: 1.75rem; }
            .stat-icon { width: 54px; height: 54px; font-size: 1.35rem; }

            /* Before/after info */
            .before-after-info { padding: 16px; }

            /* Footer */
            .footer-contact-row { gap: 10px; font-size: 13px; }
            .contact-separator { display: none; }

            /* Citizen report modal */
            .modal-body { padding: 18px; }
            .cr-verification-box { padding: 14px; }
            .cr-btn { padding: 10px 16px; }
        }

        /* Very small phones (<= 360px) */
        @media (max-width: 359.98px) {
            .hero h1 { font-size: 1.5rem; }
            .qc-brand img { height: 34px; }
            .qc-brand-text strong { font-size: 0.8rem; }
            .qc-brand-text small { font-size: 0.55rem; }
            .stat-number { font-size: 1.55rem; }
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
            <?php include __DIR__ . '/includes/navbar_quicklinks.php'; ?>
        </div>
    </nav>

    <?php include __DIR__ . '/includes/mobile_navbar_css.php'; ?>

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
                <button type="button" id="makeReportBtn" class="btn btn-primary-hero btn-hero" aria-haspopup="dialog">
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
                    <div class="citizen-report-map-wrap">
                        <div class="gis-map-search-box">
                            <label for="citizenMapSearchInput" class="visually-hidden">Search for a location in Quezon City</label>
                            <input type="text" id="citizenMapSearchInput" placeholder="Search for a location..." class="gis-search-input" autocomplete="off">
                            <button type="button" class="gis-map-btn gis-search-btn" id="citizenMapSearchBtn" title="Search" aria-label="Search for a location"><i class="fas fa-search"></i></button>
                            <div id="citizenMapSearchResults" class="gis-search-results"></div>
                        </div>
                        <div class="citizen-report-map" id="citizenMap" role="region" aria-label="Interactive map - search for a location or click the map to pin the exact location of the issue"></div>
                    </div>
                    <p class="citizen-report-hint">
                        <i class="fas fa-mouse-pointer"></i> Search for a location or click on the map to pin the exact location of the issue
                        <br><small class="text-muted">Map is restricted to Quezon City area</small>
                    </p>

                    <form id="citizenReportForm" enctype="multipart/form-data" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>">
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
                                <label for="crPhone"><i class="fas fa-phone"></i> Phone Number <span class="text-danger">*</span></label>
                                <input type="tel" name="phone" id="crPhone" required placeholder="0917 123 4567 or +639 17 123 4567" inputmode="numeric" autocomplete="tel" aria-describedby="crPhoneError">
                                <div class="field-error" id="crPhoneError" role="alert">Please enter a valid Philippine mobile number.</div>
                            </div>
                            <div class="cr-form-group">
                                <label><i class="fas fa-comment"></i> Description <span class="text-danger">*</span></label>
                                <textarea name="description" id="crDescription" rows="3" required placeholder="Describe what you observed..."></textarea>
                            </div>
                        </div>

                        <div class="cr-form-group">
                            <label for="crPhotos"><i class="fas fa-camera"></i> Add Photos <span class="text-danger">*</span></label>
                            <div class="file-upload-area">
                                <input type="file" name="photos[]" id="crPhotos" multiple accept="image/jpeg,image/jpg,image/png" required aria-describedby="crPhotosError">
                                <label for="crPhotos" class="file-upload-label">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <span class="file-upload-text">Add Files</span>
                                    <span class="file-upload-hint">Click here to select multiple photos</span>
                                </label>
                                <span class="file-count" id="fileCount">No files selected</span>
                            </div>
                            <div class="field-error" id="crPhotosError" role="alert">Please upload at least 2 photos before submitting your report.</div>
                            <div id="photoPreview" class="photo-preview-grid"></div>
                            <small class="text-muted">Click <strong>Add Files</strong> to choose photos. You can select multiple at once. Click the <strong>X</strong> on a photo to remove it.</small>
                        </div>

                        <div class="cr-verification-box">
                            <h4><i class="fas fa-shield-alt"></i> Gmail Verification</h4>
                            <p style="font-size:0.85rem;color:#666;margin-bottom:12px;">
                                Enter your Gmail to receive a verification code. Limit of <strong>2 reports per day</strong>.
                            </p>
                            <div class="cr-otp-row">
                                <label for="crEmail" class="visually-hidden">Gmail address</label>
                                <input type="email" id="crEmail" placeholder="your.email@gmail.com" required autocomplete="email">
                                <button type="button" class="cr-btn cr-btn-primary" id="sendOtpBtn"><i class="fas fa-paper-plane"></i> Send Code</button>
                            </div>
                            <div class="cr-otp-row" style="margin-top:10px;">
                                <label for="crOtp" class="visually-hidden">Verification code</label>
                                <input type="text" id="crOtp" placeholder="Enter 6-digit code" maxlength="6" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code">
                                <button type="button" class="cr-btn cr-btn-success" id="verifyOtpBtn" disabled><i class="fas fa-check"></i> Verify</button>
                            </div>
                            <div id="crOtpStatus" class="cr-status" role="status" aria-live="polite"></div>
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
                                    $image_candidates = [];
                                    if (!empty($update['attachments'])):
                                        $attachments = json_decode($update['attachments'], true);
                                        if (is_array($attachments) && !empty($attachments)):
                                            foreach ($attachments as $attachment):
                                                if (isset($attachment['type']) && $attachment['type'] === 'image' && isset($attachment['file_path'])):
                                                    $image_candidates[] = $attachment['file_path'];
                                                    break;
                                                endif;
                                            endforeach;
                                        endif;
                                    endif;
                                    if (!empty($update['image_path']) && $update['image_path'] !== '0' && $update['image_path'] !== 'null'):
                                        $image_candidates[] = $update['image_path'];
                                    endif;
                                    if (!empty($update['_first_image'])):
                                        $image_candidates[] = $update['_first_image'];
                                    endif;
                                    $display_image = '';
                                    foreach ($image_candidates as $candidate):
                                        $resolved = road_updates_resolve_image_url($candidate, $basePath);
                                        if ($resolved) { $display_image = $resolved; break; }
                                    endforeach;
                                    if ($display_image): ?>
                                        <div class="mt-3">
                                            <img src="<?php echo htmlspecialchars($display_image); ?>" 
                                                 alt="<?php echo htmlspecialchars(($update['title'] ?? 'Road update') . ' report photo'); ?>" 
                                                 loading="lazy"
                                                 class="img-fluid rounded shadow-sm"
                                                 style="max-height: 200px; object-fit: cover; width: 100%; cursor: pointer;"
                                                 onclick="window.open(this.src, '_blank')"
                                                 title="Click to view full size">
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
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH" crossorigin="anonymous"></script>
    <!-- TomTom Services (JS client for API proxy) -->
    <script>
        window.TOMTOM_API_PROXY = 'lgu_staff/pages/api/tomtom/proxy.php';
        window.LG_ASSET_CONFIG = {
            TOMTOM_API_KEY: <?php echo json_encode(defined('TOMTOM_API_KEY') ? TOMTOM_API_KEY : ''); ?>,
            CITIZEN_API: 'lgu_staff/pages/api/citizen_report.php'
        };
    </script>
    <script src="lgu_staff/js/tomtom-services.js?v=<?php echo $asset_version; ?>"></script>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>

    <!-- Custom JavaScript -->
    <script src="assets/js/main.js?v=<?php echo $asset_version; ?>"></script>


    <?php include __DIR__ . '/includes/hamburger_menu_js.php'; ?>

    <script src="lgu_staff/js/page-transition.js"></script>
    <?php include __DIR__ . '/includes/a11y_js.php'; ?>

    <!-- QC Boundary Data -->
    <script src="assets/js/qc-boundary.js?v=<?php echo $asset_version; ?>"></script>

    <!-- Citizen Report (map, OTP, photo upload, submit) -->
    <script src="assets/js/citizen-report.js?v=<?php echo $asset_version; ?>"></script>

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

    <script src="assets/js/terms-modal.js?v=<?php echo $asset_version; ?>"></script>
</body>
</html>
