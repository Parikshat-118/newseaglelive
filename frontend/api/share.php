<?php
/**
 * POST /api/share.php  { article_id: 123, platform: "whatsapp"|"telegram"|"copy" }
 * Records a share (no login needed). Returns {ok:true, share_count:int}
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ne_json(['ok'=>false,'error'=>'method'], 405);

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$aid  = (int)($body['article_id'] ?? 0);
$plat = (string)($body['platform'] ?? 'other');
if (!in_array($plat, ['whatsapp','telegram','copy','x','facebook','other'], true)) $plat = 'other';
if ($aid <= 0) ne_json(['ok'=>false,'error'=>'bad_id'], 400);

$db = ne_db();
if (!$db) ne_json(['ok'=>false,'error'=>'db'], 500);

$user = ne_current_user();

try {
    $db->prepare("INSERT INTO article_shares (article_id, user_id, platform) VALUES (:a, :u, :p)")
       ->execute([':a'=>$aid, ':u'=>$user['id'] ?? null, ':p'=>$plat]);
    $db->prepare("UPDATE news_articles SET share_count = share_count + 1 WHERE id = :a")
       ->execute([':a'=>$aid]);
    $cnt = $db->prepare("SELECT share_count FROM news_articles WHERE id = :a");
    $cnt->execute([':a'=>$aid]);
    ne_json(['ok'=>true, 'share_count'=>(int)$cnt->fetchColumn()]);
} catch (Throwable $e) {
    error_log('[share] ' . $e->getMessage());
    ne_json(['ok'=>false,'error'=>'server'], 500);
}
