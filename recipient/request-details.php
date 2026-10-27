<?php
// recipient/request-details.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification-functions.php';

require_login();
$uid = current_user_id();
$rid = int_get('id');

$stmt = $pdo->prepare("
    SELECT fr.*, d.donation_id, d.food_name, d.food_photo, d.unit, d.donor_id, d.ngo_id, d.status AS donation_status,
           u.name AS donor_name, n.name AS ngo_name
    FROM food_requests fr
    JOIN food_donations d ON d.donation_id = fr.donation_id
    JOIN users u ON u.user_id = d.donor_id
    LEFT JOIN users n ON n.user_id = d.ngo_id
    WHERE fr.request_id = :r AND fr.recipient_id = :u
");
$stmt->execute([':r' => $rid, ':u' => $uid]);
$r = $stmt->fetch();

if (!$r) { set_flash('error', 'Request not found.'); redirect(BASE_URL . 'recipient/my-requests.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'confirm_receipt') {
    verify_csrf();
    $pdo->beginTransaction();
    try {
        // Update request to completed
        $pdo->prepare("UPDATE food_requests SET status='completed' WHERE request_id=:r")->execute([':r' => $rid]);
        
        // Update donation to completed (THIS MAKES THE TIMELINE GREEN)
        $pdo->prepare("UPDATE food_donations SET status='completed' WHERE donation_id=:d")->execute([':d' => $r['donation_id']]);
        
        // Log history
        $pdo->prepare("INSERT INTO donation_history (donation_id, old_status, new_status, changed_by) VALUES (:d, :old, 'completed', :u)")
            ->execute([':d' => $r['donation_id'], ':old' => $r['donation_status'], ':u' => $uid]);

        $pdo->commit();

        if ($r['ngo_id']) notify($pdo, (int)$r['ngo_id'], '✅ Confirmed', 'Recipient confirmed receipt.', 'completed', $r['donation_id']);
        notify($pdo, (int)$r['donor_id'], '✅ Donation Completed', 'Your donated food was successfully received.', 'completed', $r['donation_id']);
        
        set_flash('success', 'Thank you for confirming! Donation completed.');
        redirect(BASE_URL . 'recipient/request-details.php?id=' . $rid);

    } catch (Exception $ex) {
        $pdo->rollBack();
        set_flash('error', 'Error: ' . $ex->getMessage());
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
        <?= status_badge($r['status']) ?>
    </div>
    <div class="details-grid">
        <img src="<?= food_photo_url($r['food_photo']) ?>" alt="">
        <div>
            <p><strong>NGO:</strong> <?= sanitize($r['ngo_name'] ?: '—') ?></p>
            <p><strong>Requested qty:</strong> <?= (float)$r['quantity'] ?> <?= sanitize($r['unit']) ?></p>
        </div>
    </div>
</div>

<!-- Timeline for Recipient -->
<div class="card mb-3" style="padding: 20px;">
    <?php render_timeline($r['donation_status']); ?>
</div>

<?php if ($proof): ?>
    <div class="card mb-3">
        <h3 class="card-title">📷 Delivery Proof</h3>
        <img src="<?= PROOF_UPLOAD_URL . rawurlencode($proof['proof_image']) ?>" style="max-width:280px;border-radius:12px;" alt="">
        <p class="text-muted">Uploaded by <?= sanitize($proof['uploader_name']) ?></p>
    </div>
<?php endif; ?>

<?php if ($r['status'] === 'accepted'): ?>
    <div class="card">
        <h3 class="card-title">Confirm Receipt</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm_receipt">
            <button class="btn btn-primary btn-lg" data-confirm="Confirm you received the food?">✅ Confirm Food Received</button>
        </form>
    </div>
<?php elseif ($r['status'] === 'completed'): ?>
    <div class="card">
        <p style="color:var(--green-dark);font-weight:600;font-size:16px;">✅ This donation is fully completed. Thank you!</p>
    </div>
<?php endif; ?>

<style>
.details-grid { display:grid; grid-template-columns: 260px 1fr; gap:20px; }
.details-grid img { width:100%; border-radius:12px; }
</style>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
