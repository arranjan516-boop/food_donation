<?php
// recipient/available-food.php
$pageTitle = 'Available Food';
require_once __DIR__ . '/../includes/dashboard-header.php';
require_once __DIR__ . '/../includes/location-functions.php';

$uid = current_user_id();

// Recipient coordinates
$me = $pdo->prepare("SELECT latitude, longitude FROM users WHERE user_id = :u");
$me->execute([':u' => $uid]);
$me = $me->fetch();
$myLat = $me['latitude']  !== null && $me['latitude']  !== '' ? (float)$me['latitude']  : null;
$myLng = $me['longitude'] !== null && $me['longitude'] !== '' ? (float)$me['longitude'] : null;

// Fetch only food that has been collected by an NGO and has remaining inventory
$sql = "
    SELECT d.*, u.name AS ngo_name, u.city AS ngo_city, u.area AS ngo_area, u.latitude AS ngo_lat, u.longitude AS ngo_lng,
           (d.collected_quantity - d.distributed_quantity) AS available_qty
    FROM food_donations d
    JOIN users u ON u.user_id = d.ngo_id
    WHERE d.status IN ('picked_up', 'distributing')
      AND (d.collected_quantity - d.distributed_quantity) > 0
      AND u.is_verified = 1
    ORDER BY d.created_at DESC
";
$all = $pdo->query($sql)->fetchAll();

// Filter by 15 KM radius of the NGO
$donations = [];
if ($myLat !== null && $myLng !== null) {
    foreach ($all as $d) {
        if ($d['ngo_lat'] === null || $d['ngo_lng'] === null) continue;

        $km = haversine_km($myLat, $myLng, (float)$d['ngo_lat'], (float)$d['ngo_lng']);
        if ($km === null || $km > MATCH_RADIUS_KM) continue;

        $d['distance_km'] = round($km, 2);
        $donations[] = $d;
    }
    usort($donations, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
}
?>

<div class="toast toast-info mb-2" style="font-size:13px">
    Showing food available at verified NGOs within <strong><?= MATCH_RADIUS_KM ?> KM</strong> of your location.
</div>

<?php if (!$donations): ?>
    <div class="empty-state">
        <div class="empty-icon">🍽️</div>
        <h3>No food available nearby</h3>
        <p>NGOs near you have not collected any food yet. Check back soon.</p>
    </div>
<?php else: ?>
    <div class="food-grid">
        <?php foreach ($donations as $d): ?>
            <?php
                $urgencyMap = [
                    'urgent'      => ['badge-red','Urgent'],
                    'very_urgent' => ['badge-red','Very Urgent'],
                    'normal'      => ['badge-yellow','Normal'],
                ];
                [$uCls,$uTxt] = $urgencyMap[$d['urgency']] ?? ['badge-gray',$d['urgency']];
            ?>
            <div class="food-card">
                <img src="<?= food_photo_url($d['food_photo']) ?>" alt="">
                <div class="food-card-body">
                    <div class="flex-between">
                        <div class="food-card-title"><?= sanitize($d['food_name']) ?></div>
                        <span class="badge <?= $uCls ?>"><?= $uTxt ?></span>
                    </div>
                    <div class="food-card-meta">
                        <span>🏢 By <?= sanitize($d['ngo_name']) ?></span>
                    </div>
                    <div class="food-card-meta">
                        <span>📦 <?= (float)$d['available_qty'] ?> <?= sanitize($d['unit']) ?> available</span>
                        <span>📍 <?= number_format($d['distance_km'], 1) ?> KM</span>
                    </div>
                    <div class="food-card-actions">
                        <a href="<?= BASE_URL ?>recipient/food-details.php?id=<?= (int)$d['donation_id'] ?>" class="btn btn-primary btn-sm">View & Request</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
