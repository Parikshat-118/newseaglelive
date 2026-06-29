<?php
/** GET /api/ticker.php → {ok, items:[{id,title,source}]} — latest 15 headlines */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$db = ne_db();
if (!$db) ne_json(['ok'=>false], 500);
try {
    $rows = $db->query(
        "SELECT id, title, source_name FROM news_articles
         ORDER BY published_at DESC LIMIT 15"
    )->fetchAll();
    ne_json(['ok'=>true, 'items'=>array_map(fn($r)=>[
        'id'=>(int)$r['id'], 'title'=>$r['title'], 'source'=>$r['source_name'] ?? ''
    ], $rows)]);
} catch (Throwable $e) {
    ne_json(['ok'=>false], 500);
}
