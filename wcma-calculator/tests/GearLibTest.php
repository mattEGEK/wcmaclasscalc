<?php
// wcma-calculator/tests/GearLibTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../gear-lib.php';

use PHPUnit\Framework\TestCase;

final class GearLibTest extends TestCase
{
    private function users(PDO $pdo): array {
        $owner = db_create_user($pdo, ['email' => 'captain@example.com', 'name' => 'Captain', 'password_hash' => 'x', 'google_id' => null]);
        $admin = db_create_user($pdo, ['email' => 'tech@example.com', 'name' => 'Tech', 'password_hash' => 'x', 'google_id' => null]);
        return [$owner, $admin];
    }

    private function newGear(PDO $pdo, int $owner, string $name = 'Jane Racer', int $season = 2026): int {
        $r = gearCreate($pdo, $owner, $name, '', $season);
        $this->assertTrue($r['ok'], (string)$r['error']);
        return $r['id'];
    }

    private function addPhoto(PDO $pdo, int $id, string $key, string $path = 'uploads/x.jpg'): void {
        db_upsert_inspection_photo($pdo, [
            'subject_type' => 'gear_record', 'subject_id' => $id, 'requirement_key' => $key,
            'requirement_version' => 1, 'file_path' => $path, 'typed_value' => null,
        ]);
        db_mark_gear_photos_draft($pdo, $id);
    }

    private function addRequired(PDO $pdo, int $id): void {
        foreach (photoRequirements('gear') as $key => $def) {
            if ($def['tier'] === 'required') $this->addPhoto($pdo, $id, $key);
        }
    }

    public function testNameNormalisation(): void
    {
        $this->assertSame('jane racer', gearNameNorm("  Jane   RACER \t"));
        $this->assertSame('', gearNameNorm('   '));
    }

    public function testStatusPrecedenceLabelsAndBadges(): void
    {
        $this->assertSame(['state' => 'none', 'via' => null], gearStatus(['status' => 'open', 'photo_status' => null]));
        $this->assertSame('photos_draft', gearStatus(['status' => 'open', 'photo_status' => 'draft'])['state']);
        $this->assertSame('pending_review', gearStatus(['status' => 'open', 'photo_status' => 'submitted'])['state']);
        $this->assertSame('pending_review', gearStatus(['status' => 'open', 'photo_status' => 'accepted'])['state']);
        $this->assertSame('needs_changes', gearStatus(['status' => 'open', 'photo_status' => 'needs_changes'])['state']);
        $this->assertSame(['state' => 'accepted', 'via' => 'photos'], gearStatus(['status' => 'accepted', 'accepted_via' => 'photos', 'photo_status' => 'accepted']));
        $this->assertSame('in_person', gearStatus(['status' => 'accepted', 'accepted_via' => null])['via']);
        $this->assertSame('accepted', gearStatus(['status' => 'accepted', 'photo_status' => 'needs_changes'])['state']);

        $this->assertSame('Gear teched 2026', gearStatusLabel(['state' => 'accepted', 'via' => 'in_person'], 2026));
        $this->assertSame('Gear pre-teched 2026', gearStatusLabel(['state' => 'accepted', 'via' => 'photos'], 2026));
        $this->assertSame('Needs gear check at the track', gearStatusLabel(['state' => 'none', 'via' => null], 2026));
        $this->assertSame('Photos pending review', gearStatusLabel(['state' => 'pending_review', 'via' => null], 2026));
        $this->assertSame('Photos need changes', gearStatusLabel(['state' => 'needs_changes', 'via' => null], 2026));
        $this->assertSame('Photos in progress', gearStatusLabel(['state' => 'photos_draft', 'via' => null], 2026));
        $this->assertSame('badge-ok', gearStatusBadgeClass('accepted'));
        $this->assertSame('badge-fail', gearStatusBadgeClass('needs_changes'));
        $this->assertSame('badge-pending', gearStatusBadgeClass('none'));
    }

    public function testNoBannedWordingInLabels(): void
    {
        foreach (['accepted', 'needs_changes', 'pending_review', 'photos_draft', 'none'] as $state) {
            foreach (['in_person', 'photos'] as $via) {
                $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', gearStatusLabel(['state' => $state, 'via' => $via], 2026));
            }
        }
    }

