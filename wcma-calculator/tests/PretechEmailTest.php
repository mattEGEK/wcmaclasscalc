<?php
// wcma-calculator/tests/PretechEmailTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../pretech-email.php';
require_once __DIR__ . '/../email-helpers.php';

use PHPUnit\Framework\TestCase;

final class PretechEmailTest extends TestCase
{
    private function sheet(array $o = []): array {
        return array_merge([
            'id' => 12, 'user_id' => 1, 'car_number' => '42', 'car_make' => 'Mazda', 'car_model' => 'MX-5',
            'entrant_name' => 'Jane <Racer>', 'season' => 2026,
        ], $o);
    }

    private function event(): array {
        return ['name' => 'Spring Sprint', 'event_date' => '2026-05-10'];
    }

    public function testSubmittedEmailsForClubAndCompetitor(): void
    {
        $club = pretechEmailSubmitted($this->sheet(), $this->event(), 'https://x.test/admin.php?action=tech-sheet&id=12', 'https://x.test/tech-sheets.php?action=pretech&id=12', 15, true);
        $this->assertStringContainsString('Pre-Tech Submitted', $club['subject']);
        $this->assertStringContainsString('Car #42', $club['subject']);
        $this->assertStringContainsString('https://x.test/admin.php?action=tech-sheet&amp;id=12', $club['html']);
        $this->assertStringContainsString('15 photos', $club['text']);
        $this->assertStringContainsString('cid:wcma-logo', $club['html']);
        $this->assertStringContainsString('Jane &lt;Racer&gt;', $club['html']);   // escaped
        $this->assertStringNotContainsString('<Racer>', $club['html']);

        $competitor = pretechEmailSubmitted($this->sheet(), $this->event(), 'https://x.test/admin', 'https://x.test/page', 15, false);
        $this->assertStringContainsString('received your photos', $competitor['text']);
        $this->assertStringContainsString('teched in person', $competitor['text']);
        $this->assertStringNotContainsString('https://x.test/admin', $competitor['text']);   // competitors never get the admin link
    }

    public function testSentBackEmailListsEachRetakeWithItsNote(): void
    {
        $mail = pretechEmailSentBack($this->sheet(), $this->event(), [
            ['label' => 'Harness date stamp', 'note' => 'Date not readable'],
            ['label' => 'Front three-quarter view', 'note' => 'Car number hidden <by a cone>'],
        ], 'https://x.test/tech-sheets.php?action=pretech&id=12');

        $this->assertStringContainsString('changes needed', $mail['subject']);
        foreach (['Harness date stamp', 'Date not readable', 'Front three-quarter view', 'Car number hidden'] as $needle) {
            $this->assertStringContainsString($needle, $mail['html']);
            $this->assertStringContainsString($needle, $mail['text']);
        }
        $this->assertStringContainsString('&lt;by a cone&gt;', $mail['html']);
        $this->assertStringContainsString('https://x.test/tech-sheets.php?action=pretech&amp;id=12', $mail['html']);
    }

    public function testAcceptedEmailHasDisclaimerAndDecalsInstruction(): void
    {
        $admin = 'https://x.test/admin.php?action=tech-sheet&id=12';
        $mail = pretechEmailAccepted($this->sheet(), $this->event(), 'https://x.test/tech-sheets.php?action=view&id=12', $admin, false);
        $this->assertStringContainsString('Pre-Tech Accepted', $mail['subject']);
        $this->assertStringContainsString(TECH_ACCEPTANCE_DISCLAIMER, $mail['html']);
        $this->assertStringContainsString(TECH_ACCEPTANCE_DISCLAIMER, $mail['text']);
        $this->assertStringContainsString('decals', $mail['text']);
        $this->assertStringContainsString('You do not need to be inspected at the track', $mail['text']);
        $this->assertStringContainsString('2026', $mail['text']);
        $this->assertStringNotContainsString('admin.php', $mail['text']);
        $this->assertStringNotContainsString('admin.php', $mail['html']);

        $club = pretechEmailAccepted($this->sheet(), $this->event(), 'https://x.test/tech-sheets.php?action=view&id=12', $admin, true);
        $this->assertSame($mail['subject'], $club['subject']);
        $this->assertStringContainsString(TECH_ACCEPTANCE_DISCLAIMER, $club['html']);
        $this->assertStringContainsString(TECH_ACCEPTANCE_DISCLAIMER, $club['text']);
        $this->assertStringContainsString($admin, $club['text']);
        $this->assertStringContainsString('admin.php?action=tech-sheet&amp;id=12', $club['html']);
        $this->assertStringContainsString('Open the review page', $club['html']);
        $this->assertStringContainsString('No in-person inspection is needed', $club['text']);
        $this->assertStringNotContainsString('You do not need to be inspected', $club['text']);
        $this->assertStringNotContainsString('action=view', $club['text']);
    }

    public function testNoApprovalWordingOutsideTheDisclaimer(): void
    {
        $mails = [
            pretechEmailSubmitted($this->sheet(), $this->event(), 'a', 'b', 3, true),
            pretechEmailSubmitted($this->sheet(), $this->event(), 'a', 'b', 3, false),
            pretechEmailSentBack($this->sheet(), $this->event(), [['label' => 'X', 'note' => 'Y']], 'b'),
            pretechEmailAccepted($this->sheet(), $this->event(), 'v', 'a', false),
            pretechEmailAccepted($this->sheet(), $this->event(), 'v', 'a', true),
        ];
        foreach ($mails as $mail) {
            $text = str_replace(TECH_ACCEPTANCE_DISCLAIMER, '', $mail['subject'] . "\n" . $mail['text']);
            $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', $text);
        }
    }

