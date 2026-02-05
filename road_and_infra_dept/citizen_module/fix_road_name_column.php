<?php
// Script to add road_name column to damage_reports table
require_once '../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Check if column already exists
    $check_column = $conn->query("SHOW COLUMNS FROM damage_reports LIKE 'road_name'");
    
    if ($check_column->num_rows == 0) {
        // Add the missing column
        $alter_sql = "ALTER TABLE damage_reports ADD COLUMN road_name VARCHAR(255) NOT NULL AFTER id";
        
        if ($conn->query($alter_sql)) {
            echo "Success: road_name column added to damage_reports table.\n";
        } else {
            echo "Error adding column: " . $conn->error . "\n";
        }
    } else {
        echo "Info: road_name column already exists in damage_reports table.\n";
    }
    
} catch (Exception $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?>
