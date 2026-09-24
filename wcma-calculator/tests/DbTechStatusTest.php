<?php
// wcma-calculator/tests/DbTechStatusTest.php
use PHPUnit\Framework\TestCase;

final class DbTechStatusTest extends TestCase
{
    private function fixture(PDO $pdo): array {
        $userId = db_create_user($pdo, ['email' => 'racer@example.com', 'name' => 'Racer', 'password_hash' => 'x', 'google_id' => null]);
        $subId = db_insert_submission($pdo, [
            ':submitted_at' => date('Y-m-d H:i:s'), ':name' => 'Racer', ':email' => 'racer@example.com',
            ':year' => '2020', ':make' => 'Mazda', ':model' => 'MX-5', ':comments' => null,
            ':competition_weight' => 2200, ':declared_hp' => 150, ':dyno_hp' => null,
            ':chassis_display' => null, ':body_mods_display' => null, ':transmission_display' => null,
            ':drivetrain_display' => null, ':tires_display' => null, ':brake_suspension' => null,
            ':chassis_value' => 0, ':body_mods_value' => 0, ':transmission_value' => 0,
            ':drivetrain_value' => 0, ':tires_value' => 0, ':brake_suspension_value' => 0,
            ':weight_factor' => 0, ':modification_factor' => 0, ':base_ratio' => 14.67, ':modified_ratio' => 14.67,
            ':calculated_class' => 'IT1', ':user_id' => $userId,
        ]);
        $spring = db_create_event($pdo, 'Spring Sprint', '2026-05-10', null);
        $fall = db_create_event($pdo, 'Fall Finale', '2026-10-04', null);
        $next = db_create_event($pdo, 'Next Year Opener', '2027-04-18', null);
        return [$userId, $subId, $spring, $fall, $next];
    }

    private function sheet(int $userId, int $subId, int $eventId, string $number = '42'): array {
        return [
            'submission_id' => $subId, 'user_id' => $userId, 'event_id' => $eventId, 'sheet_type' => 'standard',
            'entrant_name' => 'Racer', 'driver_name' => 'Racer', 'car_make' => 'Mazda', 'car_model' => 'MX-5',
            'car_colour' => 'Red', 'car_number' => $number, 'class' => 'IT1', 'engine_cc' => '1800', 'engine_hp' => '150',
            'car_weight' => 2200, 'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1,
        ];
    }

