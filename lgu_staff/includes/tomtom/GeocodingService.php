<?php

require_once __DIR__ . '/TomTomClient.php';

class GeocodingService {
    private TomTomClient $client;

    public function __construct(?TomTomClient $client = null) {
        $this->client = $client ?? new TomTomClient();
    }

    public function geocode(string $query, array $params = []): array {
        $params['query'] = $query;
        $params['limit'] = $params['limit'] ?? 10;
        return $this->client->request('/search/2/geocode/' . urlencode($query) . '.json', $params);
    }

    public function reverseGeocode(float $lat, float $lng, array $params = []): array {
        $params['returnSpeedLimit'] = $params['returnSpeedLimit'] ?? 'true';
        $params['returnRoadUse'] = $params['returnRoadUse'] ?? 'true';
        return $this->client->request('/search/2/reverseGeocode/' . $lat . ',' . $lng . '.json', $params);
    }

    public function batchGeocode(array $queries, array $params = []): array {
        $batchItems = [];
        foreach ($queries as $q) {
            $batchItems[] = ['query' => '/search/2/geocode/' . urlencode($q) . '.json?limit=1'];
        }
        return $this->client->request('/search/2/batch.json', $params, 'POST', ['batchItems' => $batchItems]);
    }

    public function batchReverseGeocode(array $coordinates, array $params = []): array {
        $batchItems = [];
        foreach ($coordinates as $coord) {
            $batchItems[] = ['query' => '/search/2/reverseGeocode/' . $coord[0] . ',' . $coord[1] . '.json'];
        }
        return $this->client->request('/search/2/batch.json', $params, 'POST', ['batchItems' => $batchItems]);
    }

    public function structuredGeocode(array $structuredQuery, array $params = []): array {
        $params = array_merge($params, $structuredQuery);
        return $this->client->request('/search/2/structuredGeocode.json', $params);
    }
}
