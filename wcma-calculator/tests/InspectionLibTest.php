<?php
// wcma-calculator/tests/InspectionLibTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../gear-lib.php';

use PHPUnit\Framework\TestCase;

final class InspectionLibTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wcma_insp_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
        rmdir($this->dir);
    }

    private function tmpFile(string $bytes): string {
        $p = $this->dir . '/' . uniqid('up_') . '.bin';
        file_put_contents($p, $bytes);
        return $p;
    }

    private function jpeg(): string {
        // Minimal JPEG (SOI + SOF0 declaring 1x1 + EOI); getimagesize() reads the header.
        return hex2bin('ffd8ffc00011080001000103011100021100031100ffd9');
    }

    private function png(): string {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    public function testValidateImageAcceptsJpegAndPng(): void
    {
        $r = inspectionValidateImage($this->tmpFile($this->jpeg()));
        $this->assertTrue($r['ok']);
        $this->assertSame('image/jpeg', $r['mime']);
        $this->assertSame('jpg', $r['ext']);

        $r = inspectionValidateImage($this->tmpFile($this->png()));
        $this->assertTrue($r['ok']);
        $this->assertSame('png', $r['ext']);
    }

    public function testValidateImageRejectsNonImagesAndOversize(): void
    {
        $this->assertFalse(inspectionValidateImage($this->tmpFile('not an image'))['ok']);
        $this->assertFalse(inspectionValidateImage($this->tmpFile(''))['ok']);
        $this->assertFalse(inspectionValidateImage($this->dir . '/missing.bin')['ok']);

        $big = $this->jpeg() . str_repeat('x', INSPECTION_MAX_BYTES);
        $this->assertFalse(inspectionValidateImage($this->tmpFile($big))['ok']);
    }

    public function testValidateImageRejectsHugeDimensions(): void
    {
        // Same minimal JPEG but declaring 5000x5000.
        $huge = hex2bin('ffd8ffc000110800' . '1388' . '1388' . '03011100021100031100ffd9');
        $r = inspectionValidateImage($this->tmpFile($huge));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('too large', $r['error']);
    }

    public function testRelativePath(): void
    {
        $this->assertSame('uploads/inspection/tech_sheet/7/front_34.jpg', inspectionPhotoRelativePath('tech_sheet', 7, 'front_34', 'jpg'));
    }

    public function testCanAccess(): void
    {
        $owner = ['id' => 5, 'role' => 'user'];
        $other = ['id' => 6, 'role' => 'user'];
        $admin = ['id' => 1, 'role' => 'admin'];
        $open = ['user_id' => 5, 'status' => 'submitted'];
        $teched = ['user_id' => 5, 'status' => 'teched'];

        $this->assertTrue(inspectionCanAccess($owner, $open, true));
        $this->assertTrue(inspectionCanAccess($owner, $open, false));
        $this->assertFalse(inspectionCanAccess($other, $open, false));
        $this->assertFalse(inspectionCanAccess($other, $open, true));
        $this->assertTrue(inspectionCanAccess($admin, $open, true));

        $this->assertTrue(inspectionCanAccess($owner, $teched, false));
        $this->assertFalse(inspectionCanAccess($owner, $teched, true));
        $this->assertTrue(inspectionCanAccess($admin, $teched, true));
    }

    public function testSavePhotoStoresFileAndRow(): void
    {
        $pdo = make_temp_pdo();
        $tmp = $this->tmpFile($this->jpeg());

        $r = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 7, 'harness_date', $tmp, ['date' => '05/2025'], 'rename');

        $this->assertTrue($r['ok'], (string)$r['error']);
        $this->assertSame('uploads/inspection/tech_sheet/7/harness_date.jpg', $r['photo']['file_path']);
        $this->assertFileExists($this->dir . '/' . $r['photo']['file_path']);
        $this->assertSame(['date' => '05/2025'], json_decode($r['photo']['typed_value'], true));
        $this->assertSame(PHOTO_REQUIREMENTS_VERSION, (int)$r['photo']['requirement_version']);
    }

    public function testSavePhotoRejectsBadInput(): void
    {
        $pdo = make_temp_pdo();
        $good = fn() => $this->tmpFile($this->jpeg());

        $r = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 7, 'not_a_key', $good(), [], 'rename');
        $this->assertFalse($r['ok']);

        $r = inspectionSavePhoto($pdo, $this->dir, 'gear_record', 7, 'front_34', $good(), [], 'rename');
        $this->assertFalse($r['ok']);

        // Gear requirement on a car subject: wrong scope.
        $r = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 7, 'helmet_label', $good(), [], 'rename');
        $this->assertFalse($r['ok']);

        $r = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 7, 'harness_date', $good(), ['date' => '2025-05'], 'rename');
        $this->assertFalse($r['ok']);

        $r = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 7, 'front_34', $this->tmpFile('junk'), [], 'rename');
        $this->assertFalse($r['ok']);

        $this->assertSame([], db_get_inspection_photos($pdo, 'tech_sheet', 7));
    }

    public function testRetakeWithDifferentFormatRemovesOldFile(): void
    {
        $pdo = make_temp_pdo();
        $first = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 7, 'front_34', $this->tmpFile($this->jpeg()), [], 'rename');
        $oldAbs = $this->dir . '/' . $first['photo']['file_path'];
        $this->assertFileExists($oldAbs);

        $second = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 7, 'front_34', $this->tmpFile($this->png()), [], 'rename');
        $this->assertTrue($second['ok']);
        $this->assertSame('uploads/inspection/tech_sheet/7/front_34.png', $second['photo']['file_path']);
        $this->assertFileDoesNotExist($oldAbs);
        $this->assertFileExists($this->dir . '/' . $second['photo']['file_path']);
        $this->assertCount(1, db_get_inspection_photos($pdo, 'tech_sheet', 7));
    }

    public function testSavePhotoLeavesNoOrphanFileWhenUpsertFails(): void
    {
        $pdo = make_temp_pdo();
        $pdo->exec("CREATE TRIGGER fail_insert BEFORE INSERT ON inspection_photos BEGIN SELECT RAISE(ABORT, 'boom'); END");

        try {
            inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 7, 'front_34', $this->tmpFile($this->jpeg()), [], 'rename');
            $this->fail('Expected the upsert to throw');
        } catch (PDOException $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->dir . '/uploads/inspection/tech_sheet/7/front_34.jpg');
        $this->assertSame([], db_get_inspection_photos($pdo, 'tech_sheet', 7));
    }

    public function testDeletePhotoRemovesFileAndRow(): void
    {
        $pdo = make_temp_pdo();
        $saved = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 7, 'front_34', $this->tmpFile($this->jpeg()), [], 'rename');
        $abs = $this->dir . '/' . $saved['photo']['file_path'];

        $this->assertTrue(inspectionDeletePhoto($pdo, $this->dir, (int)$saved['photo']['id']));
        $this->assertFileDoesNotExist($abs);
        $this->assertNull(db_get_inspection_photo($pdo, (int)$saved['photo']['id']));
        $this->assertFalse(inspectionDeletePhoto($pdo, $this->dir, 99999));
    }

    public function testSetAppliesOnlyForConditionalPhotosAndRemovesTheFileWhenTurnedOff(): void
    {
        $pdo = make_temp_pdo();

        $this->assertTrue(inspectionSetApplies($pdo, $this->dir, 'tech_sheet', 7, 'aero', true)['ok']);
        $this->assertSame('', db_get_inspection_photos($pdo, 'tech_sheet', 7)['aero']['file_path']);

        $saved = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 7, 'aero', $this->tmpFile($this->jpeg()), [], 'rename');
        $this->assertTrue($saved['ok'], (string)$saved['error']);
        $file = $this->dir . '/' . $saved['photo']['file_path'];
        $this->assertFileExists($file);

        $this->assertTrue(inspectionSetApplies($pdo, $this->dir, 'tech_sheet', 7, 'aero', false)['ok']);
        $this->assertFileDoesNotExist($file);
        $this->assertSame([], db_get_inspection_photos($pdo, 'tech_sheet', 7));

        $required = inspectionSetApplies($pdo, $this->dir, 'tech_sheet', 7, 'front_34', true);
        $this->assertFalse($required['ok']);
        $this->assertStringContainsString('not optional', $required['error']);
        $this->assertFalse(inspectionSetApplies($pdo, $this->dir, 'tech_sheet', 7, 'nope', true)['ok']);
        $this->assertFalse(inspectionSetApplies($pdo, $this->dir, 'gear_record', 7, 'aero', true)['ok']);
        $this->assertFalse(inspectionSetApplies($pdo, $this->dir, 'tech_sheet', 7, 'helmet_label', true)['ok']);   // gear scope on a car subject
    }

    public function testUpdateTypedValidatesAndResetsReview(): void
    {
        $pdo = make_temp_pdo();
        $saved = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 7, 'harness_date', $this->tmpFile($this->jpeg()), [], 'rename');
        $photoId = (int)$saved['photo']['id'];
        db_set_inspection_photo_review($pdo, $photoId, 'retake', 'Not readable');

        $r = inspectionUpdateTyped($pdo, $photoId, ['date' => '05/2025']);
        $this->assertTrue($r['ok'], (string)$r['error']);
        $this->assertSame(['date' => '05/2025'], $r['photo']['typed']);
        $this->assertSame('pending', $r['photo']['review_status']);

        $bad = inspectionUpdateTyped($pdo, $photoId, ['date' => '2025-05']);
        $this->assertFalse($bad['ok']);
        $this->assertFalse(inspectionUpdateTyped($pdo, 99999, ['date' => '05/2025'])['ok']);

        db_set_conditional_photo_applies($pdo, 'tech_sheet', 7, 'ballast', 1, true);
        $placeholderId = (int)db_get_inspection_photos($pdo, 'tech_sheet', 7)['ballast']['id'];
        $this->assertStringContainsString('photo first', inspectionUpdateTyped($pdo, $placeholderId, [])['error']);
    }

    public function testPublicPhotoHidesFilePath(): void
    {
        $row = [
            'id' => '12', 'requirement_key' => 'helmet_label', 'typed_value' => '{"standard":"SA2020"}',
            'review_status' => 'retake', 'reviewer_note' => 'Too dark', 'file_path' => 'uploads/x.jpg',
        ];
        $public = inspectionPublicPhoto($row);
        $this->assertSame(12, $public['id']);
        $this->assertSame(['standard' => 'SA2020'], $public['typed']);
        $this->assertSame('inspection.php?action=photo&id=12', $public['url']);
        $this->assertArrayNotHasKey('file_path', $public);

        $row['typed_value'] = null;
        $this->assertSame([], inspectionPublicPhoto($row)['typed']);
    }

    private function iceSheet(PDO $pdo, string $class = 'SS'): int {
        $u = db_create_user($pdo, ['email' => 'i' . uniqid() . '@example.com', 'name' => 'Ice', 'password_hash' => 'x', 'google_id' => null]);
        $car = test_make_car($pdo, $u, '7');
        $event = db_create_event($pdo, 'NASCC Ice', '2026-12-12', null, 'ice', 'NASCC');
        return test_make_ice_sheet($pdo, $u, $car, $event, $class);
    }

    public function testSaveRejectsKeysOutsideTheSubjectsList(): void
    {
        $pdo = make_temp_pdo();
        $ss = $this->iceSheet($pdo, 'SS');
        foreach (['front_34', 'ice_cage', 'ice_helmet_label'] as $key) {
            $r = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', $ss, $key, $this->tmpFile($this->jpeg()), [], 'rename');
            $this->assertFalse($r['ok'], $key);
            $this->assertSame('Unknown photo type.', $r['error'], $key);
        }
        $ok = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', $ss, 'ice_airbags', $this->tmpFile($this->jpeg()), [], 'rename');
        $this->assertTrue($ok['ok'], (string)$ok['error']);
        $this->assertSame(ICE_PHOTO_REQUIREMENTS_VERSION, (int)$ok['photo']['requirement_version']);

        // A summer (or unknown) subject still refuses ice keys.
        $this->assertFalse(inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 999, 'ice_front_34', $this->tmpFile($this->jpeg()), [], 'rename')['ok']);
    }

    public function testIceGearAppliesAndTypedUseTheIceList(): void
    {
        $pdo = make_temp_pdo();
        $owner = db_create_user($pdo, ['email' => 'g' . uniqid() . '@example.com', 'name' => 'O', 'password_hash' => 'x', 'google_id' => null]);
        $gearId = (int)gearCreate($pdo, $owner, 'Sam', '', 2027, 'ice')['id'];
        $this->assertTrue(inspectionSetApplies($pdo, $this->dir, 'gear_record', $gearId, 'ice_fhr_label', true)['ok']);
        $this->assertFalse(inspectionSetApplies($pdo, $this->dir, 'gear_record', $gearId, 'fhr_label', true)['ok']);

        $r = inspectionSavePhoto($pdo, $this->dir, 'gear_record', $gearId, 'ice_helmet_label', $this->tmpFile($this->jpeg()),
            ['standard' => 'Snell M2015', 'date' => '03/2020'], 'rename');
        $this->assertTrue($r['ok'], (string)$r['error']);
        $bad = inspectionSavePhoto($pdo, $this->dir, 'gear_record', $gearId, 'ice_helmet_label', $this->tmpFile($this->jpeg()),
            ['standard' => 'Bell Bike'], 'rename');
        $this->assertFalse($bad['ok']);
        $this->assertTrue(inspectionUpdateTyped($pdo, (int)$r['photo']['id'], ['standard' => 'Snell SA2020'])['ok']);
    }
}
