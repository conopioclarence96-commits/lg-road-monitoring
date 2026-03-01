<?php
// Test Gmail authentication with different methods
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h3>Gmail Authentication Test</h3>";

// Test 1: Basic SMTP with TLS
echo "<h4>Test 1: Basic SMTP</h4>";
$socket = fsockopen('smtp.gmail.com', 587, $errno, $errstr, 30);
if ($socket) {
    echo "✅ Connected to Gmail SMTP<br>";
    
    // Read greeting
    fgets($socket, 512);
    
    // EHLO
    fputs($socket, "EHLO localhost\r\n");
    fgets($socket, 512);
    
    // STARTTLS
    fputs($socket, "STARTTLS\r\n");
    $tlsResponse = fgets($socket, 512);
    echo "TLS Response: " . trim($tlsResponse) . "<br>";
    
    // Enable crypto
    $cryptoEnabled = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    echo "TLS Enabled: " . ($cryptoEnabled ? 'Yes' : 'No') . "<br>";
    
    // EHLO again
    fputs($socket, "EHLO localhost\r\n");
    fgets($socket, 512);
    
    // AUTH LOGIN
    fputs($socket, "AUTH LOGIN\r\n");
    $authResponse = fgets($socket, 512);
    echo "Auth Response: " . trim($authResponse) . "<br>";
    
    if (trim($authResponse) == '334') {
        echo "✅ Ready for authentication<br>";
        
        // Send username
        $user = base64_encode('conopioclarence96@gmail.com');
        fputs($socket, $user . "\r\n");
        $userResponse = fgets($socket, 512);
        echo "User Response: " . trim($userResponse) . "<br>";
        
        if (trim($userResponse) == '334') {
            echo "✅ Username accepted<br>";
            
            // Send password
            $pass = base64_encode('dlcd mkxi qcec dgri');
            fputs($socket, $pass . "\r\n");
            $passResponse = fgets($socket, 512);
            echo "Pass Response: " . trim($passResponse) . "<br>";
            
            if (substr(trim($passResponse), 0, 3) == '235') {
                echo "✅ Authentication successful!<br>";
            } else {
                echo "❌ Password authentication failed<br>";
            }
        } else {
            echo "❌ Username rejected<br>";
        }
    } else {
        echo "❌ AUTH not supported<br>";
    }
    
    fclose($socket);
} else {
    echo "❌ Connection failed: $errstr ($errno)<br>";
}

echo "<br><h4>Next Steps:</h4>";
echo "1. Enable 'Less secure app access' in Gmail settings<br>";
echo "2. Try generating a new App Password<br>";
echo "3. Make sure 2-Step Verification is enabled<br>";

?>
