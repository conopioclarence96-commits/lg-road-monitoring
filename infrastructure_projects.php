<?php
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);
session_start();

$basePath = '';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
if (strpos($scriptName, '/lgu_staff/') !== false) {
    $basePath = '../';
} elseif (strpos($scriptName, '/public/') !== false) {
    $basePath = '../';
}

$database_available = false;
$conn = null;
require_once __DIR__ . '/lgu_staff/includes/config.php';
require_once __DIR__ . '/lgu_staff/includes/functions.php';
$database_available = true;

function safe_date_fmt($date, $format = 'M d, Y', $fallback = '—') {
    if (empty($date)) return $fallback;
    $ts = @strtotime((string)$date);
    return ($ts !== false && $ts > 0) ? date($format, $ts) : $fallback;
}

$projects = [];
if ($database_available && $conn) {
    try {
        $stmt = $conn->prepare("SELECT id, name, location, budget, progress, status, start_date, end_date FROM infrastructure_projects ORDER BY FIELD(status, 'active', 'pending', 'delayed', 'completed'), start_date DESC");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $projects[] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        $projects = [];
    }
}

$infra_reports = [];
if ($database_available && $conn) {
    try {
        $tr_est = false;
        $est_res = $conn->query("SHOW COLUMNS FROM road_transportation_reports LIKE 'estimation'");
        if ($est_res && $est_res->num_rows > 0) $tr_est = true;
        $tr_est_col = $tr_est ? 'estimation' : '0 as estimation';

        $maint_est = false;
        $est_res = $conn->query("SHOW COLUMNS FROM road_maintenance_reports LIKE 'estimation'");
        if ($est_res && $est_res->num_rows > 0) $maint_est = true;
        $maint_est_col = $maint_est ? 'estimation' : '0 as estimation';

        $infra_sql = "SELECT id, report_id, title, description, location, latitude, longitude, priority, status, assigned_to, department, created_date, created_at, updated_at, approved_at, {$tr_est_col} as estimation, report_type, report_category, 'maintenance' as source_system FROM road_transportation_reports WHERE report_type = 'infrastructure_issue' AND status IN ('approved', 'in-progress', 'completed')";
        $infra_res = $conn->query($infra_sql);
        if ($infra_res) {
            while ($row = $infra_res->fetch_assoc()) $infra_reports[] = $row;
        }

        $maint_sql = "SELECT id, report_id, title, description, location, priority, status, maintenance_team as assigned_to, department, created_date, created_at, updated_at, approved_at, {$maint_est_col} as estimation, report_type, 'maintenance' as source_system FROM road_maintenance_reports WHERE status IN ('approved', 'in-progress')";
        $maint_res = $conn->query($maint_sql);
        if ($maint_res) {
            while ($row = $maint_res->fetch_assoc()) $infra_reports[] = $row;
        }

        if ($conn->query("SHOW TABLES LIKE 'ipms_road_projects'")->num_rows > 0) {
            $ipms_sql = "SELECT id, project_id, project_name, project_status, status_bucket,
                progress_percent, start_date, end_date, road_name, road_type, road_status,
                start_lat, start_lng, end_lat, end_lng, budget, assigned_engineers_json,
                created_at, status, priority, start_address, end_address
                FROM ipms_road_projects
                WHERE status IN ('approved','completed')";
            $ipms_res = @$conn->query($ipms_sql);
            if ($ipms_res) {
                while ($row = $ipms_res->fetch_assoc()) {
                    $engineers = [];
                    if (!empty($row['assigned_engineers_json'])) {
                        $decoded = json_decode($row['assigned_engineers_json'], true);
                        if (is_array($decoded)) $engineers = $decoded;
                    }
                    $location = $row['start_address'] ?: $row['road_name'];
                    $infra_reports[] = [
                        'id'            => 'ipms_' . $row['id'],
                        'report_id'     => 'IPMS-' . $row['project_id'],
                        'title'         => $row['project_name'],
                        'description'   => $row['road_status'] . ($row['road_name'] ? ' — ' . $row['road_name'] : ''),
                        'location'      => $location,
                        'latitude'      => $row['start_lat'],
                        'longitude'     => $row['start_lng'],
                        'priority'      => $row['priority'] ?: 'medium',
                        'status'        => $row['status'] ?: 'approved',
                        'assigned_to'   => implode(', ', $engineers),
                        'department'    => 'IPMS',
                        'created_at'    => $row['created_at'],
                        'estimation'    => $row['budget'],
                        'report_type'   => 'ipms_project',
                        'source_system' => 'ipms',
                        '_ipms_progress'=> (int)$row['progress_percent'],
                        '_ipms_start_date' => $row['start_date'] ?? null,
                        '_ipms_end_date'   => $row['end_date'] ?? null,
                    ];
                }
            }
        }

        usort($infra_reports, function ($a, $b) {
            $ta = strtotime($a['created_at'] ?? $a['created_date'] ?? '') ?: 0;
            $tb = strtotime($b['created_at'] ?? $b['created_date'] ?? '') ?: 0;
            return $tb - $ta;
        });
        $infra_reports = array_slice($infra_reports, 0, 12);
    } catch (Exception $e) {
        $infra_reports = [];
    }
}

$stats = [
    'total' => 0,
    'active' => 0,
    'completed' => 0,
    'delayed' => 0,
    'pending' => 0,
    'total_budget' => 0,
];

$stats['total'] = count($projects);
$stats['active'] = count(array_filter($projects, fn($p) => $p['status'] === 'active'));
$stats['completed'] = count(array_filter($projects, fn($p) => $p['status'] === 'completed'));
$stats['delayed'] = count(array_filter($projects, fn($p) => $p['status'] === 'delayed'));
$stats['pending'] = count(array_filter($projects, fn($p) => $p['status'] === 'pending'));
$stats['total_budget'] = array_sum(array_column($projects, 'budget'));

foreach ($infra_reports as $ir) {
    $s = strtolower(str_replace(' ', '-', $ir['status'] ?? ''));
    $stats['total']++;
    if (in_array($s, ['approved', 'in-progress'])) {
        $stats['active']++;
    } elseif ($s === 'completed' || $s === 'resolved') {
        $stats['completed']++;
    } elseif ($s === 'delayed') {
        $stats['delayed']++;
    } elseif ($s === 'pending') {
        $stats['pending']++;
    }
    if (!empty($ir['estimation'])) {
        $stats['total_budget'] += (float)$ir['estimation'];
    }
}

function infra_status_badge($status) {
    $map = [
        'active'    => ['bg' => '#28a745', 'label' => 'Active'],
        'completed' => ['bg' => '#17a2b8', 'label' => 'Completed'],
        'delayed'   => ['bg' => '#dc3545', 'label' => 'Delayed'],
        'pending'   => ['bg' => '#ffc107', 'label' => 'Pending'],
    ];
    $info = $map[$status] ?? ['bg' => '#6c757d', 'label' => ucfirst($status)];
    return '<span style="display:inline-block;background:'.$info['bg'].';color:'.($status === 'pending' ? '#333' : 'white').';padding:3px 12px;border-radius:20px;font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:0.3px;">'.$info['label'].'</span>';
}

