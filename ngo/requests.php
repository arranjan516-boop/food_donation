<?php
// ngo/requests.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification-functions.php';

require_login();
if (current_role() !== 'ngo' && current_role() !== 'admin') {
    set_flash('error', 'Access denied.');
    redirect(BASE_URL . 'index.php');
}

$uid = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');
    $reqId = int_post('request_id');

    if ($action === 'approve') {
        $deliveryMethod = post('delivery_method');
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                SELECT fr.*, d.donation_id, d.ngo_id, d.unit, (d.collected_quantity - d.distributed_quantity) AS live_qty
                FROM food_requests fr
                JOIN food_donations d ON d.donation_id = fr.donation_id
                WHERE fr.request_id = :r AND d.ngo_id = :u FOR UPDATE
            ");
            $stmt->execute([':r' => $reqId, ':u' => $uid]);
            $req = $stmt->fetch();

            if (!$req) throw new Exception('Request not found.');
            if ($req['quantity'] > $req['live_qty']) throw new Exception('Not enough inventory.');

            $pdo->prepare("UPDATE food_requests SET status='accepted', accepted_at=NOW(), delivery_method=:m WHERE request_id=:r")
                ->execute([':m' => $deliveryMethod, ':r' => $reqId]);
            
            $pdo->prepare("UPDATE food_donations SET distributed_quantity = distributed_quantity + :q WHERE donation_id=:d")
                ->execute([':q' => $req['quantity'], ':d' => $req['donation_id']]);

            $pdo->commit();
            notify($pdo, (int)$req['recipient_id'], '✅ Request Approved', 'Your food request has been approved.', 'request_accepted', $req['donation_id']);
            set_flash('success', 'Request approved!');

        } catch (Exception $ex) {
            $pdo->rollBack();
            set_flash('error', $ex->getMessage());
        }
        redirect(BASE_URL . 'ngo/requests.php');
    }
}

// Load pending AND accepted requests to show action buttons
$stmt = $pdo->prepare("
    SELECT fr.*, d.food_name, d.unit, u.name AS recipient_name
    FROM food_requests fr
    JOIN food_donations d ON d.donation_id = fr.donation_id
    JOIN users u ON u.user_id = fr.recipient_id
    WHERE d.ngo_id = :u AND fr.status IN ('pending', 'accepted')
    ORDER BY fr.requested_at DESC
");
$stmt->execute([':u' => $uid]);
$requests = $stmt->fetchAll();

$pageTitle = 'Recipient Requests';
require_once __DIR__ . '/../includes/dashboard-header.php';
?>

<div class="table-card">
    <div class="table-card-header"><h3>Recipient Requests</h3></div>
    <?php if (!$requests): ?>
        <div style="padding:40px;text-align:center"><p class="text-muted">No requests yet.</p></div>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Recipient</th><th>Food</th><th>Qty</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($requests as $r): ?>
                <tr>
                    <td><strong><?= sanitize($r['recipient_name']) ?></strong></td>
                    <td><?= sanitize($r['food_name']) ?></td>
                    <td><?= (float)$r['quantity'] ?> <?= sanitize($r['unit']) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td>
                        <?php if ($r['status'] === 'pending'): ?>
                            <form method="post" style="display:flex; gap:8px; align-items:center;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>">
                                <input type="hidden" name="action" value="approve">
                                <select name="delivery_method" class="form-control" style="width:auto;">
                                    <option value="ngo">🏢 NGO will Deliver</option>
                                    <option value="collector">🚴 Assign Collector</option>
                                </select>
                                <button class="btn btn-primary btn-sm" data-confirm="Approve?">✅ Approve</button>
                            </form>
                        <?php elseif ($r['status'] === 'accepted'): ?>
                            <a href="<?= BASE_URL ?>chat.php?donation_id=<?= (int)$r['donation_id'] ?>" class="btn btn-outline btn-sm">💬 Chat</a>
                            
                            <?php if ($r['delivery_method'] === 'ngo'): ?>
                                <a href="<?= BASE_URL ?>ngo/track-delivery.php?id=<?= (int)$r['request_id'] ?>" class="btn btn-primary btn-sm" style="margin-left:6px;">🚚 Deliver Food</a>
                            <?php else: ?>
                                <span class="badge badge-yellow" style="margin-left:6px;">Waiting for Collector</span>
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