    public function testAccessShapeMapsOwnerAndLockState(): void
    {
        $this->assertSame(['user_id' => 5, 'status' => 'submitted', 'photo_status' => 'draft'],
            gearAccessShape(['owner_user_id' => '5', 'status' => 'open', 'photo_status' => 'draft']));
        $this->assertSame('teched', gearAccessShape(['owner_user_id' => 5, 'status' => 'accepted', 'photo_status' => null])['status']);

        $owner = ['id' => 5, 'role' => 'user'];
        $this->assertTrue(inspectionCanAccess($owner, gearAccessShape(['owner_user_id' => 5, 'status' => 'open', 'photo_status' => 'draft']), true));
        $this->assertFalse(inspectionCanAccess($owner, gearAccessShape(['owner_user_id' => 5, 'status' => 'open', 'photo_status' => 'submitted']), true));
        $this->assertFalse(inspectionCanAccess($owner, gearAccessShape(['owner_user_id' => 5, 'status' => 'accepted', 'photo_status' => null]), true));
        $this->assertTrue(inspectionCanAccess($owner, gearAccessShape(['owner_user_id' => 5, 'status' => 'accepted', 'photo_status' => null]), false));
        $this->assertFalse(inspectionCanAccess(['id' => 6, 'role' => 'user'], gearAccessShape(['owner_user_id' => 5, 'status' => 'open']), false));
    }

    public function testRequiredTotalForGearRequirements(): void
    {
        $reqs = photoRequirements('gear');
        $this->assertSame(4, gearRequiredTotal($reqs, []));
        $this->assertSame(5, gearRequiredTotal($reqs, ['underwear_label']));
        $this->assertSame(4, gearRequiredTotal($reqs, ['helmet_back']));   // recommended never counts
    }

    public function testRosterFilter(): void
    {
        $records = [
            ['id' => 1, 'status' => 'open', 'photo_status' => null],
            ['id' => 2, 'status' => 'open', 'photo_status' => 'submitted'],
            ['id' => 3, 'status' => 'accepted', 'accepted_via' => 'photos'],
            ['id' => 4, 'status' => 'open', 'photo_status' => 'needs_changes'],
        ];
        $ids = fn($f) => array_map(fn($r) => $r['id'], gearRosterFilter($records, $f));
        $this->assertSame([1, 2, 3, 4], $ids('all'));
        $this->assertSame([1, 2, 3, 4], $ids('bogus'));
        $this->assertSame([1, 2, 4], $ids('needs_gear'));
        $this->assertSame([2], $ids('pending_review'));
        $this->assertSame([3], $ids('accepted'));
    }

    public function testCreateValidatesAndRejectsDuplicates(): void
    {
        $pdo = make_temp_pdo();
        [$owner] = $this->users($pdo);

        $r = gearCreate($pdo, $owner, '  Jane   Racer ', ' WCMA-1 ', 2026);
        $this->assertTrue($r['ok'], (string)$r['error']);
        $row = db_get_gear_record($pdo, $r['id']);
        $this->assertSame('Jane Racer', $row['driver_name']);
        $this->assertSame('jane racer', $row['driver_name_norm']);
        $this->assertSame('WCMA-1', $row['licence_no']);

        $dup = gearCreate($pdo, $owner, 'JANE RACER', '', 2026);
        $this->assertFalse($dup['ok']);
        $this->assertStringContainsString('already have', $dup['error']);

        $this->assertFalse(gearCreate($pdo, $owner, '   ', '', 2026)['ok']);
        $this->assertFalse(gearCreate($pdo, $owner, str_repeat('x', 101), '', 2026)['ok']);
        $this->assertFalse(gearCreate($pdo, $owner, 'Sam', str_repeat('9', 41), 2026)['ok']);
        $this->assertTrue(gearCreate($pdo, $owner, 'Jane Racer', '', 2027)['ok']);   // another season is fine
        $noLicence = gearCreate($pdo, $owner, 'No Licence', '', 2026);
        $this->assertNull(db_get_gear_record($pdo, $noLicence['id'])['licence_no']);
    }

    public function testSnapshotReportsPresentApplicableAndMissing(): void
    {
        $pdo = make_temp_pdo();
        [$owner] = $this->users($pdo);
        $id = $this->newGear($pdo, $owner);

        $this->assertCount(4, gearSnapshot($pdo, $id)['missing']);
        $this->addPhoto($pdo, $id, 'helmet_label');
        db_set_conditional_photo_applies($pdo, 'gear_record', $id, 'underwear_label', 1, true);

        $snap = gearSnapshot($pdo, $id);
        $this->assertSame(['helmet_label'], $snap['present']);
        $this->assertSame(['underwear_label'], $snap['applicable']);
        $this->assertCount(4, $snap['missing']);   // 3 required + underwear
        $this->assertContains('underwear_label', $snap['missing']);
        $this->assertNotContains('helmet_label', $snap['missing']);
    }

