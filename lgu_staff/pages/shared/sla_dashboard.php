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

$filter_status = sanitize_input($_GET['status'] ?? 'all');
$filter_dept = sanitize_input($_GET['department'] ?? 'all');

$transport_reports = fetch_all("SELECT * FROM road_transportation_reports ORDER BY created_at DESC");
$maintenance_reports = fetch_all("SELECT * FROM road_maintenance_reports ORDER BY created_at DESC");
$all_reports = array_merge($transport_reports ?: [], $maintenance_reports ?: []);

$sla_threshold_days = 7;
$warning_threshold_days = 14;
$overdue_threshold_days = 30;

$sla_data = [];
$overdue_count = 0;
$at_risk_count = 0;
$on_track_count = 0;
$resolved_count = 0;

foreach ($all_reports as $r) {
    $created = strtotime($r['created_at'] ?? $r['created_date']);
    $status = $r['status'] ?? 'pending';
    $dept = $r['department'] ?? 'Unknown';
    $title = $r['title'] ?? 'Untitled';
    $priority = $r['priority'] ?? 'medium';
    $report_id = $r['id'];
    $report_type = 'transportation';
    $r_id_display = $r['report_id'] ?? "ID:{$report_id}";
    
    if ($status === 'completed' || $status === 'cancelled') {
        $resolved_at = strtotime($r['updated_at'] ?? $r['resolved_date'] ?? 'now');
        $resolution_days = max(0, round(($resolved_at - $created) / 86400, 1));
        $sla_status = $resolution_days <= $sla_threshold_days ? 'on-track' : ($resolution_days <= $warning_threshold_days ? 'at-risk' : 'overdue');
        $resolved_count++;
        if ($sla_status === 'overdue') $overdue_count++;
        elseif ($sla_status === 'at-risk') $at_risk_count++;
        else $on_track_count++;
    } else {
        $elapsed_days = max(0, round((time() - $created) / 86400, 1));
        if ($elapsed_days > $overdue_threshold_days) {
            $sla_status = 'overdue';
            $overdue_count++;
        } elseif ($elapsed_days > $sla_threshold_days) {
            $sla_status = 'at-risk';
            $at_risk_count++;
        } else {
            $sla_status = 'on-track';
            $on_track_count++;
        }
        $resolution_days = $elapsed_days;
    }
    
    if ($filter_status !== 'all' && $sla_status !== $filter_status) continue;
    if ($filter_dept !== 'all' && $dept !== $filter_dept) continue;
    
    $sla_data[] = [
        'id' => $report_id,
        'report_id_display' => $r_id_display,
        'title' => $title,
        'department' => $dept,
        'priority' => $priority,
        'status' => $status,
        'sla_status' => $sla_status,
        'resolution_days' => $resolution_days,
        'created_at' => $r['created_at'] ?? $r['created_date'],
        'assigned_to' => $r['assigned_to'] ?? $r['maintenance_team'] ?? 'Unassigned',
        'report_type' => $report_type
    ];
}

$total_tracked = $on_track_count + $at_risk_count + $overdue_count;
$compliance_rate = $total_tracked > 0 ? round(($on_track_count + $resolved_count) / max(1, $total_tracked + $resolved_count) * 100, 1) : 0;

$departments = [];
foreach ($all_reports as $r) {
    $d = $r['department'] ?? 'Unknown';
    $departments[$d] = ($departments[$d] ?? 0) + 1;
}

usort($sla_data, function($a, $b) {
    $order = ['overdue' => 0, 'at-risk' => 1, 'on-track' => 2];
    $ao = $order[$a['sla_status']] ?? 3;
    $bo = $order[$b['sla_status']] ?? 3;
    if ($ao !== $bo) return $ao - $bo;
    return $b['resolution_days'] <=> $a['resolution_days'];
});

