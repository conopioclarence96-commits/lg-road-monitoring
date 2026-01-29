<!DOCTYPE html>
<html>
<head>
    <title>Domain Database Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .info { color: blue; }
        .warning { color: orange; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>🌐 Domain Database Connection Test</h1>
    
    <div class="section">
        <h2>📋 Current Configuration</h2>
        <pre>
Host: 127.0.0.1
Username: root
Password: root123456
Database: road_infra
        </pre>
    </div>
    
    <div class="section">
        <h2>🔧 Testing Connection...</h2>
        
        <?php
        try {
            $conn = new mysqli('127.0.0.1', 'root', 'root123456', 'road_infra');
            
            if ($conn->connect_error) {
                echo "<p class='error'>❌ Connection failed: " . $conn->connect_error . "</p>";
                echo "<h3>🔍 Troubleshooting Steps:</h3>";
                echo "<ol>";
                echo "<li>Make sure CyberPanel MySQL service is running</li>";
                echo "<li>Verify database 'road_infra' exists in CyberPanel</li>";
                echo "<li>Check if password 'root123456' is correct</li>";
                echo "<li>Ensure CyberPanel allows connections from 127.0.0.1</li>";
                echo "<li>Import the SQL setup file if database is empty</li>";
                echo "</ol>";
            } else {
                echo "<p class='success'>✅ Connection successful!</p>";
                
                // Check tables
                $result = $conn->query("SHOW TABLES");
                if ($result->num_rows > 0) {
                    echo "<h3>📋 Database Tables:</h3>";
                    echo "<ul>";
                    while ($row = $result->fetch_row()) {
                        echo "<li>" . $row[0] . "</li>";
                    }
                    echo "</ul>";
                    
                    // Check users
                    $userResult = $conn->query("SELECT COUNT(*) as count FROM users");
                    $userRow = $userResult->fetch_assoc();
                    echo "<p class='info'>📊 Found " . $userRow['count'] . " users in database</p>";
                    
                    if ($userRow['count'] == 0) {
                        echo "<p class='warning'>⚠️ Database is empty - you need to import the SQL setup file</p>";
                    }
                } else {
                    echo "<p class='warning'>⚠️ No tables found - database needs setup</p>";
                }
                
                $conn->close();
            }
        } catch (Exception $e) {
            echo "<p class='error'>❌ Exception: " . $e->getMessage() . "</p>";
        }
        ?>
    </div>
    
    <div class="section">
        <h2>📝 Next Steps</h2>
        <ol>
            <li><strong>If connection fails:</strong> Check CyberPanel MySQL configuration</li>
            <li><strong>If no tables:</strong> Import combined_database_setup.sql</li>
            <li><strong>If connection works:</strong> Test your application registration</li>
            <li><strong>Verify functionality:</strong> Check permissions page for name/role display</li>
        </ol>
    </div>
</body>
</html>
