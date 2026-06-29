<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$user = ne_require_admin();   // redirects to login or 403s

$PAGE_TITLE = 'Admin';
$db = ne_db();
$isSuper = !empty($user['mobile_number']) && $user['mobile_number'] === $CONFIG['super_admin_mobile'];

$tab = $_GET['tab'] ?? 'feedback';
if (!in_array($tab, ['feedback','admins','alerts','stats'], true)) $tab = 'feedback';

// ── Data per tab ──────────────────────────────────────────────────────
$pendingFeedback = [];
$admins = [];
$activeAlerts = [];
$stats = [];

try {
    if ($tab === 'feedback') {
        $pendingFeedback = $db->query(
            "SELECT id, display_name, mobile_number, rating, message, created_at
             FROM feedback WHERE is_approved = 0 ORDER BY created_at ASC LIMIT 100"
        )->fetchAll();
    } elseif ($tab === 'admins') {
        $admins = $db->query(
            "SELECT id, telegram_id, mobile_number, display_name, first_name, is_admin, created_at
             FROM users WHERE is_admin = 1 OR mobile_number IS NOT NULL
             ORDER BY is_admin DESC, created_at DESC LIMIT 200"
        )->fetchAll();
    } elseif ($tab === 'alerts') {
        $activeAlerts = $db->query(
            "SELECT id, kind, severity, headline, regions, pushed_count, created_at, expires_at, is_active
             FROM severe_alerts ORDER BY created_at DESC LIMIT 50"
        )->fetchAll();
    } elseif ($tab === 'stats') {
        $stats['users']        = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $stats['articles']     = (int)$db->query("SELECT COUNT(*) FROM news_articles")->fetchColumn();
        $stats['articles_24h'] = (int)$db->query("SELECT COUNT(*) FROM news_articles WHERE fetched_at >= (NOW() - INTERVAL 24 HOUR)")->fetchColumn();
        $stats['views']        = (int)$db->query("SELECT COALESCE(SUM(view_count),0) FROM news_articles")->fetchColumn();
        $stats['likes']        = (int)$db->query("SELECT COUNT(*) FROM article_likes")->fetchColumn();
        $stats['shares']       = (int)$db->query("SELECT COUNT(*) FROM article_shares")->fetchColumn();
        $stats['feedback_pending']  = (int)$db->query("SELECT COUNT(*) FROM feedback WHERE is_approved = 0")->fetchColumn();
        $stats['feedback_approved'] = (int)$db->query("SELECT COUNT(*) FROM feedback WHERE is_approved = 1")->fetchColumn();
        $stats['sessions']     = (int)$db->query("SELECT COUNT(*) FROM web_sessions WHERE expires_at > NOW()")->fetchColumn();
        $stats['alerts_active'] = (int)$db->query("SELECT COUNT(*) FROM severe_alerts WHERE is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())")->fetchColumn();
    }
} catch (Throwable $e) { error_log('[admin] ' . $e->getMessage()); }

include __DIR__ . '/includes/header.php';
?>

