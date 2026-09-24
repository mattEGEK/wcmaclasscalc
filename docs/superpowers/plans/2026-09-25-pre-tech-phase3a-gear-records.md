# Pre-Tech Phase 3a: Driver Gear Records Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a driver (or a team captain on their behalf) keep one gear record per driver per year, submit optional gear photos to be pre-teched, and let an inspector accept the gear record remotely (from photos) or in person, with emails at each step.

**Architecture:** A new `gear_records` table holds one row per (owner, driver name, season) with the same photo-review state machine as tech sheets (`photo_status`: `draft` → `submitted` → `needs_changes` → `submitted` → `accepted`) and an acceptance `status` (`open` → `accepted`, via `photos` or `in_person`). A session-free library (`gear-lib.php`) holds the workflow. The phase 1 photo endpoint learns a second subject type (`gear_record`, requirement scope `gear`). A competitor page (`gear.php`, "My Drivers") manages records and reuses the phase 2b pre-tech card markup and page script; an admin module (`admin-gear.php`) lists and reviews records. Linking gear records into event tech sheets and the roster is phase 3b.

**Tech Stack:** PHP 8.3, SQLite via PDO, PHPUnit (`phpunit.phar`), vanilla JS (classic scripts), PHPMailer (existing), Playwright for the end-to-end check.

**Spec:** `docs/superpowers/specs/2026-09-23-digital-tech-inspection-design.md` (implements *Concepts* → gear record, *Data Model* → `gear_records`, *Competitor Flow* → My Drivers, *Inspector Flow* for gear, and the gear rows of *Photo Requirements*).

## Global Constraints

