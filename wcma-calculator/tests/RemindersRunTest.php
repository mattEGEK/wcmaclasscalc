<?php
// wcma-calculator/tests/RemindersRunTest.php
require_once __DIR__ . '/../reminder-run.php';

use PHPUnit\Framework\TestCase;

final class RemindersRunTest extends TestCase
{
    private PDO $pdo;
    private array $sent = [];

    protected function setUp(): void
    {
        $this->pdo = make_temp_pdo();
        $this->sent = [];
    }

    private function user(string $email, string $name, bool $on = true): int {
        $u = db_create_user($this->pdo, ['email' => $email, 'name' => $name, 'password_hash' => 'x', 'google_id' => null]);
        if ($on) db_set_user_reminders($this->pdo, $u, true);
        return $u;
    }

    /** Tags the user's car $number for a new event on $date; returns the event id. */
    private function going(int $u, string $date, string $number = '42'): int {
        $e = db_create_event($this->pdo, 'Event ' . $date, $date, null);
        db_tag_event($this->pdo, $u, $e, test_make_car($this->pdo, $u, $number));
        return $e;
    }

    private function runReminders(string $today = '2026-10-01', ?callable $send = null): array {
        return remindersRun($this->pdo, $today, 'https://x.test/classing/', $send ?? function (array $to, array $m): bool {
            $this->sent[] = [$to, $m];
            return true;
        });
    }

    public function testEmailsOneDigestPerDueEventAndRecordsIt(): void
    {
        $u = $this->user('jordan@example.com', 'Jordan Lee');
        $e = $this->going($u, '2026-10-08');
        $this->going($u, '2026-10-30', '17');   // 29 days away: not due

        $this->assertSame(['users' => 1, 'sent' => 1, 'skipped' => 0, 'failed' => 0], $this->runReminders());
        $this->assertCount(1, $this->sent);
        [$to, $m] = $this->sent[0];
        $this->assertSame([['jordan@example.com', 'Jordan Lee']], $to);
        $this->assertStringContainsString('before Event 2026-10-08', $m['subject']);
        $this->assertStringContainsString('Declare class for #42', $m['text']);
        $this->assertStringContainsString('Submit a tech sheet for #42', $m['text']);
        $this->assertStringStartsWith('<https://x.test/classing/unsubscribe.php?u=' . $u . '&t=', $m['headers']['List-Unsubscribe']);
        $this->assertTrue(db_reminder_logged($this->pdo, $u, $e, 7));
    }

    public function testRunningAgainTheSameDaySendsNothing(): void
    {
        $u = $this->user('jordan@example.com', 'Jordan Lee');
        $this->going($u, '2026-10-08');
        $this->runReminders();
        $this->assertSame(['users' => 1, 'sent' => 0, 'skipped' => 1, 'failed' => 0], $this->runReminders());
        $this->assertCount(1, $this->sent);
    }

    public function testTheNextWindowSendsAgain(): void
    {
        $u = $this->user('jordan@example.com', 'Jordan Lee');
        $e = $this->going($u, '2026-10-08');
        $this->runReminders('2026-10-01');                 // 7 days out
        $this->runReminders('2026-10-06');                 // 2 days out
        $this->assertCount(2, $this->sent);
        $this->assertTrue(db_reminder_logged($this->pdo, $u, $e, 2));
    }

    public function testAMissedDayCatchesUpWithinTheWindow(): void
    {
        $u = $this->user('jordan@example.com', 'Jordan Lee');
        $e = $this->going($u, '2026-10-11');      // 10 days out: the 14-day reminder is still owed
        $this->runReminders();
        $this->assertCount(1, $this->sent);
        $this->assertTrue(db_reminder_logged($this->pdo, $u, $e, 14));
    }

    public function testNothingForOptedOutInactiveOrUntaggedUsers(): void
    {
        $off = $this->user('off@example.com', 'Off Person', false);
        $this->going($off, '2026-10-08');
        $gone = $this->user('gone@example.com', 'Gone Person');
        $this->going($gone, '2026-10-08');
        db_set_user_active($this->pdo, $gone, false);
        $this->user('idle@example.com', 'Idle Person');   // opted in, nothing tagged

        $this->assertSame(['users' => 1, 'sent' => 0, 'skipped' => 0, 'failed' => 0], $this->runReminders());
        $this->assertSame([], $this->sent);
    }

    public function testNothingOnTheDayOrAfter(): void
    {
        $u = $this->user('jordan@example.com', 'Jordan Lee');
        $this->going($u, '2026-10-01');
        $this->going($u, '2026-09-30', '17');
        $this->runReminders();
        $this->assertSame([], $this->sent);
    }

    public function testAFailedSendIsNotRecordedSoTheNextRunRetries(): void
    {
        $u = $this->user('jordan@example.com', 'Jordan Lee');
        $e = $this->going($u, '2026-10-08');
        $boom = function (array $to, array $m): bool { throw new RuntimeException('smtp down'); };
        $this->assertSame(['users' => 1, 'sent' => 0, 'skipped' => 0, 'failed' => 1], $this->runReminders('2026-10-01', $boom));
        $this->assertSame(['users' => 1, 'sent' => 0, 'skipped' => 0, 'failed' => 1], $this->runReminders('2026-10-01', fn(array $to, array $m): bool => false));
        $this->assertFalse(db_reminder_logged($this->pdo, $u, $e, 7));
        $this->assertSame(1, $this->runReminders()['sent']);
    }

    public function testOneUsersBlowUpDoesNotStopLaterUsersFromGettingTheirEmails(): void
    {
        $bad = $this->user('bad@example.com', 'Bad Person');
        $this->going($bad, 'not-a-date');   // makes reminderDue()/reminderDaysUntil() throw for this user
        $good = $this->user('good@example.com', 'Good Person');
        $e = $this->going($good, '2026-10-08');

        $summary = $this->runReminders();
        $this->assertSame(['users' => 2, 'sent' => 1, 'skipped' => 0, 'failed' => 1], $summary);
        $this->assertCount(1, $this->sent);
        $this->assertSame([['good@example.com', 'Good Person']], $this->sent[0][0]);
        $this->assertTrue(db_reminder_logged($this->pdo, $good, $e, 7));
    }

    public function testTheUnsubscribeLinkIsSignedForThatUser(): void
    {
        $u = $this->user('jordan@example.com', 'Jordan Lee');
        $this->going($u, '2026-10-08');
        $this->runReminders();
        $this->assertSame(1, preg_match('/unsubscribe\.php\?u=(\d+)&t=([0-9a-f]{64})>$/', $this->sent[0][1]['headers']['List-Unsubscribe'], $m));
        $this->assertSame($u, (int)$m[1]);
        $this->assertTrue(reminderTokenValid($u, $m[2], reminderSecret($this->pdo)));
    }
}
