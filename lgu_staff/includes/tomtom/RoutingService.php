<?php

require_once __DIR__ . '/TomTomClient.php';

class RoutingService {
    private TomTomClient $client;

    public function __construct(?TomTomClient $client = null) {
        $this->client = $client ?? new TomTomClient();
    }

    public function calculateRoute(
        float $fromLat, float $fromLng,
        float $toLat, float $toLng,
        array $params = []
    ): array {
        $loc = $fromLat . ',' . $fromLng . ':' . $toLat . ',' . $toLng;
        $defaults = [
            'routeRepresentation' => 'summaryOnly',
            'computeTravelTimeFor' => 'all',
            'instructionsType' => 'text',
            'traffic' => 'true',
            'vehicleEngineType' => 'combustion',
        ];
        $params = array_merge($defaults, $params);
        return $this->client->request('/routing/1/calculateRoute/' . $loc . '/json', $params);
    }

    public function calculateRouteWithWaypoints(
        array $waypoints,
        array $params = []
    ): array {
        if (count($waypoints) < 2) {
            return ['success' => false, 'error' => 'At least 2 waypoints required'];
        }
        $locStr = implode(':', array_map(fn($wp) => $wp[0] . ',' . $wp[1], $waypoints));
        $defaults = [
            'routeRepresentation' => 'summaryOnly',
            'computeTravelTimeFor' => 'all',
            'instructionsType' => 'text',
            'traffic' => 'true',
        ];
        $params = array_merge($defaults, $params);
        return $this->client->request('/routing/1/calculateRoute/' . $locStr . '/json', $params);
    }

    public function calculateRouteWithDescription(
        float $fromLat, float $fromLng,
        float $toLat, float $toLng,
        array $params = []
    ): array {
        $loc = $fromLat . ',' . $fromLng . ':' . $toLat . ',' . $toLng;
        $defaults = [
            'instructionsType' => 'text',
            'routeRepresentation' => 'polyline',
            'computeTravelTimeFor' => 'all',
            'traffic' => 'true',
        ];
        $params = array_merge($defaults, $params);
        return $this->client->request('/routing/1/calculateRoute/' . $loc . '/json', $params);
    }

    public function extendedRoutes(
        float $fromLat, float $fromLng,
        float $toLat, float $toLng,
        array $params = []
    ): array {
        return $this->calculateRoute($fromLat, $fromLng, $toLat, $toLng, $params);
    }

    public function matrixRoutingV2(
        array $origins,
        array $destinations,
        array $params = []
    ): array {
        $body = [
            'origins' => array_map(fn($o) => ['point' => ['latitude' => $o[0], 'longitude' => $o[1]]], $origins),
            'destinations' => array_map(fn($d) => ['point' => ['latitude' => $d[0], 'longitude' => $d[1]]], $destinations),
        ];
        $defaults = ['traffic' => 'true'];
        $params = array_merge($defaults, $params);
        return $this->client->request('/routing/1/matrix/by-geography/json', $params, 'POST', $body);
    }

    public function reachableRange(
        float $lat, float $lng,
        array $params = []
    ): array {
        $defaults = [
            'timeBudget' => '1800',
            'distanceBudget' => '50000',
            'traffic' => 'true',
        ];
        $params = array_merge($defaults, $params);
        return $this->client->request('/routing/1/reachableRange/' . $lat . ',' . $lng . '/json', $params);
    }

    public function waypointOptimization(
        array $waypoints,
        array $params = []
    ): array {
        if (count($waypoints) < 3) {
            return ['success' => false, 'error' => 'At least 3 waypoints required for optimization'];
        }
        $locStr = implode(':', array_map(fn($wp) => $wp[0] . ',' . $wp[1], $waypoints));
        $defaults = [
            'computeTravelTimeFor' => 'all',
            'traffic' => 'true',
        ];
        $params = array_merge($defaults, $params);
        return $this->client->request('/routing/1/calculateRoute/' . $locStr . '/json', $params);
    }

    public function calculateRouteV3(
        float $fromLat, float $fromLng,
        float $toLat, float $toLng,
        array $params = []
    ): array {
        $url = 'https://api.tomtom.com/maps/orbis/routing/routes/calculate';
        $apiKey = $this->client->getApiKey();

        $body = [
            'routePlanningLocations' => [
                'origin' => [
                    'type' => 'Point',
                    'coordinates' => [$fromLng, $fromLat],
                ],
                'destination' => [
                    'type' => 'Point',
                    'coordinates' => [$toLng, $toLat],
                ],
            ],
            'vehicleEngineType' => $params['vehicleEngineType'] ?? 'combustion',
            'routeType' => $params['routeType'] ?? 'short',
        ];

        unset($params['vehicleEngineType'], $params['routeType']);

        $headers = [
            'Content-Type: application/json',
            'TomTom-Api-Version: 3',
            'TomTom-Api-Key: ' . $apiKey,
            'Attributes: routes',
        ];

        return $this->client->requestRaw($url, 'POST', $body, $headers);
    }

    public function snapToRoads(
        array $points,
        array $params = []
    ): array {
        if (count($points) < 2) {
            return ['success' => false, 'error' => 'At least 2 points required'];
        }
        $pointsStr = implode(':', array_map(fn($p) => $p[0] . ',' . $p[1], $points));
        return $this->client->request('/routing/1/matching/snap/' . $pointsStr . '/json', $params);
    }
}
