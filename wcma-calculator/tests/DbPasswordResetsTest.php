<?php
use PHPUnit\Framework\TestCase;

final class DbPasswordResetsTest extends TestCase
{
    public function testCreateAndGetPasswordReset(): void
    {
        $pdo = make_temp_pdo();
        $userId = db_create_user($pdo, ['email' => 'r@example.com', 'name' => 'R', 'password_hash' => 'x', 'google_id' => null]);
        $tokenHash = hash('sha256', 'plaintoken');
        $expires = date('Y-m-d H:i:s', time() + 3600);

        db_create_password_reset($pdo, $userId, $tokenHash, $expires);

        $row = db_get_password_reset($pdo, $tokenHash);
        $this->assertNotNull($row);
        $this->assertSame($userId, $row['user_id']);
    }

    public function testDeletePasswordReset(): void
    {
        $pdo = make_temp_pdo();
        $userId = db_create_user($pdo, ['email' => 'r2@example.com', 'name' => 'R2', 'password_hash' => 'x', 'google_id' => null]);
        $tokenHash = hash('sha256', 'anothertoken');
        db_create_password_reset($pdo, $userId, $tokenHash, date('Y-m-d H:i:s', time() + 3600));

        db_delete_password_reset($pdo, $tokenHash);

        $this->assertNull(db_get_password_reset($pdo, $tokenHash));
    }

    public function testDeletePasswordResetsForUser(): void
    {
        $pdo = make_temp_pdo();
        $userId = db_create_user($pdo, ['email' => 'r3@example.com', 'name' => 'R3', 'password_hash' => 'x', 'google_id' => null]);
        db_create_password_reset($pdo, $userId, hash('sha256', 't1'), date('Y-m-d H:i:s', time() + 3600));
        db_create_password_reset($pdo, $userId, hash('sha256', 't2'), date('Y-m-d H:i:s', time() + 3600));

        db_delete_password_resets_for_user($pdo, $userId);

        $this->assertNull(db_get_password_reset($pdo, hash('sha256', 't1')));
        $this->assertNull(db_get_password_reset($pdo, hash('sha256', 't2')));
    }
}
