<?php
// wcma-calculator/tests/PretechLibTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../pretech-lib.php';

use PHPUnit\Framework\TestCase;

final class PretechLibTest extends TestCase
{
    private function fixture(PDO $pdo, string $number = '42', ?int $userId = null, string $eventDate = '2026-05-10'): array {
        $userId = $userId ?? db_create_user($pdo, ['email' => "u$number@example.com", 'name' => 'Racer', 'password_hash' => 'x', 'google_id' => null]);
        $adminId = db_create_user($pdo, ['email' => 'admin' . uniqid() . '@example.com', 'name' => 'Tech', 'password_hash' => 'x', 'google_id' => null]);
        $subId = db_insert_submission($pdo, [
            ':submitted_at' => date('Y-m-d H:i:s'), ':name' => 'Racer', ':email' => "u$number@example.com",
            ':year' => '2020', ':make' => 'Mazda', ':model' => 'MX-5', ':comments' => null,
            ':competition_weight' => 2200, ':declared_hp' => 150, ':dyno_hp' => null,
            ':chassis_display' => null, ':body_mods_display' => null, ':transmission_display' => null,
            ':drivetrain_display' => null, ':tires_display' => null, ':brake_suspension' => null,
            ':chassis_value' => 0, ':body_mods_value' => 0, ':transmission_value' => 0,
            ':drivetrain_value' => 0, ':tires_value' => 0, ':brake_suspension_value' => 0,
            ':weight_factor' => 0, ':modification_factor' => 0, ':base_ratio' => 14.67, ':modified_ratio' => 14.67,
            ':calculated_class' => 'IT1', ':user_id' => $userId,
        ]);
        $eventId = db_create_event($pdo, 'Event ' . $eventDate, $eventDate, null);
        $sheetId = db_insert_tech_sheet($pdo, [
            'submission_id' => $subId, 'user_id' => $userId, 'event_id' => $eventId, 'sheet_type' => 'standard',
            'entrant_name' => 'Racer', 'driver_name' => 'Racer', 'car_make' => 'Mazda', 'car_model' => 'MX-5',
            'car_colour' => 'Red', 'car_number' => $number, 'class' => 'IT1', 'engine_cc' => '1800', 'engine_hp' => '150',
            'car_weight' => 2200, 'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1,
        ]);
        return [$userId, $adminId, $sheetId];
    }

    private function addPhoto(PDO $pdo, int $sheetId, string $key, string $path = 'uploads/x.jpg'): void {
        db_upsert_inspection_photo($pdo, [
            'subject_type' => 'tech_sheet', 'subject_id' => $sheetId, 'requirement_key' => $key,
            'requirement_version' => 1, 'file_path' => $path, 'typed_value' => null,
        ]);
        db_mark_tech_sheet_photos_draft($pdo, $sheetId);
    }

    private function addRequiredPhotos(PDO $pdo, int $sheetId): void {
        foreach (photoRequirements('car') as $key => $def) {
            if ($def['tier'] === 'required') $this->addPhoto($pdo, $sheetId, $key);
        }
    }

    public function testSnapshotReportsPresentApplicableAndMissing(): void
    {
        $pdo = make_temp_pdo();
        [, , $id] = $this->fixture($pdo);

        $empty = pretechSnapshot($pdo, $id);
        $this->assertCount(15, $empty['missing']);
        $this->assertSame([], $empty['present']);

        $this->addPhoto($pdo, $id, 'front_34');
        db_set_conditional_photo_applies($pdo, 'tech_sheet', $id, 'ballast', 1, true);   // placeholder: applies, no file

        $snap = pretechSnapshot($pdo, $id);
        $this->assertSame(['front_34'], $snap['present']);
        $this->assertSame(['ballast'], $snap['applicable']);
        $this->assertContains('ballast', $snap['missing']);
        $this->assertNotContains('front_34', $snap['missing']);
        $this->assertCount(15, $snap['missing']);   // 14 required + ballast
        $this->assertArrayHasKey('ballast', $snap['photos']);
    }

