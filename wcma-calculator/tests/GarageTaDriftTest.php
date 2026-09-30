<?php
// wcma-calculator/tests/GarageTaDriftTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../events-lib.php';
require_once __DIR__ . '/../tech-status.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../gear-chips.php';
require_once __DIR__ . '/../home-page.php';
require_once __DIR__ . '/../garage-lib.php';
require_once __DIR__ . '/../garage-page.php';
require_once __DIR__ . '/../reminders-lib.php';
require_once __DIR__ . '/../ice-sheet-lib.php';

use PHPUnit\Framework\TestCase;

final class GarageTaDriftTest extends TestCase
{
    private const TA = ['id' => 20, 'name' => 'WSCC TA', 'event_date' => '2026-07-12', 'discipline' => 'summer', 'host_club' => 'WSCC', 'active' => 1];
    private const OPEN = ['id' => 22, 'name' => 'Open Day', 'event_date' => '2026-08-20', 'discipline' => 'summer', 'host_club' => null, 'active' => 1];

    public function testCarFormOffersSummerTaDriftOnly(): void
    {
        $html = renderAddCarHtml(['csrf' => 'tok', 'values' => ['disciplines' => 'ta_drift'], 'error' => null, 'msrLink' => null, 'event' => null]);
        $this->assertStringContainsString('<input type="radio" name="disciplines" value="ta_drift" required checked><span>Summer TA/Drift only</span>', $html);
    }

    public function testAfterAddingATaDriftOnlyCar(): void
    {
        $this->assertSame(['url' => 'tech-sheets.php?action=new-ta-drift&car_id=3&event_id=20',
                           'flash' => 'Car added and going to WSCC TA. Next, the TA/Drift tech sheet.'], garageAfterAdd(3, 'ta_drift', self::TA));
        $this->assertSame(['url' => 'garage.php?car=3', 'flash' => 'Car added and going to Open Day.'], garageAfterAdd(3, 'ta_drift', self::OPEN));
        $this->assertSame(['url' => 'garage.php?car=3#events', 'flash' => 'Car added. Which event is it going to first?'], garageAfterAdd(3, 'ta_drift', null));
    }

    public function testRaceSheetsLeaveOutIceAndTaDrift(): void
    {
        $sheets = [['id' => 1, 'discipline' => 'summer', 'sheet_type' => 'standard'], ['id' => 2, 'discipline' => 'ice'],
                   ['id' => 3, 'discipline' => 'summer', 'sheet_type' => 'ta_drift'], ['id' => 4]];
        $this->assertSame([1, 4], array_column(garageRaceSheets($sheets), 'id'));
    }

    public function testEntryTiers(): void
    {
        $events = [self::TA, self::OPEN, ['id' => 9, 'name' => 'Past', 'event_date' => '2026-01-01', 'discipline' => 'summer', 'host_club' => 'NASCC']];
        $this->assertSame(['race' => false, 'taDriftClubs' => ['WSCC']], garageEntryTiers([20 => 'ta'], $events, '2026-06-01'));
        $this->assertSame(['race' => true, 'taDriftClubs' => []], garageEntryTiers([22 => 'ta'], $events, '2026-06-01'));      // no club: race
        $this->assertSame(['race' => true, 'taDriftClubs' => []], garageEntryTiers([20 => 'race,ta'], $events, '2026-06-01'));
        $this->assertSame(['race' => false, 'taDriftClubs' => []], garageEntryTiers([9 => 'race'], $events, '2026-06-01'));    // past
    }

    public function testATaDriftOnlyCarRacesOnlyWithRaceActivity(): void
    {
        $car = ['id' => 3, 'disciplines' => 'ta_drift'];
        $this->assertFalse(garageCarRaces($car, [], [['discipline' => 'summer', 'sheet_type' => 'ta_drift']], false));
        $this->assertTrue(garageCarRaces($car, [], [], true));
        $this->assertTrue(garageCarRaces($car, [['id' => 8]], [], false));
        $this->assertTrue(garageCarRaces($car, [], [['discipline' => 'summer', 'sheet_type' => 'standard']], false));
        $this->assertTrue(garageCarRaces(['id' => 3, 'disciplines' => 'summer'], [], [], false));
    }

