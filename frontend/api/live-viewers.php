<?php
declare(strict_types=1);

// Prevent all caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once __DIR__ . '/../includes/bootstrap.php';

// Check if request is a known bot
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (preg_match('/bot|crawl|spider|slurp|inspect/i', $userAgent)) {
    ne_json(['success' => false, 'error' => 'bot_ignored'], 403);
}

$db = ne_db();
if (!$db) {
    ne_json(['success' => false, 'error' => 'db_error'], 500);
}

// Ensure unique viewer ID
$viewerId = $_COOKIE['viewer_id'] ?? '';
if (empty($viewerId) || strlen($viewerId) !== 32 || !ctype_xdigit($viewerId)) {
    $viewerId = bin2hex(random_bytes(16));
    // Set cookie for 1 year, path=/
    setcookie('viewer_id', $viewerId, time() + 31536000, '/');
}

try {
    // 1% chance to run garbage collection (clean old rows)
    if (random_int(1, 100) === 1) {
        $db->exec("DELETE FROM site_live_viewers WHERE last_seen < NOW() - INTERVAL 2 DAY");
    }

    // Upsert the viewer's heartbeat globally
    $stmt = $db->prepare("
        INSERT INTO site_live_viewers (viewer_id) 
        VALUES (:vid)
        ON DUPLICATE KEY UPDATE last_seen = CURRENT_TIMESTAMP
    ");
    $stmt->execute([':vid' => $viewerId]);

    // Count active viewers in the last 60 seconds across the entire site
    $countStmt = $db->query("
        SELECT COUNT(*) 
        FROM site_live_viewers 
        WHERE last_seen > NOW() - INTERVAL 60 SECOND
    ");
    $count = (int)$countStmt->fetchColumn();

    ne_json([
        'success' => true,
        'count' => $count,
        'updated' => time()
    ]);

} catch (Throwable $e) {
    error_log("[live-viewers] DB Error: " . $e->getMessage());
    ne_json(['success' => false, 'error' => 'server_error'], 500);
}
