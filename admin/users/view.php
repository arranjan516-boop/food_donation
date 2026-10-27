<?php
// admin/users/view.php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notification-functions.php';

require_login();
if (current_role() !== 'admin') { redirect(BASE_URL . 'index.php'); }

$id = int_get('id');

// ---- Handle Actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');

    if ($action === 'activate') {
        $pdo->prepare("UPDATE users SET status='active' WHERE user_id=:u")->execute([':u' => $id]);
        set_flash('success', 'User activated.');
        redirect(BASE_URL . 'admin/users/view.php?id=' . $id);
    }
    if ($action === 'deactivate') {
        $pdo->prepare("UPDATE users SET status='inactive' WHERE user_id=:u")->execute([':u' => $id]);
        set_flash('info', 'User deactivated.');
        redirect(BASE_URL . 'admin/users/view.php?id=' . $id);
    }
    if ($action === 'block') {
        $pdo->prepare("UPDATE users SET status='blocked' WHERE user_id=:u")->execute([':u' => $id]);
        set_flash('info', 'User blocked.');
        redirect(BASE_URL . 'admin/users/view.php?id=' . $id);
    }
    if ($action === 'delete' && (int)$id !== current_user_id()) {
        $pdo->prepare("DELETE FROM users WHERE user_id=:u")->execute([':u' => $id]);
        set_flash('success', 'User deleted.');
        redirect(BASE_URL . 'admin/users/index.php');
    }
    if ($action === 'reset_password') {
        $newPass = 'password123';
        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password=:p WHERE user_id=:u")->execute([':p' => $hash, ':u' => $id]);
        set_flash('success', 'Password reset to: ' . $newPass);
        redirect(BASE_URL . 'admin/users/view.php?id=' . $id);
    }
    // NEW: Verify NGO Action
    if ($action === 'verify_ngo') {
        $pdo->prepare("UPDATE users SET is_verified = 1 WHERE user_id = :u AND role = 'ngo'")->execute([':u' => $id]);
        set_flash('success', 'NGO has been verified and can now accept donations.');
        redirect(BASE_URL . 'admin/users/view.php?id=' . $id);
    }
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = :u");
$stmt->execute([':u' => $id]);
$u = $stmt->fetch();
if (!$u) { set_flash('error', 'User not found.'); redirect(BASE_URL . 'admin/users/index.php'); }

// Role-specific stats
$stats = [];
if ($u['role'] === 'donor') {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(people_served),0) AS p FROM food_donations WHERE donor_id=:u");
    $stmt->execute([':u' => $id]);
    $stats = $stmt->fetch();
} elseif ($u['role'] === 'recipient') {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM food_requests WHERE recipient_id=:u");
    $stmt->execute([':u' => $id]);
    $stats = $stmt->fetch();
} elseif ($u['role'] === 'collector') {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM collector_tasks WHERE collector_id=:u");
    $stmt->execute([':u' => $id]);
    $stats = $stmt->fetch();
} elseif ($u['role'] === 'ngo') {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM ngo_requests WHERE ngo_id=:u");
    $stmt->execute([':u' => $id]);
    $stats = $stmt->fetch();
}

$pageTitle = 'User Details';
require_once __DIR__ . '/../../includes/dashboard-header.php';
?>

