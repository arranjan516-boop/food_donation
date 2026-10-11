<?php
// donor/donation-details.php
// Two-stage approval view + recipient info panel (visible after Stage 1).

$pageTitle = 'Donation Details';
require_once __DIR__ . '/../includes/dashboard-header.php';
require_once __DIR__ . '/../includes/notification-functions.php';

$uid = current_user_id();
$id  = int_get('id');

// Load donation
$sql = "SELECT * FROM food_donations WHERE donation_id = :id";
if (current_role() !== 'admin') $sql .= " AND donor_id = :u";
$stmt = $pdo->prepare($sql);
$params = [':id' => $id];
if (current_role() !== 'admin') $params[':u'] = $uid;
$stmt->execute($params);
$d = $stmt->fetch();
if (!$d) { set_flash('error', 'Donation not found.'); redirect(BASE_URL . 'donor/my-donations.php'); }

// Recipient requests (with full contact + address)
$rq = $pdo->prepare("
    SELECT fr.*,
           u.name AS recipient_name, u.phone AS recipient_phone, u.email AS recipient_email,
           u.address AS recipient_address, u.city AS recipient_city,
           u.area AS recipient_area, u.pincode AS recipient_pincode,
           u.latitude AS recipient_lat, u.longitude AS recipient_lng
    FROM food_requests fr
    JOIN users u ON u.user_id = fr.recipient_id
    WHERE fr.donation_id = :id
    ORDER BY fr.requested_at DESC
");
$rq->execute([':id' => $id]);
$recipientRequests = $rq->fetchAll();

$acceptedRecipient = null;
foreach ($recipientRequests as $r) {
    if (in_array($r['status'], ['accepted','completed'], true)) {
        $acceptedRecipient = $r; break;
    }
}
$stage2Unlocked = ($acceptedRecipient !== null);

// Collector requests
$ct = $pdo->prepare("
    SELECT ct.*, u.name AS collector_name, u.phone AS collector_phone,
           u.city AS collector_city, u.area AS collector_area
    FROM collector_tasks ct
    JOIN users u ON u.user_id = ct.collector_id
    WHERE ct.donation_id = :id
    ORDER BY ct.task_id DESC
");
$ct->execute([':id' => $id]);
$collectorRequests = $ct->fetchAll();

$collectorPending  = array_values(array_filter($collectorRequests, fn($c) => $c['status'] === 'pending'));
$collectorAccepted = null;
foreach ($collectorRequests as $c) {
    if (in_array($c['status'], ['accepted','pickup_started','picked_up','delivering','delivered','completed'], true)) {
        $collectorAccepted = $c; break;
    }
}

// NGO requests
$nr = $pdo->prepare("
    SELECT nr.*, u.name AS ngo_name, u.phone AS ngo_phone,
           u.city AS ngo_city, u.area AS ngo_area
    FROM ngo_requests nr
    JOIN users u ON u.user_id = nr.ngo_id
    WHERE nr.donation_id = :id
    ORDER BY nr.requested_at DESC
");
$nr->execute([':id' => $id]);
$ngoRequests = $nr->fetchAll();

$ngoPending  = array_values(array_filter($ngoRequests, fn($n) => $n['status'] === 'pending'));
$ngoAccepted = null;
foreach ($ngoRequests as $n) {
    if (in_array($n['status'], ['accepted','completed'], true)) {
        $ngoAccepted = $n; break;
    }
}

// Delivery proof
$pf = $pdo->prepare("
    SELECT dp.*, u.name AS uploader_name
    FROM delivery_proofs dp
    JOIN users u ON u.user_id = dp.uploaded_by
    WHERE dp.donation_id = :id
    ORDER BY dp.uploaded_at DESC LIMIT 1
");
$pf->execute([':id' => $id]);
$proof = $pf->fetch();
?>

<?php if (!in_array($d['status'], ['completed','cancelled','expired'], true)): ?>
<div class="card mb-3" style="background:#F0F9FF;border-left:4px solid var(--blue)">
    <p style="margin:0;font-size:14px">
        <strong>Workflow:</strong>
        Step 1 — Accept a <strong>recipient</strong>. &nbsp;
        Step 2 — Accept <strong>one collector or NGO</strong>. &nbsp;
        Step 3 — They deliver and upload proof. &nbsp;
        Step 4 — Recipient confirms → completed.
    </p>
</div>
<?php endif; ?>

<div class="card mb-3">
    <div class="flex-between mb-2">
        <h2><?= sanitize($d['food_name']) ?></h2>
        <?= status_badge($d['status']) ?>
    </div>

    <div class="details-grid">
        <img src="<?= food_photo_url($d['food_photo']) ?>" alt="" style="width:100%;border-radius:12px">
        <div>
            <p><strong>Category:</strong> <?= sanitize($d['food_category'] ?: '—') ?></p>
            <p><strong>Type:</strong> <?= sanitize($d['food_type'] ?: '—') ?></p>
            <p><strong>Quantity:</strong> <?= (float)$d['quantity'] ?> <?= sanitize($d['unit']) ?></p>
            <p><strong>People served:</strong> <?= (int)$d['people_served'] ?></p>
            <p><strong>Best before:</strong> <?= $d['best_before'] ? date('d M Y, h:i A', strtotime($d['best_before'])) : '—' ?></p>
            <p><strong>Delivery preference:</strong> <?= ucwords(str_replace('_',' ',$d['delivery_preference'])) ?></p>
            <p><strong>Pickup:</strong> <?= sanitize($d['address']) ?>, <?= sanitize($d['area']) ?>, <?= sanitize($d['city']) ?> - <?= sanitize($d['pincode']) ?></p>
            <p class="text-muted">Posted <?= time_ago($d['created_at']) ?></p>
        </div>
    </div>
</div>

<!-- =============== RECIPIENT INFO PANEL (visible after Stage 1) =============== -->
<?php if ($acceptedRecipient): ?>
<div class="card mb-3" style="border-left:4px solid var(--green);background:#F1F8E9">
    <h3 class="card-title" style="color:var(--green-dark)">📦 Delivery Destination — Recipient Details</h3>
    <p class="text-muted" style="font-size:13px;margin-top:-6px">
        Share this with the assigned collector/NGO so they can deliver the food.
    </p>

    <div class="recipient-info">
        <div><strong>Name:</strong> <?= sanitize($acceptedRecipient['recipient_name']) ?></div>
        <div><strong>Phone:</strong>
            <?php if ($acceptedRecipient['recipient_phone']): ?>
                <a href="tel:<?= sanitize($acceptedRecipient['recipient_phone']) ?>"><?= sanitize($acceptedRecipient['recipient_phone']) ?></a>
            <?php else: ?>—<?php endif; ?>
        </div>
        <div><strong>Email:</strong> <?= sanitize($acceptedRecipient['recipient_email'] ?: '—') ?></div>
        <div style="grid-column:1/-1">
            <strong>Delivery Address:</strong>
            <?= sanitize($acceptedRecipient['recipient_address'] ?: '—') ?>
            <?= $acceptedRecipient['recipient_area'] ? ', ' . sanitize($acceptedRecipient['recipient_area']) : '' ?>
            <?= $acceptedRecipient['recipient_city']  ? ', ' . sanitize($acceptedRecipient['recipient_city'])  : '' ?>
            <?= $acceptedRecipient['recipient_pincode'] ? ' - ' . sanitize($acceptedRecipient['recipient_pincode']) : '' ?>
        </div>
        <div><strong>Recipient's location:</strong>
            <?= $acceptedRecipient['recipient_lat'] && $acceptedRecipient['recipient_lng']
                ? sanitize(number_format($acceptedRecipient['recipient_lat'], 6) . ', ' . number_format($acceptedRecipient['recipient_lng'], 6))
                : '—' ?>
        </div>
        <div><strong>Quantity to deliver:</strong> <?= (float)$acceptedRecipient['quantity'] ?> <?= sanitize($d['unit']) ?></div>
    </div>

    <?php if ($acceptedRecipient['recipient_lat'] && $acceptedRecipient['recipient_lng']): ?>
        <a class="btn btn-outline btn-sm mt-2"
           href="https://www.google.com/maps/dir/?api=1&destination=<?= urlencode($acceptedRecipient['recipient_lat'] . ',' . $acceptedRecipient['recipient_lng']) ?>"
           target="_blank">🗺️ Open route in Google Maps</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- =============== STAGE 1: Recipient Requests =============== -->
<div class="table-card mb-3">
    <div class="table-card-header">
        <h3>Stage 1 — Recipient Requests (<?= count($recipientRequests) ?>)</h3>
        <?php if ($stage2Unlocked): ?>
            <span class="badge badge-green">✅ Recipient accepted</span>
        <?php endif; ?>
    </div>

    <?php if (!$recipientRequests): ?>
        <div style="padding:24px;text-align:center"><p class="text-muted">No recipient requests yet.</p></div>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Recipient</th><th>Quantity</th><th>Contact</th><th>Message</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($recipientRequests as $r): ?>
                <tr>
                    <td>
                        <strong><?= sanitize($r['recipient_name']) ?></strong><br>
                        <small class="text-muted"><?= sanitize($r['recipient_area'] ?: $r['recipient_city']) ?></small>
                    </td>
                    <td><?= (float)$r['quantity'] ?> <?= sanitize($d['unit']) ?></td>
                    <td><?= sanitize($r['recipient_phone'] ?: '—') ?></td>
                    <td style="max-width:220px"><?= sanitize($r['message'] ?: '—') ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td>
                        <?php if ($r['status'] === 'pending' && !$stage2Unlocked): ?>
                            <form method="post" action="<?= BASE_URL ?>donor/accept-recipient.php?id=<?= (int)$r['request_id'] ?>" style="display:inline">
                                <?= csrf_field() ?>
                                <button class="btn btn-primary btn-sm" data-confirm="Accept this recipient?">✅ Accept</button>
                            </form>
                            <form method="post" action="<?= BASE_URL ?>donor/reject-request.php?id=<?= (int)$r['request_id'] ?>" style="display:inline;margin-left:6px">
                                <?= csrf_field() ?>
                                <button class="btn btn-outline btn-sm" data-confirm="Reject this recipient?">Reject</button>
                            </form>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- =============== STAGE 2: Collector / NGO Requests =============== -->
<div class="table-card mb-3" style="opacity:<?= $stage2Unlocked ? '1' : '0.55' ?>">
    <div class="table-card-header">
        <h3>Stage 2 — Collector / NGO Requests</h3>
        <?php if (!$stage2Unlocked): ?>
            <span class="badge badge-yellow">🔒 Accept a recipient first</span>
        <?php elseif ($collectorAccepted || $ngoAccepted): ?>
            <span class="badge badge-green">✅ Assigned</span>
        <?php endif; ?>
    </div>

    <?php if (!$collectorPending && !$ngoPending && !$collectorAccepted && !$ngoAccepted): ?>
        <div style="padding:24px;text-align:center">
            <p class="text-muted">
                <?= $stage2Unlocked
                    ? 'Waiting for collectors or NGOs to request. They were just notified.'
                    : 'This section will activate once you accept a recipient.' ?>
            </p>
        </div>
    <?php else: ?>

        <?php if ($collectorPending || $collectorAccepted): ?>
            <div style="padding:14px 20px 6px;font-size:13px;font-weight:600;color:var(--gray-700)">🚴 Collector Requests</div>
            <table class="table">
                <thead><tr><th>Collector</th><th>Contact</th><th>Location</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($collectorRequests as $c): ?>
                    <?php if (!in_array($c['status'], ['pending','accepted','pickup_started','picked_up','delivering','delivered','completed'], true)) continue; ?>
                    <tr>
                        <td><strong><?= sanitize($c['collector_name']) ?></strong></td>
                        <td><?= sanitize($c['collector_phone'] ?: '—') ?></td>
                        <td><?= sanitize($c['collector_area'] ?: $c['collector_city']) ?></td>
                        <td><?= status_badge($c['status']) ?></td>
                        <td>
                            <?php if ($c['status'] === 'pending' && $stage2Unlocked && !$collectorAccepted && !$ngoAccepted): ?>
                                <form method="post" action="<?= BASE_URL ?>donor/accept-collector.php?type=collector&id=<?= (int)$c['task_id'] ?>" style="display:inline">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-primary btn-sm" data-confirm="Assign this collector? All other requests will be rejected.">✅ Accept</button>
                                </form>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($ngoPending || $ngoAccepted): ?>
            <div style="padding:14px 20px 6px;font-size:13px;font-weight:600;color:var(--gray-700)">🏢 NGO Requests</div>
            <table class="table">
                <thead><tr><th>NGO</th><th>Contact</th><th>Location</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($ngoRequests as $n): ?>
                    <?php if (!in_array($n['status'], ['pending','accepted','completed'], true)) continue; ?>
                    <tr>
                        <td><strong><?= sanitize($n['ngo_name']) ?></strong></td>
                        <td><?= sanitize($n['ngo_phone'] ?: '—') ?></td>
                        <td><?= sanitize($n['ngo_area'] ?: $n['ngo_city']) ?></td>
                        <td><?= status_badge($n['status']) ?></td>
                        <td>
                            <?php if ($n['status'] === 'pending' && $stage2Unlocked && !$collectorAccepted && !$ngoAccepted): ?>
                                <form method="post" action="<?= BASE_URL ?>donor/accept-collector.php?type=ngo&id=<?= (int)$n['ngo_request_id'] ?>" style="display:inline">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-primary btn-sm" data-confirm="Assign this NGO? All other requests will be rejected.">✅ Accept</button>
                                </form>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php if ($proof): ?>
<div class="card mb-3">
    <h3 class="card-title">📷 Delivery Proof</h3>
    <p><strong>Uploaded by:</strong> <?= sanitize($proof['uploader_name']) ?> (<?= sanitize($proof['uploaded_by_role']) ?>)</p>
    <p><strong>Date:</strong> <?= date('d M Y, h:i A', strtotime($proof['uploaded_at'])) ?></p>
    <?php if ($proof['delivery_note']): ?><p><strong>Note:</strong> <?= sanitize($proof['delivery_note']) ?></p><?php endif; ?>
    <img src="<?= PROOF_UPLOAD_URL . rawurlencode($proof['proof_image']) ?>"
         style="max-width:280px;border-radius:12px;margin-top:10px" alt="">
</div>
<?php endif; ?>

<style>
.details-grid { display:grid; grid-template-columns: 320px 1fr; gap:24px; }
@media (max-width: 700px) { .details-grid { grid-template-columns: 1fr; } }

.recipient-info { display:grid; grid-template-columns: 1fr 1fr; gap:10px 24px; font-size:14px; }
@media (max-width: 600px) { .recipient-info { grid-template-columns: 1fr; } }
.recipient-info a { color: var(--green-dark); font-weight: 500; }
</style>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
