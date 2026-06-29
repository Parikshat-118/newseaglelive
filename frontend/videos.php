<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$PAGE_TITLE = 'Trending Videos';
$PAGE_DESCRIPTION = 'Trending news videos from India\'s top news channels.';

$db = ne_db();
$videos = [];
$channels = [];
$pick = $_GET['ch'] ?? '';

if ($db) {
    try {
        $channels = $db->query("SELECT DISTINCT channel FROM news_videos ORDER BY channel")->fetchAll(PDO::FETCH_COLUMN);
        if ($pick && in_array($pick, $channels, true)) {
            $s = $db->prepare("SELECT video_id, title, channel, thumbnail, published_at FROM news_videos
                               WHERE channel = :c ORDER BY published_at DESC LIMIT 24");
            $s->execute([':c' => $pick]);
            $videos = $s->fetchAll();
        } else {
            $pick = '';
            $videos = $db->query("SELECT video_id, title, channel, thumbnail, published_at FROM news_videos
                                  ORDER BY published_at DESC LIMIT 24")->fetchAll();
        }
    } catch (Throwable $e) {}
}

include __DIR__ . '/includes/header.php';
?>

<section class="section" style="padding-top:2rem">
  <div class="container">
    <div class="section-eyebrow"><span class="mono">🎥 LIVE</span><span>Trending Videos</span></div>
    <h2 class="section-title">News in motion.</h2>

    <?php if ($channels): ?>
    <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:2rem">
      <a class="action-btn<?= $pick === '' ? ' liked' : '' ?>" href="/videos.php">All channels</a>
      <?php foreach ($channels as $c): ?>
        <a class="action-btn<?= $pick === $c ? ' liked' : '' ?>" href="/videos.php?ch=<?= urlencode($c) ?>"><?= h($c) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($videos)): ?>
      <p style="text-align:center;color:var(--muted);padding:3rem 0">
        No videos yet — the bot fetches trending news videos every 30 minutes. Check back soon.
      </p>
    <?php else: ?>
      <div class="article-grid">
        <?php foreach ($videos as $v): ?>
          <div class="article-card video-card" data-vid="<?= h($v['video_id']) ?>" style="cursor:pointer">
            <div class="article-img" style="position:relative;<?= $v['thumbnail'] ? "background-image:url('" . h($v['thumbnail']) . "')" : '' ?>">
              <div class="vplay" style="position:absolute;inset:0;display:grid;place-items:center">
                <div style="width:54px;height:54px;border-radius:50%;background:rgba(0,0,0,.65);display:grid;place-items:center;transition:transform .2s">
                  <svg viewBox="0 0 24 24" width="22" height="22" fill="#fff"><path d="M8 5v14l11-7z"/></svg>
                </div>
              </div>
              <span class="article-cat"><?= h($v['channel']) ?></span>
            </div>
            <div class="article-body">
              <h3 class="article-title" style="font-size:var(--fs-base)"><?= h($v['title']) ?></h3>
              <div class="article-meta">
                <span class="article-source"><?= h($v['channel']) ?></span>
                <span><?= ne_time_ago($v['published_at']) ?></span>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- Lightbox player -->
<div id="vbox" style="display:none;position:fixed;inset:0;z-index:100;background:rgba(0,0,0,.85);align-items:center;justify-content:center;padding:1rem" onclick="closeV()">
  <div style="width:min(900px,100%);aspect-ratio:16/9;position:relative" onclick="event.stopPropagation()">
    <iframe id="vframe" style="width:100%;height:100%;border:0;border-radius:12px" allow="autoplay; encrypted-media" allowfullscreen></iframe>
    <button onclick="closeV()" style="position:absolute;top:-40px;right:0;color:#fff;font-size:1.5rem;background:none;border:none;cursor:pointer">✕ Close</button>
  </div>
</div>

<script>
document.querySelectorAll('.video-card').forEach(function(card){
  card.addEventListener('click', function(){
    var vid = card.getAttribute('data-vid');
    document.getElementById('vframe').src = 'https://www.youtube-nocookie.com/embed/' + vid + '?autoplay=1';
    document.getElementById('vbox').style.display = 'flex';
  });
});
function closeV(){
  document.getElementById('vbox').style.display = 'none';
  document.getElementById('vframe').src = '';
}
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeV(); });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
