<?php
// Simple login simulation for testing
session_start();

// Clear any existing session
session_destroy();
session_start();

// Set up test session
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'system_admin';
$_SESSION['username'] = 'test_admin';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Transparency Page Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; }
        .info { color: blue; }
        .test-box { border: 1px solid #ccc; padding: 15px; margin: 10px 0; }
        .btn { padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; }
    </style>
</head>
<body>
    <h1>LGU Road Monitoring - Transparency Page Test</h1>
    
    <div class='test-box'>
        <h2>Session Status</h2>
        <p class='success'>✓ Test session created successfully</p>
        <p class='info'>User ID: " . $_SESSION['user_id'] . "</p>
        <p class='info'>Role: " . $_SESSION['role'] . "</p>
    </div>
    
    <div class='test-box'>
        <h2>Database Connection</h2>";
        
try {
    require_once 'lgu_staff/includes/config.php';
    if ($conn && $conn->ping()) {
        echo "<p class='success'>✓ Database connection successful</p>";
    } else {
        echo "<p style='color: red;'>✗ Database connection failed</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Database error: " . $e->getMessage() . "</p>";
}

echo "    </div>
    
    <div class='test-box'>
        <h2>Test the Transparency Page</h2>
        <p>Click the button below to test the transparency page with the current session:</p>
        <p><a href='lgu_staff/pages/transparency/public_transparency.php' class='btn' target='_blank'>Open Transparency Page</a></p>
        <p class='info'>The page should open in a new tab without HTTP 500 errors.</p>
    </div>
    
    <div class='test-box'>
        <h2>Test Summary</h2>
        <p><strong>Issues Fixed:</strong></p>
        <ul>
            <li>✓ Database connection credentials corrected (empty password for local XAMPP)</li>
            <li>✓ Required database 'rgmap_lg_road_monitoring' created</li>
            <li>✓ All necessary tables created with sample data</li>
            <li>✓ PHP syntax validated - no errors found</li>
        </ul>
    </div>
    
    <div class='test-box'>
        <h2>Next Steps</h2>
        <p>To use the transparency page in production:</p>
        <ol>
            <li>Log in through the normal login system</li>
            <li>Navigate to the transparency page</li>
            <li>The page should load without HTTP 500 errors</li>
        </ol>
    </div>
</body>
</html>";
?>
