<?php
// ============================================
// FILE: daboreystep2/config.php
// ============================================

// Production error display settings
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Secure Session Configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}

// Global System Constants
define('APP_NAME', 'Daborey Step 2');
define('BASE_URL', '/daboreystep2');
define('SESSION_TIMEOUT_SECONDS', 900); // 15 Minutes

// Dedicated SQLite database file stored in persistent storage
$db_file = '/mnt/storage/daboreystep2.db';

try {
    $db = new PDO('sqlite:' . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Create dedicated users table with recovery and 2FA fields
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        twofa_secret TEXT NULL,
        remember_token TEXT,
        reset_token TEXT,
        reset_expires DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Create dedicated notes table
    $db->exec("CREATE TABLE IF NOT EXISTS notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        title TEXT,
        content TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Create dedicated system logs table
    $db->exec("CREATE TABLE IF NOT EXISTS system_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT,
        event_type TEXT,
        ip_address TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Site configuration variable
$site_name = "Daborey Step 2";
?>