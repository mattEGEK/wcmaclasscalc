<?php
// wcma-calculator/tests/GearStartSourceTest.php
//
// Source-level guard: the handleGearStart handler checks driver ownership and finds or creates
// gear records. This test verifies those critical calls are in place.
use PHPUnit\Framework\TestCase;

final class GearStartSourceTest extends TestCase
{
    private function body(string $name): string {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../gear.php'));
        $start = strpos($src, 'function ' . $name . '(');
        $this->assertNotFalse($start, $name . ' must exist');
        $next = strpos($src, "\nfunction ", $start + 1);
        return $next === false ? substr($src, $start) : substr($src, $start, $next - $start);
    }

    public function testHandlerChecksDriverOwnershipAndRejectsUnowned(): void {
        $body = $this->body('handleGearStart');
        $this->assertStringContainsString('owner_user_id', $body);
        $this->assertStringContainsString('Driver not found.', $body);
    }

    public function testHandlerFindsOrCreatesGearRecord(): void {
        // The find-or-create lives in gearStartForDriver() (gear-lib.php), which also picks the photo list.
        $this->assertStringContainsString('gearStartForDriver(', $this->body('handleGearStart'));
        $lib = str_replace("
", "
", file_get_contents(__DIR__ . '/../gear-lib.php'));
        $start = strpos($lib, 'function gearStartForDriver(');
        $fn = substr($lib, $start, strpos($lib, "
}
", $start) - $start);
        $this->assertStringContainsString('db_get_gear_record_for_driver(', $fn);
        $this->assertStringContainsString('gearCreate(', $fn);
    }

    public function testHandlerRedirectsToPretech(): void {
        $body = $this->body('handleGearStart');
        $this->assertStringContainsString('gear.php?action=pretech&id=', $body);
    }

    public function testStartIceRouteUsesTheSheetOwnerHelper(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../gear.php'));
        $this->assertStringContainsString("case 'start-ice':", $src);
        $this->assertStringContainsString('db_get_user_tech_sheet(', $src);
        $this->assertStringContainsString('gearStartIceForSheet(', $src);
    }

    public function testStartIceValidatesTheDriverNumberAsAnInteger(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../gear.php'));
        $this->assertStringContainsString("filter_var(\$_GET['driver'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])", $src);
        $this->assertStringContainsString("gearStartIceForSheet(\$pdo, \$sheet, (int)\$user['id'], \$driverNumber)", $src);
    }

    public function testPretechHandlersAreDisciplineAgnostic(): void {
        foreach (['handleGearPretech', 'handleGearPretechSubmit'] as $name) {
            $this->assertStringNotContainsString('DISCIPLINE_ICE', $this->body($name), $name);
        }
    }
}
