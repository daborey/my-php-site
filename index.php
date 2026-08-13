<?php
// 1. Production security settings
ini_set('display_errors', 0);
error_reporting(E_ALL);

// 2. Connect to SQLite using the Cloud Run mounted storage path
try {
    $dbPath = '/mnt/storage/users.db';
    $db = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create table if it doesn't exist
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    die("Database connection failed. Please check server configuration.");
}

// 3. Handle form submission
$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['name'])) {
    $name = trim($_POST['name']);
    
    $stmt = $db->prepare("INSERT INTO users (name) VALUES (:name)");
    $stmt->execute(['name' => $name]);
    
    $message = "User registered successfully!";
}

// 4. Fetch users
$stmt = $db->query("SELECT * FROM users ORDER BY id DESC");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Cloud Run App</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <h1>Cloud Run SQLite App</h1>
        
        <?php if ($message): ?>
            <p class="success"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <form method="POST">
            <label for="name">Enter Name:</label>
            <input type="text" id="name" name="name" required>
            <button type="submit">Save</button>
        </form>

        <h2>Saved Users:</h2>
        <ul>
            <?php if (empty($users)): ?>
                <li>No users found yet.</li>
            <?php else: ?>
                <?php foreach ($users as $user): ?>
                    <li><?php echo htmlspecialchars($user['name']); ?> <span>(<?php echo $user['created_at']; ?>)</span></li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </div>
</body>
</html>