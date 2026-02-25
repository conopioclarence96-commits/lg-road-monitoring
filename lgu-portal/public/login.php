<?php
// Home Page for LGU Portal - Domain Root
// This file is included by login.php when accessing the domain root

// Try to include database files with error handling
$database_available = false;
$conn = null;

try {
    require_once '../../lgu_staff/includes/config.php';
    require_once '../../lgu_staff/includes/functions.php';
    $database_available = true;
} catch (Exception $e) {
    // Database not available, continue without it
    $database_available = false;
}

// Check if user is already logged in (only if database is available)
if ($database_available && isset($_SESSION['user_id'])) {
    header('Location: ../../lgu_staff/pages/lgu_staff_dashboard.php');
    exit();
}

// Get recent reports for display (only if database is available)
$recent_reports = [];
if ($database_available) {
    try {
        $query = "(SELECT 'transport' as source, id, report_id, title, department, priority, status, created_at FROM road_transportation_reports ORDER BY created_at DESC LIMIT 5)
                  UNION ALL
                  (SELECT 'maintenance' as source, id, report_id, title, department, priority, status, created_at FROM road_maintenance_reports ORDER BY created_at DESC LIMIT 5)
                  ORDER BY created_at DESC LIMIT 5";
        $result = $conn->query($query);
        if ($result) {
            $recent_reports = $result->fetch_all(MYSQLI_ASSOC);
        }
    } catch (Exception $e) {
        // Handle database error gracefully
        $recent_reports = [];
    }
}

// Get statistics (only if database is available)
$stats = [
    'total_reports' => 0,
    'pending' => 0,
    'completed' => 0,
    'in_progress' => 0
];

if ($database_available) {
    try {
        $transport_query = "SELECT COUNT(*) as total, 
                         SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                         SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                         SUM(CASE WHEN status = 'in-progress' THEN 1 ELSE 0 END) as in_progress
                         FROM road_transportation_reports";
        $transport_result = $conn->query($transport_query);
        if ($transport_result) {
            $transport_stats = $transport_result->fetch_assoc();
            $stats['total_reports'] += $transport_stats['total'];
            $stats['pending'] += $transport_stats['pending'];
            $stats['completed'] += $transport_stats['completed'];
            $stats['in_progress'] += $transport_stats['in_progress'];
        }
        
        $maintenance_query = "SELECT COUNT(*) as total, 
                            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                            SUM(CASE WHEN status = 'in-progress' THEN 1 ELSE 0 END) as in_progress
                            FROM road_maintenance_reports";
        $maintenance_result = $conn->query($maintenance_query);
        if ($maintenance_result) {
            $maintenance_stats = $maintenance_result->fetch_assoc();
            $stats['total_reports'] += $maintenance_stats['total'];
            $stats['pending'] += $maintenance_stats['pending'];
            $stats['completed'] += $maintenance_stats['completed'];
            $stats['in_progress'] += $maintenance_stats['in_progress'];
        }
    } catch (Exception $e) {
        // Handle database error gracefully
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LGU Road Monitoring System | Home</title>
    <link rel="stylesheet" href="../styles/style.css">
    <link rel="stylesheet" href="../styles/login.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .home-container {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            flex-direction: column;
        }
        
        .home-header {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 20px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .home-logo {
            font-size: 24px;
            font-weight: 600;
            color: white;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .home-nav {
            display: flex;
            gap: 30px;
        }
        
        .home-nav a {
            color: white;
            text-decoration: none;
            font-weight: 500;
            transition: opacity 0.3s;
        }
        
        .home-nav a:hover {
            opacity: 0.8;
        }
        
        .home-content {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
        }
        
        .home-main {
            text-align: center;
            color: white;
            max-width: 800px;
        }
        
        .home-main h1 {
            font-size: 48px;
            margin-bottom: 20px;
            font-weight: 700;
        }
        
        .home-main p {
            font-size: 20px;
            margin-bottom: 40px;
            opacity: 0.9;
        }
        
        .home-buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .btn-home {
            padding: 15px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: white;
            color: #667eea;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        
        .btn-secondary {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid white;
        }
        
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        
        .stats-section {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 40px;
            margin: 40px;
            border-radius: 16px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 30px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            color: white;
        }
        
        .stat-number {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .stat-label {
            font-size: 14px;
            opacity: 0.8;
        }
        
        .recent-reports {
            margin-top: 40px;
        }
        
        .recent-reports h3 {
            color: white;
            margin-bottom: 20px;
        }
        
        .report-item {
            background: rgba(255, 255, 255, 0.1);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            color: white;
        }
        
        .report-title {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .report-meta {
            font-size: 12px;
            opacity: 0.7;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .status-pending { background: rgba(255, 193, 7, 0.2); color: #ffc107; }
        .status-completed { background: rgba(40, 167, 69, 0.2); color: #28a745; }
        .status-in-progress { background: rgba(23, 162, 184, 0.2); color: #17a2b8; }
    </style>
</head>
<body>
    <div class="home-container">
        <header class="home-header">
            <div class="home-logo">
                🏛️ LGU Road Monitoring System
            </div>
            <nav class="home-nav">
                <a href="?login">Login</a>
                <a href="?register">Register</a>
                <a href="../public_transparency_view.php">Public View</a>
            </nav>
        </header>
        
        <main class="home-content">
            <div class="home-main">
                <h1>Road & Transportation Monitoring</h1>
                <p>Empowering communities with real-time infrastructure monitoring and reporting systems</p>
                
                <div class="home-buttons">
                    <a href="?login" class="btn-home btn-primary">Staff Login</a>
                    <a href="?register" class="btn-home btn-secondary">Report Issue</a>
                </div>
            </div>
        </main>
        
        <section class="stats-section">
            <?php if (!$database_available): ?>
                <div style="background: rgba(220, 53, 69, 0.2); border: 1px solid rgba(220, 53, 69, 0.5); padding: 15px; border-radius: 8px; margin-bottom: 20px; color: white;">
                    <strong>⚠️ Database Connection Issue</strong><br>
                    The system is currently experiencing database connectivity issues. Some features may be unavailable.
                </div>
            <?php endif; ?>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['total_reports']); ?></div>
                    <div class="stat-label">Total Reports</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['pending']); ?></div>
                    <div class="stat-label">Pending</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['in_progress']); ?></div>
                    <div class="stat-label">In Progress</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['completed']); ?></div>
                    <div class="stat-label">Completed</div>
                </div>
            </div>
            
            <div class="recent-reports">
                <h3>Recent Reports</h3>
                <?php if (!empty($recent_reports)): ?>
                    <?php foreach ($recent_reports as $report): ?>
                        <div class="report-item">
                            <div class="report-title">
                                <?php echo htmlspecialchars($report['title']); ?>
                                <span class="status-badge status-<?php echo str_replace(['-', ' '], '', $report['status']); ?>">
                                    <?php echo ucfirst(str_replace('-', ' ', $report['status'])); ?>
                                </span>
                            </div>
                            <div class="report-meta">
                                <?php echo htmlspecialchars($report['department']); ?> • 
                                <?php echo date('M j, Y', strtotime($report['created_at'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="report-item">
                        <div class="report-title">
                            <?php echo $database_available ? 'No recent reports available' : 'Reports unavailable due to database connection issue'; ?>
                        </div>
                        <div class="report-meta">
                            <?php echo $database_available ? 'Check back later for updates' : 'Please contact administrator'; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</body>
</html>