<div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px; align-items:start;">
    
    <!-- Left Column: User Profile -->
    <div>
        <div class="card mb-3">
            <div class="flex gap-2 mb-3" style="align-items:center">
                <div class="avatar-lg">
                    <?php if ($u['profile_photo']): ?>
                        <img src="<?= PROFILE_UPLOAD_URL . rawurlencode($u['profile_photo']) ?>" alt="">
                    <?php else: ?>
                        <?= strtoupper(substr($u['name'], 0, 1)) ?>
                    <?php endif; ?>
                </div>
                <div style="flex:1">
                    <h2><?= sanitize($u['name']) ?></h2>
                    <p class="text-muted"><?= sanitize(ucfirst($u['role'])) ?> · <?= sanitize($u['email']) ?></p>
                </div>
                <div><?= status_badge($u['status']) ?></div>
            </div>

            <div class="detail-grid">
                <div><strong>Phone:</strong> <?= sanitize($u['phone'] ?: '—') ?></div>
                <div><strong>City:</strong> <?= sanitize($u['city'] ?: '—') ?></div>
                <div><strong>Area:</strong> <?= sanitize($u['area'] ?: '—') ?></div>
                <div><strong>Pincode:</strong> <?= sanitize($u['pincode'] ?: '—') ?></div>
                <div style="grid-column: 1/-1"><strong>Address:</strong> <?= sanitize($u['address'] ?: '—') ?></div>
                <div><strong>Latitude:</strong> <?= sanitize($u['latitude'] ?: '—') ?></div>
                <div><strong>Longitude:</strong> <?= sanitize($u['longitude'] ?: '—') ?></div>
                <div><strong>Joined:</strong> <?= date('d M Y', strtotime($u['created_at'])) ?></div>
                
                <?php if ($stats): ?>
                    <div><strong>Activity:</strong> <?= (int)$stats['c'] ?> records</div>
                    <?php if (isset($stats['p'])): ?>
                        <div><strong>People served:</strong> <?= (int)$stats['p'] ?></div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if ($u['role'] === 'ngo'): ?>
                    <div style="grid-column: 1/-1; margin-top:8px;">
                        <strong>NGO Verification Status:</strong> 
                        <?php if ($u['is_verified']): ?>
                            <span class="badge badge-green" style="font-size:14px;">✅ Verified</span>
                        <?php else: ?>
                            <span class="badge badge-red" style="font-size:14px;">⚠️ Not Verified</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <h3 class="card-title">Admin Actions</h3>
            <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="activate">
                <button class="btn btn-primary btn-sm">✅ Activate</button>
            </form>
            <form method="post" style="display:inline;margin-left:6px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="deactivate">
                <button class="btn btn-outline btn-sm">⏸ Deactivate</button>
            </form>
            <form method="post" style="display:inline;margin-left:6px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="block">
                <button class="btn btn-outline btn-sm">🚫 Block</button>
            </form>
            
            <!-- NGO Verify Button -->
            <?php if ($u['role'] === 'ngo' && !$u['is_verified']): ?>
                <form method="post" style="display:inline;margin-left:6px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="verify_ngo">
                    <button class="btn btn-success btn-sm" style="background: var(--green); color: #fff;" data-confirm="Verify this NGO? They will be able to accept donations.">✅ Verify NGO</button>
                </form>
            <?php endif; ?>

            <?php if ((int)$id !== current_user_id()): ?>
                <form method="post" style="display:inline;margin-left:6px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <button class="btn btn-danger btn-sm" data-confirm="Delete this user permanently?">🗑 Delete</button>
                </form>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>admin/users/index.php" class="btn btn-outline btn-sm" style="margin-left:6px">← Back</a>
        </div>
    </div>

    <!-- Right Column: Security & Password -->
    <div>
        <div class="card" style="border-left: 4px solid var(--orange); background: #FFF8E1;">
            <h3 class="card-title" style="color: #E65100;">🛡️ Security: Password Storage</h3>
            <p style="font-size:13px; color:#666; margin-bottom:10px;">
                For security, this app uses <strong>Bcrypt one-way hashing</strong>. 
                Neither the Admin nor the developer can read the user's plain-text password.
            </p>
            
            <label class="form-label" style="font-size:11px;">Hashed Password (Stored in Database):</label>
            <div style="background:#fff; padding:10px; border-radius:8px; border:1px solid #ddd; font-family:monospace; font-size:11px; word-break:break-all; margin-bottom:16px; color:#555;">
                <?= sanitize($u['password']) ?>
            </div>

            <p style="font-size:13px; color:#666; margin-bottom:10px;">
                If a user forgets their password, you cannot retrieve it. Instead, you must reset it to a temporary password.
            </p>

            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_password">
                <button class="btn btn-accent btn-block" data-confirm="Reset this user's password to 'password123'?">
                    🔑 Force Password Reset
                </button>
            </form>
        </div>
    </div>
</div>

<style>
.avatar-lg { width:72px;height:72px;border-radius:50%;background:var(--green);color:#fff;
             display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:700;overflow:hidden;}
.avatar-lg img { width:100%;height:100%;object-fit:cover; }
.detail-grid { display:grid; grid-template-columns: 1fr 1fr; gap:10px 24px; font-size:14px; }
@media (max-width:800px) { .detail-grid { grid-template-columns: 1fr; } }
</style>

<?php require_once __DIR__ . '/../../includes/dashboard-footer.php'; ?>
