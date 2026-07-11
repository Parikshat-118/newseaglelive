<?php
/**
 * Shared HTML layout — included at the top of every page.
 * The page that includes this should set $PAGE_TITLE and (optionally)
 * $PAGE_DESCRIPTION before include.
 */
if (!isset($CONFIG)) {
    require_once __DIR__ . '/bootstrap.php';
}
$user        = ne_current_user();
$pageTitle   = $PAGE_TITLE       ?? $CONFIG['brand']['name'];
$pageDesc    = $PAGE_DESCRIPTION ?? 'AI-curated breaking news for India on Telegram.';
$activeAlert = ne_active_alert();

// Ticker headlines (server-side so it always renders, JS refreshes later)
$tickerItems = [];
try {
    $tdb = ne_db();
    if ($tdb) {
        $tickerItems = $tdb->query(
            "SELECT id, title, source_name FROM news_articles
             ORDER BY published_at DESC LIMIT 15"
        )->fetchAll();
    }
} catch (Throwable $e) {}
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= h($pageTitle) ?> — <?= h($CONFIG['brand']['name']) ?></title>
<meta name="description" content="<?= h($pageDesc) ?>">
<meta name="theme-color" content="#0A0E1A" media="(prefers-color-scheme:dark)">
<meta name="theme-color" content="#F5EFE5" media="(prefers-color-scheme:light)">
<meta property="og:title" content="<?= h($pageTitle) ?>">
<meta property="og:description" content="<?= h($pageDesc) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= h($CONFIG['brand']['name']) ?>">

