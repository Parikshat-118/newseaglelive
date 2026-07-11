<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

function ne_search_boolean_query(string $query): string {
    $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $query) ?? '';
    $parts = preg_split('/\s+/u', trim($normalized)) ?: [];
    $tokens = [];
    foreach ($parts as $part) {
        if (mb_strlen($part) < 2) {
            continue;
        }
        $tokens[] = '+' . $part . '*';
    }
    return $tokens ? implode(' ', $tokens) : trim($query);
}

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) > 100) {
    $q = mb_substr($q, 0, 100);
}

$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;
$like = '%' . $q . '%';
$match = ne_search_boolean_query($q);

$PAGE_TITLE = $q !== '' ? 'Search: ' . $q : 'Archive Search';
$PAGE_DESCRIPTION = 'Search News Eagle Live archives across news, editorials, and quizzes.';

$newsResults = [];
$editorialResults = [];
$upscResults = [];
$mediaResults = [];
$mainsResults = [];
$videoResults = [];
$newsTotal = 0;
$editorialTotal = 0;
$upscTotal = 0;
$mediaTotal = 0;
$mainsTotal = 0;
$videoTotal = 0;

$db = ne_db();
if ($db && $q !== '') {
    try {
        // News
        try {
            $stmt = $db->prepare(
                "SELECT SQL_CALC_FOUND_ROWS id, title, summary, category, published_at, image_url, source_name, view_count, like_count
                 FROM news_articles
                 WHERE (MATCH(title, summary) AGAINST (:match IN BOOLEAN MODE)) OR (search_tags LIKE :like)
                 ORDER BY published_at DESC
                 LIMIT :lim OFFSET :off"
            );
            $stmt->bindValue(':match', $match);
            $stmt->bindValue(':like', $like);
            $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $newsResults = $stmt->fetchAll();
            $newsTotal = (int)$db->query('SELECT FOUND_ROWS()')->fetchColumn();
        } catch (Throwable $e) {
            $stmt = $db->prepare(
                "SELECT id, title, summary, category, published_at, image_url, source_name, view_count, like_count
                 FROM news_articles
                 WHERE title LIKE :like OR summary LIKE :like OR search_tags LIKE :like
                 ORDER BY published_at DESC
                 LIMIT :lim OFFSET :off"
            );
            $stmt->bindValue(':like', $like);
            $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $newsResults = $stmt->fetchAll();
            $countStmt = $db->prepare("SELECT COUNT(*) FROM news_articles WHERE title LIKE :like OR summary LIKE :like OR search_tags LIKE :like");
            $countStmt->execute([':like' => $like]);
            $newsTotal = (int)$countStmt->fetchColumn();
        }

        // Editorials
        try {
            $stmt = $db->prepare(
                "SELECT SQL_CALC_FOUND_ROWS de.id, de.title, de.background, de.editorial_date, de.language
                 FROM daily_editorials de
                 LEFT JOIN editorial_points ep ON ep.editorial_id = de.id
                 WHERE (MATCH(de.title, de.background, de.exam_relevance, de.conclusion) AGAINST (:match IN BOOLEAN MODE))
                    OR ep.content LIKE :like_ep OR de.search_tags LIKE :like_ep
                 GROUP BY de.id, de.title, de.background, de.editorial_date, de.language
                 ORDER BY de.editorial_date DESC, de.id DESC
                 LIMIT :lim OFFSET :off"
            );
            $stmt->bindValue(':match', $match);
            $stmt->bindValue(':like_ep', $like);
            $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $editorialResults = $stmt->fetchAll();
            $editorialTotal = (int)$db->query('SELECT FOUND_ROWS()')->fetchColumn();
        } catch (Throwable $e) {
            $stmt = $db->prepare(
                "SELECT de.id, de.title, de.background, de.editorial_date, de.language
                 FROM daily_editorials de
                 LEFT JOIN editorial_points ep ON ep.editorial_id = de.id
                 WHERE de.title LIKE :like OR de.background LIKE :like OR ep.content LIKE :like OR de.search_tags LIKE :like
                 GROUP BY de.id, de.title, de.background, de.editorial_date, de.language
                 ORDER BY de.editorial_date DESC, de.id DESC
                 LIMIT :lim OFFSET :off"
            );
            $stmt->bindValue(':like', $like);
            $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $editorialResults = $stmt->fetchAll();
            $countStmt = $db->prepare("SELECT COUNT(DISTINCT de.id) FROM daily_editorials de LEFT JOIN editorial_points ep ON ep.editorial_id = de.id WHERE de.title LIKE :like OR de.background LIKE :like OR ep.content LIKE :like OR de.search_tags LIKE :like");
            $countStmt->execute([':like' => $like]);
            $editorialTotal = (int)$countStmt->fetchColumn();
        }

        // UPSC Quizzes
        $stmt = $db->prepare(
            "SELECT id, title, exam_type, quiz_date, language
             FROM daily_quizzes dq
             WHERE exam_type = 'upsc' AND (title LIKE :like OR search_tags LIKE :like)
             ORDER BY quiz_date DESC
             LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue(':like', $like);
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $upscResults = $stmt->fetchAll();
        $countStmt = $db->prepare("SELECT COUNT(*) FROM daily_quizzes WHERE exam_type = 'upsc' AND (title LIKE :like OR search_tags LIKE :like)");
        $countStmt->execute([':like' => $like]);
        $upscTotal = (int)$countStmt->fetchColumn();

        // Media Exams Quizzes
        $stmt = $db->prepare(
            "SELECT id, title, exam_type, quiz_date, language
             FROM daily_quizzes dq
             WHERE exam_type = 'media' AND (title LIKE :like OR search_tags LIKE :like)
             ORDER BY quiz_date DESC
             LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue(':like', $like);
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $mediaResults = $stmt->fetchAll();
        $countStmt = $db->prepare("SELECT COUNT(*) FROM daily_quizzes WHERE exam_type = 'media' AND (title LIKE :like OR search_tags LIKE :like)");
        $countStmt->execute([':like' => $like]);
        $mediaTotal = (int)$countStmt->fetchColumn();

        // Mains Practice
        $stmt = $db->prepare(
            "SELECT id, mains_date, language, question, paper
             FROM daily_mains_questions
             WHERE question LIKE :like OR paper LIKE :like OR search_tags LIKE :like
             ORDER BY mains_date DESC
             LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue(':like', $like);
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $mainsResults = $stmt->fetchAll();
        $countStmt = $db->prepare("SELECT COUNT(*) FROM daily_mains_questions WHERE question LIKE :like OR paper LIKE :like OR search_tags LIKE :like");
        $countStmt->execute([':like' => $like]);
        $mainsTotal = (int)$countStmt->fetchColumn();

        // Videos
        $stmt = $db->prepare(
            "SELECT video_id, title, channel, thumbnail, published_at
             FROM news_videos
             WHERE title LIKE :like OR channel LIKE :like
             ORDER BY published_at DESC
             LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue(':like', $like);
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $videoResults = $stmt->fetchAll();
        $countStmt = $db->prepare("SELECT COUNT(*) FROM news_videos WHERE title LIKE :like OR channel LIKE :like");
        $countStmt->execute([':like' => $like]);
        $videoTotal = (int)$countStmt->fetchColumn();

    } catch (Throwable $e) {
        error_log('[search.php] ' . $e->getMessage());
    }
}

$maxPages = max(1, (int)ceil(max($newsTotal, $editorialTotal, $upscTotal, $mediaTotal, $mainsTotal, $videoTotal) / $perPage));

include __DIR__ . '/includes/header.php';
?>

<section class="section" style="padding-top:2rem">
  <div class="container">
    <div class="section-eyebrow"><span class="mono">/SEARCH</span><span>Archive</span></div>
    <h1 class="section-title" style="margin-bottom:1rem">Search everything in one place.</h1>
    <p style="max-width:60ch;color:var(--text-soft);margin:-.5rem 0 1.5rem">News articles, Student Hub editorials, and archived quizzes are all searchable here.</p>

    <form method="get" action="/search.php" style="margin-bottom:2rem;display:flex;gap:.5rem;flex-wrap:wrap">
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search archive..." maxlength="100" style="flex:1;min-width:220px;padding:12px 14px;border:1px solid var(--border);background:var(--surface);color:var(--text);border-radius:8px">
      <button class="btn btn-primary" type="submit">Search</button>
    </form>

    <?php if ($q === ''): ?>
      <div class="auth-card" style="max-width:760px">
        <p style="margin:0;color:var(--text-soft)">Try searches like <span class="mono">Monetary Policy</span>, <span class="mono">ISRO</span>, <span class="mono">Parliament</span>, or <span class="mono">Climate Change</span>.</p>
      </div>
    <?php else: ?>
      <?php $hasResults = $newsTotal > 0 || $editorialTotal > 0 || $upscTotal > 0 || $mediaTotal > 0 || $mainsTotal > 0 || $videoTotal > 0; ?>

      <?php if (!$hasResults): ?>
        <div class="auth-card" style="max-width:760px; text-align:center; margin:0 auto;">
          <h3 style="margin:0 0 .5rem;font-family:var(--ff-display)">No results found for "<?= h($q) ?>".</h3>
          <p style="margin:0;color:var(--text-soft)">Try searching with different keywords.</p>
        </div>
      <?php else: ?>

      <div style="display:grid;gap:2.5rem">
        <section id="section-news">
          <div class="section-eyebrow"><span class="mono">📰 NEWS</span><span><?= number_format($newsTotal) ?> matches</span></div>
          <?php if (!$newsResults): ?>
            <p style="color:var(--muted)">No matching news articles.</p>
          <?php else: ?>
            <div class="article-grid">
              <?php foreach ($newsResults as $index => $row): ?>
                <a class="article-card item-news" href="/article.php?id=<?= (int)$row['id'] ?>" <?= $index >= 3 ? 'style="display:none;"' : '' ?>>
                  <div class="article-img" <?php if (!empty($row['image_url'])): ?>style="background-image:url('<?= h($row['image_url']) ?>')"<?php endif; ?>>
                    <?php if (empty($row['image_url'])): ?><div class="article-img-placeholder">News Eagle</div><?php endif; ?>
                    <?php if (!empty($row['category'])): ?><span class="article-cat"><?= h(str_replace('_', ' ', (string)$row['category'])) ?></span><?php endif; ?>
                  </div>
                  <div class="article-body">
                    <h3 class="article-title"><?= h($row['title']) ?></h3>
                    <p class="article-summary"><?= h(mb_strimwidth((string)($row['summary'] ?? ''), 0, 140, '...')) ?></p>
                    <div class="article-meta">
                      <span class="article-source"><?= h($row['source_name'] ?? '—') ?> · <?= ne_time_ago($row['published_at']) ?></span>
                    </div>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
            <?php if (count($newsResults) > 3): ?>
              <div style="text-align:center;margin-top:1.5rem">
                <button class="btn btn-ghost" onclick="toggleSection('news', this)">Show More ↓</button>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </section>

        <section id="section-upsc">
          <div class="section-eyebrow"><span class="mono">📝 UPSC/SSC QUIZ</span><span><?= number_format($upscTotal) ?> matches</span></div>
          <?php if (!$upscResults): ?>
            <p style="color:var(--muted)">No matching UPSC/SSC quizzes.</p>
          <?php else: ?>
            <div style="display:grid;gap:1rem">
              <?php foreach ($upscResults as $index => $row): ?>
                <div class="auth-card item-upsc" style="max-width:none; <?= $index >= 3 ? 'display:none;' : '' ?>">
                  <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
                    <div>
                      <div class="mono" style="font-size:var(--fs-xs);color:var(--muted);margin-bottom:.5rem">UPSC/SSC · <?= h(date('d M Y', strtotime((string)$row['quiz_date']))) ?> · <?= strtoupper((string)$row['language']) ?></div>
                      <h3 style="margin:0;font-family:var(--ff-display)" class="<?= ($row['language'] ?? 'en') === 'hi' ? 'deva' : '' ?>"><?= h($row['title']) ?></h3>
                    </div>
                    <a class="btn btn-primary" href="/students.php?tab=quiz&amp;date=<?= urlencode((string)$row['quiz_date']) ?>&amp;lang=<?= urlencode((string)$row['language']) ?>">Practice Now</a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <?php if (count($upscResults) > 3): ?>
              <div style="text-align:center;margin-top:1.5rem">
                <button class="btn btn-ghost" onclick="toggleSection('upsc', this)">Show More ↓</button>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </section>

        <section id="section-media">
          <div class="section-eyebrow"><span class="mono">🎙️ MEDIA EXAMS</span><span><?= number_format($mediaTotal) ?> matches</span></div>
          <?php if (!$mediaResults): ?>
            <p style="color:var(--muted)">No matching Media quizzes.</p>
          <?php else: ?>
            <div style="display:grid;gap:1rem">
              <?php foreach ($mediaResults as $index => $row): ?>
                <div class="auth-card item-media" style="max-width:none; <?= $index >= 3 ? 'display:none;' : '' ?>">
                  <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
                    <div>
                      <div class="mono" style="font-size:var(--fs-xs);color:var(--muted);margin-bottom:.5rem">MEDIA · <?= h(date('d M Y', strtotime((string)$row['quiz_date']))) ?> · <?= strtoupper((string)$row['language']) ?></div>
                      <h3 style="margin:0;font-family:var(--ff-display)" class="<?= ($row['language'] ?? 'en') === 'hi' ? 'deva' : '' ?>"><?= h($row['title']) ?></h3>
                    </div>
                    <a class="btn btn-primary" href="/students.php?tab=media&amp;date=<?= urlencode((string)$row['quiz_date']) ?>&amp;lang=<?= urlencode((string)$row['language']) ?>">Practice Now</a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <?php if (count($mediaResults) > 3): ?>
              <div style="text-align:center;margin-top:1.5rem">
                <button class="btn btn-ghost" onclick="toggleSection('media', this)">Show More ↓</button>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </section>

        <section id="section-mains">
          <div class="section-eyebrow"><span class="mono">✍️ MAINS PRACTICE</span><span><?= number_format($mainsTotal) ?> matches</span></div>
          <?php if (!$mainsResults): ?>
            <p style="color:var(--muted)">No matching Mains questions.</p>
          <?php else: ?>
            <div style="display:grid;gap:1rem">
              <?php foreach ($mainsResults as $index => $row): ?>
                <div class="auth-card item-mains" style="max-width:none; <?= $index >= 3 ? 'display:none;' : '' ?>">
                  <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
                    <div>
                      <div class="mono" style="font-size:var(--fs-xs);color:var(--muted);margin-bottom:.5rem"><?= h($row['paper']) ?> · <?= h(date('d M Y', strtotime((string)$row['mains_date']))) ?> · <?= strtoupper((string)$row['language']) ?></div>
                      <h3 style="margin:0;font-family:var(--ff-display)" class="<?= ($row['language'] ?? 'en') === 'hi' ? 'deva' : '' ?>"><?= h($row['question']) ?></h3>
                    </div>
                    <a class="btn btn-primary" href="/students.php?tab=mains&amp;date=<?= urlencode((string)$row['mains_date']) ?>&amp;lang=<?= urlencode((string)$row['language']) ?>">Practice Now</a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <?php if (count($mainsResults) > 3): ?>
              <div style="text-align:center;margin-top:1.5rem">
                <button class="btn btn-ghost" onclick="toggleSection('mains', this)">Show More ↓</button>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </section>

        <section id="section-editorials">
          <div class="section-eyebrow"><span class="mono">📚 EDITORIALS</span><span><?= number_format($editorialTotal) ?> matches</span></div>
          <?php if (!$editorialResults): ?>
            <p style="color:var(--muted)">No matching editorials.</p>
          <?php else: ?>
            <div style="display:grid;gap:1rem">
              <?php foreach ($editorialResults as $index => $row): ?>
                <div class="auth-card item-editorials" style="max-width:none; <?= $index >= 3 ? 'display:none;' : '' ?>">
                  <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
                    <div>
                      <div class="mono" style="font-size:var(--fs-xs);color:var(--muted);margin-bottom:.5rem"><?= h(date('d M Y', strtotime((string)$row['editorial_date']))) ?> · <?= strtoupper((string)$row['language']) ?></div>
                      <h3 style="margin:0 0 .5rem;font-family:var(--ff-display)" class="<?= ($row['language'] ?? 'en') === 'hi' ? 'deva' : '' ?>"><?= h($row['title']) ?></h3>
                      <p style="margin:0;color:var(--text-soft)" class="<?= ($row['language'] ?? 'en') === 'hi' ? 'deva' : '' ?>"><?= h(mb_strimwidth((string)$row['background'], 0, 220, '...')) ?></p>
                    </div>
                    <a class="btn btn-ghost" href="/students.php?tab=editorial&amp;date=<?= urlencode((string)$row['editorial_date']) ?>&amp;lang=<?= urlencode((string)$row['language']) ?>">Read Editorial</a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <?php if (count($editorialResults) > 3): ?>
              <div style="text-align:center;margin-top:1.5rem">
                <button class="btn btn-ghost" onclick="toggleSection('editorials', this)">Show More ↓</button>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </section>

        <section id="section-videos">
          <div class="section-eyebrow"><span class="mono">🎥 VIDEOS</span><span><?= number_format($videoTotal) ?> matches</span></div>
          <?php if (!$videoResults): ?>
            <p style="color:var(--muted)">No matching videos.</p>
          <?php else: ?>
            <div class="article-grid">
              <?php foreach ($videoResults as $index => $row): ?>
                <div class="article-card item-videos" style="cursor:pointer; <?= $index >= 3 ? 'display:none;' : '' ?>" onclick="window.open('https://www.youtube.com/watch?v=<?= h($row['video_id']) ?>', '_blank')">
                  <div class="article-img" style="background-image:url('<?= h($row['thumbnail']) ?>')"></div>
                  <div class="article-body">
                    <h3 class="article-title"><?= h($row['title']) ?></h3>
                    <div class="article-meta" style="margin-top:1rem">
                      <span class="article-source"><?= h($row['channel']) ?> · <?= ne_time_ago($row['published_at']) ?></span>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <?php if (count($videoResults) > 3): ?>
              <div style="text-align:center;margin-top:1.5rem">
                <button class="btn btn-ghost" onclick="toggleSection('videos', this)">Show More ↓</button>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </section>
      </div>

      <script>
      function toggleSection(type, btn) {
          const items = document.querySelectorAll('.item-' + type);
          let isExpanding = btn.textContent.includes('More');
          
          items.forEach((item, index) => {
              if (index >= 3) {
                  item.style.display = isExpanding ? '' : 'none';
              }
          });
          
          btn.textContent = isExpanding ? 'Show Less ↑' : 'Show More ↓';
      }
      </script>

      <?php if ($maxPages > 1): ?>
        <div style="display:flex;justify-content:center;gap:.5rem;margin-top:2.5rem;flex-wrap:wrap">
          <?php for ($i = max(1, $page - 2); $i <= min($maxPages, $page + 2); $i++): ?>
            <a class="btn <?= $i === $page ? 'btn-primary' : 'btn-ghost' ?>" href="/search.php?<?= http_build_query(['q' => $q, 'p' => $i]) ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
