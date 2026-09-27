# Driver Media Profiles Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Drivers can add a media profile (a photo, a blurb, optional facts and sponsors) with recorded consent. Media staff get an Announcer sheet, a Media kit and a Public review queue. Accepted profiles with public consent get a public page at `driver.php?id=N`.

**Architecture:**
- Plain PHP + SQLite, as in the hub phases.
- **Pure rules** live in `media-lib.php`:
  - consent rules;
  - field validation;
  - public status changes;
  - where a profile may be used;
  - which drivers the roster shows for a car;
  - the text and CSV output for the media kit.
- **DB work** is in the `db.php` helpers and in `media-service.php`: save, withdraw and delete a profile, the review actions, and building the roster and media kit.
- **Markup** lives in two renderers:
  - `media-profile-page.php`, for the driver form;
  - `media-page.php`, for the staff tabs and the public page.
- **Controllers:**
  - `media-profile.php` (driver form);
  - `media.php` (Media section);
  - `media-photo.php` (photo serving);
  - `driver.php` (public page).
- **Media staff** is a flag on `users` (`is_media`), not a new rung in `ROLE_LEVELS`. It is carried in the session the same way as `role`, so a change takes effect the next time the person signs in.
- **No database reset.** New tables come from `CREATE TABLE IF NOT EXISTS`. New `users` columns come from a new, reusable helper, `db_add_column_if_missing()`.

**Tech Stack:**
- PHP 8.3 and SQLite (PDO).
- PHPUnit 10, run from `wcma-calculator/` with `php phpunit.phar`.
- Vanilla JS, reusing `js/photo-resize.js`. `node --test` for any JS unit tests.
- PHPMailer, already vendored, called through `emailSmtpSend()`.

**Spec:** `docs/superpowers/specs/2026-09-27-driver-media-profiles-design.md`

## Global Constraints

**Paths and tooling**
- All paths are relative to `wcma-calculator/` unless they start with `docs/`.
- Run tests from `wcma-calculator/` with `php phpunit.phar`.
- The checkout uses CRLF. A source test that slices PHP by `"\nfunction "` must first apply `str_replace("\r\n", "\n", ...)`.
- No new dependencies and no build step. `ZipArchive` is optional: check it with `class_exists('ZipArchive')` and hide the zip button without it.

**Copy**
- **Terminology:** use *reviewed*, *accepted* and *sent back*. Never use *approved*, *approval*, *passed* or *safe* in UI or email copy.
- **Binding consent wording** lives in `media-lib.php` constants. `MEDIA_CONSENT_WORDING_VERSION = 1`. Each `media_consents` row stores the version it was given under.

**Data rules**
- **No DB reset:** never add a `reset-hub-db.php` step. Existing rows must survive `db_init()`.
- **Nothing is used without consent.** Every output goes through `mediaUsable($profile, $consentRow, 'club'|'public')`.
- **A hidden profile appears nowhere,** not even to the public. The owner and Media staff can still see its photo.
- **`media_consents` rows are only ever added.** The newest row per driver is the current consent.
- **A failed email never blocks.** Log the failure with `error_log` and say so in the flash message.

**Security**
- **Escape everything** from the DB or the request with `h()`, including URLs in `href`.
- **Every state-changing POST checks** `validateCsrfToken($_POST['csrf_token'] ?? '')`.
- **Photos are only served through `media-photo.php`.** `uploads/` is already `Deny from all` (`uploads/.htaccess`).
- **Sponsor URLs are http(s) only.** A value with no scheme gets `https://` added. Any other scheme is rejected.

**Git**
- Work on branch `media-profiles` (`git checkout -b media-profiles` from `main`) and commit at the end of every task.
- Don't push.
- Every commit message ends with:
  ```
  Co-Authored-By: <the Claude model that wrote the commit> <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5
  ```

## Review Focus

1. **Deploying over a live database.** `db_init()` runs on a database whose `users` table has no `is_media` column. Every existing account must survive with `is_media = 0`, and running `db_init()` again must not fail. Pinned in Task 1 (`DbMediaTest::testAddColumnIfMissingKeepsRowsAndIsRepeatable`).
2. **Withdrawn or hidden profiles leaking.** A driver withdraws, or Media hides the profile, while the public page, a photo URL or the media kit is open or bookmarked. The next request must 404 or leave them out. Pinned in Task 2 (`MediaLibTest::testUsableNeedsConsentContentAndNotHidden`, `testPhotoAccess`) and Task 3 (`MediaServiceTest::testWithdrawTurnsConsentOffAndClearsPublicStatus`).
3. **An accepted public profile edited afterwards.** A changed blurb, photo, sponsor or about field must go back to review. Re-saving unchanged content, or changing only consent, must not. Pinned in Task 2 (`MediaLibTest::testNextPublicStatus`, `testContentChanged`).
4. **Hostile or sloppy input.**
   - A sponsor URL `javascript:alert(1)` must be rejected.
   - `acme.com` must become `https://acme.com`.
   - A 501-character blurb must be rejected.
   - An `@` in the social handle must be stripped.
   - HTML in the blurb must be escaped on every page.

   Pinned in Task 2 (`MediaLibTest::testValidateFields`) and Task 6/Task 9 (escaping asserts in the page tests).
5. **Someone else's driver.** A signed-in user posting `driver_id` for a profile they don't manage must get "Choose one of your drivers." and no write. They must not be able to load that profile's photo if it isn't public. Pinned in Task 3 (`MediaServiceTest::testOnlyTheOwnerCanSave`) and Task 2 (`testPhotoAccess`).

---

## File map

| File | Status | Responsibility |
|---|---|---|
| `db.php` | modify | `db_add_column_if_missing()`; `users.is_media` and `users.media_prompt_dismissed`; the three media tables; media DB helpers; `db_get_driver_latest_sheet()` |
| `media-lib.php` | create | Pure rules: consent, validation, status, whether a profile is usable, photo access, roster drivers, entries, copy text and CSV |
| `media-service.php` | create | DB work: save, withdraw and delete a profile, review actions, roster and kit loaders |
| `media-email.php` | create | Sent-back and hidden emails, and `mediaNotifyOwner()` |
| `roles.php` | modify | `mediaCanAccess()` |
| `session_bootstrap.php` | modify | Carry `is_media` in the session |
| `layout.php` | modify | Media nav item; `MEDIA_TABS`; `mediaSubnavHtml()` |
| `media-profile-page.php` | create | `renderMediaProfileHtml()` |
| `media-profile.php` | create | Driver form controller |
| `js/media-profile.js` | create | Resize on pick, preview, blurb counter, guardian toggle, confirm dialogs |
| `media-photo.php` | create | Streams a profile photo after `mediaPhotoAllowed()` |
| `drivers-lib.php`, `drivers-page.php`, `drivers.php` | modify | Media status and link on each driver row |
| `home-page.php`, `index.php` | modify | One-time "Clubs would like to feature you" card |
| `media-page.php` | create | Announcer, Media kit, Public review and public-page markup |
| `media.php` | create | Media section controller: tabs, review POSTs, zip download |
| `js/media-kit.js` | create | Copy-to-clipboard buttons |
| `driver.php` | create | The public page |
| `admin.php` | modify | Media staff checkbox (`set-media`) |
| `css/hub.css` | modify | Media styles and the announcer print layout |
| `hub-db-tools.php` | modify | Seed a Media user and two media profiles |
| `README.md` | modify | A short "Driver media profiles" note |

---

### Task 1: Schema, the add-column helper, and media DB helpers

**Files:**
- Modify: `db.php` (the `db_init()` end, plus a new "Media profiles" helper section at the end of the file)
- Test: `tests/DbMediaTest.php`

**Interfaces:**
- Produces:
  - `db_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): bool`: true if it added the column.
  - `db_get_media_profile(PDO $pdo, int $driverId): ?array`
  - `db_save_media_profile(PDO $pdo, int $driverId, array $f): void`: `$f` keys `blurb`, `pronunciation`, `hometown`, `racing_since`, `social_handle`, `photo_path`, `public_status`. Inserts or updates.
  - `db_set_media_public_status(PDO $pdo, int $driverId, string $status, ?int $reviewerId, ?string $note): void`
  - `db_set_media_hidden(PDO $pdo, int $driverId, ?int $byUserId, ?string $reason): void`: a null `$byUserId` unhides.
  - `db_delete_media_profile(PDO $pdo, int $driverId): void`: deletes the profile and its sponsors, and keeps the consent rows.
  - `db_get_sponsors(PDO $pdo, int $driverId): array`: rows `{id, driver_id, name, url, sort_order}` in order.
  - `db_replace_sponsors(PDO $pdo, int $driverId, array $sponsors): void`: `$sponsors` is a list of `{name, url}`.
  - `db_insert_media_consent(PDO $pdo, array $row): int`: keys `driver_id`, `consent_media`, `consent_public`, `is_minor`, `guardian_name`, `given_by_user_id`, `on_behalf`, `wording_version`.
  - `db_get_latest_media_consent(PDO $pdo, int $driverId): ?array`
  - `db_get_media_bundle(PDO $pdo, array $driverIds): array`: driver id => `['profile' => ?array, 'consent' => ?array, 'sponsors' => array]`.
  - `db_get_consented_driver_ids(PDO $pdo): array`: ints, the drivers whose newest consent row has `consent_media = 1`.
  - `db_get_media_review_queue(PDO $pdo): array`: profile rows joined with `driver_name`, where `public_status = 'pending_review'` and not hidden, oldest `updated_at` first.
  - `db_search_media_profiles(PDO $pdo, string $q, int $limit = 20): array`: profile rows with `driver_name`, where the driver's name contains `$q` (case-insensitive).
  - `db_set_user_media(PDO $pdo, int $userId, bool $on): void`
  - `db_dismiss_media_prompt(PDO $pdo, int $userId): void`
  - `db_get_driver_latest_sheet(PDO $pdo, int $driverId, int $season): ?array`: the newest tech sheet in `$season` where the driver is driver 1 or an additional driver.

- [ ] **Step 1: Create the branch**

```bash
git checkout -b media-profiles
```

- [ ] **Step 2: Write the failing tests**

Create `tests/DbMediaTest.php`:

```php
<?php
// wcma-calculator/tests/DbMediaTest.php
use PHPUnit\Framework\TestCase;

final class DbMediaTest extends TestCase
{
    private function user(PDO $pdo, string $email, string $name = 'Jordan Lee'): int {
        return db_create_user($pdo, ['email' => $email, 'name' => $name, 'password_hash' => 'x', 'google_id' => null]);
    }

    private function consent(PDO $pdo, int $driverId, int $by, int $media, int $public = 0): int {
        return db_insert_media_consent($pdo, [
            'driver_id' => $driverId, 'consent_media' => $media, 'consent_public' => $public, 'is_minor' => 0,
            'guardian_name' => null, 'given_by_user_id' => $by, 'on_behalf' => 0, 'wording_version' => 1,
        ]);
    }

    private function profile(array $o = []): array {
        return array_merge(['blurb' => 'Fast and tidy.', 'pronunciation' => null, 'hometown' => 'Red Deer, AB',
            'racing_since' => 2015, 'social_handle' => null, 'photo_path' => null, 'public_status' => 'none'], $o);
    }

    public function testAddColumnIfMissingKeepsRowsAndIsRepeatable(): void
    {
        $pdo = make_temp_pdo();
        $pdo->exec("CREATE TABLE legacy (id INTEGER PRIMARY KEY, name TEXT)");
        $pdo->exec("INSERT INTO legacy (name) VALUES ('kept')");
        $this->assertTrue(db_add_column_if_missing($pdo, 'legacy', 'flag', 'INTEGER NOT NULL DEFAULT 0'));
        $this->assertFalse(db_add_column_if_missing($pdo, 'legacy', 'flag', 'INTEGER NOT NULL DEFAULT 0'));
        $this->assertSame(['id' => 1, 'name' => 'kept', 'flag' => 0], $pdo->query("SELECT * FROM legacy")->fetch());
        db_init($pdo);   // a second init on an existing database must not fail
        $this->assertSame(0, (int)db_find_user_by_id($pdo, $this->user($pdo, 'a@example.com'))['is_media']);
    }

    public function testAddColumnRejectsUnsafeIdentifiers(): void
    {
        $pdo = make_temp_pdo();
        $this->expectException(InvalidArgumentException::class);
        db_add_column_if_missing($pdo, 'users; DROP TABLE users', 'x', 'INTEGER');
    }

    public function testUsersGetMediaFlags(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $row = db_find_user_by_id($pdo, $u);
        $this->assertSame(0, (int)$row['is_media']);
        $this->assertSame(0, (int)$row['media_prompt_dismissed']);
        db_set_user_media($pdo, $u, true);
        db_dismiss_media_prompt($pdo, $u);
        $row = db_find_user_by_id($pdo, $u);
        $this->assertSame(1, (int)$row['is_media']);
        $this->assertSame(1, (int)$row['media_prompt_dismissed']);
    }

    public function testProfileSaveUpdateStatusHideAndDelete(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $d = (int)db_get_self_driver($pdo, $u)['id'];
        $this->assertNull(db_get_media_profile($pdo, $d));

        db_save_media_profile($pdo, $d, $this->profile());
        db_save_media_profile($pdo, $d, $this->profile(['blurb' => 'Second', 'public_status' => 'pending_review']));
        $p = db_get_media_profile($pdo, $d);
        $this->assertSame('Second', $p['blurb']);
        $this->assertSame('pending_review', $p['public_status']);
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM driver_media_profiles")->fetchColumn());

        db_set_media_public_status($pdo, $d, 'sent_back', $u, 'Brighter photo please');
        $p = db_get_media_profile($pdo, $d);
        $this->assertSame('sent_back', $p['public_status']);
        $this->assertSame($u, (int)$p['public_reviewed_by']);
        $this->assertSame('Brighter photo please', $p['public_note']);

        db_set_media_hidden($pdo, $d, $u, 'Sponsor dispute');
        $this->assertNotNull(db_get_media_profile($pdo, $d)['hidden_at']);
        db_set_media_hidden($pdo, $d, null, null);
        $p = db_get_media_profile($pdo, $d);
        $this->assertNull($p['hidden_at']);
        $this->assertSame('sent_back', $p['public_status']);

        db_replace_sponsors($pdo, $d, [['name' => 'Acme', 'url' => null]]);
        $this->consent($pdo, $d, $u, 1);
        db_delete_media_profile($pdo, $d);
        $this->assertNull(db_get_media_profile($pdo, $d));
        $this->assertSame([], db_get_sponsors($pdo, $d));
        $this->assertNotNull(db_get_latest_media_consent($pdo, $d));   // consent history is kept
    }

    public function testSponsorsAreReplacedAsASetInOrder(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $d = (int)db_get_self_driver($pdo, $u)['id'];
        db_replace_sponsors($pdo, $d, [['name' => 'Acme', 'url' => 'https://acme.test'], ['name' => 'Bob', 'url' => null]]);
        db_replace_sponsors($pdo, $d, [['name' => 'Zed', 'url' => null], ['name' => 'Acme', 'url' => 'https://acme.test']]);
        $this->assertSame(['Zed', 'Acme'], array_column(db_get_sponsors($pdo, $d), 'name'));
    }

    public function testNewestConsentWinsAndConsentedIdsFollowIt(): void
    {
        $pdo = make_temp_pdo();
        $a = $this->user($pdo, 'a@example.com', 'Ann Ames');
        $b = $this->user($pdo, 'b@example.com', 'Bo Bell');
        $da = (int)db_get_self_driver($pdo, $a)['id'];
        $db = (int)db_get_self_driver($pdo, $b)['id'];
        $this->consent($pdo, $da, $a, 1, 1);
        $this->consent($pdo, $db, $b, 1);
        $this->consent($pdo, $db, $b, 0);   // Bo withdrew
        $this->assertSame(1, (int)db_get_latest_media_consent($pdo, $da)['consent_public']);
        $this->assertSame(0, (int)db_get_latest_media_consent($pdo, $db)['consent_media']);
        $this->assertSame([$da], db_get_consented_driver_ids($pdo));
    }

    public function testBundleQueueAndSearch(): void
    {
        $pdo = make_temp_pdo();
        $a = $this->user($pdo, 'a@example.com', 'Ann Ames');
        $b = $this->user($pdo, 'b@example.com', 'Bo Bell');
        $da = (int)db_get_self_driver($pdo, $a)['id'];
        $db = (int)db_get_self_driver($pdo, $b)['id'];
        db_save_media_profile($pdo, $da, $this->profile(['public_status' => 'pending_review']));
        db_save_media_profile($pdo, $db, $this->profile(['public_status' => 'pending_review']));
        db_set_media_hidden($pdo, $db, $a, 'x');
        db_replace_sponsors($pdo, $da, [['name' => 'Acme', 'url' => null]]);

        $bundle = db_get_media_bundle($pdo, [$da, 999]);
        $this->assertSame('Fast and tidy.', $bundle[$da]['profile']['blurb']);
        $this->assertSame('Acme', $bundle[$da]['sponsors'][0]['name']);
        $this->assertNull($bundle[999]['profile']);

        $queue = db_get_media_review_queue($pdo);
        $this->assertSame([$da], array_map(fn(array $r): int => (int)$r['driver_id'], $queue));
        $this->assertSame('Ann Ames', $queue[0]['driver_name']);

        $this->assertSame([$db], array_map(fn(array $r): int => (int)$r['driver_id'], db_search_media_profiles($pdo, 'bell')));
    }

    public function testDriverLatestSheetCoversDriverOneAndAdditionalDrivers(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $e = db_create_event($pdo, 'Fall Sprint', date('Y') . '-10-11', null);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42'));
        $sheet = test_make_sheet($pdo, $u, $sub, $e, '42', 'Jordan Lee');
        db_add_tech_sheet_driver($pdo, $sheet, 2, 'Sam Patel', '{}');
        $self = (int)db_get_self_driver($pdo, $u)['id'];
        $sam = (int)db_find_driver($pdo, $u, 'Sam Patel')['id'];
        $this->assertSame($sheet, (int)db_get_driver_latest_sheet($pdo, $self, (int)date('Y'))['id']);
        $this->assertSame($sheet, (int)db_get_driver_latest_sheet($pdo, $sam, (int)date('Y'))['id']);
        $this->assertNull(db_get_driver_latest_sheet($pdo, $sam, (int)date('Y') - 1));
    }
}
```

- [ ] **Step 3: Run the tests and watch them fail**

Run: `php phpunit.phar tests/DbMediaTest.php`
Expected: FAIL with `Call to undefined function db_add_column_if_missing()`.

- [ ] **Step 4: Add the schema to `db_init()`**

In `db.php`, just before the closing `}` of `db_init()` (after the `idx_tech_sheets_car` index line), add:

```php
    // ── Driver media profiles (2026-09-27 spec). Added in place: no reset. ──
    db_add_column_if_missing($pdo, 'users', 'is_media', 'INTEGER NOT NULL DEFAULT 0');
    db_add_column_if_missing($pdo, 'users', 'media_prompt_dismissed', 'INTEGER NOT NULL DEFAULT 0');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS driver_media_profiles (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            driver_id          INTEGER NOT NULL UNIQUE,
            blurb              TEXT NOT NULL DEFAULT '',
            pronunciation      TEXT,
            hometown           TEXT,
            racing_since       INTEGER,
            social_handle      TEXT,
            photo_path         TEXT,
            public_status      TEXT NOT NULL DEFAULT 'none',
            public_reviewed_by INTEGER,
            public_reviewed_at DATETIME,
            public_note        TEXT,
            hidden_at          DATETIME,
            hidden_by          INTEGER,
            hidden_reason      TEXT,
            created_at         DATETIME NOT NULL,
            updated_at         DATETIME NOT NULL
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS driver_sponsors (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            driver_id  INTEGER NOT NULL,
            name       TEXT NOT NULL,
            url        TEXT,
            sort_order INTEGER NOT NULL DEFAULT 0
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_driver_sponsors_driver ON driver_sponsors (driver_id, sort_order)");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS media_consents (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            driver_id        INTEGER NOT NULL,
            consent_media    INTEGER NOT NULL,
            consent_public   INTEGER NOT NULL,
            is_minor         INTEGER NOT NULL DEFAULT 0,
            guardian_name    TEXT,
            given_by_user_id INTEGER NOT NULL,
            on_behalf        INTEGER NOT NULL DEFAULT 0,
            wording_version  INTEGER NOT NULL,
            created_at       DATETIME NOT NULL
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_media_consents_driver ON media_consents (driver_id, id)");
```

- [ ] **Step 5: Add the helpers at the end of `db.php`**

