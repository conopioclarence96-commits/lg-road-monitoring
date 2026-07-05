<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['system_admin', 'lgu_staff'])) {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Ensure profile_picture column exists
try {
    $check_col = $conn->query("SHOW COLUMNS FROM users LIKE 'profile_picture'");
    if ($check_col->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) DEFAULT NULL AFTER email");
    }
} catch (Exception $e) {}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $full_name = sanitize_input($_POST['full_name'] ?? '');
        $email = sanitize_input($_POST['email'] ?? '');
        $department = sanitize_input($_POST['department'] ?? '');
        $address = sanitize_input($_POST['address'] ?? '');
        $birthday = sanitize_input($_POST['birthday'] ?? '');
        $civil_status = sanitize_input($_POST['civil_status'] ?? '');

        if (!empty($full_name) && !empty($email)) {
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, department = ?, address = ?, birthday = ?, civil_status = ? WHERE id = ?");
            $stmt->bind_param("ssssssi", $full_name, $email, $department, $address, $birthday, $civil_status, $user_id);
            $stmt->execute();
            $stmt->close();

            $_SESSION['full_name'] = $full_name;
            log_audit_action($user_id, 'Profile Updated', 'Updated profile information');
            $success_msg = 'Profile updated successfully.';
        } else {
            $error_msg = 'Name and email are required.';
        }
    }

    if ($action === 'request_change') {
        $data = [
            'full_name' => sanitize_input($_POST['full_name'] ?? ''),
            'email' => sanitize_input($_POST['email'] ?? ''),
            'address' => sanitize_input($_POST['address'] ?? ''),
            'birthday' => sanitize_input($_POST['birthday'] ?? ''),
            'civil_status' => sanitize_input($_POST['civil_status'] ?? ''),
        ];
        $reason = sanitize_input($_POST['reason'] ?? '');
        $stmt = $conn->prepare("INSERT INTO change_requests (user_id, requested_data, reason, status) VALUES (?, ?, ?, 'pending')");
        $json_data = json_encode($data);
        $stmt->bind_param("iss", $user_id, $json_data, $reason);
        if ($stmt->execute()) {
            log_audit_action($user_id, 'Change Request Submitted', 'Staff requested information change from settings');
            $success_msg = 'Your change request has been submitted and is pending admin review.';
        } else {
            $error_msg = 'Failed to submit request. Please try again.';
        }
        $stmt->close();
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!password_verify($current, $user['password'])) {
            $error_msg = 'Current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $error_msg = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $error_msg = 'New passwords do not match.';
        } else {
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashed, $user_id);
            $stmt->execute();
            $stmt->close();

            log_audit_action($user_id, 'Password Changed', 'Changed account password');
            $success_msg = 'Password changed successfully.';
        }
    }

    if ($action === 'save_restrictions') {
        $settings = [
            'landing_page_private' => $_POST['landing_page_private'] ?? '0',
            'hide_hero' => $_POST['hide_hero'] ?? '0',
            'hide_updates' => $_POST['hide_updates'] ?? '0',
            'hide_stats' => $_POST['hide_stats'] ?? '0',
            'hide_about' => $_POST['hide_about'] ?? '0',
            'hide_contact' => $_POST['hide_contact'] ?? '0',
            'disable_signup' => $_POST['disable_signup'] ?? '0',
            'hide_contact_form' => $_POST['hide_contact_form'] ?? '0',
            'disable_search' => $_POST['disable_search'] ?? '0',
            'custom_message' => sanitize_input($_POST['custom_message'] ?? ''),
            'redirect_url' => sanitize_input($_POST['redirect_url'] ?? ''),
        ];

        foreach ($settings as $key => $value) {
            $stmt = $conn->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->bind_param("sss", $key, $value, $value);
            $stmt->execute();
            $stmt->close();
        }

        log_audit_action($user_id, 'Restrictions Updated', 'Updated landing page access control settings');
        $success_msg = 'Access restrictions applied successfully.';
    }

    if ($action === 'upload_avatar') {
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../../uploads/profile_pictures';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (in_array($ext, $allowed)) {
                $filename = 'avatar_' . $user_id . '_' . uniqid() . '.' . $ext;
                $filepath = $upload_dir . '/' . $filename;
                if (move_uploaded_file($_FILES['avatar']['tmp_name'], $filepath)) {
                    $stmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                    $stmt->bind_param("si", $filename, $user_id);
                    $stmt->execute();
                    $stmt->close();
                    $success_msg = 'Profile picture updated.';
                }
            } else {
                $error_msg = 'Invalid file type. Allowed: jpg, jpeg, png, gif, webp';
            }
        }
    }

    if ($action === 'toggle_twofa') {
        $twofa = $_POST['twofa'] ?? '0';
        $stmt = $conn->prepare("UPDATE users SET twofa = ? WHERE id = ?");
        $stmt->bind_param("si", $twofa, $user_id);
        $stmt->execute();
        $stmt->close();

        log_audit_action($user_id, '2FA Updated', 'Two-factor authentication ' . ($twofa === '1' ? 'enabled' : 'disabled'));
        $success_msg = 'Two-factor authentication ' . ($twofa === '1' ? 'enabled' : 'disabled') . ' successfully.';
    }

    if ($action === 'toggle_darkmode') {
        $darkmode = $_POST['darkmode'] ?? '0';
        $stmt = $conn->prepare("UPDATE users SET darkmode = ? WHERE id = ?");
        $stmt->bind_param("si", $darkmode, $user_id);
        $stmt->execute();
        $stmt->close();

        $_SESSION['darkmode'] = $darkmode;

        log_audit_action($user_id, 'Dark Mode Updated', 'Dark mode ' . ($darkmode === '1' ? 'enabled' : 'disabled'));
        $success_msg = 'Dark mode ' . ($darkmode === '1' ? 'enabled' : 'disabled') . ' successfully.';
    }

    if ($action === 'clear_activity_log') {
        $conn->query("TRUNCATE TABLE audit_logs");
        log_audit_action($user_id, 'Activity Log Cleared', 'All activity log history has been deleted');
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
}

