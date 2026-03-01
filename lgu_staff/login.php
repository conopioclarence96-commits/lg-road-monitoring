<?php
// Session settings (must be set before session_start)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS
    session_start();
}

require_once 'includes/config.php';
require_once 'includes/functions.php';

// Dynamic base path detection for live server
$basePath = '';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';

// Detect if we're in a subdirectory
if (strpos($scriptName, '/lgu_staff/') !== false) {
    $basePath = '../';
} elseif (strpos($scriptName, '/public/') !== false) {
    $basePath = '../';
} elseif (strpos($requestUri, '/lgu-portal/') !== false) {
    $basePath = '';
}

// Check if user is already logged in and show logout option
if (isset($_SESSION['user_id'])) {
    // Allow logout if explicitly requested
    if (isset($_GET['logout'])) {
        session_destroy();
        setcookie(session_name(), '', time() - 3600, '/');
        header('Location: login.php');
        exit();
    }
    
    // Show message that user is already logged in and provide logout option
    $loginMessage = 'You are already logged in as ' . htmlspecialchars($_SESSION['full_name'] ?? 'User') . '. <a href="login.php?logout=1" style="color: #0066cc; text-decoration: underline;">Click here to logout</a>';
    $messageType = 'success';
}

// Handle Step 1 Registration (Email & Password)
$registerMessage = '';
$registerMessageType = '';
$showOTPModal = false;

// Handle OTP Verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
    $enteredOTP = $_POST['otp_code'] ?? '';
    $storedOTP = $_SESSION['otp_data']['code'] ?? '';
    $otpExpiry = $_SESSION['otp_data']['expiry'] ?? 0;
    
    if (empty($enteredOTP)) {
        $registerMessage = 'Please enter the OTP code';
        $registerMessageType = 'error';
        $showOTPModal = true;
    } elseif (time() > $otpExpiry) {
        $registerMessage = 'OTP has expired. Please register again.';
        $registerMessageType = 'error';
        unset($_SESSION['otp_data']);
    } elseif ($enteredOTP !== $storedOTP) {
        $registerMessage = 'Invalid OTP code. Please try again.';
        $registerMessageType = 'error';
        $showOTPModal = true;
    } else {
        // OTP verified successfully
        $_SESSION['otp_verified'] = true;
        $registerMessage = 'Email verified successfully! Please complete your profile.';
        $registerMessageType = 'success';
        
        // Auto-switch to additional info panel
        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                setTimeout(() => showPanel("additional"), 500);
            });
        </script>';
    }
}

// Handle Resend OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_otp'])) {
    if (isset($_SESSION['registration_data'])) {
        $email = $_SESSION['registration_data']['email'];
        
        // Generate new 6-digit OTP
        $otpCode = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['otp_data'] = [
            'code' => $otpCode,
            'expiry' => time() + 300 // 5 minutes expiry
        ];
        
        // Send OTP via API
        sendOTPToEmail($email, $otpCode);
        
        $registerMessage = 'A new OTP has been sent to your email.';
        $registerMessageType = 'success';
        $showOTPModal = true;
        
        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                openOTPModal();
            });
        </script>';
    }
}

