<?php
// 1. Session Setup
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Load Core Configuration
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

// If already logged in, redirect to index
if (isset($_SESSION['user_id'])) {
    header("Location: /daboreytextnote/index.php");
    exit;
}

// 3. Database Connection (SQLite PDO)
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

// Ensure users table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL
    )");
} catch (PDOException $e) {
    // Database table exists
}

// Initialize CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error_msg = "";
$success_msg = "";

// 4. Handle Registration Form Submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security validation failed.");
    }

    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    if (!empty($username) && !empty($password) && !empty($confirm_password)) {
        if ($password !== $confirm_password) {
            $error_msg = "Passwords do not match.";
        } else {
            try {
                // Check if username exists
                $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$username]);
                
                if ($stmt->fetch()) {
                    $error_msg = "Username is already taken.";
                } else {
                    // Hash password securely
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Insert user
                    $stmt = $db->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
                    if ($stmt->execute([$username, $hashed_password])) {
                        $success_msg = "Account created successfully! Redirecting to login...";
                        header("refresh:2;url=/daboreytextnote/login.php");
                    } else {
                        $error_msg = "Failed to create account.";
                    }
                }
            } catch (PDOException $e) {
                $error_msg = "Database error: " . $e->getMessage();
            }
        }
    } else {
        $error_msg = "Please fill in all fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account</title>
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
        .register-card {
            background-color: #1e293b;
            padding: 30px;
            border-radius: 8px;
            border: 1px solid #334155;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 360px;
        }
        .register-card h2 {
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
            background: #10b981;
            border: none;
            color: white;
            font-weight: bold;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.2s;
            margin-top: 10px;
        }
        .btn-submit:hover {
            background: #059669;
        }
        .msg-error {
            color: #ef4444;
            font-size: 13px;
            margin-bottom: 15px;
            text-align: center;
        }
        .msg-success {
            color: #10b981;
            font-size: 13px;
            margin-bottom: 15px;
            text-align: center;
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

<div class="register-card">
    <h2>Register</h2>

    <?php if (!empty($error_msg)): ?>
        <div class="msg-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <?php if (!empty($success_msg)): ?>
        <div class="msg-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>

    <form method="POST" action="/daboreytextnote/register.php">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" required autofocus>
        </div>
        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" required>
        </div>
        <div class="form-group">
            <label>Confirm Password</label>
            <input type="password" name="confirm_password" required>
        </div>
        <button type="submit" class="btn-submit">Sign Up</button>
    </form>

    <div class="auth-footer">
        Already have an account? <a href="/daboreytextnote/login.php">Sign In</a>
    </div>
</div>

</body>
</html>