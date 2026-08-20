<?php
// ============================================
// FILE: daboreytextnote/functions.php
// PROJECT: daboreytextnote (Protocol matched to daboreystep2)
// ============================================

require_once __DIR__ . '/config.php';

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
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
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

// Base32 Decoder for TOTP
function base32_decode($base32) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $base32 = strtoupper($base32);
    $binary = '';
    for ($i = 0; $i < strlen($base32); $i++) {
        $char = $base32[$i];
        $pos = strpos($alphabet, $char);
        if ($pos !== false) {
            $binary .= sprintf('%05b', $pos);
        }
    }
    $bytes = '';
    for ($i = 0; $i < strlen($binary); $i += 8) {
        if ($i + 8 <= strlen($binary)) {
            $bytes .= chr(bindec(substr($binary, $i, 8)));
        }
    }
    return $bytes;
}

// Verify 2FA TOTP Token
function verify_totp_token($secret, $token, $window = 1) {
    $secret_bytes = base32_decode($secret);
    $time_step = 30;
    $current_time = floor(time() / $time_step);

    for ($i = -$window; $i <= $window; $i++) {
        $time_counter = pack('N*', 0) . pack('N*', $current_time + $i);
        $hash = hash_hmac('sha1', $time_counter, $secret_bytes, true);
        $offset = ord($hash[19]) & 0x0F;
        $otp_code = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % 1000000;
        
        $padded_code = str_pad($otp_code, 6, '0', STR_PAD_LEFT);
        if (hash_equals($padded_code, (string)$token)) {
            return true;
        }
    }
    return false;
}

// ============================================
// REQUIRED DABOREYTEXTNOTE APP HELPERS
// ============================================

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function current_user() {
    global $db;
    if (!is_logged_in()) return null;
    $stmt = $db->prepare("SELECT id, username FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

function redirect($url) {
    header("Location: $url");
    exit();
}

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

function register($username, $password) {
    global $db;
    if (strlen($username) < 3 || strlen($password) < 6) return false;
    try {
        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
        return $stmt->execute([$username, $hashed]);
    } catch (Exception $e) {
        return false;
    }
}

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