<?php
// chat.php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notification-functions.php';

require_login();
$uid = current_user_id();
$donationId = int_get('donation_id');

if (!$donationId) {
    set_flash('error', 'Invalid donation.');
    redirect(BASE_URL . 'index.php');
}

$stmt = $pdo->prepare("
    SELECT d.*, 
           (SELECT recipient_id FROM food_requests WHERE donation_id = d.donation_id AND status IN ('accepted','completed') LIMIT 1) AS recipient_id
    FROM food_donations d
    WHERE d.donation_id = :id
");
$stmt->execute([':id' => $donationId]);
$d = $stmt->fetch();

if (!$d) { set_flash('error', 'Donation not found.'); redirect(BASE_URL . 'index.php'); }

$isDonor = ((int)$d['donor_id'] === $uid);
$isRecipient = ((int)$d['recipient_id'] === $uid);
$isAdmin = (current_role() === 'admin');

if (!$isDonor && !$isRecipient && !$isAdmin) {
    set_flash('error', 'You are not part of this chat.');
    redirect(BASE_URL . 'index.php');
}

if (!$d['recipient_id']) {
    set_flash('error', 'No recipient assigned to this donation yet.');
    redirect(BASE_URL . 'index.php');
}

$partnerId = $isDonor ? (int)$d['recipient_id'] : (int)$d['donor_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $msg = post('message');
    if ($msg === '') {
        $errors[] = 'Message cannot be empty.';
    } else {
        $pdo->prepare("INSERT INTO messages (donation_id, sender_id, receiver_id, message) VALUES (:d, :s, :r, :m)")
            ->execute([':d' => $donationId, ':s' => $uid, ':r' => $partnerId, ':m' => $msg]);

        $pdo->prepare("UPDATE messages SET is_read = 1 WHERE donation_id = :d AND receiver_id = :u")
            ->execute([':d' => $donationId, ':u' => $uid]);

        notify($pdo, $partnerId, '💬 New Message', 
            current_user()['name'] . ' sent a message about "' . $d['food_name'] . '".',
            'chat', $donationId);

        redirect(BASE_URL . 'chat.php?donation_id=' . $donationId);
    }
}

$msgs = $pdo->prepare("
    SELECT m.*, u.name AS sender_name, u.role AS sender_role
    FROM messages m
    JOIN users u ON u.user_id = m.sender_id
    WHERE m.donation_id = :d
      AND (m.sender_id = :u1 OR m.receiver_id = :u1)
    ORDER BY m.created_at ASC
");
$msgs->execute([':d' => $donationId, ':u1' => $uid]);
$messages = $msgs->fetchAll();

$pdo->prepare("UPDATE messages SET is_read = 1 WHERE donation_id = :d AND receiver_id = :u")
    ->execute([':d' => $donationId, ':u' => $uid]);

$pstmt = $pdo->prepare("SELECT name, role FROM users WHERE user_id = :u");
$pstmt->execute([':u' => $partnerId]);
$partner = $pstmt->fetch();

$pageTitle = 'Chat — ' . $d['food_name']; 
require_once __DIR__ . '/includes/dashboard-header.php'; 
?>

<div class="chat-layout">
    <div class="chat-header">
        <div>
            <h2>💬 <?= sanitize($d['food_name']) ?></h2>
            <p class="text-muted">Chat with <strong><?= sanitize($partner['name']) ?></strong> (<?= sanitize($partner['role']) ?>)</p>
        </div>
        <a href="<?= BASE_URL ?><?= $isDonor ? 'donor/donation-details.php' : 'recipient/request-details.php' ?>?id=<?= $isDonor ? $donationId : $d['request_id'] ?? '' ?>" class="btn btn-outline btn-sm">← Back</a>
    </div>

    <div class="chat-window" id="chatWindow">
        <?php if (!$messages): ?>
            <p style="text-align:center;color:#999;padding:40px">No messages yet. Say hello!</p>
        <?php else: ?>
            <?php foreach ($messages as $m): ?>
                <?php $mine = ((int)$m['sender_id'] === $uid); ?>
                <div class="chat-msg <?= $mine ? 'mine' : 'theirs' ?>">
                    <div class="chat-bubble">
                        <small><strong><?= $mine ? 'You' : sanitize($m['sender_name']) ?></strong> · <?= time_ago($m['created_at']) ?></small>
                        <p><?= nl2br(sanitize($m['message'])) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($errors): ?>
        <div class="toast toast-error"><?= sanitize($errors[0]) ?></div>
    <?php endif; ?>

    <form method="post" class="chat-input">
        <?= csrf_field() ?>
        <input type="text" name="message" class="form-control" placeholder="Type your message..." autocomplete="off" autofocus required>
        <button class="btn btn-primary">Send</button>
    </form>
</div>

<script>
    const cw = document.getElementById('chatWindow');
    if (cw) cw.scrollTop = cw.scrollHeight;
</script>

<style>
.chat-layout { max-width: 800px; margin: 0 auto; }
.chat-header { background: #fff; padding: 20px; border-radius: 12px; margin-bottom: 16px; box-shadow: 0 2px 8px rgba(0,0,0,.05); display: flex; justify-content: space-between; align-items: center; }
.chat-window { background: #fff; border-radius: 12px; padding: 20px; height: 480px; overflow-y: auto; box-shadow: 0 2px 8px rgba(0,0,0,.05); margin-bottom: 16px; display: flex; flex-direction: column; gap: 12px; }
.chat-msg { display: flex; }
.chat-msg.mine { justify-content: flex-end; }
.chat-msg.theirs { justify-content: flex-start; }
.chat-bubble { max-width: 75%; padding: 10px 14px; border-radius: 12px; }
.chat-msg.mine .chat-bubble { background: var(--green); color: #fff; border-bottom-right-radius: 4px; }
.chat-msg.theirs .chat-bubble { background: #F3F4F6; color: var(--gray-900); border-bottom-left-radius: 4px; }
.chat-bubble small { opacity: .8; font-size: 11px; }
.chat-bubble p { margin: 4px 0 0; font-size: 14px; }
.chat-input { display: flex; gap: 10px; background: #fff; padding: 12px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.05); }
.chat-input .form-control { flex: 1; }
</style>

<?php require_once __DIR__ . '/includes/dashboard-footer.php'; ?>
