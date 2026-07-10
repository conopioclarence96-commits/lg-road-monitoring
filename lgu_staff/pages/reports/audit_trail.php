<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

if (!is_logged_in()) {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'citizen';

$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

$action_filter = sanitize_input($_GET['action'] ?? '');
$user_filter = intval($_GET['user_id'] ?? 0);
$date_from = sanitize_input($_GET['date_from'] ?? '');
$date_to = sanitize_input($_GET['date_to'] ?? '');

$where = [];
$params = [];
$types = '';

if ($action_filter) {
    $where[] = "a.action LIKE ?";
    $params[] = "%{$action_filter}%";
    $types .= "s";
}

if ($user_filter > 0) {
    $where[] = "a.user_id = ?";
    $params[] = $user_filter;
    $types .= "i";
}

if ($date_from) {
    $where[] = "DATE(a.created_at) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if ($date_to) {
    $where[] = "DATE(a.created_at) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

$where_clause = !empty($where) ? "WHERE " . implode(' AND ', $where) : '';

$count_query = "SELECT COUNT(*) as total FROM audit_logs a {$where_clause}";
if (!empty($params)) {
    $count_result = execute_query($count_query, $params, $types);
    $total = $count_result->get_result()->fetch_assoc()['total'];
} else {
    $total = fetch_one($count_query)['total'];
}

$total_pages = max(1, ceil($total / $per_page));

$query = "SELECT a.*, u.username, u.full_name 
          FROM audit_logs a 
          LEFT JOIN users u ON a.user_id = u.id 
          {$where_clause} 
          ORDER BY a.created_at DESC 
          LIMIT ? OFFSET ?";

$query_params = $params;
$query_types = $types;
$query_params[] = $per_page;
$query_types .= "i";
$query_params[] = $offset;
$query_types .= "i";

$logs = fetch_all($query, $query_params, $query_types);

$users = fetch_all("SELECT id, username, full_name FROM users ORDER BY full_name ASC");

$actions_list = fetch_all("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC");

$action_icons = [
    'login' => 'fa-sign-in-alt',
    'logout' => 'fa-sign-out-alt',
    'create' => 'fa-plus-circle',
    'update' => 'fa-edit',
    'delete' => 'fa-trash-alt',
    'archive' => 'fa-archive',
    'restore' => 'fa-undo',
    'approve' => 'fa-check-circle',
    'reject' => 'fa-times-circle',
    'Received' => 'fa-inbox',
    'Updated' => 'fa-edit',
    'Archived' => 'fa-archive',
    'Viewed' => 'fa-eye',
    'Created' => 'fa-plus-circle',
    'Deleted' => 'fa-trash-alt',
    'Accepted' => 'fa-check-double',
    'Generated' => 'fa-file-export',
    'Exported' => 'fa-file-export',
];

function getActionIcon($action) {
    global $action_icons;
    foreach ($action_icons as $key => $icon) {
        if (stripos($action, $key) !== false) {
            return $icon;
        }
    }
    return 'fa-history';
}

function getActionColor($action) {
    if (stripos($action, 'login') !== false) return '#2563eb';
    if (stripos($action, 'logout') !== false) return '#6b7280';
    if (stripos($action, 'create') !== false || stripos($action, 'Created') !== false) return '#059669';
    if (stripos($action, 'update') !== false || stripos($action, 'Updated') !== false) return '#d97706';
    if (stripos($action, 'delete') !== false || stripos($action, 'Deleted') !== false) return '#dc2626';
    if (stripos($action, 'archive') !== false || stripos($action, 'Archived') !== false) return '#6b7280';
    if (stripos($action, 'approve') !== false) return '#059669';
    if (stripos($action, 'reject') !== false) return '#dc2626';
    if (stripos($action, 'view') !== false || stripos($action, 'Viewed') !== false) return '#2563eb';
    if (stripos($action, 'export') !== false || stripos($action, 'Exported') !== false) return '#0284c7';
    if (stripos($action, 'Accepted') !== false) return '#059669';
    if (stripos($action, 'Generated') !== false) return '#0284c7';
    if (stripos($action, 'Restored') !== false) return '#059669';
    return '#6b7280';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Trail - LGU Road Monitoring</title>
    <link rel="icon" type="image/png" href="/lg-road-monitoring/assets/img/logocityhall.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/enhanced-reports.css">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <iframe src="../../includes/sidebar.php" 
            style="position: fixed; width: 250px; height: 100vh; border: none; z-index: 1000;" 
            frameborder="0" name="sidebar-frame" scrolling="no" loading="lazy">
    </iframe>

    <div style="margin-left: 250px; padding: 28px; position: relative; z-index: 1;">
        <div class="page-header">
            <div>
                <h1><i class="fas fa-history"></i> Audit Trail</h1>
                <p>Comprehensive system activity log with filtering and search</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-outline" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
                <span style="font-size: 13px; color: var(--text-secondary); padding: 8px 0;">
                    <i class="fas fa-database"></i> <?php echo number_format($total); ?> total entries
                </span>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title"><i class="fas fa-filter"></i> Filter Logs</h3>
            </div>
            <div class="panel-body">
                <form method="GET" class="filter-bar">
                    <div>
                        <label style="font-size: 11px; color: var(--text-secondary); font-weight: 500; display: block; margin-bottom: 4px;">Action</label>
                        <select name="action">
                            <option value="">All Actions</option>
                            <?php foreach ($actions_list as $a): ?>
                            <option value="<?php echo htmlspecialchars($a['action']); ?>" <?php echo $action_filter === $a['action'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($a['action']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="font-size: 11px; color: var(--text-secondary); font-weight: 500; display: block; margin-bottom: 4px;">User</label>
                        <select name="user_id">
                            <option value="">All Users</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $user_filter === intval($u['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['full_name'] ?: $u['username']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="font-size: 11px; color: var(--text-secondary); font-weight: 500; display: block; margin-bottom: 4px;">From</label>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div>
                        <label style="font-size: 11px; color: var(--text-secondary); font-weight: 500; display: block; margin-bottom: 4px;">To</label>
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div style="align-self: flex-end;">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply</button>
                        <a href="audit_trail.php" class="btn btn-outline"><i class="fas fa-times"></i> Clear</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title"><i class="fas fa-list"></i> Activity Log</h3>
                <span style="font-size: 13px; color: var(--text-secondary);">
                    Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                </span>
            </div>
            <div class="panel-body" style="padding: 0;">
                <?php if (empty($logs)): ?>
                <div style="text-align: center; padding: 60px 20px; color: var(--text-secondary);">
                    <i class="fas fa-history" style="font-size: 48px; margin-bottom: 16px; opacity: 0.3;"></i>
                    <p>No audit log entries found matching your criteria.</p>
                </div>
                <?php else: ?>
                <div class="timeline" style="padding: 24px 24px 24px 60px;">
                    <?php foreach ($logs as $log): ?>
                    <div class="timeline-item">
                        <div class="timeline-icon" style="background: <?php echo getActionColor($log['action']); ?>;">
                            <i class="fas <?php echo getActionIcon($log['action']); ?>"></i>
                        </div>
                        <div class="timeline-content">
                            <div class="timeline-time">
                                <i class="fas fa-clock"></i> 
                                <?php echo format_datetime($log['created_at']); ?>
                                <?php if ($log['ip_address'] && $log['ip_address'] !== 'Unknown'): ?>
                                <span style="margin-left: 12px;"><i class="fas fa-network-wired"></i> <?php echo htmlspecialchars($log['ip_address']); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="timeline-title">
                                <?php echo htmlspecialchars($log['action']); ?>
                                <?php if ($log['full_name']): ?>
                                <span style="font-weight: 400; color: var(--text-secondary);">
                                    by <?php echo htmlspecialchars($log['full_name']); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <?php if ($log['details']): ?>
                            <div class="timeline-desc">
                                <?php echo htmlspecialchars($log['details']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="?page=<?php echo ($page - 1); ?>&action=<?php echo urlencode($action_filter); ?>&user_id=<?php echo $user_filter; ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>">
                <i class="fas fa-chevron-left"></i> Previous
            </a>
            <?php endif; ?>
            
            <?php
            $start = max(1, $page - 2);
            $end = min($total_pages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
                <?php if ($i === $page): ?>
                <span class="active"><?php echo $i; ?></span>
                <?php else: ?>
                <a href="?page=<?php echo $i; ?>&action=<?php echo urlencode($action_filter); ?>&user_id=<?php echo $user_filter; ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>">
                    <?php echo $i; ?>
                </a>
                <?php endif; ?>
            <?php endfor; ?>
            
            <?php if ($page < $total_pages): ?>
            <a href="?page=<?php echo ($page + 1); ?>&action=<?php echo urlencode($action_filter); ?>&user_id=<?php echo $user_filter; ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>">
                Next <i class="fas fa-chevron-right"></i>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="page-transition-overlay" id="pageTransitionOverlay">
        <div class="transition-content">
            <div class="transition-spinner"><i class="fas fa-spinner"></i></div>
            <div class="transition-text">Loading...</div>
        </div>
    </div>
</body>
</html>
