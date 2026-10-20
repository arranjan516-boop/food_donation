<?php
// collector/my-tasks.php
$pageTitle = 'My Tasks';
require_once __DIR__ . '/../includes/dashboard-header.php';

$uid = current_user_id();
$stmt = $pdo->prepare("
    SELECT ct.*, d.food_name, d.food_photo, d.city, d.area, u.name AS donor_name
    FROM collector_tasks ct
    JOIN food_donations d ON d.donation_id = ct.donation_id
    JOIN users u ON u.user_id = d.donor_id
    WHERE ct.collector_id = :u
      AND ct.status IN ('pending', 'accepted','pickup_started','picked_up','delivering','awaiting_proof')
    ORDER BY ct.assigned_at DESC
");
$stmt->execute([':u' => $uid]);
$rows = $stmt->fetchAll();
?>

<div class="table-card">
    <div class="table-card-header"><h3>My Active Tasks (<?= count($rows) ?>)</h3></div>
    <?php if (!$rows): ?>
        <div style="padding:40px;text-align:center">
            <p class="text-muted">No active tasks.</p>
            <a href="<?= BASE_URL ?>collector/available-tasks.php" class="btn btn-primary mt-2">Browse Available Tasks</a>
        </div>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Food</th><th>Donor</th><th>Pickup</th><th>Status</th><th>Assigned</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $t): ?>
                <tr>
                    <td class="flex gap-1" style="align-items:center">
                        <img class="thumb" src="<?= food_photo_url($t['food_photo']) ?>" alt="">
                        <?= sanitize($t['food_name']) ?>
                    </td>
                    <td><?= sanitize($t['donor_name']) ?></td>
                    <td><?= sanitize($t['area'] ?: $t['city']) ?></td>
                    <td><?= status_badge($t['status']) ?></td>
                    <td><?= time_ago($t['assigned_at']) ?></td>
                    <td>
                        <?php if ($t['status'] === 'pending'): ?>
                            <span class="badge badge-yellow">Waiting for Donor</span>
                        <?php else: ?>
                            <a href="<?= BASE_URL ?>collector/active-delivery.php?id=<?= (int)$t['task_id'] ?>" class="btn btn-primary btn-sm">Continue</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
