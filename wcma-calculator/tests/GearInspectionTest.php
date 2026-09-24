<?php
// wcma-calculator/tests/GearInspectionTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../gear-lib.php';

use PHPUnit\Framework\TestCase;

final class GearInspectionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wcma_gi_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
        rmdir($this->dir);
    }

    private function tmpJpeg(): string {
        $p = $this->dir . '/' . uniqid('up_') . '.bin';
        file_put_contents($p, hex2bin('ffd8ffc00011080001000103011100021100031100ffd9'));
        return $p;
    }

    private function gear(PDO $pdo): int {
        $owner = db_create_user($pdo, ['email' => 'captain@example.com', 'name' => 'Captain', 'password_hash' => 'x', 'google_id' => null]);
        return gearCreate($pdo, $owner, 'Jane Racer', '', 2026)['id'];
    }

    public function testSubjectScopeIncludesGearRecords(): void
    {
        $this->assertSame('gear', INSPECTION_SUBJECT_SCOPE['gear_record']);
        $this->assertSame('car', INSPECTION_SUBJECT_SCOPE['tech_sheet']);
    }

    public function testSavingAGearPhotoStoresItUnderTheGearFolderAndMarksTheRecordDraft(): void
    {
        $pdo = make_temp_pdo();
        $id = $this->gear($pdo);

        $r = inspectionSavePhoto($pdo, $this->dir, 'gear_record', $id, 'helmet_label', $this->tmpJpeg(), ['standard' => 'SA2020', 'date' => '03/2024'], 'rename');

        $this->assertTrue($r['ok'], (string)$r['error']);
        $this->assertSame("uploads/inspection/gear_record/$id/helmet_label.jpg", $r['photo']['file_path']);
        $this->assertFileExists($this->dir . '/' . $r['photo']['file_path']);
        $this->assertSame('draft', db_get_gear_record($pdo, $id)['photo_status']);
    }

    public function testGearSubjectsOnlyAcceptGearRequirements(): void
    {
        $pdo = make_temp_pdo();
        $id = $this->gear($pdo);

        $car = inspectionSavePhoto($pdo, $this->dir, 'gear_record', $id, 'front_34', $this->tmpJpeg(), [], 'rename');
        $this->assertFalse($car['ok']);
        $this->assertStringContainsString('Unknown photo type', $car['error']);

        $gearOnCarSubject = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 7, 'helmet_label', $this->tmpJpeg(), [], 'rename');
        $this->assertFalse($gearOnCarSubject['ok']);
    }

    public function testAppliesForGearConditionalMarksDraftAndRefusesRequiredKeys(): void
    {
        $pdo = make_temp_pdo();
        $id = $this->gear($pdo);

        $this->assertTrue(inspectionSetApplies($pdo, $this->dir, 'gear_record', $id, 'underwear_label', true)['ok']);
        $this->assertSame('draft', db_get_gear_record($pdo, $id)['photo_status']);
        $this->assertArrayHasKey('underwear_label', db_get_inspection_photos($pdo, 'gear_record', $id));

        $required = inspectionSetApplies($pdo, $this->dir, 'gear_record', $id, 'helmet_label', true);
        $this->assertFalse($required['ok']);
        $this->assertStringContainsString('not optional', $required['error']);

        $this->assertTrue(inspectionSetApplies($pdo, $this->dir, 'gear_record', $id, 'underwear_label', false)['ok']);
        $this->assertArrayNotHasKey('underwear_label', db_get_inspection_photos($pdo, 'gear_record', $id));
    }

    public function testTypedUpdateWorksOnGearPhotos(): void
    {
        $pdo = make_temp_pdo();
        $id = $this->gear($pdo);
        $saved = inspectionSavePhoto($pdo, $this->dir, 'gear_record', $id, 'suit_label', $this->tmpJpeg(), [], 'rename');

        $r = inspectionUpdateTyped($pdo, (int)$saved['photo']['id'], ['rating' => 'SFI 3.2A/5']);
        $this->assertTrue($r['ok'], (string)$r['error']);
        $this->assertSame(['rating' => 'SFI 3.2A/5'], $r['photo']['typed']);
    }
}
