<?php
// recipient/request-details.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification-functions.php';

require_login();
$uid = current_user_id();
$rid = int_get('id');

// 1. Fetch Request
$stmt = $pdo->prepare("
    SELECT fr.*, d.donation_id, d.food_name, d.food_photo, d.unit, d.donor_id, d.status AS donation_status,
           u.name AS donor_name, u.phone AS donor_phone, u.city AS donor_city, u.area AS donor_area
    FROM food_requests fr
    JOIN food_donations d ON d.donation_id = fr.donation_id
    JOIN users u ON u.user_id = d.donor_id
    WHERE fr.request_id = :r AND fr.recipient_id = :u
");
$stmt->execute([':r' => $rid, ':u' => $uid]);
$r = $stmt->fetch();

if (!$r) { 
    set_flash('error', 'Request not found.'); 
    redirect(BASE_URL . 'recipient/my-requests.php'); 
}

// 2. Handle POST (Confirm or Report)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');

    if ($action === 'confirm_receipt') {
        $pdo->prepare("UPDATE food_requests SET status='completed' WHERE request_id=:r")->execute([':r' => $rid]);
        $pdo->prepare("UPDATE food_donations SET status='completed' WHERE donation_id=:d")->execute([':d' => $r['donation_id']]);
        $pdo->prepare("INSERT INTO donation_history (donation_id, old_status, new_status, changed_by) VALUES (:d, :old, 'completed', :u)")
            ->execute([':d' => $r['donation_id'], ':old' => $r['donation_status'], ':u' => $uid]);

        notify($pdo, $r['donor_id'], '✅ Recipient Confirmed', 'Recipient confirmed food receipt for "' . $r['food_name'] . '".', 'completed', $r['donation_id']);
        notify_admins($pdo, '✅ Donation Completed', 'Request #' . $rid . ' completed.', 'completed', $r['donation_id']);

        set_flash('success', 'Thank you for confirming!');
        redirect(BASE_URL . 'recipient/request-details.php?id=' . $rid);
    }

    if ($action === 'report_problem') {
        $desc = post('description');
        if ($desc === '') {
            set_flash('error', 'Please describe the problem.');
        } else {
            $evidence = null;
            if (!empty($_FILES['evidence_image']['name'])) {
                $evidence = upload_image($_FILES['evidence_image'], PROOF_UPLOAD, PROOF_UPLOAD_URL);
            }
            $pdo->prepare("INSERT INTO problem_reports (donation_id, request_id, reported_by, description, evidence_image) VALUES (:d, :r, :u, :desc, :img)")
                ->execute([':d' => $r['donation_id'], ':r' => $rid, ':u' => $uid, ':desc' => $desc, ':img' => $evidence]);

            notify($pdo, $r['donor_id'], '⚠️ Problem Reported', 'A problem was reported for "' . $r['food_name'] . '".', 'problem', $r['donation_id']);
            notify_admins($pdo, '⚠️ Problem Reported', 'Recipient reported a problem on request #' . $rid, 'problem', $r['donation_id']);

            set_flash('info', 'Problem reported. The donor and admin have been notified.');
            redirect(BASE_URL . 'recipient/request-details.php?id=' . $rid);
        }
    }
}

// 3. Fetch Proof
$pf = $pdo->prepare("SELECT dp.*, u.name AS uploader_name FROM delivery_proofs dp JOIN users u ON u.user_id = dp.uploaded_by WHERE dp.request_id = :r ORDER BY uploaded_at DESC LIMIT 1");
$pf->execute([':r' => $rid]);
$proof = $pf->fetch();

// 4. Fetch existing problem report (if any)
$pr = $pdo->prepare("SELECT * FROM problem_reports WHERE request_id = :r ORDER BY created_at DESC LIMIT 1");
$pr->execute([':r' => $rid]);
$myProblem = $pr->fetch();

$pageTitle = 'Request Details';
require_once __DIR__ . '/../includes/dashboard-header.php';
?>

