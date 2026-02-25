<?php
// Backend PHP to handle cost estimation updates
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

// Check if user is logged in
if (!is_logged_in()) {
    json_response(['success' => false, 'error' => 'Unauthorized - Please log in again'], 401);
}

// Validate CSRF token
$csrf_token = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($csrf_token)) {
    json_response(['success' => false, 'error' => 'Invalid CSRF token']);
}

// Get and validate input data
$report_id = intval($_POST['report_id'] ?? 0);
$report_type = sanitize_input($_POST['report_type'] ?? '');
$estimation = $_POST['estimation'] ?? '';

// Validation
if ($report_id <= 0) {
    json_response(['success' => false, 'error' => 'Invalid report ID']);
}

if (empty($report_type) || !in_array($report_type, ['transportation', 'maintenance'])) {
    json_response(['success' => false, 'error' => 'Invalid report type']);
}

// Validate estimation: must be numeric and non-negative
if (!is_numeric($estimation)) {
    json_response(['success' => false, 'error' => 'Cost estimation must be a number']);
}

$estimation_value = floatval($estimation);
if ($estimation_value < 0) {
    json_response(['success' => false, 'error' => 'Cost estimation cannot be negative']);
}

// Limit estimation to reasonable maximum (10 million)
if ($estimation_value > 10000000) {
    json_response(['success' => false, 'error' => 'Cost estimation cannot exceed ₱10,000,000']);
}

// Determine table and prepare update
$table = ($report_type === 'transportation') ? 'road_transportation_reports' : 'road_maintenance_reports';
$assign_field = ($report_type === 'transportation') ? 'assigned_to' : 'maintenance_team';

try {
    // Check if estimation column exists
    $result = $conn->query("SHOW COLUMNS FROM {$table} LIKE 'estimation'");
    if (!$result || $result->num_rows === 0) {
        json_response(['success' => false, 'error' => 'Estimation column not found in database']);
    }
    
    // Update estimation in database
    $stmt = $conn->prepare("UPDATE {$table} SET estimation = ?, updated_at = NOW() WHERE id = ?");
    if (!$stmt) {
        json_response(['success' => false, 'error' => 'Database prepare failed: ' . $conn->error]);
    }
    
    $stmt->bind_param("di", $estimation_value, $report_id);
    
    if ($stmt->execute()) {
        // Log the action
        $user_id = $_SESSION['user_id'];
        log_audit_action($user_id, "Updated cost estimation", "Report ID: {$report_id}, New Estimation: ₱" . number_format($estimation_value, 2));
        
        json_response([
            'success' => true, 
            'message' => 'Cost estimation updated successfully',
            'estimation' => $estimation_value,
            'formatted_estimation' => '₱' . number_format($estimation_value, 2)
        ]);
    } else {
        json_response(['success' => false, 'error' => 'Failed to update estimation: ' . $stmt->error]);
    }
    
} catch (Exception $e) {
    json_response(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
