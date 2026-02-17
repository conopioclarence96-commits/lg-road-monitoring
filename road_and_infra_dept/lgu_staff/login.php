<?php
// Session settings (must be set before session_start)
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS

session_start();
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    // Optional: Add logout link for debugging
    if (isset($_GET['logout'])) {
        session_destroy();
        setcookie(session_name(), '', time() - 3600, '/');
        header('Location: login.php');
        exit();
    }
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Location: pages/lgu_staff_dashboard.php');
    exit();
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_form'])) {
    $email = sanitize_input($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Validate input
    $errors = [];
    if (empty($email)) {
        $errors['email'] = 'Email is required';
    } elseif (!validate_email($email)) {
        $errors['email'] = 'Invalid email format';
    }
    
    if (empty($password)) {
        $errors['password'] = 'Password is required';
    }
    
    if (empty($errors)) {
        // Check user credentials
        $stmt = $conn->prepare("SELECT id, username, email, password, full_name, role, department FROM users WHERE email = ? AND is_active = 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Verify password (using password_verify for hashed passwords)
            if (password_verify($password, $user['password'])) {
                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['department'] = $user['department'];
                
                // Log the login
                log_audit_action($user['id'], 'User login', 'User logged in successfully');
                
                // Redirect to dashboard
                header('Cache-Control: no-cache, no-store, must-revalidate');
                header('Pragma: no-cache');
                header('Expires: 0');
                header('Location: pages/lgu_staff_dashboard.php');
                exit();
            } else {
                $login_error = 'Invalid email or password';
            }
        } else {
            $login_error = 'Invalid email or password';
        }
    }
}