```php
// ── Schema helper ─────────────────────────────────────────────────────────────

/**
 * Adds a column to an existing table if it isn't there yet, keeping every row. Safe to call on
 * each request. Identifiers are checked because SQLite can't bind them. @return bool true if added
 */
function db_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): bool {
    foreach ([$table, $column] as $ident) {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $ident)) throw new InvalidArgumentException('Unsafe identifier: ' . $ident);
    }
    foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll() as $col) {
        if ($col['name'] === $column) return false;
    }
    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    return true;
}

// ── Media profiles (2026-09-27 spec) ──────────────────────────────────────────

function db_get_media_profile(PDO $pdo, int $driverId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM driver_media_profiles WHERE driver_id = :d");
    $stmt->execute([':d' => $driverId]);
    return $stmt->fetch() ?: null;
}

function db_save_media_profile(PDO $pdo, int $driverId, array $f): void {
    $now = date('Y-m-d H:i:s');
    $params = [
        ':d' => $driverId, ':b' => (string)$f['blurb'], ':p' => $f['pronunciation'], ':h' => $f['hometown'],
        ':r' => $f['racing_since'], ':s' => $f['social_handle'], ':photo' => $f['photo_path'],
        ':st' => (string)$f['public_status'], ':now' => $now,
    ];
    $pdo->prepare("
        INSERT INTO driver_media_profiles (driver_id, blurb, pronunciation, hometown, racing_since, social_handle,
                                           photo_path, public_status, created_at, updated_at)
        VALUES (:d, :b, :p, :h, :r, :s, :photo, :st, :now, :now)
        ON CONFLICT(driver_id) DO UPDATE SET blurb = excluded.blurb, pronunciation = excluded.pronunciation,
            hometown = excluded.hometown, racing_since = excluded.racing_since, social_handle = excluded.social_handle,
            photo_path = excluded.photo_path, public_status = excluded.public_status, updated_at = excluded.updated_at
    ")->execute($params);
}

function db_set_media_public_status(PDO $pdo, int $driverId, string $status, ?int $reviewerId, ?string $note): void {
    $pdo->prepare("
        UPDATE driver_media_profiles
        SET public_status = :s, public_reviewed_by = :r, public_reviewed_at = :at, public_note = :n
        WHERE driver_id = :d
    ")->execute([':s' => $status, ':r' => $reviewerId, ':at' => date('Y-m-d H:i:s'), ':n' => $note, ':d' => $driverId]);
}

function db_set_media_hidden(PDO $pdo, int $driverId, ?int $byUserId, ?string $reason): void {
    $pdo->prepare("UPDATE driver_media_profiles SET hidden_at = :at, hidden_by = :b, hidden_reason = :r WHERE driver_id = :d")
        ->execute([':at' => $byUserId === null ? null : date('Y-m-d H:i:s'), ':b' => $byUserId, ':r' => $reason, ':d' => $driverId]);
}

/** Removes the profile and sponsors. Consent rows stay: they are the record. */
function db_delete_media_profile(PDO $pdo, int $driverId): void {
    $pdo->prepare("DELETE FROM driver_sponsors WHERE driver_id = :d")->execute([':d' => $driverId]);
    $pdo->prepare("DELETE FROM driver_media_profiles WHERE driver_id = :d")->execute([':d' => $driverId]);
}

function db_get_sponsors(PDO $pdo, int $driverId): array {
    $stmt = $pdo->prepare("SELECT * FROM driver_sponsors WHERE driver_id = :d ORDER BY sort_order ASC, id ASC");
    $stmt->execute([':d' => $driverId]);
    return $stmt->fetchAll();
}

/** @param array<int, array{name: string, url: ?string}> $sponsors */
function db_replace_sponsors(PDO $pdo, int $driverId, array $sponsors): void {
    $pdo->prepare("DELETE FROM driver_sponsors WHERE driver_id = :d")->execute([':d' => $driverId]);
    $ins = $pdo->prepare("INSERT INTO driver_sponsors (driver_id, name, url, sort_order) VALUES (:d, :n, :u, :o)");
    foreach (array_values($sponsors) as $i => $s) {
        $ins->execute([':d' => $driverId, ':n' => (string)$s['name'], ':u' => $s['url'] ?? null, ':o' => $i]);
    }
}

function db_insert_media_consent(PDO $pdo, array $row): int {
    $pdo->prepare("
        INSERT INTO media_consents (driver_id, consent_media, consent_public, is_minor, guardian_name,
                                    given_by_user_id, on_behalf, wording_version, created_at)
        VALUES (:d, :m, :p, :minor, :g, :by, :ob, :v, :at)
    ")->execute([
        ':d' => (int)$row['driver_id'], ':m' => (int)$row['consent_media'], ':p' => (int)$row['consent_public'],
        ':minor' => (int)$row['is_minor'], ':g' => $row['guardian_name'], ':by' => (int)$row['given_by_user_id'],
        ':ob' => (int)$row['on_behalf'], ':v' => (int)$row['wording_version'], ':at' => date('Y-m-d H:i:s'),
    ]);
    return (int)$pdo->lastInsertId();
}

function db_get_latest_media_consent(PDO $pdo, int $driverId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM media_consents WHERE driver_id = :d ORDER BY id DESC LIMIT 1");
    $stmt->execute([':d' => $driverId]);
    return $stmt->fetch() ?: null;
}

/** driver id => ['profile' => ?row, 'consent' => ?row (newest), 'sponsors' => rows]. */
function db_get_media_bundle(PDO $pdo, array $driverIds): array {
    $out = [];
    foreach (array_values(array_unique(array_map('intval', $driverIds))) as $id) {
        $out[$id] = ['profile' => db_get_media_profile($pdo, $id), 'consent' => db_get_latest_media_consent($pdo, $id),
                     'sponsors' => db_get_sponsors($pdo, $id)];
    }
    return $out;
}

/** Drivers whose newest consent row allows announcing and club promotion. */
function db_get_consented_driver_ids(PDO $pdo): array {
    return array_map('intval', $pdo->query("
        SELECT c.driver_id FROM media_consents c
        WHERE c.id = (SELECT MAX(id) FROM media_consents WHERE driver_id = c.driver_id) AND c.consent_media = 1
        ORDER BY c.driver_id ASC
    ")->fetchAll(PDO::FETCH_COLUMN));
}

function db_get_media_review_queue(PDO $pdo): array {
    return $pdo->query("
        SELECT p.*, d.name AS driver_name FROM driver_media_profiles p JOIN drivers d ON d.id = p.driver_id
        WHERE p.public_status = 'pending_review' AND p.hidden_at IS NULL
        ORDER BY p.updated_at ASC, p.id ASC
    ")->fetchAll();
}

function db_search_media_profiles(PDO $pdo, string $q, int $limit = 20): array {
    $stmt = $pdo->prepare("
        SELECT p.*, d.name AS driver_name FROM driver_media_profiles p JOIN drivers d ON d.id = p.driver_id
        WHERE d.name_norm LIKE :q ESCAPE '\\' ORDER BY d.name_norm ASC LIMIT :lim
    ");
    $like = '%' . addcslashes(db_driver_name_norm($q), '%_\\') . '%';
    $stmt->bindValue(':q', $like);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function db_set_user_media(PDO $pdo, int $userId, bool $on): void {
    $pdo->prepare("UPDATE users SET is_media = :m WHERE id = :id")->execute([':m' => $on ? 1 : 0, ':id' => $userId]);
}

function db_dismiss_media_prompt(PDO $pdo, int $userId): void {
    $pdo->prepare("UPDATE users SET media_prompt_dismissed = 1 WHERE id = :id")->execute([':id' => $userId]);
}

/** The newest sheet in $season where the driver is driver 1 or an additional driver. */
function db_get_driver_latest_sheet(PDO $pdo, int $driverId, int $season): ?array {
    $stmt = $pdo->prepare("
        SELECT * FROM tech_sheets
        WHERE season = :s AND (driver_id = :d OR id IN (SELECT tech_sheet_id FROM tech_sheet_drivers WHERE driver_id = :d))
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([':s' => $season, ':d' => $driverId]);
    return $stmt->fetch() ?: null;
}
```

- [ ] **Step 6: Run the tests and watch them pass**

Run: `php phpunit.phar tests/DbMediaTest.php`
Expected: PASS (8 tests).

Then run the whole suite: `php phpunit.phar`
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add db.php tests/DbMediaTest.php
git commit -m "feat(media): media profile tables, add-column helper, and DB helpers"
```

---

### Task 2: Pure media rules (`media-lib.php`) and `mediaCanAccess()`

**Files:**
- Create: `media-lib.php`
- Modify: `roles.php` (add `mediaCanAccess()`)
- Test: `tests/MediaLibTest.php`

**Interfaces:**
- Consumes: `user_has_role()` (`roles.php`) and `h()` (`view_helpers.php`, not needed by this file).
- Produces:
  - **Constants:** `MEDIA_CONSENT_WORDING_VERSION`, `MEDIA_CONSENT_MEDIA_TEXT`, `MEDIA_CONSENT_PUBLIC_TEXT`, `MEDIA_CONSENT_MINOR_TEXT`, `MEDIA_ON_BEHALF_TEXT` (a `sprintf` format with `%s` for the name), `MEDIA_BLURB_MAX = 500`, `MEDIA_MAX_SPONSORS = 6`.
  - **Access:**
    - `mediaCanAccess(?array $user): bool` (in `roles.php`): admin, or `!empty($user['is_media'])`.
    - `mediaPhotoAllowed(?array $user, array $driver, ?array $profile, ?array $consent): bool`
  - **Consent:**
    - `mediaCurrentConsent(?array $row): array{media: bool, public: bool}`
    - `mediaConsentInput(array $post, bool $isSelf): array{ok: bool, error: ?string, consent: array}`: `consent` has keys `consent_media`, `consent_public`, `is_minor`, `guardian_name`, `on_behalf` (ints, and guardian as ?string).
    - `mediaConsentChanged(?array $latest, array $consent): bool`
  - **Content and status:**
    - `mediaHasContent(?array $profile): bool`
    - `mediaUsable(?array $profile, ?array $consentRow, string $output): bool`: `$output` is `'club'` or `'public'`.
    - `mediaValidateFields(array $post, int $currentYear): array{errors: string[], fields: array, sponsors: array}`: `fields` has keys `blurb` (string), `pronunciation`, `hometown` and `social_handle` (?string), and `racing_since` (?int). `sponsors` is a list of `{name: string, url: ?string}`.
    - `mediaContentChanged(?array $profile, array $sponsorsBefore, array $fields, array $sponsors, bool $photoReplaced): bool`
    - `mediaNextPublicStatus(string $current, bool $wantsPublic, bool $contentChanged): string`
    - `mediaProfileStatus(?array $profile, ?array $consent): array{state: string, label: string, class: string}`
  - **Roster and output:**
    - `mediaRosterDriverIds(array $carEventSheets, ?array $latestSheet, array $sheetDrivers, ?int $ownerSelfDriverId): int[]`
    - `mediaAcceptedClass(array $declarationsNewestFirst): string`
    - `mediaCarLabel(array $carOrSheet): string`: reads `year`/`make`/`model`/`colour`, or `car_make`/`car_model`/`car_colour` from a sheet.
    - `mediaEntry(array $driver, array $profile, array $sponsors, string $number, string $car, string $class, bool $publicLive): array`: keys `driver_id`, `name`, `pronunciation`, `hometown`, `racing_since`, `blurb`, `social_handle`, `sponsors`, `has_photo`, `number`, `car`, `class`, `public_live`.
    - `mediaCopyText(array $entry): string`
    - `mediaSlug(string $s): string`
    - `mediaCsvRows(array $entries, string $baseUrl): array`: a header row, then one row per entry.

- [ ] **Step 1: Write the failing tests**

Create `tests/MediaLibTest.php`:

```php
<?php
// wcma-calculator/tests/MediaLibTest.php
require_once __DIR__ . '/../media-lib.php';

use PHPUnit\Framework\TestCase;

final class MediaLibTest extends TestCase
{
    private function consentRow(int $media, int $public = 0): array {
        return ['consent_media' => $media, 'consent_public' => $public, 'is_minor' => 0, 'guardian_name' => null];
    }

    private function profile(array $o = []): array {
        return array_merge(['driver_id' => 5, 'blurb' => 'Fast.', 'pronunciation' => null, 'hometown' => null,
            'racing_since' => null, 'social_handle' => null, 'photo_path' => null, 'public_status' => 'none',
            'hidden_at' => null, 'public_note' => null], $o);
    }

    public function testMediaCanAccess(): void
    {
        $this->assertFalse(mediaCanAccess(null));
        $this->assertFalse(mediaCanAccess(['id' => 1, 'role' => 'inspector', 'is_media' => 0]));
        $this->assertTrue(mediaCanAccess(['id' => 1, 'role' => 'user', 'is_media' => 1]));
        $this->assertTrue(mediaCanAccess(['id' => 1, 'role' => 'admin']));
    }

    public function testCurrentConsent(): void
    {
        $this->assertSame(['media' => false, 'public' => false], mediaCurrentConsent(null));
        $this->assertSame(['media' => true, 'public' => false], mediaCurrentConsent($this->consentRow(1)));
        $this->assertSame(['media' => true, 'public' => true], mediaCurrentConsent($this->consentRow(1, 1)));
        $this->assertSame(['media' => false, 'public' => false], mediaCurrentConsent($this->consentRow(0, 1)));
    }

    public function testUsableNeedsConsentContentAndNotHidden(): void
    {
        $p = $this->profile();
        $this->assertFalse(mediaUsable(null, $this->consentRow(1), 'club'));
        $this->assertFalse(mediaUsable($p, null, 'club'));
        $this->assertTrue(mediaUsable($p, $this->consentRow(1), 'club'));
        $this->assertFalse(mediaUsable($this->profile(['blurb' => '  ', 'photo_path' => null]), $this->consentRow(1), 'club'));
        $this->assertTrue(mediaUsable($this->profile(['blurb' => '', 'photo_path' => 'uploads/media/x.jpg']), $this->consentRow(1), 'club'));
        $this->assertFalse(mediaUsable($this->profile(['hidden_at' => '2026-09-27 10:00:00']), $this->consentRow(1), 'club'));
        $this->assertFalse(mediaUsable($p, $this->consentRow(1, 1), 'public'));   // not accepted yet
        $this->assertTrue(mediaUsable($this->profile(['public_status' => 'accepted']), $this->consentRow(1, 1), 'public'));
        $this->assertFalse(mediaUsable($this->profile(['public_status' => 'accepted']), $this->consentRow(1, 0), 'public'));
        $this->assertFalse(mediaUsable($this->profile(['public_status' => 'accepted', 'hidden_at' => 'x']), $this->consentRow(1, 1), 'public'));
    }

    public function testConsentInput(): void
    {
        $r = mediaConsentInput(['consent_media' => '1', 'consent_public' => '1'], true);
        $this->assertTrue($r['ok']);
        $this->assertSame(['consent_media' => 1, 'consent_public' => 1, 'is_minor' => 0, 'guardian_name' => null, 'on_behalf' => 0], $r['consent']);

        $r = mediaConsentInput(['consent_public' => '1'], true);   // public without media means neither
        $this->assertSame(0, $r['consent']['consent_public']);

        $r = mediaConsentInput(['consent_media' => '1', 'is_minor' => '1', 'guardian_name' => ' '], true);
        $this->assertFalse($r['ok']);
        $this->assertSame("Enter the parent or guardian's name.", $r['error']);
        $r = mediaConsentInput(['consent_media' => '1', 'is_minor' => '1', 'guardian_name' => '  Pat   Lee '], true);
        $this->assertSame('Pat Lee', $r['consent']['guardian_name']);

        $r = mediaConsentInput(['consent_media' => '1'], false);
        $this->assertFalse($r['ok']);
        $this->assertSame('Confirm that this driver agreed, or untick the consent box.', $r['error']);
        $r = mediaConsentInput(['consent_media' => '1', 'on_behalf_confirm' => '1'], false);
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['consent']['on_behalf']);

        $r = mediaConsentInput([], false);   // no consent at all needs no confirmation
        $this->assertTrue($r['ok']);
        $this->assertSame(0, $r['consent']['consent_media']);
    }

    public function testConsentChanged(): void
    {
        $none = ['consent_media' => 0, 'consent_public' => 0, 'is_minor' => 0, 'guardian_name' => null, 'on_behalf' => 0];
        $yes = ['consent_media' => 1] + $none;
        $this->assertFalse(mediaConsentChanged(null, $none));
        $this->assertTrue(mediaConsentChanged(null, $yes));
        $this->assertFalse(mediaConsentChanged($yes, $yes));
        $this->assertTrue(mediaConsentChanged($yes, ['consent_public' => 1] + $yes));
        $this->assertTrue(mediaConsentChanged($yes, ['is_minor' => 1, 'guardian_name' => 'Pat'] + $yes));
    }

    public function testValidateFields(): void
    {
        $r = mediaValidateFields([
            'blurb' => "Line one\r\nline two", 'pronunciation' => ' Sin-field ', 'hometown' => '',
            'racing_since' => '2015', 'social_handle' => '@fastjordan',
            'sponsor_name' => ['Acme', '', 'Bob\'s Garage', 'Evil'], 'sponsor_url' => ['acme.com', '', '', 'javascript:alert(1)'],
        ], 2026);
        $this->assertSame(['Sponsor 4 website must start with http:// or https://.'], $r['errors']);

        $r = mediaValidateFields([
            'blurb' => "Line one\r\nline two", 'pronunciation' => ' Sin-field ', 'hometown' => '',
            'racing_since' => '2015', 'social_handle' => '@fastjordan',
            'sponsor_name' => ['Acme', '', "Bob's Garage"], 'sponsor_url' => ['acme.com', '', ''],
        ], 2026);
        $this->assertSame([], $r['errors']);
        $this->assertSame(['blurb' => "Line one\nline two", 'pronunciation' => 'Sin-field', 'hometown' => null,
            'racing_since' => 2015, 'social_handle' => 'fastjordan'], $r['fields']);
        $this->assertSame([['name' => 'Acme', 'url' => 'https://acme.com'], ['name' => "Bob's Garage", 'url' => null]], $r['sponsors']);

        $this->assertSame(['Keep the blurb to 500 characters or fewer.'], mediaValidateFields(['blurb' => str_repeat('a', 501)], 2026)['errors']);
        $this->assertSame([], mediaValidateFields(['blurb' => str_repeat('é', 500)], 2026)['errors']);
        $this->assertSame(['Racing since must be a year from 1950 to 2026.'], mediaValidateFields(['racing_since' => '2027'], 2026)['errors']);
        $this->assertSame(['Racing since must be a year from 1950 to 2026.'], mediaValidateFields(['racing_since' => '15'], 2026)['errors']);
        $this->assertSame(['Sponsor 1 needs a name as well as a website.'], mediaValidateFields(['sponsor_name' => [''], 'sponsor_url' => ['acme.com']], 2026)['errors']);
        $this->assertSame(['Add at most 6 sponsors.'], mediaValidateFields(['sponsor_name' => array_fill(0, 7, 'X')], 2026)['errors']);
        $this->assertSame(['Hometown is too long (60 characters at most).'], mediaValidateFields(['hometown' => str_repeat('a', 61)], 2026)['errors']);
        $this->assertSame([], mediaValidateFields(['blurb' => ['not', 'a', 'string']], 2026)['errors']);   // junk input is ignored, not fatal
    }

    public function testContentChanged(): void
    {
        $p = $this->profile(['blurb' => 'Fast.', 'racing_since' => 2015]);
        $fields = ['blurb' => 'Fast.', 'pronunciation' => null, 'hometown' => null, 'racing_since' => 2015, 'social_handle' => null];
        $before = [['id' => 9, 'driver_id' => 5, 'name' => 'Acme', 'url' => null, 'sort_order' => 0]];
        $same = [['name' => 'Acme', 'url' => null]];
        $this->assertFalse(mediaContentChanged($p, $before, $fields, $same, false));
        $this->assertTrue(mediaContentChanged($p, $before, $fields, $same, true));
        $this->assertTrue(mediaContentChanged($p, $before, ['blurb' => 'Faster.'] + $fields, $same, false));
        $this->assertTrue(mediaContentChanged($p, $before, $fields, [['name' => 'Acme', 'url' => 'https://a.test']], false));
        $this->assertTrue(mediaContentChanged(null, [], $fields, [], false));
    }

    public function testNextPublicStatus(): void
    {
        $this->assertSame('none', mediaNextPublicStatus('accepted', false, false));
        $this->assertSame('pending_review', mediaNextPublicStatus('none', true, false));
        $this->assertSame('accepted', mediaNextPublicStatus('accepted', true, false));
        $this->assertSame('pending_review', mediaNextPublicStatus('accepted', true, true));
        $this->assertSame('pending_review', mediaNextPublicStatus('sent_back', true, true));
        $this->assertSame('sent_back', mediaNextPublicStatus('sent_back', true, false));
        $this->assertSame('pending_review', mediaNextPublicStatus('pending_review', true, true));
    }

    public function testProfileStatus(): void
    {
        $this->assertSame('Not set up', mediaProfileStatus(null, null)['label']);
        $this->assertSame('Not set up', mediaProfileStatus($this->profile(), null)['label']);
        $this->assertSame('Shared with clubs', mediaProfileStatus($this->profile(), $this->consentRow(1))['label']);
        $this->assertSame('Public page: waiting for review', mediaProfileStatus($this->profile(['public_status' => 'pending_review']), $this->consentRow(1, 1))['label']);
        $this->assertSame('Public page live', mediaProfileStatus($this->profile(['public_status' => 'accepted']), $this->consentRow(1, 1))['label']);
        $this->assertSame('Public page sent back', mediaProfileStatus($this->profile(['public_status' => 'sent_back']), $this->consentRow(1, 1))['label']);
        $hidden = mediaProfileStatus($this->profile(['hidden_at' => 'x', 'public_status' => 'accepted']), $this->consentRow(1, 1));
        $this->assertSame(['state' => 'hidden', 'label' => 'Hidden by WCMA', 'class' => 'hub-status--todo'], $hidden);
    }

    public function testPhotoAccess(): void
    {
        $driver = ['id' => 5, 'owner_user_id' => 7];
        $p = $this->profile(['photo_path' => 'uploads/media/driver-5-a.jpg']);
        $owner = ['id' => 7, 'role' => 'user'];
        $media = ['id' => 8, 'role' => 'user', 'is_media' => 1];
        $other = ['id' => 9, 'role' => 'user'];
        $this->assertTrue(mediaPhotoAllowed($owner, $driver, $p, null));
        $this->assertTrue(mediaPhotoAllowed($media, $driver, $p, null));
        $this->assertFalse(mediaPhotoAllowed($other, $driver, $p, $this->consentRow(1)));
        $this->assertFalse(mediaPhotoAllowed(null, $driver, $p, $this->consentRow(1, 1)));
        $live = $this->profile(['photo_path' => 'uploads/media/driver-5-a.jpg', 'public_status' => 'accepted']);
        $this->assertTrue(mediaPhotoAllowed(null, $driver, $live, $this->consentRow(1, 1)));
        $this->assertFalse(mediaPhotoAllowed(null, $driver, array_merge($live, ['hidden_at' => 'x']), $this->consentRow(1, 1)));
        $this->assertFalse(mediaPhotoAllowed($owner, $driver, $this->profile(['photo_path' => null]), null));
    }

    public function testRosterDriverIdsPreferEventSheetsThenLatestSheetThenOwner(): void
    {
        $eventSheets = [['id' => 10, 'driver_id' => 1], ['id' => 11, 'driver_id' => 1]];
        $sheetDrivers = [10 => [['driver_id' => 2]], 20 => [['driver_id' => 4]]];
        $this->assertSame([1, 2], mediaRosterDriverIds($eventSheets, ['id' => 20, 'driver_id' => 3], $sheetDrivers, 9));
        $this->assertSame([3, 4], mediaRosterDriverIds([], ['id' => 20, 'driver_id' => 3], $sheetDrivers, 9));
        $this->assertSame([9], mediaRosterDriverIds([], null, $sheetDrivers, 9));
        $this->assertSame([9], mediaRosterDriverIds([], ['id' => 30, 'driver_id' => null], [], 9));
        $this->assertSame([], mediaRosterDriverIds([], null, [], null));
    }

    public function testAcceptedClassAndCarLabel(): void
    {
        $this->assertSame('GT3', mediaAcceptedClass([['review_status' => 'submitted', 'calculated_class' => 'GT2'], ['review_status' => 'accepted', 'calculated_class' => 'GT3']]));
        $this->assertSame('', mediaAcceptedClass([['review_status' => 'submitted', 'calculated_class' => 'GT2']]));
        $this->assertSame('2004 Honda S2000 (Silver)', mediaCarLabel(['year' => '2004', 'make' => 'Honda', 'model' => 'S2000', 'colour' => 'Silver']));
        $this->assertSame('Mazda Miata (Red)', mediaCarLabel(['car_make' => 'Mazda', 'car_model' => 'Miata', 'car_colour' => 'Red']));
        $this->assertSame('Honda S2000', mediaCarLabel(['year' => '', 'make' => 'Honda', 'model' => 'S2000', 'colour' => null]));
    }

    public function testEntryCopyTextSlugAndCsv(): void
    {
        $e = mediaEntry(['id' => 5, 'name' => 'Jane Doe'], $this->profile(['blurb' => 'Loves the hairpin.', 'hometown' => 'Red Deer, AB',
            'racing_since' => 2015, 'photo_path' => 'uploads/media/x.jpg', 'pronunciation' => 'Doh', 'social_handle' => 'janed']),
            [['name' => 'Acme Tires', 'url' => 'https://acme.test'], ['name' => "Bob's Garage", 'url' => null]],
            '42', '2004 Honda S2000 (Silver)', 'GT3', true);
        $this->assertSame(5, $e['driver_id']);
        $this->assertTrue($e['has_photo']);
        $this->assertSame("#42 Jane Doe — 2004 Honda S2000 (Silver)\nRed Deer, AB · Racing since 2015\nLoves the hairpin.\nSupported by: Acme Tires, Bob's Garage",
            mediaCopyText($e));

        $bare = mediaEntry(['id' => 6, 'name' => 'Bo Bell'], $this->profile(['blurb' => '']), [], '', '', '', false);
        $this->assertSame('Bo Bell', mediaCopyText($bare));

        $this->assertSame('jane-doe', mediaSlug('  Jane  Doé!! '));
        $this->assertSame('driver', mediaSlug('!!!'));

        $rows = mediaCsvRows([$e, $bare], 'https://hub.test');
        $this->assertSame(['number', 'name', 'pronunciation', 'hometown', 'racing_since', 'car', 'class', 'blurb', 'sponsors', 'social_handle', 'public_url'], $rows[0]);
        $this->assertSame(['42', 'Jane Doe', 'Doh', 'Red Deer, AB', '2015', '2004 Honda S2000 (Silver)', 'GT3', 'Loves the hairpin.',
            "Acme Tires (https://acme.test); Bob's Garage", 'janed', 'https://hub.test/driver.php?id=5'], $rows[1]);
        $this->assertSame('', $rows[2][10]);
    }
}
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `php phpunit.phar tests/MediaLibTest.php`
Expected: FAIL. `media-lib.php` doesn't exist yet.

