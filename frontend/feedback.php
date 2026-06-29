<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$PAGE_TITLE = 'Feedback';
$PAGE_DESCRIPTION = 'What readers say about News Eagle Live — and tell us what you think.';

$user = ne_current_user();
$db = ne_db();

$approved = [];
if ($db) {
    try {
        $approved = $db->query(
            "SELECT display_name, rating, message, created_at
             FROM feedback WHERE is_approved = 1
             ORDER BY approved_at DESC LIMIT 30"
        )->fetchAll();
    } catch (Throwable $e) {}
}

include __DIR__ . '/includes/header.php';
?>

<section class="section" style="padding-top:2rem">
  <div class="container">
    <div class="section-eyebrow"><span class="mono">Voices</span><span>Reader feedback</span></div>
    <h2 class="section-title">What the flock says. 🦅</h2>

    <?php if ($approved): ?>
      <div class="testimonial-grid">
        <?php foreach ($approved as $f): ?>
          <div class="testimonial">
            <div class="stars"><?= str_repeat('★', (int)$f['rating']) . str_repeat('☆', 5 - (int)$f['rating']) ?></div>
            <p class="testimonial-msg" style="margin-top:.5rem">"<?= h($f['message']) ?>"</p>
            <div class="testimonial-meta">
              <span>— <?= h($f['display_name']) ?></span>
              <span><?= ne_time_ago($f['created_at']) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p style="color:var(--muted);margin-bottom:2rem">No feedback published yet — be the first!</p>
    <?php endif; ?>

    <div class="auth-card" style="max-width:560px">
      <div class="auth-eyebrow">Your turn</div>
      <h3 class="auth-title" style="font-size:var(--fs-lg)">Tell us what you think</h3>

      <?php if (!$user): ?>
        <p style="color:var(--text-soft)">You need to log in to leave feedback.</p>
        <a class="btn btn-primary btn-block" href="/login.php?next=/feedback.php">Log in with Telegram →</a>
      <?php else: ?>
        <div id="fb-error" class="flash flash-err" style="display:none"></div>
        <div id="fb-ok" class="flash flash-ok" style="display:none"></div>

        <div class="field">
          <label>Rating</label>
          <div id="star-pick" style="font-size:1.8rem;cursor:pointer;color:var(--highlight);letter-spacing:6px;user-select:none">
            <span data-v="1">☆</span><span data-v="2">☆</span><span data-v="3">☆</span><span data-v="4">☆</span><span data-v="5">☆</span>
          </div>
        </div>
        <div class="field">
          <label for="fb-msg">Your feedback</label>
          <textarea id="fb-msg" maxlength="1000" placeholder="What do you like? What should we improve?"></textarea>
          <div class="help">Published after admin approval · posted as <?= h($user['display_name'] ?: $user['first_name'] ?: 'you') ?></div>
        </div>
        <button class="btn btn-primary btn-block" id="fb-btn" type="button">Submit feedback</button>

        <script>
        (function(){
          var rating = 5;
          var stars = document.querySelectorAll('#star-pick span');
          function paint(){ stars.forEach(function(s,i){ s.textContent = i < rating ? '★' : '☆'; }); }
          stars.forEach(function(s){ s.addEventListener('click', function(){ rating = +s.getAttribute('data-v'); paint(); }); });
          paint();

          var btn = document.getElementById('fb-btn');
          btn.addEventListener('click', function(){
            var msg = document.getElementById('fb-msg').value.trim();
            var err = document.getElementById('fb-error'), ok = document.getElementById('fb-ok');
            err.style.display='none'; ok.style.display='none';
            if (msg.length < 5) { err.textContent = 'Write at least a few words.'; err.style.display='block'; return; }
            btn.disabled = true; btn.textContent = 'Submitting…';
            fetch('/api/feedback.php', {
              method:'POST', headers:{'Content-Type':'application/json'},
              body: JSON.stringify({ message: msg, rating: rating })
            }).then(function(r){return r.json();}).then(function(d){
              if (d.ok) {
                ok.textContent = '✓ Thanks! Your feedback is pending admin approval.';
                ok.style.display='block';
                document.getElementById('fb-msg').value = '';
              } else {
                var m = { 'pending_limit':'You already have 3 pending feedbacks. Wait for approval.',
                          'too_short':'Write at least a few words.', 'auth':'Please log in again.' };
                err.textContent = m[d.error] || 'Something went wrong. Try again.';
                err.style.display='block';
              }
              btn.disabled = false; btn.textContent = 'Submit feedback';
            }).catch(function(){
              err.textContent = 'Network error.'; err.style.display='block';
              btn.disabled = false; btn.textContent = 'Submit feedback';
            });
          });
        })();
        </script>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
