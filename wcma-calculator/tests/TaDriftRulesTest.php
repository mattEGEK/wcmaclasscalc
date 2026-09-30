<?php
// wcma-calculator/tests/TaDriftRulesTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../ta-drift-rules.php';

final class TaDriftRulesTest extends TestCase
{
    private function items(array $sections): array {
        $out = [];
        foreach ($sections as $section) $out += $section['items'];
        return $out;
    }

    public function testCageSectionOnlyWhenCaged(): void
    {
        $this->assertArrayNotHasKey(TA_DRIFT_CAGED_SECTION, taDriftChecklistSections(false, 'WSCC'));
        $caged = taDriftChecklistSections(true, 'WSCC');
        $this->assertSame(['cage_spec', 'harness'], array_keys($caged[TA_DRIFT_CAGED_SECTION]['items']));
    }

    public function testRegulationsItemNamesTheHostClub(): void
    {
        $items = $this->items(taDriftChecklistSections(false, 'NASCC'));
        $this->assertSame('I have read the NASCC supplementary regulations and my car complies', $items['supps_read']);
        foreach ($items as $label) $this->assertStringNotContainsString('{club}', $label);
    }

    public function testTowPointsRequiredAndExtinguisherRecommended(): void
    {
        $items = $this->items(taDriftChecklistSections(false, 'WSCC'));
        $this->assertStringContainsString('factory ones are fine', $items['tow_points']);
        $this->assertStringNotContainsString('(recommended)', $items['tow_points']);
        $this->assertStringEndsWith('(recommended)', $items['fire_extinguisher']);
    }

    public function testItemKeysAreUniqueAcrossSections(): void
    {
        $count = 0;
        foreach (TA_DRIFT_CHECKLIST_SECTIONS as $section) $count += count($section['items']);
        $this->assertSame($count, count($this->items(TA_DRIFT_CHECKLIST_SECTIONS)));
    }

    public function testHeadAndNeckRestraintOnlyRequiredWhenCaged(): void
    {
        $this->assertTrue(taDriftEquipmentItems(false)['head_neck_restraints']['optional']);
        $this->assertFalse(taDriftEquipmentItems(true)['head_neck_restraints']['optional']);
        $this->assertTrue(taDriftEquipmentItems(false)['helmet']['has_rating']);
        $this->assertSame(['helmet', 'clothing', 'head_neck_restraints'], array_keys(taDriftEquipmentItems(false)));
    }

    public function testExistingValidatorsAcceptTheShape(): void
    {
        $sections = taDriftChecklistSections(true, 'WSCC');
        $checklist = array_map(fn($v): array => ['status' => 'ok'], emptyChecklist($sections));
        $this->assertTrue(validateChecklist($checklist, $sections));
        unset($checklist['harness']);
        $this->assertFalse(validateChecklist($checklist, $sections));

        $items = taDriftEquipmentItems(false);
        $equipment = emptyDriverEquipment($items);
        $this->assertSame(['helmet', 'clothing', 'head_neck_restraints'], array_keys($equipment));
        $this->assertFalse(validateDriverEquipment($equipment, $items));
        $equipment['helmet'] = ['competitor_confirmed' => true, 'value' => 'Snell SA2020'];
        $equipment['clothing'] = ['competitor_confirmed' => true, 'value' => null];
        $this->assertTrue(validateDriverEquipment($equipment, $items));
        $this->assertFalse(validateDriverEquipment($equipment, taDriftEquipmentItems(true)));   // caged: restraint required
    }

    public function testSummerEquipmentDefaultIsUnchanged(): void
    {
        $this->assertSame(array_keys(TECH_DRIVER_EQUIPMENT_ITEMS), array_keys(emptyDriverEquipment()));
    }

    public function testHelmetNote(): void
    {
        $this->assertStringContainsString('ECE 22.05', taDriftHelmetNote(false));
        $this->assertStringContainsString('Snell SA', taDriftHelmetNote(true));
        $this->assertStringContainsString('head and neck restraint', taDriftHelmetNote(true));
    }
}