    public function testSubmitRequiresACompleteSet(): void
    {
        $pdo = make_temp_pdo();
        [, , $id] = $this->fixture($pdo);

        $r = pretechSubmit($pdo, $id);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Add your photos', $r['error']);

        $this->addPhoto($pdo, $id, 'front_34');
        $r = pretechSubmit($pdo, $id);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('14 required photos are still missing', $r['error']);

        $this->addRequiredPhotos($pdo, $id);
        db_set_conditional_photo_applies($pdo, 'tech_sheet', $id, 'ballast', 1, true);
        $r = pretechSubmit($pdo, $id);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('1 required photo is still missing', $r['error']);

        $this->addPhoto($pdo, $id, 'ballast');
        $this->assertTrue(pretechSubmit($pdo, $id)['ok']);
        $this->assertSame('submitted', db_get_tech_sheet($pdo, $id)['photo_status']);

        $again = pretechSubmit($pdo, $id);
        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('already been submitted', $again['error']);
    }

    public function testSubmitRefusesTechedSheetsAndUnknownSheets(): void
    {
        $pdo = make_temp_pdo();
        [, $admin, $id] = $this->fixture($pdo);
        $this->addRequiredPhotos($pdo, $id);
        db_accept_tech_sheet_in_person($pdo, $id, $admin, 'sig.png');

        $this->assertStringContainsString('already been teched', pretechSubmit($pdo, $id)['error']);
        $this->assertFalse(pretechSubmit($pdo, 99999)['ok']);
    }

    public function testSendBackRequiresNotesAndFlagsPhotos(): void
    {
        $pdo = make_temp_pdo();
        [, , $id] = $this->fixture($pdo);
        $this->addRequiredPhotos($pdo, $id);
        pretechSubmit($pdo, $id);

        $r = pretechSendBack($pdo, $id, []);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('at least one photo', $r['error']);

        $r = pretechSendBack($pdo, $id, ['front_34' => '   ']);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('note for every photo', $r['error']);

        $r = pretechSendBack($pdo, $id, ['not_a_photo' => 'x']);
        $this->assertFalse($r['ok']);

        $r = pretechSendBack($pdo, $id, ['harness_date' => 'Date stamp not readable', 'front_34' => 'Car number hidden']);
        $this->assertTrue($r['ok'], (string)$r['error']);
        $this->assertSame(['harness_date' => 'Date stamp not readable', 'front_34' => 'Car number hidden'], $r['retakes']);
        $this->assertSame('needs_changes', db_get_tech_sheet($pdo, $id)['photo_status']);

        $photos = db_get_inspection_photos($pdo, 'tech_sheet', $id);
        $this->assertSame('retake', $photos['harness_date']['review_status']);
        $this->assertSame('Date stamp not readable', $photos['harness_date']['reviewer_note']);
        $this->assertSame('pending', $photos['rear_34']['review_status']);
    }

    public function testSendBackOnlyFromSubmitted(): void
    {
        $pdo = make_temp_pdo();
        [, , $id] = $this->fixture($pdo);
        $this->addRequiredPhotos($pdo, $id);   // draft
        $r = pretechSendBack($pdo, $id, ['front_34' => 'Blurry']);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('not awaiting review', $r['error']);
    }

    public function testResubmitBlockedWhileAPhotoIsStillFlaggedThenAllowedAfterRetake(): void
    {
        $pdo = make_temp_pdo();
        [, , $id] = $this->fixture($pdo);
        $this->addRequiredPhotos($pdo, $id);
        pretechSubmit($pdo, $id);
        pretechSendBack($pdo, $id, ['front_34' => 'Blurry']);

        $blocked = pretechSubmit($pdo, $id);
        $this->assertFalse($blocked['ok']);
        $this->assertStringContainsString('retake the photos the inspector flagged', $blocked['error']);

        $this->addPhoto($pdo, $id, 'front_34', 'uploads/retaken.jpg');   // replacing resets review to pending
        $this->assertTrue(pretechSubmit($pdo, $id)['ok']);
        $this->assertSame('submitted', db_get_tech_sheet($pdo, $id)['photo_status']);
    }

