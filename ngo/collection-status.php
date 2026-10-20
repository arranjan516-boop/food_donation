<?php
// ngo/collection-status.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification-functions.php';

require_login();
if (current_role() !== 'ngo' && current_role() !== 'admin') {
    set_flash('error', 'Access denied.');
    redirect(BASE_URL . 'index.php');
}

$uid = current_user_id();
$nrId = int_get('id');

$stmt = $pdo->prepare("
    SELECT nr.*,
           d.donation_id, d.donor_id, d.food_name, d.food_photo, d.unit,
           d.quantity, d.people_served,
           d.address AS pickup_address, d.area AS pickup_area, d.city AS pickup_city,
           d.best_before, d.status AS donation_status,
           u.name AS donor_name, u.phone AS donor_phone
    FROM ngo_requests nr
    JOIN food_donations d ON d.donation_id = nr.donation_id
    JOIN users u ON u.user_id = d.donor_id
    WHERE nr.ngo_request_id = :n AND nr.ngo_id = :u
");
$stmt->execute([':n' => $nrId, ':u' => $uid]);
$r = $stmt->fetch();
if (!$r) { set_flash('error', 'Record not found.'); redirect(BASE_URL . 'ngo/my-donations.php'); }

// Find the accepted recipient (for delivery address)
$recipient = null;
$rq = $pdo->prepare("
    SELECT fr.*, u.name AS recipient_name, u.phone AS recipient_phone,
           u.address AS recipient_address, u.city AS recipient_city,
           u.area AS recipient_area, u.latitude AS recipient_lat, u.longitude AS recipient_lng
    FROM food_requests fr
    JOIN users u ON u.user_id = fr.recipient_id
    WHERE fr.donation_id = :d AND fr.status = 'accepted' LIMIT 1
");
$rq->execute([':d' => $r['donation_id']]);
$recipient = $rq->fetch();

$errors = [];

// Define step-by-step button logic (Synced with donation status)
$showNextButton = false;
$nextStatus = '';
$nextButtonText = '';

if ($r['donation_status'] === 'ngo_assigned') {
    $showNextButton = true;
    $nextStatus = 'pickup_scheduled';
    $nextButtonText = '🚗 Start Pickup';
} elseif ($r['donation_status'] === 'pickup_scheduled') {
    $showNextButton = true;
    $nextStatus = 'picked_up';
    $nextButtonText = '🍱 Mark as Collected';
} elseif ($r['donation_status'] === 'picked_up') {
    $showNextButton = true;
    $nextStatus = 'out_for_delivery';
    $nextButtonText = '🚚 Start Delivery';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');

    // 1. Advance Status
    if ($action === 'advance') {
        $next = post('next_status');
        if ($next) {
            $pdo->prepare("UPDATE food_donations SET status=:s WHERE donation_id=:d")
                ->execute([':s' => $next, ':d' => $r['donation_id']]);

            $pdo->prepare("INSERT INTO donation_history (donation_id, old_status, new_status, changed_by) 
                           VALUES (:d, :old, :new, :u)")
                ->execute([':d' => $r['donation_id'], ':old' => $r['donation_status'], ':new' => $next, ':u' => $uid]);

            // If picked up, notify donor
            if ($next === 'picked_up') {
                notify($pdo, (int)$r['donor_id'], '🍱 Food Collected', 'Your donation has been collected by ' . current_user()['name'], 'ngo', $r['donation_id']);
            }

            set_flash('success', 'Status updated.');
            redirect(BASE_URL . 'ngo/collection-status.php?id=' . $nrId);
        }
    }
}

$pageTitle = 'Collection Status';
require_once __DIR__ . '/../includes/dashboard-header.php';
?>

<?php if ($errors): ?>
    <div class="toast toast-error"><?php foreach ($errors as $e): ?><?= sanitize($e) ?><br><?php endforeach; ?></div>
<?php endif; ?>

<div class="card mb-3">
    <div class="flex-between mb-2">
        <h2><?= sanitize($r['food_name']) ?></h2>
        <?= status_badge($r['donation_status']) ?>
    </div>
    <div class="details-grid">
        <img src="<?= food_photo_url($r['food_photo']) ?>" alt="">
        <div>
            <h4>📍 Pickup From</h4>
            <p><strong><?= sanitize($r['donor_name']) ?></strong> (<?= sanitize($r['donor_phone']) ?>)</p>
            <p><?= sanitize($r['pickup_address']) ?>, <?= sanitize($r['pickup_area']) ?></p>
            <p class="mt-2"><strong>Quantity:</strong> <?= (float)$r['quantity'] ?> <?= sanitize($r['unit']) ?></p>
        </div>
    </div>
</div>

<!-- Visual Timeline -->
<div class="card mb-3" style="padding: 20px;">
    <?php render_timeline($r['donation_status']); ?>
</div>

<!-- Next Step Button -->
<?php if ($showNextButton): ?>
    <div class="card mb-3">
        <h3 class="card-title">Next Step</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="advance">
            <input type="hidden" name="next_status" value="<?= $nextStatus ?>">
            <button class="btn btn-primary btn-lg"><?= $nextButtonText ?></button>
        </form>
    </div>
<?php endif; ?>

<!-- If Delivery has started, show the Recipient info and link to Distribution page for proof -->
<?php if (in_array($r['donation_status'], ['out_for_delivery', 'picked_up']) && $recipient): ?>
    <div class="card mb-3" style="border-left:4px solid var(--green);background:#F1F8E9">
        <h3 class="card-title" style="color:var(--green-dark)">🏁 Deliver To — Recipient Details</h3>
        <div class="recipient-info mb-3">
            <div><strong>Name:</strong> <?= sanitize($recipient['recipient_name']) ?></div>
            <div><strong>Phone:</strong> <?= sanitize($recipient['recipient_phone'] ?: '—') ?></div>
            <div style="grid-column:1/-1">
                <strong>Address:</strong> <?= sanitize($recipient['recipient_address']) ?>, <?= sanitize($recipient['recipient_area']) ?>
            </div>
        </div>
        
        <a href="<?= BASE_URL ?>ngo/distribution.php?id=<?= $nrId ?>" class="btn btn-primary btn-lg">
            📍 Reached Recipient & Upload Proof
        </a>
    </div>
<?php endif; ?>

<style>
.details-grid { display:grid; grid-template-columns: 260px 1fr; gap:20px; }
.details-grid img { width:100%; border-radius:12px; }
@media (max-width: 700px) { .details-grid { grid-template-columns: 1fr; } }
</style>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
