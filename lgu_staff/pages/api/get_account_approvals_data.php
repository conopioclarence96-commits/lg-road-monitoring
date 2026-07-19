ch<?php
header('Content-Type: application/json');

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'system_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once '../../includes/config.php';

$response = ['success' => true, 'data' => ['pending_users' => [], 'change_requests' => [], 'stats' => []]];

try {
    $stmt = $conn->prepare("
        SELECT id, username, email, full_name, role, department, address, birthday, civil_status, is_active, created_at, updated_at, approved_at, rejected_at, id_file_path
        FROM users
        WHERE role IN ('lgu_staff', 'citizen') AND account_status = 'pending'
        ORDER BY created_at DESC
    ");
    $stmt->execute();
    $response['data']['pending_users'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    error_log("Account approvals pending users error: " . $e->getMessage());
}

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
    $response['data']['change_requests'] = $cr_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $cr_stmt->close();
} catch (Exception $e) {
    error_log("Account approvals change requests error: " . $e->getMessage());
    $response['data']['change_requests'] = [];
}

try {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE account_status = 'pending'");
    $stmt->execute();
    $response['data']['stats']['pending_users'] = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE account_status = 'verified' AND is_active = 1");
    $stmt->execute();
    $response['data']['stats']['approved_users'] = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE account_status = 'deactivated'");
    $stmt->execute();
    $response['data']['stats']['deactivated_users'] = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();

    $response['data']['stats']['change_requests'] = count($response['data']['change_requests']);
} catch (Exception $e) {
    $response['data']['stats'] = ['pending_users' => 0, 'approved_users' => 0, 'deactivated_users' => 0, 'change_requests' => 0];
}

$conn->close();
echo json_encode($response);
?>
