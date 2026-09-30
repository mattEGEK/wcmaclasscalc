<?php
// wcma-calculator/tests/GarageCodriversTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../garage-page.php';

use PHPUnit\Framework\TestCase;

final class GarageCodriversTest extends TestCase
{
    public function testSectionListsCoDriversWithRemoveAndAnAddForm(): void
    {
        $html = garageCoDriversHtml(['car' => ['id' => 3, 'archived_at' => null], 'csrf' => 't',
            'coDrivers' => [['id' => 6, 'name' => 'Sam <Lee>']], 'coDriverOptions' => [['id' => 7, 'name' => 'Pat Driver']]]);
        $this->assertStringContainsString('<h2>Co-drivers</h2>', $html);
        $this->assertStringContainsString("People who share this car. Tick who's driving at each event.", $html);
        $this->assertStringContainsString('Sam &lt;Lee&gt;', $html);
        $this->assertStringContainsString('name="action" value="remove-co-driver"', $html);
        $this->assertStringContainsString('name="driver_id" value="6"', $html);
        $this->assertStringContainsString('<option value="7">Pat Driver</option>', $html);
        $this->assertStringContainsString('<option value="new">', $html);
        $this->assertStringContainsString('name="new_name"', $html);
    }

    public function testEmptyStateAndArchivedCarHasNoForms(): void
    {
        $html = garageCoDriversHtml(['car' => ['id' => 3, 'archived_at' => '2026-01-01'], 'csrf' => 't', 'coDrivers' => [], 'coDriverOptions' => []]);
        $this->assertStringContainsString('No co-drivers yet.', $html);
        $this->assertStringNotContainsString('<form', $html);
    }

    public function testGaragePostHandlesTheTwoActions(): void
    {
        $src = file_get_contents(__DIR__ . '/../garage.php');
        $this->assertStringContainsString("case 'add-co-driver':", $src);
        $this->assertStringContainsString('eventsAddCoDriver($pdo, $uid, $carId, $_POST)', $src);
        $this->assertStringContainsString("case 'remove-co-driver':", $src);
        $this->assertStringContainsString('eventsRemoveCoDriver($pdo, $uid, $carId, (int)($_POST[\'driver_id\'] ?? 0))', $src);
        $this->assertStringContainsString("'coDrivers' =>", $src);
    }
}
