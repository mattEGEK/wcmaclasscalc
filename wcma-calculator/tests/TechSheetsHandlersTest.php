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

    // handleSubmit()/handleUpdate() setFlash() a message (e.g. "… is on this sheet twice.")
    // before redirecting back to the new/edit form on a validation failure. Without this, that
    // flash is silently dropped and the competitor never sees why their submission was refused.
    public function testTheNewEditFormDisplaysTheFlashMessage(): void
    {
        $body = $this->body('renderTechSheetForm');
        $this->assertStringContainsString('getFlash(', $body);
        $this->assertStringContainsString("h(\$flash['message'])", $body);
    }

    // Ice events are filtered out of the summer-facing pickers (fix wave, Fix 1): db_insert_tech_sheet
    // /db_update_tech_sheet can still throw InvalidArgumentException for a mismatched event (a
    // tampered request, since the picker itself no longer lists ice events), and that must become a
    // flashed error + redirect, not an uncaught 500.
    public function testNewAndEditEventListsAreSummerOnly(): void
    {
        $this->assertStringContainsString("db_get_active_events(\$pdo, DISCIPLINE_SUMMER)", $this->body('handleNew'));
        $this->assertStringContainsString("db_get_active_events(\$pdo, DISCIPLINE_SUMMER)", $this->body('handleEdit'));
    }

    public function testSubmitAndUpdateCatchInvalidArgumentExceptionFromTheDbCall(): void
    {
        $submitBody = $this->body('handleSubmit');
        $this->assertMatchesRegularExpression(
            '/try \{.*?db_insert_tech_sheet\(.*?\} catch \(InvalidArgumentException \$e\) \{.*?setFlash\(\$e->getMessage\(\), \'error\'\);.*?exit;.*?\}/s',
            $submitBody
        );
        $updateBody = $this->body('handleUpdate');
        $this->assertMatchesRegularExpression(
            '/try \{.*?db_update_tech_sheet\(.*?\} catch \(InvalidArgumentException \$e\) \{.*?setFlash\(\$e->getMessage\(\), \'error\'\);.*?exit;.*?\}/s',
            $updateBody
        );
    }

    public function testIceRoutesExist(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../tech-sheets.php'));
        $this->assertStringContainsString("case 'new-ice':", $src);
        $this->assertStringContainsString("case 'submit-ice':", $src);
        $this->assertStringContainsString("require __DIR__ . '/ice-sheet-page.php';", $src);
    }

    public function testNewIceUsesIceEventsAndNoDeclaration(): void
    {
        foreach (['handleNewIce', 'handleSubmitIce'] as $fn) {
            $body = $this->body($fn);
            $this->assertStringContainsString('db_get_user_car(', $body, $fn);
            $this->assertStringNotContainsString('db_get_car_current_declaration(', $body, $fn);
        }
        $this->assertStringContainsString('db_get_active_events($pdo, DISCIPLINE_ICE)', $this->body('handleNewIce'));
        $submit = $this->body('handleSubmitIce');
        $this->assertStringContainsString('iceSheetValidate(', $submit);
        $this->assertStringContainsString("'car_id' => \$carId", $submit);
        $this->assertStringContainsString("'sheet_type' => 'ice'", $submit);
        $this->assertStringContainsString('catch (InvalidArgumentException $e)', $submit);
        $this->assertStringContainsString('techSheetRecipientEmail(', $submit);
    }

    public function testIceUpdateKeepsTheSheetsEventAndEmailsTheAccountHolder(): void
    {
        $body = $this->body('handleUpdateIce');
        $this->assertStringContainsString("'event_id' => (int)\$sheet['event_id']", $body);
        $this->assertStringNotContainsString("\$_POST['event_id']", $body);
        $this->assertStringContainsString('iceSheetValidate(', $body);
        $this->assertStringContainsString('techSheetRecipientEmail(', $body);
        $this->assertStringContainsString('handleUpdateIce(', $this->body('handleUpdate'));
        $this->assertStringContainsString('techSheetIsIce(', $this->body('handleEdit'));
    }

    public function testPretechIsOffForIceSheets(): void
    {
        foreach (['handlePretech', 'handlePretechSubmit'] as $fn) {
            $this->assertStringContainsString('techSheetIsIce(', $this->body($fn), $fn);
        }
        $this->assertStringContainsString('!techSheetIsIce($sheet)', $this->body('handleView'));
    }

    public function testResendUsesTheRecipientHelper(): void
    {
        $this->assertStringContainsString('techSheetRecipientEmail(', $this->body('handleResendTechSheet'));
    }
}
