# Co-drivers per Car and Entry Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Co-drivers belong to a car for the season, and only count at events where the owner ticks them as driving. Gear to-dos, reminders, tech sheet driver pickers and pre-fill all follow who's driving each entry. Today they use every driver on the account.

**Architecture:**
- **Two new tables:**
  - `car_drivers (car_id, driver_id)` holds each car's co-driver list. The owner is never stored in it.
  - `entry_drivers (entry_id → event_plans.id, driver_id)` holds who's driving an entry, the owner included.
- **Tagging:** `db_tag_event()` puts the owner on every new entry, so every tagging path gets the default.
- **Changing drivers:** `events-lib.php` validates and stores posted driver lists. The Home and car-page "Change" forms gain a "Who's driving?" fieldset, which ice entries get too.
- **Readiness:** each entry's `driverIds` replaces the "every driver on the profile" merge in the race and ice paths. TA/Drift uses the entry's drivers once they're known.
- **Tech sheets:**
  - The driver pickers offer only the owner and the car's co-drivers.
  - Drivers named on a saved sheet join the car's list and are ticked on the entry (`eventsSyncSheetDrivers()`).
- **Backfill:** it runs once, when the tables are first created, and seeds both tables from past sheets and entries.

**Tech Stack:** PHP 8.3, SQLite (PDO), PHPUnit 10 (`php phpunit.phar`), node --test for JS, Playwright phone audit (`tests/ux`).

**Spec:** `docs/superpowers/specs/2026-09-30-codrivers-per-entry-design.md`. Read all of it. The Decisions table and §3–§5 drive most tasks.

## Global Constraints

- **Paths and tests:**
  - All paths are relative to `wcma-calculator/` unless they start with `docs/`.
  - PHPUnit: `cd /c/dev/wcmaclasscalc/wcma-calculator && php phpunit.phar` (Git Bash).
  - JS: `cd /c/dev/wcmaclasscalc/wcma-calculator && node --test tests/js/*.test.js`.
  - Phone audit: `bash wcma-calculator/tests/ux/run-audit.sh`, run from the repo root.
  - PHPUnit and JS must pass at the end of every task. The phone audit must pass at the end of Task 7.
- **No new dependencies and no build step.** Always use `require_once` for app includes (`tests/RequireOnceGuardTest.php`).
- **The owner is implied on every car:**
  - The account's own driver (`drivers.user_id = owner_user_id`, from `db_get_self_driver()`) is **never** stored in `car_drivers`.
  - It **is** stored in `entry_drivers` when the owner is driving.
- **An entry always has at least one driver:**
  - Saving an empty list is refused with `Tick at least one driver.`
  - Removing a co-driver from a car never leaves an upcoming entry empty. The owner goes back on.
- **Defaults:** a new entry has only the owner ticked. Co-drivers are never ticked automatically, except by a submitted sheet that names them (§4).
- **Copy, verbatim:**
  - Fieldset legend: `Who's driving?`
  - The owner's checkbox label: `You`
  - Car page section: heading `Co-drivers`, intro `People who share this car. Tick who's driving at each event.`, empty state `No co-drivers yet.`
  - Summary line: `Driving: You · Sam Lee`, with names joined by ` · `.
  - Endurance notice: `More than one driver is ticked for this event. Use the endurance sheet so everyone is on it.`
  - Errors: `Tick at least one driver.` and `Choose drivers from this car's list.`
- **Existing tests change only where listed.** Every other test passes unedited:

  | Test | Task |
  |---|---|
  | `ReadinessTest::testSeasonalItemsOnlyUnderTheNearestEventAndSheetsUnderEach` | 3 |
  | `ReadinessTest::testGearCoversTheSheetsDriversAndEveryDriverOnTheProfile` | 3 (replaced) |
  | `ReadinessTest::testTwoCarsTaggedToTheSameEventEachGetTheirOwnItemsAndShareTheSelfDriverGearItem` | 3 |
  | `ReadinessTaDriftTest` (the whole-entry `assertSame` at about line 47, and `testRaceEventAfterATaEventStillGetsRaceItems`) | 3 |
  | `ReadinessLoaderTest` (the `$in['plans']` assertion) | 3 |

  These assert the "every profile driver" behaviour, or the old entry shape, that this plan deliberately replaces. **If any other test fails, fix the code, not the test.**
- **Branch and commits:**
  - Branch `codrivers` from `main`.
  - Commit at the end of every task.
  - Every commit message ends with:
    ```
    Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
    Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5
    ```

## Review Focus

1. **The owner unticks themself.** Readiness must drop the owner's gear to-do for that entry, but not for another entry where they are ticked. Test: Task 3, `testUntickedOwnerGetsNoGearTodoForThatEntryOnly`.
2. **Removing the last ticked co-driver from a car** when the owner was unticked must not leave an empty entry. The owner goes back on. Test: Task 1, `testRemovingTheOnlyTickedDriverPutsTheOwnerBack`.
3. **A posted driver ID from another account or another car** must be refused, and nothing stored. Test: Task 2, `testForeignOrOffListDriverIsRefused`.
4. **Deploying on an existing database:**
  - The backfill must keep every entry's current gear drivers: the owner plus that event's sheet drivers.
  - It must run once only.
  - Test: Task 1, `testBackfillSeedsFromPastSheetsOnceOnly`.
5. **Ice entries** get "Who's driving?" with no format boxes, and saving it must not touch formats. Test: Task 5, `testIceEntryChangeFormHasDriversOnly`.

---

### Task 1: Schema, backfill and database functions

**Files:**
- Modify: `db.php`:
  - the end of `db_init()`
  - `db_tag_event`
  - `db_untag_event`
  - `db_get_user_event_plans`
  - new functions after `db_get_car_last_summer_formats`
- Test: `tests/DbCodriversTest.php` (new)

**Interfaces:**
- Produces:
  - tables `car_drivers` and `entry_drivers`
  - `db_get_car_drivers(PDO $pdo, int $carId): array` returns the `drivers` rows, by name
  - `db_add_car_driver(PDO $pdo, int $userId, int $carId, int $driverId): bool`
  - `db_remove_car_driver(PDO $pdo, int $userId, int $carId, int $driverId, string $today): bool`
  - `db_get_entry_driver_ids(PDO $pdo, int $entryId): int[]`
  - `db_set_entry_drivers(PDO $pdo, int $entryId, array $driverIds): void`
  - `db_add_entry_driver(PDO $pdo, int $entryId, int $driverId): void`
  - `db_get_entry_drivers_for_user(PDO $pdo, int $userId): array<int entryId, int[]>`
  - `db_tag_event()` puts the owner's own driver on a new entry
  - `db_untag_event()` deletes that entry's drivers
  - `db_get_user_event_plans()` rows also carry `id`

- [ ] **Step 1: Create the branch**

```bash
cd /c/dev/wcmaclasscalc && git checkout main && git pull --ff-only && git checkout -b codrivers
```

- [ ] **Step 2: Write the failing test**

```php
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
}
```

- [ ] **Step 3: Run it to see it fail**

Run: `cd /c/dev/wcmaclasscalc/wcma-calculator && php phpunit.phar --filter DbCodriversTest`
Expected: FAIL. `db_add_car_driver` is undefined.

`test_make_sheet()` goes through `db_insert_tech_sheet()`, which creates or finds the driver for `driver_name` (`db_find_or_create_driver`) and takes the car from the declaration. `test_declaration_data($pdo, $u, '42')` resolves car `42` through `test_make_car()`, which is the same car as `$car`.

- [ ] **Step 4: Add the schema and backfill at the end of `db_init()`**

Append after the last `db_add_column_if_missing(...)` line in `db_init()`:

```php
    // ── Co-drivers per car and entry (2026-09-30 spec §1). New tables; seeded once, when first created. ──
    $hasCarDrivers = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'car_drivers'")->fetchColumn();
    $hasEntryDrivers = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'entry_drivers'")->fetchColumn();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS car_drivers (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            car_id     INTEGER NOT NULL,
            driver_id  INTEGER NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE (car_id, driver_id)
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS entry_drivers (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            entry_id   INTEGER NOT NULL,
            driver_id  INTEGER NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE (entry_id, driver_id)
        )
    ");
    $now = date('Y-m-d H:i:s');
    if (!$hasCarDrivers) {
        // Every non-owner driver named on any of the car's sheets (driver 1 or an added driver).
        $pdo->prepare("
            INSERT OR IGNORE INTO car_drivers (car_id, driver_id, created_at)
            SELECT DISTINCT s.car_id, d.id, :now FROM (
                SELECT car_id, driver_id FROM tech_sheets WHERE car_id IS NOT NULL AND driver_id IS NOT NULL
                UNION
                SELECT ts.car_id, tsd.driver_id FROM tech_sheet_drivers tsd JOIN tech_sheets ts ON ts.id = tsd.tech_sheet_id
                WHERE ts.car_id IS NOT NULL AND tsd.driver_id IS NOT NULL
            ) s
            JOIN cars c ON c.id = s.car_id
            JOIN drivers d ON d.id = s.driver_id
            WHERE d.owner_user_id = c.owner_user_id AND (d.user_id IS NULL OR d.user_id != d.owner_user_id)
        ")->execute([':now' => $now]);
    }
    if (!$hasEntryDrivers) {
        // Each entry: the owner's own driver, plus everyone on that event's sheet for the car.
        $pdo->prepare("
            INSERT OR IGNORE INTO entry_drivers (entry_id, driver_id, created_at)
            SELECT p.id, d.id, :now FROM event_plans p JOIN drivers d ON d.user_id = p.user_id AND d.owner_user_id = p.user_id
        ")->execute([':now' => $now]);
        $pdo->prepare("
            INSERT OR IGNORE INTO entry_drivers (entry_id, driver_id, created_at)
            SELECT p.id, s.driver_id, :now FROM event_plans p JOIN (
                SELECT car_id, event_id, driver_id FROM tech_sheets WHERE driver_id IS NOT NULL
                UNION
                SELECT ts.car_id, ts.event_id, tsd.driver_id FROM tech_sheet_drivers tsd JOIN tech_sheets ts ON ts.id = tsd.tech_sheet_id
                WHERE tsd.driver_id IS NOT NULL
            ) s ON s.car_id = p.car_id AND s.event_id = p.event_id
        ")->execute([':now' => $now]);
    }
```

- [ ] **Step 5: Tag, untag and the plans query**

Replace `db_tag_event`, `db_untag_event` and `db_get_user_event_plans` with:

