<?php
// includes/functions.php
require_once __DIR__ . '/../config/database.php';

/* =====================  OUTPUT / SAFETY  ===================== */
function sanitize($str) {
    return htmlspecialchars(trim((string)$str), ENT_QUOTES, 'UTF-8');
}

function redirect($path) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    if (!headers_sent()) { header('Location: ' . $path); }
    exit;
}

/* =====================  FLASH MESSAGES  ===================== */
function set_flash($type, $message) { $_SESSION['flash'][] = ['type' => $type, 'message' => $message]; }
function get_flash() { $items = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $items; }
function render_flash() {
    foreach (get_flash() as $f) {
        $type = sanitize($f['type']);
        $msg  = sanitize($f['message']);
        echo "<div class='toast toast-{$type}'>{$msg}</div>";
    }
}

/* =====================  CSRF  ===================== */
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
    return $_SESSION['csrf_token'];
}
function csrf_field() { return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">'; }
function verify_csrf() {
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419); die('Invalid CSRF token. Please refresh the page.');
    }
}

/* =====================  INPUT  ===================== */
function post($key, $default = '') { return isset($_POST[$key]) ? trim($_POST[$key]) : $default; }
function get($key, $default = '') { return isset($_GET[$key]) ? trim($_GET[$key]) : $default; }
function int_get($key, $default = 0) { return (int)($_GET[$key] ?? $default); }
function int_post($key, $default = 0) { return (int)($_POST[$key] ?? $default); }

/* =====================  DISTANCE (HAVERSINE)  ===================== */
function haversine_km($lat1, $lng1, $lat2, $lng2) {
    if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) return null;
    $R = 6371; $dLat = deg2rad($lat2 - $lat1); $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/* =====================  IMAGE UPLOAD  ===================== */
function upload_image($file, $destDir, $urlPrefix) {
    if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) { set_flash('error', 'Upload failed.'); return null; }
    if ($file['size'] > MAX_IMAGE_SIZE) { set_flash('error', 'Image too large.'); return null; }
    $tmp = $file['tmp_name'];
    if (!is_uploaded_file($tmp)) { set_flash('error', 'Invalid upload.'); return null; }
    $finfo = new finfo(FILEINFO_MIME_TYPE); $mime = $finfo->file($tmp);
    if (!in_array($mime, ALLOWED_IMAGE_MIME, true)) { set_flash('error', 'Invalid format.'); return null; }
    if (getimagesize($tmp) === false) { set_flash('error', 'Not an image.'); return null; }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $newName = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $target = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR . $newName;
    if (!move_uploaded_file($tmp, $target)) return null;
    return $newName;
}

/* =====================  MISC  ===================== */
function time_ago($datetime) {
    if (!$datetime) return '—';
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now'; if ($diff < 3600) return floor($diff / 60).' min ago';
    if ($diff < 86400) return floor($diff / 3600).' hr ago'; if ($diff < 604800) return floor($diff / 86400).' days ago';
    return date('d M Y', strtotime($datetime));
}

function status_badge($status) {
    $map = [
        'available'=>'badge-green', 'requested'=>'badge-yellow', 'accepted'=>'badge-blue', 'ngo_assigned'=>'badge-purple',
        'pickup_scheduled'=>'badge-blue', 'picked_up'=>'badge-orange', 'out_for_delivery'=>'badge-blue', 'delivered'=>'badge-green',
        'completed'=>'badge-green', 'cancelled'=>'badge-red', 'expired'=>'badge-red', 'pending'=>'badge-yellow', 'rejected'=>'badge-red',
    ];
    $cls = $map[$status] ?? 'badge-gray'; $text = ucwords(str_replace('_', ' ', $status));
    return "<span class='badge {$cls}'>{$text}</span>";
}

function food_photo_url($photo) {
    if (!$photo) return BASE_URL . 'assets/images/default-food.jpg';
    return FOOD_UPLOAD_URL . rawurlencode($photo);
}

/* =====================  TIMELINE  ===================== */
function render_timeline($currentStatus) {
    $steps = [
        'requested'             => ['📝', 'Requested'],
        'accepted'              => ['✅', 'Accepted'],
        'collector_assigned'    => ['🏢', 'NGO Assigned'],
        'picked_up'             => ['📦', 'Picked Up'],
        'delivered'             => ['📍', 'Delivered'],
        'completed'             => ['🏁', 'Completed']
    ];

    if ($currentStatus === 'ngo_assigned') $currentStatus = 'collector_assigned';
    if ($currentStatus === 'pickup_scheduled') $currentStatus = 'collector_assigned';

    $keys = array_keys($steps);
    $currentIndex = array_search($currentStatus, $keys);
    
    if ($currentIndex === false && !in_array($currentStatus, ['available', 'pending'])) {
        if (in_array($currentStatus, ['cancelled', 'expired'])) {
            echo "<div class='toast toast-error mb-3'>🚫 This donation is {$currentStatus}.</div>"; return;
        }
    }

    echo '<div class="timeline-tracker">';
    foreach ($steps as $key => $step) {
        $index = array_search($key, $keys); $class = '';
        if ($currentIndex !== false) {
            if ($index < $currentIndex) $class = 'completed';
            elseif ($index === $currentIndex) $class = 'active';
        }
        echo '<div class="timeline-step ' . $class . '">';
        echo '  <div class="timeline-icon">' . $step[0] . '</div>';
        echo '  <div class="timeline-label">' . $step[1] . '</div>';
        echo '</div>';
    }
    echo '</div>';
}

/* =====================  EXPIRY LOGIC  ===================== */
function auto_expire_ngo_inventory($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT donation_id, collected_quantity, distributed_quantity 
            FROM food_donations 
            WHERE collected_at IS NOT NULL 
              AND collected_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)
              AND status IN ('picked_up', 'distributing', 'delivered')
        ");
        $expired = $stmt->fetchAll();
        foreach ($expired as $row) {
            $remaining = $row['collected_quantity'] - $row['distributed_quantity'];
            if ($remaining > 0) {
                $pdo->prepare("UPDATE food_donations SET status='expired' WHERE donation_id=:d")->execute([':d' => $row['donation_id']]);
            }
        }
    } catch (Exception $e) { /* silent */ }
}

/* =====================  VERIFIED BADGE  ===================== */
function verified_badge($isVerified, $role) {
    if ($role === 'ngo' && $isVerified) {
        return '<span title="Verified NGO" style="display:inline-flex; align-items:center; justify-content:center; background:#2E7D32; color:#fff; width:18px; height:18px; border-radius:50%; font-size:11px; margin-left:6px; vertical-align:middle;">✔</span>';
    }
    return '';
}