- **Terminology (binding):** UI and email copy uses "reviewed", "accepted", "teched", "pre-teched"; never "approved", "passed" or "safe" as words. The only place "safe" may appear is the verbatim disclaimer constant `TECH_ACCEPTANCE_DISCLAIMER`, which the accepted email must include.
- **A gear record is a person, not an account:** one row per (`owner_user_id`, normalised driver name, `season`); a team captain can create records for co-drivers by name; a driver with their own account creates their own. `licence_no` is optional and only helps an inspector spot duplicates. Driver-name normalisation: collapse whitespace, trim, lowercase (`strtolower`).
- **Season = calendar year.** New records are created for the current year. A record from an earlier season can be **renewed**: a new record for the current season is created with the same name and licence number, but no photos.
- **Photo status machine** on `gear_records.photo_status`: `NULL` → `draft` (first photo activity) → `submitted` (complete set submitted) → `needs_changes` (inspector sent photos back) → `submitted` → `accepted`. Acceptance `status`: `open` → `accepted` with `accepted_via` = `photos` (remote) or `in_person`.
- **Completeness:** every `required` gear photo present, plus every `conditional` gear photo the competitor marked as applying; `recommended` never counts. Gear requirements come from `photoRequirements('gear')` (4 required: `helmet_label`, `suit_label`, `fhr_label`, `gear_flatlay`; 1 recommended: `helmet_back`; 1 conditional: `underwear_label`). A photo is present only when its row has a non-empty `file_path`.
- **Competitor write lock:** photo uploads, typed-value edits and "applies" toggles by the record's owner are refused once the record is `accepted` OR `photo_status` is `submitted` or `accepted`. Admins may always read and write. Owners may always read their own records' photos. Enforced by `inspectionCanAccess()` via a mapped shape (`gearAccessShape()`), so that function is unchanged.
- **Send-back rule:** an inspector must flag at least one photo and give every flagged photo a note (max 500 characters); flagged photos get `review_status = 'retake'`; resubmitting is refused while any photo is still flagged. Replacing a photo resets its review status to `pending` (phase 1).
- **In-person acceptance of gear has no signature:** the inspector presses one button; the record stores the reviewer and time. (The car sheet's in-person acceptance keeps its signature.) Revoking returns a record to `open`, and a photo-accepted record's photo status to `submitted` (back in the review queue).
- **Derived gear status** (per record): `accepted` (status `accepted`; label "Gear teched <season>" when `accepted_via = 'in_person'`, "Gear pre-teched <season>" when `photos`; a missing `accepted_via` counts as in person) > `needs_changes` ("Photos need changes") > `pending_review` (`photo_status` `submitted`, or `accepted` on a not-yet-accepted record; "Photos pending review") > `photos_draft` ("Photos in progress") > `none` ("Needs gear check at the track").
- **Emails** (branded like the pre-tech emails; logo via `cid:wcma-logo`; photos never embedded): *submitted* → club + competitor; *sent back* → competitor (lists each flagged photo with its note); *accepted* (photos) → competitor + club (competitor copy: collect decals, no gear inspection needed; club copy links the admin page; both carry the disclaimer). An email failure never blocks the workflow step and each message is sent independently. Mail dry-run: the `WCMA_MAIL_LOG` constant makes `emailSmtpSend()` log instead of send.
- **Admin only:** review pages/actions go through `requireAuth()` with CSRF on every POST; all output is escaped with `h()`.
- **Repo conventions:** LF-authored PHP with a `// wcma-calculator/<file>` header comment; session-free libraries; tests in `wcma-calculator/tests/*Test.php`; migrations idempotent inside `db_init()`; commit after each task with a subject line, a blank line, then the trailer; run PHP tests from `wcma-calculator/` with `php phpunit.phar` and JS tests with `node --test "tests/js/*.test.js"` (quoted glob).

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `wcma-calculator/db.php` | modify | `gear_records` table and gear DB functions |
| `wcma-calculator/gear-lib.php` | create | Name normalisation, status, create/renew, snapshot, submit, accept, send back, in-person accept, revoke, filter |
| `wcma-calculator/inspection-lib.php` | modify | `gear_record` subject scope; draft marking for gear in save/applies |
| `wcma-calculator/inspection.php` | modify | Load `gear_record` subjects (owner-scoped shape) |
| `wcma-calculator/gear-email.php` | create | Gear email renderers + notifier |
| `wcma-calculator/gear-page.php` | create | My Drivers list page and gear pre-tech page markup |
| `wcma-calculator/gear.php` | create | Competitor router and handlers |
| `wcma-calculator/js/pretech-form.js` | modify | Generalise subject type/id |
| `wcma-calculator/view_helpers.php` | modify | "My Drivers" link; shared `renderAdminNav()` |
| `wcma-calculator/admin-gear.php` | create | Admin gear list, review page, handlers |
| `wcma-calculator/admin.php`, `admin-feedback.php`, `admin-tech-sheets.php` | modify | Requires, gear routes, shared admin nav |
| `wcma-calculator/css/calculator.css` | modify | A few gear page styles |
| `wcma-calculator/tests/*` | create/modify | Tests per task |

---

### Task 1: Gear records database layer

**Files:**
- Modify: `wcma-calculator/db.php` (add a table inside `db_init()`; append functions)
- Test: `wcma-calculator/tests/DbGearTest.php`

**Interfaces:**
- Consumes: `make_temp_pdo()`, `db_create_user()`.
- Produces:
  - Table `gear_records(id, owner_user_id, driver_name, driver_name_norm, licence_no, season, photo_status, status DEFAULT 'open', accepted_via, reviewed_by_user_id, reviewed_at, created_at, updated_at, UNIQUE(owner_user_id, driver_name_norm, season))`.
  - `db_insert_gear_record(PDO $pdo, int $ownerId, string $driverName, string $driverNameNorm, ?string $licenceNo, int $season): int` (throws `PDOException` on a duplicate).
  - `db_get_gear_record(PDO $pdo, int $id): ?array`
  - `db_find_gear_record(PDO $pdo, int $ownerId, string $driverNameNorm, int $season): ?array`
  - `db_get_user_gear_records(PDO $pdo, int $ownerId): array` — newest season first, then driver name.
  - `db_get_gear_records_for_season(PDO $pdo, int $season): array` — every owner's records for the season, each with `owner_name` and `owner_email`, ordered by driver name.
  - `db_mark_gear_photos_draft(PDO $pdo, int $id): void` — `NULL` → `draft`; no-op otherwise or once accepted.
  - `db_transition_gear_photo_status(PDO $pdo, int $id, array $from, string $to): bool` — atomic; only while `status = 'open'`.
  - `db_accept_gear_by_photos(PDO $pdo, int $id, int $reviewerUserId): bool` — only from `status = 'open'` AND `photo_status = 'submitted'`.
  - `db_accept_gear_in_person(PDO $pdo, int $id, int $reviewerUserId): bool` — only from `status = 'open'`; leaves `photo_status` unchanged.
  - `db_revoke_gear_acceptance(PDO $pdo, int $id): bool` — only from `status = 'accepted'`; returns to `open`, clears `accepted_via`/reviewer/time, and turns `photo_status = 'accepted'` into `'submitted'`.

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/DbGearTest.php`:

```php
<?php
// wcma-calculator/tests/DbGearTest.php
use PHPUnit\Framework\TestCase;

final class DbGearTest extends TestCase
{
    private function users(PDO $pdo): array {
        $owner = db_create_user($pdo, ['email' => 'captain@example.com', 'name' => 'Captain', 'password_hash' => 'x', 'google_id' => null]);
        $admin = db_create_user($pdo, ['email' => 'tech@example.com', 'name' => 'Tech', 'password_hash' => 'x', 'google_id' => null]);
        return [$owner, $admin];
    }

    private function gear(PDO $pdo, int $owner, string $name = 'Jane Racer', int $season = 2026): int {
        return db_insert_gear_record($pdo, $owner, $name, strtolower($name), null, $season);
    }

    public function testInsertGetAndFind(): void
    {
        $pdo = make_temp_pdo();
        [$owner] = $this->users($pdo);
        $id = db_insert_gear_record($pdo, $owner, 'Jane Racer', 'jane racer', 'WCMA-123', 2026);

        $row = db_get_gear_record($pdo, $id);
        $this->assertSame('Jane Racer', $row['driver_name']);
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

    public function testDuplicateOwnerNameSeasonIsRejected(): void
    {
        $pdo = make_temp_pdo();
        [$owner] = $this->users($pdo);
        $this->gear($pdo, $owner);

        $this->expectException(PDOException::class);
        $this->gear($pdo, $owner);
    }

    public function testSameNameInAnotherSeasonOrForAnotherOwnerIsAllowed(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $this->gear($pdo, $owner, 'Jane Racer', 2026);
        $this->gear($pdo, $owner, 'Jane Racer', 2027);
        $this->gear($pdo, $admin, 'Jane Racer', 2026);
        $this->assertCount(2, db_get_user_gear_records($pdo, $owner));
    }

    public function testUserListIsNewestSeasonFirstThenByName(): void
    {
        $pdo = make_temp_pdo();
        [$owner] = $this->users($pdo);
        $old = $this->gear($pdo, $owner, 'Zed', 2025);
        $b = $this->gear($pdo, $owner, 'Bob', 2026);
        $a = $this->gear($pdo, $owner, 'Amy', 2026);

        $ids = array_map(fn($r) => (int)$r['id'], db_get_user_gear_records($pdo, $owner));
        $this->assertSame([$a, $b, $old], $ids);
    }

    public function testSeasonListIncludesOwnerNameAndEmail(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $this->gear($pdo, $owner, 'Jane Racer', 2026);
        $this->gear($pdo, $admin, 'Al Driver', 2026);
        $this->gear($pdo, $owner, 'Old Timer', 2025);

        $rows = db_get_gear_records_for_season($pdo, 2026);
        $this->assertSame(['Al Driver', 'Jane Racer'], array_map(fn($r) => $r['driver_name'], $rows));
        $this->assertSame('Tech', $rows[0]['owner_name']);
        $this->assertSame('captain@example.com', $rows[1]['owner_email']);
    }

    public function testMarkDraftOnlyFromNullAndNotWhenAccepted(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $id = $this->gear($pdo, $owner);

        db_mark_gear_photos_draft($pdo, $id);
        $this->assertSame('draft', db_get_gear_record($pdo, $id)['photo_status']);
        db_transition_gear_photo_status($pdo, $id, ['draft'], 'submitted');
        db_mark_gear_photos_draft($pdo, $id);
        $this->assertSame('submitted', db_get_gear_record($pdo, $id)['photo_status']);   // not reset

        $other = $this->gear($pdo, $owner, 'Accepted Driver');
        db_accept_gear_in_person($pdo, $other, $admin);
        db_mark_gear_photos_draft($pdo, $other);
        $this->assertNull(db_get_gear_record($pdo, $other)['photo_status']);
    }

    public function testTransitionIsAtomicAndNeverAppliesToAcceptedRecords(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $id = $this->gear($pdo, $owner);

        $this->assertFalse(db_transition_gear_photo_status($pdo, $id, ['draft'], 'submitted'));   // still NULL
        $this->assertFalse(db_transition_gear_photo_status($pdo, $id, [], 'submitted'));
        db_mark_gear_photos_draft($pdo, $id);
        $this->assertTrue(db_transition_gear_photo_status($pdo, $id, ['draft', 'needs_changes'], 'submitted'));
        $this->assertFalse(db_transition_gear_photo_status($pdo, $id, ['draft', 'needs_changes'], 'submitted'));
        $this->assertTrue(db_transition_gear_photo_status($pdo, $id, ['submitted'], 'needs_changes'));

        db_accept_gear_in_person($pdo, $id, $admin);
        $this->assertFalse(db_transition_gear_photo_status($pdo, $id, ['needs_changes'], 'submitted'));
    }

    public function testAcceptByPhotosOnlyFromSubmittedOnce(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $id = $this->gear($pdo, $owner);

        $this->assertFalse(db_accept_gear_by_photos($pdo, $id, $admin));
        db_mark_gear_photos_draft($pdo, $id);
        $this->assertFalse(db_accept_gear_by_photos($pdo, $id, $admin));
        db_transition_gear_photo_status($pdo, $id, ['draft'], 'submitted');

        $this->assertTrue(db_accept_gear_by_photos($pdo, $id, $admin));
        $row = db_get_gear_record($pdo, $id);
        $this->assertSame('accepted', $row['status']);
        $this->assertSame('photos', $row['accepted_via']);
        $this->assertSame('accepted', $row['photo_status']);
        $this->assertSame($admin, (int)$row['reviewed_by_user_id']);
        $this->assertNotNull($row['reviewed_at']);
        $this->assertFalse(db_accept_gear_by_photos($pdo, $id, $admin));
    }

    public function testAcceptInPersonFromOpenOnceAndLeavesPhotoStatus(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $id = $this->gear($pdo, $owner);
        db_mark_gear_photos_draft($pdo, $id);

        $this->assertTrue(db_accept_gear_in_person($pdo, $id, $admin));
        $row = db_get_gear_record($pdo, $id);
        $this->assertSame('accepted', $row['status']);
        $this->assertSame('in_person', $row['accepted_via']);
        $this->assertSame('draft', $row['photo_status']);
        $this->assertSame($admin, (int)$row['reviewed_by_user_id']);

        $this->assertFalse(db_accept_gear_in_person($pdo, $id, $admin));
        $this->assertFalse(db_accept_gear_in_person($pdo, 99999, $admin));
    }

    public function testRevokeReturnsToOpenAndRequeuesPhotoAcceptance(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);

        $byPhotos = $this->gear($pdo, $owner, 'Photo Driver');
        db_mark_gear_photos_draft($pdo, $byPhotos);
        db_transition_gear_photo_status($pdo, $byPhotos, ['draft'], 'submitted');
        db_accept_gear_by_photos($pdo, $byPhotos, $admin);
        $this->assertTrue(db_revoke_gear_acceptance($pdo, $byPhotos));
        $row = db_get_gear_record($pdo, $byPhotos);
        $this->assertSame('open', $row['status']);
        $this->assertSame('submitted', $row['photo_status']);
        foreach (['accepted_via', 'reviewed_by_user_id', 'reviewed_at'] as $col) $this->assertNull($row[$col], $col);

        $inPerson = $this->gear($pdo, $owner, 'Track Driver');
        db_mark_gear_photos_draft($pdo, $inPerson);
        db_accept_gear_in_person($pdo, $inPerson, $admin);
        $this->assertTrue(db_revoke_gear_acceptance($pdo, $inPerson));
        $this->assertSame('draft', db_get_gear_record($pdo, $inPerson)['photo_status']);

        $this->assertFalse(db_revoke_gear_acceptance($pdo, $inPerson));   // not accepted any more
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run (from `wcma-calculator/`): `php phpunit.phar --filter DbGearTest`
Expected: errors such as `Call to undefined function db_insert_gear_record()`.

- [ ] **Step 3: Add the table**

In `wcma-calculator/db.php`, inside `db_init()`, immediately before the comment `    // Add user_id to submissions if migrating an existing DB`, insert:

```php
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS gear_records (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_user_id       INTEGER NOT NULL,
            driver_name         TEXT NOT NULL,
            driver_name_norm    TEXT NOT NULL,
            licence_no          TEXT,
            season              INTEGER NOT NULL,
            photo_status        TEXT,
            status              TEXT NOT NULL DEFAULT 'open',
            accepted_via        TEXT,
            reviewed_by_user_id INTEGER,
            reviewed_at         DATETIME,
            created_at          DATETIME NOT NULL,
            updated_at          DATETIME NOT NULL,
            UNIQUE (owner_user_id, driver_name_norm, season)
        )
    ");

```

- [ ] **Step 4: Append the functions**

Append to the end of `wcma-calculator/db.php`:

```php

function db_insert_gear_record(PDO $pdo, int $ownerId, string $driverName, string $driverNameNorm, ?string $licenceNo, int $season): int {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("
        INSERT INTO gear_records (owner_user_id, driver_name, driver_name_norm, licence_no, season, created_at, updated_at)
        VALUES (:o, :n, :norm, :l, :s, :now, :now)
    ")->execute([':o' => $ownerId, ':n' => $driverName, ':norm' => $driverNameNorm, ':l' => $licenceNo, ':s' => $season, ':now' => $now]);
    return (int)$pdo->lastInsertId();
}

function db_get_gear_record(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM gear_records WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function db_find_gear_record(PDO $pdo, int $ownerId, string $driverNameNorm, int $season): ?array {
    $stmt = $pdo->prepare("SELECT * FROM gear_records WHERE owner_user_id = :o AND driver_name_norm = :n AND season = :s");
    $stmt->execute([':o' => $ownerId, ':n' => $driverNameNorm, ':s' => $season]);
    return $stmt->fetch() ?: null;
}

/** All of one owner's gear records, newest season first, then by driver name. */
function db_get_user_gear_records(PDO $pdo, int $ownerId): array {
    $stmt = $pdo->prepare("SELECT * FROM gear_records WHERE owner_user_id = :o ORDER BY season DESC, driver_name ASC, id ASC");
    $stmt->execute([':o' => $ownerId]);
    return $stmt->fetchAll();
}

/** Every owner's records for a season, with the owner's name and email, by driver name. */
function db_get_gear_records_for_season(PDO $pdo, int $season): array {
    $stmt = $pdo->prepare("
        SELECT g.*, u.name AS owner_name, u.email AS owner_email
        FROM gear_records g LEFT JOIN users u ON u.id = g.owner_user_id
        WHERE g.season = :s ORDER BY g.driver_name ASC, g.id ASC
    ");
    $stmt->execute([':s' => $season]);
    return $stmt->fetchAll();
}

/** First photo activity on a gear record: photo_status NULL -> 'draft'. No-op otherwise or once accepted. */
function db_mark_gear_photos_draft(PDO $pdo, int $id): void {
    $pdo->prepare("
        UPDATE gear_records SET photo_status = 'draft', updated_at = :now
        WHERE id = :id AND photo_status IS NULL AND status = 'open'
    ")->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id]);
}

/** Atomic photo_status transition: true only if the record is still open and its status was one of $from. */
function db_transition_gear_photo_status(PDO $pdo, int $id, array $from, string $to): bool {
    if (empty($from)) return false;
    $marks = implode(',', array_fill(0, count($from), '?'));
    $stmt = $pdo->prepare("
        UPDATE gear_records SET photo_status = ?, updated_at = ?
        WHERE id = ? AND status = 'open' AND photo_status IN ($marks)
    ");
    $stmt->execute(array_merge([$to, date('Y-m-d H:i:s'), $id], array_values($from)));
    return $stmt->rowCount() === 1;
}

/** Remote acceptance after reviewing photos. Atomic; only from a submitted photo set on an open record. */
function db_accept_gear_by_photos(PDO $pdo, int $id, int $reviewerUserId): bool {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        UPDATE gear_records SET
            status = 'accepted', accepted_via = 'photos', photo_status = 'accepted',
            reviewed_by_user_id = :reviewer, reviewed_at = :now, updated_at = :now
        WHERE id = :id AND status = 'open' AND photo_status = 'submitted'
    ");
    $stmt->execute([':reviewer' => $reviewerUserId, ':now' => $now, ':id' => $id]);
    return $stmt->rowCount() === 1;
}

/** In-person acceptance at the track: atomic, from any open record. */
function db_accept_gear_in_person(PDO $pdo, int $id, int $reviewerUserId): bool {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        UPDATE gear_records SET
            status = 'accepted', accepted_via = 'in_person',
            reviewed_by_user_id = :reviewer, reviewed_at = :now, updated_at = :now
        WHERE id = :id AND status = 'open'
    ");
    $stmt->execute([':reviewer' => $reviewerUserId, ':now' => $now, ':id' => $id]);
    return $stmt->rowCount() === 1;
}

/** Undo an acceptance: back to open; a photo-accepted set returns to the review queue. False if not accepted. */
function db_revoke_gear_acceptance(PDO $pdo, int $id): bool {
    $stmt = $pdo->prepare("
        UPDATE gear_records SET
            status = 'open', accepted_via = NULL,
            photo_status = CASE WHEN photo_status = 'accepted' THEN 'submitted' ELSE photo_status END,
            reviewed_by_user_id = NULL, reviewed_at = NULL, updated_at = :now
        WHERE id = :id AND status = 'accepted'
    ");
    $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id]);
    return $stmt->rowCount() === 1;
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php phpunit.phar`
Expected: `OK` for the whole suite, including `DbGearTest` (10 tests).

- [ ] **Step 6: Commit**

```bash
git add wcma-calculator/db.php wcma-calculator/tests/DbGearTest.php
git commit -m "feat(gear): add gear_records table and database functions"
```

---

### Task 2: Gear workflow library

**Files:**
- Create: `wcma-calculator/gear-lib.php`
- Test: `wcma-calculator/tests/GearLibTest.php`

**Interfaces:**
- Consumes: Task 1 db functions; `photoRequirements()`, `photoRequirementByKey()`, `photoSetMissingRequired()`; `pretechPlural()`, `pretechCapText()` (`pretech-lib.php`); `techCarStatusBadgeClass()` (`tech-status.php`); `db_get_inspection_photos()`, `db_set_inspection_photo_review()`, `db_set_all_photos_review_status()`.
- Produces (global functions):
  - `gearNameNorm(string $name): string`
  - `gearSeasonNow(): int`
  - `gearStatus(array $gear): array{state: string, via: ?string}` — states `accepted|needs_changes|pending_review|photos_draft|none`.
  - `gearStatusLabel(array $status, int $season): string`, `gearStatusBadgeClass(string $state): string`
  - `gearAccessShape(array $gear): array{user_id: int, status: string, photo_status: ?string}` — `status` is `teched` for an accepted record and `submitted` otherwise (so `inspectionCanAccess()` works unchanged).
  - `gearRequiredTotal(array $requirements, array $applicable): int`
  - `gearRosterFilter(array $records, string $filter): array` — `all` | `needs_gear` (not accepted) | `pending_review` | `accepted`; unknown means `all`.
  - `gearCreate(PDO $pdo, int $ownerId, string $name, string $licence, int $season): array{ok: bool, error: ?string, id: ?int}`
  - `gearRenew(PDO $pdo, int $ownerId, int $fromId, int $season): array{ok: bool, error: ?string, id: ?int}`
  - `gearSnapshot(PDO $pdo, int $id): array{photos, present, applicable, missing}`
  - `gearSubmit(PDO $pdo, int $id): array{ok, error}`
  - `gearAcceptByPhotos(PDO $pdo, int $id, int $reviewerUserId): array{ok, error}`
  - `gearSendBack(PDO $pdo, int $id, array $notes): array{ok, error, retakes}`
  - `gearAcceptInPerson(PDO $pdo, int $id, int $reviewerUserId): array{ok, error}`
  - `gearRevoke(PDO $pdo, int $id): array{ok, error}`

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/GearLibTest.php`:

```php
<?php
// wcma-calculator/tests/GearLibTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../gear-lib.php';

use PHPUnit\Framework\TestCase;

final class GearLibTest extends TestCase
{
    private function users(PDO $pdo): array {
        $owner = db_create_user($pdo, ['email' => 'captain@example.com', 'name' => 'Captain', 'password_hash' => 'x', 'google_id' => null]);
        $admin = db_create_user($pdo, ['email' => 'tech@example.com', 'name' => 'Tech', 'password_hash' => 'x', 'google_id' => null]);
        return [$owner, $admin];
    }

    private function newGear(PDO $pdo, int $owner, string $name = 'Jane Racer', int $season = 2026): int {
        $r = gearCreate($pdo, $owner, $name, '', $season);
        $this->assertTrue($r['ok'], (string)$r['error']);
        return $r['id'];
    }

    private function addPhoto(PDO $pdo, int $id, string $key, string $path = 'uploads/x.jpg'): void {
        db_upsert_inspection_photo($pdo, [
            'subject_type' => 'gear_record', 'subject_id' => $id, 'requirement_key' => $key,
            'requirement_version' => 1, 'file_path' => $path, 'typed_value' => null,
        ]);
        db_mark_gear_photos_draft($pdo, $id);
    }

    private function addRequired(PDO $pdo, int $id): void {
        foreach (photoRequirements('gear') as $key => $def) {
            if ($def['tier'] === 'required') $this->addPhoto($pdo, $id, $key);
        }
    }

    public function testNameNormalisation(): void
    {
        $this->assertSame('jane racer', gearNameNorm("  Jane   RACER \t"));
        $this->assertSame('', gearNameNorm('   '));
    }

    public function testStatusPrecedenceLabelsAndBadges(): void
    {
        $this->assertSame(['state' => 'none', 'via' => null], gearStatus(['status' => 'open', 'photo_status' => null]));
        $this->assertSame('photos_draft', gearStatus(['status' => 'open', 'photo_status' => 'draft'])['state']);
        $this->assertSame('pending_review', gearStatus(['status' => 'open', 'photo_status' => 'submitted'])['state']);
        $this->assertSame('pending_review', gearStatus(['status' => 'open', 'photo_status' => 'accepted'])['state']);
        $this->assertSame('needs_changes', gearStatus(['status' => 'open', 'photo_status' => 'needs_changes'])['state']);
        $this->assertSame(['state' => 'accepted', 'via' => 'photos'], gearStatus(['status' => 'accepted', 'accepted_via' => 'photos', 'photo_status' => 'accepted']));
        $this->assertSame('in_person', gearStatus(['status' => 'accepted', 'accepted_via' => null])['via']);
        $this->assertSame('accepted', gearStatus(['status' => 'accepted', 'photo_status' => 'needs_changes'])['state']);

        $this->assertSame('Gear teched 2026', gearStatusLabel(['state' => 'accepted', 'via' => 'in_person'], 2026));
        $this->assertSame('Gear pre-teched 2026', gearStatusLabel(['state' => 'accepted', 'via' => 'photos'], 2026));
        $this->assertSame('Needs gear check at the track', gearStatusLabel(['state' => 'none', 'via' => null], 2026));
        $this->assertSame('Photos pending review', gearStatusLabel(['state' => 'pending_review', 'via' => null], 2026));
        $this->assertSame('Photos need changes', gearStatusLabel(['state' => 'needs_changes', 'via' => null], 2026));
        $this->assertSame('Photos in progress', gearStatusLabel(['state' => 'photos_draft', 'via' => null], 2026));
        $this->assertSame('badge-ok', gearStatusBadgeClass('accepted'));
        $this->assertSame('badge-fail', gearStatusBadgeClass('needs_changes'));
        $this->assertSame('badge-pending', gearStatusBadgeClass('none'));
    }

    public function testNoBannedWordingInLabels(): void
    {
        foreach (['accepted', 'needs_changes', 'pending_review', 'photos_draft', 'none'] as $state) {
            foreach (['in_person', 'photos'] as $via) {
                $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', gearStatusLabel(['state' => $state, 'via' => $via], 2026));
            }
        }
    }

    public function testAccessShapeMapsOwnerAndLockState(): void
    {
        $this->assertSame(['user_id' => 5, 'status' => 'submitted', 'photo_status' => 'draft'],
            gearAccessShape(['owner_user_id' => '5', 'status' => 'open', 'photo_status' => 'draft']));
        $this->assertSame('teched', gearAccessShape(['owner_user_id' => 5, 'status' => 'accepted', 'photo_status' => null])['status']);

        $owner = ['id' => 5, 'role' => 'user'];
        $this->assertTrue(inspectionCanAccess($owner, gearAccessShape(['owner_user_id' => 5, 'status' => 'open', 'photo_status' => 'draft']), true));
        $this->assertFalse(inspectionCanAccess($owner, gearAccessShape(['owner_user_id' => 5, 'status' => 'open', 'photo_status' => 'submitted']), true));
        $this->assertFalse(inspectionCanAccess($owner, gearAccessShape(['owner_user_id' => 5, 'status' => 'accepted', 'photo_status' => null]), true));
        $this->assertTrue(inspectionCanAccess($owner, gearAccessShape(['owner_user_id' => 5, 'status' => 'accepted', 'photo_status' => null]), false));
        $this->assertFalse(inspectionCanAccess(['id' => 6, 'role' => 'user'], gearAccessShape(['owner_user_id' => 5, 'status' => 'open']), false));
    }

    public function testRequiredTotalForGearRequirements(): void
    {
        $reqs = photoRequirements('gear');
        $this->assertSame(4, gearRequiredTotal($reqs, []));
        $this->assertSame(5, gearRequiredTotal($reqs, ['underwear_label']));
        $this->assertSame(4, gearRequiredTotal($reqs, ['helmet_back']));   // recommended never counts
    }

    public function testRosterFilter(): void
    {
        $records = [
            ['id' => 1, 'status' => 'open', 'photo_status' => null],
            ['id' => 2, 'status' => 'open', 'photo_status' => 'submitted'],
            ['id' => 3, 'status' => 'accepted', 'accepted_via' => 'photos'],
            ['id' => 4, 'status' => 'open', 'photo_status' => 'needs_changes'],
        ];
        $ids = fn($f) => array_map(fn($r) => $r['id'], gearRosterFilter($records, $f));
        $this->assertSame([1, 2, 3, 4], $ids('all'));
        $this->assertSame([1, 2, 3, 4], $ids('bogus'));
        $this->assertSame([1, 2, 4], $ids('needs_gear'));
        $this->assertSame([2], $ids('pending_review'));
        $this->assertSame([3], $ids('accepted'));
    }

    public function testCreateValidatesAndRejectsDuplicates(): void
    {
        $pdo = make_temp_pdo();
        [$owner] = $this->users($pdo);

        $r = gearCreate($pdo, $owner, '  Jane   Racer ', ' WCMA-1 ', 2026);
        $this->assertTrue($r['ok'], (string)$r['error']);
        $row = db_get_gear_record($pdo, $r['id']);
        $this->assertSame('Jane Racer', $row['driver_name']);
        $this->assertSame('jane racer', $row['driver_name_norm']);
        $this->assertSame('WCMA-1', $row['licence_no']);

        $dup = gearCreate($pdo, $owner, 'JANE RACER', '', 2026);
        $this->assertFalse($dup['ok']);
        $this->assertStringContainsString('already have', $dup['error']);

        $this->assertFalse(gearCreate($pdo, $owner, '   ', '', 2026)['ok']);
        $this->assertFalse(gearCreate($pdo, $owner, str_repeat('x', 101), '', 2026)['ok']);
        $this->assertFalse(gearCreate($pdo, $owner, 'Sam', str_repeat('9', 41), 2026)['ok']);
        $this->assertTrue(gearCreate($pdo, $owner, 'Jane Racer', '', 2027)['ok']);   // another season is fine
        $noLicence = gearCreate($pdo, $owner, 'No Licence', '', 2026);
        $this->assertNull(db_get_gear_record($pdo, $noLicence['id'])['licence_no']);
    }

    public function testRenewCopiesNameAndLicenceForALaterSeason(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $old = gearCreate($pdo, $owner, 'Jane Racer', 'WCMA-1', 2025)['id'];
        db_accept_gear_in_person($pdo, $old, $admin);

        $r = gearRenew($pdo, $owner, $old, 2026);
        $this->assertTrue($r['ok'], (string)$r['error']);
        $new = db_get_gear_record($pdo, $r['id']);
        $this->assertSame('Jane Racer', $new['driver_name']);
        $this->assertSame('WCMA-1', $new['licence_no']);
        $this->assertSame(2026, (int)$new['season']);
        $this->assertSame('open', $new['status']);
        $this->assertNull($new['photo_status']);

        $this->assertFalse(gearRenew($pdo, $owner, $old, 2026)['ok']);        // already exists
        $this->assertFalse(gearRenew($pdo, $owner, $old, 2025)['ok']);        // not a later season
        $this->assertFalse(gearRenew($pdo, $owner + 99, $old, 2027)['ok']);   // not the owner
        $this->assertFalse(gearRenew($pdo, $owner, 99999, 2027)['ok']);
    }

    public function testSnapshotReportsPresentApplicableAndMissing(): void
    {
        $pdo = make_temp_pdo();
        [$owner] = $this->users($pdo);
        $id = $this->newGear($pdo, $owner);

        $this->assertCount(4, gearSnapshot($pdo, $id)['missing']);
        $this->addPhoto($pdo, $id, 'helmet_label');
        db_set_conditional_photo_applies($pdo, 'gear_record', $id, 'underwear_label', 1, true);

        $snap = gearSnapshot($pdo, $id);
        $this->assertSame(['helmet_label'], $snap['present']);
        $this->assertSame(['underwear_label'], $snap['applicable']);
        $this->assertCount(4, $snap['missing']);   // 3 required + underwear
        $this->assertContains('underwear_label', $snap['missing']);
        $this->assertNotContains('helmet_label', $snap['missing']);
    }

    public function testSubmitRules(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $id = $this->newGear($pdo, $owner);

        $this->assertStringContainsString('Add your photos', gearSubmit($pdo, $id)['error']);
        $this->addPhoto($pdo, $id, 'helmet_label');
        $this->assertStringContainsString('3 required photos are still missing', gearSubmit($pdo, $id)['error']);

        $this->addRequired($pdo, $id);
        db_set_conditional_photo_applies($pdo, 'gear_record', $id, 'underwear_label', 1, true);
        $this->assertStringContainsString('1 required photo is still missing', gearSubmit($pdo, $id)['error']);
        $this->addPhoto($pdo, $id, 'underwear_label');

        $this->assertTrue(gearSubmit($pdo, $id)['ok']);
        $this->assertSame('submitted', db_get_gear_record($pdo, $id)['photo_status']);
        $this->assertStringContainsString('already been submitted', gearSubmit($pdo, $id)['error']);
        $this->assertFalse(gearSubmit($pdo, 99999)['ok']);

        $done = $this->newGear($pdo, $owner, 'Accepted Driver');
        $this->addRequired($pdo, $done);
        gearAcceptInPerson($pdo, $done, $admin);
        $this->assertStringContainsString('already been teched', gearSubmit($pdo, $done)['error']);
    }

    public function testSendBackRulesAndResubmit(): void
    {
        $pdo = make_temp_pdo();
        [$owner] = $this->users($pdo);
        $id = $this->newGear($pdo, $owner);
        $this->addRequired($pdo, $id);
        $this->assertStringContainsString('not awaiting review', gearSendBack($pdo, $id, ['helmet_label' => 'Blurry'])['error']);   // still a draft
        gearSubmit($pdo, $id);

        $this->assertStringContainsString('at least one photo', gearSendBack($pdo, $id, [])['error']);
        $this->assertStringContainsString('note for every photo', gearSendBack($pdo, $id, ['helmet_label' => '  '])['error']);
        $this->assertFalse(gearSendBack($pdo, $id, ['not_a_photo' => 'x'])['ok']);

        $r = gearSendBack($pdo, $id, ['helmet_label' => 'Label not readable', 'suit_label' => 'Too dark']);
        $this->assertTrue($r['ok'], (string)$r['error']);
        $this->assertSame(['helmet_label' => 'Label not readable', 'suit_label' => 'Too dark'], $r['retakes']);
        $this->assertSame('needs_changes', db_get_gear_record($pdo, $id)['photo_status']);
        $photos = db_get_inspection_photos($pdo, 'gear_record', $id);
        $this->assertSame('retake', $photos['helmet_label']['review_status']);
        $this->assertSame('Label not readable', $photos['helmet_label']['reviewer_note']);
        $this->assertSame('pending', $photos['fhr_label']['review_status']);

        $this->assertStringContainsString('flagged', gearSubmit($pdo, $id)['error']);
        $this->addPhoto($pdo, $id, 'helmet_label', 'uploads/retaken1.jpg');
        $this->addPhoto($pdo, $id, 'suit_label', 'uploads/retaken2.jpg');
        $this->assertTrue(gearSubmit($pdo, $id)['ok']);
    }

    public function testAcceptByPhotosThenRevoke(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $id = $this->newGear($pdo, $owner);
        $this->addRequired($pdo, $id);

        $this->assertStringContainsString('not awaiting review', gearAcceptByPhotos($pdo, $id, $admin)['error']);
        gearSubmit($pdo, $id);
        $this->assertTrue(gearAcceptByPhotos($pdo, $id, $admin)['ok']);

        $row = db_get_gear_record($pdo, $id);
        $this->assertSame('accepted', $row['status']);
        $this->assertSame('photos', $row['accepted_via']);
        foreach (db_get_inspection_photos($pdo, 'gear_record', $id) as $photo) {
            $this->assertSame('accepted', $photo['review_status']);
        }
        $this->assertFalse(gearAcceptByPhotos($pdo, $id, $admin)['ok']);

        $this->assertTrue(gearRevoke($pdo, $id)['ok']);
        $this->assertSame('open', db_get_gear_record($pdo, $id)['status']);
        $this->assertSame('submitted', db_get_gear_record($pdo, $id)['photo_status']);
        $this->assertStringContainsString('has not been accepted', gearRevoke($pdo, $id)['error']);
        $this->assertFalse(gearRevoke($pdo, 99999)['ok']);
    }

    public function testAcceptInPersonWithoutPhotos(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $id = $this->newGear($pdo, $owner);

        $this->assertTrue(gearAcceptInPerson($pdo, $id, $admin)['ok']);
        $this->assertSame('in_person', db_get_gear_record($pdo, $id)['accepted_via']);
        $again = gearAcceptInPerson($pdo, $id, $admin);
        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('already been teched', $again['error']);
        $this->assertFalse(gearAcceptInPerson($pdo, 99999, $admin)['ok']);
    }

    public function testMessagesAvoidBannedWording(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $id = $this->newGear($pdo, $owner);
        $messages = [
            gearSubmit($pdo, $id)['error'], gearSubmit($pdo, 99999)['error'], gearAcceptByPhotos($pdo, $id, $admin)['error'],
            gearSendBack($pdo, $id, [])['error'], gearRevoke($pdo, $id)['error'], gearCreate($pdo, $owner, '', '', 2026)['error'],
        ];
        foreach ($messages as $m) {
            $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', (string)$m);
        }
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter GearLibTest`
Expected: fatal error, `gear-lib.php` not found.

- [ ] **Step 3: Write the library**

Create `wcma-calculator/gear-lib.php`:

```php
<?php
// wcma-calculator/gear-lib.php
//
// Driver gear records: status, create/renew, and the photo pre-tech workflow. Session-free
// (callers inject the reviewer id) so it is unit-testable. Callers must have loaded db.php.
require_once __DIR__ . '/photo-requirements.php';
require_once __DIR__ . '/inspection-lib.php';
require_once __DIR__ . '/pretech-lib.php';   // pretechPlural(), pretechCapText()

/** Collapse whitespace, trim, lowercase: the identity of a driver name within an owner and season. */
function gearNameNorm(string $name): string {
    return strtolower(trim((string)preg_replace('/\s+/', ' ', $name)));
}

function gearSeasonNow(): int {
    return (int)date('Y');
}

/**
 * Derived status of one gear record.
 * accepted > needs_changes > pending_review > photos_draft > none.
 *
 * @return array{state: string, via: ?string}
 */
function gearStatus(array $gear): array {
    if (($gear['status'] ?? '') === 'accepted') {
        return ['state' => 'accepted', 'via' => $gear['accepted_via'] ?? 'in_person'];
    }
    switch ($gear['photo_status'] ?? null) {
        case 'needs_changes': return ['state' => 'needs_changes', 'via' => null];
        case 'submitted':
        case 'accepted':      return ['state' => 'pending_review', 'via' => null];
        case 'draft':         return ['state' => 'photos_draft', 'via' => null];
    }
    return ['state' => 'none', 'via' => null];
}

function gearStatusLabel(array $status, int $season): string {
    switch ($status['state']) {
        case 'accepted':       return ($status['via'] === 'photos' ? 'Gear pre-teched ' : 'Gear teched ') . $season;
        case 'needs_changes':  return 'Photos need changes';
        case 'pending_review': return 'Photos pending review';
        case 'photos_draft':   return 'Photos in progress';
        default:               return 'Needs gear check at the track';
    }
}

function gearStatusBadgeClass(string $state): string {
    return techCarStatusBadgeClass($state);
}

/**
 * The record mapped to the shape inspectionCanAccess() expects of a tech sheet: owner in user_id,
 * 'teched' once accepted, and the photo_status that drives the owner write lock.
 *
 * @return array{user_id: int, status: string, photo_status: ?string}
 */
function gearAccessShape(array $gear): array {
    return [
        'user_id' => (int)$gear['owner_user_id'],
        'status' => ($gear['status'] ?? 'open') === 'accepted' ? 'teched' : 'submitted',
        'photo_status' => $gear['photo_status'] ?? null,
    ];
}

/** Number of photos needed for a complete set: required + applicable conditional (recommended never counts). */
function gearRequiredTotal(array $requirements, array $applicable): int {
    $total = 0;
    foreach ($requirements as $key => $req) {
        if ($req['tier'] === 'required' || ($req['tier'] === 'conditional' && in_array($key, $applicable, true))) $total++;
    }
    return $total;
}

/** $filter: 'all' | 'needs_gear' (not accepted) | 'pending_review' | 'accepted'. Unknown values mean 'all'. */
function gearRosterFilter(array $records, string $filter): array {
    if (!in_array($filter, ['needs_gear', 'pending_review', 'accepted'], true)) return $records;
    return array_values(array_filter($records, function (array $g) use ($filter): bool {
        $state = gearStatus($g)['state'];
        if ($filter === 'pending_review') return $state === 'pending_review';
        return $filter === 'accepted' ? $state === 'accepted' : $state !== 'accepted';
    }));
}

/** Creates a gear record for the owner. @return array{ok: bool, error: ?string, id: ?int} */
function gearCreate(PDO $pdo, int $ownerId, string $name, string $licence, int $season): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'id' => null];

    $name = trim((string)preg_replace('/\s+/', ' ', $name));
    $licence = trim($licence);
    if ($name === '') return $fail('Enter the driver\'s name.');
    if (strlen($name) > 100) return $fail('That name is too long (100 characters at most).');
    if (strlen($licence) > 40) return $fail('That licence number is too long (40 characters at most).');

    $norm = gearNameNorm($name);
    if (db_find_gear_record($pdo, $ownerId, $norm, $season) !== null) {
        return $fail('You already have a gear record for ' . $name . ' this season.');
    }
    try {
        $id = db_insert_gear_record($pdo, $ownerId, $name, $norm, $licence !== '' ? $licence : null, $season);
    } catch (PDOException $e) {
        return $fail('You already have a gear record for ' . $name . ' this season.');   // lost a race with a duplicate request
    }
    return ['ok' => true, 'error' => null, 'id' => $id];
}

/** New-season copy of an earlier record (same name and licence, no photos). @return array{ok: bool, error: ?string, id: ?int} */
function gearRenew(PDO $pdo, int $ownerId, int $fromId, int $season): array {
    $from = db_get_gear_record($pdo, $fromId);
    if ($from === null || (int)$from['owner_user_id'] !== $ownerId) {
        return ['ok' => false, 'error' => 'Gear record not found.', 'id' => null];
    }
    if ($season <= (int)$from['season']) {
        return ['ok' => false, 'error' => 'Choose a later season to renew for.', 'id' => null];
    }
    return gearCreate($pdo, $ownerId, $from['driver_name'], (string)($from['licence_no'] ?? ''), $season);
}

/** Photos of a gear record, which are present, which conditional ones apply, and what is still missing. */
function gearSnapshot(PDO $pdo, int $id): array {
    $photos = db_get_inspection_photos($pdo, 'gear_record', $id);
    $present = [];
    $applicable = [];
    foreach ($photos as $key => $row) {
        if ($row['file_path'] !== '') $present[] = $key;
        $req = photoRequirementByKey($key);
        if ($req !== null && $req['tier'] === 'conditional' && (int)$row['applies'] === 1) $applicable[] = $key;
    }
    return [
        'photos' => $photos,
        'present' => $present,
        'applicable' => $applicable,
        'missing' => photoSetMissingRequired('gear', $present, $applicable),
    ];
}

/** Owner submits a complete photo set for review. @return array{ok: bool, error: ?string} */
function gearSubmit(PDO $pdo, int $id): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg];

    $gear = db_get_gear_record($pdo, $id);
    if ($gear === null) return $fail('Gear record not found.');
    if ($gear['status'] === 'accepted') return $fail('This driver\'s gear has already been teched for the season.');

    $photoStatus = $gear['photo_status'] ?? null;
    if ($photoStatus === 'submitted') return $fail('These photos have already been submitted for review.');
    if (!in_array($photoStatus, ['draft', 'needs_changes'], true)) return $fail('Add your photos before submitting.');

    $snapshot = gearSnapshot($pdo, $id);
    $missing = count($snapshot['missing']);
    if ($missing > 0) {
        return $fail($missing . ' required ' . pretechPlural($missing, 'photo is', 'photos are') . ' still missing.');
    }
    foreach ($snapshot['photos'] as $row) {
        if ($row['file_path'] !== '' && $row['review_status'] === 'retake') {
            return $fail('Please retake the photos the inspector flagged before submitting again.');
        }
    }

    if (!db_transition_gear_photo_status($pdo, $id, ['draft', 'needs_changes'], 'submitted')) {
        return $fail('These photos could not be submitted. Please reload and try again.');
    }
    return ['ok' => true, 'error' => null];
}

/** Inspector accepts a submitted photo set remotely. @return array{ok: bool, error: ?string} */
function gearAcceptByPhotos(PDO $pdo, int $id, int $reviewerUserId): array {
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        if (!db_accept_gear_by_photos($pdo, $id, $reviewerUserId)) {
            if ($own) $pdo->rollBack();
            return ['ok' => false, 'error' => 'These photos are not awaiting review.'];
        }
        db_set_all_photos_review_status($pdo, 'gear_record', $id, 'accepted');
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'error' => null];
}

