<?php
// ============================================
// FILE: reset_password.php
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
$step = 'request'; // request or reset

// Check if reset token is provided
if (isset($_GET['token'])) {
    $step = 'reset';
    $token = $_GET['token'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } elseif ($step === 'request') {
        // Step 1: Request password reset
        $username = sanitize($_POST['username'] ?? '');
        
        if (empty($username)) {
            $error = 'Username is required.';
        } elseif (!check_rate_limit('reset_' . $_SERVER['REMOTE_ADDR'])) {
            $error = 'Too many reset attempts. Please try again later.';
        } else {
            // Generate token and store
            $reset_token = generate_reset_token();
            $hashed_token = password_hash($reset_token, PASSWORD_DEFAULT);
            
            global $db;
            $stmt = $db->prepare("UPDATE users SET reset_token = ?, reset_expires = datetime('now', '+1 hour') WHERE username = ?");
            $stmt->execute([$hashed_token, $username]);
            
            if ($stmt->rowCount() > 0) {
                $success = "Password reset link has been generated. Use token: " . $reset_token;
            } else {
                $error = 'Username not found.';
            }
        }
    } elseif ($step === 'reset') {
        // Step 2: Reset password
        $token = $_POST['token'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        
        if (empty($token) || empty($password) || empty($confirm)) {
            $error = 'All fields are required.';
        } elseif (!validate_password($password)) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            global $db;
            
            // Verify token
            $stmt = $db->prepare("SELECT username FROM users WHERE reset_token IS NOT NULL AND reset_expires > datetime('now')");
            $stmt->execute();
            $users = $stmt->fetchAll();
            
            $found = false;
            foreach ($users as $user) {
                // Check all available tokens (in production, store properly)
                $stmt = $db->prepare("SELECT reset_token FROM users WHERE username = ?");
                $stmt->execute([$user['username']]);
                $row = $stmt->fetch();
                
                if ($row && password_verify($token, $row['reset_token'])) {
                    $found = $user['username'];
                    break;
                }
            }
            
            if ($found) {
                // Update password
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE username = ?");
                $stmt->execute([$hashed, $found]);
                
                $success = "Password updated successfully! You can now <a href='login.php'>login</a>.";
            } else {
                $error = 'Invalid or expired reset token.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Reset Password - <?php echo $site_name; ?></title>
</head>
<body>
    <h1>Reset Password</h1>
    
    <?php if ($error): ?>
        <p style="color: red;"><?php echo $error; ?></p>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <p style="color: green;"><?php echo $success; ?></p>
    <?php else: ?>
        <?php if ($step === 'request'): ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                
                <div>
                    <label>Username:</label>
                    <input type="text" name="username" required>
                </div>
                
                <button type="submit">Request Reset</button>
            </form>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($_GET['token'] ?? ''); ?>">
                
                <div>
                    <label>New Password:</label>
                    <input type="password" name="password" required>
                </div>
                
                <div>
                    <label>Confirm Password:</label>
                    <input type="password" name="confirm_password" required>
                </div>
                
                <button type="submit">Reset Password</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
    
    <p><a href="index.php">Home</a> | <a href="login.php">Login</a></p>
</body>
</html>