<?php
use PHPUnit\Framework\TestCase;

final class DbSubmissionsUserIdTest extends TestCase
{
    public function testInsertSubmissionWithUserId(): void
    {
        $pdo = make_temp_pdo();
        $userId = db_create_user($pdo, ['email' => 'u@example.com', 'name' => 'U', 'password_hash' => 'x', 'google_id' => null]);

        $data = test_declaration_data($pdo, $userId);
        $id = db_insert_submission($pdo, $data);

        $sub = db_get_submission($pdo, $id);
        $this->assertSame($userId, $sub['user_id']);
    }

    public function testGetUserSubmissionsAndCount(): void
    {
        $pdo = make_temp_pdo();
        $userId = db_create_user($pdo, ['email' => 'u2@example.com', 'name' => 'U2', 'password_hash' => 'x', 'google_id' => null]);

        $data = test_declaration_data($pdo, $userId);
        db_insert_submission($pdo, $data);
        db_insert_submission($pdo, $data);

        $this->assertSame(2, db_count_user_submissions($pdo, $userId));
        $this->assertCount(2, db_get_user_submissions($pdo, $userId));
    }

    public function testGetUserSubmissionOwnershipScoped(): void
    {
        $pdo = make_temp_pdo();
        $ownerId = db_create_user($pdo, ['email' => 'owner@example.com', 'name' => 'Owner', 'password_hash' => 'x', 'google_id' => null]);
        $otherId = db_create_user($pdo, ['email' => 'other@example.com', 'name' => 'Other', 'password_hash' => 'x', 'google_id' => null]);

        $data = test_declaration_data($pdo, $ownerId);
        $subId = db_insert_submission($pdo, $data);

        $this->assertNotNull(db_get_user_submission($pdo, $ownerId, $subId));
        $this->assertNull(db_get_user_submission($pdo, $otherId, $subId));
    }
}
