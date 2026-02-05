<?php
/**
 * GIS Mapping View - LGU Officer Module
 * 
 * Provides interactive mapping of road damage reports for LGU officers
 * Integrates with damage_reports database for real-time data display
 * 
 * @version 1.0.0
 * @author LGU Road Monitoring System
 */

// Initialize session and security
session_start();
require_once '../config/auth.php';
require_once '../config/database.php';

// Security check - ensure user is LGU officer or admin
$auth->requireAnyRole(['lgu_officer', 'admin']);

// Initialize database connection
try {
    $database = new Database();
    $conn = $database->getConnection();
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("System temporarily unavailable. Please try again later.");
}

// Get user information
$user_id = $auth->getUserId();
$user_role = $auth->getUserRole();
$is_admin = $auth->hasRole(['admin']);

// Page metadata
$page_title = "GIS Mapping | LGU Officer";
$page_description = "Interactive map showing road damage reports and infrastructure conditions";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo htmlspecialchars($page_description); ?>">
    <meta name="author" content="LGU Road Monitoring System">
    <meta name="keywords" content="GIS mapping, road conditions, damage reports, infrastructure, LGU officer">
    
    <!-- Open Graph Meta Tags -->
    <meta property="og:title" content="<?php echo htmlspecialchars($page_title); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($page_description); ?>">
    <meta property="og:type" content="website">
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon.ico">
    
    <!-- External Libraries -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    
    <!-- Custom CSS -->
    <style>
        /* Import Google Fonts */
        @import url("https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap");
        
        /* CSS Variables */
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --primary-light: #3b82f6;
            --secondary: #64748b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --glass-bg: rgba(255, 255, 255, 0.85);
            --glass-border: rgba(255, 255, 255, 0.4);
            --text-main: #1e293b;
            --text-muted: #64748b;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }

        /* Reset and Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: "Inter", -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            height: 100vh;
            background: url('../user_and_access_management_module/assets/img/cityhall.jpeg') center/cover no-repeat fixed;
            background-size: cover;
            background-position: center;
            position: relative;
            overflow: hidden;
            color: var(--text-main);
            line-height: 1.6;
        }

        /* Background Overlay */
        body::before {
            content: "";
            position: absolute;
            inset: 0;
            backdrop-filter: blur(8px);
            background: rgba(15, 23, 42, 0.45);
            z-index: 0;
        }

        /* Main Content */
        .main-content {
            position: relative;
            margin-left: 250px;
            height: 100vh;
            padding: 40px 60px;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            z-index: 1;
        }

        /* Module Header */
        .module-header {
            color: white;
            margin-bottom: 30px;
        }

        .module-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .module-header p {
            font-size: 1rem;
            opacity: 0.9;
            letter-spacing: 1px;
        }

        .header-divider {
            border: none;
            height: 1px;
            background: rgba(255, 255, 255, 0.3);
            margin: 15px 0;
        }

        /* Quick Actions Bar */
        .quick-actions {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            border: 1px solid var(--glass-border);
            padding: 20px 30px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .action-buttons {
            display: flex;
            gap: 15px;
        }

        .action-btn {
            padding: 10px 20px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .action-btn:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        .action-btn.secondary {
            background: #64748b;
        }

        .action-btn.secondary:hover {
            background: #475569;
        }

        /* Stats Overview */
        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            border: 1px solid var(--glass-border);
            padding: 20px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        .stat-change {
            font-size: 0.8rem;
            margin-top: 5px;
        }

        .stat-change.positive {
            color: var(--success);
        }

        .stat-change.negative {
            color: var(--danger);
        }

        /* Map Container */
        .map-section {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            border: 1px solid var(--glass-border);
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .section-title {
            color: #1e40af;
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .section-desc {
            color: var(--text-muted);
            font-size: 0.95rem;
            margin-bottom: 25px;
        }

        /* Map Controls */
        .map-controls {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
        }

        .filter-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .filter-btn {
            padding: 8px 16px;
            border: 1px solid #e2e8f0;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .filter-btn:hover {
            background: #f1f5f9;
        }

        .filter-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Map */
        #map {
            height: 500px;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
            margin-bottom: 20px;
        }

        /* Report Details Panel */
        .report-details {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
            display: none;
        }

        .report-details.active {
            display: block;
        }

        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }

        .report-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #1e29af;
        }

        .report-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .report-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .info-value {
            font-size: 0.95rem;
            color: var(--text-main);
        }

        .report-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .action-link {
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .action-link.primary {
            background: var(--primary);
            color: white;
        }

        .action-link.primary:hover {
            background: var(--primary-hover);
        }

        .action-link.secondary {
            background: #e2e8f0;
            color: var(--text-main);
        }

        .action-link.secondary:hover {
            background: #cbd5e1;
        }

        /* Loading State */
        .map-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 500px;
            background: #f8fafc;
            border-radius: 12px;
            border: 2px dashed #cbd5e1;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #e2e8f0;
            border-top: 4px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Scrollbar Styling */
        .main-content::-webkit-scrollbar {
            width: 8px;
        }

        .main-content::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.05);
        }

        .main-content::-webkit-scrollbar-thumb {
            background: rgba(37, 99, 235, 0.5);
            border-radius: 4px;
        }

        .main-content::-webkit-scrollbar-thumb:hover {
            background: var(--primary);
        }
    </style>
