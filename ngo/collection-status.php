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
           d.pincode AS pickup_pincode,
           d.latitude AS pickup_lat, d.longitude AS pickup_lng,
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

// Find accepted recipient
$recipient = null;
try {
    $rq = $pdo->prepare("
        SELECT fr.*, u.name AS recipient_name, u.phone AS recipient_phone, u.email AS recipient_email,
               u.address AS recipient_address, u.city AS recipient_city,
               u.area AS recipient_area, u.pincode AS recipient_pincode,
               u.latitude AS recipient_lat, u.longitude AS recipient_lng
        FROM food_requests fr
        JOIN users u ON u.user_id = fr.recipient_id
        WHERE fr.donation_id = :d AND fr.status = 'accepted'
        ORDER BY fr.accepted_at DESC LIMIT 1
    ");
    $rq->execute([':d' => $r['donation_id']]);
    $recipient = $rq->fetch();
} catch (Exception $e) { /* silent */ }

// Google Maps URLs
$donorDestUrl = '';
if ($r['pickup_lat'] && $r['pickup_lng']) {
    $donorDestUrl = 'https://www.google.com/maps/dir/?api=1&destination=' .
        urlencode($r['pickup_lat'] . ',' . $r['pickup_lng']) . '&travelmode=driving';
} else {
    $textAddr = trim(($r['pickup_address'] ?? '') . ', ' . ($r['pickup_area'] ?? '') . ', ' . ($r['pickup_city'] ?? '') . ' ' . ($r['pickup_pincode'] ?? ''), ' ,');
    if ($textAddr) {
        $donorDestUrl = 'https://www.google.com/maps/dir/?api=1&destination=' . urlencode($textAddr) . '&travelmode=driving';
    }
}

$recipientDestUrl = '';
if ($recipient) {
    if ($recipient['recipient_lat'] && $recipient['recipient_lng']) {
        $recipientDestUrl = 'https://www.google.com/maps/dir/?api=1&destination=' .
            urlencode($recipient['recipient_lat'] . ',' . $recipient['recipient_lng']) . '&travelmode=driving';
    } else {
        $textAddr = trim(($recipient['recipient_address'] ?? '') . ', ' . ($recipient['recipient_area'] ?? '') . ', ' . ($recipient['recipient_city'] ?? '') . ' ' . ($recipient['recipient_pincode'] ?? ''), ' ,');
        if ($textAddr) {
            $recipientDestUrl = 'https://www.google.com/maps/dir/?api=1&destination=' . urlencode($textAddr) . '&travelmode=driving';
        }
    }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');

    if ($action === 'mark_collected') {
        $pdo->prepare("UPDATE ngo_requests SET status='completed', notes = CONCAT(COALESCE(notes,''), ' | Collected at ', NOW()) WHERE ngo_request_id=:n")
            ->execute([':n' => $nrId]);

        $pdo->prepare("UPDATE food_donations SET status='picked_up' WHERE donation_id=:d")
            ->execute([':d' => $r['donation_id']]);

        $pdo->prepare("INSERT INTO donation_history (donation_id, old_status, new_status, changed_by)
                       VALUES (:d, 'ngo_assigned', 'picked_up', :u)")
            ->execute([':d' => $r['donation_id'], ':u' => $uid]);

        notify($pdo, (int)$r['donor_id'], '🍱 Food Collected',
            'Your donation "' . $r['food_name'] . '" has been collected by ' . current_user()['name'] . '.',
            'ngo', $r['donation_id']);

        set_flash('success', 'Marked as collected.');
        redirect(BASE_URL . 'ngo/distribution.php?id=' . $nrId);
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
            <h4>📍 Pickup From (Donor)</h4>
            <p><strong><?= sanitize($r['donor_name']) ?></strong></p>
            <p><?= sanitize($r['pickup_address']) ?>, <?= sanitize($r['pickup_area']) ?>, <?= sanitize($r['pickup_city']) ?> - <?= sanitize($r['pickup_pincode']) ?></p>
            <p><strong>Phone:</strong>
                <?php if ($r['donor_phone']): ?>
                    <a href="tel:<?= sanitize($r['donor_phone']) ?>"><?= sanitize($r['donor_phone']) ?></a>
                <?php else: ?>—<?php endif; ?>
            </p>
            <p><strong>Quantity:</strong> <?= (float)$r['quantity'] ?> <?= sanitize($r['unit']) ?></p>
            <p><strong>People served:</strong> <?= (int)$r['people_served'] ?></p>

            <?php if ($donorDestUrl): ?>
                <a href="<?= $donorDestUrl ?>" target="_blank" class="btn btn-primary btn-sm mt-2">
                    🧭 Navigate to Donor (Google Maps)
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($recipient): ?>
<div class="card mb-3" style="border-left:4px solid var(--green);background:#F1F8E9">
    <h3 class="card-title" style="color:var(--green-dark)">🏁 Deliver To — Recipient Details</h3>

    <div class="recipient-info">
        <div><strong>Name:</strong> <?= sanitize($recipient['recipient_name']) ?></div>
        <div><strong>Phone:</strong>
            <?php if ($recipient['recipient_phone']): ?>
                <a href="tel:<?= sanitize($recipient['recipient_phone']) ?>"><?= sanitize($recipient['recipient_phone']) ?></a>
            <?php else: ?>—<?php endif; ?>
        </div>
        <div><strong>Email:</strong> <?= sanitize($recipient['recipient_email'] ?: '—') ?></div>
        <div style="grid-column:1/-1">
            <strong>Delivery Address:</strong>
            <?= sanitize($recipient['recipient_address'] ?: '—') ?>
            <?= $recipient['recipient_area'] ? ', ' . sanitize($recipient['recipient_area']) : '' ?>
            <?= $recipient['recipient_city']  ? ', ' . sanitize($recipient['recipient_city'])  : '' ?>
            <?= $recipient['recipient_pincode'] ? ' - ' . sanitize($recipient['recipient_pincode']) : '' ?>
        </div>
    </div>

    <?php if ($recipientDestUrl): ?>
        <a href="<?= $recipientDestUrl ?>" target="_blank" class="btn btn-primary btn-sm mt-2">
            🧭 Navigate to Recipient (Google Maps)
        </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h3 class="card-title">Collection</h3>
    <p class="text-muted">Once you have picked up the food from the donor, mark it as collected.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mark_collected">
        <button class="btn btn-primary btn-lg" data-confirm="Confirm you have collected the food?">🍱 Mark as Collected</button>
    </form>
</div>

<style>
.details-grid { display:grid; grid-template-columns: 260px 1fr; gap:20px; }
.details-grid img { width:100%; border-radius:12px; }
@media (max-width: 700px) { .details-grid { grid-template-columns: 1fr; } }

.recipient-info { display:grid; grid-template-columns: 1fr 1fr; gap:10px 24px; font-size:14px; }
@media (max-width: 600px) { .recipient-info { grid-template-columns: 1fr; } }
.recipient-info a { color: var(--green-dark); font-weight: 500; }
</style>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
