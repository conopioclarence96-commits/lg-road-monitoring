<?php

require_once __DIR__ . '/TomTomClient.php';

class TrafficService {
    private TomTomClient $client;

    public function __construct(?TomTomClient $client = null) {
        $this->client = $client ?? new TomTomClient();
    }

    public function trafficFlow(array $params = []): array {
        return $this->client->request('/traffic/1/flow.json', $params);
    }

    public function trafficFlowTile(int $z, int $x, int $y, array $params = []): string {
        $query = http_build_query(array_merge(['key' => $this->client->getApiKey()], $params));
        return $this->client->getBaseUrl() . '/traffic/map/4/tile/flow/absolute/' . $z . '/' . $x . '/' . $y . '.png?' . $query;
    }

    public function trafficFlowExtendedTile(int $z, int $x, int $y, array $params = []): string {
        $query = http_build_query(array_merge(['key' => $this->client->getApiKey()], $params));
        return $this->client->getBaseUrl() . '/traffic/map/4/tile/flow/extended/' . $z . '/' . $x . '/' . $y . '.png?' . $query;
    }

    public function trafficIncidents(array $params = []): array {
        return $this->client->request('/traffic/1/incidents.json', $params);
    }

    public function trafficIncidentsTile(int $z, int $x, int $y, array $params = []): string {
        $query = http_build_query(array_merge(['key' => $this->client->getApiKey()], $params));
        return $this->client->getBaseUrl() . '/traffic/map/4/tile/incidents/absolute/' . $z . '/' . $x . '/' . $y . '.png?' . $query;
    }

    public function trafficIncidentsExtendedTile(int $z, int $x, int $y, array $params = []): string {
        $query = http_build_query(array_merge(['key' => $this->client->getApiKey()], $params));
        return $this->client->getBaseUrl() . '/traffic/map/4/tile/incidents/extended/' . $z . '/' . $x . '/' . $y . '.png?' . $query;
    }

    public function trafficIncidentDetails(float $lat, float $lng, float $radius = 10, array $params = []): array {
        $bbox = $this->getBoundingBox($lat, $lng, $radius);
        $params['bbox'] = $bbox;
        return $this->client->request('/traffic/services/4/incidentDetails.json', $params);
    }

    public function trafficIncidentViewport(
        float $lat, float $lng,
        int $zoom = 12,
        array $params = []
    ): array {
        $params['mapZoom'] = $zoom;
        $params['center'] = $lat . ',' . $lng;
        return $this->client->request('/traffic/services/4/incidentViewport.json', $params);
    }

    private function getBoundingBox(float $lat, float $lng, float $radiusKm): string {
        $latChange = $radiusKm / 111.32;
        $lngChange = $radiusKm / (111.32 * cos(deg2rad($lat)));
        $south = $lat - $latChange;
        $north = $lat + $latChange;
        $west = $lng - $lngChange;
        $east = $lng + $lngChange;
        return $south . ',' . $west . ',' . $north . ',' . $east;
    }
}
