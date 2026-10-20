<?php
// collector/active-delivery.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification-functions.php';

require_login();
if (current_role() !== 'collector' && current_role() !== 'admin') {
    set_flash('error', 'Access denied.');
    redirect(BASE_URL . 'index.php');
}

$uid    = current_user_id();
$taskId = int_get('id');

$stmt = $pdo->prepare("
    SELECT ct.*, d.donation_id, d.donor_id, d.food_name, d.food_photo, d.unit, d.quantity, d.people_served,
           d.address AS pickup_address, d.area AS pickup_area, d.city AS pickup_city,
           d.latitude AS pickup_lat, d.longitude AS pickup_lng,
           u.name AS donor_name, u.phone AS donor_phone,
           fr.request_id, fr.recipient_id, fr.contact_number AS recipient_contact,
           fr.delivery_address AS recipient_delivery_address,
           ru.name AS recipient_name, ru.phone AS recipient_phone,
           ru.city AS recipient_city, ru.area AS recipient_area, ru.address AS recipient_address,
           ru.latitude AS recipient_lat, ru.longitude AS recipient_lng
    FROM collector_tasks ct
    JOIN food_donations d ON d.donation_id = ct.donation_id
    JOIN users u ON u.user_id = d.donor_id
    LEFT JOIN food_requests fr ON fr.request_id = ct.request_id
    LEFT JOIN users ru ON ru.user_id = fr.recipient_id
    WHERE ct.task_id = :t AND ct.collector_id = :u
");
$stmt->execute([':t' => $taskId, ':u' => $uid]);
$t = $stmt->fetch();

if (!$t) { set_flash('error', 'Task not found.'); redirect(BASE_URL . 'collector/my-tasks.php'); }

$errors = [];
$statusFlow = [
    'accepted'       => ['pickup_started', '🚗 Start Pickup'],
    'pickup_started' => ['picked_up',      '🍱 Food Collected'],
    'picked_up'      => ['delivering',     '🚚 Start Delivery'],
    'delivering'     => ['awaiting_proof', '📍 Reached Recipient'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');

    if ($action === 'advance') {
        $cur = $t['status'];
        if (isset($statusFlow[$cur])) {
            $next = $statusFlow[$cur][0];
            $pdo->prepare("UPDATE collector_tasks SET status=:s WHERE task_id=:t")->execute([':s' => $next, ':t' => $taskId]);
            $pdo->prepare("UPDATE food_donations SET status=:s WHERE donation_id=:d")->execute([':s' => $next === 'pickup_started' ? 'pickup_scheduled' : $next, ':d' => $t['donation_id']]);
            set_flash('success', 'Status updated.');
            redirect(BASE_URL . 'collector/active-delivery.php?id=' . $taskId);
        }
    }
}

$pageTitle = 'Active Delivery';
require_once __DIR__ . '/../includes/dashboard-header.php';
?>

<div class="card mb-3">
    <div class="flex-between mb-2">
        <h2><?= sanitize($t['food_name']) ?></h2>
        <?= status_badge($t['status']) ?>
    </div>
    <div class="details-grid">
        <img src="<?= food_photo_url($t['food_photo']) ?>" alt="">
        <div>
            <h4>📍 Pickup From</h4>
            <p><strong><?= sanitize($t['donor_name']) ?></strong> (<?= sanitize($t['donor_phone']) ?>)</p>
            <p><?= sanitize($t['pickup_address']) ?>, <?= sanitize($t['pickup_area']) ?></p>
        </div>
    </div>
</div>

<!-- Visual Timeline -->
<div class="card mb-3" style="padding: 20px;">
    <?php render_timeline($t['status']); ?>
</div>

<?php if (isset($statusFlow[$t['status']])): ?>
    <div class="card mb-3">
        <h3 class="card-title">Next Step</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="advance">
            <button class="btn btn-primary btn-lg"><?= $statusFlow[$t['status']][1] ?></button>
        </form>
    </div>
<?php endif; ?>

<style>
.details-grid { display:grid; grid-template-columns: 260px 1fr; gap:20px; }
@media (max-width: 700px) { .details-grid { grid-template-columns: 1fr; } }
</style>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
