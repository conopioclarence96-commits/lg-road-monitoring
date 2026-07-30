<?php
/**
 * RGMAO inbound webhook — receives the "verified" callback from CIMM when
 * staff verify a road_transportation_reports row on CIMM's
 * pending_reports.php "Road Monitoring Reports" panel.
 *
 * This is the return leg of the RGMAO -> CIMM push in rgmap_cimm_sync.php
 * (which pushes newly-submitted reports to CIMM's rgmap-report-webhook.php).
 *
 * Auth: Authorization: Bearer <CIMM_RGMAP_WEBHOOK_KEY> (or header X-API-Key)
 * — same shared secret as the rest of the CIMM<->RGMAO integration.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/rgmap_cimm_sync.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Apache + mod_php doesn't always forward Authorization into $_SERVER (see
// lgu_staff/pages/api/.htaccess for the rewrite fix, and the same fallback
// used in cimm-reports-webhook.php) — check getallheaders() too.
function rgmap_verify_webhook_header(string $name): string {
    $server_key = 'HTTP_' . str_replace('-', '_', strtoupper($name));
    if (!empty($_SERVER[$server_key])) {
        return (string)$_SERVER[$server_key];
    }
    $all = [];
    if (function_exists('getallheaders')) {
        $all = getallheaders() ?: [];
    } elseif (function_exists('apache_request_headers')) {
        $all = apache_request_headers() ?: [];
    }
    foreach ($all as $k => $v) {
        if (strcasecmp($k, $name) === 0) {
            return (string)$v;
        }
    }
    return '';
}

$WEBHOOK_KEY = getenv('CIMM_RGMAP_WEBHOOK_KEY') ?: 'CIMM_RGMAP_SHARED_KEY_2026';

$auth = rgmap_verify_webhook_header('Authorization');
$authorized = false;
if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m) && hash_equals($WEBHOOK_KEY, $m[1])) {
    $authorized = true;
} else {
    $alt = rgmap_verify_webhook_header('X-API-Key');
    if ($alt !== '' && hash_equals($WEBHOOK_KEY, $alt)) {
        $authorized = true;
    }
}
if (!$authorized) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$raw = file_get_contents('php://input');
$data = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}

$reportPk = (int)($data['rgmap_report_pk'] ?? 0);
$reportIdStr = trim((string)($data['rgmap_report_id'] ?? ''));
if ($reportPk <= 0 && $reportIdStr === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'rgmap_report_pk or rgmap_report_id is required']);
    exit;
}

$verifiedBy = trim((string)($data['verified_by'] ?? 'CIMM staff'));

try {
    rgmap_cimm_ensure_schema($conn);

    if ($reportPk > 0) {
        $stmt = $conn->prepare(
            "UPDATE road_transportation_reports
             SET cimm_sync_status = 'verified', cimm_verified_at = NOW(), cimm_verified_by = ?
             WHERE id = ?"
        );
        $stmt->bind_param('si', $verifiedBy, $reportPk);
    } else {
        $stmt = $conn->prepare(
            "UPDATE road_transportation_reports
             SET cimm_sync_status = 'verified', cimm_verified_at = NOW(), cimm_verified_by = ?
             WHERE report_id = ?"
        );
        $stmt->bind_param('ss', $verifiedBy, $reportIdStr);
    }

    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'DB prepare error: ' . $conn->error]);
        exit;
    }

    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected < 1) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Report not found']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Marked verified in RGMAO']);
} catch (\Throwable $e) {
    error_log('RGMAO verify webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
