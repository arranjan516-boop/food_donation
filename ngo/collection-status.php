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

        // Notify the donor (donor_id is now selected)
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
            <h4>📍 Pickup From</h4>
            <p><strong><?= sanitize($r['donor_name']) ?></strong></p>
            <p><?= sanitize($r['pickup_address']) ?>, <?= sanitize($r['pickup_area']) ?>, <?= sanitize($r['pickup_city']) ?></p>
            <p><strong>Phone:</strong> <?= sanitize($r['donor_phone'] ?: '—') ?></p>
            <p style="margin-top:12px"><strong>Quantity:</strong> <?= (float)$r['quantity'] ?> <?= sanitize($r['unit']) ?></p>
            <p><strong>People served:</strong> <?= (int)$r['people_served'] ?></p>
            <p><strong>Best before:</strong> <?= $r['best_before'] ? date('d M Y, h:i A', strtotime($r['best_before'])) : '—' ?></p>
        </div>
    </div>
</div>

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
</style>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
