<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$user = ne_require_login();
$PAGE_TITLE = 'Settings Dashboard';

$db = ne_db();

// Fetch current category subscriptions
$stmt = $db->prepare("SELECT category FROM user_subscriptions WHERE user_id = ?");
$stmt->execute([$user['id']]);
$user_cats = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Generate CSRF token before any HTML output to ensure the PHPSESSID cookie can be set
$csrf_token = ne_csrf_token();

include __DIR__ . '/includes/header.php';
?>
<section class="section" style="padding-top:2rem">
  <div class="container" style="max-width:800px">
    
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem">
        <h2 class="section-title" style="margin:0">Account Dashboard</h2>
        <?php if ($user['is_admin']): ?> 
            <a href="/admin.php" class="btn btn-primary" style="padding:0.4rem 0.8rem; font-size:0.85rem">👑 Admin Panel</a>
        <?php endif; ?>
    </div>

    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap:1.5rem">
        
        <!-- Profile Card -->
        <div style="background:var(--bg-card); border:1px solid var(--border); border-radius:12px; padding:1.5rem; box-shadow:0 4px 6px rgba(0,0,0,0.05);">
            <h3 style="margin-top:0; margin-bottom:1rem; font-size:1.1rem; border-bottom:1px solid var(--border); padding-bottom:0.5rem">👤 Profile</h3>
            
            <div style="margin-bottom:0.8rem">
                <div style="font-size:var(--fs-sm); color:var(--muted)">Telegram Status</div>
                <div style="font-weight:600; color:var(--success)">Connected ✅</div>
            </div>
            
            <div style="margin-bottom:0.8rem">
                <div style="font-size:var(--fs-sm); color:var(--muted)">Linked Mobile</div>
                <div style="font-weight:600; font-family:monospace">
                    +91 <?= $user['mobile_number'] ? substr($user['mobile_number'], 0, 2) . '******' . substr($user['mobile_number'], -2) : 'Not set' ?>
                </div>
            </div>
            
            <div style="margin-bottom:0.8rem">
                <div style="font-size:var(--fs-sm); color:var(--muted)">Member Since</div>
                <div style="font-weight:600"><?= date('F Y', strtotime($user['created_at'])) ?></div>
            </div>

            <div style="margin-top:1.5rem; display:flex; gap:0.5rem; flex-wrap:wrap">
                <a class="btn btn-ghost" href="<?= h($CONFIG['brand']['bot_url']) ?>" target="_blank" style="padding:0.4rem 0.8rem; font-size:0.85rem; border:1px solid var(--border)">Update Mobile ↗</a>
                <a class="btn btn-ghost" href="/logout.php" style="padding:0.4rem 0.8rem; font-size:0.85rem; color:var(--danger)">Log out</a>
            </div>
        </div>

        <!-- Location Preferences -->
        <div style="background:var(--bg-card); border:1px solid var(--border); border-radius:12px; padding:1.5rem; box-shadow:0 4px 6px rgba(0,0,0,0.05);">
            <h3 style="margin-top:0; margin-bottom:1rem; font-size:1.1rem; border-bottom:1px solid var(--border); padding-bottom:0.5rem">📍 Location Preferences</h3>
            <p style="font-size:var(--fs-sm); color:var(--muted); margin-bottom:1rem">Enter your PIN code to receive tailored local news and weather alerts.</p>
            
            <form id="form-location" onsubmit="saveLocation(event)">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="action" value="pincode">
                
                <div style="display:flex; gap:0.5rem">
                    <input type="text" name="pincode" value="<?= h($user['pincode'] ?? '') ?>" placeholder="6-digit PIN" pattern="^[1-9][0-9]{5}$" style="flex:1; padding:0.6rem; border:1px solid var(--border); border-radius:6px; background:var(--bg); color:var(--text)" title="Please enter a valid 6-digit Indian PIN code">
                    <button type="submit" class="btn btn-primary" id="btn-location" style="padding:0 1rem; border-radius:6px">Save</button>
                </div>
                <?php if ($user['district']): ?>
                    <div style="margin-top:0.8rem; font-size:var(--fs-sm); color:var(--success)">
                        Currently receiving news for: <strong><?= h($user['district']) ?>, <?= h($user['state']) ?></strong>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- News Interests -->
    <div style="background:var(--bg-card); border:1px solid var(--border); border-radius:12px; padding:1.5rem; margin-top:1.5rem; box-shadow:0 4px 6px rgba(0,0,0,0.05);">
        <h3 style="margin-top:0; margin-bottom:0.5rem; font-size:1.1rem; border-bottom:1px solid var(--border); padding-bottom:0.5rem">📰 News Interests</h3>
        <p style="font-size:var(--fs-sm); color:var(--muted); margin-bottom:1.5rem">Select the topics you care about most. Your Daily Digest will be customized for you.</p>
        
        <form id="form-categories" onsubmit="saveCategories(event)">
            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
            <input type="hidden" name="action" value="categories">
            
            <div style="display:flex; flex-wrap:wrap; gap:0.6rem; margin-bottom:1.5rem">
                <?php foreach ($CONFIG['categories'] as $cat): 
                    $isChecked = in_array($cat['slug'], $user_cats);
                ?>
                <label class="chip <?= $isChecked ? 'active' : '' ?>" style="cursor:pointer; display:inline-flex; align-items:center; gap:0.4rem; padding:0.5rem 1rem; border:1px solid var(--border); border-radius:20px; font-size:0.9rem; transition:all 0.2s; user-select:none; <?= $isChecked ? 'background:var(--primary); color:white; border-color:var(--primary)' : 'background:var(--bg)' ?>">
                    <input type="checkbox" value="<?= h($cat['slug']) ?>" <?= $isChecked ? 'checked' : '' ?> style="display:none" onchange="toggleChip(this)">
                    <span><?= $cat['emoji'] ?></span> <?= h($cat['name']) ?>
                </label>
                <?php endforeach; ?>
            </div>
            
            <button type="submit" class="btn btn-primary" id="btn-categories" style="padding:0.6rem 1.5rem; border-radius:6px">Save Interests</button>
        </form>
    </div>
    
  </div>
