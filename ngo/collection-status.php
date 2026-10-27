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
    SELECT nr.*, d.donation_id, d.donor_id, d.food_name, d.food_photo, d.unit,
           d.quantity, d.people_served, d.address AS pickup_address, d.area AS pickup_area, d.city AS pickup_city,
           d.best_before, d.status AS donation_status, u.name AS donor_name, u.phone AS donor_phone
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
        $collectedQty = (float)post('collected_quantity');
        if ($collectedQty <= 0) {
            $errors[] = 'Please enter a valid collected quantity.';
        } else {
            $pdo->beginTransaction();
            try {
                // 1. Complete the NGO request
                $pdo->prepare("UPDATE ngo_requests SET status='completed' WHERE ngo_request_id=:n")->execute([':n' => $nrId]);
                
                // 2. Update donation status, save quantity, AND start the 2-hour timer
                $pdo->prepare("UPDATE food_donations SET status='picked_up', collected_quantity=:q, ngo_id=:u, collected_at=NOW() WHERE donation_id=:d")
                    ->execute([':q' => $collectedQty, ':u' => $uid, ':d' => $r['donation_id']]);

                $pdo->prepare("INSERT INTO donation_history (donation_id, old_status, new_status, changed_by) VALUES (:d, 'ngo_assigned', 'picked_up', :u)")
                    ->execute([':d' => $r['donation_id'], ':u' => $uid]);

                $pdo->commit();

                notify($pdo, (int)$r['donor_id'], '🍱 Food Collected', 'Your donation has been collected by ' . current_user()['name'] . '.', 'ngo', $r['donation_id']);
                set_flash('success', 'Food collected! Quantity added to your inventory. You have 2 hours to distribute it.');
                redirect(BASE_URL . 'ngo/my-donations.php');
            } catch (Exception $ex) {
                $pdo->rollBack();
                $errors[] = 'Error: ' . $ex->getMessage();
            }
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
        </div>
    </div>
</div>

<div class="card mb-3" style="border-left:4px solid var(--blue); background:#F0F9FF;">
    <h3 class="card-title" style="color:var(--blue);">🚚 Collection & Inventory</h3>
    <p class="text-muted mb-2">The donor posted <strong><?= (float)$r['quantity'] ?> <?= sanitize($r['unit']) ?></strong>. Enter the actual quantity you collected. <br><em>(Note: The 2-hour distribution timer starts as soon as you submit this).</em></p>
    
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mark_collected">
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Collected Quantity (<?= sanitize($r['unit']) ?>) *</label>
                <input type="number" step="any" name="collected_quantity" class="form-control" required value="<?= (float)$r['quantity'] ?>">
            </div>
        </div>
        
        <button class="btn btn-primary btn-lg">✅ Mark as Collected & Start Timer</button>
    </form>
</div>

<style>
.details-grid { display:grid; grid-template-columns: 260px 1fr; gap:20px; }
.details-grid img { width:100%; border-radius:12px; }
@media (max-width: 700px) { .details-grid { grid-template-columns: 1fr; } }
</style>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
