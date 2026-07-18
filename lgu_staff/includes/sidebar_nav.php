<?php
/**
 * Sidebar Navigation Include
 * 
 * This file is included directly in each page (replaces the iframe approach).
 * 
 * Required before include:
 *   - Session must be started
 *   - $conn must be available (DB connection)
 *   - $_SESSION['user_id'] must be set
 * 
 * Optional before include:
 *   - $current_page: filename of the current page (for active state detection)
 */

// Ensure session is available
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include config and functions if not already loaded
if (!isset($conn)) {
    require_once __DIR__ . '/config.php';
}
if (!function_exists('is_logged_in')) {
    require_once __DIR__ . '/functions.php';
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: /lg-road-monitoring/lgu_staff/login.php');
    exit();
}

// Get user info
function getSidebarUserInfo() {
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

// Get notification count
function getSidebarNotificationCount($user_role = '', $user_id = 0) {
    global $conn;
    $count = 0;
    if ($conn) {
        if ($user_role === 'system_admin') {
            try {
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status = 'pending'");
                $stmt->execute();
                $count += $stmt->get_result()->fetch_assoc()['count'];
                $stmt->close();
            } catch (Exception $e) {}
            try {
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE account_status = 'pending'");
                $stmt->execute();
                $count += $stmt->get_result()->fetch_assoc()['count'];
                $stmt->close();
            } catch (Exception $e) {}
            try {
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM change_requests WHERE status = 'pending'");
                $stmt->execute();
                $count += $stmt->get_result()->fetch_assoc()['count'];
                $stmt->close();
            } catch (Exception $e) {}
            try {
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM report_notifications WHERE is_read = 0");
                $stmt->execute();
                $count += $stmt->get_result()->fetch_assoc()['count'];
                $stmt->close();
            } catch (Exception $e) {}
        } elseif ($user_role === 'lgu_staff' && $user_id > 0) {
            try {
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM change_requests WHERE user_id = ? AND status != 'pending'");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $count += $stmt->get_result()->fetch_assoc()['count'];
                $stmt->close();
            } catch (Exception $e) {}
            try {
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM road_transportation_reports WHERE created_by = ? AND status IN ('completed', 'cancelled')");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $count += $stmt->get_result()->fetch_assoc()['count'];
                $stmt->close();
            } catch (Exception $e) {}
        }
    }
    return $count;
}

// Determine base path: all pages are in lgu_staff/pages/{admin,lgu,shared}/
// So base path to lgu_staff/ is always ../../
$nav_base = isset($nav_base) ? $nav_base : '../../';

$user_info = getSidebarUserInfo();
$user_role = $_SESSION['role'] ?? $user_info['role'] ?? 'citizen';
$notification_count = getSidebarNotificationCount($user_role, $_SESSION['user_id'] ?? 0);

// Detect current page for active state
$current_page = basename($_SERVER['SCRIPT_NAME']);

// Navigation items
$nav_items = [
    'main' => [
        ['href' => $nav_base . 'pages/lgu/lgu_staff_dashboard.php', 'icon' => 'tachometer-alt', 'title' => 'Staff Dashboard', 'roles' => ['lgu_staff']],
        ['href' => $nav_base . 'pages/lgu/change_info.php', 'icon' => 'user-edit', 'title' => 'Change Information', 'roles' => ['lgu_staff']],
        ['href' => $nav_base . 'pages/admin/admin_dashboard.php', 'icon' => 'tachometer-alt', 'title' => 'Admin Dashboard', 'roles' => ['system_admin']],
        ['href' => $nav_base . 'pages/admin/manage_accounts.php', 'icon' => 'users', 'title' => 'Manage Accounts', 'roles' => ['system_admin']],
        ['href' => $nav_base . 'pages/admin/account_approvals.php', 'icon' => 'clipboard-check', 'title' => 'Account Approvals', 'roles' => ['system_admin']],
        ['href' => $nav_base . 'pages/admin/create_staff_account.php', 'icon' => 'user-plus', 'title' => 'Create Staff Account', 'roles' => ['system_admin']],
    ],
    'monitoring' => [
        ['href' => $nav_base . 'pages/shared/road_transportation_monitoring.php', 'icon' => 'map-marked-alt', 'title' => 'Road Monitoring', 'roles' => ['lgu_staff', 'system_admin']],
        ['href' => $nav_base . 'pages/admin/verification_monitoring.php', 'icon' => 'shield-alt', 'title' => 'Verification Reports', 'roles' => ['system_admin']],
        ['href' => $nav_base . 'pages/admin/report_management.php', 'icon' => 'clipboard-list', 'title' => 'Report Management', 'roles' => ['system_admin']],
    ],
    'transparency' => [
        ['href' => $nav_base . 'pages/shared/public_transparency.php', 'icon' => 'eye', 'title' => 'Public Transparency', 'roles' => ['system_admin', 'lgu_staff']],
    ],
    'reports' => [
        ['href' => $nav_base . 'pages/shared/analytics.php', 'icon' => 'chart-line', 'title' => 'Analytics', 'roles' => ['system_admin', 'lgu_staff']],
        ['href' => $nav_base . 'pages/shared/sla_dashboard.php', 'icon' => 'gavel', 'title' => 'SLA Compliance', 'roles' => ['system_admin', 'lgu_staff']],
        ['href' => $nav_base . 'pages/admin/audit_trail.php', 'icon' => 'history', 'title' => 'Audit Trail', 'roles' => ['system_admin']],
    ],
    'system' => [
        ['href' => $nav_base . 'pages/shared/notifications.php', 'icon' => 'bell', 'title' => 'Notifications', 'roles' => ['system_admin', 'lgu_staff']],
        ['href' => $nav_base . 'pages/admin/archive.php', 'icon' => 'archive', 'title' => 'Archive', 'roles' => ['system_admin']],
        ['href' => $nav_base . 'pages/shared/settings.php', 'icon' => 'cog', 'title' => 'Settings', 'roles' => ['system_admin', 'lgu_staff']],
    ]
];

// Filter by role
$filtered_items = [];
foreach ($nav_items as $section => $items) {
    $filtered_items[$section] = array_filter($items, function($item) use ($user_role) {
        return in_array($user_role, $item['roles']);
    });
}
?>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h2><i class="fas fa-road"></i> <?php echo defined('SITE_NAME') ? SITE_NAME : 'LGU Portal'; ?></h2>
        <p>Admin Portal</p>
        <div class="user-info">
            <div class="user-name"><?php echo htmlspecialchars($user_info['full_name']); ?></div>
            <div class="user-role"><?php echo htmlspecialchars(ucfirst($user_info['role'])); ?></div>
        </div>
    </div>

    <nav class="sidebar-menu">
        <?php foreach ($filtered_items as $section => $items): ?>
            <?php if (!empty($items)): ?>
                <div class="menu-label"><?php echo ucfirst($section); ?></div>
                <ul>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $href_file = basename($item['href']);
                        $is_active = ($current_page === $href_file) ? ' active' : '';
                        ?>
                        <li>
                            <a href="<?php echo $item['href']; ?>" class="nav-link<?php echo $is_active; ?>">
                                <i class="fas fa-<?php echo $item['icon']; ?>"></i>
                                <?php echo htmlspecialchars($item['title']); ?>
                                <?php if ($notification_count > 0 && $item['icon'] === 'bell'): ?>
                                    <span class="notification-badge"><?php echo $notification_count; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endforeach; ?>

        <div class="menu-label">Account</div>
        <ul>
            <li>
                <a href="<?php echo $nav_base; ?>logout.php" class="nav-link nav-link-logout" id="logoutBtn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </li>
        </ul>
    </nav>
</aside>

<script src="../../js/page-transition.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to log out?')) return;
            window.location.href = logoutBtn.href;
        });
    }

    // Animate the active sidebar link on page load
    var activeLink = document.querySelector('.sidebar-menu .nav-link.active');
    if (activeLink) {
        activeLink.classList.add('active-animate');
        activeLink.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
});
</script>
