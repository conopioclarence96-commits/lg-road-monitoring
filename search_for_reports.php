<?php
require_once 'lgu_staff/includes/config.php';

if (!$conn) {
    die("Database connection failed");
}

echo "<h2>Searching for Report Data with Images</h2>";

// Check all tables for any data that might contain image references
$tables = ['road_transportation_reports', 'road_maintenance_reports', 'infrastructure_projects'];

foreach ($tables as $table) {
    echo "<h3>Checking table: $table</h3>";
    
    // Get all columns
    $stmt = $conn->prepare("DESCRIBE `$table`");
    $stmt->execute();
    $result = $stmt->get_result();
    
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
    $stmt->close();
    
    // Try to get some data
    try {
        $stmt = $conn->prepare("SELECT * FROM `$table` LIMIT 3");
        $stmt->execute();
        $result = $stmt->get_result();
        
        $count = 0;
        while ($row = $result->fetch_assoc()) {
            $count++;
            echo "<h4>Record $count:</h4>";
            echo "<pre>" . json_encode($row, JSON_PRETTY_PRINT) . "</pre>";
            
            // Check for any field that might contain image data
            foreach ($row as $key => $value) {
                if ($value && (stripos($value, '.jpg') !== false || stripos($value, '.jpeg') !== false || stripos($value, '.png') !== false)) {
                    echo "<p><strong>Found image reference in '$key':</strong> $value</p>";
                }
            }
        }
        
        if ($count == 0) {
            echo "<p>No data found in this table.</p>";
        }
        
        $stmt->close();
    } catch (Exception $e) {
        echo "<p>Error querying table: " . $e->getMessage() . "</p>";
    }
    
    echo "<hr>";
}

$conn->close();
?>
