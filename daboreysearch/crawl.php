<?php
ini_set('max_execution_time', 600);
ini_set('memory_limit', '256M');

// 1. Force continuous streaming output to prevent proxy/server timeouts
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
for ($i = 0; $i < ob_get_level(); $i++) {
    ob_end_flush();
}
ob_implicit_flush(true);

// 2. Catch fatal crashes, execution timeouts, and out-of-memory errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "<br><br>❌ <strong>Crawl Stopped Due To Fatal Error:</strong> " . htmlspecialchars($error['message']) . 
             " in <strong>" . htmlspecialchars($error['file']) . "</strong> on line <strong>" . $error['line'] . "</strong><br>";
    }
});

require_once 'config.php';

/**
 * Initializes queue table and enables WAL mode for high-performance SQLite I/O.
 */
function init_queue_table(PDO $db): void {
    $db->exec("PRAGMA journal_mode = WAL;");
    $db->exec("PRAGMA synchronous = NORMAL;");

    $db->exec("CREATE TABLE IF NOT EXISTS crawl_queue (
        url TEXT PRIMARY KEY,
        status TEXT DEFAULT 'pending'
    )");
}

/**
 * Robustly resolves any relative, absolute, or protocol-relative URL 
 * into a fully-qualified HTTP/HTTPS URL.
 */
function resolve_url(string $relative_url, string $base_url): ?string {
    $relative_url = trim($relative_url);

    // Ignore non-http links, anchors, and javascript
    if (empty($relative_url) || 
        preg_match('/^(javascript:|mailto:|tel:|data:|#)/i', $relative_url)) {
        return null;
    }

    $base_parts = parse_url($base_url);
    if (!isset($base_parts['host'])) {
        return null;
    }

    $scheme = $base_parts['scheme'] ?? 'https';
    $host   = $base_parts['host'];
    $port   = isset($base_parts['port']) ? ':' . $base_parts['port'] : '';

    // 1. Fully-qualified URL
    if (preg_match('/^https?:\/\//i', $relative_url)) {
        return $relative_url;
    }

    // 2. Protocol-relative URL
    if (str_starts_with($relative_url, '//')) {
        return $scheme . ':' . $relative_url;
    }

    // 3. Absolute path from host root
    if (str_starts_with($relative_url, '/')) {
        return $scheme . '://' . $host . $port . $relative_url;
    }

    // 4. Query string only
    if (str_starts_with($relative_url, '?')) {
        $path = $base_parts['path'] ?? '/';
        return $scheme . '://' . $host . $port . $path . $relative_url;
    }

    // 5. Relative directory path
    $path = $base_parts['path'] ?? '/';
    $dir  = rtrim(dirname($path), '/\\');
    $dir  = ($dir === '') ? '' : $dir;

    return $scheme . '://' . $host . $port . $dir . '/' . ltrim($relative_url, '/');
}

/**
 * Safely fetches HTML content using cURL if available, or file_get_contents stream context.
 */
function fetch_webpage_content(string $url): string|false {
    $user_agent = 'DaBoreySearchBot/1.0 (+https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/bot)';

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => $user_agent,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING       => '', 
        ]);
        $content = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($http_code >= 200 && $http_code < 300) ? $content : false;
    }

    $options = [
        'http' => [
            'method'          => 'GET',
            'header'          => "User-Agent: {$user_agent}\r\nAccept: text/html,application/xhtml+xml\r\n",
            'timeout'         => 10,
            'follow_location' => 1,
            'ignore_errors'   => true
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ]
    ];

    $context = stream_context_create($options);
    return @file_get_contents($url, false, $context);
}

/**
 * Checks robots.txt restrictions safely.
 */
function is_url_allowed_by_robots(string $url): bool {
    $parsed = parse_url($url);
    if (!isset($parsed['host'])) {
        return false;
    }

    $robots_url = ($parsed['scheme'] ?? 'https') . '://' . $parsed['host'] . '/robots.txt';
    $robots_txt = fetch_webpage_content($robots_url);

    if ($robots_txt === false || empty($robots_txt)) {
        return true; 
    }

    $path = $parsed['path'] ?? '/';
    $user_agent_section = false;

    foreach (explode("\n", $robots_txt) as $line) {
        $line = trim(preg_replace('/#.*/', '', $line)); 
        if (empty($line)) continue;

        if (preg_match('/^User-agent:\s*(.*)$/i', $line, $matches)) {
            $agent = trim($matches[1]);
            $user_agent_section = ($agent === '*' || strcasecmp($agent, 'DaBoreySearchBot') === 0);
            continue;
        }

        if ($user_agent_section && preg_match('/^Disallow:\s*(.*)$/i', $line, $matches)) {
            $disallow_path = trim($matches[1]);
            if (!empty($disallow_path) && str_starts_with($path, $disallow_path)) {
                return false;
            }
        }
    }

    return true;
}