log_audit_action($user_id, "Viewed SLA dashboard", "Tracked reports: " . count($sla_data));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SLA Compliance - LGU Road Monitoring</title>
    <link rel="icon" type="image/png" href="../../assets/img/logocityhall.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/sidebar.css">
    <link rel="stylesheet" href="../../css/enhanced-reports.css">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div style="margin-left: 250px; padding: 28px; position: relative; z-index: 1;">
        <div class="page-header">
            <div>
                <h1><i class="fas fa-gavel"></i> SLA Compliance Dashboard</h1>
                <p>Track report resolution times and service level compliance</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-outline" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #059669, #047857);">
                    <i class="fas fa-check-shield"></i>
                </div>
                <div class="stat-value"><?php echo $compliance_rate; ?>%</div>
                <div class="stat-label">SLA Compliance Rate</div>
                <div class="stat-trend <?php echo $compliance_rate >= 80 ? 'up' : ($compliance_rate >= 50 ? 'neutral' : 'down'); ?>">
                    <i class="fas fa-<?php echo $compliance_rate >= 80 ? 'check-circle' : ($compliance_rate >= 50 ? 'minus-circle' : 'exclamation-circle'); ?>"></i>
                    Target: 80%
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #dc2626, #b91c1c);">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-value"><?php echo $overdue_count; ?></div>
                <div class="stat-label">Overdue (&gt;<?php echo $overdue_threshold_days; ?>d)</div>
                <div class="stat-trend down">
                    <i class="fas fa-clock"></i> Requires immediate attention
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #d97706, #b45309);">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <div class="stat-value"><?php echo $at_risk_count; ?></div>
                <div class="stat-label">At Risk (&gt;<?php echo $sla_threshold_days; ?>d)</div>
                <div class="stat-trend neutral">
                    <i class="fas fa-chart-line"></i> Needs monitoring
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #0284c7, #0369a1);">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo $on_track_count; ?></div>
                <div class="stat-label">On Track</div>
                <div class="stat-trend up">
                    <i class="fas fa-thumbs-up"></i> Within SLA
                </div>
            </div>
        </div>

        <div class="chart-grid">
            <div class="chart-card">
                <h4><i class="fas fa-chart-doughnut"></i> SLA Status Distribution</h4>
                <div class="chart-container">
                    <canvas id="slaChart"></canvas>
                </div>
            </div>
            <div class="chart-card">
                <h4><i class="fas fa-chart-bar"></i> Department SLA Performance</h4>
                <div class="chart-container">
                    <canvas id="deptSlaChart"></canvas>
                </div>
            </div>
        </div>

        <div class="panel" style="margin-top: 28px;">
            <div class="panel-header">
                <h3 class="panel-title"><i class="fas fa-list"></i> SLA Report Status</h3>
                <div class="filter-bar">
                    <select onchange="window.location='?status='+this.value+'&department=<?php echo urlencode($filter_dept); ?>'">
                        <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All SLA Status</option>
                        <option value="overdue" <?php echo $filter_status === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                        <option value="at-risk" <?php echo $filter_status === 'at-risk' ? 'selected' : ''; ?>>At Risk</option>
                        <option value="on-track" <?php echo $filter_status === 'on-track' ? 'selected' : ''; ?>>On Track</option>
                    </select>
                    <select onchange="window.location='?status=<?php echo urlencode($filter_status); ?>&department='+this.value">
                        <option value="all" <?php echo $filter_dept === 'all' ? 'selected' : ''; ?>>All Departments</option>
                        <?php foreach (array_keys($departments) as $d): ?>
                        <option value="<?php echo htmlspecialchars($d); ?>" <?php echo $filter_dept === $d ? 'selected' : ''; ?>>
                            <?php echo ucfirst($d); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="panel-body" style="padding: 0;">
                <?php if (empty($sla_data)): ?>
                <div style="text-align: center; padding: 60px 20px; color: var(--text-secondary);">
                    <i class="fas fa-gavel" style="font-size: 48px; margin-bottom: 16px; opacity: 0.3;"></i>
                    <p>No reports found matching your criteria.</p>
                </div>
                <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Report ID</th>
                            <th>Title</th>
                            <th>Department</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Days Elapsed</th>
                            <th>Assigned To</th>
                            <th>SLA Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sla_data as $s): ?>
                        <tr>
                            <td style="font-size: 11px; color: var(--text-secondary);">
                                <?php echo htmlspecialchars($s['report_id_display']); ?>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars(mb_substr($s['title'], 0, 40)) . (mb_strlen($s['title']) > 40 ? '...' : ''); ?></strong>
                            </td>
                            <td><?php echo ucfirst($s['department']); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $s['priority']; ?>">
                                    <?php echo ucfirst($s['priority']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo str_replace('_', '-', $s['status']); ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $s['status'])); ?>
                                </span>
                            </td>
                            <td style="font-size: 12px;">
                                <?php echo format_datetime($s['created_at']); ?>
                            </td>
                            <td style="font-weight: 600;">
                                <?php echo $s['resolution_days']; ?>d
                            </td>
                            <td style="font-size: 12px;">
                                <?php echo htmlspecialchars($s['assigned_to']); ?>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $s['sla_status']; ?>">
                                    <i class="fas fa-<?php echo $s['sla_status'] === 'overdue' ? 'times-circle' : ($s['sla_status'] === 'at-risk' ? 'exclamation-circle' : 'check-circle'); ?>"></i>
                                    <?php echo ucfirst(str_replace('-', ' ', $s['sla_status'])); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="../../js/enhanced-reports.js"></script>
    <script>
        const isDark = document.body.classList.contains('dark-mode');
        const textColor = isDark ? '#9ca3af' : '#64748b';

        new Chart(document.getElementById('slaChart'), {
            type: 'doughnut',
            data: {
                labels: ['On Track', 'At Risk', 'Overdue'],
                datasets: [{
                    data: [<?php echo $on_track_count; ?>, <?php echo $at_risk_count; ?>, <?php echo $overdue_count; ?>],
                    backgroundColor: ['#059669', '#d97706', '#dc2626'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '55%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: textColor, font: { family: 'Poppins', size: 11 }, padding: 16 }
                    }
                }
            }
        });

        <?php
        $dept_sla = [];
        foreach ($all_reports as $r) {
            $d = $r['department'] ?? 'Unknown';
            if (!isset($dept_sla[$d])) {
                $dept_sla[$d] = ['total' => 0, 'compliant' => 0];
            }
            $dept_sla[$d]['total']++;
            $created = strtotime($r['created_at'] ?? $r['created_date']);
            $status = $r['status'] ?? 'pending';
            if ($status === 'completed' || $status === 'cancelled') {
                $resolved = strtotime($r['updated_at'] ?? $r['resolved_date'] ?? 'now');
                $days = max(0, round(($resolved - $created) / 86400, 1));
                if ($days <= $sla_threshold_days) $dept_sla[$d]['compliant']++;
            } else {
                $days = max(0, round((time() - $created) / 86400, 1));
                if ($days <= $sla_threshold_days) $dept_sla[$d]['compliant']++;
            }
        }
        $dept_names = json_encode(array_keys($dept_sla) ?: []);
        $dept_rates = json_encode(array_map(function($d) {
            return $d['total'] > 0 ? round($d['compliant'] / $d['total'] * 100) : 0;
        }, $dept_sla) ?: []);
        ?>

        new Chart(document.getElementById('deptSlaChart'), {
            type: 'bar',
            data: {
                labels: <?php echo $dept_names; ?>,
                datasets: [{
                    label: 'Compliance Rate (%)',
                    data: <?php echo $dept_rates; ?>,
                    backgroundColor: <?php echo json_encode(array_map(function($d) {
                        $rate = $d['total'] > 0 ? round($d['compliant'] / $d['total'] * 100) : 0;
                        return $rate >= 80 ? '#059669' : ($rate >= 50 ? '#d97706' : '#dc2626');
                    }, $dept_sla) ?: []); ?>,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { ticks: { color: textColor, font: { family: 'Poppins', size: 10 } }, grid: { display: false } },
                    y: { ticks: { color: textColor, font: { family: 'Poppins', size: 10 }, callback: v => v + '%' }, grid: { color: isDark ? '#2d323b' : '#e2e8f0' }, beginAtZero: true, max: 100 }
                },
                plugins: {
                    legend: { display: false }
                }
            }
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
