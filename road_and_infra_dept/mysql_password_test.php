<!DOCTYPE html>
<html>
<head>
    <title>MySQL Password Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .testing { color: blue; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>MySQL Password Test</h1>
    <p>Testing common MySQL passwords to find the correct one...</p>
    
    <?php
    $passwords = [
        '',           // No password (common in XAMPP/WAMP)
        'root',       // Common in MAMP
        'root123456', // Your current attempt
        'password',   // Sometimes used
        'admin',      // Sometimes used
        '123456',     // Simple password
        'mysql',      // Sometimes used
    ];
    
    $host = 'localhost';
    $username = 'root';
    $database = 'rgmap_gu_road_infra';
    
    echo "<h2>Testing Passwords for MySQL User: $username@$host</h2>";
    
    foreach ($passwords as $password) {
        echo "<p class='testing'>🔍 Testing password: '" . ($password === '' ? '(empty)' : $password) . "'</p>";
        
        try {
            $conn = new mysqli($host, $username, $password, $database);
            
            if ($conn->connect_error) {
                echo "<p class='error'>❌ Failed: " . $conn->connect_error . "</p>";
            } else {
                echo "<p class='success'>✅ SUCCESS! Password '" . ($password === '' ? '(empty)' : $password) . "' works!</p>";
                
                // Test a query to make sure it's fully functional
                $result = $conn->query("SELECT COUNT(*) as count FROM users");
                $row = $result->fetch_assoc();
                echo "<p class='success'>📊 Database is working - found " . $row['count'] . " users</p>";
                
                // Show the correct configuration
                echo "<h3>✅ CORRECT CONFIGURATION FOUND:</h3>";
                echo "<pre>";
                echo "return [\n";
                echo "    'host' => 'localhost',\n";
                echo "    'username' => 'root',\n";
                echo "    'password' => '" . addslashes($password) . "',\n";
                echo "    'database' => 'rgmap_gu_road_infra'\n";
                echo "];\n";
                echo "</pre>";
                
                $conn->close();
                break;
            }
        } catch (Exception $e) {
            echo "<p class='error'>❌ Exception: " . $e->getMessage() . "</p>";
        }
        
        echo "<hr>";
    }
    
    echo "<h2>💡 If none of these work:</h2>";
    echo "<ul>";
    echo "<li>Your MySQL might have a custom password</li>";
    echo "<li>You might need to reset the MySQL root password</li>";
    echo "<li>Check your XAMPP/WAMP control panel for MySQL settings</li>";
    echo "</ul>";
    ?>
    
    <p><small>This script tests common MySQL passwords to find the correct one.</small></p>
</body>
</html>
