<?php
// ============================================
// FILE: daboreytextnote/functions.php
// ============================================
require_once 'config.php';

// Register user
function register($username, $password) {
    global $db;
    
    if (strlen($username) < 3) return false;
    if (strlen($password) < 6) return false;
    
    try {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
        return $stmt->execute([$username, $hashed]);
    } catch (PDOException $e) {
        return false;
    }
}

// Login user
function login($username, $password) {
    global $db;
    
    $stmt = $db->prepare("SELECT id, username, password FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        return true;
    }
    return false;
}

// Check if logged in
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

// Logout cleanly
function logout() {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    return true;
}

// Get current user
function current_user() {
    global $db;
    
    if (!is_logged_in()) return null;
    
    $stmt = $db->prepare("SELECT id, username FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
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

// Redirect helper
function redirect($url) {
    header("Location: $url");
    exit();
}
?>