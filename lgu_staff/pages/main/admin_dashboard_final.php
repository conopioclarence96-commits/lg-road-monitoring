<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

// Check if user is logged in and is system admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'system_admin') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    header('Location: ../../login.php');
    exit();
}

// Fetch dashboard statistics
$stats = [];
try {
    // Report statistics
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_reports,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_reports,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_reports,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_reports
        FROM (
            SELECT status FROM road_transportation_reports
            UNION ALL
            SELECT status FROM road_maintenance_reports
        ) reports
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->execute();
    $report_stats = $stmt->get_result()->fetch_assoc();
    $stats = array_merge($stats, $report_stats);
    $stmt->close();
    
    // User statistics
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_users,
            SUM(CASE WHEN account_status = 'pending' THEN 1 ELSE 0 END) as pending_users,
            SUM(CASE WHEN account_status = 'verified' THEN 1 ELSE 0 END) as verified_users,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users
        FROM users
        WHERE role IN ('lgu_staff', 'citizen')
    ");
    $stmt->execute();
    $user_stats = $stmt->get_result()->fetch_assoc();
    $stats = array_merge($stats, $user_stats);
    $stmt->close();
    
    // Department statistics
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(department, 'Unknown') as department,
            COUNT(*) as report_count
        FROM (
            SELECT department, created_at FROM road_transportation_reports
            UNION ALL
            SELECT department, created_at FROM road_maintenance_reports
        ) reports
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY department
        ORDER BY report_count DESC
    ");
    $stmt->execute();
    $stats['dept_stats'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Monthly trend
    $stmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(created_at, '%M') as month,
            COUNT(*) as report_count
        FROM (
            SELECT created_at FROM road_transportation_reports
            UNION ALL
            SELECT created_at FROM road_maintenance_reports
        ) reports
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY created_at ASC
    ");
    $stmt->execute();
    $stats['monthly_trend'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
} catch (Exception $e) {
    error_log("Error fetching dashboard stats: " . $e->getMessage());
    $stats = [
        'total_reports' => 0,
        'pending_reports' => 0,
        'in_progress_reports' => 0,
        'completed_reports' => 0,
        'total_users' => 0,
        'pending_users' => 0,
        'verified_users' => 0,
        'active_users' => 0,
        'dept_stats' => [],
        'monthly_trend' => []
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Dashboard - Data Analytics</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background: url("../../../assets/img/cityhall.jpeg") center/cover no-repeat fixed;
            position: relative;
            min-height: 100vh;
        }
        
        body::before {
            content: "";
            position: absolute;
            inset: 0;
            backdrop-filter: blur(6px);
            background: rgba(0, 0, 0, 0.35);
            z-index: -1;
        }
        
        .main-content {
            position: relative;
            z-index: 1;
        }
        
        .dashboard-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            padding: 30px;
            border-radius: 16px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .welcome-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .welcome-text h1 {
            color: #1e3c72;
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .welcome-text p {
            color: #666;
            font-size: 16px;
        }
        
        .charts-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .chart-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        .chart-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 15px;
            color: #333;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .data-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        
        .data-table tr:hover {
            background: #f8f9fa;
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-pending { background: #fff3cd; color: #856404; }
        .status-verified { background: #d1ecf1; color: #0c5460; }
        .status-active { background: #d4edda; color: #155724; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="main-content">
        <?php include 'sidebar.php'; ?>
        
        <main>
            <div class="content-header">
                <h1>Admin Dashboard</h1>
                <p class="subtitle">System Overview and Analytics</p>
            </div>
            
            <div class="welcome-section">
                <div class="welcome-text">
                    <h1>Welcome, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></h1>
                    <p>System Administration Panel</p>
                    <p class="date-time"><?php echo date('F, j Y, g:i A'); ?></p>
                </div>
            </div>
            
            <div class="charts-section">
                <div class="chart-container">
                    <h3 class="chart-title">Report Statistics (Last 30 Days)</h3>
                    <canvas id="reportChart" width="400" height="200"></canvas>
                </div>
                
                <div class="chart-container">
                    <h3 class="chart-title">User Account Status</h3>
                    <canvas id="userChart" width="400" height="200"></canvas>
                </div>
                
                <div class="chart-container">
                    <h3 class="chart-title">Department Report Distribution</h3>
                    <canvas id="deptChart" width="400" height="200"></canvas>
                </div>
                
                <div class="chart-container">
                    <h3 class="chart-title">Monthly Report Trend</h3>
                    <canvas id="trendChart" width="400" height="200"></canvas>
                </div>
            </div>
            
            <div class="charts-section">
                <div class="chart-container">
                    <h3 class="chart-title">User Statistics</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Total Users</th>
                                <th>Pending Approval</th>
                                <th>Verified Users</th>
                                <th>Active Users</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><?php echo number_format($stats['total_users'] ?? 0); ?></td>
                                <td><?php echo number_format($stats['pending_users'] ?? 0); ?></td>
                                <td><?php echo number_format($stats['verified_users'] ?? 0); ?></td>
                                <td><?php echo number_format($stats['active_users'] ?? 0); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="chart-container">
                    <h3 class="chart-title">Department Statistics</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th>Report Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['dept_stats'] as $dept): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($dept['department']); ?></td>
                                <td><?php echo number_format($dept['report_count']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
    
    <?php include '../includes/footer.php'; ?>
    
    <script>
        // Chart.js configuration
        Chart.defaults.font.family = 'Poppins, sans-serif';
        Chart.defaults.color = '#333';
        
        // Report Statistics Chart
        const reportCtx = document.getElementById('reportChart').getContext('2d');
        new Chart(reportCtx, {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'In Progress', 'Completed'],
                datasets: [{
                    data: [<?php echo $stats['pending_reports'] ?? 0; ?>, <?php echo $stats['in_progress_reports'] ?? 0; ?>, <?php echo $stats['completed_reports'] ?? 0; ?>],
                    backgroundColor: ['#fff3cd', '#ffc107', '#28a745'],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
        
        // User Status Chart
        const userCtx = document.getElementById('userChart').getContext('2d');
        new Chart(userCtx, {
            type: 'doughnut',
            data: {
                labels: ['Pending Approval', 'Verified Users', 'Active Users'],
                datasets: [{
                    data: [<?php echo $stats['pending_users'] ?? 0; ?>, <?php echo $stats['verified_users'] ?? 0; ?>, <?php echo $stats['active_users'] ?? 0; ?>],
                    backgroundColor: ['#fff3cd', '#d1ecf1', '#d4edda'],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
        
        // Department Chart
        const deptCtx = document.getElementById('deptChart').getContext('2d');
        new Chart(deptCtx, {
            type: 'pie',
            data: {
                labels: <?php echo json_encode(array_column($stats['dept_stats'], 'department')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($stats['dept_stats'], 'report_count')); ?>,
                    backgroundColor: [
                        '#FF6384', '#36A2EB', '#FFCE56', '#003F5C', '#8BC34A', '#E91E63', '#F7DC6F', '#C70039', '#3498DB', '#4E342E', '#9C27B0'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'right'
                    }
                }
            }
        });
        
        // Monthly Trend Chart
        const trendCtx = document.getElementById('trendChart').getContext('2d');
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($stats['monthly_trend'], 'month')); ?>,
                datasets: [{
                    label: 'Monthly Reports',
                    data: <?php echo json_encode(array_column($stats['monthly_trend'], 'report_count')); ?>,
                    borderColor: '#007bff',
                    backgroundColor: 'rgba(0, 123, 255, 0.1)',
                    tension: 0.1,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
