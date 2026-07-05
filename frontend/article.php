<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: /news.php'); exit; }

$db = ne_db();
$a = null;
if ($db) {
    try {
        $stmt = $db->prepare(
            "SELECT id, title, summary, ai_summary, ai_summary_hi, content, image_url, url,
                    category, source_name, published_at, view_count, like_count, share_count, language
             FROM news_articles WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $a = $stmt->fetch();
    } catch (Throwable $e) { error_log('[article] ' . $e->getMessage()); }
}

if (!$a) {
    http_response_code(404);
    $PAGE_TITLE = 'Article not found';
    include __DIR__ . '/includes/header.php';
    echo '<section class="section"><div class="container" style="text-align:center;padding:4rem 0">';
    echo '<h1 class="section-title" style="margin-bottom:1rem">404 — Article not found</h1>';
    echo '<a class="btn btn-primary" href="/news.php">Back to news</a></div></section>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ── VIEW COUNTER (dedupe: 1 view per IP/user per article per 6h) ────
$user = ne_current_user();
try {
    $ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $dupe = $db->prepare(
        "SELECT 1 FROM article_views
         WHERE article_id = :a AND viewed_at >= (NOW() - INTERVAL 6 HOUR)
           AND ((:u IS NOT NULL AND user_id = :u) OR (ip_hash = :ip))
         LIMIT 1"
    );
    $dupe->execute([':a' => $id, ':u' => $user['id'] ?? null, ':ip' => $ipHash]);
    if (!$dupe->fetch()) {
        $db->prepare("INSERT INTO article_views (article_id, user_id, ip_hash) VALUES (:a, :u, :ip)")
           ->execute([':a' => $id, ':u' => $user['id'] ?? null, ':ip' => $ipHash]);
        $db->prepare("UPDATE news_articles SET view_count = view_count + 1 WHERE id = :a")
           ->execute([':a' => $id]);
        $a['view_count']++;
    }
} catch (Throwable $e) { /* non-fatal */ }

// Has this user already liked?
$userLiked = false;
if ($user) {
    try {
        $ls = $db->prepare("SELECT 1 FROM article_likes WHERE article_id = :a AND user_id = :u");
        $ls->execute([':a' => $id, ':u' => $user['id']]);
        $userLiked = (bool)$ls->fetch();
    } catch (Throwable $e) {}
}

$PAGE_TITLE = $a['title'];
$PAGE_DESCRIPTION = mb_strimwidth($a['summary'] ?? $a['title'], 0, 150, '…');
$shareUrl = $CONFIG['brand']['site_url'] . '/article.php?id=' . $id;

include __DIR__ . '/includes/header.php';
?>

<article class="article-page">
  <div class="container article-hero">
    <a class="article-back" href="/news.php">← Back to news</a>

    <?php if (!empty($a['category'])): ?>
      <div><span class="article-cat-tag"><?= h(str_replace('_', ' ', $a['category'])) ?></span></div>
    <?php endif; ?>

    <h1 class="article-h1"><?= h($a['title']) ?></h1>

    <div class="article-byline">
      <span><?= h($a['source_name'] ?? 'News Eagle Live') ?></span>
      <span><?= $a['published_at'] ? date('M j, Y · g:i A', strtotime($a['published_at'])) : '' ?></span>
      <span>👁 <?= number_format((int)$a['view_count']) ?> views</span>
      <span>❤ <span id="like-count-meta"><?= number_format((int)$a['like_count']) ?></span> likes</span>
    </div>

    <?php if (!empty($a['image_url'])): ?>
      <div class="article-hero-img" style="background-image:url('<?= h($a['image_url']) ?>')"></div>
    <?php endif; ?>

    <!-- LIKE + SHARE bar -->
    <div class="article-actions" style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center">
      <button class="action-btn<?= $userLiked ? ' liked' : '' ?>" data-like-article="<?= $id ?>" type="button" style="min-width:80px;justify-content:center">
        <svg viewBox="0 0 24 24" fill="<?= $userLiked ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
        <span>Like</span> <span data-count><?= (int)$a['like_count'] ?></span>
      </button>
      <div class="lang-dropdown-wrap" id="lang-dropdown-wrap">
        <button class="action-btn lang-trigger" id="lang-trigger" type="button" style="min-width:160px;justify-content:space-between;border-color:var(--highlight);color:var(--highlight);gap:0.6rem">
          <span style="display:flex;align-items:center;gap:0.5rem">
            <span class="lang-badge" id="lang-badge-selected">En</span>
            <span id="lang-label-selected">English</span>
          </span>
          <svg width="12" height="12" viewBox="0 0 12 12" style="flex-shrink:0;opacity:.7"><path fill="currentColor" d="M2 4l4 4 4-4"/></svg>
        </button>
      </div>
      <button class="action-btn" id="ai-explain-btn" data-aid="<?= $id ?>" type="button" style="min-width:140px;justify-content:center;border-color:var(--highlight);color:var(--highlight)">
        ✨ <span>Explain with AI</span>
      </button>
      <button class="action-btn" data-share="whatsapp" data-article-id="<?= $id ?>" data-share-url="<?= h($shareUrl) ?>" data-share-title="<?= h($a['title']) ?>" type="button" style="min-width:100px;justify-content:center">
        <svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413"/></svg>
        <span>WhatsApp</span>
      </button>
      <button class="action-btn" data-share="telegram" data-article-id="<?= $id ?>" data-share-url="<?= h($shareUrl) ?>" data-share-title="<?= h($a['title']) ?>" type="button" style="min-width:100px;justify-content:center">
        <svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.532.818-1.084.508l-3-2.21-1.446 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.022c.242-.213-.054-.334-.373-.121l-6.871 4.326-2.962-.924c-.643-.204-.657-.643.136-.953l11.566-4.458c.538-.196 1.006.128.832.938z"/></svg>
        <span>Telegram</span>
      </button>
      <button class="action-btn" data-share="copy" data-article-id="<?= $id ?>" data-share-url="<?= h($shareUrl) ?>" type="button" style="min-width:100px;justify-content:center">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
        <span>Copy link</span>
      </button>
    </div>

    <div class="article-content">
      <?php if (!empty($a['summary'])): ?>
        <?php foreach (preg_split('/\n+/', $a['summary']) as $p): if (trim($p) === '') continue; ?>
          <p><?= h($p) ?></p>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if (!empty($a['url'])): ?>
        <p style="margin-top:2rem">
          <a class="btn btn-ghost" href="<?= h($a['url']) ?>" target="_blank" rel="noopener nofollow">
            Read full story at <?= h($a['source_name'] ?? 'source') ?> ↗
          </a>
        </p>
      <?php endif; ?>
    </div>


    <div id="ai-explain-box" style="display:none;margin-top:1.5rem;padding:1.25rem;background:var(--surface);border:1px solid var(--highlight);border-radius:14px">
      <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.75rem">
        <span class="ai-tag" style="background:var(--highlight);color:#000">✨ AI EXPLANATION</span>
        <span id="ai-explain-lang-label" style="font-size:0.8rem;color:var(--text-soft);margin-left:auto"></span>
      </div>
      <div id="ai-explain-result" style="color:var(--text-soft);white-space:pre-line;line-height:1.7"></div>
    </div>

<style>
/* ═══ LANGUAGE DROPDOWN ═══ */
.lang-dropdown-wrap{display:inline-block;position:relative}
.lang-trigger{cursor:pointer}
.lang-badge{display:inline-flex;align-items:center;justify-content:center;min-width:26px;height:26px;padding:0 5px;background:color-mix(in srgb,var(--highlight) 18%,transparent);color:var(--highlight);border-radius:6px;font-family:var(--ff-mono);font-size:11px;font-weight:700;letter-spacing:.02em;line-height:1}

/* ── Modal (appended to body by JS) ── */
.lang-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px)}
.lang-overlay.open{display:block}
.lang-modal{display:none;position:fixed;z-index:100001;background:var(--surface);overflow:hidden;flex-direction:column;border:1px solid var(--border)}
.lang-modal.open{display:flex}

