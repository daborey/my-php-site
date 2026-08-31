<add>
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
</add>