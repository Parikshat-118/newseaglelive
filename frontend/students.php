<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$PAGE_TITLE       = 'Student Hub — UPSC, SSC & Media Exam Prep';
$PAGE_DESCRIPTION = 'Daily UPSC, SSC & media-exam prep from today\'s news: MCQs, mains questions, editorial analysis, and leaderboard.';

$user = ne_current_user();
$db   = ne_db();

$tab = $_GET['tab'] ?? 'quiz';
if (!in_array($tab, ['quiz', 'media', 'mains', 'editorial', 'leaderboard'], true)) {
    $tab = 'quiz';
}

// ── Load content for the active tab ──────────────────────────────
$quiz        = null;
$questions   = [];
$myAttempt   = null;
$editorial   = null;
$ed_points   = ['key_point' => [], 'arg_for' => [], 'arg_against' => []];
$mains       = [];
$leaders     = [];
$myRank      = null;
$genStatus   = null;

try {
    $today = date('Y-m-d');

    if ($tab === 'quiz' || $tab === 'media') {
        $exam = $tab === 'media' ? 'media' : 'upsc';

        $s = $db->prepare(
            "SELECT q.id, q.title, q.quiz_date, g.status, g.model, g.provider
             FROM daily_quizzes q
             JOIN ai_generations g ON g.id = q.generation_id
             WHERE q.exam_type = :e
             ORDER BY q.quiz_date DESC LIMIT 1"
        );
        $s->execute([':e' => $exam]);
        $quiz = $s->fetch();

        if ($quiz) {
            $qs = $db->prepare(
                "SELECT id, question, option_a, option_b, option_c, option_d,
                        correct_option, explanation, topic, difficulty, order_no
                 FROM quiz_questions WHERE quiz_id = :qi ORDER BY order_no"
            );
            $qs->execute([':qi' => $quiz['id']]);
            $questions = $qs->fetchAll();

            if ($user) {
                $a = $db->prepare(
                    "SELECT score, total, accuracy, time_taken_sec
                     FROM web_quiz_attempts WHERE user_id = :u AND quiz_id = :q"
                );
                $a->execute([':u' => $user['id'], ':q' => $quiz['id']]);
                $myAttempt = $a->fetch() ?: null;
            }

            // Source articles
            $src = $db->prepare(
                "SELECT a.id, a.title, a.url FROM news_articles a
                 JOIN quiz_source_articles qsa ON qsa.article_id = a.id
                 WHERE qsa.quiz_id = :qi LIMIT 5"
            );
            $src->execute([':qi' => $quiz['id']]);
            $sourceArticles = $src->fetchAll();
        } else {
            // Check generation status
            $gs = $db->prepare(
                "SELECT status, error_msg FROM ai_generations
                 WHERE content_type = :t AND content_date = :d LIMIT 1"
            );
            $gs->execute([':t' => ($exam === 'media' ? 'media_quiz' : 'upsc_quiz'), ':d' => $today]);
            $genStatus = $gs->fetch() ?: null;
        }

    } elseif ($tab === 'mains') {
        $mq = $db->prepare(
            "SELECT m.id, m.question, m.paper, m.hint, m.order_no
             FROM daily_mains_questions m
             WHERE DATE(m.mains_date) = :d
             ORDER BY m.order_no"
        );
        $mq->execute([':d' => $today]);
        $mains = $mq->fetchAll();

        if ($mains) {
            foreach ($mains as &$mq_row) {
                $pts = $db->prepare(
                    "SELECT content FROM mains_answer_points
                     WHERE mains_question_id = :mid ORDER BY order_no"
                );
                $pts->execute([':mid' => $mq_row['id']]);
                $mq_row['points'] = $pts->fetchAll(PDO::FETCH_COLUMN);
            }
            unset($mq_row);
        } else {
            $gs = $db->prepare(
                "SELECT status FROM ai_generations WHERE content_type='mains' AND content_date=:d LIMIT 1"
            );
            $gs->execute([':d' => $today]);
            $genStatus = $gs->fetch() ?: null;
        }

    } elseif ($tab === 'editorial') {
        $ed = $db->prepare(
            "SELECT id, title, background, exam_relevance, conclusion
             FROM daily_editorials
             WHERE DATE(editorial_date) = :d LIMIT 1"
        );
        $ed->execute([':d' => $today]);
        $editorial = $ed->fetch();

        if ($editorial) {
            $epts = $db->prepare(
                "SELECT point_type, content FROM editorial_points
                 WHERE editorial_id = :eid ORDER BY point_type, order_no"
            );
            $epts->execute([':eid' => $editorial['id']]);
            foreach ($epts->fetchAll() as $pt) {
                $ed_points[$pt['point_type']][] = $pt['content'];
            }

            $src = $db->prepare(
                "SELECT a.id, a.title, a.url FROM news_articles a
                 JOIN editorial_source_articles esa ON esa.article_id = a.id
                 WHERE esa.editorial_id = :eid LIMIT 5"
            );
            $src->execute([':eid' => $editorial['id']]);
            $sourceArticles = $src->fetchAll();
        } else {
            $gs = $db->prepare(
                "SELECT status FROM ai_generations WHERE content_type='editorial' AND content_date=:d LIMIT 1"
            );
            $gs->execute([':d' => $today]);
            $genStatus = $gs->fetch() ?: null;
        }

    } elseif ($tab === 'leaderboard') {
        $leaders = $db->query(
            "SELECT u.display_name, u.first_name,
                    SUM(a.score)                        AS pts,
                    COUNT(*)                            AS quizzes,
                    ROUND(AVG(a.accuracy),1)            AS avg_accuracy,
                    ROUND(AVG(a.time_taken_sec)/60,1)   AS avg_min
             FROM web_quiz_attempts a
             JOIN users u ON u.id = a.user_id
             WHERE a.taken_at >= (NOW() - INTERVAL 7 DAY)
             GROUP BY a.user_id
             ORDER BY pts DESC, avg_accuracy DESC
             LIMIT 20"
        )->fetchAll();

        if ($user) {
            $rk = $db->prepare(
                "SELECT COUNT(*)+1 AS rank FROM (
                     SELECT user_id, SUM(score) AS pts
                     FROM web_quiz_attempts
                     WHERE taken_at >= (NOW() - INTERVAL 7 DAY)
                     GROUP BY user_id
                 ) t WHERE pts > (
                     SELECT COALESCE(SUM(score),0) FROM web_quiz_attempts
                     WHERE user_id=:u AND taken_at >= (NOW() - INTERVAL 7 DAY)
                 )"
            );
            $rk->execute([':u' => $user['id']]);
            $myRank = $rk->fetchColumn();
        }
    }
} catch (Throwable $e) {
    error_log('[students] ' . $e->getMessage());
}

