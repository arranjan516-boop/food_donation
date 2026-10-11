<?php
// collector/dashboard.php
$pageTitle = 'Collector Dashboard';
require_once __DIR__ . '/../includes/dashboard-header.php';
require_once __DIR__ . '/../includes/location-functions.php';

if (current_role() !== 'collector' && current_role() !== 'admin') {
    set_flash('error', 'Access denied.');
    redirect(BASE_URL . 'index.php');
}

$uid = current_user_id();

// ---- Stats ----
$stats = $pdo->prepare("
    SELECT
      SUM(CASE WHEN status='available' THEN 1 ELSE 0 END) AS available_tasks,
      SUM(CASE WHEN status='accepted'  THEN 1 ELSE 0 END) AS accepted_tasks,
      SUM(CASE WHEN status IN ('pickup_started','picked_up','delivering') THEN 1 ELSE 0 END) AS active_deliveries,
      SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed_tasks
    FROM collector_tasks
    WHERE collector_id = :u
");
$stats->execute([':u' => $uid]);
$s = $stats->fetch();
$s['available_tasks']    = $s['available_tasks']    ?? 0;
$s['accepted_tasks']     = $s['accepted_tasks']     ?? 0;
$s['active_deliveries']  = $s['active_deliveries']  ?? 0;
$s['completed_tasks']    = $s['completed_tasks']    ?? 0;

// ---- Collector's own coordinates ----
$me = $pdo->prepare("SELECT latitude, longitude FROM users WHERE user_id = :u");
$me->execute([':u' => $uid]);
$me = $me->fetch();
$myLat = $me['latitude']  !== null && $me['latitude']  !== '' ? (float)$me['latitude']  : null;
$myLng = $me['longitude'] !== null && $me['longitude'] !== '' ? (float)$me['longitude'] : null;

// ---- Nearby available tasks (15 KM filter applied strictly) ----
$taskSql = "
    SELECT d.*, u.name AS donor_name,
           (SELECT COUNT(*) FROM collector_tasks ct
              WHERE ct.donation_id = d.donation_id
                AND ct.status IN ('accepted','pickup_started','picked_up','delivering','completed')) AS taken
    FROM food_donations d
    JOIN users u ON u.user_id = d.donor_id
    WHERE d.status IN ('available','requested','accepted')
      AND d.delivery_preference IN ('collector','any')
      AND (d.best_before IS NULL OR d.best_before > NOW())
    ORDER BY d.created_at DESC
    LIMIT 200
";
$candidates = $pdo->query($taskSql)->fetchAll();

$nearby = [];
if ($myLat !== null && $myLng !== null) {
    foreach ($candidates as $t) {
        if ((int)$t['taken'] > 0) continue;
        if ($t['latitude'] === null || $t['longitude'] === null) continue;

        $km = haversine_km($myLat, $myLng, (float)$t['latitude'], (float)$t['longitude']);
        if ($km === null || $km > MATCH_RADIUS_KM) continue;

        $t['distance_km'] = round($km, 2);
        $nearby[] = $t;
    }
    usort($nearby, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
}
$preview = array_slice($nearby, 0, 4);

// ---- My active tasks ----
$active = $pdo->prepare("
    SELECT ct.*, d.food_name, d.food_photo, d.city, d.area, u.name AS donor_name
    FROM collector_tasks ct
    JOIN food_donations d ON d.donation_id = ct.donation_id
    JOIN users u ON u.user_id = d.donor_id
    WHERE ct.collector_id = :u
      AND ct.status IN ('accepted','pickup_started','picked_up','delivering')
    ORDER BY ct.assigned_at DESC
");
$active->execute([':u' => $uid]);
$activeTasks = $active->fetchAll();
?>

<div class="stats-grid">
    <div class="stat-card"><div class="icon-box green">📋</div>
        <div><div class="stat-value"><?= (int)$s['available_tasks'] ?></div><div class="stat-label">Available</div></div></div>
    <div class="stat-card"><div class="icon-box yellow">✅</div>
        <div><div class="stat-value"><?= (int)$s['accepted_tasks'] ?></div><div class="stat-label">Accepted</div></div></div>
    <div class="stat-card"><div class="icon-box blue">🚚</div>
        <div><div class="stat-value"><?= (int)$s['active_deliveries'] ?></div><div class="stat-label">Active Deliveries</div></div></div>
    <div class="stat-card"><div class="icon-box purple">🏁</div>
        <div><div class="stat-value"><?= (int)$s['completed_tasks'] ?></div><div class="stat-label">Completed</div></div></div>
</div>

<?php if ($myLat === null || $myLng === null): ?>
    <div class="toast toast-info mb-2">
        📍 Set your <a href="<?= BASE_URL ?>collector/edit-profile.php">location</a>
        to see pickup tasks within <?= MATCH_RADIUS_KM ?> KM.
    </div>
<?php endif; ?>

<div class="flex-between mb-2">
    <h3>Nearby Pickup Tasks <span class="text-muted" style="font-size:14px">(within <?= MATCH_RADIUS_KM ?> KM)</span></h3>
    <a href="<?= BASE_URL ?>collector/available-tasks.php" class="btn btn-outline btn-sm">View All</a>
</div>

<?php if (!$preview): ?>
    <div class="empty-state">
        <div class="empty-icon">🚴</div>
        <h3>No pickup tasks nearby</h3>
        <p>New tasks will appear here automatically.</p>
    </div>
<?php else: ?>
    <div class="table-card mb-3">
        <table class="table">
            <thead><tr><th>Food</th><th>Donor</th><th>Pickup</th><th>Distance</th><th>Urgency</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($preview as $t): ?>
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
                    <td><span class="badge badge-blue">📍 <?= number_format($t['distance_km'], 1) ?> KM</span></td>
                    <td>
                        <?php
                            $uCls = in_array($t['urgency'], ['urgent','very_urgent']) ? 'badge-red' : 'badge-yellow';
                            $uTxt = ucfirst(str_replace('_',' ',$t['urgency']));
                        ?>
                        <span class="badge <?= $uCls ?>"><?= $uTxt ?></span>
                    </td>
                    <td>
                        <a href="<?= BASE_URL ?>collector/task-details.php?id=<?= (int)$t['donation_id'] ?>"
                           class="btn btn-primary btn-sm">Open</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<h3 class="mb-2">My Active Deliveries (<?= count($activeTasks) ?>)</h3>
<div class="table-card">
    <?php if (!$activeTasks): ?>
        <div style="padding:40px;text-align:center"><p class="text-muted">No active deliveries.</p></div>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Food</th><th>Donor</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($activeTasks as $t): ?>
                <tr>
                    <td><?= sanitize($t['food_name']) ?></td>
                    <td><?= sanitize($t['donor_name']) ?></td>
                    <td><?= status_badge($t['status']) ?></td>
                    <td><a href="<?= BASE_URL ?>collector/active-delivery.php?id=<?= (int)$t['task_id'] ?>" class="btn btn-primary btn-sm">Continue</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
