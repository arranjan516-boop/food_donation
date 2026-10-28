<?php
// fix-admin.php
require_once __DIR__ . '/config/database.php';

$email = 'admin@foodshare.local';
$new_password = 'password123';

// Generate a fresh Bcrypt hash
$hash = password_hash($new_password, PASSWORD_DEFAULT);

// Update the database
try {
    $stmt = $pdo->prepare("UPDATE users SET password = :p, status = 'active' WHERE email = :e");
    $stmt->execute([':p' => $hash, ':e' => $email]);
    
    if ($stmt->rowCount() > 0) {
        echo "<h1 style='color:green; font-family:sans-serif;'>✅ Admin Password Fixed!</h1>";
        echo "<p style='font-family:sans-serif; font-size:18px;'>You can now log in with:</p>";
        echo "<ul style='font-family:sans-serif; font-size:18px;'>";
        echo "<li><strong>Email:</strong> " . htmlspecialchars($email) . "</li>";
        echo "<li><strong>Password:</strong> " . htmlspecialchars($new_password) . "</li>";
        echo "</ul>";
        echo "<p style='color:red; font-family:sans-serif; font-weight:bold;'>⚠️ DELETE THIS FILE NOW (fix-admin.php)!</p>";
    } else {
        echo "<h1 style='color:orange; font-family:sans-serif;'>⚠️ No admin account found with that email.</h1>";
        echo "<p style='font-family:sans-serif;'>Check your database to see what the admin email is.</p>";
    }
} catch (Exception $ex) {
    echo "<h1 style='color:red;'>Error: " . $ex->getMessage() . "</h1>";
}
?>
