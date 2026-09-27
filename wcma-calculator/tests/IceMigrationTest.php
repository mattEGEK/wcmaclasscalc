<?php
// wcma-calculator/tests/IceMigrationTest.php
use PHPUnit\Framework\TestCase;

final class IceMigrationTest extends TestCase
{
    public function testFullLegacyDatabaseUpgradesAndSummerStatusIsUnchanged(): void
    {
        $pdo = test_make_legacy_pdo();
        $pdo->exec("INSERT INTO events (name, event_date, created_at) VALUES ('Old', '2026-05-10', '2026-01-01')");
        $pdo->exec("INSERT INTO tech_sheets (submission_id, car_id, user_id, event_id, sheet_type, entrant_name, driver_name,
            car_make, car_model, car_colour, car_number, class, car_weight, checklist_json, driver1_equipment_json,
            status, photo_status, season, created_at, updated_at)
            VALUES (9, 3, 1, 1, 'standard', 'A', 'A', 'Mazda', 'MX-5', 'Red', '42', 'IT1', 2200, '{}', '{}',
            'submitted', 'submitted', 2026, '2026-05-10', '2026-05-10')");
        $pdo->exec("INSERT INTO gear_records (driver_id, season, created_at, updated_at) VALUES (5, 2026, '2026-05-10', '2026-05-10')");
        $pdo->exec("INSERT INTO at_track_choices (subject_type, subject_id, season, created_at) VALUES ('driver', 5, 2026, '2026-05-01')");

        $before = techCarStatus($pdo->query("SELECT * FROM tech_sheets")->fetchAll());
        db_init($pdo);
        db_init($pdo);

        foreach (['tech_sheets', 'gear_records', 'at_track_choices', 'events'] as $table) {
            $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn(), $table);
            $this->assertSame('summer', $pdo->query("SELECT discipline FROM $table")->fetchColumn(), $table);
        }
        $this->assertSame($before, techCarStatus(db_get_identity_sheets($pdo, 3, 2026)));
        $this->assertSame(['driver:5@2026'], db_get_at_track_keys($pdo, [], [5], 2026));
        // Skip checking gear_record with driver JOIN since orphaned driver_id refs don't have matching drivers
        $this->assertEqualsCanonicalizing([1], array_column($pdo->query("SELECT * FROM gear_records")->fetchAll(), 'id'));
        foreach (['tech_sheets__rebuild', 'gear_records__rebuild', 'at_track_choices__rebuild'] as $tmp) {
            $this->assertFalse(db_has_column($pdo, $tmp, 'id'), "$tmp left behind");
        }
    }

    public function testFreshDatabaseHasNewSchemaWithoutRebuilding(): void
    {
        $pdo = make_temp_pdo();
        foreach (['tech_sheets', 'gear_records', 'at_track_choices', 'events'] as $table) {
            $this->assertTrue(db_has_column($pdo, $table, 'discipline'), $table);
        }
        $this->assertFalse(db_rebuild_table($pdo, 'tech_sheets', 'discipline', DB_TECH_SHEETS_SQL));
    }
}
