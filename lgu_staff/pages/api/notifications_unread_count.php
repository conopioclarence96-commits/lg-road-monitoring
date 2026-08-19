<?php
header('Content-Type: application/json');

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();

require_once '../../includes/functions.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'system_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once '../../includes/config.php';
require_once __DIR__ . '/cimm_verification_data.php';
require_once '../../includes/notification_badge.php';

dispatch_no_update_stale_notifications($conn);

$user_id = (int)$_SESSION['user_id'];
$email = (string)($_SESSION['email'] ?? '');
if ($email === '') {
    try {
        $stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $email = (string)($row['email'] ?? '');
    } catch (Exception $e) {}
}

echo json_encode(['success' => true, 'count' => nc_admin_unread_count($conn, $user_id, $email)]);