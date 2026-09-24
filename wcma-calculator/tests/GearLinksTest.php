<?php
// wcma-calculator/tests/GearLinksTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../gear-lib.php';

use PHPUnit\Framework\TestCase;

final class GearLinksTest extends TestCase
{
    private function gear(int $id, int $owner, string $name, int $season = 2026, array $o = []): array {
        return array_merge([
            'id' => $id, 'owner_user_id' => $owner, 'driver_name' => $name, 'driver_name_norm' => gearNameNorm($name),
            'licence_no' => null, 'season' => $season, 'status' => 'open', 'photo_status' => null, 'accepted_via' => null,
        ], $o);
    }

    private function sheet(array $o = []): array {
        return array_merge(['id' => 1, 'user_id' => 5, 'season' => 2026, 'driver_name' => 'Jane Racer'], $o);
    }

    public function testLinksDriverOneAndAdditionalDriversInOrder(): void
    {
        $drivers = [
            ['driver_number' => 2, 'driver_name' => 'Sam Coach'],
            ['driver_number' => 3, 'driver_name' => 'Pat Nobody'],
        ];
        $links = gearLinksForSheet($this->sheet(), $drivers, [
            $this->gear(10, 5, 'Jane Racer', 2026, ['status' => 'accepted', 'accepted_via' => 'photos', 'photo_status' => 'accepted']),
            $this->gear(11, 5, 'sam coach', 2026, ['photo_status' => 'submitted']),
        ]);

        $this->assertSame([1, 2, 3], array_column($links, 'driver_number'));
        $this->assertSame(['Jane Racer', 'Sam Coach', 'Pat Nobody'], array_column($links, 'name'));
        $this->assertSame(10, (int)$links[0]['gear']['id']);
        $this->assertSame(['state' => 'accepted', 'via' => 'photos'], $links[0]['status']);
        $this->assertSame(11, (int)$links[1]['gear']['id']);
        $this->assertSame('pending_review', $links[1]['status']['state']);
        $this->assertNull($links[2]['gear']);
        $this->assertSame(['state' => 'none', 'via' => null], $links[2]['status']);
        $this->assertSame('pat nobody', $links[2]['name_norm']);
    }

    public function testMatchingIgnoresWhitespaceCaseOtherOwnersAndOtherSeasons(): void
    {
        $links = gearLinksForSheet($this->sheet(['driver_name' => "  JANE   racer "]), [], [
            $this->gear(1, 6, 'Jane Racer'),          // another owner
            $this->gear(2, 5, 'Jane Racer', 2025),    // another season
            $this->gear(3, 5, 'Jane Racer', 2026),
        ]);
        $this->assertCount(1, $links);
        $this->assertSame(3, (int)$links[0]['gear']['id']);
        $this->assertSame('JANE racer', $links[0]['name']);   // whitespace collapsed, case kept for display
    }

    public function testNoRecordsMeansNoneStatusAndBlankNamesAreSkipped(): void
    {
        $links = gearLinksForSheet($this->sheet(['driver_name' => '   ']), [['driver_number' => 2, 'driver_name' => ' ']], []);
        $this->assertSame([], $links);

        $links = gearLinksForSheet($this->sheet(), [], []);
        $this->assertCount(1, $links);
        $this->assertNull($links[0]['gear']);
        $this->assertSame('none', $links[0]['status']['state']);
    }

    public function testSheetWithoutSeasonFallsBackToTheCurrentYear(): void
    {
        $now = gearSeasonNow();
        $links = gearLinksForSheet($this->sheet(['season' => null]), [], [$this->gear(1, 5, 'Jane Racer', $now)]);
        $this->assertNotNull($links[0]['gear']);
    }

    public function testNameSuggestionsAreDistinctSortedAndSeasonScoped(): void
    {
        $names = gearNameSuggestions([
            $this->gear(1, 5, 'zed'), $this->gear(2, 5, 'Amy'), $this->gear(3, 5, 'Bob', 2025), $this->gear(4, 5, 'amy'),
        ], 2026);
        $this->assertSame(['Amy', 'amy', 'zed'], $names);
        $this->assertSame([], gearNameSuggestions([], 2026));
    }

