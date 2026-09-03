<?php
// ============================================
// FILE: daboreysearch/config.php
// ============================================

// Development error display settings (enables error visibility)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Secure Session Configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}

// Separate SQLite database for search engine
$db_file = '/mnt/storage/daboreysearch.db';

try {
    $db = new PDO('sqlite:' . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Create URLs table

    $db->exec("CREATE TABLE IF NOT EXISTS urls (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        url TEXT NOT NULL UNIQUE,
        title TEXT,
        source TEXT,
        crawled_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Create search logs table (optional)

    $db->exec("CREATE TABLE IF NOT EXISTS search_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        keyword TEXT,
        source TEXT,
        ip_address TEXT,
        results_count INTEGER,
        searched_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

 

} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Site configuration
$site_name = "Da Borey Search";
?>