// Get user data
$stmt = $conn->prepare("SELECT username, full_name, email, role, profile_picture, twofa, darkmode, department, address, birthday, civil_status FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get current settings
$settings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM site_settings");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

// Get activity log with user info
$activity_log = [];
try {
    $log_result = $conn->query("
        SELECT a.id, a.user_id, a.action, a.details, a.created_at,
               u.full_name, u.role as user_role
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.id
        ORDER BY a.created_at DESC
        LIMIT 200
    ");
    if ($log_result) {
        while ($row = $log_result->fetch_assoc()) {
            $activity_log[] = $row;
        }
    }
} catch (Exception $e) {
    $activity_log = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - LGU Road Monitoring</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body {
            background: #f7f5f0;
            min-height: 100vh;
        }
        .main-content { margin-left: 250px; padding: 20px; position: relative; z-index: 1; }
        .settings-container {
            width: 100%;
            background: #f0f4fa;
            backdrop-filter: blur(15px);
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            padding: 30px;
        }
        .page-header {
            display: flex; align-items: center; gap: 15px;
            margin-bottom: 25px; padding-bottom: 20px;
            border-bottom: 2px solid rgba(55,98,200,0.1);
        }
        .page-header h1 { font-size: 24px; font-weight: 700; color: #1e3c72; }
        .page-header i { font-size: 28px; color: #3762c8; }
        .tabs {
            display: flex; gap: 0; margin-bottom: 25px;
            border-bottom: 2px solid #e9ecef;
        }
        .tab-btn {
            padding: 12px 24px; border: none; background: none;
            font-size: 14px; font-weight: 500; color: #666;
            cursor: pointer; border-bottom: 3px solid transparent;
            margin-bottom: -2px; transition: all 0.2s;
            display: flex; align-items: center; gap: 8px;
        }
        .tab-btn:hover { color: #3762c8; }
        .tab-btn.active {
            color: #3762c8; border-bottom-color: #3762c8;
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .form-section {
            margin-bottom: 25px; padding-bottom: 25px;
            border-bottom: 1px solid #e9ecef;
        }
        .form-section:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .form-section h3 {
            font-size: 16px; font-weight: 600; color: #1e3c72;
            margin-bottom: 15px; display: flex; align-items: center; gap: 8px;
        }
        .form-section h3 i { color: #3762c8; }
        .form-group { margin-bottom: 15px; }
        .form-group label {
            display: block; font-size: 13px; font-weight: 500;
            color: #555; margin-bottom: 5px;
        }
        .form-control {
            width: 100%; padding: 10px 12px; border: 1px solid #ddd;
            border-radius: 8px; font-size: 14px; transition: border-color 0.2s;
        }
        .form-control:focus { border-color: #3762c8; outline: none; box-shadow: 0 0 0 3px rgba(55,98,200,0.15); }
        .form-control:disabled { background: #f8f9fa; cursor: not-allowed; }
        .btn {
            padding: 10px 24px; border: none; border-radius: 8px;
            font-size: 14px; font-weight: 500; cursor: pointer;
            transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-primary { background: #3762c8; color: white; }
        .btn-primary:hover { background: #2a4fa8; transform: translateY(-1px); }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #218838; transform: translateY(-1px); }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; }
        .btn-sm { padding: 6px 14px; font-size: 13px; }
        .form-actions { display: flex; gap: 10px; margin-top: 20px; }
        .alert {
            padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;
            font-size: 14px; display: flex; align-items: center; gap: 8px;
        }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .avatar-section {
            display: flex; align-items: center; gap: 20px; margin-bottom: 20px;
        }
        .avatar-preview {
            width: 80px; height: 80px; border-radius: 50%;
            background: #e9ecef; display: flex; align-items: center;
            justify-content: center; font-size: 32px; color: #666;
            overflow: hidden; border: 3px solid #e9ecef;
        }
        .avatar-preview img { width: 100%; height: 100%; object-fit: cover; }
        .toggle-group { display: flex; align-items: center; justify-content: space-between; padding: 10px 0; }
        .toggle-group:not(:last-child) { border-bottom: 1px solid #f0f0f0; }
        .toggle-label { font-size: 14px; color: #333; }
        .toggle-label small { display: block; font-size: 12px; color: #888; }
        .switch { position: relative; display: inline-block; width: 44px; height: 24px; flex-shrink: 0; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
            background: #ccc; transition: 0.3s; border-radius: 24px;
        }
        .slider:before {
            content: ""; position: absolute; height: 18px; width: 18px;
            left: 3px; bottom: 3px; background: white; transition: 0.3s; border-radius: 50%;
        }
        .switch input:checked + .slider { background: #3762c8; }
        .switch input:checked + .slider:before { transform: translateX(20px); }
        .checkbox-group { display: flex; flex-direction: column; gap: 8px; padding: 5px 0; }
        .checkbox-item {
            display: flex; align-items: center; gap: 10px; padding: 6px 0;
        }
        .checkbox-item input[type="checkbox"] {
            width: 18px; height: 18px; accent-color: #3762c8; cursor: pointer;
        }
        .checkbox-item label { font-size: 14px; color: #333; cursor: pointer; }
        .private-banner {
            background: #fff3cd; color: #856404; padding: 12px 16px;
            border-radius: 8px; margin-bottom: 20px; font-size: 14px;
            display: flex; align-items: center; gap: 8px;
        }
        .private-banner i { font-size: 18px; }
        textarea.form-control { resize: vertical; min-height: 80px; }
        .restriction-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 15px;
        }
        .activity-filter-bar {
            display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
            margin-bottom: 20px; padding: 15px; background: #f8f9fa;
            border-radius: 10px; border: 1px solid #e9ecef;
        }
        .activity-filter-bar select, .activity-filter-bar input {
            padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;
            font-size: 13px; background: white;
        }
        .activity-filter-bar select:focus, .activity-filter-bar input:focus {
            border-color: #3762c8; outline: none;
        }
        .activity-filter-bar .filter-label {
            font-size: 13px; font-weight: 500; color: #555;
        }
        .activity-log-list {
            max-height: 500px; overflow-y: auto;
        }
        .activity-log-list::-webkit-scrollbar { width: 6px; }
        .activity-log-list::-webkit-scrollbar-track { background: rgba(55,98,200,0.05); border-radius: 3px; }
        .activity-log-list::-webkit-scrollbar-thumb { background: rgba(55,98,200,0.2); border-radius: 3px; }
        .activity-log-item {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 14px 16px; border-bottom: 1px solid #f0f0f0;
            transition: background 0.15s, opacity 0.35s ease, max-height 0.35s ease, margin 0.35s ease, padding 0.35s ease;
            max-height: 120px; overflow: hidden; opacity: 1;
        }
        .activity-log-item.collapsed {
            max-height: 0; opacity: 0; padding-top: 0; padding-bottom: 0;
            margin-top: 0; margin-bottom: 0; border-bottom: 0;
        }
        .activity-log-item:hover { background: rgba(55,98,200,0.03); }
        .activity-log-item:last-child { border-bottom: none; }
        .activity-log-icon {
            width: 36px; height: 36px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; color: white; flex-shrink: 0; margin-top: 2px;
        }
        .activity-log-icon.admin { background: linear-gradient(135deg, #3762c8, #1e3c72); }
        .activity-log-icon.staff { background: linear-gradient(135deg, #17a2b8, #138496); }
        .activity-log-icon.system { background: linear-gradient(135deg, #6c757d, #495057); }
        .activity-log-body { flex: 1; min-width: 0; }
        .activity-log-action {
            font-size: 14px; font-weight: 600; color: #1e293b; margin-bottom: 3px;
        }
        .activity-log-details {
            font-size: 13px; color: #64748b; margin-bottom: 4px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .activity-log-meta {
            display: flex; gap: 12px; align-items: center; flex-wrap: wrap;
        }
        .activity-log-meta span {
            font-size: 12px; color: #94a3b8; display: flex; align-items: center; gap: 4px;
        }
        .activity-log-meta .role-badge {
            padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 500;
        }
        .role-badge.system_admin { background: rgba(55,98,200,0.1); color: #3762c8; }
        .role-badge.lgu_staff { background: rgba(23,162,184,0.1); color: #17a2b8; }
        .role-badge.citizen { background: rgba(108,117,125,0.1); color: #6c757d; }
        .activity-count {
            font-size: 13px; color: #64748b; margin-bottom: 15px;
        }
        .activity-empty {
            text-align: center; padding: 40px; color: #94a3b8;
        }
        .activity-empty i { font-size: 36px; margin-bottom: 10px; display: block; }

        /* --- Admin Account Tab Redesign --- */
        .profile-header {
            display: flex; align-items: center; gap: 24px;
            padding: 28px 32px;
            background: linear-gradient(135deg, #1e3c72 0%, #2a4fa8 100%);
            border-radius: 14px; margin-bottom: 28px;
            color: white; position: relative; overflow: hidden;
        }
        .profile-header::before {
            content: ''; position: absolute; top: -50%; right: -20%;
            width: 300px; height: 300px;
            background: rgba(255,255,255,0.04);
            border-radius: 50%;
        }
        .profile-header::after {
            content: ''; position: absolute; bottom: -40%; left: 30%;
            width: 200px; height: 200px;
            background: rgba(255,255,255,0.03);
            border-radius: 50%;
        }
        .profile-avatar {
            width: 80px; height: 80px; border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex; align-items: center; justify-content: center;
            font-size: 36px; overflow: hidden;
            border: 3px solid rgba(255,255,255,0.4);
            flex-shrink: 0; position: relative; z-index: 1;
        }
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .profile-info { flex: 1; min-width: 0; position: relative; z-index: 1; }
        .profile-info h2 {
            font-size: 22px; font-weight: 700; margin: 0 0 4px;
        }
        .profile-info .profile-meta {
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
        }
        .profile-info .profile-meta span {
            font-size: 13px; opacity: 0.85;
            display: flex; align-items: center; gap: 5px;
        }
        .role-badge-header {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 12px; border-radius: 20px;
            font-size: 12px; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.5px;
            background: rgba(255,255,255,0.2);
            color: white;
        }
        .account-cards { display: flex; flex-direction: column; gap: 22px; }
        .account-card {
            background: #f0f4fa;
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
            border: 1px solid #eef0f2;
            overflow: hidden;
        }
        .account-card-header {
            display: flex; align-items: center; gap: 10px;
            padding: 18px 28px;
            border-bottom: 1px solid #f0f2f4;
            background: #fafbfc;
        }
        .account-card-header i {
            font-size: 18px; color: #3762c8;
            width: 32px; height: 32px;
            display: flex; align-items: center; justify-content: center;
            background: rgba(55,98,200,0.08);
            border-radius: 8px;
        }
        .account-card-header h3 {
            font-size: 15px; font-weight: 600; color: #1e293b; margin: 0;
        }
        .account-card-body { padding: 24px 28px; }
        .account-card-body .form-group { margin-bottom: 18px; }
        .account-card-body .form-group:last-child { margin-bottom: 0; }
        .avatar-upload-row {
            display: flex; align-items: center; gap: 20px;
            padding-bottom: 20px; margin-bottom: 20px;
            border-bottom: 1px solid #f0f2f4;
        }
        .avatar-upload-row .avatar-preview-sm {
            width: 64px; height: 64px; border-radius: 50%;
            background: #f1f3f5; display: flex; align-items: center;
            justify-content: center; font-size: 28px; color: #94a3b8;
            overflow: hidden; border: 2px solid #e9ecef; flex-shrink: 0;
        }
        .avatar-upload-row .avatar-preview-sm img { width: 100%; height: 100%; object-fit: cover; }
        .avatar-upload-row .upload-controls { display: flex; align-items: center; gap: 10px; flex: 1; }
        .avatar-upload-row .upload-controls input[type="file"] {
            font-size: 13px; color: #555; flex: 1;
        }
        .form-grid-2 {
            display: grid; grid-template-columns: 1fr 1fr; gap: 18px;
        }
        .form-grid-3 {
            display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 18px;
        }
        .btn-outline {
            background: white; color: #3762c8;
            border: 1px solid #3762c8;
        }
        .btn-outline:hover { background: rgba(55,98,200,0.06); }
        .security-divider {
            height: 1px; background: #f0f2f4; margin: 20px 0;
        }
        .password-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }
        .password-wrapper .form-control {
            padding-right: 40px;
        }
        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #888;
            padding: 4px;
            font-size: 16px;
            line-height: 1;
            transition: color 0.2s;
        }
        .password-toggle:hover {
            color: #333;
        }
        .twofa-section {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 20px;
            background: #f8fafc;
            border-radius: 10px;
            border: 1px solid #eef0f2;
        }
        .twofa-section .twofa-info { flex: 1; }
        .twofa-section .twofa-info strong {
            display: block; font-size: 14px; color: #1e293b; margin-bottom: 2px;
        }
        .twofa-section .twofa-info small {
            font-size: 12px; color: #94a3b8;
        }
        .twofa-section .switch { margin-left: 16px; }
        .twofa-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600;
        }
        .twofa-badge.on { background: rgba(40,167,69,0.1); color: #28a745; }
        .twofa-badge.off { background: rgba(108,117,125,0.1); color: #6c757d; }
        .field-hint {
            font-size: 12px; color: #94a3b8; margin-top: 4px;
        }
        /* Dark Mode */
        body.dark-mode {
            background: #1a1d23;
        }
        body.dark-mode .settings-container {
            background: #22262e;
            border-color: #2d323b;
        }
        body.dark-mode .page-header h1 { color: #e4e6ea; }
        body.dark-mode .profile-header {
            background: linear-gradient(135deg, #111318 0%, #1e2229 100%);
        }
        body.dark-mode .account-card {
            background: #22262e;
            border-color: #2d323b;
        }
        body.dark-mode .account-card-header {
            background: #1e2229;
            border-color: #2d323b;
        }
        body.dark-mode .account-card-header h3 { color: #e4e6ea; }
        body.dark-mode .account-card-body label { color: #9ca3af; }
        body.dark-mode .form-control {
            background: #1a1d23;
            border-color: #2d323b;
            color: #e4e6ea;
        }
        body.dark-mode .form-control:disabled {
            background: #22262e;
            color: #6b7280;
        }
        body.dark-mode .field-hint { color: #6b7280; }
        body.dark-mode .twofa-section {
            background: #1e2229;
            border-color: #2d323b;
        }
        body.dark-mode .twofa-section .twofa-info strong { color: #e4e6ea; }
        body.dark-mode .twofa-section .twofa-info small { color: #9ca3af; }
        body.dark-mode .security-divider { background: #2d323b; }
        body.dark-mode .btn-secondary { background: #374151; }
        body.dark-mode .btn-secondary:hover { background: #4b5563; }
        body.dark-mode .tab-btn { color: #9ca3af; }
        body.dark-mode .tab-btn.active { color: #60a5fa; }
        body.dark-mode .alert-success {
            background: #064e3b; color: #6ee7b7; border-color: #065f46;
        }
        body.dark-mode .alert-danger {
            background: #7f1d1d; color: #fca5a5; border-color: #991b1b;
        }
        body.dark-mode .form-control:focus {
            border-color: #60a5fa;
            box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.15);
        }
        body.dark-mode .password-toggle { color: #6b7280; }
        body.dark-mode .password-toggle:hover { color: #e4e6ea; }
        body.dark-mode .profile-info h2 { color: #e4e6ea; }
        body.dark-mode .profile-info .profile-meta span { color: #9ca3af; }
        body.dark-mode .profile-meta .role-badge-header {
            background: rgba(255,255,255,0.1);
        }
        body.dark-mode .twofa-badge.on { background: rgba(52,211,153,0.15); color: #34d399; }
        body.dark-mode .twofa-badge.off { background: rgba(156,163,175,0.15); color: #9ca3af; }
        body.dark-mode .btn-primary { background: #2563eb; }
        body.dark-mode .btn-primary:hover { background: #1d4ed8; }
        body.dark-mode .tab-btn:hover { color: #60a5fa; }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 15px; }
            .restriction-grid { grid-template-columns: 1fr; }
            .tabs { flex-wrap: wrap; }
            .form-grid-2 { grid-template-columns: 1fr; gap: 12px; }
            .form-grid-3 { grid-template-columns: 1fr; gap: 12px; }
            .profile-header { flex-direction: column; text-align: center; padding: 20px; }
            .profile-header .profile-meta { justify-content: center; }
            .avatar-upload-row { flex-direction: column; text-align: center; }
            .avatar-upload-row .upload-controls { flex-direction: column; }
        }
    </style>
</head>
<body class="<?php echo ($user_data['darkmode'] ?? 0) == 1 ? 'dark-mode' : ''; ?>">
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
        <div class="settings-container">
            <div class="page-header">
                <i class="fas fa-cog"></i>
                <h1>Settings</h1>
            </div>

            <?php if (isset($success_msg)): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success_msg; ?></div>
            <?php endif; ?>
            <?php if (isset($error_msg)): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error_msg; ?></div>
            <?php endif; ?>

            <div class="tabs">
                <button class="tab-btn active" data-tab="account">
                    <i class="fas fa-user-shield"></i> Account
                </button>
                <button class="tab-btn" data-tab="access">
                    <i class="fas fa-lock"></i> Access Control
                </button>
                <button class="tab-btn" data-tab="activity">
                    <i class="fas fa-history"></i> Activity Log
                </button>
            </div>

            <!-- Tab 1: Admin Account -->
            <div class="tab-content active" id="tab-account">

                <!-- Profile Header -->
                <div class="profile-header">
                    <div class="profile-avatar">
                        <?php if (!empty($user_data['profile_picture']) && file_exists('../../uploads/profile_pictures/' . $user_data['profile_picture'])): ?>
                            <img src="../../uploads/profile_pictures/<?php echo htmlspecialchars($user_data['profile_picture']); ?>" alt="Avatar">
                        <?php else: ?>
                            <i class="fas fa-user"></i>
                        <?php endif; ?>
                    </div>
                    <div class="profile-info">
                        <h2><?php echo htmlspecialchars($user_data['full_name'] ?? 'Admin'); ?></h2>
                        <div class="profile-meta">
                            <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user_data['email'] ?? ''); ?></span>
                            <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($user_data['address'] ?? 'N/A'); ?></span>
                            <span><i class="fas fa-calendar"></i> <?php echo htmlspecialchars($user_data['birthday'] ?? 'N/A'); ?></span>
                            <span><i class="fas fa-heart"></i> <?php echo htmlspecialchars(ucfirst($user_data['civil_status'] ?? 'N/A')); ?></span>
                            <span class="role-badge-header"><i class="fas fa-shield-alt"></i> <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $user_data['role'] ?? ''))); ?></span>
                            <?php if (($user_data['twofa'] ?? 0) == 1): ?>
                                <span class="twofa-badge on"><i class="fas fa-check-circle"></i> 2FA On</span>
                            <?php else: ?>
                                <span class="twofa-badge off"><i class="fas fa-times-circle"></i> 2FA Off</span>
                            <?php endif; ?>
                            <?php if (($user_data['darkmode'] ?? 0) == 1): ?>
                                <span class="twofa-badge on" style="background:rgba(96,165,250,0.15);color:#60a5fa;"><i class="fas fa-moon"></i> Dark</span>
                            <?php else: ?>
                                <span class="twofa-badge off"><i class="fas fa-sun"></i> Light</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Cards -->
                <div class="account-cards">

                    <!-- Edit Profile Card -->
                    <div class="account-card">
                        <div class="account-card-header">
                            <i class="fas fa-id-card"></i>
                            <h3>Edit Profile</h3>
                        </div>
                        <div class="account-card-body">
                            <!-- Avatar upload as part of this card -->
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="upload_avatar">
                                <div class="avatar-upload-row">
                                    <div class="avatar-preview-sm">
                                        <?php if (!empty($user_data['profile_picture']) && file_exists('../../uploads/profile_pictures/' . $user_data['profile_picture'])): ?>
                                            <img src="../../uploads/profile_pictures/<?php echo htmlspecialchars($user_data['profile_picture']); ?>" alt="Avatar">
                                        <?php else: ?>
                                            <i class="fas fa-user"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="upload-controls">
                                        <input type="file" name="avatar" accept="image/*" class="form-control" style="padding:8px;">
                                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-upload"></i> Upload</button>
                                    </div>
                                </div>
                            </form>

                            <?php if ($user_data['role'] === 'system_admin'): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="update_profile">
                                <div class="form-grid-2">
                                    <div class="form-group">
                                        <label>Full Name</label>
                                        <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($user_data['full_name'] ?? ''); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Email</label>
                                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Username</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user_data['username'] ?? ''); ?>" disabled>
                                        <div class="field-hint">Username cannot be changed</div>
                                    </div>
                                    <div class="form-group">
                                        <label>Role</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $user_data['role'] ?? ''))); ?>" disabled>
                                    </div>
                                    <div class="form-group">
                                        <label>Address</label>
                                        <input type="text" name="address" class="form-control" value="<?php echo htmlspecialchars($user_data['address'] ?? ''); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Birthday</label>
                                        <input type="date" name="birthday" class="form-control" value="<?php echo htmlspecialchars($user_data['birthday'] ?? ''); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Civil Status</label>
                                        <select name="civil_status" class="form-control">
                                            <option value="">Select status</option>
                                            <option value="single" <?php echo ($user_data['civil_status'] ?? '') === 'single' ? 'selected' : ''; ?>>Single</option>
                                            <option value="married" <?php echo ($user_data['civil_status'] ?? '') === 'married' ? 'selected' : ''; ?>>Married</option>
                                            <option value="divorced" <?php echo ($user_data['civil_status'] ?? '') === 'divorced' ? 'selected' : ''; ?>>Divorced</option>
                                            <option value="widowed" <?php echo ($user_data['civil_status'] ?? '') === 'widowed' ? 'selected' : ''; ?>>Widowed</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                                    <button type="reset" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</button>
                                </div>
                            </form>
                            <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="request_change">
                                <div class="form-grid-2">
                                    <div class="form-group">
                                        <label>Full Name</label>
                                        <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($user_data['full_name'] ?? ''); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Email</label>
                                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Username</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user_data['username'] ?? ''); ?>" disabled>
                                        <div class="field-hint">Username cannot be changed</div>
                                    </div>
                                    <div class="form-group">
                                        <label>Role</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $user_data['role'] ?? ''))); ?>" disabled>
                                    </div>
                                    <div class="form-group">
                                        <label>Address</label>
                                        <input type="text" name="address" class="form-control" value="<?php echo htmlspecialchars($user_data['address'] ?? ''); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Birthday</label>
                                        <input type="date" name="birthday" class="form-control" value="<?php echo htmlspecialchars($user_data['birthday'] ?? ''); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Civil Status</label>
                                        <select name="civil_status" class="form-control">
                                            <option value="">Select status</option>
                                            <option value="single" <?php echo ($user_data['civil_status'] ?? '') === 'single' ? 'selected' : ''; ?>>Single</option>
                                            <option value="married" <?php echo ($user_data['civil_status'] ?? '') === 'married' ? 'selected' : ''; ?>>Married</option>
                                            <option value="divorced" <?php echo ($user_data['civil_status'] ?? '') === 'divorced' ? 'selected' : ''; ?>>Divorced</option>
                                            <option value="widowed" <?php echo ($user_data['civil_status'] ?? '') === 'widowed' ? 'selected' : ''; ?>>Widowed</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Reason for Change</label>
                                    <textarea name="reason" class="form-control" rows="3" placeholder="Explain why you need to update your information..." style="resize:vertical;"></textarea>
                                </div>
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Request Change</button>
                                    <button type="reset" class="btn btn-secondary"><i class="fas fa-times"></i> Reset</button>
                                </div>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Security Card -->
                    <div class="account-card">
                        <div class="account-card-header">
                            <i class="fas fa-lock"></i>
                            <h3>Security</h3>
                        </div>
                        <div class="account-card-body">
                            <!-- Password Change -->
                            <form method="POST">
                                <input type="hidden" name="action" value="change_password">
                                <div class="form-grid-3">
                                    <div class="form-group">
                                        <label>Current Password</label>
                                        <div class="password-wrapper">
                                            <input type="password" name="current_password" id="currentPassword" class="form-control" placeholder="Enter current password" required>
                                            <button type="button" class="password-toggle" onclick="togglePassword('currentPassword', this)" tabindex="-1"><i class="fas fa-eye"></i></button>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>New Password</label>
                                        <div class="password-wrapper">
                                            <input type="password" name="new_password" id="newPassword" class="form-control" placeholder="Min. 8 characters" required minlength="8">
                                            <button type="button" class="password-toggle" onclick="togglePassword('newPassword', this)" tabindex="-1"><i class="fas fa-eye"></i></button>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>Confirm New Password</label>
                                        <div class="password-wrapper">
                                            <input type="password" name="confirm_password" id="confirmPassword" class="form-control" placeholder="Repeat new password" required minlength="8">
                                            <button type="button" class="password-toggle" onclick="togglePassword('confirmPassword', this)" tabindex="-1"><i class="fas fa-eye"></i></button>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-key"></i> Update Password</button>
                                </div>
                            </form>

                            <div class="security-divider"></div>

                            <!-- 2FA Toggle -->
                            <form method="POST">
                                <input type="hidden" name="action" value="toggle_twofa">
                                <div class="twofa-section">
                                    <div class="twofa-info">
                                        <strong><i class="fas fa-shield-alt" style="color:#3762c8; margin-right:6px;"></i> Two-Factor Authentication</strong>
                                        <small>When enabled, you'll need a one-time verification code from your email to log in.</small>
                                    </div>
                                    <label class="switch">
                                        <input type="checkbox" name="twofa" value="1" onchange="this.form.submit()" <?php echo ($user_data['twofa'] ?? 0) == 1 ? 'checked' : ''; ?>>
                                        <span class="slider"></span>
                                    </label>
                                </div>
                            </form>

                            <div class="security-divider"></div>

                            <!-- Dark Mode Toggle -->
                            <form method="POST">
                                <input type="hidden" name="action" value="toggle_darkmode">
                                <div class="twofa-section">
                                    <div class="twofa-info">
                                        <strong><i class="fas fa-palette" style="color:#60a5fa; margin-right:6px;"></i> Dark Mode</strong>
                                        <small>Switch between light and dark appearance for the system interface.</small>
                                    </div>
                                    <label class="switch">
                                        <input type="checkbox" name="darkmode" value="1" onchange="this.form.submit()" <?php echo ($user_data['darkmode'] ?? 0) == 1 ? 'checked' : ''; ?>>
                                        <span class="slider"></span>
                                    </label>
                                </div>
                            </form>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Tab 2: Access Control -->
            <div class="tab-content" id="tab-access">
                <form method="POST">
                    <input type="hidden" name="action" value="save_restrictions">

                    <div class="form-section">
                        <h3><i class="fas fa-globe"></i> Landing Page Visibility</h3>

                        <div class="toggle-group">
                            <div class="toggle-label">
                                Private Landing Page
                                <small>Entire landing page viewable only to logged-in users/admins</small>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="landing_page_private" value="1" <?php echo ($settings['landing_page_private'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div id="sectionToggles">
                            <div class="checkbox-group" style="margin-top:10px; padding-left:10px;">
                                <div class="checkbox-item">
                                    <input type="checkbox" name="hide_hero" value="1" id="hide_hero" <?php echo ($settings['hide_hero'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                    <label for="hide_hero">Hide Hero Section</label>
                                </div>
                                <div class="checkbox-item">
                                    <input type="checkbox" name="hide_updates" value="1" id="hide_updates" <?php echo ($settings['hide_updates'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                    <label for="hide_updates">Hide Road Updates Section</label>
                                </div>
                                <div class="checkbox-item">
                                    <input type="checkbox" name="hide_stats" value="1" id="hide_stats" <?php echo ($settings['hide_stats'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                    <label for="hide_stats">Hide Statistics Section</label>
                                </div>
                                <div class="checkbox-item">
                                    <input type="checkbox" name="hide_about" value="1" id="hide_about" <?php echo ($settings['hide_about'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                    <label for="hide_about">Hide About Section</label>
                                </div>
                                <div class="checkbox-item">
                                    <input type="checkbox" name="hide_contact" value="1" id="hide_contact" <?php echo ($settings['hide_contact'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                    <label for="hide_contact">Hide Contact Section</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-ban"></i> Feature Restrictions</h3>
                        <div class="checkbox-group">
                            <div class="checkbox-item">
                                <input type="checkbox" name="disable_signup" value="1" id="disable_signup" <?php echo ($settings['disable_signup'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <label for="disable_signup">Disable "Sign Up" Button</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" name="hide_contact_form" value="1" id="hide_contact_form" <?php echo ($settings['hide_contact_form'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <label for="hide_contact_form">Hide Contact Form</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" name="disable_search" value="1" id="disable_search" <?php echo ($settings['disable_search'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <label for="disable_search">Disable Search Functionality</label>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-exclamation-triangle"></i> Custom Redirect / Message</h3>
                        <div class="private-banner">
                            <i class="fas fa-info-circle"></i>
                            This message or redirect will be shown to restricted users who attempt to access blocked areas.
                        </div>
                        <div class="row" style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                            <div class="form-group">
                                <label>Custom Message</label>
                                <textarea name="custom_message" class="form-control" placeholder="e.g., This page is under maintenance. Please check back later."><?php echo htmlspecialchars($settings['custom_message'] ?? ''); ?></textarea>
                                <small style="color:#888; font-size:12px;">Displayed to users when a section is restricted</small>
                            </div>
                            <div class="form-group">
                                <label>Redirect URL</label>
                                <input type="url" name="redirect_url" class="form-control" placeholder="e.g., https://example.com/maintenance" value="<?php echo htmlspecialchars($settings['redirect_url'] ?? ''); ?>">
                                <small style="color:#888; font-size:12px;">Users will be redirected here if they access a restricted area</small>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-success" style="font-size:15px; padding:12px 32px;">
                            <i class="fas fa-shield-alt"></i> Apply Restrictions
                        </button>
                    </div>
                </form>
            </div>

            <!-- Tab 3: Activity Log -->
            <div class="tab-content" id="tab-activity">
                <div class="form-section">
                    <h3><i class="fas fa-history"></i> System Activity Log</h3>
                    <p style="font-size:13px; color:#64748b; margin-bottom:15px;">Track all user actions across the system. Use filters to narrow down by role or search keywords.</p>

                    <div class="activity-filter-bar">
                        <span class="filter-label"><i class="fas fa-filter"></i> Filter:</span>
                        <select id="activityRoleFilter">
                            <option value="all">All Roles</option>
                            <option value="system_admin">Admin</option>
                            <option value="lgu_staff">LGU Staff</option>
                            <option value="citizen">Citizen</option>
                        </select>
                        <input type="text" id="activitySearch" placeholder="Search actions..." style="min-width:200px;">
                        <input type="date" id="activityDateFrom" title="From date">
                        <input type="date" id="activityDateTo" title="To date">
                        <button type="button" class="btn btn-primary btn-sm" onclick="filterActivityLog()"><i class="fas fa-search"></i> Filter</button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="resetActivityFilter()"><i class="fas fa-undo"></i> Reset</button>
                        <button type="button" class="btn btn-danger btn-sm" onclick="clearActivityLog()" style="margin-left:auto;"><i class="fas fa-trash-alt"></i> Clear History</button>
                    </div>

                    <div class="activity-count" id="activityCount">
                        Showing <?php echo count($activity_log); ?> of <?php echo count($activity_log); ?> activities
                    </div>

                    <div class="activity-log-list" id="activityLogList">
                        <?php if (empty($activity_log)): ?>
                            <div class="activity-empty">
                                <i class="fas fa-inbox"></i>
                                <p>No activity recorded yet.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($activity_log as $log):
                                $user_role = $log['user_role'] ?? '';
                                $icon_class = 'system';
                                if ($user_role === 'system_admin') $icon_class = 'admin';
                                elseif ($user_role === 'lgu_staff' || $user_role === 'citizen') $icon_class = 'staff';

                                $fa_icon = 'fa-cog';
                                $action_lower = strtolower($log['action'] ?? '');
                                if (strpos($action_lower, 'login') !== false) $fa_icon = 'fa-sign-in-alt';
                                elseif (strpos($action_lower, 'logout') !== false) $fa_icon = 'fa-sign-out-alt';
                                elseif (strpos($action_lower, 'password') !== false) $fa_icon = 'fa-key';
                                elseif (strpos($action_lower, 'profile') !== false || strpos($action_lower, 'updated') !== false) $fa_icon = 'fa-user-edit';
                                elseif (strpos($action_lower, 'deactivat') !== false) $fa_icon = 'fa-user-slash';
                                elseif (strpos($action_lower, 'activat') !== false) $fa_icon = 'fa-user-check';
                                elseif (strpos($action_lower, 'restrict') !== false || strpos($action_lower, 'setting') !== false) $fa_icon = 'fa-shield-alt';
                                elseif (strpos($action_lower, 'register') !== false || strpos($action_lower, 'sign') !== false) $fa_icon = 'fa-user-plus';
                                elseif (strpos($action_lower, 'report') !== false) $fa_icon = 'fa-file-alt';
                                elseif (strpos($action_lower, 'approv') !== false) $fa_icon = 'fa-check-circle';
                                elseif (strpos($action_lower, 'reject') !== false) $fa_icon = 'fa-times-circle';
                                elseif (strpos($action_lower, 'delete') !== false) $fa_icon = 'fa-trash-alt';
                            ?>
                                <div class="activity-log-item"
                                     data-role="<?php echo htmlspecialchars(strtolower(trim($user_role))); ?>"
                                     data-action="<?php echo htmlspecialchars(strtolower(trim($log['action'] ?? ''))); ?>"
                                     data-details="<?php echo htmlspecialchars(strtolower(trim($log['details'] ?? ''))); ?>"
                                     data-name="<?php echo htmlspecialchars(strtolower(trim($log['full_name'] ?? ''))); ?>"
                                     data-date="<?php echo htmlspecialchars($log['created_at']); ?>">
                                    <div class="activity-log-icon <?php echo $icon_class; ?>">
                                        <i class="fas <?php echo $fa_icon; ?>"></i>
                                    </div>
                                    <div class="activity-log-body">
                                        <div class="activity-log-action"><?php echo htmlspecialchars($log['action']); ?></div>
                                        <div class="activity-log-details"><?php echo htmlspecialchars($log['details']); ?></div>
                                        <div class="activity-log-meta">
                                            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($log['full_name'] ?? 'System'); ?></span>
                                            <?php if ($user_role): ?>
                                                <span class="role-badge <?php echo htmlspecialchars($user_role); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $user_role))); ?></span>
                                            <?php endif; ?>
                                            <span><i class="fas fa-clock"></i> <?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($log['created_at']))); ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword(inputId, btn) {
            var input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
                btn.querySelector('i').className = 'fas fa-eye-slash';
            } else {
                input.type = 'password';
                btn.querySelector('i').className = 'fas fa-eye';
            }
        }
        // Tab switching
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                this.classList.add('active');
                document.getElementById('tab-' + this.dataset.tab).classList.add('active');
                if (window.parent && window.parent.setActiveNav) {
                    window.parent.setActiveNav('settings.php');
                }
            });
        });

        // Toggle section visibility when "Private Landing Page" is checked
        const privateToggle = document.querySelector('input[name="landing_page_private"]');
        const sectionToggles = document.getElementById('sectionToggles');
        function toggleSectionVisibility() {
            if (privateToggle.checked) {
                sectionToggles.style.opacity = '0.5';
                sectionToggles.querySelectorAll('input').forEach(cb => cb.disabled = true);
            } else {
                sectionToggles.style.opacity = '1';
                sectionToggles.querySelectorAll('input').forEach(cb => cb.disabled = false);
            }
        }
        if (privateToggle) {
            privateToggle.addEventListener('change', toggleSectionVisibility);
            toggleSectionVisibility();
        }

        // Activity Log filter
        function filterActivityLog() {
            const role = document.getElementById('activityRoleFilter').value.trim().toLowerCase();
            const search = document.getElementById('activitySearch').value.toLowerCase().trim();
            const dateFrom = document.getElementById('activityDateFrom').value;
            const dateTo = document.getElementById('activityDateTo').value;
            const items = document.querySelectorAll('.activity-log-item');
            let visible = 0;

            items.forEach(function(item) {
                const itemRole = (item.getAttribute('data-role') || '').trim().toLowerCase();
                const itemAction = (item.getAttribute('data-action') || '').trim().toLowerCase();
                const itemDetails = (item.getAttribute('data-details') || '').trim().toLowerCase();
                const itemName = (item.getAttribute('data-name') || '').trim().toLowerCase();
                const itemDate = item.getAttribute('data-date') || '';

                let show = true;

                if (role !== 'all' && itemRole !== role) {
                    show = false;
                }

                if (search && !itemAction.includes(search) && !itemDetails.includes(search) && !itemName.includes(search)) {
                    show = false;
                }

                if (dateFrom) {
                    var from = new Date(dateFrom);
                    var itemD = new Date(itemDate);
                    if (itemD < from) show = false;
                }

                if (dateTo) {
                    var to = new Date(dateTo + 'T23:59:59');
                    var itemD = new Date(itemDate);
                    if (itemD > to) show = false;
                }

                if (show) {
                    item.classList.remove('collapsed');
                    item.style.display = '';
                    visible++;
                } else {
                    item.classList.add('collapsed');
                }
            });

            // After transition completes, hide collapsed items from layout
            setTimeout(function() {
                items.forEach(function(item) {
                    if (item.classList.contains('collapsed')) {
                        item.style.display = 'none';
                    }
                });
            }, 360);

            var total = items.length;
            document.getElementById('activityCount').textContent = 'Showing ' + visible + ' of ' + total + ' activities';
        }

        function resetActivityFilter() {
            document.getElementById('activityRoleFilter').selectedIndex = 0;
            document.getElementById('activitySearch').value = '';
            document.getElementById('activityDateFrom').value = '';
            document.getElementById('activityDateTo').value = '';
            var items = document.querySelectorAll('.activity-log-item');
            var total = items.length;
            for (var i = 0; i < total; i++) {
                items[i].style.display = '';
                items[i].classList.remove('collapsed');
            }
            document.getElementById('activityCount').textContent = 'Showing ' + total + ' of ' + total + ' activities';
        }

        function clearActivityLog() {
            if (!confirm('Are you sure you want to delete all activity log history? This cannot be undone.')) return;

            var formData = new FormData();
            formData.append('action', 'clear_activity_log');

            fetch('', { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(result) {
                    if (result.success) {
                        var list = document.getElementById('activityLogList');
                        list.innerHTML = '<div class="activity-empty"><i class="fas fa-inbox"></i><p>No activity recorded yet.</p></div>';
                        document.getElementById('activityCount').textContent = 'Showing 0 of 0 activities';
                    } else {
                        alert(result.message || 'Failed to clear log.');
                    }
                })
                .catch(function() { alert('An error occurred. Please try again.'); });
        }

        // Auto-dismiss alerts
        document.querySelectorAll('.alert').forEach(alert => {
            setTimeout(() => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            }, 5000);
        });

        // Set active nav in parent sidebar
        if (window.parent && window.parent.document) {
            try {
                const sidebarDoc = window.parent.document.querySelector('iframe[name="sidebar-frame"]');
                if (sidebarDoc) {
                    sidebarDoc.contentWindow.postMessage({ type: 'setActive', page: 'settings.php' }, '*');
                }
            } catch(e) {}
        }
    </script>
</body>
</html>
