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
    header('Location: ../login.php');
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
        'role' => 'staff'
    ];
}

// Function to get navigation items with role-based access
function getNavigationItems($user_role) {
    $base_items = [
        'main' => [
            [
                'href' => '../pages/lgu_staff_dashboard.php',
                'icon' => 'speedometer2',
                'title' => 'Staff Dashboard',
                'roles' => ['admin', 'manager', 'supervisor', 'staff']
            ]
        ],
        'monitoring' => [
            [
                'href' => '../pages/road_transportation_monitoring.php',
                'icon' => 'map',
                'title' => 'Road Transportation Monitoring',
                'roles' => ['admin', 'manager', 'supervisor', 'staff']
            ],
            [
                'href' => '../pages/verification_monitoring.php',
                'icon' => 'shield-check',
                'title' => 'Verification & Monitoring',
                'roles' => ['admin', 'manager', 'supervisor', 'staff']
            ]
        ],
        'reports' => [
            [
                'href' => '../pages/road_transportation_reporting.php',
                'icon' => 'file-earmark-text',
                'title' => 'Road Transportation Reporting',
                'roles' => ['admin', 'manager', 'supervisor', 'staff']
            ],
            [
                'href' => '../pages/verification_reporting.php',
                'icon' => 'clipboard-check',
                'title' => 'Verification Reporting',
                'roles' => ['admin', 'manager', 'supervisor', 'staff']
            ]
        ],
        'transparency' => [
            [
                'href' => '../pages/public_transparency.php',
                'icon' => 'eye',
                'title' => 'Public Transparency',
                'roles' => ['admin', 'manager', 'staff']
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
function getNotificationCount() {
    global $conn;
    
    if ($conn) {
        // Count pending reports for the user's role
        $user_role = $_SESSION['user_role'] ?? 'staff';
        
        if ($user_role === 'staff') {
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status = 'pending'");
        } else {
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status = 'pending' AND priority = 'high'");
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc()['count'];
    }
    
    return 0;
}

// Get user data
$user_info = getUserInfo();
$nav_items = getNavigationItems($user_info['role']);
$notification_count = getNotificationCount();
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
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>LGU Staff Portal</h2>
            <p>Road & Infrastructure Dept</p>
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($user_info['full_name']); ?></div>
                <div class="user-role"><?php echo htmlspecialchars(ucfirst($user_info['role'])); ?></div>
            </div>
        </div>

        <div class="sidebar-content">
            <?php foreach ($nav_items as $section => $items): ?>
                <?php if (!empty($items)): ?>
                    <!-- <?php echo ucfirst($section); ?> Section -->
                    <div class="nav-section">
                        <div class="nav-section-title"><?php echo ucfirst($section); ?></div>
                        <ul style="list-style: none;">
                            <?php foreach ($items as $item): ?>
                                <li>
                                    <a href="<?php echo $item['href']; ?>" class="nav-link" target="_parent">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-<?php echo $item['icon']; ?>" viewBox="0 0 16 16">
                                            <?php
                                            // Icon paths based on icon name
                                            $icon_paths = [
                                                'speedometer2' => '<path d="M8 4a.5.5 0 0 1 .5.5V6a.5.5 0 0 1-1 0V4.5A.5.5 0 0 1 8 4zM3.732 5.732a.5.5 0 0 1 .707 0l.915.914a.5.5 0 1 1-.708.708l-.914-.915a.5.5 0 0 1 0-.707zM2 10a.5.5 0 0 1 .5-.5h1.586a.5.5 0 0 1 0 1H2.5A.5.5 0 0 1 2 10zm9.032-4.268a.5.5 0 0 1 0 .707l-.914.915a.5.5 0 1 1-.708-.708l.915-.914a.5.5 0 0 1 .707 0zm1.732 4.268a.5.5 0 0 1-.5.5h-1.586a.5.5 0 0 1 0-1H12.5a.5.5 0 0 1 .5.5z"/><path d="M7.293 13.293a1 1 0 0 1 1.414 0l.707.707a1 1 0 0 1-1.414 1.414l-.707-.707a1 1 0 0 1 0-1.414z"/><path d="M8 2a6 6 0 1 0 0 12 6 6 0 0 0 0-12zm0 1a5 5 0 1 1 0 10 5 5 0 0 1 0-10z"/>',
                                                'map' => '<path fill-rule="evenodd" d="M15.817.113A.5.5 0 0 1 16 .5v14a.5.5 0 0 1-.402.49l-5 1A.5.5 0 0 1 10 15v-1a.5.5 0 0 1-.402.49l-5 1A.5.5 0 0 1 4 15V1a.5.5 0 0 1 .402-.49l5-1a.5.5 0 0 1 .196 0l5 1zM10 1.91V14.09l4-.8V1.11l-4 .8zm-5 0V14.09l4-.8V1.11l-4 .8z"/>',
                                                'shield-check' => '<path d="M5.338 1.59a61.44 61.44 0 0 0-2.837.856.481.481 0 0 0-.328.39c-.554 4.157.726 7.19 2.253 9.188a10.725 10.725 0 0 0 2.287 2.233c.346.244.652.42.893.533.12.057.218.095.293.118a.55.55 0 0 0 .101.025.615.615 0 0 0 .1-.025c.076-.023.174-.061.294-.118.24-.113.547-.29.893-.533a10.726 10.726 0 0 0 2.287-2.233c1.527-1.997 2.807-5.031 2.253-9.188a.48.48 0 0 0-.328-.39c-.651-.213-1.75-.56-2.837-.855C9.552 1.29 8.531 1.067 8 1.067c-.53 0-1.552.223-2.662.524zM5.072.56C6.157.265 7.31 0 8 0s1.843.265 2.928.56c1.11.3 2.229.655 2.887.87a1.54 1.54 0 0 1 1.044 1.262c.596 4.477-.787 7.795-2.465 9.99a11.775 11.775 0 0 1-2.517 2.453 7.159 7.159 0 0 1-1.048.625c-.282.132-.581.24-.829.24s-.547-.108-.829-.24a7.158 7.158 0 0 1-1.048-.625 11.777 11.777 0 0 1-2.517-2.453C1.928 10.487.545 7.169 1.141 2.692A1.54 1.54 0 0 1 2.185 1.43 62.456 62.456 0 0 1 5.072.56z"/><path d="M10.854 5.146a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708 0l-1.5-1.5a.5.5 0 1 1 .708-.708L7.5 7.793l2.646-2.647a.5.5 0 0 1 .708 0z"/>',
                                                'file-earmark-text' => '<path d="M5.5 7a.5.5 0 0 0 0 1h5a.5.5 0 0 0 0-1h-5zM5 9.5a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5zm0 2a.5.5 0 0 1 .5-.5h2a.5.5 0 0 1 0 1h-2a.5.5 0 0 1-.5-.5z"/><path d="M9.5 0H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V4.5L9.5 0zm0 1v2A1.5 1.5 0 0 0 11 4.5h2V14a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h5.5z"/>',
                                                'clipboard-check' => '<path fill-rule="evenodd" d="M10.854 7.146a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708 0l-1.5-1.5a.5.5 0 1 1 .708-.708L7.5 9.793l2.646-2.647a.5.5 0 0 1 .708 0z"/><path d="M4 1.5H3a2 2 0 0 0-2 2V14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V3.5a2 2 0 0 0-2-2h-1v1h1a1 1 0 0 1 1 1V14a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1h1v-1z"/><path d="M9.5 1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-1a.5.5 0 0 1 .5-.5h3zm-3-1A1.5 1.5 0 0 0 5 1.5v1A1.5 1.5 0 0 0 6.5 4h3A1.5 1.5 0 0 0 11 2.5v-1A1.5 1.5 0 0 0 9.5 0h-3z"/>',
                                                'eye' => '<path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/><path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>'
                                            ];
                                            echo $icon_paths[$item['icon']] ?? '';
                                            ?>
                                        </svg>
                                        <?php echo htmlspecialchars($item['title']); ?>
                                        <?php if ($notification_count > 0 && $item['href'] === '../pages/verification_monitoring.php'): ?>
                                            <span class="notification-badge"><?php echo $notification_count; ?></span>
                                        <?php endif; ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <form method="POST" action="../logout.php" target="_parent" style="margin: 20px auto; display: block; width: fit-content;">
            <button type="submit" class="logout-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-box-arrow-right" viewBox="0 0 16 16" style="margin-right: 8px;">
                    <path fill-rule="evenodd" d="M10 12.5a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-9a.5.5 0 0 1 .5-.5h8a.5.5 0 0 1 .5.5v2a.5.5 0 0 0 1 0v-2A1.5 1.5 0 0 0 9.5 2h-8A1.5 1.5 0 0 0 0 3.5v9A1.5 1.5 0 0 0 1.5 14h8a1.5 1.5 0 0 0 1.5-1.5v-2a.5.5 0 0 0-1 0v2z"/>
                    <path fill-rule="evenodd" d="M15.854 8.354a.5.5 0 0 0 0-.708l-3-3a.5.5 0 0 0-.708.708L14.293 7.5H5.5a.5.5 0 0 0 0 1h8.793l-2.147 2.146a.5.5 0 0 0 .708.708l3-3z"/>
                </svg>
                Logout
            </button>
        </form>
    </div>

    <script>
        // Set active navigation based on current page
        document.addEventListener('DOMContentLoaded', function() {
            const currentPath = window.parent.location.pathname;
            const currentFile = currentPath.split('/').pop();
            
            // Debug logging
            console.log('Current path:', currentPath);
            console.log('Current file:', currentFile);
            
            // Remove active class from all links
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
            });
            
            // Add active class to current page link
            let foundActive = false;
            document.querySelectorAll('.nav-link').forEach(link => {
                const href = link.getAttribute('href');
                const hrefFile = href.split('/').pop();
                
                console.log('Checking link:', hrefFile, 'against current:', currentFile);
                
                // Check if current file matches the href file
                if (currentFile === hrefFile) {
                    link.classList.add('active');
                    foundActive = true;
                    console.log('Found active link:', hrefFile);
                }
            });
            
            // If no exact match found, check for dashboard fallback
            if (!foundActive && (currentFile === '' || currentFile === 'lgu_staff' || currentFile === 'lgu_staff/')) {
                const dashboardLink = document.querySelector('a[href*="lgu_staff_dashboard.php"]');
                if (dashboardLink) {
                    dashboardLink.classList.add('active');
                    console.log('Set dashboard as active (fallback)');
                }
            }
            
            // Navigation without transition effect
            document.querySelectorAll('.nav-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const href = this.getAttribute('href');
                    
                    // Direct navigation without transition effect
                    window.parent.location.href = href;
                });
            });
        });
    </script>
</body>
</html>
