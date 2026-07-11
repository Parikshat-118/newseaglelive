<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$PAGE_TITLE = 'Severe Alerts';
$PAGE_DESCRIPTION = 'AI-detected severe alerts for India — weather, disease, disasters, security.';

$db = ne_db();
$alerts = [];
if ($db) {
    try {
        $alerts = $db->query(
            "SELECT id, kind, severity, headline, summary_en, summary_hi, regions, created_at, expires_at
             FROM severe_alerts
             WHERE is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY severity DESC, created_at DESC LIMIT 50"
        )->fetchAll();
    } catch (Throwable $e) {}
}

$kindEmoji = ['weather'=>'🌪️','disease'=>'🦠','disaster'=>'⚠️','security'=>'🚓','other'=>'❗'];
$sevLabel = [1=>'Watch',2=>'Advisory',3=>'Alert',4=>'Severe Alert',5=>'Critical Alert'];

include __DIR__ . '/includes/header.php';
?>

<section class="section" style="padding-top:2rem">
  <div class="container">
    <div class="section-eyebrow"><span class="mono">🚨 AI</span><span>Severe Alerts</span></div>
    <h2 class="section-title">Live alerts for your area.</h2>

    <p style="max-width:62ch;color:var(--text-soft);margin:-1.5rem 0 2.5rem">
      Our AI scans incoming news every 30 minutes for severe location-specific events —
      cyclones, heatwaves, floods, disease outbreaks, security incidents.
      
      <?php 
      $currentUser = ne_current_user();
      if ($currentUser && !empty($currentUser['pincode'])): 
      ?>
        <br><br>
        📍 Your current alert location is set to <strong><?= h($currentUser['district'] ? $currentUser['district'] . ', ' . $currentUser['state'] : 'PIN ' . $currentUser['pincode']) ?></strong>.
        You can change this in your <a href="/account.php" style="color:var(--accent);text-decoration:underline">Account Dashboard</a>.
      <?php else: ?>
        Users with a PIN code set get these pushed automatically on Telegram.
        Set yours via your <a href="/account.php" style="color:var(--accent);text-decoration:underline">Account Dashboard</a> or by typing <span class="mono">/setpincode</span> on
        <a href="<?= h($CONFIG['brand']['bot_url']) ?>" target="_blank" rel="noopener" style="color:var(--accent);text-decoration:underline">@<?= h($CONFIG['brand']['bot']) ?></a>.
      <?php endif; ?>
    </p>

    <?php if (empty($alerts)): ?>
      <div style="text-align:center;padding:4rem 1rem;background:var(--surface);border:1px solid var(--border);border-radius:14px">
        <div style="font-size:3rem;margin-bottom:.75rem">✅</div>
        <h3 style="font-family:var(--ff-display);margin:0 0 .5rem">No active severe alerts</h3>
        <p style="color:var(--muted);margin:0">All clear right now. The AI keeps watching, 24×7.</p>
      </div>
    <?php else: ?>
      <?php foreach ($alerts as $al):
        $regions = json_decode($al['regions'] ?? '[]', true) ?: [];
      ?>
        <div class="alert-card sev-<?= (int)$al['severity'] ?>">
          <div class="alert-header">
            <span style="font-size:1.4rem"><?= $kindEmoji[$al['kind']] ?? '❗' ?></span>
            <span class="alert-sev"><?= h($sevLabel[(int)$al['severity']] ?? 'Alert') ?></span>
            <span class="alert-kind"><?= h($al['kind']) ?></span>
            <span class="alert-kind" style="margin-left:auto"><?= ne_time_ago($al['created_at']) ?></span>
          </div>
          <h3 class="alert-headline"><?= h($al['headline']) ?></h3>
          <p class="alert-summary"><?= h($al['summary_en']) ?></p>
          <?php if (!empty($al['summary_hi'])): ?>
            <p class="alert-summary deva" style="margin-top:.5rem"><?= h($al['summary_hi']) ?></p>
          <?php endif; ?>
          <?php if ($regions): ?>
            <div class="alert-regions">
              <?php foreach ($regions as $r): ?>
                <span class="alert-region">📍 <?= h(trim(($r['district'] ?? '') . ', ' . ($r['state'] ?? ''), ', ')) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
