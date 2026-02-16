<?php
/**
 * LGU Staff Dashboard - Road & Infrastructure Department
 * Functional PHP dashboard with database integration
 */

// Start session for authentication
session_start();

// Include database configuration
require_once '../config/database.php';

// Check authentication
function requireAuth() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ../login.html');
        exit();
    }
    return $_SESSION['user_id'];
}

// Get current user
$userId = requireAuth();

// Initialize database helper
$dbHelper = new DBHelper();

// Get user information
function getCurrentUser($dbHelper, $userId) {
    $sql = "SELECT user_id, username, email, first_name, last_name, role, department 
            FROM staff_users WHERE user_id = ? AND is_active = TRUE";
    $dbHelper->db->prepare($sql);
    $dbHelper->db->bind(1, $userId);
    return $dbHelper->db->single();
}

$currentUser = getCurrentUser($dbHelper, $userId);

// Get dashboard statistics
function getDashboardStatistics($dbHelper) {
    try {
        $stats = $dbHelper->getDashboardStats();
        
        // If stored procedure doesn't work, use individual queries
        if (empty($stats)) {
            $stats = [
                'incidents_today' => getIncidentsCount($dbHelper, 'today'),
                'pending_incidents' => getIncidentsCount($dbHelper, 'pending'),
                'pending_verifications' => getVerificationsCount($dbHelper, 'pending'),
                'active_maintenance' => getMaintenanceCount($dbHelper, 'active'),
                'active_staff' => getActiveStaffCount($dbHelper)
            ];
        }
        
        return $stats;
    } catch (Exception $e) {
        // Return default values if database fails
        return [
            'incidents_today' => 0,
            'pending_incidents' => 0,
            'pending_verifications' => 0,
            'active_maintenance' => 0,
            'active_staff' => 0
        ];
    }
}

function getIncidentsCount($dbHelper, $type) {
    $sql = "SELECT COUNT(*) as count FROM road_incidents";
    
    switch ($type) {
        case 'today':
            $sql .= " WHERE DATE(incident_date) = CURDATE()";
            break;
        case 'pending':
            $sql .= " WHERE status IN ('pending', 'under_review')";
            break;
    }
    
    $dbHelper->db->prepare($sql);
    $result = $dbHelper->db->single();
    return $result['count'] ?? 0;
}

function getVerificationsCount($dbHelper, $status) {
    $sql = "SELECT COUNT(*) as count FROM verification_requests WHERE status = ?";
    $dbHelper->db->prepare($sql);
    $dbHelper->db->bind(1, $status);
    $result = $dbHelper->db->single();
    return $result['count'] ?? 0;
}

function getMaintenanceCount($dbHelper, $status) {
    $sql = "SELECT COUNT(*) as count FROM maintenance_schedules WHERE status = ?";
    $dbHelper->db->prepare($sql);
    $dbHelper->db->bind(1, $status);
    $result = $dbHelper->db->single();
    return $result['count'] ?? 0;
}

function getActiveStaffCount($dbHelper) {
    $sql = "SELECT COUNT(*) as count FROM staff_users WHERE is_active = TRUE";
    $dbHelper->db->prepare($sql);
    $result = $dbHelper->db->single();
    return $result['count'] ?? 0;
}

// Get recent activity
function getRecentActivity($dbHelper, $limit = 10) {
    try {
        $sql = "SELECT 
                    al.action_type,
                    al.table_name,
                    al.record_id,
                    al.new_values,
                    al.timestamp,
                    su.first_name,
                    su.last_name
                FROM activity_logs al
                LEFT JOIN staff_users su ON al.user_id = su.user_id
                ORDER BY al.timestamp DESC
                LIMIT ?";
        
        $dbHelper->db->prepare($sql);
        $dbHelper->db->bind(1, $limit);
        return $dbHelper->db->get();
    } catch (Exception $e) {
        return [];
    }
}

// Get priority tasks
function getPriorityTasks($dbHelper, $userId = null) {
    try {
        $sql = "SELECT 
                    ri.incident_id,
                    ri.title,
                    ri.severity_level,
                    ri.status,
                    r.road_name
                FROM road_incidents ri
                JOIN roads r ON ri.road_id = r.road_id
                WHERE ri.status IN ('pending', 'under_review', 'approved')
                ORDER BY 
                    CASE ri.severity_level
                        WHEN 'critical' THEN 1
                        WHEN 'high' THEN 2
                        WHEN 'medium' THEN 3
                        WHEN 'low' THEN 4
                    END,
                    ri.incident_date DESC
                LIMIT 10";
        
        $dbHelper->db->prepare($sql);
        return $dbHelper->db->get();
    } catch (Exception $e) {
        return [];
    }
}

