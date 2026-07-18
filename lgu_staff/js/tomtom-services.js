/**
 * TomTom API Services - JavaScript Client
 * Communicates with server-side PHP proxy to keep API key secure
 */
const TomTomServices = (function() {
    const API_PROXY = '../api/tomtom/proxy.php';

    function request(service, params = {}, method = 'GET', body = null) {
        const url = new URL(API_PROXY, window.location.href);
        url.searchParams.set('service', service);
        Object.keys(params).forEach(k => {
            if (params[k] !== undefined && params[k] !== null) {
                url.searchParams.set(k, params[k]);
            }
        });

        const options = {
            method: method,
            headers: { 'Content-Type': 'application/json' },
        };

        if (body && method === 'POST') {
            options.body = JSON.stringify(body);
        }

        return fetch(url.toString(), options)
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    console.error('TomTom API error:', data.error || data);
                }
                return data;
            })
            .catch(err => {
                console.error('TomTom Services error:', err);
                return { success: false, error: err.message };
            });
    }

    return {
        // Geocoding API
        geocode: function(query, params = {}) {
            return request('geocode', { query, ...params });
        },
        reverseGeocode: function(lat, lng, params = {}) {
            return request('reverse_geocode', { lat, lng, ...params });
        },
        batchGeocode: function(queries) {
            return request('batch_geocode', {}, 'POST', { queries });
        },

        // Search API
        search: function(query, params = {}) {
            return request('search', { query, ...params });
        },
        nearbySearch: function(lat, lng, params = {}) {
            return request('nearby_search', { lat, lng, ...params });
        },
        categorySearch: function(category, params = {}) {
            return request('category_search', { category, ...params });
        },
        batchSearch: function(queries) {
            return request('batch_search', {}, 'POST', { queries });
        },
        placesSearch: function(query, params = {}) {
            return this.search(query, params);
        },

        // EV Charging Stations Availability API
        evCharging: function(lat, lng, params = {}) {
            return request('ev_charging', { lat, lng, ...params });
        },
        evAvailability: function(stationId) {
            return request('ev_availability', { station_id: stationId });
        },

        // Routing API
        calculateRoute: function(fromLat, fromLng, toLat, toLng, params = {}) {
            return request('route', { from_lat: fromLat, from_lng: fromLng, to_lat: toLat, to_lng: toLng, ...params });
        },

        // Extended Routing API
        extendedRoute: function(fromLat, fromLng, toLat, toLng, params = {}) {
            return request('route_extended', { from_lat: fromLat, from_lng: fromLng, to_lat: toLat, to_lng: toLng, ...params });
        },

        // Matrix Routing v2 API
        matrixRouting: function(origins, destinations, params = {}) {
            return request('matrix_routing', params, 'POST', { origins, destinations });
        },

        // Reachable Range API
        reachableRange: function(lat, lng, params = {}) {
            return request('reachable_range', { lat, lng, ...params });
        },

        // Snap to Roads API
        snapToRoads: function(points) {
            return request('snap_to_roads', {}, 'POST', { points });
        },

        // Waypoint Optimization API
        waypointOptimization: function(waypoints) {
            return request('waypoint_optimization', {}, 'POST', { waypoints });
        },

        // Traffic API / Traffic Flow API
        trafficFlow: function(params = {}) {
            return request('traffic_flow', params);
        },

        // Traffic Incidents API
        trafficIncidents: function(lat, lng, radius, params = {}) {
            return request('traffic_incidents', { lat, lng, radius, ...params });
        },
        trafficIncidentViewport: function(lat, lng, zoom, params = {}) {
            return request('traffic_incident_viewport', { lat, lng, zoom, ...params });
        },

        // Geofencing API
        geofenceCreate: function(name, coordinates) {
            return request('geofence_create', {}, 'POST', { name, coordinates });
        },
        geofenceCheck: function(lat, lng) {
            return request('geofence_check', { lat, lng });
        },
        geofenceList: function() {
            return request('geofence_list');
        },

        // Location History API
        locationHistoryRecord: function(deviceId, lat, lng, extra = {}) {
            return request('location_history_record', {}, 'POST', { device_id: deviceId, lat, lng, ...extra });
        },
        locationHistoryGet: function(deviceId) {
            return request('location_history_get', { device_id: deviceId });
        },
        locationHistoryDevices: function() {
            return request('location_history_devices');
        },

        // Notifications API
        notificationsSend: function(notification) {
            return request('notifications_send', {}, 'POST', notification);
        },
        notificationsSubscribe: function(channel, endpoint) {
            return request('notifications_subscribe', {}, 'POST', { channel, endpoint });
        },
        notificationsList: function() {
            return request('notifications_list');
        },

        // Assets API / Maps Assets API
        assetsList: function() {
            return request('assets_list');
        },

        // Map Display API - tile URLs
        getTileUrls: function() {
            return {
                basic: 'https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=' + window.TOMTOM_API_KEY,
                satellite: 'https://api.tomtom.com/map/1/tile/satellite/main/{z}/{x}/{y}.png?view=Unified&key=' + window.TOMTOM_API_KEY,
                traffic: 'https://api.tomtom.com/traffic/map/4/tile/flow/absolute/{z}/{x}/{y}.png?view=Unified&key=' + window.TOMTOM_API_KEY,
            };
        },

        // Map Display API Styles Upload
        mapStyleCreate: function(styleData) {
            return request('map_style_create', {}, 'POST', styleData);
        },
        mapStyleList: function() {
            return request('map_style_list');
        },

        // Orbis Traffic Flow/Incidents Extended Tiles
        orbisFlowTile: function(z, x, y, params = {}) {
            return request('orbis_flow_tile', { z, x, y, ...params });
        },
        orbisIncidentsTile: function(z, x, y, params = {}) {
            return request('orbis_incidents_tile', { z, x, y, ...params });
        },
    };
})();

window.TomTomServices = TomTomServices;
