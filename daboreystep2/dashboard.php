<?php
// ============================================
// FILE: dashboard.php
// PROJECT: daboreystep2
// ============================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Check authentication guard
if (!isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

// Session timeout guard
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT_SECONDS)) {
    log_sqlite_event($db, $_SESSION['username'] ?? 'UNKNOWN', 'SESSION_TIMEOUT_EXPIRED');
    session_unset();
    session_destroy();
    header("Location: " . BASE_URL . "/login.php?expired=1");
    exit;
}
$_SESSION['last_activity'] = time();

$user_id = $_SESSION['user_id'];
$status_msg = "";
$error_msg = "";

// Fetch user's 2FA secret status
$stmt = $db->prepare("SELECT twofa_secret FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_data = $stmt->fetch();
$user_secret = $user_data['twofa_secret'] ?? null;

// Handle 2FA Verification Form
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'verify_2fa') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        log_sqlite_event($db, $_SESSION['username'] ?? 'UNKNOWN', '2FA_CSRF_FAILURE');
        die("Security token validation failed.");
    }

    $token = trim($_POST['totp_token'] ?? '');
    
    if ($user_secret && verify_totp_token($user_secret, $token)) {
        $_SESSION['2fa_verified'] = true;
        log_sqlite_event($db, $_SESSION['username'], '2FA_VERIFICATION_SUCCESS');
        header("Location: " . BASE_URL . "/dashboard.php");
        exit;
    } else {
        log_sqlite_event($db, $_SESSION['username'], '2FA_VERIFICATION_FAILED');
        $error_msg = "Invalid or expired 2FA code. Please try again.";
    }
}

// Handle Note Creation Form
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'create_note') {
    if (!empty($user_secret) && empty($_SESSION['2fa_verified'])) {
        die("Unauthorized request.");
    }
    
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        log_sqlite_event($db, $_SESSION['username'] ?? 'UNKNOWN', 'CSRF_VALIDATION_FAILURE');
        die("Security token validation failed.");
    }

    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');

    if (!empty($title) || !empty($content)) {
        try {
            $stmt = $db->prepare("INSERT INTO notes (user_id, title, content) VALUES (?, ?, ?)");
            if ($stmt->execute([$user_id, $title, $content])) {
                log_sqlite_event($db, $_SESSION['username'] ?? 'UNKNOWN', 'NOTE_CREATED_SUCCESSFULLY');
                header("Location: " . BASE_URL . "/dashboard.php");
                exit;
            } else {
                $status_msg = "Error saving note.";
            }
        } catch (PDOException $e) {
            $status_msg = "Database error: " . $e->getMessage();
        }
    }
}

