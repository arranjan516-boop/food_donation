<?php
// recipient/request-details.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification-functions.php';

require_login();
$uid = current_user_id();
$rid = int_get('id');

$stmt = $pdo->prepare("
    SELECT fr.*, d.donation_id, d.food_name, d.food_photo, d.unit, d.donor_id, d.ngo_id, d.status AS donation_status,
           u.name AS donor_name, n.name AS ngo_name
    FROM food_requests fr
    JOIN food_donations d ON d.donation_id = fr.donation_id
    JOIN users u ON u.user_id = d.donor_id
    LEFT JOIN users n ON n.user_id = d.ngo_id
    WHERE fr.request_id = :r AND fr.recipient_id = :u
");
$stmt->execute([':r' => $rid, ':u' => $uid]);
$r = $stmt->fetch();

if (!$r) { set_flash('error', 'Request not found.'); redirect(BASE_URL . 'recipient/my-requests.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');

    // 1. Handle Rating Submission
    if ($action === 'submit_rating') {
        $rating = (int)post('rating');
        $review = post('review');

        if ($rating >= 1 && $rating <= 5) {
            $pdo->prepare("INSERT INTO ngo_ratings (ngo_id, recipient_id, request_id, rating, review) 
                           VALUES (:n, :r, :req, :stars, :review)")
                ->execute([':n' => $r['ngo_id'], ':r' => $uid, ':req' => $rid, ':stars' => $rating, ':review' => $review]);
            
            notify($pdo, (int)$r['ngo_id'], '⭐ New Review', 'A recipient left you a ' . $rating . '-star rating.', 'rating', $rid);
            set_flash('success', 'Thank you for rating!');
            redirect(BASE_URL . 'recipient/request-details.php?id=' . $rid);
        }
    }

    // 2. Handle Confirm Receipt
    if ($action === 'confirm_receipt') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE food_requests SET status='completed' WHERE request_id=:r")->execute([':r' => $rid]);
            $pdo->prepare("UPDATE food_donations SET status='completed' WHERE donation_id=:d")->execute([':d' => $r['donation_id']]);
            $pdo->commit();
            set_flash('success', 'Thank you for confirming!');
            redirect(BASE_URL . 'recipient/request-details.php?id=' . $rid);
        } catch (Exception $ex) { $pdo->rollBack(); set_flash('error', $ex->getMessage()); }
    }
}

// 3. Check if I already rated this NGO
$myRating = $pdo->prepare("SELECT * FROM ngo_ratings WHERE request_id = :r AND recipient_id = :u LIMIT 1");
$myRating->execute([':r' => $rid, ':u' => $uid]);
$myRating = $myRating->fetch();

// 4. Fetch Delivery Proof
$pf = $pdo->prepare("SELECT dp.*, u.name AS uploader_name FROM delivery_proofs dp JOIN users u ON u.user_id = dp.uploaded_by WHERE dp.request_id = :r ORDER BY uploaded_at DESC LIMIT 1");
$pf->execute([':r' => $rid]);
$proof = $pf->fetch();

$pageTitle = 'Request Details';
require_once __DIR__ . '/../includes/dashboard-header.php';
?>

<div class="card mb-3">
    <div class="flex-between mb-2">
        <h2><?= sanitize($r['food_name']) ?></h2>
        <?= status_badge($r['status']) ?>
    </div>
    <div class="details-grid">
        <img src="<?= food_photo_url($r['food_photo']) ?>" alt="">
        <div>
            <p><strong>NGO:</strong> <?= sanitize($r['ngo_name'] ?: '—') ?></p>
            <p><strong>Requested qty:</strong> <?= (float)$r['quantity'] ?> <?= sanitize($r['unit']) ?></p>
            <p><strong>Delivery Method:</strong> <?= ucfirst($r['delivery_method']) ?></p>
        </div>
    </div>
</div>

<div class="card mb-3" style="padding: 20px;">
    <?php render_timeline($r['donation_status']); ?>
</div>

<?php if ($proof): ?>
    <div class="card mb-3">
        <h3 class="card-title">🍱 Delivery Proof</h3>
        <img src="<?= PROOF_UPLOAD_URL . rawurlencode($proof['proof_image']) ?>" style="max-width:280px;border-radius:12px;margin:10px 0" alt="">
    </div>
<?php endif; ?>

<?php if ($r['status'] === 'completed'): ?>
    <!-- NGO RATING SECTION -->
    <div class="card mb-3">
        <h3 class="card-title">Rate the NGO</h3>
        
        <?php if ($myRating): ?>
            <div class="toast toast-success">
                ⭐ You rated this NGO 
                <strong><?= str_repeat('★', $myRating['rating']) . str_repeat('☆', 5 - $myRating['rating']) ?></strong>
            </div>
            <?php if ($myRating['review']): ?>
                <p style="margin-top:10px; font-style:italic;">"<?= sanitize($myRating['review']) ?>"</p>
            <?php endif; ?>
        <?php else: ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="submit_rating">
                
                <div class="form-group">
                    <label class="form-label">How was your experience with <?= sanitize($r['ngo_name']) ?>?</label>
                    <div style="display:flex; gap:8px; font-size:28px; cursor:pointer;" id="star-rating">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <label style="cursor:pointer;">
                                <input type="radio" name="rating" value="<?= $i ?>" <?= $i === 5 ? 'checked' : '' ?> required style="display:none;">
                                <span class="star" data-value="<?= $i ?>" style="color:#ccc;">★</span>
                            </label>
                        <?php endfor; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Review (Optional)</label>
                    <textarea name="review" class="form-control" rows="2" placeholder="e.g. Great food and very polite volunteers!"></textarea>
                </div>

                <button class="btn btn-primary">Submit Rating</button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($r['status'] === 'accepted'): ?>
    <div class="card">
        <h3 class="card-title">Actions</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm_receipt">
            <button class="btn btn-primary btn-lg" data-confirm="Confirm you have received the food?">✅ Confirm Food Received</button>
        </form>
    </div>
<?php endif; ?>

<!-- Star Hover/Click Script -->
<script>
document.querySelectorAll('.star').forEach(star => {
    star.addEventListener('mouseover', function() {
        const val = parseInt(this.dataset.value);
        updateStars(val);
    });
    star.addEventListener('mouseout', function() {
        const checked = document.querySelector('input[name="rating"]:checked');
        updateStars(checked ? parseInt(checked.value) : 0);
    });
    star.addEventListener('click', function() {
        const val = parseInt(this.dataset.value);
        document.querySelector(`input[name="rating"][value="${val}"]`).checked = true;
        updateStars(val);
    });
});

function updateStars(value) {
    document.querySelectorAll('.star').forEach(s => {
        s.style.color = parseInt(s.dataset.value) <= value ? '#FFD700' : '#ccc';
    });
}
// Initialize on page load
const initial = document.querySelector('input[name="rating"]:checked');
if (initial) updateStars(parseInt(initial.value));
</script>

<style>
.details-grid { display:grid; grid-template-columns: 260px 1fr; gap:20px; }
.details-grid img { width:100%; border-radius:12px; }
@media (max-width: 700px) { .details-grid { grid-template-columns: 1fr; } }
</style>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
