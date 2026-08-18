<?php
require_once 'lgu_staff/includes/config.php';
require_once 'lgu_staff/includes/functions.php';

$status_filter = isset($_GET['status']) ? sanitize_input($_GET['status']) : 'all';
$type_filter = isset($_GET['type']) ? sanitize_input($_GET['type']) : 'all';
$focus_report_id = isset($_GET['report_id']) ? intval($_GET['report_id']) : 0;

$all_reports = [];
$stats = ['total_reports' => 0, 'problem_roads' => 0, 'under_construction' => 0, 'resolved_issues' => 0];

if ($conn) {
    try {
        $cimm_status_sql = "CASE
            WHEN resolution_status = 'Completed' THEN 'completed'
            WHEN resolution_status IN ('In Progress', 'Pending Completion') THEN 'in-progress'
            WHEN resolution_status = 'Cancelled' THEN 'cancelled'
            WHEN resolution_status = 'Rejected' THEN 'cancelled'
            WHEN resolution_status IN ('Scheduled', 'Approved') THEN 'pending'
            WHEN approval_status = 'Rejected' THEN 'cancelled'
            ELSE 'pending'
        END";

        // 1. Transportation Reports (citizen + lgu) — source is computed via CASE
        if ($type_filter === 'all' || $type_filter === 'citizen') {
            $t_conditions = [];
            $t_params   = [];
            $t_types    = '';

            if ($status_filter !== 'all') {
                $t_conditions[] = "status = ?";
                $t_params[]     = $status_filter;
                $t_types       .= "s";
            }
            if ($type_filter === 'citizen') {
                $t_conditions[] = "(created_by IS NULL OR created_by = 0)";
            }
            $t_where = !empty($t_conditions) ? " WHERE " . implode(' AND ', $t_conditions) : '';

            $t_query = "SELECT id, report_id, title, description, location, latitude, longitude,
                    priority, status, severity, image_path, attachments, reporter_name,
                    reported_date, created_at, department,
                    CASE WHEN created_by IS NULL OR created_by = 0 THEN 'citizen' ELSE 'lgu' END AS source
                FROM road_transportation_reports" . $t_where . " ORDER BY created_at DESC LIMIT 50";

            $transport = !empty($t_params) ? fetch_all($t_query, $t_params, $t_types) : fetch_all($t_query);
            $all_reports = array_merge($all_reports, $transport ?: []);
        }

        // 2. Maintenance Reports — only when viewing all types
        if ($type_filter === 'all') {
            $m_conditions = [];
            $m_params   = [];
            $m_types    = '';
            if ($status_filter !== 'all') {
                $m_conditions[] = "status = ?";
                $m_params[]     = $status_filter;
                $m_types       .= "s";
            }
            $m_where = !empty($m_conditions) ? " WHERE " . implode(' AND ', $m_conditions) : '';

            $m_query = "SELECT id, report_id, title, description, location, priority, status,
                    created_at, department, 'maintenance' AS source
                FROM road_maintenance_reports" . $m_where . " ORDER BY created_at DESC LIMIT 50";

            $maintenance = !empty($m_params) ? fetch_all($m_query, $m_params, $m_types) : fetch_all($m_query);
            $all_reports = array_merge($all_reports, $maintenance ?: []);
        }

        // 3. Infrastructure Projects (ipms_road_projects)
        if ($type_filter === 'all' || $type_filter === 'infrastructure') {
            $has_ipms = fetch_one("SHOW TABLES LIKE 'ipms_road_projects'");
            if ($has_ipms) {
                $i_query = "SELECT project_id AS id,
                        CAST(project_id AS CHAR) AS report_id,
                        project_name AS title,
                        COALESCE(NULLIF(road_status, ''), 'No description') AS description,
                        COALESCE(NULLIF(road_name, ''), project_name) AS location,
                        start_lat AS latitude, start_lng AS longitude,
                        priority, budget,
                        CASE
                            WHEN LOWER(road_status) LIKE '%completed%' THEN 'completed'
                            WHEN LOWER(road_status) LIKE '%progress%' THEN 'in-progress'
                            ELSE 'pending'
                        END AS status,
                        created_at, 'infrastructure' AS source
                    FROM ipms_road_projects
                    WHERE status = 'approved'
                    ORDER BY created_at DESC LIMIT 50";
                $infra = fetch_all($i_query);
                $all_reports = array_merge($all_reports, $infra ?: []);
            }
        }

        // 4. CIMM Reports (cimm_verification_reports)
        if ($type_filter === 'all' || $type_filter === 'cimm') {
            $has_cimm = fetch_one("SHOW TABLES LIKE 'cimm_verification_reports'");
            if ($has_cimm) {
                $c_query = "SELECT id, reference_code AS report_id, infrastructure AS title,
                        issue AS description, location,
                        coord_lat AS latitude, coord_lng AS longitude,
                        priority, {$cimm_status_sql} AS status,
                        COALESCE(submitted_at, verified_at, synced_at, NOW()) AS created_at,
                        'cimm' AS source
                    FROM cimm_verification_reports
                    WHERE infrastructure = 'Roads'
                    ORDER BY created_at DESC LIMIT 50";
                $cimm = fetch_all($c_query);
                $all_reports = array_merge($all_reports, $cimm ?: []);
            }
        }

        // Apply status filter for infrastructure & CIMM (transport/maintenance already filtered in SQL)
        if ($status_filter !== 'all') {
            $all_reports = array_values(array_filter($all_reports, function($r) use ($status_filter) {
                return ($r['status'] ?? '') === $status_filter;
            }));
        }

        // Stats (always from the main tables, unaffected by type filter)
        $stats['total_reports']       = fetch_one("SELECT COUNT(*) as c FROM road_transportation_reports")['c'] + fetch_one("SELECT COUNT(*) as c FROM road_maintenance_reports")['c'];
        $stats['problem_roads']       = fetch_one("SELECT COUNT(*) as c FROM road_transportation_reports WHERE status IN ('pending','in-progress') AND priority IN ('high','critical')")['c'] ?? 0;
        $stats['under_construction']  = fetch_one("SELECT COUNT(*) as c FROM road_transportation_reports WHERE status = 'in-progress'")['c'] ?? 0;
        $stats['resolved_issues']     = fetch_one("SELECT COUNT(*) as c FROM road_transportation_reports WHERE status = 'completed'")['c'] ?? 0;
    } catch (Exception $e) {
        error_log("Public reports error: " . $e->getMessage());
    }
}

