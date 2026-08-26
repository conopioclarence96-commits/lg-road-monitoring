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

// Public homepage GIS map needs the same JSON cache + Sync as staff.
// Session is optional; close early so concurrent layer fetches are not blocked.
session_write_close();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');

$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

$QC_CENTER = [14.6760, 121.0437];
// ~4 km grid across QC (Novaliches → Cubao, Project → Payatas). Radius 10 km per seed.
$TRANSIT_POI_CENTERS = [
    // South
    [14.590, 121.020], [14.590, 121.055], [14.590, 121.090],
    // Mid-south (Cubao / Kamuning / New Manila)
    [14.625, 121.015], [14.625, 121.050], [14.625, 121.085], [14.625, 121.115],
    // Central (Diliman / Project / Eastwood)
    [14.660, 121.015], [14.660, 121.050], [14.660, 121.085], [14.660, 121.115],
    // Mid-north (Commonwealth / Batasan)
    [14.700, 121.020], [14.700, 121.055], [14.700, 121.090], [14.700, 121.120],
    // North (Fairview / Novaliches)
    [14.740, 121.030], [14.740, 121.065], [14.740, 121.100],
    [14.770, 121.050], [14.770, 121.085],
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
                'radius' => 10000,
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