    public function testCardForATaDriftOnlyCarHasNoClassOrRaceTech(): void
    {
        $card = garageCard(['id' => 3, 'car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'disciplines' => 'ta_drift', 'archived_at' => null],
            [], [], [20], [self::TA], 2026, '2026-06-01', 2027, [20 => 'ta']);
        $this->assertFalse($card['usesRace']);
        $html = garageRenderCard($card);
        $this->assertStringNotContainsString('No class declared yet', $html);
        $this->assertStringNotContainsString('<dt>Car tech</dt>', $html);
        $this->assertStringNotContainsString('Declare class', $html);

        $legacy = garageCard(['id' => 3, 'car_number' => '42', 'make' => 'Honda', 'model' => 'S2000', 'archived_at' => null],
            [], [], [20], [self::TA], 2026, '2026-06-01', 2027);   // no formats passed: tagged events read as race
        $this->assertTrue($legacy['usesRace']);
    }

    public function testCarPageHidesClassAndRaceTechWhenTheCarDoesNotRace(): void
    {
        $vm = ['car' => ['id' => 3, 'car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'archived_at' => null, 'disciplines' => 'ta_drift'],
               'class' => ['current' => null, 'earlierAccepted' => null], 'declarations' => [], 'season' => 2026,
               'techState' => 'none', 'techLabel' => 'Needs tech at the track', 'techAction' => null,
               'events' => ['tagged' => [], 'untagged' => [], 'earlierSheets' => []], 'seasons' => ['summer' => true, 'ice' => false],
               'csrf' => 'tok', 'detailsForm' => null, 'usesSummer' => true, 'usesRace' => false, 'ice' => null];
        $html = renderGarageCarHtml($vm);
        $this->assertStringNotContainsString('<h2>Class</h2>', $html);
        $this->assertStringNotContainsString('<h2>Car tech 2026</h2>', $html);
        $this->assertStringContainsString('<section class="hub-card" id="events"><h2>Events</h2>', $html);
    }

    public function testHomeGlanceHidesClassForATaDriftOnlyCar(): void
    {
        $car = ['id' => 3, 'car_number' => '86', 'year' => '', 'make' => 'Subaru', 'model' => 'BRZ'];
        $html = renderHomeHtml(['name' => 'J', 'readiness' => ['events' => [], 'untagged' => []], 'cars' => [3 => $car],
            'garage' => [['car' => $car, 'declaration' => null, 'techLabel' => 'Needs tech at the track', 'techState' => 'none',
                          'usesSummer' => true, 'usesRace' => false, 'ice' => null]],
            'drivers' => [], 'seasonLinks' => [], 'csrf' => 'tok']);
        $this->assertStringNotContainsString('Not declared', $html);
        $this->assertStringNotContainsString('Car tech:', $html);
    }

    private function sheet(int $id, array $o = []): array {
        return array_merge(['id' => $id, 'car_id' => 3, 'event_id' => 20, 'season' => 2026, 'discipline' => 'summer', 'sheet_type' => 'ta_drift',
                            'club' => 'WSCC', 'status' => 'submitted', 'accepted_via' => null, 'photo_status' => null], $o);
    }

    public function testTaDriftSummaryPerClub(): void
    {
        $this->assertSame([], garageTaDriftSummaries(3, [], [], 2026));
        $this->assertSame([['club' => 'WSCC', 'state' => 'none', 'label' => 'Needs tech at the track']], garageTaDriftSummaries(3, [], ['WSCC'], 2026));

        $sheets = [$this->sheet(9, ['status' => 'teched', 'accepted_via' => 'photos']), $this->sheet(10, ['club' => 'NASCC', 'photo_status' => 'submitted'])];
        $this->assertSame([
            ['club' => 'NASCC', 'state' => 'pending_review', 'label' => 'Photos with an inspector'],
            ['club' => 'WSCC', 'state' => 'accepted', 'label' => 'Pre-teched TA/Drift WSCC 2026'],
        ], garageTaDriftSummaries(3, $sheets, [], 2026));
        $this->assertSame([], garageTaDriftSummaries(3, $sheets, [], 2027));   // another year
    }

    public function testRaceTechCoversEveryClub(): void
    {
        $race = $this->sheet(8, ['sheet_type' => 'standard', 'club' => null, 'status' => 'teched', 'accepted_via' => 'in_person']);
        $this->assertSame([['club' => 'WSCC', 'state' => 'accepted', 'label' => 'Teched 2026 (race)']],
            garageTaDriftSummaries(3, [$race], ['WSCC'], 2026));
    }

    public function testTaDriftSheetDoesNotCountAsRaceCarTech(): void
    {
        $card = garageCard(['id' => 3, 'car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'disciplines' => 'summer', 'archived_at' => null],
            [], [$this->sheet(9, ['status' => 'teched', 'accepted_via' => 'in_person'])], [20], [self::TA], 2026, '2026-06-01', 2027, [20 => 'ta']);
        $this->assertSame('none', $card['techState']);
        $this->assertSame([['club' => 'WSCC', 'state' => 'accepted', 'label' => 'Teched TA/Drift WSCC 2026']], $card['taDrift']);
        $html = garageRenderCard($card);
        $this->assertStringContainsString('<div><dt>TA/Drift WSCC</dt><dd><span class="hub-status hub-status--ok">Teched TA/Drift WSCC 2026</span></dd></div>', $html);
    }

    public function testEventRowsCarryFormatsAndTier(): void
    {
        $rows = garageCarEvents([], [20, 22], [self::TA, self::OPEN], [], '2026-06-01', [20 => 'ta,drift', 22 => 'ta']);
        $this->assertSame(['ta', 'drift'], $rows['tagged'][0]['formats']);
        $this->assertSame('ta_drift', $rows['tagged'][0]['tier']);
        $this->assertSame('race', $rows['tagged'][1]['tier']);   // Open Day has no host club
        $legacy = garageCarEvents([], [20], [self::TA], [], '2026-06-01');
        $this->assertSame(['race'], $legacy['tagged'][0]['formats']);
    }

    private function carVm(array $tagged, array $o = []): array {
        return array_merge(['car' => ['id' => 3, 'car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'archived_at' => null, 'disciplines' => 'ta_drift'],
               'class' => ['current' => null, 'earlierAccepted' => null], 'declarations' => [], 'season' => 2026,
               'techState' => 'none', 'techLabel' => 'Needs tech at the track', 'techAction' => null,
               'events' => ['tagged' => $tagged, 'untagged' => [self::OPEN], 'earlierSheets' => []], 'seasons' => ['summer' => true, 'ice' => false],
               'csrf' => 'tok', 'detailsForm' => null, 'usesSummer' => true, 'usesRace' => false, 'ice' => null, 'taDrift' => [],
               'tagDefaults' => ['ta']], $o);
    }

    public function testTaDriftEventRowLinksToTheTaDriftSheetWithoutADeclaration(): void
    {
        $html = renderGarageCarHtml($this->carVm([['event' => self::TA, 'sheet' => null, 'gearLinks' => [], 'formats' => ['ta'], 'tier' => 'ta_drift']]));
        $this->assertStringContainsString('<span class="hub-status hub-status--todo">No TA/Drift tech sheet yet</span>', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=new-ta-drift&amp;car_id=3&amp;event_id=20">Submit TA/Drift tech sheet</a>', $html);
        $this->assertStringNotContainsString('Declare a class first', $html);
        $this->assertStringContainsString('<details class="hub-entry-formats"><summary>Time Attack · <span class="hub-entry-change">Change</span></summary>', $html);
        $this->assertStringContainsString('<form method="post" action="garage.php" class="hub-line hub-tag-form">', $html);
    }

    public function testSubmittedTaDriftSheetShowsOnItsRow(): void
    {
        $sheet = ['id' => 9, 'season' => 2026, 'sheet_type' => 'ta_drift', 'discipline' => 'summer', 'club' => 'WSCC'];
        $html = renderGarageCarHtml($this->carVm([['event' => self::TA, 'sheet' => $sheet, 'gearLinks' => [], 'formats' => ['ta'], 'tier' => 'ta_drift']]));
        $this->assertStringContainsString('<span class="hub-status hub-status--ok">TA/Drift tech sheet submitted</span> <a href="tech-sheets.php?action=view&amp;id=9">View</a>', $html);
    }

    public function testBringThisCarFormHasThePickerWithTheCarsDefaults(): void
    {
        $html = renderGarageCarHtml($this->carVm([]));
        $this->assertStringContainsString('<input type="checkbox" name="formats[]" value="ta" checked> Time Attack</label>', $html);
        $this->assertStringContainsString(h("the host club's supplementary regulations"), $html);
    }

    public function testIceOnlyCarsGetNoPicker(): void
    {
        $html = renderGarageCarHtml($this->carVm([], ['seasons' => ['summer' => false, 'ice' => true], 'usesSummer' => false]));
        $this->assertStringNotContainsString('formats_shown', $html);
    }

    public function testCardNextTaDriftEventOffersTheTaDriftSheet(): void
    {
        $card = garageCard(['id' => 3, 'car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'disciplines' => 'ta_drift', 'archived_at' => null],
            [], [], [20], [self::TA], 2026, '2026-06-01', 2027, [20 => 'ta']);
        $this->assertStringContainsString('href="tech-sheets.php?action=new-ta-drift&amp;car_id=3&amp;event_id=20">Submit TA/Drift tech sheet</a>', garageRenderCard($card));
    }

    public function testCarPageListsTaDriftTech(): void
    {
        $vm = ['car' => ['id' => 3, 'car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'archived_at' => null],
               'class' => ['current' => null, 'earlierAccepted' => null], 'declarations' => [], 'season' => 2026,
               'techState' => 'none', 'techLabel' => 'Needs tech at the track', 'techAction' => null,
               'events' => ['tagged' => [], 'untagged' => [], 'earlierSheets' => []], 'seasons' => ['summer' => true, 'ice' => false],
               'csrf' => 'tok', 'detailsForm' => null, 'usesSummer' => true, 'usesRace' => false, 'ice' => null,
               'taDrift' => [['club' => 'WSCC', 'state' => 'none', 'label' => 'Needs tech at the track']]];
        $this->assertStringContainsString('<section class="hub-card"><h2>TA/Drift tech</h2><p>TA/Drift WSCC: <span class="hub-status hub-status--warn">Needs tech at the track</span></p></section>',
            renderGarageCarHtml($vm));
    }
}
