<?php
// wcma-calculator/tests/TaDriftLibTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../ta-drift-lib.php';

final class TaDriftLibTest extends TestCase
{
    public function testParseAndStoreUseCanonicalOrder(): void
    {
        $this->assertSame(['race'], entryFormatsParse(null));
        $this->assertSame(['race'], entryFormatsParse(''));
        $this->assertSame(['race'], entryFormatsParse('rally'));
        $this->assertSame(['ta', 'drift'], entryFormatsParse('drift, ta'));
        $this->assertSame('race,ta,drift', entryFormatsStore(['drift', 'race', 'ta']));
        $this->assertSame('Time Attack · Drift', entryFormatsLabel(['drift', 'ta']));
    }

    public function testTierIsTheStrictestFormat(): void
    {
        $cases = [
            'race' => TECH_TIER_RACE, 'ta' => TECH_TIER_TA_DRIFT, 'drift' => TECH_TIER_TA_DRIFT,
            'race,ta' => TECH_TIER_RACE, 'race,drift' => TECH_TIER_RACE, 'ta,drift' => TECH_TIER_TA_DRIFT,
            'race,ta,drift' => TECH_TIER_RACE,
        ];
        foreach ($cases as $stored => $tier) $this->assertSame($tier, entryTechTier(entryFormatsParse($stored)), $stored);
    }

    public function testValidate(): void
    {
        $summer = ['discipline' => 'summer', 'host_club' => 'WSCC'];
        $noClub = ['discipline' => 'summer', 'host_club' => null];
        $ice = ['discipline' => 'ice', 'host_club' => 'NASCC'];

        $this->assertSame(['ok' => true, 'formats' => ['ta', 'drift'], 'error' => null], entryFormatsValidate($summer, ['drift', 'ta']));
        $this->assertSame(ENTRY_FORMAT_ERROR, entryFormatsValidate($summer, [])['error']);
        $this->assertSame(ENTRY_FORMAT_ERROR, entryFormatsValidate($summer, ['ta', 'rally'])['error']);
        $this->assertSame(ENTRY_FORMAT_ERROR, entryFormatsValidate($summer, 'ta')['error']);
        $this->assertSame(ENTRY_NO_HOST_CLUB, entryFormatsValidate($noClub, ['race', 'ta'])['error']);
        $this->assertTrue(entryFormatsValidate($noClub, ['race'])['ok']);
        $this->assertSame(['ok' => true, 'formats' => ['race'], 'error' => null], entryFormatsValidate($ice, ['drift']));
    }

    public function testSheetIsTaDrift(): void
    {
        $this->assertTrue(techSheetIsTaDrift(['sheet_type' => 'ta_drift']));
        $this->assertFalse(techSheetIsTaDrift(['sheet_type' => 'standard']));
        $this->assertFalse(techSheetIsTaDrift([]));
    }

    public function testRaceAcceptanceCoversTaDrift(): void
    {
        $accepted = ['state' => 'accepted', 'via' => 'in_person', 'sheet_id' => 3];
        $pending = ['state' => 'pending_review', 'via' => null, 'sheet_id' => 9];
        $none = ['state' => 'none', 'via' => null, 'sheet_id' => null];

        $this->assertSame($accepted + ['tier' => TECH_TIER_RACE], taDriftCarTechStatus($accepted, $pending));
        $this->assertSame(['state' => 'accepted', 'via' => 'photos', 'sheet_id' => 9, 'tier' => TECH_TIER_TA_DRIFT],
            taDriftCarTechStatus($none, ['state' => 'accepted', 'via' => 'photos', 'sheet_id' => 9]));
        $this->assertSame($pending + ['tier' => TECH_TIER_TA_DRIFT], taDriftCarTechStatus($none, $pending));
        $this->assertSame($none + ['tier' => TECH_TIER_TA_DRIFT], taDriftCarTechStatus($pending, $none));
    }

    public function testGearCoversTier(): void
    {
        $race = ['status' => 'accepted', 'level' => null];
        $tad = ['status' => 'accepted', 'level' => GEAR_LEVEL_TA_DRIFT];
        $open = ['status' => 'open', 'level' => null];

        $this->assertTrue(gearCoversTier($race, TECH_TIER_RACE));
        $this->assertTrue(gearCoversTier($race, TECH_TIER_TA_DRIFT));
        $this->assertFalse(gearCoversTier($tad, TECH_TIER_RACE));
        $this->assertTrue(gearCoversTier($tad, TECH_TIER_TA_DRIFT));
        $this->assertFalse(gearCoversTier($open, TECH_TIER_TA_DRIFT));
        $this->assertFalse(gearCoversTier(null, TECH_TIER_TA_DRIFT));
        $this->assertTrue(gearCoversTier(['status' => 'accepted'], TECH_TIER_RACE));   // rows without a level column
    }
}
