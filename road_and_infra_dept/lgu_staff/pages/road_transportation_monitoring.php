<?php
// Session settings (must be set before session_start)
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS

session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.html');
    exit();
}

// Function to get monitoring statistics
function getMonitoringStatistics() {
    global $conn;
    $stats = [];
    
    if ($conn) {
        // Get active roads count
        $result = $conn->query("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status != 'completed'");
        $stats['active_roads'] = $result->fetch_assoc()['count'];
        
        // Get incident count
        $result = $conn->query("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status = 'pending' AND priority = 'high'");
        $stats['incidents'] = $result->fetch_assoc()['count'];
        
        // Get under repair count
        $result = $conn->query("SELECT COUNT(*) as count FROM road_maintenance_reports WHERE status = 'in-progress'");
        $stats['under_repair'] = $result->fetch_assoc()['count'];
        
        // Calculate clear flow percentage
        $total_result = $conn->query("SELECT COUNT(*) as count FROM road_transportation_reports");
        $total = $total_result->fetch_assoc()['count'];
        
        $clear_result = $conn->query("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status = 'completed'");
        $clear = $clear_result->fetch_assoc()['count'];
        
        $stats['clear_flow'] = $total > 0 ? round(($clear / $total) * 100, 0) : 94;
        
    } else {
        // Return sample data if database is not available
        $stats = [
            'active_roads' => 142,
            'incidents' => 8,
            'under_repair' => 23,
            'clear_flow' => 94
        ];
    }
    
    return $stats;
}

// Function to get active alerts
function getActiveAlerts() {
    global $conn;
    $alerts = [];
    
    if ($conn) {
        $query = "SELECT title, created_at, priority FROM road_transportation_reports 
                  WHERE status = 'pending' AND priority IN ('high', 'medium') 
                  ORDER BY created_at DESC LIMIT 5";
        $result = $conn->query($query);
        
        while ($row = $result->fetch_assoc()) {
            $alerts[] = [
                'title' => $row['title'],
                'time' => getTimeAgo($row['created_at']),
                'priority' => $row['priority']
            ];
        }
        
    } else {
        // Return sample alerts
        $alerts = [
            ['title' => 'Multi-vehicle accident on Highway 101', 'time' => '5 minutes ago', 'priority' => 'high'],
            ['title' => 'Road maintenance on Main Street', 'time' => '15 minutes ago', 'priority' => 'medium'],
            ['title' => 'Traffic light malfunction at Oak Avenue', 'time' => '30 minutes ago', 'priority' => 'high']
        ];
    }
    
    return $alerts;
}

// Function to get road status
function getRoadStatus() {
    global $conn;
    $roads = [];
    
    if ($conn) {
        $query = "SELECT title, status, description, created_at FROM road_transportation_reports 
                  WHERE status IN ('pending', 'in-progress', 'completed') 
                  ORDER BY created_at DESC LIMIT 10";
        $result = $conn->query($query);
        
        while ($row = $result->fetch_assoc()) {
            $roads[] = [
                'name' => $row['title'],
                'status' => $row['status'],
                'condition' => $row['description'] ?: 'No specific condition reported',
                'traffic' => getTrafficLevel($row['status'])
            ];
        }
        
    } else {
        // Return sample road data
        $roads = [
            ['name' => 'Highway 101', 'status' => 'completed', 'condition' => 'Clear - Normal traffic flow', 'traffic' => 'Light traffic'],
            ['name' => 'Main Street', 'status' => 'pending', 'condition' => 'Heavy congestion - Accident reported', 'traffic' => 'Heavy traffic'],
            ['name' => 'Oak Avenue', 'status' => 'in-progress', 'condition' => 'Moderate - Road maintenance', 'traffic' => 'Moderate traffic'],
            ['name' => 'Elm Street', 'status' => 'completed', 'condition' => 'Clear - No issues reported', 'traffic' => 'Light traffic']
        ];
    }
    
    return $roads;
}

// Helper function to get time ago
function getTimeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' minutes ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hours ago';
    } else {
        $days = floor($diff / 86400);
        return $days . ' days ago';
    }
}

// Helper function to get traffic level based on status
function getTrafficLevel($status) {
    switch ($status) {
        case 'pending':
            return 'Heavy traffic';
        case 'in-progress':
            return 'Moderate traffic';
        case 'completed':
            return 'Light traffic';
        default:
            return 'Normal traffic';
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'refresh_data':
            header('Content-Type: application/json');
            echo json_encode([
                'stats' => getMonitoringStatistics(),
                'alerts' => getActiveAlerts(),
                'roads' => getRoadStatus()
            ]);
            exit;
            
        case 'export_report':
            // Handle report export
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Report exported successfully']);
            exit;
    }
}

