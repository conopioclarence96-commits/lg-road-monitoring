<div class="public-gis-overlay" id="publicGisOverlay" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="publicGisOverlayTitle">
    <div class="public-gis-dialog">
    <div class="public-gis-overlay-header">
        <h2 id="publicGisOverlayTitle"><i class="fas fa-map-marked-alt" aria-hidden="true"></i> Live Road Map — Quezon City</h2>
        <button type="button" class="public-gis-close-btn" id="publicGisCloseBtn" aria-label="Close map"><i class="fas fa-times"></i></button>
    </div>
    <div class="public-gis-overlay-body">
        <div class="public-gis-map-wrap">
            <div class="public-gis-toolbar">
                <div class="public-gis-toolbar-left">
                    <div class="public-gis-legend">
                        <span class="public-gis-legend-item"><span class="public-gis-legend-dot" style="background:#dc2626;"></span> Accident</span>
                        <span class="public-gis-legend-item"><span class="public-gis-legend-dot" style="background:#111827;"></span> Closed</span>
                        <span class="public-gis-legend-item"><span class="public-gis-legend-dot" style="background:#f59e0b;"></span> Jam</span>
                        <span class="public-gis-legend-item"><span class="public-gis-legend-dot" style="background:#ca8a04;"></span> Works</span>
                        <span class="public-gis-legend-item"><span class="public-gis-legend-dot" style="background:#0284c7;"></span> Bus</span>
                        <span class="public-gis-legend-item"><span class="public-gis-legend-dot" style="background:#475569;"></span> Rail</span>
                        <span class="public-gis-legend-item"><span class="public-gis-legend-dot" style="background:#dc2626;"></span> PT route</span>
                    </div>
                    <div class="public-gis-search-box">
                        <input type="text" id="publicMapSearchInput" placeholder="Search places..." autocomplete="off" aria-label="Search places">
                        <button type="button" class="public-gis-btn" onclick="window.PublicGisMap.doMapSearch()" title="Search"><i class="fas fa-search"></i></button>
                        <div id="publicMapSearchResults" class="search-results-dropdown"></div>
                    </div>
                </div>
                <div class="public-gis-toolbar-right">
                    <div class="public-gis-tools" id="publicMapTools">
                        <button type="button" class="public-gis-btn public-gis-tools-toggle has-active" id="publicToolsDropdownBtn" onclick="window.PublicGisMap.toggleToolsDropdown()" aria-haspopup="true" aria-expanded="false">
                            <i class="fas fa-tools"></i> Tools
                            <span class="public-gis-tools-toggle-count" id="publicToolsActiveCount">1</span>
                        </button>
                        <div class="public-gis-tools-menu" id="publicToolsDropdownMenu" role="menu" aria-label="Map tools">
                            <div class="public-gis-tools-heading">Layers</div>
                            <button type="button" class="public-gis-tools-item is-on" id="publicToggleTrafficBtn" onclick="window.PublicGisMap.toggleTrafficLayer()" role="menuitemcheckbox" aria-checked="true">
                                <span class="public-gis-tools-item-main"><i class="fas fa-car"></i> Traffic</span>
                                <span class="public-gis-tools-item-state">On</span>
                            </button>
                            <button type="button" class="public-gis-tools-item is-off" id="publicToggleAccidentsBtn" onclick="window.PublicGisMap.toggleAccidentPins()" role="menuitemcheckbox" aria-checked="false">
                                <span class="public-gis-tools-item-main"><i class="fas fa-exclamation-triangle"></i> Incidents</span>
                                <span class="public-gis-tools-item-state">Off</span>
                            </button>
                            <button type="button" class="public-gis-tools-item is-off" id="publicToggleBusStopsBtn" onclick="window.PublicGisMap.toggleBusStopPins()" role="menuitemcheckbox" aria-checked="false">
                                <span class="public-gis-tools-item-main"><i class="fas fa-bus"></i> Bus</span>
                                <span class="public-gis-tools-item-state">Off</span>
                            </button>
                            <button type="button" class="public-gis-tools-item is-off" id="publicToggleRailStationsBtn" onclick="window.PublicGisMap.toggleRailStationPins()" role="menuitemcheckbox" aria-checked="false">
                                <span class="public-gis-tools-item-main"><i class="fas fa-train"></i> Rail</span>
                                <span class="public-gis-tools-item-state">Off</span>
                            </button>
                            <button type="button" class="public-gis-tools-item is-off" id="publicTogglePtRoutesBtn" onclick="window.PublicGisMap.showPtRoutesPanel()" role="menuitemcheckbox" aria-checked="false">
                                <span class="public-gis-tools-item-main"><i class="fas fa-route"></i> PT Routes</span>
                                <span class="public-gis-tools-item-state">Off</span>
                            </button>

                            <div class="public-gis-tools-divider"></div>
                            <div class="public-gis-tools-heading">Planners</div>
                            <button type="button" class="public-gis-tools-item is-off" id="publicBtnRoutePlanner" onclick="window.PublicGisMap.showRoutePlanner()" role="menuitemcheckbox" aria-checked="false">
                                <span class="public-gis-tools-item-main"><i class="fas fa-route"></i> Route Planner</span>
                                <span class="public-gis-tools-item-state">Off</span>
                            </button>
                            <button type="button" class="public-gis-tools-item is-off" id="publicBtnCommutePlanner" onclick="window.PublicGisMap.showCommutePlanner()" role="menuitemcheckbox" aria-checked="false">
                                <span class="public-gis-tools-item-main"><i class="fas fa-bus"></i> Commute Planner</span>
                                <span class="public-gis-tools-item-state">Off</span>
                            </button>
                            <button type="button" class="public-gis-tools-item is-off" id="publicBtnEVCharging" onclick="window.PublicGisMap.showEVCharging()" role="menuitemcheckbox" aria-checked="false">
                                <span class="public-gis-tools-item-main"><i class="fas fa-charging-station"></i> EV Stations</span>
                                <span class="public-gis-tools-item-state">Off</span>
                            </button>

                            <div class="public-gis-tools-divider"></div>
                            <button type="button" class="public-gis-tools-item public-gis-tools-action" id="publicSyncMapLayersBtn" onclick="window.PublicGisMap.syncMapLayers()" title="Re-download Incidents, Bus, Rail, and PT Routes" role="menuitem">
                                <span class="public-gis-tools-item-main"><i class="fas fa-sync-alt"></i> Sync Layers</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div id="publicGisMap" role="region" aria-label="Live road map of Quezon City"></div>

            <div class="sync-layers-overlay" id="publicSyncLayersOverlay" aria-hidden="true">
                <div class="sync-layers-modal" role="dialog" aria-modal="true" aria-labelledby="publicSyncLayersTitle">
                    <div class="sync-layers-modal-header">
                        <h3 id="publicSyncLayersTitle"><i class="fas fa-sync-alt" id="publicSyncLayersTitleIcon"></i> Syncing Map Layers</h3>
                        <p id="publicSyncLayersSubtitle">Downloading fresh data. Please wait — this window cannot be closed until sync finishes.</p>
                    </div>
                    <div class="sync-layers-modal-body">
                        <ul class="sync-layers-list" id="publicSyncLayersList">
                            <li class="sync-layers-item is-pending" data-layer="incidents">
                                <span class="sync-layers-item-icon"><i class="fas fa-spinner fa-spin"></i></span>
                                <span class="sync-layers-item-meta">
                                    <span class="sync-layers-item-label"><i class="fas fa-exclamation-triangle"></i> Incidents</span>
                                    <span class="sync-layers-item-status">Waiting…</span>
                                </span>
                            </li>
                            <li class="sync-layers-item is-pending" data-layer="bus">
                                <span class="sync-layers-item-icon"><i class="fas fa-spinner fa-spin"></i></span>
                                <span class="sync-layers-item-meta">
                                    <span class="sync-layers-item-label"><i class="fas fa-bus"></i> Bus Stops</span>
                                    <span class="sync-layers-item-status">Waiting…</span>
                                </span>
                            </li>
                            <li class="sync-layers-item is-pending" data-layer="rail">
                                <span class="sync-layers-item-icon"><i class="fas fa-spinner fa-spin"></i></span>
                                <span class="sync-layers-item-meta">
                                    <span class="sync-layers-item-label"><i class="fas fa-train"></i> Rail Stations</span>
                                    <span class="sync-layers-item-status">Waiting…</span>
                                </span>
                            </li>
                            <li class="sync-layers-item is-pending" data-layer="routes">
                                <span class="sync-layers-item-icon"><i class="fas fa-spinner fa-spin"></i></span>
                                <span class="sync-layers-item-meta">
                                    <span class="sync-layers-item-label"><i class="fas fa-route"></i> PT Routes</span>
                                    <span class="sync-layers-item-status">Waiting…</span>
                                </span>
                            </li>
                        </ul>
                    </div>
                    <div class="sync-layers-modal-footer" id="publicSyncLayersFooter">
                        <button type="button" class="sync-layers-close-btn" onclick="window.PublicGisMap.closeSyncLayersModal()">Close</button>
                    </div>
                </div>
            </div>

            <div class="public-gis-panels">
                <div id="publicRoutePlannerPanel" class="tomtom-panel">
                    <h5><i class="fas fa-route"></i> Route Planner</h5>
                    <label for="publicRouteFrom">Start Location</label>
                    <input type="text" id="publicRouteFrom" placeholder="Click map or type coordinates..." onclick="window.PublicGisMap.routeFromClick()">
                    <label for="publicRouteTo">Destination</label>
                    <input type="text" id="publicRouteTo" placeholder="Click map or type coordinates..." onclick="window.PublicGisMap.routeToClick()">
                    <label for="publicRouteMode">Travel Mode</label>
                    <select id="publicRouteMode">
                        <option value="car">Car</option>
                        <option value="truck">Truck</option>
                        <option value="pedestrian">Pedestrian</option>
                        <option value="bicycle">Bicycle</option>
                    </select>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button type="button" class="btn-action btn-sm" onclick="window.PublicGisMap.planRoute()"><i class="fas fa-route"></i> Calculate Route</button>
                        <button type="button" class="btn-action btn-sm btn-secondary" onclick="window.PublicGisMap.clearRoute()">Clear</button>
                        <button type="button" class="btn-action btn-sm btn-secondary" onclick="window.PublicGisMap.closePanel('publicRoutePlannerPanel')">Close</button>
                    </div>
                    <div id="publicRouteInfo" class="route-info-box" style="display:none;"></div>
                </div>

                <div id="publicEvChargingPanel" class="tomtom-panel">
                    <h5><i class="fas fa-charging-station"></i> EV Charging Stations</h5>
                    <p style="font-size:12px;color:#666;">Search for EV charging stations near the map center.</p>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button type="button" class="btn-action btn-sm" onclick="window.PublicGisMap.findEVStations()"><i class="fas fa-search"></i> Find Nearby</button>
                        <button type="button" class="btn-action btn-sm btn-secondary" onclick="window.PublicGisMap.closePanel('publicEvChargingPanel')">Close</button>
                    </div>
                    <div id="publicEvResults" class="route-info-box" style="display:none;"></div>
                </div>

                <div id="publicPtRoutesPanel" class="tomtom-panel">
                    <h5><i class="fas fa-route"></i> Public Transport Routes (OSM)</h5>
                    <p style="font-size:12px;color:#666;">Select a route to show it on the map. Data from OpenStreetMap.</p>
                    <label for="publicPtRouteSearch">Search routes</label>
                    <input type="text" id="publicPtRouteSearch" placeholder="Name, from, to, ref..." oninput="window.PublicGisMap.renderPtRouteList()">
                    <div id="publicPtRouteListMeta" style="font-size:11px;margin-top:6px;color:#666;"></div>
                    <div id="publicPtRouteList" class="pt-route-list">
                        <div class="pt-route-status">Loading routes…</div>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button type="button" class="btn-action btn-sm btn-secondary" onclick="window.PublicGisMap.clearSelectedOsmRoute()"><i class="fas fa-eraser"></i> Clear map</button>
                        <button type="button" class="btn-action btn-sm btn-secondary" onclick="window.PublicGisMap.closePanel('publicPtRoutesPanel')">Close</button>
                    </div>
                </div>

                <div id="publicCommutePlannerPanel" class="tomtom-panel">
                    <h5><i class="fas fa-bus"></i> Commute Planner</h5>
                    <p style="font-size:12px;color:#666;">Pick origin and destination on the map, then open directions on Sakay.ph.</p>
                    <div id="publicCommutePlannerStatus" class="route-info-box">Click the map to set the <strong>origin</strong>.</div>
                    <div id="publicCommutePlannerCoords" style="font-size:11px;margin-top:8px;display:none;color:#666;"></div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button type="button" class="btn-action btn-sm" id="publicOpenSakayTripBtn" onclick="window.PublicGisMap.openSakayTrip()" disabled><i class="fas fa-external-link-alt"></i> Open in Sakay</button>
                        <button type="button" class="btn-action btn-sm btn-secondary" onclick="window.PublicGisMap.resetCommutePlanner()"><i class="fas fa-redo"></i> Reset</button>
                        <button type="button" class="btn-action btn-sm btn-secondary" onclick="window.PublicGisMap.closeCommutePlanner()"><i class="fas fa-times"></i> Close</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>
</div>
