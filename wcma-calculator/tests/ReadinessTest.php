<?php
// wcma-calculator/tests/ReadinessTest.php
require_once __DIR__ . '/../tech-status.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../readiness-lib.php';

use PHPUnit\Framework\TestCase;

final class ReadinessTest extends TestCase
{
    private function world(array $o = []): array {
        return array_merge([
            'today' => '2026-09-26',
            'cars' => [3 => ['id' => 3, 'car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000']],
            'events' => [
                ['id' => 10, 'name' => 'Fall Sprint', 'event_date' => '2026-10-11'],
                ['id' => 11, 'name' => 'Season Finale', 'event_date' => '2026-10-25'],
                ['id' => 9, 'name' => 'Old Race', 'event_date' => '2026-08-01'],
            ],
            'plans' => [['event_id' => 10, 'car_id' => 3], ['event_id' => 11, 'car_id' => 3]],
            'declarations' => [3 => ['review_status' => 'accepted', 'submitted_at' => '2026-04-02 10:00:00', 'calculated_class' => 'GT3']],
            'sheets' => [],
            'sheetDrivers' => [],
            'drivers' => [5 => ['id' => 5, 'name' => 'Jordan Lee'], 6 => ['id' => 6, 'name' => 'Sam Patel']],
            'selfDriverId' => 5,
            'gear' => [],
            'atTrack' => [],
        ], $o);
    }

    private function items(array $r, int $eventIndex = 0): array {
        $out = [];
        foreach ($r['events'][$eventIndex]['items'] as $i) $out[$i['kind'] . ':' . $i['subject_id']] = $i;
        return $out;
    }

    public function testOnlyTaggedUpcomingEventsSoonestFirstAndUntaggedListed(): void
    {
        $r = buildReadiness($this->world(['plans' => [['event_id' => 11, 'car_id' => 3]]]));
        $this->assertSame([11], array_map(fn($e) => (int)$e['event']['id'], $r['events']));
        $this->assertSame([10], array_map(fn($e) => (int)$e['id'], $r['untagged']));   // past event 9 excluded
    }

    public function testSeasonalItemsOnlyUnderTheNearestEventAndSheetsUnderEach(): void
    {
        $r = buildReadiness($this->world());
        $first = array_keys($this->items($r, 0));
        $second = array_keys($this->items($r, 1));
        $this->assertEqualsCanonicalizing(['declaration:3', 'tech_sheet:3', 'car_tech:3', 'gear:5', 'gear:6'], $first);
        $this->assertSame(['tech_sheet:3'], $second);
    }

    public function testDeclarationStates(): void
    {
        $state = fn(array $decl = null) => $this->items(buildReadiness($this->world(['declarations' => $decl === null ? [] : [3 => $decl]])))['declaration:3'];

        $this->assertSame('done', $state(['review_status' => 'accepted', 'submitted_at' => '2026-04-02', 'calculated_class' => 'GT3'])['state']);
        $info = $state(['review_status' => 'submitted', 'submitted_at' => '2026-04-02', 'calculated_class' => 'GT3']);
        $this->assertSame(['info', 'Class declaration for #42 is with an inspector'], [$info['state'], $info['label']]);
        $fix = $state(['review_status' => 'needs_changes', 'submitted_at' => '2026-04-02', 'calculated_class' => 'GT3']);
        $this->assertSame(['todo', 'calculator.php?car=3'], [$fix['state'], $fix['action']['url']]);
        $none = $state(null);
        $this->assertSame(['todo', 'Declare class for #42'], [$none['state'], $none['label']]);
        $old = $state(['review_status' => 'accepted', 'submitted_at' => '2025-05-01', 'calculated_class' => 'GT3']);
        $this->assertSame('todo', $old['state']);
    }

    public function testTechSheetTodoLinksToTheEventAndDoneWhenSubmitted(): void
    {
        $todo = $this->items(buildReadiness($this->world()))['tech_sheet:3'];
        $this->assertSame(['todo', 'tech-sheets.php?action=new&car_id=3&event_id=10'], [$todo['state'], $todo['action']['url']]);

        $sheet = ['id' => 70, 'car_id' => 3, 'event_id' => 10, 'season' => 2026, 'status' => 'submitted', 'photo_status' => null, 'accepted_via' => null, 'driver_id' => 5];
        $done = $this->items(buildReadiness($this->world(['sheets' => [$sheet]])))['tech_sheet:3'];
        $this->assertSame('done', $done['state']);
    }

