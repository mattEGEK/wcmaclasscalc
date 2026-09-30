<?php
// wcma-calculator/tests/EventsCodriversTest.php
require_once __DIR__ . '/../events-lib.php';

use PHPUnit\Framework\TestCase;

final class EventsCodriversTest extends TestCase
{
    /** @return array{0: PDO, 1: int, 2: int, 3: int, 4: int, 5: int} pdo, user, car, self, sam, event */
    private function world(): array {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'ec' . uniqid() . '@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $car = db_create_car($pdo, $u, ['car_number' => '42', 'make' => 'Honda', 'model' => 'S2000', 'disciplines' => 'summer']);
        $self = (int)db_get_self_driver($pdo, $u)['id'];
        $sam = db_create_driver($pdo, $u, 'Sam Lee');
        db_add_car_driver($pdo, $u, $car, $sam);
        $event = db_create_event($pdo, 'Fall Sprint', '2099-10-11', null);
        return [$pdo, $u, $car, $self, $sam, $event];
    }

    public function testDriversFromPost(): void
    {
        $this->assertNull(entryDriversFromPost([]));
        $this->assertSame([], entryDriversFromPost(['drivers_shown' => '1']));
        $this->assertSame(['5', '6'], entryDriversFromPost(['drivers_shown' => '1', 'drivers' => ['5', '6']]));
    }

    public function testChoicesAreTheOwnerThenTheCarsCoDrivers(): void
    {
        [$pdo, $u, $car, $self, $sam] = $this->world();
        db_create_driver($pdo, $u, 'Not On This Car');
        $this->assertSame([
            ['id' => $self, 'name' => 'Jordan Lee', 'isSelf' => true],
            ['id' => $sam, 'name' => 'Sam Lee', 'isSelf' => false],
        ], eventsCarDriverChoices($pdo, $u, $car));
        $this->assertSame([$self, $sam], array_map(fn(array $d): int => (int)$d['id'], eventsSheetDriverRows($pdo, $u, $car)));
    }

    public function testTagDefaultsToTheOwnerAndTakesAPostedList(): void
    {
        [$pdo, $u, $car, $self, $sam, $event] = $this->world();
        $this->assertTrue(eventsTagCar($pdo, $u, $event, $car)['ok']);
        $entryId = (int)db_get_entry($pdo, $u, $event, $car)['id'];
        $this->assertSame([$self], db_get_entry_driver_ids($pdo, $entryId));

        $this->assertTrue(eventsSetFormats($pdo, $u, $event, $car, ['race'], false, [(string)$sam])['ok']);
        $this->assertSame([$sam], db_get_entry_driver_ids($pdo, $entryId));
        $this->assertTrue(eventsSetFormats($pdo, $u, $event, $car, ['race'], false)['ok']);   // null leaves drivers alone
        $this->assertSame([$sam], db_get_entry_driver_ids($pdo, $entryId));
    }

    public function testForeignOrOffListDriverIsRefused(): void
    {
        [$pdo, $u, $car, $self, $sam, $event] = $this->world();
        eventsTagCar($pdo, $u, $event, $car);
        $entryId = (int)db_get_entry($pdo, $u, $event, $car)['id'];
        $offList = db_create_driver($pdo, $u, 'Not On This Car');
        $other = db_create_user($pdo, ['email' => 'o' . uniqid() . '@example.com', 'name' => 'Other', 'password_hash' => 'x', 'google_id' => null]);
        $foreign = db_create_driver($pdo, $other, 'Foreign');

        foreach ([[$offList], [$foreign], [$self, 'abc']] as $bad) {
            $r = eventsSetFormats($pdo, $u, $event, $car, ['race'], false, $bad);
            $this->assertSame([false, "Choose drivers from this car's list."], [$r['ok'], $r['error']]);
        }
        $r = eventsSetFormats($pdo, $u, $event, $car, ['race'], false, []);
        $this->assertSame([false, 'Tick at least one driver.'], [$r['ok'], $r['error']]);
        $this->assertSame([$self], db_get_entry_driver_ids($pdo, $entryId));    // nothing stored

        // A bad list on a new tag refuses the tag too.
        $next = db_create_event($pdo, 'Finale', '2099-10-25', null);
        $this->assertFalse(eventsTagCar($pdo, $u, $next, $car, ['race'], false, [])['ok']);
        $this->assertNull(db_get_entry($pdo, $u, $next, $car));
    }

