<?php
// wcma-calculator/tests/InspectLibTest.php
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../garage-lib.php';
require_once __DIR__ . '/../inspect-lib.php';

use PHPUnit\Framework\TestCase;

final class InspectLibTest extends TestCase
{
    private function car(int $id, int $owner, string $n): array {
        return ['id' => $id, 'owner_user_id' => $owner, 'car_number' => $n, 'car_number_norm' => $n,
                'year' => '1999', 'make' => 'Mazda', 'model' => 'Miata', 'owner_name' => 'Owner ' . $owner, 'tagged' => 1];
    }

    private function sheet(int $id, int $carId, int $owner, array $o = []): array {
        return array_merge(['id' => $id, 'car_id' => $carId, 'user_id' => $owner, 'event_id' => 3, 'season' => 2026,
            'status' => 'submitted', 'photo_status' => null, 'accepted_via' => null, 'driver_name' => 'Jordan Lee'], $o);
    }

    private function gear(int $id, int $owner, string $name, string $status = 'open'): array {
        return ['id' => $id, 'owner_user_id' => $owner, 'season' => 2026, 'driver_name' => $name,
                'driver_name_norm' => db_driver_name_norm($name), 'status' => $status, 'photo_status' => null,
                'accepted_via' => $status === 'accepted' ? 'in_person' : null];
    }

    private function decl(int $id, string $status, ?string $acceptedAt = null): array {
        return ['id' => $id, 'review_status' => $status, 'accepted_at' => $acceptedAt, 'calculated_class' => 'IT1'];
    }

    public function testRowsCarryTheEventSheetClassCarTechAndGear(): void
    {
        $eventSheet = $this->sheet(5, 1, 10);
        $earlier = $this->sheet(3, 1, 10, ['event_id' => 2, 'status' => 'teched', 'accepted_via' => 'in_person']);
        $rows = inspectRosterRows(
            [$this->car(1, 10, '17')], [$eventSheet], [$earlier, $eventSheet],
            [1 => [$this->decl(8, 'accepted', '2026-05-01 10:00:00')]],
            [5 => [['driver_number' => 2, 'driver_name' => 'Sam Patel']]],
            [10 => ['name' => 'Jordan Lee']],
            [$this->gear(20, 10, 'Jordan Lee', 'accepted')],
            2026
        );
        $this->assertCount(1, $rows);
        $this->assertSame(5, (int)$rows[0]['sheet']['id']);
        $this->assertSame('accepted', $rows[0]['status']['state']);            // teched at an earlier event this season
        $this->assertSame(8, (int)$rows[0]['class']['current']['id']);
        $this->assertSame(['Jordan Lee', 'Sam Patel'], array_column($rows[0]['gear_links'], 'name'));
        $this->assertSame(['accepted', 'none'], array_map(fn(array $l): string => $l['status']['state'], $rows[0]['gear_links']));
    }

    public function testTheNewestEventSheetWinsAndOtherCarsSheetsAreIgnored(): void
    {
        $rows = inspectRosterRows([$this->car(1, 10, '17')],
            [$this->sheet(5, 1, 10), $this->sheet(9, 1, 10), $this->sheet(12, 2, 11)], [], [], [], [], [], 2026);
        $this->assertSame(9, (int)$rows[0]['sheet']['id']);
    }

    public function testTaggedCarWithoutASheetShowsTheOwnersGear(): void
    {
        $rows = inspectRosterRows(
            [$this->car(2, 11, '42'), $this->car(3, 12, '7')], [], [], [], [],
            [11 => ['name' => 'Casey Moss']],
            [$this->gear(30, 11, 'Casey Moss'), $this->gear(31, 12, 'Casey Moss')],
            2026
        );
        $this->assertNull($rows[0]['sheet']);
        $this->assertSame('none', $rows[0]['status']['state']);
        $this->assertNull($rows[0]['class']['current']);
        $this->assertCount(1, $rows[0]['gear_links']);
        $this->assertSame(30, (int)$rows[0]['gear_links'][0]['gear']['id']);   // the owner's record, not another account's
        $this->assertSame([], $rows[1]['gear_links']);                           // owner 12 has no self profile
    }