    private function notifyFixture(): array {
        $pdo = make_temp_pdo();
        $userId = db_create_user($pdo, ['email' => 'jane@example.com', 'name' => 'Jane', 'password_hash' => 'x', 'google_id' => null]);
        $sheet = $this->sheet(['user_id' => $userId]);
        return [$pdo, $sheet];
    }

    private function recorder(array &$log, bool $result = true): callable {
        return function (array $to, array $message) use (&$log, $result): bool {
            $log[] = ['to' => $to, 'subject' => $message['subject']];
            return $result;
        };
    }

    public function testNotifySubmittedGoesToClubAndCompetitor(): void
    {
        [$pdo, $sheet] = $this->notifyFixture();
        $log = [];
        $ok = pretechNotify($pdo, 'submitted', $sheet, $this->event(), 'https://x.test/', ['email' => 'club@example.com', 'name' => 'Club'], $this->recorder($log));

        $this->assertTrue($ok);
        $this->assertCount(2, $log);
        $recipients = array_map(fn($e) => $e['to'][0][0], $log);
        $this->assertEqualsCanonicalizing(['club@example.com', 'jane@example.com'], $recipients);
    }

    public function testNotifySentBackGoesToTheCompetitorOnlyWithLabelsFromTheRequirementList(): void
    {
        [$pdo, $sheet] = $this->notifyFixture();
        $captured = [];
        $sendFn = function (array $to, array $message) use (&$captured): bool { $captured[] = [$to, $message]; return true; };

        $ok = pretechNotify($pdo, 'sent_back', $sheet, $this->event(), 'https://x.test', ['email' => 'club@example.com', 'name' => 'Club'], $sendFn,
            ['harness_date' => 'Date not readable']);

        $this->assertTrue($ok);
        $this->assertCount(1, $captured);
        $this->assertSame('jane@example.com', $captured[0][0][0][0]);
        $this->assertStringContainsString('Harness date stamp', $captured[0][1]['text']);
        $this->assertStringContainsString('Date not readable', $captured[0][1]['text']);
        $this->assertStringContainsString('https://x.test/tech-sheets.php?action=pretech&id=12', $captured[0][1]['text']);
    }

    public function testNotifyAcceptedGoesToBoth(): void
    {
        [$pdo, $sheet] = $this->notifyFixture();
        $log = [];
        $this->assertTrue(pretechNotify($pdo, 'accepted', $sheet, $this->event(), 'https://x.test', ['email' => 'club@example.com', 'name' => 'Club'], $this->recorder($log)));
        $this->assertCount(2, $log);
        $this->assertStringContainsString('Accepted', $log[0]['subject']);
    }

    public function testNotifyAcceptedSendsTheClubWordedCopyToTheClub(): void
    {
        [$pdo, $sheet] = $this->notifyFixture();
        $captured = [];
        $sendFn = function (array $to, array $message) use (&$captured): bool { $captured[$to[0][0]] = $message; return true; };
        $this->assertTrue(pretechNotify($pdo, 'accepted', $sheet, $this->event(), 'https://x.test', ['email' => 'club@example.com', 'name' => 'Club'], $sendFn));
        $this->assertStringContainsString('https://x.test/admin.php?action=tech-sheet&id=12', $captured['club@example.com']['text']);
        $this->assertStringNotContainsString('admin.php', $captured['jane@example.com']['text']);
        $this->assertStringContainsString('action=view&id=12', $captured['jane@example.com']['text']);
    }

    public function testNotifyIsolatesEachMessage(): void
    {
        [$pdo, $sheet] = $this->notifyFixture();
        $calls = [];
        $sendFn = function (array $to, array $message) use (&$calls): bool {
            $calls[] = $to[0][0];
            if (count($calls) === 1) throw new RuntimeException('first fails');
            return true;
        };
        $this->assertFalse(pretechNotify($pdo, 'submitted', $sheet, $this->event(), 'https://x.test', ['email' => 'club@example.com', 'name' => 'Club'], $sendFn));
        $this->assertCount(2, $calls);
    }

    public function testNotifyReportsFailureAndSurvivesExceptions(): void
    {
        [$pdo, $sheet] = $this->notifyFixture();
        $club = ['email' => 'club@example.com', 'name' => 'Club'];

        $log = [];
        $this->assertFalse(pretechNotify($pdo, 'accepted', $sheet, $this->event(), 'https://x.test', $club, $this->recorder($log, false)));
        $this->assertCount(2, $log);   // a failed send does not stop the others

        $boom = function (array $to, array $message): bool { throw new RuntimeException('smtp down'); };
        $this->assertFalse(pretechNotify($pdo, 'accepted', $sheet, $this->event(), 'https://x.test', $club, $boom));

        $this->assertFalse(pretechNotify($pdo, 'bogus', $sheet, $this->event(), 'https://x.test', $club, $this->recorder($log)));
    }

    public function testMailDryRunLogsInsteadOfSending(): void
    {
        $file = sys_get_temp_dir() . '/wcma_mail_' . uniqid() . '.log';
        define('WCMA_MAIL_LOG', $file);

        $ok = emailSmtpSend([['jane@example.com', 'Jane']], ['subject' => 'Hello', 'html' => '<p>x</p>', 'text' => 'plain body']);

        $this->assertTrue($ok);
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $this->assertCount(1, $lines);
        $entry = json_decode($lines[0], true);
        $this->assertSame([['jane@example.com', 'Jane']], $entry['to']);
        $this->assertSame('Hello', $entry['subject']);
        $this->assertSame('plain body', $entry['text']);
        unlink($file);
    }
}
