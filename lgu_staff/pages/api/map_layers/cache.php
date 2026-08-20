<?php
/**
 * Server file cache for map layers: incidents, bus, rail, PT routes.
 * Serves JSON from disk when present; fetches upstream only if missing (or refresh/sync).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/tomtom/autoload.php';
require_once __DIR__ . '/../overpass/routes_lib.php';

if (!isset($_SESSION['user_id'])) {
    json_error('Unauthorized', 401);
}

session_write_close();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');

$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

$QC_CENTER = [14.6760, 121.0437];
$TRANSIT_POI_CENTERS = [
    [14.651417, 121.04917],
    [14.705, 121.05],
    [14.60, 121.05],
    [14.65, 121.015],
    [14.65, 121.09],
    [14.68, 121.075],
    [14.62, 121.03],
];

function map_layers_cache_path(string $layer): string {
    global $cacheDir;
    $files = [
        'incidents' => 'qc_incidents.json',
        'bus' => 'qc_bus_stops.json',
        'rail' => 'qc_rail_stations.json',
        'routes' => null, // uses overpass cache path
    ];
    if ($layer === 'routes') {
        return rgmap_overpass_routes_cache_file();
    }
    if (!isset($files[$layer]) || $files[$layer] === null) {
        return '';
    }
    return $cacheDir . '/' . $files[$layer];
}

function map_layers_read_cache(string $path): ?array {
    if ($path === '' || !is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function map_layers_write_cache(string $path, array $payload): bool {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        return false;
    }
    return @file_put_contents($path, $encoded) !== false;
}

function map_layers_collect_incidents($payload): array {
    if (!$payload) {
        return [];
    }
    if (is_array($payload) && isset($payload['incidents']) && is_array($payload['incidents'])) {
        return $payload['incidents'];
    }
    if (is_array($payload) && isset($payload['tm']['poi']) && is_array($payload['tm']['poi'])) {
        return $payload['tm']['poi'];
    }
    if (is_array($payload) && isset($payload['data'])) {
        return map_layers_collect_incidents($payload['data']);
    }
    return [];
}

/**
 * @return array{ok:bool,payload:?array,error:?string,from_cache:bool}
 */
function map_layers_fetch_incidents(bool $force): array {
    $path = map_layers_cache_path('incidents');
    if (!$force) {
        $cached = map_layers_read_cache($path);
        if ($cached && !empty($cached['success'])) {
            return ['ok' => true, 'payload' => $cached, 'error' => null, 'from_cache' => true];
        }
    }

    global $QC_CENTER;
    try {
        $traffic = new TrafficService();
        $resp = $traffic->trafficIncidentDetails($QC_CENTER[0], $QC_CENTER[1], 15);
        if (empty($resp['success'])) {
            $cached = map_layers_read_cache($path);
            if ($cached && !empty($cached['success'])) {
                return ['ok' => true, 'payload' => $cached, 'error' => null, 'from_cache' => true];
            }
            return ['ok' => false, 'payload' => null, 'error' => $resp['error'] ?? 'TomTom incidents failed', 'from_cache' => false];
        }
        $items = map_layers_collect_incidents($resp['data'] ?? $resp);
        $payload = [
            'success' => true,
            'data' => [
                'items' => $items,
                'fetchedAt' => (int)round(microtime(true) * 1000),
                'source' => 'tomtom',
                'count' => count($items),
            ],
        ];
        map_layers_write_cache($path, $payload);
        return ['ok' => true, 'payload' => $payload, 'error' => null, 'from_cache' => false];
    } catch (Throwable $e) {
        $cached = map_layers_read_cache($path);
        if ($cached && !empty($cached['success'])) {
            return ['ok' => true, 'payload' => $cached, 'error' => null, 'from_cache' => true];
        }
        return ['ok' => false, 'payload' => null, 'error' => $e->getMessage(), 'from_cache' => false];
    }
}

/**
 * @return array{ok:bool,payload:?array,error:?string,from_cache:bool}
 */
