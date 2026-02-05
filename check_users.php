<?php
// check_users.php - Check what users exist in the database
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'road_and_infra_dept/config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "<h2>🔍 Checking Users in Database</h2>";
    
    // Check all users
    $stmt = $conn->prepare("SELECT id, username, first_name, last_name, email FROM users ORDER BY id");
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo "<h3>📋 Available Users:</h3>";
    
    if ($result->num_rows === 0) {
        echo "<p style='color: red;'>❌ No users found in database!</p>";
        echo "<p><strong>Solution:</strong> We need to create a test user or use an existing user ID.</p>";
    } else {
        echo "<div style='background: #f8f9fa; padding: 10px; border-radius: 5px;'>";
        while ($row = $result->fetch_assoc()) {
            echo "<strong>ID: {$row['id']}</strong> - ";
            echo "{$row['first_name']} {$row['last_name']} ({$row['username']})<br>";
        }
        echo "</div>";
        
        // Get first user ID for testing
        $result->data_seek(0);
        $first_user = $result->fetch_assoc();
        echo "<p style='color: green;'><strong>✅ Use User ID: {$first_user['id']} for testing</strong></p>";
    }
    
    // Check if we can create a test user
    echo "<h3>🔧 Create Test User (if needed):</h3>";
    $test_user_id = 999;
    $check_stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
    $check_stmt->bind_param('i', $test_user_id);
    $check_stmt->execute();
    
    if ($check_stmt->get_result()->num_rows === 0) {
        echo "<p>Creating test user with ID $test_user_id...</p>";
        
        $insert_stmt = $conn->prepare("INSERT INTO users (id, username, email, first_name, last_name, password, role, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $test_username = 'test_user_' . time();
        $test_email = 'test@example.com';
        $test_password = password_hash('test123', PASSWORD_DEFAULT);
        $test_role = 'citizen';
        
        $insert_stmt->bind_param('issssss', $test_user_id, $test_username, $test_email, 'Test', 'User', $test_password, $test_role);
        
        if ($insert_stmt->execute()) {
            echo "<p style='color: green;'>✅ Test user created successfully!</p>";
            echo "<p><strong>Test User ID:</strong> $test_user_id</p>";
        } else {
            echo "<p style='color: red;'>❌ Failed to create test user: " . $conn->error . "</p>";
        }
    } else {
        echo "<p style='color: blue;'>ℹ️ Test user ID $test_user_id already exists</p>";
    }
    
} catch (Exception $e) {
    echo "<h2>❌ Database Error</h2>";
    echo "<p style='color: red;'><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
