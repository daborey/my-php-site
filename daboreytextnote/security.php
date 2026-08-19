<?php
// ============================================
// FILE: security.php
// ============================================
require_once 'config.php';
require_once 'functions.php';

// Sanitize input
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Validate email
function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Validate username
function validate_username($username) {
    return preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username);
}

// Validate password strength
function validate_password($password) {
    return strlen($password) >= 6;
}

// Rate limiting
function check_rate_limit($key, $max_attempts = 5, $time_window = 300) {
    if (!isset($_SESSION['rate_limit'])) {
        $_SESSION['rate_limit'] = [];
    }
    
    $now = time();
    $attempts = $_SESSION['rate_limit'][$key] ?? [];
    
    // Remove old attempts
    $attempts = array_filter($attempts, function($time) use ($now, $time_window) {
        return $now - $time < $time_window;
    });
    
    if (count($attempts) >= $max_attempts) {
        return false;
    }
    
    $attempts[] = $now;
    $_SESSION['rate_limit'][$key] = $attempts;
    return true;
}

// Password reset token
function generate_reset_token() {
    return bin2hex(random_bytes(32));
}
?>