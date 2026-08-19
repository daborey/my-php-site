<?php
// ============================================
// FILE: profile.php
// ============================================
require_once 'config.php';
require_once 'functions.php';
require_once 'security.php';

// Check if logged in
if (!is_logged_in()) {
    redirect('login.php');
}

$user = current_user();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $new_username = sanitize($_POST['username'] ?? '');
        $old_password = $_POST['old_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Update username
        if (!empty($new_username) && $new_username !== $user['username']) {
            if (!validate_username($new_username)) {
                $error = 'Username must be 3-20 characters (letters, numbers, underscore).';
            } else {
                global $db;
                $stmt = $db->prepare("UPDATE users SET username = ? WHERE id = ?");
                if ($stmt->execute([$new_username, $_SESSION['user_id']])) {
                    $_SESSION['username'] = $new_username;
                    $success = 'Username updated successfully.';
                    $user = current_user();
                } else {
                    $error = 'Username already exists.';
                }
            }
        }
        
        // Update password
        if (!empty($old_password) && !empty($new_password)) {
            if (empty($error)) {
                global $db;
                $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $row = $stmt->fetch();
                
                if ($row && password_verify($old_password, $row['password'])) {
                    if ($new_password !== $confirm_password) {
                        $error = 'Passwords do not match.';
                    } elseif (!validate_password($new_password)) {
                        $error = 'Password must be at least 6 characters.';
                    } else {
                        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $stmt->execute([$hashed, $_SESSION['user_id']]);
                        $success = 'Password updated successfully.';
                    }
                } else {
                    $error = 'Current password is incorrect.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Profile - <?php echo $site_name; ?></title>
</head>
<body>
    <h1>My Profile</h1>
    
    <?php if ($error): ?>
        <p style="color: red;"><?php echo $error; ?></p>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <p style="color: green;"><?php echo $success; ?></p>
    <?php endif; ?>
    
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        
        <div>
            <label>Username:</label>
            <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>">
        </div>
        
        <hr>
        
        <div>
            <label>Current Password (to change password):</label>
            <input type="password" name="old_password">
        </div>
        
        <div>
            <label>New Password:</label>
            <input type="password" name="new_password">
        </div>
        
        <div>
            <label>Confirm New Password:</label>
            <input type="password" name="confirm_password">
        </div>
        
        <button type="submit">Update Profile</button>
    </form>
    
    <p><a href="dashboard.php">Dashboard</a> | <a href="logout.php">Logout</a></p>
</body>
</html>