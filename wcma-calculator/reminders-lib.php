<?php
// wcma-calculator/reminders-lib.php
//
// Opt-in reminder emails (spec §7): which reminder is due, the digest for each tagged event, the
// one-time opt-in offer on tag forms, and signed unsubscribe links. Pure functions, except
// reminderSecret() and remindersRecordTagChoice(), which use the database. Callers must have loaded
// db.php and view_helpers.php (h()).
require_once __DIR__ . '/email-copy.php';

/**
 * Reminder windows, smallest first. 1–2 days out is the 2-day reminder, 3–7 the 7-day one and 8–14
 * the 14-day one. reminder_log keeps each window to one email, so a missed cron day still sends it
 * and a repeated run doesn't send it twice.
 */
const REMINDER_DAYS = [2, 7, 14];

/** Whole calendar days from $today to $eventDate ('YYYY-MM-DD…'); negative once the event has passed. */
function reminderDaysUntil(string $eventDate, string $today): int {
    $utc = new DateTimeZone('UTC');
    $diff = (new DateTimeImmutable(substr($today, 0, 10), $utc))->diff(new DateTimeImmutable(substr($eventDate, 0, 10), $utc));
    return $diff->invert ? -$diff->days : $diff->days;
}

/** The reminder window (14, 7 or 2) an event is in today, or null (too far away, today, or over). */
function reminderDue(string $eventDate, string $today): ?int {
    $days = reminderDaysUntil($eventDate, $today);
    if ($days < 1) return null;
    foreach (REMINDER_DAYS as $window) {
        if ($days <= $window) return $window;
    }
    return null;
}

/**
 * One digest per tagged event whose reminder window is open and which still has something to do.
 *
 * @param array $readiness buildReadiness() output
 * @return array<int, array{event: array, daysOut: int, daysUntil: int, items: array}> items are the todo items only
 */
function reminderDigests(array $readiness, string $today): array {
    $digests = [];
    foreach ($readiness['events'] as $row) {
        $date = (string)$row['event']['event_date'];
        $due = reminderDue($date, $today);
        if ($due === null) continue;
        $todo = array_values(array_filter($row['items'], fn(array $i): bool => $i['state'] === 'todo'));
        if (!$todo) continue;
        $digests[] = ['event' => $row['event'], 'daysOut' => $due, 'daysUntil' => reminderDaysUntil($date, $today), 'items' => $todo];
    }
    return $digests;
}

/** Offer the reminder checkbox on tag forms until the user has chosen, here or in Profile (spec §7). */
function remindersShouldOffer(?array $user): bool {
    return $user !== null && (int)($user['reminder_emails'] ?? 0) === 0 && empty($user['reminder_prompted_at']);
}

function reminderUnsubscribeToken(int $userId, string $secret): string {
    return hash_hmac('sha256', 'unsubscribe:' . $userId, $secret);
}

function reminderTokenValid(int $userId, string $token, string $secret): bool {
    return $userId > 0 && $secret !== '' && hash_equals(reminderUnsubscribeToken($userId, $secret), $token);
}

function reminderUnsubscribeUrl(string $baseUrl, int $userId, string $secret): string {
    return rtrim($baseUrl, '/') . '/unsubscribe.php?u=' . $userId . '&t=' . reminderUnsubscribeToken($userId, $secret);
}

/** The unsubscribe-link signing secret, created on first use. Resetting the database makes old links invalid. */
function reminderSecret(PDO $pdo): string {
    $secret = (string)db_get_setting($pdo, 'reminder_secret', '');
    if ($secret === '') {
        $secret = bin2hex(random_bytes(32));
        db_set_setting($pdo, 'reminder_secret', $secret);
    }
    return $secret;
}

/**
 * The unsubscribe page body. $state: 'confirm' (a valid link: show the button), 'done' (reminders
 * are now off), or anything else for an invalid link.
 */
function renderUnsubscribeHtml(string $state, int $userId, string $token): string {
    if ($state === 'done') {
        return '<h1 class="hub-page-title">You&#039;re unsubscribed</h1>'
            . '<p>' . h('You won\'t get reminder emails any more.') . ' You can turn them back on in your <a href="profile.php">Profile</a>.</p>';
    }
    if ($state === 'confirm') {
        return '<h1 class="hub-page-title">Stop reminder emails?</h1>'
            . '<p>' . h('You\'ll stop getting emails before the events you\'re going to. Your Home page still lists what\'s left to do.') . '</p>'
            . '<form method="post" action="unsubscribe.php">'
            . '<input type="hidden" name="u" value="' . $userId . '">'
            . '<input type="hidden" name="t" value="' . h($token) . '">'
            . '<button type="submit" class="hub-btn">Unsubscribe</button></form>';
    }
    return '<h1 class="hub-page-title">' . h('This link isn\'t valid') . '</h1>'
        . '<p>It may have been copied incompletely. You can turn reminder emails off in your <a href="profile.php">Profile</a>.</p>';
}

/** The one-time "email me reminders" checkbox for tag forms, unticked. */
function reminderOptInFieldsHtml(): string {
    return '<input type="hidden" name="offer_reminders" value="1">'
        . '<label class="hub-reminder-opt"><input type="checkbox" name="reminders" value="1"> ' . h(COPY_REMINDER_OPT_IN) . '</label>';
}

/**
 * After a successful tag: when the form offered the checkbox and the user still hasn't chosen, record
 * the choice (ticked = on, unticked = off). Returns a sentence to add to the flash message, or ''.
 */
function remindersRecordTagChoice(PDO $pdo, int $userId, array $post): string {
    if (empty($post['offer_reminders']) || !remindersShouldOffer(db_find_user_by_id($pdo, $userId))) return '';
    $on = !empty($post['reminders']);
    db_set_user_reminders($pdo, $userId, $on);
    return $on ? ' We\'ll email you reminders before your events.' : ' You can turn on reminder emails in your Profile.';
}
