<?php
// donor/donation-details.php
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

// Fetch NGO Requests
$nr = $pdo->prepare("
    SELECT nr.*, u.name AS ngo_name, u.phone AS ngo_phone, u.email AS ngo_email
    FROM ngo_requests nr
    JOIN users u ON u.user_id = nr.ngo_id
    WHERE nr.donation_id = :id
    ORDER BY nr.requested_at DESC
");
$nr->execute([':id' => $id]);
$ngoRequests = $nr->fetchAll();

$pendingNgo = null;
$acceptedNgo = null;
foreach ($ngoRequests as $n) {
    if ($n['status'] === 'pending') $pendingNgo = $n;
    if ($n['status'] === 'accepted') $acceptedNgo = $n;
}
?>

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
            <p><strong>Pickup:</strong> <?= sanitize($d['address']) ?>, <?= sanitize($d['area']) ?>, <?= sanitize($d['city']) ?> - <?= sanitize($d['pincode']) ?></p>
            <p class="text-muted">Posted <?= time_ago($d['created_at']) ?></p>
        </div>
    </div>
</div>

<!-- Visual Timeline (Will show up to 'picked_up' since we removed collector steps) -->
<div class="card mb-3" style="padding: 20px;">
    <?php render_timeline($d['status']); ?>
</div>

<!-- NGO REQUEST SECTION -->
<?php if ($d['status'] === 'available'): ?>
    <div class="table-card mb-3">
        <div class="table-card-header">
            <h3>NGO Requests (<?= count($ngoRequests) ?>)</h3>
        </div>
        <?php if (!$ngoRequests): ?>
            <div style="padding:24px;text-align:center"><p class="text-muted">Waiting for nearby NGOs to request this food...</p></div>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>NGO</th><th>Contact</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($ngoRequests as $n): ?>
                    <tr>
                        <td><strong><?= sanitize($n['ngo_name']) ?></strong></td>
                        <td><?= sanitize($n['ngo_phone'] ?: '—') ?></td>
                        <td><?= status_badge($n['status']) ?></td>
                        <td>
                            <?php if ($n['status'] === 'pending'): ?>
                                <form method="post" action="<?= BASE_URL ?>donor/accept-collector.php?type=ngo&id=<?= (int)$n['ngo_request_id'] ?>" style="display:inline">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-primary btn-sm" data-confirm="Accept this NGO?">✅ Accept NGO</button>
                                </form>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- ACCEPTED NGO INFO -->
<?php if ($acceptedNgo): ?>
    <div class="card mb-3" style="border-left:4px solid var(--green);background:#F1F8E9">
        <h3 class="card-title" style="color:var(--green-dark)">🏢 Assigned NGO</h3>
        <div class="recipient-info">
            <div><strong>Name:</strong> <?= sanitize($acceptedNgo['ngo_name']) ?></div>
            <div><strong>Phone:</strong> <?= sanitize($acceptedNgo['ngo_phone'] ?: '—') ?></div>
            <div style="grid-column:1/-1"><strong>Email:</strong> <?= sanitize($acceptedNgo['ngo_email']) ?></div>
        </div>
    </div>
<?php endif; ?>

<!-- DELIVERY PROOF (If uploaded by NGO later) -->
<?php 
$pf = $pdo->prepare("SELECT dp.*, u.name AS uploader_name FROM delivery_proofs dp JOIN users u ON u.user_id = dp.uploaded_by WHERE dp.donation_id = :id ORDER BY uploaded_at DESC LIMIT 1");
$pf->execute([':id' => $id]);
$proof = $pf->fetch();
if ($proof): 
?>
<div class="card mb-3">
    <h3 class="card-title">📷 Collection Proof</h3>
    <img src="<?= PROOF_UPLOAD_URL . rawurlencode($proof['proof_image']) ?>" style="max-width:280px;border-radius:12px;margin-top:10px" alt="">
</div>
<?php endif; ?>

<style>
.details-grid { display:grid; grid-template-columns: 320px 1fr; gap:24px; }
@media (max-width: 700px) { .details-grid { grid-template-columns: 1fr; } }
.recipient-info { display:grid; grid-template-columns: 1fr 1fr; gap:10px 24px; font-size:14px; }
</style>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
