<!DOCTYPE html>
<html>
<head>
    <title>CyberPanel Diagnosis</title>
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
    <h1>🔍 CyberPanel Database Diagnosis</h1>
    
    <div class="section">
        <h2>📋 Testing Different Configurations</h2>
        
        <?php
        $configs = [
            ['host' => 'localhost', 'port' => 3306, 'user' => 'root', 'pass' => 'root123456', 'db' => 'road_infra', 'name' => 'CyberPanel Default'],
            ['host' => 'localhost', 'port' => 3306, 'user' => 'root', 'pass' => '', 'db' => 'road_infra', 'name' => 'Empty Password'],
            ['host' => 'localhost', 'port' => 3306, 'user' => 'root', 'pass' => 'root', 'db' => 'road_infra', 'name' => 'Password: root'],
            ['host' => 'localhost', 'port' => 3306, 'user' => 'root', 'pass' => '', 'db' => 'rgmap_gu_road_infra', 'name' => 'Original Working DB'],
            ['host' => '127.0.0.1', 'port' => 3306, 'user' => 'root', 'pass' => 'root123456', 'db' => 'road_infra', 'name' => '127.0.0.1 instead of localhost'],
        ];
        
        $workingConfig = null;
        
        foreach ($configs as $config) {
            echo "<h3>Testing: " . $config['name'] . "</h3>";
            echo "<pre>";
            echo "Host: " . $config['host'] . "\n";
            echo "Port: " . $config['port'] . "\n";
            echo "User: " . $config['user'] . "\n";
            echo "Pass: '" . $config['pass'] . "'\n";
            echo "DB: " . $config['db'] . "\n";
            echo "</pre>";
            
            try {
                $conn = new mysqli($config['host'], $config['user'], $config['pass'], $config['db'], $config['port']);
                
                if ($conn->connect_error) {
                    echo "<p class='error'>❌ Failed: " . $conn->connect_error . "</p>";
                } else {
                    echo "<p class='success'>✅ SUCCESS! This configuration works!</p>";
                    
                    // Test a query
                    $result = $conn->query("SELECT COUNT(*) as count FROM users");
                    $row = $result->fetch_assoc();
                    echo "<p class='info'>📊 Found " . $row['count'] . " users</p>";
                    
                    $workingConfig = $config;
                    $conn->close();
                    break;
                }
            } catch (Exception $e) {
                echo "<p class='error'>❌ Exception: " . $e->getMessage() . "</p>";
            }
            
            echo "<hr>";
        }
        
        if ($workingConfig) {
            echo "<div class='section success'>";
            echo "<h2>🎉 WORKING CONFIGURATION FOUND!</h2>";
            echo "<pre>";
            echo "return [\n";
            echo "    'host' => '" . $workingConfig['host'] . "',\n";
            echo "    'username' => '" . $workingConfig['user'] . "',\n";
            echo "    'password' => '" . addslashes($workingConfig['pass']) . "',\n";
            echo "    'database' => '" . $workingConfig['db'] . "'\n";
            echo "];\n";
            echo "</pre>";
            echo "<p><strong>Copy this configuration into your database.local.php file!</strong></p>";
            echo "</div>";
        } else {
            echo "<div class='section error'>";
            echo "<h2>❌ No Working Configuration Found</h2>";
            echo "<p>Possible issues:</p>";
            echo "<ul>";
            echo "<li>CyberPanel MySQL is not running</li>";
            echo "<li>Wrong password in CyberPanel</li>";
            echo "<li>Database 'road_infra' doesn't exist</li>";
            echo "<li>Firewall blocking connections</li>";
            echo "<li>CyberPanel MySQL on different port</li>";
            echo "</ul>";
            echo "</div>";
        }
        ?>
    </div>
    
    <div class="section">
        <h2>🔧 Manual Steps to Fix</h2>
        <ol>
            <li><strong>Check CyberPanel Dashboard:</strong> Go to CyberPanel → Database → List Databases</li>
            <li><strong>Verify Database Exists:</strong> Make sure 'road_infra' database is listed</li>
            <li><strong>Check Password:</strong> Note the exact password for the root user</li>
            <li><strong>Test in phpMyAdmin:</strong> Try connecting with the same credentials</li>
            <li><strong>Update Config:</strong> Use the working configuration from above</li>
        </ol>
    </div>
</body>
</html>
