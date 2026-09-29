<?php
// wcma-calculator/tests/TaDriftPhotoRequirementsTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../photo-requirements.php';

final class TaDriftPhotoRequirementsTest extends TestCase
{
    public function testEveryKeyIsPrefixedAndWellFormed(): void
    {
        foreach (TA_DRIFT_PHOTO_REQUIREMENTS as $key => $def) {
            $this->assertStringStartsWith('tad_', $key);
            $this->assertContains($def['scope'], ['car', 'gear'], $key);
            $this->assertSame('required', $def['tier'], $key);
            $this->assertNotSame('', $def['label'], $key);
            $this->assertNotSame('', $def['guidance'], $key);
        }
    }

    public function testCarShotsForATaDriftSheet(): void
    {
        $sheet = ['discipline' => 'summer', 'sheet_type' => 'ta_drift', 'club' => 'WSCC', 'caged' => 0];
        $base = ['tad_front_34', 'tad_rear_34', 'tad_interior', 'tad_battery', 'tad_tow_front', 'tad_tow_rear'];
        $this->assertSame($base, array_keys(photoRequirementsFor($sheet, 'car')));
        $this->assertSame([...$base, 'tad_cage'], array_keys(photoRequirementsFor(['caged' => 1] + $sheet, 'car')));
        $this->assertSame(TA_DRIFT_PHOTO_REQUIREMENTS_VERSION, photoRequirementsFor($sheet, 'car')['tad_front_34']['version']);
    }

    public function testGearShotsOnlyWhenTheCallerAsksForTheTaDriftList(): void
    {
        $gear = ['discipline' => 'summer', 'season' => 2026];
        $this->assertSame(array_keys(photoRequirements('gear')), array_keys(photoRequirementsFor($gear, 'gear')));
        $this->assertSame(['tad_helmet_label'], array_keys(photoRequirementsFor($gear + ['photo_tier' => 'ta_drift'], 'gear')));
        $this->assertSame(['tad_helmet_label', 'tad_fhr_label'],
            array_keys(photoRequirementsFor($gear + ['photo_tier' => 'ta_drift', 'caged' => true], 'gear')));
    }

    public function testRaceAndIceListsAreUnchanged(): void
    {
        $this->assertSame(array_keys(photoRequirements('car')), array_keys(photoRequirementsFor(['discipline' => 'summer', 'sheet_type' => 'standard'], 'car')));
        $this->assertSame([], array_filter(array_keys(photoRequirementsFor(['discipline' => 'ice', 'club' => 'WSCC', 'class' => 'DRIFT', 'sheet_type' => 'ta_drift'], 'car')),
            fn(string $k): bool => str_starts_with($k, 'tad_')));
    }

    public function testLookupByKey(): void
    {
        $this->assertSame('tad_helmet_label', photoRequirementByKey('tad_helmet_label')['key']);
        $this->assertSame('front_34', photoRequirementByKey('front_34')['key']);
    }
}
