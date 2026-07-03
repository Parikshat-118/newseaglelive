<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: /news.php'); exit; }

$db = ne_db();
$a = null;
if ($db) {
    try {
        $stmt = $db->prepare(
            "SELECT id, title, summary, ai_summary, ai_summary_hi, content, image_url, url,
                    category, source_name, published_at, view_count, like_count, share_count, language
             FROM news_articles WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $a = $stmt->fetch();
    } catch (Throwable $e) { error_log('[article] ' . $e->getMessage()); }
}

if (!$a) {
    http_response_code(404);
    $PAGE_TITLE = 'Article not found';
    include __DIR__ . '/includes/header.php';
    echo '<section class="section"><div class="container" style="text-align:center;padding:4rem 0">';
    echo '<h1 class="section-title" style="margin-bottom:1rem">404 — Article not found</h1>';
    echo '<a class="btn btn-primary" href="/news.php">Back to news</a></div></section>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ── VIEW COUNTER (dedupe: 1 view per IP/user per article per 6h) ────
$user = ne_current_user();
try {
    $ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $dupe = $db->prepare(
        "SELECT 1 FROM article_views
         WHERE article_id = :a AND viewed_at >= (NOW() - INTERVAL 6 HOUR)
           AND ((:u IS NOT NULL AND user_id = :u) OR (ip_hash = :ip))
         LIMIT 1"
    );
    $dupe->execute([':a' => $id, ':u' => $user['id'] ?? null, ':ip' => $ipHash]);
    if (!$dupe->fetch()) {
        $db->prepare("INSERT INTO article_views (article_id, user_id, ip_hash) VALUES (:a, :u, :ip)")
           ->execute([':a' => $id, ':u' => $user['id'] ?? null, ':ip' => $ipHash]);
        $db->prepare("UPDATE news_articles SET view_count = view_count + 1 WHERE id = :a")
           ->execute([':a' => $id]);
        $a['view_count']++;
    }
} catch (Throwable $e) { /* non-fatal */ }

// Has this user already liked?
$userLiked = false;
if ($user) {
    try {
        $ls = $db->prepare("SELECT 1 FROM article_likes WHERE article_id = :a AND user_id = :u");
        $ls->execute([':a' => $id, ':u' => $user['id']]);
        $userLiked = (bool)$ls->fetch();
    } catch (Throwable $e) {}
}

$PAGE_TITLE = $a['title'];
$PAGE_DESCRIPTION = mb_strimwidth($a['summary'] ?? $a['title'], 0, 150, '…');
$shareUrl = $CONFIG['brand']['site_url'] . '/article.php?id=' . $id;

include __DIR__ . '/includes/header.php';
?>

<article class="article-page">
  <div class="container article-hero">
    <a class="article-back" href="/news.php">← Back to news</a>

    <?php if (!empty($a['category'])): ?>
      <div><span class="article-cat-tag"><?= h(str_replace('_', ' ', $a['category'])) ?></span></div>
    <?php endif; ?>

    <h1 class="article-h1"><?= h($a['title']) ?></h1>

    <div class="article-byline">
      <span><?= h($a['source_name'] ?? 'News Eagle Live') ?></span>
      <span><?= $a['published_at'] ? date('M j, Y · g:i A', strtotime($a['published_at'])) : '' ?></span>
      <span>👁 <?= number_format((int)$a['view_count']) ?> views</span>
      <span>❤ <span id="like-count-meta"><?= number_format((int)$a['like_count']) ?></span> likes</span>
    </div>

    <?php if (!empty($a['image_url'])): ?>
      <div class="article-hero-img" style="background-image:url('<?= h($a['image_url']) ?>')"></div>
    <?php endif; ?>

    <!-- LIKE + SHARE bar -->
    <div class="article-actions">
      <button class="action-btn<?= $userLiked ? ' liked' : '' ?>" data-like-article="<?= $id ?>" type="button">
        <svg viewBox="0 0 24 24" fill="<?= $userLiked ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
        <span>Like</span> <span data-count><?= (int)$a['like_count'] ?></span>
      </button>
      <div style="display:inline-flex;align-items:center;gap:0.4rem">
        <select id="ai-lang-select" class="action-btn" style="padding:0.35rem 0.5rem;height:auto;border-color:var(--highlight);color:var(--highlight);background:var(--surface);cursor:pointer;font-size:0.85rem;border-radius:8px">
          <option value="en">English</option>
          <option value="hi">हिन्दी (Hindi)</option>
          <option value="bn">বাংলা (Bengali)</option>
          <option value="mr">मराठी (Marathi)</option>
          <option value="ta">தமிழ் (Tamil)</option>
        </select>
        <button class="action-btn" id="ai-explain-btn" data-aid="<?= $id ?>" type="button" style="border-color:var(--highlight);color:var(--highlight)">
          ✨ <span>Explain with AI</span>
        </button>
      </div>
      <button class="action-btn" data-share="whatsapp" data-article-id="<?= $id ?>" data-share-url="<?= h($shareUrl) ?>" data-share-title="<?= h($a['title']) ?>" type="button">
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413"/></svg>
        <span>WhatsApp</span>
      </button>
      <button class="action-btn" data-share="telegram" data-article-id="<?= $id ?>" data-share-url="<?= h($shareUrl) ?>" data-share-title="<?= h($a['title']) ?>" type="button">
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.532.818-1.084.508l-3-2.21-1.446 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.022c.242-.213-.054-.334-.373-.121l-6.871 4.326-2.962-.924c-.643-.204-.657-.643.136-.953l11.566-4.458c.538-.196 1.006.128.832.938z"/></svg>
        <span>Telegram</span>
      </button>
      <button class="action-btn" data-share="copy" data-article-id="<?= $id ?>" data-share-url="<?= h($shareUrl) ?>" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
        <span>Copy link</span>
      </button>
    </div>

    <div class="article-content">
      <?php if (!empty($a['summary'])): ?>
        <?php foreach (preg_split('/\n+/', $a['summary']) as $p): if (trim($p) === '') continue; ?>
          <p><?= h($p) ?></p>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if (!empty($a['url'])): ?>
        <p style="margin-top:2rem">
          <a class="btn btn-ghost" href="<?= h($a['url']) ?>" target="_blank" rel="noopener nofollow">
            Read full story at <?= h($a['source_name'] ?? 'source') ?> ↗
          </a>
        </p>
      <?php endif; ?>
    </div>


    <div id="ai-explain-box" style="display:none;margin-top:1.5rem;padding:1.25rem;background:var(--surface);border:1px solid var(--highlight);border-radius:14px">
      <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.75rem">
        <span class="ai-tag" style="background:var(--highlight);color:#000">✨ AI EXPLANATION</span>
        <span id="ai-explain-lang-label" style="font-size:0.8rem;color:var(--text-soft);margin-left:auto"></span>
      </div>
      <div id="ai-explain-result" style="color:var(--text-soft);white-space:pre-line;line-height:1.7"></div>
    </div>

    <script>
    (function(){
      var btn = document.getElementById('ai-explain-btn');
      var langSelect = document.getElementById('ai-lang-select');
      if (!btn || !langSelect) return;

      var langLabels = {en:'English',hi:'हिन्दी',bn:'বাংলা',mr:'मराठी',ta:'தமிழ்'};

      btn.addEventListener('click', function(){
        btn.disabled = true;
        langSelect.disabled = true;
        btn.querySelector('span').textContent = 'Thinking…';
        var selectedLang = langSelect.value;

        fetch('/api/ai-explain.php', {
          method:'POST', headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ article_id: +btn.getAttribute('data-aid'), lang: selectedLang })
        }).then(function(r){return r.json();}).then(function(d){
          if (d.ok) {
            document.getElementById('ai-explain-result').textContent = d.summary || '';
            document.getElementById('ai-explain-lang-label').textContent = langLabels[selectedLang] || selectedLang;
            document.getElementById('ai-explain-box').style.display = 'block';
            document.getElementById('ai-explain-box').scrollIntoView({behavior:'smooth', block:'nearest'});
            btn.querySelector('span').textContent = 'Explain with AI';
            btn.disabled = false;
            langSelect.disabled = false;
          } else {
            btn.querySelector('span').textContent = 'Try again';
            btn.disabled = false;
            langSelect.disabled = false;
            if (d.error === 'php_curl_missing') alert('Server needs php-curl: ' + (d.hint||''));
            else if (d.detail) console.warn('AI explain failed:', d.error, d.detail);
          }
        }).catch(function(){
          btn.querySelector('span').textContent = 'Try again';
          btn.disabled = false;
          langSelect.disabled = false;
        });
      });
    })();
    </script>

    <?php if (!$user): ?>
      <div style="margin-top:2rem;padding:1.25rem;background:var(--surface);border:1px solid var(--border);border-radius:14px;text-align:center">
        <p style="margin:0 0 .75rem;color:var(--text-soft)">Log in to like articles and leave feedback.</p>
        <a class="btn btn-primary" href="/login.php?next=<?= urlencode('/article.php?id=' . $id) ?>">Log in with Telegram →</a>
      </div>
    <?php endif; ?>

  </div>
