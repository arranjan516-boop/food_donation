<?php
// donor/accept-collector.php
// Handles accepting an NGO request for the new workflow.

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification-functions.php';

require_login();
if (current_role() !== 'donor' && current_role() !== 'admin') {
    set_flash('error', 'Access denied.');
    redirect(BASE_URL . 'index.php');
}

$uid   = current_user_id();
$type  = get('type');   // Should be 'ngo'
$reqId = int_get('id');

if ($type !== 'ngo' || !$reqId) {
    set_flash('error', 'Invalid request.');
    redirect(BASE_URL . 'donor/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_flash('error', 'Please use the Accept button.');
    redirect(BASE_URL . 'donor/dashboard.php');
}
verify_csrf();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT nr.*, d.donor_id, d.donation_id, d.food_name, d.status AS donation_status
        FROM ngo_requests nr
        JOIN food_donations d ON d.donation_id = nr.donation_id
        WHERE nr.ngo_request_id = :id
    ");
    $stmt->execute([':id' => $reqId]);
    $req = $stmt->fetch();

    if (!$req) throw new Exception('NGO request not found.');
    if (current_role() !== 'admin' && (int)$req['donor_id'] !== $uid) throw new Exception('Access denied.');
    if ($req['status'] !== 'pending') throw new Exception('This request is no longer pending.');
    if ($req['donation_status'] !== 'available') throw new Exception('This donation is no longer available.');

    // 1. Accept this NGO
    $pdo->prepare("UPDATE ngo_requests SET status='accepted', accepted_at=NOW() WHERE ngo_request_id=:id")
        ->execute([':id' => $reqId]);

    // 2. Reject all other pending NGO requests for this donation
    $pdo->prepare("UPDATE ngo_requests SET status='rejected' WHERE donation_id = :d AND ngo_request_id <> :id AND status='pending'")
        ->execute([':d' => $req['donation_id'], ':id' => $reqId]);

    // 3. Update donation status and assign NGO ID
    $pdo->prepare("UPDATE food_donations SET status='ngo_assigned', ngo_id=:n WHERE donation_id=:d")
        ->execute([':n' => $req['ngo_id'], ':d' => $req['donation_id']]);

    // 4. History
    $pdo->prepare("INSERT INTO donation_history (donation_id, old_status, new_status, changed_by) VALUES (:d, 'available', 'ngo_assigned', :u)")
        ->execute([':d' => $req['donation_id'], ':u' => $uid]);

    $pdo->commit();

    // 5. Notify the NGO
    notify($pdo, (int)$req['ngo_id'], '🏢 Donation Assigned',
        'The donor accepted your request for "' . $req['food_name'] . '". Please arrange collection.',
        'ngo_assigned', $req['donation_id']);

    notify_admins($pdo, '🏢 NGO Assigned',
        'Donor accepted NGO for donation #' . $req['donation_id'], 'ngo', $req['donation_id']);

    set_flash('success', 'NGO accepted. They have been notified to collect the food.');
    redirect(BASE_URL . 'donor/donation-details.php?id=' . $req['donation_id']);

} catch (Exception $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    set_flash('error', $ex->getMessage());
    redirect(BASE_URL . 'donor/dashboard.php');
}
