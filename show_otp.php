<?php
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>OTP Debug - LGU Portal</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .otp-display { background: #f0f8ff; padding: 20px; border-radius: 10px; margin: 20px 0; }
        .otp-code { font-size: 32px; font-weight: bold; color: #0066cc; letter-spacing: 5px; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; padding: 10px; border-radius: 5px; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; border-radius: 5px; }
        .info { background: #d1ecf1; border: 1px solid #bee5eb; padding: 10px; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>🏛️ LGU Portal - OTP Debug Information</h1>
    
    <div class="info">
        <strong>Debug Mode:</strong> This page shows OTP codes for testing when the SMS API limit is reached.
    </div>
    
    <?php if (isset($_SESSION['debug_otp'])): ?>
        <div class="otp-display">
            <h2>📧 Latest Email OTP</h2>
            <p><strong>Email:</strong> <?php echo htmlspecialchars($_SESSION['debug_otp']['email']); ?></p>
            <p><strong>OTP Code:</strong> <span class="otp-code"><?php echo $_SESSION['debug_otp']['code']; ?></span></p>
            <p><strong>Generated:</strong> <?php echo date('Y-m-d H:i:s', $_SESSION['debug_otp']['timestamp']); ?></p>
            <p><strong>Expires:</strong> <?php echo date('Y-m-d H:i:s', $_SESSION['debug_otp']['timestamp'] + 300); ?></p>
            
            <?php 
            $timeLeft = ($_SESSION['debug_otp']['timestamp'] + 300) - time();
            if ($timeLeft > 0): ?>
                <p><strong>Time Remaining:</strong> <?php echo floor($timeLeft / 60); ?> minutes <?php echo $timeLeft % 60; ?> seconds</p>
            <?php else: ?>
                <p style="color: red;"><strong>⚠️ OTP has expired!</strong></p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="error">
            <strong>No OTP data found.</strong> Please start the registration process to generate an OTP.
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['otp_data'])): ?>
        <div class="otp-display">
            <h2>🔐 Current Session OTP</h2>
            <p><strong>Code:</strong> <span class="otp-code"><?php echo $_SESSION['otp_data']['code']; ?></span></p>
            <p><strong>Expires:</strong> <?php echo date('Y-m-d H:i:s', $_SESSION['otp_data']['expiry']); ?></p>
            
            <?php 
            $timeLeft = $_SESSION['otp_data']['expiry'] - time();
            if ($timeLeft > 0): ?>
                <p><strong>Time Remaining:</strong> <?php echo floor($timeLeft / 60); ?> minutes <?php echo $timeLeft % 60; ?> seconds</p>
            <?php else: ?>
                <p style="color: red;"><strong>⚠️ OTP has expired!</strong></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['registration_data'])): ?>
        <div class="success">
            <h2>📝 Registration Session Active</h2>
            <p><strong>Email:</strong> <?php echo htmlspecialchars($_SESSION['registration_data']['email']); ?></p>
            <p><strong>OTP Verified:</strong> <?php echo isset($_SESSION['otp_verified']) && $_SESSION['otp_verified'] ? '✅ Yes' : '❌ No'; ?></p>
        </div>
    <?php endif; ?>
    
    <div style="margin-top: 30px;">
        <a href="lgu_staff/login.php" style="background: #0066cc; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">← Back to Login</a>
        <a href="debug_otp.php" style="background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-left: 10px;">Generate Test OTP</a>
    </div>
    
    <script>
        // Auto-refresh every 30 seconds
        setTimeout(function() {
            window.location.reload();
        }, 30000);
    </script>
</body>
</html>
