# WCMA Hub Phase 1: Core Model Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Put the app on the hub's core model. Cars and driver profiles become first-class records. Class declarations, tech sheets and gear hang off them. An `inspector` role is added. Admins get a season-links editor. The competitor emails get their new copy. Reset and seed tools replace any data migration.

**Architecture:** This is still a plain PHP + SQLite app with no framework. The schema in `db.php`'s `db_init()` is rewritten to the new model, and every legacy `ALTER TABLE` upgrade shim is removed, because the database is reset instead of migrated. New pure helpers get new focused files (`cars-lib.php`, `roles.php`, `season-links-lib.php`, `email-copy.php`, `hub-db-tools.php`), and DB access stays in `db.php`. Existing pages change as little as possible: the page redesign is Phase 2.

**Tech Stack:** PHP 8.3, SQLite (PDO), PHPUnit 10 (`php phpunit.phar`), vanilla JS, and `node --test` for JS unit tests.

**Spec:** `docs/superpowers/specs/2026-09-24-wcma-hub-design.md`. Read §2 (Core data model), §5 (Inspector and Admin, Competitor emails) and §8 (Phase 1 row).

## Global Constraints

- All paths below are relative to `wcma-calculator/` unless they start with `docs/`. Run PHPUnit from `wcma-calculator/`: `php phpunit.phar`. Run JS tests: `node --test tests/js/`.
- No new dependencies and no build step.
- **Terminology:** use *reviewed*, *accepted* and *pre-teched*. Never use *approved*, *passed* or *safe* in UI or email copy.
- **Exact copy (from the spec):**
  - `Class declaration received. An inspector will review and respond.`
  - `Tech sheet received. An inspector will review and respond.`
  - `The scrutineer has reviewed & accepted your tech sheet.`
  - `The scrutineer has reviewed & accepted your gear.`
  - Review emails carry `Reviewed by: {first} {last}`.
  - `TECH_ACCEPTANCE_DISCLAIMER` is removed everywhere.
- **Not blocking:** a tech sheet can always be submitted, whatever the car, gear or declaration status.
- **Calculator:** its real-time recalculation behaviour must not change. Do not edit the calculation wiring in `js/ui-controller.js` or `js/calculator.js`.
- **No data migration.** The database is reset (Task 12). Delete your local `data/submissions.db` (and `-wal`/`-shm`) before manual testing. Tests always use fresh temp databases.
- **Roles:** `user` < `inspector` < `admin`. Inspectors do classing, car tech and gear. Admin-only areas are Users, Events, Settings, Feedback, Season links, and deleting or editing declarations.
- Work on a branch (e.g. `hub-phase1`), not `main`. Commit at the end of every task. Every commit message ends with:
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`

---

## File map

| File | Status | Responsibility |
|---|---|---|
| `db.php` | modify | Schema; all SQL, including new car/driver/season-link functions |
| `roles.php` | create | Pure role helpers: role levels, admin action → minimum role, name check |
| `session_bootstrap.php` | modify | `is_inspector()`; loads `roles.php` |
| `cars-lib.php` | create | Pure-ish car and declaration helpers (resolve car for a declaration, labels, public shape, sheet write-back) |
| `cars.php` | create | JSON endpoint: the signed-in user's cars |
| `season-links-lib.php` | create | Season link validation |
| `admin-season-links.php` | create | Admin season-links pages |
| `email-copy.php` | create | Binding competitor copy constants and `reviewedByLine()` |
| `hub-db-tools.php` | create | `hubResetDatabase()` and `hubSeed()` |
| `reset-hub-db.php`, `seed-hub-db.php` | create | CLI wrappers |
| `js/car-picker.js` | create | Calculator car picker |
| `car-classing.php`, `car-classing.html` | modify | Declarations require sign-in and a car |
| `account.php`, `view_helpers.php` | modify | My Cars grouped by car; nav by role |
| `tech-sheets.php` | modify | Tech sheet from a car |
| `gear-lib.php` | modify | Gear records keyed by driver |
| `admin.php`, `admin-tech-sheets.php`, `admin-gear.php`, `admin-feedback.php` | modify | Role gating, role/name editing, in-person acceptance emails |
| `pretech-email.php`, `gear-email.php`, `submission-email-render.php`, `tech-sheet-render.php`, `tech-sheet-data.php`, `inspection-lib.php`, `auth.php`, `.htaccess` | modify | As described per task |

---

### Task 1: Schema foundation, cars and driver profiles

**Files:**
- Modify: `db.php` (`db_init()` table definitions, the upgrade-shim block at the end of `db_init()`, `db_create_user()`, new functions)
- Create: `tests/DbCarsTest.php`, `tests/DbDriversTest.php`
- Modify: `tests/DbTechStatusTest.php` (delete `testMigrationBackfillsExistingRows`)

**Interfaces:**
- Produces (in `db.php`):
  - `db_create_car(PDO $pdo, int $ownerId, array $d): int`. `$d` keys: `car_number` (required), `make`, `model` (required), `year`, `colour`, `engine_cc`.
  - `db_get_car(PDO $pdo, int $id): ?array`
  - `db_get_user_car(PDO $pdo, int $ownerId, int $id): ?array`
  - `db_get_user_cars(PDO $pdo, int $ownerId, bool $includeArchived = false): array`, ordered by number (numeric first), then id.
  - `db_update_car(PDO $pdo, int $id, array $d): void`. Only keys `car_number, year, make, model, colour, engine_cc` are applied. `car_number` recomputes `car_number_norm`.
  - `db_archive_car(PDO $pdo, int $ownerId, int $id): bool`
  - `db_driver_name_norm(string $name): string`: collapse whitespace, trim, lowercase.
  - `db_create_driver(PDO $pdo, int $ownerId, string $name, ?string $licenceNo = null, ?int $userId = null): int`
  - `db_get_driver(PDO $pdo, int $id): ?array`
  - `db_find_driver(PDO $pdo, int $ownerId, string $name): ?array`
  - `db_find_or_create_driver(PDO $pdo, int $ownerId, string $name): ?int`, which returns null for a blank name.
  - `db_get_self_driver(PDO $pdo, int $userId): ?array`
  - `db_get_user_drivers(PDO $pdo, int $ownerId): array`, self profile first, then by name.
  - `db_update_driver_licence(PDO $pdo, int $id, ?string $licenceNo): void`
  - `db_create_user()` now also creates the user's self driver profile.

- [ ] **Step 1: Write the failing tests**

`tests/DbCarsTest.php`:

```php
<?php
// wcma-calculator/tests/DbCarsTest.php
use PHPUnit\Framework\TestCase;

