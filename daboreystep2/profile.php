<?php
// ============================================
// FILE: profile.php
// PROJECT: daboreystep2
// ============================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user profile details
$stmt = $db->prepare("SELECT id, username, twofa_secret, created_at FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: " . BASE_URL . "/logout.php");
    exit;
}

$has_2fa = !empty($user['twofa_secret']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Profile - Daborey Step 2</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #0f172a; color: #f8fafc; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .card { background: #1e293b; padding: 30px; border-radius: 8px; border: 1px solid #334155; width: 360px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); }
        h2 { color: #38bdf8; margin-top: 0; text-align: center; }
        .info-group { margin-bottom: 15px; font-size: 14px; }
        .info-label { color: #94a3b8; display: block; margin-bottom: 4px; }
        .info-value { font-weight: bold; color: #f8fafc; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .badge-success { background: #166534; color: #4ade80; }
        .badge-warning { background: #854d0e; color: #fde047; }
        .btn { display: block; width: 100%; padding: 10px; background: #0284c7; border: none; color: white; font-weight: bold; text-align: center; border-radius: 4px; text-decoration: none; margin-top: 15px; box-sizing: border-box; }
        .btn-secondary { background: #334155; }
        .btn:hover { opacity: 0.9; }
    </style>
</head>
<body>
    <div class="card">
        <h2>User Profile</h2>
        <div class="info-group">
            <span class="info-label">Username</span>
            <span class="info-value"><?php echo sanitize($user['username']); ?></span>
        </div>
        <div class="info-group">
            <span class="info-label">Account Created</span>
            <span class="info-value"><?php echo sanitize(date("d M Y", strtotime($user['created_at']))); ?></span>
        </div>
        <div class="info-group">
            <span class="info-label">Two-Factor Authentication (2FA)</span>
            <?php if ($has_2fa): ?>
                <span class="badge badge-success">Enabled</span>
            <?php else: ?>
                <span class="badge badge-warning">Disabled</span>
            <?php endif; ?>
        </div>

        <a href="<?php echo BASE_URL; ?>/dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
        <a href="<?php echo BASE_URL; ?>/reset_password.php" class="btn">Change Password</a>
    </div>
</body>
</html>