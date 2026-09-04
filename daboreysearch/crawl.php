<?php
// ============================================
// FILE: daboreysearch/crawl.php
// ============================================
ini_set('max_execution_time', 300); // Allow script to run up to 5 minutes
ini_set('memory_limit', '256M');     // Increase memory limit for larger queues
require_once 'config.php';
require_once 'functions.php';
require_once 'security.php';

// ===== LOGIN CHECK =====
if (!isset($_SESSION['search_user_id'])) {
    header("Location: /daboreysearch/login.php");
    exit;
}

// ===== HANDLE CLEAR ALL DATA =====
if (isset($_GET['clear']) && $_GET['clear'] === 'yes') {
    clear_urls();
    header("Location: /daboreysearch/crawl.php?cleared=1");
    exit;
}

// ===== AUTO-CRAWL FROM INDEX =====
$auto_url = $_GET['url'] ?? '';
$auto_mode = isset($_GET['auto']) && $_GET['auto'] == 1;

if ($auto_mode && !empty($auto_url)) {
    // Auto-start crawl
    echo '<div class="container">';
    echo '<h1>🕷️ Auto-Crawling...</h1>';
    echo '<div class="output">';
    echo "🔄 Starting crawl from index page...\n";
    echo "📍 Target: $auto_url\n";
    echo "📄 Max pages: 500\n";
    echo str_repeat('-', 40) . "\n";

    $crawled = crawl_website($auto_url, 500);

    echo str_repeat('-', 40) . "\n";
    echo "✅ Crawl complete!\n";
    echo "📊 Total pages crawled: $crawled\n";
    echo "📚 Total URLs in database: " . count_urls() . "\n";
    echo '</div>';
    echo '<a href="/daboreysearch/index.php?crawled=' . $crawled . '&source=' . urlencode(parse_url($auto_url, PHP_URL_HOST)) . '" class="back-link">← Back to Search</a>';
    echo '</div>';
    exit;
}

// ===== HELPER FUNCTIONS =====

/**
 * Checks if a URL is allowed by the domain's robots.txt rules.
 */
function is_url_allowed_by_robots($url)
{
    static $robots_cache = [];

    $parsed = parse_url($url);
    if (!isset($parsed['scheme'], $parsed['host'])) {
        return true;
    }

    $domain = $parsed['scheme'] . '://' . $parsed['host'];

    // Cache robots.txt content per domain to avoid re-fetching
    if (!isset($robots_cache[$domain])) {
        $robots_url = $domain . '/robots.txt';
        $context = stream_context_create([
            'http' => [
                'user_agent' => 'DaBoreySearchBot/1.0 (+https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ')',
                'timeout' => 3
            ]
        ]);

        $content = @file_get_contents($robots_url, false, $context);
        $disallowed = [];

        if ($content !== false) {
            $lines = explode("\n", $content);
            $applies = true;

            foreach ($lines as $line) {
                $line = trim(preg_replace('/#.*/', '', $line));
                if (empty($line)) continue;

                if (stripos($line, 'User-agent:') === 0) {
                    $agent = trim(substr($line, 11));
                    $applies = ($agent === '*' || stripos($agent, 'DaBoreySearch') !== false);
                } elseif ($applies && stripos($line, 'Disallow:') === 0) {
                    $rule = trim(substr($line, 9));
                    if ($rule !== '') {
                        $disallowed[] = $rule;
                    }
                }
            }
        }
        $robots_cache[$domain] = $disallowed;
    }

    $path = $parsed['path'] ?? '/';

    foreach ($robots_cache[$domain] as $rule) {
        if ($rule === '/' || str_starts_with($path, $rule)) {
            return false;
        }
    }

    return true;
}

/**
 * Main function to crawl web pages starting from a given URL
 */
