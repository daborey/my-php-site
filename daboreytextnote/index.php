<?php

$requestUri = $_SERVER['REQUEST_URI'];
$parsedUrl  = parse_url($requestUri);
$path       = $parsedUrl['path'] ?? '';

if (!str_ends_with($path, '/') && !pathinfo($path, PATHINFO_EXTENSION)) {
    $queryString = isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '';
    header("Location: " . $path . '/' . $queryString, true, 301);
    exit;
}

// Enable error display to prevent blank white screens during debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();

if (file_exists(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';
if (file_exists(__DIR__ . '/security.php')) require_once __DIR__ . '/security.php';

// 1. Connection
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

// 2. Safely patch database schema for existing old databases
try {
    $db->exec("CREATE TABLE IF NOT EXISTS notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER DEFAULT 1,
        title TEXT,
        content TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $tableInfo = $db->query("PRAGMA table_info(notes)")->fetchAll(PDO::FETCH_ASSOC);
    $columns = array_column($tableInfo, 'name');

    if (!in_array('updated_at', $columns)) {
        @$db->exec("ALTER TABLE notes ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP");
    }
    if (!in_array('user_id', $columns)) {
        @$db->exec("ALTER TABLE notes ADD COLUMN user_id INTEGER DEFAULT 1");
    }
} catch (Exception $e) {
    // Schema patching fail-safe
}

// 3. Re-verify column existence to guarantee safe queries
$tableInfo = $db->query("PRAGMA table_info(notes)")->fetchAll(PDO::FETCH_ASSOC);
$existingColumns = array_column($tableInfo, 'name');
$hasUpdatedAt = in_array('updated_at', $existingColumns);

// 4. Session Guards
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Ensure user table has import_key column
try {
    $db->exec("ALTER TABLE users ADD COLUMN import_key TEXT");
} catch (Exception $e) {
    // Column already exists
}

// Retrieve or create persistent key lock for current user
$stmtKey = $db->prepare("SELECT import_key FROM users WHERE id = ?");
$stmtKey->execute([$user_id]);
$userRow = $stmtKey->fetch(PDO::FETCH_ASSOC);

if (empty($userRow['import_key'])) {
    $userImportKey = bin2hex(random_bytes(16));
    $stmtKeyUpdate = $db->prepare("UPDATE users SET import_key = ? WHERE id = ?");
    $stmtKeyUpdate->execute([$userImportKey, $user_id]);
} else {
    $userImportKey = $userRow['import_key'];
}

// Handle Export Request
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    $stmtExport = $db->prepare("SELECT title, content, created_at FROM notes WHERE user_id = ? OR user_id IS NULL OR user_id = 0 ORDER BY created_at DESC");
    $stmtExport->execute([$user_id]);
    $exportNotes = $stmtExport->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="daborey_notes_export_' . date('Y-m-d') . '.html"');

    echo "<!DOCTYPE html>\n<html lang=\"km\">\n<head>\n";
    echo "<meta charset=\"UTF-8\">\n";
    echo "<meta name=\"daborey-key-lock\" content=\"" . htmlspecialchars($userImportKey) . "\">\n";
    echo "<title>Da Borey Text Note - Backup</title>\n";
    echo "<style>body{font-family:sans-serif;margin:20px;background:#0f172a;color:#f8fafc;}.note-card{background:#1e293b;padding:15px;margin-bottom:15px;border-radius:8px;border:1px solid #334155;}.note-title{font-weight:bold;font-size:1.1em;color:#38bdf8;margin-bottom:8px;}.note-content{white-space:pre-wrap;color:#cbd5e1;margin-bottom:10px;}.note-date{font-size:0.8em;color:#64748b;text-align:right;}</style>\n";
    echo "</head>\n<body>\n<h1>Da Borey Text Note Backup</h1>\n";

    foreach ($exportNotes as $note) {
        echo "<div class=\"note-card\">\n";
        echo "  <div class=\"note-title\">" . htmlspecialchars($note['title'] ?: 'Untitled') . "</div>\n";
        echo "  <div class=\"note-content\">" . htmlspecialchars($note['content']) . "</div>\n";
        echo "  <div class=\"note-date\">" . htmlspecialchars($note['created_at']) . "</div>\n";
        echo "</div>\n";
    }

    echo "</body>\n</html>";
    exit;
}

// 5. Handle Form Submissions (Create, Edit, Delete, Import)
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security token validation failed.");
    }

    $action = $_POST['action'] ?? 'create';

    if ($action === 'import') {
        if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
            $htmlContent = file_get_contents($_FILES['import_file']['tmp_name']);

            // Extract and validate Key Lock
            if (preg_match('/<meta\s+name=["\']daborey-key-lock["\']\s+content=["\']([^"\']+)["\']/i', $htmlContent, $matches)) {
                $fileKey = $matches[1];

                if ($fileKey === $userImportKey) {
                    libxml_use_internal_errors(true);
                    $doc = new DOMDocument();
                    $doc->loadHTML(mb_convert_encoding($htmlContent, 'HTML-ENTITIES', 'UTF-8'));
                    libxml_clear_errors();

                    $xpath = new DOMXPath($doc);
                    $cards = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' note-card ')]");

                    $stmtImport = $db->prepare("INSERT INTO notes (user_id, title, content) VALUES (?, ?, ?)");
                    foreach ($cards as $card) {
                        $titleNode = $xpath->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' note-title ')]", $card)->item(0);
                        $contentNode = $xpath->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' note-content ')]", $card)->item(0);

                        $title = $titleNode ? trim($titleNode->nodeValue) : 'Untitled';
                        $content = $contentNode ? trim($contentNode->nodeValue) : '';

                        if (!empty($title) || !empty($content)) {
                            $stmtImport->execute([$user_id, $title, $content]);
                        }
                    }
                    header("Location: index.php?import=success");
                    exit;
                } else {
                    die("Import failed: Invalid Key Lock. This HTML file was not generated by your account.");
                }
            } else {
                die("Import failed: Key Lock metadata is missing from the uploaded file.");
            }
        } else {
            die("Please select a valid HTML backup file to import.");
        }
    } elseif ($action === 'delete') {
        $note_id = (int)($_POST['note_id'] ?? 0);
        if ($note_id > 0) {
            $stmt = $db->prepare("DELETE FROM notes WHERE id = ?");
            $stmt->execute([$note_id]);
            header("Location: index.php");
            exit;
        }
    } elseif ($action === 'edit') {
        $note_id = (int)($_POST['note_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');

        if ($note_id > 0) {
            try {
                if ($hasUpdatedAt) {
                    $stmt = $db->prepare("UPDATE notes SET title = ?, content = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                } else {
                    $stmt = $db->prepare("UPDATE notes SET title = ?, content = ? WHERE id = ?");
                }
                $stmt->execute([$title, $content, $note_id]);
                header("Location: index.php");
                exit;
            } catch (PDOException $e) {
                die("Failed to update note: " . $e->getMessage());
            }
        }
    } else {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');

        if (!empty($title) || !empty($content)) {
            $stmt = $db->prepare("INSERT INTO notes (user_id, title, content) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $title, $content]);
            header("Location: index.php");
            exit;
        }
    }
}

// 6. Fetch Notes
try {
    $stmt = $db->prepare("SELECT * FROM notes WHERE user_id = ? OR user_id IS NULL OR user_id = 0 ORDER BY id DESC");
    $stmt->execute([$user_id]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Failed to fetch notes: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="km">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Dashboard & Notes</title>
    <script>
        function openEditModal(id, title, content) {
            document.getElementById('edit_note_id').value = id;
            document.getElementById('edit_title').value = title;
            document.getElementById('edit_content').value = content;
            document.getElementById('editModal').style.display = 'flex';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }
    </script>
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

        .header-title-zone h1 {
            font-size: 24px;
            color: #38bdf8;
            margin: 0 0 5px 0;
        }

        .user-info {
            font-size: 14px;
            color: #94a3b8;
        }

        .btn-logout {
            padding: 8px 16px;
            background: #ef4444;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
            font-size: 14px;
            margin-left: 15px;
        }

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

        .cell-label {
            font-size: 10px;
            color: #d1b477;
            margin-bottom: 2px;
            display: block;
        }

        .cell-value {
            font-size: 20px;
            font-weight: bold;
            color: #ffb700;
        }

        .date-cell {
            grid-column: span 4;
            padding: 4px;
            font-size: 12px;
            color: #bdc5e1;
            display: flex;
            justify-content: space-around;
            font-weight: 500;
        }

        .day-highlight {
            color: #ffb700;
            font-weight: bold;
        }

        .note-creator-container {
            display: flex;
            justify-content: center;
            margin-bottom: 40px;
        }

        .note-creator {
            background: #1e293b;
            width: 100%;
            max-width: 500px;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #334155;
        }

        .note-creator input,
        .note-creator textarea {
            width: 100%;
            background: transparent;
            border: none;
            color: #f8fafc;
            outline: none;
            box-sizing: border-box;
        }

        .note-creator input {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .note-creator textarea {
            font-size: 14px;
            min-height: 80px;
            resize: none;
        }

        .note-creator .actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 10px;
        }

        .note-creator button {
            background: #0284c7;
            color: white;
            border: none;
            padding: 6px 16px;
            border-radius: 4px;
            font-weight: bold;
            cursor: pointer;
        }

        .notes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 16px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .note-card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            word-wrap: break-word;
        }

        .note-title {
            font-size: 16px;
            font-weight: bold;
            color: #38bdf8;
            margin: 0 0 8px 0;
        }

        .note-content {
            font-size: 14px;
            color: #cbd5e1;
            white-space: pre-wrap;
            margin: 0 0 12px 0;
            flex-grow: 1;
        }

        .note-date {
            font-size: 11px;
            color: #64748b;
            text-align: right;
        }
    </style>
</head>

<body>

    <header>
        <div class="header-title-zone">
            <h1>Secure Notes Portal</h1>
            <div class="user-info">
                Authenticated Entity: <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></strong>
                <a href="index.php?action=export" class="btn-logout" style="background:#10b981;">Export HTML</a>
                <button type="button" onclick="document.getElementById('importModal').style.display='flex'" class="btn-logout" style="background:#0284c7; border:none; cursor:pointer;">Import HTML</button>
                <a href="logout.php" class="btn-logout">Sign Out</a>
            </div>
            </div>
        </div>

        <div class="clock-container">
            <div class="clock-cell"><span class="cell-label">ម៉ោង</span>
                <div id="hours" class="cell-value">00</div>
            </div>
            <div class="clock-cell"><span class="cell-label">នាទី</span>
                <div id="minutes" class="cell-value">00</div>
            </div>
            <div class="clock-cell"><span class="cell-label">វិនាទី</span>
                <div id="seconds" class="cell-value">00</div>
            </div>
            <div class="clock-cell"><span class="cell-label">ពេល</span>
                <div id="ampm" class="cell-value" style="font-size:16px;">AM</div>
            </div>
            <div class="date-cell"><span id="khmer-day" class="day-highlight">---</span><span id="khmer-date">00 --- 0000</span></div>
        </div>
    </header>

    <div class="note-creator-container">
        <form class="note-creator" method="POST" action="index.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="create">
            <input type="text" name="title" placeholder="ចំណងជើង (Title)..." autocomplete="off" required>
            <textarea name="content" placeholder="សរសេរកំណត់ចំណាំទីនេះ (Take a note...)" required></textarea>
            <div class="actions">
                <button type="submit">រក្សាទុក</button>
            </div>
        </form>
    </div>

    <div class="notes-grid">
        <?php if (!empty($notes)) : ?>
            <?php foreach ($notes as $note) : ?>
                <div class="note-card">
                    <div>
                        <div class="note-title"><?php echo htmlspecialchars($note['title'] ?: 'Untitled'); ?></div>
                        <div class="note-content"><?php echo htmlspecialchars($note['content']); ?></div>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 12px;">
                        <div style="display: flex; gap: 6px;">
                            <button type="button" onclick="openEditModal(<?php echo $note['id']; ?>, '<?php echo htmlspecialchars(addslashes($note['title'] ?? ''), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes(str_replace(array("\r", "\n"), array('\r', '\n'), $note['content'] ?? '')), ENT_QUOTES); ?>')" style="background: #0284c7; color: white; border: none; padding: 4px 10px; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 12px;">Edit</button>

                            <form method="POST" action="index.php" onsubmit="return confirm('Delete this note?');" style="margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="note_id" value="<?php echo (int)$note['id']; ?>">
                                <button type="submit" style="background: #ef4444; color: white; border: none; padding: 4px 10px; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 12px;">Delete</button>
                            </form>
                        </div>

                        <div class="note-date">
                            <?php echo htmlspecialchars(date("d M Y", strtotime($note['created_at'] ?? 'now'))); ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else : ?>
            <p style="text-align: center; width: 100%; color: #64748b;">No notes found.</p>
        <?php endif; ?>
    </div>

    <!-- Import Modal -->
    <div id="importModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); justify-content: center; align-items: center; z-index: 1000;">
        <div style="background: #1e293b; padding: 24px; border-radius: 8px; width: 90%; max-width: 480px; border: 1px solid #334155;">
            <h3 style="margin-top: 0; color: #38bdf8;">Import Notes (HTML File)</h3>
            <form method="POST" action="index.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="import">

                <div style="margin-bottom: 16px;">
                    <label style="font-size: 14px; color: #cbd5e1; display: block; margin-bottom: 8px;">Select validated backup file:</label>
                    <input type="file" name="import_file" accept=".html,.htm" required style="width: 100%; color: #cbd5e1;">
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 8px;">
                    <button type="button" onclick="document.getElementById('importModal').style.display='none'" style="background: #64748b; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer;">Cancel</button>
                    <button type="submit" style="background: #10b981; color: white; border: none; padding: 6px 12px; border-radius: 4px; font-weight: bold; cursor: pointer;">Upload & Import</button>
                </div>
            </form>
        </div>
    </div>
    <div id="editModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); justify-content: center; align-items: center; z-index: 1000;">
        <div style="background: #1e293b; padding: 24px; border-radius: 8px; width: 90%; max-width: 480px; border: 1px solid #334155;">
            <h3 style="margin-top: 0; color: #38bdf8;">Edit Note</h3>
            <form method="POST" action="index.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="note_id" id="edit_note_id">

                <div style="margin-bottom: 12px;">
                    <input type="text" name="title" id="edit_title" placeholder="Title" style="width: 100%; padding: 8px; background: #0f172a; border: 1px solid #334155; color: white; border-radius: 4px; box-sizing: border-box;">
                </div>

                <div style="margin-bottom: 12px;">
                    <textarea name="content" id="edit_content" rows="5" placeholder="Content" style="width: 100%; padding: 8px; background: #0f172a; border: 1px solid #334155; color: white; border-radius: 4px; box-sizing: border-box;"></textarea>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 8px;">
                    <button type="button" onclick="closeEditModal()" style="background: #64748b; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer;">Cancel</button>
                    <button type="submit" style="background: #10b981; color: white; border: none; padding: 6px 12px; border-radius: 4px; font-weight: bold; cursor: pointer;">Save Changes</button>
                </div>
            </form>
        </div>
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
            rawHours = rawHours % 12;
            rawHours = rawHours ? rawHours : 12;
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