<?php
// ============================================
// FILE: daboreysearch/delete_source.php
// ============================================
require_once 'config.php';
require_once 'functions.php';
require_once 'security.php';

// ===== LOGIN CHECK =====
if (!isset($_SESSION['search_user_id'])) {
    header("Location: /daboreysearch/login.php");
    exit;
}

// ===== CSRF CHECK =====
if (!isset($_GET['csrf_token']) || !validate_csrf($_GET['csrf_token'])) {
    die("Security validation failed.");
}

// ===== DELETE SOURCE =====
$source = $_GET['source'] ?? '';

if (!empty($source)) {
    delete_source($source);
    header("Location: /daboreysearch/index.php?deleted=" . urlencode($source));
    exit;
} else {
    header("Location: /daboreysearch/index.php");
    exit;
}
?>