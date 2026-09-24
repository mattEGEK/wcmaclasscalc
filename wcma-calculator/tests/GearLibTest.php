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

    public function testRenewCopiesNameAndLicenceForALaterSeason(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $old = gearCreate($pdo, $owner, 'Jane Racer', 'WCMA-1', 2025)['id'];
        db_accept_gear_in_person($pdo, $old, $admin);

        $r = gearRenew($pdo, $owner, $old, 2026);
        $this->assertTrue($r['ok'], (string)$r['error']);
        $new = db_get_gear_record($pdo, $r['id']);
        $this->assertSame('Jane Racer', $new['driver_name']);
        $this->assertSame('WCMA-1', $new['licence_no']);
        $this->assertSame(2026, (int)$new['season']);
        $this->assertSame('open', $new['status']);
        $this->assertNull($new['photo_status']);

        $this->assertFalse(gearRenew($pdo, $owner, $old, 2026)['ok']);        // already exists
        $this->assertFalse(gearRenew($pdo, $owner, $old, 2025)['ok']);        // not a later season
        $this->assertFalse(gearRenew($pdo, $owner + 99, $old, 2027)['ok']);   // not the owner
        $this->assertFalse(gearRenew($pdo, $owner, 99999, 2027)['ok']);
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
        $this->assertStringContainsString('has not been accepted', gearRevoke($pdo, $id)['error']);
        $this->assertFalse(gearRevoke($pdo, 99999)['ok']);
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
}
