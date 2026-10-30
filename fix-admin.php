<?php
require_once __DIR__ . '/config/database.php';

$email = 'admin@foodshare.local';
$new_password = 'password123';

$hash = password_hash($new_password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("UPDATE users SET password = :p WHERE email = :e");
$stmt->execute([':p' => $hash, ':e' => $email]);

echo "<h1 style='color:green;'>✅ Admin Password Reset!</h1>";
echo "<p>Log in with: <strong>" . $email . "</strong> / <strong>" . $new_password . "</strong></p>";
echo "<p style='color:red; font-weight:bold;'>⚠️ DELETE THIS FILE NOW!</p>";
?>
