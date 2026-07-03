<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ini_set('display_errors', 0);
}

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();
$session_timeout = 5 * 60;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
    header('Location: ../../login.php?timeout=1');
    exit();
}
$_SESSION['last_activity'] = time();

require_once '../../includes/config.php';
require_once '../../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'system_admin') {
    header('Location: ../../login.php');
    exit();
}

// Handle mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    if ($action === 'mark_read') {
        $type = $_POST['type'] ?? '';
        $id = $_POST['id'] ?? 0;
        
        if ($type === 'report') {
            $stmt = $conn->prepare("UPDATE road_transportation_reports SET updated_at = NOW() WHERE id = ? AND status = 'pending'");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
        } elseif ($type === 'user') {
            $stmt = $conn->prepare("UPDATE users SET updated_at = NOW() WHERE id = ? AND account_status = 'pending'");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
        }
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($action === 'mark_all_read') {
        $conn->query("UPDATE road_transportation_reports SET updated_at = NOW() WHERE status = 'pending'");
        $conn->query("UPDATE users SET updated_at = NOW() WHERE account_status = 'pending'");
        echo json_encode(['success' => true]);
        exit;
    }
}

// Get pending reports from all departments
$pending_reports = [];
try {
    $rstmt = $conn->prepare("
        SELECT id, report_id, title, department, priority, status, description, location, 
               reporter_name, reporter_email, created_at
        FROM road_transportation_reports 
        WHERE status = 'pending'
        ORDER BY 
            CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 END,
            created_at DESC
    ");
    $rstmt->execute();
    $pending_reports = $rstmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $rstmt->close();
} catch (Exception $e) {
    error_log("Pending reports query error: " . $e->getMessage());
}

// Get pending user account requests
$pending_users = [];
try {
    $ustmt = $conn->prepare("
        SELECT id, username, email, full_name, role, department, created_at, id_file_path
        FROM users 
        WHERE account_status = 'pending'
        ORDER BY created_at DESC
    ");
    $ustmt->execute();
    $pending_users = $ustmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ustmt->close();
} catch (Exception $e) {
    error_log("Pending users query error: " . $e->getMessage());
}

$total_notifications = count($pending_reports) + count($pending_users);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - LGU Road Monitoring</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../../styles/transition.css">
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
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 25px;
        }

        .welcome-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .welcome-text h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1e3c72;
            margin-bottom: 8px;
        }

        .welcome-text p {
            color: #64748b;
            font-size: 16px;
        }

        .date-time {
            text-align: right;
            color: #1e3c72;
        }

        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            text-align: center;
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            font-size: 24px;
            margin-bottom: 10px;
        }

        .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: #1e3c72;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 14px;
            color: #64748b;
            font-weight: 500;
        }

        .workflow-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 25px;
        }

        .workflow-card {
            background: rgba(255, 255, 255, 0.95);
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
            max-height: 600px;
            overflow-y: auto;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        th {
            background: rgba(55, 98, 200, 0.1);
            font-weight: 600;
            color: #1e3c72;
        }

        .priority-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .priority-high {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .priority-medium {
            background: #fffbeb;
            color: #d97706;
            border: 1px solid #fde68a;
        }

        .priority-low {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }

        .department-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-sm {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .btn-view {
            background: #3762c8;
            color: white;
        }

        .btn-view:hover {
            background: #2a4a9a;
        }

        .btn-approve {
            background: #10b981;
            color: white;
        }

        .btn-approve:hover {
            background: #059669;
        }

        .btn-reject {
            background: #ef4444;
            color: white;
        }

        .btn-reject:hover {
            background: #dc2626;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #cbd5e1;
        }

        .empty-state p {
            font-size: 16px;
        }

        .notification-item {
            padding: 15px;
            border-bottom: 1px solid #e5e7eb;
            transition: background 0.2s;
        }

        .notification-item:hover {
            background: rgba(55, 98, 200, 0.03);
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }

        .notification-title {
            font-weight: 600;
            color: #1e3c72;
            font-size: 14px;
        }

        .notification-time {
            font-size: 12px;
            color: #94a3b8;
        }

        .notification-body {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 10px;
        }

        .notification-meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .notification-tag {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 8px;
            background: #f1f5f9;
            color: #475569;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 10px;
            }

            .welcome-section {
                flex-direction: column;
                align-items: flex-start;
            }

            .date-time {
                text-align: left;
            }
        }
    </style>
</head>
<body>
    <iframe src="../../includes/sidebar.php" 
            style="position: fixed; width: 250px; height: 100vh; border: none; z-index: 1000;" 
            frameborder="0"
            name="sidebar-frame"
            scrolling="no"
            loading="lazy"
            referrerpolicy="no-referrer">
    </iframe>

    <div class="main-content">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="welcome-section">
                <div class="welcome-text">
                    <h1><i class="fas fa-bell"></i> Notifications</h1>
                    <p>Reports from other departments and user account requests</p>
                </div>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <button class="btn-sm btn-approve" onclick="markAllRead()" <?php echo $total_notifications === 0 ? 'disabled' : ''; ?>>
                        <i class="fas fa-check-double"></i> Mark All as Read
                    </button>
                    <div class="date-time">
                        <div id="currentDate"></div>
                        <div id="currentTime"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="quick-stats">
            <div class="stat-card">
                <div class="stat-icon" style="color: #f59e0b;">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="stat-number"><?php echo count($pending_reports); ?></div>
                <div class="stat-label">Pending Reports</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="color: #3b82f6;">
                    <i class="fas fa-user-clock"></i>
                </div>
                <div class="stat-number"><?php echo count($pending_users); ?></div>
                <div class="stat-label">User Requests</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="color: #10b981;">
                    <i class="fas fa-bell"></i>
                </div>
                <div class="stat-number"><?php echo $total_notifications; ?></div>
                <div class="stat-label">Total Notifications</div>
            </div>
        </div>

        <div class="workflow-container">
            <!-- Pending Reports -->
            <div class="workflow-card">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <i class="fas fa-file-alt" style="color: #f59e0b;"></i>
                        <span>Pending Reports from Departments</span>
                        <span class="workflow-badge"><?php echo count($pending_reports); ?></span>
                    </h3>
                </div>
                
                <div class="workflow-content">
                    <?php if (empty($pending_reports)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No pending reports</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($pending_reports as $report): ?>
                            <div class="notification-item" id="report-<?php echo $report['id']; ?>">
                                <div class="notification-header">
                                    <div class="notification-title"><?php echo htmlspecialchars($report['title']); ?></div>
                                    <div class="notification-time"><?php echo date('M d, Y H:i', strtotime($report['created_at'])); ?></div>
                                </div>
                                <div class="notification-body">
                                    <?php echo htmlspecialchars(substr($report['description'], 0, 150)); ?><?php echo strlen($report['description']) > 150 ? '...' : ''; ?>
                                </div>
                                <div class="notification-meta">
                                    <span class="notification-tag"><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($report['report_id']); ?></span>
                                    <span class="priority-badge priority-<?php echo $report['priority']; ?>"><?php echo ucfirst($report['priority']); ?></span>
                                    <span class="department-badge"><?php echo ucfirst(htmlspecialchars($report['department'])); ?></span>
                                    <?php if ($report['location']): ?>
                                        <span class="notification-tag"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($report['location']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($report['reporter_name']): ?>
                                        <span class="notification-tag"><i class="fas fa-user"></i> <?php echo htmlspecialchars($report['reporter_name']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div style="margin-top: 10px;">
                                    <div class="action-buttons">
                                        <a href="report_management.php" class="btn-sm btn-view" target="_parent"><i class="fas fa-eye"></i> View</a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pending User Requests -->
            <div class="workflow-card">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <i class="fas fa-user-clock" style="color: #3b82f6;"></i>
                        <span>User Account Requests</span>
                        <span class="workflow-badge"><?php echo count($pending_users); ?></span>
                    </h3>
                </div>
                
                <div class="workflow-content">
                    <?php if (empty($pending_users)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No pending user requests</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($pending_users as $user): ?>
                            <div class="notification-item" id="user-<?php echo $user['id']; ?>">
                                <div class="notification-header">
                                    <div class="notification-title"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                    <div class="notification-time"><?php echo date('M d, Y H:i', strtotime($user['created_at'])); ?></div>
                                </div>
                                <div class="notification-body">
                                    New <?php echo ucfirst(htmlspecialchars($user['role'])); ?> account registration request
                                </div>
                                <div class="notification-meta">
                                    <span class="notification-tag"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['email']); ?></span>
                                    <span class="department-badge"><?php echo ucfirst(htmlspecialchars($user['department'] ?? 'N/A')); ?></span>
                                    <?php if ($user['id_file_path']): ?>
                                        <span class="notification-tag"><i class="fas fa-id-card"></i> ID Uploaded</span>
                                    <?php endif; ?>
                                </div>
                                <div style="margin-top: 10px;">
                                    <div class="action-buttons">
                                        <a href="admin_dashboard.php" class="btn-sm btn-view" target="_parent"><i class="fas fa-eye"></i> Review</a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function updateDateTime() {
            const now = new Date();
            const dateOptions = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const timeOptions = { hour: '2-digit', minute: '2-digit', second: '2-digit' };
            
            document.getElementById('currentDate').textContent = now.toLocaleDateString('en-US', dateOptions);
            document.getElementById('currentTime').textContent = now.toLocaleTimeString('en-US', timeOptions);
        }
        
        updateDateTime();
        setInterval(updateDateTime, 1000);

        function markAllRead() {
            if (confirm('Mark all notifications as read?')) {
                const formData = new FormData();
                formData.append('action', 'mark_all_read');
                
                fetch('', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        location.reload();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                });
            }
        }
    </script>
    
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
