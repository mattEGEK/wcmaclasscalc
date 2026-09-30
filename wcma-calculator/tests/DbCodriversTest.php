<?php
// wcma-calculator/tests/DbCodriversTest.php
use PHPUnit\Framework\TestCase;

final class DbCodriversTest extends TestCase
{
    /** @return array{0: PDO, 1: int, 2: int, 3: int, 4: int} pdo, user, car, self driver, co-driver */
    private function world(): array {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'cd' . uniqid() . '@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $car = test_make_car($pdo, $u, '42');
        $self = (int)db_get_self_driver($pdo, $u)['id'];
        $sam = db_create_driver($pdo, $u, 'Sam Lee');
        return [$pdo, $u, $car, $self, $sam];
    }

    public function testCarListNeverHoldsTheOwnerOrAnotherAccountsDriver(): void
    {
        [$pdo, $u, $car, $self, $sam] = $this->world();
        $other = db_create_user($pdo, ['email' => 'o' . uniqid() . '@example.com', 'name' => 'Other', 'password_hash' => 'x', 'google_id' => null]);
        $foreign = db_create_driver($pdo, $other, 'Foreign Driver');

        $this->assertTrue(db_add_car_driver($pdo, $u, $car, $sam));
        $this->assertTrue(db_add_car_driver($pdo, $u, $car, $sam));          // no duplicate, still fine
        $this->assertFalse(db_add_car_driver($pdo, $u, $car, $self));        // the owner is implied
        $this->assertFalse(db_add_car_driver($pdo, $u, $car, $foreign));
        $this->assertFalse(db_add_car_driver($pdo, $other, $car, $foreign)); // not their car
        $this->assertSame([$sam], array_map(fn(array $d): int => (int)$d['id'], db_get_car_drivers($pdo, $car)));
    }

    public function testNewEntryStartsWithTheOwnerAndUntagClearsIt(): void
    {
        [$pdo, $u, $car, $self] = $this->world();
        $event = db_create_event($pdo, 'Fall Sprint', '2099-10-11', null);
        $this->assertTrue(db_tag_event($pdo, $u, $event, $car));
        $entry = db_get_entry($pdo, $u, $event, $car);
        $this->assertSame([$self], db_get_entry_driver_ids($pdo, (int)$entry['id']));
        $this->assertFalse(db_tag_event($pdo, $u, $event, $car));             // re-tag adds nothing
        $this->assertSame([$self], db_get_entry_driver_ids($pdo, (int)$entry['id']));
        $this->assertSame((int)$entry['id'], (int)db_get_user_event_plans($pdo, $u)[0]['id']);

        db_untag_event($pdo, $u, $event, $car);
        $this->assertSame([], db_get_entry_driver_ids($pdo, (int)$entry['id']));
    }

    public function testSetAddAndListEntryDrivers(): void
    {
        [$pdo, $u, $car, $self, $sam] = $this->world();
        $event = db_create_event($pdo, 'Fall Sprint', '2099-10-11', null);
        db_tag_event($pdo, $u, $event, $car);
        $entryId = (int)db_get_entry($pdo, $u, $event, $car)['id'];
        db_set_entry_drivers($pdo, $entryId, [$sam]);
        $this->assertSame([$sam], db_get_entry_driver_ids($pdo, $entryId));
        db_add_entry_driver($pdo, $entryId, $self);
        db_add_entry_driver($pdo, $entryId, $self);
        $this->assertEqualsCanonicalizing([$sam, $self], db_get_entry_driver_ids($pdo, $entryId));
        $this->assertEqualsCanonicalizing([$sam, $self], db_get_entry_drivers_for_user($pdo, $u)[$entryId]);
    }