    public function testAddAndRemoveCoDriver(): void
    {
        [$pdo, $u, $car, $self, $sam, $event] = $this->world();
        $pat = db_create_driver($pdo, $u, 'Pat Driver');
        $this->assertTrue(eventsAddCoDriver($pdo, $u, $car, ['driver_id' => (string)$pat])['ok']);
        $this->assertTrue(eventsAddCoDriver($pdo, $u, $car, ['driver_id' => 'new', 'new_name' => '  Alex   Kim '])['ok']);
        $this->assertSame(['Alex Kim', 'Pat Driver', 'Sam Lee'], array_map(fn(array $d): string => (string)$d['name'], db_get_car_drivers($pdo, $car)));
        $this->assertFalse(eventsAddCoDriver($pdo, $u, $car, ['driver_id' => 'new', 'new_name' => ' '])['ok']);
        $this->assertFalse(eventsAddCoDriver($pdo, $u, $car, ['driver_id' => (string)$self])['ok']);

        $this->assertTrue(eventsRemoveCoDriver($pdo, $u, $car, $pat)['ok']);
        $this->assertNotContains($pat, array_map(fn(array $d): int => (int)$d['id'], db_get_car_drivers($pdo, $car)));
    }

    public function testPrefillFollowsTheTickedDrivers(): void
    {
        [$pdo, $u, $car, $self, $sam, $event] = $this->world();
        $this->assertSame(['driver1' => null, 'others' => [], 'count' => 0], eventsSheetPrefill($pdo, $u, $car, $event));   // no entry
        eventsTagCar($pdo, $u, $event, $car);
        $this->assertSame(['driver1' => $self, 'others' => [], 'count' => 1], eventsSheetPrefill($pdo, $u, $car, $event));
        eventsSetFormats($pdo, $u, $event, $car, ['race'], false, [$sam, $self]);
        $this->assertSame(['driver1' => $self, 'others' => [$sam], 'count' => 2], eventsSheetPrefill($pdo, $u, $car, $event));
        eventsSetFormats($pdo, $u, $event, $car, ['race'], false, [$sam]);
        $this->assertSame(['driver1' => $sam, 'others' => [], 'count' => 1], eventsSheetPrefill($pdo, $u, $car, $event));
    }

    public function testASavedSheetsDriversJoinTheCarAndAreTicked(): void
    {
        [$pdo, $u, $car, $self, $sam, $event] = $this->world();
        eventsTagCar($pdo, $u, $event, $car);
        $sheet = test_make_ta_drift_sheet($pdo, $u, $car, db_create_event($pdo, 'WSCC TA', '2099-11-01', null, 'summer', 'WSCC'));
        $taEvent = (int)db_get_tech_sheet($pdo, $sheet)['event_id'];
        db_add_tech_sheet_driver($pdo, $sheet, 2, 'New Person', '{}');

        eventsSyncSheetDrivers($pdo, $u, $sheet);   // no entry for that event yet: only the car list changes
        $new = (int)db_find_driver($pdo, $u, 'New Person')['id'];
        $this->assertContains($new, array_map(fn(array $d): int => (int)$d['id'], db_get_car_drivers($pdo, $car)));

        db_tag_event($pdo, $u, $taEvent, $car);
        eventsSyncSheetDrivers($pdo, $u, $sheet);
        $entryId = (int)db_get_entry($pdo, $u, $taEvent, $car)['id'];
        $this->assertContains($new, db_get_entry_driver_ids($pdo, $entryId));
    }
}
