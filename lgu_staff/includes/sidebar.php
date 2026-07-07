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
        'reports' => [
            [
                'href' => '../pages/reports/analytics.php',
                'icon' => 'graph-up-arrow',
                'title' => 'Analytics',
                'roles' => ['system_admin', 'lgu_staff']
            ],
            [
                'href' => '../pages/reports/sla_dashboard.php',
                'icon' => 'gavel',
                'title' => 'SLA Compliance',
                'roles' => ['system_admin', 'lgu_staff']
            ],
            [
                'href' => '../pages/reports/audit_trail.php',
                'icon' => 'clock-history',
                'title' => 'Audit Trail',
                'roles' => ['system_admin']
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
                'href' => '../pages/monitoring/archive.php',
                'icon' => 'archive',
                'title' => 'Archive',
                'roles' => ['system_admin']
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
            // Count pending reports (from other departments)
            try {
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status = 'pending'");
                $stmt->execute();
                $result = $stmt->get_result();
                $count += $result->fetch_assoc()['count'];
                $stmt->close();
            } catch (Exception $e) {
                // Ignore errors
            }
            
            // Count pending account requests from users
            try {
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE account_status = 'pending'");
                $stmt->execute();
                $result = $stmt->get_result();
                $count += $result->fetch_assoc()['count'];
                $stmt->close();
            } catch (Exception $e) {
                // Ignore errors
            }
            
            // Count pending change requests
            try {
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM change_requests WHERE status = 'pending'");
                $stmt->execute();
                $result = $stmt->get_result();
                $count += $result->fetch_assoc()['count'];
                $stmt->close();
            } catch (Exception $e) {
                // Ignore errors
            }
            
            // Count unread progress update notifications
            try {
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM report_notifications WHERE is_read = 0");
                $stmt->execute();
                $result = $stmt->get_result();
                $count += $result->fetch_assoc()['count'];
                $stmt->close();
            } catch (Exception $e) {
                // Ignore errors
            }
        } elseif ($user_role === 'lgu_staff' && $user_id > 0) {
            // Count staff's own reviewed change requests
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

            // Count staff's own report status updates
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

// Sidebar data is handled by sidebar_content.php
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
</head>
<body style="font-family: 'Poppins', sans-serif; background: linear-gradient(135deg, #3762c8 0%, #1e3c72 100%); height: 100vh; overflow: hidden; margin: 0;" class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
<?php include 'sidebar_content.php'; ?>
</body>
</html>
