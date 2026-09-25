<?php
// wcma-calculator/tests/DbTechSheetCarTest.php
use PHPUnit\Framework\TestCase;

final class DbTechSheetCarTest extends TestCase
{
    private function sheetData(int $u, int $subId, int $eventId, string $driver = 'Jordan Lee'): array {
        return [
            'submission_id' => $subId, 'user_id' => $u, 'event_id' => $eventId, 'sheet_type' => 'endurance',
            'entrant_name' => 'Team', 'driver_name' => $driver, 'car_make' => 'Mazda', 'car_model' => 'MX-5',
            'car_colour' => 'Red', 'car_number' => '42', 'class' => 'IT1', 'engine_cc' => null, 'engine_hp' => null,
            'car_weight' => 2200, 'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1,
        ];
    }

    public function testSheetTakesItsDeclarationsCarAndLinksDriverProfiles(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'r@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42'));
        $event = db_create_event($pdo, 'Fall Sprint', '2026-10-11', null);

        $id = db_insert_tech_sheet($pdo, $this->sheetData($u, $sub, $event));
        $sheet = db_get_tech_sheet($pdo, $id);
        $this->assertSame(test_make_car($pdo, $u, '42'), (int)$sheet['car_id']);
        $this->assertSame((int)db_get_self_driver($pdo, $u)['id'], (int)$sheet['driver_id']);

        db_add_tech_sheet_driver($pdo, $id, 2, 'Sam Patel', '{}');
        $row = db_get_tech_sheet_drivers($pdo, $id)[0];
        $this->assertSame((int)db_find_driver($pdo, $u, 'sam patel')['id'], (int)$row['driver_id']);

        db_update_tech_sheet($pdo, $id, $this->sheetData($u, $sub, $event, 'Sam Patel'));
        $this->assertSame((int)$row['driver_id'], (int)db_get_tech_sheet($pdo, $id)['driver_id']);
    }

    public function testIdentitySheetsAreByCarAndSeason(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'r@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $s42 = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42'));
        $s7 = db_insert_submission($pdo, test_declaration_data($pdo, $u, '7'));
        $spring = db_create_event($pdo, 'Spring', '2026-05-10', null);
        $next = db_create_event($pdo, 'Next', '2027-05-10', null);

        $a = db_insert_tech_sheet($pdo, $this->sheetData($u, $s42, $spring));
        $b = db_insert_tech_sheet($pdo, $this->sheetData($u, $s7, $spring));
        $c = db_insert_tech_sheet($pdo, $this->sheetData($u, $s42, $next));
        $ids = fn(array $rows) => array_map(fn($r) => (int)$r['id'], $rows);

        $this->assertSame([$a], $ids(db_get_identity_sheets($pdo, test_make_car($pdo, $u, '42'), 2026)));
        $this->assertSame([$b], $ids(db_get_identity_sheets($pdo, test_make_car($pdo, $u, '7'), 2026)));
        $this->assertSame([$c], $ids(db_get_identity_sheets($pdo, test_make_car($pdo, $u, '42'), 2027)));
    }

    public function testLatestTechSheetIdIsNewestByCreatedAtThenId(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'r@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42'));
        $event = db_create_event($pdo, 'Fall Sprint', '2026-10-11', null);
        $carId = test_make_car($pdo, $u, '42');

        $this->assertNull(db_get_car_latest_tech_sheet_id($pdo, $carId));

        $a = db_insert_tech_sheet($pdo, $this->sheetData($u, $sub, $event));
        $this->assertSame($a, db_get_car_latest_tech_sheet_id($pdo, $carId));

        $b = db_insert_tech_sheet($pdo, $this->sheetData($u, $sub, $event));
        $this->assertSame($b, db_get_car_latest_tech_sheet_id($pdo, $carId));

        $this->assertNull(db_get_car_latest_tech_sheet_id($pdo, $carId + 999));
    }
}
