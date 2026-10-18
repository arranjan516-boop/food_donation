<?php
require_once __DIR__ . '/config/database.php';

$email = 'admin@foodshare.local';
$password = 'password123';

// Generate the hash right here on your computer
$hash = password_hash($password, PASSWORD_DEFAULT);

// Insert the admin account
$sql = "INSERT INTO users (name, email, password, role, status) VALUES ('Administrator', :email, :hash, 'admin', 'active')";
$stmt = $pdo->prepare($sql);
$stmt->execute([':email' => $email, ':hash' => $hash]);

echo "<h1>✅ Admin Account Created!</h1>";
echo "<p>You can now log in.</p>";
