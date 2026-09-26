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
}
