<?php
// wcma-calculator/tests/IceRulesTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../ice-rules.php';

final class IceRulesTest extends TestCase
{
    public function testClubsAreNasccAndWscc(): void
    {
        $this->assertSame(['NASCC', 'WSCC'], iceClubCodes());
        $this->assertSame('Winnipeg Sports Car Club', iceClubLabel('WSCC'));
        $this->assertNull(iceClubLabel('XYZ'));
    }

    public function testEveryClassHasAKnownGroupLabelAndNote(): void
    {
        foreach (ICE_CLUBS as $club => $def) {
            $this->assertNotEmpty($def['classes'], $club);
            foreach ($def['classes'] as $code => $class) {
                $this->assertContains($class['group'], ICE_CLASS_GROUPS, "$club $code");
                $this->assertNotSame('', $class['label'], "$club $code");
                $this->assertNotSame('', $class['note'], "$club $code");
            }
        }
    }

    public function testEveryGroupHasChecklistSections(): void
    {
        foreach (ICE_CLASS_GROUPS as $group) {
            $this->assertArrayHasKey($group, ICE_CHECKLIST_SECTIONS);
            $this->assertNotEmpty(ICE_CHECKLIST_SECTIONS[$group]);
        }
    }

    public function testOverridesOnlyNameExistingItems(): void
    {
        $known = [];
        foreach (ICE_CHECKLIST_SECTIONS as $sections) {
            foreach ($sections as $section) $known += $section['items'];
        }
        foreach (ICE_CLUB_OVERRIDES as $club => $overrides) {
            $this->assertContains($club, iceClubCodes());
            foreach (array_keys($overrides) as $key) {
                $this->assertArrayHasKey($key, $known, "$club override '$key'");
            }
        }
    }

    public function testIceClassLookup(): void
    {
        $ls = iceClass('NASCC', 'LS');
        $this->assertSame('LS', $ls['code']);
        $this->assertSame('caged', $ls['group']);
        $this->assertTrue($ls['fhr']);
        $this->assertFalse(iceClass('NASCC', 'NS')['fhr']);
        $this->assertSame('street_safe', iceClass('WSCC', 'FOI-SS')['group']);
        $this->assertSame('drift', iceClass('WSCC', 'DRIFT')['group']);
        $this->assertNull(iceClass('WSCC', 'LS'));
        $this->assertNull(iceClass('XYZ', 'LS'));
    }

    public function testChsIsTheSameAsCh(): void
    {
        $this->assertSame(iceClass('NASCC', 'CH')['group'], iceClass('NASCC', 'CHSS')['group']);
    }

    public function testClassOptionsAreCodeDashLabel(): void
    {
        $opts = iceClassOptions('WSCC');
        $this->assertSame(['DRIFT', 'FOI-SS', 'FOI-STD'], array_keys($opts));
        $this->assertSame('FOI-STD — Fire on Ice – Studded', $opts['FOI-STD']);
        $this->assertSame([], iceClassOptions('XYZ'));
    }

    public function testChecklistAppliesClubWording(): void
    {
        $flat = fn(array $sections): array => array_merge(...array_map(fn($s) => $s['items'], array_values($sections)));
        $n = $flat(iceChecklistSections('NASCC', 'street_safe'));
        $w = $flat(iceChecklistSections('WSCC', 'street_safe'));
        $this->assertSame('Airbags removed (disabling is not enough)', $n['airbags']);
        $this->assertSame('Airbags disabled or removed', $w['airbags']);
        $this->assertStringContainsString('4 rear brake lights', $n['brake_lights']);
        $this->assertStringContainsString('3 rear brake lights', $w['brake_lights']);
    }

    public function testNullOverrideOmitsItemAndEmptySectionsDrop(): void
    {
        $flat = fn(array $sections): array => array_merge(...array_map(fn($s) => $s['items'], array_values($sections)));
        $this->assertArrayHasKey('catch_tanks', $flat(iceChecklistSections('NASCC', 'caged')));
        $this->assertArrayNotHasKey('catch_tanks', $flat(iceChecklistSections('WSCC', 'caged')));
        $this->assertArrayNotHasKey('abs_disabled', $flat(iceChecklistSections('WSCC', 'caged')));
        foreach (iceChecklistSections('WSCC', 'caged') as $section) {
            $this->assertNotEmpty($section['items']);
        }
    }

