<?php
// ============================================
// FILE: reset_password.php
// ============================================
require_once 'config.php';
require_once 'functions.php';
require_once 'security.php';

// If logged in, go to dashboard
if (is_logged_in()) {
    redirect('/daboreytextnote/index.php');
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
                $success = "Password reset link generated. <a href='/daboreytextnote/reset_password.php?token=" . urlencode($reset_token) . "' style='color:#38bdf8;'>Click here to reset your password</a>";
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
                
                $success = "Password updated successfully! You can now <a href='/daboreytextnote/login.php' style='color:#38bdf8;'>Sign In</a>.";
            } else {
                $error = 'Invalid or expired reset token.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <style>
        body {
            font-family: 'Kantumruy Pro', 'Khmer OS Battambang', 'Segoe UI', Arial, sans-serif;
            background-color: #0f172a;
            color: #f8fafc;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .card {
            background-color: #1e293b;
            padding: 30px;
            border-radius: 8px;
            border: 1px solid #334155;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 360px;
        }
        .card h2 {
            margin-top: 0;
            color: #38bdf8;
            text-align: center;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-size: 14px;
            color: #94a3b8;
        }
        .form-group input {
            width: 100%;
            padding: 8px 12px;
            background: #0f172a;
            border: 1px solid #334155;
            color: white;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .form-group input:focus {
            outline: none;
            border-color: #0284c7;
        }
        .btn-submit {
            width: 100%;
            padding: 10px;
            background: #0284c7;
            border: none;
            color: white;
            font-weight: bold;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.2s;
            margin-top: 10px;
        }
        .btn-submit:hover {
            background: #0369a1;
        }
        .msg-error {
            color: #ef4444;
            font-size: 13px;
            margin-bottom: 15px;
            text-align: center;
        }
        .msg-success {
            color: #4ade80;
            font-size: 13px;
            margin-bottom: 15px;
            text-align: center;
            line-height: 1.5;
        }
        .auth-footer {
            margin-top: 20px;
            text-align: center;
            font-size: 13px;
            color: #94a3b8;
        }
        .auth-footer a {
            color: #38bdf8;
            text-decoration: none;
            font-weight: bold;
        }
        .auth-footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

<div class="card">
    <h2>Reset Password</h2>

    <?php if ($error): ?>
        <div class="msg-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="msg-success"><?php echo $success; ?></div>
    <?php else: ?>
        <?php if ($step === 'request'): ?>
            <form method="POST" action="/daboreytextnote/reset_password.php">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required autofocus>
                </div>
                <button type="submit" class="btn-submit">Request Reset Link</button>
            </form>
        <?php else: ?>
            <form method="POST" action="/daboreytextnote/reset_password.php?token=<?php echo urlencode($_GET['token'] ?? ''); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($_GET['token'] ?? ''); ?>">
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="password" required>
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" required>
                </div>
                <button type="submit" class="btn-submit">Update Password</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <div class="auth-footer">
        <a href="/daboreytextnote/login.php">Back to Sign In</a>
    </div>
</div>

</body>
</html>