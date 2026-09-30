<?php
// wcma-calculator/tests/InspectRosterEntryTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../tech-status.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../gear-chips.php';
require_once __DIR__ . '/../home-page.php';
require_once __DIR__ . '/../garage-lib.php';
require_once __DIR__ . '/../garage-page.php';
require_once __DIR__ . '/../inspect-lib.php';
require_once __DIR__ . '/../inspect-page.php';

use PHPUnit\Framework\TestCase;

final class InspectRosterEntryTest extends TestCase
{
    private function car(array $o = []): array {
        return array_merge(['id' => 3, 'owner_user_id' => 1, 'car_number' => '86', 'year' => '', 'make' => 'Subaru', 'model' => 'BRZ',
                            'owner_name' => 'Jordan Lee', 'tagged' => 1, 'formats' => 'ta'], $o);
    }

    /** An accepted TA/Drift sheet from an earlier event (event 19), so the car has none for this one. */
    private function earlier(array $o = []): array {
        return array_merge(['id' => 9, 'car_id' => 3, 'user_id' => 1, 'event_id' => 19, 'season' => 2026, 'discipline' => 'summer',
                            'sheet_type' => 'ta_drift', 'club' => 'WSCC', 'status' => 'teched', 'accepted_via' => 'in_person',
                            'photo_status' => null, 'driver_name' => 'Jordan Lee'], $o);
    }

    private function rows(array $cars, array $seasonSheets, ?string $club = 'WSCC'): array {
        return inspectRosterRows($cars, [], $seasonSheets, [], [], [], [], 2026, ['discipline' => 'summer', 'club' => null], $club);
    }

    public function testATaDriftEntryWithNoSheetShowsTheClubsTaDriftStanding(): void
    {
        $row = $this->rows([$this->car()], [$this->earlier()])[0];
        $this->assertSame(['ta_drift', 'WSCC', 'Time Attack'], [$row['tier'], $row['club'], $row['formats']]);
        $this->assertSame('accepted', $row['status']['state']);

        $this->assertSame('race', $this->rows([$this->car(['formats' => 'race,ta'])], [$this->earlier()])[0]['tier']);
        $this->assertSame('none', $this->rows([$this->car(['formats' => 'race,ta'])], [$this->earlier()])[0]['status']['state']);
        $this->assertSame('none', $this->rows([$this->car()], [$this->earlier(['club' => 'NASCC'])])[0]['status']['state']);
        $this->assertSame('race', $this->rows([$this->car()], [$this->earlier()], null)[0]['tier']);   // no host club passed: as before
    }

    public function testTaDriftEntriesAreNeverClassNotAccepted(): void
    {
        $rows = $this->rows([$this->car()], []);
        $this->assertSame([], inspectRosterFilter($rows, 'class_not_accepted'));
        $this->assertCount(1, inspectRosterFilter($rows, 'needs_tech'));
    }

    public function testRowShowsTheFormatsAndTheTaDriftLabel(): void
    {
        $row = $this->rows([$this->car(['formats' => 'ta,drift'])], [$this->earlier()])[0];
        $html = inspectRosterRowHtml($row, ['season' => 2026, 'csrf' => 'tok', 'filter' => 'all', 'discipline' => 'summer']);
        $this->assertStringContainsString('<p><span class="admin-chip admin-chip--info">TA/Drift</span> Time Attack · Drift</p>', $html);
        $this->assertStringContainsString('Teched TA/Drift WSCC 2026', $html);   // plan 2's taDriftCarTechStatusLabel()
        $this->assertStringNotContainsString('No class declared yet', $html);
    }

    public function testGearLevelFilter(): void
    {
        $race = ['status' => 'accepted', 'level' => null];
        $tad = ['status' => 'accepted', 'level' => 'ta_drift'];
        $open = ['status' => 'open', 'level' => null];
        $this->assertSame([$race, $tad, $open], gearRosterLevelFilter([$race, $tad, $open], 'all'));
        $this->assertSame([$race], gearRosterLevelFilter([$race, $tad, $open], 'race'));
        $this->assertSame([$tad], gearRosterLevelFilter([$race, $tad, $open], 'ta_drift'));
        $this->assertSame([$race, $tad, $open], gearRosterLevelFilter([$race, $tad, $open], 'nonsense'));
    }

    public function testGearTabHasTheLevelSelectForSummer(): void
    {
        $src = (string)file_get_contents(__DIR__ . '/../admin-gear.php');
        $this->assertStringContainsString('id="gear-level-filter" name="level"', $src);
        $this->assertStringContainsString('gearRosterLevelFilter(', $src);
    }
}