/**
 * High-Speed Resumable Web Crawler Logic
 */
function crawl_website(string $start_url, int $max_pages = 2500): int {
    global $db; 

    if (!$db) {
        echo "❌ Database connection failure. Variable \$db is not set.<br>";
        return 0;
    }

    init_queue_table($db);

    // Seed queue with initial URL if queue is empty
    $pending_count = $db->query("SELECT COUNT(*) FROM crawl_queue WHERE status = 'pending'")->fetchColumn();
    if ($pending_count == 0) {
        $stmt = $db->prepare("INSERT OR IGNORE INTO crawl_queue (url, status) VALUES (:url, 'pending')");
        $stmt->execute([':url' => strtok($start_url, '#')]);
    } else {
        echo "📂 <strong>Resuming existing crawl queue from database...</strong><br>";
    }

    $parsed_start = parse_url($start_url);
    $base_domain  = $parsed_start['host'] ?? '';

    if (empty($base_domain)) {
        echo "❌ Invalid starting URL.<br>";
        return 0;
    }

    $count = 0;
    $start_time = time();
    $max_seconds = 280; // Safety exit threshold before server execution limit

    // Prepared statements
    $get_next_stmt  = $db->prepare("SELECT url FROM crawl_queue WHERE status = 'pending' LIMIT 1");
    $mark_done_stmt = $db->prepare("UPDATE crawl_queue SET status = 'completed' WHERE url = :url");
    $add_queue_stmt = $db->prepare("INSERT OR IGNORE INTO crawl_queue (url, status) VALUES (:url, 'pending')");
    $save_url_stmt  = $db->prepare("INSERT INTO urls (url, title, source, crawled_at) VALUES (:url, :title, :source, CURRENT_TIMESTAMP) ON CONFLICT(url) DO UPDATE SET title = :title, source = :source, crawled_at = CURRENT_TIMESTAMP");

    $db_count = $db->query("SELECT COUNT(*) FROM urls")->fetchColumn();

    while (($db_count + $count) < $max_pages) {
        // Exit safely before server times out
        if ((time() - $start_time) >= $max_seconds) {
            echo "<br>⏱️ <strong>Execution limit reached ({$max_seconds}s)! Saving queue state for auto-resume...</strong><br>";
            break;
        }

        // Fetch next URL
        $get_next_stmt->execute();
        $current_url = $get_next_stmt->fetchColumn();

        if (!$current_url) {
            echo "🎉 <strong>Queue exhausted! No more pending links to crawl.</strong><br>";
            break;
        }

        // Mark current URL completed immediately
        $mark_done_stmt->execute([':url' => $current_url]);

        // Filter non-HTML extensions and redundant patterns
        if (preg_match('/\.(pdf|jpg|jpeg|png|gif|zip|rar|exe|dmg|mp3|mp4|css|js|xml|json)$/i', $current_url) ||
            preg_match('/\/(page\/|tags\/|download\/.*\/download\/)/i', $current_url)) {
            continue;
        }

        // Check robots.txt
        if (!is_url_allowed_by_robots($current_url)) {
            echo "⚠️ Skipped (Blocked by robots.txt): " . htmlspecialchars($current_url) . "<br>";
            continue;
        }

        echo "🔄 Crawling: " . htmlspecialchars($current_url) . "<br>";

        $html = fetch_webpage_content($current_url);
        if ($html === false || empty($html)) {
            echo "❌ Failed to fetch: " . htmlspecialchars($current_url) . "<br>";
            continue;
        }

        // Parse HTML
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        // Extract title
        $title = '';
        $title_nodes = $dom->getElementsByTagName('title');
        if ($title_nodes->length > 0) {
            $title = trim($title_nodes->item(0)->nodeValue);
        }
        if (empty($title)) {
            $title = 'Untitled Page (' . parse_url($current_url, PHP_URL_PATH) . ')';
        }

        // Save indexed page into DB
        try {
            $save_url_stmt->execute([
                ':url'    => $current_url,
                ':title'  => $title,
                ':source' => $base_domain
            ]);
            $count++;
            echo "✅ Saved: " . htmlspecialchars($title) . "<br>";
        } catch (PDOException $e) {
            echo "⚠️ Database Error: " . htmlspecialchars($e->getMessage()) . "<br>";
        }

        // Check for HTML base tag
        $effective_base_url = $current_url;
        $base_nodes = $dom->getElementsByTagName('base');
        if ($base_nodes->length > 0 && $base_nodes->item(0)->hasAttribute('href')) {
            $base_href = $base_nodes->item(0)->getAttribute('href');
            $resolved_base = resolve_url($base_href, $current_url);
            if ($resolved_base) {
                $effective_base_url = $resolved_base;
            }
        }

        // Extract internal links into memory array
        $extracted_links = [];
        $links = $dom->getElementsByTagName('a');
        foreach ($links as $link_node) {
            if (!$link_node->hasAttribute('href')) continue;

            $raw_href = strtok($link_node->getAttribute('href'), '#');
            $full_url = resolve_url($raw_href, $effective_base_url);

            if ($full_url) {
                $link_domain = parse_url($full_url, PHP_URL_HOST) ?? '';
                if (strcasecmp($link_domain, $base_domain) === 0) {
                    $extracted_links[] = $full_url;
                }
            }
        }

        // Bulk insert extracted links in ONE SINGLE TRANSACTION to maximize SQLite speed
        if (!empty($extracted_links)) {
            $db->beginTransaction();
            foreach ($extracted_links as $link_to_add) {
                $add_queue_stmt->execute([':url' => $link_to_add]);
            }
            $db->commit();
        }

        // Rate-limiting delay (50ms)
        usleep(50000);

        if (ob_get_level() > 0) ob_flush();
        flush();
    }

    return $count;
}

