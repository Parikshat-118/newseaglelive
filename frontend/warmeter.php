<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$PAGE_TITLE = 'War Meter';
$PAGE_DESCRIPTION = 'Live Global Conflict Index from real-time news coverage analysis.';

$window = (int)($_GET['w'] ?? 7);
if (!in_array($window, [7, 30], true)) $window = 7;

$db = ne_db();
$snap = null;
$history = [];
if ($db) {
    try {
        $s = $db->prepare(
            "SELECT global_index, threat_label, total_conflict_articles, regions, computed_at
             FROM warmeter_snapshots WHERE window_days = :w
             ORDER BY computed_at DESC LIMIT 1"
        );
        $s->execute([':w' => $window]);
        $snap = $s->fetch();

        // sparkline: last 48 snapshots (~24h)
        $h = $db->prepare(
            "SELECT global_index FROM warmeter_snapshots WHERE window_days = :w
             ORDER BY computed_at DESC LIMIT 48"
        );
        $h->execute([':w' => $window]);
        $history = array_reverse(array_map('floatval', $h->fetchAll(PDO::FETCH_COLUMN)));
    } catch (Throwable $e) {}
}

$idx = $snap ? (float)$snap['global_index'] : 0.0;
$label = $snap['threat_label'] ?? 'Low';
$regions = $snap ? (json_decode($snap['regions'] ?? '[]', true) ?: []) : [];

$labelColors = ['Low'=>'#00D4AA','Guarded'=>'#DAA520','Elevated'=>'#FF8C00','High'=>'#E8504C','Severe'=>'#FF1744'];
$col = $labelColors[$label] ?? '#00D4AA';
// Needle angle: 0 -> -90deg, 10 -> +90deg
$angle = -90 + ($idx / 10.0) * 180;

include __DIR__ . '/includes/header.php';
?>

