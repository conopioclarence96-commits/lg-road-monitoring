<?php
// Database Diagnostic Script
// This script helps identify the correct database credentials
// Upload to your live server and run it

echo "<h1>🔍 Database Connection Diagnostic</h1>";
echo "<h2>Testing Multiple Database Credentials...</h2>";

$database_name = 'lg_road_monitoring';
$working_credentials = [];

// Test 1: Root with empty password
echo "<h3>Test 1: Root with empty password</h3>";
$conn = @new mysqli('localhost', 'root', '', $database_name);
if (!$conn->connect_error) {
    echo "<p style='color: green;'>✅ SUCCESS: Root with empty password works!</p>";
    $working_credentials[] = ['user' => 'root', 'pass' => ''];
} else {
    echo "<p style='color: red;'>❌ Failed: " . $conn->connect_error . "</p>";
}

// Test 2: Root with common passwords
echo "<h3>Test 2: Root with common passwords</h3>";
$common_passwords = ['password', '123456', 'root', 'mysql', 'admin', ''];
foreach ($common_passwords as $pass) {
    $conn = @new mysqli('localhost', 'root', $pass, $database_name);
    if (!$conn->connect_error) {
        echo "<p style='color: green;'>✅ SUCCESS: Root with password '$pass' works!</p>";
        $working_credentials[] = ['user' => 'root', 'pass' => $pass];
        break;
    }
}

// Test 3: Common hosting usernames
echo "<h3>Test 3: Common hosting usernames</h3>";
$hosting_users = [
    ['user' => 'rgmapinf_lgu', 'pass' => 'lguroad2024'],
    ['user' => 'rgmapinf_lgu_user', 'pass' => 'rgmapinf123'],
    ['user' => 'rgmapinf_admin', 'pass' => 'admin123'],
    ['user' => 'rgmapinf', 'pass' => 'rgmapinf123'],
    ['user' => 'rgmapinf_lgu_user', 'pass' => ''],
    ['user' => 'rgmapinf_lgu', 'pass' => '']
];

foreach ($hosting_users as $creds) {
    $conn = @new mysqli('localhost', $creds['user'], $creds['pass'], $database_name);
    if (!$conn->connect_error) {
        echo "<p style='color: green;'>✅ SUCCESS: User '{$creds['user']}' with password '{$creds['pass']}' works!</p>";
        $working_credentials[] = $creds;
        break;
    }
}

// Test 4: Database name as username
echo "<h3>Test 4: Database name as username</h3>";
$conn = @new mysqli('localhost', $database_name, '', $database_name);
if (!$conn->connect_error) {
    echo "<p style='color: green;'>✅ SUCCESS: Database name as username works!</p>";
    $working_credentials[] = ['user' => $database_name, 'pass' => ''];
} else {
    echo "<p style='color: red;'>❌ Failed: " . $conn->connect_error . "</p>";
}

// Test 5: Show all MySQL users (if root works)
echo "<h3>Test 5: Available MySQL Users</h3>";
$conn = @new mysqli('localhost', 'root', '', '');
if (!$conn->connect_error) {
    $result = $conn->query("SELECT User, Host FROM mysql.user");
    if ($result) {
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>Username</th><th>Host</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr><td>" . htmlspecialchars($row['User']) . "</td><td>" . htmlspecialchars($row['Host']) . "</td></tr>";
        }
        echo "</table>";
    }
} else {
    echo "<p style='color: orange;'>Cannot list users (root access required)</p>";
}

// Show results
echo "<h2>🎯 Results</h2>";
if (!empty($working_credentials)) {
    echo "<h3 style='color: green;'>Working Credentials Found:</h3>";
    foreach ($working_credentials as $creds) {
        echo "<p><strong>User:</strong> " . htmlspecialchars($creds['user']) . 
             " | <strong>Password:</strong> '" . htmlspecialchars($creds['pass']) . "'</p>";
    }
    
    echo "<h3>Update your live_db_config.php with:</h3>";
    echo "<pre style='background: #f4f4f4; padding: 10px; border-radius: 5px;'>";
    echo "&lt;?php\n";
    echo "return [\n";
    echo "    'host' => 'localhost',\n";
    echo "    'user' => '" . htmlspecialchars($working_credentials[0]['user']) . "',\n";
    echo "    'pass' => '" . htmlspecialchars($working_credentials[0]['pass']) . "',\n";
    echo "    'name' => 'lg_road_monitoring'\n";
    echo "];\n";
    echo "?&gt;";
    echo "</pre>";
} else {
    echo "<p style='color: red;'>❌ No working credentials found. Contact your hosting provider for database details.</p>";
}

echo "<hr>";
echo "<h3>📋 Next Steps:</h3>";
echo "<ol>";
echo "<li>Copy the working credentials above</li>";
echo "<li>Update your live_db_config.php file</li>";
echo "<li>Delete this diagnostic script for security</li>";
echo "</ol>";

echo "<p style='color: orange;'><strong>Important:</strong> Delete this file after use for security!</p>";
?>
