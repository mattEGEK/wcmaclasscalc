<?php
// wcma-calculator/tests/GearChipsActionsSourceTest.php
//
// Source-level guard: gear.php and admin-gear.php can't run under PHPUnit (they need
// config.php), so this checks the ice discipline refusal is in the photo pre-tech handlers
// (fix wave, Fix 1).
use PHPUnit\Framework\TestCase;

final class GearChipsActionsSourceTest extends TestCase
{
    private function body(string $file, string $name): string {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../' . $file));
        $start = strpos($src, 'function ' . $name . '(');
        $this->assertNotFalse($start, $name . ' must exist');
        $next = strpos($src, "\nfunction ", $start + 1);
        return $next === false ? substr($src, $start) : substr($src, $start, $next - $start);
    }

    public function testGearPretechHandlersRefuseIceRecords(): void {
        foreach (['handleGearPretech', 'handleGearPretechSubmit'] as $fn) {
            $body = $this->body('gear.php', $fn);
            $this->assertStringContainsString('DISCIPLINE_ICE', $body, $fn);
            $this->assertStringContainsString("isn\\'t available yet. Bring your gear to tech at the event.", $body, $fn);
            $this->assertStringContainsString("Location: drivers.php", $body, $fn);
        }
    }

    public function testGearAdminPhotosAcceptRefusesIceRecords(): void {
        $body = $this->body('admin-gear.php', 'handleGearAdminPhotosAccept');
        $this->assertStringContainsString('DISCIPLINE_ICE', $body);
        $this->assertStringContainsString("Photo review isn\\'t available for ice gear yet.", $body);
        $this->assertStringContainsString("Location: inspect.php?action=gear-record&id=' . \$id", $body);
    }
}
