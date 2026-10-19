<?php
// api/check-notifications.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification-functions.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['count' => 0]);
    exit;
}

$uid = current_user_id();
$count = unread_count($pdo, $uid);

echo json_encode(['count' => $count]);
exit;
