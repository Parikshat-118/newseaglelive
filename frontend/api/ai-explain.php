<?php
/**
 * POST /api/ai-explain.php  { article_id: 123, lang: "en"|"hi"|"bn"|"mr"|"ta" }
 * AI explanation in the requested language, generated once via OpenRouter, cached in DB.
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
$lang = strtolower((string)($body['lang'] ?? 'en'));
if ($aid <= 0) ne_json(['ok'=>false,'error'=>'bad_id'], 400);

$db = ne_db();
if (!$db) ne_json(['ok'=>false,'error'=>'db'], 500);

global $CONFIG;

// ── Language config ──────────────────────────────────────────────────
$langCols = [
    'en' => 'ai_summary',
    'hi' => 'ai_summary_hi',
    'bn' => 'ai_summary_bn',
    'mr' => 'ai_summary_mr',
    'ta' => 'ai_summary_ta',
];
$langNames = [
    'en' => 'English',
    'hi' => 'Hindi',
    'bn' => 'Bengali',
    'mr' => 'Marathi',
    'ta' => 'Tamil',
];

// Validate language
if (!isset($langCols[$lang])) {
    $lang = 'en';
}
$col = $langCols[$lang];
$targetLangName = $langNames[$lang];

try {
    $stmt = $db->prepare(
        "SELECT id, title, summary, content, ai_summary, ai_summary_hi,
                ai_summary_bn, ai_summary_mr, ai_summary_ta
         FROM news_articles WHERE id = :a"
    );
    $stmt->execute([':a'=>$aid]);
    $art = $stmt->fetch();
    if (!$art) ne_json(['ok'=>false,'error'=>'not_found'], 404);

    // ── Return cached summary if available ───────────────────────────
    if (!empty($art[$col])) {
        ne_json(['ok'=>true,'cached'=>true,'summary'=>$art[$col]]);
    }

    $key = $CONFIG['openrouter']['key'] ?? '';
    if (!$key) ne_json(['ok'=>false,'error'=>'no_key'], 500);

    // ── Build prompt ─────────────────────────────────────────────────
    $newsText = mb_substr(
        ($art['title'] ?? '') . "\n\n" . ($art['content'] ?: $art['summary'] ?: ''),
        0, 6000
    );

    $userPrompt =
        "You are a skilled news analyst. Provide a detailed, expanded summary and explanation of "
        . "this news article for a busy reader.\n\n"
        . "Your response MUST be written entirely in {$targetLangName}.\n\n"
        . "Include the following sections:\n"
        . "1. Key facts (bullet points covering who, what, when, where)\n"
        . "2. Background context (explain the broader situation)\n"
        . "3. Why it matters (impact and significance)\n"
        . "4. What to watch next (future implications)\n\n"
        . "Reply with ONLY valid JSON, no markdown fences, exactly:\n"
        . '{"summary":"<your detailed explanation in ' . $targetLangName . '>"}'
        . "\n\nARTICLE:\n" . $newsText;

    $models = [
        $CONFIG['openrouter']['model'] ?? 'x-ai/grok-4.3',
        'x-ai/grok-4',
    ];

    $summary = ''; $lastErr = '';
    foreach ($models as $model) {
        $payload = json_encode([
            'model' => $model,
            'messages' => [
                ['role'=>'system','content'=>'You output only raw JSON. Never use markdown fences. Always respond in the language requested by the user.'],
                ['role'=>'user','content'=>$userPrompt],
            ],
            'temperature' => 0.3,
            'max_tokens' => 1500,
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
            $summary = trim((string)($parsed['summary'] ?? ''));
        }
        // Last-resort: use raw text as summary
        if ($summary === '' && mb_strlen(trim($content)) > 40) {
            $summary = trim(strip_tags($content));
        }
        if ($summary !== '') break;
        $lastErr = 'parse_failed';
    }

    if ($summary === '') {
        error_log('[ai-explain] all models failed for lang=' . $lang . ': ' . $lastErr);
        ne_json(['ok'=>false,'error'=>'ai_failed','detail'=>$lastErr], 502);
    }

    // ── Cache summary in the correct column ──────────────────────────
    $db->prepare("UPDATE news_articles SET `{$col}` = :summary WHERE id = :a")
       ->execute([':summary'=>$summary, ':a'=>$aid]);

    ne_json(['ok'=>true,'cached'=>false,'summary'=>$summary]);
} catch (Throwable $e) {
    error_log('[ai-explain] ' . $e->getMessage());
    ne_json(['ok'=>false,'error'=>'server'], 500);
}
