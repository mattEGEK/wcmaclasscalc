<?php
// wcma-calculator/tests/InspectSourceTest.php
//
// Source-level guards for inspect.php and admin.php (they need config.php, so they cannot run under PHPUnit).
use PHPUnit\Framework\TestCase;

final class InspectSourceTest extends TestCase
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

    /** @return string[] the case labels of inspect.php's router */
    private function routes(): array {
        preg_match_all("/^    case '([a-z-]+)':/m", $this->src('inspect.php'), $m);
        return $m[1];
    }

    private function assertRoutesMoved(array $routes, array $postRoutes): void {
        $admin = $this->src('admin.php');
        foreach ($routes as $r) {
            $this->assertContains($r, $this->routes(), $r);
            $this->assertStringNotContainsString("case '$r':", $admin, $r);
        }
        foreach ($postRoutes as $r) {
            $this->assertContains($r, INSPECT_POST_ACTIONS, $r);
        }
    }

    public function testEveryRequestIsRoleGatedAndPostActionsAreGatedBeforeTheRouter(): void
    {
        $src = $this->src('inspect.php');
        $gate = strpos($src, 'require_role(inspectActionMinRole($action));');
        $post = strpos($src, "if (in_array(\$action, INSPECT_POST_ACTIONS, true)) {\n    if (\$_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: inspect.php'); exit; }\n    if (!validateCsrfToken(\$_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }\n}");
        $router = strpos($src, 'switch ($action) {');
        $this->assertNotFalse($gate);
        $this->assertNotFalse($post);
        $this->assertNotFalse($router);
        $this->assertLessThan($post, $gate);
        $this->assertLessThan($router, $post);
    }

    public function testTechSheetRoutesMovedFromAdmin(): void
    {
        $this->assertRoutesMoved(
            ['tech-sheet', 'tech-sheet-sig', 'tech-sheet-accept', 'tech-sheet-revoke', 'tech-sheet-photos-accept', 'tech-sheet-photos-send-back'],
            ['tech-sheet-accept', 'tech-sheet-revoke', 'tech-sheet-photos-accept', 'tech-sheet-photos-send-back']
        );
        $this->assertStringNotContainsString("case 'tech-sheets':", $this->src('admin.php'));
    }

    public function testAdminRedirectsMovedActionsBeforeRouting(): void
    {
        $admin = $this->src('admin.php');
        $this->assertStringContainsString("\$movedTo = adminMovedActionUrl(is_string(\$action) ? \$action : '', \$_GET);\nif (\$movedTo !== null) { header('Location: ' . \$movedTo); exit; }", $admin);
        $this->assertLessThan(strpos($admin, 'switch ($action)'), strpos($admin, '$movedTo = adminMovedActionUrl('));
    }

    public function testTechSheetReviewPageLivesInTheInspectorSection(): void
    {
        $src = $this->src('admin-tech-sheets.php');
        $this->assertStringNotContainsString('admin.php', $src);
        $this->assertStringNotContainsString('renderSiteHeader(', $src);
        $this->assertStringNotContainsString('function handleTechSheetsList(', $src);
        $this->assertStringContainsString("renderPageStart('Tech Sheet #' . \$id, 'inspect'", $src);
        $this->assertStringContainsString("inspectSubnavHtml('roster')", $this->body('inspect.php', 'inspectShowRoster'));
    }

    public function testGearRoutesMovedFromAdmin(): void
    {
        $this->assertRoutesMoved(
            ['gear', 'gear-record', 'gear-record-accept', 'gear-record-revoke', 'gear-photos-accept', 'gear-photos-send-back', 'gear-create-accept'],
            ['gear-record-accept', 'gear-record-revoke', 'gear-photos-accept', 'gear-photos-send-back', 'gear-create-accept']
        );
        $src = $this->src('admin-gear.php');
        $this->assertStringNotContainsString('admin.php', $src);
        $this->assertStringNotContainsString('renderSiteHeader(', $src);
        $this->assertStringContainsString("renderPageStart('Gear', 'inspect'", $src);
        $this->assertStringContainsString("renderPageStart('Gear #' . \$id, 'inspect'", $src);
        $this->assertStringNotContainsString('admin.php', $this->src('gear-chips.php'));
        $this->assertStringNotContainsString('TECH_SHEET_FILTERS', $this->src('admin-tech-sheets.php'));
    }
}