</section>

<!-- Toast Notification Container -->
<div id="toast-container" style="position:fixed; bottom:20px; right:20px; z-index:9999; display:flex; flex-direction:column; gap:10px;"></div>

<script>
// Chip toggle logic
function toggleChip(checkbox) {
    const label = checkbox.parentElement;
    if (checkbox.checked) {
        label.style.background = 'var(--primary)';
        label.style.color = 'white';
        label.style.borderColor = 'var(--primary)';
    } else {
        label.style.background = 'var(--bg)';
        label.style.color = 'var(--text)';
        label.style.borderColor = 'var(--border)';
    }
}

// Toast notification
function showToast(message, isSuccess = true) {
    const toast = document.createElement('div');
    toast.style.background = isSuccess ? 'var(--success, #28a745)' : 'var(--danger, #dc3545)';
    toast.style.color = 'white';
    toast.style.padding = '12px 24px';
    toast.style.borderRadius = '8px';
    toast.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
    toast.style.fontSize = '14px';
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(20px)';
    toast.style.transition = 'all 0.3s ease';
    toast.innerHTML = (isSuccess ? '✓ ' : '⚠ ') + message;
    
    document.getElementById('toast-container').appendChild(toast);
    
    // Animate in
    setTimeout(() => {
        toast.style.opacity = '1';
        toast.style.transform = 'translateY(0)';
    }, 10);
    
    // Remove after 3s
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(20px)';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// AJAX logic for Location
async function saveLocation(e) {
    e.preventDefault();
    const btn = document.getElementById('btn-location');
    const form = e.target;
    const formData = new URLSearchParams(new FormData(form));
    
    btn.disabled = true;
    btn.textContent = 'Saving...';
    
    try {
        const res = await fetch('/api/update-profile.php', {
            method: 'POST',
            body: formData,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin'
        });
        const data = await res.json();
        showToast(data.message, data.success);
        if (data.success) {
            // Optional: wait a sec and reload to show updated district/state text
            setTimeout(() => window.location.reload(), 1500);
        }
    } catch (err) {
        showToast('Network error occurred.', false);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Save';
    }
}

// AJAX logic for Categories
async function saveCategories(e) {
    e.preventDefault();
    const btn = document.getElementById('btn-categories');
    const form = e.target;
    
    // Gather checked categories
    const checkboxes = form.querySelectorAll('input[type="checkbox"]:checked');
    const categories = Array.from(checkboxes).map(cb => cb.value).join(',');
    
    const formData = new URLSearchParams();
    formData.append('csrf_token', form.querySelector('[name="csrf_token"]').value);
    formData.append('action', 'categories');
    formData.append('categories', categories);
    
    btn.disabled = true;
    btn.textContent = 'Saving...';
    
    try {
        const res = await fetch('/api/update-profile.php', {
            method: 'POST',
            body: formData,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin'
        });
        const data = await res.json();
        showToast(data.message, data.success);
    } catch (err) {
        showToast('Network error occurred.', false);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Save Interests';
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
