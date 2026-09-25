<?php
// wcma-calculator/tests/TechStatusTest.php
require_once __DIR__ . '/../tech-status.php';

use PHPUnit\Framework\TestCase;

final class TechStatusTest extends TestCase
{
    private function sheet(int $id, array $o = []): array {
        return array_merge([
            'id' => $id, 'user_id' => 5, 'car_number' => '42', 'car_number_norm' => '42', 'season' => 2026,
            'event_id' => 10, 'status' => 'submitted', 'accepted_via' => null, 'photo_status' => null,
        ], $o);
    }

    public function testCarNumberNormalisation(): void
    {
        $this->assertSame('42', techCarNumberNorm(' 42 '));
        $this->assertSame('42', techCarNumberNorm('042'));
        $this->assertSame('7', techCarNumberNorm('007'));
        $this->assertSame('00', techCarNumberNorm('00'));
        $this->assertSame('0', techCarNumberNorm('0'));
        $this->assertSame('7A', techCarNumberNorm('7a'));
        $this->assertSame('', techCarNumberNorm('   '));
    }

    public function testSeasonFromDate(): void
    {
        $this->assertSame(2026, techSeasonFromDate('2026-05-10'));
        $this->assertSame(2027, techSeasonFromDate('2027-01-02 09:00:00'));
        $this->assertSame((int)date('Y'), techSeasonFromDate(null));
        $this->assertSame((int)date('Y'), techSeasonFromDate('garbage'));
    }

    public function testCarKeyUsesStoredIdentityOrDerivesIt(): void
    {
        $this->assertSame('5|42|2026', techCarKey($this->sheet(1)));
        $legacy = ['id' => 2, 'user_id' => 5, 'car_number' => ' 042 ', 'season' => 2026];
        $this->assertSame('5|42|2026', techCarKey($legacy));
        $otherOwner = $this->sheet(3, ['user_id' => 6]);
        $this->assertNotSame(techCarKey($this->sheet(1)), techCarKey($otherOwner));
    }

    public function testNoSheetsOrNoAcceptanceMeansNone(): void
    {
        $this->assertSame(['state' => 'none', 'via' => null, 'sheet_id' => null], techCarStatus([]));
        $this->assertSame('none', techCarStatus([$this->sheet(1), $this->sheet(2)])['state']);
    }

    public function testAnyAcceptedSheetAcceptsTheCar(): void
    {
        $status = techCarStatus([
            $this->sheet(1),
            $this->sheet(2, ['status' => 'teched', 'accepted_via' => 'in_person']),
        ]);
        $this->assertSame(['state' => 'accepted', 'via' => 'in_person', 'sheet_id' => 2], $status);
    }

    public function testLegacyTechedRowCountsAsInPerson(): void
    {
        $status = techCarStatus([$this->sheet(1, ['status' => 'teched', 'accepted_via' => null])]);
        $this->assertSame('in_person', $status['via']);
    }

    public function testEarliestAcceptedSheetIsReported(): void
    {
        $status = techCarStatus([
            $this->sheet(9, ['status' => 'teched', 'accepted_via' => 'photos']),
            $this->sheet(3, ['status' => 'teched', 'accepted_via' => 'in_person']),
        ]);
        $this->assertSame(3, $status['sheet_id']);
    }

    public function testPhotoStatePrecedence(): void
    {
        $draft = $this->sheet(1, ['photo_status' => 'draft']);
        $pending = $this->sheet(2, ['photo_status' => 'submitted']);
        $changes = $this->sheet(3, ['photo_status' => 'needs_changes']);

        $this->assertSame('photos_draft', techCarStatus([$draft])['state']);
        $this->assertSame('pending_review', techCarStatus([$draft, $pending])['state']);
        $this->assertSame('needs_changes', techCarStatus([$draft, $pending, $changes])['state']);
        $accepted = $this->sheet(4, ['status' => 'teched', 'accepted_via' => 'photos']);
        $this->assertSame('accepted', techCarStatus([$draft, $pending, $changes, $accepted])['state']);
    }

