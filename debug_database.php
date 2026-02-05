<?php
// Debug script to check what's in the database
require_once 'road_and_infra_dept/config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "<h2>Database Debug - Reports with Images</h2>";
    
    // Check all reports
    $stmt = $conn->prepare("SELECT report_id, images, created_at FROM damage_reports ORDER BY created_at DESC LIMIT 10");
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo "<h3>Last 10 Reports:</h3>";
    while ($row = $result->fetch_assoc()) {
        echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px;'>";
        echo "<strong>Report ID:</strong> " . htmlspecialchars($row['report_id']) . "<br>";
        echo "<strong>Created:</strong> " . htmlspecialchars($row['created_at']) . "<br>";
        echo "<strong>Images (raw):</strong> " . htmlspecialchars($row['images']) . "<br>";
        
        $images = json_decode($row['images'], true);
        echo "<strong>Images (decoded):</strong> ";
        if ($images && is_array($images) && count($images) > 0) {
            echo "Found " . count($images) . " images: " . implode(", ", $images);
        } else {
            echo "No images or empty array";
        }
        echo "</div>";
    }
    
    // Check files in uploads directory
    echo "<h3>Files in uploads/reports/:</h3>";
    $reports_dir = 'road_and_infra_dept/uploads/reports';
    if (is_dir($reports_dir)) {
        $files = scandir($reports_dir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                echo "- " . htmlspecialchars($file) . "<br>";
            }
        }
    } else {
        echo "Directory does not exist: " . htmlspecialchars($reports_dir);
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
