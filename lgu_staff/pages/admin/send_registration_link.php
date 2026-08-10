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

// Build a readable status from a user_tokens row WITHOUT exposing the token values.
function buildTokenStatus($row) {
    $parts[] = !empty($row['login_token_active']) ? 'Login active' : 'Login disabled';
    if (!empty($row['register_token_used_at'])) {
        $parts[] = 'Register used';
    } elseif (!empty($row['register_token_expires_at']) && strtotime($row['register_token_expires_at']) < time()) {
        $parts[] = 'Register expired';
    } else {
        $parts[] = 'Register active';
    }
    return implode(' · ', $parts);
}

$success_message = '';
$error_message = '';
$login_url = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    // Row actions: turn off login, turn off register, or delete a token row.
    $action = trim($_POST['action'] ?? '');
    if ($action !== '') {
        $email = trim($_POST['email'] ?? '');
        if (empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Email address is required.']);
            exit;
        }

        $label = '';
        if ($action === 'turn_off_login') {
            $stmt = $conn->prepare("UPDATE user_tokens SET login_token_active = 0 WHERE email = ?");
            $label = 'login disabled';
        } elseif ($action === 'turn_off_register') {
            $stmt = $conn->prepare("UPDATE user_tokens SET register_token_used_at = NOW() WHERE email = ?");
            $label = 'register disabled';
        } elseif ($action === 'delete') {
            $stmt = $conn->prepare("DELETE FROM user_tokens WHERE email = ?");
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid action.']);
            exit;
        }

        $stmt->bind_param("s", $email);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($action === 'delete') {
            if ($affected <= 0) {
                echo json_encode(['success' => false, 'message' => 'No token found for that email.']);
                exit;
            }
            $newStatus = null;
        } else {
            $check = $conn->prepare("SELECT email, login_token_active, register_token_used_at, register_token_expires_at FROM user_tokens WHERE email = ?");
            $check->bind_param("s", $email);
            $check->execute();
            $row = $check->get_result()->fetch_assoc();
            $check->close();
            if (!$row) {
                echo json_encode(['success' => false, 'message' => 'No token found for that email.']);
                exit;
            }
            $newStatus = buildTokenStatus($row);
        }

        // Audit log
        try {
            $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address, user_agent, created_at) VALUES (?, 'Token Action', ?, ?, ?, NOW())");
            $details = ($action === 'delete' ? "Deleted access token for $email" : "Turned off $label for $email");
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            $log->bind_param("isss", $_SESSION['user_id'], $details, $ip, $ua);
            $log->execute();
            $log->close();
        } catch (Exception $e) {
            error_log("Token action audit log error: " . $e->getMessage());
        }

        echo json_encode([
            'success' => true,
            'message' => 'Action completed.',
            'action' => $action,
            'email' => $email,
            'status' => $newStatus
        ]);
        exit;
    }

    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Email address is required.']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
        exit;
    }

    // Create (or rotate) the access tokens for this email. No user account is
    // created here - the recipient registers later by opening the emailed link.
    $tokens = create_user_login_tokens($email);
    if (!$tokens) {
        echo json_encode(['success' => false, 'message' => 'Failed to create token. Please try again.']);
        exit;
    }

    // Build the magic login URL carrying the login token
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    $loginUrl = $scheme . '://' . $host . dirname(dirname($scriptDir)) . '/login.php?login_token=' . $tokens['login_token'];

    // Send the email with the login link
    $emailSent = false;
    $emailMessage = '';
    try {
        $response = send_login_link_email($email, $loginUrl);
        $emailSent = !empty($response) && !isset($response['errors']);
        $emailMessage = $emailSent ? 'Access link emailed to the user.' : 'Token created, but the notification email could not be sent.';
    } catch (Exception $e) {
        error_log("Registration link email error: " . $e->getMessage());
        $emailMessage = 'Token created, but the notification email could not be sent.';
    }

    echo json_encode([
        'success' => true,
        'message' => 'Token generated successfully. ' . $emailMessage,
        'email' => $email,
        'login_url' => $loginUrl,
        'status' => buildTokenStatus(['login_token_active' => 1, 'register_token_used_at' => null, 'register_token_expires_at' => date('Y-m-d H:i:s', strtotime('+1 day'))])
    ]);
    exit;
}

