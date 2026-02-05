<?php
/**
 * Database Setup Helper for GIS Mapping
 * This script helps test and set up the database connection
 */

echo "<h2>Database Connection Test & Setup</h2>";

// Test database connection
try {
    require_once '../config/database.php';
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "<p style='color: green;'>✅ Database connection successful!</p>";
    
    // Check if damage_reports table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'damage_reports'");
    if ($table_check && $table_check->num_rows > 0) {
        echo "<p style='color: green;'>✅ damage_reports table exists!</p>";
        
        // Show table structure
        $describe = $conn->query("DESCRIBE damage_reports");
        echo "<h3>damage_reports table structure:</h3>";
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr style='background: #f0f0f0;'><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
        
        while ($row = $describe->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Check for sample data
        $count = $conn->query("SELECT COUNT(*) as count FROM damage_reports");
        $row = $count->fetch_assoc();
        echo "<p>📊 Found " . $row['count'] . " damage reports in database.</p>";
        
        if ($row['count'] == 0) {
            echo "<p style='color: orange;'>⚠️ No damage reports found. You may want to add some sample data.</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ damage_reports table not found!</p>";
        echo "<p>Please run the setup script to create the necessary tables.</p>";
    }
    
    // Instructions for switching to production API
    echo "<div style='background: #e3f2fd; padding: 15px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3>🔧 To Switch to Production Data:</h3>";
    echo "<ol>";
    echo "<li>Ensure your MySQL/MariaDB server is running</li>";
    echo "<li>Verify database credentials in config/database.local.php</li>";
    echo "<li>Run the GIS setup script: citizen_module/setup_gis_database.sql</li>";
    echo "<li>In gis_mapping_dashboard.php, change 'get_gis_data_test.php' to 'get_gis_data.php'</li>";
    echo "</ol>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Database connection failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    
    echo "<div style='background: #fff3cd; padding: 15px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3>🛠️ Troubleshooting Steps:</h3>";
    echo "<ol>";
    echo "<li>Start your MySQL/MariaDB server (XAMPP, WAMP, MAMP, etc.)</li>";
    echo "<li>Check database credentials in config/database.local.php</li>";
    echo "<li>Ensure database 'rgmap_road_infra' exists</li>";
    echo "<li>Verify user 'rgmap_root' has proper permissions</li>";
    echo "<li>Check if port 3306 is available</li>";
    echo "</ol>";
    echo "</div>";
}

echo "<p><strong>Current Status:</strong> Using sample data (test mode)</p>";
echo "<p><strong>Next Step:</strong> Set up database connection to use real data</p>";
?>
