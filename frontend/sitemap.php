<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Ensure the response is treated as XML
header("Content-Type: application/xml; charset=utf-8");

// The base URL of your live site
$baseUrl = 'https://newseaglelive.in';

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

// 1. Core Static Pages
$staticPages = [
    '/',
    '/news.php',
    '/alerts.php',
    '/warmeter.php',
    '/videos.php',
    '/students.php',
    '/feedback.php'
];

foreach ($staticPages as $page) {
    echo "\n  <url>";
    echo "\n    <loc>" . htmlspecialchars($baseUrl . $page) . "</loc>";
    echo "\n    <changefreq>daily</changefreq>";
    echo "\n    <priority>0.9</priority>";
    echo "\n  </url>";
}

// 2. Dynamic News Articles (Last 1000 articles)
try {
    $db = ne_db();
    $stmt = $db->query("SELECT id, fetched_at FROM news_articles ORDER BY fetched_at DESC LIMIT 1000");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Format ISO 8601 date
        $date = date('c', strtotime($row['fetched_at']));
        echo "\n  <url>";
        echo "\n    <loc>" . htmlspecialchars($baseUrl . '/article.php?id=' . $row['id']) . "</loc>";
        echo "\n    <lastmod>" . $date . "</lastmod>";
        echo "\n    <changefreq>never</changefreq>";
        echo "\n    <priority>0.6</priority>";
        echo "\n  </url>";
    }
} catch (Exception $e) {
    // Ignore errors to ensure core sitemap still generates
}

echo "\n</urlset>";
