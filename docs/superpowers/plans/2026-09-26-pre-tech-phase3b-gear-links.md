# Pre-Tech Phase 3b: Gear Links Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show each driver's gear status on the event tech sheet (competitor view, My Cars, admin sheet review) and on the admin event roster, so an inspector sees at a glance who still needs a gear check at the track, and a competitor can jump straight to a driver's gear page or add the missing record.

**Architecture:** No schema change. A sheet's drivers are already stored (driver 1 = `tech_sheets.driver_name`; extra drivers = `tech_sheet_drivers`). A pure function, `gearLinksForSheet()`, matches each driver name (normalised with `gearNameNorm()`) to a gear record of the **sheet owner** for the **sheet's season**. The roster gets those links through a pure attach function fed by two bulk queries (drivers for the sheets, gear records for the season), and `techRosterFilter()` reads them. A small renderer (`gear-chips.php`) draws the chips for both audiences. The sheet form suggests the owner's gear-record names in a `<datalist>` so picking one makes the names match exactly.

**Tech Stack:** PHP 8.3, SQLite via PDO, PHPUnit (`phpunit.phar`), vanilla JS (classic scripts), Playwright for the end-to-end check.

**Spec:** `docs/superpowers/specs/2026-09-23-digital-tech-inspection-design.md` (Competitor Flow steps 2 and 4, Inspector Flow step 3). Design decision recorded with the user on 2026-09-26: link **by name** rather than by stored `gear_record_id` columns (the spec's optional columns are not added), because it needs no migration, stays correct when a sheet is edited, and captains already create records by name.

## Global Constraints

- **Terminology (binding):** copy uses "reviewed", "accepted", "teched", "pre-teched"; never "approved", "passed" or "safe" as words.
- **No schema change and no writes:** this phase only reads gear records, sheets and drivers. The only new persisted thing is nothing; the `gear.php?name=` prefill is a GET parameter used to fill the add form.
- **Matching rule:** a driver matches a gear record when the record's `owner_user_id` equals the sheet's `user_id`, the record's `season` equals the sheet's `season` (falling back to the current calendar year when the sheet has no season), and `driver_name_norm` equals `gearNameNorm(<driver name>)`. Blank driver names are skipped. A gear record entered by a different user never matches (a co-driver's own account record does not link to a captain's sheet; the captain adds them under My Drivers).
- **Link shape (used by every consumer):** a list of `['driver_number' => int, 'name' => string, 'name_norm' => string, 'gear' => ?array, 'status' => array{state: string, via: ?string}]`, driver 1 first, then additional drivers in `driver_number` order. `status` is `gearStatus($gear)` or `['state' => 'none', 'via' => null]` when there is no record.
- **Roster semantics:** `needs_tech` (and its complement `accepted`) mean "not fully done": a row is done only when the car is accepted **and** every linked driver's gear state is `accepted` (a driver with no gear record counts as not accepted). `pending_review` matches when the car's photos or any driver's gear photos are awaiting review. Rows without a `gear_links` key keep the phase 2 behaviour exactly, so existing roster tests stay green.
- **Authorisation and isolation:** competitor pages only ever load the signed-in user's own gear records (`db_get_user_gear_records`); admin pages load the sheet owner's or the season's records. Links point competitors to `gear.php?action=pretech&id=N` (already owner-scoped) and admins to `admin.php?action=gear-record&id=N` (already admin-only). All output is escaped with `h()`.
- **Car flows must not change:** the sheet form, view page, My Cars page, roster and review page keep every existing element and behaviour; new markup is additive, and existing function signatures only gain optional trailing parameters.
- **Repo conventions:** LF-authored PHP with a `// wcma-calculator/<file>` header comment; pure helpers are session-free; tests live in `wcma-calculator/tests/*Test.php`; existing files are changed with the Edit tool, never rewritten whole; commit after each task with a subject line, a blank line, then the trailer; run PHP tests from `wcma-calculator/` with `php phpunit.phar` and JS tests with `node --test "tests/js/*.test.js"` (quoted glob).

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `wcma-calculator/db.php` | modify | `db_get_drivers_for_sheets()` |
| `wcma-calculator/gear-lib.php` | modify | `gearLinksForSheet`, `gearNameSuggestions`, `gearAttachToRoster` |
| `wcma-calculator/tech-status.php` | modify | Gear-aware `techRosterFilter` + two small helpers |
| `wcma-calculator/gear-chips.php` | create | `renderGearChips()` for owner and admin audiences |
| `wcma-calculator/css/calculator.css` | modify | `.gear-chips` styles |
| `wcma-calculator/gear-page.php`, `gear.php` | modify | `?name=` prefill on the add form |
| `wcma-calculator/tech-sheets.php` | modify | Gear chips on the sheet view; name suggestions on the sheet form |
| `wcma-calculator/js/tech-sheet-form.js` | modify | Attach the suggestion list to added driver rows |
| `wcma-calculator/account.php` | modify | Gear chips per sheet line |
| `wcma-calculator/admin-tech-sheets.php`, `wcma-calculator/admin.php` | modify | Roster Gear column, gear-aware filters, chips on the review page |
| `wcma-calculator/tests/*` | create/modify | Tests per task |

---

### Task 1: Gear link functions and the driver bulk query

**Files:**
- Modify: `wcma-calculator/db.php` (append one function)
- Modify: `wcma-calculator/gear-lib.php` (append three functions)
- Test: `wcma-calculator/tests/GearLinksTest.php`

**Interfaces:**
- Consumes: `gearNameNorm()`, `gearStatus()`, `gearSeasonNow()` (gear-lib.php); `db_add_tech_sheet_driver()`, `db_insert_tech_sheet()`, `db_create_event()`, `db_create_user()`, `db_insert_gear_record()`.
- Produces:
  - `db_get_drivers_for_sheets(PDO $pdo, array $sheetIds): array` — map of sheet id (int) to that sheet's `tech_sheet_drivers` rows ordered by `driver_number`; sheets with no additional drivers are absent; an empty id list returns `[]`.
  - `gearLinksForSheet(array $sheet, array $drivers, array $ownerGear): array` — the link shape from Global Constraints. `$ownerGear` is a list of gear rows (any season, any owner: the function filters).
  - `gearNameSuggestions(array $ownerGear, int $season): array` — the distinct `driver_name` values of records in `$season`, sorted case-insensitively.
  - `gearAttachToRoster(array $rows, array $driversBySheet, array $seasonGear): array` — each roster row (`['sheet' => ..., 'status' => ...]`) gains `gear_links` (from `gearLinksForSheet` with that sheet owner's records out of `$seasonGear`); `$driversBySheet` is the map from `db_get_drivers_for_sheets`.

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/GearLinksTest.php`:

```php
<?php
// wcma-calculator/tests/GearLinksTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../gear-lib.php';

use PHPUnit\Framework\TestCase;

final class GearLinksTest extends TestCase
{
    private function gear(int $id, int $owner, string $name, int $season = 2026, array $o = []): array {
        return array_merge([
            'id' => $id, 'owner_user_id' => $owner, 'driver_name' => $name, 'driver_name_norm' => gearNameNorm($name),
            'licence_no' => null, 'season' => $season, 'status' => 'open', 'photo_status' => null, 'accepted_via' => null,
        ], $o);
    }

    private function sheet(array $o = []): array {
        return array_merge(['id' => 1, 'user_id' => 5, 'season' => 2026, 'driver_name' => 'Jane Racer'], $o);
    }

    public function testLinksDriverOneAndAdditionalDriversInOrder(): void
    {
        $drivers = [
            ['driver_number' => 2, 'driver_name' => 'Sam Coach'],
            ['driver_number' => 3, 'driver_name' => 'Pat Nobody'],
        ];
        $links = gearLinksForSheet($this->sheet(), $drivers, [
            $this->gear(10, 5, 'Jane Racer', 2026, ['status' => 'accepted', 'accepted_via' => 'photos', 'photo_status' => 'accepted']),
            $this->gear(11, 5, 'sam coach', 2026, ['photo_status' => 'submitted']),
        ]);

        $this->assertSame([1, 2, 3], array_column($links, 'driver_number'));
        $this->assertSame(['Jane Racer', 'Sam Coach', 'Pat Nobody'], array_column($links, 'name'));
        $this->assertSame(10, (int)$links[0]['gear']['id']);
        $this->assertSame(['state' => 'accepted', 'via' => 'photos'], $links[0]['status']);
        $this->assertSame(11, (int)$links[1]['gear']['id']);
        $this->assertSame('pending_review', $links[1]['status']['state']);
        $this->assertNull($links[2]['gear']);
        $this->assertSame(['state' => 'none', 'via' => null], $links[2]['status']);
        $this->assertSame('pat nobody', $links[2]['name_norm']);
    }

    public function testMatchingIgnoresWhitespaceCaseOtherOwnersAndOtherSeasons(): void
    {
        $links = gearLinksForSheet($this->sheet(['driver_name' => "  JANE   racer "]), [], [
            $this->gear(1, 6, 'Jane Racer'),          // another owner
            $this->gear(2, 5, 'Jane Racer', 2025),    // another season
            $this->gear(3, 5, 'Jane Racer', 2026),
        ]);
        $this->assertCount(1, $links);
        $this->assertSame(3, (int)$links[0]['gear']['id']);
        $this->assertSame('JANE racer', $links[0]['name']);   // whitespace collapsed, case kept for display
    }

    public function testNoRecordsMeansNoneStatusAndBlankNamesAreSkipped(): void
    {
        $links = gearLinksForSheet($this->sheet(['driver_name' => '   ']), [['driver_number' => 2, 'driver_name' => ' ']], []);
        $this->assertSame([], $links);

        $links = gearLinksForSheet($this->sheet(), [], []);
        $this->assertCount(1, $links);
        $this->assertNull($links[0]['gear']);
        $this->assertSame('none', $links[0]['status']['state']);
    }

    public function testSheetWithoutSeasonFallsBackToTheCurrentYear(): void
    {
        $now = gearSeasonNow();
        $links = gearLinksForSheet($this->sheet(['season' => null]), [], [$this->gear(1, 5, 'Jane Racer', $now)]);
        $this->assertNotNull($links[0]['gear']);
    }

    public function testNameSuggestionsAreDistinctSortedAndSeasonScoped(): void
    {
        $names = gearNameSuggestions([
            $this->gear(1, 5, 'zed'), $this->gear(2, 5, 'Amy'), $this->gear(3, 5, 'Bob', 2025), $this->gear(4, 5, 'amy'),
        ], 2026);
        $this->assertSame(['Amy', 'amy', 'zed'], $names);
        $this->assertSame([], gearNameSuggestions([], 2026));
    }

    public function testAttachToRosterUsesEachSheetOwnersRecordsAndDrivers(): void
    {
        $rows = [
            ['sheet' => $this->sheet(['id' => 1, 'user_id' => 5, 'driver_name' => 'Jane Racer']), 'status' => ['state' => 'none']],
            ['sheet' => $this->sheet(['id' => 2, 'user_id' => 6, 'driver_name' => 'Jane Racer']), 'status' => ['state' => 'none']],
        ];
        $attached = gearAttachToRoster($rows, [1 => [['driver_number' => 2, 'driver_name' => 'Sam Coach']]], [
            $this->gear(10, 5, 'Jane Racer'),
            $this->gear(11, 5, 'Sam Coach'),
        ]);

        $this->assertSame($rows[0]['sheet'], $attached[0]['sheet']);
        $this->assertSame(['state' => 'none'], $attached[0]['status']);
        $this->assertCount(2, $attached[0]['gear_links']);
        $this->assertNotNull($attached[0]['gear_links'][1]['gear']);
        $this->assertCount(1, $attached[1]['gear_links']);
        $this->assertNull($attached[1]['gear_links'][0]['gear']);   // owner 6 has no records
    }

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
        $eventId = db_create_event($pdo, 'Spring Sprint', '2026-05-10', null);
        $sheetOf = fn(string $number): int => db_insert_tech_sheet($pdo, [
            'submission_id' => $subId, 'user_id' => $userId, 'event_id' => $eventId, 'sheet_type' => 'endurance',
            'entrant_name' => 'Racer', 'driver_name' => 'Racer', 'car_make' => 'Mazda', 'car_model' => 'MX-5',
            'car_colour' => 'Red', 'car_number' => $number, 'class' => 'IT1', 'engine_cc' => '1800', 'engine_hp' => '150',
            'car_weight' => 2200, 'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1,
        ]);
        return [$sheetOf('42'), $sheetOf('7'), $sheetOf('99')];
    }

    public function testDriversForSheetsGroupsByDriverNumberAndOmitsSheetsWithNone(): void
    {
        $pdo = make_temp_pdo();
        [$a, $b, $c] = $this->fixture($pdo);
        db_add_tech_sheet_driver($pdo, $a, 3, 'Pat', '{}');
        db_add_tech_sheet_driver($pdo, $a, 2, 'Sam', '{}');
        db_add_tech_sheet_driver($pdo, $b, 2, 'Lee', '{}');

        $map = db_get_drivers_for_sheets($pdo, [$a, $b, $c]);
        $this->assertSame([2, 3], array_map(fn($d) => (int)$d['driver_number'], $map[$a]));
        $this->assertSame(['Sam', 'Pat'], array_column($map[$a], 'driver_name'));
        $this->assertSame(['Lee'], array_column($map[$b], 'driver_name'));
        $this->assertArrayNotHasKey($c, $map);

        $this->assertSame([], db_get_drivers_for_sheets($pdo, []));
        $this->assertSame([], db_get_drivers_for_sheets($pdo, [999999]));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run (from `wcma-calculator/`): `php phpunit.phar --filter GearLinksTest`
Expected: errors such as `Call to undefined function gearLinksForSheet()`.

- [ ] **Step 3: Add the bulk driver query**

Append to the end of `wcma-calculator/db.php`:

```php

/** Additional drivers for many sheets at once: sheet id => rows ordered by driver number. Sheets with none are absent. */
function db_get_drivers_for_sheets(PDO $pdo, array $sheetIds): array {
    $ids = array_values(array_unique(array_map('intval', $sheetIds)));
    if (!$ids) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM tech_sheet_drivers WHERE tech_sheet_id IN ($marks) ORDER BY tech_sheet_id ASC, driver_number ASC");
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int)$row['tech_sheet_id']][] = $row;
    }
    return $map;
}
```

- [ ] **Step 4: Add the link functions**

Append to the end of `wcma-calculator/gear-lib.php`:

```php

/**
 * Each driver on a sheet (driver 1, then the additional drivers) matched to the sheet owner's
 * gear record for the sheet's season, by normalised name. $ownerGear may hold any owner's and any
 * season's records: only the owner's, same-season records are considered.
 *
 * @return array<int, array{driver_number: int, name: string, name_norm: string, gear: ?array, status: array{state: string, via: ?string}}>
 */
function gearLinksForSheet(array $sheet, array $drivers, array $ownerGear): array {
    $season = (int)($sheet['season'] ?? 0) ?: gearSeasonNow();
    $ownerId = (int)($sheet['user_id'] ?? 0);

    $byName = [];
    foreach ($ownerGear as $g) {
        if ((int)$g['owner_user_id'] === $ownerId && (int)$g['season'] === $season) {
            $byName[$g['driver_name_norm']] = $g;
        }
    }

    $entries = [[1, (string)($sheet['driver_name'] ?? '')]];
    foreach ($drivers as $d) {
        $entries[] = [(int)$d['driver_number'], (string)$d['driver_name']];
    }

    $links = [];
    foreach ($entries as [$number, $rawName]) {
        $name = trim((string)preg_replace('/\s+/', ' ', $rawName));
        if ($name === '') continue;
        $norm = gearNameNorm($name);
        $gear = $byName[$norm] ?? null;
        $links[] = [
            'driver_number' => $number,
            'name' => $name,
            'name_norm' => $norm,
            'gear' => $gear,
            'status' => $gear !== null ? gearStatus($gear) : ['state' => 'none', 'via' => null],
        ];
    }
    return $links;
}

/** Distinct gear-record driver names for a season, sorted case-insensitively: suggestions for the sheet form. */
function gearNameSuggestions(array $ownerGear, int $season): array {
    $names = [];
    foreach ($ownerGear as $g) {
        if ((int)$g['season'] === $season) $names[$g['driver_name']] = true;
    }
    $names = array_keys($names);
    usort($names, fn(string $a, string $b): int => strcasecmp($a, $b) ?: strcmp($a, $b));
    return $names;
}

/**
 * Adds `gear_links` to each roster row. $driversBySheet is db_get_drivers_for_sheets(); $seasonGear
 * is every owner's gear records for the season(s) on the roster.
 */
function gearAttachToRoster(array $rows, array $driversBySheet, array $seasonGear): array {
    $byOwner = [];
    foreach ($seasonGear as $g) {
        $byOwner[(int)$g['owner_user_id']][] = $g;
    }
    foreach ($rows as $i => $row) {
        $sheet = $row['sheet'];
        $rows[$i]['gear_links'] = gearLinksForSheet($sheet, $driversBySheet[(int)$sheet['id']] ?? [], $byOwner[(int)$sheet['user_id']] ?? []);
    }
    return $rows;
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php phpunit.phar`
Expected: `OK` for the whole suite including `GearLinksTest` (7 tests).

- [ ] **Step 6: Commit**

```bash
git add wcma-calculator/db.php wcma-calculator/gear-lib.php wcma-calculator/tests/GearLinksTest.php
git commit -m "feat(gear): link sheet drivers to gear records by name"
```

---

### Task 2: Gear-aware roster filters

**Files:**
- Modify: `wcma-calculator/tech-status.php` (replace `techRosterFilter`, add two helpers)
- Test: `wcma-calculator/tests/TechStatusTest.php` (add cases)

**Interfaces:**
- Consumes: the `gear_links` key that `gearAttachToRoster()` adds (Task 1); each link's `status['state']`.
- Produces:
  - `techGearLinksNeedGear(array $links): bool` — true when any link's `status.state` is not `accepted` (no links means false).
  - `techGearLinksPending(array $links): bool` — true when any link's state is `pending_review`.
  - `techRosterFilter(array $rows, string $filter): array` — as described in Global Constraints (rows without `gear_links` behave exactly as before).

- [ ] **Step 1: Write the failing tests**

Add to `wcma-calculator/tests/TechStatusTest.php`, inside the class (after the last existing test method; the file already has a `sheet()` helper but these tests build plain roster rows):

```php
    private function rosterRow(string $carState, ?array $gearStates): array {
        $row = ['sheet' => ['id' => 1], 'status' => ['state' => $carState, 'via' => null]];
        if ($gearStates !== null) {
            $row['gear_links'] = array_map(fn(string $s): array => ['status' => ['state' => $s, 'via' => null]], $gearStates);
        }
        return $row;
    }

    public function testGearHelpers(): void
    {
        $this->assertFalse(techGearLinksNeedGear([]));
        $this->assertFalse(techGearLinksNeedGear([['status' => ['state' => 'accepted']]]));
        $this->assertTrue(techGearLinksNeedGear([['status' => ['state' => 'accepted']], ['status' => ['state' => 'none']]]));
        $this->assertTrue(techGearLinksNeedGear([['status' => ['state' => 'pending_review']]]));
        $this->assertTrue(techGearLinksPending([['status' => ['state' => 'accepted']], ['status' => ['state' => 'pending_review']]]));
        $this->assertFalse(techGearLinksPending([['status' => ['state' => 'none']]]));
        $this->assertFalse(techGearLinksPending([]));
    }

    public function testRosterFilterCountsUnfinishedGearAsNeedingTech(): void
    {
        $rows = [
            $this->rosterRow('accepted', ['accepted']),              // 0: car and gear done
            $this->rosterRow('accepted', ['accepted', 'none']),      // 1: car done, a driver has no gear record
            $this->rosterRow('none', ['accepted']),                  // 2: gear done, car not
            $this->rosterRow('accepted', ['pending_review']),        // 3: car done, gear photos awaiting review
            $this->rosterRow('pending_review', ['accepted']),        // 4: car photos awaiting review
        ];
        $ids = fn(string $f): array => array_keys(array_filter($rows, fn($r) => in_array($r, techRosterFilter($rows, $f), true)));

        $this->assertSame([0], $ids('accepted'));
        $this->assertSame([1, 2, 3, 4], $ids('needs_tech'));
        $this->assertSame([3, 4], $ids('pending_review'));
        $this->assertSame([0, 1, 2, 3, 4], $ids('all'));
        $this->assertSame([0, 1, 2, 3, 4], $ids('bogus'));
    }

    public function testRosterFilterWithoutGearLinksKeepsThePhaseTwoBehaviour(): void
    {
        $rows = [$this->rosterRow('accepted', null), $this->rosterRow('none', null), $this->rosterRow('pending_review', null)];
        $this->assertCount(1, techRosterFilter($rows, 'accepted'));
        $this->assertCount(2, techRosterFilter($rows, 'needs_tech'));
        $this->assertCount(1, techRosterFilter($rows, 'pending_review'));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter TechStatusTest`
Expected: `Call to undefined function techGearLinksNeedGear()`.

- [ ] **Step 3: Replace the filter and add the helpers**

In `wcma-calculator/tech-status.php`, replace this block (the docblock and function):

```php
/** $filter: 'all' | 'needs_tech' (car not accepted) | 'accepted' | 'pending_review'. Unknown values mean 'all'. */
function techRosterFilter(array $rows, string $filter): array {
    if (!in_array($filter, ['needs_tech', 'accepted', 'pending_review'], true)) return $rows;
    return array_values(array_filter($rows, function (array $r) use ($filter): bool {
        $state = $r['status']['state'];
        if ($filter === 'pending_review') return $state === 'pending_review';
        $accepted = $state === 'accepted';
        return $filter === 'accepted' ? $accepted : !$accepted;
    }));
}
```

with:

```php
/** True when any linked driver's gear is not accepted (a driver without a gear record counts as not accepted). */
function techGearLinksNeedGear(array $links): bool {
    foreach ($links as $l) {
        if (($l['status']['state'] ?? 'none') !== 'accepted') return true;
    }
    return false;
}

/** True when any linked driver's gear photos are awaiting review. */
function techGearLinksPending(array $links): bool {
    foreach ($links as $l) {
        if (($l['status']['state'] ?? 'none') === 'pending_review') return true;
    }
    return false;
}

/**
 * $filter: 'all' | 'needs_tech' (car or any driver's gear not accepted) | 'accepted' (car and all gear
 * accepted) | 'pending_review' (car or gear photos awaiting review). Unknown values mean 'all'. Rows
 * without a `gear_links` key are judged on the car alone.
 */
function techRosterFilter(array $rows, string $filter): array {
    if (!in_array($filter, ['needs_tech', 'accepted', 'pending_review'], true)) return $rows;
    return array_values(array_filter($rows, function (array $r) use ($filter): bool {
        $state = $r['status']['state'];
        $links = $r['gear_links'] ?? [];
        if ($filter === 'pending_review') return $state === 'pending_review' || techGearLinksPending($links);
        $done = $state === 'accepted' && !techGearLinksNeedGear($links);
        return $filter === 'accepted' ? $done : !$done;
    }));
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar`
Expected: `OK` for the whole suite (existing roster tests included).

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/tech-status.php wcma-calculator/tests/TechStatusTest.php
git commit -m "feat(gear): count unfinished driver gear in the roster filters"
```

---

### Task 3: Gear chips renderer and add-form prefill

**Files:**
- Create: `wcma-calculator/gear-chips.php`
- Modify: `wcma-calculator/css/calculator.css` (append)
- Modify: `wcma-calculator/gear-page.php` (prefill parameter and input value)
- Modify: `wcma-calculator/gear.php` (read `?name=`)
- Test: `wcma-calculator/tests/GearChipsTest.php`; add a case to `wcma-calculator/tests/GearPageTest.php`

**Interfaces:**
- Consumes: link shape (Task 1); `gearStatusLabel()`, `gearStatusBadgeClass()`; `h()`.
- Produces:
  - `renderGearChips(array $links, string $audience): string` — `$audience` is `'owner'` or `'admin'`. Returns `''` for no links; otherwise `<ul class="gear-chips">` with one `<li class="gear-chip">` per driver: `<escaped name>: ` then, with a gear record, `<a class="<badge class>" href="…">status label</a>` (owner href `gear.php?action=pretech&amp;id=N`, admin href `admin.php?action=gear-record&amp;id=N`), or, without one, `<span class="badge-pending">No gear record</span>` plus (owner only) `<a href="gear.php?name=<rawurlencoded name>">Add gear record</a>`.
  - `renderGearListPage(array $records, int $season, string $csrf, ?array $flash, ?string $prefillName = null): void` — the driver-name input gets `value="<escaped prefill>"` when given.

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/GearChipsTest.php`:

```php
<?php
// wcma-calculator/tests/GearChipsTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../gear-chips.php';

use PHPUnit\Framework\TestCase;

final class GearChipsTest extends TestCase
{
    private function link(string $name, ?array $gear): array {
        return [
            'driver_number' => 1, 'name' => $name, 'name_norm' => gearNameNorm($name), 'gear' => $gear,
            'status' => $gear !== null ? gearStatus($gear) : ['state' => 'none', 'via' => null],
        ];
    }

    private function gear(int $id, array $o = []): array {
        return array_merge(['id' => $id, 'season' => 2026, 'status' => 'open', 'photo_status' => null, 'accepted_via' => null], $o);
    }

    public function testNoLinksRendersNothing(): void
    {
        $this->assertSame('', renderGearChips([], 'owner'));
        $this->assertSame('', renderGearChips([], 'admin'));
    }

    public function testOwnerChipsLinkToTheGearPageOrOfferToAddOne(): void
    {
        $html = renderGearChips([
            $this->link('Jane Racer', $this->gear(4, ['status' => 'accepted', 'accepted_via' => 'in_person'])),
            $this->link('Sam Coach', null),
        ], 'owner');

        $this->assertStringContainsString('<ul class="gear-chips">', $html);
        $this->assertStringContainsString('Jane Racer: <a class="badge-ok" href="gear.php?action=pretech&amp;id=4">Gear teched 2026</a>', $html);
        $this->assertStringContainsString('Sam Coach: <span class="badge-pending">No gear record</span>', $html);
        $this->assertStringContainsString('<a href="gear.php?name=Sam%20Coach">Add gear record</a>', $html);
    }

    public function testAdminChipsLinkToTheAdminGearReviewAndNeverOfferToAdd(): void
    {
        $html = renderGearChips([
            $this->link('Jane Racer', $this->gear(4, ['photo_status' => 'submitted'])),
            $this->link('Sam Coach', null),
        ], 'admin');

        $this->assertStringContainsString('href="admin.php?action=gear-record&amp;id=4">Photos pending review</a>', $html);
        $this->assertStringContainsString('Sam Coach: <span class="badge-pending">No gear record</span>', $html);
        $this->assertStringNotContainsString('Add gear record', $html);
        $this->assertStringNotContainsString('gear.php', $html);
    }

    public function testNamesAreEscapedInTextAndInTheUrl(): void
    {
        $html = renderGearChips([$this->link('<b>"Al" & Co', null)], 'owner');
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
        $this->assertStringContainsString('gear.php?name=%3Cb%3E%22Al%22%20%26%20Co', $html);
    }

    public function testStatusLabelsCoverEveryStateWithoutBannedWording(): void
    {
        $states = [
            $this->gear(1, ['status' => 'accepted', 'accepted_via' => 'photos', 'photo_status' => 'accepted']),
            $this->gear(2, ['photo_status' => 'needs_changes']),
            $this->gear(3, ['photo_status' => 'submitted']),
            $this->gear(4, ['photo_status' => 'draft']),
            $this->gear(5),
        ];
        $html = renderGearChips(array_map(fn(array $g): array => $this->link('D' . $g['id'], $g), $states), 'owner');
        foreach (['Gear pre-teched 2026', 'Photos need changes', 'Photos pending review', 'Photos in progress', 'Needs gear check at the track'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', strip_tags($html));
    }
}
```

Add to `wcma-calculator/tests/GearPageTest.php`, inside the class:

```php
    public function testAddFormCanBePrefilledFromTheQueryAndEscapesIt(): void
    {
        ob_start();
        renderGearListPage([], 2026, 'csrf-token-1', null, 'Sam "<Coach>"');
        $html = (string)ob_get_clean();
        $this->assertStringContainsString('name="driver_name" maxlength="100" required value="Sam &quot;&lt;Coach&gt;&quot;"', $html);

        $plain = $this->renderList([]);
        $this->assertStringContainsString('name="driver_name" maxlength="100" required value=""', $plain);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter "GearChipsTest|GearPageTest"`
Expected: fatal error, `gear-chips.php` not found; and the prefill test failing.

- [ ] **Step 3: Create the renderer**

Create `wcma-calculator/gear-chips.php`:

```php
<?php
// wcma-calculator/gear-chips.php
//
// One-line gear status chips per driver on a tech sheet or roster row. Pure output: the links come
// from gearLinksForSheet() (gear-lib.php).
require_once __DIR__ . '/view_helpers.php';
require_once __DIR__ . '/gear-lib.php';

/**
 * @param array $links   gearLinksForSheet() result
 * @param string $audience 'owner' (competitor pages) or 'admin' (inspector pages)
 */
function renderGearChips(array $links, string $audience): string {
    if (!$links) return '';
    $html = '<ul class="gear-chips">';
    foreach ($links as $l) {
        $name = h($l['name']);
        $gear = $l['gear'];
        if ($gear === null) {
            $html .= '<li class="gear-chip">' . $name . ': <span class="badge-pending">No gear record</span>';
            if ($audience !== 'admin') {
                $html .= ' <a href="gear.php?name=' . h(rawurlencode($l['name'])) . '">Add gear record</a>';
            }
            $html .= '</li>';
            continue;
        }
        $label = gearStatusLabel($l['status'], (int)$gear['season']);
        $class = gearStatusBadgeClass($l['status']['state']);
        $href = $audience === 'admin'
            ? 'admin.php?action=gear-record&amp;id=' . (int)$gear['id']
            : 'gear.php?action=pretech&amp;id=' . (int)$gear['id'];
        $html .= '<li class="gear-chip">' . $name . ': <a class="' . h($class) . '" href="' . $href . '">' . h($label) . '</a></li>';
    }
    return $html . '</ul>';
}
```

- [ ] **Step 4: Add the styles**

Append to the end of `wcma-calculator/css/calculator.css`:

```css

/* ── Gear chips (per-driver gear status on sheets and the roster) ───────── */
.gear-chips { list-style: none; margin: 0.25rem 0 0; padding: 0; }
.gear-chip { margin: 0.15rem 0; font-size: 0.85rem; }
```

- [ ] **Step 5: Add the prefill to the add form**

In `wcma-calculator/gear-page.php`:

Change the function signature

```php
function renderGearListPage(array $records, int $season, string $csrf, ?array $flash): void {
```
to
```php
function renderGearListPage(array $records, int $season, string $csrf, ?array $flash, ?string $prefillName = null): void {
```
and change the input

```php
    <input type="text" id="gear-driver-name" name="driver_name" maxlength="100" required>
```
to
```php
    <input type="text" id="gear-driver-name" name="driver_name" maxlength="100" required value="<?= h((string)$prefillName) ?>">
```

In `wcma-calculator/gear.php`, change `handleGearList`

```php
function handleGearList(PDO $pdo, array $user): void {
    renderGearListPage(db_get_user_gear_records($pdo, (int)$user['id']), gearSeasonNow(), generateCsrfToken(), getFlash());
}
```
to
```php
function handleGearList(PDO $pdo, array $user): void {
    $prefill = is_string($_GET['name'] ?? null) ? mb_substr(trim($_GET['name']), 0, 100) : null;
    renderGearListPage(db_get_user_gear_records($pdo, (int)$user['id']), gearSeasonNow(), generateCsrfToken(), getFlash(), $prefill);
}
```

- [ ] **Step 6: Run the tests and lints**

Run: `php -l gear-chips.php && php -l gear-page.php && php -l gear.php && php phpunit.phar`
Expected: three `No syntax errors detected`, then `OK` for the whole suite (`GearChipsTest` 5 tests, the new `GearPageTest` case included).

- [ ] **Step 7: Commit**

```bash
git add wcma-calculator/gear-chips.php wcma-calculator/css/calculator.css wcma-calculator/gear-page.php wcma-calculator/gear.php wcma-calculator/tests/GearChipsTest.php wcma-calculator/tests/GearPageTest.php
git commit -m "feat(gear): add gear status chips and add-form prefill"
```

---

### Task 4: Competitor surfaces (sheet view, sheet form suggestions, My Cars)

**Files:**
- Modify: `wcma-calculator/tech-sheets.php` (requires, `handleNew`, `handleView`, `handleEdit`, `renderTechSheetForm`, `renderTechSheetEditForm`)
- Modify: `wcma-calculator/js/tech-sheet-form.js` (one line)
- Modify: `wcma-calculator/account.php` (requires, `handleAccountList`, `renderAccountListPage`)
- Test: `wcma-calculator/tests/GearLinksSourceTest.php`

**Interfaces:**
- Consumes: `gearLinksForSheet`, `gearNameSuggestions`, `db_get_drivers_for_sheets` (Task 1); `renderGearChips` (Task 3); `db_get_user_gear_records`, `gearSeasonNow`.
- Produces:
  - Sheet view page shows a "Driver gear" block (`.no-print`) with owner chips.
  - Sheet form (new and edit) has `<datalist id="gear-names">` filled with the owner's gear-record names (season = current year for a new sheet, the sheet's season when editing), and every driver-name input (`#driver_name` and each added driver row) has `list="gear-names"`.
  - `renderTechSheetForm(..., array $existingDrivers = [], array $gearNames = [])` and `renderTechSheetEditForm(array $sheet, array $drivers, array $events, string $csrf, array $gearNames = [])`.
  - `renderAccountListPage(array $drafts, array $carGroups, int $count, string $csrf, ?array $flash, array $carStatuses, array $gearLinks = [])`, where `$gearLinks` maps tech sheet id to that sheet's links; owner chips appear under each tech sheet line on My Cars.

These pages need `config.php` and cannot run under PHPUnit; a source-level test guards the wiring and Task 6 exercises them in a browser.

- [ ] **Step 1: Write the failing source-level tests**

Create `wcma-calculator/tests/GearLinksSourceTest.php`:

```php
<?php
// wcma-calculator/tests/GearLinksSourceTest.php
//
// Source-level guards for pages that need config.php and so cannot run under PHPUnit: the gear
// wiring is present where it must be, and the terminology rule holds for the new copy.
use PHPUnit\Framework\TestCase;

final class GearLinksSourceTest extends TestCase
{
    private function src(string $file): string {
        return str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../' . $file));
    }

    private function body(string $file, string $name): string {
        $src = $this->src($file);
        $start = strpos($src, 'function ' . $name . '(');
        $this->assertNotFalse($start, $name . ' must exist in ' . $file);
        $next = strpos($src, "\nfunction ", $start + 1);
        return $next === false ? substr($src, $start) : substr($src, $start, $next - $start);
    }

    public function testCompetitorPagesRequireTheGearHelpers(): void
    {
        foreach (['tech-sheets.php', 'account.php'] as $file) {
            $src = $this->src($file);
            $this->assertStringContainsString("/gear-lib.php'", $src, $file);
            $this->assertStringContainsString("/gear-chips.php'", $src, $file);
        }
    }

    public function testSheetViewShowsOwnerGearChipsFromTheUsersOwnRecords(): void
    {
        $view = $this->body('tech-sheets.php', 'handleView');
        $this->assertStringContainsString('db_get_user_gear_records($pdo, (int)$user[\'id\'])', $view);
        $this->assertStringContainsString('gearLinksForSheet(', $view);
        $this->assertStringContainsString("renderGearChips(\$gearLinks, 'owner')", $view);
    }

    public function testSheetFormGetsNameSuggestionsFromTheUsersOwnRecords(): void
    {
        foreach (['handleNew', 'handleEdit'] as $fn) {
            $body = $this->body('tech-sheets.php', $fn);
            $this->assertStringContainsString('gearNameSuggestions(', $body, $fn);
            $this->assertStringContainsString('db_get_user_gear_records($pdo, (int)$user[\'id\'])', $body, $fn);
        }
        $form = $this->body('tech-sheets.php', 'renderTechSheetForm');
        $this->assertStringContainsString('<datalist id="gear-names">', $form);
        $this->assertStringContainsString('name="driver_name" required list="gear-names"', $form);
    }

    public function testAddedDriverRowsUseTheSuggestionList(): void
    {
        $this->assertStringContainsString("nameInput.setAttribute('list', 'gear-names');", $this->src('js/tech-sheet-form.js'));
    }

    public function testMyCarsShowsGearChipsPerSheetLine(): void
    {
        $list = $this->body('account.php', 'handleAccountList');
        $this->assertStringContainsString('db_get_drivers_for_sheets(', $list);
        $this->assertStringContainsString('gearLinksForSheet(', $list);
        $page = $this->body('account.php', 'renderAccountListPage');
        $this->assertGreaterThanOrEqual(2, substr_count($page, "renderGearChips("));
    }

    public function testNewCopyAvoidsBannedWording(): void
    {
        foreach (['tech-sheets.php', 'account.php'] as $file) {
            $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed)\b/i', $this->src($file), $file);
        }
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter GearLinksSourceTest`
Expected: failures for missing requires and wiring.

- [ ] **Step 3: Wire `tech-sheets.php`**

Edits to `wcma-calculator/tech-sheets.php` (Edit tool, exact strings):

1. After the line `require __DIR__ . '/pretech-page.php';` add:
```php
require __DIR__ . '/gear-lib.php';
require __DIR__ . '/gear-chips.php';
```

2. In `handleNew`, replace
```php
    $csrf = generateCsrfToken();
    renderTechSheetForm($submission, $events, $csrf);
```
with
```php
    $gearNames = gearNameSuggestions(db_get_user_gear_records($pdo, (int)$user['id']), gearSeasonNow());
    $csrf = generateCsrfToken();
    renderTechSheetForm($submission, $events, $csrf, null, [], $gearNames);
```

3. In `handleView`, replace
```php
    $carStatus = techCarStatusForSheet($sheet, db_get_user_tech_sheets($pdo, (int)$user['id']));
    $csrf = generateCsrfToken();
    $flash = getFlash();
    ?><!DOCTYPE html>
```
with
```php
    $carStatus = techCarStatusForSheet($sheet, db_get_user_tech_sheets($pdo, (int)$user['id']));
    $gearLinks = gearLinksForSheet($sheet, $drivers, db_get_user_gear_records($pdo, (int)$user['id']));
    $csrf = generateCsrfToken();
    $flash = getFlash();
    ?><!DOCTYPE html>
```
and, directly after the line beginning `  <p class="no-print">Car status:` (the one that ends `</strong></p>`), add:
```php
  <?php if ($gearLinks): ?>
  <div class="no-print"><p><strong>Driver gear</strong></p><?= renderGearChips($gearLinks, 'owner') ?></div>
  <?php endif; ?>
```

4. In `handleEdit`, replace
```php
    $drivers = db_get_tech_sheet_drivers($pdo, $id);
    $csrf = generateCsrfToken();
    renderTechSheetEditForm($sheet, $drivers, $events, $csrf);
```
with
```php
    $drivers = db_get_tech_sheet_drivers($pdo, $id);
    $gearNames = gearNameSuggestions(db_get_user_gear_records($pdo, (int)$user['id']), (int)($sheet['season'] ?? 0) ?: gearSeasonNow());
    $csrf = generateCsrfToken();
    renderTechSheetEditForm($sheet, $drivers, $events, $csrf, $gearNames);
```

5. Change the form function signature
```php
function renderTechSheetForm(array $submission, array $events, string $csrf, ?array $existingSheet = null, array $existingDrivers = []): void {
```
to
```php
function renderTechSheetForm(array $submission, array $events, string $csrf, ?array $existingSheet = null, array $existingDrivers = [], array $gearNames = []): void {
```
and the edit wrapper
```php
function renderTechSheetEditForm(array $sheet, array $drivers, array $events, string $csrf): void {
    renderTechSheetForm([], $events, $csrf, $sheet, $drivers);
}
```
to
```php
function renderTechSheetEditForm(array $sheet, array $drivers, array $events, string $csrf, array $gearNames = []): void {
    renderTechSheetForm([], $events, $csrf, $sheet, $drivers, $gearNames);
}
```

6. In the form markup, directly after `    <input type="hidden" name="driver_signature" id="driver_signature">` add:
```php
    <datalist id="gear-names"><?php foreach ($gearNames as $gearName): ?><option value="<?= h($gearName) ?>"><?php endforeach; ?></datalist>
```
and change
```php
<input type="text" id="driver_name" name="driver_name" required value="
```
to
```php
<input type="text" id="driver_name" name="driver_name" required list="gear-names" value="
```
(the rest of that line is unchanged), then add one hint line directly after the `</div>` that closes `tech-sheet-header-grid` (the line `      </div>` before `      <input type="hidden" name="car_make"`):
```php
      <?php if ($gearNames): ?><p class="form-hint">Pick a driver from your My Drivers list so their gear status links to this sheet.</p><?php endif; ?>
```

- [ ] **Step 4: Wire the added-driver rows**

In `wcma-calculator/js/tech-sheet-form.js`, inside `addDriverRow`, directly after the line `        nameInput.required = true;` add:

```js
        nameInput.setAttribute('list', 'gear-names');
```

- [ ] **Step 5: Wire `account.php`**

Edits to `wcma-calculator/account.php`:

1. After `require __DIR__ . '/view_helpers.php';` add:
```php
require __DIR__ . '/gear-lib.php';
require __DIR__ . '/gear-chips.php';
```

2. In `handleAccountList`, replace
```php
    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderAccountListPage($drafts, $carGroups, $totalCount, $csrf, $flash, $carStatuses);
```
with
```php
    $ownerGear = db_get_user_gear_records($pdo, (int)$user['id']);
    $driversBySheet = db_get_drivers_for_sheets($pdo, array_map(fn(array $ts): int => (int)$ts['id'], $techSheets));
    $gearLinks = [];
    foreach ($techSheets as $ts) {
        $gearLinks[(int)$ts['id']] = gearLinksForSheet($ts, $driversBySheet[(int)$ts['id']] ?? [], $ownerGear);
    }

    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderAccountListPage($drafts, $carGroups, $totalCount, $csrf, $flash, $carStatuses, $gearLinks);
```

3. Change the signature
```php
function renderAccountListPage(array $drafts, array $carGroups, int $count, string $csrf, ?array $flash, array $carStatuses): void {
```
to
```php
function renderAccountListPage(array $drafts, array $carGroups, int $count, string $csrf, ?array $flash, array $carStatuses, array $gearLinks = []): void {
```

4. In the car card tech line, after the line `            <a href="tech-sheets.php?action=view&id=<?= (int)$sheet['id'] ?>">View</a>` add:
```php
            <?= renderGearChips($gearLinks[(int)$sheet['id']] ?? [], 'owner') ?>
```
and in the orphan sheets list, after the line `      <a href="tech-sheets.php?action=view&id=<?= (int)$ts['id'] ?>">View</a>` add:
```php
      <?= renderGearChips($gearLinks[(int)$ts['id']] ?? [], 'owner') ?>
```

- [ ] **Step 6: Run the tests and lints**

Run: `php -l tech-sheets.php && php -l account.php && node --check js/tech-sheet-form.js && php phpunit.phar && node --test "tests/js/*.test.js"`
Expected: `No syntax errors detected` twice, no `node --check` output, PHPUnit `OK` (including `GearLinksSourceTest`, 6 tests), Node `# fail 0`.

- [ ] **Step 7: Commit**

```bash
git add wcma-calculator/tech-sheets.php wcma-calculator/js/tech-sheet-form.js wcma-calculator/account.php wcma-calculator/tests/GearLinksSourceTest.php
git commit -m "feat(gear): show driver gear status to competitors and suggest gear names on the sheet form"
```

---

### Task 5: Admin roster Gear column and sheet review chips

**Files:**
- Modify: `wcma-calculator/admin.php` (one require)
- Modify: `wcma-calculator/admin-tech-sheets.php` (`handleTechSheetsList`, `renderTechSheetsListPage`, `handleTechSheetView`, `renderTechSheetViewPage`)
- Test: `wcma-calculator/tests/GearLinksSourceTest.php` (add cases)

**Interfaces:**
- Consumes: `gearAttachToRoster`, `gearLinksForSheet`, `db_get_drivers_for_sheets`, `db_get_gear_records_for_season`, `db_get_user_gear_records`, `renderGearChips` (admin audience), gear-aware `techRosterFilter` (Task 2).
- Produces:
  - Roster table gains a **Gear** column (`th` "Gear") with admin chips per entrant; the empty-state `colspan` becomes 9; the counts hint reads "…still need tech at the track (car or gear)".
  - `renderTechSheetViewPage(..., array $snapshot, array $gearLinks = [])`: the "Tech review" card shows "Driver gear:" chips.

Admin pages need `config.php`; a source-level test guards the wiring and Task 6 exercises them in a browser.

- [ ] **Step 1: Add the failing source-level tests**

Add to `wcma-calculator/tests/GearLinksSourceTest.php`, inside the class:

```php
    public function testAdminRosterAttachesGearAndRendersAGearColumn(): void
    {
        $this->assertStringContainsString("/gear-chips.php'", $this->src('admin.php'));

        $list = $this->body('admin-tech-sheets.php', 'handleTechSheetsList');
        $this->assertStringContainsString('db_get_drivers_for_sheets(', $list);
        $this->assertStringContainsString('db_get_gear_records_for_season(', $list);
        $this->assertStringContainsString('gearAttachToRoster(', $list);

        $page = $this->body('admin-tech-sheets.php', 'renderTechSheetsListPage');
        $this->assertStringContainsString('<th>Gear</th>', $page);
        $this->assertStringContainsString("renderGearChips(\$row['gear_links'] ?? [], 'admin')", $page);
        $this->assertStringContainsString('colspan="9"', $page);
    }

    public function testAdminSheetReviewShowsTheOwnersGearChips(): void
    {
        $view = $this->body('admin-tech-sheets.php', 'handleTechSheetView');
        $this->assertStringContainsString("db_get_user_gear_records(\$pdo, (int)\$sheet['user_id'])", $view);
        $this->assertStringContainsString('gearLinksForSheet(', $view);
        $page = $this->body('admin-tech-sheets.php', 'renderTechSheetViewPage');
        $this->assertStringContainsString("renderGearChips(\$gearLinks, 'admin')", $page);
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed)\b/i', $this->src('admin-tech-sheets.php'));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter GearLinksSourceTest`
Expected: the two new tests fail.

- [ ] **Step 3: Add the require in `admin.php`**

In `wcma-calculator/admin.php`, after the line `require __DIR__ . '/gear-email.php';` add:

```php
require __DIR__ . '/gear-chips.php';
```

- [ ] **Step 4: Attach gear to the roster**

In `wcma-calculator/admin-tech-sheets.php`, in `handleTechSheetsList`, replace

```php
    $eventSheets = db_get_event_tech_sheets($pdo, $eventId);
    $seasonSheets = [];
    foreach (array_unique(array_map(fn(array $s): int => (int)$s['season'], $eventSheets)) as $season) {
        $seasonSheets = array_merge($seasonSheets, db_get_season_sheets($pdo, $season));
    }
    $rows = techBuildRoster($eventSheets, $seasonSheets);
```

with

```php
    $eventSheets = db_get_event_tech_sheets($pdo, $eventId);
    $seasonSheets = [];
    $seasonGear = [];
    foreach (array_unique(array_map(fn(array $s): int => (int)$s['season'], $eventSheets)) as $season) {
        $seasonSheets = array_merge($seasonSheets, db_get_season_sheets($pdo, $season));
        $seasonGear = array_merge($seasonGear, db_get_gear_records_for_season($pdo, $season));
    }
    $driversBySheet = db_get_drivers_for_sheets($pdo, array_map(fn(array $s): int => (int)$s['id'], $eventSheets));
    $rows = gearAttachToRoster(techBuildRoster($eventSheets, $seasonSheets), $driversBySheet, $seasonGear);
```

- [ ] **Step 5: Add the Gear column**

In `renderTechSheetsListPage`:

Change the hint line

```php
    <p class="form-hint" style="margin-top:.5rem"><?= (int)$counts['all'] ?> sheets: <?= (int)$counts['accepted'] ?> accepted, <?= (int)$counts['needs_tech'] ?> still need tech at the track.</p>
```
to
```php
    <p class="form-hint" style="margin-top:.5rem"><?= (int)$counts['all'] ?> sheets: <?= (int)$counts['accepted'] ?> fully accepted (car and gear), <?= (int)$counts['needs_tech'] ?> still need tech at the track (car or gear).</p>
```

Change the header row

```php
<th>Car status</th><th>Sheet</th><th>Actions</th></tr></thead>
```
to
```php
<th>Car status</th><th>Gear</th><th>Sheet</th><th>Actions</th></tr></thead>
```

Change `<tr><td colspan="8" class="empty-row">No tech sheets match.</td></tr>` to `<tr><td colspan="9" class="empty-row">No tech sheets match.</td></tr>`.

After the car status cell line

```php
        <td class="<?= h(techCarStatusBadgeClass($st['state'])) ?>"><?= h(techCarStatusLabel($st, (int)$s['season'])) ?></td>
```
add:
```php
        <td><?= renderGearChips($row['gear_links'] ?? [], 'admin') ?></td>
```

- [ ] **Step 6: Show chips on the review page**

In `handleTechSheetView`, replace

```php
    renderTechSheetViewPage($sheet, $drivers, $event, $carStatus, $reviewer, generateCsrfToken(), getFlash(), pretechSnapshot($pdo, $id));
```
with
```php
    $gearLinks = gearLinksForSheet($sheet, $drivers, db_get_user_gear_records($pdo, (int)$sheet['user_id']));
    renderTechSheetViewPage($sheet, $drivers, $event, $carStatus, $reviewer, generateCsrfToken(), getFlash(), pretechSnapshot($pdo, $id), $gearLinks);
```

Change the render signature

```php
function renderTechSheetViewPage(array $sheet, array $drivers, array $event, array $carStatus, ?array $reviewer, string $csrf, ?array $flash, array $snapshot): void {
```
to
```php
function renderTechSheetViewPage(array $sheet, array $drivers, array $event, array $carStatus, ?array $reviewer, string $csrf, ?array $flash, array $snapshot, array $gearLinks = []): void {
```

and after the car status paragraph in the "Tech review" card

```php
    <p>Car status: <strong class="<?= h(techCarStatusBadgeClass($carStatus['state'])) ?>"><?= h($statusLabel) ?></strong></p>
```
add:
```php
    <?php if ($gearLinks): ?><p>Driver gear:</p><?= renderGearChips($gearLinks, 'admin') ?><?php endif; ?>
```

- [ ] **Step 7: Run the tests and lints**

Run: `php -l admin.php && php -l admin-tech-sheets.php && php phpunit.phar`
Expected: two `No syntax errors detected`, PHPUnit `OK` (including the two new source tests and every existing roster and admin test).

- [ ] **Step 8: Commit**

```bash
git add wcma-calculator/admin.php wcma-calculator/admin-tech-sheets.php wcma-calculator/tests/GearLinksSourceTest.php
git commit -m "feat(gear): show driver gear on the admin roster and sheet review"
```

---

### Task 6: End-to-end verification

No product code changes; no commits. This proves the linking in a real browser: a seeded owner with an endurance sheet (driver 1 with an accepted gear record, an additional driver with none) and a second user's sheet with no gear. It checks the competitor sheet view, the sheet form's suggestions, the add-from-chip prefill, My Cars, the admin roster columns and filters, and the review-page chips.

**Database and email safety (critical):** use ONLY the scratch DB `scratch/tech3b-e2e.db`, never `wcma-calculator/data/submissions.db` (real local data: users=0, submissions=1, tech_sheets=0, events=0 at the start of this phase; `gear_records` must have 0 rows). Every entry point loads `scratch/tech3b-prepend.php` first; start the server with the ABSOLUTE prepend path; the harness `require_once`s the prepend itself (php -S skips `auto_prepend_file` for router-served requests). The prepend also defines `WCMA_MAIL_LOG`, so no email is sent over SMTP. Verify the default DB counts before and after.

**Files:**
- Create (scratch, untracked): `scratch/tech3b-prepend.php`, `scratch/tech3b-router.php`, `scratch/tech3b-harness.php`, `scratch/tech3b-e2e.js`

- [ ] **Step 1: Record the default database state**

Run (repo root): `php -r '$p=new PDO("sqlite:wcma-calculator/data/submissions.db"); foreach(["users","submissions","tech_sheets","events"] as $t) echo $t,"=",$p->query("SELECT COUNT(*) FROM $t")->fetchColumn(),"\n";'`
Note the four counts for Step 6.

- [ ] **Step 2: Create the harness files**

Create `scratch/tech3b-prepend.php`:

```php
<?php
if (!defined('DB_PATH')) {
    define('DB_PATH', 'C:/dev/wcmaclasscalc/scratch/tech3b-e2e.db');
}
if (!defined('WCMA_MAIL_LOG')) {
    define('WCMA_MAIL_LOG', 'C:/dev/wcmaclasscalc/scratch/tech3b-mail.log');
}
```

Create `scratch/tech3b-router.php`:

```php
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/harness') {
    require __DIR__ . '/tech3b-harness.php';
    return true;
}
return false;
```

Create `scratch/tech3b-harness.php` (seeds once, then signs the browser in; `?as=admin|owner|other`):

```php
<?php
require_once __DIR__ . '/tech3b-prepend.php';
require 'C:/dev/wcmaclasscalc/wcma-calculator/session_bootstrap.php';
require 'C:/dev/wcmaclasscalc/wcma-calculator/db.php';
require 'C:/dev/wcmaclasscalc/wcma-calculator/view_helpers.php';
require 'C:/dev/wcmaclasscalc/wcma-calculator/photo-requirements.php';
require 'C:/dev/wcmaclasscalc/wcma-calculator/inspection-lib.php';
require 'C:/dev/wcmaclasscalc/wcma-calculator/gear-lib.php';

$pdo = db_connect();
db_init($pdo);

function harnessUser(PDO $pdo, string $who, string $role): int {
    $u = db_find_user_by_email($pdo, "$who@example.com");
    if ($u) return (int)$u['id'];
    $id = db_create_user($pdo, ['email' => "$who@example.com", 'name' => ucfirst($who), 'password_hash' => 'x', 'google_id' => null]);
    $pdo->prepare('UPDATE users SET role = :r WHERE id = :id')->execute([':r' => $role, ':id' => $id]);
    return $id;
}

function harnessSubmission(PDO $pdo, int $userId, string $email, string $make): int {
    return db_insert_submission($pdo, [
        ':submitted_at' => date('Y-m-d H:i:s'), ':name' => 'Owner', ':email' => $email,
        ':year' => '2020', ':make' => $make, ':model' => 'Test', ':comments' => null,
        ':competition_weight' => 2200, ':declared_hp' => 150, ':dyno_hp' => null,
        ':chassis_display' => null, ':body_mods_display' => null, ':transmission_display' => null,
        ':drivetrain_display' => null, ':tires_display' => null, ':brake_suspension' => null,
        ':chassis_value' => 0, ':body_mods_value' => 0, ':transmission_value' => 0,
        ':drivetrain_value' => 0, ':tires_value' => 0, ':brake_suspension_value' => 0,
        ':weight_factor' => 0, ':modification_factor' => 0, ':base_ratio' => 14.67, ':modified_ratio' => 14.67,
        ':calculated_class' => 'IT1', ':user_id' => $userId,
    ]);
}

function harnessSheet(PDO $pdo, int $subId, int $userId, int $eventId, string $type, string $driver, string $number): int {
    return db_insert_tech_sheet($pdo, [
        'submission_id' => $subId, 'user_id' => $userId, 'event_id' => $eventId, 'sheet_type' => $type,
        'entrant_name' => 'Entrant', 'driver_name' => $driver, 'car_make' => 'Mazda', 'car_model' => 'MX-5',
        'car_colour' => 'Red', 'car_number' => $number, 'class' => 'IT1', 'engine_cc' => '1800', 'engine_hp' => '150',
        'car_weight' => 2200, 'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1,
    ]);
}

$adminId = harnessUser($pdo, 'admin', 'admin');
$ownerId = harnessUser($pdo, 'owner', 'user');
$otherId = harnessUser($pdo, 'other', 'user');

if (!$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn()) {
    $year = date('Y');
    $eventId = db_create_event($pdo, 'Season Finale', $year . '-11-15', null);

    $ownerSub = harnessSubmission($pdo, $ownerId, 'owner@example.com', 'Mazda');
    $sheetA = harnessSheet($pdo, $ownerSub, $ownerId, $eventId, 'endurance', 'Jane Racer', '42');
    db_add_tech_sheet_driver($pdo, $sheetA, 2, 'Sam Coach', '{}');
    // Car already accepted in person, so only the drivers' gear decides the roster filters.
    $pdo->prepare("UPDATE tech_sheets SET status = 'teched', accepted_via = 'in_person' WHERE id = :id")->execute([':id' => $sheetA]);

    $otherSub = harnessSubmission($pdo, $otherId, 'other@example.com', 'Honda');
    harnessSheet($pdo, $otherSub, $otherId, $eventId, 'standard', 'Solo Driver', '7');

    // Jane has a gear record accepted in person; Sam and Solo have none yet.
    $jane = gearCreate($pdo, $ownerId, 'Jane Racer', '', gearSeasonNow());
    gearAcceptInPerson($pdo, $jane['id'], $adminId);
}

$who = $_GET['as'] ?? 'admin';
$_SESSION['user_id'] = ['admin' => $adminId, 'owner' => $ownerId, 'other' => $otherId][$who] ?? $adminId;
$_SESSION['user_name'] = ucfirst($who);
$_SESSION['user_role'] = $who === 'admin' ? 'admin' : 'user';
generateCsrfToken();
?>
<!doctype html><meta charset="utf-8"><title>tech3b harness</title><p>harness ready: <?= h($who) ?></p>
```

Create `scratch/tech3b-e2e.js`:

```js
const { chromium } = require('C:/dev/wcmaclasscalc/scratch/tech-sheet-mockups/node_modules/playwright');
const assert = require('node:assert');

const BASE = 'http://localhost:8127';

async function signIn(browser, who) {
    const ctx = await browser.newContext({ viewport: { width: 1000, height: 900 } });
    const page = await ctx.newPage();
    await page.goto(BASE + '/harness?as=' + who);
    await page.waitForSelector('text=harness ready');
    return page;
}

(async () => {
    const browser = await chromium.launch();
    const owner = await signIn(browser, 'owner');
    const admin = await signIn(browser, 'admin');
    const year = new Date().getFullYear();

    // ── Admin roster: Gear column and gear-aware filters ─────────────────────
    await admin.goto(BASE + '/admin.php?action=tech-sheets&event=1');
    assert.match(await admin.textContent('#tech-sheets-table thead'), /Gear/);
    assert.strictEqual(await admin.locator('#tech-sheets-table tbody tr').count(), 2);
    const rowA = admin.locator('#tech-sheets-table tbody tr', { hasText: '42' });
    const rowB = admin.locator('#tech-sheets-table tbody tr', { hasText: 'Solo' }).or(admin.locator('#tech-sheets-table tbody tr', { hasText: '#7' })).first();
    let text = await rowA.textContent();
    assert.match(text, new RegExp('Jane Racer: Gear teched ' + year));
    assert.match(text, /Sam Coach: No gear record/);
    assert.match(await admin.textContent('#tech-sheets-table tbody'), /Solo Driver: No gear record/);
    assert.strictEqual(await admin.locator('#tech-sheets-table a[href*="action=gear-record"]').count(), 1, 'one admin gear link (Jane)');

    await admin.goto(BASE + '/admin.php?action=tech-sheets&event=1&filter=needs_tech');
    assert.strictEqual(await admin.locator('#tech-sheets-table tbody tr').count(), 2, 'both need tech: Sam has no gear, Solo has no gear or car');
    await admin.goto(BASE + '/admin.php?action=tech-sheets&event=1&filter=accepted');
    assert.strictEqual(await admin.locator('#tech-sheets-table tbody tr').count(), 0, 'nothing fully accepted yet');
    console.log('roster: Gear column, both rows need tech, none fully accepted');

    // ── Owner: sheet view shows chips; add Sam from the chip ─────────────────
    await owner.goto(BASE + '/account.php');
    const myCars = await owner.textContent('body');
    assert.match(myCars, new RegExp('Jane Racer: Gear teched ' + year));
    assert.match(myCars, /Sam Coach: No gear record/);

    await owner.click('a:has-text("View")');
    await owner.waitForSelector('.gear-chips');
    assert.match(await owner.textContent('.gear-chips'), /Sam Coach: No gear record/);
    await Promise.all([owner.waitForNavigation(), owner.click('.gear-chips a:has-text("Add gear record")')]);
    assert.strictEqual(await owner.inputValue('#gear-driver-name'), 'Sam Coach', 'prefilled from the chip');
    await Promise.all([owner.waitForNavigation(), owner.click('#gear-add-form button[type="submit"]')]);
    assert.match(await owner.textContent('#gear-table'), /Sam Coach/);
    console.log('owner: chips on My Cars and the sheet view; Sam added from the chip with the name prefilled');

    // Sam's chip now links to a gear record that still needs a check
    await owner.goto(BASE + '/account.php');
    await owner.click('a:has-text("View")');
    await owner.waitForSelector('.gear-chips');
    assert.match(await owner.textContent('.gear-chips'), /Sam Coach: Needs gear check at the track/);
    assert.strictEqual(await owner.locator('.gear-chips a[href*="gear.php?action=pretech"]').count(), 2, 'both chips link to the gear pages');

    // ── Admin: accept Sam in person from the roster chip ─────────────────────
    await admin.goto(BASE + '/admin.php?action=tech-sheets&event=1&filter=needs_tech');
    assert.strictEqual(await admin.locator('#tech-sheets-table tbody tr').count(), 2, 'Sam has a record now but it is not accepted');
    await admin.locator('#tech-sheets-table tbody tr', { hasText: '42' }).locator('a:has-text("Needs gear check at the track")').click();
    await admin.waitForSelector('#gear-inperson-btn');
    await Promise.all([admin.waitForNavigation(), admin.click('#gear-inperson-btn')]);
    assert.match(await admin.textContent('body'), /teched in person/i);

    await admin.goto(BASE + '/admin.php?action=tech-sheets&event=1&filter=accepted');
    assert.strictEqual(await admin.locator('#tech-sheets-table tbody tr').count(), 1, 'car accepted and both drivers accepted');
    await admin.goto(BASE + '/admin.php?action=tech-sheets&event=1&filter=needs_tech');
    assert.strictEqual(await admin.locator('#tech-sheets-table tbody tr').count(), 1, 'only the solo sheet still needs tech');
    console.log('admin: accepting Sam moves the sheet from needs-tech to accepted');

    // ── Admin sheet review page shows the driver chips ───────────────────────
    await admin.goto(BASE + '/admin.php?action=tech-sheets&event=1&filter=accepted');
    await admin.click('#tech-sheets-table tbody tr a:has-text("View")');
    await admin.waitForSelector('.gear-chips');
    const review = await admin.textContent('.gear-chips');
    assert.match(review, /Jane Racer: Gear teched/);
    assert.match(review, /Sam Coach: Gear teched/);
    assert.strictEqual(await admin.locator('.gear-chips a[href*="admin.php?action=gear-record"]').count(), 2);

    // ── Sheet form: name suggestions and the added-driver row ────────────────
    await owner.goto(BASE + '/account.php');
    await Promise.all([owner.waitForNavigation(), owner.click('a:has-text("Submit now")').catch(() => owner.click('a[href*="tech-sheets.php?action=new"]'))]).catch(() => {});
    if (!/action=new/.test(owner.url())) {
        await owner.goto(BASE + '/tech-sheets.php?action=new&submission_id=1');
    }
    await owner.waitForSelector('#driver_name');
    assert.strictEqual(await owner.getAttribute('#driver_name', 'list'), 'gear-names');
    const options = await owner.locator('#gear-names option').evaluateAll(els => els.map(e => e.value));
    assert.deepStrictEqual(options.sort(), ['Jane Racer', 'Sam Coach']);
    await owner.selectOption('#sheet_type', 'endurance');
    await owner.click('#add-driver-btn');
    assert.strictEqual(await owner.locator('#additional-drivers-container input[list="gear-names"]').count(), 1, 'added driver rows use the suggestion list');
    console.log('sheet form: suggestions from My Drivers on driver 1 and added driver rows');

    // ── Isolation: the other user sees none of the owner's gear ──────────────
    const other = await signIn(browser, 'other');
    await other.goto(BASE + '/account.php');
    const otherBody = await other.textContent('body');
    assert.doesNotMatch(otherBody, /Jane Racer/);
    assert.doesNotMatch(otherBody, /Sam Coach/);
    assert.match(otherBody, /Solo Driver: No gear record/);

    await browser.close();
    console.log('E2E OK');
})().catch(e => { console.error(e); process.exit(1); });
```

- [ ] **Step 3: Start the server (port 8127, scratch DB and mail log only)**

Run (repo root, in the background): `php -d auto_prepend_file=C:/dev/wcmaclasscalc/scratch/tech3b-prepend.php -S localhost:8127 -t wcma-calculator scratch/tech3b-router.php`
Verify: `curl -s -o /dev/null -w "%{http_code}" "http://localhost:8127/harness?as=admin"` returns `200`; `scratch/tech3b-e2e.db` exists; the default DB counts still match Step 1.

- [ ] **Step 4: Run the end-to-end script**

Run: `node scratch/tech3b-e2e.js`
Expected: output ending with `E2E OK`. If a step fails, decide whether the scratch script or the product is wrong: fix scratch-script mistakes (selectors, timing, the `.or()` locator for the second row, the "Submit now" link fallback) in the scratch script only, and report genuine product defects with evidence (file, line, expected behaviour, assertion output) instead of editing product code in this task.

- [ ] **Step 5: Stop the server and clean up**

Stop the PHP server (find the listener on 8127 with `netstat -ano | grep 8127 | grep LISTENING`, kill that process id, confirm the port is free). Remove `scratch/tech3b-e2e.db*` and `scratch/tech3b-mail.log`. This run uploads no photos. `git status --short` must show only the pre-existing `?? .htaccess.server` and `?? scratch/`.

- [ ] **Step 6: Verify the default database and run the full regression**

Re-run the Step 1 command: the four counts must match, and (if the `gear_records` table exists in the default DB) `SELECT COUNT(*) FROM gear_records` must be `0`. Then from `wcma-calculator/` run `php phpunit.phar` (all tests OK) and `node --test "tests/js/*.test.js"` (`# fail 0`). Nothing to commit for this task.

---

## Self-Review Notes

- **Design coverage:** matching function and season/owner rules (Task 1); suggestions on the form's driver-name fields including dynamically added rows (Task 4, JS line); chips with links to the gear page or an add-record prefill on the competitor sheet view and My Cars (Tasks 3, 4); admin roster gear chips, gear-aware "Needs tech at the track" filter, and chips on the review page linking to the admin gear review (Tasks 2, 5); end-to-end proof (Task 6). No schema change, no new writes.
- **Recorded rulings for the reviewer:** (1) `accepted` on the roster now means car and all drivers' gear accepted, and its complement `needs_tech` widens, so the roster counts change for existing data (every entrant without gear records now needs tech until gear is accepted); (2) `pending_review` also matches gear photos awaiting review; (3) matching uses only the sheet owner's own records, so a co-driver's separate-account record does not link; (4) "Add gear record" uses a GET prefill (`gear.php?name=`) rather than creating a record for the user.
- **Type consistency:** the link shape from `gearLinksForSheet()` (with `status` from `gearStatus()`) is what `renderGearChips()`, `gearAttachToRoster()` and the `techGearLinks*` helpers consume; `gearAttachToRoster()` preserves `sheet` and `status` on each row so `techRosterFilter()`'s `$r['status']['state']` still works; every changed function only gains optional trailing parameters.
- **Known limits:** the competitor and admin pages need `config.php`, so they are verified by source-level tests plus the browser run rather than PHPUnit; a nickname that differs from the gear record name will show "No gear record" until the names match (the suggestion list is the mitigation); legacy sheets without a stored season fall back to the current year.
