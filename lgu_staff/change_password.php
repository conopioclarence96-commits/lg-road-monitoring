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

// Must be authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . $basePath . 'lgu_staff/login.php');
    exit();
}

// Load the current user
$user = null;
if ($conn && isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("SELECT id, email, full_name, role, password, must_change_password FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$user) {
    // Account no longer exists
    lgu_logout_current_session();
    header('Location: ' . $basePath . 'lgu_staff/login.php');
    exit();
}

// Role-based dashboard URL helper
function cp_dashboard_url($role, $basePath) {
    switch ($role) {
        case 'system_admin':
            return $basePath . 'lgu_staff/pages/admin/admin_dashboard.php';
        default:
            return $basePath . 'lgu_staff/pages/lgu/lgu_staff_dashboard.php';
    }
}

// Users who are not required to change their password should not be here
if (empty($user['must_change_password'])) {
    header('Location: ' . cp_dashboard_url($user['role'], $basePath));
    exit();
}

$msg = '';
$msgType = '';
$passwordChanged = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($current_password) || empty($password) || empty($confirm_password)) {
        $msg = 'Please fill in all fields.';
        $msgType = 'error';
    } elseif (!password_verify($current_password, $user['password'])) {
        $msg = 'Current password is incorrect.';
        $msgType = 'error';
    } else {
        $passwordErrors = validate_password_strength($password);
        if (!empty($passwordErrors)) {
            $msg = 'Password must contain: ' . implode(', ', $passwordErrors);
            $msgType = 'error';
        } elseif (password_verify($password, $user['password'])) {
            // The new password must not be the same as the current temporary password
            $msg = 'New password cannot be the same as your temporary password.';
            $msgType = 'error';
        } elseif ($password !== $confirm_password) {
            $msg = 'Passwords do not match.';
            $msgType = 'error';
        } else {
            try {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $update = $conn->prepare("UPDATE users SET password = ?, must_change_password = 0, password_changed_at = CURRENT_TIMESTAMP WHERE id = ?");
                $update->bind_param("si", $hashedPassword, $user['id']);
                $update->execute();
                $update->close();

                log_audit_action($user['id'], 'Password Changed', 'Password changed (forced temporary password replacement).');

                $msg = 'Your password has been successfully updated.';
                $msgType = 'success';
                $passwordChanged = true;
            } catch (Exception $e) {
                error_log("Change password error: " . $e->getMessage());
                $msg = 'An error occurred. Please try again.';
                $msgType = 'error';
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
    <title>LGU | Change Password</title>
    <link rel="icon" type="image/png" href="../assets/img/logocityhall.png">
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
        <img src="<?php echo $basePath; ?>../assets/img/logocityhall.png" class="icon-top" />
        <h2 class="title">Change Password</h2>
        <p class="subtitle">You must change your temporary password before accessing the dashboard.</p>

        <?php if (!empty($msg)): ?>
          <div class="<?php echo $msgType === 'error' ? 'error-message' : 'success-message'; ?>" style="background: <?php echo $msgType === 'error' ? '#fee' : '#efe'; ?>; color: <?php echo $msgType === 'error' ? '#c33' : '#060'; ?>; padding: 10px; border-radius: 5px; margin-bottom: 15px;">
            <?php echo htmlspecialchars($msg); ?>
          </div>
        <?php endif; ?>

        <?php if ($passwordChanged): ?>
          <p class="small-text">
            Redirecting to your dashboard...
          </p>
          <p class="small-text">
            <a href="<?php echo htmlspecialchars(cp_dashboard_url($user['role'], $basePath)); ?>" class="link">Go to Dashboard</a>
          </p>
        <?php else: ?>
        <form method="POST">
          <input type="hidden" name="change_password" value="1">

          <div class="input-box">
            <label>Current Password</label>
            <input type="password" name="current_password" id="currentPassword" placeholder="•••••••" autocomplete="current-password" required />
            <button type="button" class="password-toggle" onclick="togglePassword('currentPassword', this)" tabindex="-1"><i class="fas fa-eye"></i></button>
            <span class="icon">🔒</span>
          </div>

          <div class="input-box">
            <label>New Password</label>
            <input type="password" name="password" id="newPassword" placeholder="•••••••" autocomplete="new-password" required />
            <button type="button" class="password-toggle" onclick="togglePassword('newPassword', this)" tabindex="-1"><i class="fas fa-eye"></i></button>
            <span class="icon">🔒</span>
          </div>

          <div class="input-box">
            <label>Confirm Password</label>
            <input type="password" name="confirm_password" id="confirmPassword" placeholder="•••••••" autocomplete="new-password" required />
            <button type="button" class="password-toggle" onclick="togglePassword('confirmPassword', this)" tabindex="-1"><i class="fas fa-eye"></i></button>
            <span class="icon">🔒</span>
          </div>

          <ul class="password-checklist" id="passwordChecklist" aria-live="polite">
            <li data-check="length"><i class="fas fa-circle"></i> Minimum 8 characters</li>
            <li data-check="uppercase"><i class="fas fa-circle"></i> Uppercase letter</li>
            <li data-check="lowercase"><i class="fas fa-circle"></i> Lowercase letter</li>
            <li data-check="number"><i class="fas fa-circle"></i> Number</li>
            <li data-check="special"><i class="fas fa-circle"></i> Special character</li>
            <li data-check="no-space"><i class="fas fa-circle"></i> No spaces</li>
            <li data-check="match"><i class="fas fa-circle"></i> Passwords match</li>
          </ul>

          <button class="btn-primary" type="submit">Update Password</button>
        </form>
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
        var password = document.getElementById('newPassword');
        var confirm = document.getElementById('confirmPassword');
        var checklist = document.getElementById('passwordChecklist');
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

      document.addEventListener('DOMContentLoaded', function() {
        var newPassword = document.getElementById('newPassword');
        var confirmPassword = document.getElementById('confirmPassword');
        var checklist = document.getElementById('passwordChecklist');

        if (newPassword) {
          newPassword.addEventListener('input', validatePasswordStrength);
          newPassword.addEventListener('focus', function() {
            if (checklist) checklist.classList.add('show');
          });
          newPassword.addEventListener('blur', function() {
            if (checklist && newPassword.value.length === 0) {
              checklist.classList.remove('show');
            }
          });
        }
        if (confirmPassword) {
          confirmPassword.addEventListener('input', validatePasswordStrength);
          confirmPassword.addEventListener('focus', function() {
            if (checklist) checklist.classList.add('show');
          });
          confirmPassword.addEventListener('blur', function() {
            if (checklist && newPassword && newPassword.value.length === 0) {
              checklist.classList.remove('show');
            }
          });
        }
        validatePasswordStrength();

        <?php if ($passwordChanged): ?>
        setTimeout(function() {
          window.location.href = '<?php echo htmlspecialchars(cp_dashboard_url($user['role'], $basePath)); ?>';
        }, 2500);
        <?php endif; ?>
      });
    </script>
  </body>
</html>
