<?php
// Debug OTP - Show code on screen instead of sending email
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_register'])) {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    
    // Generate 6-digit OTP
    $otpCode = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    
    // Store OTP in session
    $_SESSION['otp_data'] = [
        'code' => $otpCode,
        'expiry' => time() + 300 // 5 minutes expiry
    ];
    
    // Store registration data
    $_SESSION['registration_data'] = [
        'email' => $email,
        'password' => password_hash($password, PASSWORD_DEFAULT)
    ];
    
    echo "<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 30px; border: 1px solid #ddd; border-radius: 10px;'>";
    echo "<h2 style='color: #0066cc;'>🏛️ LGU Portal - Debug Mode</h2>";
    echo "<h3>OTP Generated for Testing</h3>";
    echo "<p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>";
    echo "<p><strong>Your OTP Code:</strong> <span style='background: #0066cc; color: white; font-size: 24px; font-weight: bold; padding: 10px 20px; border-radius: 5px; letter-spacing: 3px;'>" . $otpCode . "</span></p>";
    echo "<p><strong>Expires in:</strong> 5 minutes</p>";
    echo "<p style='color: #666; font-size: 14px;'>Use this code to complete registration. This is debug mode - normally this would be sent to your email.</p>";
    echo "<hr>";
    echo "<p><a href='login.php' style='background: #0066cc; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>← Back to Login</a></p>";
    echo "</div>";
    
    // Auto-redirect to step 2 after 3 seconds
    echo "<script>
        setTimeout(function() {
            window.location.href = 'login.php';
        }, 8000);
    </script>";
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Debug OTP - LGU Portal</title>
</head>
<body>
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 30px; text-align: center;'>
        <h2>Debug OTP Generator</h2>
        <p>This page generates OTP codes for testing without email.</p>
        <form method="POST" action="">
            <div style='margin: 20px 0;'>
                <label>Email:</label><br>
                <input type="email" name="email" required style='padding: 10px; width: 300px; margin: 10px 0;' placeholder="Enter your email"><br><br>
                
                <label>Password:</label><br>
                <input type="password" name="password" required style='padding: 10px; width: 300px; margin: 10px 0;' placeholder="Enter your password"><br><br>
                
                <button type="submit" name="submit_register" style='background: #0066cc; color: white; padding: 12px 30px; border: none; border-radius: 5px; cursor: pointer;'>Generate OTP</button>
            </div>
        </form>
    </div>
</body>
</html>