// List of generated tokens (GET only). Only the email and derived status are
// fetched - the actual token values are never selected or displayed.
$tokensList = [];
try {
    $res = $conn->query("SELECT email, login_token_active, register_token_used_at, register_token_expires_at, created_at FROM user_tokens ORDER BY id DESC LIMIT 200");
    while ($r = $res->fetch_assoc()) {
        $r['status'] = buildTokenStatus($r);
        $tokensList[] = $r;
    }
} catch (Exception $e) {
    error_log("user_tokens listing error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Registration Link - LGU Road Monitoring</title>
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
            width: 100%;
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

        .tokens-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .tokens-table th {
            text-align: left;
            padding: 10px 12px;
            color: #1e3c72;
            font-size: 13px;
            font-weight: 600;
            background: rgba(55, 98, 200, 0.08);
            border-bottom: 2px solid rgba(55, 98, 200, 0.2);
        }

        .tokens-table td {
            padding: 10px 12px;
            color: #1e293b;
            border-bottom: 1px solid rgba(55, 98, 200, 0.08);
        }

        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-badge.ok {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .row-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin: 2px 3px 2px 0;
            padding: 5px 10px;
            border: 1px solid rgba(55, 98, 200, 0.3);
            border-radius: 8px;
            background: #ffffff;
            color: #3762c8;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, color 0.2s;
        }

        .row-btn:hover {
            background: #3762c8;
            color: #ffffff;
        }

        .row-btn-danger {
            border-color: rgba(220, 38, 38, 0.35);
            color: #dc2626;
        }

        .row-btn-danger:hover {
            background: #dc2626;
            color: #ffffff;
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
                    <h1><i class="fas fa-user-shield"></i> Send Registration Link</h1>
                    <p>Email a sign-up link to a new user. A token is generated and sent, but no account is created until the user registers from the link.</p>
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
                        <span>Send Registration Link</span>
                    </h3>
                </div>

                <div id="alertContainer"></div>

                <form id="sendLinkForm" onsubmit="return handleSend(event)">
                    <div class="form-group">
                        <label for="email">Email Address *</label>
                        <input type="email" id="email" name="email" required placeholder="e.g. juan@lgu.gov.ph">
                        <div class="field-hint">A token will be created for this email and a login link will be sent to it. The account is created only when the user registers from the link.</div>
                    </div>

                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fas fa-paper-plane"></i> Send Link
                    </button>
                </form>
            </div>

            <!-- Generated Tokens -->
            <div class="workflow-card">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <i class="fas fa-key"></i>
                        <span>Generated Tokens</span>
                    </h3>
                </div>

                <?php if (empty($tokensList)): ?>
                    <p style="color: #94a3b8; font-size: 14px;">No tokens have been generated yet.</p>
                <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="tokens-table">
                        <thead>
                            <tr>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="tokensTableBody">
                            <?php foreach ($tokensList as $t): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($t['email']); ?></td>
                                <td><span class="status-badge <?php echo (strpos($t['status'], 'disabled') !== false || strpos($t['status'], 'used') !== false || strpos($t['status'], 'expired') !== false) ? 'danger' : 'ok'; ?>"><?php echo htmlspecialchars($t['status']); ?></span></td>
                                <td>
                                    <button type="button" class="row-btn" onclick="rowAction('turn_off_login', '<?php echo htmlspecialchars($t['email'], ENT_QUOTES); ?>')" title="Disable login link for this token"><i class="fas fa-sign-in-alt"></i> Off Login</button>
                                    <button type="button" class="row-btn" onclick="rowAction('turn_off_register', '<?php echo htmlspecialchars($t['email'], ENT_QUOTES); ?>')" title="Disable registration on the login page"><i class="fas fa-user-plus"></i> Off Register</button>
                                    <button type="button" class="row-btn row-btn-danger" onclick="rowAction('delete', '<?php echo htmlspecialchars($t['email'], ENT_QUOTES); ?>')" title="Delete this token"><i class="fas fa-trash"></i> Delete</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
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
                                <h4>Registration Link Sent</h4>
                                <p>${result.message}</p>
                                <p>Link for <strong>${result.email}</strong>:</p>
                                <div class="url-display">
                                    <span id="generatedUrl">${result.login_url}</span>
                                    <button type="button" onclick="copyUrl()">Copy</button>
                                </div>
                            </div>
                        </div>
                    `;
                    document.getElementById('sendLinkForm').reset();
                    addTokenRow(result.email, result.status);
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

        function addTokenRow(email, status) {
            const tbody = document.getElementById('tokensTableBody');
            if (!tbody) return;
            const danger = /disabled|used|expired/.test(status);
            const cls = danger ? 'danger' : 'ok';
            const tr = document.createElement('tr');
            const emailTd = document.createElement('td');
            emailTd.textContent = email;
            const statusTd = document.createElement('td');
            const badge = document.createElement('span');
            badge.className = 'status-badge ' + cls;
            badge.textContent = status;
            statusTd.appendChild(badge);
            const actionsTd = document.createElement('td');
            actionsTd.innerHTML =
                '<button type="button" class="row-btn" onclick="rowAction(\'turn_off_login\', ' + JSON.stringify(email) + ')" title="Disable login link for this token"><i class="fas fa-sign-in-alt"></i> Off Login</button>' +
                '<button type="button" class="row-btn" onclick="rowAction(\'turn_off_register\', ' + JSON.stringify(email) + ')" title="Disable registration on the login page"><i class="fas fa-user-plus"></i> Off Register</button>' +
                '<button type="button" class="row-btn row-btn-danger" onclick="rowAction(\'delete\', ' + JSON.stringify(email) + ')" title="Delete this token"><i class="fas fa-trash"></i> Delete</button>';
            tr.appendChild(emailTd);
            tr.appendChild(statusTd);
            tr.appendChild(actionsTd);
            tbody.insertBefore(tr, tbody.firstChild);
        }

        function rowAction(action, email) {
            if (action === 'delete' && !confirm('Delete this token for ' + email + '?')) {
                return;
            }
            const formData = new FormData();
            formData.append('action', action);
            formData.append('email', email);
            fetch('', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        window.location.reload();
                    } else {
                        alert(res.message || 'Action failed.');
                    }
                })
                .catch(() => alert('An error occurred. Please try again.'));
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