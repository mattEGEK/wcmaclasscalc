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

    public function testTagFormOffersFormatsForASummerEventWithTheDefaultsTicked(): void
    {
        $event = ['id' => 21, 'name' => 'WSCC TA #3', 'event_date' => '2099-09-01', 'discipline' => 'summer', 'host_club' => 'WSCC'];
        $cars = [3 => ['id' => 3, 'car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ']];
        $html = homeRenderTagForm($event, $cars, 'tok', false, ['ta', 'drift']);
        $this->assertStringContainsString('<input type="hidden" name="formats_shown" value="1">', $html);
        $this->assertStringContainsString('<input type="checkbox" name="formats[]" value="race"> Race</label>', $html);
        $this->assertStringContainsString('<input type="checkbox" name="formats[]" value="ta" checked> Time Attack</label>', $html);
        $this->assertStringContainsString('<input type="checkbox" name="formats[]" value="drift" checked> Drift</label>', $html);
        $this->assertStringContainsString('<input type="checkbox" name="supps_ack" value="1"> For Time Attack and Drift: I have read the WSCC supplementary regulations and my car complies</label>', $html);
    }

    // UX review 2026-09-30 §M12: no host club means Race only, with no disabled boxes and no "ask an admin".
    public function testNoHostClubOffersRaceOnly(): void
    {
        $event = ['id' => 22, 'name' => 'Open Day', 'event_date' => '2099-09-01', 'discipline' => 'summer', 'host_club' => null];
        $html = homeFormatsFieldsHtml($event, ['race']);
        $this->assertStringContainsString('<input type="hidden" name="formats[]" value="race">', $html);
        $this->assertStringContainsString('<strong>Running:</strong> Race', $html);
        $this->assertStringNotContainsString('value="ta"', $html);
        $this->assertStringNotContainsString('value="drift"', $html);
        $this->assertStringNotContainsString('disabled', $html);
        $this->assertStringNotContainsString(h(ENTRY_NO_HOST_CLUB), $html);
        $this->assertStringNotContainsString('supps_ack', $html);
    }

    public function testUnknownEventKeepsEveryBoxAndGenericWording(): void
    {
        $html = homeFormatsFieldsHtml(null, ['race']);
        $this->assertStringNotContainsString('disabled', $html);
        $this->assertStringContainsString(h("For Time Attack and Drift: I have read the host club's supplementary regulations and my car complies"), $html);
    }

    public function testIceEventHasNoFormatPicker(): void
    {
        $event = ['id' => 30, 'name' => 'Ice #1', 'event_date' => '2099-01-10', 'discipline' => 'ice', 'host_club' => 'WSCC'];
        $html = homeRenderTagForm($event, [3 => ['id' => 3, 'car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ']], 'tok');
        $this->assertStringNotContainsString('formats', $html);
    }

    public function testGoingCarShowsItsFormatsAndAChangeForm(): void
    {
        $html = renderHomeHtml($this->vm([$this->item('tech_sheet', 'todo', 'Submit a TA/Drift tech sheet for #86')]));
        $this->assertStringContainsString('<details class="hub-entry-formats"><summary>Time Attack · <span class="hub-entry-change">Change</span></summary>', $html);
        $this->assertStringContainsString('<input type="hidden" name="action" value="formats">', $html);
        $this->assertStringContainsString('<input type="checkbox" name="formats[]" value="ta" checked> Time Attack</label>', $html);
    }

    public function testTaDriftOnlyCarsAreNotOfferedIceEvents(): void
    {
        $cars = [1 => ['id' => 1, 'disciplines' => 'ta_drift'], 2 => ['id' => 2, 'disciplines' => 'ice'], 3 => ['id' => 3, 'disciplines' => 'summer'], 4 => ['id' => 4]];
        $this->assertSame([2, 4], array_keys(homeCarsForEvent($cars, ['discipline' => 'ice'])));
        $this->assertSame([1, 3, 4], array_keys(homeCarsForEvent($cars, ['discipline' => 'summer'])));
    }

    public function testFormatsFromPost(): void
    {
        $this->assertNull(entryFormatsFromPost(['action' => 'tag']));
        $this->assertSame([], entryFormatsFromPost(['formats_shown' => '1']));
        $this->assertSame([], entryFormatsFromPost(['formats_shown' => '1', 'formats' => 'ta']));
        $this->assertSame(['ta', 'drift'], entryFormatsFromPost(['formats_shown' => '1', 'formats' => ['x' => 'ta', 'y' => 'drift']]));
    }
}
