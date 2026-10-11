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
    SELECT ct.*,
           d.donation_id, d.donor_id, d.food_name, d.food_photo, d.unit, d.quantity, d.people_served,
           d.address AS pickup_address, d.area AS pickup_area, d.city AS pickup_city, d.pincode AS pickup_pincode,
           d.latitude AS pickup_lat, d.longitude AS pickup_lng,
           d.best_before, d.urgency,
           u.name AS donor_name, u.phone AS donor_phone,
           fr.request_id, fr.recipient_id, fr.contact_number AS recipient_contact,
           fr.delivery_address AS recipient_delivery_address,
           ru.name AS recipient_name, ru.phone AS recipient_phone, ru.email AS recipient_email,
           ru.city AS recipient_city, ru.area AS recipient_area,
           ru.pincode AS recipient_pincode, ru.address AS recipient_address,
           ru.latitude AS recipient_lat, ru.longitude AS recipient_lng
    FROM collector_tasks ct
    JOIN food_donations d ON d.donation_id = ct.donation_id
    JOIN users u ON u.user_id = d.donor_id
    LEFT JOIN food_requests fr ON fr.request_id = ct.request_id
    LEFT JOIN users ru ON ru.user_id = fr.recipient_id
    WHERE ct.task_id = :t AND ct.collector_id = :u
");
$stmt->execute([':t' => $taskId, ':u' => $uid]);
$t = $stmt->fetch();

if (!$t) { set_flash('error', 'Task not found.'); redirect(BASE_URL . 'collector/my-tasks.php'); }

// ---- Google Maps navigation URLs ----
$donorDestUrl = '';
if ($t['pickup_lat'] && $t['pickup_lng']) {
    $donorDestUrl = 'https://www.google.com/maps/dir/?api=1&destination=' .
        urlencode($t['pickup_lat'] . ',' . $t['pickup_lng']) . '&travelmode=driving';
} else {
    $donorTextAddr = trim(($t['pickup_address'] ?? '') . ', ' . ($t['pickup_area'] ?? '') . ', ' . ($t['pickup_city'] ?? '') . ' ' . ($t['pickup_pincode'] ?? ''), ' ,');
    if ($donorTextAddr) {
        $donorDestUrl = 'https://www.google.com/maps/dir/?api=1&destination=' . urlencode($donorTextAddr) . '&travelmode=driving';
    }
}

$recipientDestUrl = '';
if ($t['recipient_lat'] && $t['recipient_lng']) {
    $recipientDestUrl = 'https://www.google.com/maps/dir/?api=1&destination=' .
        urlencode($t['recipient_lat'] . ',' . $t['recipient_lng']) . '&travelmode=driving';
} else {
    $recipientAddr = trim(($t['recipient_delivery_address'] ?: $t['recipient_address']) . ', ' . ($t['recipient_area'] ?? '') . ', ' . ($t['recipient_city'] ?? '') . ' ' . ($t['recipient_pincode'] ?? ''), ' ,');
    if ($recipientAddr) {
        $recipientDestUrl = 'https://www.google.com/maps/dir/?api=1&destination=' . urlencode($recipientAddr) . '&travelmode=driving';
    }
}

