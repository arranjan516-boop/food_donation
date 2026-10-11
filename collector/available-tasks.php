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

// Collector's coordinates
$me = $pdo->prepare("SELECT latitude, longitude FROM users WHERE user_id = :u");
$me->execute([':u' => $uid]);
$me = $me->fetch();
$myLat = $me['latitude']  !== null && $me['latitude']  !== '' ? (float)$me['latitude']  : null;
$myLng = $me['longitude'] !== null && $me['longitude'] !== '' ? (float)$me['longitude'] : null;

// Fetch candidate donations
$sql = "
    SELECT d.*, u.name AS donor_name,
           (SELECT COUNT(*) FROM collector_tasks ct
              WHERE ct.donation_id = d.donation_id
                AND ct.status IN ('accepted','pickup_started','picked_up','delivering','completed')) AS taken
    FROM food_donations d
    JOIN users u ON u.user_id = d.donor_id
    WHERE d.status IN ('accepted')
      AND d.delivery_preference IN ('collector','any')
      AND (d.best_before IS NULL OR d.best_before > NOW())
    ORDER BY d.created_at DESC
    LIMIT 200
";
$all = $pdo->query($sql)->fetchAll();

// 15 KM filter
$tasks = [];
if ($myLat !== null && $myLng !== null) {
    foreach ($all as $t) {
        if ((int)$t['taken'] > 0) continue;
        if ($t['latitude'] === null || $t['longitude'] === null) continue;

        $d = haversine_km($myLat, $myLng, (float)$t['latitude'], (float)$t['longitude']);
        if ($d === null || $d > MATCH_RADIUS_KM) continue;

        $t['distance_km'] = round($d, 2);
        $tasks[] = $t;
    }
    usort($tasks, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
}
?>

<?php if ($myLat === null || $myLng === null): ?>
    <div class="toast toast-info mb-2">
        📍 Set your <a href="<?= BASE_URL ?>collector/edit-profile.php">location in your Profile</a>
        to see pickup tasks within <?= MATCH_RADIUS_KM ?> KM.
    </div>
<?php else: ?>
    <div class="toast toast-info mb-2" style="font-size:13px">
        Showing pickup tasks within <strong><?= MATCH_RADIUS_KM ?> KM</strong> of your location.
    </div>
<?php endif; ?>

<?php if (!$tasks): ?>
    <div class="empty-state">
        <div class="empty-icon">🚴</div>
        <h3>No pickup tasks available nearby</h3>
        <p>New tasks will appear here when donors within <?= MATCH_RADIUS_KM ?> KM need a collector.</p>
    </div>
<?php else: ?>
    <div class="table-card">
        <div class="table-card-header"><h3>Available Tasks (<?= count($tasks) ?>)</h3></div>
        <table class="table">
            <thead><tr><th>Food</th><th>Donor</th><th>Pickup</th><th>Distance</th><th>Urgency</th><th>Best Before</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($tasks as $t): ?>
                <?php
                    $uCls = in_array($t['urgency'], ['urgent','very_urgent']) ? 'badge-red' : 'badge-yellow';
                    $uTxt = ucfirst(str_replace('_',' ',$t['urgency']));
                    $bb = $t['best_before'] ? date('d M, h:i A', strtotime($t['best_before'])) : '—';
                ?>
                <tr>
                    <td class="flex gap-1" style="align-items:center">
                        <img class="thumb" src="<?= food_photo_url($t['food_photo']) ?>" alt="">
                        <div>
                            <strong><?= sanitize($t['food_name']) ?></strong><br>
                            <small class="text-muted"><?= (int)$t['people_served'] ?> meals · <?= sanitize($t['food_type']) ?></small>
                        </div>
                    </td>
                    <td><?= sanitize($t['donor_name']) ?></td>
                    <td><?= sanitize($t['area'] ?: $t['city']) ?></td>
                    <td>
                        <?php if (isset($t['distance_km'])): ?>
                            <span class="badge badge-blue">
                                📍 <?= $t['distance_km'] < 0.1
                                    ? 'Same location'
                                    : number_format($t['distance_km'], 1) . ' KM' ?>
                            </span>
                        <?php else: ?>
                            <span class="badge badge-gray">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $uCls ?>"><?= $uTxt ?></span></td>
                    <td><?= $bb ?></td>
                    <td>
                        <a href="<?= BASE_URL ?>collector/task-details.php?id=<?= (int)$t['donation_id'] ?>"
                           class="btn btn-primary btn-sm">View &amp; Request</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
