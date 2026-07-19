<?php
/**
 * RGMAO inbound webhook — receives CIMM citizen reports for verification
 * monitoring.
 *
 * CIMM's cimm_rgmap_sync.php calls this automatically whenever a request is
 * created, validated, or rejected (see ipms-requests.php, validate_request.php,
 * reject_request.php, citizenrepform.php in the CIMM repo). cimm-reports-pull.php
 * in this same folder can also replay reports into this endpoint as a
 * catch-up mechanism.
 *
 * Auth: Authorization: Bearer <CIMM_RGMAP_WEBHOOK_KEY>  (or header X-API-Key)
 * Body: JSON payload from CIMM — see cimm_rgmap_fetch_report() in the CIMM repo
 *       for the exact shape.
 *
 * IMPORTANT: the shared key below defaults to the same placeholder CIMM
 * ships with (CIMM_RGMAP_SHARED_KEY_2026), so the two systems talk to each
 * other out of the box in local dev. Override CIMM_RGMAP_WEBHOOK_KEY as a
 * real environment variable on both sides before deploying anywhere public.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/cimm_verification_data.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-CIMM-Event');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$WEBHOOK_KEY = getenv('CIMM_RGMAP_WEBHOOK_KEY') ?: 'CIMM_RGMAP_SHARED_KEY_2026';

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$authorized = false;
if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m) && hash_equals($WEBHOOK_KEY, $m[1])) {
    $authorized = true;
} else {
    $alt = $_SERVER['HTTP_X_API_KEY'] ?? '';
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

$cimmReqId = (int)($data['cimm_req_id'] ?? 0);
if ($cimmReqId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'cimm_req_id is required']);
    exit;
}

try {
    $pdo = rgmap_verification_pdo();
    rgmap_ensure_cimm_verification_table($pdo);

    $reference = (string)($data['reference'] ?? ('REQ-' . str_pad((string)$cimmReqId, 3, '0', STR_PAD_LEFT)));
    $cimmRepId = isset($data['cimm_rep_id']) ? (int)$data['cimm_rep_id'] : null;
    if ($cimmRepId !== null && $cimmRepId <= 0) {
        $cimmRepId = null;
    }

    $evidenceJson = json_encode($data['evidence_urls'] ?? [], JSON_UNESCAPED_SLASHES);
    $aiJson = json_encode($data['ai'] ?? [], JSON_UNESCAPED_SLASHES);
    $payloadJson = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $event = (string)($data['event'] ?? $_SERVER['HTTP_X_CIMM_EVENT'] ?? 'upsert');

    $stmt = $pdo->prepare("
        INSERT INTO cimm_verification_reports (
            cimm_req_id, cimm_rep_id, reference_code, report_reference,
            infrastructure, location, issue, reporter_name, contact_number, email,
            district, coord_lat, coord_lng, cprf_facility_id, cprf_facility_name,
            approval_status, rejection_reason, resolution_status, resolution_note, resolved_at,
            priority, budget, starting_date, estimated_end_date, submitted_at,
            evidence_json, ai_json, portal_url, payload_json, last_event
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            cimm_rep_id = VALUES(cimm_rep_id),
            reference_code = VALUES(reference_code),
            report_reference = VALUES(report_reference),
            infrastructure = VALUES(infrastructure),
            location = VALUES(location),
            issue = VALUES(issue),
            reporter_name = VALUES(reporter_name),
            contact_number = VALUES(contact_number),
            email = VALUES(email),
            district = VALUES(district),
            coord_lat = VALUES(coord_lat),
            coord_lng = VALUES(coord_lng),
            cprf_facility_id = VALUES(cprf_facility_id),
            cprf_facility_name = VALUES(cprf_facility_name),
            approval_status = VALUES(approval_status),
            rejection_reason = VALUES(rejection_reason),
            resolution_status = VALUES(resolution_status),
            resolution_note = VALUES(resolution_note),
            resolved_at = VALUES(resolved_at),
            priority = VALUES(priority),
            budget = VALUES(budget),
            starting_date = VALUES(starting_date),
            estimated_end_date = VALUES(estimated_end_date),
            submitted_at = VALUES(submitted_at),
            evidence_json = VALUES(evidence_json),
            ai_json = VALUES(ai_json),
            portal_url = VALUES(portal_url),
            payload_json = VALUES(payload_json),
            last_event = VALUES(last_event),
            synced_at = CURRENT_TIMESTAMP
    ");

    $stmt->execute([
        $cimmReqId,
        $cimmRepId,
        $reference,
        $data['report_reference'] ?? null,
        (string)($data['infrastructure'] ?? 'Unknown'),
        (string)($data['location'] ?? ''),
        (string)($data['issue'] ?? ''),
        $data['reporter_name'] ?? null,
        (string)($data['contact_number'] ?? ''),
        $data['email'] ?? null,
        $data['district'] ?? null,
        $data['coord_lat'] ?? null,
        $data['coord_lng'] ?? null,
        $data['cprf_facility_id'] ?? null,
        $data['cprf_facility_name'] ?? null,
        (string)($data['approval_status'] ?? 'Pending'),
        $data['rejection_reason'] ?? null,
        $data['resolution_status'] ?? null,
        $data['resolution_note'] ?? null,
        $data['resolved_at'] ?? null,
        $data['priority'] ?? null,
        $data['budget'] ?? null,
        $data['starting_date'] ?? null,
        $data['estimated_end_date'] ?? null,
        (string)($data['submitted_at'] ?? date('Y-m-d H:i:s')),
        $evidenceJson,
        $aiJson,
        $data['portal_url'] ?? null,
        $payloadJson,
        $event,
    ]);

    $localIdStmt = $pdo->prepare('SELECT id FROM cimm_verification_reports WHERE cimm_req_id = ? LIMIT 1');
    $localIdStmt->execute([$cimmReqId]);
    $localId = (int)($localIdStmt->fetchColumn() ?: 0);

    echo json_encode([
        'success' => true,
        'message' => 'Report synced to verification monitoring',
        'id' => $localId,
        'cimm_req_id' => $cimmReqId,
        'reference' => $reference,
    ]);
} catch (\Throwable $e) {
    error_log('RGMAO CIMM webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error storing report']);
}
