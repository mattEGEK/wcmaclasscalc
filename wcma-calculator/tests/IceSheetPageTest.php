<?php
// wcma-calculator/tests/IceSheetPageTest.php
use PHPUnit\Framework\TestCase;

// Same page-level requires as tests/GaragePageTest.php, because garage-page.php needs them.
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
require_once __DIR__ . '/../ice-sheet-page.php';

final class IceSheetPageTest extends TestCase
{
    private function vm(?array $sheet = null): array {
        $car = ['id' => 3, 'car_number' => '7', 'year' => '1985', 'make' => 'Chevrolet', 'model' => 'Chevette',
                'colour' => '', 'engine_cc' => '1600', 'archived_at' => null];
        $event = ['id' => 20, 'name' => 'NASCC Ice #1', 'event_date' => '2026-12-12', 'discipline' => 'ice', 'host_club' => 'NASCC'];
        $other = ['id' => 21, 'name' => 'WSCC Fire on Ice', 'event_date' => '2027-01-04', 'discipline' => 'ice', 'host_club' => 'WSCC'];
        $drivers = [['id' => 5, 'name' => 'Jordan Lee', 'name_norm' => 'jordan lee', 'user_id' => 1, 'owner_user_id' => 1]];
        return iceSheetFormVm($car, $event, [$event, $other], $drivers, $sheet, 'tok');
    }

    public function testVmHoldsTheClubsClassesAndPerClassData(): void
    {
        $vm = $this->vm();
        $this->assertSame('NASCC', $vm['club']);
        $this->assertSame(iceClassOptions('NASCC'), $vm['classOptions']);
        $this->assertSame('', $vm['selectedClass']);
        $this->assertSame(iceChecklistSections('NASCC', 'caged'), $vm['sectionsByClass']['LS']);
        $this->assertTrue($vm['fhrByClass']['LS']);
        $this->assertFalse($vm['fhrByClass']['SS']);
        $this->assertSame(iceHelmetNote('NASCC', iceClass('NASCC', 'CH')), $vm['helmetNotes']['CH']);
        $this->assertSame([21], array_map(fn($e) => (int)$e['id'], $vm['otherEvents']));
        $this->assertSame('tech-sheets.php?action=submit-ice', $vm['action']);
    }

    public function testNewFormHasTheClassPickerWeightEventAndScripts(): void
    {
        $html = renderIceTechSheetFormHtml($this->vm());
        $this->assertStringContainsString('<select id="ice_class" name="class" required data-message="Choose your class.">', $html);
        $this->assertStringContainsString('<option value="LS">LS — Limited Stud</option>', $html);
        $this->assertStringContainsString('name="car_weight"', $html);
        $this->assertStringContainsString('<input type="hidden" name="event_id" value="20">', $html);
        $this->assertStringContainsString('<input type="hidden" name="car_id" value="3">', $html);
        $this->assertStringContainsString('NASCC Ice #1', $html);
        $this->assertStringContainsString('Northern Alberta Sports Car Club', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=new-ice&amp;car_id=3&amp;event_id=21"', $html);
        $this->assertStringContainsString('id="car_colour"', $html);   // car has no colour on file
        $this->assertStringContainsString('id="ice-class-note"', $html);
        $this->assertStringContainsString('id="ice-helmet-note"', $html);
        $this->assertStringContainsString('window.ICE_SECTIONS_BY_CLASS', $html);
        $this->assertStringContainsString('window.ICE_RENDERED_CLASS = "";', $html);
        $this->assertStringContainsString('<script src="js/ice-class-picker.js"></script>', $html);
        $this->assertLessThan(strpos($html, 'js/tech-sheet-form.js'), strpos($html, 'js/ice-class-picker.js'));
        $this->assertStringNotContainsString('id="sheet_type"', $html);
        $this->assertStringNotContainsString('id="add-driver-btn"', $html);
    }

    public function testEditFormPreselectsTheClassAndPostsToUpdate(): void
    {
        $sheet = ['id' => 9, 'event_id' => 20, 'class' => 'LS', 'car_weight' => 2300, 'entrant_name' => 'Jordan Lee',
                  'driver_name' => 'Jordan Lee', 'engine_hp' => '90', 'checklist_json' => '{"cage":{"status":"ok"}}',
                  'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1, 'entrant_signature_path' => 'x.png',
                  'driver_signature_path' => null];
        $vm = $this->vm($sheet);
        $this->assertSame('tech-sheets.php?action=update', $vm['action']);
        $html = renderIceTechSheetFormHtml($vm);
        $this->assertStringContainsString('<option value="LS" selected>LS — Limited Stud</option>', $html);
        $this->assertStringContainsString('<input type="hidden" name="tech_sheet_id" value="9">', $html);
        $this->assertStringContainsString('value="2300"', $html);
        $this->assertStringContainsString('window.ICE_RENDERED_CLASS = "LS";', $html);
        $this->assertStringContainsString('Leave the pads blank to keep the signatures already on file.', $html);
        $this->assertStringNotContainsString('action=new-ice', $html);   // the event can't change on an edit
    }

    public function testTextIsEscaped(): void
    {
        $vm = $this->vm();
        $vm['event']['name'] = 'Ice <Day>';
        $this->assertStringContainsString('Ice &lt;Day&gt;', renderIceTechSheetFormHtml($vm));
    }

    private function renderNewSheet(): string {
        return renderIceTechSheetFormHtml($this->vm());
    }

    public function testRequiredAndOptionalFieldsSaySoAndCarryAMessage(): void
    {
        $html = $this->renderNewSheet();
        $this->assertStringContainsString('<label for="car_weight">Race weight in lbs, without driver (required)</label>', $html);
        $this->assertStringContainsString('data-message="Enter the race weight."', $html);
        $this->assertMatchesRegularExpression('/<label for="ice_class">[A-Z]+ class \(required\)<\/label>/', $html);
        $this->assertStringContainsString('data-message="Choose your class."', $html);
        $this->assertStringContainsString('<label for="entrant_name">Entrant (required)</label>', $html);
        $this->assertStringContainsString('<label for="driver1_choice">Driver (required)</label>', $html);
        $this->assertStringContainsString('<label for="engine_hp">Engine HP (optional)</label>', $html);
        $this->assertStringContainsString('data-radio-group="Log book turned in? (required)"', $html);
        $this->assertStringContainsString('data-message="Choose Yes or No for the log book."', $html);
        $this->assertStringContainsString('<label for="car_colour">Car colour (required)</label>', $html);
        $this->assertStringContainsString('<script src="js/form-problems.js"></script>', $html);
        $this->assertLessThan(strpos($html, 'class="form-actions"'), strpos($html, 'id="tech-sheet-error"'));
    }

    public function testSignaturePadsCanCollapseToOne(): void
    {
        $html = $this->renderNewSheet();
        $this->assertStringContainsString('<label id="entrant-sig-label">Entrant\'s signature</label>', $html);
        $this->assertMatchesRegularExpression('/<div id="driver-sig-block">\s*<label>Driver\'s signature<\/label>/', $html);
        $this->assertNotFalse(strpos($html, 'id="sig-error"'));
        $this->assertLessThan(strpos($html, 'id="entrant-sig-label"'), strpos($html, 'id="sig-error"'));
    }
}
