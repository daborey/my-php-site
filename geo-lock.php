<?php
/**
 * Geo-Lock: Only allow Cambodia (KH)
 * Works on Cloud Run (uses IP lookup since X-Appengine-Country is not sent)
 */

// 1. Get real IP from Cloud Run proxy
$user_ip = $_SERVER['HTTP_X_FORWARDED_FOR'] 
    ?? $_SERVER['REMOTE_ADDR'] 
    ?? '';
if (strpos($user_ip, ',') !== false) {
    $user_ip = trim(explode(',', $user_ip)[0]);
}

// 2. Try Cloudflare header first (if you add Cloudflare later)
$visitor_country = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '';

// 3. Fallback: IP lookup API with caching
if ($visitor_country === '' && filter_var($user_ip, FILTER_VALIDATE_IP)) {
    $cache_file = sys_get_temp_dir() . '/geo_' . md5($user_ip) . '.txt';
    
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 3600) {
        $visitor_country = trim(file_get_contents($cache_file));
    } else {
        $response = @file_get_contents("http://ip-api.com/json/{$user_ip}?fields=countryCode");
        if ($response) {
            $data = json_decode($response, true);
            $visitor_country = strtoupper($data['countryCode'] ?? '');
            @file_put_contents($cache_file, $visitor_country);
        }
    }
}

// 4. Block if country known and not KH
// If lookup fails (empty), ALLOW — avoids locking everyone out
if ($visitor_country !== '' && $visitor_country !== 'KH') {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Access Denied</title>
        <style>
            body { font-family: sans-serif; text-align: center; padding: 50px; background: #f4f6f9; color: #333; margin: 0; }
            .container { max-width: 500px; margin: auto; background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            h1 { color: #d9534f; margin-bottom: 16px; }
            p { color: #666; line-height: 1.6; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>403 Forbidden</h1>
            <p>This service is restricted to users within Cambodia.</p>
        </div>
    </body>
    </html>';
    exit();
}