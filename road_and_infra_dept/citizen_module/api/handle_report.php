<?php
// handle_report.php - API for handling road damage reports from citizens
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

// Debug: Check authentication status
error_log("=== HANDLE_REPORT DEBUG START ===");
error_log("Session data: " . print_r($_SESSION, true));
error_log("Is logged in: " . ($auth->isLoggedIn() ? 'YES' : 'NO'));

if (!$auth->isLoggedIn()) {
    error_log("Authentication failed - user not logged in");
    sendResponse(false, 'Session expired. Please login again.');
}

try {
    $database = new Database();
    $conn = $database->getConnection();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, 'Invalid request method.');
    }

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

    // Generate Report ID (DR-YEAR-RAND)
    $year = date('Y');
    $rand = str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
    $report_id = "DR-$year-$rand";

    // Handle Image Uploads
    $uploaded_images = [];
    
    // Debug: Check if form was submitted with proper enctype
    error_log("=== IMAGE UPLOAD DEBUG START ===");
    error_log("REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
    error_log("CONTENT_TYPE: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));
    error_log("FILES array: " . print_r($_FILES, true));
    error_log("POST array keys: " . implode(', ', array_keys($_POST)));
    
    if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
        error_log("Images found, processing upload...");
        $upload_dir = '../../uploads/reports/';
        
        // Debug: Check upload directory
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
            error_log("Created upload directory: " . $upload_dir);
        } else {
            error_log("Upload directory exists: " . $upload_dir);
            error_log("Directory writable: " . (is_writable($upload_dir) ? 'YES' : 'NO'));
        }

        // Debug: Check each file
        error_log("Processing " . count($_FILES['images']['name']) . " files");
        foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
            $file_name = $_FILES['images']['name'][$key];
            $file_error = $_FILES['images']['error'][$key];
            $file_size = $_FILES['images']['size'][$key];
            
            error_log("File $key: name=$file_name, error=$file_error, size=$file_size, tmp_name=$tmp_name");
            
            if ($file_error === UPLOAD_ERR_OK) {
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                $new_file_name = $report_id . '_' . $key . '_' . time() . '.' . $file_ext;
                $target_path = $upload_dir . $new_file_name;
                
                error_log("Processing file: " . $file_name . " -> " . $new_file_name);
                error_log("Target path: " . $target_path);
                error_log("Temp file exists: " . (file_exists($tmp_name) ? 'YES' : 'NO'));

                if (move_uploaded_file($tmp_name, $target_path)) {
                    $uploaded_images[] = $new_file_name;
                    error_log("Successfully uploaded: " . $new_file_name);
                    error_log("File exists after upload: " . (file_exists($target_path) ? 'YES' : 'NO'));
                } else {
                    error_log("Failed to upload: " . $file_name);
                    error_log("Move_uploaded_file error: " . error_get_last()['message'] ?? 'unknown');
                }
            } else {
                error_log("Upload error for file $file_name: " . $file_error);
            }
        }
    } else {
        error_log("No images found in FILES: " . print_r($_FILES, true));
        error_log("Check if form has enctype='multipart/form-data'");
    }
    
    error_log("Final uploaded_images array: " . print_r($uploaded_images, true));
    error_log("=== IMAGE UPLOAD DEBUG END ===");

    $images_json = json_encode($uploaded_images);

    // Insert into damage_reports table - FIXED bind_param
    $stmt = $conn->prepare("INSERT INTO damage_reports (report_id, reporter_id, location, barangay, damage_type, description, severity, estimated_size, traffic_impact, contact_number, anonymous_report, images, created_at, reported_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $stmt->bind_param("sisssssssisi", $report_id, $user_id, $location, $barangay, $damage_type, $description, $severity, $estimated_size, $traffic_impact, $contact_number, $anonymous_report, $images_json);

    if ($stmt->execute()) {
        // Log Activity
        $auth->logActivity('damage_reported', "Reported road damage at $location ($report_id)");
        
        sendResponse(true, 'Your report has been submitted successfully. Thank you for your cooperation!', ['report_id' => $report_id]);
    } else {
        error_log("Database error: " . $conn->error);
        sendResponse(false, 'Database error: ' . $conn->error);
    }

} catch (Exception $e) {
    error_log("Exception in handle_report: " . $e->getMessage());
    sendResponse(false, 'System error: ' . $e->getMessage());
}
error_log("=== HANDLE_REPORT DEBUG END ===");
?>
