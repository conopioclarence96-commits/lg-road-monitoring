<?php
// get_citizen_reports_test.php - API without authentication for testing
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

function sendResponse($success, $message, $data = []) {
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit;
}

try {
    // Use domain database configuration
    require_once '../../config/database.php';
    $database = new Database();
    $conn = $database->getConnection();

    // Build base query
    $query = "
        SELECT dr.*, 
               CONCAT(u.first_name, ' ', u.last_name) as reporter_name, 
               u.email as reporter_email,
               CONCAT(ao.first_name, ' ', ao.last_name) as assigned_officer_name
        FROM damage_reports dr
        LEFT JOIN users u ON dr.reporter_id = u.id
        LEFT JOIN users ao ON dr.assigned_to = ao.id
        ORDER BY dr.reported_at DESC
        LIMIT 10
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();

    $reports = [];
    while ($row = $result->fetch_assoc()) {
        // Parse images JSON if exists
        $raw_images = $row['images'];
        $row['images'] = $row['images'] ? json_decode($row['images'], true) : [];
        
        // Debug logging
        error_log("Report ID: " . $row['report_id'] . " - Raw images: " . $raw_images);
        error_log("Report ID: " . $row['report_id'] . " - Parsed images: " . print_r($row['images'], true));
        
        // Format date
        $row['created_at_formatted'] = date('M j, Y g:i A', strtotime($row['reported_at']));
        $row['updated_at_formatted'] = date('M j, Y g:i A', strtotime($row['updated_at']));
        
        $reports[] = $row;
    }

    // Get basic statistics
    $stats_query = "
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'under_review' THEN 1 ELSE 0 END) as under_review,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM damage_reports
    ";
    
    $stats_result = $conn->query($stats_query);
    $stats = $stats_result->fetch_assoc();

    sendResponse(true, 'Reports retrieved successfully', [
        'reports' => $reports,
        'stats' => $stats,
        'test_mode' => true
    ]);

} catch (Exception $e) {
    sendResponse(false, 'System error: ' . $e->getMessage());
}
?>
