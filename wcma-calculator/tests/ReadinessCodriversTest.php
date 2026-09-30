<?php
// wcma-calculator/tests/ReadinessCodriversTest.php
require_once __DIR__ . '/../tech-status.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../readiness-lib.php';
require_once __DIR__ . '/../reminders-lib.php';

use PHPUnit\Framework\TestCase;

final class ReadinessCodriversTest extends TestCase
{
    private function world(array $o = []): array {
        return array_merge([
            'today' => '2026-09-26',
            'cars' => [3 => ['id' => 3, 'car_number' => '42', 'make' => 'Honda', 'model' => 'S2000']],
            'events' => [
                ['id' => 10, 'name' => 'Fall Sprint', 'event_date' => '2026-10-11'],
                ['id' => 11, 'name' => 'Season Finale', 'event_date' => '2026-10-25'],
            ],
            'plans' => [['event_id' => 10, 'car_id' => 3, 'drivers' => [5]], ['event_id' => 11, 'car_id' => 3, 'drivers' => [5, 6]]],
            'declarations' => [3 => ['review_status' => 'accepted', 'submitted_at' => '2026-04-02', 'calculated_class' => 'GT3']],
            'sheets' => [], 'sheetDrivers' => [],
            'drivers' => [5 => ['id' => 5, 'name' => 'Jordan Lee'], 6 => ['id' => 6, 'name' => 'Sam Lee'], 7 => ['id' => 7, 'name' => 'Pat Driver']],
            'selfDriverId' => 5, 'gear' => [], 'atTrack' => [],
        ], $o);
    }

    private function gearIds(array $r, int $i): array {
        $ids = [];
        foreach ($r['events'][$i]['items'] as $item) if ($item['kind'] === 'gear') $ids[] = (int)$item['subject_id'];
        return $ids;
    }

    public function testOnlyTickedDriversGetGearTodos(): void
    {
        $r = buildReadiness($this->world());
        $this->assertSame([5], $this->gearIds($r, 0));      // Sam isn't driving the first event
        $this->assertSame([6], $this->gearIds($r, 1));      // Sam is ticked at the finale; Jordan's gear was listed already
        $this->assertNotContains(7, array_merge($this->gearIds($r, 0), $this->gearIds($r, 1)));   // Pat drives nothing
    }

    public function testUntickedOwnerGetsNoGearTodoForThatEntryOnly(): void
    {
        $r = buildReadiness($this->world(['plans' => [['event_id' => 10, 'car_id' => 3, 'drivers' => [6]], ['event_id' => 11, 'car_id' => 3, 'drivers' => [5]]]]));
        $this->assertSame([6], $this->gearIds($r, 0));
        $this->assertSame([5], $this->gearIds($r, 1));
    }

    public function testTheEventSheetsDriversAlwaysCount(): void
    {
        $sheet = ['id' => 70, 'car_id' => 3, 'event_id' => 10, 'season' => 2026, 'status' => 'submitted', 'photo_status' => null, 'accepted_via' => null, 'driver_id' => 5];
        $r = buildReadiness($this->world(['sheets' => [$sheet], 'sheetDrivers' => [70 => [7]]]));
        $this->assertSame([5, 7], $this->gearIds($r, 0));
    }

    public function testEntryWithNoStoredDriversFallsBackToTheOwner(): void
    {
        $r = buildReadiness($this->world(['plans' => [['event_id' => 10, 'car_id' => 3]]]));
        $this->assertSame([5], $this->gearIds($r, 0));
        $this->assertSame(['driverIds' => [5], 'driversKnown' => false],
            array_intersect_key($r['events'][0]['entries'][3], ['driverIds' => 0, 'driversKnown' => 0]));
    }

    public function testIceEntriesFollowTheSameRule(): void
    {
        $r = buildReadiness($this->world([
            'events' => [['id' => 20, 'name' => 'NASCC Ice #1', 'event_date' => '2027-01-10', 'discipline' => 'ice', 'host_club' => 'NASCC']],
            'plans' => [['event_id' => 20, 'car_id' => 3, 'drivers' => [6]]],
            'iceGear' => [], 'iceGearFhr' => [],
        ]));
        $this->assertSame([6], $this->gearIds($r, 0));
    }

    public function testTaDriftEntriesUseTheTickedDrivers(): void
    {
        $r = buildReadiness($this->world([
            'events' => [['id' => 30, 'name' => 'WSCC Time Attack', 'event_date' => '2026-10-20', 'discipline' => 'summer', 'host_club' => 'WSCC']],
            'plans' => [['event_id' => 30, 'car_id' => 3, 'formats' => 'ta', 'supps_ack_at' => null, 'drivers' => [5, 6]]],
        ]));
        $this->assertSame([5, 6], $this->gearIds($r, 0));
    }

    public function testRemindersLeaveOutCoDriversWhoArentDriving(): void
    {
        $digests = reminderDigests(buildReadiness($this->world(['plans' => [['event_id' => 10, 'car_id' => 3, 'drivers' => [5]]]])), '2026-09-26');
        $this->assertStringNotContainsString('Sam Lee', json_encode($digests));
        $this->assertStringNotContainsString('Pat Driver', json_encode($digests));
    }
}
