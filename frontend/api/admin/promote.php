<?php
/**
 * POST /api/admin/promote.php  { mobile: "9876543210", make_admin: true|false }
 * SUPER ADMIN ONLY (mobile 9540739137) can promote/demote other admins.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ne_json(['ok'=>false,'error'=>'method'], 405);

$user = ne_current_user();
if (!$user) ne_json(['ok'=>false,'error'=>'auth'], 401);

global $CONFIG;
$isSuper = !empty($user['mobile_number']) && $user['mobile_number'] === $CONFIG['super_admin_mobile'];
if (!$isSuper) ne_json(['ok'=>false,'error'=>'super_only'], 403);

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$mobile = preg_replace('/\D/', '', (string)($body['mobile'] ?? ''));
$make   = !empty($body['make_admin']);

if (!preg_match('/^[6-9]\d{9}$/', $mobile)) ne_json(['ok'=>false,'error'=>'bad_mobile'], 400);

// Can't demote the super admin
if ($mobile === $CONFIG['super_admin_mobile'] && !$make) {
    ne_json(['ok'=>false,'error'=>'cannot_demote_super'], 400);
}

$db = ne_db();
if (!$db) ne_json(['ok'=>false,'error'=>'db'], 500);

try {
    $stmt = $db->prepare("UPDATE users SET is_admin = :a WHERE mobile_number = :m");
    $stmt->execute([':a' => $make ? 1 : 0, ':m' => $mobile]);
    if ($stmt->rowCount() === 0) {
        ne_json(['ok'=>false,'error'=>'not_found']);
    }
    ne_json(['ok'=>true, 'mobile'=>$mobile, 'is_admin'=>$make]);
} catch (Throwable $e) {
    error_log('[promote] ' . $e->getMessage());
    ne_json(['ok'=>false,'error'=>'server'], 500);
}