/**
 * Inspector sends individual photos back for a retake. Every flagged photo needs a note.
 *
 * @param array<string,string> $notes requirement key => note
 * @return array{ok: bool, error: ?string, retakes: array<string,string>}
 */
function gearSendBack(PDO $pdo, int $id, array $notes): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'retakes' => []];

    $gear = db_get_gear_record($pdo, $id);
    if ($gear === null) return $fail('Gear record not found.');
    if ($gear['status'] === 'accepted' || ($gear['photo_status'] ?? null) !== 'submitted') {
        return $fail('These photos are not awaiting review.');
    }

    $photos = db_get_inspection_photos($pdo, 'gear_record', $id);
    $retakes = [];
    foreach ($notes as $key => $note) {
        if (!is_string($note) || !isset($photos[$key]) || $photos[$key]['file_path'] === '') continue;
        $note = pretechCapText(trim($note), 500);
        if ($note === '') return $fail('Add a note for every photo you send back.');
        $retakes[$key] = $note;
    }
    if (!$retakes) return $fail('Choose at least one photo to retake and say what is wrong.');

    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        if (!db_transition_gear_photo_status($pdo, $id, ['submitted'], 'needs_changes')) {
            if ($own) $pdo->rollBack();
            return $fail('These photos are not awaiting review.');
        }
        foreach ($retakes as $key => $note) {
            db_set_inspection_photo_review($pdo, (int)$photos[$key]['id'], 'retake', $note);
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'error' => null, 'retakes' => $retakes];
}

/** Inspector accepts the driver's gear in person: no photos needed. @return array{ok: bool, error: ?string} */
function gearAcceptInPerson(PDO $pdo, int $id, int $reviewerUserId): array {
    $gear = db_get_gear_record($pdo, $id);
    if ($gear === null) return ['ok' => false, 'error' => 'Gear record not found.'];
    if (!db_accept_gear_in_person($pdo, $id, $reviewerUserId)) {
        return ['ok' => false, 'error' => 'This driver\'s gear has already been teched.'];
    }
    return ['ok' => true, 'error' => null];
}

/** Undo an acceptance (for example the wrong driver was accepted). @return array{ok: bool, error: ?string} */
function gearRevoke(PDO $pdo, int $id): array {
    if (db_get_gear_record($pdo, $id) === null) return ['ok' => false, 'error' => 'Gear record not found.'];
    if (!db_revoke_gear_acceptance($pdo, $id)) return ['ok' => false, 'error' => 'This gear record has not been accepted.'];
    return ['ok' => true, 'error' => null];
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar`
Expected: `OK` for the whole suite, including `GearLibTest` (14 tests).

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/gear-lib.php wcma-calculator/tests/GearLibTest.php
git commit -m "feat(gear): add gear record workflow library"
```

---

### Task 3: Photo endpoint support for gear records

**Files:**
- Modify: `wcma-calculator/inspection-lib.php` (subject scope constant; draft marking in `inspectionSavePhoto` and `inspectionSetApplies`)
- Modify: `wcma-calculator/inspection.php` (require gear lib; load gear subjects)
- Test: `wcma-calculator/tests/GearInspectionTest.php`; add cases to `wcma-calculator/tests/InspectionEndpointTest.php`

**Interfaces:**
- Consumes: Task 1-2; existing `inspectionLoadSubject()`, `inspectionCanAccess()`.
- Produces:
  - `INSPECTION_SUBJECT_SCOPE` is `['tech_sheet' => 'car', 'gear_record' => 'gear']`.
  - Saving a gear photo, or turning a gear conditional photo on, marks the record's photos `draft`.
  - `inspection.php` `upload`/`applies`/`typed`/`delete`/`photo` accept `subject_type=gear_record` (and photos of a gear record), authorised through `gearAccessShape()` (owner; write lock; admins always).

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/GearInspectionTest.php`:

```php
<?php
// wcma-calculator/tests/GearInspectionTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../gear-lib.php';

use PHPUnit\Framework\TestCase;

final class GearInspectionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wcma_gi_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
        rmdir($this->dir);
    }

    private function tmpJpeg(): string {
        $p = $this->dir . '/' . uniqid('up_') . '.bin';
        file_put_contents($p, hex2bin('ffd8ffc00011080001000103011100021100031100ffd9'));
        return $p;
    }

    private function gear(PDO $pdo): int {
        $owner = db_create_user($pdo, ['email' => 'captain@example.com', 'name' => 'Captain', 'password_hash' => 'x', 'google_id' => null]);
        return gearCreate($pdo, $owner, 'Jane Racer', '', 2026)['id'];
    }

    public function testSubjectScopeIncludesGearRecords(): void
    {
        $this->assertSame('gear', INSPECTION_SUBJECT_SCOPE['gear_record']);
        $this->assertSame('car', INSPECTION_SUBJECT_SCOPE['tech_sheet']);
    }

    public function testSavingAGearPhotoStoresItUnderTheGearFolderAndMarksTheRecordDraft(): void
    {
        $pdo = make_temp_pdo();
        $id = $this->gear($pdo);

        $r = inspectionSavePhoto($pdo, $this->dir, 'gear_record', $id, 'helmet_label', $this->tmpJpeg(), ['standard' => 'SA2020', 'date' => '03/2024'], 'rename');

        $this->assertTrue($r['ok'], (string)$r['error']);
        $this->assertSame("uploads/inspection/gear_record/$id/helmet_label.jpg", $r['photo']['file_path']);
        $this->assertFileExists($this->dir . '/' . $r['photo']['file_path']);
        $this->assertSame('draft', db_get_gear_record($pdo, $id)['photo_status']);
    }

    public function testGearSubjectsOnlyAcceptGearRequirements(): void
    {
        $pdo = make_temp_pdo();
        $id = $this->gear($pdo);

        $car = inspectionSavePhoto($pdo, $this->dir, 'gear_record', $id, 'front_34', $this->tmpJpeg(), [], 'rename');
        $this->assertFalse($car['ok']);
        $this->assertStringContainsString('Unknown photo type', $car['error']);

        $gearOnCarSubject = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 7, 'helmet_label', $this->tmpJpeg(), [], 'rename');
        $this->assertFalse($gearOnCarSubject['ok']);
    }

    public function testAppliesForGearConditionalMarksDraftAndRefusesRequiredKeys(): void
    {
        $pdo = make_temp_pdo();
        $id = $this->gear($pdo);

        $this->assertTrue(inspectionSetApplies($pdo, $this->dir, 'gear_record', $id, 'underwear_label', true)['ok']);
        $this->assertSame('draft', db_get_gear_record($pdo, $id)['photo_status']);
        $this->assertArrayHasKey('underwear_label', db_get_inspection_photos($pdo, 'gear_record', $id));

        $required = inspectionSetApplies($pdo, $this->dir, 'gear_record', $id, 'helmet_label', true);
        $this->assertFalse($required['ok']);
        $this->assertStringContainsString('not optional', $required['error']);

        $this->assertTrue(inspectionSetApplies($pdo, $this->dir, 'gear_record', $id, 'underwear_label', false)['ok']);
        $this->assertArrayNotHasKey('underwear_label', db_get_inspection_photos($pdo, 'gear_record', $id));
    }

    public function testTypedUpdateWorksOnGearPhotos(): void
    {
        $pdo = make_temp_pdo();
        $id = $this->gear($pdo);
        $saved = inspectionSavePhoto($pdo, $this->dir, 'gear_record', $id, 'suit_label', $this->tmpJpeg(), [], 'rename');

        $r = inspectionUpdateTyped($pdo, (int)$saved['photo']['id'], ['rating' => 'SFI 3.2A/5']);
        $this->assertTrue($r['ok'], (string)$r['error']);
        $this->assertSame(['rating' => 'SFI 3.2A/5'], $r['photo']['typed']);
    }
}
```

Add to `wcma-calculator/tests/InspectionEndpointTest.php`, inside the class (uses helpers already defined there: `request()`, `session()`, `post()`, the `CSRF` constant, and the `$ownerId`, `$otherId`, `$adminId` properties; add a `$gearIds` property by declaring it at the point of first use as shown, and extend `tearDown()` per the note below):

```php
    private array $gearIds = [];

    /** A gear record owned by $ownerId with a high forced id so real uploads/ folders cannot collide. */
    private function makeGear(int $ownerId): int
    {
        $id = gearCreate($this->pdo, $ownerId, 'Jane Racer', '', 2026)['id'];
        $forced = 900000 + random_int(1, 90000);
        $this->pdo->prepare('UPDATE gear_records SET id = :new WHERE id = :old')->execute([':new' => $forced, ':old' => $id]);
        $this->gearIds[] = $forced;
        return $forced;
    }

    public function testGearRecordAppliesAndTypedAreOwnerScopedAndLocked(): void
    {
        $gear = $this->makeGear($this->ownerId);
        $fields = ['subject_type' => 'gear_record', 'subject_id' => $gear, 'requirement_key' => 'underwear_label', 'applies' => '1'];

        $this->assertSame(401, $this->post('applies', $fields, null)['status']);
        $this->assertSame(403, $this->post('applies', $fields, $this->ownerId, 'user', 'wrong')['status']);
        $this->assertSame(404, $this->post('applies', $fields, $this->otherId)['status']);

        $on = $this->post('applies', $fields, $this->ownerId);
        $this->assertSame(200, $on['status']);
        $this->assertArrayHasKey('underwear_label', db_get_inspection_photos($this->pdo, 'gear_record', $gear));
        $this->assertSame('draft', db_get_gear_record($this->pdo, $gear)['photo_status']);

        $this->pdo->prepare("UPDATE gear_records SET photo_status = 'submitted' WHERE id = :id")->execute([':id' => $gear]);
        $this->assertSame(404, $this->post('applies', ['applies' => '0'] + $fields, $this->ownerId)['status']);   // locked while under review
        $this->assertSame(200, $this->post('applies', ['applies' => '0'] + $fields, $this->adminId, 'admin')['status']);

        $this->pdo->prepare("UPDATE gear_records SET photo_status = NULL, status = 'accepted' WHERE id = :id")->execute([':id' => $gear]);
        $this->assertSame(404, $this->post('applies', $fields, $this->ownerId)['status']);   // locked once accepted
    }

    public function testGearPhotoIsServedToOwnerAndAdminOnly(): void
    {
        $gear = $this->makeGear($this->ownerId);
        $tmp = $this->payloadDir . '/' . uniqid('gseed_') . '.bin';
        file_put_contents($tmp, hex2bin('ffd8ffc00011080001000103011100021100031100ffd9'));
        $saved = inspectionSavePhoto($this->pdo, dirname(__DIR__), 'gear_record', $gear, 'helmet_label', $tmp, [], 'rename');
        $this->assertTrue($saved['ok'], (string)$saved['error']);
        $photoId = (string)$saved['photo']['id'];

        $get = fn(?int $uid, string $role = 'user') => $this->request('GET', ['action' => 'photo', 'id' => $photoId], [], $this->session($uid, $role));
        $this->assertSame(200, $get($this->ownerId)['status']);
        $this->assertSame(200, $get($this->adminId, 'admin')['status']);
        $this->assertSame(404, $get($this->otherId)['status']);
        $this->assertSame(401, $get(null)['status']);
    }

    public function testUnknownGearRecordIdIsTheSame404(): void
    {
        $fields = ['subject_type' => 'gear_record', 'subject_id' => 999999999, 'requirement_key' => 'underwear_label', 'applies' => '1'];
        $this->assertSame(404, $this->post('applies', $fields, $this->ownerId)['status']);
    }
