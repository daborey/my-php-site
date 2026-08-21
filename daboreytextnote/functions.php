<?php
// ============================================
// FILE: functions.php
// PROJECT: daboreytextnote
// ============================================

require_once __DIR__ . '/config.php';

// Safe CSRF Token Generation
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Validate CSRF Token
function validate_csrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

// Sanitize String Output
function sanitize($data) {
    return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
}

// Check standard password requirements
function validate_password($password) {
    return strlen($password) >= 6;
}

// Check user login state
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

// System Auditor Logger
function log_sqlite_event($db, $username, $event_type) {
    try {
        if (!$db) return;
        $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        if (strpos($ip_address, ',') !== false) {
            $ip_address = trim(explode(',', $ip_address)[0]);
        }
        $stmt = $db->prepare("INSERT INTO system_logs (username, event_type, ip_address) VALUES (?, ?, ?)");
        $stmt->execute([$username, $event_type, $ip_address]);
    } catch (Throwable $e) {
        error_log("Logging error: " . $e->getMessage());
    }
}

// Clean redirect helper
function redirect($url) {
    header("Location: " . $url);
    exit();
}
?>