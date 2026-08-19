<?php
// 1. Session Setup
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Load Core Configuration & Security
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
if (file_exists(__DIR__ . '/security.php')) {
    require_once __DIR__ . '/security.php';
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

// Ensure necessary SQLite tables exist
try {
    $db->exec("CREATE TABLE IF NOT EXISTS notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        title TEXT,
        content TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS system_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT,
        event_type TEXT,
        ip_address TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    // Database initialized
}

// Logging Helper
function log_sqlite_event($db, $username, $event_type) {
    try {
        $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        if (strpos($ip_address, ',') !== false) {
            $ip_address = trim(explode(',', $ip_address)[0]);
        }
        $stmt = $db->prepare("INSERT INTO system_logs (username, event_type, ip_address) VALUES (?, ?, ?)");
        $stmt->execute([$username, $event_type, $ip_address]);
    } catch (Exception $e) {
        error_log("Logging exception: " . $e->getMessage());
    }
}

// 4. Enforce Authentication Guard
if (!isset($_SESSION['user_id'])) {
    header("Location: /daboreytextnote/login.php");
    exit;
}

// 5. Session Timeout Check (15 Minutes)
$max_idle_seconds = 900;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $max_idle_seconds)) {
    log_sqlite_event($db, $_SESSION['username'] ?? 'UNKNOWN', 'SESSION_TIMEOUT_EXPIRED');
    session_unset();
    session_destroy();
    header("Location: /daboreytextnote/login.php?expired=1");
    exit;
}
$_SESSION['last_activity'] = time();

$user_id = $_SESSION['user_id'];
$status_msg = "";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 6. Handle Form Submissions (Note Creation)
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
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
                header("Location: /daboreytextnote/index.php");
                exit;
            } else {
                $status_msg = "Error saving note.";
            }
        } catch (PDOException $e) {
            $status_msg = "Database error: " . $e->getMessage();
        }
    }
}

