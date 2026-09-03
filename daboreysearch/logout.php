<?php
// ============================================
// FILE: daboreysearch/logout.php
// ============================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear search session
$_SESSION['search_user_id'] = null;
$_SESSION['search_username'] = null;

session_destroy();

header("Location: /daboreysearch/login.php");
exit;