    public function testLabelsAndBadgeClasses(): void
    {
        $this->assertSame('Teched 2026', techCarStatusLabel(['state' => 'accepted', 'via' => 'in_person', 'sheet_id' => 1], 2026));
        $this->assertSame('Pre-teched 2026', techCarStatusLabel(['state' => 'accepted', 'via' => 'photos', 'sheet_id' => 1], 2026));
        $this->assertSame('Needs tech at the track', techCarStatusLabel(['state' => 'none', 'via' => null, 'sheet_id' => null], 2026));
        $this->assertSame('Photos pending review', techCarStatusLabel(['state' => 'pending_review', 'via' => null, 'sheet_id' => 1], 2026));
        $this->assertSame('Photos need changes', techCarStatusLabel(['state' => 'needs_changes', 'via' => null, 'sheet_id' => 1], 2026));
        $this->assertSame('Photos in progress', techCarStatusLabel(['state' => 'photos_draft', 'via' => null, 'sheet_id' => 1], 2026));

        $this->assertSame('badge-ok', techCarStatusBadgeClass('accepted'));
        $this->assertSame('badge-fail', techCarStatusBadgeClass('needs_changes'));
        $this->assertSame('badge-pending', techCarStatusBadgeClass('none'));
        $this->assertSame('badge-pending', techCarStatusBadgeClass('pending_review'));
    }

    public function testNoApprovalWordingInLabels(): void
    {
        foreach (['accepted', 'needs_changes', 'pending_review', 'photos_draft', 'none'] as $state) {
            foreach (['in_person', 'photos'] as $via) {
                $label = techCarStatusLabel(['state' => $state, 'via' => $via, 'sheet_id' => 1], 2026);
                $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', $label);
            }
        }
    }

    public function testStatusForSheetUsesOnlyThatCarsSheets(): void
    {
        $mine = $this->sheet(1);
        $sameCarLater = $this->sheet(2, ['event_id' => 11, 'status' => 'teched', 'accepted_via' => 'in_person']);
        $otherCar = $this->sheet(3, ['car_number' => '7', 'car_number_norm' => '7', 'status' => 'teched']);
        $lastYear = $this->sheet(4, ['season' => 2025, 'status' => 'teched']);

        $owner = [$mine, $sameCarLater, $otherCar, $lastYear];
        $this->assertSame('accepted', techCarStatusForSheet($mine, $owner)['state']);
        $this->assertSame('none', techCarStatusForSheet($this->sheet(5, ['car_number_norm' => '99', 'car_number' => '99']), $owner)['state']);
        $this->assertSame('none', techCarStatusForSheet($this->sheet(6, ['season' => 2027]), $owner)['state']);
    }

    public function testRosterAndFilters(): void
    {
        $eventSheets = [
            $this->sheet(1),
            $this->sheet(2, ['user_id' => 6, 'car_number' => '7', 'car_number_norm' => '7']),
            $this->sheet(3, ['user_id' => 7, 'car_number' => '9', 'car_number_norm' => '9']),
        ];
        $seasonSheets = array_merge($eventSheets, [
            $this->sheet(20, ['event_id' => 11, 'status' => 'teched', 'accepted_via' => 'in_person']),          // accepts car 42 (owner 5)
            $this->sheet(21, ['user_id' => 7, 'car_number' => '9', 'car_number_norm' => '9', 'event_id' => 11, 'photo_status' => 'submitted']),
        ]);

        $rows = techBuildRoster($eventSheets, $seasonSheets);
        $this->assertCount(3, $rows);
        $this->assertSame('accepted', $rows[0]['status']['state']);
        $this->assertSame('none', $rows[1]['status']['state']);
        $this->assertSame('pending_review', $rows[2]['status']['state']);

        $this->assertCount(3, techRosterFilter($rows, 'all'));
        $this->assertCount(3, techRosterFilter($rows, 'bogus'));
        $this->assertSame([1], array_map(fn($r) => $r['sheet']['id'], techRosterFilter($rows, 'accepted')));
        $this->assertSame([2, 3], array_map(fn($r) => $r['sheet']['id'], techRosterFilter($rows, 'needs_tech')));
    }