```php
/** Tags the car for the event. True if it was not tagged yet: a new entry starts as race, with the owner driving (co-drivers spec §3). */
function db_tag_event(PDO $pdo, int $userId, int $eventId, int $carId): bool {
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO event_plans (user_id, event_id, car_id, created_at) VALUES (:u, :e, :c, :now)");
    $stmt->execute([':u' => $userId, ':e' => $eventId, ':c' => $carId, ':now' => date('Y-m-d H:i:s')]);
    $added = $stmt->rowCount() === 1;
    if ($added) {
        $self = db_get_self_driver($pdo, $userId);
        $entry = db_get_entry($pdo, $userId, $eventId, $carId);
        if ($self !== null && $entry !== null) db_add_entry_driver($pdo, (int)$entry['id'], (int)$self['id']);
    }
    return $added;
}

function db_untag_event(PDO $pdo, int $userId, int $eventId, int $carId): void {
    $pdo->prepare("DELETE FROM entry_drivers WHERE entry_id IN (SELECT id FROM event_plans WHERE user_id = :u AND event_id = :e AND car_id = :c)")
        ->execute([':u' => $userId, ':e' => $eventId, ':c' => $carId]);
    $pdo->prepare("DELETE FROM event_plans WHERE user_id = :u AND event_id = :e AND car_id = :c")
        ->execute([':u' => $userId, ':e' => $eventId, ':c' => $carId]);
}

function db_get_user_event_plans(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT id, event_id, car_id, formats, supps_ack_at FROM event_plans WHERE user_id = :u ORDER BY event_id ASC, car_id ASC");
    $stmt->execute([':u' => $userId]);
    return $stmt->fetchAll();
}
```

- [ ] **Step 6: Car and entry driver functions**

Add these after `db_get_car_last_summer_formats()`:

```php
// ── Co-drivers per car and entry (2026-09-30 spec §1) ────────────────────────

/** The car's co-driver list (drivers rows, by name). The owner is never on it. */
function db_get_car_drivers(PDO $pdo, int $carId): array {
    $stmt = $pdo->prepare("SELECT d.* FROM car_drivers cd JOIN drivers d ON d.id = cd.driver_id WHERE cd.car_id = :c ORDER BY d.name_norm ASC, d.id ASC");
    $stmt->execute([':c' => $carId]);
    return $stmt->fetchAll();
}

/** Adds one of the user's co-drivers to one of their cars. False for the owner's own driver, or anything not theirs. */
function db_add_car_driver(PDO $pdo, int $userId, int $carId, int $driverId): bool {
    $driver = db_get_driver($pdo, $driverId);
    if (db_get_user_car($pdo, $userId, $carId) === null || $driver === null || (int)$driver['owner_user_id'] !== $userId
        || (int)($driver['user_id'] ?? 0) === $userId) {
        return false;
    }
    $pdo->prepare("INSERT OR IGNORE INTO car_drivers (car_id, driver_id, created_at) VALUES (:c, :d, :now)")
        ->execute([':c' => $carId, ':d' => $driverId, ':now' => date('Y-m-d H:i:s')]);
    return true;
}

/**
 * Takes a co-driver off a car (the driver is kept). They are unticked from the car's entries for
 * events on or after $today; an entry left with nobody gets the owner back. Past entries keep them.
 */
function db_remove_car_driver(PDO $pdo, int $userId, int $carId, int $driverId, string $today): bool {
    if (db_get_user_car($pdo, $userId, $carId) === null) return false;
    $pdo->prepare("DELETE FROM car_drivers WHERE car_id = :c AND driver_id = :d")->execute([':c' => $carId, ':d' => $driverId]);
    $upcoming = "SELECT p.id FROM event_plans p JOIN events e ON e.id = p.event_id WHERE p.car_id = :c AND p.user_id = :u AND e.event_date >= :t";
    $pdo->prepare("DELETE FROM entry_drivers WHERE driver_id = :d AND entry_id IN ($upcoming)")
        ->execute([':d' => $driverId, ':c' => $carId, ':u' => $userId, ':t' => $today]);
    $self = db_get_self_driver($pdo, $userId);
    if ($self !== null) {
        $pdo->prepare("
            INSERT OR IGNORE INTO entry_drivers (entry_id, driver_id, created_at)
            SELECT p.id, :s, :now FROM event_plans p JOIN events e ON e.id = p.event_id
            WHERE p.car_id = :c AND p.user_id = :u AND e.event_date >= :t
              AND NOT EXISTS (SELECT 1 FROM entry_drivers x WHERE x.entry_id = p.id)
        ")->execute([':s' => (int)$self['id'], ':now' => date('Y-m-d H:i:s'), ':c' => $carId, ':u' => $userId, ':t' => $today]);
    }
    return true;
}

/** @return int[] who's driving an entry, in the order they were added */
function db_get_entry_driver_ids(PDO $pdo, int $entryId): array {
    $stmt = $pdo->prepare("SELECT driver_id FROM entry_drivers WHERE entry_id = :e ORDER BY id ASC");
    $stmt->execute([':e' => $entryId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Replaces who's driving an entry. Callers validate the list (eventsValidateDriverIds()). */
function db_set_entry_drivers(PDO $pdo, int $entryId, array $driverIds): void {
    $pdo->prepare("DELETE FROM entry_drivers WHERE entry_id = :e")->execute([':e' => $entryId]);
    foreach ($driverIds as $d) db_add_entry_driver($pdo, $entryId, (int)$d);
}

function db_add_entry_driver(PDO $pdo, int $entryId, int $driverId): void {
    $pdo->prepare("INSERT OR IGNORE INTO entry_drivers (entry_id, driver_id, created_at) VALUES (:e, :d, :now)")
        ->execute([':e' => $entryId, ':d' => $driverId, ':now' => date('Y-m-d H:i:s')]);
}

/** @return array<int, int[]> entry id => driver ids, for all of the user's entries */
function db_get_entry_drivers_for_user(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT x.entry_id, x.driver_id FROM entry_drivers x JOIN event_plans p ON p.id = x.entry_id WHERE p.user_id = :u ORDER BY x.id ASC");
    $stmt->execute([':u' => $userId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) $out[(int)$r['entry_id']][] = (int)$r['driver_id'];
    return $out;
}
```

- [ ] **Step 7: Run the tests**

Run: `cd /c/dev/wcmaclasscalc/wcma-calculator && php phpunit.phar --filter DbCodriversTest`
Expected: PASS (6 tests).

Then run `php phpunit.phar`. `ReadinessLoaderTest` still passes, because the loader maps plan rows to fixed keys and the new `id` column isn't copied yet. Fix anything else that fails in code.

- [ ] **Step 8: Commit**