- [ ] **Step 3: Add `mediaCanAccess()` to `roles.php`**

Append to `roles.php`:

```php
/** The Media section (announcer sheet, media kit, public review): admins, and accounts flagged is_media. */
function mediaCanAccess(?array $user): bool {
    if ($user === null) return false;
    return user_has_role($user, 'admin') || !empty($user['is_media']);
}
```

- [ ] **Step 4: Create `media-lib.php`**

```php
<?php
// wcma-calculator/media-lib.php
//
// Pure rules for driver media profiles (spec 2026-09-27): consent, validation, public review
// status, where a profile may be used, which drivers a roster car shows, and the media kit's
// text and CSV. No DB, session or echo. Callers must have loaded roles.php (mediaCanAccess()).

const MEDIA_CONSENT_WORDING_VERSION = 1;
const MEDIA_CONSENT_MEDIA_TEXT = 'WCMA and its affiliated clubs may use this profile, photo and sponsors for event announcing and club promotion.';
const MEDIA_CONSENT_PUBLIC_TEXT = 'Also show it on a public web page anyone can view.';
const MEDIA_CONSENT_MINOR_TEXT = 'This driver is under 18. I am their parent or guardian and I give this consent for them.';
const MEDIA_ON_BEHALF_TEXT = 'I confirm %s agreed to the above.';
const MEDIA_BLURB_MAX = 500;
const MEDIA_MAX_SPONSORS = 6;

/** @return array{media: bool, public: bool} */
function mediaCurrentConsent(?array $row): array {
    $media = $row !== null && (int)$row['consent_media'] === 1;
    return ['media' => $media, 'public' => $media && (int)$row['consent_public'] === 1];
}

function mediaHasContent(?array $profile): bool {
    return $profile !== null && ((string)($profile['photo_path'] ?? '') !== '' || trim((string)($profile['blurb'] ?? '')) !== '');
}

/** 'club' = announcer sheet and media kit; 'public' = the public page. */
function mediaUsable(?array $profile, ?array $consentRow, string $output): bool {
    if ($profile === null || !empty($profile['hidden_at'])) return false;
    $c = mediaCurrentConsent($consentRow);
    if (!$c['media'] || !mediaHasContent($profile)) return false;
    return $output === 'public' ? ($c['public'] && ($profile['public_status'] ?? '') === 'accepted') : true;
}

function mediaPhotoAllowed(?array $user, array $driver, ?array $profile, ?array $consent): bool {
    if ($profile === null || (string)($profile['photo_path'] ?? '') === '') return false;
    if ($user !== null && (mediaCanAccess($user) || (int)$driver['owner_user_id'] === (int)$user['id'])) return true;
    return mediaUsable($profile, $consent, 'public');
}

function mediaOneLine(mixed $v): string {
    return is_string($v) ? trim((string)preg_replace('/\s+/u', ' ', $v)) : '';
}

/** @return array{ok: bool, error: ?string, consent: array} */
function mediaConsentInput(array $post, bool $isSelf): array {
    $media = ($post['consent_media'] ?? '') === '1';
    $minor = ($post['is_minor'] ?? '') === '1';
    $guardian = mediaOneLine($post['guardian_name'] ?? '');
    $consent = [
        'consent_media' => $media ? 1 : 0,
        'consent_public' => $media && ($post['consent_public'] ?? '') === '1' ? 1 : 0,
        'is_minor' => $minor ? 1 : 0,
        'guardian_name' => $minor && $guardian !== '' ? $guardian : null,
        'on_behalf' => $isSelf ? 0 : 1,
    ];
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'consent' => $consent];
    if ($media && $minor && $guardian === '') return $fail("Enter the parent or guardian's name.");
    if (mb_strlen($guardian, 'UTF-8') > 100) return $fail("The parent or guardian's name is too long (100 characters at most).");
    if ($media && !$isSelf && ($post['on_behalf_confirm'] ?? '') !== '1') return $fail('Confirm that this driver agreed, or untick the consent box.');
    return ['ok' => true, 'error' => null, 'consent' => $consent];
}

function mediaConsentChanged(?array $latest, array $consent): bool {
    if ($latest === null) return (int)$consent['consent_media'] === 1;
    foreach (['consent_media', 'consent_public', 'is_minor'] as $k) {
        if ((int)$latest[$k] !== (int)$consent[$k]) return true;
    }
    return ($latest['guardian_name'] ?? null) !== ($consent['guardian_name'] ?? null);
}

/** @return array{errors: string[], fields: array, sponsors: array} */
function mediaValidateFields(array $post, int $currentYear): array {
    $errors = [];
    $blurb = is_string($post['blurb'] ?? null) ? trim(str_replace("\r\n", "\n", $post['blurb'])) : '';
    if (mb_strlen($blurb, 'UTF-8') > MEDIA_BLURB_MAX) $errors[] = 'Keep the blurb to 500 characters or fewer.';

    $short = [];
    foreach (['pronunciation' => 'Pronunciation', 'hometown' => 'Hometown', 'social_handle' => 'Social handle'] as $k => $label) {
        $v = mediaOneLine($post[$k] ?? '');
        if ($k === 'social_handle') $v = ltrim($v, '@');
        if (mb_strlen($v, 'UTF-8') > 60) $errors[] = $label . ' is too long (60 characters at most).';
        $short[$k] = $v === '' ? null : $v;
    }

    $since = mediaOneLine($post['racing_since'] ?? '');
    $sinceInt = null;
    if ($since !== '') {
        if (!ctype_digit($since) || (int)$since < 1950 || (int)$since > $currentYear) {
            $errors[] = 'Racing since must be a year from 1950 to ' . $currentYear . '.';
        } else {
            $sinceInt = (int)$since;
        }
    }

    $names = is_array($post['sponsor_name'] ?? null) ? array_values($post['sponsor_name']) : [];
    $urls = is_array($post['sponsor_url'] ?? null) ? array_values($post['sponsor_url']) : [];
    $sponsors = [];
    $count = max(count($names), count($urls));
    $filled = 0;
    for ($i = 0; $i < $count; $i++) {
        $name = mediaOneLine($names[$i] ?? '');
        $url = mediaOneLine($urls[$i] ?? '');
        if ($name === '' && $url === '') continue;
        $filled++;
        $n = $i + 1;
        if ($name === '') { $errors[] = "Sponsor $n needs a name as well as a website."; continue; }
        if (mb_strlen($name, 'UTF-8') > 80) { $errors[] = "Sponsor $n name is too long (80 characters at most)."; continue; }
        if ($url !== '') {
            if (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) $url = 'https://' . $url;
            if (!preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                $errors[] = "Sponsor $n website must start with http:// or https://."; continue;
            }
            if (strlen($url) > 200) { $errors[] = "Sponsor $n website is too long (200 characters at most)."; continue; }
        }
        $sponsors[] = ['name' => $name, 'url' => $url === '' ? null : $url];
    }
    if ($filled > MEDIA_MAX_SPONSORS) $errors = ['Add at most 6 sponsors.'];

    return ['errors' => $errors, 'sponsors' => $sponsors,
            'fields' => ['blurb' => $blurb, 'pronunciation' => $short['pronunciation'], 'hometown' => $short['hometown'],
                         'racing_since' => $sinceInt, 'social_handle' => $short['social_handle']]];
}

function mediaContentChanged(?array $profile, array $sponsorsBefore, array $fields, array $sponsors, bool $photoReplaced): bool {
    if ($photoReplaced || $profile === null) return true;
    foreach (['blurb', 'pronunciation', 'hometown', 'racing_since', 'social_handle'] as $k) {
        if ((string)($profile[$k] ?? '') !== (string)($fields[$k] ?? '')) return true;
    }
    $pair = fn(array $s): array => [(string)$s['name'], (string)($s['url'] ?? '')];
    return array_map($pair, $sponsorsBefore) !== array_map($pair, $sponsors);
}

function mediaNextPublicStatus(string $current, bool $wantsPublic, bool $contentChanged): string {
    if (!$wantsPublic) return 'none';
    if ($current === 'none') return 'pending_review';
    if ($contentChanged && in_array($current, ['accepted', 'sent_back'], true)) return 'pending_review';
    return $current;
}

/** @return array{state: string, label: string, class: string} */
function mediaProfileStatus(?array $profile, ?array $consent): array {
    $s = fn(string $state, string $label, string $class): array => ['state' => $state, 'label' => $label, 'class' => $class];
    if ($profile !== null && !empty($profile['hidden_at'])) return $s('hidden', 'Hidden by WCMA', 'hub-status--todo');
    $c = mediaCurrentConsent($consent);
    if (!$c['media'] || !mediaHasContent($profile)) return $s('none', 'Not set up', 'hub-status--warn');
    if (!$c['public']) return $s('shared', 'Shared with clubs', 'hub-status--ok');
    switch ($profile['public_status'] ?? 'none') {
        case 'accepted':  return $s('accepted', 'Public page live', 'hub-status--ok');
        case 'sent_back': return $s('sent_back', 'Public page sent back', 'hub-status--todo');
        default:          return $s('pending_review', 'Public page: waiting for review', 'hub-status--info');
    }
}

/**
 * The drivers to show for one roster car: this event's sheets for the car, else the car's latest
 * sheet, else the car owner's own profile. Sheets carry driver 1 in driver_id; $sheetDrivers maps
 * sheet id => tech_sheet_drivers rows (additional drivers). @return int[]
 */
function mediaRosterDriverIds(array $carEventSheets, ?array $latestSheet, array $sheetDrivers, ?int $ownerSelfDriverId): array {
    $fromSheets = function (array $sheets) use ($sheetDrivers): array {
        $ids = [];
        foreach ($sheets as $sheet) {
            if (!empty($sheet['driver_id'])) $ids[] = (int)$sheet['driver_id'];
            foreach ($sheetDrivers[(int)$sheet['id']] ?? [] as $d) {
                if (!empty($d['driver_id'])) $ids[] = (int)$d['driver_id'];
            }
        }
        return array_values(array_unique($ids));
    };
    $ids = $fromSheets($carEventSheets);
    if (!$ids && $latestSheet !== null) $ids = $fromSheets([$latestSheet]);
    if (!$ids && $ownerSelfDriverId !== null) $ids = [$ownerSelfDriverId];
    return $ids;
}

function mediaAcceptedClass(array $declarationsNewestFirst): string {
    foreach ($declarationsNewestFirst as $d) {
        if (($d['review_status'] ?? '') === 'accepted') return (string)$d['calculated_class'];
    }
    return '';
}

function mediaCarLabel(array $c): string {
    $parts = array_filter([
        (string)($c['year'] ?? ''), (string)($c['make'] ?? $c['car_make'] ?? ''), (string)($c['model'] ?? $c['car_model'] ?? ''),
    ], fn(string $p): bool => trim($p) !== '');
    $label = implode(' ', array_map('trim', $parts));
    $colour = trim((string)($c['colour'] ?? $c['car_colour'] ?? ''));
    return $colour !== '' && $label !== '' ? "$label ($colour)" : $label;
}

function mediaEntry(array $driver, array $profile, array $sponsors, string $number, string $car, string $class, bool $publicLive): array {
    return [
        'driver_id' => (int)$driver['id'], 'name' => (string)$driver['name'],
        'pronunciation' => $profile['pronunciation'] ?? null, 'hometown' => $profile['hometown'] ?? null,
        'racing_since' => isset($profile['racing_since']) ? (int)$profile['racing_since'] : null,
        'blurb' => (string)($profile['blurb'] ?? ''), 'social_handle' => $profile['social_handle'] ?? null,
        'sponsors' => array_map(fn(array $s): array => ['name' => (string)$s['name'], 'url' => $s['url'] ?? null], $sponsors),
        'has_photo' => (string)($profile['photo_path'] ?? '') !== '',
        'number' => $number, 'car' => $car, 'class' => $class, 'public_live' => $publicLive,
    ];
}

function mediaFactsLine(array $e): string {
    return implode(' · ', array_filter([(string)($e['hometown'] ?? ''), $e['racing_since'] ? 'Racing since ' . $e['racing_since'] : '']));
}

function mediaCopyText(array $e): string {
    $head = ($e['number'] !== '' ? '#' . $e['number'] . ' ' : '') . $e['name'] . ($e['car'] !== '' ? ' — ' . $e['car'] : '');
    $lines = [$head, mediaFactsLine($e), trim($e['blurb'])];
    if ($e['sponsors']) $lines[] = 'Supported by: ' . implode(', ', array_column($e['sponsors'], 'name'));
    return implode("\n", array_filter($lines, fn(string $l): bool => $l !== ''));
}

function mediaSlug(string $s): string {
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii === false ? $s : $ascii)), '-');
    return $slug === '' ? 'driver' : $slug;
}

function mediaCsvRows(array $entries, string $baseUrl): array {
    $rows = [['number', 'name', 'pronunciation', 'hometown', 'racing_since', 'car', 'class', 'blurb', 'sponsors', 'social_handle', 'public_url']];
    foreach ($entries as $e) {
        $sponsors = implode('; ', array_map(fn(array $s): string => $s['name'] . ($s['url'] ? ' (' . $s['url'] . ')' : ''), $e['sponsors']));
        $rows[] = [$e['number'], $e['name'], (string)$e['pronunciation'], (string)$e['hometown'], (string)($e['racing_since'] ?? ''),
                   $e['car'], $e['class'], $e['blurb'], $sponsors, (string)$e['social_handle'],
                   $e['public_live'] ? rtrim($baseUrl, '/') . '/driver.php?id=' . $e['driver_id'] : ''];
    }
    return $rows;
}
```

- [ ] **Step 5: Run the tests and watch them pass**

Run: `php phpunit.phar tests/MediaLibTest.php`
Expected: PASS. If `mediaSlug('  Jane  Doé!! ')` fails on a Windows `iconv`, keep the test and replace the transliteration with `strtr($s, ['é'=>'e','è'=>'e','ê'=>'e','à'=>'a','ç'=>'c','ö'=>'o','ü'=>'u','ñ'=>'n'])` before lowercasing.

Then run `php phpunit.phar`: all green.

- [ ] **Step 6: Commit**

```bash
git add media-lib.php roles.php tests/MediaLibTest.php
git commit -m "feat(media): pure rules for consent, validation, review status and media kit output"
```

---

### Task 3: Saving, withdrawing and deleting (`media-service.php`)

**Files:**
- Create: `media-service.php`
- Test: `tests/MediaServiceTest.php`

**Interfaces:**
- Consumes:
  - Task 1 DB helpers.
  - Task 2 `mediaValidateFields`, `mediaConsentInput`, `mediaConsentChanged`, `mediaContentChanged` and `mediaNextPublicStatus`.
  - `inspectionValidateImage()` from `inspection-lib.php`.
- Produces:
  - `mediaOwnedDriver(PDO $pdo, int $userId, int $driverId): ?array`
  - `mediaSaveProfile(PDO $pdo, int $userId, int $driverId, array $post, ?string $uploadTmp, string $baseDir, ?callable $mover = null): array{ok: bool, errors: string[], status: ?string}`
  - `mediaWithdraw(PDO $pdo, int $userId, int $driverId): bool`
  - `mediaDeleteProfile(PDO $pdo, int $userId, int $driverId, string $baseDir): bool`
  - `MEDIA_PHOTO_DIR = 'uploads/media'`

- [ ] **Step 1: Write the failing tests**

Create `tests/MediaServiceTest.php`:

