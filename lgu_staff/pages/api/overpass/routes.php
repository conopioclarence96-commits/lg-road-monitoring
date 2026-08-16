<?php
/**
 * OSM Overpass proxy for Quezon City bus/jeepney route relations.
 * Simplifies geometry and file-caches for 1 hour to avoid huge payloads / rate limits.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    json_error('Unauthorized', 401);
}

// Release session lock immediately so other pages/API calls for this user
// are not blocked while we wait on Overpass or stream a large cache file.
session_write_close();

header('Content-Type: application/json');
header('Cache-Control: private, max-age=3600');

$cacheDir = __DIR__ . '/cache';
$cacheFile = $cacheDir . '/qc_bus_jeep_routes.json';
$cacheTtl = 3600; // 1 hour
$force = isset($_GET['refresh']) && $_GET['refresh'] === '1';

if (!$force && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
    $cached = file_get_contents($cacheFile);
    if ($cached !== false && $cached !== '') {
        echo $cached;
        exit;
    }
}

$query = <<<'QL'
[out:json][timeout:90];
(
  relation["type"="route"]["route"~"^(bus|jeepney)$"](14.58,120.99,14.76,121.14);
);
out geom;
QL;

$endpoints = [
    'https://overpass-api.de/api/interpreter',
    'https://overpass.kumi.systems/api/interpreter',
];

function overpass_classify_route(array $tags): string {
    $route = strtolower((string)($tags['route'] ?? ''));
    $network = strtolower((string)($tags['network'] ?? ''));
    $name = strtolower((string)($tags['name'] ?? ''));
    if ($route === 'jeepney') {
        return 'jeep';
    }
    if (str_contains($network, 'puj') || str_contains($network, 'jeepney')) {
        return 'jeep';
    }
    if (str_contains($name, 'jeepney') || preg_match('/\bpuj\b/', $name)) {
        return 'jeep';
    }
    return 'bus';
}

function overpass_simplify_line(array $latLngs, int $step = 3): array {
    $n = count($latLngs);
    if ($n <= 2) {
        return $latLngs;
    }
    $out = [$latLngs[0]];
    for ($i = $step; $i < $n - 1; $i += $step) {
        $out[] = $latLngs[$i];
    }
    $last = $latLngs[$n - 1];
    $prev = $out[count($out) - 1];
    if ($prev[0] !== $last[0] || $prev[1] !== $last[1]) {
        $out[] = $last;
    }
    return $out;
}

function overpass_fetch(string $url, string $query): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['data' => $query]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 100,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: lg-road-monitoring/1.0 (Quezon City LGU road monitoring)',
        ],
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err || $code < 200 || $code >= 300 || !$raw) {
        return null;
    }
    $json = json_decode($raw, true);
    if (!is_array($json) || !isset($json['elements']) || !is_array($json['elements'])) {
        return null;
    }
    return $json;
}

function overpass_simplify_payload(array $data): array {
    $routes = [];
    foreach ($data['elements'] as $el) {
        if (($el['type'] ?? '') !== 'relation') {
            continue;
        }
        $tags = $el['tags'] ?? [];
        if (!is_array($tags)) {
            $tags = [];
        }
        $lines = [];
        foreach ($el['members'] ?? [] as $member) {
            if (($member['type'] ?? '') !== 'way') {
                continue;
            }
            $role = (string)($member['role'] ?? '');
            if ($role === 'stop' || $role === 'platform' || $role === 'stop_entry_only' || $role === 'stop_exit_only') {
                continue;
            }
            $geom = $member['geometry'] ?? null;
            if (!is_array($geom) || count($geom) < 2) {
                continue;
            }
            $latLngs = [];
            foreach ($geom as $pt) {
                if (!isset($pt['lat'], $pt['lon'])) {
                    continue;
                }
                $latLngs[] = [round((float)$pt['lat'], 5), round((float)$pt['lon'], 5)];
            }
            $latLngs = overpass_simplify_line($latLngs, 5);
            if (count($latLngs) >= 2) {
                $lines[] = $latLngs;
            }
        }
        if (!$lines) {
            continue;
        }
        $kind = overpass_classify_route($tags);
        $routes[] = [
            'id' => (int)($el['id'] ?? 0),
            'kind' => $kind,
            'name' => (string)($tags['name'] ?? $tags['ref'] ?? ('Route ' . ($el['id'] ?? ''))),
            'ref' => (string)($tags['ref'] ?? ''),
            'network' => (string)($tags['network'] ?? ''),
            'from' => (string)($tags['from'] ?? ''),
            'to' => (string)($tags['to'] ?? ''),
            'lines' => $lines,
        ];
    }
    return $routes;
}

$payload = null;
$lastError = 'Overpass request failed';
foreach ($endpoints as $endpoint) {
    $fetched = overpass_fetch($endpoint, $query);
    if ($fetched) {
        $routes = overpass_simplify_payload($fetched);
        $payload = [
            'success' => true,
            'data' => [
                'routes' => $routes,
                'fetchedAt' => (int)round(microtime(true) * 1000),
                'source' => 'openstreetmap',
                'count' => count($routes),
            ],
        ];
        break;
    }
    $lastError = 'Overpass endpoint unavailable: ' . $endpoint;
}

if (!$payload) {
    // Serve stale cache if available
    if (is_file($cacheFile)) {
        $cached = file_get_contents($cacheFile);
        if ($cached !== false && $cached !== '') {
            echo $cached;
            exit;
        }
    }
    json_error($lastError, 502);
}

$encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
if ($encoded === false) {
    json_error('Failed to encode route data', 500);
}

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}
@file_put_contents($cacheFile, $encoded);

echo $encoded;
