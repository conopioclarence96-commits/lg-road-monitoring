<?php
/**
 * RGMAO pull sync — fetch all CIMM reports into verification monitoring.
 *
 * This is a catch-up mechanism, not the primary path. CIMM already pushes
 * reports here automatically via cimm-reports-webhook.php whenever a request
 * is created/validated/rejected. Run this on a cron (every 5-15 min) to
 * backfill anything a push might have missed (e.g. a network blip):
 *
 *   curl -s "http://localhost/<road-monitor-folder>/lgu_staff/api/cimm-reports-pull.php?key=CIMM_RGMAP_SHARED_KEY_2026"
 *
 * Env overrides (set real values before deploying anywhere public):
 *   CIMM_RGMAP_API_KEY       — key this endpoint requires to be called
 *   CIMM_REPORTS_EXPORT_URL  — CIMM's export endpoint (defaults below assume
 *                              CIMM lives in a local XAMPP folder named "LGU",
 *                              matching CIMM's own local-dev auto-detection)
 *   CIMM_RGMAP_WEBHOOK_KEY   — key used when replaying into the local webhook
 *   RGMAP_CIMM_WEBHOOK_URL   — override for this server's own webhook URL
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$API_KEY = getenv('CIMM_RGMAP_API_KEY') ?: 'CIMM_RGMAP_SHARED_KEY_2026';
$provided = trim((string)($_GET['key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? ''));
if ($provided === '' || !hash_equals($API_KEY, $provided)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Default CIMM export URL: assume local XAMPP dev (CIMM repo folder "LGU")
// unless this server itself is clearly a live infragovservices.com host, or
// the env var is set explicitly — matching CIMM's own detection logic.
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$hostOnly = explode(':', $host)[0];
$isLocal = in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true);
$defaultExportUrl = $isLocal
    ? (($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . $host . '/LGU/lgu-portal/public/api/cimm-reports-export.php')
    : 'https://cimm.infragovservices.com/lgu-portal/public/api/cimm-reports-export.php';

$CIMM_EXPORT_URL = getenv('CIMM_REPORTS_EXPORT_URL') ?: $defaultExportUrl;

$since = trim((string)($_GET['since'] ?? ''));
$url = $CIMM_EXPORT_URL . '?key=' . rawurlencode($API_KEY);
if ($since !== '') {
    $url .= '&since=' . rawurlencode($since);
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => !$isLocal,
    CURLOPT_SSL_VERIFYHOST => $isLocal ? 0 : 2,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);
$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr !== '') {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'CIMM fetch failed', 'error' => $curlErr, 'url' => $url]);
    exit;
}

$decoded = is_string($response) ? json_decode($response, true) : null;
if ($httpCode < 200 || $httpCode >= 300 || !is_array($decoded) || empty($decoded['success'])) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'CIMM export returned error',
        'http_code' => $httpCode,
        'body' => substr((string)$response, 0, 500),
    ]);
    exit;
}

$reports = $decoded['reports'] ?? [];
$WEBHOOK_KEY = getenv('CIMM_RGMAP_WEBHOOK_KEY') ?: 'CIMM_RGMAP_SHARED_KEY_2026';
$webhookUrl = getenv('RGMAP_CIMM_WEBHOOK_URL')
    ?: ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
    . '://' . $host . '/lgu_staff/api/cimm-reports-webhook.php';

$synced = 0;
$failed = 0;
$errors = [];

foreach ($reports as $report) {
    if (!is_array($report)) {
        continue;
    }
    $json = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $wh = curl_init($webhookUrl);
    curl_setopt_array($wh, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $WEBHOOK_KEY,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $whResp = curl_exec($wh);
    $whCode = (int)curl_getinfo($wh, CURLINFO_HTTP_CODE);
    curl_close($wh);

    if ($whCode >= 200 && $whCode < 300) {
        $synced++;
    } else {
        $failed++;
        $errors[] = [
            'cimm_req_id' => $report['cimm_req_id'] ?? null,
            'http_code' => $whCode,
            'response' => substr((string)$whResp, 0, 200),
        ];
    }
}

echo json_encode([
    'success' => $failed === 0,
    'message' => 'Pull sync completed',
    'source_url' => $CIMM_EXPORT_URL,
    'fetched' => count($reports),
    'synced' => $synced,
    'failed' => $failed,
    'errors' => $errors,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
