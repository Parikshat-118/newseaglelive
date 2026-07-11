<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$PAGE_TITLE       = 'Student Hub — UPSC, SSC & Media Exam Prep';
$PAGE_DESCRIPTION = 'Daily UPSC, SSC & media-exam prep from today\'s news: MCQs, mains questions, editorial analysis, and leaderboard.';

$user = ne_current_user();
$db   = ne_db();

$tab = $_GET['tab'] ?? 'quiz';
if (!in_array($tab, ['quiz', 'media', 'mains', 'editorial'], true)) {
    $tab = 'quiz';
}

$lang = strtolower((string)($_GET['lang'] ?? 'en'));
if (!in_array($lang, ['en', 'hi'], true)) {
    $lang = 'en';
}

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$reqDate = $_GET['date'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reqDate)) {
    $reqDate = $today;
}

$studentUrl = static function (array $overrides = []) use ($tab, $reqDate, $lang): string {
    $params = array_merge([
        'tab' => $tab,
        'date' => $reqDate,
        'lang' => $lang,
    ], $overrides);
    return '/students.php?' . http_build_query($params);
};

$copy = [
    'en' => [
        'switch_en' => 'English',
        'switch_hi' => 'हिन्दी',
        'hub_title' => "Today's news. Your practice.",
        'hub_intro' => "Fresh every morning at 6:30 - AI turns today's news into UPSC/SSC MCQs, Mains practice, editorial analysis, and a dedicated section for media-exam aspirants (IIMC, YMCA, JMI & more).",
        'quiz_tab' => 'UPSC/SSC Quiz',
        'media_tab' => 'Media Exams',
        'mains_tab' => 'Mains Practice',
        'editorial_tab' => 'Editorial',
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'source_articles' => 'Source Articles',
    ],
    'hi' => [
        'switch_en' => 'English',
        'switch_hi' => 'हिन्दी',
        'hub_title' => 'आज की खबरें। आपकी तैयारी।',
        'hub_intro' => 'हर सुबह 6:30 बजे ताज़ा अपडेट - एआई आज की खबरों को UPSC/SSC MCQs, मेंस प्रैक्टिस, एडिटोरियल विश्लेषण और मीडिया-एग्जाम अभ्यर्थियों के लिए खास सामग्री में बदलता है।',
        'quiz_tab' => 'UPSC/SSC क्विज',
        'media_tab' => 'मीडिया परीक्षा',
        'mains_tab' => 'मेंस प्रैक्टिस',
        'editorial_tab' => 'एडिटोरियल',
        'today' => 'आज',
        'yesterday' => 'कल',
        'source_articles' => 'स्रोत लेख',
    ],
][$lang];

// ── Load content for the active tab ──────────────────────────────
$quiz        = null;
$questions   = [];
$myAttempt   = null;
$editorial   = null;
$ed_points   = ['key_point' => [], 'arg_for' => [], 'arg_against' => []];
$mains       = [];
$genStatus   = null;
$sourceArticles = [];

