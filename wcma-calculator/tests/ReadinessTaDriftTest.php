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
        $this->assertSame(['formats' => ['ta'], 'tier' => TECH_TIER_TA_DRIFT, 'supps_ack_at' => null,
            'driverIds' => [5], 'driversKnown' => false], $r['events'][0]['entries'][3]);
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
        $this->assertSame('Send race gear photos, or bring race-level gear to tech at the track.', $gear['detail']);
        $this->assertSame(['label' => 'Send race gear photos', 'url' => 'gear.php?action=pretech&id=40'], $gear['action']);
        $this->assertSame(['subject_type' => 'driver', 'subject_id' => 5, 'season' => 2026], $gear['at_track']);

        $race = ['level' => null] + $tadGear;
        $r = buildReadiness($this->world(['plans' => [['event_id' => 20, 'car_id' => 3, 'formats' => 'race', 'supps_ack_at' => null]], 'gear' => ['5:2026' => $race]]));
        $this->assertSame('done', $this->items($r)['gear:5']['state']);
    }

    /** Final-review Important finding 2: honour an existing "I'll do it at the track" choice. */
    public function testRaceEntryWithTaDriftGearHonoursAnExistingAtTrackChoice(): void
    {
        $tadGear = ['id' => 40, 'season' => 2026, 'discipline' => 'summer', 'level' => 'ta_drift', 'status' => 'accepted', 'accepted_via' => 'in_person', 'photo_status' => null];
        $r = buildReadiness($this->world([
            'plans' => [['event_id' => 20, 'car_id' => 3, 'formats' => 'race', 'supps_ack_at' => null]],
            'gear' => ['5:2026' => $tadGear],
            'atTrack' => ['driver:5@2026'],
        ]));
        $gear = $this->items($r)['gear:5'];
        $this->assertSame('done', $gear['state']);
        $this->assertSame('Gear for Jordan Lee: checked at the track', $gear['label']);
    }

    private function sheet(int $id, int $eventId, array $o = []): array {
        return array_merge(['id' => $id, 'car_id' => 3, 'event_id' => $eventId, 'season' => 2026, 'discipline' => 'summer',
            'sheet_type' => 'ta_drift', 'club' => 'WSCC', 'status' => 'submitted', 'accepted_via' => null,
            'photo_status' => null, 'driver_id' => 5], $o);
    }

    public function testTaOnlyEntryAsksForTheTaDriftSheetAndNoDeclaration(): void
    {
        $items = $this->items(buildReadiness($this->world()));
        $this->assertEqualsCanonicalizing(['tech_sheet:3', 'car_tech:3', 'gear:5', 'supps:3'], array_keys($items));   // self driver only

        $sheet = $items['tech_sheet:3'];
        $this->assertSame(['todo', 'Submit a TA/Drift tech sheet for #86'], [$sheet['state'], $sheet['label']]);
        $this->assertSame('Check each item on the car before you tick it. One accepted sheet covers WSCC for 2026.', $sheet['detail']);
        $this->assertSame(['label' => 'Submit TA/Drift tech sheet', 'url' => 'tech-sheets.php?action=new-ta-drift&car_id=3&event_id=20'], $sheet['action']);

        $tech = $items['car_tech:3'];
        $this->assertSame(['todo', 'TA/Drift car tech for #86 at WSCC'], [$tech['state'], $tech['label']]);
        $this->assertSame(['subject_type' => 'car', 'subject_id' => 3, 'season' => 2026, 'discipline' => TECH_TIER_TA_DRIFT, 'club' => 'WSCC'], $tech['at_track']);

        $this->assertSame(['todo', 'TA/Drift gear for Jordan Lee'], [$items['gear:5']['state'], $items['gear:5']['label']]);
        $supps = $items['supps:3'];
        $this->assertSame(['todo', "Confirm you've read the WSCC supplementary regulations for #86"], [$supps['state'], $supps['label']]);
        $this->assertSame('index.php#event-20', $supps['action']['url']);
    }

    public function testSheetIsSuggestedOnceTheCarIsApprovedForTheClub(): void
    {
        $r = buildReadiness($this->world([
            'plans' => [['event_id' => 21, 'car_id' => 3, 'formats' => 'ta', 'supps_ack_at' => '2026-06-01 10:00:00']],
            'sheets' => [$this->sheet(9, 20, ['status' => 'teched', 'accepted_via' => 'in_person'])],
        ]));
        $items = $this->items($r);
        $sheet = $items['tech_sheet:3'];
        $this->assertSame('suggested', $sheet['state']);
        $this->assertSame('Check your car for WSCC TA #2 (recommended)', $sheet['label']);
        $this->assertSame("You're teched for WSCC 2026. Going through the tech sheet before each event is how you catch a loose lug nut or a leak before it matters.", $sheet['detail']);
        $this->assertSame('tech-sheets.php?action=new-ta-drift&car_id=3&event_id=21', $sheet['action']['url']);
        $this->assertSame(['done', 'TA/Drift car tech 2026 for #86 at WSCC: teched'], [$items['car_tech:3']['state'], $items['car_tech:3']['label']]);
        $this->assertSame('done', $items['supps:3']['state']);
    }

    public function testRaceTechCoversTaDriftCarTech(): void
    {
        $race = $this->sheet(8, 22, ['sheet_type' => 'standard', 'club' => null, 'status' => 'teched', 'accepted_via' => 'photos']);
        $items = $this->items(buildReadiness($this->world(['sheets' => [$race]])));
        $this->assertSame('suggested', $items['tech_sheet:3']['state']);
        $this->assertSame(['done', 'TA/Drift car tech 2026 for #86 at WSCC: covered by race tech'], [$items['car_tech:3']['state'], $items['car_tech:3']['label']]);
    }

    public function testATaDriftSheetAtAnotherClubDoesNotCount(): void
    {
        $nascc = $this->sheet(9, 99, ['club' => 'NASCC', 'status' => 'teched', 'accepted_via' => 'in_person']);
        $items = $this->items(buildReadiness($this->world(['sheets' => [$nascc]])));
        $this->assertSame('todo', $items['tech_sheet:3']['state']);
        $this->assertSame('todo', $items['car_tech:3']['state']);
    }

    public function testSubmittingThisEventsSheetDoesTheSheetAndTheRegulations(): void
    {
        $items = $this->items(buildReadiness($this->world(['sheets' => [$this->sheet(9, 20)]])));
        $this->assertSame(['done', 'TA/Drift tech sheet for WSCC TA #1 submitted'], [$items['tech_sheet:3']['state'], $items['tech_sheet:3']['label']]);
        $this->assertSame(['done', '#86: WSCC supplementary regulations read'], [$items['supps:3']['state'], $items['supps:3']['label']]);
        $this->assertSame('todo', $items['car_tech:3']['state']);
        $this->assertSame('tech-sheets.php?action=pretech&id=9', $items['car_tech:3']['action']['url']);
    }

    public function testTheRegulationsTickCounts(): void
    {
        $items = $this->items(buildReadiness($this->world(['plans' => [['event_id' => 20, 'car_id' => 3, 'formats' => 'drift', 'supps_ack_at' => '2026-06-01 10:00:00']]])));
        $this->assertSame('done', $items['supps:3']['state']);
    }

    public function testGearDriversComeFromTheEventSheetThenTheLatestAcceptedSheetThenSelf(): void
    {
        $withSheet = $this->items(buildReadiness($this->world(['sheets' => [$this->sheet(9, 20, ['driver_id' => 6])]])));
        $this->assertArrayHasKey('gear:6', $withSheet);
        $this->assertArrayNotHasKey('gear:5', $withSheet);

        $accepted = $this->sheet(9, 19, ['driver_id' => 6, 'status' => 'teched', 'accepted_via' => 'in_person']);
        $fromSeason = $this->items(buildReadiness($this->world(['sheets' => [$accepted], 'sheetDrivers' => [9 => [5]], 'sheetDriverNumbers' => [9 => [5 => 2]]])));
        $this->assertArrayHasKey('gear:6', $fromSeason);
        $this->assertArrayHasKey('gear:5', $fromSeason);
        $this->assertSame('gear.php?action=start-ta-drift&sheet_id=9', $fromSeason['gear:6']['action']['url']);           // driver 1
        $this->assertSame('gear.php?action=start-ta-drift&sheet_id=9&driver=2', $fromSeason['gear:5']['action']['url']);  // added driver

        $noSheet = $this->items(buildReadiness($this->world()));
        $this->assertNull($noSheet['gear:5']['action']);   // not on a TA/Drift sheet yet: bring it to the track
        $this->assertSame('Bring it to tech at the track, or add photos once this driver is on a TA/Drift tech sheet.', $noSheet['gear:5']['detail']);
    }

    public function testTaDriftGearAndRaceGearBothCoverATaEntry(): void
    {
        $gear = fn(?string $level): array => ['5:2026' => ['id' => 40, 'season' => 2026, 'discipline' => 'summer', 'level' => $level,
            'status' => 'accepted', 'accepted_via' => 'photos', 'photo_status' => 'accepted']];
        $tad = $this->items(buildReadiness($this->world(['gear' => $gear('ta_drift')])))['gear:5'];
        $this->assertSame(['done', 'Gear for Jordan Lee: pre-teched 2026 · TA/Drift'], [$tad['state'], $tad['label']]);
        $race = $this->items(buildReadiness($this->world(['gear' => $gear(null)])))['gear:5'];
        $this->assertSame(['done', 'Gear for Jordan Lee: pre-teched 2026 · Race'], [$race['state'], $race['label']]);   // plan 2's gearSummerLevelLabel()
    }

    public function testTaDriftEntryWithTaDriftLevelGearHasNoRaceGearTodo(): void
    {
        $gear = ['5:2026' => ['id' => 40, 'season' => 2026, 'discipline' => 'summer', 'level' => 'ta_drift',
            'status' => 'accepted', 'accepted_via' => 'photos', 'photo_status' => 'accepted']];
        $items = $this->items(buildReadiness($this->world(['gear' => $gear])));
        $this->assertSame('done', $items['gear:5']['state']);
        $this->assertStringNotContainsString('racing needs race-level gear', $items['gear:5']['label']);
    }

    public function testAtTheTrackChoiceIsPerClub(): void
    {
        $items = $this->items(buildReadiness($this->world(['atTrack' => ['car:3@tad:WSCC:2026']])));
        $this->assertSame(['done', "TA/Drift car tech for #86: you'll bring it to tech at the track"], [$items['car_tech:3']['state'], $items['car_tech:3']['label']]);
        $race = $this->items(buildReadiness($this->world(['atTrack' => ['car:3@2026']])));
        $this->assertSame('todo', $race['car_tech:3']['state']);   // the race choice doesn't cover TA/Drift
    }

    public function testRaceEventAfterATaEventStillGetsRaceItems(): void
    {
        $r = buildReadiness($this->world([
            'cars' => [3 => ['id' => 3, 'car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'disciplines' => 'summer']],
            'plans' => [['event_id' => 20, 'car_id' => 3, 'formats' => 'ta', 'supps_ack_at' => null],
                        ['event_id' => 21, 'car_id' => 3, 'formats' => 'race,ta', 'supps_ack_at' => null, 'drivers' => [5, 6]]],
        ]));
        $race = $this->items($r, 1);
        $this->assertSame('Submit a tech sheet for #86', $race['tech_sheet:3']['label']);
        $this->assertArrayHasKey('declaration:3', $race);
        $this->assertSame('Car tech for #86', $race['car_tech:3']['label']);
        $this->assertArrayHasKey('gear:5', $race);
        $this->assertArrayHasKey('gear:6', $race);
        $this->assertArrayNotHasKey('supps:3', $race);
    }

    public function testRaceSheetsIgnoreTaDriftSheets(): void
    {
        $r = buildReadiness($this->world([
            'cars' => [3 => ['id' => 3, 'car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'disciplines' => 'summer']],
            'plans' => [['event_id' => 20, 'car_id' => 3, 'formats' => 'race', 'supps_ack_at' => null]],
            'sheets' => [$this->sheet(9, 20, ['status' => 'teched', 'accepted_via' => 'in_person'])],
        ]));
        $items = $this->items($r);
        $this->assertSame('todo', $items['tech_sheet:3']['state']);   // a TA/Drift sheet is not this event's race sheet
        $this->assertSame('todo', $items['car_tech:3']['state']);     // and doesn't accept race car tech
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