    public function testCarTechStatesAndTheAtTrackChoice(): void
    {
        $sheet = fn(array $o) => array_merge(['id' => 70, 'car_id' => 3, 'event_id' => 10, 'season' => 2026, 'status' => 'submitted', 'photo_status' => null, 'accepted_via' => null, 'driver_id' => 5], $o);
        $carTech = fn(array $w) => $this->items(buildReadiness($this->world($w)))['car_tech:3'];

        $noSheet = $carTech([]);
        $this->assertSame('todo', $noSheet['state']);
        $this->assertNull($noSheet['action']);
        $this->assertSame(['subject_type' => 'car', 'subject_id' => 3, 'season' => 2026], $noSheet['at_track']);

        $withSheet = $carTech(['sheets' => [$sheet([])]]);
        $this->assertSame('tech-sheets.php?action=pretech&id=70', $withSheet['action']['url']);

        $this->assertSame('done', $carTech(['atTrack' => ['car:3@2026']])['state']);
        $this->assertSame('info', $carTech(['sheets' => [$sheet(['photo_status' => 'submitted'])]])['state']);
        $retake = $carTech(['sheets' => [$sheet(['photo_status' => 'needs_changes'])]]);
        $this->assertSame(['todo', 'tech-sheets.php?action=pretech&id=70'], [$retake['state'], $retake['action']['url']]);
        $this->assertSame('done', $carTech(['sheets' => [$sheet(['status' => 'teched', 'accepted_via' => 'photos'])]])['state']);
    }

    public function testAtTrackChoiceIsScopedToItsSeasonAndDoesNotCarryToAnotherSeason(): void
    {
        $world = $this->world([
            'today' => '2026-09-26',
            'events' => [
                ['id' => 20, 'name' => 'Season Opener', 'event_date' => '2027-03-01'],
            ],
            'plans' => [['event_id' => 20, 'car_id' => 3]],
            'atTrack' => ['car:3@2026'],
        ]);
        $carTech = $this->items(buildReadiness($world), 0)['car_tech:3'];
        $this->assertSame('todo', $carTech['state']);
    }

    public function testGearCoversTheSheetsDriversAndEveryDriverOnTheProfile(): void
    {
        $sheet = ['id' => 70, 'car_id' => 3, 'event_id' => 10, 'season' => 2026, 'status' => 'submitted', 'photo_status' => null, 'accepted_via' => null, 'driver_id' => 5];
        $r = buildReadiness($this->world(['sheets' => [$sheet], 'sheetDrivers' => [70 => [6]],
            'gear' => ['5:2026' => ['id' => 90, 'status' => 'accepted', 'accepted_via' => 'photos', 'photo_status' => 'accepted', 'season' => 2026]]]));
        $items = $this->items($r);
        $this->assertSame('done', $items['gear:5']['state']);
        $this->assertSame(['todo', 'gear.php?action=start&driver_id=6', 'Gear for Sam Patel'], [$items['gear:6']['state'], $items['gear:6']['action']['url'], $items['gear:6']['label']]);

        // No tech sheet yet: every driver on the profile still needs this season's gear (spec §3)
        $noSheet = $this->items(buildReadiness($this->world()));
        $this->assertSame('todo', $noSheet['gear:5']['state']);
        $this->assertSame(['todo', 'Gear for Sam Patel'], [$noSheet['gear:6']['state'], $noSheet['gear:6']['label']]);

        // A sheet naming only the self driver does not hide the other profile drivers
        $selfSheet = $this->items(buildReadiness($this->world(['sheets' => [$sheet]])));
        $this->assertArrayHasKey('gear:6', $selfSheet);

        // Drivers who are not on this user's profile are never listed
        $foreign = $this->items(buildReadiness($this->world(['sheets' => [$sheet], 'sheetDrivers' => [70 => [99]]])));
        $this->assertArrayNotHasKey('gear:99', $foreign);
    }

    public function testNothingTaggedMeansNoEventsAndAllUpcomingUntagged(): void
    {
        $r = buildReadiness($this->world(['plans' => []]));
        $this->assertSame([], $r['events']);
        $this->assertSame([10, 11], array_map(fn($e) => (int)$e['id'], $r['untagged']));
    }

