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
        $this->assertStringContainsString("renderPageStart(\$title, 'inspect'", $src);
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
        $this->assertStringContainsString("renderPageStart(\$title, 'inspect'", $src);
        $this->assertStringNotContainsString('admin.php', $this->src('gear-chips.php'));
        $this->assertStringNotContainsString('TECH_SHEET_FILTERS', $this->src('admin-tech-sheets.php'));
    }

    public function testClassingRoutesMovedFromAdmin(): void
    {
        $this->assertRoutesMoved(
            ['classing', 'declaration', 'declaration-file', 'declarations-export', 'declaration-accept', 'declaration-send-back',
             'declaration-resend', 'declaration-update-contact', 'declaration-delete', 'declarations-bulk-delete'],
            ['declaration-accept', 'declaration-send-back', 'declaration-resend', 'declaration-update-contact', 'declaration-delete', 'declarations-bulk-delete']
        );
        $admin = $this->src('admin.php');
        foreach (['list', 'view', 'file', 'resend', 'update-contact', 'delete', 'bulk-delete', 'export'] as $old) {
            $this->assertStringNotContainsString("case '$old':", $admin, $old);
        }
        $this->assertStringContainsString("\$action = \$_GET['action'] ?? 'users';", $admin);
    }

    public function testReviewEmailsGoOutOnlyAfterTheReviewSucceeded(): void
    {
        foreach (['inspectAcceptDeclaration' => 'accepted', 'inspectSendBackDeclaration' => 'sent_back'] as $fn => $kind) {
            $b = $this->body('inspect.php', $fn);
            $this->assertMatchesRegularExpression("/if \\(\\\$r\\['ok'\\]\\) \\{\\s*\\\$sent = declarationNotify\\(\\\$pdo, '$kind', /", $b, $fn);
            $this->assertStringContainsString("(int)current_user()['id']", $b, $fn);
            $this->assertStringContainsString("'emailSmtpSend'", $b, $fn);
        }
    }

    public function testDeclarationFilesAreServedOnlyForKnownFields(): void
    {
        $b = $this->body('inspect.php', 'inspectDeclarationFile');
        $this->assertStringContainsString("\$columns = ['dyno_chart' => 'dyno_chart_path', 'dyno_table' => 'dyno_table_path', 'car_image' => 'car_image_path'];", $b);
        $this->assertStringContainsString('http_response_code(404)', $b);
        $this->assertStringContainsString("header('X-Content-Type-Options: nosniff');", $b);
    }

    public function testBulkDeleteIdsAreScalarOnly(): void
    {
        $this->assertStringContainsString("fn(\$v): int => is_scalar(\$v) ? (int)\$v : 0", $this->src('inspect.php'));
    }

    public function testQueueRouteBuildsFromTheThreeQueries(): void
    {
        $this->assertContains('queue', $this->routes());
        $show = $this->body('inspect.php', 'inspectShowQueue');
        foreach (['db_get_declarations_awaiting_review($pdo)', 'db_get_sheets_awaiting_photo_review($pdo)', 'db_get_gear_awaiting_photo_review($pdo)', "inspectSubnavHtml('queue')"] as $needle) {
            $this->assertStringContainsString($needle, $show);
        }
    }
}
