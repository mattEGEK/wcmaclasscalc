<?php
use PHPUnit\Framework\TestCase;

final class DbLinkSubmissionsTest extends TestCase
{
    private function minimalSubmissionData(string $email, ?int $user_id = null): array {
        return [
            ':submitted_at' => date('Y-m-d H:i:s'),
            ':name' => 'Test Driver', ':email' => $email,
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
            ':user_id' => $user_id,
        ];
    }

    public function testLinksAnonymousSubmissionsMatchingEmail(): void
    {
        $pdo = make_temp_pdo();
        $subId = db_insert_submission($pdo, $this->minimalSubmissionData('driver@example.com'));

        $userId = db_create_user($pdo, ['email' => 'driver@example.com', 'name' => 'Driver', 'password_hash' => 'x', 'google_id' => null]);
        $linked = db_link_submissions_by_email($pdo, $userId, 'driver@example.com');

        $this->assertSame(1, $linked);
        $sub = db_get_submission($pdo, $subId);
        $this->assertSame($userId, $sub['user_id']);
    }

    public function testDoesNotLinkSubmissionsAlreadyOwned(): void
    {
        $pdo = make_temp_pdo();
        $existingOwner = db_create_user($pdo, ['email' => 'owner@example.com', 'name' => 'Owner', 'password_hash' => 'x', 'google_id' => null]);
        $subId = db_insert_submission($pdo, $this->minimalSubmissionData('shared@example.com', $existingOwner));

        $newUser = db_create_user($pdo, ['email' => 'shared@example.com', 'name' => 'New', 'password_hash' => 'x', 'google_id' => null]);
        $linked = db_link_submissions_by_email($pdo, $newUser, 'shared@example.com');

        $this->assertSame(0, $linked);
        $sub = db_get_submission($pdo, $subId);
        $this->assertSame($existingOwner, $sub['user_id']);
    }

    public function testCaseInsensitiveMatch(): void
    {
        $pdo = make_temp_pdo();
        db_insert_submission($pdo, $this->minimalSubmissionData('Driver@Example.com'));

        $userId = db_create_user($pdo, ['email' => 'driver@example.com', 'name' => 'Driver', 'password_hash' => 'x', 'google_id' => null]);
        $linked = db_link_submissions_by_email($pdo, $userId, 'driver@example.com');

        $this->assertSame(1, $linked);
    }

    public function testReturnsZeroWhenNoneMatch(): void
    {
        $pdo = make_temp_pdo();
        $userId = db_create_user($pdo, ['email' => 'nomatch@example.com', 'name' => 'Nobody', 'password_hash' => 'x', 'google_id' => null]);

        $linked = db_link_submissions_by_email($pdo, $userId, 'nomatch@example.com');

        $this->assertSame(0, $linked);
    }
}
