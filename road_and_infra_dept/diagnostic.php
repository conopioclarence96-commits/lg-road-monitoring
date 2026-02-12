<?php
// Comprehensive database diagnostic script
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>🔍 Database Diagnostic Tool</h2>";
echo "<p>This script will test multiple aspects of your database connection.</p>";

echo "<h3>1. PHP Extensions Check</h3>";
$required_extensions = ['pdo', 'pdo_mysql', 'mysqli'];
$missing_extensions = [];

foreach ($required_extensions as $ext) {
    if (!extension_loaded($ext)) {
        $missing_extensions[] = $ext;
    }
}

if (empty($missing_extensions)) {
    echo "<p style='color: green;'>✅ All required PHP extensions are loaded!</p>";
} else {
    echo "<p style='color: red;'>❌ Missing PHP extensions: " . implode(', ', $missing_extensions) . "</p>";
    echo "<p><strong>Required:</strong> " . implode(', ', $required_extensions) . "</p>";
}

echo "<h3>2. MySQL Service Check</h3>";
// Check if MySQL service is running (Linux/Unix)
if (function_exists('exec')) {
    $mysql_status = shell_exec('systemctl is-active mysql 2>/dev/null');
    if ($mysql_status) {
        echo "<p style='color: green;'>✅ MySQL service is running</p>";
    } else {
        echo "<p style='color: red;'>❌ MySQL service is not running</p>";
        echo "<p><strong>Fix:</strong> Run: <code>sudo systemctl start mysql</code></p>";
    }
} else {
    echo "<p style='color: orange;'>⚠️ Cannot check MySQL service status</p>";
}

echo "<h3>3. Network Connection Test</h3>";
$hosts_to_test = ['127.0.0.1', 'localhost', 'mysql'];
$ports_to_test = [3306, 3307];

foreach ($hosts_to_test as $host) {
    foreach ($ports_to_test as $port) {
        $timeout = 5; // seconds
        $connection = @fsockopen($host, $port, $errno, $errstr, $timeout);
        
        if ($connection) {
            fclose($connection);
            echo "<p style='color: green;'>✅ Can connect to {$host}:{$port}</p>";
        } else {
            echo "<p style='color: red;'>❌ Cannot connect to {$host}:{$port} - {$errstr}</p>";
        }
    }
}

echo "<h3>4. Database Connection Test</h3>";
$database_configs = [
    ['host' => '127.0.0.1', 'username' => 'root', 'password' => 'root123456', 'database' => 'rgmap_lgu_road_monitoring'],
    ['host' => 'localhost', 'username' => 'root', 'password' => 'root123456', 'database' => 'rgmap_lgu_road_monitoring'],
];

foreach ($database_configs as $index => $config) {
    echo "<h4>Configuration " . ($index + 1) . ": {$config['host']}</h4>";
    echo "<p><strong>DSN:</strong> mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4</p>";
    
    try {
        $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['username'], $config['password']);
        echo "<p style='color: green;'>✅ Connection successful!</p>";
        
        // Test database selection
        $pdo->exec("USE {$config['database']}");
        echo "<p style='color: green;'>✅ Database selected!</p>";
        
        // Test table existence
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (in_array('users', $tables)) {
            echo "<p style='color: green;'>✅ Users table exists!</p>";
            
            // Test query
            $stmt = $pdo->query("SELECT COUNT(*) FROM users");
            $count = $stmt->fetchColumn();
            echo "<p style='color: green;'>✅ Can query users table ({$count} records)</p>";
        } else {
            echo "<p style='color: red;'>❌ Users table missing!</p>";
        }
        
        $pdo = null; // Close connection
        
    } catch (PDOException $e) {
        echo "<p style='color: red;'>❌ Connection failed: " . $e->getMessage() . "</p>";
    }
    
    echo "<hr>";
}

echo "<h3>5. Server Information</h3>";
echo "<p><strong>PHP Version:</strong> " . PHP_VERSION . "</p>";
echo "<p><strong>Server Software:</strong> " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "</p>";
echo "<p><strong>Document Root:</strong> " . $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown') . "</p>";
echo "<p><strong>Current Directory:</strong> " . __DIR__ . "</p>";

echo "<h3>6. Recommendations</h3>";
echo "<ol>";
echo "<li><strong>If MySQL service not running:</strong> <code>sudo systemctl start mysql</code></li>";
echo "<li><strong>If connection fails:</strong> Check MySQL credentials and permissions</li>";
echo "<li><strong>If database missing:</strong> Run <code>setup/install.php</code></li>";
echo "<li><strong>If network issues:</strong> Check firewall and port 3306</li>";
echo "</ol>";

echo "<hr>";
echo "<p><strong>Next Steps:</strong></p>";
echo "<ol>";
echo "<li>Run this diagnostic script and review all results</li>";
echo "<li>Fix any issues identified (red items)</li>";
echo "<li>Test login.php after fixes</li>";
echo "</ol>";
