<?php
use PHPUnit\Framework\TestCase;

final class DbTechSheetsTaDriftTest extends TestCase
{
    private function user(PDO $pdo): int {
        return db_create_user($pdo, ['email' => 'tad' . uniqid() . '@example.com', 'name' => 'TA Driver', 'password_hash' => 'x', 'google_id' => null]);
    }

    public function testTaDriftSheetTakesTheCarAndTheHostClub(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $car = test_make_car($pdo, $uid, '86');
        $event = db_create_event($pdo, 'WSCC TA', '2026-07-12', null, 'summer', 'WSCC');

        $sheet = db_get_tech_sheet($pdo, test_make_ta_drift_sheet($pdo, $uid, $car, $event, true));
        $this->assertNull($sheet['submission_id']);
        $this->assertSame($car, (int)$sheet['car_id']);
        $this->assertSame('ta_drift', $sheet['sheet_type']);
        $this->assertSame('summer', $sheet['discipline']);
        $this->assertSame('WSCC', $sheet['club']);
        $this->assertSame(2026, (int)$sheet['season']);
        $this->assertSame(1, (int)$sheet['caged']);
        $this->assertSame(0, (int)db_get_tech_sheet($pdo, test_make_ta_drift_sheet($pdo, $uid, $car, $event))['caged']);
    }

    public function testRejectsADeclaration(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $uid, '86'));
        $event = db_create_event($pdo, 'WSCC TA', '2026-07-12', null, 'summer', 'WSCC');
        $this->expectException(InvalidArgumentException::class);
        db_insert_tech_sheet($pdo, ['submission_id' => $sub] + test_ta_drift_sheet_data($uid, test_make_car($pdo, $uid, '86'), $event));
    }

    public function testRejectsAnEventWithoutAHostClub(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $event = db_create_event($pdo, 'Open Day', '2026-07-12', null);
        $this->expectExceptionMessage('A TA/Drift tech sheet needs an event with a host club.');
        test_make_ta_drift_sheet($pdo, $uid, test_make_car($pdo, $uid, '86'), $event);
    }

    public function testRejectsAnIceEvent(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $event = db_create_event($pdo, 'Ice Drift', '2027-01-16', null, 'ice', 'WSCC');
        $this->expectException(InvalidArgumentException::class);
        test_make_ta_drift_sheet($pdo, $uid, test_make_car($pdo, $uid, '86'), $event);
    }

    public function testRejectsSomeoneElsesCar(): void
    {
        $pdo = make_temp_pdo();
        $owner = $this->user($pdo);
        $other = $this->user($pdo);
        $event = db_create_event($pdo, 'WSCC TA', '2026-07-12', null, 'summer', 'WSCC');
        $this->expectException(InvalidArgumentException::class);
        test_make_ta_drift_sheet($pdo, $other, test_make_car($pdo, $owner, '86'), $event);
    }

    public function testTaDriftSheetsDoNotMergeWithRaceOrOtherClub(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $uid, '86'));
        $car = test_make_car($pdo, $uid, '86');
        $race = test_make_sheet($pdo, $uid, $sub, db_create_event($pdo, 'Sprint', '2026-06-01', null), '86');
        $wscc = test_make_ta_drift_sheet($pdo, $uid, $car, db_create_event($pdo, 'WSCC TA', '2026-07-12', null, 'summer', 'WSCC'));
        $nascc = test_make_ta_drift_sheet($pdo, $uid, $car, db_create_event($pdo, 'NASCC TA', '2026-08-09', null, 'summer', 'NASCC'));

        $ids = fn(array $rows): array => array_map(fn(array $r): int => (int)$r['id'], $rows);
        $this->assertSame([$race], $ids(db_get_identity_sheets($pdo, $car, 2026)));
        $this->assertSame([$wscc], $ids(db_get_identity_sheets($pdo, $car, 2026, 'summer', 'WSCC')));
        $this->assertSame([$nascc], $ids(db_get_identity_sheets($pdo, $car, 2026, 'summer', 'NASCC')));
        $keys = array_map(fn(int $id): string => techCarKey(db_get_tech_sheet($pdo, $id)), [$race, $wscc, $nascc]);
        $this->assertSame($keys, array_unique($keys));
    }

    public function testCannotChangeBetweenTaDriftAndRace(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $car = test_make_car($pdo, $uid, '86');
        $event = db_create_event($pdo, 'WSCC TA', '2026-07-12', null, 'summer', 'WSCC');
        $id = test_make_ta_drift_sheet($pdo, $uid, $car, $event);
        $this->expectExceptionMessage('A tech sheet cannot change between TA/Drift and race.');
        db_update_tech_sheet($pdo, $id, ['sheet_type' => 'standard'] + test_ta_drift_sheet_data($uid, $car, $event));
    }

    public function testRaceSheetCannotBecomeTaDrift(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $uid, '86'));
        $event = db_create_event($pdo, 'WSCC Sprint', '2026-07-12', null, 'summer', 'WSCC');
        $id = test_make_sheet($pdo, $uid, $sub, $event, '86');
        $this->expectException(InvalidArgumentException::class);
        db_update_tech_sheet($pdo, $id, test_ta_drift_sheet_data($uid, test_make_car($pdo, $uid, '86'), $event));
    }

    public function testUpdateTakesTheNewEventsClubAndCaged(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $car = test_make_car($pdo, $uid, '86');
        $id = test_make_ta_drift_sheet($pdo, $uid, $car, db_create_event($pdo, 'WSCC TA', '2026-07-12', null, 'summer', 'WSCC'));
        $nascc = db_create_event($pdo, 'NASCC TA', '2026-08-09', null, 'summer', 'NASCC');

        db_update_tech_sheet($pdo, $id, test_ta_drift_sheet_data($uid, $car, $nascc, true));
        $sheet = db_get_tech_sheet($pdo, $id);
        $this->assertSame('NASCC', $sheet['club']);
        $this->assertSame(1, (int)$sheet['caged']);
    }

    public function testUpdateToEventWithoutClubIsRefused(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $car = test_make_car($pdo, $uid, '86');
        $id = test_make_ta_drift_sheet($pdo, $uid, $car, db_create_event($pdo, 'WSCC TA', '2026-07-12', null, 'summer', 'WSCC'));
        $noClub = db_create_event($pdo, 'Open Day', '2026-08-01', null);
        $this->expectException(InvalidArgumentException::class);
        db_update_tech_sheet($pdo, $id, test_ta_drift_sheet_data($uid, $car, $noClub));
    }
}
