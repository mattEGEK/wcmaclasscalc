<?php
// wcma-calculator/tests/DbInspectTest.php
use PHPUnit\Framework\TestCase;

final class DbInspectTest extends TestCase
{
    private function user(PDO $pdo, string $email, string $name): int {
        return db_create_user($pdo, ['email' => $email, 'name' => $name, 'password_hash' => 'x', 'google_id' => null]);
    }

    private function ids(array $rows): array {
        return array_map(fn(array $r): int => (int)$r['id'], $rows);
    }

    public function testRosterCarsAreTaggedOrHaveASheetOrderedByNumber(): void
    {
        $pdo = make_temp_pdo();
        $jordan = $this->user($pdo, 'jordan@example.com', 'Jordan Lee');
        $casey = $this->user($pdo, 'casey@example.com', 'Casey Moss');
        $fall = db_create_event($pdo, 'Fall Sprint', '2026-10-04', null);
        $other = db_create_event($pdo, 'Finale', '2026-10-18', null);
        $c42 = test_make_car($pdo, $jordan, '42');
        $c7 = test_make_car($pdo, $casey, '7');
        $c99 = test_make_car($pdo, $casey, '99');
        test_make_car($pdo, $jordan, '5');                          // not going anywhere
        db_tag_event($pdo, $jordan, $fall, $c42);
        $sub7 = db_insert_submission($pdo, test_declaration_data($pdo, $casey, '7'));
        test_make_sheet($pdo, $casey, $sub7, $fall, '7');          // a sheet, but not tagged
        db_tag_event($pdo, $casey, $other, $c99);                  // a different event

        $rows = db_get_event_roster_cars($pdo, $fall);
        $this->assertSame([$c7, $c42], $this->ids($rows));
        $this->assertSame('Casey Moss', $rows[0]['owner_name']);
        $this->assertSame('casey@example.com', $rows[0]['owner_email']);
        $this->assertSame(0, (int)$rows[0]['tagged']);
        $this->assertSame(1, (int)$rows[1]['tagged']);
        $this->assertSame([], db_get_event_roster_cars($pdo, 999));
    }

    public function testDeclarationsAndSelfDriversAreFetchedForManyAtOnce(): void
    {
        $pdo = make_temp_pdo();
        $jordan = $this->user($pdo, 'jordan@example.com', 'Jordan Lee');
        $casey = $this->user($pdo, 'casey@example.com', 'Casey Moss');
        $old = db_insert_submission($pdo, test_declaration_data($pdo, $jordan, '42'));
        $new = db_insert_submission($pdo, test_declaration_data($pdo, $jordan, '42'));
        db_insert_submission($pdo, test_declaration_data($pdo, $casey, '7'));
        $c42 = test_make_car($pdo, $jordan, '42');
        $c7 = test_make_car($pdo, $casey, '7');

        $map = db_get_declarations_for_cars($pdo, [$c42, $c7, 999]);
        $this->assertSame([$new, $old], $this->ids($map[$c42]));
        $this->assertCount(1, $map[$c7]);
        $this->assertArrayNotHasKey(999, $map);
        $this->assertSame([], db_get_declarations_for_cars($pdo, []));

        $self = db_get_self_drivers_for_users($pdo, [$jordan, $casey, 999]);
        $this->assertSame('Jordan Lee', $self[$jordan]['name']);
        $this->assertSame('Casey Moss', $self[$casey]['name']);
        $this->assertArrayNotHasKey(999, $self);
        $this->assertSame([], db_get_self_drivers_for_users($pdo, []));
    }

    public function testReviewQueueQueriesReturnOnlyWorkWaitingOnAnInspectorOldestFirst(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'jordan@example.com', 'Jordan Lee');
        $a = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42', [':submitted_at' => '2026-09-01 10:00:00']));
        $b = db_insert_submission($pdo, test_declaration_data($pdo, $u, '7', [':submitted_at' => '2026-08-01 10:00:00']));
        $c = db_insert_submission($pdo, test_declaration_data($pdo, $u, '9'));
        db_accept_declaration($pdo, $c, $u);

        $decls = db_get_declarations_awaiting_review($pdo);
        $this->assertSame([$b, $a], $this->ids($decls));
        $this->assertSame('7', $decls[0]['car_number']);

