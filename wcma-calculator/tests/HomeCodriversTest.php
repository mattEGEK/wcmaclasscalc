<?php
// wcma-calculator/tests/HomeCodriversTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../home-page.php';
require_once __DIR__ . '/../garage-page.php';

use PHPUnit\Framework\TestCase;

final class HomeCodriversTest extends TestCase
{
    private array $drivers = [['id' => 5, 'name' => 'Jordan Lee', 'isSelf' => true], ['id' => 6, 'name' => 'Sam <Lee>', 'isSelf' => false]];

    public function testFieldsetTicksWhoIsDriving(): void
    {
        $html = homeDriversFieldsHtml($this->drivers, [5]);
        $this->assertStringContainsString("<legend>Who's driving?</legend>", $html);
        $this->assertStringContainsString('name="drivers_shown" value="1"', $html);
        $this->assertMatchesRegularExpression('/value="5" checked> You</', $html);
        $this->assertMatchesRegularExpression('/value="6"> Sam &lt;Lee&gt;</', $html);
        $this->assertSame('Driving: You · Sam <Lee>', homeDrivingLabel($this->drivers, [5, 6]));
    }

    public function testSummerChangeFormHasFormatsAndDrivers(): void
    {
        $event = ['id' => 30, 'name' => 'WSCC TA', 'discipline' => 'summer', 'host_club' => 'WSCC'];
        $html = homeRenderEntryFormatsHtml($event, ['id' => 3], ['formats' => ['ta'], 'supps_ack_at' => null, 'driverIds' => [5]], 't', $this->drivers);
        $this->assertStringContainsString('Time Attack · Driving: You · Change', html_entity_decode($html));
        $this->assertStringContainsString('name="formats[]"', $html);
        $this->assertStringContainsString("Who's driving?", $html);
    }

    public function testIceEntryChangeFormHasDriversOnly(): void
    {
        $event = ['id' => 20, 'name' => 'NASCC Ice #1', 'discipline' => 'ice', 'host_club' => 'NASCC'];
        $html = homeRenderEntryFormatsHtml($event, ['id' => 3], ['formats' => ['race'], 'supps_ack_at' => null, 'driverIds' => [6]], 't', $this->drivers);
        $this->assertStringContainsString('Driving: Sam', html_entity_decode($html));
        $this->assertStringNotContainsString('name="formats[]"', $html);
        $this->assertStringNotContainsString('formats_shown', $html);
        $this->assertStringContainsString('name="action" value="formats"', $html);
        // Without drivers (older callers) an ice entry still has no Change form.
        $this->assertSame('', homeRenderEntryFormatsHtml($event, ['id' => 3], ['formats' => ['race'], 'supps_ack_at' => null], 't'));
    }

    public function testCarPageChangeFormHasDrivers(): void
    {
        $event = ['id' => 20, 'name' => 'NASCC Ice #1', 'discipline' => 'ice', 'host_club' => 'NASCC'];
        $html = garageRenderEntryFormatsHtml($event, 3, ['race'], 't', null, $this->drivers, [5]);
        $this->assertStringContainsString("Who's driving?", $html);
        $this->assertStringContainsString('action="garage.php"', $html);
    }

    public function testHandlersPassTheDrivers(): void
    {
        foreach (['index.php', 'garage.php'] as $file) {
            $src = file_get_contents(__DIR__ . '/../' . $file);
            $this->assertStringContainsString('entryDriversFromPost($_POST)', $src, $file);
        }
        $this->assertStringContainsString("'carDrivers' =>", file_get_contents(__DIR__ . '/../index.php'));
        $this->assertStringContainsString("'carDriverChoices' =>", file_get_contents(__DIR__ . '/../garage.php'));
    }
}
