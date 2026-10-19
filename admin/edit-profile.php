<?php
// admin/edit-profile.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/location-picker.php';

require_login();
if (current_role() !== 'admin') { redirect(BASE_URL . 'index.php'); }

$uid = current_user_id();
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = :u");
$stmt->execute([':u' => $uid]);
$u = $stmt->fetch();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name    = post('name');
    $phone   = post('phone');
    $address = post('address');
    $city    = post('city');
    $area    = post('area');
    $pincode = post('pincode');
    $lat     = post('latitude');
    $lng     = post('longitude');

    if ($name === '') $errors[] = 'Name is required.';
    if ($city === '') $errors[] = 'City is required.';

    $photoName = $u['profile_photo'];
    if (!empty($_FILES['profile_photo']['name'])) {
        $newPhoto = upload_image($_FILES['profile_photo'], PROFILE_UPLOAD, PROFILE_UPLOAD_URL);
        if ($newPhoto) $photoName = $newPhoto;
    }

    if (!$errors) {
        $pdo->prepare("UPDATE users SET name=:n, phone=:p, address=:a, city=:c, area=:ar,
                        pincode=:pin, latitude=:lat, longitude=:lng, profile_photo=:ph WHERE user_id=:u")
            ->execute([
                ':n' => $name, ':p' => $phone, ':a' => $address,
                ':c' => $city, ':ar' => $area, ':pin' => $pincode,
                ':lat' => $lat !== '' ? (float)$lat : null,
                ':lng' => $lng !== '' ? (float)$lng : null,
                ':ph' => $photoName, ':u' => $uid,
            ]);
        $_SESSION['name'] = $name;
        set_flash('success', 'Profile updated successfully.');
        redirect(BASE_URL . 'admin/profile.php');
    }
}

$pageTitle = 'Edit Profile';
require_once __DIR__ . '/../includes/dashboard-header.php';
?>

<div class="settings-content" style="max-width:800px; margin: 0 auto;">
    <h2 class="settings-section-title">Edit Admin Profile</h2>

    <?php if ($errors): ?>
        <div class="toast toast-error"><?php foreach ($errors as $e): ?><?= sanitize($e) ?><br><?php endforeach; ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Full Name *</label>
                <input type="text" name="name" class="form-control" required value="<?= sanitize($u['name']) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control" value="<?= sanitize($u['phone']) ?>">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Address</label>
            <textarea name="address" id="address" class="form-control" rows="2"><?= sanitize($u['address']) ?></textarea>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">City *</label>
                <input type="text" name="city" id="city" class="form-control" required value="<?= sanitize($u['city']) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Area</label>
                <input type="text" name="area" id="area" class="form-control" value="<?= sanitize($u['area']) ?>">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Pincode</label>
                <input type="text" name="pincode" id="pincode" class="form-control" value="<?= sanitize($u['pincode']) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Profile Photo</label>
                <input type="file" name="profile_photo" class="form-control" accept="image/jpeg,image/png,image/webp">
            </div>
        </div>

        <?php render_location_picker([
            'city'      => $u['city'],
            'area'      => $u['area'],
            'pincode'   => $u['pincode'],
            'latitude'  => $u['latitude'],
            'longitude' => $u['longitude'],
        ]); ?>

        <div style="margin-top:30px; padding-top:20px; border-top:1px solid var(--gray-100);">
            <button class="btn btn-primary btn-lg">💾 Save Changes</button>
            <a href="<?= BASE_URL ?>admin/profile.php" class="btn btn-outline btn-lg">Cancel</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
