<?php
// Test script to check image display functionality
require_once 'config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();

    // Get reports with images
    $stmt = $conn->prepare("SELECT report_id, images FROM damage_reports WHERE images IS NOT NULL AND images != 'null' AND images != ''");
    $stmt->execute();
    $result = $stmt->get_result();

    echo "<h2>Reports with Images:</h2>";
    
    while ($row = $result->fetch_assoc()) {
        echo "<h3>Report ID: " . htmlspecialchars($row['report_id']) . "</h3>";
        echo "<pre>";
        print_r(json_decode($row['images'], true));
        echo "</pre>";
        echo "<hr>";
    }

    // Check if uploads/reports directory exists and has files
    $reports_dir = 'uploads/reports';
    if (is_dir($reports_dir)) {
        echo "<h2>Files in uploads/reports/:</h2>";
        $files = scandir($reports_dir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                echo "- " . htmlspecialchars($file) . "<br>";
            }
        }
    } else {
        echo "<h2>uploads/reports directory does not exist!</h2>";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
