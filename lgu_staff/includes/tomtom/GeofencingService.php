<?php

require_once __DIR__ . '/TomTomClient.php';

class GeofencingService {
    private TomTomClient $client;

    public function __construct(?TomTomClient $client = null) {
        $this->client = $client ?? new TomTomClient();
    }

    public function createFence(string $name, array $coordinates, array $params = []): array {
        $body = [
            'name' => $name,
            'fenceGeometry' => [
                'type' => 'Polygon',
                'coordinates' => [$coordinates],
            ],
        ];
        if (isset($params['adminKey'])) {
            $body['adminKey'] = $params['adminKey'];
        }
        return $this->client->request('/geofencing/1/fence', $params, 'POST', $body);
    }

    public function getFence(string $fenceId, array $params = []): array {
        return $this->client->request('/geofencing/1/fence/' . $fenceId, $params);
    }

    public function updateFence(string $fenceId, array $body, array $params = []): array {
        return $this->client->request('/geofencing/1/fence/' . $fenceId, $params, 'PUT', $body);
    }

    public function deleteFence(string $fenceId, array $params = []): array {
        return $this->client->request('/geofencing/1/fence/' . $fenceId, $params, 'DELETE');
    }

    public function listFences(array $params = []): array {
        return $this->client->request('/geofencing/1/fence', $params);
    }

    public function checkLocation(float $lat, float $lng, array $params = []): array {
        $params['lat'] = $lat;
        $params['lon'] = $lng;
        return $this->client->request('/geofencing/1/fence/checkLocation', $params);
    }

    public function checkLocationWithFence(string $fenceId, float $lat, float $lng, array $params = []): array {
        $params['lat'] = $lat;
        $params['lon'] = $lng;
        return $this->client->request('/geofencing/1/fence/' . $fenceId . '/checkLocation', $params);
    }
}
