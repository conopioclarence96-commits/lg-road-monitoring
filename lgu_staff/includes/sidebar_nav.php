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

// Force password change before accessing any protected page. Users with
// must_change_password = 1 may only access change_password.php until they
// replace their temporary password.
if ($conn && isset($_SESSION['user_id'])) {
    $mcp = $conn->prepare("SELECT must_change_password FROM users WHERE id = ?");
    $mcp->bind_param("i", $_SESSION['user_id']);
    $mcp->execute();
    $mcp_row = $mcp->get_result()->fetch_assoc();
    $mcp->close();
    if ($mcp_row && !empty($mcp_row['must_change_password'])) {
        header('Location: /lg-road-monitoring/lgu_staff/change_password.php');
        exit();
    }
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

// Get portal title based on role
function getPortalTitle($role) {
    $portal_titles = [
        'system_admin' => 'Admin Portal',
        'road_ops_supervisor' => 'Road Supervisor Portal',
        'trans_ops_supervisor' => 'Transportation Supervisor Portal',
        'road_monitoring_officer' => 'Road Monitoring Portal',
        'trans_monitoring_officer' => 'Transportation Monitoring Portal'
    ];
    return $portal_titles[$role] ?? 'Portal';
}

// Get notification count
function getSidebarNotificationCount($user_role = '', $user_id = 0) {
    global $conn;
    $count = 0;
    if ($conn) {
        // Only count unread report_notifications that reference a report
        // that still exists in one of the live tables.
        try {
            if ($user_role === 'road_ops_supervisor') {
                // Road supervisors: count only the unread notifications that
                // actually appear in their notifications feed — review requests
                // routed to their role plus results targeted to their email.
                // (The generic count below would include notifications meant
                // for other roles, inflating the badge.)
                $email = '';
                if ($user_id > 0) {
                    $estmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
                    $estmt->bind_param("i", $user_id);
                    $estmt->execute();
                    $erow = $estmt->get_result()->fetch_assoc();
                    $estmt->close();
                    $email = $erow['email'] ?? '';
                }
                $stmt = $conn->prepare("
                    SELECT COUNT(*) as count FROM report_notifications rn
                    WHERE rn.is_read = 0
                      AND (
                          (rn.recipient_role = 'road_ops_supervisor' AND rn.type IN ('completion', 'cancellation'))
                          OR (rn.recipient_email = ? AND rn.type IN ('approve_request', 'reject_request', 'complete_report', 'cancel_report'))
                      )
                      AND EXISTS (
                          SELECT 1 FROM road_transportation_reports WHERE id = rn.report_id
                          UNION ALL
                          SELECT 1 FROM road_maintenance_reports WHERE id = rn.report_id
                          UNION ALL
                          SELECT 1 FROM cimm_verification_reports WHERE id = rn.report_id
                          LIMIT 1
                      )
                ");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $count += $stmt->get_result()->fetch_assoc()['count'];
                $stmt->close();
            } else {
                $stmt = $conn->prepare("
                    SELECT COUNT(*) as count FROM report_notifications rn
                    WHERE rn.is_read = 0
                      AND EXISTS (
                          SELECT 1 FROM road_transportation_reports WHERE id = rn.report_id
                          UNION ALL
                          SELECT 1 FROM road_maintenance_reports WHERE id = rn.report_id
                          UNION ALL
                          SELECT 1 FROM cimm_verification_reports WHERE id = rn.report_id
                          LIMIT 1
                      )
                ");
                $stmt->execute();
                $count += $stmt->get_result()->fetch_assoc()['count'];
                $stmt->close();
            }
        } catch (Exception $e) {}
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
        ['href' => $nav_base . 'pages/admin/admin_dashboard.php', 'icon' => 'tachometer-alt', 'title' => 'Admin Dashboard', 'roles' => ['system_admin']],
        ['href' => $nav_base . 'pages/admin/manage_accounts.php', 'icon' => 'users', 'title' => 'Manage Accounts', 'roles' => ['system_admin']],
        ['href' => $nav_base . 'pages/admin/account_approvals.php', 'icon' => 'clipboard-check', 'title' => 'Account Approvals', 'roles' => ['system_admin']],
        ['href' => $nav_base . 'pages/admin/create_staff_account.php', 'icon' => 'user-plus', 'title' => 'Create Staff Account', 'roles' => ['system_admin']],
        ['href' => $nav_base . 'pages/admin/send_registration_link.php', 'icon' => 'envelope-open-text', 'title' => 'Send Registration Link', 'roles' => ['system_admin']],
    ],
    'monitoring' => [
        ['href' => $nav_base . 'pages/shared/road_transportation_monitoring.php', 'icon' => 'map-marked-alt', 'title' => 'Road Monitoring', 'roles' => ['system_admin','road_ops_supervisor', 'trans_ops_supervisor', 'road_monitoring_officer', 'trans_monitoring_officer']],
        ['href' => $nav_base . 'pages/admin/verification_monitoring.php', 'icon' => 'shield-alt', 'title' => 'Verification Reports', 'roles' => ['system_admin', 'road_ops_supervisor', 'trans_ops_supervisor']],
        ['href' => $nav_base . 'pages/admin/report_management.php', 'icon' => 'clipboard-list', 'title' => 'Report Management', 'roles' => ['system_admin', 'road_ops_supervisor', 'trans_ops_supervisor']],
    ],
    'transparency' => [
        ['href' => $nav_base . 'pages/shared/public_transparency.php', 'icon' => 'eye', 'title' => 'Public Transparency', 'roles' => ['system_admin', 'lgu_staff']],
    ],
    'reports' => [
        ['href' => $nav_base . 'pages/shared/analytics.php', 'icon' => 'chart-line', 'title' => 'Analytics', 'roles' => ['system_admin', 'lgu_staff']],
        ['href' => $nav_base . 'pages/admin/audit_trail.php', 'icon' => 'history', 'title' => 'Audit Trail', 'roles' => ['system_admin']],
    ],
    'system' => [
        ['href' => $nav_base . 'pages/shared/notifications.php', 'icon' => 'bell', 'title' => 'Notifications', 'roles' => ['system_admin', 'lgu_staff']],
        ['href' => $nav_base . 'pages/admin/archive.php', 'icon' => 'archive', 'title' => 'Archive', 'roles' => ['system_admin', 'road_ops_supervisor', 'trans_ops_supervisor']],
        ['href' => $nav_base . 'pages/lgu/officer_archive.php', 'icon' => 'archive', 'title' => 'Archive', 'roles' => ['road_monitoring_officer']],
        ['href' => $nav_base . 'pages/shared/settings.php', 'icon' => 'cog', 'title' => 'Settings', 'roles' => ['system_admin', 'lgu_staff']],
    ]
];

// Filter by role. The Road & Transportation staff roles share the same menu
// as 'lgu_staff'.
$filtered_items = [];
foreach ($nav_items as $section => $items) {
    $filtered_items[$section] = array_filter($items, function($item) use ($user_role) {
        $visible = in_array($user_role, $item['roles'])
            || (is_staff_role($user_role) && in_array('lgu_staff', $item['roles']));
        // Road & Transportation Operations Supervisors do not get the
        // Change Information menu item.
        if ($visible
            && in_array($user_role, ['trans_ops_supervisor', 'road_ops_supervisor'], true)
            && basename($item['href']) === 'change_info.php') {
            $visible = false;
        }
        return $visible;
    });
}
?>

<aside class="sidebar" id="sidebar" role="complementary">
    <header class="sidebar-header">
        <h2><i class="fas fa-road"></i> <?php echo defined('SITE_NAME') ? SITE_NAME : 'LGU Portal'; ?></h2>
        <p><?php echo htmlspecialchars(getPortalTitle($user_role)); ?></p>
        <div class="user-info">
            <div class="user-name"><?php echo htmlspecialchars($user_info['full_name']); ?></div>
            <div class="user-role"><?php echo htmlspecialchars(ucfirst($user_info['role'])); ?></div>
        </div>
    </header>

    <nav class="sidebar-menu" aria-label="Main navigation">
        <?php foreach ($filtered_items as $section => $items): ?>
            <?php if (!empty($items)): ?>
                <div class="menu-label" id="menu-label-<?php echo $section; ?>"><?php echo ucfirst($section); ?></div>
                <ul role="list" aria-labelledby="menu-label-<?php echo $section; ?>">
                    <?php foreach ($items as $item): ?>
                        <?php
                        $href_file = basename($item['href']);
                        $is_active = ($current_page === $href_file) ? ' active' : '';
                        $aria_current = ($current_page === $href_file) ? ' aria-current="page"' : '';
                        ?>
                        <li role="listitem">
                            <a href="<?php echo $item['href']; ?>" class="nav-link<?php echo $is_active; ?>"<?php echo $aria_current; ?>>
                                <i class="fas fa-<?php echo $item['icon']; ?>" aria-hidden="true"></i>
                                <?php echo htmlspecialchars($item['title']); ?>
                                <?php if ($notification_count > 0 && $item['icon'] === 'bell'): ?>
                                    <span class="notification-badge" role="status" aria-label="<?php echo $notification_count; ?> unread notifications"><?php echo $notification_count; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endforeach; ?>

        <div class="menu-label">Account</div>
        <ul role="list">
            <li role="listitem">
                <a href="<?php echo $nav_base; ?>logout.php" class="nav-link nav-link-logout" id="logoutBtn" role="button">
                    <i class="fas fa-sign-out-alt" aria-hidden="true"></i> Logout
                </a>
            </li>
        </ul>
    </nav>
</aside>


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
