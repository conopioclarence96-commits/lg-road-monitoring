<?php
// handle_report_test.php - Test version without authentication
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Skip session and auth for testing
// session_start();
// require_once '../../config/auth.php';
// require_once '../../config/database.php';

header('Content-Type: application/json');

function sendResponse($success, $message, $extra = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

// Bypass authentication for testing
// if (!$auth->isLoggedIn()) {
//     sendResponse(false, 'Session expired. Please login again.');
// }

try {
    // Use the domain's database configuration
    require_once '../../config/database.php';
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
    
    // Check if images were uploaded
    if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
        $upload_dir = '../../uploads/reports/';
        
        // Create directory if it doesn't exist
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

    // Convert to JSON for database
    $images_json = json_encode($uploaded_images);

    // Insert into database
    $stmt = $conn->prepare("INSERT INTO damage_reports (report_id, reporter_id, location, barangay, damage_type, description, severity, estimated_size, traffic_impact, contact_number, anonymous_report, images, created_at, reported_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $stmt->bind_param("sisssssssisi", $report_id, $user_id, $location, $barangay, $damage_type, $description, $severity, $estimated_size, $traffic_impact, $contact_number, $anonymous_report, $images_json);

    if ($stmt->execute()) {
        sendResponse(true, 'Test report submitted successfully! Images uploaded: ' . count($uploaded_images), [
            'report_id' => $report_id,
            'uploaded_images' => $uploaded_images,
            'test_mode' => true
        ]);
    } else {
        sendResponse(false, 'Database error: ' . $conn->error);
    }

} catch (Exception $e) {
    sendResponse(false, 'System error: ' . $e->getMessage());
}
?>
