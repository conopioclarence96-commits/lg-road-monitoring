<?php
// Simple test to check if we can connect to database and see recent reports
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Use the same database configuration as the main app
    require_once 'road_and_infra_dept/config/database.php';
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "<h2>✅ Database Connection Successful</h2>";
    
    // Check recent reports
    $stmt = $conn->prepare("SELECT report_id, location, images, created_at FROM damage_reports ORDER BY created_at DESC LIMIT 5");
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo "<h3>📋 Recent Reports (Last 5):</h3>";
    
    if ($result->num_rows === 0) {
        echo "<p style='color: orange;'>No reports found in database.</p>";
    } else {
        while ($row = $result->fetch_assoc()) {
            echo "<div style='border: 1px solid #ddd; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
            echo "<strong>📄 Report ID:</strong> " . htmlspecialchars($row['report_id']) . "<br>";
            echo "<strong>📍 Location:</strong> " . htmlspecialchars($row['location']) . "<br>";
            echo "<strong>📅 Created:</strong> " . htmlspecialchars($row['created_at']) . "<br>";
            echo "<strong>🖼️ Images (Raw DB Value):</strong> <code style='background: #f0f0f0; padding: 2px 5px;'>" . htmlspecialchars($row['images']) . "</code><br>";
            
            $images = json_decode($row['images'], true);
            echo "<strong>📊 Images (Decoded):</strong> ";
            if ($images && is_array($images) && count($images) > 0) {
                echo "<span style='color: green;'>✅ Found " . count($images) . " images:</span> " . implode(", ", $images);
            } else {
                echo "<span style='color: red;'>❌ No images or empty array</span>";
            }
            echo "</div>";
        }
    }
    
    // Check files in uploads directory
    echo "<h3>📁 Files in Upload Directory:</h3>";
    $reports_dir = 'road_and_infra_dept/uploads/reports';
    if (is_dir($reports_dir)) {
        $files = scandir($reports_dir);
        $image_files = array_filter($files, function($file) {
            return $file !== '.' && $file !== '..' && !is_dir($reports_dir . '/' . $file);
        });
        
        if (empty($image_files)) {
            echo "<p style='color: orange;'>No image files found in upload directory.</p>";
        } else {
            echo "<div style='background: #f0f0f0; padding: 10px; border-radius: 5px;'>";
            foreach ($image_files as $file) {
                $filepath = $reports_dir . '/' . $file;
                $filesize = filesize($filepath);
                echo "- <code>" . htmlspecialchars($file) . "</code> (" . number_format($filesize) . " bytes)<br>";
            }
            echo "</div>";
        }
    } else {
        echo "<p style='color: red;'>❌ Upload directory does not exist: " . htmlspecialchars($reports_dir) . "</p>";
    }
    
    // Check directory permissions
    echo "<h3>🔒 Directory Permissions:</h3>";
    if (is_dir($reports_dir)) {
        echo "Directory exists: ✅<br>";
        echo "Readable: " . (is_readable($reports_dir) ? "✅" : "❌") . "<br>";
        echo "Writable: " . (is_writable($reports_dir) ? "✅" : "❌") . "<br>";
    }
    
} catch (Exception $e) {
    echo "<h2>❌ Database Connection Failed</h2>";
    echo "<p style='color: red;'><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Solution:</strong> Check your database credentials in road_and_infra_dept/config/database.local.php</p>";
}
?>