// Function to send OTP via email API
function sendOTPToEmail($email, $otpCode) {
    
    // Note: Using the SMS API endpoint as provided - check if it supports email
    // For email OTP, you might need to adjust the recipient parameter
    $ch = curl_init('https://smsapiph.onrender.com/api/v1/send/sms');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'x-api-key: sk-2b10kwefyvhbibuanyy7kz9vuovguoim', // API KEY
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'recipient' => $email,  // Using email as recipient
        'message' => 'Your LGU Portal verification code is: ' . $otpCode . '. This code will expire in 5 minutes.'
    ]));
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    // Log the response for debugging
    error_log("OTP API Response: " . $response);
    
    return json_decode($response, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_register'])) {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    
    // Validate input
    if (empty($email) || empty($password)) {
        $registerMessage = 'Please fill in all fields';
        $registerMessageType = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $registerMessage = 'Invalid email format';
        $registerMessageType = 'error';
    } elseif (strlen($password) < 6) {
        $registerMessage = 'Password must be at least 6 characters';
        $registerMessageType = 'error';
    } else {
        try {
            // Check if email already exists
            $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $checkStmt->bind_param("s", $email);
            $checkStmt->execute();
            $existingUser = $checkStmt->get_result()->num_rows > 0;
            $checkStmt->close();
            
            if ($existingUser) {
                $registerMessage = 'Email address already registered';
                $registerMessageType = 'error';
            } else {
                // Store registration data in session for step 2
                $_SESSION['registration_data'] = [
                    'email' => $email,
                    'password' => password_hash($password, PASSWORD_DEFAULT)
                ];
                
                // Generate 6-digit OTP
                $otpCode = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
                $_SESSION['otp_data'] = [
                    'code' => $otpCode,
                    'expiry' => time() + 300 // 5 minutes expiry
                ];
                
                // Send OTP to email
                sendOTPToEmail($email, $otpCode);
                
                $registerMessage = 'A verification code has been sent to your email. Please check your inbox.';
                $registerMessageType = 'success';
                $showOTPModal = true;
            }
        } catch (Exception $e) {
            error_log("Registration error: " . $e->getMessage());
            $registerMessage = 'An error occurred during registration';
            $registerMessageType = 'error';
        }
    }
}

