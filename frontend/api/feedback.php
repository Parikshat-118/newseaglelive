<?php
/**
 * POST /api/feedback.php  { message: "...", rating: 1..5 }
 * Requires login. Creates a PENDING feedback (admin must approve).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ne_json(['ok'=>false,'error'=>'method'], 405);

$user = ne_current_user();
if (!$user) ne_json(['ok'=>false,'error'=>'auth'], 401);

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$msg    = trim((string)($body['message'] ?? ''));
$rating = max(1, min(5, (int)($body['rating'] ?? 5)));

if (mb_strlen($msg) < 5)    ne_json(['ok'=>false,'error'=>'too_short'], 400);
if (mb_strlen($msg) > 1000) ne_json(['ok'=>false,'error'=>'too_long'], 400);

$db = ne_db();
if (!$db) ne_json(['ok'=>false,'error'=>'db'], 500);

try {
    // Max 3 pending per user
    $chk = $db->prepare("SELECT COUNT(*) FROM feedback WHERE user_id = :u AND is_approved = 0");
    $chk->execute([':u' => $user['id']]);
    if ((int)$chk->fetchColumn() >= 3) ne_json(['ok'=>false,'error'=>'pending_limit'], 429);

    $name = $user['display_name'] ?: trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'Eagle Reader';
    $db->prepare(
        "INSERT INTO feedback (user_id, display_name, mobile_number, rating, message)
         VALUES (:u, :n, :m, :r, :msg)"
    )->execute([
        ':u'=>$user['id'], ':n'=>mb_substr($name,0,120), ':m'=>$user['mobile_number'],
        ':r'=>$rating, ':msg'=>$msg,
    ]);
    ne_json(['ok'=>true]);
} catch (Throwable $e) {
    error_log('[feedback] ' . $e->getMessage());
    ne_json(['ok'=>false,'error'=>'server'], 500);
}
