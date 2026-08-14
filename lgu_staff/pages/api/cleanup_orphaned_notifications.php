<?php
/**
 * One-time cleanup script: Remove all notifications that reference reports
 * that no longer exist in any live table.
 *
 * Run this script once, then delete it.
 * Access via: /lgu_staff/pages/api/cleanup_orphaned_notifications.php
 */
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'system_admin') {
    die('Access denied. Admin only.');
}

// Find and delete notifications where the report no longer exists in any live table.
// Only real notification types are considered: the marker rows (type 'admin_keep',
// 'admin_read', 'always_on_read') reference report_id 0 and must never be removed.
$sql = "DELETE rn FROM report_notifications rn
        WHERE rn.type IN ('progress_update','approve_request','reject_request','complete_report','cancel_report','completion','cancellation')
          AND NOT EXISTS (
            SELECT 1 FROM road_transportation_reports WHERE id = rn.report_id
            UNION ALL
            SELECT 1 FROM road_maintenance_reports WHERE id = rn.report_id
            UNION ALL
            SELECT 1 FROM cimm_verification_reports WHERE id = rn.report_id
            LIMIT 1
        )";

try {
    $result = $conn->query($sql);
    $affected = $conn->affected_rows;
    echo "Cleanup complete. Removed {$affected} orphaned notification(s).\n";
    echo "You can now delete this script.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
