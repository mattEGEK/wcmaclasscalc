# Pre-Tech Phase 2a: Inspector Foundations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give inspectors a way to see who still needs tech at an event and to accept a car in person, and show every competitor their car's annual tech status, all before any photo pre-tech exists.

**Architecture:** A pure status module (`tech-status.php`) derives a car's annual status from its tech sheets (car identity = owner + normalised car number + calendar year). New columns on `tech_sheets` store that identity and how a sheet was accepted. A session-free review library (`tech-review-lib.php`) performs the accept/revoke actions; a new admin module (`admin-tech-sheets.php`) adds a roster list and a mobile-first review page on top of it. Competitor pages (My Cars, sheet view) and the rendered sheet show the derived status. Photo pre-tech (phase 2b) later adds `photo_status` values; this phase already reads them so the derivation is complete.

**Tech Stack:** PHP 8.3, SQLite via PDO, PHPUnit (`phpunit.phar`), vanilla JS (classic scripts), Playwright (installed in `scratch/tech-sheet-mockups`) for the end-to-end check.

**Spec:** `docs/superpowers/specs/2026-09-23-digital-tech-inspection-design.md` (implements *Concepts*, *Derived car status*, *Not blocking*, the inspector roster and in-person acceptance from *Inspector Flow*, and the terminology rule). Photo capture, photo review, gear records and the acceptance emails are phases 2b and 3.

## Global Constraints

- **Terminology (binding):** UI copy uses "reviewed", "accepted", "teched", "pre-teched"; never "approved", "passed" or "safe" in labels or buttons. The one place the word "safe" appears is the required disclaimer, verbatim: *"Acceptance confirms that what you submitted matches what was reviewed. It is not a certification that the vehicle or equipment is safe."*
- **Car identity:** owner (`user_id`) + normalised car number + season. Normalisation: trim, uppercase, strip leading zeros, but a number that is all zeros (e.g. `00`) is kept as-is. **Season** = calendar year of the sheet's event date.
- **Derived car status**, from all of that owner's sheets with that identity, in precedence order: `accepted` (any sheet has `status = 'teched'`; shown as *Teched* when `accepted_via = 'in_person'`, *Pre-teched* when `'photos'`; a legacy teched row with no `accepted_via` counts as in person) > `needs_changes` (any `photo_status = 'needs_changes'`) > `pending_review` (any `photo_status = 'submitted'`) > `photos_draft` (any `photo_status = 'draft'`) > `none` (shown as *Needs tech at the track*).
- **Not blocking:** an event sheet is always submittable regardless of car status. Status is informational.
- **Acceptance data:** accepting a sheet sets `status = 'teched'`, `accepted_via`, `reviewed_by_user_id`, `reviewed_at`; in-person acceptance also stores the inspector's canvas signature in `tech_signature_path` / `tech_signed_at`. The existing lock stays: a teched sheet cannot be edited by the competitor.
- **Admin only:** the review actions and pages are admin-only (existing `requireAuth()` in `admin.php`, CSRF on every POST).
- **Roster filter names:** `all`, `needs_tech` (car not accepted), `accepted`. The UI label for `needs_tech` is "Needs tech at the track".
- **Review page is mobile-first** (inspectors use it from a phone at the track); the roster list is desktop-oriented like the other admin lists.
- **Repo conventions:** LF-authored PHP files with a `// wcma-calculator/<file>` header comment; tests in `wcma-calculator/tests/*Test.php`; migrations idempotent inside `db_init()`; commit after each task with a subject line, a blank line, then the trailer; the test suite must be run from `wcma-calculator/` with `php phpunit.phar`.

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `wcma-calculator/tech-status.php` | create | Pure functions: car-number normalisation, season, identity key, derived status, labels, roster build/filter, default event |
| `wcma-calculator/tech-sheet-files.php` | create | Decode/write/delete tech-sheet signature PNGs |
| `wcma-calculator/tech-review-lib.php` | create | Session-free accept/revoke actions (DB + files) |
| `wcma-calculator/admin-tech-sheets.php` | create | Admin roster list, review page, accept/revoke/sig handlers |
| `wcma-calculator/js/admin-tech-review.js` | create | Signature pad wiring for the review form |
| `wcma-calculator/db.php` | modify | Require `tech-status.php`; new `tech_sheets` columns + backfill + index; identity on insert/update; accept/revoke and roster queries |
| `wcma-calculator/tech-sheets.php` | modify | Use shared signature helper; competitor view shows car status |
| `wcma-calculator/tech-sheet-data.php` | modify | `TECH_ACCEPTANCE_DISCLAIMER` constant |
| `wcma-calculator/tech-sheet-render.php` | modify | Status line shows how/when reviewed, plus disclaimer |
| `wcma-calculator/account.php` | modify | My Cars shows derived car status |
| `wcma-calculator/admin.php` | modify | Require the new module, route the new actions, add "Tech Sheets" nav link |
| `wcma-calculator/admin-feedback.php` | modify | Add "Tech Sheets" nav link |
| `wcma-calculator/tests/TechStatusTest.php` | create | Pure status module |
| `wcma-calculator/tests/DbTechStatusTest.php` | create | Migration, identity, accept/revoke, roster queries |
| `wcma-calculator/tests/TechSheetFilesTest.php` | create | Signature helper |
| `wcma-calculator/tests/TechReviewLibTest.php` | create | Accept/revoke actions |
| `wcma-calculator/tests/TechSheetRenderTest.php` | modify | Status line and disclaimer |

Handlers in `admin.php` and `account.php` are thin: all decisions live in the pure module, the DB layer and the review library, which are unit-tested. The admin pages themselves are verified end to end in Task 7 (they need `config.php`, which is not present in every environment, so they are not driven from PHPUnit).

---

### Task 1: Pure tech status module

**Files:**
- Create: `wcma-calculator/tech-status.php`
- Test: `wcma-calculator/tests/TechStatusTest.php`

**Interfaces:**
- Consumes: none.
- Produces (global functions):
  - `techCarNumberNorm(string $carNumber): string`
  - `techSeasonFromDate(?string $eventDate): int`
  - `techCarKey(array $sheet): string` — `user_id|norm|season`; uses `car_number_norm` / `season` when present, else derives them (`car_number`), season `0` if absent.
  - `techCarStatus(array $sheets): array{state: string, via: ?string, sheet_id: ?int}` — `$sheets` are `tech_sheets` rows of ONE car identity; `state` is one of `accepted|needs_changes|pending_review|photos_draft|none`.
  - `techCarStatusLabel(array $status, int $season): string`
  - `techCarStatusBadgeClass(string $state): string` — `badge-ok` | `badge-fail` | `badge-pending`.
  - `techGroupSheetsByCar(array $sheets): array<string, array>` — grouped by `techCarKey`.
  - `techCarStatusForSheet(array $sheet, array $ownerSheets): array` — status of `$sheet`'s car within `$ownerSheets`.
  - `techBuildRoster(array $eventSheets, array $seasonSheets): array` — list of `['sheet' => row, 'status' => techCarStatus]`.
  - `techRosterFilter(array $rows, string $filter): array` — `all` | `needs_tech` | `accepted`; unknown filter behaves as `all`.
  - `techDefaultEventId(array $events, string $today): int` — nearest active event on/after `$today`, else the most recent event, else `0`.

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/TechStatusTest.php`:

```php
<?php
// wcma-calculator/tests/TechStatusTest.php
require_once __DIR__ . '/../tech-status.php';

use PHPUnit\Framework\TestCase;

final class TechStatusTest extends TestCase
{
    private function sheet(int $id, array $o = []): array {
        return array_merge([
            'id' => $id, 'user_id' => 5, 'car_number' => '42', 'car_number_norm' => '42', 'season' => 2026,
            'event_id' => 10, 'status' => 'submitted', 'accepted_via' => null, 'photo_status' => null,
        ], $o);
    }

    public function testCarNumberNormalisation(): void
    {
        $this->assertSame('42', techCarNumberNorm(' 42 '));
        $this->assertSame('42', techCarNumberNorm('042'));
        $this->assertSame('7', techCarNumberNorm('007'));
        $this->assertSame('00', techCarNumberNorm('00'));
        $this->assertSame('0', techCarNumberNorm('0'));
        $this->assertSame('7A', techCarNumberNorm('7a'));
        $this->assertSame('', techCarNumberNorm('   '));
    }

    public function testSeasonFromDate(): void
    {
        $this->assertSame(2026, techSeasonFromDate('2026-05-10'));
        $this->assertSame(2027, techSeasonFromDate('2027-01-02 09:00:00'));
        $this->assertSame((int)date('Y'), techSeasonFromDate(null));
        $this->assertSame((int)date('Y'), techSeasonFromDate('garbage'));
    }

    public function testCarKeyUsesStoredIdentityOrDerivesIt(): void
    {
        $this->assertSame('5|42|2026', techCarKey($this->sheet(1)));
        $legacy = ['id' => 2, 'user_id' => 5, 'car_number' => ' 042 ', 'season' => 2026];
        $this->assertSame('5|42|2026', techCarKey($legacy));
        $otherOwner = $this->sheet(3, ['user_id' => 6]);
        $this->assertNotSame(techCarKey($this->sheet(1)), techCarKey($otherOwner));
    }

    public function testNoSheetsOrNoAcceptanceMeansNone(): void
    {
        $this->assertSame(['state' => 'none', 'via' => null, 'sheet_id' => null], techCarStatus([]));
        $this->assertSame('none', techCarStatus([$this->sheet(1), $this->sheet(2)])['state']);
    }

    public function testAnyAcceptedSheetAcceptsTheCar(): void
    {
        $status = techCarStatus([
            $this->sheet(1),
            $this->sheet(2, ['status' => 'teched', 'accepted_via' => 'in_person']),
        ]);
        $this->assertSame(['state' => 'accepted', 'via' => 'in_person', 'sheet_id' => 2], $status);
    }

    public function testLegacyTechedRowCountsAsInPerson(): void
    {
        $status = techCarStatus([$this->sheet(1, ['status' => 'teched', 'accepted_via' => null])]);
        $this->assertSame('in_person', $status['via']);
    }

    public function testEarliestAcceptedSheetIsReported(): void
    {
        $status = techCarStatus([
            $this->sheet(9, ['status' => 'teched', 'accepted_via' => 'photos']),
            $this->sheet(3, ['status' => 'teched', 'accepted_via' => 'in_person']),
        ]);
        $this->assertSame(3, $status['sheet_id']);
    }

    public function testPhotoStatePrecedence(): void
    {
        $draft = $this->sheet(1, ['photo_status' => 'draft']);
        $pending = $this->sheet(2, ['photo_status' => 'submitted']);
        $changes = $this->sheet(3, ['photo_status' => 'needs_changes']);

        $this->assertSame('photos_draft', techCarStatus([$draft])['state']);
        $this->assertSame('pending_review', techCarStatus([$draft, $pending])['state']);
        $this->assertSame('needs_changes', techCarStatus([$draft, $pending, $changes])['state']);
        $accepted = $this->sheet(4, ['status' => 'teched', 'accepted_via' => 'photos']);
        $this->assertSame('accepted', techCarStatus([$draft, $pending, $changes, $accepted])['state']);
    }

    public function testLabelsAndBadgeClasses(): void
    {
        $this->assertSame('Teched 2026', techCarStatusLabel(['state' => 'accepted', 'via' => 'in_person', 'sheet_id' => 1], 2026));
        $this->assertSame('Pre-teched 2026', techCarStatusLabel(['state' => 'accepted', 'via' => 'photos', 'sheet_id' => 1], 2026));
        $this->assertSame('Needs tech at the track', techCarStatusLabel(['state' => 'none', 'via' => null, 'sheet_id' => null], 2026));
        $this->assertSame('Photos pending review', techCarStatusLabel(['state' => 'pending_review', 'via' => null, 'sheet_id' => 1], 2026));
        $this->assertSame('Photos need changes', techCarStatusLabel(['state' => 'needs_changes', 'via' => null, 'sheet_id' => 1], 2026));
        $this->assertSame('Photos in progress', techCarStatusLabel(['state' => 'photos_draft', 'via' => null, 'sheet_id' => 1], 2026));

        $this->assertSame('badge-ok', techCarStatusBadgeClass('accepted'));
        $this->assertSame('badge-fail', techCarStatusBadgeClass('needs_changes'));
        $this->assertSame('badge-pending', techCarStatusBadgeClass('none'));
        $this->assertSame('badge-pending', techCarStatusBadgeClass('pending_review'));
    }

