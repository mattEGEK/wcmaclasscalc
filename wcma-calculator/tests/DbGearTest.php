<?php
// wcma-calculator/tests/DbGearTest.php
use PHPUnit\Framework\TestCase;

final class DbGearTest extends TestCase
{
    private function users(PDO $pdo): array {
        $owner = db_create_user($pdo, ['email' => 'captain@example.com', 'name' => 'Captain', 'password_hash' => 'x', 'google_id' => null]);
        $admin = db_create_user($pdo, ['email' => 'tech@example.com', 'name' => 'Tech', 'password_hash' => 'x', 'google_id' => null]);
        return [$owner, $admin];
    }

    private function gear(PDO $pdo, int $owner, string $name = 'Jane Racer', int $season = 2026): int {
        return db_insert_gear_record($pdo, $owner, $name, strtolower($name), null, $season);
    }

    public function testInsertGetAndFind(): void
    {
        $pdo = make_temp_pdo();
        [$owner] = $this->users($pdo);
        $id = db_insert_gear_record($pdo, $owner, 'Jane Racer', 'jane racer', 'WCMA-123', 2026);

        $row = db_get_gear_record($pdo, $id);
        $this->assertSame('Jane Racer', $row['driver_name']);
        $this->assertSame('WCMA-123', $row['licence_no']);
        $this->assertSame(2026, (int)$row['season']);
        $this->assertSame('open', $row['status']);
        $this->assertNull($row['photo_status']);
        $this->assertNull($row['accepted_via']);

        $this->assertSame($id, (int)db_find_gear_record($pdo, $owner, 'jane racer', 2026)['id']);
        $this->assertNull(db_find_gear_record($pdo, $owner, 'jane racer', 2027));
        $this->assertNull(db_find_gear_record($pdo, $owner + 1, 'jane racer', 2026));
        $this->assertNull(db_get_gear_record($pdo, 99999));
    }

    public function testDuplicateOwnerNameSeasonIsRejected(): void
    {
        $pdo = make_temp_pdo();
        [$owner] = $this->users($pdo);
        $this->gear($pdo, $owner);

        $this->expectException(PDOException::class);
        $this->gear($pdo, $owner);
    }

    public function testSameNameInAnotherSeasonOrForAnotherOwnerIsAllowed(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $this->gear($pdo, $owner, 'Jane Racer', 2026);
        $this->gear($pdo, $owner, 'Jane Racer', 2027);
        $this->gear($pdo, $admin, 'Jane Racer', 2026);
        $this->assertCount(2, db_get_user_gear_records($pdo, $owner));
    }

    public function testUserListIsNewestSeasonFirstThenByName(): void
    {
        $pdo = make_temp_pdo();
        [$owner] = $this->users($pdo);
        $old = $this->gear($pdo, $owner, 'Zed', 2025);
        $b = $this->gear($pdo, $owner, 'Bob', 2026);
        $a = $this->gear($pdo, $owner, 'Amy', 2026);

        $ids = array_map(fn($r) => (int)$r['id'], db_get_user_gear_records($pdo, $owner));
        $this->assertSame([$a, $b, $old], $ids);
    }

    public function testSeasonListIncludesOwnerNameAndEmail(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $this->gear($pdo, $owner, 'Jane Racer', 2026);
        $this->gear($pdo, $admin, 'Al Driver', 2026);
        $this->gear($pdo, $owner, 'Old Timer', 2025);

        $rows = db_get_gear_records_for_season($pdo, 2026);
        $this->assertSame(['Al Driver', 'Jane Racer'], array_map(fn($r) => $r['driver_name'], $rows));
        $this->assertSame('Tech', $rows[0]['owner_name']);
        $this->assertSame('captain@example.com', $rows[1]['owner_email']);
    }

    public function testMarkDraftOnlyFromNullAndNotWhenAccepted(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $id = $this->gear($pdo, $owner);

        db_mark_gear_photos_draft($pdo, $id);
        $this->assertSame('draft', db_get_gear_record($pdo, $id)['photo_status']);
        db_transition_gear_photo_status($pdo, $id, ['draft'], 'submitted');
        db_mark_gear_photos_draft($pdo, $id);
        $this->assertSame('submitted', db_get_gear_record($pdo, $id)['photo_status']);   // not reset

        $other = $this->gear($pdo, $owner, 'Accepted Driver');
        db_accept_gear_in_person($pdo, $other, $admin);
        db_mark_gear_photos_draft($pdo, $other);
        $this->assertNull(db_get_gear_record($pdo, $other)['photo_status']);
    }

