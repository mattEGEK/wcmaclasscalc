<?php
// wcma-calculator/reminder-email.php
//
// The reminder digest email (spec §7): one per user per tagged event, listing what is still to do.
// A pure renderer, branded like the other hub emails (logo via cid:wcma-logo). Every message carries
// an unsubscribe link and the List-Unsubscribe headers mail apps use for one-click unsubscribe.
require_once __DIR__ . '/pretech-email.php';   // pretechEmailWrap/Para/Link, h()
require_once __DIR__ . '/events-lib.php';      // EVENTS_NOT_REGISTERING

/** A readiness action URL (relative to the hub) made absolute for an email. Full URLs are left alone. */
function reminderAbsoluteUrl(string $baseUrl, string $url): string {
    return preg_match('#^https?://#i', $url) ? $url : rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
}

/**
 * @param array $user   a users row (name)
 * @param array $digest one reminderDigests() entry
 * @return array{subject: string, html: string, text: string, headers: array<string,string>}
 */
function reminderEmail(array $user, array $digest, string $baseUrl, string $unsubscribeUrl): array {
    $event = $digest['event'];
    $date = strtotime((string)$event['event_date']);
    $n = count($digest['items']);
    $things = $n . ' ' . ($n === 1 ? 'thing' : 'things');
    $days = (int)$digest['daysUntil'];
    $intro = $event['name'] . ' is ' . ($days === 1 ? 'tomorrow' : 'in ' . $days . ' days') . ' (' . date('l, F j', $date) . ').'
        . ' You still have ' . $things . ' to do:';
    $home = rtrim($baseUrl, '/') . '/index.php';
    $why = 'You get these emails because you turned on reminder emails in the WCMA Hub.';

    $text = 'Hi ' . $user['name'] . ",\n\n" . $intro . "\n\n";
    $html = pretechEmailPara('Hi ' . $user['name'] . ',') . pretechEmailPara($intro) . '<ol>';
    foreach ($digest['items'] as $item) {
        $text .= '- ' . $item['label'] . "\n";
        $html .= '<li><strong>' . h((string)$item['label']) . '</strong>';
        if (trim((string)($item['detail'] ?? '')) !== '') {
            $text .= '  ' . $item['detail'] . "\n";
            $html .= '<br>' . h((string)$item['detail']);
        }
        if (!empty($item['action']['url'])) {
            $url = reminderAbsoluteUrl($baseUrl, (string)$item['action']['url']);
            $text .= '  ' . $item['action']['label'] . ': ' . $url . "\n";
            $html .= '<br><a href="' . h($url) . '">' . h((string)$item['action']['label']) . '</a>';
        }
        $html .= '</li>';
    }
    $html .= '</ol>' . pretechEmailLink($home, 'Open your Home page') . pretechEmailPara(EVENTS_NOT_REGISTERING)
        . '<p style="color:#666;font-size:0.9em">' . h($why) . ' <a href="' . h($unsubscribeUrl) . '">Unsubscribe from reminder emails</a></p>';
    $text .= "\nSee everything on your Home page: " . $home . "\n\n" . EVENTS_NOT_REGISTERING . "\n\n" . $why . "\nUnsubscribe: " . $unsubscribeUrl . "\n";

    return [
        'subject' => 'WCMA reminder: ' . $things . ' to do before ' . $event['name'] . ' (' . date('M j', $date) . ')',
        'html' => pretechEmailWrap('EVENT REMINDER', $html),
        'text' => $text,
        'headers' => ['List-Unsubscribe' => '<' . $unsubscribeUrl . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'],
    ];
}
