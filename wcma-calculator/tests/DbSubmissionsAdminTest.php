<?php
use PHPUnit\Framework\TestCase;

final class DbSubmissionsAdminTest extends TestCase
{
    private function minimalSubmissionData(PDO $pdo, ?int $userId = null): array {
        $userId ??= db_find_user_by_email($pdo, 'admin-test@example.com')['id']
            ?? db_create_user($pdo, ['email' => 'admin-test@example.com', 'name' => 'Test Driver', 'password_hash' => 'x', 'google_id' => null]);
        return test_declaration_data($pdo, (int)$userId);
    }

    public function testCountSubmissions(): void {
        $pdo = make_temp_pdo();
        db_insert_submission($pdo, $this->minimalSubmissionData($pdo));
        db_insert_submission($pdo, $this->minimalSubmissionData($pdo));
        $this->assertSame(2, db_count_submissions($pdo));
    }

    public function testGetSubmissionsRespectsLimitAndOffset(): void {
        $pdo = make_temp_pdo();
        for ($i = 0; $i < 5; $i++) {
            $data = $this->minimalSubmissionData($pdo);
            $data[':name'] = "Driver {$i}";
            db_insert_submission($pdo, $data);
        }
        $page1 = db_get_submissions($pdo, 'id', 'asc', 2, 0);
        $page2 = db_get_submissions($pdo, 'id', 'asc', 2, 2);
        $this->assertCount(2, $page1);
        $this->assertCount(2, $page2);
        $this->assertSame('Driver 0', $page1[0]['name']);
        $this->assertSame('Driver 2', $page2[0]['name']);
    }

    public function testGetSubmissionsWithoutLimitReturnsAll(): void {
        $pdo = make_temp_pdo();
        db_insert_submission($pdo, $this->minimalSubmissionData($pdo));
        db_insert_submission($pdo, $this->minimalSubmissionData($pdo));
        $this->assertCount(2, db_get_submissions($pdo));
    }

    public function testDeleteSubmissionsBulk(): void {
        $pdo = make_temp_pdo();
        $id1 = db_insert_submission($pdo, $this->minimalSubmissionData($pdo));
        $id2 = db_insert_submission($pdo, $this->minimalSubmissionData($pdo));
        $id3 = db_insert_submission($pdo, $this->minimalSubmissionData($pdo));

        $deleted = db_delete_submissions($pdo, [$id1, $id3]);

        $this->assertSame(2, $deleted);
        $this->assertSame(1, db_count_submissions($pdo));
        $this->assertNotNull(db_get_submission($pdo, $id2));
    }

    public function testDeleteSubmissionsBulkWithEmptyArrayIsNoop(): void {
        $pdo = make_temp_pdo();
        db_insert_submission($pdo, $this->minimalSubmissionData($pdo));
        $this->assertSame(0, db_delete_submissions($pdo, []));
        $this->assertSame(1, db_count_submissions($pdo));
    }

    public function testCountSubmissionsByUser(): void {
        $pdo = make_temp_pdo();
        $userId = db_create_user($pdo, ['email' => 'u@example.com', 'name' => 'U', 'password_hash' => 'x', 'google_id' => null]);

        $data = $this->minimalSubmissionData($pdo, $userId);
        db_insert_submission($pdo, $data);
        db_insert_submission($pdo, $data);
        db_insert_submission($pdo, $this->minimalSubmissionData($pdo)); // the default test user

        $counts = db_count_submissions_by_user($pdo);
        $this->assertSame(2, $counts[$userId]);
        $defaultUserId = (int)db_find_user_by_email($pdo, 'admin-test@example.com')['id'];
        $this->assertSame(1, $counts[$defaultUserId]);
    }

    public function testUpdateSubmissionContact(): void {
        $pdo = make_temp_pdo();
        $id = db_insert_submission($pdo, $this->minimalSubmissionData($pdo));

        db_update_submission_contact($pdo, $id, [
            'name' => 'Corrected Name', 'email' => 'fixed@example.com',
            'year' => '2021', 'make' => 'Toyota', 'model' => 'MR2', 'comments' => 'typo fixed',
        ]);

        $sub = db_get_submission($pdo, $id);
        $this->assertSame('Corrected Name', $sub['name']);
        $this->assertSame('fixed@example.com', $sub['email']);
        $this->assertSame('2021', $sub['year']);
        $this->assertSame('Toyota', $sub['make']);
        $this->assertSame('MR2', $sub['model']);
        $this->assertSame('typo fixed', $sub['comments']);
    }

    public function testUpdateEmailSentTracksHistory(): void {
        $pdo = make_temp_pdo();
        $id = db_insert_submission($pdo, $this->minimalSubmissionData($pdo));

        db_update_email_sent($pdo, $id, 1);
        $sub = db_get_submission($pdo, $id);
        $this->assertSame(1, (int)$sub['email_sent']);
        $this->assertSame(1, (int)$sub['email_send_count']);
        $this->assertNotNull($sub['last_emailed_at']);

        db_update_email_sent($pdo, $id, 1);
        $sub = db_get_submission($pdo, $id);
        $this->assertSame(2, (int)$sub['email_send_count']);
    }

    public function testUpdateEmailSentFailureDoesNotBumpHistory(): void {
        $pdo = make_temp_pdo();
        $id = db_insert_submission($pdo, $this->minimalSubmissionData($pdo));

        db_update_email_sent($pdo, $id, 0);
        $sub = db_get_submission($pdo, $id);
        $this->assertSame(0, (int)$sub['email_sent']);
        $this->assertSame(0, (int)$sub['email_send_count']);
        $this->assertNull($sub['last_emailed_at']);
    }
}
