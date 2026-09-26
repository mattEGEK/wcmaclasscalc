<?php
// wcma-calculator/reminder-run.php
//
// One daily reminder run (spec §7): for each opted-in, active account, build its readiness, find the
// reminders due today, and email one digest per event, once per window (reminder_log). Session-free,
// with an injectable send function, so it is unit-testable. Callers must have loaded db.php;
// reminders.php (the cron entry point) passes emailSmtpSend.
require_once __DIR__ . '/tech-status.php';
require_once __DIR__ . '/gear-lib.php';
require_once __DIR__ . '/readiness-lib.php';
require_once __DIR__ . '/view_helpers.php';
require_once __DIR__ . '/reminders-lib.php';
require_once __DIR__ . '/reminder-email.php';

/**
 * A failed send is logged and counted, not recorded, so the next run tries again.
 *
 * @param callable $sendFn function(array $to, array $message): bool; $to is a list of [email, name]
 * @return array{users: int, sent: int, skipped: int, failed: int}
 */
function remindersRun(PDO $pdo, string $today, string $baseUrl, callable $sendFn): array {
    $summary = ['users' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];
    $secret = reminderSecret($pdo);
    foreach (db_get_reminder_users($pdo) as $user) {
        $summary['users']++;
        $uid = (int)$user['id'];
        foreach (reminderDigests(buildReadiness(loadReadinessInputs($pdo, $uid, $today)), $today) as $digest) {
            $eventId = (int)$digest['event']['id'];
            if (db_reminder_logged($pdo, $uid, $eventId, $digest['daysOut'])) {
                $summary['skipped']++;
                continue;
            }
            $message = reminderEmail($user, $digest, $baseUrl, reminderUnsubscribeUrl($baseUrl, $uid, $secret));
            try {
                $ok = (bool)$sendFn([[(string)$user['email'], (string)$user['name']]], $message);
            } catch (Throwable $e) {
                error_log('Reminder email error: ' . $e->getMessage());
                $ok = false;
            }
            if ($ok) {
                db_log_reminder($pdo, $uid, $eventId, $digest['daysOut']);
                $summary['sent']++;
            } else {
                $summary['failed']++;
            }
        }
    }
    return $summary;
}