    public function testRemovingACoDriverUnticksUpcomingEntriesOnly(): void
    {
        [$pdo, $u, $car, $self, $sam] = $this->world();
        db_add_car_driver($pdo, $u, $car, $sam);
        $past = db_create_event($pdo, 'Old Race', '2000-01-01', null);
        $next = db_create_event($pdo, 'Fall Sprint', '2099-10-11', null);
        foreach ([$past, $next] as $e) {
            db_tag_event($pdo, $u, $e, $car);
            db_add_entry_driver($pdo, (int)db_get_entry($pdo, $u, $e, $car)['id'], $sam);
        }
        $this->assertTrue(db_remove_car_driver($pdo, $u, $car, $sam, '2026-09-30'));
        $this->assertSame([], db_get_car_drivers($pdo, $car));
        $this->assertSame([$self], db_get_entry_driver_ids($pdo, (int)db_get_entry($pdo, $u, $next, $car)['id']));
        $this->assertEqualsCanonicalizing([$self, $sam], db_get_entry_driver_ids($pdo, (int)db_get_entry($pdo, $u, $past, $car)['id']));
        $this->assertTrue((bool)db_get_driver($pdo, $sam));                  // the driver itself is kept
    }

    public function testRemovingTheOnlyTickedDriverPutsTheOwnerBack(): void
    {
        [$pdo, $u, $car, $self, $sam] = $this->world();
        db_add_car_driver($pdo, $u, $car, $sam);
        $next = db_create_event($pdo, 'Fall Sprint', '2099-10-11', null);
        db_tag_event($pdo, $u, $next, $car);
        $entryId = (int)db_get_entry($pdo, $u, $next, $car)['id'];
        db_set_entry_drivers($pdo, $entryId, [$sam]);
        db_remove_car_driver($pdo, $u, $car, $sam, '2026-09-30');
        $this->assertSame([$self], db_get_entry_driver_ids($pdo, $entryId));
    }

    public function testBackfillSeedsFromPastSheetsOnceOnly(): void
    {
        [$pdo, $u, $car, $self] = $this->world();
        $event = db_create_event($pdo, 'Fall Sprint', '2099-10-11', null);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42'));
        $sheet = test_make_sheet($pdo, $u, $sub, $event, '42', 'Pat Driver');   // driver 1 is a co-driver
        db_add_tech_sheet_driver($pdo, $sheet, 2, 'Sam Lee', '{}');
        db_tag_event($pdo, $u, $event, $car);
        // Simulate a database from before this change: drop the new tables and run the migration again.
        $pdo->exec('DROP TABLE car_drivers');
        $pdo->exec('DROP TABLE entry_drivers');
        db_init($pdo);

        $pat = (int)db_find_driver($pdo, $u, 'Pat Driver')['id'];
        $sam = (int)db_find_driver($pdo, $u, 'Sam Lee')['id'];
        $this->assertEqualsCanonicalizing([$pat, $sam], array_map(fn(array $d): int => (int)$d['id'], db_get_car_drivers($pdo, $car)));
        $entryId = (int)db_get_entry($pdo, $u, $event, $car)['id'];
        $this->assertEqualsCanonicalizing([$self, $pat, $sam], db_get_entry_driver_ids($pdo, $entryId));

        // Once only: a later db_init() does not re-add a co-driver the owner removed.
        db_remove_car_driver($pdo, $u, $car, $sam, '2000-01-01');
        db_init($pdo);
        $this->assertSame([$pat], array_map(fn(array $d): int => (int)$d['id'], db_get_car_drivers($pdo, $car)));
    }

    public function testEntryDriverBackfillIgnoresASheetDriverFromAnotherAccount(): void
    {
        [$pdo, $u, $car, $self] = $this->world();
        $other = db_create_user($pdo, ['email' => 'o' . uniqid() . '@example.com', 'name' => 'Other', 'password_hash' => 'x', 'google_id' => null]);
        $foreign = db_create_driver($pdo, $other, 'Foreign Driver');

        $event = db_create_event($pdo, 'Fall Sprint', '2099-10-11', null);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42'));
        $sheet = test_make_sheet($pdo, $u, $sub, $event, '42', 'Pat Driver');
        // Simulate a stale/mismatched sheet: driver 1 points at another account's driver row.
        $pdo->prepare('UPDATE tech_sheets SET driver_id = :d WHERE id = :s')->execute([':d' => $foreign, ':s' => $sheet]);
        db_tag_event($pdo, $u, $event, $car);

        $pdo->exec('DROP TABLE car_drivers');
        $pdo->exec('DROP TABLE entry_drivers');
        db_init($pdo);

        $entryId = (int)db_get_entry($pdo, $u, $event, $car)['id'];
        $this->assertSame([$self], db_get_entry_driver_ids($pdo, $entryId)); // the foreign driver is not pulled in
    }
}
