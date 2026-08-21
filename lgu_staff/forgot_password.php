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

if (strpos($scriptName, '/lgu_staff/') !== false) {
    $basePath = '../';
} elseif (strpos($scriptName, '/public/') !== false) {
    $basePath = '../';
} elseif (strpos($requestUri, '/lgu-portal/') !== false) {
    $basePath = '';
}

// Allow the user to abandon an in-progress reset and start over
if (isset($_GET['restart'])) {
    unset($_SESSION['reset_email']);
    unset($_SESSION['reset_flow']);
    unset($_SESSION['otp_sent_at']);
    unset($_SESSION['otp_data']);
    unset($_SESSION['debug_otp']);
}

$msg = '';
$msgType = '';
$currentStep = 'email';
$resendCooldown = 0;

// Determine the step from the session for GET / initial render
if (isset($_SESSION['reset_flow']) && $_SESSION['reset_flow'] === 'otp_verified') {
    $currentStep = 'password';
} elseif (!empty($_SESSION['reset_email'])) {
    $currentStep = 'otp';
    $resendCooldown = isset($_SESSION['otp_sent_at']) ? max(0, 60 - (time() - (int)$_SESSION['otp_sent_at'])) : 0;
}

// STEP 1 - Request password reset (enter registered email)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step_email'])) {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = 'Invalid email address.';
        $msgType = 'error';
        $currentStep = 'email';
    } else {
        try {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows === 1;
            $stmt->close();

            if (!$exists) {
                $msg = 'Invalid email address.';
                $msgType = 'error';
                $currentStep = 'email';
            } else {
                $_SESSION['reset_email'] = $email;
                $_SESSION['reset_flow'] = 'otp_sent';
                handle_password_reset_otp($email);
                $_SESSION['otp_sent_at'] = time();
                $msg = 'A verification code has been sent to your email.';
                $msgType = 'success';
                $currentStep = 'otp';
                $resendCooldown = 60;
            }
        } catch (Exception $e) {
            error_log("Password reset step 1 error: " . $e->getMessage());
            $msg = 'An error occurred. Please try again.';
            $msgType = 'error';
            $currentStep = 'email';
        }
    }
}

// Resend OTP (with a 60-second cooldown)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_reset_otp'])) {
    $email = $_SESSION['reset_email'] ?? '';

    if (empty($email)) {
        $msg = 'Session expired. Please start over.';
        $msgType = 'error';
        $currentStep = 'email';
    } else {
        $lastSent = (int)($_SESSION['otp_sent_at'] ?? 0);
        $elapsed = time() - $lastSent;

        if ($elapsed < 60) {
            $msg = 'Please wait ' . (60 - $elapsed) . ' seconds before requesting a new code.';
            $msgType = 'error';
            $currentStep = 'otp';
            $resendCooldown = 60 - $elapsed;
        } else {
            handle_password_reset_otp($email);
            $_SESSION['otp_sent_at'] = time();
            $msg = 'A new verification code has been sent to your email.';
            $msgType = 'success';
            $currentStep = 'otp';
            $resendCooldown = 60;
        }
    }
}

// STEP 2 - Verify the OTP (purpose = PASSWORD_RESET)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step_otp'])) {
    $email = $_SESSION['reset_email'] ?? '';
    $enteredOTP = trim($_POST['otp_code'] ?? '');

    if (empty($email)) {
        $msg = 'Session expired. Please start over.';
        $msgType = 'error';
        $currentStep = 'email';
        unset($_SESSION['reset_email'], $_SESSION['reset_flow']);
    } else {
        $storedOTP = $_SESSION['otp_data']['code'] ?? '';
        $otpExpiry = $_SESSION['otp_data']['expiry'] ?? 0;
        $otpPurpose = $_SESSION['otp_data']['purpose'] ?? '';
        $otpEmail = $_SESSION['otp_data']['email'] ?? '';

        $valid = !empty($enteredOTP)
            && $otpEmail === $email
            && $otpPurpose === 'password_reset'
            && time() <= $otpExpiry
            && hash_equals((string)$storedOTP, (string)$enteredOTP);

        if ($valid) {
            // Prevent OTP reuse by invalidating it immediately
            unset($_SESSION['otp_data']);
            unset($_SESSION['debug_otp']);
            $_SESSION['reset_flow'] = 'otp_verified';
            $msg = 'Verification successful. Please set your new password.';
            $msgType = 'success';
            $currentStep = 'password';
        } else {
            $msg = 'Invalid or expired verification code.';
            $msgType = 'error';
            $currentStep = 'otp';
            $resendCooldown = isset($_SESSION['otp_sent_at']) ? max(0, 60 - (time() - (int)$_SESSION['otp_sent_at'])) : 0;
        }
    }
}

