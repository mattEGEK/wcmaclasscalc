<?php
// wcma-calculator/tests/PhotoRequirementsTest.php
require_once __DIR__ . '/../photo-requirements.php';

use PHPUnit\Framework\TestCase;

final class PhotoRequirementsTest extends TestCase
{
    private function keysByTier(string $scope, string $tier): array {
        return array_keys(array_filter(photoRequirements($scope), fn($r) => $r['tier'] === $tier));
    }

    public function testCarHasFifteenRequiredAndSixConditional(): void
    {
        $this->assertCount(15, $this->keysByTier('car', 'required'));
        $this->assertSame(
            ['seat_label', 'fuel_cell', 'windshield_clips', 'scattershield', 'ballast', 'aero'],
            $this->keysByTier('car', 'conditional')
        );
        $this->assertSame([], $this->keysByTier('car', 'recommended'));
    }

    public function testGearTiers(): void
    {
        $this->assertSame(
            ['helmet_label', 'suit_label', 'fhr_label', 'gear_flatlay'],
            $this->keysByTier('gear', 'required')
        );
        $this->assertSame(['helmet_back'], $this->keysByTier('gear', 'recommended'));
        $this->assertSame(['underwear_label'], $this->keysByTier('gear', 'conditional'));
    }

    public function testEveryEntryIsWellFormed(): void
    {
        $this->assertSame(1, PHOTO_REQUIREMENTS_VERSION);
        foreach (PHOTO_REQUIREMENTS as $key => $def) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $key);
            $this->assertContains($def['scope'], ['car', 'gear'], $key);
            $this->assertContains($def['tier'], ['required', 'conditional', 'recommended'], $key);
            $this->assertNotSame('', $def['label'], $key);
            $this->assertNotSame('', $def['guidance'], $key);
            $this->assertNotSame('', $def['reg'], $key);
            $names = [];
            foreach ($def['typed'] as $field) {
                $this->assertContains($field['type'], ['month_year', 'select', 'text'], $key);
                $this->assertNotContains($field['name'], $names, "$key duplicate field name");
                $names[] = $field['name'];
                if ($field['type'] === 'select') {
                    $this->assertNotEmpty($field['options'], $key);
                }
            }
        }
    }

    public function testNoApprovalWordingInCopy(): void
    {
        foreach (PHOTO_REQUIREMENTS as $key => $def) {
            foreach (['label', 'guidance'] as $field) {
                $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|safe)\b/i', $def[$field], "$key $field");
            }
        }
    }

    public function testLookupByKey(): void
    {
        $r = photoRequirementByKey('harness_date');
        $this->assertSame('harness_date', $r['key']);
        $this->assertSame('car', $r['scope']);
        $this->assertNull(photoRequirementByKey('nope'));
    }

    public function testTypedValueValidation(): void
    {
        $helmet = photoRequirementByKey('helmet_label');
        $this->assertSame(
            ['standard' => 'SA2020', 'date' => '03/2024'],
            photoValidateTypedValue($helmet, ['standard' => ' SA2020 ', 'date' => '03/2024'])
        );
        $this->assertSame(['standard' => 'SA2020'], photoValidateTypedValue($helmet, ['standard' => 'SA2020', 'date' => '']));
        $this->assertSame([], photoValidateTypedValue($helmet, []));
        $this->assertNull(photoValidateTypedValue($helmet, ['standard' => 'Snell 1995']));
        $this->assertNull(photoValidateTypedValue($helmet, ['date' => '13/2024']));
        $this->assertNull(photoValidateTypedValue($helmet, ['date' => '3/2024']));
        $this->assertNull(photoValidateTypedValue($helmet, ['colour' => 'red']));
        $this->assertNull(photoValidateTypedValue($helmet, ['standard' => ['SA2020']]));

        $suit = photoRequirementByKey('suit_label');
        $this->assertNull(photoValidateTypedValue($suit, ['rating' => str_repeat('x', 101)]));
        $this->assertSame(['rating' => 'SFI 3.2A/5'], photoValidateTypedValue($suit, ['rating' => 'SFI 3.2A/5']));
    }

    public function testMissingRequiredCountsConditionalOnlyWhenApplicable(): void
    {
        $required = array_keys(array_filter(photoRequirements('gear'), fn($r) => $r['tier'] === 'required'));

        $this->assertSame($required, photoSetMissingRequired('gear', [], []));

        $present = ['helmet_label', 'suit_label'];
        $this->assertSame(['fhr_label', 'gear_flatlay'], photoSetMissingRequired('gear', $present, []));

        $allRequired = $required;
        $this->assertSame([], photoSetMissingRequired('gear', $allRequired, []));
        $this->assertSame(['underwear_label'], photoSetMissingRequired('gear', $allRequired, ['underwear_label']));
        $this->assertSame([], photoSetMissingRequired('gear', array_merge($allRequired, ['underwear_label']), ['underwear_label']));
        $this->assertSame([], photoSetMissingRequired('gear', $allRequired, ['helmet_back']));
    }
}
