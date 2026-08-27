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

/**
 * Safely format a date string. Returns a fallback if the value is empty or
 * unparseable, avoiding PHP deprecation warnings from strtotime(false|null).
 */
function safe_date_fmt($date, $format = 'M d, Y', $fallback = '—') {
    if (empty($date)) return $fallback;
    $ts = @strtotime((string)$date);
    return ($ts !== false && $ts > 0) ? date($format, $ts) : $fallback;
}

// Try to include database files with error handling
$database_available = false;
$conn = null;

try {
    require_once 'lgu_staff/includes/config.php';
    require_once 'lgu_staff/includes/functions.php';
    require_once 'lgu_staff/includes/public_announcements.php';
    $database_available = true;
} catch (Exception $e) {
    error_log("index.php: failed to load database config: " . $e->getMessage());
    $database_available = false;
    $conn = null;
} catch (Error $e) {
    error_log("index.php: failed to load database config (fatal): " . $e->getMessage());
    $database_available = false;
    $conn = null;
}

// Get latest road updates for display
$road_updates = [];
if ($database_available && $conn) {
    try {
        // Check if the expected columns exist
        $stmt = $conn->prepare("DESCRIBE road_transportation_reports");
        if (!$stmt) {
            error_log("index.php: DESCRIBE road_transportation_reports failed: " . $conn->error);
            throw new Exception("prepare failed");
        }
        $stmt->execute();
        $result = $stmt->get_result();
        if (!$result) {
            $stmt->close();
            throw new Exception("get_result failed");
        }
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
        if (!$stmt) {
            error_log("index.php: road updates SELECT failed: " . $conn->error);
            throw new Exception("prepare failed");
        }
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
            if ($media_stmt) {
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
        $stmt = $conn->prepare(
            "SELECT
                COUNT(*) AS total_reports,
                SUM(status = 'in-progress') AS ongoing_repairs,
                SUM(status = 'completed') AS resolved_issues,
                SUM(status = 'pending') AS pending_reports
             FROM road_transportation_reports"
        );
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result) {
                $row = $result->fetch_assoc();
                $stats['total_reports']  = (int)($row['total_reports'] ?? 0);
                $stats['ongoing_repairs'] = (int)($row['ongoing_repairs'] ?? 0);
                $stats['resolved_issues'] = (int)($row['resolved_issues'] ?? 0);
                $stats['pending_reports'] = (int)($row['pending_reports'] ?? 0);
            }
            $stmt->close();
        } else {
            error_log("index.php: stats query prepare failed: " . $conn->error);
        }
    } catch (Exception $e) {
        error_log("index.php statistics query: " . $e->getMessage());
    }
}



