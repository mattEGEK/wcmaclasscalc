<?php
// wcma-calculator/tests/RemindersLibTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../reminders-lib.php';

use PHPUnit\Framework\TestCase;

final class RemindersLibTest extends TestCase
{
    public function testDaysUntilCountsCalendarDays(): void
    {
        $this->assertSame(7, reminderDaysUntil('2026-10-11', '2026-10-04'));
        $this->assertSame(0, reminderDaysUntil('2026-10-04', '2026-10-04'));
        $this->assertSame(-3, reminderDaysUntil('2026-10-01', '2026-10-04'));
        $this->assertSame(14, reminderDaysUntil('2026-11-08', '2026-10-25'));      // across the DST change
        $this->assertSame(7, reminderDaysUntil('2026-10-11 09:00:00', '2026-10-04'));
    }

    public function testReminderWindows(): void
    {
        $cases = [
            '2026-10-16' => null,   // 15 days
            '2026-10-15' => 14,     // 14
            '2026-10-09' => 14,     // 8: a missed 14-day run still catches up
            '2026-10-08' => 7,      // 7
            '2026-10-04' => 7,      // 3
            '2026-10-03' => 2,      // 2
            '2026-10-02' => 2,      // 1
            '2026-10-01' => null,   // the day of the event
            '2026-09-30' => null,   // over
        ];
        foreach ($cases as $eventDate => $expected) {
            $this->assertSame($expected, reminderDue($eventDate, '2026-10-01'), $eventDate);
        }
    }

    private function item(string $state, string $label): array {
        return ['kind' => 'tech_sheet', 'subject_type' => 'car', 'subject_id' => 3, 'state' => $state, 'label' => $label,
                'detail' => '', 'action' => null, 'at_track' => null];
    }

    public function testDigestsKeepOnlyDueEventsWithSomethingToDo(): void
    {
        $readiness = ['events' => [
            ['event' => ['id' => 1, 'name' => 'Soon', 'event_date' => '2026-10-08'],
             'items' => [$this->item('todo', 'Submit a tech sheet for #42'), $this->item('done', 'Class declared'), $this->item('info', 'With an inspector')]],
            ['event' => ['id' => 2, 'name' => 'All set', 'event_date' => '2026-10-11'],
             'items' => [$this->item('done', 'Tech sheet submitted'), $this->item('info', 'Photos with an inspector')]],
            ['event' => ['id' => 3, 'name' => 'Far away', 'event_date' => '2026-10-30'],
             'items' => [$this->item('todo', 'Submit a tech sheet for #42')]],
            ['event' => ['id' => 4, 'name' => 'Very soon', 'event_date' => '2026-10-03'],
             'items' => [$this->item('todo', 'Gear for Jordan Lee')]],
        ], 'untagged' => []];

        $digests = reminderDigests($readiness, '2026-10-01');
        $this->assertSame([1, 4], array_map(fn(array $d): int => (int)$d['event']['id'], $digests));
        $this->assertSame(7, $digests[0]['daysOut']);
        $this->assertSame(7, $digests[0]['daysUntil']);
        $this->assertSame(['Submit a tech sheet for #42'], array_column($digests[0]['items'], 'label'));
        $this->assertSame(2, $digests[1]['daysOut']);
        $this->assertSame(2, $digests[1]['daysUntil']);
        $this->assertSame([], reminderDigests(['events' => [], 'untagged' => []], '2026-10-01'));
    }

    public function testTheOfferShowsUntilTheUserHasChosen(): void
    {
        $this->assertFalse(remindersShouldOffer(null));
        $this->assertTrue(remindersShouldOffer(['reminder_emails' => 0, 'reminder_prompted_at' => null]));
        $this->assertFalse(remindersShouldOffer(['reminder_emails' => 1, 'reminder_prompted_at' => null]));
        $this->assertFalse(remindersShouldOffer(['reminder_emails' => 0, 'reminder_prompted_at' => '2026-09-01 10:00:00']));
    }

    public function testUnsubscribeTokens(): void
    {
        $secret = str_repeat('ab', 32);
        $token = reminderUnsubscribeToken(5, $secret);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertTrue(reminderTokenValid(5, $token, $secret));
        $this->assertFalse(reminderTokenValid(6, $token, $secret));                  // someone else's id with your token
        $this->assertFalse(reminderTokenValid(5, substr($token, 0, 40), $secret));   // copied incompletely
        $this->assertFalse(reminderTokenValid(5, $token, 'other-secret'));
        $this->assertFalse(reminderTokenValid(5, $token, ''));
        $this->assertFalse(reminderTokenValid(0, reminderUnsubscribeToken(0, $secret), $secret));
        $this->assertSame('https://x.test/classing/unsubscribe.php?u=5&t=' . $token, reminderUnsubscribeUrl('https://x.test/classing/', 5, $secret));
    }

    public function testSecretIsCreatedOnceAndKept(): void
    {
        $pdo = make_temp_pdo();
        $first = reminderSecret($pdo);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        $this->assertSame($first, reminderSecret($pdo));
    }

    public function testOptInCopy(): void
    {
        $this->assertSame('Email me reminders for events I\'m going to.', COPY_REMINDER_OPT_IN);
    }
}
