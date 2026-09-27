<?php
// wcma-calculator/tests/DbGearIceTest.php
use PHPUnit\Framework\TestCase;

final class DbGearIceTest extends TestCase
{
    private function owner(PDO $pdo): int {
        return db_create_user($pdo, ['email' => 'g' . uniqid() . '@example.com', 'name' => 'Owner', 'password_hash' => 'x', 'google_id' => null]);
    }

    public function testSummerAndIceGearCoexistForTheSameSeason(): void
    {
        $pdo = make_temp_pdo();
        $d = db_create_driver($pdo, $this->owner($pdo), 'Sam');
        $summer = db_insert_gear_record($pdo, $d, 2027);
        $ice = db_insert_gear_record($pdo, $d, 2027, 'ice');
        $this->assertNotSame($summer, $ice);
        $this->assertSame($summer, (int)db_get_gear_record_for_driver($pdo, $d, 2027)['id']);
        $this->assertSame($ice, (int)db_get_gear_record_for_driver($pdo, $d, 2027, 'ice')['id']);
        $this->assertSame('ice', db_get_gear_record($pdo, $ice)['discipline']);
    }

    public function testGearIsUniquePerDiscipline(): void
    {
        $pdo = make_temp_pdo();
        $d = db_create_driver($pdo, $this->owner($pdo), 'Sam');
        db_insert_gear_record($pdo, $d, 2027, 'ice');
        $this->expectException(PDOException::class);
        db_insert_gear_record($pdo, $d, 2027, 'ice');
    }

    public function testFindAndSeasonListingFilterByDiscipline(): void
    {
        $pdo = make_temp_pdo();
        $owner = $this->owner($pdo);
        $d = db_create_driver($pdo, $owner, 'Sam');
        $ice = db_insert_gear_record($pdo, $d, 2027, 'ice');
        $this->assertNull(db_find_gear_record($pdo, $owner, db_driver_name_norm('Sam'), 2027));
        $this->assertSame($ice, (int)db_find_gear_record($pdo, $owner, db_driver_name_norm('Sam'), 2027, 'ice')['id']);
        $this->assertSame([], db_get_gear_records_for_season($pdo, 2027));
        $this->assertCount(1, db_get_gear_records_for_season($pdo, 2027, 'ice'));
    }

    public function testSetGearLevel(): void
    {
        $pdo = make_temp_pdo();
        $id = db_insert_gear_record($pdo, db_create_driver($pdo, $this->owner($pdo), 'Sam'), 2027, 'ice');
        $this->assertNull(db_get_gear_record($pdo, $id)['level']);
        db_set_gear_level($pdo, $id, 'street_safe');
        $this->assertSame('street_safe', db_get_gear_record($pdo, $id)['level']);
        db_set_gear_level($pdo, $id, null);
        $this->assertNull(db_get_gear_record($pdo, $id)['level']);
        $this->expectException(InvalidArgumentException::class);
        db_set_gear_level($pdo, $id, 'bogus');
    }

    public function testLegacyGearMigratesAsSummer(): void
    {
        $pdo = test_make_legacy_pdo();
        $pdo->exec("INSERT INTO gear_records (driver_id, season, status, accepted_via, created_at, updated_at)
                    VALUES (5, 2026, 'accepted', 'in_person', '2026-05-10', '2026-05-10')");
        db_init($pdo);
        db_init($pdo);
        $rows = $pdo->query("SELECT * FROM gear_records")->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame('summer', $rows[0]['discipline']);
        $this->assertSame('accepted', $rows[0]['status']);
        $this->assertNull($rows[0]['level']);
    }
}
