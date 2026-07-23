<?php

require_once __DIR__ . '/TomTomClient.php';

class MapsService {
    private TomTomClient $client;

    public function __construct(?TomTomClient $client = null) {
        $this->client = $client ?? new TomTomClient();
    }

    public function getTileUrl(string $layer = 'basic', string $style = 'main', int $z = 0, int $x = 0, int $y = 0, array $params = []): string {
        $params['view'] = $params['view'] ?? 'Unified';
        $query = http_build_query(array_merge(['key' => $this->client->getApiKey()], $params));
        return $this->client->getBaseUrl() . '/map/1/tile/' . $layer . '/' . $style . '/' . $z . '/' . $x . '/' . $y . '.png?' . $query;
    }

    public function getSatelliteTileUrl(int $z, int $x, int $y, array $params = []): string {
        return $this->getTileUrl('satellite', 'main', $z, $x, $y, $params);
    }

    public function getTrafficTileUrl(int $z, int $x, int $y, array $params = []): string {
        $params['key'] = $this->client->getApiKey();
        $query = http_build_query($params);
        return $this->client->getBaseUrl() . '/traffic/map/4/tile/flow/absolute/' . $z . '/' . $x . '/' . $y . '.png?' . $query;
    }

    public function createStyle(string $name, array $styleData, array $params = []): array {
        return $this->client->request('/map/1/style', $params, 'POST', $styleData);
    }

    public function listStyles(array $params = []): array {
        return $this->client->request('/map/1/style', $params);
    }

    public function getStyle(string $styleId, array $params = []): array {
        return $this->client->request('/map/1/style/' . $styleId, $params);
    }

    public function updateStyle(string $styleId, array $styleData, array $params = []): array {
        return $this->client->request('/map/1/style/' . $styleId, $params, 'PUT', $styleData);
    }

    public function deleteStyle(string $styleId, array $params = []): array {
        return $this->client->request('/map/1/style/' . $styleId, $params, 'DELETE');
    }

    public function uploadStyleFile(string $styleId, string $filePath, array $params = []): array {
        $ch = curl_init();
        $url = $this->client->getBaseUrl() . '/map/1/style/' . $styleId . '/upload?key=' . $this->client->getApiKey();
        if (!empty($params)) {
            $url .= '&' . http_build_query($params);
        }

        if (!class_exists('CURLFile')) {
            $file = new CURLFile($filePath, mime_content_type($filePath), basename($filePath));
        } else {
            $file = new CURLFile($filePath, mime_content_type($filePath), basename($filePath));
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['file' => $file],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'data' => json_decode($response, true) ?? $response,
        ];
    }

    public function listAssets(array $params = []): array {
        return $this->client->request('/map/1/assets', $params);
    }

    public function getAsset(string $assetId, array $params = []): array {
        return $this->client->request('/map/1/assets/' . $assetId, $params);
    }

    public function uploadAsset(string $assetType, string $filePath, array $params = []): array {
        $ch = curl_init();
        $url = $this->client->getBaseUrl() . '/map/1/assets/' . $assetType . '/upload?key=' . $this->client->getApiKey();
        if (!empty($params)) {
            $url .= '&' . http_build_query($params);
        }

        $file = new CURLFile($filePath, mime_content_type($filePath), basename($filePath));

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['file' => $file],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'data' => json_decode($response, true) ?? $response,
        ];
    }

    public function deleteAsset(string $assetId, array $params = []): array {
        return $this->client->request('/map/1/assets/' . $assetId, $params, 'DELETE');
    }
}
