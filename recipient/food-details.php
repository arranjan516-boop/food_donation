<?php
// recipient/food-details.php
$pageTitle = 'Food Details';
require_once __DIR__ . '/../includes/dashboard-header.php';
require_once __DIR__ . '/../includes/location-functions.php';

$uid = current_user_id();
$id  = int_get('id');

$stmt = $pdo->prepare("
    SELECT d.*, u.name AS ngo_name, u.phone AS ngo_phone,
           (d.collected_quantity - d.distributed_quantity) AS available_qty
    FROM food_donations d
    JOIN users u ON u.user_id = d.ngo_id
    WHERE d.donation_id = :id
");
$stmt->execute([':id' => $id]);
$d = $stmt->fetch();

if (!$d) { set_flash('error', 'Donation not found.'); redirect(BASE_URL . 'recipient/available-food.php'); }

// NGO Rating
$avg = $pdo->prepare("SELECT AVG(rating) AS avg_rating, COUNT(*) AS total FROM ngo_ratings WHERE ngo_id = :n");
$avg->execute([':n' => $d['ngo_id']]);
$ratingInfo = $avg->fetch();

// Distance
$me = $pdo->prepare("SELECT latitude, longitude FROM users WHERE user_id = :u");
$me->execute([':u' => $uid]);
$me = $me->fetch();
$distKm = null;
if ($me['latitude'] !== null && $d['latitude'] !== null) {
    $distKm = haversine_km((float)$me['latitude'], (float)$me['longitude'], (float)$d['latitude'], (float)$d['longitude']);
}

$mine = $pdo->prepare("SELECT request_id, status FROM food_requests WHERE donation_id = :d AND recipient_id = :u LIMIT 1");
$mine->execute([':d' => $id, ':u' => $uid]);
$myRequest = $mine->fetch();

$validStatuses = ['picked_up', 'delivered', 'distributing'];
$isAvailable = ($d['available_qty'] > 0) && in_array($d['status'], $validStatuses);
?>

<div class="card mb-3">
    <div class="flex-between mb-2">
        <h2><?= sanitize($d['food_name']) ?></h2>
        <span class="badge badge-blue">🏢 <?= sanitize($d['ngo_name']) ?></span>
    </div>

    <div class="details-grid">
        <img src="<?= food_photo_url($d['food_photo']) ?>" alt="">

        <div>
            <p><strong>Category:</strong> <?= sanitize($d['food_category'] ?: '—') ?></p>
            <p><strong>Type:</strong> <?= sanitize($d['food_type'] ?: '—') ?></p>
            <p><strong>Available:</strong> <?= (float)$d['available_qty'] ?> <?= sanitize($d['unit']) ?></p>
            <p><strong>Best before:</strong> <?= $d['best_before'] ? date('d M Y, h:i A', strtotime($d['best_before'])) : '—' ?></p>
            
            <p><strong>NGO Rating:</strong> 
                <?php if ($ratingInfo['total'] > 0): ?>
                    <span style="color:#FFD700; font-size:18px;">★</span> 
                    <strong><?= round($ratingInfo['avg_rating'], 1) ?></strong>
                    <small class="text-muted">(<?= $ratingInfo['total'] ?> reviews)</small>
                <?php else: ?>
                    <span class="text-muted">No reviews yet</span>
                <?php endif; ?>
            </p>

            <p><strong>Location:</strong> <?= sanitize($d['area'] ?: $d['city']) ?>
                <?php if ($distKm !== null): ?>
                    <span class="badge badge-blue">📍 <?= number_format($distKm, 1) ?> KM away</span>
                <?php endif; ?>
            </p>
            <p class="text-muted">Posted <?= time_ago($d['created_at']) ?></p>

            <!-- CHAT BUTTON (Before Request) -->
            <a href="<?= BASE_URL ?>chat.php?donation_id=<?= (int)$d['donation_id'] ?>" class="btn btn-outline btn-sm mt-2">
                💬 Chat with NGO
            </a>
        </div>
    </div>
</div>

<?php if ($myRequest): ?>
    <div class="card">
        <h3 class="card-title">Your Request</h3>
        <p>Status: <?= status_badge($myRequest['status']) ?></p>
        <a href="<?= BASE_URL ?>recipient/request-details.php?id=<?= (int)$myRequest['request_id'] ?>" class="btn btn-outline">View Request</a>
    </div>
<?php elseif (!$isAvailable): ?>
    <div class="card">
        <div class="toast toast-info">🔒 This food is out of stock or unavailable.</div>
        <a href="<?= BASE_URL ?>recipient/available-food.php" class="btn btn-outline">← Back</a>
    </div>
<?php else: ?>
    <div class="card">
        <h3 class="card-title">Request This Food</h3>
        <a href="<?= BASE_URL ?>recipient/request-food.php?id=<?= (int)$d['donation_id'] ?>" class="btn btn-primary btn-lg">🍱 Request Food</a>
    </div>
<?php endif; ?>

<style>
.details-grid { display:grid; grid-template-columns: 320px 1fr; gap:24px; }
.details-grid img { width:100%; border-radius:12px; }
@media (max-width: 700px) { .details-grid { grid-template-columns: 1fr; } }
</style>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
