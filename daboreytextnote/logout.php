<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Log SQLite Logout Event before destroying session
if (isset($_SESSION['username'])) {
    try {
        $db = new PDO('sqlite:' . __DIR__ . '/database.sqlite');
        $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        if (strpos($ip_address, ',') !== false) {
            $ip_address = trim(explode(',', $ip_address)[0]);
        }
        $stmt = $db->prepare("INSERT INTO system_logs (username, event_type, ip_address) VALUES (?, ?, ?)");
        $stmt->execute([$_SESSION['username'], 'USER_LOGOUT', $ip_address]);
    } catch (Exception $e) {
        error_log("Logging exception: " . $e->getMessage());
    }
}

// Unset all session variables
$_SESSION = array();

// Destroy session cookie if present
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session completely
session_destroy();

// Redirect back to login page relative to Cloud Run URL root
header("Location: login.php");
exit;