<?php
// wcma-calculator/tests/DbDriversTest.php
use PHPUnit\Framework\TestCase;

final class DbDriversTest extends TestCase
{
    public function testNewAccountGetsASelfDriverProfile(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'j@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);

        $self = db_get_self_driver($pdo, $u);
        $this->assertNotNull($self);
        $this->assertSame('Jordan Lee', $self['name']);
        $this->assertSame('jordan lee', $self['name_norm']);
        $this->assertSame($u, (int)$self['owner_user_id']);
        $this->assertSame($u, (int)$self['user_id']);
    }

    public function testFindOrCreateMatchesNormalisedNameWithinOwner(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'j@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $other = db_create_user($pdo, ['email' => 'o@example.com', 'name' => 'Other Person', 'password_hash' => 'x', 'google_id' => null]);

        $self = (int)db_get_self_driver($pdo, $u)['id'];
        $this->assertSame($self, db_find_or_create_driver($pdo, $u, '  jordan   LEE '));

        $sam = db_find_or_create_driver($pdo, $u, 'Sam Patel');
        $this->assertNotSame($self, $sam);
        $this->assertSame($sam, db_find_or_create_driver($pdo, $u, 'sam patel'));
        $this->assertNotSame($sam, db_find_or_create_driver($pdo, $other, 'Sam Patel'));
        $this->assertNull(db_find_or_create_driver($pdo, $u, '   '));
        $this->assertNull(db_get_driver($pdo, $sam)['user_id']);
    }

    public function testUserDriversListSelfFirstThenByName(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'j@example.com', 'name' => 'Zed Racer', 'password_hash' => 'x', 'google_id' => null]);
        db_create_driver($pdo, $u, 'Bob Co');
        db_create_driver($pdo, $u, 'Amy Co', 'WCMA-1');

        $this->assertSame(['Zed Racer', 'Amy Co', 'Bob Co'], array_column(db_get_user_drivers($pdo, $u), 'name'));
    }

    public function testDuplicateNameForSameOwnerIsRejectedAndLicenceUpdates(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'j@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $id = db_create_driver($pdo, $u, 'Sam Patel');
        db_update_driver_licence($pdo, $id, 'WCMA-9');
        $this->assertSame('WCMA-9', db_get_driver($pdo, $id)['licence_no']);

        $this->expectException(PDOException::class);
        db_create_driver($pdo, $u, 'sam  patel');
    }

    public function testNewHubTablesExist(): void
    {
        $pdo = make_temp_pdo();
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['cars', 'drivers', 'event_plans', 'season_links'] as $t) {
            $this->assertContains($t, $tables, $t);
        }
        $userCols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
        $this->assertContains('reminder_emails', $userCols);
    }
}
