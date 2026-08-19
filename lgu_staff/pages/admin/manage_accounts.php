<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();

// Suppress display_errors for AJAX/POST requests to preserve JSON response
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ini_set('display_errors', 0);
    error_reporting(0);
}
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? null;
    $userId = $_POST['user_id'] ?? null;
    $remarks = $_POST['remarks'] ?? '';

    // Lightweight endpoint: return the freshest last_activity for verified accounts
    // so the page can keep the activity indicators accurate without a full reload.
    if ($action === 'get_activity') {
        $activity = [];
        try {
            $act_stmt = $conn->prepare("
                SELECT id, last_activity
                FROM users
                WHERE role IN ('lgu_staff', 'citizen', 'road_ops_supervisor', 'trans_ops_supervisor', 'road_monitoring_officer', 'trans_monitoring_officer') AND account_status = 'verified'
            ");
            $act_stmt->execute();
            $act_res = $act_stmt->get_result();
            while ($row = $act_res->fetch_assoc()) {
                $activity[$row['id']] = !empty($row['last_activity']) ? date('c', strtotime($row['last_activity'])) : null;
            }
            $act_stmt->close();
        } catch (Exception $e) {
            error_log("get_activity error: " . $e->getMessage());
        }
        echo json_encode(['success' => true, 'activity' => $activity]);
        exit;
    }

    if (!$action || !$userId) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }

    if ($action === 'deactivate_user') {
        $stmt = $conn->prepare("UPDATE users SET is_active = 0, account_status = 'deactivated' WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();

        // audit log
        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, details, created_at) VALUES (?, 'Account Deactivated', ?, NOW())");
        $log->bind_param("is", $_SESSION['user_id'], $remarks);
        $log->execute();

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'activate_user') {
        $stmt = $conn->prepare("UPDATE users SET is_active = 1, account_status = 'verified' WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();

        // audit log
        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, details, created_at) VALUES (?, 'Account Activated', ?, NOW())");
        $log->bind_param("is", $_SESSION['user_id'], $remarks);
        $log->execute();

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'reactivate_user') {
        $stmt = $conn->prepare("UPDATE users SET is_active = 1, account_status = 'verified' WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();

        // audit log
        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, details, created_at) VALUES (?, 'Account Reactivated', ?, NOW())");
        $log->bind_param("is", $_SESSION['user_id'], $remarks);
        $log->execute();

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'update_user') {
        $full_name = $_POST['full_name'] ?? '';
        $role = $_POST['role'] ?? '';
        $department = $_POST['department'] ?? '';
        $address = $_POST['address'] ?? '';
        $birthday = $_POST['birthday'] ?? '';
        $civil_status = $_POST['civil_status'] ?? '';
        $phone_number = $_POST['phone_number'] ?? '';

        if (empty($full_name) || empty($role)) {
            echo json_encode(['success' => false, 'message' => 'Full name and role are required.']);
            exit;
        }

        $stmt = $conn->prepare("UPDATE users SET full_name = ?, role = ?, department = ?, address = ?, birthday = ?, civil_status = ?, phone_number = ?, updated_at = NOW() WHERE id = ?");
        $birthday_val = ($birthday !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) ? $birthday : null;
        $stmt->bind_param("sssssssi", $full_name, $role, $department, $address, $birthday_val, $civil_status, $phone_number, $userId);
        $stmt->execute();
        $stmt->close();

        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, details, created_at) VALUES (?, 'Account Updated', ?, NOW())");
        $details = "Updated account #$userId: $full_name";
        $log->bind_param("is", $_SESSION['user_id'], $details);
        $log->execute();
        $log->close();

        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// Get verified accounts only
