<?php
ini_set('max_execution_time', 300);
ini_set('memory_limit', '256M');

// Force continuous streaming output to prevent proxy/server timeouts
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
for ($i = 0; $i < ob_get_level(); $i++) {
    ob_end_flush();
}
ob_implicit_flush(true);

require_once 'config.php';

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

    // 2. Protocol-relative URL (e.g., //www.example.com/path)
    if (str_starts_with($relative_url, '//')) {
        return $scheme . ':' . $relative_url;
    }

    // 3. Absolute path from host root (e.g., /software/security)
    if (str_starts_with($relative_url, '/')) {
        return $scheme . '://' . $host . $port . $relative_url;
    }

    // 4. Query string only (e.g., ?page=2)
    if (str_starts_with($relative_url, '?')) {
        $path = $base_parts['path'] ?? '/';
        return $scheme . '://' . $host . $port . $path . $relative_url;
    }

    // 5. Relative directory path (e.g., subcategory/page.html)
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

    // Condition 1: Use cURL if enabled (handles modern SSL, redirects, and headers better)
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
            CURLOPT_ENCODING       => '', // Accept all encodings (gzip/deflate)
        ]);
        $content = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($http_code >= 200 && $http_code < 300) ? $content : false;
    }

    // Condition 2: Fallback to file_get_contents stream
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
        return true; // Assume allowed if robots.txt doesn't exist
    }

    $path = $parsed['path'] ?? '/';
    $user_agent_section = false;

    foreach (explode("\n", $robots_txt) as $line) {
        $line = trim(preg_replace('/#.*/', '', $line)); // Remove comments
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
 * Main Crawler Logic
 */
function crawl_website(string $start_url, int $max_pages = 500): int {
    global $pdo;

    $queue = [$start_url];
    $visited = [];
    $count = 0;

    $parsed_start = parse_url($start_url);
    $base_domain  = $parsed_start['host'] ?? '';

    if (empty($base_domain)) {
        echo "❌ Invalid starting URL.<br>";
        return 0;
    }

    while (!empty($queue) && $count < $max_pages) {
        $current_url = array_shift($queue);

        // Normalize URL to eliminate duplicate query parameters or anchor variations
        $current_url = strtok($current_url, '#');

        if (isset($visited[$current_url])) {
            continue;
        }
        $visited[$current_url] = true;

        // Skip non-HTML files based on extension
        if (preg_match('/\.(pdf|jpg|jpeg|png|gif|zip|rar|exe|dmg|mp3|mp4|css|js|xml|json)$/i', $current_url)) {
            continue;
        }

        // Verify robots.txt rules
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

        // Parse HTML safely with DOMDocument
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        // Extract <title> tag safely
        $title = '';
        $title_nodes = $dom->getElementsByTagName('title');
        if ($title_nodes->length > 0) {
            $title = trim($title_nodes->item(0)->nodeValue);
        }
        if (empty($title)) {
            $title = 'Untitled Page (' . parse_url($current_url, PHP_URL_PATH) . ')';
        }

        // Save into SQLite Database
        try {
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO pages (url, title, domain, updated_at) VALUES (:url, :title, :domain, CURRENT_TIMESTAMP)");
            $stmt->execute([
                ':url'    => $current_url,
                ':title'  => $title,
                ':domain' => $base_domain
            ]);
            $count++;
            echo "✅ Saved: " . htmlspecialchars($title) . "<br>";
        } catch (PDOException $e) {
            echo "⚠️ Database Error: " . htmlspecialchars($e->getMessage()) . "<br>";
        }

        // Check for HTML <base href="..."> tags that alter link paths
        $effective_base_url = $current_url;
        $base_nodes = $dom->getElementsByTagName('base');
        if ($base_nodes->length > 0 && $base_nodes->item(0)->hasAttribute('href')) {
            $base_href = $base_nodes->item(0)->getAttribute('href');
            $resolved_base = resolve_url($base_href, $current_url);
            if ($resolved_base) {
                $effective_base_url = $resolved_base;
            }
        }

        // Extract and resolve all internal <a> links
        $links = $dom->getElementsByTagName('a');
        foreach ($links as $link_node) {
            if (!$link_node->hasAttribute('href')) {
                continue;
            }

            $raw_href = $link_node->getAttribute('href');
            $full_url = resolve_url($raw_href, $effective_base_url);

            if ($full_url) {
                $link_domain = parse_url($full_url, PHP_URL_HOST) ?? '';
                
                // Stay within the same domain (BFS limit)
                if (strcasecmp($link_domain, $base_domain) === 0 && !isset($visited[$full_url])) {
                    $queue[] = $full_url;
                }
            }
        }

        // Rate-limiting delay (100ms)
        usleep(100000);

        // Keep HTTP connection live for browser streaming output
        if (ob_get_level() > 0) ob_flush();
        flush();
    }

    return $count;
}

// Execution block for crawl requests
$start_url = $_GET['url'] ?? $_POST['url'] ?? '';
$max_pages = isset($_GET['max']) ? (int)$_GET['max'] : 500;

if (!empty($start_url)) {
    // Ensure URL has a scheme prefix
    if (!preg_match('/^https?:\/\//i', $start_url)) {
        $start_url = 'https://' . $start_url;
    }

    echo '<div style="font-family: monospace; background: #0f172a; color: #f8fafc; padding: 20px; border-radius: 8px; line-height: 1.6;">';
    echo "🕷️ <strong>DaBoreySearch Engine Crawler</strong><br>";
    echo "📍 Target: " . htmlspecialchars($start_url) . "<br>";
    echo "📄 Cap Limit: " . $max_pages . " pages<br>";
    echo "----------------------------------------<br>";

    $total = crawl_website($start_url, $max_pages);

    $db_count = $pdo->query("SELECT COUNT(*) FROM pages")->fetchColumn();

    echo "----------------------------------------<br>";
    echo "✅ <strong>Crawl complete!</strong><br>";
    echo "📊 Total pages crawled in run: " . $total . "<br>";
    echo "📚 Total indexed URLs in DB: " . $db_count . "<br>";
    echo "----------------------------------------<br>";
    echo '<a href="index.php" style="color: #38bdf8; text-decoration: none;">← Back to Search</a>';
    echo '</div>';
}