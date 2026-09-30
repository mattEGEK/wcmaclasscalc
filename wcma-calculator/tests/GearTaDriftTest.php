<?php
// wcma-calculator/tests/GearTaDriftTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../gear-chips.php';

use PHPUnit\Framework\TestCase;

final class GearTaDriftTest extends TestCase
{
    private function user(PDO $pdo): int {
        return db_create_user($pdo, ['email' => 'gt' . uniqid() . '@example.com', 'name' => 'Pat Winters', 'password_hash' => 'x', 'google_id' => null]);
    }

    /** A current-season TA/Drift sheet for a new car of $u at a WSCC event. */
    private function taSheet(PDO $pdo, int $u, bool $caged = false): array {
        $event = db_create_event($pdo, 'WSCC TA', gearSeasonNow() . '-07-12', null, 'summer', 'WSCC');
        return db_get_tech_sheet($pdo, test_make_ta_drift_sheet($pdo, $u, test_make_car($pdo, $u, '86'), $event, $caged));
    }

    private function addRequiredPhotos(PDO $pdo, int $id): void {
        foreach (photoRequirementsFor(db_get_gear_record($pdo, $id), 'gear') as $key => $def) {
            if ($def['tier'] !== 'required') continue;
            db_upsert_inspection_photo($pdo, ['subject_type' => 'gear_record', 'subject_id' => $id, 'requirement_key' => $key,
                'requirement_version' => 1, 'file_path' => 'uploads/x.jpg', 'typed_value' => null]);
        }
        db_mark_gear_photos_draft($pdo, $id);
    }

    public function testLevelChoices(): void
    {
        $this->assertNull(gearSummerLevelStored(null));
        $this->assertNull(gearSummerLevelStored(''));
        $this->assertNull(gearSummerLevelStored('race'));
        $this->assertSame('ta_drift', gearSummerLevelStored('ta_drift'));
        $this->assertFalse(gearSummerLevelStored('caged'));
        $this->assertSame('Race', gearSummerLevelLabel(null));
        $this->assertSame('TA/Drift', gearSummerLevelLabel('ta_drift'));
    }

    public function testAcceptInPersonAtEitherLevel(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $a = (int)gearCreate($pdo, $u, 'Pat Winters', '', 2026)['id'];
        $b = (int)gearCreate($pdo, $u, 'Sam Patel', '', 2026)['id'];

        $this->assertSame('Choose the gear level: Race or TA/Drift.', gearAcceptInPerson($pdo, $a, $u, 'caged')['error']);
        $this->assertTrue(gearAcceptInPerson($pdo, $a, $u, 'ta_drift')['ok']);
        $this->assertSame('ta_drift', db_get_gear_record($pdo, $a)['level']);
        $this->assertTrue(gearAcceptInPerson($pdo, $b, $u, 'race')['ok']);
        $this->assertNull(db_get_gear_record($pdo, $b)['level']);
    }

    public function testStartFromATaDriftSheetUsesTheTaDriftPhotoList(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $sheet = $this->taSheet($pdo, $u, true);
        $r = gearStartTaDriftForSheet($pdo, $sheet, $u);
        $this->assertTrue($r['ok'], (string)$r['error']);
        $gear = db_get_gear_record($pdo, (int)$r['id']);
        $this->assertSame('summer', $gear['discipline']);
        $this->assertSame('ta_drift', $gear['photo_tier']);
        $this->assertSame(1, (int)$gear['caged']);
        $this->assertSame(['tad_helmet_label', 'tad_fhr_label'], array_keys(photoRequirementsFor($gear, 'gear')));

        $this->assertSame((int)$r['id'], gearStartTaDriftForSheet($pdo, $sheet, $u)['id']);   // same record again
        $this->assertFalse(gearStartTaDriftForSheet($pdo, $sheet, $u + 1)['ok']);
        $this->assertFalse(gearStartTaDriftForSheet($pdo, $sheet, $u, 2)['ok']);          // no driver 2 on the sheet
    }

    public function testAnExistingRecordKeepsItsListOnceItHasPhotos(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $sheet = $this->taSheet($pdo, $u);
        $id = (int)gearCreate($pdo, $u, 'Test Driver', '', gearSeasonNow())['id'];   // the sheet's driver 1
        $this->addRequiredPhotos($pdo, $id);                                           // race list photos, now draft
        $this->assertSame($id, gearStartTaDriftForSheet($pdo, $sheet, $u)['id']);
        $this->assertNull(db_get_gear_record($pdo, $id)['photo_tier']);
    }