try {
    if ($tab === 'quiz' || $tab === 'media') {
        $exam = $tab === 'media' ? 'media' : 'upsc';

        $s = $db->prepare(
            "SELECT q.id, q.title, q.quiz_date, q.language, g.status, g.model, g.provider
             FROM daily_quizzes q
             JOIN ai_generations g ON g.id = q.generation_id
             WHERE q.exam_type = :e AND q.quiz_date = :d AND q.language = :lang
             ORDER BY q.id DESC LIMIT 1"
        );
        $s->execute([':e' => $exam, ':d' => $reqDate, ':lang' => $lang]);
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
                 WHERE content_type = :t AND content_date = :d AND language = :lang LIMIT 1"
            );
            $gs->execute([':t' => ($exam === 'media' ? 'media_quiz' : 'upsc_quiz'), ':d' => $reqDate, ':lang' => $lang]);
            $genStatus = $gs->fetch() ?: null;
        }

    } elseif ($tab === 'mains') {
        $mq = $db->prepare(
            "SELECT m.id, m.question, m.paper, m.hint, m.order_no, m.language
             FROM daily_mains_questions m
             WHERE DATE(m.mains_date) = :d AND m.language = :lang
             ORDER BY m.order_no"
        );
        $mq->execute([':d' => $reqDate, ':lang' => $lang]);
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
                "SELECT status FROM ai_generations WHERE content_type='mains' AND content_date=:d AND language=:lang LIMIT 1"
            );
            $gs->execute([':d' => $reqDate, ':lang' => $lang]);
            $genStatus = $gs->fetch() ?: null;
        }

    } elseif ($tab === 'editorial') {
        $ed = $db->prepare(
            "SELECT id, title, background, exam_relevance, conclusion, language
             FROM daily_editorials
             WHERE DATE(editorial_date) = :d AND language = :lang LIMIT 1"
        );
        $ed->execute([':d' => $reqDate, ':lang' => $lang]);
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
                "SELECT status FROM ai_generations WHERE content_type='editorial' AND content_date=:d AND language=:lang LIMIT 1"
            );
            $gs->execute([':d' => $reqDate, ':lang' => $lang]);
            $genStatus = $gs->fetch() ?: null;
        }

    }
} catch (Throwable $e) {
    error_log('[students] ' . $e->getMessage());
}

include __DIR__ . '/includes/header.php';
?>

<style>
.archive-filters {
    display: flex; gap: 0.5rem; margin: 1.5rem 0; flex-wrap: wrap; align-items: center;
}
.archive-filter {
    padding: 0.5rem 1rem; border: 1px solid var(--border); border-radius: 8px;
    text-decoration: none; color: var(--text-soft); font-size: var(--fs-sm);
    transition: all 0.2s; background: var(--surface2); cursor: pointer;
}
.archive-filter.active {
    background: var(--accent); color: white; border-color: var(--accent);
}
.archive-filter:hover:not(.active) {
    background: var(--surface); color: var(--text);
}
.date-picker {
    padding: 0.4rem 0.75rem; border: 1px solid var(--border); border-radius: 8px;
    background: var(--surface); color: var(--text); font-family: var(--ff-body);
}
.lang-toggle {
    display: inline-flex; gap: 0.35rem; margin: 0 0 1.5rem; padding: 0.35rem;
    background: var(--surface); border: 1px solid var(--border); border-radius: 999px;
}
.lang-toggle a {
    padding: 0.5rem 0.9rem; border-radius: 999px; font-size: var(--fs-sm); color: var(--text-soft);
}
.lang-toggle a.active {
    background: var(--accent); color: #fff;
}
</style>