final class DbCarsTest extends TestCase
{
    private function user(PDO $pdo, string $email = 'racer@example.com'): int {
        return db_create_user($pdo, ['email' => $email, 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
    }

    public function testCreateAndGetCar(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $id = db_create_car($pdo, $u, ['car_number' => ' 042 ', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000', 'colour' => 'Silver']);

        $car = db_get_car($pdo, $id);
        $this->assertSame(' 042 ', $car['car_number']);
        $this->assertSame('42', $car['car_number_norm']);
        $this->assertSame('Honda', $car['make']);
        $this->assertSame('Silver', $car['colour']);
        $this->assertNull($car['engine_cc']);
        $this->assertNull($car['archived_at']);
        $this->assertSame($id, (int)db_get_user_car($pdo, $u, $id)['id']);
        $this->assertNull(db_get_user_car($pdo, $u + 1, $id));
        $this->assertNull(db_get_car($pdo, 99999));
    }

    public function testUserCarsOrderedByNumberAndArchivedHidden(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $c10 = db_create_car($pdo, $u, ['car_number' => '10', 'make' => 'A', 'model' => 'A']);
        $c9 = db_create_car($pdo, $u, ['car_number' => '9', 'make' => 'B', 'model' => 'B']);
        $c7a = db_create_car($pdo, $u, ['car_number' => '7A', 'make' => 'C', 'model' => 'C']);
        $ids = fn(array $rows) => array_map(fn($r) => (int)$r['id'], $rows);

        $this->assertSame([$c7a, $c9, $c10], $ids(db_get_user_cars($pdo, $u)));

        $this->assertTrue(db_archive_car($pdo, $u, $c9));
        $this->assertFalse(db_archive_car($pdo, $u, $c9));        // already archived
        $this->assertFalse(db_archive_car($pdo, $u + 1, $c10));   // not the owner
        $this->assertSame([$c7a, $c10], $ids(db_get_user_cars($pdo, $u)));
        $this->assertSame([$c7a, $c9, $c10], $ids(db_get_user_cars($pdo, $u, true)));
    }

    public function testUpdateCarAppliesOnlyKnownFieldsAndRenormalises(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $id = db_create_car($pdo, $u, ['car_number' => '42', 'make' => 'Honda', 'model' => 'S2000']);

        db_update_car($pdo, $id, ['car_number' => '007', 'colour' => 'Red', 'owner_user_id' => 999]);

        $car = db_get_car($pdo, $id);
        $this->assertSame('007', $car['car_number']);
        $this->assertSame('7', $car['car_number_norm']);
        $this->assertSame('Red', $car['colour']);
        $this->assertSame($u, (int)$car['owner_user_id']);
    }
}
```

`tests/DbDriversTest.php`:

```php
<?php
// wcma-calculator/tests/DbDriversTest.php
use PHPUnit\Framework\TestCase;

final class DbDriversTest extends TestCase
{
    public function testNewAccountGetsASelfDriverProfile(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'j@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);

        $self = db_get_self_driver($pdo, $u);
        $this->assertNotNull($self);
        $this->assertSame('Jordan Lee', $self['name']);
        $this->assertSame('jordan lee', $self['name_norm']);
        $this->assertSame($u, (int)$self['owner_user_id']);
        $this->assertSame($u, (int)$self['user_id']);
    }

    public function testFindOrCreateMatchesNormalisedNameWithinOwner(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'j@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $other = db_create_user($pdo, ['email' => 'o@example.com', 'name' => 'Other Person', 'password_hash' => 'x', 'google_id' => null]);

        $self = (int)db_get_self_driver($pdo, $u)['id'];
        $this->assertSame($self, db_find_or_create_driver($pdo, $u, '  jordan   LEE '));

        $sam = db_find_or_create_driver($pdo, $u, 'Sam Patel');
        $this->assertNotSame($self, $sam);
        $this->assertSame($sam, db_find_or_create_driver($pdo, $u, 'sam patel'));
        $this->assertNotSame($sam, db_find_or_create_driver($pdo, $other, 'Sam Patel'));
        $this->assertNull(db_find_or_create_driver($pdo, $u, '   '));
        $this->assertNull(db_get_driver($pdo, $sam)['user_id']);
    }

    public function testUserDriversListSelfFirstThenByName(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'j@example.com', 'name' => 'Zed Racer', 'password_hash' => 'x', 'google_id' => null]);
        db_create_driver($pdo, $u, 'Bob Co');
        db_create_driver($pdo, $u, 'Amy Co', 'WCMA-1');

        $this->assertSame(['Zed Racer', 'Amy Co', 'Bob Co'], array_column(db_get_user_drivers($pdo, $u), 'name'));
    }

    public function testDuplicateNameForSameOwnerIsRejectedAndLicenceUpdates(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'j@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $id = db_create_driver($pdo, $u, 'Sam Patel');
        db_update_driver_licence($pdo, $id, 'WCMA-9');
        $this->assertSame('WCMA-9', db_get_driver($pdo, $id)['licence_no']);

        $this->expectException(PDOException::class);
        db_create_driver($pdo, $u, 'sam  patel');
    }

    public function testNewHubTablesExist(): void
    {
        $pdo = make_temp_pdo();
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['cars', 'drivers', 'event_plans', 'season_links'] as $t) {
            $this->assertContains($t, $tables, $t);
        }
        $userCols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
        $this->assertContains('reminder_emails', $userCols);
    }
}
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php phpunit.phar --filter "DbCarsTest|DbDriversTest"`
Expected: FAIL with `Call to undefined function db_create_car()` (and similar).

- [ ] **Step 3: Rewrite the schema**

In `db.php` `db_init()`:

1. In `CREATE TABLE IF NOT EXISTS submissions`, add `user_id INTEGER,` after `email_send_count` (Task 2 tightens this).
2. In `CREATE TABLE IF NOT EXISTS users`, change the role line to `role TEXT NOT NULL DEFAULT 'user', -- 'user' | 'inspector' | 'admin'` and add `reminder_emails INTEGER NOT NULL DEFAULT 0,` after `active`.
3. In `CREATE TABLE IF NOT EXISTS tech_sheets`, add these lines before `created_at`:

```sql
            accepted_via            TEXT,
            photo_status            TEXT,
            car_number_norm         TEXT,
            season                  INTEGER,
```

4. **Delete the whole upgrade-shim block** at the end of `db_init()`. That runs from the comment `// Add user_id to submissions if migrating an existing DB` through the `$unfilled` backfill loop's closing brace. Replace it with:

```php
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cars (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_user_id   INTEGER NOT NULL,
            car_number      TEXT NOT NULL,
            car_number_norm TEXT NOT NULL,
            year            TEXT,
            make            TEXT NOT NULL,
            model           TEXT NOT NULL,
            colour          TEXT,
            engine_cc       TEXT,
            archived_at     DATETIME,
            created_at      DATETIME NOT NULL,
            updated_at      DATETIME NOT NULL
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cars_owner ON cars (owner_user_id)");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS drivers (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_user_id INTEGER NOT NULL,
            user_id       INTEGER UNIQUE,
            name          TEXT NOT NULL,
            name_norm     TEXT NOT NULL,
            licence_no    TEXT,
            created_at    DATETIME NOT NULL,
            updated_at    DATETIME NOT NULL,
            UNIQUE (owner_user_id, name_norm)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS event_plans (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            event_id   INTEGER NOT NULL,
            car_id     INTEGER NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE (event_id, car_id)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS season_links (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            label      TEXT NOT NULL,
            url        TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            active     INTEGER NOT NULL DEFAULT 1
        )
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tech_sheets_car ON tech_sheets (user_id, car_number_norm, season)");
```

(Task 3 replaces that last index.)

- [ ] **Step 4: Add the car and driver functions**

Add to `db.php`, directly after the `// ── Users ──` section:

```php
// ── Cars ──────────────────────────────────────────────────────────────────────

const DB_CAR_FIELDS = ['car_number', 'year', 'make', 'model', 'colour', 'engine_cc'];

function db_create_car(PDO $pdo, int $ownerId, array $d): int {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("
        INSERT INTO cars (owner_user_id, car_number, car_number_norm, year, make, model, colour, engine_cc, created_at, updated_at)
        VALUES (:o, :n, :norm, :y, :make, :model, :colour, :cc, :now, :now)
    ")->execute([
        ':o' => $ownerId, ':n' => (string)$d['car_number'], ':norm' => techCarNumberNorm((string)$d['car_number']),
        ':y' => $d['year'] ?? null, ':make' => (string)$d['make'], ':model' => (string)$d['model'],
        ':colour' => $d['colour'] ?? null, ':cc' => $d['engine_cc'] ?? null, ':now' => $now,
    ]);
    return (int)$pdo->lastInsertId();
}

function db_get_car(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM cars WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function db_get_user_car(PDO $pdo, int $ownerId, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM cars WHERE id = :id AND owner_user_id = :o");
    $stmt->execute([':id' => $id, ':o' => $ownerId]);
    return $stmt->fetch() ?: null;
}

/** One owner's cars, numeric numbers first in numeric order, then the rest alphabetically. */
function db_get_user_cars(PDO $pdo, int $ownerId, bool $includeArchived = false): array {
    $sql = "SELECT * FROM cars WHERE owner_user_id = :o" . ($includeArchived ? "" : " AND archived_at IS NULL")
         . " ORDER BY (car_number_norm GLOB '[0-9]*') DESC, CAST(car_number_norm AS INTEGER) ASC, car_number_norm ASC, id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':o' => $ownerId]);
    return $stmt->fetchAll();
}

/** Applies only the keys in DB_CAR_FIELDS; a new car_number recomputes car_number_norm. */
function db_update_car(PDO $pdo, int $id, array $d): void {
    $sets = [];
    $params = [':id' => $id, ':now' => date('Y-m-d H:i:s')];
    foreach (DB_CAR_FIELDS as $field) {
        if (!array_key_exists($field, $d)) continue;
        $sets[] = "{$field} = :{$field}";
        $params[':' . $field] = $d[$field];
    }
    if (array_key_exists('car_number', $d)) {
        $sets[] = 'car_number_norm = :norm';
        $params[':norm'] = techCarNumberNorm((string)$d['car_number']);
    }
    if (!$sets) return;
    $pdo->prepare("UPDATE cars SET " . implode(', ', $sets) . ", updated_at = :now WHERE id = :id")->execute($params);
}

/** Archives one of the owner's active cars. False if it is not theirs or is already archived. */
function db_archive_car(PDO $pdo, int $ownerId, int $id): bool {
    $stmt = $pdo->prepare("UPDATE cars SET archived_at = :now, updated_at = :now WHERE id = :id AND owner_user_id = :o AND archived_at IS NULL");
    $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id, ':o' => $ownerId]);
    return $stmt->rowCount() === 1;
}

// ── Drivers ───────────────────────────────────────────────────────────────────

/** Collapse whitespace, trim, lowercase: a driver's identity within the account that manages them. */
function db_driver_name_norm(string $name): string {
    return strtolower(trim((string)preg_replace('/\s+/', ' ', $name)));
}

function db_create_driver(PDO $pdo, int $ownerId, string $name, ?string $licenceNo = null, ?int $userId = null): int {
    $name = trim((string)preg_replace('/\s+/', ' ', $name));
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("
        INSERT INTO drivers (owner_user_id, user_id, name, name_norm, licence_no, created_at, updated_at)
        VALUES (:o, :u, :n, :norm, :l, :now, :now)
    ")->execute([':o' => $ownerId, ':u' => $userId, ':n' => $name, ':norm' => db_driver_name_norm($name), ':l' => $licenceNo, ':now' => $now]);
    return (int)$pdo->lastInsertId();
}

function db_get_driver(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM drivers WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function db_find_driver(PDO $pdo, int $ownerId, string $name): ?array {
    $stmt = $pdo->prepare("SELECT * FROM drivers WHERE owner_user_id = :o AND name_norm = :n");
    $stmt->execute([':o' => $ownerId, ':n' => db_driver_name_norm($name)]);
    return $stmt->fetch() ?: null;
}

/** The owner's driver profile with this (normalised) name, created if missing. Null for a blank name. */
function db_find_or_create_driver(PDO $pdo, int $ownerId, string $name): ?int {
    if (db_driver_name_norm($name) === '') return null;
    $existing = db_find_driver($pdo, $ownerId, $name);
    return $existing !== null ? (int)$existing['id'] : db_create_driver($pdo, $ownerId, $name);
}

function db_get_self_driver(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM drivers WHERE user_id = :u");
    $stmt->execute([':u' => $userId]);
    return $stmt->fetch() ?: null;
}

/** The owner's own profile first, then the drivers they manage by name. */
function db_get_user_drivers(PDO $pdo, int $ownerId): array {
    $stmt = $pdo->prepare("
        SELECT * FROM drivers WHERE owner_user_id = :o
        ORDER BY (user_id IS NOT NULL AND user_id = owner_user_id) DESC, name_norm ASC, id ASC
    ");
    $stmt->execute([':o' => $ownerId]);
    return $stmt->fetchAll();
}

function db_update_driver_licence(PDO $pdo, int $id, ?string $licenceNo): void {
    $pdo->prepare("UPDATE drivers SET licence_no = :l, updated_at = :now WHERE id = :id")
        ->execute([':l' => $licenceNo, ':now' => date('Y-m-d H:i:s'), ':id' => $id]);
}
```

In `db_create_user()`, replace `return (int)$pdo->lastInsertId();` with:

```php
    $id = (int)$pdo->lastInsertId();
    db_create_driver($pdo, $id, (string)$data['name'], null, $id);   // the account holder's own driver profile
    return $id;
```

- [ ] **Step 5: Delete the backfill test**

In `tests/DbTechStatusTest.php`, delete the whole `testMigrationBackfillsExistingRows()` method. The shims it tested no longer exist.

- [ ] **Step 6: Run the new tests, then the full suite**

Run: `php phpunit.phar --filter "DbCarsTest|DbDriversTest"`. Expected: PASS.
Run: `php phpunit.phar`. Expected: PASS (no other behaviour changed yet).

- [ ] **Step 7: Commit**

```bash
git add db.php tests/DbCarsTest.php tests/DbDriversTest.php tests/DbTechStatusTest.php
git commit -m "feat(hub): add cars, driver profiles and hub tables; drop legacy schema shims"
```

---

### Task 2: Class declarations belong to a car

**Files:**
- Modify: `db.php` (submissions table, `db_insert_submission()`, new functions, remove `db_link_submissions_by_email()`)
- Modify: `auth.php:147-153` (remove the link-by-email step and its flash)
- Modify: `tests/bootstrap.php`
- Create: `tests/DbDeclarationsTest.php`
- Delete: `tests/DbLinkSubmissionsTest.php`
- Modify fixtures in: `tests/DbPretechTest.php`, `tests/DbSubmissionsAdminTest.php`, `tests/DbSubmissionsUserIdTest.php`, `tests/DbTechSheetsTest.php`, `tests/DbTechStatusTest.php`, `tests/GearLinksTest.php`, `tests/InspectionEndpointTest.php`, `tests/PretechLibTest.php`, `tests/TechReviewLibTest.php`

**Interfaces:**
- Consumes: `db_create_car()`, `db_get_user_cars()` (Task 1)
- Produces:
  - `db_insert_submission(PDO $pdo, array $data): int` now **requires** `:user_id` and `:car_id` (and throws `InvalidArgumentException` without them). It stores `review_status = 'submitted'` and marks every other non-superseded declaration of the same car as `'superseded'`, in one transaction.
  - `db_get_car_current_declaration(PDO $pdo, int $carId): ?array`, the newest non-superseded declaration.
  - `db_get_car_declarations(PDO $pdo, int $carId): array`, newest first.
  - `db_get_user_current_declarations(PDO $pdo, int $userId): array`, a map of `car_id => current declaration`.
  - `db_count_tech_sheets_for_submission(PDO $pdo, int $submissionId): int`
  - Test helpers in `tests/bootstrap.php`:
    - `test_make_car(PDO $pdo, int $userId, string $number = '42'): int`, which finds or creates by user and normalised number.
    - `test_declaration_data(PDO $pdo, int $userId, string $number = '42', array $overrides = []): array`

- [ ] **Step 1: Add the test helpers**

Append to `tests/bootstrap.php`:

```php
/** The user's car with this (normalised) number, created if missing. */
function test_make_car(PDO $pdo, int $userId, string $number = '42'): int {
    foreach (db_get_user_cars($pdo, $userId, true) as $car) {
        if ($car['car_number_norm'] === techCarNumberNorm($number)) return (int)$car['id'];
    }
    return db_create_car($pdo, $userId, ['car_number' => $number, 'year' => '2020', 'make' => 'Mazda', 'model' => 'MX-5']);
}

/** A complete db_insert_submission() parameter array for the user's car $number. */
function test_declaration_data(PDO $pdo, int $userId, string $number = '42', array $overrides = []): array {
    return array_merge([
        ':submitted_at' => date('Y-m-d H:i:s'), ':name' => 'Test Driver', ':email' => 't@example.com',
        ':year' => '2020', ':make' => 'Mazda', ':model' => 'MX-5', ':comments' => null,
        ':competition_weight' => 2200, ':declared_hp' => 150, ':dyno_hp' => null,
        ':chassis_display' => null, ':body_mods_display' => null, ':transmission_display' => null,
        ':drivetrain_display' => null, ':tires_display' => null, ':brake_suspension' => '[]',
        ':chassis_value' => 0, ':body_mods_value' => 0, ':transmission_value' => 0,
        ':drivetrain_value' => 0, ':tires_value' => 0, ':brake_suspension_value' => 0,
        ':weight_factor' => 0, ':modification_factor' => 0, ':base_ratio' => 14.67, ':modified_ratio' => 14.67,
        ':calculated_class' => 'IT1', ':user_id' => $userId, ':car_id' => test_make_car($pdo, $userId, $number),
    ], $overrides);
}
```

- [ ] **Step 2: Write the failing test**

`tests/DbDeclarationsTest.php`:

```php
<?php
// wcma-calculator/tests/DbDeclarationsTest.php
use PHPUnit\Framework\TestCase;

final class DbDeclarationsTest extends TestCase
{
    private function user(PDO $pdo): int {
        return db_create_user($pdo, ['email' => 'r@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
    }

    public function testDeclarationNeedsUserAndCar(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $data = test_declaration_data($pdo, $u);
        unset($data[':car_id']);
        $this->expectException(InvalidArgumentException::class);
        db_insert_submission($pdo, $data);
    }

    public function testNewDeclarationIsSubmittedAndSupersedesTheCarsPreviousOnes(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $first = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42', [':calculated_class' => 'GT2']));
        $other = db_insert_submission($pdo, test_declaration_data($pdo, $u, '7'));
        $second = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42', [':calculated_class' => 'GT3']));

        $this->assertSame('superseded', db_get_submission($pdo, $first)['review_status']);
        $this->assertSame('submitted', db_get_submission($pdo, $second)['review_status']);
        $this->assertSame('submitted', db_get_submission($pdo, $other)['review_status']);   // another car is untouched

        $car42 = test_make_car($pdo, $u, '42');
        $this->assertSame($second, (int)db_get_car_current_declaration($pdo, $car42)['id']);
        $this->assertSame([$second, $first], array_map(fn($r) => (int)$r['id'], db_get_car_declarations($pdo, $car42)));

        $current = db_get_user_current_declarations($pdo, $u);
        $this->assertSame($second, (int)$current[$car42]['id']);
        $this->assertSame($other, (int)$current[test_make_car($pdo, $u, '7')]['id']);
    }

    public function testCarWithNoDeclarationHasNoCurrent(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $this->assertNull(db_get_car_current_declaration($pdo, test_make_car($pdo, $u, '5')));
    }
}
```

- [ ] **Step 3: Run the test and confirm it fails**

Run: `php phpunit.phar --filter DbDeclarationsTest`
Expected: FAIL. `testDeclarationNeedsUserAndCar` fails with no exception thrown, and the others fail on undefined functions or the missing `car_id` column.

- [ ] **Step 4: Implement**

In `db.php`, in `CREATE TABLE IF NOT EXISTS submissions`, replace the `user_id INTEGER,` line added in Task 1 with:

```sql
            user_id                 INTEGER NOT NULL,
            car_id                  INTEGER NOT NULL,
            review_status           TEXT NOT NULL DEFAULT 'submitted',
            reviewer_note           TEXT,
            reviewed_by_user_id     INTEGER,
            reviewed_at             DATETIME,
```

After the table, add `$pdo->exec("CREATE INDEX IF NOT EXISTS idx_submissions_car ON submissions (car_id)");`.

Replace `db_insert_submission()`:

```php
function db_insert_submission(PDO $pdo, array $data): int {
    if (empty($data[':user_id']) || empty($data[':car_id'])) {
        throw new InvalidArgumentException('A class declaration needs :user_id and :car_id.');
    }
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO submissions (
                submitted_at, name, email, year, make, model, comments,
                competition_weight, declared_hp, dyno_hp,
                chassis_display, body_mods_display, transmission_display,
                drivetrain_display, tires_display, brake_suspension,
                chassis_value, body_mods_value, transmission_value,
                drivetrain_value, tires_value, brake_suspension_value,
                weight_factor, modification_factor, base_ratio, modified_ratio,
                calculated_class, email_sent, user_id, car_id, review_status
            ) VALUES (
                :submitted_at, :name, :email, :year, :make, :model, :comments,
                :competition_weight, :declared_hp, :dyno_hp,
                :chassis_display, :body_mods_display, :transmission_display,
                :drivetrain_display, :tires_display, :brake_suspension,
                :chassis_value, :body_mods_value, :transmission_value,
                :drivetrain_value, :tires_value, :brake_suspension_value,
                :weight_factor, :modification_factor, :base_ratio, :modified_ratio,
                :calculated_class, 0, :user_id, :car_id, 'submitted'
            )
        ")->execute($data);
        $id = (int)$pdo->lastInsertId();
        // Only one current declaration per car: the new one replaces the rest.
        $pdo->prepare("UPDATE submissions SET review_status = 'superseded' WHERE car_id = :c AND id != :id AND review_status != 'superseded'")
            ->execute([':c' => $data[':car_id'], ':id' => $id]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $id;
}
```

Delete `db_link_submissions_by_email()`. Add after `db_get_user_submission()`:

```php
function db_get_car_current_declaration(PDO $pdo, int $carId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM submissions WHERE car_id = :c AND review_status != 'superseded' ORDER BY submitted_at DESC, id DESC LIMIT 1");
    $stmt->execute([':c' => $carId]);
    return $stmt->fetch() ?: null;
}

function db_get_car_declarations(PDO $pdo, int $carId): array {
    $stmt = $pdo->prepare("SELECT * FROM submissions WHERE car_id = :c ORDER BY submitted_at DESC, id DESC");
    $stmt->execute([':c' => $carId]);
    return $stmt->fetchAll();
}

/** car_id => that car's current (newest non-superseded) declaration, for all of one user's cars. */
function db_get_user_current_declarations(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT * FROM submissions WHERE user_id = :u AND review_status != 'superseded' ORDER BY submitted_at DESC, id DESC");
    $stmt->execute([':u' => $userId]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int)$row['car_id']] ??= $row;
    }
    return $map;
}

function db_count_tech_sheets_for_submission(PDO $pdo, int $submissionId): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tech_sheets WHERE submission_id = :s");
    $stmt->execute([':s' => $submissionId]);
    return (int)$stmt->fetchColumn();
}
```

In `auth.php` (register handler, about line 147), delete `$linked = db_link_submissions_by_email($pdo, $userId, $email);` and the whole `if ($linked > 0) { ... }` block.

- [ ] **Step 5: Update the test fixtures**

Delete `tests/DbLinkSubmissionsTest.php`.

In each fixture below that builds a `db_insert_submission()` array by hand, add `':car_id' => test_make_car($pdo, $userId),` next to `':user_id'`. Use `$this->pdo` in `InspectionEndpointTest`, and whatever the local user variable is called:
- `DbPretechTest::fixture`
- `DbTechSheetsTest::makeUserAndSubmission`
- `DbTechStatusTest::fixture`
- `GearLinksTest::fixture`
- `InspectionEndpointTest::makeSheet`
- `PretechLibTest::fixture` (pass its `$number` argument: `test_make_car($pdo, $userId, $number)`)
- `TechReviewLibTest::makeSheet`

`tests/DbSubmissionsAdminTest.php`: change `minimalSubmissionData()` to take the PDO and a user:

```php
    private function minimalSubmissionData(PDO $pdo, ?int $userId = null): array {
        $userId ??= db_find_user_by_email($pdo, 'admin-test@example.com')['id']
            ?? db_create_user($pdo, ['email' => 'admin-test@example.com', 'name' => 'Test Driver', 'password_hash' => 'x', 'google_id' => null]);
        return test_declaration_data($pdo, (int)$userId);
    }
```

Then:
- Replace every `$this->minimalSubmissionData()` with `$this->minimalSubmissionData($pdo)`.
- In `testCountSubmissionsByUser`, build `$data` with `$this->minimalSubmissionData($pdo, $userId)`. Change the `// no user_id` line to `db_insert_submission($pdo, $this->minimalSubmissionData($pdo));`, which is the default test user. Then assert that user's count is 1 alongside the existing assertion for `$userId`.

`tests/DbSubmissionsUserIdTest.php`:
- Delete `testInsertSubmissionWithoutUserIdIsNull`.
- In the remaining tests, replace `$data = $this->minimalSubmissionData(); $data[':user_id'] = $X;` with `$data = test_declaration_data($pdo, $X);`.
- Delete the now-unused `minimalSubmissionData()`.

`tests/DbTechStatusTest.php`: `testIdentityAndSeasonQueries` and `testEventTechSheetsIncludeEventInfoAndOrderByCarNumber` insert sheets with different numbers under one declaration. Task 3 rewrites them; leave them for now.

- [ ] **Step 6: Run the tests**

Run: `php phpunit.phar`
Expected: PASS. If a test outside the listed files fails with `A class declaration needs :user_id and :car_id`, give its fixture the same `':car_id' => test_make_car(...)` line.

- [ ] **Step 7: Commit**

```bash
git add -A db.php auth.php tests/
git commit -m "feat(hub): class declarations belong to a car with a review status"
```

---

### Task 3: Tech sheets carry their car and driver profiles

**Files:**
- Modify: `db.php` (tech_sheets and tech_sheet_drivers tables, `db_insert_tech_sheet()`, `db_update_tech_sheet()`, `db_add_tech_sheet_driver()`, `db_get_identity_sheets()`)
- Modify: `tech-status.php:5-31` (header comment and `techCarKey()`)
- Modify callers of `db_get_identity_sheets()`: `pretech-lib.php:49`, `tech-sheets.php:217`, `admin-tech-sheets.php:107`
- Modify: `tests/TechStatusTest.php`, `tests/DbTechStatusTest.php`
- Create: `tests/DbTechSheetCarTest.php`

**Interfaces:**
- Consumes: `db_find_or_create_driver()` (Task 1), `db_get_submission()`
- Produces:
  - `tech_sheets.car_id` (NOT NULL), taken from the sheet's declaration.
  - `tech_sheets.driver_id`, driver 1's profile.
  - `tech_sheet_drivers.driver_id`.
  - `techCarKey(array $sheet): string` returns `"{car_id}|{season}"`.
  - `db_get_identity_sheets(PDO $pdo, int $carId, int $season): array`

- [ ] **Step 1: Write the failing test**

`tests/DbTechSheetCarTest.php`:

```php
<?php
// wcma-calculator/tests/DbTechSheetCarTest.php
use PHPUnit\Framework\TestCase;

final class DbTechSheetCarTest extends TestCase
{
    private function sheetData(int $u, int $subId, int $eventId, string $driver = 'Jordan Lee'): array {
        return [
            'submission_id' => $subId, 'user_id' => $u, 'event_id' => $eventId, 'sheet_type' => 'endurance',
            'entrant_name' => 'Team', 'driver_name' => $driver, 'car_make' => 'Mazda', 'car_model' => 'MX-5',
            'car_colour' => 'Red', 'car_number' => '42', 'class' => 'IT1', 'engine_cc' => null, 'engine_hp' => null,
            'car_weight' => 2200, 'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1,
        ];
    }

    public function testSheetTakesItsDeclarationsCarAndLinksDriverProfiles(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'r@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42'));
        $event = db_create_event($pdo, 'Fall Sprint', '2026-10-11', null);

        $id = db_insert_tech_sheet($pdo, $this->sheetData($u, $sub, $event));
        $sheet = db_get_tech_sheet($pdo, $id);
        $this->assertSame(test_make_car($pdo, $u, '42'), (int)$sheet['car_id']);
        $this->assertSame((int)db_get_self_driver($pdo, $u)['id'], (int)$sheet['driver_id']);

        db_add_tech_sheet_driver($pdo, $id, 2, 'Sam Patel', '{}');
        $row = db_get_tech_sheet_drivers($pdo, $id)[0];
        $this->assertSame((int)db_find_driver($pdo, $u, 'sam patel')['id'], (int)$row['driver_id']);

        db_update_tech_sheet($pdo, $id, $this->sheetData($u, $sub, $event, 'Sam Patel'));
        $this->assertSame((int)$row['driver_id'], (int)db_get_tech_sheet($pdo, $id)['driver_id']);
    }

    public function testIdentitySheetsAreByCarAndSeason(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'r@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $s42 = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42'));
        $s7 = db_insert_submission($pdo, test_declaration_data($pdo, $u, '7'));
        $spring = db_create_event($pdo, 'Spring', '2026-05-10', null);
        $next = db_create_event($pdo, 'Next', '2027-05-10', null);

        $a = db_insert_tech_sheet($pdo, $this->sheetData($u, $s42, $spring));
        $b = db_insert_tech_sheet($pdo, $this->sheetData($u, $s7, $spring));
        $c = db_insert_tech_sheet($pdo, $this->sheetData($u, $s42, $next));
        $ids = fn(array $rows) => array_map(fn($r) => (int)$r['id'], $rows);

        $this->assertSame([$a], $ids(db_get_identity_sheets($pdo, test_make_car($pdo, $u, '42'), 2026)));
        $this->assertSame([$b], $ids(db_get_identity_sheets($pdo, test_make_car($pdo, $u, '7'), 2026)));
        $this->assertSame([$c], $ids(db_get_identity_sheets($pdo, test_make_car($pdo, $u, '42'), 2027)));
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php phpunit.phar --filter DbTechSheetCarTest`
Expected: FAIL (no `car_id` column).

- [ ] **Step 3: Implement the schema and DB changes**

In `db.php`:
- In the `tech_sheets` CREATE, add `car_id INTEGER NOT NULL,` after `submission_id`, and `driver_id INTEGER,` after `driver_name`.
- In the `tech_sheet_drivers` CREATE, add `driver_id INTEGER,` after `driver_name`.
- Replace the `idx_tech_sheets_car` index line with `$pdo->exec("CREATE INDEX IF NOT EXISTS idx_tech_sheets_car ON tech_sheets (car_id, season)");`.

In `db_insert_tech_sheet()`, before the INSERT:

```php
    $submission = db_get_submission($pdo, (int)$data['submission_id']);
    if ($submission === null) {
        throw new InvalidArgumentException('A tech sheet needs an existing class declaration.');
    }
    $driverId = db_find_or_create_driver($pdo, (int)$data['user_id'], (string)$data['driver_name']);
```

Add `car_id` and `driver_id` to the INSERT column list and values (`:car_id`, `:driver_id`), and to the execute array:

```php
        ':car_id' => (int)$submission['car_id'], ':driver_id' => $driverId,
```

In `db_update_tech_sheet()`, before the UPDATE:

```php
    $owner = (int)(db_get_tech_sheet($pdo, $id)['user_id'] ?? 0);
    $driverId = db_find_or_create_driver($pdo, $owner, (string)$data['driver_name']);
```

Add `driver_id = :driver_id,` to the SET list and `':driver_id' => $driverId,` to the execute array.

Replace `db_add_tech_sheet_driver()`:

```php
function db_add_tech_sheet_driver(PDO $pdo, int $tech_sheet_id, int $driver_number, string $driver_name, string $equipment_json): int {
    $owner = (int)(db_get_tech_sheet($pdo, $tech_sheet_id)['user_id'] ?? 0);
    $pdo->prepare("
        INSERT INTO tech_sheet_drivers (tech_sheet_id, driver_number, driver_name, driver_id, equipment_json)
        VALUES (:tech_sheet_id, :driver_number, :driver_name, :driver_id, :equipment_json)
    ")->execute([
        ':tech_sheet_id' => $tech_sheet_id, ':driver_number' => $driver_number, ':driver_name' => $driver_name,
        ':driver_id' => $owner > 0 ? db_find_or_create_driver($pdo, $owner, $driver_name) : null,
        ':equipment_json' => $equipment_json,
    ]);
    return (int)$pdo->lastInsertId();
}
```

Replace `db_get_identity_sheets()`:

```php
/** All sheets for one car in one season. */
function db_get_identity_sheets(PDO $pdo, int $carId, int $season): array {
    $stmt = $pdo->prepare("SELECT * FROM tech_sheets WHERE car_id = :c AND season = :s ORDER BY id ASC");
    $stmt->execute([':c' => $carId, ':s' => $season]);
    return $stmt->fetchAll();
}
```

- [ ] **Step 4: Switch car identity to `car_id`**

In `tech-status.php`, change the header comment's identity sentence to: `A car is identified by its car record (car_id) + season (calendar year)`. Replace `techCarKey()`:

```php
/** Groups sheets that belong to the same car in the same season. */
function techCarKey(array $sheet): string {
    return (int)($sheet['car_id'] ?? 0) . '|' . (int)($sheet['season'] ?? 0);
}
```

Update the three `db_get_identity_sheets()` callers to pass `(int)$sheet['car_id'], (int)$sheet['season']`:
- `pretech-lib.php:49`
- `tech-sheets.php:217`
- `admin-tech-sheets.php:107`

- [ ] **Step 5: Update the tech status tests**

`tests/TechStatusTest.php`:
- In `sheet()`, add `'car_id' => 500,` to the defaults.
- Replace `testCarKeyUsesStoredIdentityOrDerivesIt` with:

```php
    public function testCarKeyIsCarAndSeason(): void
    {
        $this->assertSame('500|2026', techCarKey($this->sheet(1)));
        $this->assertSame('500|2027', techCarKey($this->sheet(2, ['season' => 2027])));
        $this->assertNotSame(techCarKey($this->sheet(1)), techCarKey($this->sheet(3, ['car_id' => 501])));
    }
```

- In `testStatusForSheetUsesOnlyThatCarsSheets`:
  - add `'car_id' => 501` to the `$otherCar` overrides
  - add `'car_id' => 599` to the overrides of the sheet passed on the `'none'` assertion line.
- In `testRosterAndFilters`, give each distinct car its own `car_id`: the `user_id => 6` sheet gets `'car_id' => 502`, and both `user_id => 7` sheets get `'car_id' => 503`.
- Run `php phpunit.phar --filter TechStatusTest`. For any other failing case, give sheets of distinct cars distinct `car_id` values in the same way.

`tests/DbTechStatusTest.php`: make `fixture()` return a declaration factory, so each car number gets its own car:
- Change its return to `return [$userId, $subId, $spring, $fall, $next, fn(string $n): int => db_insert_submission($pdo, test_declaration_data($pdo, $userId, $n))];`
- Replace `testIdentityAndSeasonQueries` with:

```php
    public function testIdentityAndSeasonQueries(): void
    {
        $pdo = make_temp_pdo();
        [$u, $s, $spring, $fall, $next, $subFor] = $this->fixture($pdo);
        $s7 = $subFor('7');
        $a = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $spring, '42'));
        $b = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $fall, '042'));
        $c = db_insert_tech_sheet($pdo, $this->sheet($u, $s7, $fall, '7'));
        $d = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $next, '42'));
        $car42 = (int)db_get_submission($pdo, $s)['car_id'];

        $ids = fn(array $rows) => array_map(fn($r) => (int)$r['id'], $rows);
        $this->assertEqualsCanonicalizing([$a, $b], $ids(db_get_identity_sheets($pdo, $car42, 2026)));
        $this->assertSame([$d], $ids(db_get_identity_sheets($pdo, $car42, 2027)));
        $this->assertSame([], db_get_identity_sheets($pdo, $car42 + 999, 2026));
        $this->assertEqualsCanonicalizing([$a, $b, $c], $ids(db_get_season_sheets($pdo, 2026)));
    }
```

- In `testEventTechSheetsIncludeEventInfoAndOrderByCarNumber`, destructure `$subFor` and use `$subFor('10')`, `$subFor('9')` and `$subFor('1')` as the submission ids for the three sheets.

- [ ] **Step 6: Run the tests**

Run: `php phpunit.phar`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add -A db.php tech-status.php pretech-lib.php tech-sheets.php admin-tech-sheets.php tests/
git commit -m "feat(hub): tech sheets belong to a car and link driver profiles; car identity is car + season"
```

---

### Task 4: Gear records are keyed by driver profile

**Files:**
- Modify: `db.php` (gear_records table and every gear function)
- Modify: `gear-lib.php:11-14` (`gearNameNorm`) and `gear-lib.php:86-107` (`gearCreate`)
- Modify: `tests/DbGearTest.php`

**Interfaces:**
- Consumes: `db_find_or_create_driver()`, `db_find_driver()`, `db_update_driver_licence()`, `db_driver_name_norm()` (Task 1)
- Produces:
  - `db_insert_gear_record(PDO $pdo, int $driverId, int $season): int`
  - Gear rows returned by every `db_*gear*` getter keep the old column names through a join: `owner_user_id`, `driver_name`, `driver_name_norm` and `licence_no` come from the driver, plus the new `driver_id`. Every existing consumer (gear pages, chips, emails, `gearLinksForSheet`) keeps working unchanged.
  - `db_find_gear_record(PDO $pdo, int $ownerId, string $driverNameNorm, int $season): ?array` keeps its signature.
  - `gearCreate()` keeps its signature. It finds or creates the driver profile, and stores a given licence on the profile.

- [ ] **Step 1: Rewrite the DB tests for the new shape**

In `tests/DbGearTest.php`:

Replace the `gear()` helper:

```php
    private function gear(PDO $pdo, int $owner, string $name = 'Jane Racer', int $season = 2026): int {
        return db_insert_gear_record($pdo, db_find_or_create_driver($pdo, $owner, $name), $season);
    }
```

Replace `testInsertGetAndFind`:

```php
    public function testInsertGetAndFind(): void
    {
        $pdo = make_temp_pdo();
        [$owner] = $this->users($pdo);
        $driverId = db_create_driver($pdo, $owner, 'Jane Racer', 'WCMA-123');
        $id = db_insert_gear_record($pdo, $driverId, 2026);

        $row = db_get_gear_record($pdo, $id);
        $this->assertSame($driverId, (int)$row['driver_id']);
        $this->assertSame($owner, (int)$row['owner_user_id']);
        $this->assertSame('Jane Racer', $row['driver_name']);
        $this->assertSame('jane racer', $row['driver_name_norm']);
        $this->assertSame('WCMA-123', $row['licence_no']);
        $this->assertSame(2026, (int)$row['season']);
        $this->assertSame('open', $row['status']);
        $this->assertNull($row['photo_status']);
        $this->assertNull($row['accepted_via']);

        $this->assertSame($id, (int)db_find_gear_record($pdo, $owner, 'jane racer', 2026)['id']);
        $this->assertNull(db_find_gear_record($pdo, $owner, 'jane racer', 2027));
        $this->assertNull(db_find_gear_record($pdo, $owner + 1, 'jane racer', 2026));
        $this->assertNull(db_get_gear_record($pdo, 99999));
    }
```

`testDuplicateOwnerNameSeasonIsRejected` stays as-is: the `(driver_id, season)` uniqueness now rejects it. Every other test in the file calls `$this->gear(...)` and needs no change.

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php phpunit.phar --filter DbGearTest`
Expected: FAIL (`db_insert_gear_record()` expects 6 arguments / `driver_id` missing).

- [ ] **Step 3: Implement**

In `db.php`, replace the `gear_records` CREATE:

```php
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS gear_records (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            driver_id           INTEGER NOT NULL,
            season              INTEGER NOT NULL,
            photo_status        TEXT,
            status              TEXT NOT NULL DEFAULT 'open',
            accepted_via        TEXT,
            reviewed_by_user_id INTEGER,
            reviewed_at         DATETIME,
            created_at          DATETIME NOT NULL,
            updated_at          DATETIME NOT NULL,
            UNIQUE (driver_id, season)
        )
    ");
```

Replace the gear read and insert functions. The transition, accept and revoke functions only touch `gear_records` columns that still exist, so leave them as they are.

```php
/** Gear rows carry their driver's identity under the column names every consumer already uses. */
const DB_GEAR_SELECT = "
    SELECT g.*, d.owner_user_id AS owner_user_id, d.name AS driver_name,
           d.name_norm AS driver_name_norm, d.licence_no AS licence_no
    FROM gear_records g JOIN drivers d ON d.id = g.driver_id";

function db_insert_gear_record(PDO $pdo, int $driverId, int $season): int {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO gear_records (driver_id, season, created_at, updated_at) VALUES (:d, :s, :now, :now)")
        ->execute([':d' => $driverId, ':s' => $season, ':now' => $now]);
    return (int)$pdo->lastInsertId();
}

function db_get_gear_record(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare(DB_GEAR_SELECT . " WHERE g.id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function db_find_gear_record(PDO $pdo, int $ownerId, string $driverNameNorm, int $season): ?array {
    $stmt = $pdo->prepare(DB_GEAR_SELECT . " WHERE d.owner_user_id = :o AND d.name_norm = :n AND g.season = :s");
    $stmt->execute([':o' => $ownerId, ':n' => $driverNameNorm, ':s' => $season]);
    return $stmt->fetch() ?: null;
}

/** All of one owner's gear records, newest season first, then by driver name. */
function db_get_user_gear_records(PDO $pdo, int $ownerId): array {
    $stmt = $pdo->prepare(DB_GEAR_SELECT . " WHERE d.owner_user_id = :o ORDER BY g.season DESC, d.name ASC, g.id ASC");
    $stmt->execute([':o' => $ownerId]);
    return $stmt->fetchAll();
}

/** Every owner's records for a season, with the owner's name and email, by driver name. */
function db_get_gear_records_for_season(PDO $pdo, int $season): array {
    $stmt = $pdo->prepare("
        SELECT g.*, d.owner_user_id AS owner_user_id, d.name AS driver_name, d.name_norm AS driver_name_norm,
               d.licence_no AS licence_no, u.name AS owner_name, u.email AS owner_email
        FROM gear_records g JOIN drivers d ON d.id = g.driver_id LEFT JOIN users u ON u.id = d.owner_user_id
        WHERE g.season = :s ORDER BY d.name ASC, g.id ASC
    ");
    $stmt->execute([':s' => $season]);
    return $stmt->fetchAll();
}
```

In `gear-lib.php`, make `gearNameNorm()` delegate, so there is one normalisation rule:

```php
function gearNameNorm(string $name): string {
    return db_driver_name_norm($name);
}
```

In `gearCreate()`, replace everything from `$norm = gearNameNorm($name);` to the end of the function with:

```php
    $norm = gearNameNorm($name);
    if (db_find_gear_record($pdo, $ownerId, $norm, $season) !== null) {
        return $fail('You already have a gear record for ' . $name . ' this season.');
    }
    try {
        $driverId = db_find_or_create_driver($pdo, $ownerId, $name);
        if ($licence !== '') db_update_driver_licence($pdo, (int)$driverId, $licence);
        $id = db_insert_gear_record($pdo, (int)$driverId, $season);
    } catch (PDOException $e) {
        return $fail('You already have a gear record for ' . $name . ' this season.');   // lost a race with a duplicate request
    }
    return ['ok' => true, 'error' => null, 'id' => $id];
```

- [ ] **Step 4: Run the tests**

Run: `php phpunit.phar`
Expected: PASS. The gear lib, chips, links, email and endpoint tests exercise the aliased columns. If one fails on a missing `driver_name`/`owner_user_id`, that query is not using `DB_GEAR_SELECT`, so fix the query, not the test.

- [ ] **Step 5: Commit**

```bash
git add db.php gear-lib.php tests/DbGearTest.php
git commit -m "feat(hub): gear records belong to a driver profile"
```

---

### Task 5: Inspector role and staff access

**Files:**
- Create: `roles.php`, `tests/RolesTest.php`
- Modify:
  - `session_bootstrap.php`
  - `view_helpers.php` (`renderAdminNav()`, `renderCommonNav()`)
  - `inspection-lib.php:59`
  - `admin.php` (router, `requireAuth()`, users page, role and name handlers)
  - `admin-feedback.php`, `admin-gear.php`, `admin-tech-sheets.php` (`renderAdminNav()` calls)
  - `car-classing.html` (inline nav script)
  - `db.php`
  - `tests/AdminNavTest.php`

**Interfaces:**
- Produces:
  - `roles.php`:
    - `ROLE_LEVELS = ['user' => 0, 'inspector' => 1, 'admin' => 2]`
    - `user_has_role(?array $user, string $min): bool`
    - `adminActionMinRole(string $action): string`, which returns `'inspector'` or `'admin'`
    - `userHasFirstAndLastName(string $name): bool`
  - `session_bootstrap.php`: `is_inspector(): bool` (true for inspector and admin)
  - `view_helpers.php`: `renderAdminNav(string $current, string $role = 'admin'): string`. Admin-only destinations are hidden from inspectors.
  - `db.php`:
    - `db_set_user_name(PDO $pdo, int $id, string $name): void`, which also renames the self driver profile when that doesn't collide.
    - `db_set_user_role()` is unchanged.

- [ ] **Step 1: Write the failing tests**

`tests/RolesTest.php`:

```php
<?php
// wcma-calculator/tests/RolesTest.php
require_once __DIR__ . '/../roles.php';

use PHPUnit\Framework\TestCase;

final class RolesTest extends TestCase
{
    public function testRoleHierarchy(): void
    {
        $this->assertFalse(user_has_role(null, 'user'));
        $this->assertTrue(user_has_role(['role' => 'user'], 'user'));
        $this->assertFalse(user_has_role(['role' => 'user'], 'inspector'));
        $this->assertTrue(user_has_role(['role' => 'inspector'], 'inspector'));
        $this->assertFalse(user_has_role(['role' => 'inspector'], 'admin'));
        $this->assertTrue(user_has_role(['role' => 'admin'], 'inspector'));
        $this->assertFalse(user_has_role(['role' => 'bogus'], 'user'));
    }

    public function testInspectorsGetEventDayWorkAndAdminsKeepTheBackOffice(): void
    {
        foreach (['list', 'view', 'file', 'resend', 'export', 'tech-sheets', 'tech-sheet', 'tech-sheet-accept',
                  'tech-sheet-revoke', 'tech-sheet-sig', 'tech-sheet-photos-accept', 'tech-sheet-photos-send-back',
                  'gear', 'gear-record', 'gear-record-accept', 'gear-record-revoke', 'gear-photos-accept',
                  'gear-photos-send-back', 'gear-create-accept'] as $action) {
            $this->assertSame('inspector', adminActionMinRole($action), $action);
        }
        foreach (['update-contact', 'delete', 'bulk-delete', 'users', 'set-role', 'set-name', 'deactivate', 'activate',
                  'events', 'event-create', 'settings', 'settings-update', 'feedback', 'season-links', 'anything-new'] as $action) {
            $this->assertSame('admin', adminActionMinRole($action), $action);
        }
    }

    public function testFirstAndLastName(): void
    {
        $this->assertTrue(userHasFirstAndLastName('Ivy Inspector'));
        $this->assertTrue(userHasFirstAndLastName('  Mary  Ann Smith '));
        $this->assertFalse(userHasFirstAndLastName('Ivy'));
        $this->assertFalse(userHasFirstAndLastName('   '));
    }
}
```

Add to `tests/AdminNavTest.php`:

```php
    public function testInspectorsDoNotSeeBackOfficeDestinations(): void
    {
        $html = renderAdminNav('gear', 'inspector');
        foreach (['Submissions', 'Tech Sheets'] as $label) {
            $this->assertStringContainsString('>' . $label . '<', $html, $label);
        }
        foreach (['Manage Users', 'Events', 'Settings', 'Feedback', 'Season Links'] as $label) {
            $this->assertStringNotContainsString('>' . $label . '<', $html, $label);
        }
    }
```

In `testEveryAdminDestinationIsListedOnce`, add `'Season Links'` to the label list and `'action=season-links"'` to the href list. Task 6 builds that page.

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php phpunit.phar --filter "RolesTest|AdminNavTest"`
Expected: FAIL (`roles.php` missing, no Season Links).

- [ ] **Step 3: Implement `roles.php`**

```php
<?php
// wcma-calculator/roles.php
//
// Pure role helpers (no session, no DB). user < inspector < admin. Inspectors do classing,
// car tech and gear; admins also run the back office.

const ROLE_LEVELS = ['user' => 0, 'inspector' => 1, 'admin' => 2];

/** admin.php actions an inspector may use. Everything else in admin.php is admin-only. */
const ADMIN_INSPECTOR_ACTIONS = [
    'list', 'view', 'file', 'resend', 'export',
    'tech-sheets', 'tech-sheet', 'tech-sheet-accept', 'tech-sheet-revoke', 'tech-sheet-sig',
    'tech-sheet-photos-accept', 'tech-sheet-photos-send-back',
    'gear', 'gear-record', 'gear-record-accept', 'gear-record-revoke', 'gear-photos-accept',
    'gear-photos-send-back', 'gear-create-accept',
];

function user_has_role(?array $user, string $min): bool {
    if ($user === null) return false;
    $have = ROLE_LEVELS[$user['role'] ?? ''] ?? null;
    return $have !== null && $have >= (ROLE_LEVELS[$min] ?? PHP_INT_MAX);
}

function adminActionMinRole(string $action): string {
    return in_array($action, ADMIN_INSPECTOR_ACTIONS, true) ? 'inspector' : 'admin';
}

/** Staff review emails name the reviewer, so staff accounts need at least two name words. */
function userHasFirstAndLastName(string $name): bool {
    return count(preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY)) >= 2;
}
```

- [ ] **Step 4: Wire roles into session, nav and access checks**

`session_bootstrap.php`: add `require_once __DIR__ . '/roles.php';` after the opening `<?php`. Replace `is_admin()` and add `is_inspector()`:

```php
function is_admin(): bool {
    return user_has_role(current_user(), 'admin');
}

