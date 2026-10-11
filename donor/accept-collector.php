<?php
// donor/accept-collector.php
// PURE ACTION SCRIPT — no HTML.
// Donor accepts ONE collector OR NGO request.
// All other pending collector/NGO requests on the same donation are auto-rejected.

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification-functions.php';

require_login();
if (current_role() !== 'donor' && current_role() !== 'admin') {
    set_flash('error', 'Access denied.');
    redirect(BASE_URL . 'index.php');
}

$uid   = current_user_id();
$type  = get('type');   // 'collector' or 'ngo'
$reqId = int_get('id');

if (!in_array($type, ['collector','ngo'], true) || !$reqId) {
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

    if ($type === 'collector') {
        $stmt = $pdo->prepare("
            SELECT ct.*, d.donor_id, d.donation_id, d.food_name, d.status AS donation_status
            FROM collector_tasks ct
            JOIN food_donations d ON d.donation_id = ct.donation_id
            WHERE ct.task_id = :id
        ");
        $stmt->execute([':id' => $reqId]);
        $task = $stmt->fetch();

        if (!$task) throw new Exception('Collector request not found.');
        if (current_role() !== 'admin' && (int)$task['donor_id'] !== $uid) throw new Exception('Access denied.');
        if ($task['status'] !== 'pending') throw new Exception('This request is no longer pending.');
        if ($task['donation_status'] !== 'accepted') {
            throw new Exception('You must first accept a recipient request.');
        }

        $pdo->prepare("UPDATE collector_tasks SET status='accepted', assigned_at=NOW() WHERE task_id=:id")
            ->execute([':id' => $reqId]);

        $pdo->prepare("
            UPDATE collector_tasks SET status='rejected'
            WHERE donation_id = :d AND task_id <> :id AND status='pending'
        ")->execute([':d' => $task['donation_id'], ':id' => $reqId]);

        $pdo->prepare("
            UPDATE ngo_requests SET status='rejected'
            WHERE donation_id = :d AND status='pending'
        ")->execute([':d' => $task['donation_id']]);

        $pdo->prepare("UPDATE food_donations SET status='collector_assigned' WHERE donation_id=:d")
            ->execute([':d' => $task['donation_id']]);

        $pdo->prepare("INSERT INTO donation_history (donation_id, old_status, new_status, changed_by)
                       VALUES (:d, 'accepted', 'collector_assigned', :u)")
            ->execute([':d' => $task['donation_id'], ':u' => $uid]);

        $pdo->commit();

        notify($pdo, $task['collector_id'], '🚴 You have been assigned',
            'The donor chose you to collect "' . $task['food_name'] . '".',
            'collector', $task['donation_id']);

        if (!empty($task['request_id'])) {
            $rr = $pdo->prepare("SELECT recipient_id FROM food_requests WHERE request_id=:r");
            $rr->execute([':r' => $task['request_id']]);
            $recipientId = (int)$rr->fetchColumn();
            if ($recipientId) {
                notify($pdo, $recipientId, '🚴 Collector Assigned',
                    'A collector will bring your food soon.', 'collector', $task['donation_id']);
            }
        }

        notify_admins($pdo, '🚴 Collector Assigned',
            'Donor accepted collector for donation #' . $task['donation_id'],
            'collector', $task['donation_id']);

        set_flash('success', 'Collector accepted. All other requests were rejected automatically.');
        redirect(BASE_URL . 'donor/donation-details.php?id=' . $task['donation_id']);

    } else {
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
        if ($req['donation_status'] !== 'accepted') {
            throw new Exception('You must first accept a recipient request.');
        }

        $pdo->prepare("UPDATE ngo_requests SET status='accepted', accepted_at=NOW() WHERE ngo_request_id=:id")
            ->execute([':id' => $reqId]);

        $pdo->prepare("
            UPDATE ngo_requests SET status='rejected'
            WHERE donation_id = :d AND ngo_request_id <> :id AND status='pending'
        ")->execute([':d' => $req['donation_id'], ':id' => $reqId]);

        $pdo->prepare("
            UPDATE collector_tasks SET status='rejected'
            WHERE donation_id = :d AND status='pending'
        ")->execute([':d' => $req['donation_id']]);

        $pdo->prepare("UPDATE food_donations SET status='ngo_assigned' WHERE donation_id=:d")
            ->execute([':d' => $req['donation_id']]);

        $pdo->prepare("INSERT INTO donation_history (donation_id, old_status, new_status, changed_by)
                       VALUES (:d, 'accepted', 'ngo_assigned', :u)")
            ->execute([':d' => $req['donation_id'], ':u' => $uid]);

        $pdo->commit();

        notify($pdo, $req['ngo_id'], '🏢 You have been assigned',
            'The donor chose your NGO for "' . $req['food_name'] . '".',
            'ngo', $req['donation_id']);

        notify_admins($pdo, '🏢 NGO Assigned',
            'Donor accepted NGO for donation #' . $req['donation_id'],
            'ngo', $req['donation_id']);

        set_flash('success', 'NGO accepted. All other requests were rejected automatically.');
        redirect(BASE_URL . 'donor/donation-details.php?id=' . $req['donation_id']);
    }

} catch (Exception $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    set_flash('error', $ex->getMessage());
    redirect(BASE_URL . 'donor/dashboard.php');
}
