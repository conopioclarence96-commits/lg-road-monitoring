<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// Session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();

// Session timeout configuration
$session_timeout = 5 * 60; // 5 minutes in seconds

// Check if session has expired
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    // Session expired, destroy and redirect to login
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
    header('Location: ../../login.php?timeout=1');
    exit();
}

// Update last activity time
$_SESSION['last_activity'] = time();

require_once '../../includes/config.php';
require_once '../../includes/functions.php';

// Ensure approved_at and rejected_at columns exist in users table
if ($conn->connect_error === null) {
    $check = $conn->query("SHOW COLUMNS FROM users LIKE 'approved_at'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN approved_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at");
    }
    $check2 = $conn->query("SHOW COLUMNS FROM users LIKE 'rejected_at'");
    if ($check2 && $check2->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN rejected_at TIMESTAMP NULL DEFAULT NULL AFTER approved_at");
    }
}

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

// Get verified users inactive for 2+ weeks
$inactive_2weeks_users = [];
try {
    $inactive_stmt = $conn->prepare("
        SELECT id, username, email, full_name, role, department, last_login, created_at, updated_at
        FROM users 
        WHERE account_status = 'verified' 
        AND is_active = 1
        AND last_login IS NOT NULL 
        AND last_login < DATE_SUB(NOW(), INTERVAL 14 DAY)
        ORDER BY last_login ASC
    ");
    $inactive_stmt->execute();
    $inactive_2weeks_users = $inactive_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $inactive_stmt->close();
} catch (Exception $e) {
    error_log("Inactive users query error: " . $e->getMessage());
    $inactive_2weeks_users = [];
}

// Get dashboard statistics
$stats = [];
try {
    // Pending user approvals
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE account_status = 'pending'");
    $stmt->execute();
    $stats['pending_users'] = $stmt->get_result()->fetch_assoc()['count'];
    
    // Approved users
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE account_status = 'verified' AND is_active = 1");
    $stmt->execute();
    $stats['approved_users'] = $stmt->get_result()->fetch_assoc()['count'];
    
    // Active reports
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status IN ('pending', 'in-progress')");
    $stmt->execute();
    $stats['active_reports'] = $stmt->get_result()->fetch_assoc()['count'];
    
    // Deactivated users
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE account_status = 'deactivated'");
    $stmt->execute();
    $stats['deactivated_users'] = $stmt->get_result()->fetch_assoc()['count'];
    
    // Inactive users (logged in before but not in 2 weeks)
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE account_status = 'verified' AND is_active = 1 AND last_login IS NOT NULL AND last_login < DATE_SUB(NOW(), INTERVAL 14 DAY)");
    $stmt->execute();
    $stats['inactive_2weeks'] = $stmt->get_result()->fetch_assoc()['count'];
    
} catch (Exception $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
    $stats = [
        'pending_users' => 0,
        'approved_users' => 0,
        'active_reports' => 0,
        'deactivated_users' => 0,
        'inactive_2weeks' => 0
    ];
}

// Report statistics for charts
$report_stats = [
    'by_status' => [],
    'by_type' => [],
    'by_month' => []
];

try {
    // Reports by status
    $rstmt = $conn->prepare("SELECT status, COUNT(*) as count FROM road_transportation_reports GROUP BY status ORDER BY count DESC");
    $rstmt->execute();
    $report_stats['by_status'] = $rstmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $rstmt->close();

    // Reports by report_type
    $rstmt = $conn->prepare("SELECT report_type, COUNT(*) as count FROM road_transportation_reports GROUP BY report_type ORDER BY count DESC");
    $rstmt->execute();
    $report_stats['by_type'] = $rstmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $rstmt->close();

    // Reports by month (last 6 months)
    $rstmt = $conn->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') as month_label, 
               DATE_FORMAT(created_at, '%b %Y') as month_name, 
               COUNT(*) as count 
        FROM road_transportation_reports 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY month_label, month_name 
        ORDER BY month_label ASC
    ");
    $rstmt->execute();
    $report_stats['by_month'] = $rstmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $rstmt->close();
} catch (Exception $e) {
    error_log("Report stats error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Dashboard - Account Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background: #f7f5f0;
            min-height: 100vh;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        .main-content {
            margin-left: 250px;
            padding: 20px;
            position: relative;
            z-index: 1;
        }

        .dashboard-header {
            background: #f0f4fa;
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
            margin-bottom: 20px;
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

        .date-time {
            text-align: right;
            color: #3762c8;
            font-weight: 500;
        }

        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.9), rgba(255, 255, 255, 0.7));
            backdrop-filter: blur(10px);
            padding: 20px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            text-align: center;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #3762c8, #1e3c72);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(55, 98, 200, 0.2);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            margin: 0 auto 15px;
        }

        .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: #1e3c72;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #666;
            font-size: 14px;
            font-weight: 500;
        }

        .main-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        .card {
            background: #f0f4fa;
            backdrop-filter: blur(15px);
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            overflow: hidden;
        }

        .card-header {
            background: #f8fafc;
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
            margin: -25px -25px 20px -25px;
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e3c72;
            margin: 0;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        th {
            background: #f8fafc;
            font-weight: 600;
            color: #475569;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 500;
        }

        .status-pending {
            background: #fef3c7;
            color: #d97706;
        }

        .status-verified {
            background: #dcfce7;
            color: #166534;
        }

        .status-deactivated {
            background: #fee2e2;
            color: #dc2626;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.85em;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-approve {
            background: #22c55e;
            color: white;
        }

        .btn-reject {
            background: #ef4444;
            color: white;
        }

        .btn-deactivate {
            background: #f59e0b;
            color: white;
        }

        .btn-assign {
            background: #3b82f6;
            color: white;
        }

        .btn-manage {
            background: #3b82f6;
            color: white;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }

        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 30px;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 5px;
        }

        .form-group textarea {
            resize: vertical;
        }

        .audit-log {
            max-height: 400px;
            overflow-y: auto;
        }

        .log-entry {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
        }

        .log-entry:last-child {
            border-bottom: none;
        }

        .log-action {
            font-weight: 500;
            color: #1e293b;
        }

        .log-details {
            color: #64748b;
            font-size: 0.9em;
            margin-top: 5px;
        }

        .log-time {
            color: #94a3b8;
            font-size: 0.85em;
        }

        .btn-logout {
            background: #ef4444;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            transition: all 0.2s;
        }

        .btn-logout:hover {
            background: #dc2626;
        }

        .charts-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
        }

        .chart-card {
            background: #f0f4fa;
            backdrop-filter: blur(15px);
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .chart-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(55, 98, 200, 0.1);
        }

        .chart-card-title {
            font-size: 16px;
            font-weight: 600;
            color: #1e3c72;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .chart-container {
            position: relative;
            width: 100%;
            max-height: 300px;
        }

        .chart-body {
            display: flex;
            align-items: center;
            gap: 25px;
        }

        .chart-body .chart-container {
            flex: 0 0 55%;
            max-height: 260px;
        }

        .chart-summary {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .chart-summary-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 10px;
            background: rgba(241, 245, 249, 0.7);
            transition: background 0.2s;
        }

        .chart-summary-item:hover {
            background: rgba(241, 245, 249, 1);
        }

        .summary-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .summary-info {
            flex: 1;
        }

        .summary-label {
            font-size: 12px;
            color: #64748b;
            font-weight: 500;
        }

        .summary-count {
            font-size: 18px;
            font-weight: 700;
            color: #1e3c72;
        }

        .summary-percent {
            font-size: 13px;
            font-weight: 600;
            color: #94a3b8;
        }

        .chart-full {
            grid-column: 1 / -1;
        }

        .workflow-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 25px;
        }

        .workflow-card {
            background: #f0f4fa;
            backdrop-filter: blur(15px);
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .workflow-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(55, 98, 200, 0.1);
        }

        .workflow-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e3c72;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .workflow-badge {
            background: #3762c8;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .workflow-content {
            max-height: 400px;
            overflow-y: auto;
            padding-right: 10px;
        }

        .workflow-content::-webkit-scrollbar {
            width: 6px;
        }

        .workflow-content::-webkit-scrollbar-track {
            background: rgba(55, 98, 200, 0.1);
            border-radius: 3px;
        }

        .workflow-content::-webkit-scrollbar-thumb {
            background: rgba(55, 98, 200, 0.3);
            border-radius: 3px;
        }

        .workflow-content::-webkit-scrollbar-thumb:hover {
            background: rgba(55, 98, 200, 0.5);
        }

        @media (max-width: 1200px) {
            .workflow-container {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .welcome-section {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .date-time {
                text-align: left;
                margin-top: 10px;
            }

            .charts-section {
                grid-template-columns: 1fr;
            }

            .chart-body {
                flex-direction: column;
            }

            .chart-body .chart-container {
                flex: 0 0 auto;
                max-height: 260px;
            }
        }
    .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 15% auto;
            padding: 20px;
            border-radius: 8px;
            width: 80%;
            max-width: 500px;
            position: absolute;
            top: 25%;
            left: 50%;
            transform: translate(-50%, -50%);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-title {
            margin: 0;
            color: #333;
        }
        
        .close {
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        
        .modal-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
        }
        
        .modal-form-grid .form-group {
            margin-bottom: 0;
        }
    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <!-- SIDEBAR -->
    <?php include '../../includes/sidebar_content.php'; ?>

    <div class="main-content">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="welcome-section">
                <div class="welcome-text">
                    <h1>📋 Accounts Approval</h1>
                    <p>Review and approve pending LGU Staff account registrations</p>
                </div>
                <div class="date-time">
                    <div id="currentDate"></div>
                    <div id="currentTime"></div>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="quick-stats">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-clock"></i>
                </div>
                <div class="stat-number"><?php echo $stats['pending_users']; ?></div>
                <div class="stat-label">Pending Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-number"><?php echo $stats['approved_users']; ?></div>
                <div class="stat-label">Approved Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-slash"></i>
                </div>
                <div class="stat-number"><?php echo $stats['inactive_2weeks']; ?></div>
                <div class="stat-label">Inactive (2+ Weeks)</div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="charts-section">
            <!-- User Accounts Overview -->
            <div class="chart-card">
                <div class="chart-card-header">
                    <h3 class="chart-card-title">
                        <i class="fas fa-chart-pie"></i>
                        <span>User Accounts Overview</span>
                    </h3>
                    <span class="workflow-badge"><?php echo $stats['pending_users'] + $stats['approved_users'] + $stats['inactive_2weeks'] + $stats['deactivated_users']; ?> Total</span>
                </div>
                <div class="chart-body">
                    <div class="chart-container">
                        <canvas id="userAccountsChart"></canvas>
                    </div>
                    <div class="chart-summary">
                        <?php
                        $total_users = $stats['pending_users'] + $stats['approved_users'] + $stats['inactive_2weeks'] + $stats['deactivated_users'];
                        $summary_items = [
                            ['label' => 'Pending', 'count' => $stats['pending_users'], 'color' => '#f59e0b'],
                            ['label' => 'Approved', 'count' => $stats['approved_users'], 'color' => '#10b981'],
                            ['label' => 'Inactive (2+ Weeks)', 'count' => $stats['inactive_2weeks'], 'color' => '#6b7280'],
                            ['label' => 'Deactivated', 'count' => $stats['deactivated_users'], 'color' => '#ef4444'],
                        ];
                        foreach ($summary_items as $item):
                            $pct = $total_users > 0 ? round(($item['count'] / $total_users) * 100) : 0;
                        ?>
                        <div class="chart-summary-item">
                            <span class="summary-dot" style="background: <?php echo $item['color']; ?>;"></span>
                            <div class="summary-info">
                                <div class="summary-label"><?php echo $item['label']; ?></div>
                            </div>
                            <div class="summary-count"><?php echo $item['count']; ?></div>
                            <div class="summary-percent"><?php echo $pct; ?>%</div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Reports by Status -->
            <div class="chart-card">
                <div class="chart-card-header">
                    <h3 class="chart-card-title">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports by Status</span>
                    </h3>
                </div>
                <div class="chart-container">
                    <canvas id="reportsByStatusChart"></canvas>
                </div>
            </div>

            <!-- Reports Trend (Last 6 Months) -->
            <div class="chart-card chart-full">
                <div class="chart-card-header">
                    <h3 class="chart-card-title">
                        <i class="fas fa-chart-line"></i>
                        <span>Reports Trend (Last 6 Months)</span>
                    </h3>
                </div>
                <div class="chart-container">
                    <canvas id="reportsTrendChart"></canvas>
                </div>
            </div>
        </div>

        <div class="workflow-container">
            <!-- Inactive Users (2+ Weeks) -->
            <div class="workflow-card">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <i class="fas fa-user-slash"></i>
                        <span>Inactive Users (2+ Weeks)</span>
                        <span class="workflow-badge"><?php echo count($inactive_2weeks_users); ?></span>
                    </h3>
                </div>
                
                <div class="workflow-content">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Department</th>
                                    <th>Last Login</th>
                                    <th>Registered</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($inactive_2weeks_users)): ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; color: #64748b;">No inactive users found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($inactive_2weeks_users as $user): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td><?php echo htmlspecialchars($user['role']); ?></td>
                                            <td><?php echo htmlspecialchars($user['department'] ?? 'N/A'); ?></td>
                                            <td><?php echo $user['last_login'] ? date('M d, Y', strtotime($user['last_login'])) : 'Never'; ?></td>
                                            <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Update date and time
        function updateDateTime() {
            const now = new Date();
            const dateOptions = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const timeOptions = { hour: '2-digit', minute: '2-digit', second: '2-digit' };
            
            document.getElementById('currentDate').textContent = now.toLocaleDateString('en-US', dateOptions);
            document.getElementById('currentTime').textContent = now.toLocaleTimeString('en-US', timeOptions);
        }
        
        updateDateTime();
        setInterval(updateDateTime, 1000);

        // Chart Data from PHP
        const userStats = {
            pending: <?php echo $stats['pending_users']; ?>,
            approved: <?php echo $stats['approved_users']; ?>,
            inactive: <?php echo $stats['inactive_2weeks']; ?>,
            deactivated: <?php echo $stats['deactivated_users']; ?>
        };

        const reportsByStatus = <?php echo json_encode($report_stats['by_status']); ?>;
        const reportsByMonth = <?php echo json_encode($report_stats['by_month']); ?>;
        const reportsByType = <?php echo json_encode($report_stats['by_type']); ?>;

        // Color palette
        const chartColors = {
            pending: '#f59e0b',
            approved: '#10b981',
            inactive: '#6b7280',
            deactivated: '#ef4444',
            statusColors: ['#f59e0b', '#3b82f6', '#10b981', '#6b7280', '#ef4444', '#8b5cf6'],
            typeColors: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16', '#f97316']
        };

        // User Accounts Doughnut Chart
        const userAccountsCtx = document.getElementById('userAccountsChart').getContext('2d');
        new Chart(userAccountsCtx, {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'Approved', 'Inactive (2+ Weeks)', 'Deactivated'],
                datasets: [{
                    data: [userStats.pending, userStats.approved, userStats.inactive, userStats.deactivated],
                    backgroundColor: [chartColors.pending, chartColors.approved, chartColors.inactive, chartColors.deactivated],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            usePointStyle: true,
                            font: { size: 12 }
                        }
                    }
                },
                cutout: '60%'
            }
        });

        // Reports by Status Bar Chart
        const reportsByStatusCtx = document.getElementById('reportsByStatusChart').getContext('2d');
        const statusLabels = reportsByStatus.map(r => r.status.charAt(0).toUpperCase() + r.status.slice(1));
        const statusData = reportsByStatus.map(r => r.count);
        new Chart(reportsByStatusCtx, {
            type: 'bar',
            data: {
                labels: statusLabels,
                datasets: [{
                    label: 'Reports',
                    data: statusData,
                    backgroundColor: chartColors.statusColors.slice(0, statusLabels.length),
                    borderRadius: 8,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1, font: { size: 12 } },
                        grid: { color: 'rgba(0,0,0,0.05)' }
                    },
                    x: {
                        ticks: { font: { size: 11 } },
                        grid: { display: false }
                    }
                }
            }
        });

        // Reports Trend Line Chart
        const reportsTrendCtx = document.getElementById('reportsTrendChart').getContext('2d');
        const monthLabels = reportsByMonth.map(r => r.month_name);
        const monthData = reportsByMonth.map(r => r.count);
        new Chart(reportsTrendCtx, {
            type: 'line',
            data: {
                labels: monthLabels.length > 0 ? monthLabels : ['No Data'],
                datasets: [{
                    label: 'Reports Submitted',
                    data: monthData.length > 0 ? monthData : [0],
                    borderColor: '#3762c8',
                    backgroundColor: 'rgba(55, 98, 200, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#3762c8',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { usePointStyle: true, font: { size: 12 } }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1, font: { size: 12 } },
                        grid: { color: 'rgba(0,0,0,0.05)' }
                    },
                    x: {
                        ticks: { font: { size: 12 } },
                        grid: { display: false }
                    }
                }
            }
        });
        
        // Auto-hide messages after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);
    </script>
    
    <!-- Page Transition Overlay -->
    <div class="page-transition-overlay" id="pageTransitionOverlay">
        <div class="transition-content">
            <div class="transition-spinner">
                <i class="fas fa-spinner"></i>
            </div>
            <div class="transition-text">Loading...</div>
        </div>
    </div>
</body>
</html>
