<?php
// admin/reports/donations-report.php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
if (current_role() !== 'admin') { redirect(BASE_URL . 'index.php'); }

// Date Filters
$from = get('from', date('Y-m-01')); // Default to 1st of this month
$to   = get('to',   date('Y-m-d'));

// Fetch Report Data
$stmt = $pdo->prepare("
    SELECT d.*, u.name AS donor_name
    FROM food_donations d
    JOIN users u ON u.user_id = d.donor_id
    WHERE DATE(d.created_at) BETWEEN :from AND :to
    ORDER BY d.created_at DESC
");
$stmt->execute([':from' => $from, ':to' => $to]);
$rows = $stmt->fetchAll();

// Calculate Totals
$totalCount = count($rows);
$totalQty   = array_sum(array_column($rows, 'quantity'));
$totalServed= array_sum(array_column($rows, 'people_served'));

$pageTitle = 'Donations Report';
require_once __DIR__ . '/../../includes/dashboard-header.php';
?>

<!-- Filter Bar (Hidden when printing) -->
<div class="noprint mb-3" style="background:#fff; padding:20px; border-radius:12px; display:flex; justify-content:space-between; align-items:center; box-shadow:0 2px 10px rgba(0,0,0,0.03);">
    <form method="get" class="flex gap-2" style="align-items:center;">
        <label style="font-size:14px; font-weight:600;">From:</label>
        <input type="date" name="from" value="<?= sanitize($from) ?>" class="form-control" style="width:auto;">
        <label style="font-size:14px; font-weight:600; margin-left:10px;">To:</label>
        <input type="date" name="to" value="<?= sanitize($to) ?>" class="form-control" style="width:auto;">
        <button class="btn btn-primary" style="margin-left:10px;">Apply Filter</button>
    </form>
    <button onclick="window.print()" class="btn btn-accent">🖨️ Print / Save PDF</button>
</div>

<!-- Printable Report Area -->
<div class="admin-table-wrapper" id="report-content" style="padding:40px;">
    
    <div style="text-align:center; margin-bottom:30px; border-bottom:2px solid #2E7D32; padding-bottom:20px;">
        <h1 style="font-size:24px; color:#2E7D32; margin-bottom:5px;">FoodShare — Donations Report</h1>
        <p style="color:#666; font-size:14px;">Period: <strong><?= date('d M Y', strtotime($from)) ?></strong> to <strong><?= date('d M Y', strtotime($to)) ?></strong></p>
        <p style="color:#999; font-size:12px;">Generated on: <?= date('d M Y, h:i A') ?></p>
    </div>

    <div style="display:flex; justify-content:space-between; margin-bottom:30px; background:#F9FAFB; padding:20px; border-radius:8px; border:1px solid #eee;">
        <div style="text-align:center; flex:1;">
            <div style="font-size:24px; font-weight:700; color:#333;"><?= $totalCount ?></div>
            <div style="font-size:12px; text-transform:uppercase; color:#666;">Donations</div>
        </div>
        <div style="text-align:center; flex:1; border-left:1px solid #ddd; border-right:1px solid #ddd;">
            <div style="font-size:24px; font-weight:700; color:#333;"><?= number_format($totalQty) ?></div>
            <div style="font-size:12px; text-transform:uppercase; color:#666;">Total Quantity</div>
        </div>
        <div style="text-align:center; flex:1;">
            <div style="font-size:24px; font-weight:700; color:#2E7D32;"><?= number_format($totalServed) ?></div>
            <div style="font-size:12px; text-transform:uppercase; color:#666;">People Served</div>
        </div>
    </div>

    <?php if (!$rows): ?>
        <p style="text-align:center; color:#666; padding:40px;">No donations found in this date range.</p>
    <?php else: ?>
        <table style="width:100%; border-collapse:collapse; font-size:12px;">
            <thead>
                <tr style="background:#E8F5E9;">
                    <th style="border:1px solid #ccc; padding:10px; text-align:left;">#</th>
                    <th style="border:1px solid #ccc; padding:10px; text-align:left;">Date</th>
                    <th style="border:1px solid #ccc; padding:10px; text-align:left;">Donor</th>
                    <th style="border:1px solid #ccc; padding:10px; text-align:left;">Food</th>
                    <th style="border:1px solid #ccc; padding:10px; text-align:left;">Qty</th>
                    <th style="border:1px solid #ccc; padding:10px; text-align:left;">Served</th>
                    <th style="border:1px solid #ccc; padding:10px; text-align:left;">Method</th>
                    <th style="border:1px solid #ccc; padding:10px; text-align:left;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($rows as $d): ?>
                    <tr>
                        <td style="border:1px solid #ccc; padding:8px;"><?= $i++ ?></td>
                        <td style="border:1px solid #ccc; padding:8px;"><?= date('d M Y', strtotime($d['created_at'])) ?></td>
                        <td style="border:1px solid #ccc; padding:8px;"><?= sanitize($d['donor_name']) ?></td>
                        <td style="border:1px solid #ccc; padding:8px;"><?= sanitize($d['food_name']) ?></td>
                        <td style="border:1px solid #ccc; padding:8px;"><?= (float)$d['quantity'] ?> <?= sanitize($d['unit']) ?></td>
                        <td style="border:1px solid #ccc; padding:8px;"><?= (int)$d['people_served'] ?></td>
                        <td style="border:1px solid #ccc; padding:8px;"><?= ucwords(str_replace('_',' ',$d['delivery_preference'])) ?></td>
                        <td style="border:1px solid #ccc; padding:8px;"><?= ucwords(str_replace('_',' ',$d['status'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div style="text-align:center; margin-top:40px; font-size:10px; color:#999;">
        <p>FoodShare — Official Report — Page 1</p>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/dashboard-footer.php'; ?>