// Handle Step 2 Registration (Additional Information)
$additionalMessage = '';
$additionalMessageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_additional'])) {
    // Check if we have registration data from step 1 and OTP is verified
    if (!isset($_SESSION['registration_data'])) {
        $additionalMessage = 'Registration session expired. Please start over.';
        $additionalMessageType = 'error';
        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                showPanel("register");
            });
        </script>';
    } elseif (!isset($_SESSION['otp_verified']) || $_SESSION['otp_verified'] !== true) {
        $additionalMessage = 'Please verify your email with OTP first.';
        $additionalMessageType = 'error';
        echo '<script>
            document.addEventListener("DOMContentLoaded", function() {
                showPanel("register");
            });
        </script>';
    } else {
        // Collect form data
        $first_name = sanitize_input($_POST['first_name'] ?? '');
        $middle_name = sanitize_input($_POST['middle_name'] ?? '');
        $last_name = sanitize_input($_POST['last_name'] ?? '');
        $birthday = sanitize_input($_POST['birthday'] ?? '');
        $address = sanitize_input($_POST['address'] ?? '');
        $civil_status = sanitize_input($_POST['civil_status'] ?? '');
        $role = sanitize_input($_POST['role'] ?? '');
        
        // Validate required fields
        if (empty($first_name) || empty($last_name) || empty($role)) {
            $additionalMessage = 'Please fill in all required fields (First Name, Last Name, Role)';
            $additionalMessageType = 'error';
        } else {
            try {
                // Get registration data from session
                $email = $_SESSION['registration_data']['email'];
                $hashedPassword = $_SESSION['registration_data']['password'];
                
                // Create full name from first, middle, and last names
                $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
                $full_name = preg_replace('/\s+/', ' ', $full_name); // Remove extra spaces
                
                // Create username from email (before @)
                $username = explode('@', $email)[0];
                
                // Set department based on role
                $department = ($role === 'system_admin') ? 'Admin' : (($role === 'lgu_staff') ? 'LGU Services' : 'Citizen Services');
                
                // Insert new user with all information
                $idFilePath = null;
                
                // Handle file upload if provided
                if (isset($_FILES['id_file']) && $_FILES['id_file']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = __DIR__ . '/uploads/ids/';
                    
                    // Create directory if it doesn't exist
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    // Generate unique filename
                    $fileExt = pathinfo($_FILES['id_file']['name'], PATHINFO_EXTENSION);
                    $uniqueFilename = uniqid() . '_' . time() . '.' . $fileExt;
                    $targetFile = $uploadDir . $uniqueFilename;
                    
                    // Move uploaded file
                    if (move_uploaded_file($_FILES['id_file']['tmp_name'], $targetFile)) {
                        $idFilePath = 'uploads/ids/' . $uniqueFilename;
                    }
                }
                
                $stmt = $conn->prepare("
                    INSERT INTO users (
                        username, email, password, full_name, role, department, 
                        address, birthday, civil_status, id_file_path, account_status, is_active, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                
                if ($stmt) {
                    $stmt->bind_param("ssssssssss", 
                        $username, $email, $hashedPassword, $full_name, $role, $department,
                        $address, $birthday, $civil_status, $idFilePath
                    );
                    
                    if ($stmt->execute()) {
                        $fileMessage = $idFilePath ? ' ID file uploaded successfully.' : '';
                        $additionalMessage = 'Account created successfully!' . $fileMessage . ' Please wait for admin approval.';
                        $additionalMessageType = 'success';
                        
                        // Clear registration session data
                        unset($_SESSION['registration_data']);
                        unset($_SESSION['otp_data']);
                        unset($_SESSION['otp_verified']);
                        
                        // Redirect back to login after 3 seconds
                        echo '<script>
                            document.addEventListener("DOMContentLoaded", function() {
                                setTimeout(() => {
                                    showPanel("login");
                                    // Clear any form data
                                    document.querySelector(".panel.additional form").reset();
                                }, 3000);
                            });
                        </script>';
                        
                    } else {
                        $additionalMessage = 'Failed to create account';
                        $additionalMessageType = 'error';
                    }
                    $stmt->close();
                } else {
                    $additionalMessage = 'Database error occurred';
                    $additionalMessageType = 'error';
                }
            } catch (Exception $e) {
                error_log("Registration completion error: " . $e->getMessage());
                $additionalMessage = 'An error occurred while creating your account';
                $additionalMessageType = 'error';
            }
        }
    }
}

// Handle login form submission
$loginMessage = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['submit_register']) && !isset($_POST['submit_additional'])) {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    
    // Validate input
    if (empty($email) || empty($password)) {
        $loginMessage = 'Please fill in all fields';
        $messageType = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $loginMessage = 'Invalid email format';
        $messageType = 'error';
    } else {
        try {
            // Prepare statement to prevent SQL injection
            $stmt = $conn->prepare("
                SELECT id, email, password, full_name, role, is_active, account_status
                FROM users 
                WHERE email = ?
            ");
            
            if (!$stmt) {
                throw new Exception("Database preparation failed");
            }
            
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                // Verify password
                if (password_verify($password, $user['password'])) {
                    
                    // Check account status
                    if ($user['account_status'] === 'pending') {
                        $loginMessage = 'Your account is pending approval. Please wait for admin approval.';
                        $messageType = 'error';
                    }
                    elseif ($user['account_status'] === 'rejected') {
                        $loginMessage = 'Your account has been rejected. Please contact administrator.';
                        $messageType = 'error';
                    }
                    elseif (!$user['is_active']) {
                        $loginMessage = 'Account is not active. Please contact administrator.';
                        $messageType = 'error';
                    }
                    else {
                        // Login successful - set session variables
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['full_name'] = $user['full_name'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['logged_in'] = true;
                        $_SESSION['login_time'] = time();
                        
                        // Determine redirect based on user role
                        switch ($user['role']) {
                            case 'system_admin':
                                // Redirect to Admin Dashboard
                                $redirectUrl = $basePath . 'lgu_staff/pages/main/admin_dashboard.php';
                                break;
                            case 'lgu_staff':
                                // Redirect to LGU Staff Dashboard
                                $redirectUrl = $basePath . 'lgu_staff/pages/main/lgu_staff_dashboard.php';
                                break;
                            case 'citizen':
                                $redirectUrl = $basePath . 'lgu_staff/pages/main/lgu_staff_dashboard.php';
                                break;
                            default:
                                $redirectUrl = $basePath . 'lgu_staff/pages/main/lgu_staff_dashboard.php';
                        }
                        
                        // Redirect to appropriate dashboard
                        header('Location: ' . $redirectUrl);
                        exit;
                    }
                } else {
                    // Invalid password
                    $loginMessage = 'Invalid email or password';
                    $messageType = 'error';
                }
            } else {
                // User not found
                $loginMessage = 'Invalid email or password';
                $messageType = 'error';
            }
            
            $stmt->close();
            
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            $loginMessage = 'An error occurred. Please try again later.';
            $messageType = 'error';
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>LGU | Login</title>
    <link rel="stylesheet" href="<?php echo $basePath; ?>../styles/style.css" />
    <link rel="stylesheet" href="<?php echo $basePath; ?>../styles/login.css" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  </head>

  <body>
    <header class="nav">
      <div class="nav-logo">🏛️ Local Government Unit Portal</div>
      <div class="nav-links">
        <a href="">Home</a>
      </div>
    </header>
    <div class="wrapper">
      <div class="slider" id="slider">
        <!-- LOGIN -->
        <div class="panel login">
          <div class="card">
            <img src="<?php echo $basePath; ?>../assets/img/logocityhall.png" class="icon-top" />
            <h2 class="title">LGU Login</h2>
            <p class="subtitle">
              Road and Transportation Infrastructure Monitoring
            </p>

            <?php if (!empty($loginMessage)): ?>
                <div class="<?php echo $messageType === 'error' ? 'error-message' : 'success-message'; ?>" style="background: <?php echo $messageType === 'error' ? '#fee' : '#efe'; ?>; color: <?php echo $messageType === 'error' ? '#c33' : '#060'; ?>; padding: 10px; border-radius: 5px; margin-bottom: 15px;">
                    <?php echo htmlspecialchars($loginMessage); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
              <div class="input-box">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="name@lgu.gov.ph" value="<?php echo isset($_POST['email']) && !isset($_POST['submit_register']) && !isset($_POST['submit_additional']) ? htmlspecialchars($_POST['email']) : ''; ?>" />
                <span class="icon">📧</span>
              </div>

              <div class="input-box">
                <label>Password</label>
                <input type="password" name="password" placeholder="•••••••" />
                <span class="icon">🔒</span>
              </div>

              <button class="btn-primary">Sign In</button>

              <p class="small-text">
                Don't have an account?
                <a href="#" class="link" onclick="showPanel('register')"
                  >Create one</a
                >
              </p>
            </form>
          </div>
        </div>

        <!-- REGISTER STEP 1 -->
        <div class="panel register">
          <div class="card">
            <h2 class="title">Create Account - Step 1</h2>
            <p class="subtitle">Enter your email and password.</p>

            <?php if (!empty($registerMessage)): ?>
                <div class="<?php echo $registerMessageType === 'error' ? 'error-message' : 'success-message'; ?>" style="background: <?php echo $registerMessageType === 'error' ? '#fee' : '#efe'; ?>; color: <?php echo $registerMessageType === 'error' ? '#c33' : '#060'; ?>; padding: 10px; border-radius: 5px; margin-bottom: 15px;">
                    <?php echo htmlspecialchars($registerMessage); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
              <input type="hidden" name="submit_register" value="1">
              <div class="input-box">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="name@lgu.gov.ph" value="<?php echo isset($_POST['email']) && isset($_POST['submit_register']) ? htmlspecialchars($_POST['email']) : ''; ?>" required />
                <span class="icon">📧</span>
              </div>

              <div class="input-box">
                <label>Password</label>
                <input type="password" name="password" placeholder="•••••••" required />
                <span class="icon">🔒</span>
              </div>

              <button class="btn-primary" type="submit">Next Step</button>

              <p class="small-text">
                Already have an account?
                <a href="#" class="link" onclick="showPanel('login')"
                  >Back to Login</a
                >
              </p>
            </form>
          </div>
        </div>

        <!-- ADDITIONAL INFO PANEL - STEP 2 -->
        <div class="panel additional">
          <div class="card wide">
            <h2 class="title">Create Account - Step 2</h2>
            <p class="subtitle">Complete your profile information.</p>

            <?php if (!empty($additionalMessage)): ?>
                <div class="<?php echo $additionalMessageType === 'error' ? 'error-message' : 'success-message'; ?>" style="background: <?php echo $additionalMessageType === 'error' ? '#fee' : '#efe'; ?>; color: <?php echo $additionalMessageType === 'error' ? '#c33' : '#060'; ?>; padding: 10px; border-radius: 5px; margin-bottom: 15px;">
                    <?php echo htmlspecialchars($additionalMessage); ?>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" class="two-column-form">
              <input type="hidden" name="submit_additional" value="1">
              
              <div class="input-box">
                <label>First Name *</label>
                <input type="text" name="first_name" value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>" required />
              </div>

              <div class="input-box">
                <label>Middle Name</label>
                <input type="text" name="middle_name" value="<?php echo isset($_POST['middle_name']) ? htmlspecialchars($_POST['middle_name']) : ''; ?>" />
              </div>

              <div class="input-box">
                <label>Last Name *</label>
                <input type="text" name="last_name" value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>" required />
              </div>

              <div class="input-box">
                <label>Birthday</label>
                <input type="date" name="birthday" value="<?php echo isset($_POST['birthday']) ? htmlspecialchars($_POST['birthday']) : ''; ?>" />
              </div>

              <div class="input-box">
                <label>Address</label>
                <input type="text" name="address" value="<?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?>" />
              </div>

              <div class="input-box">
                <label>Civil Status</label>
                <select name="civil_status">
                  <option value="">Select status</option>
                  <option value="single" <?php echo (isset($_POST['civil_status']) && $_POST['civil_status'] == 'single') ? 'selected' : ''; ?>>Single</option>
                  <option value="married" <?php echo (isset($_POST['civil_status']) && $_POST['civil_status'] == 'married') ? 'selected' : ''; ?>>Married</option>
                  <option value="divorced" <?php echo (isset($_POST['civil_status']) && $_POST['civil_status'] == 'divorced') ? 'selected' : ''; ?>>Divorced</option>
                  <option value="widowed" <?php echo (isset($_POST['civil_status']) && $_POST['civil_status'] == 'widowed') ? 'selected' : ''; ?>>Widowed</option>
                </select>
              </div>

              <div class="input-box">
                <label>Role *</label>
                <select name="role" required>
                  <option value="">Select role</option>
            
                  <option value="lgu_staff" <?php echo (isset($_POST['role']) && $_POST['role'] == 'lgu_staff') ? 'selected' : ''; ?>>LGU Staff</option>
                  <option value="citizen" <?php echo (isset($_POST['role']) && $_POST['role'] == 'citizen') ? 'selected' : ''; ?>>Citizen</option>
                </select>
              </div>

              <!-- UPLOAD ID -->
              <div class="input-box">
                <label>Upload Valid ID (Optional)</label>
                <input type="file" name="id_file" accept="image/*,.pdf" />
                <small>Supported formats: JPG, PNG, PDF</small>
              </div>

              <!-- FULL WIDTH BUTTON -->
              <div class="form-actions">
                <button class="btn-primary" type="submit">Complete Registration</button>
                <p class="small-text">
                  <a href="#" class="link" onclick="showPanel('register')"
                    >Back to Step 1</a
                  >
                </p>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- OTP Verification Modal -->
    <div id="otpModal" class="modal" style="display: <?php echo $showOTPModal ? 'block' : 'none'; ?>;">
      <div class="modal-content">
        <div class="modal-header">
          <h3>Email Verification</h3>
          <p>A 6-digit verification code has been sent to your email address. Please enter it below to verify your account.</p>
        </div>
        
        <form method="POST" id="otpForm">
          <div class="otp-input-container">
            <input type="text" name="otp_code" id="otpCode" maxlength="6" placeholder="000000" class="otp-input" autocomplete="off" />
          </div>
          
          <div class="otp-actions">
            <button type="submit" name="verify_otp" class="btn-primary">Verify Code</button>
          </div>
        </form>
        
        <form method="POST" class="resend-form">
          <div class="otp-resend">
            <span>Didn't receive the code?</span>
            <button type="submit" name="resend_otp" class="link-btn">Resend OTP</button>
          </div>
        </form>
      </div>
    </div>

    <style>
      .modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        display: none;
        justify-content: center;
        align-items: center;
        z-index: 1000;
      }
      
      .modal-content {
        background: white;
        padding: 30px;
        border-radius: 10px;
        max-width: 400px;
        width: 90%;
        text-align: center;
        box-shadow: 0 4px 20px rgba(0,0,0,0.3);
      }
      
      .modal-header h3 {
        margin: 0 0 10px 0;
        color: #333;
      }
      
      .modal-header p {
        color: #666;
        font-size: 14px;
        margin-bottom: 25px;
      }
      
      .otp-input-container {
        margin: 20px 0;
      }
      
      .otp-input {
        width: 180px;
        height: 50px;
        font-size: 24px;
        text-align: center;
        letter-spacing: 8px;
        border: 2px solid #ddd;
        border-radius: 8px;
        outline: none;
      }
      
      .otp-input:focus {
        border-color: #0066cc;
      }
      
      .otp-actions {
        margin: 20px 0;
      }
      
      .otp-resend {
        margin-top: 15px;
        font-size: 13px;
        color: #666;
      }
      
      .otp-resend span {
        margin-right: 5px;
      }
      
      .link-btn {
        background: none;
        border: none;
        color: #0066cc;
        text-decoration: underline;
        cursor: pointer;
        font-size: 13px;
        padding: 0;
      }
      
      .link-btn:hover {
        color: #0055aa;
      }
      
      .resend-form {
        display: inline;
      }
    </style>

    <footer class="footer">
      <div class="footer-links">
        <a href="<?php echo $basePath; ?>../footer/privacy_policy.html">Privacy Policy</a>
        <a href="<?php echo $basePath; ?>../footer/about.html">About</a>
        <a href="<?php echo $basePath; ?>../footer/help.html">Help</a>
      </div>

      <div class="footer-logo">
        © 2025 LGU Citizen Portal · All Rights Reserved
      </div>
    </footer>
    
    <script>
      function showPanel(panel) {
        const wrapper = document.querySelector(".wrapper");

        wrapper.classList.remove("show-register", "show-additional");

        if (panel === "register") wrapper.classList.add("show-register");
        if (panel === "additional") wrapper.classList.add("show-additional");
      }
      
      function openOTPModal() {
        document.getElementById('otpModal').style.display = 'flex';
        document.getElementById('otpCode').focus();
      }
      
      function closeOTPModal() {
        document.getElementById('otpModal').style.display = 'none';
      }

      // Auto-focus on email field for login
      document.addEventListener('DOMContentLoaded', function() {
        const emailInput = document.querySelector('.panel.login input[name="email"]');
        if (emailInput) emailInput.focus();
        
        <?php if ($showOTPModal): ?>
        openOTPModal();
        <?php endif; ?>
      });
      
      // OTP input - only allow numbers
      document.getElementById('otpCode')?.addEventListener('input', function(e) {
        this.value = this.value.replace(/[^0-9]/g, '');
      });

      // Clear error messages on input
      document.querySelectorAll('input, select').forEach(input => {
        input.addEventListener('input', function() {
          const errorMsg = this.closest('.input-box')?.querySelector('.field-error');
          if (errorMsg) errorMsg.remove();
        });
      });
    </script>
  </body>
</html>