<section class="section" style="padding-top:2rem">
  <div class="container">
    <div class="section-eyebrow"><span class="mono">⚔️ LIVE</span><span>War Meter</span></div>
    <h2 class="section-title">Global Conflict Index.</h2>

    <p style="max-width:64ch;color:var(--text-soft);margin:-1.5rem 0 2rem">
      Derived from the volume of conflict-related reporting in our live news feed,
      using a transparent scaling function. Recomputed every 30 minutes.
      <strong>It is a signal, not a prediction.</strong>
    </p>

    <div style="display:flex;gap:.5rem;margin-bottom:2rem">
      <a class="btn <?= $window===7 ? 'btn-primary' : 'btn-ghost' ?>" href="?w=7">7 Days</a>
      <a class="btn <?= $window===30 ? 'btn-primary' : 'btn-ghost' ?>" href="?w=30">30 Days</a>
      <?php if ($snap): ?>
        <span class="mono" style="margin-left:auto;align-self:center;color:var(--muted);font-size:var(--fs-xs)">
          Updated <?= ne_time_ago($snap['computed_at']) ?>
        </span>
      <?php endif; ?>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1.2fr;gap:1.5rem;align-items:start" class="wm-grid">
      <style>@media (max-width:860px){.wm-grid{grid-template-columns:1fr !important}}</style>

      <!-- GAUGE -->
      <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:2rem;text-align:center">
        <svg viewBox="0 0 200 130" style="max-width:340px;margin:0 auto">
          <defs>
            <linearGradient id="arc" x1="0%" y1="0%" x2="100%" y2="0%">
              <stop offset="0%" stop-color="#00D4AA"/><stop offset="35%" stop-color="#DAA520"/>
              <stop offset="60%" stop-color="#FF8C00"/><stop offset="80%" stop-color="#E8504C"/>
              <stop offset="100%" stop-color="#FF1744"/>
            </linearGradient>
          </defs>
          <path d="M 20 110 A 80 80 0 0 1 180 110" fill="none" stroke="var(--surface2)" stroke-width="16" stroke-linecap="round"/>
          <path d="M 20 110 A 80 80 0 0 1 180 110" fill="none" stroke="url(#arc)" stroke-width="16" stroke-linecap="round"
                stroke-dasharray="<?= round(($idx/10)*251.3, 1) ?> 251.3"/>
          <g transform="rotate(<?= $angle ?> 100 110)">
            <line x1="100" y1="110" x2="100" y2="42" stroke="var(--text)" stroke-width="3" stroke-linecap="round"/>
            <circle cx="100" cy="110" r="6" fill="var(--text)"/>
          </g>
          <text x="100" y="100" text-anchor="middle" font-family="JetBrains Mono, monospace" font-size="9" fill="var(--muted)">/ 10</text>
        </svg>
        <div style="font-family:var(--ff-display);font-size:3.2rem;font-weight:800;line-height:1;margin-top:.5rem;color:<?= $col ?>"><?= number_format($idx, 1) ?></div>
        <div style="font-family:var(--ff-mono);letter-spacing:.2em;text-transform:uppercase;font-weight:700;margin-top:.4rem;color:<?= $col ?>"><?= h($label) ?></div>

        <div style="display:flex;justify-content:space-between;margin-top:1.5rem;font-family:var(--ff-mono);font-size:10px;color:var(--muted)">
          <span style="color:#00D4AA">LOW</span><span style="color:#DAA520">GUARDED</span>
          <span style="color:#FF8C00">ELEVATED</span><span style="color:#E8504C">HIGH</span>
          <span style="color:#FF1744">SEVERE</span>
        </div>

        <?php if ($snap): ?>
        <div style="margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid var(--border-soft);display:flex;justify-content:space-around">
          <div><div class="stat-n mono" style="font-size:1.4rem"><?= number_format((int)$snap['total_conflict_articles']) ?></div><div class="stat-l">conflict articles · <?= $window ?>d</div></div>
          <div><div class="stat-n mono" style="font-size:1.4rem"><?= count($regions) ?></div><div class="stat-l">active hotspots</div></div>
        </div>
        <?php endif; ?>

        <?php if (count($history) > 2):
          $maxH = max(max($history), 0.1); $pts = [];
          foreach ($history as $i => $v) {
              $x = round(($i / (count($history)-1)) * 300, 1);
              $y = round(40 - ($v / 10) * 38, 1);
              $pts[] = "$x,$y";
          } ?>
        <div style="margin-top:1.25rem">
          <div class="mono" style="font-size:10px;color:var(--muted);text-align:left;margin-bottom:4px">INDEX TREND (recent)</div>
          <svg viewBox="0 0 300 42" style="width:100%;height:42px">
            <polyline points="<?= implode(' ', $pts) ?>" fill="none" stroke="<?= $col ?>" stroke-width="2"/>
          </svg>
        </div>
        <?php endif; ?>
      </div>

      <!-- HOTSPOTS -->
      <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:1.5rem">
        <h3 style="font-family:var(--ff-display);margin:0 0 1.25rem;display:flex;align-items:center;gap:.5rem">
          <span class="pulse" style="background:var(--accent)"></span> Conflict Hotspots — last <?= $window ?> days
        </h3>
        <?php if (empty($regions)): ?>
          <p style="color:var(--muted)">No snapshot yet — the bot computes one every 30 minutes. Check back shortly.</p>
        <?php else: ?>
          <?php foreach ($regions as $i => $r): ?>
            <div style="margin-bottom:1rem">
              <div style="display:flex;justify-content:space-between;font-size:var(--fs-sm);margin-bottom:4px">
                <span><span class="mono" style="color:var(--muted)"><?= str_pad((string)($i+1), 2, '0', STR_PAD_LEFT) ?></span> &nbsp;<?= h($r['region']) ?></span>
                <span class="mono" style="color:var(--text-soft)"><?= h((string)$r['articles']) ?> reports · <?= number_format((float)$r['score'],1) ?></span>
              </div>
              <div style="height:8px;background:var(--surface2);border-radius:99px;overflow:hidden">
                <div style="height:100%;width:<?= min(100,(float)$r['score']*10) ?>%;background:linear-gradient(90deg,var(--highlight),var(--accent));border-radius:99px"></div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
        <p class="mono" style="font-size:10px;color:var(--muted);margin:1.5rem 0 0">
          Ranks locations by volume of conflict-related coverage in our news database. Signal, not prediction.
        </p>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
