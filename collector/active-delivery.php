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
    SELECT ct.*, d.donation_id, d.donor_id, d.food_name, d.food_photo, d.unit, d.quantity, d.people_served,
           d.address AS pickup_address, d.area AS pickup_area, d.city AS pickup_city,
           u.name AS donor_name, u.phone AS donor_phone,
           fr.request_id, fr.recipient_id, fr.contact_number AS recipient_contact,
           fr.delivery_address AS recipient_delivery_address,
           ru.name AS recipient_name, ru.phone AS recipient_phone,
           ru.city AS recipient_city, ru.area AS recipient_area, ru.address AS recipient_address
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
            
            // 1. Update collector task status
            if ($next === 'picked_up') {
                $pdo->prepare("UPDATE collector_tasks SET status=:s, pickup_time=NOW() WHERE task_id=:t")
                    ->execute([':s' => $next, ':t' => $taskId]);
            } else {
                $pdo->prepare("UPDATE collector_tasks SET status=:s WHERE task_id=:t")
                    ->execute([':s' => $next, ':t' => $taskId]);
            }

            // 2. Update donation status (Matching the timeline exactly)
            $donationStatus = match ($next) {
                'pickup_started' => 'picked_up',         // Skip pickup_scheduled, go straight to picked_up for timeline
                'picked_up'      => 'picked_up',
                'delivering'     => 'out_for_delivery',
                'awaiting_proof' => 'delivered',
                default          => $next,
            };
            
            $pdo->prepare("UPDATE food_donations SET status=:s WHERE donation_id=:d")
                ->execute([':s' => $donationStatus, ':d' => $t['donation_id']]);

            // 3. Log History
            $pdo->prepare("INSERT INTO donation_history (donation_id, old_status, new_status, changed_by)
                           VALUES (:d, :old, :new, :u)")
                ->execute([':d' => $t['donation_id'], ':old' => $t['status'], ':new' => $next, ':u' => $uid]);

            // 4. Notify Recipient
            if ($next === 'picked_up' && !empty($t['recipient_id'])) {
                notify($pdo, (int)$t['recipient_id'], '🍱 Food Collected',
                    'Your food has been collected and is on the way.', 'delivery', $t['donation_id']);
            }

            set_flash('success', 'Status updated to: ' . ucwords(str_replace('_', ' ', $next)));
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
            <h4>📍 Pickup From</h4>
            <p><strong><?= sanitize($t['donor_name']) ?></strong> (<?= sanitize($t['donor_phone']) ?>)</p>
            <p><?= sanitize($t['pickup_address']) ?>, <?= sanitize($t['pickup_area']) ?></p>
        </div>
    </div>
</div>

<!-- Visual Timeline -->
<div class="card mb-3" style="padding: 20px;">
    <?php render_timeline($t['status']); ?>
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

<?php if (in_array($t['status'], ['delivering','awaiting_proof'], true)): ?>
    <div class="card">
        <h3 class="card-title">📷 Upload Delivery Proof</h3>
        <p class="text-muted mb-2">Mandatory — take a photo of the food being handed over.</p>

        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_proof">
            <div class="form-group">
                <label class="form-label">Delivery Proof Photo *</label>
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
@media (max-width: 700px) { .details-grid { grid-template-columns: 1fr; } }
</style>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
