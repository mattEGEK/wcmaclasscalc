<?php
// wcma-calculator/tests/InspectTaDriftTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../events-lib.php';
require_once __DIR__ . '/../tech-status.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../gear-chips.php';
require_once __DIR__ . '/../home-page.php';
require_once __DIR__ . '/../garage-lib.php';
require_once __DIR__ . '/../garage-page.php';
require_once __DIR__ . '/../declaration-review-lib.php';
require_once __DIR__ . '/../inspect-lib.php';
require_once __DIR__ . '/../inspect-page.php';

use PHPUnit\Framework\TestCase;

final class InspectTaDriftTest extends TestCase
{
    private function car(): array {
        return ['id' => 1, 'owner_user_id' => 10, 'car_number' => '86', 'car_number_norm' => '86', 'year' => '2015',
                'make' => 'Subaru', 'model' => 'BRZ', 'owner_name' => 'Pat', 'tagged' => 1];
    }

    private function sheet(int $id, array $o = []): array {
        return array_merge(['id' => $id, 'car_id' => 1, 'user_id' => 10, 'event_id' => 3, 'season' => 2026, 'discipline' => 'summer',
            'sheet_type' => 'ta_drift', 'club' => 'WSCC', 'status' => 'submitted', 'photo_status' => null, 'accepted_via' => null,
            'driver_name' => 'Pat'], $o);
    }

    public function testRosterRowUsesTheTaDriftStanding(): void
    {
        $tad = $this->sheet(5);
        $rows = inspectRosterRows([$this->car()], [$tad], [$tad], [], [], [], [], 2026);
        $this->assertSame('none', $rows[0]['status']['state']);
        $this->assertSame('ta_drift', $rows[0]['status']['tier']);
        $this->assertSame('TA/Drift (WSCC)', $rows[0]['ice_class']);
        $this->assertSame([], inspectRosterFilter($rows, 'class_not_accepted'));

        $race = $this->sheet(2, ['sheet_type' => 'standard', 'club' => null, 'status' => 'teched', 'accepted_via' => 'in_person', 'event_id' => 1]);
        $rows = inspectRosterRows([$this->car()], [$tad], [$race, $tad], [], [], [], [], 2026);
        $this->assertSame(['state' => 'accepted', 'via' => 'in_person', 'sheet_id' => 2, 'tier' => 'race'], $rows[0]['status']);
    }

    public function testRosterCardShowsTheChipAndLabel(): void
    {
        $tad = $this->sheet(5, ['status' => 'teched', 'accepted_via' => 'photos']);
        $rows = inspectRosterRows([$this->car()], [$tad], [$tad], [], [], [], [], 2026);
        $html = renderInspectRosterHtml(['events' => [['id' => 3, 'name' => 'WSCC TA', 'event_date' => '2026-07-12']], 'eventId' => 3,
            'filter' => 'all', 'rows' => $rows, 'counts' => inspectRosterCounts($rows), 'season' => 2026, 'csrf' => 'tok', 'discipline' => 'summer']);
        $this->assertStringContainsString('<span class="admin-chip admin-chip--info">TA/Drift</span>', $html);
        $this->assertStringContainsString('Pre-teched TA/Drift WSCC 2026', $html);
        $this->assertStringContainsString('TA/Drift (WSCC)', $html);
    }

    public function testQueueMarksTaDriftSheetsAndGear(): void
    {
        $sheet = $this->sheet(9, ['car_number' => '86', 'car_make' => 'Subaru', 'car_model' => 'BRZ', 'entrant_name' => 'Pat',
            'event_name' => 'WSCC TA', 'updated_at' => '2026-06-01 10:00:00']);
        $tadGear = ['id' => 4, 'driver_name' => 'Pat', 'owner_name' => 'Pat', 'season' => 2026, 'updated_at' => '2026-06-02 10:00:00',
                    'discipline' => 'summer', 'photo_tier' => 'ta_drift', 'status' => 'open', 'level' => null, 'photo_status' => 'submitted'];
        $upgrade = ['id' => 5, 'photo_tier' => null, 'status' => 'accepted', 'level' => 'ta_drift', 'updated_at' => '2026-06-03 10:00:00'] + $tadGear;
        $items = inspectReviewQueue([], [$sheet], [$tadGear, $upgrade]);
        $this->assertSame('Pat · WSCC TA · TA/Drift · WSCC', $items[0]['detail']);
        $this->assertSame('Entered by Pat · 2026 · TA/Drift', $items[1]['detail']);
        $this->assertSame('Entered by Pat · 2026 · Upgrade to race', $items[2]['detail']);
    }

    public function testSheetPageSource(): void
    {
        $src = file_get_contents(__DIR__ . '/../admin-tech-sheets.php');
        $this->assertStringContainsString('taDriftSheetCarStatus($sheet, db_get_user_tech_sheets($pdo, (int)$sheet[\'user_id\']))', $src);
        $this->assertStringContainsString('taDriftCarTechStatusLabel(', $src);
        $this->assertStringContainsString('<span class="admin-chip admin-chip--info">TA/Drift</span>', $src);
    }
}