```bash
cd /c/dev/wcmaclasscalc && git add wcma-calculator/db.php wcma-calculator/tests/DbCodriversTest.php
git commit -m "feat(codrivers): car and entry driver tables, backfill, and the owner on every new entry

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 2: Entry drivers: validation, tag/change, co-driver list and sheet sync

**Files:**
- Modify: `events-lib.php`
- Test: `tests/EventsCodriversTest.php` (new)

**Interfaces:**
- Consumes: Task 1's `db_*` functions.
- Produces (all in `events-lib.php`):
  - `entryDriversFromPost(array $post): ?array`: null when the "Who's driving?" fieldset wasn't posted (no `drivers_shown`), otherwise the posted `drivers[]` values.
  - `eventsCarDriverChoices(PDO $pdo, int $userId, int $carId): array<int, array{id: int, name: string, isSelf: bool}>`: the owner first, then the car's co-drivers.
  - `eventsValidateDriverIds(PDO $pdo, int $userId, int $carId, mixed $picked): array{ok: bool, ids: int[], error: ?string}`
  - `eventsTagCar(..., ?array $formats = null, bool $suppsAck = false, ?array $driverIds = null)`
  - `eventsSetFormats(..., $formats, bool $suppsAck, ?array $driverIds = null)`
  - `eventsAddCoDriver(PDO $pdo, int $userId, int $carId, array $post): array{ok: bool, error: ?string}`, where `$post` has `driver_id` (an id, or `new` plus `new_name`)
  - `eventsRemoveCoDriver(PDO $pdo, int $userId, int $carId, int $driverId): array{ok: bool, error: ?string}`
  - `eventsSheetDriverRows(PDO $pdo, int $userId, int $carId): array`: full `drivers` rows, the owner first, then the car's co-drivers. These are the sheet pickers.
  - `eventsSheetPrefill(PDO $pdo, int $userId, int $carId, int $eventId): array{driver1: ?int, others: int[], count: int}`
  - `eventsSyncSheetDrivers(PDO $pdo, int $userId, int $sheetId): void`

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/EventsCodriversTest.php
require_once __DIR__ . '/../events-lib.php';

use PHPUnit\Framework\TestCase;

final class EventsCodriversTest extends TestCase
{
    /** @return array{0: PDO, 1: int, 2: int, 3: int, 4: int, 5: int} pdo, user, car, self, sam, event */
    private function world(): array {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'ec' . uniqid() . '@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $car = db_create_car($pdo, $u, ['car_number' => '42', 'make' => 'Honda', 'model' => 'S2000', 'disciplines' => 'summer']);
        $self = (int)db_get_self_driver($pdo, $u)['id'];
        $sam = db_create_driver($pdo, $u, 'Sam Lee');
        db_add_car_driver($pdo, $u, $car, $sam);
        $event = db_create_event($pdo, 'Fall Sprint', '2099-10-11', null);
        return [$pdo, $u, $car, $self, $sam, $event];
    }

    public function testDriversFromPost(): void
    {
        $this->assertNull(entryDriversFromPost([]));
        $this->assertSame([], entryDriversFromPost(['drivers_shown' => '1']));
        $this->assertSame(['5', '6'], entryDriversFromPost(['drivers_shown' => '1', 'drivers' => ['5', '6']]));
    }

    public function testChoicesAreTheOwnerThenTheCarsCoDrivers(): void
    {
        [$pdo, $u, $car, $self, $sam] = $this->world();
        db_create_driver($pdo, $u, 'Not On This Car');
        $this->assertSame([
            ['id' => $self, 'name' => 'Jordan Lee', 'isSelf' => true],
            ['id' => $sam, 'name' => 'Sam Lee', 'isSelf' => false],
        ], eventsCarDriverChoices($pdo, $u, $car));
        $this->assertSame([$self, $sam], array_map(fn(array $d): int => (int)$d['id'], eventsSheetDriverRows($pdo, $u, $car)));
    }

    public function testTagDefaultsToTheOwnerAndTakesAPostedList(): void
    {
        [$pdo, $u, $car, $self, $sam, $event] = $this->world();
        $this->assertTrue(eventsTagCar($pdo, $u, $event, $car)['ok']);
        $entryId = (int)db_get_entry($pdo, $u, $event, $car)['id'];
        $this->assertSame([$self], db_get_entry_driver_ids($pdo, $entryId));

        $this->assertTrue(eventsSetFormats($pdo, $u, $event, $car, ['race'], false, [(string)$sam])['ok']);
        $this->assertSame([$sam], db_get_entry_driver_ids($pdo, $entryId));
        $this->assertTrue(eventsSetFormats($pdo, $u, $event, $car, ['race'], false)['ok']);   // null leaves drivers alone
        $this->assertSame([$sam], db_get_entry_driver_ids($pdo, $entryId));
    }

    public function testForeignOrOffListDriverIsRefused(): void
    {
        [$pdo, $u, $car, $self, $sam, $event] = $this->world();
        eventsTagCar($pdo, $u, $event, $car);
        $entryId = (int)db_get_entry($pdo, $u, $event, $car)['id'];
        $offList = db_create_driver($pdo, $u, 'Not On This Car');
        $other = db_create_user($pdo, ['email' => 'o' . uniqid() . '@example.com', 'name' => 'Other', 'password_hash' => 'x', 'google_id' => null]);
        $foreign = db_create_driver($pdo, $other, 'Foreign');

        foreach ([[$offList], [$foreign], [$self, 'abc']] as $bad) {
            $r = eventsSetFormats($pdo, $u, $event, $car, ['race'], false, $bad);
            $this->assertSame([false, "Choose drivers from this car's list."], [$r['ok'], $r['error']]);
        }
        $r = eventsSetFormats($pdo, $u, $event, $car, ['race'], false, []);
        $this->assertSame([false, 'Tick at least one driver.'], [$r['ok'], $r['error']]);
        $this->assertSame([$self], db_get_entry_driver_ids($pdo, $entryId));    // nothing stored

        // A bad list on a new tag refuses the tag too.
        $next = db_create_event($pdo, 'Finale', '2099-10-25', null);
        $this->assertFalse(eventsTagCar($pdo, $u, $next, $car, ['race'], false, [])['ok']);
        $this->assertNull(db_get_entry($pdo, $u, $next, $car));
    }

    public function testAddAndRemoveCoDriver(): void
    {
        [$pdo, $u, $car, $self, $sam, $event] = $this->world();
        $pat = db_create_driver($pdo, $u, 'Pat Driver');
        $this->assertTrue(eventsAddCoDriver($pdo, $u, $car, ['driver_id' => (string)$pat])['ok']);
        $this->assertTrue(eventsAddCoDriver($pdo, $u, $car, ['driver_id' => 'new', 'new_name' => '  Alex   Kim '])['ok']);
        $this->assertSame(['Alex Kim', 'Pat Driver', 'Sam Lee'], array_map(fn(array $d): string => (string)$d['name'], db_get_car_drivers($pdo, $car)));
        $this->assertFalse(eventsAddCoDriver($pdo, $u, $car, ['driver_id' => 'new', 'new_name' => ' '])['ok']);
        $this->assertFalse(eventsAddCoDriver($pdo, $u, $car, ['driver_id' => (string)$self])['ok']);

        $this->assertTrue(eventsRemoveCoDriver($pdo, $u, $car, $pat)['ok']);
        $this->assertNotContains($pat, array_map(fn(array $d): int => (int)$d['id'], db_get_car_drivers($pdo, $car)));
    }

    public function testPrefillFollowsTheTickedDrivers(): void
    {
        [$pdo, $u, $car, $self, $sam, $event] = $this->world();
        $this->assertSame(['driver1' => null, 'others' => [], 'count' => 0], eventsSheetPrefill($pdo, $u, $car, $event));   // no entry
        eventsTagCar($pdo, $u, $event, $car);
        $this->assertSame(['driver1' => $self, 'others' => [], 'count' => 1], eventsSheetPrefill($pdo, $u, $car, $event));
        eventsSetFormats($pdo, $u, $event, $car, ['race'], false, [$sam, $self]);
        $this->assertSame(['driver1' => $self, 'others' => [$sam], 'count' => 2], eventsSheetPrefill($pdo, $u, $car, $event));
        eventsSetFormats($pdo, $u, $event, $car, ['race'], false, [$sam]);
        $this->assertSame(['driver1' => $sam, 'others' => [], 'count' => 1], eventsSheetPrefill($pdo, $u, $car, $event));
    }

    public function testASavedSheetsDriversJoinTheCarAndAreTicked(): void
    {
        [$pdo, $u, $car, $self, $sam, $event] = $this->world();
        eventsTagCar($pdo, $u, $event, $car);
        $sheet = test_make_ta_drift_sheet($pdo, $u, $car, db_create_event($pdo, 'WSCC TA', '2099-11-01', null, 'summer', 'WSCC'));
        $taEvent = (int)db_get_tech_sheet($pdo, $sheet)['event_id'];
        db_add_tech_sheet_driver($pdo, $sheet, 2, 'New Person', '{}');

        eventsSyncSheetDrivers($pdo, $u, $sheet);   // no entry for that event yet: only the car list changes
        $new = (int)db_find_driver($pdo, $u, 'New Person')['id'];
        $this->assertContains($new, array_map(fn(array $d): int => (int)$d['id'], db_get_car_drivers($pdo, $car)));

        db_tag_event($pdo, $u, $taEvent, $car);
        eventsSyncSheetDrivers($pdo, $u, $sheet);
        $entryId = (int)db_get_entry($pdo, $u, $taEvent, $car)['id'];
        $this->assertContains($new, db_get_entry_driver_ids($pdo, $entryId));
    }
}
```

`db_get_tech_sheet($pdo, $id)`, `db_add_tech_sheet_driver($pdo, $sheetId, $number, $name, $equipmentJson)` and `db_create_event($pdo, $name, $date, $location, $discipline, $hostClub)` are the existing `db.php` functions.

- [ ] **Step 2: Run it to see it fail**

Run: `cd /c/dev/wcmaclasscalc/wcma-calculator && php phpunit.phar --filter EventsCodriversTest`
Expected: FAIL. `entryDriversFromPost` is undefined.

- [ ] **Step 3: Implement in `events-lib.php`**

Replace `eventsTagCar` and `eventsSetFormats`:

```php
function eventsTagCar(PDO $pdo, int $userId, int $eventId, int $carId, ?array $formats = null, bool $suppsAck = false, ?array $driverIds = null): array {
    $car = db_get_user_car($pdo, $userId, $carId);
    if ($car === null || $car['archived_at'] !== null) return ['ok' => false, 'error' => 'Choose one of your cars.'];
    $event = db_get_event($pdo, $eventId);
    if ($event === null || (int)$event['active'] !== 1) return ['ok' => false, 'error' => 'That event is not open.'];
    if ($formats !== null) {
        $v = entryFormatsValidate($event, $formats);
        if (!$v['ok']) return ['ok' => false, 'error' => $v['error']];
    }
    if ($driverIds !== null) {
        $dv = eventsValidateDriverIds($pdo, $userId, $carId, $driverIds);
        if (!$dv['ok']) return ['ok' => false, 'error' => $dv['error']];
    }
    $added = db_tag_event($pdo, $userId, $eventId, $carId);
    if ($formats !== null) {
        eventsStoreFormats($pdo, $userId, $eventId, $carId, $v['formats'], $suppsAck);
    } elseif ($added) {
        db_set_entry_formats($pdo, $userId, $eventId, $carId, entryFormatsStore(eventsDefaultFormats($pdo, $car, $event)), null);
    }
    if ($driverIds !== null) {
        db_set_entry_drivers($pdo, (int)db_get_entry($pdo, $userId, $eventId, $carId)['id'], $dv['ids']);
    }
    return ['ok' => true, 'error' => null];
}

/**
 * Change an entry the user already has (the event card): its formats and, when the "Who's driving?"
 * fieldset was posted ($driverIds not null), who's driving. Drivers are checked first, so a bad
 * list stores nothing.
 * @param mixed $formats the posted list
 * @return array{ok: bool, error: ?string}
 */
function eventsSetFormats(PDO $pdo, int $userId, int $eventId, int $carId, $formats, bool $suppsAck, ?array $driverIds = null): array {
    $entry = db_get_entry($pdo, $userId, $eventId, $carId);
    if ($entry === null) return ['ok' => false, 'error' => 'Add this car to the event first.'];
    $event = db_get_event($pdo, $eventId);
    if ($event === null) return ['ok' => false, 'error' => 'That event is not open.'];
    $v = entryFormatsValidate($event, $formats);
    if (!$v['ok']) return ['ok' => false, 'error' => $v['error']];
    if ($driverIds !== null) {
        $dv = eventsValidateDriverIds($pdo, $userId, $carId, $driverIds);
        if (!$dv['ok']) return ['ok' => false, 'error' => $dv['error']];
    }
    eventsStoreFormats($pdo, $userId, $eventId, $carId, $v['formats'], $suppsAck);
    if ($driverIds !== null) db_set_entry_drivers($pdo, (int)$entry['id'], $dv['ids']);
    return ['ok' => true, 'error' => null];
}
```

Then add at the end of `events-lib.php`:

