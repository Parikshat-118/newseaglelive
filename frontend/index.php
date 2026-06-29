<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$PAGE_TITLE = 'AI news for India';
$PAGE_DESCRIPTION = 'AI-curated breaking news for India on Telegram. 20 categories. English + Hindi. Free.';

// Fetch top recent articles for homepage cards
$db = ne_db();
$articles = [];
$count24h = 0;
if ($db) {
    try {
        $articles = $db->query(
            "SELECT id, title, summary, image_url, category, source_name, published_at, view_count, like_count
             FROM news_articles
             WHERE published_at >= (NOW() - INTERVAL 36 HOUR)
             ORDER BY (like_count * 3 + view_count) DESC, published_at DESC
             LIMIT 6"
        )->fetchAll();
        $count24h = (int)$db->query(
            "SELECT COUNT(*) FROM news_articles WHERE fetched_at >= (NOW() - INTERVAL 24 HOUR)"
        )->fetchColumn();
    } catch (Throwable $e) { /* silent */ }
}

include __DIR__ . '/includes/header.php';
?>

<section class="hero">
  <div class="container hero-grid">
    <div>
      <div class="hero-eyebrow">
        <span class="badge-live"><span class="pulse"></span>ON AIR</span>
        <span class="mono">EN · हिन्दी</span>
      </div>
      <h1 class="hero-title">Stay <em>Ahead.</em><br>Stay <em>Informed.</em></h1>
      <p class="hero-deck">AI-curated breaking news for India — delivered on Telegram and the web. Twenty categories. Bilingual. Free. With severe-event alerts powered by AI.</p>
      <div class="hero-cta">
        <a class="btn btn-primary" href="/news.php">
          <span>Browse news</span><span class="cta-arrow">→</span>
        </a>
        <a class="btn btn-ghost" href="<?= h($CONFIG['brand']['bot_url']) ?>" target="_blank" rel="noopener">
          Open @<?= h($CONFIG['brand']['bot']) ?>
        </a>
      </div>
      <div class="hero-stats">
        <div><div class="stat-n mono"><?= $count24h ? number_format($count24h) : '500+' ?></div><div class="stat-l">articles · 24h</div></div>
        <div><div class="stat-n mono">20</div><div class="stat-l">categories</div></div>
        <div><div class="stat-n mono">2</div><div class="stat-l">languages</div></div>
        <div><div class="stat-n mono">AI</div><div class="stat-l">severe alerts</div></div>
      </div>
    </div>
    <div class="hero-emblem" style="position:relative;color:var(--accent);aspect-ratio:1;max-width:420px;width:100%;margin-inline:auto" aria-hidden="true">
      <svg viewBox="0 0 400 400" style="width:100%">
        <defs><radialGradient id="rg" cx="50%" cy="50%" r="50%"><stop offset="0%" stop-color="currentColor" stop-opacity=".18"/><stop offset="100%" stop-color="currentColor" stop-opacity="0"/></radialGradient></defs>
        <circle cx="200" cy="200" r="190" fill="url(#rg)"/>
        <g stroke="currentColor" fill="none" stroke-width=".5" opacity=".4"><circle cx="200" cy="200" r="80"/><circle cx="200" cy="200" r="130"/><circle cx="200" cy="200" r="180"/></g>
        <g stroke="currentColor" stroke-width=".5" opacity=".3"><line x1="200" y1="10" x2="200" y2="390"/><line x1="10" y1="200" x2="390" y2="200"/></g>
        <g style="transform-origin:200px 200px;animation:hover 6s ease-in-out infinite"><path d="M200 70 L290 180 L240 220 L290 310 L200 270 L110 310 L160 220 L110 180 Z" fill="currentColor"/><circle cx="200" cy="160" r="4" fill="var(--bg)"/></g>
        <g style="transform-origin:200px 200px;animation:sweep 6s linear infinite" opacity=".6"><path d="M 200 200 L 380 200 A 180 180 0 0 1 320 327 Z" fill="currentColor" opacity=".12"/></g>
      </svg>
      <style>@keyframes hover{0%,100%{transform:translateY(0) rotate(0deg)}50%{transform:translateY(-6px) rotate(-1.5deg)}}@keyframes sweep{from{transform:rotate(0)}to{transform:rotate(360deg)}}</style>
    </div>
  </div>
</section>

<?php if (!empty($articles)): ?>
<section class="section">
  <div class="container">
    <div class="section-eyebrow"><span class="mono">/01</span><span>Now trending</span></div>
    <h2 class="section-title">What India's reading right now.</h2>
    <div class="article-grid">
      <?php foreach ($articles as $a): ?>
        <a class="article-card" href="/article.php?id=<?= (int)$a['id'] ?>">
          <div class="article-img" <?php if (!empty($a['image_url'])): ?>style="background-image:url('<?= h($a['image_url']) ?>')"<?php endif; ?>>
            <?php if (empty($a['image_url'])): ?>
              <div class="article-img-placeholder">News Eagle</div>
            <?php endif; ?>
            <?php if (!empty($a['category'])): ?>
              <span class="article-cat"><?= h(str_replace('_', ' ', $a['category'])) ?></span>
            <?php endif; ?>
          </div>
          <div class="article-body">
            <h3 class="article-title"><?= h($a['title']) ?></h3>
            <?php if (!empty($a['summary'])): ?>
              <p class="article-summary"><?= h(mb_strimwidth($a['summary'], 0, 140, '…')) ?></p>
            <?php endif; ?>
            <div class="article-meta">
              <span class="article-source"><?= h($a['source_name'] ?? '—') ?> · <?= ne_time_ago($a['published_at']) ?></span>
              <span class="article-stats">
                <span>👁 <?= (int)$a['view_count'] ?></span>
                <span>❤ <?= (int)$a['like_count'] ?></span>
              </span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="section">
  <div class="container">
    <div class="section-eyebrow"><span class="mono">/02</span><span>Coverage</span></div>
    <h2 class="section-title">Twenty categories. One feed.</h2>
    <div class="cat-grid">
      <?php foreach ($CONFIG['categories'] as $i => $c): ?>
        <a class="cat-chip" href="/news.php?cat=<?= h($c['slug']) ?>">
          <span class="cat-emoji"><?= $c['emoji'] ?></span>
          <span class="cat-name"><?= h($c['name']) ?></span>
          <span class="cat-hi"><?= h($c['hi']) ?></span>
          <span class="cat-num"><?= str_pad((string)($i+1), 2, '0', STR_PAD_LEFT) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
