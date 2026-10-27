<?php
// recipient/request-food.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification-functions.php';

require_login();
$uid = current_user_id();
$donationId = int_get('id');
$errors = [];

// Load donation + NGO inventory details
$stmt = $pdo->prepare("
    SELECT d.*, u.name AS ngo_name,
           (d.collected_quantity - d.distributed_quantity) AS available_qty
    FROM food_donations d
    JOIN users u ON u.user_id = d.ngo_id
    WHERE d.donation_id = :id
");
$stmt->execute([':id' => $donationId]);
$d = $stmt->fetch();

if (!$d) { set_flash('error', 'Donation not found.'); redirect(BASE_URL . 'recipient/available-food.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $qty = (float)post('quantity');
    $contact = post('contact_number');
    $address = post('delivery_address');
    $message = post('message');

    if ($qty <= 0) $errors[] = 'Quantity must be greater than 0.';
    if ($qty > (float)$d['available_qty']) $errors[] = 'Quantity exceeds available NGO inventory.';
    if ($contact === '') $errors[] = 'Contact number is required.';

    $check = $pdo->prepare("SELECT request_id FROM food_requests WHERE donation_id = :d AND recipient_id = :u LIMIT 1");
    $check->execute([':d' => $donationId, ':u' => $uid]);
    if ($check->fetch()) $errors[] = 'You have already requested this donation.';

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // Lock row to check inventory again safely
            $lock = $pdo->prepare("SELECT (collected_quantity - distributed_quantity) AS live_qty FROM food_donations WHERE donation_id = :d FOR UPDATE");
            $lock->execute([':d' => $donationId]);
            $live = $lock->fetch();

            if ($qty > (float)$live['live_qty']) {
                throw new Exception('Quantity exceeds remaining available inventory.');
            }

            // Insert Request
            $ins = $pdo->prepare("
                INSERT INTO food_requests
                  (donation_id, recipient_id, quantity, message, delivery_preference, contact_number, delivery_address, status)
                VALUES
                  (:d, :u, :q, :m, 'ngo', :c, :a, 'pending')
            ");
            $ins->execute([
                ':d' => $donationId, ':u' => $uid, ':q' => $qty,
                ':m' => $message, ':c' => $contact, ':a' => $address,
            ]);
            $requestId = (int)$pdo->lastInsertId();

            $pdo->commit();

            // Notify NGO
            notify($pdo, (int)$d['ngo_id'], '📨 New Food Request',
                current_user()['name'] . ' requested ' . $qty . ' ' . $d['unit'] . ' of "' . $d['food_name'] . '".',
                'new_request', $donationId);

            set_flash('success', 'Request submitted! The NGO will review it shortly.');
            redirect(BASE_URL . 'recipient/my-requests.php');

        } catch (Exception $ex) {
            $pdo->rollBack();
            $errors[] = $ex->getMessage();
        }
    }
}

$pageTitle = 'Request Food';
require_once __DIR__ . '/../includes/dashboard-header.php';
?>

<?php if ($errors): ?>
    <div class="toast toast-error"><?php foreach ($errors as $e): ?><?= sanitize($e) ?><br><?php endforeach; ?></div>
<?php endif; ?>

<div class="card" style="max-width:780px">
    <div class="flex-between mb-2">
        <h2><?= sanitize($d['food_name']) ?></h2>
        <span class="badge badge-blue">🏢 <?= sanitize($d['ngo_name']) ?></span>
    </div>

    <div class="details-grid">
        <img src="<?= food_photo_url($d['food_photo']) ?>" alt="">
        <div>
            <p><strong>Available:</strong> <?= (float)$d['available_qty'] ?> <?= sanitize($d['unit']) ?></p>
            <p><strong>Type:</strong> <?= sanitize($d['food_type']) ?></p>
            <p><strong>Best before:</strong> <?= $d['best_before'] ? date('d M Y, h:i A', strtotime($d['best_before'])) : '—' ?></p>
        </div>
    </div>
</div>

<form method="post" class="card mt-3" style="max-width:780px">
    <?= csrf_field() ?>

    <h3 class="card-title">Request Details</h3>

    <div class="form-group">
        <label class="form-label">Required Quantity (<?= sanitize($d['unit']) ?>) *</label>
        <input type="number" step="any" min="0.1" max="<?= (float)$d['available_qty'] ?>" name="quantity" class="form-control" required value="<?= isset($_POST['quantity']) ? sanitize($_POST['quantity']) : '' ?>">
        <small class="text-muted">Max available: <?= (float)$d['available_qty'] ?> <?= sanitize($d['unit']) ?></small>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Contact Number *</label>
            <input type="text" name="contact_number" class="form-control" required value="<?= isset($_POST['contact_number']) ? sanitize($_POST['contact_number']) : '' ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Delivery Address</label>
            <input type="text" name="delivery_address" class="form-control" value="<?= isset($_POST['delivery_address']) ? sanitize($_POST['delivery_address']) : '' ?>">
        </div>
    </div>

    <div class="form-group">
        <label class="form-label">Additional Message</label>
        <textarea name="message" class="form-control" rows="3"><?= isset($_POST['message']) ? sanitize($_POST['message']) : '' ?></textarea>
    </div>

    <button class="btn btn-primary btn-lg">🍱 Submit Request</button>
    <a href="<?= BASE_URL ?>recipient/food-details.php?id=<?= (int)$d['donation_id'] ?>" class="btn btn-outline">Cancel</a>
</form>

<style>
.details-grid { display:grid; grid-template-columns: 220px 1fr; gap:20px; }
.details-grid img { width:100%; border-radius:12px; }
@media (max-width: 700px) { .details-grid { grid-template-columns: 1fr; } }
</style>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
