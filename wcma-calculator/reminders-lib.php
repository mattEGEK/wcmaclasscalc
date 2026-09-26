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
