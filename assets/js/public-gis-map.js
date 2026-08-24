/**
 * Public homepage GIS map overlay — TomTom + Incidents/Bus/Rail/PT Sync,
 * Route / Commute / EV tools. No LGU/citizen report pins.
 */
(function () {
    'use strict';

    var CONFIG = Object.assign({
        MAP_LAYERS_CACHE_API: 'lgu_staff/pages/api/map_layers/cache.php',
        DISTRICTS_URL: 'lgu_staff/pages/api/qc_districts.geojson',
        TOMTOM_API_KEY: (window.LG_ASSET_CONFIG && window.LG_ASSET_CONFIG.TOMTOM_API_KEY) || window.TOMTOM_API_KEY || ''
    }, window.PUBLIC_GIS_CONFIG || {});

    var TOMTOM_API_KEY = CONFIG.TOMTOM_API_KEY;
    var QC_CENTER = [14.651417, 121.04917];
    var map = null;
    var trafficLayer = null;
    var trafficVisible = true;
    var basicTileLayer = null;
    var searchPinMarker = null;
    var pinMarker = null;
    var mapInitialized = false;
    var overlayOpen = false;
    var qcMaxBounds = null;
    var ncrMaxBounds = null;
    var mapFenceMode = 'qc'; // 'qc' | 'ncr'
    var PANEL_IDS = [
        'publicRoutePlannerPanel',
        'publicEvChargingPanel',
        'publicPtRoutesPanel',
        'publicCommutePlannerPanel'
    ];

    function showNotification(message, type) {
        var el = document.getElementById('publicGisToast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'publicGisToast';
            el.className = 'public-gis-toast';
            document.body.appendChild(el);
        }
        el.textContent = message || '';
        el.className = 'public-gis-toast is-visible type-' + (type || 'info');
        clearTimeout(showNotification._t);
        showNotification._t = setTimeout(function () {
            el.classList.remove('is-visible');
        }, 3200);
    }

    function isInsideQCBounds(lat, lng) {
        var geo = window.QC_GEOJSON;
        if (!geo || !geo.coordinates) return true;
        var polys = geo.type === 'MultiPolygon' ? geo.coordinates : [geo.coordinates];
        for (var p = 0; p < polys.length; p++) {
            var rings = polys[p];
            var polyInside = false;
            for (var r = 0; r < rings.length; r++) {
                var ring = rings[r];
                var ringInside = false;
                for (var i = 0, j = ring.length - 1; i < ring.length; j = i++) {
                    var xi = ring[i][1], yi = ring[i][0];
                    var xj = ring[j][1], yj = ring[j][0];
                    if ((yi > lng) !== (yj > lng) && lat < (xj - xi) * (lng - yi) / (yj - yi) + xi) {
                        ringInside = !ringInside;
                    }
                }
                if (r === 0) polyInside = ringInside;
                else if (ringInside) { polyInside = false; break; }
            }
            if (polyInside) return true;
        }
        return false;
    }

    function initMap() {
        if (mapInitialized || typeof L === 'undefined') return;
        var mapEl = document.getElementById('publicGisMap');
        if (!mapEl) return;

        map = L.map('publicGisMap', { zoomControl: true }).setView(QC_CENTER, 13);

        basicTileLayer = L.tileLayer(
            'https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=' + TOMTOM_API_KEY,
            { attribution: '© TomTom', maxZoom: 18 }
        ).addTo(map);

        trafficLayer = L.tileLayer(
            'https://api.tomtom.com/traffic/map/4/tile/flow/relative0/{z}/{x}/{y}.png?view=Unified&key=' + TOMTOM_API_KEY,
            { attribution: '© TomTom Traffic', opacity: 0.7, maxZoom: 18 }
        ).addTo(map);
        trafficVisible = true;
        setTrafficBtnStyle(true);

        var geo = window.QC_GEOJSON;
        if (geo && geo.coordinates) {
            var QC_BOUNDARY_DATA = geo.type === 'MultiPolygon' ? geo.coordinates : [geo.coordinates];
            var outer = QC_BOUNDARY_DATA[0] && QC_BOUNDARY_DATA[0][0]
                ? QC_BOUNDARY_DATA[0][0].map(function (p) { return [p[1], p[0]]; })
                : null;
            if (outer) {
                var poly = L.polygon(outer, {
                    color: '#3762c8', weight: 2, opacity: 0.8, fillOpacity: 0.08, fillColor: '#3762c8'
                }).addTo(map);
                qcMaxBounds = poly.getBounds().pad(0.15);
                if (!ncrMaxBounds) {
                    ncrMaxBounds = L.latLngBounds([14.32, 120.90], [14.80, 121.15]);
                }
                map.setMinZoom(11);
                map.setMaxZoom(18);
                setMapFence('qc');
                map.on('moveend', function () {
                    var bounds = mapFenceMode === 'ncr' ? ncrMaxBounds : qcMaxBounds;
                    if (!bounds) return;
                    var center = map.getCenter();
                    if (!bounds.contains(center)) {
                        if (mapFenceMode === 'ncr') {
                            map.panInsideBounds(bounds, { animate: true });
                        } else {
                            map.setView(QC_CENTER, 13);
                        }
                    }
                });
            }
        }

        fetch(CONFIG.DISTRICTS_URL)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                L.geoJSON(data, {
                    style: function (feature) {
                        var colors = { 1: '#3b82f6', 2: '#8b5cf6', 3: '#10b981', 4: '#f59e0b', 5: '#ef4444', 6: '#06b6d4' };
                        var dNum = parseInt((feature.properties.district_number || feature.properties.district || '').replace(/\D/g, ''), 10) || 1;
                        return {
                            color: colors[dNum] || '#3762c8', weight: 1.5, opacity: 0.6,
                            fillOpacity: 0.04, fillColor: colors[dNum] || '#3762c8', dashArray: '5,5'
                        };
                    },
                    onEachFeature: function (feature, layer) {
                        layer.bindTooltip(feature.properties.district_name || feature.properties.district, {
                            sticky: true, className: 'district-tooltip'
                        });
                    }
                }).addTo(map);
            })
            .catch(function () { /* districts optional */ });

        mapInitialized = true;
        setTimeout(function () { map.invalidateSize(); }, 50);

        var searchInput = document.getElementById('publicMapSearchInput');
        if (searchInput && !searchInput._publicGisBound) {
            searchInput._publicGisBound = true;
            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') doMapSearch();
            });
        }
    }

    function anyToolPanelOpen() {
        return PANEL_IDS.some(function (id) {
            var el = document.getElementById(id);
            return el && el.style.display === 'block';
        });
    }

    function setMapFence(mode) {
        if (!map) return;
        mapFenceMode = mode === 'ncr' ? 'ncr' : 'qc';
        var bounds = mapFenceMode === 'ncr' ? ncrMaxBounds : qcMaxBounds;
        if (!bounds) return;
        map.setMaxBounds(bounds);
        map.options.maxBoundsViscosity = 0.85;
        try {
            map.panInsideBounds(bounds, { animate: false });
        } catch (e) { /* ignore */ }
    }

    /** Wider NCR fence while a tool panel is open (map is shorter); QC again when closed. */
    function syncMapFenceFromPanels() {
        if (!map) return;
        setTimeout(function () {
            if (!map) return;
            map.invalidateSize({ animate: false, pan: false });
            setMapFence(anyToolPanelOpen() ? 'ncr' : 'qc');
        }, 80);
    }

    function setTrafficBtnStyle(on) {
        var btn = document.getElementById('publicToggleTrafficBtn');
        if (!btn) return;
        if (on) {
            btn.style.background = 'rgba(55,98,200,0.1)';
            btn.style.color = '#3762c8';
            btn.style.borderColor = 'rgba(55,98,200,0.3)';
        } else {
            btn.style.background = '#6c757d';
            btn.style.color = '#fff';
            btn.style.borderColor = '#6c757d';
        }
    }

    function toggleTrafficLayer() {
        if (!map || !trafficLayer) return;
        trafficVisible = !trafficVisible;
        if (trafficVisible) {
            trafficLayer.addTo(map);
            setTrafficBtnStyle(true);
        } else {
            map.removeLayer(trafficLayer);
            setTrafficBtnStyle(false);
        }
    }

    function resetMapView() {
        if (!map) return;
        if (typeof closeAllPanels === 'function') {
            closeAllPanels({ skipFence: true });
        }
        if (searchPinMarker) {
            map.removeLayer(searchPinMarker);
            searchPinMarker = null;
        }
        if (typeof setMapFence === 'function') {
            setMapFence('qc');
        }
        map.setView(QC_CENTER, 13);
        map.invalidateSize({ animate: false, pan: false });
    }

    function openOverlay() {
        var overlay = document.getElementById('publicGisOverlay');
        if (!overlay) return;
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.classList.add('public-gis-open');
        overlayOpen = true;
        initMap();
        if (!openOverlay._layersStarted) {
            openOverlay._layersStarted = true;
            if (typeof scheduleLayerPrefetch === 'function') scheduleLayerPrefetch();
            if (typeof ensureOsmRoutesLoaded === 'function') ensureOsmRoutesLoaded(true);
        }
        setTimeout(function () {
            resetMapView();
        }, 200);
        var closeBtn = document.getElementById('publicGisCloseBtn');
        if (closeBtn) closeBtn.focus();
    }

    function closeOverlay() {
        var overlay = document.getElementById('publicGisOverlay');
        if (!overlay) return;
        var sync = document.getElementById('publicSyncLayersOverlay');
        if (sync && sync.classList.contains('is-open')) {
            var footer = document.getElementById('publicSyncLayersFooter');
            if (footer && !footer.classList.contains('is-visible')) return;
        }
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('public-gis-open');
        overlayOpen = false;
        if (typeof closeAllPanels === 'function') closeAllPanels();
    }

    // ===== Extracted TomTom / layer features (adapted IDs) =====
    // ===== TOMTOM API FEATURES =====

    let routeFromPoint = null, routeToPoint = null;
    let routeLayer = null, satelliteLayer = null, incidentsLayer = null;
    let accidentsLayer = null;
    let accidentsVisible = false;
    let busStopsLayer = null;
    let busStopsVisible = false;
    let railStationsLayer = null;
    let railStationsVisible = false;
    let busRoutesLayer = null;
    let selectedOsmRouteId = null;
    let evMarkersLayer = null, rangeLayer = null;
    let toolsDropdownOpen = false;
    let mapClickHandler = null;
    let commuteFrom = null, commuteTo = null;
    let commuteMarkersLayer = null;

    // Tools dropdown
    function toggleToolsDropdown() {
        const menu = document.getElementById('publicToolsDropdownMenu');
        toolsDropdownOpen = !toolsDropdownOpen;
        menu.style.display = toolsDropdownOpen ? 'block' : 'none';
    }
    

    function closePanel(panelId) {
        var el = document.getElementById(panelId);
        if (el) el.style.display = 'none';
        if (panelId === 'publicPtRoutesPanel') setPtRoutesBtnStyle(false);
        if (panelId === 'publicCommutePlannerPanel') clearCommutePlannerState(false);
        if (mapClickHandler) {
            map.off('click', mapClickHandler);
            mapClickHandler = null;
        }
        syncMapFenceFromPanels();
    }

    // ===== SEARCH / GEOCODING =====
    function doMapSearch() {
        const q = document.getElementById('publicMapSearchInput').value.trim();
        if (!q) return;
        const resultsDiv = document.getElementById('publicMapSearchResults');

        TomTomServices.poiSearch(q, { limit: 10 }).then(data => {
            if (!data.success || !data.data || !data.data.results) {
                resultsDiv.style.display = 'none';
                return;
            }
            const results = data.data.results;
            if (results.length > 0 && results[0].position) {
                flyToLocation(results[0].position.lat, results[0].position.lon, 15);
            }
            resultsDiv.innerHTML = results.map(r => {
                const pos = r.position || {};
                return `<div class="search-result-item" onclick="window.PublicGisMap.flyToLocation(${pos.lat || 0}, ${pos.lon || 0}, 15)">
                    <i class="fas fa-map-pin" style="color:#3762c8;margin-right:6px;"></i>${r.poi?.name || r.address?.freeformAddress || 'Unknown'}
                    <small>${r.address?.freeformAddress || ''}</small>
                </div>`;
            }).join('');
            resultsDiv.style.display = 'block';
        });
    }

    

    

    function flyToLocation(lat, lng, zoom) {
        map.setView([lat, lng], zoom || 14);
        var resultsDiv = document.getElementById('publicMapSearchResults');
        if (resultsDiv) resultsDiv.style.display = 'none';
        if (searchPinMarker) {
            map.removeLayer(searchPinMarker);
            searchPinMarker = null;
        }
        searchPinMarker = L.marker([lat, lng]).addTo(map);
    }

    // ===== ROUTE PLANNER =====
    function showRoutePlanner() {
        closeAllPanels({ skipFence: true });
        document.getElementById('publicRoutePlannerPanel').style.display = 'block';
        syncMapFenceFromPanels();
        showNotification('Click on the map to set start point, then destination', 'info');
        routeFromPoint = null;
        routeToPoint = null;
        clearRoute();
        // Set map click to set start point
        if (mapClickHandler) map.off('click', mapClickHandler);
        mapClickHandler = function(e) {
            if (!routeFromPoint) {
                routeFromPoint = e.latlng;
                document.getElementById('publicRouteFrom').value = e.latlng.lat.toFixed(5) + ', ' + e.latlng.lng.toFixed(5);
                L.circleMarker(e.latlng, { color: '#10b981', radius: 8, fillOpacity: 0.8 }).addTo(map).bindPopup('Start').openPopup();
                showNotification('Now click destination point', 'info');
            } else if (!routeToPoint) {
                routeToPoint = e.latlng;
                document.getElementById('publicRouteTo').value = e.latlng.lat.toFixed(5) + ', ' + e.latlng.lng.toFixed(5);
                L.circleMarker(e.latlng, { color: '#ef4444', radius: 8, fillOpacity: 0.8 }).addTo(map).bindPopup('End').openPopup();
                map.off('click', mapClickHandler);
                mapClickHandler = null;
                planRoute();
            }
        };
        map.on('click', mapClickHandler);
    }

    function routeFromClick() {
        if (mapClickHandler) map.off('click', mapClickHandler);
        routeFromPoint = null;
        document.getElementById('publicRouteFrom').value = '';
        mapClickHandler = function(e) {
            routeFromPoint = e.latlng;
            document.getElementById('publicRouteFrom').value = e.latlng.lat.toFixed(5) + ', ' + e.latlng.lng.toFixed(5);
            L.circleMarker(e.latlng, { color: '#10b981', radius: 8, fillOpacity: 0.8 }).addTo(map).bindPopup('Start').openPopup();
            map.off('click', mapClickHandler);
            mapClickHandler = null;
        };
        map.on('click', mapClickHandler);
    }

    function routeToClick() {
        if (mapClickHandler) map.off('click', mapClickHandler);
        routeToPoint = null;
        document.getElementById('publicRouteTo').value = '';
        mapClickHandler = function(e) {
            routeToPoint = e.latlng;
            document.getElementById('publicRouteTo').value = e.latlng.lat.toFixed(5) + ', ' + e.latlng.lng.toFixed(5);
            L.circleMarker(e.latlng, { color: '#ef4444', radius: 8, fillOpacity: 0.8 }).addTo(map).bindPopup('End').openPopup();
            map.off('click', mapClickHandler);
            mapClickHandler = null;
        };
        map.on('click', mapClickHandler);
    }

    function planRoute() {
        const fromText = document.getElementById('publicRouteFrom').value.trim();
        const toText = document.getElementById('publicRouteTo').value.trim();
        const mode = document.getElementById('publicRouteMode').value;

        // Try to parse lat,lng or geocode
        const fromMatch = fromText.match(/^(-?\d+\.?\d*),\s*(-?\d+\.?\d*)$/);
        const toMatch = toText.match(/^(-?\d+\.?\d*),\s*(-?\d+\.?\d*)$/);

        if (!fromMatch && !routeFromPoint) { showNotification('Please set a start location', 'error'); return; }
        if (!toMatch && !routeToPoint) { showNotification('Please set a destination', 'error'); return; }

        const fromLat = routeFromPoint ? routeFromPoint.lat : parseFloat(fromMatch[1]);
        const fromLng = routeFromPoint ? routeFromPoint.lng : parseFloat(fromMatch[2]);
        const toLat = routeToPoint ? routeToPoint.lat : parseFloat(toMatch[1]);
        const toLng = routeToPoint ? routeToPoint.lng : parseFloat(toMatch[2]);

        const routes = mode === 'truck' ? TomTomServices.extendedRoute(fromLat, fromLng, toLat, toLng, { vehicleCommercial: 'true' })
            : mode === 'pedestrian' ? TomTomServices.extendedRoute(fromLat, fromLng, toLat, toLng, { travelMode: 'pedestrian' })
            : mode === 'bicycle' ? TomTomServices.extendedRoute(fromLat, fromLng, toLat, toLng, { travelMode: 'bicycle' })
            : TomTomServices.calculateRoute(fromLat, fromLng, toLat, toLng);

        routes.then(data => {
            if (!data.success || !data.data) {
                showNotification('Route calculation failed', 'error');
                return;
            }
            const route = data.data;
            const summary = route.routes?.[0]?.summary;
            if (summary) {
                const distKm = (summary.lengthInMeters / 1000).toFixed(1);
                const timeMin = Math.round(summary.travelTimeInSeconds / 60);
                document.getElementById('publicRouteInfo').style.display = 'block';
                document.getElementById('publicRouteInfo').innerHTML =
                    `<strong>Route Summary</strong><br>
                    Distance: ${distKm} km<br>
                    Duration: ${timeMin} min<br>
                    Mode: ${mode}`;

                if (route.routes[0].legs) {
                    drawRoutePolyline(route.routes[0]);
                }
            } else {
                showNotification('No route found', 'info');
            }
        });
    }

    function drawRoutePolyline(routeData) {
        if (routeLayer) map.removeLayer(routeLayer);
        try {
            const points = [];
            if (routeData.legs) {
                routeData.legs.forEach(leg => {
                    // v3 API uses leg.path.coordinates [lng, lat] arrays
                    if (leg.path && leg.path.coordinates) {
                        leg.path.coordinates.forEach(c => points.push([c[1], c[0]]));
                    // v1 API uses leg.points[].latitude / .longitude
                    } else if (leg.points) {
                        leg.points.forEach(p => points.push([p.latitude, p.longitude]));
                    }
                });
            }
            // Fallback: check for path at route level
            if (points.length === 0 && routeData.path && routeData.path.coordinates) {
                routeData.path.coordinates.forEach(c => points.push([c[1], c[0]]));
            }
            if (points.length > 0) {
                routeLayer = L.polyline(points, { color: '#3762c8', weight: 4, opacity: 0.7 }).addTo(map);
                map.fitBounds(routeLayer.getBounds().pad(0.1));
            }
        } catch (e) {
            console.error('Draw route error:', e);
        }
    }

    function clearRoute() {
        if (routeLayer) { map.removeLayer(routeLayer); routeLayer = null; }
        document.getElementById('publicRouteInfo').style.display = 'none';
        document.getElementById('publicRouteFrom').value = '';
        document.getElementById('publicRouteTo').value = '';
        routeFromPoint = null;
        routeToPoint = null;
    }

    // ===== TOMTOM INCIDENT HELPERS =====
    const INCIDENT_CATEGORY_LABELS = {
        0: 'Unknown', 1: 'Accident', 2: 'Fog', 3: 'Dangerous Conditions',
        4: 'Rain', 5: 'Ice', 6: 'Jam', 7: 'Lane Closed', 8: 'Road Closed',
        9: 'Road Works', 10: 'Wind', 11: 'Flooding', 14: 'Broken Down Vehicle'
    };

    function collectTomTomIncidents(payload) {
        if (!payload) return [];
        if (Array.isArray(payload.incidents)) return payload.incidents;
        if (payload.tm && Array.isArray(payload.tm.poi)) return payload.tm.poi;
        if (payload.data) return collectTomTomIncidents(payload.data);
        return [];
    }

    function incidentLatLng(inc) {
        const geom = inc.geometry || {};
        let coords = geom.coordinates;
        if (Array.isArray(coords) && coords.length) {
            if (typeof coords[0] === 'number') {
                return [coords[1], coords[0]];
            }
            if (Array.isArray(coords[0]) && typeof coords[0][0] === 'number') {
                const mid = coords[Math.floor(coords.length / 2)];
                return [mid[1], mid[0]];
            }
            if (Array.isArray(coords[0]) && Array.isArray(coords[0][0])) {
                const ring = coords[0];
                const mid = ring[Math.floor(ring.length / 2)];
                return [mid[1], mid[0]];
            }
        }
        if (inc.p && inc.p.x != null && inc.p.y != null) return [inc.p.y, inc.p.x];
        const point = geom.point || inc.properties?.geometryCoordinates;
        if (point) {
            const lat = point.lat || point.latitude;
            const lng = point.lon || point.lng || point.longitude;
            if (lat != null && lng != null) return [lat, lng];
        }
        return null;
    }

    function incidentCategory(inc) {
        const props = inc.properties || inc;
        const cat = props.iconCategory ?? props.ic;
        if (cat === 1 || cat === '1' || String(cat).toLowerCase() === 'accident') return 1;
        const events = props.events || [];
        for (let i = 0; i < events.length; i++) {
            if (events[i].iconCategory === 1 || /accident/i.test(events[i].description || '')) return 1;
        }
        const n = parseInt(cat, 10);
        return isNaN(n) ? 0 : n;
    }

    function incidentPopupHtml(inc) {
        const props = inc.properties || inc;
        const cat = incidentCategory(inc);
        const events = props.events || [];
        const desc = events.map(function(e) { return e.description; }).filter(Boolean).join(' — ')
            || INCIDENT_CATEGORY_LABELS[cat]
            || 'Traffic incident';
        const from = props.from || '';
        const to = props.to || '';
        const delayMin = props.delay ? Math.round(props.delay / 60) : null;
        let html = '<b>' + escapeHtml(desc) + '</b>';
        html += '<br><small>' + escapeHtml(INCIDENT_CATEGORY_LABELS[cat] || 'Incident') + '</small>';
        if (from || to) {
            html += '<br><small>' + escapeHtml([from, to].filter(Boolean).join(' → ')) + '</small>';
        }
        if (delayMin) html += '<br><small>Delay: ' + delayMin + ' min</small>';
        return html;
    }

    function incidentStyle(cat) {
        if (cat === 1) return { color: '#dc2626', css: 'cat-accident', icon: 'car-crash' };
        if (cat === 8 || cat === 7) return { color: '#111827', css: 'cat-closed', icon: 'ban' };
        if (cat === 6) return { color: '#f59e0b', css: 'cat-jam', icon: 'traffic-light' };
        if (cat === 9) return { color: '#ca8a04', css: 'cat-works', icon: 'helmet-safety' };
        return { color: '#6b7280', css: 'cat-other', icon: 'exclamation' };
    }

    function incidentLineLatLngs(inc) {
        const geom = inc.geometry || {};
        const coords = geom.coordinates;
        if (geom.type === 'LineString' && Array.isArray(coords) && coords.length) {
            return coords.map(function(c) { return [c[1], c[0]]; });
        }
        return null;
    }

    // Server file cache for Incidents / Bus / Rail / PT Routes (no TTL — Sync Layers refreshes)
    const MAP_LAYERS_CACHE_API = CONFIG.MAP_LAYERS_CACHE_API;
    const LAYER_CACHE_STORAGE_KEY = 'qc_map_layer_cache_v2';
    const LAYER_RENDER_CHUNK = 35;
    const layerCaches = {
        incidents: { fetchedAt: 0, items: null, loading: false },
        bus: { fetchedAt: 0, items: null, loading: false },
        rail: { fetchedAt: 0, items: null, loading: false },
        osmRoutes: { fetchedAt: 0, items: null, loading: false }
    };
    const TOGGLE_BTN_LABELS = {
        publicToggleAccidentsBtn: '<i class="fas fa-exclamation-triangle"></i> Incidents',
        publicToggleBusStopsBtn: '<i class="fas fa-bus"></i> Bus',
        publicToggleRailStationsBtn: '<i class="fas fa-train"></i> Rail',
        publicTogglePtRoutesBtn: '<i class="fas fa-route"></i> PT Routes',
        publicSyncMapLayersBtn: '<i class="fas fa-sync-alt"></i> Sync Layers'
    };
    // Cancel mid-flight chunked paints when the user toggles a layer off
    let accidentRenderGen = 0;

    function hasLayerCache(cache) {
        return Array.isArray(cache.items);
    }
    function isLayerCacheFresh(cache) {
        // File-backed: memory/session copy is good until Sync Layers
        return hasLayerCache(cache);
    }

    // Yield so Leaflet pin paints / JSON work don't freeze the UI thread
    function yieldToMain() {
        return new Promise(function(resolve) {
            if (typeof requestAnimationFrame === 'function') {
                requestAnimationFrame(function() { setTimeout(resolve, 0); });
            } else {
                setTimeout(resolve, 0);
            }
        });
    }

    function mapOverChunks(items, eachFn, shouldContinue, chunkSize) {
        items = items || [];
        chunkSize = chunkSize || LAYER_RENDER_CHUNK;
        let i = 0;
        let count = 0;
        function step() {
            if (typeof shouldContinue === 'function' && !shouldContinue()) {
                return Promise.resolve(count);
            }
            const end = Math.min(i + chunkSize, items.length);
            for (; i < end; i++) {
                if (eachFn(items[i], i)) count++;
            }
            if (i >= items.length) return Promise.resolve(count);
            return yieldToMain().then(step);
        }
        return step();
    }

    function loadLayerCachesFromStorage() {
        try {
            const raw = localStorage.getItem(LAYER_CACHE_STORAGE_KEY);
            if (!raw) return;
            const stored = JSON.parse(raw);
            ['incidents', 'bus', 'rail'].forEach(function(key) {
                const entry = stored && stored[key];
                if (!entry || !Array.isArray(entry.items) || !entry.fetchedAt) return;
                layerCaches[key].items = entry.items;
                layerCaches[key].fetchedAt = entry.fetchedAt;
            });
            // osmRoutes uses IndexedDB — see loadOsmRoutesFromIdb / saveOsmRoutesToIdb
        } catch (e) { /* ignore corrupt/quota errors */ }
    }
    function saveLayerCacheToStorage(key) {
        if (key === 'osmRoutes') {
            saveOsmRoutesToIdb();
            return;
        }
        setTimeout(function() {
            try {
                let stored = {};
                try {
                    stored = JSON.parse(localStorage.getItem(LAYER_CACHE_STORAGE_KEY) || '{}') || {};
                } catch (e) {
                    stored = {};
                }
                const cache = layerCaches[key];
                if (!hasLayerCache(cache) || !cache.fetchedAt) return;
                stored[key] = { fetchedAt: cache.fetchedAt, items: cache.items };
                localStorage.setItem(LAYER_CACHE_STORAGE_KEY, JSON.stringify(stored));
            } catch (e) { /* ignore quota errors */ }
        }, 0);
    }

    function clearClientLayerCaches() {
        ['incidents', 'bus', 'rail', 'osmRoutes'].forEach(function(key) {
            layerCaches[key].items = null;
            layerCaches[key].fetchedAt = 0;
            layerCaches[key].loading = false;
        });
        try { localStorage.removeItem(LAYER_CACHE_STORAGE_KEY); } catch (e) { /* ignore */ }
        try { localStorage.removeItem('qc_map_layer_cache_v1'); } catch (e) { /* ignore */ }
        openOsmRoutesIdb().then(function(db) {
            return new Promise(function(resolve, reject) {
                const tx = db.transaction(OSM_ROUTES_IDB_STORE, 'readwrite');
                tx.objectStore(OSM_ROUTES_IDB_STORE).delete('osmRoutes');
                tx.oncomplete = function() { resolve(true); };
                tx.onerror = function() { reject(tx.error); };
            });
        }).catch(function() { /* ignore */ });
    }

    // OSM routes are too large for localStorage; IndexedDB keeps the 1h client cache across refresh
    const OSM_ROUTES_IDB_NAME = 'qc_map_osm_routes_v1';
    const OSM_ROUTES_IDB_STORE = 'layers';
    function openOsmRoutesIdb() {
        return new Promise(function(resolve, reject) {
            if (!window.indexedDB) {
                reject(new Error('IndexedDB unavailable'));
                return;
            }
            const req = indexedDB.open(OSM_ROUTES_IDB_NAME, 1);
            req.onupgradeneeded = function() {
                const db = req.result;
                if (!db.objectStoreNames.contains(OSM_ROUTES_IDB_STORE)) {
                    db.createObjectStore(OSM_ROUTES_IDB_STORE);
                }
            };
            req.onsuccess = function() { resolve(req.result); };
            req.onerror = function() { reject(req.error || new Error('IndexedDB open failed')); };
        });
    }
    function loadOsmRoutesFromIdb() {
        return openOsmRoutesIdb().then(function(db) {
            return new Promise(function(resolve, reject) {
                const tx = db.transaction(OSM_ROUTES_IDB_STORE, 'readonly');
                const req = tx.objectStore(OSM_ROUTES_IDB_STORE).get('osmRoutes');
                req.onsuccess = function() {
                    db.close();
                    resolve(req.result || null);
                };
                req.onerror = function() {
                    db.close();
                    reject(req.error);
                };
            });
        }).then(function(entry) {
            if (!entry || !Array.isArray(entry.items) || !entry.fetchedAt) return false;
            layerCaches.osmRoutes.items = entry.items;
            layerCaches.osmRoutes.fetchedAt = entry.fetchedAt;
            return true;
        }).catch(function() { return false; });
    }
    function saveOsmRoutesToIdb() {
        const cache = layerCaches.osmRoutes;
        if (!hasLayerCache(cache) || !cache.fetchedAt) return Promise.resolve();
        return openOsmRoutesIdb().then(function(db) {
            return new Promise(function(resolve, reject) {
                const tx = db.transaction(OSM_ROUTES_IDB_STORE, 'readwrite');
                tx.objectStore(OSM_ROUTES_IDB_STORE).put({
                    fetchedAt: cache.fetchedAt,
                    items: cache.items
                }, 'osmRoutes');
                tx.oncomplete = function() {
                    db.close();
                    resolve();
                };
                tx.onerror = function() {
                    db.close();
                    reject(tx.error);
                };
            });
        }).catch(function() { /* quota / private mode */ });
    }
    // Parse localStorage off the critical path so a large cache doesn't freeze first paint
    const layerCacheHydrated = new Promise(function(resolve) {
        setTimeout(function() {
            loadLayerCachesFromStorage();
            resolve();
        }, 0);
    });
    // Resolve before any OSM fetch so refresh can reuse IndexedDB within 1h
    const osmRoutesIdbReady = loadOsmRoutesFromIdb();

    function setToggleLoading(btnId, loading, restoreStyleFn) {
        const btn = document.getElementById(btnId);
        if (!btn) return;
        btn.classList.toggle('is-loading', !!loading);
        btn.disabled = !!loading;
        if (loading) {
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading';
            return;
        }
        btn.innerHTML = TOGGLE_BTN_LABELS[btnId] || btn.innerHTML;
        if (typeof restoreStyleFn === 'function') restoreStyleFn();
    }

    function renderAccidentPinsFromData(incidents) {
        const gen = ++accidentRenderGen;
        if (accidentsLayer) {
            map.removeLayer(accidentsLayer);
            accidentsLayer = null;
        }
        accidentsLayer = L.layerGroup().addTo(map);
        const layer = accidentsLayer;

        return mapOverChunks(incidents, function(inc) {
            if (gen !== accidentRenderGen || !accidentsVisible || layer !== accidentsLayer) return false;
            const pos = incidentLatLng(inc);
            if (!pos || pos[0] == null || pos[1] == null) return false;
            if (typeof isInsideQCBounds === 'function' && !isInsideQCBounds(pos[0], pos[1])) return false;
            const cat = incidentCategory(inc);
            const style = incidentStyle(cat);
            const popup = incidentPopupHtml(inc);
            const line = incidentLineLatLngs(inc);
            if (line && line.length > 1) {
                L.polyline(line, { color: style.color, weight: 5, opacity: 0.85 })
                    .bindPopup(popup)
                    .addTo(layer);
            }
            const icon = L.divIcon({
                html: '<div class="incident-map-pin ' + style.css + '"><i class="fas fa-' + style.icon + '"></i></div>',
                className: '',
                iconSize: [28, 28],
                iconAnchor: [14, 14]
            });
            L.marker(pos, { icon: icon, zIndexOffset: 600 })
                .bindPopup(popup)
                .addTo(layer);
            return true;
        }, function() {
            return gen === accidentRenderGen && accidentsVisible && layer === accidentsLayer;
        });
    }

    function fetchAccidentIncidents() {
        return fetch(MAP_LAYERS_CACHE_API + '?layer=incidents', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.success) {
                    throw new Error((data && data.error) || 'Could not load traffic incidents');
                }
                const payload = data.data || {};
                if (Array.isArray(payload.items)) return payload.items;
                return collectTomTomIncidents(payload);
            });
    }

    function loadAccidentPins(silent) {
        const cache = layerCaches.incidents;
        let showedCache = false;
        let paintPromise = Promise.resolve(0);

        if (accidentsVisible && hasLayerCache(cache)) {
            setToggleLoading('publicToggleAccidentsBtn', true);
            paintPromise = renderAccidentPinsFromData(cache.items).then(function(count) {
                showedCache = true;
                setToggleLoading('publicToggleAccidentsBtn', false, function() {
                    setAccidentToggleStyle(accidentsVisible);
                });
                if (!silent && isLayerCacheFresh(cache)) {
                    showNotification(count ? (count + ' live incident' + (count === 1 ? '' : 's') + ' on the map') : 'No live traffic incidents in Quezon City', 'info');
                }
                return count;
            });
        }
        if (isLayerCacheFresh(cache) || cache.loading) return paintPromise;

        cache.loading = true;
        setToggleLoading('publicToggleAccidentsBtn', true);
        return paintPromise.then(function() {
            return fetchAccidentIncidents();
        }).then(function(incidents) {
            cache.items = incidents;
            cache.fetchedAt = Date.now();
            cache.loading = false;
            saveLayerCacheToStorage('incidents');
            if (!accidentsVisible) {
                setToggleLoading('publicToggleAccidentsBtn', false, function() {
                    setAccidentToggleStyle(accidentsVisible);
                });
                return 0;
            }
            return renderAccidentPinsFromData(incidents).then(function(count) {
                setToggleLoading('publicToggleAccidentsBtn', false, function() {
                    setAccidentToggleStyle(accidentsVisible);
                });
                if (!silent && !showedCache) {
                    showNotification(count ? (count + ' live incident' + (count === 1 ? '' : 's') + ' on the map') : 'No live traffic incidents in Quezon City', 'info');
                }
                return count;
            });
        }).catch(function(err) {
            cache.loading = false;
            setToggleLoading('publicToggleAccidentsBtn', false, function() {
                setAccidentToggleStyle(accidentsVisible);
            });
            if (!silent && !showedCache && accidentsVisible) {
                showNotification(err.message || 'Could not load traffic incidents', 'error');
            }
        });
    }
    window.loadAccidentPins = loadAccidentPins;

    function setAccidentToggleStyle(on) {
        const btn = document.getElementById('publicToggleAccidentsBtn');
        if (!btn || btn.classList.contains('is-loading')) return;
        if (on) {
            btn.style.background = 'rgba(220,38,38,0.1)';
            btn.style.color = '#dc2626';
            btn.style.borderColor = 'rgba(220,38,38,0.3)';
        } else {
            btn.style.background = '#6c757d';
            btn.style.color = '#fff';
            btn.style.borderColor = '#6c757d';
        }
    }

    function toggleAccidentPins() {
        accidentsVisible = !accidentsVisible;
        setAccidentToggleStyle(accidentsVisible);
        if (!accidentsVisible) {
            accidentRenderGen++;
            if (accidentsLayer) {
                map.removeLayer(accidentsLayer);
                accidentsLayer = null;
            }
            showNotification('Incident pins hidden', 'info');
            return;
        }
        loadAccidentPins(false);
    }

    // ===== BUS / RAIL TRANSIT POIs (TomTom categorySet) =====
    const TOMTOM_BUS_CATEGORY = '9942002';
    const TOMTOM_RAIL_CATEGORY = '7380';
    const TRANSIT_POI_CENTERS = [
        [14.651417, 121.04917],
        [14.705, 121.05],
        [14.60, 121.05],
        [14.65, 121.015],
        [14.65, 121.09],
        [14.68, 121.075],
        [14.62, 121.03]
    ];

    function transitPoiPosition(poi) {
        const pos = poi && poi.position;
        if (!pos || pos.lat == null || pos.lon == null) return null;
        return [pos.lat, pos.lon];
    }

    function transitPoiPopupHtml(poi, kindLabel) {
        const name = (poi.poi && poi.poi.name) || kindLabel;
        const addr = (poi.address && (poi.address.freeformAddress || poi.address.streetName)) || '';
        const cats = (poi.poi && poi.poi.categories) ? poi.poi.categories.join(', ') : '';
        return '<strong>' + name + '</strong><br>' +
            (addr ? addr + '<br>' : '') +
            (cats ? '<span style="color:#6b7280;font-size:11px;">' + cats + '</span><br>' : '') +
            '<span style="color:#6b7280;font-size:11px;">' + kindLabel + '</span>';
    }

    function fetchTransitPois(categorySet) {
        const layer = (categorySet === TOMTOM_RAIL_CATEGORY) ? 'rail' : 'bus';
        return fetch(MAP_LAYERS_CACHE_API + '?layer=' + encodeURIComponent(layer), { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.success) {
                    throw new Error((data && data.error) || 'Could not load transit stops');
                }
                const payload = data.data || {};
                return Array.isArray(payload.items) ? payload.items : [];
            });
    }

    function renderTransitPins(pois, cssClass, iconName, kindLabel, opts) {
        opts = opts || {};
        const genRef = opts.genRef;
        const isVisible = opts.isVisible;
        const setLayer = opts.setLayer;
        const getLayer = opts.getLayer;
        const gen = genRef ? (++genRef.value) : 0;

        const existing = typeof getLayer === 'function' ? getLayer() : null;
        if (existing) {
            map.removeLayer(existing);
            if (typeof setLayer === 'function') setLayer(null);
        }
        const layer = L.layerGroup().addTo(map);
        if (typeof setLayer === 'function') setLayer(layer);

        return mapOverChunks(pois, function(poi) {
            if (genRef && gen !== genRef.value) return false;
            if (typeof isVisible === 'function' && !isVisible()) return false;
            if (typeof getLayer === 'function' && getLayer() !== layer) return false;
            const pos = transitPoiPosition(poi);
            if (!pos) return false;
            if (typeof isInsideQCBounds === 'function' && !isInsideQCBounds(pos[0], pos[1])) return false;
            const icon = L.divIcon({
                html: '<div class="transit-map-pin ' + cssClass + '"><i class="fas fa-' + iconName + '"></i></div>',
                className: '',
                iconSize: [26, 26],
                iconAnchor: [13, 13]
            });
            L.marker(pos, { icon: icon, zIndexOffset: 500 })
                .bindPopup(transitPoiPopupHtml(poi, kindLabel))
                .addTo(layer);
            return true;
        }, function() {
            if (genRef && gen !== genRef.value) return false;
            if (typeof isVisible === 'function' && !isVisible()) return false;
            if (typeof getLayer === 'function' && getLayer() !== layer) return false;
            return true;
        }).then(function(count) {
            return { layer: layer, count: count };
        });
    }

    const busRenderToken = { value: 0 };
    const railRenderToken = { value: 0 };

    function setBusToggleStyle(on) {
        const btn = document.getElementById('publicToggleBusStopsBtn');
        if (!btn || btn.classList.contains('is-loading')) return;
        if (on) {
            btn.style.background = 'rgba(2,132,199,0.1)';
            btn.style.color = '#0284c7';
            btn.style.borderColor = 'rgba(2,132,199,0.35)';
        } else {
            btn.style.background = '#6c757d';
            btn.style.color = '#fff';
            btn.style.borderColor = '#6c757d';
        }
    }

    function setRailToggleStyle(on) {
        const btn = document.getElementById('publicToggleRailStationsBtn');
        if (!btn || btn.classList.contains('is-loading')) return;
        if (on) {
            btn.style.background = 'rgba(71,85,105,0.12)';
            btn.style.color = '#475569';
            btn.style.borderColor = 'rgba(71,85,105,0.35)';
        } else {
            btn.style.background = '#6c757d';
            btn.style.color = '#fff';
            btn.style.borderColor = '#6c757d';
        }
    }

    function loadBusStopPins(silent) {
        const cache = layerCaches.bus;
        let showedCache = false;
        let paintPromise = Promise.resolve({ count: 0 });

        if (busStopsVisible && hasLayerCache(cache)) {
            setToggleLoading('publicToggleBusStopsBtn', true);
            paintPromise = renderTransitPins(cache.items, 'bus', 'bus', 'Bus stop', {
                genRef: busRenderToken,
                isVisible: function() { return busStopsVisible; },
                getLayer: function() { return busStopsLayer; },
                setLayer: function(l) { busStopsLayer = l; }
            }).then(function(rendered) {
                showedCache = true;
                setToggleLoading('publicToggleBusStopsBtn', false, function() {
                    setBusToggleStyle(busStopsVisible);
                });
                if (!silent && isLayerCacheFresh(cache)) {
                    showNotification(rendered.count ? (rendered.count + ' bus stop' + (rendered.count === 1 ? '' : 's') + ' on the map') : 'No bus stops found in Quezon City', 'info');
                }
                return rendered;
            });
        }
        if (isLayerCacheFresh(cache) || cache.loading) return paintPromise;

        cache.loading = true;
        setToggleLoading('publicToggleBusStopsBtn', true);
        return paintPromise.then(function() {
            return fetchTransitPois(TOMTOM_BUS_CATEGORY);
        }).then(function(pois) {
            cache.items = pois;
            cache.fetchedAt = Date.now();
            cache.loading = false;
            saveLayerCacheToStorage('bus');
            if (!busStopsVisible) {
                setToggleLoading('publicToggleBusStopsBtn', false, function() {
                    setBusToggleStyle(busStopsVisible);
                });
                return { count: 0 };
            }
            return renderTransitPins(pois, 'bus', 'bus', 'Bus stop', {
                genRef: busRenderToken,
                isVisible: function() { return busStopsVisible; },
                getLayer: function() { return busStopsLayer; },
                setLayer: function(l) { busStopsLayer = l; }
            }).then(function(rendered) {
                setToggleLoading('publicToggleBusStopsBtn', false, function() {
                    setBusToggleStyle(busStopsVisible);
                });
                if (!silent && !showedCache) {
                    showNotification(rendered.count ? (rendered.count + ' bus stop' + (rendered.count === 1 ? '' : 's') + ' on the map') : 'No bus stops found in Quezon City', 'info');
                }
                return rendered;
            });
        }).catch(function() {
            cache.loading = false;
            setToggleLoading('publicToggleBusStopsBtn', false, function() {
                setBusToggleStyle(busStopsVisible);
            });
            if (!silent && !showedCache && busStopsVisible) {
                showNotification('Could not load bus stops', 'error');
            }
        });
    }
    window.loadBusStopPins = loadBusStopPins;

    function loadRailStationPins(silent) {
        const cache = layerCaches.rail;
        let showedCache = false;
        let paintPromise = Promise.resolve({ count: 0 });

        if (railStationsVisible && hasLayerCache(cache)) {
            setToggleLoading('publicToggleRailStationsBtn', true);
            paintPromise = renderTransitPins(cache.items, 'rail', 'train', 'Railroad station', {
                genRef: railRenderToken,
                isVisible: function() { return railStationsVisible; },
                getLayer: function() { return railStationsLayer; },
                setLayer: function(l) { railStationsLayer = l; }
            }).then(function(rendered) {
                showedCache = true;
                setToggleLoading('publicToggleRailStationsBtn', false, function() {
                    setRailToggleStyle(railStationsVisible);
                });
                if (!silent && isLayerCacheFresh(cache)) {
                    showNotification(rendered.count ? (rendered.count + ' rail station' + (rendered.count === 1 ? '' : 's') + ' on the map') : 'No rail stations found in Quezon City', 'info');
                }
                return rendered;
            });
        }
        if (isLayerCacheFresh(cache) || cache.loading) return paintPromise;

        cache.loading = true;
        setToggleLoading('publicToggleRailStationsBtn', true);
        return paintPromise.then(function() {
            return fetchTransitPois(TOMTOM_RAIL_CATEGORY);
        }).then(function(pois) {
            cache.items = pois;
            cache.fetchedAt = Date.now();
            cache.loading = false;
            saveLayerCacheToStorage('rail');
            if (!railStationsVisible) {
                setToggleLoading('publicToggleRailStationsBtn', false, function() {
                    setRailToggleStyle(railStationsVisible);
                });
                return { count: 0 };
            }
            return renderTransitPins(pois, 'rail', 'train', 'Railroad station', {
                genRef: railRenderToken,
                isVisible: function() { return railStationsVisible; },
                getLayer: function() { return railStationsLayer; },
                setLayer: function(l) { railStationsLayer = l; }
            }).then(function(rendered) {
                setToggleLoading('publicToggleRailStationsBtn', false, function() {
                    setRailToggleStyle(railStationsVisible);
                });
                if (!silent && !showedCache) {
                    showNotification(rendered.count ? (rendered.count + ' rail station' + (rendered.count === 1 ? '' : 's') + ' on the map') : 'No rail stations found in Quezon City', 'info');
                }
                return rendered;
            });
        }).catch(function() {
            cache.loading = false;
            setToggleLoading('publicToggleRailStationsBtn', false, function() {
                setRailToggleStyle(railStationsVisible);
            });
            if (!silent && !showedCache && railStationsVisible) {
                showNotification('Could not load rail stations', 'error');
            }
        });
    }
    window.loadRailStationPins = loadRailStationPins;

    function toggleBusStopPins() {
        busStopsVisible = !busStopsVisible;
        setBusToggleStyle(busStopsVisible);
        if (!busStopsVisible) {
            busRenderToken.value++;
            if (busStopsLayer) {
                map.removeLayer(busStopsLayer);
                busStopsLayer = null;
            }
            showNotification('Bus stop pins hidden', 'info');
            return;
        }
        loadBusStopPins(false);
    }

    function toggleRailStationPins() {
        railStationsVisible = !railStationsVisible;
        setRailToggleStyle(railStationsVisible);
        if (!railStationsVisible) {
            railRenderToken.value++;
            if (railStationsLayer) {
                map.removeLayer(railStationsLayer);
                railStationsLayer = null;
            }
            showNotification('Rail station pins hidden', 'info');
            return;
        }
        loadRailStationPins(false);
    }

    // Prefetch after idle so map init / UI stay responsive; stagger layers
    function scheduleLayerPrefetch() {
        const run = function() {
            layerCacheHydrated.then(function() {
                loadAccidentPins(true);
                setTimeout(function() { loadBusStopPins(true); }, 400);
                setTimeout(function() { loadRailStationPins(true); }, 800);
            });
        };
        if (typeof requestIdleCallback === 'function') {
            requestIdleCallback(run, { timeout: 2500 });
        } else {
            setTimeout(run, 600);
        }
    }
    // scheduleLayerPrefetch called after map init

    const SYNC_LAYER_DEFS = [
        { key: 'incidents', label: 'Incidents' },
        { key: 'bus', label: 'Bus Stops' },
        { key: 'rail', label: 'Rail Stations' },
        { key: 'routes', label: 'PT Routes' }
    ];

    function openSyncLayersModal() {
        const overlay = document.getElementById('publicSyncLayersOverlay');
        const footer = document.getElementById('publicSyncLayersFooter');
        const titleIcon = document.getElementById('publicSyncLayersTitleIcon');
        const subtitle = document.getElementById('publicSyncLayersSubtitle');
        if (!overlay) return;
        SYNC_LAYER_DEFS.forEach(function(def) {
            setSyncLayerItemState(def.key, 'pending', 'Fetching…');
        });
        if (footer) footer.classList.remove('is-visible');
        if (titleIcon) titleIcon.className = 'fas fa-sync-alt fa-spin';
        if (subtitle) {
            subtitle.textContent = 'Downloading fresh data. Please wait — this window cannot be closed until sync finishes.';
        }
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
    }

    function closeSyncLayersModal() {
        const overlay = document.getElementById('publicSyncLayersOverlay');
        const footer = document.getElementById('publicSyncLayersFooter');
        if (footer && !footer.classList.contains('is-visible')) return;
        if (!overlay) return;
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
    }

    function setSyncLayerItemState(layerKey, state, statusText) {
        const item = document.querySelector('#publicSyncLayersList .sync-layers-item[data-layer="' + layerKey + '"]');
        if (!item) return;
        item.classList.remove('is-pending', 'is-done', 'is-failed');
        item.classList.add(state === 'done' ? 'is-done' : (state === 'failed' ? 'is-failed' : 'is-pending'));
        const icon = item.querySelector('.sync-layers-item-icon i');
        if (icon) {
            if (state === 'done') icon.className = 'fas fa-check';
            else if (state === 'failed') icon.className = 'fas fa-times';
            else icon.className = 'fas fa-spinner fa-spin';
        }
        const status = item.querySelector('.sync-layers-item-status');
        if (status) status.textContent = statusText || '';
    }

    function finishSyncLayersModal(results) {
        const footer = document.getElementById('publicSyncLayersFooter');
        const titleIcon = document.getElementById('publicSyncLayersTitleIcon');
        const subtitle = document.getElementById('publicSyncLayersSubtitle');
        const okCount = results.filter(function(r) { return r.ok; }).length;
        const failCount = results.length - okCount;
        if (titleIcon) titleIcon.className = failCount ? 'fas fa-exclamation-circle' : 'fas fa-check-circle';
        if (subtitle) {
            if (failCount === 0) subtitle.textContent = 'All layers synced successfully. You can close this window.';
            else if (okCount === 0) subtitle.textContent = 'Sync finished, but every layer failed. You can close this window and try again.';
            else subtitle.textContent = 'Sync finished with ' + failCount + ' failed layer(s). You can close this window.';
        }
        if (footer) footer.classList.add('is-visible');
    }

    function fetchSyncLayer(layerKey) {
        return fetch(MAP_LAYERS_CACHE_API + '?layer=' + encodeURIComponent(layerKey) + '&refresh=1', {
            credentials: 'same-origin'
        })
            .then(function(r) { return r.json().then(function(data) { return { okHttp: r.ok, data: data }; }); })
            .then(function(res) {
                const data = res.data;
                if (!res.okHttp || !data || !data.success) {
                    throw new Error((data && (data.error || data.message)) || 'Request failed');
                }
                const payload = data.data || {};
                let count = null;
                if (layerKey === 'routes') {
                    count = Array.isArray(payload.routes) ? payload.routes.length : (payload.count != null ? payload.count : null);
                } else {
                    count = Array.isArray(payload.items) ? payload.items.length : (payload.count != null ? payload.count : null);
                }
                return { key: layerKey, ok: true, count: count, error: null };
            })
            .catch(function(err) {
                return { key: layerKey, ok: false, count: null, error: (err && err.message) ? err.message : 'Failed' };
            });
    }

    function syncMapLayers() {
        const btn = document.getElementById('publicSyncMapLayersBtn');
        if (btn && btn.classList.contains('is-loading')) return;
        setToggleLoading('publicSyncMapLayersBtn', true);
        openSyncLayersModal();
        clearClientLayerCaches();

        const jobs = SYNC_LAYER_DEFS.map(function(def) {
            return fetchSyncLayer(def.key).then(function(result) {
                if (result.ok) {
                    const detail = result.count != null ? ('Done · ' + result.count + ' item(s)') : 'Done';
                    setSyncLayerItemState(result.key, 'done', detail);
                } else {
                    setSyncLayerItemState(result.key, 'failed', result.error || 'Failed');
                }
                return result;
            });
        });

        Promise.all(jobs)
            .then(function(results) {
                const reloadJobs = [];
                results.forEach(function(result) {
                    if (!result.ok) return;
                    if (result.key === 'incidents') reloadJobs.push(loadAccidentPins(true));
                    else if (result.key === 'bus') reloadJobs.push(loadBusStopPins(true));
                    else if (result.key === 'rail') reloadJobs.push(loadRailStationPins(true));
                    else if (result.key === 'routes') reloadJobs.push(ensureOsmRoutesLoaded(true));
                });
                return Promise.all(reloadJobs).then(function() { return results; });
            })
            .then(function(results) {
                finishSyncLayersModal(results || []);
                const okCount = (results || []).filter(function(r) { return r.ok; }).length;
                const failCount = (results || []).length - okCount;
                if (failCount === 0) showNotification('All map layers synced', 'success');
                else if (okCount === 0) showNotification('Map layer sync failed', 'error');
                else showNotification('Synced ' + okCount + ' layer(s), ' + failCount + ' failed', 'error');
            })
            .catch(function(err) {
                SYNC_LAYER_DEFS.forEach(function(def) {
                    const item = document.querySelector('#publicSyncLayersList .sync-layers-item[data-layer="' + def.key + '"]');
                    if (item && item.classList.contains('is-pending')) {
                        setSyncLayerItemState(def.key, 'failed', err.message || 'Failed');
                    }
                });
                finishSyncLayersModal(SYNC_LAYER_DEFS.map(function(def) {
                    return { key: def.key, ok: false };
                }));
                showNotification(err.message || 'Could not sync map layers', 'error');
            })
            .finally(function() {
                setToggleLoading('publicSyncMapLayersBtn', false, function() {
                    const syncBtn = document.getElementById('publicSyncMapLayersBtn');
                    if (!syncBtn) return;
                    syncBtn.style.background = '#3762c8';
                    syncBtn.style.color = '#fff';
                    syncBtn.style.borderColor = '#3762c8';
                });
            });
    }
    window.syncMapLayers = syncMapLayers;
    window.closeSyncLayersModal = closeSyncLayersModal;

    // ===== OSM PT ROUTES (Overpass) — list first, map on select =====
    const OSM_ROUTES_API = MAP_LAYERS_CACHE_API + '?layer=routes';
    const OSM_ROUTE_COLORS = { bus: '#dc2626', jeep: '#dc2626' };

    function osmRoutePopupHtml(route) {
        const kindLabel = route.kind === 'jeep' ? 'Jeepney route' : 'Bus / PUV route';
        const bits = [];
        if (route.ref) bits.push('Ref: ' + route.ref);
        if (route.network) bits.push(route.network);
        if (route.from || route.to) bits.push([route.from, route.to].filter(Boolean).join(' → '));
        return '<strong>' + (route.name || kindLabel) + '</strong><br>' +
            '<span style="color:#6b7280;font-size:11px;">' + kindLabel + ' · OpenStreetMap</span>' +
            (bits.length ? '<br><span style="color:#6b7280;font-size:11px;">' + bits.join(' · ') + '</span>' : '');
    }

    function setPtRoutesBtnStyle(active) {
        const btn = document.getElementById('publicTogglePtRoutesBtn');
        if (!btn || btn.classList.contains('is-loading')) return;
        if (active) {
            btn.style.background = 'rgba(220,38,38,0.12)';
            btn.style.color = '#dc2626';
            btn.style.borderColor = 'rgba(220,38,38,0.35)';
        } else {
            btn.style.background = '#6c757d';
            btn.style.color = '#fff';
            btn.style.borderColor = '#6c757d';
        }
    }

    function setOsmRoutesLoading(loading) {
        setToggleLoading('publicTogglePtRoutesBtn', loading, function() {
            const panel = document.getElementById('publicPtRoutesPanel');
            setPtRoutesBtnStyle(panel && panel.style.display === 'block');
        });
    }

    function fetchOsmRoutes() {
        return fetch(OSM_ROUTES_API, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.success) {
                    throw new Error((data && data.error) || 'Could not load OSM routes');
                }
                const payload = data.data || {};
                return {
                    routes: Array.isArray(payload.routes) ? payload.routes : [],
                    fetchedAt: payload.fetchedAt || Date.now()
                };
            });
    }

    function ensureOsmRoutesLoaded(silent) {
        return osmRoutesIdbReady.then(function() {
            const cache = layerCaches.osmRoutes;
            if (isLayerCacheFresh(cache)) {
                renderPtRouteList();
                return cache.items;
            }
            if (cache.loading) {
                return new Promise(function(resolve) {
                    const timer = setInterval(function() {
                        if (!layerCaches.osmRoutes.loading) {
                            clearInterval(timer);
                            resolve(layerCaches.osmRoutes.items || []);
                        }
                    }, 200);
                });
            }
            cache.loading = true;
            setOsmRoutesLoading(true);
            const listEl = document.getElementById('publicPtRouteList');
            if (listEl && !hasLayerCache(cache)) {
                listEl.innerHTML = '<div class="pt-route-status"><i class="fas fa-spinner fa-spin"></i> Loading routes…</div>';
            }
            return fetchOsmRoutes().then(function(result) {
                cache.items = result.routes;
                cache.fetchedAt = result.fetchedAt || Date.now();
                cache.loading = false;
                setOsmRoutesLoading(false);
                saveOsmRoutesToIdb();
                renderPtRouteList();
                if (!silent) {
                    showNotification(result.routes.length
                        ? (result.routes.length + ' OSM routes ready')
                        : 'No OSM routes found for Quezon City', 'info');
                }
                return result.routes;
            }).catch(function(err) {
                cache.loading = false;
                setOsmRoutesLoading(false);
                if (listEl) {
                    listEl.innerHTML = '<div class="pt-route-empty">' + (err.message || 'Could not load OSM routes') + '</div>';
                }
                if (!silent) showNotification(err.message || 'Could not load OSM routes', 'error');
                return [];
            });
        });
    }
    window.loadOsmRouteLines = function(silent) { ensureOsmRoutesLoaded(!!silent); };

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function renderPtRouteList() {
        const listEl = document.getElementById('publicPtRouteList');
        const metaEl = document.getElementById('publicPtRouteListMeta');
        const searchEl = document.getElementById('publicPtRouteSearch');
        if (!listEl) return;
        const cache = layerCaches.osmRoutes;
        if (cache.loading && !hasLayerCache(cache)) {
            listEl.innerHTML = '<div class="pt-route-status"><i class="fas fa-spinner fa-spin"></i> Loading routes…</div>';
            if (metaEl) metaEl.textContent = '';
            return;
        }
        if (!hasLayerCache(cache)) {
            listEl.innerHTML = '<div class="pt-route-empty">No routes loaded yet.</div>';
            if (metaEl) metaEl.textContent = '';
            return;
        }
        const q = ((searchEl && searchEl.value) || '').trim().toLowerCase();
        const routes = cache.items.slice().sort(function(a, b) {
            return String(a.name || '').localeCompare(String(b.name || ''));
        });
        const filtered = !q ? routes : routes.filter(function(r) {
            const hay = [r.name, r.from, r.to, r.ref, r.network, r.kind].join(' ').toLowerCase();
            return hay.indexOf(q) !== -1;
        });
        if (metaEl) {
            metaEl.textContent = filtered.length + ' of ' + routes.length + ' route' + (routes.length === 1 ? '' : 's');
        }
        if (!filtered.length) {
            listEl.innerHTML = '<div class="pt-route-empty">No routes match your search.</div>';
            return;
        }
        listEl.innerHTML = filtered.map(function(r) {
            const selected = String(r.id) === String(selectedOsmRouteId);
            const ends = [r.from, r.to].filter(Boolean).join(' → ');
            const metaBits = [];
            if (ends) metaBits.push(ends);
            if (r.ref) metaBits.push('Ref ' + r.ref);
            if (r.network) metaBits.push(r.network);
            const badge = r.kind === 'jeep' ? '<span class="pt-route-badge">Jeep</span>' : '';
            return '<button type="button" class="pt-route-item' + (selected ? ' is-selected' : '') + '" data-route-id="' + escapeHtml(r.id) + '" onclick="window.PublicGisMap.selectOsmRoute(' + Number(r.id) + ')">' +
                '<span class="pt-route-name">' + escapeHtml(r.name || ('Route ' + r.id)) + badge + '</span>' +
                (metaBits.length ? '<span class="pt-route-meta">' + escapeHtml(metaBits.join(' · ')) + '</span>' : '') +
                '</button>';
        }).join('');
    }
    window.renderPtRouteList = renderPtRouteList;

    function clearSelectedOsmRoute(silent) {
        selectedOsmRouteId = null;
        if (busRoutesLayer) {
            map.removeLayer(busRoutesLayer);
            busRoutesLayer = null;
        }
        renderPtRouteList();
        if (!silent) showNotification('Route cleared from map', 'info');
    }
    window.clearSelectedOsmRoute = clearSelectedOsmRoute;

    function selectOsmRoute(routeId) {
        const cache = layerCaches.osmRoutes;
        if (!hasLayerCache(cache)) {
            showNotification('Routes are still loading', 'info');
            ensureOsmRoutesLoaded(false).then(function() { selectOsmRoute(routeId); });
            return;
        }
        if (String(selectedOsmRouteId) === String(routeId)) {
            clearSelectedOsmRoute(false);
            return;
        }
        const route = cache.items.find(function(r) { return String(r.id) === String(routeId); });
        if (!route) {
            showNotification('Route not found', 'error');
            return;
        }
        if (busRoutesLayer) {
            map.removeLayer(busRoutesLayer);
            busRoutesLayer = null;
        }
        const color = OSM_ROUTE_COLORS[route.kind] || OSM_ROUTE_COLORS.bus;
        const layer = L.layerGroup().addTo(map);
        const bounds = [];
        const popup = osmRoutePopupHtml(route);
        (route.lines || []).forEach(function(line) {
            if (!line || line.length < 2) return;
            L.polyline(line, {
                color: color,
                weight: 4,
                opacity: 0.9,
                lineJoin: 'round',
                lineCap: 'round'
            }).bindPopup(popup).addTo(layer);
            line.forEach(function(pt) {
                if (pt && pt.length >= 2) bounds.push(pt);
            });
        });
        busRoutesLayer = layer;
        selectedOsmRouteId = route.id;
        renderPtRouteList();
        if (bounds.length) {
            try {
                map.fitBounds(bounds, { padding: [40, 40], maxZoom: 15 });
            } catch (e) { /* ignore invalid bounds */ }
        }
        showNotification((route.name || 'Route') + ' shown on map', 'info');
    }
    window.selectOsmRoute = selectOsmRoute;

    function showPtRoutesPanel() {
        closeAllPanels({ skipFence: true });
        document.getElementById('publicPtRoutesPanel').style.display = 'block';
        setPtRoutesBtnStyle(true);
        syncMapFenceFromPanels();
        ensureOsmRoutesLoaded(true).then(function() {
            renderPtRouteList();
        });
    }
    window.showPtRoutesPanel = showPtRoutesPanel;

    // Prefetch after IndexedDB hydrate (no network if client 1h cache is fresh)
    // ensureOsmRoutesLoaded called after map init

    // ===== EV CHARGING STATIONS =====
    let evMarkerObjects = [];
    function showEVCharging() {
        closeAllPanels({ skipFence: true });
        document.getElementById('publicEvChargingPanel').style.display = 'block';
        syncMapFenceFromPanels();
        findEVStations();
    }

    function findEVStations() {
        const center = map.getCenter();
        if (evMarkersLayer) { map.removeLayer(evMarkersLayer); evMarkersLayer = null; }

        TomTomServices.evCharging(center.lat, center.lng, { limit: 20 }).then(data => {
            const resultsDiv = document.getElementById('publicEvResults');
            if (!data.success || !data.data || !data.data.results) {
                resultsDiv.style.display = 'block';
                resultsDiv.innerHTML = 'No EV charging stations found nearby.';
                return;
            }
            const stations = data.data.results;
            evMarkersLayer = L.layerGroup().addTo(map);
            resultsDiv.style.display = 'block';
            resultsDiv.innerHTML = '<strong>' + stations.length + ' EV stations found</strong><br>';

            stations.forEach((s, i) => {
                const pos = s.position;
                if (pos) {
                    const icon = L.divIcon({
                        html: '<div style="background:#10b981;color:#fff;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-size:12px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.3);"><i class="fas fa-charging-station"></i></div>',
                        className: '', iconSize: [24, 24]
                    });
                    L.marker([pos.lat, pos.lon], { icon })
                        .bindPopup(`<b>${s.poi?.name || 'EV Station'}</b><br>${s.address?.freeformAddress || ''}`)
                        .addTo(evMarkersLayer);
                    resultsDiv.innerHTML += `${i+1}. ${s.poi?.name || 'Station'} - ${s.address?.freeformAddress || ''}<br>`;
                }
            });
        });
    }

    // ===== UTILITY =====
    function closeAllPanels(opts) {
        opts = opts || {};
        PANEL_IDS.forEach(function (id) {
            const el = document.getElementById(id);
            if (el) el.style.display = 'none';
        });
        setPtRoutesBtnStyle(false);
        clearCommutePlannerState(false);
        if (mapClickHandler) { map.off('click', mapClickHandler); mapClickHandler = null; }
        if (!opts.skipFence) syncMapFenceFromPanels();
    }

    // ===== COMMUTE PLANNER (Sakay deep link) =====
    function updateCommutePlannerUi() {
        const statusEl = document.getElementById('publicCommutePlannerStatus');
        const coordsEl = document.getElementById('publicCommutePlannerCoords');
        const openBtn = document.getElementById('publicOpenSakayTripBtn');
        if (!statusEl || !coordsEl || !openBtn) return;

        if (!commuteFrom) {
            statusEl.innerHTML = 'Click the map to set the <strong>origin</strong>.';
            coordsEl.style.display = 'none';
            openBtn.disabled = true;
            return;
        }
        if (!commuteTo) {
            statusEl.innerHTML = 'Origin set. Click the map to set the <strong>destination</strong>.';
            coordsEl.style.display = 'block';
            coordsEl.textContent = 'From: ' + commuteFrom.lat.toFixed(6) + ', ' + commuteFrom.lng.toFixed(6);
            openBtn.disabled = true;
            return;
        }
        statusEl.innerHTML = 'Origin and destination set. Open Sakay for transit directions.';
        coordsEl.style.display = 'block';
        coordsEl.innerHTML =
            'From: ' + commuteFrom.lat.toFixed(6) + ', ' + commuteFrom.lng.toFixed(6) + '<br>' +
            'To: ' + commuteTo.lat.toFixed(6) + ', ' + commuteTo.lng.toFixed(6);
        openBtn.disabled = false;
    }

    function clearCommutePlannerState(keepPanel) {
        commuteFrom = null;
        commuteTo = null;
        window.suppressMapReportPin = false;
        if (commuteMarkersLayer) {
            map.removeLayer(commuteMarkersLayer);
            commuteMarkersLayer = null;
        }
        if (!keepPanel) {
            const panel = document.getElementById('publicCommutePlannerPanel');
            if (panel) panel.style.display = 'none';
        }
        updateCommutePlannerUi();
    }

    function resetCommutePlanner() {
        if (mapClickHandler) {
            map.off('click', mapClickHandler);
            mapClickHandler = null;
        }
        clearCommutePlannerState(true);
        bindCommuteMapClicks();
        showNotification('Click the map to set the origin', 'info');
    }
    window.resetCommutePlanner = resetCommutePlanner;

    function closeCommutePlanner() {
        if (mapClickHandler) {
            map.off('click', mapClickHandler);
            mapClickHandler = null;
        }
        clearCommutePlannerState(false);
    }
    window.closeCommutePlanner = closeCommutePlanner;

    function buildSakayTripUrl(from, to) {
        const fromParam = encodeURIComponent(from.lat + ',' + from.lng);
        const toParam = encodeURIComponent(to.lat + ',' + to.lng);
        return 'https://sakay.ph/app/trip?from=' + fromParam + '&to=' + toParam;
    }

    function openSakayTrip() {
        if (!commuteFrom || !commuteTo) {
            showNotification('Set both origin and destination first', 'error');
            return;
        }
        const url = buildSakayTripUrl(commuteFrom, commuteTo);
        window.open(url, '_blank', 'noopener,noreferrer');
        showNotification('Opening Sakay.ph commute directions…', 'info');
    }
    window.openSakayTrip = openSakayTrip;

    function bindCommuteMapClicks() {
        if (mapClickHandler) map.off('click', mapClickHandler);
        window.suppressMapReportPin = true;
        mapClickHandler = function(e) {
            if (typeof isInsideQCBounds === 'function' && !isInsideQCBounds(e.latlng.lat, e.latlng.lng)) {
                showNotification('Please select a location within Quezon City only.', 'error');
                return;
            }
            if (!commuteMarkersLayer) {
                commuteMarkersLayer = L.layerGroup().addTo(map);
            }
            if (!commuteFrom) {
                commuteFrom = e.latlng;
                L.circleMarker(e.latlng, {
                    color: '#10b981',
                    fillColor: '#10b981',
                    fillOpacity: 0.85,
                    radius: 9,
                    weight: 2
                }).bindPopup('Origin').addTo(commuteMarkersLayer).openPopup();
                updateCommutePlannerUi();
                showNotification('Now click the destination on the map', 'info');
                return;
            }
            if (!commuteTo) {
                commuteTo = e.latlng;
                L.circleMarker(e.latlng, {
                    color: '#ef4444',
                    fillColor: '#ef4444',
                    fillOpacity: 0.85,
                    radius: 9,
                    weight: 2
                }).bindPopup('Destination').addTo(commuteMarkersLayer).openPopup();
                map.off('click', mapClickHandler);
                mapClickHandler = null;
                window.suppressMapReportPin = false;
                updateCommutePlannerUi();
                const panel = document.getElementById('publicCommutePlannerPanel');
                if (panel) {
                    panel.style.display = 'block';
                    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
                openSakayTrip();
            }
        };
        map.on('click', mapClickHandler);
    }

    function showCommutePlanner() {
        closeAllPanels({ skipFence: true });
        document.getElementById('publicCommutePlannerPanel').style.display = 'block';
        document.getElementById('publicToolsDropdownMenu').style.display = 'none';
        toolsDropdownOpen = false;
        clearCommutePlannerState(true);
        bindCommuteMapClicks();
        syncMapFenceFromPanels();
        showNotification('Click the map to set the origin', 'info');
    }
    window.showCommutePlanner = showCommutePlanner;


    window.PublicGisMap = {
        open: openOverlay,
        close: closeOverlay,
        toggleTrafficLayer: toggleTrafficLayer,
        toggleToolsDropdown: toggleToolsDropdown,
        doMapSearch: doMapSearch,
        flyToLocation: flyToLocation,
        showRoutePlanner: showRoutePlanner,
        routeFromClick: routeFromClick,
        routeToClick: routeToClick,
        planRoute: planRoute,
        clearRoute: clearRoute,
        closePanel: closePanel,
        toggleAccidentPins: toggleAccidentPins,
        toggleBusStopPins: toggleBusStopPins,
        toggleRailStationPins: toggleRailStationPins,
        showPtRoutesPanel: showPtRoutesPanel,
        syncMapLayers: syncMapLayers,
        closeSyncLayersModal: closeSyncLayersModal,
        showEVCharging: showEVCharging,
        findEVStations: findEVStations,
        showCommutePlanner: showCommutePlanner,
        resetCommutePlanner: resetCommutePlanner,
        closeCommutePlanner: closeCommutePlanner,
        openSakayTrip: openSakayTrip,
        selectOsmRoute: selectOsmRoute,
        clearSelectedOsmRoute: clearSelectedOsmRoute,
        renderPtRouteList: renderPtRouteList
    };

    document.addEventListener('DOMContentLoaded', function () {
        var fab = document.getElementById('publicGisFab');
        if (fab) fab.addEventListener('click', openOverlay);
        var closeBtn = document.getElementById('publicGisCloseBtn');
        if (closeBtn) closeBtn.addEventListener('click', closeOverlay);
        var overlay = document.getElementById('publicGisOverlay');
        if (overlay) {
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) closeOverlay();
            });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlayOpen) closeOverlay();
        });
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.public-gis-tools-dropdown')) {
                var menu = document.getElementById('publicToolsDropdownMenu');
                if (menu) menu.style.display = 'none';
                toolsDropdownOpen = false;
            }
            if (!e.target.closest('.public-gis-search-box')) {
                var results = document.getElementById('publicMapSearchResults');
                if (results) results.style.display = 'none';
            }
        });
    });
})();
