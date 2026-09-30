<?php
use PHPUnit\Framework\TestCase;

final class DbTaDriftSchemaTest extends TestCase
{
    private function user(PDO $pdo): int {
        return db_create_user($pdo, ['email' => 'tad' . uniqid() . '@example.com', 'name' => 'TA Driver', 'password_hash' => 'x', 'google_id' => null]);
    }

    public function testFreshDatabaseHasTheColumns(): void
    {
        $pdo = make_temp_pdo();
        foreach ([['event_plans', 'formats'], ['event_plans', 'supps_ack_at'], ['tech_sheets', 'caged'],
                  ['tech_sheets', 'revoke_note'], ['gear_records', 'revoke_note']] as [$table, $column]) {
            $this->assertTrue(db_has_column($pdo, $table, $column), "$table.$column");
        }
    }

    public function testLegacyEntriesReadAsRace(): void
    {
        $pdo = test_make_legacy_pdo();
        $pdo->exec("CREATE TABLE event_plans (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
            event_id INTEGER NOT NULL, car_id INTEGER NOT NULL, created_at DATETIME NOT NULL, UNIQUE (event_id, car_id))");
        $pdo->exec("INSERT INTO event_plans (user_id, event_id, car_id, created_at) VALUES (1, 2, 3, '2026-05-01')");
        db_init($pdo);
        db_init($pdo);
        $row = $pdo->query("SELECT formats, supps_ack_at FROM event_plans")->fetch();
        $this->assertSame(['formats' => 'race', 'supps_ack_at' => null], $row);
    }

    public function testTagReportsWhetherItAdded(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $car = test_make_car($pdo, $uid, '86');
        $event = db_create_event($pdo, 'WSCC TA', '2026-07-12', null, 'summer', 'WSCC');
        $this->assertTrue(db_tag_event($pdo, $uid, $event, $car));
        $this->assertFalse(db_tag_event($pdo, $uid, $event, $car));
        $plan = db_get_user_event_plans($pdo, $uid)[0];
        $this->assertSame('race', $plan['formats']);
        $this->assertArrayHasKey('supps_ack_at', $plan);
    }

    public function testTechRevokeStoresTheNoteAndAcceptClearsIt(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $uid, '42'));
        $event = db_create_event($pdo, 'Spring Sprint', '2026-05-10', null);
        $id = test_make_sheet($pdo, $uid, $sub, $event);

        $this->assertTrue(db_accept_tech_sheet_in_person($pdo, $id, 1, 'sig.png'));
        $this->assertTrue(db_revoke_tech_sheet_acceptance($pdo, $id, 'Car changed: new engine'));
        $this->assertSame('Car changed: new engine', db_get_tech_sheet($pdo, $id)['revoke_note']);
        $this->assertTrue(db_accept_tech_sheet_in_person($pdo, $id, 1, 'sig.png'));
        $this->assertNull(db_get_tech_sheet($pdo, $id)['revoke_note']);

        $this->assertTrue(db_revoke_tech_sheet_acceptance($pdo, $id));   // callers without a note still work
        $this->assertNull(db_get_tech_sheet($pdo, $id)['revoke_note']);
    }

    public function testGearRevokeStoresTheNoteAndAcceptClearsIt(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $gear = db_insert_gear_record($pdo, db_find_or_create_driver($pdo, $uid, 'TA Driver'), 2026);

        $this->assertTrue(db_accept_gear_in_person($pdo, $gear, 1));
        $this->assertTrue(db_revoke_gear_acceptance($pdo, $gear, 'Helmet expired'));
        $this->assertSame('Helmet expired', db_get_gear_record($pdo, $gear)['revoke_note']);
        $this->assertTrue(db_accept_gear_in_person($pdo, $gear, 1));
        $this->assertNull(db_get_gear_record($pdo, $gear)['revoke_note']);
    }

    public function testGearLevelTakesTaDrift(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $gear = db_insert_gear_record($pdo, db_find_or_create_driver($pdo, $uid, 'TA Driver'), 2026);
        db_set_gear_level($pdo, $gear, GEAR_LEVEL_TA_DRIFT);
        $this->assertSame('ta_drift', db_get_gear_record($pdo, $gear)['level']);
        $this->expectException(InvalidArgumentException::class);
        db_set_gear_level($pdo, $gear, 'nonsense');
    }
}
