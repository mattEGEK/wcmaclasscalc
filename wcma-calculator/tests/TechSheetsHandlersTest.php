<?php
// wcma-calculator/tests/TechSheetsHandlersTest.php
//
// Source-level guard: the handlers need config.php so they cannot run under PHPUnit. This
// checks the photo edit-lock guard sits in the edit paths and nowhere else.
use PHPUnit\Framework\TestCase;

final class TechSheetsHandlersTest extends TestCase
{
    private function body(string $name): string {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../tech-sheets.php'));
        $start = strpos($src, 'function ' . $name . '(');
        $this->assertNotFalse($start, $name . ' must exist');
        $next = strpos($src, "\nfunction ", $start + 1);
        return $next === false ? substr($src, $start) : substr($src, $start, $next - $start);
    }

    public function testEditLockGuardIsInTheEditPaths(): void
    {
        foreach (['handleView', 'handleEdit', 'handleUpdate'] as $fn) {
            $this->assertStringContainsString('pretechSheetEditable(', $this->body($fn), $fn);
        }
    }

    public function testEditLockGuardIsNotInNewSheetOrPretechSubmit(): void
    {
        foreach (['handleSubmit', 'handlePretechSubmit'] as $fn) {
            $this->assertStringNotContainsString('pretechSheetEditable(', $this->body($fn), $fn);
        }
    }

    public function testNewSheetsStartFromACarAndItsCurrentDeclaration(): void
    {
        foreach (['handleNew', 'handleSubmit'] as $fn) {
            $body = $this->body($fn);
            $this->assertStringContainsString('db_get_user_car(', $body, $fn);
            $this->assertStringContainsString('db_get_car_current_declaration(', $body, $fn);
            $this->assertStringNotContainsString('submission_id=', $body, $fn);
        }
    }

    public function testSubmitAndUpdateWriteDetailsBackToTheCar(): void
    {
        foreach (['handleSubmit', 'handleUpdate'] as $fn) {
            $this->assertStringContainsString('carsApplySheetDetails(', $this->body($fn), $fn);
        }
    }

    public function testUpdateOnlyWritesBackWhenEditingTheCarsNewestSheet(): void
    {
        $body = $this->body('handleUpdate');
        $this->assertStringContainsString('db_get_car_latest_tech_sheet_id(', $body);
        $this->assertMatchesRegularExpression(
            '/if \(db_get_car_latest_tech_sheet_id\(.*?===\s*\$id\)\s*\{\s*carsApplySheetDetails\(/s',
            $body
        );
    }

    public function testSubmittingASheetTagsTheEventAndNewCanPreselectIt(): void
    {
        $this->assertStringContainsString('db_tag_event(', $this->body('handleSubmit'));
        $this->assertStringContainsString('$eventId', $this->body('handleNew'));
    }

    public function testValidationFailureRedirectCarriesTheChosenEventId(): void
    {
        $body = $this->body('handleSubmit');
        $this->assertMatchesRegularExpression(
            '/driverRows === null\).*?if \(\$eventId > 0\) \$redirect \.= \'&event_id=\' \. \$eventId;/s',
            $body
        );
    }
}
