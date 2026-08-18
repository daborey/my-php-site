<?php
require 'db.php';

// Auto-detect base path
if (isset($_SERVER['GOOGLE_CLOUD_RUN']) || is_dir('/mnt/storage')) {
    $basePath = '/daboreystep2';
} else {
    $basePath = '/my-php-site/daboreystep2';
}

if (!isset($_SESSION['user_id'])) {
    header("Location: " . $basePath . "/index.php");
    exit;
}

$max_idle_seconds = 900;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $max_idle_seconds)) {
    log_system_event($conn, $_SESSION['username'], 'SESSION_TIMEOUT_EXPIRED');
    session_unset();
    session_destroy();
    header("Location: " . $basePath . "/index.php?expired=1");
    exit;
}
$_SESSION['last_activity'] = time();

$message = "";
$status = "";

function fetchUserTokens($conn, $userId) {
    $result = execute_query($conn, "SELECT id, service_name, secret_seed FROM two_factor_tokens WHERE user_id = ?", [1 => $userId]);
    return fetch_all($result);
}

// DELETE ALL TOKENS
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'delete_all') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security token validation failed.");
    }
    
    try {
        execute_query($conn, "DELETE FROM two_factor_tokens WHERE user_id = ?", [1 => $_SESSION['user_id']]);
        log_system_event($conn, $_SESSION['username'], '2FA_ALL_TOKENS_DELETED');
        header("Location: " . $basePath . "/home.php?deleted_all=1");
        exit;
    } catch (Exception $e) {
        $message = "Failed to delete all tokens.";
        $status = "error";
    }
}

if (isset($_GET['deleted_all']) && $_GET['deleted_all'] == 1) {
    $message = "All 2FA tokens have been deleted.";
    $status = "success";
}

// EXPORT BACKUP
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'export_backup') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security token validation failed.");
    }
    
    $tokens = fetchUserTokens($conn, $_SESSION['user_id']);
    log_system_event($conn, $_SESSION['username'], '2FA_UNIVERSAL_JSON_EXPORTED');
    
    $exportData = [];
    foreach ($tokens as $t) {
        if (!empty($t['secret_seed'])) {
            $exportData[] = [
                'name'   => $t['service_name'],
                'secret' => strtoupper($t['secret_seed'])
            ];
        }
    }
    
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="Vault_Backup_' . date('Ymd_His') . '.json"');
    echo json_encode($exportData, JSON_PRETTY_PRINT);
    exit;
}

// IMPORT BACKUP
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'import_backup') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security token validation failed.");
    }

    if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
        $fileContent = file_get_contents($_FILES['backup_file']['tmp_name']);
        $payload = json_decode($fileContent, true);
        
        if (is_array($payload)) {
            $items = $payload;
            if (!isset($payload[0])) { 
                foreach ($payload as $key => $value) {
                    if (is_array($value)) {
                        $items = $value;
                        break;
                    }
                }
            }

            $success_count = 0;
            
            foreach ($items as $item) {
                if (!is_array($item)) continue;
                $cleanItem = array_change_key_case($item, CASE_LOWER);
                $name = trim($cleanItem['name'] ?? $cleanItem['label'] ?? $cleanItem['issuer'] ?? $cleanItem['originalname'] ?? $cleanItem['issuername'] ?? 'Imported Account');
                
                $seed = '';
                if (isset($cleanItem['secret']) && !empty($cleanItem['secret'])) {
                    $seed = trim($cleanItem['secret']);
                } elseif (isset($cleanItem['seed']) && !empty($cleanItem['seed'])) {
                    $seed = trim($cleanItem['seed']);
                } elseif (isset($cleanItem['key']) && !empty($cleanItem['key'])) {
                    $seed = trim($cleanItem['key']);
                } elseif (isset($cleanItem['secretname']) && !empty($cleanItem['secretname'])) {
                    $seed = trim($cleanItem['secretname']);
                } elseif (isset($cleanItem['totp']['secret']) && !empty($cleanItem['totp']['secret'])) {
                    $seed = trim($cleanItem['totp']['secret']);
                } elseif (isset($cleanItem['uri']) && !empty($cleanItem['uri'])) {
                    parse_str(parse_url($cleanItem['uri'], PHP_URL_QUERY), $queryOpts);
                    $seed = $queryOpts['secret'] ?? '';
                }

                $seed = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $seed));
                
                if (!empty($name) && !empty($seed)) {
                    try {
                        execute_query($conn, "INSERT INTO two_factor_tokens (user_id, service_name, secret_seed) VALUES (?, ?, ?)", 
                            [1 => $_SESSION['user_id'], 2 => $name, 3 => $seed]);
                        $success_count++;
                    } catch (Exception $e) {}
                }
            }
            
            if ($success_count > 0) {
                log_system_event($conn, $_SESSION['username'], '2FA_UNIVERSAL_JSON_IMPORTED_COUNT_' . $success_count);
                $message = "Migration Complete! Successfully loaded " . $success_count . " profiles.";
                $status = "success";
            } else {
                $message = "Could not parse data pairs out of this file structure.";
                $status = "error";
            }
        } else {
            $message = "Invalid JSON structure framework syntax.";
            $status = "error";
        }
    } else {
        $message = "File upload failure. Check properties and retry.";
        $status = "error";
    }
}

