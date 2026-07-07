<?php
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
    session_destroy();
    header('Location: ../../login.php?timeout=1');
    exit();
}

$_SESSION['last_activity'] = time();

require_once '../../includes/config.php';
require_once '../../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'lgu_staff') {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg = '';

$conn->query("
    CREATE TABLE IF NOT EXISTS change_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        requested_data TEXT NOT NULL,
        reason TEXT,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        admin_notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        reviewed_at TIMESTAMP NULL,
        reviewed_by INT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    $email = sanitize_input($_POST['email'] ?? '');
    $address = sanitize_input($_POST['address'] ?? '');
    $civil_status = sanitize_input($_POST['civil_status'] ?? '');
    $birthday = sanitize_input($_POST['birthday'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $reason = sanitize_input($_POST['reason'] ?? '');

    if (empty($email)) {
        $error_msg = 'Email is required.';
    } else {
        $requested_data = [
            'email' => $email,
            'address' => $address,
            'civil_status' => $civil_status,
            'birthday' => $birthday
        ];

        if (!empty($new_password)) {
            $requested_data['new_password'] = true;
        }

        if (!empty($_FILES['id_file']['name'])) {
            $upload_dir = '../../uploads/change_requests/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $file_ext = strtolower(pathinfo($_FILES['id_file']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
            if (in_array($file_ext, $allowed)) {
                $file_name = 'cr_' . $user_id . '_' . time() . '.' . $file_ext;
                $file_path = $upload_dir . $file_name;
                if (move_uploaded_file($_FILES['id_file']['tmp_name'], $file_path)) {
                    $requested_data['id_file_path'] = 'uploads/change_requests/' . $file_name;
                } else {
                    $error_msg = 'Failed to upload ID file.';
                }
            } else {
                $error_msg = 'Invalid file type. Allowed: jpg, jpeg, png, gif, pdf.';
            }
        }

        if (empty($error_msg)) {
            $stmt = $conn->prepare("INSERT INTO change_requests (user_id, requested_data, reason, status) VALUES (?, ?, ?, 'pending')");
            $json_data = json_encode($requested_data);
            $stmt->bind_param("iss", $user_id, $json_data, $reason);
            if ($stmt->execute()) {
                log_audit_action($user_id, 'Change Request Submitted', 'Staff requested information change');
                $success_msg = 'Your change request has been submitted and is pending admin review.';
            } else {
                $error_msg = 'Failed to submit request. Please try again.';
            }
            $stmt->close();
        }
    }
}

$stmt = $conn->prepare("SELECT username, full_name, email, role, department, address, birthday, civil_status FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as count FROM change_requests WHERE user_id = ? AND status = 'pending'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$pending_request = $stmt->get_result()->fetch_assoc()['count'] > 0;
$stmt->close();

$stmt = $conn->prepare("SELECT status, admin_notes, reviewed_at FROM change_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$last_request = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Information - LGU Road Monitoring</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background: #f7f5f0; min-height: 100vh; }
        .main-content { margin-left: 250px; padding: 20px; position: relative; z-index: 1; }

        .page-header {
            background: #f0f4fa; backdrop-filter: blur(15px); padding: 25px 30px;
            border-radius: 16px; margin-bottom: 25px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1); border: 1px solid rgba(255,255,255,0.2);
        }
        .page-header h1 { font-size: 24px; font-weight: 700; color: #1e3c72; margin-bottom: 5px; }
        .page-header h1 i { color: #3762c8; margin-right: 10px; }
        .page-header p { color: #666; font-size: 14px; }

        .info-card {
            background: #f0f4fa; backdrop-filter: blur(15px); padding: 25px 30px;
            border-radius: 16px; margin-bottom: 25px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1); border: 1px solid rgba(255,255,255,0.2);
        }
        .info-card h3 { font-size: 18px; font-weight: 600; color: #1e3c72; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .info-card h3 i { color: #3762c8; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 13px; font-weight: 500; color: #333; margin-bottom: 6px; }
        .form-control {
            width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px;
            font-size: 14px; outline: none; transition: border-color 0.2s, box-shadow 0.2s;
            background: white; color: #333;
        }
        .form-control:focus { border-color: #3762c8; box-shadow: 0 0 0 3px rgba(55,98,200,0.15); }
        .form-control:disabled { background: #f8f9fa; cursor: not-allowed; color: #999; }
        .form-control-plaintext { padding: 10px 0; color: #666; font-size: 14px; }

        .form-actions { display: flex; gap: 10px; margin-top: 24px; padding-top: 20px; border-top: 1px solid #e2e8f0; }
        .btn-primary {
            padding: 10px 24px; background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 500;
            cursor: pointer; transition: all 0.3s; display: flex; align-items: center; gap: 8px;
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(55,98,200,0.3); }
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; transform: none; box-shadow: none; }
        .btn-secondary {
            padding: 10px 24px; background: #6c757d; color: white; border: none;
            border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; transition: all 0.3s;
            display: flex; align-items: center; gap: 8px; text-decoration: none;
        }
        .btn-secondary:hover { background: #5a6268; }

        .alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-danger { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }
        .alert-info { background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }

        .request-status-box {
            padding: 16px 20px; border-radius: 12px; margin-bottom: 20px;
            display: flex; align-items: center; gap: 14px;
        }
        .request-status-box.pending { background: #fffbeb; border: 1px solid #fde68a; }
        .request-status-box.approved { background: #f0fdf4; border: 1px solid #86efac; }
        .request-status-box.rejected { background: #fef2f2; border: 1px solid #fecaca; }
        .request-status-box i { font-size: 24px; }
        .request-status-box.pending i { color: #d97706; }
        .request-status-box.approved i { color: #16a34a; }
        .request-status-box.rejected i { color: #dc2626; }
        .request-status-text h4 { font-size: 14px; margin-bottom: 2px; }
        .request-status-text p { font-size: 13px; color: #666; }
        .request-status-text small { color: #999; font-size: 11px; }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 15px; }
            .form-grid { grid-template-columns: 1fr; }
        }

        body.dark-mode .page-header { background: #22262e; border-color: #2d323b; }
        body.dark-mode .page-header h1 { color: #e4e6ea; }
        body.dark-mode .page-header h1 i { color: #60a5fa; }
        body.dark-mode .page-header p { color: #9ca3af; }
        body.dark-mode .info-card { background: #22262e; border-color: #2d323b; }
        body.dark-mode .info-card h3 { color: #e4e6ea; }
        body.dark-mode .info-card h3 i { color: #60a5fa; }
        body.dark-mode .form-group label { color: #9ca3af; }
        body.dark-mode .form-control { background: #1a1d23; border-color: #2d323b; color: #e4e6ea; }
        body.dark-mode .form-control:disabled { background: #22262e; color: #6b7280; }
        body.dark-mode .form-control-plaintext { color: #6b7280; }
        body.dark-mode .form-actions { border-color: #2d323b; }
        body.dark-mode .btn-secondary { background: #374151; }
        body.dark-mode .btn-secondary:hover { background: #4b5563; }
        body.dark-mode .alert-success { background: #064e3b; color: #6ee7b7; border-color: #065f46; }
        body.dark-mode .alert-danger { background: #7f1d1d; color: #fca5a5; border-color: #991b1b; }
        body.dark-mode .alert-info { background: #1e3a5f; color: #93c5fd; border-color: #1e40af; }
        body.dark-mode .request-status-box.pending { background: rgba(217,119,6,0.15); border-color: rgba(217,119,6,0.3); }
        body.dark-mode .request-status-box.approved { background: rgba(22,163,74,0.15); border-color: rgba(22,163,74,0.3); }
        body.dark-mode .request-status-box.rejected { background: rgba(220,38,38,0.15); border-color: rgba(220,38,38,0.3); }
        body.dark-mode .request-status-text p { color: #9ca3af; }
        body.dark-mode .request-status-text small { color: #6b7280; }
    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <?php include '../../includes/sidebar_content.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-user-edit"></i> Change Information</h1>
            <p>Request updates to your account information. Changes require admin approval.</p>
        </div>

        <?php if ($success_msg): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <?php if ($pending_request): ?>
            <div class="request-status-box pending">
                <i class="fas fa-clock"></i>
                <div class="request-status-text">
                    <h4>Pending Request</h4>
                    <p>You already have a pending change request. Wait for admin approval before submitting another.</p>
                </div>
            </div>
        <?php elseif ($last_request && $last_request['status'] === 'approved'): ?>
            <div class="request-status-box approved">
                <i class="fas fa-check-circle"></i>
                <div class="request-status-text">
                    <h4>Last Request Approved</h4>
                    <p><?php echo htmlspecialchars($last_request['admin_notes'] ?? 'Your changes have been applied.'); ?></p>
                    <small>Reviewed on <?php echo date('M d, Y h:i A', strtotime($last_request['reviewed_at'])); ?></small>
                </div>
            </div>
        <?php elseif ($last_request && $last_request['status'] === 'rejected'): ?>
            <div class="request-status-box rejected">
                <i class="fas fa-times-circle"></i>
                <div class="request-status-text">
                    <h4>Last Request Rejected</h4>
                    <p><?php echo htmlspecialchars($last_request['admin_notes'] ?? 'No reason provided.'); ?></p>
                    <small>Reviewed on <?php echo date('M d, Y h:i A', strtotime($last_request['reviewed_at'])); ?></small>
                </div>
            </div>
        <?php endif; ?>

        <div class="info-card">
            <h3><i class="fas fa-id-card"></i> Account Info</h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 10px;">
                <div><small style="color:#666;">Username</small><div class="form-control-plaintext"><?php echo htmlspecialchars($user['username'] ?? ''); ?></div></div>
                <div><small style="color:#666;">Full Name</small><div class="form-control-plaintext"><?php echo htmlspecialchars($user['full_name'] ?? ''); ?></div></div>
                <div><small style="color:#666;">Department</small><div class="form-control-plaintext">LGU Staff</div></div>
            </div>
        </div>

        <div class="info-card">
            <h3><i class="fas fa-pencil-alt"></i> Request Changes</h3>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control"
                               value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="address">Address</label>
                        <input type="text" id="address" name="address" class="form-control"
                               value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>" placeholder="Enter your address">
                    </div>
                    <div class="form-group">
                        <label for="civil_status">Civil Status</label>
                        <select id="civil_status" name="civil_status" class="form-control">
                            <option value="">Select status</option>
                            <option value="single" <?php echo ($user['civil_status'] ?? '') === 'single' ? 'selected' : ''; ?>>Single</option>
                            <option value="married" <?php echo ($user['civil_status'] ?? '') === 'married' ? 'selected' : ''; ?>>Married</option>
                            <option value="widowed" <?php echo ($user['civil_status'] ?? '') === 'widowed' ? 'selected' : ''; ?>>Widowed</option>
                            <option value="separated" <?php echo ($user['civil_status'] ?? '') === 'separated' ? 'selected' : ''; ?>>Separated</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="birthday">Birthday</label>
                        <input type="date" id="birthday" name="birthday" class="form-control"
                               value="<?php echo htmlspecialchars($user['birthday'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="id_file">New ID Photo</label>
                        <input type="file" id="id_file" name="id_file" class="form-control"
                               accept=".jpg,.jpeg,.png,.gif,.pdf">
                    </div>
                    <div class="form-group">
                        <label for="new_password">New Password <small style="color:#999;">(leave blank to keep current)</small></label>
                        <input type="password" id="new_password" name="new_password" class="form-control"
                               placeholder="Enter new password" autocomplete="new-password">
                    </div>
                </div>
                <div class="form-group">
                    <label for="reason">Reason for Change</label>
                    <textarea id="reason" name="reason" class="form-control" rows="3"
                              placeholder="Explain why you need to update your information..." style="resize:vertical;"></textarea>
                </div>
                <div class="form-actions">
                    <button type="submit" name="submit_request" class="btn-primary" <?php echo $pending_request ? 'disabled' : ''; ?>>
                        <i class="fas fa-paper-plane"></i> Submit Request
                    </button>
                    <a href="lgu_staff_dashboard.php" class="btn-secondary"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(function(a) {
                a.style.transition = 'opacity 0.5s';
                a.style.opacity = '0';
                setTimeout(function() { a.remove(); }, 500);
            });
        }, 5000);
    </script>
</body>
</html>