```php
// ── Co-drivers per car and entry (2026-09-30 spec §2–§4) ─────────────────────

const ENTRY_DRIVERS_NONE = 'Tick at least one driver.';
const ENTRY_DRIVERS_OFF_LIST = "Choose drivers from this car's list.";

/** The posted "Who's driving?" ticks, or null when that fieldset wasn't on the form. */
function entryDriversFromPost(array $post): ?array {
    if (!isset($post['drivers_shown'])) return null;
    return is_array($post['drivers'] ?? null) ? array_values($post['drivers']) : [];
}

/** The owner first ("You"), then the car's co-drivers. @return array<int, array{id: int, name: string, isSelf: bool}> */
function eventsCarDriverChoices(PDO $pdo, int $userId, int $carId): array {
    return array_map(fn(array $d): array => ['id' => (int)$d['id'], 'name' => (string)$d['name'],
        'isSelf' => (int)($d['user_id'] ?? 0) === $userId], eventsSheetDriverRows($pdo, $userId, $carId));
}

/** Full drivers rows for the sheet pickers: the owner, then the car's co-drivers. */
function eventsSheetDriverRows(PDO $pdo, int $userId, int $carId): array {
    $self = db_get_self_driver($pdo, $userId);
    return array_merge($self !== null ? [$self] : [], db_get_car_drivers($pdo, $carId));
}

/** @param mixed $picked @return array{ok: bool, ids: int[], error: ?string} */
function eventsValidateDriverIds(PDO $pdo, int $userId, int $carId, $picked): array {
    if (!is_array($picked) || $picked === []) return ['ok' => false, 'ids' => [], 'error' => ENTRY_DRIVERS_NONE];
    $allowed = array_map(fn(array $c): int => $c['id'], eventsCarDriverChoices($pdo, $userId, $carId));
    $ids = [];
    foreach ($picked as $p) {
        if (!(is_int($p) || (is_string($p) && ctype_digit($p))) || !in_array((int)$p, $allowed, true)) {
            return ['ok' => false, 'ids' => [], 'error' => ENTRY_DRIVERS_OFF_LIST];
        }
        $ids[(int)$p] = (int)$p;
    }
    return ['ok' => true, 'ids' => array_values($ids), 'error' => null];
}

/** Car page "Add a co-driver": one of the user's drivers by id, or a new name ('new'). @return array{ok: bool, error: ?string} */
function eventsAddCoDriver(PDO $pdo, int $userId, int $carId, array $post): array {
    $choice = (string)($post['driver_id'] ?? '');
    if ($choice === 'new') {
        $name = trim((string)preg_replace('/\s+/', ' ', (string)($post['new_name'] ?? '')));
        if ($name === '' || mb_strlen($name, 'UTF-8') > 100) return ['ok' => false, 'error' => 'Enter the co-driver\'s name.'];
        $driverId = db_find_or_create_driver($pdo, $userId, $name);
    } else {
        $driverId = ctype_digit($choice) ? (int)$choice : 0;
    }
    return $driverId !== null && $driverId > 0 && db_add_car_driver($pdo, $userId, $carId, $driverId)
        ? ['ok' => true, 'error' => null]
        : ['ok' => false, 'error' => 'Choose one of your co-drivers, or add a name.'];
}

/** @return array{ok: bool, error: ?string} */
function eventsRemoveCoDriver(PDO $pdo, int $userId, int $carId, int $driverId): array {
    return db_remove_car_driver($pdo, $userId, $carId, $driverId, date('Y-m-d'))
        ? ['ok' => true, 'error' => null]
        : ['ok' => false, 'error' => 'Choose one of your cars.'];
}

/**
 * What a new tech sheet starts with (spec §4): driver 1 is the owner when ticked, else the first
 * ticked co-driver; the rest are the added drivers. All empty when the car has no entry there.
 * @return array{driver1: ?int, others: int[], count: int}
 */
function eventsSheetPrefill(PDO $pdo, int $userId, int $carId, int $eventId): array {
    $entry = $eventId > 0 ? db_get_entry($pdo, $userId, $eventId, $carId) : null;
    $ids = $entry !== null ? db_get_entry_driver_ids($pdo, (int)$entry['id']) : [];
    if ($ids === []) return ['driver1' => null, 'others' => [], 'count' => 0];
    $selfId = (int)(db_get_self_driver($pdo, $userId)['id'] ?? 0);
    $first = in_array($selfId, $ids, true) ? $selfId : $ids[0];
    return ['driver1' => $first, 'others' => array_values(array_filter($ids, fn(int $i): bool => $i !== $first)), 'count' => count($ids)];
}

/**
 * After a tech sheet is saved (spec §4): everyone it names joins the car's co-driver list (the owner
 * excepted) and is ticked on that event's entry, if there is one. The sheet wins over the entry.
 */
function eventsSyncSheetDrivers(PDO $pdo, int $userId, int $sheetId): void {
    $sheet = db_get_tech_sheet($pdo, $sheetId);
    if ($sheet === null || (int)$sheet['user_id'] !== $userId || empty($sheet['car_id'])) return;
    $carId = (int)$sheet['car_id'];
    $ids = [(int)($sheet['driver_id'] ?? 0)];
    foreach (db_get_tech_sheet_drivers($pdo, $sheetId) as $row) $ids[] = (int)($row['driver_id'] ?? 0);
    $entry = db_get_entry($pdo, $userId, (int)$sheet['event_id'], $carId);
    foreach (array_unique(array_filter($ids)) as $did) {
        db_add_car_driver($pdo, $userId, $carId, $did);   // false (and skipped) for the owner
        if ($entry !== null) db_add_entry_driver($pdo, (int)$entry['id'], $did);
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `cd /c/dev/wcmaclasscalc/wcma-calculator && php phpunit.phar --filter "EventsCodriversTest|EventsTaDriftTest|EventsTagForSheetTest|EventsTagFormatsTest"`
Expected: PASS. Then run the full suite.

- [ ] **Step 5: Commit**

```bash
cd /c/dev/wcmaclasscalc && git add wcma-calculator/events-lib.php wcma-calculator/tests/EventsCodriversTest.php
git commit -m "feat(codrivers): who's driving an entry, the car's co-driver list, and sheet drivers joining both

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 3: Readiness asks for gear from who's driving each entry

**Files:**
- Modify: `readiness-lib.php`:
  - `readinessEntry`
  - `buildReadiness` (race path)
  - `readinessIceCarItems`
  - `readinessTaDriftCarItems`
  - `loadReadinessInputs`
  - new `readinessEntryDriverIds`
- Modify (deliberate updates listed in Global Constraints): `tests/ReadinessTest.php`, `tests/ReadinessTaDriftTest.php`, `tests/ReadinessLoaderTest.php`
- Test: `tests/ReadinessCodriversTest.php` (new)

**Interfaces:**
- Consumes: `db_get_entry_drivers_for_user()`; `db_get_user_event_plans()` rows with `id` (Task 1).
- Produces:
  - Plan rows in `loadReadinessInputs()['plans']` carry `'drivers' => int[]`, which is empty when the entry has none stored.
  - `readinessEntry(array $event, ?array $plan, int $selfDriverId = 0)` adds:
    - `'driverIds' => int[]`: the stored drivers, else `[selfDriverId]`
    - `'driversKnown' => bool`: true when stored
  - `readinessEntryDriverIds(array $entry, ?array $eventSheet, array $sheetDrivers): int[]`: the entry's drivers plus the drivers the event sheet names.
  - Race and ice entries ask for gear from `readinessEntryDriverIds()` only.
  - TA/Drift entries use it when `driversKnown`. Otherwise they keep the old sheet-source fallback.

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/ReadinessCodriversTest.php
require_once __DIR__ . '/../tech-status.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../readiness-lib.php';
require_once __DIR__ . '/../reminders-lib.php';

use PHPUnit\Framework\TestCase;

final class ReadinessCodriversTest extends TestCase
{
    private function world(array $o = []): array {
        return array_merge([
            'today' => '2026-09-26',
            'cars' => [3 => ['id' => 3, 'car_number' => '42', 'make' => 'Honda', 'model' => 'S2000']],
            'events' => [
                ['id' => 10, 'name' => 'Fall Sprint', 'event_date' => '2026-10-11'],
                ['id' => 11, 'name' => 'Season Finale', 'event_date' => '2026-10-25'],
            ],
            'plans' => [['event_id' => 10, 'car_id' => 3, 'drivers' => [5]], ['event_id' => 11, 'car_id' => 3, 'drivers' => [5, 6]]],
            'declarations' => [3 => ['review_status' => 'accepted', 'submitted_at' => '2026-04-02', 'calculated_class' => 'GT3']],
            'sheets' => [], 'sheetDrivers' => [],
            'drivers' => [5 => ['id' => 5, 'name' => 'Jordan Lee'], 6 => ['id' => 6, 'name' => 'Sam Lee'], 7 => ['id' => 7, 'name' => 'Pat Driver']],
            'selfDriverId' => 5, 'gear' => [], 'atTrack' => [],
        ], $o);
    }

    private function gearIds(array $r, int $i): array {
        $ids = [];
        foreach ($r['events'][$i]['items'] as $item) if ($item['kind'] === 'gear') $ids[] = (int)$item['subject_id'];
        return $ids;
    }

    public function testOnlyTickedDriversGetGearTodos(): void
    {
        $r = buildReadiness($this->world());
        $this->assertSame([5], $this->gearIds($r, 0));      // Sam isn't driving the first event
        $this->assertSame([6], $this->gearIds($r, 1));      // Sam is ticked at the finale; Jordan's gear was listed already
        $this->assertNotContains(7, array_merge($this->gearIds($r, 0), $this->gearIds($r, 1)));   // Pat drives nothing
    }

    public function testUntickedOwnerGetsNoGearTodoForThatEntryOnly(): void
    {
        $r = buildReadiness($this->world(['plans' => [['event_id' => 10, 'car_id' => 3, 'drivers' => [6]], ['event_id' => 11, 'car_id' => 3, 'drivers' => [5]]]]));
        $this->assertSame([6], $this->gearIds($r, 0));
        $this->assertSame([5], $this->gearIds($r, 1));
    }

    public function testTheEventSheetsDriversAlwaysCount(): void
    {
        $sheet = ['id' => 70, 'car_id' => 3, 'event_id' => 10, 'season' => 2026, 'status' => 'submitted', 'photo_status' => null, 'accepted_via' => null, 'driver_id' => 5];
        $r = buildReadiness($this->world(['sheets' => [$sheet], 'sheetDrivers' => [70 => [7]]]));
        $this->assertSame([5, 7], $this->gearIds($r, 0));
    }

    public function testEntryWithNoStoredDriversFallsBackToTheOwner(): void
    {
        $r = buildReadiness($this->world(['plans' => [['event_id' => 10, 'car_id' => 3]]]));
        $this->assertSame([5], $this->gearIds($r, 0));
        $this->assertSame(['driverIds' => [5], 'driversKnown' => false],
            array_intersect_key($r['events'][0]['entries'][3], ['driverIds' => 0, 'driversKnown' => 0]));
    }

    public function testIceEntriesFollowTheSameRule(): void
    {
        $r = buildReadiness($this->world([
            'events' => [['id' => 20, 'name' => 'NASCC Ice #1', 'event_date' => '2027-01-10', 'discipline' => 'ice', 'host_club' => 'NASCC']],
            'plans' => [['event_id' => 20, 'car_id' => 3, 'drivers' => [6]]],
            'iceGear' => [], 'iceGearFhr' => [],
        ]));
        $this->assertSame([6], $this->gearIds($r, 0));
    }

    public function testTaDriftEntriesUseTheTickedDrivers(): void
    {
        $r = buildReadiness($this->world([
            'events' => [['id' => 30, 'name' => 'WSCC Time Attack', 'event_date' => '2026-10-20', 'discipline' => 'summer', 'host_club' => 'WSCC']],
            'plans' => [['event_id' => 30, 'car_id' => 3, 'formats' => 'ta', 'supps_ack_at' => null, 'drivers' => [5, 6]]],
        ]));
        $this->assertSame([5, 6], $this->gearIds($r, 0));
    }