    public function testCarTechAddPhotosUsesTheHighestSheetIdRegardlessOfInputOrder(): void
    {
        $older = ['id' => 70, 'car_id' => 3, 'event_id' => 10, 'season' => 2026, 'status' => 'submitted', 'photo_status' => null, 'accepted_via' => null, 'driver_id' => 5];
        $newer = ['id' => 71, 'car_id' => 3, 'event_id' => 10, 'season' => 2026, 'status' => 'submitted', 'photo_status' => null, 'accepted_via' => null, 'driver_id' => 5];
        // Passed newest-first, as db_get_user_tech_sheets() (created_at DESC) would.
        $r = buildReadiness($this->world(['sheets' => [$newer, $older]]));
        $carTech = $this->items($r)['car_tech:3'];
        $this->assertSame('tech-sheets.php?action=pretech&id=71', $carTech['action']['url']);
    }

    public function testTwoCarsTaggedToTheSameEventEachGetTheirOwnItemsAndShareTheSelfDriverGearItem(): void
    {
        $r = buildReadiness($this->world([
            'cars' => [
                3 => ['id' => 3, 'car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000'],
                4 => ['id' => 4, 'car_number' => '7', 'year' => '2010', 'make' => 'Mazda', 'model' => 'MX-5'],
            ],
            'plans' => [['event_id' => 10, 'car_id' => 3], ['event_id' => 10, 'car_id' => 4], ['event_id' => 11, 'car_id' => 3], ['event_id' => 11, 'car_id' => 4]],
            'declarations' => [
                3 => ['review_status' => 'accepted', 'submitted_at' => '2026-04-02 10:00:00', 'calculated_class' => 'GT3'],
                4 => ['review_status' => 'accepted', 'submitted_at' => '2026-04-02 10:00:00', 'calculated_class' => 'GT2'],
            ],
        ]));
        $kinds = array_map(fn($i) => $i['kind'] . ':' . $i['subject_id'], $r['events'][0]['items']);
        $this->assertSame(
            ['declaration:3', 'tech_sheet:3', 'car_tech:3', 'gear:5', 'gear:6', 'declaration:4', 'tech_sheet:4', 'car_tech:4'],
            $kinds
        );
        $this->assertSame(1, count(array_filter($kinds, fn($k) => $k === 'gear:5')));
    }

    public function testNeedsChangesDeclarationFromAnEarlierSeasonIsStillTheNeedsChangesTodo(): void
    {
        $r = buildReadiness($this->world(['declarations' => [3 => ['review_status' => 'needs_changes', 'submitted_at' => '2025-05-01', 'calculated_class' => 'GT3']]]));
        $item = $this->items($r)['declaration:3'];
        $this->assertSame(['todo', 'Your class declaration for #42 needs changes', 'calculator.php?car=3'], [$item['state'], $item['label'], $item['action']['url']]);
    }

    public function testNoBannedWording(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', file_get_contents(__DIR__ . '/../readiness-lib.php'));
    }

    private function iceWorld(array $o = []): array {
        return $this->world(array_merge([
            'events' => [['id' => 20, 'name' => 'NASCC Ice #1', 'event_date' => '2027-01-10', 'discipline' => 'ice', 'host_club' => 'NASCC']],
            'plans' => [['event_id' => 20, 'car_id' => 3]],
            'drivers' => [5 => ['id' => 5, 'name' => 'Jordan Lee']],
            'iceGear' => [],
            'iceGearFhr' => [],
        ], $o));
    }

    private function iceSheet(array $o = []): array {
        return array_merge(['id' => 70, 'car_id' => 3, 'event_id' => 20, 'season' => 2027, 'discipline' => 'ice', 'club' => 'NASCC',
                            'class' => 'SS', 'status' => 'submitted', 'accepted_via' => null, 'photo_status' => null, 'driver_id' => 5], $o);
    }

    public function testIceEventHasNoDeclarationAndAnIceTechSheetTodo(): void
    {
        $items = $this->items(buildReadiness($this->iceWorld()));
        $this->assertEqualsCanonicalizing(['tech_sheet:3', 'car_tech:3', 'gear:5'], array_keys($items));
        $this->assertSame('Submit an ice tech sheet for #42', $items['tech_sheet:3']['label']);
        $this->assertSame('tech-sheets.php?action=new-ice&car_id=3&event_id=20', $items['tech_sheet:3']['action']['url']);
        $this->assertSame('Ice car tech for #42 at NASCC', $items['car_tech:3']['label']);
        $this->assertSame(['subject_type' => 'car', 'subject_id' => 3, 'season' => 2027, 'discipline' => 'ice', 'club' => 'NASCC'],
            $items['car_tech:3']['at_track']);
        $this->assertSame(['subject_type' => 'driver', 'subject_id' => 5, 'season' => 2027, 'discipline' => 'ice', 'club' => ''],
            $items['gear:5']['at_track']);
    }

