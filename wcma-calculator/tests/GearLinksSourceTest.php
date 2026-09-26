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
        foreach (['tech-sheets.php', 'garage.php'] as $file) {
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

    public function testSheetFormPicksDriversFromTheUsersProfiles(): void
    {
        foreach (['handleNew', 'handleEdit', 'handleSubmit', 'handleUpdate'] as $fn) {
            $this->assertStringContainsString("db_get_user_drivers(\$pdo, (int)\$user['id'])", $this->body('tech-sheets.php', $fn), $fn);
        }
        foreach (['handleSubmit', 'handleUpdate'] as $fn) {
            $this->assertStringContainsString('techSheetApplyDriverChoices($_POST, $owned)', $this->body('tech-sheets.php', $fn), $fn);
        }
        $form = $this->body('tech-sheets.php', 'renderTechSheetForm');
        $this->assertStringContainsString('window.TECH_SHEET_DRIVERS = ', $form);
        $this->assertStringContainsString('<script src="js/driver-choice.js"></script>', $form);
        $this->assertStringNotContainsString('gear-names', $this->src('tech-sheets.php') . $this->src('js/tech-sheet-form.js'));
        $this->assertStringContainsString('WcmaDriverChoice.build(', $this->src('js/tech-sheet-form.js'));
    }

    public function testGarageCarPageShowsGearChipsPerTaggedSheet(): void
    {
        $show = $this->body('garage.php', 'garageShowCar');
        $this->assertStringContainsString('db_get_drivers_for_sheets(', $show);
        $this->assertStringContainsString('gearLinksForSheet(', $show);
        $this->assertStringContainsString("renderGearChips(\$row['gearLinks']", $this->src('garage-page.php'));
    }

    public function testNewCopyAvoidsBannedWording(): void
    {
        foreach (['tech-sheets.php', 'garage.php', 'garage-page.php'] as $file) {
            $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed)\b/i', $this->src($file), $file);
        }
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