function map_layers_fetch_transit(string $layer, string $categorySet, bool $force): array {
    $path = map_layers_cache_path($layer);
    if (!$force) {
        $cached = map_layers_read_cache($path);
        if ($cached && !empty($cached['success'])) {
            return ['ok' => true, 'payload' => $cached, 'error' => null, 'from_cache' => true];
        }
    }

    global $TRANSIT_POI_CENTERS;
    try {
        $search = new SearchService();
        $byId = [];
        foreach ($TRANSIT_POI_CENTERS as $c) {
            $resp = $search->nearbySearch((float)$c[0], (float)$c[1], [
                'categorySet' => $categorySet,
                'radius' => 8000,
                'limit' => 100,
            ]);
            if (empty($resp['success'])) {
                continue;
            }
            $payload = $resp['data'] ?? [];
            $results = (isset($payload['results']) && is_array($payload['results'])) ? $payload['results'] : [];
            foreach ($results as $poi) {
                $key = $poi['id'] ?? null;
                if ($key === null && isset($poi['position']['lat'], $poi['position']['lon'])) {
                    $key = $poi['position']['lat'] . ',' . $poi['position']['lon'];
                }
                if ($key && !isset($byId[$key])) {
                    $byId[$key] = $poi;
                }
            }
        }
        $items = array_values($byId);
        $out = [
            'success' => true,
            'data' => [
                'items' => $items,
                'fetchedAt' => (int)round(microtime(true) * 1000),
                'source' => 'tomtom',
                'count' => count($items),
            ],
        ];
        map_layers_write_cache($path, $out);
        return ['ok' => true, 'payload' => $out, 'error' => null, 'from_cache' => false];
    } catch (Throwable $e) {
        $cached = map_layers_read_cache($path);
        if ($cached && !empty($cached['success'])) {
            return ['ok' => true, 'payload' => $cached, 'error' => null, 'from_cache' => true];
        }
        return ['ok' => false, 'payload' => null, 'error' => $e->getMessage(), 'from_cache' => false];
    }
}

/**
 * @return array{ok:bool,payload:?array,error:?string,from_cache:bool}
 */
function map_layers_fetch_routes(bool $force): array {
    return rgmap_get_overpass_routes_payload($force);
}

function map_layers_get(string $layer, bool $force): array {
    if ($layer === 'incidents') {
        return map_layers_fetch_incidents($force);
    }
    if ($layer === 'bus') {
        return map_layers_fetch_transit('bus', '9942002', $force);
    }
    if ($layer === 'rail') {
        return map_layers_fetch_transit('rail', '7380', $force);
    }
    if ($layer === 'routes') {
        return map_layers_fetch_routes($force);
    }
    return ['ok' => false, 'payload' => null, 'error' => 'Unknown layer', 'from_cache' => false];
}

$sync = isset($_GET['sync']) && ($_GET['sync'] === '1' || $_GET['sync'] === 'true');
$layer = preg_replace('/[^a-z]/', '', strtolower((string)($_GET['layer'] ?? '')));
$force = $sync || (isset($_GET['refresh']) && $_GET['refresh'] === '1');

if ($sync) {
    $results = [];
    foreach (['incidents', 'bus', 'rail', 'routes'] as $key) {
        $res = map_layers_get($key, true);
        $results[$key] = [
            'ok' => $res['ok'],
            'from_cache' => $res['from_cache'],
            'error' => $res['error'],
            'count' => null,
        ];
        if ($res['ok'] && is_array($res['payload'])) {
            $data = $res['payload']['data'] ?? [];
            if ($key === 'routes') {
                $results[$key]['count'] = isset($data['routes']) ? count($data['routes']) : ($data['count'] ?? null);
            } else {
                $results[$key]['count'] = isset($data['items']) ? count($data['items']) : ($data['count'] ?? null);
            }
        }
    }
    echo json_encode([
        'success' => true,
        'message' => 'Map layers synced',
        'data' => ['layers' => $results],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($layer === '') {
    json_error('layer or sync parameter required', 400);
}

$res = map_layers_get($layer, $force);
if (!$res['ok'] || !$res['payload']) {
    json_error($res['error'] ?? 'Failed to load layer', 502);
}

echo json_encode($res['payload'], JSON_UNESCAPED_UNICODE);
