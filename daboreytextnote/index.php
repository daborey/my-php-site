<?php
// ============================================
// FILE: daboreytextnote/index.php
// ============================================

require_once 'config.php';
require_once 'functions.php';

// Native SQLite Logging Helper
function log_sqlite_event($db, $username, $event_type) {
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $stmt = $db->prepare("INSERT INTO system_logs (username, event_type, ip_address) VALUES (?, ?, ?)");
        $stmt->execute([$username, $event_type, $ip_address]);
    } catch (Exception $e) {
        error_log("Logging exception: " . $e->getMessage());
    }
}

// 1. Enforce Authentication Guard
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 2. 15-Minute Session Timeout Check
$max_idle_seconds = 900;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $max_idle_seconds)) {
    log_sqlite_event($db, $_SESSION['username'] ?? 'UNKNOWN', 'SESSION_TIMEOUT_EXPIRED');
    session_unset();
    session_destroy();
    header("Location: login.php?expired=1");
    exit;
}
$_SESSION['last_activity'] = time();

$user_id = $_SESSION['user_id'];
$status_msg = "";

// Generate CSRF Token if missing
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 3. Handle Form Submissions (Note Creation)
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
                header("Location: index.php");
                exit;
            } else {
                $status_msg = "Error saving note.";
            }
        } catch (PDOException $e) {
            $status_msg = "Database error: " . $e->getMessage();
        }
    }
}

// 4. Fetch Notes for Authenticated User
$notes = [];
try {
    $stmt = $db->prepare("SELECT title, content, created_at FROM notes WHERE user_id = ? ORDER BY id DESC");
    $stmt->execute([$user_id]);
    $notes = $stmt->fetchAll();
} catch (PDOException $e) {
    $notes = [];
}
?>