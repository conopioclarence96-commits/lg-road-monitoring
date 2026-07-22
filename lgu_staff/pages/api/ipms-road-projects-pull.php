<?php
/**
 * IPMS road projects poller — pulls the live "upcoming/ongoing road
 * projects" feed from IPMS so the public dashboard can show citizens which
 * roads are about to be, or currently being, worked on.
 *
 * This mirrors the shape of cimm-reports-pull.php (same folder): a small
 * cron-friendly script that authenticates outbound to a partner system,
 * fetches JSON, and syncs it into our own DB. Unlike the CIMM pull, there is
 * no "replay into a webhook" step — this data is cached directly via
 * ipms_road_projects_data.php, since IPMS has no inbound push side and this
 * feed is read-only for us (we never write back to IPMS).
 *
 * Run on a cron (hourly is plenty — IPMS says this data doesn't change
 * minute to minute):
 *
 *   curl -s "http://localhost/<road-monitor-folder>/lgu_staff/pages/api/ipms-road-projects-pull.php?key=IPMS_RGMAP_PULL_KEY_2026"
 *
 * Env config (set real values in .env before this can do anything — see
 * .env.example):
 *   IPMS_BASE_URL           — IPMS's deployed base URL (no trailing slash
 *                             needed). Still localhost on the IPMS side as
 *                             of this writing, so this only resolves once
 *                             IPMS is deployed somewhere this app can reach.
 *   ROAD_MONITORING_API_KEY — shared secret IPMS's feed requires via
 *                             X-API-Key. Must be the exact same string as
 *                             ROAD_MONITORING_API_KEY in IPMS's own .env —
 *                             get the real value from the IPMS teammate,
 *                             never invent one.
 *   IPMS_PULL_KEY            — (optional) key this endpoint itself requires
 *                             to be called, so the pull can't be triggered
 *                             by just anyone hammering IPMS on our behalf.
 *                             Defaults to a shared placeholder like CIMM's
 *                             pull script does, for local dev out of the box.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/ipms_road_projects_data.php';

header('Content-Type: application/json; charset=utf-8');

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

/**
 * Read a config value from .env (same convention as TOMTOM_API_KEY /
 * BREVO_* in config.php / functions.php) falling back to a real process
 * env var of the same name.
 */
function rgmap_ipms_env(string $key): string {
    static $envVariables = null;
    if ($envVariables === null) {
        $envFile = __DIR__ . '/../../../.env';
        $envVariables = file_exists($envFile) ? (parse_ini_file($envFile) ?: []) : [];
    }
    $value = $envVariables[$key] ?? getenv($key);
    return $value !== false && $value !== null ? trim((string)$value) : '';
}

$PULL_KEY = rgmap_ipms_env('IPMS_PULL_KEY') ?: 'IPMS_RGMAP_PULL_KEY_2026';
$provided = trim((string)($_GET['key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? ''));
if ($provided === '' || !hash_equals($PULL_KEY, $provided)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$IPMS_BASE_URL = rtrim(rgmap_ipms_env('IPMS_BASE_URL'), '/');
$API_KEY = rgmap_ipms_env('ROAD_MONITORING_API_KEY');

if ($IPMS_BASE_URL === '' || $API_KEY === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'IPMS_BASE_URL and/or ROAD_MONITORING_API_KEY are not configured. '
            . 'Set both in .env (coordinate the real values with the IPMS teammate).',
    ]);
    exit;
}

$url = $IPMS_BASE_URL . '/integrations/road-monitoring/upcoming-roads-feed.php';
$hostOnly = parse_url($IPMS_BASE_URL, PHP_URL_HOST) ?: 'localhost';
$isLocal = in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => !$isLocal,
    CURLOPT_SSL_VERIFYHOST => $isLocal ? 0 : 2,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'X-API-Key: ' . $API_KEY,
    ],
]);
$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr !== '') {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'IPMS fetch failed', 'error' => $curlErr, 'url' => $url]);
    exit;
}

if ($httpCode === 401) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'IPMS rejected our API key (401). Confirm ROAD_MONITORING_API_KEY matches IPMS\'s own .env exactly.',
    ]);
    exit;
}

$decoded = is_string($response) ? json_decode($response, true) : null;
if ($httpCode < 200 || $httpCode >= 300 || !is_array($decoded) || empty($decoded['success']) || !isset($decoded['roads']) || !is_array($decoded['roads'])) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'IPMS feed returned an unexpected response',
        'http_code' => $httpCode,
        'body' => substr((string)$response, 0, 500),
    ]);
    exit;
}

$roads = $decoded['roads'];

try {
    $pdo = rgmap_ipms_pdo();
    rgmap_ensure_ipms_road_projects_table($pdo);

    $synced = 0;
    $failed = 0;
    $errors = [];
    $seenIds = [];

    foreach ($roads as $road) {
        if (!is_array($road)) {
            continue;
        }
        $projectId = (int)($road['project_id'] ?? 0);
        if ($projectId <= 0) {
            $failed++;
            $errors[] = ['project_id' => $road['project_id'] ?? null, 'reason' => 'missing/invalid project_id'];
            continue;
        }
        if (rgmap_upsert_ipms_road_project($pdo, $road)) {
            $synced++;
            $seenIds[] = $projectId;
        } else {
            $failed++;
            $errors[] = ['project_id' => $projectId, 'reason' => 'upsert failed'];
        }
    }

    // Reconcile: IPMS's feed is always the current live "upcoming" scope, so
    // anything cached here but absent from this pull (e.g. a project moved
    // to completed/cancelled) should drop off the dashboard too.
    $pruned = rgmap_prune_ipms_road_projects($pdo, $seenIds);

    echo json_encode([
        'success' => $failed === 0,
        'message' => 'IPMS road projects pull completed',
        'source_url' => $url,
        'fetched' => count($roads),
        'synced' => $synced,
        'failed' => $failed,
        'pruned' => $pruned,
        'errors' => $errors,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('IPMS road projects pull error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error syncing IPMS road projects']);
}
