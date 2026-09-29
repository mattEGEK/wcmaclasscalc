<?php
// wcma-calculator/tests/DbMsrActionsTest.php
require_once __DIR__ . '/../msr-lib.php';

use PHPUnit\Framework\TestCase;

final class DbMsrActionsTest extends TestCase
{
    private const EV = 'AAAAAAAA-BBBB-CCCC-DDDDDDDDDDDDDDDD';
    private const EV2 = 'BBBBBBBB-BBBB-CCCC-DDDDDDDDDDDDDDDD';

    private function pdoWithNewRows(): PDO {
        $pdo = make_temp_pdo();
        foreach ([self::EV => 'Wkd 1 Sprint', self::EV2 => 'Wkd 1 Time Attack'] as $id => $name) {
            db_upsert_msr_event($pdo, 'NASCC', ['msr_id' => $id, 'name' => $name, 'start_date' => '2027-06-13', 'end_date' => '2027-06-14',
                'type' => 'Club Race', 'venue' => 'Rad Torque Raceway', 'detail_url' => 'https://www.motorsportreg.com/events/' . $id, 'cancelled' => 0], '2026-10-01 06:00:00');
        }
        return $pdo;
    }

    private function fields(): array {
        return ['name' => 'Summer Weekend 1', 'date' => '2027-06-13', 'location' => 'Rad Torque Raceway', 'discipline' => 'summer',
                'club' => 'NASCC', 'msr_url' => 'https://www.motorsportreg.com/events/' . self::EV];
    }

    public function testAddToHubCreatesTheEventWithItsLinkAndMarksTheRowPrimary(): void
    {
        $pdo = $this->pdoWithNewRows();
        $this->assertNull(msrAddToHub($pdo, self::EV, $this->fields()));
        $row = db_get_msr_event($pdo, self::EV);
        $event = db_get_event($pdo, (int)$row['hub_event_id']);
        $this->assertSame('Summer Weekend 1', $event['name']);
        $this->assertSame('NASCC', $event['host_club']);
        $this->assertSame('https://www.motorsportreg.com/events/' . self::EV, $event['msr_url']);
        $this->assertSame(['added', 1, 'Wkd 1 Sprint'], [$row['status'], (int)$row['is_primary'], $row['snap_name']]);
        // Twice (double click / second admin): refused, nothing duplicated.
        $this->assertSame(MSR_STALE, msrAddToHub($pdo, self::EV, $this->fields()));
        $this->assertCount(1, db_get_all_events($pdo));
    }

    public function testACancelledNewEventCanOnlyBeIgnored(): void
    {
        $pdo = $this->pdoWithNewRows();
        $pdo->exec("UPDATE msr_events SET cancelled = 1");
        $this->assertSame(MSR_STALE, msrAddToHub($pdo, self::EV, $this->fields()));
        $this->assertNull(msrIgnore($pdo, self::EV));
    }

    public function testAttachToAnExistingEvent(): void
    {
        $pdo = $this->pdoWithNewRows();
        msrAddToHub($pdo, self::EV, $this->fields());
        $hub = (int)db_get_msr_event($pdo, self::EV)['hub_event_id'];
        $this->assertNull(msrAttach($pdo, self::EV2, $hub));
        $row = db_get_msr_event($pdo, self::EV2);
        $this->assertSame([$hub, 0, 'added'], [(int)$row['hub_event_id'], (int)$row['is_primary'], $row['status']]);
        $this->assertSame(MSR_STALE, msrAttach($pdo, self::EV2, $hub));   // already attached
    }

    public function testAttachChecksTheEventExists(): void
    {
        $pdo = $this->pdoWithNewRows();
        $this->assertSame('Choose a hub event.', msrAttach($pdo, self::EV2, 999));
        $this->assertSame('new', db_get_msr_event($pdo, self::EV2)['status']);
    }

    public function testIgnoreAndRestore(): void
    {
        $pdo = $this->pdoWithNewRows();
        $this->assertNull(msrIgnore($pdo, self::EV));
        $this->assertSame(MSR_STALE, msrIgnore($pdo, self::EV));
        $this->assertNull(msrRestore($pdo, self::EV));
        $this->assertSame('new', db_get_msr_event($pdo, self::EV)['status']);
        $this->assertSame(MSR_STALE, msrRestore($pdo, self::EV));
    }

    public function testApplyUpdatesOnlyWhatChangedAndDeactivatesOnCancel(): void
    {
        $pdo = $this->pdoWithNewRows();
        msrAddToHub($pdo, self::EV, $this->fields());   // admin renamed it "Summer Weekend 1"
        $hub = (int)db_get_msr_event($pdo, self::EV)['hub_event_id'];
        $pdo->exec("UPDATE msr_events SET start_date = '2027-06-20', end_date = '2027-06-21', cancelled = 1 WHERE msr_id = '" . self::EV . "'");
        $this->assertNull(msrApply($pdo, self::EV));
        $event = db_get_event($pdo, $hub);
        $this->assertSame(['Summer Weekend 1', '2027-06-20', 0], [$event['name'], $event['event_date'], (int)$event['active']]);
        $this->assertSame([], msrChanges(db_get_msr_event($pdo, self::EV)));
        $this->assertSame(MSR_STALE, msrApply($pdo, self::EV));   // nothing left to apply
    }

    public function testAttachedRowsNeverChangeTheHubEvent(): void
    {
        $pdo = $this->pdoWithNewRows();
        msrAddToHub($pdo, self::EV, $this->fields());
        $hub = (int)db_get_msr_event($pdo, self::EV)['hub_event_id'];
        msrAttach($pdo, self::EV2, $hub);
        $pdo->exec("UPDATE msr_events SET cancelled = 1 WHERE msr_id = '" . self::EV2 . "'");
        $this->assertSame(MSR_STALE, msrApply($pdo, self::EV2));
        $this->assertSame(1, (int)db_get_event($pdo, $hub)['active']);
        $this->assertNull(msrKeep($pdo, self::EV2));
        $this->assertSame([], msrChanges(db_get_msr_event($pdo, self::EV2)));
    }

    public function testKeepOnAGoneRowForgetsIt(): void
    {
        $pdo = $this->pdoWithNewRows();
        msrAddToHub($pdo, self::EV, $this->fields());
        db_set_msr_status($pdo, self::EV, 'gone');
        $this->assertSame(MSR_STALE, msrApply($pdo, self::EV));
        $this->assertNull(msrKeep($pdo, self::EV));
        $this->assertNull(db_get_msr_event($pdo, self::EV));
        $this->assertCount(1, db_get_all_events($pdo));   // the hub event stays
    }
}