/** Inspectors and admins: classing, car tech and gear. */
function is_inspector(): bool {
    return user_has_role(current_user(), 'inspector');
}
```

`view_helpers.php`: replace `renderAdminNav()`:

```php
/**
 * The staff sub-navigation shared by every admin page. $current marks the page being viewed
 * (inert "you are here" text, see navItem()). Inspectors see only the event-day destinations.
 */
function renderAdminNav(string $current, string $role = 'admin'): string {
    $items = [
        'submissions'  => ['admin.php', 'Submissions', 'inspector'],
        'tech-sheets'  => ['admin.php?action=tech-sheets', 'Tech Sheets', 'inspector'],
        'gear'         => ['admin.php?action=gear', 'Gear', 'inspector'],
        'users'        => ['admin.php?action=users', 'Manage Users', 'admin'],
        'events'       => ['admin.php?action=events', 'Events', 'admin'],
        'season-links' => ['admin.php?action=season-links', 'Season Links', 'admin'],
        'settings'     => ['admin.php?action=settings', 'Settings', 'admin'],
        'feedback'     => ['admin.php?action=feedback', 'Feedback', 'admin'],
    ];
    $links = [];
    foreach ($items as $key => [$href, $label, $min]) {
        if (!user_has_role(['role' => $role], $min)) continue;
        $links[] = navItem($href, $label, $key === $current);
    }
    return implode(' ', $links);
}
```

`view_helpers.php` must load roles: add `require_once __DIR__ . '/roles.php';` at the top of the file.

In `renderCommonNav()`, replace the `if (is_admin()) { ... }` block with:

```php
        if (is_inspector()) {
            $links[] = navItem('admin.php', is_admin() ? 'Admin' : 'Inspector', $current === 'admin');
        }
