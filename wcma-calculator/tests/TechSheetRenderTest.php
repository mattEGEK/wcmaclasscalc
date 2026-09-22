<?php
require_once __DIR__ . '/../view_helpers.php';

use PHPUnit\Framework\TestCase;

final class TechSheetRenderTest extends TestCase
{
    private function sampleSheet(): array {
        require_once __DIR__ . '/../tech-sheet-data.php';
        $checklist = emptyChecklist();
        foreach ($checklist as $key => $v) { $checklist[$key] = ['status' => 'ok']; }
        $equipment = emptyDriverEquipment();
        foreach ($equipment as $key => $v) { $equipment[$key]['competitor_confirmed'] = true; }
        $equipment['helmet']['value'] = 'SA2020';
        $equipment['suit']['value'] = 'SFI 3.2A/5';

        return [
            'id' => 1, 'sheet_type' => 'standard', 'entrant_name' => 'Jane Racer', 'driver_name' => 'Jane Racer',
            'car_make' => 'Mazda', 'car_model' => 'MX-5', 'car_colour' => 'Red', 'car_number' => '42',
            'class' => 'IT1', 'engine_cc' => '1800', 'engine_hp' => '150', 'car_weight' => 2200,
            'checklist_json' => json_encode($checklist), 'driver1_equipment_json' => json_encode($equipment),
            'log_book_turned_in' => 1, 'entrant_signature_path' => null, 'driver_signature_path' => null,
            'tech_signature_path' => null, 'status' => 'submitted',
        ];
    }

    public function testRenderIncludesHeaderFields(): void
    {
        require_once __DIR__ . '/../tech-sheet-render.php';
        $html = renderTechSheetHtml($this->sampleSheet(), [], ['name' => 'Spring Sprint', 'event_date' => '2026-05-10']);
        $this->assertStringContainsString('Jane Racer', $html);
        $this->assertStringContainsString('Mazda', $html);
        $this->assertStringContainsString('MX-5', $html);
        $this->assertStringContainsString('IT1', $html);
        $this->assertStringContainsString('Spring Sprint', $html);
    }

    public function testRenderIncludesEveryChecklistItemLabel(): void
    {
        require_once __DIR__ . '/../tech-sheet-render.php';
        $html = renderTechSheetHtml($this->sampleSheet(), [], ['name' => 'Spring Sprint', 'event_date' => '2026-05-10']);
        foreach (TECH_CHECKLIST_SECTIONS as $section) {
            foreach ($section['items'] as $key => $label) {
                $this->assertStringContainsString(htmlspecialchars($label), $html);
            }
        }
    }

    public function testRenderIncludesDriverEquipmentRatings(): void
    {
        require_once __DIR__ . '/../tech-sheet-render.php';
        $html = renderTechSheetHtml($this->sampleSheet(), [], ['name' => 'Spring Sprint', 'event_date' => '2026-05-10']);
        $this->assertStringContainsString('SA2020', $html);
        $this->assertStringContainsString('SFI 3.2A/5', $html);
    }

    public function testRenderIncludesAdditionalDriversForEndurance(): void
    {
        require_once __DIR__ . '/../tech-sheet-render.php';
        $sheet = $this->sampleSheet();
        $sheet['sheet_type'] = 'endurance';
        $drivers = [
            ['driver_number' => 2, 'driver_name' => 'Co-Driver A', 'equipment_json' => json_encode(emptyDriverEquipment())],
        ];
        $html = renderTechSheetHtml($sheet, $drivers, ['name' => 'Spring Sprint', 'event_date' => '2026-05-10']);
        $this->assertStringContainsString('Co-Driver A', $html);
    }
}
