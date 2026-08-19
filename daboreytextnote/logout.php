<?php
// ============================================
// FILE: logout.php
// ============================================
require_once 'config.php';
require_once 'functions.php';
require_once 'security.php';

// Clear remember me cookie
setcookie('remember_token', '', time() - 3600, '/');

// Logout
logout();
redirect('index.php');
?>