```

Pass the role to every `renderAdminNav(` call. Find them with `grep -rn "renderAdminNav(" --include=*.php . | grep -v tests`. Add a second argument of `(string)(current_user()['role'] ?? 'user')` to each, in `admin.php`, `admin-feedback.php`, `admin-gear.php` and `admin-tech-sheets.php`.

`inspection-lib.php:59`: replace `if (($user['role'] ?? '') === 'admin') return true;` with `if (user_has_role($user, 'inspector')) return true;`. Add `require_once __DIR__ . '/roles.php';` at the top of `inspection-lib.php`. Update the docblock line above it to say "Inspectors and admins may always read and write."

`car-classing.html` inline nav script: replace `if (data.role === 'admin') { ... }` with:

```js
            if (data.role === 'admin' || data.role === 'inspector') {
              var adminLink = document.createElement('a');
              adminLink.href = 'admin.php';
              adminLink.textContent = data.role === 'admin' ? 'Admin' : 'Inspector';
              nav.appendChild(adminLink);
            }
```

- [ ] **Step 5: Gate admin.php by action**

In `admin.php`, replace `requireAuth()`:

```php
function requireAuth(string $min = 'admin'): void {
    if (current_user() === null) {
        header('Location: auth.php?action=login');
        exit;
    }
    if (!user_has_role(current_user(), $min)) {
        setFlash('You are not authorized to view that page.', 'error');
        header('Location: ' . (is_inspector() ? 'admin.php' : 'car-classing.html'));
        exit;
    }
}
```

After `$action = $_GET['action'] ?? 'list';`, add `$minRole = adminActionMinRole($action);`. Then replace every `requireAuth();` inside the router `switch` with `requireAuth($minRole);`, for example with `sed -i 's/requireAuth();/requireAuth($minRole);/' admin.php`, and check the diff.

Replace the `promote` and `demote` router cases with:

```php
    case 'set-role':
        requireAuth($minRole);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=users'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleSetRole($pdo, (int)($_POST['id'] ?? 0), (string)($_POST['role'] ?? ''));
        break;

    case 'set-name':
        requireAuth($minRole);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=users'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleSetName($pdo, (int)($_POST['id'] ?? 0), (string)($_POST['name'] ?? ''));
        break;
```

Replace `handleSetRole()` and add `handleSetName()`:

```php
function handleSetRole(PDO $pdo, int $id, string $role): void {
    $target = db_find_user_by_id($pdo, $id);
    if ($target === null || !isset(ROLE_LEVELS[$role])) {
        setFlash('Choose a valid user and role.', 'error');
    } elseif ($target['role'] === 'admin' && $role !== 'admin' && db_count_admins($pdo) <= 1) {
        setFlash('Cannot change the role of the last remaining admin.', 'error');
    } elseif ($role !== 'user' && !userHasFirstAndLastName((string)$target['name'])) {
        setFlash('Add a first and last name for this account before giving it the ' . $role . ' role. Review emails name the inspector.', 'error');
    } else {
        db_set_user_role($pdo, $id, $role);
        setFlash('Role updated. They will see the change the next time they sign in.', 'success');
    }
    header('Location: admin.php?action=users');
    exit;
}

function handleSetName(PDO $pdo, int $id, string $name): void {
    $name = trim((string)preg_replace('/\s+/', ' ', $name));
    if (db_find_user_by_id($pdo, $id) === null || $name === '' || mb_strlen($name, 'UTF-8') > 100) {
        setFlash('Enter a name of 100 characters or fewer.', 'error');
    } else {
        db_set_user_name($pdo, $id, $name);
        setFlash('Name updated.', 'success');
    }
    header('Location: admin.php?action=users');
    exit;
}
```

Add to `db.php` (Users section):

```php
/** Renames the account and its self driver profile (the profile is left alone if the new name collides). */
function db_set_user_name(PDO $pdo, int $id, string $name): void {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE users SET name = :n WHERE id = :id")->execute([':n' => $name, ':id' => $id]);
    $pdo->prepare("UPDATE OR IGNORE drivers SET name = :n, name_norm = :norm, updated_at = :now WHERE user_id = :id")
        ->execute([':n' => $name, ':norm' => db_driver_name_norm($name), ':now' => $now, ':id' => $id]);
}
```

In `renderUsersPage()`:
- Add `<option value="inspector">Inspector</option>` to the role filter.
- In the Name cell, after the name, add `<?php if ($u['role'] !== 'user' && !userHasFirstAndLastName((string)$u['name'])): ?> <span class="badge-fail">Needs first &amp; last name</span><?php endif; ?>`.
- Replace the Promote/Demote `if/else` forms with:

```php
          <form method="post" action="admin.php?action=set-role" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <label class="visually-hidden" for="role-<?= (int)$u['id'] ?>">Role for <?= h($u['email']) ?></label>
            <select id="role-<?= (int)$u['id'] ?>" name="role">
              <?php foreach (array_keys(ROLE_LEVELS) as $r): ?>
              <option value="<?= h($r) ?>"<?= $u['role'] === $r ? ' selected' : '' ?>><?= h(ucfirst($r)) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-role">Save role</button>
          </form>
          <form method="post" action="admin.php?action=set-name" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <label class="visually-hidden" for="name-<?= (int)$u['id'] ?>">Name for <?= h($u['email']) ?></label>
            <input type="text" id="name-<?= (int)$u['id'] ?>" name="name" value="<?= h($u['name']) ?>" maxlength="100" required>
            <button type="submit" class="btn-role">Save name</button>
          </form>
```

If `.visually-hidden` doesn't exist in `css/calculator.css` (check with `grep -n "visually-hidden" css/calculator.css`), add:
`.visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }`

**Hide admin-only buttons from inspectors.** Run `grep -n "action=delete\|action=bulk-delete\|action=update-contact" admin.php` and wrap each form, button or select-all control it finds in `<?php if (is_admin()): ?> ... <?php endif; ?>`. That covers the list page's bulk-delete form and checkboxes, and the detail page's delete and edit-contact forms.

- [ ] **Step 6: Run the tests**

Run: `php phpunit.phar`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add roles.php session_bootstrap.php view_helpers.php inspection-lib.php admin.php admin-feedback.php admin-gear.php admin-tech-sheets.php car-classing.html db.php css/calculator.css tests/RolesTest.php tests/AdminNavTest.php
git commit -m "feat(hub): add the inspector role and gate staff pages by role"
```

---

### Task 6: Season links (admin)

**Files:**
- Create: `season-links-lib.php`, `admin-season-links.php`, `tests/SeasonLinksTest.php`
- Modify: `db.php`, `admin.php` (require the new file and add router cases)

**Interfaces:**
- Produces:
  - `db_get_season_links(PDO $pdo, bool $activeOnly = false): array`, ordered by `sort_order`, then `id`.
  - `db_create_season_link(PDO $pdo, string $label, string $url, int $sortOrder): int`
  - `db_update_season_link(PDO $pdo, int $id, string $label, string $url, int $sortOrder, bool $active): void`
  - `db_delete_season_link(PDO $pdo, int $id): void`
  - `seasonLinkValidate(string $label, string $url, string $sortOrder): array{ok: bool, error: ?string, label: string, url: string, sort_order: int}`

Phase 2 shows these links on Home.

- [ ] **Step 1: Write the failing test**

`tests/SeasonLinksTest.php`:

```php
<?php
// wcma-calculator/tests/SeasonLinksTest.php
require_once __DIR__ . '/../season-links-lib.php';

use PHPUnit\Framework\TestCase;

final class SeasonLinksTest extends TestCase
{
    public function testValidation(): void
    {
        $ok = seasonLinkValidate('  2026 Annual Waiver ', ' https://www.motorsportreg.com/events/x-575797 ', '2');
        $this->assertTrue($ok['ok']);
        $this->assertSame('2026 Annual Waiver', $ok['label']);
        $this->assertSame('https://www.motorsportreg.com/events/x-575797', $ok['url']);
        $this->assertSame(2, $ok['sort_order']);

        $this->assertFalse(seasonLinkValidate('', 'https://x.test', '0')['ok']);
        $this->assertFalse(seasonLinkValidate('Label', 'javascript:alert(1)', '0')['ok']);
        $this->assertFalse(seasonLinkValidate('Label', 'not a url', '0')['ok']);
        $this->assertFalse(seasonLinkValidate(str_repeat('x', 121), 'https://x.test', '0')['ok']);
        $this->assertSame(0, seasonLinkValidate('Label', 'https://x.test', 'abc')['sort_order']);
    }

    public function testCrudAndOrdering(): void
    {
        $pdo = make_temp_pdo();
        $b = db_create_season_link($pdo, 'Licences', 'https://x.test/b', 2);
        $a = db_create_season_link($pdo, 'Waiver', 'https://x.test/a', 1);
        $this->assertSame(['Waiver', 'Licences'], array_column(db_get_season_links($pdo), 'label'));

        db_update_season_link($pdo, $b, 'Race Licences', 'https://x.test/b2', 0, false);
        $this->assertSame(['Waiver'], array_column(db_get_season_links($pdo, true), 'label'));
        $this->assertSame(['Race Licences', 'Waiver'], array_column(db_get_season_links($pdo), 'label'));

        db_delete_season_link($pdo, $a);
        $this->assertCount(1, db_get_season_links($pdo));
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php phpunit.phar --filter SeasonLinksTest`
Expected: FAIL (file missing).

- [ ] **Step 3: Implement the library and DB functions**

`season-links-lib.php`:

```php
<?php
// wcma-calculator/season-links-lib.php
//
// Admin-maintained links shown to competitors (e.g. this season's MotorsportReg waiver and
// licences). MSR items get new URLs every season, so these are data, not code.

/** @return array{ok: bool, error: ?string, label: string, url: string, sort_order: int} */
function seasonLinkValidate(string $label, string $url, string $sortOrder): array {
    $label = trim((string)preg_replace('/\s+/', ' ', $label));
    $url = trim($url);
    $sort = ctype_digit(ltrim(trim($sortOrder), '-')) ? (int)$sortOrder : 0;
    $out = ['ok' => false, 'error' => null, 'label' => $label, 'url' => $url, 'sort_order' => $sort];

    if ($label === '' || mb_strlen($label, 'UTF-8') > 120) {
        return ['error' => 'Enter a label of 120 characters or fewer.'] + $out;
    }
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    if (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
        return ['error' => 'Enter a full web address starting with https://.'] + $out;
    }
    return ['ok' => true] + $out;
}
```

Add to `db.php`:

```php
// ── Season links ──────────────────────────────────────────────────────────────

function db_get_season_links(PDO $pdo, bool $activeOnly = false): array {
    return $pdo->query("SELECT * FROM season_links" . ($activeOnly ? " WHERE active = 1" : "") . " ORDER BY sort_order ASC, id ASC")->fetchAll();
}

function db_create_season_link(PDO $pdo, string $label, string $url, int $sortOrder): int {
    $pdo->prepare("INSERT INTO season_links (label, url, sort_order) VALUES (:l, :u, :s)")
        ->execute([':l' => $label, ':u' => $url, ':s' => $sortOrder]);
    return (int)$pdo->lastInsertId();
}

function db_update_season_link(PDO $pdo, int $id, string $label, string $url, int $sortOrder, bool $active): void {
    $pdo->prepare("UPDATE season_links SET label = :l, url = :u, sort_order = :s, active = :a WHERE id = :id")
        ->execute([':l' => $label, ':u' => $url, ':s' => $sortOrder, ':a' => $active ? 1 : 0, ':id' => $id]);
}

function db_delete_season_link(PDO $pdo, int $id): void {
    $pdo->prepare("DELETE FROM season_links WHERE id = :id")->execute([':id' => $id]);
}
```

- [ ] **Step 4: Add the admin page**

`admin-season-links.php`:

