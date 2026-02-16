<?php
/**
 * Authentication API Endpoints
 * Handles user authentication, session management, and authorization
 */

require_once '../config/database.php';

// Set CORS headers
setCorsHeaders();

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = new DBHelper();
    
    switch ($method) {
        case 'POST':
            handlePostRequests($db);
            break;
        case 'GET':
            handleGetRequests($db);
            break;
        case 'DELETE':
            handleDeleteRequests($db);
            break;
        default:
            jsonResponse(['error' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    logError('Auth API Error: ' . $e->getMessage());
    jsonResponse(['error' => 'Internal server error'], 500);
}

/**
 * Handle POST requests
 */
function handlePostRequests($db) {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'login':
            handleLogin($db);
            break;
        case 'logout':
            handleLogout($db);
            break;
        case 'register':
            handleRegistration($db);
            break;
        case 'reset_password':
            handlePasswordReset($db);
            break;
        default:
            jsonResponse(['error' => 'Invalid action'], 400);
    }
}

/**
 * Handle GET requests
 */
function handleGetRequests($db) {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'validate_session':
            validateSession($db);
            break;
        case 'user_profile':
            getUserProfile($db);
            break;
        case 'notifications':
            getUserNotifications($db);
            break;
        default:
            jsonResponse(['error' => 'Invalid action'], 400);
    }
}

/**
 * Handle DELETE requests
 */
function handleDeleteRequests($db) {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'logout':
            handleLogout($db);
            break;
        default:
            jsonResponse(['error' => 'Invalid action'], 400);
    }
}

/**
 * Handle user login
 */
function handleLogin($db) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        jsonResponse(['error' => 'Username and password are required'], 400);
    }
    
    try {
        // Authenticate user
        $user = $db->authenticateUser($username, $password);
        
        if (!$user) {
            jsonResponse(['error' => 'Invalid username or password'], 401);
        }
        
        if (!$user['is_active']) {
            jsonResponse(['error' => 'Account is deactivated'], 401);
        }
        
        // Create session
        $sessionId = generateSecureToken();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+8 hours')); // 8 hour session
        
        $sql = "INSERT INTO user_sessions (session_id, user_id, ip_address, user_agent, expires_at)
                VALUES (?, ?, ?, ?, ?)";
        
        $db->prepare($sql);
        $db->bind(1, $sessionId);
        $db->bind(2, $user['user_id']);
        $db->bind(3, $_SERVER['REMOTE_ADDR'] ?? '');
        $db->bind(4, $_SERVER['HTTP_USER_AGENT'] ?? '');
        $db->bind(5, $expiresAt);
        
        $db->execute();
        
        // Update last login
        $sql = "UPDATE staff_users SET last_login = NOW() WHERE user_id = ?";
        $db->prepare($sql);
        $db->bind(1, $user['user_id']);
        $db->execute();
        
        // Log activity
        $db->logActivity($user['user_id'], 'LOGIN', 'staff_users', $user['user_id']);
        
        jsonResponse([
            'success' => true,
            'user' => [
                'user_id' => $user['user_id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'role' => $user['role'],
                'department' => $user['department'],
                'last_login' => $user['last_login']
            ],
            'session_id' => $sessionId,
            'expires_at' => $expiresAt
        ]);
    } catch (Exception $e) {
        logError('Login error: ' . $e->getMessage());
        jsonResponse(['error' => 'Login failed'], 500);
    }
}

/**
 * Handle user logout
 */
function handleLogout($db) {
    $sessionId = $_POST['session_id'] ?? $_GET['session_id'] ?? '';
    
    if (empty($sessionId)) {
        jsonResponse(['error' => 'No session provided'], 400);
    }
    
    try {
        // Get session info before deletion for logging
        $sql = "SELECT user_id FROM user_sessions WHERE session_id = ? AND is_active = TRUE";
        $db->prepare($sql);
        $db->bind(1, $sessionId);
        $session = $db->single();
        
        // Deactivate session
        $sql = "UPDATE user_sessions SET is_active = FALSE WHERE session_id = ?";
        $db->prepare($sql);
        $db->bind(1, $sessionId);
        $db->execute();
        
        if ($session) {
            // Log activity
            $db->logActivity($session['user_id'], 'LOGOUT', 'staff_users', $session['user_id']);
        }
        
        jsonResponse(['success' => true]);
    } catch (Exception $e) {
        logError('Logout error: ' . $e->getMessage());
        jsonResponse(['error' => 'Logout failed'], 500);
    }
}