    public function testSubmitRules(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $id = $this->newGear($pdo, $owner);

        $this->assertStringContainsString('Add your photos', gearSubmit($pdo, $id)['error']);
        $this->addPhoto($pdo, $id, 'helmet_label');
        $this->assertStringContainsString('3 required photos are still missing', gearSubmit($pdo, $id)['error']);

        $this->addRequired($pdo, $id);
        db_set_conditional_photo_applies($pdo, 'gear_record', $id, 'underwear_label', 1, true);
        $this->assertStringContainsString('1 required photo is still missing', gearSubmit($pdo, $id)['error']);
        $this->addPhoto($pdo, $id, 'underwear_label');

        $this->assertTrue(gearSubmit($pdo, $id)['ok']);
        $this->assertSame('submitted', db_get_gear_record($pdo, $id)['photo_status']);
        $this->assertStringContainsString('already been submitted', gearSubmit($pdo, $id)['error']);
        $this->assertFalse(gearSubmit($pdo, 99999)['ok']);

        $done = $this->newGear($pdo, $owner, 'Accepted Driver');
        $this->addRequired($pdo, $done);
        gearAcceptInPerson($pdo, $done, $admin);
        $this->assertStringContainsString('already been teched', gearSubmit($pdo, $done)['error']);
    }

    public function testSendBackRulesAndResubmit(): void
    {
        $pdo = make_temp_pdo();
        [$owner] = $this->users($pdo);
        $id = $this->newGear($pdo, $owner);
        $this->addRequired($pdo, $id);
        $this->assertStringContainsString('not awaiting review', gearSendBack($pdo, $id, ['helmet_label' => 'Blurry'])['error']);   // still a draft
        gearSubmit($pdo, $id);

        $this->assertStringContainsString('at least one photo', gearSendBack($pdo, $id, [])['error']);
        $this->assertStringContainsString('note for every photo', gearSendBack($pdo, $id, ['helmet_label' => '  '])['error']);
        $this->assertFalse(gearSendBack($pdo, $id, ['not_a_photo' => 'x'])['ok']);

        $r = gearSendBack($pdo, $id, ['helmet_label' => 'Label not readable', 'suit_label' => 'Too dark']);
        $this->assertTrue($r['ok'], (string)$r['error']);
        $this->assertSame(['helmet_label' => 'Label not readable', 'suit_label' => 'Too dark'], $r['retakes']);
        $this->assertSame('needs_changes', db_get_gear_record($pdo, $id)['photo_status']);
        $photos = db_get_inspection_photos($pdo, 'gear_record', $id);
        $this->assertSame('retake', $photos['helmet_label']['review_status']);
        $this->assertSame('Label not readable', $photos['helmet_label']['reviewer_note']);
        $this->assertSame('pending', $photos['fhr_label']['review_status']);

        $this->assertStringContainsString('flagged', gearSubmit($pdo, $id)['error']);
        $this->addPhoto($pdo, $id, 'helmet_label', 'uploads/retaken1.jpg');
        $this->addPhoto($pdo, $id, 'suit_label', 'uploads/retaken2.jpg');
        $this->assertTrue(gearSubmit($pdo, $id)['ok']);
    }

    public function testAcceptByPhotosThenRevoke(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $id = $this->newGear($pdo, $owner);
        $this->addRequired($pdo, $id);

        $this->assertStringContainsString('not awaiting review', gearAcceptByPhotos($pdo, $id, $admin)['error']);
        gearSubmit($pdo, $id);
        $this->assertTrue(gearAcceptByPhotos($pdo, $id, $admin)['ok']);

        $row = db_get_gear_record($pdo, $id);
        $this->assertSame('accepted', $row['status']);
        $this->assertSame('photos', $row['accepted_via']);
        foreach (db_get_inspection_photos($pdo, 'gear_record', $id) as $photo) {
            $this->assertSame('accepted', $photo['review_status']);
        }
        $this->assertFalse(gearAcceptByPhotos($pdo, $id, $admin)['ok']);

        $this->assertTrue(gearRevoke($pdo, $id)['ok']);
        $this->assertSame('open', db_get_gear_record($pdo, $id)['status']);
        $this->assertSame('submitted', db_get_gear_record($pdo, $id)['photo_status']);
        $photos = db_get_inspection_photos($pdo, 'gear_record', $id);
        $this->assertNotEmpty($photos);
        foreach ($photos as $photo) {
            $this->assertSame('pending', $photo['review_status']);
        }
        $this->assertStringContainsString('has not been accepted', gearRevoke($pdo, $id)['error']);
        $this->assertFalse(gearRevoke($pdo, 99999)['ok']);
    }

