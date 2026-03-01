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

    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }
}

// Get all pending accounts
$pending_users = [];
try {
    $stmt = $conn->prepare("
        SELECT id, username, email, full_name, role, department, address, birthday, civil_status, 
               is_active, created_at, updated_at, id_file_path 
        FROM users 
        WHERE role IN ('lgu_staff', 'citizen') AND account_status = 'pending'
        ORDER BY created_at DESC
    ");
    $stmt->execute();
    $pending_users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching pending users: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Pending Account Approvals - LGU Road Monitoring</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            color: #1e3c72;
        }
        
        tr:hover {
            background: #f8fafc;
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
        .status-rejected { background: #f8d7da; color: #721c24; }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-approve {
            background: #28a745;
            color: white;
        }
        
        .btn-approve:hover {
            background: #218838;
        }
        
        .btn-reject {
            background: #dc3545;
            color: white;
        }
        
        .btn-reject:hover {
            background: #c82333;
        }
        
        .btn-deactivate {
            background: #ffc107;
            color: #212529;
        }
        
        .btn-deactivate:hover {
            background: #e0a800;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }
        
        .modal-content {
            background: white;
            margin: 10% auto;
            padding: 30px;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: 600;
            color: #1e3c72;
        }
        
        .close {
            font-size: 28px;
            font-weight: bold;
            color: #aaa;
            cursor: pointer;
        }
        
        .close:hover {
            color: #000;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #333;
        }
        
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            resize: vertical;
            min-height: 80px;
        }
        
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }
        
        .btn-cancel {
            background: #6c757d;
            color: white;
        }
        
        .btn-cancel:hover {
            background: #5a6268;
        }
        
        .alert {
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid transparent;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        
        .empty-state i {
            font-size: 48px;
            color: #ccc;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="main-content">
        <?php include '../includes/sidebar.php'; ?>
        
        <main>
            <div class="dashboard-header">
                <div class="welcome-section">
                    <div class="welcome-text">
                        <h1>Pending Account Approvals</h1>
                        <p>Review and approve user account requests</p>
                        <p class="date-time"><?php echo date('F, j Y, g:i A'); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-user-check"></i> Pending User Accounts
                        <span style="float: right; font-size: 14px; color: #666;">
                            <?php echo count($pending_users); ?> pending accounts
                        </span>
                    </h2>
                </div>
                
                <?php if (!empty($pending_users)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Department</th>
                                    <th>Applied Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_users as $user): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <span class="status-badge status-pending">
                                            <?php echo htmlspecialchars(ucfirst($user['role'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['department'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <span class="status-badge status-pending">Pending</span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-approve" onclick="showActionModal('approve', <?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['full_name']); ?>')">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                            <button class="btn btn-reject" onclick="showActionModal('reject', <?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['full_name']); ?>')">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-user-check"></i>
                        <h3>No Pending Accounts</h3>
                        <p>All user accounts have been reviewed. No pending approvals at this time.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <?php include '../includes/footer.php'; ?>
    
    <!-- Action Modal -->
    <div id="actionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">Confirm Action</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p id="modalMessage">Are you sure you want to perform this action?</p>
                <div class="form-group">
                    <label for="remarks">Remarks (Optional)</label>
                    <textarea id="remarks" placeholder="Add any notes or comments..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-cancel" onclick="closeModal()">Cancel</button>
                <button class="btn btn-approve" id="confirmBtn">Confirm</button>
            </div>
        </div>
    </div>
    
    <script>
        let currentAction = '';
        let currentUserId = 0;
        
        function showActionModal(action, userId, userName) {
            currentAction = action;
            currentUserId = userId;
            
            const modal = document.getElementById('actionModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalMessage = document.getElementById('modalMessage');
            const confirmBtn = document.getElementById('confirmBtn');
            const remarks = document.getElementById('remarks');
            
            // Reset remarks
            remarks.value = '';
            
            if (action === 'approve') {
                modalTitle.textContent = 'Approve Account';
                modalMessage.textContent = `Are you sure you want to approve the account for ${userName}?`;
                confirmBtn.textContent = 'Approve';
                confirmBtn.className = 'btn btn-approve';
            } else if (action === 'reject') {
                modalTitle.textContent = 'Reject Account';
                modalMessage.textContent = `Are you sure you want to reject the account for ${userName}?`;
                confirmBtn.textContent = 'Reject';
                confirmBtn.className = 'btn btn-reject';
            }
            
            modal.style.display = 'block';
        }
        
        function closeModal() {
            document.getElementById('actionModal').style.display = 'none';
        }
        
        // Confirm action
        document.getElementById('confirmBtn').addEventListener('click', function() {
            const remarks = document.getElementById('remarks').value;
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=${currentAction}&user_id=${currentUserId}&remarks=${encodeURIComponent(remarks)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    closeModal();
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        });
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('actionModal');
            if (event.target === modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>
