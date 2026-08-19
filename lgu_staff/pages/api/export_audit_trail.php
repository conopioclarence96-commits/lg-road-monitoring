<?php
// Session settings (must be set before session_start)
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS

session_start();
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

// Check if user is logged in
if (!is_logged_in()) {
    die('Unauthorized access');
}

// Only the system admin can export the full audit trail.
if (($_SESSION['role'] ?? '') !== 'system_admin') {
    die('Unauthorized access');
}

// Export ALL audit trail records (not just the currently visible page).
$logs = fetch_all(
    "SELECT a.id, a.action, a.details, a.ip_address, a.user_agent, a.created_at,
            u.username, u.full_name, u.role
     FROM audit_logs a
     LEFT JOIN users u ON a.user_id = u.id
     ORDER BY a.created_at DESC"
);

$headers = ['ID', 'Date/Time', 'User', 'Role', 'Action', 'Details', 'IP Address', 'User Agent'];
$csv_data = [];

foreach ($logs as $log) {
    $csv_data[] = [
        $log['id'],
        format_date($log['created_at']),
        $log['full_name'] ? $log['full_name'] . ' (' . $log['username'] . ')' : 'System',
        $log['role'] ? ucfirst(str_replace('_', ' ', $log['role'])) : '—',
        $log['action'],
        $log['details'] ?? '',
        $log['ip_address'] ?? '',
        $log['user_agent'] ?? '',
    ];
}

$filename = 'audit_trail_export_' . date('Y-m-d_H-i-s') . '.csv';

// Export to CSV
export_to_csv($csv_data, $filename, $headers);
?>
