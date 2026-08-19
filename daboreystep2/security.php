<?php
// ============================================
// FILE: security.php
// PROJECT: daboreystep2
// ============================================

// 1. MUST load config.php FIRST so BASE_URL is defined before anything else
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Set standard security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Guard function to demand active login
function require_login() {
    if (!isset($_SESSION['user_id'])) {
        $login_url = defined('BASE_URL') ? BASE_URL . '/login.php' : '/login.php';
        header("Location: " . $login_url);
        exit;
    }
}

// Guard function to enforce active 2FA verification if configured
function require_2fa_if_enabled($db) {
    require_login();
    
    $stmt = $db->prepare("SELECT twofa_secret FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!empty($user['twofa_secret']) && empty($_SESSION['2fa_verified'])) {
        $dashboard_url = defined('BASE_URL') ? BASE_URL . '/dashboard.php' : '/dashboard.php';
        header("Location: " . $dashboard_url);
        exit;
    }
}