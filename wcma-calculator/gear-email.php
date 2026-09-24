<?php
// wcma-calculator/gear-email.php
//
// Emails for the driver gear pre-tech workflow: pure renderers (branded like the car pre-tech
// emails, logo via cid:wcma-logo, photos never embedded) and a notifier with an injectable send
// function. Callers must have loaded db.php first.
require_once __DIR__ . '/pretech-email.php';   // pretechEmailWrap/Para/Link, view_helpers, photo-requirements, disclaimer

function gearEmailDriverLine(array $gear): string {
    return $gear['driver_name'] . ' — ' . (int)($gear['season'] ?? date('Y'));
}

/** @return array{subject: string, html: string, text: string} */
function gearEmailSubmitted(array $gear, string $adminUrl, string $pageUrl, int $photoCount, bool $forClub): array {
    $driver = gearEmailDriverLine($gear);
    $count = $photoCount . ' ' . ($photoCount === 1 ? 'photo' : 'photos');

    if ($forClub) {
        $subject = 'WCMA Gear Pre-Tech Submitted — ' . $driver;
        $lines = ['A gear pre-tech photo set is waiting for review.', $driver, 'Photos submitted: ' . $count . '.', 'Review it here:', $adminUrl];
        $html = pretechEmailPara($lines[0]) . pretechEmailPara($driver) . pretechEmailPara($lines[2]) . pretechEmailLink($adminUrl, 'Open the review page');
        $title = 'GEAR PRE-TECH SUBMITTED';
    } else {
        $subject = 'Your WCMA gear pre-tech submission — ' . $driver;
        $lines = [
            'We received your photos (' . $count . ') for ' . $driver . '.',
            'An inspector will review them. You will get an email when they are accepted or when a photo needs to be retaken.',
            'Your gear can still be checked in person at the track if you prefer.',
            'You can check your submission here:', $pageUrl,
        ];
        $html = pretechEmailPara($lines[0]) . pretechEmailPara($lines[1]) . pretechEmailPara($lines[2]) . pretechEmailLink($pageUrl, 'View your gear photos');
        $title = 'GEAR PRE-TECH RECEIVED';
    }
    return ['subject' => $subject, 'html' => pretechEmailWrap($title, $html), 'text' => implode("\n\n", $lines) . "\n"];
}

/**
 * @param array<int, array{label: string, note: string}> $retakes
 * @return array{subject: string, html: string, text: string}
 */
function gearEmailSentBack(array $gear, array $retakes, string $pageUrl): array {
    $driver = gearEmailDriverLine($gear);
    $intro = 'An inspector reviewed the gear pre-tech photos for ' . $driver . ' and needs the following photos retaken:';

    $html = pretechEmailPara($intro) . '<ul>';
    $text = $intro . "\n\n";
    foreach ($retakes as $r) {
        $html .= '<li><strong>' . h($r['label']) . '</strong>: ' . h($r['note']) . '</li>';
        $text .= '- ' . $r['label'] . ': ' . $r['note'] . "\n";
    }
    $html .= '</ul>' . pretechEmailPara('Retake them and submit again. The photos that were not flagged do not need to be redone.')
        . pretechEmailLink($pageUrl, 'Open your gear page');
    $text .= "\nRetake them and submit again. The photos that were not flagged do not need to be redone.\n" . $pageUrl . "\n";

    return [
        'subject' => 'WCMA Gear Pre-Tech — changes needed — ' . $driver,
        'html' => pretechEmailWrap('GEAR PRE-TECH: CHANGES NEEDED', $html),
        'text' => $text,
    ];
}

