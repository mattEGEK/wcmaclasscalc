<?php
// wcma-calculator/tests/DbPretechTest.php
use PHPUnit\Framework\TestCase;

final class DbPretechTest extends TestCase
{
    private function fixture(PDO $pdo): array {
        $userId = db_create_user($pdo, ['email' => 'racer@example.com', 'name' => 'Racer', 'password_hash' => 'x', 'google_id' => null]);
        $adminId = db_create_user($pdo, ['email' => 'tech@example.com', 'name' => 'Tech', 'password_hash' => 'x', 'google_id' => null]);
        $subId = db_insert_submission($pdo, [
            ':submitted_at' => date('Y-m-d H:i:s'), ':name' => 'Racer', ':email' => 'racer@example.com',
            ':year' => '2020', ':make' => 'Mazda', ':model' => 'MX-5', ':comments' => null,
            ':competition_weight' => 2200, ':declared_hp' => 150, ':dyno_hp' => null,
            ':chassis_display' => null, ':body_mods_display' => null, ':transmission_display' => null,
            ':drivetrain_display' => null, ':tires_display' => null, ':brake_suspension' => null,
            ':chassis_value' => 0, ':body_mods_value' => 0, ':transmission_value' => 0,
            ':drivetrain_value' => 0, ':tires_value' => 0, ':brake_suspension_value' => 0,
            ':weight_factor' => 0, ':modification_factor' => 0, ':base_ratio' => 14.67, ':modified_ratio' => 14.67,
            ':calculated_class' => 'IT1', ':user_id' => $userId,
        ]);
        $eventId = db_create_event($pdo, 'Spring Sprint', '2026-05-10', null);
        $sheetId = db_insert_tech_sheet($pdo, [
            'submission_id' => $subId, 'user_id' => $userId, 'event_id' => $eventId, 'sheet_type' => 'standard',
            'entrant_name' => 'Racer', 'driver_name' => 'Racer', 'car_make' => 'Mazda', 'car_model' => 'MX-5',
            'car_colour' => 'Red', 'car_number' => '42', 'class' => 'IT1', 'engine_cc' => '1800', 'engine_hp' => '150',
            'car_weight' => 2200, 'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1,
        ]);
        return [$userId, $adminId, $sheetId];
    }

    private function photoStatus(PDO $pdo, int $id): ?string {
        return db_get_tech_sheet($pdo, $id)['photo_status'];
    }

    private function photo(PDO $pdo, int $sheetId, string $key = 'front_34', string $path = 'uploads/x.jpg'): int {
        db_upsert_inspection_photo($pdo, [
            'subject_type' => 'tech_sheet', 'subject_id' => $sheetId, 'requirement_key' => $key,
            'requirement_version' => 1, 'file_path' => $path, 'typed_value' => null,
        ]);
        return (int)db_get_inspection_photos($pdo, 'tech_sheet', $sheetId)[$key]['id'];
    }

    public function testMarkDraftOnlyFromNullAndNotWhenTeched(): void
    {
        $pdo = make_temp_pdo();
        [$u, $admin, $id] = $this->fixture($pdo);

        $this->assertNull($this->photoStatus($pdo, $id));
        db_mark_tech_sheet_photos_draft($pdo, $id);
        $this->assertSame('draft', $this->photoStatus($pdo, $id));

        db_transition_tech_sheet_photo_status($pdo, $id, ['draft'], 'submitted');
        db_mark_tech_sheet_photos_draft($pdo, $id);
        $this->assertSame('submitted', $this->photoStatus($pdo, $id));   // not reset

        $pdo2 = make_temp_pdo();
        [, $admin2, $sheet2] = $this->fixture($pdo2);
        db_accept_tech_sheet_in_person($pdo2, $sheet2, $admin2, 'sig.png');
        db_mark_tech_sheet_photos_draft($pdo2, $sheet2);
        $this->assertNull($this->photoStatus($pdo2, $sheet2));
    }

    public function testTransitionIsAtomicAndChecksSourceState(): void
    {
        $pdo = make_temp_pdo();
        [, , $id] = $this->fixture($pdo);

        $this->assertFalse(db_transition_tech_sheet_photo_status($pdo, $id, ['draft'], 'submitted'));   // still NULL
        db_mark_tech_sheet_photos_draft($pdo, $id);
        $this->assertTrue(db_transition_tech_sheet_photo_status($pdo, $id, ['draft', 'needs_changes'], 'submitted'));
        $this->assertSame('submitted', $this->photoStatus($pdo, $id));
        $this->assertFalse(db_transition_tech_sheet_photo_status($pdo, $id, ['draft', 'needs_changes'], 'submitted'));
        $this->assertTrue(db_transition_tech_sheet_photo_status($pdo, $id, ['submitted'], 'needs_changes'));
        $this->assertSame('needs_changes', $this->photoStatus($pdo, $id));
    }

    public function testAcceptByPhotosOnlyFromSubmittedOnce(): void
    {
        $pdo = make_temp_pdo();
        [, $admin, $id] = $this->fixture($pdo);

        $this->assertFalse(db_accept_tech_sheet_by_photos($pdo, $id, $admin));   // no photo set yet
        db_mark_tech_sheet_photos_draft($pdo, $id);
        $this->assertFalse(db_accept_tech_sheet_by_photos($pdo, $id, $admin));   // draft is not reviewable
        db_transition_tech_sheet_photo_status($pdo, $id, ['draft'], 'submitted');

        $this->assertTrue(db_accept_tech_sheet_by_photos($pdo, $id, $admin));
        $row = db_get_tech_sheet($pdo, $id);
        $this->assertSame('teched', $row['status']);
        $this->assertSame('photos', $row['accepted_via']);
        $this->assertSame('accepted', $row['photo_status']);
        $this->assertSame($admin, (int)$row['reviewed_by_user_id']);
        $this->assertNotNull($row['reviewed_at']);
        $this->assertNull($row['tech_signature_path']);

        $this->assertFalse(db_accept_tech_sheet_by_photos($pdo, $id, $admin));
        $this->assertFalse(db_transition_tech_sheet_photo_status($pdo, $id, ['accepted'], 'submitted'));   // teched sheets never transition
    }