    private function filterRow(string $carState, bool $sheet, ?string $declStatus, array $gearStates, string $iceClass = ''): array {
        return [
            'car' => [], 'sheet' => $sheet ? ['id' => 1] : null,
            'class' => ['current' => $declStatus === null ? null : $this->decl(1, $declStatus), 'earlierAccepted' => null],
            'status' => ['state' => $carState, 'via' => null, 'sheet_id' => null],
            'gear_links' => array_map(fn(string $s): array => ['status' => ['state' => $s, 'via' => null]], $gearStates),
            'ice_class' => $iceClass,
        ];
    }

    public function testRosterFiltersAndCounts(): void
    {
        $done = $this->filterRow('accepted', true, 'accepted', ['accepted']);
        $gearDue = $this->filterRow('accepted', true, 'submitted', ['none']);
        $noSheet = $this->filterRow('none', false, null, []);
        $pending = $this->filterRow('pending_review', true, 'needs_changes', ['accepted']);
        $rows = [$done, $gearDue, $noSheet, $pending];

        $this->assertSame([$done, $gearDue], inspectRosterFilter($rows, 'needs_decals'));
        $this->assertSame([$gearDue, $noSheet, $pending], inspectRosterFilter($rows, 'needs_tech'));
        $this->assertSame([$noSheet], inspectRosterFilter($rows, 'no_sheet'));
        $this->assertSame([$gearDue, $noSheet, $pending], inspectRosterFilter($rows, 'class_not_accepted'));
        $this->assertSame($rows, inspectRosterFilter($rows, 'all'));
        $this->assertSame($rows, inspectRosterFilter($rows, 'bogus'));
        $this->assertSame(['all' => 4, 'needs_decals' => 2, 'needs_tech' => 3, 'no_sheet' => 1, 'class_not_accepted' => 3], inspectRosterCounts($rows));
    }

    public function testQueueMergesTheThreeKindsOldestFirst(): void
    {
        $items = inspectReviewQueue(
            [['id' => 4, 'car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000', 'name' => 'Jordan Lee', 'calculated_class' => 'GT3', 'submitted_at' => '2026-09-03 09:00:00']],
            [['id' => 12, 'car_number' => '17', 'car_make' => 'Mazda', 'car_model' => 'Miata', 'entrant_name' => 'Jordan Lee', 'event_name' => 'Fall Sprint', 'updated_at' => '2026-09-01 08:00:00']],
            [['id' => 6, 'driver_name' => 'Sam Patel', 'owner_name' => 'Jordan Lee', 'season' => 2026, 'updated_at' => '2026-09-02 12:00:00']]
        );
        $this->assertSame(['car_photos', 'gear_photos', 'declaration'], array_column($items, 'kind'));
        $this->assertSame('Car pre-tech photos: #17 Mazda Miata', $items[0]['title']);
        $this->assertSame('Jordan Lee · Fall Sprint', $items[0]['detail']);
        $this->assertSame('inspect.php?action=tech-sheet&id=12#pretech-review', $items[0]['url']);
        $this->assertSame('Gear pre-tech photos: Sam Patel', $items[1]['title']);
        $this->assertSame('Entered by Jordan Lee · 2026', $items[1]['detail']);
        $this->assertSame('inspect.php?action=gear-record&id=6#gear-review', $items[1]['url']);
        $this->assertSame('Class declaration: #42 2004 Honda S2000', $items[2]['title']);
        $this->assertSame('Jordan Lee · GT3', $items[2]['detail']);
        $this->assertSame('inspect.php?action=declaration&id=4', $items[2]['url']);
        $this->assertSame('2026-09-03 09:00:00', $items[2]['since']);
        $this->assertSame([], inspectReviewQueue([], [], []));
    }

    public function testClassingFiltersAreValidated(): void
    {
        $this->assertSame(['q' => 's2000', 'class' => 'GT3', 'season' => 2026, 'status' => 'accepted', 'car' => 4, 'page' => 2],
            inspectClassingFilters(['q' => '  s2000 ', 'class' => 'gt3', 'season' => '2026', 'status' => 'accepted', 'car' => '4', 'page' => '2']));
        $this->assertSame(['q' => '', 'class' => '', 'season' => 0, 'status' => '', 'car' => 0, 'page' => 1],
            inspectClassingFilters(['q' => ['x'], 'class' => 'GT9', 'season' => '1999', 'status' => 'bogus', 'car' => '-3', 'page' => '0']));
        $this->assertSame(100, mb_strlen(inspectClassingFilters(['q' => str_repeat('é', 150)])['q'], 'UTF-8'));
    }

