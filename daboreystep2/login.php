<?php
// ============================================
// FILE: daboreystep2/login.php
// ============================================

require_once __DIR__ . '/config.php';

$error_msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    // Verify CSRF Token safely
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        die("Security validation failed.");
    }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($username) && !empty($password)) {
        $stmt = $db->prepare("SELECT id, username, password FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Regenerate session to prevent session fixation
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['last_activity'] = time();

            // Clear old CSRF token so dashboard generates a fresh one
            unset($_SESSION['csrf_token']);

            header("Location: /daboreystep2/dashboard.php");
            exit;
        } else {
            $error_msg = "Invalid username or password.";
        }
    } else {
        $error_msg = "Please fill in all fields.";
    }
}

// Generate new token for the form
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Daborey Step 2</title>
    <style>
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #0f172a;
            color: #f8fafc;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 16px;
            box-sizing: border-box;
        }

        .card {
            background: #1e293b;
            padding: 30px;
            border-radius: 8px;
            border: 1px solid #334155;
            width: 100%;
            max-width: 340px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            box-sizing: border-box;
        }

        h2 {
            color: #38bdf8;
            margin-top: 0;
            text-align: center;
        }

        input {
            width: 100%;
            padding: 12px;
            margin: 10px 0;
            background: #0f172a;
            border: 1px solid #334155;
            color: white;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 14px;
        }

        button {
            width: 100%;
            min-height: 44px;
            padding: 12px;
            background: #0284c7;
            border: none;
            color: white;
            font-weight: bold;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 10px;
            font-size: 14px;
        }

        button:hover {
            background: #0369a1;
        }

        .error {
            color: #ef4444;
            font-size: 13px;
            text-align: center;
            margin-bottom: 10px;
        }

        .links {
            margin-top: 20px;
            text-align: center;
            font-size: 14px;
        }

        .links a {
            color: #38bdf8;
            text-decoration: none;
            margin: 0 8px;
            display: inline-block;
            padding: 4px 0;
        }

        .links a:hover {
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .card {
                padding: 20px;
            }

            h2 {
                font-size: 20px;
            }
        }
    </style>
</head>

<body>
    <div class="card">
        <h2>Sign In</h2>
        <?php if ($error_msg) : ?>
            <div class="error"><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <form method="POST" action="/daboreystep2/login.php">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="text" name="username" placeholder="Username" required autofocus autocomplete="off">
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Log In</button>
        </form>
        <div class="links">
            <a href="/daboreystep2/reset_password.php">Reset Password</a> |
            <a href="/daboreystep2/register.php">Register</a>
        </div>
    </div>
</body>

</html>