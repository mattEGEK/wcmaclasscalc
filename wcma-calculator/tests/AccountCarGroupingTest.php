<?php
// wcma-calculator/tests/AccountCarGroupingTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../view_helpers.php';

final class AccountCarGroupingTest extends TestCase
{
    private function car(int $id, string $number = '42'): array {
        return ['id' => $id, 'car_number' => $number, 'year' => '2020', 'make' => 'Mazda', 'model' => 'MX-5'];
    }

    private function sheet(int $id, int $carId, int $eventId): array {
        return ['id' => $id, 'car_id' => $carId, 'event_id' => $eventId, 'status' => 'submitted'];
    }

    private function event(int $id, string $name, string $date = '2026-10-04'): array {
        return ['id' => $id, 'name' => $name, 'event_date' => $date];
    }

    public function testCarWithNoSheetsGetsANotSubmittedLinePerActiveEvent(): void
    {
        $groups = buildCarGroups([$this->car(1)], [], [], [$this->event(10, 'Fall Sprint'), $this->event(11, 'Finale')], []);
        $this->assertCount(1, $groups);
        $this->assertNull($groups[0]['declaration']);
        $this->assertSame(['Fall Sprint', 'Finale'], array_column($groups[0]['lines'], 'event_name'));
        $this->assertNull($groups[0]['lines'][0]['sheet']);
    }

    public function testSheetsAttachToTheirOwnCarAndNewestPerEventWins(): void
    {
        $decl = ['id' => 7, 'car_id' => 1, 'calculated_class' => 'GT3', 'review_status' => 'submitted'];
        $groups = buildCarGroups(
            [$this->car(1), $this->car(2, '7')],
            [1 => $decl],
            [$this->sheet(31, 1, 10), $this->sheet(30, 1, 10), $this->sheet(40, 2, 10)],   // newest first, as db_get_user_tech_sheets returns
            [$this->event(10, 'Fall Sprint')],
            [10 => 'Fall Sprint']
        );
        $this->assertSame($decl, $groups[0]['declaration']);
        $this->assertSame(31, $groups[0]['lines'][0]['sheet']['id']);
        $this->assertSame(40, $groups[1]['lines'][0]['sheet']['id']);
    }

    public function testSheetForAnInactiveEventIsStillShown(): void
    {
        $groups = buildCarGroups([$this->car(1)], [], [$this->sheet(5, 1, 99)], [], [99 => 'Old Event']);
        $this->assertCount(1, $groups[0]['lines']);
        $this->assertSame('Old Event', $groups[0]['lines'][0]['event_name']);
        $this->assertNull($groups[0]['lines'][0]['event_date']);
    }
}
