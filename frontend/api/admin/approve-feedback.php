<?php
/**
 * POST /api/admin/approve-feedback.php  { id: 1, action: "approve"|"reject" }
 * Admin only.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ne_json(['ok'=>false,'error'=>'method'], 405);

$user = ne_current_user();
if (!$user) ne_json(['ok'=>false,'error'=>'auth'], 401);
if (empty($user['is_admin'])) ne_json(['ok'=>false,'error'=>'forbidden'], 403);

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$id     = (int)($body['id'] ?? 0);
$action = (string)($body['action'] ?? '');
if ($id <= 0 || !in_array($action, ['approve','reject'], true)) ne_json(['ok'=>false,'error'=>'bad_req'], 400);

$db = ne_db();
if (!$db) ne_json(['ok'=>false,'error'=>'db'], 500);

try {
    if ($action === 'approve') {
        $db->prepare("UPDATE feedback SET is_approved = 1, approved_by = :a, approved_at = NOW() WHERE id = :id")
           ->execute([':a'=>$user['id'], ':id'=>$id]);
    } else {
        $db->prepare("DELETE FROM feedback WHERE id = :id")->execute([':id'=>$id]);
    }
    ne_json(['ok'=>true]);
} catch (Throwable $e) {
    error_log('[approve-feedback] ' . $e->getMessage());
    ne_json(['ok'=>false,'error'=>'server'], 500);
}