    public function testIceCarTechIsPerClubAndSeason(): void
    {
        $teched = $this->iceSheet(['status' => 'teched', 'accepted_via' => 'in_person']);
        $items = $this->items(buildReadiness($this->iceWorld(['sheets' => [$teched]])));
        $this->assertSame('done', $items['car_tech:3']['state']);
        $this->assertSame('Ice car tech 2027 for #42 at NASCC: teched', $items['car_tech:3']['label']);
        $this->assertSame('Ice tech sheet for NASCC Ice #1 submitted', $items['tech_sheet:3']['label']);

        $wscc = $this->iceSheet(['club' => 'WSCC', 'event_id' => 99, 'class' => 'FOI-STD', 'status' => 'teched', 'accepted_via' => 'in_person']);
        $this->assertSame('todo', $this->items(buildReadiness($this->iceWorld(['sheets' => [$wscc]])))['car_tech:3']['state']);
    }

    public function testSummerCarTechIgnoresIceSheets(): void
    {
        $iceTeched = $this->iceSheet(['season' => 2026, 'status' => 'teched', 'accepted_via' => 'in_person']);
        $items = $this->items(buildReadiness($this->world(['sheets' => [$iceTeched]])));
        $this->assertSame('todo', $items['car_tech:3']['state']);
    }

    public function testIceAtTrackKeysUseTheClub(): void
    {
        $done = $this->items(buildReadiness($this->iceWorld(['atTrack' => ['car:3@ice:NASCC:2027', 'driver:5@ice:2027']])));
        $this->assertSame('done', $done['car_tech:3']['state']);
        $this->assertSame('done', $done['gear:5']['state']);
        $other = $this->items(buildReadiness($this->iceWorld(['atTrack' => ['car:3@ice:WSCC:2027', 'car:3@2027', 'driver:5@2027']])));
        $this->assertSame('todo', $other['car_tech:3']['state']);
        $this->assertSame('todo', $other['gear:5']['state']);
    }

    public function testSummerGearCarriesOverToTheNextIceSeasonOnly(): void
    {
        $accepted = fn(int $season): array => ['id' => 40, 'season' => $season, 'status' => 'accepted', 'accepted_via' => 'in_person', 'photo_status' => null];
        $label = fn(array $gear): string => $this->items(buildReadiness($this->iceWorld(['gear' => $gear])))['gear:5']['label'];
        $this->assertSame('Ice gear for Jordan Lee: from summer 2026', $label(['5:2026' => $accepted(2026)]));
        $this->assertSame('todo', $this->items(buildReadiness($this->iceWorld(['gear' => ['5:2027' => $accepted(2027)]])))['gear:5']['state']);
        $this->assertSame('todo', $this->items(buildReadiness($this->iceWorld(['gear' => ['5:2025' => $accepted(2025)]])))['gear:5']['state']);
        $submitted = ['id' => 41, 'season' => 2026, 'status' => 'open', 'accepted_via' => null, 'photo_status' => 'submitted'];
        $this->assertSame('todo', $this->items(buildReadiness($this->iceWorld(['gear' => ['5:2026' => $submitted]])))['gear:5']['state']);
    }

    public function testIceGearLevelMustCoverTheSheetsClass(): void
    {
        $gear = ['5:2027' => ['id' => 50, 'season' => 2027, 'discipline' => 'ice', 'level' => 'street_safe', 'status' => 'accepted', 'accepted_via' => 'in_person', 'photo_status' => null]];
        $noSheet = $this->items(buildReadiness($this->iceWorld(['iceGear' => $gear])));
        $this->assertSame('done', $noSheet['gear:5']['state']);
        $this->assertSame('Ice gear for Jordan Lee: teched Ice 2027 · street-safe', $noSheet['gear:5']['label']);

        $ls = $this->items(buildReadiness($this->iceWorld(['iceGear' => $gear, 'sheets' => [$this->iceSheet(['class' => 'LS'])]])));
        $this->assertSame('todo', $ls['gear:5']['state']);
        $this->assertSame("Jordan Lee's gear is checked for street-safe; LS needs caged-level gear", $ls['gear:5']['label']);
    }

