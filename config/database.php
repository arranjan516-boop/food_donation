<?php
// config/database.php
require_once __DIR__ . '/constants.php';

$DB_HOST = 'localhost';
$DB_NAME = 'food_waste_donation';
$DB_USER = 'root';
$DB_PASS = '';           // XAMPP default = empty
$DB_CHAR = 'utf8mb4';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHAR}",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // Never expose raw errors to users
    error_log('DB Connection failed: ' . $e->getMessage());
    die('Database connection failed. Please try again later.');
}
