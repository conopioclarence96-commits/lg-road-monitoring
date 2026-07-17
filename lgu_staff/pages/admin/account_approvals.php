<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ini_set('display_errors', 0);
    error_reporting(0);
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

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    $user_id = $_POST['user_id'] ?? 0;
    $remarks = $_POST['remarks'] ?? '';

    if ($action === 'approve' && $user_id > 0) {
        $stmt = $conn->prepare("UPDATE users SET is_active = 1, account_status = 'verified', approved_at = NOW() WHERE id = ?");
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to prepare approval query']);
            exit;
        }
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $user_stmt = $conn->prepare("SELECT email, full_name FROM users WHERE id = ?");
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            $user_data = $user_result ? $user_result->fetch_assoc() : null;
            if ($user_data) {
                log_audit_action($_SESSION['user_id'], 'Account Approved', "Approved account for {$user_data['full_name']} ({$user_data['email']}). Remarks: $remarks");
            }
            echo json_encode(['success' => true, 'message' => 'Account approved successfully']);
            if ($user_stmt) $user_stmt->close();
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to approve account']);
        }
        $stmt->close();
        exit;

    } elseif ($action === 'reject' && $user_id > 0) {
        $stmt = $conn->prepare("UPDATE users SET is_active = 0, account_status = 'rejected', rejected_at = NOW() WHERE id = ?");
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to prepare rejection query']);
            exit;
        }
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $user_stmt = $conn->prepare("SELECT email, full_name FROM users WHERE id = ?");
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            $user_data = $user_result ? $user_result->fetch_assoc() : null;
            if ($user_data) {
                log_audit_action($_SESSION['user_id'], 'Account Rejected', "Rejected account for {$user_data['full_name']} ({$user_data['email']}). Remarks: $remarks");
            }
            echo json_encode(['success' => true, 'message' => 'Account rejected successfully']);
            if ($user_stmt) $user_stmt->close();
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to reject account']);
        }
        $stmt->close();
        exit;

    } elseif ($action === 'deactivate_user' && $user_id > 0) {
        $stmt = $conn->prepare("UPDATE users SET is_active = 0, account_status = 'deactivated' WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $user_stmt = $conn->prepare("SELECT email, full_name FROM users WHERE id = ?");
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            $user_data = $user_result->fetch_assoc();
            log_audit_action($_SESSION['user_id'], 'Account Deactivated', "Deactivated account for {$user_data['full_name']} ({$user_data['email']}). Remarks: $remarks");
            echo json_encode(['success' => true, 'message' => 'Account deactivated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to deactivate account']);
        }
        $stmt->close();
        $user_stmt->close();
        exit;

    } elseif ($action === 'activate_user' && $user_id > 0) {
        $stmt = $conn->prepare("UPDATE users SET is_active = 1, account_status = 'verified' WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $user_stmt = $conn->prepare("SELECT email, full_name FROM users WHERE id = ?");
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            $user_data = $user_result->fetch_assoc();
            log_audit_action($_SESSION['user_id'], 'Account Activated', "Activated account for {$user_data['full_name']} ({$user_data['email']}). Remarks: $remarks");
            echo json_encode(['success' => true, 'message' => 'Account activated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to activate account']);
        }
        $stmt->close();
        $user_stmt->close();
        exit;

    } elseif ($action === 'approve_change' && $user_id > 0) {
        $request_id = intval($_POST['request_id'] ?? 0);
        $cr_user_id = intval($_POST['cr_user_id'] ?? 0);
        $new_email = sanitize_input($_POST['new_email'] ?? '');
        $new_address = sanitize_input($_POST['new_address'] ?? '');
        $new_civil_status = sanitize_input($_POST['new_civil_status'] ?? '');
        $new_birthday = sanitize_input($_POST['new_birthday'] ?? '');
        $new_password = $_POST['new_password'] ?? '';
        $new_id_file = $_POST['new_id_file_path'] ?? '';
        $new_profile_picture = $_POST['new_profile_picture'] ?? '';
        $admin_notes = sanitize_input($_POST['admin_notes'] ?? '');

        if ($request_id > 0 && $cr_user_id > 0) {
            $sql = "UPDATE users SET email = ?, address = ?, civil_status = ?, birthday = ?";
            $params = [$new_email, $new_address, $new_civil_status, $new_birthday];
            $types = "ssss";

            if (!empty($new_password)) {
                $sql .= ", password = ?";
                $params[] = (str_starts_with($new_password, '$2y$') || str_starts_with($new_password, '$2a$') || str_starts_with($new_password, '$2b$'))
                    ? $new_password
                    : password_hash($new_password, PASSWORD_DEFAULT);
                $types .= "s";
            }
            if (!empty($new_id_file)) {
                $sql .= ", id_file_path = ?";
                $params[] = $new_id_file;
                $types .= "s";
            }
            if (!empty($new_profile_picture)) {
                $sql .= ", profile_picture = ?";
                $params[] = $new_profile_picture;
                $types .= "s";
            }
            $sql .= " WHERE id = ?";
            $params[] = $cr_user_id;
            $types .= "i";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            if ($stmt->execute()) {
                $stmt->close();
                $stmt2 = $conn->prepare("UPDATE change_requests SET status = 'approved', admin_notes = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
                $stmt2->bind_param("sii", $admin_notes, $_SESSION['user_id'], $request_id);
                $stmt2->execute();
                $stmt2->close();
                log_audit_action($_SESSION['user_id'], 'Change Request Approved', "Approved change request #$request_id for user #$cr_user_id");
                echo json_encode(['success' => true, 'message' => 'Change request approved and user info updated.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update user info.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid request parameters.']);
        }
        exit;

    } elseif ($action === 'reject_change' && $user_id > 0) {
        $request_id = intval($_POST['request_id'] ?? 0);
        $admin_notes = sanitize_input($_POST['admin_notes'] ?? '');
        if ($request_id > 0) {
            $stmt = $conn->prepare("UPDATE change_requests SET status = 'rejected', admin_notes = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
            $stmt->bind_param("sii", $admin_notes, $_SESSION['user_id'], $request_id);
            $stmt->execute();
            $stmt->close();
            log_audit_action($_SESSION['user_id'], 'Change Request Rejected', "Rejected change request #$request_id");
            echo json_encode(['success' => true, 'message' => 'Change request rejected.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid request ID.']);
        }
        exit;
    }
}

$stmt = $conn->prepare("
    SELECT id, username, email, full_name, role, department, address, birthday, civil_status, is_active, created_at, updated_at, approved_at, rejected_at, id_file_path 
    FROM users 
    WHERE role IN ('lgu_staff', 'citizen') AND account_status = 'pending'
    ORDER BY created_at DESC
");
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$change_requests = [];
try {
    $cr_stmt = $conn->prepare("
        SELECT cr.*, u.full_name as user_name, u.email as user_email,
               u.department as user_department, u.address as user_address,
               u.civil_status as user_civil_status, u.birthday as user_birthday,
               u.id_file_path as user_id_file
        FROM change_requests cr
        LEFT JOIN users u ON cr.user_id = u.id
        WHERE cr.status = 'pending'
        ORDER BY cr.created_at DESC
    ");
    $cr_stmt->execute();
    $change_requests = $cr_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $cr_stmt->close();
} catch (Exception $e) {
    error_log("Change requests query error: " . $e->getMessage());
    $change_requests = [];
}

$stats = [];
try {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE account_status = 'pending'");
    $stmt->execute();
    $stats['pending_users'] = $stmt->get_result()->fetch_assoc()['count'];

    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE account_status = 'verified' AND is_active = 1");
    $stmt->execute();
    $stats['approved_users'] = $stmt->get_result()->fetch_assoc()['count'];

    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE account_status = 'deactivated'");
    $stmt->execute();
    $stats['deactivated_users'] = $stmt->get_result()->fetch_assoc()['count'];
} catch (Exception $e) {
    $stats = ['pending_users' => 0, 'approved_users' => 0, 'deactivated_users' => 0];
}

$pending_changes_count = count($change_requests);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Account Approvals - LGU Road Monitoring</title>
    <link rel="icon" type="image/png" href="../../assets/img/logocityhall.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../css/sidebar.css">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <style>
        body { background: #f7f5f0; min-height: 100vh; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }

        .main-content { margin-left: 250px; padding: 20px; position: relative; z-index: 1; }

        .dashboard-header {
            background: #f0f4fa; backdrop-filter: blur(15px); padding: 25px 30px;
            border-radius: 16px; margin-bottom: 25px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1); border: 1px solid rgba(255,255,255,0.2);
        }
        .welcome-section { display: flex; justify-content: space-between; align-items: center; }
        .welcome-text h1 { color: #1e3c72; font-size: 28px; font-weight: 700; }
        .welcome-text p { color: #64748b; font-size: 15px; margin-top: 5px; }
        .date-time { text-align: right; color: #3762c8; font-weight: 500; }

        .quick-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .stat-card {
            background: #f0f4fa; backdrop-filter: blur(15px); padding: 20px; border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1); border: 1px solid rgba(255,255,255,0.2);
            text-align: center; transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-icon { width: 50px; height: 50px; background: linear-gradient(135deg, #3762c8, #1e3c72); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; margin: 0 auto 12px; }
        .stat-number { font-size: 28px; font-weight: 700; color: #1e3c72; }
        .stat-label { color: #64748b; font-size: 14px; font-weight: 500; }

        .workflow-container { display: grid; grid-template-columns: 1fr; gap: 25px; }
        .workflow-card {
            background: #f0f4fa; backdrop-filter: blur(15px); padding: 25px; border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1); border: 1px solid rgba(255,255,255,0.2);
        }
        .workflow-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid rgba(55,98,200,0.1);
        }
        .workflow-title { font-size: 18px; font-weight: 600; color: #1e3c72; display: flex; align-items: center; gap: 10px; }
        .workflow-badge { background: #3762c8; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; }
        .sync-indicator { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #64748b; }
        .sync-dot { width: 8px; height: 8px; border-radius: 50%; background: #22c55e; }
        .sync-dot.syncing { animation: syncPulse 0.8s ease-in-out infinite; background: #f59e0b; }
        @keyframes syncPulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

        .cr-modal-section { background: #f8fafc; border-radius: 10px; padding: 16px; margin-bottom: 16px; border: 1px solid #e2e8f0; }
        .cr-modal-section.cr-requested { background: #eff6ff; border-color: #bfdbfe; }
        .cr-modal-section.cr-requested .cr-modal-section-title { color: #1e40af; }
        .cr-modal-section-title { font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
        .cr-modal-section-title i { font-size: 14px; }
        .cr-compare-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .cr-compare-item { display: flex; flex-direction: column; gap: 4px; }
        .cr-compare-label { font-size: 11px; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; }
        .cr-compare-old { font-size: 13px; color: #64748b; padding: 8px 12px; background: #f1f5f9; border-radius: 6px; border-left: 3px solid #cbd5e1; }
        .cr-compare-new { font-size: 13px; color: #1e3c72; padding: 8px 12px; background: #eff6ff; border-radius: 6px; border-left: 3px solid #3762c8; font-weight: 500; }
        .cr-compare-new.no-change { color: #94a3b8; background: #f8fafc; border-left-color: #e2e8f0; font-weight: 400; }
        .cr-media-preview { display: flex; align-items: center; gap: 12px; padding: 8px 12px; background: #f1f5f9; border-radius: 6px; border-left: 3px solid #3762c8; }
        .cr-media-preview img { width: 70px; height: 70px; border-radius: 8px; object-fit: cover; border: 2px solid #e2e8f0; }
        .cr-media-preview .cr-media-label { font-size: 12px; color: #64748b; }
        .cr-staff-header { display: flex; align-items: center; gap: 14px; padding-bottom: 14px; border-bottom: 1px solid #e2e8f0; margin-bottom: 16px; }
        .cr-staff-avatar { width: 44px; height: 44px; border-radius: 50%; background: linear-gradient(135deg, #3762c8, #1e3c72); display: flex; align-items: center; justify-content: center; color: white; font-size: 18px; font-weight: 600; flex-shrink: 0; }
        .cr-staff-info { flex: 1; }
        .cr-staff-name { font-size: 16px; font-weight: 600; color: #1e3c72; }
        .cr-staff-date { font-size: 12px; color: #94a3b8; margin-top: 2px; }
        .cr-admin-notes textarea { width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; resize: vertical; font-family: 'Poppins', sans-serif; transition: border-color 0.2s; }
        .cr-admin-notes textarea:focus { outline: none; border-color: #3762c8; box-shadow: 0 0 0 3px rgba(55,98,200,0.1); }
        .cr-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; padding-top: 16px; border-top: 1px solid #e2e8f0; }
        .cr-btn { padding: 10px 20px; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 8px; }
        .cr-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .cr-btn-reject { background: #ef4444; color: white; }
        .cr-btn-approve { background: #22c55e; color: white; }
        .cr-btn-close { background: #e2e8f0; color: #475569; }
        .cr-btn-close:hover { background: #cbd5e1; }
        .workflow-content { max-height: 500px; overflow-y: auto; padding-right: 10px; }
        .workflow-content::-webkit-scrollbar { width: 6px; }
        .workflow-content::-webkit-scrollbar-track { background: rgba(55,98,200,0.1); border-radius: 3px; }
        .workflow-content::-webkit-scrollbar-thumb { background: rgba(55,98,200,0.3); border-radius: 3px; }

        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; font-weight: 600; color: #475569; }

        .action-buttons { display: flex; gap: 8px; }
        .btn-sm { padding: 6px 12px; font-size: 0.85em; border: none; border-radius: 5px; cursor: pointer; transition: all 0.2s; }
        .btn-approve { background: #22c55e; color: white; }
        .btn-reject { background: #ef4444; color: white; }
        .btn-manage { background: #3b82f6; color: white; }
        .btn-view { background: #3762c8; color: white; }

        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); overflow-y: auto; }
        .modal-content { background-color: white; margin: 5% auto; padding: 0 30px 30px 30px; border-radius: 12px; width: 90%; max-width: 650px; position: relative; max-height: 85vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding: 18px 24px; background: linear-gradient(135deg, #1e3c72, #3762c8); border-radius: 12px 12px 0 0; margin: -30px -30px 20px -30px; }
        .modal-title { margin: 0; color: #ffffff; font-size: 20px; }
        .modal-title i { color: #93c5fd; }
        .close { font-size: 28px; font-weight: bold; cursor: pointer; color: #cbd5e1; transition: color 0.2s; }
        .close:hover { color: #ffffff; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 500; font-size: 13px; color: #333; }
        .modal-form-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; }
        .modal-form-grid .form-group { margin-bottom: 0; }
        input, select, textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; font-size: 13px; }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 15px; }
            .welcome-section { flex-direction: column; align-items: flex-start; }
            .date-time { text-align: left; margin-top: 10px; }
        }

        body.dark-mode .dashboard-header { background: #22262e; border-color: #2d323b; }
        body.dark-mode .welcome-text h1 { color: #e4e6ea; }
        body.dark-mode .welcome-text p { color: #9ca3af; }
        body.dark-mode .date-time { color: #60a5fa; }
        body.dark-mode .stat-card { background: #22262e; border-color: #2d323b; }
        body.dark-mode .stat-number { color: #e4e6ea; }
        body.dark-mode .stat-label { color: #9ca3af; }
        body.dark-mode .workflow-card { background: #22262e; border-color: #2d323b; }
        body.dark-mode .workflow-title { color: #e4e6ea; }
        body.dark-mode th { background: #1a1d23; color: #9ca3af; }
        body.dark-mode td { border-color: #2d323b; color: #d1d5db; }
        body.dark-mode .modal-content { background: #1a1d23; border-color: #2d323b; }
        body.dark-mode .modal-header { background: linear-gradient(135deg, #0f172a, #1e293b); }
        body.dark-mode .modal-title { color: #f1f5f9; }
        body.dark-mode .modal-title i { color: #60a5fa; }
        body.dark-mode .close { color: #94a3b8; }
        body.dark-mode .close:hover { color: #f1f5f9; }
        body.dark-mode .form-group label { color: #9ca3af; }
        body.dark-mode input, body.dark-mode select, body.dark-mode textarea { background: #22262e; border-color: #2d323b; color: #e4e6ea; }
        body.dark-mode .cr-modal-section { background: #1a1d23; border-color: #2d323b; }
        body.dark-mode .cr-modal-section.cr-requested { background: #172033; border-color: #1e3a5f; }
        body.dark-mode .cr-modal-section.cr-requested .cr-modal-section-title { color: #60a5fa; }
        body.dark-mode .cr-modal-section-title { color: #9ca3af; }
        body.dark-mode .cr-compare-old { background: #22262e; color: #9ca3af; border-left-color: #4b5563; }
        body.dark-mode .cr-compare-new { background: #1e293b; color: #93c5fd; border-left-color: #60a5fa; }
        body.dark-mode .cr-compare-new.no-change { color: #6b7280; background: #1a1d23; border-left-color: #2d323b; }
        body.dark-mode .cr-media-preview { background: #22262e; }
        body.dark-mode .cr-media-preview img { border-color: #2d323b; }
        body.dark-mode .cr-staff-header { border-color: #2d323b; }
        body.dark-mode .cr-staff-name { color: #e4e6ea; }
        body.dark-mode .cr-admin-notes textarea { background: #22262e; border-color: #2d323b; color: #e4e6ea; }
        body.dark-mode .cr-actions { border-color: #2d323b; }
        body.dark-mode .cr-btn-close { background: #2d323b; color: #9ca3af; }
        body.dark-mode .cr-btn-close:hover { background: #374151; }
    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="main-content">
        <div class="dashboard-header">
            <div class="welcome-section">
                <div class="welcome-text">
                    <h1><i class="fas fa-check-double"></i> Account Approvals</h1>
                    <p>Review pending user registrations and staff change requests</p>
                </div>
                <div class="date-time">
                    <div id="currentDate"></div>
                    <div id="currentTime"></div>
                    <div class="sync-indicator" id="syncIndicator">
                        <div class="sync-dot" id="syncDot"></div>
                        <span id="syncText">Auto-sync on</span>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="quick-stats">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-user-clock"></i></div>
                <div class="stat-number" id="statPendingUsers"><?php echo $stats['pending_users']; ?></div>
                <div class="stat-label">Pending Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-user-check"></i></div>
                <div class="stat-number" id="statApprovedUsers"><?php echo $stats['approved_users']; ?></div>
                <div class="stat-label">Approved Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-user-slash"></i></div>
                <div class="stat-number" id="statDeactivated"><?php echo $stats['deactivated_users']; ?></div>
                <div class="stat-label">Deactivated</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-user-edit"></i></div>
                <div class="stat-number" id="statChangeRequests"><?php echo $pending_changes_count; ?></div>
                <div class="stat-label">Change Requests</div>
            </div>
        </div>

        <div class="workflow-container">
            <div class="workflow-card">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <i class="fas fa-user-clock"></i>
                        <span>Pending Account Approvals</span>
                        <span class="workflow-badge" id="pendingUsersBadge"><?php echo count($users); ?></span>
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
                            <tbody id="pendingUsersBody">
                                <?php if (empty($users)): ?>
                                    <tr><td colspan="6" style="text-align:center; color:#64748b;">No pending users</td></tr>
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

            <div class="workflow-card">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <i class="fas fa-user-edit"></i>
                        <span>Staff Change Requests</span>
                        <span class="workflow-badge" id="changeRequestsBadge"><?php echo $pending_changes_count; ?></span>
                    </h3>
                </div>
                <div class="workflow-content">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Staff</th>
                                    <th>Current Info</th>
                                    <th>Requested Changes</th>
                                    <th>Reason</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="changeRequestsBody">
                                <?php if (empty($change_requests)): ?>
                                    <tr><td colspan="6" style="text-align:center; color:#64748b;">No pending change requests</td></tr>
                                <?php else: ?>
                                    <?php foreach ($change_requests as $cr):
                                        $req_data = json_decode($cr['requested_data'], true);
                                    ?>
                                        <?php
                                            $fields = ['email', 'address', 'civil_status', 'birthday'];
                                            $current_map = [
                                                'email' => $cr['user_email'],
                                                'address' => $cr['user_address'],
                                                'civil_status' => $cr['user_civil_status'],
                                                'birthday' => $cr['user_birthday'],
                                            ];
                                            $changed_fields = [];
                                            foreach ($fields as $f) {
                                                if (isset($req_data[$f]) && $req_data[$f] !== '' && $req_data[$f] !== ($current_map[$f] ?? '')) {
                                                    $changed_fields[] = $f;
                                                }
                                            }
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($cr['user_name']); ?></td>
                                            <td>
                                                <small style="color:#666;">
                                                <?php if (empty($changed_fields) && empty($req_data['new_password']) && empty($req_data['new_password_hash']) && empty($req_data['profile_picture']) && empty($req_data['id_file_path'])): ?>
                                                    No changes
                                                <?php else: ?>
                                                    <?php foreach ($changed_fields as $f): ?>
                                                        <?php $label = ucfirst(str_replace('_', ' ', $f)); ?>
                                                        <strong><?php echo $label; ?>:</strong> <?php echo htmlspecialchars($current_map[$f] ?? 'N/A'); ?><br>
                                                    <?php endforeach; ?>
                                                    <?php if (!empty($req_data['new_password']) || !empty($req_data['new_password_hash'])): ?>
                                                        <span style="color:#d97706;"><i class="fas fa-key"></i> Current password</span><br>
                                                    <?php endif; ?>
                                                    <?php if (!empty($req_data['profile_picture'])): ?>
                                                        <span style="color:#7c3aed;"><i class="fas fa-user-circle"></i> Current profile picture</span><br>
                                                    <?php endif; ?>
                                                    <?php if (!empty($req_data['id_file_path'])): ?>
                                                        <span style="color:#059669;"><i class="fas fa-id-card"></i> Current ID photo</span><br>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                </small>
                                            </td>
                                            <td>
                                                <small style="color:#1e3c72;">
                                                <?php if (empty($changed_fields) && empty($req_data['new_password']) && empty($req_data['new_password_hash']) && empty($req_data['profile_picture']) && empty($req_data['id_file_path'])): ?>
                                                    No changes
                                                <?php else: ?>
                                                    <?php foreach ($changed_fields as $f): ?>
                                                        <?php $label = ucfirst(str_replace('_', ' ', $f)); ?>
                                                        <strong><?php echo $label; ?>:</strong> <?php echo htmlspecialchars($req_data[$f]); ?><br>
                                                    <?php endforeach; ?>
                                                    <?php if (!empty($req_data['new_password']) || !empty($req_data['new_password_hash'])): ?>
                                                        <span style="color:#f59e0b; font-weight:600;"><i class="fas fa-key"></i> New password requested</span><br>
                                                    <?php endif; ?>
                                                    <?php if (!empty($req_data['profile_picture'])): ?>
                                                        <span style="color:#8b5cf6; font-weight:600;"><i class="fas fa-user-circle"></i> New profile picture</span><br>
                                                    <?php endif; ?>
                                                    <?php if (!empty($req_data['id_file_path'])): ?>
                                                        <span style="color:#10b981; font-weight:600;"><i class="fas fa-id-card"></i> New ID photo</span><br>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                </small>
                                            </td>
                                            <td><small><?php echo htmlspecialchars($cr['reason'] ?? 'N/A'); ?></small></td>
                                            <td><small><?php echo date('M d, Y', strtotime($cr['created_at'])); ?></small></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn-sm btn-approve" onclick="showChangeRequestModal(<?php echo $cr['id']; ?>, <?php echo $cr['user_id']; ?>)">Review</button>
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
        </div>
    </div>

    <!-- User Management Modal -->
    <div id="userModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h2 class="modal-title">User Details</h2>
                <span class="close" onclick="closeUserModal()">&times;</span>
            </div>
            <div class="modal-form-grid">
                <div class="form-group"><label>Email:</label><input type="email" id="modalEmail" disabled></div>
                <div class="form-group"><label>Full Name:</label><input type="text" id="modalFullName" disabled></div>
                <div class="form-group"><label>Role:</label><input type="text" id="modalRole" disabled></div>
                <div class="form-group"><label>Department:</label><input type="text" id="modalDepartment" disabled></div>
                <div class="form-group"><label>Address:</label><input type="text" id="modalAddress" disabled></div>
                <div class="form-group"><label>Birthday:</label><input type="text" id="modalBirthday" disabled></div>
                <div class="form-group"><label>Civil Status:</label><input type="text" id="modalCivilStatus" disabled></div>
                <div class="form-group"><label>Account Status:</label><input type="text" id="modalAccountStatus" disabled></div>
                <div class="form-group"><label>Created At:</label><input type="text" id="modalCreatedAt" disabled></div>
                <div class="form-group"><label>Approved At:</label><input type="text" id="modalApprovedAt" disabled></div>
                <div class="form-group"><label>Rejected At:</label><input type="text" id="modalRejectedAt" disabled></div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label>ID File:</label>
                    <div id="modalIdFileContainer">
                        <img id="modalIdFile" src="" alt="ID File" style="max-width:200px; max-height:150px; border-radius:8px; border:1px solid #ddd; display:none;">
                        <p id="modalIdFileNone" style="color:#666; font-style:italic;">No ID file uploaded</p>
                    </div>
                </div>
            </div>
            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:20px;">
                <button type="button" class="btn-sm btn-approve" onclick="approveUser()">Approve</button>
                <button type="button" class="btn-sm btn-reject" onclick="rejectUser()">Reject</button>
                <button type="button" class="btn-sm btn-manage" onclick="closeUserModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Change Request Review Modal (read-only review) -->
    <div id="changeRequestModal" class="modal">
        <div class="modal-content" style="max-width: 680px;">
            <div class="modal-header">
                <h2 class="modal-title"><i class="fas fa-clipboard-check"></i> Review Change Request</h2>
                <span class="close" onclick="closeChangeRequestModal()">&times;</span>
            </div>

            <div class="cr-staff-header" id="crStaffHeader">
                <div class="cr-staff-avatar" id="crStaffAvatar">S</div>
                <div class="cr-staff-info">
                    <div class="cr-staff-name" id="crStaffName">Staff Name</div>
                    <div class="cr-staff-date" id="crStaffDate">Submitted on --</div>
                </div>
            </div>

            <form id="changeRequestForm">
                <input type="hidden" id="crAction" name="action">
                <input type="hidden" id="crRequestId" name="request_id">
                <input type="hidden" id="crUserId" name="cr_user_id">
                <input type="hidden" id="crAdminUserId" name="user_id" value="<?php echo $_SESSION['user_id']; ?>">
                <input type="hidden" id="crIdFilePath" name="new_id_file_path">
                <input type="hidden" id="crProfilePicture" name="new_profile_picture">
                <input type="hidden" id="crEmail" name="new_email">
                <input type="hidden" id="crAddress" name="new_address">
                <input type="hidden" id="crCivilStatus" name="new_civil_status">
                <input type="hidden" id="crBirthday" name="new_birthday">
                <input type="hidden" id="crPassword" name="new_password">

                <div class="cr-modal-section" id="crCurrentSection">
                    <div class="cr-modal-section-title"><i class="fas fa-user"></i> Current Information</div>
                    <div class="cr-compare-grid" id="crCurrentGrid"></div>
                </div>

                <div class="cr-modal-section cr-requested">
                    <div class="cr-modal-section-title"><i class="fas fa-pen"></i> Requested Changes</div>
                    <div class="cr-compare-grid">
                        <div class="cr-compare-item">
                            <span class="cr-compare-label">Email</span>
                            <div class="cr-compare-new" id="crEmailDisplay">--</div>
                        </div>
                        <div class="cr-compare-item">
                            <span class="cr-compare-label">Address</span>
                            <div class="cr-compare-new" id="crAddressDisplay">--</div>
                        </div>
                        <div class="cr-compare-item">
                            <span class="cr-compare-label">Civil Status</span>
                            <div class="cr-compare-new" id="crCivilStatusDisplay">--</div>
                        </div>
                        <div class="cr-compare-item">
                            <span class="cr-compare-label">Birthday</span>
                            <div class="cr-compare-new" id="crBirthdayDisplay">--</div>
                        </div>
                        <div class="cr-compare-item">
                            <span class="cr-compare-label">New Password</span>
                            <div class="cr-compare-new" id="crPasswordDisplay">--</div>
                        </div>
                        <div class="cr-compare-item" id="crIdFileGroup" style="display:none;">
                            <span class="cr-compare-label">New ID Photo</span>
                            <div class="cr-media-preview" id="crIdFilePreview"></div>
                        </div>
                        <div class="cr-compare-item" id="crProfilePicGroup" style="display:none;">
                            <span class="cr-compare-label">New Profile Picture</span>
                            <div class="cr-media-preview" id="crProfilePicPreview"></div>
                        </div>
                    </div>
                </div>

                <div class="cr-modal-section cr-admin-notes">
                    <div class="cr-modal-section-title"><i class="fas fa-sticky-note"></i> Admin Notes</div>
                    <textarea id="crAdminNotes" name="admin_notes" rows="2" placeholder="Optional notes for the staff..."></textarea>
                </div>

                <div class="cr-actions">
                    <button type="button" class="cr-btn cr-btn-close" onclick="closeChangeRequestModal()"><i class="fas fa-times"></i> Close</button>
                    <button type="button" class="cr-btn cr-btn-reject" onclick="rejectChangeRequest()"><i class="fas fa-times"></i> Reject</button>
                    <button type="button" class="cr-btn cr-btn-approve" onclick="approveChangeRequest()"><i class="fas fa-check"></i> Approve & Update</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let currentUserId = null;
        let usersData = <?php echo json_encode($users); ?>;

        function showUserModal(userId) {
            currentUserId = userId;
            const user = usersData.find(u => u.id == userId);
            if (!user) return;

            document.getElementById('modalEmail').value = user.email;
            document.getElementById('modalFullName').value = user.full_name;
            document.getElementById('modalRole').value = user.role;
            document.getElementById('modalDepartment').value = user.department || 'N/A';
            document.getElementById('modalAddress').value = user.address || 'N/A';
            document.getElementById('modalBirthday').value = user.birthday || 'N/A';
            document.getElementById('modalCivilStatus').value = user.civil_status ? user.civil_status.charAt(0).toUpperCase() + user.civil_status.slice(1) : 'N/A';
            document.getElementById('modalAccountStatus').value = user.is_active ? 'Active' : 'Inactive';
            document.getElementById('modalCreatedAt').value = user.created_at;
            document.getElementById('modalApprovedAt').value = user.approved_at || 'N/A';
            document.getElementById('modalRejectedAt').value = user.rejected_at || 'N/A';

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
            document.getElementById('userModal').style.display = 'block';
        }

        function closeUserModal() {
            document.getElementById('userModal').style.display = 'none';
            currentUserId = null;
        }

        function doUserAction(action) {
            if (!currentUserId) return;
            const msg = action === 'approve' ? 'approve' : 'reject';
            if (!confirm('Are you sure you want to ' + msg + ' this user account?')) return;

            const formData = new FormData();
            formData.append('action', action);
            formData.append('user_id', currentUserId);
            formData.append('remarks', action === 'approve' ? 'Approved by admin' : 'Rejected by admin');

            fetch('', { method: 'POST', body: formData })
            .then(async (response) => {
                const contentType = response.headers.get('content-type') || '';
                if (!response.ok) {
                    const text = await response.text();
                    throw new Error(text || 'Request failed');
                }
                if (!contentType.includes('application/json')) {
                    const text = await response.text();
                    throw new Error(text || 'Not JSON');
                }
                return response.json();
            })
            .then(result => {
                if (result.success) { closeUserModal(); location.reload(); }
                else { alert(result.message); }
            })
            .catch(error => { console.error('Error:', error); alert('An error occurred.'); });
        }

        function approveUser() { doUserAction('approve'); }
        function rejectUser() { doUserAction('reject'); }

        const changeRequestsData = <?php echo json_encode($change_requests); ?>;

        function showChangeRequestModal(requestId, userId) {
            const cr = changeRequestsData.find(r => r.id == requestId);
            if (!cr) return;
            const data = JSON.parse(cr.requested_data);

            document.getElementById('crAction').value = '';
            document.getElementById('crRequestId').value = cr.id;
            document.getElementById('crUserId').value = cr.user_id;

            // Staff header
            var initials = (cr.user_name || 'S').split(' ').map(function(w){ return w.charAt(0); }).join('').substring(0,2).toUpperCase();
            document.getElementById('crStaffAvatar').textContent = initials;
            document.getElementById('crStaffName').textContent = cr.user_name || 'Unknown Staff';
            document.getElementById('crStaffDate').textContent = 'Submitted on ' + new Date(cr.created_at).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });

            // Current information grid
            var currentHtml = '';
            var fields = [
                { key: 'email', label: 'Email', value: cr.user_email },
                { key: 'address', label: 'Address', value: cr.user_address },
                { key: 'civil_status', label: 'Civil Status', value: cr.user_civil_status ? cr.user_civil_status.charAt(0).toUpperCase() + cr.user_civil_status.slice(1) : 'N/A' },
                { key: 'birthday', label: 'Birthday', value: cr.user_birthday }
            ];
            fields.forEach(function(f) {
                currentHtml += '<div class="cr-compare-item"><span class="cr-compare-label">' + f.label + '</span><div class="cr-compare-old">' + escapeHtml(f.value || 'N/A') + '</div></div>';
            });
            if (cr.user_id_file) {
                var ext = cr.user_id_file.split('.').pop().toLowerCase();
                if (['jpg','jpeg','png','gif'].includes(ext)) {
                    currentHtml += '<div class="cr-compare-item" style="grid-column:1/-1;"><span class="cr-compare-label">Current ID Photo</span><div class="cr-media-preview"><img src="../../' + cr.user_id_file + '" alt="Current ID"><span class="cr-media-label">Uploaded ID file</span></div></div>';
                }
            }
            document.getElementById('crCurrentGrid').innerHTML = currentHtml;

            // Requested changes
            document.getElementById('crEmail').value = data.email || cr.user_email || '';
            document.getElementById('crEmailDisplay').textContent = data.email || 'N/A';
            document.getElementById('crEmailDisplay').className = (data.email && data.email !== cr.user_email) ? 'cr-compare-new' : 'cr-compare-new no-change';

            document.getElementById('crAddress').value = data.address || cr.user_address || '';
            document.getElementById('crAddressDisplay').textContent = data.address || 'N/A';
            document.getElementById('crAddressDisplay').className = (data.address && data.address !== cr.user_address) ? 'cr-compare-new' : 'cr-compare-new no-change';

            document.getElementById('crCivilStatus').value = data.civil_status || cr.user_civil_status || '';
            var csDisplay = data.civil_status ? data.civil_status.charAt(0).toUpperCase() + data.civil_status.slice(1) : 'N/A';
            document.getElementById('crCivilStatusDisplay').textContent = csDisplay;
            document.getElementById('crCivilStatusDisplay').className = (data.civil_status && data.civil_status !== cr.user_civil_status) ? 'cr-compare-new' : 'cr-compare-new no-change';

            document.getElementById('crBirthday').value = data.birthday || cr.user_birthday || '';
            document.getElementById('crBirthdayDisplay').textContent = data.birthday || 'N/A';
            document.getElementById('crBirthdayDisplay').className = (data.birthday && data.birthday !== cr.user_birthday) ? 'cr-compare-new' : 'cr-compare-new no-change';

            var pwVal = '';
            if (data.new_password_hash) { pwVal = data.new_password_hash; }
            else if (data.new_password && typeof data.new_password === 'string') { pwVal = data.new_password; }
            document.getElementById('crPassword').value = pwVal;
            var hasPw = !!(data.new_password || data.new_password_hash);
            document.getElementById('crPasswordDisplay').innerHTML = hasPw ? '<i class="fas fa-key" style="color:#f59e0b; margin-right:6px;"></i>New password requested' : 'No change';
            document.getElementById('crPasswordDisplay').className = hasPw ? 'cr-compare-new' : 'cr-compare-new no-change';

            document.getElementById('crIdFilePath').value = data.id_file_path || '';
            document.getElementById('crProfilePicture').value = data.profile_picture || '';
            document.getElementById('crAdminNotes').value = '';

            // ID file preview
            var idFileGroup = document.getElementById('crIdFileGroup');
            var idFilePreview = document.getElementById('crIdFilePreview');
            if (data.id_file_path) {
                idFileGroup.style.display = 'block';
                var ext = data.id_file_path.split('.').pop().toLowerCase();
                if (['jpg','jpeg','png','gif'].includes(ext)) {
                    idFilePreview.innerHTML = '<img src="../../' + data.id_file_path + '" alt="New ID"><span class="cr-media-label">New ID photo uploaded</span>';
                } else {
                    idFilePreview.innerHTML = '<a href="../../' + data.id_file_path + '" target="_blank" style="color:#3762c8; font-size:13px;"><i class="fas fa-file"></i> View uploaded file</a>';
                }
            } else {
                idFileGroup.style.display = 'none';
                idFilePreview.innerHTML = '';
            }

            // Profile picture preview
            var profilePicGroup = document.getElementById('crProfilePicGroup');
            var profilePicPreview = document.getElementById('crProfilePicPreview');
            if (data.profile_picture) {
                profilePicGroup.style.display = 'block';
                profilePicPreview.innerHTML = '<img src="../../uploads/profile_pictures/' + data.profile_picture + '" alt="New Profile Pic" style="border-radius:50%;"><span class="cr-media-label">New profile picture uploaded</span>';
            } else {
                profilePicGroup.style.display = 'none';
                profilePicPreview.innerHTML = '';
            }

            document.getElementById('changeRequestModal').style.display = 'block';
        }

        function closeChangeRequestModal() {
            document.getElementById('changeRequestModal').style.display = 'none';
        }

        function approveChangeRequest() {
            if (!confirm('Apply these changes to the user account?')) return;
            document.getElementById('crAction').value = 'approve_change';
            submitChangeRequest();
        }

        function rejectChangeRequest() {
            if (!confirm('Reject this change request?')) return;
            document.getElementById('crAction').value = 'reject_change';
            submitChangeRequest();
        }

        function submitChangeRequest() {
            const form = document.getElementById('changeRequestForm');
            const formData = new FormData(form);
            fetch('', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(result => {
                if (result.success) { closeChangeRequestModal(); location.reload(); }
                else { alert(result.message); }
            })
            .catch(error => { console.error('Error:', error); alert('An error occurred.'); });
        }

        window.onclick = function(event) {
            const m1 = document.getElementById('userModal');
            const m2 = document.getElementById('changeRequestModal');
            if (event.target == m1) closeUserModal();
            if (event.target == m2) closeChangeRequestModal();
        }

        function updateDateTime() {
            const now = new Date();
            document.getElementById('currentDate').textContent = now.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
            document.getElementById('currentTime').textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        updateDateTime();
        setInterval(updateDateTime, 1000);

        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(a => {
                a.style.transition = 'opacity 0.5s';
                a.style.opacity = '0';
                setTimeout(() => a.remove(), 500);
            });
        }, 5000);

        let syncInterval = null;
        let previousChangeRequestIds = changeRequestsData.map(r => r.id);
        let previousUserIds = usersData.map(u => u.id);

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function renderPendingUsersRow(user) {
            return '<tr>' +
                '<td>' + escapeHtml(user.full_name) + '</td>' +
                '<td>' + escapeHtml(user.email) + '</td>' +
                '<td>' + escapeHtml(user.role) + '</td>' +
                '<td>' + escapeHtml(user.department || 'N/A') + '</td>' +
                '<td>' + new Date(user.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + '</td>' +
                '<td><div class="action-buttons"><button class="btn-sm btn-manage" onclick="showUserModal(' + user.id + ')">Manage</button></div></td>' +
                '</tr>';
        }

        function renderChangeRequestRow(cr) {
            var reqData = {};
            try { reqData = JSON.parse(cr.requested_data); } catch(e) {}
            var fields = ['email', 'address', 'civil_status', 'birthday'];
            var currentMap = {
                'email': cr.user_email,
                'address': cr.user_address,
                'civil_status': cr.user_civil_status,
                'birthday': cr.user_birthday
            };
            var changedFields = [];
            fields.forEach(function(f) {
                if (reqData[f] && reqData[f] !== '' && reqData[f] !== (currentMap[f] || '')) {
                    changedFields.push(f);
                }
            });

            var currentHtml = '';
            var requestedHtml = '';
            if (changedFields.length === 0 && !reqData.new_password && !reqData.new_password_hash && !reqData.profile_picture && !reqData.id_file_path) {
                currentHtml = 'No changes';
                requestedHtml = 'No changes';
            } else {
                changedFields.forEach(function(f) {
                    var label = f.charAt(0).toUpperCase() + f.slice(1).replace(/_/g, ' ');
                    currentHtml += '<strong>' + label + ':</strong> ' + escapeHtml(currentMap[f] || 'N/A') + '<br>';
                    requestedHtml += '<strong>' + label + ':</strong> ' + escapeHtml(reqData[f]) + '<br>';
                });
                if (reqData.new_password || reqData.new_password_hash) {
                    currentHtml += '<span style="color:#d97706;"><i class="fas fa-key"></i> Current password</span><br>';
                    requestedHtml += '<span style="color:#f59e0b; font-weight:600;"><i class="fas fa-key"></i> New password requested</span><br>';
                }
                if (reqData.profile_picture) {
                    currentHtml += '<span style="color:#7c3aed;"><i class="fas fa-user-circle"></i> Current profile picture</span><br>';
                    requestedHtml += '<span style="color:#8b5cf6; font-weight:600;"><i class="fas fa-user-circle"></i> New profile picture</span><br>';
                }
                if (reqData.id_file_path) {
                    currentHtml += '<span style="color:#059669;"><i class="fas fa-id-card"></i> Current ID photo</span><br>';
                    requestedHtml += '<span style="color:#10b981; font-weight:600;"><i class="fas fa-id-card"></i> New ID photo</span><br>';
                }
            }

            return '<tr>' +
                '<td>' + escapeHtml(cr.user_name) + '</td>' +
                '<td><small style="color:#666;">' + currentHtml + '</small></td>' +
                '<td><small style="color:#1e3c72;">' + requestedHtml + '</small></td>' +
                '<td><small>' + escapeHtml(cr.reason || 'N/A') + '</small></td>' +
                '<td><small>' + new Date(cr.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + '</small></td>' +
                '<td><div class="action-buttons"><button class="btn-sm btn-approve" onclick="showChangeRequestModal(' + cr.id + ', ' + cr.user_id + ')">Review</button></div></td>' +
                '</tr>';
        }

        function refreshApprovalsData() {
            var dot = document.getElementById('syncDot');
            var text = document.getElementById('syncText');
            dot.classList.add('syncing');
            text.textContent = 'Syncing...';

            fetch('../api/get_account_approvals_data.php')
            .then(function(response) { return response.json(); })
            .then(function(result) {
                if (!result.success) return;

                var data = result.data;

                // Update stat cards
                document.getElementById('statPendingUsers').textContent = data.stats.pending_users;
                document.getElementById('statApprovedUsers').textContent = data.stats.approved_users;
                document.getElementById('statDeactivated').textContent = data.stats.deactivated_users;
                document.getElementById('statChangeRequests').textContent = data.stats.change_requests;

                // Update workflow badges
                document.getElementById('pendingUsersBadge').textContent = data.pending_users.length;
                document.getElementById('changeRequestsBadge').textContent = data.change_requests.length;

                // Update pending users table
                var pendingUsersBody = document.getElementById('pendingUsersBody');
                if (data.pending_users.length === 0) {
                    pendingUsersBody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:#64748b;">No pending users</td></tr>';
                } else {
                    var usersHtml = '';
                    data.pending_users.forEach(function(user) { usersHtml += renderPendingUsersRow(user); });
                    pendingUsersBody.innerHTML = usersHtml;
                }

                // Update change requests table
                var changeRequestsBody = document.getElementById('changeRequestsBody');
                if (data.change_requests.length === 0) {
                    changeRequestsBody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:#64748b;">No pending change requests</td></tr>';
                } else {
                    var crHtml = '';
                    data.change_requests.forEach(function(cr) { crHtml += renderChangeRequestRow(cr); });
                    changeRequestsBody.innerHTML = crHtml;
                }

                // Update the JS data arrays so modals work with fresh data
                usersData = data.pending_users;
                changeRequestsData = data.change_requests;

                // Check for new change requests and flash notification
                var newCrIds = data.change_requests.map(function(r) { return r.id; });
                var hasNewRequests = newCrIds.some(function(id) { return previousChangeRequestIds.indexOf(id) === -1; });
                if (hasNewRequests && previousChangeRequestIds.length > 0) {
                    var badge = document.getElementById('changeRequestsBadge');
                    badge.style.transition = 'background 0.3s';
                    badge.style.background = '#f59e0b';
                    setTimeout(function() { badge.style.background = '#3762c8'; }, 2000);
                }
                previousChangeRequestIds = newCrIds;

                // Check for new pending users
                var newUserIds = data.pending_users.map(function(u) { return u.id; });
                var hasNewUsers = newUserIds.some(function(id) { return previousUserIds.indexOf(id) === -1; });
                if (hasNewUsers && previousUserIds.length > 0) {
                    var badge = document.getElementById('pendingUsersBadge');
                    badge.style.transition = 'background 0.3s';
                    badge.style.background = '#f59e0b';
                    setTimeout(function() { badge.style.background = '#3762c8'; }, 2000);
                }
                previousUserIds = newUserIds;

                dot.classList.remove('syncing');
                text.textContent = 'Synced just now';
            })
            .catch(function(error) {
                console.error('Sync error:', error);
                dot.classList.remove('syncing');
                text.textContent = 'Sync failed';
            });
        }

        syncInterval = setInterval(refreshApprovalsData, 30000);
    </script>


</body>
</html>
