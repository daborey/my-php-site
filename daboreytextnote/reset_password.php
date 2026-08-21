<?php
// ============================================
// FILE: reset_password.php
// PROJECT: daboreytextnote
// ============================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (is_logged_in()) {
    redirect('index.php');
}

$error = '';
$success = '';
$step = isset($_GET['token']) ? 'reset' : 'request';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token_post = $_POST['csrf_token'] ?? '';
    
    if (!validate_csrf($token_post)) {
        $error = 'Invalid security token. Please refresh and try again.';
    } elseif ($step === 'request') {
        $username = trim($_POST['username'] ?? '');
        
        if (empty($username)) {
            $error = 'Username is required.';
        } else {
            try {
                $raw_reset_token = bin2hex(random_bytes(16));
                $hashed_token = password_hash($raw_reset_token, PASSWORD_DEFAULT);
                
                $stmt = $db->prepare("UPDATE users SET reset_token = ?, reset_expires = datetime('now', '+1 hour') WHERE username = ?");
                $stmt->execute([$hashed_token, $username]);
                
                if ($stmt->rowCount() > 0) {
                    log_sqlite_event($db, $username, 'PASSWORD_RESET_REQUESTED');
                    $reset_link = "reset_password.php?token=" . urlencode($raw_reset_token);
                    $success = "Reset token created. Click here: <a href='" . $reset_link . "' style='color:#38bdf8;'>Proceed to Reset Password</a>";
                } else {
                    $error = 'Username not found.';
                }
            } catch (Throwable $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    } elseif ($step === 'reset') {
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
            try {
                $stmt = $db->prepare("SELECT username, reset_token FROM users WHERE reset_token IS NOT NULL AND reset_expires > datetime('now')");
                $stmt->execute();
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $target_username = false;
                foreach ($users as $u) {
                    if (password_verify($token, $u['reset_token'])) {
                        $target_username = $u['username'];
                        break;
                    }
                }
                
                if ($target_username) {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $update_stmt = $db->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE username = ?");
                    $update_stmt->execute([$hashed_password, $target_username]);
                    
                    log_sqlite_event($db, $target_username, 'PASSWORD_CHANGE_SUCCESS');
                    $success = "Password updated successfully! You can now <a href='login.php' style='color:#38bdf8;'>Sign In</a>.";
                } else {
                    $error = 'Invalid or expired reset token.';
                }
            } catch (Throwable $e) {
                $error = 'Database update error: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?php echo sanitize($site_name ?? 'Da Borey Text Note'); ?></title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #0f172a; color: #f8fafc; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .card { background-color: #1e293b; padding: 30px; border-radius: 8px; border: 1px solid #334155; box-shadow: 0 4px 12px rgba(0,0,0,0.3); width: 100%; max-width: 360px; }
        h2 { margin-top: 0; color: #38bdf8; text-align: center; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-size: 14px; color: #94a3b8; }
        .form-group input { width: 100%; padding: 8px 12px; background: #0f172a; border: 1px solid #334155; color: white; border-radius: 4px; box-sizing: border-box; }
        .btn-submit { width: 100%; padding: 10px; background: #0284c7; border: none; color: white; font-weight: bold; border-radius: 4px; cursor: pointer; margin-top: 10px; }
        .msg-error { color: #ef4444; font-size: 13px; margin-bottom: 15px; text-align: center; word-break: break-all; }
        .msg-success { color: #4ade80; font-size: 13px; margin-bottom: 15px; text-align: center; }
        .auth-footer { margin-top: 20px; text-align: center; font-size: 13px; color: #94a3b8; }
        .auth-footer a { color: #38bdf8; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>

<div class="card">
    <h2>Reset Password</h2>
    
    <?php if ($error): ?>
        <div class="msg-error"><?php echo sanitize($error); ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="msg-success"><?php echo $success; ?></div>
    <?php else: ?>
        <?php if ($step === 'request'): ?>
            <form method="POST" action="reset_password.php">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required autofocus autocomplete="off">
                </div>
                <button type="submit" class="btn-submit">Request Reset Token</button>
            </form>
        <?php else: ?>
            <form method="POST" action="reset_password.php?token=<?php echo sanitize($_GET['token'] ?? ''); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="token" value="<?php echo sanitize($_GET['token'] ?? ''); ?>">
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="password" required>
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" required>
                </div>
                <button type="submit" class="btn-submit">Reset Password</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
    
    <div class="auth-footer">
        <a href="login.php">Back to Sign In</a>
    </div>
</div>

</body>
</html>