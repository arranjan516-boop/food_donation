<?php
// donor/reject-request.php
// PURE ACTION SCRIPT — no HTML.

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification-functions.php';

require_login();
if (current_role() !== 'donor' && current_role() !== 'admin') {
    set_flash('error', 'Access denied.');
    redirect(BASE_URL . 'index.php');
}

$uid = current_user_id();
$rid = int_get('id');

if (!$rid || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_flash('error', 'Invalid request.');
    redirect(BASE_URL . 'donor/dashboard.php');
}
verify_csrf();

$sql = "SELECT fr.*, d.donation_id, d.donor_id, d.food_name, d.status AS donation_status
        FROM food_requests fr
        JOIN food_donations d ON d.donation_id = fr.donation_id
        WHERE fr.request_id = :r";
if (current_role() !== 'admin') $sql .= " AND d.donor_id = :u";

$stmt = $pdo->prepare($sql);
$params = [':r' => $rid];
if (current_role() !== 'admin') $params[':u'] = $uid;
$stmt->execute($params);
$r = $stmt->fetch();

if (!$r || $r['status'] !== 'pending') {
    set_flash('info', 'Nothing to reject.');
    redirect(BASE_URL . 'donor/dashboard.php');
}

$pdo->prepare("UPDATE food_requests SET status='rejected' WHERE request_id=:r")
    ->execute([':r' => $rid]);

notify($pdo, (int)$r['recipient_id'], '❌ Request Declined',
    'Your request for "' . $r['food_name'] . '" was declined.',
    'request_rejected', $r['donation_id']);

set_flash('info', 'Recipient request rejected.');
redirect(BASE_URL . 'donor/donation-details.php?id=' . $r['donation_id']);