<section class="section" style="padding-top:2rem">
  <div class="container">
    <div class="section-eyebrow"><span class="mono">👑</span><span>Admin Panel<?= $isSuper ? ' · Super Admin' : '' ?></span></div>
    <h2 class="section-title" style="margin-bottom:1.5rem">Control room.</h2>

    <div class="admin-tabs">
      <a class="admin-tab<?= $tab==='feedback' ? ' active' : '' ?>" href="?tab=feedback">📝 Feedback queue</a>
      <a class="admin-tab<?= $tab==='admins' ? ' active' : '' ?>" href="?tab=admins">👥 Admins &amp; users</a>
      <a class="admin-tab<?= $tab==='alerts' ? ' active' : '' ?>" href="?tab=alerts">🚨 Alerts</a>
      <a class="admin-tab<?= $tab==='stats' ? ' active' : '' ?>" href="?tab=stats">📊 Stats</a>
    </div>

    <?php if ($tab === 'feedback'): ?>
      <?php if (!$pendingFeedback): ?>
        <p style="color:var(--muted)">✅ No pending feedback. All caught up.</p>
      <?php else: ?>
        <div class="table-scroll"><table class="admin-table">
          <thead><tr><th>From</th><th>Rating</th><th>Message</th><th>When</th><th>Action</th></tr></thead>
          <tbody>
          <?php foreach ($pendingFeedback as $f): ?>
            <tr id="fb-row-<?= (int)$f['id'] ?>">
              <td><?= h($f['display_name']) ?><br><span class="mono" style="color:var(--muted);font-size:11px"><?= h($f['mobile_number'] ?? '') ?></span></td>
              <td><span class="stars"><?= str_repeat('★', (int)$f['rating']) ?></span></td>
              <td style="max-width:380px"><?= h($f['message']) ?></td>
              <td class="mono" style="font-size:11px"><?= ne_time_ago($f['created_at']) ?></td>
              <td class="row-actions">
                <button class="btn btn-primary" style="padding:6px 12px;font-size:13px" onclick="fbAct(<?= (int)$f['id'] ?>,'approve')">Approve</button>
                <button class="btn btn-ghost" style="padding:6px 12px;font-size:13px" onclick="fbAct(<?= (int)$f['id'] ?>,'reject')">Reject</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <script>
        function fbAct(id, action){
          fetch('/api/admin/approve-feedback.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ id:id, action:action })
          }).then(r=>r.json()).then(d=>{
            if (d.ok) { var row = document.getElementById('fb-row-'+id); if (row) row.remove(); }
            else alert('Failed: ' + (d.error||'unknown'));
          });
        }
        </script>
      <?php endif; ?>

    <?php elseif ($tab === 'admins'): ?>
      <?php if ($isSuper): ?>
        <div class="auth-card" style="max-width:480px;margin-bottom:2rem">
          <h3 style="font-family:var(--ff-display);margin:0 0 1rem">Promote a mobile to admin</h3>
          <div class="field">
            <label for="promote-mobile">Mobile number</label>
            <input type="tel" id="promote-mobile" inputmode="numeric" maxlength="10" placeholder="10-digit mobile">
            <div class="help">User must already have linked this mobile via /setmobile on the bot</div>
          </div>
          <div style="display:flex;gap:.5rem">
            <button class="btn btn-primary" onclick="promote(true)" type="button">Make admin</button>
            <button class="btn btn-ghost" onclick="promote(false)" type="button">Remove admin</button>
          </div>
          <div id="promote-msg" class="flash" style="display:none;margin-top:1rem"></div>
        </div>
        <script>
        function promote(make){
          var m = document.getElementById('promote-mobile').value.replace(/\D/g,'');
          var box = document.getElementById('promote-msg');
          fetch('/api/admin/promote.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ mobile:m, make_admin:make })
          }).then(r=>r.json()).then(d=>{
            box.style.display='block';
            if (d.ok) { box.className='flash flash-ok'; box.textContent='✓ ' + d.mobile + (d.is_admin ? ' is now ADMIN' : ' admin removed'); setTimeout(()=>location.reload(), 900); }
            else {
              var msgs = {'not_found':'No user with that mobile. They must /setmobile on the bot first.','super_only':'Only Super Admin can do this.','cannot_demote_super':'Cannot demote the Super Admin.','bad_mobile':'Invalid mobile.'};
              box.className='flash flash-err'; box.textContent = msgs[d.error] || 'Failed.';
            }
          });
        }
        </script>
      <?php else: ?>
        <p style="color:var(--muted);margin-bottom:1.5rem">Only the Super Admin (<?= h($CONFIG['super_admin_mobile']) ?>) can promote/demote admins.</p>
      <?php endif; ?>

      <div class="table-scroll"><table class="admin-table">
        <thead><tr><th>Name</th><th>Mobile</th><th>Telegram ID</th><th>Role</th><th>Joined</th></tr></thead>
        <tbody>
        <?php foreach ($admins as $u): ?>
          <tr>
            <td><?= h($u['display_name'] ?: $u['first_name'] ?: '—') ?></td>
            <td class="mono"><?= h($u['mobile_number'] ?? '—') ?></td>
            <td class="mono" style="font-size:11px"><?= h((string)$u['telegram_id']) ?></td>
            <td>
              <?php if ($u['mobile_number'] === $CONFIG['super_admin_mobile']): ?>
                <span class="badge badge-admin">SUPER</span>
              <?php elseif ($u['is_admin']): ?>
                <span class="badge badge-admin">ADMIN</span>
              <?php else: ?>
                <span class="badge badge-off">USER</span>
              <?php endif; ?>
            </td>
            <td class="mono" style="font-size:11px"><?= ne_time_ago($u['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>

    <?php elseif ($tab === 'alerts'): ?>
      <?php if (!$activeAlerts): ?>
        <p style="color:var(--muted)">No alerts in the system yet. The AI scans every 30 minutes.</p>
      <?php else: ?>
        <div class="table-scroll"><table class="admin-table">
          <thead><tr><th>Severity</th><th>Kind</th><th>Headline</th><th>Regions</th><th>Pushed</th><th>Status</th><th>Created</th></tr></thead>
          <tbody>
          <?php foreach ($activeAlerts as $al):
            $regions = json_decode($al['regions'] ?? '[]', true) ?: [];
            $regionTxt = implode(', ', array_map(fn($r) => $r['state'] ?? '', $regions));
            $isLive = $al['is_active'] && (!$al['expires_at'] || strtotime($al['expires_at']) > time());
          ?>
            <tr>
              <td><span class="badge <?= (int)$al['severity'] >= 4 ? 'badge-admin' : 'badge-off' ?>">SEV <?= (int)$al['severity'] ?></span></td>
              <td><?= h($al['kind']) ?></td>
              <td style="max-width:320px"><?= h($al['headline']) ?></td>
              <td style="font-size:12px"><?= h($regionTxt ?: '—') ?></td>
              <td class="mono"><?= (int)$al['pushed_count'] ?></td>
              <td><span class="badge <?= $isLive ? 'badge-on' : 'badge-off' ?>"><?= $isLive ? 'LIVE' : 'EXPIRED' ?></span></td>
              <td class="mono" style="font-size:11px"><?= ne_time_ago($al['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>

    <?php elseif ($tab === 'stats'): ?>
      <div class="hero-stats" style="grid-template-columns:repeat(5,1fr);gap:1rem;border:none;padding:0">
        <?php
        $cards = [
          ['Users', $stats['users'] ?? 0], ['Articles', $stats['articles'] ?? 0],
          ['Articles 24h', $stats['articles_24h'] ?? 0], ['Total views', $stats['views'] ?? 0],
          ['Likes', $stats['likes'] ?? 0], ['Shares', $stats['shares'] ?? 0],
          ['Pending feedback', $stats['feedback_pending'] ?? 0], ['Approved feedback', $stats['feedback_approved'] ?? 0],
          ['Live web sessions', $stats['sessions'] ?? 0], ['Active alerts', $stats['alerts_active'] ?? 0],
        ];
        foreach ($cards as [$label, $n]): ?>
          <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1.25rem">
            <div class="stat-n mono"><?= number_format((int)$n) ?></div>
            <div class="stat-l"><?= h($label) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