    public function testRevokeOfInPersonAcceptanceLeavesPhotosUnchanged(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $id = $this->newGear($pdo, $owner);
        $this->addRequired($pdo, $id);
        $before = db_get_inspection_photos($pdo, 'gear_record', $id);
        $this->assertNotEmpty($before);

        $this->assertTrue(gearAcceptInPerson($pdo, $id, $admin)['ok']);
        $this->assertTrue(gearRevoke($pdo, $id)['ok']);

        $after = db_get_inspection_photos($pdo, 'gear_record', $id);
        foreach ($before as $key => $photo) {
            $this->assertSame($photo['review_status'], $after[$key]['review_status']);
        }
    }

    public function testCreateCountsCharactersNotBytes(): void
    {
        $pdo = make_temp_pdo();
        [$owner] = $this->users($pdo);
        $this->assertTrue(gearCreate($pdo, $owner, str_repeat('é', 100), '', 2026)['ok']);
        $this->assertFalse(gearCreate($pdo, $owner, str_repeat('é', 101), '', 2027)['ok']);
        $this->assertTrue(gearCreate($pdo, $owner, 'Lic Ok', str_repeat('é', 40), 2026)['ok']);
        $this->assertFalse(gearCreate($pdo, $owner, 'Lic Bad', str_repeat('é', 41), 2026)['ok']);
    }

    public function testAcceptInPersonWithoutPhotos(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $id = $this->newGear($pdo, $owner);

        $this->assertTrue(gearAcceptInPerson($pdo, $id, $admin)['ok']);
        $this->assertSame('in_person', db_get_gear_record($pdo, $id)['accepted_via']);
        $again = gearAcceptInPerson($pdo, $id, $admin);
        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('already been teched', $again['error']);
        $this->assertFalse(gearAcceptInPerson($pdo, 99999, $admin)['ok']);
    }

    public function testMessagesAvoidBannedWording(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $id = $this->newGear($pdo, $owner);
        $messages = [
            gearSubmit($pdo, $id)['error'], gearSubmit($pdo, 99999)['error'], gearAcceptByPhotos($pdo, $id, $admin)['error'],
            gearSendBack($pdo, $id, [])['error'], gearRevoke($pdo, $id)['error'], gearCreate($pdo, $owner, '', '', 2026)['error'],
        ];
        foreach ($messages as $m) {
            $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', (string)$m);
        }
    }

    private function owner(PDO $pdo): int {
        return db_create_user($pdo, ['email' => 'g' . uniqid() . '@example.com', 'name' => 'Owner', 'password_hash' => 'x', 'google_id' => null]);
    }

    // Note: like the Phase 2 season tests, this fails in the ten days before 1 July, when the event
    // rolls into the next ice season.
    public function testStartIceForSheetCreatesOrReusesTheOwnersRecord(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->owner($pdo);
        $car = test_make_car($pdo, $u, '7');
        $event = db_create_event($pdo, 'NASCC Ice', date('Y-m-d', strtotime('+10 days')), null, 'ice', 'NASCC');
        $sheet = db_get_tech_sheet($pdo, test_make_ice_sheet($pdo, $u, $car, $event, 'SS'));

        $r = gearStartIceForSheet($pdo, $sheet, $u);
        $this->assertTrue($r['ok'], (string)$r['error']);
        $g = db_get_gear_record($pdo, (int)$r['id']);
        $this->assertSame('ice', $g['discipline']);
        $this->assertSame((int)$sheet['season'], (int)$g['season']);
        $this->assertSame('Test Driver', $g['driver_name']);
        $this->assertSame($r['id'], gearStartIceForSheet($pdo, $sheet, $u)['id']);   // reused, not duplicated

        $this->assertFalse(gearStartIceForSheet($pdo, $sheet, $this->owner($pdo))['ok']);   // not the owner
        $summer = ['discipline' => 'summer'] + $sheet;
        $this->assertFalse(gearStartIceForSheet($pdo, $summer, $u)['ok']);
        $old = ['season' => gearSeasonNow('ice') - 1] + $sheet;
        $this->assertFalse(gearStartIceForSheet($pdo, $old, $u)['ok']);
    }