    public function testNoApprovalWordingInLabels(): void
    {
        foreach (['accepted', 'needs_changes', 'pending_review', 'photos_draft', 'none'] as $state) {
            foreach (['in_person', 'photos'] as $via) {
                $label = techCarStatusLabel(['state' => $state, 'via' => $via, 'sheet_id' => 1], 2026);
                $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', $label);
            }
        }
    }

    public function testStatusForSheetUsesOnlyThatCarsSheets(): void
    {
        $mine = $this->sheet(1);
        $sameCarLater = $this->sheet(2, ['event_id' => 11, 'status' => 'teched', 'accepted_via' => 'in_person']);
        $otherCar = $this->sheet(3, ['car_number' => '7', 'car_number_norm' => '7', 'status' => 'teched']);
        $lastYear = $this->sheet(4, ['season' => 2025, 'status' => 'teched']);

        $owner = [$mine, $sameCarLater, $otherCar, $lastYear];
        $this->assertSame('accepted', techCarStatusForSheet($mine, $owner)['state']);
        $this->assertSame('none', techCarStatusForSheet($this->sheet(5, ['car_number_norm' => '99', 'car_number' => '99']), $owner)['state']);
        $this->assertSame('none', techCarStatusForSheet($this->sheet(6, ['season' => 2027]), $owner)['state']);
    }

    public function testRosterAndFilters(): void
    {
        $eventSheets = [
            $this->sheet(1),
            $this->sheet(2, ['user_id' => 6, 'car_number' => '7', 'car_number_norm' => '7']),
            $this->sheet(3, ['user_id' => 7, 'car_number' => '9', 'car_number_norm' => '9']),
        ];
        $seasonSheets = array_merge($eventSheets, [
            $this->sheet(20, ['event_id' => 11, 'status' => 'teched', 'accepted_via' => 'in_person']),          // accepts car 42 (owner 5)
            $this->sheet(21, ['user_id' => 7, 'car_number' => '9', 'car_number_norm' => '9', 'event_id' => 11, 'photo_status' => 'submitted']),
        ]);

        $rows = techBuildRoster($eventSheets, $seasonSheets);
        $this->assertCount(3, $rows);
        $this->assertSame('accepted', $rows[0]['status']['state']);
        $this->assertSame('none', $rows[1]['status']['state']);
        $this->assertSame('pending_review', $rows[2]['status']['state']);

        $this->assertCount(3, techRosterFilter($rows, 'all'));
        $this->assertCount(3, techRosterFilter($rows, 'bogus'));
        $this->assertSame([1], array_map(fn($r) => $r['sheet']['id'], techRosterFilter($rows, 'accepted')));
        $this->assertSame([2, 3], array_map(fn($r) => $r['sheet']['id'], techRosterFilter($rows, 'needs_tech')));
    }

    public function testDefaultEventId(): void
    {
        $events = [
            ['id' => 1, 'event_date' => '2026-05-01', 'active' => 1],
            ['id' => 2, 'event_date' => '2026-10-04', 'active' => 1],
            ['id' => 3, 'event_date' => '2026-09-01', 'active' => 0],
            ['id' => 4, 'event_date' => '2026-11-15', 'active' => 1],
        ];
        $this->assertSame(2, techDefaultEventId($events, '2026-09-24'));
        $this->assertSame(2, techDefaultEventId($events, '2026-10-04'));
        $this->assertSame(4, techDefaultEventId($events, '2026-10-05'));
        $this->assertSame(4, techDefaultEventId($events, '2027-01-01'));   // none upcoming: most recent event
        $this->assertSame(0, techDefaultEventId([], '2026-09-24'));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run (from `wcma-calculator/`): `php phpunit.phar --filter TechStatusTest`
Expected: fatal error, `tech-status.php` not found.

- [ ] **Step 3: Write the module**

Create `wcma-calculator/tech-status.php`:

```php
<?php
// wcma-calculator/tech-status.php
//
// Pure functions (no DB, no HTML) for a car's annual tech status. A car is
// identified by owner + normalised car number + season (calendar year); its
// status is derived from all of that car's tech sheets in the season. Any
// accepted sheet accepts the car for the year.

/** Trim, uppercase, strip leading zeros. A number that is all zeros (e.g. "00") is kept as-is. */
function techCarNumberNorm(string $carNumber): string {
    $n = strtoupper(trim($carNumber));
    $stripped = ltrim($n, '0');
    return $stripped === '' ? $n : $stripped;
}

/** Calendar year of an event date ('YYYY-MM-DD...'); the current year if it cannot be read. */
function techSeasonFromDate(?string $eventDate): int {
    if ($eventDate !== null && preg_match('/^(\d{4})-\d{2}-\d{2}/', $eventDate, $m)) {
        return (int)$m[1];
    }
    return (int)date('Y');
}

/** Groups sheets that belong to the same car in the same season. */
function techCarKey(array $sheet): string {
    $norm = $sheet['car_number_norm'] ?? null;
    if ($norm === null || $norm === '') {
        $norm = techCarNumberNorm((string)($sheet['car_number'] ?? ''));
    }
    return (int)$sheet['user_id'] . '|' . $norm . '|' . (int)($sheet['season'] ?? 0);
}

/**
 * Derived status of one car from ITS tech sheets (same identity).
 * Precedence: accepted > needs_changes > pending_review > photos_draft > none.
 *
 * @param array[] $sheets tech_sheets rows for one car identity
 * @return array{state: string, via: ?string, sheet_id: ?int}
 */
function techCarStatus(array $sheets): array {
    usort($sheets, fn(array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);

    foreach ($sheets as $s) {
        if (($s['status'] ?? '') === 'teched') {
            return ['state' => 'accepted', 'via' => $s['accepted_via'] ?? 'in_person', 'sheet_id' => (int)$s['id']];
        }
    }
    foreach (['needs_changes' => 'needs_changes', 'submitted' => 'pending_review', 'draft' => 'photos_draft'] as $photoStatus => $state) {
        foreach ($sheets as $s) {
            if (($s['photo_status'] ?? null) === $photoStatus) {
                return ['state' => $state, 'via' => null, 'sheet_id' => (int)$s['id']];
            }
        }
    }
    return ['state' => 'none', 'via' => null, 'sheet_id' => null];
}

function techCarStatusLabel(array $status, int $season): string {
    switch ($status['state']) {
        case 'accepted':       return ($status['via'] === 'photos' ? 'Pre-teched ' : 'Teched ') . $season;
        case 'needs_changes':  return 'Photos need changes';
        case 'pending_review': return 'Photos pending review';
        case 'photos_draft':   return 'Photos in progress';
        default:               return 'Needs tech at the track';
    }
}

function techCarStatusBadgeClass(string $state): string {
    if ($state === 'accepted') return 'badge-ok';
    if ($state === 'needs_changes') return 'badge-fail';
    return 'badge-pending';
}

/** @return array<string, array[]> sheets keyed by techCarKey() */
function techGroupSheetsByCar(array $sheets): array {
    $groups = [];
    foreach ($sheets as $s) {
        $groups[techCarKey($s)][] = $s;
    }
    return $groups;
}

/** Status of $sheet's car within $ownerSheets (which may include other cars and seasons). */
function techCarStatusForSheet(array $sheet, array $ownerSheets): array {
    $groups = techGroupSheetsByCar($ownerSheets);
    return techCarStatus($groups[techCarKey($sheet)] ?? []);
}

/**
 * One roster row per sheet in $eventSheets, with the derived status of that
 * sheet's car. $seasonSheets must include every sheet in the season(s) of
 * $eventSheets, because a car's other sheets decide its status.
 *
 * @return array<int, array{sheet: array, status: array}>
 */
function techBuildRoster(array $eventSheets, array $seasonSheets): array {
    $groups = techGroupSheetsByCar($seasonSheets);
    $rows = [];
    foreach ($eventSheets as $s) {
        $rows[] = ['sheet' => $s, 'status' => techCarStatus($groups[techCarKey($s)] ?? [$s])];
    }
    return $rows;
}

/** $filter: 'all' | 'needs_tech' (car not accepted) | 'accepted'. Unknown values mean 'all'. */
function techRosterFilter(array $rows, string $filter): array {
    if ($filter !== 'needs_tech' && $filter !== 'accepted') return $rows;
    return array_values(array_filter($rows, function (array $r) use ($filter): bool {
        $accepted = $r['status']['state'] === 'accepted';
        return $filter === 'accepted' ? $accepted : !$accepted;
    }));
}

/** The event to show first: nearest active event on/after $today, else the most recent event, else 0 (all events). */
function techDefaultEventId(array $events, string $today): int {
    $upcoming = null;
    $latest = null;
    foreach ($events as $e) {
        if ($latest === null || $e['event_date'] > $latest['event_date']) $latest = $e;
        if ((int)$e['active'] === 1 && $e['event_date'] >= $today
            && ($upcoming === null || $e['event_date'] < $upcoming['event_date'])) {
            $upcoming = $e;
        }
    }
    return (int)(($upcoming ?? $latest)['id'] ?? 0);
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar --filter TechStatusTest`
Expected: `OK (12 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/tech-status.php wcma-calculator/tests/TechStatusTest.php
git commit -m "feat(tech-status): add pure car tech status derivation and roster helpers"
```

---

### Task 2: Database identity columns, acceptance and roster queries

**Files:**
- Modify: `wcma-calculator/db.php` (top: one `require_once`; `db_init()` migration; `db_insert_tech_sheet`; `db_update_tech_sheet`; new functions at end)
- Test: `wcma-calculator/tests/DbTechStatusTest.php`

**Interfaces:**
- Consumes: `techCarNumberNorm()`, `techSeasonFromDate()` (Task 1); existing `db_get_event()`, `db_insert_tech_sheet()`, `db_create_event()`, `db_create_user()`, `db_insert_submission()`.
- Produces:
  - `tech_sheets` gains columns `accepted_via TEXT`, `photo_status TEXT`, `car_number_norm TEXT`, `season INTEGER` and index `idx_tech_sheets_car (user_id, car_number_norm, season)`; existing rows are backfilled.
  - `db_tech_sheet_identity(PDO $pdo, string $carNumber, int $eventId): array{car_number_norm: string, season: int}`
  - `db_insert_tech_sheet` / `db_update_tech_sheet` keep the identity columns current.
  - `db_accept_tech_sheet_in_person(PDO $pdo, int $id, int $reviewerUserId, string $signaturePath): bool` — true only if the sheet was `submitted` and is now `teched`.
  - `db_revoke_tech_sheet_acceptance(PDO $pdo, int $id): bool` — true only if the sheet was `teched`; returns it to `submitted` and clears reviewer, timestamps, `accepted_via` and the tech signature columns.
  - `db_get_identity_sheets(PDO $pdo, int $userId, string $carNumberNorm, int $season): array`
  - `db_get_season_sheets(PDO $pdo, int $season): array`
  - `db_get_event_tech_sheets(PDO $pdo, int $eventId): array` — `eventId 0` means all events; rows include `event_name` and `event_date`; ordered by event date (newest first) then car number.

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/DbTechStatusTest.php`:

```php
<?php
// wcma-calculator/tests/DbTechStatusTest.php
use PHPUnit\Framework\TestCase;

final class DbTechStatusTest extends TestCase
{
    private function fixture(PDO $pdo): array {
        $userId = db_create_user($pdo, ['email' => 'racer@example.com', 'name' => 'Racer', 'password_hash' => 'x', 'google_id' => null]);
        $subId = db_insert_submission($pdo, [
            ':submitted_at' => date('Y-m-d H:i:s'), ':name' => 'Racer', ':email' => 'racer@example.com',
            ':year' => '2020', ':make' => 'Mazda', ':model' => 'MX-5', ':comments' => null,
            ':competition_weight' => 2200, ':declared_hp' => 150, ':dyno_hp' => null,
            ':chassis_display' => null, ':body_mods_display' => null, ':transmission_display' => null,
            ':drivetrain_display' => null, ':tires_display' => null, ':brake_suspension' => null,
            ':chassis_value' => 0, ':body_mods_value' => 0, ':transmission_value' => 0,
            ':drivetrain_value' => 0, ':tires_value' => 0, ':brake_suspension_value' => 0,
            ':weight_factor' => 0, ':modification_factor' => 0, ':base_ratio' => 14.67, ':modified_ratio' => 14.67,
            ':calculated_class' => 'IT1', ':user_id' => $userId,
        ]);
        $spring = db_create_event($pdo, 'Spring Sprint', '2026-05-10', null);
        $fall = db_create_event($pdo, 'Fall Finale', '2026-10-04', null);
        $next = db_create_event($pdo, 'Next Year Opener', '2027-04-18', null);
        return [$userId, $subId, $spring, $fall, $next];
    }

    private function sheet(int $userId, int $subId, int $eventId, string $number = '42'): array {
        return [
            'submission_id' => $subId, 'user_id' => $userId, 'event_id' => $eventId, 'sheet_type' => 'standard',
            'entrant_name' => 'Racer', 'driver_name' => 'Racer', 'car_make' => 'Mazda', 'car_model' => 'MX-5',
            'car_colour' => 'Red', 'car_number' => $number, 'class' => 'IT1', 'engine_cc' => '1800', 'engine_hp' => '150',
            'car_weight' => 2200, 'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1,
        ];
    }

    public function testInsertStoresNormalisedNumberAndSeason(): void
    {
        $pdo = make_temp_pdo();
        [$u, $s, $spring, , $next] = $this->fixture($pdo);

        $id = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $spring, ' 042 '));
        $row = db_get_tech_sheet($pdo, $id);
        $this->assertSame('42', $row['car_number_norm']);
        $this->assertSame(2026, (int)$row['season']);

        $id2 = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $next, '42'));
        $this->assertSame(2027, (int)db_get_tech_sheet($pdo, $id2)['season']);
    }

    public function testUpdateRecomputesIdentity(): void
    {
        $pdo = make_temp_pdo();
        [$u, $s, $spring, , $next] = $this->fixture($pdo);
        $id = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $spring, '42'));

        $data = $this->sheet($u, $s, $next, '07');
        db_update_tech_sheet($pdo, $id, $data);

        $row = db_get_tech_sheet($pdo, $id);
        $this->assertSame('7', $row['car_number_norm']);
        $this->assertSame(2027, (int)$row['season']);
    }

    public function testMigrationBackfillsExistingRows(): void
    {
        $pdo = make_temp_pdo();
        [$u, $s, $spring] = $this->fixture($pdo);
        $id = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $spring, '042'));
        $pdo->exec("UPDATE tech_sheets SET car_number_norm = NULL, season = NULL WHERE id = $id");

        db_init($pdo);   // idempotent; backfills rows that predate the columns

        $row = db_get_tech_sheet($pdo, $id);
        $this->assertSame('42', $row['car_number_norm']);
        $this->assertSame(2026, (int)$row['season']);
        $this->assertNull($row['accepted_via']);
        $this->assertNull($row['photo_status']);
    }

    public function testAcceptInPersonOnlyFromSubmittedAndOnlyOnce(): void
    {
        $pdo = make_temp_pdo();
        [$u, $s, $spring] = $this->fixture($pdo);
        $admin = db_create_user($pdo, ['email' => 'tech@example.com', 'name' => 'Tech', 'password_hash' => 'x', 'google_id' => null]);
        $id = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $spring));

        $this->assertTrue(db_accept_tech_sheet_in_person($pdo, $id, $admin, 'uploads/tech-sheets/1/tech.png'));

        $row = db_get_tech_sheet($pdo, $id);
        $this->assertSame('teched', $row['status']);
        $this->assertSame('in_person', $row['accepted_via']);
        $this->assertSame($admin, (int)$row['reviewed_by_user_id']);
        $this->assertNotNull($row['reviewed_at']);
        $this->assertSame('uploads/tech-sheets/1/tech.png', $row['tech_signature_path']);
        $this->assertNotNull($row['tech_signed_at']);

        $this->assertFalse(db_accept_tech_sheet_in_person($pdo, $id, $admin, 'other.png'));
        $this->assertSame('uploads/tech-sheets/1/tech.png', db_get_tech_sheet($pdo, $id)['tech_signature_path']);
        $this->assertFalse(db_accept_tech_sheet_in_person($pdo, 99999, $admin, 'x.png'));
    }

    public function testRevokeReturnsSheetToSubmittedAndClearsReviewFields(): void
    {
        $pdo = make_temp_pdo();
        [$u, $s, $spring] = $this->fixture($pdo);
        $id = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $spring));

        $this->assertFalse(db_revoke_tech_sheet_acceptance($pdo, $id));   // not accepted yet

        db_accept_tech_sheet_in_person($pdo, $id, $u, 'sig.png');
        $this->assertTrue(db_revoke_tech_sheet_acceptance($pdo, $id));

        $row = db_get_tech_sheet($pdo, $id);
        $this->assertSame('submitted', $row['status']);
        foreach (['accepted_via', 'reviewed_by_user_id', 'reviewed_at', 'tech_signature_path', 'tech_signed_at'] as $col) {
            $this->assertNull($row[$col], $col);
        }
    }

    public function testIdentityAndSeasonQueries(): void
    {
        $pdo = make_temp_pdo();
        [$u, $s, $spring, $fall, $next] = $this->fixture($pdo);
        $a = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $spring, '42'));
        $b = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $fall, '042'));
        $c = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $fall, '7'));
        $d = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $next, '42'));

        $ids = fn(array $rows) => array_map(fn($r) => (int)$r['id'], $rows);

        $this->assertEqualsCanonicalizing([$a, $b], $ids(db_get_identity_sheets($pdo, $u, '42', 2026)));
        $this->assertSame([$d], $ids(db_get_identity_sheets($pdo, $u, '42', 2027)));
        $this->assertSame([], db_get_identity_sheets($pdo, $u + 1, '42', 2026));
        $this->assertEqualsCanonicalizing([$a, $b, $c], $ids(db_get_season_sheets($pdo, 2026)));
    }

    public function testEventTechSheetsIncludeEventInfoAndOrderByCarNumber(): void
    {
        $pdo = make_temp_pdo();
        [$u, $s, $spring, $fall] = $this->fixture($pdo);
        $n10 = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $fall, '10'));
        $n9  = db_insert_tech_sheet($pdo, $this->sheet($u, $s, $fall, '9'));
        db_insert_tech_sheet($pdo, $this->sheet($u, $s, $spring, '1'));

        $rows = db_get_event_tech_sheets($pdo, $fall);
        $this->assertSame([$n9, $n10], array_map(fn($r) => (int)$r['id'], $rows));
        $this->assertSame('Fall Finale', $rows[0]['event_name']);
        $this->assertSame('2026-10-04', $rows[0]['event_date']);

        $all = db_get_event_tech_sheets($pdo, 0);
        $this->assertCount(3, $all);
        $this->assertSame('Fall Finale', $all[0]['event_name']);   // newest event first
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter DbTechStatusTest`
Expected: errors such as `Call to undefined function db_accept_tech_sheet_in_person()` and missing-column errors.

- [ ] **Step 3: Require the status module**

In `wcma-calculator/db.php`, immediately after the file's opening docblock (the block ending `*/` before `if (!defined('DB_PATH'))`), add:

```php
require_once __DIR__ . '/tech-status.php';
```

- [ ] **Step 4: Add the migration**

In `db.php`, inside `db_init()`, after the final migration (the block that adds `active` to `users`, ending with `$pdo->exec("ALTER TABLE users ADD COLUMN active INTEGER NOT NULL DEFAULT 1");` and its closing `}`) and before the closing brace of `db_init()`, insert:

```php

    // Annual tech status columns and car identity on tech_sheets, if migrating an existing DB
    $techCols = array_column($pdo->query("PRAGMA table_info(tech_sheets)")->fetchAll(), 'name');
    foreach (['accepted_via' => 'TEXT', 'photo_status' => 'TEXT', 'car_number_norm' => 'TEXT', 'season' => 'INTEGER'] as $col => $type) {
        if (!in_array($col, $techCols, true)) {
            $pdo->exec("ALTER TABLE tech_sheets ADD COLUMN {$col} {$type}");
        }
    }
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tech_sheets_car ON tech_sheets (user_id, car_number_norm, season)");
    $unfilled = $pdo->query("
        SELECT ts.id, ts.car_number, ts.created_at, e.event_date
        FROM tech_sheets ts LEFT JOIN events e ON e.id = ts.event_id
        WHERE ts.car_number_norm IS NULL OR ts.season IS NULL
    ")->fetchAll();
    if ($unfilled) {
        $fill = $pdo->prepare("UPDATE tech_sheets SET car_number_norm = :n, season = :s WHERE id = :id");
        foreach ($unfilled as $row) {
            $fill->execute([
                ':n' => techCarNumberNorm((string)$row['car_number']),
                ':s' => techSeasonFromDate($row['event_date'] ?? $row['created_at']),
                ':id' => $row['id'],
            ]);
        }
    }
```

- [ ] **Step 5: Keep identity current on insert and update**

In `db_insert_tech_sheet`, make three edits. First, the column list:

```php
            checklist_json, driver1_equipment_json, log_book_turned_in,
            status, created_at, updated_at
        ) VALUES (
```
becomes
```php
            checklist_json, driver1_equipment_json, log_book_turned_in,
            car_number_norm, season,
            status, created_at, updated_at
        ) VALUES (
```
Second, the values list:
```php
            :checklist_json, :driver1_equipment_json, :log_book_turned_in,
            'submitted', :created_at, :updated_at
```
becomes
```php
            :checklist_json, :driver1_equipment_json, :log_book_turned_in,
            :car_number_norm, :season,
            'submitted', :created_at, :updated_at
```
Third, in the `execute([...])` array of `db_insert_tech_sheet` (the one that ends with `':created_at' => $now, ':updated_at' => $now,`), add the identity. Change:
```php
        ':log_book_turned_in' => $data['log_book_turned_in'] ?? null,
        ':created_at' => $now, ':updated_at' => $now,
    ]);
    return (int)$pdo->lastInsertId();
