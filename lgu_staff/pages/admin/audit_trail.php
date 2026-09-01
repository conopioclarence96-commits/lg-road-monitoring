<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

if (!is_logged_in()) {
    header('Location: ' . rgmap_url('login'));
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'citizen';

// Font color overrides below apply to the system admin only.
$is_system_admin = ($user_role === 'system_admin');

$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

$action_filter = sanitize_input($_GET['action'] ?? '');
$user_filter = intval($_GET['user_id'] ?? 0);
$date_from = sanitize_input($_GET['date_from'] ?? '');
$date_to = sanitize_input($_GET['date_to'] ?? '');

$where = [];
$params = [];
$types = '';

if ($action_filter) {
    $where[] = "a.action = ?";
    $params[] = $action_filter;
    $types .= "s";
}

if ($user_filter > 0) {
    $where[] = "a.user_id = ?";
    $params[] = $user_filter;
    $types .= "i";
}

if ($date_from) {
    $where[] = "a.created_at >= ?";
    $params[] = $date_from . ' 00:00:00';
    $types .= "s";
}

if ($date_to) {
    $where[] = "a.created_at <= ?";
    $params[] = $date_to . ' 23:59:59';
    $types .= "s";
}

$where_clause = !empty($where) ? "WHERE " . implode(' AND ', $where) : '';

$count_query = "SELECT COUNT(*) as total FROM audit_logs a {$where_clause}";
if (!empty($params)) {
    $count_result = execute_query($count_query, $params, $types);
    $total = $count_result->get_result()->fetch_assoc()['total'];
} else {
    $total = fetch_one($count_query)['total'];
}

$total_pages = max(1, ceil($total / $per_page));

$query = "SELECT a.*, u.username, u.full_name 
          FROM audit_logs a 
          LEFT JOIN users u ON a.user_id = u.id 
          {$where_clause} 
          ORDER BY a.created_at DESC 
          LIMIT ? OFFSET ?";

$query_params = $params;
$query_types = $types;
$query_params[] = $per_page;
$query_types .= "i";
$query_params[] = $offset;
$query_types .= "i";

$logs = fetch_all($query, $query_params, $query_types);

$users = fetch_all("SELECT id, username, full_name FROM users ORDER BY full_name ASC");

$actions_list = fetch_all("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC");

$action_icons = [
    'login' => 'fa-sign-in-alt',
    'logout' => 'fa-sign-out-alt',
    'create' => 'fa-plus-circle',
    'update' => 'fa-edit',
    'delete' => 'fa-trash-alt',
    'archive' => 'fa-archive',
    'restore' => 'fa-undo',
    'approve' => 'fa-check-circle',
    'reject' => 'fa-times-circle',
    'Received' => 'fa-inbox',
    'Updated' => 'fa-edit',
    'Archived' => 'fa-archive',
    'Viewed' => 'fa-eye',
    'Created' => 'fa-plus-circle',
    'Deleted' => 'fa-trash-alt',
    'Accepted' => 'fa-check-double',
    'Generated' => 'fa-file-export',
    'Exported' => 'fa-file-export',
];

function getActionIcon($action) {
    global $action_icons;
    foreach ($action_icons as $key => $icon) {
        if (stripos($action, $key) !== false) {
            return $icon;
        }
    }
    return 'fa-history';
}

function getActionColor($action) {
    if (stripos($action, 'login') !== false) return '#2563eb';
    if (stripos($action, 'logout') !== false) return '#6b7280';
    if (stripos($action, 'create') !== false || stripos($action, 'Created') !== false) return '#059669';
    if (stripos($action, 'update') !== false || stripos($action, 'Updated') !== false) return '#d97706';
    if (stripos($action, 'delete') !== false || stripos($action, 'Deleted') !== false) return '#dc2626';
    if (stripos($action, 'archive') !== false || stripos($action, 'Archived') !== false) return '#6b7280';
    if (stripos($action, 'approve') !== false) return '#059669';
    if (stripos($action, 'reject') !== false) return '#dc2626';
    if (stripos($action, 'view') !== false || stripos($action, 'Viewed') !== false) return '#2563eb';
    if (stripos($action, 'export') !== false || stripos($action, 'Exported') !== false) return '#0284c7';
    if (stripos($action, 'Accepted') !== false) return '#059669';
    if (stripos($action, 'Generated') !== false) return '#0284c7';
    if (stripos($action, 'Restored') !== false) return '#059669';
    return '#6b7280';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once __DIR__ . '/../../includes/page_head_base.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Trail - LGU Road Monitoring</title>
    <link rel="icon" type="image/png" href="lgu_staff/assets/img/infra-gov-logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="lgu_staff/css/theme-tokens.css">
    <link rel="stylesheet" href="lgu_staff/css/theme-utilities.css">
    <link rel="stylesheet" href="lgu_staff/css/sidebar.css?v=6">
    <link rel="stylesheet" href="lgu_staff/css/enhanced-reports.css">
    <link rel="stylesheet" href="styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="lgu_staff/css/dark-mode.css"><?php endif; ?>
    <style>
        /* ── Audit Trail: light dashboard look + dark mode ── */
        body { background: #f7f5f0; min-height: 100vh; color: #1e293b; }
        body.dark-mode { background: var(--bg-page); color: var(--text-primary); }

        .audit-main {
            margin-left: 250px;
            padding: 24px 28px 40px !important;
            max-width: 100%;
            overflow-x: hidden;
            position: relative;
            z-index: 1;
        }

        .audit-main .page-header {
            background: #fff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04), 0 8px 24px rgba(30, 60, 114, 0.06);
            border-radius: 16px;
            padding: 20px 24px;
            margin-bottom: 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
        }

        .audit-main .page-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
        }

        .audit-main .header-icon {
            flex: 0 0 44px;
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: rgba(55, 98, 200, 0.12);
            color: #3762c8;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .audit-main .page-header h1 {
            font-size: 22px;
            font-weight: 700;
            color: #1e3c72;
            margin: 0 0 2px;
            letter-spacing: -0.02em;
        }

        .audit-main .page-header h1 i { display: none; }

        .audit-main .page-header p {
            margin: 0;
            color: #64748b;
            font-size: 13px;
        }

        .audit-main .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .dt-chip {
            display: flex; align-items: center; gap: 10px;
            background: var(--color-primary-bg, #eef2ff);
            border: 1px solid var(--border-default, #d5dce8);
            border-radius: 14px; padding: 10px 14px;
            flex-shrink: 0;
        }
        .dt-chip i {
            color: #fff; font-size: 16px; width: 28px; height: 28px; border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #1e3c72, #0f274a);
        }
        .dt-chip #currentDate { font-weight: 600; color: var(--text-primary, #0f172a); font-size: 13px; }
        .dt-chip #currentTime { color: var(--text-secondary, #64748b); font-size: 12px; margin-top: 1px; }

        .audit-main .entries-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
            color: #475569;
        }

        .audit-main .entries-chip i { color: #3762c8; }

        .audit-main .btn {
            padding: 9px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            transition: background 0.2s, border-color 0.2s, box-shadow 0.2s, transform 0.2s;
        }

        .audit-main .btn-primary {
            background: #3762c8;
            color: #fff;
            border: none;
            box-shadow: 0 2px 8px rgba(55, 98, 200, 0.2);
        }

        .audit-main .btn-primary:hover {
            background: #1e3c72;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(55, 98, 200, 0.28);
        }

        .audit-main .btn-outline {
            background: #fff;
            border: 1px solid #e2e8f0;
            color: #475569;
            box-shadow: none;
        }

        .audit-main .btn-outline:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #1e3c72;
            transform: translateY(-1px);
        }

        .audit-main .panel {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04), 0 6px 18px rgba(30, 60, 114, 0.05);
            margin-bottom: 18px;
            overflow: hidden;
            position: relative;
        }

        .audit-main .panel::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: #3762c8;
            z-index: 1;
        }

        .audit-main .panel-filter::before { background: #f59e0b; }
        .audit-main .panel-activity::before { background: #3762c8; }

        .audit-main .panel-header {
            padding: 14px 18px 14px 20px;
            background: #f8fafc;
            border-bottom: 1px solid #eef2f7;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .audit-main .panel-title {
            font-size: 15px;
            font-weight: 700;
            color: #1e3c72;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .audit-main .panel-title i {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            margin-right: 0;
            background: rgba(55, 98, 200, 0.1);
            color: #3762c8;
        }

        .audit-main .panel-filter .panel-title i {
            background: rgba(245, 158, 11, 0.14);
            color: #d97706;
        }

        .audit-main .panel-meta {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
        }

        .audit-main .panel-body {
            padding: 18px 18px 18px 20px;
        }

        .audit-main .filter-bar {
            display: flex;
            gap: 12px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .audit-main .filter-bar > div {
            min-width: 140px;
            flex: 1 1 140px;
        }

        .audit-main .filter-bar .filter-actions {
            flex: 0 0 auto;
            min-width: auto;
            display: flex;
            gap: 8px;
            align-self: flex-end;
        }

        .audit-main .filter-bar label {
            font-size: 11px;
            color: #64748b;
            font-weight: 600;
            display: block;
            margin-bottom: 6px;
        }

        .audit-main .filter-bar select,
        .audit-main .filter-bar input {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #fff;
            color: #1e293b;
            font-size: 13px;
            font-family: 'Poppins', sans-serif;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .audit-main .filter-bar select:focus,
        .audit-main .filter-bar input:focus {
            outline: none;
            border-color: #3762c8;
            box-shadow: 0 0 0 3px rgba(55, 98, 200, 0.12);
        }

        .audit-main .panel-body-flush {
            padding: 0;
        }

        .audit-main .empty-state {
            text-align: center;
            padding: 56px 20px;
            color: #64748b;
        }

        .audit-main .empty-state i {
            font-size: 42px;
            margin-bottom: 14px;
            color: #cbd5e1;
            display: block;
        }

        .audit-main .timeline {
            padding: 20px 20px 20px 56px;
            position: relative;
        }

        .audit-main .timeline::before {
            left: 27px;
            background: #e2e8f0;
            width: 2px;
        }

        .audit-main .timeline-item {
            padding-bottom: 16px;
        }

        .audit-main .timeline-icon {
            left: -36px;
            width: 34px;
            height: 34px;
            border-radius: 10px;
            font-size: 13px;
            background: color-mix(in srgb, var(--ac, #64748b) 14%, #fff);
            color: var(--ac, #64748b);
            border: 1px solid color-mix(in srgb, var(--ac, #64748b) 22%, #fff);
            box-shadow: none;
        }

        .audit-main .timeline-content {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 16px;
            transition: box-shadow 0.2s, border-color 0.2s;
        }

        .audit-main .timeline-item:hover .timeline-content {
            background: #fff;
            border-color: #d5dce8;
            box-shadow: 0 4px 14px rgba(30, 60, 114, 0.06);
        }

        .audit-main .timeline-time {
            font-size: 11px;
            color: #64748b;
            margin-bottom: 6px;
            display: flex;
            flex-wrap: wrap;
            gap: 4px 14px;
            align-items: center;
        }

        .audit-main .timeline-time i { color: #94a3b8; }

        .audit-main .timeline-title {
            font-weight: 600;
            font-size: 14px;
            color: #1e3c72;
            margin-bottom: 4px;
        }

        .audit-main .timeline-title .by-user {
            font-weight: 500;
            color: #64748b;
        }

        .audit-main .timeline-desc {
            font-size: 13px;
            color: #64748b;
            line-height: 1.5;
        }

        .audit-main .pagination {
            display: flex;
            gap: 6px;
            justify-content: center;
            flex-wrap: wrap;
            padding: 8px 0 4px;
        }

        .audit-main .pagination a,
        .audit-main .pagination span {
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            border: 1px solid #e2e8f0;
            color: #64748b;
            text-decoration: none;
            background: #fff;
            transition: background 0.2s, border-color 0.2s, color 0.2s;
        }

        .audit-main .pagination a:hover {
            border-color: #3762c8;
            color: #3762c8;
            background: rgba(55, 98, 200, 0.06);
        }

        .audit-main .pagination .active {
            background: #3762c8;
            color: #fff;
            border-color: #3762c8;
        }

        /* Dark mode — explicit colors for contrast (readable on dark cards) */
        body.dark-mode .audit-main {
            color: #e4e6ea;
        }

        body.dark-mode .audit-main .page-header,
        body.dark-mode .audit-main .panel,
        body.dark-mode .audit-main .pagination a,
        body.dark-mode .audit-main .pagination span {
            background: #1a1d24 !important;
            border-color: #2d323b !important;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.35) !important;
        }

        body.dark-mode .audit-main .timeline-content {
            background: #22262e !important;
            border-color: #2d323b !important;
        }

        body.dark-mode .audit-main .page-header h1,
        body.dark-mode .audit-main .panel-title,
        body.dark-mode .audit-main .timeline-title {
            color: #e4e6ea !important;
        }

        body.dark-mode .audit-main .page-header p,
        body.dark-mode .audit-main .panel-meta,
        body.dark-mode .audit-main .filter-bar label,
        body.dark-mode .audit-main .timeline-time,
        body.dark-mode .audit-main .timeline-title .by-user,
        body.dark-mode .audit-main .timeline-desc,
        body.dark-mode .audit-main .empty-state,
        body.dark-mode .audit-main .empty-state p,
        body.dark-mode .audit-main .pagination a,
        body.dark-mode .audit-main .pagination span:not(.active) {
            color: #b0b7c3 !important;
        }

        body.dark-mode .audit-main .timeline-time i {
            color: #8b93a1 !important;
        }

        body.dark-mode .audit-main .header-icon {
            background: rgba(96, 165, 250, 0.2) !important;
            color: #93c5fd !important;
        }

        body.dark-mode .audit-main .entries-chip {
            background: #22262e !important;
            border-color: #2d323b !important;
            color: #b0b7c3 !important;
        }

        body.dark-mode .audit-main .entries-chip i { color: #93c5fd !important; }

        body.dark-mode .audit-main .panel-header {
            background: #1e2229 !important;
            border-bottom-color: #2d323b !important;
        }

        body.dark-mode .audit-main .panel-title i {
            background: rgba(96, 165, 250, 0.18) !important;
            color: #93c5fd !important;
        }

        body.dark-mode .audit-main .panel-filter .panel-title i {
            background: rgba(245, 158, 11, 0.2) !important;
            color: #fbbf24 !important;
        }

        body.dark-mode .audit-main .btn-outline {
            background: #22262e !important;
            border-color: #374151 !important;
            color: #e4e6ea !important;
        }

        body.dark-mode .audit-main .btn-outline:hover {
            background: #2a2f38 !important;
            border-color: #60a5fa !important;
            color: #f3f4f6 !important;
        }

        body.dark-mode .audit-main .btn-primary {
            background: #3b82f6 !important;
            color: #ffffff !important;
        }

        body.dark-mode .audit-main .btn-primary:hover {
            background: #2563eb !important;
            color: #ffffff !important;
        }

        body.dark-mode .audit-main .filter-bar select,
        body.dark-mode .audit-main .filter-bar input {
            background: #22262e !important;
            border-color: #374151 !important;
            color: #e4e6ea !important;
            color-scheme: dark;
        }

        body.dark-mode .audit-main .filter-bar select option {
            background: #1a1d24;
            color: #e4e6ea;
        }

        body.dark-mode .audit-main .timeline::before {
            background: #374151 !important;
        }

        body.dark-mode .audit-main .timeline-icon {
            background: color-mix(in srgb, var(--ac, #93c5fd) 24%, #1a1d24) !important;
            border-color: color-mix(in srgb, var(--ac, #93c5fd) 40%, #1a1d24) !important;
            color: var(--ac, #93c5fd) !important;
        }

        body.dark-mode .audit-main .timeline-item:hover .timeline-content {
            background: #2a2f38 !important;
            border-color: #3b4453 !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.35) !important;
        }

        body.dark-mode .audit-main .empty-state i { color: #6b7280 !important; }

        body.dark-mode .audit-main .pagination a:hover {
            background: rgba(96, 165, 250, 0.15) !important;
            border-color: #60a5fa !important;
            color: #93c5fd !important;
        }

        body.dark-mode .audit-main .pagination .active {
            background: #3b82f6 !important;
            border-color: #3b82f6 !important;
            color: #ffffff !important;
        }

        @media (max-width: 768px) {
            .audit-main {
                margin-left: 0;
                padding: 16px !important;
            }
            .audit-main .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .audit-main .filter-bar > div {
                flex: 1 1 100%;
            }
            .audit-main .timeline {
                padding-left: 48px;
            }
        }
    </style>
<?php if ($is_system_admin): ?>
    <!-- System Admin only: fit for the Filter Logs panel (panel-filter).
         Desktop: no global border-box reset is loaded on this page, so the
         width:100% date/select controls add their padding+border on top of
         the container width and spill ~26px over the neighbouring fields and
         the Apply/Clear buttons. Mobile: the global stylesheet flips
         .filter-bar to flex-direction: column below 768px while this page
         keeps align-items: flex-end — fields then shrink to their content
         width and hug the right edge instead of stacking full-width.
         UI-only CSS scoping — other portals/pages are unaffected and no
         behaviour changes. -->
    <style>
        /* ── All viewports (desktop included): stop controls spilling out of
           their containers and overlapping each other ── */
        .audit-main .panel-filter,
        .audit-main .panel-filter .panel-body,
        .audit-main .panel-filter .filter-bar {
            max-width: 100%;
            min-width: 0;
            box-sizing: border-box;
        }

        /* Allow the field columns to shrink below their content size */
        .audit-main .panel-filter .filter-bar > div {
            min-width: 0;
        }

        /* width:100% must INCLUDE padding + border, otherwise every control
           paints ~26px past its wrapper onto the adjacent container */
        .audit-main .panel-filter .filter-bar select,
        .audit-main .panel-filter .filter-bar input {
            box-sizing: border-box;
            min-width: 0;
            max-width: 100%;
        }

        @media (max-width: 768px) {
            /* Keep the bar a wrapping ROW so flex-basis:100% makes each field
               take a full row; in column direction flex-basis is read as
               HEIGHT, which breaks the stacked layout. */
            .audit-main .panel-filter .filter-bar {
                flex-direction: row;
                flex-wrap: wrap;
                align-items: stretch;
                gap: 10px;
            }

            .audit-main .panel-filter .filter-bar > div {
                flex: 1 1 100%;
                min-width: 0;
                max-width: 100%;
            }

            /* Comfortable tap targets; >=16px stops iOS Safari zoom-on-focus */
            .audit-main .panel-filter .filter-bar label {
                margin-bottom: 5px;
            }
            .audit-main .panel-filter .filter-bar select,
            .audit-main .panel-filter .filter-bar input {
                width: 100%;
                min-width: 0;
                max-width: 100%;
                font-size: 16px;
                padding: 10px 12px;
                box-sizing: border-box;
            }

            /* Apply / Clear share one full-width row */
            .audit-main .panel-filter .filter-bar .filter-actions {
                flex: 1 1 100%;
                display: flex;
                gap: 10px;
                align-self: auto;
            }
            .audit-main .panel-filter .filter-bar .filter-actions .btn {
                flex: 1 1 0;
                justify-content: center;
                text-align: center;
            }

            /* Tighter panel padding on phones */
            .audit-main .panel-filter .panel-body {
                padding: 14px 14px 16px 17px;
            }
        }
    </style>
<?php endif; ?>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="audit-main main-content">
        <div class="page-header">
            <div class="page-header-left">
                <div class="header-icon"><i class="fas fa-history"></i></div>
                <div>
                    <h1><i class="fas fa-history"></i> Audit Trail</h1>
                    <p>Comprehensive system activity log with filtering and search</p>
                </div>
            </div>
            <div class="header-actions">
                <div class="dt-chip">
                    <i class="fas fa-calendar-day"></i>
                    <div>
                        <div id="currentDate"></div>
                        <div id="currentTime"></div>
                    </div>
                </div>
                <a class="btn btn-outline" href="<?php echo htmlspecialchars(rgmap_api_url('export_audit_trail.php')); ?>" style="text-decoration:none;">
                    <i class="fas fa-file-export"></i> Export
                </a>
                <button type="button" class="btn btn-outline" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
                <span class="entries-chip">
                    <i class="fas fa-database"></i> <?php echo number_format($total); ?> total entries
                </span>
            </div>
        </div>

        <div class="panel panel-filter">
            <div class="panel-header">
                <h3 class="panel-title"><i class="fas fa-filter"></i> Filter Logs</h3>
            </div>
            <div class="panel-body">
                <form method="GET" class="filter-bar">
                    <div>
                        <label for="action">Action</label>
                        <select name="action" id="action">
                            <option value="">All Actions</option>
                            <?php foreach ($actions_list as $a): ?>
                            <option value="<?php echo htmlspecialchars($a['action']); ?>" <?php echo $action_filter === $a['action'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($a['action']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="user_id">User</label>
                        <select name="user_id" id="user_id">
                            <option value="">All Users</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $user_filter === intval($u['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['full_name'] ?: $u['username']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="date_from">From</label>
                        <input type="date" name="date_from" id="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div>
                        <label for="date_to">To</label>
                        <input type="date" name="date_to" id="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply</button>
                        <a href="<?php echo htmlspecialchars(rgmap_url('audit-trail')); ?>" class="btn btn-outline"><i class="fas fa-times"></i> Clear</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="panel panel-activity">
            <div class="panel-header">
                <h3 class="panel-title"><i class="fas fa-list"></i> Activity Log</h3>
                <span class="panel-meta">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
            </div>
            <div class="panel-body panel-body-flush">
                <?php if (empty($logs)): ?>
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <p>No audit log entries found matching your criteria.</p>
                </div>
                <?php else: ?>
                <div class="timeline">
                    <?php foreach ($logs as $log): ?>
                    <div class="timeline-item">
                        <div class="timeline-icon" style="--ac: <?php echo getActionColor($log['action']); ?>;">
                            <i class="fas <?php echo getActionIcon($log['action']); ?>"></i>
                        </div>
                        <div class="timeline-content">
                            <div class="timeline-time">
                                <span><i class="fas fa-clock"></i> <?php echo format_datetime($log['created_at']); ?></span>
                                <?php if ($log['ip_address'] && $log['ip_address'] !== 'Unknown'): ?>
                                <span><i class="fas fa-network-wired"></i> <?php echo htmlspecialchars($log['ip_address']); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="timeline-title">
                                <?php echo htmlspecialchars($log['action']); ?>
                                <?php if ($log['full_name']): ?>
                                <span class="by-user">by <?php echo htmlspecialchars($log['full_name']); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($log['details']): ?>
                            <div class="timeline-desc">
                                <?php echo htmlspecialchars($log['details']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="?page=<?php echo ($page - 1); ?>&action=<?php echo urlencode($action_filter); ?>&user_id=<?php echo $user_filter; ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>">
                <i class="fas fa-chevron-left"></i> Previous
            </a>
            <?php endif; ?>
            
            <?php
            $start = max(1, $page - 2);
            $end = min($total_pages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
                <?php if ($i === $page): ?>
                <span class="active"><?php echo $i; ?></span>
                <?php else: ?>
                <a href="?page=<?php echo $i; ?>&action=<?php echo urlencode($action_filter); ?>&user_id=<?php echo $user_filter; ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>">
                    <?php echo $i; ?>
                </a>
                <?php endif; ?>
            <?php endfor; ?>
            
            <?php if ($page < $total_pages): ?>
            <a href="?page=<?php echo ($page + 1); ?>&action=<?php echo urlencode($action_filter); ?>&user_id=<?php echo $user_filter; ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>">
                Next <i class="fas fa-chevron-right"></i>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
        function updateDateTime() {
            const now = new Date();
            const dateOptions = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const timeOptions = { hour: '2-digit', minute: '2-digit', second: '2-digit' };
            const dateEl = document.getElementById('currentDate');
            const timeEl = document.getElementById('currentTime');
            if (dateEl) dateEl.textContent = now.toLocaleDateString('en-US', dateOptions);
            if (timeEl) timeEl.textContent = now.toLocaleTimeString('en-US', timeOptions);
        }
        updateDateTime();
        setInterval(updateDateTime, 1000);
    </script>

</body>
</html>
