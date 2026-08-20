<?php
// ============================================
// FILE: functions.php
// PROJECT: daboreytextnote
// ============================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';

// Prevent header injection / blank page issues on Cloud Run
if (!defined('BASE_URL')) {
    $script_dir = dirname($_SERVER['SCRIPT_NAME']);
    define('BASE_URL', rtrim($script_dir, '/\\'));
}

function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

function sanitize($data) {
    return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
}

function log_sqlite_event($db, $username, $event_type) {
    try {
        $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        if (strpos($ip_address, ',') !== false) {
            $ip_address = trim(explode(',', $ip_address)[0]);
        }
        $stmt = $db->prepare("INSERT INTO system_logs (username, event_type, ip_address) VALUES (?, ?, ?)");
        $stmt->execute([$username, $event_type, $ip_address]);
    } catch (Throwable $e) {
        error_log("Logging exception: " . $e->getMessage());
    }
}
?>