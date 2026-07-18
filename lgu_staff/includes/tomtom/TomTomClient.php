<?php

class TomTomClient {
    private string $apiKey;
    private string $baseUrl = 'https://api.tomtom.com';
    private int $timeout = 30;

    public function __construct(?string $apiKey = null) {
        $this->apiKey = $apiKey ?? (defined('TOMTOM_API_KEY') ? TOMTOM_API_KEY : '');
        if (empty($this->apiKey)) {
            $this->apiKey = 'i6kR3bj7mdc5l8onrDIHX6MpcVbvm1oV';
        }
    }

    public function setApiKey(string $key): void {
        $this->apiKey = $key;
    }

    public function request(
        string $path,
        array $query = [],
        string $method = 'GET',
        ?array $body = null,
        array $headers = []
    ): array {
        $query['key'] = $this->apiKey;
        $url = $this->baseUrl . $path . '?' . http_build_query($query);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body) {
                $json = json_encode($body);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
                $headers[] = 'Content-Type: application/json';
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($body) {
                $json = json_encode($body);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
                $headers[] = 'Content-Type: application/json';
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => 'cURL error: ' . $error, 'http_code' => 0];
        }

        $data = json_decode($response, true);
        $success = $httpCode >= 200 && $httpCode < 300;

        return [
            'success' => $success,
            'http_code' => $httpCode,
            'data' => $data ?? $response,
            'raw' => $response,
        ];
    }

    public function getBaseUrl(): string {
        return $this->baseUrl;
    }

    public function getApiKey(): string {
        return $this->apiKey;
    }
}
