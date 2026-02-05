<?php
// Start session and include authentication
session_start();
require_once '../config/auth.php';

// Set timezone to ensure accurate time calculations
date_default_timezone_set('Asia/Manila');

// Require admin role to access this page
$auth->requireRole('admin');

// Log page access
$auth->logActivity('page_access', 'Accessed user permission management');

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_role') {
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $newRole = filter_input(INPUT_POST, 'role', FILTER_SANITIZE_STRING);

        if ($userId && $newRole && in_array($newRole, ['citizen', 'engineer', 'lgu_officer', 'admin'])) {
            try {
                $database = new Database();
                $conn = $database->getConnection();

                $stmt = $conn->prepare("UPDATE users SET role = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->bind_param("si", $newRole, $userId);

                if ($stmt->execute()) {
                    $message = "User role updated successfully";
                    $messageType = "success";
                    $auth->logActivity('role_update', "Updated role for user ID: $userId to $newRole");
                } else {
                    $message = "Failed to update user role";
                    $messageType = "error";
                }

                $stmt->close();
            } catch (Exception $e) {
                error_log("Error updating user role: " . $e->getMessage());
                $message = "An error occurred while updating user role";
                $messageType = "error";
            }
        }
    }

    if ($action === 'update_status') {
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $newStatus = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);

        if ($userId && $newStatus && in_array($newStatus, ['pending', 'active', 'inactive', 'suspended'])) {
            try {
                $database = new Database();
                $conn = $database->getConnection();

                $stmt = $conn->prepare("UPDATE users SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->bind_param("si", $newStatus, $userId);

                if ($stmt->execute()) {
                    $message = "User status updated successfully";
                    $messageType = "success";
                    $auth->logActivity('status_update', "Updated status for user ID: $userId to $newStatus");
                } else {
                    $message = "Failed to update user status";
                    $messageType = "error";
                }

                $stmt->close();
            } catch (Exception $e) {
                error_log("Error updating user status: " . $e->getMessage());
                $message = "An error occurred while updating user status";
                $messageType = "error";
            }
        }
    }

    if ($action === 'approve_user') {
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

        if ($userId) {
            try {
                $database = new Database();
                $conn = $database->getConnection();

                $stmt = $conn->prepare("UPDATE users SET status = 'active', email_verified = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->bind_param("i", $userId);

                if ($stmt->execute()) {
                    $message = "User account approved successfully";
                    $messageType = "success";
                    $auth->logActivity('user_approval', "Approved user ID: $userId");
                } else {
                    $message = "Failed to approve user account";
                    $messageType = "error";
                }

                $stmt->close();
            } catch (Exception $e) {
                error_log("Error approving user: " . $e->getMessage());
                $message = "An error occurred while approving user";
                $messageType = "error";
            }
        }
    }

    if ($action === 'reject_user') {
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

        if ($userId) {
            try {
                $database = new Database();
                $conn = $database->getConnection();

                $stmt = $conn->prepare("UPDATE users SET status = 'rejected', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->bind_param("i", $userId);

                if ($stmt->execute()) {
                    $message = "User account rejected successfully";
                    $messageType = "success";
                    $auth->logActivity('user_rejection', "Rejected user ID: $userId");
                } else {
                    $message = "Failed to reject user account";
                    $messageType = "error";
                }

                $stmt->close();
            } catch (Exception $e) {
                error_log("Error rejecting user: " . $e->getMessage());
                $message = "An error occurred while rejecting user";
                $messageType = "error";
            }
        }
    }

    if ($action === 'delete_user') {
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

        if ($userId) {
            try {
                $database = new Database();
                $conn = $database->getConnection();

                // Prevent deletion of admin users or self
                if ($userId == $_SESSION['user_id']) {
                    $message = "You cannot delete your own account";
                    $messageType = "error";
                } else {
                    // Check if user is admin before deletion
                    $checkStmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
                    $checkStmt->bind_param("i", $userId);
                    $checkStmt->execute();
                    $result = $checkStmt->get_result();
                    $userToDelete = $result->fetch_assoc();

                    if ($userToDelete && $userToDelete['role'] === 'admin') {
                        $message = "You cannot delete admin accounts";
                        $messageType = "error";
                    } else {
                        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                        $stmt->bind_param("i", $userId);

                        if ($stmt->execute()) {
                            $message = "User account deleted successfully";
                            $messageType = "success";
                            $auth->logActivity('user_deletion', "Deleted user ID: $userId");
                        } else {
                            $message = "Failed to delete user account";
                            $messageType = "error";
                        }
                        $stmt->close();
                    }
                    $checkStmt->close();
                }

            } catch (Exception $e) {
                error_log("Error deleting user: " . $e->getMessage());
                $message = "An error occurred while deleting user";
                $messageType = "error";
            }
        }
    }

    if ($action === 'view_permissions') {
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

        if ($userId) {
            try {
                $database = new Database();
                $conn = $database->getConnection();

                $stmt = $conn->prepare("
                    SELECT id, module, permission, granted_at 
                    FROM user_permissions 
                    WHERE user_id = ? 
                    ORDER BY granted_at DESC
                ");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $result = $stmt->get_result();
                
                $permissions = [];
                while ($row = $result->fetch_assoc()) {
                    $permissions[] = $row;
                }
                $stmt->close();

                header('Content-Type: application/json');
                echo json_encode($permissions);
                exit;
            } catch (Exception $e) {
                error_log("Error fetching permissions: " . $e->getMessage());
                header('Content-Type: application/json');
                echo json_encode([]);
                exit;
            }
        }
    }

    if ($action === 'view') {
        $database = new Database();
        $conn = $database->getConnection();
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        // Add a hidden div for AJAX
        echo '<div id="ajaxUserData" style="display:none;">' . json_encode($user) . '</div>';
    }

    if ($action === 'grant_permission') {
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $permission = filter_input(INPUT_POST, 'permission', FILTER_SANITIZE_STRING);
        $module = filter_input(INPUT_POST, 'module', FILTER_SANITIZE_STRING);

        if ($userId && $permission && $module) {
            try {
                $database = new Database();
                $conn = $database->getConnection();

                // Check if permission already exists
                $checkStmt = $conn->prepare("SELECT id FROM user_permissions WHERE user_id = ? AND permission = ? AND module = ?");
                $checkStmt->bind_param("iss", $userId, $permission, $module);
                $checkStmt->execute();
                $existing = $checkStmt->get_result()->num_rows > 0;
                $checkStmt->close();

                if (!$existing) {
                    // Get user details to check if they are a citizen
                    $userCheckStmt = $conn->prepare("SELECT role, first_name FROM users WHERE id = ?");
                    $userCheckStmt->bind_param("i", $userId);
                    $userCheckStmt->execute();
                    $userResult = $userCheckStmt->get_result();
                    $userInfo = $userResult->fetch_assoc();
                    $userCheckStmt->close();
                    
                    $stmt = $conn->prepare("INSERT INTO user_permissions (user_id, permission, module, granted_by, granted_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)");
                    $adminId = $_SESSION['user_id'];
                    $stmt->bind_param("issi", $userId, $permission, $module, $adminId);

                    if ($stmt->execute()) {
                        $message = "Permission granted successfully";
                        $messageType = "success";
                        $auth->logActivity('permission_grant', "Granted $permission permission for module $module to user ID: $userId");
                        
                        // Create notification for citizen users
                        if ($userInfo && $userInfo['role'] === 'citizen') {
                            $notificationTitle = "New Permission Granted";
                            $notificationMessage = "You have been granted '$permission' permission for the '$module' module. You can now access this feature.";
                            $auth->createNotification($userId, $notificationTitle, $notificationMessage, 'permission_granted');
                        }
                    } else {
                        $message = "Failed to grant permission";
                        $messageType = "error";
                    }
                    $stmt->close();
                } else {
                    $message = "Permission already exists for this user";
                    $messageType = "error";
                }
            } catch (Exception $e) {
                error_log("Error granting permission: " . $e->getMessage());
                $message = "An error occurred while granting permission";
                $messageType = "error";
            }
        }
    }

    if ($action === 'revoke_permission') {
        $permissionId = filter_input(INPUT_POST, 'permission_id', FILTER_VALIDATE_INT);

        if ($permissionId) {
            try {
                $database = new Database();
                $conn = $database->getConnection();

                $stmt = $conn->prepare("DELETE FROM user_permissions WHERE id = ?");
                $stmt->bind_param("i", $permissionId);

                if ($stmt->execute()) {
                    $message = "Permission revoked successfully";
                    $messageType = "success";
                    $auth->logActivity('permission_revoke', "Revoked permission ID: $permissionId");
                } else {
                    $message = "Failed to revoke permission";
                    $messageType = "error";
                }
                $stmt->close();
            } catch (Exception $e) {
                error_log("Error revoking permission: " . $e->getMessage());
                $message = "An error occurred while revoking permission";
                $messageType = "error";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Permissions | LGU Admin Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url("https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap");

        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --glass-bg: rgba(255, 255, 255, 0.85);
            --glass-border: rgba(255, 255, 255, 0.4);
            --text-main: #1e293b;
            --text-muted: #64748b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Inter", sans-serif;
        }

        body {
            height: 100vh;
            background: url('../user_and_access_management_module/assets/img/cityhall.jpeg') center/cover no-repeat fixed;
            position: relative;
            overflow: hidden;
            color: var(--text-main);
        }

        body::before {
            content: "";
            position: absolute;
            inset: 0;
            backdrop-filter: blur(8px);
            background: rgba(15, 23, 42, 0.45);
            z-index: 0;
        }

        .main-content {
            position: relative;
            margin-left: 250px;
            height: 100vh;
            padding: 30px 40px;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            z-index: 1;
        }

        /* Scrollbar Styling */
        .main-content::-webkit-scrollbar {
            width: 10px;
        }

        .main-content::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
        }

        .main-content::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 10px;
            border: 2px solid transparent;
            background-clip: content-box;
        }

        .main-content::-webkit-scrollbar-thumb:hover {
            background: #555;
            background-clip: content-box;
        }

        /* Module Header */
        .module-header {
            color: white;
            margin-bottom: 30px;
        }

        .module-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .module-header p {
            font-size: 1rem;
            opacity: 0.9;
            letter-spacing: 0.5px;
        }

        .header-divider {
            border: none;
            height: 1px;
            background: rgba(255, 255, 255, 0.3);
            margin: 15px 0;
        }

        /* Message Styles */
        .message {
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            font-weight: 600;
            backdrop-filter: blur(10px);
        }

        /* Scrollbar Styling */
        .main-content::-webkit-scrollbar {
            width: 8px;
        }

        .main-content::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
        }

        .main-content::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
        }

        .main-content::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        /* Registry Card and Table Scroll */
        .registry-card {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            border: 1px solid var(--glass-border);
            padding: 0;
            margin-bottom: 30px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            max-height: calc(100vh - 250px); /* Limit height for scroll */
        }

        .registry-header {
            padding: 25px 30px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }

        .registry-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: #1e293b;
        }

        /* Search Section */
        .search-container {
            position: relative;
            width: 300px;
        }

        .search-input {
            width: 100%;
            padding: 10px 15px 10px 40px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            font-size: 0.9rem;
            outline: none;
            transition: all 0.2s;
        }

        .search-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        .table-scroll-container {
            overflow-y: auto;
            flex-grow: 1;
        }

        /* Table Styling */
        .user-table {
            width: 100%;
            border-collapse: collapse;
        }

        .user-table thead {
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .user-table th {
            text-align: left;
            padding: 15px 25px;
            background: #ffffff !important; /* Force opaque background */
            font-size: 0.8rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #e2e8f0;
            box-shadow: 0 1px 0 rgba(0,0,0,0.05); /* Subtle bottom shadow */
        }

        .user-table td {
            padding: 15px 25px;
            font-size: 0.95rem;
            color: #334155;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        /* Custom scrollbar for table container */
        .table-scroll-container::-webkit-scrollbar {
            width: 8px;
        }

        .table-scroll-container::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        .table-scroll-container::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        .table-scroll-container::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Role Badges */
        .badge {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 20px;
            margin-bottom: 2rem;
            font-weight: 600;
            backdrop-filter: blur(10px);
        }

        .message.success {
            background: rgba(212, 237, 218, 0.9);
            color: #155724;
            border: 1px solid rgba(195, 230, 203, 0.5);
        }

        .message.error {
            background: rgba(248, 215, 218, 0.9);
            color: #721c24;
            border: 1px solid rgba(245, 198, 203, 0.5);
        }

        /* Registry Card */
        .registry-card {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            border: 1px solid var(--glass-border);
            padding: 0;
            margin-bottom: 30px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .registry-header {
            padding: 30px;
            border-bottom: 1px solid #f1f5f9;
        }

        .registry-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: #1e293b;
        }

        /* Table Styling */
        .user-table {
            width: 100%;
            border-collapse: collapse;
        }

        .user-table th {
            text-align: left;
            padding: 15px 25px;
            background: rgba(241, 245, 249, 0.5);
            font-size: 0.8rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #e2e8f0;
        }

        .user-table td {
            padding: 20px 25px;
            font-size: 0.95rem;
            color: #334155;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        /* Role Badges */
        .badge {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 700;
            text-align: center;
            min-width: 100px;
        }

        .badge-lgu_officer { background: #10b981; color: white; }
        .badge-admin { background: #ef4444; color: white; }
        .badge-engineer { background: #2563eb; color: white; }
        .badge-citizen { background: #64748b; color: white; }

        /* Status Pills */
        .status-pill {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
            text-align: center;
            min-width: 90px;
        }

        .status-active { background: #dcfce7; color: #10b981; }
        .status-pending { background: #fef3c7; color: #d97706; }
        .status-rejected { background: #fee2e2; color: #ef4444; }

        /* Action Buttons */
        .action-group {
            display: flex;
            gap: 8px;
        }

        .btn-action {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            color: white;
            text-decoration: none;
        }

        .btn-view { background: #6366f1; }
        .btn-approve { background: #10b981; }
        .btn-reject { background: #ef4444; }
        .btn-delete { background: #94a3b8; }

        .btn-action:hover {
            transform: translateY(-2px);
            opacity: 0.9;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 20px;
            width: 450px;
            max-width: 90%;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.2);
        }

    </style>
</head>
<body>
    <?php include '../sidebar/admin_sidebar.php'; ?>

    <main class="main-content">
        <header class="module-header">
            <h1><i class="fas fa-shield-alt"></i> User Permissions</h1>
            <p>Directly manage system access levels and account statuses</p>
            <hr class="header-divider">
        </header>

        <?php if ($message): ?>
            <div class="message <?php echo htmlspecialchars($messageType); ?>">
                <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="registry-card">
            <div class="registry-header">
                <h2 class="registry-title">Permissions Registry</h2>
                <div class="search-container">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" id="userSearch" class="search-input" placeholder="Search by name, email or role..." onkeyup="filterTable()">
                </div>
            </div>
            <div class="table-scroll-container">
                <table class="user-table" id="registryTable">
                    <thead>
                        <tr>
                            <th width="60">ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Phone</th>
                            <th width="150">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        try {
                            $database = new Database();
                            $conn = $database->getConnection();

                            $stmt = $conn->prepare("
                                SELECT id, first_name, middle_name, last_name, email, role, status, phone, address, created_at, last_login 
                                FROM users 
                                ORDER BY created_at DESC
                            ");
                            $stmt->execute();
                            $result = $stmt->get_result();

                            if ($result->num_rows === 0) {
                                echo '<tr><td colspan="7" style="text-align: center; padding: 40px; color: #94a3b8;">No users found in registry</td></tr>';
                            }

                            while ($user = $result->fetch_assoc()):
                                $roleLabel = ucfirst(str_replace('_', ' ', $user['role']));
                                $fullName = htmlspecialchars($user['first_name'] . ' ' . ($user['middle_name'] ? $user['middle_name'] . ' ' : '') . $user['last_name']);
                                
                                // Debug: Log user data for troubleshooting
                                error_log("User ID {$user['id']} data: " . print_r($user, true));
                            ?>
                                <tr>
                                    <td><?php echo $user['id']; ?></td>
                                    <td style="font-weight: 600;"><?php echo $fullName; ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><span class="badge badge-<?php echo $user['role']; ?>"><?php echo $roleLabel; ?></span></td>
                                    <td><span class="status-pill status-<?php echo $user['status']; ?>"><?php echo ucfirst($user['status']); ?></span></td>
                                    <td><?php echo $user['phone'] ? htmlspecialchars($user['phone']) : '<span style="color: #94a3b8;">N/A</span>'; ?></td>
                                    <td>
                                        <div class="action-group">
                                            <button class="btn-action btn-view" onclick="fetchUser(<?php echo $user['id']; ?>)" title="View Details"><i class="fas fa-eye"></i></button>
                                            <?php if ($user['status'] === 'pending'): ?>
                                                <button class="btn-action btn-approve" onclick="handleAction('approve_user', <?php echo $user['id']; ?>)" title="Approve"><i class="fas fa-check"></i></button>
                                                <button class="btn-action btn-reject" onclick="handleAction('reject_user', <?php echo $user['id']; ?>)" title="Reject"><i class="fas fa-times"></i></button>
                                            <?php endif; ?>

                                            <?php if ($user['id'] != $_SESSION['user_id'] && $user['role'] !== 'admin'): ?>
                                                <button class="btn-action btn-delete" onclick="handleAction('delete_user', <?php echo $user['id']; ?>)" title="Delete"><i class="fas fa-trash"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile;
                            $stmt->close();
                        } catch (Exception $e) {
                            echo '<tr><td colspan="7" style="text-align: center; padding: 40px; color: #ef4444;">Error loading user registry: ' . htmlspecialchars($e->getMessage()) . '</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

            </main>

    <!-- User Details Modal -->
    <div id="userModal" class="modal">
        <div class="modal-content">
            <h3 id="modalTitle" style="margin-bottom: 20px; color: #1e293b;">User Details</h3>
            <div id="modalBody"></div>
            <div style="margin-top: 25px; display: flex; justify-content: flex-end;">
                <button onclick="closeModal()" style="padding: 10px 20px; border-radius: 8px; border: 1px solid #e2e8f0; cursor: pointer;">Close</button>
            </div>
        </div>
    </div>

    <!-- Hidden form for actions -->
    <form id="actionForm" method="POST" style="display: none;">
        <input type="hidden" name="action" id="formAction">
        <input type="hidden" name="user_id" id="formUserId">
    </form>

    <script>
        function handleAction(action, userId) {
            let confirmMsg = "";
            if (action === 'approve_user') confirmMsg = "Approve this user?";
            if (action === 'reject_user') confirmMsg = "Reject this user?";
            if (action === 'delete_user') confirmMsg = "Permanently delete this user?";
            
            if (confirmMsg && confirm(confirmMsg)) {
                document.getElementById('formAction').value = action;
                document.getElementById('formUserId').value = userId;
                document.getElementById('actionForm').submit();
            }
        }

        function fetchUser(userId) {
            const formData = new FormData();
            formData.append('action', 'view');
            formData.append('user_id', userId);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(res => res.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const userDataEl = doc.getElementById('ajaxUserData');
                if (userDataEl) {
                    const user = JSON.parse(userDataEl.textContent);
                    showModal(user);
                }
            });
        }

        function showModal(user) {
            const modal = document.getElementById('userModal');
            const body = document.getElementById('modalBody');
            
            // Format birthday if exists
            let birthdayText = 'N/A';
            if (user.birthday) {
                const birthday = new Date(user.birthday);
                birthdayText = birthday.toLocaleDateString('en-US', { 
                    year: 'numeric', 
                    month: 'long', 
                    day: 'numeric' 
                });
            }
            
            // Format last login if exists
            let lastLoginText = 'Never';
            if (user.last_login) {
                const lastLogin = new Date(user.last_login);
                lastLoginText = lastLogin.toLocaleString('en-US', { 
                    year: 'numeric', 
                    month: 'short', 
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            }
            
            body.innerHTML = `
                <div style="display: grid; gap: 12px;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <p><strong>First Name:</strong> ${user.first_name || 'N/A'}</p>
                            <p><strong>Middle Name:</strong> ${user.middle_name || 'N/A'}</p>
                            <p><strong>Last Name:</strong> ${user.last_name || 'N/A'}</p>
                        </div>
                        <div>
                            <p><strong>Email:</strong> ${user.email || 'N/A'}</p>
                            <p><strong>Phone:</strong> ${user.phone || 'N/A'}</p>
                            <p><strong>Role:</strong> ${user.role || 'N/A'}</p>
                        </div>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div>
                            <p><strong>Status:</strong> ${user.status || 'N/A'}</p>
                            <p><strong>Civil Status:</strong> ${user.civil_status || 'N/A'}</p>
                        </div>
                        <div>
                            <p><strong>Birthday:</strong> ${birthdayText}</p>
                            <p><strong>Address:</strong> ${user.address || 'N/A'}</p>
                        </div>
                    </div>
                    <div style="border-top: 1px solid #e5e7eb; padding-top: 12px;">
                        <p><strong>Registered:</strong> ${new Date(user.created_at).toLocaleString('en-US', { 
                            year: 'numeric', 
                            month: 'short', 
                            day: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit'
                        })}</p>
                        <p><strong>Last Login:</strong> ${lastLoginText}</p>
                    </div>
                </div>
            `;
            modal.style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('userModal').style.display = 'none';
        }

        window.onclick = function(event) {
            if (event.target == document.getElementById('userModal')) {
                closeModal();
            }
        }
        function filterTable() {
            const input = document.getElementById('userSearch');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('registryTable');
            const tr = table.getElementsByTagName('tr');

            for (let i = 1; i < tr.length; i++) {
                const tdName = tr[i].getElementsByTagName('td')[1];
                const tdEmail = tr[i].getElementsByTagName('td')[2];
                const tdRole = tr[i].getElementsByTagName('td')[3];
                const tdPhone = tr[i].getElementsByTagName('td')[5];
                
                if (tdName || tdEmail || tdRole || tdPhone) {
                    const nameText = tdName.textContent || tdName.innerText;
                    const emailText = tdEmail.textContent || tdEmail.innerText;
                    const roleText = tdRole.textContent || tdRole.innerText;
                    const phoneText = tdPhone.textContent || tdPhone.innerText;
                    
                    if (nameText.toLowerCase().indexOf(filter) > -1 || 
                        emailText.toLowerCase().indexOf(filter) > -1 || 
                        roleText.toLowerCase().indexOf(filter) > -1 ||
                        phoneText.toLowerCase().indexOf(filter) > -1) {
                        tr[i].style.display = "";
                    } else {
                        tr[i].style.display = "none";
                    }
                }
            }
        }
    </script>
</body>
</html>
