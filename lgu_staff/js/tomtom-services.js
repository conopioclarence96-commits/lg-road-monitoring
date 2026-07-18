/**
 * TomTom API Services - JavaScript Client
 * Communicates with server-side PHP proxy to keep API key secure
 * Requires TOMTOM_PROXY_URL global to be set by PHP
 */
const TomTomServices = (function() {

    function request(service, params = {}, method = 'GET', body = null) {
        const proxyUrl = window.TOMTOM_PROXY_URL;
        if (!proxyUrl) {
            console.error('TomTomServices: TOMTOM_PROXY_URL not set');
            return Promise.resolve({ success: false, error: 'Proxy URL not configured' });
        }
        const url = new URL(proxyUrl);
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
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status + ' ' + r.statusText);
                return r.json();
            })
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
        geocode: function(query, params = {}) {
            return request('geocode', { query, ...params });
        },
        reverseGeocode: function(lat, lng, params = {}) {
            return request('reverse_geocode', { lat, lng, ...params });
        },
        batchGeocode: function(queries) {
            return request('batch_geocode', {}, 'POST', { queries });
        },
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
        evCharging: function(lat, lng, params = {}) {
            return request('ev_charging', { lat, lng, ...params });
        },
        evAvailability: function(stationId) {
            return request('ev_availability', { station_id: stationId });
        },
        calculateRoute: function(fromLat, fromLng, toLat, toLng, params = {}) {
            return request('route', { from_lat: fromLat, from_lng: fromLng, to_lat: toLat, to_lng: toLng, ...params });
        },
        extendedRoute: function(fromLat, fromLng, toLat, toLng, params = {}) {
            return request('route_extended', { from_lat: fromLat, from_lng: fromLng, to_lat: toLat, to_lng: toLng, ...params });
        },
        matrixRouting: function(origins, destinations, params = {}) {
            return request('matrix_routing', params, 'POST', { origins, destinations });
        },
        reachableRange: function(lat, lng, params = {}) {
            return request('reachable_range', { lat, lng, ...params });
        },
        snapToRoads: function(points) {
            return request('snap_to_roads', {}, 'POST', { points });
        },
        waypointOptimization: function(waypoints) {
            return request('waypoint_optimization', {}, 'POST', { waypoints });
        },
        trafficFlow: function(params = {}) {
            return request('traffic_flow', params);
        },
        trafficIncidents: function(lat, lng, radius, params = {}) {
            return request('traffic_incidents', { lat, lng, radius, ...params });
        },
        trafficIncidentViewport: function(lat, lng, zoom, params = {}) {
            return request('traffic_incident_viewport', { lat, lng, zoom, ...params });
        },
        geofenceCreate: function(name, coordinates) {
            return request('geofence_create', {}, 'POST', { name, coordinates });
        },
        geofenceCheck: function(lat, lng) {
            return request('geofence_check', { lat, lng });
        },
        geofenceList: function() {
            return request('geofence_list');
        },
        locationHistoryRecord: function(deviceId, lat, lng, extra = {}) {
            return request('location_history_record', {}, 'POST', { device_id: deviceId, lat, lng, ...extra });
        },
        locationHistoryGet: function(deviceId) {
            return request('location_history_get', { device_id: deviceId });
        },
        locationHistoryDevices: function() {
            return request('location_history_devices');
        },
        notificationsSend: function(notification) {
            return request('notifications_send', {}, 'POST', notification);
        },
        notificationsSubscribe: function(channel, endpoint) {
            return request('notifications_subscribe', {}, 'POST', { channel, endpoint });
        },
        notificationsList: function() {
            return request('notifications_list');
        },
        assetsList: function() {
            return request('assets_list');
        },
        getTileUrls: function() {
            return {
                basic: 'https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=' + window.TOMTOM_API_KEY,
                satellite: 'https://api.tomtom.com/map/1/tile/satellite/main/{z}/{x}/{y}.png?view=Unified&key=' + window.TOMTOM_API_KEY,
                traffic: 'https://api.tomtom.com/traffic/map/4/tile/flow/absolute/{z}/{x}/{y}.png?view=Unified&key=' + window.TOMTOM_API_KEY,
            };
        },
        mapStyleCreate: function(styleData) {
            return request('map_style_create', {}, 'POST', styleData);
        },
        mapStyleList: function() {
            return request('map_style_list');
        },
        orbisFlowTile: function(z, x, y, params = {}) {
            return request('orbis_flow_tile', { z, x, y, ...params });
        },
        orbisIncidentsTile: function(z, x, y, params = {}) {
            return request('orbis_incidents_tile', { z, x, y, ...params });
        },
    };
})();

window.TomTomServices = TomTomServices;