```php
<?php
// wcma-calculator/admin-season-links.php
//
// Admin: this season's links for competitors (MotorsportReg waiver, licences, number reservation).
// Loaded by admin.php, which has already checked the admin role and CSRF.

function handleSeasonLinksList(PDO $pdo): void {
    renderSeasonLinksPage(db_get_season_links($pdo), generateCsrfToken(), getFlash());
}

function handleSeasonLinkSave(PDO $pdo, int $id): void {
    $v = seasonLinkValidate((string)($_POST['label'] ?? ''), (string)($_POST['url'] ?? ''), (string)($_POST['sort_order'] ?? '0'));
    if (!$v['ok']) {
        setFlash($v['error'], 'error');
    } elseif ($id > 0) {
        db_update_season_link($pdo, $id, $v['label'], $v['url'], $v['sort_order'], !empty($_POST['active']));
        setFlash('Link updated.', 'success');
    } else {
        db_create_season_link($pdo, $v['label'], $v['url'], $v['sort_order']);
        setFlash('Link added.', 'success');
    }
    header('Location: admin.php?action=season-links');
    exit;
}

function handleSeasonLinkDelete(PDO $pdo, int $id): void {
    db_delete_season_link($pdo, $id);
    setFlash('Link removed.', 'success');
    header('Location: admin.php?action=season-links');
    exit;
}

function renderSeasonLinksPage(array $links, string $csrf, ?array $flash): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Season Links — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Season Links', renderAdminNav('season-links', (string)(current_user()['role'] ?? 'user')) . renderCommonNav('admin')); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
  <div class="detail-card">
    <p>These links are shown to competitors as "This season on MotorsportReg". MotorsportReg gives each season's waiver and licences new web addresses, so update them at the start of every season.</p>
  </div>

  <table class="data-table">
    <thead><tr><th>Order</th><th>Label</th><th>Web address</th><th>Shown</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (!$links): ?>
      <tr><td colspan="5" class="empty-row">No links yet. Add one below.</td></tr>
    <?php endif; ?>
    <?php foreach ($links as $l): $fid = 'link-' . (int)$l['id']; ?>
      <tr>
        <td><input form="<?= $fid ?>" type="number" name="sort_order" value="<?= (int)$l['sort_order'] ?>" style="width:5rem" aria-label="Order"></td>
        <td><input form="<?= $fid ?>" type="text" name="label" value="<?= h($l['label']) ?>" maxlength="120" required aria-label="Label"></td>
        <td><input form="<?= $fid ?>" type="url" name="url" value="<?= h($l['url']) ?>" required aria-label="Web address"></td>
        <td><input form="<?= $fid ?>" type="checkbox" name="active" value="1"<?= (int)$l['active'] === 1 ? ' checked' : '' ?> aria-label="Shown"></td>
        <td class="actions">
          <form id="<?= $fid ?>" method="post" action="admin.php?action=season-link-save" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <button type="submit" class="link-button">Save</button>
          </form>
          <form method="post" action="admin.php?action=season-link-delete" style="display:inline" data-confirm="Remove this link?">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <button type="submit" class="link-button">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <form method="post" action="admin.php?action=season-link-save" class="detail-card" style="margin-top:1.5rem">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="id" value="0">
    <h3>Add a link</h3>
    <label for="new-label">Label</label>
    <input type="text" id="new-label" name="label" maxlength="120" required placeholder="2026 Annual Waiver / Hardcard">
    <label for="new-url">Web address</label>
    <input type="url" id="new-url" name="url" required placeholder="https://www.motorsportreg.com/events/…">
    <label for="new-sort">Order (lower shows first)</label>
    <input type="number" id="new-sort" name="sort_order" value="<?= count($links) ?>">
    <button type="submit" class="btn btn-primary" style="margin-top:.75rem">Add link</button>
  </form>
</div>
<script src="js/confirm-modal.js"></script>
<script src="js/form-feedback.js"></script>
</body>
</html><?php
}
```

In `admin.php`, add `require __DIR__ . '/season-links-lib.php';` and `require __DIR__ . '/admin-season-links.php';` with the other requires, and these router cases (all admin-only by default):

```php
    case 'season-links':
        requireAuth($minRole);
        handleSeasonLinksList($pdo);
        break;

    case 'season-link-save':
        requireAuth($minRole);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=season-links'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleSeasonLinkSave($pdo, (int)($_POST['id'] ?? 0));
        break;

    case 'season-link-delete':
        requireAuth($minRole);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=season-links'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleSeasonLinkDelete($pdo, (int)($_POST['id'] ?? 0));
        break;
```

- [ ] **Step 5: Run the tests**

Run: `php phpunit.phar`. Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add season-links-lib.php admin-season-links.php admin.php db.php tests/SeasonLinksTest.php
git commit -m "feat(hub): admin-maintained season links"
```

---

### Task 7: Declaring a class requires sign-in and a car

**Files:**
- Create: `cars-lib.php`, `cars.php`, `email-copy.php`, `tests/CarsLibTest.php`
- Modify: `car-classing.php`

**Interfaces:**
- Consumes: car and declaration DB functions (Tasks 1–2)
- Produces:
  - `email-copy.php`: `COPY_DECLARATION_RECEIVED` (Task 11 adds the rest).
  - `cars-lib.php`:
    - `carsResolveForDeclaration(PDO $pdo, int $userId, array $post): array{ok: bool, error: ?string, car_id: ?int}`. `$post['car_id']` is an owned car id, `'new'` (which needs `car_number`, `make`, `model`), or `''`, which is an error.
    - `carDisplayName(array $car): string`, e.g. `"#42 2004 Honda S2000"`.
    - `declarationReviewLabel(string $status): string`
    - `declarationReviewBadgeClass(string $status): string`
    - `carsPublicShape(array $car, ?array $declaration): array`
    - `carsApplySheetDetails(PDO $pdo, int $carId, string $number, string $colour, ?string $engineCc): void`
  - `cars.php?action=list` returns JSON `{success: true, cars: [carsPublicShape…]}`, or 401 when signed out.
  - `car-classing.php` returns 401 JSON when signed out. Otherwise it resolves the car, and its success message starts with `COPY_DECLARATION_RECEIVED`.

- [ ] **Step 1: Write the failing test**

`tests/CarsLibTest.php`:

```php
<?php
// wcma-calculator/tests/CarsLibTest.php
require_once __DIR__ . '/../cars-lib.php';

use PHPUnit\Framework\TestCase;