    public function testFhrClassNeedsAnFhrSeenForPhotoAcceptedGear(): void
    {
        $caged = fn(string $via): array => ['5:2027' => ['id' => 51, 'season' => 2027, 'discipline' => 'ice', 'level' => 'caged', 'status' => 'accepted', 'accepted_via' => $via, 'photo_status' => $via === 'photos' ? 'accepted' : null]];
        $ls = [$this->iceSheet(['class' => 'LS'])];
        $photos = $this->items(buildReadiness($this->iceWorld(['iceGear' => $caged('photos'), 'sheets' => $ls])));
        $this->assertSame('todo', $photos['gear:5']['state']);
        $this->assertSame("Jordan Lee's gear needs a frontal head restraint checked for LS", $photos['gear:5']['label']);
        $seen = $this->items(buildReadiness($this->iceWorld(['iceGear' => $caged('photos'), 'iceGearFhr' => [51 => true], 'sheets' => $ls])));
        $this->assertSame('done', $seen['gear:5']['state']);
        $inPerson = $this->items(buildReadiness($this->iceWorld(['iceGear' => $caged('in_person'), 'sheets' => $ls])));
        $this->assertSame('done', $inPerson['gear:5']['state']);
    }

    public function testIceGearPhotosLinkFromTheIceSheet(): void
    {
        $items = $this->items(buildReadiness($this->iceWorld(['sheets' => [$this->iceSheet()]])));
        $this->assertSame('gear.php?action=start-ice&sheet_id=70', $items['gear:5']['action']['url']);
        $this->assertNull($this->items(buildReadiness($this->iceWorld()))['gear:5']['action']);
    }

    public function testIceGearShortfallSeenAcrossAllOfDriversIceSheetsCaseA(): void
    {
        // Two NASCC events, same season: event 1's sheet is SS, event 2's sheet is LS. The
        // shortfall against the LS sheet must not be lost just because event 1 is processed first.
        $ss = $this->iceSheet(['class' => 'SS']);
        $ls = $this->iceSheet(['id' => 71, 'event_id' => 21, 'class' => 'LS']);
        $gear = ['5:2027' => ['id' => 50, 'season' => 2027, 'discipline' => 'ice', 'level' => 'street_safe', 'status' => 'accepted', 'accepted_via' => 'in_person', 'photo_status' => null]];
        $world = $this->iceWorld([
            'events' => [
                ['id' => 20, 'name' => 'NASCC Ice #1', 'event_date' => '2027-01-10', 'discipline' => 'ice', 'host_club' => 'NASCC'],
                ['id' => 21, 'name' => 'NASCC Ice #2', 'event_date' => '2027-01-24', 'discipline' => 'ice', 'host_club' => 'NASCC'],
            ],
            'plans' => [['event_id' => 20, 'car_id' => 3], ['event_id' => 21, 'car_id' => 3]],
            'sheets' => [$ss, $ls],
            'iceGear' => $gear,
        ]);
        $items = $this->items(buildReadiness($world), 0);
        $this->assertSame('todo', $items['gear:5']['state']);
        $this->assertSame("Jordan Lee's gear is checked for street-safe; LS needs caged-level gear", $items['gear:5']['label']);
    }

    public function testIceGearShortfallSeenAcrossAllOfDriversIceSheetsCaseB(): void
    {
        // Same event, two cars: car #42's sheet is SS, car #7's sheet is LS, same driver on both.
        $ss = $this->iceSheet(['car_id' => 3, 'class' => 'SS']);
        $ls = $this->iceSheet(['id' => 71, 'car_id' => 4, 'class' => 'LS']);
        $gear = ['5:2027' => ['id' => 50, 'season' => 2027, 'discipline' => 'ice', 'level' => 'street_safe', 'status' => 'accepted', 'accepted_via' => 'in_person', 'photo_status' => null]];
        $world = $this->iceWorld([
            'cars' => [
                3 => ['id' => 3, 'car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000'],
                4 => ['id' => 4, 'car_number' => '7', 'year' => '2010', 'make' => 'Mazda', 'model' => 'MX-5'],
            ],
            'plans' => [['event_id' => 20, 'car_id' => 3], ['event_id' => 20, 'car_id' => 4]],
            'sheets' => [$ss, $ls],
            'iceGear' => $gear,
        ]);
        $items = $this->items(buildReadiness($world), 0);
        $this->assertSame('todo', $items['gear:5']['state']);
        $this->assertSame("Jordan Lee's gear is checked for street-safe; LS needs caged-level gear", $items['gear:5']['label']);
    }

