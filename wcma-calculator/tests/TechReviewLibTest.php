<?php
// wcma-calculator/tests/TechReviewLibTest.php
require_once __DIR__ . '/../tech-sheet-files.php';
require_once __DIR__ . '/../tech-review-lib.php';

use PHPUnit\Framework\TestCase;

final class TechReviewLibTest extends TestCase
{
    private string $dir;
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wcma_trl_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
        rmdir($this->dir);
    }

    private function makeSheet(PDO $pdo): array {
        $userId = db_create_user($pdo, ['email' => 'racer@example.com', 'name' => 'Racer', 'password_hash' => 'x', 'google_id' => null]);
        $adminId = db_create_user($pdo, ['email' => 'tech@example.com', 'name' => 'Tech', 'password_hash' => 'x', 'google_id' => null]);
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
        $sheetId = db_insert_tech_sheet($pdo, [
            'submission_id' => $subId, 'user_id' => $userId, 'event_id' => $eventId, 'sheet_type' => 'standard',
            'entrant_name' => 'Racer', 'driver_name' => 'Racer', 'car_make' => 'Mazda', 'car_model' => 'MX-5',
            'car_colour' => 'Red', 'car_number' => '42', 'class' => 'IT1', 'engine_cc' => '1800', 'engine_hp' => '150',
            'car_weight' => 2200, 'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1,
        ]);
        return [$sheetId, $adminId];
    }

    public function testAcceptStoresSignatureAndMarksSheetTeched(): void
    {
        $pdo = make_temp_pdo();
        [$sheetId, $adminId] = $this->makeSheet($pdo);

        $r = techReviewAcceptInPerson($pdo, $this->dir, $sheetId, $adminId, self::PNG);

        $this->assertTrue($r['ok'], (string)$r['error']);
        $row = db_get_tech_sheet($pdo, $sheetId);
        $this->assertSame('teched', $row['status']);
        $this->assertSame('in_person', $row['accepted_via']);
        $this->assertSame($adminId, (int)$row['reviewed_by_user_id']);
        $this->assertSame("uploads/tech-sheets/$sheetId/tech.png", $row['tech_signature_path']);
        $this->assertFileExists($this->dir . '/' . $row['tech_signature_path']);
    }

    public function testAcceptRequiresAValidSignatureAndChangesNothingWithout(): void
    {
        $pdo = make_temp_pdo();
        [$sheetId, $adminId] = $this->makeSheet($pdo);

        foreach (['', 'garbage', 'data:image/jpeg;base64,AAAA'] as $bad) {
            $r = techReviewAcceptInPerson($pdo, $this->dir, $sheetId, $adminId, $bad);
            $this->assertFalse($r['ok']);
            $this->assertStringContainsString('signature', $r['error']);
        }
        $this->assertSame('submitted', db_get_tech_sheet($pdo, $sheetId)['status']);
    }

    public function testAcceptRejectsMissingAndAlreadyAcceptedSheets(): void
    {
        $pdo = make_temp_pdo();
        [$sheetId, $adminId] = $this->makeSheet($pdo);

        $this->assertFalse(techReviewAcceptInPerson($pdo, $this->dir, 99999, $adminId, self::PNG)['ok']);

        $this->assertTrue(techReviewAcceptInPerson($pdo, $this->dir, $sheetId, $adminId, self::PNG)['ok']);
        $firstBytes = file_get_contents($this->dir . "/uploads/tech-sheets/$sheetId/tech.png");

        $second = techReviewAcceptInPerson($pdo, $this->dir, $sheetId, $adminId, self::PNG);
        $this->assertFalse($second['ok']);
        $this->assertStringContainsString('already', $second['error']);
        $this->assertSame($firstBytes, file_get_contents($this->dir . "/uploads/tech-sheets/$sheetId/tech.png"));
    }

    public function testRevokeReturnsSheetToSubmittedAndRemovesSignatureFile(): void
    {
        $pdo = make_temp_pdo();
        [$sheetId, $adminId] = $this->makeSheet($pdo);
        techReviewAcceptInPerson($pdo, $this->dir, $sheetId, $adminId, self::PNG);
        $file = $this->dir . "/uploads/tech-sheets/$sheetId/tech.png";
        $this->assertFileExists($file);

        $r = techReviewRevoke($pdo, $this->dir, $sheetId);

        $this->assertTrue($r['ok'], (string)$r['error']);
        $this->assertSame('submitted', db_get_tech_sheet($pdo, $sheetId)['status']);
        $this->assertFileDoesNotExist($file);
    }

    public function testRevokeRejectsMissingAndUnacceptedSheets(): void
    {
        $pdo = make_temp_pdo();
        [$sheetId] = $this->makeSheet($pdo);

        $this->assertFalse(techReviewRevoke($pdo, $this->dir, 99999)['ok']);
        $r = techReviewRevoke($pdo, $this->dir, $sheetId);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('not been accepted', $r['error']);
    }

    public function testMessagesAvoidApprovalWording(): void
    {
        $pdo = make_temp_pdo();
        [$sheetId, $adminId] = $this->makeSheet($pdo);
        $messages = [
            techReviewAcceptInPerson($pdo, $this->dir, 99999, $adminId, self::PNG)['error'],
            techReviewAcceptInPerson($pdo, $this->dir, $sheetId, $adminId, '')['error'],
            techReviewRevoke($pdo, $this->dir, $sheetId)['error'],
        ];
        foreach ($messages as $m) {
            $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', $m);
        }
    }
}
