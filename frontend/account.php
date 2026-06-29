<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$user = ne_require_login();
$PAGE_TITLE = 'My account';

$db = ne_db();
$myLikes = 0; $myFeedback = 0;
try {
    $s = $db->prepare("SELECT COUNT(*) FROM article_likes WHERE user_id = :u");
    $s->execute([':u' => $user['id']]); $myLikes = (int)$s->fetchColumn();
    $s = $db->prepare("SELECT COUNT(*) FROM feedback WHERE user_id = :u");
    $s->execute([':u' => $user['id']]); $myFeedback = (int)$s->fetchColumn();
} catch (Throwable $e) {}

include __DIR__ . '/includes/header.php';
?>
<section class="section" style="padding-top:2rem">
  <div class="container" style="max-width:640px">
    <div class="section-eyebrow"><span class="mono">You</span><span>Account</span></div>
    <h2 class="section-title">
      <?= h($user['display_name'] ?: $user['first_name'] ?: 'Eagle Reader') ?>
      <?php if ($user['is_admin']): ?> <span class="badge badge-admin" style="vertical-align:middle">ADMIN</span><?php endif; ?>
    </h2>

    <div class="auth-card">
      <div class="table-scroll"><table class="admin-table">
        <tr><th>Mobile</th><td class="mono">+91 <?= h($user['mobile_number'] ?? '—') ?></td></tr>
        <tr><th>Telegram ID</th><td class="mono"><?= h((string)$user['telegram_id']) ?></td></tr>
        <tr><th>Language</th><td><?= $user['language'] === 'hi' ? 'हिन्दी' : 'English' ?></td></tr>
        <tr><th>PIN code</th><td class="mono"><?= h($user['pincode'] ?? 'not set') ?><?php if ($user['district']): ?> · <?= h($user['district']) ?>, <?= h($user['state']) ?><?php endif; ?></td></tr>
        <tr><th>Likes given</th><td class="mono"><?= $myLikes ?></td></tr>
        <tr><th>Feedback sent</th><td class="mono"><?= $myFeedback ?></td></tr>
        <tr><th>Member since</th><td class="mono"><?= $user['created_at'] ? date('M j, Y', strtotime($user['created_at'])) : '—' ?></td></tr>
      </table></div>

      <p style="color:var(--muted);font-size:var(--fs-sm);margin:1.25rem 0">
        To change language, PIN code, or categories — use the bot:
        <span class="mono">/settings</span>, <span class="mono">/setpincode</span>, <span class="mono">/categories</span>
      </p>

      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <?php if ($user['is_admin']): ?>
          <a class="btn btn-primary" href="/admin.php">👑 Admin panel</a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= h($CONFIG['brand']['bot_url']) ?>" target="_blank" rel="noopener">Open bot ↗</a>
        <a class="btn btn-ghost" href="/logout.php">Log out</a>
      </div>
    </div>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
