<?php
/**
 * JSON feed for the Infrastructure Projects panel on verification_monitoring.php.
 * Returns the same filtered/mapped rows the page renders server-side so the
 * Sync button can rebuild the table without a full page reload.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/session_config.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/ipms_road_projects_data.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $reports = rgmap_infra_panel_rows();
    echo json_encode([
        'success' => true,
        'reports' => $reports,
        'count'   => count($reports),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('IPMS infra panel data error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to load infrastructure projects']);
}
