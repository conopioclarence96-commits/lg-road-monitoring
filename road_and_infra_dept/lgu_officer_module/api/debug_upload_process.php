<?php
// debug_upload_process.php - Complete debug of the upload process
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

function log_debug($message) {
    file_put_contents('debug_upload_process.log', date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND);
}

log_debug("=== DEBUG UPLOAD PROCESS START ===");

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Show debug form
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Debug Upload Process</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .section { border: 1px solid #ddd; padding: 20px; margin: 20px 0; border-radius: 8px; }
            .result { background: #f8f9fa; padding: 15px; margin: 10px 0; border-radius: 5px; }
            .success { background: #d4edda; color: #155724; }
            .error { background: #f8d7da; color: #721c24; }
            input, button { padding: 10px; margin: 5px; }
            button { background: #dc3545; color: white; border: none; cursor: pointer; }
            .debug { background: #f1f1f1; padding: 10px; font-family: monospace; font-size: 12px; white-space: pre-wrap; }
        </style>
    </head>
    <body>
        <h1>🔍 Debug Upload Process</h1>
        
        <div class="section">
            <h2>📝 Test Upload with Full Debug</h2>
            <form method="POST" enctype="multipart/form-data">
                <div>
                    <label>Location:</label>
                    <input type="text" name="location" value="Debug Test Location" required>
                </div>
                <div>
                    <label>Barangay:</label>
                    <select name="barangay" required>
                        <option value="Poblacion">Poblacion</option>
                    </select>
                </div>
                <div>
                    <label>Damage Type:</label>
                    <select name="damage_type" required>
                        <option value="pothole">Pothole</option>
                    </select>
                </div>
                <div>
                    <label>Description:</label>
                    <input type="text" name="description" value="Debug upload test" required>
                </div>
                <div>
                    <label><strong>Upload Image:</strong></label>
                    <input type="file" name="images[]" accept="image/*" required>
                </div>
                <div>
                    <label>
                        <input type="checkbox" name="anonymous_report" value="1" checked>
                        Submit anonymously
                    </label>
                </div>
                <button type="submit">🔍 Debug Upload</button>
            </form>
        </div>
        
        <div class="section">
            <h2>📋 Debug Log</h2>
            <button onclick="checkDebugLog()">📄 View Debug Log</button>
            <button onclick="clearDebugLog()">🗑️ Clear Debug Log</button>
            <div id="debugLog" class="debug" style="display: none;"></div>
        </div>
        
        <div class="section">
            <h2>🗃️ Check Database Directly</h2>
            <button onclick="checkDatabase()">🔍 Check Latest Reports</button>
            <div id="dbResults"></div>
        </div>

        <script>
            async function checkDebugLog() {
                try {
                    const response = await fetch('debug_upload_process.log');
                    if (response.ok) {
                        const logText = await response.text();
                        document.getElementById('debugLog').style.display = 'block';
                        document.getElementById('debugLog').textContent = logText;
                    } else {
                        document.getElementById('debugLog').style.display = 'block';
                        document.getElementById('debugLog').textContent = 'Debug log not found';
                    }
                } catch(error) {
                    document.getElementById('debugLog').style.display = 'block';
                    document.getElementById('debugLog').textContent = 'Error: ' + error.message;
                }
            }

            async function clearDebugLog() {
                try {
                    await fetch('', { method: 'POST', body: 'action=clear_log' });
                    document.getElementById('debugLog').style.display = 'none';
                    document.getElementById('debugLog').textContent = '';
                } catch(error) {
                    console.error('Error clearing log:', error);
                }
            }

            async function checkDatabase() {
                const dbResults = document.getElementById('dbResults');
                dbResults.innerHTML = '<div class="result">🔄 Checking database...</div>';
                
                try {
                    const response = await fetch('road_and_infra_dept/lgu_officer_module/api/get_citizen_reports_test.php');
                    const data = await response.json();
                    
                    if (data.success) {
                        let html = '<h3>📊 Latest Reports in Database:</h3>';
                        data.data.reports.forEach((report, index) => {
                            html += `<div style="border: 1px solid #ddd; padding: 10px; margin: 10px;">`;
                            html += `<strong>Report ${index + 1}:</strong> ${report.report_id}<br>`;
                            html += `<strong>Images:</strong> <code>${report.images}</code><br>`;
                            html += `<strong>Location:</strong> ${report.location}<br>`;
                            html += `<strong>Created:</strong> ${report.created_at_formatted}`;
                            html += `</div>`;
                        });
                        dbResults.innerHTML = html;
                    } else {
                        dbResults.innerHTML = '<div class="result error">❌ Error: ' + data.message + '</div>';
                    }
                } catch(error) {
                    dbResults.innerHTML = '<div class="result error">❌ Error: ' + error.message + '</div>';
                }
            }
        </script>
    </body>
    </html>
    <?php
    exit;
}

// Handle POST request
log_debug("POST request received");
log_debug("POST data: " . print_r($_POST, true));
log_debug("FILES data: " . print_r($_FILES, true));

if (isset($_POST['action']) && $_POST['action'] === 'clear_log') {
    if (file_exists('debug_upload_process.log')) {
        unlink('debug_upload_process.log');
    }
    echo '{"success": true, "message": "Debug log cleared"}';
    exit;
}

// Use the same logic as handle_report_test.php
try {
    require_once '../../config/database.php';
    $database = new Database();
    $conn = $database->getConnection();
    
    log_debug("Database connection successful");

    $location = $_POST['location'] ?? '';
    $barangay = $_POST['barangay'] ?? '';
    $damage_type = $_POST['damage_type'] ?? '';
    $description = $_POST['description'] ?? '';
    $anonymous_report = isset($_POST['anonymous_report']) ? 1 : 0;
    
    log_debug("Form data validated");

    // Handle user ID
    if ($anonymous_report) {
        $check_user = $conn->prepare("SELECT id FROM users ORDER BY id LIMIT 1");
        $check_user->execute();
        $user_result = $check_user->get_result();
        
        if ($user_result->num_rows > 0) {
            $user_row = $user_result->fetch_assoc();
            $user_id = $user_row['id'];
        } else {
            $insert_user = $conn->prepare("INSERT INTO users (username, email, first_name, last_name, password, role, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $test_username = 'debug_user_' . time();
            $test_email = 'debug@example.com';
            $test_password = password_hash('debug123', PASSWORD_DEFAULT);
            $test_role = 'citizen';
            
            $insert_user->bind_param('ssssss', $test_username, $test_email, 'Debug', 'User', $test_password, $test_role);
            $insert_user->execute();
            $user_id = $conn->insert_id;
        }
    } else {
        $check_user = $conn->prepare("SELECT id FROM users ORDER BY id LIMIT 1");
        $check_user->execute();
        $user_result = $check_user->get_result();
        
        if ($user_result->num_rows > 0) {
            $user_row = $user_result->fetch_assoc();
            $user_id = $user_row['id'];
        } else {
            log_debug("No users found in database");
            echo json_encode(['success' => false, 'message' => 'No users found in database']);
            exit;
        }
    }
    
    log_debug("User ID determined: $user_id");

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
    
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
        log_debug("Created upload directory");
    }

    if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
        log_debug("Processing image uploads...");
        
        foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
            $file_name = $_FILES['images']['name'][$key];
            $file_error = $_FILES['images']['error'][$key];
            
            log_debug("Processing file $key: name=$file_name, error=$file_error");
            
            if ($file_error === UPLOAD_ERR_OK && !empty($tmp_name)) {
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                $new_file_name = $report_id . '_' . $key . '_' . time() . '.' . $file_ext;
                $target_path = $upload_dir . $new_file_name;
                
                log_debug("Moving file from $tmp_name to $target_path");
                
                if (move_uploaded_file($tmp_name, $target_path)) {
                    $uploaded_images[] = $new_file_name;
                    log_debug("Successfully uploaded: $new_file_name");
                    
                    if (file_exists($target_path)) {
                        log_debug("File verified: $target_path (" . filesize($target_path) . " bytes)");
                    } else {
                        log_debug("WARNING: File not found after upload: $target_path");
                    }
                } else {
                    log_debug("Failed to upload: $file_name");
                }
            }
        }
    } else {
        log_debug("No images found in FILES array");
    }

    log_debug("Final uploaded_images: " . print_r($uploaded_images, true));

    // Convert to JSON
    $images_json = json_encode($uploaded_images);
    log_debug("Images JSON: $images_json");

    // Insert into database
    $stmt = $conn->prepare("INSERT INTO damage_reports (report_id, reporter_id, location, barangay, damage_type, description, severity, estimated_size, traffic_impact, contact_number, anonymous_report, images, created_at, reported_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $severity = 'medium';
    $estimated_size = '';
    $traffic_impact = 'moderate';
    $contact_number = '';
    
    $stmt->bind_param("sisssssssisi", $report_id, $user_id, $location, $barangay, $damage_type, $description, $severity, $estimated_size, $traffic_impact, $contact_number, $anonymous_report, $images_json);

    if ($stmt->execute()) {
        log_debug("Database insert successful: $report_id");
        
        // Verify the insert
        $verify_stmt = $conn->prepare("SELECT images FROM damage_reports WHERE report_id = ?");
        $verify_stmt->bind_param('s', $report_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        $verify_row = $verify_result->fetch_assoc();
        
        log_debug("Verification - Images in DB: " . $verify_row['images']);
        
        echo json_encode([
            'success' => true,
            'message' => 'Debug upload completed!',
            'report_id' => $report_id,
            'uploaded_images' => $uploaded_images,
            'images_json' => $images_json,
            'db_images' => $verify_row['images']
        ]);
    } else {
        log_debug("Database insert failed: " . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }

} catch (Exception $e) {
    log_debug("Exception: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
}

log_debug("=== DEBUG UPLOAD PROCESS END ===");
?>
