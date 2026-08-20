<?php
/**
 * OSM Overpass proxy for Quezon City bus/jeepney route relations.
 * File-caches until Sync / ?refresh=1 (no time TTL).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/routes_lib.php';

if (!isset($_SESSION['user_id'])) {
    json_error('Unauthorized', 401);
}

session_write_close();

header('Content-Type: application/json');
header('Cache-Control: private, max-age=3600');

$force = isset($_GET['refresh']) && $_GET['refresh'] === '1';
$result = rgmap_get_overpass_routes_payload($force);

if (!$result['ok'] || !$result['payload']) {
    json_error($result['error'] ?? 'Overpass request failed', 502);
}

echo json_encode($result['payload'], JSON_UNESCAPED_UNICODE);