// DELETE SINGLE 2FA TOKEN
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'delete_2fa') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security token validation failed.");
    }

    $token_id = intval($_POST['token_id']);
    if ($token_id > 0) {
        try {
            execute_query($conn, "DELETE FROM two_factor_tokens WHERE id = ? AND user_id = ?", 
                [1 => $token_id, 2 => $_SESSION['user_id']]);
            log_system_event($conn, $_SESSION['username'], '2FA_TOKEN_DELETED_ID_' . $token_id);
            header("Location: " . $basePath . "/home.php?deleted=1");
            exit;
        } catch (Exception $e) {
            $message = "Failed to purge database entry.";
            $status = "error";
        }
    }
}

if (isset($_GET['deleted']) && $_GET['deleted'] == 1) {
    $message = "2FA entry purged successfully.";
    $status = "success";
}

// ADD 2FA TOKEN (Manual)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'add_2fa') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security token validation failed.");
    }

    $service_name = trim($_POST['service_name']); 
    $secret_seed = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', trim($_POST['secret_seed']))); 

    if (!empty($service_name) && !empty($secret_seed)) {
        try {
            execute_query($conn, "INSERT INTO two_factor_tokens (user_id, service_name, secret_seed) VALUES (?, ?, ?)", 
                [1 => $_SESSION['user_id'], 2 => $service_name, 3 => $secret_seed]);
            log_system_event($conn, $_SESSION['username'], '2FA_KEY_ADDED_' . strtoupper($service_name));
            
            header("Location: " . $basePath . "/home.php?success=1");
            exit;
        } catch (Exception $e) {
            $message = "Failed to store 2FA metadata.";
            $status = "error";
        }
    }
}

if (isset($_GET['success']) && $_GET['success'] == 1) {
    $message = "2FA Token added successfully.";
    $status = "success";
}

