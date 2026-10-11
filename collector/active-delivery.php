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
    SELECT ct.*,
           d.donation_id, d.donor_id, d.food_name, d.food_photo, d.unit, d.quantity, d.people_served,
           d.address AS pickup_address, d.area AS pickup_area, d.city AS pickup_city,
           d.best_before, d.urgency,
           u.name AS donor_name, u.phone AS donor_phone,
           fr.request_id, fr.recipient_id, fr.contact_number AS recipient_contact,
           fr.delivery_address AS recipient_delivery_address,
           ru.name AS recipient_name, ru.phone AS recipient_phone, ru.email AS recipient_email,
           ru.city AS recipient_city, ru.area AS recipient_area,
           ru.pincode AS recipient_pincode, ru.address AS recipient_address,
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

            if ($next === 'picked_up') {
                $pdo->prepare("UPDATE collector_tasks SET status = :s, pickup_time = NOW() WHERE task_id = :t")
                    ->execute([':s' => $next, ':t' => $taskId]);
            } else {
                $pdo->prepare("UPDATE collector_tasks SET status = :s WHERE task_id = :t")
                    ->execute([':s' => $next, ':t' => $taskId]);
            }

            $donationStatus = match ($next) {
                'pickup_started' => 'pickup_scheduled',
                'picked_up'      => 'picked_up',
                'delivering'     => 'out_for_delivery',
                'awaiting_proof' => 'out_for_delivery',
                default          => null,
            };
            if ($donationStatus) {
                $pdo->prepare("UPDATE food_donations SET status=:s WHERE donation_id=:d")
                    ->execute([':s' => $donationStatus, ':d' => $t['donation_id']]);
            }

            $pdo->prepare("INSERT INTO donation_history (donation_id, old_status, new_status, changed_by)
                           VALUES (:d, :old, :new, :u)")
                ->execute([':d' => $t['donation_id'], ':old' => $t['status'], ':new' => $next, ':u' => $uid]);

            if ($next === 'picked_up' && !empty($t['recipient_id'])) {
                notify($pdo, (int)$t['recipient_id'], '🍱 Food Collected',
                    'Your food has been collected and is on the way.', 'delivery', $t['donation_id']);
            }

            set_flash('success', 'Status updated.');
            redirect(BASE_URL . 'collector/active-delivery.php?id=' . $taskId);
        }
    }

    if ($action === 'upload_proof') {
        if (!in_array($t['status'], ['awaiting_proof','delivering'], true)) {
            $errors[] = 'You cannot upload proof at this stage.';
        } else {
            $proof = null;
            if (!empty($_FILES['proof_image']['name'])) {
                $proof = upload_image($_FILES['proof_image'], PROOF_UPLOAD, PROOF_UPLOAD_URL);
            }
            if (!$proof) {
                $errors[] = 'Please upload a valid delivery proof photo.';
            } else {
                $pdo->beginTransaction();
                try {
                    $insDel = $pdo->prepare("
                        INSERT INTO deliveries (donation_id, request_id, method, collector_id, status, delivered_at, notes)
                        VALUES (:d, :r, 'collector', :c, 'delivered', NOW(), :note)
                    ");
                    $insDel->execute([
                        ':d'    => $t['donation_id'],
                        ':r'    => $t['request_id'],
                        ':c'    => $uid,
                        ':note' => post('delivery_note'),
                    ]);
                    $deliveryId = (int)$pdo->lastInsertId();

                    $pdo->prepare("
                        INSERT INTO delivery_proofs
                          (donation_id, request_id, delivery_id, uploaded_by, uploaded_by_role, proof_image, delivery_note)
                        VALUES (:d, :r, :del, :u, 'collector', :img, :note)
                    ")->execute([
                        ':d'    => $t['donation_id'],
                        ':r'    => $t['request_id'],
                        ':del'  => $deliveryId,
                        ':u'    => $uid,
                        ':img'  => $proof,
                        ':note' => post('delivery_note'),
                    ]);

                    $pdo->prepare("UPDATE collector_tasks SET status='delivered', delivery_time=NOW() WHERE task_id=:t")
                        ->execute([':t' => $taskId]);

                    $pdo->prepare("UPDATE food_donations SET status='delivered' WHERE donation_id=