```

Also, in the same file, extend `tearDown()` so gear upload folders are removed: after the existing loop that removes `uploads/inspection/tech_sheet/<id>` directories, add

```php
        foreach ($this->gearIds as $id) {
            $this->removeDir(dirname(__DIR__) . '/uploads/inspection/gear_record/' . $id);
        }
```
and add `require_once __DIR__ . '/../gear-lib.php';` next to the other `require_once` lines at the top of that test file.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter "GearInspectionTest|InspectionEndpointTest"`
Expected: failures such as `Failed asserting that an array has the key 'gear_record'` and gear endpoint requests returning 404 for valid owners.

- [ ] **Step 3: Update the library**

In `wcma-calculator/inspection-lib.php`, replace the constant

```php
const INSPECTION_SUBJECT_SCOPE = ['tech_sheet' => 'car'];
```
(keep the explanatory comment above it; update the sentence that mentions adding keys so it also says the gear lib is required by `inspection.php`) with:

```php
const INSPECTION_SUBJECT_SCOPE = ['tech_sheet' => 'car', 'gear_record' => 'gear'];
```

In `inspectionSavePhoto`, change

```php
    if ($subjectType === 'tech_sheet') db_mark_tech_sheet_photos_draft($pdo, $subjectId);

    $stored =
```
to
```php
    if ($subjectType === 'tech_sheet') {
        db_mark_tech_sheet_photos_draft($pdo, $subjectId);
    } elseif ($subjectType === 'gear_record') {
        db_mark_gear_photos_draft($pdo, $subjectId);
    }

    $stored =
```

In `inspectionSetApplies`, change

```php
    if ($applies && $subjectType === 'tech_sheet') db_mark_tech_sheet_photos_draft($pdo, $subjectId);
```
to
```php
    if ($applies && $subjectType === 'tech_sheet') {
        db_mark_tech_sheet_photos_draft($pdo, $subjectId);
    } elseif ($applies && $subjectType === 'gear_record') {
        db_mark_gear_photos_draft($pdo, $subjectId);
    }
```

- [ ] **Step 4: Update the endpoint**

In `wcma-calculator/inspection.php`, add after the existing `require __DIR__ . '/inspection-lib.php';` line:

```php
require __DIR__ . '/gear-lib.php';
```
and replace the `match` in `inspectionLoadSubject` so it handles both subject types. The function currently looks like:

```php
function inspectionLoadSubject(PDO $pdo, array $user, string $type, int $id, bool $forWrite): ?array {
    if (!isset(INSPECTION_SUBJECT_SCOPE[$type])) return null;
    $sheet = match ($type) {
        'tech_sheet' => db_get_tech_sheet($pdo, $id),
        default => null,
    };
    if (!$sheet || !inspectionCanAccess($user, $sheet, $forWrite)) return null;
    return $sheet;
}
```
Change the `match` and the access check to:

```php
    $subject = match ($type) {
        'tech_sheet' => db_get_tech_sheet($pdo, $id),
        'gear_record' => ($gear = db_get_gear_record($pdo, $id)) ? gearAccessShape($gear) : null,
        default => null,
    };
    if (!$subject || !inspectionCanAccess($user, $subject, $forWrite)) return null;
    return $subject;
```
(Rename the local variable in the rest of the function body from `$sheet` to `$subject` where it appears; keep the function signature and return type.)

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php -l inspection.php && php phpunit.phar`
Expected: `No syntax errors detected`, then `OK` for the whole suite (the existing tech-sheet endpoint tests must still pass, including the earlier "unknown subject type" cases).

- [ ] **Step 6: Commit**

```bash
git add wcma-calculator/inspection-lib.php wcma-calculator/inspection.php wcma-calculator/tests/GearInspectionTest.php wcma-calculator/tests/InspectionEndpointTest.php
git commit -m "feat(gear): let the photo endpoint handle gear record subjects"
```

---

### Task 4: Gear emails

**Files:**
- Create: `wcma-calculator/gear-email.php`
- Test: `wcma-calculator/tests/GearEmailTest.php`

**Interfaces:**
- Consumes: `pretechEmailWrap()`, `pretechEmailPara()`, `pretechEmailLink()` (`pretech-email.php`); `TECH_ACCEPTANCE_DISCLAIMER`; `db_find_user_by_id()`, `db_get_inspection_photos()`, `photoRequirementByKey()`.
- Produces:
  - `gearEmailSubmitted(array $gear, string $adminUrl, string $pageUrl, int $photoCount, bool $forClub): array{subject, html, text}`
  - `gearEmailSentBack(array $gear, array $retakes, string $pageUrl): array{subject, html, text}` — `$retakes` is a list of `['label' => string, 'note' => string]`.
  - `gearEmailAccepted(array $gear, string $pageUrl, string $adminUrl, bool $forClub): array{subject, html, text}`
  - `gearNotify(PDO $pdo, string $kind, array $gear, string $baseUrl, array $club, callable $sendFn, array $retakes = []): bool` — `$kind` is `submitted` | `sent_back` | `accepted`; recipients: the owner from the users table, the `$club` address; each message sent independently (one failure or exception does not stop the others); returns true only if all were sent.

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/GearEmailTest.php`:

```php
<?php
// wcma-calculator/tests/GearEmailTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../gear-email.php';

use PHPUnit\Framework\TestCase;

final class GearEmailTest extends TestCase
{
    private function gear(array $o = []): array {
        return array_merge(['id' => 4, 'owner_user_id' => 1, 'driver_name' => 'Jane <Racer>', 'season' => 2026], $o);
    }

    public function testSubmittedEmailsForClubAndOwner(): void
    {
        $club = gearEmailSubmitted($this->gear(), 'https://x.test/admin.php?action=gear-record&id=4', 'https://x.test/gear.php?action=pretech&id=4', 5, true);
        $this->assertStringContainsString('Gear Pre-Tech Submitted', $club['subject']);
        $this->assertStringContainsString('cid:wcma-logo', $club['html']);
        $this->assertStringContainsString('https://x.test/admin.php?action=gear-record&amp;id=4', $club['html']);
        $this->assertStringContainsString('5 photos', $club['text']);
        $this->assertStringContainsString('Jane &lt;Racer&gt;', $club['html']);
        $this->assertStringNotContainsString('<Racer>', $club['html']);

        $owner = gearEmailSubmitted($this->gear(), 'https://x.test/admin', 'https://x.test/page', 5, false);
        $this->assertStringContainsString('received your photos', $owner['text']);
        $this->assertStringContainsString('checked in person', $owner['text']);
        $this->assertStringNotContainsString('https://x.test/admin', $owner['text']);
        $this->assertStringNotContainsString('https://x.test/admin', $owner['html']);
    }

    public function testSentBackListsEachRetakeWithItsNote(): void
    {
        $mail = gearEmailSentBack($this->gear(), [
            ['label' => 'Helmet certification label', 'note' => 'Date not readable'],
            ['label' => 'Race suit label', 'note' => 'Too dark <flash>'],
        ], 'https://x.test/gear.php?action=pretech&id=4');

        $this->assertStringContainsString('changes needed', $mail['subject']);
        foreach (['Helmet certification label', 'Date not readable', 'Race suit label', 'Too dark'] as $needle) {
            $this->assertStringContainsString($needle, $mail['html']);
            $this->assertStringContainsString($needle, $mail['text']);
        }
        $this->assertStringContainsString('&lt;flash&gt;', $mail['html']);
        $this->assertStringContainsString('https://x.test/gear.php?action=pretech&amp;id=4', $mail['html']);
    }

    public function testAcceptedOwnerAndClubCopies(): void
    {
        $owner = gearEmailAccepted($this->gear(), 'https://x.test/gear.php?action=pretech&id=4', 'https://x.test/admin.php?action=gear-record&id=4', false);
        $club = gearEmailAccepted($this->gear(), 'https://x.test/gear.php?action=pretech&id=4', 'https://x.test/admin.php?action=gear-record&id=4', true);

        foreach ([$owner, $club] as $mail) {
            $this->assertStringContainsString('Gear Pre-Tech Accepted', $mail['subject']);
            $this->assertStringContainsString(TECH_ACCEPTANCE_DISCLAIMER, $mail['html']);
            $this->assertStringContainsString(TECH_ACCEPTANCE_DISCLAIMER, $mail['text']);
            $this->assertStringContainsString('2026', $mail['text']);
        }
        $this->assertStringContainsString('decals', $owner['text']);
        $this->assertStringNotContainsString('admin.php', $owner['text']);
        $this->assertStringNotContainsString('admin.php', $owner['html']);
        $this->assertStringContainsString('admin.php?action=gear-record&id=4', $club['text']);
        $this->assertStringNotContainsString('You do not need', $club['text']);
    }

    public function testNoBannedWordingOutsideTheDisclaimer(): void
    {
        $mails = [
            gearEmailSubmitted($this->gear(), 'a', 'b', 3, true), gearEmailSubmitted($this->gear(), 'a', 'b', 3, false),
            gearEmailSentBack($this->gear(), [['label' => 'X', 'note' => 'Y']], 'b'),
            gearEmailAccepted($this->gear(), 'b', 'a', true), gearEmailAccepted($this->gear(), 'b', 'a', false),
        ];
        foreach ($mails as $mail) {
            $text = str_replace(TECH_ACCEPTANCE_DISCLAIMER, '', $mail['subject'] . "\n" . $mail['text']);
            $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', $text);
        }
    }

    private function fixture(): array {
        $pdo = make_temp_pdo();
        $ownerId = db_create_user($pdo, ['email' => 'jane@example.com', 'name' => 'Jane', 'password_hash' => 'x', 'google_id' => null]);
        return [$pdo, $this->gear(['owner_user_id' => $ownerId])];
    }

    private function recorder(array &$log, bool $result = true): callable {
        return function (array $to, array $message) use (&$log, $result): bool {
            $log[] = ['to' => $to, 'subject' => $message['subject']];
            return $result;
        };
    }

    public function testNotifySubmittedAndAcceptedGoToBoth(): void
    {
        [$pdo, $gear] = $this->fixture();
        $club = ['email' => 'club@example.com', 'name' => 'Club'];
        foreach (['submitted', 'accepted'] as $kind) {
            $log = [];
            $this->assertTrue(gearNotify($pdo, $kind, $gear, 'https://x.test/', $club, $this->recorder($log)), $kind);
            $this->assertCount(2, $log, $kind);
            $this->assertEqualsCanonicalizing(['club@example.com', 'jane@example.com'], array_map(fn($e) => $e['to'][0][0], $log), $kind);
        }
    }

    public function testNotifySentBackGoesToTheOwnerWithLabelsFromTheRequirementList(): void
    {
        [$pdo, $gear] = $this->fixture();
        $captured = [];
        $sendFn = function (array $to, array $message) use (&$captured): bool { $captured[] = [$to, $message]; return true; };

        $this->assertTrue(gearNotify($pdo, 'sent_back', $gear, 'https://x.test', ['email' => 'club@example.com', 'name' => 'Club'], $sendFn, ['helmet_label' => 'Date not readable']));
        $this->assertCount(1, $captured);
        $this->assertSame('jane@example.com', $captured[0][0][0][0]);
        $this->assertStringContainsString('Helmet certification label', $captured[0][1]['text']);
        $this->assertStringContainsString('Date not readable', $captured[0][1]['text']);
        $this->assertStringContainsString('https://x.test/gear.php?action=pretech&id=4', $captured[0][1]['text']);
    }

    public function testNotifyIsolatesEachMessageAndReportsFailure(): void
    {
        [$pdo, $gear] = $this->fixture();
        $club = ['email' => 'club@example.com', 'name' => 'Club'];

        $log = [];
        $this->assertFalse(gearNotify($pdo, 'accepted', $gear, 'https://x.test', $club, $this->recorder($log, false)));
        $this->assertCount(2, $log);

        $calls = 0;
        $boomFirst = function (array $to, array $message) use (&$calls): bool {
            $calls++;
            if ($calls === 1) throw new RuntimeException('smtp down');
            return true;
        };
        $this->assertFalse(gearNotify($pdo, 'accepted', $gear, 'https://x.test', $club, $boomFirst));
        $this->assertSame(2, $calls);

        $this->assertFalse(gearNotify($pdo, 'bogus', $gear, 'https://x.test', $club, $this->recorder($log)));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter GearEmailTest`
Expected: fatal error, `gear-email.php` not found.

- [ ] **Step 3: Write the emails module**

Create `wcma-calculator/gear-email.php`:

