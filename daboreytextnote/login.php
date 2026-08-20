<?php
// ============================================
// FILE: login.php
// PROJECT: daboreytextnote
// ============================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$error_msg = "";

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($token)) {
        die("Security validation failed.");
    }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($username) && !empty($password)) {
        $stmt = $db->prepare("SELECT id, username, password FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['last_activity'] = time();

            unset($_SESSION['csrf_token']);
            log_sqlite_event($db, $user['username'], 'LOGIN_SUCCESS');

            header("Location: dashboard.php");
            exit;
        } else {
            $error_msg = "Invalid username or password.";
        }
    } else {
        $error_msg = "Please fill in all fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - Daborey Text Note</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #0f172a; color: #f8fafc; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .card { background: #1e293b; padding: 30px; border-radius: 8px; border: 1px solid #334155; width: 320px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); }
        h2 { color: #38bdf8; margin-top: 0; text-align: center; }
        input { width: 100%; padding: 10px; margin: 10px 0; background: #0f172a; border: 1px solid #334155; color: white; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background: #0284c7; border: none; color: white; font-weight: bold; border-radius: 4px; cursor: pointer; margin-top: 10px; }
        .error { color: #ef4444; font-size: 13px; text-align: center; margin-bottom: 10px; }
        .links { margin-top: 15px; text-align: center; font-size: 13px; }
        .links a { color: #38bdf8; text-decoration: none; margin: 0 5px; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Sign In</h2>
        <?php if ($error_msg): ?>
            <div class="error"><?php echo sanitize($error_msg); ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['expired'])): ?>
            <div class="error">Session expired due to inactivity.</div>
        <?php endif; ?>
        <form method="POST" action="login.php">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <input type="text" name="username" placeholder="Username" required autofocus autocomplete="off">
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Log In</button>
        </form>
        <div class="links">
            <a href="reset_password.php">Reset Password</a> | 
            <a href="register.php">Register</a>
        </div>
    </div>
</body>
</html>