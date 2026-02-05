<?php
// Test image upload process without database
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

function sendResponse($success, $message, $extra = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

// Simulate POST request for testing
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Show test form
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Image Upload Test</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .test-section { border: 1px solid #ddd; padding: 20px; margin: 20px 0; border-radius: 8px; }
            .result { background: #f8f9fa; padding: 15px; margin: 10px 0; border-radius: 5px; }
            .success { background: #d4edda; color: #155724; }
            .error { background: #f8d7da; color: #721c24; }
            input, button { padding: 10px; margin: 5px; }
            button { background: #007bff; color: white; border: none; cursor: pointer; }
        </style>
    </head>
    <body>
        <h1>🧪 Image Upload Test (No Database)</h1>
        
        <div class="test-section">
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
        
        <div class="test-section">
            <h2>Current Upload Directory Status</h2>
            <?php
            $upload_dir = 'road_and_infra_dept/uploads/reports/';
            if (is_dir($upload_dir)) {
                echo "<p>✅ Directory exists: $upload_dir</p>";
                echo "<p>Writable: " . (is_writable($upload_dir) ? "✅ Yes" : "❌ No") . "</p>";
                
                $files = scandir($upload_dir);
                $image_files = array_filter($files, function($file) use ($upload_dir) {
                    return $file !== '.' && $file !== '..' && !is_dir($upload_dir . '/' . $file);
                });
                
                if (!empty($image_files)) {
                    echo "<h3>Existing files:</h3>";
                    foreach ($image_files as $file) {
                        echo "<code>$file</code><br>";
                    }
                } else {
                    echo "<p>No files in directory</p>";
                }
            } else {
                echo "<p>❌ Directory does not exist: $upload_dir</p>";
            }
            ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("=== IMAGE UPLOAD TEST START ===");
    error_log("POST data: " . print_r($_POST, true));
    error_log("FILES data: " . print_r($_FILES, true));
    
    $location = $_POST['location'] ?? '';
    $description = $_POST['description'] ?? '';
    
    if (empty($location) || empty($description)) {
        sendResponse(false, 'Missing required fields');
    }
    
    // Handle image upload
    $uploaded_images = [];
    $upload_dir = 'road_and_infra_dept/uploads/reports/';
    
    // Create directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        if (mkdir($upload_dir, 0777, true)) {
            error_log("Created upload directory: $upload_dir");
        } else {
            sendResponse(false, 'Failed to create upload directory');
        }
    }
    
    if (!is_writable($upload_dir)) {
        sendResponse(false, 'Upload directory is not writable');
    }
    
    // Process uploaded files
    if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
        foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
            $file_name = $_FILES['images']['name'][$key];
            $file_error = $_FILES['images']['error'][$key];
            $file_size = $_FILES['images']['size'][$key];
            
            error_log("Processing file $key: name=$file_name, error=$file_error, size=$file_size");
            
            if ($file_error === UPLOAD_ERR_OK) {
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                $new_file_name = 'TEST_' . $key . '_' . time() . '.' . $file_ext;
                $target_path = $upload_dir . $new_file_name;
                
                if (move_uploaded_file($tmp_name, $target_path)) {
                    $uploaded_images[] = $new_file_name;
                    error_log("Successfully uploaded: $new_file_name");
                } else {
                    error_log("Failed to upload: $file_name");
                }
            } else {
                error_log("Upload error: $file_error");
            }
        }
    } else {
        error_log("No images found in FILES array");
    }
    
    error_log("Final uploaded_images: " . print_r($uploaded_images, true));
    error_log("=== IMAGE UPLOAD TEST END ===");
    
    if (!empty($uploaded_images)) {
        sendResponse(true, 'Upload successful', [
            'uploaded_files' => $uploaded_images,
            'upload_count' => count($uploaded_images),
            'upload_dir' => $upload_dir
        ]);
    } else {
        sendResponse(false, 'No files were uploaded');
    }
}
?>
