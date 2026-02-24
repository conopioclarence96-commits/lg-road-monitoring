<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ini_set('display_errors', 0);
}

// Session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and is system admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'system_admin') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    header('Location: login.php');
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
                if ($user_stmt) {
                    $user_stmt->close();
                }
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
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            $user_data = $user_result->fetch_assoc();
            
            // Log audit action
            log_audit_action($_SESSION['user_id'], 'Account Deactivated', 
                "Deactivated account for {$user_data['full_name']} ({$user_data['email']}). Remarks: $remarks");
            
            $message = "Account for {$user_data['full_name']} has been deactivated.";
            $messageType = 'warning';
        } else {
            $message = "Failed to deactivate account. Please try again.";
            $messageType = 'error';
        }
        $stmt->close();
        $user_stmt->close();
        
    } elseif ($action === 'deactivate_user' && $user_id > 0) {
        // Deactivate account (new version)
        $stmt = $conn->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        
        if ($stmt->execute()) {
            // Get user details for audit log
            $user_stmt = $conn->prepare("SELECT email, full_name FROM users WHERE id = ?");
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            $user_data = $user_result->fetch_assoc();
            
            // Log audit action
            log_audit_action($_SESSION['user_id'], 'Account Deactivated', 
                "Deactivated account for {$user_data['full_name']} ({$user_data['email']}). Remarks: $remarks");
            
            echo json_encode(['success' => true, 'message' => 'Account deactivated successfully']);
        } else {
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
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            $user_data = $user_result->fetch_assoc();
            
            // Log audit action
            log_audit_action($_SESSION['user_id'], 'Account Activated', 
                "Activated account for {$user_data['full_name']} ({$user_data['email']}). Remarks: $remarks");
            
            echo json_encode(['success' => true, 'message' => 'Account activated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to activate account']);
        }
        $stmt->close();
        $user_stmt->close();
        exit;
    }
}

