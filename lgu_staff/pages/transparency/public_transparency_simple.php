<?php
// Session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();

// Session timeout configuration
$session_timeout = 5 * 60; // 5 minutes in seconds

// Check if session has expired
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
    header('Location: ../../login.php?timeout=1');
    exit();
}

// Update last activity time
$_SESSION['last_activity'] = time();

require_once __DIR__ . '/../../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'system_admin' && $_SESSION['role'] !== 'lgu_staff')) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    header('Location: ../../login.php');
    exit();
}

// Simple test data
$stats = [
    'documents' => 156,
    'views' => 2847,
    'downloads' => 423,
    'score' => 98.5
];

$budget = [
    'annual_budget' => 125000000,
    'allocation_percentage' => 89,
    'spent_amount' => 111250000,
    'remaining_amount' => 13750000
];

$projects = [];
$performance = [
    'service_delivery' => 85,
    'citizen_rating' => 4.6,
    'response_time' => 2.3,
    'efficiency_score' => 78,
    'department_performance' => []
];

$publications = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Public Transparency | LGU Staff (Simple)</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/public_transparency.css">
    <style>
        body {
            background: url("../../../assets/img/cityhall.jpeg") center/cover no-repeat fixed;
            position: relative;
            min-height: 100vh;
        }
        
        body::before {
            content: "";
            position: absolute;
            inset: 0;
            backdrop-filter: blur(6px);
            background: rgba(0, 0, 0, 0.35);
            z-index: -1;
        }
        
        .main-content {
            position: relative;
            z-index: 1;
        }
        
        .debug-info {
            background: rgba(255, 255, 255, 0.9);
            padding: 20px;
            margin: 20px;
            border-radius: 8px;
            color: #333;
        }
    </style>
</head>
<body>
    <!-- SIDEBAR -->
    <iframe src="../../includes/sidebar.php" 
            style="position: fixed; width: 250px; height: 100vh; border: none; z-index: 1000;" 
            frameborder="0"
            name="sidebar-frame"
            scrolling="yes">
    </iframe>

    <div class="main-content">
        <div class="debug-info">
            <h2>🔍 Debug Information</h2>
            <p><strong>User ID:</strong> <?php echo $_SESSION['user_id']; ?></p>
            <p><strong>Role:</strong> <?php echo $_SESSION['role']; ?></p>
            <p><strong>Full Name:</strong> <?php echo $_SESSION['full_name']; ?></p>
            <p><strong>Database Connection:</strong> <?php echo $conn ? 'Connected' : 'Not Connected'; ?></p>
            <p><strong>Session Last Activity:</strong> <?php echo date('Y-m-d H:i:s', $_SESSION['last_activity']); ?></p>
            <p><strong>Current Time:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
            <p><strong>Memory Usage:</strong> <?php echo number_format(memory_get_usage() / 1024 / 1024, 2); ?> MB</p>
        </div>

        <!-- Transparency Header -->
        <div class="transparency-header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Public Transparency (Simple Version)</h1>
                    <p>Basic transparency dashboard for testing</p>
                </div>
                <div class="header-actions">
                    <a href="public_transparency.php" class="btn-action">
                        <i class="fas fa-arrow-left"></i>
                        Back to Full Version
                    </a>
                </div>
            </div>
        </div>

        <!-- Transparency Statistics -->
        <div class="transparency-stats">
            <div class="transparency-stat">
                <div class="stat-number"><?php echo number_format($stats['documents']); ?></div>
                <div class="stat-label">Public Documents</div>
            </div>
            <div class="transparency-stat">
                <div class="stat-number"><?php echo number_format($stats['views']); ?></div>
                <div class="stat-label">Total Views</div>
            </div>
            <div class="transparency-stat">
                <div class="stat-number"><?php echo number_format($stats['downloads']); ?></div>
                <div class="stat-label">Downloads</div>
            </div>
            <div class="transparency-stat">
                <div class="stat-number"><?php echo $stats['score']; ?>%</div>
                <div class="stat-label">Transparency Score</div>
            </div>
        </div>

        <div class="debug-info">
            <h3>✅ If you can see this page, the basic functionality works!</h3>
            <p>The issue with the full version is likely related to:</p>
            <ul>
                <li>Missing database tables (publications, budget_allocation, infrastructure_projects)</li>
                <li>Complex database queries causing timeouts</li>
                <li>Memory issues with large datasets</li>
            </ul>
            <p><a href="../../debug_transparency.php">Run Debug Script</a> to identify the specific issue.</p>
        </div>
    </div>
</body>
</html>