```
to
```php
        ':log_book_turned_in' => $data['log_book_turned_in'] ?? null,
        ':car_number_norm' => $identity['car_number_norm'], ':season' => $identity['season'],
        ':created_at' => $now, ':updated_at' => $now,
    ]);
    return (int)$pdo->lastInsertId();
```
and add, as the first statement after `$now = date('Y-m-d H:i:s');` in `db_insert_tech_sheet`:
```php
    $identity = db_tech_sheet_identity($pdo, (string)$data['car_number'], (int)$data['event_id']);
```

In `db_update_tech_sheet`, change the SQL tail:
```php
            log_book_turned_in = :log_book_turned_in, updated_at = :updated_at
        WHERE id = :id
```
to
```php
            log_book_turned_in = :log_book_turned_in,
            car_number_norm = :car_number_norm, season = :season, updated_at = :updated_at
        WHERE id = :id
```
and the execute tail:
```php
        ':log_book_turned_in' => $data['log_book_turned_in'] ?? null,
        ':updated_at' => date('Y-m-d H:i:s'), ':id' => $id,
    ]);
```
to
```php
        ':log_book_turned_in' => $data['log_book_turned_in'] ?? null,
        ':car_number_norm' => $identity['car_number_norm'], ':season' => $identity['season'],
        ':updated_at' => date('Y-m-d H:i:s'), ':id' => $id,
    ]);
```
adding, as the first statement of the function body of `db_update_tech_sheet`:
```php
    $identity = db_tech_sheet_identity($pdo, (string)$data['car_number'], (int)$data['event_id']);
```

- [ ] **Step 6: Add the new functions**

Append to the end of `wcma-calculator/db.php`:

```php

/** Normalised car number and season (calendar year of the sheet's event) for a tech sheet. */
function db_tech_sheet_identity(PDO $pdo, string $carNumber, int $eventId): array {
    $event = db_get_event($pdo, $eventId);
    return [
        'car_number_norm' => techCarNumberNorm($carNumber),
        'season' => techSeasonFromDate($event['event_date'] ?? null),
    ];
}

/**
 * Marks a submitted sheet as accepted in person. The WHERE clause makes this atomic:
 * returns false if the sheet does not exist or was already accepted.
 */
function db_accept_tech_sheet_in_person(PDO $pdo, int $id, int $reviewerUserId, string $signaturePath): bool {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        UPDATE tech_sheets SET
            status = 'teched', accepted_via = 'in_person',
            reviewed_by_user_id = :reviewer, reviewed_at = :now,
            tech_signature_path = :sig, tech_signed_at = :now, updated_at = :now
        WHERE id = :id AND status = 'submitted'
    ");
    $stmt->execute([':reviewer' => $reviewerUserId, ':now' => $now, ':sig' => $signaturePath, ':id' => $id]);
    return $stmt->rowCount() === 1;
}

/** Returns an accepted sheet to 'submitted' and clears its review fields. False if it was not accepted. */
function db_revoke_tech_sheet_acceptance(PDO $pdo, int $id): bool {
    $stmt = $pdo->prepare("
        UPDATE tech_sheets SET
            status = 'submitted', accepted_via = NULL,
            reviewed_by_user_id = NULL, reviewed_at = NULL,
            tech_signature_path = NULL, tech_signed_at = NULL, updated_at = :now
        WHERE id = :id AND status = 'teched'
    ");
    $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id]);
    return $stmt->rowCount() === 1;
}

