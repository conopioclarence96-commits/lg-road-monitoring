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
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    header('Location: ../../login.php');
    exit();
}

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $email = trim($_POST['email'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $birthday = trim($_POST['birthday'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $civil_status = trim($_POST['civil_status'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $department = trim($_POST['department'] ?? '');

    if (empty($email) || empty($first_name) || empty($last_name)) {
        echo json_encode(['success' => false, 'message' => 'Email, first name, and last name are required.']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
        exit;
    }

    // The email address is the user's username throughout the system.
    $username = $email;

    // Check for duplicate email
    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $check->close();
        echo json_encode(['success' => false, 'message' => 'Email address already exists.']);
        exit;
    }
    $check->close();

    // Combine name fields into full_name
    $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
    $full_name = preg_replace('/\s+/', ' ', $full_name);

    // Generate a secure random temporary password
    $raw_password = generate_secure_temporary_password(12);
    $hashed_password = password_hash($raw_password, PASSWORD_DEFAULT);

    $allowed_roles = ['system_admin', 'road_monitoring_officer', 'road_ops_supervisor', 'trans_monitoring_officer', 'trans_ops_supervisor', 'lgu_staff'];
    $role = $_POST['role'] ?? 'lgu_staff';
    if (!in_array($role, $allowed_roles, true)) {
        $role = 'lgu_staff';
    }

    // Handle ID file upload
    $id_file_path = null;
    if (isset($_FILES['id_file']) && $_FILES['id_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../../uploads/ids/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $fileExt = strtolower(pathinfo($_FILES['id_file']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        if (in_array($fileExt, $allowed)) {
            $uniqueFilename = uniqid() . '_' . time() . '.' . $fileExt;
            $targetFile = $uploadDir . $uniqueFilename;
            if (move_uploaded_file($_FILES['id_file']['tmp_name'], $targetFile)) {
                $id_file_path = 'uploads/ids/' . $uniqueFilename;
            }
        }
    }

    $stmt = $conn->prepare("INSERT INTO users (username, email, password, full_name, role, department, address, birthday, civil_status, phone_number, id_file_path, account_status, is_active, must_change_password, temporary_password_created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, NULLIF(?, ''), ?, 'verified', 1, 1, NOW())");
    $stmt->bind_param("sssssssssss", $username, $email, $hashed_password, $full_name, $role, $department, $address, $birthday, $civil_status, $phone_number, $id_file_path);

    if ($stmt->execute()) {
        $new_user_id = $stmt->insert_id;
        $stmt->close();

        // Audit log
        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address, user_agent, created_at) VALUES (?, 'Staff Account Created', ?, ?, ?, NOW())");
        $details = "Created account for $full_name ($email) with role $role";
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $log->bind_param("isss", $_SESSION['user_id'], $details, $ip, $ua);
        $log->execute();
        $log->close();

        // Send the welcome email with the temporary password and login link.
        // The login link carries a login-only token (no registration offered).
        $emailSent = false;
        $emailMessage = '';
        $loginUrl = '';
        try {
            $tokens = create_user_login_tokens($email, false);
            if ($tokens) {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
                $loginUrl = $scheme . '://' . $host . dirname(dirname($scriptDir)) . '/login.php?login_token=' . $tokens['login_token'];
                $firstName = trim(explode(' ', $full_name)[0]);

                $response = send_staff_account_email($email, $firstName, $raw_password, $loginUrl);
                $emailSent = !empty($response) && !isset($response['errors']);
                $emailMessage = $emailSent ? 'Credentials emailed to the staff member.' : 'Account created, but the notification email could not be sent.';
            } else {
                $emailMessage = 'Account created, but the access token could not be created.';
            }
        } catch (Exception $e) {
            error_log("Staff account email error: " . $e->getMessage());
            $emailMessage = 'Account created, but the notification email could not be sent.';
        }

        echo json_encode([
            'success' => true,
            'message' => 'Staff account created successfully. ' . $emailMessage,
            'password' => $raw_password,
            'email' => $email,
            'login_url' => $loginUrl
        ]);
        exit;
    } else {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Failed to create account. Please try again.']);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Staff Account - LGU Road Monitoring</title>
    <link rel="icon" type="image/png" href="../../assets/img/infra-gov-logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../css/theme-tokens.css">
    <link rel="stylesheet" href="../../css/theme-utilities.css">
    <link rel="stylesheet" href="../../css/sidebar.css?v=6">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background: #f5f3ee; min-height: 100vh; color: var(--text-primary); }
        body.dark-mode { background: var(--bg-page); }

        .main-content.create-dash {
            margin-left: 250px; padding: 24px 28px; max-width: 100%; overflow-x: hidden;
            position: relative; z-index: 1;
        }

        /* Dashboard header */
        .dashboard-header {
            background: #f4f7fb; border-radius: 14px; padding: 20px 26px; margin-bottom: 22px;
            border: 1px solid #d5dce8; box-shadow: var(--shadow-card);
            display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap;
        }
        .welcome-section { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; }
        .welcome-text h1 { font-size: 22px; font-weight: 700; color: var(--text-primary); margin-bottom: 4px; display: flex; align-items: center; gap: 12px; }
        .header-icon {
            width: 40px; height: 40px; border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #1e3c72, #0f274a); color: #fff; font-size: 16px;
            box-shadow: 0 4px 12px rgba(30, 60, 114, 0.35);
        }
        .welcome-text p { color: var(--text-secondary); font-size: 13px; }
        .date-time { color: var(--text-secondary); font-size: 13px; }
        .dt-chip {
            display: flex; align-items: center; gap: 10px;
            background: var(--color-primary-bg); border: 1px solid var(--border-default);
            border-radius: 14px; padding: 10px 14px;
        }
        .dt-chip i {
            color: #fff; font-size: 16px; width: 28px; height: 28px; border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #1e3c72, #0f274a);
        }
        .dt-chip #currentDate { font-weight: 600; color: var(--text-primary); font-size: 13px; }
        .dt-chip #currentTime { color: var(--text-secondary); font-size: 12px; margin-top: 1px; }

        /* Workflow cards */
        .workflow-container { display: grid; grid-template-columns: 1fr; gap: 22px; }
        .workflow-card {
            background: #f4f7fb; border-radius: 14px; padding: 22px;
            border: 1px solid #d5dce8; box-shadow: var(--shadow-card);
            position: relative; overflow: hidden;
        }
        .workflow-card::after { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; }
        .workflow-card.panel-account::after { background: linear-gradient(180deg, #1e3c72, #0f274a); }
        .workflow-card.panel-other::after { background: linear-gradient(180deg, #5a4e78, #3f3658); }
        .workflow-header {
            display: flex; justify-content: space-between; align-items: center;
            margin: -22px -22px 18px; padding: 14px 18px 14px 22px;
            border-bottom: 1px solid var(--border-light);
        }
        .workflow-card.panel-account .workflow-header { background: rgba(30, 60, 114, 0.06); }
        .workflow-card.panel-other .workflow-header { background: rgba(90, 78, 120, 0.06); }
        .workflow-title { font-size: 14px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 10px; }
        .workflow-card.panel-account .workflow-title { color: #1e3c72; }
        .workflow-card.panel-other .workflow-title { color: #3f3658; }
        .title-icon {
            width: 32px; height: 32px; border-radius: 9px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 13px; flex-shrink: 0; color: #fff;
        }
        .workflow-card.panel-account .title-icon { background: linear-gradient(135deg, #1e3c72, #0f274a); }
        .workflow-card.panel-other .title-icon { background: linear-gradient(135deg, #5a4e78, #3f3658); }

        /* Form */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-group { margin-bottom: 0; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 6px; }
        .form-group input,
        .form-group select {
            width: 100%; padding: 9px 12px; border: 1px solid var(--border-default); border-radius: 8px;
            font-size: 13px; color: var(--text-primary); background: var(--bg-input);
            transition: border-color 0.2s, box-shadow 0.2s; box-sizing: border-box;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none; border-color: #1e3c72; box-shadow: 0 0 0 3px rgba(30, 60, 114, 0.12); background: #fff;
        }
        .form-group select:disabled { background: var(--bg-input-readonly); color: var(--text-secondary); cursor: not-allowed; }
        .form-group .locked-select { background: var(--bg-input-readonly); color: var(--text-secondary); cursor: not-allowed; pointer-events: none; }

        .file-input-wrapper { position: relative; border: 2px dashed var(--border-default); border-radius: 10px; overflow: hidden; transition: border-color 0.2s; }
        .file-input-wrapper:hover { border-color: #1e3c72; }
        .file-input-wrapper input[type="file"] { position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; z-index: 2; }
        .file-input-display { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 20px; color: var(--text-secondary); text-align: center; }
        .file-input-display i { font-size: 28px; color: #1e3c72; margin-bottom: 8px; }
        .file-input-display span { font-size: 13px; font-weight: 500; }
        .file-input-display small { font-size: 11px; color: var(--text-muted); margin-top: 4px; }
        .file-input-display.has-file i { color: #16a34a; }
        .file-input-display.has-file span { color: #15803d; font-weight: 600; }

        .form-group.full-width { grid-column: 1 / -1; }
        .form-actions { grid-column: 1 / -1; display: flex; gap: 12px; justify-content: flex-end; margin-top: 10px; }
        .btn { padding: 10px 24px; border: none; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background: linear-gradient(135deg, #1e3c72, #0f274a); color: #fff; box-shadow: 0 4px 12px rgba(30, 60, 114, 0.3); }
        .btn-primary:hover { filter: brightness(1.15); transform: translateY(-1px); }
        .btn-secondary { background: linear-gradient(135deg, #64748b, #475569); color: #fff; }
        .btn-secondary:hover { filter: brightness(1.1); transform: translateY(-1px); }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        /* Alerts */
        .success-box {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0); border: 1px solid #10b981;
            border-radius: 10px; padding: 16px 20px; margin-bottom: 20px;
            display: flex; align-items: flex-start; gap: 12px;
        }
        .success-box .icon { color: #059669; font-size: 20px; margin-top: 2px; }
        .success-box .content h4 { color: #065f46; font-size: 15px; margin-bottom: 4px; }
        .success-box .content p { color: #047857; font-size: 13px; }
        .success-box .content .password-display {
            background: #fff; border: 1px dashed #10b981; border-radius: 6px; padding: 10px 14px; margin-top: 10px;
            font-family: monospace; font-size: 16px; color: #1e3c72; letter-spacing: 1px;
            display: flex; align-items: center; justify-content: space-between;
        }
        .success-box .content .password-display button { background: none; border: none; color: #3762c8; cursor: pointer; font-size: 13px; font-weight: 500; }
        .success-box .content .password-display button:hover { text-decoration: underline; }
        .error-box {
            background: linear-gradient(135deg, #fee2e2, #fecaca); border: 1px solid #ef4444;
            border-radius: 10px; padding: 16px 20px; margin-bottom: 20px;
            display: flex; align-items: center; gap: 12px;
        }
        .error-box .icon { color: #dc2626; font-size: 20px; }
        .error-box .content p { color: #991b1b; font-size: 14px; }

        /* Dark mode */
        body.dark-mode .dashboard-header,
        body.dark-mode .workflow-card {
            background: #1c2432 !important; border-color: rgba(147, 179, 224, 0.22) !important;
        }
        body.dark-mode .workflow-header { background: rgba(255, 255, 255, 0.03) !important; border-color: var(--border-default) !important; }
        body.dark-mode .workflow-card.panel-account .workflow-title { color: #93b3e0 !important; }
        body.dark-mode .workflow-card.panel-other .workflow-title { color: #c5bdd8 !important; }
        body.dark-mode .dt-chip { background: var(--color-primary-bg); border-color: var(--border-default); }
        body.dark-mode .dt-chip i { background: linear-gradient(135deg, #1e3c72, #0f274a); }
        body.dark-mode .welcome-text h1 { color: var(--text-primary); }
        body.dark-mode .welcome-text p,
        body.dark-mode .date-time { color: var(--text-secondary) !important; }
        body.dark-mode .form-group input,
        body.dark-mode .form-group select {
            background: #1c2432 !important; border-color: var(--border-default) !important; color: var(--text-primary) !important;
        }
        body.dark-mode .form-group input:focus,
        body.dark-mode .form-group select:focus {
            border-color: #6a9bff !important; box-shadow: 0 0 0 3px rgba(106, 155, 255, 0.12) !important; background: #1c2432 !important;
        }
        body.dark-mode .file-input-wrapper { border-color: var(--border-default); }
        body.dark-mode .success-box .content .password-display { background: #1c2432; color: var(--text-primary); }

        @media (max-width: 768px) {
            .main-content.create-dash { margin-left: 0; padding: 16px; }
            .dashboard-header { flex-direction: column; align-items: flex-start; }
            .welcome-section { flex-direction: column; align-items: flex-start; }
            .date-time { text-align: left; }
            .form-grid { grid-template-columns: 1fr; }
        }    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <!-- SIDEBAR -->
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="main-content create-dash">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="welcome-text">
                <h1>
                    <span class="header-icon"><i class="fas fa-user-plus"></i></span>
                    Create Staff Account
                </h1>
                <p>Register a new LGU staff member into the system</p>
            </div>
            <div class="dt-chip">
                <i class="fas fa-calendar-day"></i>
                <div>
                    <div id="currentDate"></div>
                    <div id="currentTime"></div>
                </div>
            </div>
        </div>

        <div class="workflow-container">
            <!-- Account Details -->
            <div class="workflow-card panel-account">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <span class="title-icon"><i class="fas fa-id-card"></i></span>
                        <span>Account Details</span>
                    </h3>
                </div>

                <div id="alertContainer"></div>

                <form id="createForm" onsubmit="return handleCreate(event)" enctype="multipart/form-data">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="email">Email Address *</label>
                            <input type="email" id="email" name="email" required placeholder="e.g. juan@lgu.gov.ph">
                        </div>
                        <div class="form-group">
                            <label for="department">Department</label>
                            <select id="department" name="department" class="locked-select">
                                <option value="LGU Services" selected>LGU Services</option>
                                <option value="Engineering">Engineering</option>
                                <option value="Planning">Planning</option>
                                <option value="Maintenance">Maintenance</option>
                                <option value="Finance">Finance</option>
                                <option value="IT / System Administration">IT / System Administration</option>
                                <option value="LGU Services">LGU Services</option>
                                <option value="Citizen Services">Citizen Services</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="role">Role</label>
                            <select id="role" name="role">
                                <option value="lgu_staff">LGU Staff</option>
                                <option value="system_admin">Admin</option>
                                <option value="road_monitoring_officer">Road Monitoring Officer</option>
                                <option value="road_ops_supervisor">Road Ops Supervisor</option>
                                <option value="trans_monitoring_officer">Trans Monitoring Officer</option>
                                <option value="trans_ops_supervisor">Trans Ops Supervisor</option>
                            </select>
                        </div>
                    </div>
            </div>

            <!-- Other Information -->
            <div class="workflow-card panel-other">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <span class="title-icon"><i class="fas fa-user"></i></span>
                        <span>Other Information</span>
                    </h3>
                </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="first_name">First Name *</label>
                            <input type="text" id="first_name" name="first_name" required placeholder="e.g. Juan">
                        </div>
                        <div class="form-group">
                            <label for="middle_name">Middle Name</label>
                            <input type="text" id="middle_name" name="middle_name" placeholder="e.g. Reyes">
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name *</label>
                            <input type="text" id="last_name" name="last_name" required placeholder="e.g. Dela Cruz">
                        </div>
                        <div class="form-group">
                            <label for="birthday">Birthday</label>
                            <input type="date" id="birthday" name="birthday">
                        </div>
                        <div class="form-group">
                            <label for="address">Address</label>
                            <input type="text" id="address" name="address" placeholder="e.g. Brgy. San Isidro, Manila">
                        </div>
                        <div class="form-group">
                            <label for="civil_status">Civil Status</label>
                            <select id="civil_status" name="civil_status">
                                <option value="">Select status</option>
                                <option value="single">Single</option>
                                <option value="married">Married</option>
                                <option value="divorced">Divorced</option>
                                <option value="widowed">Widowed</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="phone_number">Contact Number</label>
                            <input type="tel" id="phone_number" name="phone_number" placeholder="e.g. 09171234567" maxlength="20" pattern="[0-9+\-\s()]+" title="Enter a valid contact number">
                        </div>
                        <div class="form-group full-width">
                            <label for="id_file">Upload Valid ID (Optional)</label>
                            <div class="file-input-wrapper">
                                <input type="file" id="id_file" name="id_file" accept="image/*,.pdf" onchange="handleFileSelect(event)">
                                <div class="file-input-display" id="fileDisplay">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <span>Choose a file or drag it here</span>
                                    <small>JPG, PNG, or PDF (Max 5MB)</small>
                                </div>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="resetForm()">Clear</button>
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <i class="fas fa-plus-circle"></i> Create Account
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function handleFileSelect(e) {
            const file = e.target.files[0];
            const display = document.getElementById('fileDisplay');
            if (file) {
                display.classList.add('has-file');
                display.innerHTML = `
                    <i class="fas fa-file-check"></i>
                    <span>${file.name}</span>
                    <small>${(file.size / 1024).toFixed(1)} KB</small>
                `;
            } else {
                display.classList.remove('has-file');
                display.innerHTML = `
                    <i class="fas fa-cloud-upload-alt"></i>
                    <span>Choose a file or drag it here</span>
                    <small>JPG, PNG, or PDF (Max 5MB)</small>
                `;
            }
        }

        function handleCreate(e) {
            e.preventDefault();

            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';

            const formData = new FormData(document.getElementById('createForm'));

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                const container = document.getElementById('alertContainer');

                if (result.success) {
                    container.innerHTML = `
                        <div class="success-box">
                            <div class="icon"><i class="fas fa-check-circle"></i></div>
                            <div class="content">
                                <h4>Account Created Successfully</h4>
                                <p>Email Address: <strong>${result.email}</strong></p>
                                <p>Credentials have been emailed to the staff member. A secure temporary password is also shown below:</p>
                                <div class="password-display">
                                    <span id="generatedPassword">${result.password}</span>
                                    <button type="button" onclick="copyPassword()">Copy</button>
                                </div>
                                ${result.login_url ? `
                                <p style="margin-top:12px;">Login link (one-time magic link with token):</p>
                                <div class="password-display">
                                    <span id="generatedUrl">${result.login_url}</span>
                                    <button type="button" onclick="copyUrl()">Copy</button>
                                </div>` : ''}
                            </div>
                        </div>
                    `;
                    document.getElementById('createForm').reset();
                    document.getElementById('department').value = 'LGU Services';
                    document.getElementById('role').value = 'lgu_staff';
                    resetFileDisplay();
                } else {
                    container.innerHTML = `
                        <div class="error-box">
                            <div class="icon"><i class="fas fa-exclamation-circle"></i></div>
                            <div class="content">
                                <p>${result.message}</p>
                            </div>
                        </div>
                    `;
                }

                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-plus-circle"></i> Create Account';
            })
            .catch(error => {
                console.error('Error:', error);
                const container = document.getElementById('alertContainer');
                container.innerHTML = `
                    <div class="error-box">
                        <div class="icon"><i class="fas fa-exclamation-circle"></i></div>
                        <div class="content">
                            <p>An error occurred. Please try again.</p>
                        </div>
                    </div>
                `;
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-plus-circle"></i> Create Account';
            });

            return false;
        }

        function copyPassword() {
            const password = document.getElementById('generatedPassword').textContent;
            navigator.clipboard.writeText(password).then(() => {
                const btn = event.target;
                btn.textContent = 'Copied!';
                setTimeout(() => { btn.textContent = 'Copy'; }, 1500);
            });
        }

        function copyUrl() {
            const url = document.getElementById('generatedUrl').textContent;
            navigator.clipboard.writeText(url).then(() => {
                const btn = event.target;
                btn.textContent = 'Copied!';
                setTimeout(() => { btn.textContent = 'Copy'; }, 1500);
            });
        }

        function resetFileDisplay() {
            const display = document.getElementById('fileDisplay');
            display.classList.remove('has-file');
            display.innerHTML = `
                <i class="fas fa-cloud-upload-alt"></i>
                <span>Choose a file or drag it here</span>
                <small>JPG, PNG, or PDF (Max 5MB)</small>
            `;
        }

        function resetForm() {
            document.getElementById('createForm').reset();
            document.getElementById('department').value = 'LGU Services';
            document.getElementById('role').value = 'lgu_staff';
            document.getElementById('alertContainer').innerHTML = '';
            resetFileDisplay();
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
