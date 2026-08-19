<?php
// ============================================
// FILE: dashboard.php
// ============================================
require_once 'config.php';
require_once 'functions.php';
require_once 'security.php';

// Check if logged in
if (!is_logged_in()) {
    redirect('login.php');
}

$user = current_user();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Dashboard - <?php echo $site_name; ?></title>
</head>
<body>
    <h1>Welcome, <?php echo htmlspecialchars($user['username']); ?>!</h1>
    <p>You are logged in.</p>
    
    <ul>
        <li><a href="profile.php">My Profile</a></li>
        <li><a href="logout.php">Logout</a></li>
    </ul>
    
    <p><a href="index.php">Home</a></p>
</body>
</html>