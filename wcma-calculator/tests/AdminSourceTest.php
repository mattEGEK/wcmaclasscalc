<?php
// wcma-calculator/tests/AdminSourceTest.php
//
// Source-level guards for the Admin back office (admin.php needs config.php, so it cannot run under PHPUnit).
use PHPUnit\Framework\TestCase;

final class AdminSourceTest extends TestCase
{
    private function src(string $file): string {
        return str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../' . $file));
    }

    public function testAdminIsAdminOnlyAfterTheMovedRedirects(): void
    {
        $admin = $this->src('admin.php');
        $moved = strpos($admin, '$movedTo = adminMovedActionUrl(');
        $gate = strpos($admin, "require_role('admin');");
        $router = strpos($admin, 'switch ($action) {');
        $this->assertNotFalse($moved);
        $this->assertNotFalse($gate);
        $this->assertNotFalse($router);
        $this->assertLessThan($gate, $moved);
        $this->assertLessThan($router, $gate);
        foreach (['requireAuth(', 'adminActionMinRole(', 'renderAdminNav(', 'TECH_EMAIL', "/admin-tech-sheets.php'", "/admin-gear.php'"] as $gone) {
            $this->assertStringNotContainsString($gone, $admin, $gone);
        }
    }

    public function testBackOfficePagesUseTheAdminTabs(): void
    {
        $expect = [
            'admin.php' => ["adminSubnavHtml('users')", "adminSubnavHtml('events')", "adminSubnavHtml('settings')"],
            'admin-feedback.php' => ["adminSubnavHtml('feedback')"],
            'admin-season-links.php' => ["adminSubnavHtml('season-links')"],
        ];
        foreach ($expect as $file => $needles) {
            $src = $this->src($file);
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $src, $file);
            }
            $this->assertStringNotContainsString("'staff')", $src, $file);
        }
    }

    public function testEventsListShowsHowManyCarsAreGoing(): void
    {
        $admin = $this->src('admin.php');
        $this->assertStringContainsString('db_count_event_plans($pdo)', $admin);
        $this->assertStringContainsString('<th>Going</th>', $admin);
    }

    public function testNoPageStillUsesTheOldAdminNav(): void
    {
        foreach (glob(__DIR__ . '/../*.php') as $f) {
            $src = file_get_contents($f);
            $this->assertStringNotContainsString('renderAdminNav(', $src, basename($f));
            $this->assertStringNotContainsString('adminActionMinRole(', $src, basename($f));
        }
    }
}
