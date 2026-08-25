<?php
header("Content-Type: text/html; charset=utf-8");

// 1. Production security settings
ini_set('display_errors', 0);
error_reporting(E_ALL);

// 2. Dynamic Path Resolution for Subfolders
$currentScript = $_SERVER['SCRIPT_NAME'];

// 3. System & Database Configuration
$SYSTEM_NAME = 'employee';
$DATABASE_NAME = 'employee.db'; // Switched dynamically to employee database

$SCHEMA_FIELDS = array (
  0 => 'ឈ្មោះបុគ្គលិក', // Employee Name
  1 => 'តួនាទី',        // Position / Role
  2 => 'ប្រាក់ខែ',      // Salary / Rate
);

// 4. Persistent Storage Setup
$storageDir = '/mnt/storage';
$uploadsDir = $storageDir . '/uploads';

if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0777, true);
}
if (!is_dir($uploadsDir)) {
    @mkdir($uploadsDir, 0777, true);
}

// ---------------- Handle IMAGE SERVING ----------------
if (isset($_GET["action"]) && $_GET["action"] === "view_image" && !empty($_GET["file"])) {
    $filename = basename($_GET["file"]);
    $filePath = $uploadsDir . '/' . $filename;

    if (file_exists($filePath) && is_file($filePath)) {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp'
        ];
        
        $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
        header("Content-Type: " . $mime);
        header("Content-Length: " . filesize($filePath));
        readfile($filePath);
        exit;
    } else {
        header("HTTP/1.0 404 Not Found");
        exit("Image not found.");
    }
}

// 5. Connect to SQLite database using PDO (employee.db)
try {
    $dbPath = $storageDir . '/' . $DATABASE_NAME;
    $db = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db.exec("PRAGMA encoding = 'UTF-8';");
} catch (PDOException $e) {
    die("Database connection failed. Please check server configuration.");
}

$SYSTEM_LOGO = 'uploads/logo_6a8d888b85ca3.jpg';
$error = "";

// Helper function using current dynamic script URL
function getImageUrl($path) {
    global $currentScript;
    if (empty($path)) return '';
    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0 || strpos($path, 'data:') === 0) {
        return $path;
    }
    $filename = basename($path);
    return $currentScript . '?action=view_image&file=' . urlencode($filename);
}

// Ensure records table exists safely
$cols = [
    'id INTEGER PRIMARY KEY AUTOINCREMENT',
    'avatar TEXT',
    'created_at DATETIME DEFAULT CURRENT_TIMESTAMP'
];
foreach ($SCHEMA_FIELDS as $field) {
    $cols[] = '"' . str_replace('"', '""', $field) . '" TEXT';
}
$db->exec('CREATE TABLE IF NOT EXISTS records (' . implode(', ', $cols) . ');');

// Dynamically check and add missing columns via PDO
$table_info = $db->query("PRAGMA table_info(records)");
$existing_cols = [];
if ($table_info) {
    while ($col_row = $table_info->fetch(PDO::FETCH_ASSOC)) {
        $existing_cols[] = $col_row['name'];
    }
}

foreach ($SCHEMA_FIELDS as $field) {
    if (!in_array($field, $existing_cols, true)) {
        $safe_field = str_replace('"', '""', $field);
        @$db->exec('ALTER TABLE records ADD COLUMN "' . $safe_field . '" TEXT;');
    }
}

// Helper to remove files safely from persistent volume
function removeImageFile($path) {
    global $uploadsDir;
    if (!empty($path) && strpos($path, 'http') !== 0 && strpos($path, 'data:') !== 0) {
        $filename = basename($path);
        $full_path = $uploadsDir . '/' . $filename;
        if (file_exists($full_path) && is_file($full_path)) {
            @unlink($full_path);
        }
    }
}

