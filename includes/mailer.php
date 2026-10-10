<?php
// PHPMailer Integration Wrapper
require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Send an email to the specified recipient.
 * Returns false when SMTP is not configured, developer mode is enabled,
 * or the SMTP delivery attempt fails.
 *
 * @param string $to Recipient email address
 * @param string $subject Email subject
 * @param string $body Text/HTML body of the email
 * @return bool True if successful, false otherwise
 */
function sendEmailNotification($to, $subject, $body) {
    return sendEmailNotificationWithAttachment($to, $subject, $body);
}

function sendEmailNotificationWithAttachment($to, $subject, $body, ?string $attachmentPath = null, ?string $attachmentName = null): bool {
    $config = require __DIR__ . '/../config/mail.php';

    if (!empty($config['developer_mode'])) {
        error_log('Email delivery skipped because mailer developer mode is enabled.');
        return false;
    }

    if (empty($config['smtp_user']) || empty($config['smtp_pass']) || empty($to) || empty($config['smtp_host'])) {
        error_log('Mailer configuration is incomplete.');
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $config['smtp_host'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $config['smtp_user'];
        $mail->Password = $config['smtp_pass'];
        $mail->Port = (int)($config['smtp_port'] ?? 587);
        $mail->Timeout = 20;
        $mail->SMTPDebug = 0;

        $secureType = strtolower((string)($config['smtp_secure'] ?? 'tls'));
        if ($secureType === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom($config['from_email'] ?? $config['smtp_user'], $config['from_name'] ?? 'Document Tracking System');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = nl2br($body);
        $mail->AltBody = strip_tags($body);
        if ($attachmentPath !== null && is_file($attachmentPath)) {
            $mail->addAttachment($attachmentPath, $attachmentName ?: basename($attachmentPath));
        }

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('PHPMailer error: ' . $e->getMessage());
        return false;
    }
}
