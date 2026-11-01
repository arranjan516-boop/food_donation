<?php
// ngo/received-food.php
$pageTitle = 'My Inventory';
require_once __DIR__ . '/../includes/dashboard-header.php';

$uid = current_user_id();

$stmt = $pdo->prepare("
    SELECT d.*, u.name AS donor_name,
           (d.collected_quantity - d.distributed_quantity) AS remaining_qty,
           TIMESTAMPDIFF(MINUTE, NOW(), DATE_ADD(d.collected_at, INTERVAL 2 HOUR)) AS minutes_left
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
                    <th>Remaining (Available)</th>
                    <th>Status / Time Left</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($batches as $b): ?>
                <?php 
                    $percent = $b['collected_quantity'] > 0 
                        ? ($b['distributed_quantity'] / $b['collected_quantity']) * 100 
                        : 0;
                        
                    $isExpired = in_array($b['status'], ['expired', 'completed', 'cancelled']);
                    $isOutOfStock = $b['remaining_qty'] <= 0;
                    $isExpiringSoon = (!$isExpired && !$isOutOfStock && $b['minutes_left'] !== null && $b['minutes_left'] <= 30 && $b['minutes_left'] > 0);
                    $timeIsUp = (!$isExpired && !$isOutOfStock && $b['minutes_left'] !== null && $b['minutes_left'] <= 0);
                ?>
                <tr>
                    <td class="flex gap-1" style="align-items:center">
                        <img class="thumb" src="<?= food_photo_url($b['food_photo']) ?>" alt="">
                        <strong><?= sanitize($b['food_name']) ?></strong>
                    </td>
                    <td><?= sanitize($b['donor_name']) ?></td>
                    <td><?= (float)$b['collected_quantity'] ?> <?= sanitize($b['unit']) ?></td>
                    <td><?= (float)$b['distributed_quantity'] ?> <?= sanitize($b['unit']) ?></td>
                    
                    <!-- Remaining Quantity Display -->
                    <td>
                        <?php if ($isOutOfStock): ?>
                            <span class="badge badge-red">0 (Out of Stock)</span>
                        <?php else: ?>
                            <span class="badge badge-green" style="font-size:14px;"><?= (float)$b['remaining_qty'] ?> <?= sanitize($b['unit']) ?></span>
                            <div style="background:#E5E7EB; height:8px; border-radius:20px; margin-top:6px; max-width:150px;">
                                <div style="width:<?= 100 - $percent ?>%; background: var(--green); height:100%; border-radius:20px;"></div>
                            </div>
                        <?php endif; ?>
                    </td>

                    <!-- Status & Expiry Timer Display -->
                    <td>
                        <?php if ($isExpired || $timeIsUp): ?>
                            <span class="badge badge-red">🔴 Expired</span>
                            <br><small class="text-muted" style="font-size:11px;">Not available to recipients</small>
                        <?php elseif ($isOutOfStock): ?>
                            <span class="badge badge-gray">✅ Fully Distributed</span>
                        <?php else: ?>
                            <span class="badge badge-blue" style="margin-bottom:4px;">🟢 Active</span>
                            <br>
                            <?php if ($isExpiringSoon): ?>
                                <span class="text-muted" style="font-size:12px; color:var(--orange); font-weight:600;">
                                    ⚠️ Expires in <?= $b['minutes_left'] ?> minutes!
                                </span>
                            <?php elseif ($b['minutes_left'] !== null): ?>
                                <span class="text-muted" style="font-size:12px;">
                                    ⏱️ <?= floor($b['minutes_left'] / 60) ?>h <?= $b['minutes_left'] % 60 ?>m left
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
