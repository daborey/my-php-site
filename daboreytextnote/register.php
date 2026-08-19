<?php
// ============================================
// FILE: register.php
// ============================================
require_once 'config.php';
require_once 'functions.php';
require_once 'security.php';

// If logged in, go to dashboard
if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $username = sanitize($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        
        // Validate
        if (empty($username) || empty($password) || empty($confirm)) {
            $error = 'All fields are required.';
        } elseif (!validate_username($username)) {
            $error = 'Username must be 3-20 characters (letters, numbers, underscore).';
        } elseif (!validate_password($password)) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } elseif (!check_rate_limit('register_' . $_SERVER['REMOTE_ADDR'])) {
            $error = 'Too many registration attempts. Please try again later.';
        } else {
            // Register
            if (register($username, $password)) {
                $success = 'Registration successful! You can now <a href="login.php">login</a>.';
            } else {
                $error = 'Username already exists or registration failed.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Register - <?php echo $site_name; ?></title>
</head>
<body>
    <h1>Register</h1>
    
    <?php if ($error): ?>
        <p style="color: red;"><?php echo $error; ?></p>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <p style="color: green;"><?php echo $success; ?></p>
    <?php else: ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            
            <div>
                <label>Username:</label>
                <input type="text" name="username" required>
            </div>
            
            <div>
                <label>Password:</label>
                <input type="password" name="password" required>
            </div>
            
            <div>
                <label>Confirm Password:</label>
                <input type="password" name="confirm_password" required>
            </div>
            
            <button type="submit">Register</button>
        </form>
    <?php endif; ?>
    
    <p><a href="index.php">Home</a> | <a href="login.php">Login</a></p>
</body>
</html>