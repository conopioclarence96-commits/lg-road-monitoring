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

$period = sanitize_input($_GET['period'] ?? '30');

$transport_reports = fetch_all("SELECT * FROM road_transportation_reports ORDER BY created_at DESC");
$maintenance_reports = fetch_all("SELECT * FROM road_maintenance_reports ORDER BY created_at DESC");
$all_reports = array_merge($transport_reports ?: [], $maintenance_reports ?: []);

$total_reports = count($all_reports);
$total_transport = count($transport_reports ?: []);
$total_maintenance = count($maintenance_reports ?: []);

$status_counts = ['pending' => 0, 'in-progress' => 0, 'completed' => 0, 'cancelled' => 0];
$priority_counts = ['high' => 0, 'medium' => 0, 'low' => 0];
$department_counts = [];
$type_counts = [];
$monthly_counts = [];

foreach ($all_reports as $r) {
    $s = $r['status'] ?? 'pending';
    $status_counts[$s] = ($status_counts[$s] ?? 0) + 1;
    
    $p = $r['priority'] ?? 'medium';
    $priority_counts[$p] = ($priority_counts[$p] ?? 0) + 1;
    
    $d = $r['department'] ?? 'Unknown';
    $department_counts[$d] = ($department_counts[$d] ?? 0) + 1;
    
    $t = $r['report_type'] ?? 'Unknown';
    $type_counts[$t] = ($type_counts[$t] ?? 0) + 1;
    
    $month = date('Y-m', strtotime($r['created_at'] ?? $r['created_date']));
    $monthly_counts[$month] = ($monthly_counts[$month] ?? 0) + 1;
}

krsort($monthly_counts);
$monthly_labels = array_keys(array_slice($monthly_counts, 0, 12));
$monthly_data = array_values(array_slice($monthly_counts, 0, 12));
$monthly_labels = array_reverse($monthly_labels);
$monthly_data = array_reverse($monthly_data);

$completion_times = [];
foreach ($all_reports as $r) {
    if (($r['status'] ?? '') === 'completed' && !empty($r['created_at']) && !empty($r['updated_at'])) {
        $created = strtotime($r['created_at']);
        $updated = strtotime($r['updated_at']);
        if ($updated > $created) {
            $completion_times[] = round(($updated - $created) / 86400, 1);
        }
    }
}
$avg_completion_days = !empty($completion_times) ? round(array_sum($completion_times) / count($completion_times), 1) : 0;

$estimation_total = 0;
$estimation_count = 0;
foreach ($all_reports as $r) {
    if (!empty($r['estimation']) && $r['estimation'] > 0) {
        $estimation_total += floatval($r['estimation']);
        $estimation_count++;
    }
}
$avg_estimation = $estimation_count > 0 ? round($estimation_total / $estimation_count, 2) : 0;

$department_colors = ['#3762c8', '#059669', '#d97706', '#dc2626', '#8b5cf6', '#ec4899', '#06b6d4', '#f97316'];
$type_colors = ['#3762c8', '#059669', '#d97706', '#dc2626', '#8b5cf6', '#ec4899'];

