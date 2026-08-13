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
    <link rel="icon" type="image/png" href="../../assets/img/logocityhall.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/theme-tokens.css">
    <link rel="stylesheet" href="../../css/theme-utilities.css">
    <link rel="stylesheet" href="../../css/sidebar.css?v=2">
    <link rel="stylesheet" href="../../css/enhanced-reports.css">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        <?php if (in_array($user_role, ['road_monitoring_officer', 'road_ops_supervisor'], true) && !empty($_SESSION['darkmode'])): ?>
        body.dark-mode {
            --text-primary: #f8fafc;
            --text-secondary: #cbd5e1;
        }
        <?php endif; ?>
        <?php if ($is_system_admin): ?>
        /* Match the admin portal font colors (system_admin only) */
        .page-header h1 { color: #1e3c72; }
        .stat-card .stat-value { color: #1e3c72; }
        .panel-title { color: #1e3c72; }
        body.dark-mode .page-header h1 { color: #e4e6ea !important; }
        body.dark-mode .page-header p { color: #9ca3af !important; }
        body.dark-mode .stat-card .stat-value { color: #e4e6ea !important; }
        body.dark-mode .stat-card .stat-label { color: #9ca3af !important; }
        body.dark-mode .chart-card h4 { color: #9ca3af !important; }
        body.dark-mode .panel-title { color: #e4e6ea !important; }
        body.dark-mode .panel-body .data-table th { color: #9ca3af !important; }
        body.dark-mode .panel-body .data-table td { color: #e4e6ea !important; }
        body.dark-mode .stat-card .stat-trend { color: #9ca3af !important; }
        <?php endif; ?>
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
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="analytics-main main-content" style="padding: 28px; position: relative; z-index: 1;">
        <div class="page-header">
            <div>
                <h1><i class="fas fa-chart-pie"></i> Analytics Dashboard</h1>
                <p>Comprehensive data analysis and reporting insights</p>
            </div>
            <div class="header-actions print-hide">
                <select id="period-select" name="period" onchange="window.location='?period='+this.value" style="padding:8px 12px;border:1px solid var(--border);border-radius:6px;background:var(--card-bg);color:var(--text-primary);font-size:13px;">
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
                                    <div style="flex:1;height:6px;background:var(--border);border-radius:3px;max-width:100px;">
                                        <div style="width:<?php echo $rate; ?>%;height:100%;background:<?php echo $rate > 70 ? '#059669' : ($rate > 40 ? '#d97706' : '#dc2626'); ?>;border-radius:3px;"></div>
                                    </div>
                                    <span style="font-size:12px;font-weight:600;"><?php echo $rate; ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
    </script>


</body>
</html>
