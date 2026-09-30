<?php
// wcma-calculator/tests/TechSheetCodriversTest.php
require_once __DIR__ . '/../tech-sheet-data.php';

use PHPUnit\Framework\TestCase;

final class TechSheetCodriversTest extends TestCase
{
    private function drivers(): array {
        return [
            ['id' => 5, 'user_id' => 1, 'owner_user_id' => 1, 'name' => 'Jordan Lee', 'name_norm' => 'jordan lee'],
            ['id' => 6, 'user_id' => null, 'owner_user_id' => 1, 'name' => 'Sam Lee', 'name_norm' => 'sam lee'],
        ];
    }

    public function testNewSheetStartsWithThePreferredDriver(): void
    {
        $this->assertSame('5', techSheetDriver1FormState($this->drivers(), null)['choice']);
        $this->assertSame('6', techSheetDriver1FormState($this->drivers(), null, 6)['choice']);
        $this->assertSame('5', techSheetDriver1FormState($this->drivers(), null, 99)['choice']);   // not in the list
        $sheet = ['driver_name' => 'Sam Lee'];
        $this->assertSame('6', techSheetDriver1FormState($this->drivers(), $sheet, 5)['choice']);   // an edit keeps its driver
    }

    public function testPrefillRowsNumberFromTwo(): void
    {
        $byId = [];
        foreach ($this->drivers() as $d) $byId[(int)$d['id']] = $d;
        $this->assertSame([['driver_number' => 2, 'driver_name' => 'Sam Lee', 'equipment_json' => '{}']], techSheetPrefillRows($byId, [6, 99]));
    }

    public function testFormsUseTheCarsDriversAndEverySaveSyncs(): void
    {
        $src = file_get_contents(__DIR__ . '/../tech-sheets.php');
        $this->assertSame(6, substr_count($src, 'eventsSyncSheetDrivers($pdo, (int)$user[\'id\'], '));   // 3 submits + 3 updates
        $this->assertGreaterThanOrEqual(6, substr_count($src, 'eventsSheetDriverRows($pdo, (int)$user[\'id\'], '));   // 3 new + 3 edit forms
        $this->assertStringContainsString("More than one driver is ticked for this event. Use the endurance sheet so everyone is on it.", $src);
        $this->assertStringContainsString("(\$_GET['type'] ?? '') === 'endurance'", $src);
    }
}
