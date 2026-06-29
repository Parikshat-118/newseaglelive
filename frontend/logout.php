<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$token = ne_session_token();
if ($token) {
    $db = ne_db();
    if ($db) {
        try {
            $db->prepare("DELETE FROM web_sessions WHERE token = :t")->execute([':t' => $token]);
        } catch (Throwable $e) {}
    }
    ne_clear_session_cookie();
}
header('Location: /');
exit;