<div class="card mb-3">
    <div class="flex-between mb-2">
        <h2><?= sanitize($r['food_name']) ?></h2>
        <div class="flex gap-2" style="align-items:center;">
            <?= status_badge($r['status']) ?>
            <?php if (in_array($r['status'], ['accepted', 'completed'], true)): ?>
                <a href="<?= BASE_URL ?>chat.php?donation_id=<?= (int)$r['donation_id'] ?>" class="btn btn-primary btn-sm">💬 Chat with Donor</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="details-grid">
        <img src="<?= food_photo_url($r['food_photo']) ?>" alt="">
        <div>
            <p><strong>Donor:</strong> <?= sanitize($r['donor_name']) ?></p>
            <p><strong>Pickup:</strong> <?= sanitize($r['donor_area'] ?: $r['donor_city']) ?></p>
            <p><strong>Requested qty:</strong> <?= (float)$r['quantity'] ?> <?= sanitize($r['unit']) ?></p>
            <p><strong>Delivery preference:</strong> <?= ucwords(str_replace('_',' ',$r['delivery_preference'])) ?></p>
            <p><strong>Your contact:</strong> <?= sanitize($r['contact_number'] ?: '—') ?></p>
            <?php if ($r['delivery_address']): ?>
                <p><strong>Delivery address:</strong> <?= sanitize($r['delivery_address']) ?></p>
            <?php endif; ?>
            <p class="text-muted">Requested <?= time_ago($r['requested_at']) ?></p>
        </div>
    </div>
</div>

<?php if ($proof): ?>
    <div class="card mb-3">
        <h3 class="card-title">🍱 Food Delivered</h3>
        <p>A delivery proof photo is available. Please review and confirm receipt.</p>
        <img src="<?= PROOF_UPLOAD_URL . rawurlencode($proof['proof_image']) ?>" style="max-width:280px;border-radius:12px;margin:10px 0" alt="">
        <p class="text-muted">Uploaded by <?= sanitize($proof['uploader_name']) ?> (<?= sanitize($proof['uploaded_by_role']) ?>) on <?= date('d M Y, h:i A', strtotime($proof['uploaded_at'])) ?></p>
        <?php if ($proof['delivery_note']): ?><p><strong>Note:</strong> <?= sanitize($proof['delivery_note']) ?></p><?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($myProblem): ?>
    <div class="card mb-3" style="border-left: 4px solid var(--red); background: #FFEBEE;">
        <h3 class="card-title" style="color: var(--red);">⚠️ Problem Reported</h3>
        <p><strong>Description:</strong> <?= nl2br(sanitize($myProblem['description'])) ?></p>
        <p><strong>Status:</strong> <?= ucfirst(sanitize($myProblem['status'])) ?></p>
        <?php if ($myProblem['admin_note']): ?>
            <p><strong>Admin Note:</strong> <?= nl2br(sanitize($myProblem['admin_note'])) ?></p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($r['status'] === 'accepted' || $r['status'] === 'completed'): ?>
    <div class="card">
        <h3 class="card-title">Actions</h3>

        <?php if ($r['status'] === 'accepted' && $proof && !$myProblem): ?>
            <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="confirm_receipt">
                <button class="btn btn-primary btn-lg">✅ Confirm Food Received</button>
            </form>

            <button class="btn btn-danger btn-lg" style="margin-left:8px" onclick="document.getElementById('problem-form').style.display='block'">
                ⚠️ Report a Problem
            </button>

            <div id="problem-form" style="display:none;margin-top:20px;padding-top:20px;border-top:1px solid var(--gray-100)">
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="report_problem">
                    <div class="form-group">
                        <label class="form-label">Problem description *</label>
                        <textarea name="description" class="form-control" rows="3" required></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Evidence photo (optional)</label>
                        <input type="file" name="evidence_image" class="form-control" accept="image/jpeg,image/png,image/webp">
                    </div>
                    <button class="btn btn-danger">Submit Report</button>
                </form>
            </div>

        <?php elseif ($r['status'] === 'accepted' && !$proof): ?>
            <p class="text-muted">Waiting for the delivery proof to be uploaded. You'll be able to confirm once it arrives.</p>
        <?php elseif ($r['status'] === 'completed'): ?>
            <p>✅ This request has been completed. Thank you!</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<style>
.details-grid { display:grid; grid-template-columns: 260px 1fr; gap:20px; }
.details-grid img { width:100%; border-radius:12px; }
@media (max-width: 700px) { .details-grid { grid-template-columns: 1fr; } }
</style>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
