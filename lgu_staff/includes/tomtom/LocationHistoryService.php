<?php

require_once __DIR__ . '/TomTomClient.php';

class LocationHistoryService {
    private TomTomClient $client;

    public function __construct(?TomTomClient $client = null) {
        $this->client = $client ?? new TomTomClient();
    }

    public function recordPosition(string $deviceId, float $lat, float $lng, array $params = []): array {
        $body = [
            'deviceId' => $deviceId,
            'position' => ['lat' => $lat, 'lon' => $lng],
            'timestamp' => $params['timestamp'] ?? date('c'),
        ];
        if (isset($params['speed'])) $body['speed'] = $params['speed'];
        if (isset($params['heading'])) $body['heading'] = $params['heading'];
        if (isset($params['accuracy'])) $body['accuracy'] = $params['accuracy'];

        return $this->client->request('/locationHistory/1/positions', $params, 'POST', $body);
    }

    public function getPositionHistory(string $deviceId, array $params = []): array {
        return $this->client->request('/locationHistory/1/positions/' . $deviceId, $params);
    }

    public function getLatestPosition(string $deviceId, array $params = []): array {
        $params['limit'] = 1;
        return $this->client->request('/locationHistory/1/positions/' . $deviceId, $params);
    }

    public function getPositionByTime(string $deviceId, string $timestamp, array $params = []): array {
        return $this->client->request('/locationHistory/1/positions/' . $deviceId . '/at/' . urlencode($timestamp), $params);
    }

    public function getPositionRange(string $deviceId, string $from, string $to, array $params = []): array {
        $params['from'] = $from;
        $params['to'] = $to;
        return $this->client->request('/locationHistory/1/positions/' . $deviceId . '/range', $params);
    }

    public function deletePositionHistory(string $deviceId, array $params = []): array {
        return $this->client->request('/locationHistory/1/positions/' . $deviceId, $params, 'DELETE');
    }

    public function registerDevice(string $deviceId, array $params = []): array {
        $body = [
            'deviceId' => $deviceId,
            'name' => $params['name'] ?? $deviceId,
            'description' => $params['description'] ?? '',
        ];
        return $this->client->request('/locationHistory/1/devices', $params, 'POST', $body);
    }

    public function listDevices(array $params = []): array {
        return $this->client->request('/locationHistory/1/devices', $params);
    }

    public function getDevice(string $deviceId, array $params = []): array {
        return $this->client->request('/locationHistory/1/devices/' . $deviceId, $params);
    }

    public function deleteDevice(string $deviceId, array $params = []): array {
        return $this->client->request('/locationHistory/1/devices/' . $deviceId, $params, 'DELETE');
    }
}
