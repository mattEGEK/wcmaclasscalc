<?php
// wcma-calculator/tests/DbCarRestoreTest.php
use PHPUnit\Framework\TestCase;

final class DbCarRestoreTest extends TestCase
{
    private function user(PDO $pdo, string $email): int {
        return db_create_user($pdo, ['email' => $email, 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
    }

    public function testRestoreBringsBackOnlyTheOwnersArchivedCar(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'a@example.com');
        $other = $this->user($pdo, 'b@example.com');
        $id = db_create_car($pdo, $u, ['car_number' => '42', 'make' => 'Honda', 'model' => 'S2000']);

        $this->assertFalse(db_restore_car($pdo, $u, $id));          // not archived
        $this->assertTrue(db_archive_car($pdo, $u, $id));
        $this->assertFalse(db_restore_car($pdo, $other, $id));      // not theirs
        $this->assertTrue(db_restore_car($pdo, $u, $id));
        $this->assertNull(db_get_car($pdo, $id)['archived_at']);
        $this->assertCount(1, db_get_user_cars($pdo, $u));
    }

    public function testSubmissionsHaveAnAcceptedAtColumn(): void
    {
        $pdo = make_temp_pdo();
        $cols = array_column($pdo->query("PRAGMA table_info(submissions)")->fetchAll(), 'name');
        $this->assertContains('accepted_at', $cols);
    }
}
