<?php
// donor/notifications.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification-functions.php';
require_login();
$uid = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');
    if ($action === 'read') {
        mark_notification_read($pdo, int_post('id'), $uid);
        redirect(BASE_URL . 'donor/notifications.php');
    }
    if ($action === 'read_all') {
        mark_all_notifications_read($pdo, $uid);
        set_flash('success', 'All notifications marked as read.');
        redirect(BASE_URL . 'donor/notifications.php');
    }
}

$pageTitle = 'Notifications';
require_once __DIR__ . '/../includes/dashboard-header.php';
$notifs = get_notifications($pdo, $uid, 100);
?>

<div class="flex-between mb-3">
    <h3>Notifications (<?= count($notifs) ?>)</h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="read_all">
        <button class="btn btn-outline btn-sm">✅ Mark all as read</button>
    </form>
</div>

<div class="table-card">
    <?php if (!$notifs): ?>
        <div style="padding:40px;text-align:center">
            <div style="font-size:40px; margin-bottom:10px;">📭</div>
            <p class="text-muted">You have no notifications.</p>
        </div>
    <?php else: ?>
        <?php foreach ($notifs as $n): ?>
            <div style="padding:20px 24px;border-bottom:1px solid var(--gray-100);<?= !$n['is_read']?'background:#F0FDF4;':'' ?>">
                <div class="flex-between">
                    <div>
                        <strong style="color:<?= !$n['is_read'] ? 'var(--green-dark)' : 'var(--gray-900)' ?>; font-size:15px;">
                            <?= sanitize($n['title']) ?>
                        </strong>
                        <p style="margin:6px 0;color:var(--gray-700);font-size:14px;"><?= sanitize($n['message']) ?></p>
                        <small class="text-muted"><?= time_ago($n['created_at']) ?></small>
                    </div>
                    <?php if (!$n['is_read']): ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="read">
                            <input type="hidden" name="id" value="<?= (int)$n['notification_id'] ?>">
                            <button class="btn btn-primary btn-sm">Mark read</button>
                        </form>
                    <?php else: ?>
                        <span class="badge badge-gray">Read</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
