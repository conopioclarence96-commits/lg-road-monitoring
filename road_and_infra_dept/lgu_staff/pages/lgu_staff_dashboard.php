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
    header('Location: ../login.php');
    exit();
}

// Function to get dashboard statistics
function getDashboardStatistics($conn) {
    $stats = [];
    
    // Today's road reports
    $result = $conn->query("SELECT COUNT(*) as today_reports FROM road_transportation_reports WHERE DATE(created_at) = CURDATE()");
    $transport_today = $result->fetch_assoc()['today_reports'];
    
    $result = $conn->query("SELECT COUNT(*) as today_reports FROM road_maintenance_reports WHERE DATE(created_at) = CURDATE()");
    $maintenance_today = $result->fetch_assoc()['today_reports'];
    $stats['today_reports'] = $transport_today + $maintenance_today;
    
    // Pending verifications
    $result = $conn->query("SELECT COUNT(*) as pending FROM road_transportation_reports WHERE status = 'pending'");
    $transport_pending = $result->fetch_assoc()['pending'];
    
    $result = $conn->query("SELECT COUNT(*) as pending FROM road_maintenance_reports WHERE status = 'pending'");
    $maintenance_pending = $result->fetch_assoc()['pending'];
    $stats['pending_verifications'] = $transport_pending + $maintenance_pending;
    
    // Under maintenance (in-progress)
    $result = $conn->query("SELECT COUNT(*) as in_progress FROM road_transportation_reports WHERE status = 'in-progress'");
    $transport_progress = $result->fetch_assoc()['in_progress'];
    
    $result = $conn->query("SELECT COUNT(*) as in_progress FROM road_maintenance_reports WHERE status = 'in-progress'");
    $maintenance_progress = $result->fetch_assoc()['in_progress'];
    $stats['under_maintenance'] = $transport_progress + $maintenance_progress;
    
    // Completed this month
    $result = $conn->query("SELECT COUNT(*) as completed FROM road_transportation_reports WHERE status = 'completed' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
    $transport_completed = $result->fetch_assoc()['completed'];
    
    $result = $conn->query("SELECT COUNT(*) as completed FROM road_maintenance_reports WHERE status = 'completed' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
    $maintenance_completed = $result->fetch_assoc()['completed'];
    $stats['completed_month'] = $transport_completed + $maintenance_completed;
    
    return $stats;
}

// Function to get recent activity
function getRecentActivity($conn) {
    $query = "(SELECT 'transport' as source, 'road' as type, title, created_at FROM road_transportation_reports ORDER BY created_at DESC LIMIT 5)
              UNION ALL
              (SELECT 'maintenance' as source, 'maintenance' as type, title, created_at FROM road_maintenance_reports ORDER BY created_at DESC LIMIT 5)
              ORDER BY created_at DESC LIMIT 5";
    $result = $conn->query($query);
    return $result;
}

// Function to get priority tasks
function getPriorityTasks($conn) {
    $query = "(SELECT 'High' as priority, title, created_at FROM road_transportation_reports WHERE status = 'pending' AND priority = 'high' ORDER BY created_at DESC LIMIT 3)
              UNION ALL
              (SELECT 'High' as priority, title, created_at FROM road_maintenance_reports WHERE status = 'pending' AND priority = 'high' ORDER BY created_at DESC LIMIT 3)
              UNION ALL
              (SELECT 'Medium' as priority, title, created_at FROM road_transportation_reports WHERE status = 'pending' AND priority = 'medium' ORDER BY created_at DESC LIMIT 2)
              UNION ALL
              (SELECT 'Medium' as priority, title, created_at FROM road_maintenance_reports WHERE status = 'pending' AND priority = 'medium' ORDER BY created_at DESC LIMIT 2)
              ORDER BY FIELD(priority, 'High', 'Medium'), created_at DESC LIMIT 5";
    $result = $conn->query($query);
    return $result;
}

// Function to get weekly chart data
function getWeeklyChartData($conn) {
    $data = ['reports' => [], 'verifications' => []];
    $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    
    // Get data for the current week
    $current_week = date('W');
    $current_year = date('Y');
    
    foreach ($days as $index => $day) {
        $day_of_week = ($index + 2); // MySQL DAYOFWEEK: 1=Sunday, 2=Monday, etc.
        
        // Get road reports for this day
        $transport_query = "SELECT COUNT(*) as count FROM road_transportation_reports 
                           WHERE DAYOFWEEK(created_at) = $day_of_week 
                           AND WEEK(created_at, 1) = WEEK(CURRENT_DATE, 1) 
                           AND YEAR(created_at) = $current_year";
        $result = $conn->query($transport_query);
        $transport_count = $result->fetch_assoc()['count'];
        
        $maintenance_query = "SELECT COUNT(*) as count FROM road_maintenance_reports 
                             WHERE DAYOFWEEK(created_at) = $day_of_week 
                             AND WEEK(created_at, 1) = WEEK(CURRENT_DATE, 1) 
                             AND YEAR(created_at) = $current_year";
        $result = $conn->query($maintenance_query);
        $maintenance_count = $result->fetch_assoc()['count'];
        
        $data['reports'][] = (int)($transport_count + $maintenance_count);
        
        // Get verification activities for this day
        // Check if audit_trails table exists and has data
        $audit_check = $conn->query("SHOW TABLES LIKE 'audit_trails'");
        if ($audit_check->num_rows > 0) {
            $verification_query = "SELECT COUNT(*) as count FROM audit_trails 
                                 WHERE audit_type = 'verification' 
                                 AND DAYOFWEEK(created_at) = $day_of_week 
                                 AND WEEK(created_at, 1) = WEEK(CURRENT_DATE, 1) 
                                 AND YEAR(created_at) = $current_year";
            $result = $conn->query($verification_query);
            $verification_count = $result->fetch_assoc()['count'];
        } else {
            // Fallback: count status changes to 'completed' or 'approved' as verifications
            $verification_query = "(SELECT COUNT(*) as count FROM road_transportation_reports 
                                   WHERE status IN ('completed', 'approved') 
                                   AND DAYOFWEEK(updated_at) = $day_of_week 
                                   AND WEEK(updated_at, 1) = WEEK(CURRENT_DATE, 1) 
                                   AND YEAR(updated_at) = $current_year)
                                   UNION ALL
                                   (SELECT COUNT(*) as count FROM road_maintenance_reports 
                                   WHERE status IN ('completed', 'approved') 
                                   AND DAYOFWEEK(updated_at) = $day_of_week 
                                   AND WEEK(updated_at, 1) = WEEK(CURRENT_DATE, 1) 
                                   AND YEAR(updated_at) = $current_year)";
            $result = $conn->query($verification_query);
            $verification_count = 0;
            while ($row = $result->fetch_assoc()) {
                $verification_count += $row['count'];
            }
        }
        
        $data['verifications'][] = (int)$verification_count;
    }
    
    // If no data for current week, get last week's data as fallback
    if (array_sum($data['reports']) == 0) {
        foreach ($days as $index => $day) {
            $day_of_week = ($index + 2);
            
            // Get last week's data
            $transport_query = "SELECT COUNT(*) as count FROM road_transportation_reports 
                               WHERE DAYOFWEEK(created_at) = $day_of_week 
                               AND WEEK(created_at, 1) = WEEK(CURRENT_DATE - INTERVAL 1 WEEK, 1)";
            $result = $conn->query($transport_query);
            $transport_count = $result->fetch_assoc()['count'];
            
            $maintenance_query = "SELECT COUNT(*) as count FROM road_maintenance_reports 
                                 WHERE DAYOFWEEK(created_at) = $day_of_week 
                                 AND WEEK(created_at, 1) = WEEK(CURRENT_DATE - INTERVAL 1 WEEK, 1)";
            $result = $conn->query($maintenance_query);
            $maintenance_count = $result->fetch_assoc()['count'];
            
            $data['reports'][$index] = (int)($transport_count + $maintenance_count);
            
            // Get verifications for last week
            $verification_query = "(SELECT COUNT(*) as count FROM road_transportation_reports 
                                   WHERE status IN ('completed', 'approved') 
                                   AND DAYOFWEEK(updated_at) = $day_of_week 
                                   AND WEEK(updated_at, 1) = WEEK(CURRENT_DATE - INTERVAL 1 WEEK, 1))
                                   UNION ALL
                                   (SELECT COUNT(*) as count FROM road_maintenance_reports 
                                   WHERE status IN ('completed', 'approved') 
                                   AND DAYOFWEEK(updated_at) = $day_of_week 
                                   AND WEEK(updated_at, 1) = WEEK(CURRENT_DATE - INTERVAL 1 WEEK, 1))";
            $result = $conn->query($verification_query);
            $verification_count = 0;
            while ($row = $result->fetch_assoc()) {
                $verification_count += $row['count'];
            }
            
            $data['verifications'][$index] = (int)$verification_count;
        }
    }
    
    return $data;
}

