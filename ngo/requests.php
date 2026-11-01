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
    $collectorTaskId = int_post('collector_task_id');

    // Approve Recipient Request
    if ($action === 'approve') {
        $deliveryMethod = post('delivery_method');
        try {
            $pdo->beginTransaction();
            
            // 1. Lock the row
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

            // 2. Update request status
            $pdo->prepare("UPDATE food_requests SET status='accepted', accepted_at=NOW(), delivery_method=:m WHERE request_id=:r")
                ->execute([':m' => $deliveryMethod, ':r' => $reqId]);
            
            // 3. Deduct Inventory
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

    // Approve Collector Request
    if ($action === 'approve_collector') {
        try {
            $pdo->beginTransaction();
            
            // 1. Approve collector
            $pdo->prepare("UPDATE collector_tasks SET status='accepted' WHERE task_id=:t AND status='pending'")
                ->execute([':t' => $collectorTaskId]);

            // 2. Reject others
            $info = $pdo->prepare("SELECT request_id FROM collector_tasks WHERE task_id=:t");
            $info->execute([':t' => $collectorTaskId]);
            $rid = $info->fetchColumn();

            $pdo->prepare("UPDATE collector_tasks SET status='rejected' WHERE request_id=:r AND task_id <> :t AND status='pending'")
                ->execute([':r' => $rid, ':t' => $collectorTaskId]);

            $pdo->commit();

            // Notify Collector
            $colId = $pdo->prepare("SELECT collector_id FROM collector_tasks WHERE task_id=:t");
            $colId->execute([':t' => $collectorTaskId]);
            $collectorUserId = $colId->fetchColumn();
            
            notify($pdo, (int)$collectorUserId, '✅ Task Approved', 'The NGO approved you to deliver the food.', 'collector', $rid);
            set_flash('success', 'Collector approved!');

        } catch (Exception $ex) { 
            $pdo->rollBack(); 
            set_flash('error', $ex->getMessage()); 
        }
        redirect(BASE_URL . 'ngo/requests.php');
    }
}

// Fetch all requests
$stmt = $pdo->prepare("
    SELECT fr.*, d.food_name, d.unit, u.name AS recipient_name
    FROM food_requests fr
    JOIN food_donations d ON d.donation_id = fr.donation_id
    JOIN users u ON u.user_id = fr.recipient_id
    WHERE d.ngo_id = :u AND fr.status IN ('pending', 'accepted', 'completed')
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
                <?php
                // Fetch collector requests for this specific task
                $colReq = $pdo->prepare("
                    SELECT ct.task_id, ct.status AS task_status, u.name AS collector_name 
                    FROM collector_tasks ct 
                    JOIN users u ON u.user_id = ct.collector_id 
                    WHERE ct.request_id = :r AND ct.status IN ('pending','accepted','pickup_started','picked_up','delivering','delivered','completed')
                ");
                $colReq->execute([':r' => $r['request_id']]);
                $collectorRequests = $colReq->fetchAll();
                ?>
                <tr>
                    <td><strong><?= sanitize($r['recipient_name']) ?></strong></td>
                    <td><?= sanitize($r['food_name']) ?></td>
                    <td><?= (float)$r['quantity'] ?> <?= sanitize($r['unit']) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td>
                        <!-- CHAT BUTTON -->
                        <a href="<?= BASE_URL ?>chat.php?donation_id=<?= (int)$r['donation_id'] ?>" class="btn btn-outline btn-sm" style="margin-bottom:4px;">💬 Chat</a>

                        <?php if ($r['status'] === 'pending'): ?>
                            <form method="post" style="display:flex; gap:8px; align-items:center; margin-top:4px;">
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
                            <?php if ($r['delivery_method'] === 'ngo'): ?>
                                <a href="<?= BASE_URL ?>ngo/track-delivery.php?id=<?= (int)$r['request_id'] ?>" class="btn btn-primary btn-sm" style="margin-top:4px;">🚚 Deliver Food</a>
                            <?php else: ?>
                                <?php if (count($collectorRequests) > 0): ?>
                                    <table style="width:100%; font-size:12px; margin:4px 0 0 0;">
                                        <?php foreach ($collectorRequests as $cr): ?>
                                            <tr>
                                                <td style="padding:4px 0;">🚴 <?= sanitize($cr['collector_name']) ?></td>
                                                <td>
                                                    <?php if ($cr['task_status'] === 'pending'): ?>
                                                        <form method="post" style="display:inline;">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="approve_collector">
                                                            <input type="hidden" name="collector_task_id" value="<?= (int)$cr['task_id'] ?>">
                                                            <button class="btn btn-primary btn-sm" style="padding:2px 8px; font-size:11px;">Approve</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <?= status_badge($cr['task_status']) ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:12px; display:block; margin-top:4px;">Waiting for collectors...</span>
                                <?php endif; ?>
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
