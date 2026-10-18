<?php
// includes/functions.php
require_once __DIR__ . '/../config/database.php';

/* =====================  OUTPUT / SAFETY  ===================== */

function sanitize($str) {
    return htmlspecialchars(trim((string)$str), ENT_QUOTES, 'UTF-8');
}

function redirect($path) {
    // Discard any output accidentally printed (like hidden spaces or BOM)
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    // Force the header
    if (!headers_sent()) {
        header('Location: ' . $path);
    }
    exit;
}

/* =====================  FLASH MESSAGES  ===================== */

function set_flash($type, $message) {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flash() {
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

function render_flash() {
    foreach (get_flash() as $f) {
        $type = sanitize($f['type']);
        $msg  = sanitize($f['message']);
        echo "<div class='toast toast-{$type}'>{$msg}</div>";
    }
}

/* =====================  CSRF  ===================== */

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function verify_csrf() {
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        die('Invalid CSRF token. Please refresh the page.');
    }
}

/* =====================  INPUT  ===================== */

function post($key, $default = '') {
    return isset($_POST[$key]) ? trim($_POST[$key]) : $default;
}

function get($key, $default = '') {
    return isset($_GET[$key]) ? trim($_GET[$key]) : $default;
}

function int_get($key, $default = 0) {
    return (int)($_GET[$key] ?? $default);
}

function int_post($key, $default = 0) {
    return (int)($_POST[$key] ?? $default);
}

/* =====================  DISTANCE (HAVERSINE)  ===================== */

function haversine_km($lat1, $lng1, $lat2, $lng2) {
    if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) {
        return null;
    }
    $R    = 6371; // Earth radius km
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a    = sin($dLat/2) ** 2
          + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/* =====================  IMAGE UPLOAD (SECURE)  ===================== */

function upload_image($file, $destDir, $urlPrefix) {
    if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        set_flash('error', 'Upload failed (code ' . $file['error'] . ').');
        return null;
    }
    if ($file['size'] > MAX_IMAGE_SIZE) {
        set_flash('error', 'Image too large (max 5 MB).');
        return null;
    }

    $tmp = $file['tmp_name'];
    if (!is_uploaded_file($tmp)) {
        set_flash('error', 'Invalid upload.');
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($tmp);
    if (!in_array($mime, ALLOWED_IMAGE_MIME, true)) {
        set_flash('error', 'Only JPG, PNG, or WEBP images allowed.');
        return null;
    }

    if (getimagesize($tmp) === false) {
        set_flash('error', 'File is not a valid image.');
        return null;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_IMAGE_EXT, true)) {
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'jpg',
        };
    }

    $newName = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $target  = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR . $newName;

    if (!move_uploaded_file($tmp, $target)) {
        set_flash('error', 'Could not save uploaded file.');
        return null;
    }
    return $newName;
}

/* =====================  MISC  ===================== */

function time_ago($datetime) {
    if (!$datetime) return '—';
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60)   . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600) . ' hr ago';
    if ($diff < 604800) return floor($diff / 86400). ' days ago';
    return date('d M Y', strtotime($datetime));
}

function status_badge($status) {
    $map = [
        'available'             => 'badge-green',
        'requested'             => 'badge-yellow',
        'accepted'              => 'badge-blue',
        'collector_assigned'    => 'badge-purple',
        'ngo_assigned'          => 'badge-purple',
        'pickup_scheduled'      => 'badge-blue',
        'picked_up'             => 'badge-orange',
        'out_for_delivery'      => 'badge-blue',
        'delivered'             => 'badge-green',
        'awaiting_proof'        => 'badge-orange',
        'awaiting_confirmation' => 'badge-purple',
        'completed'             => 'badge-green',
        'cancelled'             => 'badge-red',
        'expired'               => 'badge-red',
        'pending'               => 'badge-yellow',
        'rejected'              => 'badge-red',
        'open'                  => 'badge-red',
    ];
    $cls  = $map[$status] ?? 'badge-gray';
    $text = ucwords(str_replace('_', ' ', $status));
    return "<span class='badge {$cls}'>{$text}</span>";
}

function food_photo_url($photo) {
    if (!$photo) return BASE_URL . 'assets/images/default-food.jpg';
    return FOOD_UPLOAD_URL . rawurlencode($photo);
}