/**
 * Handle user registration (admin only)
 */
function handleRegistration($db) {
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';
    $firstName = $_POST['first_name'] ?? '';
    $lastName = $_POST['last_name'] ?? '';
    $role = $_POST['role'] ?? 'staff';
    $phone = $_POST['phone'] ?? '';
    $registeredBy = $_POST['registered_by'] ?? '';
    
    $required = ['username', 'email', 'first_name', 'last_name', 'role', 'registered_by'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            jsonResponse(['error' => "Missing required field: $field"], 400);
        }
    }
    
    // Validate role
    $validRoles = ['admin', 'supervisor', 'staff', 'technician'];
    if (!in_array($role, $validRoles)) {
        jsonResponse(['error' => 'Invalid role'], 400);
    }
    
    try {
        // Check if username or email already exists
        $sql = "SELECT user_id FROM staff_users WHERE username = ? OR email = ?";
        $db->prepare($sql);
        $db->bind(1, $username);
        $db->bind(2, $email);
        $existing = $db->single();
        
        if ($existing) {
            jsonResponse(['error' => 'Username or email already exists'], 409);
        }
        
        // Generate temporary password
        $tempPassword = generateSecureToken(12);
        $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
        
        // Create user
        $sql = "INSERT INTO staff_users 
                (username, password_hash, email, first_name, last_name, role, phone, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, TRUE)";
        
        $db->prepare($sql);
        $db->bind(1, $username);
        $db->bind(2, $passwordHash);
        $db->bind(3, $email);
        $db->bind(4, $firstName);
        $db->bind(5, $lastName);
        $db->bind(6, $role);
        $db->bind(7, $phone);
        
        $db->execute();
        $userId = $db->lastInsertId();
        
        // Log activity
        $db->logActivity($registeredBy, 'INSERT', 'staff_users', $userId);
        
        jsonResponse([
            'success' => true,
            'user_id' => $userId,
            'temp_password' => $tempPassword,
            'message' => 'User created successfully. Temporary password: ' . $tempPassword
        ]);
    } catch (Exception $e) {
        logError('Registration error: ' . $e->getMessage());
        jsonResponse(['error' => 'Registration failed'], 500);
    }
}

/**
 * Handle password reset
 */
function handlePasswordReset($db) {
    $email = $_POST['email'] ?? '';
    
    if (empty($email)) {
        jsonResponse(['error' => 'Email is required'], 400);
    }
    
    try {
        // Find user by email
        $sql = "SELECT user_id, username, first_name, last_name FROM staff_users WHERE email = ? AND is_active = TRUE";
        $db->prepare($sql);
        $db->bind(1, $email);
        $user = $db->single();
        
        if (!$user) {
            jsonResponse(['error' => 'Email not found'], 404);
        }
        
        // Generate reset token
        $resetToken = generateSecureToken(32);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Store reset token (you might want to create a separate table for this)
        $sql = "UPDATE staff_users SET password_hash = ? WHERE user_id = ?";
        $db->prepare($sql);
        $db->bind(1, 'RESET:' . $resetToken . ':' . $expiresAt); // Temporary format
        $db->bind(2, $user['user_id']);
        $db->execute();
        
        // Log activity
        $db->logActivity($user['user_id'], 'PASSWORD_RESET', 'staff_users', $user['user_id']);
        
        // In a real implementation, you would send an email here
        jsonResponse([
            'success' => true,
            'message' => 'Password reset instructions sent to email',
            'reset_token' => $resetToken // Only for development
        ]);
    } catch (Exception $e) {
        logError('Password reset error: ' . $e->getMessage());
        jsonResponse(['error' => 'Password reset failed'], 500);
    }
}

/**
 * Validate session
 */