    public function testTransitionIsAtomicAndNeverAppliesToAcceptedRecords(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $id = $this->gear($pdo, $owner);

        $this->assertFalse(db_transition_gear_photo_status($pdo, $id, ['draft'], 'submitted'));   // still NULL
        $this->assertFalse(db_transition_gear_photo_status($pdo, $id, [], 'submitted'));
        db_mark_gear_photos_draft($pdo, $id);
        $this->assertTrue(db_transition_gear_photo_status($pdo, $id, ['draft', 'needs_changes'], 'submitted'));
        $this->assertFalse(db_transition_gear_photo_status($pdo, $id, ['draft', 'needs_changes'], 'submitted'));
        $this->assertTrue(db_transition_gear_photo_status($pdo, $id, ['submitted'], 'needs_changes'));

        db_accept_gear_in_person($pdo, $id, $admin);
        $this->assertFalse(db_transition_gear_photo_status($pdo, $id, ['needs_changes'], 'submitted'));
    }

    public function testAcceptByPhotosOnlyFromSubmittedOnce(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $id = $this->gear($pdo, $owner);

        $this->assertFalse(db_accept_gear_by_photos($pdo, $id, $admin));
        db_mark_gear_photos_draft($pdo, $id);
        $this->assertFalse(db_accept_gear_by_photos($pdo, $id, $admin));
        db_transition_gear_photo_status($pdo, $id, ['draft'], 'submitted');

        $this->assertTrue(db_accept_gear_by_photos($pdo, $id, $admin));
        $row = db_get_gear_record($pdo, $id);
        $this->assertSame('accepted', $row['status']);
        $this->assertSame('photos', $row['accepted_via']);
        $this->assertSame('accepted', $row['photo_status']);
        $this->assertSame($admin, (int)$row['reviewed_by_user_id']);
        $this->assertNotNull($row['reviewed_at']);
        $this->assertFalse(db_accept_gear_by_photos($pdo, $id, $admin));
    }

    public function testAcceptInPersonFromOpenOnceAndLeavesPhotoStatus(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $id = $this->gear($pdo, $owner);
        db_mark_gear_photos_draft($pdo, $id);

        $this->assertTrue(db_accept_gear_in_person($pdo, $id, $admin));
        $row = db_get_gear_record($pdo, $id);
        $this->assertSame('accepted', $row['status']);
        $this->assertSame('in_person', $row['accepted_via']);
        $this->assertSame('draft', $row['photo_status']);
        $this->assertSame($admin, (int)$row['reviewed_by_user_id']);

        $this->assertFalse(db_accept_gear_in_person($pdo, $id, $admin));
        $this->assertFalse(db_accept_gear_in_person($pdo, 99999, $admin));
    }

    public function testRevokeReturnsToOpenAndRequeuesPhotoAcceptance(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);

        $byPhotos = $this->gear($pdo, $owner, 'Photo Driver');
        db_mark_gear_photos_draft($pdo, $byPhotos);
        db_transition_gear_photo_status($pdo, $byPhotos, ['draft'], 'submitted');
        db_accept_gear_by_photos($pdo, $byPhotos, $admin);
        $this->assertTrue(db_revoke_gear_acceptance($pdo, $byPhotos));
        $row = db_get_gear_record($pdo, $byPhotos);
        $this->assertSame('open', $row['status']);
        $this->assertSame('submitted', $row['photo_status']);
        foreach (['accepted_via', 'reviewed_by_user_id', 'reviewed_at'] as $col) $this->assertNull($row[$col], $col);

        $inPerson = $this->gear($pdo, $owner, 'Track Driver');
        db_mark_gear_photos_draft($pdo, $inPerson);
        db_accept_gear_in_person($pdo, $inPerson, $admin);
        $this->assertTrue(db_revoke_gear_acceptance($pdo, $inPerson));
        $this->assertSame('draft', db_get_gear_record($pdo, $inPerson)['photo_status']);

        $this->assertFalse(db_revoke_gear_acceptance($pdo, $inPerson));   // not accepted any more
    }
}
