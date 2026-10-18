<?php
require_once __DIR__ . '/config/database.php';

$email = 'admin@foodshare.local';
$password = 'password123';

// Generate a fresh bcrypt hash right now
$hash = password_hash($password, PASSWORD_DEFAULT);

// Update the user
$stmt = $pdo->prepare("UPDATE users SET password = :p, role = 'admin', status = 'active' WHERE email = :e");
$stmt->execute([':p' => $hash, ':e' => $email]);

echo "<h2 style='color:green'>✅ Password updated successfully!</h2>";
echo "<p>You can now log in with:</p>";
echo "<ul>";
echo "<li><strong>Email:</strong> " . htmlspecialchars($email) . "</li>";
echo "<li><strong>Password:</strong> " . htmlspecialchars($password) . "</li>";
echo "</ul>";
echo "<p style='color:red'><strong>⚠️ DELETE THIS FILE NOW!</strong></p>";
echo "<p><a href='login.php'>Go to Login</a></p>";
