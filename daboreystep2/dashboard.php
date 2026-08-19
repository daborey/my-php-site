<?php
// ============================================
// FILE: daboreystep2/dashboard.php
// ============================================

require_once __DIR__ . '/config.php';

// Functions & Security Helpers
function sanitize($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function log_sqlite_event($db, $username, $event_type) {
    try {
        $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        if (strpos($ip_address, ',') !== false) {
            $ip_address = trim(explode(',', $ip_address)[0]);
        }
        $stmt = $db->prepare("INSERT INTO system_logs (username, event_type, ip_address) VALUES (?, ?, ?)");
        $stmt->execute([$username, $event_type, $ip_address]);
    } catch (Exception $e) {
        error_log("Logging Exception: " . $e->getMessage());
    }
}

// Simple Base32 Generator for TOTP 2FA Secrets
function generate_base32_secret($length = 16) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < $length; $i++) {
        $secret .= $chars[random_int(0, 31)];
    }
    return $secret;
}

// 1. Authentication Guard
if (!isset($_SESSION['user_id'])) {
    header("Location: /daboreystep2/login.php");
    exit;
}

// 2. Idle Session Timeout Guard (15 Minutes)
$max_idle_seconds = 900;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $max_idle_seconds)) {
    log_sqlite_event($db, $_SESSION['username'] ?? 'UNKNOWN', 'SESSION_TIMEOUT_EXPIRED');
    session_unset();
    session_destroy();
    header("Location: /daboreystep2/login.php?expired=1");
    exit;
}
$_SESSION['last_activity'] = time();

$user_id = $_SESSION['user_id'];
$status_msg = "";
$status_type = "error";

// 3. Fetch User 2FA Status
$stmt = $db->prepare("SELECT username, twofa_secret FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$username = $user['username'] ?? 'User';
$twofa_secret = $user['twofa_secret'] ?? '';

// Handle Generating New Secret Request
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        log_sqlite_event($db, $username, 'CSRF_VALIDATION_FAILURE');
        die("Security token validation failed.");
    }

    if ($_POST['action'] === 'enable_2fa') {
        $new_secret = generate_base32_secret();
        $update_stmt = $db->prepare("UPDATE users SET twofa_secret = ? WHERE id = ?");
        if ($update_stmt->execute([$new_secret, $user_id])) {
            $twofa_secret = $new_secret;
            log_sqlite_event($db, $username, '2FA_SECRET_GENERATED');
            $status_msg = "New 2FA Secret Provisioned Successfully!";
            $status_type = "success";
        }
    } elseif ($_POST['action'] === 'disable_2fa') {
        $update_stmt = $db->prepare("UPDATE users SET twofa_secret = NULL WHERE id = ?");
        if ($update_stmt->execute([$user_id])) {
            $twofa_secret = '';
            log_sqlite_event($db, $username, '2FA_SECRET_REMOVED');
            $status_msg = "2FA Token Removed.";
            $status_type = "success";
        }
    }
}

