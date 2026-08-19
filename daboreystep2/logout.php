<?php
// ============================================
// FILE: logout.php
// PROJECT: daboreystep2
// ============================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (isset($_SESSION['username'])) {
    log_sqlite_event($db, $_SESSION['username'], 'LOGOUT_SUCCESS');
}

// Unset all session variables
$_SESSION = array();

// Destroy session cookies
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

header("Location: " . BASE_URL . "/login.php");
exit;