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

require_once __DIR__ . '/../../includes/config.php';
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

// On stock Apache + mod_php (e.g. XAMPP's default config), the Authorization
// header is stripped before it ever reaches $_SERVER unless the host is
// explicitly configured to forward it (no .htaccess rule does this here).
// getallheaders()/apache_request_headers() can still see it in that case, so
// check those too instead of relying on $_SERVER alone — this is what was
// silently turning every CIMM sync into a 401, even though CIMM's requests
// table had the report the whole time.
function rgmap_webhook_header(string $name): string {
    $server_key = 'HTTP_' . str_replace('-', '_', strtoupper($name));
    if (!empty($_SERVER[$server_key])) {
        return (string) $_SERVER[$server_key];
    }
    $all = [];
    if (function_exists('getallheaders')) {
        $all = getallheaders() ?: [];
    } elseif (function_exists('apache_request_headers')) {
        $all = apache_request_headers() ?: [];
    }
    foreach ($all as $k => $v) {
        if (strcasecmp($k, $name) === 0) {
            return (string) $v;
        }
    }
    return '';
}

$auth = rgmap_webhook_header('Authorization');
$authorized = false;
if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m) && hash_equals($WEBHOOK_KEY, $m[1])) {
    $authorized = true;
} else {
    $alt = rgmap_webhook_header('X-API-Key');
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
    $result = rgmap_apply_cimm_report_payload($pdo, isset($conn) && $conn instanceof mysqli ? $conn : null, $data);

    echo json_encode([
        'success' => true,
        'message' => 'Report synced to verification monitoring',
        'id' => $result['id'],
        'cimm_req_id' => $result['cimm_req_id'],
        'reference' => $result['reference'],
    ]);
} catch (\Throwable $e) {
    error_log('RGMAO CIMM webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error storing report']);
}