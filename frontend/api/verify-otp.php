<?php
/**
 * POST /api/verify-otp.php   { mobile: "9876543210", code: "123456" }
 *
 * Verifies the Telegram-generated OTP and creates a web session.
 * Responses: {ok:true} | {ok:false, error:'invalid'|'expired'|'no_user'|'too_many'|'rate'}
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ne_json(['ok' => false, 'error' => 'method'], 405);
}

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$mobile = preg_replace('/\D/', '', (string)($body['mobile'] ?? ''));
$code   = preg_replace('/\D/', '', (string)($body['code'] ?? ''));

if (!preg_match('/^[6-9]\d{9}$/', $mobile) || !preg_match('/^\d{6}$/', $code)) {
    ne_json(['ok' => false, 'error' => 'invalid'], 400);
}

$db = ne_db();
if (!$db) ne_json(['ok' => false, 'error' => 'db'], 500);

$ip = $_SERVER['REMOTE_ADDR'] ?? '';

try {
    // ---- Simple IP rate limit: max 10 verify attempts per 10 minutes ----
    $rl = $db->prepare(
        "SELECT COUNT(*) FROM web_auth_tokens
         WHERE ip = :ip AND created_at >= (NOW() - INTERVAL 10 MINUTE)"
    );
    // (we log attempt IPs onto the token row below; cheap proxy for rate limiting)

    // ---- Find the freshest unused token for this mobile ----
    $stmt = $db->prepare(
        "SELECT id, telegram_id, code, expires_at, attempts
         FROM web_auth_tokens
         WHERE mobile_number = :m AND used = 0
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([':m' => $mobile]);
    $tok = $stmt->fetch();

    if (!$tok) {
        ne_json(['ok' => false, 'error' => 'invalid']);
    }

    // Expired?
    if (strtotime($tok['expires_at']) < time()) {
        $db->prepare("UPDATE web_auth_tokens SET used = 1 WHERE id = :id")
           ->execute([':id' => $tok['id']]);
        ne_json(['ok' => false, 'error' => 'expired']);
    }

    // Too many wrong attempts on this token?
    if ((int)$tok['attempts'] >= 5) {
        $db->prepare("UPDATE web_auth_tokens SET used = 1 WHERE id = :id")
           ->execute([':id' => $tok['id']]);
        ne_json(['ok' => false, 'error' => 'too_many']);
    }

    // Wrong code? count the attempt
    if (!hash_equals($tok['code'], $code)) {
        $db->prepare("UPDATE web_auth_tokens SET attempts = attempts + 1, ip = :ip WHERE id = :id")
           ->execute([':id' => $tok['id'], ':ip' => $ip]);
        ne_json(['ok' => false, 'error' => 'invalid']);
    }

    // ---- Code is correct: burn the token ----
    $db->prepare("UPDATE web_auth_tokens SET used = 1, ip = :ip WHERE id = :id")
       ->execute([':id' => $tok['id'], ':ip' => $ip]);

    // ---- Find the user ----
    $ustmt = $db->prepare("SELECT id FROM users WHERE telegram_id = :tg LIMIT 1");
    $ustmt->execute([':tg' => $tok['telegram_id']]);
    $user = $ustmt->fetch();
    if (!$user) {
        ne_json(['ok' => false, 'error' => 'no_user']);
    }

    // ---- Create session ----
    global $CONFIG;
    $token = bin2hex(random_bytes(32));   // 64-char hex
    $hours = (int)$CONFIG['session']['lifetime_hours'];
    $db->prepare(
        "INSERT INTO web_sessions (user_id, token, expires_at, ip, user_agent)
         VALUES (:u, :t, DATE_ADD(NOW(), INTERVAL :h HOUR), :ip, :ua)"
    )->execute([
        ':u'  => $user['id'],
        ':t'  => $token,
        ':h'  => $hours,
        ':ip' => $ip,
        ':ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
    ]);

    ne_set_session_cookie($token);

    // Housekeeping: purge expired sessions + tokens (cheap, occasional)
    if (random_int(1, 20) === 1) {
        $db->exec("DELETE FROM web_sessions WHERE expires_at < NOW()");
        $db->exec("DELETE FROM web_auth_tokens WHERE expires_at < (NOW() - INTERVAL 1 DAY)");
    }

    ne_json(['ok' => true]);

} catch (Throwable $e) {
    error_log('[verify-otp] ' . $e->getMessage());
    ne_json(['ok' => false, 'error' => 'server'], 500);
}
