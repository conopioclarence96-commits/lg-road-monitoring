<?php
declare(strict_types=1);

header('Content-Type: application/json');

function diag_mask(string $s): string {
    $len = strlen($s);
    if ($len === 0) return '(empty)';
    if ($len <= 6) return str_repeat('*', $len) . " (len $len)";
    return substr($s, 0, 3) . str_repeat('*', $len - 6) . substr($s, -3) . " (len $len)";
}

$webhookKey = getenv('CIMM_RGMAP_WEBHOOK_KEY') ?: 'CIMM_RGMAP_SHARED_KEY_2026';
$apiKey = getenv('CIMM_RGMAP_API_KEY') ?: 'CIMM_RGMAP_SHARED_KEY_2026';

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$hostOnly = explode(':', $host)[0];
$isLocal = in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true);
$defaultExportUrl = $isLocal
    ? (($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . $host . '/LGU/lgu-portal/public/api/cimm-reports-export.php')
    : 'https://cimm.infragovservices.com/lgu-portal/public/api/cimm-reports-export.php';
$exportUrl = getenv('CIMM_REPORTS_EXPORT_URL') ?: $defaultExportUrl;

$out = [
    'this_side' => 'RGMAP',
    'webhook_key_source' => getenv('CIMM_RGMAP_WEBHOOK_KEY') !== false ? 'env override' : 'default',
    'webhook_key_masked' => diag_mask($webhookKey),
    'api_key_source' => getenv('CIMM_RGMAP_API_KEY') !== false ? 'env override' : 'default',
    'api_key_masked' => diag_mask($apiKey),
    'configured_export_url' => $exportUrl,
    'curl_extension_loaded' => extension_loaded('curl'),
];

$ch = curl_init($exportUrl . '?key=' . rawurlencode($apiKey));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$resp = curl_exec($ch);
$out['connectivity_test'] = [
    'target' => $exportUrl,
    'http_code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
    'curl_error' => curl_error($ch) ?: null,
    'response_snippet' => substr((string)$resp, 0, 200),
];
curl_close($ch);

echo json_encode($out, JSON_PRETTY_PRINT);
