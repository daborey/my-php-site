<?php
// ============================================
// FILE: config.php
// PROJECT: daboreystep2
// ============================================

if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    die('Direct access forbidden.');
}

// Secure session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Strict');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Global System Constants
define('APP_NAME', 'Daborey Step 2');
define('BASE_URL', '/daboreystep2');
define('DB_PATH', __DIR__ . '/database.sqlite');
define('SESSION_TIMEOUT_SECONDS', 900); // 15 Minutes

// Set default timezone
date_default_timezone_set('Asia/Phnom_Penh');

// Initialize Database Connection
try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}