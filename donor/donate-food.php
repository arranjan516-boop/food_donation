<?php
// donor/donate-food.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/location-functions.php';
require_once __DIR__ . '/../includes/notification-functions.php';
require_once __DIR__ . '/../includes/location-picker.php';

require_login();
if (current_role() !== 'donor' && current_role() !== 'admin') {
    set_flash('error', 'Access denied.');
    redirect(BASE_URL . 'index.php');
}

$uid = current_user_id();

$userRow = $pdo->prepare("SELECT * FROM users WHERE user_id = :u");
$userRow->execute([':u' => $uid]);
$user = $userRow->fetch();

$errors = [];
$old = [
    'food_name' => '', 'food_category' => '', 'description' => '',
    'quantity' => '', 'unit' => 'kg', 'food_type' => 'Vegetarian',
    'people_served' => '',
    'preparation_time' => date('Y-m-d\TH:i'),
    'best_before' => date('Y-m-d\TH:i', strtotime('+6 hours')),
    'urgency' => 'normal',
    'address' => $user['address'] ?? '',
    'city' => $user['city'] ?? '',
    'area' => $user['area'] ?? '',
    'pincode' => $user['pincode'] ?? '',
    'latitude' => $user['latitude'] ?? '',
    'longitude' => $user['longitude'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    foreach ($old as $k => $_) {
        if (isset($_POST[$k])) $old[$k] = post($k);
    }

    if ($old['food_name'] === '')  $errors[] = 'Food name is required.';
    if ($old['quantity'] === '' || (float)$old['quantity'] <= 0) $errors[] = 'Valid quantity is required.';
    if ($old['people_served'] === '' || (int)$old['people_served'] <= 0) $errors[] = 'Number of people served is required.';
    if ($old['city'] === '')       $errors[] = 'City is required for 15 KM matching.';
    if ($old['pincode'] === '')    $errors[] = 'Pincode is required.';
    if ($old['best_before'] === '')$errors[] = 'Best-before time is required.';

    $photoName = null;
    if (!empty($_FILES['food_photo']['name'])) {
        $photoName = upload_image($_FILES['food_photo'], FOOD_UPLOAD, FOOD_UPLOAD_URL);
        if (!$photoName) $errors[] = 'Food image upload failed.';
    } else {
        $errors[] = 'Food photo is required.';
    }

    if (!$errors) {
        // New Flow: Delivery is always handled by NGO
        $sql = "INSERT INTO food_donations
            (donor_id, food_name, food_category, description, quantity, unit,
             food_type, people_served, food_photo, preparation_time, best_before,
             urgency, address, city, area, pincode, latitude, longitude,
             delivery_preference, allow_partial_request, status)
            VALUES
            (:donor_id, :food_name, :food_category, :description, :quantity, :unit,
             :food_type, :people_served, :food_photo, :preparation_time, :best_before,
             :urgency, :address, :city, :area, :pincode, :latitude, :longitude,
             'ngo', 1, 'available')";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':donor_id' => $uid,
            ':food_name' => $old['food_name'],
            ':food_category' => $old['food_category'],
            ':description' => $old['description'],
            ':quantity' => (float)$old['quantity'],
            ':unit' => $old['unit'],
            ':food_type' => $old['food_type'],
            ':people_served' => (int)$old['people_served'],
            ':food_photo' => $photoName,
            ':preparation_time' => date('Y-m-d H:i:s', strtotime($old['preparation_time'])),
            ':best_before' => date('Y-m-d H:i:s', strtotime($old['best_before'])),
            ':urgency' => $old['urgency'],
            ':address' => $old['address'],
            ':city' => $old['city'],
            ':area' => $old['area'],
            ':pincode' => $old['pincode'],
            ':latitude' => $old['latitude'] !== '' ? (float)$old['latitude'] : null,
            ':longitude' => $old['longitude'] !== '' ? (float)$old['longitude'] : null,
        ]);

        $donationId = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO donation_history (donation_id, old_status, new_status, changed_by)
                       VALUES (:d, NULL, 'available', :u)")
            ->execute([':d' => $donationId, ':u' => $uid]);

        // NEW FLOW: Notify ONLY nearby verified NGOs
        if ($old['latitude'] !== '' && $old['longitude'] !== '') {
            $lat = (float)$old['latitude'];
            $lng = (float)$old['longitude'];

            $ngos = users_within_km($pdo, 'ngo', $lat, $lng);
            foreach ($ngos as $ngo) {
                if ($ngo['is_verified']) {
                    notify($pdo, $ngo['user_id'], '🏢 New Donation Available',
                        'A donor nearby posted "' . $old['food_name'] . '".', 'new_donation', $donationId);
                }
            }
        }

        notify_admins($pdo, '📦 New Donation Posted',
            current_user()['name'] . ' posted "' . $old['food_name'] . '".',
            'new_donation', $donationId);

        set_flash('success', 'Donation posted successfully! Nearby NGOs have been notified.');
        redirect(BASE_URL . 'donor/my-donations.php');
    }
}