</article>

<?php
// ── Related articles (same category, recent) ─────────────────────────
$related = [];
if ($db && !empty($a['category'])) {
    try {
        $rs = $db->prepare(
            "SELECT id, title, image_url, category, published_at, view_count, like_count
             FROM news_articles
             WHERE category = :c AND id != :id
             ORDER BY published_at DESC LIMIT 3"
        );
        $rs->execute([':c' => $a['category'], ':id' => $id]);
        $related = $rs->fetchAll();
    } catch (Throwable $e) {}
}
if ($related): ?>
<section class="section">
  <div class="container">
    <h2 class="section-title" style="font-size:var(--fs-xl);margin-bottom:1.5rem">More in <?= h(str_replace('_', ' ', $a['category'])) ?></h2>
    <div class="article-grid">
      <?php foreach ($related as $r): ?>
        <a class="article-card" href="/article.php?id=<?= (int)$r['id'] ?>">
          <div class="article-img" <?php if (!empty($r['image_url'])): ?>style="background-image:url('<?= h($r['image_url']) ?>')"<?php endif; ?>>
            <?php if (empty($r['image_url'])): ?><div class="article-img-placeholder">News Eagle</div><?php endif; ?>
          </div>
          <div class="article-body">
            <h3 class="article-title"><?= h($r['title']) ?></h3>
            <div class="article-meta">
              <span><?= ne_time_ago($r['published_at']) ?></span>
              <span class="article-stats"><span>👁 <?= (int)$r['view_count'] ?></span><span>❤ <?= (int)$r['like_count'] ?></span></span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
