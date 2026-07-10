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

    // 3. Historical UPSC & Media Quizzes
    $stmt = $db->query("SELECT quiz_date, exam_type FROM daily_quizzes ORDER BY quiz_date DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $tab = $row['exam_type'] === 'media' ? 'media' : 'quiz';
        echo "\n  <url>";
        echo "\n    <loc>" . htmlspecialchars($baseUrl . '/students.php?tab=' . $tab . '&date=' . $row['quiz_date']) . "</loc>";
        echo "\n    <changefreq>never</changefreq>";
        echo "\n    <priority>0.7</priority>";
        echo "\n  </url>";
    }

    // 4. Historical Editorials
    $stmt = $db->query("SELECT editorial_date FROM daily_editorials ORDER BY editorial_date DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // editorial_date might be DATETIME, let's just grab the Y-m-d part
        $ed_date = substr($row['editorial_date'], 0, 10);
        echo "\n  <url>";
        echo "\n    <loc>" . htmlspecialchars($baseUrl . '/students.php?tab=editorial&date=' . $ed_date) . "</loc>";
        echo "\n    <changefreq>never</changefreq>";
        echo "\n    <priority>0.7</priority>";
        echo "\n  </url>";
    }

    // 5. Historical Mains Practice
    $stmt = $db->query("SELECT DISTINCT DATE(mains_date) as m_date FROM daily_mains_questions ORDER BY m_date DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "\n  <url>";
        echo "\n    <loc>" . htmlspecialchars($baseUrl . '/students.php?tab=mains&date=' . $row['m_date']) . "</loc>";
        echo "\n    <changefreq>never</changefreq>";
        echo "\n    <priority>0.7</priority>";
        echo "\n  </url>";
    }
} catch (Exception $e) {
    // Ignore errors to ensure core sitemap still generates
}

echo "\n</urlset>";