    public function testStartIceForSheetOpensTheAddedDriversRecord(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->owner($pdo);
        $car = test_make_car($pdo, $u, '7');
        $event = db_create_event($pdo, 'NASCC Ice', date('Y-m-d', strtotime('+10 days')), null, 'ice', 'NASCC');
        $sheet = db_get_tech_sheet($pdo, test_make_ice_sheet($pdo, $u, $car, $event, 'SS'));
        db_add_tech_sheet_driver($pdo, (int)$sheet['id'], 2, 'Sam  Patel', '{}');
        $other = test_make_ice_sheet($pdo, $u, $car, $event, 'SS');
        db_add_tech_sheet_driver($pdo, $other, 3, 'Other Person', '{}');   // driver 3 on another sheet only

        $r = gearStartIceForSheet($pdo, $sheet, $u, 2);
        $this->assertTrue($r['ok'], (string)$r['error']);
        $g = db_get_gear_record($pdo, (int)$r['id']);
        $this->assertSame('Sam Patel', $g['driver_name']);
        $this->assertSame('ice', $g['discipline']);
        $this->assertSame($r['id'], gearStartIceForSheet($pdo, $sheet, $u, 2)['id']);   // found, not duplicated

        $one = gearStartIceForSheet($pdo, $sheet, $u, 1);
        $this->assertSame('Test Driver', db_get_gear_record($pdo, (int)$one['id'])['driver_name']);
        $this->assertSame($one['id'], gearStartIceForSheet($pdo, $sheet, $u)['id']);   // default is driver 1

        $this->assertFalse(gearStartIceForSheet($pdo, $sheet, $u, 3)['ok']);   // not on THIS sheet
        $this->assertFalse(gearStartIceForSheet($pdo, $sheet, $u, 0)['ok']);
        $this->assertFalse(gearStartIceForSheet($pdo, $sheet, $this->owner($pdo), 2)['ok']);   // not the owner
    }

    public function testIceSeasonNowAndIceLabels(): void
    {
        $this->assertSame(iceSeasonFromDate(date('Y-m-d')), gearSeasonNow('ice'));
        $this->assertSame((int)date('Y'), gearSeasonNow());
        $this->assertSame('Gear teched Winter 2026–27', gearStatusLabel(['state' => 'accepted', 'via' => 'in_person'], 2027, 'ice'));
        $this->assertSame('Gear teched 2026', gearStatusLabel(['state' => 'accepted', 'via' => 'in_person'], 2026));
    }

    public function testIceGearAcceptNeedsALevelAndRevokeClearsIt(): void
    {
        $pdo = make_temp_pdo();
        $owner = $this->owner($pdo);
        $r = gearCreate($pdo, $owner, 'Sam', '', 2027, 'ice');
        $this->assertTrue($r['ok']);
        $id = (int)$r['id'];
        $this->assertSame('ice', db_get_gear_record($pdo, $id)['discipline']);

        $this->assertFalse(gearAcceptInPerson($pdo, $id, $owner)['ok']);
        $this->assertFalse(gearAcceptInPerson($pdo, $id, $owner, 'bogus')['ok']);
        $this->assertSame('open', db_get_gear_record($pdo, $id)['status']);

        $this->assertTrue(gearAcceptInPerson($pdo, $id, $owner, 'caged')['ok']);
        $this->assertSame('caged', db_get_gear_record($pdo, $id)['level']);

        $this->assertTrue(gearRevoke($pdo, $id)['ok']);
        $this->assertNull(db_get_gear_record($pdo, $id)['level']);
    }

    public function testSummerGearAcceptIgnoresLevel(): void
    {
        $pdo = make_temp_pdo();
        $owner = $this->owner($pdo);
        $id = (int)gearCreate($pdo, $owner, 'Sam', '', 2026)['id'];
        $this->assertTrue(gearAcceptInPerson($pdo, $id, $owner)['ok']);
        $this->assertNull(db_get_gear_record($pdo, $id)['level']);
    }

