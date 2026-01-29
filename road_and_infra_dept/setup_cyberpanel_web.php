<!DOCTYPE html>
<html>
<head>
    <title>CyberPanel Database Setup</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .info { color: blue; }
        .warning { color: orange; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; overflow-x: auto; }
        .btn { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; margin: 10px 5px; }
        .btn:hover { background: #0056b3; }
        .btn-danger { background: #dc3545; }
        .btn-danger:hover { background: #c82333; }
    </style>
</head>
<body>
    <h1>🔧 CyberPanel Database Setup</h1>
    
    <?php
    $message = '';
    $messageType = '';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        
        try {
            // Test CyberPanel connection
            $conn = new mysqli('localhost', 'root', 'root123456', 'road_infra');
            
            if ($conn->connect_error) {
                $message = "❌ CyberPanel connection failed: " . $conn->connect_error;
                $messageType = 'error';
            } else {
                $message = "✅ CyberPanel connection successful!";
                $messageType = 'success';
                
                if ($action === 'setup') {
                    // Check if database has tables
                    $result = $conn->query("SHOW TABLES");
                    if ($result->num_rows > 0) {
                        $message = "⚠️ Database already has tables. Setup skipped.";
                        $messageType = 'warning';
                    } else {
                        // Import SQL setup file
                        $sqlFile = __DIR__ . '/setup/combined_database_setup.sql';
                        if (file_exists($sqlFile)) {
                            $sql = file_get_contents($sqlFile);
                            $statements = array_filter(array_map('trim', explode(';', $sql)));
                            
                            $executed = 0;
                            $errors = 0;
                            
                            foreach ($statements as $statement) {
                                if (!empty($statement) && !preg_match('/^--/', $statement)) {
                                    if ($conn->query($statement)) {
                                        $executed++;
                                    } else {
                                        $errors++;
                                    }
                                }
                            }
                            
                            $message = "🎉 Database setup completed! Executed: $executed statements, Errors: $errors";
                            $messageType = $errors > 0 ? 'warning' : 'success';
                        } else {
                            $message = "❌ SQL setup file not found";
                            $messageType = 'error';
                        }
                    }
                } elseif ($action === 'check') {
                    // Show database status
                    $result = $conn->query("SHOW TABLES");
                    $tables = [];
                    while ($row = $result->fetch_row()) {
                        $tables[] = $row[0];
                    }
                    
                    $userResult = $conn->query("SELECT COUNT(*) as count FROM users");
                    $userRow = $userResult->fetch_assoc();
                    
                    $message = "📊 Database Status: " . count($tables) . " tables, " . $userRow['count'] . " users";
                    $messageType = 'info';
                }
            }
            $conn->close();
            
        } catch (Exception $e) {
            $message = "❌ Error: " . $e->getMessage();
            $messageType = 'error';
        }
    }
    ?>
    
    <?php if ($message): ?>
        <div class="<?php echo $messageType; ?>" style="padding: 15px; margin: 20px 0; border-radius: 5px; background: #f8f9fa; border: 1px solid #dee2e6;">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>
    
    <div class="info">
        <h2>📋 Current Configuration:</h2>
        <pre>
Host: localhost
Username: root
Password: root123456
Database: road_infra
        </pre>
    </div>
    
    <form method="POST">
        <button type="submit" name="action" value="check" class="btn">🔍 Check Database Status</button>
        <button type="submit" name="action" value="setup" class="btn">🚀 Setup Database (Import SQL)</button>
    </form>
    
    <div class="info">
        <h2>📝 Instructions:</h2>
        <ol>
            <li>Click "Check Database Status" to see if the database is accessible</li>
            <li>If the database is empty, click "Setup Database" to import the SQL structure</li>
            <li>Once setup is complete, your application should work with CyberPanel</li>
        </ol>
        
        <h3>🔧 If Connection Fails:</h3>
        <ul>
            <li>Make sure CyberPanel MySQL service is running</li>
            <li>Verify the database 'road_infra' exists in CyberPanel</li>
            <li>Check if the password 'root123456' is correct in CyberPanel</li>
            <li>Ensure CyberPanel allows localhost connections</li>
        </ul>
    </div>
    
    <hr>
    <p><small>This page helps set up your CyberPanel database for the LGU Road Monitoring system.</small></p>
</body>
</html>
