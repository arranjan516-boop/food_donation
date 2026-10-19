<?php
// contact.php
$pageTitle = 'Contact Us';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/mailer.php'; // <-- ADDED HERE: Loads the mailer

$success = false;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name    = post('name');
    $email   = post('email');
    $message = post('message');

    // Validation
    if ($name === '') $errors[] = 'Please enter your name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if ($message === '') $errors[] = 'Please enter a message.';

    if (!$errors) {
        try {
            // 1. Save to Database
            $stmt = $pdo->prepare("INSERT INTO contact_messages (name, email, message) VALUES (:n, :e, :m)");
            $stmt->execute([':n' => $name, ':e' => $email, ':m' => $message]);
            
            // 2. Send Email (ADDED HERE)
            $adminEmail = 'your_email@gmail.com'; // <-- PUT YOUR EMAIL HERE
            $subject = "New Contact Message from " . $name;
            $body = "<h3>New Message from FoodShare</h3>
                     <p><strong>Name:</strong> $name</p>
                     <p><strong>Email:</strong> $email</p>
                     <p><strong>Message:</strong><br>$message</p>";
            
            // This calls the function from mailer.php
            send_email_notification($adminEmail, $subject, $body);
            // ------------------

            set_flash('success', 'Thank you! Your message has been sent. We will check our email and reply soon.');
            redirect(BASE_URL . 'contact.php');
        } catch (Exception $ex) {
            $errors[] = 'Something went wrong. Please try again later.';
        }
    }
}
?>

<section class="section">
    <div class="container" style="max-width:1000px">
        <h1 class="section-title">Contact Us</h1>
        <p class="section-sub">Questions, suggestions or partnership ideas? We'd love to hear from you.</p>

        <div class="contact-grid">
            <!-- Left Side: Contact Info Cards -->
            <div class="contact-info">
                <div class="contact-card">
                    <div class="helper-icon">📍</div>
                    <h3>Visit Us</h3>
                    <p>Tumkur, Karnataka, India</p>
                </div>
                <div class="contact-card">
                    <div class="helper-icon">📧</div>
                    <h3>Email Us</h3>
                    <p>arranjan5714@gmail.com</p>
                </div>
                <div class="contact-card">
                    <div class="helper-icon">📞</div>
                    <h3>Call Us</h3>
                    <p>+91 98765 43210</p>
                </div>
            </div>

            <!-- Right Side: Form -->
            <div class="card" style="padding: 34px 38px;">
                <h3 class="card-title" style="margin-bottom:20px;">Send a Message</h3>

                <?php if ($errors): ?>
                    <div class="toast toast-error mb-2">
                        <ul style="margin:0; padding-left:18px;">
                            <?php foreach ($errors as $err): ?>
                                <li><?= sanitize($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <?= csrf_field() ?>

                    <div class="form-group">
                        <label class="form-label">Your Name *</label>
                        <input type="text" name="name" class="form-control" required value="<?= isset($_POST['name']) ? sanitize($_POST['name']) : '' ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Your Email *</label>
                        <input type="email" name="email" class="form-control" required value="<?= isset($_POST['email']) ? sanitize($_POST['email']) : '' ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Message *</label>
                        <textarea name="message" class="form-control" rows="5" required><?= isset($_POST['message']) ? sanitize($_POST['message']) : '' ?></textarea>
                    </div>

                    <button class="btn btn-primary btn-block btn-lg">Send Message</button>
                </form>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