```php
<?php
// wcma-calculator/gear-email.php
//
// Emails for the driver gear pre-tech workflow: pure renderers (branded like the car pre-tech
// emails, logo via cid:wcma-logo, photos never embedded) and a notifier with an injectable send
// function. Callers must have loaded db.php first.
require_once __DIR__ . '/pretech-email.php';   // pretechEmailWrap/Para/Link, view_helpers, photo-requirements, disclaimer

function gearEmailDriverLine(array $gear): string {
    return $gear['driver_name'] . ' — ' . (int)($gear['season'] ?? date('Y'));
}

/** @return array{subject: string, html: string, text: string} */
function gearEmailSubmitted(array $gear, string $adminUrl, string $pageUrl, int $photoCount, bool $forClub): array {
    $driver = gearEmailDriverLine($gear);
    $count = $photoCount . ' ' . ($photoCount === 1 ? 'photo' : 'photos');

    if ($forClub) {
        $subject = 'WCMA Gear Pre-Tech Submitted — ' . $driver;
        $lines = ['A gear pre-tech photo set is waiting for review.', $driver, 'Photos submitted: ' . $count . '.', 'Review it here:', $adminUrl];
        $html = pretechEmailPara($lines[0]) . pretechEmailPara($driver) . pretechEmailPara($lines[2]) . pretechEmailLink($adminUrl, 'Open the review page');
        $title = 'GEAR PRE-TECH SUBMITTED';
    } else {
        $subject = 'Your WCMA gear pre-tech submission — ' . $driver;
        $lines = [
            'We received your photos (' . $count . ') for ' . $driver . '.',
            'An inspector will review them. You will get an email when they are accepted or when a photo needs to be retaken.',
            'Your gear can still be checked in person at the track if you prefer.',
            'You can check your submission here:', $pageUrl,
        ];
        $html = pretechEmailPara($lines[0]) . pretechEmailPara($lines[1]) . pretechEmailPara($lines[2]) . pretechEmailLink($pageUrl, 'View your gear photos');
        $title = 'GEAR PRE-TECH RECEIVED';
    }
    return ['subject' => $subject, 'html' => pretechEmailWrap($title, $html), 'text' => implode("\n\n", $lines) . "\n"];
}

/**
 * @param array<int, array{label: string, note: string}> $retakes
 * @return array{subject: string, html: string, text: string}
 */
function gearEmailSentBack(array $gear, array $retakes, string $pageUrl): array {
    $driver = gearEmailDriverLine($gear);
    $intro = 'An inspector reviewed the gear pre-tech photos for ' . $driver . ' and needs the following photos retaken:';

    $html = pretechEmailPara($intro) . '<ul>';
    $text = $intro . "\n\n";
    foreach ($retakes as $r) {
        $html .= '<li><strong>' . h($r['label']) . '</strong>: ' . h($r['note']) . '</li>';
        $text .= '- ' . $r['label'] . ': ' . $r['note'] . "\n";
    }
    $html .= '</ul>' . pretechEmailPara('Retake them and submit again. The photos that were not flagged do not need to be redone.')
        . pretechEmailLink($pageUrl, 'Open your gear page');
    $text .= "\nRetake them and submit again. The photos that were not flagged do not need to be redone.\n" . $pageUrl . "\n";

    return [
        'subject' => 'WCMA Gear Pre-Tech — changes needed — ' . $driver,
        'html' => pretechEmailWrap('GEAR PRE-TECH: CHANGES NEEDED', $html),
        'text' => $text,
    ];
}

/** @return array{subject: string, html: string, text: string} */
function gearEmailAccepted(array $gear, string $pageUrl, string $adminUrl, bool $forClub): array {
    $driver = gearEmailDriverLine($gear);
    $season = (int)($gear['season'] ?? date('Y'));

    if ($forClub) {
        $lines = [
            'The gear pre-tech photos for ' . $driver . ' were reviewed and accepted. This driver\'s gear is pre-teched for ' . $season . '.',
            'No gear check is needed at the track.',
            TECH_ACCEPTANCE_DISCLAIMER,
            'Review page:', $adminUrl,
        ];
        $link = pretechEmailLink($adminUrl, 'Open the review page');
    } else {
        $lines = [
            'The gear pre-tech photos for ' . $driver . ' were reviewed and accepted. This driver\'s gear is pre-teched for ' . $season . '.',
            'You do not need your gear checked at the track: just collect your decals at the event.',
            TECH_ACCEPTANCE_DISCLAIMER,
            'Your gear page:', $pageUrl,
        ];
        $link = pretechEmailLink($pageUrl, 'View your gear page');
    }
    $html = pretechEmailPara($lines[0]) . pretechEmailPara($lines[1])
        . '<p style="font-size:0.85rem;color:#555">' . h(TECH_ACCEPTANCE_DISCLAIMER) . '</p>' . $link;

    return [
        'subject' => 'WCMA Gear Pre-Tech Accepted — ' . $driver,
        'html' => pretechEmailWrap('GEAR PRE-TECH ACCEPTED', $html),
        'text' => implode("\n\n", $lines) . "\n",
    ];
}

/**
 * Sends the emails for one gear workflow step. Failures never propagate: the workflow step has
 * already happened, so this only reports whether every message was sent, and each message is
 * sent independently.
 *
 * @param string $kind 'submitted' | 'sent_back' | 'accepted'
 * @param array{email: string, name: string} $club recipient for club copies
 * @param callable $sendFn function(array $to, array $message): bool; $to is a list of [email, name]
 * @param array<string,string> $retakes requirement key => note (sent_back only)
 */
function gearNotify(PDO $pdo, string $kind, array $gear, string $baseUrl, array $club, callable $sendFn, array $retakes = []): bool {
    try {
        $base = rtrim($baseUrl, '/');
        $id = (int)$gear['id'];
        $pageUrl = $base . '/gear.php?action=pretech&id=' . $id;
        $adminUrl = $base . '/admin.php?action=gear-record&id=' . $id;

        $owner = db_find_user_by_id($pdo, (int)$gear['owner_user_id']);
        $ownerTo = $owner ? [[$owner['email'], $owner['name']]] : [];
        $clubTo = [[$club['email'], $club['name']]];

        $messages = [];   // list of [to, message]
        switch ($kind) {
            case 'submitted':
                $count = count(array_filter(db_get_inspection_photos($pdo, 'gear_record', $id), fn(array $p): bool => $p['file_path'] !== ''));
                $messages[] = [$clubTo, gearEmailSubmitted($gear, $adminUrl, $pageUrl, $count, true)];
                if ($ownerTo) $messages[] = [$ownerTo, gearEmailSubmitted($gear, $adminUrl, $pageUrl, $count, false)];
                break;
            case 'sent_back':
                $list = [];
                foreach ($retakes as $key => $note) {
                    $req = photoRequirementByKey((string)$key);
                    $list[] = ['label' => $req['label'] ?? (string)$key, 'note' => (string)$note];
                }
                if ($ownerTo) $messages[] = [$ownerTo, gearEmailSentBack($gear, $list, $pageUrl)];
                break;
            case 'accepted':
                if ($ownerTo) $messages[] = [$ownerTo, gearEmailAccepted($gear, $pageUrl, $adminUrl, false)];
                $messages[] = [$clubTo, gearEmailAccepted($gear, $pageUrl, $adminUrl, true)];
                break;
            default:
                return false;
        }

        $allSent = true;
        foreach ($messages as [$to, $message]) {
            try {
                if (!$sendFn($to, $message)) $allSent = false;
            } catch (Throwable $e) {
                error_log('Gear notification error: ' . $e->getMessage());
                $allSent = false;
            }
        }
        return $allSent;
    } catch (Throwable $e) {
        error_log('Gear notification error: ' . $e->getMessage());
        return false;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar`
