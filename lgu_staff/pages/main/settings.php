<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'system_admin') {
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

        if (!empty($full_name) && !empty($email)) {
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?");
            $stmt->bind_param("ssi", $full_name, $email, $user_id);
            $stmt->execute();
            $stmt->close();

            $_SESSION['full_name'] = $full_name;
            log_audit_action($user_id, 'Profile Updated', 'Updated profile information');
            $success_msg = 'Profile updated successfully.';
        } else {
            $error_msg = 'Name and email are required.';
        }
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
}

// Get user data
$stmt = $conn->prepare("SELECT username, full_name, email, role, profile_picture FROM users WHERE id = ?");
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
            background: url("../../../assets/img/cityhall.jpeg") center/cover no-repeat fixed;
            min-height: 100vh;
            position: relative;
        }
        body::before {
            content: ""; position: absolute; inset: 0;
            backdrop-filter: blur(6px);
            background: rgba(0, 0, 0, 0.35);
            z-index: -1;
        }
        .main-content { margin-left: 250px; padding: 20px; position: relative; z-index: 1; }
        .settings-container {
            max-width: 900px; margin: 0 auto;
            background: rgba(255,255,255,0.95);
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
        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 15px; }
            .restriction-grid { grid-template-columns: 1fr; }
            .tabs { flex-wrap: wrap; }
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
                    <i class="fas fa-user-shield"></i> Admin Account
                </button>
                <button class="tab-btn" data-tab="access">
                    <i class="fas fa-lock"></i> Access Control
                </button>
            </div>

            <!-- Tab 1: Admin Account -->
            <div class="tab-content active" id="tab-account">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_avatar">
                    <div class="form-section">
                        <h3><i class="fas fa-id-card"></i> Profile Information</h3>
                        <div class="avatar-section">
                            <div class="avatar-preview">
                                <?php if (!empty($user_data['profile_picture']) && file_exists('../../uploads/profile_pictures/' . $user_data['profile_picture'])): ?>
                                    <img src="../../uploads/profile_pictures/<?php echo htmlspecialchars($user_data['profile_picture']); ?>" alt="Avatar">
                                <?php else: ?>
                                    <i class="fas fa-user"></i>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="form-group" style="margin-bottom:5px;">
                                    <input type="file" name="avatar" accept="image/*" class="form-control" style="padding:8px;">
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-upload"></i> Upload Photo</button>
                            </div>
                        </div>
                    </div>
                </form>

                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="form-section">
                        <div class="row" style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
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
                            </div>
                            <div class="form-group">
                                <label>Role</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars(ucfirst($user_data['role'] ?? '')); ?>" disabled>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                            <button type="reset" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</button>
                        </div>
                    </div>
                </form>

                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    <div class="form-section">
                        <h3><i class="fas fa-key"></i> Change Password</h3>
                        <div class="row" style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                            <div class="form-group">
                                <label>Current Password</label>
                                <input type="password" name="current_password" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>New Password</label>
                                <input type="password" name="new_password" class="form-control" required minlength="8">
                            </div>
                            <div class="form-group">
                                <label>Confirm New Password</label>
                                <input type="password" name="confirm_password" class="form-control" required minlength="8">
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-key"></i> Update Password</button>
                        </div>
                    </div>
                </form>
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
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