log_audit_action($user_id, "Viewed analytics dashboard", "Period: {$period} days");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard - LGU Road Monitoring</title>
    <link rel="icon" type="image/png" href="/lg-road-monitoring/assets/img/logocityhall.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/enhanced-reports.css">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <iframe src="../../includes/sidebar.php" 
            style="position: fixed; width: 250px; height: 100vh; border: none; z-index: 1000;" 
            frameborder="0" name="sidebar-frame" scrolling="no" loading="lazy">
    </iframe>

    <div style="margin-left: 250px; padding: 28px; position: relative; z-index: 1;">
        <div class="page-header">
            <div>
                <h1><i class="fas fa-chart-pie"></i> Analytics Dashboard</h1>
                <p>Comprehensive data analysis and reporting insights</p>
            </div>
            <div class="header-actions">
                <select onchange="window.location='?period='+this.value" style="padding:8px 12px;border:1px solid var(--border);border-radius:6px;background:var(--card-bg);color:var(--text-primary);font-size:13px;">
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
                <div class="stat-icon" style="background: linear-gradient(135deg, #3762c8, #1e3c72);">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="stat-value"><?php echo number_format($total_reports); ?></div>
                <div class="stat-label">Total Reports</div>
                <div class="stat-trend neutral">
                    <i class="fas fa-layer-group"></i> <?php echo $total_transport; ?> Transport / <?php echo $total_maintenance; ?> Maintenance
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #059669, #047857);">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo number_format($status_counts['completed'] ?? 0); ?></div>
                <div class="stat-label">Completed</div>
                <div class="stat-trend up">
                    <i class="fas fa-arrow-up"></i> 
                    <?php echo $total_reports > 0 ? round(($status_counts['completed'] ?? 0) / $total_reports * 100) : 0; ?>% completion rate
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #d97706, #b45309);">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo number_format($status_counts['pending'] ?? 0); ?></div>
                <div class="stat-label">Pending</div>
                <div class="stat-trend down">
                    <i class="fas fa-hourglass-half"></i> Awaiting action
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #0284c7, #0369a1);">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="stat-value"><?php echo $avg_completion_days; ?>d</div>
                <div class="stat-label">Avg. Completion Time</div>
                <div class="stat-trend neutral">
                    <i class="fas fa-chart-line"></i> Across <?php echo count($completion_times); ?> completed reports
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                    <i class="fas fa-peso-sign"></i>
                </div>
                <div class="stat-value"><?php echo $avg_estimation > 0 ? '₱' . number_format($avg_estimation, 0) : 'N/A'; ?></div>
                <div class="stat-label">Avg. Cost Estimate</div>
                <div class="stat-trend neutral">
                    <i class="fas fa-calculator"></i> Total: ₱<?php echo number_format($estimation_total, 2); ?>
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
                <h4><i class="fas fa-flag"></i> Status Overview</h4>
                <div class="chart-container">
                    <canvas id="overviewChart"></canvas>
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
                        <?php 
                        $dept_perf = [];
                        foreach ($all_reports as $r) {
                            $d = $r['department'] ?? 'Unknown';
                            if (!isset($dept_perf[$d])) {
                                $dept_perf[$d] = ['total' => 0, 'pending' => 0, 'in-progress' => 0, 'completed' => 0];
                            }
                            $dept_perf[$d]['total']++;
                            $s = $r['status'] ?? 'pending';
                            if (isset($dept_perf[$d][$s])) $dept_perf[$d][$s]++;
                        }
                        foreach ($dept_perf as $dept => $d):
                            $rate = $d['total'] > 0 ? round($d['completed'] / $d['total'] * 100) : 0;
                        ?>
                        <tr>
                            <td><strong><?php echo ucfirst($dept); ?></strong></td>
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
    </div>

    <script src="../../js/enhanced-reports.js"></script>
    <script>
        const isDark = document.body.classList.contains('dark-mode');
        const textColor = isDark ? '#9ca3af' : '#64748b';
        const gridColor = isDark ? '#2d323b' : '#e2e8f0';

        const chartDefaults = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { labels: { color: textColor, font: { family: 'Poppins', size: 11 } } }
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
                labels: ['Pending', 'In Progress', 'Completed', 'Cancelled'],
                datasets: [{
                    label: 'Reports',
                    data: [
                        <?php echo $status_counts['pending'] ?? 0; ?>,
                        <?php echo $status_counts['in-progress'] ?? 0; ?>,
                        <?php echo $status_counts['completed'] ?? 0; ?>,
                        <?php echo $status_counts['cancelled'] ?? 0; ?>
                    ],
                    backgroundColor: ['#d97706', '#2563eb', '#059669', '#dc2626'],
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
                labels: <?php echo json_encode(array_keys($department_counts) ?: []); ?>,
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
                labels: <?php echo json_encode(array_keys($type_counts) ?: []); ?>,
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

        new Chart(document.getElementById('overviewChart'), {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'In Progress', 'Completed', 'Cancelled'],
                datasets: [{
                    data: [
                        <?php echo $status_counts['pending'] ?? 0; ?>,
                        <?php echo $status_counts['in-progress'] ?? 0; ?>,
                        <?php echo $status_counts['completed'] ?? 0; ?>,
                        <?php echo $status_counts['cancelled'] ?? 0; ?>
                    ],
                    backgroundColor: ['#d97706', '#2563eb', '#059669', '#dc2626'],
                    borderWidth: 0
                }]
            },
            options: Object.assign({}, chartDefaults, { cutout: '60%' })
        });
    </script>

    <div class="page-transition-overlay" id="pageTransitionOverlay">
        <div class="transition-content">
            <div class="transition-spinner"><i class="fas fa-spinner"></i></div>
            <div class="transition-text">Loading...</div>
        </div>
    </div>
</body>
</html>
