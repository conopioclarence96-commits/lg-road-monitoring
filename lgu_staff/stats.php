<?php
/**
 * Read-only headline metric for the Main LGU SSO hub dashboard.
 * Auth: Authorization: Bearer <SSO_SHARED_SECRET> (same secret used for SSO).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/sso_config.php';

header('Content-Type: application/json');

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? '') : '');
$token = preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m) ? $m[1] : '';

if (!hash_equals(SSO_SHARED_SECRET, $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$count = 0;
$result = $conn->query('SELECT COUNT(*) AS c FROM road_maintenance_reports');
if ($result) {
    $count = (int) $result->fetch_assoc()['c'];
}

echo json_encode(['count' => $count, 'label' => 'Maintenance Reports']);
