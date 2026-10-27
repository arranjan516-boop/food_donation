<?php
// ngo/track-delivery.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification-functions.php';

require_login();
if (current_role() !== 'ngo') { redirect(BASE_URL . 'index.php'); }

$uid = current_user_id();
$reqId = int_get('id');

// Fetch request details
$stmt = $pdo->prepare("
    SELECT fr.*, d.donation_id, d.food_name, d.food_photo, d.unit, d.latitude AS ngo_lat, d.longitude AS ngo_lng,
           u.address AS ngo_address, u.area AS ngo_area, u.city AS ngo_city,
           r.name AS recipient_name, r.phone AS recipient_phone, r.address AS recipient_address, r.area AS recipient_area, r.city AS recipient_city, r.latitude AS r_lat, r.longitude AS r_lng
    FROM food_requests fr
    JOIN food_donations d ON d.donation_id = fr.donation_id
    JOIN users u ON u.user_id = d.ngo_id
    JOIN users r ON r.user_id = fr.recipient_id
    WHERE fr.request_id = :r AND d.ngo_id = :u AND fr.delivery_method = 'ngo'
");
$stmt->execute([':r' => $reqId, ':u' => $uid]);
$t = $stmt->fetch();

if (!$t) { set_flash('error', 'Delivery task not found or not assigned to NGO delivery.'); redirect(BASE_URL . 'ngo/dashboard.php'); }

// Map URLs
$ngoMapUrl = 'https://www.google.com/maps/dir/?api=1&destination=' . urlencode(($t['ngo_lat'] && $t['ngo_lng']) ? $t['ngo_lat'].','.$t['ngo_lng'] : $t['ngo_area'].' '.$t['ngo_city']) . '&travelmode=driving';
$recipientMapUrl = 'https://www.google.com/maps/dir/?api=1&destination=' . urlencode(($t['r_lat'] && $t['r_lng']) ? $t['r_lat'].','.$t['r_lng'] : $t['recipient_area'].' '.$t['recipient_city']) . '&travelmode=driving';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'complete') {
    verify_csrf();
    $proof = null;
    if (!empty($_FILES['proof_image']['name'])) {
        $proof = upload_image($_FILES['proof_image'], PROOF_UPLOAD, PROOF_UPLOAD_URL);
    }
    if (!$proof) {
        $errors[] = 'Please upload a valid delivery proof.';
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO deliveries (donation_id, request_id, method, ngo_id, status, delivered_at) VALUES (:d, :r, 'ngo', :u, 'delivered', NOW())")
                ->execute([':d' => $t['donation_id'], ':r' => $reqId, ':u' => $uid]);
            $deliveryId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO delivery_proofs (donation_id, request_id, delivery_id, uploaded_by, uploaded_by_role, proof_image, delivery_note) VALUES (:d, :r, :del, :u, 'ngo', :img, :note)")
                ->execute([':d' => $t['donation_id'], ':r' => $reqId, ':del' => $deliveryId, ':u' => $uid, ':img' => $proof, ':note' => post('note')]);

            $pdo->prepare("UPDATE food_requests SET status='completed' WHERE request_id=:r")->execute([':r' => $reqId]);
            
            $pdo->commit();
            notify($pdo, (int)$t['recipient_id'], '🍱 Food Delivered', 'Your food has been delivered by the NGO.', 'delivered', $t['donation_id']);
            set_flash('success', 'Delivery completed successfully!');
            redirect(BASE_URL . 'ngo/requests.php');
        } catch (Exception $ex) {
            $pdo->rollBack();
            $errors[] = $ex->getMessage();
        }
    }
}

$pageTitle = 'Track Delivery';
require_once __DIR__ . '/../includes/dashboard-header.php';
?>

<?php if ($errors): ?><div class="toast toast-error"><?= sanitize($errors[0]) ?></div><?php endif; ?>

<div class="card mb-3">
    <h2><?= sanitize($t['food_name']) ?></h2>
    <p class="text-muted">Delivery to <strong><?= sanitize($t['recipient_name']) ?></strong></p>
</div>

<div class="card mb-3" style="border-left:4px solid var(--green); background:#F1F8E9">
    <h4>🏁 Deliver To (Recipient)</h4>
    <p><?= sanitize($t['recipient_address']) ?>, <?= sanitize($t['recipient_area']) ?>, <?= sanitize($t['recipient_city']) ?></p>
    <a href="<?= $recipientMapUrl ?>" target="_blank" class="btn btn-outline btn-sm mt-2">🧭 Navigate to Recipient</a>
</div>

<div class="card">
    <h3 class="card-title">📷 Upload Delivery Proof</h3>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="complete">
        <div class="form-group">
            <input type="file" name="proof_image" class="form-control" required accept="image/jpeg,image/png,image/webp">
        </div>
        <div class="form-group">
            <textarea name="note" class="form-control" rows="2" placeholder="Delivery note (optional)"></textarea>
        </div>
        <button class="btn btn-primary btn-lg">✅ Mark as Delivered</button>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
