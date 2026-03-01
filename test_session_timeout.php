<?php
session_start();

// Session timeout configuration
$session_timeout = 5 * 60; // 5 minutes in seconds

// Check if session has expired
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    echo "<h2>Session Expired</h2>";
    echo "<p>Your session has expired due to inactivity.</p>";
    echo "<p>Last activity: " . date('Y-m-d H:i:s', $_SESSION['last_activity']) . "</p>";
    echo "<p>Current time: " . date('Y-m-d H:i:s') . "</p>";
    echo "<p>Time difference: " . (time() - $_SESSION['last_activity']) . " seconds</p>";
    echo "<p>Timeout limit: $session_timeout seconds</p>";
    
    session_destroy();
    echo '<a href="login.php">Login Again</a>';
    exit;
}

// Update last activity time
$_SESSION['last_activity'] = time();

echo "<h2>Session Active</h2>";
echo "<p>Session started: " . date('Y-m-d H:i:s', $_SESSION['last_activity']) . "</p>";
echo "<p>Current time: " . date('Y-m-d H:i:s') . "</p>";
echo "<p>Session expires in: " . ($session_timeout / 60) . " minutes</p>";
echo "<p><strong>Note:</strong> You will receive a 1-minute warning before timeout.</p>";
echo "<p><a href='login.php?timeout=1'>Simulate Timeout</a></p>";
?>

<script>
// Simulate activity updates
function updateActivity() {
    fetch('test_session_timeout.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'update_activity=1'
    }).then(response => response.json())
    .then(data => {
        console.log('Activity updated:', data);
        document.getElementById('status').innerHTML = 
            'Last activity: ' + new Date(data.last_activity * 1000).toLocaleTimeString();
    });
}

// Auto-update every 10 seconds
setInterval(updateActivity, 10000);
</script>

<div id="status" style="padding: 20px; background: #f0f8ff; margin: 20px; border-radius: 5px;">
    Click anywhere to update session activity...
</div>
?>
