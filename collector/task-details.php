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

$stmt = $pdo->prepare("
    SELECT d.*, u.name AS donor_name, u.phone AS donor_phone
    FROM food_donations d
    JOIN users u ON u.user_id = d.donor_id
    WHERE d.donation_id = :id
");
$stmt->execute([':id' => $donationId]);
$d = $stmt->fetch();
if (!$d) { set_flash('error', 'Donation not found.'); redirect(BASE_URL . 'collector/available-tasks.php'); }

// Already a collector assigned?
$existing = $pdo->prepare("
    SELECT ct.*, u.name AS collector_name
    FROM collector_tasks ct
    JOIN users u ON u.user_id = ct.collector_id
    WHERE ct.donation_id = :d AND ct.status IN ('accepted','pickup_started','picked_up','delivering','completed')
    LIMIT 1
");
$existing->execute([':d' => $donationId]);
$existingTask = $existing->fetch();

// My own request?
$mine = $pdo->prepare("SELECT * FROM collector_tasks WHERE donation_id=:d AND collector_id=:u ORDER BY task_id DESC LIMIT 1");
$mine->execute([':d' => $donationId, ':u' => $uid]);
$myTask = $mine->fetch();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');

    if ($action === 'request_collect') {
        if ($existingTask) {
            $errors[] = 'This pickup has already been accepted by ' . $existingTask['collector_name'] . '.';
        } elseif ($myTask && $myTask['status'] === 'pending') {
            $errors[] = 'You already have a pending request for this donation.';
        } elseif ($myTask && in_array($myTask['status'], ['rejected','cancelled'], true)) {
            $errors[] = 'Your earlier request was not accepted.';
        } else {
            try {
                $pdo->beginTransaction();

                $lock = $pdo->prepare("SELECT status FROM food_donations WHERE donation_id = :d FOR UPDATE");
                $lock->execute([':d' => $donationId]);
                $live = $lock->fetch();
                if (!$live || $live['status'] !== 'accepted') {
                    throw new Exception('This donation is not ready for collectors yet.');
                }

                $reqStmt = $pdo->prepare("SELECT request_id FROM food_requests WHERE donation_id = :d AND status='accepted' ORDER BY accepted_at DESC LIMIT 1");
                $reqStmt->execute([':d' => $donationId]);
                $req = $reqStmt->fetch();
                $requestId = $req ? (int)$req['request_id'] : null;

                $pdo->prepare("
                    INSERT INTO collector_tasks (request_id, donation_id, collector_id, status)
                    VALUES (:r, :d, :c, 'pending')
                ")->execute([':r' => $requestId, ':d' => $donationId, ':c' => $uid]);

                notify($pdo, (int)$d['donor_id'], '🚴 New Collector Request',
                    current_user()['name'] . ' wants to collect "' . $d['food_name'] . '".',
                    'collector_request', $donationId);

                $pdo->commit();

                set_flash('success', 'Request sent to the donor. You will be notified when they accept.');
                redirect(BASE_URL . 'collector/my-tasks.php');

            } catch (Exception $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = $ex->getMessage();
            }
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
    <div class="flex-between mb-2">
        <h2><?= sanitize($d['food_name']) ?></h2>
        <?= status_badge($d['status']) ?>
    </div>

    <div class="details-grid">
        <img src="<?= food_photo_url($d['food_photo']) ?>" alt="">
        <div>
            <p><strong>Donor:</strong> <?= sanitize($d['donor_name']) ?></p>
            <p><strong>Contact:</strong> <?= sanitize($d['donor_phone'] ?: '—') ?></p>
            <p><strong>Pickup:</strong> <?= sanitize($d['address']) ?>, <?= sanitize($d['area']) ?>, <?= sanitize($d['city']) ?> - <?= sanitize($d['pincode']) ?></p>
            <p><strong>Quantity:</strong> <?= (float)$d['quantity'] ?> <?= sanitize($d['unit']) ?></p>
            <p><strong>People served:</strong> <?= (int)$d['people_served'] ?></p>
            <p><strong>Urgency:</strong> <?= ucfirst(str_replace('_',' ',$d['urgency'])) ?></p>
            <p><strong>Best before:</strong> <?= $d['best_before'] ? date('d M Y, h:i A', strtotime($d['best_before'])) : '—' ?></p>
        </div>
    </div>
</div>

<?php if ($existingTask && $existingTask['collector_id'] != $uid): ?>
    <div class="card">
        <div class="toast toast-info">🔒 This pickup was assigned to <?= sanitize($existingTask['collector_name']) ?>.</div>
        <a href="<?= BASE_URL ?>collector/available-tasks.php" class="btn btn-outline">← Back</a>
    </div>
<?php elseif ($existingTask && $existingTask['collector_id'] == $uid): ?>
    <div class="card">
        <div class="toast toast-success">✅ You have been assigned this pickup.</div>
        <a href="<?= BASE_URL ?>collector/active-delivery.php?id=<?= (int)$existingTask['task_id'] ?>" class="btn btn-primary">Continue to Active Delivery</a>
    </div>
<?php elseif ($myTask && $myTask['status'] === 'pending'): ?>
    <div class="card">
        <div class="toast toast-info">⏳ Your request is pending. Waiting for the donor to accept.</div>
        <a href="<?= BASE_URL ?>collector/my-tasks.php" class="btn btn-outline">← My Tasks</a>
    </div>
<?php else: ?>
    <div class="card">
        <h3 class="card-title">Request to Collect</h3>
        <p class="text-muted">Send a request to the donor. They will choose one collector or NGO.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="request_collect">
            <button class="btn btn-primary btn-lg" data-confirm="Send a request to collect this donation?">🚴 Request to Collect</button>
            <a href="<?= BASE_URL ?>collector/available-tasks.php" class="btn btn-outline">Cancel</a>
        </form>
    </div>
<?php endif; ?>

<style>
.details-grid { display:grid; grid-template-columns: 260px 1fr; gap:20px; }
.details-grid img { width:100%; border-radius:12px; }
@media (max-width: 700px) { .details-grid { grid-template-columns: 1fr; } }
</style>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
