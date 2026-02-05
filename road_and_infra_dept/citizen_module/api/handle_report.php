<?php
// handle_report.php - FINAL WORKING VERSION with authentication
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../../config/auth.php';
require_once '../../config/database.php';

header('Content-Type: application/json');

function sendResponse($success, $message, $extra = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

// Check authentication
if (!$auth->isLoggedIn()) {
    sendResponse(false, 'Session expired. Please login again.');
}

try {
    $database = new Database();
    $conn = $database->getConnection();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, 'Invalid request method.');
    }

    // Get form data
    $location = $_POST['location'] ?? '';
    $barangay = $_POST['barangay'] ?? '';
    $damage_type = $_POST['damage_type'] ?? '';
    $severity = $_POST['severity'] ?? 'medium';
    $description = $_POST['description'] ?? '';
    $estimated_size = $_POST['estimated_size'] ?? '';
    $traffic_impact = $_POST['traffic_impact'] ?? 'moderate';
    $contact_number = $_POST['contact_number'] ?? '';
    $anonymous_report = isset($_POST['anonymous_report']) ? 1 : 0;
    $user_id = $anonymous_report ? null : $_SESSION['user_id'];

    if (empty($location) || empty($barangay) || empty($damage_type) || empty($description)) {
        sendResponse(false, 'Please fill in all required fields.');
    }

    // Generate Report ID
    $year = date('Y');
    $rand = str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
    $report_id = "DR-$year-$rand";

    // Handle Image Uploads - WORKING VERSION
    $uploaded_images = [];
    
    if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
        $upload_dir = '../../uploads/reports/';
        
        // Create directory if needed
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Process each uploaded file
        foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
            $file_name = $_FILES['images']['name'][$key];
            $file_error = $_FILES['images']['error'][$key];
            
            if ($file_error === UPLOAD_ERR_OK && !empty($tmp_name)) {
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                $new_file_name = $report_id . '_' . $key . '_' . time() . '.' . $file_ext;
                $target_path = $upload_dir . $new_file_name;
                
                if (move_uploaded_file($tmp_name, $target_path)) {
                    $uploaded_images[] = $new_file_name;
                }
            }
        }
    }

    // Convert to JSON
    $images_json = json_encode($uploaded_images);

    // Insert into database
    $stmt = $conn->prepare("INSERT INTO damage_reports (report_id, reporter_id, location, barangay, damage_type, description, severity, estimated_size, traffic_impact, contact_number, anonymous_report, images, created_at, reported_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $stmt->bind_param("sisssssssisi", $report_id, $user_id, $location, $barangay, $damage_type, $description, $severity, $estimated_size, $traffic_impact, $contact_number, $anonymous_report, $images_json);

    if ($stmt->execute()) {
        // Log activity
        $auth->logActivity('damage_reported', "Reported road damage at $location ($report_id)");
        
        sendResponse(true, 'Your report has been submitted successfully. Thank you for your cooperation!', [
            'report_id' => $report_id,
            'uploaded_images' => $uploaded_images
        ]);
    } else {
        sendResponse(false, 'Database error: ' . $conn->error);
    }

} catch (Exception $e) {
    sendResponse(false, 'System error: ' . $e->getMessage());
}
?>
