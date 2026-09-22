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
        db_update_event($pdo, $id, 'New Name', '2026-02-02', 'New Location');

        $event = db_get_event($pdo, $id);
        $this->assertSame('New Name', $event['name']);
        $this->assertSame('2026-02-02', $event['event_date']);
        $this->assertSame('New Location', $event['location']);
    }
}
