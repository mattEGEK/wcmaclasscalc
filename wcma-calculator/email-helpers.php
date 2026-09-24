<?php
// wcma-calculator/email-helpers.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * CID source for the WCMA logo header in email bodies: embed rather than
 * hotlink so it renders inline instead of needing "show images" or getting
 * stripped. Attaches the image to the given PHPMailer instance, so call it
 * once per message that carries the rendered body.
 */
function emailLogoSrc(PHPMailer $mail): ?string {
    $full = __DIR__ . '/assets/wcma-logo.png';
    if (!is_file($full)) return null;
    try {
        $mail->addEmbeddedImage($full, 'wcma-logo', 'wcma-logo.png', 'base64', 'image/png');
    } catch (Exception $e) {
        return null;
    }
    return 'cid:wcma-logo';
}

/**
 * Production send function for pretechNotify(): one SMTP message to $to (a list of [email, name]),
 * with the WCMA logo embedded. If the constant WCMA_MAIL_LOG is defined, the message is appended to
 * that file as a JSON line and NOTHING is sent (development / end-to-end runs).
 *
 * @param array{subject: string, html: string, text: string} $message
 */
function emailSmtpSend(array $to, array $message): bool {
    if (defined('WCMA_MAIL_LOG')) {
        return file_put_contents(WCMA_MAIL_LOG, json_encode(['to' => $to, 'subject' => $message['subject'], 'text' => $message['text']]) . "\n", FILE_APPEND) !== false;
    }
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = (SMTP_PORT === 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        foreach ($to as [$address, $name]) {
            $mail->addAddress($address, $name);
        }
        $mail->Subject = $message['subject'];
        $mail->isHTML(true);
        $mail->Body    = $message['html'];
        $mail->AltBody = $message['text'];
        emailLogoSrc($mail);
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Pre-tech email error: ' . $e->getMessage());
        return false;
    }
}
