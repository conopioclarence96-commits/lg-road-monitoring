<?php
// Live Database Setup Script
// This script helps create database and user on live server
// Upload this to your live server and run it once

echo "<h1>LGU Road Monitoring - Database Setup</h1>";

// Database connection with root privileges (for setup only)
$root_conn = new mysqli('localhost', 'root', '');

if ($root_conn->connect_error) {
    die("Failed to connect to MySQL as root: " . $root_conn->connect_error . 
        "<br><br>Please check your MySQL root password or contact hosting support.");
}

echo "<p style='color: green;'>✅ Connected to MySQL successfully</p>";

// Database configuration
$db_name = 'lg_road_monitoring';
$db_user = 'rgmapinf_lgu_user';
$db_pass = 'YourSecurePassword123!'; // Change this to a secure password

// Create database if it doesn't exist
echo "<h3>Creating database...</h3>";
$sql = "CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
if ($root_conn->query($sql)) {
    echo "<p style='color: green;'>✅ Database '$db_name' created or already exists</p>";
} else {
    echo "<p style='color: red;'>❌ Error creating database: " . $root_conn->error . "</p>";
}

// Create user if it doesn't exist
echo "<h3>Creating database user...</h3>";
$sql = "CREATE USER IF NOT EXISTS '$db_user'@'localhost' IDENTIFIED BY '$db_pass'";
if ($root_conn->query($sql)) {
    echo "<p style='color: green;'>✅ User '$db_user' created or already exists</p>";
} else {
    echo "<p style='color: red;'>❌ Error creating user: " . $root_conn->error . "</p>";
}

// Grant privileges
echo "<h3>Granting privileges...</h3>";
$sql = "GRANT ALL PRIVILEGES ON `$db_name`.* TO '$db_user'@'localhost'";
if ($root_conn->query($sql)) {
    echo "<p style='color: green;'>✅ Privileges granted successfully</p>";
} else {
    echo "<p style='color: red;'>❌ Error granting privileges: " . $root_conn->error . "</p>";
}

// Flush privileges
$root_conn->query("FLUSH PRIVILEGES");

// Test connection with new user
echo "<h3>Testing new database connection...</h3>";
$test_conn = new mysqli('localhost', $db_user, $db_pass, $db_name);
if ($test_conn->connect_error) {
    echo "<p style='color: red;'>❌ Test connection failed: " . $test_conn->connect_error . "</p>";
} else {
    echo "<p style='color: green;'>✅ Test connection successful!</p>";
}

$root_conn->close();
$test_conn->close();

echo "<hr>";
echo "<h2>Setup Complete!</h2>";
echo "<p><strong>Database Name:</strong> $db_name</p>";
echo "<p><strong>Username:</strong> $db_user</p>";
echo "<p><strong>Password:</strong> $db_pass</p>";
echo "<p><strong>Host:</strong> localhost</p>";

echo "<h3>Next Steps:</h3>";
echo "<ol>";
echo "<li>Update your <code>live_db_config.php</code> file with the credentials above</li>";
echo "<li>Import your SQL dump file using phpMyAdmin</li>";
echo "<li>Delete this setup script from the server</li>";
echo "</ol>";

echo "<p style='color: orange;'><strong>Important:</strong> Delete this file after setup for security!</p>";
?>