final class CarsLibTest extends TestCase
{
    private function user(PDO $pdo, string $email = 'r@example.com'): int {
        return db_create_user($pdo, ['email' => $email, 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
    }

    public function testNewCarIsCreatedFromTheForm(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $r = carsResolveForDeclaration($pdo, $u, ['car_id' => 'new', 'car_number' => ' 42 ', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000']);
        $this->assertTrue($r['ok']);
        $car = db_get_car($pdo, $r['car_id']);
        $this->assertSame('42', $car['car_number']);
        $this->assertSame('Honda', $car['make']);
        $this->assertSame($u, (int)$car['owner_user_id']);
    }

    public function testNewCarNeedsNumberMakeAndModel(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $this->assertSame('Enter the car number for your new car.', carsResolveForDeclaration($pdo, $u, ['car_id' => 'new', 'make' => 'H', 'model' => 'S'])['error']);
        $this->assertFalse(carsResolveForDeclaration($pdo, $u, ['car_id' => 'new', 'car_number' => '12345678901', 'make' => 'H', 'model' => 'S'])['ok']);
        $this->assertFalse(carsResolveForDeclaration($pdo, $u, ['car_id' => 'new', 'car_number' => '4', 'make' => '', 'model' => 'S'])['ok']);
        $this->assertSame('Choose which car this class declaration is for.', carsResolveForDeclaration($pdo, $u, ['car_id' => ''])['error']);
    }

    public function testExistingCarMustBeOwnedAndActiveAndIsUpdated(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $other = $this->user($pdo, 'o@example.com');
        $mine = db_create_car($pdo, $u, ['car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000']);
        $theirs = db_create_car($pdo, $other, ['car_number' => '7', 'make' => 'Mazda', 'model' => 'MX-5']);

        $r = carsResolveForDeclaration($pdo, $u, ['car_id' => (string)$mine, 'year' => '2005', 'make' => 'Honda', 'model' => 'S2000 CR']);
        $this->assertSame($mine, $r['car_id']);
        $this->assertSame('S2000 CR', db_get_car($pdo, $mine)['model']);
        $this->assertSame('2005', db_get_car($pdo, $mine)['year']);

        $this->assertFalse(carsResolveForDeclaration($pdo, $u, ['car_id' => (string)$theirs, 'make' => 'x', 'model' => 'y'])['ok']);
        db_archive_car($pdo, $u, $mine);
        $this->assertFalse(carsResolveForDeclaration($pdo, $u, ['car_id' => (string)$mine, 'make' => 'x', 'model' => 'y'])['ok']);
    }

    public function testLabelsAndPublicShape(): void
    {
        $car = ['id' => 3, 'car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000', 'colour' => 'Silver', 'engine_cc' => null];
        $this->assertSame('#42 2004 Honda S2000', carDisplayName($car));
        $this->assertSame('#42 Honda S2000', carDisplayName(['year' => null] + $car));

        $this->assertSame('With an inspector', declarationReviewLabel('submitted'));
        $this->assertSame('Accepted', declarationReviewLabel('accepted'));
        $this->assertSame('Needs changes', declarationReviewLabel('needs_changes'));
        $this->assertSame('badge-ok', declarationReviewBadgeClass('accepted'));
        $this->assertSame('badge-fail', declarationReviewBadgeClass('needs_changes'));
        $this->assertSame('badge-pending', declarationReviewBadgeClass('submitted'));

        $shape = carsPublicShape($car, ['calculated_class' => 'GT3', 'review_status' => 'submitted']);
        $this->assertSame(['id' => 3, 'car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000',
            'colour' => 'Silver', 'label' => '#42 2004 Honda S2000', 'current_class' => 'GT3', 'review_status' => 'submitted'], $shape);
        $this->assertNull(carsPublicShape($car, null)['current_class']);
    }

    public function testApplySheetDetailsWritesBackToTheCar(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $id = db_create_car($pdo, $u, ['car_number' => '42', 'make' => 'Honda', 'model' => 'S2000']);
        carsApplySheetDetails($pdo, $id, '042', 'Blue', '1998');
        $car = db_get_car($pdo, $id);
        $this->assertSame(['042', '42', 'Blue', '1998'], [$car['car_number'], $car['car_number_norm'], $car['colour'], $car['engine_cc']]);
    }

    public function testNoBannedWording(): void
    {
        $src = file_get_contents(__DIR__ . '/../cars-lib.php') . file_get_contents(__DIR__ . '/../email-copy.php');
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', $src);
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php phpunit.phar --filter CarsLibTest`
Expected: FAIL (file missing).

- [ ] **Step 3: Implement**

`email-copy.php`:

```php
<?php
// wcma-calculator/email-copy.php
//
// Binding competitor-facing copy from the hub spec (docs/superpowers/specs/2026-09-24-wcma-hub-design.md,
// "Competitor emails"). Change wording here only, never inline.

const COPY_DECLARATION_RECEIVED = 'Class declaration received. An inspector will review and respond.';
```

`cars-lib.php`:

```php
<?php
// wcma-calculator/cars-lib.php
//
// Car and class-declaration helpers. Callers must have loaded db.php.

/**
 * The car a class declaration is for. $post['car_id'] is one of the user's active car ids, or
 * 'new' to create one from car_number/year/make/model. An existing car takes the year/make/model
 * the competitor just declared, so the car record matches its newest declaration.
 *
 * @return array{ok: bool, error: ?string, car_id: ?int}
 */
function carsResolveForDeclaration(PDO $pdo, int $userId, array $post): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'car_id' => null];
    $field = trim((string)($post['car_id'] ?? ''));
    $details = [
        'year' => trim((string)($post['year'] ?? '')),
        'make' => trim((string)($post['make'] ?? '')),
        'model' => trim((string)($post['model'] ?? '')),
    ];

    if ($field === '') return $fail('Choose which car this class declaration is for.');

    if ($field !== 'new') {
        $car = ctype_digit($field) ? db_get_user_car($pdo, $userId, (int)$field) : null;
        if ($car === null || $car['archived_at'] !== null) return $fail('Choose one of your cars, or add a new car.');
        db_update_car($pdo, (int)$car['id'], array_filter($details, fn(string $v): bool => $v !== ''));
        return ['ok' => true, 'error' => null, 'car_id' => (int)$car['id']];
    }

    $number = trim((string)($post['car_number'] ?? ''));
    if ($number === '') return $fail('Enter the car number for your new car.');
    if (mb_strlen($number, 'UTF-8') > 10) return $fail('That car number is too long (10 characters at most).');
    if ($details['make'] === '' || $details['model'] === '') return $fail('Enter the make and model of your new car.');

    $id = db_create_car($pdo, $userId, ['car_number' => $number, 'year' => $details['year'] ?: null] + $details);
    return ['ok' => true, 'error' => null, 'car_id' => $id];
}

function carDisplayName(array $car): string {
    return '#' . $car['car_number'] . ' ' . trim(($car['year'] ?? '') . ' ' . $car['make'] . ' ' . $car['model']);
}

function declarationReviewLabel(string $status): string {
    switch ($status) {
        case 'accepted':      return 'Accepted';
        case 'needs_changes': return 'Needs changes';
        case 'superseded':    return 'Replaced by a newer declaration';
        default:              return 'With an inspector';
    }
}

function declarationReviewBadgeClass(string $status): string {
    if ($status === 'accepted') return 'badge-ok';
    if ($status === 'needs_changes') return 'badge-fail';
    return 'badge-pending';
}

/** What the calculator's car picker needs to know about a car. */
function carsPublicShape(array $car, ?array $declaration): array {
    return [
        'id' => (int)$car['id'], 'car_number' => (string)$car['car_number'], 'year' => $car['year'],
        'make' => (string)$car['make'], 'model' => (string)$car['model'], 'colour' => $car['colour'],
        'label' => carDisplayName($car),
        'current_class' => $declaration['calculated_class'] ?? null,
        'review_status' => $declaration['review_status'] ?? null,
    ];
}

/** A submitted tech sheet's car details become the car's details (the sheet itself keeps its snapshot). */
function carsApplySheetDetails(PDO $pdo, int $carId, string $number, string $colour, ?string $engineCc): void {
    db_update_car($pdo, $carId, ['car_number' => $number, 'colour' => $colour, 'engine_cc' => $engineCc]);
}
```

`cars.php`:

```php
<?php
// wcma-calculator/cars.php — JSON: the signed-in competitor's active cars (for the calculator's car picker).
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/cars-lib.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$user = current_user();
if ($user === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not signed in.']);
    exit;
}

$pdo = db_connect();
db_init($pdo);
$current = db_get_user_current_declarations($pdo, (int)$user['id']);
$cars = array_map(
    fn(array $c): array => carsPublicShape($c, $current[(int)$c['id']] ?? null),
    db_get_user_cars($pdo, (int)$user['id'])
);
echo json_encode(['success' => true, 'cars' => $cars]);
```

`car-classing.php`:

1. Add `require __DIR__ . '/cars-lib.php';` and `require __DIR__ . '/email-copy.php';` after the `submission-email-render.php` require.
2. Directly after `header('Content-Type: application/json');`, add:

```php
if ($current_user === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sign in or create a free account to submit your class declaration.']);
    exit;
}
```

3. Directly after the `if (!empty($errors)) { ... exit; }` block and before `// ── Persist to database`, add:

```php
$car = carsResolveForDeclaration($pdo, (int)$current_user['id'], $_POST);
if (!$car['ok']) {
    foreach ($attachments as $attachment) {
        if (file_exists($attachment['path'])) unlink($attachment['path']);
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'errors' => [$car['error']]]);
    exit;
}
```

4. In the `db_insert_submission()` call, replace `':user_id' => $current_user['id'] ?? null,` with:

```php
    ':user_id'                => (int)$current_user['id'],
    ':car_id'                 => $car['car_id'],
```

5. In the success response, change the message to `COPY_DECLARATION_RECEIVED . ' A confirmation has been sent to ' . htmlspecialchars($email) . '.'`.

- [ ] **Step 4: Run the tests**

Run: `php phpunit.phar`. Expected: PASS.
Run: `php -l car-classing.php && php -l cars.php`. Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add cars-lib.php cars.php email-copy.php car-classing.php tests/CarsLibTest.php
git commit -m "feat(hub): class declarations require sign-in and are filed against a car"
```

---

### Task 8: Calculator car picker

**Files:**
- Create: `js/car-picker.js`, `tests/js/car-picker.test.js`
- Modify: `car-classing.html` (two form groups in the contact section, and a script tag)

**Interfaces:**
- Consumes: `cars.php?action=list` (Task 7), `session-status.php`
- Produces: `js/car-picker.js` exports (CommonJS, for node tests):
  - `carIdFromQuery(search: string): string`
  - `pickerOptions(cars: Car[]): {value, label}[]`, which lists the cars, then `{value: 'new', label: 'A new car'}`
  - `initialCarValue(cars: Car[], search: string): string`, which is the `?car=` id if it's one of the user's cars, else `''` when they have cars, else `'new'`

In the browser, it auto-initializes on `DOMContentLoaded`.

This task must not touch `js/ui-controller.js` or `js/calculator.js`. Recalculation stays exactly as it is.

- [ ] **Step 1: Write the failing test**

`tests/js/car-picker.test.js`:

```js
const test = require('node:test');
const assert = require('node:assert');
const { carIdFromQuery, pickerOptions, initialCarValue } = require('../../js/car-picker.js');

const cars = [
    { id: 3, label: '#42 2004 Honda S2000', current_class: 'GT3' },
    { id: 8, label: '#17 1999 Mazda Miata', current_class: null },
];

test('reads the car id from the query string', () => {
    assert.strictEqual(carIdFromQuery('?car=8'), '8');
    assert.strictEqual(carIdFromQuery('?draft=2&car=3'), '3');
    assert.strictEqual(carIdFromQuery('?car=abc'), '');
    assert.strictEqual(carIdFromQuery(''), '');
});

test('lists cars with their class, then a new-car option', () => {
    assert.deepStrictEqual(pickerOptions(cars), [
        { value: '3', label: '#42 2004 Honda S2000 (GT3)' },
        { value: '8', label: '#17 1999 Mazda Miata' },
        { value: 'new', label: 'A new car' },
    ]);
});

test('initial choice: the linked car, else ask, else a new car', () => {
    assert.strictEqual(initialCarValue(cars, '?car=8'), '8');
    assert.strictEqual(initialCarValue(cars, '?car=99'), '');
    assert.strictEqual(initialCarValue(cars, ''), '');
    assert.strictEqual(initialCarValue([], '?car=8'), 'new');
});
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `node --test tests/js/car-picker.test.js`
Expected: FAIL (`Cannot find module '../../js/car-picker.js'`).

- [ ] **Step 3: Implement `js/car-picker.js`**

```js
// wcma-calculator/js/car-picker.js
//
// "Which car is this for?" on the calculator, for signed-in competitors. Every class declaration
// belongs to a car. Classic script (not a module) so it can be unit tested with node --test.
(function () {
    function carIdFromQuery(search) {
        const m = /[?&]car=(\d+)(?:&|$)/.exec(search || '');
        return m ? m[1] : '';
    }

    function pickerOptions(cars) {
        return cars.map(c => ({
            value: String(c.id),
            label: c.label + (c.current_class ? ' (' + c.current_class + ')' : ''),
        })).concat([{ value: 'new', label: 'A new car' }]);
    }

    function initialCarValue(cars, search) {
        if (!cars.length) return 'new';
        const wanted = carIdFromQuery(search);
        return cars.some(c => String(c.id) === wanted) ? wanted : '';
    }

    function applyChoice(doc, cars, value) {
        doc.getElementById('car-number-group').hidden = value !== 'new';
        const car = cars.find(c => String(c.id) === value);
        if (!car) return;
        const fields = { year: car.year, make: car.make, model: car.model };
        Object.keys(fields).forEach(id => {
            const el = doc.getElementById(id);
            if (el && fields[id] != null) el.value = fields[id];
        });
    }

    async function init(doc, win) {
        try {
            const status = await (await fetch('session-status.php', { credentials: 'same-origin' })).json();
            if (!status.loggedIn) return;   // signed out: the server asks them to sign in on submit
            const data = await (await fetch('cars.php?action=list', { credentials: 'same-origin' })).json();
            if (!data.success) return;

            const select = doc.getElementById('car-id');
            select.textContent = '';
            const placeholder = doc.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '-- Choose your car --';
            select.appendChild(placeholder);
            pickerOptions(data.cars).forEach(o => {
                const opt = doc.createElement('option');
                opt.value = o.value;
                opt.textContent = o.label;
                select.appendChild(opt);
            });

            select.value = initialCarValue(data.cars, win.location.search);
            doc.getElementById('car-picker-group').hidden = false;
            applyChoice(doc, data.cars, select.value);
            select.addEventListener('change', () => applyChoice(doc, data.cars, select.value));
        } catch (e) {
            // Leave the picker hidden; the server still validates the car on submit.
        }
    }

    const api = { carIdFromQuery, pickerOptions, initialCarValue };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        window.WcmaCarPicker = api;
        document.addEventListener('DOMContentLoaded', () => init(document, window));
    }
})();
```

- [ ] **Step 4: Add the fields and script to the calculator page**

In `car-classing.html`, inside `<div class="form-grid compact-grid">` of the Contact Information section, insert **before** the Name form group:

```html
                    <div class="form-group" id="car-picker-group" hidden>
                        <label for="car-id">Car <span class="required">*</span></label>
                        <select id="car-id" name="car_id"></select>
                        <p class="field-help">Every class declaration is for one of your cars.</p>
                        <span class="error-message" id="car-id-error"></span>
                    </div>

                    <div class="form-group" id="car-number-group" hidden>
                        <label for="car-number">Car number <span class="required">*</span></label>
                        <input type="text" id="car-number" name="car_number" maxlength="10">
                        <p class="field-help">The number you race with. Numbers are reserved on MotorsportReg.</p>
                        <span class="error-message" id="car-number-error"></span>
                    </div>
```

After `<script src="js/feedback.js"></script>`, add `<script src="js/car-picker.js"></script>`.

- [ ] **Step 5: Run the tests**

Run: `node --test tests/js/`. Expected: all PASS.
Run: `php phpunit.phar`. Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add js/car-picker.js tests/js/car-picker.test.js car-classing.html
git commit -m "feat(hub): calculator asks which car a class declaration is for"
```

---

### Task 9: My Cars lists cars, not declarations

**Files:**
- Modify: `view_helpers.php` (replace `buildCarTechSheetGroups()` with `buildCarGroups()`)
- Modify: `account.php` (list handler and render, delete guard, new `archive-car` action)
- Rewrite: `tests/AccountCarGroupingTest.php`

**Interfaces:**
- Consumes:
  - `db_get_user_cars()`, `db_get_user_current_declarations()`, `db_archive_car()`, `db_count_tech_sheets_for_submission()`
  - `carDisplayName()`, `declarationReviewLabel()`, `declarationReviewBadgeClass()`
- Produces: `buildCarGroups(array $cars, array $currentDeclarations, array $techSheets, array $activeEvents, array $eventNames): array`. It returns a list of `['car' => array, 'declaration' => ?array, 'lines' => array]`, where each line is `{event_id, event_name, event_date, sheet}`.

- [ ] **Step 1: Rewrite the grouping test**

Replace the contents of `tests/AccountCarGroupingTest.php`:

```php
<?php
// wcma-calculator/tests/AccountCarGroupingTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../view_helpers.php';

final class AccountCarGroupingTest extends TestCase
{
    private function car(int $id, string $number = '42'): array {
        return ['id' => $id, 'car_number' => $number, 'year' => '2020', 'make' => 'Mazda', 'model' => 'MX-5'];
    }

    private function sheet(int $id, int $carId, int $eventId): array {
        return ['id' => $id, 'car_id' => $carId, 'event_id' => $eventId, 'status' => 'submitted'];
    }

    private function event(int $id, string $name, string $date = '2026-10-04'): array {
        return ['id' => $id, 'name' => $name, 'event_date' => $date];
    }

    public function testCarWithNoSheetsGetsANotSubmittedLinePerActiveEvent(): void
    {
        $groups = buildCarGroups([$this->car(1)], [], [], [$this->event(10, 'Fall Sprint'), $this->event(11, 'Finale')], []);
        $this->assertCount(1, $groups);
        $this->assertNull($groups[0]['declaration']);
        $this->assertSame(['Fall Sprint', 'Finale'], array_column($groups[0]['lines'], 'event_name'));
        $this->assertNull($groups[0]['lines'][0]['sheet']);
    }

    public function testSheetsAttachToTheirOwnCarAndNewestPerEventWins(): void
    {
        $decl = ['id' => 7, 'car_id' => 1, 'calculated_class' => 'GT3', 'review_status' => 'submitted'];
        $groups = buildCarGroups(
            [$this->car(1), $this->car(2, '7')],
            [1 => $decl],
            [$this->sheet(31, 1, 10), $this->sheet(30, 1, 10), $this->sheet(40, 2, 10)],   // newest first, as db_get_user_tech_sheets returns
            [$this->event(10, 'Fall Sprint')],
            [10 => 'Fall Sprint']
        );
        $this->assertSame($decl, $groups[0]['declaration']);
        $this->assertSame(31, $groups[0]['lines'][0]['sheet']['id']);
        $this->assertSame(40, $groups[1]['lines'][0]['sheet']['id']);
    }

    public function testSheetForAnInactiveEventIsStillShown(): void
    {
        $groups = buildCarGroups([$this->car(1)], [], [$this->sheet(5, 1, 99)], [], [99 => 'Old Event']);
        $this->assertCount(1, $groups[0]['lines']);
        $this->assertSame('Old Event', $groups[0]['lines'][0]['event_name']);
        $this->assertNull($groups[0]['lines'][0]['event_date']);
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php phpunit.phar --filter AccountCarGroupingTest`
Expected: FAIL (`Call to undefined function buildCarGroups()`).

- [ ] **Step 3: Implement `buildCarGroups()`**

In `view_helpers.php`, replace `buildCarTechSheetGroups()` (docblock included) with:

```php
/**
 * My Cars: each of the competitor's cars with its current class declaration and one tech-sheet
 * line per active event (plus any sheet for an event no longer active). Pure data transformation:
 * no DB, no HTML (see tests/AccountCarGroupingTest.php).
 *
 * @param array $cars                Rows from db_get_user_cars()
 * @param array $currentDeclarations car_id => row, from db_get_user_current_declarations()
 * @param array $techSheets          Rows from db_get_user_tech_sheets() (newest first)
 * @param array $activeEvents        Rows from db_get_active_events()
 * @param array $eventNames          [event_id => name] covering inactive events too
 * @return array<int, array{car: array, declaration: ?array, lines: array}>
 */
function buildCarGroups(array $cars, array $currentDeclarations, array $techSheets, array $activeEvents, array $eventNames): array {
    $sheetsByCar = [];
    foreach ($techSheets as $ts) {
        $sheetsByCar[(int)$ts['car_id']][] = $ts;
    }

    $groups = [];
    foreach ($cars as $car) {
        $carId = (int)$car['id'];
        $sheetsByEvent = [];
        foreach ($sheetsByCar[$carId] ?? [] as $ts) {
            $sheetsByEvent[(int)$ts['event_id']] ??= $ts;   // newest sheet for each event
        }

        $lines = [];
        foreach ($activeEvents as $e) {
            $eventId = (int)$e['id'];
            $lines[] = ['event_id' => $eventId, 'event_name' => $e['name'], 'event_date' => $e['event_date'], 'sheet' => $sheetsByEvent[$eventId] ?? null];
            unset($sheetsByEvent[$eventId]);
        }
        foreach ($sheetsByEvent as $eventId => $ts) {
            $lines[] = ['event_id' => $eventId, 'event_name' => $eventNames[$eventId] ?? 'Unknown event', 'event_date' => null, 'sheet' => $ts];
        }

        $groups[] = ['car' => $car, 'declaration' => $currentDeclarations[$carId] ?? null, 'lines' => $lines];
    }
    return $groups;
}
```

- [ ] **Step 4: Rebuild the My Cars list**

In `account.php`:
- Add `require __DIR__ . '/cars-lib.php';` after the `gear-chips.php` require.
- Add a router case:

```php
    case 'archive-car':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: account.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        $ok = db_archive_car($pdo, (int)$user['id'], (int)($_POST['id'] ?? 0));
        setFlash($ok ? 'Car archived. Its history is kept.' : 'Car not found.', $ok ? 'success' : 'error');
        header('Location: account.php');
        exit;
```

In `handleAccountList()`:
- Replace the `$submissions`, `$totalCount` and `$carGroups` lines with:

```php
    $cars = db_get_user_cars($pdo, (int)$user['id']);
    $totalCount = count($cars) + db_count_user_drafts($pdo, $user['id']);
```

- Keep `$techSheets`, `$activeEvents` and `$eventNames`, then add:

```php
    $carGroups = buildCarGroups($cars, db_get_user_current_declarations($pdo, (int)$user['id']), $techSheets, $activeEvents, $eventNames);
```

- Everything below stays (`$carStatuses`, `$gearLinks`, the render call).

In `renderAccountListPage()`, replace everything from `<h2>My Cars</h2>` up to (not including) `<h2 style="margin-top:2rem">Drafts</h2>` with:

```php
  <h2>My Cars</h2>
  <?php if (empty($carGroups)): ?>
  <p class="empty-row">No cars yet. <a href="car-classing.html">Declare your class</a> to add your first car.</p>
  <?php else: ?>
    <?php foreach ($carGroups as $group): $car = $group['car']; $d = $group['declaration']; ?>
    <div class="car-card">
      <div class="car-card-header">
        <span class="car-card-vehicle"><?= h(carDisplayName($car)) ?></span>
        <span class="car-card-class"><?= h($d['calculated_class'] ?? '—') ?></span>
      </div>
      <?php if ($d): ?>
      <p class="car-card-meta">Class declared <?= h(date('M j, Y', strtotime($d['submitted_at']))) ?> ·
        <span class="<?= h(declarationReviewBadgeClass($d['review_status'])) ?>"><?= h(declarationReviewLabel($d['review_status'])) ?></span></p>
      <?php else: ?>
      <p class="car-card-meta">No class declared yet.</p>
      <?php endif; ?>
      <div class="car-card-actions">
        <?php if ($d): ?><a href="account.php?action=view&id=<?= (int)$d['id'] ?>">View declaration</a><?php endif; ?>
        <a href="car-classing.html?car=<?= (int)$car['id'] ?>"><?= $d ? 'Re-declare class' : 'Declare class' ?></a>
        <form method="post" action="account.php?action=archive-car" style="display:inline"
              data-confirm="Archive <?= h(carDisplayName($car)) ?>? It will be hidden, and its history is kept.">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int)$car['id'] ?>">
          <button type="submit" class="link-button">Archive car</button>
        </form>
      </div>
      <?php if (!empty($group['lines'])): ?>
      <ul class="car-card-tech-list">
        <?php foreach ($group['lines'] as $line): $sheet = $line['sheet']; ?>
        <?php $eventLabel = h($line['event_name']) . ($line['event_date'] ? ' (' . h(date('M j', strtotime($line['event_date']))) . ')' : ''); ?>
        <li class="car-card-tech-line">
          <?php if ($sheet === null): ?>
            Tech sheet for <strong><?= $eventLabel ?></strong>: <span class="badge-pending">not submitted</span>
            <?php if ($d): ?> — <a href="tech-sheets.php?action=new&car_id=<?= (int)$car['id'] ?>">Submit now</a><?php else: ?> — declare a class first<?php endif; ?>
          <?php else: ?>
            Tech sheet (<?= h(ucfirst($sheet['sheet_type'])) ?>) for <strong><?= $eventLabel ?></strong>:
            <span class="badge-pending">submitted</span>
            <?php $cs = $carStatuses[(int)$sheet['id']] ?? ['state' => 'none', 'via' => null, 'sheet_id' => null]; ?>
            <span class="<?= h(techCarStatusBadgeClass($cs['state'])) ?>"><?= h(techCarStatusLabel($cs, (int)($sheet['season'] ?? date('Y')))) ?></span> —
            <a href="tech-sheets.php?action=view&id=<?= (int)$sheet['id'] ?>">View</a>
            <?= renderGearChips($gearLinks[(int)$sheet['id']] ?? [], 'owner', ['sheet_season' => (int)($sheet['season'] ?? 0)]) ?>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <p class="car-card-no-events">No upcoming events open for tech sheet submission yet.</p>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

```

(The "Other Tech Sheets" block is gone, because every sheet now has a car.)

**Declaration delete.** In `renderAccountViewPage()`'s Actions card, after the Resend form, add:

```php
    <form method="post" action="account.php?action=delete" style="margin-top:1rem"
          data-confirm="Permanently delete this class declaration and its files?">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
      <button type="submit" class="btn btn-secondary">Delete this declaration</button>
    </form>
```

Add `<script src="js/confirm-modal.js"></script>` before `form-feedback.js` on that page.

In `handleAccountDelete()`, after the not-found check, add:

```php
    if (db_count_tech_sheets_for_submission($pdo, $id) > 0) {
        setFlash('This declaration is on a submitted tech sheet, so it cannot be deleted.', 'error');
        header('Location: account.php?action=view&id=' . $id);
        exit;
    }
```

- [ ] **Step 5: Run the tests**

Run: `php phpunit.phar`. Expected: PASS.
Run: `php -l account.php view_helpers.php`. Expected: no syntax errors.

- [ ] **Step 6: Commit**

```bash
git add view_helpers.php account.php tests/AccountCarGroupingTest.php
git commit -m "feat(hub): My Cars lists each car with its current declaration"
```

---

### Task 10: Tech sheets start from a car

**Files:**
- Modify: `tech-sheets.php` (`new` router case, `handleNew()`, `renderTechSheetForm()`, `handleSubmit()`, `handleUpdate()`)
- Modify: `tests/TechSheetsHandlersTest.php`

**Interfaces:**
- Consumes:
  - `db_get_user_car()`, `db_get_car_current_declaration()`
  - `carsApplySheetDetails()` (Task 7). `db_insert_tech_sheet()` derives `car_id` from the declaration (Task 3).
- Produces:
  - `tech-sheets.php?action=new&car_id={id}`. `submission_id` is no longer accepted.
  - The form posts `car_id`.
  - Submit and update write the number, colour and engine back to the car.

- [ ] **Step 1: Add the failing source-level guards**

Append to `tests/TechSheetsHandlersTest.php`:

```php
    public function testNewSheetsStartFromACarAndItsCurrentDeclaration(): void
    {
        foreach (['handleNew', 'handleSubmit'] as $fn) {
            $body = $this->body($fn);
            $this->assertStringContainsString('db_get_user_car(', $body, $fn);
            $this->assertStringContainsString('db_get_car_current_declaration(', $body, $fn);
            $this->assertStringNotContainsString('submission_id=', $body, $fn);
        }
    }

    public function testSubmitAndUpdateWriteDetailsBackToTheCar(): void
    {
        foreach (['handleSubmit', 'handleUpdate'] as $fn) {
            $this->assertStringContainsString('carsApplySheetDetails(', $this->body($fn), $fn);
        }
    }
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php phpunit.phar --filter TechSheetsHandlersTest`
Expected: FAIL.

- [ ] **Step 3: Implement**

In `tech-sheets.php`:
- Add `require __DIR__ . '/cars-lib.php';` after `gear-chips.php`.
- Change the `new` case to `handleNew($pdo, $user, (int)($_GET['car_id'] ?? 0));`.

Replace `handleNew()`:

```php
function handleNew(PDO $pdo, array $user, int $carId): void {
    $car = db_get_user_car($pdo, (int)$user['id'], $carId);
    if (!$car || $car['archived_at'] !== null) {
        setFlash('Car not found.', 'error');
        header('Location: account.php');
        exit;
    }
    $declaration = db_get_car_current_declaration($pdo, $carId);
    if (!$declaration) {
        setFlash('Declare a class for this car before submitting a tech sheet.', 'error');
        header('Location: car-classing.html?car=' . $carId);
        exit;
    }

    $events = db_get_active_events($pdo);
    if (empty($events)) {
        setFlash('There are no upcoming events open for tech sheet submission yet.', 'error');
        header('Location: account.php');
        exit;
    }

    $gearNames = gearNameSuggestions(db_get_user_gear_records($pdo, (int)$user['id']), gearSeasonNow());
    renderTechSheetForm($declaration, $events, generateCsrfToken(), null, [], $gearNames, $car);
}
```

Change the `renderTechSheetForm()` signature to `renderTechSheetForm(array $submission, array $events, string $csrf, ?array $existingSheet = null, array $existingDrivers = [], array $gearNames = [], ?array $car = null): void`. In its variable block, replace the `$carNumber`, `$carColour`, `$engineCc`, `$carMake` and `$carModel` lines with:

```php
    $carNumber = $isEdit ? $existingSheet['car_number'] : (string)($car['car_number'] ?? '');
    $carColour = $isEdit ? $existingSheet['car_colour'] : (string)($car['colour'] ?? '');
    $engineCc = $isEdit ? $existingSheet['engine_cc'] : (string)($car['engine_cc'] ?? '');
    $carMake = $isEdit ? $existingSheet['car_make'] : (string)($car['make'] ?? $submission['make']);
    $carModel = $isEdit ? $existingSheet['car_model'] : (string)($car['model'] ?? $submission['model']);
```

In the form's hidden inputs, replace `<input type="hidden" name="submission_id" value="<?= (int)$submission['id'] ?>">` with `<input type="hidden" name="car_id" value="<?= (int)($car['id'] ?? 0) ?>">`.

In `handleSubmit()`, replace the opening lookup (`$submissionId = ...` through its `exit; }`) with:

```php
    $carId = (int)($_POST['car_id'] ?? 0);
    $car = db_get_user_car($pdo, (int)$user['id'], $carId);
    $submission = ($car && $car['archived_at'] === null) ? db_get_car_current_declaration($pdo, $carId) : null;
    if (!$submission) {
        setFlash('Car not found, or it has no class declaration yet.', 'error');
        header('Location: account.php');
        exit;
    }
```

Then in `handleSubmit()`:
- Change both `header('Location: tech-sheets.php?action=new&submission_id=' . $submissionId);` lines to `header('Location: tech-sheets.php?action=new&car_id=' . $carId);`.
- In the `db_insert_tech_sheet()` data, use `'car_make' => $car['make'], 'car_model' => $car['model'],`.
- Directly after the insert, add `carsApplySheetDetails($pdo, $carId, $parsed['car_number'], $parsed['car_colour'], $parsed['engine_cc']);`.

In `handleUpdate()`, directly after the `db_update_tech_sheet()` call, add:

```php
    carsApplySheetDetails($pdo, (int)$sheet['car_id'], $parsed['car_number'], $parsed['car_colour'], $parsed['engine_cc']);
```

- [ ] **Step 4: Run the tests**

Run: `php phpunit.phar`. Expected: PASS.
Run: `php -l tech-sheets.php`. Expected: no syntax errors.

- [ ] **Step 5: Commit**

```bash
git add tech-sheets.php tests/TechSheetsHandlersTest.php
git commit -m "feat(hub): tech sheets start from a car and keep its details current"
```

---

### Task 11: Competitor email copy and in-person acceptance emails

**Files:**
- Modify:
  - `email-copy.php`
  - `tech-sheet-data.php:92` (delete `TECH_ACCEPTANCE_DISCLAIMER`)
  - `tech-sheet-render.php:115-118`
  - `admin-tech-sheets.php` (`:189` hint, `handleTechSheetAccept()`)
  - `admin-gear.php` (`handleGearAdminAcceptInPerson()`, `handleGearCreateAccept()`)
  - `pretech-email.php`, `gear-email.php`
  - `submission-email-render.php` (`renderSubmissionEmailHtml`/`Text`)
  - `car-classing.php` (confirmation email to the competitor)
  - `tech-sheets.php` (`sendTechSheetConfirmationEmail()`)
- Modify tests: `tests/PretechEmailTest.php`, `tests/GearEmailTest.php`, `tests/AdminTechCopyTest.php`, `tests/SubmissionEmailRenderTest.php`
- Create: `tests/EmailCopyTest.php`

**Interfaces:**
- Produces:
  - `email-copy.php`:
    - `COPY_TECH_SHEET_RECEIVED`, `COPY_TECH_SHEET_ACCEPTED`, `COPY_GEAR_ACCEPTED`
    - `reviewedByLine(?array $reviewer): string`, which returns `'Reviewed by: {name}'`, or `''` when there is no reviewer or name.
    - `techSheetReceivedEmailHtml(string $sheetHtml): string`
  - `pretechEmailAccepted(array $sheet, array $event, string $viewUrl, string $adminUrl, bool $forClub, ?array $reviewer = null, string $via = 'photos'): array`
  - `gearEmailAccepted(array $gear, string $pageUrl, string $adminUrl, bool $forClub, ?array $reviewer = null, string $via = 'photos'): array`
  - `pretechNotify()` and `gearNotify()` accept the new kind `'accepted_in_person'`.
  - Both notifiers load the reviewer from `reviewed_by_user_id` for the accepted kinds.
  - `renderSubmissionEmailHtml(array $s, ?string $logoSrc = null, bool $isResend = false, ?string $headline = null): string`, with the same new `$headline` parameter on `renderSubmissionEmailText`.

- [ ] **Step 1: Write the failing tests**

`tests/EmailCopyTest.php`:

```php
<?php
// wcma-calculator/tests/EmailCopyTest.php
require_once __DIR__ . '/../email-copy.php';
require_once __DIR__ . '/../view_helpers.php';   // h()

use PHPUnit\Framework\TestCase;

final class EmailCopyTest extends TestCase
{
    public function testBindingCopy(): void
    {
        $this->assertSame('Class declaration received. An inspector will review and respond.', COPY_DECLARATION_RECEIVED);
        $this->assertSame('Tech sheet received. An inspector will review and respond.', COPY_TECH_SHEET_RECEIVED);
        $this->assertSame('The scrutineer has reviewed & accepted your tech sheet.', COPY_TECH_SHEET_ACCEPTED);
        $this->assertSame('The scrutineer has reviewed & accepted your gear.', COPY_GEAR_ACCEPTED);
    }

    public function testReviewedByLine(): void
    {
        $this->assertSame('Reviewed by: Ivy Inspector', reviewedByLine(['name' => '  Ivy   Inspector ']));
        $this->assertSame('', reviewedByLine(null));
        $this->assertSame('', reviewedByLine(['name' => ' ']));
    }

    public function testReceivedHeadlineLeadsTheSheet(): void
    {
        $html = techSheetReceivedEmailHtml('<div>SHEET</div>');
        $this->assertStringContainsString(h(COPY_TECH_SHEET_RECEIVED), $html);
        $this->assertLessThan(strpos($html, 'SHEET'), strpos($html, 'Tech sheet received'));
    }

    public function testDisclaimerIsGoneEverywhere(): void
    {
        foreach (glob(__DIR__ . '/../*.php') as $file) {
            $this->assertStringNotContainsString('TECH_ACCEPTANCE_DISCLAIMER', file_get_contents($file), basename($file));
            $this->assertStringNotContainsString('is not a certification', file_get_contents($file), basename($file));
        }
    }
}
```

In `tests/PretechEmailTest.php`, replace `testAcceptedEmailHasDisclaimerAndDecalsInstruction` with:

```php
    public function testAcceptedEmailLeadsWithTheScrutineerLineAndNamesTheReviewer(): void
    {
        $admin = 'https://x.test/admin.php?action=tech-sheet&id=12';
        $view = 'https://x.test/tech-sheets.php?action=view&id=12';
        $ivy = ['name' => 'Ivy Inspector'];

        $mail = pretechEmailAccepted($this->sheet(), $this->event(), $view, $admin, false, $ivy);
        $this->assertStringStartsWith(COPY_TECH_SHEET_ACCEPTED, $mail['text']);
        $this->assertStringContainsString(h(COPY_TECH_SHEET_ACCEPTED), $mail['html']);
        $this->assertStringContainsString('Reviewed by: Ivy Inspector', $mail['text']);
        $this->assertStringContainsString('Reviewed by: Ivy Inspector', $mail['html']);
        $this->assertStringContainsString('decals', $mail['text']);
        $this->assertStringContainsString('2026', $mail['text']);
        $this->assertStringNotContainsString('admin.php', $mail['text'] . $mail['html']);

        $club = pretechEmailAccepted($this->sheet(), $this->event(), $view, $admin, true, $ivy);
        $this->assertStringContainsString('Reviewed by: Ivy Inspector', $club['text']);
        $this->assertStringContainsString($admin, $club['text']);
        $this->assertStringContainsString('No in-person inspection is needed', $club['text']);

        $inPerson = pretechEmailAccepted($this->sheet(), $this->event(), $view, $admin, false, $ivy, 'in_person');
        $this->assertStringStartsWith(COPY_TECH_SHEET_ACCEPTED, $inPerson['text']);
        $this->assertStringContainsString('inspected in person', $inPerson['text']);
        $this->assertStringNotContainsString('photos', $inPerson['text']);
        $this->assertStringContainsString('Tech Sheet Accepted', $inPerson['subject']);
    }
```

In the same file:
- In the test at the old line ~90 that strips the disclaimer (`str_replace(TECH_ACCEPTANCE_DISCLAIMER, '', ...)`), remove that `str_replace` and use the raw text.
- Change any `pretechEmailAccepted(...)` call there to pass `['name' => 'Ivy Inspector']` as the sixth argument.

In `tests/GearEmailTest.php`, make the same kind of change:
- Replace the `TECH_ACCEPTANCE_DISCLAIMER` assertions (around lines 54–55) with:

```php
            $this->assertStringContainsString('Reviewed by: Ivy Inspector', $mail['text']);
            $this->assertStringContainsString('Reviewed by: Ivy Inspector', $mail['html']);
```

- Add `['name' => 'Ivy Inspector']` as the fifth argument of the `gearEmailAccepted()` calls in that test.
- Add `$this->assertStringStartsWith(COPY_GEAR_ACCEPTED, $mail['text']);` for the competitor (`forClub = false`) mail.
- Remove the `str_replace(TECH_ACCEPTANCE_DISCLAIMER, '', ...)` at around line 73.

In `tests/AdminTechCopyTest.php`, replace the first test with:

```php
    public function testAdminTechPageHasNoCertificationOrSafetyWording(): void {
        $src = file_get_contents(__DIR__ . '/../admin-tech-sheets.php');
        $this->assertStringNotContainsString('is not a certification', $src);
        $this->assertStringNotContainsString('TECH_ACCEPTANCE_DISCLAIMER', $src);
        $this->assertDoesNotMatchRegularExpression('/\bsafe\b/i', $src);
    }
```

In `tests/SubmissionEmailRenderTest.php`, add:

```php
    public function testOptionalHeadlineLeadsTheEmail(): void
    {
        $html = renderSubmissionEmailHtml($this->sample(), null, false, 'Class declaration received. An inspector will review and respond.');
        $this->assertStringContainsString('Class declaration received. An inspector will review and respond.', $html);
        $this->assertLessThan(strpos($html, 'CLASS DECLARATION</h1>'), strpos($html, 'Class declaration received'));
        $this->assertStringStartsWith('Class declaration received.', renderSubmissionEmailText($this->sample(), false, 'Class declaration received. An inspector will review and respond.'));
        $this->assertStringNotContainsString('received', renderSubmissionEmailHtml($this->sample()));
    }
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php phpunit.phar --filter "EmailCopyTest|PretechEmailTest|GearEmailTest|AdminTechCopyTest|SubmissionEmailRenderTest"`
Expected: FAIL.

- [ ] **Step 3: Implement the copy module**

Append to `email-copy.php`:

```php
const COPY_TECH_SHEET_RECEIVED = 'Tech sheet received. An inspector will review and respond.';
const COPY_TECH_SHEET_ACCEPTED = 'The scrutineer has reviewed & accepted your tech sheet.';
const COPY_GEAR_ACCEPTED = 'The scrutineer has reviewed & accepted your gear.';

/** "Reviewed by: First Last" for review emails, or '' when the reviewer is unknown. */
function reviewedByLine(?array $reviewer): string {
    $name = trim((string)preg_replace('/\s+/', ' ', (string)($reviewer['name'] ?? '')));
    return $name === '' ? '' : 'Reviewed by: ' . $name;
}

/** The tech sheet confirmation email body: the "received" headline, then the rendered sheet. */
function techSheetReceivedEmailHtml(string $sheetHtml): string {
    return '<html><body>'
        . '<p style="font-family:Arial,sans-serif;font-size:1.1rem;font-weight:bold">' . htmlspecialchars(COPY_TECH_SHEET_RECEIVED, ENT_QUOTES, 'UTF-8') . '</p>'
        . $sheetHtml . '</body></html>';
}
```

- [ ] **Step 4: Remove the disclaimer and apply the new copy**

- `tech-sheet-data.php`: delete the `TECH_ACCEPTANCE_DISCLAIMER` constant (line 92).
- `tech-sheet-render.php`: delete the `if ($reviewed) { $out .= ... TECH_ACCEPTANCE_DISCLAIMER ... }` block (lines ~116–118).
- `admin-tech-sheets.php:189`: change the hint to `<p class="form-hint">Accepting records that what the competitor submitted matches the car in front of you.</p>`.
- `pretech-email.php`:
  - Change the header `require_once __DIR__ . '/tech-sheet-data.php';` line to `require_once __DIR__ . '/email-copy.php';`.
  - Replace `pretechEmailAccepted()`:

```php
/** @return array{subject: string, html: string, text: string} */
function pretechEmailAccepted(array $sheet, array $event, string $viewUrl, string $adminUrl, bool $forClub, ?array $reviewer = null, string $via = 'photos'): array {
    $car = pretechEmailCarLine($sheet, $event);
    $season = (int)($sheet['season'] ?? date('Y'));
    $byLine = reviewedByLine($reviewer);
    $inPerson = $via === 'in_person';
    $what = $inPerson
        ? $car . ' was inspected in person and is teched for ' . $season . '.'
        : 'The pre-tech photos for ' . $car . ' were reviewed and accepted. This car is pre-teched for ' . $season . '.';

    if ($forClub) {
        $note = $inPerson ? 'The competitor collects their decals at the event.' : 'No in-person inspection is needed; the competitor will collect their decals at the event.';
        $lines = array_values(array_filter([$what . ' ' . $note, $byLine, 'Open the review page:', $adminUrl]));
        $link = pretechEmailLink($adminUrl, 'Open the review page');
        $body = pretechEmailPara($what . ' ' . $note);
    } else {
        $note = $inPerson ? 'Collect your decals at the event.' : 'You do not need to be inspected at the track: just collect your decals at the event.';
        $lines = array_values(array_filter([COPY_TECH_SHEET_ACCEPTED, $byLine, $what, $note, 'Your tech sheet:', $viewUrl]));
        $link = pretechEmailLink($viewUrl, 'View your tech sheet');
        $body = pretechEmailPara(COPY_TECH_SHEET_ACCEPTED) . pretechEmailPara($what) . pretechEmailPara($note);
    }
    if ($byLine !== '') $body .= pretechEmailPara($byLine);

    return [
        'subject' => ($inPerson ? 'WCMA Tech Sheet Accepted' : 'WCMA Pre-Tech Accepted') . ' — Car #' . $sheet['car_number'] . ' — ' . ($event['name'] ?? ''),
        'html' => pretechEmailWrap($inPerson ? 'TECH SHEET ACCEPTED' : 'PRE-TECH ACCEPTED', $body . $link),
        'text' => implode("\n\n", $lines) . "\n",
    ];
}
```

  - In `pretechNotify()`, replace the `case 'accepted':` block with:

```php
            case 'accepted':
            case 'accepted_in_person':
                $reviewer = !empty($sheet['reviewed_by_user_id']) ? db_find_user_by_id($pdo, (int)$sheet['reviewed_by_user_id']) : null;
                $via = $kind === 'accepted_in_person' ? 'in_person' : 'photos';
                if ($competitor) $messages[] = [$competitor, pretechEmailAccepted($sheet, $event, $viewUrl, $adminUrl, false, $reviewer, $via)];
                $messages[] = [$clubTo, pretechEmailAccepted($sheet, $event, $viewUrl, $adminUrl, true, $reviewer, $via)];
                break;
```

- `gear-email.php`: make the same changes.
  - Make sure `email-copy.php` is loaded: add `require_once __DIR__ . '/email-copy.php';` at the top.
  - Replace `gearEmailAccepted()`:

```php
/** @return array{subject: string, html: string, text: string} */
function gearEmailAccepted(array $gear, string $pageUrl, string $adminUrl, bool $forClub, ?array $reviewer = null, string $via = 'photos'): array {
    $driver = gearEmailDriverLine($gear);
    $season = (int)($gear['season'] ?? date('Y'));
    $byLine = reviewedByLine($reviewer);
    $what = $via === 'in_person'
        ? $driver . '\'s gear was checked in person and is teched for ' . $season . '.'
        : 'The gear pre-tech photos for ' . $driver . ' were reviewed and accepted. This driver\'s gear is pre-teched for ' . $season . '.';

    if ($forClub) {
        $lines = array_values(array_filter([$what, 'No gear check is needed at the track.', $byLine, 'Review page:', $adminUrl]));
        $body = pretechEmailPara($what) . pretechEmailPara('No gear check is needed at the track.');
        $link = pretechEmailLink($adminUrl, 'Open the review page');
    } else {
        $note = 'You do not need your gear checked at the track: just collect your decals at the event.';
        $lines = array_values(array_filter([COPY_GEAR_ACCEPTED, $byLine, $what, $note, 'Your gear page:', $pageUrl]));
        $body = pretechEmailPara(COPY_GEAR_ACCEPTED) . pretechEmailPara($what) . pretechEmailPara($note);
        $link = pretechEmailLink($pageUrl, 'View your gear page');
    }
    if ($byLine !== '') $body .= pretechEmailPara($byLine);

    return [
        'subject' => 'WCMA Gear Accepted — ' . $driver,
        'html' => pretechEmailWrap('GEAR ACCEPTED', $body . $link),
        'text' => implode("\n\n", $lines) . "\n",
    ];
}
```

  - In `gearNotify()`, change `case 'accepted':` the same way as `pretechNotify`: accept `'accepted_in_person'`, load the reviewer from `$gear['reviewed_by_user_id']`, and pass `$reviewer, $via` to both `gearEmailAccepted()` calls.
  - If an existing `GearEmailTest` assertion checks the old subject `'Gear Pre-Tech Accepted'`, update it to `'Gear Accepted'`.
- `submission-email-render.php`:
  - Add a `?string $headline = null` parameter to both render functions.
  - In `renderSubmissionEmailHtml()`, directly after `$out = '<div style=...>';`, add `if ($headline !== null) $out .= '<p style="font-size:1.1rem;font-weight:bold">' . h($headline) . '</p>';`.
  - In `renderSubmissionEmailText()`, start `$t` with `($headline !== null ? $headline . "\n\n" : '') .`.
- `car-classing.php`: build the **competitor's** email (`$mail2`) with the headline:
  - `$mail2->Body = renderSubmissionEmailHtml($submission_for_email, emailLogoSrc($mail2), false, COPY_DECLARATION_RECEIVED);`
  - `$mail2->AltBody = renderSubmissionEmailText($submission_for_email, false, COPY_DECLARATION_RECEIVED);`
  - The club email stays without a headline.
- `tech-sheets.php`:
  - Add `require __DIR__ . '/email-copy.php';`.
  - In `sendTechSheetConfirmationEmail()`, replace the `$bodyHtml = '<html><body>' . renderTechSheetHtml(...) . '</body></html>';` line with `$bodyHtml = techSheetReceivedEmailHtml(renderTechSheetHtml($sheet, $drivers, $event, techSheetSignatureResolverEmail($mail), emailLogoSrc($mail)));`.
  - Prefix the `AltBody` with `COPY_TECH_SHEET_RECEIVED . "\n\n" .`.
- `admin-tech-sheets.php` `handleTechSheetAccept()`: after a successful accept, send the in-person email. Use the same base-URL expression and club array that `handleTechSheetPhotosAccept()` in this file passes to `pretechNotify()` (around line 222):

```php
    if ($result['ok']) {
        $sheet = db_get_tech_sheet($pdo, $id);
        $sent = pretechNotify($pdo, 'accepted_in_person', $sheet, db_get_event($pdo, (int)$sheet['event_id']) ?? [],
            /* same base URL expression as handleTechSheetPhotosAccept() */ feedbackBaseUrl($_SERVER, (string)config_default('SITE_BASE_URL', '')),
            ['email' => TECH_EMAIL, 'name' => TECH_NAME], 'emailSmtpSend');
        setFlash('Sheet accepted (teched in person).' . ($sent ? ' The competitor was emailed.' : ' The email could not be sent.'), $sent ? 'success' : 'error');
    } else {
        setFlash($result['error'], 'error');
    }
```

  If `handleTechSheetPhotosAccept()` builds its base URL differently, use its expression verbatim.
- `admin-gear.php`:
  - In `handleGearAdminAcceptInPerson()`, after a successful accept, call `gearNotify($pdo, 'accepted_in_person', db_get_gear_record($pdo, $id), gearAdminBaseUrl(), ['email' => TECH_EMAIL, 'name' => TECH_NAME], 'emailSmtpSend');`, and add the same "emailed / could not be sent" wording to its flash.
  - Do the same in `handleGearCreateAccept()`, using the record id returned by `gearCreateAndAcceptInPerson()`.

- [ ] **Step 5: Run the tests**

Run: `php phpunit.phar`. Expected: PASS.
Run: `grep -rn "TECH_ACCEPTANCE_DISCLAIMER\|is not a certification" --include=*.php .`. Expected: no output.

- [ ] **Step 6: Commit**

```bash
git add -A email-copy.php tech-sheet-data.php tech-sheet-render.php admin-tech-sheets.php admin-gear.php pretech-email.php gear-email.php submission-email-render.php car-classing.php tech-sheets.php tests/
git commit -m "feat(hub): new competitor email copy, reviewer names and in-person acceptance emails"
```

---

### Task 12: Database reset and seed tools

**Files:**
- Create: `hub-db-tools.php`, `reset-hub-db.php`, `seed-hub-db.php`, `tests/HubDbToolsTest.php`
- Modify: `.htaccess` (deny web access to the tools)

**Interfaces:**
- Consumes: DB functions from Tasks 1–6, `gearCreate()`
- Produces:
  - `hubResetDatabase(string $dbPath, string $uploadsDir): void`. It deletes the SQLite file, its `-wal` and `-shm` files, and everything under `$uploadsDir` except its top-level `.htaccess`.
  - `hubSeed(PDO $pdo, string $password): array`, which returns a summary `[label => count]`. It refuses (throws `RuntimeException`) when any user exists.

- [ ] **Step 1: Write the failing test**

`tests/HubDbToolsTest.php`:

```php
<?php
// wcma-calculator/tests/HubDbToolsTest.php
require_once __DIR__ . '/../hub-db-tools.php';

use PHPUnit\Framework\TestCase;

final class HubDbToolsTest extends TestCase
{
    public function testResetRemovesDatabaseAndUploadsButKeepsUploadsHtaccess(): void
    {
        $dir = sys_get_temp_dir() . '/hub_reset_' . uniqid();
        mkdir($dir . '/uploads/12', 0777, true);
        file_put_contents($dir . '/db.sqlite', 'x');
        file_put_contents($dir . '/db.sqlite-wal', 'x');
        file_put_contents($dir . '/uploads/.htaccess', 'Deny from all');
        file_put_contents($dir . '/uploads/12/dyno.pdf', 'x');

        hubResetDatabase($dir . '/db.sqlite', $dir . '/uploads');

        $this->assertFileDoesNotExist($dir . '/db.sqlite');
        $this->assertFileDoesNotExist($dir . '/db.sqlite-wal');
        $this->assertFileDoesNotExist($dir . '/uploads/12');
        $this->assertFileExists($dir . '/uploads/.htaccess');
    }

    public function testSeedCreatesAUsableHub(): void
    {
        $pdo = make_temp_pdo();
        $summary = hubSeed($pdo, 'password123');

        $this->assertSame(3, $summary['users']);
        $admin = db_find_user_by_email($pdo, BOOTSTRAP_ADMIN_EMAIL);
        $this->assertSame('admin', $admin['role']);
        $inspector = db_find_user_by_email($pdo, 'inspector@example.com');
        $this->assertSame('inspector', $inspector['role']);
        $this->assertTrue(userHasFirstAndLastName($inspector['name']));
        $this->assertTrue(password_verify('password123', $inspector['password_hash']));

        $jordan = db_find_user_by_email($pdo, 'jordan@example.com');
        $cars = db_get_user_cars($pdo, (int)$jordan['id']);
        $this->assertCount(2, $cars);
        $this->assertNotNull(db_get_car_current_declaration($pdo, (int)$cars[0]['id']));
        $this->assertCount(2, db_get_user_drivers($pdo, (int)$jordan['id']));
        $this->assertCount(2, db_get_active_events($pdo));
        $this->assertCount(3, db_get_season_links($pdo, true));
        $this->assertCount(1, db_get_user_tech_sheets($pdo, (int)$jordan['id']));
    }

    public function testSeedRefusesANonEmptyDatabase(): void
    {
        $pdo = make_temp_pdo();
        db_create_user($pdo, ['email' => 'x@example.com', 'name' => 'X Y', 'password_hash' => 'x', 'google_id' => null]);
        $this->expectException(RuntimeException::class);
        hubSeed($pdo, 'password123');
    }

    public function testResetScriptRefusesWithoutConfirm(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../reset-hub-db.php') . ' 2>&1', $out, $code);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('--confirm', implode("\n", $out));
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php phpunit.phar --filter HubDbToolsTest`
Expected: FAIL (file missing).

- [ ] **Step 3: Implement**

`hub-db-tools.php`:

```php
<?php
// wcma-calculator/hub-db-tools.php
//
// The app is not live, so the hub model ships with a reset instead of a data migration.
// hubSeed() fills an empty database with realistic test data for local runs and e2e harnesses.
// Callers must have loaded db.php and gear-lib.php (or let this file load them).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/gear-lib.php';

function hubResetDatabase(string $dbPath, string $uploadsDir): void {
    foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) {
        if (is_file($f)) unlink($f);
    }
    if (!is_dir($uploadsDir)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadsDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    $keep = realpath($uploadsDir . '/.htaccess');
    foreach ($items as $item) {
        if ($item->getRealPath() === $keep) continue;
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
}

/** @return array<string, int> */
function hubSeed(PDO $pdo, string $password): array {
    if ((int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0) {
        throw new RuntimeException('The database already has users. Reset it first.');
    }
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $user = fn(string $email, string $name): int => db_create_user($pdo, ['email' => $email, 'name' => $name, 'password_hash' => $hash, 'google_id' => null]);

    $admin = $user(BOOTSTRAP_ADMIN_EMAIL, 'Site Admin');           // bootstrap email is made admin on creation
    $inspector = $user('inspector@example.com', 'Ivy Inspector');
    db_set_user_role($pdo, $inspector, 'inspector');
    $jordan = $user('jordan@example.com', 'Jordan Lee');

    $year = (int)date('Y');
    $fall = db_create_event($pdo, 'Fall Sprint', date('Y-m-d', strtotime('+17 days')), 'Castrol Raceway');
    db_create_event($pdo, 'Season Finale', date('Y-m-d', strtotime('+31 days')), 'Castrol Raceway');

    $s2000 = db_create_car($pdo, $jordan, ['car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000', 'colour' => 'Silver', 'engine_cc' => '1997']);
    $miata = db_create_car($pdo, $jordan, ['car_number' => '17', 'year' => '1999', 'make' => 'Mazda', 'model' => 'Miata', 'colour' => 'Red', 'engine_cc' => '1839']);
    $declare = function (int $carId, string $make, string $model, string $carYear, int $weight, int $hp, string $class) use ($pdo, $jordan): int {
        return db_insert_submission($pdo, [
            ':submitted_at' => date('Y-m-d H:i:s'), ':name' => 'Jordan Lee', ':email' => 'jordan@example.com',
            ':year' => $carYear, ':make' => $make, ':model' => $model, ':comments' => null,
            ':competition_weight' => $weight, ':declared_hp' => $hp, ':dyno_hp' => null,
            ':chassis_display' => null, ':body_mods_display' => null, ':transmission_display' => null,
            ':drivetrain_display' => null, ':tires_display' => null, ':brake_suspension' => '[]',
            ':chassis_value' => 0, ':body_mods_value' => 0, ':transmission_value' => 0,
            ':drivetrain_value' => 0, ':tires_value' => 0, ':brake_suspension_value' => 0,
            ':weight_factor' => 0, ':modification_factor' => 0, ':base_ratio' => round($weight / $hp, 2),
            ':modified_ratio' => round($weight / $hp, 2), ':calculated_class' => $class,
            ':user_id' => $jordan, ':car_id' => $carId,
        ]);
    };
    $declare($s2000, 'Honda', 'S2000', '2004', 2860, 240, 'GT3');
    $miataDecl = $declare($miata, 'Mazda', 'Miata', '1999', 2400, 140, 'IT1');

    gearCreate($pdo, $jordan, 'Jordan Lee', 'WCMA-0412', $year);   // the self profile
    db_create_driver($pdo, $jordan, 'Sam Patel');

    $checklist = [];
    foreach (TECH_CHECKLIST_SECTIONS as $section) {
        foreach ($section['items'] as $item) $checklist[$item['key']] = true;
    }
    $equipment = [];
    foreach (TECH_DRIVER_EQUIPMENT_ITEMS as $item) $equipment[$item['key']] = true;
    db_insert_tech_sheet($pdo, [
        'submission_id' => $miataDecl, 'user_id' => $jordan, 'event_id' => $fall, 'sheet_type' => 'standard',
        'entrant_name' => 'Jordan Lee', 'driver_name' => 'Jordan Lee', 'car_make' => 'Mazda', 'car_model' => 'Miata',
        'car_colour' => 'Red', 'car_number' => '17', 'class' => 'IT1', 'engine_cc' => '1839', 'engine_hp' => '140',
        'car_weight' => 2400, 'checklist_json' => json_encode($checklist), 'driver1_equipment_json' => json_encode($equipment),
        'log_book_turned_in' => 1,
    ]);

    db_create_season_link($pdo, $year . ' Annual Waiver / Hardcard', 'https://www.motorsportreg.com/orgs/western-canada-motorsport-associati', 1);
    db_create_season_link($pdo, $year . ' Race Licences', 'https://www.motorsportreg.com/orgs/western-canada-motorsport-associati', 2);
    db_create_season_link($pdo, 'Car Classing & Number Reservation', 'https://www.motorsportreg.com/orgs/western-canada-motorsport-associati', 3);

    return ['users' => 3, 'cars' => 2, 'events' => 2, 'tech_sheets' => 1, 'season_links' => 3];
}
```

Before writing the checklist loops, check the real shape of `TECH_CHECKLIST_SECTIONS` and `TECH_DRIVER_EQUIPMENT_ITEMS` in `tech-sheet-data.php`, and check the keys `validateChecklist()` expects. Then build `$checklist`/`$equipment` in exactly that shape; the loops above assume `['items' => [['key' => ...]]]` and `[['key' => ...]]`. Also add `require_once __DIR__ . '/tech-sheet-data.php';` to the top of `hub-db-tools.php`. The seed links point at the WCMA MotorsportReg org page, because each season's item URLs change; admins replace them on the Season Links page.

`reset-hub-db.php`:

```php
<?php
// wcma-calculator/reset-hub-db.php — CLI only. Deletes the database and uploaded files.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (!in_array('--confirm', $argv, true)) {
    fwrite(STDERR, "This permanently deletes the database and every uploaded file.\nRun again with --confirm to proceed.\n");
    exit(1);
}
require __DIR__ . '/hub-db-tools.php';
hubResetDatabase(DB_PATH, __DIR__ . '/uploads');
$pdo = db_connect();
db_init($pdo);
echo "Database reset. The account using " . BOOTSTRAP_ADMIN_EMAIL . " becomes admin when it registers.\n";
```

`seed-hub-db.php`:

```php
<?php
// wcma-calculator/seed-hub-db.php — CLI only. Fills an EMPTY database with test data. Not for production.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$password = 'password123';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--password=')) $password = substr($arg, strlen('--password='));
}
require __DIR__ . '/hub-db-tools.php';
$pdo = db_connect();
db_init($pdo);
try {
    $summary = hubSeed($pdo, $password);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
foreach ($summary as $label => $count) echo str_pad($label, 14) . $count . "\n";
echo "Accounts: " . BOOTSTRAP_ADMIN_EMAIL . " (admin), inspector@example.com, jordan@example.com. Password: {$password}\n";
```

In `.htaccess`, extend the `FilesMatch` list: `^(autopull\.sh|git-autopull\.log|CLAUDE\.md|README\.md|phpunit\.xml|reset-hub-db\.php|seed-hub-db\.php|hub-db-tools\.php)$`.

- [ ] **Step 4: Run the tests**

Run: `php phpunit.phar`. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add hub-db-tools.php reset-hub-db.php seed-hub-db.php .htaccess tests/HubDbToolsTest.php
git commit -m "feat(hub): database reset and seed tools"
```

---

### Task 13: End-to-end verification

**Files:** none changed unless a defect is found. If you fix one, add a test for it and commit it separately.

- [ ] **Step 1: Full automated suites**

Run: `php phpunit.phar`. Expected: `OK`, with 0 failures.
Run: `node --test tests/js/`. Expected: all pass.
Run: `for f in *.php; do php -l "$f" >/dev/null || echo "LINT FAIL $f"; done`. Expected: no output.

- [ ] **Step 2: Reset, seed and serve**

```bash
php reset-hub-db.php --confirm
php seed-hub-db.php
php -S localhost:8080
```

- [ ] **Step 3: Manual smoke (record each result)**

1. **Signed out.** Open `http://localhost:8080/car-classing.html` and fill in weight and HP. The ratio and class update live as each value changes (no regression), and there's no Car field. Submit: the message says "Sign in or create a free account to submit your class declaration."
2. **Sign in as jordan@example.com / password123.**
   - The calculator shows a Car field with "#42 2004 Honda S2000 (GT3)", "#17 1999 Mazda Miata (IT1)" and "A new car".
   - `car-classing.html?car=<S2000 id>` preselects the S2000 and fills year/make/model.
   - Submit: the message begins "Class declaration received. An inspector will review and respond."
3. **My Cars** (`account.php`):
   - There are two car cards. The S2000's class shows "With an inspector".
   - The Miata's Fall Sprint line shows "submitted" with a View link.
   - The S2000's Fall Sprint line has "Submit now" linking to `tech-sheets.php?action=new&car_id=…`.
4. **Submit a tech sheet** for the S2000 and change its colour to "Blue".
   - My Cars shows the sheet.
   - Choosing "A new car" in the calculator's picker and switching back fills in the stored year/make/model.
   - The car record's colour (check with `sqlite3 data/submissions.db "select colour from cars"`, or the next sheet form's prefill) is "Blue".
5. **Archive the Miata.** It disappears from My Cars and the calculator picker.
6. **Sign in as inspector@example.com.**
   - The nav shows "Inspector".
   - `admin.php` shows only Submissions, Tech Sheets and Gear tabs.
   - `admin.php?action=users` redirects back to `admin.php` with "You are not authorized to view that page."
   - The declaration detail page shows no Delete or Edit contact controls.
7. **As the inspector, accept a tech sheet in person.** The flash says the competitor was emailed, or that the email could not be sent (expected locally without SMTP). There are no PHP errors in the server log.
8. **Sign in as the admin** (bootstrap email / password123).
   - All tabs are visible, including Season Links.
   - Add a link with `javascript:alert(1)`: it's rejected. Add one with an `https://` address: it's accepted.
   - On Manage Users, set Jordan's role to Inspector: this works, because the name has two words. Rename Jordan to "Jordan", then set Inspector again: it's refused with the first-and-last-name message.
9. **Signed out,** open `http://localhost:8080/seed-hub-db.php`: you get a 403 (or the `.htaccess` denial on Apache).

- [ ] **Step 4: Report**

Write the smoke results (pass/fail per item, with any error text) in the PR description or hand-off message. Do not claim Phase 1 complete while any item fails.
