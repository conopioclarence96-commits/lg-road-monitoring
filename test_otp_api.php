<?php
// Test the OTP API functionality
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Test email OTP function
function testSendOTPToEmail($email, $otpCode) {
    $apiKey = 'sk-2b10kwefyvhbibuanyy7kz9vuovguoim';
    
    $ch = curl_init('https://smsapiph.onrender.com/api/v1/send/email');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'x-api-key: ' . $apiKey,
        'Content-Type: application/json'
    ]);
    
    $message = "Your LGU Portal verification code is: " . $otpCode . ". This code will expire in 5 minutes.";
    
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'recipient' => $email,
        'message' => $message
    ]));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "<h3>Email OTP Test</h3>";
    echo "<p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>";
    echo "<p><strong>OTP Code:</strong> " . $otpCode . "</p>";
    echo "<p><strong>HTTP Status:</strong> " . $httpCode . "</p>";
    echo "<p><strong>API Response:</strong> " . htmlspecialchars($response) . "</p>";
    
    return $httpCode === 200;
}

// Test SMS OTP function
function testSendOTPToSMS($phoneNumber, $otpCode) {
    $ch = curl_init('https://smsapiph.onrender.com/api/v1/send/sms');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'x-api-key: sk-2b10kwefyvhbibuanyy7kz9vuovguoim',
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'recipient' => $phoneNumber,
        'message' => 'Your LGU Portal 2FA code is: ' . $otpCode . '. This code will expire in 5 minutes. Do not share this code.'
    ]));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "<h3>SMS OTP Test</h3>";
    echo "<p><strong>Phone Number:</strong> " . htmlspecialchars($phoneNumber) . "</p>";
    echo "<p><strong>OTP Code:</strong> " . $otpCode . "</p>";
    echo "<p><strong>HTTP Status:</strong> " . $httpCode . "</p>";
    echo "<p><strong>API Response:</strong> " . htmlspecialchars($response) . "</p>";
    
    return json_decode($response, true);
}

// Generate test OTP
$testOTP = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

echo "<!DOCTYPE html>
<html>
<head>
    <title>OTP API Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .test-section { border: 1px solid #ddd; padding: 20px; margin: 20px 0; border-radius: 5px; }
        .success { background: #d4edda; border-color: #c3e6cb; }
        .error { background: #f8d7da; border-color: #f5c6cb; }
    </style>
</head>
<body>
    <h1>🏛️ LGU Portal - OTP API Test</h1>";

// Test Email OTP
$emailResult = testSendOTPToEmail('conopioclarence96@gmail.com', $testOTP);

// Test SMS OTP
$smsResult = testSendOTPToSMS('09123456789', $testOTP);

echo "<div class='test-section " . ($emailResult ? 'success' : 'error') . "'>
    <h2>Email OTP Result: " . ($emailResult ? '✅ SUCCESS' : '❌ FAILED') . "</h2>
</div>";

echo "<div class='test-section'>
    <h2>SMS OTP Result</h2>
    <pre>" . print_r($smsResult, true) . "</pre>
</div>";

echo "<p><strong>Test OTP Code for Manual Verification:</strong> <span style='font-size: 24px; font-weight: bold; color: #0066cc;'>" . $testOTP . "</span></p>";

echo "</body></html>";
?>
