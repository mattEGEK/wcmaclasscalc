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
