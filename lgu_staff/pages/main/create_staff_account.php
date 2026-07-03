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

    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $birthday = trim($_POST['birthday'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $civil_status = trim($_POST['civil_status'] ?? '');
    $department = trim($_POST['department'] ?? '');

    if (empty($username) || empty($email) || empty($first_name) || empty($last_name)) {
        echo json_encode(['success' => false, 'message' => 'Username, email, first name, and last name are required.']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
        exit;
    }

    // Check for duplicate username or email
    $check = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $check->bind_param("ss", $username, $email);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $check->close();
        echo json_encode(['success' => false, 'message' => 'Username or email already exists.']);
        exit;
    }
    $check->close();

    // Combine name fields into full_name
    $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
    $full_name = preg_replace('/\s+/', ' ', $full_name);

    // Generate a random password
    $raw_password = bin2hex(random_bytes(6));
    $hashed_password = password_hash($raw_password, PASSWORD_DEFAULT);

    $role = 'lgu_staff';

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

    $stmt = $conn->prepare("INSERT INTO users (username, email, password, full_name, role, department, address, birthday, civil_status, id_file_path, account_status, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 1)");
    $stmt->bind_param("ssssssssss", $username, $email, $hashed_password, $full_name, $role, $department, $address, $birthday, $civil_status, $id_file_path);

    if ($stmt->execute()) {
        $new_user_id = $stmt->insert_id;
        $stmt->close();

        // Audit log
        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address, user_agent, created_at) VALUES (?, 'Staff Account Created', ?, ?, ?, NOW())");
        $details = "Created account for $full_name ($username) with role $role";
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $log->bind_param("isss", $_SESSION['user_id'], $details, $ip, $ua);
        $log->execute();
        $log->close();

        echo json_encode([
            'success' => true,
            'message' => 'Staff account created successfully.',
            'password' => $raw_password,
            'username' => $username
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../../styles/transition.css">
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
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 25px;
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

        .workflow-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e3c72;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #1e3c72;
            margin-bottom: 6px;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            color: #1e293b;
            background: #f8fafc;
            transition: border-color 0.2s, box-shadow 0.2s;
            box-sizing: border-box;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #3762c8;
            box-shadow: 0 0 0 3px rgba(55, 98, 200, 0.15);
            background: #fff;
        }

        .form-group select:disabled {
            background: #e2e8f0;
            color: #475569;
            cursor: not-allowed;
        }

        .form-group .locked-select {
            background: #e2e8f0;
            color: #475569;
            cursor: not-allowed;
            pointer-events: none;
        }

        .file-input-wrapper {
            position: relative;
            border: 2px dashed #d1d5db;
            border-radius: 8px;
            overflow: hidden;
            transition: border-color 0.2s;
        }

        .file-input-wrapper:hover {
            border-color: #3762c8;
        }

        .file-input-wrapper input[type="file"] {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
            z-index: 2;
        }

        .file-input-display {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #64748b;
            text-align: center;
        }

        .file-input-display i {
            font-size: 28px;
            color: #3762c8;
            margin-bottom: 8px;
        }

        .file-input-display span {
            font-size: 13px;
            font-weight: 500;
        }

        .file-input-display small {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 4px;
        }

        .file-input-display.has-file i {
            color: #10b981;
        }

        .file-input-display.has-file span {
            color: #065f46;
            font-weight: 600;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-actions {
            grid-column: 1 / -1;
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 10px;
        }

        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .btn-primary {
            background: #3762c8;
            color: white;
        }

        .btn-primary:hover {
            background: #2a4a9a;
        }

        .btn-secondary {
            background: #64748b;
            color: white;
        }

        .btn-secondary:hover {
            background: #475569;
        }

        .success-box {
            background: #d1fae5;
            border: 1px solid #10b981;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .success-box .icon {
            color: #10b981;
            font-size: 20px;
            margin-top: 2px;
        }

        .success-box .content h4 {
            color: #065f46;
            font-size: 15px;
            margin-bottom: 4px;
        }

        .success-box .content p {
            color: #047857;
            font-size: 13px;
        }

        .success-box .content .password-display {
            background: #fff;
            border: 1px dashed #10b981;
            border-radius: 6px;
            padding: 10px 14px;
            margin-top: 10px;
            font-family: monospace;
            font-size: 16px;
            color: #1e3c72;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .success-box .content .password-display button {
            background: none;
            border: none;
            color: #3762c8;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
        }

        .success-box .content .password-display button:hover {
            text-decoration: underline;
        }

        .error-box {
            background: #fee2e2;
            border: 1px solid #ef4444;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .error-box .icon {
            color: #ef4444;
            font-size: 20px;
        }

        .error-box .content p {
            color: #991b1b;
            font-size: 14px;
        }

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

            .form-grid {
                grid-template-columns: 1fr;
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
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="welcome-section">
                <div class="welcome-text">
                    <h1><i class="fas fa-user-plus"></i> Create Staff Account</h1>
                    <p>Register a new LGU staff member into the system</p>
                </div>
                <div class="date-time">
                    <div id="currentDate"></div>
                    <div id="currentTime"></div>
                </div>
            </div>
        </div>

        <div class="workflow-container">
            <!-- Account Details -->
            <div class="workflow-card">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <i class="fas fa-id-card"></i>
                        <span>Account Details</span>
                    </h3>
                </div>

                <div id="alertContainer"></div>

                <form id="createForm" onsubmit="return handleCreate(event)" enctype="multipart/form-data">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="username">Username *</label>
                            <input type="text" id="username" name="username" required placeholder="e.g. juan.delacruz">
                        </div>
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
                            <select id="role" name="role" class="locked-select">
                                <option value="lgu_staff" selected>LGU Staff</option>
                            </select>
                        </div>
                    </div>
            </div>

            <!-- Other Information -->
            <div class="workflow-card">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <i class="fas fa-user"></i>
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
                                <p>Username: <strong>${result.username}</strong></p>
                                <p>Temporary password (share this with the staff member):</p>
                                <div class="password-display">
                                    <span id="generatedPassword">${result.password}</span>
                                    <button type="button" onclick="copyPassword()">Copy</button>
                                </div>
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

    <!-- Page Transition Overlay -->
    <div class="page-transition-overlay" id="pageTransitionOverlay">
        <div class="transition-content">
            <div class="transition-spinner">
                <i class="fas fa-spinner"></i>
            </div>
            <div class="transition-text">Loading...</div>
        </div>
    </div>
</body>
</html>