    public function testDefaultEventId(): void
    {
        $events = [
            ['id' => 1, 'event_date' => '2026-05-01', 'active' => 1],
            ['id' => 2, 'event_date' => '2026-10-04', 'active' => 1],
            ['id' => 3, 'event_date' => '2026-09-01', 'active' => 0],
            ['id' => 4, 'event_date' => '2026-11-15', 'active' => 1],
        ];
        $this->assertSame(2, techDefaultEventId($events, '2026-09-24'));
        $this->assertSame(2, techDefaultEventId($events, '2026-10-04'));
        $this->assertSame(4, techDefaultEventId($events, '2026-10-05'));
        $this->assertSame(4, techDefaultEventId($events, '2027-01-01'));   // none upcoming: most recent event
        $this->assertSame(0, techDefaultEventId([], '2026-09-24'));
    }

    public function testAcceptedPhotoStatusWithoutTechedSheetCountsAsPending(): void
    {
        $status = techCarStatus([$this->sheet(1, ['photo_status' => 'accepted'])]);
        $this->assertSame('pending_review', $status['state']);
    }

    public function testPendingReviewRosterFilter(): void
    {
        $rows = [
            ['sheet' => $this->sheet(1), 'status' => ['state' => 'pending_review', 'via' => null, 'sheet_id' => 1]],
            ['sheet' => $this->sheet(2), 'status' => ['state' => 'none', 'via' => null, 'sheet_id' => null]],
            ['sheet' => $this->sheet(3), 'status' => ['state' => 'needs_changes', 'via' => null, 'sheet_id' => 3]],
            ['sheet' => $this->sheet(4), 'status' => ['state' => 'accepted', 'via' => 'photos', 'sheet_id' => 4]],
        ];
        $this->assertSame([1], array_map(fn($r) => $r['sheet']['id'], techRosterFilter($rows, 'pending_review')));
        $this->assertSame([1, 2, 3], array_map(fn($r) => $r['sheet']['id'], techRosterFilter($rows, 'needs_tech')));
    }

    private function rosterRow(string $carState, ?array $gearStates): array {
        $row = ['sheet' => ['id' => 1], 'status' => ['state' => $carState, 'via' => null]];
        if ($gearStates !== null) {
            $row['gear_links'] = array_map(fn(string $s): array => ['status' => ['state' => $s, 'via' => null]], $gearStates);
        }
        return $row;
    }

    public function testGearHelpers(): void
    {
        $this->assertFalse(techGearLinksNeedGear([]));
        $this->assertFalse(techGearLinksNeedGear([['status' => ['state' => 'accepted']]]));
        $this->assertTrue(techGearLinksNeedGear([['status' => ['state' => 'accepted']], ['status' => ['state' => 'none']]]));
        $this->assertTrue(techGearLinksNeedGear([['status' => ['state' => 'pending_review']]]));
        $this->assertTrue(techGearLinksPending([['status' => ['state' => 'accepted']], ['status' => ['state' => 'pending_review']]]));
        $this->assertFalse(techGearLinksPending([['status' => ['state' => 'none']]]));
        $this->assertFalse(techGearLinksPending([]));
    }

    public function testRosterFilterCountsUnfinishedGearAsNeedingTech(): void
    {
        $rows = [
            $this->rosterRow('accepted', ['accepted']),              // 0: car and gear done
            $this->rosterRow('accepted', ['accepted', 'none']),      // 1: car done, a driver has no gear record
            $this->rosterRow('none', ['accepted']),                  // 2: gear done, car not
            $this->rosterRow('accepted', ['pending_review']),        // 3: car done, gear photos awaiting review
            $this->rosterRow('pending_review', ['accepted']),        // 4: car photos awaiting review
        ];
        $ids = fn(string $f): array => array_keys(array_filter($rows, fn($r) => in_array($r, techRosterFilter($rows, $f), true)));

        $this->assertSame([0], $ids('accepted'));
        $this->assertSame([1, 2, 3, 4], $ids('needs_tech'));
        $this->assertSame([3, 4], $ids('pending_review'));
        $this->assertSame([0, 1, 2, 3, 4], $ids('all'));
        $this->assertSame([0, 1, 2, 3, 4], $ids('bogus'));
    }

    public function testRosterFilterWithoutGearLinksKeepsThePhaseTwoBehaviour(): void
    {
        $rows = [$this->rosterRow('accepted', null), $this->rosterRow('none', null), $this->rosterRow('pending_review', null)];
        $this->assertCount(1, techRosterFilter($rows, 'accepted'));
        $this->assertCount(2, techRosterFilter($rows, 'needs_tech'));
        $this->assertCount(1, techRosterFilter($rows, 'pending_review'));
    }
}
