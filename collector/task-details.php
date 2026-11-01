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

// Check if I already requested
$existing = $pdo->prepare("SELECT ct.* FROM collector_tasks ct WHERE ct.request_id = :rid AND ct.collector_id = :u ORDER BY task_id DESC LIMIT 1");
$existing->execute([':rid' => $requestId, ':u' => $uid]);
$myTask = $existing->fetch();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'request_task') {
    verify_csrf();
    
    if ($myTask && $myTask['status'] === 'pending') {
        $errors[] = 'You already have a pending request for this task.';
    } elseif ($myTask && in_array($myTask['status'], ['accepted','pickup_started','picked_up','delivering','delivered','completed'])) {
        $errors[] = 'You have already accepted this task.';
    } else {
        try {
            // Insert as PENDING (waiting for NGO approval)
            $ins = $pdo->prepare("
                INSERT INTO collector_tasks (request_id, donation_id, collector_id, status)
                VALUES (:r, :d, :c, 'pending')
            ");
            $ins->execute([':r' => $d['request_id'], ':d' => $d['donation_id'], ':c' => $uid]);
            
            notify($pdo, (int)$d['ngo_id'], '🚴 New Collector Request', 
                current_user()['name'] . ' wants to deliver the food to ' . $d['recipient_name'] . '.',
                'collector_request', $d['donation_id']);

            set_flash('success', 'Request sent to the NGO. You will be notified when they approve.');
            redirect(BASE_URL . 'collector/my-tasks.php');
        } catch (Exception $ex) {
            $errors[] = $ex->getMessage();
        }
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
        <?php if ($myTask['status'] === 'pending'): ?>
            <div class="toast toast-info">⏳ Your request is pending. Waiting for NGO approval.</div>
            <a href="<?= BASE_URL ?>collector/my-tasks.php" class="btn btn-outline">← My Tasks</a>
        <?php elseif ($myTask['status'] === 'rejected'): ?>
            <div class="toast toast-error">❌ Your request was rejected by the NGO.</div>
            <a href="<?= BASE_URL ?>collector/available-tasks.php" class="btn btn-outline">← Back</a>
        <?php else: ?>
            <div class="toast toast-success">✅ You have been assigned this task by the NGO.</div>
            <a href="<?= BASE_URL ?>collector/active-delivery.php?id=<?= (int)$myTask['task_id'] ?>" class="btn btn-primary">Continue to Active Delivery</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card">
        <h3 class="card-title">Send Request to NGO</h3>
        <p class="text-muted mb-2">Click below to request this task. The NGO will review your request and assign the delivery to you.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="request_task">
            <button class="btn btn-primary btn-lg" data-confirm="Send request to NGO?">🚴 Request to Deliver</button>
        </form>
    </div>
<?php endif; ?>

<style>
.details-grid { display:grid; grid-template-columns: 260px 1fr; gap:20px; }
.details-grid img { width:100%; border-radius:12px; }
@media (max-width: 700px) { .details-grid { grid-template-columns: 1fr; } }
</style>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
