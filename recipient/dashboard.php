<?php
// recipient/dashboard.php
$pageTitle = 'Recipient Dashboard';
$extraCss = ['food-grid.css'];
require_once __DIR__ . '/../includes/dashboard-header.php';
require_once __DIR__ . '/../includes/location-functions.php';

if (current_role() !== 'recipient' && current_role() !== 'admin') {
    set_flash('error', 'Access denied.');
    redirect(BASE_URL . 'index.php');
}

$uid = current_user_id();

// Recipient coordinates
$me = $pdo->prepare("SELECT * FROM users WHERE user_id = :u");
$me->execute([':u' => $uid]);
$me = $me->fetch();
$myLat = $me['latitude'];
$myLng = $me['longitude'];

// ---- Stats ----
$stats = $pdo->prepare("
    SELECT COUNT(*) AS total_requests,
           SUM(CASE WHEN status='pending'   THEN 1 ELSE 0 END) AS pending,
           SUM(CASE WHEN status='accepted'  THEN 1 ELSE 0 END) AS accepted,
           SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed
    FROM food_requests
    WHERE recipient_id = :u
");
$stats->execute([':u' => $uid]);
$s = $stats->fetch();
$s['total_requests'] = $s['total_requests'] ?? 0;
$s['pending']        = $s['pending']        ?? 0;
$s['accepted']       = $s['accepted']       ?? 0;
$s['completed']      = $s['completed']      ?? 0;

// ---- Available food within 15 KM ----
$allAvailable = $pdo->query("
    SELECT d.*, u.name AS donor_name
    FROM food_donations d
    JOIN users u ON u.user_id = d.donor_id
    WHERE d.status = 'available'
      AND (d.best_before IS NULL OR d.best_before > NOW())
    ORDER BY d.created_at DESC
    LIMIT 60
")->fetchAll();

$nearby = filter_within_km($allAvailable, $myLat, $myLng, MATCH_RADIUS_KM);
usort($nearby, fn($a,$b) => $a['distance_km'] <=> $b['distance_km']);
$preview = array_slice($nearby, 0, 6);
?>

<div class="stats-grid">
    <div class="stat-card"><div class="icon-box green">📨</div>
        <div><div class="stat-value"><?= $s['total_requests'] ?></div><div class="stat-label">My Requests</div></div></div>
    <div class="stat-card"><div class="icon-box yellow">⏳</div>
        <div><div class="stat-value"><?= $s['pending'] ?></div><div class="stat-label">Pending</div></div></div>
    <div class="stat-card"><div class="icon-box blue">👍</div>
        <div><div class="stat-value"><?= $s['accepted'] ?></div><div class="stat-label">Accepted</div></div></div>
    <div class="stat-card"><div class="icon-box purple">✅</div>
        <div><div class="stat-value"><?= $s['completed'] ?></div><div class="stat-label">Completed</div></div></div>
</div>

<div class="flex-between mb-2">
    <h3>Food Near You <span class="text-muted" style="font-size:14px">(within <?= MATCH_RADIUS_KM ?> KM)</span></h3>
    <a href="<?= BASE_URL ?>recipient/available-food.php" class="btn btn-outline btn-sm">View All</a>
</div>

<?php if (!$myLat || !$myLng): ?>
    <div class="toast toast-info mb-2">
        📍 Set your location in <a href="<?= BASE_URL ?>recipient/profile.php">Profile</a> to see food within <?= MATCH_RADIUS_KM ?> KM.
    </div>
<?php endif; ?>

<?php if (!$preview): ?>
    <div class="empty-state">
        <div class="empty-icon">🍽️</div>
        <h3>No food available nearby right now</h3>
        <p>Check back soon — new donations arrive daily.</p>
    </div>
<?php else: ?>
    <div class="food-grid">
        <?php foreach ($preview as $d): ?>
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
                        <span>🍽️ <?= (int)$d['people_served'] ?> meals</span>
                        <span>📍 <?= number_format($d['distance_km'],1) ?> KM</span>
                    </div>
                    <div class="food-card-actions">
                        <a href="<?= BASE_URL ?>recipient/food-details.php?id=<?= (int)$d['donation_id'] ?>"
                           class="btn btn-primary btn-sm">View Details</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