$stmt = $conn->prepare("
    SELECT id, username, email, full_name, role, department, address, birthday, civil_status, phone_number, is_active, last_activity, created_at, updated_at, approved_at, rejected_at, id_file_path 
    FROM users 
    WHERE role IN ('lgu_staff', 'citizen', 'road_ops_supervisor', 'trans_ops_supervisor', 'road_monitoring_officer', 'trans_monitoring_officer') AND account_status = 'verified'
    ORDER BY created_at DESC
");
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get unverified/rejected accounts
$stmt2 = $conn->prepare("
    SELECT id, username, email, full_name, role, department, address, birthday, civil_status, phone_number, is_active, account_status, created_at, updated_at, approved_at, rejected_at, id_file_path 
    FROM users 
    WHERE role IN ('lgu_staff', 'citizen', 'road_ops_supervisor', 'trans_ops_supervisor', 'road_monitoring_officer', 'trans_monitoring_officer') AND account_status IN ('pending', 'rejected')
    ORDER BY created_at DESC
");
$stmt2->execute();
$unverified_users = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt2->close();

// Get deactivated accounts
$stmt3 = $conn->prepare("
    SELECT id, username, email, full_name, role, department, address, birthday, civil_status, phone_number, is_active, account_status, created_at, updated_at, approved_at, rejected_at, id_file_path 
    FROM users 
    WHERE role IN ('lgu_staff', 'citizen', 'road_ops_supervisor', 'trans_ops_supervisor', 'road_monitoring_officer', 'trans_monitoring_officer') AND account_status = 'deactivated'
    ORDER BY updated_at DESC
");
$stmt3->execute();
$deactivated_users = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt3->close();

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

// Calculate stats
$active_accounts = 0;
$inactive_accounts = 0;
foreach ($users as $user) {
    if ($user['is_active']) {
        $active_accounts++;
    } else {
        $inactive_accounts++;
    }
}

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
    // Log error for debugging
    error_log("Audit log query error: " . $e->getMessage());
    $audit_log = [];
}

// Format a timestamp into a human-friendly relative "last active" string.
// Used as a server-side fallback for the Verified Accounts activity indicator.
function formatLastActive($timestamp) {
    if (empty($timestamp) || $timestamp === '0000-00-00 00:00:00') {
        return 'Never active';
    }
    $ts = strtotime($timestamp);
    if (!$ts) {
        return 'Never active';
    }
    $diff = max(0, time() - $ts);

    if ($diff < 60) {
        return 'Active just now';
    }
    $mins = floor($diff / 60);
    if ($mins < 60) {
        return 'Active ' . $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
    }
    $hours = floor($mins / 60);
    if ($hours < 24) {
        return 'Active ' . $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    }
    $days = floor($hours / 24);
    if ($days < 7) {
        return 'Active ' . $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    }
    $weeks = floor($days / 7);
    if ($weeks < 5) {
        return 'Active ' . $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' ago';
    }
    $months = floor($days / 30);
    if ($months < 12) {
        return 'Active ' . $months . ' month' . ($months > 1 ? 's' : '') . ' ago';
    }
    $years = floor($days / 365);
    return 'Active ' . $years . ' year' . ($years > 1 ? 's' : '') . ' ago';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Accounts - LGU Road Monitoring</title>
    <link rel="icon" type="image/png" href="../../assets/img/logocityhall.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../css/theme-tokens.css">
    <link rel="stylesheet" href="../../css/theme-utilities.css">
    <link rel="stylesheet" href="../../css/sidebar.css?v=3">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <style>
        body {
            background: #f7f5f0;
            min-height: 100vh;
        }
        
        .main-content {
            position: relative;
            z-index: 1;
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
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 25px;
        }

        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: #f0f4fa;
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
            color: white;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 56px;
            height: 56px;
            margin: 0 auto 12px;
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

        .filter-section {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-dropdown {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            background: white;
            font-size: 14px;
        }

        .filter-button {
            padding: 8px 16px;
            background: #3762c8;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.2s;
        }

        .filter-button:hover {
            background: #2a4a9a;
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

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-verified {
            background: #10b981;
            color: white;
        }

        .status-inactive {
            background: #6c757d;
            color: white;
            font-weight: 600;
            border: 2px solid #495057;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .status-pending {
            background: #f59e0b;
            color: white;
        }

        .status-rejected {
            background: #ef4444;
            color: white;
        }

        .status-deactivated {
            background: #6c757d;
            color: white;
        }

        .activity-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            white-space: nowrap;
            line-height: 1.2;
        }

        .activity-indicator .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
            background: currentColor;
        }

        .activity-recent {
            background: var(--color-success-bg);
            color: var(--color-success-text);
        }

        .activity-moderate {
            background: var(--color-warning-bg);
            color: var(--color-warning-text);
        }

        .activity-idle {
            background: var(--color-info-bg);
            color: var(--color-info-text);
        }

        .activity-never {
            background: var(--bg-input-readonly);
            color: var(--text-secondary);
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

        .btn-placeholder {
            background: #64748b;
            color: white;
        }

        .btn-placeholder:hover {
            background: #475569;
        }

        .btn-edit {
            background: #3762c8;
            color: white;
        }

        .btn-edit:hover {
            background: #2a4a9a;
        }

        .btn-save {
            background: #10b981;
            color: white;
        }

        .btn-save:hover {
            background: #059669;
        }

        .btn-deactivate {
            background: #ef4444;
            color: white;
        }

        .btn-deactivate:hover {
            background: #dc2626;
        }

        .editable-field {
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .editable-field.editing {
            border-color: #3762c8 !important;
            box-shadow: 0 0 0 2px rgba(55, 98, 200, 0.2);
            background: #f8faff;
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

        /* Responsive Design */
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

            .workflow-container {
                grid-template-columns: 1fr;
            }

            .table-container {
                font-size: 14px;
            }

            th, td {
                padding: 8px;
            }
        }
    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <!-- SIDEBAR -->
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="main-content">
        <!-- Simple User Modal -->
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
                        <input type="text" id="modalFullName" class="editable-field" disabled>
                    </div>
                    <div class="form-group">
                        <label>Role:</label>
                        <input type="text" id="modalRole" class="editable-field" disabled>
                    </div>
                    <div class="form-group">
                        <label>Department:</label>
                        <input type="text" id="modalDepartment" class="editable-field" disabled>
                    </div>
                    <div class="form-group">
                        <label>Address:</label>
                        <input type="text" id="modalAddress" class="editable-field" disabled>
                    </div>
                    <div class="form-group">
                        <label>Birthday:</label>
                        <input type="date" id="modalBirthday" class="editable-field" disabled>
                    </div>
                    <div class="form-group">
                        <label>Civil Status:</label>
                        <select id="modalCivilStatus" class="editable-field" disabled>
                            <option value="">-- Select --</option>
                            <option value="single">Single</option>
                            <option value="married">Married</option>
                            <option value="widowed">Widowed</option>
                            <option value="separated">Separated</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Contact Number:</label>
                        <input type="tel" id="modalPhoneNumber" class="editable-field" maxlength="20" pattern="[0-9+\-\s()]+" title="Enter a valid contact number" disabled>
                    </div>
                    <div class="form-group">
                        <label>Account Status:</label>
                        <input type="text" id="modalAccountStatus" disabled>
                    </div>
                    <div class="form-group">
                        <label>Created At:</label>
                        <input type="text" id="modalCreatedAt" disabled>
                    </div>
                    <div class="form-group">
                        <label>Approved At:</label>
                        <input type="text" id="modalApprovedAt" disabled>
                    </div>
                    <div class="form-group">
                        <label>Rejected At:</label>
                        <input type="text" id="modalRejectedAt" disabled>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>ID File:</label>
                        <div id="modalIdFileContainer">
                            <img id="modalIdFile" src="" alt="ID File" style="max-width: 200px; max-height: 150px; border-radius: 8px; border: 1px solid #ddd; display: none;">
                            <p id="modalIdFileNone" style="font-style: italic;" class="t-text-secondary">No ID file uploaded</p>
                        </div>
                    </div>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" id="actionButton" class="btn-sm btn-approve"></button>
                    <button type="button" class="btn-sm btn-placeholder" onclick="closeUserModal()">Close</button>
                </div>
            </div>
        </div>

        <style>
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

            select.editable-field {
                width: 100%;
                padding: 8px;
                border: 1px solid #ddd;
                border-radius: 4px;
                box-sizing: border-box;
                background: white;
            }
            
            .modal-form-grid {
                display: grid;
                grid-template-columns: 1fr 1fr 1fr;
                gap: 15px;
            }
            
            .modal-form-grid .form-group {
                margin-bottom: 0;
            }
            
            button {
                background-color: #007bff;
                color: white;
                padding: 10px 20px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
            }
            
            button:hover {
                background-color: #0056b3;
            }
        </style>

        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="welcome-section">
                <div class="welcome-text">
                    <h1>👥 Manage Accounts</h1>
                    <p>Manage verified LGU Staff accounts</p>
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
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-number"><?php echo $active_accounts; ?></div>
                <div class="stat-label">Total Active Accounts</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-slash"></i>
                </div>
                <div class="stat-number"><?php echo $inactive_accounts; ?></div>
                <div class="stat-label">Total Inactive Accounts</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-clock"></i>
                </div>
                <div class="stat-number"><?php echo count($unverified_users); ?></div>
                <div class="stat-label">Pending/Rejected Accounts</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-xmark"></i>
                </div>
                <div class="stat-number"><?php echo count($deactivated_users); ?></div>
                <div class="stat-label">Deactivated Accounts</div>
            </div>
        </div>

        <div class="workflow-container">
            <!-- Verified Accounts -->
            <div class="workflow-card">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <i class="fas fa-users"></i>
                        <span>Verified Accounts</span>
                        <span class="workflow-badge"><?php echo count($users); ?></span>
                    </h3>
                    <div class="filter-section">
                        <label for="statusFilter" style="font-size: 14px;" class="t-text-secondary">Filter by:</label>
                        <select id="statusFilter" class="filter-dropdown">
                            <option value="all">All</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                        <button class="filter-button" onclick="applyFilter()">Go</button>
                    </div>
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
                                    <th>Status</th>
                                    <th>Active</th>
                                    <th>Last Active</th>
                                    <th>Registered</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="9" style="text-align: center;" class="t-text-secondary">No verified accounts found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td><?php echo htmlspecialchars($user['role']); ?></td>
                                            <td><?php echo htmlspecialchars($user['department'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span class="status-badge status-verified">Verified</span>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $user['is_active'] ? 'verified' : 'inactive'; ?>">
                                                    <?php echo $user['is_active'] ? 'Yes' : 'No'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="activity-indicator activity-recent" data-user-id="<?php echo $user['id']; ?>" data-last-active="<?php echo !empty($user['last_activity']) ? date('c', strtotime($user['last_activity'])) : ''; ?>" title="<?php echo !empty($user['last_activity']) ? date('M d, Y h:i A', strtotime($user['last_activity'])) : 'No activity recorded'; ?>">
                                                    <span class="dot"></span>
                                                    <span class="activity-text"><?php echo formatLastActive($user['last_activity']); ?></span>
                                                </span>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn-sm btn-placeholder" onclick="showUserModal(<?php echo $user['id']; ?>)">Manage</button>
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

            <!-- Deactivated Accounts -->
            <div class="workflow-card">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <i class="fas fa-user-xmark"></i>
                        <span>Deactivated Accounts</span>
                        <span class="workflow-badge"><?php echo count($deactivated_users); ?></span>
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
                                    <th>Status</th>
                                    <th>Deactivated On</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($deactivated_users)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center;" class="t-text-secondary">No deactivated accounts found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($deactivated_users as $user): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td><?php echo htmlspecialchars($user['role']); ?></td>
                                            <td><?php echo htmlspecialchars($user['department'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span class="status-badge status-deactivated">Deactivated</span>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($user['updated_at'])); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn-sm btn-approve" onclick="reactivateAccount(<?php echo $user['id']; ?>)"><i class="fas fa-rotate-right"></i> Reactivate</button>
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
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($inactive_2weeks_users)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center;" class="t-text-secondary">No inactive users found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($inactive_2weeks_users as $user): ?>
                                        <tr id="inactive-row-<?php echo $user['id']; ?>">
                                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td><?php echo htmlspecialchars($user['role']); ?></td>
                                            <td><?php echo htmlspecialchars($user['department'] ?? 'N/A'); ?></td>
                                            <td><?php echo $user['last_login'] ? date('M d, Y', strtotime($user['last_login'])) : 'Never'; ?></td>
                                            <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                            <td>
                                                <button class="btn-sm btn-deactivate" onclick="confirmDeactivate(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars(addslashes($user['full_name'])); ?>')">
                                                    <i class="fas fa-user-slash"></i> Deactivate
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    <!-- Deactivate Confirmation Modal -->
    <div id="deactivateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" style="color:#dc2626;"><i class="fas fa-exclamation-triangle"></i> Confirm Deactivation</h3>
                <span class="close" onclick="closeDeactivateModal()">&times;</span>
            </div>
            <p id="deactivateModalBody">Are you sure you want to deactivate this account?</p>
            <p style="font-size: 13px; color: #64748b; margin-bottom: 20px;">The user will no longer be able to log in or access the system.</p>
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn-sm btn-deactivate" id="deactivateConfirmBtn" onclick="executeDeactivate()">
                    <i class="fas fa-user-slash"></i> Deactivate Account
                </button>
                <button type="button" class="btn-sm btn-manage" onclick="closeDeactivateModal()">Cancel</button>
            </div>
        </div>
    </div>

    <script>
        let currentUserId = null;
        let isEditing = false;
        let usersData = <?php echo json_encode($users); ?>;
        
        const editableFields = ['modalFullName', 'modalRole', 'modalDepartment', 'modalAddress', 'modalBirthday', 'modalCivilStatus', 'modalPhoneNumber'];

        function showUserModal(userId) {
            console.log('Opening modal for user ID:', userId);
            currentUserId = userId;
            isEditing = false;
            const user = usersData.find(u => u.id == userId);
            
            if (user) {
                // Display user info
                document.getElementById('modalEmail').value = user.email;
                document.getElementById('modalFullName').value = user.full_name;
                document.getElementById('modalRole').value = user.role;
                document.getElementById('modalDepartment').value = user.department || '';
                document.getElementById('modalAddress').value = user.address || '';
                document.getElementById('modalBirthday').value = user.birthday || '';
                document.getElementById('modalCivilStatus').value = user.civil_status || '';
                document.getElementById('modalPhoneNumber').value = user.phone_number || '';
                document.getElementById('modalAccountStatus').value = user.is_active ? 'Active' : 'Inactive';
                document.getElementById('modalCreatedAt').value = user.created_at;
                document.getElementById('modalApprovedAt').value = user.approved_at || 'N/A';
                document.getElementById('modalRejectedAt').value = user.rejected_at || 'N/A';
                
                // Display ID file
                const idFileImg = document.getElementById('modalIdFile');
                const idFileNone = document.getElementById('modalIdFileNone');
                if (user.id_file_path) {
                    idFileImg.src = '../../' + user.id_file_path;
                    idFileImg.style.display = 'block';
                    idFileNone.style.display = 'none';
                } else {
                    idFileImg.style.display = 'none';
                    idFileNone.style.display = 'block';
                }
                
                // Reset edit mode
                setFieldsDisabled(true);
                
                // Set dynamic button
                const actionButton = document.getElementById('actionButton');
                actionButton.style.display = '';
                if (user.is_active) {
                    actionButton.textContent = 'Deactivate Account';
                    actionButton.className = 'btn-sm btn-deactivate';
                    actionButton.onclick = deactivateAccount;
                } else {
                    actionButton.textContent = 'Activate Account';
                    actionButton.className = 'btn-sm btn-approve';
                    actionButton.onclick = activateAccount;
                }
                
                // Show modal
                const modal = document.getElementById('userModal');
                modal.style.display = 'block';
            }
        }

        function setFieldsDisabled(disabled) {
            editableFields.forEach(function(id) {
                const el = document.getElementById(id);
                el.disabled = disabled;
                el.classList.toggle('editing', !disabled);
            });
        }

        function closeUserModal() {
            const modal = document.getElementById('userModal');
            if (modal) {
                modal.style.display = 'none';
            }
            currentUserId = null;
            isEditing = false;
        }

        function deactivateAccount() {
            if (!currentUserId) return;
            
            if (confirm('Are you sure you want to deactivate this user account?')) {
                // Create form data
                const formData = new FormData();
                formData.append('action', 'deactivate_user');
                formData.append('user_id', currentUserId);
                formData.append('remarks', 'Deactivated by admin from Manage Accounts');
                
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

        function activateAccount() {
            if (!currentUserId) return;
            
            if (confirm('Are you sure you want to activate this user account?')) {
                // Create form data
                const formData = new FormData();
                formData.append('action', 'activate_user');
                formData.append('user_id', currentUserId);
                formData.append('remarks', 'Activated by admin from Manage Accounts');
                
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

        function reactivateAccount(userId) {
            if (confirm('Are you sure you want to reactivate this account?')) {
                const formData = new FormData();
                formData.append('action', 'reactivate_user');
                formData.append('user_id', userId);
                formData.append('remarks', 'Reactivated by admin from Manage Accounts');
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        location.reload();
                    } else {
                        alert(result.message || 'Failed to reactivate account.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            }
        }

        function applyFilter() {
            const filterValue = document.getElementById('statusFilter').value;
            const rows = document.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                if (filterValue === 'all') {
                    row.style.display = '';
                } else {
                    const activeCell = row.querySelector('td:nth-child(6)'); // Active column
                    const isActive = activeCell && activeCell.textContent.trim() === 'Yes';
                    
                    if (filterValue === 'active' && isActive) {
                        row.style.display = '';
                    } else if (filterValue === 'inactive' && !isActive) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                }
            });
            
            // Update badge count
            const visibleRows = Array.from(rows).filter(row => row.style.display !== 'none');
            const badge = document.querySelector('.workflow-badge');
            if (badge) {
                badge.textContent = visibleRows.length;
            }
        }

        function updateDateTime() {
            const now = new Date();
            const dateOptions = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const timeOptions = { hour: '2-digit', minute: '2-digit', second: '2-digit' };
            
            document.getElementById('currentDate').textContent = now.toLocaleDateString('en-US', dateOptions);
            document.getElementById('currentTime').textContent = now.toLocaleTimeString('en-US', timeOptions);
        }
        
        updateDateTime();
        setInterval(updateDateTime, 1000);

        function lastActiveDiff(iso) {
            if (!iso) return null;
            const last = new Date(iso);
            if (isNaN(last.getTime())) return null;
            return Math.max(0, Date.now() - last.getTime());
        }

        function formatLastActiveJS(ms) {
            if (ms === null) return 'Never active';
            const sec = Math.floor(ms / 1000);
            if (sec < 60) return 'Active just now';
            const min = Math.floor(sec / 60);
            if (min < 60) return 'Active ' + min + ' min' + (min > 1 ? 's' : '') + ' ago';
            const hr = Math.floor(min / 60);
            if (hr < 24) return 'Active ' + hr + ' hour' + (hr > 1 ? 's' : '') + ' ago';
            const day = Math.floor(hr / 24);
            if (day < 7) return 'Active ' + day + ' day' + (day > 1 ? 's' : '') + ' ago';
            const wk = Math.floor(day / 7);
            if (wk < 5) return 'Active ' + wk + ' week' + (wk > 1 ? 's' : '') + ' ago';
            const mo = Math.floor(day / 30);
            if (mo < 12) return 'Active ' + mo + ' month' + (mo > 1 ? 's' : '') + ' ago';
            const yr = Math.floor(day / 365);
            return 'Active ' + yr + ' year' + (yr > 1 ? 's' : '') + ' ago';
        }

        function lastActiveClass(ms) {
            if (ms === null) return 'activity-never';
            const hr = ms / 3600000;
            if (hr < 1) return 'activity-recent';
            if (hr < 24) return 'activity-moderate';
            return 'activity-idle';
        }

        function updateActivityIndicators() {
            document.querySelectorAll('[data-last-active]').forEach(function(el) {
                const ms = lastActiveDiff(el.getAttribute('data-last-active') || '');
                const textEl = el.querySelector('.activity-text');
                if (textEl) textEl.textContent = formatLastActiveJS(ms);
                el.classList.remove('activity-recent', 'activity-moderate', 'activity-idle', 'activity-never');
                el.classList.add(lastActiveClass(ms));
            });
        }

        function formatAbsoluteTitle(iso) {
            if (!iso) return 'No activity recorded';
            const d = new Date(iso);
            if (isNaN(d.getTime())) return 'No activity recorded';
            const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            const pad = n => (n < 10 ? '0' : '') + n;
            return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear() + ' ' +
                   pad(d.getHours()) + ':' + pad(d.getMinutes());
        }

        function refreshActivityData() {
            const formData = new FormData();
            formData.append('action', 'get_activity');
            fetch('', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (!data || !data.success || !data.activity) return;
                    const activity = data.activity;
                    document.querySelectorAll('[data-last-active]').forEach(function(el) {
                        const uid = el.getAttribute('data-user-id');
                        if (uid && Object.prototype.hasOwnProperty.call(activity, uid)) {
                            const iso = activity[uid] || '';
                            if (el.getAttribute('data-last-active') !== iso) {
                                el.setAttribute('data-last-active', iso);
                                el.title = formatAbsoluteTitle(iso);
                                const ms = lastActiveDiff(iso);
                                const textEl = el.querySelector('.activity-text');
                                if (textEl) textEl.textContent = formatLastActiveJS(ms);
                                el.classList.remove('activity-recent', 'activity-moderate', 'activity-idle', 'activity-never');
                                el.classList.add(lastActiveClass(ms));
                            }
                        }
                    });
                })
                .catch(function() {
                    // Silently ignore transient network failures; next poll will retry
                });
        }

        updateActivityIndicators();
        setInterval(updateActivityIndicators, 30000);
        refreshActivityData();
        setInterval(refreshActivityData, 30000);

        // Deactivate Account - Modal and AJAX
        let pendingDeactivateUserId = null;

        function confirmDeactivate(userId, userName) {
            pendingDeactivateUserId = userId;
            document.getElementById('deactivateModalBody').innerHTML =
                'Are you sure you want to deactivate the account for <strong>' + userName + '</strong>?';
            document.getElementById('deactivateModal').style.display = 'flex';
        }

        function closeDeactivateModal() {
            document.getElementById('deactivateModal').style.display = 'none';
            pendingDeactivateUserId = null;
        }

        function executeDeactivate() {
            if (!pendingDeactivateUserId) return;

            const btn = document.getElementById('deactivateConfirmBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deactivating...';

            const formData = new FormData();
            formData.append('action', 'deactivate_user');
            formData.append('user_id', pendingDeactivateUserId);
            formData.append('remarks', 'Deactivated by admin from Manage Accounts (inactive users)');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-user-slash"></i> Deactivate Account';
                if (result.success) {
                    closeDeactivateModal();
                    const row = document.getElementById('inactive-row-' + pendingDeactivateUserId);
                    if (row) {
                        row.style.transition = 'opacity 0.3s';
                        row.style.opacity = '0';
                        setTimeout(() => row.remove(), 300);
                    }
                } else {
                    alert(result.message || 'Failed to deactivate account.');
                }
            })
            .catch(error => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-user-slash"></i> Deactivate Account';
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        }
    </script>
    

</body>
</html>
