<?php
// collector/available-tasks.php
$pageTitle = 'Available Pickup Tasks';
require_once __DIR__ . '/../includes/dashboard-header.php';
require_once __DIR__ . '/../includes/location-functions.php';

if (current_role() !== 'collector' && current_role() !== 'admin') {
    set_flash('error', 'Access denied.');
    redirect(BASE_URL . 'index.php');
}

$uid = current_user_id();

$me = $pdo->prepare("SELECT latitude, longitude FROM users WHERE user_id = :u");
$me->execute([':u' => $uid]);
$me = $me->fetch();
$myLat = $me['latitude']  !== null && $me['latitude']  !== '' ? (float)$me['latitude']  : null;
$myLng = $me['longitude'] !== null && $me['longitude'] !== '' ? (float)$me['longitude'] : null;

$sql = "
    SELECT fr.request_id, fr.quantity AS req_qty, fr.delivery_method, 
           d.donation_id, d.food_name, d.food_photo, d.unit, d.area AS ngo_area, d.city AS ngo_city, d.latitude AS ngo_lat, d.longitude AS ngo_lng,
           n.name AS ngo_name,
           r.name AS recipient_name, r.area AS recipient_area, r.city AS recipient_city,
           (SELECT COUNT(*) FROM collector_tasks ct WHERE ct.request_id = fr.request_id AND ct.status IN ('accepted','pickup_started','picked_up','delivering','delivered','completed')) AS taken
    FROM food_requests fr
    JOIN food_donations d ON d.donation_id = fr.donation_id
    JOIN users n ON n.user_id = d.ngo_id
    JOIN users r ON r.user_id = fr.recipient_id
    WHERE fr.delivery_method = 'collector'
      AND fr.status = 'accepted'
      AND d.status IN ('picked_up', 'delivered', 'distributing')
    ORDER BY fr.accepted_at DESC
    LIMIT 50
";
$all = $pdo->query($sql)->fetchAll();

$tasks = [];
if ($myLat !== null && $myLng !== null) {
    foreach ($all as $t) {
        if ((int)$t['taken'] > 0) continue;
        if ($t['ngo_lat'] === null || $t['ngo_lng'] === null) continue;

        $d = haversine_km($myLat, $myLng, (float)$t['ngo_lat'], (float)$t['ngo_lng']);
        if ($d === null || $d > MATCH_RADIUS_KM) continue;

        $t['distance_km'] = round($d, 2);
        $tasks[] = $t;
    }
    usort($tasks, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
}
?>

<?php if ($myLat === null || $myLng === null): ?>
    <div class="toast toast-info mb-2">
        📍 Set your <a href="<?= BASE_URL ?>collector/edit-profile.php">location in your Profile</a> to see tasks.
    </div>
<?php endif; ?>

<?php if (!$tasks): ?>
    <div class="empty-state">
        <div class="empty-icon">🚴</div>
        <h3>No delivery tasks available</h3>
        <p>New tasks will appear here when NGOs assign deliveries.</p>
    </div>
<?php else: ?>
    <div class="table-card">
        <div class="table-card-header"><h3>Available Delivery Tasks (<?= count($tasks) ?>)</h3></div>
        <table class="table">
            <thead><tr><th>Food</th><th>NGO (Pickup)</th><th>Recipient (Drop)</th><th>Distance</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($tasks as $t): ?>
                <tr>
                    <td class="flex gap-1" style="align-items:center">
                        <img class="thumb" src="<?= food_photo_url($t['food_photo']) ?>" alt="">
                        <div>
                            <strong><?= sanitize($t['food_name']) ?></strong><br>
                            <small class="text-muted"><?= (float)$t['req_qty'] ?> <?= sanitize($t['unit']) ?></small>
                        </div>
                    </td>
                    <td>🏢 <?= sanitize($t['ngo_name']) ?></td>
                    <td>🙋 <?= sanitize($t['recipient_name']) ?></td>
                    <td><span class="badge badge-blue">📍 <?= number_format($t['distance_km'], 1) ?> KM</span></td>
                    <td>
                        <a href="<?= BASE_URL ?>collector/task-details.php?id=<?= (int)$t['donation_id'] ?>&request_id=<?= (int)$t['request_id'] ?>" class="btn btn-primary btn-sm">View &amp; Request</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
