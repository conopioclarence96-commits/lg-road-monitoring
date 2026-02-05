<?php
// debug_handle_report.php - Simplified version for debugging image uploads
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

function sendResponse($success, $message, $extra = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

// Debug: Check request details
error_log("=== DEBUG UPLOAD START ===");
error_log("REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
error_log("CONTENT_TYPE: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));
error_log("POST data: " . print_r($_POST, true));
error_log("FILES data: " . print_r($_FILES, true));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method.');
}

// Basic validation
$location = $_POST['location'] ?? '';
$barangay = $_POST['barangay'] ?? '';
$damage_type = $_POST['damage_type'] ?? '';
$description = $_POST['description'] ?? '';

if (empty($location) || empty($barangay) || empty($damage_type) || empty($description)) {
    sendResponse(false, 'Missing required fields.');
}

// Generate Report ID
$year = date('Y');
$rand = str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
$report_id = "DR-$year-$rand";

// Handle Image Uploads
$uploaded_images = [];

if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
    error_log("Images found, processing upload...");
    $upload_dir = 'road_and_infra_dept/uploads/reports/';
    
    // Check upload directory
    if (!is_dir($upload_dir)) {
        if (mkdir($upload_dir, 0777, true)) {
            error_log("Created upload directory: " . $upload_dir);
        } else {
            error_log("Failed to create upload directory: " . $upload_dir);
            sendResponse(false, 'Failed to create upload directory.');
        }
    }
    
    if (!is_writable($upload_dir)) {
        error_log("Upload directory is not writable: " . $upload_dir);
        sendResponse(false, 'Upload directory is not writable.');
    }

    // Process each file
    foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
        $file_name = $_FILES['images']['name'][$key];
        $file_error = $_FILES['images']['error'][$key];
        $file_size = $_FILES['images']['size'][$key];
        
        error_log("Processing file $key: name=$file_name, error=$file_error, size=$file_size");
        
        if ($file_error === UPLOAD_ERR_OK) {
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $new_file_name = $report_id . '_' . $key . '_' . time() . '.' . $file_ext;
            $target_path = $upload_dir . $new_file_name;
            
            error_log("Moving file from $tmp_name to $target_path");
            
            if (move_uploaded_file($tmp_name, $target_path)) {
                $uploaded_images[] = $new_file_name;
                error_log("Successfully uploaded: " . $new_file_name);
            } else {
                error_log("Failed to move uploaded file: " . $file_name);
                error_log("Error details: " . error_get_last()['message'] ?? 'unknown');
            }
        } else {
            error_log("Upload error for file $file_name: " . $file_error);
        }
    }
} else {
    error_log("No images found in FILES array");
}

error_log("Final uploaded_images: " . print_r($uploaded_images, true));
error_log("=== DEBUG UPLOAD END ===");

// For debugging, we'll just return success without database insertion
sendResponse(true, 'Debug test completed successfully', [
    'report_id' => $report_id,
    'uploaded_images' => $uploaded_images,
    'debug_info' => [
        'files_received' => isset($_FILES['images']) ? 'yes' : 'no',
        'images_count' => isset($_FILES['images']) ? count($_FILES['images']['name']) : 0,
        'upload_dir' => 'road_and_infra_dept/uploads/reports/',
        'upload_dir_exists' => is_dir('road_and_infra_dept/uploads/reports/'),
        'upload_dir_writable' => is_writable('road_and_infra_dept/uploads/reports/')
    ]
]);
?>
