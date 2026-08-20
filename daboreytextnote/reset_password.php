<?php
// ============================================
// FILE: reset_password.php
// PROJECT: daboreytextnote
// ============================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$error_msg = "";
$success_msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        die("Security validation failed.");
    }

    $username = trim($_POST['username'] ?? '');
    $old_password = $_POST['old_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!empty($username) && !empty($old_password) && !empty($new_password) && !empty($confirm_password)) {
        if ($new_password === $confirm_password) {
            $stmt = $db->prepare("SELECT id, password FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($old_password, $user['password'])) {
                $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                $update_stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                $update_stmt->execute([$hashed_password, $user['id']]);

                log_sqlite_event($db, $username, 'PASSWORD_CHANGE_SUCCESS');
                $success_msg = "Password updated successfully! You can now log in.";
            } else {
                $error_msg = "Invalid username or old password.";
            }
        } else {
            $error_msg = "New passwords do not match.";
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
    <title>Reset Password - Daborey Text Note</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #0f172a; color: #f8fafc; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .card { background: #1e293b; padding: 30px; border-radius: 8px; border: 1px solid #334155; width: 320px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); }
        h2 { color: #38bdf8; margin-top: 0; text-align: center; }
        input { width: 100%; padding: 10px; margin: 8px 0; background: #0f172a; border: 1px solid #334155; color: white; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background: #0284c7; border: none; color: white; font-weight: bold; border-radius: 4px; cursor: pointer; margin-top: 10px; }
        button:hover { background: #0369a1; }
        .error { color: #ef4444; font-size: 13px; text-align: center; margin-bottom: 10px; }
        .success { color: #4ade80; font-size: 13px; text-align: center; margin-bottom: 10px; }
        a { color: #38bdf8; text-decoration: none; font-size: 13px; display: block; text-align: center; margin-top: 15px; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Reset Password</h2>
        <?php if ($error_msg): ?>
            <div class="error"><?php echo sanitize($error_msg); ?></div>
        <?php endif; ?>
        <?php if ($success_msg): ?>
            <div class="success"><?php echo sanitize($success_msg); ?></div>
        <?php endif; ?>
        <form method="POST" action="reset_password.php">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <input type="text" name="username" placeholder="Username" required autofocus autocomplete="off">
            <input type="password" name="old_password" placeholder="Old Password" required>
            <input type="password" name="new_password" placeholder="New Password" required>
            <input type="password" name="confirm_password" placeholder="Confirm New Password" required>
            <button type="submit">Update Password</button>
        </form>
        <a href="login.php">Back to Login</a>
    </div>
</body>
</html>