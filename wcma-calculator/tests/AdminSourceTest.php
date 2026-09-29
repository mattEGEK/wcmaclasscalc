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

    private function body(string $file, string $name): string {
        $src = $this->src($file);
        $start = strpos($src, 'function ' . $name . '(');
        $this->assertNotFalse($start, $name . ' must exist in ' . $file);
        $next = strpos($src, "\nfunction ", $start + 1);
        return $next === false ? substr($src, $start) : substr($src, $start, $next - $start);
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

        // login/logout must work for anyone (signed-out users too), so the redirect to auth.php
        // has to sit before the admin-only gate.
        $loginRedirect = strpos($admin, "header('Location: auth.php?action=' . \$action);");
        $this->assertNotFalse($loginRedirect, 'admin.php should redirect login/logout to auth.php');
        $this->assertLessThan($gate, $loginRedirect);
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

    public function testEventFormHasDisciplineAndHostClub(): void
    {
        $src = $this->src('admin.php');
        $this->assertStringContainsString('name="discipline"', $src);
        $this->assertStringContainsString('name="host_club"', $src);
        $this->assertStringContainsString('iceEventFields($_POST, ', $src);
    }

    // Fix 3: a POST that omits "discipline" (e.g. a stale form, or a script only touching name/date)
    // must keep the event's current discipline/host_club rather than silently defaulting to summer.
    // iceEventFields() itself stays pure — handleEventUpdate() fills the gaps before calling it.
    public function testEventUpdateKeepsCurrentDisciplineWhenPostOmitsIt(): void
    {
        $body = $this->body('admin.php', 'handleEventUpdate');
        $this->assertStringContainsString("db_get_event(\$pdo, \$id)", $body);
        $this->assertStringContainsString("array_key_exists('discipline', \$disciplineInput)", $body);
        $this->assertStringContainsString("iceEventFields(\$disciplineInput, ", $body);
        // iceEventFields() is called with the built array, not the raw $_POST.
        $this->assertStringNotContainsString('iceEventFields($_POST', $body);
    }

    public function testClubsTabIsRoutedAndEventsTakeClubsFromTheList(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../admin.php'));
        foreach (["case 'clubs':", "case 'club-save':", "case 'event-club':", "adminRequirePost('admin.php?action=clubs')",
                  "adminRequirePost('admin.php?action=events')", 'array_column(db_get_clubs($pdo, true), \'code\')'] as $needle) {
            $this->assertStringContainsString($needle, $src);
        }
        $layout = file_get_contents(__DIR__ . '/../layout.php');
        $this->assertStringContainsString("'clubs' => ['admin.php?action=clubs', 'Clubs']", $layout);
    }

    public function testDeactivateAndReactivateAreSecondaryButtons(): void
    {
        $src = file_get_contents(__DIR__ . '/../admin.php');
        $this->assertStringContainsString('<button type="submit" class="btn-role btn-role--secondary">Deactivate</button>', $src);
        $this->assertStringContainsString('<button type="submit" class="btn-role btn-role--secondary">Reactivate</button>', $src);
    }
}
