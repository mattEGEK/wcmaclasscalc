<?php
// wcma-calculator/tests/IcePhotoRequirementsTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../photo-requirements.php';

final class IcePhotoRequirementsTest extends TestCase
{
    private function iceSheet(string $club, string $class): array {
        return ['discipline' => 'ice', 'club' => $club, 'class' => $class];
    }

    public function testEveryIceKeyIsPrefixedAndWellFormed(): void
    {
        foreach (ICE_PHOTO_REQUIREMENTS as $key => $def) {
            $this->assertStringStartsWith('ice_', $key);
            $this->assertContains($def['scope'], ['car', 'gear'], $key);
            $this->assertContains($def['tier'], ['required', 'conditional', 'recommended'], $key);
            $this->assertNotSame('', $def['label'], $key);
            $this->assertNotSame('', $def['guidance'], $key);
            if ($def['scope'] === 'car') {
                $this->assertNotEmpty($def['groups'], $key);
                foreach ($def['groups'] as $g) $this->assertContains($g, ICE_CLASS_GROUPS, $key);
            }
            foreach (array_keys($def['club_guidance'] ?? []) as $club) $this->assertContains($club, iceClubCodes(), $key);
        }
        $this->assertSame([], array_intersect_key(ICE_PHOTO_REQUIREMENTS, PHOTO_REQUIREMENTS));
    }

    public function testByKeyFindsSummerAndIceKeys(): void
    {
        $this->assertSame(PHOTO_REQUIREMENTS['front_34'] + ['key' => 'front_34'], photoRequirementByKey('front_34'));
        $this->assertSame('ice_cage', photoRequirementByKey('ice_cage')['key']);
        $this->assertNull(photoRequirementByKey('nope'));
    }

    public function testSummerAndEmptySubjectsGetTheSummerList(): void
    {
        $this->assertSame(array_keys(photoRequirements('car')), array_keys(photoRequirementsFor([], 'car')));
        $this->assertSame(array_keys(photoRequirements('gear')), array_keys(photoRequirementsFor(['discipline' => 'summer'], 'gear')));
        $this->assertSame(PHOTO_REQUIREMENTS_VERSION, photoRequirementsFor([], 'car')['front_34']['version']);
    }

    public function testIceCarListFollowsTheClassGroup(): void
    {
        $drift = array_keys(photoRequirementsFor($this->iceSheet('WSCC', 'DRIFT'), 'car'));
        $ss = array_keys(photoRequirementsFor($this->iceSheet('NASCC', 'SS'), 'car'));
        $caged = array_keys(photoRequirementsFor($this->iceSheet('NASCC', 'LS'), 'car'));
        $this->assertSame(['ice_front_34', 'ice_rear_34', 'ice_tires'], $drift);
        $this->assertContains('ice_airbags', $ss);
        $this->assertNotContains('ice_cage', $ss);
        $this->assertContains('ice_cage', $caged);
        $this->assertContains('ice_mud_flaps', $caged);
        $this->assertNotContains('ice_airbags', $caged);
        $this->assertSame([], photoRequirementsFor($this->iceSheet('NASCC', 'XX'), 'car'));
        $this->assertSame(ICE_PHOTO_REQUIREMENTS_VERSION, photoRequirementsFor($this->iceSheet('NASCC', 'LS'), 'car')['ice_cage']['version']);
    }

    public function testClubGuidanceIsApplied(): void
    {
        $n = photoRequirementsFor($this->iceSheet('NASCC', 'SS'), 'car');
        $w = photoRequirementsFor($this->iceSheet('WSCC', 'FOI-SS'), 'car');
        $this->assertStringContainsString('removed, not just disabled', $n['ice_airbags']['guidance']);
        $this->assertStringContainsString('removed or disabled', $w['ice_airbags']['guidance']);
        $this->assertStringContainsString('all 4', $n['ice_brake_lights']['guidance']);
        $this->assertStringContainsString('all 3', $w['ice_brake_lights']['guidance']);
        $this->assertArrayNotHasKey('club_guidance', $n['ice_airbags']);
    }

    public function testIceGearList(): void
    {
        $gear = photoRequirementsFor(['discipline' => 'ice'], 'gear');
        $this->assertSame(['ice_helmet_label', 'ice_suit_label', 'ice_gloves_shoes', 'ice_fhr_label'], array_keys($gear));
        $this->assertSame('conditional', $gear['ice_fhr_label']['tier']);
        $this->assertSame(ICE_HELMET_STANDARDS, $gear['ice_helmet_label']['typed'][0]['options']);
    }

    public function testForSubjectOnlyReturnsKeysOnThatList(): void
    {
        $ss = $this->iceSheet('NASCC', 'SS');
        $this->assertSame('ice_airbags', photoRequirementForSubject($ss, 'car', 'ice_airbags')['key']);
        $this->assertNull(photoRequirementForSubject($ss, 'car', 'ice_cage'));
        $this->assertNull(photoRequirementForSubject($ss, 'car', 'front_34'));
        $this->assertNull(photoRequirementForSubject([], 'car', 'ice_front_34'));
        $this->assertNull(photoRequirementForSubject(['discipline' => 'ice'], 'car', 'ice_helmet_label'));
    }

    public function testMissingFromAList(): void
    {
        $reqs = photoRequirementsFor(['discipline' => 'ice'], 'gear');
        $this->assertSame(['ice_helmet_label', 'ice_suit_label', 'ice_gloves_shoes'], photoSetMissingFrom($reqs, [], []));
        $this->assertSame(['ice_fhr_label'], photoSetMissingFrom($reqs, ['ice_helmet_label', 'ice_suit_label', 'ice_gloves_shoes'], ['ice_fhr_label']));
        // The summer helper still behaves as before.
        $this->assertSame(photoSetMissingFrom(photoRequirements('gear'), [], []), photoSetMissingRequired('gear', [], []));
    }

    public function testHelmetStandardSuggestsALevel(): void
    {
        $this->assertSame('caged', iceGearLevelForHelmet('Snell SA2020'));
        $this->assertSame('caged', iceGearLevelForHelmet('FIA 8860-2018'));
        $this->assertSame('street_safe', iceGearLevelForHelmet('Snell M2015'));
        $this->assertSame('street_safe', iceGearLevelForHelmet('ECE 22.06'));
        $this->assertNull(iceGearLevelForHelmet(''));
        $this->assertNull(iceGearLevelForHelmet('Bell Bike'));
        foreach (ICE_HELMET_STANDARDS as $s) $this->assertNotNull(iceGearLevelForHelmet($s), $s);
    }
}