// ---------------- Handle EXCEL (.xls) EXPORT ----------------
if (isset($_GET["action"]) && $_GET["action"] === "export") {
    $clean_sys_name = preg_replace("/[^a-zA-Z0-9_\-]/", "_", $SYSTEM_NAME);
    $clean_sys_name = trim(preg_replace("/_+/", "_", $clean_sys_name), "_");
    if (empty($clean_sys_name)) {
        $clean_sys_name = "System";
    }

    $filename = $clean_sys_name . "_export_" . date("Y-m-d_H-i-s") . ".xls";
    
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=" . $filename);
    header("Pragma: no-cache");
    header("Expires: 0");

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="utf-8" /></head>';
    echo '<body>';
    echo '<table border="1" style="border-collapse: collapse;">';
    echo '<tr style="background-color: #0f172a; color: #ffffff; font-weight: bold; text-align: center;">';
    echo '<th style="padding: 10px;">ID</th>';
    foreach ($SCHEMA_FIELDS as $field) {
        echo '<th style="padding: 10px;">' . htmlspecialchars($field, ENT_QUOTES, "UTF-8") . '</th>';
    }
    echo '<th style="padding: 10px;">Profile Image Link / Path</th>';
    echo '<th style="padding: 10px;">Created At</th>';
    echo '</tr>';

    $stmt = $db->query("SELECT * FROM records ORDER BY id ASC");
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo '<tr>';
            echo '<td style="padding: 8px; text-align: center;">' . $row["id"] . '</td>';
            foreach ($SCHEMA_FIELDS as $field) {
                echo '<td style="padding: 8px;">' . htmlspecialchars($row[$field] ?? "", ENT_QUOTES, "UTF-8") . '</td>';
            }
            echo '<td style="padding: 8px;">' . htmlspecialchars($row["avatar"] ?? "", ENT_QUOTES, "UTF-8") . '</td>';
            echo '<td style="padding: 8px;">' . htmlspecialchars($row["created_at"] ?? "", ENT_QUOTES, "UTF-8") . '</td>';
            echo '</tr>';
        }
    }
    echo '</table>';
    echo '</body></html>';
    exit;
}

// ---------------- Handle DELETE ----------------
if (isset($_GET["action"]) && $_GET["action"] === "delete" && isset($_GET["id"])) {
    $id = (int)$_GET["id"];
    
    $stmt = $db->prepare("SELECT avatar FROM records WHERE id = :id");
    if ($stmt) {
        $stmt->execute([':id' => $id]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res) {
            removeImageFile($res["avatar"]);
            
            $del_stmt = $db->prepare("DELETE FROM records WHERE id = :id");
            $del_stmt->execute([':id' => $id]);
        }
    }
    header("Location: " . $currentScript);
    exit;
}

