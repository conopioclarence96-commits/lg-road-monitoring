<?php
// handle_report_no_auth.php - Test upload without authentication requirement
error_reporting(E_ALL);
ini_set('display_errors', 1);

function log_debug($message) {
    file_put_contents('debug_upload.log', date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND);
}

header('Content-Type: application/json');

log_debug("=== NO AUTH UPLOAD TEST START ===");
log_debug("REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
log_debug("CONTENT_TYPE: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    log_debug("Not a POST request");
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// Log all incoming data
log_debug("POST data: " . print_r($_POST, true));
log_debug("FILES data: " . print_r($_FILES, true));

$location = $_POST['location'] ?? '';
$barangay = $_POST['barangay'] ?? '';
$damage_type = $_POST['damage_type'] ?? '';
$description = $_POST['description'] ?? '';

if (empty($location) || empty($barangay) || empty($damage_type) || empty($description)) {
    log_debug("Missing required fields");
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
    exit;
}

// Generate Report ID
$year = date('Y');
$rand = str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
$report_id = "DR-$year-$rand";

log_debug("Generated report ID: $report_id");

// Handle Image Uploads
$uploaded_images = [];
$upload_dir = '../../uploads/reports/';

log_debug("Upload directory: $upload_dir");
log_debug("Directory exists: " . (is_dir($upload_dir) ? 'YES' : 'NO'));
log_debug("Directory writable: " . (is_writable($upload_dir) ? 'YES' : 'NO'));

// Create directory if needed
if (!is_dir($upload_dir)) {
    if (mkdir($upload_dir, 0777, true)) {
        log_debug("Created upload directory: $upload_dir");
    } else {
        log_debug("Failed to create upload directory: $upload_dir");
    }
}

// Process uploaded files
if (isset($_FILES['images']) && is_array($_FILES['images'])) {
    log_debug("Images array found in FILES");
    
    if (!empty($_FILES['images']['name'][0])) {
        log_debug("Processing image uploads...");
        
        foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
            if (!empty($tmp_name) && $_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
                $file_name = $_FILES['images']['name'][$key];
                $file_size = $_FILES['images']['size'][$key];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                log_debug("Processing file $key: name=$file_name, size=$file_size, ext=$file_ext");
                
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
                
                log_debug("Moving from $tmp_name to $target_path");
                
                if (move_uploaded_file($tmp_name, $target_path)) {
                    $uploaded_images[] = $new_file_name;
                    log_debug("Successfully uploaded: $new_file_name");
                    
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

// Encode images for storage (simulating database)
$images_json = json_encode($uploaded_images);
log_debug("Images JSON: $images_json");

log_debug("=== NO AUTH UPLOAD TEST END ===");

// Return success response
echo json_encode([
    'success' => true,
    'message' => 'Test upload completed successfully',
    'report_id' => $report_id,
    'uploaded_images' => $uploaded_images,
    'images_json' => $images_json,
    'debug_info' => [
        'images_count' => count($uploaded_images),
        'upload_dir' => $upload_dir,
        'directory_exists' => is_dir($upload_dir),
        'directory_writable' => is_writable($upload_dir)
    ]
]);
?>
