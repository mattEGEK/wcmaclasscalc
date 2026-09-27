<?php
// wcma-calculator/tests/GearChipsActionsSourceTest.php
//
// Source-level guard: gear.php and admin-gear.php can't run under PHPUnit (they need
// config.php). The gear pre-tech ice refusal (fix wave, Fix 1) was removed in ice-phase3
// task 4 — gear.php's photo pre-tech now works for ice gear too — so only the admin-gear.php
// photo review refusal (out of task 4's scope) is still checked here.
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

    public function testGearAdminPhotosAcceptRefusesIceRecords(): void {
        $body = $this->body('admin-gear.php', 'handleGearAdminPhotosAccept');
        $this->assertStringContainsString('DISCIPLINE_ICE', $body);
        $this->assertStringContainsString("Photo review isn\\'t available for ice gear yet.", $body);
        $this->assertStringContainsString("Location: inspect.php?action=gear-record&id=' . \$id", $body);
    }
}
