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
                $pdo->prepare("UPDATE ngo_requests SET status='completed' WHERE ngo_request_id=:n")->execute([':n' => $nrId]);
                
                // Update to 'delivered' so it shows up for the Recipient
                $pdo->prepare("UPDATE food_donations SET status='delivered', collected_quantity=:q, ngo_id=:u, collected_at=NOW() WHERE donation_id=:d")
                    ->execute([':q' => $collectedQty, ':u' => $uid, ':d' => $r['donation_id']]);

                $pdo->prepare("INSERT INTO donation_history (donation_id, old_status, new_status, changed_by) VALUES (:d, 'ngo_assigned', 'delivered', :u)")
                    ->execute([':d' => $r['donation_id'], ':u' => $uid]);

                $pdo->commit();

                notify($pdo, (int)$r['donor_id'], '🍱 Food Delivered to NGO', 'Your donation has reached the NGO center.', 'ngo', $r['donation_id']);
                set_flash('success', 'Food received at NGO. Quantity added to inventory.');
                redirect(BASE_URL . 'ngo/my-donations.php');
            } catch (Exception $ex) {
                $pdo->rollBack();
                $errors[] = 'Error: ' . $ex->getMessage();
            }
        }
    }

    if ($action === 'upload_proof') {
        $proof = null;
        if (!empty($_FILES['proof_image']['name'])) {
            $proof = upload_image($_FILES['proof_image'], PROOF_UPLOAD, PROOF_UPLOAD_URL);
        }
        if (!$proof) {
            $errors[] = 'Please upload a valid photo.';
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("
                    INSERT INTO deliveries (donation_id, request_id, method, ngo_id, status, delivered_at, notes)
                    VALUES (:d, NULL, 'ngo', :n, 'delivered', NOW(), :note)
                ")->execute([
                    ':d' => $r['donation_id'], 
                    ':n' => $uid, 
                    ':note' => post('delivery_note')
                ]);
                $deliveryId = (int)$pdo->lastInsertId();

                $pdo->prepare("
                    INSERT INTO delivery_proofs (donation_id, request_id, delivery_id, uploaded_by, uploaded_by_role, proof_image, delivery_note) 
                    VALUES (:d, NULL, :del, :u, 'ngo', :img, :note)
                ")->execute([
                    ':d' => $r['donation_id'], 
                    ':del' => $deliveryId, 
                    ':u' => $uid, 
                    ':img' => $proof, 
                    ':note' => post('delivery_note')
                ]);
                
                $pdo->commit();
                notify($pdo, (int)$r['donor_id'], '📷 NGO Proof Uploaded', 'The NGO uploaded a photo of the food at their center.', 'proof', $r['donation_id']);

                set_flash('success', 'Collection proof uploaded successfully.');
                redirect(BASE_URL . 'ngo/collection-status.php?id=' . $nrId);
            } catch (Exception $ex) {
                $pdo->rollBack();
                $errors[] = 'Upload failed: ' . $ex->getMessage();
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

<div class="card mb-3" style="padding: 20px;">
    <?php render_timeline($r['donation_status']); ?>
</div>

<?php if ($r['donation_status'] === 'ngo_assigned'): ?>
    <div class="card mb-3" style="border-left:4px solid var(--blue); background:#F0F9FF;">
        <h3 class="card-title" style="color:var(--blue);">🚚 Collection & Inventory</h3>
        <p class="text-muted mb-2">The donor posted <strong><?= (float)$r['quantity'] ?> <?= sanitize($r['unit']) ?></strong>. Enter the actual quantity you collected to add it to your inventory.</p>
        
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mark_collected">
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Collected Quantity (<?= sanitize($r['unit']) ?>) *</label>
                    <input type="number" step="any" name="collected_quantity" class="form-control" required value="<?= (float)$r['quantity'] ?>">
                </div>
            </div>
            
            <button class="btn btn-primary btn-lg">✅ Mark as Collected & Received at NGO</button>
        </form>
    </div>
<?php endif; ?>

<?php if ($r['donation_status'] === 'delivered'): ?>
    <div class="card mt-3">
        <h3 class="card-title">📷 Upload Collection Proof (Optional)</h3>
        <p class="text-muted mb-2">Upload a photo of the food at your NGO center to share with the donor.</p>

        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_proof">
            
            <div class="form-group">
                <label class="form-label">Proof Photo *</label>
                <input type="file" name="proof_image" class="form-control" required accept="image/jpeg,image/png,image/webp">
            </div>

            <div class="form-group">
                <label class="form-label">Note (optional)</label>
                <textarea name="delivery_note" class="form-control" rows="2" placeholder="e.g. Food arrived safely at NGO center."></textarea>
            </div>

            <button class="btn btn-primary">Upload Proof</button>
        </form>
    </div>
<?php endif; ?>

<style>
.details-grid { display:grid; grid-template-columns: 260px 1fr; gap:20px; }
.details-grid img { width:100%; border-radius:12px; }
@media (max-width: 700px) { .details-grid { grid-template-columns: 1fr; } }
</style>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
