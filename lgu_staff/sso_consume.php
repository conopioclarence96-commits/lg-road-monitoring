<?php
/**
 * SSO consumer: accepts a signed token from Main LGU (infragovservices.com hub),
 * establishes a native session using the same keys login.php sets, then hands
 * off into the real admin dashboard. See lgu_staff/login.php:185-191 for the
 * session shape this mirrors.
 */
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', 0);
    session_start();
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/sso_config.php';

function sso_reject(string $message): void
{
    http_response_code(403);
    exit('SSO error: ' . $message);
}

$token = $_GET['sso_token'] ?? '';
$parts = explode('.', $token, 2);
if (count($parts) !== 2) {
    sso_reject('malformed token');
}
[$payloadPart, $signaturePart] = $parts;

$expectedSig = rtrim(strtr(base64_encode(hash_hmac('sha256', $payloadPart, SSO_SHARED_SECRET, true)), '+/', '-_'), '=');
if (!hash_equals($expectedSig, $signaturePart)) {
    sso_reject('invalid signature');
}

$payloadJson = base64_decode(strtr($payloadPart, '-_', '+/'));
$payload = json_decode($payloadJson, true);
if (!is_array($payload)) {
    sso_reject('invalid payload');
}

if (($payload['target'] ?? '') !== 'roadmon') {
    sso_reject('token not issued for this system');
}
if (!isset($payload['exp']) || time() > $payload['exp']) {
    sso_reject('token expired');
}

$nonce = $payload['nonce'] ?? '';
$conn->query("CREATE TABLE IF NOT EXISTS sso_used_tokens (
    nonce VARCHAR(64) PRIMARY KEY,
    used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$nonceStmt = $conn->prepare("INSERT INTO sso_used_tokens (nonce) VALUES (?)");
$nonceStmt->bind_param('s', $nonce);
try {
    $nonceStmt->execute();
} catch (mysqli_sql_exception $e) {
    sso_reject('token already used');
}
$nonceStmt->close();

$email = $payload['email'] ?? '';
$fullName = $payload['full_name'] ?? 'Super Admin';

$userStmt = $conn->prepare("SELECT id, email, full_name, role FROM users WHERE email = ? LIMIT 1");
$userStmt->bind_param('s', $email);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$user) {
    $username = 'sso_' . substr(md5($email), 0, 10);
    $randomPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $insert = $conn->prepare("INSERT INTO users (username, email, password, full_name, role, department, is_active) VALUES (?, ?, ?, ?, 'system_admin', 'System Administration', 1)");
    $insert->bind_param('ssss', $username, $email, $randomPassword, $fullName);
    $insert->execute();
    $newId = $insert->insert_id;
    $insert->close();

    $user = ['id' => $newId, 'email' => $email, 'full_name' => $fullName, 'role' => 'system_admin'];
}

session_regenerate_id(true);
$_SESSION['sso_from_mainlgu'] = true;
$_SESSION['user_id'] = $user['id'];
$_SESSION['email'] = $user['email'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role'] = $user['role'];
$_SESSION['darkmode'] = 0;
$_SESSION['logged_in'] = true;
$_SESSION['login_time'] = time();
$_SESSION['last_activity'] = time();

$loginUpdate = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
$loginUpdate->bind_param('i', $user['id']);
$loginUpdate->execute();
$loginUpdate->close();

header('Location: pages/admin/admin_dashboard.php');
exit;
