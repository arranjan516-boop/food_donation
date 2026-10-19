<?php
// admin/settings/index.php
$pageTitle = 'System Settings';
require_once __DIR__ . '/../../includes/dashboard-header.php';

if (current_role() !== 'admin') { redirect(BASE_URL . 'index.php'); }

$counts = $pdo->query("
    SELECT
      (SELECT COUNT(*) FROM users) AS u,
      (SELECT COUNT(*) FROM food_donations) AS d,
      (SELECT COUNT(*) FROM food_requests) AS r,
      (SELECT COUNT(*) FROM collector_tasks) AS ct,
      (SELECT COUNT(*) FROM ngo_requests) AS nr,
      (SELECT COUNT(*) FROM delivery_proofs) AS dp
")->fetch();
?>

<div class="settings-container">
    
    <!-- Sidebar Navigation -->
    <div class="settings-nav">
        <a href="#" class="active" onclick="showTab('general', this); return false;">⚙️ General Settings</a>
        <a href="#" onclick="showTab('database', this); return false;">🗄️ Database Overview</a>
        <a href="#" onclick="showTab('logs', this); return false;">📋 Activity Logs</a>
        <a href="#" onclick="showTab('security', this); return false;">🛡️ Security Audit</a>
    </div>

    <!-- Content Area -->
    <div class="settings-content">
        
        <!-- TAB: General -->
        <div id="tab-general" class="settings-tab">
            <h2 class="settings-section-title">Application Settings</h2>
            
            <div class="toggle-row">
                <div class="toggle-info">
                    <h4>Maintenance Mode</h4>
                    <p>When enabled, only admins can access the site.</p>
                </div>
                <label class="switch">
                    <input type="checkbox" onchange="alert('Setting saved (demo only)')">
                    <span class="slider"></span>
                </label>
            </div>

            <div class="toggle-row">
                <div class="toggle-info">
                    <h4>Allow New Registrations</h4>
                    <p>If disabled, new users cannot sign up.</p>
                </div>
                <label class="switch">
                    <input type="checkbox" checked onchange="alert('Setting saved (demo only)')">
                    <span class="slider"></span>
                </label>
            </div>

            <div class="toggle-row">
                <div class="toggle-info">
                    <h4>Email Notifications</h4>
                    <p>Send email alerts for new donations (requires SMTP setup).</p>
                </div>
                <label class="switch">
                    <input type="checkbox" onchange="alert('Setting saved (demo only)')">
                    <span class="slider"></span>
                </label>
            </div>

            <div style="margin-top:30px; display:grid; grid-template-columns:1fr 1fr; gap:20px;">
                <div class="form-group">
                    <label class="form-label">Application Name</label>
                    <input type="text" class="form-control" value="FoodShare" disabled style="background:#F9FAFB;">
                </div>
                <div class="form-group">
                    <label class="form-label">Match Radius (KM)</label>
                    <input type="number" class="form-control" value="15" disabled style="background:#F9FAFB;">
                </div>
            </div>
        </div>

        <!-- TAB: Database Info -->
        <div id="tab-database" class="settings-tab" style="display:none;">
            <h2 class="settings-section-title">Database Overview</h2>
            <p style="color:var(--gray-500); font-size:14px; margin-bottom:24px;">Live row counts from the <code>food_waste_donation</code> database.</p>
            
            <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:20px;">
                <div class="setting-card">
                    <div class="label">Total Users</div>
                    <div class="value"><?= number_format($counts['u']) ?></div>
                </div>
                <div class="setting-card">
                    <div class="label">Total Donations</div>
                    <div class="value"><?= number_format($counts['d']) ?></div>
                </div>
                <div class="setting-card">
                    <div class="label">Food Requests</div>
                    <div class="value"><?= number_format($counts['r']) ?></div>
                </div>
                <div class="setting-card">
                    <div class="label">Collector Tasks</div>
                    <div class="value"><?= number_format($counts['ct']) ?></div>
                </div>
                <div class="setting-card">
                    <div class="label">NGO Requests</div>
                    <div class="value"><?= number_format($counts['nr']) ?></div>
                </div>
                <div class="setting-card">
                    <div class="label">Delivery Proofs</div>
                    <div class="value"><?= number_format($counts['dp']) ?></div>
                </div>
            </div>
        </div>

        <!-- TAB: Activity Logs -->
        <div id="tab-logs" class="settings-tab" style="display:none;">
            <h2 class="settings-section-title">Recent Activity</h2>
            <p style="color:var(--gray-500); font-size:14px; margin-bottom:24px;">Latest status changes in the system.</p>
            
            <?php 
            $logs = $pdo->query("
                SELECT dh.*, d.food_name, u.name AS user_name
                FROM donation_history dh
                JOIN food_donations d ON d.donation_id = dh.donation_id
                LEFT JOIN users u ON u.user_id = dh.changed_by
                ORDER BY dh.created_at DESC LIMIT 10
            ")->fetchAll();
            ?>
            
            <?php if (!$logs): ?>
                <p class="text-muted">No activity recorded yet.</p>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:12px;">
                    <?php foreach ($logs as $l): ?>
                        <div style="padding:16px; background:#fff; border:1px solid var(--gray-100); border-radius:8px; display:flex; justify-content:space-between; align-items:center; box-shadow: 0 2px 4px rgba(0,0,0,.02);">
                            <div>
                                <strong><?= sanitize($l['user_name'] ?: 'System') ?></strong> changed 
                                <strong><?= sanitize($l['food_name']) ?></strong> 
                                from <span class="badge badge-gray"><?= sanitize($l['old_status'] ?: 'new') ?></span> 
                                to <span class="badge badge-blue"><?= sanitize($l['new_status']) ?></span>
                            </div>
                            <small class="text-muted" style="font-size:12px;"><?= time_ago($l['created_at']) ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB: Security -->
        <div id="tab-security" class="settings-tab" style="display:none;">
            <h2 class="settings-section-title">Security Audit</h2>
            <p style="color:var(--gray-500); font-size:14px; margin-bottom:24px;">System security settings and best practices.</p>

            <div style="display:flex; flex-direction:column; gap:16px;">
                <div style="padding:20px; background:#F0FDF4; border:1px solid #BBF7D0; border-radius:8px; display:flex; gap:16px;">
                    <div style="font-size:24px;">✅</div>
                    <div>
                        <h4 style="color:#166534; margin-bottom:4px; font-size:16px;">Password Hashing</h4>
                        <p style="font-size:14px; color:#15803D; margin:0;">All passwords use bcrypt (PASSWORD_DEFAULT). Plain-text passwords are never stored.</p>
                    </div>
                </div>
                <div style="padding:20px; background:#F0FDF4; border:1px solid #BBF7D0; border-radius:8px; display:flex; gap:16px;">
                    <div style="font-size:24px;">✅</div>
                    <div>
                        <h4 style="color:#166534; margin-bottom:4px; font-size:16px;">CSRF Protection</h4>
                        <p style="font-size:14px; color:#15803D; margin:0;">All POST forms use CSRF tokens. Cross-site request forgery is blocked.</p>
                    </div>
                </div>
                <div style="padding:20px; background:#F0FDF4; border:1px solid #BBF7D0; border-radius:8px; display:flex; gap:16px;">
                    <div style="font-size:24px;">✅</div>
                    <div>
                        <h4 style="color:#166534; margin-bottom:4px; font-size:16px;">SQL Injection Prevention</h4>
                        <p style="font-size:14px; color:#15803D; margin:0;">PDO prepared statements are used everywhere. User input is never concatenated into SQL.</p>
                    </div>
                </div>
                <div style="padding:20px; background:#F0FDF4; border:1px solid #BBF7D0; border-radius:8px; display:flex; gap:16px;">
                    <div style="font-size:24px;">✅</div>
                    <div>
                        <h4 style="color:#166534; margin-bottom:4px; font-size:16px;">Secure File Uploads</h4>
                        <p style="font-size:14px; color:#15803D; margin:0;">MIME type validated, files renamed randomly, size limit enforced.</p>
                    </div>
                </div>
                <div style="padding:20px; background:#FEF3C7; border:1px solid #FDE68A; border-radius:8px; display:flex; gap:16px; margin-top:10px;">
                    <div style="font-size:24px;">⚠️</div>
                    <div>
                        <h4 style="color:#92400E; margin-bottom:4px; font-size:16px;">Change Default Admin Password</h4>
                        <p style="font-size:14px; color:#92400E; margin:0;">If you're still using <code>password123</code>, update it now via phpMyAdmin.</p>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
function showTab(tabId, link) {
    document.querySelectorAll('.settings-tab').forEach(t => t.style.display = 'none');
    document.getElementById('tab-' + tabId).style.display = 'block';
    document.querySelectorAll('.settings-nav a').forEach(a => a.classList.remove('active'));
    link.classList.add('active');
}
</script>

<?php require_once __DIR__ . '/../../includes/dashboard-footer.php'; ?>
