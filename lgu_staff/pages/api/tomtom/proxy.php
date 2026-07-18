<?php
session_start();
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/tomtom/autoload.php';

if (!isset($_SESSION['user_id'])) {
    json_error('Unauthorized', 401);
}

header('Content-Type: application/json');

$service = $_GET['service'] ?? '';
$action = $_GET['action'] ?? '';

// Strip internal params before forwarding to TomTom API
$tomtomParams = $_GET;
unset($tomtomParams['service'], $tomtomParams['action']);

try {
    switch ($service) {
        case 'geocode':
            $geo = new GeocodingService();
            $query = $tomtomParams['query'] ?? '';
            if (empty($query)) json_error('Query parameter required');
            echo json_encode($geo->geocode($query, $tomtomParams));
            break;

        case 'reverse_geocode':
            $geo = new GeocodingService();
            $lat = (float)($tomtomParams['lat'] ?? 0);
            $lng = (float)($tomtomParams['lng'] ?? 0);
            if (!$lat || !$lng) json_error('lat and lng parameters required');
            echo json_encode($geo->reverseGeocode($lat, $lng, $tomtomParams));
            break;

        case 'search':
            $search = new SearchService();
            $query = $tomtomParams['query'] ?? '';
            if (empty($query)) json_error('Query parameter required');
            echo json_encode($search->search($query, $tomtomParams));
            break;

        case 'poi_search':
            $search = new SearchService();
            $query = $tomtomParams['query'] ?? '';
            if (empty($query)) json_error('Query parameter required');
            echo json_encode($search->poiSearch($query, $tomtomParams));
            break;

        case 'nearby_search':
            $search = new SearchService();
            $lat = (float)($tomtomParams['lat'] ?? 0);
            $lng = (float)($tomtomParams['lng'] ?? 0);
            if (!$lat || !$lng) json_error('lat and lng parameters required');
            echo json_encode($search->nearbySearch($lat, $lng, $tomtomParams));
            break;

        case 'category_search':
            $search = new SearchService();
            $category = $tomtomParams['category'] ?? '';
            if (empty($category)) json_error('Category parameter required');
            echo json_encode($search->categorySearch($category, $tomtomParams));
            break;

        case 'route':
            $routing = new RoutingService();
            $fromLat = (float)($tomtomParams['from_lat'] ?? 0);
            $fromLng = (float)($tomtomParams['from_lng'] ?? 0);
            $toLat = (float)($tomtomParams['to_lat'] ?? 0);
            $toLng = (float)($tomtomParams['to_lng'] ?? 0);
            if (!$fromLat || !$fromLng || !$toLat || !$toLng) json_error('from_lat, from_lng, to_lat, to_lng parameters required');
            echo json_encode($routing->calculateRouteV3($fromLat, $fromLng, $toLat, $toLng, $tomtomParams));
            break;

        case 'route_extended':
            $routing = new RoutingService();
            $fromLat = (float)($tomtomParams['from_lat'] ?? 0);
            $fromLng = (float)($tomtomParams['from_lng'] ?? 0);
            $toLat = (float)($tomtomParams['to_lat'] ?? 0);
            $toLng = (float)($tomtomParams['to_lng'] ?? 0);
            if (!$fromLat || !$fromLng || !$toLat || !$toLng) json_error('from_lat, from_lng, to_lat, to_lng parameters required');
            echo json_encode($routing->calculateRouteV3($fromLat, $fromLng, $toLat, $toLng, $tomtomParams));
            break;

        case 'matrix_routing':
            $routing = new RoutingService();
            $input = json_decode(file_get_contents('php://input'), true);
            $origins = $input['origins'] ?? [];
            $destinations = $input['destinations'] ?? [];
            if (empty($origins) || empty($destinations)) json_error('origins and destinations required');
            echo json_encode($routing->matrixRoutingV2($origins, $destinations, $tomtomParams));
            break;

        case 'reachable_range':
            $routing = new RoutingService();
            $lat = (float)($tomtomParams['lat'] ?? 0);
            $lng = (float)($tomtomParams['lng'] ?? 0);
            if (!$lat || !$lng) json_error('lat and lng parameters required');
            echo json_encode($routing->reachableRange($lat, $lng, $tomtomParams));
            break;

        case 'snap_to_roads':
            $routing = new RoutingService();
            $input = json_decode(file_get_contents('php://input'), true);
            $points = $input['points'] ?? [];
            if (count($points) < 2) json_error('At least 2 points required');
            echo json_encode($routing->snapToRoads($points, $tomtomParams));
            break;

        case 'waypoint_optimization':
            $routing = new RoutingService();
            $input = json_decode(file_get_contents('php://input'), true);
            $waypoints = $input['waypoints'] ?? [];
            if (count($waypoints) < 3) json_error('At least 3 waypoints required');
            echo json_encode($routing->waypointOptimization($waypoints, $tomtomParams));
            break;

        case 'traffic_incidents':
            $traffic = new TrafficService();
            $lat = (float)($tomtomParams['lat'] ?? 14.65);
            $lng = (float)($tomtomParams['lng'] ?? 121.05);
            $radius = (float)($tomtomParams['radius'] ?? 10);
            echo json_encode($traffic->trafficIncidentDetails($lat, $lng, $radius, $tomtomParams));
            break;

        case 'traffic_incident_viewport':
            $traffic = new TrafficService();
            $lat = (float)($tomtomParams['lat'] ?? 14.65);
            $lng = (float)($tomtomParams['lng'] ?? 121.05);
            $zoom = (int)($tomtomParams['zoom'] ?? 12);
            echo json_encode($traffic->trafficIncidentViewport($lat, $lng, $zoom, $tomtomParams));
            break;

        case 'traffic_flow':
            $traffic = new TrafficService();
            echo json_encode($traffic->trafficFlow($tomtomParams));
            break;

        case 'batch_geocode':
            $geo = new GeocodingService();
            $input = json_decode(file_get_contents('php://input'), true);
            $queries = $input['queries'] ?? [];
            if (empty($queries)) json_error('queries array required');
            echo json_encode($geo->batchGeocode($queries, $tomtomParams));
            break;

        case 'batch_search':
            $search = new SearchService();
            $input = json_decode(file_get_contents('php://input'), true);
            $queries = $input['queries'] ?? [];
            if (empty($queries)) json_error('queries array required');
            echo json_encode($search->batchSearch($queries, $tomtomParams));
            break;

        case 'ev_charging':
            $ev = new EVChargingService();
            $lat = (float)($tomtomParams['lat'] ?? 14.65);
            $lng = (float)($tomtomParams['lng'] ?? 121.05);
            echo json_encode($ev->findNearbyStations($lat, $lng, $tomtomParams));
            break;

        case 'ev_availability':
            $ev = new EVChargingService();
            $stationId = $tomtomParams['station_id'] ?? '';
            if (empty($stationId)) json_error('station_id required');
            echo json_encode($ev->getStationAvailability($stationId, $tomtomParams));
            break;

        case 'geofence_create':
            $geoFence = new GeofencingService();
            $input = json_decode(file_get_contents('php://input'), true);
            $name = $input['name'] ?? '';
            $coordinates = $input['coordinates'] ?? [];
            if (empty($name) || empty($coordinates)) json_error('name and coordinates required');
            echo json_encode($geoFence->createFence($name, $coordinates, $tomtomParams));
            break;

        case 'geofence_check':
            $geoFence = new GeofencingService();
            $lat = (float)($tomtomParams['lat'] ?? 0);
            $lng = (float)($tomtomParams['lng'] ?? 0);
            if (!$lat || !$lng) json_error('lat and lng required');
            echo json_encode($geoFence->checkLocation($lat, $lng, $tomtomParams));
            break;

        case 'geofence_list':
            $geoFence = new GeofencingService();
            echo json_encode($geoFence->listFences($tomtomParams));
            break;

        case 'location_history_record':
            $locHist = new LocationHistoryService();
            $input = json_decode(file_get_contents('php://input'), true);
            $deviceId = $input['device_id'] ?? '';
            $lat = (float)($input['lat'] ?? 0);
            $lng = (float)($input['lng'] ?? 0);
            if (empty($deviceId) || !$lat || !$lng) json_error('device_id, lat, and lng required');
            echo json_encode($locHist->recordPosition($deviceId, $lat, $lng, $input));
            break;

        case 'location_history_get':
            $locHist = new LocationHistoryService();
            $deviceId = $tomtomParams['device_id'] ?? '';
            if (empty($deviceId)) json_error('device_id required');
            echo json_encode($locHist->getPositionHistory($deviceId, $tomtomParams));
            break;

        case 'location_history_devices':
            $locHist = new LocationHistoryService();
            echo json_encode($locHist->listDevices($tomtomParams));
            break;

        case 'notifications_send':
            $notif = new NotificationsService();
            $input = json_decode(file_get_contents('php://input'), true);
            echo json_encode($notif->sendNotification($input, $tomtomParams));
            break;

        case 'notifications_subscribe':
            $notif = new NotificationsService();
            $input = json_decode(file_get_contents('php://input'), true);
            $channel = $input['channel'] ?? '';
            $endpoint = $input['endpoint'] ?? '';
            if (empty($channel) || empty($endpoint)) json_error('channel and endpoint required');
            echo json_encode($notif->createSubscription($channel, $endpoint, $tomtomParams));
            break;

        case 'notifications_list':
            $notif = new NotificationsService();
            echo json_encode($notif->listSubscriptions($tomtomParams));
            break;

        case 'assets_list':
            $assets = new AssetsService();
            echo json_encode($assets->listAssets($tomtomParams));
            break;

        case 'map_style_create':
            $maps = new MapsService();
            $input = json_decode(file_get_contents('php://input'), true);
            $name = $input['name'] ?? '';
            if (empty($name)) json_error('name required');
            echo json_encode($maps->createStyle($name, $input, $tomtomParams));
            break;

        case 'map_style_list':
            $maps = new MapsService();
            echo json_encode($maps->listStyles($tomtomParams));
            break;

        case 'orbis_flow_tile':
            $orbis = new OrbisTrafficService();
            $z = (int)($tomtomParams['z'] ?? 0);
            $x = (int)($tomtomParams['x'] ?? 0);
            $y = (int)($tomtomParams['y'] ?? 0);
            echo json_encode(['url' => $orbis->getFlowExtendedTileUrl($z, $x, $y, $tomtomParams)]);
            break;

        case 'orbis_incidents_tile':
            $orbis = new OrbisTrafficService();
            $z = (int)($tomtomParams['z'] ?? 0);
            $x = (int)($tomtomParams['x'] ?? 0);
            $y = (int)($tomtomParams['y'] ?? 0);
            echo json_encode(['url' => $orbis->getIncidentsExtendedTileUrl($z, $x, $y, $tomtomParams)]);
            break;

        default:
            json_error('Unknown service: ' . $service, 400);
    }
} catch (Exception $e) {
    json_error($e->getMessage(), 500);
}
