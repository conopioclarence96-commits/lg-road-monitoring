<?php
require_once '../../includes/session_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$_SESSION['last_activity'] = time();
echo json_encode(['success' => true, 'last_activity' => $_SESSION['last_activity']]);
