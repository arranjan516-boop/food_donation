<?php
// admin/profile.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
if (current_role() !== 'admin') { redirect(BASE_URL . 'index.php'); }

$uid = current_user_id();
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = :u");
$stmt->execute([':u' => $uid]);
$u = $stmt->fetch();

$pageTitle = 'My Profile';
require_once __DIR__ . '/../includes/dashboard-header.php';
?>

<!-- Advanced Profile Header -->
<div class="profile-header-card">
    <div class="profile-avatar-large">
        <?php if ($u['profile_photo']): ?>
            <img src="<?= PROFILE_UPLOAD_URL . rawurlencode($u['profile_photo']) ?>" alt="">
        <?php else: ?>
            <?= strtoupper(substr($u['name'], 0, 1)) ?>
        <?php endif; ?>
    </div>
    <div class="profile-header-info">
        <h1><?= sanitize($u['name']) ?></h1>
        <p>
            <?= sanitize($u['email']) ?>
            <span class="badge-role"><?= sanitize(ucfirst($u['role'])) ?></span>
        </p>
    </div>
</div>

<!-- Profile Details Grid -->
<div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px;">
    
    <div class="settings-content">
        <h2 class="settings-section-title">Account Information</h2>
        
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:20px;">
            <div>
                <label class="form-label" style="color:var(--gray-500); font-size:12px; text-transform:uppercase;">Phone Number</label>
                <p style="font-size:16px; font-weight:500;"><?= sanitize($u['phone'] ?: 'Not set') ?></p>
            </div>
            <div>
                <label class="form-label" style="color:var(--gray-500); font-size:12px; text-transform:uppercase;">Joined Date</label>
                <p style="font-size:16px; font-weight:500;"><?= date('d M Y', strtotime($u['created_at'])) ?></p>
            </div>
        </div>

        <h2 class="settings-section-title" style="margin-top:30px;">Location Details</h2>
        
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:20px;">
            <div>
                <label class="form-label" style="color:var(--gray-500); font-size:12px; text-transform:uppercase;">City</label>
                <p style="font-size:16px; font-weight:500;"><?= sanitize($u['city'] ?: 'Not set') ?></p>
            </div>
            <div>
                <label class="form-label" style="color:var(--gray-500); font-size:12px; text-transform:uppercase;">Area / Pincode</label>
                <p style="font-size:16px; font-weight:500;"><?= sanitize($u['area'] ?: '—') ?> · <?= sanitize($u['pincode'] ?: '—') ?></p>
            </div>
        </div>
        <div>
            <label class="form-label" style="color:var(--gray-500); font-size:12px; text-transform:uppercase;">Full Address</label>
            <p style="font-size:16px; font-weight:500;"><?= nl2br(sanitize($u['address'] ?: 'Not set')) ?></p>
        </div>

        <div style="margin-top:30px; padding-top:20px; border-top:1px solid var(--gray-100);">
            <a href="<?= BASE_URL ?>admin/edit-profile.php" class="btn btn-primary">✏️ Edit Profile Details</a>
        </div>
    </div>

    <!-- Sidebar: Account Status -->
    <div>
        <div class="settings-content" style="padding:20px;">
            <h3 style="font-size:15px; font-weight:600; margin-bottom:12px;">Account Status</h3>
            <div style="display:flex; align-items:center; gap:10px; background:#F0FDF4; padding:12px; border-radius:8px; border:1px solid #BBF7D0;">
                <span style="font-size:20px;">✅</span>
                <div>
                    <div style="font-weight:600; color:#166534; font-size:14px;">Active</div>
                    <div style="font-size:12px; color:#15803D;">You have full access</div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
