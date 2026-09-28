<?php
// wcma-calculator/tests/DriversLibTest.php
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../drivers-lib.php';

use PHPUnit\Framework\TestCase;

final class DriversLibTest extends TestCase
{
    private function user(PDO $pdo, string $email = 'j@example.com', string $name = 'Jordan Lee'): int {
        return db_create_user($pdo, ['email' => $email, 'name' => $name, 'password_hash' => 'x', 'google_id' => null]);
    }

    public function testGearLabelSaysNeedsGearTechUntilThereIsActivity(): void
    {
        $this->assertSame('Needs gear tech 2027', driversGearLabel(['state' => 'none', 'via' => null], 2027));
        $this->assertSame('Gear pre-teched 2027', driversGearLabel(['state' => 'accepted', 'via' => 'photos'], 2027));
        $this->assertSame('Photos need changes', driversGearLabel(['state' => 'needs_changes', 'via' => null], 2027));
    }

    public function testGearActionAlwaysOpensThisSeasonsPhotos(): void
    {
        $this->assertSame(['label' => 'Add photos', 'url' => 'gear.php?action=start&driver_id=5'], driversGearAction(5, ['state' => 'none', 'via' => null]));
        $this->assertSame('Continue photos', driversGearAction(5, ['state' => 'photos_draft', 'via' => null])['label']);
        $this->assertSame('Retake photos', driversGearAction(5, ['state' => 'needs_changes', 'via' => null])['label']);
        $this->assertSame('View photos', driversGearAction(5, ['state' => 'pending_review', 'via' => null])['label']);
        $this->assertSame('View gear', driversGearAction(5, ['state' => 'accepted', 'via' => 'in_person'])['label']);
    }

    public function testRowsMarkSelfAndUseTheGivenSeasonsRecord(): void
    {
        $drivers = [['id' => 1, 'name' => 'Jordan Lee'], ['id' => 2, 'name' => 'Sam Patel']];
        $rows = driversRows($drivers, [2 => ['status' => 'accepted', 'accepted_via' => 'photos', 'photo_status' => 'accepted']], 1, 2026);
        $this->assertTrue($rows[0]['isSelf']);
        $this->assertFalse($rows[1]['isSelf']);
        $this->assertSame('Needs gear tech 2026', $rows[0]['label']);
        $this->assertSame('accepted', $rows[1]['state']);
        $this->assertSame('Gear pre-teched 2026', $rows[1]['label']);
    }

    public function testRowsCarryIceGearWithTheRightAction(): void
    {
        $drivers = [['id' => 5, 'name' => 'Jordan'], ['id' => 6, 'name' => 'Sam'], ['id' => 7, 'name' => 'Alex']];
        $rows = driversRows($drivers, [], 5, 2026, [], [
            5 => ['state' => 'accepted', 'label' => 'Ice 2027: from summer 2026', 'gearId' => null, 'sheetId' => 9],
            6 => ['state' => 'none', 'label' => 'Needs ice gear check 2027', 'gearId' => null, 'sheetId' => 9],
            7 => ['state' => 'photos_draft', 'label' => 'Photos in progress', 'gearId' => 12, 'sheetId' => null],
        ]);
        $this->assertNull($rows[0]['ice']['action']);
        $this->assertSame(['label' => 'Add ice gear photos', 'url' => 'gear.php?action=start-ice&sheet_id=9'], $rows[1]['ice']['action']);
        $this->assertSame(['label' => 'View ice gear', 'url' => 'gear.php?action=pretech&id=12'], $rows[2]['ice']['action']);
        $this->assertArrayNotHasKey('ice', driversRows($drivers, [], 5, 2026)[0]);
    }

    public function testIceSheetIdsByDriverIncludeAddedDriversNotOnlyThePrimary(): void
    {
        $sheets = [
            ['id' => 9, 'season' => 2027, 'discipline' => 'ice', 'driver_id' => 5],
            ['id' => 10, 'season' => 2027, 'discipline' => 'ice', 'driver_id' => 5],
            ['id' => 11, 'season' => 2026, 'discipline' => 'ice', 'driver_id' => 6],   // wrong season: ignored
            ['id' => 12, 'season' => 2027, 'discipline' => 'summer', 'driver_id' => 7],   // not ice: ignored
        ];
        // Driver 6 is an added driver (tech_sheet_drivers) on sheet 9 only, not on the newer sheet 10.
        $sheetDrivers = [9 => [6]];
        $byDriver = driversIceSheetIdsByDriver($sheets, $sheetDrivers, 2027);
        $this->assertSame(10, $byDriver[5]);   // primary driver: newest of their two sheets
        $this->assertSame(9, $byDriver[6]);    // added driver: the one sheet naming them, not sheet 10
        $this->assertArrayNotHasKey(7, $byDriver);
    }

    public function testAddCreatesACoDriverAndRejectsBlankLongAndDuplicateNames(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $r = driversAdd($pdo, $u, '  Sam   Patel ', ' L-12 ');
        $this->assertTrue($r['ok']);
        $d = db_get_driver($pdo, $r['id']);
        $this->assertSame(['Sam Patel', 'L-12', null], [$d['name'], $d['licence_no'], $d['user_id']]);

        $this->assertSame('Sam Patel is already on your Drivers page.', driversAdd($pdo, $u, 'sam  patel', '')['error']);
        $this->assertSame("Enter the driver's name.", driversAdd($pdo, $u, '  ', '')['error']);
        $this->assertFalse(driversAdd($pdo, $u, str_repeat('a', 101), '')['ok']);
        $this->assertFalse(driversAdd($pdo, $u, 'Alex Kim', str_repeat('1', 41))['ok']);
        $this->assertFalse(driversAdd($pdo, $u, 'Jordan Lee', '')['ok']);            // the self profile made with the account
    }

    public function testLicenceCanBeSetAndCleared(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $self = db_get_self_driver($pdo, $u);
        $this->assertTrue(driversSetLicence($pdo, $u, (int)$self['id'], ' 2026-0412 ')['ok']);
        $this->assertSame('2026-0412', db_get_driver($pdo, (int)$self['id'])['licence_no']);
        $this->assertTrue(driversSetLicence($pdo, $u, (int)$self['id'], '')['ok']);
        $this->assertNull(db_get_driver($pdo, (int)$self['id'])['licence_no']);
        $this->assertFalse(driversSetLicence($pdo, $u, (int)$self['id'], str_repeat('9', 41))['ok']);
    }

    public function testLicenceCannotBeSetOnSomeoneElsesDriver(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $other = db_get_self_driver($pdo, $this->user($pdo, 'o@example.com', 'Olly Other'));
        $this->assertSame(['ok' => false, 'error' => 'Driver not found.'], driversSetLicence($pdo, $u, (int)$other['id'], 'X'));
        $this->assertNull(db_get_driver($pdo, (int)$other['id'])['licence_no']);
    }
}
