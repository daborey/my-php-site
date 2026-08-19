<?php
// ============================================
// FILE: login.php
// ============================================
require_once 'config.php';
require_once 'functions.php';
require_once 'security.php';

// If logged in, go to dashboard
if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = '';
$remember = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $username = sanitize($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']);
        
        if (empty($username) || empty($password)) {
            $error = 'All fields are required.';
        } elseif (!check_rate_limit('login_' . $_SERVER['REMOTE_ADDR'])) {
            $error = 'Too many login attempts. Please try again later.';
        } else {
            if (login($username, $password)) {
                // Remember me (7 days)
                if ($remember) {
                    $token = bin2hex(random_bytes(32));
                    setcookie('remember_token', $token, time() + 604800, '/');
                    
                    global $db;
                    $stmt = $db->prepare("UPDATE users SET remember_token = ? WHERE username = ?");
                    $stmt->execute([password_hash($token, PASSWORD_DEFAULT), $username]);
                }
                redirect('dashboard.php');
            } else {
                $error = 'Invalid username or password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login - <?php echo $site_name; ?></title>
</head>
<body>
    <h1>Login</h1>
    
    <?php if ($error): ?>
        <p style="color: red;"><?php echo $error; ?></p>
    <?php endif; ?>
    
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
            <label>
                <input type="checkbox" name="remember"> Remember me
            </label>
        </div>
        
        <button type="submit">Login</button>
    </form>
    
    <p><a href="index.php">Home</a> | <a href="register.php">Register</a> | <a href="reset_password.php">Forgot Password?</a></p>
</body>
</html>