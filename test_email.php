<?php
// Test email sending
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Test basic PHP mail
$to = 'conopioclarence96@gmail.com';
$subject = 'Test Email from LGU Portal';
$message = 'This is a test email to check if PHP mail() function works.';
$headers = 'From: conopioclarence96@gmail.com' . "\r\n" .
           'Reply-To: conopioclarence96@gmail.com' . "\r\n" .
           'X-Mailer: PHP/' . phpversion();

echo "Attempting to send test email to: $to<br>";
$sent = mail($to, $subject, $message, $headers);

if ($sent) {
    echo "✅ Basic PHP mail() successful!<br>";
} else {
    echo "❌ Basic PHP mail() failed!<br>";
    echo "Error: " . error_get_last()['message'] ?? "Unknown error<br>";
}

// Test SMTP connection
echo "<br>Testing SMTP connection to Gmail...<br>";
$smtpHost = 'smtp.gmail.com';
$smtpPort = 587;

$socket = fsockopen($smtpHost, $smtpPort, $errno, $errstr, 10);
if ($socket) {
    echo "✅ SMTP connection successful!<br>";
    fclose($socket);
} else {
    echo "❌ SMTP connection failed: $errstr ($errno)<br>";
}

// Check PHP configuration
echo "<br>PHP Mail Configuration:<br>";
echo "sendmail_path: " . ini_get('sendmail_path') . "<br>";
echo "SMTP: " . ini_get('SMTP') . "<br>";
echo "smtp_port: " . ini_get('smtp_port') . "<br>";
echo "mail.add_x_header: " . (ini_get('mail.add_x_header') ? 'On' : 'Off') . "<br>";

?>
