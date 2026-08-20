<?php
// ============================================
// FILE: functions.php
// PROJECT: daboreytextnote
// ============================================

require_once __DIR__ . '/config.php';

// Dynamically determine BASE_URL if not defined to avoid path errors
if (!defined('BASE_URL')) {
    $script_dir = dirname($_SERVER['SCRIPT_NAME']);
    define('BASE_URL', rtrim($script_dir, '/\\'));
}

// Generate CSRF token
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Verify CSRF token
function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Sanitize string output
function sanitize($data) {
    return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
}

// Log audit events into SQLite database
function log_sqlite_event($db, $username, $event_type) {
    try {
        $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        if (strpos($ip_address, ',') !== false) {
            $ip_address = trim(explode(',', $ip_address)[0]);
        }
        $stmt = $db->prepare("INSERT INTO system_logs (username, event_type, ip_address) VALUES (?, ?, ?)");
        $stmt->execute([$username, $event_type, $ip_address]);
    } catch (Exception $e) {
        error_log("Logging exception: " . $e->getMessage());
    }
}
?>