/** @return array{subject: string, html: string, text: string} */
function gearEmailAccepted(array $gear, string $pageUrl, string $adminUrl, bool $forClub): array {
    $driver = gearEmailDriverLine($gear);
    $season = (int)($gear['season'] ?? date('Y'));

    if ($forClub) {
        $lines = [
            'The gear pre-tech photos for ' . $driver . ' were reviewed and accepted. This driver\'s gear is pre-teched for ' . $season . '.',
            'No gear check is needed at the track.',
            TECH_ACCEPTANCE_DISCLAIMER,
            'Review page:', $adminUrl,
        ];
        $link = pretechEmailLink($adminUrl, 'Open the review page');
    } else {
        $lines = [
            'The gear pre-tech photos for ' . $driver . ' were reviewed and accepted. This driver\'s gear is pre-teched for ' . $season . '.',
            'You do not need your gear checked at the track: just collect your decals at the event.',
            TECH_ACCEPTANCE_DISCLAIMER,
            'Your gear page:', $pageUrl,
        ];
        $link = pretechEmailLink($pageUrl, 'View your gear page');
    }
    $html = pretechEmailPara($lines[0]) . pretechEmailPara($lines[1])
        . '<p style="font-size:0.85rem;color:#555">' . h(TECH_ACCEPTANCE_DISCLAIMER) . '</p>' . $link;

    return [
        'subject' => 'WCMA Gear Pre-Tech Accepted — ' . $driver,
        'html' => pretechEmailWrap('GEAR PRE-TECH ACCEPTED', $html),
        'text' => implode("\n\n", $lines) . "\n",
    ];
}

/**
 * Sends the emails for one gear workflow step. Failures never propagate: the workflow step has
 * already happened, so this only reports whether every message was sent, and each message is
 * sent independently.
 *
 * @param string $kind 'submitted' | 'sent_back' | 'accepted'
 * @param array{email: string, name: string} $club recipient for club copies
 * @param callable $sendFn function(array $to, array $message): bool; $to is a list of [email, name]
 * @param array<string,string> $retakes requirement key => note (sent_back only)
 */
function gearNotify(PDO $pdo, string $kind, array $gear, string $baseUrl, array $club, callable $sendFn, array $retakes = []): bool {
    try {
        $base = rtrim($baseUrl, '/');
        $id = (int)$gear['id'];
        $pageUrl = $base . '/gear.php?action=pretech&id=' . $id;
        $adminUrl = $base . '/admin.php?action=gear-record&id=' . $id;

        $owner = db_find_user_by_id($pdo, (int)$gear['owner_user_id']);
        $ownerTo = $owner ? [[$owner['email'], $owner['name']]] : [];
        $clubTo = [[$club['email'], $club['name']]];

        $messages = [];   // list of [to, message]
        switch ($kind) {
            case 'submitted':
                $count = count(array_filter(db_get_inspection_photos($pdo, 'gear_record', $id), fn(array $p): bool => $p['file_path'] !== ''));
                $messages[] = [$clubTo, gearEmailSubmitted($gear, $adminUrl, $pageUrl, $count, true)];
                if ($ownerTo) $messages[] = [$ownerTo, gearEmailSubmitted($gear, $adminUrl, $pageUrl, $count, false)];
                break;
            case 'sent_back':
                $list = [];
                foreach ($retakes as $key => $note) {
                    $req = photoRequirementByKey((string)$key);
                    $list[] = ['label' => $req['label'] ?? (string)$key, 'note' => (string)$note];
                }
                if ($ownerTo) $messages[] = [$ownerTo, gearEmailSentBack($gear, $list, $pageUrl)];
                break;
            case 'accepted':
                if ($ownerTo) $messages[] = [$ownerTo, gearEmailAccepted($gear, $pageUrl, $adminUrl, false)];
                $messages[] = [$clubTo, gearEmailAccepted($gear, $pageUrl, $adminUrl, true)];
                break;
            default:
                return false;
        }

        $allSent = true;
        foreach ($messages as [$to, $message]) {
            try {
                if (!$sendFn($to, $message)) $allSent = false;
            } catch (Throwable $e) {
                error_log('Gear notification error: ' . $e->getMessage());
                $allSent = false;
            }
        }
        return $allSent;
    } catch (Throwable $e) {
        error_log('Gear notification error: ' . $e->getMessage());
        return false;
    }
}