        $fall = db_create_event($pdo, 'Fall Sprint', '2026-10-04', null);
        $s1 = test_make_sheet($pdo, $u, $a, $fall, '42');
        $s2 = test_make_sheet($pdo, $u, $b, $fall, '7');
        $pdo->exec("UPDATE tech_sheets SET photo_status = 'submitted', updated_at = '2026-09-10 00:00:00' WHERE id = $s1");
        $pdo->exec("UPDATE tech_sheets SET photo_status = 'needs_changes' WHERE id = $s2");
        $sheets = db_get_sheets_awaiting_photo_review($pdo);
        $this->assertSame([$s1], $this->ids($sheets));
        $this->assertSame('Fall Sprint', $sheets[0]['event_name']);

        $sam = db_create_driver($pdo, $u, 'Sam Patel');
        $g1 = db_insert_gear_record($pdo, $sam, 2026);
        $g2 = db_insert_gear_record($pdo, (int)db_get_self_driver($pdo, $u)['id'], 2026);
        $pdo->exec("UPDATE gear_records SET photo_status = 'submitted' WHERE id IN ($g1, $g2)");
        $pdo->exec("UPDATE gear_records SET status = 'accepted' WHERE id = $g2");
        $gear = db_get_gear_awaiting_photo_review($pdo);
        $this->assertSame([$g1], $this->ids($gear));
        $this->assertSame('Sam Patel', $gear[0]['driver_name']);
        $this->assertSame('Jordan Lee', $gear[0]['owner_name']);
        $this->assertSame($u, (int)$gear[0]['owner_user_id']);
    }

    public function testSearchDeclarationsFiltersAndPaginates(): void
    {
        $pdo = make_temp_pdo();
        $jordan = $this->user($pdo, 'jordan@example.com', 'Jordan Lee');
        $casey = $this->user($pdo, 'casey@example.com', 'Casey Moss');
        $a = db_insert_submission($pdo, test_declaration_data($pdo, $jordan, '42', [':submitted_at' => '2025-06-01 10:00:00', ':calculated_class' => 'GT3', ':make' => 'Honda', ':model' => 'S2000']));
        $b = db_insert_submission($pdo, test_declaration_data($pdo, $jordan, '17', [':submitted_at' => '2026-05-01 10:00:00']));
        $c = db_insert_submission($pdo, test_declaration_data($pdo, $casey, '8', [':submitted_at' => '2026-06-01 10:00:00', ':name' => 'Casey 50% Moss']));
        db_accept_declaration($pdo, $b, $jordan);
        $ids = fn(array $f, int $limit = 50, int $offset = 0): array => $this->ids(db_search_declarations($pdo, $f, $limit, $offset)['rows']);

        $this->assertSame([$c, $b, $a], $ids([]));
        $this->assertSame([$a], $ids(['q' => 's2000']));
        $this->assertSame([$a], $ids(['q' => '42']));                 // car number
        $this->assertSame([$c], $ids(['q' => '50%']));                // % is literal, not a wildcard
        $this->assertSame([], $ids(['q' => '_']));                    // so is _
        $this->assertSame([$c, $b], $ids(['class' => 'IT1']));
        $this->assertSame([$a], $ids(['season' => 2025]));
        $this->assertSame([$b], $ids(['status' => 'accepted']));
        $this->assertSame([$b], $ids(['car' => (int)db_get_submission($pdo, $b)['car_id']]));
        $this->assertSame([], $ids(['class' => 'IT1', 'season' => 2025]));

        $page = db_search_declarations($pdo, [], 2, 2);
        $this->assertSame(3, $page['total']);
        $this->assertSame([$a], $this->ids($page['rows']));
        $this->assertSame('42', db_search_declarations($pdo, ['q' => 's2000'], 50, 0)['rows'][0]['car_number']);
    }

    public function testEventPlanCounts(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'jordan@example.com', 'Jordan Lee');
        $fall = db_create_event($pdo, 'Fall Sprint', '2026-10-04', null);
        $finale = db_create_event($pdo, 'Finale', '2026-10-18', null);
        $empty = db_create_event($pdo, 'Empty', '2026-11-01', null);
        db_tag_event($pdo, $u, $fall, test_make_car($pdo, $u, '42'));
        db_tag_event($pdo, $u, $fall, test_make_car($pdo, $u, '17'));
        db_tag_event($pdo, $u, $finale, test_make_car($pdo, $u, '42'));

        $counts = db_count_event_plans($pdo);
        $this->assertSame(2, $counts[$fall]);
        $this->assertSame(1, $counts[$finale]);
        $this->assertArrayNotHasKey($empty, $counts);
    }
}
