<?php
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
$login_url = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Email address is required.']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
        exit;
    }

    // Create (or rotate) the access tokens for this email
    $tokens = create_user_login_tokens($email);
    if (!$tokens) {
        echo json_encode(['success' => false, 'message' => 'Failed to create access tokens. Please try again.']);
        exit;
    }

    // Build the magic login URL carrying the login token
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    $loginUrl = $scheme . '://' . $host . dirname(dirname($scriptDir)) . '/login.php?token=' . $tokens['login_token'];

    // Send the email with the login link
    $emailSent = false;
    $emailMessage = '';
    try {
        $response = send_login_link_email($email, $loginUrl);
        $emailSent = !empty($response) && !isset($response['errors']);
        $emailMessage = $emailSent ? 'Access link emailed to the user.' : 'Access link created, but the notification email could not be sent.';
    } catch (Exception $e) {
        error_log("Login link email error: " . $e->getMessage());
        $emailMessage = 'Access link created, but the notification email could not be sent.';
    }

    echo json_encode([
        'success' => true,
        'message' => 'Access link generated successfully. ' . $emailMessage,
        'email' => $email,
        'login_url' => $loginUrl
    ]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Login Link - LGU Road Monitoring</title>
    <link rel="icon" type="image/png" href="../../assets/img/logocityhall.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../css/theme-tokens.css">
    <link rel="stylesheet" href="../../css/theme-utilities.css">
    <link rel="stylesheet" href="../../css/sidebar.css">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: #f7f5f0;
            min-height: 100vh;
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
            max-width: 640px;
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

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #1e3c72;
            margin-bottom: 6px;
        }

        .form-group input {
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

        .form-group input:focus {
            outline: none;
            border-color: #3762c8;
            box-shadow: 0 0 0 3px rgba(55, 98, 200, 0.15);
            background: #fff;
        }

        .field-hint {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 5px;
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

        .success-box .content .url-display {
            background: #fff;
            border: 1px dashed #10b981;
            border-radius: 6px;
            padding: 10px 14px;
            margin-top: 10px;
            font-family: monospace;
            font-size: 12px;
            color: #1e3c72;
            word-break: break-all;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .success-box .content .url-display button {
            background: none;
            border: none;
            color: #3762c8;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            white-space: nowrap;
        }

        .success-box .content .url-display button:hover {
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
        }
    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <!-- SIDEBAR -->
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="main-content">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="welcome-section">
                <div class="welcome-text">
                    <h1><i class="fas fa-envelope-open-text"></i> Send Login Link</h1>
                    <p>Email a login access link to a user. The link never expires for sign-in; the account creation link expires after 1 day.</p>
                </div>
                <div class="date-time">
                    <div id="currentDate"></div>
                    <div id="currentTime"></div>
                </div>
            </div>
        </div>

        <div class="workflow-container">
            <div class="workflow-card">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <i class="fas fa-paper-plane"></i>
                        <span>Send Access Link</span>
                    </h3>
                </div>

                <div id="alertContainer"></div>

                <form id="sendLinkForm" onsubmit="return handleSend(event)">
                    <div class="form-group">
                        <label for="email">Email Address *</label>
                        <input type="email" id="email" name="email" required placeholder="e.g. juan@lgu.gov.ph">
                        <div class="field-hint">The user will receive an email with a login URL containing a secure token.</div>
                    </div>

                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fas fa-paper-plane"></i> Send Link
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function handleSend(e) {
            e.preventDefault();

            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

            const formData = new FormData(document.getElementById('sendLinkForm'));

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
                                <h4>Access Link Generated</h4>
                                <p>${result.message}</p>
                                <p>Login link for <strong>${result.email}</strong>:</p>
                                <div class="url-display">
                                    <span id="generatedUrl">${result.login_url}</span>
                                    <button type="button" onclick="copyUrl()">Copy</button>
                                </div>
                            </div>
                        </div>
                    `;
                    document.getElementById('sendLinkForm').reset();
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
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Link';
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
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Link';
            });

            return false;
        }

        function copyUrl() {
            const url = document.getElementById('generatedUrl').textContent;
            navigator.clipboard.writeText(url).then(() => {
                const btn = event.target;
                btn.textContent = 'Copied!';
                setTimeout(() => { btn.textContent = 'Copy'; }, 1500);
            });
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
