<?php
// wcma-calculator/tests/DbTechSheetsIceTest.php
use PHPUnit\Framework\TestCase;

final class DbTechSheetsIceTest extends TestCase
{
    private function user(PDO $pdo): int {
        return db_create_user($pdo, ['email' => 'ice' . uniqid() . '@example.com', 'name' => 'Ice Racer', 'password_hash' => 'x', 'google_id' => null]);
    }

    public function testIceSheetNeedsNoDeclarationAndGetsIceIdentity(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $car = test_make_car($pdo, $uid, '7');
        $event = db_create_event($pdo, 'NASCC Ice #1', '2026-12-12', null, 'ice', 'NASCC');

        $sheet = db_get_tech_sheet($pdo, test_make_ice_sheet($pdo, $uid, $car, $event));
        $this->assertNull($sheet['submission_id']);
        $this->assertSame($car, (int)$sheet['car_id']);
        $this->assertSame('ice', $sheet['discipline']);
        $this->assertSame('NASCC', $sheet['club']);
        $this->assertSame(2027, (int)$sheet['season']);
        $this->assertSame('LS', $sheet['class']);
    }

    public function testSummerSheetWithoutDeclarationIsStillRejected(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $car = test_make_car($pdo, $uid, '7');
        $event = db_create_event($pdo, 'Spring Sprint', '2026-05-10', null);
        $this->expectException(InvalidArgumentException::class);
        test_make_ice_sheet($pdo, $uid, $car, $event);   // summer event, no submission_id
    }

    public function testIceSheetRejectsADeclaration(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $uid, '7'));
        $event = db_create_event($pdo, 'NASCC Ice #1', '2026-12-12', null, 'ice', 'NASCC');
        $this->expectException(InvalidArgumentException::class);
        test_make_sheet($pdo, $uid, $sub, $event, '7');
    }

    public function testIceSheetRejectsSomeoneElsesCar(): void
    {
        $pdo = make_temp_pdo();
        $owner = $this->user($pdo);
        $other = $this->user($pdo);
        $car = test_make_car($pdo, $owner, '7');
        $event = db_create_event($pdo, 'NASCC Ice #1', '2026-12-12', null, 'ice', 'NASCC');
        $this->expectException(InvalidArgumentException::class);
        test_make_ice_sheet($pdo, $other, $car, $event);
    }

    public function testSummerAndIceSheetsSameYearDoNotMerge(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $uid, '7'));
        $car = test_make_car($pdo, $uid, '7');
        $summer = db_create_event($pdo, 'Fall Sprint', '2027-09-12', null);
        $ice = db_create_event($pdo, 'NASCC Ice #3', '2027-02-01', null, 'ice', 'NASCC');
        $s = test_make_sheet($pdo, $uid, $sub, $summer, '7');
        $i = test_make_ice_sheet($pdo, $uid, $car, $ice);

        $ids = fn(array $rows): array => array_map(fn($r) => (int)$r['id'], $rows);
        $this->assertSame([$s], $ids(db_get_identity_sheets($pdo, $car, 2027)));
        $this->assertSame([$i], $ids(db_get_identity_sheets($pdo, $car, 2027, 'ice', 'NASCC')));
        $this->assertSame([], db_get_identity_sheets($pdo, $car, 2027, 'ice', 'WSCC'));
        $this->assertSame([$s], $ids(db_get_season_sheets($pdo, 2027)));
        $this->assertSame([$i], $ids(db_get_season_sheets($pdo, 2027, 'ice')));
        $this->assertSame([$i], $ids(db_get_sheet_identity_sheets($pdo, db_get_tech_sheet($pdo, $i))));
        $this->assertSame([$s], $ids(db_get_sheet_identity_sheets($pdo, db_get_tech_sheet($pdo, $s))));
    }

    public function testNasccAndWsccSheetsDoNotMerge(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $car = test_make_car($pdo, $uid, '7');
        $n = test_make_ice_sheet($pdo, $uid, $car, db_create_event($pdo, 'NASCC', '2027-01-10', null, 'ice', 'NASCC'));
        $w = test_make_ice_sheet($pdo, $uid, $car, db_create_event($pdo, 'WSCC', '2027-01-18', null, 'ice', 'WSCC'), 'FOI-STD');
        $this->assertNotSame(techCarKey(db_get_tech_sheet($pdo, $n)), techCarKey(db_get_tech_sheet($pdo, $w)));
    }

    public function testSheetCannotMoveBetweenDisciplines(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $car = test_make_car($pdo, $uid, '7');
        $ice = db_create_event($pdo, 'NASCC Ice #1', '2026-12-12', null, 'ice', 'NASCC');
        $summer = db_create_event($pdo, 'Spring Sprint', '2027-05-10', null);
        $id = test_make_ice_sheet($pdo, $uid, $car, $ice);
        $data = db_get_tech_sheet($pdo, $id);
        $data['event_id'] = $summer;
        $this->expectException(InvalidArgumentException::class);
        db_update_tech_sheet($pdo, $id, $data);
    }

    public function testLegacyDatabaseMigratesTechSheetsAsSummer(): void
    {
        $pdo = test_make_legacy_pdo();
        $pdo->exec("INSERT INTO events (name, event_date, created_at) VALUES ('Old', '2026-05-10', '2026-01-01')");
        $pdo->exec("INSERT INTO tech_sheets (submission_id, car_id, user_id, event_id, sheet_type, entrant_name, driver_name,
            car_make, car_model, car_colour, car_number, class, car_weight, checklist_json, driver1_equipment_json,
            status, accepted_via, season, created_at, updated_at)
            VALUES (9, 3, 1, 1, 'standard', 'A', 'A', 'Mazda', 'MX-5', 'Red', '42', 'IT1', 2200, '{}', '{}',
            'teched', 'in_person', 2026, '2026-05-10', '2026-05-10')");

        db_init($pdo);
        db_init($pdo);   // second run does nothing

        $rows = $pdo->query("SELECT * FROM tech_sheets")->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame(9, (int)$rows[0]['submission_id']);
        $this->assertSame('summer', $rows[0]['discipline']);
        $this->assertNull($rows[0]['club']);
        $this->assertSame('accepted', techCarStatus(db_get_identity_sheets($pdo, 3, 2026))['state']);
        $this->assertSame('summer', db_get_event($pdo, 1)['discipline']);
    }
}