// Get data
$stats = getDashboardStatistics($conn);
$recent_activity = getRecentActivity($conn);
$priority_tasks = getPriorityTasks($conn);
$chart_data = getWeeklyChartData($conn);
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

        #reportsChart {
            max-height: 300px !important;
            width: 100% !important;
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
    </style>
</head>
<body>
    <!-- SIDEBAR -->
    <iframe src="../includes/sidebar.php" 
            style="position: fixed; width: 250px; height: 100vh; border: none; z-index: 1000;" 
            frameborder="0"
            name="sidebar-frame"
            scrolling="no"
            loading="lazy"
            referrerpolicy="no-referrer">
    </iframe>

    <div class="main-content">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="welcome-section">
                <div class="welcome-text">
                    <h1>Welcome back, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Staff'); ?></h1>
                    <p>Here's what's happening with the Road & Infrastructure Department today</p>
                </div>
                <div class="date-time">
                    <div id="currentDate"></div>
                    <div id="currentTime"></div>
                </div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="quick-stats">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-road"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['today_reports']); ?></div>
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
                <div class="stat-number"><?php echo number_format($stats['under_maintenance']); ?></div>
                <div class="stat-label">Under Maintenance</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['completed_month']); ?></div>
                <div class="stat-label">Completed This Month</div>
            </div>
        </div>

        <!-- Main Grid -->
        <div class="main-grid">
            <!-- Chart Section -->
            <div class="chart-container">
                <div class="chart-header">
                    <h3 class="chart-title">Weekly Road Reports Trend</h3>
                    <select id="periodSelector" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;">
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
                <?php if ($recent_activity->num_rows > 0): ?>
                    <?php while ($activity = $recent_activity->fetch_assoc()): ?>
                        <div class="activity-item">
                            <div class="activity-icon <?php echo $activity['type']; ?>">
                                <i class="fas fa-<?php echo getActivityIcon($activity['type']); ?>"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title"><?php echo htmlspecialchars($activity['title']); ?></div>
                                <div class="activity-time"><?php echo getTimeAgo($activity['created_at']); ?></div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 20px; color: #666;">
                        <i class="fas fa-clock" style="font-size: 24px; margin-bottom: 10px; opacity: 0.5;"></i>
                        <p>No recent activity</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Priority Tasks -->
        <div class="priority-tasks">
            <h3 class="chart-title" style="margin-bottom: 20px;">Priority Tasks</h3>
            <?php if ($priority_tasks->num_rows > 0): ?>
                <?php while ($task = $priority_tasks->fetch_assoc()): ?>
                    <div class="task-item">
                        <div class="task-priority priority-<?php echo strtolower($task['priority']); ?>"></div>
                        <div class="task-text"><?php echo htmlspecialchars($task['title']); ?></div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div style="text-align: center; padding: 20px; color: #666;">
                    <i class="fas fa-check-circle" style="font-size: 24px; margin-bottom: 10px; opacity: 0.5;"></i>
                    <p>No priority tasks</p>
                </div>
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

        // Initialize Chart
        const ctx = document.getElementById('reportsChart').getContext('2d');
        const reportsChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']); ?>,
                datasets: [{
                    label: 'Road Reports',
                    data: <?php echo json_encode($chart_data['reports']); ?>,
                    borderColor: '#3762c8',
                    backgroundColor: 'rgba(55, 98, 200, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Verifications',
                    data: <?php echo json_encode($chart_data['verifications']); ?>,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                aspectRatio: 2,
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 50 // Set a reasonable max value to prevent expansion
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

        // Handle period selector change
        document.getElementById('periodSelector').addEventListener('change', function() {
            const period = this.value;
            updateChartData(period);
        });

        // Function to update chart data based on selected period
        function updateChartData(period) {
            fetch(`get_chart_data.php?period=${period}`)
                .then(response => response.json())
                .then(data => {
                    reportsChart.data.datasets[0].data = data.reports;
                    reportsChart.data.datasets[1].data = data.verifications;
                    reportsChart.update();
                })
                .catch(error => {
                    console.error('Error fetching chart data:', error);
                    // Fallback to current data if fetch fails
                    showNotification('Unable to update chart data', 'error');
                });
        }

        // Show notification function
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = 'notification ' + type;
            notification.textContent = message;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                border-radius: 8px;
                color: white;
                font-weight: 500;
                z-index: 10000;
                animation: slideIn 0.3s ease;
                background: ${type === 'error' ? '#dc3545' : type === 'success' ? '#28a745' : '#17a2b8'};
            `;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }
    </script>
</body>
</html>

<?php
// Helper functions
function getActivityIcon($type) {
    $icons = [
        'road' => 'road',
        'maintenance' => 'tools',
        'verification' => 'clipboard-check',
        'report' => 'file-alt'
    ];
    
    return $icons[$type] ?? 'file';
}

function getTimeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M d, Y', $time);
    }
}
?>
