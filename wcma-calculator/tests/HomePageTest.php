<?php
// wcma-calculator/tests/HomePageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../events-lib.php';
require_once __DIR__ . '/../home-page.php';
require_once __DIR__ . '/../reminders-lib.php';

use PHPUnit\Framework\TestCase;

final class HomePageTest extends TestCase
{
    private function item(string $kind, int $id, string $state, string $label, ?array $action = null, ?array $atTrack = null): array {
        return ['kind' => $kind, 'subject_type' => $kind === 'gear' ? 'driver' : 'car', 'subject_id' => $id, 'state' => $state,
                'label' => $label, 'detail' => '', 'action' => $action, 'at_track' => $atTrack];
    }

    private function vm(array $o = []): array {
        return array_merge([
            'name' => 'Jordan Lee',
            'readiness' => ['events' => [[
                'event' => ['id' => 10, 'name' => 'Fall Sprint', 'event_date' => '2099-10-11'],
                'items' => [
                    $this->item('tech_sheet', 3, 'todo', 'Submit a tech sheet for #42', ['label' => 'Submit tech sheet', 'url' => 'tech-sheets.php?action=new&car_id=3&event_id=10']),
                    $this->item('car_tech', 3, 'todo', 'Car tech for #42', null, ['subject_type' => 'car', 'subject_id' => 3, 'season' => 2099]),
                    $this->item('declaration', 3, 'info', 'Class declaration for #42 is with an inspector'),
                    $this->item('gear', 5, 'done', 'Gear for Jordan Lee: pre-teched 2099'),
                ],
            ]], 'untagged' => [['id' => 11, 'name' => 'Season Finale', 'event_date' => '2099-10-25']]],
            'cars' => [3 => ['id' => 3, 'car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000']],
            'garage' => [],
            'drivers' => [],
            'seasonLinks' => [['label' => 'Waiver <2026>', 'url' => 'https://x.test/a?b=1&c=2']],
            'csrf' => 'tok',
        ], $o);
    }

    public function testHeadlineVariants(): void
    {
        $r = $this->vm()['readiness'];
        $this->assertSame('2 things to do before Fall Sprint', homeHeadline($r));
        $r['events'][0]['items'] = array_values(array_filter($r['events'][0]['items'], fn($i) => $i['state'] !== 'todo'));
        $this->assertSame('You\'re all set for Fall Sprint ✓', homeHeadline($r));
        $this->assertSame('Which events are you going to?', homeHeadline(['events' => [], 'untagged' => []]));
        $one = $this->vm()['readiness'];
        array_pop($one['events'][0]['items']); array_pop($one['events'][0]['items']); array_pop($one['events'][0]['items']);
        $this->assertSame('1 thing to do before Fall Sprint', homeHeadline($one));
    }

