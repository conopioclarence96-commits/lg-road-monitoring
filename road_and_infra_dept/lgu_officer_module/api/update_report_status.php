<?php
// update_report_status.php - API for LGU officers to update report status
session_start();
require_once '../../config/auth.php';
require_once '../../config/database.php';

header('Content-Type: application/json');

function sendResponse($success, $message, $data = []) {
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit;
}

if (!$auth->isLoggedIn()) {
    sendResponse(false, 'Session expired. Please login again.');
}

$auth->requireAnyRole(['lgu_officer', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method.');
}

try {
    $database = new Database();
    $conn = $database->getConnection();

    // Get JSON data from request body
    $json_input = file_get_contents('php://input');
    $data = json_decode($json_input, true);
    
    $report_id = $data['report_id'] ?? '';
    $status = $data['status'] ?? '';
    $lgu_notes = $data['notes'] ?? ''; // JavaScript sends 'notes', not 'lgu_notes'
    $assigned_to = $data['assigned_to'] ?? null;
    $officer_id = $_SESSION['user_id'];

    if (empty($report_id) || empty($status)) {
        sendResponse(false, 'Report ID and status are required.');
    }

    // Validate status
    $valid_statuses = ['pending', 'under_review', 'approved', 'in_progress', 'completed', 'rejected'];
    if (!in_array($status, $valid_statuses)) {
        sendResponse(false, 'Invalid status value.');
    }

    // Start transaction
    $conn->begin_transaction();

    // Update the report using correct column names
    $update_query = "
        UPDATE damage_reports 
        SET status = ?, lgu_notes = ?, updated_at = CURRENT_TIMESTAMP
    ";
    
    $params = [$status, $lgu_notes];
    $types = 'ss';

    // If assigning to officer, include that
    if ($assigned_to) {
        $update_query .= ", assigned_to = ?";
        $params[] = $assigned_to;
        $types .= 'i';
    }

    $update_query .= " WHERE report_id = ?";
    $params[] = $report_id;
    $types .= 's';

    $stmt = $conn->prepare($update_query);
    $stmt->bind_param($types, ...$params);

    if (!$stmt->execute()) {
        throw new Exception("Failed to update report: " . $conn->error);
    }

    // If status is being changed to 'approved', create an inspection record
    if ($status === 'approved') {
        // Generate inspection ID
        $inspection_id = 'INSP-' . date('Y') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Insert into inspections table
        $inspection_query = "
            INSERT INTO inspections (
                inspection_id, location, severity, 
                description, photos, inspector_id, status, created_by, 
                inspection_date, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ";
        
        // Get report details for inspection
        $report_query = "SELECT * FROM damage_reports WHERE report_id = ?";
        $report_stmt = $conn->prepare($report_query);
        $report_stmt->bind_param('s', $report_id);
        $report_stmt->execute();
        $report_data = $report_stmt->get_result()->fetch_assoc();
        
        $inspection_stmt = $conn->prepare($inspection_query);
        
        // Create variables for bind_param
        $inspector_id = $report_data['reporter_id'] ?: null;
        
        $inspection_stmt->bind_param(
            'ssssssis',
            $inspection_id,
            $report_data['location'],
            $report_data['severity'],
            $report_data['description'],
            $report_data['images'],
            $inspector_id,
            $officer_id
        );
        
        if (!$inspection_stmt->execute()) {
            throw new Exception("Failed to create inspection record: " . $conn->error);
        }
        
        // Log inspection creation
        $auth->logActivity('inspection_created', "Created inspection $inspection_id from approved citizen report $report_id");
    }

    // Log the activity
    $activity_details = "Updated report $report_id status to $status";
    if ($lgu_notes) {
        $activity_details .= " with notes: " . substr($lgu_notes, 0, 100);
    }
    $auth->logActivity('report_status_updated', $activity_details);

    // Get updated report details
    $select_query = "
        SELECT dr.*, CONCAT(u.first_name, ' ', u.last_name) as reporter_name, CONCAT(ao.first_name, ' ', ao.last_name) as assigned_officer_name
        FROM damage_reports dr
        LEFT JOIN users u ON dr.reporter_id = u.id
        LEFT JOIN users ao ON dr.assigned_to = ao.id
        WHERE dr.report_id = ?
    ";
    
    $select_stmt = $conn->prepare($select_query);
    $select_stmt->bind_param('s', $report_id);
    $select_stmt->execute();
    $result = $select_stmt->get_result();
    $updated_report = $result->fetch_assoc();

    $conn->commit();

    $response_data = ['report' => $updated_report];
    
    // Include inspection details if one was created
    if ($status === 'approved' && isset($inspection_id)) {
        $response_data['inspection_created'] = [
            'inspection_id' => $inspection_id,
            'message' => 'Inspection record created successfully'
        ];
    }

    sendResponse(true, 'Report status updated successfully', $response_data);

} catch (Exception $e) {
    $conn->rollback();
    sendResponse(false, 'System error: ' . $e->getMessage());
}
?>