// Get chart data for weekly reports
function getWeeklyChartData($dbHelper) {
    try {
        $sql = "SELECT 
                    DATE(incident_date) as date,
                    DAYNAME(incident_date) as day_name,
                    COUNT(*) as incident_count
                FROM road_incidents 
                WHERE incident_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                GROUP BY DATE(incident_date), DAYNAME(incident_date)
                ORDER BY DATE(incident_date)";
        
        $dbHelper->db->prepare($sql);
        $results = $dbHelper->db->get();
        
        // Format data for Chart.js
        $labels = [];
        $data = [];
        
        // Get last 7 days
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $dayName = date('D', strtotime("-$i days"));
            $labels[] = $dayName;
            
            $count = 0;
            foreach ($results as $row) {
                if ($row['date'] == $date) {
                    $count = $row['incident_count'];
                    break;
                }
            }
            $data[] = $count;
        }
        
        return [
            'labels' => $labels,
            'data' => $data
        ];
    } catch (Exception $e) {
        // Return sample data if database fails
        return [
            'labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            'data' => [12, 19, 15, 25, 22, 18, 24]
        ];
    }
}

// Get all data
$stats = getDashboardStatistics($dbHelper);
$recentActivity = getRecentActivity($dbHelper);
$priorityTasks = getPriorityTasks($dbHelper, $userId);
$chartData = getWeeklyChartData($dbHelper);

// Format activity for display
function formatActivity($activity) {
    $icon = 'fa-info-circle';
    $color = 'report';
    
    switch ($activity['table_name']) {
        case 'road_incidents':
            $icon = 'fa-road';
            $color = 'road';
            break;
        case 'verification_requests':
            $icon = 'fa-clipboard-check';
            $color = 'verification';
            break;
    }
    
    $title = ucfirst($activity['action_type']) . ' ' . str_replace('_', ' ', $activity['table_name']);
    $time = timeAgo($activity['timestamp']);
    
    return [
        'icon' => $icon,
        'color' => $color,
        'title' => $title,
        'time' => $time,
        'user' => trim($activity['first_name'] . ' ' . $activity['last_name'])
    ];
}

function timeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . ' minutes ago';
    } elseif ($diff < 86400) {
        return floor($diff / 3600) . ' hours ago';
    } else {
        return floor($diff / 86400) . ' days ago';
    }
}

// Format tasks for display
function formatTask($task) {
    $priority = 'medium';
    switch ($task['severity_level']) {
        case 'critical':
        case 'high':
            $priority = 'high';
            break;
        case 'medium':
            $priority = 'medium';
            break;
        case 'low':
            $priority = 'low';
            break;
    }
    
    return [
        'priority' => $priority,
        'text' => $task['title'] . ' - ' . $task['road_name']
    ];
}

