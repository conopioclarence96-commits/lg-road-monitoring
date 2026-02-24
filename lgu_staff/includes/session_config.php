<?php
// Session configuration - must be included before any session_start()
if (session_status() === PHP_SESSION_NONE) {
    // Session ini settings (must be set before session_start)
    ini_set('session.cookie_path', '/');
    ini_set('session.cookie_domain', '');
    ini_set('session.cookie_httponly', true);
    ini_set('session.use_strict_mode', true);
    ini_set('session.cookie_samesite', 'Lax');
    
    session_start();
}
?>
