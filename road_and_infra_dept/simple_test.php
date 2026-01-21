<?php
// Simple test
echo "API Test: " . date('Y-m-d H:i:s') . "<br>";

// Test if we can include the database config
try {
    require_once 'config/database.php';
    echo "Database config: " . (file_exists('config/database.php') ? "✅ Found" : "❌ Not Found") . "<br>";
    
    if (file_exists('config/database.php')) {
        echo "Database connection test: ";
        $database = new Database();
        $conn = $database->getConnection();
        echo $conn ? "✅ Connected" : "❌ Failed";
        echo "<br>";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