/** All of one owner's sheets for a car identity (owner + normalised number + season). */
function db_get_identity_sheets(PDO $pdo, int $userId, string $carNumberNorm, int $season): array {
    $stmt = $pdo->prepare("SELECT * FROM tech_sheets WHERE user_id = :u AND car_number_norm = :n AND season = :s ORDER BY id ASC");
    $stmt->execute([':u' => $userId, ':n' => $carNumberNorm, ':s' => $season]);
    return $stmt->fetchAll();
}

/** Every tech sheet in a season (used to derive each car's status on a roster). */
function db_get_season_sheets(PDO $pdo, int $season): array {
    $stmt = $pdo->prepare("SELECT * FROM tech_sheets WHERE season = :s ORDER BY id ASC");
    $stmt->execute([':s' => $season]);
    return $stmt->fetchAll();
}

/** Sheets for one event (0 = every event), with event name/date, newest event first then by car number. */
function db_get_event_tech_sheets(PDO $pdo, int $eventId): array {
    $sql = "SELECT ts.*, e.name AS event_name, e.event_date AS event_date
            FROM tech_sheets ts LEFT JOIN events e ON e.id = ts.event_id";
    $params = [];
    if ($eventId > 0) {
        $sql .= " WHERE ts.event_id = :e";
        $params[':e'] = $eventId;
    }
    $sql .= " ORDER BY e.event_date DESC, CAST(ts.car_number_norm AS INTEGER) ASC, ts.car_number_norm ASC, ts.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php phpunit.phar`
Expected: `OK` for the whole suite, including `DbTechStatusTest` (7 tests) and the existing `DbTechSheetsTest` (insert/update still work with the identity columns).

- [ ] **Step 8: Commit**

```bash
git add wcma-calculator/db.php wcma-calculator/tests/DbTechStatusTest.php
git commit -m "feat(tech-status): store car identity and acceptance on tech sheets, add roster queries"
```

---

### Task 3: Shared signature-file helper

**Files:**
- Create: `wcma-calculator/tech-sheet-files.php`
- Modify: `wcma-calculator/tech-sheets.php` (replace `saveSignatureFile()` and its 4 call sites; add a `require`)
- Test: `wcma-calculator/tests/TechSheetFilesTest.php`

**Interfaces:**
- Consumes: none.
- Produces:
  - `techSheetDecodeSignature(string $dataUrl): ?string` — the PNG bytes of a `data:image/png;base64,...` URL, or `null` if it is not a valid PNG data URL.
  - `techSheetSignatureRelativePath(int $techSheetId, string $field): string` — `uploads/tech-sheets/{id}/{field}.png`.
  - `techSheetWriteSignature(string $baseDir, int $techSheetId, string $field, string $binary): ?string` — writes the file, returns the relative path or `null`; `$field` must be `entrant`, `driver` or `tech`.
  - `techSheetSaveSignature(string $baseDir, int $techSheetId, string $field, string $dataUrl): ?string` — decode then write.
  - `techSheetDeleteSignature(string $baseDir, ?string $relativePath): void`

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/TechSheetFilesTest.php`:

```php
<?php
// wcma-calculator/tests/TechSheetFilesTest.php
require_once __DIR__ . '/../tech-sheet-files.php';

use PHPUnit\Framework\TestCase;

final class TechSheetFilesTest extends TestCase
{
    private string $dir;
    private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wcma_tsf_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
        rmdir($this->dir);
    }

    private function dataUrl(): string { return 'data:image/png;base64,' . self::PNG_B64; }

    public function testDecodeAcceptsPngDataUrlOnly(): void
    {
        $bytes = techSheetDecodeSignature($this->dataUrl());
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($bytes, 0, 8));

        $this->assertNull(techSheetDecodeSignature('data:image/jpeg;base64,' . self::PNG_B64));
        $this->assertNull(techSheetDecodeSignature('not a data url'));
        $this->assertNull(techSheetDecodeSignature('data:image/png;base64,' . base64_encode('plain text, not a png')));
        $this->assertNull(techSheetDecodeSignature(''));
    }

    public function testRelativePath(): void
    {
        $this->assertSame('uploads/tech-sheets/12/tech.png', techSheetSignatureRelativePath(12, 'tech'));
    }

    public function testSaveWritesFileAndReturnsRelativePath(): void
    {
        $rel = techSheetSaveSignature($this->dir, 7, 'entrant', $this->dataUrl());
        $this->assertSame('uploads/tech-sheets/7/entrant.png', $rel);
        $this->assertFileExists($this->dir . '/' . $rel);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr(file_get_contents($this->dir . '/' . $rel), 0, 8));
    }

    public function testSaveRejectsBadInputAndUnknownFields(): void
    {
        $this->assertNull(techSheetSaveSignature($this->dir, 7, 'entrant', 'garbage'));
        $this->assertNull(techSheetSaveSignature($this->dir, 7, '../evil', $this->dataUrl()));
        $this->assertNull(techSheetSaveSignature($this->dir, 7, 'passenger', $this->dataUrl()));
        $this->assertDirectoryDoesNotExist($this->dir . '/uploads/tech-sheets/7');
    }

    public function testWriteThenDelete(): void
    {
        $rel = techSheetWriteSignature($this->dir, 9, 'tech', techSheetDecodeSignature($this->dataUrl()));
        $this->assertFileExists($this->dir . '/' . $rel);

        techSheetDeleteSignature($this->dir, $rel);
        $this->assertFileDoesNotExist($this->dir . '/' . $rel);

        techSheetDeleteSignature($this->dir, $rel);   // already gone: no error
        techSheetDeleteSignature($this->dir, null);
        techSheetDeleteSignature($this->dir, '');
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter TechSheetFilesTest`
Expected: fatal error, `tech-sheet-files.php` not found.

- [ ] **Step 3: Write the helper**

Create `wcma-calculator/tech-sheet-files.php`:

```php
<?php
// wcma-calculator/tech-sheet-files.php
//
// Reading and writing tech sheet signature PNGs (drawn on a canvas, sent as data URLs).
// Shared by the competitor form (tech-sheets.php) and the inspector review (admin).

const TECH_SHEET_SIGNATURE_FIELDS = ['entrant', 'driver', 'tech'];

/** The PNG bytes inside a `data:image/png;base64,...` URL, or null if it is not a valid PNG data URL. */
function techSheetDecodeSignature(string $dataUrl): ?string {
    $prefix = 'data:image/png;base64,';
    if (strpos($dataUrl, $prefix) !== 0) return null;
    $binary = base64_decode(substr($dataUrl, strlen($prefix)));
    if ($binary === false || substr($binary, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;
    return $binary;
}

function techSheetSignatureRelativePath(int $techSheetId, string $field): string {
    return 'uploads/tech-sheets/' . $techSheetId . '/' . $field . '.png';
}

/** Writes PNG bytes for a sheet's signature; returns the relative path, or null on a bad field or write failure. */
function techSheetWriteSignature(string $baseDir, int $techSheetId, string $field, string $binary): ?string {
    if (!in_array($field, TECH_SHEET_SIGNATURE_FIELDS, true)) return null;
    $relative = techSheetSignatureRelativePath($techSheetId, $field);
    $dir = dirname($baseDir . '/' . $relative);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) return null;
    if (file_put_contents($baseDir . '/' . $relative, $binary) === false) return null;
    return $relative;
}

/** Decode a data URL and write it. Null if the data URL is not a valid PNG or the field is unknown. */
function techSheetSaveSignature(string $baseDir, int $techSheetId, string $field, string $dataUrl): ?string {
    if (!in_array($field, TECH_SHEET_SIGNATURE_FIELDS, true)) return null;
    $binary = techSheetDecodeSignature($dataUrl);
    if ($binary === null) return null;
    return techSheetWriteSignature($baseDir, $techSheetId, $field, $binary);
}

function techSheetDeleteSignature(string $baseDir, ?string $relativePath): void {
    if ($relativePath === null || $relativePath === '') return;
    $abs = $baseDir . '/' . $relativePath;
    if (is_file($abs)) unlink($abs);
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar --filter TechSheetFilesTest`
Expected: `OK (5 tests, ...)`.

- [ ] **Step 5: Switch `tech-sheets.php` to the shared helper**

In `wcma-calculator/tech-sheets.php`:

1. Add after the existing `require __DIR__ . '/email-helpers.php';` line:
```php
require __DIR__ . '/tech-sheet-files.php';
```
2. Delete the whole `saveSignatureFile()` function (from `function saveSignatureFile(int $techSheetId, string $field, string $dataUrl): ?string {` through its closing `}`).
3. Replace each of the four call sites. `saveSignatureFile($id, 'entrant', $_POST['entrant_signature'])` becomes `techSheetSaveSignature(__DIR__, $id, 'entrant', $_POST['entrant_signature'])` and `saveSignatureFile($id, 'driver', $_POST['driver_signature'])` becomes `techSheetSaveSignature(__DIR__, $id, 'driver', $_POST['driver_signature'])` (two occurrences of each: one in `handleSubmit`, one in `handleUpdate`).

- [ ] **Step 6: Verify nothing references the old function and run the suite**

Run: `grep -n "saveSignatureFile" tech-sheets.php` (expect no output), `php -l tech-sheets.php` (expect `No syntax errors detected`), then `php phpunit.phar` (expect `OK`).

- [ ] **Step 7: Commit**

```bash
git add wcma-calculator/tech-sheet-files.php wcma-calculator/tech-sheets.php wcma-calculator/tests/TechSheetFilesTest.php
git commit -m "refactor(tech-sheets): move signature file handling into a shared helper"
```

---

### Task 4: Review library (accept and revoke)

**Files:**
- Create: `wcma-calculator/tech-review-lib.php`
- Test: `wcma-calculator/tests/TechReviewLibTest.php`

**Interfaces:**
- Consumes: `db_get_tech_sheet()`, `db_accept_tech_sheet_in_person()`, `db_revoke_tech_sheet_acceptance()` (Task 2); `techSheetDecodeSignature()`, `techSheetSignatureRelativePath()`, `techSheetWriteSignature()`, `techSheetDeleteSignature()` (Task 3).
- Produces:
  - `techReviewAcceptInPerson(PDO $pdo, string $baseDir, int $sheetId, int $reviewerUserId, string $signatureDataUrl): array{ok: bool, error: ?string}`
  - `techReviewRevoke(PDO $pdo, string $baseDir, int $sheetId): array{ok: bool, error: ?string}`

Order matters in accept: validate the signature, then do the atomic database update, then write the file. This way a second inspector who loses the race never overwrites the winner's signature image.

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/TechReviewLibTest.php`:

```php
<?php
// wcma-calculator/tests/TechReviewLibTest.php
require_once __DIR__ . '/../tech-sheet-files.php';
require_once __DIR__ . '/../tech-review-lib.php';

use PHPUnit\Framework\TestCase;

final class TechReviewLibTest extends TestCase
{
    private string $dir;
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wcma_trl_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
        rmdir($this->dir);
    }

    private function makeSheet(PDO $pdo): array {
        $userId = db_create_user($pdo, ['email' => 'racer@example.com', 'name' => 'Racer', 'password_hash' => 'x', 'google_id' => null]);
        $adminId = db_create_user($pdo, ['email' => 'tech@example.com', 'name' => 'Tech', 'password_hash' => 'x', 'google_id' => null]);
        $subId = db_insert_submission($pdo, [
            ':submitted_at' => date('Y-m-d H:i:s'), ':name' => 'Racer', ':email' => 'racer@example.com',
            ':year' => '2020', ':make' => 'Mazda', ':model' => 'MX-5', ':comments' => null,
            ':competition_weight' => 2200, ':declared_hp' => 150, ':dyno_hp' => null,
            ':chassis_display' => null, ':body_mods_display' => null, ':transmission_display' => null,
            ':drivetrain_display' => null, ':tires_display' => null, ':brake_suspension' => null,
            ':chassis_value' => 0, ':body_mods_value' => 0, ':transmission_value' => 0,
            ':drivetrain_value' => 0, ':tires_value' => 0, ':brake_suspension_value' => 0,
            ':weight_factor' => 0, ':modification_factor' => 0, ':base_ratio' => 14.67, ':modified_ratio' => 14.67,
            ':calculated_class' => 'IT1', ':user_id' => $userId,
        ]);
        $eventId = db_create_event($pdo, 'Spring Sprint', '2026-05-10', null);
        $sheetId = db_insert_tech_sheet($pdo, [
            'submission_id' => $subId, 'user_id' => $userId, 'event_id' => $eventId, 'sheet_type' => 'standard',
            'entrant_name' => 'Racer', 'driver_name' => 'Racer', 'car_make' => 'Mazda', 'car_model' => 'MX-5',
            'car_colour' => 'Red', 'car_number' => '42', 'class' => 'IT1', 'engine_cc' => '1800', 'engine_hp' => '150',
            'car_weight' => 2200, 'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1,
        ]);
        return [$sheetId, $adminId];
    }

    public function testAcceptStoresSignatureAndMarksSheetTeched(): void
    {
        $pdo = make_temp_pdo();
        [$sheetId, $adminId] = $this->makeSheet($pdo);

        $r = techReviewAcceptInPerson($pdo, $this->dir, $sheetId, $adminId, self::PNG);

        $this->assertTrue($r['ok'], (string)$r['error']);
        $row = db_get_tech_sheet($pdo, $sheetId);
        $this->assertSame('teched', $row['status']);
        $this->assertSame('in_person', $row['accepted_via']);
        $this->assertSame($adminId, (int)$row['reviewed_by_user_id']);
        $this->assertSame("uploads/tech-sheets/$sheetId/tech.png", $row['tech_signature_path']);
        $this->assertFileExists($this->dir . '/' . $row['tech_signature_path']);
    }

    public function testAcceptRequiresAValidSignatureAndChangesNothingWithout(): void
    {
        $pdo = make_temp_pdo();
        [$sheetId, $adminId] = $this->makeSheet($pdo);

        foreach (['', 'garbage', 'data:image/jpeg;base64,AAAA'] as $bad) {
            $r = techReviewAcceptInPerson($pdo, $this->dir, $sheetId, $adminId, $bad);
            $this->assertFalse($r['ok']);
            $this->assertStringContainsString('signature', $r['error']);
        }
        $this->assertSame('submitted', db_get_tech_sheet($pdo, $sheetId)['status']);
    }

    public function testAcceptRejectsMissingAndAlreadyAcceptedSheets(): void
    {
        $pdo = make_temp_pdo();
        [$sheetId, $adminId] = $this->makeSheet($pdo);

        $this->assertFalse(techReviewAcceptInPerson($pdo, $this->dir, 99999, $adminId, self::PNG)['ok']);

        $this->assertTrue(techReviewAcceptInPerson($pdo, $this->dir, $sheetId, $adminId, self::PNG)['ok']);
        $firstBytes = file_get_contents($this->dir . "/uploads/tech-sheets/$sheetId/tech.png");

        $second = techReviewAcceptInPerson($pdo, $this->dir, $sheetId, $adminId, self::PNG);
        $this->assertFalse($second['ok']);
        $this->assertStringContainsString('already', $second['error']);
        $this->assertSame($firstBytes, file_get_contents($this->dir . "/uploads/tech-sheets/$sheetId/tech.png"));
    }

    public function testRevokeReturnsSheetToSubmittedAndRemovesSignatureFile(): void
    {
        $pdo = make_temp_pdo();
        [$sheetId, $adminId] = $this->makeSheet($pdo);
        techReviewAcceptInPerson($pdo, $this->dir, $sheetId, $adminId, self::PNG);
        $file = $this->dir . "/uploads/tech-sheets/$sheetId/tech.png";
        $this->assertFileExists($file);

        $r = techReviewRevoke($pdo, $this->dir, $sheetId);

        $this->assertTrue($r['ok'], (string)$r['error']);
        $this->assertSame('submitted', db_get_tech_sheet($pdo, $sheetId)['status']);
        $this->assertFileDoesNotExist($file);
    }

    public function testRevokeRejectsMissingAndUnacceptedSheets(): void
    {
        $pdo = make_temp_pdo();
        [$sheetId] = $this->makeSheet($pdo);

        $this->assertFalse(techReviewRevoke($pdo, $this->dir, 99999)['ok']);
        $r = techReviewRevoke($pdo, $this->dir, $sheetId);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('not been accepted', $r['error']);
    }

    public function testMessagesAvoidApprovalWording(): void
    {
        $pdo = make_temp_pdo();
        [$sheetId, $adminId] = $this->makeSheet($pdo);
        $messages = [
            techReviewAcceptInPerson($pdo, $this->dir, 99999, $adminId, self::PNG)['error'],
            techReviewAcceptInPerson($pdo, $this->dir, $sheetId, $adminId, '')['error'],
            techReviewRevoke($pdo, $this->dir, $sheetId)['error'],
        ];
        foreach ($messages as $m) {
            $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', $m);
        }
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter TechReviewLibTest`
Expected: fatal error, `tech-review-lib.php` not found.

- [ ] **Step 3: Write the library**

Create `wcma-calculator/tech-review-lib.php`:

```php
<?php
// wcma-calculator/tech-review-lib.php
//
// Inspector review actions on a tech sheet. Session-free (the admin handler injects the
// reviewer id and base directory) so it is unit-testable. Callers must have loaded db.php
// and tech-sheet-files.php.

/**
 * Accept a submitted sheet in person: the inspector's canvas signature is required.
 * Order: validate signature -> atomic DB update -> write the file. A second inspector who
 * loses the race is refused before any file is written, so the winner's signature stays intact.
 *
 * @return array{ok: bool, error: ?string}
 */
function techReviewAcceptInPerson(PDO $pdo, string $baseDir, int $sheetId, int $reviewerUserId, string $signatureDataUrl): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg];

    $sheet = db_get_tech_sheet($pdo, $sheetId);
    if ($sheet === null) return $fail('Tech sheet not found.');
    if ($sheet['status'] === 'teched') return $fail('This sheet has already been accepted.');

    $binary = techSheetDecodeSignature($signatureDataUrl);
    if ($binary === null) return $fail('A tech representative signature is required.');

    $relative = techSheetSignatureRelativePath($sheetId, 'tech');
    if (!db_accept_tech_sheet_in_person($pdo, $sheetId, $reviewerUserId, $relative)) {
        return $fail('This sheet has already been accepted.');
    }

    if (techSheetWriteSignature($baseDir, $sheetId, 'tech', $binary) === null) {
        db_revoke_tech_sheet_acceptance($pdo, $sheetId);
        return $fail('Could not save the signature. Please try again.');
    }
    return ['ok' => true, 'error' => null];
}

/**
 * Undo an acceptance (for example an inspector accepted the wrong car): the sheet returns to
 * 'submitted' and its inspector signature is removed.
 *
 * @return array{ok: bool, error: ?string}
 */
function techReviewRevoke(PDO $pdo, string $baseDir, int $sheetId): array {
    $sheet = db_get_tech_sheet($pdo, $sheetId);
    if ($sheet === null) return ['ok' => false, 'error' => 'Tech sheet not found.'];

    $signaturePath = $sheet['tech_signature_path'] ?? null;
    if (!db_revoke_tech_sheet_acceptance($pdo, $sheetId)) {
        return ['ok' => false, 'error' => 'This sheet has not been accepted.'];
    }
    techSheetDeleteSignature($baseDir, $signaturePath);
    return ['ok' => true, 'error' => null];
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar`
Expected: `OK` for the whole suite, including `TechReviewLibTest` (6 tests).

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/tech-review-lib.php wcma-calculator/tests/TechReviewLibTest.php
git commit -m "feat(tech-status): add in-person accept and revoke review actions"
```

---

### Task 5: Admin tech sheets roster

**Files:**
- Create: `wcma-calculator/admin-tech-sheets.php` (roster part; Task 6 adds the review page to the same file)
- Modify: `wcma-calculator/admin.php` (require, router case, nav link on four pages)
- Modify: `wcma-calculator/admin-feedback.php` (nav link)

**Interfaces:**
- Consumes: `db_get_all_events()`, `db_get_event_tech_sheets()`, `db_get_season_sheets()` (Task 2); `techBuildRoster()`, `techRosterFilter()`, `techDefaultEventId()`, `techCarStatusLabel()`, `techCarStatusBadgeClass()` (Task 1); existing `requireAuth()`, `renderSiteHeader()`, `renderCommonNav()`, `getFlash()`, `h()`.
- Produces:
  - `TECH_SHEET_FILTERS` const `['all' => 'All sheets', 'needs_tech' => 'Needs tech at the track', 'accepted' => 'Accepted']`
  - `handleTechSheetsList(PDO $pdo): void` and `renderTechSheetsListPage(array $events, int $eventId, string $filter, array $rows, array $counts, ?array $flash): void`
  - `ADMIN_TECH_NAV` const (nav links for the new pages); the four existing admin pages and the feedback page get a "Tech Sheets" link by inline edits (Step 3).
  - Route `admin.php?action=tech-sheets[&event=ID&filter=needs_tech]`.
  - Each roster row links to `admin.php?action=tech-sheet&id=ID` (built in Task 6).

The roster lists sheets, so it is desktop-oriented like the other admin lists. It is verified end to end in Task 8 (admin pages need `config.php`).

- [ ] **Step 1: Create the module with the roster handler and page**

Create `wcma-calculator/admin-tech-sheets.php`:

```php
<?php
// wcma-calculator/admin-tech-sheets.php
//
// Admin "Tech Sheets" tab: event roster (who still needs tech at the track) and the
// in-person review page. Included by admin.php, which provides requireAuth(), the router
// and the CSRF/POST checks.

const TECH_SHEET_FILTERS = [
    'all' => 'All sheets',
    'needs_tech' => 'Needs tech at the track',
    'accepted' => 'Accepted',
];

const ADMIN_TECH_NAV = '<a href="admin.php">Submissions</a> <a href="admin.php?action=users">Manage Users</a> <a href="admin.php?action=events">Events</a> <a href="admin.php?action=settings">Settings</a> <a href="admin.php?action=feedback">Feedback</a>';

function handleTechSheetsList(PDO $pdo): void {
    $events = db_get_all_events($pdo);
    $eventId = isset($_GET['event']) ? max(0, (int)$_GET['event']) : techDefaultEventId($events, date('Y-m-d'));
    $filter = (string)($_GET['filter'] ?? 'all');
    if (!isset(TECH_SHEET_FILTERS[$filter])) $filter = 'all';

    $eventSheets = db_get_event_tech_sheets($pdo, $eventId);
    $seasonSheets = [];
    foreach (array_unique(array_map(fn(array $s): int => (int)$s['season'], $eventSheets)) as $season) {
        $seasonSheets = array_merge($seasonSheets, db_get_season_sheets($pdo, $season));
    }
    $rows = techBuildRoster($eventSheets, $seasonSheets);

    $counts = [
        'all' => count($rows),
        'needs_tech' => count(techRosterFilter($rows, 'needs_tech')),
        'accepted' => count(techRosterFilter($rows, 'accepted')),
    ];
    renderTechSheetsListPage($events, $eventId, $filter, techRosterFilter($rows, $filter), $counts, getFlash());
}

function renderTechSheetsListPage(array $events, int $eventId, string $filter, array $rows, array $counts, ?array $flash): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tech Sheets — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Tech Sheets', ADMIN_TECH_NAV . renderCommonNav('admin')); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

  <form method="get" action="admin.php" class="detail-card" style="margin-bottom:1rem">
    <input type="hidden" name="action" value="tech-sheets">
    <label for="tech-event">Event</label>
    <select id="tech-event" name="event">
      <option value="0"<?= $eventId === 0 ? ' selected' : '' ?>>All events</option>
      <?php foreach ($events as $e): ?>
      <option value="<?= (int)$e['id'] ?>"<?= (int)$e['id'] === $eventId ? ' selected' : '' ?>><?= h($e['name']) ?> (<?= h(date('M j, Y', strtotime($e['event_date']))) ?>)</option>
      <?php endforeach; ?>
    </select>
    <label for="tech-filter">Show</label>
    <select id="tech-filter" name="filter">
      <?php foreach (TECH_SHEET_FILTERS as $value => $label): ?>
      <option value="<?= h($value) ?>"<?= $value === $filter ? ' selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">Apply</button>
    <p class="form-hint" style="margin-top:.5rem"><?= (int)$counts['all'] ?> sheets: <?= (int)$counts['accepted'] ?> accepted, <?= (int)$counts['needs_tech'] ?> still need tech at the track.</p>
  </form>

  <table class="data-table" id="tech-sheets-table">
    <thead><tr><th>Car #</th><th>Vehicle</th><th>Entrant</th><th>Class</th><th>Event</th><th>Car status</th><th>Sheet</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (empty($rows)): ?>
      <tr><td colspan="8" class="empty-row">No tech sheets match.</td></tr>
    <?php else: foreach ($rows as $row): $s = $row['sheet']; $st = $row['status']; ?>
      <tr>
        <td><?= h($s['car_number']) ?></td>
        <td><?= h(trim($s['car_make'] . ' ' . $s['car_model'])) ?></td>
        <td><?= h($s['entrant_name']) ?></td>
        <td><?= h($s['class']) ?></td>
        <td><?= h($s['event_name'] ?? '—') ?></td>
        <td class="<?= h(techCarStatusBadgeClass($st['state'])) ?>"><?= h(techCarStatusLabel($st, (int)$s['season'])) ?></td>
        <td><?= $s['status'] === 'teched' ? 'Reviewed' : 'Submitted ' . h(date('M j', strtotime($s['created_at']))) ?></td>
        <td class="actions"><a href="admin.php?action=tech-sheet&id=<?= (int)$s['id'] ?>"><?= $s['status'] === 'teched' ? 'View' : 'Review' ?></a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
</body>
</html><?php
}
```

- [ ] **Step 2: Require the module and add the route**

In `wcma-calculator/admin.php`, after the line `require __DIR__ . '/admin-feedback.php';`, add:

```php
require __DIR__ . '/admin-tech-sheets.php';
```

In the router `switch ($action)`, add this case immediately before `case 'settings':`:

```php
    case 'tech-sheets':
        requireAuth();
        handleTechSheetsList($pdo);
        break;

```

- [ ] **Step 3: Add the "Tech Sheets" link to the other admin pages**

In `admin.php`, change the four `renderSiteHeader(...)` nav strings (each is unique):

- Submissions page: `'<a href="admin.php?action=users">Manage Users</a> <a href="admin.php?action=events">Events</a> <a href="admin.php?action=settings">Settings</a> <a href="admin.php?action=feedback">Feedback</a>'` becomes `'<a href="admin.php?action=tech-sheets">Tech Sheets</a> <a href="admin.php?action=users">Manage Users</a> <a href="admin.php?action=events">Events</a> <a href="admin.php?action=settings">Settings</a> <a href="admin.php?action=feedback">Feedback</a>'`
- Users page: `'<a href="admin.php">Submissions</a> <a href="admin.php?action=events">Events</a> <a href="admin.php?action=settings">Settings</a> <a href="admin.php?action=feedback">Feedback</a>'` becomes `'<a href="admin.php">Submissions</a> <a href="admin.php?action=tech-sheets">Tech Sheets</a> <a href="admin.php?action=events">Events</a> <a href="admin.php?action=settings">Settings</a> <a href="admin.php?action=feedback">Feedback</a>'`
- Events page: `'<a href="admin.php">Submissions</a> <a href="admin.php?action=users">Manage Users</a> <a href="admin.php?action=settings">Settings</a> <a href="admin.php?action=feedback">Feedback</a>'` becomes `'<a href="admin.php">Submissions</a> <a href="admin.php?action=tech-sheets">Tech Sheets</a> <a href="admin.php?action=users">Manage Users</a> <a href="admin.php?action=settings">Settings</a> <a href="admin.php?action=feedback">Feedback</a>'`
- Settings page: `'<a href="admin.php">Submissions</a> <a href="admin.php?action=users">Manage Users</a> <a href="admin.php?action=events">Events</a> <a href="admin.php?action=feedback">Feedback</a>'` becomes `'<a href="admin.php">Submissions</a> <a href="admin.php?action=tech-sheets">Tech Sheets</a> <a href="admin.php?action=users">Manage Users</a> <a href="admin.php?action=events">Events</a> <a href="admin.php?action=feedback">Feedback</a>'`

In `admin-feedback.php`, change the `FEEDBACK_ADMIN_NAV` constant to:

```php
const FEEDBACK_ADMIN_NAV = '<a href="admin.php">Submissions</a> <a href="admin.php?action=tech-sheets">Tech Sheets</a> <a href="admin.php?action=users">Manage Users</a> <a href="admin.php?action=events">Events</a> <a href="admin.php?action=settings">Settings</a>';
```

- [ ] **Step 4: Lint and run the suite**

Run: `php -l admin-tech-sheets.php && php -l admin.php && php -l admin-feedback.php && php phpunit.phar`
Expected: three `No syntax errors detected` lines, then `OK` for the suite. (The page itself is exercised in Task 8.)

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/admin-tech-sheets.php wcma-calculator/admin.php wcma-calculator/admin-feedback.php
git commit -m "feat(tech-status): add admin tech sheets roster with needs-tech filter"
```

---

### Task 6: Admin review page (accept, revoke, signature)

**Files:**
- Modify: `wcma-calculator/admin-tech-sheets.php` (add handlers and the review page)
- Create: `wcma-calculator/js/admin-tech-review.js`
- Modify: `wcma-calculator/admin.php` (require the two libraries; add four router cases)

**Interfaces:**
- Consumes: `techReviewAcceptInPerson()`, `techReviewRevoke()` (Task 4); `db_get_tech_sheet()`, `db_get_event()`, `db_get_tech_sheet_drivers()`, `db_get_identity_sheets()`, `db_find_user_by_id()`; `techCarStatus()`, `techCarStatusLabel()`, `techCarStatusBadgeClass()`; existing `renderTechSheetHtml()`, `WcmaSignaturePad` (`js/signature-pad.js`), `requireAuth()`, `validateCsrfToken()`, `generateCsrfToken()`.
- Produces:
  - Routes: `GET admin.php?action=tech-sheet&id=N` (review page), `POST admin.php?action=tech-sheet-accept` (fields `csrf_token`, `id`, `tech_signature`), `POST admin.php?action=tech-sheet-revoke` (`csrf_token`, `id`), `GET admin.php?action=tech-sheet-sig&id=N&which=entrant|driver|tech` (PNG).
  - `handleTechSheetView(PDO $pdo, int $id)`, `handleTechSheetAccept(PDO $pdo, int $id)`, `handleTechSheetRevoke(PDO $pdo, int $id)`, `handleTechSheetSig(PDO $pdo, int $id, string $which)`, `renderTechSheetViewPage(array $sheet, array $drivers, array $event, array $carStatus, ?array $reviewer, string $csrf, ?array $flash)`, `adminTechSheetSigResolver(int $id): callable`.

- [ ] **Step 1: Add the handlers and page to `admin-tech-sheets.php`**

Append to `wcma-calculator/admin-tech-sheets.php`:

```php

function handleTechSheetView(PDO $pdo, int $id): void {
    $sheet = db_get_tech_sheet($pdo, $id);
    if ($sheet === null) {
        setFlash('Tech sheet not found.', 'error');
        header('Location: admin.php?action=tech-sheets');
        exit;
    }
    $event = db_get_event($pdo, (int)$sheet['event_id']) ?? [];
    $drivers = db_get_tech_sheet_drivers($pdo, $id);
    $carStatus = techCarStatus(db_get_identity_sheets($pdo, (int)$sheet['user_id'], (string)$sheet['car_number_norm'], (int)$sheet['season']));
    $reviewer = !empty($sheet['reviewed_by_user_id']) ? db_find_user_by_id($pdo, (int)$sheet['reviewed_by_user_id']) : null;
    renderTechSheetViewPage($sheet, $drivers, $event, $carStatus, $reviewer, generateCsrfToken(), getFlash());
}

function handleTechSheetAccept(PDO $pdo, int $id): void {
    $user = current_user();
    $result = techReviewAcceptInPerson($pdo, __DIR__, $id, (int)$user['id'], (string)($_POST['tech_signature'] ?? ''));
    setFlash($result['ok'] ? 'Sheet accepted (teched in person).' : $result['error'], $result['ok'] ? 'success' : 'error');
    header('Location: admin.php?action=tech-sheet&id=' . $id);
    exit;
}

function handleTechSheetRevoke(PDO $pdo, int $id): void {
    $result = techReviewRevoke($pdo, __DIR__, $id);
    setFlash($result['ok'] ? 'Acceptance revoked. The sheet is back to submitted.' : $result['error'], $result['ok'] ? 'success' : 'error');
    header('Location: admin.php?action=tech-sheet&id=' . $id);
    exit;
}

/** Serves a signature PNG to an admin (uploads/ is Deny-from-all, so it cannot be linked directly). */
function handleTechSheetSig(PDO $pdo, int $id, string $which): void {
    $columns = ['entrant' => 'entrant_signature_path', 'driver' => 'driver_signature_path', 'tech' => 'tech_signature_path'];
    $sheet = isset($columns[$which]) ? db_get_tech_sheet($pdo, $id) : null;
    $path = $sheet[$columns[$which] ?? ''] ?? null;
    $full = $path ? __DIR__ . '/' . $path : null;
    if ($full === null || !is_file($full)) { http_response_code(404); exit; }

    header('Content-Type: image/png');
    header('Content-Length: ' . filesize($full));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($full);
    exit;
}

function adminTechSheetSigResolver(int $techSheetId): callable {
    return function (string $which, string $path) use ($techSheetId): ?string {
        return 'admin.php?action=tech-sheet-sig&id=' . $techSheetId . '&which=' . rawurlencode($which);
    };
}

function renderTechSheetViewPage(array $sheet, array $drivers, array $event, array $carStatus, ?array $reviewer, string $csrf, ?array $flash): void {
    $id = (int)$sheet['id'];
    $accepted = $sheet['status'] === 'teched';
    $statusLabel = techCarStatusLabel($carStatus, (int)$sheet['season']);
    $acceptedLine = '';
    if ($accepted) {
        $how = ($sheet['accepted_via'] ?? 'in_person') === 'photos' ? 'remotely' : 'in person';
        $who = $reviewer ? ' by ' . $reviewer['name'] : '';
        $when = !empty($sheet['reviewed_at']) ? ' on ' . date('M j, Y g:i A', strtotime($sheet['reviewed_at'])) : '';
        $acceptedLine = 'Accepted ' . $how . $who . $when . '.';
    }
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tech Sheet #<?= $id ?> — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Tech Sheet #' . $id, '<a href="admin.php?action=tech-sheets&event=' . (int)$sheet['event_id'] . '">← Back to roster</a>' . renderCommonNav('admin')); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

  <div class="detail-card">
    <h2>Tech review</h2>
    <p>Car #<?= h($sheet['car_number']) ?> — <?= h(trim($sheet['car_make'] . ' ' . $sheet['car_model'])) ?> (<?= h($sheet['entrant_name']) ?>)</p>
    <p>Car status: <strong class="<?= h(techCarStatusBadgeClass($carStatus['state'])) ?>"><?= h($statusLabel) ?></strong></p>

    <?php if ($accepted): ?>
    <p><?= h($acceptedLine) ?></p>
    <form method="post" action="admin.php?action=tech-sheet-revoke" data-confirm="Revoke this acceptance? The sheet goes back to submitted and the inspector signature is removed.">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button type="submit" class="btn btn-secondary">Revoke acceptance</button>
    </form>
    <?php else: ?>
    <p class="form-hint">Accepting records that what the competitor submitted matches the car in front of you. It is not a certification that the vehicle is safe.</p>
    <form method="post" action="admin.php?action=tech-sheet-accept" id="tech-accept-form">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="tech_signature" id="tech_signature">
      <label>Tech representative signature</label>
      <div class="sig-pad-wrap"><canvas id="tech-sig-canvas"></canvas></div>
      <div class="sig-pad-actions"><button type="button" class="link-button" data-clear-sig="tech">Clear</button></div>
      <button type="submit" class="btn btn-primary" style="margin-top:.75rem">Accept — teched in person</button>
    </form>
    <?php endif; ?>
  </div>

  <?= renderTechSheetHtml($sheet, $drivers, $event, adminTechSheetSigResolver($id), 'assets/wcma-logo.png') ?>
</div>
<script src="js/confirm-modal.js"></script>
<script src="js/form-feedback.js"></script>
<script src="js/signature-pad.js"></script>
<script src="js/admin-tech-review.js"></script>
</body>
</html><?php
}
```

- [ ] **Step 2: Add the signature-pad wiring**

Create `wcma-calculator/js/admin-tech-review.js`:

```js
// wcma-calculator/js/admin-tech-review.js
// Signature pad for the in-person tech review form (admin.php?action=tech-sheet).
(function () {
    const canvas = document.getElementById('tech-sig-canvas');
    if (!canvas) return; // accepted sheets have no form

    const pad = WcmaSignaturePad.attach(canvas);
    const form = document.getElementById('tech-accept-form');
    const field = document.getElementById('tech_signature');
    const wrap = canvas.closest('.sig-pad-wrap');

    document.querySelector('[data-clear-sig="tech"]').addEventListener('click', function () {
        pad.clear();
    });

    form.addEventListener('submit', function (event) {
        if (pad.isEmpty()) {
            event.preventDefault();
            wrap.classList.add('field-error');
            return;
        }
        wrap.classList.remove('field-error');
        field.value = pad.toPNGDataURL();
    });
})();
```

- [ ] **Step 3: Load the libraries and add the routes in `admin.php`**

After the `require __DIR__ . '/admin-tech-sheets.php';` line from Task 5, add:

```php
require __DIR__ . '/tech-sheet-files.php';
require __DIR__ . '/tech-review-lib.php';
require __DIR__ . '/tech-sheet-render.php';
```
(`tech-sheet-render.php` loads `tech-sheet-data.php` itself with `require_once`.)

In the router, extend the `tech-sheets` case block from Task 5 by adding these cases right after it:

```php
    case 'tech-sheet':
        requireAuth();
        handleTechSheetView($pdo, (int)($_GET['id'] ?? 0));
        break;

    case 'tech-sheet-accept':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=tech-sheets'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleTechSheetAccept($pdo, (int)($_POST['id'] ?? 0));
        break;

    case 'tech-sheet-revoke':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=tech-sheets'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleTechSheetRevoke($pdo, (int)($_POST['id'] ?? 0));
        break;

    case 'tech-sheet-sig':
        requireAuth();
        handleTechSheetSig($pdo, (int)($_GET['id'] ?? 0), (string)($_GET['which'] ?? ''));
        break;

```

- [ ] **Step 4: Lint, syntax-check and run the suite**

Run: `php -l admin-tech-sheets.php && php -l admin.php && node --check js/admin-tech-review.js && php phpunit.phar`
Expected: `No syntax errors detected` twice, no output from `node --check`, then `OK` for the suite. (The pages are exercised in Task 8.)

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/admin-tech-sheets.php wcma-calculator/admin.php wcma-calculator/js/admin-tech-review.js
git commit -m "feat(tech-status): add in-person review page with signature, accept and revoke"
```

---

### Task 7: Competitor-facing status and rendered-sheet wording

**Files:**
- Modify: `wcma-calculator/tech-sheet-data.php` (disclaimer constant)
- Modify: `wcma-calculator/tech-sheet-render.php` (status line + disclaimer)
- Modify: `wcma-calculator/account.php` (My Cars status)
- Modify: `wcma-calculator/tech-sheets.php` (view page status)
- Test: `wcma-calculator/tests/TechSheetRenderTest.php`

**Interfaces:**
- Consumes: `techCarStatusForSheet()`, `techCarStatusLabel()`, `techCarStatusBadgeClass()` (Task 1); existing `db_get_user_tech_sheets()`.
- Produces: `const TECH_ACCEPTANCE_DISCLAIMER` (exact text from the Global Constraints); the rendered sheet's status line reads `Reviewed in person on <date>` or `Reviewed remotely on <date>` for accepted sheets and `Submitted — awaiting review` otherwise, followed by the disclaimer only on accepted sheets.

- [ ] **Step 1: Write the failing render tests**

Add to `wcma-calculator/tests/TechSheetRenderTest.php`, inside the class:

```php
    public function testAcceptedSheetShowsHowAndWhenAndTheDisclaimer(): void
    {
        require_once __DIR__ . '/../tech-sheet-render.php';
        $sheet = $this->sampleSheet();
        $sheet['status'] = 'teched';
        $sheet['accepted_via'] = 'in_person';
        $sheet['reviewed_at'] = '2026-05-10 09:30:00';
        $html = renderTechSheetHtml($sheet, [], ['name' => 'Spring Sprint', 'event_date' => '2026-05-10']);
        $this->assertStringContainsString('Reviewed in person on May 10, 2026', $html);
        $this->assertStringContainsString('It is not a certification that the vehicle or equipment is safe.', $html);

        $sheet['accepted_via'] = 'photos';
        $remote = renderTechSheetHtml($sheet, [], ['name' => 'Spring Sprint', 'event_date' => '2026-05-10']);
        $this->assertStringContainsString('Reviewed remotely on May 10, 2026', $remote);
    }

    public function testLegacyAcceptedSheetWithoutViaIsInPerson(): void
    {
        require_once __DIR__ . '/../tech-sheet-render.php';
        $sheet = $this->sampleSheet();
        $sheet['status'] = 'teched';
        $html = renderTechSheetHtml($sheet, [], ['name' => 'Spring Sprint', 'event_date' => '2026-05-10']);
        $this->assertStringContainsString('Reviewed in person', $html);
    }

    public function testSubmittedSheetShowsNoDisclaimer(): void
    {
        require_once __DIR__ . '/../tech-sheet-render.php';
        $html = renderTechSheetHtml($this->sampleSheet(), [], ['name' => 'Spring Sprint', 'event_date' => '2026-05-10']);
        $this->assertStringContainsString('Submitted — awaiting review', $html);
        $this->assertStringNotContainsString('not a certification', $html);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter TechSheetRenderTest`
Expected: the three new tests fail (the status line still says only "Reviewed").

- [ ] **Step 3: Add the disclaimer constant**

In `wcma-calculator/tech-sheet-data.php`, after the `TECH_DRIVER_EQUIPMENT_ITEMS` constant, add:

```php

const TECH_ACCEPTANCE_DISCLAIMER = 'Acceptance confirms that what you submitted matches what was reviewed. It is not a certification that the vehicle or equipment is safe.';
```

- [ ] **Step 4: Update the rendered status line**

In `wcma-calculator/tech-sheet-render.php`, replace this line in `renderTechSheetHtml`:

```php
    $out .= '<p style="font-weight:bold;color:' . (($sheet['status'] ?? 'submitted') === 'teched' ? '#27ae60' : '#f39c12') . '">Status: ' . h(($sheet['status'] ?? 'submitted') === 'teched' ? 'Reviewed' : 'Submitted — awaiting review') . '</p>';
```

with:

```php
    $reviewed = ($sheet['status'] ?? 'submitted') === 'teched';
    if ($reviewed) {
        $how = ($sheet['accepted_via'] ?? 'in_person') === 'photos' ? 'remotely' : 'in person';
        $when = !empty($sheet['reviewed_at']) ? ' on ' . date('F j, Y', strtotime($sheet['reviewed_at'])) : '';
        $statusText = 'Reviewed ' . $how . $when;
    } else {
        $statusText = 'Submitted — awaiting review';
    }
    $out .= '<p style="font-weight:bold;color:' . ($reviewed ? '#27ae60' : '#f39c12') . '">Status: ' . h($statusText) . '</p>';
    if ($reviewed) {
        $out .= '<p style="font-size:0.8rem;color:#555">' . h(TECH_ACCEPTANCE_DISCLAIMER) . '</p>';
    }
```

- [ ] **Step 5: Run the render tests to verify they pass**

Run: `php phpunit.phar --filter TechSheetRenderTest`
Expected: `OK` (all render tests, old and new).

- [ ] **Step 6: Show car status on My Cars**

In `wcma-calculator/account.php`:

1. In `handleAccountList`, after the line `$carGroups = buildCarTechSheetGroups($submissions, $techSheets, $activeEvents, $eventNames);` add:
```php
    $carStatuses = [];
    foreach ($techSheets as $ts) {
        $carStatuses[(int)$ts['id']] = techCarStatusForSheet($ts, $techSheets);
    }
```
and change the call `renderAccountListPage($drafts, $carGroups, $totalCount, $csrf, $flash);` to `renderAccountListPage($drafts, $carGroups, $totalCount, $csrf, $flash, $carStatuses);`.
2. Change the function signature `function renderAccountListPage(array $drafts, array $carGroups, int $count, string $csrf, ?array $flash): void {` to `function renderAccountListPage(array $drafts, array $carGroups, int $count, string $csrf, ?array $flash, array $carStatuses): void {`.
3. Replace the sheet-status span in the per-event line:
```php
            <span class="<?= $sheet['status'] === 'teched' ? 'badge-ok' : 'badge-pending' ?>"><?= $sheet['status'] === 'teched' ? 'submitted, reviewed' : 'submitted' ?></span> —
            <a href="tech-sheets.php?action=view&id=<?= (int)$sheet['id'] ?>">View</a>
```
with:
```php
            <span class="badge-pending">submitted</span>
            <?php $cs = $carStatuses[(int)$sheet['id']] ?? ['state' => 'none', 'via' => null, 'sheet_id' => null]; ?>
            <span class="<?= h(techCarStatusBadgeClass($cs['state'])) ?>"><?= h(techCarStatusLabel($cs, (int)($sheet['season'] ?? date('Y')))) ?></span> —
            <a href="tech-sheets.php?action=view&id=<?= (int)$sheet['id'] ?>">View</a>
```
4. In the "Other Tech Sheets" list, replace:
```php
      <span class="<?= $ts['status'] === 'teched' ? 'badge-ok' : 'badge-pending' ?>"><?= $ts['status'] === 'teched' ? 'submitted, reviewed' : 'submitted' ?></span> —
```
with:
```php
      <?php $cs = $carStatuses[(int)$ts['id']] ?? ['state' => 'none', 'via' => null, 'sheet_id' => null]; ?>
      <span class="badge-pending">submitted</span>
      <span class="<?= h(techCarStatusBadgeClass($cs['state'])) ?>"><?= h(techCarStatusLabel($cs, (int)($ts['season'] ?? date('Y')))) ?></span> —
```
5. `account.php` must load the status module. Since it already requires `db.php` (which requires `tech-status.php`), no extra `require` is needed; confirm with `grep -n "require" account.php`.

- [ ] **Step 7: Show car status on the competitor's sheet page**

In `wcma-calculator/tech-sheets.php`, inside `handleView`, after `$drivers = db_get_tech_sheet_drivers($pdo, $id);` add:

```php
    $carStatus = techCarStatusForSheet($sheet, db_get_user_tech_sheets($pdo, (int)$user['id']));
```

and just above the line `<?= renderTechSheetHtml($sheet, $drivers, $event ?? [], techSheetSignatureResolverWeb((int)$sheet['id']), 'assets/wcma-logo.png') ?>` add:

```php
  <p class="no-print">Car status: <strong class="<?= h(techCarStatusBadgeClass($carStatus['state'])) ?>"><?= h(techCarStatusLabel($carStatus, (int)$sheet['season'])) ?></strong></p>
```

- [ ] **Step 8: Lint and run the full suite**

Run: `php -l account.php && php -l tech-sheets.php && php -l tech-sheet-render.php && php phpunit.phar`
Expected: three `No syntax errors detected` lines, then `OK`.

- [ ] **Step 9: Commit**

```bash
git add wcma-calculator/tech-sheet-data.php wcma-calculator/tech-sheet-render.php wcma-calculator/account.php wcma-calculator/tech-sheets.php wcma-calculator/tests/TechSheetRenderTest.php
git commit -m "feat(tech-status): show derived car tech status to competitors and on the rendered sheet"
```

---

### Task 8: End-to-end verification

No product code changes; no commits. This proves the admin roster, review page, signature, accept, revoke and the competitor-facing status work together in a real browser. The harness lives in `scratch/` (untracked).

**Important, learned in phase 1:** the harness and the served scripts must use the same scratch database and NEVER the default `wcma-calculator/data/submissions.db`, which holds real local data. Every scratch entry point loads `scratch/tech2a-prepend.php` first, the server is started with the absolute path to that file as `auto_prepend_file`, and Step 6 verifies the default database row counts are unchanged.

**Files:**
- Create (scratch, untracked): `scratch/tech2a-prepend.php`, `scratch/tech2a-router.php`, `scratch/tech2a-harness.php`, `scratch/tech2a-e2e.js`

**Interfaces:**
- Consumes: everything above; Playwright at `scratch/tech-sheet-mockups/node_modules`; `wcma-calculator/config.php` (present locally).

- [ ] **Step 1: Record the default database state**

Run (from repo root): `php -r '$p=new PDO("sqlite:wcma-calculator/data/submissions.db"); foreach(["users","submissions","tech_sheets","events"] as $t) echo $t,"=",$p->query("SELECT COUNT(*) FROM $t")->fetchColumn(),"\n";'`
Note the four counts; Step 6 compares against them.

- [ ] **Step 2: Create the harness files**

Create `scratch/tech2a-prepend.php`:

```php
<?php
if (!defined('DB_PATH')) {
    define('DB_PATH', 'C:/dev/wcmaclasscalc/scratch/tech2a-e2e.db');
}
```

Create `scratch/tech2a-router.php`:

```php
<?php
// php -S router: serves the harness page; everything else falls through to the app.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/harness') {
    require __DIR__ . '/tech2a-harness.php';
    return true;
}
return false;
```

Create `scratch/tech2a-harness.php`:

```php
<?php
// Seeds the scratch DB and signs the browser in. ?as=admin | owner | other
require_once __DIR__ . '/tech2a-prepend.php';   // php -S does not apply auto_prepend_file to router-served requests
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

if (!$pdo->query('SELECT COUNT(*) FROM tech_sheets')->fetchColumn()) {
    $sub = function (int $userId, string $email) use ($pdo): int {
        return db_insert_submission($pdo, [
            ':submitted_at' => date('Y-m-d H:i:s'), ':name' => 'Racer', ':email' => $email, ':year' => '2020', ':make' => 'Mazda',
            ':model' => 'MX-5', ':comments' => null, ':competition_weight' => 2200, ':declared_hp' => 150, ':dyno_hp' => null,
            ':chassis_display' => null, ':body_mods_display' => null, ':transmission_display' => null, ':drivetrain_display' => null,
            ':tires_display' => null, ':brake_suspension' => null, ':chassis_value' => 0, ':body_mods_value' => 0,
            ':transmission_value' => 0, ':drivetrain_value' => 0, ':tires_value' => 0, ':brake_suspension_value' => 0,
            ':weight_factor' => 0, ':modification_factor' => 0, ':base_ratio' => 14.67, ':modified_ratio' => 14.67,
            ':calculated_class' => 'IT1', ':user_id' => $userId,
        ]);
    };
    $sheet = function (int $userId, int $subId, int $eventId, string $number, string $entrant) use ($pdo): int {
        return db_insert_tech_sheet($pdo, [
            'submission_id' => $subId, 'user_id' => $userId, 'event_id' => $eventId, 'sheet_type' => 'standard',
            'entrant_name' => $entrant, 'driver_name' => $entrant, 'car_make' => 'Mazda', 'car_model' => 'MX-5',
            'car_colour' => 'Red', 'car_number' => $number, 'class' => 'IT1', 'engine_cc' => '1800', 'engine_hp' => '150',
            'car_weight' => 2200, 'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1,
        ]);
    };
    $spring = db_create_event($pdo, 'E2E Spring Sprint', '2026-05-10', null);
    $fall   = db_create_event($pdo, 'E2E Fall Finale', '2026-10-04', null);
    $ownerSub = $sub($ownerId, 'owner@example.com');
    $otherSub = $sub($otherId, 'other@example.com');
    $sheet($ownerId, $ownerSub, $spring, '042', 'Owner');   // sheet 1: owner car 42, spring
    $sheet($ownerId, $ownerSub, $fall, '42', 'Owner');      // sheet 2: same car, fall (identity: 042 == 42)
    $sheet($otherId, $otherSub, $fall, '7', 'Other');       // sheet 3: someone else's car
}

$who = $_GET['as'] ?? 'admin';
$_SESSION['user_id'] = ['admin' => $adminId, 'owner' => $ownerId, 'other' => $otherId][$who] ?? $adminId;
$_SESSION['user_name'] = ucfirst($who);
$_SESSION['user_role'] = $who === 'admin' ? 'admin' : 'user';
generateCsrfToken();
?>
<!doctype html><meta charset="utf-8"><title>tech2a harness</title><p>harness ready: <?= h($who) ?></p>
```

Create `scratch/tech2a-e2e.js`:

```js
const { chromium } = require('C:/dev/wcmaclasscalc/scratch/tech-sheet-mockups/node_modules/playwright');
const assert = require('node:assert');
const BASE = 'http://localhost:8124';

async function signIn(browser, who) {
    const ctx = await browser.newContext({ viewport: { width: 420, height: 900 } });   // phone-sized: the review page is mobile-first
    const page = await ctx.newPage();
    await page.goto(BASE + '/harness?as=' + who);
    await page.waitForSelector('text=harness ready');
    return page;
}

async function drawSignature(page) {
    const box = await page.locator('#tech-sig-canvas').boundingBox();
    await page.mouse.move(box.x + 20, box.y + 60);
    await page.mouse.down();
    await page.mouse.move(box.x + 120, box.y + 30, { steps: 8 });
    await page.mouse.move(box.x + 220, box.y + 90, { steps: 8 });
    await page.mouse.up();
}

(async () => {
    const browser = await chromium.launch();

    // ── Owner (not admin) cannot reach the admin pages ─────────────────────
    const owner = await signIn(browser, 'owner');
    const denied = await owner.goto(BASE + '/admin.php?action=tech-sheets');
    assert.ok(!(await owner.content()).includes('Tech Sheets — WCMA Admin'), 'non-admin must not see the roster');
    console.log('non-admin roster: blocked (final url ' + owner.url() + ')');

    // ── Owner's My Cars before acceptance ──────────────────────────────────
    await owner.goto(BASE + '/account.php');
    const before = await owner.textContent('body');
    console.log('owner before:', /Needs tech at the track/.test(before) ? 'Needs tech at the track' : 'MISSING');
    assert.match(before, /Needs tech at the track/);

    // ── Admin roster: all events, needs-tech filter ─────────────────────────
    const admin = await signIn(browser, 'admin');
    await admin.goto(BASE + '/admin.php?action=tech-sheets&event=0&filter=all');
    let rows = await admin.locator('#tech-sheets-table tbody tr').count();
    assert.strictEqual(rows, 3, 'three sheets in the roster');
    await admin.goto(BASE + '/admin.php?action=tech-sheets&event=0&filter=needs_tech');
    assert.strictEqual(await admin.locator('#tech-sheets-table tbody tr').count(), 3, 'all three need tech');
    console.log('roster: 3 sheets, all need tech');

    // ── Review sheet 1 on a phone-sized viewport: submit without a signature is blocked ──
    await admin.goto(BASE + '/admin.php?action=tech-sheet&id=1');
    await admin.click('text=Accept — teched in person');
    assert.ok(await admin.locator('.sig-pad-wrap.field-error').count() > 0, 'empty signature flagged');
    assert.ok(await admin.locator('#tech-accept-form button[type="submit"]').isEnabled(), 'Accept stays usable after an empty-signature attempt');
    assert.match(admin.url(), /action=tech-sheet&id=1/);
    console.log('accept without signature: blocked client-side');

    // ── Accept with a drawn signature ───────────────────────────────────────
    await drawSignature(admin);
    await Promise.all([admin.waitForNavigation(), admin.click('text=Accept — teched in person')]);
    const acceptedPage = await admin.textContent('body');
    assert.match(acceptedPage, /Sheet accepted \(teched in person\)/);
    assert.match(acceptedPage, /Accepted in person by Admin/);
    assert.match(acceptedPage, /Teched 2026/);
    assert.match(acceptedPage, /Reviewed in person/);
    assert.match(acceptedPage, /not a certification/);
    const sigStatus = await admin.evaluate(async () => (await fetch('admin.php?action=tech-sheet-sig&id=1&which=tech')).status);
    assert.strictEqual(sigStatus, 200, 'admin can load the tech signature');
    console.log('accepted sheet 1 in person; signature served (200)');

    // ── The same car's other sheet (042 == 42) is now accepted too; the other owner's car is not ──
    await admin.goto(BASE + '/admin.php?action=tech-sheets&event=0&filter=accepted');
    assert.strictEqual(await admin.locator('#tech-sheets-table tbody tr').count(), 2, 'both sheets of car 42 accepted');
    await admin.goto(BASE + '/admin.php?action=tech-sheets&event=0&filter=needs_tech');
    assert.strictEqual(await admin.locator('#tech-sheets-table tbody tr').count(), 1, 'only car 7 still needs tech');
    console.log('identity: 042 and 42 share the annual; car 7 still needs tech');

    // ── Owner sees the accepted status; other owner does not ────────────────
    await owner.goto(BASE + '/account.php');
    assert.match(await owner.textContent('body'), /Teched 2026/);
    await owner.goto(BASE + '/tech-sheets.php?action=view&id=2');
    const ownerView = await owner.textContent('body');
    assert.match(ownerView, /Car status:\s*Teched 2026/);
    const other = await signIn(browser, 'other');
    await other.goto(BASE + '/account.php');
    const otherBody = await other.textContent('body');
    assert.ok(!/Teched 2026/.test(otherBody), 'other owner must not inherit acceptance');
    console.log('competitor views: owner sees Teched 2026, other owner does not');

    // ── The competitor cannot edit an accepted sheet ──
    await owner.goto(BASE + '/tech-sheets.php?action=edit&id=1');
    assert.match(await owner.textContent('body'), /already been reviewed/);
    console.log('competitor edit of an accepted sheet: refused');

    // ── Revoke ──────────────────────────────────────────────────────────────
    await admin.goto(BASE + '/admin.php?action=tech-sheet&id=1');
    await admin.click('text=Revoke acceptance');                       // opens the site's in-page confirm modal
    await Promise.all([admin.waitForNavigation(), admin.click('[data-role="confirm"]')]);
    assert.match(await admin.textContent('body'), /Acceptance revoked/);
    await admin.goto(BASE + '/admin.php?action=tech-sheets&event=0&filter=needs_tech');
    assert.strictEqual(await admin.locator('#tech-sheets-table tbody tr').count(), 3, 'all three need tech again');
    const sigGone = await admin.evaluate(async () => (await fetch('admin.php?action=tech-sheet-sig&id=1&which=tech')).status);
    assert.strictEqual(sigGone, 404, 'signature removed on revoke');
    console.log('revoked: all three need tech again; signature 404');

    await browser.close();
    console.log('E2E OK');
})().catch(e => { console.error(e); process.exit(1); });
```

- [ ] **Step 3: Start the server (port 8124, scratch DB only)**

Run (from repo root, in the background): `php -d auto_prepend_file=C:/dev/wcmaclasscalc/scratch/tech2a-prepend.php -S localhost:8124 -t wcma-calculator scratch/tech2a-router.php`
Expected: `Development Server (http://localhost:8124) started`. Verify with `curl -s -o /dev/null -w "%{http_code}" "http://localhost:8124/harness?as=admin"` (expect `200`).

- [ ] **Step 4: Run the end-to-end script**

Run: `node scratch/tech2a-e2e.js`
Expected: output ending with `E2E OK`. If a step fails, work out whether the scratch script or the product is wrong: fix scratch-script mistakes (selectors, timing) in the scratch script only, and report genuine product defects with evidence rather than editing product code in this task.

- [ ] **Step 5: Stop the server and clean up**

Stop the PHP server you started (find the listener on port 8124 with `netstat -ano | grep 8124 | grep LISTENING` and kill that process id), confirm the port has no listener, then remove `scratch/tech2a-e2e.db*` and `wcma-calculator/uploads/tech-sheets/1` and `wcma-calculator/uploads/tech-sheets/2` only if they were created by this run (the harness sheets have ids 1-3 in the scratch DB; list the folder before deleting and delete only signature files this run created).

- [ ] **Step 6: Verify the default database was untouched, and run the full regression**

Run the Step 1 command again; the four counts must match what you recorded. Then from `wcma-calculator/` run `php phpunit.phar`. Finally `git status --short` must show only the pre-existing `?? .htaccess.server` and `?? scratch/`. Nothing to commit for this task.

---

## Self-Review Notes

- **Spec coverage (phase 2a):** derived car status with the exact precedence (Task 1); car identity columns, season and normalisation, accepted/`accepted_via`/reviewer fields (Task 2); in-person acceptance with the inspector signature and revoke (Tasks 3-4, 6); roster with the "Needs tech at the track" filter and per-event view (Task 5); competitor status on My Cars and the sheet page, the reviewed/accepted wording and the disclaimer (Task 7); "not blocking" is preserved because nothing in this phase gates sheet submission. Deferred to phase 2b per the phasing: photo capture, photo review and the sent-back/accepted emails; gear chips wait for phase 3.
- **Ruling recorded for the reviewer:** in-person acceptance captures the inspector signature (existing signature pad), as the paper form does; remote acceptance in 2b records "accepted remotely by ... on ..." with no signature.
- **Type consistency:** the status array shape `{state, via, sheet_id}` is produced by `techCarStatus()` and consumed by `techCarStatusLabel()`, `techCarStatusBadgeClass()` (state only), the roster and the admin/competitor pages; sheet rows always carry `season`, `car_number_norm`, `accepted_via`, `photo_status` after the Task 2 migration.
- **Known limits:** the admin pages and `account.php` are covered by the Task 8 browser run, not PHPUnit, because they load `config.php`; all decisions they make live in tested modules.
