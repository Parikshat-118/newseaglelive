<?php
/**
 * POST /api/like.php  { article_id: 123 }
 * Toggle like. Requires login.
 * Returns {ok:true, liked:bool, like_count:int} | {ok:false, error:'auth'|...}
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ne_json(['ok'=>false,'error'=>'method'], 405);

$user = ne_current_user();
if (!$user) ne_json(['ok'=>false,'error'=>'auth'], 401);

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$aid  = (int)($body['article_id'] ?? 0);
if ($aid <= 0) ne_json(['ok'=>false,'error'=>'bad_id'], 400);

$db = ne_db();
if (!$db) ne_json(['ok'=>false,'error'=>'db'], 500);

try {
    // Does the article exist?
    $chk = $db->prepare("SELECT id FROM news_articles WHERE id = :a");
    $chk->execute([':a' => $aid]);
    if (!$chk->fetch()) ne_json(['ok'=>false,'error'=>'not_found'], 404);

    // Toggle
    $del = $db->prepare("DELETE FROM article_likes WHERE article_id = :a AND user_id = :u");
    $del->execute([':a' => $aid, ':u' => $user['id']]);

    if ($del->rowCount() > 0) {
        $liked = false;
        $db->prepare("UPDATE news_articles SET like_count = GREATEST(like_count - 1, 0) WHERE id = :a")
           ->execute([':a' => $aid]);
    } else {
        $db->prepare("INSERT INTO article_likes (article_id, user_id) VALUES (:a, :u)")
           ->execute([':a' => $aid, ':u' => $user['id']]);
        $liked = true;
        $db->prepare("UPDATE news_articles SET like_count = like_count + 1 WHERE id = :a")
           ->execute([':a' => $aid]);
    }

    $cnt = $db->prepare("SELECT like_count FROM news_articles WHERE id = :a");
    $cnt->execute([':a' => $aid]);
    ne_json(['ok' => true, 'liked' => $liked, 'like_count' => (int)$cnt->fetchColumn()]);
} catch (Throwable $e) {
    error_log('[like] ' . $e->getMessage());
    ne_json(['ok'=>false,'error'=>'server'], 500);
}
