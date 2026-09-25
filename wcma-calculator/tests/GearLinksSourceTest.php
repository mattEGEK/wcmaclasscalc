<?php
// wcma-calculator/tests/GearLinksSourceTest.php
//
// Source-level guards for pages that need config.php and so cannot run under PHPUnit: the gear
// wiring is present where it must be, and the terminology rule holds for the new copy.
use PHPUnit\Framework\TestCase;

final class GearLinksSourceTest extends TestCase
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

    public function testCompetitorPagesRequireTheGearHelpers(): void
    {
        foreach (['tech-sheets.php', 'account.php'] as $file) {
            $src = $this->src($file);
            $this->assertStringContainsString("/gear-lib.php'", $src, $file);
            $this->assertStringContainsString("/gear-chips.php'", $src, $file);
        }
    }

    public function testSheetViewShowsOwnerGearChipsFromTheUsersOwnRecords(): void
    {
        $view = $this->body('tech-sheets.php', 'handleView');
        $this->assertStringContainsString('db_get_user_gear_records($pdo, (int)$user[\'id\'])', $view);
        $this->assertStringContainsString('gearLinksForSheet(', $view);
        $this->assertStringContainsString("renderGearChips(\$gearLinks, 'owner', [", $view);
    }

    public function testSheetFormGetsNameSuggestionsFromTheUsersOwnRecords(): void
    {
        foreach (['handleNew', 'handleEdit'] as $fn) {
            $body = $this->body('tech-sheets.php', $fn);
            $this->assertStringContainsString('gearNameSuggestions(', $body, $fn);
            $this->assertStringContainsString('db_get_user_gear_records($pdo, (int)$user[\'id\'])', $body, $fn);
        }
        $form = $this->body('tech-sheets.php', 'renderTechSheetForm');
        $this->assertStringContainsString('<datalist id="gear-names">', $form);
        $this->assertStringContainsString('name="driver_name" required list="gear-names"', $form);
    }

    public function testAddedDriverRowsUseTheSuggestionList(): void
    {
        $this->assertStringContainsString("nameInput.setAttribute('list', 'gear-names');", $this->src('js/tech-sheet-form.js'));
    }

    public function testMyCarsShowsGearChipsPerSheetLine(): void
    {
        $list = $this->body('account.php', 'handleAccountList');
        $this->assertStringContainsString('db_get_drivers_for_sheets(', $list);
        $this->assertStringContainsString('gearLinksForSheet(', $list);
        $page = $this->body('account.php', 'renderAccountListPage');
        $this->assertGreaterThanOrEqual(2, substr_count($page, "renderGearChips("));
    }

    public function testNewCopyAvoidsBannedWording(): void
    {
        foreach (['tech-sheets.php', 'account.php'] as $file) {
            $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed)\b/i', $this->src($file), $file);
        }
    }

    public function testAdminRosterAttachesGearAndRendersAGearColumn(): void
    {
        $this->assertStringContainsString("/gear-chips.php'", $this->src('admin.php'));

        $list = $this->body('admin-tech-sheets.php', 'handleTechSheetsList');
        $this->assertStringContainsString('db_get_drivers_for_sheets(', $list);
        $this->assertStringContainsString('db_get_gear_records_for_season(', $list);
        $this->assertStringContainsString('gearAttachToRoster(', $list);

        $page = $this->body('admin-tech-sheets.php', 'renderTechSheetsListPage');
        $this->assertStringContainsString('<th>Gear</th>', $page);
        $this->assertStringContainsString("renderGearChips(\$row['gear_links'] ?? [], 'admin', [", $page);
        $this->assertStringContainsString('colspan="9"', $page);
    }

    public function testAdminSheetReviewShowsTheOwnersGearChips(): void
    {
        $view = $this->body('admin-tech-sheets.php', 'handleTechSheetView');
        $this->assertStringContainsString("db_get_user_gear_records(\$pdo, (int)\$sheet['user_id'])", $view);
        $this->assertStringContainsString('gearLinksForSheet(', $view);
        $page = $this->body('admin-tech-sheets.php', 'renderTechSheetViewPage');
        $this->assertStringContainsString("renderGearChips(\$gearLinks, 'admin', [", $page);
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed)\b/i', $this->src('admin-tech-sheets.php'));
    }
}
