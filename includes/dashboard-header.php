<?php
// includes/dashboard-header.php
if (ob_get_level() === 0) {
    ob_start(); // Start output buffering to prevent headers already sent errors
}

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notification-functions.php';

require_login();

$__role      = current_role();
$__user      = current_user();
$__bellCount = unread_count($pdo, current_user_id());
$__pageTitle = $pageTitle ?? 'Dashboard';
$__current   = basename($_SERVER['PHP_SELF']);

$__menus = [
    'donor' => [
        ['Dashboard',       'donor/dashboard.php',         '🏠'],
        ['Donate Food',     'donor/donate-food.php',       '🍱'],
        ['My Donations',    'donor/my-donations.php',      '📦'],
        ['Food Requests',   'donor/food-requests.php',     '📨'],
        ['Delivery Options','donor/delivery-options.php',  '🚚'],
        ['Pickup Schedule', 'donor/pickup-schedule.php',   '📅'],
        ['History',         'donor/history.php',           '🕘'],
        ['Notifications',   'donor/notifications.php',     '🔔'],
        ['Feedback',        'donor/feedback.php',          '⭐'],
        ['Profile',         'donor/profile.php',           '👤'],
    ],
    'recipient' => [
        ['Dashboard',       'recipient/dashboard.php',      '🏠'],
        ['Available Food',  'recipient/available-food.php', '🍲'],
        ['My Requests',     'recipient/my-requests.php',    '📨'],
        ['Delivery Status', 'recipient/delivery-status.php','🚚'],
        ['History',         'recipient/history.php',        '🕘'],
        ['Notifications',   'recipient/notifications.php',  '🔔'],
        ['Feedback',        'recipient/feedback.php',       '⭐'],
        ['Profile',         'recipient/profile.php',        '👤'],
    ],
    'collector' => [
        ['Dashboard',        'collector/dashboard.php',         '🏠'],
        ['Available Tasks',  'collector/available-tasks.php',   '📋'],
        ['My Tasks',         'collector/my-tasks.php',          '✅'],
        ['Active Delivery',  'collector/active-delivery.php',   '🚚'],
        ['Completed',        'collector/completed-tasks.php',   '🏁'],
        ['History',          'collector/history.php',           '🕘'],
        ['Notifications',    'collector/notifications.php',     '🔔'],
        ['Feedback',         'collector/feedback.php',          '⭐'],
        ['Profile',          'collector/profile.php',           '👤'],
    ],
    'ngo' => [
        ['Dashboard',           'ngo/dashboard.php',            '🏠'],
        ['Available Donations', 'ngo/available-donations.php',  '🍱'],
        ['My Donations',        'ngo/my-donations.php',         '📦'],
        ['Pickup Schedule',     'ngo/pickup-schedule.php',      '📅'],
        ['Distribution',        'ngo/distribution.php',         '🍽️'],
        ['History',             'ngo/history.php',              '🕘'],
        ['Notifications',       'ngo/notifications.php',        '🔔'],
        ['Feedback',            'ngo/feedback.php',             '⭐'],
        ['Profile',             'ngo/profile.php',              '👤'],
    ],
    'admin' => [
        ['Dashboard',     'admin/dashboard.php',            '🏠'],
        ['Users',         'admin/users/index.php',          '👥'],
        ['Donors',        'admin/donors/index.php',         '🍱'],
        ['Recipients',    'admin/recipients/index.php',     '🙋'],
        ['Collectors',    'admin/collectors/index.php',     '🚴'],
        ['NGOs',          'admin/ngos/index.php',           '🏢'],
        ['Donations',     'admin/donations/index.php',      '📦'],
        ['Requests',      'admin/requests/index.php',       '📨'],
        ['Collector Tasks','admin/collector-tasks/index.php','📋'],
        ['NGO Requests',  'admin/ngo-requests/index.php',   '🤝'],
        ['Deliveries',    'admin/deliveries/index.php',     '🚚'],
        ['Notifications', 'admin/notifications/index.php',  '🔔'],
        ['Feedback',      'admin/feedback/index.php',       '⭐'],
        ['Reports',       'admin/reports/donations-report.php','📊'],
        ['Analytics',     'admin/analytics/index.php',      '📈'],
        ['Settings',      'admin/settings/index.php',       '⚙️'],
    ],
];

$__menu = $__menus[$__role] ?? [];
$__initial = strtoupper(substr($__user['name'] ?: 'U', 0, 1));

$__avatarStmt = $pdo->prepare("SELECT profile_photo FROM users WHERE user_id = :u");
$__avatarStmt->execute([':u' => current_user_id()]);
$__avatarPhoto = $__avatarStmt->fetchColumn() ?: null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($__pageTitle) ?> — <?= SITE_NAME ?></title>
    <link rel="icon" href="<?= BASE_URL ?>assets/images/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Core Styles -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/dashboard.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/responsive.css">
    
    <!-- Load Advanced User Theme ONLY for non-admin modules -->
    <?php if (strpos($_SERVER['PHP_SELF'], '/admin/') === false): ?>
        <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/user-advanced.css">
    <?php endif; ?>

    <!-- Admin Specific Styles -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    
    <?php if (!empty($extraCss)) foreach ((array)$extraCss as $c): ?>
        <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/<?= sanitize($c) ?>">
    <?php endforeach; ?>

    <!-- Scripts (jQuery + DataTables) -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
</head>
<body>
<div class="dash">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <img src="<?= BASE_URL ?>assets/images/logo.png" alt="">
            <span><?= SITE_NAME ?></span>
        </div>
        <ul class="sidebar-menu">
            <?php foreach ($__menu as $item): ?>
                <?php
                    [$label, $path, $icon] = $item;
                    $isActive = ($__current === basename($path)) ? 'active' : '';
                ?>
                <li>
                    <a href="<?= BASE_URL . $path ?>" class="<?= $isActive ?>">
                        <span class="icon"><?= $icon ?></span>
                        <span><?= sanitize($label) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
            <li><a href="<?= BASE_URL ?>logout.php"><span class="icon">🚪</span> Logout</a></li>
        </ul>
    </aside>

    <div class="dash-main">
        <div class="dash-topbar">
            <button class="dash-toggle" onclick="document.querySelector('.sidebar').classList.toggle('open')">☰</button>
            <h1><?= sanitize($__pageTitle) ?></h1>
            <div class="flex gap-2" style="align-items:center">
                <a href="<?= BASE_URL . $__role ?>/notifications.php" class="bell" title="Notifications">
                    🔔
                    <?php if ($__bellCount > 0): ?>
                        <span class="count"><?= $__bellCount ?></span>
                    <?php endif; ?>
                </a>
                <div class="user-chip">
                    <div class="avatar">
                        <?php if ($__avatarPhoto): ?>
                            <img src="<?= PROFILE_UPLOAD_URL . rawurlencode($__avatarPhoto) ?>" alt="">
                        <?php else: ?>
                            <?= $__initial ?>
                        <?php endif; ?>
                    </div>
                    <span><?= sanitize($__user['name']) ?></span>
                </div>
            </div>
        </div>

        <div class="dash-content">
            <?php render_flash(); ?>