    public function testSummerCarryOverOverridesAnIceGearShortfall(): void
    {
        $gear = ['5:2027' => ['id' => 50, 'season' => 2027, 'discipline' => 'ice', 'level' => 'street_safe', 'status' => 'accepted', 'accepted_via' => 'in_person', 'photo_status' => null]];
        $summer = ['5:2026' => ['id' => 40, 'season' => 2026, 'status' => 'accepted', 'accepted_via' => 'in_person', 'photo_status' => null]];
        $items = $this->items(buildReadiness($this->iceWorld([
            'iceGear' => $gear, 'gear' => $summer, 'sheets' => [$this->iceSheet(['class' => 'LS'])],
        ])));
        $this->assertSame('done', $items['gear:5']['state']);
        $this->assertSame('Ice gear for Jordan Lee: from summer 2026', $items['gear:5']['label']);
    }

    public function testTwoIceEventsSameClubAndSeasonDedupeCarTechAndGear(): void
    {
        $world = $this->iceWorld([
            'events' => [
                ['id' => 20, 'name' => 'NASCC Ice #1', 'event_date' => '2027-01-10', 'discipline' => 'ice', 'host_club' => 'NASCC'],
                ['id' => 21, 'name' => 'NASCC Ice #2', 'event_date' => '2027-01-24', 'discipline' => 'ice', 'host_club' => 'NASCC'],
            ],
            'plans' => [['event_id' => 20, 'car_id' => 3], ['event_id' => 21, 'car_id' => 3]],
        ]);
        $r = buildReadiness($world);
        $all = array_merge($r['events'][0]['items'], $r['events'][1]['items']);
        $this->assertSame(1, count(array_filter($all, fn($i) => $i['kind'] === 'car_tech')));
        $this->assertSame(1, count(array_filter($all, fn($i) => $i['kind'] === 'gear')));
    }

    public function testIceAndSummerEventsInTheSameSeasonKeepSeparateCarTechAndGear(): void
    {
        $world = $this->iceWorld([
            'events' => [
                ['id' => 20, 'name' => 'NASCC Ice #1', 'event_date' => '2027-01-10', 'discipline' => 'ice', 'host_club' => 'NASCC'],
                ['id' => 30, 'name' => 'Summer Opener', 'event_date' => '2027-06-01'],
            ],
            'plans' => [['event_id' => 20, 'car_id' => 3], ['event_id' => 30, 'car_id' => 3]],
            'declarations' => [3 => ['review_status' => 'accepted', 'submitted_at' => '2027-04-02 10:00:00', 'calculated_class' => 'GT3']],
        ]);
        $r = buildReadiness($world);
        $iceItems = $this->items($r, 0);
        $summerItems = $this->items($r, 1);
        $this->assertArrayHasKey('car_tech:3', $iceItems);
        $this->assertArrayHasKey('car_tech:3', $summerItems);
        $this->assertArrayHasKey('gear:5', $iceItems);
        $this->assertArrayHasKey('gear:5', $summerItems);
    }

    public function testStrictestIceClassIgnoresSheetsFromEventsThatAreNoLongerLive(): void
    {
        // Stale sheet's event_id (999) isn't in the world's events at all (as if the event was
        // deactivated, or is simply long past and dropped from the active-events list).
        $gear = ['5:2027' => ['id' => 50, 'season' => 2027, 'discipline' => 'ice', 'level' => 'street_safe', 'status' => 'accepted', 'accepted_via' => 'in_person', 'photo_status' => null]];
        $staleLs = $this->iceSheet(['id' => 71, 'event_id' => 999, 'class' => 'LS']);
        $items = $this->items(buildReadiness($this->iceWorld(['sheets' => [$staleLs], 'iceGear' => $gear])));
        $this->assertSame('done', $items['gear:5']['state']);
    }

