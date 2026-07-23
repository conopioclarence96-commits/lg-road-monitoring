<?php

require_once __DIR__ . '/TomTomClient.php';

class EVChargingService {
    private TomTomClient $client;

    public function __construct(?TomTomClient $client = null) {
        $this->client = $client ?? new TomTomClient();
    }

    public function findNearbyStations(float $lat, float $lng, array $params = []): array {
        $params['lat'] = $lat;
        $params['lon'] = $lng;
        $params['limit'] = $params['limit'] ?? 20;
        $params['category'] = 'EV Charging Station';
        return $this->client->request('/search/2/categorySearch/' . urlencode('EV Charging Station') . '.json', $params);
    }

    public function getStationAvailability(string $stationId, array $params = []): array {
        return $this->client->request('/ev-charging/1/availability/' . $stationId, $params);
    }

    public function getStationsAvailability(array $stationIds, array $params = []): array {
        $params['stationIds'] = implode(',', $stationIds);
        return $this->client->request('/ev-charging/1/availability', $params);
    }

    public function getStationDetails(string $stationId, array $params = []): array {
        $params['id'] = $stationId;
        return $this->client->request('/search/2/search/' . urlencode($stationId) . '.json', $params);
    }

    public function getStationConnectors(string $stationId, array $params = []): array {
        return $this->client->request('/ev-charging/1/availability/' . $stationId . '/connectors', $params);
    }

    public function searchAlongRoute(
        float $fromLat, float $fromLng,
        float $toLat, float $toLng,
        array $params = []
    ): array {
        $params['category'] = 'EV Charging Station';
        $params['query'] = 'EV charging station';
        $params['route'] = $fromLat . ',' . $fromLng . ':' . $toLat . ',' . $toLng;
        return $this->client->request('/search/2/search/' . urlencode('EV charging station') . '.json', $params);
    }
}
