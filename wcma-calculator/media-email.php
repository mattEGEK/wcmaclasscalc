<?php
// wcma-calculator/media-email.php
//
// Emails to the account that manages a driver profile when Media staff send its public page back or
// hide it. Pure renderers plus a notifier with an injectable send function. Callers must have loaded
// db.php and pretech-email.php (pretechEmailWrap/Para/Link).

/** @return array{subject: string, html: string, text: string} */
function mediaEmailSentBack(array $driver, string $note, string $pageUrl): array {
    $name = (string)$driver['name'];
    $lines = [
        'WCMA media staff reviewed the public driver page for ' . $name . ' and sent it back with this note:',
        $note,
        'Update the profile and save it to send it for review again. It is still used for announcing and club promotion in the meantime.',
    ];
    $html = pretechEmailPara($lines[0]) . pretechEmailPara($note) . pretechEmailPara($lines[2])
        . pretechEmailLink($pageUrl, 'Open the media profile');
    return ['subject' => 'Your WCMA public driver page was sent back — ' . $name,
            'html' => pretechEmailWrap('PUBLIC PAGE: CHANGES NEEDED', $html),
            'text' => implode("\n\n", $lines) . "\n\n" . $pageUrl . "\n"];
}

/** @return array{subject: string, html: string, text: string} */
function mediaEmailHidden(array $driver, string $reason, string $pageUrl): array {
    $name = (string)$driver['name'];
    $lines = [
        'WCMA media staff hid the driver profile for ' . $name . '. Reason:',
        $reason,
        'While hidden it is not used for announcing, club promotion or the public page. Reply to this email if you have questions.',
    ];
    $html = pretechEmailPara($lines[0]) . pretechEmailPara($reason) . pretechEmailPara($lines[2])
        . pretechEmailLink($pageUrl, 'Open the media profile');
    return ['subject' => 'Your WCMA driver profile was hidden — ' . $name,
            'html' => pretechEmailWrap('DRIVER PROFILE HIDDEN', $html),
            'text' => implode("\n\n", $lines) . "\n\n" . $pageUrl . "\n"];
}

function mediaNotifyOwner(PDO $pdo, string $kind, int $driverId, string $note, string $baseUrl, callable $send): bool {
    $driver = db_get_driver($pdo, $driverId);
    $owner = $driver !== null ? db_find_user_by_id($pdo, (int)$driver['owner_user_id']) : null;
    if ($owner === null) return false;
    $page = rtrim($baseUrl, '/') . '/media-profile.php?driver_id=' . $driverId;
    $message = $kind === 'hidden' ? mediaEmailHidden($driver, $note, $page) : mediaEmailSentBack($driver, $note, $page);
    try {
        return (bool)$send([[(string)$owner['email'], (string)$owner['name']]], $message);
    } catch (Throwable $e) {
        error_log('media email failed: ' . $e->getMessage());
        return false;
    }
}
