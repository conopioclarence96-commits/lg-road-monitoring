<?php

require_once __DIR__ . '/TomTomClient.php';

class AssetsService {
    private TomTomClient $client;

    public function __construct(?TomTomClient $client = null) {
        $this->client = $client ?? new TomTomClient();
    }

    public function listAssets(array $params = []): array {
        return $this->client->request('/map/1/assets', $params);
    }

    public function getAsset(string $assetId, array $params = []): array {
        return $this->client->request('/map/1/assets/' . $assetId, $params);
    }

    public function uploadAsset(string $assetType, string $filePath, array $params = []): array {
        return $this->client->request('/map/1/assets/' . $assetType . '/upload', $params);
    }

    public function deleteAsset(string $assetId, array $params = []): array {
        return $this->client->request('/map/1/assets/' . $assetId, $params, 'DELETE');
    }

    public function getAssetUploadUrl(string $assetType, array $params = []): string {
        $params['key'] = $this->client->getApiKey();
        return $this->client->getBaseUrl() . '/map/1/assets/' . $assetType . '/upload?' . http_build_query($params);
    }

    public function listAssetTypes(array $params = []): array {
        return $this->client->request('/map/1/assets/types', $params);
    }

    public function getAssetDownloadUrl(string $assetId, array $params = []): string {
        $params['key'] = $this->client->getApiKey();
        return $this->client->getBaseUrl() . '/map/1/assets/' . $assetId . '/download?' . http_build_query($params);
    }

    public function listStyleAssets(string $styleId, array $params = []): array {
        return $this->client->request('/map/1/style/' . $styleId . '/assets', $params);
    }
}
