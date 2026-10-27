<?php
// ngo/donation-details.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/location-functions.php';
require_once __DIR__ . '/../includes/notification-functions.php';

require_login();
if (current_role() !== 'ngo' && current_role() !== 'admin') {
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
if (!$d) { set_flash('error', 'Donation not found.'); redirect(BASE_URL . 'ngo/available-donations.php'); }

// Is an NGO already assigned?
$existing = $pdo->prepare("
    SELECT nr.*, u.name AS ngo_name
    FROM ngo_requests nr
    JOIN users u ON u.user_id = nr.ngo_id
    WHERE nr.donation_id = :d AND nr.status IN ('accepted','completed')
    LIMIT 1
");
$existing->execute([':d' => $donationId]);
$existingNgo = $existing->fetch();

// My own request?
$mine = $pdo->prepare("SELECT * FROM ngo_requests WHERE donation_id=:d AND ngo_id=:u ORDER BY ngo_request_id DESC LIMIT 1");
$mine->execute([':d' => $donationId, ':u' => $uid]);
$myRequest = $mine->fetch();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');

    if ($action === 'request_handle') {
        if ($existingNgo) {
            $errors[] = 'This donation was already assigned to ' . $existingNgo['ngo_name'] . '.';
        } elseif ($myRequest && $myRequest['status'] === 'pending') {
            $errors[] = 'You already have a pending request.';
        } else {
            try {
                $pdo->beginTransaction();

                $lock = $pdo->prepare("SELECT status FROM food_donations WHERE donation_id = :d FOR UPDATE");
                $lock->execute([':d' => $donationId]);
                $live = $lock->fetch();
                
                // THE FIX: Allow requesting if the status is 'available' or 'ngo_assigned'
                if (!$live || !in_array($live['status'], ['available', 'ngo_assigned'])) {
                    throw new Exception('This donation is not available for NGO handling.');
                }

                $pdo->prepare("INSERT INTO ngo_requests (donation_id, ngo_id, status) VALUES (:d, :n, 'pending')")
                    ->execute([':d' => $donationId, ':n' => $uid]);

                notify($pdo, (int)$d['donor_id'], '🏢 New NGO Request',
                    current_user()['name'] . ' wants to handle "' . $d['food_name'] . '".',
                    'ngo_request', $donationId);

                $pdo->commit();

                set_flash('success', 'Request sent to the donor. You will be notified when they accept.');
                redirect(BASE_URL . 'ngo/my-donations.php');

            } catch (Exception $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = $ex->getMessage();
            }
        }
    }
}

$pageTitle = 'Donation Details';
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
            <p><strong>Pickup:</strong> <?= sanitize($d['address']) ?>, <?= sanitize($d['area']) ?>, <?= sanitize($d['city']) ?></p>
            <p><strong>Category / Type:</strong> <?= sanitize($d['food_category']) ?> · <?= sanitize($d['food_type']) ?></p>
            <p><strong>Quantity:</strong> <?= (float)$d['quantity'] ?> <?= sanitize($d['unit']) ?></p>
            <p><strong>People served:</strong> <?= (int)$d['people_served'] ?></p>
            <p><strong>Best before:</strong> <?= $d['best_before'] ? date('d M Y, h:i A', strtotime($d['best_before'])) : '—' ?></p>
        </div>
    </div>
</div>

<?php if ($existingNgo && $existingNgo['ngo_id'] != $uid): ?>
    <div class="card">
        <div class="toast toast-info">🔒 This donation was assigned to <?= sanitize($existingNgo['ngo_name']) ?>.</div>
        <a href="<?= BASE_URL ?>ngo/available-donations.php" class="btn btn-outline">← Back</a>
    </div>
<?php elseif ($existingNgo && $existingNgo['ngo_id'] == $uid): ?>
    <div class="card">
        <div class="toast toast-success">✅ Your NGO has been assigned this donation.</div>
        <a href="<?= BASE_URL ?>ngo/collection-status.php?id=<?= (int)$existingNgo['ngo_request_id'] ?>" class="btn btn-primary">Continue to Collection</a>
    </div>
<?php elseif ($myRequest && $myRequest['status'] === 'pending'): ?>
    <div class="card">
        <div class="toast toast-info">⏳ Your request is pending. Waiting for the donor to accept.</div>
        <a href="<?= BASE_URL ?>ngo/my-donations.php" class="btn btn-outline">← My Donations</a>
    </div>
<?php else: ?>
    <div class="card">
        <h3 class="card-title">Request to Handle</h3>
        <p class="text-muted">Send a request to the donor. They will choose one collector or NGO.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="request_handle">
            <button class="btn btn-primary btn-lg" data-confirm="Send a request to handle this donation?">🏢 Request to Handle</button>
            <a href="<?= BASE_URL ?>ngo/available-donations.php" class="btn btn-outline">Cancel</a>
        </form>
    </div>
<?php endif; ?>

<style>
.details-grid { display:grid; grid-template-columns: 260px 1fr; gap:20px; }
.details-grid img { width:100%; border-radius:12px; }
@media (max-width: 700px) { .details-grid { grid-template-columns: 1fr; } }
</style>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
