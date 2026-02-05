<?php
// add_gis_marker.php - API for adding GIS markers
session_start();
require_once '../../config/auth.php';
require_once '../../config/database.php';

header('Content-Type: application/json');

function sendResponse($success, $message, $data = []) {
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit;
}

// Require authentication and admin privileges
if (!$auth->isLoggedIn()) {
    sendResponse(false, 'Session expired. Please login again.');
}

if (!$auth->hasRole(['admin', 'lgu_officer', 'engineer'])) {
    sendResponse(false, 'Insufficient permissions.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method.');
}

try {
    $database = new Database();
    $conn = $database->getConnection();

    // Get POST data
    $latitude = $_POST['latitude'] ?? '';
    $longitude = $_POST['longitude'] ?? '';
    $marker_type = $_POST['marker_type'] ?? '';
    $title = $_POST['title'] ?? '';
    $description = $_POST['description'] ?? '';
    $severity = $_POST['severity'] ?? 'medium';
    $address = $_POST['address'] ?? '';
    $barangay = $_POST['barangay'] ?? '';
    $related_report_id = $_POST['related_report_id'] ?? null;

    // Validate required fields
    if (empty($latitude) || empty($longitude) || empty($marker_type) || empty($title)) {
        sendResponse(false, 'Please fill in all required fields.');
    }

    // Validate coordinates
    if (!is_numeric($latitude) || !is_numeric($longitude) || 
        $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        sendResponse(false, 'Invalid coordinates.');
    }

    // Validate marker type
    $valid_types = ['damage', 'issue', 'construction', 'project', 'completed', 'infrastructure'];
    if (!in_array($marker_type, $valid_types)) {
        sendResponse(false, 'Invalid marker type.');
    }

    // Validate severity
    $valid_severities = ['low', 'medium', 'high', 'critical'];
    if (!in_array($severity, $valid_severities)) {
        sendResponse(false, 'Invalid severity level.');
    }

    // Generate marker ID
    $marker_id = "GIS-MARK-" . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);

    // Handle image uploads
    $uploaded_images = [];
    if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
        $upload_dir = '../../uploads/gis_markers/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
            $file_name = $_FILES['images']['name'][$key];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $new_file_name = $marker_id . '_' . $key . '_' . time() . '.' . $file_ext;
            $target_path = $upload_dir . $new_file_name;

            if (move_uploaded_file($tmp_name, $target_path)) {
                $uploaded_images[] = $new_file_name;
            }
        }
    }

    $images_json = json_encode($uploaded_images);

    // Additional properties
    $properties = [
        'created_by' => $_SESSION['user_id'],
        'source' => 'manual_entry'
    ];
    $properties_json = json_encode($properties);

    // Insert into gis_map_markers table
    $stmt = $conn->prepare("INSERT INTO gis_map_markers (marker_id, latitude, longitude, marker_type, title, description, severity, address, barangay, related_report_id, images, properties, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
    $stmt->bind_param("ddssssssissii", $marker_id, $latitude, $longitude, $marker_type, $title, $description, $severity, $address, $barangay, $related_report_id, $images_json, $properties_json, $_SESSION['user_id']);

    if ($stmt->execute()) {
        // Log activity
        $auth->logActivity('gis_marker_added', "Added GIS marker: $title ($marker_id)");
        
        sendResponse(true, 'GIS marker added successfully!', ['marker_id' => $marker_id]);
    } else {
        sendResponse(false, 'Database error: ' . $conn->error);
    }

} catch (Exception $e) {
    sendResponse(false, 'System error: ' . $e->getMessage());
}
?>
