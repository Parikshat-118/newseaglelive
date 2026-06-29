<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$cat = $_GET['cat'] ?? '';
$q   = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 18;
$offset = ($page - 1) * $perPage;

$validSlugs = array_column($CONFIG['categories'], 'slug');
$cat = in_array($cat, $validSlugs, true) ? $cat : '';

$PAGE_TITLE = $cat ? ucfirst(str_replace('_', ' ', $cat)) . ' news' : 'All news';

$articles = [];
$total = 0;
$db = ne_db();
if ($db) {
    try {
        $where = [];
        $params = [];
        if ($cat) { $where[] = 'category = :cat'; $params[':cat'] = $cat; }
        if ($q !== '') {
            $where[] = '(title LIKE :q OR summary LIKE :q)';
            $params[':q'] = "%$q%";
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stmt = $db->prepare(
            "SELECT id, title, summary, image_url, category, source_name, published_at, view_count, like_count
             FROM news_articles $whereSql
             ORDER BY published_at DESC
             LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $articles = $stmt->fetchAll();

        $cstmt = $db->prepare("SELECT COUNT(*) FROM news_articles $whereSql");
        foreach ($params as $k => $v) $cstmt->bindValue($k, $v);
        $cstmt->execute();
        $total = (int)$cstmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('[news.php] ' . $e->getMessage());
    }
}

$totalPages = max(1, (int)ceil($total / $perPage));

include __DIR__ . '/includes/header.php';
?>

<section class="section" style="padding-top:2rem">
  <div class="container">
    <div class="section-eyebrow">
      <span class="mono">News</span>
      <span><?= $cat ? h(ucfirst(str_replace('_', ' ', $cat))) : 'All' ?></span>
    </div>
    <h2 class="section-title" style="margin-bottom:1.5rem">
      <?= $cat ? h(ucfirst(str_replace('_', ' ', $cat))) : 'Latest news' ?>
      <?php if ($q): ?><small style="font-size:.6em;font-weight:400;color:var(--muted)"> · "<?= h($q) ?>"</small><?php endif; ?>
    </h2>

    <!-- Search -->
    <form method="get" action="/news.php" style="margin-bottom:1.5rem;display:flex;gap:.5rem;flex-wrap:wrap">
      <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search headlines…" style="flex:1;min-width:200px;padding:10px 14px;border:1px solid var(--border);background:var(--surface);color:var(--text);border-radius:8px">
      <?php if ($cat): ?><input type="hidden" name="cat" value="<?= h($cat) ?>"><?php endif; ?>
      <button class="btn btn-primary" type="submit">Search</button>
    </form>

    <!-- Category filter chips -->
    <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:2rem">
      <a class="action-btn<?= $cat === '' ? ' liked' : '' ?>" href="/news.php<?= $q ? '?q=' . urlencode($q) : '' ?>">All</a>
      <?php foreach ($CONFIG['categories'] as $c): ?>
        <a class="action-btn<?= $cat === $c['slug'] ? ' liked' : '' ?>"
           href="/news.php?cat=<?= h($c['slug']) ?><?= $q ? '&q=' . urlencode($q) : '' ?>">
          <span style="font-size:1.1em"><?= $c['emoji'] ?></span> <?= h($c['name']) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if (empty($articles)): ?>
      <p style="text-align:center;color:var(--muted);padding:3rem 0">No articles yet. Check back soon — the bot fetches new ones every 5 minutes.</p>
    <?php else: ?>
      <div class="article-grid">
        <?php foreach ($articles as $a): ?>
          <a class="article-card" href="/article.php?id=<?= (int)$a['id'] ?>">
            <div class="article-img" <?php if (!empty($a['image_url'])): ?>style="background-image:url('<?= h($a['image_url']) ?>')"<?php endif; ?>>
              <?php if (empty($a['image_url'])): ?><div class="article-img-placeholder">News Eagle</div><?php endif; ?>
              <?php if (!empty($a['category'])): ?><span class="article-cat"><?= h(str_replace('_', ' ', $a['category'])) ?></span><?php endif; ?>
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

      <?php if ($totalPages > 1): ?>
        <div style="display:flex;justify-content:center;gap:.5rem;margin-top:2.5rem;flex-wrap:wrap">
          <?php
          $base = '/news.php?' . http_build_query(array_filter([
              'cat' => $cat, 'q' => $q,
          ]));
          $sep = strpos($base, '?') !== false ? '&' : '?';
          for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a class="btn <?= $i === $page ? 'btn-primary' : 'btn-ghost' ?>"
               href="<?= $base . $sep ?>p=<?= $i ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