// Get all LGU Staff accounts with pending status (excluding system admin)
$stmt = $conn->prepare("
    SELECT id, username, email, full_name, role, department, address, birthday, civil_status, is_active, created_at, updated_at, id_file_path 
    FROM users 
    WHERE role IN ('lgu_staff', 'citizen') AND account_status = 'pending'
    ORDER BY created_at DESC
");
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get audit log for account actions
try {
    $audit_stmt = $conn->prepare("
        SELECT a.*, u.full_name as admin_name 
        FROM audit_logs a 
        LEFT JOIN users u ON a.user_id = u.id 
        WHERE a.action LIKE '%Account%' 
        ORDER BY a.created_at DESC 
        LIMIT 50
    ");
    $audit_stmt->execute();
    $audit_log = $audit_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $audit_stmt->close();
} catch (Exception $e) {
    // Log the error for debugging
    error_log("Audit log query error: " . $e->getMessage());
    $audit_log = [];
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
    
} catch (Exception $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
    $stats = [
        'pending_users' => 0,
        'approved_users' => 0,
        'active_reports' => 0,
        'deactivated_users' => 0
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Dashboard - Account Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            min-height: 100vh;
            background: url("../../assets/img/cityhall.jpeg") center/cover no-repeat fixed;
            position: relative;
        }

        body::before {
            content: "";
            position: absolute;
            inset: 0;
            backdrop-filter: blur(6px);
            background: rgba(0, 0, 0, 0.35);
            z-index: 0;
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
            background: rgba(255, 255, 255, 0.95);
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
<body>
    <!-- SIDEBAR -->
    <iframe src="../includes/sidebar.php" 
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
                    <h1>📋 Accounts Approval</h1>
                    <p>Review and approve pending LGU Staff account registrations</p>
                </div>
                <div class="date-time">
                    <div id="currentDate"></div>
                    <div id="currentTime"></div>
                </div>
            </div>
        </div>

        <!-- Message Display -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

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
        </div>

        <!-- Workflow Container -->
        <div class="workflow-container">
            <!-- All Users Management -->
            <div class="workflow-card">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <i class="fas fa-user-clock"></i>
                        <span>Pending Account Approvals</span>
                        <span class="workflow-badge"><?php echo count($users); ?></span>
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
                                    <th>Registered</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; color: #64748b;">No users found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td><?php echo htmlspecialchars($user['role']); ?></td>
                                            <td><?php echo htmlspecialchars($user['department'] ?? 'N/A'); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn-sm btn-manage" onclick="showUserModal(<?php echo $user['id']; ?>)">Manage</button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Audit Log -->
            <div class="workflow-card">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <i class="fas fa-history"></i>
                        <span>Recent Admin Actions</span>
                        <span class="workflow-badge"><?php echo count($audit_log); ?></span>
                    </h3>
                </div>
                
                <div class="workflow-content">
                    <?php if (empty($audit_log)): ?>
                        <div class="log-entry">
                            <div class="log-action">No admin actions found</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($audit_log as $action): ?>
                            <div class="log-entry">
                                <div class="log-action"><?php echo htmlspecialchars($action['action']); ?></div>
                                <div class="log-details">
                                    <?php echo htmlspecialchars($action['details']); ?>
                                    <?php if ($action['admin_name']): ?>
                                        by <?php echo htmlspecialchars($action['admin_name']); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="log-time"><?php echo date('M d, Y H:i', strtotime($action['created_at'])); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <input type="hidden" name="action" id="modalAction">
                <input type="hidden" name="user_id" id="modalUserId">
                
                <div class="form-group">
                    <label for="remarks">Remarks (Optional)</label>
                    <textarea name="remarks" id="remarks" rows="3" placeholder="Add any notes about this action..."></textarea>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn-sm" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn-sm" id="modalSubmitBtn">Confirm</button>
                </div>
            </form>
        </div>
    </div>

    <!-- User Management Modal -->
    <div id="userModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">User Details</h2>
                <span class="close" onclick="closeUserModal()">&times;</span>
            </div>
            <div class="modal-form-grid">
                <div class="form-group">
                    <label>Email:</label>
                    <input type="email" id="modalEmail" disabled>
                </div>
                <div class="form-group">
                    <label>Full Name:</label>
                    <input type="text" id="modalFullName" disabled>
                </div>
                <div class="form-group">
                    <label>Role:</label>
                    <input type="text" id="modalRole" disabled>
                </div>
                <div class="form-group">
                    <label>Department:</label>
                    <input type="text" id="modalDepartment" disabled>
                </div>
                <div class="form-group">
                    <label>Address:</label>
                    <input type="text" id="modalAddress" disabled>
                </div>
                <div class="form-group">
                    <label>Birthday:</label>
                    <input type="text" id="modalBirthday" disabled>
                </div>
                <div class="form-group">
                    <label>Civil Status:</label>
                    <input type="text" id="modalCivilStatus" disabled>
                </div>
                <div class="form-group">
                    <label>Account Status:</label>
                    <input type="text" id="modalAccountStatus" disabled>
                </div>
                <div class="form-group">
                    <label>Created At:</label>
                    <input type="text" id="modalCreatedAt" disabled>
                </div>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label>ID File:</label>
                    <div id="modalIdFileContainer">
                        <img id="modalIdFile" src="" alt="ID File" style="max-width: 200px; max-height: 150px; border-radius: 8px; border: 1px solid #ddd; display: none;">
                        <p id="modalIdFileNone" style="color: #666; font-style: italic;">No ID file uploaded</p>
                    </div>
                </div>
            </div>
            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" class="btn-sm btn-approve" onclick="approveUser()">Approve</button>
                <button type="button" class="btn-sm btn-reject" onclick="rejectUser()">Reject</button>
                <button type="button" class="btn-sm btn-manage" onclick="closeUserModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        let currentUserId = null;
        let usersData = <?php echo json_encode($users); ?>;
        
        function showUserModal(userId) {
            console.log('Opening modal for user ID:', userId);
            currentUserId = userId;
            const user = usersData.find(u => u.id == userId);
            
            if (user) {
                // Display user info
                document.getElementById('modalEmail').value = user.email;
                document.getElementById('modalFullName').value = user.full_name;
                document.getElementById('modalRole').value = user.role;
                document.getElementById('modalDepartment').value = user.department || 'N/A';
                document.getElementById('modalAddress').value = user.address || 'N/A';
                document.getElementById('modalBirthday').value = user.birthday || 'N/A';
                document.getElementById('modalCivilStatus').value = user.civil_status ? user.civil_status.charAt(0).toUpperCase() + user.civil_status.slice(1) : 'N/A';
                document.getElementById('modalAccountStatus').value = user.is_active ? 'Active' : 'Inactive';
                document.getElementById('modalCreatedAt').value = user.created_at;
                
                // Display ID file
                const idFileImg = document.getElementById('modalIdFile');
                const idFileNone = document.getElementById('modalIdFileNone');
                if (user.id_file_path) {
                    idFileImg.src = '../' + user.id_file_path;
                    idFileImg.style.display = 'block';
                    idFileNone.style.display = 'none';
                } else {
                    idFileImg.style.display = 'none';
                    idFileNone.style.display = 'block';
                }
                
                // Show modal
                const modal = document.getElementById('userModal');
                modal.style.display = 'block';
            }
        }

        function closeUserModal() {
            const modal = document.getElementById('userModal');
            if (modal) {
                modal.style.display = 'none';
            }
            currentUserId = null;
        }

        function approveUser() {
            if (!currentUserId) return;
            
            if (confirm('Are you sure you want to approve this user account?')) {
                // Create form data
                const formData = new FormData();
                formData.append('action', 'approve');
                formData.append('user_id', currentUserId);
                formData.append('remarks', 'Approved by admin from dashboard');
                
                // Send request
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(async (response) => {
                    const contentType = response.headers.get('content-type') || '';
                    if (!response.ok) {
                        const text = await response.text();
                        throw new Error(text || `Request failed (${response.status})`);
                    }
                    if (!contentType.includes('application/json')) {
                        const text = await response.text();
                        throw new Error(text || 'Server did not return JSON');
                    }
                    return response.json();
                })
                .then(result => {
                    if (result.success) {
                        closeUserModal();
                        location.reload();
                    } else {
                        alert(result.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            }
        }

        function rejectUser() {
            if (!currentUserId) return;
            
            if (confirm('Are you sure you want to reject this user account?')) {
                // Create form data
                const formData = new FormData();
                formData.append('action', 'reject');
                formData.append('user_id', currentUserId);
                formData.append('remarks', 'Rejected by admin from dashboard');
                
                // Send request
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        closeUserModal();
                        location.reload();
                    } else {
                        alert(result.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            }
        }

        function openActionModal(action, userId, userName) {
            const modal = document.getElementById('actionModal');
            const title = document.getElementById('modalTitle');
            const actionField = document.getElementById('modalAction');
            const userIdField = document.getElementById('modalUserId');
            const submitBtn = document.getElementById('modalSubmitBtn');
            
            let actionText = '';
            let btnClass = '';
            
            switch(action) {
                case 'approve':
                    actionText = 'Approve Account';
                    btnClass = 'btn-approve';
                    break;
                case 'reject':
                    actionText = 'Reject Account';
                    btnClass = 'btn-reject';
                    break;
                case 'deactivate':
                    actionText = 'Deactivate Account';
                    btnClass = 'btn-deactivate';
                    break;
            }
            
            title.textContent = `${actionText} - ${userName}`;
            actionField.value = action;
            userIdField.value = userId;
            submitBtn.textContent = actionText;
            submitBtn.className = `btn-sm ${btnClass}`;
            
            modal.style.display = 'block';
        }
        
        function closeModal() {
            document.getElementById('actionModal').style.display = 'none';
            document.getElementById('actionForm').reset();
        }
        
        function deactivateUser(userId) {
            const actionMessage = 'Are you sure you want to deactivate this user account?';
            
            if (confirm(actionMessage)) {
                // Create form data
                const formData = new FormData();
                formData.append('action', 'deactivate_user');
                formData.append('user_id', userId);
                formData.append('remarks', 'Deactivated by admin');
                
                // Send request
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        location.reload();
                    } else {
                        alert(result.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            }
        }
        
        function activateUser(userId) {
            const actionMessage = 'Are you sure you want to activate this user account?';
            
            if (confirm(actionMessage)) {
                // Create form data
                const formData = new FormData();
                formData.append('action', 'activate_user');
                formData.append('user_id', userId);
                formData.append('remarks', 'Activated by admin');
                
                // Send request
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        location.reload();
                    } else {
                        alert(result.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            }
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('actionModal');
            if (event.target == modal) {
                closeModal();
            }
        }
        
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
        
        // Auto-hide messages after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);
    </script>
</body>
</html>
