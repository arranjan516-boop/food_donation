<?php
// login.php
$pageTitle = 'Login';
require_once __DIR__ . '/includes/header.php';

if (is_logged_in()) {
    redirect(dashboard_url_for_role(current_role()));
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email    = post('email');
    $password = post('password');

    if (!$email || !$password) {
        $error = 'Please enter email and password.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :e LIMIT 1");
        $stmt->execute([':e' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = 'No account found with that email.';
        } elseif ($user['status'] === 'blocked') {
            $error = 'Your account has been blocked. Contact support.';
        } elseif ($user['status'] === 'inactive') {
            $error = 'Your account is inactive. Contact support.';
        } elseif (!password_verify($password, $user['password'])) {
            $error = 'Incorrect password.';
        } else {
            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = :u")
                ->execute([':u' => $user['user_id']]);

            login_user($user);
            set_flash('success', 'Welcome back, ' . $user['name'] . '!');
            redirect(dashboard_url_for_role($user['role']));
        }
    }
}
?>

<section class="auth-section">
    <div class="auth-container auth-login">
        <div class="auth-form-wrap">
            <h1>Welcome Back!</h1>
            <p class="text-muted mb-3">Login to your account</p>

            <?php if ($error): ?>
                <div class="toast toast-error"><?= sanitize($error) ?></div>
            <?php endif; ?>

            <form method="post" data-validate>
                <?= csrf_field() ?>

                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-control" required
                           value="<?= sanitize($email) ?>" autofocus>
                </div>

                <div class="form-group">
                    <label class="form-label">Password</label>
                    <div class="password-wrap">
                        <input type="password" name="password" class="form-control" required>
                        <button type="button" class="password-toggle" onclick="togglePassword(this)" aria-label="Show password">👁</button>
                    </div>
                </div>

                <div class="flex-between mb-2">
                    <label style="font-size:13px">
                        <input type="checkbox" name="remember"> Remember me
                    </label>
                    <a href="<?= BASE_URL ?>forgot-password.php" style="font-size:13px">Forgot Password?</a>
                </div>

                <button class="btn btn-primary btn-block btn-lg">Login</button>

                <p class="text-center mt-2">
                    Don't have an account? <a href="<?= BASE_URL ?>register.php">Register</a>
                </p>
            </form>
        </div>

        <!-- Right: Premium Animated Image -->
        <div class="auth-side">
            <div class="auth-side-content">
                <p class="auth-side-tagline">Good food brings people together</p>
                <h2>Good Food Brings <br>People Together.</h2>
                
                <div class="auth-side-icons">
                    <div class="floating-icon">🍱<span>Donate</span></div>
                    <div class="floating-icon">🤝<span>Help</span></div>
                    <div class="floating-icon">🚴<span>Deliver</span></div>
                    <div class="floating-icon">🌱<span>Grow</span></div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