    public function testAttachToRosterUsesEachSheetOwnersRecordsAndDrivers(): void
    {
        $rows = [
            ['sheet' => $this->sheet(['id' => 1, 'user_id' => 5, 'driver_name' => 'Jane Racer']), 'status' => ['state' => 'none']],
            ['sheet' => $this->sheet(['id' => 2, 'user_id' => 6, 'driver_name' => 'Jane Racer']), 'status' => ['state' => 'none']],
        ];
        $attached = gearAttachToRoster($rows, [1 => [['driver_number' => 2, 'driver_name' => 'Sam Coach']]], [
            $this->gear(10, 5, 'Jane Racer'),
            $this->gear(11, 5, 'Sam Coach'),
        ]);

        $this->assertSame($rows[0]['sheet'], $attached[0]['sheet']);
        $this->assertSame(['state' => 'none'], $attached[0]['status']);
        $this->assertCount(2, $attached[0]['gear_links']);
        $this->assertNotNull($attached[0]['gear_links'][1]['gear']);
        $this->assertCount(1, $attached[1]['gear_links']);
        $this->assertNull($attached[1]['gear_links'][0]['gear']);   // owner 6 has no records
    }

    private function fixture(PDO $pdo): array {
        $userId = db_create_user($pdo, ['email' => 'racer@example.com', 'name' => 'Racer', 'password_hash' => 'x', 'google_id' => null]);
        $subId = db_insert_submission($pdo, [
            ':submitted_at' => date('Y-m-d H:i:s'), ':name' => 'Racer', ':email' => 'racer@example.com',
            ':year' => '2020', ':make' => 'Mazda', ':model' => 'MX-5', ':comments' => null,
            ':competition_weight' => 2200, ':declared_hp' => 150, ':dyno_hp' => null,
            ':chassis_display' => null, ':body_mods_display' => null, ':transmission_display' => null,
            ':drivetrain_display' => null, ':tires_display' => null, ':brake_suspension' => null,
            ':chassis_value' => 0, ':body_mods_value' => 0, ':transmission_value' => 0,
            ':drivetrain_value' => 0, ':tires_value' => 0, ':brake_suspension_value' => 0,
            ':weight_factor' => 0, ':modification_factor' => 0, ':base_ratio' => 14.67, ':modified_ratio' => 14.67,
            ':calculated_class' => 'IT1', ':user_id' => $userId,
        ]);
        $eventId = db_create_event($pdo, 'Spring Sprint', '2026-05-10', null);
        $sheetOf = fn(string $number): int => db_insert_tech_sheet($pdo, [
            'submission_id' => $subId, 'user_id' => $userId, 'event_id' => $eventId, 'sheet_type' => 'endurance',
            'entrant_name' => 'Racer', 'driver_name' => 'Racer', 'car_make' => 'Mazda', 'car_model' => 'MX-5',
            'car_colour' => 'Red', 'car_number' => $number, 'class' => 'IT1', 'engine_cc' => '1800', 'engine_hp' => '150',
            'car_weight' => 2200, 'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1,
        ]);
        return [$sheetOf('42'), $sheetOf('7'), $sheetOf('99')];
    }

    public function testDriversForSheetsGroupsByDriverNumberAndOmitsSheetsWithNone(): void
    {
        $pdo = make_temp_pdo();
        [$a, $b, $c] = $this->fixture($pdo);
        db_add_tech_sheet_driver($pdo, $a, 3, 'Pat', '{}');
        db_add_tech_sheet_driver($pdo, $a, 2, 'Sam', '{}');
        db_add_tech_sheet_driver($pdo, $b, 2, 'Lee', '{}');

        $map = db_get_drivers_for_sheets($pdo, [$a, $b, $c]);
        $this->assertSame([2, 3], array_map(fn($d) => (int)$d['driver_number'], $map[$a]));
        $this->assertSame(['Sam', 'Pat'], array_column($map[$a], 'driver_name'));
        $this->assertSame(['Lee'], array_column($map[$b], 'driver_name'));
        $this->assertArrayNotHasKey($c, $map);

        $this->assertSame([], db_get_drivers_for_sheets($pdo, []));
        $this->assertSame([], db_get_drivers_for_sheets($pdo, [999999]));
    }
}
