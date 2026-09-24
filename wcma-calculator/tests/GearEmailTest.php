<?php
// wcma-calculator/tests/GearEmailTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../gear-email.php';

use PHPUnit\Framework\TestCase;

final class GearEmailTest extends TestCase
{
    private function gear(array $o = []): array {
        return array_merge(['id' => 4, 'owner_user_id' => 1, 'driver_name' => 'Jane <Racer>', 'season' => 2026], $o);
    }

    public function testSubmittedEmailsForClubAndOwner(): void
    {
        $club = gearEmailSubmitted($this->gear(), 'https://x.test/admin.php?action=gear-record&id=4', 'https://x.test/gear.php?action=pretech&id=4', 5, true);
        $this->assertStringContainsString('Gear Pre-Tech Submitted', $club['subject']);
        $this->assertStringContainsString('cid:wcma-logo', $club['html']);
        $this->assertStringContainsString('https://x.test/admin.php?action=gear-record&amp;id=4', $club['html']);
        $this->assertStringContainsString('5 photos', $club['text']);
        $this->assertStringContainsString('Jane &lt;Racer&gt;', $club['html']);
        $this->assertStringNotContainsString('<Racer>', $club['html']);

        $owner = gearEmailSubmitted($this->gear(), 'https://x.test/admin', 'https://x.test/page', 5, false);
        $this->assertStringContainsString('received your photos', $owner['text']);
        $this->assertStringContainsString('checked in person', $owner['text']);
        $this->assertStringNotContainsString('https://x.test/admin', $owner['text']);
        $this->assertStringNotContainsString('https://x.test/admin', $owner['html']);
    }

    public function testSentBackListsEachRetakeWithItsNote(): void
    {
        $mail = gearEmailSentBack($this->gear(), [
            ['label' => 'Helmet certification label', 'note' => 'Date not readable'],
            ['label' => 'Race suit label', 'note' => 'Too dark <flash>'],
        ], 'https://x.test/gear.php?action=pretech&id=4');

        $this->assertStringContainsString('changes needed', $mail['subject']);
        foreach (['Helmet certification label', 'Date not readable', 'Race suit label', 'Too dark'] as $needle) {
            $this->assertStringContainsString($needle, $mail['html']);
            $this->assertStringContainsString($needle, $mail['text']);
        }
        $this->assertStringContainsString('&lt;flash&gt;', $mail['html']);
        $this->assertStringContainsString('https://x.test/gear.php?action=pretech&amp;id=4', $mail['html']);
    }

    public function testAcceptedOwnerAndClubCopies(): void
    {
        $owner = gearEmailAccepted($this->gear(), 'https://x.test/gear.php?action=pretech&id=4', 'https://x.test/admin.php?action=gear-record&id=4', false);
        $club = gearEmailAccepted($this->gear(), 'https://x.test/gear.php?action=pretech&id=4', 'https://x.test/admin.php?action=gear-record&id=4', true);

        foreach ([$owner, $club] as $mail) {
            $this->assertStringContainsString('Gear Pre-Tech Accepted', $mail['subject']);
            $this->assertStringContainsString(TECH_ACCEPTANCE_DISCLAIMER, $mail['html']);
            $this->assertStringContainsString(TECH_ACCEPTANCE_DISCLAIMER, $mail['text']);
            $this->assertStringContainsString('2026', $mail['text']);
        }
        $this->assertStringContainsString('decals', $owner['text']);
        $this->assertStringNotContainsString('admin.php', $owner['text']);
        $this->assertStringNotContainsString('admin.php', $owner['html']);
        $this->assertStringContainsString('admin.php?action=gear-record&id=4', $club['text']);
        $this->assertStringNotContainsString('You do not need', $club['text']);
    }

    public function testNoBannedWordingOutsideTheDisclaimer(): void
    {
        $mails = [
            gearEmailSubmitted($this->gear(), 'a', 'b', 3, true), gearEmailSubmitted($this->gear(), 'a', 'b', 3, false),
            gearEmailSentBack($this->gear(), [['label' => 'X', 'note' => 'Y']], 'b'),
            gearEmailAccepted($this->gear(), 'b', 'a', true), gearEmailAccepted($this->gear(), 'b', 'a', false),
        ];
        foreach ($mails as $mail) {
            $text = str_replace(TECH_ACCEPTANCE_DISCLAIMER, '', $mail['subject'] . "\n" . $mail['text']);
            $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', $text);
        }
    }

    private function fixture(): array {
        $pdo = make_temp_pdo();
        $ownerId = db_create_user($pdo, ['email' => 'jane@example.com', 'name' => 'Jane', 'password_hash' => 'x', 'google_id' => null]);
        return [$pdo, $this->gear(['owner_user_id' => $ownerId])];
    }

    private function recorder(array &$log, bool $result = true): callable {
        return function (array $to, array $message) use (&$log, $result): bool {
            $log[] = ['to' => $to, 'subject' => $message['subject']];
            return $result;
        };
    }

    public function testNotifySubmittedAndAcceptedGoToBoth(): void
    {
        [$pdo, $gear] = $this->fixture();
        $club = ['email' => 'club@example.com', 'name' => 'Club'];
        foreach (['submitted', 'accepted'] as $kind) {
            $log = [];
            $this->assertTrue(gearNotify($pdo, $kind, $gear, 'https://x.test/', $club, $this->recorder($log)), $kind);
            $this->assertCount(2, $log, $kind);
            $this->assertEqualsCanonicalizing(['club@example.com', 'jane@example.com'], array_map(fn($e) => $e['to'][0][0], $log), $kind);
        }
    }

    public function testNotifySentBackGoesToTheOwnerWithLabelsFromTheRequirementList(): void
    {
        [$pdo, $gear] = $this->fixture();
        $captured = [];
        $sendFn = function (array $to, array $message) use (&$captured): bool { $captured[] = [$to, $message]; return true; };

        $this->assertTrue(gearNotify($pdo, 'sent_back', $gear, 'https://x.test', ['email' => 'club@example.com', 'name' => 'Club'], $sendFn, ['helmet_label' => 'Date not readable']));
        $this->assertCount(1, $captured);
        $this->assertSame('jane@example.com', $captured[0][0][0][0]);
        $this->assertStringContainsString('Helmet certification label', $captured[0][1]['text']);
        $this->assertStringContainsString('Date not readable', $captured[0][1]['text']);
        $this->assertStringContainsString('https://x.test/gear.php?action=pretech&id=4', $captured[0][1]['text']);
    }

    public function testNotifyIsolatesEachMessageAndReportsFailure(): void
    {
        [$pdo, $gear] = $this->fixture();
        $club = ['email' => 'club@example.com', 'name' => 'Club'];

        $log = [];
        $this->assertFalse(gearNotify($pdo, 'accepted', $gear, 'https://x.test', $club, $this->recorder($log, false)));
        $this->assertCount(2, $log);

        $calls = 0;
        $boomFirst = function (array $to, array $message) use (&$calls): bool {
            $calls++;
            if ($calls === 1) throw new RuntimeException('smtp down');
            return true;
        };
        $this->assertFalse(gearNotify($pdo, 'accepted', $gear, 'https://x.test', $club, $boomFirst));
        $this->assertSame(2, $calls);

        $this->assertFalse(gearNotify($pdo, 'bogus', $gear, 'https://x.test', $club, $this->recorder($log)));
    }
}
