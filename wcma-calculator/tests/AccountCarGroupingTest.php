<?php
// wcma-calculator/tests/AccountCarGroupingTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../view_helpers.php';

final class AccountCarGroupingTest extends TestCase
{
    private function submission(int $id, string $make = 'Mazda', string $model = 'MX-5'): array {
        return ['id' => $id, 'year' => '2020', 'make' => $make, 'model' => $model, 'calculated_class' => 'GT3', 'submitted_at' => '2026-08-03 10:00:00'];
    }

    private function techSheet(int $id, int $submissionId, int $eventId, string $status = 'submitted'): array {
        return ['id' => $id, 'submission_id' => $submissionId, 'event_id' => $eventId, 'status' => $status, 'car_make' => 'Mazda', 'car_model' => 'MX-5', 'car_number' => '42'];
    }

    private function event(int $id, string $name, string $date = '2026-10-04'): array {
        return ['id' => $id, 'name' => $name, 'event_date' => $date];
    }

    public function testCarWithNoTechSheetsShowsNotSubmittedForEachActiveEvent(): void
    {
        $groups = buildCarTechSheetGroups(
            [$this->submission(1)],
            [],
            [$this->event(10, 'Fall Sprint')],
            [10 => 'Fall Sprint']
        );

        $this->assertCount(1, $groups['cars']);
        $this->assertCount(1, $groups['cars'][0]['lines']);
        $this->assertNull($groups['cars'][0]['lines'][0]['sheet']);
        $this->assertSame('Fall Sprint', $groups['cars'][0]['lines'][0]['event_name']);
    }

    public function testCarWithSubmittedNotReviewedSheetShowsItsStatus(): void
    {
        $groups = buildCarTechSheetGroups(
            [$this->submission(1)],
            [$this->techSheet(100, 1, 10, 'submitted')],
            [$this->event(10, 'Fall Sprint')],
            [10 => 'Fall Sprint']
        );

        $sheet = $groups['cars'][0]['lines'][0]['sheet'];
        $this->assertNotNull($sheet);
        $this->assertSame('submitted', $sheet['status']);
    }

    public function testCarWithReviewedSheetShowsTechedStatus(): void
    {
        $groups = buildCarTechSheetGroups(
            [$this->submission(1)],
            [$this->techSheet(100, 1, 10, 'teched')],
            [$this->event(10, 'Fall Sprint')],
            [10 => 'Fall Sprint']
        );

        $this->assertSame('teched', $groups['cars'][0]['lines'][0]['sheet']['status']);
    }

    public function testCarWithSheetsAgainstMultipleEventsShowsOneLineEach(): void
    {
        $groups = buildCarTechSheetGroups(
            [$this->submission(1)],
            [$this->techSheet(100, 1, 10, 'submitted'), $this->techSheet(101, 1, 11, 'teched')],
            [$this->event(10, 'Fall Sprint'), $this->event(11, 'Summer Enduro')],
            [10 => 'Fall Sprint', 11 => 'Summer Enduro']
        );

        $this->assertCount(2, $groups['cars'][0]['lines']);
        $eventNames = array_map(fn($l) => $l['event_name'], $groups['cars'][0]['lines']);
        $this->assertSame(['Fall Sprint', 'Summer Enduro'], $eventNames);
    }

    public function testSheetForInactiveEventStillShownAfterActiveEventLines(): void
    {
        // Event 12 is no longer active (absent from $activeEvents) but the sheet still exists.
        $groups = buildCarTechSheetGroups(
            [$this->submission(1)],
            [$this->techSheet(100, 1, 12, 'teched')],
            [$this->event(10, 'Fall Sprint')],
            [10 => 'Fall Sprint', 12 => 'Old Event']
        );

        $this->assertCount(2, $groups['cars'][0]['lines']);
        $this->assertSame('Old Event', $groups['cars'][0]['lines'][1]['event_name']);
        $this->assertSame('teched', $groups['cars'][0]['lines'][1]['sheet']['status']);
    }

    public function testTechSheetWithUnknownSubmissionGoesToOrphans(): void
    {
        $groups = buildCarTechSheetGroups(
            [$this->submission(1)],
            [$this->techSheet(100, 999, 10, 'submitted')],
            [$this->event(10, 'Fall Sprint')],
            [10 => 'Fall Sprint']
        );

        $this->assertNull($groups['cars'][0]['lines'][0]['sheet']);
        $this->assertCount(1, $groups['orphanSheets']);
        $this->assertSame(100, (int)$groups['orphanSheets'][0]['id']);
    }
}