// Prepare Google Authenticator QR Code URI
$qr_code_url = "";
if (!empty($twofa_secret)) {
    $otpauth_url = "otpauth://totp/DaboreyStep2:" . urlencode($username) . "?secret=" . $twofa_secret . "&issuer=" . urlencode("DaboreyStep2");
    $qr_code_url = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($otpauth_url);
}
?>
<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>2FA Authenticator - Daborey Step 2</title>
    <style>
        body { 
            font-family: 'Segoe UI', Arial, sans-serif; 
            background-color: #0f172a; 
            color: #f8fafc; 
            margin: 0; 
            padding: 20px; 
        }
        header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding: 15px 40px; 
            background: #1e293b; 
            border-bottom: 1px solid #334155; 
            border-radius: 8px; 
            margin-bottom: 30px; 
            flex-wrap: wrap; 
            gap: 20px; 
        }
        .header-title-zone h1 { font-size: 24px; color: #38bdf8; margin: 0 0 5px 0; }
        .user-info { font-size: 14px; color: #94a3b8; }
        .btn-profile {
            padding: 8px 16px; 
            background: #334155; 
            color: white; 
            text-decoration: none; 
            border-radius: 4px; 
            font-weight: bold; 
            font-size: 14px; 
            margin-left: 15px; 
        }
        .btn-profile:hover { background: #475569; }
        .btn-logout { 
            padding: 8px 16px; 
            background: #ef4444; 
            color: white; 
            text-decoration: none; 
            border-radius: 4px; 
            font-weight: bold; 
            font-size: 14px; 
            margin-left: 10px; 
        }
        .btn-logout:hover { background: #dc2626; }

        .clock-container {
            background-color: #090a0f;
            padding: 10px 15px;
            border-radius: 8px;
            border: 1px solid #383121;
            display: grid;
            grid-template-columns: repeat(4, 70px);
            gap: 6px;
            text-align: center;
        }
        .clock-cell {
            background-color: #161922;
            padding: 6px 4px;
            border-radius: 4px;
            border: 1px solid #2d2618;
        }
        .cell-label { font-size: 10px; color: #d1b477; display: block; }
        .cell-value { font-size: 20px; font-weight: bold; color: #ffb700; }
        .date-cell { grid-column: span 4; font-size: 12px; color: #bdc5e1; display: flex; justify-content: space-around; }
        .day-highlight { color: #ffb700; font-weight: bold; }

        .auth-container {
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            max-width: 600px;
            margin: 0 auto;
            background: #1e293b;
            padding: 30px;
            border-radius: 8px;
            border: 1px solid #334155;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            text-align: center;
        }
        .qr-box {
            background: white;
            padding: 15px;
            border-radius: 8px;
            display: inline-block;
            margin: 20px 0;
        }
        .qr-box img { display: block; width: 200px; height: 200px; }
        .secret-key {
            background: #0f172a;
            border: 1px dashed #38bdf8;
            color: #38bdf8;
            padding: 10px;
            font-size: 18px;
            font-family: monospace;
            border-radius: 4px;
            letter-spacing: 2px;
            margin: 15px 0;
            word-break: break-all;
        }
        .btn-action {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            font-weight: bold;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-green { background: #16a34a; color: white; }
        .btn-green:hover { background: #15803d; }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; }

        .status-msg { margin-bottom: 15px; font-weight: bold; }
        .status-error { color: #ef4444; }
        .status-success { color: #4ade80; }
    </style>
</head>
<body>

    <header>
        <div class="header-title-zone">
            <h1>Daborey Step 2 (2FA Manager)</h1>
            <div class="user-info">
                Authenticated Entity: <strong><?php echo sanitize($username); ?></strong>
                <a href="/daboreystep2/profile.php" class="btn-profile">Profile</a>
                <a href="/daboreystep2/logout.php" class="btn-logout">Sign Out</a>
            </div>
        </div>

        <div class="clock-container">
            <div class="clock-cell"><span class="cell-label">ម៉ោង</span><div id="hours" class="cell-value">00</div></div>
            <div class="clock-cell"><span class="cell-label">នាទី</span><div id="minutes" class="cell-value">00</div></div>
            <div class="clock-cell"><span class="cell-label">វិនាទី</span><div id="seconds" class="cell-value">00</div></div>
            <div class="clock-cell"><span class="cell-label">ពេល</span><div id="ampm" class="cell-value">AM</div></div>
            <div class="date-cell">
                <span id="khmer-day" class="day-highlight">---</span>
                <span id="khmer-date">00 --- 0000</span>
            </div>
        </div>
    </header>

    <?php if (!empty($status_msg)): ?>
        <div class="status-msg status-<?php echo $status_type; ?>"><?php echo sanitize($status_msg); ?></div>
    <?php endif; ?>

    <div class="auth-container">
        <h2>Two-Factor Authentication (2FA)</h2>
        <p style="color:#94a3b8; font-size: 14px;">Scan the QR code image using Google Authenticator, Authy, or 1Password to bind your timed security token.</p>

        <?php if (!empty($twofa_secret)): ?>
            <div class="qr-box">
                <img src="<?php echo sanitize($qr_code_url); ?>" alt="2FA QR Code">
            </div>

            <div>Secret Key (Manual Entry):</div>
            <div class="secret-key"><?php echo sanitize($twofa_secret); ?></div>

            <form method="POST" action="/daboreystep2/dashboard.php">
                <input type="hidden" name="action" value="disable_2fa">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <button type="submit" class="btn-action btn-danger">Disable / Reset 2FA Token</button>
            </form>
        <?php else: ?>
            <div style="margin: 30px 0; color: #e2e8f0;">
                <em>No 2FA secret is currently provisioned for this account.</em>
            </div>

            <form method="POST" action="/daboreystep2/dashboard.php">
                <input type="hidden" name="action" value="enable_2fa">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <button type="submit" class="btn-action btn-green">Generate 2FA QR Code & Token</button>
            </form>
        <?php endif; ?>
    </div>

    <script>
        function updateKhmerClock() {
            const khmerNumerals = ['០', '១', '២', '៣', '៤', '៥', '៦', '៧', '៨', '៩'];
            const khmerDays = ['អាទិត្យ', 'ច័ន្ទ', 'អង្គារ', 'ពុធ', 'ព្រហស្បតិ៍', 'សុក្រ', 'សៅរ៍'];
            const khmerMonths = ['មករា', 'កុម្ភៈ', 'មីនា', 'មេសា', 'ឧសភា', 'មិថុនា', 'កក្កដា', 'សីហា', 'កញ្ញា', 'តុលា', 'វិច្ឆិកា', 'ធ្នូ'];

            function toKhmerNum(num) {
                return num.toString().padStart(2, '0').split('').map(digit => khmerNumerals[parseInt(digit)] || digit).join('');
            }

            const now = new Date();
            let rawHours = now.getHours();
            const ampmKhmer = rawHours >= 12 ? 'ល្ងាច' : 'ព្រឹក';
            rawHours = rawHours % 12 || 12;

            document.getElementById('hours').innerText = toKhmerNum(rawHours);
            document.getElementById('minutes').innerText = toKhmerNum(now.getMinutes());
            document.getElementById('seconds').innerText = toKhmerNum(now.getSeconds());
            document.getElementById('ampm').innerText = ampmKhmer;

            document.getElementById('khmer-day').innerText = 'ថ្ងៃ' + khmerDays[now.getDay()];
            document.getElementById('khmer-date').innerText = toKhmerNum(now.getDate()) + ' ' + khmerMonths[now.getMonth()] + ' ' + now.getFullYear().toString().split('').map(digit => khmerNumerals[parseInt(digit)] || digit).join('');
        }
        updateKhmerClock();
        setInterval(updateKhmerClock, 1000);
    </script>
</body>
</html>