```php
<?php
// wcma-calculator/tests/MediaServiceTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../media-lib.php';
require_once __DIR__ . '/../media-service.php';

use PHPUnit\Framework\TestCase;

final class MediaServiceTest extends TestCase
{
    private string $base;

    protected function setUp(): void {
        $this->base = sys_get_temp_dir() . '/wcma_media_' . uniqid();
        mkdir($this->base, 0755, true);
    }

    private function user(PDO $pdo, string $email, string $name = 'Jordan Lee'): int {
        return db_create_user($pdo, ['email' => $email, 'name' => $name, 'password_hash' => 'x', 'google_id' => null]);
    }

    /** A real 1x1 PNG in a temp file, standing in for an upload. */
    private function png(): string {
        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        return $path;
    }

    public function testOnlyTheOwnerCanSave(): void
    {
        $pdo = make_temp_pdo();
        $a = $this->user($pdo, 'a@example.com', 'Ann Ames');
        $b = $this->user($pdo, 'b@example.com', 'Bo Bell');
        $db = (int)db_get_self_driver($pdo, $b)['id'];
        $r = mediaSaveProfile($pdo, $a, $db, ['blurb' => 'Hijack', 'consent_media' => '1'], null, $this->base, 'rename');
        $this->assertSame(['ok' => false, 'errors' => ['Choose one of your drivers.'], 'status' => null], $r);
        $this->assertNull(db_get_media_profile($pdo, $db));
        $this->assertNull(db_get_latest_media_consent($pdo, $db));
    }

    public function testSelfSaveWithPhotoConsentAndPublicRequest(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $d = (int)db_get_self_driver($pdo, $u)['id'];
        $r = mediaSaveProfile($pdo, $u, $d, [
            'blurb' => 'Fast.', 'sponsor_name' => ['Acme'], 'sponsor_url' => ['acme.test'],
            'consent_media' => '1', 'consent_public' => '1',
        ], $this->png(), $this->base, 'rename');
        $this->assertTrue($r['ok'], implode(' ', $r['errors']));
        $this->assertSame('pending_review', $r['status']);
        $p = db_get_media_profile($pdo, $d);
        $this->assertMatchesRegularExpression('#^uploads/media/driver-' . $d . '-[0-9a-f]{12}\.png$#', $p['photo_path']);
        $this->assertFileExists($this->base . '/' . $p['photo_path']);
        $this->assertSame('https://acme.test', db_get_sponsors($pdo, $d)[0]['url']);
        $c = db_get_latest_media_consent($pdo, $d);
        $this->assertSame([1, 1, 0, $u, MEDIA_CONSENT_WORDING_VERSION],
            [(int)$c['consent_media'], (int)$c['consent_public'], (int)$c['on_behalf'], (int)$c['given_by_user_id'], (int)$c['wording_version']]);

        // Re-saving the same thing adds no consent row and keeps the photo.
        mediaSaveProfile($pdo, $u, $d, ['blurb' => 'Fast.', 'sponsor_name' => ['Acme'], 'sponsor_url' => ['https://acme.test'],
            'consent_media' => '1', 'consent_public' => '1'], null, $this->base, 'rename');
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM media_consents")->fetchColumn());
        $this->assertSame($p['photo_path'], db_get_media_profile($pdo, $d)['photo_path']);
    }

    public function testReplacingThePhotoDeletesTheOldFileAndSendsAnAcceptedProfileBackToReview(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $d = (int)db_get_self_driver($pdo, $u)['id'];
        $post = ['blurb' => 'Fast.', 'consent_media' => '1', 'consent_public' => '1'];
        mediaSaveProfile($pdo, $u, $d, $post, $this->png(), $this->base, 'rename');
        $first = db_get_media_profile($pdo, $d)['photo_path'];
        db_set_media_public_status($pdo, $d, 'accepted', $u, null);

        $r = mediaSaveProfile($pdo, $u, $d, $post, $this->png(), $this->base, 'rename');
        $this->assertSame('pending_review', $r['status']);
        $this->assertFileDoesNotExist($this->base . '/' . $first);
        $this->assertFileExists($this->base . '/' . db_get_media_profile($pdo, $d)['photo_path']);
    }

    public function testCoDriverNeedsOnBehalfConfirmationAndRecordsIt(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $sam = db_create_driver($pdo, $u, 'Sam Patel');
        $r = mediaSaveProfile($pdo, $u, $sam, ['blurb' => 'Hi', 'consent_media' => '1'], null, $this->base, 'rename');
        $this->assertFalse($r['ok']);
        $this->assertSame(['Confirm that this driver agreed, or untick the consent box.'], $r['errors']);

        $r = mediaSaveProfile($pdo, $u, $sam, ['blurb' => 'Hi', 'consent_media' => '1', 'on_behalf_confirm' => '1',
            'is_minor' => '1', 'guardian_name' => 'Pat Patel'], null, $this->base, 'rename');
        $this->assertTrue($r['ok']);
        $c = db_get_latest_media_consent($pdo, $sam);
        $this->assertSame([1, 1, 'Pat Patel'], [(int)$c['on_behalf'], (int)$c['is_minor'], $c['guardian_name']]);
    }

    public function testValidationErrorsWriteNothingAndKeepNoFile(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $d = (int)db_get_self_driver($pdo, $u)['id'];
        $r = mediaSaveProfile($pdo, $u, $d, ['blurb' => str_repeat('a', 501)], $this->png(), $this->base, 'rename');
        $this->assertFalse($r['ok']);
        $this->assertNull(db_get_media_profile($pdo, $d));
        $this->assertDirectoryDoesNotExist($this->base . '/uploads/media');

        $bad = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($bad, 'not an image');
        $r = mediaSaveProfile($pdo, $u, $d, ['blurb' => 'ok'], $bad, $this->base, 'rename');
        $this->assertSame(['That file is not a photo we can read.'], $r['errors']);
    }

    public function testWithdrawTurnsConsentOffAndClearsPublicStatus(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $d = (int)db_get_self_driver($pdo, $u)['id'];
        mediaSaveProfile($pdo, $u, $d, ['blurb' => 'Fast.', 'consent_media' => '1', 'consent_public' => '1'], null, $this->base, 'rename');
        db_set_media_public_status($pdo, $d, 'accepted', $u, null);

        $this->assertFalse(mediaWithdraw($pdo, $u + 100, $d));
        $this->assertTrue(mediaWithdraw($pdo, $u, $d));
        $this->assertSame(['media' => false, 'public' => false], mediaCurrentConsent(db_get_latest_media_consent($pdo, $d)));
        $this->assertSame('none', db_get_media_profile($pdo, $d)['public_status']);
        $this->assertSame('Fast.', db_get_media_profile($pdo, $d)['blurb']);   // data kept
        $this->assertSame([], db_get_consented_driver_ids($pdo));
    }

    public function testDeleteRemovesPhotoProfileAndConsent(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $d = (int)db_get_self_driver($pdo, $u)['id'];
        mediaSaveProfile($pdo, $u, $d, ['blurb' => 'Fast.', 'consent_media' => '1'], $this->png(), $this->base, 'rename');
        $photo = db_get_media_profile($pdo, $d)['photo_path'];

        $this->assertTrue(mediaDeleteProfile($pdo, $u, $d, $this->base));
        $this->assertNull(db_get_media_profile($pdo, $d));
        $this->assertFileDoesNotExist($this->base . '/' . $photo);
        $this->assertFalse(mediaCurrentConsent(db_get_latest_media_consent($pdo, $d))['media']);
        $this->assertSame(2, (int)$pdo->query("SELECT COUNT(*) FROM media_consents")->fetchColumn());
    }
}
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `php phpunit.phar tests/MediaServiceTest.php`
Expected: FAIL. `media-service.php` doesn't exist yet.

- [ ] **Step 3: Create `media-service.php`**

```php
<?php
// wcma-calculator/media-service.php
//
// DB work for driver media profiles: save, withdraw, delete (this task), and the review actions and
// roster/kit loaders (added in later tasks). No session or echo; controllers pass the user id.
// Callers must have loaded db.php, roles.php, photo-requirements.php, inspection-lib.php and media-lib.php.

const MEDIA_PHOTO_DIR = 'uploads/media';

function mediaOwnedDriver(PDO $pdo, int $userId, int $driverId): ?array {
    $driver = db_get_driver($pdo, $driverId);
    return $driver !== null && (int)$driver['owner_user_id'] === $userId ? $driver : null;
}

function mediaIsSelf(array $driver, int $userId): bool {
    return (int)($driver['user_id'] ?? 0) === $userId;
}

/** @return array{ok: bool, errors: string[], status: ?string} */
function mediaSaveProfile(PDO $pdo, int $userId, int $driverId, array $post, ?string $uploadTmp, string $baseDir, ?callable $mover = null): array {
    $fail = fn(array $errors): array => ['ok' => false, 'errors' => $errors, 'status' => null];
    $driver = mediaOwnedDriver($pdo, $userId, $driverId);
    if ($driver === null) return $fail(['Choose one of your drivers.']);

    $v = mediaValidateFields($post, (int)date('Y'));
    $c = mediaConsentInput($post, mediaIsSelf($driver, $userId));
    $errors = $v['errors'];
    if (!$c['ok']) $errors[] = $c['error'];
    $image = null;
    if ($uploadTmp !== null) {
        $image = inspectionValidateImage($uploadTmp);
        if (!$image['ok']) $errors[] = $image['error'];
    }
    if ($errors) return $fail($errors);

    $profile = db_get_media_profile($pdo, $driverId);
    $sponsorsBefore = db_get_sponsors($pdo, $driverId);
    $oldPhoto = $profile['photo_path'] ?? null;
    $photoPath = $oldPhoto;
    $newAbs = null;
    if ($image !== null) {
        $photoPath = MEDIA_PHOTO_DIR . '/driver-' . $driverId . '-' . bin2hex(random_bytes(6)) . '.' . $image['ext'];
        $newAbs = $baseDir . '/' . $photoPath;
        $dir = dirname($newAbs);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) return $fail(['Could not store the photo.']);
        if (!($mover ?? 'move_uploaded_file')($uploadTmp, $newAbs)) return $fail(['Could not store the photo.']);
    }

    $status = mediaNextPublicStatus((string)($profile['public_status'] ?? 'none'), $c['consent']['consent_public'] === 1,
        mediaContentChanged($profile, $sponsorsBefore, $v['fields'], $v['sponsors'], $image !== null));

    $pdo->beginTransaction();
    try {
        db_save_media_profile($pdo, $driverId, $v['fields'] + ['photo_path' => $photoPath, 'public_status' => $status]);
        db_replace_sponsors($pdo, $driverId, $v['sponsors']);
        if (mediaConsentChanged(db_get_latest_media_consent($pdo, $driverId), $c['consent'])) {
            db_insert_media_consent($pdo, $c['consent'] + ['driver_id' => $driverId, 'given_by_user_id' => $userId,
                'wording_version' => MEDIA_CONSENT_WORDING_VERSION]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($newAbs !== null && is_file($newAbs)) unlink($newAbs);
        throw $e;
    }
    if ($image !== null && $oldPhoto && is_file($baseDir . '/' . $oldPhoto)) unlink($baseDir . '/' . $oldPhoto);
    return ['ok' => true, 'errors' => [], 'status' => $status];
}

/** Adds an all-off consent row (keeping the minor details) and takes the public page down. */
function mediaWithdraw(PDO $pdo, int $userId, int $driverId): bool {
    $driver = mediaOwnedDriver($pdo, $userId, $driverId);
    if ($driver === null) return false;
    $latest = db_get_latest_media_consent($pdo, $driverId);
    if (mediaCurrentConsent($latest)['media']) {
        db_insert_media_consent($pdo, [
            'driver_id' => $driverId, 'consent_media' => 0, 'consent_public' => 0,
            'is_minor' => (int)($latest['is_minor'] ?? 0), 'guardian_name' => $latest['guardian_name'] ?? null,
            'given_by_user_id' => $userId, 'on_behalf' => mediaIsSelf($driver, $userId) ? 0 : 1,
            'wording_version' => MEDIA_CONSENT_WORDING_VERSION,
        ]);
    }
    if (db_get_media_profile($pdo, $driverId) !== null) {
        $pdo->prepare("UPDATE driver_media_profiles SET public_status = 'none' WHERE driver_id = :d")->execute([':d' => $driverId]);
    }
    return true;
}

function mediaDeleteProfile(PDO $pdo, int $userId, int $driverId, string $baseDir): bool {
    if (!mediaWithdraw($pdo, $userId, $driverId)) return false;
    $photo = db_get_media_profile($pdo, $driverId)['photo_path'] ?? null;
    db_delete_media_profile($pdo, $driverId);
    if ($photo && is_file($baseDir . '/' . $photo)) unlink($baseDir . '/' . $photo);
    return true;
}
```

- [ ] **Step 4: Run the tests and watch them pass**

Run: `php phpunit.phar tests/MediaServiceTest.php`
Expected: PASS (7 tests).

Then run `php phpunit.phar`: all green.

- [ ] **Step 5: Commit**

```bash
git add media-service.php tests/MediaServiceTest.php
git commit -m "feat(media): save, withdraw and delete a driver media profile"
```

---

### Task 4: Session flag, Media nav and tabs

**Files:**
- Modify: `session_bootstrap.php` (`current_user()`, `login_user()`)
- Modify: `layout.php` (`hubNavItems()`, add `MEDIA_TABS` and `mediaSubnavHtml()`)
- Test: `tests/LayoutTest.php` (add tests) and `tests/MediaSourceTest.php` (new)

**Interfaces:**
- Consumes: `mediaCanAccess()` (Task 2).
- Produces:
  - `current_user()` gains `'is_media' => int`.
  - Nav item key `media`, pointing at `media.php`.
  - `MEDIA_TABS` with keys `announcer`, `kit` and `review`.
  - `mediaSubnavHtml(string $current): string`

- [ ] **Step 1: Write the failing tests**

Add to `tests/LayoutTest.php`, inside the class (look at its existing tests to see how it loads `layout.php`, and follow that):

```php
    public function testMediaNavShowsForMediaStaffAndAdminsOnly(): void
    {
        $keys = fn(?array $u): array => array_column(hubNavItems($u), 'key');
        $this->assertNotContains('media', $keys(['id' => 1, 'name' => 'A', 'role' => 'inspector', 'is_media' => 0]));
        $this->assertContains('media', $keys(['id' => 1, 'name' => 'A', 'role' => 'user', 'is_media' => 1]));
        $this->assertContains('media', $keys(['id' => 1, 'name' => 'A', 'role' => 'admin', 'is_media' => 0]));
        $admin = $keys(['id' => 1, 'name' => 'A', 'role' => 'admin', 'is_media' => 0]);
        $this->assertLessThan(array_search('admin', $admin, true), array_search('media', $admin, true));
    }

    public function testMediaTabs(): void
    {
        $html = mediaSubnavHtml('kit');
        $this->assertStringContainsString('<a href="media.php">Announcer</a>', $html);
        $this->assertStringContainsString('<span class="hub-tab-current" aria-current="page">Media kit</span>', $html);
        $this->assertStringContainsString('<a href="media.php?action=review">Public review</a>', $html);
    }
```

Create `tests/MediaSourceTest.php`. Later tasks add more tests to it.

```php
<?php
// wcma-calculator/tests/MediaSourceTest.php
//
// Source-level guards for the media controllers (they need config.php/sessions, so they cannot run here).
use PHPUnit\Framework\TestCase;

final class MediaSourceTest extends TestCase
{
    private function src(string $file): string {
        return str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../' . $file));
    }

    public function testSessionCarriesTheMediaFlag(): void
    {
        $src = $this->src('session_bootstrap.php');
        $this->assertStringContainsString("'is_media' => (int)(\$_SESSION['user_is_media'] ?? 0)", $src);
        $this->assertStringContainsString("\$_SESSION['user_is_media'] = (int)(\$user['is_media'] ?? 0);", $src);
    }
}
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `php phpunit.phar tests/LayoutTest.php tests/MediaSourceTest.php`
Expected: FAIL.

- [ ] **Step 3: Carry the flag in the session**

In `session_bootstrap.php`, `current_user()` returns:

```php
    return [
        'id'   => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'],
        'role' => $_SESSION['user_role'],
        'is_media' => (int)($_SESSION['user_is_media'] ?? 0),
    ];
```

In `login_user()`, after `$_SESSION['user_role'] = $user['role'];`, add:

```php
    $_SESSION['user_is_media'] = (int)($user['is_media'] ?? 0);
```

Check the four `login_user(` call sites in `auth.php` (lines ~126, ~170, ~317, ~409). Each must pass a full `users` row, from `db_find_user_by_*`. If one passes a hand-built array, change it to pass the DB row, re-read with `db_find_user_by_id($pdo, $id)`.

- [ ] **Step 4: Add the nav item and tabs**

In `layout.php`, `hubNavItems()`: between the inspector block and the admin block, add:

```php
    if (mediaCanAccess($user)) {
        $items[] = ['key' => 'media', 'label' => 'Media', 'href' => 'media.php', 'staff' => true];
    }
```

After `ADMIN_TABS`, add:

```php
const MEDIA_TABS = [
    'announcer' => ['media.php', 'Announcer'],
    'kit' => ['media.php?action=kit', 'Media kit'],
    'review' => ['media.php?action=review', 'Public review'],
];

function mediaSubnavHtml(string $current): string {
    return hubSubnavHtml('Media', MEDIA_TABS, $current);
}
```

`layout.php` already relies on `roles.php`, which is where `mediaCanAccess()` lives. No new require is needed.

- [ ] **Step 5: Run the tests and watch them pass**

Run: `php phpunit.phar`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add session_bootstrap.php layout.php auth.php tests/LayoutTest.php tests/MediaSourceTest.php
git commit -m "feat(media): media staff flag in the session, Media nav item and tabs"
```

---

### Task 5: Admin "Media staff" checkbox

**Files:**
- Modify: `admin.php` (router `case 'set-media'`, the `handleSetMedia()` handler, and a form in the users table)
- Test: `tests/MediaSourceTest.php` (add)

**Interfaces:**
- Consumes: `db_set_user_media()` (Task 1).

- [ ] **Step 1: Write the failing test**

Add to `MediaSourceTest`:

```php
    public function testAdminCanSetTheMediaFlagByCsrfCheckedPost(): void
    {
        $src = $this->src('admin.php');
        $this->assertMatchesRegularExpression("/case 'set-media':\s*adminRequirePost\('admin.php\?action=users'\);\s*handleSetMedia\(\\\$pdo, \\\$postId, \(\\\$_POST\['is_media'\] \?\? ''\) === '1'\);/", $src);
        $this->assertStringContainsString('function handleSetMedia(PDO $pdo, int $id, bool $on): void', $src);
        $this->assertStringContainsString('action="admin.php?action=set-media"', $src);
        $this->assertStringContainsString('name="is_media" value="1"', $src);
    }
```

- [ ] **Step 2: Run the test and watch it fail**

Run: `php phpunit.phar --filter testAdminCanSetTheMediaFlag`
Expected: FAIL.

- [ ] **Step 3: Implement it**

In `admin.php`'s `switch ($action)`, after `case 'set-name'`:

```php
    case 'set-media':
        adminRequirePost('admin.php?action=users');
        handleSetMedia($pdo, $postId, ($_POST['is_media'] ?? '') === '1');
        break;
```

Next to `handleSetName()`:

```php
function handleSetMedia(PDO $pdo, int $id, bool $on): void {
    if (db_find_user_by_id($pdo, $id) === null) {
        setFlash('Choose a valid user.', 'error');
    } else {
        db_set_user_media($pdo, $id, $on);
        setFlash('Media access ' . ($on ? 'given' : 'removed') . '. They will see the change the next time they sign in.', 'success');
    }
    header('Location: admin.php?action=users');
    exit;
}
```

In `renderUsersPage()`, in the actions cell right after the "Save name" form, add:

```php
          <form method="post" action="admin.php?action=set-media" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <label><input type="checkbox" name="is_media" value="1"<?= (int)($u['is_media'] ?? 0) === 1 ? ' checked' : '' ?>> Media staff</label>
            <button type="submit" class="btn-role">Save media</button>
          </form>
```

- [ ] **Step 4: Run the tests and watch them pass**

Run: `php phpunit.phar`
Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add admin.php tests/MediaSourceTest.php
git commit -m "feat(media): admins can give an account Media staff access"
```

---

### Task 6: The driver form (`media-profile.php`)

**Files:**
- Create: `media-profile-page.php`, `media-profile.php`, `js/media-profile.js`
- Modify: `css/hub.css` (append the media styles)
- Test: `tests/MediaProfilePageTest.php`, `tests/MediaSourceTest.php` (add)

**Interfaces:**
- Consumes: Task 2 constants, `mediaProfileStatus()` and `mediaCurrentConsent()`, and Task 3 `mediaSaveProfile()`, `mediaWithdraw()`, `mediaDeleteProfile()` and `mediaOwnedDriver()`.
- Produces: `renderMediaProfileHtml(array $vm): string`. `$vm` has these keys:
  - `driver` (row) and `isSelf` (bool);
  - `profile` (?row) and `sponsors` (a list of `{name, url}`);
  - `consent` (?row), `status` (from `mediaProfileStatus`), `errors` (string[]) and `csrf`;
  - `input` (?array: the POSTed values to re-show after an error).

- [ ] **Step 1: Write the failing tests**

Create `tests/MediaProfilePageTest.php`:

```php
<?php
// wcma-calculator/tests/MediaProfilePageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../media-lib.php';
require_once __DIR__ . '/../media-profile-page.php';

use PHPUnit\Framework\TestCase;

final class MediaProfilePageTest extends TestCase
{
    private function vm(array $o = []): array {
        return array_merge([
            'driver' => ['id' => 5, 'name' => 'Jordan <Lee>', 'owner_user_id' => 1, 'user_id' => 1], 'isSelf' => true,
            'profile' => null, 'sponsors' => [], 'consent' => null, 'status' => mediaProfileStatus(null, null),
            'errors' => [], 'csrf' => 'tok', 'input' => null,
        ], $o);
    }

    public function testEmptyFormHasEveryFieldAndBothConsentBoxes(): void
    {
        $html = renderMediaProfileHtml($this->vm());
        $this->assertStringContainsString('Jordan &lt;Lee&gt;', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('name="csrf_token" value="tok"', $html);
        $this->assertStringContainsString('name="driver_id" value="5"', $html);
        $this->assertStringContainsString('name="photo" accept="image/jpeg,image/png,image/webp"', $html);
        $this->assertStringContainsString('maxlength="500"', $html);
        $this->assertSame(6, substr_count($html, 'name="sponsor_name[]"'));
        $this->assertStringContainsString(h(MEDIA_CONSENT_MEDIA_TEXT), $html);
        $this->assertStringContainsString(h(MEDIA_CONSENT_PUBLIC_TEXT), $html);
        $this->assertStringContainsString('Reviewed by WCMA media staff before it appears.', $html);
        $this->assertStringNotContainsString('on_behalf_confirm', $html);
        $this->assertStringNotContainsString('value="withdraw"', $html);   // nothing to withdraw yet
        $this->assertStringNotContainsString('approv', strtolower($html));
    }

    public function testCoDriverFormAsksForConfirmation(): void
    {
        $html = renderMediaProfileHtml($this->vm(['isSelf' => false, 'driver' => ['id' => 6, 'name' => 'Sam Patel', 'owner_user_id' => 1, 'user_id' => null]]));
        $this->assertStringContainsString('name="on_behalf_confirm" value="1"', $html);
        $this->assertStringContainsString(h(sprintf(MEDIA_ON_BEHALF_TEXT, 'Sam Patel')), $html);
    }

    public function testSavedProfileIsPrefilledEscapedAndOffersWithdrawAndPublicLink(): void
    {
        $profile = ['driver_id' => 5, 'blurb' => '<script>x</script>', 'pronunciation' => 'Lee', 'hometown' => 'Red Deer',
            'racing_since' => 2015, 'social_handle' => 'jl', 'photo_path' => 'uploads/media/driver-5-a.jpg',
            'public_status' => 'accepted', 'hidden_at' => null, 'public_note' => null];
        $consent = ['consent_media' => 1, 'consent_public' => 1, 'is_minor' => 0, 'guardian_name' => null];
        $html = renderMediaProfileHtml($this->vm(['profile' => $profile, 'consent' => $consent,
            'sponsors' => [['name' => 'Acme', 'url' => 'https://acme.test']], 'status' => mediaProfileStatus($profile, $consent)]));
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;</textarea>', $html);
        $this->assertStringContainsString('src="media-photo.php?driver_id=5"', $html);
        $this->assertStringContainsString('value="Acme"', $html);
        $this->assertMatchesRegularExpression('/name="consent_media" value="1"[^>]*checked/', $html);
        $this->assertStringContainsString('value="withdraw"', $html);
        $this->assertStringContainsString('href="driver.php?id=5"', $html);
        $this->assertStringContainsString('Public page live', $html);
    }

    public function testSentBackAndHiddenShowTheReason(): void
    {
        $consent = ['consent_media' => 1, 'consent_public' => 1, 'is_minor' => 0, 'guardian_name' => null];
        $sent = ['driver_id' => 5, 'blurb' => 'x', 'photo_path' => null, 'public_status' => 'sent_back', 'hidden_at' => null,
            'public_note' => 'Brighter photo <please>', 'hidden_reason' => null];
        $html = renderMediaProfileHtml($this->vm(['profile' => $sent, 'consent' => $consent, 'status' => mediaProfileStatus($sent, $consent)]));
        $this->assertStringContainsString('Brighter photo &lt;please&gt;', $html);

        $hidden = ['hidden_at' => '2026-09-27', 'hidden_reason' => 'Sponsor dispute'] + $sent;
        $html = renderMediaProfileHtml($this->vm(['profile' => $hidden, 'consent' => $consent, 'status' => mediaProfileStatus($hidden, $consent)]));
        $this->assertStringContainsString('Hidden by WCMA', $html);
        $this->assertStringContainsString('Sponsor dispute', $html);
    }

    public function testErrorsAndPostedInputAreShownBack(): void
    {
        $html = renderMediaProfileHtml($this->vm(['errors' => ['Keep the blurb to 500 characters or fewer.'],
            'input' => ['blurb' => 'Typed <text>', 'hometown' => 'Olds', 'consent_media' => '1', 'is_minor' => '1', 'guardian_name' => 'Pat']]));
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('Keep the blurb to 500 characters or fewer.', $html);
        $this->assertStringContainsString('Typed &lt;text&gt;</textarea>', $html);
        $this->assertStringContainsString('value="Olds"', $html);
        $this->assertStringContainsString('value="Pat"', $html);
    }
}
```

Add to `MediaSourceTest`:

```php
    public function testMediaProfileControllerGuardsOwnershipAndCsrf(): void
    {
        $src = $this->src('media-profile.php');
        $this->assertStringContainsString("require_role('user')", $src);
        $this->assertMatchesRegularExpression("/REQUEST_METHOD'\] === 'POST'\) \{\s*if \(!validateCsrfToken\(/", $src);
        $this->assertStringContainsString('mediaOwnedDriver($pdo, $uid, $driverId)', $src);
        $this->assertStringContainsString('http_response_code(404)', $src);
        $this->assertStringContainsString('UPLOAD_ERR_NO_FILE', $src);
        $this->assertStringContainsString('js/photo-resize.js', $src);
    }
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `php phpunit.phar tests/MediaProfilePageTest.php tests/MediaSourceTest.php`
Expected: FAIL.

- [ ] **Step 3: Create `media-profile-page.php`**

```php
<?php
// wcma-calculator/media-profile-page.php
//
// Markup for a driver's media profile form. Pure: no DB, no session, no echo. Callers must have
// loaded view_helpers.php (h()) and media-lib.php.

function mediaFormValue(array $vm, string $key): string {
    if ($vm['input'] !== null) return is_string($vm['input'][$key] ?? null) ? $vm['input'][$key] : '';
    return (string)($vm['profile'][$key] ?? '');
}

function mediaFormChecked(array $vm, string $key): bool {
    if ($vm['input'] !== null) return ($vm['input'][$key] ?? '') === '1';
    $c = $vm['consent'];
    if ($c === null) return false;
    return match ($key) {
        'consent_media' => (int)$c['consent_media'] === 1,
        'consent_public' => (int)$c['consent_media'] === 1 && (int)$c['consent_public'] === 1,
        'is_minor' => (int)$c['is_minor'] === 1,
        default => false,
    };
}

function mediaTextField(array $vm, string $key, string $label, string $hint, int $max, string $type = 'text'): string {
    return '<label for="mp-' . $key . '">' . h($label) . '</label>'
        . ($hint !== '' ? '<p class="form-hint" id="mp-' . $key . '-hint">' . h($hint) . '</p>' : '')
        . '<input type="' . $type . '" id="mp-' . $key . '" name="' . $key . '" maxlength="' . $max . '" value="' . h(mediaFormValue($vm, $key)) . '"'
        . ($hint !== '' ? ' aria-describedby="mp-' . $key . '-hint"' : '') . '>';
}

function renderMediaProfileHtml(array $vm): string {
    $d = $vm['driver'];
    $id = (int)$d['id'];
    $name = (string)$d['name'];
    $csrf = h((string)$vm['csrf']);
    $p = $vm['profile'];
    $status = $vm['status'];
    $hidden = fn(string $n, string $v): string => '<input type="hidden" name="' . $n . '" value="' . h($v) . '">';

    $out = '<p><a href="drivers.php">&larr; Drivers</a></p>'
        . '<h1>Media profile: ' . h($name) . '</h1>'
        . '<p class="hub-intro">Clubs use this for announcing at events and for promotion. You choose whether it also goes on a public web page.</p>'
        . '<p>Status: <span class="hub-status ' . h($status['class']) . '">' . h($status['label']) . '</span>';
    if ($status['state'] === 'accepted') $out .= ' <a href="driver.php?id=' . $id . '">View public page</a>';
    $out .= '</p>';
    if ($status['state'] === 'sent_back' && (string)($p['public_note'] ?? '') !== '') {
        $out .= '<div class="hub-card media-note"><strong>WCMA media staff sent your public page back:</strong> ' . h((string)$p['public_note']) . '</div>';
    }
    if ($status['state'] === 'hidden') {
        $out .= '<div class="hub-card media-note"><strong>Hidden by WCMA.</strong> ' . h((string)($p['hidden_reason'] ?? ''))
            . ' It is not used anywhere until WCMA media staff unhide it.</div>';
    }
    if ($vm['errors']) {
        $out .= '<div class="form-messages show error" role="alert"><ul>';
        foreach ($vm['errors'] as $e) $out .= '<li>' . h($e) . '</li>';
        $out .= '</ul></div>';
    }

    $out .= '<form method="post" action="media-profile.php?driver_id=' . $id . '" enctype="multipart/form-data" class="hub-card media-form" id="media-form">'
        . $hidden('csrf_token', (string)$vm['csrf']) . $hidden('action', 'save') . $hidden('driver_id', (string)$id)
        . '<h2>Photo</h2>'
        . '<img class="media-photo-preview" id="mp-preview" alt="' . h($name) . '"'
        . ((string)($p['photo_path'] ?? '') !== '' ? ' src="media-photo.php?driver_id=' . $id . '"' : ' hidden') . '>'
        . '<label for="mp-photo">' . ((string)($p['photo_path'] ?? '') !== '' ? 'Replace photo' : 'Add a photo') . '</label>'
        . '<p class="form-hint" id="mp-photo-hint">A clear head-and-shoulders shot or a photo of you with the car. JPEG, PNG or WebP.</p>'
        . '<input type="file" id="mp-photo" name="photo" accept="image/jpeg,image/png,image/webp" aria-describedby="mp-photo-hint">'
        . '<h2>About you</h2>'
        . '<label for="mp-blurb">Blurb</label>'
        . '<p class="form-hint" id="mp-blurb-hint">Written so an announcer can read it out. Up to 500 characters.</p>'
        . '<textarea id="mp-blurb" name="blurb" rows="5" maxlength="500" aria-describedby="mp-blurb-hint mp-blurb-count">' . h(mediaFormValue($vm, 'blurb')) . '</textarea>'
        . '<p class="form-hint" id="mp-blurb-count" aria-live="polite"></p>'
        . mediaTextField($vm, 'pronunciation', 'How to say your name (optional)', 'For example "Sin-field".', 60)
        . mediaTextField($vm, 'hometown', 'Hometown (optional)', '', 60)
        . mediaTextField($vm, 'racing_since', 'Racing since (optional)', 'A year, like 2015.', 4)
        . mediaTextField($vm, 'social_handle', 'Social media handle (optional)', 'Shown on the public page and in the media kit.', 60)
        . '<h2>Sponsors (optional)</h2><p class="form-hint">Up to 6. The website is optional.</p><div class="media-sponsors">';
    $sponsors = $vm['sponsors'];
    for ($i = 0; $i < MEDIA_MAX_SPONSORS; $i++) {
        $nameVal = $vm['input'] !== null ? (string)(($vm['input']['sponsor_name'] ?? [])[$i] ?? '') : (string)($sponsors[$i]['name'] ?? '');
        $urlVal = $vm['input'] !== null ? (string)(($vm['input']['sponsor_url'] ?? [])[$i] ?? '') : (string)($sponsors[$i]['url'] ?? '');
        $n = $i + 1;
        $out .= '<div class="media-sponsor-row">'
            . '<label for="mp-sn-' . $n . '">Sponsor ' . $n . '</label><input type="text" id="mp-sn-' . $n . '" name="sponsor_name[]" maxlength="80" value="' . h($nameVal) . '">'
            . '<label for="mp-su-' . $n . '">Website</label><input type="text" id="mp-su-' . $n . '" name="sponsor_url[]" maxlength="200" inputmode="url" value="' . h($urlVal) . '">'
            . '</div>';
    }
    $check = fn(string $n, string $text, bool $on, string $extra = ''): string => '<label class="media-check"><input type="checkbox" name="' . $n . '" value="1"'
        . ($on ? ' checked' : '') . $extra . '> ' . h($text) . '</label>';
    $out .= '</div><h2>Consent</h2>'
        . $check('consent_media', MEDIA_CONSENT_MEDIA_TEXT, mediaFormChecked($vm, 'consent_media'))
        . $check('consent_public', MEDIA_CONSENT_PUBLIC_TEXT, mediaFormChecked($vm, 'consent_public'))
        . '<p class="form-hint" id="mp-public-note">Reviewed by WCMA media staff before it appears.</p>'
        . $check('is_minor', MEDIA_CONSENT_MINOR_TEXT, mediaFormChecked($vm, 'is_minor'))
        . '<div id="mp-guardian"><label for="mp-guardian_name">Parent or guardian name</label>'
        . '<input type="text" id="mp-guardian_name" name="guardian_name" maxlength="100" value="' . h($vm['input'] !== null ? mediaFormValue($vm, 'guardian_name') : (string)($vm['consent']['guardian_name'] ?? '')) . '"></div>';
    if (!$vm['isSelf']) {
        $out .= $check('on_behalf_confirm', sprintf(MEDIA_ON_BEHALF_TEXT, $name), false);
    }
    $out .= '<p class="form-hint">Without the first box ticked, your profile is saved but not used anywhere.</p>'
        . '<button type="submit" class="hub-btn">Save profile</button></form>';

    if (mediaCurrentConsent($vm['consent'])['media']) {
        $out .= '<form method="post" action="media-profile.php?driver_id=' . $id . '" class="hub-line" data-confirm="Withdraw consent? Your profile stops being used straight away.">'
            . $hidden('csrf_token', (string)$vm['csrf']) . $hidden('action', 'withdraw') . $hidden('driver_id', (string)$id)
            . '<button type="submit" class="hub-btn hub-btn--secondary">Withdraw consent</button></form>';
    }
    if ($p !== null) {
        $out .= '<form method="post" action="media-profile.php?driver_id=' . $id . '" class="hub-line" data-confirm="Delete this media profile and its photo? This can\'t be undone.">'
            . $hidden('csrf_token', (string)$vm['csrf']) . $hidden('action', 'delete') . $hidden('driver_id', (string)$id)
            . '<button type="submit" class="hub-btn hub-btn--link">Delete profile</button></form>';
    }
    return $out;
}
```

- [ ] **Step 4: Create `media-profile.php`**

```php
<?php
// wcma-calculator/media-profile.php — a driver's media profile (spec 2026-09-27 §3). Only the account
// that manages the driver profile may open it; anyone else gets a 404.
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/photo-requirements.php';
require __DIR__ . '/inspection-lib.php';
require __DIR__ . '/media-lib.php';
require __DIR__ . '/media-service.php';
require __DIR__ . '/media-profile-page.php';

date_default_timezone_set('America/Denver');
$pdo = db_connect();
db_init($pdo);
$user = require_role('user');
$uid = (int)$user['id'];
$driverId = (int)($_POST['driver_id'] ?? $_GET['driver_id'] ?? 0);
$driver = mediaOwnedDriver($pdo, $uid, $driverId);
if ($driver === null) {
    http_response_code(404);
    renderPageStart('Not found', 'drivers');
    echo '<h1>Driver not found</h1><p><a href="drivers.php">Back to Drivers</a></p>';
    renderPageEnd();
    exit;
}

$errors = [];
$input = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
    $back = 'media-profile.php?driver_id=' . $driverId;
    switch ($_POST['action'] ?? '') {
        case 'save':
            $file = $_FILES['photo'] ?? null;
            $err = is_array($file) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
            if ($err !== UPLOAD_ERR_OK && $err !== UPLOAD_ERR_NO_FILE) {
                $r = ['ok' => false, 'errors' => ['The photo could not be uploaded (2 MB maximum). Try a smaller one.']];
            } else {
                $r = mediaSaveProfile($pdo, $uid, $driverId, $_POST, $err === UPLOAD_ERR_OK ? (string)$file['tmp_name'] : null, __DIR__);
            }
            if ($r['ok']) {
                $msg = match ($r['status']) {
                    'pending_review' => 'Profile saved. WCMA media staff will review it before it goes on the public page.',
                    default => 'Profile saved.',
                };
                setFlash($msg, 'success');
                header('Location: ' . $back);
                exit;
            }
            $errors = $r['errors'];
            $input = $_POST;
            break;
        case 'withdraw':
            mediaWithdraw($pdo, $uid, $driverId);
            setFlash('Consent withdrawn. The profile is no longer used anywhere.', 'success');
            header('Location: ' . $back);
            exit;
        case 'delete':
            mediaDeleteProfile($pdo, $uid, $driverId, __DIR__);
            setFlash('Media profile deleted.', 'success');
            header('Location: drivers.php');
            exit;
    }
}

