<?php
// includes/mailer.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../src/Exception.php';
require_once __DIR__ . '/../src/PHPMailer.php';
require_once __DIR__ . '/../src/SMTP.php';

function send_email_notification($toEmail, $subject, $body) {
    // --- CONFIGURE YOUR GMAIL HERE ---
    $smtpUser = 'arranjan5714@gmail.com';      // <-- Your Gmail address
    $smtpPass = 'xoglpkjtskaiuctv';          // <-- 16-char App Password (NO spaces)
    // ----------------------------------

    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 465;

        // --- TURN ON DEBUGGING ---
        // This will print the connection details to the screen. 
        // Delete these 2 lines once the email works.
        $mail->SMTPDebug  = 2; 
        $mail->Debugoutput = 'html';
        // -------------------------

        // Recipients
        $mail->setFrom($smtpUser, 'FoodShare System');
        $mail->addAddress($toEmail);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        // If it fails, print the exact error on the screen
        echo "<div style='background:#FFEBEE; color:#B71C1C; padding:20px; border:2px solid #B71C1C; margin:20px; border-radius:8px; font-family:monospace;'>";
        echo "<h3>❌ Email Failed to Send!</h3>";
        echo "<strong>Error Message:</strong> " . $mail->ErrorInfo;
        echo "</div>";
        return false;
    }
}
