<?php
// donor/accept-recipient.php
// PURE ACTION SCRIPT — no HTML output before redirect.
// Donor accepts a recipient's request.
// After acceptance, if delivery_preference includes collector/NGO,
// nearby collectors and NGOs within 15 KM are notified.

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/location-functions.php';
require_once __DIR__ . '/../includes/notification-functions.php';

require_login();

if (current_role() !== 'donor' && current_role() !== 'admin') {
    set_flash('error', 'Access denied.');
    redirect(BASE_URL . 'index.php');
}

$uid = current_user_id();
$rid = int_get('id');

if (!$rid) {
    set_flash('error', 'Invalid request.');
    redirect(BASE_URL . 'donor/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_flash('error', 'Please use the Accept button.');
    redirect(BASE_URL . 'donor/dashboard.php');
}
verify_csrf();

// Load request + donation
$sql = "
    SELECT fr.*, d.donation_id, d.donor_id, d.food_name, d.people_served,
           d.delivery_preference, d.latitude, d.longitude,
           d.status AS donation_status, d.allow_partial_request
    FROM food_requests fr
    JOIN food_donations d ON d.donation_id = fr.donation_id
    WHERE fr.request_id = :r
";
if (current_role() !== 'admin') $sql .= " AND d.donor_id = :u";

$stmt = $pdo->prepare($sql);
$params = [':r' => $rid];
if (current_role() !== 'admin') $params[':u'] = $uid;
$stmt->execute($params);
$r = $stmt->fetch();

if (!$r) {
    set_flash('error', 'Request not found.');
    redirect(BASE_URL . 'donor/dashboard.php');
}

if ($r['status'] !== 'pending') {
    set_flash('info', 'This request is not pending.');
    redirect(BASE_URL . 'donor/donation-details.php?id=' . $r['donation_id']);
}

if (!in_array($r['donation_status'], ['available','requested'], true)) {
    set_flash('error', 'This donation is no longer available.');
    redirect(BASE_URL . 'donor/donation-details.php?id=' . $r['donation_id']);
}

$pdo->beginTransaction();
try {
    // 1. Accept the recipient's request
    $pdo->prepare("UPDATE food_requests SET status='accepted', accepted_at=NOW() WHERE request_id=:r")
        ->execute([':r' => $rid]);

    // 2. Reject all other pending recipient requests (if partial not allowed)
    if (!$r['allow_partial_request']) {
        $pdo->prepare("
            UPDATE food_requests
            SET status='rejected'
            WHERE donation_id = :d
              AND request_id <> :r
              AND status = 'pending'
        ")->execute([':d' => $r['donation_id'], ':r' => $rid]);
    }

    // 3. Update donation status
    $pdo->prepare("UPDATE food_donations SET status='accepted' WHERE donation_id=:d")
        ->execute([':d' => $r['donation_id']]);

    // 4. History
    $pdo->prepare("INSERT INTO donation_history (donation_id, old_status, new_status, changed_by)
                   VALUES (:d, :old, 'accepted', :u)")
        ->execute([':d' => $r['donation_id'], ':old' => $r['donation_status'], ':u' => $uid]);

    $pdo->commit();

    // 5. Notify the recipient
    notify($pdo, $r['recipient_id'], '✅ Request Accepted',
        'Your request for "' . $r['food_name'] . '" has been accepted.',
        'request_accepted', $r['donation_id']);

    // 6. If delivery preference includes collector/NGO, notify them now (within 15 KM)
    $pref = $r['delivery_preference'];
    $notified = 0;
    if (in_array($pref, ['collector','ngo','any'], true)
        && $r['latitude'] !== null && $r['longitude'] !== null) {

        $notified = notify_collectors_and_ngos_after_acceptance(
            $pdo, $r['donation_id'],
            (float)$r['latitude'], (float)$r['longitude'],
            $r['food_name'], (int)$r['people_served']
        );
    }

    notify_admins($pdo, '✅ Recipient Accepted',
        'Donor accepted request #' . $rid . ' for "' . $r['food_name'] . '".',
        'donation', $r['donation_id']);

    $msg = 'Recipient accepted. ';
    if (in_array($pref, ['collector','ngo','any'], true)) {
        $msg .= $notified > 0
            ? 'Notified ' . $notified . ' nearby collectors/NGOs.'
            : 'No collectors or NGOs found within ' . MATCH_RADIUS_KM . ' KM yet.';
    } else {
        $msg .= 'You have chosen to deliver it yourself.';
    }
    set_flash('success', $msg);

} catch (Exception $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    set_flash('error', 'Could not accept the request: ' . $ex->getMessage());
}

redirect(BASE_URL . 'donor/donation-details.php?id=' . $r['donation_id']);
