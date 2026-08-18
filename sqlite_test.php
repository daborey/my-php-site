<?php
$dbPath = __DIR__ . '/test.db';

try {
    $conn = new SQLite3($dbPath);
    echo "SQLite3 connected successfully!<br>";
    echo "Database created at: " . $dbPath;
} catch (Exception $e) {
    echo "SQLite error: " . $e->getMessage();
}
?>