function validateSession($db) {
    $sessionId = $_GET['session_id'] ?? '';
    
    if (empty($sessionId)) {
        jsonResponse(['error' => 'No session provided'], 400);
    }
    
    try {
        $sql = "SELECT us.*, su.username, su.email, su.first_name, su.last_name, su.role, su.department
                FROM user_sessions us
                JOIN staff_users su ON us.user_id = su.user_id
                WHERE us.session_id = ? 
                AND us.is_active = TRUE 
                AND us.expires_at > NOW()
                AND su.is_active = TRUE";
        
        $db->prepare($sql);
        $db->bind(1, $sessionId);
        $session = $db->single();
        
        if (!$session) {
            jsonResponse(['error' => 'Invalid or expired session'], 401);
        }
        
        jsonResponse([
            'success' => true,
            'user' => [
                'user_id' => $session['user_id'],
                'username' => $session['username'],
                'email' => $session['email'],
                'first_name' => $session['first_name'],
                'last_name' => $session['last_name'],
                'role' => $session['role'],
                'department' => $session['department']
            ]
        ]);
    } catch (Exception $e) {
        logError('Session validation error: ' . $e->getMessage());
        jsonResponse(['error' => 'Session validation failed'], 500);
    }
}

/**
 * Get user profile
 */
function getUserProfile($db) {
    $sessionId = $_GET['session_id'] ?? '';
    
    if (empty($sessionId)) {
        jsonResponse(['error' => 'No session provided'], 400);
    }
    
    try {
        // First validate session
        $validation = validateSession($db);
        if (!$validation['success']) {
            return $validation;
        }
        
        $userId = $validation['user']['user_id'];
        
        // Get detailed user profile
        $sql = "SELECT user_id, username, email, first_name, last_name, role, department, 
                phone, is_active, last_login, created_at
                FROM staff_users 
                WHERE user_id = ?";
        
        $db->prepare($sql);
        $db->bind(1, $userId);
        $profile = $db->single();
        
        // Get user statistics
        $sql = "SELECT 
                (SELECT COUNT(*) FROM verification_requests WHERE assigned_verifier = ?) as assigned_verifications,
                (SELECT COUNT(*) FROM road_incidents WHERE assigned_staff_id = ?) as assigned_incidents,
                (SELECT COUNT(*) FROM activity_logs WHERE user_id = ? AND DATE(timestamp) = CURDATE()) as today_activities";
        
        $db->prepare($sql);
        $db->bind(1, $userId);
        $db->bind(2, $userId);
        $db->bind(3, $userId);
        $stats = $db->single();
        
        jsonResponse([
            'profile' => $profile,
            'statistics' => $stats
        ]);
    } catch (Exception $e) {
        logError('Get profile error: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get profile'], 500);
    }
}

/**
 * Get user notifications
 */
function getUserNotifications($db) {
    $sessionId = $_GET['session_id'] ?? '';
    $unreadOnly = $_GET['unread_only'] ?? 'false';
    $limit = intval($_GET['limit'] ?? 20);
    
    if (empty($sessionId)) {
        jsonResponse(['error' => 'No session provided'], 400);
    }
    
    try {
        // First validate session
        $validation = validateSession($db);
        if (!$validation['success']) {
            return $validation;
        }
        
        $userId = $validation['user']['user_id'];
        
        $notifications = $db->getUserNotifications($userId, $unreadOnly === 'true');
        
        // Format notifications
        $formattedNotifications = [];
        foreach ($notifications as $notification) {
            $formattedNotifications[] = [
                'notification_id' => $notification['notification_id'],
                'title' => $notification['title'],
                'message' => $notification['message'],
                'type' => $notification['notification_type'],
                'related_table' => $notification['related_table'],
                'related_record_id' => $notification['related_record_id'],
                'is_read' => (bool) $notification['is_read'],
                'created_at' => $notification['created_at'],
                'read_at' => $notification['read_at'],
                'formatted_time' => formatTimeAgo($notification['created_at'])
            ];
        }
        
        jsonResponse($formattedNotifications);
    } catch (Exception $e) {
        logError('Get notifications error: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get notifications'], 500);
    }
}

/**
 * Helper functions
 */
function generateSecureToken($length = 128) {
    return bin2hex(random_bytes($length / 2));
}

function formatTimeAgo($timestamp) {
    $time = strtotime($timestamp);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . ' minutes ago';
    } elseif ($diff < 86400) {
        return floor($diff / 3600) . ' hours ago';
    } else {
        return date('M j, Y g:i A', $time);
    }
}

?>