    public function testInsertStoresNormalisedNumberAndSeason(): void
    {
        $pdo = make_temp_pdo();
        [$u, $s, $spring, , $next] = $this->fixture($pdo);

        $id = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $spring, ' 042 '));
        $row = db_get_tech_sheet($pdo, $id);
        $this->assertSame('42', $row['car_number_norm']);
        $this->assertSame(2026, (int)$row['season']);

        $id2 = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $next, '42'));
        $this->assertSame(2027, (int)db_get_tech_sheet($pdo, $id2)['season']);
    }

    public function testUpdateRecomputesIdentity(): void
    {
        $pdo = make_temp_pdo();
        [$u, $s, $spring, , $next] = $this->fixture($pdo);
        $id = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $spring, '42'));

        $data = $this->sheet($u, $s, $next, '07');
        db_update_tech_sheet($pdo, $id, $data);

        $row = db_get_tech_sheet($pdo, $id);
        $this->assertSame('7', $row['car_number_norm']);
        $this->assertSame(2027, (int)$row['season']);
    }

    public function testMigrationBackfillsExistingRows(): void
    {
        $pdo = make_temp_pdo();
        [$u, $s, $spring] = $this->fixture($pdo);
        $id = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $spring, '042'));
        $pdo->exec("UPDATE tech_sheets SET car_number_norm = NULL, season = NULL WHERE id = $id");

        db_init($pdo);   // idempotent; backfills rows that predate the columns

        $row = db_get_tech_sheet($pdo, $id);
        $this->assertSame('42', $row['car_number_norm']);
        $this->assertSame(2026, (int)$row['season']);
        $this->assertNull($row['accepted_via']);
        $this->assertNull($row['photo_status']);
    }

    public function testAcceptInPersonOnlyFromSubmittedAndOnlyOnce(): void
    {
        $pdo = make_temp_pdo();
        [$u, $s, $spring] = $this->fixture($pdo);
        $admin = db_create_user($pdo, ['email' => 'tech@example.com', 'name' => 'Tech', 'password_hash' => 'x', 'google_id' => null]);
        $id = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $spring));

        $this->assertTrue(db_accept_tech_sheet_in_person($pdo, $id, $admin, 'uploads/tech-sheets/1/tech.png'));

        $row = db_get_tech_sheet($pdo, $id);
        $this->assertSame('teched', $row['status']);
        $this->assertSame('in_person', $row['accepted_via']);
        $this->assertSame($admin, (int)$row['reviewed_by_user_id']);
        $this->assertNotNull($row['reviewed_at']);
        $this->assertSame('uploads/tech-sheets/1/tech.png', $row['tech_signature_path']);
        $this->assertNotNull($row['tech_signed_at']);

        $this->assertFalse(db_accept_tech_sheet_in_person($pdo, $id, $admin, 'other.png'));
        $this->assertSame('uploads/tech-sheets/1/tech.png', db_get_tech_sheet($pdo, $id)['tech_signature_path']);
        $this->assertFalse(db_accept_tech_sheet_in_person($pdo, 99999, $admin, 'x.png'));
    }

    public function testRevokeReturnsSheetToSubmittedAndClearsReviewFields(): void
    {
        $pdo = make_temp_pdo();
        [$u, $s, $spring] = $this->fixture($pdo);
        $id = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $spring));

        $this->assertFalse(db_revoke_tech_sheet_acceptance($pdo, $id));   // not accepted yet

        db_accept_tech_sheet_in_person($pdo, $id, $u, 'sig.png');
        $this->assertTrue(db_revoke_tech_sheet_acceptance($pdo, $id));

        $row = db_get_tech_sheet($pdo, $id);
        $this->assertSame('submitted', $row['status']);
        foreach (['accepted_via', 'reviewed_by_user_id', 'reviewed_at', 'tech_signature_path', 'tech_signed_at'] as $col) {
            $this->assertNull($row[$col], $col);
        }
    }

    public function testIdentityAndSeasonQueries(): void
    {
        $pdo = make_temp_pdo();
        [$u, $s, $spring, $fall, $next] = $this->fixture($pdo);
        $a = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $spring, '42'));
        $b = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $fall, '042'));
        $c = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $fall, '7'));
        $d = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $next, '42'));

        $ids = fn(array $rows) => array_map(fn($r) => (int)$r['id'], $rows);

        $this->assertEqualsCanonicalizing([$a, $b], $ids(db_get_identity_sheets($pdo, $u, '42', 2026)));
        $this->assertSame([$d], $ids(db_get_identity_sheets($pdo, $u, '42', 2027)));
        $this->assertSame([], db_get_identity_sheets($pdo, $u + 1, '42', 2026));
        $this->assertEqualsCanonicalizing([$a, $b, $c], $ids(db_get_season_sheets($pdo, 2026)));
    }

    public function testEventTechSheetsIncludeEventInfoAndOrderByCarNumber(): void
    {
        $pdo = make_temp_pdo();
        [$u, $s, $spring, $fall] = $this->fixture($pdo);
        $n10 = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $fall, '10'));
        $n9  = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $fall, '9'));
        db_insert_tech_sheet($pdo, $this->sheet($u, $s, $spring, '1'));

        $rows = db_get_event_tech_sheets($pdo, $fall);
        $this->assertSame([$n9, $n10], array_map(fn($r) => (int)$r['id'], $rows));
        $this->assertSame('Fall Finale', $rows[0]['event_name']);
        $this->assertSame('2026-10-04', $rows[0]['event_date']);

        $all = db_get_event_tech_sheets($pdo, 0);
        $this->assertCount(3, $all);
        $this->assertSame('Fall Finale', $all[0]['event_name']);   // newest event first
    }
}