// Public Transparency announcements (index.php only; not internal dashboards)
$public_announcements = [];
if ($database_available && $conn && function_exists('public_announcements_fetch_published')) {
    try {
        $public_announcements = public_announcements_fetch_published($conn, 12);
    } catch (Exception $e) {
        error_log("index.php public announcements query: " . $e->getMessage());
        $public_announcements = [];
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
    <link rel="icon" type="image/png" href="assets/img/infra-gov-logo.png">
    
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
    <!-- Leaflet CSS (citizen / infra maps; public GIS CSS loaded via a11y_css) -->
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

            .footer-contact-row {
                justify-content: center;
                flex-wrap: wrap;
            }

            .footer-links-row {
                justify-content: center;
                gap: 16px;
            }
        }

        /* System Announcements (from Public Transparency) */
        .announcements-public-section {
            background: #ffffff;
        }

        .announcement-public-card {
            background: #fff;
            border: 1px solid var(--qc-card-border);
            border-radius: 14px;
            overflow: hidden;
            height: 100%;
            display: flex;
            flex-direction: column;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .announcement-public-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 28px rgba(17, 82, 114, 0.12);
        }

        .announcement-public-photo {
            width: 100%;
            height: 240px;
            background: #e8eef3;
            overflow: hidden;
        }

        .announcement-public-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .announcement-public-body {
            padding: 22px 22px 24px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            flex: 1;
        }

        .announcement-public-body h3 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--qc-primary-900);
            line-height: 1.35;
        }

        .announcement-public-message {
            margin: 0;
            color: #4a5c6a;
            font-size: 0.95rem;
            line-height: 1.6;
            white-space: pre-wrap;
            flex: 1;
        }

        .announcement-public-date {
            font-size: 0.85rem;
            color: #6b7c8a;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .announcement-public-empty {
            text-align: center;
            padding: 36px 20px;
            color: #6b7c8a;
        }

        .announcement-public-empty i {
            font-size: 2.5rem;
            margin-bottom: 12px;
            color: var(--qc-primary-800);
            opacity: 0.55;
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
        #cr-location-info {
            display: none;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.08), rgba(55, 98, 200, 0.08));
            border: 1px solid rgba(16, 185, 129, 0.25);
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 16px;
        }
        #cr-location-info .gis-field-tag {
            display: inline-block;
            background: rgba(55, 98, 200, 0.1);
            color: #1e3c72;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
            margin-right: 6px;
            margin-bottom: 3px;
        }
        #cr-location-info .gis-field-tag .gis-tag-label {
            color: #666;
            font-weight: 400;
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
        html.dark-mode #cr-location-info {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(55, 98, 200, 0.12));
            border-color: rgba(16, 185, 129, 0.35);
        }
        html.dark-mode #cr-location-info .gis-field-tag {
            background: rgba(55, 98, 200, 0.2);
            color: #93c5fd;
        }
        html.dark-mode #cr-location-info .gis-field-tag .gis-tag-label { color: #9ca3af; }
        html.dark-mode #cr-location-info strong { color: #e4e6ea; }
        html.dark-mode #cr-location-details { color: #cbd5e1; }
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

        /* ============================================================
           SMART MOBILITY HUB - NEW FEATURES
           ============================================================ */

        /* 5. Emergency Transit Advisory Ticker */
        .emergency-ticker {
            margin-top: 62px;
            background: linear-gradient(90deg, #b91c1c 0%, #dc2626 50%, #ef4444 100%);
            color: #fff;
            padding: 12px 0;
            font-size: 0.92rem;
            line-height: 1.4;
            position: relative;
            z-index: 1020;
            box-shadow: 0 2px 8px rgba(185,28,28,0.25);
            animation: tickerSlideDown 0.4s ease;
        }
        @keyframes tickerSlideDown { from { transform: translateY(-100%); opacity:0;} to { transform: translateY(0); opacity:1; } }
        .emergency-ticker.dismissed { display: none !important; }
        .emergency-ticker-badge {
            background: rgba(255,255,255,0.22);
            border: 1px solid rgba(255,255,255,0.35);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .emergency-ticker-badge i { animation: tickerPulse 1.2s infinite; }
        @keyframes tickerPulse { 0%,100% { opacity:1; } 50% { opacity:0.55; } }
        .emergency-ticker-text { color: #fff; }
        .emergency-ticker-text strong { color: #fff; }
        .emergency-ticker-link {
            color: #fff !important;
            font-weight: 700;
            text-decoration: underline;
            text-underline-offset: 3px;
            margin-left: 10px;
            white-space: nowrap;
            background: rgba(255,255,255,0.18);
            padding: 4px 10px;
            border-radius: 6px;
            transition: background 0.2s;
        }
        .emergency-ticker-link:hover { background: rgba(255,255,255,0.28); color: #fff !important; }
        .emergency-ticker-dismiss {
            background: rgba(255,255,255,0.18);
            border: 1px solid rgba(255,255,255,0.3);
            color: #fff;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .emergency-ticker-dismiss:hover { background: rgba(255,255,255,0.32); transform: rotate(90deg); }

        /* 1. Interactive Public Transportation Widget */
        .transport-card {
            background: #fff;
            border: 1px solid var(--qc-card-border);
            border-radius: 14px;
            padding: 28px 22px;
            text-align: center;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
        }
        .transport-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 28px rgba(17,82,114,0.12);
            border-color: var(--qc-primary-300);
        }
        .transport-icon {
            width: 72px;
            height: 72px;
            background: var(--qc-icon-bg);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.9rem;
            color: var(--qc-primary-800);
            margin-bottom: 16px;
        }
        .transport-card h5 {
            font-weight: 800;
            color: var(--qc-primary-900);
            font-size: 1.05rem;
            margin-bottom: 8px;
        }
        .transport-card p {
            font-size: 0.9rem;
            color: var(--qc-shades-500);
            line-height: 1.6;
            flex: 1;
            margin-bottom: 18px;
        }
        .transport-card-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--qc-primary-700);
            text-decoration: none;
            border: 1px solid var(--qc-card-border);
            padding: 8px 14px;
            border-radius: 8px;
            transition: all 0.2s;
            background: #fff;
            cursor: pointer;
            font-family: inherit;
        }
        .transport-card-link:hover {
            background: var(--qc-primary-800);
            color: #fff;
            border-color: var(--qc-primary-800);
        }
        .transport-badge {
            display: inline-block;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            padding: 4px 10px;
            border-radius: 20px;
            margin-bottom: 10px;
        }
        .badge-bus { background: #dbeafe; color: #1e40af; }
        .badge-jeep { background: #fef3c7; color: #92400e; }
        .badge-bike { background: #dcfce7; color: #166534; }

        /* 2. Live Traffic Congestion Layer */
        .live-traffic-card {
            background: #fff;
            border: 1px solid var(--qc-card-border);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(17,82,114,0.06);
        }
        .live-traffic-header {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 22px;
            background: #f8fafc;
            border-bottom: 1px solid var(--qc-card-border);
        }
        .live-traffic-header h4 {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--qc-primary-900);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .live-traffic-header h4 i { color: var(--qc-red); }
        .traffic-toggle-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--qc-primary-900);
        }
        .traffic-switch {
            position: relative;
            width: 52px;
            height: 28px;
            background: #cbd5e1;
            border-radius: 28px;
            cursor: pointer;
            transition: background 0.25s;
            border: none;
            flex-shrink: 0;
        }
        .traffic-switch.active { background: #16a34a; }
        .traffic-switch::after {
            content: '';
            position: absolute;
            width: 22px;
            height: 22px;
            background: #fff;
            border-radius: 50%;
            top: 3px;
            left: 3px;
            transition: transform 0.25s;
            box-shadow: 0 1px 4px rgba(0,0,0,0.2);
        }
        .traffic-switch.active::after { transform: translateX(24px); }
        .traffic-status {
            font-size: 0.8rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
            background: #f1f5f9;
            color: #64748b;
        }
        .traffic-status.live { background: #dcfce7; color: #166534; }
        #liveTrafficMap {
            height: 420px;
            width: 100%;
            background: #e8eef3;
        }
        .traffic-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            padding: 12px 22px;
            background: #fff;
            border-top: 1px solid var(--qc-card-border);
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--qc-shades-500);
        }
        .traffic-legend span { display: inline-flex; align-items: center; gap: 6px; }
        .traffic-legend i { width: 14px; height: 8px; border-radius: 4px; display: inline-block; }
        .legend-green { background: #22c55e; }
        .legend-yellow { background: #eab308; }
        .legend-red { background: #ef4444; }
        .legend-dark { background: #7f1d1d; }

        /* 3. Quick-Access Category Filter Bar */
        .road-filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: center;
            margin-bottom: 36px;
            padding: 16px;
            background: #f8fafc;
            border: 1px solid var(--qc-card-border);
            border-radius: 12px;
        }
        .filter-pill {
            border: 1px solid var(--qc-card-border);
            background: #fff;
            color: var(--qc-primary-800);
            font-size: 0.85rem;
            font-weight: 700;
            padding: 8px 18px;
            border-radius: 999px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }
        .filter-pill:hover { border-color: var(--qc-primary-300); background: var(--qc-primary-50); transform: translateY(-1px); }
        .filter-pill.active {
            background: var(--qc-primary-800);
            color: #fff;
            border-color: var(--qc-primary-800);
            box-shadow: 0 4px 10px rgba(17,82,114,0.22);
        }
        .filter-pill .count {
            background: rgba(0,0,0,0.08);
            padding: 2px 7px;
            border-radius: 20px;
            font-size: 0.72rem;
        }
        .filter-pill.active .count { background: rgba(255,255,255,0.22); }
        .update-card.filtered-out { display: none !important; }
        .filter-empty {
            display: none;
            text-align: center;
            padding: 36px 20px;
            color: var(--qc-shades-400);
        }
        .filter-empty.show { display: block; }
        .filter-empty i { font-size: 2rem; margin-bottom: 10px; color: var(--qc-shades-300); }

        /* 4. Commuter FAQ Accordion */
        .faq-accordion .accordion-item {
            border: 1px solid var(--qc-card-border);
            border-radius: 12px !important;
            margin-bottom: 12px;
            overflow: hidden;
            background: #fff;
        }
        .faq-accordion .accordion-button {
            font-weight: 700;
            color: var(--qc-primary-900);
            background: #fff;
            padding: 18px 20px;
            font-size: 0.95rem;
            gap: 12px;
        }
        .faq-accordion .accordion-button:not(.collapsed) {
            background: var(--qc-primary-50);
            color: var(--qc-primary-800);
            box-shadow: none;
        }
        .faq-accordion .accordion-button:focus { box-shadow: none; border-color: var(--qc-primary-200); }
        .faq-accordion .accordion-button::after { background-size: 1rem; }
        .faq-accordion .accordion-body {
            padding: 16px 20px 20px;
            color: var(--qc-shades-600);
            font-size: 0.92rem;
            line-height: 1.7;
            background: #fff;
        }
        .faq-accordion .accordion-body strong { color: var(--qc-primary-800); }
        .faq-icon {
            width: 36px;
            height: 36px;
            background: var(--qc-icon-bg);
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--qc-primary-800);
            font-size: 0.95rem;
            flex-shrink: 0;
        }

        /* Responsive tweaks for new sections */
        @media (max-width: 768px) {
            .emergency-ticker { font-size: 0.85rem; padding: 10px 0; }
            .emergency-ticker-link { margin-left: 0; margin-top: 6px; }
            #liveTrafficMap { height: 340px; }
            .live-traffic-header { flex-direction: column; align-items: flex-start; }
            .road-filter-bar { justify-content: flex-start; overflow-x: auto; flex-wrap: nowrap; padding-bottom: 10px; -webkit-overflow-scrolling: touch; }
            .road-filter-bar::-webkit-scrollbar { display: none; }
            .filter-pill { white-space: nowrap; }
        }

        /* Dark mode for new components */
        html.dark-mode .emergency-ticker { background: linear-gradient(90deg, #7f1d1d 0%, #991b1b 100%); }
        html.dark-mode .transport-card { background: #1e2229; border-color: #2d323b; }
        html.dark-mode .transport-card p { color: #9ca3af; }
        html.dark-mode .transport-card-link { border-color: #3a3f47; color: #93c5fd; background: #1e2229; }
        html.dark-mode .transport-card-link:hover { background: var(--qc-primary-700); color: #fff; }
        html.dark-mode .live-traffic-card { background: #1e2229; border-color: #2d323b; }
        html.dark-mode .live-traffic-header { background: #171a1f; border-color: #2d323b; }
        html.dark-mode .traffic-status { background: #2d323b; color: #9ca3af; }
        html.dark-mode .traffic-status.live { background: #13251a; color: #6ee7b7; }
        html.dark-mode .traffic-legend { background: #1e2229; border-color: #2d323b; }
        html.dark-mode .road-filter-bar { background: #171a1f; border-color: #2d323b; }
        html.dark-mode .filter-pill { background: #1e2229; border-color: #3a3f47; color: #cbd5e1; }
        html.dark-mode .filter-pill:hover { background: #26313c; }
        html.dark-mode .filter-pill.active { background: var(--qc-primary-800); color: #fff; }
        html.dark-mode .faq-accordion .accordion-item { background: #1e2229; border-color: #2d323b; }
        html.dark-mode .faq-accordion .accordion-button { background: #1e2229; color: #e4e6ea; }
        html.dark-mode .faq-accordion .accordion-button:not(.collapsed) { background: #26313c; color: #93c5fd; }
        html.dark-mode .faq-accordion .accordion-body { background: #1e2229; color: #9ca3af; }
        html.dark-mode .faq-icon { background: #26313c; color: #93c5fd; }

        /* ============================================================
           LANDING PAGE ONLY — Premium modal styling for public-transport
           (qcBusRoutesModal / jeepneyRoutesModal / bikeLaneModal) — light + dark
           ============================================================ */
        /* Premium base — all three modals */
        #qcBusRoutesModal .modal-content,
        #jeepneyRoutesModal .modal-content,
        #bikeLaneModal .modal-content {
            border: none !important;
            border-radius: 16px !important;
            overflow: hidden !important;
            box-shadow: 0 25px 60px rgba(15,23,42,0.35), 0 8px 24px rgba(15,23,42,0.18) !important;
            backdrop-filter: blur(0px);
        }
        #qcBusRoutesModal .modal-header,
        #jeepneyRoutesModal .modal-header,
        #bikeLaneModal .modal-header {
            border-bottom: none !important;
            position: relative;
        }
        #qcBusRoutesModal .modal-header::after,
        #jeepneyRoutesModal .modal-header::after,
        #bikeLaneModal .modal-header::after {
            content: '';
            position: absolute;
            left: 0; right: 0; bottom: 0;
            height: 1px;
            background: linear-gradient(90deg, rgba(255,255,255,0.18), rgba(255,255,255,0));
        }
        #qcBusRoutesModal .modal-footer,
        #jeepneyRoutesModal .modal-footer,
        #bikeLaneModal .modal-footer {
            backdrop-filter: blur(6px);
        }
        /* Card hover premium */
        #qcBusRoutesModal .row.g-3 > div > div,
        #jeepneyRoutesModal .row.g-3 > div > div,
        #bikeLaneModal .row.g-3 > div > div {
            transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
        }
        #qcBusRoutesModal .row.g-3 > div > div:hover,
        #jeepneyRoutesModal .row.g-3 > div > div:hover,
        #bikeLaneModal .row.g-3 > div > div:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(15,23,42,0.08);
        }

        /* ——— Dark mode premium overrides — landing page modals only ——— */
        html.dark-mode #qcBusRoutesModal .modal-content,
        html.dark-mode #jeepneyRoutesModal .modal-content,
        html.dark-mode #bikeLaneModal .modal-content {
            background: #0f141c !important;
            border: 1px solid #1e2e46 !important;
            box-shadow: 0 30px 80px rgba(0,0,0,0.65), 0 0 0 1px rgba(147,197,253,0.06) inset !important;
        }
        html.dark-mode #qcBusRoutesModal .modal-body,
        html.dark-mode #jeepneyRoutesModal .modal-body,
        html.dark-mode #bikeLaneModal .modal-body {
            background: #0f141c !important;
        }
        html.dark-mode #qcBusRoutesModal .modal-footer,
        html.dark-mode #jeepneyRoutesModal .modal-footer,
        html.dark-mode #bikeLaneModal .modal-footer {
            background: #0c1220 !important;
            border-top-color: #1e2e46 !important;
        }
        /* Summary header blocks — override inline backgrounds */
        html.dark-mode #qcBusRoutesModal .modal-body > div:first-child {
            background: linear-gradient(180deg, #0f1d2e 0%, #0c1220 100%) !important;
            border-bottom-color: #1e3a5a !important;
        }
        html.dark-mode #jeepneyRoutesModal .modal-body > div:first-child {
            background: linear-gradient(180deg, #1a1406 0%, #141009 100%) !important;
            border-bottom-color: #3a2a0a !important;
        }
        html.dark-mode #bikeLaneModal .modal-body > div:first-child {
            background: linear-gradient(180deg, #071a10 0%, #0a1410 100%) !important;
            border-bottom-color: #143a24 !important;
        }
        html.dark-mode #qcBusRoutesModal .modal-body > div:first-child *,
        html.dark-mode #jeepneyRoutesModal .modal-body > div:first-child *,
        html.dark-mode #bikeLaneModal .modal-body > div:first-child * {
            color: #cbd5e1 !important;
        }
        html.dark-mode #qcBusRoutesModal .modal-body > div:first-child i,
        html.dark-mode #jeepneyRoutesModal .modal-body > div:first-child i,
        html.dark-mode #bikeLaneModal .modal-body > div:first-child i {
            color: #93c5fd !important;
        }
        /* Row cards — override inline background:#fff / border */
        html.dark-mode #qcBusRoutesModal .row.g-3 > div > div,
        html.dark-mode #jeepneyRoutesModal .row.g-3 > div > div,
        html.dark-mode #bikeLaneModal .row.g-3 > div > div {
            background: #162032 !important;
            border-color: #1e2e46 !important;
            box-shadow: 0 8px 20px rgba(0,0,0,0.35) !important;
        }
        /* Jeepney amber left-border stays but slightly muted in dark */
        html.dark-mode #jeepneyRoutesModal .row.g-3 > div > div[style*="border-left"] {
            border-left-color: #b45309 !important;
        }
        html.dark-mode #bikeLaneModal .row.g-3 > div > div[style*="border-left"] {
            border-left-color: #16a34a !important;
        }
        /* Text & list items inside cards */
        html.dark-mode #qcBusRoutesModal .row.g-3 h6,
        html.dark-mode #jeepneyRoutesModal .row.g-3 h6,
        html.dark-mode #bikeLaneModal .row.g-3 h6 {
            color: #e2e8f0 !important;
        }
        html.dark-mode #qcBusRoutesModal .row.g-3 ul,
        html.dark-mode #qcBusRoutesModal .row.g-3 li,
        html.dark-mode #jeepneyRoutesModal .row.g-3 ul,
        html.dark-mode #jeepneyRoutesModal .row.g-3 li,
        html.dark-mode #bikeLaneModal .row.g-3 ul,
        html.dark-mode #bikeLaneModal .row.g-3 li {
            color: #cbd5e1 !important;
        }
        html.dark-mode #qcBusRoutesModal .row.g-3 .small,
        html.dark-mode #jeepneyRoutesModal .row.g-3 .small,
        html.dark-mode #bikeLaneModal .row.g-3 .small {
            color: #94a3b8 !important;
        }
        html.dark-mode #qcBusRoutesModal .row.g-3 strong,
        html.dark-mode #jeepneyRoutesModal .row.g-3 strong,
        html.dark-mode #bikeLaneModal .row.g-3 strong {
            color: #e0f2fe !important;
        }
        /* Badges inside cards — subtle dark variant */
        html.dark-mode #qcBusRoutesModal .badge.bg-light,
        html.dark-mode #jeepneyRoutesModal .badge.bg-light,
        html.dark-mode #bikeLaneModal .badge.bg-light {
            background: #1e293b !important;
            color: #93c5fd !important;
            border-color: #334155 !important;
        }
        /* Amenities / QCitizen note boxes */
        html.dark-mode #qcBusRoutesModal div[style*="background:#fff7ed"],
        html.dark-mode #jeepneyRoutesModal div[style*="background:#f8fafc"],
        html.dark-mode #bikeLaneModal div[style*="border:1px dashed"] {
            background: #1a2333 !important;
            border-color: #1e3a5a !important;
        }
        html.dark-mode #qcBusRoutesModal div[style*="background:#fff7ed"] *,
        html.dark-mode #jeepneyRoutesModal div[style*="background:#f8fafc"] *,
        html.dark-mode #bikeLaneModal div[style*="border:1px dashed"] * {
            color: #cbd5e1 !important;
        }
        html.dark-mode #qcBusRoutesModal div[style*="background:#fff7ed"] h6,
        html.dark-mode #bikeLaneModal div[style*="border:1px dashed"] h6 {
            color: #fde68a !important;
        }
        /* Links inside dark modals */
        html.dark-mode #qcBusRoutesModal a,
        html.dark-mode #jeepneyRoutesModal a,
        html.dark-mode #bikeLaneModal a {
            color: #93c5fd !important;
        }
        /* Scrollbar for premium feel */
        #qcBusRoutesModal .modal-body::-webkit-scrollbar,
        #jeepneyRoutesModal .modal-body::-webkit-scrollbar,
        #bikeLaneModal .modal-body::-webkit-scrollbar { width: 8px; }
        #qcBusRoutesModal .modal-body::-webkit-scrollbar-thumb,
        #jeepneyRoutesModal .modal-body::-webkit-scrollbar-thumb,
        #bikeLaneModal .modal-body::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
        html.dark-mode #qcBusRoutesModal .modal-body::-webkit-scrollbar-track,
        html.dark-mode #jeepneyRoutesModal .modal-body::-webkit-scrollbar-track,
        html.dark-mode #bikeLaneModal .modal-body::-webkit-scrollbar-track { background: #0f141c; }
        html.dark-mode #qcBusRoutesModal .modal-body::-webkit-scrollbar-thumb,
        html.dark-mode #jeepneyRoutesModal .modal-body::-webkit-scrollbar-thumb,
        html.dark-mode #bikeLaneModal .modal-body::-webkit-scrollbar-thumb { background: #334155; }
    </style>
    <style>
        /* View Route Map Modal — GIS sized for modal only (not fullscreen like .public-gis-dialog) */
        #viewRouteMapModal .modal-dialog { max-width: 860px; }
        #viewRouteGisMap, #qcBusRoutesGisMap {
            width: 100%;
            height: 420px;
            min-height: 320px;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid rgba(55, 98, 200, 0.2);
            background: #e8eef8;
            z-index: 1;
        }
        #qcBusRoutesGisMap { height: 360px; min-height: 280px; }
        #qcBusRoutesModal .modal-content {
            background: #f7f5f0;
            border: 1px solid rgba(255,255,255,0.12);
        }
        #qcBusRoutesModal .modal-body { background: #f7f5f0; }
        #viewRouteMapModal .modal-content {
            background: #f7f5f0;
            border: 1px solid rgba(255,255,255,0.12);
            box-shadow: 0 24px 64px rgba(0,0,0,0.35);
        }
        #viewRouteMapModal .modal-body { background: #f7f5f0; }
        .view-route-gis-wrap {
            display: flex;
            flex-direction: column;
            gap: 10px;
            position: relative;
        }
        .view-route-dropdown-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            background: #fff;
            border: 1px solid var(--qc-card-border);
            border-radius: 10px;
            padding: 10px 12px;
            box-shadow: 0 2px 8px rgba(17,82,114,0.06);
        }
        .view-route-dropdown-bar label {
            font-weight: 700;
            font-size: 12px;
            color: var(--qc-primary-900);
            white-space: nowrap;
            margin: 0;
        }
        #viewRouteDropdown, #qcBusRoutesDropdown {
            flex: 1;
            min-width: 220px;
            max-width: 420px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid var(--qc-card-border);
            border-radius: 8px;
            padding: 6px 10px;
            background: #fff;
            color: var(--qc-primary-900);
        }
        #viewRouteDropdown:focus, #qcBusRoutesDropdown:focus {
            border-color: var(--qc-primary-500);
            box-shadow: 0 0 0 3px rgba(33,161,214,0.15);
            outline: none;
        }
        .view-route1-map-overlay {
            position: absolute;
            left: 12px;
            right: 12px;
            bottom: 18px;
            z-index: 500;
            background: rgba(255,255,255,0.96);
            border: 1px solid var(--qc-card-border);
            border-left: 4px solid var(--qc-primary-800);
            border-radius: 10px;
            padding: 10px 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
            font-size: 11px;
            line-height: 1.5;
            display: none;
        }
        .view-route1-map-overlay.is-visible { display: block; }
        .view-route1-map-overlay strong { color: var(--qc-primary-900); }
        .view-route1-stops { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin:6px 0; }
        .view-route1-stop { display:inline-flex; align-items:center; gap:4px; background:#fff; border:1px solid #e2e8f0; border-radius:20px; padding:2px 8px; font-size:10px; font-weight:600; color:#334155; }
        .view-route1-stop .dot { width:8px; height:8px; border-radius:50%; background: var(--qc-primary-600); display:inline-block; }
        .view-route1-stop .dot.start { background:#10b981; }
        .view-route1-stop .dot.end { background:#dc2626; }
        .view-route1-arrow { color:#94a3b8; font-size:10px; }
        @media (max-width: 768px) {
            #viewRouteGisMap { height: 320px; min-height: 260px; }
            .view-route1-map-overlay { left:8px; right:8px; bottom:12px; padding:8px 10px; }
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
                <img src="assets/img/infra-gov-logo.png" alt="Quezon City Hall Logo">
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
                        <input type="hidden" name="detected_district" id="crDistrict">
                        <input type="hidden" name="barangay" id="crBarangay">
                        <input type="hidden" name="street_name" id="crStreet">

                        <div id="cr-location-info">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                                <i class="fas fa-map-marked-alt" style="color:#10b981;font-size:15px;"></i>
                                <strong style="font-size:13px;color:#1e3c72;">Detected Location</strong>
                                <span id="cr-loading-badge" style="display:none;margin-left:auto;font-size:11px;color:#666;"><i class="fas fa-spinner fa-spin"></i> Detecting...</span>
                            </div>
                            <div id="cr-location-details" style="font-size:12px;color:#555;line-height:1.7;"></div>
                        </div>

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
            <h2 class="section-title">Road Updates</h2>
            <p class="section-subtitle">Stay informed about the latest road conditions and maintenance activities</p>

            <!-- 3. Quick-Access Category Filter Bar -->
            <div class="road-filter-bar" role="tablist" aria-label="Filter road updates by category">
                <button type="button" class="filter-pill active" data-filter="all" role="tab" aria-selected="true"><i class="fas fa-layer-group"></i> All</button>
                <button type="button" class="filter-pill" data-filter="traffic_light" role="tab" aria-selected="false"><i class="fas fa-traffic-light"></i> Traffic Lights</button>
                <button type="button" class="filter-pill" data-filter="accident" role="tab" aria-selected="false"><i class="fas fa-car-crash"></i> Accidents</button>
                <button type="button" class="filter-pill" data-filter="closure" role="tab" aria-selected="false"><i class="fas fa-road"></i> Road Closures</button>
                <button type="button" class="filter-pill" data-filter="pothole" role="tab" aria-selected="false"><i class="fas fa-exclamation-circle"></i> Potholes</button>
            </div>
            
            <div class="row g-4" id="roadUpdatesGrid">
                <?php if (!empty($road_updates)): ?>
                    <?php foreach ($road_updates as $update):
                        // Map DB report_type + title to filter category for pill filtering (non-destructive, display only)
                        $rt = strtolower((string)($update['report_type'] ?? ''));
                        $ttl = strtolower((string)($update['title'] ?? '') . ' ' . ($update['description'] ?? ''));
                        if (strpos($rt, 'traffic') !== false || strpos($rt, 'light') !== false || strpos($ttl, 'traffic light') !== false || strpos($ttl, 'signal') !== false) $filterCat = 'traffic_light';
                        elseif (strpos($rt, 'accident') !== false || strpos($ttl, 'accident') !== false || strpos($ttl, 'collision') !== false) $filterCat = 'accident';
                        elseif (strpos($rt, 'closure') !== false || strpos($rt, 'closed') !== false || strpos($ttl, 'closure') !== false || strpos($ttl, 'closed') !== false) $filterCat = 'closure';
                        elseif (strpos($rt, 'pothole') !== false || strpos($ttl, 'pothole') !== false) $filterCat = 'pothole';
                        else $filterCat = 'other';
                    ?>
                        <div class="col-md-4 road-update-item" data-category="<?php echo htmlspecialchars($filterCat); ?>">
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
                                        <?php echo safe_date_fmt($update['reported_date'] ?? ''); ?>
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
            <div id="filterEmptyState" class="filter-empty">
                <i class="fas fa-search"></i>
                <h6>No reports found in this category</h6>
                <p class="mb-2">Try selecting <strong>All</strong> or browse the full archive.</p>
                <button type="button" class="btn btn-sm btn-outline-dark" onclick="document.querySelector('.filter-pill[data-filter=all]').click()">Show All</button>
            </div>
            <div class="text-center mt-4">
                <a href="public_reports.php" class="btn btn-primary-hero btn-hero" style="font-size: 1rem; padding: 12px 28px;">
                    <i class="fas fa-list"></i> View All Road Reports
                </a>
            </div>
        </div>
    </section>

    <!-- 1. Interactive Public Transportation Widget -->
    <section class="section" id="public-transport">
        <div class="container">
            <h2 class="section-title">Public Transportation Hub</h2>
            <p class="section-subtitle">Plan your commute with official Quezon City mobility services — bus, jeepney, and bike infrastructure at a glance</p>
            <div class="row g-4">
                <div class="col-lg-4 col-md-6">
                    <div class="transport-card">
                        <span class="transport-badge badge-bus"><i class="fas fa-check-circle me-1"></i> Free Ride</span>
                        <div class="transport-icon"><i class="fas fa-bus"></i></div>
                        <h5>QC Bus Service</h5>
                        <p>8 free routes covering major corridors including Quezon Ave, Commonwealth, and EDSA. Low-floor, PWD-friendly units with fixed 20-min intervals.</p>
                        <button type="button" class="transport-card-link" data-bs-toggle="modal" data-bs-target="#qcBusRoutesModal" aria-label="View QC Bus Routes details"><i class="fas fa-bus"></i> View QC Bus Routes</button>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6">
                    <div class="transport-card">
                        <span class="transport-badge badge-jeep"><i class="fas fa-route me-1"></i> Rationalized</span>
                        <div class="transport-icon"><i class="fas fa-shuttle-van"></i></div>
                        <h5>Jeepney Rationalization</h5>
                        <p>City-approved consolidated routes with designated stops. Real-time dispatch from QC EDSA Carousel &amp; Litex terminals.</p>
                        <button type="button" class="transport-card-link" data-bs-toggle="modal" data-bs-target="#jeepneyRoutesModal" aria-label="View Jeepney Lines details"><i class="fas fa-shuttle-van"></i> View Jeepney Lines</button>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6">
                    <div class="transport-card">
                        <span class="transport-badge badge-bike"><i class="fas fa-leaf me-1"></i> 90+ KM Network</span>
                        <div class="transport-icon"><i class="fas fa-bicycle"></i></div>
                        <h5>Bike Lane Network</h5>
                        <p>Protected &amp; shared lanes along Elliptical, East Ave, Quezon Ave. With secure racks, repair stations, and park-and-ride links.</p>
                        <button type="button" class="transport-card-link" data-bs-toggle="modal" data-bs-target="#bikeLaneModal" aria-label="View Bike-Lane Map details"><i class="fas fa-bicycle"></i> View Bike-Lane Map</button>
                    </div>
                </div>
            </div>
            <div class="text-center mt-4">
                <small class="text-muted"><i class="fas fa-info-circle me-1"></i> Official schedules may change during holidays &amp; emergency rerouting — tap the <a href="#" onclick="document.getElementById('publicGisFab')?.click(); return false;"><i class="fas fa-map-marked-alt"></i> Live Road Map</a> FAB for current detours.</small>
            </div>
        </div>
    </section>

    <!-- QC Bus Routes Modal — generated from official Libreng Sakay data -->
    <div class="modal fade" id="qcBusRoutesModal" tabindex="-1" aria-labelledby="qcBusRoutesModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content" style="border-radius:14px; overflow:hidden;">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--qc-primary-800) 0%, #1d698b 100%); color:#fff; border-bottom:none; padding:16px 20px;">
                    <h5 class="modal-title d-flex align-items-center gap-2" id="qcBusRoutesModalLabel" style="font-weight:800; font-size:1.05rem; line-height:1.3;">
                        <span style="width:36px;height:36px;background:rgba(255,255,255,0.18);border-radius:8px;display:inline-flex;align-items:center;justify-content:center;"><i class="fas fa-bus"></i></span>
                        QC Bus Service — Libreng Sakay (8 Official Routes)
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <!-- Summary header — background edges exact to row -->
                    <div style="background: var(--qc-primary-50); border-bottom:1px solid var(--qc-card-border);">
                        <div class="row g-3 small p-3 p-md-4 mx-0">
                            <div class="col-md-4 d-flex gap-2"><i class="fas fa-clock mt-1" style="color:var(--qc-primary-700)"></i><div><strong>Operating Hours</strong><br><span class="text-muted">5:00 AM – 9:00 PM Daily<br>Mon–Sat full service • Sun/Holiday 6:00 AM – 8:00 PM</span></div></div>
                            <div class="col-md-4 d-flex gap-2"><i class="fas fa-stopwatch mt-1" style="color:var(--qc-primary-700)"></i><div><strong>Intervals</strong><br><span class="text-muted">Every <strong>10–15 mins</strong> (peak 6–9 AM, 5–8 PM)<br>Every <strong>20 mins</strong> off-peak</span></div></div>
                            <div class="col-md-4 d-flex gap-2"><i class="fas fa-id-card mt-1" style="color:var(--qc-primary-700)"></i><div><strong>Fare</strong><br><span class="text-muted"><span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:20px;font-weight:700;font-size:0.75rem;">LIBRE • FREE RIDE</span> Priority for QC residents</span></div></div>
                        </div>
                    </div>

                    <!-- GIS Map — Libreng Sakay routes dropdown (UI changed to GIS) -->
                    <div class="p-3 p-md-4" style="background:#f7f5f0; border-bottom:1px solid var(--qc-card-border);">
                        <div class="view-route-dropdown-bar">
                            <label for="qcBusRoutesDropdown"><i class="fas fa-bus me-1" style="color:var(--qc-primary-700)"></i> Select Libreng Sakay Route:</label>
                            <select id="qcBusRoutesDropdown" aria-label="Select Libreng Sakay route">
                                <option value="1" selected>Route 1: QC Hall to Cubao — Kalayaan Ave.</option>
                                <option value="2">Route 2: QC Hall to Litex / IBP Road — Commonwealth Ave</option>
                                <option value="3">Route 3: Welcome Rotonda to Aurora-Katipunan — E. Rodriguez / Aurora</option>
                                <option value="4">Route 4: QC Hall to General Luis — Mindanao / Quirino Hwy</option>
                                <option value="5">Route 5: QC Hall to Mindanao Ave. via Visayas Ave.</option>
                                <option value="6">Route 6: QC Hall to Gilmore — East Ave / E. Rodriguez</option>
                                <option value="7">Route 7: QC Hall to C5 / Ortigas Ave. Ext. — C-5</option>
                                <option value="8">Route 8: QC Hall to Muñoz — North Ave / Roosevelt</option>
                            </select>
                        </div>
                        <div id="qcBusRoutesGisMap" role="region" aria-label="QC City Bus GIS Map" style="margin-top:12px;"></div>
                        <small class="text-muted text-center d-block mt-2"><i class="fas fa-info-circle me-1"></i> GIS preview — select a Libreng Sakay route to see its stops and lane-following path on the map.</small>
                    </div>

                    <div class="p-3 p-md-4">
                        <div class="row g-3 g-md-4">
                            <!-- Route 1 -->
                            <div class="col-md-6">
                                <div class="h-100" style="border:1px solid var(--qc-card-border); border-radius:12px; padding:16px; background:#fff;">
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <span style="background:var(--qc-primary-800);color:#fff;font-weight:800;font-size:0.7rem;padding:4px 8px;border-radius:20px;">ROUTE 1</span>
                                        <h6 class="mb-0" style="font-weight:800; color:var(--qc-primary-900); font-size:0.95rem;">QC Hall to Cubao</h6>
                                    </div>
                                    <div class="small text-muted mb-2"><i class="fas fa-map-pin me-1" style="color:var(--qc-primary-600)"></i> Kalayaan Ave. corridor via Kamias & 15th Ave → Aurora Blvd</div>
                                    <ul class="small mb-2 ps-3" style="line-height:1.7; color:#3e454c;">
                                         <li>Quezon City Hall Materials Recovery Facility (Gate 3 - Simula)</li>
                                         <li>Masigla &amp; Kalayaan Avenue</li>
                                         <li>Bus Stop - Route 3 - Kalayaan Ave cor. Kamias</li>
                                         <li>Barangay Silangan Hall</li>
                                         <li>Aurora Boulevard &amp; 15th Avenue</li>
                                         <li><strong>Ali Mall (Araneta City - Dulo) — Terminal</strong></li>
                                    </ul>
                                    <div class="d-flex flex-wrap gap-2 small"><span class="badge bg-light text-dark border"><i class="far fa-clock me-1"></i>5AM–9PM</span><span class="badge bg-light text-dark border"><i class="fas fa-sync-alt me-1"></i>10–15 min</span></div>
                                    <button type="button" id="viewRoute1MapBtn" class="btn btn-sm w-100 mt-3" style="border:1px solid var(--qc-primary-800); color:var(--qc-primary-800); font-weight:700; border-radius:8px; padding:8px 12px;" data-bs-toggle="modal" data-bs-target="#viewRouteMapModal" data-route-id="1" data-route-name="QC Hall to Cubao" aria-label="View Route 1 QC Hall to Cubao on Map" title="Route 1: QC Hall → Cubao"><i class="fas fa-map-marked-alt me-1"></i> View Route on Map <span style="background:var(--qc-primary-800); color:#fff; font-size:0.65rem; padding:2px 7px; border-radius:20px; margin-left:6px; font-weight:800; letter-spacing:0.3px;"><i class="fas fa-route me-1"></i>Route 1</span></button>
                                </div>
                            </div>
                            <!-- Route 2 -->
                            <div class="col-md-6">
                                <div class="h-100" style="border:1px solid var(--qc-card-border); border-radius:12px; padding:16px; background:#fff;">
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <span style="background:var(--qc-primary-800);color:#fff;font-weight:800;font-size:0.7rem;padding:4px 8px;border-radius:20px;">ROUTE 2</span>
                                        <h6 class="mb-0" style="font-weight:800; color:var(--qc-primary-900); font-size:0.95rem;">QC Hall to Litex / IBP Road</h6>
                                    </div>
                                    <div class="small text-muted mb-2"><i class="fas fa-map-pin me-1" style="color:var(--qc-primary-600)"></i> Commonwealth Ave corridor (heaviest demand)</div>
                                    <ul class="small mb-2 ps-3" style="line-height:1.7; color:#3e454c;">
                                        <li>QC Hall Gate 3 — Kalayaan Ave.</li>
                                        <li>St. Peter Parish: Shrine of Leaders (Commonwealth Avenue)</li>
                                        <li>Rosario Maclang Bautista General Hospital (IBP Road)</li>
                                        <li>QCU Guidance (Batasan Hills Campus, IBP Road)</li>
                                        <li><strong>Litex Market (Dulo / Terminal)</strong></li>
                                    </ul>
                                    <div class="d-flex flex-wrap gap-2 small"><span class="badge bg-light text-dark border"><i class="far fa-clock me-1"></i>5AM–9PM</span><span class="badge bg-light text-dark border"><i class="fas fa-sync-alt me-1"></i>10–15 min</span></div>
                                    <button type="button" class="btn btn-sm w-100 mt-3" style="border:1px solid var(--qc-primary-800); color:var(--qc-primary-800); font-weight:700; border-radius:8px; padding:8px 12px;" data-bs-toggle="modal" data-bs-target="#viewRouteMapModal" data-route-id="2" data-route-name="QC Hall to Litex / IBP Road" aria-label="View Route 2 on Map"><i class="fas fa-map-marked-alt me-1"></i> View Route on Map</button>
                                </div>
                            </div>
                            <!-- Route 3 -->
                            <div class="col-md-6">
                                <div class="h-100" style="border:1px solid var(--qc-card-border); border-radius:12px; padding:16px; background:#fff;">
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <span style="background:var(--qc-primary-800);color:#fff;font-weight:800;font-size:0.7rem;padding:4px 8px;border-radius:20px;">ROUTE 3</span>
                                        <h6 class="mb-0" style="font-weight:800; color:var(--qc-primary-900); font-size:0.95rem;">Welcome Rotonda to Aurora-Katipunan</h6>
                                    </div>
                                    <div class="small text-muted mb-2"><i class="fas fa-map-pin me-1" style="color:var(--qc-primary-600)"></i> Eastern QC via E. Rodriguez / Aurora Blvd</div>
                                    <ul class="small mb-2 ps-3" style="line-height:1.7; color:#3e454c;">
                                         <li>Welcome Rotonda / E. Rodriguez Sr. Avenue</li>
                                         <li>E. Rodriguez Sr. Ave. (St. Luke's / NCH)</li>
                                         <li>Gilmore Interchange</li>
                                         <li>Kamuning Road</li>
                                         <li>Kamias Road / EDSA Interchange</li>
                                         <li>Kalayaan cor. Kamias</li>
                                         <li>Anonas Road</li>
                                         <li>LRT-2 Anonas Station</li>
                                         <li><strong>Katipunan Interchange (Terminal)</strong></li>
                                    </ul>
                                    <div class="d-flex flex-wrap gap-2 small"><span class="badge bg-light text-dark border"><i class="far fa-clock me-1"></i>5AM–9PM</span><span class="badge bg-light text-dark border"><i class="fas fa-sync-alt me-1"></i>15–20 min</span></div>
                                    <button type="button" class="btn btn-sm w-100 mt-3" style="border:1px solid var(--qc-primary-800); color:var(--qc-primary-800); font-weight:700; border-radius:8px; padding:8px 12px;" data-bs-toggle="modal" data-bs-target="#viewRouteMapModal" data-route-id="3" data-route-name="Welcome Rotonda to Aurora-Katipunan" aria-label="View Route 3 on Map"><i class="fas fa-map-marked-alt me-1"></i> View Route on Map</button>
                                </div>
                            </div>
                            <!-- Route 4 -->
                            <div class="col-md-6">
                                <div class="h-100" style="border:1px solid var(--qc-card-border); border-radius:12px; padding:16px; background:#fff;">
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <span style="background:var(--qc-primary-800);color:#fff;font-weight:800;font-size:0.7rem;padding:4px 8px;border-radius:20px;">ROUTE 4</span>
                                        <h6 class="mb-0" style="font-weight:800; color:var(--qc-primary-900); font-size:0.95rem;">QC Hall to General Luis</h6>
                                    </div>
                                    <div class="small text-muted mb-2"><i class="fas fa-map-pin me-1" style="color:var(--qc-primary-600)"></i> Northern QC via Mindanao / Quirino Hwy</div>
                                    <ul class="small mb-2 ps-3" style="line-height:1.7; color:#3e454c;">
                                        <li>QC Hall Gate 3 — Kalayaan Ave.</li>
                                         <li>North Avenue (Veterans / Vertis North)</li>
                                         <li>Mindanao Ave. cor. Road 1</li>
                                         <li>Mindanao Ave. (Tullahan Bridge)</li>
                                         <li>Mindanao Ave. cor. Congressional Ave.</li>
                                         <li>Mindanao Ave. cor. Tandang Sora</li>
                                         <li>Mindanao Ave. cor. D. Muñoz</li>
                                         <li>Mindanao Ave. cor. Old Sauyo Road</li>
                                         <li>Mindanao Ave. cor. Quirino Highway</li>
                                         <li>QCU Main / Novaliches District Hospital</li>
                                         <li>Quirino Highway (SM City Novaliches)</li>
                                         <li>General Luis (Nova Bayan)</li>
                                         <li>General Luis cor. Banahaw St.</li>
                                         <li><strong>General Luis cor. SB Road (Terminal)</strong></li>
                                    </ul>
                                    <div class="d-flex flex-wrap gap-2 small"><span class="badge bg-light text-dark border"><i class="far fa-clock me-1"></i>5AM–9PM</span><span class="badge bg-light text-dark border"><i class="fas fa-sync-alt me-1"></i>15–20 min</span></div>
                                    <button type="button" class="btn btn-sm w-100 mt-3" style="border:1px solid var(--qc-primary-800); color:var(--qc-primary-800); font-weight:700; border-radius:8px; padding:8px 12px;" data-bs-toggle="modal" data-bs-target="#viewRouteMapModal" data-route-id="4" data-route-name="QC Hall to General Luis" aria-label="View Route 4 on Map"><i class="fas fa-map-marked-alt me-1"></i> View Route on Map</button>
                                </div>
                            </div>
                            <!-- Route 5 -->
                            <div class="col-md-6">
                                <div class="h-100" style="border:1px solid var(--qc-card-border); border-radius:12px; padding:16px; background:#fff;">
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <span style="background:var(--qc-primary-800);color:#fff;font-weight:800;font-size:0.7rem;padding:4px 8px;border-radius:20px;">ROUTE 5</span>
                                        <h6 class="mb-0" style="font-weight:800; color:var(--qc-primary-900); font-size:0.95rem;">QC Hall to Mindanao Ave. via Visayas Ave.</h6>
                                    </div>
                                    <div class="small text-muted mb-2"><i class="fas fa-map-pin me-1" style="color:var(--qc-primary-600)"></i> Visayas/Congressional connector</div>
                                    <ul class="small mb-2 ps-3" style="line-height:1.7; color:#3e454c;">
                                        <li>QC Hall</li>
                                        <li>North Avenue</li>
                                        <li>Visayas Avenue</li>
                                        <li>Congressional Avenue</li>
                                        <li><strong>Mindanao Avenue (Terminal)</strong></li>
                                    </ul>
                                    <div class="d-flex flex-wrap gap-2 small"><span class="badge bg-light text-dark border"><i class="far fa-clock me-1"></i>5AM–9PM</span><span class="badge bg-light text-dark border"><i class="fas fa-sync-alt me-1"></i>15–20 min</span></div>
                                    <button type="button" class="btn btn-sm w-100 mt-3" style="border:1px solid var(--qc-primary-800); color:var(--qc-primary-800); font-weight:700; border-radius:8px; padding:8px 12px;" data-bs-toggle="modal" data-bs-target="#viewRouteMapModal" data-route-id="5" data-route-name="QC Hall to Mindanao Ave. via Visayas Ave." aria-label="View Route 5 on Map"><i class="fas fa-map-marked-alt me-1"></i> View Route on Map</button>
                                </div>
                            </div>
                            <!-- Route 6 -->
                            <div class="col-md-6">
                                <div class="h-100" style="border:1px solid var(--qc-card-border); border-radius:12px; padding:16px; background:#fff;">
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <span style="background:var(--qc-primary-800);color:#fff;font-weight:800;font-size:0.7rem;padding:4px 8px;border-radius:20px;">ROUTE 6</span>
                                        <h6 class="mb-0" style="font-weight:800; color:var(--qc-primary-900); font-size:0.95rem;">QC Hall to Gilmore</h6>
                                    </div>
                                    <div class="small text-muted mb-2"><i class="fas fa-map-pin me-1" style="color:var(--qc-primary-600)"></i> Short connector via East Ave / E. Rodriguez</div>
                                    <ul class="small mb-2 ps-3" style="line-height:1.7; color:#3e454c;">
                                        <li>QC Hall</li>
                                        <li>East Avenue</li>
                                        <li>E. Rodriguez Sr. Avenue</li>
                                        <li><strong>Gilmore (Terminal)</strong></li>
                                    </ul>
                                    <div class="d-flex flex-wrap gap-2 small"><span class="badge bg-light text-dark border"><i class="far fa-clock me-1"></i>5AM–9PM</span><span class="badge bg-light text-dark border"><i class="fas fa-sync-alt me-1"></i>15 min</span></div>
                                    <button type="button" class="btn btn-sm w-100 mt-3" style="border:1px solid var(--qc-primary-800); color:var(--qc-primary-800); font-weight:700; border-radius:8px; padding:8px 12px;" data-bs-toggle="modal" data-bs-target="#viewRouteMapModal" data-route-id="6" data-route-name="QC Hall to Gilmore" aria-label="View Route 6 on Map"><i class="fas fa-map-marked-alt me-1"></i> View Route on Map</button>
                                </div>
                            </div>
                            <!-- Route 7 -->
                            <div class="col-md-6">
                                <div class="h-100" style="border:1px solid var(--qc-card-border); border-radius:12px; padding:16px; background:#fff;">
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <span style="background:var(--qc-primary-800);color:#fff;font-weight:800;font-size:0.7rem;padding:4px 8px;border-radius:20px;">ROUTE 7</span>
                                        <h6 class="mb-0" style="font-weight:800; color:var(--qc-primary-900); font-size:0.95rem;">QC Hall to C5 / Ortigas Ave. Ext.</h6>
                                    </div>
                                    <div class="small text-muted mb-2"><i class="fas fa-map-pin me-1" style="color:var(--qc-primary-600)"></i> Eastern link via C-5 Road</div>
                                    <ul class="small mb-2 ps-3" style="line-height:1.7; color:#3e454c;">
                                        <li>QC Hall</li>
                                        <li>C-5 Road</li>
                                        <li><strong>Ortigas Avenue (Terminal)</strong></li>
                                    </ul>
                                    <div class="d-flex flex-wrap gap-2 small"><span class="badge bg-light text-dark border"><i class="far fa-clock me-1"></i>5AM–9PM</span><span class="badge bg-light text-dark border"><i class="fas fa-sync-alt me-1"></i>20 min</span></div>
                                    <button type="button" class="btn btn-sm w-100 mt-3" style="border:1px solid var(--qc-primary-800); color:var(--qc-primary-800); font-weight:700; border-radius:8px; padding:8px 12px;" data-bs-toggle="modal" data-bs-target="#viewRouteMapModal" data-route-id="7" data-route-name="QC Hall to C5 / Ortigas Ave. Ext." aria-label="View Route 7 on Map"><i class="fas fa-map-marked-alt me-1"></i> View Route on Map</button>
                                </div>
                            </div>
                            <!-- Route 8 -->
                            <div class="col-md-6">
                                <div class="h-100" style="border:1px solid var(--qc-card-border); border-radius:12px; padding:16px; background:#fff;">
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <span style="background:var(--qc-primary-800);color:#fff;font-weight:800;font-size:0.7rem;padding:4px 8px;border-radius:20px;">ROUTE 8</span>
                                        <h6 class="mb-0" style="font-weight:800; color:var(--qc-primary-900); font-size:0.95rem;">QC Hall to Muñoz</h6>
                                    </div>
                                    <div class="small text-muted mb-2"><i class="fas fa-map-pin me-1" style="color:var(--qc-primary-600)"></i> North Ave corridor via Roosevelt</div>
                                    <ul class="small mb-2 ps-3" style="line-height:1.7; color:#3e454c;">
                                        <li>QC Hall</li>
                                        <li>North Avenue</li>
                                        <li>Roosevelt Avenue</li>
                                        <li><strong>Muñoz (Terminal)</strong></li>
                                    </ul>
                                    <div class="d-flex flex-wrap gap-2 small"><span class="badge bg-light text-dark border"><i class="far fa-clock me-1"></i>5AM–9PM</span><span class="badge bg-light text-dark border"><i class="fas fa-sync-alt me-1"></i>15 min</span></div>
                                    <button type="button" class="btn btn-sm w-100 mt-3" style="border:1px solid var(--qc-primary-800); color:var(--qc-primary-800); font-weight:700; border-radius:8px; padding:8px 12px;" data-bs-toggle="modal" data-bs-target="#viewRouteMapModal" data-route-id="8" data-route-name="QC Hall to Muñoz" aria-label="View Route 8 on Map"><i class="fas fa-map-marked-alt me-1"></i> View Route on Map</button>
                                </div>
                            </div>
                        </div>

                        <!-- QCitizen note -->
                        <div class="mt-4 p-3" style="background:#fff7ed; border:1px solid #fed7aa; border-radius:10px;">
                            <h6 class="mb-2" style="font-weight:800; color:#9a3412; font-size:0.9rem;"><i class="fas fa-id-badge me-2"></i>QCitizen ID Requirement</h6>
                            <p class="small mb-2" style="color:#7c2d12; line-height:1.6;">
                                <strong>Libreng Sakay is FREE for all passengers</strong> with priority boarding for Quezon City residents. Present your <strong>QCitizen ID</strong> upon boarding to avail of the free ride and help the city track ridership.
                                Non-QC residents may still ride for free by presenting any valid government ID and registering on-site at the terminal. PWD, senior citizen, and pregnant-passenger priority seats are available in all low-floor units.
                            </p>
                            <p class="small mb-0" style="color:#9a3412;">
                                <i class="fas fa-info-circle me-1"></i> Tip: Download the <strong>QCitizen App</strong> or visit <a href="https://qcitizen.qc.gov.ph" target="_blank" rel="noopener" style="color:#9a3412; text-decoration:underline; font-weight:700;">qcitizen.qc.gov.ph</a> to apply. Bring your ID — first-come, first-served.
                            </p>
                        </div>

                        <p class="small text-muted text-center mt-3 mb-0"><i class="fas fa-exclamation-circle me-1"></i> Routes, stops, and intervals may change during holidays, class suspensions, or emergency rerouting — check the <a href="#" onclick="(bootstrap.Modal.getInstance(document.getElementById('qcBusRoutesModal'))||bootstrap.Modal.getOrCreateInstance(document.getElementById('qcBusRoutesModal'))).hide(); setTimeout(()=>document.getElementById('publicGisFab')?.click(), 300); return false;">Live Road Map FAB</a> or official QC Government page for real-time updates.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- View Route on Map Modal — GIS sized for modal only -->
    <div class="modal fade" id="viewRouteMapModal" tabindex="-1" aria-labelledby="viewRouteMapModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content" style="border-radius:14px; overflow:hidden;">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--qc-primary-800) 0%, #1d698b 100%); color:#fff; border-bottom:none; padding:16px 20px;">
                    <h5 class="modal-title d-flex align-items-center gap-2" id="viewRouteMapModalLabel" style="font-weight:800; font-size:1.05rem; line-height:1.3;">
                        <span style="width:32px;height:32px;background:rgba(255,255,255,0.18);border-radius:8px;display:inline-flex;align-items:center;justify-content:center;"><i class="fas fa-map-marked-alt"></i></span>
                        QC Bus Route — Map View
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="view-route-gis-wrap">
                        <div class="view-route-dropdown-bar">
                            <label for="viewRouteDropdown"><i class="fas fa-bus me-1" style="color:var(--qc-primary-700)"></i> Select Bus Route:</label>
                            <select id="viewRouteDropdown" aria-label="Select bus route">
                                <option value="1" selected>Route 1: QC Hall to Cubao — Kalayaan Ave.</option>
                                <option value="2">Route 2: QC Hall to Litex / IBP Road — Commonwealth Ave</option>
                                <option value="3">Route 3: Welcome Rotonda to Aurora-Katipunan — E. Rodriguez / Aurora</option>
                                <option value="4">Route 4: QC Hall to General Luis — Mindanao / Quirino Hwy</option>
                                <option value="5">Route 5: QC Hall to Mindanao Ave. via Visayas Ave.</option>
                                <option value="6">Route 6: QC Hall to Gilmore — East Ave / E. Rodriguez</option>
                                <option value="7">Route 7: QC Hall to C5 / Ortigas Ave. Ext. — C-5</option>
                                <option value="8">Route 8: QC Hall to Muñoz — North Ave / Roosevelt</option>
                            </select>
                        </div>
                        <div id="viewRouteGisMap" role="region" aria-label="QC Bus Route Map"></div>
                        <div id="viewRoute1MapOverlay" class="view-route1-map-overlay" aria-live="polite">
                            <div style="font-weight:800; color:var(--qc-primary-900); font-size:11px; display:flex; align-items:center; gap:6px; flex-wrap:wrap;"><span style="background:var(--qc-primary-800);color:#fff;font-weight:800;font-size:0.65rem;padding:2px 6px;border-radius:20px;">ROUTE <span id="viewRouteOverlayNum">1</span></span> <span id="viewRouteOverlayTitle">Route 1: QC Hall to Cubao — Kalayaan Ave.</span></div>
                            <div class="view-route1-stops" id="viewRouteOverlayStops">
                                <span class="view-route1-stop"><span class="dot start"></span> QC Hall MRF Gate 3</span><span class="view-route1-arrow">→</span>
                                <span class="view-route1-stop"><span class="dot"></span> Masigla &amp; Kalayaan</span><span class="view-route1-arrow">→</span>
                                <span class="view-route1-stop"><span class="dot"></span> Kamias</span><span class="view-route1-arrow">→</span>
                                <span class="view-route1-stop"><span class="dot"></span> Silangan Hall</span><span class="view-route1-arrow">→</span>
                                <span class="view-route1-stop"><span class="dot"></span> Aurora &amp; 15th</span><span class="view-route1-arrow">→</span>
                                <span class="view-route1-stop"><span class="dot end"></span> Ali Mall</span>
                            </div>
                            <div id="viewRouteOverlayCorridor" style="color:#64748b; font-size:10px;"><i class="far fa-clock me-1"></i>Kalayaan Ave. via 15th Ave → Aurora Blvd • Based on official QC Gov Route 1 map</div>
                            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:4px; font-size:9px; font-weight:600;"><span style="display:inline-flex; align-items:center; gap:4px;"><span style="width:10px;height:10px;border-radius:50%;background:#115272;border:1px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,0.2);display:inline-block;"></span> Stop</span><span style="display:inline-flex; align-items:center; gap:4px;"><span style="width:10px;height:10px;border-radius:50%;background:#f59e0b;border:1px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,0.2);display:inline-block;"></span> Turn (point-to-point)</span><span style="display:inline-flex; align-items:center; gap:4px;"><span style="width:10px;height:10px;border-radius:50%;background:#10b981;border:1px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,0.2);display:inline-block;"></span> Start</span><span style="display:inline-flex; align-items:center; gap:4px;"><span style="width:10px;height:10px;border-radius:50%;background:#dc2626;border:1px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,0.2);display:inline-block;"></span> End</span></div>
                            <div style="color:#64748b; font-size:10px; margin-top:4px;"><i class="fas fa-route me-1" style="color:#115272;"></i>Point-to-point at every turn • Follows lanes (no bypass) • Official QC Gov</div>
                        </div>
                        <small class="text-muted text-center d-block"><i class="fas fa-info-circle me-1"></i> Modal-sized GIS — same layers as Live Road Map, constrained to modal.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script type="application/json" id="qcBusRoutesData">[
  { "routeNumber": 1, "name": "QC Hall to Cubao", "keyStops": ["Quezon City Hall Materials Recovery Facility (Gate 3 - Simula)", "Masigla & Kalayaan Avenue", "Bus Stop - Route 3 - Kalayaan Ave cor. Kamias", "Barangay Silangan Hall", "Aurora Boulevard & 15th Avenue", "Ali Mall (Araneta City - Dulo)"] },
  { "routeNumber": 2, "name": "QC Hall to Litex / IBP Road", "keyStops": ["QC Hall Gate 3 Kalayaan Ave.", "St. Peter Parish: Shrine of Leaders (Commonwealth Avenue)", "Rosario Maclang Bautista General Hospital (IBP Road)", "QCU Guidance (Batasan Hills Campus, IBP Road)", "Litex Market (Dulo / Terminal)"] },
  { "routeNumber": 3, "name": "Welcome Rotonda to Aurora-Katipunan", "keyStops": ["Welcome Rotonda / E. Rodriguez Sr. Avenue", "E. Rodriguez Sr. Avenue (Quezon Institute)", "E. Rodriguez Sr. Avenue (St. Luke's / NCH)", "Gilmore Interchange", "Kamuning Road", "Kamias Road / EDSA Interchange", "Kalayaan cor. Kamias", "Anonas Road", "LRT-2 Anonas Station", "Katipunan Interchange"] },
  { "routeNumber": 4, "name": "QC Hall to General Luis", "keyStops": ["QC Hall Gate 3 Kalayaan Ave.", "North Avenue (Veterans / Vertis North)", "Mindanao Ave. cor. Road 1", "Mindanao Ave. (Tullahan Bridge)", "Mindanao Ave. cor. Congressional Ave.", "Mindanao Ave. cor. Tandang Sora", "Mindanao Ave. cor. D. Muñoz", "Mindanao Ave. cor. Old Sauyo Road", "Mindanao Ave. cor. Quirino Highway", "QCU Main / Novaliches District Hospital", "Quirino Highway (SM City Novaliches)", "General Luis (Nova Bayan)", "General Luis cor. Banahaw St.", "General Luis cor. SB Road"] },
  { "routeNumber": 5, "name": "QC Hall to Mindanao Ave. via Visayas Ave.", "keyStops": ["QC Hall", "North Avenue", "Visayas Avenue", "Congressional Avenue", "Mindanao Avenue"] },
  { "routeNumber": 6, "name": "QC Hall to Gilmore", "keyStops": ["QC Hall", "East Avenue", "E. Rodriguez Sr. Avenue", "Gilmore"] },
  { "routeNumber": 7, "name": "QC Hall to C5 / Ortigas Ave. Ext.", "keyStops": ["QC Hall", "C-5 Road", "Ortigas Avenue"] },
  { "routeNumber": 8, "name": "QC Hall to Muñoz", "keyStops": ["QC Hall", "North Avenue", "Roosevelt Avenue", "Muñoz"] }
]</script>

    <!-- Jeepney Rationalization Modal — consolidated lines from QC DOTr rationalization program -->
    <div class="modal fade" id="jeepneyRoutesModal" tabindex="-1" aria-labelledby="jeepneyRoutesModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content" style="border-radius:14px; overflow:hidden;">
                <div class="modal-header" style="background: linear-gradient(135deg, #92400e 0%, #d97706 100%); color:#fff; border-bottom:none; padding:16px 20px;">
                    <h5 class="modal-title d-flex align-items-center gap-2" id="jeepneyRoutesModalLabel" style="font-weight:800; font-size:1.05rem; line-height:1.3;">
                        <span style="width:36px;height:36px;background:rgba(255,255,255,0.22);border-radius:8px;display:inline-flex;align-items:center;justify-content:center;"><i class="fas fa-shuttle-van"></i></span>
                        Jeepney Rationalization — Consolidated Routes (DOTr)
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <!-- Summary header -->
                    <div class="p-3 p-md-4" style="background:#fffbeb; border-bottom:1px solid #fde68a;">
                        <div class="row g-3 small">
                            <div class="col-md-4 d-flex gap-2"><i class="fas fa-route mt-1" style="color:#92400e"></i><div><strong>Program</strong><br><span class="text-muted">PUV Modernization — Rationalized &amp; Consolidated<br>Designated stops only • No “baba lang”</span></div></div>
                            <div class="col-md-4 d-flex gap-2"><i class="fas fa-warehouse mt-1" style="color:#92400e"></i><div><strong>Dispatch Terminals</strong><br><span class="text-muted">QC EDSA Carousel • Litex • Anonas<br>Welcome Rotonda Terminal</span></div></div>
                            <div class="col-md-4 d-flex gap-2"><i class="fas fa-clock mt-1" style="color:#92400e"></i><div><strong>Operations</strong><br><span class="text-muted">5:00 AM – 10:00 PM Daily<br><span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:20px;font-weight:700;font-size:0.72rem;">CONSOLIDATED</span></span></div></div>
                        </div>
                    </div>

                    <div class="p-3 p-md-4">
                        <div class="row g-3 g-md-4">
                            <!-- JR-01 -->
                            <div class="col-md-6">
                                <div class="h-100" style="border:1px solid #fde68a; border-left:4px solid #d97706; border-radius:12px; padding:16px; background:#fff;">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span style="background:#92400e;color:#fff;font-weight:800;font-size:0.7rem;padding:4px 8px;border-radius:20px;">JR-01</span>
                                        <span style="background:#dcfce7;color:#166534;font-weight:700;font-size:0.62rem;padding:3px 7px;border-radius:20px;letter-spacing:0.3px;"><i class="fas fa-check-circle me-1"></i>Rationalized &amp; Consolidated</span>
                                    </div>
                                    <h6 class="mb-1" style="font-weight:800; color:#78350f; font-size:0.95rem;">QC Hall to Philcoa / SM North</h6>
                                    <div class="small mb-2" style="color:#92400e;"><i class="fas fa-map-pin me-1"></i> QC EDSA Terminal • Elliptical Rd. corridor</div>
                                    <ul class="small mb-0 ps-3" style="line-height:1.7; color:#3e454c;">
                                        <li>QC Hall</li>
                                        <li>Elliptical Road</li>
                                        <li>Philcoa</li>
                                        <li><strong>SM North EDSA (Terminal)</strong></li>
                                    </ul>
                                </div>
                            </div>
                            <!-- JR-02 -->
                            <div class="col-md-6">
                                <div class="h-100" style="border:1px solid #fde68a; border-left:4px solid #d97706; border-radius:12px; padding:16px; background:#fff;">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span style="background:#92400e;color:#fff;font-weight:800;font-size:0.7rem;padding:4px 8px;border-radius:20px;">JR-02</span>
                                        <span style="background:#dcfce7;color:#166534;font-weight:700;font-size:0.62rem;padding:3px 7px;border-radius:20px;letter-spacing:0.3px;"><i class="fas fa-check-circle me-1"></i>Rationalized &amp; Consolidated</span>
                                    </div>
                                    <h6 class="mb-1" style="font-weight:800; color:#78350f; font-size:0.95rem;">Litex to Fairview Center Mall</h6>
                                    <div class="small mb-2" style="color:#92400e;"><i class="fas fa-map-pin me-1"></i> Litex Terminal • Commonwealth Ave corridor</div>
                                    <ul class="small mb-0 ps-3" style="line-height:1.7; color:#3e454c;">
                                        <li>Litex</li>
                                        <li>Commonwealth Avenue</li>
                                        <li><strong>Fairview Center Mall (Terminal)</strong></li>
                                    </ul>
                                </div>
                            </div>
                            <!-- JR-03 -->
                            <div class="col-md-6">
                                <div class="h-100" style="border:1px solid #fde68a; border-left:4px solid #d97706; border-radius:12px; padding:16px; background:#fff;">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span style="background:#92400e;color:#fff;font-weight:800;font-size:0.7rem;padding:4px 8px;border-radius:20px;">JR-03</span>
                                        <span style="background:#dcfce7;color:#166534;font-weight:700;font-size:0.62rem;padding:3px 7px;border-radius:20px;letter-spacing:0.3px;"><i class="fas fa-check-circle me-1"></i>Rationalized &amp; Consolidated</span>
                                    </div>
                                    <h6 class="mb-1" style="font-weight:800; color:#78350f; font-size:0.95rem;">Project 2 &amp; 3 to Cubao / Anonas</h6>
                                    <div class="small mb-2" style="color:#92400e;"><i class="fas fa-map-pin me-1"></i> Anonas Terminal • Aurora Blvd corridor</div>
                                    <ul class="small mb-2 ps-3" style="line-height:1.7; color:#3e454c;">
                                        <li>Project 2 &amp; 3</li>
                                        <li>Aurora Boulevard</li>
                                        <li>Anonas</li>
                                        <li><strong>Cubao (Terminal)</strong></li>
                                    </ul>
                                    <div class="small text-muted"><i class="fas fa-arrows-alt-h me-1"></i>Connects to LRT-2 Anonas &amp; MRT-3 Cubao</div>
                                </div>
                            </div>
                            <!-- JR-04 -->
                            <div class="col-md-6">
                                <div class="h-100" style="border:1px solid #fde68a; border-left:4px solid #d97706; border-radius:12px; padding:16px; background:#fff;">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span style="background:#92400e;color:#fff;font-weight:800;font-size:0.7rem;padding:4px 8px;border-radius:20px;">JR-04</span>
                                        <span style="background:#dcfce7;color:#166534;font-weight:700;font-size:0.62rem;padding:3px 7px;border-radius:20px;letter-spacing:0.3px;"><i class="fas fa-check-circle me-1"></i>Rationalized &amp; Consolidated</span>
                                    </div>
                                    <h6 class="mb-1" style="font-weight:800; color:#78350f; font-size:0.95rem;">Welcome Rotonda to E. Rodriguez / España</h6>
                                    <div class="small mb-2" style="color:#92400e;"><i class="fas fa-map-pin me-1"></i> Welcome Rotonda Terminal • España / E. Rodriguez</div>
                                    <ul class="small mb-0 ps-3" style="line-height:1.7; color:#3e454c;">
                                        <li>Welcome Rotonda</li>
                                        <li>E. Rodriguez Sr. Avenue</li>
                                        <li><strong>España Boulevard (Terminal)</strong></li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- Operational note -->
                        <div class="mt-4 p-3" style="background:#f8fafc; border:1px solid var(--qc-card-border); border-radius:10px;">
                            <h6 class="mb-2" style="font-weight:800; color:var(--qc-primary-900); font-size:0.88rem;"><i class="fas fa-info-circle me-2" style="color:var(--qc-primary-700)"></i>How to ride the rationalized lines</h6>
                            <ul class="small mb-2 ps-3" style="color:#3e454c; line-height:1.7;">
                                <li><strong>Board only at designated stops &amp; terminals</strong> — QC EDSA Carousel, Litex, Anonas, Welcome Rotonda. No flag-down outside stops.</li>
                                <li>Units are <strong>consolidated cooperatives</strong> with dispatch intervals <strong>every 5–10 minutes</strong> peak. GPS-tracked, PWD-friendly for newer modern units.</li>
                                <li>Fare matrix per LTFRB • Pay via cash or Beep in modern units. Keep queueig at terminals during rush hour.</li>
                            </ul>
                            <p class="small mb-0" style="color:var(--qc-shades-500);">
                                <i class="fas fa-map-marked-alt me-1"></i> Need exact stop location? Tap the <a href="#" onclick="(bootstrap.Modal.getInstance(document.getElementById('jeepneyRoutesModal'))||bootstrap.Modal.getOrCreateInstance(document.getElementById('jeepneyRoutesModal'))).hide(); setTimeout(()=>document.getElementById('publicGisFab')?.click(), 300); return false;" style="color:var(--qc-primary-700); font-weight:700; text-decoration:underline;">Live Road Map FAB → Search</a> and type the terminal name.
                            </p>
                        </div>

                        <p class="small text-muted text-center mt-3 mb-0"><i class="fas fa-exclamation-circle me-1"></i> Routes and dispatch times may change during coding, class suspensions, or emergencies — check terminals or the Live Road Map for real-time updates. Official list via QC DPOS / LTFRB.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script type="application/json" id="jeepneyRoutesData">[
  { "routeCode": "JR-01", "lineName": "QC Hall to Philcoa / SM North", "terminal": "QC EDSA Terminal", "status": "Rationalized & Consolidated", "keyStops": ["QC Hall", "Elliptical Road", "Philcoa", "SM North EDSA"] },
  { "routeCode": "JR-02", "lineName": "Litex to Fairview Center Mall", "terminal": "Litex Terminal", "status": "Rationalized & Consolidated", "keyStops": ["Litex", "Commonwealth Avenue", "Fairview Center Mall"] },
  { "routeCode": "JR-03", "lineName": "Project 2 & 3 to Cubao / Anonas", "terminal": "Anonas Terminal", "status": "Rationalized & Consolidated", "keyStops": ["Project 2 & 3", "Aurora Boulevard", "Anonas", "Cubao"] },
  { "routeCode": "JR-04", "lineName": "Welcome Rotonda to E. Rodriguez / España", "terminal": "Welcome Rotonda Terminal", "status": "Rationalized & Consolidated", "keyStops": ["Welcome Rotonda", "E. Rodriguez Sr. Avenue", "España Boulevard"] }
]</script>

    <!-- Bike Lane Network Modal — 90+ km QC protected & shared network -->
    <div class="modal fade" id="bikeLaneModal" tabindex="-1" aria-labelledby="bikeLaneModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content" style="border-radius:14px; overflow:hidden;">
                <div class="modal-header" style="background: linear-gradient(135deg, #065f46 0%, #16a34a 100%); color:#fff; border-bottom:none; padding:16px 20px;">
                    <h5 class="modal-title d-flex align-items-center gap-2" id="bikeLaneModalLabel" style="font-weight:800; font-size:1.05rem; line-height:1.3;">
                        <span style="width:36px;height:36px;background:rgba(255,255,255,0.22);border-radius:8px;display:inline-flex;align-items:center;justify-content:center;"><i class="fas fa-bicycle"></i></span>
                        Bike Lane Network — 90+ km Protected &amp; Shared Corridors
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <!-- Summary header -->
                    <div class="p-3 p-md-4" style="background:#f0fdf4; border-bottom:1px solid #bbf7d0;">
                        <div class="row g-3 small">
                            <div class="col-md-4 d-flex gap-2"><i class="fas fa-road mt-1" style="color:#065f46"></i><div><strong>Network</strong><br><span class="text-muted">90+ km citywide<br>Protected + shared + buffered lanes</span></div></div>
                            <div class="col-md-4 d-flex gap-2"><i class="fas fa-tools mt-1" style="color:#065f46"></i><div><strong>Amenities</strong><br><span class="text-muted">Secure racks • Repair stations<br>Park-and-ride links</span></div></div>
                            <div class="col-md-4 d-flex gap-2"><i class="fas fa-shield-alt mt-1" style="color:#065f46"></i><div><strong>Safety</strong><br><span class="text-muted"><span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:20px;font-weight:700;font-size:0.72rem;">PROTECTED</span> Barriers &amp; green sight-lines</span></div></div>
                        </div>
                    </div>

                    <div class="p-3 p-md-4">
                        <div class="row g-3 g-md-4">
                            <!-- BK-01 -->
                            <div class="col-md-6">
                                <div class="h-100" style="border:1px solid #bbf7d0; border-left:4px solid #16a34a; border-radius:12px; padding:16px; background:#fff;">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span style="background:#065f46;color:#fff;font-weight:800;font-size:0.68rem;padding:4px 8px;border-radius:20px;">BK-01</span>
                                        <span style="background:#dcfce7;color:#166534;font-weight:700;font-size:0.62rem;padding:3px 7px;border-radius:20px;letter-spacing:0.3px;">Protected Bike Lane</span>
                                    </div>
                                    <h6 class="mb-1" style="font-weight:800; color:#064e3b; font-size:0.95rem;">Elliptical Road &amp; Quezon Memorial Circle</h6>
                                    <div class="small mb-2" style="color:#065f46;"><i class="fas fa-map-pin me-1"></i> QMC core loop — green-paved premier corridor</div>
                                    <ul class="small mb-0 ps-3" style="line-height:1.7; color:#3e454c;">
                                        <li>Green-paved lanes</li>
                                        <li>Concrete plant box barriers</li>
                                        <li>Access to QMC Underpass bike ramp</li>
                                    </ul>
                                </div>
                            </div>
                            <!-- BK-02 -->
                            <div class="col-md-6">
                                <div class="h-100" style="border:1px solid #bbf7d0; border-left:4px solid #16a34a; border-radius:12px; padding:16px; background:#fff;">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span style="background:#065f46;color:#fff;font-weight:800;font-size:0.68rem;padding:4px 8px;border-radius:20px;">BK-02</span>
                                        <span style="background:#fef3c7;color:#92400e;font-weight:700;font-size:0.62rem;padding:3px 7px;border-radius:20px;letter-spacing:0.3px;">Protected &amp; Shared Network</span>
                                    </div>
                                    <h6 class="mb-1" style="font-weight:800; color:#064e3b; font-size:0.95rem;">Commonwealth Avenue</h6>
                                    <div class="small mb-2" style="color:#065f46;"><i class="fas fa-map-pin me-1"></i> Longest QC corridor — Tandang Sora to Fairview</div>
                                    <ul class="small mb-0 ps-3" style="line-height:1.7; color:#3e454c;">
                                        <li>Physical bollards and plant box separators</li>
                                        <li>Footbridge bike ramps (Philcoa &amp; UP AIT)</li>
                                    </ul>
                                </div>
                            </div>
                            <!-- BK-03 -->
                            <div class="col-md-6">
                                <div class="h-100" style="border:1px solid #bbf7d0; border-left:4px solid #16a34a; border-radius:12px; padding:16px; background:#fff;">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span style="background:#065f46;color:#fff;font-weight:800;font-size:0.68rem;padding:4px 8px;border-radius:20px;">BK-03</span>
                                        <span style="background:#dcfce7;color:#166534;font-weight:700;font-size:0.62rem;padding:3px 7px;border-radius:20px;letter-spacing:0.3px;">Protected Lane</span>
                                    </div>
                                    <h6 class="mb-1" style="font-weight:800; color:#064e3b; font-size:0.95rem;">East Avenue</h6>
                                    <div class="small mb-2" style="color:#065f46;"><i class="fas fa-map-pin me-1"></i> Government &amp; medical district spine</div>
                                    <ul class="small mb-2 ps-3" style="line-height:1.7; color:#3e454c;">
                                        <li>Connected to major QC government offices and hospital zones</li>
                                        <li>Clear road markings</li>
                                    </ul>
                                    <div class="small text-muted"><i class="fas fa-hospital me-1"></i> Links QC Hall • East Ave Medical • Heart Center</div>
                                </div>
                            </div>
                            <!-- BK-04 -->
                            <div class="col-md-6">
                                <div class="h-100" style="border:1px solid #bbf7d0; border-left:4px solid #16a34a; border-radius:12px; padding:16px; background:#fff;">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span style="background:#065f46;color:#fff;font-weight:800;font-size:0.68rem;padding:4px 8px;border-radius:20px;">BK-04</span>
                                        <span style="background:#dcfce7;color:#166534;font-weight:700;font-size:0.62rem;padding:3px 7px;border-radius:20px;letter-spacing:0.3px;">Protected Lane</span>
                                    </div>
                                    <h6 class="mb-1" style="font-weight:800; color:#064e3b; font-size:0.95rem;">Quezon Avenue</h6>
                                    <div class="small mb-2" style="color:#065f46;"><i class="fas fa-map-pin me-1"></i> Central QC — Welcome Rotonda to EDSA</div>
                                    <ul class="small mb-2 ps-3" style="line-height:1.7; color:#3e454c;">
                                        <li>NAPWC footbridge bike ramps</li>
                                        <li>Seamless transit convergence points</li>
                                    </ul>
                                    <div class="small text-muted"><i class="fas fa-exchange-alt me-1"></i> Interchanges with MRT-3 &amp; QC Bus</div>
                                </div>
                            </div>
                            <!-- BK-05 -->
                            <div class="col-md-6">
                                <div class="h-100" style="border:1px solid #bbf7d0; border-left:4px solid #16a34a; border-radius:12px; padding:16px; background:#fff;">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span style="background:#065f46;color:#fff;font-weight:800;font-size:0.68rem;padding:4px 8px;border-radius:20px;">BK-05</span>
                                        <span style="background:#a7f3d0;color:#065f46;font-weight:700;font-size:0.62rem;padding:3px 7px;border-radius:20px;letter-spacing:0.3px;">Buffered &amp; Protected Lane</span>
                                    </div>
                                    <h6 class="mb-1" style="font-weight:800; color:#064e3b; font-size:0.95rem;">Katipunan Avenue</h6>
                                    <div class="small mb-2" style="color:#065f46;"><i class="fas fa-map-pin me-1"></i> University corridor — Ateneo to UP</div>
                                    <ul class="small mb-0 ps-3" style="line-height:1.7; color:#3e454c;">
                                        <li>UP Town Center footbridge bike ramp</li>
                                        <li>University corridor connections</li>
                                    </ul>
                                </div>
                            </div>
                            <!-- Amenities card -->
                            <div class="col-md-6">
                                <div class="h-100" style="border:1px dashed #86efac; border-radius:12px; padding:16px; background:#f0fdf4;">
                                    <h6 class="mb-2" style="font-weight:800; color:#065f46; font-size:0.9rem;"><i class="fas fa-parking me-2"></i>Corridor Amenities</h6>
                                    <ul class="small mb-2 ps-3" style="color:#065f46; line-height:1.7;">
                                        <li><strong>Secure racks</strong> — QC Hall, QMC, SM North, Anonas, Philcoa terminals</li>
                                        <li><strong>Repair stations</strong> — Elliptical, Commonwealth, Katipunan (pump &amp; tools)</li>
                                        <li><strong>Park-and-ride links</strong> — Bike → QC Bus /MRT-3 seamless transfer at Philcoa, SM North, Cubao, Anonas</li>
                                        <li><strong>Safety</strong> — Solar studs, green paint at intersections, 30 kph shared-zone markings</li>
                                    </ul>
                                    <p class="small mb-0" style="color:#047857;">
                                        <i class="fas fa-bicycle me-1"></i> Tip: Use the <a href="#" onclick="(bootstrap.Modal.getInstance(document.getElementById('bikeLaneModal'))||bootstrap.Modal.getOrCreateInstance(document.getElementById('bikeLaneModal'))).hide(); setTimeout(()=>document.getElementById('publicGisFab')?.click(), 300); return false;" style="color:#065f46; font-weight:700; text-decoration:underline;">Live Road Map FAB → Search “bike”</a> to locate the nearest ramp or rack.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <p class="small text-muted text-center mt-3 mb-0"><i class="fas fa-exclamation-circle me-1"></i> Network expands quarterly — 90+ km as of 2026. Lane types and footbridge access may shift during road works — check the Live Road Map for closures and safe detours.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script type="application/json" id="bikeLaneData">[
  { "sectionId": "bk-01", "corridorName": "Elliptical Road & Quezon Memorial Circle", "type": "Protected Bike Lane", "features": ["Green-paved lanes", "Concrete plant box barriers", "Access to QMC Underpass bike ramp"] },
  { "sectionId": "bk-02", "corridorName": "Commonwealth Avenue", "type": "Protected & Shared Network", "features": ["Physical bollards and plant box separators", "Footbridge bike ramps (Philcoa & UP AIT)"] },
  { "sectionId": "bk-03", "corridorName": "East Avenue", "type": "Protected Lane", "features": ["Connected to major QC government offices and hospital zones", "Clear road markings"] },
  { "sectionId": "bk-04", "corridorName": "Quezon Avenue", "type": "Protected Lane", "features": ["NAPWC footbridge bike ramps", "Seamless transit convergence points"] },
  { "sectionId": "bk-05", "corridorName": "Katipunan Avenue", "type": "Buffered & Protected Lane", "features": ["UP Town Center footbridge bike ramp", "University corridor connections"] }
]</script>

    <!-- 2. Live Traffic (now via FAB) — inline map removed; use the floating Live Road Map button -->
    <!-- System Announcements (published from Public Transparency) -->
    <section class="section announcements-public-section" id="announcements">
        <div class="container">
            <h2 class="section-title">Announcements</h2>
            <p class="section-subtitle">Official notices and updates from the local government unit</p>

            <?php if (!empty($public_announcements)): ?>
            <div class="row g-4">
                <?php foreach ($public_announcements as $ann):
                    $ann_title = (string)($ann['title'] ?? '');
                    $ann_content = (string)($ann['content'] ?? '');
                    $ann_photo = !empty($ann['photo'])
                        ? road_updates_resolve_image_url($ann['photo'], $basePath)
                        : '';
                ?>
                <div class="col-lg-4 col-md-6">
                    <article class="announcement-public-card">
                        <?php if ($ann_photo): ?>
                        <div class="announcement-public-photo">
                            <img src="<?php echo htmlspecialchars($ann_photo); ?>"
                                 alt="<?php echo htmlspecialchars($ann_title !== '' ? $ann_title : 'Announcement photo'); ?>"
                                 loading="lazy"
                                 onclick="window.open(this.src, '_blank')"
                                 style="cursor:pointer"
                                 title="Click to view full size">
                        </div>
                        <?php endif; ?>
                        <div class="announcement-public-body">
                            <h3><?php echo htmlspecialchars($ann_title !== '' ? $ann_title : 'Announcement'); ?></h3>
                            <p class="announcement-public-message"><?php echo nl2br(htmlspecialchars($ann_content)); ?></p>
                            <div class="announcement-public-date">
                                <i class="fas fa-calendar-day"></i>
                                <?php echo safe_date_fmt($ann['posted_at'] ?? ''); ?>
                            </div>
                        </div>
                    </article>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="announcement-public-empty">
                <i class="fas fa-bullhorn"></i>
                <h5>No Announcements Yet</h5>
                <p class="mb-0">Published announcements from the LGU will appear here.</p>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- 4. Commuter FAQ Accordion -->
    <section class="section bg-light" id="commuter-faq">
        <div class="container">
            <h2 class="section-title">Commuter Information Center</h2>
            <p class="section-subtitle">Answers to the most common questions from Quezon City commuters and motorists</p>
            <div class="row justify-content-center">
                <div class="col-lg-9">
                    <div class="accordion faq-accordion" id="commuterFaqAccordion">
                        <div class="accordion-item">
                            <h3 class="accordion-header" id="faqOneHeading">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faqOne" aria-expanded="true" aria-controls="faqOne">
                                    <span class="faq-icon"><i class="fas fa-gavel"></i></span>
                                    How do I contest a traffic ticket issued in Quezon City?
                                </button>
                            </h3>
                            <div id="faqOne" class="accordion-collapse collapse show" aria-labelledby="faqOneHeading" data-bs-parent="#commuterFaqAccordion">
                                <div class="accordion-body">
                                    <strong>5-day contest window.</strong> Bring your ticket, valid ID, and supporting evidence (dashcam, photo) to the QC Department of Public Order &amp; Safety (DPOS) at QC Hall Compound, 8AM–5PM Mon–Fri. You may also file via email at <a href="mailto:roads@lgu.gov.ph">roads@lgu.gov.ph</a>. Adjudication is typically <strong>3–5 working days</strong>; fines are held in abeyance until resolution. Tip: keep your citation number — you can track status at the <a href="public_reports.php">Road Reports portal</a>.
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h3 class="accordion-header" id="faqTwoHeading">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqTwo" aria-expanded="false" aria-controls="faqTwo">
                                    <span class="faq-icon"><i class="fas fa-bicycle"></i></span>
                                    What are the penalties for parking on a bike lane?
                                </button>
                            </h3>
                            <div id="faqTwo" class="accordion-collapse collapse" aria-labelledby="faqTwoHeading" data-bs-parent="#commuterFaqAccordion">
                                <div class="accordion-body">
                                    Under QC Ordinance No. SP-2942 and MMDA coordination, <strong>illegal parking on protected bike lanes is ₱1,000 (first offense) to ₱2,000 + towing for repeat</strong>. Towing is enforced 24/7 on Elliptical, East Ave, and QC Circle access roads. Motorcycles, e-trikes, and vendor carts are also covered. Report violations via the <a href="#home" onclick="document.getElementById('makeReportBtn').click(); return false;">Make a Report</a> button — include a photo with plate visible.
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h3 class="accordion-header" id="faqThreeHeading">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqThree" aria-expanded="false" aria-controls="faqThree">
                                    <span class="faq-icon"><i class="fas fa-traffic-light"></i></span>
                                    How long does traffic signal repair take after reporting?
                                </button>
                            </h3>
                            <div id="faqThree" class="accordion-collapse collapse" aria-labelledby="faqThreeHeading" data-bs-parent="#commuterFaqAccordion">
                                <div class="accordion-body">
                                    <strong>Standard SLA: 24–48 hours</strong> for signal bulb/controller faults, <strong>72 hours</strong> for knocked-down poles or power-feed damage. Emergency blinking-red mode is deployed within <strong>4 hours</strong> and manual enforcers are dispatched. Track progress on <a href="public_reports.php">Browse All Reports</a> — look for status <em>In-Progress</em> → <em>Completed</em>. For outages blocking intersections, call <strong>(02) 8988-1234</strong>.
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h3 class="accordion-header" id="faqFourHeading">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqFour" aria-expanded="false" aria-controls="faqFour">
                                    <span class="faq-icon"><i class="fas fa-road"></i></span>
                                    Where can I see planned road closures &amp; alternate routes?
                                </button>
                            </h3>
                            <div id="faqFour" class="accordion-collapse collapse" aria-labelledby="faqFourHeading" data-bs-parent="#commuterFaqAccordion">
                                <div class="accordion-body">
                                    Closures are posted in <a href="#updates">Road Updates</a>, <a href="#announcements">Announcements</a>, and on the <a href="#" onclick="document.getElementById('publicGisFab')?.click(); return false;">Live Road Map FAB</a> (red overlays). Detour maps are attached to each advisory post. Major events (QC Anniversary, rallies) are announced <strong>48 hours in advance</strong> on the QC Government Facebook page. Pro tip: open the <strong>Live Road Map FAB → Tools → Traffic Flow</strong> to preview current congestion before you leave.
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h3 class="accordion-header" id="faqFiveHeading">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqFive" aria-expanded="false" aria-controls="faqFive">
                                    <span class="faq-icon"><i class="fas fa-tools"></i></span>
                                    How are pothole reports prioritized?
                                </button>
                            </h3>
                            <div id="faqFive" class="accordion-collapse collapse" aria-labelledby="faqFiveHeading" data-bs-parent="#commuterFaqAccordion">
                                <div class="accordion-body">
                                    Potholes are triaged by <strong>severity, traffic volume, and proximity to schools/hospitals</strong>. <em>Severe</em> (tire/axle damage, &gt;15cm deep) is patched within <strong>5 days</strong>; <em>Medium</em> within <strong>15 days</strong>. Submit via <a href="#home" onclick="document.getElementById('makeReportBtn').click(); return false;">Make a Report</a> with at least 2 photos and a pinned location — it auto-routes to the correct QC district engineering office.
                                </div>
                            </div>
                        </div>
                    </div>
                    <p class="text-center small text-muted mt-4 mb-0"><i class="fas fa-headset me-1"></i> Still need help? <a href="#contact">Contact the Road &amp; Transportation Department</a> or call <a href="tel:+63289881234">(02) 8988-1234</a>.</p>
                </div>
            </div>
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

    <!-- Shared TomTom / citizen API config (a11y_js merges TOMTOM key + loads Leaflet / GIS) -->
    <script>
        window.TOMTOM_API_PROXY = 'lgu_staff/pages/api/tomtom/proxy.php';
        window.LG_ASSET_CONFIG = {
            TOMTOM_API_KEY: <?php echo json_encode(defined('TOMTOM_API_KEY') ? TOMTOM_API_KEY : ''); ?>,
            CITIZEN_API: 'lgu_staff/pages/api/citizen_report.php'
        };
    </script>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>

    <!-- Custom JavaScript -->
    <script src="assets/js/main.js?v=<?php echo $asset_version; ?>"></script>


    <?php include __DIR__ . '/includes/hamburger_menu_js.php'; ?>

    <script src="lgu_staff/js/page-transition.js"></script>
    <?php include __DIR__ . '/includes/a11y_js.php'; ?>

    <!-- Citizen Report (map, OTP, photo upload, submit) -->
    <script src="assets/js/citizen-report.js?v=<?php echo htmlspecialchars((string)(@filemtime(__DIR__ . '/assets/js/citizen-report.js') ?: $asset_version), ENT_QUOTES, 'UTF-8'); ?>"></script>

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

    <!-- Smart Mobility Hub Features -->
    <script>
    (function(){
        'use strict';

        // 5. Emergency Ticker dismiss (persist for session)
        window.dismissEmergencyTicker = function(){
            var el = document.getElementById('emergencyTicker');
            if(el){ el.classList.add('dismissed'); try{ sessionStorage.setItem('qc_emergency_dismissed','1'); }catch(e){} }
        };
        try{ if(sessionStorage.getItem('qc_emergency_dismissed')==='1'){ var t=document.getElementById('emergencyTicker'); if(t) t.classList.add('dismissed'); } }catch(e){}

        // 3. Road Updates Filter Bar (client-side, no reload, does not touch PHP data)
        var pills = document.querySelectorAll('.filter-pill');
        var items = document.querySelectorAll('.road-update-item');
        var emptyState = document.getElementById('filterEmptyState');
        function applyFilter(cat){
            var visible=0;
            items.forEach(function(card){
                var c = card.getAttribute('data-category') || 'other';
                var show = (cat==='all' || c===cat);
                card.style.display = show ? '' : 'none';
                card.classList.toggle('filtered-out', !show);
                if(show) visible++;
            });
            if(emptyState){ emptyState.classList.toggle('show', visible===0 && items.length>0); }
            pills.forEach(function(p){
                var active = p.getAttribute('data-filter')===cat;
                p.classList.toggle('active', active);
                p.setAttribute('aria-selected', active ? 'true':'false');
            });
        }
        pills.forEach(function(p){
            p.addEventListener('click', function(){ applyFilter(this.getAttribute('data-filter')); });
        });
        // keyboard accessible handled by button role

        // 2. Live Traffic — inline #liveTrafficMap removed per user request (FAB provides the GIS map).
        // Keep toggleLiveTraffic as a backward-compatible shim that opens the public-gis FAB and ensures traffic flow is visible.
        window.toggleLiveTraffic = function(){
            var fab = document.getElementById('publicGisFab');
            if(fab) fab.click();
            setTimeout(function(){
                var tBtn = document.getElementById('publicToggleTrafficBtn');
                if(tBtn && tBtn.classList.contains('is-off')) tBtn.click();
            }, 450);
        };
    })();
    </script>

    <!-- View Route Map Modal GIS — GIS interface with dropdown for all 8 bus routes (road-following, point-to-point at every turn) -->
    <script>
    (function(){
        'use strict';
        var vrMap = null, vrTrafficLayer = null, vrMapInited = false;
        var vrRouteLayer = null, vrRouteMarkers = null;
        var vrRoute1Layer = null, vrRoute1Markers = null; // legacy aliases
        var QC_CENTER = [14.651417, 121.04917];
        // Full 8 routes — Route 1 detailed per official QC Gov map, others via official keyStops
        var ROUTE1_STOPS = [
            {name:'QC Hall Gate 3 — Kalayaan Ave. (Start)', lat:14.6479, lng:121.0518, type:'stop'},
            {name:'Masigla & Kalayaan Avenue', lat:14.6395, lng:121.0560, type:'stop'},
            {name:'Bus Stop - Route 3 - Kalayaan Ave cor. Kamias', lat:14.6360, lng:121.0605, type:'stop'},
            {name:'Barangay Silangan Hall', lat:14.6255, lng:121.0600, type:'stop'},
            {name:'Aurora Boulevard & 15th Avenue', lat:14.6205, lng:121.0590, type:'stop'},
            {name:'Ali Mall (Araneta City - Dulo) — Terminal', lat:14.6198, lng:121.0566, type:'stop'}
        ];
        var BUS_GIS_ROUTES = {
            1: { name:'Route 1: QC Hall to Cubao — Kalayaan Ave.', corridor:'Kalayaan Ave. via 15th Ave → Aurora Blvd', waypoints: ROUTE1_STOPS },
            2: { name:'Route 2: QC Hall to Litex / IBP Road', corridor:'Commonwealth Ave corridor', waypoints:[
                {name:'QC Hall Gate 3 — Kalayaan Ave. (Start)', lat:14.6479, lng:121.0518, type:'stop'},
                {name:'St. Peter Parish: Shrine of Leaders (Commonwealth Avenue)', lat:14.6803, lng:121.0849, type:'stop'},
                {name:'Rosario Maclang Bautista General Hospital (IBP Road)', lat:14.6861, lng:121.0891, type:'stop'},
                {name:'QCU Guidance (Batasan Hills Campus, IBP Road)', lat:14.6900, lng:121.1010, type:'stop'},
                {name:'Litex Market (Dulo / Terminal)', lat:14.7002, lng:121.0876, type:'stop'}
            ]},
            3: { name:'Route 3: Welcome Rotonda to Aurora-Katipunan', corridor:'E. Rodriguez / Aurora Blvd', waypoints:[
                {name:'Welcome Rotonda / E. Rodriguez Sr. Avenue (Mabuhay Rotonda)', lat:14.6178, lng:121.0017, type:'stop'},
                {name:'E. Rodriguez Sr. Avenue (Quezon Institute)', lat:14.6185, lng:121.0180, type:'stop'},
                {name:'E. Rodriguez Sr. Avenue (St. Luke’s / National Children’s Hospital)', lat:14.6227, lng:121.0233, type:'stop'},
                {name:'E. Rodriguez Sr. Avenue corner Gilmore Interchange', lat:14.6225, lng:121.0330, type:'stop'},
                {name:'Kamuning Road (Delgado Hospital / Kamuning Market)', lat:14.6275, lng:121.0375, type:'stop'},
                {name:'Kamuning Road (K-E Street)', lat:14.6280, lng:121.0430, type:'stop'},
                {name:'Kamias Road / EDSA Interchange', lat:14.6295, lng:121.0505, type:'stop'},
                {name:'Kalayaan Avenue corner Kamias Interchange', lat:14.6360, lng:121.0605, type:'stop'},
                {name:'Kamias Road corner Anonas Road', lat:14.6285, lng:121.0630, type:'stop'},
                {name:'Anonas Road (Chico Street)', lat:14.6260, lng:121.0635, type:'stop'},
                {name:'LRT-2 Anonas Station, Aurora Blvd.', lat:14.6280, lng:121.0647, type:'stop'},
                {name:'Aurora Boulevard (J.P. Rizal Street)', lat:14.6220, lng:121.0600, type:'stop'},
                {name:'Aurora Boulevard corner Katipunan Interchange (Dulo)', lat:14.6305, lng:121.0730, type:'stop'}
            ]},
             4: { name:'Route 4: QC Hall to General Luis', corridor:'Mindanao / Quirino Hwy', waypoints:[
                 {name:'QC Hall Gate 3 — Kalayaan Ave. (Start)', lat:14.6480, lng:121.0504, type:'stop'},
                 {name:'North Avenue (Veterans / Vertis North)', lat:14.6546, lng:121.0351, type:'stop'},
                 {name:'Mindanao Avenue cor. Road 1', lat:14.6570, lng:121.0360, type:'stop'},
                 {name:'Mindanao Avenue (Tullahan Bridge)', lat:14.6640, lng:121.0360, type:'stop'},
                 {name:'Mindanao Avenue cor. Congressional Avenue', lat:14.6697, lng:121.0326, type:'stop'},
                 {name:'Mindanao Avenue cor. Tandang Sora Avenue', lat:14.6734, lng:121.0320, type:'stop'},
                 {name:'Mindanao Avenue cor. D. Muñoz Street', lat:14.6803, lng:121.0319, type:'stop'},
                 {name:'Mindanao Avenue cor. Old Sauyo Road', lat:14.6887, lng:121.0301, type:'stop'},
                 {name:'Mindanao Avenue cor. Quirino Highway', lat:14.6959, lng:121.0320, type:'stop'},
                 {name:'Quezon City University Main Campus / Novaliches District Hospital', lat:14.7008, lng:121.0349, type:'stop'},
                 {name:'Quirino Highway (SM City Novaliches)', lat:14.7058, lng:121.0382, type:'stop'},
                 {name:'General Luis (Nova Bayan)', lat:14.7195, lng:121.0399, type:'stop'},
                 {name:'General Luis cor. Banahaw Street', lat:14.7205, lng:121.0305, type:'stop'},
                 {name:'General Luis cor. SB Road (Dulo / Terminal)', lat:14.7210, lng:121.0290, type:'stop'}
             ]},
             5: { name:'Route 5: QC Hall to Mindanao Ave. via Visayas Ave.', corridor:'Visayas/Congressional', waypoints:[
                 {name:'QC Hall Gate 3 — Kalayaan Ave. (Start)', lat:14.6509, lng:121.0520, type:'stop'},
                 {name:'Visayas Ave. — Central Ave.', lat:14.6605, lng:121.0449, type:'stop'},
                 {name:'Visayas Ave. — Vargas St.', lat:14.6655, lng:121.0445, type:'stop'},
                 {name:'Congressional Ave. — Visayas Ave.', lat:14.6718, lng:121.0420, type:'stop'},
                 {name:'Congressional Ave. — Circle C', lat:14.6707, lng:121.0377, type:'stop'},
                 {name:'Mindanao Ave. — Congressional Ave.', lat:14.6697, lng:121.0326, type:'stop'},
                 {name:'Mindanao Ave. — Quirino Hwy. (Terminal)', lat:14.6904, lng:121.0284, type:'stop'}
             ]},
            6: { name:'Route 6: QC Hall to Gilmore', corridor:'East Ave / E. Rodriguez', waypoints:[
                {name:'QC Hall Gate 3', lat:14.6479, lng:121.0518, type:'stop'},
                {name:'East Ave.', lat:14.6445, lng:121.0508, type:'turn'},
                {name:'E. Rodriguez Sr. Ave.', lat:14.6225, lng:121.0455, type:'turn'},
                {name:'Gilmore (Terminal)', lat:14.6255, lng:121.0520, type:'stop'}
            ]},
            7: { name:'Route 7: QC Hall to C5 / Ortigas Ave. Ext.', corridor:'C-5 Road', waypoints:[
                {name:'QC Hall Gate 3', lat:14.6479, lng:121.0518, type:'stop'},
                {name:'C-5 Road — Libis', lat:14.6250, lng:121.0700, type:'turn'},
                {name:'Ortigas Ave. Ext. (Terminal)', lat:14.5850, lng:121.0800, type:'stop'}
            ]},
            8: { name:'Route 8: QC Hall to Muñoz', corridor:'North Ave / Roosevelt', waypoints:[
                {name:'QC Hall Gate 3', lat:14.6479, lng:121.0518, type:'stop'},
                {name:'North Ave.', lat:14.6515, lng:121.0385, type:'turn'},
                {name:'Roosevelt Ave.', lat:14.6420, lng:121.0280, type:'turn'},
                {name:'Muñoz (Terminal)', lat:14.6575, lng:121.0200, type:'stop'}
            ]}
        };
        // keep alias for external
        var currentRouteId = 1;
        function getTomTomKey(){ return (window.LG_ASSET_CONFIG && window.LG_ASSET_CONFIG.TOMTOM_API_KEY) || window.TOMTOM_API_KEY || ''; }
        function initViewRouteMap(){
            if(vrMapInited || typeof L === 'undefined') return;
            var el = document.getElementById('viewRouteGisMap');
            if(!el) return;
            var key = getTomTomKey();
            vrMap = L.map('viewRouteGisMap', { zoomControl: true }).setView(QC_CENTER, 13);
            L.tileLayer('https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=' + key, { attribution: '\u00A9 TomTom', maxZoom: 18 }).addTo(vrMap);
            vrTrafficLayer = L.tileLayer('https://api.tomtom.com/traffic/map/4/tile/flow/relative0/{z}/{x}/{y}.png?view=Unified&key=' + key, { attribution: '\u00A9 TomTom Traffic', opacity: 0.7, maxZoom: 18 }).addTo(vrMap);
            vrMapInited = true;
            setTimeout(function(){ if(vrMap) vrMap.invalidateSize(); }, 100);
        }
        function extractRoutePoints(routeData){
            var pts = [];
            try{
                var legs = routeData.routes && routeData.routes[0] && routeData.routes[0].legs;
                if(legs){
                    legs.forEach(function(leg){
                        if(leg.path && leg.path.coordinates){
                            leg.path.coordinates.forEach(function(c){ pts.push([c[1], c[0]]); });
                        } else if(leg.points){
                            leg.points.forEach(function(p){ pts.push([p.latitude, p.longitude]); });
                        }
                    });
                }
                if(!pts.length && routeData.routes && routeData.routes[0] && routeData.routes[0].path && routeData.routes[0].path.coordinates){
                    routeData.routes[0].path.coordinates.forEach(function(c){ pts.push([c[1], c[0]]); });
                }
                if(!pts.length && routeData.path && routeData.path.coordinates){
                    routeData.path.coordinates.forEach(function(c){ pts.push([c[1], c[0]]); });
                }
            }catch(e){}
            return pts;
        }
        function fetchSegmentRoad(from, to){
            if(!window.TomTomServices || !window.TomTomServices.calculateRoute){
                return Promise.resolve([[from.lat, from.lng],[to.lat, to.lng]]);
            }
            return window.TomTomServices.calculateRoute(from.lat, from.lng, to.lat, to.lng).then(function(data){
                if(!data || !data.success || !data.data) return [[from.lat, from.lng],[to.lat, to.lng]];
                var pts = extractRoutePoints(data.data);
                return pts.length ? pts : [[from.lat, from.lng],[to.lat, to.lng]];
            }).catch(function(){ return [[from.lat, from.lng],[to.lat, to.lng]]; });
        }
        function renderOverlay(routeId){
            var overlay = document.getElementById('viewRoute1MapOverlay');
            var route = BUS_GIS_ROUTES[routeId];
            if(!overlay || !route) return;
            var stops = route.waypoints.filter(function(w){ return w.type==='stop'; });
            var numEl = document.getElementById('viewRouteOverlayNum');
            if(numEl) numEl.textContent = String(routeId);
            var title = document.getElementById('viewRouteOverlayTitle');
            if(title) title.textContent = route.name;
            var corridorEl = document.getElementById('viewRouteOverlayCorridor');
            if(corridorEl) corridorEl.textContent = route.corridor + ' • Official QC Gov';
            var stopsEl = document.getElementById('viewRouteOverlayStops');
            if(stopsEl){
                stopsEl.innerHTML = stops.map(function(s, idx){
                    var isStart = idx===0, isEnd = idx===stops.length-1;
                    var dotCls = isStart ? 'start' : (isEnd ? 'end' : '');
                    return '<span class="view-route1-stop"><span class="dot '+dotCls+'"></span> '+s.name.replace(' — Terminal','').replace(' (Terminal)','').replace(' QC Hall Gate 3','Gate 3')+'</span>' + (idx < stops.length-1 ? '<span class="view-route1-arrow">→</span>' : '');
                }).join('');
            }
        }
        function clearRoute(){
            if(vrRouteLayer && vrMap){ vrMap.removeLayer(vrRouteLayer); vrRouteLayer=null; }
            if(vrRouteMarkers && vrMap){ vrMap.removeLayer(vrRouteMarkers); vrRouteMarkers=null; }
            // legacy
            if(vrRoute1Layer && vrMap){ vrMap.removeLayer(vrRoute1Layer); vrRoute1Layer=null; }
            if(vrRoute1Markers && vrMap){ vrMap.removeLayer(vrRoute1Markers); vrRoute1Markers=null; }
        }
        function showRouteIndication(routeId){
            routeId = parseInt(routeId,10) || 1;
            if(!BUS_GIS_ROUTES[routeId]) routeId = 1;
            currentRouteId = routeId;
            var overlay = document.getElementById('viewRoute1MapOverlay');
            if(overlay) overlay.classList.add('is-visible');
            renderOverlay(routeId);
            var titleEl = document.getElementById('viewRouteMapModalLabel');
            if(titleEl){
                var r = BUS_GIS_ROUTES[routeId];
                titleEl.innerHTML = '<span style="width:32px;height:32px;background:rgba(255,255,255,0.18);border-radius:8px;display:inline-flex;align-items:center;justify-content:center;"><i class="fas fa-map-marked-alt"></i></span> ' + r.name + ' — Map View';
            }
            var dropdown = document.getElementById('viewRouteDropdown');
            if(dropdown) dropdown.value = String(routeId);
            if(!vrMap) return;
            clearRoute();
            var waypoints = BUS_GIS_ROUTES[routeId].waypoints;
            vrRouteMarkers = L.layerGroup().addTo(vrMap);
            vrRoute1Markers = vrRouteMarkers; // alias
            waypoints.forEach(function(s, idx){
                var isStart = idx===0, isEnd = idx===waypoints.length-1;
                var isTurn = s.type === 'turn';
                var bg = isTurn ? '#f59e0b' : (isStart ? '#10b981' : (isEnd ? '#dc2626' : '#115272'));
                var size = isTurn ? 18 : 22;
                var iconInner = isTurn ? '<i class="fas fa-share" style="font-size:8px;"></i>' : (idx+1);
                var label = isTurn ? 'Turn' : 'Stop';
                var html = '<div style="width:'+size+'px;height:'+size+'px;border-radius:50%;background:'+bg+';border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.3);display:flex;align-items:center;justify-content:center;color:#fff;font-size:'+(isTurn?'7px':'9px')+';font-weight:800;" title="'+s.name+'">'+iconInner+'</div>';
                var icon = L.divIcon({html:html, className:'', iconSize:[size,size], iconAnchor:[size/2,size/2]});
                var popup = '<strong style="font-size:12px;">'+s.name+'</strong><br><small style="color:'+(isTurn?'#f59e0b':'#115272')+';font-weight:600;">'+label+' '+(idx+1)+'/'+waypoints.length+' • '+(isTurn?'Follow lane':'Official stop')+'</small>';
                if(isTurn) popup += '<br><small style="color:#64748b;">Point-to-point turn — stays on road</small>';
                L.marker([s.lat, s.lng], {icon:icon}).bindPopup(popup).addTo(vrRouteMarkers);
            });
            // fetch road-following geometry point-to-point at every turn so it never bypasses lanes
            var promises = [];
            for(var i=0;i<waypoints.length-1;i++){
                promises.push(fetchSegmentRoad(waypoints[i], waypoints[i+1]));
            }
            Promise.all(promises).then(function(segments){
                if(!vrMap) return;
                if(vrRouteLayer) return;
                var all = [];
                segments.forEach(function(seg){
                    if(!seg || !seg.length) return;
                    if(all.length && seg.length){
                        var last = all[all.length-1];
                        var first = seg[0];
                        if(last[0]===first[0] && last[1]===first[1]) seg = seg.slice(1);
                    }
                    all = all.concat(seg);
                });
                if(!all.length) all = waypoints.map(function(s){ return [s.lat, s.lng]; });
                vrRouteLayer = L.polyline(all, {color:'#115272', weight:5, opacity:0.94, lineCap:'round', lineJoin:'round'}).addTo(vrMap);
                vrRoute1Layer = vrRouteLayer;
                L.polyline(all, {color:'#ffffff', weight:1.2, opacity:0.35, lineCap:'round', lineJoin:'round'}).addTo(vrRouteMarkers);
                try { vrMap.fitBounds(vrRouteLayer.getBounds().pad(0.14)); } catch(e){}
                if(vrRouteMarkers) vrRouteMarkers.bringToFront();
            });
        }
        function showRoute1Indication(){ return showRouteIndication(1); }
        function hideRoute1Indication(){
            var overlay = document.getElementById('viewRoute1MapOverlay');
            if(overlay) overlay.classList.remove('is-visible');
            if(vrRoute1Layer && vrMap){ vrMap.removeLayer(vrRoute1Layer); vrRoute1Layer=null; }
            if(vrRoute1Markers && vrMap){ vrMap.removeLayer(vrRoute1Markers); vrRoute1Markers=null; }
        }
        // expose for second map
        window.BUS_GIS_ROUTES = BUS_GIS_ROUTES;
        window.showRouteIndication = showRouteIndication;
        document.addEventListener('DOMContentLoaded', function(){
            var modalEl = document.getElementById('viewRouteMapModal');
            var qcModalEl = document.getElementById('qcBusRoutesModal');
            var dropdown = document.getElementById('viewRouteDropdown');
            var qcDropdown = document.getElementById('qcBusRoutesDropdown');
            // sync dropdowns
            function syncDropdowns(routeId){
                if(dropdown) dropdown.value = String(routeId);
                if(qcDropdown) qcDropdown.value = String(routeId);
            }
            if(dropdown){
                dropdown.addEventListener('change', function(){
                    var rid = parseInt(this.value,10)||1;
                    syncDropdowns(rid);
                    showRouteIndication(rid);
                    if(qcMap) showQcBusRoute(rid);
                });
            }
            if(qcDropdown){
                qcDropdown.addEventListener('change', function(){
                    var rid = parseInt(this.value,10)||1;
                    syncDropdowns(rid);
                    showQcBusRoute(rid);
                    showRouteIndication(rid);
                });
            }
            // also sync View Route on Map buttons to set dropdown
            document.querySelectorAll('#qcBusRoutesModal .btn[data-route-id]').forEach(function(btn){
                btn.addEventListener('click', function(){ var rid=parseInt(this.getAttribute('data-route-id'),10)||1; syncDropdowns(rid); });
            });
            if(modalEl){
                modalEl.addEventListener('show.bs.modal', function(e){
                    var trigger = e.relatedTarget;
                    var routeId = 1;
                    if(trigger){
                        var rid = trigger.getAttribute('data-route-id') || trigger.getAttribute('data-route') || '';
                        if(rid) routeId = parseInt(rid,10) || 1;
                        else if(trigger.id==='viewRoute1MapBtn') routeId = 1;
                        else if(trigger.classList && trigger.classList.contains('btn')) {
                            routeId = dropdown ? (parseInt(dropdown.value,10)||1) : 1;
                        }
                    } else {
                        routeId = dropdown ? (parseInt(dropdown.value,10)||1) : (qcDropdown ? parseInt(qcDropdown.value,10)||1 : 1);
                    }
                    modalEl._showRouteId = routeId;
                    syncDropdowns(routeId);
                });
                modalEl.addEventListener('shown.bs.modal', function(){
                    initViewRouteMap();
                    if(!vrMap) return;
                    setTimeout(function(){
                        vrMap.invalidateSize();
                        var rid = modalEl._showRouteId || (dropdown ? parseInt(dropdown.value,10)||1 : 1);
                        showRouteIndication(rid);
                    }, 180);
                });
                modalEl.addEventListener('hide.bs.modal', function(){ hideRoute1Indication(); });
            }
            // QC Bus Routes modal GIS (first changes visible on localhost)
            var qcMap = null, qcRouteLayer = null, qcRouteMarkers = null, qcMapInited = false;
            function initQcBusMap(){
                if(qcMapInited || typeof L === 'undefined') return;
                var el = document.getElementById('qcBusRoutesGisMap');
                if(!el) return;
                var key = (window.LG_ASSET_CONFIG && window.LG_ASSET_CONFIG.TOMTOM_API_KEY) || window.TOMTOM_API_KEY || '';
                qcMap = L.map('qcBusRoutesGisMap', { zoomControl: true }).setView(QC_CENTER, 13);
                L.tileLayer('https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=' + key, { attribution: '\u00A9 TomTom', maxZoom: 18 }).addTo(qcMap);
                L.tileLayer('https://api.tomtom.com/traffic/map/4/tile/flow/relative0/{z}/{x}/{y}.png?view=Unified&key=' + key, { attribution: '\u00A9 TomTom Traffic', opacity: 0.7, maxZoom: 18 }).addTo(qcMap);
                qcMapInited = true;
                setTimeout(function(){ if(qcMap) qcMap.invalidateSize(); }, 100);
            }
            function clearQcBusRoute(){
                if(qcRouteLayer && qcMap){ qcMap.removeLayer(qcRouteLayer); qcRouteLayer=null; }
                if(qcRouteMarkers && qcMap){ qcMap.removeLayer(qcRouteMarkers); qcRouteMarkers=null; }
            }
            function showQcBusRoute(routeId){
                routeId = parseInt(routeId,10)||1;
                if(!BUS_GIS_ROUTES[routeId]) routeId=1;
                syncDropdowns(routeId);
                if(!qcMap) return;
                clearQcBusRoute();
                var waypoints = BUS_GIS_ROUTES[routeId].waypoints;
                qcRouteMarkers = L.layerGroup().addTo(qcMap);
                waypoints.forEach(function(s, idx){
                    var isStart = idx===0, isEnd = idx===waypoints.length-1;
                    var isTurn = s.type==='turn';
                    var bg = isTurn ? '#f59e0b' : (isStart ? '#10b981' : (isEnd ? '#dc2626' : '#115272'));
                    var size = isTurn ? 16 : 20;
                    var iconInner = isTurn ? '<i class="fas fa-share" style="font-size:7px;"></i>' : (idx+1);
                    var html = '<div style="width:'+size+'px;height:'+size+'px;border-radius:50%;background:'+bg+';border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.3);display:flex;align-items:center;justify-content:center;color:#fff;font-size:'+(isTurn?'6px':'8px')+';font-weight:800;" title="'+s.name+'">'+iconInner+'</div>';
                    var icon = L.divIcon({html:html, className:'', iconSize:[size,size], iconAnchor:[size/2,size/2]});
                    L.marker([s.lat, s.lng], {icon:icon}).bindPopup('<strong>'+s.name+'</strong><br><small>'+(isTurn?'Turn':'Stop')+' '+(idx+1)+'/'+waypoints.length+'</small>').addTo(qcRouteMarkers);
                });
                var promises = [];
                for(var i=0;i<waypoints.length-1;i++) promises.push(fetchSegmentRoad(waypoints[i], waypoints[i+1]));
                Promise.all(promises).then(function(segments){
                    if(!qcMap) return;
                    var all=[];
                    segments.forEach(function(seg){
                        if(!seg||!seg.length) return;
                        if(all.length && seg.length){
                            var last=all[all.length-1], first=seg[0];
                            if(last[0]===first[0] && last[1]===first[1]) seg=seg.slice(1);
                        }
                        all=all.concat(seg);
                    });
                    if(!all.length) all=waypoints.map(function(s){return [s.lat,s.lng];});
                    if(qcRouteLayer && qcMap) qcMap.removeLayer(qcRouteLayer);
                    qcRouteLayer = L.polyline(all, {color:'#115272', weight:4, opacity:0.92, lineCap:'round', lineJoin:'round'}).addTo(qcMap);
                    try{ qcMap.fitBounds(qcRouteLayer.getBounds().pad(0.14)); }catch(e){}
                    if(qcRouteMarkers) qcRouteMarkers.bringToFront();
                });
            }
            window.showQcBusRoute = showQcBusRoute;
            if(qcModalEl){
                qcModalEl.addEventListener('shown.bs.modal', function(){
                    initQcBusMap();
                    if(!qcMap) return;
                    setTimeout(function(){
                        qcMap.invalidateSize();
                        var rid = qcDropdown ? parseInt(qcDropdown.value,10)||1 : 1;
                        showQcBusRoute(rid);
                    }, 220);
                });
            }
        });
    })();
    </script>
</body>
</html>
