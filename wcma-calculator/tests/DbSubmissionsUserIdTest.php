<?php
use PHPUnit\Framework\TestCase;

final class DbSubmissionsUserIdTest extends TestCase
{
    private function minimalSubmissionData(): array {
        return [
            ':submitted_at' => date('Y-m-d H:i:s'),
            ':name' => 'Test Driver', ':email' => 't@example.com',
            ':year' => '2020', ':make' => 'Mazda', ':model' => 'MX-5',
            ':comments' => null,
            ':competition_weight' => 2200, ':declared_hp' => 150, ':dyno_hp' => null,
            ':chassis_display' => null, ':body_mods_display' => null,
            ':transmission_display' => null, ':drivetrain_display' => null, ':tires_display' => null,
            ':brake_suspension' => '[]',
            ':chassis_value' => 0, ':body_mods_value' => 0, ':transmission_value' => 0,
            ':drivetrain_value' => 0, ':tires_value' => 0, ':brake_suspension_value' => 0,
            ':weight_factor' => 0, ':modification_factor' => 0,
            ':base_ratio' => 14.67, ':modified_ratio' => 14.67, ':calculated_class' => 'IT1',
        ];
    }

    public function testInsertSubmissionWithUserId(): void
    {
        $pdo = make_temp_pdo();
        $userId = db_create_user($pdo, ['email' => 'u@example.com', 'name' => 'U', 'password_hash' => 'x', 'google_id' => null]);

        $data = $this->minimalSubmissionData();
        $data[':user_id'] = $userId;
        $id = db_insert_submission($pdo, $data);

        $sub = db_get_submission($pdo, $id);
        $this->assertSame($userId, $sub['user_id']);
    }

    public function testInsertSubmissionWithoutUserIdIsNull(): void
    {
        $pdo = make_temp_pdo();
        $data = $this->minimalSubmissionData();
        $id = db_insert_submission($pdo, $data);

        $sub = db_get_submission($pdo, $id);
        $this->assertNull($sub['user_id']);
    }

    public function testGetUserSubmissionsAndCount(): void
    {
        $pdo = make_temp_pdo();
        $userId = db_create_user($pdo, ['email' => 'u2@example.com', 'name' => 'U2', 'password_hash' => 'x', 'google_id' => null]);

        $data = $this->minimalSubmissionData();
        $data[':user_id'] = $userId;
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

        $data = $this->minimalSubmissionData();
        $data[':user_id'] = $ownerId;
        $subId = db_insert_submission($pdo, $data);

        $this->assertNotNull(db_get_user_submission($pdo, $ownerId, $subId));
        $this->assertNull(db_get_user_submission($pdo, $otherId, $subId));
    }
}