// STEP 3 - Set the new password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step_password'])) {
    $email = $_SESSION['reset_email'] ?? '';
    $verified = isset($_SESSION['reset_flow']) && $_SESSION['reset_flow'] === 'otp_verified';

    if (empty($email) || !$verified) {
        $msg = 'Session expired. Please start over.';
        $msgType = 'error';
        $currentStep = 'email';
        unset($_SESSION['reset_email'], $_SESSION['reset_flow']);
    } else {
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        $passwordErrors = validate_password_strength($password);
        if (!empty($passwordErrors)) {
            $msg = 'Password must contain: ' . implode(', ', $passwordErrors);
            $msgType = 'error';
            $currentStep = 'password';
        } elseif ($password !== $confirm_password) {
            $msg = 'Passwords do not match.';
            $msgType = 'error';
            $currentStep = 'password';
        } else {
            try {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                // Update the password and reset the account lockout security state
                $stmt = $conn->prepare("UPDATE users SET password = ?, failed_attempts = 0, lock_until = NULL, lock_level = 0 WHERE email = ?");
                $stmt->bind_param("ss", $hashedPassword, $email);
                $stmt->execute();
                $stmt->close();

                // Invalidate the OTP and clear the reset session
                unset($_SESSION['otp_data']);
                unset($_SESSION['debug_otp']);
                unset($_SESSION['reset_email']);
                unset($_SESSION['reset_flow']);
                unset($_SESSION['otp_sent_at']);

                $msg = 'Your password has been successfully updated.';
                $msgType = 'success';
                $currentStep = 'success';
            } catch (Exception $e) {
                error_log("Password reset step 3 error: " . $e->getMessage());
                $msg = 'An error occurred. Please try again.';
                $msgType = 'error';
                $currentStep = 'password';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>LGU | Forgot Password</title>
    <link rel="icon" type="image/png" href="../assets/img/infra-gov-logo.png">
    <link rel="stylesheet" href="<?php echo $basePath; ?>styles/style.css" />
    <link rel="stylesheet" href="<?php echo $basePath; ?>styles/login.css" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  </head>

  <body>
    <header class="nav">
      <div class="nav-logo">🏛️ Local Government Unit Portal</div>
      <div class="nav-links">
        <a href="../index.php">Home</a>
      </div>
    </header>

    <div class="wrapper">
      <div class="card">
        <img src="<?php echo $basePath; ?>../assets/img/infra-gov-logo.png" class="icon-top" />
        <h2 class="title">Forgot Password</h2>
        <p class="subtitle">Reset your account password securely.</p>

        <?php if (!empty($msg)): ?>
          <div class="<?php echo $msgType === 'error' ? 'error-message' : 'success-message'; ?>" style="background: <?php echo $msgType === 'error' ? '#fee' : '#efe'; ?>; color: <?php echo $msgType === 'error' ? '#c33' : '#060'; ?>; padding: 10px; border-radius: 5px; margin-bottom: 15px;">
            <?php echo htmlspecialchars($msg); ?>
          </div>
        <?php endif; ?>

        <?php if ($currentStep === 'email'): ?>
          <!-- STEP 1: Enter registered email -->
          <form method="POST">
            <input type="hidden" name="step_email" value="1">
            <div class="input-box">
              <label>Registered Email Address</label>
              <input type="email" name="email" placeholder="name@lgu.gov.ph" value="<?php echo isset($_POST['email']) && isset($_POST['step_email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required />
              <span class="icon">📧</span>
            </div>
            <button class="btn-primary" type="submit">Send Verification Code</button>
            <p class="small-text">
              <a href="login.php" class="link">Back to Login</a>
            </p>
          </form>

        <?php elseif ($currentStep === 'otp'): ?>
          <!-- STEP 2: Enter OTP -->
          <form method="POST">
            <input type="hidden" name="step_otp" value="1">
            <div class="input-box">
              <label>Verification Code</label>
              <input type="text" name="otp_code" id="resetOtpCode" maxlength="6" placeholder="000000" class="otp-input" autocomplete="off" required />
            </div>
            <button class="btn-primary" type="submit">Verify Code</button>
          </form>

          <form method="POST" class="resend-form">
            <div class="otp-resend">
              <span>Didn't receive the code?</span>
              <button type="submit" name="resend_reset_otp" id="resendResetBtn" class="link-btn">Resend OTP</button>
            </div>
          </form>

          <p class="small-text">
            <a href="forgot_password.php?restart=1" class="link">Start Over</a> ·
            <a href="login.php" class="link">Back to Login</a>
          </p>

        <?php elseif ($currentStep === 'password'): ?>
          <!-- STEP 3: New password + confirm -->
          <form method="POST">
            <input type="hidden" name="step_password" value="1">
            <div class="input-box">
              <label>New Password</label>
              <input type="password" name="password" id="resetPassword" placeholder="•••••••" autocomplete="new-password" required />
              <button type="button" class="password-toggle" onclick="togglePassword('resetPassword', this)" tabindex="-1"><i class="fas fa-eye"></i></button>
              <span class="icon">🔒</span>
            </div>
            <div class="input-box">
              <label>Confirm Password</label>
              <input type="password" name="confirm_password" id="resetConfirmPassword" placeholder="•••••••" autocomplete="new-password" required />
              <button type="button" class="password-toggle" onclick="togglePassword('resetConfirmPassword', this)" tabindex="-1"><i class="fas fa-eye"></i></button>
              <span class="icon">🔒</span>
            </div>

            <ul class="password-checklist" id="resetPasswordChecklist" aria-live="polite">
              <li data-check="length"><i class="fas fa-circle"></i> Minimum 8 characters</li>
              <li data-check="uppercase"><i class="fas fa-circle"></i> Uppercase letter</li>
              <li data-check="lowercase"><i class="fas fa-circle"></i> Lowercase letter</li>
              <li data-check="number"><i class="fas fa-circle"></i> Number</li>
              <li data-check="special"><i class="fas fa-circle"></i> Special character</li>
              <li data-check="no-space"><i class="fas fa-circle"></i> No spaces</li>
              <li data-check="match"><i class="fas fa-circle"></i> Passwords match</li>
            </ul>

            <button class="btn-primary" type="submit">Update Password</button>
            <p class="small-text">
              <a href="login.php" class="link">Back to Login</a>
            </p>
          </form>

        <?php elseif ($currentStep === 'success'): ?>
          <!-- SUCCESS -->
          <div class="success-message" style="background: #efe; color: #060; padding: 10px; border-radius: 5px; margin-bottom: 15px;">
            <?php echo htmlspecialchars($msg); ?>
          </div>
          <p class="small-text">
            Redirecting to the login page...
          </p>
          <p class="small-text">
            <a href="login.php" class="link">Go to Login</a>
          </p>
        <?php endif; ?>
      </div>
    </div>

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

    <style>
      .password-toggle {
        position: absolute;
        right: 38px;
        top: 45px;
        transform: translateY(-50%);
        background: none;
        border: none;
        cursor: pointer;
        color: #888;
        padding: 4px;
        font-size: 16px;
        line-height: 1;
        z-index: 2;
        transition: color 0.2s;
      }
      .password-toggle:hover {
        color: #333;
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

      .otp-resend {
        margin-top: 15px;
        font-size: 13px;
        color: #666;
        text-align: center;
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
      .link-btn:disabled {
        color: #999;
        cursor: not-allowed;
      }

      .resend-form {
        display: inline;
      }

      /* Password strength checklist */
      .password-checklist {
        list-style: none;
        margin: 2px 0 10px 0;
        padding: 10px 12px;
        text-align: left;
        background: rgba(255, 255, 255, 0.55);
        border-radius: 10px;
        font-size: 12px;
        display: none;
      }
      .password-checklist.show {
        display: block;
      }
      .password-checklist li {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #999;
        margin-bottom: 4px;
        transition: color 0.2s;
      }
      .password-checklist li:last-child {
        margin-bottom: 0;
      }
      .password-checklist li i {
        font-size: 13px;
        color: #ccc;
      }
      .password-checklist li.valid {
        color: #16a34a;
      }
      .password-checklist li.valid i {
        color: #16a34a;
      }
      .password-checklist li.invalid {
        color: #c0392b;
      }
      .password-checklist li.invalid i {
        color: #c0392b;
      }
    </style>

    <script>
      function togglePassword(inputId, btn) {
        var input = document.getElementById(inputId);
        if (input.type === 'password') {
          input.type = 'text';
          btn.querySelector('i').className = 'fas fa-eye-slash';
        } else {
          input.type = 'password';
          btn.querySelector('i').className = 'fas fa-eye';
        }
      }

      function setCheckState(item, state) {
        var icon = item.querySelector('i');
        item.classList.remove('valid', 'invalid');
        if (state === 'valid') {
          item.classList.add('valid');
          icon.className = 'fas fa-check-circle';
        } else if (state === 'invalid') {
          item.classList.add('invalid');
          icon.className = 'fas fa-times-circle';
        } else {
          icon.className = 'fas fa-circle';
        }
      }

      function validatePasswordStrength() {
        var password = document.getElementById('resetPassword');
        var confirm = document.getElementById('resetConfirmPassword');
        var checklist = document.getElementById('resetPasswordChecklist');
        if (!password || !checklist) return;

        var value = password.value;
        var hasValue = value.length > 0;
        var confirmValue = confirm ? confirm.value : '';
        var hasConfirm = confirmValue.length > 0;

        var rules = {
          length: value.length >= 8,
          uppercase: /[A-Z]/.test(value),
          lowercase: /[a-z]/.test(value),
          number: /[0-9]/.test(value),
          special: /[^A-Za-z0-9]/.test(value),
          'no-space': !/\s/.test(value)
        };

        for (var key in rules) {
          var item = checklist.querySelector('[data-check="' + key + '"]');
          if (!item) continue;
          setCheckState(item, !hasValue ? 'neutral' : (rules[key] ? 'valid' : 'invalid'));
        }

        var matchItem = checklist.querySelector('[data-check="match"]');
        if (matchItem) {
          if (!hasValue || !hasConfirm) {
            setCheckState(matchItem, 'neutral');
          } else {
            setCheckState(matchItem, value === confirmValue ? 'valid' : 'invalid');
          }
        }

        if (hasValue) {
          checklist.classList.add('show');
        } else if (!checklist.matches(':focus-within')) {
          checklist.classList.remove('show');
        }
      }

      function startResendCountdown(seconds) {
        var btn = document.getElementById('resendResetBtn');
        if (!btn) return;
        var remaining = seconds;
        btn.disabled = true;
        var original = btn.textContent;
        var timer = setInterval(function() {
          if (remaining <= 0) {
            clearInterval(timer);
            btn.disabled = false;
            btn.textContent = original;
          } else {
            btn.textContent = 'Resend OTP (' + remaining + 's)';
            remaining--;
          }
        }, 1000);
      }

      document.addEventListener('DOMContentLoaded', function() {
        var resetPassword = document.getElementById('resetPassword');
        var resetConfirmPassword = document.getElementById('resetConfirmPassword');
        var checklist = document.getElementById('resetPasswordChecklist');

        if (resetPassword) {
          resetPassword.addEventListener('input', validatePasswordStrength);
          resetPassword.addEventListener('focus', function() {
            if (checklist) checklist.classList.add('show');
          });
          resetPassword.addEventListener('blur', function() {
            if (checklist && resetPassword.value.length === 0) {
              checklist.classList.remove('show');
            }
          });
        }
        if (resetConfirmPassword) {
          resetConfirmPassword.addEventListener('input', validatePasswordStrength);
          resetConfirmPassword.addEventListener('focus', function() {
            if (checklist) checklist.classList.add('show');
          });
          resetConfirmPassword.addEventListener('blur', function() {
            if (checklist && resetPassword && resetPassword.value.length === 0) {
              checklist.classList.remove('show');
            }
          });
        }
        validatePasswordStrength();

        document.getElementById('resetOtpCode')?.addEventListener('input', function(e) {
          this.value = this.value.replace(/[^0-9]/g, '');
        });

        var cooldown = <?php echo (int)$resendCooldown; ?>;
        if (cooldown > 0) startResendCountdown(cooldown);

        <?php if ($currentStep === 'success'): ?>
        setTimeout(function() { window.location.href = 'login.php'; }, 3000);
        <?php endif; ?>
      });
    </script>
  </body>
</html>