    public function testHomeRendersNumberedTodosSecondaryActionAndSections(): void
    {
        $html = renderHomeHtml($this->vm());
        $this->assertStringContainsString('class="hub-todo"', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=new&amp;car_id=3&amp;event_id=10"', $html);
        $this->assertStringContainsString("I'll do it at the track", $html);
        $this->assertStringContainsString('name="action" value="at-track"', $html);
        $this->assertStringContainsString('name="csrf_token" value="tok"', $html);
        $this->assertStringContainsString('With an inspector', $html);
        $this->assertStringContainsString('<details class="hub-done"><summary>1 with an inspector · 1 already done</summary>', $html);
        $this->assertStringContainsString('Already done for Fall Sprint', $html);
        $this->assertStringNotContainsString('<details class="hub-done" open', $html);   // starts closed
        // One card per event, soonest first, with the event's to-do badge in its header.
        $this->assertStringContainsString('<h3>Fall Sprint</h3><span class="hub-event-date">Sun, Oct 11</span><span class="hub-status hub-status--todo">2 things to do</span>', $html);
        $this->assertLessThan(strpos($html, '<h3>Season Finale</h3>'), strpos($html, '<h3>Fall Sprint</h3>'));
        $this->assertSame(1, substr_count($html, EVENTS_NOT_REGISTERING));
        $this->assertStringNotContainsString('Next after that', $html);
        $this->assertStringContainsString('Season Finale', $html);
        $this->assertStringContainsString("I'm going", $html);
        $this->assertStringContainsString(EVENTS_NOT_REGISTERING, $html);
        $this->assertStringContainsString('href="garage.php">Open garage', $html);
        $this->assertStringNotContainsString('Clubs would like to feature you', $html);

        $withPrompt = renderHomeHtml($this->vm(['mediaPrompt' => true]));
        $this->assertStringContainsString('Clubs would like to feature you', $withPrompt);
        // The invitation is a quiet strip after "At a glance", before the MotorsportReg links.
        $this->assertGreaterThan(strpos($withPrompt, '<h2>At a glance</h2>'), strpos($withPrompt, 'Clubs would like to feature you'));
        $this->assertLessThan(strpos($withPrompt, 'This season on MotorsportReg'), strpos($withPrompt, 'Clubs would like to feature you'));
    }

    public function testMediaPromptCardAppearsOnlyWhenAsked(): void
    {
        $html = homeMediaPromptHtml('tok');
        $this->assertStringContainsString('Clubs would like to feature you', $html);
        $this->assertStringContainsString('href="media-profile.php?driver_id=self"', $html);
        $this->assertStringContainsString('name="action" value="media-prompt-dismiss"', $html);
        $this->assertStringContainsString('name="csrf_token" value="tok"', $html);
    }

    public function testMediaPromptInAttentionModeShowsEachDriverNoteAndNoDismiss(): void
    {
        $html = homeMediaPromptHtml('tok', ['kind' => 'attention', 'items' => [
            ['driverId' => 5, 'name' => 'Jordan <Lee>', 'state' => 'sent_back', 'note' => 'Brighter <photo> please'],
            ['driverId' => 6, 'name' => 'Sam Patel', 'state' => 'hidden', 'note' => 'Sponsor dispute'],
        ]]);
        $this->assertStringContainsString('media-prompt', $html);
        $this->assertStringContainsString('Jordan &lt;Lee&gt;', $html);
        $this->assertStringContainsString('Brighter &lt;photo&gt; please', $html);
        $this->assertStringContainsString('Public page sent back', $html);
        $this->assertStringContainsString('Hidden by WCMA', $html);
        $this->assertStringContainsString('Sponsor dispute', $html);
        $this->assertStringContainsString('href="media-profile.php?driver_id=5">Fix profile</a>', $html);
        $this->assertStringContainsString('href="media-profile.php?driver_id=6">Fix profile</a>', $html);
        $this->assertStringNotContainsString('media-prompt-dismiss', $html);
        $this->assertStringNotContainsString('Clubs would like to feature you', $html);
        $this->assertStringNotContainsString('approv', strtolower($html));

        $home = renderHomeHtml($this->vm(['mediaPrompt' => ['kind' => 'attention', 'items' => [
            ['driverId' => 5, 'name' => 'Jordan Lee', 'state' => 'sent_back', 'note' => 'x']]]]));
        $this->assertLessThan(strpos($home, '<h2>At a glance</h2>'), strpos($home, 'Fix profile'));
    }

    public function testSeasonLinksAreEscapedAndOmittedWhenEmpty(): void
    {
        $html = renderHomeHtml($this->vm());
        $this->assertStringContainsString('Waiver &lt;2026&gt;', $html);
        $this->assertStringContainsString('href="https://x.test/a?b=1&amp;c=2"', $html);
        $this->assertStringContainsString('rel="noopener"', $html);
        $this->assertStringNotContainsString('MotorsportReg', renderHomeHtml($this->vm(['seasonLinks' => []])));
    }

    public function testEmptyStates(): void
    {
        $noCars = renderHomeHtml($this->vm(['cars' => [], 'readiness' => ['events' => [], 'untagged' => []]]));
        $this->assertStringContainsString('Start by adding your car and declaring its class', $noCars);
        $this->assertStringContainsString('href="garage.php?action=add"', $noCars);
        $this->assertStringNotContainsString('<div class="hub-card"><h3>Garage</h3><div class="hub-card">', $noCars);
        $noEvents = renderHomeHtml($this->vm(['readiness' => ['events' => [], 'untagged' => []]]));
        $this->assertStringContainsString('No upcoming events yet.', $noEvents);
    }

    public function testAtAGlanceLabelsClassCarTechAndGearTech(): void
    {
        $html = renderHomeHtml($this->vm([
            'garage' => [
                ['car' => ['id' => 3, 'car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000 <R>'],
                 'declaration' => ['calculated_class' => 'GT3', 'review_status' => 'submitted'],
                 'techLabel' => 'Needs tech at the track', 'techState' => 'none'],
                ['car' => ['id' => 4, 'car_number' => '7', 'year' => '', 'make' => 'Mazda', 'model' => 'MX-5'],
                 'declaration' => null, 'techLabel' => 'Teched 2099', 'techState' => 'accepted'],
            ],
            'drivers' => [['name' => 'Jordan <Lee>', 'isSelf' => true, 'gearLabel' => 'Needs gear check at the track', 'gearState' => 'none']],
        ]));
        $this->assertStringContainsString('<span class="hub-plate hub-plate--sm">42</span><span class="hub-glance-name">2004 Honda S2000 &lt;R&gt;</span><span class="hub-class">GT3</span>', $html);
        $this->assertStringContainsString('<span class="hub-pill hub-status hub-status--info"><span class="hub-pill-k">Class:</span> With an inspector</span>', $html);
        $this->assertStringContainsString('<span class="hub-pill hub-status hub-status--warn"><span class="hub-pill-k">Car tech:</span> Needs tech at the track</span>', $html);
        $this->assertStringContainsString('<span class="hub-glance-name">Mazda MX-5</span></div>', $html);   // no class badge
        $this->assertStringContainsString('Class:</span> Not declared</span><a href="calculator.php?car=4">Declare class</a>', $html);
        $this->assertStringContainsString('<span class="hub-pill hub-status hub-status--ok"><span class="hub-pill-k">Car tech:</span> Teched 2099</span>', $html);
        $this->assertStringContainsString('<span class="hub-glance-name">Jordan &lt;Lee&gt; (you)</span>', $html);
        $this->assertStringContainsString('<span class="hub-pill hub-status hub-status--warn"><span class="hub-pill-k">Gear tech:</span> Needs gear check at the track</span>', $html);
        // The "doesn't register you" rule introduces the event cards instead of trailing after them.
        $this->assertLessThan(strpos($html, '<h3>Fall Sprint</h3>'), strpos($html, EVENTS_NOT_REGISTERING));
        $this->assertGreaterThan(strpos($html, '<h2>Upcoming events</h2>'), strpos($html, EVENTS_NOT_REGISTERING));
    }

    public function testTagFormOffersOnlyCarsNotYetTaggedToAGoingToEvent(): void
    {
        $vm = $this->vm([
            'cars' => [
                3 => ['id' => 3, 'car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000'],
                4 => ['id' => 4, 'car_number' => '7', 'year' => '2010', 'make' => 'Mazda', 'model' => 'MX-5'],
            ],
        ]);
        $html = renderHomeHtml($vm);
        // Car 3 is tagged to event 10 (has a tech_sheet item), so it gets the untag form...
        $this->assertStringContainsString('#42 2004 Honda S2000', $html);
        $this->assertStringContainsString('Not going anymore', $html);
        // ...while car 4 is not tagged, so it's offered a tag form for event 10, car 4 only.
        $this->assertStringContainsString(
            'name="action" value="tag"><input type="hidden" name="event_id" value="10">'
            . '<span>#7 2010 Mazda MX-5</span><input type="hidden" name="car_id" value="4">',
            $html
        );
        // Both are inside Fall Sprint's card, before the next event's card starts.
        $fall = strpos($html, '<h3>Fall Sprint</h3>');
        $finale = strpos($html, '<h3>Season Finale</h3>');
        $this->assertGreaterThan($fall, strpos($html, 'Not going anymore'));
        $this->assertLessThan($finale, strpos($html, 'value="4"><button type="submit" class="hub-btn">I\'m going'));
        // An event you're not going to offers both cars in a labelled picker.
        $this->assertStringContainsString('<label class="visually-hidden" for="tag-car-11">Car for Season Finale</label><select id="tag-car-11" name="car_id">', $html);
    }

    public function testAlreadyDoneSectionOmittedWhenNoDoneItems(): void
    {
        $vm = $this->vm();
        $vm['readiness']['events'][0]['items'] = array_values(array_filter(
            $vm['readiness']['events'][0]['items'],
            fn($i) => $i['state'] !== 'done'
        ));
        $html = renderHomeHtml($vm);
        $this->assertStringNotContainsString('Already done', $html);
        $this->assertStringContainsString('<summary>1 with an inspector</summary>', $html);

        // With nothing with an inspector and nothing done, there is no summary line at all.
        $vm['readiness']['events'][0]['items'] = array_values(array_filter(
            $vm['readiness']['events'][0]['items'],
            fn($i) => $i['state'] === 'todo'
        ));
        $this->assertStringNotContainsString('hub-done', renderHomeHtml($vm));
    }

    public function testLandingOffersCalculatorAndSignIn(): void
    {
        $html = renderLandingHtml([['label' => 'Waiver', 'url' => 'https://x.test']]);
        foreach (['href="calculator.php"', 'action=login', 'action=register', 'Waiver'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function testLandingHeadingIsShortWithTaglineBelow(): void
    {
        $html = renderLandingHtml([]);
        $this->assertStringContainsString('<h1>WCMA Hub</h1>', $html);
        $this->assertStringContainsString('<p class="hub-hero-tagline">Declare your class', $html);
    }

    public function testTagFormsOfferRemindersOnlyWhenAsked(): void
    {
        $offered = renderHomeHtml($this->vm(['offerReminders' => true]));
        $this->assertStringContainsString('name="offer_reminders" value="1"', $offered);
        $this->assertStringContainsString('Email me reminders for events I&#039;m going to.', $offered);
        $this->assertStringNotContainsString('offer_reminders', renderHomeHtml($this->vm()));
        $this->assertStringNotContainsString('offer_reminders', renderHomeHtml($this->vm(['offerReminders' => false])));
    }

    public function testTagFormWrapsOnNarrowScreens(): void
    {
        $this->assertStringContainsString('class="hub-line hub-tag-form"', renderHomeHtml($this->vm()));
    }

    public function testNoBannedWording(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', file_get_contents(__DIR__ . '/../home-page.php'));
    }

    public function testIceAtTrackFormCarriesDisciplineAndClub(): void
    {
        $item = ['kind' => 'car_tech', 'subject_type' => 'car', 'subject_id' => 3, 'state' => 'todo', 'label' => 'Ice car tech for #42 at NASCC',
                 'detail' => '', 'action' => null, 'at_track' => ['subject_type' => 'car', 'subject_id' => 3, 'season' => 2027, 'discipline' => 'ice', 'club' => 'NASCC']];
        $html = homeRenderTodoItem(1, $item, 'tok');
        $this->assertStringContainsString('<input type="hidden" name="discipline" value="ice">', $html);
        $this->assertStringContainsString('<input type="hidden" name="club" value="NASCC">', $html);

        $summer = ['at_track' => ['subject_type' => 'car', 'subject_id' => 3, 'season' => 2026]] + $item;
        $this->assertStringNotContainsString('name="discipline"', homeRenderTodoItem(1, $summer, 'tok'));
    }

    public function testIceEventCardShowsTheClubBadge(): void
    {
        $event = ['id' => 20, 'name' => 'NASCC Ice #1', 'event_date' => '2027-01-10', 'discipline' => 'ice', 'host_club' => 'NASCC'];
        $this->assertStringContainsString('<span class="hub-status hub-status--info">Ice · NASCC</span>', homeEventCardHtml($event, null, [], 'tok', false));
        $summer = ['id' => 10, 'name' => 'Fall Sprint', 'event_date' => '2026-10-11'];
        $this->assertStringNotContainsString('Ice ·', homeEventCardHtml($summer, null, [], 'tok', false));
    }
}
