<?php
// wcma-calculator/tests/GearCreateAcceptSourceTest.php
//
// Source-level guards for the pages and route that need config.php and so cannot run under
// PHPUnit: the one-tap route is admin-only, POST-only and CSRF-checked, and the chips are wired
// with the options that make the button and the season gating work.
use PHPUnit\Framework\TestCase;

final class GearCreateAcceptSourceTest extends TestCase
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

    public function testRouteIsAdminOnlyPostOnlyAndCsrfChecked(): void
    {
        $admin = $this->src('admin.php');
        $this->assertMatchesRegularExpression("/case 'gear-create-accept':\\s+requireAuth\\(\\);/", $admin);
        $this->assertMatchesRegularExpression("/case 'gear-create-accept':.{0,300}?REQUEST_METHOD.{0,300}?validateCsrfToken.{0,300}?handleGearCreateAccept\\(\\\$pdo\\);/s", $admin);
    }

    public function testHandlerReadsTheNameFromTheSheetNotFromTheRequest(): void
    {
        $handler = $this->body('admin-gear.php', 'handleGearCreateAccept');
        $this->assertStringContainsString('db_get_tech_sheet($pdo, $sheetId)', $handler);
        $this->assertStringContainsString('db_get_tech_sheet_drivers($pdo, $sheetId)', $handler);
        $this->assertStringContainsString('gearCreateAndAcceptInPerson($pdo, $sheet,', $handler);
        $this->assertStringContainsString('(int)$user[\'id\']', $handler);
        $this->assertStringNotContainsString("\$_POST['driver_name']", $handler);
        $this->assertStringContainsString('TECH_SHEET_FILTERS[$_POST[\'filter\']]', $handler);
    }

    public function testRosterPassesATokenAndOptionsToTheAdminChips(): void
    {
        $list = $this->body('admin-tech-sheets.php', 'handleTechSheetsList');
        $this->assertStringContainsString('getFlash(), generateCsrfToken()', $list);

        $page = $this->body('admin-tech-sheets.php', 'renderTechSheetsListPage');
        $this->assertStringContainsString('?array $flash, string $csrf = \'\'): void', $page);
        $this->assertStringContainsString("'csrf' => \$csrf, 'sheet_id' => (int)\$s['id']", $page);
        $this->assertStringContainsString("'back' => 'roster'", $page);
        $this->assertStringContainsString("'sheet_season' => (int)\$s['season']", $page);
    }

    public function testReviewPagePassesATokenAndOptionsToTheAdminChips(): void
    {
        $page = $this->body('admin-tech-sheets.php', 'renderTechSheetViewPage');
        $this->assertStringContainsString("'csrf' => \$csrf, 'sheet_id' => \$id", $page);
        $this->assertStringContainsString("'back' => 'sheet'", $page);
    }

    public function testOwnerChipsGetTheSheetSeason(): void
    {
        $this->assertStringContainsString("renderGearChips(\$gearLinks, 'owner', ['sheet_season' => (int)(\$sheet['season'] ?? 0)])", $this->src('tech-sheets.php'));
        $account = $this->src('account.php');
        $this->assertStringContainsString("renderGearChips(\$gearLinks[(int)\$sheet['id']] ?? [], 'owner', ['sheet_season' => (int)(\$sheet['season'] ?? 0)])", $account);
        $this->assertStringContainsString("renderGearChips(\$gearLinks[(int)\$ts['id']] ?? [], 'owner', ['sheet_season' => (int)(\$ts['season'] ?? 0)])", $account);
    }

    public function testNewCopyAvoidsBannedWording(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed)\b/i', $this->body('admin-gear.php', 'handleGearCreateAccept'));
    }
}
