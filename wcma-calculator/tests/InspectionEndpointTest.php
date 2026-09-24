<?php
// wcma-calculator/tests/InspectionEndpointTest.php
//
// Black-box tests of inspection.php: ordering of checks (401, 405, 403 CSRF, then
// authorisation), identical 404s, the teched write lock and the photo-serving headers.
// inspection.php calls exit, so each request runs in a subprocess through
// tests/support/run_inspection_endpoint.php against a temp SQLite DB.
//
// NOT covered here: the successful *upload* path. move_uploaded_file() only accepts
// files from a real HTTP upload, so it cannot be driven from CLI. That path is covered
// by InspectionLibTest (inspectionSavePhoto with the 'rename' mover) and the browser e2e.
//
// Photo files live under the real app dir (inspection.php uses __DIR__ as base), so the
// seeded sheets get very high ids and tearDown removes their upload directories.
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';

use PHPUnit\Framework\TestCase;

final class InspectionEndpointTest extends TestCase
{
    private string $dbPath;
    private PDO $pdo;
    private string $payloadDir;
    private array $sheetIds = [];
    private int $ownerId;
    private int $otherId;
    private int $adminId;
    private int $ownerSheet;
    private int $ownerPhotoId;

    private const CSRF = 'csrf-token-abc';

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/wcma_ep_' . uniqid() . '.db';
        $this->payloadDir = sys_get_temp_dir() . '/wcma_epp_' . uniqid();
        mkdir($this->payloadDir);
        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        db_init($this->pdo);

