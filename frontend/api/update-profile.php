<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$user = ne_require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ne_json(['success' => false, 'message' => 'Method not allowed'], 405);
}

// Check CSRF
$csrf = $_POST['csrf_token'] ?? '';
if (!ne_check_csrf($csrf)) {
    ne_json(['success' => false, 'message' => 'Invalid security token. Please refresh the page.'], 403);
}

$db = ne_db();
$action = $_POST['action'] ?? '';

if ($action === 'pincode') {
    $pincode = trim($_POST['pincode'] ?? '');
    
    if ($pincode !== '' && !preg_match('/^[1-9][0-9]{5}$/', $pincode)) {
        ne_json(['success' => false, 'message' => 'Invalid PIN code. Must be 6 digits.']);
    }
    
    $district = null; 
    $state = null;
    
    if ($pincode) {
        $stmt = $db->prepare("SELECT city, district, state FROM pincode_directory WHERE pincode = ?");
        $stmt->execute([$pincode]);
        $loc = $stmt->fetch();
        if ($loc) {
            $district = $loc['district'];
            $state = $loc['state'];
        }
    }
    
    $stmt = $db->prepare("UPDATE users SET pincode = ?, district = ?, state = ? WHERE id = ?");
    $stmt->execute([$pincode ?: null, $district, $state, $user['id']]);
    
    if ($pincode && !$district) {
        ne_json(['success' => true, 'message' => 'PIN code saved, but location not found in directory.']);
    }
    ne_json(['success' => true, 'message' => 'Location preferences updated.']);
} 
elseif ($action === 'categories') {
    $cats = $_POST['categories'] ?? '';
    // $cats is a comma-separated string
    $selected = array_filter(array_map('trim', explode(',', $cats)));
    
    // Validate against allowed categories
    global $CONFIG;
    $allowed = array_column($CONFIG['categories'], 'slug');
    $valid = array_intersect($selected, $allowed);
    
    try {
        $db->beginTransaction();
        $stmt = $db->prepare("DELETE FROM user_subscriptions WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        
        if (!empty($valid)) {
            $stmt = $db->prepare("INSERT INTO user_subscriptions (user_id, category) VALUES (?, ?)");
            foreach ($valid as $cat) {
                $stmt->execute([$user['id'], $cat]);
            }
        }
        $db->commit();
        ne_json(['success' => true, 'message' => 'News interests updated successfully.']);
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('[update-profile] Failed to save categories: ' . $e->getMessage());
        ne_json(['success' => false, 'message' => 'Database error. Failed to save interests.']);
    }
}

ne_json(['success' => false, 'message' => 'Invalid action specified.']);
