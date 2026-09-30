<?php
// wcma-calculator/tests/UxReviewTest.php — behaviour added by the 2026-09-30 UX review
// (docs/ux-review-2026-09-30.md): dates, Home's attention list and first-run steps, folded event
// forms, the untag confirm, admin event order and photo status words.
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../events-lib.php';
require_once __DIR__ . '/../clubs-lib.php';
require_once __DIR__ . '/../home-page.php';
require_once __DIR__ . '/../reminders-lib.php';
require_once __DIR__ . '/../garage-page.php';
require_once __DIR__ . '/../ice-rules.php';
require_once __DIR__ . '/../admin-ui.php';
require_once __DIR__ . '/../admin-events.php';
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../pretech-lib.php';
require_once __DIR__ . '/../pretech-page.php';
require_once __DIR__ . '/../admin-tech-sheets.php';

use PHPUnit\Framework\TestCase;

final class UxReviewTest extends TestCase
{
    private function car(int $id = 3, string $n = '42'): array {
        return ['id' => $id, 'car_number' => $n, 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000'];
    }

    private function homeVm(array $o = []): array {
        return array_merge([
            'name' => 'Jordan Lee', 'readiness' => ['events' => [], 'untagged' => []],
            'cars' => [3 => $this->car()], 'garage' => [], 'drivers' => [], 'seasonLinks' => [], 'csrf' => 'tok',
        ], $o);
    }

    private function garageRow(array $o = []): array {
        return array_merge(['car' => $this->car(), 'declaration' => ['id' => 8, 'review_status' => 'accepted', 'calculated_class' => 'GT3'],
            'techState' => 'none', 'techLabel' => 'Needs tech at the track', 'usesSummer' => true, 'usesRace' => true], $o);
    }

    // ---- M10: three date forms ----

    public function testDateHelpers(): void
    {
        $this->assertSame('Fri, Oct 16, 2026', hubEventDate('2026-10-16'));
        $this->assertSame('Fri, Oct 16, 2026', hubEventDate('2026-10-16 09:30:00'));
        $this->assertSame('Sep 29, 2026', hubDate('2026-09-29 23:04:00'));
        $this->assertSame('Sep 29, 2026, 11:04 PM', hubDateTime('2026-09-29 23:04:00'));
        foreach (['hubEventDate', 'hubDate', 'hubDateTime'] as $fn) {
            $this->assertSame('', $fn(null), $fn);
            $this->assertSame('', $fn(''), $fn);
            $this->assertSame('', $fn('not a date'), $fn);
        }
    }

    // ---- H3: sent-back work shows on Home whatever is tagged ----

    public function testAttentionListsASentBackDeclarationWithTheInspectorsNote(): void
    {
        $vm = $this->homeVm(['garage' => [$this->garageRow(['declaration' =>
            ['id' => 8, 'review_status' => 'needs_changes', 'calculated_class' => 'GT3', 'reviewer_note' => 'Attach a <dyno> sheet.']])]]);
        $items = homeAttentionItems($vm);
        $this->assertCount(1, $items);
        $this->assertSame('Your class declaration for #42 needs changes', $items[0]['label']);
        $this->assertSame('Inspector\'s note: Attach a <dyno> sheet.', $items[0]['detail']);
        $this->assertSame(['label' => 'Re-declare class', 'url' => 'calculator.php?car=3'], $items[0]['action']);

        $html = renderHomeHtml($vm);
        $this->assertStringContainsString('<h1 id="todo" tabindex="-1">1 thing needs your attention</h1>', $html);
        $this->assertStringContainsString('class="hub-todo hub-todo--attention"', $html);
        $this->assertStringContainsString('Attach a &lt;dyno&gt; sheet.', $html);
        $this->assertStringContainsString('href="calculator.php?car=3">Re-declare class</a>', $html);
        // The attention list comes before the events, so it is the first thing on the page.
        $this->assertLessThan(strpos($html, 'id="events"'), strpos($html, 'hub-todo--attention'));
    }

    public function testAttentionListsPhotosToRetakeForCarsAndDrivers(): void
    {
        $vm = $this->homeVm([
            'garage' => [$this->garageRow(['techState' => 'needs_changes', 'techRetakeUrl' => 'tech-sheets.php?action=pretech&id=12'])],
            'drivers' => [
                ['id' => 5, 'name' => 'Jordan Lee', 'isSelf' => true, 'gearState' => 'needs_changes', 'gearLabel' => 'Photos need changes',
                 'gearRetakeUrl' => 'gear.php?action=pretech&id=9', 'showSummer' => true, 'ice' => null],
                ['id' => 6, 'name' => 'Sam Patel', 'isSelf' => false, 'gearState' => 'none', 'gearLabel' => 'Needs gear tech', 'showSummer' => true,
                 'ice' => ['state' => 'needs_changes', 'label' => 'Photos need changes', 'gearId' => 14]],
            ],
        ]);
        $items = homeAttentionItems($vm);
        $this->assertSame(['Retake car photos for #42', 'Retake gear photos for Jordan Lee', 'Retake ice gear photos for Sam Patel'], array_column($items, 'label'));
        $this->assertSame('tech-sheets.php?action=pretech&id=12', $items[0]['action']['url']);
        $this->assertSame('gear.php?action=pretech&id=9', $items[1]['action']['url']);
        $this->assertSame('gear.php?action=pretech&id=14', $items[2]['action']['url']);
        $this->assertSame('3 things need your attention', homeHeadline(['events' => [], 'untagged' => []], 0, ['attention' => 3]));
    }

    public function testAttentionLeavesOutWhatTheEventListAlreadyAsksFor(): void
    {
        $vm = $this->homeVm(['garage' => [$this->garageRow(['declaration' => ['id' => 8, 'review_status' => 'needs_changes', 'calculated_class' => 'GT3']])]]);
        $shown = [['kind' => 'declaration', 'subject_type' => 'car', 'subject_id' => 3, 'state' => 'todo', 'label' => 'x', 'detail' => '', 'action' => null, 'at_track' => null]];
        $this->assertSame([], homeAttentionItems($vm, $shown));
        // ...but an item that is only "with an inspector" in the event list doesn't hide it.
        $shown[0]['state'] = 'info';
        $this->assertCount(1, homeAttentionItems($vm, $shown));
        // Nothing sent back: nothing listed, and no heading.
        $this->assertSame([], homeAttentionItems($this->homeVm(['garage' => [$this->garageRow()]])));
        $this->assertSame('', homeAttentionHtml([], 'tok', true));
    }

    public function testATaDriftOnlyCarNeverGetsAClassAttentionItem(): void
    {
        $vm = $this->homeVm(['garage' => [$this->garageRow(['usesRace' => false,
            'declaration' => ['id' => 8, 'review_status' => 'needs_changes', 'calculated_class' => 'GT3']])]]);
        $this->assertSame([], homeAttentionItems($vm));
    }

    // ---- M1: first-run steps ----

    public function testFirstRunStepsLeadWithAddingACarThenDeclaringItsClass(): void
    {
        $none = homeGettingStartedHtml($this->homeVm(['cars' => []]));
        $this->assertStringContainsString('<strong>Add your car</strong>', $none);
        $this->assertStringContainsString('href="garage.php?action=add">Add a car</a>', $none);
        $this->assertStringNotContainsString('Declare class</a>', $none);   // only the first open step has a button
        $this->assertSame('Start by adding your car', homeHeadline(['events' => [], 'untagged' => []], 0, ['hasCars' => false]));

        $undeclared = homeGettingStartedHtml($this->homeVm(['garage' => [$this->garageRow(['declaration' => null])]]));
        $this->assertStringContainsString('hub-todo-item--done', $undeclared);      // step 1 is ticked
        $this->assertStringContainsString('href="calculator.php?car=3">Declare class</a>', $undeclared);
        $this->assertStringNotContainsString('href="garage.php?action=add"', $undeclared);

        // A car with a class, or one that doesn't race (ice, TA/Drift): straight to events.
        $this->assertSame('', homeGettingStartedHtml($this->homeVm(['garage' => [$this->garageRow()]])));
        $this->assertSame('', homeGettingStartedHtml($this->homeVm(['garage' => [$this->garageRow(['declaration' => null, 'usesRace' => false])]])));
    }

    public function testTheEventsHeadingIsNotRepeatedUnderATitleThatAlreadyAsks(): void
    {
        $untagged = ['events' => [], 'untagged' => [['id' => 11, 'name' => 'Season Finale', 'event_date' => '2099-10-25']]];
        $ready = renderHomeHtml($this->homeVm(['readiness' => $untagged, 'garage' => [$this->garageRow()]]));
        $this->assertStringContainsString('Which events are you going to?</h1>', $ready);
        $this->assertStringNotContainsString('<h2 id="events">Upcoming events</h2>', $ready);
        $new = renderHomeHtml($this->homeVm(['readiness' => $untagged, 'cars' => []]));
        $this->assertStringContainsString('<h2 id="events">Upcoming events</h2>', $new);
    }

    // ---- H4: an event is one row until "I'm going" is tapped ----

    public function testTagFormFoldsBehindItsButton(): void
    {
        $summer = ['id' => 10, 'name' => 'Fall Sprint', 'event_date' => '2099-10-15', 'discipline' => 'summer', 'host_club' => 'WSCC'];
        $html = homeRenderTagForm($summer, [3 => $this->car()], 'tok');
        $this->assertStringStartsWith('<details class="hub-event-add"><summary class="hub-btn">I\'m going</summary>', $html);
        $this->assertStringContainsString('<button type="submit" class="hub-btn">Confirm I\'m going</button>', $html);
        $this->assertStringNotContainsString(' open', substr($html, 0, 40));

        $another = homeRenderTagForm($summer, [3 => $this->car()], 'tok', false, ['race'], true);
        $this->assertStringContainsString('<summary class="hub-btn hub-btn--secondary">Add another car</summary>', $another);

        // An ice event with one car and no reminder question has nothing to choose: one tap.
        $ice = ['id' => 20, 'name' => 'Ice #1', 'event_date' => '2099-01-10', 'discipline' => 'ice', 'host_club' => 'NASCC'];
        $direct = homeRenderTagForm($ice, [3 => $this->car()], 'tok');
        $this->assertStringNotContainsString('<details', $direct);
        $this->assertStringContainsString('<button type="submit" class="hub-btn">I\'m going</button>', $direct);
        // The reminder question is asked inside the folded form, so it is seen once, not on every card.
        $asked = homeRenderTagForm($ice, [3 => $this->car()], 'tok', true);
        $this->assertStringContainsString('<details class="hub-event-add">', $asked);
        $this->assertStringContainsString('name="offer_reminders" value="1"', $asked);
    }

    // ---- M5: untag asks first ----

    public function testNotGoingAnymoreAsksFirstAndSaysWhatIsKept(): void
    {
        $event = ['id' => 10, 'name' => 'Fall <Sprint>', 'event_date' => '2099-10-15'];
        $html = homeRenderUntagForm($event, $this->car(), 'tok');
        $this->assertStringContainsString('data-confirm="Take #42 2004 Honda S2000 off Fall &lt;Sprint&gt;?', $html);
        $this->assertStringContainsString('A tech sheet you already sent is kept.', $html);
    }

    // ---- M12: admin events ----

    public function testAdminEventsListComingEventsSoonestFirstThenPastNewestFirst(): void
    {
        $e = fn(int $id, string $date): array => ['id' => $id, 'event_date' => $date];
        $sorted = adminEventsInListOrder([$e(1, '2026-11-20'), $e(2, '2026-08-01'), $e(3, '2026-10-16'), $e(4, '2026-09-01'), $e(5, '2026-09-30')], '2026-09-30');
        $this->assertSame([5, 3, 1, 4, 2], array_column($sorted, 'id'));
    }

    public function testAdminEventsFlagAComingEventWithNoHostClub(): void
    {
        $clubs = [['code' => 'WSCC', 'name' => 'Winnipeg', 'msr_url' => '', 'active' => 1]];
        $events = [
            ['id' => 7, 'name' => 'Fall Sprint', 'event_date' => '2026-10-16', 'location' => null, 'discipline' => 'summer', 'host_club' => null, 'active' => 1],
            ['id' => 8, 'name' => 'Spring Opener', 'event_date' => '2026-05-01', 'location' => null, 'discipline' => 'summer', 'host_club' => null, 'active' => 1],
            ['id' => 9, 'name' => 'Time Attack', 'event_date' => '2026-10-23', 'location' => null, 'discipline' => 'summer', 'host_club' => 'WSCC', 'active' => 1],
        ];
        $html = renderEventsPageHtml($events, [], $clubs, 'tok', null, null, ['pending' => 0, 'connected' => false], '2026-09-30');
        $this->assertStringContainsString('1 coming event has no host club.', $html);
        $this->assertSame(1, substr_count($html, '>No host club</span>'));   // the past event keeps its dash
        $none = renderEventsPageHtml([$events[2]], [], $clubs, 'tok', null, null, ['pending' => 0, 'connected' => false], '2026-09-30');
        $this->assertStringNotContainsString('no host club', $none);
    }

    // ---- M6: photo status words ----

    public function testPhotoStatusIsAmberOnlyWhenAPhotoIsNeeded(): void
    {
        $this->assertSame('Photo needed', pretechPhotoStatusText(null, 'required'));
        $this->assertSame('Optional', pretechPhotoStatusText(null, 'recommended'));
        $this->assertSame('Only if it applies', pretechPhotoStatusText(null, 'conditional'));
        $this->assertSame('Photo needed', pretechPhotoStatusText(null, 'conditional', true));
        $this->assertTrue(pretechPhotoNeeded('required', false));
        $this->assertFalse(pretechPhotoNeeded('recommended', true));
        $this->assertFalse(pretechPhotoNeeded('conditional', false));
    }

    // ---- M4: inspector page titles ----

    public function testInspectorSheetTitleNamesTheCarAndEvent(): void
    {
        $sheet = ['id' => 1, 'car_number' => '17', 'car_make' => 'Mazda', 'car_model' => 'Miata'];
        $this->assertSame('#17 Mazda Miata — Fall Sprint', techSheetReviewTitle($sheet, ['name' => 'Fall Sprint']));
        $this->assertSame('#17 Mazda Miata', techSheetReviewTitle($sheet, []));
    }

    // ---- M7: the car page has one main button ----

    public function testReDeclareIsTheMainButtonOnlyWhenItIsNeeded(): void
    {
        $src = file_get_contents(__DIR__ . '/../garage-page.php');
        $this->assertStringContainsString("\$needsDeclaring = \$cur === null || (string)\$cur['review_status'] === 'needs_changes';", $src);
        $this->assertStringContainsString("'<a class=\"hub-btn' . (\$needsDeclaring ? '' : ' hub-btn--secondary')", $src);
    }
}
