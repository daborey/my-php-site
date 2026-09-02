<?php
// ============================================
// FILE: daboreystep2/dashboard.php
// ============================================

require_once __DIR__ . '/config.php';

// Functions & Security Helpers
function sanitize($data)
{
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function generate_csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function log_sqlite_event($db, $username, $event_type)
{
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

// Ensure dedicated table for saved TOTP Accounts exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS authenticator_accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        account_label TEXT NOT NULL,
        secret_key TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
} catch (PDOException $e) {
    // Table handles auto-creation
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

// 3. Handle Saving Decoded 2FA Secret
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        log_sqlite_event($db, $_SESSION['username'] ?? 'UNKNOWN', 'CSRF_VALIDATION_FAILURE');
        die("Security token validation failed.");
    }

    if ($_POST['action'] === 'add_account') {
        $label = trim($_POST['account_label'] ?? 'Uploaded Account');
        $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $_POST['secret_key'] ?? ''));

        if (!empty($secret)) {
            $stmt = $db->prepare("INSERT INTO authenticator_accounts (user_id, account_label, secret_key) VALUES (?, ?, ?)");
            if ($stmt->execute([$user_id, $label, $secret])) {
                log_sqlite_event($db, $_SESSION['username'] ?? 'UNKNOWN', '2FA_ACCOUNT_ADDED');
                header("Location: /daboreystep2/dashboard.php");
                exit;
            } else {
                $status_msg = "Failed to save 2FA account.";
            }
        } else {
            $status_msg = "Invalid 2FA Secret extracted from image.";
        }
    } elseif ($_POST['action'] === 'delete_account') {
        $account_id = intval($_POST['account_id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM authenticator_accounts WHERE id = ? AND user_id = ?");
        $stmt->execute([$account_id, $user_id]);
        header("Location: /daboreystep2/dashboard.php");
        exit;
    }
}

// 4. Fetch User's 2FA Accounts
$accounts = [];
try {
    $stmt = $db->prepare("SELECT id, account_label, secret_key, created_at FROM authenticator_accounts WHERE user_id = ? ORDER BY id DESC");
    $stmt->execute([$user_id]);
    $accounts = $stmt->fetchAll();
} catch (PDOException $e) {
    $accounts = [];
}
?>
<!DOCTYPE html>
<html lang="km">

//
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>2FA Authenticator Vault - Daborey Step 2</title>
    <!-- jsQR Library for scanning QR image directly in the browser -->
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
    <!-- OTPAuth Library for client-side live 6-digit TOTP code calculation -->
    <script src="https://cdn.jsdelivr.net/npm/otpauth@9.1.4/dist/otpauth.umd.min.js"></script>
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

        .header-title-zone h1 {
            font-size: 24px;
            color: #38bdf8;
            margin: 0 0 5px 0;
        }

        .user-info {
            font-size: 14px;
            color: #94a3b8;
        }

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

        .btn-profile:hover {
            background: #475569;
        }

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

        .btn-logout:hover {
            background: #dc2626;
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
            display: block;
        }

        .cell-value {
            font-size: 20px;
            font-weight: bold;
            color: #ffb700;
        }

        .date-cell {
            grid-column: span 4;
            font-size: 12px;
            color: #bdc5e1;
            display: flex;
            justify-content: space-around;
        }

        .day-highlight {
            color: #ffb700;
            font-weight: bold;
        }

        .upload-card {
            background: #1e293b;
            padding: 25px;
            border-radius: 8px;
            border: 1px solid #334155;
            max-width: 600px;
            margin: 0 auto 30px auto;
            text-align: center;
        }

        .upload-zone {
            border: 2px dashed #38bdf8;
            padding: 20px;
            border-radius: 6px;
            cursor: pointer;
            margin-top: 15px;
            background: #0f172a;
        }

        .upload-zone:hover {
            background: #1b283f;
        }

        .file-input {
            display: none;
        }

        .accounts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .account-card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 20px;
            position: relative;
        }

        .account-title {
            font-size: 16px;
            font-weight: bold;
            color: #38bdf8;
            margin-bottom: 10px;
        }

        .totp-code {
            font-size: 32px;
            font-weight: bold;
            font-family: monospace;
            color: #4ade80;
            letter-spacing: 4px;
            margin: 10px 0;
        }

        .timer-bar {
            height: 4px;
            background: #0284c7;
            width: 100%;
            border-radius: 2px;
            transition: width 1s linear;
        }

        .btn-delete {
            background: #ef4444;
            color: white;
            border: none;
            padding: 4px 8px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            float: right;
        }
        
        .status-error {
            text-align: center;
            color: #ef4444;
            margin-bottom: 15px;
        }

        .dual-column-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 25px;
            max-width: 1200px;
            margin: 0 auto 30px auto;
        }

        .feature-card {
            background: #1e293b;
            padding: 25px;
            border-radius: 8px;
            border: 1px solid #334155;
            text-align: center;
        }

        .tab-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .tab-btn {
            flex: 1;
            padding: 10px;
            background: #0f172a;
            border: 1px solid #334155;
            color: #94a3b8;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            font-size: 13px;
        }

        .tab-btn.active {
            background: #0284c7;
            color: #ffffff;
            border-color: #38bdf8;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        #scanner-viewfinder {
            width: 100%;
            max-width: 320px;
            height: 240px;
            background: #0f172a;
            border: 2px dashed #38bdf8;
            border-radius: 8px;
            margin: 15px auto;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #camera-feed {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: none;
        }

        .scanner-overlay {
            position: absolute;
            width: 160px;
            height: 160px;
            border: 2px solid #4ade80;
            box-shadow: 0 0 0 4000px rgba(0, 0, 0, 0.4);
            border-radius: 8px;
            display: none;
            pointer-events: none;
        }

        .form-input {
            width: 100%;
            padding: 10px;
            background: #0f172a;
            border: 1px solid #334155;
            color: white;
            border-radius: 4px;
            margin-bottom: 12px;
            box-sizing: border-box;
            font-size: 14px;
        }

        .btn-action {
            width: 100%;
            padding: 10px;
            background: #0284c7;
            color: white;
            border: none;
            border-radius: 4px;
            font-weight: bold;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-action:hover {
            background: #0369a1;
        }

        .btn-stop {
            background: #ef4444;
            margin-top: 10px;
        }

        .btn-stop:hover {
            background: #dc2626;
        }

        /* Mobile & Tablet Responsiveness */
        @media (max-width: 768px) {
            body {
                padding: 12px;
            }

            header {
                flex-direction: column;
                align-items: stretch;
                padding: 16px;
                gap: 16px;
            }

            .header-title-zone {
                display: flex;
                flex-direction: column;
                align-items: center;
                text-align: center;
            }

            .user-info {
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                align-items: center;
                gap: 8px;
                margin-top: 10px;
            }

            .btn-profile, .btn-logout {
                margin-left: 0 !important;
                padding: 10px 14px;
                font-size: 13px;
                display: inline-block;
            }

            .clock-container {
                grid-template-columns: repeat(4, 1fr);
                gap: 4px;
                padding: 8px;
            }

            .cell-value {
                font-size: 16px;
            }

            .cell-label {
                font-size: 9px;
            }

            .date-cell {
                font-size: 11px;
            }

            .dual-column-container {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .feature-card {
                padding: 16px;
            }

            #scanner-viewfinder {
                max-width: 100%;
                height: 200px;
            }

            .accounts-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .totp-code {
                font-size: 28px;
            }

            .btn-action, .btn-delete {
                min-height: 44px;
                line-height: 24px;
            }
        }
    </style>
</head>

<body>

    <header>
        <div class="header-title-zone">
            <h1>Daborey Step 2 (Authenticator)</h1>
            <div class="user-info">
                Authenticated Entity: <strong><?php echo sanitize($_SESSION['username'] ?? 'User'); ?></strong>
                <a href="/daboreystep2/profile.php" class="btn-profile">Profile</a>
                <a href="/daboreystep2/logout.php" class="btn-logout">Sign Out</a>
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
                <div id="ampm" class="cell-value">AM</div>
            </div>
            <div class="date-cell">
                <span id="khmer-day" class="day-highlight">---</span>
                <span id="khmer-date">00 --- 0000</span>
            </div>
        </div>
    </header>

    <?php if (!empty($status_msg)): ?>
        <div class="status-error"><?php echo sanitize($status_msg); ?></div>
    <?php endif; ?>
//

    <!-- Dual-Column Feature Area -->
    <div class="dual-column-container">
        <!-- Left Column: Upload Backup 2FA Image -->
        <div class="feature-card">
            <h2 style="margin-top:0; color:#38bdf8; font-size:18px;">Upload Backup 2FA Image</h2>
            <p style="color:#94a3b8; font-size:13px;">Select or drop a saved QR code screenshot to parse its secret key.</p>

            <div class="upload-zone" onclick="document.getElementById('qr-file').click();">
                <span id="upload-label">Click or Drag & Drop QR Image Here</span>
                <input type="file" id="qr-file" class="file-input" accept="image/*" onchange="processQRImage(this)">
            </div>

            <form id="save-account-form" method="POST" action="/daboreystep2/dashboard.php" style="display:none; margin-top:20px;">
                <input type="hidden" name="action" value="add_account">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="secret_key" id="extracted-secret">
                <input type="text" name="account_label" id="extracted-label" placeholder="Account Name (e.g. Google, GitHub)" required class="form-input">
                <button type="submit" class="btn-action">Save 2FA Account</button>
            </form>
        </div>

        <!-- REFACTORED RIGHT COLUMN: Google Authenticator Workflow -->
        <div class="feature-card">
            <h2 style="margin-top:0; color:#38bdf8; font-size:18px;">Add 2-Step Verification</h2>
            <p style="color:#94a3b8; font-size:13px; margin-bottom:15px;">Add account using Google Authenticator steps.</p>

            <div class="tab-buttons">
                <button type="button" class="tab-btn active" onclick="switchTab('camera')">📷 Scan QR Code</button>
                <button type="button" class="tab-btn" onclick="switchTab('manual')">⌨️ Enter Setup Key</button>
            </div>

            <!-- Tab 1: Live Camera Scanner -->
            <div id="tab-camera" class="tab-content active">
                <div id="scanner-viewfinder">
                    <span id="camera-placeholder" style="color:#64748b; font-size:13px;">Camera inactive</span>
                    <video id="camera-feed" playsinline></video>
                    <div id="scanner-overlay" class="scanner-overlay"></div>
                </div>
                <canvas id="qr-canvas" style="display:none;"></canvas>

                <button id="btn-start-camera" type="button" class="btn-action" onclick="startCameraScanner()">Start Rear Camera</button>
                <button id="btn-stop-camera" type="button" class="btn-action btn-stop" style="display:none;" onclick="stopCameraScanner()">Cancel / Turn Off Camera</button>

                <form id="camera-save-form" method="POST" action="/daboreystep2/dashboard.php" style="display:none; margin-top:15px;">
                    <input type="hidden" name="action" value="add_account">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="secret_key" id="camera-extracted-secret">
                    <input type="text" name="account_label" id="camera-extracted-label" placeholder="Account Name" required class="form-input">
                    <button type="submit" class="btn-action">Save Scanned Account</button>
                </form>
            </div>

            <!-- Tab 2: Manual Setup Key Form -->
            <div id="tab-manual" class="tab-content">
                <form method="POST" action="/daboreystep2/dashboard.php" style="margin-top:10px;">
                    <input type="hidden" name="action" value="add_account">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

                    <input type="text" name="account_label" placeholder="Account name (e.g. GitHub)" required class="form-input">
                    <input type="text" name="secret_key" placeholder="Your key (Base32 secret)" required class="form-input" oninput="sanitizeManualKey(this)">

                    <select class="form-input" disabled style="opacity:0.7;">
                        <option selected>Type of key: Time-based (TOTP)</option>
                    </select>

                    <button type="submit" class="btn-action">Add Account</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Live 2FA Tokens Grid -->
    <div class="accounts-grid">
        <?php if (!empty($accounts)): ?>
            <?php foreach ($accounts as $acc): ?>
                <div class="account-card" data-secret="<?php echo sanitize($acc['secret_key']); ?>">
                    <form method="POST" action="/daboreystep2/dashboard.php" style="display:inline;">
                        <input type="hidden" name="action" value="delete_account">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="account_id" value="<?php echo $acc['id']; ?>">
                        <button type="submit" class="btn-delete" onclick="return confirm('Remove this token?')">Delete</button>
                    </form>
                    <div class="account-title"><?php echo sanitize($acc['account_label']); ?></div>
                    <div class="totp-code" id="code-<?php echo $acc['id']; ?>">------</div>
                    <div class="timer-bar" id="bar-<?php echo $acc['id']; ?>"></div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="grid-column: 1 / -1; text-align:center; color:#64748b; margin-top:30px;">
                No 2FA tokens added yet. Upload a QR code image above to start generating 6-digit codes.
            </div>
        <?php endif; ?>
    </div>

    <!-- Include Protobuf Library -->
    <script src="https://cdn.jsdelivr.net/npm/protobufjs@7.2.6/dist/protobuf.min.js"></script>

    <script>
        // Tab Switching Logic
        function switchTab(tab) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

            if (tab === 'camera') {
                document.querySelectorAll('.tab-btn')[0].classList.add('active');
                document.getElementById('tab-camera').classList.add('active');
            } else {
                stopCameraScanner();
                document.querySelectorAll('.tab-btn')[1].classList.add('active');
                document.getElementById('tab-manual').classList.add('active');
            }
        }

        // Real-Time Sanitizer for Manual Input Key
        function sanitizeManualKey(input) {
            input.value = input.value.replace(/[^A-Za-z2-7]/g, '').toUpperCase();
        }

        // Camera Hardware Variables
        let mediaStream = null;
        let animationFrameId = null;

        // Start Live Rear Camera Scanner
        async function startCameraScanner() {
            const video = document.getElementById('camera-feed');
            const placeholder = document.getElementById('camera-placeholder');
            const overlay = document.getElementById('scanner-overlay');
            const btnStart = document.getElementById('btn-start-camera');
            const btnStop = document.getElementById('btn-stop-camera');

            try {
                // Strictly target environment/rear camera
                mediaStream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: {
                            ideal: "environment"
                        }
                    }
                });

                video.srcObject = mediaStream;
                video.setAttribute("playsinline", true);
                await video.play();

                placeholder.style.display = "none";
                video.style.display = "block";
                overlay.style.display = "block";
                btnStart.style.display = "none";
                btnStop.style.display = "block";

                scanCameraFrame();
            } catch (err) {
                alert("Camera Access Error: " + (err.message || "Unable to access rear camera. Ensure HTTPS is enabled and permissions are granted."));
                stopCameraScanner();
            }
        }

        // Continuous Frame Scanning via jsQR
        function scanCameraFrame() {
            const video = document.getElementById('camera-feed');
            const canvas = document.getElementById('qr-canvas');
            const ctx = canvas.getContext('2d');

            if (video.readyState === video.HAVE_ENOUGH_DATA) {
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

                const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                const code = jsQR(imageData.data, imageData.width, imageData.height);

                if (code && code.data) {
                    // Key detected - process secret & release camera hardware
                    processCameraQRData(code.data.trim());
                    stopCameraScanner();
                    return;
                }
            }
            animationFrameId = requestAnimationFrame(scanCameraFrame);
        }

        // Hardware Release: Turn off Camera Stream
        function stopCameraScanner() {
            if (animationFrameId) {
                cancelAnimationFrame(animationFrameId);
                animationFrameId = null;
            }
            if (mediaStream) {
                mediaStream.getTracks().forEach(track => track.stop());
                mediaStream = null;
            }

            const video = document.getElementById('camera-feed');
            const placeholder = document.getElementById('camera-placeholder');
            const overlay = document.getElementById('scanner-overlay');
            const btnStart = document.getElementById('btn-start-camera');
            const btnStop = document.getElementById('btn-stop-camera');

            if (video) video.style.display = "none";
            if (placeholder) placeholder.style.display = "block";
            if (overlay) overlay.style.display = "none";
            if (btnStart) btnStart.style.display = "block";
            if (btnStop) btnStop.style.display = "none";
        }

        // Process Camera Scan Result
        function processCameraQRData(qrData) {
            let secret = "";
            let label = "Scanned Account";

            if (qrData.startsWith("otpauth://")) {
                try {
                    const url = new URL(qrData);
                    secret = url.searchParams.get("secret");
                    const pathParts = url.pathname.split('/');
                    if (pathParts.length > 1) {
                        label = decodeURIComponent(pathParts[pathParts.length - 1]);
                    }
                    if (url.searchParams.get("issuer")) {
                        label = decodeURIComponent(url.searchParams.get("issuer")) + " (" + label + ")";
                    }
                } catch (e) {}
            } else if (/^[A-Za-z2-7=\s]{16,64}$/.test(qrData)) {
                secret = qrData.replace(/\s+/g, '');
            }

            secret = (secret || "").replace(/[^A-Za-z2-7]/g, '').toUpperCase();

            if (secret.length >= 8) {
                document.getElementById('camera-extracted-secret').value = secret;
                document.getElementById('camera-extracted-label').value = label;
                document.getElementById('camera-save-form').style.display = "block";
            } else {
                alert("QR scanned, but no valid Base32 TOTP key was detected.");
            }
        }

        // 1. Process and Parse Uploaded QR Code Image
        function processQRImage(input) {
            if (!input.files || !input.files[0]) return;
            const file = input.files[0];
            document.getElementById('upload-label').innerText = "Processing " + file.name + "...";

            const reader = new FileReader();
            reader.onload = function(e) {
                const img = new Image();
                img.onload = function() {
                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');
                    canvas.width = img.width;
                    canvas.height = img.height;
                    ctx.drawImage(img, 0, 0, img.width, img.height);

                    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                    const code = jsQR(imageData.data, imageData.width, imageData.height);

                    if (code && code.data) {
                        parseQRContent(code.data.trim());
                    } else {
                        alert("Could not detect a valid QR code in the uploaded image.");
                        document.getElementById('upload-label').innerText = "Click or Drag & Drop QR Image Here";
                    }
                };
                img.src = e.target.result;
            };
            reader.readAsDataURL(file);
        }

        // Helper: Convert Uint8Array to Base32 String
        function bytesToBase32(bytes) {
            const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
            let bits = 0;
            let value = 0;
            let output = "";
            for (let i = 0; i < bytes.length; i++) {
                value = (value << 8) | bytes[i];
                bits += 8;
                while (bits >= 5) {
                    output += alphabet[(value >>> (bits - 5)) & 31];
                    bits -= 5;
                }
            }
            if (bits > 0) {
                output += alphabet[(value << (5 - bits)) & 31];
            }
            return output;
        }

        // 2. Flexible QR Data Parser (Handles URI, Migration Exports, Raw Base32 Secrets)
        function parseQRContent(qrData) {
            let secret = "";
            let label = "Uploaded 2FA Account";

            // Case A: Google Authenticator Migration Link (otpauth-migration://)
            if (qrData.startsWith("otpauth-migration://")) {
                try {
                    const url = new URL(qrData);
                    const dataParam = url.searchParams.get("data");
                    if (dataParam) {
                        const binaryStr = atob(dataParam);
                        const bytes = new Uint8Array(binaryStr.length);
                        for (let i = 0; i < binaryStr.length; i++) {
                            bytes[i] = binaryStr.charCodeAt(i);
                        }

                        // ProtoBuf schema structure for Google Authenticator Export
                        const root = protobuf.Root.fromJSON({
                            nested: {
                                MigrationPayload: {
                                    fields: {
                                        otpParameters: {
                                            rule: "repeated",
                                            type: "OtpParameters",
                                            id: 1
                                        }
                                    }
                                },
                                OtpParameters: {
                                    fields: {
                                        secret: {
                                            type: "bytes",
                                            id: 1
                                        },
                                        name: {
                                            type: "string",
                                            id: 2
                                        },
                                        issuer: {
                                            type: "string",
                                            id: 3
                                        }
                                    }
                                }
                            }
                        });

                        const MigrationPayload = root.lookupType("MigrationPayload");
                        const message = MigrationPayload.decode(bytes);

                        if (message.otpParameters && message.otpParameters.length > 0) {
                            const param = message.otpParameters[0]; // Extract first account
                            secret = bytesToBase32(param.secret);
                            label = (param.issuer ? param.issuer + " (" + param.name + ")" : param.name) || "Google Exported Token";
                        }
                    }
                } catch (e) {
                    console.error("Migration Parse Error:", e);
                }
            }
            // Case B: Standard otpauth:// URI
            else if (qrData.startsWith("otpauth://")) {
                try {
                    const url = new URL(qrData);
                    secret = url.searchParams.get("secret");
                    const pathParts = url.pathname.split('/');
                    if (pathParts.length > 1) {
                        label = decodeURIComponent(pathParts[pathParts.length - 1]);
                    }
                    if (url.searchParams.get("issuer")) {
                        label = decodeURIComponent(url.searchParams.get("issuer")) + " (" + label + ")";
                    }
                } catch (e) {
                    console.error("URI Parse Error:", e);
                }
            }
            // Case C: Raw Base32 Secret Key String
            else if (/^[A-Za-z2-7=\s]{16,64}$/.test(qrData)) {
                secret = qrData.replace(/\s+/g, '');
                label = "Backup Key Token";
            }
            // Case D: Plain key-value parameter containing secret=
            else if (qrData.includes("secret=")) {
                const match = qrData.match(/secret=([A-Za-z2-7]+)/i);
                if (match && match[1]) {
                    secret = match[1];
                }
            }

            // Clean & Validate Secret
            secret = (secret || "").replace(/[^A-Za-z2-7]/g, '').toUpperCase();

            if (secret.length >= 8) {
                document.getElementById('extracted-secret').value = secret;
                document.getElementById('extracted-label').value = label;
                document.getElementById('upload-label').innerText = "✅ Decoded: " + label;
                document.getElementById('save-account-form').style.display = "block";
            } else {
                alert("The QR code was read, but no valid Base32 TOTP secret key could be extracted.\n\nScanned content: " + qrData);
                document.getElementById('upload-label').innerText = "Click or Drag & Drop QR Image Here";
            }
        }

        // 3. Real-time TOTP Code & Progress Bar Generator
        function updateTOTPCodes() {
            const cards = document.querySelectorAll('.account-card');
            const seconds = new Date().getSeconds();
            const remaining = 30 - (seconds % 30);
            const progressPercent = (remaining / 30) * 100;

            cards.forEach(card => {
                const secret = card.getAttribute('data-secret');
                const codeElement = card.querySelector('.totp-code');
                const barElement = card.querySelector('.timer-bar');

                if (secret && window.OTPAuth) {
                    try {
                        const totp = new OTPAuth.TOTP({
                            secret: OTPAuth.Secret.fromBase32(secret)
                        });
                        codeElement.innerText = totp.generate();
                    } catch (e) {
                        codeElement.innerText = "ERROR";
                    }
                }
                if (barElement) {
                    barElement.style.width = progressPercent + "%";
                }
            });
        }
        setInterval(updateTOTPCodes, 1000);
        updateTOTPCodes();

        // 4. Header Clock Script
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