// Execution block for crawl requests
$start_url = $_GET['url'] ?? $_POST['url'] ?? '';
$max_pages = isset($_GET['max']) ? (int)$_GET['max'] : 2500;

if (!empty($start_url)) {
    if (!preg_match('/^https?:\/\//i', $start_url)) {
        $start_url = 'https://' . $start_url;
    }

    echo '<div style="font-family: monospace; background: #0f172a; color: #f8fafc; padding: 20px; border-radius: 8px; line-height: 1.6;">';
    echo "🕷️ <strong>DaBoreySearch Engine Crawler (Auto Resume)</strong><br>";
    echo "📍 Target: " . htmlspecialchars($start_url) . "<br>";
    echo "📄 Cap Limit: " . $max_pages . " pages<br>";
    echo "----------------------------------------<br>";

    $total = crawl_website($start_url, $max_pages);

    $db_count = $db->query("SELECT COUNT(*) FROM urls")->fetchColumn();
    $queue_remaining = $db->query("SELECT COUNT(*) FROM crawl_queue WHERE status = 'pending'")->fetchColumn();

    echo "----------------------------------------<br>";
    echo "✅ <strong>Batch Complete!</strong><br>";
    echo "📊 Pages crawled in this run: " . $total . "<br>";
    echo "📚 Total indexed URLs in DB: " . $db_count . "<br>";
    echo "⏳ Remaining URLs pending in queue: " . $queue_remaining . "<br>";
    echo "----------------------------------------<br>";

    // Auto-continue if goal not met and queue still has pending URLs
    if ($db_count < $max_pages && $queue_remaining > 0) {
        $next_url = "crawl.php?url=" . urlencode($start_url) . "&max=" . $max_pages;
        
        echo "<div style='margin-top: 15px; padding: 15px; background: #1e293b; border-left: 4px solid #facc15; border-radius: 4px;'>";
        echo "<div style='color: #facc15; font-weight: bold;' id='timer'>
                ⏱️ Reached 280s batch limit. Automatically resuming next batch in 5 seconds...
              </div>";
        echo '<br><a href="index.php" style="color: #38bdf8; text-decoration: none;">[ Stop & Return to Search ]</a>';
        echo "</div>";

        echo "
        <script>
            let seconds = 5;
            const timerElement = document.getElementById('timer');
            const interval = setInterval(() => {
                seconds--;
                if (seconds > 0) {
                    timerElement.innerText = '⏱️ Reached 280s batch limit. Automatically resuming next batch in ' + seconds + ' seconds...';
                } else {
                    clearInterval(interval);
                    window.location.href = '$next_url';
                }
            }, 1000);
        </script>";
    } else {
        echo "🎉 <strong>Crawl finished! Target of {$max_pages} pages reached or queue empty.</strong><br><br>";
        echo '<a href="index.php" style="display: inline-block; background: #334155; color: #f8fafc; padding: 8px 16px; text-decoration: none; border-radius: 6px;">← Back to Search</a>';
    }

    echo '</div>';
}