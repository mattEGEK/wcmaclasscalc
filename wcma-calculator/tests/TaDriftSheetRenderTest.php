<?php
// wcma-calculator/tests/TaDriftSheetRenderTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../tech-sheet-render.php';
require_once __DIR__ . '/../tech-sheet-next.php';

final class TaDriftSheetRenderTest extends TestCase
{
    private function sheet(bool $caged): array {
        $checklist = array_map(fn($v): array => ['status' => 'ok'], emptyChecklist(taDriftChecklistSections($caged, 'WSCC')));
        $equipment = emptyDriverEquipment(taDriftEquipmentItems($caged));
        foreach ($equipment as $k => $v) $equipment[$k]['competitor_confirmed'] = true;
        $equipment['helmet']['value'] = 'Snell SA2020';
        return [
            'id' => 4, 'sheet_type' => 'ta_drift', 'discipline' => 'summer', 'club' => 'WSCC', 'season' => 2026, 'caged' => $caged ? 1 : 0,
            'entrant_name' => 'Pat Winters', 'driver_name' => 'Pat Winters', 'car_make' => 'Subaru', 'car_model' => 'BRZ',
            'car_colour' => 'White', 'car_number' => '86', 'class' => '', 'engine_cc' => '1998', 'engine_hp' => null, 'car_weight' => 0,
            'checklist_json' => json_encode($checklist), 'driver1_equipment_json' => json_encode($equipment),
            'log_book_turned_in' => null, 'entrant_signature_path' => null, 'driver_signature_path' => null,
            'tech_signature_path' => null, 'status' => 'submitted',
        ];
    }

    private function event(): array {
        return ['name' => 'WSCC Time Attack', 'event_date' => '2026-07-12'];
    }

    public function testHeaderAndFields(): void
    {
        $html = renderTechSheetHtml($this->sheet(false), [], $this->event());
        $this->assertStringContainsString('TA/DRIFT VEHICLE INSPECTION FORM', $html);
        $this->assertStringContainsString('WSCC Time Attack', $html);
        $this->assertStringContainsString(' · WSCC · 2026', $html);
        $this->assertStringContainsString('<strong>Roll bar or cage:</strong> No', $html);
        $this->assertStringNotContainsString('Car Weight', $html);
        $this->assertStringNotContainsString('<strong>Class:</strong>', $html);
        $this->assertStringNotContainsString('Log Book', $html);
        $this->assertStringContainsString('I have read the WSCC supplementary regulations and my car complies', $html);
        $this->assertStringNotContainsString('Roll bar or cage built to WCMA spec', $html);
    }

    public function testCagedSheetShowsTheCageChecks(): void
    {
        $html = renderTechSheetHtml($this->sheet(true), [], $this->event());
        $this->assertStringContainsString('<strong>Roll bar or cage:</strong> Yes', $html);
        $this->assertStringContainsString('Roll bar or cage built to WCMA spec', $html);
    }

    public function testAddedDriversGearIsShown(): void
    {
        $equipment = emptyDriverEquipment(taDriftEquipmentItems(false));
        $html = renderTechSheetHtml($this->sheet(false), [['driver_number' => 2, 'driver_name' => 'Sam Patel', 'equipment_json' => json_encode($equipment)]], $this->event());
        $this->assertStringContainsString('Driver 2 — Sam Patel', $html);
    }

    public function testRaceSheetIsUnchanged(): void
    {
        $race = ['sheet_type' => 'standard', 'club' => null, 'caged' => 0, 'class' => 'IT1', 'car_weight' => 2200, 'log_book_turned_in' => 1,
                 'checklist_json' => '{}', 'driver1_equipment_json' => '{}'] + $this->sheet(false);
        $html = renderTechSheetHtml($race, [], $this->event());
        $this->assertStringContainsString('>VEHICLE INSPECTION FORM<', $html);
        $this->assertStringContainsString('<strong>Class:</strong> IT1', $html);
        $this->assertStringContainsString('Car Weight', $html);
        $this->assertStringContainsString('Vehicle Log Book Turned In', $html);
    }

    public function testViewTitle(): void
    {
        $this->assertSame('TA/Drift tech sheet — #86 Subaru BRZ — WSCC Time Attack', techSheetViewTitle($this->sheet(false), $this->event()));
    }
}