include __DIR__ . '/includes/header.php';
?>

<section class="section" style="padding-top:2rem">
  <div class="container">

    <div class="section-eyebrow"><span class="mono">🎓 Daily</span><span>Student Hub</span></div>
    <h1 class="section-title">Today's news. Tomorrow's rank.</h1>
    <p style="max-width:62ch;color:var(--text-soft);margin:-1.5rem 0 2rem">
      Fresh every morning at 6:30 — AI turns today's news into UPSC/SSC MCQs, Mains practice,
      editorial analysis, and a dedicated section for media-exam aspirants (IIMC, YMCA, JMI &amp; more).
    </p>

    <div class="admin-tabs">
      <a id="tab-quiz"        class="admin-tab<?= $tab==='quiz'        ? ' active' : '' ?>" href="?tab=quiz">📝 UPSC/SSC Quiz</a>
      <a id="tab-media"       class="admin-tab<?= $tab==='media'       ? ' active' : '' ?>" href="?tab=media">📺 Media Exams</a>
      <a id="tab-mains"       class="admin-tab<?= $tab==='mains'       ? ' active' : '' ?>" href="?tab=mains">✍️ Mains Practice</a>
      <a id="tab-editorial"   class="admin-tab<?= $tab==='editorial'   ? ' active' : '' ?>" href="?tab=editorial">📰 Editorial</a>
      <a id="tab-leaderboard" class="admin-tab<?= $tab==='leaderboard' ? ' active' : '' ?>" href="?tab=leaderboard">🏆 Leaderboard</a>
    </div>

    <?php /* ── QUIZ TAB ── */ if ($tab === 'quiz' || $tab === 'media'): ?>

      <?php if (!$quiz): ?>
        <div style="padding:2.5rem 0">
          <?php if ($genStatus && $genStatus['status'] === 'RUNNING'): ?>
            <p style="color:var(--highlight)">⏳ Today's quiz is being generated right now... refresh in a moment.</p>
          <?php elseif ($genStatus && $genStatus['status'] === 'FAILED'): ?>
            <p style="color:var(--accent)">⚠️ Today's quiz generation failed. It will be retried automatically. Check back soon.</p>
          <?php else: ?>
            <p style="color:var(--muted)">Today's quiz is being prepared — generated daily at 6:30 AM. Check back soon.</p>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <h3 style="font-family:var(--ff-display);margin:0 0 .25rem"><?= h($quiz['title']) ?></h3>
        <p class="mono" style="color:var(--muted);font-size:var(--fs-xs);margin:0 0 .5rem">
          <?= count($questions) ?> questions · attempt once · counts for leaderboard
        </p>
        <p class="mono" style="color:var(--muted);font-size:var(--fs-xs);margin:0 0 1.5rem">
          Generated by <?= h($quiz['model'] ?? $quiz['provider'] ?? 'AI') ?>
        </p>

        <?php if (!$user): ?>
          <!-- GUEST VIEW -->
          <div id="quiz" data-quiz-id="<?= (int)$quiz['id'] ?>" data-done="0" data-auth="0">
            <?php foreach ($questions as $i => $q): ?>
              <?php if ($i > 1) continue; // Only render first 2 for guests ?>
              <div class="auth-card quiz-q" style="max-width:760px;margin-bottom:1rem;position:relative;<?= $i === 1 ? 'filter:blur(5px);pointer-events:none;user-select:none;' : '' ?>" data-qi="<?= $i ?>">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
                  <span class="mono" style="color:var(--accent);font-size:var(--fs-xs)">Q<?= $i+1 ?></span>
                  <div style="display:flex;gap:.4rem">
                    <span class="badge badge-off"><?= h($q['topic']) ?></span>
                    <span class="badge badge-off" style="text-transform:capitalize"><?= h($q['difficulty']) ?></span>
                  </div>
                </div>
                <p style="margin:0 0 1rem;font-weight:600"><?= h($q['question']) ?></p>
                <?php foreach (['a','b','c','d'] as $opt): ?>
                  <button class="action-btn opt" data-opt="<?= strtoupper($opt) ?>" type="button" style="display:flex;width:100%;text-align:left;margin-bottom:.5rem;justify-content:flex-start">
                    <span class="mono" style="color:var(--muted);min-width:1.6rem"><?= strtoupper($opt) ?>.</span>
                    <?= h($q['option_'.$opt]) ?>
                  </button>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
            <div style="text-align:center;margin-top:-6rem;position:relative;z-index:10;background:linear-gradient(to top, var(--bg) 60%, transparent);padding-top:6rem;padding-bottom:2rem">
              <h3 style="font-family:var(--ff-display);margin:0 0 .5rem">Unlock the full test</h3>
              <p style="color:var(--text-soft);margin:0 0 1.5rem">Log in to unlock all <?= count($questions) ?> questions, start the timer, and climb the leaderboard.</p>
              <a class="btn btn-primary" style="display:inline-block;padding:1rem 2rem;font-size:1.1rem" href="/login.php?next=<?= urlencode('/students.php?tab='.$tab) ?>">Log in with Telegram →</a>
            </div>
          </div>

        <?php elseif ($myAttempt): ?>
          <!-- COMPLETED VIEW -->
          <div class="flash flash-ok">
            ✓ You scored <strong><?= (int)$myAttempt['score'] ?>/<?= (int)$myAttempt['total'] ?></strong>
            (accuracy <?= number_format((float)$myAttempt['accuracy'], 1) ?>%
            <?php if ($myAttempt['time_taken_sec']): ?>
              · completed in <?= gmdate('i:s', (int)$myAttempt['time_taken_sec']) ?>
            <?php endif; ?>)
            — New quiz drops tomorrow 6:30 AM. Answers revealed below.
          </div>
          <div id="quiz" data-quiz-id="<?= (int)$quiz['id'] ?>" data-done="1" data-auth="1">
            <?php foreach ($questions as $i => $q): ?>
              <div class="auth-card quiz-q" style="max-width:760px;margin-bottom:1rem" data-qi="<?= $i ?>">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
                  <span class="mono" style="color:var(--accent);font-size:var(--fs-xs)">Q<?= $i+1 ?></span>
                  <div style="display:flex;gap:.4rem">
                    <span class="badge badge-off"><?= h($q['topic']) ?></span>
                    <span class="badge badge-off" style="text-transform:capitalize"><?= h($q['difficulty']) ?></span>
                  </div>
                </div>
                <p style="margin:0 0 1rem;font-weight:600"><?= h($q['question']) ?></p>
                <?php foreach (['a','b','c','d'] as $opt): ?>
                  <button class="action-btn opt" data-opt="<?= strtoupper($opt) ?>" type="button" style="display:flex;width:100%;text-align:left;margin-bottom:.5rem;justify-content:flex-start" disabled>
                    <span class="mono" style="color:var(--muted);min-width:1.6rem"><?= strtoupper($opt) ?>.</span>
                    <?= h($q['option_'.$opt]) ?>
                  </button>
                <?php endforeach; ?>
                <div style="margin-top:.75rem;padding:.75rem 1rem;background:var(--surface2);border-radius:8px;font-size:var(--fs-sm);color:var(--text-soft)">
                  ✅ Correct: <strong><?= h($q['correct_option']) ?></strong> &mdash; <?= h($q['option_'.strtolower($q['correct_option'])]) ?><br>
                  💡 <?= h($q['explanation']) ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

        <?php else: ?>
          <!-- ASPIRANT TAKING TEST VIEW -->
          <div id="quiz-landing" class="auth-card" style="max-width:500px;text-align:center;padding:3rem 2rem;margin:2rem auto">
            <h2 style="font-family:var(--ff-display);margin:0 0 .5rem">Ready to begin?</h2>
            <p style="color:var(--text-soft);margin:0 0 2rem">This is a strict <?= count($questions) ?>-question test. Once you click Start, the timer will begin. Your final time and score will be permanently recorded on the leaderboard.</p>
            <button class="btn btn-primary" id="start-test-btn" type="button" style="padding:1rem 3rem;font-size:1.2rem">Start Test</button>
          </div>

          <div id="quiz-timer" style="display:none;position:sticky;top:60px;z-index:100;background:var(--surface);border-bottom:1px solid var(--border);padding:.75rem;text-align:center;font-weight:700;font-size:1.2rem;color:var(--highlight);margin:0 -1rem 1rem">
            ⏱️ <span id="timer-display">00:00</span>
          </div>

          <div id="quiz" data-quiz-id="<?= (int)$quiz['id'] ?>" data-done="0" data-auth="1" style="display:none">
            <?php foreach ($questions as $i => $q): ?>
              <div class="auth-card quiz-q" style="max-width:760px;margin-bottom:1rem" data-qi="<?= $i ?>">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
                  <span class="mono" style="color:var(--accent);font-size:var(--fs-xs)">Q<?= $i+1 ?></span>
                  <div style="display:flex;gap:.4rem">
                    <span class="badge badge-off"><?= h($q['topic']) ?></span>
                    <span class="badge badge-off" style="text-transform:capitalize"><?= h($q['difficulty']) ?></span>
                  </div>
                </div>
                <p style="margin:0 0 1rem;font-weight:600"><?= h($q['question']) ?></p>
                <?php foreach (['a','b','c','d'] as $opt): ?>
                  <button class="action-btn opt" data-opt="<?= strtoupper($opt) ?>" type="button" style="display:flex;width:100%;text-align:left;margin-bottom:.5rem;justify-content:flex-start">
                    <span class="mono" style="color:var(--muted);min-width:1.6rem"><?= strtoupper($opt) ?>.</span>
                    <?= h($q['option_'.$opt]) ?>
                  </button>
                <?php endforeach; ?>
                <div class="explain" style="display:none;margin-top:.75rem;padding:.75rem 1rem;background:var(--surface2);border-radius:8px;font-size:var(--fs-sm);color:var(--text-soft)"></div>
              </div>
            <?php endforeach; ?>

            <button class="btn btn-primary" id="submit-quiz" type="button" style="margin-top:1rem;padding:1rem 2rem;font-size:1.1rem;width:100%;max-width:760px">Submit Test</button>
            <div id="quiz-result" class="flash flash-ok" style="display:none;margin-top:1rem"></div>
          </div>
        <?php endif; ?>

        <?php if (!empty($sourceArticles)): ?>
          <div style="margin-top:2rem">
            <h4 style="font-size:var(--fs-sm);color:var(--muted);margin:0 0 .75rem">📰 Source Articles</h4>
            <?php foreach ($sourceArticles as $sa): ?>
              <a href="/article.php?id=<?= (int)$sa['id'] ?>" style="display:block;font-size:var(--fs-sm);color:var(--accent);margin-bottom:.4rem;text-decoration:none">
                → <?= h($sa['title']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <script>
      (function(){
        var box   = document.getElementById('quiz');
        if (!box) return;
        var done  = box.getAttribute('data-done') === '1';
        var auth  = box.getAttribute('data-auth') === '1';
        var picks = {};
        
        var timerInterval = null;
        var startTime     = 0;
        var timerDisplay  = document.getElementById('timer-display');
        var timerBar      = document.getElementById('quiz-timer');
        var startBtn      = document.getElementById('start-test-btn');
        var landing       = document.getElementById('quiz-landing');

        function formatTime(sec) {
          var m = Math.floor(sec / 60);
          var s = sec % 60;
          return (m < 10 ? '0'+m : m) + ':' + (s < 10 ? '0'+s : s);
        }

        if (startBtn) {
          startBtn.addEventListener('click', function(){
            landing.style.display = 'none';
            box.style.display = 'block';
            timerBar.style.display = 'block';
            startTime = Date.now();
            timerInterval = setInterval(function(){
              var elapsed = Math.floor((Date.now() - startTime) / 1000);
              timerDisplay.textContent = formatTime(elapsed);
            }, 1000);
          });
        }

        document.querySelectorAll('.quiz-q').forEach(function(qel){
          var qi = +qel.getAttribute('data-qi');
          qel.querySelectorAll('.opt').forEach(function(btn){
            btn.addEventListener('click', function(){
              if (done || !auth) return;
              qel.querySelectorAll('.opt').forEach(function(b){ b.classList.remove('liked'); });
              btn.classList.add('liked');
              picks[qi] = btn.getAttribute('data-opt');
            });
          });
        });

        var submit = document.getElementById('submit-quiz');
        if (submit) submit.addEventListener('click', function(){
          if (timerInterval) clearInterval(timerInterval);
          var total   = document.querySelectorAll('.quiz-q').length;
          var answers = [];
          for (var i = 0; i < total; i++) answers.push(picks[i] || null);
          var timeTaken = Math.floor((Date.now() - startTime) / 1000);
          
          submit.disabled = true; submit.textContent = 'Scoring…';
          fetch('/api/quiz-attempt.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
              quiz_id:        +box.getAttribute('data-quiz-id'),
              answers:        answers,
              time_taken_sec: timeTaken
            })
          }).then(r=>r.json()).then(function(d){
            if (!d.ok){ alert('Failed: '+(d.error||'')); submit.disabled=false; submit.textContent='Submit Test'; return; }
            done = true;
            if (timerBar) timerBar.style.display = 'none';
            var res = document.getElementById('quiz-result');
            res.innerHTML = '🎯 Score: <strong>'+d.score+'/'+d.total+'</strong> ('+d.accuracy+'%) in '+formatTime(timeTaken)+' — Answers revealed below.';
            res.style.display = 'block';
            submit.style.display = 'none';
            document.querySelectorAll('.quiz-q').forEach(function(qel, i){
              var r = d.results[i]; if (!r) return;
              qel.querySelectorAll('.opt').forEach(function(b){
                var opt = b.getAttribute('data-opt');
                if (opt === r.answer){ b.style.borderColor='#00D4AA'; b.style.background='color-mix(in srgb,#00D4AA 15%,transparent)'; }
                else if (picks[i] === opt && opt !== r.answer){ b.style.borderColor='var(--accent)'; }
              });
              var ex = qel.querySelector('.explain');
              if (ex && r.explanation){ ex.innerHTML = '✅ Correct: <strong>'+r.answer+'</strong> — 💡 '+r.explanation; ex.style.display='block'; }
            });
            window.scrollTo({top: res.offsetTop - 100, behavior:'smooth'});
          });
        });
      })();
      </script>

    <?php /* ── MAINS TAB ── */ elseif ($tab === 'mains'): ?>
      <?php if (!$mains): ?>
        <p style="color:var(--muted);padding:2rem 0">
          <?= $genStatus && $genStatus['status']==='FAILED'
              ? '⚠️ Generation failed. Will retry automatically.'
              : 'Today\'s Mains questions are being prepared. Generated daily at 6:30 AM.' ?>
        </p>
      <?php else: ?>
        <h3 style="font-family:var(--ff-display);margin:0 0 1.5rem">Mains Practice — <?= date('d M Y') ?></h3>
        <?php foreach ($mains as $i => $mq): ?>
          <div class="auth-card" style="max-width:760px;margin-bottom:1.25rem">
            <div style="display:flex;gap:.5rem;margin-bottom:.75rem">
              <span class="badge badge-admin"><?= h($mq['paper']) ?></span>
              <span class="badge badge-off">150–250 words</span>
            </div>
            <p style="font-weight:600;font-size:var(--fs-lg);margin:0 0 .75rem">Q<?= $i+1 ?>. <?= h($mq['question']) ?></p>
            <?php if ($mq['hint']): ?>
              <p style="color:var(--muted);font-size:var(--fs-sm);margin:0 0 1rem">💭 Approach: <?= h($mq['hint']) ?></p>
            <?php endif; ?>
            <details>
              <summary style="cursor:pointer;color:var(--accent);font-weight:600">Reveal model answer points</summary>
              <ul style="margin:.75rem 0 0;color:var(--text-soft)">
                <?php foreach ($mq['points'] as $pt): ?>
                  <li style="margin-bottom:.4rem"><?= h($pt) ?></li>
                <?php endforeach; ?>
              </ul>
            </details>
          </div>
        <?php endforeach; ?>
        <p style="color:var(--muted);font-size:var(--fs-sm)">✍️ Tip: write your answer on paper first, THEN reveal the points. Compare honestly.</p>
      <?php endif; ?>

    <?php /* ── EDITORIAL TAB ── */ elseif ($tab === 'editorial'): ?>
      <?php if (!$editorial): ?>
        <p style="color:var(--muted);padding:2rem 0">
          <?= $genStatus && $genStatus['status']==='FAILED'
              ? '⚠️ Generation failed. Will retry automatically.'
              : 'Today\'s editorial analysis is being prepared. Generated daily at 6:30 AM.' ?>
        </p>
      <?php else: ?>
        <div class="auth-card" style="max-width:780px">
          <span class="badge badge-admin">EDITORIAL ANALYSIS</span>
          <h3 style="font-family:var(--ff-display);font-size:var(--fs-xl);margin:.75rem 0 1rem"><?= h($editorial['title']) ?></h3>

          <h4 style="margin:1.25rem 0 .5rem;color:var(--accent)">Background</h4>
          <p style="color:var(--text-soft);margin:0"><?= h($editorial['background']) ?></p>

          <?php if ($ed_points['key_point']): ?>
            <h4 style="margin:1.25rem 0 .5rem;color:var(--accent)">Key Points</h4>
            <ul style="color:var(--text-soft);margin:0">
              <?php foreach ($ed_points['key_point'] as $p): ?>
                <li style="margin-bottom:.35rem"><?= h($p) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:1.25rem" class="ed-grid">
            <style>@media(max-width:640px){.ed-grid{grid-template-columns:1fr !important}}</style>
            <?php if ($ed_points['arg_for']): ?>
              <div style="background:color-mix(in srgb,#00D4AA 8%,transparent);border:1px solid #00D4AA;border-radius:8px;padding:1rem">
                <h4 style="margin:0 0 .5rem;color:#00D4AA">✓ Arguments For</h4>
                <ul style="margin:0;color:var(--text-soft);font-size:var(--fs-sm)">
                  <?php foreach ($ed_points['arg_for'] as $p): ?>
                    <li style="margin-bottom:.3rem"><?= h($p) ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>
            <?php if ($ed_points['arg_against']): ?>
              <div style="background:var(--accent-soft);border:1px solid var(--accent);border-radius:8px;padding:1rem">
                <h4 style="margin:0 0 .5rem;color:var(--accent)">✗ Arguments Against</h4>
                <ul style="margin:0;color:var(--text-soft);font-size:var(--fs-sm)">
                  <?php foreach ($ed_points['arg_against'] as $p): ?>
                    <li style="margin-bottom:.3rem"><?= h($p) ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>
          </div>

          <h4 style="margin:1.25rem 0 .5rem;color:var(--highlight)">🎯 Exam Relevance</h4>
          <p style="color:var(--text-soft);margin:0"><?= h($editorial['exam_relevance']) ?></p>

          <h4 style="margin:1.25rem 0 .5rem;color:var(--accent)">Conclusion</h4>
          <p style="color:var(--text-soft);margin:0"><?= h($editorial['conclusion']) ?></p>

          <?php if (!empty($sourceArticles)): ?>
            <div style="margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--border)">
              <h4 style="font-size:var(--fs-sm);color:var(--muted);margin:0 0 .75rem">📰 Source Articles</h4>
              <?php foreach ($sourceArticles as $sa): ?>
                <a href="/article.php?id=<?= (int)$sa['id'] ?>" style="display:block;font-size:var(--fs-sm);color:var(--accent);margin-bottom:.4rem;text-decoration:none">
                  → <?= h($sa['title']) ?>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

    <?php /* ── LEADERBOARD TAB ── */ elseif ($tab === 'leaderboard'): ?>
      <h3 style="font-family:var(--ff-display);margin:0 0 .5rem">🏆 This week's toppers</h3>
      <?php if ($myRank): ?>
        <p style="color:var(--muted);font-size:var(--fs-sm);margin:0 0 1.5rem">Your rank this week: <strong>#<?= (int)$myRank ?></strong></p>
      <?php endif; ?>
      <?php if (!$leaders): ?>
        <p style="color:var(--muted)">No attempts yet this week. Be the first — take today's quiz!</p>
      <?php else: ?>
        <div class="table-scroll">
          <table class="admin-table" style="max-width:720px">
            <thead>
              <tr>
                <th>#</th>
                <th>Aspirant</th>
                <th>Points</th>
                <th>Quizzes</th>
                <th>Avg Accuracy</th>
                <th>Avg Time</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($leaders as $i => $L): ?>
              <tr>
                <td class="mono"><?= $i < 3 ? ['🥇','🥈','🥉'][$i] : $i+1 ?></td>
                <td><?= h($L['display_name'] ?: $L['first_name'] ?: 'Eagle Aspirant') ?></td>
                <td class="mono" style="font-weight:700"><?= (int)$L['pts'] ?></td>
                <td class="mono"><?= (int)$L['quizzes'] ?></td>
                <td class="mono"><?= $L['avg_accuracy'] !== null ? $L['avg_accuracy'].'%' : '—' ?></td>
                <td class="mono"><?= $L['avg_min'] !== null ? $L['avg_min'].' min' : '—' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    <?php endif; ?>

  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