function infra_progress_color($progress) {
    if ($progress >= 80) return '#28a745';
    if ($progress >= 50) return '#17a2b8';
    if ($progress >= 25) return '#ffc107';
    return '#dc3545';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/includes/a11y_head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Infrastructure Projects – Citizen Transparency | Road &amp; Transportation Department</title>
    <link rel="icon" type="image/png" href="assets/img/infra-gov-logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles/transition.css">
    <style>
        :root {
            --primary-color: #115272;
            --secondary-color: #1d698b;
            --accent-color: #d93939;
            --light-bg: #f4f6f7;
            --qc-primary-50: #f1f9fe;
            --qc-primary-100: #e1f1fc;
            --qc-primary-200: #c3e3f8;
            --qc-primary-300: #96cdf1;
            --qc-primary-400: #62b2e7;
            --qc-primary-500: #21a1d6;
            --qc-primary-600: #1381b6;
            --qc-primary-700: #15689b;
            --qc-primary-800: #115272;
            --qc-primary-900: #143c5e;
            --qc-primary-950: #0e2f43;
            --qc-shades-100: #eef1f3;
            --qc-shades-200: #d6dce1;
            --qc-shades-300: #c3ccd3;
            --qc-shades-400: #9aa7b0;
            --qc-shades-500: #5f6c75;
            --qc-card-border: #e0e8ee;
            --qc-icon-bg: #d6e9f8;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Montserrat', sans-serif; color: #3e454c; line-height: 1.6; }

        .qc-navbar {
            background: #ffffff !important;
            border-bottom: 1px solid var(--qc-shades-100);
            box-shadow: 0 1px 3px rgba(17, 82, 114, 0.06);
            padding: 0.55rem 0;
        }
        .qc-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; padding: 0; }
        .qc-brand img { height: 46px; width: auto; border-radius: 6px; }
        .qc-brand-text { line-height: 1.15; text-align: left; }
        .qc-brand-text strong { display: block; font-size: 1.02rem; font-weight: 800; color: var(--qc-primary-800); }
        .qc-brand-text small { display: block; font-size: 0.7rem; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; color: var(--qc-primary-600); }

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
        .btn-login:hover { background: var(--qc-primary-600); color: #fff; transform: translateY(-2px); }

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
        .hamburger-btn:hover { background: rgba(17, 82, 114, 0.14) !important; }
        .hamburger-btn .bar { background: var(--qc-primary-800) !important; }

        .hero-bar {
            background:
                linear-gradient(115deg, rgba(11, 42, 62, 0.96) 0%, rgba(17, 82, 114, 0.9) 55%, rgba(19, 129, 182, 0.78) 100%),
                url('assets/img/cityhall.jpeg') center/cover;
            padding: 112px 0 84px;
            color: white;
            text-align: center;
        }
        .hero-bar h1 { font-size: 2.2rem; font-weight: 800; margin-bottom: 8px; text-shadow: 0 2px 10px rgba(0,0,0,0.25); }
        .hero-bar p { font-size: 1.05rem; opacity: 0.92; margin-bottom: 0; }

        .section { padding: 70px 0 60px; background: #ffffff; }
        .section-title { text-align: center; font-size: 1.7rem; font-weight: 800; color: var(--qc-primary-800); margin-bottom: 12px; line-height: 1.25; }
        .section-title::after { content: ''; display: block; width: 56px; height: 4px; margin: 12px auto 0; border-radius: 4px; background: var(--qc-primary-500); }
        .section-subtitle { text-align: center; font-size: 1.02rem; color: var(--qc-shades-500); margin-bottom: 40px; max-width: 620px; margin-left: auto; margin-right: auto; }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 16px;
            max-width: 1280px;
            margin: 0 auto 50px;
            align-items: stretch;
        }
        @media (max-width: 1199.98px) {
            .stats-row { gap: 14px; }
            .stat-card { padding: 22px 14px; }
            .stat-number { font-size: 1.75rem; }
            .stat-icon { width: 56px; height: 56px; font-size: 1.35rem; }
        }
        @media (max-width: 991.98px) {
            .stats-row { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (max-width: 767.98px) {
            .stats-row { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        }
        @media (max-width: 575.98px) {
            .stats-row { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
            .stat-card { padding: 18px 12px; }
            .stat-number { font-size: 1.55rem; }
            .stat-label { font-size: 0.82rem; }
            .stat-sub { font-size: 0.7rem; }
        }
        @media (max-width: 359.98px) {
            .stats-row { grid-template-columns: 1fr; }
        }
        .stat-card {
            background: white;
            border: 1px solid var(--qc-card-border);
            border-radius: 12px;
            padding: 28px 20px;
            text-align: center;
            box-shadow: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 10px 24px rgba(17, 82, 114, 0.1); }
        .stat-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 12px;
            background: var(--qc-icon-bg);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--qc-primary-800);
        }
        .stat-number { font-size: 2rem; font-weight: 800; color: var(--qc-primary-800); margin-bottom: 4px; }
        .stat-label { font-size: 0.9rem; color: var(--qc-shades-500); font-weight: 500; }
        .stat-sub { font-size: 0.75rem; color: var(--qc-shades-400); margin-top: 4px; }

        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(480px, 1fr));
            gap: 30px;
            max-width: 1200px;
            margin: 0 auto;
        }
        @media (max-width: 576px) {
            .projects-grid { grid-template-columns: 1fr; }
        }
        .project-card {
            background: white;
            border: 1px solid var(--qc-card-border);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .project-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 24px rgba(17, 82, 114, 0.1);
        }
        .project-info { padding: 20px 24px; }
        .project-info h4 {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--qc-primary-900);
            margin-bottom: 10px;
        }
        .project-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-bottom: 12px;
            font-size: 0.88rem;
            color: var(--qc-shades-500);
        }
        .project-meta i { color: var(--qc-primary-500); margin-right: 5px; }
        .project-desc {
            font-size: 0.92rem;
            color: var(--qc-shades-500);
            line-height: 1.55;
            margin-top: 10px;
        }

        /* Infrastructure Reports Panel */
        .infra-reports-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fbfe 100%);
            border: 1px solid var(--qc-card-border);
            border-radius: 16px;
            padding: 0;
            max-width: 1200px;
            margin: 0 auto;
            box-shadow: 0 2px 12px rgba(17, 82, 114, 0.06);
            overflow: hidden;
        }
        .infra-reports-header {
            background: linear-gradient(135deg, var(--qc-primary-800) 0%, var(--qc-primary-600) 100%);
            padding: 24px 30px;
            color: white;
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .infra-reports-icon {
            width: 48px;
            height: 48px;
            background: rgba(255,255,255,0.18);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
        }
        .infra-reports-title {
            font-size: 1.2rem;
            font-weight: 700;
        }
        .infra-reports-badge {
            background: rgba(255,255,255,0.22);
            color: #fff;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 600;
        }
        .infra-reports-subtitle {
            font-size: 0.88rem;
            opacity: 0.88;
            width: 100%;
        }
        .infra-reports-search {
            padding: 16px 30px;
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            border-bottom: 1px solid var(--qc-card-border);
        }
        .infra-reports-search input {
            flex: 1;
            min-width: 200px;
            padding: 10px 16px 10px 40px;
            border: 1px solid var(--qc-card-border);
            border-radius: 8px;
            font-size: 0.9rem;
            font-family: 'Montserrat', sans-serif;
            background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%239aa7b0' viewBox='0 0 16 16'%3E%3Cpath d='M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85zm-5.242.656a5 5 0 1 1 0-10 5 5 0 0 1 0 10z'/%3E%3C/svg%3E") no-repeat 14px center;
            transition: border-color 0.2s;
        }
        .infra-reports-search input:focus {
            outline: none;
            border-color: var(--qc-primary-500);
            box-shadow: 0 0 0 3px rgba(33, 161, 214, 0.15);
        }
        .infra-reports-sort-btn {
            background: linear-gradient(135deg, var(--qc-primary-800) 0%, var(--qc-primary-600) 100%);
            color: #fff;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .infra-reports-sort-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(17, 82, 114, 0.25);
        }

        .infra-grid-scroll {
            max-height: calc((260px + 20px) * 2);
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--qc-primary-300) transparent;
            margin: 0 30px;
            border-radius: 12px;
        }
        .infra-grid-scroll::-webkit-scrollbar { width: 6px; }
        .infra-grid-scroll::-webkit-scrollbar-track { background: transparent; }
        .infra-grid-scroll::-webkit-scrollbar-thumb { background: var(--qc-primary-300); border-radius: 3px; }
        .infra-grid-scroll.expanded {
            max-height: none;
            overflow-y: visible;
        }
        @media (max-width: 767.98px) {
            .infra-grid-scroll {
                max-height: calc((260px + 20px) * 2);
                margin: 0 16px;
            }
        }

        .infra-projects-grid {
            display: grid !important;
            grid-template-columns: repeat(2, 1fr) !important;
            grid-auto-rows: min-content;
            gap: 20px;
            padding: 24px 30px 30px;
            align-items: start;
        }
        @media (max-width: 767.98px) {
            .infra-projects-grid {
                grid-template-columns: 1fr !important;
            }
        }

        .infra-scroll-more {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            margin: 0 30px 24px;
            font-size: 13px;
            font-weight: 700;
            color: var(--qc-primary-700);
            background: var(--qc-primary-50);
            border: 1px dashed var(--qc-primary-300);
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.25s ease;
        }
        .infra-scroll-more:hover {
            background: var(--qc-primary-100);
            border-color: var(--qc-primary-500);
            transform: translateY(-2px);
            box-shadow: 0 4px 14px rgba(17, 82, 114, 0.12);
        }
        .infra-scroll-more i {
            font-size: 11px;
            transition: transform 0.3s ease;
        }
        .infra-scroll-more.expanded i {
            transform: rotate(180deg);
        }
        .infra-scroll-more.hidden { display: none; }

        .infra-project-card {
            display: flex;
            flex-direction: column;
            background: #fff;
            border: 1px solid var(--qc-card-border);
            border-radius: 12px;
            overflow: hidden;
            min-height: 0;
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.3s ease;
        }
        .infra-project-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 28px rgba(17, 82, 114, 0.12);
        }
        .infra-project-header {
            padding: 18px 20px 14px;
            border-bottom: 1px solid #f0f4f7;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }
        .infra-project-header .title-wrap {
            flex: 1;
            min-width: 0;
        }
        .infra-project-header .type {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--qc-primary-600);
            margin-bottom: 4px;
        }
        .infra-project-header .title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--qc-primary-900);
            margin-bottom: 4px;
            line-height: 1.3;
        }
        .infra-project-header .report {
            font-size: 0.78rem;
            color: var(--qc-shades-400);
        }
        .infra-project-header .badges {
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex-shrink: 0;
        }
        .infra-project-body {
            padding: 16px 20px;
        }
        .infra-project-body .meta {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 14px;
        }
        .infra-project-body .meta-row {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            color: var(--qc-shades-500);
        }
        .infra-project-body .meta-row i {
            width: 16px;
            text-align: center;
            color: var(--qc-primary-500);
            font-size: 0.8rem;
        }
        .infra-project-body .meta-label {
            font-weight: 600;
            color: var(--qc-shades-400);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .infra-project-body .meta-value {
            color: var(--qc-primary-900);
            font-weight: 500;
        }
        .infra-project-progress {
            margin-top: 4px;
        }
        .infra-project-progress .progress {
            height: 8px;
            border-radius: 8px;
            background: var(--qc-shades-100);
            overflow: hidden;
        }
        .infra-project-progress .progress-bar {
            border-radius: 8px;
            transition: width 0.6s ease;
            background: linear-gradient(90deg, var(--qc-primary-500), var(--qc-primary-700));
        }
        .infra-progress-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
        }
        .infra-progress-head .label {
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--qc-shades-500);
        }
        .infra-progress-head .pct {
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--qc-primary-800);
        }
        .infra-project-footer {
            padding: 14px 20px;
            border-top: 1px solid #f0f4f7;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .infra-project-footer .btn {
            font-size: 0.82rem;
            font-weight: 600;
            padding: 8px 16px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
            cursor: pointer;
            border: none;
            text-decoration: none;
        }
        .infra-project-footer .btn-qc {
            background: var(--qc-primary-800);
            color: #fff;
        }
        .infra-project-footer .btn-qc:hover {
            background: var(--qc-primary-600);
            color: #fff;
            transform: translateY(-1px);
        }
        .infra-project-footer .btn-outline-qc {
            background: transparent;
            color: var(--qc-primary-700);
            border: 1px solid var(--qc-card-border);
        }
        .infra-project-footer .btn-outline-qc:hover {
            background: var(--qc-primary-50);
            border-color: var(--qc-primary-400);
        }
        .infra-reports-verified {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.78rem;
            font-weight: 600;
            color: #28a745;
            padding: 6px 14px;
            background: rgba(40,167,69,0.08);
            border-radius: 20px;
        }
        .infra-reports-verified i { font-size: 0.7rem; }

        .infra-reports-empty {
            text-align: center;
            padding: 48px 20px;
            color: var(--qc-shades-400);
        }
        .infra-reports-empty i { font-size: 3rem; margin-bottom: 12px; color: var(--qc-shades-200); }
        .infra-reports-empty h5 { font-size: 1.1rem; font-weight: 700; color: var(--qc-shades-500); margin-bottom: 6px; }
        .infra-reports-empty p { font-size: 0.9rem; }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--qc-shades-400);
        }
        .empty-state i { font-size: 3.5rem; margin-bottom: 15px; color: var(--qc-shades-200); }
        .empty-state h5 { font-size: 1.2rem; font-weight: 700; color: var(--qc-shades-500); margin-bottom: 8px; }

        .btn-qc {
            background: var(--qc-primary-800);
            color: #fff;
            border: none;
            padding: 10px 22px;
            border-radius: 8px;
            font-weight: 700;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .btn-qc:hover { background: var(--qc-primary-600); color: #fff; transform: translateY(-2px); }

        /* Status badges for infra cards */
        .infra-status-approved {
            background: #d4edda;
            color: #155724;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .infra-status-in-progress {
            background: #cce5ff;
            color: #004085;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .infra-status-pending {
            background: #fff3cd;
            color: #856404;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .infra-status-cancelled {
            background: #f8d7da;
            color: #721c24;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .infra-status-completed {
            background: #d1ecf1;
            color: #0c5460;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .infra-status-rejected {
            background: #f8d7da;
            color: #721c24;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .infra-priority-high {
            background: rgba(220,53,69,0.1);
            color: #dc3545;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .infra-priority-medium {
            background: rgba(255,193,7,0.15);
            color: #b8860b;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .infra-priority-low {
            background: rgba(40,167,69,0.1);
            color: #28a745;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        /* Detail section CSS for modal content */
        .detail-section {
            margin-bottom: 20px;
        }
        .detail-section-title {
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--qc-primary-800);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .detail-section-title .icon-badge {
            width: 28px;
            height: 28px;
            background: var(--qc-icon-bg);
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            color: var(--qc-primary-700);
        }
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .detail-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--qc-shades-400);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .detail-value {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--qc-primary-900);
        }
        .detail-value-money {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--qc-primary-700);
        }
        .detail-link {
            font-size: 0.85rem;
            color: var(--qc-primary-600);
            text-decoration: none;
            font-weight: 500;
        }
        .detail-link:hover {
            color: var(--qc-primary-800);
            text-decoration: underline;
        }

        .milestone-timeline {
            position: relative;
            padding-left: 20px;
        }
        .milestone-timeline::before {
            content: '';
            position: absolute;
            left: 7px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--qc-shades-200);
        }
        .milestone-item {
            position: relative;
            padding-bottom: 16px;
            padding-left: 20px;
        }
        .milestone-item:last-child {
            padding-bottom: 0;
        }
        .milestone-dot {
            position: absolute;
            left: -13px;
            top: 2px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: var(--qc-shades-200);
            border: 2px solid #fff;
            z-index: 1;
        }
        .milestone-item.done .milestone-dot {
            background: #28a745;
        }
        .milestone-item.pending .milestone-dot {
            background: var(--qc-shades-300);
        }
        .milestone-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .milestone-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--qc-primary-900);
        }
        .milestone-date {
            font-size: 0.78rem;
            color: var(--qc-shades-400);
        }

        .detail-geo {
            margin-top: 10px;
        }
        .detail-geo-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: linear-gradient(135deg, var(--qc-primary-800), var(--qc-primary-600));
            color: #fff;
            padding: 8px 18px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .detail-geo-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(17, 82, 114, 0.3);
            color: #fff;
            text-decoration: none;
        }

        .detail-feedback-text {
            font-size: 0.88rem;
            color: var(--qc-shades-500);
            margin-bottom: 10px;
        }
        .detail-feedback-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .detail-feedback-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 18px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        .detail-feedback-danger {
            background: rgba(220,53,69,0.1);
            color: #dc3545;
            border: 1px solid rgba(220,53,69,0.25);
        }
        .detail-feedback-danger:hover {
            background: #dc3545;
            color: #fff;
        }
        .detail-feedback-primary {
            background: var(--qc-primary-800);
            color: #fff;
        }
        .detail-feedback-primary:hover {
            background: var(--qc-primary-600);
            color: #fff;
        }

        /* Infrastructure Modal (Premium) */
        .infra-modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .infra-modal-overlay.active {
            display: flex;
        }
        .infra-modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(14, 47, 67, 0.65);
            backdrop-filter: blur(6px);
            animation: infraModalFadeIn 0.3s ease;
        }
        .infra-modal-container {
            position: relative;
            background: #fff;
            border-radius: 16px;
            max-width: 640px;
            width: 100%;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 25px 60px rgba(14, 47, 67, 0.3);
            animation: infraModalScaleIn 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
            z-index: 1;
        }
        .infra-modal-header {
            background: linear-gradient(135deg, var(--qc-primary-800) 0%, var(--qc-primary-600) 100%);
            color: #fff;
            padding: 24px 28px 20px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
            border-radius: 16px 16px 0 0;
        }
        .infra-modal-icon {
            width: 44px;
            height: 44px;
            background: rgba(255,255,255,0.18);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .infra-modal-title {
            font-size: 1.15rem;
            font-weight: 700;
            margin-bottom: 4px;
            line-height: 1.3;
        }
        .infra-modal-subtitle {
            font-size: 0.85rem;
            opacity: 0.85;
        }
        .infra-modal-close {
            position: absolute;
            top: 18px;
            right: 18px;
            background: rgba(255,255,255,0.18);
            border: none;
            color: #fff;
            width: 34px;
            height: 34px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1rem;
            transition: background 0.2s;
        }
        .infra-modal-close:hover {
            background: rgba(255,255,255,0.3);
        }
        .infra-modal-body {
            padding: 24px 28px;
            overflow-y: auto;
            flex: 1;
        }
        .infra-modal-footer {
            padding: 16px 28px;
            border-top: 1px solid var(--qc-card-border);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .infra-modal-footer-btn {
            padding: 10px 22px;
            border-radius: 8px;
            font-size: 0.88rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            font-family: 'Montserrat', sans-serif;
        }
        .infra-modal-footer-btn.primary {
            background: var(--qc-primary-800);
            color: #fff;
        }
        .infra-modal-footer-btn.primary:hover {
            background: var(--qc-primary-600);
        }
        .infra-modal-footer-btn.secondary {
            background: var(--qc-shades-100);
            color: var(--qc-shades-500);
        }
        .infra-modal-footer-btn.secondary:hover {
            background: var(--qc-shades-200);
        }
        .infra-modal-source { display: none !important; }

        @keyframes infraModalFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes infraModalScaleIn {
            from { opacity: 0; transform: scale(0.92) translateY(10px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        @keyframes infraModalScaleOut {
            from { opacity: 1; transform: scale(1) translateY(0); }
            to { opacity: 0; transform: scale(0.92) translateY(10px); }
        }

        @media (max-width: 767.98px) {
            .infra-modal-container {
                max-width: 100%;
                max-height: 90vh;
                border-radius: 12px;
            }
            .infra-modal-header {
                border-radius: 12px 12px 0 0;
                padding: 20px 20px 16px;
            }
            .infra-modal-body {
                padding: 20px;
            }
            .infra-modal-footer {
                padding: 14px 20px;
            }
            .detail-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Footer */
        footer.qc-footer {
            background: linear-gradient(135deg, var(--qc-primary-800) 0%, #1d698b 100%);
            color: #fff;
            padding: 34px 0 20px;
        }
        footer.qc-footer a { color: #fff; text-decoration: none; }
        .footer-top-row { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 20px; }
        .footer-contact-row { display: flex; align-items: center; gap: 14px; font-size: 14px; }
        .footer-contact-item { display: inline-flex; align-items: center; gap: 8px; color: #fff; }
        .footer-contact-item:hover { color: #fff; }
        .footer-contact-item i { color: #fff; font-size: 15px; }
        .contact-separator { width: 1px; height: 18px; background: rgba(255,255,255,0.4); }
        .footer-links-row { display: flex; flex-wrap: wrap; gap: 22px; }
        .footer-links-row a { font-size: 12px; font-weight: 600; letter-spacing: 0.2px; }
        .footer-links-row a:hover { color: #eaf3f9; text-decoration: underline; }
        .footer-divider { height: 1px; background: rgba(255,255,255,0.34); margin: 22px 0 14px; }
        .footer-bottom-row { text-align: center; }
        .footer-copyright { font-size: 13px; color: rgba(244,248,251,0.85); margin: 0; }
        .footer-copyright i { margin-right: 4px; }

        @media (max-width: 768px) {
            .hero-bar { padding: 96px 0 64px; }
            .hero-bar h1 { font-size: 1.5rem; }
            .section-title { font-size: 1.4rem; }
            .footer-top-row { flex-direction: column; text-align: center; }
            .footer-contact-row { justify-content: center; flex-wrap: wrap; }
            .footer-links-row { justify-content: center; gap: 16px; }
            .infra-reports-header { padding: 18px 20px; }
            .infra-reports-search { padding: 12px 20px; }
            .infra-projects-grid { padding: 16px 20px 20px; }
        }

        /* Dark Mode */
        html.dark-mode .infra-reports-card {
            background: #1e2229;
            border-color: #2d3340;
        }
        html.dark-mode .infra-reports-header {
            background: linear-gradient(135deg, #0e2f43 0%, #143c5e 100%);
        }
        html.dark-mode .infra-reports-icon {
            background: rgba(255,255,255,0.12);
        }
        html.dark-mode .infra-reports-title {
            color: #e2e8f0;
        }
        html.dark-mode .infra-reports-badge {
            background: rgba(255,255,255,0.15);
        }
        html.dark-mode .infra-reports-subtitle {
            color: #94a3b8;
        }
        html.dark-mode .infra-reports-search {
            border-color: #2d3340;
        }
        html.dark-mode .infra-reports-search input {
            background: #252a33;
            border-color: #374151;
            color: #e2e8f0;
        }
        html.dark-mode .infra-reports-search input:focus {
            border-color: #62b2e7;
            box-shadow: 0 0 0 3px rgba(98, 178, 231, 0.15);
        }
        html.dark-mode .infra-reports-sort-btn {
            background: linear-gradient(135deg, #0e2f43, #143c5e);
        }
        html.dark-mode .infra-project-card {
            background: #252a33;
            border-color: #2d3340;
        }
        html.dark-mode .infra-project-header {
            border-color: #2d3340;
        }
        html.dark-mode .infra-project-header .type {
            color: #7dd3fc;
        }
        html.dark-mode .infra-project-header .title {
            color: #e2e8f0;
        }
        html.dark-mode .infra-project-header .report {
            color: #64748b;
        }
        html.dark-mode .infra-project-body .meta-label {
            color: #64748b;
        }
        html.dark-mode .infra-project-body .meta-value {
            color: #cbd5e1;
        }
        html.dark-mode .infra-project-body .meta-row i {
            color: #62b2e7;
        }
        html.dark-mode .infra-project-progress .progress {
            background: #374151;
        }
        html.dark-mode .infra-progress-head .label {
            color: #94a3b8;
        }
        html.dark-mode .infra-progress-head .pct {
            color: #7dd3fc;
        }
        html.dark-mode .infra-project-desc {
            color: #94a3b8;
        }
        html.dark-mode .infra-project-footer {
            border-color: #2d3340;
        }
        html.dark-mode .infra-project-footer .btn-qc {
            background: #15689b;
        }
        html.dark-mode .infra-project-footer .btn-qc:hover {
            background: #1381b6;
        }
        html.dark-mode .infra-project-footer .btn-outline-qc {
            border-color: #374151;
            color: #94a3b8;
        }
        html.dark-mode .infra-project-footer .btn-outline-qc:hover {
            background: #2d3340;
        }
        html.dark-mode .infra-status-approved { background: rgba(110, 231, 183, 0.12); color: #6ee7b7; }
        html.dark-mode .infra-status-in-progress { background: rgba(125, 211, 252, 0.12); color: #7dd3fc; }
        html.dark-mode .infra-status-pending { background: rgba(253, 230, 138, 0.12); color: #fde68a; }
        html.dark-mode .infra-status-cancelled { background: rgba(252, 165, 165, 0.12); color: #fca5a5; }
        html.dark-mode .infra-status-completed { background: rgba(110, 231, 183, 0.12); color: #6ee7b7; }
        html.dark-mode .infra-status-rejected { background: rgba(252, 165, 165, 0.12); color: #fca5a5; }
        html.dark-mode .infra-priority-high { background: rgba(252, 165, 165, 0.12); color: #fca5a5; }
        html.dark-mode .infra-priority-medium { background: rgba(253, 230, 138, 0.12); color: #fde68a; }
        html.dark-mode .infra-priority-low { background: rgba(110, 231, 183, 0.12); color: #6ee7b7; }
        html.dark-mode .infra-reports-empty i { color: #374151; }
        html.dark-mode .infra-reports-empty h5 { color: #94a3b8; }
        html.dark-mode .infra-reports-empty p { color: #64748b; }

        html.dark-mode .infra-scroll-more {
            color: var(--qc-primary-300);
            background: rgba(33,161,214,0.08);
            border-color: rgba(33,161,214,0.25);
        }
        html.dark-mode .infra-scroll-more:hover {
            background: rgba(33,161,214,0.15);
            border-color: var(--qc-primary-500);
        }
        html.dark-mode .infra-projects-grid::-webkit-scrollbar-thumb { background: #475569; }
        html.dark-mode .infra-grid-scroll::-webkit-scrollbar-thumb { background: #475569; }
        html.dark-mode .detail-section-title { color: #7dd3fc; }
        html.dark-mode .detail-section-title .icon-badge { background: rgba(125, 211, 252, 0.12); color: #7dd3fc; }
        html.dark-mode .detail-value { color: #e2e8f0; }
        html.dark-mode .detail-label { color: #64748b; }
        html.dark-mode .detail-value-money { color: #6ee7b7; }
        html.dark-mode .milestone-label { color: #e2e8f0; }
        html.dark-mode .milestone-date { color: #64748b; }
        html.dark-mode .milestone-timeline::before { background: #374151; }
        html.dark-mode .infra-modal-overlay .infra-modal-container {
            background: #1e2229;
        }
        html.dark-mode .infra-modal-body { color: #cbd5e1; }
        html.dark-mode .infra-modal-footer { border-color: #2d3340; }
        html.dark-mode .infra-modal-footer-btn.secondary {
            background: #2d3340;
            color: #94a3b8;
        }
        html.dark-mode .infra-modal-footer-btn.secondary:hover {
            background: #374151;
        }
        html.dark-mode .stat-sub { color: #64748b; }
    </style>
    <?php include __DIR__ . '/includes/a11y_css.php'; ?>
    <?php include __DIR__ . '/includes/hamburger_menu_css.php'; ?>
</head>
<body>
    <nav class="navbar navbar-light fixed-top qc-navbar">
        <div class="container-fluid">
            <a class="navbar-brand qc-brand" href="index.php">
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

    <div class="hero-bar">
        <div class="container">
            <h1><i class="fas fa-hard-hat"></i> Infrastructure Projects</h1>
            <p>Transparent view of all road infrastructure projects and their progress</p>
        </div>
    </div>

    <section class="section">
        <div class="container">
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-project-diagram"></i></div>
                    <div class="stat-number"><?php echo number_format($stats['total']); ?></div>
                    <div class="stat-label">Total Projects</div>
                    <div class="stat-sub">Active: <?php echo number_format($stats['active']); ?> &middot; Completed: <?php echo number_format($stats['completed']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-spinner"></i></div>
                    <div class="stat-number"><?php echo number_format($stats['active']); ?></div>
                    <div class="stat-label">Active</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-number"><?php echo number_format($stats['completed']); ?></div>
                    <div class="stat-label">Completed</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="stat-number"><?php echo number_format($stats['delayed']); ?></div>
                    <div class="stat-label">Delayed</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="stat-number"><?php echo number_format($stats['pending']); ?></div>
                    <div class="stat-label">Pending</div>
                </div>
            </div>

            <h2 class="section-title">All Projects</h2>
            <p class="section-subtitle">Browse our current and upcoming infrastructure projects across Quezon City</p>

            <?php if (!empty($projects)): ?>
            <div class="projects-grid">
                <?php foreach ($projects as $proj): ?>
                <div class="project-card">
                    <div class="project-info">
                        <h4><?php echo htmlspecialchars($proj['name']); ?></h4>
                        <div class="project-meta">
                            <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($proj['location'] ?: '—'); ?></span>
                            <span><i class="fas fa-calendar"></i> <?php echo safe_date_fmt($proj['start_date']); ?> — <?php echo safe_date_fmt($proj['end_date']); ?></span>
                        </div>
                        <div class="project-meta">
                            <?php if (!empty($proj['budget'])): ?>
                            <span><i class="fas fa-peso-sign"></i> ₱<?php echo number_format((float)$proj['budget'], 2); ?></span>
                            <?php endif; ?>
                            <span><?php echo infra_status_badge($proj['status']); ?></span>
                        </div>
                        <div class="infra-project-progress" style="margin-top:14px;">
                            <div class="infra-progress-head">
                                <span class="label">Progress</span>
                                <span class="pct"><?php echo (int)$proj['progress']; ?>%</span>
                            </div>
                            <div class="progress">
                                <div class="progress-bar" style="width: <?php echo (int)$proj['progress']; ?>%; background: <?php echo infra_progress_color((int)$proj['progress']); ?>;"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>

            <div id="infraReportsPanel" class="infra-reports-card">
                <div class="infra-reports-header">
                    <div class="infra-reports-icon">
                        <i class="fas fa-hard-hat"></i>
                    </div>
                    <div>
                        <div class="infra-reports-title">Infrastructure Projects</div>
                        <span class="infra-reports-badge"><?php echo count($infra_reports); ?> verified projects</span>
                    </div>
                    <div class="infra-reports-subtitle">Approved and in-progress infrastructure projects sourced from verified department records</div>
                </div>

                <div class="infra-reports-search">
                    <input type="text" id="infraSearchInput" placeholder="Search by name, location, or department..." oninput="panelInfraSearch()">
                    <button class="infra-reports-sort-btn" onclick="toggleInfraReportsSort()">
                        <i class="fas fa-sort"></i> Sort by Date
                    </button>
                </div>

                <div class="infra-grid-scroll" id="infraGridScroll">
                <div class="infra-projects-grid" id="infraProjectsGrid">
                    <?php if (!empty($infra_reports)): ?>
                        <?php foreach ($infra_reports as $idx => $ir):
                            $norm_status = strtolower(str_replace(' ', '-', $ir['status'] ?? ''));
                            $slabel = ucfirst(str_replace('-', ' ', $norm_status));
                            $plabel = ucfirst($ir['priority'] ?? 'medium');
                            $created = $ir['created_at'] ?? $ir['created_date'] ?? '';
                            $est_val = !empty($ir['estimation']) ? (float)$ir['estimation'] : 0;
                            $prog = isset($ir['_ipms_progress']) ? (int)$ir['_ipms_progress'] : 0;
                            $report_id = htmlspecialchars($ir['report_id'] ?? '');
                            $title = htmlspecialchars($ir['title'] ?? 'Untitled');
                            $desc = htmlspecialchars($ir['description'] ?? '');
                            $loc = htmlspecialchars($ir['location'] ?? '—');
                            $dept = htmlspecialchars($ir['department'] ?? '—');
                            $assigned = htmlspecialchars($ir['assigned_to'] ?? '—');
                            $sdate = safe_date_fmt($ir['_ipms_start_date'] ?? $ir['created_at'] ?? '');
                            $edate = safe_date_fmt($ir['_ipms_end_date'] ?? '');
                            $lat = htmlspecialchars($ir['latitude'] ?? '');
                            $lng = htmlspecialchars($ir['longitude'] ?? '');
                            $src = htmlspecialchars($ir['source_system'] ?? 'department');
                            $rtype = htmlspecialchars($ir['report_type'] ?? '');

                            $modal_html = '<div class="detail-section"><div class="detail-section-title"><span class="icon-badge"><i class="fas fa-info-circle"></i></span> Project Details</div>';
                            $modal_html .= '<div class="detail-grid">';
                            $modal_html .= '<div class="detail-item"><div class="detail-label">Report ID</div><div class="detail-value">' . $report_id . '</div></div>';
                            $modal_html .= '<div class="detail-item"><div class="detail-label">Status</div><div class="detail-value"><span class="infra-status-' . $norm_status . '">' . $slabel . '</span></div></div>';
                            $modal_html .= '<div class="detail-item"><div class="detail-label">Priority</div><div class="detail-value"><span class="infra-priority-' . strtolower($plabel) . '">' . $plabel . '</span></div></div>';
                            $modal_html .= '<div class="detail-item"><div class="detail-label">Department</div><div class="detail-value">' . $dept . '</div></div>';
                            $modal_html .= '<div class="detail-item"><div class="detail-label">Location</div><div class="detail-value">' . $loc . '</div></div>';
                            $modal_html .= '<div class="detail-item"><div class="detail-label">Assigned To</div><div class="detail-value">' . $assigned . '</div></div>';
                            if ($est_val > 0) {
                                $modal_html .= '<div class="detail-item"><div class="detail-label">Estimation / Budget</div><div class="detail-value detail-value-money">₱' . number_format($est_val, 2) . '</div></div>';
                            }
                            $modal_html .= '<div class="detail-item"><div class="detail-label">Created</div><div class="detail-value">' . safe_date_fmt($created) . '</div></div>';
                            $modal_html .= '</div></div>';

                            if ($prog > 0 || ($sdate !== '—') || ($edate !== '—')) {
                                $modal_html .= '<div class="detail-section"><div class="detail-section-title"><span class="icon-badge"><i class="fas fa-tasks"></i></span> Timeline &amp; Progress</div>';
                                if ($prog > 0) {
                                    $modal_html .= '<div class="infra-project-progress" style="margin-bottom:14px;"><div class="infra-progress-head"><span class="label">Progress</span><span class="pct">' . $prog . '%</span></div><div class="progress"><div class="progress-bar" style="width:' . $prog . '%;background:' . infra_progress_color($prog) . ';"></div></div></div>';
                                }
                                $modal_html .= '<div class="milestone-timeline">';
                                if ($sdate !== '—') $modal_html .= '<div class="milestone-item done"><div class="milestone-dot"></div><div class="milestone-info"><div class="milestone-label">Project Started</div><div class="milestone-date">' . $sdate . '</div></div></div>';
                                if ($prog > 0 && $prog < 100) $modal_html .= '<div class="milestone-item done"><div class="milestone-dot"></div><div class="milestone-info"><div class="milestone-label">In Progress (' . $prog . '%)</div><div class="milestone-date">Ongoing</div></div></div>';
                                if ($prog >= 100) $modal_html .= '<div class="milestone-item done"><div class="milestone-dot"></div><div class="milestone-info"><div class="milestone-label">Completed</div><div class="milestone-date">' . ($edate !== '—' ? $edate : '—') . '</div></div>';
                                else $modal_html .= '<div class="milestone-item pending"><div class="milestone-dot"></div><div class="milestone-info"><div class="milestone-label">Target Completion</div><div class="milestone-date">' . $edate . '</div></div></div>';
                                $modal_html .= '</div></div>';
                            }

                            if ($lat && $lng) {
                                $modal_html .= '<div class="detail-section"><div class="detail-section-title"><span class="icon-badge"><i class="fas fa-map-marked-alt"></i></span> Location</div><div class="detail-geo"><a href="https://www.google.com/maps?q=' . $lat . ',' . $lng . '" target="_blank" rel="noopener" class="detail-geo-btn"><i class="fas fa-external-link-alt"></i> View on Google Maps</a></div></div>';
                            }

                            $modal_html .= '<div class="detail-section"><div class="detail-section-title"><span class="icon-badge"><i class="fas fa-flag"></i></span> Additional Information</div><div style="font-size:0.88rem;color:var(--qc-shades-500);line-height:1.6;">' . ($desc ?: 'No additional description available.') . '</div></div>';

                            $modal_html .= '<div class="detail-section"><div class="detail-section-title"><span class="icon-badge"><i class="fas fa-bullhorn"></i></span> Feedback</div><div class="detail-feedback-text">Have concerns about this project?</div><div class="detail-feedback-actions"><a href="contact.php?ref=' . urlencode($report_id) . '" class="detail-feedback-btn detail-feedback-primary"><i class="fas fa-envelope"></i> Contact Us</a><a href="contact.php?ref=' . urlencode($report_id) . '&type=report" class="detail-feedback-btn detail-feedback-danger"><i class="fas fa-flag"></i> Report Issue</a></div></div>';
                        ?>
                        <div class="infra-project-card" data-title="<?php echo strtolower($title); ?>" data-location="<?php echo strtolower($loc); ?>" data-department="<?php echo strtolower($dept); ?>" data-created="<?php echo strtotime($created ?? '') ?: 0; ?>">
                            <div class="infra-project-header">
                                <div class="title-wrap">
                                    <div class="type"><?php echo $src === 'ipms' ? 'IPMS Project' : 'Department Record'; ?></div>
                                    <div class="title"><?php echo $title; ?></div>
                                    <div class="report"><?php echo $report_id; ?></div>
                                </div>
                                <div class="badges">
                                    <span class="infra-status-<?php echo $norm_status; ?>"><?php echo $slabel; ?></span>
                                    <span class="infra-priority-<?php echo strtolower($plabel); ?>"><?php echo $plabel; ?></span>
                                </div>
                            </div>
                            <div class="infra-project-body">
                                <div class="meta">
                                    <div class="meta-row"><i class="fas fa-map-marker-alt"></i><span class="meta-label">Location:</span> <span class="meta-value"><?php echo $loc; ?></span></div>
                                    <div class="meta-row"><i class="fas fa-building"></i><span class="meta-label">Department:</span> <span class="meta-value"><?php echo $dept; ?></span></div>
                                    <?php if ($assigned !== '—'): ?>
                                    <div class="meta-row"><i class="fas fa-user"></i><span class="meta-label">Assigned:</span> <span class="meta-value"><?php echo $assigned; ?></span></div>
                                    <?php endif; ?>
                                    <?php if ($est_val > 0): ?>
                                    <div class="meta-row"><i class="fas fa-peso-sign"></i><span class="meta-label">Budget:</span> <span class="meta-value">₱<?php echo number_format($est_val, 2); ?></span></div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($prog > 0): ?>
                                <div class="infra-project-progress">
                                    <div class="infra-progress-head">
                                        <span class="label">Progress</span>
                                        <span class="pct"><?php echo $prog; ?>%</span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar" style="width: <?php echo $prog; ?>%; background: <?php echo infra_progress_color($prog); ?>;"></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="infra-project-footer">
                                <button class="btn btn-qc" onclick="openInfraModal(this)"><i class="fas fa-eye"></i> View Details</button>
                                <?php if ($lat && $lng): ?>
                                <a href="https://www.google.com/maps?q=<?php echo $lat . ',' . $lng; ?>" target="_blank" rel="noopener" class="btn btn-outline-qc"><i class="fas fa-map-marker-alt"></i> View on Map</a>
                                <?php endif; ?>
                            </div>
                            <div class="infra-modal-source"><?php echo $modal_html; ?></div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                </div>
                <?php if (count($infra_reports) > 2): ?>
                <button type="button" class="infra-scroll-more" id="infraScrollMoreBtn" onclick="toggleInfraGridExpand()">
                    <span>View All Projects</span>
                    <i class="fas fa-chevron-down"></i>
                </button>
                <?php endif; ?>

                <?php if (empty($infra_reports)): ?>
                <div class="infra-reports-empty" id="infraEmptyState">
                    <i class="fas fa-hard-hat"></i>
                    <h5>No Verified Infrastructure Projects</h5>
                    <p>There are currently no verified infrastructure projects to display. Projects will appear here once approved by the department.</p>
                </div>
                <div class="infra-reports-empty" id="infraNoResults" style="display:none;">
                    <i class="fas fa-search"></i>
                    <h5>No Matching Projects</h5>
                    <p>No projects match your search criteria. Try adjusting your search terms.</p>
                </div>
                <?php else: ?>
                <div class="infra-reports-empty" id="infraNoResults" style="display:none;">
                    <i class="fas fa-search"></i>
                    <h5>No Matching Projects</h5>
                    <p>No projects match your search criteria. Try adjusting your search terms.</p>
                </div>
                <?php endif; ?>
            </div>

            <?php endif; ?>
        </div>
    </section>

    <div id="infraModalOverlay" class="infra-modal-overlay">
        <div class="infra-modal-backdrop" onclick="closeInfraModal()"></div>
        <div class="infra-modal-container">
            <div class="infra-modal-header">
                <div class="infra-modal-icon"><i class="fas fa-hard-hat"></i></div>
                <div>
                    <div class="infra-modal-title" id="infraModalTitle">Project Details</div>
                    <div class="infra-modal-subtitle" id="infraModalSubtitle">Verified infrastructure project information</div>
                </div>
                <button class="infra-modal-close" onclick="closeInfraModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="infra-modal-body" id="infraModalBody"></div>
            <div class="infra-modal-footer">
                <button class="infra-modal-footer-btn secondary" onclick="closeInfraModal()">Close</button>
            </div>
        </div>
    </div>

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
                <a href="index.php">Home</a>
                <a href="road-updates.php">Road Updates</a>
                <a href="public_reports.php">Road Status</a>
                <a href="about.php">About</a>
                <a href="contact.php">Contact</a>
                <a href="public_transparency_view.php">Transparency</a>
            </div>
            <div class="footer-divider"></div>
            <div class="footer-bottom-row">
                <p class="footer-copyright"><i class="fas fa-copyright"></i> 2026 Road and Transportation Department. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/includes/a11y_html.php'; ?>

    <script src="lgu_staff/js/page-transition.js"></script>
    <?php include __DIR__ . '/includes/a11y_js.php'; ?>
    <?php include __DIR__ . '/includes/hamburger_menu_js.php'; ?>
    <script>
    (function() {
        var infraSortAsc = false;

        function panelInfraSearch() {
            var query = document.getElementById('infraSearchInput').value.toLowerCase().trim();
            var grid = document.getElementById('infraProjectsGrid');
            if (!grid) return;
            var cards = grid.querySelectorAll('.infra-project-card');
            var visible = 0;
            cards.forEach(function(card) {
                var title = card.getAttribute('data-title') || '';
                var loc = card.getAttribute('data-location') || '';
                var dept = card.getAttribute('data-department') || '';
                var match = !query || title.indexOf(query) !== -1 || loc.indexOf(query) !== -1 || dept.indexOf(query) !== -1;
                card.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            var emptyEl = document.getElementById('infraEmptyState');
            var noRes = document.getElementById('infraNoResults');
            if (noRes) noRes.style.display = (query && visible === 0) ? '' : 'none';
            if (emptyEl) emptyEl.style.display = (!query && visible === 0) ? '' : 'none';
        }

        function cellCompare(a, b, key, asc) {
            var va = parseInt(a.getAttribute('data-' + key) || '0', 10);
            var vb = parseInt(b.getAttribute('data-' + key) || '0', 10);
            return asc ? va - vb : vb - va;
        }

        function toggleInfraReportsSort() {
            infraSortAsc = !infraSortAsc;
            var grid = document.getElementById('infraProjectsGrid');
            if (!grid) return;
            var cards = Array.from(grid.querySelectorAll('.infra-project-card'));
            cards.sort(function(a, b) { return cellCompare(a, b, 'created', infraSortAsc); });
            cards.forEach(function(card) { grid.appendChild(card); });
        }

        function openInfraModal(btn) {
            var card = btn.closest('.infra-project-card');
            if (!card) return;
            var source = card.querySelector('.infra-modal-source');
            if (!source) return;
            var title = card.querySelector('.title');
            var report = card.querySelector('.report');
            var overlay = document.getElementById('infraModalOverlay');
            var modalBody = document.getElementById('infraModalBody');
            var modalTitle = document.getElementById('infraModalTitle');
            var modalSub = document.getElementById('infraModalSubtitle');
            if (title) modalTitle.textContent = title.textContent;
            if (report) modalSub.textContent = report.textContent;
            modalBody.innerHTML = source.innerHTML;
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeInfraModal() {
            var overlay = document.getElementById('infraModalOverlay');
            var container = overlay.querySelector('.infra-modal-container');
            container.style.animation = 'infraModalScaleOut 0.25s ease forwards';
            setTimeout(function() {
                overlay.classList.remove('active');
                container.style.animation = '';
                document.getElementById('infraModalBody').innerHTML = '';
                document.body.style.overflow = '';
            }, 250);
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                var overlay = document.getElementById('infraModalOverlay');
                if (overlay && overlay.classList.contains('active')) closeInfraModal();
            }
        });

        var searchInput = document.getElementById('infraSearchInput');
        if (searchInput) searchInput.addEventListener('input', panelInfraSearch);

        function toggleInfraGridExpand() {
            var scroll = document.getElementById('infraGridScroll');
            var btn = document.getElementById('infraScrollMoreBtn');
            if (!scroll || !btn) return;
            var expanded = scroll.classList.toggle('expanded');
            btn.classList.toggle('expanded', expanded);
            btn.querySelector('span').textContent = expanded ? 'Show Less' : 'View All Projects';
        }

        window.panelInfraSearch = panelInfraSearch;
        window.toggleInfraReportsSort = toggleInfraReportsSort;
        window.toggleInfraGridExpand = toggleInfraGridExpand;
        window.openInfraModal = openInfraModal;
        window.closeInfraModal = closeInfraModal;
    })();
    </script>
</body>
</html>
