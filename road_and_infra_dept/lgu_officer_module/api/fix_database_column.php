<?php
// fix_database_column.php - Fix the images column type
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "<h2>🔧 Fix Database Column Type</h2>";
    
    // Check current column type
    $check_query = "SHOW COLUMNS FROM damage_reports WHERE Field = 'images'";
    $result = $conn->query($check_query);
    $row = $result->fetch_assoc();
    
    echo "<h3>📋 Current Column Info:</h3>";
    echo "<pre>";
    print_r($row);
    echo "</pre>";
    
    // Fix the column type
    echo "<h3>🔨 Fixing Column Type...</h3>";
    
    $alter_query = "ALTER TABLE damage_reports MODIFY COLUMN images TEXT";
    
    if ($conn->query($alter_query)) {
        echo "<p style='color: green;'><strong>✅ Column type fixed successfully!</strong></p>";
        echo "<p>Images column is now TEXT type and can store JSON data.</p>";
        
        // Verify the fix
        $verify_query = "SHOW COLUMNS FROM damage_reports WHERE Field = 'images'";
        $verify_result = $conn->query($verify_query);
        $verify_row = $verify_result->fetch_assoc();
        
        echo "<h3>📋 Updated Column Info:</h3>";
        echo "<pre>";
        print_r($verify_row);
        echo "</pre>";
        
    } else {
        echo "<p style='color: red;'><strong>❌ Failed to fix column:</strong> " . $conn->error . "</p>";
    }
    
} catch (Exception $e) {
    echo "<h2>❌ Error</h2>";
    echo "<p style='color: red;'><strong>Error:</strong> " . $e->getMessage() . "</p>";
}
?>
