<?php
// ============================================
// FILE: daboreysearch/crawl.php
// ============================================
require_once 'config.php';
require_once 'functions.php';
require_once 'security.php';

// Simple password protection (optional)
$crawl_password = 'crawl123'; // CHANGE THIS!

// Check if password provided
if (!isset($_GET['pass']) || $_GET['pass'] !== $crawl_password) {
    die("🔒 Access denied. Add ?pass=crawl123 to the URL.");
}

// Function to crawl a website
function crawl_website($start_url, $max_pages = 100) {
    $urls = [];
    $visited = [];
    $queue = [$start_url];
    $count = 0;
    
    while (!empty($queue) && $count < $max_pages) {
        $current_url = array_shift($queue);
        $current_url = trim($current_url);
        
        // Skip if already visited or empty
        if (empty($current_url) || isset($visited[$current_url])) {
            continue;
        }
        
        $visited[$current_url] = true;
        $count++;
        
        echo "🔄 Crawling: $current_url\n";
        
        // Fetch page content
        $html = @file_get_contents($current_url);
        if ($html === false) {
            echo "❌ Failed to fetch: $current_url\n";
            continue;
        }
        
        // Extract title
        $title = '';
        if (preg_match('/<title>(.*?)<\/title>/i', $html, $matches)) {
            $title = trim($matches[1]);
        }
        
        // Save URL to database
        add_url($current_url, $title);
        echo "✅ Saved: " . ($title ?: $current_url) . "\n";
        
        // Extract all links
        preg_match_all('/<a\s+href=["\']([^"\']*)["\']/i', $html, $matches);
        $links = $matches[1];
        
        foreach ($links as $link) {
            // Convert relative to absolute
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
            
            // Only crawl same domain (optional - remove to crawl all)
            $parsed_current = parse_url($current_url);
            $parsed_link = parse_url($link);
            
            if (isset($parsed_link['host']) && $parsed_link['host'] !== $parsed_current['host']) {
                // Skip external domains
                continue;
            }
            
            // Add to queue if not visited
            if (!isset($visited[$link]) && !in_array($link, $queue)) {
                $queue[] = $link;
            }
        }
        
        // Delay to be nice to server
        usleep(100000); // 0.1 second
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
        h1 { color: #38bdf8; }
        .info { color: #94a3b8; }
        .success { color: #4ade80; }
        .error { color: #f87171; }
        .form-group { margin: 15px 0; }
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
        button:hover { background: #0369a1; }
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
    <h1>🕷️ URL Crawler</h1>
    <p class="info">Enter a URL to start crawling. The crawler will find and save all internal links.</p>
    
    <form method="POST" action="">
        <div class="form-group">
            <input type="url" name="start_url" placeholder="https://yoursite.com" required>
        </div>
        <div class="form-group">
            <label>Max pages: <input type="number" name="max_pages" value="100" min="1" max="1000" style="width:80px; background:#0f172a; border:1px solid #334155; color:white; padding:6px;"></label>
        </div>
        <button type="submit">🚀 Start Crawl</button>
    </form>
    
    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['start_url'])): 
        $start_url = $_POST['start_url'];
        $max_pages = (int)($_POST['max_pages'] ?? 100);
        
        echo '<div class="output">';
        echo "🔄 Starting crawl...\n";
        echo "📍 Target: $start_url\n";
        echo "📄 Max pages: $max_pages\n";
        echo str_repeat('-', 40) . "\n";
        
        // Start crawling
        $crawled = crawl_website($start_url, $max_pages);
        
        echo str_repeat('-', 40) . "\n";
        echo "✅ Crawl complete!\n";
        echo "📊 Total pages crawled: $crawled\n";
        echo "📚 Total URLs in database: " . count_urls() . "\n";
        echo '</div>';
    endif; ?>
    
    <a href="index.php" class="back-link">← Back to Search</a>
    <br>
    <a href="?pass=<?php echo $crawl_password; ?>" class="back-link" style="color:#64748b;">🔄 Reset</a>
</div>

</body>
</html>