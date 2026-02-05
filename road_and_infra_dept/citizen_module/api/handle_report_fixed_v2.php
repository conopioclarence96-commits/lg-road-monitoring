<?php
// handle_report_fixed.php - Fixed version with better error handling and debugging
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

// Enhanced debugging
error_log("=== FIXED HANDLE_REPORT START ===");
error_log("Session status: " . (session_status() === PHP_SESSION_ACTIVE ? 'ACTIVE' : 'INACTIVE'));
error_log("Session ID: " . session_id());
error_log("Session data: " . print_r($_SESSION, true));

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

    // Log all incoming data
    error_log("POST data: " . print_r($_POST, true));
    error_log("FILES data: " . print_r($_FILES, true));
    error_log("Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));

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

    // Handle Image Uploads with enhanced error handling
    $uploaded_images = [];
    
    // Check if images are being uploaded
    if (isset($_FILES['images']) && is_array($_FILES['images'])) {
        error_log("Images array found in FILES");
        
        // Check if any files were actually uploaded
        if (!empty($_FILES['images']['name'][0])) {
            error_log("Processing image uploads...");
            
            $upload_dir = '../../uploads/reports/';
            
            // Ensure upload directory exists
            if (!is_dir($upload_dir)) {
                if (mkdir($upload_dir, 0777, true)) {
                    error_log("Created upload directory: $upload_dir");
                } else {
                    error_log("Failed to create upload directory: $upload_dir");
                }
            }
            
            // Check directory permissions
            if (!is_writable($upload_dir)) {
                error_log("Upload directory is not writable: $upload_dir");
                // Try to fix permissions
                chmod($upload_dir, 0777);
            }
            
            // Process each uploaded file
            foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
                if (!empty($tmp_name) && $_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
                    $file_name = $_FILES['images']['name'][$key];
                    $file_size = $_FILES['images']['size'][$key];
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    
                    // Validate file type
                    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    if (!in_array($file_ext, $allowed_extensions)) {
                        error_log("Invalid file extension: $file_ext");
                        continue;
                    }
                    
                    // Validate file size (max 5MB)
                    $max_size = 5 * 1024 * 1024;
                    if ($file_size > $max_size) {
                        error_log("File too large: $file_name ($file_size bytes)");
                        continue;
                    }
                    
                    // Generate unique filename
                    $new_file_name = $report_id . '_' . $key . '_' . time() . '.' . $file_ext;
                    $target_path = $upload_dir . $new_file_name;
                    
                    error_log("Processing file: $file_name -> $new_file_name");
                    error_log("Source: $tmp_name, Target: $target_path");
                    
                    // Move uploaded file
                    if (move_uploaded_file($tmp_name, $target_path)) {
                        $uploaded_images[] = $new_file_name;
                        error_log("Successfully uploaded: $new_file_name");
                        
                        // Verify file exists after upload
                        if (file_exists($target_path)) {
                            error_log("File verified: $target_path (" . filesize($target_path) . " bytes)");
                        } else {
                            error_log("WARNING: File not found after upload: $target_path");
                        }
                    } else {
                        error_log("Failed to move uploaded file: $file_name");
                        error_log("Upload error: " . error_get_last()['message'] ?? 'unknown');
                    }
                } else {
                    error_log("Upload error for file $key: " . $_FILES['images']['error'][$key]);
                }
            }
        } else {
            error_log("No files selected for upload (empty images array)");
        }
    } else {
        error_log("No images array found in FILES");
    }
    
    error_log("Final uploaded_images array: " . print_r($uploaded_images, true));

    // Encode images for database storage
    $images_json = json_encode($uploaded_images);
    error_log("Images JSON for database: $images_json");

    // Insert into database
    $stmt = $conn->prepare("INSERT INTO damage_reports (report_id, reporter_id, location, barangay, damage_type, description, severity, estimated_size, traffic_impact, contact_number, anonymous_report, images, created_at, reported_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $stmt->bind_param("sisssssssisi", $report_id, $user_id, $location, $barangay, $damage_type, $description, $severity, $estimated_size, $traffic_impact, $contact_number, $anonymous_report, $images_json);

    if ($stmt->execute()) {
        // Log activity
        $auth->logActivity('damage_reported', "Reported road damage at $location ($report_id)");
        
        error_log("Report successfully inserted into database: $report_id");
        sendResponse(true, 'Your report has been submitted successfully. Thank you for your cooperation!', [
            'report_id' => $report_id,
            'uploaded_images' => $uploaded_images,
            'debug_info' => [
                'images_count' => count($uploaded_images),
                'images_json' => $images_json
            ]
        ]);
    } else {
        error_log("Database error: " . $conn->error);
        sendResponse(false, 'Database error: ' . $conn->error);
    }

} catch (Exception $e) {
    error_log("Exception in handle_report: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    sendResponse(false, 'System error: ' . $e->getMessage());
}

error_log("=== FIXED HANDLE_REPORT END ===");
?>
