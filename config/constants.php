<?php
// config/constants.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---- Site ----
define('SITE_NAME', 'FoodShare');
define('BASE_URL',  'http://localhost/food_donation/');

// ---- Filesystem paths ----
define('ROOT_PATH',      dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('UPLOAD_PATH',    ROOT_PATH . 'uploads' . DIRECTORY_SEPARATOR);
define('FOOD_UPLOAD',    UPLOAD_PATH . 'food' . DIRECTORY_SEPARATOR);
define('PROFILE_UPLOAD', UPLOAD_PATH . 'profiles' . DIRECTORY_SEPARATOR);
define('NGO_UPLOAD',     UPLOAD_PATH . 'ngo' . DIRECTORY_SEPARATOR);
define('PROOF_UPLOAD',   UPLOAD_PATH . 'delivery_proofs' . DIRECTORY_SEPARATOR);

// ---- Web paths ----
define('FOOD_UPLOAD_URL',    BASE_URL . 'uploads/food/');
define('PROFILE_UPLOAD_URL', BASE_URL . 'uploads/profiles/');
define('NGO_UPLOAD_URL',     BASE_URL . 'uploads/ngo/');
define('PROOF_UPLOAD_URL',   BASE_URL . 'uploads/delivery_proofs/');

// ---- Business rules ----
define('MATCH_RADIUS_KM', 15);
define('MAX_IMAGE_SIZE',  5 * 1024 * 1024); // 5 MB
define('ALLOWED_IMAGE_EXT', ['jpg','jpeg','png','webp']);
define('ALLOWED_IMAGE_MIME', ['image/jpeg','image/png','image/webp']);

// ---- Timezone ----
date_default_timezone_set('Asia/Kolkata');

// ---- Error display (set to 0 in production) ----
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ---- Auto-create upload dirs ----
foreach ([FOOD_UPLOAD, PROFILE_UPLOAD, NGO_UPLOAD, PROOF_UPLOAD] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}
