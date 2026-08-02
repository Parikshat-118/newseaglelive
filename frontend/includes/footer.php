</main>

<footer class="footer">
  <div class="container">
    <div class="footer-grid">
      <div class="footer-brand">
        <svg class="brand-mark" viewBox="0 0 32 32" aria-hidden="true"><path d="M16 3 L26 13 L21 17 L26 24 L16 21 L6 24 L11 17 L6 13 Z" fill="currentColor"/></svg>
        <div>
          <div class="footer-name"><?= h($CONFIG['brand']['name']) ?></div>
          <div class="footer-tag"><?= h($CONFIG['brand']['tagline']) ?></div>
        </div>
      </div>
      <div class="footer-cols">
        <div>
          <div class="footer-h">Product</div>
          <a href="/news.php">Browse news</a>
          <a href="/alerts.php">Severe alerts</a>
          <a href="<?= h($CONFIG['brand']['bot_url']) ?>" target="_blank" rel="noopener">Telegram bot</a>
          <a href="/feedback.php">Feedback</a>
        </div>
        <div>
          <div class="footer-h">Community</div>
          <a href="<?= h($CONFIG['social']['whatsapp']) ?>" target="_blank" rel="noopener">WhatsApp Group</a>
          <a href="<?= h($CONFIG['social']['whatsapp_channel']) ?>" target="_blank" rel="noopener">WhatsApp Channel</a>
          <a href="<?= h($CONFIG['social']['discord']) ?>" target="_blank" rel="noopener">Discord</a>
          <a href="<?= h($CONFIG['social']['youtube']) ?>" target="_blank" rel="noopener">YouTube</a>
          <a href="mailto:<?= h($CONFIG['brand']['email']) ?>"><?= h($CONFIG['brand']['email']) ?></a>
        </div>
        <div>
          <div class="footer-h">Follow</div>
          <a href="<?= h($CONFIG['social']['x']) ?>" target="_blank" rel="noopener">X</a>
          <a href="<?= h($CONFIG['social']['instagram']) ?>" target="_blank" rel="noopener">Instagram</a>
          <a href="<?= h($CONFIG['social']['linkedin']) ?>" target="_blank" rel="noopener">LinkedIn</a>
          <a href="<?= h($CONFIG['social']['facebook']) ?>" target="_blank" rel="noopener">Facebook</a>
        </div>
      </div>
    </div>
    <div class="footer-stripe">
      <div class="footer-stripe-text">
        <span>A product of</span>
        <span class="lockup"><?= h($CONFIG['brand']['parent']) ?></span>
        <span>under</span>
        <span class="lockup"><?= h($CONFIG['brand']['umbrella']) ?></span>
      </div>
      <div class="footer-meta"><span>© <?= date('Y') ?></span><span>All rights reserved.</span></div>
    </div>
  </div>
</footer>

<script>
(function(){
  'use strict';
  var ROOT = document.documentElement;
  // Theme toggle
  var t = document.querySelector('.theme-toggle');
  if (t) t.addEventListener('click', function(){
    var cur = ROOT.getAttribute('data-theme') || 'dark';
    var nxt = cur === 'dark' ? 'light' : 'dark';
    ROOT.setAttribute('data-theme', nxt);
    try { localStorage.setItem('ne-theme', nxt); } catch(e) {}
  });

  // Like button
  document.querySelectorAll('[data-like-article]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var aid = btn.getAttribute('data-like-article');
      fetch('/api/like.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ article_id: Number(aid) })
      }).then(function(r){ return r.json(); })
        .then(function(d){
          if (d.ok) {
            btn.classList.toggle('liked', d.liked);
            var cnt = btn.querySelector('[data-count]');
            if (cnt) cnt.textContent = d.like_count;
          } else if (d.error === 'auth') {
            location.href = '/login.php?next=' + encodeURIComponent(location.pathname + location.search);
          }
        }).catch(function(){});
    });
  });

  // Share buttons (event delegation + popup-safe opening)
  document.addEventListener('click', function(e){
    var btn = e.target.closest('[data-share]');
    if (!btn) return;
    e.preventDefault();

    var platform = btn.getAttribute('data-share');
    var url = btn.getAttribute('data-share-url') || location.href;
    var title = btn.getAttribute('data-share-title') || document.title;
    var aid = btn.getAttribute('data-article-id');

    // record share (fire & forget)
    if (aid) {
      try {
        fetch('/api/share.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ article_id: Number(aid), platform: platform })
        }).catch(function(){});
      } catch(err) {}
    }

    if (platform === 'copy') {
      var done = function(){
        var orig = btn.innerHTML;
        btn.innerHTML = '✓ Copied';
        setTimeout(function(){ btn.innerHTML = orig; }, 1500);
      };
      var legacy = function(){
        var ta = document.createElement('textarea');
        ta.value = url;
        ta.style.position = 'fixed'; ta.style.top = '-1000px';
        document.body.appendChild(ta);
        ta.focus(); ta.select(); ta.setSelectionRange(0, 99999);
        var ok = false;
        try { ok = document.execCommand('copy'); } catch(err) {}
        document.body.removeChild(ta);
        if (ok) done(); else window.prompt('Copy this link:', url);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url).then(done).catch(legacy);
      } else { legacy(); }
      return;
    }

    var go = null;
    if (platform === 'whatsapp') {
      go = 'https://wa.me/?text=' + encodeURIComponent(title + ' ' + url);
    } else if (platform === 'telegram') {
      go = 'https://t.me/share/url?url=' + encodeURIComponent(url) + '&text=' + encodeURIComponent(title);
    }
    if (go) {
      // anchor click = treated as navigation, never popup-blocked
      var a = document.createElement('a');
      a.href = go; a.target = '_blank'; a.rel = 'noopener';
      document.body.appendChild(a);
      a.click();
      a.remove();
    }
  });

  // ── Global Live Viewers Tracking ──────────────────────────────────────
  var globalLiveBadge = document.getElementById('global-live-badge');
  var globalLiveCountEl = document.getElementById('global-live-count');
  
  if (globalLiveBadge && globalLiveCountEl) {
      var globalLiveInterval = null;

      function pingGlobalLiveViewers() {
          if (document.hidden) return; // Paused while tab is inactive
          
          fetch('/api/live-viewers.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({}) // no article_id, global tracking
          })
          .then(function(r) { return r.json(); })
          .then(function(d) {
              if (d.success) {
                  if (globalLiveCountEl.textContent !== String(d.count)) {
                      globalLiveCountEl.style.opacity = '0';
                      setTimeout(function() {
                          globalLiveCountEl.textContent = d.count;
                          globalLiveCountEl.style.opacity = '1';
                      }, 200);
                  }
                  if (globalLiveBadge.style.display === 'none') {
                      globalLiveBadge.style.display = 'inline-flex';
                      globalLiveBadge.style.opacity = '0';
                      setTimeout(function() { globalLiveBadge.style.opacity = '1'; }, 50);
                  }
              }
          })
          .catch(function(e) { /* ignore silently, keep last known number */ });
      }

      // Initial ping and set interval
      pingGlobalLiveViewers();
      globalLiveInterval = setInterval(pingGlobalLiveViewers, 20000);

      document.addEventListener('visibilitychange', function() {
          if (!document.hidden) {
              // Immediately ping when returning to tab
              pingGlobalLiveViewers();
          }
      });
  }

})();
</script>
</body>
</html>