$errors = [];
$statusFlow = [
    'accepted'       => ['pickup_started', '🚗 Start Pickup'],
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

            if ($next === 'picked_up') {
                $pdo->prepare("UPDATE collector_tasks SET status = :s, pickup_time = NOW() WHERE task_id = :t")
                    ->execute([':s' => $next, ':t' => $taskId]);
            } else {
                $pdo->prepare("UPDATE collector_tasks SET status = :s WHERE task_id = :t")
                    ->execute([':s' => $next, ':t' => $taskId]);
            }

            $donationStatus = match ($next) {
                'pickup_started' => 'pickup_scheduled',
                'picked_up'      => 'picked_up',
                'delivering'     => 'out_for_delivery',
                'awaiting_proof' => 'out_for_delivery',
                default          => null,
            };
            if ($donationStatus) {
                $pdo->prepare("UPDATE food_donations SET status=:s WHERE donation_id=:d")
                    ->execute([':s' => $donationStatus, ':d' => $t['donation_id']]);
            }

            $pdo->prepare("INSERT INTO donation_history (donation_id, old_status, new_status, changed_by)
                           VALUES (:d, :old, :new, :u)")
                ->execute([':d' => $t['donation_id'], ':old' => $t['status'], ':new' => $next, ':u' => $uid]);

            if ($next === 'picked_up' && !empty($t['recipient_id'])) {
                notify($pdo, (int)$t['recipient_id'], '🍱 Food Collected',
                    'Your food has been collected and is on the way.', 'delivery', $t['donation_id']);
            }

            set_flash('success', 'Status updated.');
            redirect(BASE_URL . 'collector/active-delivery.php?id=' . $taskId);
        }
    }

    if ($action === 'upload_proof') {
        if (!in_array($t['status'], ['awaiting_proof','delivering'], true)) {
            $errors[] = 'You cannot upload proof at this stage.';
        } else {
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
                    $insDel->execute([
                        ':d'    => $t['donation_id'],
                        ':r'    => $t['request_id'],
                        ':c'    => $uid,
                        ':note' => post('delivery_note'),
                    ]);
                    $deliveryId = (int)$pdo->lastInsertId();

                    $pdo->prepare("
                        INSERT INTO delivery_proofs
                          (donation_id, request_id, delivery_id, uploaded_by, uploaded_by_role, proof_image, delivery_note)
                        VALUES (:d, :r, :del, :u, 'collector', :img, :note)
                    ")->execute([
                        ':d'    => $t['donation_id'],
                        ':r'    => $t['request_id'],
                        ':del'  => $deliveryId,
                        ':u'    => $uid,
                        ':img'  => $proof,
                        ':note' => post('delivery_note'),
                    ]);

                    $pdo->prepare("UPDATE collector_tasks SET status='delivered', delivery_time=NOW() WHERE task_id=:t")
                        ->execute([':t' => $taskId]);

                    $pdo->prepare("UPDATE food_donations SET status='delivered' WHERE donation_id=:d")
                        ->execute([':d' => $t['donation_id']]);

                    $pdo->prepare("INSERT INTO donation_history (donation_id, old_status, new_status, changed_by)
                                   VALUES (:d, :old, 'delivered', :u)")
                        ->execute([':d' => $t['donation_id'], ':old' => $t['status'], ':u' => $uid]);

                    $pdo->commit();

                    notify($pdo, (int)$t['donor_id'], '✅ Food Delivered',
                        'Your donated food has been delivered successfully. Delivery proof uploaded.',
                        'delivered', $t['donation_id']);

                    if (!empty($t['recipient_id'])) {
                        notify($pdo, (int)$t['recipient_id'], '🍱 Food Delivered',
                            'Your food has been delivered. Delivery proof is available. Please confirm receipt.',
                            'delivered', $t['donation_id']);
                    }

                    notify_admins($pdo, '📷 Delivery Proof Uploaded',
                        'Collector uploaded proof for donation #' . $t['donation_id'],
                        'proof', $t['donation_id']);

                    set_flash('success', 'Delivery proof submitted!');
                    redirect(BASE_URL . 'collector/completed-tasks.php');

                } catch (Exception $ex) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $errors[] = 'Upload failed: ' . $ex->getMessage();
                }
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
            <h4 style="margin-bottom:8px">📍 Pickup From (Donor)</h4>
            <p><strong><?= sanitize($t['donor_name']) ?></strong></p>
            <p><?= sanitize($t['pickup_address']) ?>, <?= sanitize($t['pickup_area']) ?>, <?= sanitize($t['pickup_city']) ?> - <?= sanitize($t['pickup_pincode']) ?></p>
            <p><strong>Phone:</strong>
                <?php if ($t['donor_phone']): ?>
                    <a href="tel:<?= sanitize($t['donor_phone']) ?>"><?= sanitize($t['donor_phone']) ?></a>
                <?php else: ?>—<?php endif; ?>
            </p>
            <p><strong>Quantity:</strong> <?= (float)$t['quantity'] ?> <?= sanitize($t['unit']) ?></p>

            <?php if ($donorDestUrl): ?>
                <a href="<?= $donorDestUrl ?>" target="_blank" class="btn btn-primary btn-sm mt-2">
                    🧭 Navigate to Donor (Google Maps)
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($t['recipient_name'])): ?>
<div class="card mb-3" style="border-left:4px solid var(--green);background:#F1F8E9">
    <h3 class="card-title" style="color:var(--green-dark)">🏁 Deliver To — Recipient Details</h3>

    <div class="recipient-info">
        <div><strong>Name:</strong> <?= sanitize($t['recipient_name']) ?></div>
        <div><strong>Phone:</strong>
            <?php $rp = $t['recipient_phone'] ?: $t['recipient_contact']; ?>
            <?php if ($rp): ?>
                <a href="tel:<?= sanitize($rp) ?>"><?= sanitize($rp) ?></a>
            <?php else: ?>—<?php endif; ?>
        </div>
        <div><strong>Email:</strong> <?= sanitize($t['recipient_email'] ?: '—') ?></div>
        <div style="grid-column:1/-1">
            <strong>Delivery Address:</strong>
            <?php
                $addr = $t['recipient_delivery_address'] ?: $t['recipient_address'];
                echo sanitize($addr ?: '—');
            ?>
            <?= $t['recipient_area'] ? ', ' . sanitize($t['recipient_area']) : '' ?>
            <?= $t['recipient_city'] ? ', ' . sanitize($t['recipient_city']) : '' ?>
            <?= $t['recipient_pincode'] ? ' - ' . sanitize($t['recipient_pincode']) : '' ?>
        </div>
    </div>

    <?php if ($recipientDestUrl): ?>
        <a href="<?= $recipientDestUrl ?>" target="_blank" class="btn btn-primary btn-sm mt-2">
            🧭 Navigate to Recipient (Google Maps)
        </a>
    <?php endif; ?>