// Fetch Notes
$notes = [];
if (!empty($user_secret) && empty($_SESSION['2fa_verified'])) {
    $require_2fa_step = true;
} else {
    $require_2fa_step = false;
    try {
        $stmt = $db->prepare("SELECT title, content, created_at FROM notes WHERE user_id = ? ORDER BY id DESC");
        $stmt->execute([$user_id]);
        $notes = $stmt->fetchAll();
    } catch (PDOException $e) {
        $notes = [];
    }
}
?>
<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Daborey Step 2</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #0f172a; color: #f8fafc; margin: 0; padding: 20px; }
        header { display: flex; justify-content: space-between; align-items: center; padding: 15px 40px; background: #1e293b; border-bottom: 1px solid #334155; border-radius: 8px; margin-bottom: 30px; flex-wrap: wrap; gap: 20px; }
        .header-title-zone h1 { font-size: 24px; color: #38bdf8; margin: 0 0 5px 0; }
        .user-info { font-size: 14px; color: #94a3b8; }
        .btn-logout { padding: 8px 16px; background: #ef4444; color: white; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 14px; margin-left: 15px; }
        .clock-container { background-color: #090a0f; padding: 10px 15px; border-radius: 8px; border: 1px solid #383121; display: grid; grid-template-columns: repeat(4, 70px); gap: 6px; text-align: center; }
        .clock-cell { background-color: #161922; padding: 6px 4px; border-radius: 4px; border: 1px solid #2d2618; }
        .cell-label { font-size: 10px; color: #d1b477; display: block; }
        .cell-value { font-size: 20px; font-weight: bold; color: #ffb700; }
        .date-cell { grid-column: span 4; font-size: 12px; color: #bdc5e1; display: flex; justify-content: space-around; }
        .day-highlight { color: #ffb700; font-weight: bold; }
        .twofa-card { background: #1e293b; max-width: 400px; margin: 40px auto; padding: 25px; border-radius: 8px; border: 1px solid #334155; text-align: center; }
        .twofa-card input { width: 100%; padding: 10px; margin: 15px 0; background: #0f172a; border: 1px solid #334155; color: white; font-size: 18px; letter-spacing: 4px; text-align: center; border-radius: 4px; box-sizing: border-box; }
        .twofa-card button { width: 100%; padding: 10px; background: #0284c7; border: none; color: white; font-weight: bold; border-radius: 4px; cursor: pointer; }
        .note-creator-container { display: flex; justify-content: center; margin-bottom: 40px; }
        .note-creator { background: #1e293b; width: 100%; max-width: 500px; padding: 15px; border-radius: 8px; border: 1px solid #334155; }
        .note-creator input, .note-creator textarea { width: 100%; background: transparent; border: none; color: #f8fafc; outline: none; font-family: inherit; resize: none; box-sizing: border-box; }
        .note-creator input { font-size: 16px; font-weight: bold; margin-bottom: 10px; }
        .note-creator textarea { font-size: 14px; min-height: 80px; }
        .note-creator button { background: #0284c7; color: white; border: none; padding: 6px 16px; border-radius: 4px; font-weight: bold; cursor: pointer; }
        .notes-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; max-width: 1200px; margin: 0 auto; }
        .note-card { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 16px; word-wrap: break-word; }
        .note-title { font-size: 16px; font-weight: bold; color: #38bdf8; margin: 0 0 8px 0; }
        .note-content { font-size: 14px; color: #cbd5e1; white-space: pre-wrap; margin: 0 0 12px 0; }
        .note-date { font-size: 11px; color: #64748b; text-align: right; }
        .status-error { text-align: center; color: #ef4444; margin-bottom: 15px; }
    </style>
</head>
<body>
    <header>
        <div class="header-title-zone">
            <h1>Daborey Step 2 Dashboard</h1>
            <div class="user-info">
                Authenticated Entity: <strong><?php echo sanitize($_SESSION['username'] ?? 'User'); ?></strong>
                <a href="<?php echo BASE_URL; ?>/logout.php" class="btn-logout">Sign Out</a>
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

    <?php if ($require_2fa_step): ?>
        <div class="twofa-card">
            <h2>Two-Factor Authentication</h2>
            <p style="font-size:14px; color:#94a3b8;">Enter the 6-digit verification code from your authenticator app.</p>
            <?php if (!empty($error_msg)): ?><div class="status-error"><?php echo sanitize($error_msg); ?></div><?php endif; ?>
            <form method="POST" action="dashboard.php">
                <input type="hidden" name="action" value="verify_2fa">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="text" name="totp_token" maxlength="6" placeholder="000000" required autofocus autocomplete="off">
                <button type="submit">Verify Token</button>
            </form>
        </div>
    <?php else: ?>
        <?php if (!empty($status_msg)): ?><div class="status-error"><?php echo sanitize($status_msg); ?></div><?php endif; ?>

        <div class="note-creator-container">
            <form class="note-creator" method="POST" action="dashboard.php">
                <input type="hidden" name="action" value="create_note">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="text" name="title" placeholder="ចំណងជើង (Title)..." autocomplete="off" required>
                <textarea name="content" placeholder="សរសេរកំណត់ចំណាំទីនេះ (Take a note...)" required></textarea>
                <div style="display:flex; justify-content:flex-end; margin-top:10px;">
                    <button type="submit">រក្សាទុក</button>
                </div>
            </form>
        </div>

        <div class="notes-grid">
            <?php if (!empty($notes)): ?>
                <?php foreach ($notes as $note): ?>
                    <div class="note-card">
                        <div class="note-title"><?php echo sanitize($note['title'] ?: 'Untitled'); ?></div>
                        <div class="note-content"><?php echo sanitize($note['content']); ?></div>
                        <div class="note-date"><?php echo sanitize(date("d M Y, h:i A", strtotime($note['created_at']))); ?></div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="text-align:center; color:#64748b; width:100%; grid-column:1/-1;">មិនទាន់មានកំណត់ចំណាំនៅឡើយទេ។ (No notes found.)</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

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