$profile = db_get_media_profile($pdo, $driverId);
$consent = db_get_latest_media_consent($pdo, $driverId);
renderPageStart('Media profile', 'drivers', ['flash' => getFlash()]);
echo renderMediaProfileHtml([
    'driver' => $driver, 'isSelf' => mediaIsSelf($driver, $uid), 'profile' => $profile,
    'sponsors' => db_get_sponsors($pdo, $driverId), 'consent' => $consent,
    'status' => mediaProfileStatus($profile, $consent), 'errors' => $errors, 'csrf' => generateCsrfToken(), 'input' => $input,
]);
renderPageEnd(['scripts' => '<script src="js/photo-resize.js"></script><script src="js/media-profile.js"></script>']);
```

- [ ] **Step 5: Create `js/media-profile.js`**

```js
// wcma-calculator/js/media-profile.js
//
// Media profile form: shrink the picked photo in the browser (the server takes 2 MB at most),
// preview it, count blurb characters, show the guardian field only for minors, and confirm
// withdraw/delete. Without JS the form still works; big photos are then refused by the server.
(function () {
    const form = document.getElementById('media-form');
    if (!form) return;

    const photo = document.getElementById('mp-photo');
    const preview = document.getElementById('mp-preview');
    photo.addEventListener('change', async function () {
        const file = photo.files && photo.files[0];
        if (!file) return;
        try {
            const blob = await window.WcmaPhotoResize.resizeToJpeg(file, { maxEdge: 1600, quality: 0.85 });
            const resized = new File([blob], 'photo.jpg', { type: 'image/jpeg' });
            const dt = new DataTransfer();
            dt.items.add(resized);
            photo.files = dt.files;
            preview.src = URL.createObjectURL(resized);
            preview.hidden = false;
        } catch (e) {
            // Keep the original file; the server will say if it is too large.
        }
    });

    const blurb = document.getElementById('mp-blurb');
    const count = document.getElementById('mp-blurb-count');
    const updateCount = function () { count.textContent = blurb.value.length + ' of 500 characters'; };
    blurb.addEventListener('input', updateCount);
    updateCount();

    const minor = form.querySelector('input[name="is_minor"]');
    const guardian = document.getElementById('mp-guardian');
    const syncMinor = function () { guardian.hidden = !minor.checked; };
    minor.addEventListener('change', syncMinor);
    syncMinor();

    document.querySelectorAll('form[data-confirm]').forEach(function (f) {
        f.addEventListener('submit', function (ev) {
            if (!window.confirm(f.getAttribute('data-confirm'))) ev.preventDefault();
        });
    });
})();
```

- [ ] **Step 6: Add the styles**

Append to `css/hub.css`:

```css
/* ── Driver media profiles ─────────────────────────────────────────────── */
.media-form h2 { margin-top: 1.5rem; }
.media-photo-preview { width: 160px; height: 160px; object-fit: cover; border-radius: 8px; display: block; margin-bottom: .5rem; }
.media-sponsor-row { display: grid; grid-template-columns: 1fr; gap: .25rem; margin-bottom: .75rem; }
@media (min-width: 700px) { .media-sponsor-row { grid-template-columns: auto 1fr auto 1fr; align-items: center; gap: .5rem; } }
.media-check { display: flex; gap: .5rem; align-items: flex-start; margin: .75rem 0 .25rem; font-weight: 500; }
.media-check input { margin-top: .25rem; flex: none; width: 1.25rem; height: 1.25rem; }
.media-note { border-left: 4px solid var(--hub-warn); }
```

- [ ] **Step 7: Run the tests and watch them pass**

Run: `php phpunit.phar`
Expected: all green.

- [ ] **Step 8: Check it in the browser**

1. Run `php -S localhost:8080` from `wcma-calculator/`. Sign in as `jordan@example.com`, open `media-profile.php?driver_id=<Jordan's self driver id>`, then save with a photo, a blurb and both consents.
2. Expect: the flash says the profile will be reviewed, the preview shows the photo, and the status reads "Public page: waiting for review".
3. Tick "under 18" and check the guardian field appears.
4. Open another account's driver id and check you get "Driver not found" with a 404.

- [ ] **Step 9: Commit**

```bash
git add media-profile.php media-profile-page.php js/media-profile.js css/hub.css tests/MediaProfilePageTest.php tests/MediaSourceTest.php
git commit -m "feat(media): drivers can write a media profile, upload a photo and give consent"
```

---

### Task 7: Photo serving (`media-photo.php`)

**Files:**
- Create: `media-photo.php`
- Test: `tests/MediaSourceTest.php` (add)

**Interfaces:**
- Consumes: `mediaPhotoAllowed()` (Task 2) and `current_user()`.

- [ ] **Step 1: Write the failing test**

```php
    public function testPhotoEndpointChecksAccessAndNeverRevealsWhy(): void
    {
        $src = $this->src('media-photo.php');
        $this->assertStringContainsString('mediaPhotoAllowed(current_user(), $driver, $profile, db_get_latest_media_consent($pdo, $driverId))', $src);
        $this->assertStringContainsString("header('X-Content-Type-Options: nosniff');", $src);
        $this->assertStringContainsString("header('Cache-Control: private, max-age=0, must-revalidate');", $src);
        $this->assertSame(1, substr_count($src, 'readfile('));
        $this->assertStringContainsString("str_starts_with(\$path, MEDIA_PHOTO_DIR . '/')", $src);
    }
```

- [ ] **Step 2: Run the test and watch it fail**

Run: `php phpunit.phar --filter testPhotoEndpoint`
Expected: FAIL.

- [ ] **Step 3: Create `media-photo.php`**

```php
<?php
// wcma-calculator/media-photo.php — streams a driver's media photo. uploads/ is Deny-from-all, so
// this is the only way in. The owner and Media staff always see it; everyone else only while the
// public page is live. Every refusal is the same bare 404.
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/media-lib.php';
require __DIR__ . '/photo-requirements.php';
require __DIR__ . '/inspection-lib.php';
require __DIR__ . '/media-service.php';

$pdo = db_connect();
db_init($pdo);
$driverId = (int)($_GET['driver_id'] ?? 0);
$driver = db_get_driver($pdo, $driverId);
$profile = $driver !== null ? db_get_media_profile($pdo, $driverId) : null;
$path = (string)($profile['photo_path'] ?? '');
$abs = __DIR__ . '/' . $path;
if ($driver === null || !str_starts_with($path, MEDIA_PHOTO_DIR . '/') || !is_file($abs)
    || !mediaPhotoAllowed(current_user(), $driver, $profile, db_get_latest_media_consent($pdo, $driverId))) {
    http_response_code(404);
    exit;
}
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . ($types[strtolower(pathinfo($abs, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($abs));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($abs);
```

- [ ] **Step 4: Run the tests and watch them pass**

Run: `php phpunit.phar`
Expected: all green.

Manual check:
- Signed in as Jordan, `media-photo.php?driver_id=<id>` shows the photo.
- In a private window, before acceptance, it's a 404.

- [ ] **Step 5: Commit**

```bash
git add media-photo.php tests/MediaSourceTest.php
git commit -m "feat(media): serve media photos only to the owner, media staff, or a live public page"
```

---

### Task 8: Drivers page status and the Home prompt

**Files:**
- Modify: `drivers-lib.php` (`driversRows()`), `drivers-page.php` (`driversRenderRow()`), `drivers.php`
- Modify: `home-page.php` (`renderHomeHtml()` and the new `homeMediaPromptHtml()`), `index.php`
- Test: `tests/DriversPageTest.php`, `tests/HomePageTest.php`, `tests/MediaSourceTest.php`

