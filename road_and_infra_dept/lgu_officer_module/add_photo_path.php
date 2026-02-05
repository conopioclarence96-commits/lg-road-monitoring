<?php
// Script to add photo_path column to damage_reports table
require_once '../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Check if photo_path column already exists
    $check_column = $conn->query("SHOW COLUMNS FROM damage_reports LIKE 'photo_path'");
    
    if ($check_column->num_rows == 0) {
        // Add the photo_path column
        $alter_sql = "ALTER TABLE damage_reports ADD COLUMN photo_path VARCHAR(500) NULL AFTER status";
        
        if ($conn->query($alter_sql)) {
            echo "Success: photo_path column added to damage_reports table.\n";
            
            // Add index for better performance
            $index_sql = "CREATE INDEX idx_damage_reports_photo_path ON damage_reports(photo_path)";
            $conn->query($index_sql);
            echo "Success: Index added for photo_path column.\n";
        } else {
            echo "Error adding photo_path column: " . $conn->error . "\n";
        }
    } else {
        echo "Info: photo_path column already exists in damage_reports table.\n";
    }
    
    // Show table structure
    echo "\nCurrent damage_reports table structure:\n";
    $result = $conn->query("DESCRIBE damage_reports");
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
    
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?>
