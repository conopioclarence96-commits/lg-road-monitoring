<!DOCTYPE html>
<html>
<head>
    <title>Database Connection Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>Database Connection Test</h1>
    
    <?php
    echo "<h2 class='info'>🔍 Checking Configuration...</h2>";
    
    // Check environment variables
    echo "<h3>Environment Variables:</h3>";
    echo "<pre>";
    echo "DB_HOST: '" . (getenv('DB_HOST') ?: 'NOT SET') . "'\n";
    echo "DB_USER: '" . (getenv('DB_USER') ?: 'NOT SET') . "'\n";
    echo "DB_PASS: '" . (getenv('DB_PASS') ?: 'NOT SET') . "'\n";
    echo "DB_NAME: '" . (getenv('DB_NAME') ?: 'NOT SET') . "'\n";
    echo "</pre>";
    
    // Check local config file
    $localConfigFile = __DIR__ . '/config/database.local.php';
    echo "<h3>Local Config File:</h3>";
    echo "<pre>";
    echo "File exists: " . (file_exists($localConfigFile) ? 'YES' : 'NO') . "\n";
    echo "File path: " . $localConfigFile . "\n";
    
    if (file_exists($localConfigFile)) {
        $localConfig = include $localConfigFile;
        echo "Config loaded: " . (is_array($localConfig) ? 'YES' : 'NO') . "\n";
        if (is_array($localConfig)) {
            echo "Local config values:\n";
            echo "  host: '" . ($localConfig['host'] ?? 'NOT SET') . "'\n";
            echo "  username: '" . ($localConfig['username'] ?? 'NOT SET') . "'\n";
            echo "  password: '" . ($localConfig['password'] ?? 'NOT SET') . "'\n";
            echo "  database: '" . ($localConfig['database'] ?? 'NOT SET') . "'\n";
        }
    }
    echo "</pre>";
    
    // Test database connection
    echo "<h2 class='info'>🔧 Testing Database Connection...</h2>";
    try {
        require_once 'config/database.php';
        $database = new Database();
        $conn = $database->getConnection();
        echo "<p class='success'>✅ Connection successful!</p>";
        
        // Test query
        $result = $conn->query("SELECT COUNT(*) as count FROM users");
        $row = $result->fetch_assoc();
        echo "<p class='success'>📊 Found " . $row['count'] . " users in database.</p>";
        
    } catch (Exception $e) {
        echo "<p class='error'>❌ Connection failed: " . $e->getMessage() . "</p>";
    }
    ?>
    
    <h2>📝 Next Steps:</h2>
    <p>If you see a connection error above, the database credentials need to be updated in:</p>
    <p><code><?php echo __DIR__; ?>/config/database.local.php</code></p>
    
    <hr>
    <p><small>This test page helps diagnose database connection issues.</small></p>
</body>
</html>
