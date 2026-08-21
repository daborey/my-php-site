<?php
// ============================================
// FILE: login.php
// PROJECT: daboreytextnote
// ============================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Redirect if already authenticated
if (is_logged_in()) {
    redirect("index.php");
}

$error_msg = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST['csrf_token'] ?? '';
    
    if (!validate_csrf($token)) {
        log_sqlite_event($db, $_POST['username'] ?? 'UNKNOWN', 'LOGIN_CSRF_FAILED');
        $error_msg = "Security validation failed. Please try again.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (!empty($username) && !empty($password)) {
            try {
                $stmt = $db->prepare("SELECT id, username, password FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($password, $user['password'])) {
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_regenerate_id(true);
                    }
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['last_activity'] = time();

                    log_sqlite_event($db, $user['username'], 'LOGIN_SUCCESSFUL');

                    redirect("index.php");
                } else {
                    log_sqlite_event($db, $username, 'LOGIN_FAILED_INVALID_CREDENTIALS');
                    $error_msg = "Invalid username or password.";
                }
            } catch (Throwable $e) {
                $error_msg = "System error during login. Please try again.";
            }
        } else {
            $error_msg = "Please fill in all fields.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - <?php echo sanitize($site_name ?? 'Da Borey Text Note'); ?></title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #0f172a; color: #f8fafc; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-card { background-color: #1e293b; padding: 30px; border-radius: 8px; border: 1px solid #334155; box-shadow: 0 4px 12px rgba(0,0,0,0.3); width: 100%; max-width: 360px; }
        .login-card h2 { margin-top: 0; color: #38bdf8; text-align: center; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-size: 14px; color: #94a3b8; }
        .form-group input { width: 100%; padding: 8px 12px; background: #0f172a; border: 1px solid #334155; color: white; border-radius: 4px; box-sizing: border-box; }
        .btn-submit { width: 100%; padding: 10px; background: #0284c7; border: none; color: white; font-weight: bold; border-radius: 4px; cursor: pointer; margin-top: 10px; }
        .msg-error { color: #ef4444; font-size: 13px; margin-bottom: 15px; text-align: center; }
        .msg-expired { color: #eab308; font-size: 13px; margin-bottom: 15px; text-align: center; }
        .auth-footer { margin-top: 20px; text-align: center; font-size: 13px; color: #94a3b8; }
        .auth-footer a { color: #38bdf8; text-decoration: none; font-weight: bold; margin: 0 4px; }
    </style>
</head>
<body>

<div class="login-card">
    <h2>Sign In</h2>

    <?php if (isset($_GET['expired'])): ?>
        <div class="msg-expired">Your session expired due to inactivity. Please log in again.</div>
    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>
        <div class="msg-error"><?php echo sanitize($error_msg); ?></div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" required autofocus autocomplete="off">
        </div>
        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" required>
        </div>
        <button type="submit" class="btn-submit">Sign In</button>
    </form>

    <div class="auth-footer">
        <a href="reset_password.php">Reset Password</a> | 
        <a href="register.php">Create Account</a>
    </div>
</div>

</body>
</html>