/* Desktop: centered floating card */
@media(min-width:641px){
  .lang-modal{top:50%;left:50%;transform:translate(-50%,-50%);width:380px;max-height:520px;border-radius:16px;box-shadow:0 25px 80px rgba(0,0,0,.5);animation:langPop .2s ease}
  @keyframes langPop{from{opacity:0;transform:translate(-50%,-50%) scale(.95)}to{opacity:1;transform:translate(-50%,-50%) scale(1)}}
}
/* Mobile: full-screen takeover */
@media(max-width:640px){
  .lang-modal{inset:0;width:100%;height:100%;border-radius:0;border:none;animation:langSlideUp .25s cubic-bezier(.2,.7,.2,1)}
  @keyframes langSlideUp{from{transform:translateY(100%)}to{transform:translateY(0)}}
}

.lang-modal-header{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;padding-top:calc(16px + env(safe-area-inset-top, 0px));border-bottom:1px solid var(--border);flex-shrink:0;background:var(--surface)}
.lang-modal-title{font-family:var(--ff-display);font-weight:700;font-size:1.15rem;color:var(--text)}
.lang-modal-close{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%;background:var(--surface2);border:1px solid var(--border);color:var(--text);font-size:1.1rem;cursor:pointer;transition:background .15s ease;-webkit-tap-highlight-color:transparent}
.lang-modal-close:hover{background:var(--border)}
.lang-modal-close:active{background:var(--accent);color:#fff;border-color:var(--accent)}

.lang-search-wrap{display:flex;align-items:center;gap:.5rem;padding:12px 18px;border-bottom:1px solid var(--border);background:var(--surface2);flex-shrink:0}
.lang-search{flex:1;background:transparent;border:none;outline:none;color:var(--text);font-size:16px;padding:2px 0}
.lang-search::placeholder{color:var(--muted)}
.lang-list{flex:1;overflow-y:auto;padding:8px;-webkit-overflow-scrolling:touch}
.lang-list::-webkit-scrollbar{width:5px}
.lang-list::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px}
.lang-item{display:flex;align-items:center;gap:.7rem;padding:11px 14px;border-radius:10px;cursor:pointer;transition:background .12s ease;font-size:15px;-webkit-tap-highlight-color:transparent}
.lang-item:hover{background:color-mix(in srgb,var(--highlight) 12%,transparent)}
.lang-item:active{background:color-mix(in srgb,var(--highlight) 25%,transparent)}
.lang-item.active{background:color-mix(in srgb,var(--highlight) 20%,transparent);color:var(--highlight)}
.lang-item .lang-badge{flex-shrink:0}
.lang-native{color:var(--text);font-weight:500}
.lang-english{color:var(--muted);font-size:12px;margin-left:auto;white-space:nowrap}
.lang-no-results{padding:2rem;text-align:center;color:var(--muted);font-size:14px}
body.lang-modal-open{overflow:hidden!important}
</style>

    <script>
    (function(){
      // ── Language Data (50 languages, alphabetical by English name) ──
      var LANGS = [
        {code:'am',name:'Amharic',native:'አማርኛ',script:'አ'},
        {code:'ar',name:'Arabic',native:'العربية',script:'ع'},
        {code:'bn',name:'Bengali',native:'বাংলা',script:'ব'},
        {code:'bg',name:'Bulgarian',native:'Български',script:'Б'},
        {code:'my',name:'Burmese',native:'မြန်မာ',script:'မ'},
        {code:'zh',name:'Chinese (Simplified)',native:'中文（简体）',script:'简'},
        {code:'zh-tw',name:'Chinese (Traditional)',native:'中文（繁體）',script:'繁'},
        {code:'hr',name:'Croatian',native:'Hrvatski',script:'Hr'},
        {code:'cs',name:'Czech',native:'Čeština',script:'Čs'},
        {code:'da',name:'Danish',native:'Dansk',script:'Da'},
        {code:'nl',name:'Dutch',native:'Nederlands',script:'Nl'},
        {code:'en',name:'English',native:'English',script:'En'},
        {code:'fil',name:'Filipino',native:'Filipino',script:'Fl'},
        {code:'fi',name:'Finnish',native:'Suomi',script:'Fi'},
        {code:'fr',name:'French',native:'Français',script:'Fr'},
        {code:'de',name:'German',native:'Deutsch',script:'De'},
        {code:'el',name:'Greek',native:'Ελληνικά',script:'Ε'},
        {code:'gu',name:'Gujarati',native:'ગુજરાતી',script:'ગ'},
        {code:'he',name:'Hebrew',native:'עברית',script:'א'},
        {code:'hi',name:'Hindi',native:'हिन्दी',script:'हि'},
        {code:'hu',name:'Hungarian',native:'Magyar',script:'Hu'},
        {code:'id',name:'Indonesian',native:'Bahasa Indonesia',script:'Id'},
        {code:'it',name:'Italian',native:'Italiano',script:'It'},
        {code:'ja',name:'Japanese',native:'日本語',script:'日'},
        {code:'kn',name:'Kannada',native:'ಕನ್ನಡ',script:'ಕ'},
        {code:'ko',name:'Korean',native:'한국어',script:'한'},
        {code:'ms',name:'Malay',native:'Bahasa Melayu',script:'Ms'},
        {code:'ml',name:'Malayalam',native:'മലയാളം',script:'മ'},
        {code:'mr',name:'Marathi',native:'मराठी',script:'म'},
        {code:'ne',name:'Nepali',native:'नेपाली',script:'ने'},
        {code:'no',name:'Norwegian',native:'Norsk',script:'No'},
        {code:'fa',name:'Persian',native:'فارسی',script:'فا'},
        {code:'pl',name:'Polish',native:'Polski',script:'Pl'},
        {code:'pt',name:'Portuguese',native:'Português',script:'Pt'},
        {code:'pa',name:'Punjabi',native:'ਪੰਜਾਬੀ',script:'ਪ'},
        {code:'ro',name:'Romanian',native:'Română',script:'Ro'},
        {code:'ru',name:'Russian',native:'Русский',script:'Р'},
        {code:'sr',name:'Serbian',native:'Српски',script:'С'},
        {code:'si',name:'Sinhala',native:'සිංහල',script:'ස'},
        {code:'sk',name:'Slovak',native:'Slovenčina',script:'Sk'},
        {code:'es',name:'Spanish',native:'Español',script:'Es'},
        {code:'sw',name:'Swahili',native:'Kiswahili',script:'Sw'},
        {code:'sv',name:'Swedish',native:'Svenska',script:'Sv'},
        {code:'ta',name:'Tamil',native:'தமிழ்',script:'த'},
        {code:'te',name:'Telugu',native:'తెలుగు',script:'తె'},
        {code:'th',name:'Thai',native:'ไทย',script:'ก'},
        {code:'tr',name:'Turkish',native:'Türkçe',script:'Tr'},
        {code:'uk',name:'Ukrainian',native:'Українська',script:'У'},
        {code:'ur',name:'Urdu',native:'اردو',script:'ا'},
        {code:'vi',name:'Vietnamese',native:'Tiếng Việt',script:'Vi'}
      ];

      var selectedLang = LANGS.find(function(l){ return l.code === 'en'; });
      var trigger = document.getElementById('lang-trigger');
      var badgeSel = document.getElementById('lang-badge-selected');
      var labelSel = document.getElementById('lang-label-selected');
      var btn = document.getElementById('ai-explain-btn');
      if (!trigger || !btn) return;

      // ── Build modal HTML and append to <body> ──────────────────────
      var overlay = document.createElement('div');
      overlay.className = 'lang-overlay';
      var modal = document.createElement('div');
      modal.className = 'lang-modal';
      modal.innerHTML =
        '<div class="lang-modal-header">' +
          '<span class="lang-modal-title">Select Language</span>' +
          '<button type="button" class="lang-modal-close" aria-label="Close">✕</button>' +
        '</div>' +
        '<div class="lang-search-wrap">' +
          '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;opacity:.5"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>' +
          '<input type="text" class="lang-search" placeholder="Search languages..." autocomplete="off" spellcheck="false">' +
        '</div>' +
        '<div class="lang-list"></div>';
      document.body.appendChild(overlay);
      document.body.appendChild(modal);

      var closeBtn = modal.querySelector('.lang-modal-close');
      var search = modal.querySelector('.lang-search');
      var list = modal.querySelector('.lang-list');

      // ── Render language list ───────────────────────────────────────
      function renderList(filter) {
        var q = (filter || '').toLowerCase().trim();
        var html = '';
        var count = 0;
        LANGS.forEach(function(l){
          if (q && l.name.toLowerCase().indexOf(q) === -1
              && l.native.toLowerCase().indexOf(q) === -1
              && l.code.indexOf(q) === -1) return;
          count++;
          var isActive = selectedLang && selectedLang.code === l.code;
          html += '<div class="lang-item' + (isActive ? ' active' : '') + '" data-code="' + l.code + '">'
            + '<span class="lang-badge">' + l.script + '</span>'
            + '<span class="lang-native">' + l.native + '</span>'
            + '<span class="lang-english">' + l.name + '</span>'
            + '</div>';
        });
        if (count === 0) {
          html = '<div class="lang-no-results">No languages found</div>';
        }
        list.innerHTML = html;
      }

      // ── Open / Close ──────────────────────────────────────────────
      function openModal(){
        overlay.classList.add('open');
        modal.classList.add('open');
        document.body.classList.add('lang-modal-open');
        search.value = '';
        renderList('');
        setTimeout(function(){ search.focus(); }, 80);
      }
      function closeModal(){
        overlay.classList.remove('open');
        modal.classList.remove('open');
        document.body.classList.remove('lang-modal-open');
      }

      trigger.addEventListener('click', function(e){
        e.stopPropagation();
        openModal();
      });
      closeBtn.addEventListener('click', closeModal);
      overlay.addEventListener('click', closeModal);
      document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') closeModal();
      });

      // ── Search filtering ──────────────────────────────────────────
      search.addEventListener('input', function(){
        renderList(this.value);
      });

      // ── Select a language ─────────────────────────────────────────
      list.addEventListener('click', function(e){
        var item = e.target.closest('.lang-item');
        if (!item) return;
        var code = item.getAttribute('data-code');
        selectedLang = LANGS.find(function(l){ return l.code === code; });
        if (selectedLang) {
          badgeSel.textContent = selectedLang.script;
          labelSel.textContent = selectedLang.native;
        }
        closeModal();
      });

      // Initial render
      renderList('');

      // ── AI Explain button ─────────────────────────────────────────
      btn.addEventListener('click', function(){
        btn.disabled = true;
        trigger.style.pointerEvents = 'none';
        btn.querySelector('span').textContent = 'Thinking…';

        fetch('/api/ai-explain.php', {
          method:'POST', headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ article_id: +btn.getAttribute('data-aid'), lang: selectedLang.code })
        }).then(function(r){return r.json();}).then(function(d){
          if (d.ok) {
            document.getElementById('ai-explain-result').textContent = d.summary || '';
            document.getElementById('ai-explain-lang-label').textContent = selectedLang.native + ' (' + selectedLang.name + ')';
            document.getElementById('ai-explain-box').style.display = 'block';
            document.getElementById('ai-explain-box').scrollIntoView({behavior:'smooth', block:'nearest'});
            btn.querySelector('span').textContent = 'Explain with AI';
            btn.disabled = false;
            trigger.style.pointerEvents = '';
          } else {
            btn.querySelector('span').textContent = 'Try again';
            btn.disabled = false;
            trigger.style.pointerEvents = '';
            if (d.error === 'php_curl_missing') alert('Server needs php-curl: ' + (d.hint||''));
            else if (d.detail) console.warn('AI explain failed:', d.error, d.detail);
          }
        }).catch(function(){
          btn.querySelector('span').textContent = 'Try again';
          btn.disabled = false;
          trigger.style.pointerEvents = '';
        });
      });
    })();
    </script>

    <?php if (!$user): ?>
      <div style="margin-top:2rem;padding:1.25rem;background:var(--surface);border:1px solid var(--border);border-radius:14px;text-align:center">
        <p style="margin:0 0 .75rem;color:var(--text-soft)">Log in to like articles and leave feedback.</p>
        <a class="btn btn-primary" href="/login.php?next=<?= urlencode('/article.php?id=' . $id) ?>">Log in with Telegram →</a>
      </div>
    <?php endif; ?>

  </div>
