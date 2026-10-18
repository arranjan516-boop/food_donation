<?php
// admin/analytics/index.php
$pageTitle = 'Analytics';
require_once __DIR__ . '/../../includes/dashboard-header.php';

if (current_role() !== 'admin') { redirect(BASE_URL . 'index.php'); }

// 1. Users by Role
$roles = $pdo->query("SELECT role, COUNT(*) as c FROM users GROUP BY role")->fetchAll();
$totalUsers = array_sum(array_column($roles, 'c'));

// 2. Donations by Status
$statuses = $pdo->query("SELECT status, COUNT(*) as c FROM food_donations GROUP BY status")->fetchAll();
$totalDonations = array_sum(array_column($statuses, 'c'));

// 3. Monthly Donations (Last 6 Months)
$monthly = $pdo->query("
    SELECT DATE_FORMAT(created_at, '%b') as month, COUNT(*) as total
    FROM food_donations
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY YEAR(created_at), MONTH(created_at), month
    ORDER BY created_at ASC
")->fetchAll();
$maxMonthly = $monthly ? max(array_column($monthly, 'total')) : 1;

// 4. Top Donors
$topDonors = $pdo->query("
    SELECT u.name, COUNT(d.donation_id) as total_donations, COALESCE(SUM(d.people_served),0) as people_served
    FROM users u
    JOIN food_donations d ON d.donor_id = u.user_id
    GROUP BY u.user_id
    ORDER BY total_donations DESC LIMIT 5
")->fetchAll();

// 5. Delivery Methods
$methods = $pdo->query("SELECT delivery_preference, COUNT(*) as c FROM food_donations GROUP BY delivery_preference")->fetchAll();
$methodLabels = ['self_delivery'=>'Self Delivery', 'collector'=>'Collector', 'ngo'=>'NGO', 'any'=>'Any'];
?>

<!-- KPI Row -->
<div class="admin-kpi-grid">
    <div class="admin-kpi-card primary">
        <div class="admin-kpi-icon bg-green">👥</div>
        <div>
            <div class="admin-kpi-value"><?= number_format($totalUsers) ?></div>
            <div class="admin-kpi-label">Total Users</div>
        </div>
    </div>
    <div class="admin-kpi-card info">
        <div class="admin-kpi-icon bg-blue">🍱</div>
        <div>
            <div class="admin-kpi-value"><?= number_format($totalDonations) ?></div>
            <div class="admin-kpi-label">Total Donations</div>
        </div>
    </div>
    <div class="admin-kpi-card warning">
        <div class="admin-kpi-icon bg-orange">🏢</div>
        <div>
            <div class="admin-kpi-value"><?= number_format($pdo->query("SELECT COUNT(*) FROM delivery_proofs")->fetchColumn()) ?></div>
            <div class="admin-kpi-label">Verified Proofs</div>
        </div>
    </div>
</div>

<div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px;">
    <!-- Monthly Bar Chart -->
    <div class="chart-container">
        <div class="chart-title">Monthly Donations (Last 6 Months)</div>
        <?php if (!$monthly): ?>
            <p class="text-muted">Not enough data yet.</p>
        <?php else: ?>
            <div class="css-bar-chart">
                <?php foreach ($monthly as $m): ?>
                    <div class="css-bar-col">
                        <div class="css-bar" style="height: <?= max(10, ($m['total'] / $maxMonthly) * 180) ?>px">
                            <span><?= (int)$m['total'] ?></span>
                        </div>
                        <div class="css-bar-label"><?= sanitize($m['month']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Delivery Methods -->
    <div class="chart-container">
        <div class="chart-title">Delivery Methods</div>
        <?php 
            $colors = ['#2E7D32','#F57C00','#1976D2','#7B1FA2'];
            $totalM = array_sum(array_column($methods, 'c'));
        ?>
        <div class="css-donut-list">
            <?php foreach ($methods as $i => $m): ?>
                <div class="css-donut-row">
                    <div class="css-donut-dot" style="background:<?= $colors[$i % count($colors)] ?>"></div>
                    <div class="css-donut-label"><?= $methodLabels[$m['delivery_preference']] ?? $m['delivery_preference'] ?></div>
                    <div class="css-donut-pct"><?= round(($m['c'] / $totalM) * 100) ?>%</div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap:24px; margin-top:24px;">
    <!-- Donations by Status -->
    <div class="chart-container">
        <div class="chart-title">Donations by Status</div>
        <div class="css-donut-list">
            <?php 
                $sColors = ['available'=>'#2E7D32','completed'=>'#1976D2','requested'=>'#F57C00','cancelled'=>'#D32F2F','accepted'=>'#7B1FA2','delivered'=>'#00897B'];
                foreach ($statuses as $s): 
                $pct = $totalDonations > 0 ? round(($s['c'] / $totalDonations) * 100) : 0;
            ?>
                <div class="css-donut-row">
                    <div class="css-donut-dot" style="background:<?= $sColors[$s['status']] ?? '#999' ?>"></div>
                    <div class="css-donut-label"><?= ucfirst(str_replace('_',' ', $s['status'])) ?></div>
                    <div class="css-donut-pct"><?= $s['c'] ?> (<?= $pct ?>%)</div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Top Donors -->
    <div class="chart-container">
        <div class="chart-title">Top Donors</div>
        <table style="width:100%; border-collapse:collapse; font-size:14px;">
            <thead>
                <tr style="border-bottom:1px solid #eee; text-align:left; color:#666;">
                    <th style="padding:8px 0;">Name</th>
                    <th style="padding:8px 0; text-align:right;">Donations</th>
                    <th style="padding:8px 0; text-align:right;">Meals</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topDonors as $d): ?>
                    <tr style="border-bottom:1px solid #f9f9f9;">
                        <td style="padding:10px 0; font-weight:500;"><?= sanitize($d['name']) ?></td>
                        <td style="padding:10px 0; text-align:right;"><?= $d['total_donations'] ?></td>
                        <td style="padding:10px 0; text-align:right; color:var(--green); font-weight:600;"><?= $d['people_served'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/dashboard-footer.php'; ?>
