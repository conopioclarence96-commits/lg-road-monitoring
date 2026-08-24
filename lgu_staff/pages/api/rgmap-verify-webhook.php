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
// rgmap_verification_pdo() / rgmap_ensure_cimm_verification_table() live here,
// NOT in rgmap_cimm_sync.php. Without this the handler dies with
// "Call to undefined function rgmap_verification_pdo()", which the catch-all
// turns into a generic 500 — so every verify callback from CIMM fails and
// road_transportation_reports never gets marked verified on this side.
require_once __DIR__ . '/cimm_verification_data.php';

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

    // Resolve the exact original report CIMM verified — never by "latest" row
    // or an unrelated transportation id in the same table.
    $target = rgmap_cimm_resolve_target_report($conn, $data);
    if ($target === null) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Report not found or rgmap_report_pk/rgmap_report_id mismatch',
            'rgmap_report_pk' => $reportPk,
            'rgmap_report_id' => $reportIdStr,
        ]);
        exit;
    }

    $targetId = (int)$target['id'];

    // Prefer ENGR/Budget from the callback body when CIMM sends them; otherwise
    // look up the mirrored CIMM verification row for this exact PK only.
    $engineer = null;
    $budgetAllocation = null;
    if (array_key_exists('engineer', $data) && $data['engineer'] !== null && trim((string)$data['engineer']) !== '') {
        $engineer = trim((string)$data['engineer']);
    }
    if (array_key_exists('budget', $data) && $data['budget'] !== null && $data['budget'] !== '') {
        $budgetAllocation = (float)$data['budget'];
    } elseif (array_key_exists('budget_allocation', $data) && $data['budget_allocation'] !== null && $data['budget_allocation'] !== '') {
        $budgetAllocation = (float)$data['budget_allocation'];
    }

    if ($engineer === null || $budgetAllocation === null) {
        $pdo = rgmap_verification_pdo();
        rgmap_ensure_cimm_verification_table($pdo);
        // Match on exact JSON number for this PK — never LIMIT 1 without PK.
        $cimmLookup = $pdo->prepare(
            "SELECT engineer, budget
               FROM cimm_verification_reports
              WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.rgmap_report_pk')) AS UNSIGNED) = ?
              LIMIT 1"
        );
        $cimmLookup->execute([$targetId]);
        $cimmRow = $cimmLookup->fetch(PDO::FETCH_ASSOC);
        if (is_array($cimmRow)) {
            if ($engineer === null) {
                $engineer = $cimmRow['engineer'] ?? null;
            }
            if ($budgetAllocation === null && isset($cimmRow['budget']) && $cimmRow['budget'] !== null && $cimmRow['budget'] !== '') {
                $budgetAllocation = (float)$cimmRow['budget'];
            }
        }
    }

    // Mark verified on the exact resolved row. ENGR/Budget only when this is
    // the Road report CIMM processed — transportation rows stay untouched.
    if (($target['report_category'] ?? '') === 'road') {
        $stmt = $conn->prepare(
            "UPDATE road_transportation_reports
             SET cimm_sync_status = 'verified', cimm_verified_at = NOW(), cimm_verified_by = ?,
                 engineer = ?, budget_allocation = ?,
                 cimm_engineer_name = COALESCE(?, cimm_engineer_name),
                 cimm_budget = COALESCE(?, cimm_budget)
             WHERE id = ?"
        );
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'DB prepare error: ' . $conn->error]);
            exit;
        }
        $budgetStr = $budgetAllocation !== null ? (string)$budgetAllocation : null;
        $stmt->bind_param('sssssi', $verifiedBy, $engineer, $budgetStr, $engineer, $budgetStr, $targetId);
    } else {
        // Do not write ENGR/Budget onto transportation — only acknowledge
        // verify status so we don't leave a stuck "pushed" badge.
        $stmt = $conn->prepare(
            "UPDATE road_transportation_reports
             SET cimm_sync_status = 'verified', cimm_verified_at = NOW(), cimm_verified_by = ?
             WHERE id = ?"
        );
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'DB prepare error: ' . $conn->error]);
            exit;
        }
        $stmt->bind_param('si', $verifiedBy, $targetId);
    }

    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected < 1) {
        // Row existed but values were identical — still treat as success.
        $check = $conn->prepare('SELECT id FROM road_transportation_reports WHERE id = ? LIMIT 1');
        $check->bind_param('i', $targetId);
        $check->execute();
        $exists = (bool)$check->get_result()->fetch_assoc();
        $check->close();
        if (!$exists) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Report not found']);
            exit;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Marked verified in RGMAO',
        'id' => $targetId,
        'rgmap_report_id' => $target['report_id'],
        'report_category' => $target['report_category'],
    ]);
} catch (\Throwable $e) {
    error_log('RGMAO verify webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
