<?php
// ============================================
// FILE: register.php
// PROJECT: daboreystep2
// ============================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "/dashboard.php");
    exit;
}

$error_msg = "";
$success_msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        die("Security validation failed.");
    }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!empty($username) && !empty($password) && !empty($confirm_password)) {
        if ($password !== $confirm_password) {
            $error_msg = "Passwords do not match.";
        } else {
            // Check if user exists
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);

            if ($stmt->fetch()) {
                $error_msg = "Username is already taken.";
            } else {
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $db->prepare("INSERT INTO users (username, password) VALUES (?, ?)");

                if ($stmt->execute([$username, $hashed_password])) {
                    log_sqlite_event($db, $username, 'REGISTER_SUCCESS');
                    $success_msg = "Registration successful! You can now log in.";
                } else {
                    $error_msg = "Error registering account. Please try again.";
                }
            }
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
    <title>Register - Daborey Step 2</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #0f172a; color: #f8fafc; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .card { background: #1e293b; padding: 30px; border-radius: 8px; border: 1px solid #334155; width: 320px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); }
        h2 { color: #38bdf8; margin-top: 0; text-align: center; }
        input { width: 100%; padding: 10px; margin: 10px 0; background: #0f172a; border: 1px solid #334155; color: white; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background: #0284c7; border: none; color: white; font-weight: bold; border-radius: 4px; cursor: pointer; margin-top: 10px; }
        .error { color: #ef4444; font-size: 13px; text-align: center; margin-bottom: 10px; }
        .success { color: #4ade80; font-size: 13px; text-align: center; margin-bottom: 10px; }
        a { color: #38bdf8; text-decoration: none; font-size: 13px; display: block; text-align: center; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Register Account</h2>
        <?php if ($error_msg): ?>
            <div class="error"><?php echo sanitize($error_msg); ?></div>
        <?php endif; ?>
        <?php if ($success_msg): ?>
            <div class="success"><?php echo sanitize($success_msg); ?></div>
        <?php endif; ?>
        <form method="POST" action="register.php">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <input type="text" name="username" placeholder="Username" required autofocus autocomplete="off">
            <input type="password" name="password" placeholder="Password" required>
            <input type="password" name="confirm_password" placeholder="Confirm Password" required>
            <button type="submit">Register</button>
        </form>
        <a href="login.php">Already have an account? Sign in</a>
    </div>
</body>
</html>