// ---------------- Handle CREATE & UPDATE ----------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"])) {
    $action = $_POST["action"];
    $record_id = isset($_POST["id"]) ? (int)$_POST["id"] : 0;
    $avatar_path = "";
    $allowed = ["jpg", "jpeg", "png", "gif", "webp"];

    $existing = null;
    if ($action === "update" && $record_id > 0) {
        $stmt = $db->prepare("SELECT * FROM records WHERE id = :id");
        if ($stmt) {
            $stmt->execute([':id' => $record_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            $avatar_path = $existing["avatar"] ?? "";
        }
    }

    if (isset($_FILES["avatar"]) && $_FILES["avatar"]["error"] === UPLOAD_ERR_OK) {
        $tmp_name = $_FILES["avatar"]["tmp_name"];
        $ext = strtolower(pathinfo($_FILES["avatar"]["name"], PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $filename = uniqid("img_", true) . "." . $ext;
            $destination = $uploadsDir . '/' . $filename;
            
            if (move_uploaded_file($tmp_name, $destination)) {
                chmod($destination, 0666);
                if ($action === "update" && !empty($existing["avatar"])) {
                    removeImageFile($existing["avatar"]);
                }
                $avatar_path = "uploads/" . $filename;
            } else {
                $error = "Failed to upload file to volume storage. Check folder permissions.";
            }
        } else {
            $error = "Invalid file format! Allowed: JPG, JPEG, PNG, GIF, WEBP.";
        }
    } 
    elseif (!empty($_POST["avatar_url"])) {
        $url = trim($_POST["avatar_url"]);
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            if ($action === "update" && !empty($existing["avatar"])) {
                removeImageFile($existing["avatar"]);
            }
            $avatar_path = $url;
        } else {
            $error = "Invalid Image URL provided.";
        }
    } 
    elseif ($action === "create" && empty($avatar_path)) {
        $error = "Please upload an image file or provide an Image URL.";
    }

    if (empty($error)) {
        if ($action === "create") {
            $cols = ["avatar"];
            $vals = [":avatar"];
            $params = [":avatar" => $avatar_path];

            foreach ($SCHEMA_FIELDS as $field) {
                $cols[] = '"' . str_replace('"', '""', $field) . '"';
                $param_key = ":" . md5($field);
                $vals[] = $param_key;
                $params[$param_key] = $_POST[$field] ?? "";
            }

            $stmt = $db->prepare("INSERT INTO records (" . implode(", ", $cols) . ") VALUES (" . implode(", ", $vals) . ")");
            if ($stmt) {
                $stmt->execute($params);
            }
        } 
        elseif ($action === "update" && $record_id > 0) {
            $set_clauses = ["avatar = :avatar"];
            $params = [":avatar" => $avatar_path, ":id" => $record_id];

            foreach ($SCHEMA_FIELDS as $field) {
                $param_key = ":" . md5($field);
                $set_clauses[] = '"' . str_replace('"', '""', $field) . '" = ' . $param_key;
                $params[$param_key] = $_POST[$field] ?? "";
            }

            $stmt = $db->prepare("UPDATE records SET " . implode(", ", $set_clauses) . " WHERE id = :id");
            if ($stmt) {
                $stmt->execute($params);
            }
        }

        header("Location: " . $currentScript);
        exit;
    }
}

// Fetch record to edit
$edit_data = null;
if (isset($_GET["action"]) && $_GET["action"] === "edit" && isset($_GET["id"])) {
    $edit_id = (int)$_GET["id"];
    $stmt = $db->prepare("SELECT * FROM records WHERE id = :id");
    if ($stmt) {
        $stmt->execute([':id' => $edit_id]);
        $edit_data = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

$results = $db->query("SELECT * FROM records ORDER BY id ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($SYSTEM_NAME, ENT_QUOTES, "UTF-8") ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background-color: #f8fafc; color: #0f172a; margin: 0; padding: 24px; line-height: 1.5; }

        .navbar { display: flex; align-items: center; justify-content: space-between; background-color: #ffffff; padding: 16px 24px; border-radius: 10px; border: 1px solid #e2e8f0; margin-bottom: 24px; }
        .navbar-brand { display: flex; align-items: center; gap: 14px; }
        .navbar-brand img { height: 38px; border-radius: 6px; object-fit: contain; }
        .navbar-title { font-size: 18px; font-weight: 700; color: #0f172a; margin: 0; text-transform: capitalize; }

        .main-grid { display: grid; grid-template-columns: 340px minmax(0, 1fr); gap: 24px; align-items: start; }
        .card { background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px; overflow: hidden; }

        .card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        .card-title { font-size: 15px; font-weight: 600; color: #0f172a; margin: 0; }

        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 13px; font-weight: 500; color: #64748b; margin-bottom: 6px; }
        .form-control { width: 100%; padding: 9px 12px; font-size: 14px; font-family: inherit; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; color: #0f172a; transition: border-color 0.15s ease; }
        .form-control:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }

        .avatar-section { display: flex; align-items: center; gap: 16px; margin-bottom: 16px; }
        .avatar-preview { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; border: 1px solid #e2e8f0; background-color: #f1f5f9; flex-shrink: 0; }
        .avatar-inputs { display: flex; flex-direction: column; gap: 8px; width: 100%; }

        .btn { display: inline-flex; align-items: center; justify-content: center; padding: 9px 16px; font-size: 14px; font-weight: 500; font-family: inherit; border-radius: 6px; border: 1px solid transparent; cursor: pointer; text-decoration: none; transition: all 0.15s ease; }
        .btn-primary { background-color: #2563eb; color: #ffffff; width: 100%; }
        .btn-primary:hover { background-color: #1d4ed8; }
        .btn-secondary { background-color: #ffffff; border-color: #e2e8f0; color: #0f172a; width: 100%; margin-top: 8px; }
        .btn-secondary:hover { background-color: #f8fafc; }
        .btn-excel { background-color: #059669; color: white; padding: 7px 12px; font-size: 13px; }
        .btn-excel:hover { background-color: #047857; }

        .table-container { 
            width: 100%; 
            overflow-x: auto; 
            border: 1px solid #e2e8f0; 
            border-radius: 8px; 
            -webkit-overflow-scrolling: touch;
        }
        table { width: 100%; border-collapse: collapse; min-width: max-content; font-size: 14px; }
        th { background-color: #f8fafc; color: #64748b; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; text-align: left; padding: 12px 16px; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
        td { padding: 12px 16px; border-bottom: 1px solid #e2e8f0; color: #0f172a; vertical-align: middle; white-space: nowrap; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background-color: #f8fafc; }

        .avatar-thumb { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 1px solid #e2e8f0; }
        .action-links { display: flex; gap: 8px; }
        .btn-action { padding: 4px 10px; font-size: 12px; font-weight: 500; border-radius: 4px; text-decoration: none; border: 1px solid #e2e8f0; color: #0f172a; background-color: #ffffff; }
        .btn-action:hover { background-color: #f1f5f9; }
        .btn-action-delete { color: #ef4444; border-color: #fca5a5; background-color: #fef2f2; }
        .btn-action-delete:hover { background-color: #fee2e2; }

        .alert { background-color: #fef2f2; border: 1px solid #fca5a5; color: #ef4444; padding: 12px 16px; border-radius: 6px; font-size: 14px; margin-bottom: 20px; }

        @media (max-width: 900px) {
            .main-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="navbar">
    <div class="navbar-brand">
        <?php if (!empty($SYSTEM_LOGO)): ?>
            <?php $logo_src = getImageUrl($SYSTEM_LOGO); ?>
            <img src="<?= htmlspecialchars($logo_src, ENT_QUOTES, "UTF-8") ?>" alt="Logo">
        <?php endif; ?>
        <h1 class="navbar-title"><?= htmlspecialchars($SYSTEM_NAME, ENT_QUOTES, "UTF-8") ?></h1>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?></div>
<?php endif; ?>

<div class="main-grid">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><?= $edit_data ? "Edit Record" : "New Entry" ?></h2>
        </div>
        <form method="POST" action="<?= htmlspecialchars($currentScript, ENT_QUOTES, "UTF-8") ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="<?= $edit_data ? "update" : "create" ?>">
            <?php if ($edit_data): ?>
                <input type="hidden" name="id" value="<?= $edit_data["id"] ?>">
            <?php endif; ?>

            <div class="form-group">
                <label class="form-label">Profile Image</label>
                <div class="avatar-section">
                    <?php 
                    $initial_img = "data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='64' height='64' viewBox='0 0 24 24' fill='%23cbd5e1'><path d='M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z'/></svg>";
                    if ($edit_data && !empty($edit_data["avatar"])) {
                        $initial_img = getImageUrl($edit_data["avatar"]);
                    }
                    ?>
                    <img id="avatar-preview" src="<?= htmlspecialchars($initial_img, ENT_QUOTES, "UTF-8") ?>" class="avatar-preview" alt="Preview">
                    <div class="avatar-inputs">
                        <input type="file" name="avatar" class="form-control" accept="image/*" onchange="previewAvatarFile(this)">
                        <input type="url" name="avatar_url" class="form-control" placeholder="Or Image URL" oninput="previewAvatarUrl(this.value)" value="<?= ($edit_data && strpos($edit_data['avatar'], 'http') === 0) ? htmlspecialchars($edit_data['avatar'], ENT_QUOTES, 'UTF-8') : '' ?>">
                    </div>
                </div>
            </div>

            <?php foreach ($SCHEMA_FIELDS as $field): ?>
            <div class="form-group">
                <label class="form-label"><?= htmlspecialchars($field, ENT_QUOTES, "UTF-8") ?></label>
                <input type="text" class="form-control" name="<?= htmlspecialchars($field, ENT_QUOTES, "UTF-8") ?>" value="<?= htmlspecialchars($edit_data[$field] ?? "", ENT_QUOTES, "UTF-8") ?>" required>
            </div>
            <?php endforeach; ?>

            <button type="submit" class="btn btn-primary"><?= $edit_data ? "Update Record" : "Save Record" ?></button>
            <?php if ($edit_data): ?>
                <a href="<?= htmlspecialchars($currentScript, ENT_QUOTES, "UTF-8") ?>" class="btn btn-secondary">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">All Records</h2>
            <a href="<?= htmlspecialchars($currentScript, ENT_QUOTES, "UTF-8") ?>?action=export" class="btn btn-excel">Export XLS</a>
        </div>
        
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th style="width: 50px;">Avatar</th>
                        <?php foreach ($SCHEMA_FIELDS as $field): ?>
                        <th><?= htmlspecialchars($field, ENT_QUOTES, "UTF-8") ?></th>
                        <?php endforeach; ?>
                        <th style="width: 110px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($results): ?>
                        <?php while ($row = $results->fetch(PDO::FETCH_ASSOC)): ?>
                        <tr>
                            <td>
                                <?php if (!empty($row["avatar"])): ?>
                                    <img src="<?= htmlspecialchars(getImageUrl($row["avatar"]), ENT_QUOTES, "UTF-8") ?>" class="avatar-thumb">
                                <?php else: ?>
                                    <span style="color: #64748b; font-size: 12px;">None</span>
                                <?php endif; ?>
                            </td>
                            <?php foreach ($SCHEMA_FIELDS as $field): ?>
                            <td><?= htmlspecialchars($row[$field] ?? "", ENT_QUOTES, "UTF-8") ?></td>
                            <?php endforeach; ?>
                            <td>
                                <div class="action-links">
                                    <a href="<?= htmlspecialchars($currentScript, ENT_QUOTES, "UTF-8") ?>?action=edit&id=<?= $row["id"] ?>" class="btn-action">Edit</a>
                                    <a href="<?= htmlspecialchars($currentScript, ENT_QUOTES, "UTF-8") ?>?action=delete&id=<?= $row["id"] ?>" class="btn-action btn-action-delete" onclick="return confirm('Delete this record?')">Delete</a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function previewAvatarFile(input) {
    const preview = document.getElementById("avatar-preview");
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) { preview.src = e.target.result; }
        reader.readAsDataURL(input.files[0]);
    }
}
function previewAvatarUrl(url) {
    const preview = document.getElementById("avatar-preview");
    if (url.trim() !== "") { preview.src = url; }
}
</script>

</body>
</html>