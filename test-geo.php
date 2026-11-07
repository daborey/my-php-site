<?php
header('Content-Type: text/plain; charset=utf-8');

echo "=== Geo-Lock Test ===\n\n";
echo "X-Appengine-Country: " . ($_SERVER['HTTP_X_APPENGINE_COUNTRY'] ?? 'NOT SET') . "\n";
echo "X-Appengine-Region:  " . ($_SERVER['HTTP_X_APPENGINE_REGION'] ?? 'NOT SET') . "\n";
echo "X-Appengine-City:    " . ($_SERVER['HTTP_X_APPENGINE_CITY'] ?? 'NOT SET') . "\n";
echo "REMOTE_ADDR:         " . ($_SERVER['REMOTE_ADDR'] ?? 'NOT SET') . "\n\n";

echo "--- All headers ---\n";
foreach (getallheaders() as $name => $value) {
    if (stripos($name, 'appengine') !== false || stripos($name, 'forwarded') !== false) {
        echo "$name: $value\n";
    }
}