</head>
<body>
    <?php include '../sidebar/sidebar.php'; ?>

    <main class="main-content">
        <header class="module-header">
            <h1>GIS Mapping Dashboard</h1>
            <p>Interactive map visualization of road damage reports and infrastructure conditions</p>
            <hr class="header-divider">
        </header>

        <!-- Quick Actions Bar -->
        <div class="quick-actions">
            <div class="action-buttons">
                <a href="road_reporting_overview.php" class="action-btn">
                    <i class="fas fa-plus-circle"></i>
                    New Report
                </a>
                <a href="#" class="action-btn secondary" onclick="exportData()">
                    <i class="fas fa-download"></i>
                    Export Data
                </a>
                <a href="#" class="action-btn secondary" onclick="refreshMap()">
                    <i class="fas fa-sync"></i>
                    Refresh
                </a>
            </div>
            <div class="filter-group">
                <span style="color: var(--text-muted); font-size: 0.9rem;">Last updated: <span id="last-updated">Just now</span></span>
            </div>
        </div>

        <!-- Statistics Overview -->
        <div class="stats-overview">
            <div class="stat-card">
                <div class="stat-number" id="total-reports">0</div>
                <div class="stat-label">Total Reports</div>
                <div class="stat-change positive">+12% this month</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="pending-reports">0</div>
                <div class="stat-label">Pending Review</div>
                <div class="stat-change negative">+3 urgent</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="in-progress">0</div>
                <div class="stat-label">In Progress</div>
                <div class="stat-change positive">-2 from last week</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="completed">0</div>
                <div class="stat-label">Completed</div>
                <div class="stat-change positive">+8 this month</div>
            </div>
        </div>

        <!-- Map Section -->
        <div class="map-section">
            <h2 class="section-title">Road Damage Reports Map</h2>
            <p class="section-desc">Click on map markers to view detailed report information and take action</p>
            
            <!-- Map Controls -->
            <div class="map-controls">
                <div class="filter-group">
                    <button class="filter-btn active" onclick="filterMap('all')">
                        <i class="fas fa-layer-group"></i> All Reports
                    </button>
                    <button class="filter-btn" onclick="filterMap('issues')">
                        <i class="fas fa-exclamation-triangle"></i> Active Issues
                    </button>
                    <button class="filter-btn" onclick="filterMap('completed')">
                        <i class="fas fa-check-circle"></i> Completed
                    </button>
                </div>
                
                <div class="filter-group">
                    <button class="filter-btn" onclick="resetMap()">
                        <i class="fas fa-redo"></i> Reset View
                    </button>
                </div>
            </div>

            <!-- Map Container -->
            <div id="map">
                <div class="map-loading">
                    <div class="spinner"></div>
                </div>
            </div>

            <!-- Report Details Panel -->
            <div id="report-details" class="report-details">
                <div class="report-header">
                    <div>
                        <div class="report-title" id="detail-title">Select a report to view details</div>
                        <div class="report-status" id="detail-status"></div>
                    </div>
                    <button onclick="closeDetails()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <div class="report-info">
                    <div class="info-item">
                        <span class="info-label">Report ID</span>
                        <span class="info-value" id="detail-id">-</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Location</span>
                        <span class="info-value" id="detail-location">-</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Damage Type</span>
                        <span class="info-value" id="detail-type">-</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Severity</span>
                        <span class="info-value" id="detail-severity">-</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Reported By</span>
                        <span class="info-value" id="detail-reporter">-</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Date Reported</span>
                        <span class="info-value" id="detail-date">-</span>
                    </div>
                </div>
                
                <div class="info-item" style="margin-bottom: 15px;">
                    <span class="info-label">Description</span>
                    <span class="info-value" id="detail-description">-</span>
                </div>
                
                <div class="report-actions">
                    <a href="#" class="action-link primary" id="action-view">View Full Report</a>
                    <a href="#" class="action-link secondary" id="action-update">Update Status</a>
                    <a href="#" class="action-link secondary" id="action-assign">Assign Team</a>
                </div>
            </div>
        </div>
    </main>

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Initialize map
        let map;
        let markers = [];
        let currentFilter = 'all';
        let selectedReport = null;

        function initMap() {
            // Create map centered on a default location (adjust to your area)
            map = L.map('map').setView([14.5995, 120.9842], 13);

            // Add tile layer
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);

            // Load real data from database
            loadMapData('all');
        }

        function loadMapData(filter = 'all') {
            fetch('../api/get_gis_data.php?filter=' + filter)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateMapWithRealData(data.data.features);
                        updateStatistics(data.data.statistics);
                        updateLastUpdated();
                    } else {
                        console.error('Error loading map data:', data.message);
                        showErrorMessage('Unable to load map data. Please try again.');
                    }
                })
                .catch(error => {
                    console.error('Error fetching map data:', error);
                    showErrorMessage('Network error. Please check your connection.');
                });
        }

        function updateMapWithRealData(features) {
            // Clear existing markers
            markers.forEach(marker => {
                map.removeLayer(marker);
            });
            markers = [];

            // Add markers from database
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
                    <small>Severity: ${props.severity}</small>
                `;

                if (props.address) {
                    popupContent += `<br><small>Location: ${props.address}</small>`;
                }
                if (props.reporter_name) {
                    popupContent += `<br><small>Reported by: ${props.reporter_name}</small>`;
                }

                marker.bindPopup(popupContent);
                
                // Add click event for detailed view
                marker.on('click', function() {
                    showReportDetails(props);
                });
                
                marker.type = props.type;
                marker.severity = props.severity;
                marker.data_type = props.data_type;
                marker.reportData = props;
                markers.push(marker);
            });
        }

        function showReportDetails(report) {
            selectedReport = report;
            
            document.getElementById('detail-title').textContent = report.title;
            document.getElementById('detail-id').textContent = report.report_id || 'N/A';
            document.getElementById('detail-location').textContent = report.address || 'N/A';
            document.getElementById('detail-type').textContent = report.data_type === 'damage_report' ? 
                (report.damage_type || 'N/A') : 'Construction Zone';
            document.getElementById('detail-severity').textContent = report.severity || 'N/A';
            document.getElementById('detail-reporter').textContent = report.reporter_name || 'N/A';
            document.getElementById('detail-date').textContent = report.created_at ? 
                new Date(report.created_at).toLocaleDateString() : 'N/A';
            document.getElementById('detail-description').textContent = report.description || 'No description available';
            
            // Update status badge
            const statusElement = document.getElementById('detail-status');
            statusElement.textContent = report.status ? report.status.replace('_', ' ') : 'Unknown';
            statusElement.className = 'report-status status-' + (report.status || 'unknown');
            
            // Update action links
            if (report.data_type === 'damage_report') {
                document.getElementById('action-view').href = `road_reporting_overview.php?report_id=${report.id}`;
                document.getElementById('action-update').href = `update_report_status.php?id=${report.id}`;
                document.getElementById('action-assign').href = `assign_team.php?id=${report.id}`;
            } else {
                document.getElementById('action-view').href = `construction_details.php?id=${report.id}`;
                document.getElementById('action-update').href = `update_construction.php?id=${report.id}`;
                document.getElementById('action-assign').style.display = 'none';
            }
            
            document.getElementById('report-details').classList.add('active');
        }

        function closeDetails() {
            document.getElementById('report-details').classList.remove('active');
            selectedReport = null;
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

        function getColorBySeverity(severity) {
            const colors = {
                'low': '#10b981',
                'medium': '#f59e0b',
                'high': '#ef4444',
                'critical': '#dc2626'
            };
            return colors[severity] || '#6b7280';
        }

        function updateStatistics(stats) {
            document.getElementById('total-reports').textContent = stats.total_markers || 0;
            document.getElementById('pending-reports').textContent = stats.active_issues || 0;
            document.getElementById('in-progress').textContent = stats.in_progress || 0;
            document.getElementById('completed').textContent = stats.completed_work || 0;
        }

        function updateLastUpdated() {
            document.getElementById('last-updated').textContent = 'Just now';
        }

        function filterMap(filter) {
            // Remove active class from all buttons
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            // Add active class to clicked button
            event.target.closest('.filter-btn').classList.add('active');

            currentFilter = filter;
            loadMapData(filter);
        }

        function resetMap() {
            map.setView([14.5995, 120.9842], 13);
            filterMap('all');
        }

        function refreshMap() {
            loadMapData(currentFilter);
        }

        function exportData() {
            window.open('../api/export_reports.php', '_blank');
        }

        function showErrorMessage(message) {
            const mapDiv = document.getElementById('map');
            mapDiv.innerHTML = `
                <div style="display: flex; align-items: center; justify-content: center; height: 500px; background: #fef2f2; border-radius: 12px; border: 2px solid #fecaca;">
                    <div style="text-align: center; color: #dc2626;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 2rem; margin-bottom: 10px;"></i>
                        <p>${message}</p>
                    </div>
                </div>
            `;
        }

        // Initialize map when page loads
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(initMap, 1000);
        });

        // Auto-refresh every 5 minutes
        setInterval(refreshMap, 300000);
    </script>
</body>
</html>
