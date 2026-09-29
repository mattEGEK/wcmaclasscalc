<?php
// wcma-calculator/tests/ReadinessTaDriftTest.php
require_once __DIR__ . '/../tech-status.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../readiness-lib.php';
require_once __DIR__ . '/../events-lib.php';

use PHPUnit\Framework\TestCase;

final class ReadinessTaDriftTest extends TestCase
{
    private function world(array $o = []): array {
        return array_merge([
            'today' => '2026-06-01',
            'cars' => [3 => ['id' => 3, 'car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'disciplines' => 'ta_drift']],
            'events' => [
                ['id' => 20, 'name' => 'WSCC TA #1', 'event_date' => '2026-07-12', 'discipline' => 'summer', 'host_club' => 'WSCC'],
                ['id' => 21, 'name' => 'WSCC TA #2', 'event_date' => '2026-08-16', 'discipline' => 'summer', 'host_club' => 'WSCC'],
                ['id' => 22, 'name' => 'Open Day', 'event_date' => '2026-08-20', 'discipline' => 'summer', 'host_club' => null],
            ],
            'plans' => [['event_id' => 20, 'car_id' => 3, 'formats' => 'ta', 'supps_ack_at' => null]],
            'declarations' => [], 'sheets' => [], 'sheetDrivers' => [],
            'drivers' => [5 => ['id' => 5, 'name' => 'Jordan Lee'], 6 => ['id' => 6, 'name' => 'Sam Patel']],
            'selfDriverId' => 5, 'gear' => [], 'atTrack' => [],
        ], $o);
    }

    private function items(array $r, int $eventIndex = 0): array {
        $out = [];
        foreach ($r['events'][$eventIndex]['items'] as $i) $out[$i['kind'] . ':' . $i['subject_id']] = $i;
        return $out;
    }

    public function testTierAtEvent(): void
    {
        $summer = ['discipline' => 'summer', 'host_club' => 'WSCC'];
        $this->assertSame(TECH_TIER_TA_DRIFT, entryTierAtEvent($summer, 'ta,drift'));
        $this->assertSame(TECH_TIER_RACE, entryTierAtEvent($summer, 'race,ta'));
        $this->assertSame(TECH_TIER_RACE, entryTierAtEvent($summer, null));                                   // legacy entry
        $this->assertSame(TECH_TIER_RACE, entryTierAtEvent(['discipline' => 'summer', 'host_club' => ''], 'ta'));   // club removed since
        $this->assertSame(TECH_TIER_RACE, entryTierAtEvent(['discipline' => 'ice', 'host_club' => 'WSCC'], 'drift'));
    }

    public function testEntriesCarryFormatsAndTier(): void
    {
        $r = buildReadiness($this->world());
        $this->assertSame(['formats' => ['ta'], 'tier' => TECH_TIER_TA_DRIFT, 'supps_ack_at' => null], $r['events'][0]['entries'][3]);
    }

    public function testLegacyPlanWithoutFormatsIsRace(): void
    {
        $r = buildReadiness($this->world(['plans' => [['event_id' => 20, 'car_id' => 3]]]));
        $this->assertSame(TECH_TIER_RACE, $r['events'][0]['entries'][3]['tier']);
        $this->assertArrayHasKey('declaration:3', $this->items($r));
    }

    public function testTaDriftEntryAtEventWithoutHostClubFallsBackToRace(): void
    {
        $r = buildReadiness($this->world(['plans' => [['event_id' => 22, 'car_id' => 3, 'formats' => 'ta', 'supps_ack_at' => null]]]));
        $this->assertSame(TECH_TIER_RACE, $r['events'][0]['entries'][3]['tier']);
        $this->assertSame('Submit a tech sheet for #86', $this->items($r)['tech_sheet:3']['label']);
    }

    public function testRaceEntryNeedsRaceLevelGear(): void
    {
        $tadGear = ['id' => 40, 'season' => 2026, 'discipline' => 'summer', 'level' => 'ta_drift', 'status' => 'accepted', 'accepted_via' => 'in_person', 'photo_status' => null];
        $r = buildReadiness($this->world([
            'plans' => [['event_id' => 20, 'car_id' => 3, 'formats' => 'race', 'supps_ack_at' => null]],
            'gear' => ['5:2026' => $tadGear],
        ]));
        $gear = $this->items($r)['gear:5'];
        $this->assertSame('todo', $gear['state']);
        $this->assertSame("Jordan Lee's gear is checked for TA/Drift; racing needs race-level gear", $gear['label']);
        $this->assertSame('Bring race-level gear to tech at the track.', $gear['detail']);

        $race = ['level' => null] + $tadGear;
        $r = buildReadiness($this->world(['plans' => [['event_id' => 20, 'car_id' => 3, 'formats' => 'race', 'supps_ack_at' => null]], 'gear' => ['5:2026' => $race]]));
        $this->assertSame('done', $this->items($r)['gear:5']['state']);
    }

    public function testLoaderCarriesFormatsAndTheRegulationsTick(): void
    {
        $pdo = make_temp_pdo();
        $uid = db_create_user($pdo, ['email' => 'tad' . uniqid() . '@example.com', 'name' => 'TA Driver', 'password_hash' => 'x', 'google_id' => null]);
        $car = test_make_car($pdo, $uid, '86');
        $event = db_create_event($pdo, 'WSCC TA', date('Y-m-d', strtotime('+10 days')), null, 'summer', 'WSCC');
        $this->assertTrue(eventsTagCar($pdo, $uid, $event, $car, ['ta'], true)['ok']);
        $plan = loadReadinessInputs($pdo, $uid, date('Y-m-d'))['plans'][0];
        $this->assertSame('ta', $plan['formats']);
        $this->assertNotNull($plan['supps_ack_at']);
    }
}
