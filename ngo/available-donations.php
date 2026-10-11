<?php
// ngo/available-donations.php
$pageTitle = 'Available Donations';
require_once __DIR__ . '/../includes/dashboard-header.php';
require_once __DIR__ . '/../includes/location-functions.php';

if (current_role() !== 'ngo' && current_role() !== 'admin') {
    set_flash('error', 'Access denied.');
    redirect(BASE_URL . 'index.php');
}

$uid = current_user_id();

// NGO's own coordinates
$me = $pdo->prepare("SELECT latitude, longitude FROM users WHERE user_id = :u");
$me->execute([':u' => $uid]);
$me = $me->fetch();
$myLat = $me['latitude']  !== null && $me['latitude']  !== '' ? (float)$me['latitude']  : null;
$myLng = $me['longitude'] !== null && $me['longitude'] !== '' ? (float)$me['longitude'] : null;

$all = $pdo->query("
    SELECT d.*, u.name AS donor_name,
      (SELECT COUNT(*) FROM ngo_requests nr
         WHERE nr.donation_id = d.donation_id
           AND nr.status IN ('accepted','completed')) AS ngo_taken
    FROM food_donations d
    JOIN users u ON u.user_id = d.donor_id
    WHERE d.status IN ('available','requested','accepted')
      AND d.delivery_preference IN ('ngo','any')
      AND (d.best_before IS NULL OR d.best_before > NOW())
    ORDER BY d.created_at DESC
    LIMIT 200
")->fetchAll();

// ---- Apply 15 KM filter strictly ----
$rows = [];
if ($myLat !== null && $myLng !== null) {
    foreach ($all as $d) {
        if ((int)$d['ngo_taken'] > 0) continue;
        if ($d['latitude'] === null || $d['longitude'] === null) continue;

        $km = haversine_km($myLat, $myLng, (float)$d['latitude'], (float)$d['longitude']);
        if ($km === null || $km > MATCH_RADIUS_KM) continue;

        $d['distance_km'] = round($km, 2);
        $rows[] = $d;
    }
    usort($rows, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
}
?>

<?php if ($myLat === null || $myLng === null): ?>
    <div class="toast toast-info mb-2">
        📍 Set your <a href="<?= BASE_URL ?>ngo/edit-profile.php">location in your Profile</a>
        to see donations within <?= MATCH_RADIUS_KM ?> KM.
    </div>
<?php else: ?>
    <div class="toast toast-info mb-2" style="font-size:13px">
        Showing donations within <strong><?= MATCH_RADIUS_KM ?> KM</strong> of your location
        (<?= number_format($myLat, 4) ?>, <?= number_format($myLng, 4) ?>).
    </div>
<?php endif; ?>

<?php if (!$rows): ?>
    <div class="empty-state">
        <div class="empty-icon">🍱</div>
        <h3>No donations available nearby</h3>
        <p>New donations within <?= MATCH_RADIUS_KM ?> KM will appear here.</p>
    </div>
<?php else: ?>
    <div class="table-card">
        <div class="table-card-header"><h3>Available Donations (<?= count($rows) ?>)</h3></div>
        <table class="table">
            <thead><tr><th>Food</th><th>Donor</th><th>Location</th><th>Distance</th><th>People</th><th>Urgency</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $d): ?>
                <?php
                    $uCls = in_array($d['urgency'], ['urgent','very_urgent']) ? 'badge-red' : 'badge-yellow';
                    $uTxt = ucfirst(str_replace('_',' ',$d['urgency']));
                ?>
                <tr>
                    <td class="flex gap-1" style="align-items:center">
                        <img class="thumb" src="<?= food_photo_url($d['food_photo']) ?>" alt="">
                        <div>
                            <strong><?= sanitize($d['food_name']) ?></strong><br>
                            <small class="text-muted"><?= sanitize($d['food_type']) ?> · <?= (float)$d['quantity'] ?> <?= sanitize($d['unit']) ?></small>
                        </div>
                    </td>
                    <td><?= sanitize($d['donor_name']) ?></td>
                    <td><?= sanitize($d['area'] ?: $d['city']) ?></td>
                    <td><span class="badge badge-blue">📍 <?= number_format($d['distance_km'], 1) ?> KM</span></td>
                    <td><?= (int)$d['people_served'] ?></td>
                    <td><span class="badge <?= $uCls ?>"><?= $uTxt ?></span></td>
                    <td><a href="<?= BASE_URL ?>ngo/donation-details.php?id=<?= (int)$d['donation_id'] ?>" class="btn btn-primary btn-sm">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
