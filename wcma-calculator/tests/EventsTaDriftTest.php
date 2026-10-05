<?php
// wcma-calculator/tests/EventsTaDriftTest.php
require_once __DIR__ . '/../events-lib.php';

use PHPUnit\Framework\TestCase;

final class EventsTaDriftTest extends TestCase
{
    /** @return array{0: PDO, 1: int, 2: int, 3: int, 4: array<string, int>} */
    private function world(): array {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'tad' . uniqid() . '@example.com', 'name' => 'TA Driver', 'password_hash' => 'x', 'google_id' => null]);
        $car = db_create_car($pdo, $u, ['car_number' => '42', 'make' => 'Honda', 'model' => 'S2000', 'disciplines' => 'summer']);
        $taCar = db_create_car($pdo, $u, ['car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'disciplines' => 'ta_drift']);
        $events = [
            'wscc' => db_create_event($pdo, 'WSCC TA', '2026-07-12', null, 'summer', 'WSCC'),
            'wscc2' => db_create_event($pdo, 'WSCC TA 2', '2026-08-16', null, 'summer', 'WSCC'),
            'noclub' => db_create_event($pdo, 'Open Day', '2026-08-01', null),
            'ice' => db_create_event($pdo, 'Ice Drift', '2027-01-16', null, 'ice', 'WSCC'),
        ];
        return [$pdo, $u, $car, $taCar, $events];
    }

    private function formats(PDO $pdo, int $u, int $event, int $car): string {
        return (string)db_get_entry($pdo, $u, $event, $car)['formats'];
    }

    public function testNewEntryDefaultsToRace(): void
    {
        [$pdo, $u, $car, , $e] = $this->world();
        $this->assertTrue(eventsTagCar($pdo, $u, $e['wscc'], $car)['ok']);
        $this->assertSame('race', $this->formats($pdo, $u, $e['wscc'], $car));
    }

    public function testTaDriftOnlyCarDefaultsToTimeAttack(): void
    {
        [$pdo, $u, , $taCar, $e] = $this->world();
        eventsTagCar($pdo, $u, $e['wscc'], $taCar);
        $this->assertSame('ta', $this->formats($pdo, $u, $e['wscc'], $taCar));
    }

    public function testDefaultsFollowTheCarsLastSummerEntry(): void
    {
        [$pdo, $u, $car, , $e] = $this->world();
        $this->assertTrue(eventsTagCar($pdo, $u, $e['wscc'], $car, ['drift', 'ta'])['ok']);
        eventsTagCar($pdo, $u, $e['wscc2'], $car);
        $this->assertSame('ta,drift', $this->formats($pdo, $u, $e['wscc2'], $car));
    }

    public function testNoHostClubMeansRaceOnly(): void
    {
        [$pdo, $u, $car, $taCar, $e] = $this->world();
        eventsTagCar($pdo, $u, $e['noclub'], $taCar);
        $this->assertSame('race', $this->formats($pdo, $u, $e['noclub'], $taCar));

        $r = eventsTagCar($pdo, $u, $e['noclub'], $car, ['ta']);
        $this->assertSame(['ok' => false, 'error' => ENTRY_NO_HOST_CLUB], $r);
        $this->assertNull(db_get_entry($pdo, $u, $e['noclub'], $car));   // refused before tagging
    }

    public function testIceEntryIsAlwaysRace(): void
    {
        [$pdo, $u, $car, , $e] = $this->world();
        $this->assertTrue(eventsTagCar($pdo, $u, $e['ice'], $car, ['drift'], true)['ok']);
        $entry = db_get_entry($pdo, $u, $e['ice'], $car);
        $this->assertSame('race', $entry['formats']);
        $this->assertNull($entry['supps_ack_at']);
    }

    public function testRetagKeepsChosenFormats(): void
    {
        [$pdo, $u, $car, , $e] = $this->world();
        eventsTagCar($pdo, $u, $e['wscc'], $car, ['ta'], true);
        $ack = db_get_entry($pdo, $u, $e['wscc'], $car)['supps_ack_at'];
        eventsTagCar($pdo, $u, $e['wscc'], $car);   // e.g. the auto-tag when a sheet is submitted
        $this->assertSame('ta', $this->formats($pdo, $u, $e['wscc'], $car));
        $this->assertSame($ack, db_get_entry($pdo, $u, $e['wscc'], $car)['supps_ack_at']);
    }

    public function testRegulationsTickIsKeptWhileTicked(): void
    {
        [$pdo, $u, $car, , $e] = $this->world();
        eventsTagCar($pdo, $u, $e['wscc'], $car, ['ta'], true);
        $ack = db_get_entry($pdo, $u, $e['wscc'], $car)['supps_ack_at'];
        $this->assertNotNull($ack);

        $this->assertTrue(eventsSetFormats($pdo, $u, $e['wscc'], $car, ['ta', 'drift'], true)['ok']);
        $this->assertSame($ack, db_get_entry($pdo, $u, $e['wscc'], $car)['supps_ack_at']);

        // Switching to Race clears nothing (spec §3; bug list 2026-10-02 #5): the box stays ticked, and
        // switching back to Time Attack still has the first tick.
        eventsSetFormats($pdo, $u, $e['wscc'], $car, ['race', 'ta'], true);
        $this->assertSame($ack, db_get_entry($pdo, $u, $e['wscc'], $car)['supps_ack_at']);
        eventsSetFormats($pdo, $u, $e['wscc'], $car, ['race'], true);
        $this->assertSame($ack, db_get_entry($pdo, $u, $e['wscc'], $car)['supps_ack_at']);
        eventsSetFormats($pdo, $u, $e['wscc'], $car, ['ta'], true);
        $this->assertSame($ack, db_get_entry($pdo, $u, $e['wscc'], $car)['supps_ack_at']);

        // Unticking the box clears it.
        eventsSetFormats($pdo, $u, $e['wscc'], $car, ['ta'], false);
        $this->assertNull(db_get_entry($pdo, $u, $e['wscc'], $car)['supps_ack_at']);
    }

    public function testAnEventWithNoHostClubNeverStoresTheTick(): void
    {
        [$pdo, $u, $car, , $e] = $this->world();
        $this->assertTrue(eventsTagCar($pdo, $u, $e['noclub'], $car, ['race'], true)['ok']);
        $this->assertNull(db_get_entry($pdo, $u, $e['noclub'], $car)['supps_ack_at']);
    }

    public function testSetFormatsNeedsAnEntryAndValidFormats(): void
    {
        [$pdo, $u, $car, , $e] = $this->world();
        $this->assertSame('Add this car to the event first.', eventsSetFormats($pdo, $u, $e['wscc'], $car, ['ta'], false)['error']);
        eventsTagCar($pdo, $u, $e['wscc'], $car);
        $this->assertSame(ENTRY_FORMAT_ERROR, eventsSetFormats($pdo, $u, $e['wscc'], $car, [], false)['error']);
        $this->assertSame(ENTRY_FORMAT_ERROR, eventsSetFormats($pdo, $u, $e['wscc'], $car, ['rally'], false)['error']);
        $this->assertSame('race', $this->formats($pdo, $u, $e['wscc'], $car));

        $other = db_create_user($pdo, ['email' => 'o' . uniqid() . '@example.com', 'name' => 'Other', 'password_hash' => 'x', 'google_id' => null]);
        $this->assertFalse(eventsSetFormats($pdo, $other, $e['wscc'], $car, ['ta'], false)['ok']);
    }
}
