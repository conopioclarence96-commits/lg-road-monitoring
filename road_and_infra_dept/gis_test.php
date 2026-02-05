<?php
/**
 * Simple GIS Test - No Authentication Required
 * Test the GIS mapping functionality without login requirements
 */

// Skip authentication for testing
// session_start();
// require_once '../config/auth.php';
// require_once '../config/database.php';

// Page metadata
$page_title = "GIS Mapping Test";
$page_description = "Interactive map showing road damage reports - Test Version";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    
    <!-- External Libraries -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    
    <!-- Custom CSS -->
    <style>
        body {
            font-family: "Inter", -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f8fafc;
            color: #1e293b;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header h1 {
            color: #1e40af;
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .header p {
            color: #64748b;
            font-size: 1rem;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #2563eb;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #64748b;
        }

        .map-controls {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-btn {
            padding: 8px 16px;
            border: 1px solid #e2e8f0;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .filter-btn:hover {
            background: #f1f5f9;
        }

        .filter-btn.active {
            background: #2563eb;
            color: white;
            border-color: #2563eb;
        }

        #map {
            height: 500px;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
            background: white;
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            background: #3b82f6;
            color: white;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 10000;
            font-size: 0.9rem;
            max-width: 300px;
        }

        .error {
            background: #ef4444;
        }

        .success {
            background: #10b981;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🗺️ GIS Mapping Test</h1>
            <p>Interactive map showing road damage reports - Test Version (No Authentication)</p>
        </div>

        <!-- Statistics -->
        <div class="stats">
            <div class="stat-card">
                <div class="stat-number" id="total-reports">0</div>
                <div class="stat-label">Total Reports</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="active-issues">0</div>
                <div class="stat-label">Active Issues</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="completed">0</div>
                <div class="stat-label">Completed</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="in-progress">0</div>
                <div class="stat-label">In Progress</div>
            </div>
        </div>

        <!-- Map Controls -->
        <div class="map-controls">
            <button class="filter-btn active" onclick="filterMap('all')">
                <i class="fas fa-layer-group"></i> All Reports
            </button>
            <button class="filter-btn" onclick="filterMap('issues')">
                <i class="fas fa-exclamation-triangle"></i> Active Issues
            </button>
            <button class="filter-btn" onclick="filterMap('completed')">
                <i class="fas fa-check-circle"></i> Completed
            </button>
            <button class="filter-btn" onclick="resetMap()">
                <i class="fas fa-redo"></i> Reset View
            </button>
        </div>

        <!-- Map -->
        <div id="map"></div>
    </div>

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        let map;
        let markers = [];
        let currentFilter = 'all';

        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = 'notification ' + type;
            notification.innerHTML = `<i class="fas fa-info-circle"></i> ${message}`;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 5000);
        }

        function initMap() {
            try {
                // Create map
                map = L.map('map').setView([14.5995, 120.9842], 13);

                // Add tile layer
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OpenStreetMap contributors'
                }).addTo(map);

                showNotification('Map initialized successfully!', 'success');
                
                // Load data
                loadMapData('all');
                
            } catch (error) {
                console.error('Map initialization error:', error);
                showNotification('Failed to initialize map: ' + error.message, 'error');
            }
        }

        function loadMapData(filter = 'all') {
            showNotification('Loading map data...', 'info');
            
            fetch('./api/get_gis_data_test.php?filter=' + filter)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        updateMapWithRealData(data.data.features);
                        updateStatistics(data.data.statistics);
                        showNotification('Map data loaded successfully!', 'success');
                        
                        if (data.message) {
                            showNotification(data.message, 'info');
                        }
                    } else {
                        throw new Error(data.message || 'Unknown API error');
                    }
                })
                .catch(error => {
                    console.error('Error loading map data:', error);
                    showNotification('Failed to load map data: ' + error.message, 'error');
                });
        }

        function updateMapWithRealData(features) {
            // Clear existing markers
            markers.forEach(marker => {
                map.removeLayer(marker);
            });
            markers = [];

            // Add markers
            features.forEach(feature => {
                const coords = feature.geometry.coordinates;
                const props = feature.properties;
                
                const color = getColorBySeverity(props.severity);
                const marker = L.circleMarker([coords[1], coords[0]], {
                    radius: getMarkerSize(props.severity),
                    fillColor: color,
                    color: '#fff',
                    weight: 2,
                    opacity: 1,
                    fillOpacity: 0.8
                }).addTo(map);

                // Create popup content
                let popupContent = `
                    <strong>${props.title}</strong><br>
                    ${props.description}<br>
                    <small>Status: ${props.status}</small><br>
                    <small>Severity: ${props.severity}</small><br>
                    <small>Location: ${props.address}</small><br>
                    <small>Reported by: ${props.reporter_name}</small>
                `;

                marker.bindPopup(popupContent);
                markers.push(marker);
            });
        }

        function getColorBySeverity(severity) {
            const colors = {
                'low': '#10b981',
                'medium': '#f59e0b',
                'high': '#ef4444',
                'critical': '#dc2626'
            };
            return colors[severity] || '#6b7280';
        }

        function getMarkerSize(severity) {
            const sizes = {
                'low': 6,
                'medium': 8,
                'high': 10,
                'critical': 12
            };
            return sizes[severity] || 8;
        }

        function updateStatistics(stats) {
            document.getElementById('total-reports').textContent = stats.total_markers || 0;
            document.getElementById('active-issues').textContent = stats.active_issues || 0;
            document.getElementById('completed').textContent = stats.completed_work || 0;
            document.getElementById('in-progress').textContent = stats.in_progress || 0;
        }

        function filterMap(filter) {
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');
            
            currentFilter = filter;
            loadMapData(filter);
        }

        function resetMap() {
            map.setView([14.5995, 120.9842], 13);
            filterMap('all');
        }

        // Initialize map when page loads
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(initMap, 1000);
        });
    </script>
</body>
</html>
