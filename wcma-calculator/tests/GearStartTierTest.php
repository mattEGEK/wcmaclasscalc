<?php
// wcma-calculator/tests/GearStartTierTest.php — "Add gear photos" from the Drivers page puts a driver
// whose user only does TA/Drift on the TA/Drift photo list, not the race one (bug list 2026-10-02, item 3).
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../garage-lib.php';

use PHPUnit\Framework\TestCase;

final class GearStartTierTest extends TestCase
{
    private const TODAY = '2026-06-01';

    private function event(int $id, string $club = 'WSCC'): array {
        return ['id' => $id, 'event_date' => '2026-07-12', 'discipline' => 'summer', 'host_club' => $club];
    }

    public function testNoCarsMeansRace(): void
    {
        $this->assertTrue(userRacesSummer([], [], [], [], [], self::TODAY));
    }

    public function testATaDriftOnlyCarDoesNotRace(): void
    {
        $cars = [3 => ['id' => 3, 'disciplines' => 'ta_drift']];
        $sheets = [['car_id' => 3, 'discipline' => 'summer', 'sheet_type' => 'ta_drift']];
        $this->assertFalse(userRacesSummer($cars, $sheets, [], [], [], self::TODAY));
        // Entered at a TA/Drift-only format: still not racing.
        $this->assertFalse(userRacesSummer($cars, $sheets, [], [['event_id' => 20, 'car_id' => 3, 'formats' => 'ta']], [$this->event(20)], self::TODAY));
    }

    public function testRaceActivityOnAnyCarMeansRace(): void
    {
        $ta = [3 => ['id' => 3, 'disciplines' => 'ta_drift']];
        // Entered in a race format.
        $this->assertTrue(userRacesSummer($ta, [], [], [['event_id' => 20, 'car_id' => 3, 'formats' => 'race']], [$this->event(20)], self::TODAY));
        // A class declaration.
        $this->assertTrue(userRacesSummer($ta, [], [3 => ['id' => 8]], [], [], self::TODAY));
        // A second, ordinary summer car.
        $this->assertTrue(userRacesSummer($ta + [4 => ['id' => 4, 'disciplines' => 'summer']], [], [], [], [], self::TODAY));
    }

    public function testAnIceOnlyUserIsNotTaDriftOnly(): void
    {
        // No summer car: not racing summer, but not using it either, so gear.php keeps the race default.
        $ice = [5 => ['id' => 5, 'disciplines' => 'ice']];
        $this->assertFalse(userRacesSummer($ice, [], [], [], [], self::TODAY));
        $this->assertFalse(userUsesSummer($ice, [], [], [], [], self::TODAY));
    }

    private function user(PDO $pdo): int {
        return db_create_user($pdo, ['email' => 'gs' . uniqid() . '@example.com', 'name' => 'Pat Winters', 'password_hash' => 'x', 'google_id' => null]);
    }

    public function testTaDriftOnlyStartsOnTheTaDriftList(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $driver = db_get_driver($pdo, db_find_or_create_driver($pdo, $u, 'Pat Winters'));
        $r = gearStartForDriver($pdo, $u, $driver, 2026, true, true);
        $this->assertTrue($r['ok']);
        $gear = db_get_gear_record($pdo, (int)$r['id']);
        $this->assertSame(GEAR_LEVEL_TA_DRIFT, $gear['photo_tier']);
        $this->assertSame(1, (int)$gear['caged']);
        $keys = array_keys(photoRequirementsFor($gear, 'gear'));
        $this->assertNotEmpty($keys);
        foreach ($keys as $k) $this->assertStringStartsWith('tad_', $k, 'only TA/Drift gear photos');
        // A second visit opens the same record.
        $this->assertSame($r['id'], gearStartForDriver($pdo, $u, $driver, 2026, true, true)['id']);
    }

    public function testARacerStartsOnTheRaceList(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $driver = db_get_driver($pdo, db_find_or_create_driver($pdo, $u, 'Pat Winters'));
        $gear = db_get_gear_record($pdo, (int)gearStartForDriver($pdo, $u, $driver, 2026, false, false)['id']);
        $this->assertNull($gear['photo_tier']);
    }

    public function testAnUntouchedRaceRecordMovesButOneWithPhotosUnderWayStays(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $driver = db_get_driver($pdo, db_find_or_create_driver($pdo, $u, 'Pat Winters'));
        $id = (int)gearCreate($pdo, $u, 'Pat Winters', '', 2026)['id'];
        gearStartForDriver($pdo, $u, $driver, 2026, true, false);
        $this->assertSame(GEAR_LEVEL_TA_DRIFT, db_get_gear_record($pdo, $id)['photo_tier']);

        $sam = db_get_driver($pdo, db_find_or_create_driver($pdo, $u, 'Sam Patel'));
        $id2 = (int)gearCreate($pdo, $u, 'Sam Patel', '', 2026)['id'];
        db_mark_gear_photos_draft($pdo, $id2);
        gearStartForDriver($pdo, $u, $sam, 2026, true, false);
        $this->assertNull(db_get_gear_record($pdo, $id2)['photo_tier'], 'race photos under way keep their list');
    }

    public function testTheHandlerUsesIt(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../gear.php'));
        $this->assertStringContainsString('$taDriftOnly = userUsesSummer(...$summerArgs) && !userRacesSummer(...$summerArgs);', $src);
        $this->assertStringContainsString('gearStartForDriver($pdo, $uid, $driver, $season, $taDriftOnly, $caged)', $src);
    }
}
