<?php
// ngo/received-food.php
$pageTitle = 'My Inventory';
require_once __DIR__ . '/../includes/dashboard-header.php';

$uid = current_user_id();

$stmt = $pdo->prepare("
    SELECT d.*, u.name AS donor_name,
           (d.collected_quantity - d.distributed_quantity) AS remaining_qty
    FROM food_donations d
    JOIN users u ON u.user_id = d.donor_id
    WHERE d.ngo_id = :u AND d.collected_quantity > 0
    ORDER BY d.created_at DESC
");
$stmt->execute([':u' => $uid]);
$batches = $stmt->fetchAll();
?>

<div class="table-card">
    <div class="table-card-header"><h3>Received Food Inventory (<?= count($batches) ?>)</h3></div>
    <?php if (!$batches): ?>
        <div style="padding:40px;text-align:center">
            <p class="text-muted">You haven't collected any food yet.</p>
        </div>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Food</th>
                    <th>Donor</th>
                    <th>Collected</th>
                    <th>Distributed</th>
                    <th>Inventory Level</th>
                    <th>Remaining</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($batches as $b): ?>
                <?php 
                    $percent = $b['collected_quantity'] > 0 
                        ? ($b['distributed_quantity'] / $b['collected_quantity']) * 100 
                        : 0;
                ?>
                <tr>
                    <td class="flex gap-1" style="align-items:center">
                        <img class="thumb" src="<?= food_photo_url($b['food_photo']) ?>" alt="">
                        <strong><?= sanitize($b['food_name']) ?></strong>
                    </td>
                    <td><?= sanitize($b['donor_name']) ?></td>
                    <td><?= (float)$b['collected_quantity'] ?> <?= sanitize($b['unit']) ?></td>
                    <td><?= (float)$b['distributed_quantity'] ?> <?= sanitize($b['unit']) ?></td>
                    <td style="width:250px;">
                        <div style="background:#E5E7EB; height:12px; border-radius:20px; overflow:hidden; margin-top:10px;">
                            <div style="width:<?= $percent ?>%; background: var(--green); height:100%; transition: width 0.3s;"></div>
                        </div>
                        <small class="text-muted"><?= round($percent) ?>% distributed</small>
                    </td>
                    <td>
                        <?php if ($b['remaining_qty'] > 0): ?>
                            <span class="badge badge-green" style="font-size:14px;"><?= (float)$b['remaining_qty'] ?> <?= sanitize($b['unit']) ?></span>
                        <?php else: ?>
                            <span class="badge badge-red">Out of Stock</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
