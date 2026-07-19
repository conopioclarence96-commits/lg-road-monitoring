<?php

require_once __DIR__ . '/TomTomClient.php';

class ExtendedRoutingService {
    private TomTomClient $client;

    public function __construct(?TomTomClient $client = null) {
        $this->client = $client ?? new TomTomClient();
    }

    public function calculateRouteWithFullDetails(
        float $fromLat, float $fromLng,
        float $toLat, float $toLng,
        array $params = []
    ): array {
        $loc = $fromLat . ',' . $fromLng . ':' . $toLat . ',' . $toLng;
        $defaults = [
            'routeRepresentation' => 'polyline',
            'instructionsType' => 'text',
            'computeTravelTimeFor' => 'all',
            'traffic' => 'true',
            'vehicleEngineType' => 'combustion',
            'accelerate' => 'true',
            'decelerate' => 'true',
            'hilliness' => 'true',
        ];
        $params = array_merge($defaults, $params);
        return $this->client->request('/routing/1/calculateRoute/' . $loc . '/json', $params);
    }

    public function calculateRouteWithSectionTypes(
        float $fromLat, float $fromLng,
        float $toLat, float $toLng,
        array $sectionTypes = ['traffic', 'travelMode'],
        array $params = []
    ): array {
        $loc = $fromLat . ',' . $fromLng . ':' . $toLat . ',' . $toLng;
        $params['sectionType'] = implode(',', $sectionTypes);
        $defaults = [
            'routeRepresentation' => 'polyline',
            'instructionsType' => 'text',
            'computeTravelTimeFor' => 'all',
            'traffic' => 'true',
        ];
        $params = array_merge($defaults, $params);
        return $this->client->request('/routing/1/calculateRoute/' . $loc . '/json', $params);
    }

    public function calculateTruckRoute(
        float $fromLat, float $fromLng,
        float $toLat, float $toLng,
        array $params = []
    ): array {
        $params['vehicleCommercial'] = 'true';
        $params['vehicleEngineType'] = $params['vehicleEngineType'] ?? 'combustion';
        return $this->calculateRouteWithFullDetails($fromLat, $fromLng, $toLat, $toLng, $params);
    }

    public function calculatePedestrianRoute(
        float $fromLat, float $fromLng,
        float $toLat, float $toLng,
        array $params = []
    ): array {
        $params['travelMode'] = 'pedestrian';
        return $this->calculateRouteWithFullDetails($fromLat, $fromLng, $toLat, $toLng, $params);
    }

    public function calculateBicycleRoute(
        float $fromLat, float $fromLng,
        float $toLat, float $toLng,
        array $params = []
    ): array {
        $params['travelMode'] = 'bicycle';
        return $this->calculateRouteWithFullDetails($fromLat, $fromLng, $toLat, $toLng, $params);
    }

    public function getRouteAlternatives(
        float $fromLat, float $fromLng,
        float $toLat, float $toLng,
        int $maxAlternatives = 3,
        array $params = []
    ): array {
        $params['maxAlternatives'] = $maxAlternatives;
        return $this->calculateRouteWithFullDetails($fromLat, $fromLng, $toLat, $toLng, $params);
    }

    public function calculateAvoidRoute(
        float $fromLat, float $fromLng,
        float $toLat, float $toLng,
        string $avoid = 'tollRoads',
        array $params = []
    ): array {
        $params['avoid'] = $avoid;
        return $this->calculateRouteWithFullDetails($fromLat, $fromLng, $toLat, $toLng, $params);
    }

    public function calculateDepartureTimeRoute(
        float $fromLat, float $fromLng,
        float $toLat, float $toLng,
        string $departAt,
        array $params = []
    ): array {
        $params['departAt'] = $departAt;
        return $this->calculateRouteWithFullDetails($fromLat, $fromLng, $toLat, $toLng, $params);
    }

    public function calculateArrivalTimeRoute(
        float $fromLat, float $fromLng,
        float $toLat, float $toLng,
        string $arriveAt,
        array $params = []
    ): array {
        $params['arriveAt'] = $arriveAt;
        return $this->calculateRouteWithFullDetails($fromLat, $fromLng, $toLat, $toLng, $params);
    }
}
