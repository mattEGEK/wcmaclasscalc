<?php
// wcma-calculator/tests/HomeTaDriftTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../events-lib.php';
require_once __DIR__ . '/../clubs-lib.php';
require_once __DIR__ . '/../reminders-lib.php';
require_once __DIR__ . '/../home-page.php';

use PHPUnit\Framework\TestCase;

final class HomeTaDriftTest extends TestCase
{
    private function item(string $kind, string $state, string $label, ?array $action = null, ?array $atTrack = null): array {
        return ['kind' => $kind, 'subject_type' => $kind === 'gear' ? 'driver' : 'car', 'subject_id' => 3, 'state' => $state,
                'label' => $label, 'detail' => '', 'action' => $action, 'at_track' => $atTrack];
    }

    private function vm(array $items, array $o = []): array {
        return array_merge([
            'name' => 'Jordan Lee',
            'readiness' => ['events' => [[
                'event' => ['id' => 20, 'name' => 'WSCC TA #2', 'event_date' => '2099-08-16', 'discipline' => 'summer', 'host_club' => 'WSCC'],
                'items' => $items,
                'entries' => [3 => ['formats' => ['ta'], 'tier' => 'ta_drift', 'supps_ack_at' => null]],
            ]], 'untagged' => []],
            'cars' => [3 => ['id' => 3, 'car_number' => '86', 'year' => '2017', 'make' => 'Subaru', 'model' => 'BRZ', 'disciplines' => 'ta_drift']],
            'garage' => [], 'drivers' => [], 'seasonLinks' => [], 'csrf' => 'tok',
        ], $o);
    }

    public function testSuggestedItemsAreListedApartAndNeverCounted(): void
    {
        $suggested = $this->item('tech_sheet', 'suggested', 'Check your car for WSCC TA #2 (recommended)',
            ['label' => 'Go through the tech sheet', 'url' => 'tech-sheets.php?action=new-ta-drift&car_id=3&event_id=20']);
        $html = renderHomeHtml($this->vm([$suggested, $this->item('car_tech', 'done', 'TA/Drift car tech 2099 for #86 at WSCC: teched')]));

        $this->assertStringContainsString(h("You're all set for WSCC TA #2"), $html);
        $this->assertStringContainsString('<h2>Recommended</h2><ul class="hub-todo hub-todo--suggested">', $html);
        $this->assertStringContainsString('<li class="hub-todo-item hub-todo-item--optional">', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=new-ta-drift&amp;car_id=3&amp;event_id=20">Go through the tech sheet</a>', $html);
        $this->assertStringContainsString('<span class="hub-status hub-status--ok">All set</span>', $html);
        $this->assertStringNotContainsString('<ol class="hub-todo">', $html);
    }

    public function testNoRecommendedHeadingWithoutSuggestedItems(): void
    {
        $html = renderHomeHtml($this->vm([$this->item('supps', 'todo', "Confirm you've read the WSCC supplementary regulations for #86")]));
        $this->assertStringNotContainsString('Recommended', $html);
        $this->assertStringContainsString('1 thing to do before WSCC TA #2', $html);
    }

    public function testEventCardsHaveAnAnchor(): void
    {
        $html = renderHomeHtml($this->vm([]));
        $this->assertStringContainsString('<section class="hub-card hub-event" id="event-20">', $html);
    }

    public function testTaDriftAtTrackFormSendsTheTierAndClub(): void
    {
        $item = $this->item('car_tech', 'todo', 'TA/Drift car tech for #86 at WSCC', null,
            ['subject_type' => 'car', 'subject_id' => 3, 'season' => 2099, 'discipline' => 'ta_drift', 'club' => 'WSCC']);
        $html = renderHomeHtml($this->vm([$item]));
        $this->assertStringContainsString('<input type="hidden" name="discipline" value="ta_drift">', $html);
        $this->assertStringContainsString('<input type="hidden" name="club" value="WSCC">', $html);
    }

    public function testRemindersLeaveSuggestedItemsOut(): void
    {
        $readiness = ['events' => [[
            'event' => ['id' => 20, 'name' => 'WSCC TA #2', 'event_date' => '2026-07-12'],
            'items' => [$this->item('tech_sheet', 'suggested', 'Check your car'), $this->item('supps', 'todo', 'Confirm')],
        ]], 'untagged' => []];
        $digests = reminderDigests($readiness, '2026-07-05');
        $this->assertSame(['Confirm'], array_column($digests[0]['items'], 'label'));
        $readiness['events'][0]['items'] = [$this->item('tech_sheet', 'suggested', 'Check your car')];
        $this->assertSame([], reminderDigests($readiness, '2026-07-05'));
    }
}