**Interfaces:**
- Consumes: `mediaProfileStatus()`, `mediaCurrentConsent()` and `db_get_media_bundle()`.
- Produces:
  - `driversRows(array $drivers, array $gear, int $selfId, int $season, array $media = []): array`. Each row gains a `media` key holding the `mediaProfileStatus()` result.
  - `renderHomeHtml()` accepts `$vm['mediaPrompt']` (bool).
  - `homeMediaPromptHtml(string $csrf): string`

- [ ] **Step 1: Write the failing tests**

In `tests/DriversPageTest.php`:
- Add `require_once __DIR__ . '/../media-lib.php';` after the other requires. `driversRows()` now calls `mediaProfileStatus()`, and `media-lib.php` needs `roles.php`, which the bootstrap already loads.
- Add this test:

```php
    public function testEachDriverShowsItsMediaProfileStatusAndLink(): void
    {
        $consent = ['consent_media' => 1, 'consent_public' => 0, 'is_minor' => 0, 'guardian_name' => null];
        $rows = driversRows(
            [['id' => 1, 'name' => 'Jordan Lee', 'licence_no' => null], ['id' => 2, 'name' => 'Sam Patel', 'licence_no' => null]],
            [], 1, 2026,
            [1 => ['profile' => ['blurb' => 'Fast.', 'photo_path' => null, 'hidden_at' => null, 'public_status' => 'none'], 'consent' => $consent, 'sponsors' => []]]
        );
        $html = renderDriversHtml($this->vm([], ['rows' => $rows]));
        $this->assertStringContainsString('Shared with clubs', $html);
        $this->assertStringContainsString('href="media-profile.php?driver_id=1">Edit media profile</a>', $html);
        $this->assertStringContainsString('Not set up', $html);
        $this->assertStringContainsString('href="media-profile.php?driver_id=2">Set up media profile</a>', $html);
    }
```

In `tests/HomePageTest.php`, add a test next to the existing ones. Build the `$vm` the same way the file's other tests do, with `'mediaPrompt' => true`:

```php
    public function testMediaPromptCardAppearsOnlyWhenAsked(): void
    {
        $html = homeMediaPromptHtml('tok');
        $this->assertStringContainsString('Clubs would like to feature you', $html);
        $this->assertStringContainsString('href="media-profile.php?driver_id=self"', $html);
        $this->assertStringContainsString('name="action" value="media-prompt-dismiss"', $html);
        $this->assertStringContainsString('name="csrf_token" value="tok"', $html);
    }
```

Then add to one existing `renderHomeHtml` test in that file:
- assert that `'Clubs would like to feature you'` is **absent** when `mediaPrompt` isn't set;
- add a second render with `'mediaPrompt' => true` and assert it is **present**, before `'<h2>At a glance</h2>'`.

Add to `MediaSourceTest`:

```php
    public function testHomeDismissesThePromptAndDriversPageLoadsMediaStatus(): void
    {
        $index = $this->src('index.php');
        $this->assertStringContainsString("case 'media-prompt-dismiss':", $index);
        $this->assertStringContainsString('db_dismiss_media_prompt($pdo, $uid);', $index);
        $this->assertStringContainsString("'mediaPrompt' =>", $index);
        $drivers = $this->src('drivers.php');
        $this->assertStringContainsString('db_get_media_bundle($pdo, array_map(fn(array $d): int => (int)$d[\'id\'], $drivers))', $drivers);
        $profile = $this->src('media-profile.php');
        $this->assertStringContainsString("=== 'self'", $profile);
    }
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `php phpunit.phar tests/DriversPageTest.php tests/HomePageTest.php tests/MediaSourceTest.php`
Expected: FAIL.

- [ ] **Step 3: Drivers page**

In `drivers-lib.php`:

```php
/** @param array $gear driver id => that driver's gear_records row for $season
 *  @param array $media driver id => db_get_media_bundle() entry */
function driversRows(array $drivers, array $gear, int $selfId, int $season, array $media = []): array {
    $rows = [];
    foreach ($drivers as $d) {
        $id = (int)$d['id'];
        $status = isset($gear[$id]) ? gearStatus($gear[$id]) : ['state' => 'none', 'via' => null];
        $rows[] = ['driver' => $d, 'isSelf' => $id === $selfId, 'state' => $status['state'],
                   'label' => driversGearLabel($status, $season), 'action' => driversGearAction($id, $status),
                   'media' => mediaProfileStatus($media[$id]['profile'] ?? null, $media[$id]['consent'] ?? null)];
    }
    return $rows;
}
```

Also update the file's header comment. `drivers-lib.php` now also needs `media-lib.php` loaded by the caller.

In `drivers-page.php` `driversRenderRow()`, just before the `if ($row['isSelf'])` hint, add:

```php
    $m = $row['media'];
    $out .= '<p class="hub-line">Media profile: <span class="hub-status ' . h($m['class']) . '">' . h($m['label']) . '</span> '
        . '<a href="media-profile.php?driver_id=' . $id . '">' . ($m['state'] === 'none' ? 'Set up media profile' : 'Edit media profile') . '</a></p>';
```

In `drivers.php`:
- add `require __DIR__ . '/media-lib.php';` before `drivers-lib.php`;
- replace the `driversRows(...)` call with:

```php
    'rows' => driversRows($drivers, $gear, $self !== null ? (int)$self['id'] : 0, $season,
        db_get_media_bundle($pdo, array_map(fn(array $d): int => (int)$d['id'], $drivers))),
```

- [ ] **Step 4: Home prompt**

In `home-page.php`, add:

```php
/** One-time invitation to add a media profile (spec 2026-09-27 §3). Not part of readiness. */
function homeMediaPromptHtml(string $csrf): string {
    return '<div class="hub-card media-prompt"><h2>Clubs would like to feature you</h2>'
        . '<p>Add a photo and a line about yourself. Announcers read it out at events, and clubs use it to promote racing. You choose whether it goes on a public page.</p>'
        . '<p><a class="hub-btn" href="media-profile.php?driver_id=self">Add profile</a></p>'
        . '<form method="post" action="index.php"><input type="hidden" name="csrf_token" value="' . h($csrf) . '">'
        . '<input type="hidden" name="action" value="media-prompt-dismiss">'
        . '<button type="submit" class="hub-btn hub-btn--link">No thanks</button></form></div>';
}
```

In `renderHomeHtml()`, immediately before `$out .= '<h2>At a glance</h2>...`, add:

```php
    if (!empty($vm['mediaPrompt'])) $out .= homeMediaPromptHtml($csrf);
```

In `index.php`:
- add `require __DIR__ . '/media-lib.php';` after `readiness-lib.php`;
- in the POST switch, add:

```php
        case 'media-prompt-dismiss':
            db_dismiss_media_prompt($pdo, $uid);
            setFlash('OK. You can add one any time from the Drivers page.', 'success');
            break;
```

Then, in the signed-in branch before `renderPageStart('Home', ...)`:

```php
$userRow = db_find_user_by_id($pdo, $uid);
$selfDriver = db_get_self_driver($pdo, $uid);
$mediaPrompt = $selfDriver !== null && (int)($userRow['media_prompt_dismissed'] ?? 0) === 0
    && !mediaCurrentConsent(db_get_latest_media_consent($pdo, (int)$selfDriver['id']))['media'];
```

Finally, add `'mediaPrompt' => $mediaPrompt,` to the `renderHomeHtml([...])` array. Reuse `$userRow` for `offerReminders` (`remindersShouldOffer($userRow)`) instead of calling `db_find_user_by_id` twice.

- [ ] **Step 5: Let `media-profile.php` accept `driver_id=self`**

In `media-profile.php`, replace the `$driverId = ...` line with:

```php
$rawDriver = $_POST['driver_id'] ?? $_GET['driver_id'] ?? '';
$driverId = $rawDriver === 'self' ? (int)(db_get_self_driver($pdo, $uid)['id'] ?? 0) : (int)$rawDriver;
```

- [ ] **Step 6: Run the tests and watch them pass**

Run: `php phpunit.phar`
Expected: all green, including the unchanged `DriversPageTest` and `HomePageTest` cases.

- [ ] **Step 7: Commit**

```bash
git add drivers-lib.php drivers-page.php drivers.php home-page.php index.php media-profile.php tests/DriversPageTest.php tests/HomePageTest.php tests/MediaSourceTest.php
git commit -m "feat(media): media status on the Drivers page and a one-time Home invitation"
```

---

### Task 9: The Media section: Announcer and Media kit tabs

**Files:**
- Modify: `media-service.php` (add the loaders)
- Create: `media-page.php` (announcer and kit renderers; Task 10 adds the review renderer and Task 11 the public page renderer)
- Create: `media.php`, `js/media-kit.js`
- Modify: `css/hub.css` (announcer, kit and print styles)
- Test: `tests/MediaServiceTest.php` (add), `tests/MediaPageTest.php` (new), `tests/MediaSourceTest.php` (add)

**Interfaces:**
- Consumes: `db_get_event_roster_cars`, `db_get_event_tech_sheets`, `db_get_car_latest_tech_sheet_id`, `db_get_tech_sheet`, `db_get_drivers_for_sheets`, `db_get_self_drivers_for_users`, `db_get_declarations_for_cars`, `db_get_media_bundle`, `db_get_consented_driver_ids`, `db_get_driver_latest_sheet` and `db_search_media_profiles`, plus the Task 2 helpers.
- Produces:
  - `mediaAnnouncerRoster(PDO $pdo, int $eventId): array`: a list of `['number' => string, 'car' => string, 'class' => string, 'drivers' => [['name' => string, 'entry' => ?array]]]`.
  - `mediaEntriesForDrivers(PDO $pdo, array $driverIds, int $season): array`: `mediaEntry` arrays for drivers usable in the `'club'` output, with number, car and class from `db_get_driver_latest_sheet()`, in the order given.
  - `mediaKitEntries(PDO $pdo, int $eventId, int $season): array`: `$eventId` 0 means every consented driver; otherwise the drivers on that event's roster who have an entry.
  - `renderMediaEntryHtml(array $e, bool $forKit): string`
  - `renderAnnouncerHtml(array $vm): string`: `$vm` keys `events`, `eventId`, `roster`, `q` and `extra` (entries found by search).
  - `renderMediaKitHtml(array $vm): string`: `$vm` keys `events`, `eventId`, `entries` and `zip` (bool).

- [ ] **Step 1: Write the failing tests**

Add to `tests/MediaServiceTest.php`:

```php
    public function testAnnouncerRosterUsesSheetsThenOwnerAndOnlyShowsConsentedProfiles(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $e = db_create_event($pdo, 'Fall Sprint', date('Y') . '-10-11', null);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42'));
        db_accept_declaration($pdo, $sub, $u);
        $sheet = test_make_sheet($pdo, $u, $sub, $e, '42', 'Jordan Lee');
        db_add_tech_sheet_driver($pdo, $sheet, 2, 'Sam Patel', '{}');
        $car7 = test_make_car($pdo, $u, '7');
        db_tag_event($pdo, $u, $e, $car7);   // tagged, never sheeted: falls back to the owner

        $self = (int)db_get_self_driver($pdo, $u)['id'];
        mediaSaveProfile($pdo, $u, $self, ['blurb' => 'Fast.', 'consent_media' => '1'], null, $this->base, 'rename');

        $roster = mediaAnnouncerRoster($pdo, $e);
        $this->assertSame(['7', '42'], array_column($roster, 'number'));
        $this->assertSame('IT1', $roster[1]['class']);
        $this->assertSame(['Jordan Lee', 'Sam Patel'], array_column($roster[1]['drivers'], 'name'));
        $this->assertSame('Fast.', $roster[1]['drivers'][0]['entry']['blurb']);
        $this->assertNull($roster[1]['drivers'][1]['entry']);   // Sam has no consent
        $this->assertSame('Jordan Lee', $roster[0]['drivers'][0]['name']);
        $this->assertSame('', $roster[0]['class']);   // no sheet and no accepted declaration
    }

    public function testKitEntriesListConsentedDriversWithTheirLatestCar(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $e = db_create_event($pdo, 'Fall Sprint', date('Y') . '-10-11', null);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42'));
        test_make_sheet($pdo, $u, $sub, $e, '42', 'Jordan Lee');
        $self = (int)db_get_self_driver($pdo, $u)['id'];
        mediaSaveProfile($pdo, $u, $self, ['blurb' => 'Fast.', 'consent_media' => '1'], null, $this->base, 'rename');
        $other = $this->user($pdo, 'o@example.com', 'Olive Odd');   // no consent

        $all = mediaKitEntries($pdo, 0, (int)date('Y'));
        $this->assertSame([$self], array_column($all, 'driver_id'));
        $this->assertSame('42', $all[0]['number']);
        $this->assertSame('Mazda MX-5 (Red)', $all[0]['car']);
        $this->assertSame([$self], array_column(mediaKitEntries($pdo, $e, (int)date('Y')), 'driver_id'));

        mediaWithdraw($pdo, $u, $self);
        $this->assertSame([], mediaKitEntries($pdo, 0, (int)date('Y')));
    }
```

Create `tests/MediaPageTest.php`:

```php
<?php
// wcma-calculator/tests/MediaPageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../media-lib.php';
require_once __DIR__ . '/../media-page.php';

use PHPUnit\Framework\TestCase;

final class MediaPageTest extends TestCase
{
    private function entry(array $o = []): array {
        return array_merge(mediaEntry(['id' => 5, 'name' => 'Jane <Doe>'], ['blurb' => 'Loves <b>hairpins</b>.', 'hometown' => 'Red Deer, AB',
            'racing_since' => 2015, 'photo_path' => 'uploads/media/x.jpg', 'pronunciation' => 'Doh', 'social_handle' => 'janed'],
            [['name' => 'Acme', 'url' => 'https://acme.test/?a=1&b=2']], '42', '2004 Honda S2000 (Silver)', 'GT3', true), $o);
    }

    public function testEntryCardEscapesEverythingAndLinksSponsors(): void
    {
        $html = renderMediaEntryHtml($this->entry(), false);
        $this->assertStringContainsString('Jane &lt;Doe&gt;', $html);
        $this->assertStringContainsString('Loves &lt;b&gt;hairpins&lt;/b&gt;.', $html);
        $this->assertStringContainsString('src="media-photo.php?driver_id=5"', $html);
        $this->assertStringContainsString('Say it: Doh', $html);
        $this->assertStringContainsString('href="https://acme.test/?a=1&amp;b=2"', $html);
        $this->assertStringContainsString('rel="sponsored noopener"', $html);
        $this->assertStringNotContainsString('data-copy', $html);
    }

    public function testKitCardHasCopyTextAndPhotoDownload(): void
    {
        $html = renderMediaEntryHtml($this->entry(), true);
        $this->assertStringContainsString('data-copy', $html);
        $this->assertStringContainsString(h(mediaCopyText($this->entry())), $html);
        $this->assertStringContainsString('href="media-photo.php?driver_id=5" download', $html);
    }

    public function testAnnouncerListsCarsWithAndWithoutProfiles(): void
    {
        $html = renderAnnouncerHtml([
            'events' => [['id' => 3, 'name' => 'Fall Sprint', 'event_date' => '2026-10-11']], 'eventId' => 3, 'q' => '', 'extra' => [],
            'roster' => [
                ['number' => '7', 'car' => 'Mazda MX-5', 'class' => '', 'drivers' => [['name' => 'Bo Bell', 'entry' => null]]],
                ['number' => '42', 'car' => '2004 Honda S2000 (Silver)', 'class' => 'GT3', 'drivers' => [['name' => 'Jane <Doe>', 'entry' => $this->entry()]]],
            ],
        ]);
        $this->assertStringContainsString('<option value="3" selected>', $html);
        $this->assertLessThan(strpos($html, '#42'), strpos($html, '#7'));
        $this->assertStringContainsString('No media profile', $html);
        $this->assertStringContainsString('Loves &lt;b&gt;hairpins&lt;/b&gt;.', $html);
        $this->assertStringContainsString('name="q"', $html);
    }

    public function testAnnouncerWithNoEventsOrEmptyRoster(): void
    {
        $this->assertStringContainsString('No events yet.', renderAnnouncerHtml(['events' => [], 'eventId' => 0, 'roster' => [], 'q' => '', 'extra' => []]));
        $html = renderAnnouncerHtml(['events' => [['id' => 3, 'name' => 'Fall Sprint', 'event_date' => '2026-10-11']], 'eventId' => 3, 'roster' => [], 'q' => '', 'extra' => []]);
        $this->assertStringContainsString('No cars on this event yet.', $html);
    }

    public function testKitShowsZipOnlyWhenAvailable(): void
    {
        $vm = ['events' => [], 'eventId' => 0, 'entries' => [$this->entry()], 'zip' => true];
        $this->assertStringContainsString('href="media.php?action=kit-zip&amp;event=0"', renderMediaKitHtml($vm));
        $this->assertStringNotContainsString('kit-zip', renderMediaKitHtml(['zip' => false] + $vm));
        $this->assertStringContainsString('No drivers have shared a profile yet.', renderMediaKitHtml(['entries' => []] + $vm));
    }
}
```

Add to `MediaSourceTest`:

```php
    public function testMediaControllerIsGatedAndReviewActionsArePostOnly(): void
    {
        $src = $this->src('media.php');
        $this->assertStringContainsString("\$user = require_role('user');", $src);
        $this->assertMatchesRegularExpression('/if \(!mediaCanAccess\(\$user\)\) \{\s*http_response_code\(403\);\s*hubRenderForbidden\(\);\s*exit;/', $src);
        $this->assertStringContainsString("class_exists('ZipArchive')", $src);
    }
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `php phpunit.phar tests/MediaServiceTest.php tests/MediaPageTest.php tests/MediaSourceTest.php`
Expected: FAIL.

- [ ] **Step 3: Add the loaders to `media-service.php`**

```php
/** @return array<int, array{number: string, car: string, class: string, drivers: array}> */
function mediaAnnouncerRoster(PDO $pdo, int $eventId): array {
    $cars = db_get_event_roster_cars($pdo, $eventId);
    $byCar = [];
    foreach (db_get_event_tech_sheets($pdo, $eventId) as $s) $byCar[(int)$s['car_id']][] = $s;
    $latest = [];
    foreach ($cars as $car) {
        $cid = (int)$car['id'];
        if (isset($byCar[$cid])) continue;
        $lid = db_get_car_latest_tech_sheet_id($pdo, $cid);
        if ($lid !== null) $latest[$cid] = db_get_tech_sheet($pdo, $lid);
    }
    $sheetIds = array_merge(array_column(array_merge(...array_values($byCar ?: [[]])), 'id'), array_column(array_values($latest), 'id'));
    $sheetDrivers = db_get_drivers_for_sheets($pdo, $sheetIds);
    $selfs = db_get_self_drivers_for_users($pdo, array_column($cars, 'owner_user_id'));
    $decls = db_get_declarations_for_cars($pdo, array_column($cars, 'id'));

    $perCar = [];
    $allIds = [];
    foreach ($cars as $car) {
        $cid = (int)$car['id'];
        $owner = (int)$car['owner_user_id'];
        $ids = mediaRosterDriverIds($byCar[$cid] ?? [], $latest[$cid] ?? null, $sheetDrivers, isset($selfs[$owner]) ? (int)$selfs[$owner]['id'] : null);
        $perCar[$cid] = $ids;
        array_push($allIds, ...$ids);
    }
    $bundles = db_get_media_bundle($pdo, $allIds);

    $out = [];
    foreach ($cars as $car) {
        $cid = (int)$car['id'];
        $eventSheets = $byCar[$cid] ?? [];
        $class = $eventSheets ? (string)end($eventSheets)['class'] : mediaAcceptedClass($decls[$cid] ?? []);
        $carLabel = mediaCarLabel($car);
        $drivers = [];
        foreach ($perCar[$cid] as $did) {
            $driver = db_get_driver($pdo, $did);
            if ($driver === null) continue;
            $b = $bundles[$did];
            $entry = mediaUsable($b['profile'], $b['consent'], 'club')
                ? mediaEntry($driver, $b['profile'], $b['sponsors'], (string)$car['car_number'], $carLabel, $class, mediaUsable($b['profile'], $b['consent'], 'public'))
                : null;
            $drivers[] = ['name' => (string)$driver['name'], 'entry' => $entry];
        }
        $out[] = ['number' => (string)$car['car_number'], 'car' => $carLabel, 'class' => $class, 'drivers' => $drivers];
    }
    return $out;
}

/** mediaEntry() arrays for the drivers usable for clubs, car details from their latest sheet in $season. */
function mediaEntriesForDrivers(PDO $pdo, array $driverIds, int $season): array {
    $out = [];
    foreach (db_get_media_bundle($pdo, $driverIds) as $did => $b) {
        if (!mediaUsable($b['profile'], $b['consent'], 'club')) continue;
        $driver = db_get_driver($pdo, $did);
        if ($driver === null) continue;
        $sheet = db_get_driver_latest_sheet($pdo, $did, $season);
        $out[] = mediaEntry($driver, $b['profile'], $b['sponsors'], (string)($sheet['car_number'] ?? ''),
            $sheet !== null ? mediaCarLabel($sheet) : '', (string)($sheet['class'] ?? ''), mediaUsable($b['profile'], $b['consent'], 'public'));
    }
    return $out;
}

/** $eventId 0 = every consented driver; otherwise the entries on that event's announcer roster. */
function mediaKitEntries(PDO $pdo, int $eventId, int $season): array {
    if ($eventId === 0) return mediaEntriesForDrivers($pdo, db_get_consented_driver_ids($pdo), $season);
    $out = [];
    foreach (mediaAnnouncerRoster($pdo, $eventId) as $car) {
        foreach ($car['drivers'] as $d) {
            if ($d['entry'] !== null) $out[] = $d['entry'];
        }
    }
    return $out;
}
```

`array_merge(...array_values($byCar ?: [[]]))` flattens the per-car sheet lists, and is safe when there are none.

- [ ] **Step 4: Create `media-page.php` with the announcer and kit renderers**

```php
<?php
// wcma-calculator/media-page.php
//
// Markup for the Media section (Announcer, Media kit, Public review) and the public driver page.
// Pure: no DB, no session, no echo. Callers must have loaded view_helpers.php (h()) and media-lib.php.