    public function testRemindersLeaveOutCoDriversWhoArentDriving(): void
    {
        $digests = reminderDigests(buildReadiness($this->world(['plans' => [['event_id' => 10, 'car_id' => 3, 'drivers' => [5]]]])), '2026-09-26');
        $this->assertStringNotContainsString('Sam Lee', json_encode($digests));
        $this->assertStringNotContainsString('Pat Driver', json_encode($digests));
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `cd /c/dev/wcmaclasscalc/wcma-calculator && php phpunit.phar --filter ReadinessCodriversTest`
Expected: FAIL. Sam and Pat get gear to-dos, because of the profile-wide merge.

- [ ] **Step 3: Implement**

In `readiness-lib.php`, replace `readinessEntry()` and add the helper below it:

```php
/**
 * One car's entry at one event: its formats, the tech tier they need there (entryTierAtEvent()),
 * when the supplementary-regulations box was ticked, and who's driving (co-drivers spec §5):
 * the stored drivers, else the account's own driver. $plan is its event_plans row, or null.
 * @return array{formats: string[], tier: string, supps_ack_at: ?string, driverIds: int[], driversKnown: bool}
 */
function readinessEntry(array $event, ?array $plan, int $selfDriverId = 0): array {
    $stored = isset($plan['formats']) ? (string)$plan['formats'] : null;
    $known = isset($plan['drivers']) && is_array($plan['drivers']) && $plan['drivers'] !== [];
    return ['formats' => entryFormatsParse($stored), 'tier' => entryTierAtEvent($event, $stored),
            'supps_ack_at' => $plan['supps_ack_at'] ?? null,
            'driverIds' => $known ? array_values(array_map('intval', $plan['drivers'])) : ($selfDriverId > 0 ? [$selfDriverId] : []),
            'driversKnown' => $known];
}

/** Who needs gear for one entry: who's driving it, plus anyone its event sheet names. @return int[] */
function readinessEntryDriverIds(array $entry, ?array $eventSheet, array $sheetDrivers): array {
    $sheetIds = $eventSheet !== null
        ? array_merge([(int)($eventSheet['driver_id'] ?? 0)], array_map('intval', $sheetDrivers[(int)$eventSheet['id']] ?? []))
        : [];
    return array_values(array_unique(array_filter(array_merge(array_map('intval', $entry['driverIds'] ?? []), $sheetIds))));
}
```

In `buildReadiness()`:
- change `$entries[$carId] = readinessEntry($event, $plansByEvent[$eid][$carId] ?? null);` to pass `(int)$in['selfDriverId']` as the third argument;
- change the ice call to pass the entry as a new last argument: `readinessIceCarItems($in, $event, $key, $carId, $sheetsByCar, $atTrack, $once, $liveEventIds, $entries[$carId])`;
- in the race path, replace the comment and `$sheetDriverIds` / `$driverIds` lines (the "Every driver on the profile…" block) with:

```php
            // Gear for who's driving this entry, plus anyone its sheet names (co-drivers spec §5).
            $driverIds = readinessEntryDriverIds($entries[$carId], $eventSheet, $in['sheetDrivers']);
```

In `readinessIceCarItems()`:
- add the parameter `?array $entry = null` at the end of the signature;
- replace the `$driverIds = array_values(array_unique(array_filter(array_merge(...))));` statement with:

```php
    $driverIds = readinessEntryDriverIds($entry ?? ['driverIds' => [(int)$in['selfDriverId']]], $eventSheet, $in['sheetDrivers']);
```

In `readinessTaDriftCarItems()`, replace the `$driverIds = $source !== null ? … : [(int)$in['selfDriverId']];` statement with the code below. Keep the `$source` computation, because the gear photos link still uses it.

```php
    // Who's driving (co-drivers spec §5); entries from before that had no drivers keep the old source.
    $driverIds = !empty($entry['driversKnown'])
        ? readinessEntryDriverIds($entry, $eventSheet !== null && techSheetIsTaDrift($eventSheet) ? $eventSheet : null, $in['sheetDrivers'])
        : ($source !== null
            ? array_merge([(int)($source['driver_id'] ?? 0)], array_map('intval', $in['sheetDrivers'][(int)$source['id']] ?? []))
            : [(int)$in['selfDriverId']]);
```

In `loadReadinessInputs()`:
- add `$entryDrivers = db_get_entry_drivers_for_user($pdo, $userId);` before the `return`;
- change the `'plans'` mapping to carry the drivers:

```php
        'plans' => array_map(fn(array $p): array => ['event_id' => (int)$p['event_id'], 'car_id' => (int)$p['car_id'],
            'formats' => (string)($p['formats'] ?? 'race'), 'supps_ack_at' => $p['supps_ack_at'] ?? null,
            'drivers' => $entryDrivers[(int)$p['id']] ?? []], db_get_user_event_plans($pdo, $userId)),
```

Add the `db_get_self_driver` import only if needed: it is already used in this function.

- [ ] **Step 4: Update the listed existing tests (deliberately)**

- `ReadinessTest::testSeasonalItemsOnlyUnderTheNearestEventAndSheetsUnderEach`: expect `['declaration:3', 'tech_sheet:3', 'car_tech:3', 'gear:5']`, because Sam isn't driving.
- `ReadinessTest::testTwoCarsTaggedToTheSameEventEachGetTheirOwnItemsAndShareTheSelfDriverGearItem`: expect `['declaration:3', 'tech_sheet:3', 'car_tech:3', 'gear:5', 'declaration:4', 'tech_sheet:4', 'car_tech:4']`.
- `ReadinessTest::testGearCoversTheSheetsDriversAndEveryDriverOnTheProfile`: rename it to `testGearCoversTheSheetsDriversAndWhoIsDriving` and replace its body with:

```php
        $sheet = ['id' => 70, 'car_id' => 3, 'event_id' => 10, 'season' => 2026, 'status' => 'submitted', 'photo_status' => null, 'accepted_via' => null, 'driver_id' => 5];
        $r = buildReadiness($this->world(['sheets' => [$sheet], 'sheetDrivers' => [70 => [6]],
            'gear' => ['5:2026' => ['id' => 90, 'status' => 'accepted', 'accepted_via' => 'photos', 'photo_status' => 'accepted', 'season' => 2026]]]));
        $items = $this->items($r);
        $this->assertSame('done', $items['gear:5']['state']);
        $this->assertSame(['todo', 'gear.php?action=start&driver_id=6', 'Gear for Sam Patel'], [$items['gear:6']['state'], $items['gear:6']['action']['url'], $items['gear:6']['label']]);

        // No sheet and nobody else ticked: only the owner's gear (co-drivers spec §5)
        $noSheet = $this->items(buildReadiness($this->world()));
        $this->assertSame('todo', $noSheet['gear:5']['state']);
        $this->assertArrayNotHasKey('gear:6', $noSheet);

        // Ticked on the entry: listed without a sheet
        $ticked = $this->items(buildReadiness($this->world(['plans' => [['event_id' => 10, 'car_id' => 3, 'drivers' => [5, 6]], ['event_id' => 11, 'car_id' => 3]]])));
        $this->assertArrayHasKey('gear:6', $ticked);

        // Drivers who are not on this user's profile are never listed
        $foreign = $this->items(buildReadiness($this->world(['sheets' => [$sheet], 'sheetDrivers' => [70 => [99]]])));
        $this->assertArrayNotHasKey('gear:99', $foreign);
```

- `ReadinessTaDriftTest`:
  - Near line 47, add `'driverIds' => [5], 'driversKnown' => false` to the expected entry array.
  - In `testRaceEventAfterATaEventStillGetsRaceItems`, give event 21's plan `'drivers' => [5, 6]` so the `gear:6` assertion still holds.
- `ReadinessLoaderTest`: the `$in['plans']` assertion becomes `[['event_id' => $event, 'car_id' => $car, 'formats' => 'race', 'supps_ack_at' => null, 'drivers' => [$self]]]`. `db_tag_event` now puts the owner on the entry. Sam is only on the sheet, and readiness counts him through the sheet.

- [ ] **Step 5: Run the tests**

Run: `cd /c/dev/wcmaclasscalc/wcma-calculator && php phpunit.phar --filter "Readiness|Reminders|HomeTaDrift"`
Expected: PASS. Then run the full suite.

- [ ] **Step 6: Commit**

```bash
cd /c/dev/wcmaclasscalc && git add wcma-calculator/readiness-lib.php wcma-calculator/tests/ReadinessCodriversTest.php wcma-calculator/tests/ReadinessTest.php wcma-calculator/tests/ReadinessTaDriftTest.php wcma-calculator/tests/ReadinessLoaderTest.php
git commit -m "feat(codrivers): gear to-dos and reminders only for who's driving each entry

Replaces the every-profile-driver merge (0cce43d) for race and ice; TA/Drift uses the entry's
drivers once stored.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 4: The car page's Co-drivers section

**Files:**
- Modify: `garage.php` (`garageShowCar` view model; `handleGaragePost` gets the `add-co-driver` and `remove-co-driver` cases)
- Modify: `garage-page.php` (`renderGarageCarHtml`, a new section after Details)
- Test: `tests/GarageCodriversTest.php` (new)

**Interfaces:**
- Consumes: `eventsAddCoDriver()`, `eventsRemoveCoDriver()`, `db_get_car_drivers()`.
- Produces:
  - `renderGarageCarHtml` reads `$vm['coDrivers']` (drivers rows) and `$vm['coDriverOptions']` (the owner's other drivers not on this car, never the owner).
  - A `garageCoDriversHtml(array $vm): string` helper.

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/GarageCodriversTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../garage-page.php';

use PHPUnit\Framework\TestCase;

final class GarageCodriversTest extends TestCase
{
    public function testSectionListsCoDriversWithRemoveAndAnAddForm(): void
    {
        $html = garageCoDriversHtml(['car' => ['id' => 3, 'archived_at' => null], 'csrf' => 't',
            'coDrivers' => [['id' => 6, 'name' => 'Sam <Lee>']], 'coDriverOptions' => [['id' => 7, 'name' => 'Pat Driver']]]);
        $this->assertStringContainsString('<h2>Co-drivers</h2>', $html);
        $this->assertStringContainsString("People who share this car. Tick who's driving at each event.", $html);
        $this->assertStringContainsString('Sam &lt;Lee&gt;', $html);
        $this->assertStringContainsString('name="action" value="remove-co-driver"', $html);
        $this->assertStringContainsString('name="driver_id" value="6"', $html);
        $this->assertStringContainsString('<option value="7">Pat Driver</option>', $html);
        $this->assertStringContainsString('<option value="new">', $html);
        $this->assertStringContainsString('name="new_name"', $html);
    }

    public function testEmptyStateAndArchivedCarHasNoForms(): void
    {
        $html = garageCoDriversHtml(['car' => ['id' => 3, 'archived_at' => '2026-01-01'], 'csrf' => 't', 'coDrivers' => [], 'coDriverOptions' => []]);
        $this->assertStringContainsString('No co-drivers yet.', $html);
        $this->assertStringNotContainsString('<form', $html);
    }

    public function testGaragePostHandlesTheTwoActions(): void
    {
        $src = file_get_contents(__DIR__ . '/../garage.php');
        $this->assertStringContainsString("case 'add-co-driver':", $src);
        $this->assertStringContainsString('eventsAddCoDriver($pdo, $uid, $carId, $_POST)', $src);
        $this->assertStringContainsString("case 'remove-co-driver':", $src);
        $this->assertStringContainsString('eventsRemoveCoDriver($pdo, $uid, $carId, (int)($_POST[\'driver_id\'] ?? 0))', $src);
        $this->assertStringContainsString("'coDrivers' =>", $src);
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `cd /c/dev/wcmaclasscalc/wcma-calculator && php phpunit.phar --filter GarageCodriversTest`
Expected: FAIL. `garageCoDriversHtml` is undefined.

- [ ] **Step 3: Implement**

In `garage-page.php`, add this before `renderGarageCarHtml`:

```php
/** The car page's Co-drivers section (co-drivers spec §2). Archived cars show the list only. */
function garageCoDriversHtml(array $vm): string {
    $id = (int)$vm['car']['id'];
    $csrf = (string)$vm['csrf'];
    $archived = $vm['car']['archived_at'] !== null;
    $out = '<section class="hub-card" id="co-drivers"><h2>Co-drivers</h2>'
        . '<p class="form-hint">People who share this car. Tick who\'s driving at each event.</p>';
    if (!$vm['coDrivers']) $out .= '<p>No co-drivers yet.</p>';
    foreach ($vm['coDrivers'] as $d) {
        $out .= '<div class="hub-line"><span>' . h((string)$d['name']) . '</span>'
            . ($archived ? '' : garagePostForm($csrf, 'remove-co-driver', $id, 'Remove', 'hub-btn hub-btn--link', '', ['driver_id' => (int)$d['id']]))
            . '</div>';
    }
    if (!$archived) {
        $out .= '<form method="post" action="garage.php" class="hub-line hub-tag-form">' . garageCsrfField($csrf)
            . '<input type="hidden" name="action" value="add-co-driver"><input type="hidden" name="car_id" value="' . $id . '">'
            . '<label for="co-driver-choice">Add a co-driver</label><select id="co-driver-choice" name="driver_id">';
        foreach ($vm['coDriverOptions'] as $d) {
            $out .= '<option value="' . (int)$d['id'] . '">' . h((string)$d['name']) . '</option>';
        }
        $out .= '<option value="new">New name…</option></select>'
            . '<label for="co-driver-new" class="visually-hidden">New co-driver\'s name</label>'
            . '<input type="text" id="co-driver-new" name="new_name" maxlength="100" placeholder="New co-driver\'s name">'
            . '<button type="submit" class="hub-btn hub-btn--secondary">Add</button></form>';
    }
    return $out . '</section>';
}
```

In `renderGarageCarHtml`, after the Details section's closing `</section>` line (the one ending `…Save details</button></form></details></section>';`), add:

```php
    $out .= garageCoDriversHtml($vm);
```

In `garage.php` `garageShowCar()`, add these to the `renderGarageCarHtml([...])` array:

```php
        'coDrivers' => db_get_car_drivers($pdo, $carId),
        'coDriverOptions' => array_values(array_filter(db_get_user_drivers($pdo, $uid), fn(array $d): bool =>
            (int)($d['user_id'] ?? 0) !== $uid && !in_array((int)$d['id'], array_map(fn(array $c): int => (int)$c['id'], db_get_car_drivers($pdo, $carId)), true))),
```

In `handleGaragePost()`, add these cases next to `untag`:

```php
        case 'add-co-driver':
            $r = eventsAddCoDriver($pdo, $uid, $carId, $_POST);
            setFlash($r['ok'] ? 'Co-driver added.' : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            header('Location: garage.php?car=' . $carId . '#co-drivers');
            return;
        case 'remove-co-driver':
            $r = eventsRemoveCoDriver($pdo, $uid, $carId, (int)($_POST['driver_id'] ?? 0));
            setFlash($r['ok'] ? 'Removed from this car.' : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            header('Location: garage.php?car=' . $carId . '#co-drivers');
            return;
```

`handleGaragePost` already checks the CSRF token before the `switch` (garage.php top). Confirm that, and don't add a second check. Existing tests that call `renderGarageCarHtml` without `coDrivers` must keep passing, so read `$vm['coDrivers'] ?? []` and `$vm['coDriverOptions'] ?? []` inside `garageCoDriversHtml`: change the two reads to use `?? []`.

- [ ] **Step 4: Run the tests**

Run: `cd /c/dev/wcmaclasscalc/wcma-calculator && php phpunit.phar --filter "GarageCodriversTest|Garage"`
Expected: PASS. Then run the full suite.

- [ ] **Step 5: Commit**

```bash
cd /c/dev/wcmaclasscalc && git add wcma-calculator/garage.php wcma-calculator/garage-page.php wcma-calculator/tests/GarageCodriversTest.php
git commit -m "feat(codrivers): Co-drivers section on the car page

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 5: "Who's driving?" on Home and the car page (ice entries included)

**Files:**
- Modify: `home-page.php`:
  - new `homeDriversFieldsHtml` and `homeDrivingLabel`
  - `homeRenderEntryFormatsHtml`
  - `homeEventCardHtml`
  - `renderHomeHtml`
- Modify: `index.php` (the `formats` case passes drivers; builds `carDrivers`)
- Modify: `garage-page.php` (`garageRenderEntryFormatsHtml` and its call site)
- Modify: `garage.php` (the `formats` case; view model `carDriverChoices` and each tagged row's `driverIds`)
- Modify: `css/hub.css`
- Test: `tests/HomeCodriversTest.php` (new)

**Interfaces:**
- Consumes: `eventsCarDriverChoices()`, `entryDriversFromPost()`, `eventsSetFormats(..., $driverIds)` (Task 2); readiness entries carry `driverIds` (Task 3).
- Produces:
  - `homeDriversFieldsHtml(array $drivers, array $tickedIds): string`
  - `homeDrivingLabel(array $drivers, array $tickedIds): string`, for example `Driving: You · Sam Lee`
  - `homeRenderEntryFormatsHtml(array $event, array $car, array $entry, string $csrf, array $drivers = [])`
  - `homeEventCardHtml(..., array $tagDefaults = [], array $carDrivers = [])`
  - `garageRenderEntryFormatsHtml(array $event, int $carId, array $formats, string $csrf, ?string $suppsAckAt = null, array $drivers = [], array $tickedIds = [])`

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/HomeCodriversTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../home-page.php';
require_once __DIR__ . '/../garage-page.php';

use PHPUnit\Framework\TestCase;

final class HomeCodriversTest extends TestCase
{
    private array $drivers = [['id' => 5, 'name' => 'Jordan Lee', 'isSelf' => true], ['id' => 6, 'name' => 'Sam <Lee>', 'isSelf' => false]];

    public function testFieldsetTicksWhoIsDriving(): void
    {
        $html = homeDriversFieldsHtml($this->drivers, [5]);
        $this->assertStringContainsString("<legend>Who's driving?</legend>", $html);
        $this->assertStringContainsString('name="drivers_shown" value="1"', $html);
        $this->assertMatchesRegularExpression('/value="5" checked> You</', $html);
        $this->assertMatchesRegularExpression('/value="6"> Sam &lt;Lee&gt;</', $html);
        $this->assertSame('Driving: You · Sam <Lee>', homeDrivingLabel($this->drivers, [5, 6]));
    }

    public function testSummerChangeFormHasFormatsAndDrivers(): void
    {
        $event = ['id' => 30, 'name' => 'WSCC TA', 'discipline' => 'summer', 'host_club' => 'WSCC'];
        $html = homeRenderEntryFormatsHtml($event, ['id' => 3], ['formats' => ['ta'], 'supps_ack_at' => null, 'driverIds' => [5]], 't', $this->drivers);
        $this->assertStringContainsString('Time Attack · Driving: You · Change', html_entity_decode($html));
        $this->assertStringContainsString('name="formats[]"', $html);
        $this->assertStringContainsString("Who's driving?", $html);
    }

    public function testIceEntryChangeFormHasDriversOnly(): void
    {
        $event = ['id' => 20, 'name' => 'NASCC Ice #1', 'discipline' => 'ice', 'host_club' => 'NASCC'];
        $html = homeRenderEntryFormatsHtml($event, ['id' => 3], ['formats' => ['race'], 'supps_ack_at' => null, 'driverIds' => [6]], 't', $this->drivers);
        $this->assertStringContainsString('Driving: Sam', html_entity_decode($html));
        $this->assertStringNotContainsString('name="formats[]"', $html);
        $this->assertStringNotContainsString('formats_shown', $html);
        $this->assertStringContainsString('name="action" value="formats"', $html);
        // Without drivers (older callers) an ice entry still has no Change form.
        $this->assertSame('', homeRenderEntryFormatsHtml($event, ['id' => 3], ['formats' => ['race'], 'supps_ack_at' => null], 't'));
    }

    public function testCarPageChangeFormHasDrivers(): void
    {
        $event = ['id' => 20, 'name' => 'NASCC Ice #1', 'discipline' => 'ice', 'host_club' => 'NASCC'];
        $html = garageRenderEntryFormatsHtml($event, 3, ['race'], 't', null, $this->drivers, [5]);
        $this->assertStringContainsString("Who's driving?", $html);
        $this->assertStringContainsString('action="garage.php"', $html);
    }

    public function testHandlersPassTheDrivers(): void
    {
        foreach (['index.php', 'garage.php'] as $file) {
            $src = file_get_contents(__DIR__ . '/../' . $file);
            $this->assertStringContainsString('entryDriversFromPost($_POST)', $src, $file);
        }
        $this->assertStringContainsString("'carDrivers' =>", file_get_contents(__DIR__ . '/../index.php'));
        $this->assertStringContainsString("'carDriverChoices' =>", file_get_contents(__DIR__ . '/../garage.php'));
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `cd /c/dev/wcmaclasscalc/wcma-calculator && php phpunit.phar --filter HomeCodriversTest`
Expected: FAIL. `homeDriversFieldsHtml` is undefined.

- [ ] **Step 3: Implement in `home-page.php`**

Add these after `homeFormatsFieldsHtml()`:

```php
/** "Who's driving?" for one entry (co-drivers spec §3): the owner ("You"), then the car's co-drivers. */
function homeDriversFieldsHtml(array $drivers, array $tickedIds): string {
    $ticked = array_map('intval', $tickedIds);
    $out = '<fieldset class="hub-formats hub-drivers"><legend>Who\'s driving?</legend><input type="hidden" name="drivers_shown" value="1"><div class="hub-formats-options">';
    foreach ($drivers as $d) {
        $out .= '<label><input type="checkbox" name="drivers[]" value="' . (int)$d['id'] . '"' . (in_array((int)$d['id'], $ticked, true) ? ' checked' : '') . '> '
            . h($d['isSelf'] ? 'You' : (string)$d['name']) . '</label>';
    }
    return $out . '</div></fieldset>';
}

/** "Driving: You · Sam Lee" (plain text; escape when printing). */
function homeDrivingLabel(array $drivers, array $tickedIds): string {
    $ticked = array_map('intval', $tickedIds);
    $names = [];
    foreach ($drivers as $d) {
        if (in_array((int)$d['id'], $ticked, true)) $names[] = $d['isSelf'] ? 'You' : (string)$d['name'];
    }
    return 'Driving: ' . implode(' · ', $names);
}
```

Replace `homeRenderEntryFormatsHtml()` with:

```php
/**
 * A going car's entry on its event card, with a "Change" form: what it runs (summer) and, when
 * $drivers is given, who's driving (every discipline).
 */
function homeRenderEntryFormatsHtml(array $event, array $car, array $entry, string $csrf, array $drivers = []): string {
    $isIce = ($event['discipline'] ?? 'summer') === 'ice';
    if ($isIce && $drivers === []) return '';
    $ticked = $entry['driverIds'] ?? [];
    $summary = [];
    if (!$isIce) $summary[] = entryFormatsLabel($entry['formats']);
    if ($drivers !== []) $summary[] = homeDrivingLabel($drivers, $ticked);
    return '<details class="hub-entry-formats"><summary>' . h(implode(' · ', $summary)) . ' · Change</summary>'
        . '<form method="post" action="index.php" class="hub-line hub-tag-form">' . homeCsrfField($csrf)
        . '<input type="hidden" name="action" value="formats">'
        . '<input type="hidden" name="event_id" value="' . (int)$event['id'] . '">'
        . '<input type="hidden" name="car_id" value="' . (int)$car['id'] . '">'
        . ($isIce ? '' : homeFormatsFieldsHtml($event, $entry['formats'], ($entry['supps_ack_at'] ?? null) !== null))
        . ($drivers !== [] ? homeDriversFieldsHtml($drivers, $ticked) : '')
        . '<button type="submit" class="hub-btn hub-btn--secondary">Save</button></form></details>';
}
```

- In `homeEventCardHtml`, add the parameter `array $carDrivers = []` at the end. In the going-cars loop, pass `$carDrivers[$carId] ?? []` as the new last argument to `homeRenderEntryFormatsHtml`, and give the fallback entry `'driverIds' => []`.
- In `renderHomeHtml`, pass `$vm['carDrivers'] ?? []` as the new last argument of the `homeEventCardHtml(...)` call.

**Ice entries in the posted form:** the ice form has no `formats_shown`, so `entryFormatsFromPost()` returns null and the handler's `?? []` passes `[]`. `entryFormatsValidate()` returns `['race']` for ice events, so formats are untouched. Keep that path: don't add a formats fieldset for ice.

- [ ] **Step 4: Handlers and view models**

- `index.php`:
  - In the `formats` case, add `entryDriversFromPost($_POST)` as the last argument of `eventsSetFormats(...)`.
  - Before `renderHomeHtml`, build:

```php
// Who can drive each car: you, then its co-drivers (co-drivers spec §3).
$carDrivers = [];
foreach (array_keys($in['cars']) as $cid) $carDrivers[(int)$cid] = eventsCarDriverChoices($pdo, $uid, (int)$cid);
```

  - Add `'carDrivers' => $carDrivers,` to the `renderHomeHtml([...])` array.

- `garage.php`:
  - In the `formats` case, add `entryDriversFromPost($_POST)` as the last argument of `eventsSetFormats(...)`.
  - In `garageShowCar()`, inside the loop that sets `suppsAckAt`, also set:

```php
        $entryRow = db_get_entry($pdo, $uid, (int)$row['event']['id'], $carId);
        $events['tagged'][$i]['driverIds'] = $entryRow !== null ? db_get_entry_driver_ids($pdo, (int)$entryRow['id']) : [];
```

    (reuse the one `db_get_entry` call for both `suppsAckAt` and `driverIds`)
  - Add `'carDriverChoices' => eventsCarDriverChoices($pdo, $uid, $carId),` to the view model.

- `garage-page.php`: replace `garageRenderEntryFormatsHtml` with:

```php
function garageRenderEntryFormatsHtml(array $event, int $carId, array $formats, string $csrf, ?string $suppsAckAt = null, array $drivers = [], array $tickedIds = []): string {
    $isIce = ($event['discipline'] ?? 'summer') === 'ice';
    if ($isIce && $drivers === []) return '';
    $summary = [];
    if (!$isIce) $summary[] = entryFormatsLabel($formats);
    if ($drivers !== []) $summary[] = homeDrivingLabel($drivers, $tickedIds);
    return '<details class="hub-entry-formats"><summary>' . h(implode(' · ', $summary)) . ' · Change</summary>'
        . '<form method="post" action="garage.php" class="hub-line hub-tag-form">' . garageCsrfField($csrf)
        . '<input type="hidden" name="action" value="formats"><input type="hidden" name="car_id" value="' . $carId . '">'
        . '<input type="hidden" name="event_id" value="' . (int)$event['id'] . '">'
        . ($isIce ? '' : homeFormatsFieldsHtml($event, $formats, $suppsAckAt !== null))
        . ($drivers !== [] ? homeDriversFieldsHtml($drivers, $tickedIds) : '')
        . '<button type="submit" class="hub-btn hub-btn--secondary">Save</button></form></details>';
}
```

  and change its call site in `renderGarageCarHtml` to pass `$vm['carDriverChoices'] ?? [], $row['driverIds'] ?? []` as the two new last arguments.

- `css/hub.css`: `.hub-drivers` reuses `.hub-formats`, because the fieldset carries both classes. Add only this, below `.hub-formats-supps`:

```css
.hub-drivers { margin-top: 8px; }
```

- [ ] **Step 5: Run the tests**

Run: `cd /c/dev/wcmaclasscalc/wcma-calculator && php phpunit.phar --filter "HomeCodriversTest|Home|Garage"`
Expected: PASS. Then run the full suite and `node --test tests/js/*.test.js`.

- [ ] **Step 6: Commit**

```bash
cd /c/dev/wcmaclasscalc && git add wcma-calculator/home-page.php wcma-calculator/index.php wcma-calculator/garage.php wcma-calculator/garage-page.php wcma-calculator/css/hub.css wcma-calculator/tests/HomeCodriversTest.php
git commit -m "feat(codrivers): tick who's driving on Home and the car page, ice entries too

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 6: Tech sheets: car-scoped pickers, pre-fill, sync and the endurance notice

**Files:**
- Modify: `tech-sheet-data.php` (`techSheetDriver1FormState` gains `?int $preferId`; new `techSheetPrefillRows`)
- Modify: `ice-sheet-page.php` (`iceSheetFormVm` gains `?int $preferDriver1 = null`)
- Modify: `ta-drift-sheet-page.php` (`taDriftSheetFormVm` gains `?int $preferDriver1 = null`)
- Modify: `tech-sheets.php`:
  - `handleNew`, `renderTechSheetForm`, `handleEdit`, `handleNewIce`, `handleNewTaDrift`
  - the six submit/update handlers
- Test: `tests/TechSheetCodriversTest.php` (new)

**Interfaces:**
- Consumes: `eventsSheetDriverRows()`, `eventsSheetPrefill()`, `eventsSyncSheetDrivers()` (Task 2).
- Produces:
  - `techSheetDriver1FormState(array $ownerDrivers, ?array $sheet, ?int $preferId = null)`: a new sheet starts with `$preferId` when it's in the list, else the owner.
  - `techSheetPrefillRows(array $ownedById, array $otherIds): array`, with rows `['driver_number' => 2.., 'driver_name' => name, 'equipment_json' => '{}']`
  - `renderTechSheetForm(..., int $preselectEventId = 0, array $prefill = [])`, where `$prefill` holds `driver1` (?int), `rows`, `sheetType` (`standard` or `endurance`) and `notice` (?string URL)
  - `handleNew` reads `?type=endurance`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/TechSheetCodriversTest.php
require_once __DIR__ . '/../tech-sheet-data.php';

use PHPUnit\Framework\TestCase;

final class TechSheetCodriversTest extends TestCase
{
    private function drivers(): array {
        return [
            ['id' => 5, 'user_id' => 1, 'owner_user_id' => 1, 'name' => 'Jordan Lee', 'name_norm' => 'jordan lee'],
            ['id' => 6, 'user_id' => null, 'owner_user_id' => 1, 'name' => 'Sam Lee', 'name_norm' => 'sam lee'],
        ];
    }

    public function testNewSheetStartsWithThePreferredDriver(): void
    {
        $this->assertSame('5', techSheetDriver1FormState($this->drivers(), null)['choice']);
        $this->assertSame('6', techSheetDriver1FormState($this->drivers(), null, 6)['choice']);
        $this->assertSame('5', techSheetDriver1FormState($this->drivers(), null, 99)['choice']);   // not in the list
        $sheet = ['driver_name' => 'Sam Lee'];
        $this->assertSame('6', techSheetDriver1FormState($this->drivers(), $sheet, 5)['choice']);   // an edit keeps its driver
    }

    public function testPrefillRowsNumberFromTwo(): void
    {
        $byId = [];
        foreach ($this->drivers() as $d) $byId[(int)$d['id']] = $d;
        $this->assertSame([['driver_number' => 2, 'driver_name' => 'Sam Lee', 'equipment_json' => '{}']], techSheetPrefillRows($byId, [6, 99]));
    }

    public function testFormsUseTheCarsDriversAndEverySaveSyncs(): void
    {
        $src = file_get_contents(__DIR__ . '/../tech-sheets.php');
        $this->assertSame(6, substr_count($src, 'eventsSyncSheetDrivers($pdo, (int)$user[\'id\'], '));   // 3 submits + 3 updates
        $this->assertGreaterThanOrEqual(6, substr_count($src, 'eventsSheetDriverRows($pdo, (int)$user[\'id\'], '));   // 3 new + 3 edit forms
        $this->assertStringContainsString("More than one driver is ticked for this event. Use the endurance sheet so everyone is on it.", $src);
        $this->assertStringContainsString("(\$_GET['type'] ?? '') === 'endurance'", $src);
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `cd /c/dev/wcmaclasscalc/wcma-calculator && php phpunit.phar --filter TechSheetCodriversTest`
Expected: FAIL.

- [ ] **Step 3: `tech-sheet-data.php`**

In `techSheetDriver1FormState()`:
- change the signature to `function techSheetDriver1FormState(array $ownerDrivers, ?array $sheet, ?int $preferId = null): array`;
- change the `$choice` expression to:

```php
    $choice = $sheet !== null
        ? techSheetDriverChoiceFor($ownedById, (string)$sheet['driver_name'])
        : ($preferId !== null && isset($ownedById[$preferId]) ? (string)$preferId : ($selfId !== null ? (string)$selfId : 'new'));
```

Add below it:

```php
/** Added-driver rows for a new sheet from who's ticked on the entry (co-drivers spec §4). */
function techSheetPrefillRows(array $ownedById, array $otherIds): array {
    $rows = [];
    foreach ($otherIds as $id) {
        if (!isset($ownedById[(int)$id])) continue;
        $rows[] = ['driver_number' => count($rows) + 2, 'driver_name' => (string)$ownedById[(int)$id]['name'], 'equipment_json' => '{}'];
    }
    return $rows;
}
```

- [ ] **Step 4: Form view models**

- `ice-sheet-page.php`: in `iceSheetFormVm`, add `?int $preferDriver1 = null` as the last parameter, and use `techSheetDriver1FormState($ownerDrivers, $sheet, $preferDriver1)`.
- `ta-drift-sheet-page.php`: in `taDriftSheetFormVm`, add `?int $preferDriver1 = null` as the last parameter, and use `techSheetDriver1FormState($ownerDrivers, $sheet, $preferDriver1)`.

- [ ] **Step 5: `tech-sheets.php`**

- **Pickers:** replace `db_get_user_drivers($pdo, (int)$user['id'])` with `eventsSheetDriverRows($pdo, (int)$user['id'], <carId>)` in the **form** calls only:
  - `handleNew` (line ~204): `$carId`
  - `handleEdit` TA/Drift, ice and summer (lines ~331, ~341, ~349): `(int)$car['id']`
  - `handleNewIce` (~864): `$carId`
  - `handleNewTaDrift` (~1009): `$carId`

  Keep `db_get_user_drivers` in the submit/update handlers' `$owned` lists (lines ~670, ~779, ~874, ~1019). Those still accept any of the owner's drivers or a new name, and the sync below adds them to the car.

- **Pre-fill for new sheets:**
  - `handleNew`: replace the final `renderTechSheetForm(...)` call with:

```php
    $pickers = eventsSheetDriverRows($pdo, (int)$user['id'], $carId);
    $byId = [];
    foreach ($pickers as $d) $byId[(int)$d['id']] = $d;
    $prefill = eventsSheetPrefill($pdo, (int)$user['id'], $carId, $eventId);
    $type = ($_GET['type'] ?? '') === 'endurance' ? 'endurance' : 'standard';
    renderTechSheetForm($declaration, $events, generateCsrfToken(), null, [], $pickers, $car, $eventId, [
        'driver1' => $prefill['driver1'],
        'rows' => techSheetPrefillRows($byId, $prefill['others']),
        'sheetType' => $type,
        'notice' => $type === 'standard' && $prefill['count'] > 1
            ? 'tech-sheets.php?action=new&car_id=' . $carId . '&event_id=' . $eventId . '&type=endurance' : null,
    ]);
```

  - `handleNewIce`: `$prefill = eventsSheetPrefill($pdo, (int)$user['id'], $carId, (int)$event['id']);` then pass `$prefill['driver1']` as `iceSheetFormVm`'s new last argument.
  - `handleNewTaDrift`: build `$pickers` and `$byId` as above, and `$prefill` for `(int)$event['id']`. Pass `techSheetPrefillRows($byId, $prefill['others'])` as the `$sheetDrivers` argument (it's `[]` today) and `$prefill['driver1']` as the new last argument.

- **`renderTechSheetForm`:**
  - add the `array $prefill = []` parameter at the end;
  - at the top, after `$isEdit`, add `if (!$isEdit && isset($prefill['rows'])) $existingDrivers = $prefill['rows'];`;
  - change `$selectedSheetType = $isEdit ? $existingSheet['sheet_type'] : 'standard';` to `… : ($prefill['sheetType'] ?? 'standard');`;
  - change `$d1 = techSheetDriver1FormState($ownerDrivers, $isEdit ? $existingSheet : null);` to pass `$prefill['driver1'] ?? null` as the third argument;
  - print the notice just before the `<form` tag:

```php
<?php if (!empty($prefill['notice'])): ?>
  <div class="form-messages show info">More than one driver is ticked for this event. Use the endurance sheet so everyone is on it.
    <a href="<?= h($prefill['notice']) ?>">Use the endurance sheet</a></div>
<?php endif; ?>
```

- **Sync after every save.** Add `eventsSyncSheetDrivers($pdo, (int)$user['id'], <sheet id>);` in each handler, after that handler has written the sheet's drivers and tagged the event:
  - `handleSubmit`: after the `db_replace_tech_sheet_drivers` block (~725), with `$id`
  - `handleUpdate`: after the drivers `if/else` (~827), with `$id`
  - `handleSubmitIce`: after `eventsTagForSheet(...)` (~934), with `$id`
  - `handleUpdateIce`: after the `db_update_tech_sheet(...)` try block, with `$id`
  - `handleSubmitTaDrift`: after `eventsTagForSheet(...)` (~1071), with `$id`
  - `handleUpdateTaDrift`: after `db_replace_tech_sheet_drivers(...)` (~1113), with `$id`

  Use each handler's real sheet-id variable name. The test counts the literal prefix `eventsSyncSheetDrivers($pdo, (int)$user['id'], `.

- [ ] **Step 6: Run the tests**

Run: `cd /c/dev/wcmaclasscalc/wcma-calculator && php phpunit.phar --filter "TechSheetCodriversTest|TechSheet|GearLinksSource|TaDriftSheet"`
Expected: PASS. `GearLinksSourceTest::testSheetFormPicksDriversFromTheUsersProfiles` may pin `db_get_user_drivers` in the form. If it does, check what it asserts:
- if it asserts the form calls `db_get_user_drivers`, update it to `eventsSheetDriverRows` and add it to this commit (a deliberate change for spec §4);
- if it only checks the submit handlers, leave it.

Then run the full suite and `node --test tests/js/*.test.js`.

- [ ] **Step 7: Commit**

```bash
cd /c/dev/wcmaclasscalc && git add wcma-calculator/tech-sheet-data.php wcma-calculator/ice-sheet-page.php wcma-calculator/ta-drift-sheet-page.php wcma-calculator/tech-sheets.php wcma-calculator/tests/TechSheetCodriversTest.php
git commit -m "feat(codrivers): sheet pickers offer the car's drivers, pre-fill from the entry, and saved drivers join it

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 7: Seed and phone audit

**Files:**
- Modify: `hub-db-tools.php` (Sam Patel becomes a co-driver on Jordan's S2000)
- Modify: `tests/ux/audit.mjs`
- Test: `tests/HubDbToolsTest.php` (add one assertion)

- [ ] **Step 1: Write the failing assertion**

In `tests/HubDbToolsTest.php`, in the test that seeds the demo database, add:

```php
        $jordan = (int)db_find_user_by_email($pdo, 'jordan@example.com')['id'];
        $s2000 = (int)array_values(array_filter(db_get_user_cars($pdo, $jordan), fn(array $c): bool => $c['model'] === 'S2000'))[0]['id'];
        $this->assertSame(['Sam Patel'], array_map(fn(array $d): string => (string)$d['name'], db_get_car_drivers($pdo, $s2000)));
```

Put it in the same test method that already looks up `jordan@example.com` (around line 40), after the seed call.

- [ ] **Step 2: Seed**

In `hub-db-tools.php`, after the `$samDriver = …` line, add:

```php
    db_add_car_driver($pdo, $jordan, $s2000, $samDriver);   // shares the S2000 (co-drivers spec §2)
```

- [ ] **Step 3: Audit**

In `tests/ux/audit.mjs`, directly after `await audit(page, 'car page');`, add:

```js
  report('the car page has a Co-drivers section',
    await page.locator('h2:has-text("Co-drivers")').count() === 1 ? [] : ['no Co-drivers section on the car page']);
  await page.selectOption('#co-driver-choice', 'new');
  await page.fill('#co-driver-new', 'Alex Kim');
  await page.click('#co-drivers button:has-text("Add")');
  await audit(page, 'car page (co-driver added)');
  report('an added co-driver is listed', (await page.locator('#co-drivers').innerText()).includes('Alex Kim') ? [] : ['Alex Kim is not listed']);
```

After `await audit(page, 'home (change what you are running)');`, add:

```js
  report("the Change form asks who's driving",
    await page.locator('section.hub-event:has-text("WSCC Time Attack") legend:has-text("Who\'s driving?")').count() === 1
      ? [] : ["no Who's driving? on the event's Change form"]);
```

If the `#co-driver-choice` select has only the "New name…" option (the audit's fresh account has no other drivers), `selectOption('new')` still works.

- [ ] **Step 4: Run everything**

```bash
cd /c/dev/wcmaclasscalc/wcma-calculator && php phpunit.phar && node --test tests/js/*.test.js
cd /c/dev/wcmaclasscalc && bash wcma-calculator/tests/ux/run-audit.sh
```

Expected: every suite passes, and the audit prints all pages passing, including the two new pages and the three new `report()` checks. If an audit rule fails on the new markup (tap size, text size), fix the markup or CSS. Don't use `no-audit`.

- [ ] **Step 5: Commit**

```bash
cd /c/dev/wcmaclasscalc && git add wcma-calculator/hub-db-tools.php wcma-calculator/tests/HubDbToolsTest.php wcma-calculator/tests/ux/audit.mjs
git commit -m "test(codrivers): seed a co-driver; phone audit covers the Co-drivers section and Who's driving

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

## Spec coverage

| Spec | Task |
|---|---|
| §1 tables, functions, backfill (once) | 1 |
| §2 car page Co-drivers, removal unticks upcoming entries | 1 (DB), 2 (validation), 4 (UI) |
| §3 Who's driving: default owner, at least one, only the owner or the car's list, ice included, "Driving:" line | 1, 2, 5 |
| §4 pickers, pre-fill, auto-add and tick on save, endurance notice | 2, 6 |
| §5 readiness for race, ice and TA/Drift, the no-rows fallback, reminders | 3 |
| §6 unchanged (gear records, the Drivers page) | untouched |
| §7 tests and phone audit | 1–7 |

## Notes on spec ambiguities resolved here

- **The "I'm going" form** keeps its car picker and ticks only the owner. Choosing co-drivers happens on the entry's **Change** form, on Home and on the car page. The multi-car "I'm going" form can't know which car's co-drivers to list, and the spec's default is owner-only anyway.
- **Ice entries** now get a "Change" form on Home and the car page with only "Who's driving?". It posts the existing `formats` action, whose validation always returns Race for ice.
- **"Only fills tables that are still empty"** is done as "only when the table is first created". A new database (with no data) and a later removal of a co-driver are then never undone by a re-run.
- **The TA/Drift gear photos link** still comes from the event sheet or the latest accepted club sheet (`$source`). Only the list of drivers changes.