$pageTitle = 'Donate Food';
require_once __DIR__ . '/../includes/dashboard-header.php';
?>

<?php if ($errors): ?>
    <div class="toast toast-error">
        <ul style="margin:0;padding-left:18px">
            <?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card">
    <?= csrf_field() ?>

    <h3 class="card-title">Food Details</h3>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Food Name *</label>
            <input type="text" name="food_name" class="form-control" required value="<?= sanitize($old['food_name']) ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Category</label>
            <select name="food_category" class="form-control">
                <?php foreach (['Rice','Meals','Curry','Bread','Fruits','Vegetables','Bakery','Snacks','Other'] as $c): ?>
                    <option value="<?= $c ?>" <?= $old['food_category']===$c?'selected':'' ?>><?= $c ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="form-group">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="2"><?= sanitize($old['description']) ?></textarea>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Quantity *</label>
            <input type="number" step="any" name="quantity" class="form-control" required value="<?= sanitize($old['quantity']) ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Unit</label>
            <select name="unit" class="form-control">
                <?php foreach (['kg','meals','packets','litres','pieces','boxes'] as $u): ?>
                    <option value="<?= $u ?>" <?= $old['unit']===$u?'selected':'' ?>><?= $u ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Food Type</label>
            <select name="food_type" class="form-control">
                <?php foreach (['Vegetarian','Non-Vegetarian','Vegan'] as $t): ?>
                    <option value="<?= $t ?>" <?= $old['food_type']===$t?'selected':'' ?>><?= $t ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Number of People Served *</label>
            <input type="number" name="people_served" class="form-control" required value="<?= sanitize($old['people_served']) ?>">
        </div>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Preparation Date &amp; Time</label>
            <input type="datetime-local" name="preparation_time" class="form-control" value="<?= sanitize($old['preparation_time']) ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Best Before *</label>
            <input type="datetime-local" name="best_before" class="form-control" required value="<?= sanitize($old['best_before']) ?>">
        </div>
    </div>

    <div class="form-group">
        <label class="form-label">Urgency</label>
        <select name="urgency" class="form-control">
            <option value="normal"      <?= $old['urgency']==='normal'?'selected':'' ?>>Normal</option>
            <option value="urgent"      <?= $old['urgency']==='urgent'?'selected':'' ?>>Urgent</option>
            <option value="very_urgent" <?= $old['urgency']==='very_urgent'?'selected':'' ?>>Very Urgent</option>
        </select>
    </div>

    <h3 class="card-title" style="margin-top:24px">Pickup Location</h3>

    <div class="form-group">
        <label class="form-label">Address</label>
        <textarea name="address" id="address" class="form-control" rows="2"><?= sanitize($old['address']) ?></textarea>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label">City *</label>
            <input type="text" name="city" id="city" class="form-control" required value="<?= sanitize($old['city']) ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Area</label>
            <input type="text" name="area" id="area" class="form-control" value="<?= sanitize($old['area']) ?>">
        </div>
    </div>

    <div class="form-group">
        <label class="form-label">Pincode *</label>
        <input type="text" name="pincode" id="pincode" class="form-control" required value="<?= sanitize($old['pincode']) ?>">
    </div>

    <?php render_location_picker($old); ?>

    <h3 class="card-title" style="margin-top:24px">Food Photo *</h3>
    <div class="form-group">
        <input type="file" name="food_photo" class="form-control" accept="image/jpeg,image/png,image/webp"
               onchange="previewImage(this, document.getElementById('food-preview'))" required>
        <img id="food-preview" style="display:none;max-width:220px;margin-top:10px;border-radius:10px">
    </div>

    <div class="toast toast-info mt-3">
        ℹ️ In this new workflow, your donation will be offered to <strong>Verified NGOs</strong> within 15 KM. They will collect the food and distribute it to recipients.
    </div>

    <div class="mt-3">
        <button class="btn btn-primary btn-lg">Post Donation</button>
        <a href="<?= BASE_URL ?>donor/dashboard.php" class="btn btn-outline">Cancel</a>
    </div>
</form>

<?php require_once __DIR__ . '/../includes/dashboard-footer.php'; ?>
