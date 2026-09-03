<?php
// ============================================
// FILE: daboreysearch/index.php
// ============================================
require_once 'config.php';
require_once 'functions.php';
require_once 'security.php';

$keyword = $_GET['q'] ?? '';
$results = [];
$total_results = 0;

if (!empty($keyword)) {
    // Rate limiting
    if (!check_search_rate_limit()) {
        $error = "Too many search attempts. Please wait a few minutes.";
    } else {
        $results = search_urls($keyword);
        $total_results = count($results);
    }
}

// Initialize CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$total_urls = count_urls();
?>
<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Da Borey Search</title>
    <style>
        /* ===== YOUR STYLE ===== */
        body {
            font-family: 'Kantumruy Pro', 'Khmer OS Battambang', 'Segoe UI', Arial, sans-serif;
            background-color: #0f172a;
            color: #f8fafc;
            margin: 0;
            padding: 20px;
            min-height: 100vh;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        /* Header */
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 25px;
            background: #1e293b;
            border-bottom: 1px solid #334155;
            border-radius: 8px;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .logo h1 {
            color: #38bdf8;
            margin: 0;
            font-size: 24px;
        }
        
        .logo small {
            color: #94a3b8;
            font-size: 12px;
        }
        
        .stats {
            font-size: 13px;
            color: #94a3b8;
        }
        
        .stats span {
            color: #f8fafc;
            font-weight: bold;
        }
        
        /* Search Box */
        .search-container {
            margin-bottom: 30px;
        }
        
        .search-box {
            display: flex;
            gap: 10px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 8px;
            transition: border-color 0.3s;
        }
        
        .search-box:focus-within {
            border-color: #0284c7;
        }
        
        .search-box input {
            flex: 1;
            background: transparent;
            border: none;
            color: #f8fafc;
            padding: 10px 14px;
            font-size: 16px;
            outline: none;
        }
        
        .search-box input::placeholder {
            color: #64748b;
        }
        
        .search-box button {
            background: #0284c7;
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s;
            font-size: 14px;
        }
        
        .search-box button:hover {
            background: #0369a1;
        }
        
        /* Results */
        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #1e293b;
            margin-bottom: 20px;
        }
        
        .results-header h2 {
            color: #f8fafc;
            font-size: 18px;
            margin: 0;
        }
        
        .results-header .count {
            color: #94a3b8;
            font-size: 14px;
        }
        
        .results-grid {
            display: grid;
            gap: 12px;
        }
        
        .result-item {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 16px 20px;
            transition: border-color 0.2s;
        }
        
        .result-item:hover {
            border-color: #0284c7;
        }
        
        .result-item a {
            color: #38bdf8;
            text-decoration: none;
            font-weight: bold;
            font-size: 16px;
            word-break: break-all;
        }
        
        .result-item a:hover {
            text-decoration: underline;
        }
        
        .result-item .url {
            color: #64748b;
            font-size: 13px;
            word-break: break-all;
            margin-top: 4px;
        }
        
        .result-item .meta {
            color: #64748b;
            font-size: 12px;
            margin-top: 6px;
        }
        
        .result-item .meta span {
            color: #94a3b8;
        }
        
        /* No Results */
        .no-results {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }
        
        .no-results .emoji {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .no-results h3 {
            color: #f8fafc;
            margin-bottom: 8px;
        }
        
        .no-results p {
            margin: 0;
        }
        
        /* Error */
        .error {
            background: #7f1d1d;
            border: 1px solid #991b1b;
            color: #fca5a5;
            padding: 12px 18px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        /* Recent Searches */
        .recent-searches {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 16px 20px;
            margin-top: 30px;
        }
        
        .recent-searches h3 {
            color: #94a3b8;
            font-size: 14px;
            margin: 0 0 10px 0;
        }
        
        .recent-searches .tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .recent-searches .tag {
            background: #0f172a;
            color: #38bdf8;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 13px;
            text-decoration: none;
        }
        
        .recent-searches .tag:hover {
            background: #1e293b;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            body { padding: 12px; }
            header { flex-direction: column; text-align: center; }
            .search-box { flex-direction: column; }
            .search-box button { width: 100%; }
            .results-header { flex-direction: column; align-items: flex-start; gap: 6px; }
        }
    </style>
</head>
<body>

<div class="container">
    
    <!-- Header -->
    <header>
        <div class="logo">
            <h1>🔍 <?php echo $site_name; ?></h1>
            <small>Search any URL</small>
        </div>
        <div class="stats">
            📄 <span><?php echo number_format($total_urls); ?></span> URLs indexed
        </div>
    </header>
    
    <!-- Search Box -->
    <div class="search-container">
        <form method="GET" action="/daboreysearch/index.php">
            <div class="search-box">
                <input 
                    type="text" 
                    name="q" 
                    placeholder="Search for URLs... (e.g., football, article, note)" 
                    value="<?php echo htmlspecialchars($keyword); ?>"
                    autofocus
                >
                <button type="submit">Search</button>
            </div>
        </form>
    </div>
    
    <!-- Error -->
    <?php if (isset($error)): ?>
        <div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <!-- Results -->
    <?php if (!empty($keyword)): ?>
        <div class="results-header">
            <h2>Results for "<?php echo htmlspecialchars($keyword); ?>"</h2>
            <span class="count"><?php echo $total_results; ?> found</span>
        </div>
        
        <?php if ($total_results > 0): ?>
            <div class="results-grid">
                <?php foreach ($results as $url): ?>
                    <div class="result-item">
                        <a href="<?php echo htmlspecialchars($url['url']); ?>" target="_blank">
                            <?php echo htmlspecialchars($url['title'] ?: 'Untitled'); ?>
                        </a>
                        <div class="url"><?php echo htmlspecialchars($url['url']); ?></div>
                        <div class="meta">
                            Indexed: <span><?php echo date('M d, Y', strtotime($url['crawled_at'])); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="no-results">
                <div class="emoji">🔎</div>
                <h3>No results found</h3>
                <p>Try a different keyword or crawl more URLs.</p>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    
    <!-- Recent Searches -->
    <?php 
        $recent = get_recent_searches(10);
        if (!empty($recent)): 
    ?>
        <div class="recent-searches">
            <h3>🔥 Recent Searches</h3>
            <div class="tags">
                <?php foreach ($recent as $item): ?>
                    <a href="?q=<?php echo urlencode($item['keyword']); ?>" class="tag">
                        <?php echo htmlspecialchars($item['keyword']); ?>
                        <small style="color:#64748b;">(<?php echo $item['results_count']; ?>)</small>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Footer -->
    <div style="text-align: center; color: #475569; font-size: 12px; margin-top: 40px; padding: 20px 0; border-top: 1px solid #1e293b;">
        <a href="/daboreytextnote/" style="color: #38bdf8; text-decoration: none;">📝 Da Borey Text Note</a>
        &nbsp;|&nbsp;
        <a href="crawl.php" style="color: #64748b; text-decoration: none;">🔄 Crawl URLs</a>
        &nbsp;|&nbsp;
        Da Borey Search v1.0
    </div>
    
</div>

</body>
</html>