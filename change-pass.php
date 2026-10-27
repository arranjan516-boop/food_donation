<?php
require_once __DIR__ . '/config/database.php';

// --- CHANGE THESE TWO LINES ---
$new_email = 'admin@foodshare.local';  // Your current admin email
$new_password = 'ARm2006!!';    // Put your new password here
// ------------------------------

$hash = password_hash($new_password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("UPDATE users SET password = :p WHERE email = :e AND role = 'admin'");
$stmt->execute([':p' => $hash, ':e' => $new_email]);

if ($stmt->rowCount() > 0) {
    echo "<h1 style='color:green'>✅ Admin Password Updated Successfully!</h1>";
    echo "<p><strong>Email:</strong> " . htmlspecialchars($new_email) . "</p>";
    echo "<p><strong>New Password:</strong> " . htmlspecialchars($new_password) . "</p>";
    echo "<p style='color:red'><strong>⚠️ DELETE THIS FILE NOW!</strong></p>";
} else {
    echo "<h1 style='color:red'>❌ Error: Admin email not found.</h1>";
}
?>
