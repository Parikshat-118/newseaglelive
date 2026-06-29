<?php
/**
 * Bootstrap — included by every page. Sets up the DB handle, session,
 * current-user helpers, and global utility functions.
 */
declare(strict_types=1);

// --- Load config -----------------------------------------------------
$CONFIG = require __DIR__ . '/config.php';
if (!is_array($CONFIG)) {
    http_response_code(500);
    die('Config missing');
}

// --- Auto-detect site URL if blank ----------------------------------
if (empty($CONFIG['brand']['site_url'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Bare origin only (scheme + host). base_path is '' for root deploy anyway.
    $CONFIG['brand']['site_url'] = rtrim("$scheme://$host", '/');
}

// --- DB --------------------------------------------------------------
function ne_db(): ?PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    global $CONFIG;
    try {
        $cfg = $CONFIG['db'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['host'], (int)$cfg['port'], $cfg['name']);
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
        return $pdo;
    } catch (Throwable $e) {
        error_log('[ne-web] DB connect failed: ' . $e->getMessage());
        return null;
    }
}

// --- HTML escape shortcut -------------------------------------------
function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// --- Session / auth --------------------------------------------------
function ne_session_token(): ?string {
    global $CONFIG;
    $name = $CONFIG['session']['cookie_name'];
    return $_COOKIE[$name] ?? null;
}

function ne_set_session_cookie(string $token): void {
    global $CONFIG;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(
        $CONFIG['session']['cookie_name'],
        $token,
        [
            'expires'  => time() + $CONFIG['session']['lifetime_hours'] * 3600,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ],
    );
}

function ne_clear_session_cookie(): void {
    global $CONFIG;
    setcookie($CONFIG['session']['cookie_name'], '', [
        'expires' => time() - 3600, 'path' => '/', 'httponly' => true,
    ]);
}

function ne_current_user(): ?array {
    static $cache = false;
    if ($cache !== false) return $cache;

    $token = ne_session_token();
    if (!$token) return $cache = null;

    $db = ne_db();
    if (!$db) return $cache = null;
    try {
        $row = $db->prepare(
            "SELECT u.* FROM web_sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.token = :t AND s.expires_at > NOW() LIMIT 1"
        );
        $row->execute([':t' => $token]);
        $u = $row->fetch() ?: null;
        if ($u) {
            // touch last_seen_at (best effort)
            try {
                $db->prepare("UPDATE web_sessions SET last_seen_at = NOW() WHERE token = :t")
                   ->execute([':t' => $token]);
            } catch (Throwable $e) {}
        }
        return $cache = $u;
    } catch (Throwable $e) {
        return $cache = null;
    }
}

function ne_require_login(): array {
    $u = ne_current_user();
    if (!$u) {
        $next = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: /login.php?next=' . urlencode($next));
        exit;
    }
    return $u;
}

function ne_require_admin(): array {
    $u = ne_require_login();
    if (empty($u['is_admin'])) {
        http_response_code(403);
        die('Admins only.');
    }
    return $u;
}

// --- CSRF token ------------------------------------------------------
function ne_csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['_csrf'];
}

function ne_check_csrf(string $token): bool {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    return !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
}

// --- JSON response helper -------------------------------------------
function ne_json($payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// --- Time-ago helper -------------------------------------------------
function ne_time_ago(?string $datetime): string {
    if (!$datetime) return '';
    $ts = strtotime($datetime);
    if (!$ts) return '';
    $diff = time() - $ts;
    if ($diff < 60)         return $diff . 's ago';
    if ($diff < 3600)       return intdiv($diff, 60) . 'm ago';
    if ($diff < 86400)      return intdiv($diff, 3600) . 'h ago';
    if ($diff < 86400 * 7)  return intdiv($diff, 86400) . 'd ago';
    return date('M j', $ts);
}

// --- Active alerts (for site-wide banner) ---------------------------
function ne_active_alert(): ?array {
    $db = ne_db();
    if (!$db) return null;
    try {
        $row = $db->query(
            "SELECT id, kind, severity, headline, summary_en
             FROM severe_alerts
             WHERE is_active = 1
               AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY severity DESC, created_at DESC
             LIMIT 1"
        )->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}


// --- URL helper: prefixes the app's base path -------------------------
function ne_url(string $path = '/'): string {
    global $CONFIG;
    $base = $CONFIG['brand']['base_path'] ?? '';
    if ($path === '' || $path === '/') return $base ?: '/';
    return $base . '/' . ltrim($path, '/');
}
