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

    public function testSubmitAndUpdateSnapshotTheCarRecord(): void
    {
        foreach (['handleSubmit', 'handleUpdate'] as $fn) {
            $body = $this->body($fn);
            $this->assertStringContainsString('carsSheetSnapshot($car, $_POST)', $body, $fn);
            $this->assertStringContainsString("'car_make' => \$car['make'], 'car_model' => \$car['model']", $body, $fn);
            $this->assertStringContainsString("if (\$snap['colour_for_car'] !== null) db_update_car(", $body, $fn);
            $this->assertStringNotContainsString('carsApplySheetDetails(', $body, $fn);
        }
        $this->assertStringContainsString('db_get_user_car($pdo, (int)$user[\'id\'], (int)$sheet[\'car_id\'])', $this->body('handleUpdate'));
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
