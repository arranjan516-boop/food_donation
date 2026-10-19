<?php
// recipient/available-food.php
$pageTitle = 'Available Food';
$extraCss = ['food-grid.css'];
require_once __DIR__ . '/../includes/dashboard-header.php';
require_once __DIR__ . '/../includes/location-functions.php';

$uid = current_user_id();

// Recipient coords
$me = $pdo->prepare("SELECT latitude, longitude FROM users WHERE user_id = :u");
$me->execute([':u' => $uid]);
$me = $me->fetch();
$myLat = $me['latitude']  !== null ? (float)$me['latitude']  : null;
$myLng = $me['longitude'] !== null ? (float)$me['longitude'] : null;

// Filters
$cat = get('category');
$type = get('type');
$urg = get('urgency');
$search = get('q');

$sql = "SELECT d.*, u.name AS donor_name
        FROM food_donations d
        JOIN users u ON u.user_id = d.donor_id
        WHERE d.status = 'available'
          AND (d.best_before IS NULL OR d.best_before > NOW())";
$params = [];
if ($cat)    { $sql .= " AND d.food_category = :cat";  $params[':cat'] = $cat; }
if ($type)   { $sql .= " AND d.food_type = :type";     $params[':type'] = $type; }
if ($urg)    { $sql .= " AND d.urgency = :urg";        $params[':urg'] = $urg; }
if ($search) { $sql .= " AND (d.food_name LIKE :q1 OR d.description LIKE :q2)";
               $params[':q1'] = "%$search%";
               $params[':q2'] = "%$search%"; }
$sql .= " ORDER BY d.created_at DESC LIMIT 80";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$all = $stmt->fetchAll();

// 15 KM filter
$donations = ($myLat !== null && $myLng !== null)
    ? filter_within_km($all, $myLat, $myLng, MATCH_RADIUS_KM)
    : [];
usort($donations, fn($a,$b) => $a['distance_km'] <=> $b['distance_km']);
?>

<div class="toast toast-info mb-2" style="font-size:13px">
    Showing donations within <strong><?= MATCH_RADIUS_KM ?> KM</strong> of your location.
    <?php if ($myLat === null): ?>
        Set your <a href="<?= BASE_URL ?>recipient/profile.php">location</a> to see nearby food.
    <?php endif; ?>
</div>

<form method="get" class="filter-bar">
    <input type="text" name="q" value="<?= sanitize($search) ?>" class="form-control" placeholder="🔍 Search...">
    <select name="category" class="form-control">
        <option value="">All Categories</option>
        <?php foreach (['Rice','Meals','Curry','Bread','Fruits','Vegetables','Bakery','Snacks','Other'] as $c): ?>
            <option value="<?= $c ?>" <?= $cat===$c?'selected':'' ?>><?= $c ?></option>
        <?php endforeach; ?>
    </select>
    <select name="type" class="form-control">
        <option value="">All Types</option>
        <?php foreach (['Vegetarian','Non-Vegetarian','Vegan'] as $t): ?>
            <option value="<?= $t ?>" <?= $type===$t?'selected':'' ?>><?= $t ?></option>
        <?php endforeach; ?>
    </select>
    <select name="urgency" class="form-control">
        <option value="">Any Urgency</option>
        <option value="normal"      <?= $urg==='normal'?'selected':'' ?>>Normal</option>
        <option value="urgent"      <?= $urg==='urgent'?'selected':'' ?>>Urgent</option>
        <option value="very_urgent" <?= $urg==='very_urgent'?'selected':'' ?>>Very Urgent</option>
    </select>
    <button class="btn btn-primary">Filter</button>
    <a href="?" class="btn btn-outline">Reset</a>
</form>

<?php if (!$donations): ?>
    <div class="empty-state">
        <div class="empty-icon">🍽️</div>
        <h3>No donations within <?= MATCH_RADIUS_KM ?> KM</h3>
        <p>Try again later or adjust filters.</p>
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
                $bb = $d['best_before'] ? date('d M, h:i A', strtotime($d['best_before'])) : '—';
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
                        <span>🥗 <?= sanitize($d['food_type']) ?></span>
                    </div>
                    <div class="food-card-meta">
                        <span>📍 <?= number_format($d['distance_km'],1) ?> KM</span>
                        <span>⏰ <?= $bb ?></span>
                    </div>
                    <div class="food-card-meta text-muted">By <?= sanitize($d['donor_name']) ?></div>
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