$two_factor_tokens = fetchUserTokens($conn, $_SESSION['user_id']);
if (!is_array($two_factor_tokens)) {
    $two_factor_tokens = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DaboreyPass - 2FA Control Console</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #0f172a; color: #f8fafc; margin: 0; padding: 20px; }
        .container { max-width: 1100px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #334155; padding-bottom: 20px; margin-bottom: 30px; }
        h1 { color: #38bdf8; margin: 0; font-size: 28px; }
        .logout-btn { padding: 8px 16px; background: #ef4444; color: white; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 14px; }
        .logout-btn:hover { background: #dc2626; }
        
        .grid-layout { display: grid; grid-template-columns: 1.2fr 1fr; gap: 30px; }
        .box { background: #1e293b; padding: 25px; border-radius: 8px; border: 1px solid #334155; height: fit-content; margin-bottom: 25px; }
        h3 { margin-top: 0; color: #38bdf8; border-bottom: 1px solid #334155; padding-bottom: 10px; margin-bottom: 15px; }
        
        label { font-size: 13px; color: #94a3b8; display: block; margin-top: 10px; }
        input[type="text"], input[type="file"] { 
            width: 100%; padding: 10px; margin: 6px 0 14px 0; box-sizing: border-box; 
            border: 1px solid #475569; border-radius: 4px; background: #0f172a; color: #fff; 
        }
        input[type="text"]:focus { outline: none; border-color: #38bdf8; }

        .dropzone-area { 
            border: 2px dashed #475569; background: #0f172a; border-radius: 6px; 
            padding: 20px; text-align: center; cursor: pointer; color: #94a3b8; 
            transition: all 0.3s ease;
        }
        .dropzone-area:hover, .dropzone-area.dragover { border-color: #38bdf8; color: #f8fafc; background: rgba(56, 189, 248, 0.05); }
        .dropzone-area input { display: none; }

        .submit-btn { 
            width: 100%; padding: 12px; background: #10b981; border: none; color: white; 
            border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 14px;
        }
        .submit-btn:hover { background: #059669; }

        .danger-btn {
            width: 100%; padding: 12px; background: #ef4444; border: none; color: white;
            border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 14px;
        }
        .danger-btn:hover { background: #dc2626; }

        .error { color: #f87171; background: rgba(248,113,113,0.1); border: 1px solid rgba(248,113,113,0.2); padding: 10px; font-size: 14px; border-radius: 4px; margin-bottom: 15px; }
        .success { color: #34d399; background: rgba(52,211,153,0.1); border: 1px solid rgba(52,211,153,0.2); padding: 10px; font-size: 14px; border-radius: 4px; margin-bottom: 15px; }
        
        .token-row { 
            background: #0f172a; border: 1px solid #334155; border-radius: 6px; 
            padding: 15px; display: flex; justify-content: space-between; align-items: center; 
            margin-bottom: 10px; transition: all 0.15s ease;
        }
        .token-row:hover { border-color: #475569; }
        .token-label { font-size: 12px; color: #94a3b8; text-transform: uppercase; font-weight: bold; }
        .token-code { 
            font-size: 28px; color: #38bdf8; font-family: 'Courier New', monospace; 
            font-weight: bold; letter-spacing: 3px; 
        }
        mark.highlight { background: #eab308; color: #0f172a; padding: 1px 3px; border-radius: 2px; font-weight: bold; }
        
        .action-tray { display: flex; gap: 8px; align-items: center; }
        .copy-btn { 
            background: #334155; color: white; border: none; padding: 6px 12px; 
            border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold; 
        }
        .copy-btn:hover { background: #475569; }
        .del-btn { 
            background: rgba(239, 68, 68, 0.1); color: #f87171; 
            border: 1px solid rgba(239, 68, 68, 0.2); padding: 5px 12px; 
            border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: bold; 
        }
        .del-btn:hover { background: #ef4444; color: white; }

        .progress-wrapper { 
            display: flex; align-items: center; justify-content: space-between; 
            margin-bottom: 20px; background: rgba(56, 189, 248, 0.05); 
            padding: 10px; border-radius: 6px; border: 1px solid rgba(56, 189, 248, 0.1); 
            font-size: 13px;
        }
        .bar-container { background: #334155; height: 6px; width: 120px; border-radius: 3px; overflow: hidden; }
        .bar-fill { background: #38bdf8; height: 100%; width: 100%; transition: width 1s linear; }
        
        .search-container { position: relative; margin-bottom: 20px; }
        .search-input { 
            width: 100%; padding: 12px 14px; box-sizing: border-box; 
            border: 1px solid #0284c7; border-radius: 6px; background: #0f172a; 
            color: #fff; font-size: 14px; margin: 0; 
        }
        .search-input:focus { outline: none; border-color: #38bdf8; box-shadow: 0 0 8px rgba(56, 189, 248, 0.2); }
        
        #status { 
            margin-top: 10px; color: #94a3b8; font-size: 13px; text-align: center; 
            word-break: break-all; min-height: 20px;
        }
        #status code { 
            background: rgba(15, 23, 42, 0.8); padding: 2px 6px; border-radius: 3px; 
            font-size: 11px; display: inline-block; max-width: 100%;
        }
        
        .empty-state { text-align: center; color: #64748b; font-size: 14px; padding: 30px 0; }
        
        .backup-tray { 
            display: flex; flex-direction: column; gap: 15px; 
            border-top: 2px dashed #334155; padding-top: 20px; margin-top: 25px; 
        }
        .btn-backup { 
            background: #4f46e5; border: none; color: white; font-weight: bold; 
            padding: 12px; border-radius: 4px; cursor: pointer; width: 100%; 
            font-size: 14px; text-align: center; display: block; text-decoration: none;
        }
        .btn-backup:hover { background: #4338ca; }
        .import-box-area { 
            background: #0f172a; border: 1px dashed #475569; border-radius: 6px; 
            padding: 15px; text-align: center; cursor: pointer; color: #94a3b8; font-size: 13px; 
        }
        .import-box-area:hover { border-color: #a855f7; color: #fff; }

        .viewport-box { width: 100%; min-height: 200px; background: #0f172a; border-radius: 6px; border: 1px solid #475569; overflow: hidden; margin-bottom: 15px; margin-top: 5px; }
        .cam-segment { background: rgba(15, 23, 42, 0.4); padding: 15px; border-radius: 6px; border: 1px solid #334155; margin-bottom: 20px; }
        .cam-btn { background: #0284c7; color: white; border: none; padding: 8px 14px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 12px; }
        .cam-btn.stop { background: #ef4444; }
        .cam-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        
        #no-results-message { text-align: center; color: #64748b; font-size: 14px; padding: 30px 0; display: none; }
        
        .delete-all-container {
            display: flex; justify-content: flex-end; margin-top: 10px;
        }
        
        @media (max-width: 768px) {
            .grid-layout { grid-template-columns: 1fr; }
            .token-row { flex-direction: column; align-items: stretch; gap: 10px; }
            .action-tray { justify-content: flex-end; }
        }
    </style>
    <script src="https://unpkg.com/html5-qrcode"></script>
    <script src="https://cdn.jsdelivr.net/npm/otpauth@9.3.6/dist/otpauth.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>DaboreyPass 2FA</h1>
                <span style="color:#94a3b8; font-size:14px;">Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong></span>
            </div>
            <a href="<?php echo $basePath; ?>/logout.php" class="logout-btn">Logout</a>
        </div>

        <?php 
        if ($status === "success") echo "<div class='success'>".htmlspecialchars($message)."</div>";
        if ($status === "error") echo "<div class='error'>".htmlspecialchars($message)."</div>";
        ?>

        <div class="grid-layout">
            <div>
                <!-- Option 1: Camera Scan -->
                <div class="box">
                    <h3>Option 1: Scan QR Code (Camera)</h3>
                    <div class="cam-segment">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size:14px; font-weight:bold; color:#f8fafc;">📷 Live Camera</span>
                            <div>
                                <button type="button" class="cam-btn" id="start-cam-btn" onclick="startCamera()">Open Camera</button>
                                <button type="button" class="cam-btn stop" id="stop-cam-btn" onclick="stopCamera()" disabled>Close</button>
                            </div>
                        </div>
                        <div id="viewport" class="viewport-box"></div>
                    </div>
                </div>

                <!-- Option 2: Upload QR Screenshot -->
                <div class="box">
                    <h3>Option 2: Upload QR Screenshot</h3>
                    <div class="dropzone-area" id="drop-zone" onclick="document.getElementById('qr-file-input').click()">
                        <div style="font-size: 48px; margin-bottom: 5px;">🖼️</div>
                        <span>Click or drop your 2FA QR code image here</span>
                        <input type="file" id="qr-file-input" accept="image/*">
                    </div>
                    <div id="status">Waiting for image...</div>
                </div>

                <!-- Option 3: Manual Entry -->
                <div class="box">
                    <h3>Option 3: Enter Setup Key Manually</h3>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="add_2fa">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <label>Account Name</label>
                        <input type="text" name="service_name" placeholder="e.g. Google:me@gmail.com" required>
                        
                        <label>Secret Key (Base32)</label>
                        <input type="text" name="secret_seed" placeholder="e.g. JBSWY3DPEHPK3PXP" required>
                        
                        <button type="submit" class="submit-btn">Save Key</button>
                    </form>
                </div>

                <form method="POST" action="" id="qr-submit-form">
                    <input type="hidden" name="action" value="add_2fa">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" id="final-name" name="service_name">
                    <input type="hidden" id="final-seed" name="secret_seed">
                </form>

                <form method="POST" action="" id="delete-token-form">
                    <input type="hidden" name="action" value="delete_2fa">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" id="delete-target-id" name="token_id">
                </form>

                <form method="POST" action="" id="delete-all-form">
                    <input type="hidden" name="action" value="delete_all">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                </form>
            </div>

            <div>
                <div class="box">
                    <h3>Your Authenticator Codes</h3>
                    
                    <div class="search-container">
                        <input type="text" id="live-search-bar" class="search-input" placeholder="Type to search & filter tokens instantly..." autocomplete="off">
                    </div>

                    <div class="progress-wrapper">
                        <span id="timer-display">Awaiting clock sync...</span>
                        <div class="bar-container"><div class="bar-fill" id="timer-bar"></div></div>
                    </div>

                    <div id="token-container">
                        <p id="no-results-message">No matching active codes found.</p>
                        
                        <?php if (!empty($two_factor_tokens)): ?>
                            <?php foreach ($two_factor_tokens as $token): ?>
                                <div class="token-row" data-searchable-name="<?php echo htmlspecialchars(strtolower($token['service_name'])); ?>">
                                    <div>
                                        <div class="token-label" data-raw-text="<?php echo htmlspecialchars($token['service_name']); ?>"><?php echo htmlspecialchars($token['service_name']); ?></div>
                                        <div class="token-code" id="code-<?php echo $token['id']; ?>" data-seed="<?php echo htmlspecialchars($token['secret_seed']); ?>">000 000</div>
                                    </div>
                                    <div class="action-tray">
                                        <button class="copy-btn" onclick="copyTokenValue('code-<?php echo $token['id']; ?>', this)">Copy</button>
                                        <button class="del-btn" onclick="triggerTokenDeletion(<?php echo $token['id']; ?>, '<?php echo htmlspecialchars(addslashes($token['service_name'])); ?>')">Delete</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <div class="delete-all-container">
                                <button class="del-btn" onclick="triggerDeleteAll()" style="padding: 8px 16px; font-size: 13px;">Delete All Tokens</button>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">No profiles found inside your vault.</div>
                        <?php endif; ?>
                    </div>

                    <!-- Backup Tray -->
                    <div class="backup-tray">
                        <h4 style="margin: 0; color: #a855f7; border-bottom: 1px solid #334155; padding-bottom: 6px;">🔄 Cross-Platform Data Migration</h4>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="export_backup">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <button type="submit" class="btn-backup">Export Backup</button>
                        </form>

                        <form method="POST" action="" enctype="multipart/form-data" id="import-form">
                            <input type="hidden" name="action" value="import_backup">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <div class="import-box-area" onclick="document.getElementById('import-file-input').click()">
                                <span>Import Backup</span>
                                <input type="file" id="import-file-input" name="backup_file" accept=".json" style="display:none;" onchange="document.getElementById('import-form').submit();">
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // ============================================
    // DELETE ALL TOKENS
    // ============================================
    function triggerDeleteAll() {
        const count = document.querySelectorAll('.token-row').length;
        if (count === 0) {
            status.textContent = "No tokens to delete.";
            return;
        }
        if (confirm("⚠️ Permanently delete ALL " + count + " 2FA tokens? This cannot be undone!")) {
            document.getElementById('delete-all-form').submit();
        }
    }

    // ============================================
    // CAMERA SCANNER - TWO CONDITIONS (XAMPP vs Cloud Run)
    // ============================================
    let camInstance = null;
    const status = document.getElementById('status');

    function startCamera() {
        const isLocal = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
        const isHttps = window.location.protocol === 'https:';
        
        // Condition 1: Cloud Run (HTTPS) - works
        if (!isLocal && isHttps) {
            startCameraEngine();
            return;
        }
        
        // Condition 2: Localhost - try camera
        if (isLocal) {
            if (isHttps) {
                startCameraEngine();
            } else {
                if (confirm("Camera on HTTP may be blocked. Try anyway?")) {
                    startCameraEngine();
                } else {
                    document.getElementById('start-cam-btn').disabled = false;
                    document.getElementById('stop-cam-btn').disabled = true;
                    status.textContent = "Use Option 2 (Upload) instead.";
                }
            }
            return;
        }
        
        alert("Camera requires HTTPS. Please use Option 2 (Upload).");
    }

    function startCameraEngine() {
        document.getElementById('start-cam-btn').disabled = true;
        document.getElementById('stop-cam-btn').disabled = false;
        status.textContent = "Starting camera...";
        
        try {
            camInstance = new Html5Qrcode("viewport");
            camInstance.start(
                { facingMode: "environment" },
                { fps: 15, qrbox: 180 },
                (decodedText) => { handleDecodedText(decodedText, 'camera'); },
                () => {}
            ).then(() => {
                status.textContent = "Camera ready - scanning...";
            }).catch((err) => {
                status.textContent = "Camera access denied. Use Option 2.";
                stopCamera();
            });
        } catch (err) {
            status.textContent = "Camera not available. Use Option 2.";
            stopCamera();
        }
    }

    function stopCamera() {
        document.getElementById('start-cam-btn').disabled = false;
        document.getElementById('stop-cam-btn').disabled = true;
        if (camInstance) {
            camInstance.stop().then(() => {
                document.getElementById('viewport').innerHTML = "";
                camInstance = null;
                status.textContent = "Camera stopped.";
            });
        }
    }

    // ============================================
    // QR UPLOAD (jsQR) - WORKS EVERYWHERE
    // ============================================
    const fileInput = document.getElementById('qr-file-input');
    const dropZone = document.getElementById('drop-zone');

    document.getElementById('qr-file-input').addEventListener('change', function(e) {
        if (e.target.files.length) {
            processFile(e.target.files[0]);
        }
    });

    document.getElementById('drop-zone').addEventListener('dragover', function(e) {
        e.preventDefault();
        this.classList.add('dragover');
    });

    document.getElementById('drop-zone').addEventListener('dragleave', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
    });

    document.getElementById('drop-zone').addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
        if (e.dataTransfer.files.length) {
            processFile(e.dataTransfer.files[0]);
        }
    });

    function processFile(file) {
        status.textContent = "Scanning...";
        const reader = new FileReader();
        reader.onload = function(e) {
            const img = new Image();
            img.onload = function() {
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                canvas.width = img.naturalWidth || img.width;
                canvas.height = img.naturalHeight || img.height;
                ctx.drawImage(img, 0, 0);
                const data = ctx.getImageData(0, 0, canvas.width, canvas.height);
                const code = jsQR(data.data, data.width, data.height);
                
                if (code && code.data) {
                    handleDecodedText(code.data, 'upload');
                } else {
                    status.textContent = "No QR code found. Make sure the image is clear.";
                }
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    }

    // ============================================
    // MIGRATION QR PARSER
    // ============================================
    const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    function bytesToBase32(buffer) {
        let value = 0, bits = 0, output = '';
        for (let i = 0; i < buffer.length; i++) {
            value = (value << 8) | buffer[i];
            bits += 8;
            while (bits >= 5) {
                output += BASE32_CHARS[(value >>> (bits - 5)) & 31];
                bits -= 5;
            }
        }
        if (bits > 0) {
            output += BASE32_CHARS[(value << (5 - bits)) & 31];
        }
        return output;
    }

    function parseGoogleMigration(text) {
        try {
            let url = new URL(text);
            if (url.protocol !== 'otpauth-migration:') return null;
            let dataParam = url.searchParams.get('data');
            if (!dataParam) return null;

            let binaryString = atob(dataParam.replace(/-/g, '+').replace(/_/g, '/'));
            let bytes = new Uint8Array(binaryString.length);
            for (let i = 0; i < binaryString.length; i++) {
                bytes[i] = binaryString.charCodeAt(i);
            }

            let accounts = [], ptr = 0;
            while (ptr < bytes.length) {
                let tag = bytes[ptr++];
                let wireType = tag & 7;
                let fieldNumber = tag >> 3;

                if (wireType === 2) {
                    let length = bytes[ptr++];
                    let fieldData = bytes.subarray(ptr, ptr + length);
                    ptr += length;

                    if (fieldNumber === 1) {
                        let secret = '', name = '', issuer = '';
                        let innerPtr = 0;
                        while (innerPtr < fieldData.length) {
                            let innerTag = fieldData[innerPtr++];
                            let innerWire = innerTag & 7;
                            let innerField = innerTag >> 3;

                            if (innerWire === 2) {
                                let innerLen = fieldData[innerPtr++];
                                let valBytes = fieldData.subarray(innerPtr, innerPtr + innerLen);
                                innerPtr += innerLen;

                                if (innerField === 1) {
                                    secret = bytesToBase32(valBytes);
                                } else if (innerField === 2) {
                                    name = new TextDecoder().decode(valBytes);
                                } else if (innerField === 3) {
                                    issuer = new TextDecoder().decode(valBytes);
                                }
                            } else if (innerWire === 0) {
                                innerPtr++;
                            }
                        }
                        if (secret) {
                            accounts.push({
                                secret: secret,
                                label: issuer ? (name ? issuer + ':' + name : issuer) : (name || 'Imported Account')
                            });
                        }
                    }
                } else if (wireType === 0) {
                    ptr++;
                }
            }
            return accounts.length > 0 ? accounts : null;
        } catch (e) {
            console.error("Migration parser error:", e);
            return null;
        }
    }

    // ============================================
    // SHARED DECODER
    // ============================================
    function handleDecodedText(text, source) {
        status.innerHTML = "Decoded: <code>" + text.substring(0, 60) + "...</code>";

        if (text.toLowerCase().startsWith('otpauth-migration://')) {
            status.textContent = "Parsing migration data...";
            let accounts = parseGoogleMigration(text);

            if (accounts && accounts.length > 0) {
                status.innerHTML = "✅ Found " + accounts.length + " account(s). Saving first account...";
                document.getElementById('final-name').value = accounts[0].label;
                document.getElementById('final-seed').value = accounts[0].secret;
                setTimeout(() => {
                    document.getElementById('qr-submit-form').submit();
                }, 1200);
            } else {
                status.textContent = "Could not parse migration QR.";
            }
            return;
        }

        let match = text.match(/secret=([A-Z2-7]{16,32})/i);
        if (match) {
            let label = "Imported Token";
            let labelMatch = text.match(/(?:label|issuer)=([^&]+)/i);
            if (labelMatch) {
                label = decodeURIComponent(labelMatch[1]);
            }
            document.getElementById('final-name').value = label;
            document.getElementById('final-seed').value = match[1].toUpperCase();
            document.getElementById('qr-submit-form').submit();
        } else {
            let rawMatch = text.match(/([A-Z2-7]{16,32})/);
            if (rawMatch) {
                document.getElementById('final-name').value = 'Imported';
                document.getElementById('final-seed').value = rawMatch[1].toUpperCase();
                document.getElementById('qr-submit-form').submit();
            } else {
                status.textContent = "No valid secret found.";
            }
        }
    }

    // ============================================
    // SEARCH FILTER
    // ============================================
    const searchBar = document.getElementById('live-search-bar');

    if (searchBar) {
        searchBar.addEventListener('input', function() {
            const query = this.value.trim().toLowerCase();
            const rows = document.querySelectorAll('.token-row');
            const noResultsMsg = document.getElementById('no-results-message');
            let visibleCount = 0;

            rows.forEach(row => {
                const labelNode = row.querySelector('.token-label');
                const originalText = labelNode.getAttribute('data-raw-text');

                if (!query) {
                    row.style.display = 'flex';
                    labelNode.textContent = originalText;
                    visibleCount++;
                } else {
                    if (originalText.toLowerCase().includes(query)) {
                        row.style.display = 'flex';
                        visibleCount++;
                        const regex = new RegExp(`(${query.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&')})`, 'gi');
                        labelNode.innerHTML = originalText.replace(regex, '<mark class="highlight">$1</mark>');
                    } else {
                        row.style.display = 'none';
                    }
                }
            });

            if (noResultsMsg) {
                noResultsMsg.style.display = (rows.length > 0 && visibleCount === 0) ? 'block' : 'none';
            }
        });
    }

    // ============================================
    // DELETE SINGLE TOKEN
    // ============================================
    function triggerTokenDeletion(id, serviceName) {
        if (confirm("Permanently delete '" + serviceName + "'?")) {
            document.getElementById('delete-target-id').value = id;
            document.getElementById('delete-token-form').submit();
        }
    }

    // ============================================
    // TOTP CODE GENERATION & CLOCK
    // ============================================
    function updateTokensAndClock() {
        const epoch = Math.floor(Date.now() / 1000);
        const remainder = epoch % 30;
        const timeLeft = 30 - remainder;

        document.getElementById('timer-display').innerText = "Codes change in: " + timeLeft + "s";
        document.getElementById('timer-bar').style.width = (timeLeft / 30) * 100 + "%";

        document.querySelectorAll('[id^="code-"]').forEach(el => {
            const seed = el.getAttribute('data-seed');
            try {
                if (typeof OTPAuth !== 'undefined') {
                    const totp = new OTPAuth.TOTP({ secret: seed });
                    const token = totp.generate();
                    el.innerText = token.substr(0, 3) + ' ' + token.substr(3);
                }
            } catch(e) {}
        });
    }

    function loadOTPAuth() {
        if (typeof OTPAuth === 'undefined') {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/otpauth@9.3.6/dist/otpauth.umd.min.js';
            script.onload = () => {
                updateTokensAndClock();
                setInterval(updateTokensAndClock, 1000);
            };
            document.head.appendChild(script);
        } else {
            updateTokensAndClock();
            setInterval(updateTokensAndClock, 1000);
        }
    }

    loadOTPAuth();

    // ============================================
    // COPY TOKEN
    // ============================================
    function copyTokenValue(id, btn) {
        const code = document.getElementById(id).innerText.replace(/\s/g, '');
        navigator.clipboard.writeText(code).then(() => {
            const old = btn.innerText;
            btn.innerText = "Copied!";
            setTimeout(() => { btn.innerText = old; }, 1200);
        });
    }
    </script>
</body>
</html>