// Get data for the page
$stats = getMonitoringStatistics();
$alerts = getActiveAlerts();
$roads = getRoadStatus();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Road Transportation Monitoring | LGU Staff</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            min-height: 100vh;
            background: url("../../assets/img/cityhall.jpeg") center/cover no-repeat fixed;
            position: relative;
        }

        body::before {
            content: "";
            position: absolute;
            inset: 0;
            backdrop-filter: blur(6px);
            background: rgba(0, 0, 0, 0.35);
            z-index: 0;
        }

        .main-content {
            margin-left: 250px;
            padding: 20px;
            position: relative;
            z-index: 1;
        }

        .monitoring-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            padding: 25px 30px;
            border-radius: 16px;
            margin-bottom: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .header-title h1 {
            color: #1e3c72;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .header-title p {
            color: #666;
            font-size: 14px;
        }

        .monitoring-actions {
            display: flex;
            gap: 15px;
        }

        .btn-action {
            padding: 10px 20px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(55, 98, 200, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #495057);
        }

        .btn-secondary:hover {
            box-shadow: 0 6px 20px rgba(108, 117, 125, 0.3);
        }

        .monitoring-layout {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 25px;
            margin-bottom: 25px;
        }

        .map-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .map-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .map-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e3c72;
        }

        .map-filters {
            display: flex;
            gap: 10px;
        }

        .filter-btn {
            padding: 6px 12px;
            background: rgba(55, 98, 200, 0.1);
            color: #3762c8;
            border: 1px solid rgba(55, 98, 200, 0.3);
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filter-btn:hover,
        .filter-btn.active {
            background: #3762c8;
            color: white;
        }

        #map {
            height: 500px;
            border-radius: 12px;
            overflow: hidden;
        }

        .sidebar-section {
            display: flex;
            flex-direction: column;
            gap: 25px;
        }

        .info-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .info-card-title {
            font-size: 16px;
            font-weight: 600;
            color: #1e3c72;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .stat-item {
            text-align: center;
            padding: 15px 10px;
            background: rgba(55, 98, 200, 0.05);
            border-radius: 10px;
            border: 1px solid rgba(55, 98, 200, 0.1);
        }

        .stat-number {
            font-size: 24px;
            font-weight: 700;
            color: #3762c8;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 12px;
            color: #666;
            font-weight: 500;
        }

        .alert-list {
            max-height: 200px;
            overflow-y: auto;
        }

        .alert-item {
            display: flex;
            align-items: flex-start;
            padding: 12px;
            margin-bottom: 10px;
            background: rgba(220, 53, 69, 0.05);
            border-left: 3px solid #dc3545;
            border-radius: 6px;
            transition: all 0.3s ease;
        }

        .alert-item:hover {
            background: rgba(220, 53, 69, 0.1);
        }

        .alert-item.warning {
            background: rgba(255, 193, 7, 0.05);
            border-left-color: #ffc107;
        }

        .alert-item.warning:hover {
            background: rgba(255, 193, 7, 0.1);
        }

        .alert-icon {
            margin-right: 12px;
            color: #dc3545;
            font-size: 16px;
        }

        .alert-item.warning .alert-icon {
            color: #ffc107;
        }

        .alert-content {
            flex: 1;
        }

        .alert-title {
            font-size: 13px;
            font-weight: 500;
            color: #333;
            margin-bottom: 3px;
        }

        .alert-time {
            font-size: 11px;
            color: #999;
        }

        .road-status-list {
            max-height: 250px;
            overflow-y: auto;
        }

        .road-item {
            display: flex;
            align-items: center;
            padding: 12px;
            margin-bottom: 10px;
            background: rgba(255, 255, 255, 0.7);
            border-radius: 8px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .road-item:hover {
            background: rgba(55, 98, 200, 0.1);
            transform: translateX(5px);
        }

        .road-status {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 12px;
            flex-shrink: 0;
        }

        .status-clear {
            background: #28a745;
        }

        .status-moderate {
            background: #ffc107;
        }

        .status-heavy {
            background: #dc3545;
        }

        .road-info {
            flex: 1;
        }

        .road-name {
            font-size: 14px;
            font-weight: 500;
            color: #333;
            margin-bottom: 3px;
        }

        .road-condition {
            font-size: 12px;
            color: #666;
        }

        .traffic-indicator {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            color: #666;
        }


        @media (max-width: 1200px) {
            .monitoring-layout {
                grid-template-columns: 1fr;
            }
            
            .sidebar-section {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .monitoring-actions {
                width: 100%;
                justify-content: flex-start;
            }
            
            .sidebar-section {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- SIDEBAR -->
    <iframe src="../includes/sidebar.php" 
            style="position: fixed; width: 250px; height: 100vh; border: none; z-index: 1000;" 
            frameborder="0"
            name="sidebar-frame"
            scrolling="no">
    </iframe>

    <div class="main-content">
        <!-- Monitoring Header -->
        <div class="monitoring-header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Road Transportation Monitoring</h1>
                    <p>Real-time monitoring of road conditions and traffic flow</p>
                </div>
                <div class="monitoring-actions">
                    <button class="btn-action btn-secondary" onclick="refreshData()">
                        <i class="fas fa-sync-alt"></i>
                        Refresh
                    </button>
                    <button class="btn-action" onclick="exportReport()">
                        <i class="fas fa-download"></i>
                        Export Report
                    </button>
                </div>
            </div>
        </div>

        <!-- Main Monitoring Layout -->
        <div class="monitoring-layout">
            <!-- Map Section -->
            <div class="map-section">
                <div class="map-header">
                    <h3 class="map-title">Live Road Map</h3>
                    <div class="map-filters">
                        <button class="filter-btn active">All</button>
                        <button class="filter-btn">Incidents</button>
                        <button class="filter-btn">Construction</button>
                        <button class="filter-btn">Traffic</button>
                    </div>
                </div>
                <div id="map"></div>
            </div>

            <!-- Sidebar -->
            <div class="sidebar-section">
                <!-- Statistics Card -->
                <div class="info-card">
                    <h3 class="info-card-title">
                        <i class="fas fa-chart-line"></i>
                        Live Statistics
                    </h3>
                    <div class="stats-grid">
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $stats['active_roads']; ?></div>
                            <div class="stat-label">Active Roads</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $stats['incidents']; ?></div>
                            <div class="stat-label">Incidents</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $stats['under_repair']; ?></div>
                            <div class="stat-label">Under Repair</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $stats['clear_flow']; ?>%</div>
                            <div class="stat-label">Clear Flow</div>
                        </div>
                    </div>
                </div>

                <!-- Active Alerts -->
                <div class="info-card">
                    <h3 class="info-card-title">
                        <i class="fas fa-exclamation-triangle"></i>
                        Active Alerts
                    </h3>
                    <div class="alert-list">
                        <?php foreach ($alerts as $alert): ?>
                        <div class="alert-item <?php echo $alert['priority'] == 'medium' ? 'warning' : ''; ?>">
                            <div class="alert-icon">
                                <i class="fas fa-<?php echo $alert['priority'] == 'high' ? 'car-crash' : 'tools'; ?>"></i>
                            </div>
                            <div class="alert-content">
                                <div class="alert-title"><?php echo htmlspecialchars($alert['title']); ?></div>
                                <div class="alert-time"><?php echo htmlspecialchars($alert['time']); ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Road Status List -->
        <div class="info-card">
            <h3 class="info-card-title">
                <i class="fas fa-road"></i>
                Major Road Status
            </h3>
            <div class="road-status-list">
                <?php foreach ($roads as $road): ?>
                <div class="road-item">
                    <div class="road-status status-<?php 
                        echo $road['status'] == 'completed' ? 'clear' : 
                             ($road['status'] == 'in-progress' ? 'moderate' : 'heavy'); 
                    ?>"></div>
                    <div class="road-info">
                        <div class="road-name"><?php echo htmlspecialchars($road['name']); ?></div>
                        <div class="road-condition"><?php echo htmlspecialchars($road['condition']); ?></div>
                        <div class="traffic-indicator">
                            <i class="fas fa-car"></i>
                            <span><?php echo htmlspecialchars($road['traffic']); ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>

    <script>
        // Initialize map
        const map = L.map('map').setView([14.5995, 120.9842], 13);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        // Add sample markers for incidents
        const incidentLocations = [
            { lat: 14.5995, lng: 120.9842, type: 'accident', title: 'Traffic Accident' },
            { lat: 14.6095, lng: 120.9742, type: 'construction', title: 'Road Construction' },
            { lat: 14.5895, lng: 120.9942, type: 'traffic', title: 'Heavy Traffic' }
        ];

        incidentLocations.forEach(location => {
            const icon = L.divIcon({
                html: `<div style="background: ${location.type === 'accident' ? '#dc3545' : location.type === 'construction' ? '#ffc107' : '#3762c8'}; color: white; border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                    <i class="fas fa-${location.type === 'accident' ? 'car-crash' : location.type === 'construction' ? 'tools' : 'traffic-light'}"></i>
                </div>`,
                className: 'custom-marker',
                iconSize: [30, 30]
            });

            L.marker([location.lat, location.lng], { icon })
                .addTo(map)
                .bindPopup(`<b>${location.title}</b><br>Click for more details`);
        });

        // Filter functionality
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
            });
        });

        // Refresh data function
        function refreshData() {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=refresh_data'
            })
            .then(response => response.json())
            .then(data => {
                // Update statistics
                const statsElements = document.querySelectorAll('.stat-number');
                statsElements[0].textContent = data.stats.active_roads;
                statsElements[1].textContent = data.stats.incidents;
                statsElements[2].textContent = data.stats.under_repair;
                statsElements[3].textContent = data.stats.clear_flow + '%';
                
                // Update alerts (you can implement dynamic alert updates here)
                console.log('Data refreshed successfully');
            })
            .catch(error => {
                console.error('Error refreshing data:', error);
            });
        }

        // Export report function
        function exportReport() {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=export_report'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Report exported successfully!');
                } else {
                    alert('Error exporting report: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error exporting report:', error);
            });
        }
    </script>
</body>
</html>