function renderMediaEntryHtml(array $e, bool $forKit): string {
    $id = (int)$e['driver_id'];
    $out = '<article class="hub-card media-entry">';
    if ($e['has_photo']) {
        $out .= '<img class="media-entry-photo" src="media-photo.php?driver_id=' . $id . '" alt="' . h($e['name']) . '" loading="lazy">';
    }
    $out .= '<div class="media-entry-body"><h3>' . ($e['number'] !== '' ? '<span class="media-number">#' . h($e['number']) . '</span> ' : '') . h($e['name']) . '</h3>';
    if ((string)$e['pronunciation'] !== '') $out .= '<p class="media-say">Say it: ' . h((string)$e['pronunciation']) . '</p>';
    if ($e['car'] !== '' || $e['class'] !== '') $out .= '<p class="media-car">' . h(trim($e['car'] . ($e['class'] !== '' ? ' · ' . $e['class'] : ''))) . '</p>';
    $facts = mediaFactsLine($e);
    if ($facts !== '') $out .= '<p class="media-facts">' . h($facts) . '</p>';
    if (trim($e['blurb']) !== '') $out .= '<p class="media-blurb">' . nl2br(h($e['blurb'])) . '</p>';
    if ($e['sponsors']) {
        $links = array_map(fn(array $s): string => $s['url']
            ? '<a href="' . h($s['url']) . '" target="_blank" rel="sponsored noopener">' . h($s['name']) . '</a>'
            : h($s['name']), $e['sponsors']);
        $out .= '<p class="media-sponsors-line">Supported by: ' . implode(', ', $links) . '</p>';
    }
    if ($forKit) {
        $out .= '<textarea class="media-copy-src" readonly hidden>' . h(mediaCopyText($e)) . '</textarea>'
            . '<p class="hub-line"><button type="button" class="hub-btn hub-btn--secondary" data-copy>Copy text</button>'
            . ($e['has_photo'] ? ' <a href="media-photo.php?driver_id=' . $id . '" download>Download photo</a>' : '')
            . ((string)$e['social_handle'] !== '' ? ' <span class="form-hint">@' . h((string)$e['social_handle']) . '</span>' : '')
            . '</p>';
    }
    return $out . '</div></article>';
}

function mediaEventPickerHtml(array $events, int $eventId, string $action, bool $allowAll): string {
    $out = '<form method="get" action="media.php" class="hub-line media-picker">'
        . ($action !== '' ? '<input type="hidden" name="action" value="' . h($action) . '">' : '')
        . '<label for="media-event">Event</label><select id="media-event" name="event">';
    if ($allowAll) $out .= '<option value="0"' . ($eventId === 0 ? ' selected' : '') . '>All drivers who shared a profile</option>';
    foreach ($events as $ev) {
        $out .= '<option value="' . (int)$ev['id'] . '"' . ((int)$ev['id'] === $eventId ? ' selected' : '') . '>'
            . h($ev['name'] . ' — ' . $ev['event_date']) . '</option>';
    }
    return $out . '</select><button type="submit" class="hub-btn hub-btn--secondary">Show</button></form>';
}

function renderAnnouncerHtml(array $vm): string {
    $out = '<h1>Announcer</h1>';
    if (!$vm['events']) return $out . '<p>No events yet.</p>';
    $out .= mediaEventPickerHtml($vm['events'], (int)$vm['eventId'], '', false)
        . '<p class="form-hint no-print">Cars in number order. Drivers come from tech sheets for this event, or the car\'s last tech sheet, or the car owner.</p>';
    if (!$vm['roster']) $out .= '<p>No cars on this event yet.</p>';
    foreach ($vm['roster'] as $car) {
        $out .= '<section class="media-car-block"><h2><span class="media-number">#' . h($car['number']) . '</span> '
            . h($car['car']) . ($car['class'] !== '' ? ' <span class="media-class">' . h($car['class']) . '</span>' : '') . '</h2>';
        foreach ($car['drivers'] as $d) {
            $out .= $d['entry'] !== null ? renderMediaEntryHtml($d['entry'], false)
                : '<p class="media-bare">' . h($d['name']) . ' <span class="form-hint">No media profile</span></p>';
        }
        $out .= '</section>';
    }
    $out .= '<form method="get" action="media.php" class="hub-card no-print"><input type="hidden" name="event" value="' . (int)$vm['eventId'] . '">'
        . '<label for="media-q">Add a driver who is not on the list</label>'
        . '<input type="search" id="media-q" name="q" value="' . h((string)$vm['q']) . '" maxlength="100">'
        . '<button type="submit" class="hub-btn hub-btn--secondary">Find</button></form>';
    if ((string)$vm['q'] !== '') {
        $out .= '<h2>Added for this view</h2>';
        if (!$vm['extra']) $out .= '<p>No shared profiles match "' . h((string)$vm['q']) . '".</p>';
        foreach ($vm['extra'] as $e) $out .= renderMediaEntryHtml($e, false);
    }
    return $out;
}

function renderMediaKitHtml(array $vm): string {
    $out = '<h1>Media kit</h1>' . mediaEventPickerHtml($vm['events'], (int)$vm['eventId'], 'kit', true);
    if (!$vm['entries']) return $out . '<p>No drivers have shared a profile yet.</p>';
    if ($vm['zip']) {
        $out .= '<p><a class="hub-btn" href="media.php?action=kit-zip&amp;event=' . (int)$vm['eventId'] . '">Download all (.zip)</a></p>';
    }
    foreach ($vm['entries'] as $e) $out .= renderMediaEntryHtml($e, true);
    return $out;
}
```

- [ ] **Step 5: Create `media.php` (announcer, kit and zip; Task 10 adds review)**

```php
<?php
// wcma-calculator/media.php — the Media section (spec 2026-09-27 §4): Announcer, Media kit and Public
// review, for accounts with Media staff access and admins. Review actions are POST-only and CSRF-checked.
require __DIR__ . '/session_bootstrap.php';
date_default_timezone_set('America/Denver');
require __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/feedback-lib.php';          // feedbackBaseUrl()
require __DIR__ . '/tech-status.php';           // techDefaultEventId()
require __DIR__ . '/photo-requirements.php';
require __DIR__ . '/inspection-lib.php';
require __DIR__ . '/media-lib.php';
require __DIR__ . '/media-service.php';
require __DIR__ . '/media-page.php';

$pdo = db_connect();
db_init($pdo);
$user = require_role('user');
if (!mediaCanAccess($user)) {
    http_response_code(403);
    hubRenderForbidden();
    exit;
}

$action = is_string($_GET['action'] ?? null) && $_GET['action'] !== '' ? $_GET['action'] : 'announcer';
$season = (int)date('Y');
$events = db_get_all_events($pdo);
$eventIds = array_map(fn(array $e): int => (int)$e['id'], $events);
$eventParam = is_scalar($_GET['event'] ?? null) ? (int)$_GET['event'] : -1;

switch ($action) {
    case 'kit':
    case 'kit-zip':
        $eventId = in_array($eventParam, $eventIds, true) ? $eventParam : 0;
        $entries = mediaKitEntries($pdo, $eventId, $season);
        if ($action === 'kit-zip' && class_exists('ZipArchive')) {
            mediaSendZip($pdo, $entries, feedbackBaseUrl($_SERVER, (string)config_default('SITE_BASE_URL', '')));
        }
        renderPageStart('Media kit', 'media', ['flash' => getFlash(), 'subnav' => mediaSubnavHtml('kit')]);
        echo renderMediaKitHtml(['events' => $events, 'eventId' => $eventId, 'entries' => $entries, 'zip' => class_exists('ZipArchive')]);
        renderPageEnd(['scripts' => '<script src="js/media-kit.js"></script>']);
        break;

    default:
        $eventId = in_array($eventParam, $eventIds, true) ? $eventParam : techDefaultEventId($events, date('Y-m-d'));
        $q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
        $extra = $q === '' ? [] : mediaEntriesForDrivers($pdo, array_map(fn(array $r): int => (int)$r['driver_id'], db_search_media_profiles($pdo, $q)), $season);
        renderPageStart('Announcer', 'media', ['flash' => getFlash(), 'subnav' => mediaSubnavHtml('announcer'), 'bodyClass' => 'media-announcer']);
        echo renderAnnouncerHtml(['events' => $events, 'eventId' => $eventId, 'roster' => $eventId > 0 ? mediaAnnouncerRoster($pdo, $eventId) : [],
            'q' => $q, 'extra' => $extra]);
        renderPageEnd();
}

/** Streams a zip of photos ({number}-{name}.{ext}) and profiles.csv, then exits. */
function mediaSendZip(PDO $pdo, array $entries, string $baseUrl): void {
    $tmp = tempnam(sys_get_temp_dir(), 'wcmakit');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $csv = fopen('php://temp', 'r+');
    foreach (mediaCsvRows($entries, $baseUrl) as $row) fputcsv($csv, $row);
    rewind($csv);
    $zip->addFromString('profiles.csv', stream_get_contents($csv));
    fclose($csv);
    foreach ($entries as $e) {
        $path = (string)(db_get_media_profile($pdo, (int)$e['driver_id'])['photo_path'] ?? '');
        if ($path === '' || !is_file(__DIR__ . '/' . $path)) continue;
        $name = ($e['number'] !== '' ? mediaSlug($e['number']) . '-' : '') . mediaSlug($e['name']) . '-' . (int)$e['driver_id'] . '.' . pathinfo($path, PATHINFO_EXTENSION);
        $zip->addFile(__DIR__ . '/' . $path, $name);
    }
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="wcma-media-kit-' . date('Y-m-d') . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}
```

Photo file names include the driver id, so two drivers with the same name and number can't collide inside the zip.

- [ ] **Step 6: Create `js/media-kit.js`**

```js
// wcma-calculator/js/media-kit.js — "Copy text" buttons on the media kit.
(function () {
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const src = btn.closest('.media-entry').querySelector('.media-copy-src');
            const text = src.value;
            try {
                await navigator.clipboard.writeText(text);
            } catch (e) {
                src.hidden = false; src.select(); document.execCommand('copy'); src.hidden = true;
            }
            const label = btn.textContent;
            btn.textContent = 'Copied';
            setTimeout(function () { btn.textContent = label; }, 1500);
        });
    });
})();
```

- [ ] **Step 7: Add the styles and the print layout**

Append to `css/hub.css`:

```css
.media-entry { display: flex; gap: 1rem; align-items: flex-start; }
.media-entry-photo { width: 120px; height: 120px; object-fit: cover; border-radius: 8px; flex: none; }
@media (max-width: 480px) { .media-entry { flex-direction: column; } .media-entry-photo { width: 100%; height: auto; aspect-ratio: 1; } }
.media-number { font-family: 'Archivo Narrow', sans-serif; font-weight: 700; }
.media-car-block h2 { font-size: 1.6rem; margin-top: 1.5rem; }
.media-class { font-size: 1rem; padding: .1rem .5rem; border: 2px solid currentColor; border-radius: 4px; vertical-align: middle; }
.media-say { font-style: italic; }
.media-blurb { font-size: 1.15rem; line-height: 1.5; }
.media-bare { padding: .5rem 0; }
@media print {
  .hub-header, .hub-stripe, .hub-subnav, .hub-footer, .no-print, .media-picker, .hub-menu-btn { display: none !important; }
  .media-car-block { break-inside: avoid; }
  .media-entry { box-shadow: none; border: 1px solid #999; }
}
```

- [ ] **Step 8: Run the tests and watch them pass**

Run: `php phpunit.phar`
Expected: all green.

- [ ] **Step 9: Check it in the browser**

1. Give an account Media staff access (Task 5), sign out and back in, then open **Media**.
2. Announcer shows Fall Sprint's cars in number order, with Jordan's profile.
3. Print preview hides the navigation.
4. Media kit: "Copy text" puts the text on the clipboard, and "Download all" gives a zip with `profiles.csv` and the photos.

- [ ] **Step 10: Commit**

```bash
git add media-service.php media-page.php media.php js/media-kit.js css/hub.css tests/MediaServiceTest.php tests/MediaPageTest.php tests/MediaSourceTest.php
git commit -m "feat(media): announcer sheet and media kit for media staff"
```

---

### Task 10: Public review tab, hiding, and the owner emails

**Files:**
- Modify: `media-service.php` (`mediaReviewAction()`)
- Create: `media-email.php`
- Modify: `media-page.php` (`renderMediaReviewHtml()`), `media.php` (review tab and POST actions)
- Test: `tests/MediaServiceTest.php`, `tests/MediaEmailTest.php`, `tests/MediaPageTest.php`, `tests/MediaSourceTest.php`

**Interfaces:**
- Consumes: `db_set_media_public_status`, `db_set_media_hidden`, `db_get_media_review_queue` and `db_search_media_profiles`, plus `pretechEmailWrap/Para/Link` (`pretech-email.php`) and `emailSmtpSend`.
- Produces:
  - `MEDIA_POST_ACTIONS = ['media-accept', 'media-send-back', 'media-hide', 'media-unhide']`, in `media-service.php`.
  - `mediaReviewAction(PDO $pdo, string $action, int $driverId, int $reviewerId, string $note): array{ok: bool, error: ?string, notify: ?string}`: `notify` is `'sent_back'`, `'hidden'` or null.
  - `mediaEmailSentBack(array $driver, string $note, string $pageUrl): array{subject, html, text}`
  - `mediaEmailHidden(array $driver, string $reason, string $pageUrl): array{subject, html, text}`
  - `mediaNotifyOwner(PDO $pdo, string $kind, int $driverId, string $note, string $baseUrl, callable $send): bool`
  - `renderMediaReviewHtml(array $vm): string`: `$vm` keys `queue` (a list of `{driver_id, driver_name, entry}`), `q`, `found` (profile rows with `driver_name` and `hidden_at`) and `csrf`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/MediaServiceTest.php` (it also needs `require_once __DIR__ . '/../pretech-email.php';` and `require_once __DIR__ . '/../media-email.php';` at the top):

```php
    public function testReviewActions(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $d = (int)db_get_self_driver($pdo, $u)['id'];
        $this->assertSame('That profile no longer exists.', mediaReviewAction($pdo, 'media-accept', $d, $u, '')['error']);
        mediaSaveProfile($pdo, $u, $d, ['blurb' => 'Fast.', 'consent_media' => '1', 'consent_public' => '1'], null, $this->base, 'rename');

        $this->assertSame('Add a note so the driver knows what to change.', mediaReviewAction($pdo, 'media-send-back', $d, $u, ' ')['error']);
        $r = mediaReviewAction($pdo, 'media-send-back', $d, $u, 'Brighter photo');
        $this->assertSame(['ok' => true, 'error' => null, 'notify' => 'sent_back'], $r);
        $this->assertSame('That profile is not waiting for review.', mediaReviewAction($pdo, 'media-accept', $d, $u, '')['error']);

        mediaSaveProfile($pdo, $u, $d, ['blurb' => 'Faster.', 'consent_media' => '1', 'consent_public' => '1'], null, $this->base, 'rename');
        $this->assertSame(['ok' => true, 'error' => null, 'notify' => null], mediaReviewAction($pdo, 'media-accept', $d, $u, ''));
        $this->assertSame('accepted', db_get_media_profile($pdo, $d)['public_status']);

        $this->assertSame('Add a reason for hiding it.', mediaReviewAction($pdo, 'media-hide', $d, $u, '')['error']);
        $this->assertSame('hidden', mediaReviewAction($pdo, 'media-hide', $d, $u, 'Sponsor dispute')['notify']);
        $this->assertFalse(mediaUsable(db_get_media_profile($pdo, $d), db_get_latest_media_consent($pdo, $d), 'club'));
        mediaReviewAction($pdo, 'media-unhide', $d, $u, '');
        $this->assertTrue(mediaUsable(db_get_media_profile($pdo, $d), db_get_latest_media_consent($pdo, $d), 'public'));
        $this->assertSame('Unknown action.', mediaReviewAction($pdo, 'media-nuke', $d, $u, '')['error']);
    }

    public function testNotifyOwnerEmailsTheManagingAccountAndSurvivesAFailedSend(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $sam = db_create_driver($pdo, $u, 'Sam Patel');
        $sent = [];
        $ok = mediaNotifyOwner($pdo, 'sent_back', $sam, 'Brighter photo', 'https://hub.test', function (array $to, array $m) use (&$sent): bool {
            $sent[] = [$to, $m];
            return true;
        });
        $this->assertTrue($ok);
        $this->assertSame([['j@example.com', 'Jordan Lee']], $sent[0][0]);
        $this->assertStringContainsString('https://hub.test/media-profile.php?driver_id=' . $sam, $sent[0][1]['text']);
        $this->assertFalse(mediaNotifyOwner($pdo, 'hidden', $sam, 'x', 'https://hub.test', function (): bool { throw new RuntimeException('smtp down'); }));
    }
```

Create `tests/MediaEmailTest.php`:

```php
<?php
// wcma-calculator/tests/MediaEmailTest.php
require_once __DIR__ . '/../pretech-email.php';
require_once __DIR__ . '/../media-email.php';

use PHPUnit\Framework\TestCase;

final class MediaEmailTest extends TestCase
{
    public function testSentBackEmail(): void
    {
        $m = mediaEmailSentBack(['name' => 'Sam <Patel>'], 'Brighter <photo>', 'https://hub.test/media-profile.php?driver_id=6');
        $this->assertSame('Your WCMA public driver page was sent back — Sam <Patel>', $m['subject']);
        $this->assertStringContainsString('Brighter &lt;photo&gt;', $m['html']);
        $this->assertStringContainsString('Brighter <photo>', $m['text']);
        $this->assertStringContainsString('https://hub.test/media-profile.php?driver_id=6', $m['text']);
        $this->assertStringContainsString('still used for announcing', $m['text']);
        $this->assertStringNotContainsString('approv', strtolower($m['text'] . $m['html']));
    }

    public function testHiddenEmail(): void
    {
        $m = mediaEmailHidden(['name' => 'Sam Patel'], 'Sponsor dispute', 'https://hub.test/media-profile.php?driver_id=6');
        $this->assertSame('Your WCMA driver profile was hidden — Sam Patel', $m['subject']);
        $this->assertStringContainsString('Sponsor dispute', $m['text']);
        $this->assertStringContainsString('not used for announcing, club promotion or the public page', $m['text']);
    }
}
```

Add to `tests/MediaPageTest.php`:

```php
    public function testReviewQueueHasAcceptSendBackAndHideForms(): void
    {
        $html = renderMediaReviewHtml(['csrf' => 'tok', 'q' => '', 'found' => [],
            'queue' => [['driver_id' => 5, 'driver_name' => 'Jane <Doe>', 'entry' => $this->entry()]]]);
        $this->assertStringContainsString('Jane &lt;Doe&gt;', $html);
        foreach (['media-accept', 'media-send-back', 'media-hide'] as $a) {
            $this->assertStringContainsString('action="media.php?action=' . $a . '"', $html);
        }
        $this->assertSame(3, substr_count($html, 'name="csrf_token" value="tok"'));
        $this->assertStringContainsString('name="note" required', $html);
        $this->assertStringNotContainsString('approv', strtolower($html));
        $this->assertStringContainsString('Nothing waiting for review.', renderMediaReviewHtml(['csrf' => 't', 'q' => '', 'found' => [], 'queue' => []]));
    }

    public function testHideSearchOffersUnhideForHiddenProfiles(): void
    {
        $html = renderMediaReviewHtml(['csrf' => 'tok', 'q' => 'doe', 'queue' => [], 'found' => [
            ['driver_id' => 5, 'driver_name' => 'Jane Doe', 'hidden_at' => null, 'public_status' => 'accepted'],
            ['driver_id' => 6, 'driver_name' => 'John Doe', 'hidden_at' => '2026-09-27 10:00:00', 'hidden_reason' => 'Dispute', 'public_status' => 'none'],
        ]]);
        $this->assertStringContainsString('action="media.php?action=media-hide"', $html);
        $this->assertStringContainsString('action="media.php?action=media-unhide"', $html);
        $this->assertStringContainsString('Dispute', $html);
    }
```

Add to `MediaSourceTest`:

```php
    public function testReviewPostsAreCsrfCheckedAndEmailTheOwner(): void
    {
        $src = $this->src('media.php');
        $this->assertMatchesRegularExpression("/if \(in_array\(\\\$action, MEDIA_POST_ACTIONS, true\)\) \{\s*if \(\\\$_SERVER\['REQUEST_METHOD'\] !== 'POST'\)/", $src);
        $this->assertStringContainsString("if (!validateCsrfToken(\$_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }", $src);
        $this->assertStringContainsString("mediaNotifyOwner(\$pdo, \$r['notify']", $src);
        $this->assertStringContainsString("'emailSmtpSend'", $src);
    }
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `php phpunit.phar tests/MediaServiceTest.php tests/MediaEmailTest.php tests/MediaPageTest.php tests/MediaSourceTest.php`
Expected: FAIL.

- [ ] **Step 3: Add `mediaReviewAction()` to `media-service.php`**

```php
const MEDIA_POST_ACTIONS = ['media-accept', 'media-send-back', 'media-hide', 'media-unhide'];

/** @return array{ok: bool, error: ?string, notify: ?string} */
function mediaReviewAction(PDO $pdo, string $action, int $driverId, int $reviewerId, string $note): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'notify' => null];
    $ok = fn(?string $notify = null): array => ['ok' => true, 'error' => null, 'notify' => $notify];
    if (!in_array($action, MEDIA_POST_ACTIONS, true)) return $fail('Unknown action.');
    $profile = db_get_media_profile($pdo, $driverId);
    if ($profile === null) return $fail('That profile no longer exists.');
    $note = trim((string)preg_replace('/\s+/u', ' ', $note));
    if (mb_strlen($note, 'UTF-8') > 500) return $fail('Keep the note to 500 characters or fewer.');
    switch ($action) {
        case 'media-accept':
            if ($profile['public_status'] !== 'pending_review') return $fail('That profile is not waiting for review.');
            db_set_media_public_status($pdo, $driverId, 'accepted', $reviewerId, null);
            return $ok();
        case 'media-send-back':
            if ($note === '') return $fail('Add a note so the driver knows what to change.');
            if ($profile['public_status'] !== 'pending_review') return $fail('That profile is not waiting for review.');
            db_set_media_public_status($pdo, $driverId, 'sent_back', $reviewerId, $note);
            return $ok('sent_back');
        case 'media-hide':
            if ($note === '') return $fail('Add a reason for hiding it.');
            db_set_media_hidden($pdo, $driverId, $reviewerId, $note);
            return $ok('hidden');
        default:   // media-unhide
            db_set_media_hidden($pdo, $driverId, null, null);
            return $ok();
    }
}
```

- [ ] **Step 4: Create `media-email.php`**

```php
<?php
// wcma-calculator/media-email.php
//
// Emails to the account that manages a driver profile when Media staff send its public page back or
// hide it. Pure renderers plus a notifier with an injectable send function. Callers must have loaded
// db.php and pretech-email.php (pretechEmailWrap/Para/Link).