    public function testTaDriftPhotosCannotBeAcceptedAtRace(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $id = (int)gearStartTaDriftForSheet($pdo, $this->taSheet($pdo, $u), $u)['id'];
        $this->addRequiredPhotos($pdo, $id);
        $this->assertTrue(gearSubmit($pdo, $id)['ok']);

        $this->assertSame('These photos only cover TA/Drift gear: accept them at TA/Drift.', gearAcceptByPhotos($pdo, $id, $u, 'race')['error']);
        $this->assertSame('open', db_get_gear_record($pdo, $id)['status']);
        $this->assertTrue(gearAcceptByPhotos($pdo, $id, $u, 'ta_drift')['ok']);
        $this->assertSame('ta_drift', db_get_gear_record($pdo, $id)['level']);
        $this->assertTrue(gearCoversTier(db_get_gear_record($pdo, $id), TECH_TIER_TA_DRIFT));
        $this->assertFalse(gearCoversTier(db_get_gear_record($pdo, $id), TECH_TIER_RACE));
    }

    public function testRacePhotosCanBeAcceptedAtEitherLevel(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $id = (int)gearCreate($pdo, $u, 'Pat Winters', '', 2026)['id'];
        $this->addRequiredPhotos($pdo, $id);
        gearSubmit($pdo, $id);
        $this->assertTrue(gearAcceptByPhotos($pdo, $id, $u, 'ta_drift')['ok']);
        $this->assertSame('ta_drift', db_get_gear_record($pdo, $id)['level']);
    }

    public function testSheetLinksCarryTheTier(): void
    {
        $gear = [];
        $links = gearLinksForSheet(['user_id' => 4, 'season' => 2026, 'driver_name' => 'Pat', 'discipline' => 'summer', 'sheet_type' => 'ta_drift', 'club' => 'WSCC', 'caged' => 1], [], $gear);
        $this->assertSame('ta_drift', $links[0]['tier']);
        $this->assertSame('ta_drift', $links[0]['default_level']);
        $this->assertTrue($links[0]['sheet_caged']);
        $race = gearLinksForSheet(['user_id' => 4, 'season' => 2026, 'driver_name' => 'Pat'], [], $gear);
        $this->assertSame('race', $race[0]['tier']);
        $this->assertNull($race[0]['default_level']);
    }

    public function testChips(): void
    {
        $none = ['driver_number' => 2, 'name' => 'Sam', 'name_norm' => 'sam', 'gear' => null, 'status' => ['state' => 'none', 'via' => null],
                 'discipline' => 'summer', 'tier' => 'ta_drift', 'default_level' => 'ta_drift'];
        $owner = renderGearChips([$none], 'owner', ['sheet_id' => 9, 'sheet_season' => gearSeasonNow()]);
        $this->assertStringContainsString('href="gear.php?action=start-ta-drift&amp;sheet_id=9&amp;driver=2"', $owner);

        $admin = renderGearChips([$none], 'admin', ['csrf' => 'tok', 'sheet_id' => 9, 'sheet_season' => gearSeasonNow()]);
        $this->assertStringContainsString('<option value="ta_drift" selected>TA/Drift</option>', $admin);
        $this->assertStringContainsString('<option value="race">Race</option>', $admin);

        $accepted = ['gear' => ['id' => 4, 'season' => 2026, 'discipline' => 'summer', 'level' => 'ta_drift', 'status' => 'accepted', 'accepted_via' => 'in_person', 'photo_status' => null],
                     'status' => ['state' => 'accepted', 'via' => 'in_person']] + $none;
        $this->assertStringContainsString('Gear teched 2026 · TA/Drift', renderGearChips([$accepted], 'owner'));

        $fresh = ['gear' => ['id' => 5, 'season' => gearSeasonNow(), 'discipline' => 'summer', 'level' => null, 'status' => 'open', 'accepted_via' => null, 'photo_status' => null, 'photo_tier' => null]] + $none;
        $this->assertStringContainsString('href="gear.php?action=start-ta-drift&amp;sheet_id=9&amp;driver=2"', renderGearChips([$fresh], 'owner', ['sheet_id' => 9]));
    }

    public function testAdminAndEmailShowTheLevel(): void
    {
        $src = file_get_contents(__DIR__ . '/../admin-gear.php');
        $this->assertStringContainsString('GEAR_SUMMER_LEVEL_LABELS', $src);
        $this->assertStringContainsString("(\$gear['photo_tier'] ?? null) === GEAR_LEVEL_TA_DRIFT", $src);
        $this->assertStringContainsString("GEAR_LEVEL_TA_DRIFT ? 'Gear level: TA/Drift.'", file_get_contents(__DIR__ . '/../gear-email.php'));
    }
}
