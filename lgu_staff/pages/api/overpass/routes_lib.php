<?php
/**
 * Shared Overpass PT-route helpers + file cache (no TTL: serve until Sync/refresh).
 */

function rgmap_overpass_routes_cache_file(): string {
    return __DIR__ . '/cache/qc_bus_jeep_routes.json';
}

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

/**
 * @return array{ok:bool,payload:?array,error:?string,from_cache:bool}
 */
function rgmap_get_overpass_routes_payload(bool $force = false): array {
    $cacheFile = rgmap_overpass_routes_cache_file();
    $cacheDir = dirname($cacheFile);

    if (!$force && is_file($cacheFile)) {
        $cached = file_get_contents($cacheFile);
        if ($cached !== false && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded) && !empty($decoded['success'])) {
                return ['ok' => true, 'payload' => $decoded, 'error' => null, 'from_cache' => true];
            }
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
        if (is_file($cacheFile)) {
            $cached = file_get_contents($cacheFile);
            if ($cached !== false && $cached !== '') {
                $decoded = json_decode($cached, true);
                if (is_array($decoded) && !empty($decoded['success'])) {
                    return ['ok' => true, 'payload' => $decoded, 'error' => null, 'from_cache' => true];
                }
            }
        }
        return ['ok' => false, 'payload' => null, 'error' => $lastError, 'from_cache' => false];
    }

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($encoded !== false) {
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        @file_put_contents($cacheFile, $encoded);
    }

    return ['ok' => true, 'payload' => $payload, 'error' => null, 'from_cache' => false];
}
