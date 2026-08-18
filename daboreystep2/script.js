// ============================================
// DABOREYPASS - Main JavaScript
// Device Detection, Camera, QR Scanner, TOTP
// ============================================

// ============================================
// ENVIRONMENT (passed from PHP)
// ============================================
const ENVIRONMENT = window.ENVIRONMENT || 'local';
const BASE_PATH = window.BASE_PATH || '/my-php-site/daboreystep2';

// ============================================
// GLOBALS
// ============================================
let camInstance = null;
let deviceInfo = { isMobile: false, isTablet: false, isDesktop: true };
const status = document.getElementById('status');

// ============================================
// DEVICE DETECTION
// ============================================
function detectDevice() {
    const ua = navigator.userAgent;
    const isMobile = /Mobi|Android|iPhone|iPad|iPod|BlackBerry|Windows Phone/i.test(ua);
    const isTablet = /iPad|Tablet|Silk|KFAPWI|Nexus 7|Nexus 10|SM-T|GT-N/i.test(ua) && !isMobile;
    const isDesktop = !isMobile && !isTablet;
    
    deviceInfo = { isMobile, isTablet, isDesktop };
    
    const hint = document.getElementById('device-hint');
    const msg = document.getElementById('device-message');
    const icon = document.getElementById('camera-icon');
    const label = document.getElementById('camera-label');
    const startBtn = document.getElementById('start-cam-btn');
    
    // Check if elements exist before modifying
    if (!hint || !msg || !icon || !label || !startBtn) {
        console.warn("Device hint elements not found in DOM");
        return;
    }
    
    if (isMobile) {
        msg.textContent = '📱 Mobile device detected - using rear camera';
        icon.textContent = '📱';
        label.textContent = 'Mobile Camera (Rear)';
        startBtn.innerHTML = '<span class="btn-icon">📱</span><span class="btn-label">Open Rear</span>';
        document.body.classList.add('device-mobile');
    } else if (isTablet) {
        msg.textContent = '📋 Tablet detected - using rear camera';
        icon.textContent = '📋';
        label.textContent = 'Tablet Camera';
        startBtn.innerHTML = '<span class="btn-icon">📋</span><span class="btn-label">Open Camera</span>';
        document.body.classList.add('device-tablet');
    } else {
        msg.textContent = '💻 Desktop detected - using webcam';
        icon.textContent = '💻';
        label.textContent = 'Webcam';
        startBtn.innerHTML = '<span class="btn-icon">💻</span><span class="btn-label">Open Webcam</span>';
        document.body.classList.add('device-desktop');
    }
}

// ============================================
// CAMERA SCANNER - Device-Aware + Environment-Aware
// ============================================
function startCamera() {
    const isLocal = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
    const isHttps = window.location.protocol === 'https:';
    
    // Cloud Run: Always HTTPS, camera works
    if (ENVIRONMENT === 'cloud') {
        startCameraEngine();
        return;
    }
    
    // Local XAMPP: Check HTTPS
    if (!isLocal && !isHttps) {
        alert("Camera requires HTTPS. Please use Option 2 (Upload) instead.");
        return;
    }
    
    if (isLocal && !isHttps) {
        if (confirm("Camera on HTTP may be blocked. Try anyway?")) {
            startCameraEngine();
        } else {
            document.getElementById('start-cam-btn').disabled = false;
            document.getElementById('stop-cam-btn').disabled = true;
            status.textContent = "Use Option 2 (Upload) instead.";
        }
        return;
    }
    
    startCameraEngine();
}

function startCameraEngine() {
    let facingMode = "environment";
    if (deviceInfo.isDesktop) {
        facingMode = "user";
    }
    
    document.getElementById('start-cam-btn').disabled = true;
    document.getElementById('stop-cam-btn').disabled = false;
    status.textContent = "Starting " + (deviceInfo.isDesktop ? "webcam" : "camera") + "...";
    
    try {
        camInstance = new Html5Qrcode("viewport");
        camInstance.start(
            { facingMode: facingMode },
            { fps: 15, qrbox: 180, aspectRatio: 1.0 },
            (decodedText) => { handleDecodedText(decodedText, 'camera'); },
            () => {}
        ).then(() => {
            status.textContent = "Camera ready - scanning for QR codes...";
        }).catch((err) => {
            console.error("Camera error:", err);
            status.textContent = "Camera access denied. Use Option 2 (Upload) instead.";
            stopCamera();
        });
    } catch (err) {
        status.textContent = "Camera not available. Use Option 2 (Upload).";
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
            status.textContent = "Camera stopped. Use Option 2 (Upload) instead.";
        });
    }
}

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
// QR UPLOAD (jsQR) - WORKS EVERYWHERE
// ============================================
const fileInput = document.getElementById('qr-file-input');
const dropZone = document.getElementById('drop-zone');

if (fileInput) {
    fileInput.addEventListener('change', function(e) {
        if (e.target.files.length) {
            processFile(e.target.files[0]);
        }
    });
}

if (dropZone) {
    dropZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.classList.add('dragover');
    });

    dropZone.addEventListener('dragleave', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
    });

    dropZone.addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
        if (e.dataTransfer.files.length) {
            processFile(e.dataTransfer.files[0]);
        }
    });
}

function processFile(file) {
    if (!status) return;
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
    if (!status) return;
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

    const timerDisplay = document.getElementById('timer-display');
    const timerBar = document.getElementById('timer-bar');
    
    if (timerDisplay) timerDisplay.innerText = "Codes change in: " + timeLeft + "s";
    if (timerBar) timerBar.style.width = (timeLeft / 30) * 100 + "%";

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

// ============================================
// INIT - Runs when DOM is fully loaded
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    // Add environment class to body
    if (ENVIRONMENT === 'cloud') {
        document.body.classList.add('env-cloud');
    } else {
        document.body.classList.add('env-local');
    }
    
    // Detect device
    detectDevice();
    
    // Load OTPAuth
    loadOTPAuth();
});