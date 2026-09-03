<?php
// ============================================
// FILE: daboreysearch/security.php
// ============================================
require_once 'config.php';
require_once 'functions.php';

// Sanitize input
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Validate URL
function validate_url($url) {
    return filter_var($url, FILTER_VALIDATE_URL);
}

// Generate CSRF token
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Validate CSRF token
function validate_csrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Rate limiting for searches
function check_search_rate_limit($max_attempts = 20, $time_window = 300) {
    if (!isset($_SESSION['search_rate_limit'])) {
        $_SESSION['search_rate_limit'] = [];
    }
    
    $now = time();
    $attempts = $_SESSION['search_rate_limit'];
    
    // Remove old attempts
    $attempts = array_filter($attempts, function($time) use ($now, $time_window) {
        return $now - $time < $time_window;
    });
    
    if (count($attempts) >= $max_attempts) {
        return false;
    }
    
    $attempts[] = $now;
    $_SESSION['search_rate_limit'] = $attempts;
    return true;
}

// Redirect helper
function redirect($url) {
    header("Location: $url");
    exit();
}

// Get base URL
function get_base_url() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    return $protocol . '://' . $host . '/daboreysearch/';
}
?>