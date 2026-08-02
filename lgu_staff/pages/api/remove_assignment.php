<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => 'Unauthorized'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

$assignment_id = intval($_POST['assignment_id'] ?? 0);

if ($assignment_id <= 0) {
    json_response(['success' => false, 'message' => 'Invalid assignment ID']);
}

try {
    // Update status to cancelled instead of deleting
    $stmt = $conn->prepare("UPDATE report_assignments SET status = 'cancelled' WHERE id = ?");
    $stmt->bind_param("i", $assignment_id);
    $stmt->execute();
    
    if ($stmt->affected_rows === 0) {
        json_response(['success' => false, 'message' => 'Assignment not found']);
    }
    
    // Audit log
    log_audit_action($_SESSION['user_id'], "Cancelled user assignment", "Assignment ID: {$assignment_id}");
    
    json_response(['success' => true, 'message' => 'User unassigned successfully']);
} catch (Exception $e) {
    error_log("Remove assignment error: " . $e->getMessage());
    json_response(['success' => false, 'message' => 'Failed to unassign user: ' . $e->getMessage()], 500);
}
