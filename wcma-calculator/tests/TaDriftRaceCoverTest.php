<?php
// wcma-calculator/tests/TaDriftRaceCoverTest.php — a car with accepted race tech is not asked for
// TA/Drift car photos: race tech covers TA/Drift (TA/Drift spec §2; bug list 2026-10-02, item 4).
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../pretech-lib.php';

use PHPUnit\Framework\TestCase;

final class TaDriftRaceCoverTest extends TestCase
{
    /** @return array{0: int, 1: int, 2: int, 3: int} user, admin, race sheet, TA/Drift sheet, all for car #86 in 2026 */
    private function fixture(PDO $pdo): array {
        $u = db_create_user($pdo, ['email' => 'rc' . uniqid() . '@example.com', 'name' => 'Racer', 'password_hash' => 'x', 'google_id' => null]);
        $admin = db_create_user($pdo, ['email' => 'ad' . uniqid() . '@example.com', 'name' => 'Tech', 'password_hash' => 'x', 'google_id' => null]);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $u, '86'));
        $race = test_make_sheet($pdo, $u, $sub, db_create_event($pdo, 'Race 1', '2026-06-14', null), '86');
        $ta = test_make_ta_drift_sheet($pdo, $u, test_make_car($pdo, $u, '86'), db_create_event($pdo, 'WSCC TA', '2026-07-12', null, 'summer', 'WSCC'));
        return [$u, $admin, $race, $ta];
    }

    private function mode(PDO $pdo, int $id): array {
        $sheet = db_get_tech_sheet($pdo, $id);
        return pretechPageMode($sheet, db_get_sheet_identity_sheets($pdo, $sheet), pretechRaceCover($pdo, $sheet));
    }

    public function testRaceTechNotYetAcceptedLeavesTheTaDriftPhotosOpen(): void
    {
        $pdo = make_temp_pdo();
        [, , , $ta] = $this->fixture($pdo);
        $this->assertSame('this_sheet', $this->mode($pdo, $ta)['mode']);
    }

    public function testAcceptedRaceTechCoversTheTaDriftSheet(): void
    {
        $pdo = make_temp_pdo();
        [, $admin, $race, $ta] = $this->fixture($pdo);
        db_accept_tech_sheet_in_person($pdo, $race, $admin, 'sig.png');

        $this->assertSame(['mode' => 'car_accepted', 'sheet_id' => $race, 'by_race' => true], $this->mode($pdo, $ta));
        // The race sheet's own page is unchanged.
        $this->assertArrayNotHasKey('by_race', $this->mode($pdo, $race));
    }

    public function testSubmittingTaDriftPhotosIsRefusedOnceRaceTechIsAccepted(): void
    {
        $pdo = make_temp_pdo();
        [, $admin, $race, $ta] = $this->fixture($pdo);
        db_accept_tech_sheet_in_person($pdo, $race, $admin, 'sig.png');
        $r = pretechSubmit($pdo, $ta);
        $this->assertFalse($r['ok']);
        $this->assertSame('This car has already been teched for the season, so no photos are needed.', $r['error']);
    }

    public function testRaceTechInAnotherSeasonDoesNotCover(): void
    {
        $pdo = make_temp_pdo();
        [$u, $admin, , $ta] = $this->fixture($pdo);
        $old = test_make_sheet($pdo, $u, db_insert_submission($pdo, test_declaration_data($pdo, $u, '86')), db_create_event($pdo, 'Race 2025', '2025-06-14', null), '86');
        db_accept_tech_sheet_in_person($pdo, $old, $admin, 'sig.png');
        $this->assertSame('this_sheet', $this->mode($pdo, $ta)['mode']);
    }

    public function testOnlyTaDriftSheetsGetARaceCover(): void
    {
        $pdo = make_temp_pdo();
        [, , $race] = $this->fixture($pdo);
        $this->assertNull(pretechRaceCover($pdo, db_get_tech_sheet($pdo, $race)));
    }

    public function testThePageExplainsRaceTechCoversIt(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../pretech-page.php'));
        $this->assertStringContainsString("which covers TA/Drift. You do not need to submit photos.", $src);
        $handler = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../tech-sheets.php'));
        $this->assertStringContainsString('pretechPageMode($sheet, $identity, pretechRaceCover($pdo, $sheet))', $handler);
    }
}