// Handle registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_form'])) {
    $email = sanitize_input($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $first_name = sanitize_input($_POST['first_name'] ?? '');
    $middle_name = sanitize_input($_POST['middle_name'] ?? '');
    $last_name = sanitize_input($_POST['last_name'] ?? '');
    $birthday = $_POST['birthday'] ?? '';
    $address = sanitize_input($_POST['address'] ?? '');
    $civil_status = sanitize_input($_POST['civil_status'] ?? '');
    $role = sanitize_input($_POST['role'] ?? '');
    
    // Validate registration
    $errors = [];
    if (empty($email)) $errors['email'] = 'Email is required';
    elseif (!validate_email($email)) $errors['email'] = 'Invalid email format';
    
    if (empty($password)) $errors['password'] = 'Password is required';
    elseif (strlen($password) < 6) $errors['password'] = 'Password must be at least 6 characters';
    
    if (empty($first_name)) $errors['first_name'] = 'First name is required';
    if (empty($last_name)) $errors['last_name'] = 'Last name is required';
    if (empty($role)) $errors['role'] = 'Role is required';
    
    // Handle file upload
    $id_file_path = '';
    if (isset($_FILES['id_file']) && $_FILES['id_file']['error'] === UPLOAD_ERR_OK) {
        $upload_result = handle_file_upload($_FILES['id_file'], 'uploads/ids/', ['jpg', 'jpeg', 'png']);
        if ($upload_result['success']) {
            $id_file_path = $upload_result['filepath'];
        } else {
            $errors['id_file'] = $upload_result['error'];
        }
    }
    
    if (empty($errors)) {
        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert new user
        $stmt = $conn->prepare("INSERT INTO users (username, email, password, full_name, role, department) VALUES (?, ?, ?, ?, ?, ?)");
        $username = $first_name . ' ' . $last_name;
        $full_name = "$first_name $middle_name $last_name";
        $department = ($role === 'lgu_officer') ? 'LGU Services' : 'Citizen Services';
        
        $stmt->bind_param("ssssss", $username, $email, $hashed_password, $full_name, $role, $department);
        
        if ($stmt->execute()) {
            $register_success = 'Registration successful! Please wait for account approval.';
            log_audit_action(0, 'User registration', "New user registered: $email");
        } else {
            $register_error = 'Registration failed. Please try again.';
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
    <link rel="stylesheet" href="../styles/style.css" />
    <link rel="stylesheet" href="../styles/login.css" />
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
            <img src="../assets/img/logocityhall.png" class="icon-top" />
            <h2 class="title">LGU Login</h2>
            <p class="subtitle">
              Road and Transportation Infrastructure Monitoring
            </p>

            <?php if (isset($login_error)): ?>
                <div class="error-message" style="background: #fee; color: #c33; padding: 10px; border-radius: 5px; margin-bottom: 15px;">
                    <?php echo htmlspecialchars($login_error); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($register_success)): ?>
                <div class="success-message" style="background: #efe; color: #3c3; padding: 10px; border-radius: 5px; margin-bottom: 15px;">
                    <?php echo htmlspecialchars($register_success); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
              <input type="hidden" name="login_form" value="1">
              <div class="input-box">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="name@lgu.gov.ph" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" />
                <span class="icon">📧</span>
                <?php if (isset($errors['email'])): ?>
                    <div class="field-error" style="color: #dc3545; font-size: 12px; margin-top: 5px;"><?php echo htmlspecialchars($errors['email']); ?></div>
                <?php endif; ?>
              </div>

              <div class="input-box">
                <label>Password</label>
                <input type="password" name="password" placeholder="•••••••" />
                <span class="icon">🔒</span>
                <?php if (isset($errors['password'])): ?>
                    <div class="field-error" style="color: #dc3545; font-size: 12px; margin-top: 5px;"><?php echo htmlspecialchars($errors['password']); ?></div>
                <?php endif; ?>
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

        <!-- REGISTER -->
        <div class="panel register">
          <div class="card">
            <h2 class="title">Create Account</h2>
            <p class="subtitle">Register for LGU services.</p>

            <?php if (isset($register_error)): ?>
                <div class="error-message" style="background: #fee; color: #c33; padding: 10px; border-radius: 5px; margin-bottom: 15px;">
                    <?php echo htmlspecialchars($register_error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
              <input type="hidden" name="register_form" value="1">
              <div class="input-box">
                <label>Email Address</label>
                <input type="email" name="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" />
                <?php if (isset($errors['email'])): ?>
                    <div class="field-error" style="color: #dc3545; font-size: 12px; margin-top: 5px;"><?php echo htmlspecialchars($errors['email']); ?></div>
                <?php endif; ?>
              </div>

              <div class="input-box">
                <label>Password</label>
                <input type="password" name="password" />
                <?php if (isset($errors['password'])): ?>
                    <div class="field-error" style="color: #dc3545; font-size: 12px; margin-top: 5px;"><?php echo htmlspecialchars($errors['password']); ?></div>
                <?php endif; ?>
              </div>

              <button
                class="btn-primary"
                type="button"
                onclick="showPanel('additional')"
              >
                Next
              </button>

              <p class="small-text">
                Already have an account?
                <a href="#" class="link" onclick="showPanel('login')"
                  >Back to Login</a
                >
              </p>
            </form>
          </div>
        </div>

        <!-- ADDITIONAL INFO PANEL -->
        <div class="panel additional">
          <div class="card wide">
            <h2 class="title">Additional Information</h2>

            <form method="POST" enctype="multipart/form-data" class="two-column-form">
              <input type="hidden" name="register_form" value="1">
              <div class="input-box">
                <label>First Name</label>
                <input type="text" name="first_name" value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>" />
                <?php if (isset($errors['first_name'])): ?>
                    <div class="field-error" style="color: #dc3545; font-size: 12px; margin-top: 5px;"><?php echo htmlspecialchars($errors['first_name']); ?></div>
                <?php endif; ?>
              </div>

              <div class="input-box">
                <label>Middle Name</label>
                <input type="text" name="middle_name" value="<?php echo isset($_POST['middle_name']) ? htmlspecialchars($_POST['middle_name']) : ''; ?>" />
              </div>

              <div class="input-box">
                <label>Last Name</label>
                <input type="text" name="last_name" value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>" />
                <?php if (isset($errors['last_name'])): ?>
                    <div class="field-error" style="color: #dc3545; font-size: 12px; margin-top: 5px;"><?php echo htmlspecialchars($errors['last_name']); ?></div>
                <?php endif; ?>
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
                <input type="text" name="civil_status" value="<?php echo isset($_POST['civil_status']) ? htmlspecialchars($_POST['civil_status']) : ''; ?>" />
              </div>

              <div class="input-box">
                <label>Role</label>
                <select name="role">
                  <option value="">Select role</option>
                  <option value="lgu_officer" <?php echo (isset($_POST['role']) && $_POST['role'] == 'lgu_officer') ? 'selected' : ''; ?>>LGU Officer</option>
                  <option value="citizen" <?php echo (isset($_POST['role']) && $_POST['role'] == 'citizen') ? 'selected' : ''; ?>>Citizen</option>
                </select>
                <?php if (isset($errors['role'])): ?>
                    <div class="field-error" style="color: #dc3545; font-size: 12px; margin-top: 5px;"><?php echo htmlspecialchars($errors['role']); ?></div>
                <?php endif; ?>
              </div>

              <!-- UPLOAD ID -->
              <div class="input-box">
                <label>Upload Valid ID</label>
                <input type="file" name="id_file" accept="image/*" />
                <?php if (isset($errors['id_file'])): ?>
                    <div class="field-error" style="color: #dc3545; font-size: 12px; margin-top: 5px;"><?php echo htmlspecialchars($errors['id_file']); ?></div>
                <?php endif; ?>
              </div>

              <!-- FULL WIDTH BUTTON -->
              <div class="form-actions">
                <button class="btn-primary" type="submit">Submit</button>
                <p class="small-text">
                  <a href="#" class="link" onclick="showPanel('register')"
                    >Back</a
                  >
                </p>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <footer class="footer">
      <div class="footer-links">
        <a href="../footer/privacy_policy.html">Privacy Policy</a>
        <a href="../footer/about.html">About</a>
        <a href="../footer/help.html">Help</a>
      </div>

      <div class="footer-logo">
        © 2025 LGU Citizen Portal · All Rights Reserved
      </div>
    </footer>
    
    <!-- Demo Accounts Info -->
    <div style="position: fixed; bottom: 20px; right: 20px; background: rgba(0,0,0,0.8); color: white; padding: 15px; border-radius: 8px; font-size: 12px; z-index: 1000;">
        <strong><i class="fas fa-info-circle"></i> Demo Accounts:</strong><br>
        Admin: admin@lgu.gov.ph / password<br>
        Officer: jsantos@lgu.gov.ph / password<br>
        Staff: mreyes@lgu.gov.ph / password<br><br>
        <a href="login.php?logout=1" style="color: #4CAF50; text-decoration: underline;">🔄 Clear Session</a>
    </div>

    <script>
      function showPanel(panel) {
        const wrapper = document.querySelector(".wrapper");

        wrapper.classList.remove("show-register", "show-additional");

        if (panel === "register") wrapper.classList.add("show-register");
        if (panel === "additional") wrapper.classList.add("show-additional");
      }

      // Auto-focus on email field for login
      document.addEventListener('DOMContentLoaded', function() {
        const emailInput = document.querySelector('.panel.login input[name="email"]');
        if (emailInput) emailInput.focus();
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