Expected: `OK` for the whole suite, including `GearEmailTest` (7 tests).

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/gear-email.php wcma-calculator/tests/GearEmailTest.php
git commit -m "feat(gear): add gear pre-tech emails and notifier"
```

---

### Task 5: Competitor "My Drivers" pages

**Files:**
- Create: `wcma-calculator/gear-page.php`
- Create: `wcma-calculator/gear.php`
- Modify: `wcma-calculator/js/pretech-form.js` (generalise subject)
- Modify: `wcma-calculator/view_helpers.php` (add the "My Drivers" nav link)
- Modify: `wcma-calculator/css/calculator.css` (append a few styles)
- Test: `wcma-calculator/tests/GearPageTest.php`

**Interfaces:**
- Consumes: Tasks 1-4; `pretechRenderCard()` (`pretech-page.php`); `renderSiteHeader()`, `renderCommonNav()`, `h()`; `feedbackBaseUrl()`; `emailSmtpSend()`; `WcmaPhotoUpload`, `WcmaPretechProgress`.
- Produces:
  - `renderGearListPage(array $records, int $season, string $csrf, ?array $flash): void`
  - `renderGearPretechPage(array $gear, array $snapshot, string $csrf, ?array $flash): void`
  - Routes: `GET gear.php` (My Drivers list), `POST gear.php?action=add` (`csrf_token`, `driver_name`, `licence_no`), `POST gear.php?action=renew` (`csrf_token`, `id`), `GET gear.php?action=pretech&id=N`, `POST gear.php?action=pretech-submit` (`csrf_token`, `id`).
  - Markup contract for the end-to-end script: list table `#gear-table`; add form `#gear-add-form` with inputs `driver_name` and `licence_no`; the pre-tech page reuses the phase 2b contract exactly (`.pretech-card[data-key][data-tier]`, `input[data-photo-input]`, `[data-status]`, `[data-applies-toggle]`, `[data-typed="<name>"]`, `#pretech-progress`, `#pretech-fill`, `#pretech-submit-btn`) and `window.PRETECH_STATE = {subjectType: 'gear_record', subjectId, csrf, locked, requirements, photos, applicable}`.
  - `js/pretech-form.js` uploads/toggles for `state.subjectType || 'tech_sheet'` and `state.subjectId ?? state.sheetId` (the car page's state is unchanged).
  - Nav: signed-in users get a "My Drivers" link (`gear.php`) beside "My Cars".

- [ ] **Step 1: Write the failing page tests**

Create `wcma-calculator/tests/GearPageTest.php`:

```php
<?php
// wcma-calculator/tests/GearPageTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../pretech-lib.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../pretech-page.php';
require_once __DIR__ . '/../gear-page.php';

use PHPUnit\Framework\TestCase;

// The page header calls current_user()/is_admin() (session_bootstrap.php starts a session and is
// not loaded in unit tests). A signed-out stub is enough to render the layout.
if (!function_exists('current_user')) {
    function current_user(): ?array { return null; }
}
if (!function_exists('is_admin')) {
    function is_admin(): bool { return false; }
}

final class GearPageTest extends TestCase
{
    private function gear(array $o = []): array {
        return array_merge([
            'id' => 4, 'owner_user_id' => 1, 'driver_name' => 'Jane <Racer>', 'driver_name_norm' => 'jane <racer>',
            'licence_no' => 'WCMA-1', 'season' => 2026, 'status' => 'open', 'photo_status' => null, 'accepted_via' => null,
        ], $o);
    }

    private function snapshot(array $photos = [], array $applicable = []): array {
        $present = array_keys(array_filter($photos, fn($p) => $p['file_path'] !== ''));
        return ['photos' => $photos, 'present' => $present, 'applicable' => $applicable, 'missing' => photoSetMissingRequired('gear', $present, $applicable)];
    }

    private function photoRow(string $key, array $o = []): array {
        return array_merge([
            'id' => 5, 'requirement_key' => $key, 'file_path' => 'uploads/x.jpg', 'typed_value' => null,
            'review_status' => 'pending', 'reviewer_note' => null, 'applies' => 1,
        ], $o);
    }

    private function renderList(array $records, int $season = 2026, ?array $flash = null): string {
        ob_start();
        renderGearListPage($records, $season, 'csrf-token-1', $flash);
        return (string)ob_get_clean();
    }

    private function renderPretech(array $gear, array $snapshot, ?array $flash = null): string {
        ob_start();
        renderGearPretechPage($gear, $snapshot, 'csrf-token-1', $flash);
        return (string)ob_get_clean();
    }

    public function testListShowsAddFormRecordsAndEscapesNames(): void
    {
        $html = $this->renderList([$this->gear()]);
        $this->assertStringContainsString('id="gear-add-form"', $html);
        $this->assertStringContainsString('name="driver_name"', $html);
        $this->assertStringContainsString('name="licence_no"', $html);
        $this->assertStringContainsString('name="csrf_token" value="csrf-token-1"', $html);
        $this->assertStringContainsString('id="gear-table"', $html);
        $this->assertStringContainsString('Jane &lt;Racer&gt;', $html);
        $this->assertStringNotContainsString('<Racer>', $html);
        $this->assertStringContainsString('WCMA-1', $html);
        $this->assertStringContainsString('Needs gear check at the track', $html);
        $this->assertStringContainsString('gear.php?action=pretech&amp;id=4', $html);
    }

    public function testListShowsStatusChipsPerRecord(): void
    {
        $html = $this->renderList([
            $this->gear(['id' => 1, 'driver_name' => 'A', 'status' => 'accepted', 'accepted_via' => 'photos', 'photo_status' => 'accepted']),
            $this->gear(['id' => 2, 'driver_name' => 'B', 'status' => 'accepted', 'accepted_via' => 'in_person']),
            $this->gear(['id' => 3, 'driver_name' => 'C', 'photo_status' => 'submitted']),
        ]);
        $this->assertStringContainsString('Gear pre-teched 2026', $html);
        $this->assertStringContainsString('Gear teched 2026', $html);
        $this->assertStringContainsString('Photos pending review', $html);
    }

    public function testEmptyListSaysSo(): void
    {
        $this->assertStringContainsString('No drivers yet', $this->renderList([]));
    }

    public function testPreviousSeasonRecordsOfferRenewOnlyWhenNoCurrentRecordExists(): void
    {
        $old = $this->gear(['id' => 9, 'season' => 2025, 'driver_name' => 'Old Timer', 'driver_name_norm' => 'old timer']);
        $current = $this->gear(['id' => 10, 'season' => 2026, 'driver_name' => 'Jane Racer', 'driver_name_norm' => 'jane racer']);
        $oldSame = $this->gear(['id' => 11, 'season' => 2025, 'driver_name' => 'Jane Racer', 'driver_name_norm' => 'jane racer']);

        $html = $this->renderList([$current, $old, $oldSame], 2026);
        $this->assertStringContainsString('Renew for 2026', $html);
        $this->assertSame(1, substr_count($html, 'action="gear.php?action=renew"'));   // Old Timer only: Jane already has a 2026 record
        $this->assertStringContainsString('name="id" value="9"', $html);
        $this->assertStringNotContainsString('name="id" value="11"', $html);
    }

    public function testPretechFormShowsACardForEveryGearRequirement(): void
    {
        $html = $this->renderPretech($this->gear(), $this->snapshot());

        $this->assertSame(count(photoRequirements('gear')), substr_count($html, 'class="pretech-card"'));
        foreach (['helmet_label', 'suit_label', 'fhr_label', 'gear_flatlay', 'helmet_back', 'underwear_label'] as $key) {
            $this->assertStringContainsString('data-key="' . $key . '"', $html);
        }
        $this->assertStringContainsString('data-tier="recommended"', $html);
        $this->assertStringContainsString('data-tier="conditional"', $html);
        $this->assertStringContainsString('0 of 4 required photos', $html);
        $this->assertMatchesRegularExpression('/id="pretech-submit-btn"[^>]*disabled/', $html);
        $this->assertStringContainsString('action="gear.php?action=pretech-submit"', $html);
        $this->assertStringContainsString('name="csrf_token" value="csrf-token-1"', $html);
        $this->assertStringContainsString('"subjectType":"gear_record"', $html);
        $this->assertStringContainsString('"subjectId":4', $html);
        $this->assertStringContainsString('js/pretech-form.js', $html);
        $this->assertStringContainsString('Jane &lt;Racer&gt;', $html);
    }

    public function testCompleteSetEnablesSubmitAndShowsRetakeNotes(): void
    {
        $photos = [];
        foreach (['helmet_label', 'suit_label', 'fhr_label', 'gear_flatlay'] as $i => $key) {
            $photos[$key] = $this->photoRow($key, ['id' => $i + 1]);
        }
        $photos['helmet_label']['review_status'] = 'retake';
        $photos['helmet_label']['reviewer_note'] = 'Date hidden <by glare>';

        $html = $this->renderPretech($this->gear(['photo_status' => 'needs_changes']), $this->snapshot($photos));

        $this->assertStringContainsString('4 of 4 required photos', $html);
        $this->assertDoesNotMatchRegularExpression('/id="pretech-submit-btn"[^>]*disabled/', $html);
        $this->assertStringContainsString('Retake requested', $html);
        $this->assertStringContainsString('Date hidden &lt;by glare&gt;', $html);
        $this->assertStringContainsString('inspection.php?action=photo&amp;id=1', $html);
    }

    public function testLockedRecordIsReadOnly(): void
    {
        $html = $this->renderPretech($this->gear(['photo_status' => 'submitted']), $this->snapshot());
        $this->assertStringContainsString('submitted for review', $html);
        $this->assertStringNotContainsString('data-photo-input', $html);
        $this->assertStringNotContainsString('id="pretech-submit-btn"', $html);
        $this->assertStringContainsString('"locked":true', $html);
    }

    public function testAcceptedRecordShowsOnlyABanner(): void
    {
        $html = $this->renderPretech($this->gear(['status' => 'accepted', 'accepted_via' => 'in_person']), $this->snapshot());
        $this->assertStringContainsString('already teched', $html);
        $this->assertStringNotContainsString('pretech-card', $html);
    }

    public function testApplicableConditionalIsCheckedAndCounted(): void
    {
        $html = $this->renderPretech($this->gear(['photo_status' => 'draft']),
            $this->snapshot(['underwear_label' => $this->photoRow('underwear_label', ['file_path' => ''])], ['underwear_label']));
        $this->assertStringContainsString('0 of 5 required photos', $html);
        $this->assertMatchesRegularExpression('/data-applies-toggle[^>]*checked/', $html);
    }

    public function testCopyAvoidsBannedWording(): void
    {
        foreach ([$this->renderList([$this->gear()]), $this->renderPretech($this->gear(), $this->snapshot())] as $html) {
            $text = strip_tags(preg_replace('/<script.*?<\/script>/s', '', $html));
            $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', $text);
        }
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter GearPageTest`
Expected: fatal error, `gear-page.php` not found.

- [ ] **Step 3: Write the page renderers**

Create `wcma-calculator/gear-page.php`:

```php
<?php
// wcma-calculator/gear-page.php
//
// Markup for the competitor's My Drivers list (gear.php) and a driver's gear pre-tech page
// (gear.php?action=pretech). Pure output; decisions live in gear-lib.php. Callers must have
// loaded photo-requirements.php, inspection-lib.php, pretech-page.php (pretechRenderCard),
// gear-lib.php and view_helpers.php.

function renderGearListPage(array $records, int $season, string $csrf, ?array $flash): void {
    $current = array_values(array_filter($records, fn(array $g): bool => (int)$g['season'] === $season));
    $currentNames = array_map(fn(array $g): string => $g['driver_name_norm'], $current);
    $renewable = [];
    foreach ($records as $g) {
        if ((int)$g['season'] < $season && !in_array($g['driver_name_norm'], $currentNames, true) && !isset($renewable[$g['driver_name_norm']])) {
            $renewable[$g['driver_name_norm']] = $g;   // the most recent earlier record for that driver (list is newest season first)
        }
    }
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Drivers — WCMA Calculator</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('My Drivers', renderCommonNav('gear')); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

  <div class="detail-card">
    <h2>Driver gear for <?= (int)$season ?></h2>
    <p>Each driver's gear is checked once a year. Add a record for yourself, or for each co-driver if you are a team captain, then optionally submit photos of the gear so an inspector can review it before the event. If the photos are accepted, the gear does not need to be checked at the track.</p>
  </div>

  <form method="post" action="gear.php?action=add" id="gear-add-form" class="detail-card" style="margin-bottom:1rem">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <h3>Add a driver</h3>
    <label for="gear-driver-name">Driver name</label>
    <input type="text" id="gear-driver-name" name="driver_name" maxlength="100" required>
    <label for="gear-licence">WCMA licence number (optional)</label>
    <input type="text" id="gear-licence" name="licence_no" maxlength="40">
    <button type="submit" class="btn btn-primary" style="margin-top:.75rem">Add driver</button>
  </form>

  <table class="data-table" id="gear-table">
    <thead><tr><th>Driver</th><th>Licence</th><th>Gear status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (empty($current)): ?>
      <tr><td colspan="4" class="empty-row">No drivers yet for <?= (int)$season ?>. Add one above.</td></tr>
    <?php else: foreach ($current as $g): $st = gearStatus($g); ?>
      <tr>
        <td><?= h($g['driver_name']) ?></td>
        <td><?= h((string)($g['licence_no'] ?? '')) ?></td>
        <td class="<?= h(gearStatusBadgeClass($st['state'])) ?>"><?= h(gearStatusLabel($st, (int)$g['season'])) ?></td>
        <td class="actions"><a href="gear.php?action=pretech&amp;id=<?= (int)$g['id'] ?>"><?= $st['state'] === 'accepted' ? 'View' : 'Gear photos' ?></a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>

  <?php if ($renewable): ?>
  <div class="detail-card" style="margin-top:1.5rem">
    <h3>From earlier seasons</h3>
    <?php foreach ($renewable as $g): ?>
    <form method="post" action="gear.php?action=renew" style="display:inline-block;margin:.25rem .5rem .25rem 0">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
      <?= h($g['driver_name']) ?> (<?= (int)$g['season'] ?>)
      <button type="submit" class="btn btn-secondary">Renew for <?= (int)$season ?></button>
    </form>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<script src="js/form-feedback.js"></script>
</body>
</html><?php
}

function renderGearPretechPage(array $gear, array $snapshot, string $csrf, ?array $flash): void {
    $id = (int)$gear['id'];
    $requirements = photoRequirements('gear');
    $photoStatus = $gear['photo_status'] ?? null;
    $accepted = ($gear['status'] ?? 'open') === 'accepted';
    $locked = $accepted || in_array($photoStatus, ['submitted', 'accepted'], true);
    $missing = count($snapshot['missing']);
    $requiredTotal = gearRequiredTotal($requirements, $snapshot['applicable']);
    $done = $requiredTotal - $missing;

    $clientPhotos = [];
    foreach ($snapshot['photos'] as $key => $row) {
        if ($row['file_path'] !== '') $clientPhotos[$key] = inspectionPublicPhoto($row);
    }
    $clientRequirements = [];
    foreach ($requirements as $key => $req) {
        $clientRequirements[] = ['key' => $key, 'tier' => $req['tier'], 'typed' => array_map(fn(array $f): array => ['name' => $f['name'], 'type' => $f['type']], $req['typed'])];
    }
    $state = [
        'subjectType' => 'gear_record', 'subjectId' => $id, 'csrf' => $csrf, 'locked' => $locked,
        'requirements' => $clientRequirements, 'photos' => (object)$clientPhotos, 'applicable' => $snapshot['applicable'],
    ];
    $driverLine = $gear['driver_name'] . ' — ' . (int)$gear['season'];
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gear pre-tech — WCMA Calculator</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Gear pre-tech', '<a href="gear.php">← Back to My Drivers</a>' . renderCommonNav('gear')); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

  <div class="detail-card">
    <h2><?= h($driverLine) ?></h2>
    <?php if ($accepted): ?>
      <p>This driver's gear is already teched for <?= (int)$gear['season'] ?>. You do not need to submit photos.</p>
    <?php else: ?>
      <p>Optional: submit photos of this driver's gear so an inspector can review them before the event. If they are accepted, the gear does not need to be checked at the track and you just collect your decals. The gear can still be checked in person instead.</p>
      <?php if ($photoStatus === 'submitted'): ?>
        <p class="badge-pending">These photos were submitted for review. You will get an email when an inspector has looked at them.</p>
      <?php elseif ($photoStatus === 'needs_changes'): ?>
        <p class="badge-fail">An inspector asked for some photos to be retaken. Retake the flagged photos below, then submit again.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

<?php if (!$accepted): ?>
  <div class="checklist-progress-wrap">
    <div class="checklist-progress-label" id="pretech-progress"><?= (int)$done ?> of <?= (int)$requiredTotal ?> required photos</div>
    <div class="checklist-progress-bar"><div class="checklist-progress-fill" id="pretech-fill" style="width:<?= $requiredTotal > 0 ? (int)round($done / $requiredTotal * 100) : 0 ?>%"></div></div>
  </div>

  <?php foreach ($requirements as $key => $req): ?>
    <?= pretechRenderCard($key, $req, $snapshot['photos'][$key] ?? null, in_array($key, $snapshot['applicable'], true), $locked) ?>
  <?php endforeach; ?>

  <?php if (!$locked): ?>
  <form method="post" action="gear.php?action=pretech-submit" id="pretech-submit-form" class="detail-card">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <button type="submit" class="btn btn-primary" id="pretech-submit-btn"<?= $missing > 0 ? ' disabled' : '' ?>>Submit for gear pre-tech review</button>
    <p class="form-hint" id="pretech-submit-hint"><?= $missing > 0 ? 'Add every required photo to enable submitting.' : 'Everything required is in. Submit when you are ready.' ?></p>
  </form>
  <?php endif; ?>

  <script>window.PRETECH_STATE = <?= json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
  <script src="js/photo-resize.js"></script>
  <script src="js/photo-upload.js"></script>
  <script src="js/pretech-progress.js"></script>
  <script src="js/pretech-form.js"></script>
<?php endif; ?>
</div>
<script src="js/form-feedback.js"></script>
</body>
</html><?php
}
```

- [ ] **Step 4: Generalise the page script**

In `wcma-calculator/js/pretech-form.js`, directly after the line `    if (!state || state.locked) return;` add:

```js
    const subjectType = state.subjectType || 'tech_sheet';
    const subjectId = state.subjectId !== undefined ? state.subjectId : state.sheetId;
```
then change the upload call's fields

```js
                        file: file, subjectType: 'tech_sheet', subjectId: state.sheetId,
```
to
```js
                        file: file, subjectType: subjectType, subjectId: subjectId,
```
and the toggle call

```js
                    await client.applies({ subjectType: 'tech_sheet', subjectId: state.sheetId, requirementKey: key, applies: toggle.checked });
```
to
```js
                    await client.applies({ subjectType: subjectType, subjectId: subjectId, requirementKey: key, applies: toggle.checked });
```
Confirm with `grep -n "state.sheetId\|'tech_sheet'" js/pretech-form.js`: the only remaining mentions must be the two default lines you just added.

- [ ] **Step 5: Add the nav link and styles**

In `wcma-calculator/view_helpers.php`, in `renderCommonNav`, after the line `        $links[] = navItem('account.php', 'My Cars', $current === 'account');` add:

```php
        $links[] = navItem('gear.php', 'My Drivers', $current === 'gear');
```

Append to the end of `wcma-calculator/css/calculator.css`:

```css

/* ── My Drivers ──────────────────────────────────────────────────────────── */
#gear-add-form label { display: block; margin: 0.5rem 0 0.15rem; font-size: 0.9rem; }
#gear-add-form input[type="text"] { width: 100%; max-width: 420px; }
#gear-table td.actions a { white-space: nowrap; }
```

- [ ] **Step 6: Write the competitor router**

Create `wcma-calculator/gear.php`:

```php
<?php
// wcma-calculator/gear.php — competitor "My Drivers": gear records and gear photo pre-tech.
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/feedback-lib.php';
require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';
require __DIR__ . '/email-helpers.php';
require __DIR__ . '/photo-requirements.php';
require __DIR__ . '/inspection-lib.php';
require __DIR__ . '/pretech-lib.php';
require __DIR__ . '/pretech-email.php';
require __DIR__ . '/pretech-page.php';
require __DIR__ . '/gear-lib.php';
require __DIR__ . '/gear-email.php';
require __DIR__ . '/gear-page.php';

date_default_timezone_set('America/Denver');

$pdo = db_connect();
db_init($pdo);

// Gear submissions go to the same club address as tech sheets.
define('GEAR_CLUB_EMAIL', db_get_setting($pdo, 'tech_sheet_recipient_email', config_default('TECH_SHEET_RECIPIENT_EMAIL', 'classing@wcma.ca')));
define('GEAR_CLUB_NAME', db_get_setting($pdo, 'tech_sheet_recipient_name', config_default('TECH_SHEET_RECIPIENT_NAME', 'WCMA Classing')));

function requireGearLogin(): array {
    $user = current_user();
    if ($user === null) {
        header('Location: auth.php?action=login&redirect=' . rawurlencode('gear.php'));
        exit;
    }
    return $user;
}

/** The signed-in user's own gear record, or a flash + redirect to the list. */
function loadOwnGearRecord(PDO $pdo, array $user, int $id): array {
    $gear = db_get_gear_record($pdo, $id);
    if ($gear === null || (int)$gear['owner_user_id'] !== (int)$user['id']) {
        setFlash('Gear record not found.', 'error');
        header('Location: gear.php');
        exit;
    }
    return $gear;
}

function requireGearPost(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: gear.php'); exit; }
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
}

$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'list':
        $user = requireGearLogin();
        handleGearList($pdo, $user);
        break;

    case 'add':
        $user = requireGearLogin();
        requireGearPost();
        handleGearAdd($pdo, $user);
        break;

    case 'renew':
        $user = requireGearLogin();
        requireGearPost();
        handleGearRenew($pdo, $user, (int)($_POST['id'] ?? 0));
        break;

    case 'pretech':
        $user = requireGearLogin();
        handleGearPretech($pdo, $user, (int)($_GET['id'] ?? 0));
        break;

    case 'pretech-submit':
        $user = requireGearLogin();
        requireGearPost();
        handleGearPretechSubmit($pdo, $user, (int)($_POST['id'] ?? 0));
        break;

    default:
        header('Location: gear.php');
        exit;
}

function handleGearList(PDO $pdo, array $user): void {
    renderGearListPage(db_get_user_gear_records($pdo, (int)$user['id']), gearSeasonNow(), generateCsrfToken(), getFlash());
}

function handleGearAdd(PDO $pdo, array $user): void {
    $r = gearCreate($pdo, (int)$user['id'], (string)($_POST['driver_name'] ?? ''), (string)($_POST['licence_no'] ?? ''), gearSeasonNow());
    setFlash($r['ok'] ? 'Driver added. Open their gear photos to pre-tech their gear, or have it checked at the track.' : $r['error'], $r['ok'] ? 'success' : 'error');
    header('Location: gear.php');
    exit;
}

function handleGearRenew(PDO $pdo, array $user, int $id): void {
    $r = gearRenew($pdo, (int)$user['id'], $id, gearSeasonNow());
    setFlash($r['ok'] ? 'Gear record renewed for ' . gearSeasonNow() . '.' : $r['error'], $r['ok'] ? 'success' : 'error');
    header('Location: gear.php');
    exit;
}

function handleGearPretech(PDO $pdo, array $user, int $id): void {
    $gear = loadOwnGearRecord($pdo, $user, $id);
    renderGearPretechPage($gear, gearSnapshot($pdo, $id), generateCsrfToken(), getFlash());
}

function handleGearPretechSubmit(PDO $pdo, array $user, int $id): void {
    loadOwnGearRecord($pdo, $user, $id);

    $result = gearSubmit($pdo, $id);
    if (!$result['ok']) {
        setFlash($result['error'], 'error');
    } else {
        $sent = gearNotify(
            $pdo, 'submitted', db_get_gear_record($pdo, $id),
            feedbackBaseUrl($_SERVER, (string)config_default('SITE_BASE_URL', '')),
            ['email' => GEAR_CLUB_EMAIL, 'name' => GEAR_CLUB_NAME], 'emailSmtpSend'
        );
        setFlash('Photos submitted for review.' . ($sent ? ' We emailed you a confirmation.' : ' The confirmation email could not be sent.'), $sent ? 'success' : 'error');
    }
    header('Location: gear.php?action=pretech&id=' . $id);
    exit;
}
```

- [ ] **Step 7: Run the tests and lints**

Run: `php -l gear-page.php && php -l gear.php && php -l view_helpers.php && node --check js/pretech-form.js && php phpunit.phar && node --test "tests/js/*.test.js"`
Expected: `No syntax errors detected` three times, no output from `node --check`, PHPUnit `OK` (including `GearPageTest`, 9 tests), Node `# fail 0`. (`gear.php` needs `config.php`; the router, handlers and page script are exercised in Task 7.)

- [ ] **Step 8: Commit**

```bash
git add wcma-calculator/gear-page.php wcma-calculator/gear.php wcma-calculator/js/pretech-form.js wcma-calculator/view_helpers.php wcma-calculator/css/calculator.css wcma-calculator/tests/GearPageTest.php
git commit -m "feat(gear): add My Drivers pages and gear photo pre-tech for competitors"
```

---

### Task 6: Admin gear review and shared admin nav

**Files:**
- Create: `wcma-calculator/admin-gear.php`
- Modify: `wcma-calculator/view_helpers.php` (add `renderAdminNav()`)
- Modify: `wcma-calculator/admin.php`, `wcma-calculator/admin-feedback.php`, `wcma-calculator/admin-tech-sheets.php` (use the shared nav; requires; routes)
- Test: `wcma-calculator/tests/AdminNavTest.php`, `wcma-calculator/tests/AdminGearCopyTest.php`

**Interfaces:**
- Consumes: Tasks 1-4; `requireAuth()`, `validateCsrfToken()`, `generateCsrfToken()`, `getFlash()`, `setFlash()`, `current_user()`, `feedbackBaseUrl()`, `emailSmtpSend()`, `inspectionPublicPhoto()`, `photoRequirementByKey()`; admin.php constants `TECH_EMAIL` / `TECH_NAME`.
- Produces:
  - `renderAdminNav(string $current): string` — the admin sub-nav: Submissions (`admin.php`), Tech Sheets, Gear, Manage Users, Events, Settings, Feedback; the link for `$current` (one of `submissions`, `tech-sheets`, `gear`, `users`, `events`, `settings`, `feedback`) is rendered as inert "you are here" text via `navItem()`. It replaces the hard-coded strings in `admin.php` (4 pages), `admin-feedback.php` (`FEEDBACK_ADMIN_NAV`) and `admin-tech-sheets.php` (`ADMIN_TECH_NAV`), which are removed.
  - Routes (all `requireAuth()`; POST routes also check method and CSRF): `GET admin.php?action=gear[&season=YYYY&filter=…]`, `GET admin.php?action=gear-record&id=N`, `POST admin.php?action=gear-record-accept` (`id`), `POST admin.php?action=gear-record-revoke` (`id`), `POST admin.php?action=gear-photos-accept` (`id`), `POST admin.php?action=gear-photos-send-back` (`id`, `retake[<key>]=1`, `note[<key>]`).
  - `const GEAR_ADMIN_FILTERS = ['all' => 'All drivers', 'needs_gear' => 'Needs gear check at the track', 'pending_review' => 'Photos awaiting review', 'accepted' => 'Accepted']`.
  - Markup contract for the end-to-end script: list table `#gear-admin-table` with a `Review` link per row; review card `#gear-review`; photo-accept button `#gear-accept-btn`; per-photo controls `input[name="retake[<key>]"]` and `input[name="note[<key>]"]`; send-back button `#gear-sendback-btn`; in-person accept button `#gear-inperson-btn`; a "Revoke acceptance" button when accepted.

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/AdminNavTest.php`:

```php
<?php
// wcma-calculator/tests/AdminNavTest.php
require_once __DIR__ . '/../view_helpers.php';

use PHPUnit\Framework\TestCase;

final class AdminNavTest extends TestCase
{
    public function testEveryAdminDestinationIsListedOnce(): void
    {
        $html = renderAdminNav('users');
        foreach (['Submissions', 'Tech Sheets', 'Gear', 'Manage Users', 'Events', 'Settings', 'Feedback'] as $label) {
            $this->assertSame(1, substr_count($html, '>' . $label . '<'), $label);
        }
        foreach (['admin.php"', 'action=tech-sheets"', 'action=gear"', 'action=events"', 'action=settings"', 'action=feedback"'] as $href) {
            $this->assertStringContainsString($href, $html, $href);
        }
    }

    public function testCurrentPageIsInertTextNotALink(): void
    {
        $html = renderAdminNav('gear');
        $this->assertStringContainsString('<span class="nav-current" aria-current="page">Gear</span>', $html);
        $this->assertStringNotContainsString('action=gear"', $html);
        $this->assertStringContainsString('action=tech-sheets"', $html);
    }

    public function testUnknownCurrentLeavesEveryLinkActive(): void
    {
        $this->assertStringNotContainsString('nav-current', renderAdminNav('nope'));
    }
}
```

Create `wcma-calculator/tests/AdminGearCopyTest.php`:

```php
<?php
// wcma-calculator/tests/AdminGearCopyTest.php
//
// Source-level guards for admin-gear.php (its pages need config.php, so they cannot run under
// PHPUnit): the terminology rule and the routing/authorisation shape.
use PHPUnit\Framework\TestCase;

final class AdminGearCopyTest extends TestCase
{
    private function src(string $file): string {
        return str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../' . $file));
    }

    public function testGearAdminHasNoBannedWording(): void
    {
        $source = $this->src('admin-gear.php');
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed)\b/i', $source);
        $this->assertDoesNotMatchRegularExpression('/\bsafe\b/i', $source);
    }

    public function testEveryGearRouteIsAdminOnlyAndPostRoutesCheckCsrf(): void
    {
        $admin = $this->src('admin.php');
        foreach (['gear', 'gear-record', 'gear-record-accept', 'gear-record-revoke', 'gear-photos-accept', 'gear-photos-send-back'] as $route) {
            $this->assertMatchesRegularExpression("/case '" . preg_quote($route, '/') . "':\\s+requireAuth\\(\\);/", $admin, $route);
        }
        foreach (['gear-record-accept', 'gear-record-revoke', 'gear-photos-accept', 'gear-photos-send-back'] as $route) {
            $this->assertMatchesRegularExpression("/case '" . preg_quote($route, '/') . "':.*?validateCsrfToken/s", $admin, $route);
        }
    }

    public function testNoHardCodedAdminNavStringsRemain(): void
    {
        foreach (['admin.php', 'admin-feedback.php', 'admin-tech-sheets.php', 'admin-gear.php'] as $file) {
            $src = $this->src($file);
            $this->assertStringNotContainsString('<a href="admin.php?action=users">Manage Users</a>', $src, $file);
            $this->assertStringNotContainsString('FEEDBACK_ADMIN_NAV', $src, $file);
            $this->assertStringNotContainsString('ADMIN_TECH_NAV', $src, $file);
        }
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter "AdminNavTest|AdminGearCopyTest"`
Expected: `Call to undefined function renderAdminNav()` and file-not-found for `admin-gear.php`.

- [ ] **Step 3: Add the shared admin nav**

In `wcma-calculator/view_helpers.php`, add this function directly before the `renderCommonNav` docblock:

```php
/**
 * The admin sub-navigation shared by every admin page. $current is one of 'submissions',
 * 'tech-sheets', 'gear', 'users', 'events', 'settings', 'feedback'; that destination renders as
 * inert "you are here" text (see navItem()), the others as links.
 */
function renderAdminNav(string $current): string {
    $items = [
        'submissions' => ['admin.php', 'Submissions'],
        'tech-sheets' => ['admin.php?action=tech-sheets', 'Tech Sheets'],
        'gear'        => ['admin.php?action=gear', 'Gear'],
        'users'       => ['admin.php?action=users', 'Manage Users'],
        'events'      => ['admin.php?action=events', 'Events'],
        'settings'    => ['admin.php?action=settings', 'Settings'],
        'feedback'    => ['admin.php?action=feedback', 'Feedback'],
    ];
    $links = [];
    foreach ($items as $key => [$href, $label]) {
        $links[] = navItem($href, $label, $key === $current);
    }
    return implode(' ', $links);
}

```

- [ ] **Step 4: Replace the hard-coded nav strings**

In `wcma-calculator/admin.php`, replace the first argument of four `renderSiteHeader` calls (each of the old strings below is unique):

- `'<a href="admin.php?action=tech-sheets">Tech Sheets</a> <a href="admin.php?action=users">Manage Users</a> <a href="admin.php?action=events">Events</a> <a href="admin.php?action=settings">Settings</a> <a href="admin.php?action=feedback">Feedback</a>' . renderCommonNav('admin')` becomes `renderAdminNav('submissions') . renderCommonNav('admin')`
- `'<a href="admin.php">Submissions</a> <a href="admin.php?action=tech-sheets">Tech Sheets</a> <a href="admin.php?action=events">Events</a> <a href="admin.php?action=settings">Settings</a> <a href="admin.php?action=feedback">Feedback</a>' . renderCommonNav('admin')` becomes `renderAdminNav('users') . renderCommonNav('admin')`
- `'<a href="admin.php">Submissions</a> <a href="admin.php?action=tech-sheets">Tech Sheets</a> <a href="admin.php?action=users">Manage Users</a> <a href="admin.php?action=settings">Settings</a> <a href="admin.php?action=feedback">Feedback</a>' . renderCommonNav('admin')` becomes `renderAdminNav('events') . renderCommonNav('admin')`
- `'<a href="admin.php">Submissions</a> <a href="admin.php?action=tech-sheets">Tech Sheets</a> <a href="admin.php?action=users">Manage Users</a> <a href="admin.php?action=events">Events</a> <a href="admin.php?action=feedback">Feedback</a>' . renderCommonNav('admin')` becomes `renderAdminNav('settings') . renderCommonNav('admin')`

In `wcma-calculator/admin-feedback.php`: delete the whole `const FEEDBACK_ADMIN_NAV = ...;` line and change `renderSiteHeader('Feedback', FEEDBACK_ADMIN_NAV . renderCommonNav('admin'))` to `renderSiteHeader('Feedback', renderAdminNav('feedback') . renderCommonNav('admin'))`.

In `wcma-calculator/admin-tech-sheets.php`: delete the whole `const ADMIN_TECH_NAV = ...;` line and change `renderSiteHeader('Tech Sheets', ADMIN_TECH_NAV . renderCommonNav('admin'))` to `renderSiteHeader('Tech Sheets', renderAdminNav('tech-sheets') . renderCommonNav('admin'))`.

Run `grep -rn "ADMIN_NAV\|ADMIN_TECH_NAV" wcma-calculator --include=*.php`; the only matches allowed are in `tests/`.

- [ ] **Step 5: Write the admin gear module**

Create `wcma-calculator/admin-gear.php`:

```php
<?php
// wcma-calculator/admin-gear.php
//
// Admin "Gear" tab: driver gear records for a season, and the review page (accept in person,
// accept photos remotely, send photos back). Included by admin.php, which provides requireAuth(),
// the router and the CSRF/POST checks.

const GEAR_ADMIN_FILTERS = [
    'all' => 'All drivers',
    'needs_gear' => 'Needs gear check at the track',
    'pending_review' => 'Photos awaiting review',
    'accepted' => 'Accepted',
];

function handleGearAdminList(PDO $pdo): void {
    $season = isset($_GET['season']) ? (int)$_GET['season'] : gearSeasonNow();
    if ($season < 2000 || $season > 2100) $season = gearSeasonNow();
    $filter = (string)($_GET['filter'] ?? 'all');
    if (!isset(GEAR_ADMIN_FILTERS[$filter])) $filter = 'all';

    $records = db_get_gear_records_for_season($pdo, $season);
    $counts = [
        'all' => count($records),
        'accepted' => count(gearRosterFilter($records, 'accepted')),
        'needs_gear' => count(gearRosterFilter($records, 'needs_gear')),
        'pending_review' => count(gearRosterFilter($records, 'pending_review')),
    ];
    renderGearAdminListPage(gearRosterFilter($records, $filter), $season, $filter, $counts, getFlash());
}

function renderGearAdminListPage(array $records, int $season, string $filter, array $counts, ?array $flash): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gear — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Gear', renderAdminNav('gear') . renderCommonNav('admin')); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

  <form method="get" action="admin.php" class="detail-card" style="margin-bottom:1rem">
    <input type="hidden" name="action" value="gear">
    <label for="gear-season">Season</label>
    <input type="number" id="gear-season" name="season" value="<?= (int)$season ?>" min="2000" max="2100">
    <label for="gear-filter">Show</label>
    <select id="gear-filter" name="filter">
      <?php foreach (GEAR_ADMIN_FILTERS as $value => $label): ?>
      <option value="<?= h($value) ?>"<?= $value === $filter ? ' selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">Apply</button>
    <p class="form-hint" style="margin-top:.5rem"><?= (int)$counts['all'] ?> drivers: <?= (int)$counts['accepted'] ?> accepted, <?= (int)$counts['pending_review'] ?> with photos awaiting review, <?= (int)$counts['needs_gear'] ?> still need a gear check at the track.</p>
  </form>

  <table class="data-table" id="gear-admin-table">
    <thead><tr><th>Driver</th><th>Licence</th><th>Entered by</th><th>Gear status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (empty($records)): ?>
      <tr><td colspan="5" class="empty-row">No gear records match.</td></tr>
    <?php else: foreach ($records as $g): $st = gearStatus($g); ?>
      <tr>
        <td><?= h($g['driver_name']) ?></td>
        <td><?= h((string)($g['licence_no'] ?? '')) ?></td>
        <td><?= h((string)($g['owner_name'] ?? '')) ?></td>
        <td class="<?= h(gearStatusBadgeClass($st['state'])) ?>"><?= h(gearStatusLabel($st, (int)$g['season'])) ?></td>
        <td class="actions"><a href="admin.php?action=gear-record&amp;id=<?= (int)$g['id'] ?>"><?= $st['state'] === 'accepted' ? 'View' : 'Review' ?></a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
</body>
</html><?php
}

function handleGearAdminView(PDO $pdo, int $id): void {
    $gear = db_get_gear_record($pdo, $id);
    if ($gear === null) {
        setFlash('Gear record not found.', 'error');
        header('Location: admin.php?action=gear');
        exit;
    }
    $owner = db_find_user_by_id($pdo, (int)$gear['owner_user_id']);
    $reviewer = !empty($gear['reviewed_by_user_id']) ? db_find_user_by_id($pdo, (int)$gear['reviewed_by_user_id']) : null;
    renderGearAdminViewPage($gear, gearSnapshot($pdo, $id), $owner, $reviewer, generateCsrfToken(), getFlash());
}

function gearAdminBaseUrl(): string {
    return feedbackBaseUrl($_SERVER, (string)config_default('SITE_BASE_URL', ''));
}

function handleGearAdminAcceptInPerson(PDO $pdo, int $id): void {
    $user = current_user();
    $r = gearAcceptInPerson($pdo, $id, (int)$user['id']);
    setFlash($r['ok'] ? 'Gear accepted (teched in person).' : $r['error'], $r['ok'] ? 'success' : 'error');
    header('Location: admin.php?action=gear-record&id=' . $id);
    exit;
}

function handleGearAdminRevoke(PDO $pdo, int $id): void {
    $r = gearRevoke($pdo, $id);
    setFlash($r['ok'] ? 'Acceptance revoked. The gear record is open again.' : $r['error'], $r['ok'] ? 'success' : 'error');
    header('Location: admin.php?action=gear-record&id=' . $id);
    exit;
}

function handleGearAdminPhotosAccept(PDO $pdo, int $id): void {
    $user = current_user();
    $r = gearAcceptByPhotos($pdo, $id, (int)$user['id']);
    if (!$r['ok']) {
        setFlash($r['error'], 'error');
    } else {
        $sent = gearNotify($pdo, 'accepted', db_get_gear_record($pdo, $id), gearAdminBaseUrl(), ['email' => TECH_EMAIL, 'name' => TECH_NAME], 'emailSmtpSend');
        setFlash('Photos accepted: the gear is pre-teched.' . ($sent ? ' The driver\'s account holder and the club were emailed.' : ' The notification email could not be sent.'), $sent ? 'success' : 'error');
    }
    header('Location: admin.php?action=gear-record&id=' . $id);
    exit;
}

function handleGearAdminPhotosSendBack(PDO $pdo, int $id): void {
    $flagged = isset($_POST['retake']) && is_array($_POST['retake']) ? array_keys($_POST['retake']) : [];
    $noteInput = isset($_POST['note']) && is_array($_POST['note']) ? $_POST['note'] : [];
    $notes = [];
    foreach ($flagged as $key) {
        $notes[(string)$key] = is_string($noteInput[$key] ?? null) ? $noteInput[$key] : '';
    }

    $r = gearSendBack($pdo, $id, $notes);
    if (!$r['ok']) {
        setFlash($r['error'], 'error');
    } else {
        $sent = gearNotify($pdo, 'sent_back', db_get_gear_record($pdo, $id), gearAdminBaseUrl(), ['email' => TECH_EMAIL, 'name' => TECH_NAME], 'emailSmtpSend', $r['retakes']);
        $n = count($r['retakes']);
        setFlash($n . ' ' . ($n === 1 ? 'photo' : 'photos') . ' sent back for a retake.' . ($sent ? ' The account holder was emailed.' : ' The notification email could not be sent.'), $sent ? 'success' : 'error');
    }
    header('Location: admin.php?action=gear-record&id=' . $id);
    exit;
}

function renderGearAdminViewPage(array $gear, array $snapshot, ?array $owner, ?array $reviewer, string $csrf, ?array $flash): void {
    $id = (int)$gear['id'];
    $st = gearStatus($gear);
    $accepted = $gear['status'] === 'accepted';
    $acceptedLine = '';
    if ($accepted) {
        $how = ($gear['accepted_via'] ?? 'in_person') === 'photos' ? 'remotely' : 'in person';
        $who = $reviewer ? ' by ' . $reviewer['name'] : '';
        $when = !empty($gear['reviewed_at']) ? ' on ' . date('M j, Y g:i A', strtotime($gear['reviewed_at'])) : '';
        $acceptedLine = 'Accepted ' . $how . $who . $when . '.';
    }
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gear #<?= $id ?> — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Gear #' . $id, '<a href="admin.php?action=gear&amp;season=' . (int)$gear['season'] . '">← Back to gear list</a>' . renderCommonNav('admin')); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

  <div class="detail-card">
    <h2>Gear review</h2>
    <p><?= h($gear['driver_name']) ?> — <?= (int)$gear['season'] ?><?= !empty($gear['licence_no']) ? ' (licence ' . h($gear['licence_no']) . ')' : '' ?></p>
    <?php if ($owner): ?><p>Entered by <?= h($owner['name']) ?> (<?= h($owner['email']) ?>)</p><?php endif; ?>
    <p>Gear status: <strong class="<?= h(gearStatusBadgeClass($st['state'])) ?>"><?= h(gearStatusLabel($st, (int)$gear['season'])) ?></strong></p>

    <?php if ($accepted): ?>
    <p><?= h($acceptedLine) ?></p>
    <form method="post" action="admin.php?action=gear-record-revoke" data-confirm="Revoke this acceptance? The gear record goes back to open<?= ($gear['accepted_via'] ?? '') === 'photos' ? ' and its photos return to the review queue' : '' ?>.">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button type="submit" class="btn btn-secondary">Revoke acceptance</button>
    </form>
    <?php else: ?>
    <p class="form-hint">Accepting in person records that the gear you are looking at matches what the driver declared. To review photos instead, use the photo review below.</p>
    <form method="post" action="admin.php?action=gear-record-accept">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button type="submit" class="btn btn-primary" id="gear-inperson-btn">Accept — gear teched in person</button>
    </form>
    <?php endif; ?>
  </div>

  <?php renderGearReviewCard($gear, $snapshot, $csrf); ?>
</div>
<script src="js/confirm-modal.js"></script>
<script src="js/form-feedback.js"></script>
</body>
</html><?php
}

/** The gear photos, with accept / send-back controls while they are awaiting review. */
function renderGearReviewCard(array $gear, array $snapshot, string $csrf): void {
    $photos = array_filter($snapshot['photos'], fn(array $p): bool => $p['file_path'] !== '');
    $photoStatus = $gear['photo_status'] ?? null;
    if ($photoStatus === null && !$photos) return;

    $id = (int)$gear['id'];
    $awaiting = $photoStatus === 'submitted' && $gear['status'] === 'open';
    $statusLabels = [
        'draft' => 'The driver\'s account holder has started adding photos (not submitted yet).',
        'submitted' => 'Submitted: awaiting review.',
        'needs_changes' => 'Sent back: waiting for photos to be retaken.',
        'accepted' => 'Photos reviewed and accepted.',
    ];
    ?>
  <div class="detail-card" id="gear-review">
    <h2>Gear photos</h2>
    <p><?= h($statusLabels[$photoStatus] ?? 'No photo set yet.') ?> <?= count($photos) ?> <?= count($photos) === 1 ? 'photo' : 'photos' ?> on file.</p>
    <?php if ($awaiting): ?>
    <p class="form-hint">Accepting these photos makes the gear pre-teched for the season. To send photos back, tick each one, say what is wrong, and use "Send back for retakes".</p>
    <?php endif; ?>

    <form method="post" action="admin.php?action=gear-photos-send-back" id="gear-review-form">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <?php foreach ($photos as $key => $row):
          $req = photoRequirementByKey($key);
          $public = inspectionPublicPhoto($row);
      ?>
      <div class="pretech-card" data-key="<?= h($key) ?>">
        <h3><?= h($req['label'] ?? $key) ?>
          <span class="pretech-status <?= $row['review_status'] === 'retake' ? 'badge-fail' : ($row['review_status'] === 'accepted' ? 'badge-ok' : 'badge-pending') ?>">
            <?= h($row['review_status'] === 'retake' ? 'Retake requested' : ($row['review_status'] === 'accepted' ? 'Accepted' : 'Pending')) ?></span></h3>
        <a href="<?= h($public['url']) ?>" target="_blank" rel="noopener"><img class="pretech-thumb" src="<?= h($public['url']) ?>" alt="<?= h($req['label'] ?? $key) ?>"></a>
        <?php foreach ($public['typed'] as $name => $value): ?>
          <p class="form-hint"><?= h(ucfirst((string)$name)) ?>: <strong><?= h((string)$value) ?></strong></p>
        <?php endforeach; ?>
        <?php if ($row['review_status'] === 'retake' && !empty($row['reviewer_note'])): ?>
          <p class="badge-fail">Note sent: <?= h((string)$row['reviewer_note']) ?></p>
        <?php endif; ?>
        <?php if ($awaiting): ?>
          <label><input type="checkbox" name="retake[<?= h($key) ?>]" value="1"> Needs a retake</label>
          <input type="text" name="note[<?= h($key) ?>]" maxlength="500" placeholder="What is wrong with this photo?">
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <?php if ($awaiting): ?>
      <button type="submit" class="btn btn-secondary" id="gear-sendback-btn">Send back for retakes</button>
      <?php endif; ?>
    </form>

    <?php if ($awaiting): ?>
    <form method="post" action="admin.php?action=gear-photos-accept" style="margin-top:.75rem">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button type="submit" class="btn btn-primary" id="gear-accept-btn">Accept photos (pre-teched)</button>
    </form>
    <?php endif; ?>
  </div>
<?php
}
```

- [ ] **Step 6: Add requires and routes in `admin.php`**

After the existing `require __DIR__ . '/pretech-email.php';` line add:

```php
require __DIR__ . '/gear-lib.php';
require __DIR__ . '/gear-email.php';
require __DIR__ . '/admin-gear.php';
```
and add these router cases right after the existing `case 'tech-sheet-photos-send-back':` block:

```php
    case 'gear':
        requireAuth();
        handleGearAdminList($pdo);
        break;

    case 'gear-record':
        requireAuth();
        handleGearAdminView($pdo, (int)($_GET['id'] ?? 0));
        break;

    case 'gear-record-accept':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=gear'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleGearAdminAcceptInPerson($pdo, (int)($_POST['id'] ?? 0));
        break;

    case 'gear-record-revoke':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=gear'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleGearAdminRevoke($pdo, (int)($_POST['id'] ?? 0));
        break;

    case 'gear-photos-accept':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=gear'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleGearAdminPhotosAccept($pdo, (int)($_POST['id'] ?? 0));
        break;

    case 'gear-photos-send-back':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=gear'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleGearAdminPhotosSendBack($pdo, (int)($_POST['id'] ?? 0));
        break;

```

- [ ] **Step 7: Run the tests and lints**

Run: `php -l admin-gear.php && php -l admin.php && php -l admin-feedback.php && php -l admin-tech-sheets.php && php -l view_helpers.php && php phpunit.phar`
Expected: five `No syntax errors detected` lines, then `OK` for the whole suite including `AdminNavTest` (3 tests) and `AdminGearCopyTest` (3 tests). (The admin pages need `config.php`; they are exercised in Task 7.)

- [ ] **Step 8: Commit**

```bash
git add wcma-calculator/admin-gear.php wcma-calculator/view_helpers.php wcma-calculator/admin.php wcma-calculator/admin-feedback.php wcma-calculator/admin-tech-sheets.php wcma-calculator/tests/AdminNavTest.php wcma-calculator/tests/AdminGearCopyTest.php
git commit -m "feat(gear): add admin gear list and review, with a shared admin nav"
```

---

### Task 7: End-to-end verification

No product code changes; no commits. This proves the whole gear loop in a real browser: a captain adds two drivers, one driver's photos go through send-back, retake and remote acceptance, the other is accepted in person and then revoked; statuses, locks, isolation between users and the emails (captured in a dry-run log) are checked.

**Database and email safety (critical, learned in earlier phases):** the harness must use ONLY the scratch DB `scratch/tech3a-e2e.db`, never `wcma-calculator/data/submissions.db` (real local data: users=0, submissions=1, tech_sheets=0, events=0 at the start of this phase). Every entry point loads `scratch/tech3a-prepend.php` first; start the server with the ABSOLUTE prepend path; the harness `require_once`s the prepend itself (php -S skips `auto_prepend_file` for router-served requests). The prepend also defines `WCMA_MAIL_LOG`, so NO email is ever sent over SMTP (they are appended to `scratch/tech3a-mail.log`; club copies go to the default `classing@wcma.ca` address only in that log). Verify the default DB counts before and after.

**Files:**
- Create (scratch, untracked): `scratch/tech3a-prepend.php`, `scratch/tech3a-router.php`, `scratch/tech3a-harness.php`, `scratch/tech3a-e2e.js`

- [ ] **Step 1: Record the default database state**

Run (repo root): `php -r '$p=new PDO("sqlite:wcma-calculator/data/submissions.db"); foreach(["users","submissions","tech_sheets","events"] as $t) echo $t,"=",$p->query("SELECT COUNT(*) FROM $t")->fetchColumn(),"\n";'`
Note the four counts for Step 6. (`gear_records` will exist in the default DB once the app has run `db_init()`; it must have no rows.)

- [ ] **Step 2: Create the harness files**

Create `scratch/tech3a-prepend.php`:

```php
<?php
if (!defined('DB_PATH')) {
    define('DB_PATH', 'C:/dev/wcmaclasscalc/scratch/tech3a-e2e.db');
}
if (!defined('WCMA_MAIL_LOG')) {
    define('WCMA_MAIL_LOG', 'C:/dev/wcmaclasscalc/scratch/tech3a-mail.log');
}
```

Create `scratch/tech3a-router.php`:

```php
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/harness') {
    require __DIR__ . '/tech3a-harness.php';
    return true;
}
return false;
```

Create `scratch/tech3a-harness.php`:

```php
<?php
// Seeds three users in the scratch DB and signs the browser in. ?as=admin | owner | other
require_once __DIR__ . '/tech3a-prepend.php';
require 'C:/dev/wcmaclasscalc/wcma-calculator/session_bootstrap.php';
require 'C:/dev/wcmaclasscalc/wcma-calculator/db.php';
require 'C:/dev/wcmaclasscalc/wcma-calculator/view_helpers.php';

