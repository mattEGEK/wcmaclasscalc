<?php
use PHPUnit\Framework\TestCase;

final class DbUsersTest extends TestCase
{
    public function testCreateAndFindUserByEmail(): void
    {
        $pdo = make_temp_pdo();
        $id = db_create_user($pdo, [
            'email' => 'racer@example.com',
            'name' => 'Racer McRace',
            'password_hash' => password_hash('secret123', PASSWORD_BCRYPT),
            'google_id' => null,
        ]);

        $this->assertIsInt($id);
        $user = db_find_user_by_email($pdo, 'racer@example.com');
        $this->assertNotNull($user);
        $this->assertSame('Racer McRace', $user['name']);
        $this->assertSame('user', $user['role']);
        $this->assertNull($user['google_id']);
    }

    public function testBootstrapAdminEmailGetsAdminRole(): void
    {
        $pdo = make_temp_pdo();
        $id = db_create_user($pdo, [
            'email' => 'Matt.Sinfield@gmail.com', // case-insensitive match
            'name' => 'Matt Sinfield',
            'password_hash' => password_hash('secret123', PASSWORD_BCRYPT),
            'google_id' => null,
        ]);

        $user = db_find_user_by_id($pdo, $id);
        $this->assertSame('admin', $user['role']);
    }

    public function testFindUserByGoogleId(): void
    {
        $pdo = make_temp_pdo();
        db_create_user($pdo, [
            'email' => 'g@example.com',
            'name' => 'Google User',
            'password_hash' => null,
            'google_id' => 'g-12345',
        ]);

        $user = db_find_user_by_google_id($pdo, 'g-12345');
        $this->assertNotNull($user);
        $this->assertSame('g@example.com', $user['email']);
    }

    public function testSetUserRoleAndCountAdmins(): void
    {
        $pdo = make_temp_pdo();
        $id1 = db_create_user($pdo, ['email' => 'a@example.com', 'name' => 'A', 'password_hash' => 'x', 'google_id' => null]);
        db_create_user($pdo, ['email' => 'b@example.com', 'name' => 'B', 'password_hash' => 'x', 'google_id' => null]);

        $this->assertSame(0, db_count_admins($pdo));
        db_set_user_role($pdo, $id1, 'admin');
        $this->assertSame(1, db_count_admins($pdo));
    }

    public function testLinkGoogleIdToExistingUser(): void
    {
        $pdo = make_temp_pdo();
        $id = db_create_user($pdo, ['email' => 'link@example.com', 'name' => 'Link', 'password_hash' => 'x', 'google_id' => null]);
        db_link_google_id($pdo, $id, 'g-99999');

        $user = db_find_user_by_google_id($pdo, 'g-99999');
        $this->assertSame($id, $user['id']);
    }

    public function testGetAllUsersOrderedByCreatedAt(): void
    {
        $pdo = make_temp_pdo();
        db_create_user($pdo, ['email' => 'first@example.com', 'name' => 'First', 'password_hash' => 'x', 'google_id' => null]);
        db_create_user($pdo, ['email' => 'second@example.com', 'name' => 'Second', 'password_hash' => 'x', 'google_id' => null]);

        $users = db_get_all_users($pdo);
        $this->assertCount(2, $users);
        $this->assertSame('first@example.com', $users[0]['email']);
    }
}