    public function testRevokeOfPhotoAcceptedSheetReturnsItToTheReviewQueue(): void
    {
        $pdo = make_temp_pdo();
        [, $admin, $id] = $this->fixture($pdo);
        db_mark_tech_sheet_photos_draft($pdo, $id);
        db_transition_tech_sheet_photo_status($pdo, $id, ['draft'], 'submitted');
        db_accept_tech_sheet_by_photos($pdo, $id, $admin);

        $this->assertTrue(db_revoke_tech_sheet_acceptance($pdo, $id));

        $row = db_get_tech_sheet($pdo, $id);
        $this->assertSame('submitted', $row['status']);
        $this->assertSame('submitted', $row['photo_status']);
        $this->assertNull($row['accepted_via']);
    }

    public function testRevokeLeavesOtherPhotoStatusesAlone(): void
    {
        $pdo = make_temp_pdo();
        [, $admin, $id] = $this->fixture($pdo);
        db_mark_tech_sheet_photos_draft($pdo, $id);
        db_accept_tech_sheet_in_person($pdo, $id, $admin, 'sig.png');

        $this->assertTrue(db_revoke_tech_sheet_acceptance($pdo, $id));
        $this->assertSame('draft', $this->photoStatus($pdo, $id));
    }

    public function testConditionalAppliesPlaceholderAndRemoval(): void
    {
        $pdo = make_temp_pdo();
        [, , $id] = $this->fixture($pdo);

        $this->assertNull(db_set_conditional_photo_applies($pdo, 'tech_sheet', $id, 'ballast', 1, true));
        $rows = db_get_inspection_photos($pdo, 'tech_sheet', $id);
        $this->assertSame('', $rows['ballast']['file_path']);
        $this->assertSame(1, (int)$rows['ballast']['applies']);

        $this->assertNull(db_set_conditional_photo_applies($pdo, 'tech_sheet', $id, 'ballast', 1, true));   // idempotent
        $this->assertCount(1, db_get_inspection_photos($pdo, 'tech_sheet', $id));

        $this->assertNull(db_set_conditional_photo_applies($pdo, 'tech_sheet', $id, 'ballast', 1, false));  // nothing to return: placeholder had no file
        $this->assertSame([], db_get_inspection_photos($pdo, 'tech_sheet', $id));
    }

    public function testTurningOffAppliesReturnsThePreviousFilePath(): void
    {
        $pdo = make_temp_pdo();
        [, , $id] = $this->fixture($pdo);
        $this->photo($pdo, $id, 'aero', 'uploads/inspection/tech_sheet/1/aero.jpg');

        $this->assertSame('uploads/inspection/tech_sheet/1/aero.jpg', db_set_conditional_photo_applies($pdo, 'tech_sheet', $id, 'aero', 1, false));
        $this->assertSame([], db_get_inspection_photos($pdo, 'tech_sheet', $id));

        $this->photo($pdo, $id, 'aero', 'uploads/inspection/tech_sheet/1/aero.jpg');
        $this->assertNull(db_set_conditional_photo_applies($pdo, 'tech_sheet', $id, 'aero', 1, true));      // turning on keeps an existing photo
        $this->assertSame('uploads/inspection/tech_sheet/1/aero.jpg', db_get_inspection_photos($pdo, 'tech_sheet', $id)['aero']['file_path']);
    }

    public function testTypedUpdateResetsReviewAndReviewHelpers(): void
    {
        $pdo = make_temp_pdo();
        [, , $id] = $this->fixture($pdo);
        $photoId = $this->photo($pdo, $id, 'harness_date');

        db_set_inspection_photo_review($pdo, $photoId, 'retake', 'Label not readable');
        $row = db_get_inspection_photo($pdo, $photoId);
        $this->assertSame('retake', $row['review_status']);
        $this->assertSame('Label not readable', $row['reviewer_note']);

        db_update_inspection_photo_typed($pdo, $photoId, '{"date":"05/2025"}');
        $row = db_get_inspection_photo($pdo, $photoId);
        $this->assertSame('{"date":"05/2025"}', $row['typed_value']);
        $this->assertSame('pending', $row['review_status']);
        $this->assertNull($row['reviewer_note']);
    }

    public function testSetAllReviewStatusOnlyTouchesRowsWithFiles(): void
    {
        $pdo = make_temp_pdo();
        [, , $id] = $this->fixture($pdo);
        $withFile = $this->photo($pdo, $id, 'front_34');
        db_set_conditional_photo_applies($pdo, 'tech_sheet', $id, 'ballast', 1, true);   // placeholder, no file

        db_set_all_photos_review_status($pdo, 'tech_sheet', $id, 'accepted');

        $rows = db_get_inspection_photos($pdo, 'tech_sheet', $id);
        $this->assertSame('accepted', $rows['front_34']['review_status']);
        $this->assertSame('pending', $rows['ballast']['review_status']);
        $this->assertSame($withFile, (int)$rows['front_34']['id']);
    }
}
