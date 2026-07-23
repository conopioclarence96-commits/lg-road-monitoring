<?php

require_once __DIR__ . '/TomTomClient.php';

class OrbisTrafficService {
    private TomTomClient $client;

    public function __construct(?TomTomClient $client = null) {
        $this->client = $client ?? new TomTomClient();
    }

    public function getFlowExtendedTileUrl(int $z, int $x, int $y, array $params = []): string {
        $params['key'] = $this->client->getApiKey();
        $query = http_build_query($params);
        return $this->client->getBaseUrl() . '/traffic/map/4/tile/flow/extended/' . $z . '/' . $x . '/' . $y . '.png?' . $query;
    }

    public function getIncidentsExtendedTileUrl(int $z, int $x, int $y, array $params = []): string {
        $params['key'] = $this->client->getApiKey();
        $query = http_build_query($params);
        return $this->client->getBaseUrl() . '/traffic/map/4/tile/incidents/extended/' . $z . '/' . $x . '/' . $y . '.png?' . $query;
    }

    public function getFlowTileUrl(int $z, int $x, int $y, array $params = []): string {
        $params['key'] = $this->client->getApiKey();
        $query = http_build_query($params);
        return $this->client->getBaseUrl() . '/traffic/map/4/tile/flow/absolute/' . $z . '/' . $x . '/' . $y . '.png?' . $query;
    }

    public function getIncidentsTileUrl(int $z, int $x, int $y, array $params = []): string {
        $params['key'] = $this->client->getApiKey();
        $query = http_build_query($params);
        return $this->client->getBaseUrl() . '/traffic/map/4/tile/incidents/absolute/' . $z . '/' . $x . '/' . $y . '.png?' . $query;
    }

    public function getOrbisFlowTileUrl(int $z, int $x, int $y, array $params = []): string {
        return $this->getFlowExtendedTileUrl($z, $x, $y, $params);
    }

    public function getOrbisIncidentsTileUrl(int $z, int $x, int $y, array $params = []): string {
        return $this->getIncidentsExtendedTileUrl($z, $x, $y, $params);
    }

    public function createFlowExtendedTileLayer(array $params = []): array {
        return [
            'type' => 'extended_flow',
            'urlTemplate' => $this->client->getBaseUrl() . '/traffic/map/4/tile/flow/extended/{z}/{x}/{y}.png',
            'params' => $params,
        ];
    }

    public function createIncidentsExtendedTileLayer(array $params = []): array {
        return [
            'type' => 'extended_incidents',
            'urlTemplate' => $this->client->getBaseUrl() . '/traffic/map/4/tile/incidents/extended/{z}/{x}/{y}.png',
            'params' => $params,
        ];
    }
}
