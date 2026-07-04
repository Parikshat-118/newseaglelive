<?php
/**
 * POST /api/ai-explain.php  { article_id: 123, lang: "en"|"hi"|"bn"|"mr"|"ta"|"te"|"kn"|"gu"|"ml"|"pa" }
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
    'te' => 'ai_summary_te',
    'kn' => 'ai_summary_kn',
    'gu' => 'ai_summary_gu',
    'ml' => 'ai_summary_ml',
    'pa' => 'ai_summary_pa',
];
$langNames = [
    'en' => 'English',
    'hi' => 'Hindi',
    'bn' => 'Bengali',
    'mr' => 'Marathi',
    'ta' => 'Tamil',
    'te' => 'Telugu',
    'kn' => 'Kannada',
    'gu' => 'Gujarati',
    'ml' => 'Malayalam',
    'pa' => 'Punjabi',
];

// Validate language
if (!isset($langCols[$lang])) {
    $lang = 'en';
}
$col = $langCols[$lang];
$targetLangName = $langNames[$lang];

try {
    $stmt = $db->prepare(
        "SELECT id, title, summary, content,
                ai_summary, ai_summary_hi, ai_summary_bn, ai_summary_mr, ai_summary_ta,
                ai_summary_te, ai_summary_kn, ai_summary_gu, ai_summary_ml, ai_summary_pa
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
        "You are an expert news analyst and journalist. Provide a comprehensive, in-depth summary "
        . "and explanation of this news article. Write for an intelligent reader who wants to "
        . "deeply understand the story.\n\n"
        . "Your response MUST be written entirely in {$targetLangName}.\n\n"
        . "Structure your explanation with these sections (use the section headers in {$targetLangName}):\n\n"
        . "1. **Key Facts** — Cover all the essential details: who is involved, what happened, "
        . "when and where it took place. Use bullet points.\n\n"
        . "2. **Background & Context** — Explain the broader situation, history, or events leading "
        . "up to this story. Help the reader understand why this event occurred.\n\n"
        . "3. **Impact & Significance** — Describe who is affected and how. What are the political, "
        . "economic, social, or humanitarian implications?\n\n"
        . "4. **Different Perspectives** — If applicable, briefly mention how different stakeholders "
        . "(government, opposition, experts, public) view this development.\n\n"
        . "5. **What to Watch Next** — What could happen next? What are the future implications "
        . "or upcoming events related to this story?\n\n"
        . "Be detailed and thorough. Aim for at least 300 words of substantive analysis.\n\n"
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
                ['role'=>'system','content'=>'You are a multilingual news analyst. You output only raw JSON. Never use markdown fences. Always respond in the language requested by the user. Provide thorough, detailed analysis.'],
                ['role'=>'user','content'=>$userPrompt],
            ],
            'temperature' => 0.3,
            'max_tokens' => 2500,
        ]);

        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 90,
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
