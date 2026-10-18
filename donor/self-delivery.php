<?php
// donor/self-delivery.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification-functions.php';

require_login();
if (current_role() !== 'donor' && current_role() !== 'admin') {
    set_flash('error', 'Access denied.');
    redirect(BASE_URL . 'index.php');
}

$uid = current_user_id();
$id = int_get('id');

if (!$id || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_flash('error', 'Invalid request.');
    redirect(BASE_URL . 'donor/dashboard.php');
}
verify_csrf();

// Verify donation belongs to this donor and is in a valid state
$stmt = $pdo->prepare("SELECT * FROM food_donations WHERE donation_id = :id AND donor_id = :u");
$stmt->execute([':id' => $id, ':u' => $uid]);
$d = $stmt->fetch();

if (!$d) {
    set_flash('error', 'Donation not found.');
    redirect(BASE_URL . 'donor/dashboard.php');
}

if (!in_array($d['status'], ['accepted', 'out_for_delivery'])) {
    set_flash('error', 'This donation cannot be marked as delivered yet.');
    redirect(BASE_URL . 'donor/donation-details.php?id=' . $id);
}

// Find the accepted recipient
$rq = $pdo->prepare("SELECT request_id, recipient_id FROM food_requests WHERE donation_id = :d AND status = 'accepted' ORDER BY accepted_at DESC LIMIT 1");
$rq->execute([':d' => $id]);
$req = $rq->fetch();

$requestId = $req ? (int)$req['request_id'] : null;
$recipientId = $req ? (int)$req['recipient_id'] : null;

// Handle Upload
$proof = null;
if (!empty($_FILES['proof_image']['name'])) {
    $proof = upload_image($_FILES['proof_image'], PROOF_UPLOAD, PROOF_UPLOAD_URL);
}

if (!$proof) {
    set_flash('error', 'Please upload a valid delivery proof photo.');
    redirect(BASE_URL . 'donor/donation-details.php?id=' . $id);
}

$pdo->beginTransaction();
try {
    // 1. Create delivery record
    $pdo->prepare("INSERT INTO deliveries (donation_id, request_id, method, status, delivered_at) VALUES (:d, :r, 'self', 'delivered', NOW())")
        ->execute([':d' => $id, ':r' => $requestId]);
    $deliveryId = (int)$pdo->lastInsertId();

    // 2. Insert proof
    $pdo->prepare("
        INSERT INTO delivery_proofs
          (donation_id, request_id, delivery_id, uploaded_by, uploaded_by_role, proof_image, delivery_note)
        VALUES (:d, :r, :del, :u, 'donor', :img, :note)
    ")->execute([
        ':d' => $id, ':r' => $requestId, ':del' => $deliveryId, ':u' => $uid,
        ':img' => $proof, ':note' => post('delivery_note')
    ]);

    // 3. Update donation
    $pdo->prepare("UPDATE food_donations SET status='delivered' WHERE donation_id=:d")->execute([':d' => $id]);
    
    // 4. Update request
    if ($requestId) {
        $pdo->prepare("UPDATE food_requests SET status='completed' WHERE request_id=:r")->execute([':r' => $requestId]);
    }

    // 5. History
    $pdo->prepare("INSERT INTO donation_history (donation_id, old_status, new_status, changed_by) VALUES (:d, :old, 'delivered', :u)")
        ->execute([':d' => $id, ':old' => $d['status'], ':u' => $uid]);

    $pdo->commit();

    if ($recipientId) {
        notify($pdo, $recipientId, '🍱 Food Delivered', 'The donor has delivered your food. Please confirm receipt.', 'delivered', $id);
    }
    notify_admins($pdo, '📷 Self-Delivery Proof', 'Donor uploaded proof for donation #' . $id, 'proof', $id);

    set_flash('success', 'Delivery recorded successfully!');
} catch (Exception $ex) {
    $pdo->rollBack();
    set_flash('error', 'Upload failed: ' . $ex->getMessage());
}

redirect(BASE_URL . 'donor/donation-details.php?id=' . $id);