/** @return array{subject: string, html: string, text: string} */
function mediaEmailSentBack(array $driver, string $note, string $pageUrl): array {
    $name = (string)$driver['name'];
    $lines = [
        'WCMA media staff reviewed the public driver page for ' . $name . ' and sent it back with this note:',
        $note,
        'Update the profile and save it to send it for review again. It is still used for announcing and club promotion in the meantime.',
    ];
    $html = pretechEmailPara($lines[0]) . pretechEmailPara($note) . pretechEmailPara($lines[2])
        . pretechEmailLink($pageUrl, 'Open the media profile');
    return ['subject' => 'Your WCMA public driver page was sent back — ' . $name,
            'html' => pretechEmailWrap('PUBLIC PAGE: CHANGES NEEDED', $html),
            'text' => implode("\n\n", $lines) . "\n\n" . $pageUrl . "\n"];
}

/** @return array{subject: string, html: string, text: string} */
function mediaEmailHidden(array $driver, string $reason, string $pageUrl): array {
    $name = (string)$driver['name'];
    $lines = [
        'WCMA media staff hid the driver profile for ' . $name . '. Reason:',
        $reason,
        'While hidden it is not used for announcing, club promotion or the public page. Reply to this email if you have questions.',
    ];
    $html = pretechEmailPara($lines[0]) . pretechEmailPara($reason) . pretechEmailPara($lines[2])
        . pretechEmailLink($pageUrl, 'Open the media profile');
    return ['subject' => 'Your WCMA driver profile was hidden — ' . $name,
            'html' => pretechEmailWrap('DRIVER PROFILE HIDDEN', $html),
            'text' => implode("\n\n", $lines) . "\n\n" . $pageUrl . "\n"];
}

function mediaNotifyOwner(PDO $pdo, string $kind, int $driverId, string $note, string $baseUrl, callable $send): bool {
    $driver = db_get_driver($pdo, $driverId);
    $owner = $driver !== null ? db_find_user_by_id($pdo, (int)$driver['owner_user_id']) : null;
    if ($owner === null) return false;
    $page = rtrim($baseUrl, '/') . '/media-profile.php?driver_id=' . $driverId;
    $message = $kind === 'hidden' ? mediaEmailHidden($driver, $note, $page) : mediaEmailSentBack($driver, $note, $page);
    try {
        return (bool)$send([[(string)$owner['email'], (string)$owner['name']]], $message);
    } catch (Throwable $e) {
        error_log('media email failed: ' . $e->getMessage());
        return false;
    }
}
```

`pretechEmailPara(string $text)` escapes its text, so the note and the reason are safe to pass straight in.

- [ ] **Step 5: Add `renderMediaReviewHtml()` to `media-page.php`**

```php
function mediaPostFormHtml(string $action, int $driverId, string $csrf, string $inner, string $cls = 'hub-line'): string {
    return '<form method="post" action="media.php?action=' . h($action) . '" class="' . h($cls) . '">'
        . '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">'
        . '<input type="hidden" name="driver_id" value="' . $driverId . '">' . $inner . '</form>';
}

function renderMediaReviewHtml(array $vm): string {
    $csrf = (string)$vm['csrf'];
    $out = '<h1>Public review</h1><p class="hub-intro">Profiles that asked to go on the public page, oldest first. Announcing and the media kit already use them.</p>';
    if (!$vm['queue']) $out .= '<p>Nothing waiting for review.</p>';
    foreach ($vm['queue'] as $row) {
        $id = (int)$row['driver_id'];
        $out .= '<section class="media-review-item"><h2>' . h((string)$row['driver_name']) . '</h2>'
            . renderMediaEntryHtml($row['entry'], false)
            . mediaPostFormHtml('media-accept', $id, $csrf, '<button type="submit" class="hub-btn">Accept for the public page</button>')
            . mediaPostFormHtml('media-send-back', $id, $csrf, '<label for="sb-' . $id . '">Note for the driver</label>'
                . '<input type="text" id="sb-' . $id . '" name="note" required maxlength="500">'
                . '<button type="submit" class="hub-btn hub-btn--secondary">Send back</button>')
            . mediaPostFormHtml('media-hide', $id, $csrf, '<label for="hd-' . $id . '">Reason for hiding</label>'
                . '<input type="text" id="hd-' . $id . '" name="note" required maxlength="500">'
                . '<button type="submit" class="hub-btn hub-btn--link">Hide everywhere</button>')
            . '</section>';
    }
    $out .= '<h2>Hide or unhide any profile</h2><form method="get" action="media.php" class="hub-line">'
        . '<input type="hidden" name="action" value="review"><label for="rv-q">Driver name</label>'
        . '<input type="search" id="rv-q" name="q" maxlength="100" value="' . h((string)$vm['q']) . '">'
        . '<button type="submit" class="hub-btn hub-btn--secondary">Find</button></form>';
    if ((string)$vm['q'] !== '' && !$vm['found']) $out .= '<p>No profiles match "' . h((string)$vm['q']) . '".</p>';
    foreach ($vm['found'] as $p) {
        $id = (int)$p['driver_id'];
        $out .= '<div class="hub-card"><strong>' . h((string)$p['driver_name']) . '</strong> ';
        if (!empty($p['hidden_at'])) {
            $out .= '<span class="hub-status hub-status--todo">Hidden</span> ' . h((string)($p['hidden_reason'] ?? ''))
                . mediaPostFormHtml('media-unhide', $id, $csrf, '<button type="submit" class="hub-btn hub-btn--secondary">Unhide</button>');
        } else {
            $out .= mediaPostFormHtml('media-hide', $id, $csrf, '<label for="hf-' . $id . '">Reason for hiding</label>'
                . '<input type="text" id="hf-' . $id . '" name="note" required maxlength="500">'
                . '<button type="submit" class="hub-btn hub-btn--link">Hide everywhere</button>');
        }
        $out .= '</div>';
    }
    return $out;
}
```

- [ ] **Step 6: Wire the review tab and POSTs into `media.php`**

Add the requires after `media-page.php`:

```php
require __DIR__ . '/pretech-email.php';
require __DIR__ . '/media-email.php';
require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';
require __DIR__ . '/email-helpers.php';
```

Right after `$action = ...`, before `$season`:

```php
if (in_array($action, MEDIA_POST_ACTIONS, true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: media.php?action=review'); exit; }
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
    $driverId = (int)($_POST['driver_id'] ?? 0);
    $r = mediaReviewAction($pdo, $action, $driverId, (int)$user['id'], is_string($_POST['note'] ?? null) ? $_POST['note'] : '');
    if (!$r['ok']) {
        setFlash($r['error'], 'error');
    } else {
        $done = ['media-accept' => 'Accepted. The public page is live.', 'media-send-back' => 'Sent back with your note.',
                 'media-hide' => 'Hidden everywhere.', 'media-unhide' => 'Unhidden.'][$action];
        if ($r['notify'] !== null) {
            $sent = mediaNotifyOwner($pdo, $r['notify'], $driverId, (string)$_POST['note'],
                feedbackBaseUrl($_SERVER, (string)config_default('SITE_BASE_URL', '')), 'emailSmtpSend');
            $done .= $sent ? ' The driver was emailed.' : ' The email could not be sent.';
        }
        setFlash($done, 'success');
    }
    header('Location: media.php?action=review');
    exit;
}
```

Add to the `switch ($action)` before `default:`:

```php
    case 'review':
        $queue = [];
        foreach (db_get_media_review_queue($pdo) as $row) {
            $did = (int)$row['driver_id'];
            $consent = db_get_latest_media_consent($pdo, $did);
            if (!mediaCurrentConsent($consent)['public']) continue;
            $driver = db_get_driver($pdo, $did);
            $sheet = db_get_driver_latest_sheet($pdo, $did, $season);
            $queue[] = ['driver_id' => $did, 'driver_name' => $row['driver_name'], 'entry' => mediaEntry($driver, $row, db_get_sponsors($pdo, $did),
                (string)($sheet['car_number'] ?? ''), $sheet !== null ? mediaCarLabel($sheet) : '', (string)($sheet['class'] ?? ''), false)];
        }
        $q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
        renderPageStart('Public review', 'media', ['flash' => getFlash(), 'subnav' => mediaSubnavHtml('review')]);
        echo renderMediaReviewHtml(['queue' => $queue, 'q' => $q, 'found' => $q === '' ? [] : db_search_media_profiles($pdo, $q), 'csrf' => generateCsrfToken()]);
        renderPageEnd();
        break;
```

- [ ] **Step 7: Run the tests and watch them pass**

Run: `php phpunit.phar`
Expected: all green.

- [ ] **Step 8: Check it in the browser**

Set `WCMA_MAIL_LOG` in your local `config.php` so no email is really sent.
1. As Media staff, accept Jordan's profile. The flash reads "Accepted. The public page is live."
2. Edit the blurb as Jordan. The profile is back in the queue.
3. Send it back with a note. The mail log has the email, and Jordan's form shows the note.
4. Hide it. It's gone from the Announcer and the kit.
5. Unhide it.

- [ ] **Step 9: Commit**

```bash
git add media-service.php media-email.php media-page.php media.php tests/MediaServiceTest.php tests/MediaEmailTest.php tests/MediaPageTest.php tests/MediaSourceTest.php
git commit -m "feat(media): public review queue, send back and hide, with owner emails"
```

---

### Task 11: The public page (`driver.php`)

**Files:**
- Modify: `media-page.php` (`renderPublicDriverHtml()`)
- Create: `driver.php`
- Test: `tests/MediaPageTest.php`, `tests/MediaSourceTest.php`

**Interfaces:**
- Consumes: `mediaUsable(..., 'public')`, `mediaEntry` and `db_get_driver_latest_sheet`.
- Produces: `renderPublicDriverHtml(array $e): string`

- [ ] **Step 1: Write the failing tests**

Add to `MediaPageTest`:

```php
    public function testPublicPageShowsTheProfileWithoutStaffControls(): void
    {
        $html = renderPublicDriverHtml($this->entry());
        $this->assertStringContainsString('<h1>Jane &lt;Doe&gt;</h1>', $html);
        $this->assertStringContainsString('src="media-photo.php?driver_id=5"', $html);
        $this->assertStringContainsString('#42', $html);
        $this->assertStringContainsString('rel="sponsored noopener"', $html);
        $this->assertStringContainsString('@janed', $html);
        $this->assertStringNotContainsString('data-copy', $html);
        $this->assertStringNotContainsString('<form', $html);
    }
```

Add to `MediaSourceTest`:

```php
    public function testPublicPageOnlyShowsLiveProfilesAndOtherwise404s(): void
    {
        $src = $this->src('driver.php');
        $this->assertStringContainsString("mediaUsable(\$profile, \$consent, 'public')", $src);
        $this->assertStringContainsString('http_response_code(404);', $src);
        $this->assertStringContainsString("This profile isn&#039;t available", $src);
        $this->assertStringNotContainsString('require_role', $src);
    }
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `php phpunit.phar tests/MediaPageTest.php tests/MediaSourceTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement it**

Add to `media-page.php`:

```php
function renderPublicDriverHtml(array $e): string {
    $id = (int)$e['driver_id'];
    $out = '<article class="media-public">';
    if ($e['has_photo']) $out .= '<img class="media-public-photo" src="media-photo.php?driver_id=' . $id . '" alt="' . h($e['name']) . '">';
    $out .= '<h1>' . h($e['name']) . '</h1>';
    $facts = mediaFactsLine($e);
    if ($facts !== '') $out .= '<p class="hub-intro">' . h($facts) . '</p>';
    if ($e['number'] !== '' || $e['car'] !== '') {
        $out .= '<p class="media-car">' . ($e['number'] !== '' ? '<span class="media-number">#' . h($e['number']) . '</span> ' : '') . h($e['car'])
            . ($e['class'] !== '' ? ' · ' . h($e['class']) : '') . '</p>';
    }
    if (trim($e['blurb']) !== '') $out .= '<p class="media-blurb">' . nl2br(h($e['blurb'])) . '</p>';
    if ($e['sponsors']) {
        $out .= '<h2>Sponsors</h2><ul class="media-public-sponsors">';
        foreach ($e['sponsors'] as $s) {
            $out .= '<li>' . ($s['url'] ? '<a href="' . h($s['url']) . '" target="_blank" rel="sponsored noopener">' . h($s['name']) . '</a>' : h($s['name'])) . '</li>';
        }
        $out .= '</ul>';
    }
    if ((string)$e['social_handle'] !== '') $out .= '<p>Follow: @' . h((string)$e['social_handle']) . '</p>';
    return $out . '<p class="form-hint">Racing with the Western Canada Motorsport Association.</p></article>';
}
```

Create `driver.php`:

```php
<?php
// wcma-calculator/driver.php — a driver's public page (spec 2026-09-27 §5). Shown only while public
// consent is on, Media staff accepted it and it isn't hidden; otherwise the same 404 whatever the reason.
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/media-lib.php';
require __DIR__ . '/media-page.php';

date_default_timezone_set('America/Denver');
$pdo = db_connect();
db_init($pdo);
$driverId = (int)($_GET['id'] ?? 0);
$driver = db_get_driver($pdo, $driverId);
$profile = $driver !== null ? db_get_media_profile($pdo, $driverId) : null;
$consent = $driver !== null ? db_get_latest_media_consent($pdo, $driverId) : null;

if ($driver === null || !mediaUsable($profile, $consent, 'public')) {
    http_response_code(404);
    renderPageStart('Not available', '');
    echo '<h1>This profile isn&#039;t available</h1><p><a href="index.php">Go to the WCMA Hub</a></p>';
    renderPageEnd();
    exit;
}
$sheet = db_get_driver_latest_sheet($pdo, $driverId, (int)date('Y'));
$entry = mediaEntry($driver, $profile, db_get_sponsors($pdo, $driverId), (string)($sheet['car_number'] ?? ''),
    $sheet !== null ? mediaCarLabel($sheet) : '', (string)($sheet['class'] ?? ''), true);
renderPageStart((string)$driver['name'], '', ['extraHead' => '<meta name="description" content="' . h(mb_substr(trim($entry['blurb']), 0, 155)) . '">']);
echo renderPublicDriverHtml($entry);
renderPageEnd();
```

Append to `css/hub.css`:

```css
.media-public { max-width: 42rem; }
.media-public-photo { width: 100%; max-width: 420px; aspect-ratio: 1; object-fit: cover; border-radius: 12px; }
.media-public-sponsors { padding-left: 1.2rem; }
```

- [ ] **Step 4: Run the tests and watch them pass**

Run: `php phpunit.phar`
Expected: all green.

Manual check, in a private window:
- `driver.php?id=<Jordan>` shows the page after acceptance.
- After Jordan withdraws, it gives "This profile isn't available" with a 404, and so does the photo URL.

- [ ] **Step 5: Commit**

```bash
git add media-page.php driver.php css/hub.css tests/MediaPageTest.php tests/MediaSourceTest.php
git commit -m "feat(media): public driver page for accepted profiles"
```

---

### Task 12: Seed data, README, and an end-to-end pass

**Files:**
- Modify: `hub-db-tools.php` (`hubSeed()`), `tests/HubDbToolsTest.php` (the summary counts), `seed-hub-db.php` (the accounts line), `README.md`

- [ ] **Step 1: Update the seed test first**

In `tests/HubDbToolsTest.php`, find the assertion on `hubSeed()`'s returned summary.
- Change the expected array to `'users' => 4` and add `'media_profiles' => 2`.
- Add these assertions:

```php
        $media = db_find_user_by_email($pdo, 'media@example.com');
        $this->assertSame(1, (int)$media['is_media']);
        $this->assertSame(1, count(db_get_media_review_queue($pdo)));
        $this->assertCount(2, db_get_consented_driver_ids($pdo));
```

Run: `php phpunit.phar tests/HubDbToolsTest.php`
Expected: FAIL.

- [ ] **Step 2: Seed the data**

In `hubSeed()`:
- After `$jordan = $user(...)`, add:

```php
    $media = $user('media@example.com', 'Mia Media');
    db_set_user_media($pdo, $media, true);
```

- Before `return`, add:

```php
    // Media profiles: Jordan fully consented and waiting for public review; Sam a minor, club use only,
    // consent confirmed by Jordan on Sam's behalf. The inspector's own profile has no consent.
    $jordanDriver = (int)db_get_self_driver($pdo, $jordan)['id'];
    db_save_media_profile($pdo, $jordanDriver, ['blurb' => 'Jordan has raced the S2000 at Castrol since 2015 and still brakes too late into turn 1.',
        'pronunciation' => null, 'hometown' => 'Red Deer, AB', 'racing_since' => 2015, 'social_handle' => 'jordanlee42',
        'photo_path' => null, 'public_status' => 'pending_review']);
    db_replace_sponsors($pdo, $jordanDriver, [['name' => 'Acme Tires', 'url' => 'https://example.com'], ['name' => "Bob's Garage", 'url' => null]]);
    db_insert_media_consent($pdo, ['driver_id' => $jordanDriver, 'consent_media' => 1, 'consent_public' => 1, 'is_minor' => 0,
        'guardian_name' => null, 'given_by_user_id' => $jordan, 'on_behalf' => 0, 'wording_version' => 1]);
    $samDriver = (int)db_find_driver($pdo, $jordan, 'Sam Patel')['id'];
    db_save_media_profile($pdo, $samDriver, ['blurb' => 'Sam is 16 and in a first season moving up from karts.', 'pronunciation' => null,
        'hometown' => 'Olds, AB', 'racing_since' => (int)date('Y'), 'social_handle' => null, 'photo_path' => null, 'public_status' => 'none']);
    db_insert_media_consent($pdo, ['driver_id' => $samDriver, 'consent_media' => 1, 'consent_public' => 0, 'is_minor' => 1,
        'guardian_name' => 'Priya Patel', 'given_by_user_id' => $jordan, 'on_behalf' => 1, 'wording_version' => 1]);
```

- Change the return to `['users' => 4, 'cars' => 2, 'events' => 2, 'tech_sheets' => 1, 'season_links' => 3, 'event_plans' => 1, 'media_profiles' => 2]`.
- In `seed-hub-db.php`, add `media@example.com (media)` to the accounts line.

- [ ] **Step 3: Update the README**

Add this section to `README.md` after the reminders section:

```markdown
## Driver media profiles

Drivers add a photo, a blurb and sponsors from **Drivers → Set up media profile**, and give consent there.
Media staff (tick **Media staff** on Admin → Users & roles; it applies at their next sign-in) get a
**Media** section: Announcer (event roster with profiles, printable), Media kit (copy text, photos,
zip with `profiles.csv`) and Public review. Public pages live at `driver.php?id=N` once accepted.

- No database reset: `db_init()` adds the new tables and `users` columns in place.
- Photos are stored in `uploads/media/` and only served through `media-photo.php`.
- The zip download needs PHP's `zip` extension; without it the button is hidden.
```

- [ ] **Step 4: Run the full suite**

Run: `php phpunit.phar`
Expected: all green.

- [ ] **Step 5: End-to-end pass on a database made by the old code**

This proves the upgrade works without a reset:

```bash
git checkout main
rm -f data/*.db data/*.db-wal data/*.db-shm        # local dev data only
php seed-hub-db.php                                  # a database shaped like production's
git checkout media-profiles
php -r 'require "config.php"; require "db.php"; $p = db_connect(); db_init($p); print_r($p->query("SELECT id, email, is_media, media_prompt_dismissed FROM users")->fetchAll());'
```

Expected: the three original accounts are listed with `is_media` and `media_prompt_dismissed` both `0`, and there are no errors. The media seed rows aren't there, and that's fine: this check is about the upgrade. Reseed for the walk-through below with `php reset-hub-db.php --confirm && php seed-hub-db.php`. That is local only; production is never reset.

Then walk the whole flow once in the browser, with `WCMA_MAIL_LOG` set:
1. Jordan dismisses the Home card, then opens Drivers and sets up Sam's profile. Sam needs the confirmation box.
2. The Media user sees both on Announcer (Fall Sprint) and in the kit, and accepts Jordan.
3. `driver.php?id=<Jordan>` works signed out.
4. Jordan withdraws. The public page and the photo 404, and the kit drops him.
5. At 390px wide, the form, the announcer and the review page don't scroll sideways.

- [ ] **Step 6: Commit**

```bash
git add hub-db-tools.php seed-hub-db.php tests/HubDbToolsTest.php README.md
git commit -m "chore(media): seed media profiles and a media user; README section"
```

---

## Deploying (after review and merge)

- Merge `media-profiles` into `main` and push. The server pulls `main` on its own.
- No `reset-hub-db.php`. The first request after the deploy adds the tables and columns.
- Tick **Media staff** on the right accounts in Admin → Users & roles. They sign out and back in to see the Media section.
