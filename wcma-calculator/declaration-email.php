<?php
// wcma-calculator/declaration-email.php
//
// Emails to the competitor after an inspector reviews a class declaration (spec §5, "Competitor
// emails"): pure renderers, branded like the pre-tech emails (logo via cid:wcma-logo), and a notifier
// with an injectable send function. Callers must have loaded db.php.
require_once __DIR__ . '/pretech-email.php';   // pretechEmailWrap/Para/Link, h()
require_once __DIR__ . '/email-copy.php';

function declarationEmailCarLine(array $sub, array $car): string {
    return 'Car #' . $car['car_number'] . ' — ' . trim($sub['year'] . ' ' . $sub['make'] . ' ' . $sub['model'])
        . ' (' . ($sub['calculated_class'] ?? '—') . ')';
}

/** @return array{subject: string, html: string, text: string} */
function declarationEmailAccepted(array $sub, array $car, string $garageUrl, ?array $reviewer): array {
    $what = 'Your class declaration for ' . declarationEmailCarLine($sub, $car) . ', submitted '
        . date('F j, Y', strtotime((string)$sub['submitted_at'])) . ', is accepted.';
    $byLine = reviewedByLine($reviewer);
    $lines = array_values(array_filter([COPY_DECLARATION_ACCEPTED, $byLine, $what, 'Your car:', $garageUrl]));
    $html = pretechEmailPara(COPY_DECLARATION_ACCEPTED) . pretechEmailPara($what)
        . ($byLine !== '' ? pretechEmailPara($byLine) : '')
        . pretechEmailLink($garageUrl, 'Open your car in the Garage');
    return [
        'subject' => 'WCMA Class Declaration Accepted — Car #' . $car['car_number'] . ' — ' . ($sub['calculated_class'] ?? ''),
        'html' => pretechEmailWrap('CLASS DECLARATION ACCEPTED', $html),
        'text' => implode("\n\n", $lines) . "\n",
    ];
}

/** @return array{subject: string, html: string, text: string} */
function declarationEmailSentBack(array $sub, array $car, string $note, string $redeclareUrl, ?array $reviewer): array {
    $intro = 'An inspector reviewed your class declaration for ' . declarationEmailCarLine($sub, $car)
        . ' and needs changes before it can be accepted.';
    $next = 'Re-declare the car\'s class. The calculator opens with this declaration filled in, so you only change what the note asks for.';
    $byLine = reviewedByLine($reviewer);
    $lines = array_values(array_filter([$intro, 'Inspector\'s note: ' . $note, $byLine, $next, $redeclareUrl]));
    $html = pretechEmailPara($intro)
        . '<p><strong>Inspector\'s note:</strong> ' . nl2br(h($note)) . '</p>'
        . ($byLine !== '' ? pretechEmailPara($byLine) : '')
        . pretechEmailPara($next)
        . pretechEmailLink($redeclareUrl, 'Re-declare class');
    return [
        'subject' => 'WCMA Class Declaration — changes needed — Car #' . $car['car_number'],
        'html' => pretechEmailWrap('CLASS DECLARATION: CHANGES NEEDED', $html),
        'text' => implode("\n\n", $lines) . "\n",
    ];
}

/**
 * Emails the declaration's account holder after a review. Failures never propagate: the review has
 * already been saved, so this only reports whether the message was sent.
 *
 * @param string $kind 'accepted' | 'sent_back'
 * @param callable $sendFn function(array $to, array $message): bool; $to is a list of [email, name]
 */
function declarationNotify(PDO $pdo, string $kind, array $sub, string $baseUrl, callable $sendFn): bool {
    try {
        $car = db_get_car($pdo, (int)$sub['car_id']);
        if ($car === null) return false;
        $owner = db_find_user_by_id($pdo, (int)$sub['user_id']);
        $to = $owner !== null ? [[$owner['email'], $owner['name']]] : [[$sub['email'], $sub['name']]];
        $reviewer = !empty($sub['reviewed_by_user_id']) ? db_find_user_by_id($pdo, (int)$sub['reviewed_by_user_id']) : null;
        $base = rtrim($baseUrl, '/');

        if ($kind === 'accepted') {
            $message = declarationEmailAccepted($sub, $car, $base . '/garage.php?car=' . (int)$car['id'], $reviewer);
        } elseif ($kind === 'sent_back') {
            $message = declarationEmailSentBack($sub, $car, (string)$sub['reviewer_note'], $base . '/calculator.php?car=' . (int)$car['id'], $reviewer);
        } else {
            return false;
        }
        return (bool)$sendFn($to, $message);
    } catch (Throwable $e) {
        error_log('Declaration notification error: ' . $e->getMessage());
        return false;
    }
}
