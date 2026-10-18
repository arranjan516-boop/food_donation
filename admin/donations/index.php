<?php
// admin/donations/index.php
$pageTitle = 'Manage Donations';
require_once __DIR__ . '/../../includes/dashboard-header.php';

if (current_role() !== 'admin') { redirect(BASE_URL . 'index.php'); }

$status = get('status');
$search = get('q');

$sql = "SELECT d.*, u.name AS donor_name
        FROM food_donations d
        JOIN users u ON u.user_id = d.donor_id
        WHERE 1=1";
$params = [];
if ($status) { $sql .= " AND d.status = :s"; $params[':s'] = $status; }
if ($search) { $sql .= " AND (d.food_name LIKE :q1 OR u.name LIKE :q2)"; $params[':q1'] = "%$search%"; $params[':q2'] = "%$search%"; }
$sql .= " ORDER BY d.created_at DESC LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>

<form method="get" class="filter-bar mb-3" style="grid-template-columns: 2fr 1fr auto auto">
    <input type="text" name="q" value="<?= sanitize($search) ?>" class="form-control" placeholder="🔍 Food name or donor...">
    <select name="status" class="form-control">
        <option value="">All Statuses</option>
        <?php foreach (['available','requested','accepted','collector_assigned','ngo_assigned','pickup_scheduled','picked_up','out_for_delivery','delivered','completed','cancelled','expired'] as $st): ?>
            <option value="<?= $st ?>" <?= $status===$st?'selected':'' ?>><?= ucwords(str_replace('_',' ',$st)) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-primary">Filter</button>
    <a href="?" class="btn btn-outline">Reset</a>
</form>

<div class="admin-table-wrapper">
    <div class="admin-table-header">
        <h3>Donations (<?= count($rows) ?>)</h3>
        <a href="<?= BASE_URL ?>admin/export-csv.php?type=donations" class="btn btn-accent btn-sm">📥 Export CSV</a>
    </div>
    
    <?php if (!$rows): ?>
        <div style="padding:40px;text-align:center"><p class="text-muted">No donations found.</p></div>
    <?php else: ?>
        <table class="admin-table">
            <thead><tr><th>Photo</th><th>Food</th><th>Donor</th><th>Qty</th><th>Location</th><th>Method</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $d): ?>
                <tr>
                    <td><img class="thumb" src="<?= food_photo_url($d['food_photo']) ?>" alt="" style="width:40px;height:40px;border-radius:6px;object-fit:cover;"></td>
                    <td><strong><?= sanitize($d['food_name']) ?></strong></td>
                    <td><?= sanitize($d['donor_name']) ?></td>
                    <td><?= (float)$d['quantity'] ?> <?= sanitize($d['unit']) ?></td>
                    <td><?= sanitize($d['area'] ?: $d['city']) ?></td>
                    <td><?= ucwords(str_replace('_',' ',$d['delivery_preference'])) ?></td>
                    <td><?= status_badge($d['status']) ?></td>
                    <td><a href="view.php?id=<?= (int)$d['donation_id'] ?>" class="btn btn-outline btn-sm">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/dashboard-footer.php'; ?>