<section class="section" style="padding-top:2rem">
  <div class="container">

    <div class="section-eyebrow"><span class="mono">🎓 Daily</span><span>Student Hub</span></div>
    <div class="lang-toggle" role="tablist" aria-label="Student Hub language switch">
      <a href="<?= h($studentUrl(['lang' => 'en'])) ?>" class="<?= $lang === 'en' ? 'active' : '' ?>" aria-current="<?= $lang === 'en' ? 'page' : 'false' ?>"><?= h($copy['switch_en']) ?></a>
      <a href="<?= h($studentUrl(['lang' => 'hi'])) ?>" class="<?= $lang === 'hi' ? 'active' : '' ?> deva" aria-current="<?= $lang === 'hi' ? 'page' : 'false' ?>"><?= h($copy['switch_hi']) ?></a>
    </div>
    <h1 class="section-title<?= $lang === 'hi' ? ' deva' : '' ?>"><?= h($copy['hub_title']) ?></h1>
    <p style="max-width:62ch;color:var(--text-soft);margin:-1.5rem 0 2rem">
      <?= h($copy['hub_intro']) ?>
    </p>

    <div class="admin-tabs">
      <a id="tab-quiz"        class="admin-tab<?= $tab==='quiz'        ? ' active' : '' ?>" href="<?= h($studentUrl(['tab' => 'quiz'])) ?>">📝 <?= h($copy['quiz_tab']) ?></a>
      <a id="tab-media"       class="admin-tab<?= $tab==='media'       ? ' active' : '' ?>" href="<?= h($studentUrl(['tab' => 'media'])) ?>">📺 <?= h($copy['media_tab']) ?></a>
      <a id="tab-mains"       class="admin-tab<?= $tab==='mains'       ? ' active' : '' ?>" href="<?= h($studentUrl(['tab' => 'mains'])) ?>">✍️ <?= h($copy['mains_tab']) ?></a>
      <a id="tab-editorial"   class="admin-tab<?= $tab==='editorial'   ? ' active' : '' ?>" href="<?= h($studentUrl(['tab' => 'editorial'])) ?>">📰 <?= h($copy['editorial_tab']) ?></a>
    </div>
    
    <div class="archive-filters">
        <a href="<?= h($studentUrl(['date' => $today])) ?>" class="archive-filter <?= $reqDate==$today?'active':'' ?>"><?= h($copy['today']) ?></a>
        <a href="<?= h($studentUrl(['date' => $yesterday])) ?>" class="archive-filter <?= $reqDate==$yesterday?'active':'' ?>"><?= h($copy['yesterday']) ?></a>
        <input type="date" class="date-picker" value="<?= $reqDate ?>" max="<?= $today ?>" onchange='location.href=<?= json_encode('/students.php?tab=' . rawurlencode($tab) . '&lang=' . rawurlencode($lang) . '&date=') ?>+this.value'>
    </div>

    <?php /* ── QUIZ TAB ── */ if ($tab === 'quiz' || $tab === 'media'): ?>

      <?php if (!$quiz): ?>
        <div style="padding:2.5rem 0">
          <?php if ($genStatus && $genStatus['status'] === 'RUNNING'): ?>
            <p style="color:var(--highlight)"<?= $lang === 'hi' ? ' class="deva"' : '' ?>><?= $lang === 'hi' ? '⏳ ' . h($reqDate) . ' के लिए क्विज अभी तैयार हो रहा है... कृपया थोड़ी देर में रिफ्रेश करें।' : '⏳ The quiz for ' . h($reqDate) . ' is being generated right now... refresh in a moment.' ?></p>
          <?php elseif ($genStatus && $genStatus['status'] === 'FAILED'): ?>
            <p style="color:var(--accent)"<?= $lang === 'hi' ? ' class="deva"' : '' ?>><?= $lang === 'hi' ? '⚠️ ' . h($reqDate) . ' के लिए क्विज जनरेशन असफल रही। यह अपने-आप फिर से प्रयास करेगी। कृपया थोड़ी देर बाद देखें।' : '⚠️ The quiz generation for ' . h($reqDate) . ' failed. It will be retried automatically. Check back soon.' ?></p>
          <?php else: ?>
            <p style="color:var(--muted)"<?= $lang === 'hi' ? ' class="deva"' : '' ?>><?= $lang === 'hi' ? h($reqDate) . ' के लिए कोई क्विज नहीं मिला। यह हर दिन सुबह 6:30 बजे बनता है।' : 'No quiz found for ' . h($reqDate) . '. Generated daily at 6:30 AM.' ?></p>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <h3 style="font-family:var(--ff-display);margin:0 0 .25rem"><?= h($quiz['title']) ?></h3>
        <p class="mono" style="color:var(--muted);font-size:var(--fs-xs);margin:0 0 .5rem">
          <?= count($questions) ?> questions · instantly evaluated
        </p>
        <p class="mono" style="color:var(--muted);font-size:var(--fs-xs);margin:0 0 1.5rem">
          Generated by <?= h($quiz['model'] ?? $quiz['provider'] ?? 'AI') ?>
        </p>

        <?php if ($myAttempt): ?>
          <!-- COMPLETED VIEW -->
          <div class="flash flash-ok">
            ✓ You scored <strong><?= (int)$myAttempt['score'] ?>/<?= (int)$myAttempt['total'] ?></strong>
            (accuracy <?= number_format((float)$myAttempt['accuracy'], 1) ?>%
            <?php if ($myAttempt['time_taken_sec']): ?>
              · completed in <?= gmdate('i:s', (int)$myAttempt['time_taken_sec']) ?>
            <?php endif; ?>)
            — Answers revealed below.
          </div>
          <div id="quiz" data-quiz-id="<?= (int)$quiz['id'] ?>" data-done="1">
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
          <!-- ASPIRANT TAKING TEST VIEW (Anyone) -->
          <div id="quiz-landing" class="auth-card" style="max-width:500px;text-align:center;padding:3rem 2rem;margin:2rem auto">
            <h2 style="font-family:var(--ff-display);margin:0 0 .5rem" class="<?= $lang === 'hi' ? 'deva' : '' ?>"><?= $lang === 'hi' ? 'शुरू करने के लिए तैयार हैं?' : 'Ready to begin?' ?></h2>
            <p style="color:var(--text-soft);margin:0 0 2rem" class="<?= $lang === 'hi' ? 'deva' : '' ?>"><?= $lang === 'hi' ? 'यह ' . count($questions) . ' प्रश्नों का सख्त टेस्ट है। Start दबाते ही टाइमर शुरू हो जाएगा।' : 'This is a strict ' . count($questions) . '-question test. Once you click Start, the timer will begin.' ?></p>
            <button class="btn btn-primary<?= $lang === 'hi' ? ' deva' : '' ?>" id="start-test-btn" type="button" style="padding:1rem 3rem;font-size:1.2rem"><?= $lang === 'hi' ? 'टेस्ट शुरू करें' : 'Start Test' ?></button>
          </div>

          <div id="quiz-timer" style="display:none;position:sticky;top:60px;z-index:100;background:var(--surface);border-bottom:1px solid var(--border);padding:.75rem;text-align:center;font-weight:700;font-size:1.2rem;color:var(--highlight);margin:0 -1rem 1rem">
            ⏱️ <span id="timer-display">00:00</span>
          </div>

          <div id="quiz" data-quiz-id="<?= (int)$quiz['id'] ?>" data-done="0" style="display:none">
            <div id="quiz-result" class="flash flash-ok" style="display:none;margin-bottom:1.5rem;font-size:1.1rem;padding:1.5rem;text-align:center"></div>

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
          </div>
        <?php endif; ?>

        <?php if (!empty($sourceArticles)): ?>
          <div style="margin-top:2rem">
            <h4 style="font-size:var(--fs-sm);color:var(--muted);margin:0 0 .75rem" class="<?= $lang === 'hi' ? 'deva' : '' ?>">📰 <?= h($copy['source_articles']) ?></h4>
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
              if (done) return;
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
            var correctCount = 0, incorrectCount = 0, skippedCount = 0;
            d.results.forEach(function(r, i){
                if (!r) return;
                var myPick = picks[i];
                if (r.correct) correctCount++;
                else if (!myPick) skippedCount++;
                else incorrectCount++;
            });
            var attemptedCount = d.total - skippedCount;

            var statsHtml = '<div style="display:flex;justify-content:center;gap:1.5rem;margin:1rem 0;font-size:0.95rem;flex-wrap:wrap">' +
                '<div><span style="color:var(--text-soft)">Attempted:</span> <strong>' + attemptedCount + '</strong></div>' +
                '<div><span style="color:#00D4AA">Correct:</span> <strong>' + correctCount + '</strong></div>' +
                '<div><span style="color:var(--accent)">Incorrect:</span> <strong>' + incorrectCount + '</strong></div>' +
                '<div><span style="color:var(--muted)">Skipped:</span> <strong>' + skippedCount + '</strong></div>' +
                '</div>';

            res.innerHTML = '<div style="font-size:2.5rem;margin-bottom:0.5rem">🎯 <strong>'+d.score+'/'+d.total+'</strong></div>' +
                            '<div style="font-size:1.1rem">Accuracy: <strong>'+d.accuracy+'%</strong> &nbsp;•&nbsp; Time: <strong>'+formatTime(timeTaken)+'</strong></div>' +
                            statsHtml + 
                            '<div style="margin-top:0.5rem;font-size:0.95rem;opacity:0.8;border-top:1px solid var(--border);padding-top:1rem">Review your answers below.</div>';
            res.style.display = 'block';
            submit.style.display = 'none';
            document.querySelectorAll('.quiz-q').forEach(function(qel, i){
              var r = d.results[i]; if (!r) return;
              var myPick = picks[i];
              qel.querySelectorAll('.opt').forEach(function(b){
                var opt = b.getAttribute('data-opt');
                if (opt === r.answer){ b.style.borderColor='#00D4AA'; b.style.background='color-mix(in srgb,#00D4AA 15%,transparent)'; }
                else if (myPick === opt && opt !== r.answer){ b.style.borderColor='var(--accent)'; }
              });
              var ex = qel.querySelector('.explain');
              if (ex && r.explanation){ 
                  var ptext = 'Your Answer: ' + (myPick ? myPick : 'Skipped') + ' &nbsp;|&nbsp; Correct Answer: ' + r.answer;
                  var htext;
                  if (r.correct) htext = '<span style="color:#00D4AA">✅ Correct</span>';
                  else if (!myPick) htext = '<span style="color:var(--muted)">⚠️ Skipped</span>';
                  else htext = '<span style="color:var(--accent)">❌ Incorrect</span>';
                  
                  ex.innerHTML = htext + '<br><strong style="color:var(--text)">' + ptext + '</strong><br><br>💡 '+r.explanation; 
                  ex.style.display='block'; 
              }
            });
            window.scrollTo({top: box.offsetTop - 100, behavior:'smooth'});
          });
        });
      })();
      </script>

    <?php /* ── MAINS TAB ── */ elseif ($tab === 'mains'): ?>
      <?php if (!$mains): ?>
        <p style="color:var(--muted);padding:2rem 0" class="<?= $lang === 'hi' ? 'deva' : '' ?>">
          <?= $genStatus && $genStatus['status']==='FAILED'
              ? ($lang === 'hi' ? "⚠️ $reqDate के लिए जनरेशन असफल रही। यह अपने-आप फिर से प्रयास करेगी।" : "⚠️ Generation for $reqDate failed. Will retry automatically.")
              : ($lang === 'hi' ? "$reqDate के लिए मेंस प्रश्न उपलब्ध नहीं हैं।" : "Mains questions for $reqDate are not available.") ?>
        </p>
      <?php else: ?>
        <h3 style="font-family:var(--ff-display);margin:0 0 1.5rem">Mains Practice — <?= date('d M Y', strtotime($reqDate)) ?></h3>
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
        <p style="color:var(--muted);padding:2rem 0" class="<?= $lang === 'hi' ? 'deva' : '' ?>">
          <?= $genStatus && $genStatus['status']==='FAILED'
              ? ($lang === 'hi' ? "⚠️ $reqDate के लिए जनरेशन असफल रही। यह अपने-आप फिर से प्रयास करेगी।" : "⚠️ Generation for $reqDate failed. Will retry automatically.")
              : ($lang === 'hi' ? "$reqDate के लिए एडिटोरियल विश्लेषण उपलब्ध नहीं है।" : "Editorial analysis for $reqDate is not available.") ?>
        </p>
      <?php else: ?>
        <div class="auth-card" style="max-width:780px">
          <span class="badge badge-admin">EDITORIAL ANALYSIS — <?= date('d M Y', strtotime($reqDate)) ?></span>
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
              <h4 style="font-size:var(--fs-sm);color:var(--muted);margin:0 0 .75rem" class="<?= $lang === 'hi' ? 'deva' : '' ?>">📰 <?= h($copy['source_articles']) ?></h4>
              <?php foreach ($sourceArticles as $sa): ?>
                <a href="/article.php?id=<?= (int)$sa['id'] ?>" style="display:block;font-size:var(--fs-sm);color:var(--accent);margin-bottom:.4rem;text-decoration:none">
                  → <?= h($sa['title']) ?>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

    <?php endif; ?>

  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
