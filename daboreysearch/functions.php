<?php
// ============================================
// FILE: daboreysearch/functions.php
// ============================================
require_once 'config.php';

// Get all URLs from database
function get_all_urls() {
    global $db;
    $stmt = $db->query("SELECT id, url, title, crawled_at FROM urls ORDER BY id DESC");
    return $stmt->fetchAll();
}

// Search URLs by keyword
function search_urls($keyword, $source = '') {
    global $db;
    
    if (empty($keyword)) {
        return [];
    }
    
    $search_term = '%' . $keyword . '%';
    
    if (!empty($source)) {
        $stmt = $db->prepare("
            SELECT id, url, title, source, crawled_at 
            FROM urls 
            WHERE (url LIKE ? OR title LIKE ?) AND source = ?
            ORDER BY id DESC
        ");
        $stmt->execute([$search_term, $search_term, $source]);
    } else {
        $stmt = $db->prepare("
            SELECT id, url, title, source, crawled_at 
            FROM urls 
            WHERE url LIKE ? OR title LIKE ?
            ORDER BY id DESC
        ");
        $stmt->execute([$search_term, $search_term]);
    }
    
    $results = $stmt->fetchAll();
    
    // Log the search
    log_search($keyword, $source, count($results));
    
    return $results;
}

// Log search activity
function log_search($keyword, $results_count) {
    global $db;
    try {
        $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        if (strpos($ip_address, ',') !== false) {
            $ip_address = trim(explode(',', $ip_address)[0]);
        }
        $stmt = $db->prepare("INSERT INTO search_logs (keyword, ip_address, results_count) VALUES (?, ?, ?)");
        $stmt->execute([$keyword, $ip_address, $results_count]);
    } catch (Exception $e) {
        // Silent fail for logging
    }
}
function log_search($keyword, $source, $results_count) {
    global $db;
    try {
        $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        if (strpos($ip_address, ',') !== false) {
            $ip_address = trim(explode(',', $ip_address)[0]);
        }
        $stmt = $db->prepare("INSERT INTO search_logs (keyword, source, ip_address, results_count) VALUES (?, ?, ?)");
        $stmt->execute([$keyword, $source, $ip_address, $results_count]);
    } catch (Exception $e) {
        // Silent fail for logging
    }
}

// Add URL to database (skip duplicates)

function add_url($url, $title = '') {
    global $db;
    try {
        $stmt = $db->prepare("INSERT OR IGNORE INTO urls (url, title) VALUES (?, ?)");
        return $stmt->execute([$url, $title]);
    } catch (PDOException $e) {
        return false;
    }
}
function add_url($url, $title = '', $source = '') {
    global $db;
    try {
        $stmt = $db->prepare("INSERT OR IGNORE INTO urls (url, title, source) VALUES (?, ?, ?)");
        return $stmt->execute([$url, $title, $source]);
    } catch (PDOException $e) {
        return false;
    }
}

// Count total URLs
function count_urls() {
    global $db;
    $stmt = $db->query("SELECT COUNT(*) as total FROM urls");
    $result = $stmt->fetch();
    return $result['total'] ?? 0;
}

// Clear all URLs (optional)
function clear_urls() {
    global $db;
    $db->exec("DELETE FROM urls");
}
// Get all unique sources with count
function get_all_sources() {
    global $db;
    $stmt = $db->query("SELECT DISTINCT source, COUNT(*) as count FROM urls WHERE source IS NOT NULL AND source != '' GROUP BY source ORDER BY source");
    return $stmt->fetchAll();
}

// Delete all URLs from a specific source
function delete_source($source) {
    global $db;
    try {
        $stmt = $db->prepare("DELETE FROM urls WHERE source = ?");
        return $stmt->execute([$source]);
    } catch (PDOException $e) {
        return false;
    }
}

// Get recent searches
function get_recent_searches($limit = 10) {
    global $db;
    $stmt = $db->prepare("
        SELECT keyword, source, results_count, searched_at 
        FROM search_logs 
        ORDER BY searched_at DESC 
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

//
?>