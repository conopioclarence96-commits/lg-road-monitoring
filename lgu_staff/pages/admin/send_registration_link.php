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

        .main-content.reglink-dash {
            margin-left: 250px; padding: 24px 28px; max-width: 100%; overflow-x: hidden;
            position: relative; z-index: 1;
        }

        /* Dashboard header */
        .dashboard-header {
            background: #f4f7fb; border-radius: 14px; padding: 20px 26px; margin-bottom: 22px;
            border: 1px solid #d5dce8; box-shadow: var(--shadow-card);
            display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap;
        }
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
        .workflow-card.panel-send::after { background: linear-gradient(180deg, #d97706, #b45309); }
        .workflow-card.panel-tokens::after { background: linear-gradient(180deg, #d97706, #b45309); }
        .workflow-header {
            display: flex; justify-content: space-between; align-items: center;
            margin: -22px -22px 18px; padding: 14px 18px 14px 22px;
            border-bottom: 1px solid var(--border-light);
        }
        .workflow-card.panel-send .workflow-header { background: rgba(217,119,6,0.10); }
        .workflow-card.panel-tokens .workflow-header { background: rgba(217,119,6,0.10); }
        .workflow-title { font-size: 14px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 10px; }
        .workflow-card.panel-send .workflow-title { color: #b45309; }
        .workflow-card.panel-tokens .workflow-title { color: #b45309; }
        .title-icon {
            width: 32px; height: 32px; border-radius: 9px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 13px; flex-shrink: 0; color: #fff;
        }
        .workflow-card.panel-send .title-icon { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .workflow-card.panel-tokens .title-icon { background: linear-gradient(135deg, #f59e0b, #d97706); }

        /* Form */
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 6px; }
        .form-group input {
            width: 100%; padding: 9px 12px; border: 1px solid var(--border-default); border-radius: 8px;
            font-size: 13px; color: var(--text-primary); background: var(--bg-input);
            transition: border-color 0.2s, box-shadow 0.2s; box-sizing: border-box;
        }
        .form-group input:focus {
            outline: none; border-color: #d97706; box-shadow: 0 0 0 3px rgba(217,119,6,0.14); background: #fff;
        }
        .field-hint { font-size: 12px; color: var(--text-muted); margin-top: 5px; }

        .btn { padding: 10px 24px; border: none; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background: linear-gradient(135deg, #f59e0b, #b45309); color: #fff; box-shadow: 0 4px 12px rgba(217,119,6,0.35); }
        .btn-primary:hover { filter: brightness(1.08); transform: translateY(-1px); }
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
        .success-box .content .url-display {
            background: #fff; border: 1px dashed #10b981; border-radius: 6px; padding: 10px 14px; margin-top: 10px;
            font-family: monospace; font-size: 12px; color: #1e3c72; word-break: break-all;
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
        }
        .success-box .content .url-display button { background: none; border: none; color: #3762c8; cursor: pointer; font-size: 13px; font-weight: 500; white-space: nowrap; }
        .success-box .content .url-display button:hover { text-decoration: underline; }
        .error-box {
            background: linear-gradient(135deg, #fee2e2, #fecaca); border: 1px solid #ef4444;
            border-radius: 10px; padding: 16px 20px; margin-bottom: 20px;
            display: flex; align-items: center; gap: 12px;
        }
        .error-box .icon { color: #dc2626; font-size: 20px; }
        .error-box .content p { color: #991b1b; font-size: 14px; }

        /* Tokens table */
        .table-container {
            overflow-x: auto; border: 1px solid var(--border-light);
            border-radius: 12px; background: #f4f7fb;
        }
        .tokens-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .tokens-table th, .tokens-table td { padding: 11px 14px; text-align: left; }
        .tokens-table th {
            background: linear-gradient(135deg, #f59e0b, #b45309); color: #fff;
            font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600;
        }
        .tokens-table td { color: var(--text-primary); border-bottom: 1px solid var(--border-light); }
        .tokens-table tbody tr:hover td { background: var(--bg-hover); }
        .tokens-table tbody tr:last-child td { border-bottom: none; }
        .empty-state { color: var(--text-muted); font-size: 14px; }

        .status-badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .status-badge.ok { background: var(--color-success-bg); color: var(--color-success-text); }
        .status-badge.danger { background: var(--color-danger-bg); color: var(--color-danger-text); }
        body.dark-mode .status-badge.ok { background: var(--color-success-bg) !important; color: var(--color-success) !important; }
        body.dark-mode .status-badge.danger { background: var(--color-danger-bg) !important; color: var(--color-danger) !important; }

        .row-btn {
            display: inline-flex; align-items: center; gap: 6px; margin: 2px 3px 2px 0;
            padding: 6px 12px; border: none; border-radius: 8px;
            background: var(--color-primary-bg); color: var(--color-primary);
            font-size: 12px; font-weight: 600; cursor: pointer;
            transition: all 0.2s; box-shadow: 0 2px 6px rgba(30, 60, 114, 0.12);
        }
        .row-btn i { font-size: 11px; }
        .row-btn:hover { background: linear-gradient(135deg, #3762c8, #1e3c72); color: #fff; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(30, 60, 114, 0.3); }
        .row-btn-warn {
            background: var(--color-warning-bg); color: var(--color-warning-text);
            box-shadow: 0 2px 6px rgba(217, 119, 6, 0.14);
        }
        .row-btn-warn:hover { background: linear-gradient(135deg, #f59e0b, #b45309); color: #fff; box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3); }
        .row-btn-danger {
            background: var(--color-danger-bg); color: var(--color-danger-text);
            box-shadow: 0 2px 6px rgba(220, 38, 38, 0.14);
        }
        .row-btn-danger:hover { background: linear-gradient(135deg, #ef4444, #b91c1c); color: #fff; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3); }

        /* Dark mode */
        body.dark-mode .dashboard-header,
        body.dark-mode .workflow-card,
        body.dark-mode .table-container {
            background: #1c2432 !important; border-color: rgba(147, 179, 224, 0.22) !important;
        }
        body.dark-mode .workflow-header { background: rgba(255,255,255,0.03) !important; border-color: var(--border-default) !important; }
        body.dark-mode .workflow-card.panel-send .workflow-title { color: #fbbf24 !important; }
        body.dark-mode .workflow-card.panel-tokens .workflow-title { color: #fbbf24 !important; }
        body.dark-mode .dt-chip { background: var(--color-primary-bg); border-color: var(--border-default); }
        body.dark-mode .dt-chip i { background: linear-gradient(135deg, #1e3c72, #0f274a); }
        body.dark-mode .welcome-text h1 { color: var(--text-primary); }
        body.dark-mode .welcome-text p,
        body.dark-mode .date-time { color: var(--text-secondary) !important; }
        body.dark-mode .form-group input {
            background: #1c2432 !important; border-color: var(--border-default) !important; color: var(--text-primary) !important;
        }
        body.dark-mode .form-group input:focus {
            border-color: #fbbf24 !important; box-shadow: 0 0 0 3px rgba(251,191,36,0.12) !important; background: #1c2432 !important;
        }
        body.dark-mode .tokens-table th { background: linear-gradient(135deg, #f59e0b, #b45309); }
        body.dark-mode .tokens-table td { color: var(--text-primary); border-bottom-color: var(--border-default); }
        body.dark-mode .row-btn { background: var(--color-primary-bg); color: var(--color-primary); }
        body.dark-mode .row-btn-warn { background: var(--color-warning-bg); color: var(--color-warning-text); }
        body.dark-mode .row-btn-danger { background: var(--color-danger-bg); color: var(--color-danger-text); }
        body.dark-mode .success-box .content .url-display { background: #1c2432; color: var(--text-primary); }

        @media (max-width: 768px) {
            .main-content.reglink-dash { margin-left: 0; padding: 16px; }
            .dashboard-header { flex-direction: column; align-items: flex-start; }
            .welcome-section { flex-direction: column; align-items: flex-start; }
            .date-time { text-align: left; }
        }    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <!-- SIDEBAR -->
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="main-content reglink-dash">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="welcome-text">
                <h1>
                    <span class="header-icon"><i class="fas fa-user-shield"></i></span>
                    Send Registration Link
                </h1>
                <p>Email a sign-up link to a new user. A token is generated and sent, but no account is created until the user registers from the link.</p>
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
            <div class="workflow-card panel-send">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <span class="title-icon"><i class="fas fa-paper-plane"></i></span>
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
            <div class="workflow-card panel-tokens">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <span class="title-icon"><i class="fas fa-key"></i></span>
                        <span>Generated Tokens</span>
                    </h3>
                </div>

                <?php if (empty($tokensList)): ?>
                    <p class="empty-state">No tokens have been generated yet.</p>
                <?php else: ?>
                <div class="table-container">
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
                                    <button type="button" class="row-btn row-btn-warn" onclick="rowAction('turn_off_register', '<?php echo htmlspecialchars($t['email'], ENT_QUOTES); ?>')" title="Disable registration on the login page"><i class="fas fa-user-plus"></i> Off Register</button>
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
                '<button type="button" class="row-btn row-btn-warn" onclick="rowAction(\'turn_off_register\', ' + JSON.stringify(email) + ')" title="Disable registration on the login page"><i class="fas fa-user-plus"></i> Off Register</button>' +
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