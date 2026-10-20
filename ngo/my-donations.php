<?php
// ngo/my-donations.php
$pageTitle = 'My Donations';
require_once __DIR__ . '/../includes/dashboard-header.php';

$uid = current_user_id();
$stmt = $pdo->prepare("
    SELECT nr.*, 
           d.food_name, d.food_photo, d.unit, d.people_served, d.city, d.area,
           d.status AS donation_status, 
           u.name AS donor_name
    FROM ngo_requests nr
    JOIN food_donations d ON d.donation_id = nr.donation_id
    JOIN users u ON u.user_id = d.donor_id
    WHERE nr.ngo_id = :u
    ORDER BY nr.requested_at DESC
");
$stmt->execute([':u' => $uid]);
$rows = $stmt->fetchAll();
?>

<div class="table-card">
    <div class="table-card-header"><h3>My Accepted Donations (<?= count($rows) ?>)</h3></div>
    <?php if (!$rows): ?>
        <div style="padding:40px;text-align:center">
            <p class="text-muted">No donations yet.</p>
            <a href="<?= BASE_URL ?>ngo/available-donations.php" class="btn btn-primary mt-2">Browse Donations</a>
        </div>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Food</th><th>Donor</th><th>Location</th><th>People</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="flex gap-1" style="align-items:center">
                        <img class="thumb" src="<?= food_photo_url($r['food_photo']) ?>" alt="">
                        <strong><?= sanitize($r['food_name']) ?></strong>
                    </td>
                    <td><?= sanitize($r['donor_name']) ?></td>
                    <td><?= sanitize($r['area'] ?: $r['city']) ?></td>
                    <td><?= (int)$r['people_served'] ?></td>
                    <td><?= status_badge($r['donation_status']) ?></td>
                    <td>
                        <?php if ($r['donation_status'] === 'delivered' || $r['donation_status'] === 'completed'): ?>
                            <!-- If delivered, show View instead of Distribute -->
                            <a href="<?= BASE_URL ?>ngo/collection-status.php?id=<?= (int)$r['ngo_request_id'] ?>" class="btn btn-outline btn-sm">View</a>
                        
                        <?php elseif ($r['donation_status'] === 'picked_up' || $r['donation_status'] === 'out_for_delivery'): ?>
                            <!-- Ready to distribute -->
                            <a href="<?= BASE_URL ?>ngo/distribution.php?id=<?= (int)$r['ngo_request_id'] ?>" class="btn btn-accent btn-sm">Distribute</a>
                        
                        <?php elseif ($r['donation_status'] === 'ngo_assigned' || $r['donation_status'] === 'pickup_scheduled'): ?>
                            <!-- Ready for pickup -->
                            <a href="<?= BASE_URL ?>ngo/collection-status.php?id=<?= (int)$r['ngo_request_id'] ?>" class="btn btn-primary btn-sm">Pickup</a>
                        
                        <?php else: ?>
                            <!-- Rejected or Cancelled -->
                            <a href="<?= BASE_URL ?>ngo/donation-details.php?id=<?= (int)$r['donation_id'] ?>" class="btn btn-outline btn-sm">View</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
