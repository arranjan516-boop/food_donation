<?php
// recipient/request-details.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification-functions.php';

require_login();
$uid = current_user_id();
$rid = int_get('id');

$stmt = $pdo->prepare("
    SELECT fr.*, d.donation_id, d.food_name, d.food_photo, d.unit, d.donor_id, d.status AS donation_status,
           u.name AS donor_name, u.phone AS donor_phone, u.city AS donor_city, u.area AS donor_area
    FROM food_requests fr
    JOIN food_donations d ON d.donation_id = fr.donation_id
    JOIN users u ON u.user_id = d.donor_id
    WHERE fr.request_id = :r AND fr.recipient_id = :u
");
$stmt->execute([':r' => $rid, ':u' => $uid]);
$r = $stmt->fetch();

if (!$r) { set_flash('error', 'Request not found.'); redirect(BASE_URL . 'recipient/my-requests.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');

    if ($action === 'confirm_receipt') {
        $pdo->prepare("UPDATE food_requests SET status='completed' WHERE request_id=:r")->execute([':r' => $rid]);
        $pdo->prepare("UPDATE food_donations SET status='completed' WHERE donation_id=:d")->execute([':d' => $r['donation_id']]);
        set_flash('success', 'Thank you for confirming!');
        redirect(BASE_URL . 'recipient/request-details.php?id=' . $rid);
    }
}

$pf = $pdo->prepare("SELECT dp.*, u.name AS uploader_name FROM delivery_proofs dp JOIN users u ON u.user_id = dp.uploaded_by WHERE dp.request_id = :r ORDER BY uploaded_at DESC LIMIT 1");
$pf->execute([':r' => $rid]);
$proof = $pf->fetch();

$pageTitle = 'Request Details';
require_once __DIR__ . '/../includes/dashboard-header.php';
?>

<div class="card mb-3">
    <div class="flex-between mb-2">
        <h2><?= sanitize($r['food_name']) ?></h2>
        <div class="flex gap-2" style="align-items:center;">
            <?= status_badge($r['status']) ?>
            <?php if (in_array($r['status'], ['accepted', 'completed'], true)): ?>
                <a href="<?= BASE_URL ?>chat.php?donation_id=<?= (int)$r['donation_id'] ?>" class="btn btn-primary btn-sm">💬 Chat with Donor</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="details-grid">
        <img src="<?= food_photo_url($r['food_photo']) ?>" alt="">
        <div>
            <p><strong>Donor:</strong> <?= sanitize($r['donor_name']) ?></p>
            <p><strong>Pickup:</strong> <?= sanitize($r['donor_area'] ?: $r['donor_city']) ?></p>
            <p><strong>Requested qty:</strong> <?= (float)$r['quantity'] ?> <?= sanitize($r['unit']) ?></p>
        </div>
    </div>
</div>

<!-- Visual Timeline -->
<div class="card mb-3" style="padding: 20px;">
    <?php render_timeline($r['donation_status']); ?>
</div>

<?php if ($proof): ?>
    <div class="card mb-3">
        <h3 class="card-title">🍱 Food Delivered</h3>
        <p>Delivery proof is available. Please confirm receipt.</p>
        <img src="<?= PROOF_UPLOAD_URL . rawurlencode($proof['proof_image']) ?>" style="max-width:280px;border-radius:12px;margin:10px 0" alt="">
    </div>
<?php endif; ?>

<?php if ($r['status'] === 'accepted' || $r['status'] === 'completed'): ?>
    <div class="card">
        <h3 class="card-title">Actions</h3>
        <?php if ($r['status'] === 'accepted' && $proof): ?>
            <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="confirm_receipt">
                <button class="btn btn-primary btn-lg">✅ Confirm Food Received</button>
            </form>
        <?php elseif ($r['status'] === 'completed'): ?>
            <p>✅ This request has been completed. Thank you!</p>
        <?php else: ?>
            <p class="text-muted">Waiting for the delivery proof.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<style>
.details-grid { display:grid; grid-template-columns: 260px 1fr; gap:20px; }
.details-grid img { width:100%; border-radius:12px; }
@media (max-width: 700px) { .details-grid { grid-template-columns: 1fr; } }
</style>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
