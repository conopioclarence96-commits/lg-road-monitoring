<?php
// handle_report_test.php - Test version without authentication with extensive logging
error_reporting(E_ALL);
ini_set('display_errors', 1);

function log_debug($message) {
    $log_message = date('Y-m-d H:i:s') . ' - ' . $message . "\n";
    file_put_contents('handle_report_debug.log', $log_message, FILE_APPEND);
    // Also echo for immediate feedback
    echo $log_message . "<br>";
}

// Skip session and auth for testing
// session_start();
// require_once '../../config/auth.php';
// require_once '../../config/database.php';

header('Content-Type: application/json');

log_debug("=== HANDLE_REPORT_TEST START ===");
log_debug("REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
log_debug("CONTENT_TYPE: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));

function sendResponse($success, $message, $extra = []) {
    log_debug("RESPONSE: success=$success, message=$message");
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

// Bypass authentication for testing
// if (!$auth->isLoggedIn()) {
//     sendResponse(false, 'Session expired. Please login again.');
// }

try {
    log_debug("Starting database connection...");
    
    // Use the domain's database configuration
    require_once '../../config/database.php';
    $database = new Database();
    $conn = $database->getConnection();
    
    log_debug("Database connection successful");

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        log_debug("Invalid request method: " . $_SERVER['REQUEST_METHOD']);
        sendResponse(false, 'Invalid request method.');
    }

    // Log all incoming data
    log_debug("POST data: " . print_r($_POST, true));
    log_debug("FILES data: " . print_r($_FILES, true));

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
    
    // Handle foreign key constraint - use existing user or create a test user
    if ($anonymous_report) {
        // For anonymous reports, try to find an existing user or use a default
        $check_user = $conn->prepare("SELECT id FROM users ORDER BY id LIMIT 1");
        $check_user->execute();
        $user_result = $check_user->get_result();
        
        if ($user_result->num_rows > 0) {
            $user_row = $user_result->fetch_assoc();
            $user_id = $user_row['id'];
        } else {
            // Create a test user if none exists
            $insert_user = $conn->prepare("INSERT INTO users (username, email, first_name, last_name, password, role, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $test_username = 'test_user_' . time();
            $test_email = 'test@example.com';
            $test_password = password_hash('test123', PASSWORD_DEFAULT);
            $test_role = 'citizen';
            
            $insert_user->bind_param('ssssss', $test_username, $test_email, 'Test', 'User', $test_password, $test_role);
            $insert_user->execute();
            $user_id = $conn->insert_id;
        }
    } else {
        // For non-anonymous, use the first available user
        $check_user = $conn->prepare("SELECT id FROM users ORDER BY id LIMIT 1");
        $check_user->execute();
        $user_result = $check_user->get_result();
        
        if ($user_result->num_rows > 0) {
            $user_row = $user_result->fetch_assoc();
            $user_id = $user_row['id'];
        } else {
            sendResponse(false, 'No users found in database. Cannot create report.');
        }
    }

    if (empty($location) || empty($barangay) || empty($damage_type) || empty($description)) {
        sendResponse(false, 'Please fill in all required fields.');
    }

    // Generate Report ID
    $year = date('Y');
    $rand = str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
    $report_id = "DR-$year-$rand";

    // Handle Image Uploads - DOMAIN VERSION
    $uploaded_images = [];
    
    log_debug("Starting image upload process...");
    
    // Check if images were uploaded
    if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
        log_debug("Images array found in FILES");
        log_debug("Images name array: " . print_r($_FILES['images']['name'], true));
        log_debug("Images error array: " . print_r($_FILES['images']['error'], true));
        
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
        
        // Process each uploaded file
        foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
            $file_name = $_FILES['images']['name'][$key];
            $file_error = $_FILES['images']['error'][$key];
            $file_size = $_FILES['images']['size'][$key];
            
            log_debug("Processing file $key: name=$file_name, error=$file_error, size=$file_size, tmp_name=$tmp_name");
            
            if ($file_error === UPLOAD_ERR_OK && !empty($tmp_name)) {
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                $new_file_name = $report_id . '_' . $key . '_' . time() . '.' . $file_ext;
                $target_path = $upload_dir . $new_file_name;
                
                log_debug("Moving file from $tmp_name to $target_path");
                log_debug("File exists before move: " . (file_exists($tmp_name) ? 'YES' : 'NO'));
                
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
                    log_debug("PHP upload error: " . error_get_last()['message'] ?? 'unknown');
                }
            } else {
                log_debug("Upload error for file $key: error=$file_error");
            }
        }
    } else {
        log_debug("No images array found in FILES");
        log_debug("FILES keys: " . implode(', ', array_keys($_FILES)));
    }

    // Convert to JSON for database
    $images_json = json_encode($uploaded_images);
    log_debug("Images JSON for database: $images_json");
    log_debug("Final uploaded_images array: " . print_r($uploaded_images, true));

    // Insert into database
    log_debug("Preparing database insert...");
    $stmt = $conn->prepare("INSERT INTO damage_reports (report_id, reporter_id, location, barangay, damage_type, description, severity, estimated_size, traffic_impact, contact_number, anonymous_report, images, created_at, reported_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    
    if (!$stmt) {
        log_debug("Failed to prepare statement: " . $conn->error);
        sendResponse(false, 'Database prepare error: ' . $conn->error);
    }
    
    $stmt->bind_param("sisssssssisi", $report_id, $user_id, $location, $barangay, $damage_type, $description, $severity, $estimated_size, $traffic_impact, $contact_number, $anonymous_report, $images_json);

    log_debug("Executing database insert...");
    if ($stmt->execute()) {
        log_debug("Database insert successful for report: $report_id");
        
        // Verify the insert
        log_debug("Verifying database insert...");
        $verify_stmt = $conn->prepare("SELECT images FROM damage_reports WHERE report_id = ?");
        $verify_stmt->bind_param('s', $report_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        $verify_row = $verify_result->fetch_assoc();
        
        log_debug("Verification - Images in DB: " . $verify_row['images']);
        
        sendResponse(true, 'Test report submitted successfully! Images uploaded: ' . count($uploaded_images), [
            'report_id' => $report_id,
            'uploaded_images' => $uploaded_images,
            'images_json' => $images_json,
            'db_images' => $verify_row['images'],
            'test_mode' => true
        ]);
    } else {
        log_debug("Database insert failed: " . $stmt->error);
        sendResponse(false, 'Database error: ' . $stmt->error);
    }

} catch (Exception $e) {
    sendResponse(false, 'System error: ' . $e->getMessage());
}
?>