    public function testStrictestIceClassIgnoresSheetsForCarsNotInTheDriversCarList(): void
    {
        // Sheet's car_id (999) is not in $in['cars'] (as if the car had been archived).
        $gear = ['5:2027' => ['id' => 50, 'season' => 2027, 'discipline' => 'ice', 'level' => 'street_safe', 'status' => 'accepted', 'accepted_via' => 'in_person', 'photo_status' => null]];
        $archivedCarLs = $this->iceSheet(['id' => 71, 'car_id' => 999, 'class' => 'LS']);
        $items = $this->items(buildReadiness($this->iceWorld(['sheets' => [$archivedCarLs], 'iceGear' => $gear])));
        $this->assertSame('done', $items['gear:5']['state']);
    }

    public function testCarNumberWithPercentSignRendersSafelyInCarTechDoneLabels(): void
    {
        $car = [3 => ['id' => 3, 'car_number' => '5%', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000']];
        $summerSheet = ['id' => 70, 'car_id' => 3, 'event_id' => 10, 'season' => 2026, 'status' => 'teched', 'photo_status' => null, 'accepted_via' => 'in_person', 'driver_id' => 5];
        $summerItems = $this->items(buildReadiness($this->world(['cars' => $car, 'sheets' => [$summerSheet]])));
        $this->assertStringContainsString('#5%', $summerItems['car_tech:3']['label']);
        $this->assertStringNotContainsString('#5%%', $summerItems['car_tech:3']['label']);

        $iceSheet = $this->iceSheet(['status' => 'teched', 'accepted_via' => 'in_person']);
        $iceItems = $this->items(buildReadiness($this->iceWorld(['cars' => $car, 'sheets' => [$iceSheet]])));
        $this->assertStringContainsString('#5%', $iceItems['car_tech:3']['label']);
        $this->assertStringNotContainsString('#5%%', $iceItems['car_tech:3']['label']);
    }

    public function testIceGearPhotosLinkFromALaterEventSheetSameSeason(): void
    {
        $sheet = $this->iceSheet(['id' => 70, 'event_id' => 21]);
        $world = $this->iceWorld([
            'events' => [
                ['id' => 20, 'name' => 'NASCC Ice #1', 'event_date' => '2027-01-10', 'discipline' => 'ice', 'host_club' => 'NASCC'],
                ['id' => 21, 'name' => 'NASCC Ice #2', 'event_date' => '2027-01-24', 'discipline' => 'ice', 'host_club' => 'NASCC'],
            ],
            'plans' => [['event_id' => 20, 'car_id' => 3], ['event_id' => 21, 'car_id' => 3]],
            'sheets' => [$sheet],
        ]);
        // The gear item de-dupes onto the soonest (first) event, which has no sheet of its own.
        $items = $this->items(buildReadiness($world), 0);
        $this->assertSame('gear.php?action=start-ice&sheet_id=70', $items['gear:5']['action']['url']);
    }

    public function testIceGearDetailTextWithAndWithoutAnIceSheetLink(): void
    {
        $withLink = $this->items(buildReadiness($this->iceWorld(['sheets' => [$this->iceSheet()]])))['gear:5'];
        $this->assertSame('Pre-tech with photos from your ice tech sheet, or bring it to tech at the track.', $withLink['detail']);

        $withoutLink = $this->items(buildReadiness($this->iceWorld()))['gear:5'];
        $this->assertSame('Bring it to tech at the track, or add photos once this driver is on an ice tech sheet.', $withoutLink['detail']);
    }

    public function testDriverNameWithPercentSignRendersSafelyInSummerAndIceGearLabels(): void
    {
        $name = '100% Jordan';
        $summer = ['5:2026' => ['id' => 40, 'season' => 2026, 'status' => 'accepted', 'accepted_via' => 'in_person', 'photo_status' => null]];
        $summerItems = $this->items(buildReadiness($this->world(['drivers' => [5 => ['id' => 5, 'name' => $name]], 'gear' => $summer])));
        $this->assertSame("Gear for $name: teched 2026", $summerItems['gear:5']['label']);

        $ice = ['5:2027' => ['id' => 51, 'season' => 2027, 'discipline' => 'ice', 'level' => 'caged', 'status' => 'accepted', 'accepted_via' => 'in_person', 'photo_status' => null]];
        $iceItems = $this->items(buildReadiness($this->iceWorld(['drivers' => [5 => ['id' => 5, 'name' => $name]], 'iceGear' => $ice])));
        $this->assertSame("Ice gear for $name: teched Ice 2027 · caged", $iceItems['gear:5']['label']);
    }
}
