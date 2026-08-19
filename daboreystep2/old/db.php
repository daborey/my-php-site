<?php
// Cloud Run production settings
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1); 
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}

// Cloud Run SQLite path with persistent storage
$dbPath = '/mnt/storage/daboreypass.db';

try {
    $conn = new SQLite3($dbPath);
    $conn->enableExceptions(true);
    
    $conn->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            login_attempts INTEGER DEFAULT 0,
            lock_until DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            action_performed TEXT NOT NULL,
            network_ip TEXT NOT NULL,
            logged_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS two_factor_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            service_name TEXT NOT NULL,
            secret_seed TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
    ");
    
} catch (Exception $e) {
    die("System connection exception. Check internal system logs.");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function log_system_event($conn, $username, $action) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN_IP';
    $stmt = $conn->prepare("INSERT INTO audit_logs (username, action_performed, network_ip) VALUES (?, ?, ?)");
    $stmt->bindValue(1, $username, SQLITE3_TEXT);
    $stmt->bindValue(2, $action, SQLITE3_TEXT);
    $stmt->bindValue(3, $ip, SQLITE3_TEXT);
    $stmt->execute();
}

function execute_query($conn, $sql, $params = []) {
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception("Unable to prepare statement: " . $conn->lastErrorMsg());
    }
    
    foreach ($params as $key => $value) {
        $type = is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT;
        $stmt->bindValue($key, $value, $type);
    }
    
    $result = $stmt->execute();
    return $result;
}

function fetch_one($result) {
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row;
}

function fetch_all($result) {
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}
?>