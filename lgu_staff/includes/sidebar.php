<?php
// Cache control headers to prevent iframe reloading
header('Cache-Control: max-age=3600, private');
header('Pragma: cache');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');

// Session settings (must be set before session_start)
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS

session_start();
require_once 'config.php';
require_once 'functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: /lg-road-monitoring/lgu_staff/login.php');
    exit();
}

// Function to get user information
function getUserInfo() {
    global $conn;
    
    if ($conn && isset($_SESSION['user_id'])) {
        $stmt = $conn->prepare("SELECT username, full_name, email, role FROM users WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    return [
        'username' => 'Staff User',
        'full_name' => 'LGU Staff',
        'email' => 'staff@lgu.gov.ph',
        'role' => 'lgu_staff'
    ];
}

// Function to get navigation items with role-based access
function getNavigationItems($user_role) {
    $base_items = [
        'main' => [
            [
                'href' => '../pages/main/lgu_staff_dashboard.php',
                'icon' => 'speedometer2',
                'title' => 'Staff Dashboard',
                'roles' => ['lgu_staff']
            ],
            [
                'href' => '../pages/main/change_info.php',
                'icon' => 'user-edit',
                'title' => 'Change Information',
                'roles' => ['lgu_staff']
            ],
            [
                'href' => '../pages/main/admin_dashboard.php',
                'icon' => 'speedometer2',
                'title' => 'Admin Dashboard',
                'roles' => ['system_admin']
            ],
            [
                'href' => '../pages/main/manage_accounts.php',
                'icon' => 'users',
                'title' => 'Manage Accounts',
                'roles' => ['system_admin']
            ],
            [
                'href' => '../pages/main/account_approvals.php',
                'icon' => 'clipboard-check',
                'title' => 'Account Approvals',
                'roles' => ['system_admin']
            ],
            [
                'href' => '../pages/main/create_staff_account.php',
                'icon' => 'person-plus',
                'title' => 'Create Staff Account',
                'roles' => ['system_admin']
            ]
        ],
        'monitoring' => [
            [
                'href' => '../pages/monitoring/road_transportation_monitoring.php',
                'icon' => 'map',
                'title' => 'Road and Transportation Monitoring',
                'roles' => ['lgu_staff', 'system_admin']
            ],
            [
                'href' => '../pages/monitoring/verification_monitoring.php',
                'icon' => 'shield-check',
                'title' => 'Verification & Monitoring Reports',
                'roles' => ['system_admin']
            ],
            [
                'href' => '../pages/monitoring/report_management.php',
                'icon' => 'clipboard-data',
                'title' => 'Report Management',
                'roles' => ['system_admin']
            ]
        ],
        'transparency' => [
            [
                'href' => '../pages/transparency/public_transparency.php',
                'icon' => 'eye',
                'title' => 'Public Transparency',
                'roles' => ['system_admin', 'lgu_staff']
            ]
        ],
        'system' => [
            [
                'href' => '../pages/main/notifications.php',
                'icon' => 'bell',
                'title' => 'Notifications',
                'roles' => ['system_admin', 'lgu_staff']
            ],
            [
                'href' => '../pages/main/settings.php',
                'icon' => 'gear',
                'title' => 'Settings',
                'roles' => ['system_admin','lgu_staff']
            ]
        ]
    ];
    
    // Filter items based on user role
    $filtered_items = [];
    foreach ($base_items as $section => $items) {
        $filtered_items[$section] = array_filter($items, function($item) use ($user_role) {
            return in_array($user_role, $item['roles']);
        });
    }
    
    return $filtered_items;
}

// Function to get notification count
function getNotificationCount($user_role = '', $user_id = 0) {
    global $conn;
    
    $count = 0;
    
    if ($conn) {
        if ($user_role === 'system_admin') {
            try {
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status = 'pending'");
                $stmt->execute();
                $result = $stmt->get_result();
                $count += $result->fetch_assoc()['count'];
                $stmt->close();
            } catch (Exception $e) {
                // Ignore errors
            }
            
            try {
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE account_status = 'pending'");
                $stmt->execute();
                $result = $stmt->get_result();
                $count += $result->fetch_assoc()['count'];
                $stmt->close();
            } catch (Exception $e) {
                // Ignore errors
            }
            
            try {
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM change_requests WHERE status = 'pending'");
                $stmt->execute();
                $result = $stmt->get_result();
                $count += $result->fetch_assoc()['count'];
                $stmt->close();
            } catch (Exception $e) {
                // Ignore errors
            }
        } elseif ($user_role === 'lgu_staff' && $user_id > 0) {
            try {
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM change_requests WHERE user_id = ? AND status != 'pending'");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $count += $result->fetch_assoc()['count'];
                $stmt->close();
            } catch (Exception $e) {
                // Ignore errors
            }

            try {
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM road_transportation_reports WHERE created_by = ? AND status IN ('completed', 'cancelled')");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $count += $result->fetch_assoc()['count'];
                $stmt->close();
            } catch (Exception $e) {
                // Ignore errors
            }
        }
    }
    
    return $count;
}

// Function to get notification details for dropdown
function getNotifications() {
    global $conn;
    
    $notifications = ['reports' => [], 'users' => []];
    
    if ($conn) {
        try {
            $stmt = $conn->prepare("
                SELECT id, report_id, title, department, priority, description, location, reporter_name, created_at
                FROM road_transportation_reports 
                WHERE status = 'pending'
                ORDER BY CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 END, created_at DESC
                LIMIT 5
            ");
            $stmt->execute();
            $notifications['reports'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } catch (Exception $e) {
            // Ignore errors
        }
        
        try {
            $stmt = $conn->prepare("
                SELECT id, full_name, role, department, created_at
                FROM users 
                WHERE account_status = 'pending'
                ORDER BY created_at DESC
                LIMIT 5
            ");
            $stmt->execute();
            $notifications['users'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } catch (Exception $e) {
            // Ignore errors
        }
    }
    
    return $notifications;
}

// Get user data
$user_info = getUserInfo();
$user_role = $_SESSION['role'] ?? $user_info['role'] ?? 'citizen'; // Use session role first
$nav_items = getNavigationItems($user_role);
$notification_count = getNotificationCount($user_role, $_SESSION['user_id'] ?? 0);
$notifications = ($user_role === 'system_admin') ? getNotifications() : ['reports' => [], 'users' => []];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="max-age=3600, private">
    <meta http-equiv="Pragma" content="cache">
    <meta http-equiv="Expires" content="3600">
    <title>LGU Staff Sidebar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../styles/style.css" rel="stylesheet">
    <link href="../styles/sidebar.css" rel="stylesheet">
    <link href="../styles/transition.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #3762c8 0%, #1e3c72 100%);
            height: 100vh;
            overflow: hidden;
        }

        .sidebar {
            width: 100%;
            height: 100vh;
            display: flex;
            flex-direction: column;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
            position: relative;
        }

        .sidebar-header {
            padding: 20px;
            background: linear-gradient(135deg, #3762c8 0%, #1e3c72 100%);
            color: white;
            text-align: center;
        }

        .sidebar-header h2 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .sidebar-header p {
            font-size: 12px;
            opacity: 0.9;
        }

        .user-info {
            margin-top: 10px;
            padding-top: 10px;
        }

        .user-name {
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 2px;
        }

        .user-role {
            font-size: 11px;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 10px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .sidebar-content {
            flex: 1;
            overflow-y: auto;
            padding: 20px 0;
        }

        .nav-section {
            margin-bottom: 25px;
        }

        .nav-section-title {
            padding: 0 20px 10px;
            font-size: 11px;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: #333;
            text-decoration: none;
            border-left: 3px solid transparent;
            font-size: 14px;
            position: relative;
            overflow: hidden;
        }


        .nav-link:hover {
            background: rgba(55, 98, 200, 0.1);
            border-left-color: #3762c8;
            color: #3762c8;
        }

        .nav-link.active {
            background: rgba(55, 98, 200, 0.15);
            border-left-color: #3762c8;
            color: #3762c8;
            font-weight: 500;
        }

        .nav-link.active::after {
            content: '';
            position: absolute;
            right: 10px;
            top: 50%;
            margin-top: -3px;
            width: 6px;
            height: 6px;
            background: #3762c8;
            border-radius: 50%;
        }


        .nav-link svg {
            margin-right: 12px;
            flex-shrink: 0;
        }

        .logout-btn {
            margin: 20px;
            padding: 12px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logout-btn:hover {
            background: #c82333;
        }

        .nav-link.nav-link-logout {
            color: #c82333;
            margin-top: 8px;
        }
        .nav-link.nav-link-logout:hover {
            background: rgba(220, 53, 69, 0.1);
            border-left-color: #dc3545;
            color: #c82333;
        }

        /* Scrollbar styling */
        .sidebar-content::-webkit-scrollbar {
            width: 4px;
        }

        .sidebar-content::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        .sidebar-content::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 2px;
        }

        .sidebar-content::-webkit-scrollbar-thumb:hover {
            background: #555;
        }

        /* Notification Dropdown */
        .notification-wrapper {
            position: relative;
        }

        .notification-dropdown {
            display: none;
            position: fixed;
            left: 250px;
            top: 0;
            width: 360px;
            max-height: 100vh;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.15);
            z-index: 1001;
            overflow-y: auto;
            border-left: 1px solid rgba(55, 98, 200, 0.1);
        }

        .notification-dropdown.active {
            display: block;
        }

        .notif-dropdown-header {
            position: sticky;
            top: 0;
            background: linear-gradient(135deg, #3762c8 0%, #1e3c72 100%);
            color: white;
            padding: 18px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 2;
        }

        .notif-dropdown-header h3 {
            font-size: 16px;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .notif-dropdown-header .notif-count-badge {
            background: rgba(255, 255, 255, 0.25);
            color: white;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .notif-dropdown-close {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            transition: background 0.2s;
        }

        .notif-dropdown-close:hover {
            background: rgba(255, 255, 255, 0.35);
        }

        .notif-dropdown-tabs {
            display: flex;
            border-bottom: 2px solid #e5e7eb;
            background: #f8fafc;
        }

        .notif-tab {
            flex: 1;
            padding: 10px;
            text-align: center;
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            border: none;
            background: none;
            transition: all 0.2s;
            position: relative;
        }

        .notif-tab:hover {
            color: #3762c8;
            background: rgba(55, 98, 200, 0.05);
        }

        .notif-tab.active {
            color: #3762c8;
            background: white;
        }

        .notif-tab.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: #3762c8;
        }

        .notif-tab .tab-count {
            background: #dc3545;
            color: white;
            padding: 1px 6px;
            border-radius: 10px;
            font-size: 10px;
            margin-left: 4px;
            font-weight: 700;
        }

        .notif-dropdown-body {
            max-height: calc(100vh - 140px);
            overflow-y: auto;
        }

        .notif-section {
            display: none;
        }

        .notif-section.active {
            display: block;
        }

        .notif-item {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: background 0.2s;
            position: relative;
        }

        .notif-item:hover {
            background: rgba(55, 98, 200, 0.04);
        }

        .notif-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: transparent;
            transition: background 0.2s;
        }

        .notif-item.priority-high::before {
            background: #dc2626;
        }

        .notif-item.priority-medium::before {
            background: #f59e0b;
        }

        .notif-item.priority-low::before {
            background: #10b981;
        }

        .notif-item-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 4px;
        }

        .notif-item-title {
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
            line-height: 1.3;
            flex: 1;
            margin-right: 8px;
        }

        .notif-item-time {
            font-size: 10px;
            color: #94a3b8;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .notif-item-desc {
            font-size: 12px;
            color: #64748b;
            line-height: 1.4;
            margin-bottom: 6px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .notif-item-meta {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .notif-tag {
            font-size: 10px;
            padding: 2px 7px;
            border-radius: 6px;
            font-weight: 500;
        }

        .notif-tag-dept {
            background: #eff6ff;
            color: #2563eb;
        }

        .notif-tag-priority-high {
            background: #fef2f2;
            color: #dc2626;
        }

        .notif-tag-priority-medium {
            background: #fffbeb;
            color: #d97706;
        }

        .notif-tag-priority-low {
            background: #f0fdf4;
            color: #16a34a;
        }

        .notif-tag-location {
            background: #f0fdf4;
            color: #16a34a;
        }

        .notif-tag-reporter {
            background: #faf5ff;
            color: #9333ea;
        }

        .notif-tag-role {
            background: #fff7ed;
            color: #ea580c;
        }

        .notif-empty {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
        }

        .notif-empty i {
            font-size: 36px;
            margin-bottom: 10px;
            display: block;
            color: #cbd5e1;
        }

        .notif-empty p {
            font-size: 13px;
        }

        .notif-dropdown-footer {
            position: sticky;
            bottom: 0;
            background: #f8fafc;
            border-top: 1px solid #e5e7eb;
            padding: 10px 16px;
            text-align: center;
        }

        .notif-view-all-btn {
            display: inline-block;
            padding: 8px 20px;
            background: linear-gradient(135deg, #3762c8 0%, #1e3c72 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }

        .notif-view-all-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(55, 98, 200, 0.3);
        }

        .notif-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.3);
            z-index: 1000;
        }

        .notif-overlay.active {
            display: block;
        }

        .notification-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            min-width: 18px;
            height: 18px;
            font-size: 10px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
            line-height: 1;
        }

        body.dark-mode {
            background: #1a1d23 !important;
        }

        body.dark-mode .sidebar {
            background: linear-gradient(135deg, #1a1d23 0%, #22262e 100%) !important;
        }

        body.dark-mode .sidebar-header {
            border-color: #2d323b !important;
        }

        body.dark-mode .sidebar-header h2 {
            color: #e4e6ea !important;
        }

        body.dark-mode .sidebar-header p {
            color: #9ca3af !important;
        }

        body.dark-mode .user-info {
            background: rgba(255,255,255,0.05) !important;
        }

        body.dark-mode .user-info span {
            color: #e4e6ea !important;
        }

        body.dark-mode .user-info small {
            color: #9ca3af !important;
        }

        body.dark-mode .nav-section {
            color: #6b7280 !important;
        }

        body.dark-mode .nav-link {
            color: #ffffff !important;
        }

        body.dark-mode .nav-link:hover {
            background: rgba(255,255,255,0.08) !important;
            color: #ffffff !important;
            border-left-color: #60a5fa !important;
        }

        body.dark-mode .nav-link.active {
            background: rgba(96,165,250,0.15) !important;
            color: #ffffff !important;
            border-left-color: #60a5fa !important;
        }

        body.dark-mode .nav-link svg,
        body.dark-mode .nav-link i {
            color: #d1d5db !important;
        }

        body.dark-mode .nav-link:hover svg,
        body.dark-mode .nav-link.active svg,
        body.dark-mode .nav-link:hover i,
        body.dark-mode .nav-link.active i {
            color: #ffffff !important;
        }

        body.dark-mode .nav-link .badge {
            background: #2563eb !important;
            color: white !important;
        }

        body.dark-mode .sidebar-footer {
            border-color: #2d323b !important;
        }

        body.dark-mode .sidebar-footer a {
            color: #9ca3af !important;
        }

        body.dark-mode .sidebar-footer a:hover {
            color: #f87171 !important;
        }

        body.dark-mode .sidebar-content::-webkit-scrollbar-track {
            background: #1a1d23 !important;
        }

        body.dark-mode .sidebar-content::-webkit-scrollbar-thumb {
            background: #2d323b !important;
        }

        body.dark-mode .sidebar-content::-webkit-scrollbar-thumb:hover {
            background: #3d4350 !important;
        }
    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>LGU Staff Portal</h2>
            <p>Road and Transportation Department</p>
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($user_info['full_name']); ?></div>
                <div class="user-role"><?php echo htmlspecialchars(ucfirst($user_info['role'])); ?></div>
            </div>
        </div>

        <div class="sidebar-content">
            <?php 
            // Debug output
            error_log("User role: " . $user_role);
            error_log("Nav items: " . json_encode($nav_items));
            
            // Test if nav_items is empty
            $total_items = 0;
            foreach ($nav_items as $section => $items) {
                $total_items += count($items);
            }
            error_log("Total nav items: " . $total_items);
            
            // If empty, show all items regardless of role (temporary debug)
            if ($total_items === 0) {
                error_log("Nav items empty! Showing all items for debugging.");
                ?>
                <div class="nav-section">
                    <div class="nav-section-title">Main</div>
                    <ul style="list-style: none;">
                        <li><a href="../pages/main/lgu_staff_dashboard.php" class="nav-link" target="_parent">📊 Staff Dashboard</a></li>
                        <li><a href="../pages/main/admin_dashboard.php" class="nav-link" target="_parent">🔧 Admin Dashboard</a></li>
                        <li><a href="../pages/main/manage_accounts.php" class="nav-link" target="_parent">👥 Manage Accounts</a></li>
                    </ul>
                </div>
                <div class="nav-section">
                    <div class="nav-section-title">Monitoring</div>
                    <ul style="list-style: none;">
                        <li><a href="../pages/monitoring/road_transportation_monitoring.php" class="nav-link" target="_parent">🗺️ Road and Transportation Reporting</a></li>
                        <li><a href="../pages/monitoring/verification_monitoring.php" class="nav-link" target="_parent">✅ Verification & Monitoring Reports</a></li>
                        <li><a href="../pages/monitoring/report_management.php" class="nav-link" target="_parent">📊 Report Management</a></li>
                    </ul>
                </div>
                <div class="nav-section">
                    <div class="nav-section-title">Transparency</div>
                    <ul style="list-style: none;">
                        <li><a href="../pages/transparency/public_transparency.php" class="nav-link" target="_parent">👁️ Public Transparency</a></li>
                    </ul>
                </div>
                <div class="nav-section">
                    <div class="nav-section-title">Account</div>
                    <ul style="list-style: none;">
                        <li>
                            <a href="../logout.php" class="nav-link nav-link-logout" target="_parent">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-box-arrow-right" viewBox="0 0 16 16">
                                    <path fill-rule="evenodd" d="M10 12.5a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-9a.5.5 0 0 1 .5-.5h8a.5.5 0 0 1 .5.5v2a.5.5 0 0 0 1 0v-2A1.5 1.5 0 0 0 9.5 2h-8A1.5 1.5 0 0 0 0 3.5v9A1.5 1.5 0 0 0 1.5 14h8a1.5 1.5 0 0 0 1.5-1.5v-2a.5.5 0 0 0-1 0v2z"/>
                                    <path fill-rule="evenodd" d="M15.854 8.354a.5.5 0 0 0 0-.708l-3-3a.5.5 0 0 0-.708.708L14.293 7.5H5.5a.5.5 0 0 0 0 1h8.793l-2.147 2.146a.5.5 0 0 0 .708.708l3-3z"/>
                                </svg>
                                Logout
                            </a>
                        </li>
                    </ul>
                </div>
                <?php
                return;
            }
            ?>
            <?php foreach ($nav_items as $section => $items): ?>
                <?php if (!empty($items)): ?>
                    <div class="nav-section">
                        <div class="nav-section-title"><?php echo ucfirst($section); ?></div>
                        <ul style="list-style: none;">
                            <?php foreach ($items as $item): ?>
                                <li<?php echo ($item['icon'] === 'bell') ? ' class="notification-wrapper"' : ''; ?>>
                                    <?php if ($item['icon'] === 'bell'): ?>
                                        <a href="javascript:void(0);" class="nav-link" id="notifBellBtn" onclick="toggleNotificationDropdown(event)">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-bell" viewBox="0 0 16 16">
                                                <path d="M8 16a2 2 0 0 0 1.985-1.75c.017-.137-.097-.25-.235-.25h-3.5c-.138 0-.252.113-.235.25A2 2 0 0 0 8 16zM3 5a5 5 0 0 1 10 0v2.947c0 .05.015.098.042.139l1.703 2.555A1.519 1.519 0 0 1 13.482 13H2.518a1.516 1.516 0 0 1-1.263-2.36l1.703-2.554A.255.255 0 0 0 3 7.947V5zm5-3.5A3.5 3.5 0 0 0 4.5 5v2.947c0 .346-.102.683-.294.97l-1.703 2.556a.017.017 0 0 0-.003.01l.001.006c0 .002.002.004.004.006l.006.004.007.001h10.964l.007-.001.006-.004.004-.006.001-.007a.017.017 0 0 0-.003-.01l-1.703-2.554a1.745 1.745 0 0 1-.294-.97V5A3.5 3.5 0 0 0 8 1.5z"/>
                                            </svg>
                                            Notifications
                                            <?php if ($notification_count > 0): ?>
                                                <span class="notification-badge"><?php echo $notification_count; ?></span>
                                            <?php endif; ?>
                                        </a>
                                    <?php else: ?>
                                        <a href="<?php echo $item['href']; ?>" class="nav-link" target="_parent">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-<?php echo $item['icon']; ?>" viewBox="0 0 16 16">
                                                <?php
                                                $icon_paths = [
                                                    'speedometer2' => '<path d="M8 4a.5.5 0 0 1 .5.5V6a.5.5 0 0 1-1 0V4.5A.5.5 0 0 1 8 4zM3.732 5.732a.5.5 0 0 1 .707 0l.915.914a.5.5 0 1 1-.708.708l-.914-.915a.5.5 0 0 1 0-.707zM2 10a.5.5 0 0 1 .5-.5h1.586a.5.5 0 0 1 0 1H2.5A.5.5 0 0 1 2 10zm9.032-4.268a.5.5 0 0 1 0 .707l-.914.915a.5.5 0 1 1-.708-.708l.915-.914a.5.5 0 0 1 .707 0zm1.732 4.268a.5.5 0 0 1-.5.5h-1.586a.5.5 0 0 1 0-1H12.5a.5.5 0 0 1 .5.5z"/><path d="M7.293 13.293a1 1 0 0 1 1.414 0l.707.707a1 1 0 0 1-1.414 1.414l-.707-.707a1 1 0 0 1 0-1.414z"/><path d="M8 2a6 6 0 1 0 0 12 6 6 0 0 0 0-12zm0 1a5 5 0 1 1 0 10 5 5 0 0 1 0-10z"/>',
                                                    'map' => '<path fill-rule="evenodd" d="M15.817.113A.5.5 0 0 1 16 .5v14a.5.5 0 0 1-.402.49l-5 1A.5.5 0 0 1 10 15v-1a.5.5 0 0 1-.402.49l-5 1A.5.5 0 0 1 4 15V1a.5.5 0 0 1 .402-.49l5-1a.5.5 0 0 1 .196 0l5 1zM10 1.91V14.09l4-.8V1.11l-4 .8zm-5 0V14.09l4-.8V1.11l-4 .8z"/>',
                                                    'gear' => '<path d="M8 4.754a3.246 3.246 0 1 0 0 6.492 3.246 3.246 0 0 0 0-6.492zM5.754 8a2.246 2.246 0 1 1 4.492 0 2.246 2.246 0 0 1-4.492 0z"/><path d="M9.796 1.343c-.527-1.79-3.065-1.79-3.592 0l-.094.319a.873.873 0 0 1-1.255.52l-.292-.16c-1.64-.892-3.433.902-2.54 2.541l.159.292a.873.873 0 0 1-.52 1.255l-.319.094c-1.79.527-1.79 3.065 0 3.592l.319.094a.873.873 0 0 1 .52 1.255l-.16.292c-.892 1.64.901 3.434 2.541 2.54l.292-.159a.873.873 0 0 1 1.255.52l.094.319c.527 1.79 3.065 1.79 3.592 0l.094-.319a.873.873 0 0 1 1.255-.52l.292.16c1.64.893 3.434-.902 2.54-2.541l-.159-.292a.873.873 0 0 1 .52-1.255l.319-.094c1.79-.527 1.79-3.065 0-3.592l-.319-.094a.873.873 0 0 1-.52-1.255l.16-.292c.893-1.64-.902-3.433-2.541-2.54l-.292.159a.873.873 0 0 1-1.255-.52l-.094-.319zm-2.633.283c.246-.835 1.428-.835 1.674 0l.094.319a1.873 1.873 0 0 0 2.693 1.115l.291-.16c.764-.415 1.6.42 1.184 1.185l-.159.292a1.873 1.873 0 0 0 1.116 2.692l.318.094c.835.246.835 1.428 0 1.674l-.319.094a1.873 1.873 0 0 0-1.115 2.693l.16.291c.415.764-.42 1.6-1.185 1.184l-.291-.159a1.873 1.873 0 0 0-2.693 1.116l-.094.318c-.246.835-1.428.835-1.674 0l-.094-.319a1.873 1.873 0 0 0-2.692-1.115l-.292.16c-.764.415-1.6-.42-1.184-1.185l.159-.291A1.873 1.873 0 0 0 1.945 8.93l-.319-.094c-.835-.246-.835-1.428 0-1.674l.319-.094A1.873 1.873 0 0 0 3.06 4.377l-.16-.292c-.415-.764.42-1.6 1.185-1.184l.292.159a1.873 1.873 0 0 0 2.692-1.115l.094-.319z"/>',
                                                    'shield-check' => '<path d="M5.338 1.59a61.44 61.44 0 0 0-2.837.856.481.481 0 0 0-.328.39c-.554 4.157.726 7.19 2.253 9.188a10.725 10.725 0 0 0 2.287 2.233c.346.244.652.42.893.533.12.057.218.095.293.118a.55.55 0 0 0 .101.025.615.615 0 0 0 .1-.025c.076-.023.174-.061.294-.118.24-.113.547-.29.893-.533a10.726 10.726 0 0 0 2.287-2.233c1.527-1.997 2.807-5.031 2.253-9.188a.48.48 0 0 0-.328-.39c-.651-.213-1.75-.56-2.837-.855C9.552 1.29 8.531 1.067 8 1.067c-.53 0-1.552.223-2.662.524zM5.072.56C6.157.265 7.31 0 8 0s1.843.265 2.928.56c1.11.3 2.229.655 2.887.87a1.54 1.54 0 0 1 1.044 1.262c.596 4.477-.787 7.795-2.465 9.99a11.775 11.775 0 0 1-2.517 2.453 7.159 7.159 0 0 1-1.048.625c-.282.132-.581.24-.829.24s-.547-.108-.829-.24a7.158 7.158 0 0 1-1.048-.625 11.777 11.777 0 0 1-2.517-2.453C1.928 10.487.545 7.169 1.141 2.692A1.54 1.54 0 0 1 2.185 1.43 62.456 62.456 0 0 1 5.072.56z"/><path d="M10.854 5.146a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708 0l-1.5-1.5a.5.5 0 1 1 .708-.708L7.5 7.793l2.646-2.647a.5.5 0 0 1 .708 0z"/>',
                                                    'users' => '<path d="M15 14s1 0 1-1-1-4-5-4-5 3-5 4 1 1 1 1h8zm-7.978-1A.261.261 0 0 1 7 12.996c.001-.264.167-1.03.76-1.72C8.312 10.629 9.282 10 11 10c1.717 0 2.687.63 3.24 1.276.593.69.758 1.457.76 1.72l-.008.002a.274.274 0 0 1-.014.002H7.022zM11 7a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm3-2a3 3 0 1 1-6 0 3 3 0 0 1 6 0zM6.936 9.28a5.88 5.88 0 0 0-1.23-.247A7.35 7.35 0 0 0 5 9c-4 0-5 3-5 4 0 .667.333 1 1 1h4.216A2.238 2.238 0 0 1 5 13c0-1.01.377-2.042 1.09-2.904.243-.294.526-.569.846-.816zM4.92 10A5.493 5.493 0 0 0 4 13H1c0-.26.164-1.03.76-1.724.545-.636 1.492-1.256 3.16-1.275zM1.5 5.5a3 3 0 1 1 6 0 3 3 0 0 1-6 0zm3-2a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/>',
                                                    'person-plus' => '<path d="M6 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6m2-3a2 2 0 1 1-4 0 2 2 0 0 1 4 0m4 8c0 1-1 1-1 1H4s-1 0-1-1 1-4 6-4 6 3 6 4m-1-.004c-.001-.246-.154-.986-.832-1.664C9.516 10.68 8.289 10 6 10s-3.516.68-4.168 1.332c-.678.678-.83 1.418-.832 1.664z"/><path fill-rule="evenodd" d="M13.5 5a.5.5 0 0 1 .5.5V7h1.5a.5.5 0 0 1 0 1H14v1.5a.5.5 0 0 1-1 0V8h-1.5a.5.5 0 0 1 0-1H13V5.5a.5.5 0 0 1 .5-.5"/>'
                                                ];
                                                echo $icon_paths[$item['icon']] ?? '';
                                                ?>
                                            </svg>
                                            <?php echo htmlspecialchars($item['title']); ?>
                                        </a>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <div class="nav-section">
                <div class="nav-section-title">Account</div>
                <ul style="list-style: none;">
                    <li>
                        <a href="../logout.php" class="nav-link nav-link-logout" target="_parent">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-box-arrow-right" viewBox="0 0 16 16">
                                <path fill-rule="evenodd" d="M10 12.5a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-9a.5.5 0 0 1 .5-.5h8a.5.5 0 0 1 .5.5v2a.5.5 0 0 0 1 0v-2A1.5 1.5 0 0 0 9.5 2h-8A1.5 1.5 0 0 0 0 3.5v9A1.5 1.5 0 0 0 1.5 14h8a1.5 1.5 0 0 0 1.5-1.5v-2a.5.5 0 0 0-1 0v2z"/>
                                <path fill-rule="evenodd" d="M15.854 8.354a.5.5 0 0 0 0-.708l-3-3a.5.5 0 0 0-.708.708L14.293 7.5H5.5a.5.5 0 0 0 0 1h8.793l-2.147 2.146a.5.5 0 0 0 .708.708l3-3z"/>
                            </svg>
                            Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Notification Overlay -->
    <div class="notif-overlay" id="notifOverlay" onclick="closeNotificationDropdown()"></div>

    <!-- Notification Dropdown Panel -->
    <div class="notification-dropdown" id="notifDropdown">
        <div class="notif-dropdown-header">
            <h3>
                <i class="fas fa-bell"></i> Notifications
                <?php if ($notification_count > 0): ?>
                    <span class="notif-count-badge"><?php echo $notification_count; ?> new</span>
                <?php endif; ?>
            </h3>
            <button class="notif-dropdown-close" onclick="closeNotificationDropdown()">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="notif-dropdown-tabs">
            <button class="notif-tab active" onclick="switchNotifTab('reports')">
                <i class="fas fa-file-alt"></i> Reports
                <?php if (!empty($notifications['reports'])): ?>
                    <span class="tab-count"><?php echo count($notifications['reports']); ?></span>
                <?php endif; ?>
            </button>
            <button class="notif-tab" onclick="switchNotifTab('users')">
                <i class="fas fa-users"></i> Users
                <?php if (!empty($notifications['users'])): ?>
                    <span class="tab-count"><?php echo count($notifications['users']); ?></span>
                <?php endif; ?>
            </button>
        </div>

        <div class="notif-dropdown-body">
            <!-- Reports Section -->
            <div class="notif-section active" id="notifSectionReports">
                <?php if (empty($notifications['reports'])): ?>
                    <div class="notif-empty">
                        <i class="fas fa-check-circle"></i>
                        <p>No pending reports</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($notifications['reports'] as $report): ?>
                        <div class="notif-item priority-<?php echo $report['priority']; ?>" onclick="window.parent.location.href='../pages/monitoring/report_management.php'">
                            <div class="notif-item-top">
                                <div class="notif-item-title"><?php echo htmlspecialchars($report['title']); ?></div>
                                <div class="notif-item-time"><?php echo date('M d, H:i', strtotime($report['created_at'])); ?></div>
                            </div>
                            <div class="notif-item-desc"><?php echo htmlspecialchars(substr($report['description'], 0, 100)); ?><?php echo strlen($report['description']) > 100 ? '...' : ''; ?></div>
                            <div class="notif-item-meta">
                                <span class="notif-tag notif-tag-dept"><?php echo ucfirst(htmlspecialchars($report['department'])); ?></span>
                                <span class="notif-tag notif-tag-priority-<?php echo $report['priority']; ?>"><?php echo ucfirst($report['priority']); ?></span>
                                <?php if ($report['location']): ?>
                                    <span class="notif-tag notif-tag-location"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars(substr($report['location'], 0, 20)); ?></span>
                                <?php endif; ?>
                                <?php if ($report['reporter_name']): ?>
                                    <span class="notif-tag notif-tag-reporter"><i class="fas fa-user"></i> <?php echo htmlspecialchars($report['reporter_name']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Users Section -->
            <div class="notif-section" id="notifSectionUsers">
                <?php if (empty($notifications['users'])): ?>
                    <div class="notif-empty">
                        <i class="fas fa-check-circle"></i>
                        <p>No pending user requests</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($notifications['users'] as $user): ?>
                        <div class="notif-item" onclick="window.parent.location.href='../pages/main/admin_dashboard.php'">
                            <div class="notif-item-top">
                                <div class="notif-item-title"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                <div class="notif-item-time"><?php echo date('M d, H:i', strtotime($user['created_at'])); ?></div>
                            </div>
                            <div class="notif-item-desc">New <?php echo ucfirst(htmlspecialchars($user['role'])); ?> account registration request</div>
                            <div class="notif-item-meta">
                                <span class="notif-tag notif-tag-dept"><?php echo ucfirst(htmlspecialchars($user['department'] ?? 'N/A')); ?></span>
                                <span class="notif-tag notif-tag-role"><?php echo ucfirst(htmlspecialchars($user['role'])); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="notif-dropdown-footer">
            <a href="../pages/main/notifications.php" class="notif-view-all-btn" target="_parent">
                <i class="fas fa-eye"></i> View All Notifications
            </a>
        </div>
    </div>

    <script>
        // Page transition for logout link with confirmation
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('a[href*="logout.php"]').forEach(link => {
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    if (!confirm('Are you sure you want to log out?')) return;
                    const overlay = document.getElementById('pageTransitionOverlay');
                    overlay.classList.add('active');
                    
                    setTimeout(() => {
                        window.location.href = this.href;
                    }, 800);
                });
            });
        });
    </script>
    <script>
        // Set active navigation based on current page
        document.addEventListener('DOMContentLoaded', function() {
            const currentPath = window.parent.location.pathname;
            const currentFile = currentPath.split('/').pop();
            
            // Remove active class from all links
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
            });
            
            // Add active class to current page link
            let foundActive = false;
            document.querySelectorAll('.nav-link').forEach(link => {
                const href = link.getAttribute('href');
                const hrefFile = href.split('/').pop();
                
                // Check if current file matches the href file
                if (currentFile === hrefFile) {
                    link.classList.add('active');
                    foundActive = true;
                }
            });
            
            // If no exact match found, check for dashboard fallback
            if (!foundActive && (currentFile === '' || currentFile === 'lgu_staff' || currentFile === 'lgu_staff/')) {
                const dashboardLink = document.querySelector('a[href*="lgu_staff_dashboard.php"]');
                if (dashboardLink) {
                    dashboardLink.classList.add('active');
                }
            }
            
            // Navigation with transition effect
            document.querySelectorAll('.nav-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    if (this.id === 'notifBellBtn') return;
                    e.preventDefault();
                    const href = this.getAttribute('href');
                    
                    // Show transition overlay
                    const overlay = document.getElementById('pageTransitionOverlay');
                    if (overlay) {
                        overlay.classList.add('active');
                        
                        // Navigate after transition delay
                        setTimeout(() => {
                            window.parent.location.href = href;
                        }, 800);
                    } else {
                        // Fallback: direct navigation if overlay not found
                        window.parent.location.href = href;
                    }
                });
            });
        });
    </script>