function crawl_website($start_url, $max_pages = 500)
{
    $urls = [];
    $visited = [];
    $queue = [$start_url];
    $count = 0;

    while (!empty($queue) && $count < $max_pages) {
        $current_url = array_shift($queue);
        $current_url = trim($current_url);

        if (empty($current_url) || isset($visited[$current_url])) {
            continue;
        }

        $visited[$current_url] = true;

        // 1. Check robots.txt compliance before hitting the server
        if (!is_url_allowed_by_robots($current_url)) {
            echo "⚠️ Skipped (Blocked by robots.txt): $current_url\n";
            continue;
        }

        // 2. Skip non-HTML links (images, archives, media files) by URL extension
        $path_extension = strtolower(pathinfo(parse_url($current_url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        if (in_array($path_extension, ['pdf', 'zip', 'rar', 'exe', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'mp4', 'mp3', 'css', 'js', 'json'])) {
            echo "⚠️ Skipped (Non-HTML File): $current_url\n";
            continue;
        }

        $count++;

        echo "🔄 Crawling: $current_url\n";

        // Transparent User-Agent header for courteous request identification
        $options = [
            'http' => [
                'method' => "GET",
                'header' => "User-Agent: DaBoreySearchBot/1.0 (+https://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ")\r\n"
            ]
        ];
        $context = stream_context_create($options);
        $html = @file_get_contents($current_url, false, $context);
        if ($html === false) {
            echo "❌ Failed to fetch: $current_url\n";
            continue;
        }

        $title = '';
        if (preg_match('/<title>(.*?)<\/title>/i', $html, $matches)) {
            $title = trim($matches[1]);
        }

        $source_domain = parse_url($start_url, PHP_URL_HOST);
        add_url($current_url, $title, $source_domain);
        echo "✅ Saved: " . ($title ?: $current_url) . "\n";

        preg_match_all('/<a\s+href=["\']([^"\']*)["\']/i', $html, $matches);
        $links = $matches[1];

        foreach ($links as $link) {
            if (strpos($link, 'http') !== 0) {
                if (strpos($link, '/') === 0) {
                    $parsed = parse_url($current_url);
                    $base = $parsed['scheme'] . '://' . $parsed['host'];
                    $link = $base . $link;
                } elseif (strpos($link, '#') === 0 || strpos($link, 'javascript:') === 0) {
                    continue;
                } else {
                    $link = dirname($current_url) . '/' . $link;
                }
            }

            $parsed_current = parse_url($current_url);
            $parsed_link = parse_url($link);

            if (isset($parsed_link['host']) && $parsed_link['host'] !== $parsed_current['host']) {
                continue;
            }

            if (!isset($visited[$link]) && !in_array($link, $queue)) {
                $queue[] = $link;
            }
        }

        // Polite rate-limiting delay between requests (100ms)
        usleep(100000);
    }

    return $count;
}

// ===== MAIN EXECUTION =====
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Crawl URLs - Da Borey Search</title>
    <style>
        body {
            font-family: 'Kantumruy Pro', 'Segoe UI', Arial, sans-serif;
            background-color: #0f172a;
            color: #f8fafc;
            padding: 20px;
        }

        .container {
            max-width: 700px;
            margin: 0 auto;
            background: #1e293b;
            padding: 25px;
            border-radius: 8px;
            border: 1px solid #334155;
        }

        h1 {
            color: #38bdf8;
        }

        .info {
            color: #94a3b8;
        }

        .form-group {
            margin: 15px 0;
        }

        input[type="url"] {
            width: 100%;
            padding: 10px;
            background: #0f172a;
            border: 1px solid #334155;
            color: white;
            border-radius: 4px;
            box-sizing: border-box;
        }

        button {
            background: #0284c7;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #0369a1;
        }

        .output {
            background: #0f172a;
            padding: 15px;
            border-radius: 4px;
            margin-top: 15px;
            max-height: 400px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 13px;
            white-space: pre-wrap;
            word-wrap: break-word;
            color: #cbd5e1;
        }

        .back-link {
            color: #38bdf8;
            text-decoration: none;
            display: inline-block;
            margin-top: 15px;
        }
    </style>
</head>

<body>

    <div class="container">
        <?php if (isset($_GET['cleared'])): ?>
            <div style="background: #7f1d1d; border: 1px solid #991b1b; color: #fca5a5; padding: 12px 18px; border-radius: 6px; margin-bottom: 20px;">
                ✅ All URLs have been deleted from the database.
            </div>
        <?php endif; ?>
        <h1>🕷️ URL Crawler</h1>
        <p class="info">Enter a URL to start crawling. The crawler will find and save all internal links.</p>

        <form method="POST" action="">
            <div class="form-group">
                <input type="url" name="start_url" placeholder="https://yoursite.com" required>
            </div>
            <div class="form-group">
                <label>Max pages: <input type="number" name="max_pages" value="500" min="1" max="1000" style="width:80px; background:#0f172a; border:1px solid #334155; color:white; padding:6px;"></label>
            </div>
            <button type="submit">🚀 Start Crawl</button>
        </form>

        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['start_url'])) :
            $start_url = $_POST['start_url'];
            $max_pages = (int)($_POST['max_pages'] ?? 500);

            echo '<div class="output">';
            echo "🔄 Starting crawl...\n";
            echo "📍 Target: $start_url\n";
            echo "📄 Max pages: $max_pages\n";
            echo str_repeat('-', 40) . "\n";

            $crawled = crawl_website($start_url, $max_pages);

            echo str_repeat('-', 40) . "\n";
            echo "✅ Crawl complete!\n";
            echo "📊 Total pages crawled: $crawled\n";
            echo "📚 Total URLs in database: " . count_urls() . "\n";
            echo '</div>';
        endif; ?>

        <a href="index.php" class="back-link">← Back to Search</a>
        <br>
        <a href="/daboreysearch/logout.php" style="color:#ef4444; text-decoration:none; margin-top:10px; display:inline-block;">🚪 Logout</a>
        <br><br>
        <a href="?clear=yes" onclick="return confirm('⚠️ Delete ALL crawled URLs from ALL sources? This cannot be undone!');" style="color:#ef4444; text-decoration:none; font-weight:bold; border:1px solid #ef4444; padding:8px 16px; border-radius:4px; display:inline-block; margin-top:10px;">🗑️ Clear All Data</a>
    </div>

</body>

</html>