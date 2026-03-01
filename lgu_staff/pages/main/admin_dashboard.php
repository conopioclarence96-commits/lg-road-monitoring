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

// Handle account actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    $user_id = $_POST['user_id'] ?? 0;
    $remarks = $_POST['remarks'] ?? '';

    if ($action === 'approve' && $user_id > 0) {
        // Approve account
        $stmt = $conn->prepare("UPDATE users SET is_active = 1, account_status = 'verified' WHERE id = ?");
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to prepare approval query']);
            exit;
        }
        $stmt->bind_param("i", $user_id);

        if ($stmt->execute()) {
            // Get user details for audit log
            $user_stmt = $conn->prepare("SELECT email, full_name FROM users WHERE id = ?");
            if (!$user_stmt) {
                $stmt->close();
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to prepare user lookup query']);
                exit;
            }
            $user_stmt->bind_param("i", $user_id);
            if (!$user_stmt->execute()) {
                $stmt->close();
                $user_stmt->close();
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to lookup user details']);
                exit;
            }
            $user_result = $user_stmt->get_result();
            $user_data = $user_result ? $user_result->fetch_assoc() : null;

            // Log audit action
            if ($user_data) {
                log_audit_action($_SESSION['user_id'], 'Account Approved', 
                    "Approved account for {$user_data['full_name']} ({$user_data['email']}). Remarks: $remarks");
            }

            echo json_encode(['success' => true, 'message' => 'Account approved successfully']);
            if ($user_stmt) {
                $user_stmt->close();
            }
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to approve account']);
        }
        $stmt->close();
        exit;

    } elseif ($action === 'reject' && $user_id > 0) {
        // Reject account (keep as inactive)
        $stmt = $conn->prepare("UPDATE users SET is_active = 0, account_status = 'rejected' WHERE id = ?");
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to prepare rejection query']);
            exit;
        }
        $stmt->bind_param("i", $user_id);

        if ($stmt->execute()) {
            // Get user details for audit log
            $user_stmt = $conn->prepare("SELECT email, full_name FROM users WHERE id = ?");
            if (!$user_stmt) {
                $stmt->close();
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to prepare user lookup query']);
                exit;
            }
            $user_stmt->bind_param("i", $user_id);
            if (!$user_stmt->execute()) {
                $stmt->close();
                $user_stmt->close();
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to lookup user details']);
                exit;
            }
            $user_result = $user_stmt->get_result();
            $user_data = $user_result ? $user_result->fetch_assoc() : null;

            // Log audit action
            if ($user_data) {
                log_audit_action($_SESSION['user_id'], 'Account Rejected', 
                    "Rejected account for {$user_data['full_name']} ({$user_data['email']}). Remarks: $remarks");
            }

            echo json_encode(['success' => true, 'message' => 'Account rejected successfully']);
            if ($user_stmt) {
                $user_stmt->close();
            }
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to reject account']);
        }
        $stmt->close();
        exit;

    } elseif ($action === 'deactivate' && $user_id > 0) {
        // Deactivate account
        $stmt = $conn->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
        $stmt->bind_param("i", $user_id);

        if ($stmt->execute()) {
            // Get user details for audit log
            $user_stmt = $conn->prepare("SELECT email, full_name FROM users WHERE id = ?");
            if (!$user_stmt) {
                $stmt->close();
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to prepare user lookup query']);
                exit;
            }
            $user_stmt->bind_param("i", $user_id);
            if (!$user_stmt->execute()) {
                $stmt->close();
                $user_stmt->close();
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to lookup user details']);
                exit;
            }
            $user_result = $user_stmt->get_result();
            $user_data = $user_result ? $user_result->fetch_assoc() : null;

            // Log audit action
            if ($user_data) {
                log_audit_action($_SESSION['user_id'], 'Account Deactivated', 
                    "Deactivated account for {$user_data['full_name']} ({$user_data['email']})");
            }

            echo json_encode(['success' => true, 'message' => 'Account deactivated successfully']);
            if ($user_stmt) {
                $user_stmt->close();
            }
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to deactivate account']);
        }
        $stmt->close();
        exit;

    } elseif ($action === 'deactivate_user' && $user_id > 0) {
        // Deactivate account (new version)
        $stmt = $conn->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
        $stmt->bind_param("i", $user_id);

        if ($stmt->execute()) {
            // Get user details for audit log
            $user_stmt = $conn->prepare("SELECT email, full_name FROM users WHERE id = ?");
            if (!$user_stmt) {
                $stmt->close();
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to prepare user lookup query']);
                exit;
            }
            $user_stmt->bind_param("i", $user_id);
            if (!$user_stmt->execute()) {
                $stmt->close();
                $user_stmt->close();
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to lookup user details']);
                exit;
            }
            $user_result = $user_stmt->get_result();
            $user_data = $user_result ? $user_result->fetch_assoc() : null;

            // Log audit action
            if ($user_data) {
                log_audit_action($_SESSION['user_id'], 'Account Deactivated', 
                    "Deactivated account for {$user_data['full_name']} ({$user_data['email']})");
            }

            echo json_encode(['success' => true, 'message' => 'Account deactivated successfully']);
            if ($user_stmt) {
                $user_stmt->close();
            }
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to deactivate account']);
        }
        $stmt->close();
        $user_stmt->close();
        exit;

    } elseif ($action === 'activate_user' && $user_id > 0) {
        // Activate account
        $stmt = $conn->prepare("UPDATE users SET is_active = 1 WHERE id = ?");
        $stmt->bind_param("i", $user_id);

        if ($stmt->execute()) {
            // Get user details for audit log
            $user_stmt = $conn->prepare("SELECT email, full_name FROM users WHERE id = ?");
            if (!$user_stmt) {
                $stmt->close();
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to prepare user lookup query']);
                exit;
            }
            $user_stmt->bind_param("i", $user_id);
            if (!$user_stmt->execute()) {
                $stmt->close();
                $user_stmt->close();
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to lookup user details']);
                exit;
            }
            $user_result = $user_stmt->get_result();
            $user_data = $user_result ? $user_result->fetch_assoc() : null;

            // Log audit action
            if ($user_data) {
                log_audit_action($_SESSION['user_id'], 'Account Activated', 
                    "Activated account for {$user_data['full_name']} ({$user_data['email']}). Remarks: $remarks");
            }

            echo json_encode(['success' => true, 'message' => 'Account activated successfully']);
            if ($user_stmt) {
                $user_stmt->close();
            }
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to activate account']);
        }
        $stmt->close();
        $user_stmt->close();
        exit;

    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }
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
        <?php include '../includes/sidebar.php'; ?>
        
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
        </div>
    </main>
    
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
