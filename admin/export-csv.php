<?php
// admin/export-csv.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
if (current_role() !== 'admin') { 
    http_response_code(403);
    die('Access denied');
}

$type = get('type', 'donations');
$filename = 'foodshare_' . $type . '_' . date('Y-m-d_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

if ($type === 'donations') {
    fputcsv($output, ['ID', 'Donor', 'Food', 'Category', 'Type', 'Quantity', 'Unit', 'People Served', 'City', 'Area', 'Status', 'Posted At']);
    $stmt = $pdo->query("SELECT d.*, u.name AS donor_name FROM food_donations d JOIN users u ON u.user_id = d.donor_id ORDER BY d.created_at DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [$row['donation_id'], $row['donor_name'], $row['food_name'], $row['food_category'], $row['food_type'], $row['quantity'], $row['unit'], $row['people_served'], $row['city'], $row['area'], $row['status'], $row['created_at']]);
    }
} elseif ($type === 'users') {
    fputcsv($output, ['ID', 'Name', 'Email', 'Phone', 'Role', 'City', 'Area', 'Pincode', 'Status', 'Joined']);
    $stmt = $pdo->query("SELECT * FROM users ORDER BY created_at DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [$row['user_id'], $row['name'], $row['email'], $row['phone'], $row['role'], $row['city'], $row['area'], $row['pincode'], $row['status'], $row['created_at']]);
    }
} elseif ($type === 'deliveries') {
    fputcsv($output, ['Proof ID', 'Donation', 'Food', 'Uploaded By', 'Role', 'Uploaded At']);
    $stmt = $pdo->query("SELECT dp.*, d.food_name, u.name AS uploader_name FROM delivery_proofs dp JOIN food_donations d ON d.donation_id = dp.donation_id JOIN users u ON u.user_id = dp.uploaded_by ORDER BY dp.uploaded_at DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [$row['proof_id'], $row['donation_id'], $row['food_name'], $row['uploader_name'], $row['uploaded_by_role'], $row['uploaded_at']]);
    }
}

fclose($output);
exit;
