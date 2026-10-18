<?php
// admin/analytics/index.php
$pageTitle = 'Analytics & Reports';
require_once __DIR__ . '/../../includes/dashboard-header.php';

if (current_role() !== 'admin') { redirect(BASE_URL . 'index.php'); }

// --- 1. Fetch Full Donation Details ---
$totalDonations = (int)$pdo->query("SELECT COUNT(*) FROM food_donations")->fetchColumn();
$totalQuantity  = (float)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM food_donations")->fetchColumn();
$totalServed    = (int)$pdo->query("SELECT COALESCE(SUM(people_served),0) FROM food_donations WHERE status='completed'")->fetchColumn();
$completed      = (int)$pdo->query("SELECT COUNT(*) FROM food_donations WHERE status='completed'")->fetchColumn();
$active         = (int)$pdo->query("SELECT COUNT(*) FROM food_donations WHERE status NOT IN ('completed','cancelled','expired')")->fetchColumn();

// --- 2. Chart Data: Status Distribution ---
$statuses = $pdo->query("SELECT status, COUNT(*) as c FROM food_donations GROUP BY status")->fetchAll();
$statusLabels = [];
$statusData   = [];
foreach ($statuses as $s) {
    $statusLabels[] = ucfirst(str_replace('_',' ', $s['status']));
    $statusData[]   = $s['c'];
}

// --- 3. Chart Data: Delivery Methods ---
$methods = $pdo->query("SELECT delivery_preference, COUNT(*) as c FROM food_donations GROUP BY delivery_preference")->fetchAll();
$methodLabels = [];
$methodData   = [];
$mapMethod = ['self_delivery'=>'Self Delivery', 'collector'=>'Collector', 'ngo'=>'NGO', 'any'=>'Any'];
foreach ($methods as $m) {
    $methodLabels[] = $mapMethod[$m['delivery_preference']] ?? $m['delivery_preference'];
    $methodData[]   = $m['c'];
}

// --- 4. Chart Data: Monthly Trend (Last 6 Months) ---
$monthly = $pdo->query("
    SELECT DATE_FORMAT(created_at, '%b %Y') as month, COUNT(*) as total
    FROM food_donations
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY YEAR(created_at), MONTH(created_at), month
    ORDER BY created_at ASC
")->fetchAll();
$monthLabels = [];
$monthData   = [];
foreach ($monthly as $m) {
    $monthLabels[] = $m['month'];
    $monthData[]   = $m['total'];
}
?>

<!-- Load Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- KPI Cards -->
<div class="admin-kpi-grid">
    <div class="admin-kpi-card primary">
        <div class="admin-kpi-icon bg-green">📦</div>
        <div>
            <div class="admin-kpi-value"><?= number_format($totalDonations) ?></div>
            <div class="admin-kpi-label">Total Donations</div>
        </div>
    </div>
    <div class="admin-kpi-card info">
        <div class="admin-kpi-icon bg-blue">⚖️</div>
        <div>
            <div class="admin-kpi-value"><?= number_format($totalQuantity, 1) ?></div>
            <div class="admin-kpi-label">Quantity Donated</div>
        </div>
    </div>
    <div class="admin-kpi-card warning">
        <div class="admin-kpi-icon bg-orange">👥</div>
        <div>
            <div class="admin-kpi-value"><?= number_format($totalServed) ?></div>
            <div class="admin-kpi-label">People Served</div>
        </div>
    </div>
    <div class="admin-kpi-card">
        <div class="admin-kpi-icon bg-green">✅</div>
        <div>
            <div class="admin-kpi-value"><?= number_format($completed) ?></div>
            <div class="admin-kpi-label">Completed</div>
        </div>
    </div>
    <div class="admin-kpi-card danger">
        <div class="admin-kpi-icon bg-red">🎯</div>
        <div>
            <div class="admin-kpi-value"><?= number_format($active) ?></div>
            <div class="admin-kpi-label">Active Now</div>
        </div>
    </div>
</div>

<!-- Charts Row 1 -->
<div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px; margin-bottom:24px;">
    <!-- Bar Chart: Monthly Trend -->
    <div class="chart-container">
        <div class="chart-title">Monthly Donation Trend</div>
        <canvas id="monthlyChart" height="100"></canvas>
    </div>

    <!-- Doughnut Chart: Delivery Methods -->
    <div class="chart-container">
        <div class="chart-title">Delivery Methods</div>
        <canvas id="methodChart" height="200"></canvas>
    </div>
</div>

<!-- Charts Row 2 -->
<div style="display:grid; grid-template-columns: 1fr; gap:24px;">
    <!-- Doughnut Chart: Donation Statuses -->
    <div class="chart-container">
        <div class="chart-title">Donation Status Distribution</div>
        <div style="max-width:400px; margin:0 auto;">
            <canvas id="statusChart" height="200"></canvas>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Common Chart.js Options
    Chart.defaults.font.family = "'Poppins', sans-serif";
    Chart.defaults.color = '#6B7280';

    // 1. Monthly Trend (Bar Chart)
    new Chart(document.getElementById('monthlyChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($monthLabels) ?>,
            datasets: [{
                label: 'Donations',
                data: <?= json_encode($monthData) ?>,
                backgroundColor: '#2E7D32',
                borderRadius: 6,
                barThickness: 40
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 } },
                x: { grid: { display: false } }
            }
        }
    });

    // 2. Delivery Methods (Doughnut Chart)
    new Chart(document.getElementById('methodChart'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($methodLabels) ?>,
            datasets: [{
                data: <?= json_encode($methodData) ?>,
                backgroundColor: ['#2E7D32', '#F57C00', '#1976D2', '#7B1FA2'],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            cutout: '70%',
            plugins: {
                legend: { position: 'bottom', labels: { padding: 20, usePointStyle: true } }
            }
        }
    });

    // 3. Status Distribution (Doughnut Chart)
    new Chart(document.getElementById('statusChart'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($statusLabels) ?>,
            datasets: [{
                data: <?= json_encode($statusData) ?>,
                backgroundColor: ['#2E7D32', '#FBC02D', '#1976D2', '#7B1FA2', '#D32F2F', '#00897B'],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            cutout: '60%',
            plugins: {
                legend: { position: 'bottom', labels: { padding: 20, usePointStyle: true } }
            }
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/dashboard-footer.php'; ?>
