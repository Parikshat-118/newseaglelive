<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$PAGE_TITLE = 'Student Hub';
$PAGE_DESCRIPTION = 'Daily UPSC, SSC & media-exam prep from today\'s news: MCQs, mains questions, editorial analysis.';

$user = ne_current_user();
$db = ne_db();

$tab = $_GET['tab'] ?? 'quiz';
if (!in_array($tab, ['quiz','media','mains','editorial','leaderboard'], true)) $tab = 'quiz';

// ── Load content per tab ─────────────────────────────────────────────
$mcq = null; $mcqAttempt = null; $mains = null; $editorial = null; $leaders = []; $myRank = null;

try {
    if ($tab === 'quiz' || $tab === 'media') {
        $exam = $tab === 'media' ? 'media' : 'upsc';
        $s = $db->prepare("SELECT id, title, payload, content_date FROM student_content
                           WHERE kind = 'mcq' AND exam = :e ORDER BY content_date DESC, id DESC LIMIT 1");
        $s->execute([':e' => $exam]);
        $mcq = $s->fetch();
        if ($mcq && $user) {
            $a = $db->prepare("SELECT score, total FROM web_quiz_attempts WHERE user_id = :u AND content_id = :c");
            $a->execute([':u' => $user['id'], ':c' => $mcq['id']]);
            $mcqAttempt = $a->fetch() ?: null;
        }
    } elseif ($tab === 'mains') {
        $mains = $db->query("SELECT id, title, payload, content_date FROM student_content
                             WHERE kind = 'mains' ORDER BY content_date DESC, id DESC LIMIT 1")->fetch();
    } elseif ($tab === 'editorial') {
        $editorial = $db->query("SELECT id, title, payload, content_date FROM student_content
                                 WHERE kind = 'editorial' ORDER BY content_date DESC, id DESC LIMIT 1")->fetch();
    } elseif ($tab === 'leaderboard') {
        $leaders = $db->query(
            "SELECT u.display_name, u.first_name, SUM(a.score) AS pts, COUNT(*) AS quizzes
             FROM web_quiz_attempts a JOIN users u ON u.id = a.user_id
             WHERE a.taken_at >= (NOW() - INTERVAL 7 DAY)
             GROUP BY a.user_id ORDER BY pts DESC LIMIT 20"
        )->fetchAll();
    }
} catch (Throwable $e) { error_log('[students] ' . $e->getMessage()); }

include __DIR__ . '/includes/header.php';
?>

<section class="section" style="padding-top:2rem">
  <div class="container">
    <div class="section-eyebrow"><span class="mono">🎓 Daily</span><span>Student Hub</span></div>
    <h2 class="section-title">Today's news. Tomorrow's rank.</h2>
    <p style="max-width:62ch;color:var(--text-soft);margin:-1.5rem 0 2rem">
      Fresh every morning at 6:30 — AI turns today's news into UPSC/SSC MCQs, Mains practice,
      editorial analysis, and a dedicated section for media-exam aspirants (IIMC, YMCA, JMI &amp; more).
    </p>

    <div class="admin-tabs">
      <a class="admin-tab<?= $tab==='quiz' ? ' active' : '' ?>" href="?tab=quiz">📝 UPSC/SSC Quiz</a>
      <a class="admin-tab<?= $tab==='media' ? ' active' : '' ?>" href="?tab=media">📺 Media Exams</a>
      <a class="admin-tab<?= $tab==='mains' ? ' active' : '' ?>" href="?tab=mains">✍️ Mains Practice</a>
      <a class="admin-tab<?= $tab==='editorial' ? ' active' : '' ?>" href="?tab=editorial">📰 Editorial</a>
      <a class="admin-tab<?= $tab==='leaderboard' ? ' active' : '' ?>" href="?tab=leaderboard">🏆 Leaderboard</a>
    </div>

    <?php if ($tab === 'quiz' || $tab === 'media'): ?>
      <?php if (!$mcq): ?>
        <p style="color:var(--muted);padding:2rem 0">Today's quiz is being prepared — generated daily at 6:30 AM. Check back soon.</p>
      <?php else:
        $payload = json_decode($mcq['payload'], true) ?: [];
        $questions = $payload['questions'] ?? [];
      ?>
        <h3 style="font-family:var(--ff-display);margin:0 0 .25rem"><?= h($mcq['title']) ?></h3>
        <p class="mono" style="color:var(--muted);font-size:var(--fs-xs);margin:0 0 1.5rem"><?= count($questions) ?> questions · attempt once · counts for leaderboard</p>

        <?php if (!$user): ?>
          <div class="auth-card" style="max-width:480px">
            <p style="margin:0 0 1rem;color:var(--text-soft)">Log in to attempt the quiz and appear on the leaderboard.</p>
            <a class="btn btn-primary btn-block" href="/login.php?next=<?= urlencode('/students.php?tab=' . $tab) ?>">Log in with Telegram →</a>
          </div>
        <?php elseif ($mcqAttempt): ?>
          <div class="flash flash-ok">✓ You scored <strong><?= (int)$mcqAttempt['score'] ?>/<?= (int)$mcqAttempt['total'] ?></strong> on this quiz. New quiz drops tomorrow 6:30 AM. Review below.</div>
        <?php endif; ?>

        <div id="quiz" data-content-id="<?= (int)$mcq['id'] ?>" data-done="<?= $mcqAttempt ? 1 : 0 ?>" data-auth="<?= $user ? 1 : 0 ?>">
          <?php foreach ($questions as $i => $q): ?>
            <div class="auth-card quiz-q" style="max-width:760px;margin-bottom:1rem" data-qi="<?= $i ?>">
              <div style="display:flex;justify-content:space-between;margin-bottom:.75rem">
                <span class="mono" style="color:var(--accent);font-size:var(--fs-xs)">Q<?= $i+1 ?></span>
                <span class="badge badge-off"><?= h($q['topic'] ?? 'GK') ?></span>
              </div>
              <p style="margin:0 0 1rem;font-weight:600"><?= h($q['q'] ?? '') ?></p>
              <?php foreach (($q['options'] ?? []) as $oi => $opt): ?>
                <button class="action-btn opt" data-oi="<?= $oi ?>" type="button"
                        style="display:flex;width:100%;text-align:left;margin-bottom:.5rem;justify-content:flex-start">
                  <span class="mono" style="color:var(--muted)"><?= chr(65+$oi) ?>.</span>&nbsp; <?= h($opt) ?>
                </button>
              <?php endforeach; ?>
              <div class="explain" style="display:none;margin-top:.75rem;padding:.75rem 1rem;background:var(--surface2);border-radius:8px;font-size:var(--fs-sm);color:var(--text-soft)"></div>
            </div>
          <?php endforeach; ?>

          <?php if ($user && !$mcqAttempt): ?>
            <button class="btn btn-primary" id="submit-quiz" type="button" style="margin-top:.5rem">Submit answers</button>
          <?php endif; ?>
          <div id="quiz-result" class="flash flash-ok" style="display:none;margin-top:1rem"></div>
        </div>

        <script>
        (function(){
          var box = document.getElementById('quiz');
          var done = box.getAttribute('data-done') === '1';
          var auth = box.getAttribute('data-auth') === '1';
          var picks = {};

          document.querySelectorAll('.quiz-q').forEach(function(qel){
            var qi = +qel.getAttribute('data-qi');
            qel.querySelectorAll('.opt').forEach(function(btn){
              btn.addEventListener('click', function(){
                if (done) return;
                qel.querySelectorAll('.opt').forEach(function(b){ b.classList.remove('liked'); });
                btn.classList.add('liked');
                picks[qi] = +btn.getAttribute('data-oi');
              });
            });
          });

          var submit = document.getElementById('submit-quiz');
          if (submit) submit.addEventListener('click', function(){
            var total = document.querySelectorAll('.quiz-q').length;
            var answers = [];
            for (var i = 0; i < total; i++) answers.push(picks.hasOwnProperty(i) ? picks[i] : -1);
            submit.disabled = true; submit.textContent = 'Scoring…';
            fetch('/api/quiz-attempt.php', {
              method:'POST', headers:{'Content-Type':'application/json'},
              body: JSON.stringify({ content_id: +box.getAttribute('data-content-id'), answers: answers })
            }).then(r=>r.json()).then(function(d){
              if (!d.ok) { alert('Failed: ' + (d.error||'')); submit.disabled = false; submit.textContent = 'Submit answers'; return; }
              done = true;
              var res = document.getElementById('quiz-result');
              res.textContent = '🎯 Score: ' + d.score + '/' + d.total + ' — answers & explanations revealed below.';
              res.style.display = 'block';
              submit.style.display = 'none';
              document.querySelectorAll('.quiz-q').forEach(function(qel, i){
                var r = d.results[i]; if (!r) return;
                qel.querySelectorAll('.opt').forEach(function(b){
                  var oi = +b.getAttribute('data-oi');
                  if (oi === r.answer) { b.style.borderColor = '#00D4AA'; b.style.background = 'color-mix(in srgb,#00D4AA 15%, transparent)'; }
                  else if (picks[i] === oi && !r.correct) { b.style.borderColor = 'var(--accent)'; }
                });
                var ex = qel.querySelector('.explain');
                if (r.explanation) { ex.textContent = '💡 ' + r.explanation; ex.style.display = 'block'; }
              });
              window.scrollTo({top: res.offsetTop - 100, behavior:'smooth'});
            });
          });
        })();
        </script>
      <?php endif; ?>

    <?php elseif ($tab === 'mains'): ?>
      <?php if (!$mains): ?>
        <p style="color:var(--muted);padding:2rem 0">Today's Mains questions are being prepared. Generated daily 6:30 AM.</p>
      <?php else:
        $payload = json_decode($mains['payload'], true) ?: [];
      ?>
        <h3 style="font-family:var(--ff-display);margin:0 0 1.5rem"><?= h($mains['title']) ?></h3>
        <?php foreach (($payload['questions'] ?? []) as $i => $q): ?>
          <div class="auth-card" style="max-width:760px;margin-bottom:1.25rem">
            <div style="display:flex;gap:.5rem;margin-bottom:.75rem">
              <span class="badge badge-admin"><?= h($q['paper'] ?? 'GS') ?></span>
              <span class="badge badge-off">150–250 words</span>
            </div>
            <p style="font-weight:600;font-size:var(--fs-lg);margin:0 0 .75rem">Q<?= $i+1 ?>. <?= h($q['q'] ?? '') ?></p>
            <?php if (!empty($q['hint'])): ?>
              <p style="color:var(--muted);font-size:var(--fs-sm);margin:0 0 1rem">💭 Approach: <?= h($q['hint']) ?></p>
            <?php endif; ?>
            <details>
              <summary style="cursor:pointer;color:var(--accent);font-weight:600">Reveal model answer points</summary>
              <ul style="margin:.75rem 0 0;color:var(--text-soft)">
                <?php foreach (($q['points'] ?? []) as $pt): ?>
                  <li style="margin-bottom:.4rem"><?= h($pt) ?></li>
                <?php endforeach; ?>
              </ul>
            </details>
          </div>
        <?php endforeach; ?>
        <p style="color:var(--muted);font-size:var(--fs-sm)">✍️ Tip: write your answer on paper first, THEN reveal the points. Compare honestly.</p>
      <?php endif; ?>

    <?php elseif ($tab === 'editorial'): ?>
      <?php if (!$editorial): ?>
        <p style="color:var(--muted);padding:2rem 0">Today's editorial analysis is being prepared. Generated daily 6:30 AM.</p>
      <?php else:
        $e = json_decode($editorial['payload'], true) ?: [];
      ?>
        <div class="auth-card" style="max-width:780px">
          <span class="badge badge-admin">EDITORIAL ANALYSIS</span>
          <h3 style="font-family:var(--ff-display);font-size:var(--fs-xl);margin:.75rem 0 1rem"><?= h($e['title'] ?? $editorial['title']) ?></h3>

          <?php if (!empty($e['background'])): ?>
            <h4 style="margin:1.25rem 0 .5rem;color:var(--accent)">Background</h4>
            <p style="color:var(--text-soft);margin:0"><?= h($e['background']) ?></p>
          <?php endif; ?>

          <?php if (!empty($e['key_points'])): ?>
            <h4 style="margin:1.25rem 0 .5rem;color:var(--accent)">Key Points</h4>
            <ul style="color:var(--text-soft);margin:0">
              <?php foreach ($e['key_points'] as $p): ?><li style="margin-bottom:.35rem"><?= h($p) ?></li><?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:1.25rem" class="ed-grid">
            <style>@media(max-width:640px){.ed-grid{grid-template-columns:1fr !important}}</style>
            <?php if (!empty($e['arguments_for'])): ?>
              <div style="background:color-mix(in srgb,#00D4AA 8%,transparent);border:1px solid #00D4AA;border-radius:8px;padding:1rem">
                <h4 style="margin:0 0 .5rem;color:#00D4AA">✓ Arguments For</h4>
                <ul style="margin:0;color:var(--text-soft);font-size:var(--fs-sm)">
                  <?php foreach ($e['arguments_for'] as $p): ?><li style="margin-bottom:.3rem"><?= h($p) ?></li><?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>
            <?php if (!empty($e['arguments_against'])): ?>
              <div style="background:var(--accent-soft);border:1px solid var(--accent);border-radius:8px;padding:1rem">
                <h4 style="margin:0 0 .5rem;color:var(--accent)">✗ Arguments Against</h4>
                <ul style="margin:0;color:var(--text-soft);font-size:var(--fs-sm)">
                  <?php foreach ($e['arguments_against'] as $p): ?><li style="margin-bottom:.3rem"><?= h($p) ?></li><?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>
          </div>

          <?php if (!empty($e['exam_relevance'])): ?>
            <h4 style="margin:1.25rem 0 .5rem;color:var(--highlight)">🎯 Exam Relevance</h4>
            <p style="color:var(--text-soft);margin:0"><?= h($e['exam_relevance']) ?></p>
          <?php endif; ?>

          <?php if (!empty($e['conclusion'])): ?>
            <h4 style="margin:1.25rem 0 .5rem;color:var(--accent)">Conclusion</h4>
            <p style="color:var(--text-soft);margin:0"><?= h($e['conclusion']) ?></p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

    <?php elseif ($tab === 'leaderboard'): ?>
      <h3 style="font-family:var(--ff-display);margin:0 0 1.25rem">🏆 This week's toppers</h3>
      <?php if (!$leaders): ?>
        <p style="color:var(--muted)">No attempts yet this week. Be the first — take today's quiz!</p>
      <?php else: ?>
        <div class="table-scroll"><table class="admin-table" style="max-width:640px">
          <thead><tr><th>#</th><th>Aspirant</th><th>Points</th><th>Quizzes</th></tr></thead>
          <tbody>
          <?php foreach ($leaders as $i => $L): ?>
            <tr>
              <td class="mono"><?= $i < 3 ? ['🥇','🥈','🥉'][$i] : $i+1 ?></td>
              <td><?= h($L['display_name'] ?: $L['first_name'] ?: 'Eagle Aspirant') ?></td>
              <td class="mono" style="font-weight:700"><?= (int)$L['pts'] ?></td>
              <td class="mono"><?= (int)$L['quizzes'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    <?php endif; ?>

  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
