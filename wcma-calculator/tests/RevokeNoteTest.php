<?php
// wcma-calculator/tests/RevokeNoteTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../tech-sheet-files.php';
require_once __DIR__ . '/../tech-review-lib.php';
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../gear-lib.php';

use PHPUnit\Framework\TestCase;

final class RevokeNoteTest extends TestCase
{
    public function testClean(): void
    {
        $this->assertNull(revokeNoteClean(null));
        $this->assertNull(revokeNoteClean("  \n\t "));
        $this->assertNull(revokeNoteClean(['Car changed']));
        $this->assertSame('Car changed: new engine', revokeNoteClean("  Car   changed:\nnew engine "));
        $this->assertSame(REVOKE_NOTE_MAX, mb_strlen(revokeNoteClean(str_repeat('é', 600)), 'UTF-8'));
    }

    public function testNotice(): void
    {
        $this->assertSame('', revokeNoticeHtml(null, 'Tech'));
        $this->assertSame('', revokeNoticeHtml('  ', 'Gear'));
        $html = revokeNoticeHtml('Car changed <engine>', 'Tech');
        $this->assertStringContainsString('<strong>Tech revoked:</strong> Car changed &lt;engine&gt;', $html);
    }

    public function testBlankNoteIsRefusedAndNothingChanges(): void
    {
        $pdo = make_temp_pdo();
        $dir = sys_get_temp_dir() . '/wcma_revoke_' . uniqid();
        mkdir($dir);
        $u = db_create_user($pdo, ['email' => 'rv' . uniqid() . '@example.com', 'name' => 'Pat', 'password_hash' => 'x', 'google_id' => null]);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42'));
        $sheetId = test_make_sheet($pdo, $u, $sub, db_create_event($pdo, 'Sprint', '2026-05-10', null));
        $this->assertTrue(db_accept_tech_sheet_in_person($pdo, $sheetId, $u, 'uploads/sig.png'));

        $r = techReviewRevoke($pdo, $dir, $sheetId, "   ");
        $this->assertSame(['ok' => false, 'error' => REVOKE_NOTE_REQUIRED], $r);
        $sheet = db_get_tech_sheet($pdo, $sheetId);
        $this->assertSame('teched', $sheet['status']);
        $this->assertSame('uploads/sig.png', $sheet['tech_signature_path']);

        $this->assertTrue(techReviewRevoke($pdo, $dir, $sheetId, 'Car changed: new engine')['ok']);
        $this->assertSame('Car changed: new engine', db_get_tech_sheet($pdo, $sheetId)['revoke_note']);
    }

    public function testGearRevokeNeedsANote(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'rg' . uniqid() . '@example.com', 'name' => 'Pat', 'password_hash' => 'x', 'google_id' => null]);
        $id = (int)gearCreate($pdo, $u, 'Pat Winters', '', 2026)['id'];
        // TODO(Task 6): restore GEAR_LEVEL_TA_DRIFT + level assertion
        $this->assertTrue(gearAcceptInPerson($pdo, $id, $u)['ok']);

        $this->assertSame(REVOKE_NOTE_REQUIRED, gearRevoke($pdo, $id, '')['error']);
        $this->assertSame('accepted', db_get_gear_record($pdo, $id)['status']);

        $this->assertTrue(gearRevoke($pdo, $id, 'Helmet expired')['ok']);
        $row = db_get_gear_record($pdo, $id);
        $this->assertSame('Helmet expired', $row['revoke_note']);
    }

    public function testFormsPostTheNoteAndOwnersSeeIt(): void
    {
        $techAdmin = file_get_contents(__DIR__ . '/../admin-tech-sheets.php');
        $gearAdmin = file_get_contents(__DIR__ . '/../admin-gear.php');
        foreach ([$techAdmin, $gearAdmin] as $src) {
            $this->assertStringContainsString('name="revoke_note" maxlength="500" rows="2" required', $src);
            $this->assertStringContainsString("\$_POST['revoke_note'] ?? null", $src);
        }
        $this->assertStringContainsString("revokeNoticeHtml(\$sheet['revoke_note'] ?? null, 'Tech')", file_get_contents(__DIR__ . '/../tech-sheets.php'));
        $this->assertStringContainsString("revokeNoticeHtml(\$gear['revoke_note'] ?? null, 'Gear')", file_get_contents(__DIR__ . '/../gear-page.php'));
    }
}