</div>
    
<!-- Page Transition Overlay -->
<div class="page-transition-overlay" id="pageTransitionOverlay">
    <div class="transition-content">
        <div class="transition-spinner">
            <i class="fas fa-spinner"></i>
        </div>
        <div class="transition-text">Loading...</div>
    </div>
</div>

<script>
    function toggleNotificationDropdown(e) {
        if (e) e.preventDefault();
        var dropdown = document.getElementById('notifDropdown');
        var overlay = document.getElementById('notifOverlay');
        var isActive = dropdown.classList.contains('active');
        
        if (isActive) {
            closeNotificationDropdown();
        } else {
            dropdown.classList.add('active');
            overlay.classList.add('active');
            loadNotifications();
        }
    }

    function closeNotificationDropdown() {
        document.getElementById('notifDropdown').classList.remove('active');
        document.getElementById('notifOverlay').classList.remove('active');
    }

    function switchNotifTab(tab) {
        document.querySelectorAll('.notif-tab').forEach(function(t) { t.classList.remove('active'); });
        document.querySelectorAll('.notif-section').forEach(function(s) { s.classList.remove('active'); });
        
        if (tab === 'reports') {
            document.querySelector('.notif-tab:first-child').classList.add('active');
            document.getElementById('notifSectionReports').classList.add('active');
        } else {
            document.querySelector('.notif-tab:last-child').classList.add('active');
            document.getElementById('notifSectionUsers').classList.add('active');
        }
    }

    function loadNotifications() {
        var baseUrl = '/lg-road-monitoring/lgu_staff/pages/api/get_notifications.php';
        
        fetch(baseUrl)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                updateDropdownContent(data.data);
            }
        })
        .catch(function(err) {
            console.error('Error loading notifications:', err);
        });
    }

    function updateDropdownContent(data) {
        var reportsSection = document.getElementById('notifSectionReports');
        var usersSection = document.getElementById('notifSectionUsers');
        
        if (data.reports.length === 0) {
            reportsSection.innerHTML = '<div class="notif-empty"><i class="fas fa-check-circle"></i><p>No pending reports</p></div>';
        } else {
            var html = '';
            data.reports.forEach(function(r) {
                var desc = r.description ? r.description.substring(0, 100) : '';
                if (r.description && r.description.length > 100) desc += '...';
                var time = new Date(r.created_at).toLocaleDateString('en-US', {month:'short', day:'numeric'}) + ' ' + 
                           new Date(r.created_at).toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit', hour12:false});
                
                html += '<div class="notif-item priority-' + r.priority + '" onclick="window.parent.location.href=\'../pages/monitoring/report_management.php\'">';
                html += '<div class="notif-item-top"><div class="notif-item-title">' + escapeHtml(r.title) + '</div>';
                html += '<div class="notif-item-time">' + time + '</div></div>';
                html += '<div class="notif-item-desc">' + escapeHtml(desc) + '</div>';
                html += '<div class="notif-item-meta">';
                html += '<span class="notif-tag notif-tag-dept">' + capitalize(r.department) + '</span>';
                html += '<span class="notif-tag notif-tag-priority-' + r.priority + '">' + capitalize(r.priority) + '</span>';
                if (r.location) html += '<span class="notif-tag notif-tag-location"><i class="fas fa-map-marker-alt"></i> ' + escapeHtml(r.location.substring(0, 20)) + '</span>';
                if (r.reporter_name) html += '<span class="notif-tag notif-tag-reporter"><i class="fas fa-user"></i> ' + escapeHtml(r.reporter_name) + '</span>';
                html += '</div></div>';
            });
            reportsSection.innerHTML = html;
        }
        
        if (data.users.length === 0) {
            usersSection.innerHTML = '<div class="notif-empty"><i class="fas fa-check-circle"></i><p>No pending user requests</p></div>';
        } else {
            var html = '';
            data.users.forEach(function(u) {
                var time = new Date(u.created_at).toLocaleDateString('en-US', {month:'short', day:'numeric'}) + ' ' + 
                           new Date(u.created_at).toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit', hour12:false});
                
                html += '<div class="notif-item" onclick="window.parent.location.href=\'../pages/main/admin_dashboard.php\'">';
                html += '<div class="notif-item-top"><div class="notif-item-title">' + escapeHtml(u.full_name) + '</div>';
                html += '<div class="notif-item-time">' + time + '</div></div>';
                html += '<div class="notif-item-desc">New ' + capitalize(u.role) + ' account registration request</div>';
                html += '<div class="notif-item-meta">';
                html += '<span class="notif-tag notif-tag-dept">' + capitalize(u.department || 'N/A') + '</span>';
                html += '<span class="notif-tag notif-tag-role">' + capitalize(u.role) + '</span>';
                html += '</div></div>';
            });
            usersSection.innerHTML = html;
        }

        var tabs = document.querySelectorAll('.notif-tab .tab-count');
        if (tabs[0]) tabs[0].textContent = data.counts.reports;
        if (tabs[1]) tabs[1].textContent = data.counts.users;
    }

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function capitalize(str) {
        if (!str) return '';
        return str.charAt(0).toUpperCase() + str.slice(1);
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeNotificationDropdown();
        });
    });
</script>
</body>
</html>
