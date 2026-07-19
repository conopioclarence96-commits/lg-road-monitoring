<?php

$tomtomDir = __DIR__;

$services = [
    'TomTomClient',
    'GeocodingService',
    'SearchService',
    'RoutingService',
    'TrafficService',
    'GeofencingService',
    'MapsService',
    'LocationHistoryService',
    'NotificationsService',
    'ExtendedRoutingService',
    'EVChargingService',
    'OrbisTrafficService',
    'AssetsService',
];

foreach ($services as $service) {
    $file = $tomtomDir . '/' . $service . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
}
