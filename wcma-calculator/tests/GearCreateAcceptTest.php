<?php
// wcma-calculator/tests/GearCreateAcceptTest.php
require_once __DIR__ . '/../gear-lib.php';

use PHPUnit\Framework\TestCase;

final class GearCreateAcceptTest extends TestCase
{
    private function users(PDO $pdo): array {
        $owner = db_create_user($pdo, ['email' => 'captain@example.com', 'name' => 'Captain', 'password_hash' => 'x', 'google_id' => null]);
        $admin = db_create_user($pdo, ['email' => 'tech@example.com', 'name' => 'Tech', 'password_hash' => 'x', 'google_id' => null]);
        return [$owner, $admin];
    }

    private function sheet(int $owner, array $o = []): array {
        return array_merge(['id' => 1, 'user_id' => $owner, 'season' => gearSeasonNow(), 'driver_name' => 'Jane Racer'], $o);
    }

    public function testCreatesUnderTheSheetOwnerAndAcceptsInPersonForDriverOne(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);

        $r = gearCreateAndAcceptInPerson($pdo, $this->sheet($owner), [], 1, $admin);

        $this->assertTrue($r['ok'], (string)$r['error']);
        $row = db_get_gear_record($pdo, $r['id']);
        $this->assertSame($owner, (int)$row['owner_user_id'], 'owned by the sheet owner, not the acting admin');
        $this->assertSame('Jane Racer', $row['driver_name']);
        $this->assertSame(gearSeasonNow(), (int)$row['season']);
        $this->assertSame('accepted', $row['status']);
        $this->assertSame('in_person', $row['accepted_via']);
        $this->assertSame($admin, (int)$row['reviewed_by_user_id']);
        $this->assertCount(0, db_get_user_gear_records($pdo, $admin));
    }

    public function testAdditionalDriverIsFoundByNumberAndNormalised(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $drivers = [['driver_number' => 2, 'driver_name' => '  Sam   Coach ']];

        $r = gearCreateAndAcceptInPerson($pdo, $this->sheet($owner), $drivers, 2, $admin);

        $this->assertTrue($r['ok'], (string)$r['error']);
        $this->assertSame('Sam Coach', db_get_gear_record($pdo, $r['id'])['driver_name']);
    }

    public function testDriverNotOnTheSheetOrBlankIsRefusedAndCreatesNothing(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);

        $missing = gearCreateAndAcceptInPerson($pdo, $this->sheet($owner), [], 3, $admin);
        $blank = gearCreateAndAcceptInPerson($pdo, $this->sheet($owner, ['driver_name' => '   ']), [], 1, $admin);

        foreach ([$missing, $blank] as $r) {
            $this->assertFalse($r['ok']);
            $this->assertNull($r['id']);
            $this->assertStringContainsString('not on this sheet', $r['error']);
        }
        $this->assertCount(0, db_get_user_gear_records($pdo, $owner));
    }

    public function testExistingOpenRecordIsAcceptedInsteadOfDuplicated(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $existing = gearCreate($pdo, $owner, 'JANE  racer', 'WCMA-1', gearSeasonNow())['id'];

        $r = gearCreateAndAcceptInPerson($pdo, $this->sheet($owner), [], 1, $admin);

        $this->assertTrue($r['ok'], (string)$r['error']);
        $this->assertSame($existing, $r['id']);
        $this->assertCount(1, db_get_user_gear_records($pdo, $owner));
        $row = db_get_gear_record($pdo, $existing);
        $this->assertSame('accepted', $row['status']);
        $this->assertSame('WCMA-1', $row['licence_no']);
    }

    public function testAlreadyAcceptedIsRefusedCleanly(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $this->assertTrue(gearCreateAndAcceptInPerson($pdo, $this->sheet($owner), [], 1, $admin)['ok']);

        $again = gearCreateAndAcceptInPerson($pdo, $this->sheet($owner), [], 1, $admin);

        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('already been teched', $again['error']);
        $this->assertCount(1, db_get_user_gear_records($pdo, $owner));
    }

    public function testPastSeasonSheetsAreRefused(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);

        $r = gearCreateAndAcceptInPerson($pdo, $this->sheet($owner, ['season' => gearSeasonNow() - 1]), [], 1, $admin);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('current season', $r['error']);
        $this->assertCount(0, db_get_user_gear_records($pdo, $owner));
    }

    public function testMissingSeasonFallsBackToTheCurrentYear(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);

        $r = gearCreateAndAcceptInPerson($pdo, $this->sheet($owner, ['season' => 0]), [], 1, $admin);

        $this->assertTrue($r['ok'], (string)$r['error']);
        $this->assertSame(gearSeasonNow(), (int)db_get_gear_record($pdo, $r['id'])['season']);
    }

    public function testTooLongNameIsRejectedAndLeavesNothingBehind(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);

        $r = gearCreateAndAcceptInPerson($pdo, $this->sheet($owner, ['driver_name' => str_repeat('x', 101)]), [], 1, $admin);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('too long', $r['error']);
        $this->assertCount(0, db_get_user_gear_records($pdo, $owner));
    }

    public function testDoesNotCommitInsideACallersTransaction(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);

        $pdo->beginTransaction();
        $r = gearCreateAndAcceptInPerson($pdo, $this->sheet($owner), [], 1, $admin);
        $this->assertTrue($r['ok'], (string)$r['error']);
        $this->assertTrue($pdo->inTransaction());
        $pdo->rollBack();

        $this->assertCount(0, db_get_user_gear_records($pdo, $owner));
    }

    public function testMessagesAvoidBannedWording(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        gearCreateAndAcceptInPerson($pdo, $this->sheet($owner), [], 1, $admin);
        $messages = [
            gearCreateAndAcceptInPerson($pdo, $this->sheet($owner), [], 1, $admin)['error'],
            gearCreateAndAcceptInPerson($pdo, $this->sheet($owner), [], 9, $admin)['error'],
            gearCreateAndAcceptInPerson($pdo, $this->sheet($owner, ['season' => 1999]), [], 1, $admin)['error'],
        ];
        foreach ($messages as $m) {
            $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', (string)$m);
        }
    }
}
