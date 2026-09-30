<?php
// wcma-calculator/tests/AtTrackTaDriftTest.php
require_once __DIR__ . '/../events-lib.php';

use PHPUnit\Framework\TestCase;

final class AtTrackTaDriftTest extends TestCase
{
    public function testTaDriftChoiceIsPerClubAndSeparateFromRace(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'tad' . uniqid() . '@example.com', 'name' => 'TA Driver', 'password_hash' => 'x', 'google_id' => null]);
        $car = test_make_car($pdo, $u, '86');

        $this->assertTrue(eventsSetAtTrack($pdo, $u, 'car', $car, 2026, TECH_TIER_TA_DRIFT, 'WSCC')['ok']);
        $this->assertTrue(eventsSetAtTrack($pdo, $u, 'car', $car, 2026, TECH_TIER_TA_DRIFT, 'WSCC')['ok']);   // no duplicate
        $this->assertSame(["car:$car@tad:WSCC:2026"], db_get_at_track_keys($pdo, [$car], [], 2026));

        eventsSetAtTrack($pdo, $u, 'car', $car, 2026);
        $this->assertEqualsCanonicalizing(["car:$car@tad:WSCC:2026", "car:$car@2026"], db_get_at_track_keys($pdo, [$car], [], 2026));
        $this->assertSame([], db_get_at_track_keys($pdo, [$car], [], 2026, DISCIPLINE_ICE));
    }

    public function testRejectsDriversBadClubsAndOtherPeoplesCars(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'tad' . uniqid() . '@example.com', 'name' => 'TA Driver', 'password_hash' => 'x', 'google_id' => null]);
        $other = db_create_user($pdo, ['email' => 'o' . uniqid() . '@example.com', 'name' => 'Other', 'password_hash' => 'x', 'google_id' => null]);
        $driver = db_find_or_create_driver($pdo, $u, 'TA Driver');

        $this->assertFalse(eventsSetAtTrack($pdo, $u, 'driver', $driver, 2026, TECH_TIER_TA_DRIFT, 'WSCC')['ok']);
        $this->assertFalse(eventsSetAtTrack($pdo, $u, 'car', test_make_car($pdo, $u, '86'), 2026, TECH_TIER_TA_DRIFT, '')['ok']);
        $this->assertFalse(eventsSetAtTrack($pdo, $u, 'car', test_make_car($pdo, $other, '7'), 2026, TECH_TIER_TA_DRIFT, 'WSCC')['ok']);
    }
}
