<?php
// wcma-calculator/tests/AtTrackIceTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../events-lib.php';
require_once __DIR__ . '/../ice-rules.php';

final class AtTrackIceTest extends TestCase
{
    /** @return array{0: PDO, 1: int, 2: int, 3: int} pdo, user, car, driver */
    private function setUpSubjects(): array {
        $pdo = make_temp_pdo();
        $uid = db_create_user($pdo, ['email' => 'a' . uniqid() . '@example.com', 'name' => 'Owner', 'password_hash' => 'x', 'google_id' => null]);
        $car = test_make_car($pdo, $uid, '7');
        $driver = db_create_driver($pdo, $uid, 'Sam');
        return [$pdo, $uid, $car, $driver];
    }

    public function testKeyFormats(): void
    {
        $this->assertSame('car:5@2026', atTrackKey('car', 5, 2026));
        $this->assertSame('car:5@ice:NASCC:2027', atTrackKey('car', 5, 2027, 'ice', 'NASCC'));
        $this->assertSame('driver:9@ice:2027', atTrackKey('driver', 9, 2027, 'ice'));
    }

    public function testSummerAtTrackStillDedupes(): void
    {
        [$pdo, , $car] = $this->setUpSubjects();
        db_set_at_track($pdo, 'car', $car, 2026);
        db_set_at_track($pdo, 'car', $car, 2026);
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM at_track_choices")->fetchColumn());
        $this->assertSame(["car:$car@2026"], db_get_at_track_keys($pdo, [$car], [], 2026));
    }

    public function testIceChoicesAreKeptApartFromSummerAndBetweenClubs(): void
    {
        [$pdo, , $car, $driver] = $this->setUpSubjects();
        db_set_at_track($pdo, 'car', $car, 2027, 'ice', 'NASCC');
        db_set_at_track($pdo, 'driver', $driver, 2027, 'ice');
        $this->assertSame([], db_get_at_track_keys($pdo, [$car], [$driver], 2027));
        $this->assertEqualsCanonicalizing(["car:$car@ice:NASCC:2027", "driver:$driver@ice:2027"],
            db_get_at_track_keys($pdo, [$car], [$driver], 2027, 'ice'));
        $this->assertNotContains(atTrackKey('car', $car, 2027, 'ice', 'WSCC'), db_get_at_track_keys($pdo, [$car], [], 2027, 'ice'));
    }

    public function testEventsSetAtTrackValidatesDisciplineAndClub(): void
    {
        [$pdo, $uid, $car] = $this->setUpSubjects();
        $this->assertTrue(eventsSetAtTrack($pdo, $uid, 'car', $car, 2027, 'ice', 'WSCC')['ok']);
        $this->assertFalse(eventsSetAtTrack($pdo, $uid, 'car', $car, 2027, 'ice', 'XYZ')['ok']);
        $this->assertFalse(eventsSetAtTrack($pdo, $uid, 'car', $car, 2027, 'ice', '')['ok']);
        $this->assertFalse(eventsSetAtTrack($pdo, $uid, 'car', $car, 2027, 'rally', '')['ok']);
        $this->assertTrue(eventsSetAtTrack($pdo, $uid, 'car', $car, 2026)['ok']);
    }

    public function testLegacyAtTrackMigratesAsSummer(): void
    {
        $pdo = test_make_legacy_pdo();
        $pdo->exec("INSERT INTO at_track_choices (subject_type, subject_id, season, created_at) VALUES ('car', 3, 2026, '2026-05-01')");
        db_init($pdo);
        db_init($pdo);
        $this->assertSame(['car:3@2026'], db_get_at_track_keys($pdo, [3], [], 2026));
        db_set_at_track($pdo, 'car', 3, 2026);   // still de-duplicated after the migration
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM at_track_choices")->fetchColumn());
    }
}
