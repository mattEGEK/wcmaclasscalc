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
}
