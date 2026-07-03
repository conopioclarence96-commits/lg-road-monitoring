<?php
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

$notifications = ['reports' => [], 'users' => [], 'counts' => ['reports' => 0, 'users' => 0, 'total' => 0]];

try {
    $rstmt = $conn->prepare("
        SELECT id, report_id, title, department, priority, status, description, location,
               reporter_name, reporter_email, created_at
        FROM road_transportation_reports
        WHERE status = 'pending'
        ORDER BY
            CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 END,
            created_at DESC
        LIMIT 10
    ");
    $rstmt->execute();
    $notifications['reports'] = $rstmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $rstmt->close();

    $cstmt = $conn->prepare("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status = 'pending'");
    $cstmt->execute();
    $notifications['counts']['reports'] = $cstmt->get_result()->fetch_assoc()['count'];
    $cstmt->close();
} catch (Exception $e) {
    error_log("Notifications reports error: " . $e->getMessage());
}

try {
    $ustmt = $conn->prepare("
        SELECT id, username, email, full_name, role, department, created_at
        FROM users
        WHERE account_status = 'pending'
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $ustmt->execute();
    $notifications['users'] = $ustmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ustmt->close();

    $cstmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE account_status = 'pending'");
    $cstmt->execute();
    $notifications['counts']['users'] = $cstmt->get_result()->fetch_assoc()['count'];
    $cstmt->close();
} catch (Exception $e) {
    error_log("Notifications users error: " . $e->getMessage());
}

$notifications['counts']['total'] = $notifications['counts']['reports'] + $notifications['counts']['users'];

$conn->close();

echo json_encode(['success' => true, 'data' => $notifications]);
?>
