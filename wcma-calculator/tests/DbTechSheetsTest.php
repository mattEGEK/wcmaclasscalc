<?php
// wcma-calculator/tests/DbTechSheetsTest.php
use PHPUnit\Framework\TestCase;

final class DbTechSheetsTest extends TestCase
{
    private function makeUserAndSubmission(PDO $pdo): array {
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
        $eventId = db_create_event($pdo, 'Spring Sprint', '2026-05-10', null);
        return [$userId, $subId, $eventId];
    }

    private function baseTechSheetData(int $userId, int $subId, int $eventId): array {
        return [
            'submission_id' => $subId, 'user_id' => $userId, 'event_id' => $eventId, 'sheet_type' => 'standard',
            'entrant_name' => 'Racer', 'driver_name' => 'Racer', 'car_make' => 'Mazda', 'car_model' => 'MX-5',
            'car_colour' => 'Red', 'car_number' => '42', 'class' => 'IT1', 'engine_cc' => '1800', 'engine_hp' => '150',
            'car_weight' => 2200, 'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1,
        ];
    }

    public function testInsertAndGetTechSheet(): void
    {
        $pdo = make_temp_pdo();
        [$userId, $subId, $eventId] = $this->makeUserAndSubmission($pdo);

        $id = db_insert_tech_sheet($pdo, $this->baseTechSheetData($userId, $subId, $eventId));

        $sheet = db_get_tech_sheet($pdo, $id);
        $this->assertSame('standard', $sheet['sheet_type']);
        $this->assertSame('submitted', $sheet['status']);
        $this->assertSame($subId, (int)$sheet['submission_id']);
    }

    public function testGetUserTechSheetsOrderedNewestFirst(): void
    {
        $pdo = make_temp_pdo();
        [$userId, $subId, $eventId] = $this->makeUserAndSubmission($pdo);

        $id1 = db_insert_tech_sheet($pdo, $this->baseTechSheetData($userId, $subId, $eventId));
        sleep(1);
        $id2 = db_insert_tech_sheet($pdo, $this->baseTechSheetData($userId, $subId, $eventId));

        $sheets = db_get_user_tech_sheets($pdo, $userId);
        $this->assertCount(2, $sheets);
        $this->assertSame($id2, (int)$sheets[0]['id']);
    }

    public function testUpdateTechSheetChecklist(): void
    {
        $pdo = make_temp_pdo();
        [$userId, $subId, $eventId] = $this->makeUserAndSubmission($pdo);
        $id = db_insert_tech_sheet($pdo, $this->baseTechSheetData($userId, $subId, $eventId));

        db_update_tech_sheet($pdo, $id, array_merge($this->baseTechSheetData($userId, $subId, $eventId), [
            'checklist_json' => '{"steering_linkage":{"status":"ok"}}',
            'log_book_turned_in' => 0,
        ]));

        $sheet = db_get_tech_sheet($pdo, $id);
        $this->assertSame('{"steering_linkage":{"status":"ok"}}', $sheet['checklist_json']);
        $this->assertSame(0, (int)$sheet['log_book_turned_in']);
    }

    public function testUpdateTechSheetSignatures(): void
    {
        $pdo = make_temp_pdo();
        [$userId, $subId, $eventId] = $this->makeUserAndSubmission($pdo);
        $id = db_insert_tech_sheet($pdo, $this->baseTechSheetData($userId, $subId, $eventId));

        db_update_tech_sheet_signatures($pdo, $id, [
            'entrant_signature_path' => 'uploads/tech-sheets/1/entrant.png',
            'driver_signature_path' => 'uploads/tech-sheets/1/driver.png',
        ]);

        $sheet = db_get_tech_sheet($pdo, $id);
        $this->assertSame('uploads/tech-sheets/1/entrant.png', $sheet['entrant_signature_path']);
        $this->assertNotNull($sheet['entrant_signed_at']);
    }

    public function testAddAndGetTechSheetDrivers(): void
    {
        $pdo = make_temp_pdo();
        [$userId, $subId, $eventId] = $this->makeUserAndSubmission($pdo);
        $id = db_insert_tech_sheet($pdo, array_merge($this->baseTechSheetData($userId, $subId, $eventId), ['sheet_type' => 'endurance']));

        db_add_tech_sheet_driver($pdo, $id, 2, 'Co-Driver A', '{"helmet":{"competitor_confirmed":true}}');
        db_add_tech_sheet_driver($pdo, $id, 3, 'Co-Driver B', '{}');

        $drivers = db_get_tech_sheet_drivers($pdo, $id);
        $this->assertCount(2, $drivers);
        $this->assertSame('Co-Driver A', $drivers[0]['driver_name']);
        $this->assertSame(2, (int)$drivers[0]['driver_number']);
    }

    public function testReplaceTechSheetDriversClearsOldRows(): void
    {
        $pdo = make_temp_pdo();
        [$userId, $subId, $eventId] = $this->makeUserAndSubmission($pdo);
        $id = db_insert_tech_sheet($pdo, array_merge($this->baseTechSheetData($userId, $subId, $eventId), ['sheet_type' => 'endurance']));
        db_add_tech_sheet_driver($pdo, $id, 2, 'Stale Driver', '{}');

        db_replace_tech_sheet_drivers($pdo, $id, [
            ['driver_number' => 2, 'driver_name' => 'Fresh Driver', 'equipment_json' => '{}'],
        ]);

        $drivers = db_get_tech_sheet_drivers($pdo, $id);
        $this->assertCount(1, $drivers);
        $this->assertSame('Fresh Driver', $drivers[0]['driver_name']);
    }

    public function testUpdateEmailSentTechSheet(): void
    {
        $pdo = make_temp_pdo();
        [$userId, $subId, $eventId] = $this->makeUserAndSubmission($pdo);
        $id = db_insert_tech_sheet($pdo, $this->baseTechSheetData($userId, $subId, $eventId));

        db_update_email_sent_tech_sheet($pdo, $id, 1);

        $sheet = db_get_tech_sheet($pdo, $id);
        $this->assertSame(1, (int)$sheet['email_sent']);
        $this->assertSame(1, (int)$sheet['email_send_count']);
        $this->assertNotNull($sheet['last_emailed_at']);
    }
}
