<?php
// wcma-calculator/pretech-email.php
//
// Emails for the photo pre-tech workflow: pure renderers (branded like the tech sheet emails,
// logo via cid:wcma-logo, photos are never embedded) and a notifier with an injectable send
// function. Callers must have loaded db.php first.
require_once __DIR__ . '/view_helpers.php';        // h()
require_once __DIR__ . '/photo-requirements.php';  // photoRequirementByKey()
require_once __DIR__ . '/tech-sheet-data.php';     // TECH_ACCEPTANCE_DISCLAIMER

function pretechEmailCarLine(array $sheet, array $event): string {
    $when = !empty($event['event_date']) ? ', ' . date('F j, Y', strtotime($event['event_date'])) : '';
    return 'Car #' . $sheet['car_number'] . ' — ' . trim($sheet['car_make'] . ' ' . $sheet['car_model'])
        . ' (' . $sheet['entrant_name'] . ') — ' . ($event['name'] ?? '') . $when;
}

/** Branded HTML shell: logo, title, then already-escaped body HTML. */
function pretechEmailWrap(string $title, string $bodyHtml): string {
    return '<html><body><div style="font-family:Arial,sans-serif;color:#222;max-width:800px">'
        . '<div style="text-align:center;margin-bottom:0.5rem"><img src="cid:wcma-logo" alt="WCMA Logo" style="max-height:70px"></div>'
        . '<h1 style="text-align:center;margin-bottom:0.2rem">' . h($title) . '</h1>'
        . $bodyHtml
        . '</div></body></html>';
}

function pretechEmailPara(string $text): string {
    return '<p>' . h($text) . '</p>';
}

function pretechEmailLink(string $url, string $label): string {
    return '<p><a href="' . h($url) . '">' . h($label) . '</a></p>';
}

/** @return array{subject: string, html: string, text: string} */
function pretechEmailSubmitted(array $sheet, array $event, string $adminUrl, string $pageUrl, int $photoCount, bool $forClub): array {
    $car = pretechEmailCarLine($sheet, $event);
    $count = $photoCount . ' ' . ($photoCount === 1 ? 'photo' : 'photos');

    if ($forClub) {
        $subject = 'WCMA Pre-Tech Submitted — Car #' . $sheet['car_number'] . ' — ' . ($event['name'] ?? '');
        $lines = ['A pre-tech photo set is waiting for review.', $car, 'Photos submitted: ' . $count . '.', 'Review it here:', $adminUrl];
        $html = pretechEmailPara($lines[0]) . pretechEmailPara($car) . pretechEmailPara($lines[2]) . pretechEmailLink($adminUrl, 'Open the review page');
        $title = 'PRE-TECH SUBMITTED';
    } else {
        $subject = 'Your WCMA pre-tech submission — ' . ($event['name'] ?? '');
        $lines = [
            'We received your photos (' . $count . ') for ' . $car . '.',
            'An inspector will review them. You will get an email when they are accepted or when a photo needs to be retaken.',
            'You can still be teched in person at the track if you prefer.',
            'You can check your submission here:', $pageUrl,
        ];
        $html = pretechEmailPara($lines[0]) . pretechEmailPara($lines[1]) . pretechEmailPara($lines[2]) . pretechEmailLink($pageUrl, 'View your pre-tech photos');
        $title = 'PRE-TECH RECEIVED';
    }
    return ['subject' => $subject, 'html' => pretechEmailWrap($title, $html), 'text' => implode("\n\n", $lines) . "\n"];
}

/**
 * @param array<int, array{label: string, note: string}> $retakes
 * @return array{subject: string, html: string, text: string}
 */
function pretechEmailSentBack(array $sheet, array $event, array $retakes, string $pageUrl): array {
    $car = pretechEmailCarLine($sheet, $event);
    $intro = 'An inspector reviewed your pre-tech photos for ' . $car . ' and needs the following photos retaken:';

    $html = pretechEmailPara($intro) . '<ul>';
    $text = $intro . "\n\n";
    foreach ($retakes as $r) {
        $html .= '<li><strong>' . h($r['label']) . '</strong>: ' . h($r['note']) . '</li>';
        $text .= '- ' . $r['label'] . ': ' . $r['note'] . "\n";
    }
    $html .= '</ul>' . pretechEmailPara('Retake them and submit again. The photos that were not flagged do not need to be redone.')
        . pretechEmailLink($pageUrl, 'Open your pre-tech page');
    $text .= "\nRetake them and submit again. The photos that were not flagged do not need to be redone.\n" . $pageUrl . "\n";

    return [
        'subject' => 'WCMA Pre-Tech — changes needed — Car #' . $sheet['car_number'],
        'html' => pretechEmailWrap('PRE-TECH: CHANGES NEEDED', $html),
        'text' => $text,
    ];
}

