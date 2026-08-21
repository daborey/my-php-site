<?php
// ============================================
// FILE: reset_password.php
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
if (file_exists(__DIR__ . '/security.php')) {
    require_once __DIR__ . '/security.php';
}
if (file_exists(__DIR__ . '/functions.php')) {
    require_once __DIR__ . '/functions.php';
}

// Redirect if already authenticated
if (function_exists('is_logged_in') && is_logged_in()) {
    header("Location: /daboreytextnote/index.php");
    exit;
}

// Fallback CSRF generator function if missing
if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token() {
        if (function_exists('csrf_token')) {
            return csrf_token();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

// Fallback CSRF validator function if missing
if (!function_exists('validate_csrf_token')) {
    function validate_csrf_token($token) {
        if (function_exists('validate_csrf')) {
            return validate_csrf($token);
        }
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

// Database Connection Handle
if (!isset($db) && !isset($pdo)) {
    try {
        $db = new PDO('sqlite:' . __DIR__ . '/database.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("Database connection error: " . $e->getMessage());
    }
} else {
    $db = $db ?? $pdo;
}

$error_msg = '';
$success_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    
    if (!validate_csrf_token($token)) {
        $error_msg = 'Invalid security token. Please refresh and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $old_password = $_POST['old_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($username) || empty($old_password) || empty($new_password) || empty($confirm_password)) {
            $error_msg = 'All fields are required.';
        } elseif ($new_password !== $confirm_password) {
            $error_msg = 'New passwords do not match.';
        } elseif (strlen($new_password) < 6) {
            $error_msg = 'New password must be at least 6 characters.';
        } else {
            try {
                $stmt = $db->prepare("SELECT id, password FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if ($user && password_verify($old_password, $user['password'])) {
                    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                    $update_stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $update_stmt->execute([$hashed, $user['id']]);
                    
                    $success_msg = "Password updated successfully!";
                } else {
                    $error_msg = 'Invalid username or current password.';
                }
            } catch (PDOException $e) {
                $error_msg = 'Database error: ' . $e->getMessage();
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
        .card input[type="text"],
        .card input[type="password"] {
            width: 100%;
            padding: 10px 12px;
            margin-bottom: 15px;
            background: #0f172a;
            border: 1px solid #334155;
            color: white;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .card input:focus {
            outline: none;
            border-color: #0284c7;
        }
        .card button {
            width: 100%;
            padding: 10px;
            background: #0284c7;
            border: none;
            color: white;
            font-weight: bold;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.2s;
            margin-bottom: 15px;
        }
        .card button:hover {
            background: #0369a1;
        }
        .error {
            color: #ef4444;
            font-size: 13px;
            margin-bottom: 15px;
            text-align: center;
        }
        .success {
            color: #4ade80;
            font-size: 13px;
            margin-bottom: 15px;
            text-align: center;
        }
        .card a {
            display: block;
            text-align: center;
            color: #38bdf8;
            text-decoration: none;
            font-size: 13px;
            font-weight: bold;
        }
        .card a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="card">
        <h2>Reset Password</h2>
        <?php if ($error_msg): ?>
            <div class="error"><?php echo function_exists('sanitize') ? sanitize($error_msg) : htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($success_msg): ?>
            <div class="success"><?php echo function_exists('sanitize') ? sanitize($success_msg) : htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?></div>
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