// 7. Fetch User Notes
$notes = [];
try {
    $stmt = $db->prepare("SELECT title, content, created_at FROM notes WHERE user_id = ? ORDER BY id DESC");
    $stmt->execute([$user_id]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $notes = [];
}
?>
<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Dashboard & Notes</title>
    <style>
        body { 
            font-family: 'Kantumruy Pro', 'Khmer OS Battambang', 'Segoe UI', Arial, sans-serif; 
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
        .btn-logout { 
            padding: 8px 16px; 
            background: #ef4444; 
            color: white; 
            text-decoration: none; 
            border-radius: 4px; 
            font-weight: bold; 
            font-size: 14px; 
            margin-left: 15px; 
            transition: background 0.2s; 
        }
        .btn-logout:hover { background: #dc2626; }

        .clock-container {
            background-color: #090a0f;
            padding: 10px 15px;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.4), inset 0 0 10px rgba(255, 174, 0, 0.05);
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
        .cell-label {
            font-size: 10px;
            font-weight: 500;
            color: #d1b477;
            margin-bottom: 2px;
            display: block;
        }
        .cell-value {
            font-size: 20px;
            font-weight: bold;
            color: #ffb700; 
            text-shadow: 0 0 8px rgba(255, 183, 0, 0.5);
            line-height: 1.1;
        }
        #ampm { font-size: 16px; }
        .date-cell {
            grid-column: span 4;
            padding: 4px;
            font-size: 12px;
            color: #bdc5e1;
            display: flex;
            justify-content: space-around;
            align-items: center;
            font-weight: 500;
            background: transparent;
            border: none;
        }
        .day-highlight {
            color: #ffb700;
            font-weight: bold;
            background: rgba(255, 183, 0, 0.1);
            padding: 1px 6px;
            border-radius: 3px;
        }

        .note-creator-container { display: flex; justify-content: center; margin-bottom: 40px; }
        .note-creator { background: #1e293b; width: 100%; max-width: 500px; padding: 15px; border-radius: 8px; border: 1px solid #334155; box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
        .note-creator input, .note-creator textarea { width: 100%; background: transparent; border: none; color: #f8fafc; outline: none; font-family: inherit; resize: none; box-sizing: border-box; }
        .note-creator input { font-size: 16px; font-weight: bold; margin-bottom: 10px; }
        .note-creator textarea { font-size: 14px; min-height: 80px; }
        .note-creator .actions { display: flex; justify-content: flex-end; margin-top: 10px; }
        .note-creator button { background: #0284c7; color: white; border: none; padding: 6px 16px; border-radius: 4px; font-weight: bold; cursor: pointer; transition: background 0.2s; }
        .note-creator button:hover { background: #0369a1; }

        .notes-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; max-width: 1200px; margin: 0 auto; }
        .note-card { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 16px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); display: flex; flex-direction: column; justify-content: space-between; word-wrap: break-word; }
        .note-title { font-size: 16px; font-weight: bold; color: #38bdf8; margin: 0 0 8px 0; }
        .note-content { font-size: 14px; color: #cbd5e1; white-space: pre-wrap; margin: 0 0 12px 0; flex-grow: 1; }
        .note-date { font-size: 11px; color: #64748b; text-align: right; }
        
        .no-notes { text-align: center; color: #64748b; font-size: 16px; width: 100%; grid-column: 1 / -1; margin-top: 40px; }
        .status-error { text-align: center; color: #ef4444; margin-bottom: 15px; }
    </style>
</head>
<body>

    <header>
        <div class="header-title-zone">
            <h1>Secure Notes Portal</h1>
            <div class="user-info">
                Authenticated Entity: <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></strong>
                <a href="/daboreytextnote/logout.php" class="btn-logout">Sign Out</a>
            </div>
        </div>

        <div class="clock-container">
            <div class="clock-cell">
                <span class="cell-label">ម៉ោង</span>
                <div id="hours" class="cell-value">00</div>
            </div>
            <div class="clock-cell">
                <span class="cell-label">នាទី</span>
                <div id="minutes" class="cell-value">00</div>
            </div>
            <div class="clock-cell">
                <span class="cell-label">វិនាទី</span>
                <div id="seconds" class="cell-value">00</div>
            </div>
            <div class="clock-cell">
                <span class="cell-label">ពេល</span>
                <div id="ampm" class="cell-value">AM</div>
            </div>
            <div class="date-cell">
                <span id="khmer-day" class="day-highlight">---</span>
                <span id="khmer-date">00 --- 0000</span>
            </div>
        </div>
    </header>

    <?php if (!empty($status_msg)): ?>
        <div class="status-error"><?php echo htmlspecialchars($status_msg); ?></div>
    <?php endif; ?>

    <div class="note-creator-container">
        <form class="note-creator" method="POST" action="/daboreytextnote/index.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="text" name="title" placeholder="ចំណងជើង (Title)..." autocomplete="off" required>
            <textarea name="content" placeholder="សរសេរកំណត់ចំណាំទីនេះ (Take a note...)" required></textarea>
            <div class="actions">
                <button type="submit">រក្សាទុក</button>
            </div>
        </form>
    </div>

    <div class="notes-grid">
        <?php if (!empty($notes)): ?>
            <?php foreach ($notes as $note): ?>
                <div class="note-card">
                    <div>
                        <div class="note-title"><?php echo htmlspecialchars($note['title'] ?: 'Untitled'); ?></div>
                        <div class="note-content"><?php echo htmlspecialchars($note['content']); ?></div>
                    </div>
                    <div class="note-date">
                        <?php echo htmlspecialchars(date("d M Y, h:i A", strtotime($note['created_at']))); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="no-notes">មិនទាន់មានកំណត់ចំណាំនៅឡើយទេ។ (No notes found.)</div>
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
            const rawMinutes = now.getMinutes();
            const rawSeconds = now.getSeconds();

            const ampmKhmer = rawHours >= 12 ? 'ល្ងាច' : 'ព្រឹក';
            rawHours = rawHours % 12;
            rawHours = rawHours ? rawHours : 12; 

            document.getElementById('hours').innerText = toKhmerNum(rawHours);
            document.getElementById('minutes').innerText = toKhmerNum(rawMinutes);
            document.getElementById('seconds').innerText = toKhmerNum(rawSeconds);
            document.getElementById('ampm').innerText = ampmKhmer;

            const dayName = khmerDays[now.getDay()];
            const dayNum = toKhmerNum(now.getDate());
            const monthName = khmerMonths[now.getMonth()];
            const yearNum = now.getFullYear().toString().split('').map(digit => khmerNumerals[parseInt(digit)] || digit).join('');

            document.getElementById('khmer-day').innerText = 'ថ្ងៃ' + dayName;
            document.getElementById('khmer-date').innerText = dayNum + ' ' + monthName + ' ' + yearNum;
        }

        updateKhmerClock();
        setInterval(updateKhmerClock, 1000);
    </script>
</body>
</html>