$pdo = db_connect();
db_init($pdo);

function harnessUser(PDO $pdo, string $who, string $role): int {
    $u = db_find_user_by_email($pdo, "$who@example.com");
    if ($u) return (int)$u['id'];
    $id = db_create_user($pdo, ['email' => "$who@example.com", 'name' => ucfirst($who), 'password_hash' => 'x', 'google_id' => null]);
    $pdo->prepare('UPDATE users SET role = :r WHERE id = :id')->execute([':r' => $role, ':id' => $id]);
    return $id;
}

$adminId = harnessUser($pdo, 'admin', 'admin');
$ownerId = harnessUser($pdo, 'owner', 'user');
$otherId = harnessUser($pdo, 'other', 'user');

$who = $_GET['as'] ?? 'admin';
$_SESSION['user_id'] = ['admin' => $adminId, 'owner' => $ownerId, 'other' => $otherId][$who] ?? $adminId;
$_SESSION['user_name'] = ucfirst($who);
$_SESSION['user_role'] = $who === 'admin' ? 'admin' : 'user';
generateCsrfToken();
?>
<!doctype html><meta charset="utf-8"><title>tech3a harness</title><p>harness ready: <?= h($who) ?></p>
```

Create `scratch/tech3a-e2e.js`:

```js
const { chromium } = require('C:/dev/wcmaclasscalc/scratch/tech-sheet-mockups/node_modules/playwright');
const assert = require('node:assert');
const fs = require('node:fs');