    public function testSheetLinksOnlyMatchGearOfTheSheetsDiscipline(): void
    {
        $gear = [
            ['id' => 1, 'owner_user_id' => 4, 'season' => 2027, 'discipline' => 'summer', 'driver_name_norm' => 'sam', 'status' => 'accepted', 'accepted_via' => 'in_person', 'photo_status' => null],
            ['id' => 2, 'owner_user_id' => 4, 'season' => 2027, 'discipline' => 'ice', 'driver_name_norm' => 'sam', 'status' => 'open', 'accepted_via' => null, 'photo_status' => null],
        ];
        $ice = gearLinksForSheet(['user_id' => 4, 'season' => 2027, 'driver_name' => 'Sam', 'discipline' => 'ice', 'club' => 'NASCC', 'class' => 'LS'], [], $gear);
        $this->assertSame(2, $ice[0]['gear']['id']);
        $this->assertSame('ice', $ice[0]['discipline']);
        $this->assertSame('caged', $ice[0]['default_level']);
        $summer = gearLinksForSheet(['user_id' => 4, 'season' => 2027, 'driver_name' => 'Sam'], [], $gear);
        $this->assertSame(1, $summer[0]['gear']['id']);
        $this->assertNull($summer[0]['default_level']);
    }

    public function testIceGearSnapshotUsesTheIceList(): void
    {
        $pdo = make_temp_pdo();
        $id = (int)gearCreate($pdo, $this->owner($pdo), 'Sam', '', 2027, 'ice')['id'];
        $this->assertSame(['ice_helmet_label', 'ice_suit_label', 'ice_gloves_shoes'], gearSnapshot($pdo, $id)['missing']);
    }

    public function testAcceptingIceGearPhotosNeedsALevel(): void
    {
        $pdo = make_temp_pdo();
        $owner = $this->owner($pdo);
        $id = (int)gearCreate($pdo, $owner, 'Sam', '', 2027, 'ice')['id'];
        db_mark_gear_photos_draft($pdo, $id);
        db_transition_gear_photo_status($pdo, $id, ['draft'], 'submitted');

        $this->assertFalse(gearAcceptByPhotos($pdo, $id, $owner)['ok']);
        $this->assertFalse(gearAcceptByPhotos($pdo, $id, $owner, 'bogus')['ok']);
        $this->assertSame('submitted', db_get_gear_record($pdo, $id)['photo_status']);

        $this->assertTrue(gearAcceptByPhotos($pdo, $id, $owner, 'street_safe')['ok']);
        $g = db_get_gear_record($pdo, $id);
        $this->assertSame('accepted', $g['status']);
        $this->assertSame('photos', $g['accepted_via']);
        $this->assertSame('street_safe', $g['level']);
    }

    public function testSuggestedLevelComesFromTheHelmetPhoto(): void
    {
        $snap = fn(?string $std): array => ['photos' => $std === null ? [] : ['ice_helmet_label' => [
            'file_path' => 'x.jpg', 'typed_value' => json_encode(['standard' => $std]),
        ]]];
        $this->assertSame('caged', gearSuggestedLevel($snap('Snell SA2020')));
        $this->assertSame('street_safe', gearSuggestedLevel($snap('ECE 22.06')));
        $this->assertNull(gearSuggestedLevel($snap(null)));
    }

    public function testIceSummaryPrefersAcceptedThenCarryOver(): void
    {
        $ice = fn(string $status, ?string $level = null, string $via = 'in_person'): array =>
            ['id' => 7, 'season' => 2027, 'discipline' => 'ice', 'level' => $level, 'status' => $status,
             'accepted_via' => $status === 'accepted' ? $via : null, 'photo_status' => null];
        $summer = ['id' => 3, 'season' => 2026, 'discipline' => 'summer', 'status' => 'accepted', 'accepted_via' => 'in_person', 'photo_status' => null];

        $this->assertSame(['state' => 'accepted', 'label' => 'Gear teched Winter 2026–27 · caged', 'gearId' => 7],
            gearIceSummary($ice('accepted', 'caged'), $summer, 2027));
        $this->assertSame(['state' => 'accepted', 'label' => 'Winter 2026–27: from summer 2026', 'gearId' => null],
            gearIceSummary($ice('open'), $summer, 2027));
        $this->assertSame(['state' => 'accepted', 'label' => 'Winter 2026–27: from summer 2026', 'gearId' => null],
            gearIceSummary(null, $summer, 2027));
        $this->assertSame('none', gearIceSummary($ice('open'), null, 2027)['state']);
        $this->assertSame(7, gearIceSummary($ice('open'), null, 2027)['gearId']);
        $this->assertSame(['state' => 'none', 'label' => 'Needs ice gear check 2027', 'gearId' => null], gearIceSummary(null, null, 2027));
        $notAccepted = ['status' => 'open'] + $summer;
        $this->assertSame('none', gearIceSummary(null, $notAccepted, 2027)['state']);
    }
}