</article>

<?php
// ── Related articles (same category, recent) ─────────────────────────
$related = [];
if ($db && !empty($a['category'])) {
    try {
        $rs = $db->prepare(
            "SELECT id, title, image_url, category, published_at, view_count, like_count
             FROM news_articles
             WHERE category = :c AND id != :id
             ORDER BY published_at DESC LIMIT 3"
        );
        $rs->execute([':c' => $a['category'], ':id' => $id]);
        $related = $rs->fetchAll();
    } catch (Throwable $e) {}
}
if ($related): ?>
<section class="section">
  <div class="container">
    <h2 class="section-title" style="font-size:var(--fs-xl);margin-bottom:1.5rem">More in <?= h(str_replace('_', ' ', $a['category'])) ?></h2>
    <div class="article-grid">
      <?php foreach ($related as $r): ?>
        <a class="article-card" href="/article.php?id=<?= (int)$r['id'] ?>">
          <div class="article-img" <?php if (!empty($r['image_url'])): ?>style="background-image:url('<?= h($r['image_url']) ?>')"<?php endif; ?>>
            <?php if (empty($r['image_url'])): ?><div class="article-img-placeholder">News Eagle</div><?php endif; ?>
          </div>
          <div class="article-body">
            <h3 class="article-title"><?= h($r['title']) ?></h3>
            <div class="article-meta">
              <span><?= ne_time_ago($r['published_at']) ?></span>
              <span class="article-stats"><span>👁 <?= (int)$r['view_count'] ?></span><span>❤ <?= (int)$r['like_count'] ?></span></span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
