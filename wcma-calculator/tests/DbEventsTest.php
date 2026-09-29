<?php
// wcma-calculator/tests/DbEventsTest.php
use PHPUnit\Framework\TestCase;

final class DbEventsTest extends TestCase
{
    public function testCreateAndGetEvent(): void
    {
        $pdo = make_temp_pdo();
        $id = db_create_event($pdo, '2026 Spring Sprint', '2026-05-10', 'Race City Speedway');

        $event = db_get_event($pdo, $id);
        $this->assertSame('2026 Spring Sprint', $event['name']);
        $this->assertSame('2026-05-10', $event['event_date']);
        $this->assertSame('Race City Speedway', $event['location']);
        $this->assertSame(1, (int)$event['active']);
    }

    public function testActiveEventsOnlyReturnsActive(): void
    {
        $pdo = make_temp_pdo();
        $activeId = db_create_event($pdo, 'Active Event', '2026-06-01', null);
        $inactiveId = db_create_event($pdo, 'Inactive Event', '2026-07-01', null);
        db_set_event_active($pdo, $inactiveId, false);

        $active = db_get_active_events($pdo);
        $ids = array_column($active, 'id');
        $this->assertContains($activeId, $ids);
        $this->assertNotContains($inactiveId, $ids);
    }

    public function testGetAllEventsIncludesInactive(): void
    {
        $pdo = make_temp_pdo();
        $activeId = db_create_event($pdo, 'Active Event', '2026-06-01', null);
        $inactiveId = db_create_event($pdo, 'Inactive Event', '2026-07-01', null);
        db_set_event_active($pdo, $inactiveId, false);

        $all = db_get_all_events($pdo);
        $ids = array_column($all, 'id');
        $this->assertContains($activeId, $ids);
        $this->assertContains($inactiveId, $ids);
    }

    public function testUpdateEvent(): void
    {
        $pdo = make_temp_pdo();
        $id = db_create_event($pdo, 'Old Name', '2026-01-01', null);
        db_update_event($pdo, $id, 'New Name', '2026-02-02', 'New Location', 'summer', null);

        $event = db_get_event($pdo, $id);
        $this->assertSame('New Name', $event['name']);
        $this->assertSame('2026-02-02', $event['event_date']);
        $this->assertSame('New Location', $event['location']);
    }

    public function testEventMsrLinkStartsBlankAndCanBeSet(): void
    {
        $pdo = make_temp_pdo();
        $id = db_create_event($pdo, 'Fall Sprint', '2026-10-11', null);
        $this->assertSame('', db_get_event($pdo, $id)['msr_url']);
        db_set_event_msr_url($pdo, $id, 'https://msr.example/events/fall');
        $this->assertSame('https://msr.example/events/fall', db_get_event($pdo, $id)['msr_url']);
        db_set_event_msr_url($pdo, $id, '');
        $this->assertSame('', db_get_event($pdo, $id)['msr_url']);
    }

    public function testEventsDefaultToSummer(): void
    {
        $pdo = make_temp_pdo();
        $event = db_get_event($pdo, db_create_event($pdo, 'Spring Sprint', '2026-05-10', null));
        $this->assertSame('summer', $event['discipline']);
        $this->assertNull($event['host_club']);
    }

    public function testCreateAndUpdateIceEvent(): void
    {
        $pdo = make_temp_pdo();
        $id = db_create_event($pdo, 'Ice #1', '2027-01-04', 'Lake Shirley', 'ice', 'WSCC');
        $event = db_get_event($pdo, $id);
        $this->assertSame('ice', $event['discipline']);
        $this->assertSame('WSCC', $event['host_club']);

        db_update_event($pdo, $id, 'Ice #1', '2027-01-04', 'Lake Shirley', 'ice', 'NASCC');
        $this->assertSame('NASCC', db_get_event($pdo, $id)['host_club']);
    }

    public function testActiveEventsFilteredByDisciplineExcludesIce(): void
    {
        $pdo = make_temp_pdo();
        $summerId = db_create_event($pdo, 'Summer Sprint', '2026-06-01', null);
        $iceId = db_create_event($pdo, 'Ice #1', '2027-01-04', 'Lake Shirley', 'ice', 'WSCC');

        $summerOnly = db_get_active_events($pdo, 'summer');
        $ids = array_column($summerOnly, 'id');
        $this->assertContains($summerId, $ids);
        $this->assertNotContains($iceId, $ids);

        // No argument: unchanged behaviour, everything active comes back.
        $all = db_get_active_events($pdo);
        $allIds = array_column($all, 'id');
        $this->assertContains($summerId, $allIds);
        $this->assertContains($iceId, $allIds);
    }
}
