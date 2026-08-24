<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

if (!is_logged_in()) {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'citizen';

// Font color overrides below apply to the system admin only.
$is_system_admin = ($user_role === 'system_admin');

// Transport Operations Supervisor flag: scopes the mobile-fit CSS below to this portal only.
$is_trans_ops_supervisor = ($user_role === 'trans_ops_supervisor');

// Road Monitoring Officer flag: scopes the mobile-fit CSS below to this portal only.
$is_road_monitoring_officer = ($user_role === 'road_monitoring_officer');

$period = sanitize_input($_GET['period'] ?? '30');

// Date range applied to every card, chart and table on the page.
$date_floor = null;
switch ($period) {
    case '7':   $date_floor = date('Y-m-d 00:00:00', strtotime('-7 days'));   break;
    case '90':  $date_floor = date('Y-m-d 00:00:00', strtotime('-90 days'));  break;
    case '365': $date_floor = date('Y-m-d 00:00:00', strtotime('-365 days')); break;
    case 'all': $date_floor = null;                                            break;
    case '30':
    default:    $date_floor = date('Y-m-d 00:00:00', strtotime('-30 days'));  break;
}
$main_where = $date_floor ? "WHERE created_at >= '$date_floor'" : '';
$cimm_extra = $date_floor ? "AND COALESCE(submitted_at, created_at) >= '$date_floor'" : '';

// The CIMM verification flag may or may not exist on the transport table.
$has_cimm_flag = false;
$col_check = $conn->query("SHOW COLUMNS FROM road_transportation_reports LIKE 'cimm_sync_status'");
if ($col_check) {
    $has_cimm_flag = (bool)$col_check->num_rows;
}
$verified_expr = $has_cimm_flag ? "COALESCE(cimm_sync_status, '') = 'verified'" : "1 = 0";

// Source-system classification built only from existing report fields.
$src_case = "CASE
    WHEN report_type IN ('infrastructure_issue','maintenance','maintenance_request') THEN 'infrastructure'
    WHEN report_source = 'external' THEN 'cimm'
    WHEN report_source = 'local' AND COALESCE(created_by, 0) != 0 THEN 'lgu'
    ELSE 'citizen'
END";

$transport_rows = fetch_all("SELECT report_type, department, priority, status, created_at, created_by, report_source, COALESCE(resolved_date, updated_at) AS resolved_at, ($verified_expr) AS is_verified, $src_case AS source_system FROM road_transportation_reports $main_where") ?: [];
$maintenance_rows = fetch_all("SELECT report_type, department, priority, status, created_at, updated_at AS resolved_at, 'infrastructure' AS source_system FROM road_maintenance_reports $main_where") ?: [];

// CIMM verification reports feed the CIMM source bucket and verified counter.
$cimm_total = 0;
$cimm_verified = 0;
$has_cimm_table = (bool)$conn->query("SHOW TABLES LIKE 'cimm_verification_reports'")->num_rows;
if ($has_cimm_table) {
    $cimm_total = (int)(fetch_one("SELECT COUNT(*) AS c FROM cimm_verification_reports WHERE 1 = 1 $cimm_extra")['c'] ?? 0);
    $cimm_verified = (int)(fetch_one("SELECT COUNT(*) AS c FROM cimm_verification_reports WHERE verification_status = 'Verified' $cimm_extra")['c'] ?? 0);
}

// Normalize every report (transport + maintenance) into one analytics set.
$all_rows = [];
foreach ($transport_rows as $r) {
    $all_rows[] = [
        'status'      => $r['status'] ?? 'pending',
        'priority'    => $r['priority'] ?? 'medium',
        'department'  => $r['department'] ?? 'Unknown',
        'report_type' => $r['report_type'] ?? 'Unknown',
        'created_at'  => $r['created_at'] ?? null,
        'resolved_at' => $r['resolved_at'] ?? null,
        'source'      => $r['source_system'] ?? 'citizen',
        'verified'    => (int)($r['is_verified'] ?? 0),
    ];
}
foreach ($maintenance_rows as $r) {
    $all_rows[] = [
        'status'      => $r['status'] ?? 'pending',
        'priority'    => $r['priority'] ?? 'medium',
        'department'  => $r['department'] ?? 'Unknown',
        'report_type' => $r['report_type'] ?? 'Unknown',
        'created_at'  => $r['created_at'] ?? null,
        'resolved_at' => $r['resolved_at'] ?? null,
        'source'      => 'infrastructure',
        'verified'    => 0,
    ];
}

// ---- Aggregations (single pass over the analytics set) ----
$status_counts = ['pending' => 0, 'approved' => 0, 'in-progress' => 0, 'completed' => 0, 'cancelled' => 0, 'rejected' => 0];
$card_counts = ['pending' => 0, 'in-progress' => 0, 'completed' => 0];
$priority_counts = ['high' => 0, 'medium' => 0, 'low' => 0];
$department_counts = [];
$type_counts = [];
$monthly_counts = [];
$source_counts = ['lgu' => 0, 'citizen' => 0, 'cimm' => 0, 'infrastructure' => 0];
$verified_count = 0;
$completion_times = [];

foreach ($all_rows as $r) {
    $s = $r['status'];
    $status_counts[$s] = ($status_counts[$s] ?? 0) + 1;
    if (isset($card_counts[$s])) $card_counts[$s]++;

    $p = $r['priority'];
    $priority_counts[$p] = ($priority_counts[$p] ?? 0) + 1;

    $d = $r['department'];
    $department_counts[$d] = ($department_counts[$d] ?? 0) + 1;

    $t = $r['report_type'];
    $type_counts[$t] = ($type_counts[$t] ?? 0) + 1;

    $m = date('Y-m', strtotime($r['created_at'] ?: 'now'));
    $monthly_counts[$m] = ($monthly_counts[$m] ?? 0) + 1;

    $source_counts[$r['source']] = ($source_counts[$r['source']] ?? 0) + 1;

    if ($r['verified']) $verified_count++;

    if ($s === 'completed' && !empty($r['created_at']) && !empty($r['resolved_at'])) {
        $start = strtotime($r['created_at']);
        $end = strtotime($r['resolved_at']);
        if ($end > $start) {
            $completion_times[] = round(($end - $start) / 86400, 1);
        }
    }
}

// CIMM reports join the source split and the verified counter.
$source_counts['cimm'] += $cimm_total;
$verified_count += $cimm_verified;

$total_reports = count($all_rows) + $cimm_total;
$avg_resolution_days = !empty($completion_times) ? round(array_sum($completion_times) / count($completion_times), 1) : 0;

// ---- Monthly trend (last 12 months, reports created per month) ----
krsort($monthly_counts);
$monthly_labels = array_keys(array_slice($monthly_counts, 0, 12));
$monthly_data = array_values(array_slice($monthly_counts, 0, 12));
$monthly_labels = array_reverse($monthly_labels);
$monthly_data = array_reverse($monthly_data);

// ---- Friendly labels for report types and departments (raw values kept as fallback) ----
$type_labels_map = [
    'traffic' => 'Traffic', 'road_damage' => 'Road Damage', 'maintenance' => 'Maintenance',
    'infrastructure_issue' => 'Infrastructure Issue', 'maintenance_request' => 'Maintenance Request',
    'monthly' => 'Monthly', 'safety' => 'Safety', 'budget' => 'Budget',
    'traffic_violation' => 'Traffic Violation', 'routine' => 'Routine', 'emergency' => 'Emergency',
    'preventive' => 'Preventive', 'corrective' => 'Corrective', 'scheduled' => 'Scheduled',
    'accident' => 'Accident', 'congestion' => 'Congestion', 'pothole' => 'Pothole',
    'traffic_light_outage' => 'Traffic Light Outage',
];
function friendly_type_label($t) {
    global $type_labels_map;
    return $type_labels_map[$t] ?? ucwords(str_replace('_', ' ', $t));
}
$type_labels = array_map('friendly_type_label', array_keys($type_counts));

$dept_labels_map = [
    'engineering' => 'Engineering', 'maintenance' => 'Maintenance', 'planning' => 'Planning',
    'finance' => 'Finance', 'cimm' => 'CIMM', 'transport' => 'Road & Transportation',
    'road' => 'Road', 'transportation' => 'Transportation',
];
function friendly_dept_label($d) {
    global $dept_labels_map;
    return $dept_labels_map[$d] ?? ucfirst($d);
}
$department_labels = array_map('friendly_dept_label', array_keys($department_counts));

// ---- Department performance summary ----
$dept_perf = [];
foreach ($all_rows as $r) {
    $d = $r['department'];
    if (!isset($dept_perf[$d])) $dept_perf[$d] = ['total' => 0, 'pending' => 0, 'in-progress' => 0, 'completed' => 0];
    $dept_perf[$d]['total']++;
    $s = $r['status'];
    if (isset($dept_perf[$d][$s])) $dept_perf[$d][$s]++;
}
uasort($dept_perf, function ($a, $b) { return $b['total'] - $a['total']; });

$department_colors = ['#3762c8', '#059669', '#d97706', '#dc2626', '#8b5cf6', '#ec4899', '#06b6d4', '#f97316'];
$type_colors = ['#3762c8', '#059669', '#d97706', '#dc2626', '#8b5cf6', '#ec4899', '#06b6d4', '#f97316'];
$status_colors = ['#d97706', '#3b82f6', '#8b5cf6', '#059669', '#dc2626', '#ef4444'];
$source_colors = ['#3762c8', '#06b6d4', '#d97706', '#059669'];

log_audit_action($user_id, "Viewed analytics dashboard", "Period: {$period} days");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard - LGU Road Monitoring</title>
    <link rel="icon" type="image/png" href="../../assets/img/infra-gov-logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/theme-tokens.css">
    <link rel="stylesheet" href="../../css/theme-utilities.css">
    <link rel="stylesheet" href="../../css/sidebar.css?v=6">
    <link rel="stylesheet" href="../../css/enhanced-reports.css">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        <?php if (in_array($user_role, ['road_monitoring_officer', 'road_ops_supervisor', 'trans_ops_supervisor', 'trans_monitoring_officer'], true) && !empty($_SESSION['darkmode'])): ?>
        body.dark-mode {
            --text-primary: #f8fafc;
            --text-secondary: #cbd5e1;
        }
        <?php endif; ?>

        /* ── Analytics: light dashboard look (match Public Transparency) ── */
        body { background: #f7f5f0; min-height: 100vh; color: #1e293b; }
        body.dark-mode { background: var(--bg-page); }

        .analytics-main {
            margin-left: 250px;
            padding: 24px 28px 40px !important;
            max-width: 100%;
            overflow-x: hidden;
        }

        .analytics-main .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04), 0 8px 24px rgba(30, 60, 114, 0.06);
            border-radius: 16px;
            padding: 20px 24px;
            margin-bottom: 22px;
            gap: 14px;
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

        .analytics-main .page-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
        }

        .analytics-main .header-icon {
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

        .analytics-main .page-header h1 {
            font-size: 22px;
            font-weight: 700;
            color: #1e3c72;
            margin: 0 0 2px;
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 0;
        }

        .analytics-main .page-header h1 i { display: none; }

        .analytics-main .page-header p {
            margin: 0;
            color: #64748b;
            font-size: 13px;
        }

        .analytics-main .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .analytics-main .period-select {
            padding: 9px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #f8fafc;
            color: #1e3c72;
            font-size: 13px;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            min-width: 140px;
            cursor: pointer;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .analytics-main .period-select:focus {
            outline: none;
            border-color: #3762c8;
            box-shadow: 0 0 0 3px rgba(55, 98, 200, 0.12);
            background: #fff;
        }

        .analytics-main .btn {
            padding: 9px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
        }

        .analytics-main .btn-outline {
            background: #fff;
            border: 1px solid #e2e8f0;
            color: #475569;
            box-shadow: none;
        }

        .analytics-main .btn-outline:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #1e3c72;
            transform: translateY(-1px);
        }

        .analytics-main .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }

        .analytics-main .stat-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 16px 18px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04), 0 6px 18px rgba(30, 60, 114, 0.05);
            position: relative;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .analytics-main .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: #3762c8;
        }

        .analytics-main .stat-card:nth-child(2)::before { background: #f59e0b; }
        .analytics-main .stat-card:nth-child(3)::before { background: #7c3aed; }
        .analytics-main .stat-card:nth-child(4)::before { background: #10b981; }
        .analytics-main .stat-card:nth-child(5)::before { background: #0ea5e9; }
        .analytics-main .stat-card:nth-child(6)::before { background: #64748b; }

        .analytics-main .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(30, 60, 114, 0.08);
        }

        .analytics-main .stat-card .stat-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            font-size: 14px;
            margin-bottom: 10px;
            box-shadow: none;
        }

        .analytics-main .t-stat-icon-blue {
            background: rgba(55, 98, 200, 0.12) !important;
            color: #3762c8 !important;
        }
        .analytics-main .t-stat-icon-amber {
            background: rgba(245, 158, 11, 0.14) !important;
            color: #d97706 !important;
        }
        .analytics-main .t-stat-icon-purple {
            background: rgba(124, 58, 237, 0.12) !important;
            color: #7c3aed !important;
        }
        .analytics-main .t-stat-icon-green {
            background: rgba(16, 185, 129, 0.14) !important;
            color: #059669 !important;
        }
        .analytics-main .t-stat-icon-info {
            background: rgba(14, 165, 233, 0.12) !important;
            color: #0284c7 !important;
        }
        .analytics-main .t-stat-icon-cimm {
            background: rgba(100, 116, 139, 0.12) !important;
            color: #475569 !important;
        }

        .analytics-main .stat-card .stat-value {
            font-size: 26px;
            font-weight: 700;
            color: #1e3c72;
            letter-spacing: -0.03em;
            margin-bottom: 2px;
        }

        .analytics-main .stat-card .stat-value small {
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
        }

        .analytics-main .stat-card .stat-label {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
        }

        .analytics-main .stat-card .stat-trend {
            font-size: 11px;
            margin-top: 8px;
            color: #94a3b8;
        }

        .analytics-main .stat-trend.up { color: #059669; }
        .analytics-main .stat-trend.down { color: #d97706; }

        .analytics-main .chart-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
            gap: 16px;
        }

        .analytics-main .chart-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 18px 18px 16px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04), 0 6px 18px rgba(30, 60, 114, 0.05);
            transition: box-shadow 0.2s ease;
        }

        .analytics-main .chart-card:hover {
            box-shadow: 0 8px 24px rgba(30, 60, 114, 0.08);
        }

        .analytics-main .chart-card h4 {
            font-size: 14px;
            font-weight: 700;
            color: #1e3c72;
            margin: 0 0 14px;
            padding-bottom: 12px;
            border-bottom: 1px solid #eef2f7;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .analytics-main .chart-card h4 i {
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

        .analytics-main .chart-container {
            height: 260px;
        }

        .analytics-main .panel {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04), 0 6px 18px rgba(30, 60, 114, 0.05);
            margin-top: 22px !important;
            margin-bottom: 0;
            overflow: hidden;
            position: relative;
        }

        .analytics-main .panel::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: #3762c8;
        }

        .analytics-main .panel-header {
            padding: 14px 18px 14px 20px;
            background: #f8fafc;
            border-bottom: 1px solid #eef2f7;
        }

        .analytics-main .panel-title {
            font-size: 15px;
            font-weight: 700;
            color: #1e3c72;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .analytics-main .panel-title i {
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

        .analytics-main .panel-body {
            padding: 8px 4px 4px;
        }

        .analytics-main .data-table th {
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            color: #64748b;
            font-size: 11px;
            padding: 12px 16px;
        }

        .analytics-main .data-table td {
            padding: 12px 16px;
            font-size: 13px;
            border-bottom: 1px solid #f1f5f9;
        }

        .analytics-main .data-table tr:last-child td {
            border-bottom: none;
        }

        .analytics-main .data-table tr:hover td {
            background: #f8fafc;
        }

        .analytics-main .rate-track {
            flex: 1;
            height: 6px;
            background: #e2e8f0;
            border-radius: 999px;
            max-width: 100px;
            overflow: hidden;
        }

        .analytics-main .rate-fill {
            height: 100%;
            border-radius: 999px;
        }

        <?php if ($is_system_admin): ?>
        .analytics-main .page-header h1 { color: #1e3c72; }
        .analytics-main .stat-card .stat-value { color: #1e3c72; }
        .analytics-main .panel-title { color: #1e3c72; }
        <?php endif; ?>

        body.dark-mode .analytics-main { color: #e4e6ea; }

        body.dark-mode .analytics-main .page-header,
        body.dark-mode .analytics-main .stat-card,
        body.dark-mode .analytics-main .chart-card,
        body.dark-mode .analytics-main .panel {
            background: #1a1d24 !important;
            border-color: #2d323b !important;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.35) !important;
        }

        body.dark-mode .analytics-main .page-header h1,
        body.dark-mode .analytics-main .stat-card .stat-value,
        body.dark-mode .analytics-main .panel-title,
        body.dark-mode .analytics-main .chart-card h4,
        body.dark-mode .analytics-main .data-table td,
        body.dark-mode .analytics-main .data-table td strong {
            color: #e4e6ea !important;
        }

        body.dark-mode .analytics-main .page-header p,
        body.dark-mode .analytics-main .stat-card .stat-label,
        body.dark-mode .analytics-main .stat-card .stat-trend,
        body.dark-mode .analytics-main .stat-card .stat-value small,
        body.dark-mode .analytics-main .data-table th {
            color: #b0b7c3 !important;
        }

        body.dark-mode .analytics-main .chart-card h4 {
            border-bottom-color: #2d323b !important;
        }

        body.dark-mode .analytics-main .chart-card h4 i,
        body.dark-mode .analytics-main .panel-title i {
            background: rgba(96, 165, 250, 0.18) !important;
            color: #93c5fd !important;
        }

        body.dark-mode .analytics-main .panel-header,
        body.dark-mode .analytics-main .data-table th {
            background: #1e2229 !important;
            border-color: #2d323b !important;
        }

        body.dark-mode .analytics-main .data-table td {
            border-bottom-color: #2d323b !important;
        }

        body.dark-mode .analytics-main .data-table tr:hover td {
            background: #22262e !important;
        }

        body.dark-mode .analytics-main .period-select {
            background: #22262e !important;
            border-color: #374151 !important;
            color: #e4e6ea !important;
            color-scheme: dark;
        }

        body.dark-mode .analytics-main .period-select option {
            background: #1a1d24;
            color: #e4e6ea;
        }

        body.dark-mode .analytics-main .btn-outline {
            background: #22262e !important;
            border-color: #374151 !important;
            color: #e4e6ea !important;
        }

        body.dark-mode .analytics-main .btn-outline:hover {
            background: #2a2f38 !important;
            border-color: #60a5fa !important;
            color: #f3f4f6 !important;
        }

        body.dark-mode .analytics-main .header-icon {
            background: rgba(96, 165, 250, 0.2) !important;
            color: #93c5fd !important;
        }

        body.dark-mode .analytics-main .rate-track {
            background: #374151 !important;
        }

        @media (max-width: 768px) {
            .analytics-main {
                margin-left: 0;
                padding: 16px !important;
            }
            .analytics-main .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .analytics-main .chart-grid {
                grid-template-columns: 1fr;
            }
            .analytics-main .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media print {
            body > aside.sidebar,
            body > nav,
            .sidebar,
            .header-actions,
            .page-header .header-actions,
            .print-hide { display: none !important; }
            .analytics-main { margin-left: 0 !important; padding: 0 !important; }
            body { background: #fff !important; }
            .chart-card, .stat-card, .panel { break-inside: avoid; page-break-inside: avoid; }
        }
    </style>
    <?php if ($is_trans_ops_supervisor): ?>
    <!-- Transport Operations Supervisor only: fit all six analytics stat
         cards on phones in TWO rows of three. The generic mobile rules
         collapse the grid to 2x3; use a fixed 3-column grid with compact
         tiles instead (trend lines hidden on small screens to fit). UI-only
         CSS scoping — other portals are unaffected and no behaviour changes. -->
    <style>
        @media (max-width: 768px) {
            body.trans-supervisor-view .analytics-main .stats-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 8px;
                margin-bottom: 14px;
            }
            body.trans-supervisor-view .analytics-main .stat-card {
                padding: 8px 5px;
                border-radius: 10px;
                min-width: 0;
                max-width: 100%;
                box-sizing: border-box;
            }
            body.trans-supervisor-view .analytics-main .stat-card::before { height: 2px; }
            body.trans-supervisor-view .analytics-main .stat-card .stat-icon {
                width: 20px;
                height: 20px;
                border-radius: 6px;
                font-size: 9px;
                margin-bottom: 4px;
            }
            body.trans-supervisor-view .analytics-main .stat-card .stat-value { font-size: 13px; }
            body.trans-supervisor-view .analytics-main .stat-card .stat-value small { font-size: 8px; }
            body.trans-supervisor-view .analytics-main .stat-card .stat-label {
                font-size: 7.5px;
                line-height: 1.25;
                overflow-wrap: anywhere;
                word-break: break-word;
            }
            body.trans-supervisor-view .analytics-main .stat-card .stat-trend { display: none; }
        }
    </style>
    <?php endif; ?>
    <?php if ($is_road_monitoring_officer): ?>
    <!-- Road Monitoring Officer only: fit all six analytics stat cards on
         phones in a 3x2 layout (three columns, two rows). The generic mobile
         rules collapse the grid to 2x3; use a fixed 3-column grid with
         compact tiles instead (trend lines hidden on small screens to fit).
         Mirrors the trans_ops_supervisor treatment. UI-only CSS scoping —
         other portals are unaffected and no behaviour changes. -->
    <style>
        @media (max-width: 768px) {
            body.rmo-view .analytics-main .stats-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 8px;
                margin-bottom: 14px;
            }
            body.rmo-view .analytics-main .stat-card {
                padding: 8px 5px;
                border-radius: 10px;
                min-width: 0;
                max-width: 100%;
                box-sizing: border-box;
            }
            body.rmo-view .analytics-main .stat-card::before { height: 2px; }
            body.rmo-view .analytics-main .stat-card .stat-icon {
                width: 20px;
                height: 20px;
                border-radius: 6px;
                font-size: 9px;
                margin-bottom: 4px;
            }
            body.rmo-view .analytics-main .stat-card .stat-value { font-size: 13px; }
            body.rmo-view .analytics-main .stat-card .stat-value small { font-size: 8px; }
            body.rmo-view .analytics-main .stat-card .stat-label {
                font-size: 7.5px;
                line-height: 1.25;
                overflow-wrap: anywhere;
                word-break: break-word;
            }
            body.rmo-view .analytics-main .stat-card .stat-trend { display: none; }

            /* Road Monitoring Officer only: two chart cards per row instead of
               the generic single-column stack. Cards and chart containers are
               compacted so both columns stay readable on phones. */
            body.rmo-view .analytics-main .chart-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }
            body.rmo-view .analytics-main .chart-card {
                padding: 10px 8px;
                min-width: 0;
                max-width: 100%;
                box-sizing: border-box;
            }
            body.rmo-view .analytics-main .chart-card h4 {
                font-size: 11px;
                margin-bottom: 8px;
                padding-bottom: 8px;
                gap: 6px;
            }
            body.rmo-view .analytics-main .chart-card h4 i {
                width: 20px;
                height: 20px;
                border-radius: 6px;
                font-size: 9px;
            }
            body.rmo-view .analytics-main .chart-container { height: 150px; }
            body.rmo-view .analytics-main .chart-card canvas { max-width: 100%; }
        }
    </style>
    <?php endif; ?>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?><?php echo $is_trans_ops_supervisor ? ' trans-supervisor-view' : ''; ?><?php echo $is_road_monitoring_officer ? ' rmo-view' : ''; ?>">
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="analytics-main main-content">
        <div class="page-header">
            <div class="page-header-left">
                <div class="header-icon"><i class="fas fa-chart-pie"></i></div>
                <div>
                    <h1><i class="fas fa-chart-pie"></i> Analytics Dashboard</h1>
                    <p>Comprehensive data analysis and reporting insights</p>
                </div>
            </div>
            <div class="header-actions print-hide">
                <div class="dt-chip">
                    <i class="fas fa-calendar-day"></i>
                    <div>
                        <div id="currentDate"></div>
                        <div id="currentTime"></div>
                    </div>
                </div>
                <select id="period-select" class="period-select" name="period" onchange="window.location='?period='+this.value">
                    <option value="7" <?php echo $period === '7' ? 'selected' : ''; ?>>Last 7 days</option>
                    <option value="30" <?php echo $period === '30' ? 'selected' : ''; ?>>Last 30 days</option>
                    <option value="90" <?php echo $period === '90' ? 'selected' : ''; ?>>Last 90 days</option>
                    <option value="365" <?php echo $period === '365' ? 'selected' : ''; ?>>Last year</option>
                    <option value="all" <?php echo $period === 'all' ? 'selected' : ''; ?>>All time</option>
                </select>
                <button class="btn btn-outline" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon t-stat-icon-blue">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="stat-value"><?php echo number_format($total_reports); ?></div>
                <div class="stat-label">Total Reports</div>
                <div class="stat-trend neutral">
                    <i class="fas fa-layer-group"></i> All report sources
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon t-stat-icon-amber">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <div class="stat-value"><?php echo number_format($card_counts['pending']); ?></div>
                <div class="stat-label">Pending Reports</div>
                <div class="stat-trend down">
                    <i class="fas fa-clock"></i> Awaiting action
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon t-stat-icon-purple">
                    <i class="fas fa-spinner"></i>
                </div>
                <div class="stat-value"><?php echo number_format($card_counts['in-progress']); ?></div>
                <div class="stat-label">In Progress</div>
                <div class="stat-trend neutral">
                    <i class="fas fa-tasks"></i> Currently being handled
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon t-stat-icon-green">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo number_format($card_counts['completed']); ?></div>
                <div class="stat-label">Completed</div>
                <div class="stat-trend up">
                    <i class="fas fa-arrow-up"></i> <?php echo $total_reports > 0 ? round(($card_counts['completed']) / $total_reports * 100) : 0; ?>% completion rate
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon t-stat-icon-info">
                    <i class="fas fa-clipboard-check"></i>
                </div>
                <div class="stat-value"><?php echo number_format($verified_count); ?></div>
                <div class="stat-label">Verified Reports</div>
                <div class="stat-trend neutral">
                    <i class="fas fa-shield-alt"></i> CIMM verified
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon t-stat-icon-cimm">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-value"><?php echo $avg_resolution_days > 0 ? number_format($avg_resolution_days, 1) : '0'; ?> <small>days</small></div>
                <div class="stat-label">Average Resolution Time</div>
                <div class="stat-trend neutral">
                    <i class="fas fa-stopwatch"></i> From creation to completion
                </div>
            </div>
        </div>

        <div class="chart-grid">
            <div class="chart-card">
                <h4><i class="fas fa-chart-line"></i> Report Trends (Monthly)</h4>
                <div class="chart-container">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
            <div class="chart-card">
                <h4><i class="fas fa-chart-bar"></i> Reports by Status</h4>
                <div class="chart-container">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
            <div class="chart-card">
                <h4><i class="fas fa-chart-pie"></i> Reports by Priority</h4>
                <div class="chart-container">
                    <canvas id="priorityChart"></canvas>
                </div>
            </div>
            <div class="chart-card">
                <h4><i class="fas fa-chart-doughnut"></i> Reports by Department</h4>
                <div class="chart-container">
                    <canvas id="departmentChart"></canvas>
                </div>
            </div>
            <div class="chart-card">
                <h4><i class="fas fa-chart-bar"></i> Reports by Type</h4>
                <div class="chart-container">
                    <canvas id="typeChart"></canvas>
                </div>
            </div>
            <div class="chart-card">
                <h4><i class="fas fa-share-alt"></i> Reports by Source</h4>
                <div class="chart-container">
                    <canvas id="sourceChart"></canvas>
                </div>
            </div>
        </div>

        <div class="panel" style="margin-top: 28px;">
            <div class="panel-header">
                <h3 class="panel-title"><i class="fas fa-table"></i> Department Performance Summary</h3>
            </div>
            <div class="panel-body">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Total Reports</th>
                            <th>Pending</th>
                            <th>In Progress</th>
                            <th>Completed</th>
                            <th>Completion Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($dept_perf)): ?>
                            <tr><td colspan="6" style="text-align:center;color:var(--text-secondary);padding:24px;">No data available for the selected period.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($dept_perf as $dept => $d):
                            $rate = $d['total'] > 0 ? round($d['completed'] / $d['total'] * 100) : 0;
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars(friendly_dept_label($dept)); ?></strong></td>
                            <td><?php echo $d['total']; ?></td>
                            <td><?php echo $d['pending']; ?></td>
                            <td><?php echo $d['in-progress']; ?></td>
                            <td><?php echo $d['completed']; ?></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <div class="rate-track">
                                        <div class="rate-fill" style="width:<?php echo $rate; ?>%;background:<?php echo $rate > 70 ? '#10b981' : ($rate > 40 ? '#f59e0b' : '#ef4444'); ?>;"></div>
                                    </div>
                                    <span style="font-size:12px;font-weight:600;color:#1e3c72;"><?php echo $rate; ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="../../js/enhanced-reports.js"></script>
    <script>
        const isDark = document.body.classList.contains('dark-mode');
        const textColor = isDark ? '#9ca3af' : '#64748b';
        const gridColor = isDark ? '#2d323b' : '#e2e8f0';
        const tooltipBg = isDark ? '#2a2e36' : '#ffffff';
        const tooltipColor = isDark ? '#e4e6ea' : '#1e293b';

        const chartDefaults = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { labels: { color: textColor, font: { family: 'Poppins', size: 11 } } },
                tooltip: {
                    backgroundColor: tooltipBg,
                    titleColor: tooltipColor,
                    bodyColor: tooltipColor,
                    borderColor: gridColor,
                    borderWidth: 1,
                    cornerRadius: 6,
                    displayColors: true
                }
            }
        };

        const scaleDefaults = {
            x: { ticks: { color: textColor, font: { family: 'Poppins', size: 10 } }, grid: { color: gridColor } },
            y: { ticks: { color: textColor, font: { family: 'Poppins', size: 10 } }, grid: { color: gridColor }, beginAtZero: true }
        };

        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode($monthly_labels ?: []); ?>,
                datasets: [{
                    label: 'Reports Created',
                    data: <?php echo json_encode($monthly_data ?: []); ?>,
                    borderColor: '#3762c8',
                    backgroundColor: 'rgba(55,98,200,0.1)',
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#3762c8',
                    pointRadius: 4
                }]
            },
            options: Object.assign({}, chartDefaults, { scales: scaleDefaults, plugins: Object.assign({}, chartDefaults.plugins, { legend: { display: false } }) })
        });

        new Chart(document.getElementById('statusChart'), {
            type: 'bar',
            data: {
                labels: ['Pending', 'Approved', 'In Progress', 'Completed', 'Cancelled', 'Rejected'],
                datasets: [{
                    label: 'Reports',
                    data: [
                        <?php echo $status_counts['pending'] ?? 0; ?>,
                        <?php echo $status_counts['approved'] ?? 0; ?>,
                        <?php echo $status_counts['in-progress'] ?? 0; ?>,
                        <?php echo $status_counts['completed'] ?? 0; ?>,
                        <?php echo $status_counts['cancelled'] ?? 0; ?>,
                        <?php echo $status_counts['rejected'] ?? 0; ?>
                    ],
                    backgroundColor: <?php echo json_encode($status_colors); ?>,
                    borderRadius: 4
                }]
            },
            options: Object.assign({}, chartDefaults, { scales: scaleDefaults, plugins: Object.assign({}, chartDefaults.plugins, { legend: { display: false } }) })
        });

        new Chart(document.getElementById('priorityChart'), {
            type: 'pie',
            data: {
                labels: ['High', 'Medium', 'Low'],
                datasets: [{
                    data: [
                        <?php echo $priority_counts['high'] ?? 0; ?>,
                        <?php echo $priority_counts['medium'] ?? 0; ?>,
                        <?php echo $priority_counts['low'] ?? 0; ?>
                    ],
                    backgroundColor: ['#dc2626', '#d97706', '#059669'],
                    borderWidth: 0
                }]
            },
            options: chartDefaults
        });

        new Chart(document.getElementById('departmentChart'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($department_labels ?: []); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_values($department_counts) ?: []); ?>,
                    backgroundColor: <?php echo json_encode(array_slice($department_colors, 0, count($department_counts) ?: 1)); ?>,
                    borderWidth: 0
                }]
            },
            options: chartDefaults
        });

        new Chart(document.getElementById('typeChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($type_labels ?: []); ?>,
                datasets: [{
                    label: 'Count',
                    data: <?php echo json_encode(array_values($type_counts) ?: []); ?>,
                    backgroundColor: <?php echo json_encode(array_slice($type_colors, 0, count($type_counts) ?: 1)); ?>,
                    borderRadius: 4
                }]
            },
            options: Object.assign({}, chartDefaults, {
                scales: scaleDefaults,
                indexAxis: 'y',
                plugins: Object.assign({}, chartDefaults.plugins, { legend: { display: false } })
            })
        });

        new Chart(document.getElementById('sourceChart'), {
            type: 'doughnut',
            data: {
                labels: ['LGU Monitoring', 'Citizen', 'CIMM', 'Infrastructure'],
                datasets: [{
                    data: [
                        <?php echo $source_counts['lgu'] ?? 0; ?>,
                        <?php echo $source_counts['citizen'] ?? 0; ?>,
                        <?php echo $source_counts['cimm'] ?? 0; ?>,
                        <?php echo $source_counts['infrastructure'] ?? 0; ?>
                    ],
                    backgroundColor: <?php echo json_encode($source_colors); ?>,
                    borderWidth: 0
                }]
            },
            options: Object.assign({}, chartDefaults, { cutout: '60%' })
        });

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