const BASE = 'http://localhost:8126';
const MAIL_LOG = 'C:/dev/wcmaclasscalc/scratch/tech3a-mail.log';
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');
const REQUIRED = ['helmet_label', 'suit_label', 'fhr_label', 'gear_flatlay'];

function mailLog() {
    if (!fs.existsSync(MAIL_LOG)) return [];
    return fs.readFileSync(MAIL_LOG, 'utf8').split('\n').filter(Boolean).map(l => JSON.parse(l));
}

async function signIn(browser, who) {
    const ctx = await browser.newContext({ viewport: { width: 420, height: 900 } });
    const page = await ctx.newPage();
    await page.goto(BASE + '/harness?as=' + who);
    await page.waitForSelector('text=harness ready');
    return page;
}

async function uploadPhoto(page, key) {
    const card = page.locator('.pretech-card[data-key="' + key + '"]');
    await card.locator('input[data-photo-input]').setInputFiles({ name: key + '.png', mimeType: 'image/png', buffer: PNG });
    await page.waitForFunction(k => {
        const s = document.querySelector('.pretech-card[data-key="' + k + '"] [data-status]');
        return s && /^(Added|Accepted)$/.test(s.textContent.trim());
    }, key, { timeout: 15000 });
}

async function rowFor(page, name) {
    return page.locator('#gear-table tbody tr', { hasText: name });
}

(async () => {
    if (fs.existsSync(MAIL_LOG)) fs.unlinkSync(MAIL_LOG);
    const browser = await chromium.launch();
    const owner = await signIn(browser, 'owner');
    const admin = await signIn(browser, 'admin');
    const other = await signIn(browser, 'other');

    // ── Owner: My Drivers, add two drivers ────────────────────────────────────
    await owner.goto(BASE + '/gear.php');
    assert.match(await owner.textContent('body'), /No drivers yet/);
    assert.match(await owner.textContent('nav, header'), /My Drivers/, 'nav has My Drivers');
    await owner.fill('#gear-add-form input[name="driver_name"]', 'Jane Racer');
    await owner.fill('#gear-add-form input[name="licence_no"]', 'WCMA-101');
    await Promise.all([owner.waitForNavigation(), owner.click('#gear-add-form button[type="submit"]')]);
    await owner.fill('#gear-add-form input[name="driver_name"]', 'Sam Coach');
    await Promise.all([owner.waitForNavigation(), owner.click('#gear-add-form button[type="submit"]')]);
    assert.strictEqual(await owner.locator('#gear-table tbody tr').count(), 2);
    assert.match(await (await rowFor(owner, 'Jane Racer')).textContent(), /Needs gear check at the track/);

    // Duplicate name (case/space-insensitive) is refused with a message
    await owner.fill('#gear-add-form input[name="driver_name"]', '  jane   RACER ');
    await Promise.all([owner.waitForNavigation(), owner.click('#gear-add-form button[type="submit"]')]);
    assert.match(await owner.textContent('body'), /already have a gear record/i);
    assert.strictEqual(await owner.locator('#gear-table tbody tr').count(), 2);
    console.log('My Drivers: 2 drivers added, duplicate refused');

    // ── Owner: Jane's gear pre-tech page ──────────────────────────────────────
    await owner.click('#gear-table tbody tr:has-text("Jane Racer") a');
    await owner.waitForSelector('.pretech-card');
    assert.strictEqual(await owner.locator('.pretech-card').count(), 6, 'six gear cards');
    assert.strictEqual(await owner.locator('.pretech-card[data-tier="required"]').count(), 4);
    assert.match(await owner.textContent('#pretech-progress'), /0 of 4 required photos/);
    assert.ok(await owner.locator('#pretech-submit-btn').isDisabled());
    const janeUrl = owner.url();
    for (const key of REQUIRED) await uploadPhoto(owner, key);
    assert.match(await owner.textContent('#pretech-progress'), /4 of 4 required photos/);
    assert.ok(await owner.locator('#pretech-submit-btn').isEnabled());

    // Typed details, the optional recommended photo, and the conditional toggle
    await owner.locator('.pretech-card[data-key="helmet_label"] [data-typed="date"]').fill('03/2024');
    await owner.locator('.pretech-card[data-key="helmet_label"] [data-typed="date"]').blur();
    await owner.waitForTimeout(600);
    await uploadPhoto(owner, 'helmet_back');
    assert.match(await owner.textContent('#pretech-progress'), /4 of 4 required photos/, 'recommended photo never counts');
    await owner.check('.pretech-card[data-key="underwear_label"] [data-applies-toggle]');
    await owner.waitForFunction(() => /4 of 5/.test(document.getElementById('pretech-progress').textContent));
    assert.ok(await owner.locator('#pretech-submit-btn').isDisabled(), 'applicable conditional blocks submit until added');
    await owner.uncheck('.pretech-card[data-key="underwear_label"] [data-applies-toggle]');
    await owner.waitForFunction(() => /4 of 4/.test(document.getElementById('pretech-progress').textContent));
    await owner.reload();
    assert.strictEqual(await owner.locator('.pretech-card[data-key="helmet_label"] [data-typed="date"]').inputValue(), '03/2024');
    console.log('gear photos: 4 required uploaded, typed detail saved, recommended never counts, conditional toggles');

    // ── Submit: read-only, two emails ─────────────────────────────────────────
    await Promise.all([owner.waitForNavigation(), owner.click('#pretech-submit-btn')]);
    assert.match(await owner.textContent('body'), /submitted for review/i);
    assert.strictEqual(await owner.locator('input[data-photo-input]').count(), 0);
    let mails = mailLog();
    assert.strictEqual(mails.length, 2, 'submitted: club + owner');
    assert.ok(mails.every(m => /Gear Pre-Tech|gear pre-tech/i.test(m.subject)));
    assert.ok(mails.some(m => m.to[0][0] === 'owner@example.com') && mails.some(m => m.to[0][0] === 'classing@wcma.ca'));
    const locked = await owner.evaluate(async () => {
        const f = new FormData();
        f.append('csrf_token', window.PRETECH_STATE.csrf); f.append('subject_type', 'gear_record');
        f.append('subject_id', String(window.PRETECH_STATE.subjectId)); f.append('requirement_key', 'underwear_label'); f.append('applies', '1');
        return (await fetch('inspection.php?action=applies', { method: 'POST', body: f })).status;
    });
    assert.strictEqual(locked, 404, 'owner writes refused while under review');
    console.log('submitted: read-only, 2 emails logged, writes locked');

    // ── Isolation: another user cannot see or touch Jane's record ─────────────
    await other.goto(janeUrl);
    assert.match(await other.textContent('body'), /Gear record not found/i);
    const photoUrl = await owner.locator('.pretech-card[data-key="helmet_label"] img[data-thumb]').getAttribute('src');
    const otherPhoto = await other.evaluate(async (u) => (await fetch(u, { credentials: 'same-origin' })).status, BASE + '/' + photoUrl.split('&v=')[0]);
    assert.strictEqual(otherPhoto, 404, 'other user cannot fetch the photo');
    console.log('isolation: other user gets not-found for the page and the photo');

    // ── Inspector: list, review, send back ────────────────────────────────────
    await admin.goto(BASE + '/admin.php?action=gear&filter=pending_review');
    assert.match(await admin.textContent('nav, header'), /Gear/);
    assert.strictEqual(await admin.locator('#gear-admin-table tbody tr').count(), 1, 'one record awaiting review');
    await admin.click('#gear-admin-table tbody tr a');
    assert.strictEqual(await admin.locator('#gear-review .pretech-card').count(), 5, 'four required + helmet back photo');
    assert.match(await admin.textContent('#gear-review'), /03\/2024/, 'typed detail visible');
    await Promise.all([admin.waitForNavigation(), admin.click('#gear-sendback-btn')]);
    assert.match(await admin.textContent('body'), /at least one photo/i);
    await admin.check('input[name="retake[helmet_label]"]');
    await Promise.all([admin.waitForNavigation(), admin.click('#gear-sendback-btn')]);
    assert.match(await admin.textContent('body'), /note for every photo/i);
    await admin.check('input[name="retake[helmet_label]"]');
    await admin.fill('input[name="note[helmet_label]"]', 'Date on the label is not readable');
    await Promise.all([admin.waitForNavigation(), admin.click('#gear-sendback-btn')]);
    assert.match(await admin.textContent('body'), /1 photo sent back/i);
    mails = mailLog();
    assert.strictEqual(mails.length, 3);
    assert.strictEqual(mails[2].to[0][0], 'owner@example.com');
    assert.match(mails[2].text, /Date on the label is not readable/);
    assert.match(mails[2].text, /Helmet certification label/);
    console.log('inspector sent 1 photo back with a note; owner emailed');

    // ── Owner retakes and resubmits ───────────────────────────────────────────
    await owner.goto(janeUrl);
    assert.match(await owner.textContent('body'), /Date on the label is not readable/);
    await Promise.all([owner.waitForNavigation(), owner.click('#pretech-submit-btn')]).catch(() => {});
    assert.match(await owner.textContent('body'), /flagged/i, 'resubmit blocked while flagged');
    await owner.goto(janeUrl);
    await uploadPhoto(owner, 'helmet_label');
    await Promise.all([owner.waitForNavigation(), owner.click('#pretech-submit-btn')]);
    assert.match(await owner.textContent('body'), /submitted for review/i);
    assert.strictEqual(mailLog().length, 5);

    // ── Inspector accepts the photos ──────────────────────────────────────────
    await admin.goto(BASE + '/admin.php?action=gear&filter=pending_review');
    await admin.click('#gear-admin-table tbody tr a');
    await Promise.all([admin.waitForNavigation(), admin.click('#gear-accept-btn')]);
    let body = await admin.textContent('body');
    assert.match(body, /Photos accepted/);
    assert.match(body, /Gear pre-teched \d{4}/);
    assert.match(body, /Accepted remotely/);
    mails = mailLog();
    assert.strictEqual(mails.length, 7);
    const acc = mails.slice(5);
    assert.ok(acc.every(m => /Accepted/.test(m.subject) && /not a certification/.test(m.text)));
    const toOwner = acc.find(m => m.to[0][0] === 'owner@example.com');
    const toClub = acc.find(m => m.to[0][0] === 'classing@wcma.ca');
    assert.ok(toOwner && toClub);
    assert.ok(!/admin\.php/.test(toOwner.text) && /decals/.test(toOwner.text));
    assert.match(toClub.text, /admin\.php\?action=gear-record&id=/);
    console.log('retook, resubmitted, accepted remotely; 2 emails (owner copy + club copy) with disclaimer');

    // ── Owner sees the chip and a banner; edits are locked ───────────────────
    await owner.goto(BASE + '/gear.php');
    assert.match(await (await rowFor(owner, 'Jane Racer')).textContent(), /Gear pre-teched \d{4}/);
    await owner.goto(janeUrl);
    assert.match(await owner.textContent('body'), /already teched/i);

    // ── Sam: accepted in person, no photos, then revoked ─────────────────────
    await admin.goto(BASE + '/admin.php?action=gear&filter=needs_gear');
    assert.strictEqual(await admin.locator('#gear-admin-table tbody tr').count(), 1, 'only Sam still needs a gear check');
    await admin.click('#gear-admin-table tbody tr a');
    await Promise.all([admin.waitForNavigation(), admin.click('#gear-inperson-btn')]);
    body = await admin.textContent('body');
    assert.match(body, /teched in person/i);
    assert.match(body, /Gear teched \d{4}/);
    assert.match(body, /Accepted in person by Admin/);
    assert.strictEqual(mailLog().length, 7, 'in-person acceptance sends no email');
    await owner.goto(BASE + '/gear.php');
    assert.match(await (await rowFor(owner, 'Sam Coach')).textContent(), /Gear teched \d{4}/);

    await admin.click('text=Revoke acceptance');
    await Promise.all([admin.waitForNavigation(), admin.click('[data-role="confirm"]')]);
    assert.match(await admin.textContent('body'), /Acceptance revoked/);
    await owner.goto(BASE + '/gear.php');
    assert.match(await (await rowFor(owner, 'Sam Coach')).textContent(), /Needs gear check at the track/);
    console.log('Sam: accepted in person (no email), revoked, back to needing a gear check');

    // ── Revoking a photo acceptance returns Jane to the review queue ─────────
    await admin.goto(BASE + '/admin.php?action=gear&filter=accepted');
    await admin.click('#gear-admin-table tbody tr a');
    await admin.click('text=Revoke acceptance');
    await Promise.all([admin.waitForNavigation(), admin.click('[data-role="confirm"]')]);
    await admin.goto(BASE + '/admin.php?action=gear&filter=pending_review');
    assert.strictEqual(await admin.locator('#gear-admin-table tbody tr').count(), 1, 'photo acceptance revoked: back in the queue');
    console.log('revoke of a photo acceptance: back in the review queue');

    // ── Access control: non-admins cannot reach the admin gear pages ─────────
    await owner.goto(BASE + '/admin.php?action=gear');
    assert.ok(!(await owner.content()).includes('Gear — WCMA Admin'), 'non-admin blocked');

    await browser.close();
    console.log('E2E OK');
})().catch(e => { console.error(e); process.exit(1); });
```

- [ ] **Step 3: Start the server (port 8126, scratch DB and mail log only)**

Run (repo root, in the background): `php -d auto_prepend_file=C:/dev/wcmaclasscalc/scratch/tech3a-prepend.php -S localhost:8126 -t wcma-calculator scratch/tech3a-router.php`
Verify: `curl -s -o /dev/null -w "%{http_code}" "http://localhost:8126/harness?as=admin"` returns `200`; `scratch/tech3a-e2e.db` now exists; the default DB counts still match Step 1.

- [ ] **Step 4: Run the end-to-end script**

Run: `node scratch/tech3a-e2e.js`
Expected: output ending with `E2E OK`. If a step fails, decide whether the scratch script or the product is wrong: fix scratch-script mistakes (selectors, timing) in the scratch script only, and report genuine product defects with evidence (file, line, expected behaviour) instead of editing product code in this task.

- [ ] **Step 5: Stop the server and clean up**

Stop the PHP server (find the listener on 8126 with `netstat -ano | grep 8126 | grep LISTENING`, kill that process id, confirm the port is free). Remove `scratch/tech3a-e2e.db*` and `scratch/tech3a-mail.log`. Photos uploaded by the run live under `wcma-calculator/uploads/inspection/gear_record/<id>/`: list them and delete only the folders created by this run (ids 1 and 2 in the scratch DB; they did not exist before, check timestamps), then remove empty parent folders this run created. `git status --short` must show only the pre-existing `?? .htaccess.server` and `?? scratch/`.

- [ ] **Step 6: Verify the default database and run the full regression**

Re-run the Step 1 command: the four counts must match, and `SELECT COUNT(*) FROM gear_records` on the default DB must be `0`. Then from `wcma-calculator/` run `php phpunit.phar` (all tests OK) and `node --test "tests/js/*.test.js"` (`# fail 0`). Nothing to commit for this task.

---

## Self-Review Notes

- **Spec coverage (phase 3a):** the `gear_records` table with the spec's columns plus a normalised name for uniqueness (Task 1); one record per driver per year, created and managed by whoever adds the driver, licence number optional, and "start next year" via renew (Tasks 2, 5); gear photo requirements with their tiers, typed details and conditional toggle through the existing endpoint with owner-scoped authorisation and the same write lock (Task 3); the same submit / send-back / accept-remotely / revoke loop with emails (Tasks 2, 4, 6); in-person acceptance (Task 6); My Drivers page and nav (Task 5). Deliberately deferred to phase 3b: picking gear records on the event sheet form (entrant and additional drivers), showing gear chips on tech sheets and the roster, and any reminder/expiry features.
- **Rulings recorded here for the reviewer:** in-person acceptance of gear captures no signature (one button; reviewer and time only), because gear is accepted as a whole record rather than per-item; gear pre-tech emails go to the same club address as tech sheets; a shared `renderAdminNav()` replaces the six hard-coded admin nav strings so new tabs need one edit.
- **Type consistency:** `gearStatus()` returns `{state, via}` consumed by `gearStatusLabel()`, `gearStatusBadgeClass()` and both list pages; `gearSnapshot()` mirrors `pretechSnapshot()`'s shape so the reused `pretechRenderCard()` and `js/pretech-form.js` work unchanged; `gearAccessShape()` maps a record to the tech-sheet shape `inspectionCanAccess()` expects; `gearNotify()`'s `$sendFn(array $to, array $message)` matches `emailSmtpSend`.
- **Known limits:** the competitor and admin gear pages and handlers load `config.php`, so they are verified end to end (Task 7) rather than by PHPUnit; all decisions they make are in tested libraries; the page renderers are unit-tested with stubbed session functions.
