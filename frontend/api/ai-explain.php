<?php
/**
 * POST /api/ai-explain.php  { article_id: 123, lang: "<language_code>" }
 * AI explanation in any of 50 world languages, generated once via OpenRouter,
 * cached in the `article_ai_summaries` table.
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
$lang = strtolower(trim((string)($body['lang'] ?? 'en')));
if ($aid <= 0) ne_json(['ok'=>false,'error'=>'bad_id'], 400);

$db = ne_db();
if (!$db) ne_json(['ok'=>false,'error'=>'db'], 500);

global $CONFIG;

// ── 50 World Languages ──────────────────────────────────────────────
$LANGUAGES = [
    'am'    => ['name' => 'Amharic',              'native' => 'አማርኛ',            'script' => 'አ'],
    'ar'    => ['name' => 'Arabic',               'native' => 'العربية',          'script' => 'ع'],
    'bn'    => ['name' => 'Bengali',              'native' => 'বাংলা',            'script' => 'ব'],
    'bg'    => ['name' => 'Bulgarian',            'native' => 'Български',        'script' => 'Б'],
    'my'    => ['name' => 'Burmese',              'native' => 'မြန်မာ',           'script' => 'မ'],
    'zh'    => ['name' => 'Chinese (Simplified)', 'native' => '中文（简体）',      'script' => '简'],
    'zh-tw' => ['name' => 'Chinese (Traditional)','native' => '中文（繁體）',      'script' => '繁'],
    'hr'    => ['name' => 'Croatian',             'native' => 'Hrvatski',         'script' => 'Hr'],
    'cs'    => ['name' => 'Czech',                'native' => 'Čeština',          'script' => 'Čs'],
    'da'    => ['name' => 'Danish',               'native' => 'Dansk',            'script' => 'Da'],
    'nl'    => ['name' => 'Dutch',                'native' => 'Nederlands',       'script' => 'Nl'],
    'en'    => ['name' => 'English',              'native' => 'English',          'script' => 'En'],
    'fil'   => ['name' => 'Filipino',             'native' => 'Filipino',         'script' => 'Fl'],
    'fi'    => ['name' => 'Finnish',              'native' => 'Suomi',            'script' => 'Fi'],
    'fr'    => ['name' => 'French',               'native' => 'Français',         'script' => 'Fr'],
    'de'    => ['name' => 'German',               'native' => 'Deutsch',          'script' => 'De'],
    'el'    => ['name' => 'Greek',                'native' => 'Ελληνικά',         'script' => 'Ε'],
    'gu'    => ['name' => 'Gujarati',             'native' => 'ગુજરાતી',          'script' => 'ગ'],
    'he'    => ['name' => 'Hebrew',               'native' => 'עברית',            'script' => 'א'],
    'hi'    => ['name' => 'Hindi',                'native' => 'हिन्दी',            'script' => 'हि'],
    'hu'    => ['name' => 'Hungarian',            'native' => 'Magyar',           'script' => 'Hu'],
    'id'    => ['name' => 'Indonesian',           'native' => 'Bahasa Indonesia', 'script' => 'Id'],
    'it'    => ['name' => 'Italian',              'native' => 'Italiano',         'script' => 'It'],
    'ja'    => ['name' => 'Japanese',             'native' => '日本語',            'script' => '日'],
    'kn'    => ['name' => 'Kannada',              'native' => 'ಕನ್ನಡ',             'script' => 'ಕ'],
    'ko'    => ['name' => 'Korean',               'native' => '한국어',             'script' => '한'],
    'ms'    => ['name' => 'Malay',                'native' => 'Bahasa Melayu',    'script' => 'Ms'],
    'ml'    => ['name' => 'Malayalam',            'native' => 'മലയാളം',           'script' => 'മ'],
    'mr'    => ['name' => 'Marathi',              'native' => 'मराठी',             'script' => 'म'],
    'ne'    => ['name' => 'Nepali',               'native' => 'नेपाली',            'script' => 'ने'],
    'no'    => ['name' => 'Norwegian',            'native' => 'Norsk',            'script' => 'No'],
    'fa'    => ['name' => 'Persian',              'native' => 'فارسی',            'script' => 'فا'],
    'pl'    => ['name' => 'Polish',               'native' => 'Polski',           'script' => 'Pl'],
    'pt'    => ['name' => 'Portuguese',           'native' => 'Português',        'script' => 'Pt'],
    'pa'    => ['name' => 'Punjabi',              'native' => 'ਪੰਜਾਬੀ',           'script' => 'ਪ'],
    'ro'    => ['name' => 'Romanian',             'native' => 'Română',           'script' => 'Ro'],
    'ru'    => ['name' => 'Russian',              'native' => 'Русский',          'script' => 'Р'],
    'sr'    => ['name' => 'Serbian',              'native' => 'Српски',           'script' => 'С'],
    'si'    => ['name' => 'Sinhala',              'native' => 'සිංහල',            'script' => 'ස'],
    'sk'    => ['name' => 'Slovak',               'native' => 'Slovenčina',       'script' => 'Sk'],
    'es'    => ['name' => 'Spanish',              'native' => 'Español',          'script' => 'Es'],
    'sw'    => ['name' => 'Swahili',              'native' => 'Kiswahili',        'script' => 'Sw'],
    'sv'    => ['name' => 'Swedish',              'native' => 'Svenska',          'script' => 'Sv'],
    'ta'    => ['name' => 'Tamil',                'native' => 'தமிழ்',             'script' => 'த'],
    'te'    => ['name' => 'Telugu',               'native' => 'తెలుగు',            'script' => 'తె'],
    'th'    => ['name' => 'Thai',                 'native' => 'ไทย',              'script' => 'ก'],
    'tr'    => ['name' => 'Turkish',              'native' => 'Türkçe',           'script' => 'Tr'],
    'uk'    => ['name' => 'Ukrainian',            'native' => 'Українська',       'script' => 'У'],
    'ur'    => ['name' => 'Urdu',                 'native' => 'اردو',             'script' => 'ا'],
    'vi'    => ['name' => 'Vietnamese',           'native' => 'Tiếng Việt',       'script' => 'Vi'],
];

// Validate language
if (!isset($LANGUAGES[$lang])) {
    $lang = 'en';
}
$targetLangName = $LANGUAGES[$lang]['name'];

try {
    // ── Fetch article ────────────────────────────────────────────────
    $stmt = $db->prepare(
        "SELECT id, title, summary, content FROM news_articles WHERE id = :a"
    );
    $stmt->execute([':a'=>$aid]);
    $art = $stmt->fetch();
    if (!$art) ne_json(['ok'=>false,'error'=>'not_found'], 404);

    // ── Check cache in article_ai_summaries table ────────────────────
    $cacheStmt = $db->prepare(
        "SELECT summary FROM article_ai_summaries
         WHERE article_id = :a AND language_code = :lang LIMIT 1"
    );
    $cacheStmt->execute([':a' => $aid, ':lang' => $lang]);
    $cached = $cacheStmt->fetch();

    if ($cached && !empty($cached['summary'])) {
        ne_json(['ok'=>true,'cached'=>true,'summary'=>$cached['summary']]);
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

    // ── Cache summary in article_ai_summaries table ──────────────────
    $db->prepare(
        "INSERT INTO article_ai_summaries (article_id, language_code, summary)
         VALUES (:a, :lang, :summary)
         ON DUPLICATE KEY UPDATE summary = VALUES(summary), created_at = CURRENT_TIMESTAMP"
    )->execute([':a' => $aid, ':lang' => $lang, ':summary' => $summary]);

    ne_json(['ok'=>true,'cached'=>false,'summary'=>$summary]);
} catch (Throwable $e) {
    error_log('[ai-explain] ' . $e->getMessage());
    ne_json(['ok'=>false,'error'=>'server'], 500);
}
