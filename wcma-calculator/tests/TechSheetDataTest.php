<?php
// wcma-calculator/tests/TechSheetDataTest.php
use PHPUnit\Framework\TestCase;

final class TechSheetDataTest extends TestCase
{
    public function testEmptyChecklistHasEveryItemKeyAsNull(): void
    {
        require_once __DIR__ . '/../tech-sheet-data.php';
        $checklist = emptyChecklist();
        $expectedKeys = [];
        foreach (TECH_CHECKLIST_SECTIONS as $section) {
            foreach ($section['items'] as $key => $label) {
                $expectedKeys[] = $key;
            }
        }
        $this->assertCount(count($expectedKeys), $checklist);
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $checklist);
            $this->assertNull($checklist[$key]);
        }
    }

    public function testValidateChecklistRejectsMissingItem(): void
    {
        require_once __DIR__ . '/../tech-sheet-data.php';
        $checklist = emptyChecklist();
        unset($checklist['steering_linkage']);
        $this->assertFalse(validateChecklist($checklist));
    }

    public function testValidateChecklistRejectsNullValue(): void
    {
        require_once __DIR__ . '/../tech-sheet-data.php';
        $checklist = emptyChecklist();
        $this->assertFalse(validateChecklist($checklist)); // all still null
    }

    public function testValidateChecklistAcceptsAllOk(): void
    {
        require_once __DIR__ . '/../tech-sheet-data.php';
        $checklist = emptyChecklist();
        foreach ($checklist as $key => $v) {
            $checklist[$key] = ['status' => 'ok'];
        }
        $this->assertTrue(validateChecklist($checklist));
    }

    public function testValidateChecklistRejectsInvalidStatus(): void
    {
        require_once __DIR__ . '/../tech-sheet-data.php';
        $checklist = emptyChecklist();
        foreach ($checklist as $key => $v) {
            $checklist[$key] = ['status' => 'ok'];
        }
        $checklist['steering_linkage'] = ['status' => 'maybe'];
        $this->assertFalse(validateChecklist($checklist));
    }

    public function testValidateDriverEquipmentRequiresHelmetAndSuitRating(): void
    {
        require_once __DIR__ . '/../tech-sheet-data.php';
        $equipment = emptyDriverEquipment();
        foreach ($equipment as $key => $v) {
            $equipment[$key]['competitor_confirmed'] = true;
        }
        $this->assertFalse(validateDriverEquipment($equipment)); // helmet/suit rating still blank
        $equipment['helmet']['value'] = 'SA2020';
        $equipment['suit']['value'] = 'SFI 3.2A/5';
        $this->assertTrue(validateDriverEquipment($equipment));
    }

    public function testValidateDriverEquipmentAllowsUnderwearUnconfirmed(): void
    {
        require_once __DIR__ . '/../tech-sheet-data.php';
        $equipment = emptyDriverEquipment();
        foreach ($equipment as $key => $v) {
            $equipment[$key]['competitor_confirmed'] = true;
        }
        $equipment['helmet']['value'] = 'SA2020';
        $equipment['suit']['value'] = 'SFI 3.2A/5';
        $equipment['underwear']['competitor_confirmed'] = false;
        $this->assertTrue(validateDriverEquipment($equipment));
    }

    public function testValidateDriverEquipmentRejectsUnconfirmedRequiredItem(): void
    {
        require_once __DIR__ . '/../tech-sheet-data.php';
        $equipment = emptyDriverEquipment();
        foreach ($equipment as $key => $v) {
            $equipment[$key]['competitor_confirmed'] = true;
        }
        $equipment['helmet']['value'] = 'SA2020';
        $equipment['suit']['value'] = 'SFI 3.2A/5';
        $equipment['gloves']['competitor_confirmed'] = false;
        $this->assertFalse(validateDriverEquipment($equipment));
    }
}
