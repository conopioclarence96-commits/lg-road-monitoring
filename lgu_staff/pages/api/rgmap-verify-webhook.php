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

        // Engineer and budget_allocation are only relevant for Road reports
        // (report_category = 'road'). Transportation reports must not receive
        // these fields — the WHERE clause on report_category enforces that.
        $pdo = rgmap_verification_pdo();
        rgmap_ensure_cimm_verification_table($pdo);

        // Look up engineer and budget that CIMM stored in cimm_verification_reports
        // for this LGU report (matched via the rgmap_report_pk that CIMM echoes
        // back in payload_json).
        // NOTE: the column is named `budget`, not `budget_allocation` — selecting
        // budget_allocation raised "Unknown column" on every callback, turning it
        // into a generic 500 that silently broke all verify callbacks.
        $engineer = null;
        $budgetAllocation = null;
        $cimmLookup = $pdo->prepare(
            "SELECT engineer, budget
               FROM cimm_verification_reports
              WHERE JSON_EXTRACT(payload_json, '$.rgmap_report_pk') = ?
              LIMIT 1"
        );
        $cimmLookup->execute([$reportPk]);
        $cimmRow = $cimmLookup->fetch(PDO::FETCH_ASSOC);
        if (is_array($cimmRow)) {
            $engineer = $cimmRow['engineer'] ?? null;
            $budgetAllocation = $cimmRow['budget'] ?? null;
        }

        if ($reportPk > 0) {
            $stmt = $conn->prepare(
                "UPDATE road_transportation_reports
                 SET cimm_sync_status = 'verified', cimm_verified_at = NOW(), cimm_verified_by = ?,
                     engineer = ?, budget_allocation = ?
                 WHERE id = ? AND report_category = 'road'"
            );
            $stmt->bind_param('sssi', $verifiedBy, $engineer, $budgetAllocation, $reportPk);
        } else {
            // Look up engineer/budget via report_reference (the LGU report_id
            // CIMM echoes back in payload_json) for the string-ID path.
            // Column is `budget`, not `budget_allocation` (same fix as above).
            $cimmLookup2 = $pdo->prepare(
                "SELECT engineer, budget
                   FROM cimm_verification_reports
                  WHERE report_reference = ? OR JSON_EXTRACT(payload_json, '$.rgmap_report_id') = ?
                  LIMIT 1"
            );
            $cimmLookup2->execute([$reportIdStr, $reportIdStr]);
            $cimmRow2 = $cimmLookup2->fetch(PDO::FETCH_ASSOC);
            // fetch() returns false when there's no match; guard against indexing
            // a non-array which would emit a warning into the JSON response body.
            $engineer2 = is_array($cimmRow2) ? ($cimmRow2['engineer'] ?? null) : null;
            $budgetAllocation2 = is_array($cimmRow2) ? ($cimmRow2['budget'] ?? null) : null;

            $stmt = $conn->prepare(
                "UPDATE road_transportation_reports
                 SET cimm_sync_status = 'verified', cimm_verified_at = NOW(), cimm_verified_by = ?,
                     engineer = ?, budget_allocation = ?
                 WHERE report_id = ? AND report_category = 'road'"
            );
            // 'a' is not a valid mysqli bind type — report_id is VARCHAR so 's'.
            $stmt->bind_param('ssss', $verifiedBy, $engineer2, $budgetAllocation2, $reportIdStr);
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
