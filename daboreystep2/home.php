<?php
require 'db.php';

$basePath = $GLOBALS['basePath'];

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>DaboreyPass - 2FA Control Console</title>
    
    <!-- External CSS -->
    <link rel="stylesheet" href="<?php echo $basePath; ?>/style.css">
    
    <style>
        .device-hint { font-size: 12px; color: #64748b; margin-top: 5px; }
        .device-hint .icon { font-size: 16px; }
        #start-cam-btn { transition: all 0.3s ease; }
        #start-cam-btn .btn-label { display: inline; }
        #start-cam-btn .btn-icon { display: inline; }
        
        @media (max-width: 480px) {
            #start-cam-btn .btn-label { display: none; }
            #start-cam-btn .btn-icon { font-size: 18px; }
        }
    </style>
    
    <!-- External Libraries -->
    <script src="https://unpkg.com/html5-qrcode"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
    
    <!-- External JavaScript -->
    <script>
        // Pass PHP environment to JavaScript
        const ENVIRONMENT = '<?php echo ENVIRONMENT; ?>';
        const BASE_PATH = '<?php echo $basePath; ?>';
    </script>
    <script src="<?php echo $basePath; ?>/script.js"></script>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>DaboreyPass 2FA</h1>
                <span class="header-user">Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong></span>
                <span style="font-size:11px; color:#64748b; margin-left:10px;">
                    [<?php echo ENVIRONMENT === 'cloud' ? '☁️ Cloud' : '💻 Local'; ?>]
                </span>
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
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                            <span style="font-size:14px; font-weight:bold; color:#f8fafc;">
                                <span id="camera-icon">📷</span> 
                                <span id="camera-label">Live Camera</span>
                            </span>
                            <div>
                                <button type="button" class="cam-btn" id="start-cam-btn" onclick="startCamera()">
                                    <span class="btn-icon">📷</span>
                                    <span class="btn-label">Open</span>
                                </button>
                                <button type="button" class="cam-btn stop" id="stop-cam-btn" onclick="stopCamera()" disabled>
                                    <span class="btn-icon">✕</span>
                                    <span class="btn-label">Close</span>
                                </button>
                            </div>
                        </div>
                        <div id="device-hint" class="device-hint">
                            <span class="icon">📱</span> 
                            <span id="device-message">Detecting device...</span>
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
                                <button class="del-btn" onclick="triggerDeleteAll()" style="padding: 8px 16px; font-size: 13px;">🗑️ Delete All Tokens</button>
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
</body>
</html>