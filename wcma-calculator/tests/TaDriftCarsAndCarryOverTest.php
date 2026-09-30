<?php
// wcma-calculator/tests/TaDriftCarsAndCarryOverTest.php
require_once __DIR__ . '/../tech-status.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../garage-lib.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../readiness-lib.php';

use PHPUnit\Framework\TestCase;

final class TaDriftCarsAndCarryOverTest extends TestCase
{
    public function testTaDriftOnlyCarsRaceSummerOnly(): void
    {
        $this->assertContains('ta_drift', CAR_DISCIPLINES);
        $this->assertSame(['summer' => true, 'ice' => false], carSeasons('ta_drift', false, false));
        $this->assertSame(['summer' => true, 'ice' => true], carSeasons('ta_drift', false, true));   // activity is never hidden
        $this->assertSame(['summer' => true, 'ice' => true], carSeasons('both', false, false));
    }

    public function testCarFormAcceptsTaDriftOnly(): void
    {
        $form = ['car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'colour' => 'White', 'disciplines' => 'ta_drift'];
        $r = carsValidateDetails($form, true);
        $this->assertTrue($r['ok']);
        $this->assertSame('ta_drift', $r['data']['disciplines']);
    }

    public function testTaDriftSummerGearDoesNotCarryOverToIce(): void
    {
        $summer = fn(?string $level): array => ['id' => 3, 'season' => 2026, 'discipline' => 'summer', 'level' => $level,
            'status' => 'accepted', 'accepted_via' => 'in_person', 'photo_status' => null];

        $this->assertSame('accepted', gearIceSummary(null, $summer(null), 2027)['state']);
        $this->assertSame('none', gearIceSummary(null, $summer(GEAR_LEVEL_TA_DRIFT), 2027)['state']);

        $race = readinessIceGear(5, 'Sam', 2027, null, $summer(null), null, false, false, null);
        $this->assertSame('done', $race['state']);
        $tad = readinessIceGear(5, 'Sam', 2027, null, $summer(GEAR_LEVEL_TA_DRIFT), null, false, false, null);
        $this->assertSame('todo', $tad['state']);
        $this->assertStringNotContainsString('from summer', $tad['label']);
    }
}