        $this->ownerId = $this->makeUser('owner@example.com', 'Owner', 'user');
        $this->otherId = $this->makeUser('other@example.com', 'Other', 'user');
        $this->adminId = $this->makeUser('admin@example.com', 'Admin', 'admin');
        $this->ownerSheet = $this->makeSheet($this->ownerId, 900000 + random_int(1, 90000));
        $this->ownerPhotoId = $this->seedPhoto($this->ownerSheet);
    }

    protected function tearDown(): void
    {
        foreach ($this->sheetIds as $id) {
            $this->removeDir(dirname(__DIR__) . '/uploads/inspection/tech_sheet/' . $id);
        }
        $this->removeDir($this->payloadDir);
        unset($this->pdo);
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (file_exists($this->dbPath . $suffix)) @unlink($this->dbPath . $suffix);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
        rmdir($dir);
    }

    private function makeUser(string $email, string $name, string $role): int
    {
        $id = db_create_user($this->pdo, ['email' => $email, 'name' => $name, 'password_hash' => 'x', 'google_id' => null]);
        $this->pdo->prepare('UPDATE users SET role = :r WHERE id = :id')->execute([':r' => $role, ':id' => $id]);
        return $id;
    }

    private function makeSheet(int $userId, int $forcedId): int
    {
        $subId = db_insert_submission($this->pdo, [
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
        $eventId = db_create_event($this->pdo, 'Spring Sprint', '2026-05-10', null);
        $id = db_insert_tech_sheet($this->pdo, [
            'submission_id' => $subId, 'user_id' => $userId, 'event_id' => $eventId, 'sheet_type' => 'standard',
            'entrant_name' => 'Racer', 'driver_name' => 'Racer', 'car_make' => 'Mazda', 'car_model' => 'MX-5',
            'car_colour' => 'Red', 'car_number' => '42', 'class' => 'IT1', 'engine_cc' => '1800', 'engine_hp' => '150',
            'car_weight' => 2200, 'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1,
        ]);
        // High id so the real uploads/ dir used by the endpoint cannot collide with dev data.
        $this->pdo->prepare('UPDATE tech_sheets SET id = :new WHERE id = :old')->execute([':new' => $forcedId, ':old' => $id]);
        $this->sheetIds[] = $forcedId;
        return $forcedId;
    }

    /** Stores a real photo file + row via the library, returning the row id. */
    private function seedPhoto(int $sheetId): int
    {
        $tmp = $this->payloadDir . '/' . uniqid('seed_') . '.bin';
        file_put_contents($tmp, hex2bin('ffd8ffc00011080001000103011100021100031100ffd9'));
        $r = inspectionSavePhoto($this->pdo, dirname(__DIR__), 'tech_sheet', $sheetId, 'front_34', $tmp, [], 'rename');
        $this->assertTrue($r['ok'], (string)$r['error']);
        return (int)$r['photo']['id'];
    }

    private function photoFile(): string
    {
        return dirname(__DIR__) . '/uploads/inspection/tech_sheet/' . $this->ownerSheet . '/front_34.jpg';
    }

    private function session(?int $userId, string $role = 'user'): ?array
    {
        if ($userId === null) return null;
        return ['user_id' => $userId, 'user_name' => 'U' . $userId, 'user_role' => $role, 'csrf_token' => self::CSRF];
    }

    /** @return array{status: int, headers: string[], body: string} */
    private function request(string $method, array $get, array $post, ?array $session): array
    {
        $file = $this->payloadDir . '/' . uniqid('req_') . '.json';
        file_put_contents($file, json_encode([
            'db_path' => $this->dbPath, 'method' => $method, 'get' => $get, 'post' => $post, 'session' => $session,
        ]));
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/support/run_inspection_endpoint.php') . ' ' . escapeshellarg($file);
        $out = shell_exec($cmd);
        $this->assertIsString($out);
        $pos = strrpos($out, '@@RESULT@@');
        $this->assertNotFalse($pos, 'Harness produced no result: ' . $out);
        $res = json_decode(substr($out, $pos + strlen('@@RESULT@@')), true);
        $this->assertIsArray($res);
        $res['body'] = base64_decode($res['body']);
        return $res;
    }

    private function header(array $res, string $name): ?string
    {
        foreach ($res['headers'] as $h) {
            if (stripos($h, $name . ':') === 0) return trim(substr($h, strlen($name) + 1));
        }
        return null;
    }

    private function upload(array $post, ?int $userId, string $role = 'user', string $csrf = self::CSRF): array
    {
        return $this->request('POST', ['action' => 'upload'], $post + ['csrf_token' => $csrf], $this->session($userId, $role));
    }

    private function ownerUploadFields(): array
    {
        return ['subject_type' => 'tech_sheet', 'subject_id' => $this->ownerSheet, 'requirement_key' => 'front_34'];
    }

    public function testAnonymousGets401ForEveryAction(): void
    {
        foreach (['upload', 'delete', 'photo', 'nonsense'] as $action) {
            $res = $this->request('POST', ['action' => $action, 'id' => $this->ownerPhotoId], ['csrf_token' => self::CSRF], null);
            $this->assertSame(401, $res['status'], $action);
        }
    }

    public function testUploadWithGetIs405(): void
    {
        $res = $this->request('GET', ['action' => 'upload'], [], $this->session($this->ownerId));
        $this->assertSame(405, $res['status']);
    }

    public function testUploadWithWrongCsrfIs403BeforeAuthorisation(): void
    {
        // Another user's sheet, wrong token: 403 (CSRF) not 404, proving CSRF is checked first.
        $res = $this->upload($this->ownerUploadFields(), $this->otherId, 'user', 'wrong');
        $this->assertSame(403, $res['status']);
    }

    public function testDeleteWithWrongCsrfIs403(): void
    {
        $res = $this->request('POST', ['action' => 'delete'], ['id' => $this->ownerPhotoId, 'csrf_token' => 'wrong'], $this->session($this->ownerId));
        $this->assertSame(403, $res['status']);
        $this->assertFileExists($this->photoFile());
    }

    public function testNotYoursAndNotThereAreIdentical404(): void
    {
        $others = $this->upload($this->ownerUploadFields(), $this->otherId);
        $missing = $this->upload(['subject_type' => 'tech_sheet', 'subject_id' => 999999999, 'requirement_key' => 'front_34'], $this->otherId);
        $this->assertSame(404, $others['status']);
        $this->assertSame(404, $missing['status']);
        $this->assertSame($missing['body'], $others['body']);
    }

    public function testUploadToTechedSheetIs404ForOwner(): void
    {
        $this->pdo->prepare("UPDATE tech_sheets SET status = 'teched' WHERE id = :id")->execute([':id' => $this->ownerSheet]);
        $res = $this->upload($this->ownerUploadFields(), $this->ownerId);
        $this->assertSame(404, $res['status']);
    }

    public function testUnknownSubjectTypeIs404(): void
    {
        foreach (['gear_record', 'bogus', ''] as $type) {
            $res = $this->upload(['subject_type' => $type] + $this->ownerUploadFields(), $this->ownerId);
            $this->assertSame(404, $res['status'], "type [$type]");
        }
    }

    public function testStoredPhotoWithUnhandledSubjectTypeIsNotServedOrDeleted(): void
    {
        // A row whose subject_type has no loader branch must never be authorised against a tech sheet
        // that happens to share its numeric id (finding: explicit dispatch in inspectionLoadSubject).
        $this->pdo->prepare("UPDATE inspection_photos SET subject_type = 'gear_record' WHERE id = :id")->execute([':id' => $this->ownerPhotoId]);
        $get = $this->request('GET', ['action' => 'photo', 'id' => $this->ownerPhotoId], [], $this->session($this->ownerId));
        $this->assertSame(404, $get['status']);
        $del = $this->request('POST', ['action' => 'delete'], ['id' => $this->ownerPhotoId, 'csrf_token' => self::CSRF], $this->session($this->adminId, 'admin'));
        $this->assertSame(404, $del['status']);
    }

    public function testUnknownActionIs400(): void
    {
        $res = $this->request('GET', ['action' => 'nonsense'], [], $this->session($this->ownerId));
        $this->assertSame(400, $res['status']);
    }

    public function testUploadWithoutFileIs400ForOwner(): void
    {
        $res = $this->upload($this->ownerUploadFields(), $this->ownerId);
        $this->assertSame(400, $res['status']);
    }

    public function testDeleteByOtherUserIs404AndByOwnerRemovesFileAndRow(): void
    {
        $post = ['id' => $this->ownerPhotoId, 'csrf_token' => self::CSRF];
        $res = $this->request('POST', ['action' => 'delete'], $post, $this->session($this->otherId));
        $this->assertSame(404, $res['status']);
        $this->assertFileExists($this->photoFile());
        $this->assertNotNull(db_get_inspection_photo($this->pdo, $this->ownerPhotoId));

        $res = $this->request('POST', ['action' => 'delete'], $post, $this->session($this->ownerId));
        $this->assertSame(200, $res['status']);
        $this->assertTrue(json_decode($res['body'], true)['ok']);
        $this->assertFileDoesNotExist($this->photoFile());
        $this->assertNull(db_get_inspection_photo($this->pdo, $this->ownerPhotoId));
    }

    public function testOwnerServesPhotoWithSafeHeaders(): void
    {
        // headers_list() is empty under the CLI SAPI, so this one goes over real HTTP
        // through PHP's built-in server to see the actual response headers.
        $res = $this->httpGet('inspection.php?action=photo&id=' . $this->ownerPhotoId, $this->session($this->ownerId));
        $this->assertSame(200, $res['status']);
        $this->assertSame('image/jpeg', $this->header($res, 'Content-Type'));
        $this->assertSame('nosniff', $this->header($res, 'X-Content-Type-Options'));
        $this->assertSame('private, max-age=0, must-revalidate', $this->header($res, 'Cache-Control'));
        $this->assertSame(file_get_contents($this->photoFile()), $res['body']);
    }

    /** @return array{status: int, headers: string[], body: string} */
    private function httpGet(string $path, array $session): array
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/support/inspection_router.php'];
        $env = ['WCMA_TEST_DB' => $this->dbPath, 'WCMA_TEST_SESSION' => json_encode($session)] + getenv();
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__ . '/..', $env);
        $this->assertIsResource($proc);
        try {
            $ready = false;
            for ($i = 0; $i < 50 && !$ready; $i++) {
                $c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($c) { fclose($c); $ready = true; } else { usleep(100000); }
            }
            $this->assertTrue($ready, 'Built-in server did not start');
            $body = @file_get_contents('http://127.0.0.1:' . $port . '/' . $path, false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]));
            $headers = $http_response_header ?? [];
            preg_match('#^HTTP/\S+ (\d{3})#', $headers[0] ?? '', $m);
            return ['status' => (int)($m[1] ?? 0), 'headers' => $headers, 'body' => (string)$body];
        } finally {
            foreach ($pipes as $p) fclose($p);
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    public function testPhotoIs404ForOtherUserAndMissingId(): void
    {
        $res = $this->request('GET', ['action' => 'photo', 'id' => $this->ownerPhotoId], [], $this->session($this->otherId));
        $this->assertSame(404, $res['status']);
        $res = $this->request('GET', ['action' => 'photo', 'id' => 999999999], [], $this->session($this->ownerId));
        $this->assertSame(404, $res['status']);
        $res = $this->request('GET', ['action' => 'photo'], [], $this->session($this->ownerId));
        $this->assertSame(404, $res['status']);
    }

    public function testAdminCanReadAndWriteAnotherUsersSheet(): void
    {
        $res = $this->request('GET', ['action' => 'photo', 'id' => $this->ownerPhotoId], [], $this->session($this->adminId, 'admin'));
        $this->assertSame(200, $res['status']);

        // Write access: passes authorisation and reaches the "no photo received" check (400), not 404.
        $res = $this->upload($this->ownerUploadFields(), $this->adminId, 'admin');
        $this->assertSame(400, $res['status']);

        // Admin write also passes on a teched sheet, and admin can delete.
        $this->pdo->prepare("UPDATE tech_sheets SET status = 'teched' WHERE id = :id")->execute([':id' => $this->ownerSheet]);
        $res = $this->upload($this->ownerUploadFields(), $this->adminId, 'admin');
        $this->assertSame(400, $res['status']);
        $res = $this->request('POST', ['action' => 'delete'], ['id' => $this->ownerPhotoId, 'csrf_token' => self::CSRF], $this->session($this->adminId, 'admin'));
        $this->assertSame(200, $res['status']);
    }
}
