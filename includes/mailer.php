<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// We point to the 'src' folder we pasted in Step 1
require_once __DIR__ . '/../src/Exception.php';
require_once __DIR__ . '/../src/PHPMailer.php';
require_once __DIR__ . '/../src/SMTP.php';

function send_email_notification($toEmail, $subject, $body) {
    // --- CHANGE THESE TWO LINES ---
    $smtpUser = 'arranjan5714@gmail.com';      // 1. Your real Gmail address
    $smtpPass = 'Fu.ryZa@5714!!';       // 2. Paste the 16-letter App Password from Step 2
    // ------------------------------

    $mail = new PHPMailer(true);
    try {
        // Tell PHPMailer to use Gmail's servers securely
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Who is sending it
        $mail->setFrom($smtpUser, 'FoodShare Alerts');
        
        // Who gets it
        $mail->addAddress($toEmail);

        // The content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}
