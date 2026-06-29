<?php
/**
 * POST /api/ai-explain.php  { article_id: 123 }
 * AI summary (EN + HI), generated once via OpenRouter, cached in DB.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ne_json(['ok'=>false,'error'=>'method'], 405);

if (!function_exists('curl_init')) {
    ne_json(['ok'=>false,'error'=>'php_curl_missing',
             'hint'=>'Run on server: sudo apt install -y php-curl && sudo systemctl reload apache2'], 500);
}

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$aid = (int)($body['article_id'] ?? 0);
if ($aid <= 0) ne_json(['ok'=>false,'error'=>'bad_id'], 400);

$db = ne_db();
if (!$db) ne_json(['ok'=>false,'error'=>'db'], 500);

global $CONFIG;

try {
    $stmt = $db->prepare("SELECT id, title, summary, content, ai_summary, ai_summary_hi FROM news_articles WHERE id = :a");
    $stmt->execute([':a'=>$aid]);
    $art = $stmt->fetch();
    if (!$art) ne_json(['ok'=>false,'error'=>'not_found'], 404);

    if (!empty($art['ai_summary'])) {
        ne_json(['ok'=>true,'cached'=>true,'summary_en'=>$art['ai_summary'],'summary_hi'=>$art['ai_summary_hi'] ?? '']);
    }

    $key = $CONFIG['openrouter']['key'] ?? '';
    if (!$key) ne_json(['ok'=>false,'error'=>'no_key'], 500);

    $newsText = mb_substr(($art['title'] ?? '') . "\n\n" . ($art['content'] ?: $art['summary'] ?: ''), 0, 6000);
    $userPrompt =
        "Summarize this Indian news article for a busy reader.\n"
        . "Reply with ONLY valid JSON, no markdown, exactly: "
        . '{"summary_en":"• point 1\n• point 2\n• point 3\n• point 4\n• point 5\n\nWhy it matters: one line",'
        . '"summary_hi":"<same content in Hindi>"}'
        . "\n\nARTICLE:\n" . $newsText;

    $models = [
        $CONFIG['openrouter']['model'] ?? 'x-ai/grok-4.3',
        'x-ai/grok-4',
    ];

    $en = ''; $hi = ''; $lastErr = '';
    foreach ($models as $model) {
        $payload = json_encode([
            'model' => $model,
            'messages' => [
                ['role'=>'system','content'=>'You output only raw JSON. Never use markdown fences.'],
                ['role'=>'user','content'=>$userPrompt],
            ],
            'temperature' => 0.3,
            'max_tokens' => 900,
        ]);

        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 75,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
                'HTTP-Referer: ' . ($CONFIG['brand']['site_url'] ?? 'https://newseagle.live'),
                'X-Title: News Eagle Live',
            ],
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($resp === false) { $lastErr = 'curl: ' . $cerr; continue; }
        if ($http !== 200)   { $lastErr = 'http_' . $http . ': ' . mb_substr((string)$resp, 0, 200); continue; }

        $data = json_decode($resp, true);
        $content = $data['choices'][0]['message']['content'] ?? '';
        if ($content === '') { $lastErr = 'empty_ai_content'; continue; }

        // Strip fences if model added them anyway, then locate the JSON object
        $content = preg_replace('/```(json)?/i', '', $content);
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $content = substr($content, $start, $end - $start + 1);
        }
        $parsed = json_decode(trim($content), true);
        if (is_array($parsed)) {
            $en = trim((string)($parsed['summary_en'] ?? ''));
            $hi = trim((string)($parsed['summary_hi'] ?? ''));
        }
        // Last-resort: use raw text as English summary
        if ($en === '' && mb_strlen(trim($content)) > 40) {
            $en = trim(strip_tags($content));
        }
        if ($en !== '') break;
        $lastErr = 'parse_failed';
    }

    if ($en === '') {
        error_log('[ai-explain] all models failed: ' . $lastErr);
        ne_json(['ok'=>false,'error'=>'ai_failed','detail'=>$lastErr], 502);
    }

    $db->prepare("UPDATE news_articles SET ai_summary = :en, ai_summary_hi = :hi WHERE id = :a")
       ->execute([':en'=>$en, ':hi'=>$hi ?: null, ':a'=>$aid]);

    ne_json(['ok'=>true,'cached'=>false,'summary_en'=>$en,'summary_hi'=>$hi]);
} catch (Throwable $e) {
    error_log('[ai-explain] ' . $e->getMessage());
    ne_json(['ok'=>false,'error'=>'server'], 500);
}