    public function testAcceptMarksSheetAndPhotosAccepted(): void
    {
        $pdo = make_temp_pdo();
        [, $admin, $id] = $this->fixture($pdo);
        $this->addRequiredPhotos($pdo, $id);

        $early = pretechAccept($pdo, $id, $admin);
        $this->assertFalse($early['ok']);
        $this->assertStringContainsString('not awaiting review', $early['error']);

        pretechSubmit($pdo, $id);
        $r = pretechAccept($pdo, $id, $admin);
        $this->assertTrue($r['ok'], (string)$r['error']);

        $row = db_get_tech_sheet($pdo, $id);
        $this->assertSame('teched', $row['status']);
        $this->assertSame('photos', $row['accepted_via']);
        foreach (db_get_inspection_photos($pdo, 'tech_sheet', $id) as $photo) {
            $this->assertSame('accepted', $photo['review_status']);
        }
        $this->assertFalse(pretechAccept($pdo, $id, $admin)['ok']);
    }

    public function testPageModes(): void
    {
        $pdo = make_temp_pdo();
        [$u, $admin, $spring] = $this->fixture($pdo, '42', null, '2026-05-10');
        [, , $fall] = $this->fixture($pdo, '42', $u, '2026-10-04');

        $sheets = fn() => db_get_identity_sheets($pdo, $u, '42', 2026);
        $mode = fn(int $id) => pretechPageMode(db_get_tech_sheet($pdo, $id), $sheets());

        $this->assertSame(['mode' => 'this_sheet', 'sheet_id' => null], $mode($spring));

        $this->addPhoto($pdo, $spring, 'front_34');   // spring now holds a photo set
        $this->assertSame(['mode' => 'this_sheet', 'sheet_id' => null], $mode($spring));
        $this->assertSame(['mode' => 'held_elsewhere', 'sheet_id' => $spring], $mode($fall));

        db_accept_tech_sheet_in_person($pdo, $fall, $admin, 'sig.png');
        $this->assertSame('car_accepted', $mode($spring)['mode']);
        $this->assertSame('car_accepted', $mode($fall)['mode']);
        $this->assertSame($fall, $mode($spring)['sheet_id']);
    }

    public function testOwnerWritesLockOnSubmittedAndAcceptedPhotoStatus(): void
    {
        $owner = ['id' => 5, 'role' => 'user'];
        $admin = ['id' => 1, 'role' => 'admin'];
        $base = ['user_id' => 5, 'status' => 'submitted'];

        foreach ([null, 'draft', 'needs_changes'] as $open) {
            $this->assertTrue(inspectionCanAccess($owner, $base + ['photo_status' => $open], true), (string)$open);
        }
        foreach (['submitted', 'accepted'] as $locked) {
            $this->assertFalse(inspectionCanAccess($owner, $base + ['photo_status' => $locked], true), $locked);
            $this->assertTrue(inspectionCanAccess($owner, $base + ['photo_status' => $locked], false), $locked . ' (read)');
            $this->assertTrue(inspectionCanAccess($admin, $base + ['photo_status' => $locked], true), $locked . ' (admin)');
        }
    }

    public function testSavingAPhotoMarksTheSheetDraft(): void
    {
        $pdo = make_temp_pdo();
        [, , $id] = $this->fixture($pdo);
        $dir = sys_get_temp_dir() . '/wcma_pt_' . uniqid();
        mkdir($dir, 0755, true);
        $tmp = $dir . '/in.bin';
        file_put_contents($tmp, hex2bin('ffd8ffc00011080001000103011100021100031100ffd9'));

        $this->assertNull(db_get_tech_sheet($pdo, $id)['photo_status']);
        $r = inspectionSavePhoto($pdo, $dir, 'tech_sheet', $id, 'front_34', $tmp, [], 'rename');

        $this->assertTrue($r['ok'], (string)$r['error']);
        $this->assertSame('draft', db_get_tech_sheet($pdo, $id)['photo_status']);

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
        rmdir($dir);
    }
}
