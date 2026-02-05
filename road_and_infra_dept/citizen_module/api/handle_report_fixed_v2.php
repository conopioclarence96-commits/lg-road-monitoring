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
function log_debug($message) {
    file_put_contents('debug_upload.log', date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND);
}

log_debug("=== FIXED HANDLE_REPORT START ===");
log_debug("Session status: " . (session_status() === PHP_SESSION_ACTIVE ? 'ACTIVE' : 'INACTIVE'));
log_debug("Session ID: " . session_id());
log_debug("Session data: " . print_r($_SESSION, true));

if (!$auth->isLoggedIn()) {
    log_debug("Authentication failed - user not logged in");
    sendResponse(false, 'Session expired. Please login again.');
}

try {
    $database = new Database();
    $conn = $database->getConnection();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, 'Invalid request method.');
    }

    // Log all incoming data
    log_debug("POST data: " . print_r($_POST, true));
    log_debug("FILES data: " . print_r($_FILES, true));
    log_debug("Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));

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
        log_debug("Images array found in FILES");
        
        // Check if any files were actually uploaded
        if (!empty($_FILES['images']['name'][0])) {
            log_debug("Processing image uploads...");
            
            $upload_dir = '../../uploads/reports/';
            
            // Ensure upload directory exists
            if (!is_dir($upload_dir)) {
                if (mkdir($upload_dir, 0777, true)) {
                    log_debug("Created upload directory: $upload_dir");
                } else {
                    log_debug("Failed to create upload directory: $upload_dir");
                }
            }
            
            // Check directory permissions
            if (!is_writable($upload_dir)) {
                log_debug("Upload directory is not writable: $upload_dir");
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
                        log_debug("Invalid file extension: $file_ext");
                        continue;
                    }
                    
                    // Validate file size (max 5MB)
                    $max_size = 5 * 1024 * 1024;
                    if ($file_size > $max_size) {
                        log_debug("File too large: $file_name ($file_size bytes)");
                        continue;
                    }
                    
                    // Generate unique filename
                    $new_file_name = $report_id . '_' . $key . '_' . time() . '.' . $file_ext;
                    $target_path = $upload_dir . $new_file_name;
                    
                    log_debug("Processing file: $file_name -> $new_file_name");
                    log_debug("Source: $tmp_name, Target: $target_path");
                    
                    // Move uploaded file
                    if (move_uploaded_file($tmp_name, $target_path)) {
                        $uploaded_images[] = $new_file_name;
                        log_debug("Successfully uploaded: $new_file_name");
                        
                        // Verify file exists after upload
                        if (file_exists($target_path)) {
                            log_debug("File verified: $target_path (" . filesize($target_path) . " bytes)");
                        } else {
                            log_debug("WARNING: File not found after upload: $target_path");
                        }
                    } else {
                        log_debug("Failed to move uploaded file: $file_name");
                        log_debug("Upload error: " . error_get_last()['message'] ?? 'unknown');
                    }
                } else {
                    log_debug("Upload error for file $key: " . $_FILES['images']['error'][$key]);
                }
            }
        } else {
            log_debug("No files selected for upload (empty images array)");
        }
    } else {
        log_debug("No images array found in FILES");
    }
    
    log_debug("Final uploaded_images array: " . print_r($uploaded_images, true));

    // Encode images for database storage
    $images_json = json_encode($uploaded_images);
    log_debug("Images JSON for database: $images_json");

    // Insert into database
    $stmt = $conn->prepare("INSERT INTO damage_reports (report_id, reporter_id, location, barangay, damage_type, description, severity, estimated_size, traffic_impact, contact_number, anonymous_report, images, created_at, reported_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $stmt->bind_param("sisssssssisi", $report_id, $user_id, $location, $barangay, $damage_type, $description, $severity, $estimated_size, $traffic_impact, $contact_number, $anonymous_report, $images_json);

    if ($stmt->execute()) {
        // Log activity
        $auth->logActivity('damage_reported', "Reported road damage at $location ($report_id)");
        
        log_debug("Report successfully inserted into database: $report_id");
        sendResponse(true, 'Your report has been submitted successfully. Thank you for your cooperation!', [
            'report_id' => $report_id,
            'uploaded_images' => $uploaded_images,
            'debug_info' => [
                'images_count' => count($uploaded_images),
                'images_json' => $images_json
            ]
        ]);
    } else {
        log_debug("Database error: " . $conn->error);
        sendResponse(false, 'Database error: ' . $conn->error);
    }

} catch (Exception $e) {
    log_debug("Exception in handle_report: " . $e->getMessage());
    log_debug("Stack trace: " . $e->getTraceAsString());
    sendResponse(false, 'System error: ' . $e->getMessage());
}

log_debug("=== FIXED HANDLE_REPORT END ===");
?>
