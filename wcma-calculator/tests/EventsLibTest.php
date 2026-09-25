<?php
// wcma-calculator/tests/EventsLibTest.php
require_once __DIR__ . '/../events-lib.php';
require_once __DIR__ . '/../gear-lib.php';

use PHPUnit\Framework\TestCase;

final class EventsLibTest extends TestCase
{
    private function setUpWorld(PDO $pdo): array {
        $u = db_create_user($pdo, ['email' => 'j@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $other = db_create_user($pdo, ['email' => 'o@example.com', 'name' => 'Other Person', 'password_hash' => 'x', 'google_id' => null]);
        $car = db_create_car($pdo, $u, ['car_number' => '42', 'make' => 'Honda', 'model' => 'S2000']);
        $theirs = db_create_car($pdo, $other, ['car_number' => '7', 'make' => 'Mazda', 'model' => 'MX-5']);
        $event = db_create_event($pdo, 'Fall Sprint', '2026-10-11', null);
        return [$u, $other, $car, $theirs, $event];
    }

    public function testTagIsIdempotentAndUntagRemoves(): void
    {
        $pdo = make_temp_pdo();
        [$u, , $car, , $event] = $this->setUpWorld($pdo);

        $this->assertTrue(eventsTagCar($pdo, $u, $event, $car)['ok']);
        $this->assertTrue(eventsTagCar($pdo, $u, $event, $car)['ok']);
        $this->assertSame([['event_id' => $event, 'car_id' => $car]], array_map(
            fn($r) => ['event_id' => (int)$r['event_id'], 'car_id' => (int)$r['car_id']], db_get_user_event_plans($pdo, $u)));

        $this->assertTrue(eventsUntagCar($pdo, $u, $event, $car)['ok']);
        $this->assertSame([], db_get_user_event_plans($pdo, $u));
    }

    public function testCannotTagSomeoneElsesCarAnArchivedCarOrAnInactiveEvent(): void
    {
        $pdo = make_temp_pdo();
        [$u, , $car, $theirs, $event] = $this->setUpWorld($pdo);
        $this->assertFalse(eventsTagCar($pdo, $u, $event, $theirs)['ok']);

        db_set_event_active($pdo, $event, false);
        $this->assertFalse(eventsTagCar($pdo, $u, $event, $car)['ok']);

        db_set_event_active($pdo, $event, true);
        db_archive_car($pdo, $u, $car);
        $this->assertFalse(eventsTagCar($pdo, $u, $event, $car)['ok']);
    }

    public function testAtTrackChoiceIsOwnedAndIdempotent(): void
    {
        $pdo = make_temp_pdo();
        [$u, $other, $car, $theirs] = $this->setUpWorld($pdo);
        $self = (int)db_get_self_driver($pdo, $u)['id'];

        $this->assertTrue(eventsSetAtTrack($pdo, $u, 'car', $car, 2026)['ok']);
        $this->assertTrue(eventsSetAtTrack($pdo, $u, 'car', $car, 2026)['ok']);
        $this->assertTrue(eventsSetAtTrack($pdo, $u, 'driver', $self, 2026)['ok']);
        $this->assertFalse(eventsSetAtTrack($pdo, $u, 'car', $theirs, 2026)['ok']);
        $this->assertFalse(eventsSetAtTrack($pdo, $u, 'driver', (int)db_get_self_driver($pdo, $other)['id'], 2026)['ok']);
        $this->assertFalse(eventsSetAtTrack($pdo, $u, 'boat', $car, 2026)['ok']);

        $this->assertEqualsCanonicalizing(["car:$car", "driver:$self"], db_get_at_track_keys($pdo, [$car], [$self], 2026));
        $this->assertSame([], db_get_at_track_keys($pdo, [$car], [$self], 2027));
    }

    public function testGearRecordForDriver(): void
    {
        $pdo = make_temp_pdo();
        [$u] = $this->setUpWorld($pdo);
        $self = (int)db_get_self_driver($pdo, $u)['id'];
        $this->assertNull(db_get_gear_record_for_driver($pdo, $self, 2026));
        gearCreate($pdo, $u, 'Jordan Lee', '', 2026);
        $this->assertSame('Jordan Lee', db_get_gear_record_for_driver($pdo, $self, 2026)['driver_name']);
    }
}
