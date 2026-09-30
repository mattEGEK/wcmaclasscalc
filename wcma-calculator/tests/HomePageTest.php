<?php
// wcma-calculator/tests/HomePageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../events-lib.php';
require_once __DIR__ . '/../clubs-lib.php';
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
        $this->assertStringContainsString('<h3>Fall Sprint</h3><span class="hub-event-date">Sun, Oct 11, 2099</span><a class="hub-status hub-status--todo hub-todo-link" href="#todo">2 things to do</a>', $html);
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
        $this->assertStringContainsString('<h1 id="todo" tabindex="-1">Start by adding your car</h1>', $noCars);
        $this->assertStringContainsString('<strong>Add your car</strong>', $noCars);   // the first-run steps (UX review §M1)
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

    public function testAtAGlanceShowsIceTechAndIceGearWhenPresent(): void
    {
        $html = renderHomeHtml($this->vm([
            'garage' => [
                ['car' => ['id' => 3, 'car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000'],
                 'declaration' => null, 'techLabel' => 'Needs tech at the track', 'techState' => 'none',
                 'usesSummer' => false, 'ice' => ['state' => 'accepted', 'label' => 'Teched Winter 2026–27 · NASCC · LS']],
            ],
            'drivers' => [['name' => 'Jordan Lee', 'isSelf' => true, 'gearLabel' => 'Needs gear check at the track', 'gearState' => 'none',
                'ice' => ['state' => 'accepted', 'label' => 'Winter 2026–27: from summer 2026']]],
        ]));
        $this->assertStringContainsString('Teched Winter 2026–27 · NASCC · LS', $html);
        $this->assertStringContainsString('Winter 2026–27: from summer 2026', $html);
        $this->assertStringNotContainsString('Not declared', $html);
        $this->assertStringNotContainsString('Declare class', $html);
    }

    public function testAtAGlanceHidesTheSummerGearPillWhenNotShown(): void
    {
        $driver = ['name' => 'Jordan Lee', 'isSelf' => true, 'gearLabel' => 'Needs gear check at the track', 'gearState' => 'none',
                   'ice' => ['state' => 'none', 'label' => 'Needs ice gear check 2027']];
        $hidden = renderHomeHtml($this->vm(['drivers' => [$driver + ['showSummer' => false]]]));
        $this->assertStringNotContainsString('Gear tech:', $hidden);
        $this->assertStringContainsString('Needs ice gear check 2027', $hidden);
        $this->assertStringContainsString('Gear tech:', renderHomeHtml($this->vm(['drivers' => [$driver]])));
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
            . '<p class="hub-tag-car"><strong>Car:</strong> #7 2010 Mazda MX-5</p><input type="hidden" name="car_id" value="4">',
            $html
        );
        // Both are inside Fall Sprint's card, before the next event's card starts.
        $fall = strpos($html, '<h3>Fall Sprint</h3>');
        $finale = strpos($html, '<h3>Season Finale</h3>');
        $this->assertGreaterThan($fall, strpos($html, 'Not going anymore'));
        // The picker (TA/Drift) now sits between the car and the button, so allow it in between.
        $this->assertLessThan($finale, strpos($html, 'name="car_id" value="4">'));
        // A car is already going, so the folded form adds a second one and says so (UX review 2026-09-30 §H4).
        $this->assertLessThan($finale, strpos($html, '<summary class="hub-btn hub-btn--secondary">Add another car</summary>'));
        $this->assertLessThan($finale, strpos($html, '<button type="submit" class="hub-btn">Add this car</button>'));
        // An event you're not going to offers both cars in a labelled picker.
        $this->assertStringContainsString('<label for="tag-car-11">Which car?<span class="visually-hidden"> For Season Finale</span></label><select id="tag-car-11" name="car_id">', $html);
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
        $this->assertStringContainsString('<p class="hub-hero-tagline">Get your car and gear ready for race day.', $html);
        // The account is the main action; the calculator is a link (UX review 2026-09-30 §M1).
        $this->assertStringContainsString('<a class="hub-btn" href="auth.php?action=register&amp;redirect=index.php">Create account</a>', $html);
        $this->assertStringContainsString('<h2>How it works</h2>', $html);
        $this->assertStringContainsString('href="calculator.php">Try the class calculator</a>', $html);
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

    public function testCarsForEventRespectStoredSeasons(): void
    {
        $cars = [1 => ['id' => 1, 'disciplines' => 'ice'], 2 => ['id' => 2, 'disciplines' => 'summer'], 3 => ['id' => 3, 'disciplines' => null], 4 => ['id' => 4, 'disciplines' => 'both']];
        $this->assertSame([1, 3, 4], array_keys(homeCarsForEvent($cars, ['discipline' => 'ice'])));
        $this->assertSame([2, 3, 4], array_keys(homeCarsForEvent($cars, ['discipline' => 'summer'])));
        $this->assertSame([2, 3, 4], array_keys(homeCarsForEvent($cars, [])));
    }

    public function testEventCardLinksToAddACarWhenNoCarFits(): void
    {
        $summer = ['id' => 10, 'name' => 'Fall Sprint', 'event_date' => '2026-10-15', 'discipline' => 'summer'];
        $none = homeEventCardHtml($summer, null, [], 'tok', false);
        $this->assertStringContainsString('<a class="hub-btn hub-btn--secondary" href="garage.php?action=add&amp;event_id=10">Add a car for this event</a>', $none);
        $iceOnly = homeEventCardHtml($summer, null, [1 => ['id' => 1, 'car_number' => '42', 'make' => 'Honda', 'model' => 'Civic', 'disciplines' => 'ice']], 'tok', false);
        $this->assertStringContainsString('href="garage.php?action=add&amp;event_id=10"', $iceOnly);
        $this->assertStringNotContainsString('name="car_id"', $iceOnly);
    }

    public function testLandingLeadsWithIceWhenTheNextEventIsIce(): void
    {
        $html = renderLandingHtml([], true);
        $this->assertStringContainsString('<p class="hub-hero-tagline">Get your car and gear ready for the ice.', $html);
        $this->assertStringContainsString('<strong>Send an ice tech sheet</strong>', $html);
        $this->assertStringContainsString('<a class="hub-btn" href="auth.php?action=register&amp;redirect=index.php">Create account</a>', $html);
        $this->assertStringNotContainsString('<a class="hub-btn" href="calculator.php">', $html);
        $this->assertStringContainsString('href="calculator.php">Summer class calculator</a>', $html);
    }

    /** The default vm plus a second event the driver is going to, with one thing to do. */
    private function vmTwoEvents(array $o = []): array {
        $vm = $this->vm($o);
        $vm['readiness']['events'][] = [
            'event' => ['id' => 11, 'name' => 'Season <Finale>', 'event_date' => '2099-10-25'],
            'items' => [$this->item('tech_sheet', 3, 'todo', 'Submit a tech sheet for #42 (finale)', ['label' => 'Submit tech sheet', 'url' => 'tech-sheets.php?action=new&car_id=3&event_id=11'])],
        ];
        $vm['readiness']['untagged'] = [];
        return $vm;
    }

    public function testEachEventsToDoCountLinksToThatEventsList(): void
    {
        $html = renderHomeHtml($this->vmTwoEvents());
        $this->assertStringContainsString('<h1 id="todo" tabindex="-1">2 things to do before Fall Sprint</h1>', $html);
        // The soonest event's list is already at the top: its count jumps there.
        $this->assertStringContainsString('<a class="hub-status hub-status--todo hub-todo-link" href="#todo">2 things to do</a>', $html);
        // A later event's list isn't on the page yet: its count reloads Home with that list at the top.
        $this->assertStringContainsString('<a class="hub-status hub-status--todo hub-todo-link" href="index.php?event=11#todo">1 thing to do</a>', $html);
        $this->assertStringNotContainsString('Submit a tech sheet for #42 (finale)', $html);
        $this->assertStringNotContainsString('Back to', $html);
    }

    public function testAChosenEventFillsTheTopListWithAWayBack(): void
    {
        $html = renderHomeHtml($this->vmTwoEvents(['focusEventId' => 11]));
        $this->assertStringContainsString('<h1 id="todo" tabindex="-1">1 thing to do before Season &lt;Finale&gt;</h1>', $html);
        $this->assertStringContainsString('Submit a tech sheet for #42 (finale)', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=new&amp;car_id=3&amp;event_id=11"', $html);
        $this->assertStringNotContainsString('<strong>Submit a tech sheet for #42</strong>', $html);   // Fall Sprint's list isn't shown
        $this->assertStringContainsString('<a class="hub-back-link" href="index.php#todo">&larr; Back to Fall Sprint</a>', $html);
        $this->assertStringContainsString('<a class="hub-status hub-status--todo hub-todo-link" href="#todo">1 thing to do</a>', $html);
        $this->assertStringContainsString('<a class="hub-status hub-status--todo hub-todo-link" href="index.php?event=10#todo">2 things to do</a>', $html);
    }

    public function testAnUnknownEventFallsBackToTheSoonest(): void
    {
        $events = $this->vmTwoEvents()['readiness']['events'];
        $this->assertSame(0, homeFocusIndex($events, null));
        $this->assertSame(1, homeFocusIndex($events, 11));
        $this->assertSame(0, homeFocusIndex($events, 999));
        $html = renderHomeHtml($this->vmTwoEvents(['focusEventId' => 999]));
        $this->assertStringContainsString('2 things to do before Fall Sprint', $html);
    }

    public function testEventCardsLinkToTheirMotorsportRegEvent(): void
    {
        $vm = $this->vm();
        $vm['readiness']['events'][0]['event']['msr_url'] = 'https://msr.example/e/fall?a=1&b=2';
        $vm['readiness']['untagged'][0]['msr_url'] = 'http://not-https.example/';
        $html = renderHomeHtml($vm);
        $this->assertStringContainsString('<a class="hub-btn hub-btn--secondary hub-register-link" href="https://msr.example/e/fall?a=1&amp;b=2" target="_blank" rel="noopener">Register on MotorsportReg &#8599;</a>', $html);
        $this->assertSame(1, substr_count($html, 'hub-register-link'));   // the http link is never shown
    }

    public function testLandingNextIsIceLooksAtTheSoonestUpcomingEvent(): void
    {
        $events = [['event_date' => '2026-09-01', 'discipline' => 'summer'], ['event_date' => '2026-11-12', 'discipline' => 'ice'], ['event_date' => '2026-10-15', 'discipline' => 'summer']];
        $this->assertFalse(landingNextIsIce($events, '2026-09-28'));
        $this->assertTrue(landingNextIsIce($events, '2026-10-16'));
        $this->assertFalse(landingNextIsIce([], '2026-09-28'));
    }
}