<script>(function(){try{var t=localStorage.getItem('ne-theme');if(!t)t=matchMedia('(prefers-color-scheme:light)').matches?'light':'dark';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,700;12..96,800&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&family=Noto+Serif+Devanagari:wght@500;700&display=swap" rel="stylesheet">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="icon" type="image/png" sizes="192x192" href="/favicon-192x192.png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<style>
/* ═══ TOKENS ═══ */
:root,[data-theme="dark"]{
  --bg:#0A0E1A;--bg2:#0E1320;--surface:#131825;--surface2:#1A2030;
  --border:#232A3B;--border-soft:#1A2030;
  --text:#E8E6E1;--text-soft:#B6BBC4;--muted:#6B7280;
  --accent:#E8504C;--accent-soft:#401D1F;--highlight:#DAA520;--live:#00D4AA;
  --shadow:0 8px 24px rgba(0,0,0,.35);
}
[data-theme="light"]{
  --bg:#F5EFE5;--bg2:#FAF6EE;--surface:#FFFFFF;--surface2:#F3EDE2;
  --border:#DDD3BF;--border-soft:#E8E0CE;
  --text:#1A1D24;--text-soft:#3F4654;--muted:#6B7280;
  --accent:#C13B30;--accent-soft:#F4D5D1;--highlight:#A8821B;--live:#00966F;
  --shadow:0 8px 24px rgba(20,20,30,.08);
}
:root{
  --ff-display:'Bricolage Grotesque','Inter',system-ui,sans-serif;
  --ff-body:'Inter',system-ui,sans-serif;
  --ff-mono:'JetBrains Mono',ui-monospace,'SF Mono',monospace;
  --ff-deva:'Noto Serif Devanagari','Inter',serif;
  --fs-xs:clamp(.72rem,.65rem + .2vw,.78rem);
  --fs-sm:clamp(.84rem,.78rem + .25vw,.92rem);
  --fs-base:clamp(.95rem,.9rem + .3vw,1.05rem);
  --fs-lg:clamp(1.1rem,1rem + .45vw,1.25rem);
  --fs-xl:clamp(1.45rem,1.2rem + 1.1vw,1.95rem);
  --fs-2xl:clamp(2.1rem,1.6rem + 2.2vw,3.2rem);
  --fs-3xl:clamp(2.8rem,2rem + 4vw,5rem);
  --ease:cubic-bezier(.2,.7,.2,1);--dur:220ms;
}
*,*::before,*::after{box-sizing:border-box}
html,body{margin:0;padding:0}html{scroll-behavior:smooth}
body{background:var(--bg);color:var(--text);font-family:var(--ff-body);font-size:var(--fs-base);line-height:1.55;-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility;transition:background var(--dur) var(--ease),color var(--dur) var(--ease);min-height:100vh;display:flex;flex-direction:column}
@media (prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;transition-duration:.01ms!important}html{scroll-behavior:auto}}
a{color:inherit;text-decoration:none;transition:color var(--dur) var(--ease)}
a:hover{color:var(--accent)}
button{font:inherit;cursor:pointer;border:none;background:none;color:inherit}
input,textarea,select{font:inherit;color:inherit}
img,svg{display:block;max-width:100%}
::selection{background:var(--accent);color:#fff}
.mono{font-family:var(--ff-mono);font-feature-settings:"tnum" 1;letter-spacing:.02em}
.deva{font-family:var(--ff-deva)}
.container{max-width:1200px;margin:0 auto;padding:0 clamp(1rem,3vw,2rem);width:100%}
main{flex:1}


/* ═══ TICKER (responsive) ═══ */
.ticker{display:flex;align-items:center;background:#000;color:#fff;height:36px;overflow:hidden;border-bottom:1px solid var(--border)}
[data-theme="light"] .ticker{background:#1A1D24}
.ticker-tag{flex-shrink:0;display:flex;align-items:center;gap:.45rem;padding:0 .85rem;height:100%;background:var(--accent);font-family:var(--ff-mono);font-weight:700;font-size:10px;letter-spacing:.15em}
.ticker-track-wrap{display:flex;flex:1;overflow:hidden;-webkit-mask-image:linear-gradient(90deg,transparent 0,#000 20px,#000 calc(100% - 20px),transparent 100%);mask-image:linear-gradient(90deg,transparent 0,#000 20px,#000 calc(100% - 20px),transparent 100%)}
.ticker-content{display:flex;align-items:center;flex-shrink:0;gap:2rem;padding-left:2rem;font-family:var(--ff-mono);font-size:12px;white-space:nowrap;animation:tscroll 80s linear infinite}
.ticker:hover .ticker-content{animation-play-state:paused}
@keyframes tscroll{from{transform:translateX(0)}to{transform:translateX(-100%)}}
.ticker-item{display:inline-flex;align-items:center;gap:.5rem}
.ticker-dot{color:var(--accent)}
.ticker a{color:#fff}.ticker a:hover{color:var(--highlight)}
.ticker-src{color:rgba(255,255,255,.45);font-size:10px}
@media (max-width:640px){
  .ticker{height:32px}
  .ticker-content{font-size:11px;animation-duration:60s}
  .ticker-tag{padding:0 .6rem}
}

/* ═══ MOBILE NAV (hamburger) ═══ */
.nav-burger{display:none;width:38px;height:38px;background:var(--surface);border:1px solid var(--border);border-radius:8px;place-items:center;cursor:pointer}
.nav-burger svg{width:20px;height:20px}
.mobile-menu{display:none;position:fixed;inset:0;z-index:60;background:color-mix(in srgb,var(--bg) 96%,transparent);backdrop-filter:blur(8px);padding:1.25rem;flex-direction:column}
.mobile-menu.open{display:flex}
.mobile-menu-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem}
.mobile-menu a.mlink{display:block;padding:.9rem 1rem;font-family:var(--ff-display);font-weight:600;font-size:1.15rem;color:var(--text);border-bottom:1px solid var(--border-soft)}
.mobile-menu a.mlink:active{color:var(--accent)}
@media (max-width:820px){
  .nav-burger{display:grid}
  .cta-mini{display:none}
  .avatar-pill span:not(.av){display:none}
}

/* ═══ MOBILE/TABLET GLOBAL FIXES ═══ */
.table-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
.admin-table{min-width:560px}
@media (max-width:640px){
  .container{padding:0 1rem}
  .nav{height:56px;gap:.5rem}
  .section{padding:2rem 0}
  .section-title{margin-bottom:1.5rem}
  .hero{padding:2rem 0 1.5rem}
  .hero-emblem{max-width:260px !important}
  .article-actions{gap:.4rem}
  .action-btn{padding:8px 10px;font-size:13px}
  .auth-card{padding:1.25rem}
  .admin-tabs{gap:.25rem}
  .admin-tab{padding:.6rem .7rem;font-size:13px}
  input,textarea,select,button{font-size:16px !important} /* prevents iOS zoom */
}
/* ═══ ALERT BANNER ═══ */
.alert-bar{background:linear-gradient(90deg,#DA2A22,#E8504C);color:#fff;font-family:var(--ff-mono);font-size:var(--fs-xs);padding:8px 1rem;text-align:center;letter-spacing:.05em}
.alert-bar a{color:#fff;text-decoration:underline}
.alert-bar strong{font-family:var(--ff-display);font-weight:700;letter-spacing:0;text-transform:uppercase;margin-right:.5rem}

/* ═══ NAV ═══ */
.nav{position:sticky;top:0;z-index:40;display:flex;align-items:center;gap:1rem;height:64px;padding:0 clamp(1rem,3vw,2rem);background:color-mix(in srgb,var(--bg) 92%,transparent);backdrop-filter:saturate(180%) blur(12px);-webkit-backdrop-filter:saturate(180%) blur(12px);border-bottom:1px solid var(--border-soft)}
.brand{display:flex;align-items:center;gap:.75rem;color:var(--accent);font-family:var(--ff-display);font-weight:700;font-size:var(--fs-lg)}
.brand-mark{width:28px;height:28px;flex-shrink:0;color:var(--accent)}
.brand-text{display:flex;align-items:baseline;gap:6px;color:var(--text)}
.brand-live{font-family:var(--ff-mono);font-size:var(--fs-xs);letter-spacing:.18em;color:var(--accent);padding:2px 6px;border:1px solid var(--accent);border-radius:3px}
.nav-links{display:flex;align-items:center;gap:1.25rem;margin-left:auto;font-size:var(--fs-sm)}
.nav-links a{color:var(--text-soft)}.nav-links a:hover,.nav-links a.active{color:var(--text)}
.nav-actions{display:flex;align-items:center;gap:.5rem}
.nav-search{display:flex;align-items:center;gap:.35rem;height:38px;padding:0 .45rem 0 .7rem;background:var(--surface);border:1px solid var(--border);border-radius:10px}
.nav-search input{width:180px;background:transparent;border:none;outline:none;color:var(--text);font-size:var(--fs-sm)}
.nav-search input::placeholder{color:var(--muted)}
.nav-search button{display:inline-grid;place-items:center;width:28px;height:28px;border-radius:8px;color:var(--text-soft)}
.nav-search button:hover{color:var(--accent)}
.nav-search-mobile{display:none}
.icon-btn{display:inline-grid;place-items:center;width:38px;height:38px;background:var(--surface);border:1px solid var(--border);border-radius:8px;color:var(--text);transition:all var(--dur) var(--ease)}
.icon-btn:hover{color:var(--accent);border-color:var(--accent)}
.icon-btn svg{width:18px;height:18px}
.theme-toggle .icon-sun{display:none}.theme-toggle .icon-moon{display:block}
[data-theme="light"] .theme-toggle .icon-sun{display:block}[data-theme="light"] .theme-toggle .icon-moon{display:none}
.cta-mini{display:inline-flex;align-items:center;gap:.5rem;height:38px;padding:0 1rem;background:var(--accent);color:#fff;border-radius:8px;font-weight:600;font-size:var(--fs-sm)}
.cta-mini:hover{background:var(--text);color:var(--bg)}
.avatar-pill{display:inline-flex;align-items:center;gap:.5rem;height:38px;padding:0 .75rem 0 .5rem;background:var(--surface);border:1px solid var(--border);border-radius:999px;font-size:var(--fs-sm)}
.avatar-pill .av{width:26px;height:26px;border-radius:50%;background:var(--accent);color:#fff;display:grid;place-items:center;font-weight:700;font-size:11px}
@media (max-width:1040px){.nav-search{display:none}.nav-search-mobile{display:inline-grid}}
@media (max-width:820px){.nav-links{display:none}}

/* ═══ BUTTONS / FORM ═══ */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;padding:12px 18px;border-radius:8px;font-family:var(--ff-display);font-weight:600;font-size:var(--fs-base);transition:all var(--dur) var(--ease);border:1px solid transparent;cursor:pointer}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{background:var(--text);color:var(--bg)}
.btn-ghost{background:transparent;border-color:var(--border);color:var(--text)}
.btn-ghost:hover{border-color:var(--accent);color:var(--accent)}
.btn-block{display:flex;width:100%}
.field{display:flex;flex-direction:column;gap:6px;margin-bottom:1rem}
.field label{font-family:var(--ff-mono);font-size:var(--fs-xs);letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}
.field input,.field textarea,.field select{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:12px 14px;color:var(--text);font-size:var(--fs-base);transition:border-color var(--dur) var(--ease),box-shadow var(--dur) var(--ease)}
.field input:focus,.field textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 25%,transparent)}
.field textarea{min-height:120px;resize:vertical;font-family:var(--ff-body)}
.field .help{color:var(--muted);font-size:var(--fs-xs);margin-top:4px}
.flash{padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:var(--fs-sm)}
.flash-ok{background:color-mix(in srgb,var(--live) 18%,var(--bg));border:1px solid var(--live);color:var(--text)}
.flash-err{background:var(--accent-soft);border:1px solid var(--accent);color:var(--text)}

/* ═══ HERO ═══ */
.hero{padding:clamp(2.5rem,6vw,5rem) 0 clamp(2rem,5vw,4rem);position:relative;overflow:hidden;background:radial-gradient(circle at 100% 0%,var(--accent-soft) 0%,transparent 50%),radial-gradient(circle at 0% 100%,color-mix(in srgb,var(--highlight) 12%,transparent) 0%,transparent 55%),var(--bg)}
.hero-grid{display:grid;grid-template-columns:1.4fr 1fr;align-items:center;gap:clamp(2rem,4vw,4rem)}
@media (max-width:900px){.hero-grid{grid-template-columns:1fr}}
.hero-eyebrow{display:flex;align-items:center;gap:1rem;margin-bottom:1.25rem;font-size:var(--fs-xs);flex-wrap:wrap}
.badge-live{display:inline-flex;align-items:center;gap:.5rem;padding:5px 10px;background:var(--accent);color:#fff;border-radius:999px;font-family:var(--ff-mono);font-weight:700;font-size:10px;letter-spacing:.18em}
.pulse{display:inline-block;width:7px;height:7px;border-radius:50%;background:#fff;animation:pulse 1.4s infinite ease-in-out}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.3;transform:scale(.85)}}
.hero-title{margin:0 0 1.25rem;font-family:var(--ff-display);font-size:var(--fs-3xl);font-weight:700;line-height:.96;letter-spacing:-.02em}
.hero-title em{font-style:italic;background:linear-gradient(180deg,var(--accent),var(--highlight));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.hero-deck{max-width:56ch;margin:0 0 1.5rem;color:var(--text-soft);font-size:var(--fs-lg)}
.hero-cta{display:flex;flex-wrap:wrap;gap:.75rem;margin-bottom:2rem}
.cta-arrow{font-family:var(--ff-mono);transition:transform var(--dur) var(--ease)}
.btn:hover .cta-arrow{transform:translateX(4px)}
.hero-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:.5rem;padding-top:1.25rem;border-top:1px solid var(--border-soft)}
@media (max-width:560px){.hero-stats{grid-template-columns:repeat(2,1fr)}}
.stat-n{font-family:var(--ff-display);font-size:var(--fs-xl);font-weight:700;color:var(--text);line-height:1}
.stat-l{margin-top:4px;color:var(--muted);font-size:var(--fs-xs)}

/* ═══ SECTIONS ═══ */
.section{padding:clamp(2.5rem,6vw,5rem) 0;border-top:1px solid var(--border-soft)}
.section-eyebrow{display:inline-flex;align-items:center;gap:.75rem;padding:5px 12px 5px 5px;background:var(--surface);border:1px solid var(--border);border-radius:999px;font-size:var(--fs-xs);color:var(--text-soft);margin-bottom:1.25rem}
.section-eyebrow .mono{padding:3px 8px;background:var(--accent);color:#fff;border-radius:999px;font-size:10px;letter-spacing:.1em}
.section-title{margin:0 0 2.5rem;font-family:var(--ff-display);font-size:var(--fs-2xl);font-weight:700;letter-spacing:-.02em;line-height:1.1}

/* ═══ ARTICLE CARDS ═══ */
.article-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem}
@media (max-width:860px){.article-grid{grid-template-columns:repeat(2,1fr)}}
@media (max-width:560px){.article-grid{grid-template-columns:1fr}}
.article-card{display:flex;flex-direction:column;background:var(--surface);border:1px solid var(--border);border-radius:14px;overflow:hidden;transition:all var(--dur) var(--ease);color:inherit}
.article-card:hover{transform:translateY(-3px);border-color:var(--accent);box-shadow:var(--shadow)}
.article-img{aspect-ratio:16/9;background:var(--surface2);background-size:cover;background-position:center;position:relative}
.article-img-placeholder{display:grid;place-items:center;width:100%;height:100%;color:var(--muted);font-family:var(--ff-mono);font-size:var(--fs-xs);background:linear-gradient(135deg,var(--surface2),var(--bg))}
.article-cat{position:absolute;top:.75rem;left:.75rem;padding:3px 8px;background:rgba(0,0,0,.7);color:#fff;border-radius:999px;font-family:var(--ff-mono);font-size:10px;letter-spacing:.1em;text-transform:uppercase}
.article-body{flex:1;display:flex;flex-direction:column;padding:1rem 1.25rem 1.25rem}
.article-title{margin:0 0 .5rem;font-family:var(--ff-display);font-size:var(--fs-lg);font-weight:700;line-height:1.3;color:var(--text)}
.article-summary{flex:1;margin:0 0 .75rem;color:var(--text-soft);font-size:var(--fs-sm)}
.article-meta{display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding-top:.75rem;border-top:1px solid var(--border-soft);font-family:var(--ff-mono);font-size:var(--fs-xs);color:var(--muted)}
.article-source{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:60%}
.article-stats{display:flex;gap:.75rem}
.article-stats span{display:inline-flex;align-items:center;gap:3px}

/* ═══ ARTICLE PAGE ═══ */
.article-page{padding:clamp(2rem,4vw,3rem) 0}
.article-hero{max-width:780px;margin:0 auto}
.article-back{display:inline-flex;align-items:center;gap:.5rem;color:var(--text-soft);font-family:var(--ff-mono);font-size:var(--fs-xs);margin-bottom:1.5rem;letter-spacing:.1em}
.article-back:hover{color:var(--accent)}
.article-cat-tag{display:inline-block;padding:4px 10px;background:var(--accent);color:#fff;border-radius:999px;font-family:var(--ff-mono);font-size:var(--fs-xs);letter-spacing:.1em;text-transform:uppercase;margin-bottom:1rem}
h1.article-h1{margin:0 0 1.25rem;font-family:var(--ff-display);font-size:var(--fs-2xl);font-weight:700;letter-spacing:-.02em;line-height:1.15}
.article-byline{display:flex;flex-wrap:wrap;gap:1rem;color:var(--muted);font-family:var(--ff-mono);font-size:var(--fs-xs);margin-bottom:1.5rem}
.article-hero-img{aspect-ratio:16/9;background:var(--surface2);background-size:cover;background-position:center;border-radius:14px;margin-bottom:1.5rem}
.article-content{font-size:var(--fs-lg);color:var(--text-soft);line-height:1.7}
.article-content p{margin:0 0 1rem}
.article-content .ai-tag{display:inline-block;padding:2px 8px;background:var(--accent);color:#fff;border-radius:4px;font-family:var(--ff-mono);font-size:10px;letter-spacing:.1em;text-transform:uppercase;margin-right:.5rem;vertical-align:middle}
.article-actions{display:flex;flex-wrap:wrap;gap:.5rem;padding:1.5rem 0;margin:1.5rem 0;border-top:1px solid var(--border-soft);border-bottom:1px solid var(--border-soft)}
.action-btn{display:inline-flex;align-items:center;gap:.5rem;padding:10px 14px;border:1px solid var(--border);border-radius:8px;font-size:var(--fs-sm);transition:all var(--dur) var(--ease);background:var(--surface);color:var(--text);cursor:pointer}
.action-btn:hover{border-color:var(--accent);color:var(--accent)}
.action-btn.liked{background:var(--accent);color:#fff;border-color:var(--accent)}
.action-btn svg{width:18px;height:18px}

/* ═══ LOGIN ═══ */
.auth-page{padding:clamp(2rem,6vw,5rem) 0;display:flex;align-items:center;justify-content:center}
.auth-card{max-width:480px;width:100%;background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:clamp(1.5rem,3vw,2.5rem);box-shadow:var(--shadow)}
.auth-eyebrow{font-family:var(--ff-mono);font-size:var(--fs-xs);letter-spacing:.18em;color:var(--accent);margin-bottom:.5rem;text-transform:uppercase}
.auth-title{margin:0 0 1.5rem;font-family:var(--ff-display);font-size:var(--fs-xl);font-weight:700}
.auth-steps{counter-reset:step;margin:0 0 1.5rem;padding:0;list-style:none;display:flex;flex-direction:column;gap:.75rem}
.auth-step{display:flex;gap:.75rem;font-size:var(--fs-sm);color:var(--text-soft)}
.auth-step-n{flex-shrink:0;width:28px;height:28px;background:var(--surface2);border:1px solid var(--border);border-radius:50%;display:grid;place-items:center;font-family:var(--ff-mono);font-size:11px;font-weight:700;color:var(--text)}
.auth-divider{height:1px;background:var(--border-soft);margin:1.25rem 0;position:relative;text-align:center}
.auth-divider::before{content:attr(data-label);position:absolute;top:-9px;left:50%;transform:translateX(-50%);background:var(--surface);padding:0 .75rem;color:var(--muted);font-family:var(--ff-mono);font-size:var(--fs-xs);letter-spacing:.1em}
.otp-input{font-family:var(--ff-mono);font-size:1.5rem;letter-spacing:.4em;text-align:center;font-weight:600}

/* ═══ FOOTER ═══ */
.footer{background:var(--bg2);border-top:1px solid var(--border);padding:3rem 0 1.5rem;margin-top:auto}
.footer-grid{display:grid;grid-template-columns:1fr 2fr;gap:3rem;margin-bottom:2rem}
@media (max-width:720px){.footer-grid{grid-template-columns:1fr}}
.footer-brand{display:flex;align-items:center;gap:.75rem;color:var(--accent)}
.footer-brand .brand-mark{width:32px;height:32px}
.footer-name{font-family:var(--ff-display);font-weight:700;font-size:var(--fs-lg);color:var(--text)}
.footer-tag{color:var(--text-soft);font-size:var(--fs-sm)}
.footer-cols{display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem}
@media (max-width:520px){.footer-cols{grid-template-columns:1fr 1fr}}
.footer-cols div{display:flex;flex-direction:column;gap:.5rem}
.footer-cols a{color:var(--text-soft);font-size:var(--fs-sm)}
.footer-cols a:hover{color:var(--accent)}
.footer-h{font-family:var(--ff-mono);font-size:var(--fs-xs);letter-spacing:.15em;text-transform:uppercase;color:var(--text);margin-bottom:.5rem}
.footer-stripe{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem;padding-top:1.5rem;border-top:1px solid var(--border-soft);font-family:var(--ff-mono);font-size:var(--fs-xs);color:var(--muted)}
.footer-stripe-text{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem}
.lockup{color:var(--text);font-family:var(--ff-display);font-weight:700}
.footer-meta{display:flex;gap:.75rem}

/* ═══ CATEGORIES ═══ */
.cat-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:.75rem}
@media (max-width:900px){.cat-grid{grid-template-columns:repeat(3,1fr)}}
@media (max-width:520px){.cat-grid{grid-template-columns:repeat(2,1fr)}}
.cat-chip{position:relative;display:flex;flex-direction:column;align-items:flex-start;gap:4px;padding:1rem 1rem 1.5rem;background:var(--surface);border:1px solid var(--border);border-radius:8px;color:var(--text);transition:all var(--dur) var(--ease)}
.cat-chip:hover,.cat-chip.active{border-color:var(--accent);transform:translateY(-2px);box-shadow:var(--shadow)}
.cat-chip.active{background:var(--accent);color:#fff}
.cat-chip.active .cat-hi{color:rgba(255,255,255,.85)}
.cat-emoji{font-size:1.5rem;line-height:1}
.cat-name{font-family:var(--ff-display);font-weight:600;font-size:var(--fs-base)}
.cat-hi{font-family:var(--ff-deva);font-size:var(--fs-sm);color:var(--text-soft)}
.cat-num{position:absolute;top:.75rem;right:.75rem;font-family:var(--ff-mono);color:var(--muted);font-size:10px}

/* ═══ ADMIN ═══ */
.admin-tabs{display:flex;gap:.5rem;margin-bottom:1.5rem;border-bottom:1px solid var(--border);overflow-x:auto}
.admin-tab{padding:.75rem 1rem;color:var(--text-soft);font-size:var(--fs-sm);border-bottom:2px solid transparent;white-space:nowrap}
.admin-tab.active{color:var(--accent);border-bottom-color:var(--accent)}
.admin-table{width:100%;border-collapse:collapse;font-size:var(--fs-sm)}
.admin-table th,.admin-table td{padding:.75rem;border-bottom:1px solid var(--border-soft);text-align:left}
.admin-table th{font-family:var(--ff-mono);font-size:var(--fs-xs);letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}
.admin-table .row-actions{display:flex;gap:.5rem}
.badge{display:inline-block;padding:2px 8px;border-radius:999px;font-family:var(--ff-mono);font-size:10px;letter-spacing:.1em;text-transform:uppercase}
.badge-on{background:var(--live);color:#fff}
.badge-off{background:var(--muted);color:#fff}
.badge-admin{background:var(--highlight);color:#000}

/* ═══ FEEDBACK ═══ */
.testimonial-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:2rem}
@media (max-width:860px){.testimonial-grid{grid-template-columns:repeat(2,1fr)}}
@media (max-width:560px){.testimonial-grid{grid-template-columns:1fr}}
.testimonial{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1.25rem}
.testimonial-msg{margin:0 0 .75rem;color:var(--text);font-size:var(--fs-base);line-height:1.5}
.testimonial-meta{display:flex;align-items:center;justify-content:space-between;font-family:var(--ff-mono);font-size:var(--fs-xs);color:var(--muted)}
.stars{color:var(--highlight);letter-spacing:2px}

/* ═══ ALERTS PAGE ═══ */
.alert-card{background:var(--surface);border-left:4px solid var(--accent);padding:1.25rem 1.5rem;border-radius:8px;margin-bottom:1rem}
.alert-card.sev-5{border-left-color:#FF1744}
.alert-card.sev-4{border-left-color:var(--accent)}
.alert-card.sev-3{border-left-color:var(--highlight)}
.alert-card.sev-1,.alert-card.sev-2{border-left-color:var(--muted)}
.alert-header{display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem}
.alert-kind{font-family:var(--ff-mono);font-size:var(--fs-xs);letter-spacing:.15em;color:var(--muted);text-transform:uppercase}
.alert-sev{padding:2px 8px;background:var(--accent);color:#fff;border-radius:999px;font-family:var(--ff-mono);font-size:10px;letter-spacing:.1em;font-weight:700}
.alert-headline{margin:0 0 .5rem;font-family:var(--ff-display);font-size:var(--fs-xl);font-weight:700}
.alert-summary{margin:0;color:var(--text-soft)}
.alert-regions{display:flex;flex-wrap:wrap;gap:.25rem;margin-top:.75rem}
.alert-region{padding:2px 8px;background:var(--surface2);border:1px solid var(--border);border-radius:999px;font-family:var(--ff-mono);font-size:var(--fs-xs);color:var(--text-soft)}
</style>
</head>
<body>

<!-- LIVE TICKER -->
<?php if ($tickerItems): ?>
<div class="ticker" role="region" aria-label="Live news ticker">
  <div class="ticker-tag"><span class="pulse"></span><span class="ticker-tag-text">LIVE</span></div>
  <div class="ticker-track-wrap">
    <div class="ticker-content" id="ticker-track">
      <?php foreach ($tickerItems as $ti): ?>
        <span class="ticker-item"><span class="ticker-dot">◆</span><a href="/article.php?id=<?= (int)$ti['id'] ?>"><?= h($ti['title']) ?></a><?php if (!empty($ti['source_name'])): ?><span class="ticker-src"><?= h($ti['source_name']) ?></span><?php endif; ?></span>
      <?php endforeach; ?>
    </div>
    <div class="ticker-content" id="ticker-track2" aria-hidden="true">
      <?php foreach ($tickerItems as $ti): ?>
        <span class="ticker-item"><span class="ticker-dot">◆</span><a href="/article.php?id=<?= (int)$ti['id'] ?>"><?= h($ti['title']) ?></a><?php if (!empty($ti['source_name'])): ?><span class="ticker-src"><?= h($ti['source_name']) ?></span><?php endif; ?></span>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($activeAlert): ?>
<div class="alert-bar">
  <strong>🚨 Severe Alert</strong>
  <?= h($activeAlert['headline']) ?>
  &nbsp;·&nbsp;<a href="/alerts.php">View all active alerts →</a>
</div>
<?php endif; ?>

<header class="nav">
  <a class="brand" href="/" aria-label="<?= h($CONFIG['brand']['name']) ?> home">
    <svg class="brand-mark" viewBox="0 0 32 32" aria-hidden="true"><path d="M16 3 L26 13 L21 17 L26 24 L16 21 L6 24 L11 17 L6 13 Z" fill="currentColor"/></svg>
    <span class="brand-text"><span>News Eagle</span><span class="brand-live">LIVE</span></span>
  </a>
  <nav class="nav-links" aria-label="Primary">
    <a href="/news.php"<?= (strpos($_SERVER['REQUEST_URI'], 'news') !== false) ? ' class="active"' : '' ?>>News</a>
    <a href="/alerts.php"<?= (strpos($_SERVER['REQUEST_URI'], 'alerts') !== false) ? ' class="active"' : '' ?>>Alerts</a>
    <a href="/warmeter.php"<?= (strpos($_SERVER['REQUEST_URI'], 'warmeter') !== false) ? ' class="active"' : '' ?>>⚔️ War Meter</a>
    <a href="/videos.php"<?= (strpos($_SERVER['REQUEST_URI'], 'videos') !== false) ? ' class="active"' : '' ?>>Videos</a>
    <a href="/students.php"<?= (strpos($_SERVER['REQUEST_URI'], 'students') !== false) ? ' class="active"' : '' ?>>🎓 Students</a>
    <a href="/feedback.php"<?= (strpos($_SERVER['REQUEST_URI'], 'feedback') !== false) ? ' class="active"' : '' ?>>Feedback</a>
    <a href="<?= h($CONFIG['brand']['bot_url']) ?>" target="_blank" rel="noopener">Bot ↗</a>
  </nav>
  <div class="nav-actions">
    <form class="nav-search" method="get" action="/search.php" role="search" aria-label="Global archive search">
      <input type="search" name="q" value="<?= h((string)($_GET['q'] ?? '')) ?>" placeholder="Search archive..." maxlength="100">
      <button type="submit" aria-label="Search archive">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3.5-3.5"></path></svg>
      </button>
    </form>
    <button class="nav-burger" type="button" aria-label="Open menu" onclick="document.getElementById('mmenu').classList.add('open')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
    </button>
    <a class="icon-btn nav-search-mobile" href="/search.php" aria-label="Open archive search">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3.5-3.5"></path></svg>
    </a>
    <button class="icon-btn theme-toggle" type="button" aria-label="Toggle light/dark mode">
      <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
      <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
    </button>
    <?php if ($user): ?>
      <a class="avatar-pill" href="/account.php">
        <span class="av"><?= h(mb_substr($user['display_name'] ?? $user['first_name'] ?? 'U', 0, 1)) ?></span>
        <span><?= h($user['display_name'] ?? $user['first_name'] ?? 'Account') ?></span>
        <?php if ($user['is_admin']): ?><span class="badge badge-admin">Admin</span><?php endif; ?>
      </a>
    <?php else: ?>
      <a class="cta-mini" href="/login.php">Log in →</a>
    <?php endif; ?>
  </div>
</header>

<div class="mobile-menu" id="mmenu">
  <div class="mobile-menu-head">
    <span style="font-family:var(--ff-display);font-weight:700;font-size:1.2rem;color:var(--accent)">🦅 News Eagle Live</span>
    <button class="icon-btn" type="button" aria-label="Close menu" onclick="document.getElementById('mmenu').classList.remove('open')">✕</button>
  </div>
  <a class="mlink" href="/">🏠 Home</a>
  <a class="mlink" href="/news.php">📰 News</a>
  <a class="mlink" href="/search.php">🔎 Search Archive</a>
  <a class="mlink" href="/warmeter.php">⚔️ War Meter</a>
  <a class="mlink" href="/videos.php">🎥 Videos</a>
  <a class="mlink" href="/students.php">🎓 Student Hub</a>
  <a class="mlink" href="/alerts.php">🚨 Alerts</a>
  <a class="mlink" href="/feedback.php">📝 Feedback</a>
  <?php if ($user): ?>
    <a class="mlink" href="/account.php">👤 My Account</a>
    <?php if ($user['is_admin']): ?><a class="mlink" href="/admin.php">👑 Admin</a><?php endif; ?>
  <?php else: ?>
    <a class="mlink" href="/login.php" style="color:var(--accent)">🔐 Log in</a>
  <?php endif; ?>
  <a class="mlink" href="<?= h($CONFIG['brand']['bot_url']) ?>" target="_blank" rel="noopener">🤖 Telegram Bot ↗</a>
</div>


<script>
(function(){
  function load(){
    fetch('/api/ticker.php').then(function(r){return r.json();}).then(function(d){
      if (!d.ok || !d.items || !d.items.length) return;
      var html = d.items.map(function(it){
        return '<span class="ticker-item"><span class="ticker-dot">◆</span>' +
          '<a href="/article.php?id=' + it.id + '">' + it.title.replace(/</g,'&lt;') + '</a>' +
          (it.source ? '<span class="ticker-src">' + it.source.replace(/</g,'&lt;') + '</span>' : '') + '</span>';
      }).join('');
      var t1 = document.getElementById('ticker-track'), t2 = document.getElementById('ticker-track2');
      if (t1) t1.innerHTML = html;
      if (t2) t2.innerHTML = html;
    }).catch(function(){});
  }
  setInterval(load, 60000);
})();
</script>
<main>