/** @return array{subject: string, html: string, text: string} */
function pretechEmailAccepted(array $sheet, array $event, string $viewUrl, string $adminUrl, bool $forClub): array {
    $car = pretechEmailCarLine($sheet, $event);
    $season = (int)($sheet['season'] ?? date('Y'));
    $accepted = 'The pre-tech photos for ' . $car . ' were reviewed and accepted. This car is pre-teched for ' . $season . '.';
    if ($forClub) {
        $note = 'No in-person inspection is needed; the competitor will collect their decals at the event.';
        $lines = [$accepted . ' ' . $note, TECH_ACCEPTANCE_DISCLAIMER, 'Open the review page:', $adminUrl];
        $link = pretechEmailLink($adminUrl, 'Open the review page');
        $first = pretechEmailPara($accepted . ' ' . $note);
    } else {
        $note = 'You do not need to be inspected at the track: just collect your decals at the event.';
        $lines = [$accepted, $note, TECH_ACCEPTANCE_DISCLAIMER, 'Your tech sheet:', $viewUrl];
        $link = pretechEmailLink($viewUrl, 'View your tech sheet');
        $first = pretechEmailPara($accepted) . pretechEmailPara($note);
    }
    $html = $first
        . '<p style="font-size:0.85rem;color:#555">' . h(TECH_ACCEPTANCE_DISCLAIMER) . '</p>'
        . $link;

    return [
        'subject' => 'WCMA Pre-Tech Accepted — Car #' . $sheet['car_number'] . ' — ' . ($event['name'] ?? ''),
        'html' => pretechEmailWrap('PRE-TECH ACCEPTED', $html),
        'text' => implode("\n\n", $lines) . "\n",
    ];
}

/**
 * Sends the emails for one workflow step. Failures never propagate: the workflow step has
 * already happened, so this only reports whether every message was sent.
 *
 * @param string $kind 'submitted' | 'sent_back' | 'accepted'
 * @param array{email: string, name: string} $club recipient for club copies
 * @param callable $sendFn function(array $to, array $message): bool; $to is a list of [email, name]
 * @param array<string,string> $retakes requirement key => note (sent_back only)
 */
function pretechNotify(PDO $pdo, string $kind, array $sheet, array $event, string $baseUrl, array $club, callable $sendFn, array $retakes = []): bool {
    try {
        $base = rtrim($baseUrl, '/');
        $id = (int)$sheet['id'];
        $pageUrl = $base . '/tech-sheets.php?action=pretech&id=' . $id;
        $viewUrl = $base . '/tech-sheets.php?action=view&id=' . $id;
        $adminUrl = $base . '/admin.php?action=tech-sheet&id=' . $id;

        $owner = db_find_user_by_id($pdo, (int)$sheet['user_id']);
        $competitor = $owner ? [[$owner['email'], $owner['name']]] : [];
        $clubTo = [[$club['email'], $club['name']]];

        $messages = [];   // list of [to, message]
        switch ($kind) {
            case 'submitted':
                $count = count(array_filter(db_get_inspection_photos($pdo, 'tech_sheet', $id), fn(array $p): bool => $p['file_path'] !== ''));
                $messages[] = [$clubTo, pretechEmailSubmitted($sheet, $event, $adminUrl, $pageUrl, $count, true)];
                if ($competitor) $messages[] = [$competitor, pretechEmailSubmitted($sheet, $event, $adminUrl, $pageUrl, $count, false)];
                break;
            case 'sent_back':
                $list = [];
                foreach ($retakes as $key => $note) {
                    $req = photoRequirementByKey((string)$key);
                    $list[] = ['label' => $req['label'] ?? (string)$key, 'note' => (string)$note];
                }
                if ($competitor) $messages[] = [$competitor, pretechEmailSentBack($sheet, $event, $list, $pageUrl)];
                break;
            case 'accepted':
                if ($competitor) $messages[] = [$competitor, pretechEmailAccepted($sheet, $event, $viewUrl, $adminUrl, false)];
                $messages[] = [$clubTo, pretechEmailAccepted($sheet, $event, $viewUrl, $adminUrl, true)];
                break;
            default:
                return false;
        }

        $allSent = true;
        foreach ($messages as [$to, $message]) {
            try {
                if (!$sendFn($to, $message)) $allSent = false;
            } catch (Throwable $e) {
                error_log('Pre-tech notification send error: ' . $e->getMessage());
                $allSent = false;
            }
        }
        return $allSent;
    } catch (Throwable $e) {
        error_log('Pre-tech notification error: ' . $e->getMessage());
        return false;
    }
}
