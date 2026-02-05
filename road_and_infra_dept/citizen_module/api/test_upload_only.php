<?php
// test_upload_only.php - Test image upload without database
error_reporting(E_ALL);
ini_set('display_errors', 1);

function log_debug($message) {
    file_put_contents('debug_upload.log', date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND);
}

header('Content-Type: application/json');

log_debug("=== UPLOAD ONLY TEST START ===");
log_debug("REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
log_debug("CONTENT_TYPE: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Show test form
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Image Upload Test (No Database)</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .section { border: 1px solid #ddd; padding: 20px; margin: 20px 0; border-radius: 8px; }
            .result { background: #f8f9fa; padding: 15px; margin: 10px 0; border-radius: 5px; }
            .success { background: #d4edda; color: #155724; }
            .error { background: #f8d7da; color: #721c24; }
            input, button { padding: 10px; margin: 5px; }
            button { background: #007bff; color: white; border: none; cursor: pointer; }
        </style>
    </head>
    <body>
        <h1>🧪 Image Upload Test (No Database)</h1>
        
        <div class="section">
            <h2>Test Image Upload Process</h2>
            <form method="POST" enctype="multipart/form-data">
                <div>
                    <label>Test Location:</label>
                    <input type="text" name="location" value="Test Location" required>
                </div>
                <div>
                    <label>Test Description:</label>
                    <input type="text" name="description" value="Test upload" required>
                </div>
                <div>
                    <label><strong>Upload Image:</strong></label>
                    <input type="file" name="images[]" accept="image/*" required>
                </div>
                <button type="submit">Test Upload</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Handle POST request
log_debug("POST data: " . print_r($_POST, true));
log_debug("FILES data: " . print_r($_FILES, true));

$location = $_POST['location'] ?? '';
$description = $_POST['description'] ?? '';

if (empty($location) || empty($description)) {
    log_debug("Missing required fields");
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Handle image upload
$uploaded_images = [];
$upload_dir = '../../uploads/reports/';

log_debug("Upload directory: $upload_dir");
log_debug("Directory exists: " . (is_dir($upload_dir) ? 'YES' : 'NO'));
log_debug("Directory writable: " . (is_writable($upload_dir) ? 'YES' : 'NO'));

// Create directory if it doesn't exist
if (!is_dir($upload_dir)) {
    if (mkdir($upload_dir, 0777, true)) {
        log_debug("Created upload directory: $upload_dir");
    } else {
        log_debug("Failed to create upload directory: $upload_dir");
    }
}

// Process uploaded files
if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
    log_debug("Processing image uploads...");
    
    foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
        if (!empty($tmp_name) && $_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
            $file_name = $_FILES['images']['name'][$key];
            $file_size = $_FILES['images']['size'][$key];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            
            log_debug("Processing file $key: name=$file_name, size=$file_size, ext=$file_ext");
            
            // Generate unique filename
            $new_file_name = 'TEST_' . $key . '_' . time() . '.' . $file_ext;
            $target_path = $upload_dir . $new_file_name;
            
            log_debug("Moving from $tmp_name to $target_path");
            
            if (move_uploaded_file($tmp_name, $target_path)) {
                $uploaded_images[] = $new_file_name;
                log_debug("Successfully uploaded: $new_file_name");
                
                // Verify file exists
                if (file_exists($target_path)) {
                    log_debug("File verified: $target_path (" . filesize($target_path) . " bytes)");
                } else {
                    log_debug("WARNING: File not found after upload: $target_path");
                }
            } else {
                log_debug("Failed to upload: $file_name");
                log_debug("PHP error: " . error_get_last()['message'] ?? 'unknown');
            }
        } else {
            log_debug("Upload error for file $key: " . $_FILES['images']['error'][$key]);
        }
    }
} else {
    log_debug("No images found in FILES array");
}

log_debug("Final uploaded_images: " . print_r($uploaded_images, true));
log_debug("=== UPLOAD ONLY TEST END ===");

if (!empty($uploaded_images)) {
    echo json_encode([
        'success' => true, 
        'message' => 'Upload successful',
        'uploaded_files' => $uploaded_images,
        'upload_count' => count($uploaded_images)
    ]);
} else {
    echo json_encode([
        'success' => false, 
        'message' => 'No files were uploaded',
        'debug_info' => 'Check debug_upload.log for details'
    ]);
}
?>
