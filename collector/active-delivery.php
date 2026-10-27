<?php
// collector/active-delivery.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification-functions.php';

require_login();
if (current_role() !== 'collector' && current_role() !== 'admin') {
    set_flash('error', 'Access denied.');
    redirect(BASE_URL . 'index.php');
}

$uid    = current_user_id();
$taskId = int_get('id');

$stmt = $pdo->prepare("
    SELECT ct.*, d.donation_id, d.food_name, d.food_photo, d.unit, d.quantity AS total_qty,
           n.name AS ngo_name, n.phone AS ngo_phone, n.address AS ngo_address, n.area AS ngo_area, n.city AS ngo_city, n.latitude AS ngo_lat, n.longitude AS ngo_lng,
           fr.recipient_id, fr.quantity AS req_qty, fr.contact_number AS recipient_phone, fr.delivery_address AS recipient_address,
           r.name AS recipient_name, r.phone AS r_phone, r.address AS r_address, r.area AS r_area, r.city AS r_city, r.latitude AS r_lat, r.longitude AS r_lng
    FROM collector_tasks ct
    JOIN food_donations d ON d.donation_id = ct.donation_id
    JOIN users n ON n.user_id = d.ngo_id
    JOIN food_requests fr ON fr.request_id = ct.request_id
    JOIN users r ON r.user_id = fr.recipient_id
    WHERE ct.task_id = :t AND ct.collector_id = :u
");
$stmt->execute([':t' => $taskId, ':u' => $uid]);
$t = $stmt->fetch();

if (!$t) { set_flash('error', 'Task not found.'); redirect(BASE_URL . 'collector/my-tasks.php'); }

// ---- SMART MAP LINK GENERATION ----

// NGO Destination Logic
if (!empty($t['ngo_lat']) && !empty($t['ngo_lng'])) {
    $ngoDestination = $t['ngo_lat'] . ',' . $t['ngo_lng'];
} else {
    // Fallback to text address if GPS is missing
    $ngoDestination = trim($t['ngo_address'] . ', ' . $t['ngo_area'] . ', ' . $t['ngo_city']);
}
$ngoMapUrl = 'https://www.google.com/maps/dir/?api=1&destination=' . urlencode($ngoDestination) . '&travelmode=driving';

// Recipient Destination Logic
$rAddress = $t['recipient_address'] ?: $t['r_address'];
$rArea = $t['r_area'];
$rCity = $t['r_city'];

if (!empty($t['r_lat']) && !empty($t['r_lng'])) {
    $recipientDestination = $t['r_lat'] . ',' . $t['r_lng'];
} else {
    // Fallback to text address if GPS is missing
    $recipientDestination = trim($rAddress . ', ' . $rArea . ', ' . $rCity);
}
$recipientMapUrl = 'https://www.google.com/maps/dir/?api=1&destination=' . urlencode($recipientDestination) . '&travelmode=driving';

// ---------------------------------

$errors = [];
$statusFlow = [
    'accepted'       => ['pickup_started', '🚗 Start Pickup from NGO'],
    'pickup_started' => ['picked_up',      '🍱 Food Collected'],
    'picked_up'      => ['delivering',     '🚚 Start Delivery'],
    'delivering'     => ['awaiting_proof', '📍 Reached Recipient'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');

    if ($action === 'advance') {
        $cur = $t['status'];
        if (isset($statusFlow[$cur])) {
            $next = $statusFlow[$cur][0];
            
            $pdo->prepare("UPDATE collector_tasks SET status=:s WHERE task_id=:t")
                ->execute([':s' => $next, ':t' => $taskId]);

            set_flash('success', 'Status updated.');
            redirect(BASE_URL . 'collector/active-delivery.php?id=' . $taskId);
        }
    }

    if ($action === 'upload_proof') {
        $proof = null;
        if (!empty($_FILES['proof_image']['name'])) {
            $proof = upload_image($_FILES['proof_image'], PROOF_UPLOAD, PROOF_UPLOAD_URL);
        }
        
        if (!$proof) {
            $errors[] = 'Please upload a valid delivery proof photo.';
        } else {
            $pdo->beginTransaction();
            try {
                $insDel = $pdo->prepare("
                    INSERT INTO deliveries (donation_id, request_id, method, collector_id, status, delivered_at, notes)
                    VALUES (:d, :r, 'collector', :c, 'delivered', NOW(), :note)
                ");
                $insDel->execute([':d' => $t['donation_id'], ':r' => $t['request_id'], ':c' => $uid, ':note' => post('delivery_note')]);
                $deliveryId = (int)$pdo->lastInsertId();

                $pdo->prepare("
                    INSERT INTO delivery_proofs (donation_id, request_id, delivery_id, uploaded_by, uploaded_by_role, proof_image, delivery_note)
                    VALUES (:d, :r, :del, :u, 'collector', :img, :note)
                ")->execute([':d' => $t['donation_id'], ':r' => $t['request_id'], ':del' => $deliveryId, ':u' => $uid, ':img' => $proof, ':note' => post('delivery_note')]);

                $pdo->prepare("UPDATE collector_tasks SET status='delivered', delivery_time=NOW() WHERE task_id=:t")->execute([':t' => $taskId]);
                $pdo->prepare("UPDATE food_requests SET status='completed' WHERE request_id=:r")->execute([':r' => $t['request_id']]);

                $pdo->commit();

                notify($pdo, (int)$t['recipient_id'], '🍱 Food Delivered', 'Your food has been delivered.', 'delivered', $t['donation_id']);
                set_flash('success', 'Delivery proof submitted!');
                redirect(BASE_URL . 'collector/completed-tasks.php');

            } catch (Exception $ex) {
                $pdo->rollBack();
                $errors[] = 'Upload failed: ' . $ex->getMessage();
            }
        }
    }
}

$pageTitle = 'Active Delivery';
require_once __DIR__ . '/../includes/dashboard-header.php';
?>

<?php if ($errors): ?>
    <div class="toast toast-error"><?php foreach ($errors as $e): ?><?= sanitize($e) ?><br><?php endforeach; ?></div>
<?php endif; ?>

<div class="card mb-3">
    <div class="flex-between mb-2">
        <h2><?= sanitize($t['food_name']) ?></h2>
        <?= status_badge($t['status']) ?>
    </div>
    <div class="details-grid">
        <img src="<?= food_photo_url($t['food_photo']) ?>" alt="">
        <div>
            <h4>🏢 Pickup From (NGO)</h4>
            <p><strong><?= sanitize($t['ngo_name']) ?></strong> (<?= sanitize($t['ngo_phone']) ?>)</p>
            <p><?= sanitize($t['ngo_address']) ?>, <?= sanitize($t['ngo_area']) ?>, <?= sanitize($t['ngo_city']) ?></p>
            <a href="<?= $ngoMapUrl ?>" target="_blank" class="btn btn-outline btn-sm mt-2">🧭 Navigate to NGO</a>
        </div>
    </div>
</div>

<div class="card mb-3" style="border-left:4px solid var(--green); background:#F1F8E9">
    <h4>🏁 Deliver To (Recipient)</h4>
    <p><strong><?= sanitize($t['recipient_name']) ?></strong> (<?= sanitize($t['recipient_phone']) ?>)</p>
    <p><?= sanitize($rAddress) ?>, <?= sanitize($rArea) ?>, <?= sanitize($rCity) ?></p>
    <a href="<?= $recipientMapUrl ?>" target="_blank" class="btn btn-outline btn-sm mt-2">🧭 Navigate to Recipient</a>
</div>

<?php if (isset($statusFlow[$t['status']])): ?>
    <div class="card mb-3">
        <h3 class="card-title">Next Step</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="advance">
            <button class="btn btn-primary btn-lg"><?= $statusFlow[$t['status']][1] ?></button>
        </form>
    </div>
<?php endif; ?>

<?php if (in_array($t['status'], ['delivering', 'awaiting_proof'], true)): ?>
    <div class="card">
        <h3 class="card-title">📷 Upload Delivery Proof</h3>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_proof">
            <div class="form-group">
                <input type="file" name="proof_image" class="form-control" required accept="image/jpeg,image/png,image/webp">
            </div>
            <div class="form-group">
                <textarea name="delivery_note" class="form-control" rows="2" placeholder="Delivery note (optional)"></textarea>
            </div>
            <button class="btn btn-primary btn-lg">Submit Delivery Proof</button>
        </form>
    </div>
<?php endif; ?>

<style>
.details-grid { display:grid; grid-template-columns: 260px 1fr; gap:20px; }
.details-grid img { width:100%; border-radius:12px; }
@media (max-width: 700px) { .details-grid { grid-template-columns: 1fr; } }
</style>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