    public function testIceRosterUsesTheClubsIceSheetsAndTheSheetClass(): void
    {
        $car = ['id' => 3, 'owner_user_id' => 4, 'car_number' => '7', 'year' => '1985', 'make' => 'Chevrolet', 'model' => 'Chevette', 'owner_name' => 'Sam', 'tagged' => 1];
        $iceSheet = ['id' => 9, 'car_id' => 3, 'user_id' => 4, 'season' => 2027, 'discipline' => 'ice', 'club' => 'NASCC', 'class' => 'CH',
                     'status' => 'teched', 'accepted_via' => 'in_person', 'photo_status' => null, 'driver_name' => 'Sam', 'event_id' => 20];
        $otherClub = ['id' => 10] + ['club' => 'WSCC'] + $iceSheet;
        $rows = inspectRosterRows([$car], [$iceSheet], [$iceSheet, $otherClub], [], [], [], [], 2027, ['discipline' => 'ice', 'club' => 'NASCC']);
        $this->assertSame('accepted', $rows[0]['status']['state']);
        $this->assertSame('CH — Chevette (NASCC)', $rows[0]['ice_class']);

        $wscc = inspectRosterRows([$car], [], [$otherClub], [], [], [], [], 2027, ['discipline' => 'ice', 'club' => 'NASCC']);
        $this->assertSame('none', $wscc[0]['status']['state']);   // a WSCC sheet doesn't tech the car for NASCC
    }

    public function testIceRowWithASheetIsNotCountedOrFilteredAsClassNotAccepted(): void
    {
        $iceRow = $this->filterRow('accepted', true, null, ['accepted'], 'CH — Chevette (NASCC)');
        $summerNoClass = $this->filterRow('accepted', true, null, ['accepted']);
        $rows = [$iceRow, $summerNoClass];

        $this->assertSame([$summerNoClass], inspectRosterFilter($rows, 'class_not_accepted'));
        $this->assertSame(['all' => 2, 'needs_decals' => 2, 'needs_tech' => 0, 'no_sheet' => 0, 'class_not_accepted' => 1], inspectRosterCounts($rows));
    }

    public function testClassingQueryKeepsOnlySetFilters(): void
    {
        $f = inspectClassingFilters(['q' => 'honda civic', 'class' => 'GT3']);
        $this->assertSame('inspect.php?action=classing&q=honda+civic&class=GT3', inspectClassingQuery($f));
        $this->assertSame('inspect.php?action=classing&q=honda+civic&class=GT3&page=3', inspectClassingQuery($f, ['page' => 3]));
        $this->assertSame('inspect.php?action=classing', inspectClassingQuery(inspectClassingFilters([])));
    }

    public function testReviewQueueMarksIcePhotoSets(): void
    {
        $sheet = ['id' => 9, 'car_number' => '7', 'car_make' => 'Honda', 'car_model' => 'Civic', 'entrant_name' => 'Sam',
                  'event_name' => 'NASCC Ice #1', 'updated_at' => '2026-12-01 10:00:00',
                  'discipline' => 'ice', 'club' => 'NASCC', 'class' => 'LS'];
        $gear = ['id' => 4, 'driver_name' => 'Sam', 'owner_name' => 'Jordan', 'season' => 2027, 'updated_at' => '2026-12-02 10:00:00', 'discipline' => 'ice'];
        $items = inspectReviewQueue([], [$sheet], [$gear]);
        $this->assertSame('Sam · NASCC Ice #1 · Ice · LS — Limited Stud (NASCC)', $items[0]['detail']);
        $this->assertSame('Entered by Jordan · Ice 2027', $items[1]['detail']);

        $summer = inspectReviewQueue([], [['discipline' => 'summer', 'club' => null, 'class' => 'IT1'] + $sheet], []);
        $this->assertSame('Sam · NASCC Ice #1', $summer[0]['detail']);
    }
}
