<?php
// 1. Production security settings
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Start session to track logged-in users
session_start();

// 2. Connect to SQLite using the Cloud Run mounted storage path
try {
    $dbPath = '/mnt/storage/users.db';
    $db = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create users table with username, email, and password
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        email TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    die("Database connection failed. Please check server configuration.");
}

$message = "";
$error = "";

// 3. Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Handle Registration
    if ($action === 'register') {
        $username = trim($_POST['reg_username']);
        $email = trim($_POST['reg_email']);
        $password = $_POST['reg_password'];

        if (!empty($username) && !empty($email) && !empty($password)) {
            // Hash the password securely
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            try {
                $stmt = $db->prepare("INSERT INTO users (username, email, password) VALUES (:username, :email, :password)");
                $stmt->execute([
                    'username' => $username,
                    'email' => $email,
                    'password' => $hashedPassword
                ]);
                $message = "Registration successful! You can now log in.";
            } catch (PDOException $e) {
                $error = "Username or Email already exists.";
            }
        } else {
            $error = "All registration fields are required.";
        }
    }

    // Handle Login
    if ($action === 'login') {
        $username = trim($_POST['login_username']);
        $password = $_POST['login_password'];

        if (!empty($username) && !empty($password)) {
            $stmt = $db->prepare("SELECT * FROM users WHERE username = :username");
            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user'] = $user['username'];
                $message = "Welcome back, " . htmlspecialchars($user['username']) . "!";
            } else {
                $error = "Invalid username or password.";
            }
        } else {
            $error = "All login fields are required.";
        }
    }
}

// Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login & Register - Cloud Run App</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <h1>Cloud Run PHP App</h1>

        <?php if ($message): ?>
            <p class="success"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>
        <?php if ($error): ?>
            <p class="error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>

        <?php if (isset($_SESSION['user'])): ?>
            <!-- Dashboard view when logged in -->
            <div class="dashboard">
                <h2>Hello, <?php echo htmlspecialchars($_SESSION['user']); ?>!</h2>
                <p>You are successfully logged in.</p>
                <a href="index.php?logout=true" class="logout-btn">Log Out</a>
            </div>
        <?php else: ?>
            <!-- Forms view when logged out -->
            <div class="forms-grid">
                <!-- Register Form -->
                <div class="form-box">
                    <h2>Register</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="register">
                        <label>Username:</label>
                        <input type="text" name="reg_username" required>
                        
                        <label>Email:</label>
                        <input type="email" name="reg_email" required>
                        
                        <label>Password:</label>
                        <input type="password" name="reg_password" required>
                        
                        <button type="submit">Sign Up</button>
                    </form>
                </div>

                <!-- Login Form -->
                <div class="form-box">
                    <h2>Login</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="login">
                        <label>Username:</label>
                        <input type="text" name="login_username" required>
                        
                        <label>Password:</label>
                        <input type="password" name="login_password" required>
                        
                        <button type="submit">Log In</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>