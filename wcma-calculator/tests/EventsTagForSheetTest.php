<?php
// wcma-calculator/tests/EventsTagForSheetTest.php
require_once __DIR__ . '/../events-lib.php';

use PHPUnit\Framework\TestCase;

final class EventsTagForSheetTest extends TestCase
{
    /** @return array{0: PDO, 1: int, 2: array, 3: array, 4: array} pdo, user, summer car, TA/Drift-only car, events by name */
    private function world(): array {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'tfs' . uniqid() . '@example.com', 'name' => 'Pat', 'password_hash' => 'x', 'google_id' => null]);
        $car = db_get_car($pdo, db_create_car($pdo, $u, ['car_number' => '42', 'make' => 'Honda', 'model' => 'S2000', 'disciplines' => 'summer']));
        $taCar = db_get_car($pdo, db_create_car($pdo, $u, ['car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'disciplines' => 'ta_drift']));
        $events = [];
        foreach ([['wscc', 'WSCC TA', 'summer', 'WSCC'], ['sprint', 'Sprint', 'summer', null], ['ice', 'Ice', 'ice', 'NASCC']] as [$k, $name, $disc, $club]) {
            $events[$k] = db_get_event($pdo, db_create_event($pdo, $name, '2026-07-12', null, $disc, $club));
        }
        return [$pdo, $u, $car, $taCar, $events];
    }

    private function entry(PDO $pdo, int $u, array $event, array $car): ?array {
        return db_get_entry($pdo, $u, (int)$event['id'], (int)$car['id']);
    }

    public function testNewEntryFromTaDriftSheetIsTimeAttack(): void
    {
        [$pdo, $u, $car, , $e] = $this->world();
        eventsTagForSheet($pdo, $u, $e['wscc'], $car, TECH_TIER_TA_DRIFT);
        $entry = $this->entry($pdo, $u, $e['wscc'], $car);
        $this->assertSame('ta', $entry['formats']);
        $this->assertNotNull($entry['supps_ack_at']);   // submitting the sheet counts as the regulations tick
    }

    public function testNewEntryKeepsItsDefaultsWhenTheyMatchTheSheet(): void
    {
        [$pdo, $u, , $taCar, $e] = $this->world();
        eventsTagCar($pdo, $u, (int)$e['sprint']['id'], (int)$taCar['id']);   // any earlier summer entry ...
        db_set_entry_formats($pdo, $u, (int)$e['sprint']['id'], (int)$taCar['id'], 'drift', null);   // ... that ran Drift
        eventsTagForSheet($pdo, $u, $e['wscc'], $taCar, TECH_TIER_TA_DRIFT);
        $this->assertSame('drift', $this->entry($pdo, $u, $e['wscc'], $taCar)['formats']);
    }

    public function testExistingRaceEntryIsLeftAlone(): void
    {
        [$pdo, $u, $car, , $e] = $this->world();
        eventsTagCar($pdo, $u, (int)$e['wscc']['id'], (int)$car['id'], ['race', 'ta']);
        eventsTagForSheet($pdo, $u, $e['wscc'], $car, TECH_TIER_TA_DRIFT);
        $entry = $this->entry($pdo, $u, $e['wscc'], $car);
        $this->assertSame('race,ta', $entry['formats']);
        $this->assertNull($entry['supps_ack_at']);
    }

    public function testExistingTaDriftEntryGetsTheRegulationsTick(): void
    {
        [$pdo, $u, $car, , $e] = $this->world();
        eventsTagCar($pdo, $u, (int)$e['wscc']['id'], (int)$car['id'], ['drift']);
        eventsTagForSheet($pdo, $u, $e['wscc'], $car, TECH_TIER_TA_DRIFT);
        $entry = $this->entry($pdo, $u, $e['wscc'], $car);
        $this->assertSame('drift', $entry['formats']);
        $this->assertNotNull($entry['supps_ack_at']);
    }

    public function testRaceSheetMakesARaceEntryEvenForATaDriftCar(): void
    {
        [$pdo, $u, , $taCar, $e] = $this->world();
        eventsTagForSheet($pdo, $u, $e['wscc'], $taCar, TECH_TIER_RACE);
        $entry = $this->entry($pdo, $u, $e['wscc'], $taCar);
        $this->assertSame('race', $entry['formats']);
        $this->assertNull($entry['supps_ack_at']);
    }

    public function testRaceAndIceSheetsStillTag(): void
    {
        [$pdo, $u, $car, , $e] = $this->world();
        eventsTagForSheet($pdo, $u, $e['sprint'], $car, TECH_TIER_RACE);
        eventsTagForSheet($pdo, $u, $e['ice'], $car, TECH_TIER_RACE);
        $this->assertSame('race', $this->entry($pdo, $u, $e['sprint'], $car)['formats']);
        $this->assertSame('race', $this->entry($pdo, $u, $e['ice'], $car)['formats']);
    }
}
