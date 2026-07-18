<?php

require_once __DIR__ . '/TomTomClient.php';

class SearchService {
    private TomTomClient $client;

    public function __construct(?TomTomClient $client = null) {
        $this->client = $client ?? new TomTomClient();
    }

    public function search(string $query, array $params = []): array {
        $params['limit'] = $params['limit'] ?? 10;
        $params['typeahead'] = $params['typeahead'] ?? 'true';
        return $this->client->request('/search/2/search/' . urlencode($query) . '.json', $params);
    }

    public function poiSearch(string $query, array $params = []): array {
        $params['limit'] = $params['limit'] ?? 10;
        return $this->client->request('/search/2/poiSearch/' . urlencode($query) . '.json', $params);
    }

    public function fuzzySearch(string $query, array $params = []): array {
        $params['limit'] = $params['limit'] ?? 10;
        $params['typeahead'] = $params['typeahead'] ?? 'true';
        return $this->client->request('/search/2/search/' . urlencode($query) . '.json', $params);
    }

    public function placesSearch(string $query, array $params = []): array {
        return $this->search($query, $params);
    }

    public function categorySearch(string $category, array $params = []): array {
        $params['category'] = $category;
        return $this->client->request('/search/2/categorySearch/' . urlencode($category) . '.json', $params);
    }

    public function nearbySearch(float $lat, float $lng, array $params = []): array {
        $params['lat'] = $lat;
        $params['lon'] = $lng;
        return $this->client->request('/search/2/nearbySearch.json', $params);
    }

    public function geometrySearch(string $geometryList, array $params = []): array {
        $params['geometryList'] = $geometryList;
        return $this->client->request('/search/2/geometrySearch.json', $params);
    }

    public function batchSearch(array $queries, array $params = []): array {
        $batchItems = [];
        foreach ($queries as $q) {
            $batchItems[] = ['query' => '/search/2/search/' . urlencode($q) . '.json?limit=1'];
        }
        return $this->client->request('/search/2/batch.json', $params, 'POST', ['batchItems' => $batchItems]);
    }

    public function getBatchStatus(string $batchId, array $params = []): array {
        return $this->client->request('/search/2/batch/' . $batchId . '/status.json', $params);
    }

    public function getBatchResults(string $batchId, array $params = []): array {
        return $this->client->request('/search/2/batch/' . $batchId . '/results.json', $params);
    }

    public function EVChargingStations(float $lat, float $lng, array $params = []): array {
        $params['lat'] = $lat;
        $params['lon'] = $lng;
        $params['limit'] = $params['limit'] ?? 10;
        return $this->client->request('/search/2/search/' . urlencode('EV charging station') . '.json', $params);
    }
}
