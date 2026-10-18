<?php
// admin/users/index.php
$pageTitle = 'Manage Users';
require_once __DIR__ . '/../../includes/dashboard-header.php';

if (current_role() !== 'admin') { redirect(BASE_URL . 'index.php'); }

$role   = get('role');
$search = get('q');

$sql = "SELECT * FROM users WHERE 1=1";
$params = [];
if ($role)   { $sql .= " AND role = :r"; $params[':r'] = $role; }
if ($search) { $sql .= " AND (name LIKE :q1 OR email LIKE :q2 OR phone LIKE :q3)"; $params[':q1'] = "%$search%"; $params[':q2'] = "%$search%"; $params[':q3'] = "%$search%"; }
$sql .= " ORDER BY created_at DESC LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();
?>

<form method="get" class="filter-bar mb-3" style="grid-template-columns: 2fr 1fr auto auto">
    <input type="text" name="q" value="<?= sanitize($search) ?>" class="form-control" placeholder="🔍 Search name, email, phone...">
    <select name="role" class="form-control">
        <option value="">All Roles</option>
        <?php foreach (['donor','recipient','collector','ngo','admin'] as $r): ?>
            <option value="<?= $r ?>" <?= $role===$r?'selected':'' ?>><?= ucfirst($r) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-primary">Filter</button>
    <a href="?" class="btn btn-outline">Reset</a>
</form>

<div class="admin-table-wrapper">
    <div class="admin-table-header">
        <h3>Users (<?= count($users) ?>)</h3>
        <a href="<?= BASE_URL ?>admin/export-csv.php?type=users" class="btn btn-accent btn-sm">📥 Export CSV</a>
    </div>

    <?php if (!$users): ?>
        <div style="padding:40px;text-align:center"><p class="text-muted">No users found.</p></div>
    <?php else: ?>
        <table class="admin-table">
            <thead><tr><th>Photo</th><th>Name</th><th>Email</th><th>Role</th><th>Location</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td>
                        <?php if ($u['profile_photo']): ?>
                            <img class="thumb" src="<?= PROFILE_UPLOAD_URL . rawurlencode($u['profile_photo']) ?>" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover;">
                        <?php else: ?>
                            <div style="width:40px;height:40px;border-radius:50%;background:var(--green);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;"><?= strtoupper(substr($u['name'], 0, 1)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><strong><?= sanitize($u['name']) ?></strong></td>
                    <td><?= sanitize($u['email']) ?></td>
                    <td><span class="badge badge-blue"><?= sanitize(ucfirst($u['role'])) ?></span></td>
                    <td><?= sanitize($u['area'] ?: $u['city'] ?: '—') ?></td>
                    <td><?= status_badge($u['status']) ?></td>
                    <td><a href="view.php?id=<?= (int)$u['user_id'] ?>" class="btn btn-outline btn-sm">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/dashboard-footer.php'; ?>
