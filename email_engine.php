<?php
// config/email_engine.php

// config/email_engine.php ke top par yeh lagayein:
require_once $_SERVER['DOCUMENT_ROOT'] . '/library-system/vendor/phpmailer/Exception.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/library-system/vendor/phpmailer/PHPMailer.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/library-system/vendor/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Yeh ek universal function hai jo email bhejega
function sendLibraryEmail($toEmail, $toName, $subject, $messageBody) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; // Gmail ka server
        $mail->SMTPAuth   = true;
        $mail->Username   = 'mursaleenchauhan809@gmail.com'; // Aapki real Gmail ID
        $mail->Password   = 'mpaj cqws qiuw remw';            // Aapka Gmail App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Kiske paas se email ja rahi hai (Yahan bhi apni real email daal di hai)
        $mail->setFrom('mursaleenchauhan809@gmail.com', 'University Library System');
        
        // Kisko email bhejni hai
        $mail->addAddress($toEmail, $toName);

        // Email ka content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $messageBody;

        $mail->send();
        return true; // Agar email chali gayi to true
    } catch (Exception $e) {
        return false; // Agar koi error aaya to false
    }
}
?>