    public function testUnknownGroupHasNoChecklist(): void
    {
        $this->assertSame([], iceChecklistSections('NASCC', 'bogus'));
    }

    public function testGearLevelSatisfiesGroup(): void
    {
        $this->assertTrue(iceGearSatisfies('caged', 'caged'));
        $this->assertTrue(iceGearSatisfies('caged', 'street_safe'));
        $this->assertTrue(iceGearSatisfies('caged', 'drift'));
        $this->assertTrue(iceGearSatisfies('street_safe', 'street_safe'));
        $this->assertTrue(iceGearSatisfies('street_safe', 'drift'));
        $this->assertFalse(iceGearSatisfies('street_safe', 'caged'));
        $this->assertFalse(iceGearSatisfies(null, 'drift'));
        $this->assertFalse(iceGearSatisfies('bogus', 'drift'));
    }

    public function testRulesVersionIsTwo(): void
    {
        $this->assertSame(2, ICE_RULES_VERSION);
    }

    public function testChecklistGapsFromPhaseOneAreFilled(): void
    {
        $flat = fn(array $sections): array => array_merge(...array_map(fn($s) => $s['items'], array_values($sections)));
        $drift = $flat(iceChecklistSections('WSCC', 'drift'));
        $this->assertArrayHasKey('windshield', $drift);
        $this->assertStringContainsString('terminal', $drift['battery']);
        $this->assertArrayHasKey('ballast', $flat(iceChecklistSections('NASCC', 'caged')));
        $this->assertArrayHasKey('ballast', $flat(iceChecklistSections('WSCC', 'caged')));
    }

    public function testHelmetNoteFollowsGroupClubAndFhr(): void
    {
        $this->assertSame('Helmet: Snell SA2020 or newer. A frontal head restraint is required for this class.',
            iceHelmetNote('NASCC', iceClass('NASCC', 'LS')));
        $this->assertSame('Helmet: Snell SA2020 or newer.', iceHelmetNote('NASCC', iceClass('NASCC', 'NS')));
        $this->assertSame('Helmet: Snell SA2015 or newer, or ECE 22.05 made 2015 or later.', iceHelmetNote('WSCC', iceClass('WSCC', 'FOI-STD')));
        $this->assertSame('Helmet: Snell M2015 or newer, or ECE 22.05/22.06.', iceHelmetNote('NASCC', iceClass('NASCC', 'SS')));
        $this->assertSame('Helmet: Snell M2015 or newer, or ECE 22.05/22.06.', iceHelmetNote('WSCC', iceClass('WSCC', 'DRIFT')));
        $this->assertSame('', iceHelmetNote('NASCC', null));
    }

    public function testEquipmentItemsRelaxRecommendedItemsAndFollowFhr(): void
    {
        $ss = iceEquipmentItems(iceClass('NASCC', 'SS'));
        $this->assertSame(array_keys(TECH_DRIVER_EQUIPMENT_ITEMS), array_keys($ss));
        foreach (['goggles_visor', 'socks', 'balaclava', 'underwear', 'head_neck_restraints'] as $k) {
            $this->assertTrue($ss[$k]['optional'], $k);
        }
        foreach (['helmet', 'suit', 'shoes', 'gloves'] as $k) {
            $this->assertFalse($ss[$k]['optional'], $k);
        }
        $this->assertSame('Suit or FR coveralls', $ss['suit']['label']);
        $this->assertFalse(iceEquipmentItems(iceClass('NASCC', 'LS'))['head_neck_restraints']['optional']);
        $this->assertTrue(iceEquipmentItems(null)['head_neck_restraints']['optional']);
    }

    public function testGearLevelForClass(): void
    {
        $this->assertSame('caged', iceGearLevelForClass('NASCC', 'LS'));
        $this->assertSame('street_safe', iceGearLevelForClass('NASCC', 'SS'));
        $this->assertSame('street_safe', iceGearLevelForClass('WSCC', 'DRIFT'));
        $this->assertNull(iceGearLevelForClass('WSCC', 'LS'));
        $this->assertSame('street-safe', ICE_GEAR_LEVEL_LABELS['street_safe']);
    }
}