</div>
<?php endif; ?>

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

<?php if (in_array($t['status'], ['delivering','awaiting_proof'], true)): ?>
    <div class="card">
        <h3 class="card-title">📷 Upload Delivery Proof</h3>
        <p class="text-muted mb-2">Mandatory — take a photo of the food being handed over.</p>

        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_proof">

            <div class="form-group">
                <label class="form-label">Delivery Proof Photo *</label>
                <input type="file" name="proof_image" class="form-control" required accept="image/jpeg,image/png,image/webp"
                       onchange="previewImage(this, document.getElementById('proof-preview'))">
                <img id="proof-preview" style="display:none;max-width:260px;margin-top:10px;border-radius:10px">
            </div>

            <div class="form-group">
                <label class="form-label">Delivery Note (optional)</label>
                <textarea name="delivery_note" class="form-control" rows="2" placeholder="e.g. Food delivered successfully to the recipient."></textarea>
            </div>

            <button class="btn btn-primary btn-lg">Submit Delivery Proof</button>
        </form>
    </div>
<?php endif; ?>

<style>
.details-grid { display:grid; grid-template-columns: 260px 1fr; gap:20px; }
.details-grid img { width:100%; border-radius:12px; }
@media (max-width: 700px) { .details-grid { grid-template-columns: 1fr; } }

.recipient-info { display:grid; grid-template-columns: 1fr 1fr; gap:10px 24px; font-size:14px; }
@media (max-width: 600px) { .recipient-info { grid-template-columns: 1fr; } }
.recipient-info a { color: var(--green-dark); font-weight: 500; }
</style>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