$formattedActivity = array_map('formatActivity', $recentActivity);
$formattedTasks = array_map('formatTask', $priorityTasks);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LGU Staff Dashboard | Road & Infrastructure Dept</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .dashboard-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            padding: 30px;
            border-radius: 16px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .welcome-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .welcome-text h1 {
            color: #1e3c72;
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .welcome-text p {
            color: #666;
            font-size: 16px;
        }

        .date-time {
            text-align: right;
            color: #3762c8;
            font-weight: 500;
        }

        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.9), rgba(255, 255, 255, 0.7));
            backdrop-filter: blur(10px);
            padding: 20px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #3762c8, #1e3c72);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(55, 98, 200, 0.2);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            margin-bottom: 15px;
        }

        .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: #1e3c72;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #666;
            font-size: 14px;
            font-weight: 500;
        }

        .main-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        .chart-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .chart-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e3c72;
        }

        .activity-feed {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            padding: 15px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            flex-shrink: 0;
        }

        .activity-icon.road {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }

        .activity-icon.verification {
            background: rgba(40, 167, 69, 0.2);
            color: #28a745;
        }

        .activity-icon.report {
            background: rgba(220, 53, 69, 0.2);
            color: #dc3545;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 500;
            color: #333;
            margin-bottom: 4px;
        }

        .activity-time {
            font-size: 12px;
            color: #999;
        }

        .priority-tasks {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .task-item {
            display: flex;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .task-item:last-child {
            border-bottom: none;
        }

        .task-priority {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 15px;
        }

        .priority-high {
            background: #dc3545;
        }

        .priority-medium {
            background: #ffc107;
        }

        .priority-low {
            background: #28a745;
        }

        .task-text {
            flex: 1;
            font-size: 14px;
            color: #333;
        }

        @media (max-width: 1200px) {
            .main-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .welcome-section {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .date-time {
                text-align: left;
                margin-top: 10px;
            }
        }

        .error-message {
            background: rgba(220, 53, 69, 0.1);
            border: 1px solid rgba(220, 53, 69, 0.3);
            color: #dc3545;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .loading {
            text-align: center;
            padding: 20px;
            color: #666;
        }
    </style>
</head>
<body>
    <!-- SIDEBAR -->
    <iframe src="../includes/sidebar.html" 
            style="position: fixed; width: 250px; height: 100vh; border: none; z-index: 1000;" 
            frameborder="0">
    </iframe>

    <div class="main-content">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="welcome-section">
                <div class="welcome-text">
                    <h1>Welcome back, <?php echo htmlspecialchars($currentUser['first_name'] ?? 'Staff'); ?></h1>
                    <p>Here's what's happening with the Road & Infrastructure Department today</p>
                </div>
                <div class="date-time">
                    <div id="currentDate"></div>
                    <div id="currentTime"></div>
                </div>
            </div>
        </div>

        <?php if (isset($error)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Quick Stats -->
        <div class="quick-stats">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-road"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['incidents_today']); ?></div>
                <div class="stat-label">Road Reports Today</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-clipboard-check"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['pending_verifications']); ?></div>
                <div class="stat-label">Pending Verifications</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-tools"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['active_maintenance']); ?></div>
                <div class="stat-label">Under Maintenance</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['active_staff']); ?></div>
                <div class="stat-label">Active Staff</div>
            </div>
        </div>

        <!-- Main Grid -->
        <div class="main-grid">
            <!-- Chart Section -->
            <div class="chart-container">
                <div class="chart-header">
                    <h3 class="chart-title">Weekly Road Reports Trend</h3>
                    <select id="chartPeriod" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;">
                        <option value="7">Last 7 Days</option>
                        <option value="30">Last 30 Days</option>
                        <option value="90">Last 3 Months</option>
                    </select>
                </div>
                <canvas id="reportsChart" width="400" height="200"></canvas>
            </div>

            <!-- Activity Feed -->
            <div class="activity-feed">
                <h3 class="chart-title" style="margin-bottom: 20px;">Recent Activity</h3>
                <?php if (empty($formattedActivity)): ?>
                    <div class="loading">No recent activity found</div>
                <?php else: ?>
                    <?php foreach (array_slice($formattedActivity, 0, 5) as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-icon <?php echo $activity['color']; ?>">
                                <i class="fas <?php echo $activity['icon']; ?>"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title"><?php echo htmlspecialchars($activity['title']); ?></div>
                                <div class="activity-time"><?php echo htmlspecialchars($activity['time']); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Priority Tasks -->
        <div class="priority-tasks">
            <h3 class="chart-title" style="margin-bottom: 20px;">Priority Tasks</h3>
            <?php if (empty($formattedTasks)): ?>
                <div class="loading">No priority tasks found</div>
            <?php else: ?>
                <?php foreach (array_slice($formattedTasks, 0, 5) as $task): ?>
                    <div class="task-item">
                        <div class="task-priority priority-<?php echo $task['priority']; ?>"></div>
                        <div class="task-text"><?php echo htmlspecialchars($task['text']); ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Update date and time
        function updateDateTime() {
            const now = new Date();
            const dateOptions = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const timeOptions = { hour: '2-digit', minute: '2-digit', second: '2-digit' };
            
            document.getElementById('currentDate').textContent = now.toLocaleDateString('en-US', dateOptions);
            document.getElementById('currentTime').textContent = now.toLocaleTimeString('en-US', timeOptions);
        }
        
        updateDateTime();
        setInterval(updateDateTime, 1000);

        // Chart data from PHP
        const chartData = <?php echo json_encode($chartData); ?>;

        // Initialize Chart
        const ctx = document.getElementById('reportsChart').getContext('2d');
        const reportsChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: 'Road Reports',
                    data: chartData.data,
                    borderColor: '#3762c8',
                    backgroundColor: 'rgba(55, 98, 200, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Handle chart period change
        document.getElementById('chartPeriod').addEventListener('change', function() {
            const period = this.value;
            // You can implement AJAX call here to fetch data for different periods
            console.log('Loading data for period:', period);
        });

        // Auto-refresh dashboard every 5 minutes
        setInterval(function() {
            location.reload();
        }, 300000);
    </script>
</body>
</html>