usort($all_reports, function($a, $b) {
    return strtotime($b['created_at'] ?? 'now') - strtotime($a['created_at'] ?? 'now');
});

function getReportPhoto($report) {
    $raw = null;
    if (!empty($report['image_path'])) {
        $raw = $report['image_path'];
    } elseif (!empty($report['attachments'])) {
        $atts = json_decode($report['attachments'], true);
        if (is_array($atts)) {
            foreach ($atts as $att) {
                $path = $att['file_path'] ?? $att['file'] ?? '';
                if ($path) { $raw = $path; break; }
            }
        }
    }
    return road_updates_resolve_image_url($raw, '');
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

function getStatusBadge($status) {
    $map = ['pending' => 'warning', 'in-progress' => 'info', 'completed' => 'success', 'cancelled' => 'secondary', 'approved' => 'success', 'rejected' => 'danger'];
    $class = $map[$status] ?? 'secondary';
    return "<span class=\"badge bg-{$class}\">" . ucfirst(str_replace('-', ' ', $status)) . "</span>";
}

function getPriorityBadge($priority) {
    $map = ['high' => 'danger', 'critical' => 'danger', 'medium' => 'warning', 'low' => 'success'];
    $class = $map[$priority] ?? 'secondary';
    return "<span class=\"badge bg-{$class}\">" . ucfirst($priority) . "</span>";
}

function getSeverityIcon($status) {
    if (in_array($status, ['in-progress', 'pending'])) {
        return '<i class="fas fa-exclamation-triangle text-danger" title="Problem Road"></i>';
    }
    if ($status === 'completed') {
        return '<i class="fas fa-check-circle text-success" title="Resolved"></i>';
    }
    return '<i class="fas fa-minus-circle text-secondary" title="' . ucfirst($status) . '"></i>';
}

function getTimeAgoShort($datetime) {
    if (!$datetime) return '';
    $diff = time() - strtotime($datetime);
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return date('M d', strtotime($datetime));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/includes/a11y_head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Road Reports - Quezon City</title>
    <link rel="icon" type="image/png" href="assets/img/logocityhall.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="lgu_staff/css/progress-updates.css">
    <link rel="stylesheet" href="styles/transition.css">
    <style>
        :root {
            --primary: #115272;
            --primary-light: #1d698b;
            --accent: #d93939;
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
        body { font-family: 'Montserrat', sans-serif; color: #3e454c; }

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

        .stats-ribbon { background: white; border-bottom: 1px solid var(--qc-shades-100); padding: 16px 0; }
        .stat-chip { text-align: center; }
        .stat-chip .num { font-size: 1.6rem; font-weight: 800; color: var(--qc-primary-800); }
        .stat-chip .lbl { font-size: 0.8rem; color: var(--qc-shades-500); font-weight: 500; }

        .filters-bar { background: white; border: 1px solid var(--qc-card-border); border-radius: 12px; padding: 16px 20px; box-shadow: 0 2px 8px rgba(17,82,114,0.06); margin-bottom: 20px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .filters-bar select { padding: 8px 12px; border: 1px solid var(--qc-shades-200); border-radius: 8px; font-size: 0.85rem; min-width: 150px; font-family: inherit; }
        .filters-bar .result-count { font-size: 0.85rem; color: var(--qc-shades-500); margin-left: auto; }

        .report-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 18px; }
        
        .report-card {
            background: white; border-radius: 12px; overflow: hidden;
            box-shadow: none; transition: all 0.2s ease;
            border: 1px solid var(--qc-card-border);
        }
        .report-card:hover { transform: translateY(-4px); box-shadow: 0 10px 24px rgba(17, 82, 114, 0.1); }

        .report-img {
            width: 100%; height: 180px; object-fit: cover;
            background: var(--qc-shades-100); display: flex; align-items: center; justify-content: center;
            color: var(--qc-shades-300); font-size: 2.5rem;
        }
        .report-img-placeholder {
            width: 100%; height: 180px; background: linear-gradient(135deg, #eef1f3, #d6dce1);
            display: flex; align-items: center; justify-content: center; color: var(--qc-shades-300); font-size: 2.5rem;
        }

        .report-body { padding: 16px 18px 18px; }
        .report-meta { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; }
        .report-title { font-size: 1rem; font-weight: 700; color: var(--qc-primary-900); margin-bottom: 6px; line-height: 1.3; }
        .report-desc { font-size: 0.85rem; color: var(--qc-shades-500); margin-bottom: 10px; line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .report-location { font-size: 0.8rem; color: var(--qc-shades-500); margin-bottom: 10px; }
        .report-location i { margin-right: 4px; color: #dc3545; }
        .report-footer { display: flex; justify-content: space-between; align-items: center; padding-top: 10px; border-top: 1px solid #eef1f3; font-size: 0.8rem; color: var(--qc-shades-500); }
        .report-source { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--qc-shades-300); }

        .road-marker { display: inline-flex; align-items: center; gap: 4px; font-size: 0.75rem; font-weight: 600; padding: 2px 8px; border-radius: 12px; }
        .road-marker.problem { background: rgba(220,53,69,0.12); color: #dc3545; }
        .road-marker.construction { background: rgba(255,193,7,0.15); color: #d97706; }
        .road-marker.resolved { background: rgba(40,167,69,0.12); color: #28a745; }

        .back-home { display: inline-flex; align-items: center; gap: 6px; color: rgba(255,255,255,0.8); text-decoration: none; font-size: 0.85rem; font-weight: 500; }
        .back-home:hover { color: white; }

        .detail-modal .modal-header { background: var(--qc-primary-800); color: white; border: none; }
        .detail-modal .modal-header .btn-close { filter: brightness(0) invert(1); }
        .detail-modal .modal-body { padding: 24px; }
        .detail-modal .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eef1f3; font-size: 0.9rem; }
        .detail-modal .info-row .label { color: var(--qc-shades-500); font-weight: 500; }
        .detail-modal .info-row .value { font-weight: 600; color: var(--qc-primary-900); }

        .no-reports { text-align: center; padding: 60px 20px; color: var(--qc-shades-500); }
        .no-reports i { font-size: 3.5rem; margin-bottom: 16px; opacity: 0.3; }

        /* Footer — QC E-Services style */
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
            .report-grid { grid-template-columns: 1fr; }
            .hero-bar { padding: 96px 0 64px; }
            .hero-bar h1 { font-size: 1.5rem; }
            .footer-top-row { flex-direction: column; text-align: center; }
            .footer-contact-row { justify-content: center; flex-wrap: wrap; }
            .footer-links-row { justify-content: center; gap: 16px; }
        }

        /* Dark mode is controlled by the accessibility panel (html.dark-mode),
           matching the rest of the public pages. Default is light mode. */
        html.dark-mode body { background: #1a1d23; color: #e4e6ea; }
        html.dark-mode .stats-ribbon { background: #22262e; border-color: #2d323b; }
        html.dark-mode .stat-chip .num { color: #93c5fd; }
        html.dark-mode .stat-chip .lbl { color: #9ca3af; }
        html.dark-mode .filters-bar { background: #22262e; box-shadow: 0 2px 8px rgba(0,0,0,0.3); }
        html.dark-mode .filters-bar select { background: #1a1d23; color: #e4e6ea; border-color: #2d323b; }
        html.dark-mode .filters-bar .result-count { color: #9ca3af; }
        html.dark-mode .filters-bar label { color: #9ca3af !important; }
        html.dark-mode .report-card { background: #22262e; border-color: #2d323b; box-shadow: 0 2px 12px rgba(0,0,0,0.2); }
        html.dark-mode .report-card:hover { box-shadow: 0 8px 28px rgba(0,0,0,0.4); }
        html.dark-mode .report-title { color: #e4e6ea; }
        html.dark-mode .report-desc { color: #9ca3af; }
        html.dark-mode .report-location { color: #9ca3af; }
        html.dark-mode .report-location i { color: #fca5a5; }
        html.dark-mode .report-footer { border-top-color: #2d323b; color: #9ca3af; }
        html.dark-mode .report-source { color: #6b7280; }
        html.dark-mode .report-img { background: #2d323b; color: #6b7280; }
        html.dark-mode .report-img-placeholder { background: linear-gradient(135deg, #2d323b, #374151); color: #6b7280; }
        html.dark-mode .road-marker.problem { background: rgba(252,165,165,0.15); color: #fca5a5; }
        html.dark-mode .road-marker.construction { background: rgba(251,191,36,0.15); color: #fbbf24; }
        html.dark-mode .road-marker.resolved { background: rgba(52,211,153,0.15); color: #34d399; }
        html.dark-mode .detail-modal .modal-content { background: #22262e; }
        html.dark-mode .detail-modal .modal-body { background: #22262e; }
        html.dark-mode .detail-modal .info-row { border-bottom-color: #2d323b; }
        html.dark-mode .detail-modal .info-row .label { color: #9ca3af; }
        html.dark-mode .detail-modal .info-row .value { color: #e4e6ea; }
        html.dark-mode #modalDescription { color: #d1d5db !important; }
        html.dark-mode .no-reports { color: #9ca3af; }
        html.dark-mode .modal-footer { background: #1e2229; border-top-color: #2d323b; }
    </style>
    <?php include __DIR__ . '/includes/a11y_css.php'; ?>
    <?php include __DIR__ . '/includes/hamburger_menu_css.php'; ?>
</head>
<body>
    <nav class="navbar navbar-light fixed-top qc-navbar">
        <div class="container-fluid">
            <a class="navbar-brand qc-brand" href="index.php">
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

    <div class="hero-bar">
        <div class="container">
            <h1><i class="fas fa-map-marked-alt"></i> Road Status & Public Reports</h1>
            <p>Transparent view of all road issues, construction projects, and completed repairs</p>
        </div>
    </div>

    <div class="stats-ribbon">
        <div class="container">
            <div class="row text-center">
                <div class="col-3 col-md-3 stat-chip">
                    <div class="num"><?php echo number_format($stats['total_reports']); ?></div>
                    <div class="lbl">Total Reports</div>
                </div>
                <div class="col-3 col-md-3 stat-chip">
                    <div class="num" style="color:#dc3545;"><?php echo number_format($stats['problem_roads']); ?></div>
                    <div class="lbl">Problem Roads</div>
                </div>
                <div class="col-3 col-md-3 stat-chip">
                    <div class="num" style="color:#d97706;"><?php echo number_format($stats['under_construction']); ?></div>
                    <div class="lbl">Under Construction</div>
                </div>
                <div class="col-3 col-md-3 stat-chip">
                    <div class="num" style="color:#28a745;"><?php echo number_format($stats['resolved_issues']); ?></div>
                    <div class="lbl">Resolved Issues</div>
                </div>
            </div>
        </div>
    </div>

    <div class="container" style="padding-top: 24px; padding-bottom: 48px;">
        <div class="filters-bar">
            <label style="font-weight: 500; font-size: 0.85rem; color: #495057;"><i class="fas fa-filter"></i> Filter:</label>
            <select id="statusFilter" onchange="applyFilters()">
                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="in-progress" <?php echo $status_filter === 'in-progress' ? 'selected' : ''; ?>>In Progress</option>
                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
            </select>
            <select id="typeFilter" onchange="applyFilters()">
                <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                <option value="citizen" <?php echo $type_filter === 'citizen' ? 'selected' : ''; ?>>Citizen Reports</option>
                <option value="cimm" <?php echo $type_filter === 'cimm' ? 'selected' : ''; ?>>CIMM Reports</option>
                <option value="infrastructure" <?php echo $type_filter === 'infrastructure' ? 'selected' : ''; ?>>Infrastructure Projects</option>
            </select>
            <span class="result-count"><i class="fas fa-list"></i> <?php echo count($all_reports); ?> report(s) found</span>
        </div>

        <?php if (empty($all_reports)): ?>
        <div class="no-reports">
            <i class="fas fa-inbox"></i>
            <h5>No Reports Found</h5>
            <p>No reports match the current filters. Try adjusting your selection.</p>
        </div>
        <?php else: ?>
        <div class="report-grid">
            <?php foreach ($all_reports as $r):
                $photo = getReportPhoto($r);
                $is_problem = in_array($r['status'] ?? '', ['pending', 'in-progress']);
                $is_construction = ($r['status'] ?? '') === 'in-progress';
                $is_resolved = ($r['status'] ?? '') === 'completed';
            ?>
            <div class="report-card" onclick="openDetail(<?php echo htmlspecialchars(json_encode([
                'title' => $r['title'] ?? 'Untitled',
                'description' => $r['description'] ?? 'No description',
                'location' => $r['location'] ?? 'Not specified',
                'status' => $r['status'] ?? 'pending',
                'priority' => $r['priority'] ?? 'medium',
                'source' => $r['source'] ?? 'transportation',
                'reported_date' => $r['reported_date'] ?? $r['created_at'] ?? '',
                'reporter' => $r['reporter_name'] ?? 'Anonymous',
                'department' => $r['department'] ?? 'Not specified',
                'severity' => $r['severity'] ?? 'Not specified',
                'has_photo' => $photo ? true : false,
                'photo_url' => $photo ?: '',
                'db_id' => $r['id'] ?? 0
            ], JSON_HEX_TAG | JSON_HEX_AMP)); ?>); return false;">
                <?php if ($photo): ?>
                <img src="<?php echo htmlspecialchars($photo); ?>" alt="Report photo" class="report-img" loading="lazy" onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                <div class="report-img-placeholder" style="display:none;">
                    <i class="fas fa-road"></i>
                </div>
                <?php else: ?>
                <div class="report-img-placeholder">
                    <i class="fas fa-road"></i>
                </div>
                <?php endif; ?>
                
                <div class="report-body">
                    <div class="report-meta">
                        <?php echo getSeverityIcon($r['status'] ?? ''); ?>
                        <?php echo getStatusBadge($r['status'] ?? 'pending'); ?>
                        <?php echo getPriorityBadge($r['priority'] ?? 'medium'); ?>
                        <?php if ($is_construction): ?>
                        <span class="road-marker construction"><i class="fas fa-hard-hat"></i> Construction</span>
                        <?php elseif ($is_problem): ?>
                        <span class="road-marker problem"><i class="fas fa-exclamation-circle"></i> Problem</span>
                        <?php elseif ($is_resolved): ?>
                        <span class="road-marker resolved"><i class="fas fa-check"></i> Resolved</span>
                        <?php endif; ?>
                    </div>
                    <div class="report-title"><?php echo htmlspecialchars($r['title'] ?? 'Untitled Report'); ?></div>
                    <div class="report-desc"><?php echo htmlspecialchars($r['description'] ?? ''); ?></div>
                    <div class="report-location">
                        <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($r['location'] ?? 'Location not specified'); ?>
                    </div>
                    <div class="report-footer">
                        <span><i class="far fa-clock"></i> <?php echo getTimeAgoShort($r['reported_date'] ?? $r['created_at'] ?? ''); ?></span>
                        <span class="report-source"><i class="fas fa-tag"></i> <?php echo ucfirst($r['source'] ?? 'transportation'); ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="modal fade detail-modal" id="reportModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-alt"></i> <span id="modalTitle"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="modalPhoto" style="margin-bottom: 16px; display: none;">
                        <img id="modalPhotoImg" src="" alt="Report Photo" style="width:100%; max-height: 300px; object-fit: cover; border-radius: 8px;">
                    </div>
                    <div id="modalDescription" style="margin-bottom: 20px; line-height: 1.7; font-size: 0.95rem; color: #334155;"></div>
                    <div id="modalInfo"></div>
                    <div id="citizenTimeline" style="margin-top: 24px; display: none;"></div>
                </div>
                <div class="modal-footer" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                    <div>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="toggleTimelineBtn" onclick="toggleCitizenTimeline()" style="display:none;">
                            <i class="fas fa-clock"></i> Progress Timeline
                        </button>
                    </div>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Close</button>
                </div>
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
                <p class="footer-copyright"><i class="fas fa-copyright"></i> 2026 Quezon City Road &amp; Transportation Department. All rights reserved.</p>
                <p class="footer-copyright" style="margin-top:4px;font-size:0.8rem;opacity:0.75;">Data presented is for public transparency and informational purposes only.</p>
            </div>
        </div>
    </footer>

    <!-- Lightbox -->
    <div class="lightbox-overlay" id="lightboxOverlay" onclick="closeLightbox()">
        <button class="lightbox-close" onclick="closeLightbox()">&times;</button>
        <img id="lightboxImage" src="" alt="Enlarged photo">
    </div>

    <?php include __DIR__ . '/includes/a11y_html.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentData = null;

        function applyFilters() {
            const status = document.getElementById('statusFilter').value;
            const type = document.getElementById('typeFilter').value;
            const url = new URL(window.location);
            url.searchParams.set('status', status);
            url.searchParams.set('type', type);
            window.location.href = url.toString();
        }

        function openDetail(data) {
            currentData = data;
            document.getElementById('modalTitle').textContent = data.title;
            
            const descEl = document.getElementById('modalDescription');
            descEl.textContent = data.description || 'No description available.';
            
            const infoEl = document.getElementById('modalInfo');
            const statusClass = (data.status === 'pending' ? 'bg-warning' : data.status === 'in-progress' ? 'bg-info' : data.status === 'completed' ? 'bg-success' : 'bg-secondary');
            const priorityClass = (data.priority === 'high' || data.priority === 'critical') ? 'bg-danger' : data.priority === 'medium' ? 'bg-warning' : 'bg-success';
            
            infoEl.innerHTML = `
                <div class="info-row"><span class="label"><i class="fas fa-flag"></i> Status</span><span class="value"><span class="badge ${statusClass}">${data.status}</span></span></div>
                <div class="info-row"><span class="label"><i class="fas fa-exclamation-circle"></i> Priority</span><span class="value"><span class="badge ${priorityClass}">${data.priority}</span></span></div>
                <div class="info-row"><span class="label"><i class="fas fa-map-marker-alt"></i> Location</span><span class="value">${data.location}</span></div>
                <div class="info-row"><span class="label"><i class="fas fa-building"></i> Department</span><span class="value">${data.department}</span></div>
                <div class="info-row"><span class="label"><i class="fas fa-tag"></i> Type</span><span class="value">${data.source}</span></div>
                <div class="info-row"><span class="label"><i class="fas fa-clock"></i> Reported</span><span class="value">${data.reported_date || 'Not specified'}</span></div>
                <div class="info-row"><span class="label"><i class="fas fa-user"></i> Reporter</span><span class="value">${data.reporter}</span></div>
                <div class="info-row"><span class="label"><i class="fas fa-tachometer-alt"></i> Severity</span><span class="value">${data.severity}</span></div>
            `;

            if (data.has_photo) {
                document.getElementById('modalPhoto').style.display = 'block';
            } else {
                document.getElementById('modalPhoto').style.display = 'none';
            }

            const modal = new bootstrap.Modal(document.getElementById('reportModal'));
            modal.show();
        }

        document.querySelectorAll('.report-card').forEach(card => {
            card.addEventListener('click', function(e) {
                if (e.target.closest('.badge') || e.target.closest('.road-marker')) return;
            });
        });

        /* Citizen Progress Timeline */
        let citizenTimelineVisible = false;
        let citizenUpdatesLoaded = false;

        function openDetail(data) {
            currentData = data;
            document.getElementById('modalTitle').textContent = data.title;

            const descEl = document.getElementById('modalDescription');
            descEl.textContent = data.description || 'No description available.';

            const infoEl = document.getElementById('modalInfo');
            const statusClass = (data.status === 'pending' ? 'bg-warning' : data.status === 'in-progress' ? 'bg-info' : data.status === 'completed' ? 'bg-success' : 'bg-secondary');
            const priorityClass = (data.priority === 'high' || data.priority === 'critical') ? 'bg-danger' : data.priority === 'medium' ? 'bg-warning' : 'bg-success';

            infoEl.innerHTML = `
                <div class="info-row"><span class="label"><i class="fas fa-flag"></i> Status</span><span class="value"><span class="badge ${statusClass}">${data.status}</span></span></div>
                <div class="info-row"><span class="label"><i class="fas fa-exclamation-circle"></i> Priority</span><span class="value"><span class="badge ${priorityClass}">${data.priority}</span></span></div>
                <div class="info-row"><span class="label"><i class="fas fa-map-marker-alt"></i> Location</span><span class="value">${data.location}</span></div>
                <div class="info-row"><span class="label"><i class="fas fa-building"></i> Department</span><span class="value">${data.department}</span></div>
                <div class="info-row"><span class="label"><i class="fas fa-tag"></i> Type</span><span class="value">${data.source}</span></div>
                <div class="info-row"><span class="label"><i class="fas fa-clock"></i> Reported</span><span class="value">${data.reported_date || 'Not specified'}</span></div>
                <div class="info-row"><span class="label"><i class="fas fa-user"></i> Reporter</span><span class="value">${data.reporter}</span></div>
                <div class="info-row"><span class="label"><i class="fas fa-tachometer-alt"></i> Severity</span><span class="value">${data.severity}</span></div>
            `;

            if (data.photo_url) {
                document.getElementById('modalPhotoImg').src = data.photo_url;
                document.getElementById('modalPhoto').style.display = 'block';
            } else {
                document.getElementById('modalPhoto').style.display = 'none';
            }

            // Reset timeline
            citizenTimelineVisible = false;
            citizenUpdatesLoaded = false;
            document.getElementById('citizenTimeline').style.display = 'none';
            document.getElementById('citizenTimeline').innerHTML = '';
            const toggleBtn = document.getElementById('toggleTimelineBtn');
            toggleBtn.style.display = 'inline-block';
            toggleBtn.innerHTML = '<i class="fas fa-clock"></i> Progress Timeline';

            const modal = new bootstrap.Modal(document.getElementById('reportModal'));
            modal.show();
        }

        function toggleCitizenTimeline() {
            const container = document.getElementById('citizenTimeline');
            const btn = document.getElementById('toggleTimelineBtn');
            citizenTimelineVisible = !citizenTimelineVisible;

            if (citizenTimelineVisible) {
                container.style.display = 'block';
                btn.innerHTML = '<i class="fas fa-times"></i> Hide Timeline';
                if (!citizenUpdatesLoaded) {
                    loadCitizenUpdates();
                }
            } else {
                container.style.display = 'none';
                btn.innerHTML = '<i class="fas fa-clock"></i> Progress Timeline';
            }
        }

        function loadCitizenUpdates() {
            const container = document.getElementById('citizenTimeline');
            container.innerHTML = '<div style="text-align:center;padding:20px;"><i class="fas fa-spinner fa-spin fa-2x" style="color:#3762c8;"></i></div>';

            const reportType = currentData.source === 'maintenance' ? 'maintenance' : 'transportation';
            fetch(`lgu_staff/pages/api/progress_update_api.php?action=get_updates&report_id=${currentData.db_id}&report_type=${reportType}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        renderCitizenTimeline(data.updates);
                    } else {
                        container.innerHTML = '<div class="timeline-empty"><i class="fas fa-exclamation-circle"></i><br>' + escapeHtml(data.message) + '</div>';
                    }
                })
                .catch(() => {
                    container.innerHTML = '<div class="timeline-empty"><i class="fas fa-exclamation-triangle"></i><br>Unable to load timeline.</div>';
                });
        }

        function openLightbox(src) {
            const overlay = document.getElementById('lightboxOverlay');
            const img = document.getElementById('lightboxImage');
            if (overlay && img) {
                img.onerror = function() {
                    const retried = this.dataset.retried === '1';
                    this.dataset.retried = '1';
                    if (retried) { this.style.display = 'none'; return; }
                    this.src = 'lgu_staff/' + String(src).replace(/\\/g, '/').replace(/^\.?\/+/, '');
                };
                img.src = src;
                overlay.classList.add('show');
            }
        }

        function mediaRetry(img, origPath) {
            if (img.dataset.retried === '1') { img.style.display = 'none'; return; }
            img.dataset.retried = '1';
            img.src = 'lgu_staff/' + String(origPath).replace(/\\/g, '/').replace(/^\.?\/+/, '');
        }

        function openMedia(p) {
            const cleaned = String(p || '').replace(/\\/g, '/').replace(/^\.?\/+/, '');
            if (/^https?:\/\//i.test(cleaned) || cleaned.indexOf('data:') === 0) { window.open(cleaned, '_blank'); return; }
            const candidates = [cleaned, 'lgu_staff/' + cleaned];
            const tryOpen = (i) => {
                if (i >= candidates.length) return;
                fetch(candidates[i], { method: 'HEAD' })
                    .then(r => { if (r.ok) window.open(candidates[i], '_blank'); else tryOpen(i + 1); })
                    .catch(() => tryOpen(i + 1));
            };
            tryOpen(0);
        }

        function closeLightbox() {
            const overlay = document.getElementById('lightboxOverlay');
            if (overlay) overlay.classList.remove('show');
        }

        function renderCitizenTimeline(updates) {
            const container = document.getElementById('citizenTimeline');
            citizenUpdatesLoaded = true;
            if (!updates || updates.length === 0) {
                container.innerHTML = '<div class="timeline-empty"><i class="fas fa-clock"></i><br>No progress updates yet.</div>';
                return;
            }
            let html = '<div class="timeline-container">';
            updates.forEach(u => {
                const mediaHtml = (u.media || []).map(m => {
                    if (m.file_type === 'video') {
                        return `<div class="timeline-media-item video-thumb" onclick="openMedia('${escapeHtmlAttr(m.file_path)}')"><i class="fas fa-play-circle"></i></div>`;
                    }
                    return `<div class="timeline-media-item" onclick="openLightbox('${escapeHtmlAttr(m.file_path)}')"><img src="${escapeHtmlAttr(m.file_path)}" alt="" loading="lazy" onerror="mediaRetry(this,'${escapeHtmlAttr(m.file_path)}')"></div>`;
                }).join('');

                html += `
                <div class="timeline-entry">
                    <div class="timeline-dot"><i class="fas fa-check"></i></div>
                    <div class="timeline-card">
                        <div class="timeline-header">
                            <div class="timeline-meta">
                                <span class="admin-badge"><i class="fas fa-user-shield"></i> LGU Staff</span>
                                <span class="time"><i class="far fa-clock"></i> ${escapeHtml(u.created_at_formatted || u.created_at)}</span>
                            </div>
                        </div>
                        ${u.title ? `<div class="timeline-title">${escapeHtml(u.title)}</div>` : ''}
                        <div class="timeline-desc">${escapeHtml(u.description)}</div>
                        ${mediaHtml ? `<div class="timeline-media">${mediaHtml}</div>` : ''}
                    </div>
                </div>`;
            });
            html += '</div>';
            container.innerHTML = html;
        }

        function escapeHtml(t) { if (!t) return ''; const d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
        function escapeHtmlAttr(t) { if (!t) return ''; return t.replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

        // Auto-open specific report from URL parameter
        <?php if ($focus_report_id > 0): ?>
        window.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.report-card');
            for (const card of cards) {
                const attr = card.getAttribute('onclick') || '';
                const m = attr.match(/"db_id"\s*:\s*(\d+)/);
                if (m && parseInt(m[1]) === <?php echo $focus_report_id; ?>) {
                    card.click();
                    break;
                }
            }
        });
        <?php endif; ?>
    </script>

    <script src="lgu_staff/js/page-transition.js"></script>
    <?php include __DIR__ . '/includes/a11y_js.php'; ?>
    <?php include __DIR__ . '/includes/hamburger_menu_js.php'; ?>
</body>
</html>
