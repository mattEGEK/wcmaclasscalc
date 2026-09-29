<?php
// wcma-calculator/tests/TaDriftSheetPageTest.php
use PHPUnit\Framework\TestCase;

// Same page-level requires as tests/IceSheetPageTest.php, because garage-page.php needs them.
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../events-lib.php';
require_once __DIR__ . '/../tech-status.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../gear-chips.php';
require_once __DIR__ . '/../home-page.php';
require_once __DIR__ . '/../garage-lib.php';
require_once __DIR__ . '/../garage-page.php';
require_once __DIR__ . '/../reminders-lib.php';
require_once __DIR__ . '/../ta-drift-sheet-page.php';

final class TaDriftSheetPageTest extends TestCase
{
    private function vm(?array $sheet = null, array $sheetDrivers = []): array {
        $car = ['id' => 3, 'car_number' => '86', 'year' => '2015', 'make' => 'Subaru', 'model' => 'BRZ',
                'colour' => '', 'engine_cc' => '1998', 'archived_at' => null, 'owner_user_id' => 1];
        $event = ['id' => 20, 'name' => 'WSCC Time Attack', 'event_date' => '2026-07-12', 'discipline' => 'summer', 'host_club' => 'WSCC'];
        $other = ['id' => 21, 'name' => 'NASCC TA', 'event_date' => '2026-08-09', 'discipline' => 'summer', 'host_club' => 'NASCC'];
        $drivers = [['id' => 5, 'name' => 'Pat Winters', 'name_norm' => 'pat winters', 'user_id' => 1, 'owner_user_id' => 1],
                    ['id' => 6, 'name' => 'Sam Patel', 'name_norm' => 'sam patel', 'user_id' => null, 'owner_user_id' => 1]];
        return taDriftSheetFormVm($car, $event, [$event, $other], $drivers, $sheet, $sheetDrivers, 'tok');
    }

    public function testVm(): void
    {
        $vm = $this->vm();
        $this->assertSame('WSCC', $vm['club']);
        $this->assertFalse($vm['caged']);
        $this->assertSame(taDriftChecklistSections(true, 'WSCC'), $vm['sections']['on']);
        $this->assertSame(taDriftChecklistSections(false, 'WSCC'), $vm['sections']['off']);
        $this->assertSame([21], array_map(fn($e) => (int)$e['id'], $vm['otherEvents']));
        $this->assertSame('tech-sheets.php?action=submit-ta-drift', $vm['action']);
        $this->assertSame('wcma-tsdraft:1:3:20', $vm['draftKey']);
    }

    public function testNewFormOpensWithTheSafetyLineAndHasTheFields(): void
    {
        $html = renderTaDriftTechSheetFormHtml($this->vm());
        $this->assertSame("Check each item on the car itself before you tick it. You're confirming your car is safe to go on track.", TA_DRIFT_SHEET_INTRO);
        $this->assertStringContainsString(h(TA_DRIFT_SHEET_INTRO), $html);   // the apostrophe is escaped
        $this->assertLessThan(strpos($html, 'WSCC Time Attack'), strpos($html, 'Check each item on the car itself'));
        $this->assertStringContainsString('<input type="hidden" name="car_id" value="3">', $html);
        $this->assertStringContainsString('<input type="hidden" name="event_id" value="20">', $html);
        $this->assertStringContainsString('<input type="checkbox" id="ta_drift_caged" name="caged" value="1">', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=new-ta-drift&amp;car_id=3&amp;event_id=21"', $html);
        $this->assertStringContainsString('id="car_colour"', $html);   // no colour on file yet
        $this->assertStringContainsString('id="endurance-drivers-card"', $html);
        $this->assertStringContainsString('<input type="hidden" id="sheet_type" value="endurance">', $html);
        $this->assertStringContainsString('id="ta-drift-helmet-note"', $html);
        $this->assertStringContainsString('window.TA_DRIFT_SECTIONS', $html);
        $this->assertStringContainsString('window.TA_DRIFT_RENDERED_CAGED = false;', $html);
        $this->assertStringContainsString('window.TECH_SHEET_DRAFT_KEY = "wcma-tsdraft:1:3:20";', $html);
        $this->assertStringContainsString('Submit TA/Drift Tech Sheet', $html);
        $this->assertLessThan(strpos($html, 'js/tech-sheet-form.js'), strpos($html, 'js/ice-class-picker.js'));
    }

    public function testNoClassWeightHpOrLogBook(): void
    {
        $html = renderTaDriftTechSheetFormHtml($this->vm());
        foreach (['name="class"', 'name="car_weight"', 'name="engine_hp"', 'name="log_book_turned_in"'] as $field) {
            $this->assertStringNotContainsString($field, $html);
        }
    }

    public function testEditKeepsTheCageAnswerAndAddedDrivers(): void
    {
        $sheet = ['id' => 9, 'caged' => 1, 'driver_name' => 'Pat Winters', 'entrant_name' => 'Team Pat',
                  'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'entrant_signature_path' => 'x.png', 'driver_signature_path' => null];
        $vm = $this->vm($sheet, [['driver_number' => 2, 'driver_name' => 'Sam Patel', 'equipment_json' => '{"helmet":{"competitor_confirmed":true,"value":"SA2020"}}']]);
        $this->assertTrue($vm['caged']);
        $this->assertSame([], $vm['otherEvents']);
        $this->assertNull($vm['draftKey']);
        $this->assertSame([['driver_number' => 2, 'driver_choice' => '6', 'new_name' => '',
            'equipment' => ['helmet' => ['competitor_confirmed' => true, 'value' => 'SA2020']]]], $vm['existingDrivers']);

        $html = renderTaDriftTechSheetFormHtml($vm);
        $this->assertStringContainsString('<input type="checkbox" id="ta_drift_caged" name="caged" value="1" checked>', $html);
        $this->assertStringContainsString('<input type="hidden" name="tech_sheet_id" value="9">', $html);
        $this->assertStringContainsString('value="Team Pat"', $html);
        $this->assertStringContainsString('window.TA_DRIFT_RENDERED_CAGED = true;', $html);
        $this->assertStringContainsString('window.TECH_SHEET_HAS_ENTRANT_SIGNATURE = true;', $html);
        $this->assertStringContainsString('"driver_choice":"6"', $html);
        $this->assertStringContainsString('Save Changes', $html);
    }

    public function testNamesAreEscaped(): void
    {
        $vm = $this->vm();
        $vm['event']['name'] = '<b>TA</b>';
        $this->assertStringNotContainsString('<b>TA</b>', renderTaDriftTechSheetFormHtml($vm));
    }
}
