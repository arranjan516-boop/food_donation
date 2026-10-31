<?php
// collector/task-details.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/location-functions.php';
require_once __DIR__ . '/../includes/notification-functions.php';

require_login();
if (current_role() !== 'collector' && current_role() !== 'admin') {
    set_flash('error', 'Access denied.');
    redirect(BASE_URL . 'index.php');
}

$uid = current_user_id();
$donationId = int_get('id');
$requestId = int_get('request_id');

// Load donation + NGO info + Recipient info
$stmt = $pdo->prepare("
    SELECT d.*, n.name AS ngo_name, n.phone AS ngo_phone, n.address AS ngo_address, n.area AS ngo_area,
           fr.request_id, fr.recipient_id, fr.quantity AS req_qty, r.name AS recipient_name, r.phone AS recipient_phone,
           r.address AS recipient_address, r.area AS recipient_area
    FROM food_donations d
    JOIN users n ON n.user_id = d.ngo_id
    JOIN food_requests fr ON fr.request_id = :rid
    JOIN users r ON r.user_id = fr.recipient_id
    WHERE d.donation_id = :did
");
$stmt->execute([':rid' => $requestId, ':did' => $donationId]);
$d = $stmt->fetch();

if (!$d) { set_flash('error', 'Task not found.'); redirect(BASE_URL . 'collector/available-tasks.php'); }

// Check if I already accepted this
$existing = $pdo->prepare("SELECT ct.* FROM collector_tasks ct WHERE ct.request_id = :rid AND ct.collector_id = :u AND ct.status IN ('accepted','pickup_started','picked_up','delivering','delivered','completed') LIMIT 1");
$existing->execute([':rid' => $requestId, ':u' => $uid]);
$myTask = $existing->fetch();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'accept') {
    verify_csrf();
    try {
        $pdo->beginTransaction();
        
        // 1. Insert the task
        $ins = $pdo->prepare("
            INSERT INTO collector_tasks (request_id, donation_id, collector_id, status)
            VALUES (:r, :d, :c, 'accepted')
        ");
        $ins->execute([':r' => $d['request_id'], ':d' => $d['donation_id'], ':c' => $uid]);
        
        // 2. Get the newly created Task ID
        $newTaskId = (int)$pdo->lastInsertId();
        
        $pdo->commit();
        
        set_flash('success', 'Delivery task accepted! Navigate to the NGO to pick up the food.');
        
        // 3. Redirect using the TASK ID, not the donation ID
        redirect(BASE_URL . 'collector/active-delivery.php?id=' . $newTaskId);
        
    } catch (Exception $ex) {
        $pdo->rollBack();
        $errors[] = $ex->getMessage();
    }
}

$pageTitle = 'Task Details';
require_once __DIR__ . '/../includes/dashboard-header.php';
?>

<?php if ($errors): ?>
    <div class="toast toast-error"><?php foreach ($errors as $e): ?><?= sanitize($e) ?><br><?php endforeach; ?></div>
<?php endif; ?>

<div class="card mb-3">
    <h3 class="card-title">📦 Delivery Task</h3>
    <div class="details-grid">
        <img src="<?= food_photo_url($d['food_photo']) ?>" alt="" style="width:100%;border-radius:12px">
        <div>
            <h4>🏢 Pickup From (NGO)</h4>
            <p><strong><?= sanitize($d['ngo_name']) ?></strong></p>
            <p><?= sanitize($d['ngo_address']) ?>, <?= sanitize($d['ngo_area']) ?></p>
            <p><strong>Phone:</strong> <?= sanitize($d['ngo_phone']) ?></p>
            
            <h4 style="margin-top:16px">🏁 Deliver To (Recipient)</h4>
            <p><strong><?= sanitize($d['recipient_name']) ?></strong></p>
            <p><?= sanitize($d['recipient_address']) ?>, <?= sanitize($d['recipient_area']) ?></p>
            <p><strong>Phone:</strong> <?= sanitize($d['recipient_phone']) ?></p>
            
            <p style="margin-top:16px"><strong>Quantity:</strong> <?= (float)$d['req_qty'] ?> <?= sanitize($d['unit']) ?></p>
        </div>
    </div>
</div>

<?php if ($myTask): ?>
    <div class="card">
        <div class="toast toast-success">✅ You have accepted this delivery.</div>
        <a href="<?= BASE_URL ?>collector/active-delivery.php?id=<?= (int)$myTask['task_id'] ?>" class="btn btn-primary">Continue to Active Delivery</a>
    </div>
<?php else: ?>
    <div class="card">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="accept">
            <button class="btn btn-primary btn-lg" data-confirm="Accept this delivery task?">🚴 Accept Delivery Task</button>
        </form>
    </div>
<?php endif; ?>

<style>
.details-grid { display:grid; grid-template-columns: 260px 1fr; gap:20px; }
.details-grid img { width:100%; border-radius:12px; }
@media (max-width: 700px) { .details-grid { grid-template-columns: 1fr; } }
</style>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
