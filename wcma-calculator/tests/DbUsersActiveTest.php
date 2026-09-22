<?php
use PHPUnit\Framework\TestCase;

final class DbUsersActiveTest extends TestCase
{
    public function testNewUserIsActiveByDefault(): void {
        $pdo = make_temp_pdo();
        $id = db_create_user($pdo, ['email' => 'a@example.com', 'name' => 'A', 'password_hash' => 'x', 'google_id' => null]);
        $user = db_find_user_by_id($pdo, $id);
        $this->assertSame(1, (int)$user['active']);
    }

    public function testSetUserActiveTogglesFlag(): void {
        $pdo = make_temp_pdo();
        $id = db_create_user($pdo, ['email' => 'a@example.com', 'name' => 'A', 'password_hash' => 'x', 'google_id' => null]);

        db_set_user_active($pdo, $id, false);
        $this->assertSame(0, (int)db_find_user_by_id($pdo, $id)['active']);

        db_set_user_active($pdo, $id, true);
        $this->assertSame(1, (int)db_find_user_by_id($pdo, $id)['active']);
    }

    public function testCountActiveAdminsExcludesDeactivated(): void {
        $pdo = make_temp_pdo();
        $id1 = db_create_user($pdo, ['email' => 'a1@example.com', 'name' => 'A1', 'password_hash' => 'x', 'google_id' => null]);
        $id2 = db_create_user($pdo, ['email' => 'a2@example.com', 'name' => 'A2', 'password_hash' => 'x', 'google_id' => null]);
        db_set_user_role($pdo, $id1, 'admin');
        db_set_user_role($pdo, $id2, 'admin');
        $this->assertSame(2, db_count_active_admins($pdo));

        db_set_user_active($pdo, $id2, false);
        $this->assertSame(1, db_count_active_admins($pdo));
    }
}
