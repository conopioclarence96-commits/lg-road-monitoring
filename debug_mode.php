<?php
// Debug mode configuration for OTP testing
session_start();

// Enable debug mode
define('OTP_DEBUG_MODE', true);

// Debug function to show OTP on screen instead of sending
function debugSendOTP($recipient, $otpCode, $type = 'email') {
    if (OTP_DEBUG_MODE) {
        // Store OTP in session for debugging
        $_SESSION['debug_otp'] = [
            'code' => $otpCode,
            'recipient' => $recipient,
            'type' => $type,
            'timestamp' => time()
        ];
        
        // Log for debugging
        error_log("DEBUG OTP - $type: $recipient -> $otpCode");
        
        return true;
    }
    return false;
}

// Function to get latest debug OTP
function getDebugOTP() {
    return $_SESSION['debug_otp'] ?? null;
}

// Function to clear debug OTP
function clearDebugOTP() {
    unset($_SESSION['debug_otp']);
}
?>
