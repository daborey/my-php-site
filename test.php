// 6. Handle Form Submissions (Create & Delete)
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        log_sqlite_event($db, $_SESSION['username'] ?? 'UNKNOWN', 'CSRF_VALIDATION_FAILURE');
        die("Security token validation failed.");
    }

    $action = $_POST['action'] ?? 'create';

    // Handle Note Deletion
    if ($action === 'delete') {
        $note_id = (int)($_POST['note_id'] ?? 0);
        if ($note_id > 0) {
            try {
                $stmt = $db->prepare("DELETE FROM notes WHERE id = ? AND user_id = ?");
                $stmt->execute([$note_id, $user_id]);
                log_sqlite_event($db, $_SESSION['username'] ?? 'UNKNOWN', 'NOTE_DELETED');
                header("Location: /daboreytextnote/index.php");
                exit;
            } catch (PDOException $e) {
                $status_msg = "Error deleting note: " . $e->getMessage();
            }
        }
    } 
    // Handle Note Creation
    else {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');

        if (!empty($title) || !empty($content)) {
            try {
                $stmt = $db->prepare("INSERT INTO notes (user_id, title, content) VALUES (?, ?, ?)");
                if ($stmt->execute([$user_id, $title, $content])) {
                    log_sqlite_event($db, $_SESSION['username'] ?? 'UNKNOWN', 'NOTE_CREATED');
                    header("Location: /daboreytextnote/index.php");
                    exit;
                }
            } catch (PDOException $e) {
                $status_msg = "Database error: " . $e->getMessage();
            }
        }
    }
}