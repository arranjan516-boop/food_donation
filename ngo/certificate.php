<?php
// donor/certificate.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
if (current_role() !== 'donor') { redirect(BASE_URL . 'index.php'); }

$uid = current_user_id();
$id = int_get('id');

$stmt = $pdo->prepare("
    SELECT d.*, n.name AS ngo_name, u.name AS donor_name
    FROM food_donations d
    JOIN users u ON u.user_id = d.donor_id
    LEFT JOIN users n ON n.user_id = d.ngo_id
    WHERE d.donation_id = :id AND d.donor_id = :u AND d.status = 'completed'
");
$stmt->execute([':id' => $id, ':u' => $uid]);
$d = $stmt->fetch();

if (!$d) { set_flash('error', 'Certificate available only for completed donations.'); redirect(BASE_URL . 'donor/my-donations.php'); }
?>
<!DOCTYPE html>
<html>
<head>
    <title>Impact Certificate</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background: #f0f4f0; margin: 0; padding: 40px 20px; }
        .certificate {
            background: #fff; max-width: 800px; margin: 0 auto;
            padding: 60px 80px; text-align: center;
            border: 12px double #2E7D32;
            border-radius: 12px;
            position: relative;
            box-shadow: 0 20px 40px rgba(0,0,0,.1);
        }
        .certificate::before {
            content: '🌱';
            font-size: 80px; position: absolute; top: -40px; left: 50%; transform: translateX(-50%);
            background: #fff; padding: 0 20px;
        }
        h1 { color: #2E7D32; font-size: 36px; margin-top: 30px; text-transform: uppercase; letter-spacing: 2px;}
        h2 { font-size: 22px; font-weight: 600; color: #333; margin-top: 40px; }
        .donor-name { font-size: 32px; color: #1B5E20; margin: 20px 0; font-weight: 700; border-bottom: 2px solid #E8F5E9; padding-bottom: 10px; display:inline-block;}
        p { font-size: 16px; color: #555; line-height: 1.8; }
        .bold { color: #1B5E20; font-weight: 700; }
        .signature { margin-top: 60px; display: flex; justify-content: space-between; padding: 0 40px; }
        .sig-line { border-top: 2px solid #333; width: 200px; padding-top: 8px; font-size: 14px; color: #333;}
        .print-btn { display: block; text-align: center; margin-top: 30px; }
        .btn { background: #2E7D32; color: #fff; padding: 12px 30px; border: none; border-radius: 6px; font-size: 16px; cursor: pointer; text-decoration: none; }
        @media print {
            .print-btn, .no-print { display: none; }
            body { background: #fff; padding: 0; }
            .certificate { border: 12px double #2E7D32; box-shadow: none; margin: 0; }
        }
    </style>
</head>
<body>

<div class="certificate">
    <h1>Certificate of Appreciation</h1>
    <p>This certificate is proudly presented to</p>
    <div class="donor-name"><?= sanitize($d['donor_name']) ?></div>
    <p>
        For their generous contribution of <span class="bold"><?= (int)$d['people_served'] ?> meals</span> 
        (<span class="bold"><?= (float)$d['quantity'] ?> <?= sanitize($d['unit']) ?> of <?= sanitize($d['food_name']) ?></span>)
        which successfully reached the community through <span class="bold"><?= sanitize($d['ngo_name']) ?></span>.
    </p>
    <p>Donated on <span class="bold"><?= date('d M Y', strtotime($d['created_at'])) ?></span></p>

    <div class="signature">
        <div class="sig-line">Date</div>
        <div class="sig-line">FoodShare Director</div>
    </div>
</div>

<div class="print-btn">
    <button onclick="window.print()" class="btn">🖨️ Print / Save as PDF</button>
</div>

</body>
</html>
