<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

// Check if user is logged in and is system admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'system_admin') {
    header('Location: ../../login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? null;
    $userId = $_POST['user_id'] ?? null;
    $remarks = $_POST['remarks'] ?? '';

    if (!$action || !$userId) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }

    if ($action === 'deactivate_user') {
        $stmt = $conn->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();

        // audit log
        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, 'Account Deactivated', ?)");
        $log->bind_param("is", $_SESSION['user_id'], $remarks);
        $log->execute();

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'activate_user') {
        $stmt = $conn->prepare("UPDATE users SET is_active = 1 WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();

        // audit log
        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, 'Account Activated', ?)");
        $log->bind_param("is", $_SESSION['user_id'], $remarks);
        $log->execute();

        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// Get verified accounts only
$stmt = $conn->prepare("
    SELECT id, username, email, full_name, role, department, address, birthday, civil_status, is_active, created_at, updated_at, id_file_path 
    FROM users 
    WHERE role IN ('lgu_staff', 'citizen') AND account_status = 'verified'
    ORDER BY created_at DESC
");
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Accounts - LGU Road Monitoring</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            color: #3762c8;
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
<body>
    <!-- SIDEBAR -->
    <iframe src="../../includes/sidebar.php" 
            style="position: fixed; width: 250px; height: 100vh; border: none; z-index: 1000;" 
            frameborder="0"
            name="sidebar-frame"
            scrolling="no"
            loading="lazy"
            referrerpolicy="no-referrer">
    </iframe>

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
                        <label for="statusFilter" style="font-size: 14px; color: #64748b;">Filter by:</label>
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
                                    <th>Registered</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; color: #64748b;">No verified accounts found</td>
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

            <!-- Audit Log -->
            <div class="workflow-card">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <i class="fas fa-history"></i>
                        <span>Recent Admin Actions</span>
                        <span class="workflow-badge"><?php echo count($audit_log); ?></span>
                    </h3>
                </div>
                
                <div class="workflow-content audit-log">
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
                </div>
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
                
                // Set dynamic button
                const actionButton = document.getElementById('actionButton');
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

        function closeUserModal() {
            const modal = document.getElementById('userModal');
            if (modal) {
                modal.style.display = 'none';
            }
            currentUserId = null;
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
    </script>
</body>
</html>
