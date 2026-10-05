<?php
// wcma-calculator/tests/GearAdminCountsTest.php — the Gear tab's count line describes the drivers the
// Level filter shows (bug list 2026-10-02, item 12).
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../gear-lib.php';

use PHPUnit\Framework\TestCase;

final class GearAdminCountsTest extends TestCase
{
    private function records(): array {
        return [
            ['id' => 1, 'status' => 'accepted', 'level' => null],                       // race
            ['id' => 2, 'status' => 'accepted', 'level' => GEAR_LEVEL_TA_DRIFT],
            ['id' => 3, 'status' => 'accepted', 'level' => GEAR_LEVEL_TA_DRIFT],
            ['id' => 4, 'status' => 'open', 'level' => null, 'photo_status' => 'submitted'],
            ['id' => 5, 'status' => 'open', 'level' => null, 'photo_status' => null],
        ];
    }

    public function testAnyLevelCountsEveryone(): void
    {
        $this->assertSame(['all' => 5, 'accepted' => 3, 'needs_gear' => 2, 'pending_review' => 1],
            gearAdminCounts(gearRosterLevelFilter($this->records(), 'all')));
    }

    public function testALevelCountsOnlyTheDriversItShows(): void
    {
        $this->assertSame(['all' => 2, 'accepted' => 2, 'needs_gear' => 0, 'pending_review' => 0],
            gearAdminCounts(gearRosterLevelFilter($this->records(), 'ta_drift')));
        $this->assertSame(['all' => 1, 'accepted' => 1, 'needs_gear' => 0, 'pending_review' => 0],
            gearAdminCounts(gearRosterLevelFilter($this->records(), 'race')));
    }

    public function testTheListPageCountsAfterTheLevelFilter(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../admin-gear.php'));
        $fn = substr($src, strpos($src, 'function handleGearAdminList('), 1400);
        $this->assertStringContainsString('$records = gearRosterLevelFilter(db_get_gear_records_for_season($pdo, $season, $discipline), $level);', $fn);
        $this->assertStringContainsString('$counts = gearAdminCounts($records);', $fn);
    }
}
