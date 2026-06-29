<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

// Already logged in? Bounce home.
if (ne_current_user()) {
    header('Location: ' . ($_GET['next'] ?? '/'));
    exit;
}

$PAGE_TITLE = 'Log in';
$PAGE_DESCRIPTION = 'Log in with your mobile number and a code from the Telegram bot.';
$next = $_GET['next'] ?? '/';

include __DIR__ . '/includes/header.php';
?>

<section class="auth-page">
  <div class="container" style="display:flex;justify-content:center">
    <div class="auth-card">
      <div class="auth-eyebrow">Telegram-OTP Login</div>
      <h1 class="auth-title">No password. No SMS.<br>Your bot is the key. 🔐</h1>

      <ol class="auth-steps">
        <li class="auth-step">
          <span class="auth-step-n">1</span>
          <span>Open <a href="<?= h($CONFIG['brand']['bot_url']) ?>" target="_blank" rel="noopener" style="color:var(--accent);text-decoration:underline">@<?= h($CONFIG['brand']['bot']) ?></a> on Telegram and send <span class="mono">/webauth</span></span>
        </li>
        <li class="auth-step">
          <span class="auth-step-n">2</span>
          <span>The bot replies with a <strong>6-digit code</strong> (valid 3 minutes)</span>
        </li>
        <li class="auth-step">
          <span class="auth-step-n">3</span>
          <span>Enter your mobile number + that code below</span>
        </li>
      </ol>

      <div class="auth-divider" data-label="LOG IN"></div>

      <div id="login-error" class="flash flash-err" style="display:none"></div>
      <div id="login-ok" class="flash flash-ok" style="display:none"></div>

      <div class="field">
        <label for="mobile">Mobile number</label>
        <input type="tel" id="mobile" inputmode="numeric" maxlength="10" placeholder="98765 43210" autocomplete="tel-national">
        <div class="help">The number you linked with <span class="mono">/setmobile</span> on the bot</div>
      </div>

      <div class="field">
        <label for="otp">6-digit code from Telegram</label>
        <input type="text" id="otp" class="otp-input" inputmode="numeric" maxlength="6" placeholder="••••••" autocomplete="one-time-code">
        <div class="help">Get it by sending <span class="mono">/webauth</span> to the bot · expires in 3 min</div>
      </div>

      <button class="btn btn-primary btn-block" id="login-btn" type="button">
        <span id="login-btn-text">Verify &amp; log in</span>
      </button>

      <div class="auth-divider" data-label="FIRST TIME?"></div>
      <p style="margin:0;color:var(--text-soft);font-size:var(--fs-sm);text-align:center">
        1) Open the bot → <span class="mono">/start</span> ·
        2) <span class="mono">/setmobile &lt;your number&gt;</span> ·
        3) <span class="mono">/webauth</span> → come back here.
      </p>
    </div>
  </div>
</section>

<script>
(function(){
  'use strict';
  var btn = document.getElementById('login-btn');
  var btnText = document.getElementById('login-btn-text');
  var errBox = document.getElementById('login-error');
  var okBox = document.getElementById('login-ok');
  var next = <?= json_encode($next) ?>;

  function showErr(msg){ errBox.textContent = msg; errBox.style.display='block'; okBox.style.display='none'; }
  function showOk(msg){ okBox.textContent = msg; okBox.style.display='block'; errBox.style.display='none'; }

  // Only digits in inputs
  ['mobile','otp'].forEach(function(id){
    document.getElementById(id).addEventListener('input', function(){
      this.value = this.value.replace(/\D/g,'');
    });
  });

  // Enter key submits
  document.getElementById('otp').addEventListener('keydown', function(e){
    if (e.key === 'Enter') btn.click();
  });

  btn.addEventListener('click', function(){
    var mobile = document.getElementById('mobile').value.trim();
    var code = document.getElementById('otp').value.trim();

    if (!/^[6-9]\d{9}$/.test(mobile)) { showErr('Enter a valid 10-digit Indian mobile number.'); return; }
    if (!/^\d{6}$/.test(code)) { showErr('Enter the 6-digit code from the Telegram bot.'); return; }

    btn.disabled = true; btnText.textContent = 'Verifying…';

    fetch('/api/verify-otp.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ mobile: mobile, code: code })
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (d.ok) {
        showOk('✓ Logged in! Redirecting…');
        setTimeout(function(){ location.href = next || '/'; }, 700);
      } else {
        var msgs = {
          'invalid':   'Wrong or expired code. Send /webauth to the bot for a fresh one.',
          'expired':   'Code expired (3 min limit). Send /webauth again.',
          'no_user':   'No account found for this mobile. On the bot: /start then /setmobile ' + mobile,
          'too_many':  'Too many attempts. Get a new code with /webauth.',
          'rate':      'Too many tries. Wait a minute and try again.'
        };
        showErr(msgs[d.error] || 'Login failed. Try /webauth again on the bot.');
        btn.disabled = false; btnText.textContent = 'Verify & log in';
      }
    })
    .catch(function(){
      showErr('Network error. Try again.');
      btn.disabled = false; btnText.textContent = 'Verify & log in';
    });
  });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
