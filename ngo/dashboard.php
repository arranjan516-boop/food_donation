<?php
// ngo/dashboard.php
$pageTitle = 'NGO Dashboard';
require_once __DIR__ . '/../includes/dashboard-header.php';
require_once __DIR__ . '/../includes/location-functions.php';

if (current_role() !== 'ngo' && current_role() !== 'admin') {
    set_flash('error', 'Access denied.');
    redirect(BASE_URL . 'index.php');
}

$uid = current_user_id();

// ---- Inventory Stats ----
$inv = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT donation_id) AS total_batches,
        COALESCE(SUM(collected_quantity), 0) AS total_collected,
        COALESCE(SUM(distributed_quantity), 0) AS total_distributed,
        COALESCE(SUM(collected_quantity - distributed_quantity), 0) AS current_inventory
    FROM food_donations
    WHERE ngo_id = :u AND collected_quantity > 0
");
$inv->execute([':u' => $uid]);
$stats = $inv->fetch();

// ---- NGO Rating Stats ----
$ratingStmt = $pdo->prepare("SELECT AVG(rating) AS avg_rating, COUNT(*) AS total FROM ngo_ratings WHERE ngo_id = :u");
$ratingStmt->execute([':u' => $uid]);
$myRating = $ratingStmt->fetch();

// ---- Pending Requests from Recipients ----
$reqStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM food_requests fr
    JOIN food_donations d ON d.donation_id = fr.donation_id
    WHERE d.ngo_id = :u AND fr.status = 'pending'
");
$reqStmt->execute([':u' => $uid]);
$pendingReqs = (int)$reqStmt->fetchColumn();

// ---- Active Collections (Food that needs to be picked up) ----
$activeStmt = $pdo->prepare("
    SELECT nr.ngo_request_id, d.food_name, d.food_photo, d.area, d.city, d.people_served, d.status as donation_status
    FROM ngo_requests nr
    JOIN food_donations d ON d.donation_id = nr.donation_id
    WHERE nr.ngo_id = :u AND nr.status = 'accepted' AND d.status = 'ngo_assigned'
");
$activeStmt->execute([':u' => $uid]);
$activeCollections = $activeStmt->fetchAll();

?>

<div class="stats-grid">
    <div class="stat-card"><div class="icon-box blue">📦</div>
        <div><div class="stat-value"><?= (float)$stats['current_inventory'] ?></div><div class="stat-label">Current Inventory</div></div></div>
    <div class="stat-card"><div class="icon-box green">🍱</div>
        <div><div class="stat-value"><?= (float)$stats['total_collected'] ?></div><div class="stat-label">Total Collected</div></div></div>
    <div class="stat-card"><div class="icon-box orange">🤝</div>
        <div><div class="stat-value"><?= (float)$stats['total_distributed'] ?></div><div class="stat-label">Total Distributed</div></div></div>
    <div class="stat-card"><div class="icon-box yellow">📨</div>
        <div><div class="stat-value"><?= $pendingReqs ?></div><div class="stat-label">Pending Requests</div></div></div>
    <div class="stat-card"><div class="icon-box yellow">⭐</div>
        <div><div class="stat-value"><?= $myRating['total'] > 0 ? round($myRating['avg_rating'], 1) : 'N/A' ?></div><div class="stat-label">NGO Rating (<?= $myRating['total'] ?>)</div></div></div>
</div>

<!-- COLLECTIONS TO DO -->
<?php if ($activeCollections): ?>
<div class="table-card mb-3" style="border-left: 4px solid var(--orange); background: #FFF8E1;">
    <div class="table-card-header" style="background: transparent; border-bottom: 1px solid #FDE68A;">
        <h3 style="color: #E65100;">🚚 Pending Collections</h3>
    </div>
    <table class="table">
        <thead><tr><th>Food</th><th>Location</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($activeCollections as $a): ?>
            <tr>
                <td><strong><?= sanitize($a['food_name']) ?></strong></td>
                <td><?= sanitize($a['area'] ?: $a['city']) ?></td>
                <td>
                    <a href="<?= BASE_URL ?>ngo/collection-status.php?id=<?= (int)$a['ngo_request_id'] ?>" class="btn btn-primary btn-sm">Mark Collected</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- QUICK ACTIONS -->
<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
    <div class="card">
        <h3 class="card-title">📦 My Inventory</h3>
        <p class="text-muted">View the food batches you have collected and track what remains.</p>
        <a href="<?= BASE_URL ?>ngo/received-food.php" class="btn btn-outline btn-block mt-2">View Inventory</a>
    </div>
    <div class="card">
        <h3 class="card-title">📨 Recipient Requests</h3>
        <p class="text-muted">Review and approve food requests from recipients.</p>
        <a href="<?= BASE_URL ?>ngo/requests.php" class="btn btn-outline btn-block mt-2">
            View Requests <?= $pendingReqs > 0 ? "($pendingReqs)" : "" ?>
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
