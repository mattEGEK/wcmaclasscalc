# TA/Drift Phase 2: Sheet and Gear Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a driver submit, edit, print and pre-tech a **TA/Drift tech sheet**, let inspectors accept it and accept driver gear at **Race** or **TA/Drift** level (with a TA/Drift→Race upgrade on the same record), and make every revoke carry a required note that the owner sees.

**Architecture:** Plain PHP + SQLite, no framework. The form follows the ice sheet's pattern:
- **Pure logic:** `ta-drift-sheet-lib.php` holds the TA/Drift form's parse, validate and row helpers, plus the car's TA/Drift standing.
- **Page:** `ta-drift-sheet-page.php` holds the view model and HTML.
- **Routes:** `tech-sheets.php` gains `new-ta-drift` and `submit-ta-drift`. `edit` and `update` fork on `techSheetIsTaDrift()`.
- **Sheet dispatch:** the checklist, equipment and class-line dispatchers in `ice-sheet-lib.php` gain a TA/Drift branch.
- **Gear:** summer gear records gain a photo list tier (`photo_tier`, `caged`) and use phase 1's `level` column (`NULL` = race, `ta_drift`).
- **Revoke notes:** live in a small `revoke-lib.php`.

**Tech Stack:** PHP 8.3, SQLite (PDO), PHPUnit 10 (`php phpunit.phar`), node --test for JS, Playwright phone audit (`tests/ux`).

**Spec:** `docs/superpowers/specs/2026-09-29-ta-drift-tech-design.md`, §3 (form, review, acceptance and revoke) and §2 `gear_records`. Phase 1 is `docs/superpowers/plans/2026-09-29-ta-drift-phase1-foundations.md`: this plan uses its names exactly.

### Roadmap

| Phase | Delivers | Spec |
|---|---|---|
| 1 — Foundations (done first) | Rules data, photo lists, schema, entry formats, `ta_drift` sheet insert and update, keys, ladder, gear coverage | §1, §2, §3 Entry |
| **2 — Sheet and gear (this plan)** | The TA/Drift form and routes, print and email, inspector review with a TA/Drift chip, gear level on accept, TA/Drift gear photos, upgrading to race gear, and revoking with a required note | §3, §2 gear |
| 3 — Readiness and screens | Readiness (with `suggested`), Home, Garage, Drivers, reminders, the entry format picker, the MotorsportReg types, admin filters | §4, §5 |

## Global Constraints

- **Paths and tests:**
  - All paths are relative to `wcma-calculator/` unless they start with `docs/`.
  - Run PHPUnit from `wcma-calculator/` with `php phpunit.phar`. In Git Bash: `cd /c/dev/wcmaclasscalc/wcma-calculator && php phpunit.phar ...`.
  - Run JS tests with `node --test tests/js/`.
  - The full PHPUnit suite and the JS tests must pass at the end of every task.
- **Branch:**
  - Phase 1 must be in first. Branch `ta-drift-phase2` from `main` once phase 1 is merged, or from `ta-drift-phase1` if it isn't.
  - Commit at the end of every task.
  - Every commit message ends with:
    ```
    Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
    Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5
    ```
- **Naming:** people see "TA/Drift". Code uses `ta_drift` and the phase 1 constants (`SHEET_TYPE_TA_DRIFT`, `TECH_TIER_TA_DRIFT`, `TECH_TIER_RACE`, `GEAR_LEVEL_TA_DRIFT`). Never write "light".
- **Exact copy:**
  - The form opens with: `Check each item on the car itself before you tick it. You're confirming your car is safe to go on track.`
  - The owner's revoke notice reads `Tech revoked: {note}` or `Gear revoked: {note}`.
- **A TA/Drift sheet:**
  - `sheet_type` = `ta_drift`, `discipline` = `summer`, `club` = the event's host club.
  - It has no class, no weight, no HP and no log book.
  - Its drivers are driver 1 plus optional extra drivers in `tech_sheet_drivers`.
  - Each driver's gear is checked against `taDriftEquipmentItems($caged)`.
- **A TA/Drift sheet is only offered for summer events with a host club** (`taDriftOpenEvents()`).
- **Gear level on summer records:**
  - `NULL` is race and `ta_drift` is TA/Drift.
  - Forms post `level=race` or `level=ta_drift`. A blank or missing value means race, so existing callers are unchanged.
- **Photo list tiers:**
  - A summer gear record's photo list is TA/Drift only when its `photo_tier` is `ta_drift`, which photoRequirementsFor() reads from the row.
  - A TA/Drift photo set can only be accepted at TA/Drift. A race upgrade photo set can only be accepted at Race.
- **Revoking needs a note** for every tech sheet and gear record: trimmed, whitespace collapsed, at most 500 characters. Accepting again clears it (phase 1).
- **Race and ice behaviour stays the same,** apart from two changes:
  - Revoking now needs a note.
  - Submitting a sheet tags the event through `eventsTagForSheet()` (a new race entry still starts as race).

## Review Focus

1. **A TA/Drift sheet for an event the car isn't entered in yet** must create a Time Attack entry, not a Race one. Otherwise the to-do list would ask for a race sheet. Test: Task 4, `testNewEntryFromTaDriftSheetIsTimeAttack`.
2. **A car already entered for Race** (or Race+TA) must keep its formats when a TA/Drift sheet is submitted, and must not get the regulations tick. Test: Task 4, `testExistingRaceEntryIsLeftAlone`.
3. **A TA/Drift photo set accepted at Race** must be refused, because nobody checked the race gear list. Test: Task 6, `testTaDriftPhotosCannotBeAcceptedAtRace`.
4. **Gear in the middle of a race upgrade** must keep covering TA/Drift, and must not cover Race until an inspector accepts it. Test: Task 7, `testUpgradeKeepsTaDriftCoverUntilAccepted`.
5. **A revoke with a blank or whitespace-only note** must be refused, with nothing changed: the sheet stays accepted and its signature stays. Test: Task 5, `testBlankNoteIsRefusedAndNothingChanges`.

---

### Task 1: TA/Drift sheet logic and the sheet dispatchers

**Files:**
- Create: `ta-drift-sheet-lib.php`
- Modify: `ice-sheet-lib.php` (the requires, `techSheetChecklistSections`, `techSheetEquipmentItems`, `techSheetClassLine`)
- Modify: `tech-sheet-data.php` (`validateAdditionalDrivers` takes an items list)
- Test: `tests/TaDriftSheetLibTest.php`

**Interfaces:**
- Consumes (phase 1):
  - `taDriftChecklistSections`, `taDriftEquipmentItems`
  - `techSheetIsTaDrift`, `taDriftCarTechStatus`
  - `SHEET_TYPE_TA_DRIFT`, `TECH_TIER_RACE`, `TECH_TIER_TA_DRIFT`
  - `techCarKey`
- Produces:
  - `taDriftOpenEvents(array $events): array`: the summer events that have a host club.
  - `taDriftSheetParsePost(array $post): array` with the keys `caged` (bool), `checklist`, `equipment`, `drivers_input`, `entrant_name`, `driver_name`, `car_number`, `car_colour` and `engine_cc`.
  - `taDriftSheetValidate(array $parsed, string $club): array{error: ?string, drivers: array}`. `drivers` holds the rows ready for `db_replace_tech_sheet_drivers()`.
  - `taDriftSheetRow(array $parsed, array $car): array`: the `db_insert_tech_sheet()` / `db_update_tech_sheet()` columns.
  - `taDriftSheetCarStatus(array $sheet, array $ownerSheets): array{state, via, sheet_id, tier}`
  - `taDriftCarTechStatusLabel(array $status, int $season, string $club): string`
  - `techSheetChecklistSections`, `techSheetEquipmentItems` and `techSheetClassLine` handle `ta_drift` sheets. The class line is `"TA/Drift (WSCC)"`.
  - `validateAdditionalDrivers(array $driversInput, array $items = TECH_DRIVER_EQUIPMENT_ITEMS): ?array`

- [ ] **Step 1: Create the branch**

```bash
cd /c/dev/wcmaclasscalc && git checkout main && git pull --ff-only && git checkout -b ta-drift-phase2
```

(If phase 1 isn't merged yet: `git checkout ta-drift-phase1 && git checkout -b ta-drift-phase2`.)

- [ ] **Step 2: Write the failing test**

```php
<?php
// wcma-calculator/tests/TaDriftSheetLibTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../ice-sheet-lib.php';

final class TaDriftSheetLibTest extends TestCase
{
    private function okChecklist(bool $caged, string $club = 'WSCC'): array {
        return array_map(fn($v): array => ['status' => 'ok'], emptyChecklist(taDriftChecklistSections($caged, $club)));
    }

    private function okEquipment(bool $caged): array {
        $e = emptyDriverEquipment(taDriftEquipmentItems($caged));
        foreach ($e as $k => $v) $e[$k]['competitor_confirmed'] = true;
        $e['helmet']['value'] = 'Snell SA2020';
        return $e;
    }

    private function parsed(array $o = []): array {
        return array_merge([
            'caged' => false, 'checklist' => $this->okChecklist(false), 'equipment' => $this->okEquipment(false),
            'drivers_input' => [], 'entrant_name' => 'Pat Winters', 'driver_name' => 'Pat Winters',
            'car_number' => '86', 'car_colour' => 'White', 'engine_cc' => null,
        ], $o);
    }

    public function testOpenEventsAreSummerEventsWithAHostClub(): void
    {
        $events = [
            ['id' => 1, 'discipline' => 'summer', 'host_club' => 'WSCC'],
            ['id' => 2, 'discipline' => 'summer', 'host_club' => null],
            ['id' => 3, 'discipline' => 'summer', 'host_club' => '  '],
            ['id' => 4, 'discipline' => 'ice', 'host_club' => 'NASCC'],
            ['id' => 5, 'host_club' => 'NASCC'],   // rows from before disciplines are summer
        ];
        $this->assertSame([1, 5], array_column(taDriftOpenEvents($events), 'id'));
    }

    public function testParsePost(): void
    {
        $p = taDriftSheetParsePost([
            'caged' => '1', 'checklist_json' => '{"brakes":{"status":"ok"}}', 'driver1_equipment_json' => 'not json',
            'drivers_json' => '[{"driver_number":2,"driver_name":"Sam","equipment":{}}]',
            'entrant_name' => ' Pat ', 'driver_name' => 'Pat', 'car_number' => '86', 'car_colour' => 'White', 'engine_cc' => '',
        ]);
        $this->assertTrue($p['caged']);
        $this->assertSame(['brakes' => ['status' => 'ok']], $p['checklist']);
        $this->assertSame([], $p['equipment']);
        $this->assertSame('Sam', $p['drivers_input'][0]['driver_name']);
        $this->assertSame('Pat', $p['entrant_name']);
        $this->assertNull($p['engine_cc']);
        $this->assertFalse(taDriftSheetParsePost([])['caged']);
        $this->assertFalse(taDriftSheetParsePost(['caged' => ['1']])['caged']);
    }

    public function testValidateAcceptsACompleteSheet(): void
    {
        $this->assertSame(['error' => null, 'drivers' => []], taDriftSheetValidate($this->parsed(), 'WSCC'));
    }

    public function testValidateRefusesGaps(): void
    {
        $this->assertSame('Please complete every required field.', taDriftSheetValidate($this->parsed(['entrant_name' => '']), 'WSCC')['error']);
        $checklist = $this->okChecklist(false);
        unset($checklist['tow_points']);
        $this->assertSame('Please mark every checklist item OK or N/A.', taDriftSheetValidate($this->parsed(['checklist' => $checklist]), 'WSCC')['error']);
        $equipment = $this->okEquipment(false);
        $equipment['helmet']['value'] = '';
        $this->assertStringContainsString("Driver 1's safety equipment", (string)taDriftSheetValidate($this->parsed(['equipment' => $equipment]), 'WSCC')['error']);
    }

    public function testRegulationsItemCannotBeNotApplicable(): void
    {
        $checklist = $this->okChecklist(false);
        $checklist['supps_read'] = ['status' => 'na'];
        $this->assertSame('Confirm you have read the WSCC supplementary regulations.',
            taDriftSheetValidate($this->parsed(['checklist' => $checklist]), 'WSCC')['error']);
    }

    public function testCagedSheetNeedsTheCageChecksAndARestraint(): void
    {
        // An uncaged checklist is incomplete once the car is caged.
        $this->assertNotNull(taDriftSheetValidate($this->parsed(['caged' => true]), 'WSCC')['error']);
        $ok = $this->parsed(['caged' => true, 'checklist' => $this->okChecklist(true), 'equipment' => $this->okEquipment(true)]);
        $this->assertNull(taDriftSheetValidate($ok, 'WSCC')['error']);
        $ok['equipment']['head_neck_restraints']['competitor_confirmed'] = false;
        $this->assertNotNull(taDriftSheetValidate($ok, 'WSCC')['error']);
    }

    public function testAddedDriversAreCheckedAgainstTheTaDriftGear(): void
    {
        $row = ['driver_number' => 2, 'driver_name' => 'Sam Patel', 'equipment' => $this->okEquipment(false)];
        $r = taDriftSheetValidate($this->parsed(['drivers_input' => [$row]]), 'WSCC');
        $this->assertNull($r['error']);
        $this->assertSame([['driver_number' => 2, 'driver_name' => 'Sam Patel', 'equipment_json' => json_encode($this->okEquipment(false))]], $r['drivers']);

        $row['equipment']['clothing']['competitor_confirmed'] = false;
        $this->assertSame('Please confirm the safety equipment of every added driver.',
            taDriftSheetValidate($this->parsed(['drivers_input' => [$row]]), 'WSCC')['error']);
    }

    public function testRowHasNoClassWeightHpOrLogBook(): void
    {
        $row = taDriftSheetRow($this->parsed(['caged' => true]), ['make' => 'Subaru', 'model' => 'BRZ']);
        $this->assertSame('ta_drift', $row['sheet_type']);
        $this->assertTrue($row['caged']);
        $this->assertSame('', $row['class']);
        $this->assertSame(0, $row['car_weight']);
        $this->assertNull($row['engine_hp']);
        $this->assertNull($row['log_book_turned_in']);
        $this->assertSame('Subaru', $row['car_make']);
    }

    public function testDispatchersFollowTheSheet(): void
    {
        $sheet = ['discipline' => 'summer', 'sheet_type' => 'ta_drift', 'club' => 'NASCC', 'caged' => 1];
        $this->assertSame(taDriftChecklistSections(true, 'NASCC'), techSheetChecklistSections($sheet));
        $this->assertSame(taDriftEquipmentItems(true), techSheetEquipmentItems($sheet));
        $this->assertSame('TA/Drift (NASCC)', techSheetClassLine($sheet));
        $this->assertSame(TECH_CHECKLIST_SECTIONS, techSheetChecklistSections(['discipline' => 'summer', 'sheet_type' => 'standard']));
        $this->assertSame('IT1', techSheetClassLine(['discipline' => 'summer', 'sheet_type' => 'standard', 'class' => 'IT1']));
    }

    public function testCarStatusCountsRaceTechAndOnlyThisClub(): void
    {
        $tad = ['id' => 7, 'car_id' => 3, 'season' => 2026, 'discipline' => 'summer', 'sheet_type' => 'ta_drift', 'club' => 'WSCC',
                'status' => 'submitted', 'photo_status' => null, 'accepted_via' => null];
        $race = ['id' => 2, 'car_id' => 3, 'season' => 2026, 'discipline' => 'summer', 'sheet_type' => 'standard', 'club' => null,
                 'status' => 'teched', 'photo_status' => null, 'accepted_via' => 'in_person'];
        $nascc = ['id' => 5, 'club' => 'NASCC', 'status' => 'teched', 'accepted_via' => 'photos'] + $tad;

        $this->assertSame(['state' => 'none', 'via' => null, 'sheet_id' => null, 'tier' => 'ta_drift'], taDriftSheetCarStatus($tad, [$tad, $nascc]));
        $this->assertSame(['state' => 'accepted', 'via' => 'in_person', 'sheet_id' => 2, 'tier' => 'race'], taDriftSheetCarStatus($tad, [$tad, $race]));
        $this->assertSame('accepted', taDriftSheetCarStatus($nascc, [$tad, $nascc])['state']);
    }

    public function testStatusLabel(): void
    {
        $this->assertSame('Teched 2026 (race)', taDriftCarTechStatusLabel(['state' => 'accepted', 'via' => 'in_person', 'tier' => 'race'], 2026, 'WSCC'));
        $this->assertSame('Pre-teched TA/Drift WSCC 2026', taDriftCarTechStatusLabel(['state' => 'accepted', 'via' => 'photos', 'tier' => 'ta_drift'], 2026, 'WSCC'));
        $this->assertSame('Photos pending review', taDriftCarTechStatusLabel(['state' => 'pending_review', 'via' => null, 'tier' => 'ta_drift'], 2026, 'WSCC'));
    }
}
```

- [ ] **Step 3: Run the test to confirm it fails**

Run: `php phpunit.phar --filter TaDriftSheetLibTest`
Expected: FAIL. `taDriftOpenEvents()` is undefined.

- [ ] **Step 4: Let `validateAdditionalDrivers` take an items list**

In `tech-sheet-data.php`, replace:

```php
function validateAdditionalDrivers(array $driversInput): ?array {
```

with:

```php
function validateAdditionalDrivers(array $driversInput, array $items = TECH_DRIVER_EQUIPMENT_ITEMS): ?array {
```

and inside it replace:

```php
        if (!validateDriverEquipment($equipment)) return null;
```

with:

```php
        if (!validateDriverEquipment($equipment, $items)) return null;
```

Add ` $items (summer by default) is the gear list each driver's equipment is checked against.` to the end of its doc comment.

- [ ] **Step 5: Create `ta-drift-sheet-lib.php`**

```php
<?php
// wcma-calculator/ta-drift-sheet-lib.php
//
// TA/Drift tech sheets (spec 2026-09-29-ta-drift-tech-design.md §3): which events take one, parsing
// and validating the form, the DB row, and the car's TA/Drift standing (race tech also counts).
// Pure: no DB, no HTML.
require_once __DIR__ . '/ta-drift-rules.php';
require_once __DIR__ . '/ta-drift-lib.php';
require_once __DIR__ . '/tech-sheet-data.php';

/** The events a TA/Drift sheet can be for: summer events with a host club (TA/Drift tech is per club). */
function taDriftOpenEvents(array $events): array {
    return array_values(array_filter($events, fn(array $e): bool =>
        ($e['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_SUMMER && trim((string)($e['host_club'] ?? '')) !== ''));
}

/** Decodes and trims the TA/Drift form's POST, after techSheetApplyDriverChoices(). No validation here. */
function taDriftSheetParsePost(array $post): array {
    $str = fn(string $k): string => is_string($post[$k] ?? null) ? trim($post[$k]) : '';
    $json = fn(string $k): array => is_string($post[$k] ?? null) ? (($decoded = json_decode($post[$k], true)) && is_array($decoded) ? $decoded : []) : [];
    return [
        'caged'         => ($post['caged'] ?? null) === '1',
        'checklist'     => $json('checklist_json'),
        'equipment'     => $json('driver1_equipment_json'),
        'drivers_input' => $json('drivers_json'),
        'entrant_name'  => $str('entrant_name'),
        'driver_name'   => $str('driver_name'),
        'car_number'    => $str('car_number'),
        'car_colour'    => $str('car_colour'),
        'engine_cc'     => $str('engine_cc') ?: null,
    ];
}

/**
 * Checks a parsed TA/Drift sheet for $club. On success, 'drivers' holds the added drivers as
 * db_replace_tech_sheet_drivers() rows.
 *
 * @return array{error: ?string, drivers: array}
 */
function taDriftSheetValidate(array $parsed, string $club): array {
    $fail = fn(string $msg): array => ['error' => $msg, 'drivers' => []];
    if ($parsed['entrant_name'] === '' || $parsed['driver_name'] === ''
        || $parsed['car_number'] === '' || $parsed['car_colour'] === '') {
        return $fail('Please complete every required field.');
    }
    $caged = (bool)$parsed['caged'];
    if (!validateChecklist($parsed['checklist'], taDriftChecklistSections($caged, $club))) {
        return $fail('Please mark every checklist item OK or N/A.');
    }
    // The regulations item is a promise, so "N/A" doesn't answer it.
    if (($parsed['checklist']['supps_read']['status'] ?? null) !== 'ok') {
        return $fail('Confirm you have read the ' . $club . ' supplementary regulations.');
    }
    $items = taDriftEquipmentItems($caged);
    if (!validateDriverEquipment($parsed['equipment'], $items)) {
        return $fail("Please confirm Driver 1's safety equipment, including the helmet rating.");
    }
    $drivers = validateAdditionalDrivers($parsed['drivers_input'], $items);
    if ($drivers === null) return $fail('Please confirm the safety equipment of every added driver.');
    return ['error' => null, 'drivers' => $drivers];
}

/**
 * The DB columns shared by submitting and updating a TA/Drift sheet. A TA/Drift sheet has no class,
 * weight, HP or log book. Callers merge in car_id/user_id/event_id (new) or event_id (edit).
 */
function taDriftSheetRow(array $p, array $car): array {
    return [
        'sheet_type' => SHEET_TYPE_TA_DRIFT, 'caged' => (bool)$p['caged'],
        'entrant_name' => $p['entrant_name'], 'driver_name' => $p['driver_name'],
        'car_make' => $car['make'], 'car_model' => $car['model'], 'car_colour' => $p['car_colour'],
        'car_number' => $p['car_number'], 'class' => '',
        'engine_cc' => $p['engine_cc'], 'engine_hp' => null, 'car_weight' => 0,
        'checklist_json' => json_encode($p['checklist']), 'driver1_equipment_json' => json_encode($p['equipment']),
        'log_book_turned_in' => null,
    ];
}

/**
 * The car's standing at this sheet's club and season (spec §2 approval ladder): its summer race tech
 * if accepted, otherwise its TA/Drift sheets for the club. $ownerSheets is every sheet of the owner's.
 *
 * @return array{state: string, via: ?string, sheet_id: ?int, tier: string}
 */
function taDriftSheetCarStatus(array $sheet, array $ownerSheets): array {
    $groups = techGroupSheetsByCar($ownerSheets);
    $raceKey = techCarKey(['car_id' => $sheet['car_id'], 'season' => $sheet['season'], 'discipline' => DISCIPLINE_SUMMER]);
    return taDriftCarTechStatus(techCarStatus($groups[$raceKey] ?? []), techCarStatus($groups[techCarKey($sheet)] ?? []));
}

/** "Teched 2026 (race)", "Pre-teched TA/Drift WSCC 2026", or the photo state for a car not yet accepted. */
function taDriftCarTechStatusLabel(array $status, int $season, string $club): string {
    if (($status['state'] ?? 'none') !== 'accepted') return techCarStatusLabel($status, $season);
    $how = ($status['via'] ?? null) === 'photos' ? 'Pre-teched ' : 'Teched ';
    return ($status['tier'] ?? TECH_TIER_TA_DRIFT) === TECH_TIER_RACE
        ? $how . $season . ' (race)'
        : $how . 'TA/Drift ' . $club . ' ' . $season;
}
```

- [ ] **Step 6: Add the TA/Drift branch to the dispatchers**

In `ice-sheet-lib.php`, after `require_once __DIR__ . '/tech-status.php';`, add:

```php
require_once __DIR__ . '/ta-drift-sheet-lib.php';
```

Replace:

```php
function techSheetChecklistSections(array $sheet): array {
    if (!techSheetIsIce($sheet)) return TECH_CHECKLIST_SECTIONS;
```

with:

```php
function techSheetChecklistSections(array $sheet): array {
    if (techSheetIsTaDrift($sheet)) return taDriftChecklistSections(!empty($sheet['caged']), (string)($sheet['club'] ?? ''));
    if (!techSheetIsIce($sheet)) return TECH_CHECKLIST_SECTIONS;
```

Replace:

```php
function techSheetEquipmentItems(array $sheet): array {
    if (!techSheetIsIce($sheet)) return TECH_DRIVER_EQUIPMENT_ITEMS;
```

with:

```php
function techSheetEquipmentItems(array $sheet): array {
    if (techSheetIsTaDrift($sheet)) return taDriftEquipmentItems(!empty($sheet['caged']));
    if (!techSheetIsIce($sheet)) return TECH_DRIVER_EQUIPMENT_ITEMS;
```

Replace:

```php
/** "LS — Limited Stud (NASCC)" for an ice sheet; the stored class for summer. */
function techSheetClassLine(array $sheet): string {
    $code = (string)($sheet['class'] ?? '');
```

with:

```php
/** "LS — Limited Stud (NASCC)" for an ice sheet; "TA/Drift (WSCC)" for a TA/Drift sheet; the stored class for summer. */
function techSheetClassLine(array $sheet): string {
    if (techSheetIsTaDrift($sheet)) return 'TA/Drift (' . (string)($sheet['club'] ?? '') . ')';
    $code = (string)($sheet['class'] ?? '');
```

- [ ] **Step 7: Run the test to confirm it passes, then run the full suite**

Run: `php phpunit.phar --filter TaDriftSheetLibTest` → PASS. Then `php phpunit.phar` → all PASS.

- [ ] **Step 8: Commit**

```bash
git add ta-drift-sheet-lib.php ice-sheet-lib.php tech-sheet-data.php tests/TaDriftSheetLibTest.php
git commit -m "feat(ta-drift): TA/Drift sheet parsing, validation, row and car standing

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 2: Print, email and page title for a TA/Drift sheet

**Files:**
- Modify: `tech-sheet-render.php` (`renderTechSheetHtml`)
- Modify: `tech-sheet-next.php` (`techSheetViewTitle`)
- Test: `tests/TaDriftSheetRenderTest.php`

**Interfaces:**
- Consumes: the Task 1 dispatchers.
- Produces:
  - `renderTechSheetHtml()` for a `ta_drift` sheet:
    - title `TA/DRIFT VEHICLE INSPECTION FORM`, with club and year in the subtitle
    - a "Roll bar or cage" row
    - no Class, Weight or log book line
    - every added driver's gear
  - `techSheetViewTitle()` starts with `TA/Drift tech sheet`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/TaDriftSheetRenderTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../tech-sheet-render.php';
require_once __DIR__ . '/../tech-sheet-next.php';

final class TaDriftSheetRenderTest extends TestCase
{
    private function sheet(bool $caged): array {
        $checklist = array_map(fn($v): array => ['status' => 'ok'], emptyChecklist(taDriftChecklistSections($caged, 'WSCC')));
        $equipment = emptyDriverEquipment(taDriftEquipmentItems($caged));
        foreach ($equipment as $k => $v) $equipment[$k]['competitor_confirmed'] = true;
        $equipment['helmet']['value'] = 'Snell SA2020';
        return [
            'id' => 4, 'sheet_type' => 'ta_drift', 'discipline' => 'summer', 'club' => 'WSCC', 'season' => 2026, 'caged' => $caged ? 1 : 0,
            'entrant_name' => 'Pat Winters', 'driver_name' => 'Pat Winters', 'car_make' => 'Subaru', 'car_model' => 'BRZ',
            'car_colour' => 'White', 'car_number' => '86', 'class' => '', 'engine_cc' => '1998', 'engine_hp' => null, 'car_weight' => 0,
            'checklist_json' => json_encode($checklist), 'driver1_equipment_json' => json_encode($equipment),
            'log_book_turned_in' => null, 'entrant_signature_path' => null, 'driver_signature_path' => null,
            'tech_signature_path' => null, 'status' => 'submitted',
        ];
    }

    private function event(): array {
        return ['name' => 'WSCC Time Attack', 'event_date' => '2026-07-12'];
    }

    public function testHeaderAndFields(): void
    {
        $html = renderTechSheetHtml($this->sheet(false), [], $this->event());
        $this->assertStringContainsString('TA/DRIFT VEHICLE INSPECTION FORM', $html);
        $this->assertStringContainsString('WSCC Time Attack', $html);
        $this->assertStringContainsString(' · WSCC · 2026', $html);
        $this->assertStringContainsString('<strong>Roll bar or cage:</strong> No', $html);
        $this->assertStringNotContainsString('Car Weight', $html);
        $this->assertStringNotContainsString('<strong>Class:</strong>', $html);
        $this->assertStringNotContainsString('Log Book', $html);
        $this->assertStringContainsString('I have read the WSCC supplementary regulations and my car complies', $html);
        $this->assertStringNotContainsString('Roll bar or cage built to WCMA spec', $html);
    }

    public function testCagedSheetShowsTheCageChecks(): void
    {
        $html = renderTechSheetHtml($this->sheet(true), [], $this->event());
        $this->assertStringContainsString('<strong>Roll bar or cage:</strong> Yes', $html);
        $this->assertStringContainsString('Roll bar or cage built to WCMA spec', $html);
    }

    public function testAddedDriversGearIsShown(): void
    {
        $equipment = emptyDriverEquipment(taDriftEquipmentItems(false));
        $html = renderTechSheetHtml($this->sheet(false), [['driver_number' => 2, 'driver_name' => 'Sam Patel', 'equipment_json' => json_encode($equipment)]], $this->event());
        $this->assertStringContainsString('Driver 2 — Sam Patel', $html);
    }

    public function testRaceSheetIsUnchanged(): void
    {
        $race = ['sheet_type' => 'standard', 'club' => null, 'caged' => 0, 'class' => 'IT1', 'car_weight' => 2200, 'log_book_turned_in' => 1,
                 'checklist_json' => '{}', 'driver1_equipment_json' => '{}'] + $this->sheet(false);
        $html = renderTechSheetHtml($race, [], $this->event());
        $this->assertStringContainsString('>VEHICLE INSPECTION FORM<', $html);
        $this->assertStringContainsString('<strong>Class:</strong> IT1', $html);
        $this->assertStringContainsString('Car Weight', $html);
        $this->assertStringContainsString('Vehicle Log Book Turned In', $html);
    }

    public function testViewTitle(): void
    {
        $this->assertSame('TA/Drift tech sheet — #86 Subaru BRZ — WSCC Time Attack', techSheetViewTitle($this->sheet(false), $this->event()));
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `php phpunit.phar --filter TaDriftSheetRenderTest`
Expected: FAIL. The title is `VEHICLE INSPECTION FORM`, and the Class and Weight rows are shown.

- [ ] **Step 3: Implement the render branch**

In `tech-sheet-render.php` `renderTechSheetHtml()`, replace:

```php
    $isIce = techSheetIsIce($sheet);
```

with:

```php
    $isIce = techSheetIsIce($sheet);
    $isTaDrift = techSheetIsTaDrift($sheet);
```

Replace:

```php
    $out .= '<h1 style="text-align:center;margin-bottom:0.2rem">' . ($isIce ? 'ICE RACE VEHICLE INSPECTION FORM' : 'VEHICLE INSPECTION FORM') . '</h1>';
```

with:

```php
    $heading = $isIce ? 'ICE RACE VEHICLE INSPECTION FORM' : ($isTaDrift ? 'TA/DRIFT VEHICLE INSPECTION FORM' : 'VEHICLE INSPECTION FORM');
    $out .= '<h1 style="text-align:center;margin-bottom:0.2rem">' . $heading . '</h1>';
```

Replace:

```php
    if ($isIce) {
        $subtitle .= ' · ' . h((string)(iceClubLabel((string)($sheet['club'] ?? '')) ?? ($sheet['club'] ?? ''))) . ' · ' . iceSeasonLabel((int)($sheet['season'] ?? 0));
    }
```

with:

```php
    if ($isIce) {
        $subtitle .= ' · ' . h((string)(iceClubLabel((string)($sheet['club'] ?? '')) ?? ($sheet['club'] ?? ''))) . ' · ' . iceSeasonLabel((int)($sheet['season'] ?? 0));
    } elseif ($isTaDrift) {
        $subtitle .= ' · ' . h((string)($sheet['club'] ?? '')) . ' · ' . (int)($sheet['season'] ?? 0);
    }
```

Replace these three lines:

```php
    $out .= '<tr><td><strong>Car Model:</strong> ' . h($sheet['car_model']) . '</td><td><strong>Class:</strong> ' . h(techSheetClassLine($sheet)) . '</td></tr>';
    $out .= '<tr><td><strong>Car Colour:</strong> ' . h($sheet['car_colour']) . '</td><td><strong>Engine:</strong> ' . h(techSheetEngineLine($sheet['engine_cc'] ?? null, $sheet['engine_hp'] ?? null)) . '</td></tr>';
    $out .= '<tr><td><strong>Car Weight:</strong> ' . h((string)$sheet['car_weight']) . ' lbs</td><td></td></tr>';
```

with:

```php
    if ($isTaDrift) {
        // A TA/Drift sheet has no class, weight or HP (TA/Drift spec §3).
        $out .= '<tr><td><strong>Car Model:</strong> ' . h($sheet['car_model']) . '</td><td><strong>Car Colour:</strong> ' . h($sheet['car_colour']) . '</td></tr>';
        $out .= '<tr><td><strong>Roll bar or cage:</strong> ' . (!empty($sheet['caged']) ? 'Yes' : 'No') . '</td><td><strong>Engine:</strong> ' . h(techSheetEngineLine($sheet['engine_cc'] ?? null, null)) . '</td></tr>';
    } else {
        $out .= '<tr><td><strong>Car Model:</strong> ' . h($sheet['car_model']) . '</td><td><strong>Class:</strong> ' . h(techSheetClassLine($sheet)) . '</td></tr>';
        $out .= '<tr><td><strong>Car Colour:</strong> ' . h($sheet['car_colour']) . '</td><td><strong>Engine:</strong> ' . h(techSheetEngineLine($sheet['engine_cc'] ?? null, $sheet['engine_hp'] ?? null)) . '</td></tr>';
        $out .= '<tr><td><strong>Car Weight:</strong> ' . h((string)$sheet['car_weight']) . ' lbs</td><td></td></tr>';
    }
```

Replace:

```php
    if (($sheet['sheet_type'] ?? 'standard') === 'endurance' && !empty($drivers)) {
```

with:

```php
    if (in_array($sheet['sheet_type'] ?? 'standard', ['endurance', SHEET_TYPE_TA_DRIFT], true) && !empty($drivers)) {
```

Replace:

```php
    $out .= '<p>Vehicle Log Book Turned In: <strong>' . (($sheet['log_book_turned_in'] ?? null) === null ? '—' : ((int)$sheet['log_book_turned_in'] === 1 ? 'Yes' : 'No')) . '</strong></p>';
```

with:

```php
    if (!$isTaDrift) {
        $out .= '<p>Vehicle Log Book Turned In: <strong>' . (($sheet['log_book_turned_in'] ?? null) === null ? '—' : ((int)$sheet['log_book_turned_in'] === 1 ? 'Yes' : 'No')) . '</strong></p>';
    }
```

- [ ] **Step 4: Implement the page title**

In `tech-sheet-next.php` `techSheetViewTitle()`, replace:

```php
    $parts = [$ice ? 'Ice tech sheet' : 'Tech sheet',
```

with:

```php
    $kind = $ice ? 'Ice tech sheet' : (($sheet['sheet_type'] ?? '') === 'ta_drift' ? 'TA/Drift tech sheet' : 'Tech sheet');
    $parts = [$kind,
```

- [ ] **Step 5: Run the test to confirm it passes, then run the full suite**

Run: `php phpunit.phar --filter TaDriftSheetRenderTest` → PASS. Then `php phpunit.phar` → all PASS (`TechSheetRenderTest` and `TechSheetNextTest` included).

- [ ] **Step 6: Commit**

```bash
git add tech-sheet-render.php tech-sheet-next.php tests/TaDriftSheetRenderTest.php
git commit -m "feat(ta-drift): print and email a TA/Drift sheet

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 3: The TA/Drift form (view model, HTML and the cage toggle)

**Files:**
- Create: `ta-drift-sheet-page.php`
- Modify: `js/ice-class-picker.js` (`equipmentLabel` takes a reason)
- Modify: `js/tech-sheet-form.js` (the cage toggle block and `headNeckReason`)
- Test: `tests/TaDriftSheetPageTest.php`, `tests/js/ice-class-picker.test.js` (add a test), `tests/js/tech-sheet-form-source.test.js` (add a test)

**Interfaces:**
- Consumes: from Task 1, `taDriftChecklistSections`, `taDriftEquipmentItems` and `taDriftHelmetNote`. Also `techSheetDriver1FormState`, `techSheetDriverChoiceFor`, `techSheetDraftKey`, and `garageCarTitle` / `garageCarSub`.
- Produces:
  - `TA_DRIFT_SHEET_INTRO`
  - `taDriftSheetFormVm(array $car, array $event, array $taEvents, array $ownerDrivers, ?array $sheet, array $sheetDrivers, string $csrf): array`
  - `renderTaDriftTechSheetFormHtml(array $vm): string`
  - Form fields: the POST names match the summer form (`checklist_json`, `driver1_equipment_json`, `drivers_json`, `driver1_choice`, `driver1_new_name`, `entrant_name`, `car_colour`, signatures), plus `caged=1`, `car_id` (new) or `tech_sheet_id` (edit), and `event_id`.

- [ ] **Step 1: Write the failing PHP test**

```php
<?php
// wcma-calculator/tests/TaDriftSheetPageTest.php
use PHPUnit\Framework\TestCase;

// Same page-level requires as tests/IceSheetPageTest.php, because garage-page.php needs them.
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../events-lib.php';
require_once __DIR__ . '/../tech-status.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../gear-chips.php';
require_once __DIR__ . '/../home-page.php';
require_once __DIR__ . '/../garage-lib.php';
require_once __DIR__ . '/../garage-page.php';
require_once __DIR__ . '/../reminders-lib.php';
require_once __DIR__ . '/../ta-drift-sheet-page.php';

final class TaDriftSheetPageTest extends TestCase
{
    private function vm(?array $sheet = null, array $sheetDrivers = []): array {
        $car = ['id' => 3, 'car_number' => '86', 'year' => '2015', 'make' => 'Subaru', 'model' => 'BRZ',
                'colour' => '', 'engine_cc' => '1998', 'archived_at' => null, 'owner_user_id' => 1];
        $event = ['id' => 20, 'name' => 'WSCC Time Attack', 'event_date' => '2026-07-12', 'discipline' => 'summer', 'host_club' => 'WSCC'];
        $other = ['id' => 21, 'name' => 'NASCC TA', 'event_date' => '2026-08-09', 'discipline' => 'summer', 'host_club' => 'NASCC'];
        $drivers = [['id' => 5, 'name' => 'Pat Winters', 'name_norm' => 'pat winters', 'user_id' => 1, 'owner_user_id' => 1],
                    ['id' => 6, 'name' => 'Sam Patel', 'name_norm' => 'sam patel', 'user_id' => null, 'owner_user_id' => 1]];
        return taDriftSheetFormVm($car, $event, [$event, $other], $drivers, $sheet, $sheetDrivers, 'tok');
    }

    public function testVm(): void
    {
        $vm = $this->vm();
        $this->assertSame('WSCC', $vm['club']);
        $this->assertFalse($vm['caged']);
        $this->assertSame(taDriftChecklistSections(true, 'WSCC'), $vm['sections']['on']);
        $this->assertSame(taDriftChecklistSections(false, 'WSCC'), $vm['sections']['off']);
        $this->assertSame([21], array_map(fn($e) => (int)$e['id'], $vm['otherEvents']));
        $this->assertSame('tech-sheets.php?action=submit-ta-drift', $vm['action']);
        $this->assertSame('wcma-tsdraft:1:3:20', $vm['draftKey']);
    }

    public function testNewFormOpensWithTheSafetyLineAndHasTheFields(): void
    {
        $html = renderTaDriftTechSheetFormHtml($this->vm());
        $this->assertSame("Check each item on the car itself before you tick it. You're confirming your car is safe to go on track.", TA_DRIFT_SHEET_INTRO);
        $this->assertStringContainsString(h(TA_DRIFT_SHEET_INTRO), $html);   // the apostrophe is escaped
        $this->assertLessThan(strpos($html, 'WSCC Time Attack'), strpos($html, 'Check each item on the car itself'));
        $this->assertStringContainsString('<input type="hidden" name="car_id" value="3">', $html);
        $this->assertStringContainsString('<input type="hidden" name="event_id" value="20">', $html);
        $this->assertStringContainsString('<input type="checkbox" id="ta_drift_caged" name="caged" value="1">', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=new-ta-drift&amp;car_id=3&amp;event_id=21"', $html);
        $this->assertStringContainsString('id="car_colour"', $html);   // no colour on file yet
        $this->assertStringContainsString('id="endurance-drivers-card"', $html);
        $this->assertStringContainsString('<input type="hidden" id="sheet_type" value="endurance">', $html);
        $this->assertStringContainsString('id="ta-drift-helmet-note"', $html);
        $this->assertStringContainsString('window.TA_DRIFT_SECTIONS', $html);
        $this->assertStringContainsString('window.TA_DRIFT_RENDERED_CAGED = false;', $html);
        $this->assertStringContainsString('window.TECH_SHEET_DRAFT_KEY = "wcma-tsdraft:1:3:20";', $html);
        $this->assertStringContainsString('Submit TA/Drift Tech Sheet', $html);
        $this->assertLessThan(strpos($html, 'js/tech-sheet-form.js'), strpos($html, 'js/ice-class-picker.js'));
    }

    public function testNoClassWeightHpOrLogBook(): void
    {
        $html = renderTaDriftTechSheetFormHtml($this->vm());
        foreach (['name="class"', 'name="car_weight"', 'name="engine_hp"', 'name="log_book_turned_in"'] as $field) {
            $this->assertStringNotContainsString($field, $html);
        }
    }

    public function testEditKeepsTheCageAnswerAndAddedDrivers(): void
    {
        $sheet = ['id' => 9, 'caged' => 1, 'driver_name' => 'Pat Winters', 'entrant_name' => 'Team Pat',
                  'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'entrant_signature_path' => 'x.png', 'driver_signature_path' => null];
        $vm = $this->vm($sheet, [['driver_number' => 2, 'driver_name' => 'Sam Patel', 'equipment_json' => '{"helmet":{"competitor_confirmed":true,"value":"SA2020"}}']]);
        $this->assertTrue($vm['caged']);
        $this->assertSame([], $vm['otherEvents']);
        $this->assertNull($vm['draftKey']);
        $this->assertSame([['driver_number' => 2, 'driver_choice' => '6', 'new_name' => '',
            'equipment' => ['helmet' => ['competitor_confirmed' => true, 'value' => 'SA2020']]]], $vm['existingDrivers']);

        $html = renderTaDriftTechSheetFormHtml($vm);
        $this->assertStringContainsString('<input type="checkbox" id="ta_drift_caged" name="caged" value="1" checked>', $html);
        $this->assertStringContainsString('<input type="hidden" name="tech_sheet_id" value="9">', $html);
        $this->assertStringContainsString('value="Team Pat"', $html);
        $this->assertStringContainsString('window.TA_DRIFT_RENDERED_CAGED = true;', $html);
        $this->assertStringContainsString('window.TECH_SHEET_HAS_ENTRANT_SIGNATURE = true;', $html);
        $this->assertStringContainsString('"driver_choice":"6"', $html);
        $this->assertStringContainsString('Save Changes', $html);
    }

    public function testNamesAreEscaped(): void
    {
        $vm = $this->vm();
        $vm['event']['name'] = '<b>TA</b>';
        $this->assertStringNotContainsString('<b>TA</b>', renderTaDriftTechSheetFormHtml($vm));
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `php phpunit.phar --filter TaDriftSheetPageTest`
Expected: FAIL. `ta-drift-sheet-page.php` doesn't exist.

- [ ] **Step 3: Create `ta-drift-sheet-page.php`**

```php
<?php
// wcma-calculator/ta-drift-sheet-page.php
//
// The TA/Drift tech sheet form (spec 2026-09-29-ta-drift-tech-design.md §3): its view model and HTML.
// It keeps the summer form's element ids so js/tech-sheet-form.js drives it. The "roll bar or cage"
// box swaps in the cage checks, and a hidden #sheet_type of "endurance" turns on the added-drivers
// card. Callers must have loaded view_helpers.php and garage-page.php (garageCarTitle/Sub).
require_once __DIR__ . '/ice-sheet-lib.php';   // techSheetDraftKey(); loads ta-drift-sheet-lib.php

const TA_DRIFT_SHEET_INTRO = "Check each item on the car itself before you tick it. You're confirming your car is safe to go on track.";

/**
 * Everything the TA/Drift form needs. $sheet is null for a new sheet, the tech_sheets row for an edit;
 * $sheetDrivers are that sheet's tech_sheet_drivers rows. $taEvents are taDriftOpenEvents().
 */
function taDriftSheetFormVm(array $car, array $event, array $taEvents, array $ownerDrivers, ?array $sheet, array $sheetDrivers, string $csrf): array {
    $club = (string)$event['host_club'];
    $caged = $sheet !== null && !empty($sheet['caged']);
    $d1 = techSheetDriver1FormState($ownerDrivers, $sheet);
    $existingDrivers = array_map(function (array $d) use ($d1): array {
        $choice = techSheetDriverChoiceFor($d1['ownedById'], (string)$d['driver_name']);
        return [
            'driver_number' => (int)$d['driver_number'],
            'driver_choice' => $choice,
            'new_name' => $choice === 'new' ? (string)$d['driver_name'] : '',
            'equipment' => json_decode((string)($d['equipment_json'] ?? '{}'), true) ?: [],
        ];
    }, $sheetDrivers);
    return [
        'car' => $car, 'event' => $event, 'club' => $club, 'caged' => $caged,
        'otherEvents' => $sheet !== null ? [] : array_values(array_filter($taEvents, fn(array $e): bool => (int)$e['id'] !== (int)$event['id'])),
        'sections' => ['off' => taDriftChecklistSections(false, $club), 'on' => taDriftChecklistSections(true, $club)],
        'helmetNotes' => ['off' => taDriftHelmetNote(false), 'on' => taDriftHelmetNote(true)],
        'equipmentItems' => taDriftEquipmentItems($caged),
        'driver1' => $d1, 'ownerDrivers' => $ownerDrivers, 'existingDrivers' => $existingDrivers,
        'sheet' => $sheet, 'csrf' => $csrf,
        'draftKey' => $sheet === null ? techSheetDraftKey((int)($car['owner_user_id'] ?? 0), (int)$car['id'], (int)$event['id']) : null,
        'action' => $sheet !== null ? 'tech-sheets.php?action=update' : 'tech-sheets.php?action=submit-ta-drift',
    ];
}

function renderTaDriftTechSheetFormHtml(array $vm): string {
    $car = $vm['car'];
    $event = $vm['event'];
    $sheet = $vm['sheet'];
    $isEdit = $sheet !== null;
    $d1 = $vm['driver1'];
    $key = $vm['caged'] ? 'on' : 'off';
    $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $json = fn($v): string => json_encode($v ?: new stdClass(), $flags);

    $out = '<div class="detail-card"><p><strong>' . h(TA_DRIFT_SHEET_INTRO) . '</strong></p></div>'
        . '<form id="tech-sheet-form" method="post" action="' . h($vm['action']) . '">'
        . '<input type="hidden" name="csrf_token" value="' . h($vm['csrf']) . '">'
        . ($isEdit ? '<input type="hidden" name="tech_sheet_id" value="' . (int)$sheet['id'] . '">'
                   : '<input type="hidden" name="car_id" value="' . (int)$car['id'] . '">')
        . '<input type="hidden" name="event_id" value="' . (int)$event['id'] . '">'
        . '<input type="hidden" id="sheet_type" value="endurance">'   // turns on the added-drivers card in tech-sheet-form.js
        . '<input type="hidden" name="checklist_json" id="checklist_json">'
        . '<input type="hidden" name="driver1_equipment_json" id="driver1_equipment_json">'
        . '<input type="hidden" name="drivers_json" id="drivers_json">'
        . '<input type="hidden" name="entrant_signature" id="entrant_signature">'
        . '<input type="hidden" name="driver_signature" id="driver_signature">';

    // Event
    $out .= '<div class="detail-card"><h2>Event</h2><p><strong>' . h((string)$event['name']) . '</strong> — '
        . h(date('M j, Y', strtotime((string)$event['event_date']))) . ' · ' . h($vm['club']) . '</p>'
        . '<p class="form-hint">This sheet is for Time Attack and Drift. It is checked against the ' . h($vm['club'])
        . ' supplementary regulations.</p>';
    if ($vm['otherEvents']) {
        $links = [];
        foreach ($vm['otherEvents'] as $e) {
            $links[] = '<a href="tech-sheets.php?action=new-ta-drift&amp;car_id=' . (int)$car['id'] . '&amp;event_id=' . (int)$e['id'] . '">'
                . h((string)$e['name']) . '</a>';
        }
        $out .= '<p class="form-hint">Other events: ' . implode(' · ', $links) . '</p>';
    }
    $out .= '</div>';

    // Car
    $out .= '<div class="detail-card"><h2>Car</h2><p class="tech-sheet-car"><span class="hub-plate">' . h((string)$car['car_number']) . '</span> '
        . h(garageCarTitle($car)) . (garageCarSub($car) !== '' ? ' · ' . h(garageCarSub($car)) : '') . '</p>'
        . '<p class="form-hint">Car details come from your Garage. <a href="garage.php?car=' . (int)$car['id'] . '">Edit car details</a></p>';
    if (trim((string)($car['colour'] ?? '')) === '') {
        $out .= '<label for="car_colour">Car colour (required)</label><input type="text" id="car_colour" name="car_colour" maxlength="30" required data-message="Enter the car\'s colour.">'
            . '<p class="form-hint">Your car has no colour on file yet. It will be saved to the car.</p>';
    }
    $out .= '<label class="checkbox-label"><input type="checkbox" id="ta_drift_caged" name="caged" value="1"' . ($vm['caged'] ? ' checked' : '') . '> This car has a roll bar or cage</label>'
        . '<p class="form-hint">A roll bar or cage adds its own checks, a 5- or 6-point harness, and a head and neck restraint for every driver.</p></div>';

    // Entrant & driver
    $out .= '<div class="detail-card"><h2>Entrant &amp; Driver</h2><div class="tech-sheet-header-grid">'
        . '<div><label for="entrant_name">Entrant (required)</label><input type="text" id="entrant_name" name="entrant_name" required data-message="Enter the entrant\'s name." value="'
        . h($isEdit ? (string)$sheet['entrant_name'] : (string)($d1['ownedById'][$d1['selfId'] ?? 0]['name'] ?? '')) . '"></div>'
        . '<div><label for="driver1_choice">Driver 1 (required)</label><select id="driver1_choice" name="driver1_choice" required>';
    foreach ($vm['ownerDrivers'] as $d) {
        $out .= '<option value="' . (int)$d['id'] . '"' . ((string)(int)$d['id'] === $d1['choice'] ? ' selected' : '') . '>'
            . h((string)$d['name']) . ((int)$d['id'] === $d1['selfId'] ? ' (you)' : '') . '</option>';
    }
    $out .= '<option value="new"' . ($d1['choice'] === 'new' ? ' selected' : '') . '>+ Add a co-driver</option></select>'
        . '<input type="text" id="driver1_new_name" name="driver1_new_name" maxlength="100" placeholder="Driver\'s name" aria-label="Driver 1 name" data-message="Enter the co-driver\'s name." value="' . h($d1['newName']) . '"></div>'
        . '</div></div>';

    // Checklist, driver 1 gear, other drivers
    $out .= '<div class="detail-card"><h2>Vehicle Checklist</h2><div id="checklist-container"></div></div>'
        . '<div class="detail-card"><h2>Driver Safety Equipment — Driver 1</h2><p class="form-hint" id="ta-drift-helmet-note">' . h($vm['helmetNotes'][$key]) . '</p>'
        . '<div id="equipment-container"></div></div>'
        . '<div class="detail-card" id="endurance-drivers-card"><h2>Other Drivers (optional)</h2>'
        . '<p class="form-hint">Add everyone else driving this car in Time Attack or Drift at this event.</p>'
        . '<div id="additional-drivers-container"></div>'
        . '<button type="button" class="btn btn-secondary" id="add-driver-btn">+ Add Driver</button></div>';

    // Signatures
    $out .= '<div class="detail-card"><h2>Declaration &amp; Signatures</h2>'
        . '<p><em>I hereby stipulate that the above vehicle meets the regulations for the event.</em></p>'
        . ($isEdit ? '<p class="form-hint">Leave the pads blank to keep the signatures already on file.</p>' : '')
        . '<p id="sig-error" class="field-message" hidden></p>'
        . '<label id="entrant-sig-label">Entrant\'s signature</label><div class="sig-pad-wrap"><canvas id="entrant-sig-canvas"></canvas></div>'
        . '<div class="sig-pad-actions"><button type="button" class="link-button" data-clear-sig="entrant">Clear</button></div>'
        . '<div id="driver-sig-block"><label>Driver\'s signature</label><div class="sig-pad-wrap"><canvas id="driver-sig-canvas"></canvas></div>'
        . '<div class="sig-pad-actions"><button type="button" class="link-button" data-clear-sig="driver">Clear</button></div></div></div>';

    $out .= '<div id="tech-sheet-error" class="form-messages error" role="alert" hidden></div>'
        . '<div class="form-actions"><button type="submit" class="btn btn-primary" id="tech-sheet-submit-btn">'
        . ($isEdit ? 'Save Changes' : 'Submit TA/Drift Tech Sheet') . '</button></div></form>';

    $existingChecklist = $isEdit ? (json_decode((string)($sheet['checklist_json'] ?? '{}'), true) ?: []) : [];
    $existingEquipment = $isEdit ? (json_decode((string)($sheet['driver1_equipment_json'] ?? '{}'), true) ?: []) : [];
    $out .= '<script>'
        . 'const TECH_CHECKLIST_SECTIONS = ' . $json($vm['sections'][$key]) . ';'
        . 'const TECH_DRIVER_EQUIPMENT_ITEMS = ' . $json($vm['equipmentItems']) . ';'
        . 'window.TA_DRIFT_SECTIONS = ' . $json($vm['sections']) . ';'
        . 'window.TA_DRIFT_HELMET_NOTES = ' . $json($vm['helmetNotes']) . ';'
        . 'window.TA_DRIFT_RENDERED_CAGED = ' . ($vm['caged'] ? 'true' : 'false') . ';'
        . 'window.TECH_SHEET_EXISTING_CHECKLIST = ' . $json($existingChecklist) . ';'
        . 'window.TECH_SHEET_EXISTING_EQUIPMENT = ' . $json($existingEquipment) . ';'
        . 'window.TECH_SHEET_EXISTING_DRIVERS = ' . json_encode($vm['existingDrivers'], $flags) . ';'
        . 'window.TECH_SHEET_DRIVERS = ' . json_encode($d1['driversForJs'], $flags) . ';'
        . 'window.TECH_SHEET_HAS_ENTRANT_SIGNATURE = ' . ($isEdit && !empty($sheet['entrant_signature_path']) ? 'true' : 'false') . ';'
        . 'window.TECH_SHEET_HAS_DRIVER_SIGNATURE = ' . ($isEdit && !empty($sheet['driver_signature_path']) ? 'true' : 'false') . ';'
        . (($vm['draftKey'] ?? null) !== null ? 'window.TECH_SHEET_DRAFT_KEY = ' . json_encode($vm['draftKey']) . ';' : '')
        . '</script>'
        . '<script src="js/tech-sheet-checklist.js"></script><script src="js/signature-pad.js"></script>'
        . '<script src="js/driver-choice.js"></script><script src="js/ice-class-picker.js"></script>'
        . '<script src="js/form-problems.js"></script><script src="js/tech-sheet-draft.js"></script><script src="js/tech-sheet-form.js"></script>';
    return $out;
}
```

- [ ] **Step 4: Run the PHP test to confirm it passes**

Run: `php phpunit.phar --filter TaDriftSheetPageTest` → PASS.

- [ ] **Step 5: Write the failing JS tests**

Append to `tests/js/ice-class-picker.test.js`:

```js
test('equipmentLabel can say why the item is required', () => {
    assert.strictEqual(equipmentLabel('Head & Neck Restraint', true, 'in a caged car'), 'Head & Neck Restraint (required in a caged car)');
    assert.strictEqual(equipmentLabel('Head & Neck Restraint', false, 'in a caged car'), 'Head & Neck Restraint');
});
```

Append to `tests/js/tech-sheet-form-source.test.js`:

```js
test('the TA/Drift cage box swaps the checklist and the head & neck rule, and syncs once on load', () => {
    assert.match(src, /document\.getElementById\('ta_drift_caged'\)/);
    assert.match(src, /window\.TA_DRIFT_SECTIONS\[cagedBox\.checked \? 'on' : 'off'\]/);
    assert.match(src, /TECH_DRIVER_EQUIPMENT_ITEMS\.head_neck_restraints\.optional = !cagedBox\.checked/);
    assert.match(src, /cagedBox\.checked !== !!window\.TA_DRIFT_RENDERED_CAGED/);
    assert.match(src, /let headNeckReason;/);
});
```

- [ ] **Step 6: Run the JS tests to confirm they fail**

Run: `node --test tests/js/`
Expected: FAIL on the two new tests.

- [ ] **Step 7: Implement the JS**

In `js/ice-class-picker.js`, replace:

```js
    function equipmentLabel(baseLabel, required) {
        return required ? baseLabel + ' (required for this class)' : baseLabel;
    }
```

with:

```js
    /** reason: why it is required, e.g. 'in a caged car' (TA/Drift); defaults to the ice class wording. */
    function equipmentLabel(baseLabel, required, reason) {
        return required ? baseLabel + ' (required ' + (reason || 'for this class') + ')' : baseLabel;
    }
```

In `js/tech-sheet-form.js`, replace:

```js
    let headNeckRequired = false;
```

with:

```js
    let headNeckRequired = false;
    // Why it is required, for the label: the ice class (default) or, on the TA/Drift form, the cage.
    let headNeckReason;
```

Inside `renderEquipmentInto`, replace:

```js
                ? WcmaIceClass.equipmentLabel(def.label, true) : def.label;
```

with:

```js
                ? WcmaIceClass.equipmentLabel(def.label, true, headNeckReason) : def.label;
```

Directly before `const entrantPad = WcmaSignaturePad.attach(`, add:

```js
    // TA/Drift form: a roll bar or cage adds the cage checks and makes the head & neck restraint required.
    const cagedBox = document.getElementById('ta_drift_caged');
    if (cagedBox && window.TA_DRIFT_SECTIONS && window.WcmaIceClass) {
        headNeckReason = 'in a caged car';
        function syncHeadNeck() {
            headNeckRequired = cagedBox.checked;
            TECH_DRIVER_EQUIPMENT_ITEMS.head_neck_restraints.optional = !cagedBox.checked;
            document.querySelectorAll('[data-equipment-key="head_neck_restraints"] .checklist-item-label').forEach(function (el) {
                el.textContent = WcmaIceClass.equipmentLabel(TECH_DRIVER_EQUIPMENT_ITEMS.head_neck_restraints.label, cagedBox.checked, headNeckReason);
            });
            document.getElementById('ta-drift-helmet-note').textContent = (window.TA_DRIFT_HELMET_NOTES || {})[cagedBox.checked ? 'on' : 'off'] || '';
        }
        function onCagedChange(seed) {
            const sections = window.TA_DRIFT_SECTIONS[cagedBox.checked ? 'on' : 'off'];
            const container = document.getElementById('checklist-container');
            // seed: answers from a restored draft (tech-sheet-draft.js), used on the first render only.
            const carried = WcmaIceClass.carryChecklistState(Object.assign({}, seed || {}, checklistWidget.getState()), sections);
            container.innerHTML = '';
            checklistWidget = WcmaTechChecklist.render(container, sections, carried);
            syncHeadNeck();
        }
        cagedBox.addEventListener('change', function () { onCagedChange(); });
        // A browser can restore the box on reload without firing `change` (as with the ice class above).
        if (cagedBox.checked !== !!window.TA_DRIFT_RENDERED_CAGED) onCagedChange(window.TECH_SHEET_EXISTING_CHECKLIST);
        else syncHeadNeck();
    }

```

- [ ] **Step 8: Run all tests**

Run: `node --test tests/js/` → all PASS. Then `php phpunit.phar` → all PASS.

- [ ] **Step 9: Commit**

```bash
git add ta-drift-sheet-page.php js/ice-class-picker.js js/tech-sheet-form.js tests/TaDriftSheetPageTest.php tests/js/ice-class-picker.test.js tests/js/tech-sheet-form-source.test.js
git commit -m "feat(ta-drift): the TA/Drift tech sheet form with the roll bar or cage toggle

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 4: Routes, submitting, editing, and the entry a sheet makes

**Files:**
- Modify: `events-lib.php` (new `eventsTagForSheet`)
- Modify: `tech-sheets.php` (requires, router, `handleNew`, `handleView`, `handleEdit`, `handleSubmit`, `handleUpdate`, `handleSubmitIce`, and new `handleNewTaDrift`, `taDriftSheetReadPost`, `handleSubmitTaDrift` and `handleUpdateTaDrift`)
- Modify: `tests/TechSheetsHandlersTest.php` (`db_tag_event(` → `eventsTagForSheet(`, plus new source checks)
- Test: `tests/EventsTagForSheetTest.php`

**Interfaces:**
- Consumes:
  - Phase 1: `eventsTagCar`, `eventsStoreFormats`, `eventsDefaultFormats`, `db_get_entry`, `entryFormatsParse`, `entryTechTier`
  - Task 1: `taDriftOpenEvents`, `taDriftSheetParsePost`, `taDriftSheetValidate`, `taDriftSheetRow`, `taDriftSheetCarStatus`, `taDriftCarTechStatusLabel`
  - Task 3: `taDriftSheetFormVm`, `renderTaDriftTechSheetFormHtml`
- Produces:
  - `eventsTagForSheet(PDO $pdo, int $userId, array $event, array $car, string $tier): void`
  - Routes:
    - `tech-sheets.php?action=new-ta-drift&car_id=N[&event_id=M]`: with no or an unknown `event_id`, it takes the first open TA/Drift event.
    - `tech-sheets.php?action=submit-ta-drift` (POST)
    - `edit` and `update` also work for TA/Drift sheets. The event stays fixed on edit.
  - `tech-sheets.php?action=new&car_id=N` for a `ta_drift` car with no declaration redirects to `new-ta-drift`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/EventsTagForSheetTest.php
require_once __DIR__ . '/../events-lib.php';

use PHPUnit\Framework\TestCase;

final class EventsTagForSheetTest extends TestCase
{
    /** @return array{0: PDO, 1: int, 2: array, 3: array, 4: array} pdo, user, summer car, TA/Drift-only car, events by name */
    private function world(): array {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'tfs' . uniqid() . '@example.com', 'name' => 'Pat', 'password_hash' => 'x', 'google_id' => null]);
        $car = db_get_car($pdo, db_create_car($pdo, $u, ['car_number' => '42', 'make' => 'Honda', 'model' => 'S2000', 'disciplines' => 'summer']));
        $taCar = db_get_car($pdo, db_create_car($pdo, $u, ['car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'disciplines' => 'ta_drift']));
        $events = [];
        foreach ([['wscc', 'WSCC TA', 'summer', 'WSCC'], ['sprint', 'Sprint', 'summer', null], ['ice', 'Ice', 'ice', 'NASCC']] as [$k, $name, $disc, $club]) {
            $events[$k] = db_get_event($pdo, db_create_event($pdo, $name, '2026-07-12', null, $disc, $club));
        }
        return [$pdo, $u, $car, $taCar, $events];
    }

    private function entry(PDO $pdo, int $u, array $event, array $car): ?array {
        return db_get_entry($pdo, $u, (int)$event['id'], (int)$car['id']);
    }

    public function testNewEntryFromTaDriftSheetIsTimeAttack(): void
    {
        [$pdo, $u, $car, , $e] = $this->world();
        eventsTagForSheet($pdo, $u, $e['wscc'], $car, TECH_TIER_TA_DRIFT);
        $entry = $this->entry($pdo, $u, $e['wscc'], $car);
        $this->assertSame('ta', $entry['formats']);
        $this->assertNotNull($entry['supps_ack_at']);   // submitting the sheet counts as the regulations tick
    }

    public function testNewEntryKeepsItsDefaultsWhenTheyMatchTheSheet(): void
    {
        [$pdo, $u, , $taCar, $e] = $this->world();
        eventsTagCar($pdo, $u, (int)$e['sprint']['id'], (int)$taCar['id']);   // any earlier summer entry ...
        db_set_entry_formats($pdo, $u, (int)$e['sprint']['id'], (int)$taCar['id'], 'drift', null);   // ... that ran Drift
        eventsTagForSheet($pdo, $u, $e['wscc'], $taCar, TECH_TIER_TA_DRIFT);
        $this->assertSame('drift', $this->entry($pdo, $u, $e['wscc'], $taCar)['formats']);
    }

    public function testExistingRaceEntryIsLeftAlone(): void
    {
        [$pdo, $u, $car, , $e] = $this->world();
        eventsTagCar($pdo, $u, (int)$e['wscc']['id'], (int)$car['id'], ['race', 'ta']);
        eventsTagForSheet($pdo, $u, $e['wscc'], $car, TECH_TIER_TA_DRIFT);
        $entry = $this->entry($pdo, $u, $e['wscc'], $car);
        $this->assertSame('race,ta', $entry['formats']);
        $this->assertNull($entry['supps_ack_at']);
    }

    public function testExistingTaDriftEntryGetsTheRegulationsTick(): void
    {
        [$pdo, $u, $car, , $e] = $this->world();
        eventsTagCar($pdo, $u, (int)$e['wscc']['id'], (int)$car['id'], ['drift']);
        eventsTagForSheet($pdo, $u, $e['wscc'], $car, TECH_TIER_TA_DRIFT);
        $entry = $this->entry($pdo, $u, $e['wscc'], $car);
        $this->assertSame('drift', $entry['formats']);
        $this->assertNotNull($entry['supps_ack_at']);
    }

    public function testRaceSheetMakesARaceEntryEvenForATaDriftCar(): void
    {
        [$pdo, $u, , $taCar, $e] = $this->world();
        eventsTagForSheet($pdo, $u, $e['wscc'], $taCar, TECH_TIER_RACE);
        $entry = $this->entry($pdo, $u, $e['wscc'], $taCar);
        $this->assertSame('race', $entry['formats']);
        $this->assertNull($entry['supps_ack_at']);
    }

    public function testRaceAndIceSheetsStillTag(): void
    {
        [$pdo, $u, $car, , $e] = $this->world();
        eventsTagForSheet($pdo, $u, $e['sprint'], $car, TECH_TIER_RACE);
        eventsTagForSheet($pdo, $u, $e['ice'], $car, TECH_TIER_RACE);
        $this->assertSame('race', $this->entry($pdo, $u, $e['sprint'], $car)['formats']);
        $this->assertSame('race', $this->entry($pdo, $u, $e['ice'], $car)['formats']);
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `php phpunit.phar --filter EventsTagForSheetTest`
Expected: FAIL. `eventsTagForSheet()` is undefined.

- [ ] **Step 3: Implement `eventsTagForSheet`**

In `events-lib.php`, directly after `eventsDefaultFormats()`, add:

```php
/**
 * The entry a submitted tech sheet makes (TA/Drift spec §3). It tags the car if it isn't tagged yet.
 * A new entry gets its default formats, unless they would need other tech than this sheet gives:
 * then a TA/Drift sheet enters Time Attack and a race sheet enters Race. An existing entry keeps its
 * formats. Submitting a TA/Drift sheet also counts as the supplementary-regulations tick for a
 * TA/Drift entry.
 */
function eventsTagForSheet(PDO $pdo, int $userId, array $event, array $car, string $tier): void {
    $eventId = (int)$event['id'];
    $carId = (int)$car['id'];
    $entry = db_get_entry($pdo, $userId, $eventId, $carId);
    if ($entry === null) {
        $formats = eventsDefaultFormats($pdo, $car, $event);
        if (entryTechTier($formats) !== $tier) $formats = $tier === TECH_TIER_TA_DRIFT ? ['ta'] : ['race'];
        eventsTagCar($pdo, $userId, $eventId, $carId, $formats, $tier === TECH_TIER_TA_DRIFT);
        return;
    }
    $formats = entryFormatsParse((string)$entry['formats']);
    if ($tier === TECH_TIER_TA_DRIFT && entryTechTier($formats) === TECH_TIER_TA_DRIFT) {
        eventsStoreFormats($pdo, $userId, $eventId, $carId, $formats, true);
    }
}
```

- [ ] **Step 4: Run the test to confirm it passes**

Run: `php phpunit.phar --filter EventsTagForSheetTest` → PASS.

- [ ] **Step 5: Update the handler source test (it fails until Step 6)**

In `tests/TechSheetsHandlersTest.php` `testSubmittingASheetTagsTheEventAndNewCanPreselectIt`, replace `$this->assertStringContainsString('db_tag_event(', $this->body('handleSubmit'));` with:

```php
        foreach (['handleSubmit', 'handleSubmitIce', 'handleSubmitTaDrift'] as $fn) {
            $this->assertStringContainsString('eventsTagForSheet(', $this->body($fn), $fn);
            $this->assertStringNotContainsString('db_tag_event(', $this->body($fn), $fn);
        }
```

Add these methods to the class:

```php
    public function testTaDriftRoutesAndForks(): void
    {
        $src = file_get_contents(__DIR__ . '/../tech-sheets.php');
        $this->assertStringContainsString("case 'new-ta-drift':", $src);
        $this->assertStringContainsString("case 'submit-ta-drift':", $src);
        $this->assertStringContainsString('techSheetIsTaDrift($sheet)', $this->body('handleEdit'));
        $this->assertStringContainsString('handleUpdateTaDrift($pdo, $user, $sheet)', $this->body('handleUpdate'));
        $this->assertStringContainsString('pretechSheetEditable(', $this->body('handleUpdate'));   // checked before the fork
        $this->assertStringContainsString("action=new-ta-drift", $this->body('handleNew'));
    }

    public function testTaDriftSubmitChecksTheEventTheCarAndTheSheet(): void
    {
        $body = $this->body('handleSubmitTaDrift');
        $this->assertStringContainsString('taDriftOpenEvents([$event])', $body);
        $this->assertStringContainsString('db_get_user_car(', $body);
        $this->assertStringContainsString('taDriftSheetValidate(', $body);
        $this->assertStringContainsString("db_replace_tech_sheet_drivers(\$pdo, \$id, \$valid['drivers'])", $body);
        $this->assertStringContainsString('TECH_TIER_TA_DRIFT', $body);
        $update = $this->body('handleUpdateTaDrift');
        $this->assertStringContainsString("'event_id' => (int)\$sheet['event_id']", $update);   // the event stays fixed
        $this->assertStringContainsString("db_replace_tech_sheet_drivers(\$pdo, \$id, \$valid['drivers'])", $update);
    }
```

- [ ] **Step 6: Implement the routes and handlers**

In `tech-sheets.php`, after `require __DIR__ . '/ice-sheet-page.php';`, add:

```php
require __DIR__ . '/ta-drift-sheet-page.php';
```

In the router, directly after the `case 'submit-ice':` block (ending `break;`), add:

```php
    case 'new-ta-drift':
        $user = requireTechSheetLogin();
        handleNewTaDrift($pdo, $user, (int)($_GET['car_id'] ?? 0), (int)($_GET['event_id'] ?? 0));
        break;

    case 'submit-ta-drift':
        $user = requireTechSheetLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: garage.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleSubmitTaDrift($pdo, $user);
        break;
```

In `handleNew`, replace:

```php
    $declaration = db_get_car_current_declaration($pdo, $carId);
    if (!$declaration) {
        setFlash('Declare a class for this car before submitting a tech sheet.', 'error');
```

with:

```php
    $declaration = db_get_car_current_declaration($pdo, $carId);
    if (!$declaration && ($car['disciplines'] ?? null) === 'ta_drift') {
        // A TA/Drift-only car has no class declaration: it takes the TA/Drift sheet.
        header('Location: tech-sheets.php?action=new-ta-drift&car_id=' . $carId . ($eventId > 0 ? '&event_id=' . $eventId : ''));
        exit;
    }
    if (!$declaration) {
        setFlash('Declare a class for this car before submitting a tech sheet.', 'error');
```

In `handleView`, replace:

```php
    $carStatus = techCarStatusForSheet($sheet, db_get_user_tech_sheets($pdo, (int)$user['id']));
```

with:

```php
    $ownerSheets = db_get_user_tech_sheets($pdo, (int)$user['id']);
    $carStatus = techSheetIsTaDrift($sheet) ? taDriftSheetCarStatus($sheet, $ownerSheets) : techCarStatusForSheet($sheet, $ownerSheets);
    $statusLabel = techSheetIsTaDrift($sheet)
        ? taDriftCarTechStatusLabel($carStatus, (int)($sheet['season'] ?? date('Y')), (string)$sheet['club'])
        : techCarStatusLabel($carStatus, (int)($sheet['season'] ?? date('Y')), (string)($sheet['discipline'] ?? 'summer'));
```

and replace:

```php
  <p class="no-print">Car status: <span class="hub-status <?= h(homeStatusClass($carStatus['state'])) ?>"><?= h(techCarStatusLabel($carStatus, (int)($sheet['season'] ?? date('Y')), (string)($sheet['discipline'] ?? 'summer'))) ?></span></p>
```

with:

```php
  <p class="no-print">Car status: <span class="hub-status <?= h(homeStatusClass($carStatus['state'])) ?>"><?= h($statusLabel) ?></span></p>
```

In `handleEdit`, directly before `if (techSheetIsIce($sheet)) {`, add:

```php
    if (techSheetIsTaDrift($sheet)) {
        $event = db_get_event($pdo, (int)$sheet['event_id']) ?? ['id' => (int)$sheet['event_id'], 'name' => '', 'event_date' => date('Y-m-d')];
        $event['host_club'] = (string)$sheet['club'];
        renderPageStart('Edit TA/Drift Tech Sheet', 'garage', ['flash' => getFlash(), 'subnav' => '<a href="tech-sheets.php?action=view&amp;id=' . $id . '">&larr; Back to the sheet</a>']);
        echo renderTaDriftTechSheetFormHtml(taDriftSheetFormVm($car, $event, [], db_get_user_drivers($pdo, (int)$user['id']), $sheet,
            db_get_tech_sheet_drivers($pdo, $id), generateCsrfToken()));
        renderPageEnd();
        return;
    }

```

In `handleSubmit`, replace:

```php
    db_tag_event($pdo, (int)$user['id'], $eventId, $carId);
```

with:

```php
    eventsTagForSheet($pdo, (int)$user['id'], $event, $car, TECH_TIER_RACE);
```

In `handleSubmitIce`, replace:

```php
    db_tag_event($pdo, (int)$user['id'], $eventId, $carId);
```

with:

```php
    eventsTagForSheet($pdo, (int)$user['id'], $event, $car, TECH_TIER_RACE);
```

In `handleUpdate`, replace:

```php
    if (techSheetIsIce($sheet)) {
        handleUpdateIce($pdo, $user, $sheet);
        return;
    }
```

with:

```php
    if (techSheetIsIce($sheet)) {
        handleUpdateIce($pdo, $user, $sheet);
        return;
    }
    if (techSheetIsTaDrift($sheet)) {
        handleUpdateTaDrift($pdo, $user, $sheet);
        return;
    }
```

Directly before `function handleResendTechSheet(`, add:

```php
/** The TA/Drift sheet form for one of the user's cars, at an open summer event with a host club (the first one, unless $eventId picks another). */
function handleNewTaDrift(PDO $pdo, array $user, int $carId, int $eventId): void {
    $car = db_get_user_car($pdo, (int)$user['id'], $carId);
    if (!$car || $car['archived_at'] !== null) {
        setFlash('Choose one of your cars for the TA/Drift tech sheet.', 'error');
        header('Location: garage.php');
        exit;
    }
    $events = taDriftOpenEvents(db_get_active_events($pdo, DISCIPLINE_SUMMER));
    if (!$events) {
        setFlash('There are no events with a host club open for TA/Drift tech sheets yet.', 'error');
        header('Location: garage.php?car=' . $carId);
        exit;
    }
    $event = $events[0];
    foreach ($events as $e) {
        if ((int)$e['id'] === $eventId) $event = $e;
    }
    renderPageStart('TA/Drift tech sheet', 'garage', ['flash' => getFlash(), 'subnav' => '<a href="garage.php?car=' . $carId . '">&larr; Back to the car</a>']);
    echo renderTaDriftTechSheetFormHtml(taDriftSheetFormVm($car, $event, $events, db_get_user_drivers($pdo, (int)$user['id']), null, [], generateCsrfToken()));
    renderPageEnd();
}

/** Parses (but does not validate) a TA/Drift form POST. @return array{ok: bool, error: ?string, parsed: ?array, snap: ?array} */
function taDriftSheetReadPost(PDO $pdo, array $user, array $car): array {
    $fail = fn(string $m): array => ['ok' => false, 'error' => $m, 'parsed' => null, 'snap' => null];
    $snap = carsSheetSnapshot($car, $_POST);
    if (!$snap['ok']) return $fail((string)$snap['error']);
    $owned = [];
    foreach (db_get_user_drivers($pdo, (int)$user['id']) as $d) $owned[(int)$d['id']] = $d;
    // 'endurance' makes techSheetApplyDriverChoices() read the added drivers; the sheet itself stays ta_drift.
    $choices = techSheetApplyDriverChoices(array_merge($_POST, ['sheet_type' => 'endurance']), $owned);
    if (!$choices['ok']) return $fail((string)$choices['error']);
    $parsed = taDriftSheetParsePost(array_merge($choices['post'], [
        'car_number' => $snap['car_number'], 'car_colour' => $snap['car_colour'], 'engine_cc' => (string)($snap['engine_cc'] ?? ''),
    ]));
    return ['ok' => true, 'error' => null, 'parsed' => $parsed, 'snap' => $snap];
}

function handleSubmitTaDrift(PDO $pdo, array $user): void {
    $carId = (int)($_POST['car_id'] ?? 0);
    $eventId = (int)($_POST['event_id'] ?? 0);
    $back = 'tech-sheets.php?action=new-ta-drift&car_id=' . $carId . '&event_id=' . $eventId;
    $car = db_get_user_car($pdo, (int)$user['id'], $carId);
    if (!$car || $car['archived_at'] !== null) {
        setFlash('Choose one of your cars for the TA/Drift tech sheet.', 'error');
        header('Location: garage.php');
        exit;
    }
    $event = db_get_event($pdo, $eventId);
    if (!$event || (int)$event['active'] !== 1 || taDriftOpenEvents([$event]) === []) {
        setFlash('Please choose an open event with a host club.', 'error');
        header('Location: garage.php?car=' . $carId);
        exit;
    }

    $read = taDriftSheetReadPost($pdo, $user, $car);
    if (!$read['ok']) {
        setFlash((string)$read['error'], 'error');
        header('Location: ' . $back);
        exit;
    }
    $p = $read['parsed'];
    $valid = taDriftSheetValidate($p, (string)$event['host_club']);
    if ($valid['error'] !== null) {
        setFlash($valid['error'], 'error');
        header('Location: ' . $back);
        exit;
    }
    try {
        $id = db_insert_tech_sheet($pdo, array_merge(taDriftSheetRow($p, $car), [
            'car_id' => $carId, 'user_id' => $user['id'], 'event_id' => $eventId,
        ]));
    } catch (InvalidArgumentException $e) {
        setFlash($e->getMessage(), 'error');
        header('Location: ' . $back);
        exit;
    }
    db_replace_tech_sheet_drivers($pdo, $id, $valid['drivers']);

    if ($read['snap']['colour_for_car'] !== null) db_update_car($pdo, $carId, ['colour' => $read['snap']['colour_for_car']]);
    eventsTagForSheet($pdo, (int)$user['id'], $event, $car, TECH_TIER_TA_DRIFT);
    iceSheetSaveSignatures($pdo, $id);   // saves the posted entrant/driver signatures (shared with the ice form)

    $sheet = db_get_tech_sheet($pdo, $id);
    $recipient = techSheetRecipientEmail($pdo, $sheet);
    $sent = $recipient !== null && sendTechSheetConfirmationEmail($sheet, db_get_tech_sheet_drivers($pdo, $id), $event, $recipient, $p['entrant_name']);
    db_update_email_sent_tech_sheet($pdo, $id, $sent ? 1 : 0);
    setFlash('TA/Drift tech sheet submitted' . ($sent ? ' and emailed to you and the club.' : ', but the confirmation email failed to send.'), $sent ? 'success' : 'error');
    header('Location: tech-sheets.php?action=view&id=' . $id);
    exit;
}

/** Update for a TA/Drift sheet. The caller has already checked ownership, status and the photo edit lock. The event stays fixed. */
function handleUpdateTaDrift(PDO $pdo, array $user, array $sheet): void {
    $id = (int)$sheet['id'];
    $back = 'tech-sheets.php?action=edit&id=' . $id;
    $car = db_get_user_car($pdo, (int)$user['id'], (int)$sheet['car_id']);
    if ($car === null) {
        setFlash('Car not found.', 'error');
        header('Location: garage.php');
        exit;
    }
    $read = taDriftSheetReadPost($pdo, $user, $car);
    if (!$read['ok']) {
        setFlash((string)$read['error'], 'error');
        header('Location: ' . $back);
        exit;
    }
    $p = $read['parsed'];
    $valid = taDriftSheetValidate($p, (string)$sheet['club']);
    if ($valid['error'] !== null) {
        setFlash($valid['error'], 'error');
        header('Location: ' . $back);
        exit;
    }
    try {
        db_update_tech_sheet($pdo, $id, array_merge(taDriftSheetRow($p, $car), ['event_id' => (int)$sheet['event_id']]));
    } catch (InvalidArgumentException $e) {
        setFlash($e->getMessage(), 'error');
        header('Location: ' . $back);
        exit;
    }
    db_replace_tech_sheet_drivers($pdo, $id, $valid['drivers']);
    if ($read['snap']['colour_for_car'] !== null) db_update_car($pdo, (int)$car['id'], ['colour' => $read['snap']['colour_for_car']]);
    iceSheetSaveSignatures($pdo, $id);

    $updated = db_get_tech_sheet($pdo, $id);
    $event = db_get_event($pdo, (int)$sheet['event_id']) ?? [];
    $recipient = techSheetRecipientEmail($pdo, $updated);
    $sent = $recipient !== null && sendTechSheetConfirmationEmail($updated, db_get_tech_sheet_drivers($pdo, $id), $event, $recipient, $p['entrant_name']);
    db_update_email_sent_tech_sheet($pdo, $id, $sent ? 1 : 0);
    setFlash('TA/Drift tech sheet updated' . ($sent ? ' and re-emailed to you and the club.' : ', but the confirmation email failed to send.'), $sent ? 'success' : 'error');
    header('Location: tech-sheets.php?action=view&id=' . $id);
    exit;
}

```

- [ ] **Step 7: Run the tests, then check it by hand**

Run: `php phpunit.phar --filter "EventsTagForSheetTest|TechSheetsHandlersTest"` → PASS. Then `php phpunit.phar` → all PASS.

Check it by hand. Seed a scratch database as `tests/ux/run-audit.sh` does, then run `php -S localhost:8171` from `wcma-calculator/` with that prepend.
1. Add a summer event with a host club under Admin, Events.
2. Sign in as `jordan@example.com` and open `tech-sheets.php?action=new-ta-drift&car_id=<a car id>`.
3. Tick "This car has a roll bar or cage". The Roll Bar or Cage checks appear, and the head and neck restraint label ends "(required in a caged car)".
4. Fill in the form and submit. The view shows "TA/DRIFT VEHICLE INSPECTION FORM" and the status "Needs tech at the track".
5. Edit the sheet, then save.

- [ ] **Step 8: Commit**

```bash
git add events-lib.php tech-sheets.php tests/EventsTagForSheetTest.php tests/TechSheetsHandlersTest.php
git commit -m "feat(ta-drift): submit and edit TA/Drift tech sheets; sheets tag the event with matching formats

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 5: Revoking needs a note, and the owner sees it

**Files:**
- Create: `revoke-lib.php`
- Modify: `tech-review-lib.php` (`techReviewRevoke`)
- Modify: `gear-lib.php` (the requires, `gearRevoke`)
- Modify: `admin-tech-sheets.php` (`handleTechSheetRevoke`, the revoke form, the earlier-note line)
- Modify: `admin-gear.php` (`handleGearAdminRevoke`, the revoke form, the earlier-note line)
- Modify: `tech-sheets.php` (a require, and the notice in `handleView`)
- Modify: `gear-page.php` (the notice)
- Modify: `tests/TechReviewLibTest.php`, `tests/GearLibTest.php` (revoke calls pass a note)
- Test: `tests/RevokeNoteTest.php`

**Interfaces:**
- Consumes (phase 1): `db_revoke_tech_sheet_acceptance($pdo, $id, ?string $note)` and `db_revoke_gear_acceptance($pdo, $id, ?string $note)`. Accepting clears `revoke_note`.
- Produces:
  - `REVOKE_NOTE_MAX = 500`, `REVOKE_NOTE_REQUIRED`
  - `revokeNoteClean(mixed $note): ?string`
  - `revokeNoticeHtml(?string $note, string $what): string` (`$what` is `'Tech'` or `'Gear'`)
  - `techReviewRevoke(PDO $pdo, string $baseDir, int $sheetId, mixed $note): array{ok, error}`: the note is now required.
  - `gearRevoke(PDO $pdo, int $id, mixed $note): array{ok, error}`: the note is now required, and the level is cleared for every discipline.
  - The inspector forms post `revoke_note`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/RevokeNoteTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../tech-sheet-files.php';
require_once __DIR__ . '/../tech-review-lib.php';
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../gear-lib.php';

use PHPUnit\Framework\TestCase;

final class RevokeNoteTest extends TestCase
{
    public function testClean(): void
    {
        $this->assertNull(revokeNoteClean(null));
        $this->assertNull(revokeNoteClean("  \n\t "));
        $this->assertNull(revokeNoteClean(['Car changed']));
        $this->assertSame('Car changed: new engine', revokeNoteClean("  Car   changed:\nnew engine "));
        $this->assertSame(REVOKE_NOTE_MAX, mb_strlen(revokeNoteClean(str_repeat('é', 600)), 'UTF-8'));
    }

    public function testNotice(): void
    {
        $this->assertSame('', revokeNoticeHtml(null, 'Tech'));
        $this->assertSame('', revokeNoticeHtml('  ', 'Gear'));
        $html = revokeNoticeHtml('Car changed <engine>', 'Tech');
        $this->assertStringContainsString('<strong>Tech revoked:</strong> Car changed &lt;engine&gt;', $html);
    }

    public function testBlankNoteIsRefusedAndNothingChanges(): void
    {
        $pdo = make_temp_pdo();
        $dir = sys_get_temp_dir() . '/wcma_revoke_' . uniqid();
        mkdir($dir);
        $u = db_create_user($pdo, ['email' => 'rv' . uniqid() . '@example.com', 'name' => 'Pat', 'password_hash' => 'x', 'google_id' => null]);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42'));
        $sheetId = test_make_sheet($pdo, $u, $sub, db_create_event($pdo, 'Sprint', '2026-05-10', null));
        $this->assertTrue(db_accept_tech_sheet_in_person($pdo, $sheetId, $u, 'uploads/sig.png'));

        $r = techReviewRevoke($pdo, $dir, $sheetId, "   ");
        $this->assertSame(['ok' => false, 'error' => REVOKE_NOTE_REQUIRED], $r);
        $sheet = db_get_tech_sheet($pdo, $sheetId);
        $this->assertSame('teched', $sheet['status']);
        $this->assertSame('uploads/sig.png', $sheet['tech_signature_path']);

        $this->assertTrue(techReviewRevoke($pdo, $dir, $sheetId, 'Car changed: new engine')['ok']);
        $this->assertSame('Car changed: new engine', db_get_tech_sheet($pdo, $sheetId)['revoke_note']);
    }

    public function testGearRevokeNeedsANote(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'rg' . uniqid() . '@example.com', 'name' => 'Pat', 'password_hash' => 'x', 'google_id' => null]);
        $id = (int)gearCreate($pdo, $u, 'Pat Winters', '', 2026)['id'];
        $this->assertTrue(gearAcceptInPerson($pdo, $id, $u, GEAR_LEVEL_TA_DRIFT)['ok']);

        $this->assertSame(REVOKE_NOTE_REQUIRED, gearRevoke($pdo, $id, '')['error']);
        $this->assertSame('accepted', db_get_gear_record($pdo, $id)['status']);

        $this->assertTrue(gearRevoke($pdo, $id, 'Helmet expired')['ok']);
        $row = db_get_gear_record($pdo, $id);
        $this->assertSame('Helmet expired', $row['revoke_note']);
        $this->assertNull($row['level']);   // a revoked record starts again with no level
    }

    public function testFormsPostTheNoteAndOwnersSeeIt(): void
    {
        $techAdmin = file_get_contents(__DIR__ . '/../admin-tech-sheets.php');
        $gearAdmin = file_get_contents(__DIR__ . '/../admin-gear.php');
        foreach ([$techAdmin, $gearAdmin] as $src) {
            $this->assertStringContainsString('name="revoke_note" maxlength="500" rows="2" required', $src);
            $this->assertStringContainsString("\$_POST['revoke_note'] ?? null", $src);
        }
        $this->assertStringContainsString("revokeNoticeHtml(\$sheet['revoke_note'] ?? null, 'Tech')", file_get_contents(__DIR__ . '/../tech-sheets.php'));
        $this->assertStringContainsString("revokeNoticeHtml(\$gear['revoke_note'] ?? null, 'Gear')", file_get_contents(__DIR__ . '/../gear-page.php'));
    }
}
```

(`gearAcceptInPerson($pdo, $id, $u, GEAR_LEVEL_TA_DRIFT)` passes only after Task 6. Until then, this test's gear part fails with "Choose the gear level", which is expected. Task 6 makes it pass. If you run Task 5 alone, temporarily call `gearAcceptInPerson($pdo, $id, $u)` and drop the level assertion, then restore both in Task 6 Step 1.)

- [ ] **Step 2: Run the test to confirm it fails**

Run: `php phpunit.phar --filter RevokeNoteTest`
Expected: FAIL. `revokeNoteClean()` is undefined.

- [ ] **Step 3: Create `revoke-lib.php`**

```php
<?php
// wcma-calculator/revoke-lib.php
//
// Why an inspector revoked an acceptance (TA/Drift spec §3): a required note, stored on the tech sheet
// or gear record and shown to its owner until it is accepted again. revokeNoticeHtml() needs h()
// (view_helpers.php) loaded by the caller.

const REVOKE_NOTE_MAX = 500;
const REVOKE_NOTE_REQUIRED = 'Say why you are revoking this acceptance. The owner sees this note.';

/** The note trimmed, with whitespace collapsed and capped at REVOKE_NOTE_MAX characters; null when missing or blank. */
function revokeNoteClean(mixed $note): ?string {
    if (!is_string($note)) return null;
    $note = trim((string)preg_replace('/\s+/u', ' ', $note));
    return $note === '' ? null : mb_substr($note, 0, REVOKE_NOTE_MAX, 'UTF-8');
}

/** The owner's notice ("Tech revoked: …" / "Gear revoked: …"), or '' when there is no note. */
function revokeNoticeHtml(?string $note, string $what): string {
    $note = trim((string)$note);
    if ($note === '') return '';
    return '<div class="form-messages show error" role="status"><strong>' . h($what) . ' revoked:</strong> ' . h($note) . '</div>';
}
```

- [ ] **Step 4: Require the note in the review functions**

In `tech-review-lib.php`, after the header comment, add:

```php
require_once __DIR__ . '/revoke-lib.php';
```

Replace `techReviewRevoke()` with:

```php
/**
 * Undo an acceptance, for example when an inspector accepted the wrong car, or when the car has
 * changed substantially. The sheet returns to 'submitted', its inspector signature is removed, and
 * $note (required) is kept for the owner to see.
 *
 * @return array{ok: bool, error: ?string}
 */
function techReviewRevoke(PDO $pdo, string $baseDir, int $sheetId, mixed $note): array {
    $sheet = db_get_tech_sheet($pdo, $sheetId);
    if ($sheet === null) return ['ok' => false, 'error' => 'Tech sheet not found.'];
    $clean = revokeNoteClean($note);
    if ($clean === null) return ['ok' => false, 'error' => REVOKE_NOTE_REQUIRED];

    $signaturePath = $sheet['tech_signature_path'] ?? null;
    if (!db_revoke_tech_sheet_acceptance($pdo, $sheetId, $clean)) {
        return ['ok' => false, 'error' => 'This sheet has not been accepted.'];
    }
    techSheetDeleteSignature($baseDir, $signaturePath);
    return ['ok' => true, 'error' => null];
}
```

In `gear-lib.php`, after `require_once __DIR__ . '/ta-drift-lib.php';` (added in phase 1), add:

```php
require_once __DIR__ . '/revoke-lib.php';
```

Replace `gearRevoke()` from its doc comment down to its opening transaction:

```php
/** Undo an acceptance (for example the wrong driver was accepted). @return array{ok: bool, error: ?string} */
function gearRevoke(PDO $pdo, int $id): array {
    $gear = db_get_gear_record($pdo, $id);
    if ($gear === null) return ['ok' => false, 'error' => 'Gear record not found.'];
    $viaPhotos = ($gear['accepted_via'] ?? null) === 'photos';
```

with:

```php
/** Undo an acceptance (for example the wrong driver was accepted). $note (required) is kept for the owner to see. @return array{ok: bool, error: ?string} */
function gearRevoke(PDO $pdo, int $id, mixed $note): array {
    $gear = db_get_gear_record($pdo, $id);
    if ($gear === null) return ['ok' => false, 'error' => 'Gear record not found.'];
    $clean = revokeNoteClean($note);
    if ($clean === null) return ['ok' => false, 'error' => REVOKE_NOTE_REQUIRED];
    $viaPhotos = ($gear['accepted_via'] ?? null) === 'photos';
```

Inside it, replace:

```php
        if (!db_revoke_gear_acceptance($pdo, $id)) {
```

with:

```php
        if (!db_revoke_gear_acceptance($pdo, $id, $clean)) {
```

and replace:

```php
        if (($gear['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_ICE) db_set_gear_level($pdo, $id, null);
```

with:

```php
        db_set_gear_level($pdo, $id, null);   // ice or TA/Drift: the next acceptance sets the level again
```

- [ ] **Step 5: Update the existing callers in the tests**

In `tests/TechReviewLibTest.php`, change each of the four calls `techReviewRevoke($pdo, $this->dir, $sheetId)` and `techReviewRevoke($pdo, $this->dir, 99999)` to pass a fourth argument `'Wrong car'`.

In `tests/GearLibTest.php`, change every `gearRevoke($pdo, $id)` and `gearRevoke($pdo, 99999)` (six calls, including the one in `testMessagesAvoidBannedWording`) to pass a third argument `'Wrong driver'`.

- [ ] **Step 6: Update the inspector handlers and forms**

In `admin-tech-sheets.php`, replace:

```php
    $result = techReviewRevoke($pdo, __DIR__, $id);
```

with:

```php
    $result = techReviewRevoke($pdo, __DIR__, $id, $_POST['revoke_note'] ?? null);
```

In its revoke form, replace:

```php
      <input type="hidden" name="id" value="<?= $id ?>">
      <button type="submit" class="btn btn-secondary">Revoke acceptance</button>
```

with:

```php
      <input type="hidden" name="id" value="<?= $id ?>">
      <label for="revoke-note">Why are you revoking it? The competitor sees this.</label>
      <textarea id="revoke-note" name="revoke_note" maxlength="500" rows="2" required data-message="Say why you are revoking this acceptance." placeholder="For example: car changed, new engine"></textarea>
      <button type="submit" class="btn btn-secondary">Revoke acceptance</button>
```

Directly before `<?php if ($accepted): ?>` in `renderTechSheetViewPage`, add:

```php
    <?php if (!empty($sheet['revoke_note'])): ?><p class="form-hint">Revoked earlier: <?= h((string)$sheet['revoke_note']) ?></p><?php endif; ?>
```

In `admin-gear.php`, replace:

```php
    $r = gearRevoke($pdo, $id);
```

with:

```php
    $r = gearRevoke($pdo, $id, $_POST['revoke_note'] ?? null);
```

In its revoke form, replace:

```php
      <input type="hidden" name="id" value="<?= $id ?>">
      <button type="submit" class="btn btn-secondary">Revoke acceptance</button>
```

with:

```php
      <input type="hidden" name="id" value="<?= $id ?>">
      <label for="gear-revoke-note">Why are you revoking it? The account holder sees this.</label>
      <textarea id="gear-revoke-note" name="revoke_note" maxlength="500" rows="2" required data-message="Say why you are revoking this acceptance." placeholder="For example: helmet expired"></textarea>
      <button type="submit" class="btn btn-secondary">Revoke acceptance</button>
```

Directly before `<?php if ($accepted): ?>` in `renderGearAdminViewPage`, add:

```php
    <?php if (!empty($gear['revoke_note'])): ?><p class="form-hint">Revoked earlier: <?= h((string)$gear['revoke_note']) ?></p><?php endif; ?>
```

- [ ] **Step 7: Show the note to the owner**

In `tech-sheets.php`, after `require_once __DIR__ . '/email-copy.php';`, add:

```php
require_once __DIR__ . '/revoke-lib.php';
```

In `handleView`, replace:

```php
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
  <p class="no-print">Car status:
```

with:

```php
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
  <?= revokeNoticeHtml($sheet['revoke_note'] ?? null, 'Tech') ?>
  <p class="no-print">Car status:
```

In `gear-page.php`, replace:

```php
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

  <div class="detail-card">
```

with:

```php
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
  <?= revokeNoticeHtml($gear['revoke_note'] ?? null, 'Gear') ?>

  <div class="detail-card">
```

- [ ] **Step 8: Run the tests**

Run: `php phpunit.phar --filter "RevokeNoteTest|TechReviewLibTest|GearLibTest"`. Everything passes except the gear part of `RevokeNoteTest`, which waits on Task 6 (see the Step 1 note). Then run `php phpunit.phar`: every other test passes.

- [ ] **Step 9: Commit**

```bash
git add revoke-lib.php tech-review-lib.php gear-lib.php admin-tech-sheets.php admin-gear.php tech-sheets.php gear-page.php tests/RevokeNoteTest.php tests/TechReviewLibTest.php tests/GearLibTest.php
git commit -m "feat(ta-drift): revoking tech or gear needs a note, shown to the owner

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 6: Summer gear level (Race or TA/Drift) and TA/Drift gear photos

**Files:**
- Modify: `db.php` (a migration, and a new `db_set_gear_photo_tier`)
- Modify: `gear-lib.php` (`GEAR_SUMMER_LEVEL_LABELS`, `gearSummerLevelStored`, `gearSummerLevelLabel`, `gearAcceptByPhotos`, `gearAcceptInPerson`, `gearCreateAndAcceptInPerson`, `gearLinksForSheet`, and the new `gearStartTaDriftForSheet`)
- Modify: `gear-chips.php` (`gearChipCreateForm`, `renderGearChips`)
- Modify: `gear.php` (the `start-ta-drift` route)
- Modify: `admin-gear.php` (the list label, the view's level line, and the level selects on both accept forms)
- Modify: `gear-email.php` (the summer level line)
- Test: `tests/GearTaDriftTest.php`

**Interfaces:**
- Consumes:
  - Phase 1: `GEAR_LEVEL_TA_DRIFT`, `TECH_TIER_TA_DRIFT`, `TECH_TIER_RACE`, `techSheetIsTaDrift`, `db_set_gear_level` (accepts `ta_drift`), `photoRequirementsFor` (reads `photo_tier` and `caged`)
  - Phase 1 test helpers: `test_make_ta_drift_sheet`
- Produces:
  - Columns `gear_records.photo_tier TEXT` (`NULL` = race list, `ta_drift`) and `gear_records.caged INTEGER NOT NULL DEFAULT 0`.
  - `db_set_gear_photo_tier(PDO $pdo, int $id, ?string $tier, bool $caged): void`
  - `GEAR_SUMMER_LEVEL_LABELS = ['race' => 'Race', 'ta_drift' => 'TA/Drift']`
  - `gearSummerLevelStored(?string $choice): string|null|false`: `race`, `''` or null give `NULL`; `ta_drift` gives `'ta_drift'`; anything else gives `false`.
  - `gearSummerLevelLabel(?string $level): string`
  - `gearAcceptInPerson(..., ?string $level)` and `gearAcceptByPhotos(..., ?string $level)` take a summer level. A TA/Drift photo set must be accepted at `ta_drift`.
  - `gearLinksForSheet()` links carry `tier` (`race` or `ta_drift`) and `sheet_caged`. A TA/Drift sheet's `default_level` is `ta_drift`.
  - `gearStartTaDriftForSheet(PDO $pdo, array $sheet, int $ownerId, int $driverNumber = 1): array{ok, error, id}`
  - Route `gear.php?action=start-ta-drift&sheet_id=N[&driver=M]`

- [ ] **Step 1: Write the failing test**

(If you changed `RevokeNoteTest` in Task 5 Step 1, restore its `GEAR_LEVEL_TA_DRIFT` call and the level assertion now.)

```php
<?php
// wcma-calculator/tests/GearTaDriftTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../gear-chips.php';

use PHPUnit\Framework\TestCase;

final class GearTaDriftTest extends TestCase
{
    private function user(PDO $pdo): int {
        return db_create_user($pdo, ['email' => 'gt' . uniqid() . '@example.com', 'name' => 'Pat Winters', 'password_hash' => 'x', 'google_id' => null]);
    }

    /** A current-season TA/Drift sheet for a new car of $u at a WSCC event. */
    private function taSheet(PDO $pdo, int $u, bool $caged = false): array {
        $event = db_create_event($pdo, 'WSCC TA', gearSeasonNow() . '-07-12', null, 'summer', 'WSCC');
        return db_get_tech_sheet($pdo, test_make_ta_drift_sheet($pdo, $u, test_make_car($pdo, $u, '86'), $event, $caged));
    }

    private function addRequiredPhotos(PDO $pdo, int $id): void {
        foreach (photoRequirementsFor(db_get_gear_record($pdo, $id), 'gear') as $key => $def) {
            if ($def['tier'] !== 'required') continue;
            db_upsert_inspection_photo($pdo, ['subject_type' => 'gear_record', 'subject_id' => $id, 'requirement_key' => $key,
                'requirement_version' => 1, 'file_path' => 'uploads/x.jpg', 'typed_value' => null]);
        }
        db_mark_gear_photos_draft($pdo, $id);
    }

    public function testLevelChoices(): void
    {
        $this->assertNull(gearSummerLevelStored(null));
        $this->assertNull(gearSummerLevelStored(''));
        $this->assertNull(gearSummerLevelStored('race'));
        $this->assertSame('ta_drift', gearSummerLevelStored('ta_drift'));
        $this->assertFalse(gearSummerLevelStored('caged'));
        $this->assertSame('Race', gearSummerLevelLabel(null));
        $this->assertSame('TA/Drift', gearSummerLevelLabel('ta_drift'));
    }

    public function testAcceptInPersonAtEitherLevel(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $a = (int)gearCreate($pdo, $u, 'Pat Winters', '', 2026)['id'];
        $b = (int)gearCreate($pdo, $u, 'Sam Patel', '', 2026)['id'];

        $this->assertSame('Choose the gear level: Race or TA/Drift.', gearAcceptInPerson($pdo, $a, $u, 'caged')['error']);
        $this->assertTrue(gearAcceptInPerson($pdo, $a, $u, 'ta_drift')['ok']);
        $this->assertSame('ta_drift', db_get_gear_record($pdo, $a)['level']);
        $this->assertTrue(gearAcceptInPerson($pdo, $b, $u, 'race')['ok']);
        $this->assertNull(db_get_gear_record($pdo, $b)['level']);
    }

    public function testStartFromATaDriftSheetUsesTheTaDriftPhotoList(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $sheet = $this->taSheet($pdo, $u, true);
        $r = gearStartTaDriftForSheet($pdo, $sheet, $u);
        $this->assertTrue($r['ok'], (string)$r['error']);
        $gear = db_get_gear_record($pdo, (int)$r['id']);
        $this->assertSame('summer', $gear['discipline']);
        $this->assertSame('ta_drift', $gear['photo_tier']);
        $this->assertSame(1, (int)$gear['caged']);
        $this->assertSame(['tad_helmet_label', 'tad_fhr_label'], array_keys(photoRequirementsFor($gear, 'gear')));

        $this->assertSame((int)$r['id'], gearStartTaDriftForSheet($pdo, $sheet, $u)['id']);   // same record again
        $this->assertFalse(gearStartTaDriftForSheet($pdo, $sheet, $u + 1)['ok']);
        $this->assertFalse(gearStartTaDriftForSheet($pdo, $sheet, $u, 2)['ok']);          // no driver 2 on the sheet
    }

    public function testAnExistingRecordKeepsItsListOnceItHasPhotos(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $sheet = $this->taSheet($pdo, $u);
        $id = (int)gearCreate($pdo, $u, 'Test Driver', '', gearSeasonNow())['id'];   // the sheet's driver 1
        $this->addRequiredPhotos($pdo, $id);                                           // race list photos, now draft
        $this->assertSame($id, gearStartTaDriftForSheet($pdo, $sheet, $u)['id']);
        $this->assertNull(db_get_gear_record($pdo, $id)['photo_tier']);
    }

    public function testTaDriftPhotosCannotBeAcceptedAtRace(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $id = (int)gearStartTaDriftForSheet($pdo, $this->taSheet($pdo, $u), $u)['id'];
        $this->addRequiredPhotos($pdo, $id);
        $this->assertTrue(gearSubmit($pdo, $id)['ok']);

        $this->assertSame('These photos only cover TA/Drift gear: accept them at TA/Drift.', gearAcceptByPhotos($pdo, $id, $u, 'race')['error']);
        $this->assertSame('open', db_get_gear_record($pdo, $id)['status']);
        $this->assertTrue(gearAcceptByPhotos($pdo, $id, $u, 'ta_drift')['ok']);
        $this->assertSame('ta_drift', db_get_gear_record($pdo, $id)['level']);
        $this->assertTrue(gearCoversTier(db_get_gear_record($pdo, $id), TECH_TIER_TA_DRIFT));
        $this->assertFalse(gearCoversTier(db_get_gear_record($pdo, $id), TECH_TIER_RACE));
    }

    public function testRacePhotosCanBeAcceptedAtEitherLevel(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $id = (int)gearCreate($pdo, $u, 'Pat Winters', '', 2026)['id'];
        $this->addRequiredPhotos($pdo, $id);
        gearSubmit($pdo, $id);
        $this->assertTrue(gearAcceptByPhotos($pdo, $id, $u, 'ta_drift')['ok']);
        $this->assertSame('ta_drift', db_get_gear_record($pdo, $id)['level']);
    }

    public function testSheetLinksCarryTheTier(): void
    {
        $gear = [];
        $links = gearLinksForSheet(['user_id' => 4, 'season' => 2026, 'driver_name' => 'Pat', 'discipline' => 'summer', 'sheet_type' => 'ta_drift', 'club' => 'WSCC', 'caged' => 1], [], $gear);
        $this->assertSame('ta_drift', $links[0]['tier']);
        $this->assertSame('ta_drift', $links[0]['default_level']);
        $this->assertTrue($links[0]['sheet_caged']);
        $race = gearLinksForSheet(['user_id' => 4, 'season' => 2026, 'driver_name' => 'Pat'], [], $gear);
        $this->assertSame('race', $race[0]['tier']);
        $this->assertNull($race[0]['default_level']);
    }

    public function testChips(): void
    {
        $none = ['driver_number' => 2, 'name' => 'Sam', 'name_norm' => 'sam', 'gear' => null, 'status' => ['state' => 'none', 'via' => null],
                 'discipline' => 'summer', 'tier' => 'ta_drift', 'default_level' => 'ta_drift'];
        $owner = renderGearChips([$none], 'owner', ['sheet_id' => 9, 'sheet_season' => gearSeasonNow()]);
        $this->assertStringContainsString('href="gear.php?action=start-ta-drift&amp;sheet_id=9&amp;driver=2"', $owner);

        $admin = renderGearChips([$none], 'admin', ['csrf' => 'tok', 'sheet_id' => 9, 'sheet_season' => gearSeasonNow()]);
        $this->assertStringContainsString('<option value="ta_drift" selected>TA/Drift</option>', $admin);
        $this->assertStringContainsString('<option value="race">Race</option>', $admin);

        $accepted = ['gear' => ['id' => 4, 'season' => 2026, 'discipline' => 'summer', 'level' => 'ta_drift', 'status' => 'accepted', 'accepted_via' => 'in_person', 'photo_status' => null],
                     'status' => ['state' => 'accepted', 'via' => 'in_person']] + $none;
        $this->assertStringContainsString('Gear teched 2026 · TA/Drift', renderGearChips([$accepted], 'owner'));

        $fresh = ['gear' => ['id' => 5, 'season' => gearSeasonNow(), 'discipline' => 'summer', 'level' => null, 'status' => 'open', 'accepted_via' => null, 'photo_status' => null, 'photo_tier' => null]] + $none;
        $this->assertStringContainsString('href="gear.php?action=start-ta-drift&amp;sheet_id=9&amp;driver=2"', renderGearChips([$fresh], 'owner', ['sheet_id' => 9]));
    }

    public function testAdminAndEmailShowTheLevel(): void
    {
        $src = file_get_contents(__DIR__ . '/../admin-gear.php');
        $this->assertStringContainsString('GEAR_SUMMER_LEVEL_LABELS', $src);
        $this->assertStringContainsString("(\$gear['photo_tier'] ?? null) === GEAR_LEVEL_TA_DRIFT", $src);
        $this->assertStringContainsString("GEAR_LEVEL_TA_DRIFT ? 'Gear level: TA/Drift.'", file_get_contents(__DIR__ . '/../gear-email.php'));
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `php phpunit.phar --filter GearTaDriftTest`
Expected: FAIL. `gearSummerLevelStored()` is undefined.

- [ ] **Step 3: Add the schema and DB function**

In `db.php`, directly after phase 1's `db_add_column_if_missing($pdo, 'gear_records', 'revoke_note', 'TEXT');`, add:

```php
    // TA/Drift gear photos (phase 2): which photo list a summer gear record is on (NULL = race, 'ta_drift'), and
    // whether its driver's car is caged (adds the head and neck restraint photo).
    db_add_column_if_missing($pdo, 'gear_records', 'photo_tier', 'TEXT');
    db_add_column_if_missing($pdo, 'gear_records', 'caged', 'INTEGER NOT NULL DEFAULT 0');
```

Directly after `db_set_gear_level()`, add:

```php
/** A summer gear record's photo list: NULL (race) or GEAR_LEVEL_TA_DRIFT, and whether the car is caged. */
function db_set_gear_photo_tier(PDO $pdo, int $id, ?string $tier, bool $caged): void {
    if ($tier !== null && $tier !== GEAR_LEVEL_TA_DRIFT) throw new InvalidArgumentException('Unknown photo tier: ' . $tier);
    $pdo->prepare("UPDATE gear_records SET photo_tier = :t, caged = :c, updated_at = :now WHERE id = :id")
        ->execute([':t' => $tier, ':c' => $caged ? 1 : 0, ':now' => date('Y-m-d H:i:s'), ':id' => $id]);
}
```

- [ ] **Step 4: Implement the gear levels and TA/Drift start**

In `gear-lib.php`, directly after `gearSeasonNow()`, add:

```php
/** Summer gear levels an inspector picks from (TA/Drift spec §2). Race is stored as NULL. */
const GEAR_SUMMER_LEVEL_LABELS = ['race' => 'Race', 'ta_drift' => 'TA/Drift'];

/** A summer level from a form as stored: 'race', '' or null give NULL (race); 'ta_drift' gives 'ta_drift'; anything else gives false. */
function gearSummerLevelStored(?string $choice): string|null|false {
    if ($choice === null || $choice === '' || $choice === 'race') return null;
    return $choice === GEAR_LEVEL_TA_DRIFT ? GEAR_LEVEL_TA_DRIFT : false;
}

/** "Race" or "TA/Drift" for a summer record's level. */
function gearSummerLevelLabel(?string $level): string {
    return $level === GEAR_LEVEL_TA_DRIFT ? 'TA/Drift' : 'Race';
}

/**
 * The level a summer acceptance stores, or an error. A TA/Drift photo set was only checked against the
 * TA/Drift list, so it can only be accepted at TA/Drift.
 * @return array{ok: bool, error: ?string, level: ?string}
 */
function gearSummerAcceptLevel(array $gear, ?string $choice, bool $byPhotos): array {
    $level = gearSummerLevelStored($choice);
    if ($level === false) return ['ok' => false, 'error' => 'Choose the gear level: Race or TA/Drift.', 'level' => null];
    if ($byPhotos && ($gear['photo_tier'] ?? null) === GEAR_LEVEL_TA_DRIFT && $level !== GEAR_LEVEL_TA_DRIFT) {
        return ['ok' => false, 'error' => 'These photos only cover TA/Drift gear: accept them at TA/Drift.', 'level' => null];
    }
    return ['ok' => true, 'error' => null, 'level' => $level];
}
```

In `gearAcceptByPhotos()`, replace:

```php
    if ($isIce && !isset(ICE_GEAR_LEVEL_LABELS[(string)$level])) {
        return ['ok' => false, 'error' => 'Choose the gear level: street-safe or caged.'];
    }
```

with:

```php
    if ($isIce && !isset(ICE_GEAR_LEVEL_LABELS[(string)$level])) {
        return ['ok' => false, 'error' => 'Choose the gear level: street-safe or caged.'];
    }
    $summer = ($gear !== null && !$isIce) ? gearSummerAcceptLevel($gear, $level, true) : ['ok' => true, 'error' => null, 'level' => null];
    if (!$summer['ok']) return ['ok' => false, 'error' => $summer['error']];
```

and in the same function replace:

```php
        if ($isIce) db_set_gear_level($pdo, $id, $level);
```

with:

```php
        db_set_gear_level($pdo, $id, $isIce ? $level : $summer['level']);
```

Update its doc comment to: `/** Inspector accepts a submitted photo set remotely, recording the level: ice street-safe/caged, summer race (NULL) or TA/Drift. @return array{ok: bool, error: ?string} */`

In `gearAcceptInPerson()`, replace:

```php
    if ($isIce && !isset(ICE_GEAR_LEVEL_LABELS[(string)$level])) {
        return ['ok' => false, 'error' => 'Choose the gear level: street-safe or caged.'];
    }
```

with:

```php
    if ($isIce && !isset(ICE_GEAR_LEVEL_LABELS[(string)$level])) {
        return ['ok' => false, 'error' => 'Choose the gear level: street-safe or caged.'];
    }
    $summer = $isIce ? ['ok' => true, 'error' => null, 'level' => null] : gearSummerAcceptLevel($gear, $level, false);
    if (!$summer['ok']) return ['ok' => false, 'error' => $summer['error']];
```

and in the same function replace:

```php
        if ($isIce) db_set_gear_level($pdo, $id, $level);
```

with:

```php
        db_set_gear_level($pdo, $id, $isIce ? $level : $summer['level']);
```

Update its doc comment to: `/** In-person acceptance, recording the level the inspector confirmed (ice: street-safe/caged; summer: race or TA/Drift). @return array{ok: bool, error: ?string} */`

In `gearCreateAndAcceptInPerson()`, replace:

```php
    if ($discipline === DISCIPLINE_ICE && !isset(ICE_GEAR_LEVEL_LABELS[(string)$level])) {
        return $fail('Choose the gear level: street-safe or caged.');
    }
```

with:

```php
    if ($discipline === DISCIPLINE_ICE && !isset(ICE_GEAR_LEVEL_LABELS[(string)$level])) {
        return $fail('Choose the gear level: street-safe or caged.');
    }
    if ($discipline !== DISCIPLINE_ICE && gearSummerLevelStored($level) === false) {
        return $fail('Choose the gear level: Race or TA/Drift.');
    }
```

In `gearLinksForSheet()`, replace:

```php
    $defaultLevel = $discipline === DISCIPLINE_ICE ? iceGearLevelForClass((string)($sheet['club'] ?? ''), (string)($sheet['class'] ?? '')) : null;
```

with:

```php
    $isTaDrift = techSheetIsTaDrift($sheet);
    $defaultLevel = $discipline === DISCIPLINE_ICE
        ? iceGearLevelForClass((string)($sheet['club'] ?? ''), (string)($sheet['class'] ?? ''))
        : ($isTaDrift ? GEAR_LEVEL_TA_DRIFT : null);
```

and replace:

```php
            'default_level' => $defaultLevel,
```

with:

```php
            'default_level' => $defaultLevel,
            'tier' => $isTaDrift ? TECH_TIER_TA_DRIFT : TECH_TIER_RACE,
            'sheet_caged' => !empty($sheet['caged']),
```

In the `@return` line of its doc comment, add `tier: string, sheet_caged: bool,` after `default_level: ?string,`.

Directly after `gearStartIceForSheet()`, add:

```php
/**
 * Opens TA/Drift gear photos from a TA/Drift tech sheet (TA/Drift spec §3): finds or creates the summer
 * gear record for one of the sheet's drivers, in the sheet's (current) season, under the sheet owner,
 * and puts it on the TA/Drift photo list. A record already under way on another list keeps it; an
 * accepted record is left alone. $driverNumber 1 is the sheet's driver; 2 and up are its added drivers.
 *
 * @return array{ok: bool, error: ?string, id: ?int}
 */
function gearStartTaDriftForSheet(PDO $pdo, array $sheet, int $ownerId, int $driverNumber = 1): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'id' => null];
    if ((int)($sheet['user_id'] ?? 0) !== $ownerId) return $fail('Tech sheet not found.');
    if (!techSheetIsTaDrift($sheet)) return $fail('That is not a TA/Drift tech sheet.');
    $season = (int)($sheet['season'] ?? 0);
    if ($season !== gearSeasonNow()) return $fail('Gear photos can only be added for the current season.');
    $rawName = null;
    if ($driverNumber === 1) {
        $rawName = (string)($sheet['driver_name'] ?? '');
    } else {
        foreach (db_get_tech_sheet_drivers($pdo, (int)$sheet['id']) as $d) {
            if ((int)$d['driver_number'] === $driverNumber) { $rawName = (string)$d['driver_name']; break; }
        }
    }
    $name = trim((string)preg_replace('/\s+/', ' ', (string)$rawName));
    if ($name === '') return $fail('That driver is not on this sheet.');
    $caged = !empty($sheet['caged']);

    $existing = db_find_gear_record($pdo, $ownerId, gearNameNorm($name), $season, DISCIPLINE_SUMMER);
    if ($existing !== null) {
        $id = (int)$existing['id'];
        $photoStatus = $existing['photo_status'] ?? null;
        $movable = ($existing['status'] ?? '') === 'open' && ($photoStatus === null
            || (($existing['photo_tier'] ?? null) === GEAR_LEVEL_TA_DRIFT && $photoStatus !== 'submitted'));
        if ($movable) db_set_gear_photo_tier($pdo, $id, GEAR_LEVEL_TA_DRIFT, $caged);
        return ['ok' => true, 'error' => null, 'id' => $id];
    }
    $created = gearCreate($pdo, $ownerId, $name, '', $season);
    if (!$created['ok']) return $fail((string)$created['error']);
    db_set_gear_photo_tier($pdo, (int)$created['id'], GEAR_LEVEL_TA_DRIFT, $caged);
    return ['ok' => true, 'error' => null, 'id' => (int)$created['id']];
}
```

- [ ] **Step 5: The route**

In `gear.php`, directly after the `case 'start-ice':` block (ending `break;`), add:

```php
    case 'start-ta-drift':
        $user = requireGearLogin();
        // driver: 1 (or missing) = the sheet's driver; 2+ = that added driver on the sheet.
        handleGearStartTaDrift($pdo, $user, (int)($_GET['sheet_id'] ?? 0),
            filter_var($_GET['driver'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]));
        break;
```

Directly after `handleGearStartIce()`, add:

```php
/** Opens TA/Drift gear photos for one driver on one of the user's TA/Drift tech sheets. $driverNumber is false for a bad query value. */
function handleGearStartTaDrift(PDO $pdo, array $user, int $sheetId, int|false $driverNumber): void {
    $sheet = db_get_user_tech_sheet($pdo, (int)$user['id'], $sheetId);
    if ($sheet === null) {
        $r = ['ok' => false, 'error' => 'Tech sheet not found.'];
    } elseif ($driverNumber === false) {
        $r = ['ok' => false, 'error' => 'That driver is not on this sheet.'];
    } else {
        $r = gearStartTaDriftForSheet($pdo, $sheet, (int)$user['id'], $driverNumber);
    }
    if (!$r['ok']) {
        setFlash((string)$r['error'], 'error');
        header('Location: ' . ($sheet === null ? 'garage.php' : 'tech-sheets.php?action=view&id=' . $sheetId));
        exit;
    }
    header('Location: gear.php?action=pretech&id=' . (int)$r['id']);
    exit;
}
```

- [ ] **Step 6: The chips**

In `gear-chips.php`, replace the whole `gearChipCreateForm()` with:

```php
/**
 * The inspector's one-tap form for a driver with no gear record (posts to inspect.php). $levels are the
 * level choices (value => label) or [] for none (race sheets); $defaultLevel is preselected.
 */
function gearChipCreateForm(string $csrf, int $sheetId, int $driverNumber, array $hidden, ?string $defaultLevel = null, array $levels = []): string {
    $out = '<form method="post" action="inspect.php?action=gear-create-accept" class="gear-inline-form">'
        . '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">'
        . '<input type="hidden" name="sheet_id" value="' . $sheetId . '">'
        . '<input type="hidden" name="driver_number" value="' . $driverNumber . '">';
    foreach ($hidden as $name => $value) {
        $out .= '<input type="hidden" name="' . h((string)$name) . '" value="' . h((string)$value) . '">';
    }
    if ($levels) {
        $out .= '<label class="gear-level-label">Gear level <select name="level" required>';
        $out .= $defaultLevel === null ? '<option value="">Choose</option>' : '';
        foreach ($levels as $value => $label) {
            $out .= '<option value="' . h((string)$value) . '"' . ((string)$value === $defaultLevel ? ' selected' : '') . '>' . h($label) . '</option>';
        }
        $out .= '</select></label> ';
    }
    return $out . '<button type="submit" class="btn btn-secondary">Create and accept gear in person</button></form>';
}
```

In `renderGearChips()`, replace:

```php
        $isIce = $linkDiscipline === DISCIPLINE_ICE;
        $seasonOk = $sheetSeason === 0 || $sheetSeason === gearSeasonNow($linkDiscipline);
        if ($gear === null) {
            if ($isIce && $audience === 'owner') {
```

with:

```php
        $isIce = $linkDiscipline === DISCIPLINE_ICE;
        $isTaDrift = ($l['tier'] ?? TECH_TIER_RACE) === TECH_TIER_TA_DRIFT;
        $seasonOk = $sheetSeason === 0 || $sheetSeason === gearSeasonNow($linkDiscipline);
        $driverParam = (int)$l['driver_number'] >= 2 ? '&amp;driver=' . (int)$l['driver_number'] : '';
        if ($gear === null) {
            if ($isTaDrift && $audience === 'owner') {
                $html .= '<li class="gear-chip">' . $name . ': <span class="badge-pending">No gear record</span>'
                    . ($seasonOk && $sheetId > 0
                        ? ' <a href="gear.php?action=start-ta-drift&amp;sheet_id=' . $sheetId . $driverParam . '">Add gear photos</a> or have it checked at the track.'
                        : ' Gear is checked at the track.')
                    . '</li>';
                continue;
            }
            if ($isIce && $audience === 'owner') {
```

In the same function, replace:

```php
                $html .= ' ' . gearChipCreateForm($csrf, $sheetId, (int)$l['driver_number'], $hidden, $l['default_level'] ?? null, $isIce);
```

with:

```php
                $levels = $isIce ? ICE_GEAR_LEVEL_LABELS : ($isTaDrift ? GEAR_SUMMER_LEVEL_LABELS : []);
                $html .= ' ' . gearChipCreateForm($csrf, $sheetId, (int)$l['driver_number'], $hidden, $l['default_level'] ?? null, $levels);
```

Replace:

```php
        if (($gear['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_ICE && !empty($gear['level']) && $l['status']['state'] === 'accepted') {
            $label .= ' · ' . (ICE_GEAR_LEVEL_LABELS[$gear['level']] ?? $gear['level']);
        }
```

with:

```php
        if (($gear['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_ICE && !empty($gear['level']) && $l['status']['state'] === 'accepted') {
            $label .= ' · ' . (ICE_GEAR_LEVEL_LABELS[$gear['level']] ?? $gear['level']);
        } elseif (($gear['level'] ?? null) === GEAR_LEVEL_TA_DRIFT && $l['status']['state'] === 'accepted') {
            $label .= ' · TA/Drift';
        }
```

Replace:

```php
        $href = $audience === 'owner'
            ? 'gear.php?action=pretech&amp;id=' . (int)$gear['id']
            : 'inspect.php?action=gear-record&amp;id=' . (int)$gear['id'];
```

with:

```php
        // An open record with no photos yet, reached from a TA/Drift sheet, starts on the TA/Drift photo list.
        $startTaDrift = $isTaDrift && $sheetId > 0 && ($gear['status'] ?? '') === 'open' && ($gear['photo_status'] ?? null) === null
            && ($gear['photo_tier'] ?? null) !== GEAR_LEVEL_TA_DRIFT;
        $href = $audience === 'owner'
            ? ($startTaDrift ? 'gear.php?action=start-ta-drift&amp;sheet_id=' . $sheetId . $driverParam : 'gear.php?action=pretech&amp;id=' . (int)$gear['id'])
            : 'inspect.php?action=gear-record&amp;id=' . (int)$gear['id'];
```

- [ ] **Step 7: Admin gear pages and the email**

In `admin-gear.php` `renderGearAdminListPage`, replace:

```php
if ($discipline === DISCIPLINE_ICE && $st['state'] === 'accepted' && !empty($g['level'])) { $statusLabel .= ' · ' . (ICE_GEAR_LEVEL_LABELS[$g['level']] ?? $g['level']); } ?>
```

with:

```php
if ($discipline === DISCIPLINE_ICE && $st['state'] === 'accepted' && !empty($g['level'])) { $statusLabel .= ' · ' . (ICE_GEAR_LEVEL_LABELS[$g['level']] ?? $g['level']); } elseif ($st['state'] === 'accepted' && ($g['level'] ?? null) === GEAR_LEVEL_TA_DRIFT) { $statusLabel .= ' · TA/Drift'; } ?>
```

In `renderGearAdminViewPage`, replace:

```php
    <?php if (($gear['discipline'] ?? 'summer') === 'ice'): ?><p>Ice gear<?= !empty($gear['level']) ? ' — level: ' . h(ICE_GEAR_LEVEL_LABELS[$gear['level']] ?? $gear['level']) : '' ?></p><?php endif; ?>
```

with:

```php
    <?php if (($gear['discipline'] ?? 'summer') === 'ice'): ?><p>Ice gear<?= !empty($gear['level']) ? ' — level: ' . h(ICE_GEAR_LEVEL_LABELS[$gear['level']] ?? $gear['level']) : '' ?></p>
    <?php else: ?><p>Summer gear<?= $accepted ? ' — level: ' . h(gearSummerLevelLabel($gear['level'] ?? null)) : '' ?><?= ($gear['photo_tier'] ?? null) === GEAR_LEVEL_TA_DRIFT ? ' · TA/Drift photo list' : '' ?></p><?php endif; ?>
```

In the in-person accept form, replace:

```php
      <p class="form-hint">Caged: SA/FIA helmet (and a frontal head restraint where the class needs one). Uncaged classes: Snell M2015+ or ECE 22.05/22.06.</p>
      <?php endif; ?>
      <button type="submit" class="btn btn-primary" id="gear-inperson-btn">Accept — gear teched in person</button>
```

with:

```php
      <p class="form-hint">Caged: SA/FIA helmet (and a frontal head restraint where the class needs one). Uncaged classes: Snell M2015+ or ECE 22.05/22.06.</p>
      <?php else: ?>
      <label for="gear-level">Gear level</label>
      <select id="gear-level" name="level" required>
        <?php foreach (GEAR_SUMMER_LEVEL_LABELS as $value => $label): ?>
        <option value="<?= h($value) ?>"<?= $value === (($gear['photo_tier'] ?? null) === GEAR_LEVEL_TA_DRIFT ? 'ta_drift' : 'race') ? ' selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="form-hint">Race: full WCMA race gear (suit, gloves, shoes, head and neck restraint). TA/Drift: a helmet and natural-fibre clothing, and a head and neck restraint in a caged car.</p>
      <?php endif; ?>
      <button type="submit" class="btn btn-primary" id="gear-inperson-btn">Accept — gear teched in person</button>
```

In `renderGearReviewCard`'s accept form, replace:

```php
      <p class="form-hint">Suggested from the helmet standard in the photo. Caged: Snell SA or FIA helmet (NASCC caged classes need SA2020 or newer). Uncaged classes: Snell M2015+ or ECE 22.05/22.06.</p>
      <?php endif; ?>
```

with:

```php
      <p class="form-hint">Suggested from the helmet standard in the photo. Caged: Snell SA or FIA helmet (NASCC caged classes need SA2020 or newer). Uncaged classes: Snell M2015+ or ECE 22.05/22.06.</p>
      <?php elseif (($gear['photo_tier'] ?? null) === GEAR_LEVEL_TA_DRIFT): ?>
      <input type="hidden" name="level" value="ta_drift">
      <p class="form-hint">These are the TA/Drift gear photos, so they are accepted at TA/Drift.</p>
      <?php else: ?>
      <label for="gear-photos-level">Gear level</label>
      <select id="gear-photos-level" name="level" required>
        <?php foreach (GEAR_SUMMER_LEVEL_LABELS as $value => $label): ?>
        <option value="<?= h($value) ?>"<?= $value === 'race' ? ' selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
```

In `gear-email.php` `gearEmailAccepted()`, replace:

```php
    $levelLine = ($isIce && !empty($gear['level'])) ? 'Gear level: ' . (ICE_GEAR_LEVEL_LABELS[$gear['level']] ?? $gear['level']) . '.' : '';
```

with:

```php
    $levelLine = ($isIce && !empty($gear['level'])) ? 'Gear level: ' . (ICE_GEAR_LEVEL_LABELS[$gear['level']] ?? $gear['level']) . '.'
        : ((!$isIce && ($gear['level'] ?? null) === GEAR_LEVEL_TA_DRIFT) ? 'Gear level: TA/Drift.' : '');
```

- [ ] **Step 8: Run the tests**

Run: `php phpunit.phar --filter "GearTaDriftTest|RevokeNoteTest|GearChipsTest|GearChipsActionsTest|GearLibTest|AdminGearCopyTest"` → PASS. Then `php phpunit.phar` → all PASS.

- [ ] **Step 9: Commit**

```bash
git add db.php gear-lib.php gear-chips.php gear.php admin-gear.php gear-email.php tests/GearTaDriftTest.php tests/RevokeNoteTest.php
git commit -m "feat(ta-drift): accept summer gear at Race or TA/Drift; TA/Drift gear photos from a TA/Drift sheet

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 7: Upgrading TA/Drift gear to Race on the same record

**Files:**
- Modify: `db.php`:
  - `db_transition_gear_photo_status`, `db_accept_gear_by_photos`, `db_get_gear_awaiting_photo_review`
  - new `db_start_gear_race_upgrade` and `db_upgrade_gear_to_race_in_person`
- Modify: `gear-lib.php`:
  - `gearAccessShape`, `gearSubmit`, `gearSendBack`, `gearSummerAcceptLevel`
  - new `gearIsRaceUpgrade`, `gearStartRaceUpgrade` and `gearUpgradeToRaceInPerson`
- Modify: `gear.php` (the `upgrade-race` route)
- Modify: `gear-page.php` (the upgrade card, and the lock)
- Modify: `admin-gear.php` (the in-person upgrade button and handler, and the awaiting check)
- Modify: `inspect.php` (the route), `roles.php` (`INSPECT_POST_ACTIONS`)
- Test: `tests/GearRaceUpgradeTest.php`

**Interfaces:**
- Consumes: Task 6 (`gearSummerAcceptLevel`, the level on accept).
- Produces:
  - `gearIsRaceUpgrade(array $gear): bool`
  - `gearStartRaceUpgrade(PDO $pdo, int $id): array{ok, error}`
  - `gearUpgradeToRaceInPerson(PDO $pdo, int $id, int $reviewerUserId): array{ok, error}`
  - `db_start_gear_race_upgrade(PDO $pdo, int $id): bool`
  - `db_upgrade_gear_to_race_in_person(PDO $pdo, int $id, int $reviewerUserId): bool`
  - Routes:
    - `gear.php?action=upgrade-race` (POST, `id`)
    - `inspect.php?action=gear-record-upgrade-race` (POST, `id`)
- The upgrade rule:
  - While an upgrade is under way, the record stays `status = 'accepted'` with `level = 'ta_drift'`. So `gearCoversTier()` is true for TA/Drift and false for Race.
  - Its race photo set can be submitted, sent back, and accepted at Race only.

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/GearRaceUpgradeTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../gear-lib.php';

use PHPUnit\Framework\TestCase;

final class GearRaceUpgradeTest extends TestCase
{
    /** @return array{0: PDO, 1: int, 2: int} pdo, user, a summer gear record accepted at TA/Drift */
    private function taDriftGear(): array {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'up' . uniqid() . '@example.com', 'name' => 'Pat', 'password_hash' => 'x', 'google_id' => null]);
        $id = (int)gearCreate($pdo, $u, 'Pat Winters', '', 2026)['id'];
        $this->assertTrue(gearAcceptInPerson($pdo, $id, $u, 'ta_drift')['ok']);
        return [$pdo, $u, $id];
    }

    private function addRacePhotos(PDO $pdo, int $id): void {
        foreach (photoRequirements('gear') as $key => $def) {
            if ($def['tier'] !== 'required') continue;
            db_upsert_inspection_photo($pdo, ['subject_type' => 'gear_record', 'subject_id' => $id, 'requirement_key' => $key,
                'requirement_version' => 1, 'file_path' => 'uploads/x.jpg', 'typed_value' => null]);
        }
    }

    public function testUpgradeKeepsTaDriftCoverUntilAccepted(): void
    {
        [$pdo, $u, $id] = $this->taDriftGear();
        $this->assertTrue(gearStartRaceUpgrade($pdo, $id)['ok']);
        $gear = db_get_gear_record($pdo, $id);
        $this->assertTrue(gearIsRaceUpgrade($gear));
        $this->assertSame('draft', $gear['photo_status']);
        $this->assertNull($gear['photo_tier']);
        $this->assertSame(array_keys(photoRequirements('gear')), array_keys(photoRequirementsFor($gear, 'gear')));   // the race list
        $this->assertTrue(gearCoversTier($gear, TECH_TIER_TA_DRIFT));
        $this->assertFalse(gearCoversTier($gear, TECH_TIER_RACE));
        $this->assertSame('submitted', gearAccessShape($gear)['status']);   // the owner can add photos

        $this->addRacePhotos($pdo, $id);
        $this->assertTrue(gearSubmit($pdo, $id)['ok']);
        $this->assertSame([$id], array_map(fn(array $g): int => (int)$g['id'], db_get_gear_awaiting_photo_review($pdo)));
        $this->assertTrue(gearCoversTier(db_get_gear_record($pdo, $id), TECH_TIER_TA_DRIFT));

        $this->assertSame('These are race gear photos: accept them at Race, or send them back.', gearAcceptByPhotos($pdo, $id, $u, 'ta_drift')['error']);
        $this->assertTrue(gearAcceptByPhotos($pdo, $id, $u, 'race')['ok']);
        $gear = db_get_gear_record($pdo, $id);
        $this->assertNull($gear['level']);
        $this->assertSame('accepted', $gear['photo_status']);
        $this->assertFalse(gearIsRaceUpgrade($gear));
        $this->assertTrue(gearCoversTier($gear, TECH_TIER_RACE));
        $this->assertSame([], db_get_gear_awaiting_photo_review($pdo));
    }

    public function testUpgradePhotosCanBeSentBack(): void
    {
        [$pdo, $u, $id] = $this->taDriftGear();
        gearStartRaceUpgrade($pdo, $id);
        $this->addRacePhotos($pdo, $id);
        gearSubmit($pdo, $id);
        $r = gearSendBack($pdo, $id, ['helmet_label' => 'Blurry']);
        $this->assertTrue($r['ok'], (string)$r['error']);
        $gear = db_get_gear_record($pdo, $id);
        $this->assertSame('needs_changes', $gear['photo_status']);
        $this->assertSame('accepted', $gear['status']);
        $this->assertSame('ta_drift', $gear['level']);
    }

    public function testOnlyTaDriftGearCanBeUpgraded(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'up2' . uniqid() . '@example.com', 'name' => 'Pat', 'password_hash' => 'x', 'google_id' => null]);
        $open = (int)gearCreate($pdo, $u, 'Open Driver', '', 2026)['id'];
        $race = (int)gearCreate($pdo, $u, 'Race Driver', '', 2026)['id'];
        gearAcceptInPerson($pdo, $race, $u);
        foreach ([$open, $race] as $id) {
            $this->assertSame('Only gear accepted at TA/Drift can be upgraded to race gear.', gearStartRaceUpgrade($pdo, $id)['error']);
        }
        $this->assertFalse(gearStartRaceUpgrade($pdo, 99999)['ok']);
    }

    public function testInspectorCanUpgradeInPerson(): void
    {
        [$pdo, $u, $id] = $this->taDriftGear();
        gearStartRaceUpgrade($pdo, $id);
        $this->assertTrue(gearUpgradeToRaceInPerson($pdo, $id, $u)['ok']);
        $gear = db_get_gear_record($pdo, $id);
        $this->assertNull($gear['level']);
        $this->assertSame('in_person', $gear['accepted_via']);
        $this->assertTrue(gearCoversTier($gear, TECH_TIER_RACE));
        $this->assertSame([], db_get_gear_awaiting_photo_review($pdo));
        $this->assertFalse(gearUpgradeToRaceInPerson($pdo, $id, $u)['ok']);   // already race
    }

    public function testPagesAndRoutes(): void
    {
        $this->assertContains('gear-record-upgrade-race', INSPECT_POST_ACTIONS);
        $this->assertStringContainsString("case 'gear-record-upgrade-race':", file_get_contents(__DIR__ . '/../inspect.php'));
        $this->assertStringContainsString("case 'upgrade-race':", file_get_contents(__DIR__ . '/../gear.php'));
        $page = file_get_contents(__DIR__ . '/../gear-page.php');
        $this->assertStringContainsString('action="gear.php?action=upgrade-race"', $page);
        $this->assertStringContainsString('gearIsRaceUpgrade($gear)', $page);
        $this->assertStringContainsString('action="inspect.php?action=gear-record-upgrade-race"', file_get_contents(__DIR__ . '/../admin-gear.php'));
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `php phpunit.phar --filter GearRaceUpgradeTest`
Expected: FAIL. `gearStartRaceUpgrade()` is undefined.

- [ ] **Step 3: DB changes**

In `db.php`, directly above `function db_insert_gear_record`, add:

```php
/** A gear record whose photo set can move: an open record, or TA/Drift gear being upgraded to race (TA/Drift phase 2). */
const DB_GEAR_PHOTOS_OPEN_SQL = "(status = 'open' OR (status = 'accepted' AND level = 'ta_drift'))";
```

In `db_transition_gear_photo_status`, replace:

```php
        WHERE id = ? AND status = 'open' AND photo_status IN ($marks)
```

with:

```php
        WHERE id = ? AND " . DB_GEAR_PHOTOS_OPEN_SQL . " AND photo_status IN ($marks)
```

and change its doc comment to `/** Atomic photo_status transition: true only if the record's photos can still move (open, or a race upgrade) and its status was one of $from. */`

In `db_accept_gear_by_photos`, replace:

```php
        WHERE id = :id AND status = 'open' AND photo_status = 'submitted'
```

with:

```php
        WHERE id = :id AND " . DB_GEAR_PHOTOS_OPEN_SQL . " AND photo_status = 'submitted'
```

and change its doc comment to `/** Remote acceptance after reviewing photos. Atomic; only from a submitted photo set on an open record or a race upgrade. */`

In `db_get_gear_awaiting_photo_review`, replace:

```php
        WHERE g.photo_status = 'submitted' AND g.status = 'open'
```

with:

```php
        WHERE g.photo_status = 'submitted' AND (g.status = 'open' OR (g.status = 'accepted' AND g.level = 'ta_drift'))
```

Directly after `db_set_gear_photo_tier()` (Task 6), add:

```php
/** Starts race gear photos on gear accepted at TA/Drift: back to the race list, photos in draft; it stays accepted at TA/Drift. */
function db_start_gear_race_upgrade(PDO $pdo, int $id): bool {
    $stmt = $pdo->prepare("
        UPDATE gear_records SET photo_tier = NULL, caged = 0, photo_status = 'draft', updated_at = :now
        WHERE id = :id AND discipline = 'summer' AND status = 'accepted' AND level = 'ta_drift'
          AND (photo_status IS NULL OR photo_status = 'accepted')
    ");
    $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id]);
    return $stmt->rowCount() === 1;
}

/** An inspector checked the race gear in person: gear accepted at TA/Drift becomes race level. */
function db_upgrade_gear_to_race_in_person(PDO $pdo, int $id, int $reviewerUserId): bool {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        UPDATE gear_records SET level = NULL, accepted_via = 'in_person', revoke_note = NULL,
            reviewed_by_user_id = :reviewer, reviewed_at = :now, updated_at = :now
        WHERE id = :id AND discipline = 'summer' AND status = 'accepted' AND level = 'ta_drift'
    ");
    $stmt->execute([':reviewer' => $reviewerUserId, ':now' => $now, ':id' => $id]);
    return $stmt->rowCount() === 1;
}
```

- [ ] **Step 4: Gear lib changes**

In `gear-lib.php`, directly after `gearSummerLevelLabel()` (Task 6), add:

```php
/** Gear accepted at TA/Drift whose owner is sending race gear photos (TA/Drift spec §2). It keeps covering TA/Drift meanwhile. */
function gearIsRaceUpgrade(array $gear): bool {
    return ($gear['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_SUMMER
        && ($gear['status'] ?? '') === 'accepted' && ($gear['level'] ?? null) === GEAR_LEVEL_TA_DRIFT
        && in_array($gear['photo_status'] ?? null, ['draft', 'needs_changes', 'submitted'], true);
}

/** Owner starts race gear photos for gear accepted at TA/Drift. @return array{ok: bool, error: ?string} */
function gearStartRaceUpgrade(PDO $pdo, int $id): array {
    $gear = db_get_gear_record($pdo, $id);
    if ($gear === null) return ['ok' => false, 'error' => 'Gear record not found.'];
    if (gearIsRaceUpgrade($gear)) return ['ok' => true, 'error' => null];
    if (!db_start_gear_race_upgrade($pdo, $id)) {
        return ['ok' => false, 'error' => 'Only gear accepted at TA/Drift can be upgraded to race gear.'];
    }
    return ['ok' => true, 'error' => null];
}

/** Inspector checked race gear in person on gear accepted at TA/Drift. @return array{ok: bool, error: ?string} */
function gearUpgradeToRaceInPerson(PDO $pdo, int $id, int $reviewerUserId): array {
    if (!db_upgrade_gear_to_race_in_person($pdo, $id, $reviewerUserId)) {
        return ['ok' => false, 'error' => 'Only gear accepted at TA/Drift can be accepted again at Race.'];
    }
    return ['ok' => true, 'error' => null];
}
```

In `gearSummerAcceptLevel()` (Task 6), directly before its final `return ['ok' => true, ...`, add:

```php
    if ($byPhotos && gearIsRaceUpgrade($gear) && $level !== null) {
        return ['ok' => false, 'error' => 'These are race gear photos: accept them at Race, or send them back.', 'level' => null];
    }
```

In `gearAccessShape()`, replace:

```php
        'status' => ($gear['status'] ?? 'open') === 'accepted' ? 'teched' : 'submitted',
```

with:

```php
        // A race upgrade stays accepted (at TA/Drift) but its owner can still add photos.
        'status' => ($gear['status'] ?? 'open') === 'accepted' && !gearIsRaceUpgrade($gear) ? 'teched' : 'submitted',
```

In `gearSubmit()`, replace:

```php
    if ($gear['status'] === 'accepted') return $fail('This driver\'s gear has already been teched for the season.');
```

with:

```php
    if ($gear['status'] === 'accepted' && !gearIsRaceUpgrade($gear)) return $fail('This driver\'s gear has already been teched for the season.');
```

In `gearSendBack()`, replace:

```php
    if ($gear['status'] === 'accepted' || ($gear['photo_status'] ?? null) !== 'submitted') {
```

with:

```php
    if (($gear['status'] === 'accepted' && !gearIsRaceUpgrade($gear)) || ($gear['photo_status'] ?? null) !== 'submitted') {
```

- [ ] **Step 5: Routes, the owner's page and the inspector's page**

In `roles.php` `INSPECT_POST_ACTIONS`, replace `'gear-create-accept',` with `'gear-create-accept', 'gear-record-upgrade-race',`.

In `inspect.php`, directly after the `case 'gear-record-revoke':` block, add:

```php
    case 'gear-record-upgrade-race':
        handleGearAdminUpgradeRace($pdo, $postId);
        break;
```

In `admin-gear.php`, directly after `handleGearAdminRevoke()`, add:

```php
/** Inspector checked race gear in person on gear accepted at TA/Drift. */
function handleGearAdminUpgradeRace(PDO $pdo, int $id): void {
    $r = gearUpgradeToRaceInPerson($pdo, $id, (int)current_user()['id']);
    if ($r['ok']) {
        $sent = gearNotify($pdo, 'accepted_in_person', db_get_gear_record($pdo, $id), gearAdminBaseUrl(), ['email' => TECH_EMAIL, 'name' => TECH_NAME], 'emailSmtpSend');
        setFlash('Gear accepted at Race (checked in person).' . ($sent ? ' The driver\'s account holder was emailed.' : ' The email could not be sent.'), $sent ? 'success' : 'error');
    } else {
        setFlash($r['error'], 'error');
    }
    header('Location: inspect.php?action=gear-record&id=' . $id);
    exit;
}
```

In `renderGearAdminViewPage`, replace:

```php
    <?php if ($accepted): ?>
    <p><?= h($acceptedLine) ?></p>
```

with:

```php
    <?php if ($accepted): ?>
    <p><?= h($acceptedLine) ?></p>
    <?php if (($gear['discipline'] ?? 'summer') === 'summer' && ($gear['level'] ?? null) === GEAR_LEVEL_TA_DRIFT): ?>
    <form method="post" action="inspect.php?action=gear-record-upgrade-race">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <p class="form-hint">This gear is accepted at TA/Drift. If you have checked full race gear in person, accept it at Race.</p>
      <button type="submit" class="btn btn-primary">Accept at Race — race gear checked in person</button>
    </form>
    <?php endif; ?>
```

In `renderGearReviewCard`, replace:

```php
    $awaiting = $photoStatus === 'submitted' && $gear['status'] === 'open';
```

with:

```php
    $awaiting = $photoStatus === 'submitted' && ($gear['status'] === 'open' || gearIsRaceUpgrade($gear));
```

In `gear.php`, directly after the `case 'pretech-submit':` block, add:

```php
    case 'upgrade-race':
        $user = requireGearLogin();
        requireGearPost();
        handleGearUpgradeRace($pdo, $user, (int)($_POST['id'] ?? 0));
        break;
```

and directly after `handleGearPretechSubmit()`, add:

```php
/** Owner starts race gear photos for gear accepted at TA/Drift. */
function handleGearUpgradeRace(PDO $pdo, array $user, int $id): void {
    loadOwnGearRecord($pdo, $user, $id);
    $r = gearStartRaceUpgrade($pdo, $id);
    setFlash($r['ok'] ? 'Add the race gear photos below. The gear stays teched for TA/Drift meanwhile.' : (string)$r['error'], $r['ok'] ? 'success' : 'error');
    header('Location: gear.php?action=pretech&id=' . $id);
    exit;
}
```

In `gear-page.php` `renderGearPretechPage()`, replace:

```php
    $accepted = ($gear['status'] ?? 'open') === 'accepted';
```

with:

```php
    $upgrading = gearIsRaceUpgrade($gear);
    $accepted = ($gear['status'] ?? 'open') === 'accepted' && !$upgrading;
```

Replace:

```php
    <?php if ($accepted): ?>
      <p>This driver's gear is already teched for <?= h(($gear['discipline'] ?? 'summer') === 'ice' ? iceSeasonLabel((int)$gear['season']) : (string)(int)$gear['season']) ?>. You do not need to submit photos.</p>
    <?php else: ?>
```

with:

```php
    <?php if ($accepted && ($gear['discipline'] ?? 'summer') === 'summer' && ($gear['level'] ?? null) === GEAR_LEVEL_TA_DRIFT): ?>
      <p>This driver's gear is teched for TA/Drift in <?= (int)$gear['season'] ?>. To race as well, send photos of the full race gear.</p>
      <form method="post" action="gear.php?action=upgrade-race">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit" class="btn btn-secondary">Send race gear photos</button>
      </form>
    <?php elseif ($accepted): ?>
      <p>This driver's gear is already teched for <?= h(($gear['discipline'] ?? 'summer') === 'ice' ? iceSeasonLabel((int)$gear['season']) : (string)(int)$gear['season']) ?>. You do not need to submit photos.</p>
    <?php else: ?>
      <?php if ($upgrading): ?><p><strong>Race gear photos.</strong> This driver's gear stays teched for TA/Drift while an inspector reviews them.</p><?php endif; ?>
```

- [ ] **Step 6: Run the tests**

Run: `php phpunit.phar --filter "GearRaceUpgradeTest|GearLibTest|DbInspectTest|RolesTest|GearPageTest|InspectionEndpointTest"` → PASS. Then `php phpunit.phar` → all PASS.

- [ ] **Step 7: Commit**

```bash
git add db.php gear-lib.php gear.php gear-page.php admin-gear.php inspect.php roles.php tests/GearRaceUpgradeTest.php
git commit -m "feat(ta-drift): upgrade TA/Drift gear to race on the same record, by photos or in person

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 8: Inspect shows TA/Drift sheets (roster, queue and sheet page)

**Files:**
- Modify: `inspect-lib.php` (the require, `inspectRosterRows`, `inspectReviewQueue`)
- Modify: `inspect-page.php` (`inspectRosterRowHtml`)
- Modify: `admin-tech-sheets.php` (`handleTechSheetView`, `renderTechSheetViewPage`)
- Test: `tests/InspectTaDriftTest.php`

**Interfaces:**
- Consumes: from Task 1, `taDriftCarTechStatus`, `taDriftSheetCarStatus`, `taDriftCarTechStatusLabel` and `techSheetClassLine`. From Task 7, `gearIsRaceUpgrade`.
- Produces:
  - A roster row whose event sheet is a TA/Drift sheet:
    - `status` comes from `taDriftCarTechStatus` and includes `tier`.
    - `ice_class` (the class line from the sheet) is `"TA/Drift (CLUB)"`, so it isn't filtered as "class not accepted".
  - The roster card shows a `TA/Drift` chip and the TA/Drift status label.
  - Queue details end in ` · TA/Drift · CLUB` (car photos). For gear, they end in ` · TA/Drift` or ` · Upgrade to race`.
  - The sheet page shows the chip and the TA/Drift standing.

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/InspectTaDriftTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../events-lib.php';
require_once __DIR__ . '/../tech-status.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../gear-chips.php';
require_once __DIR__ . '/../home-page.php';
require_once __DIR__ . '/../garage-lib.php';
require_once __DIR__ . '/../garage-page.php';
require_once __DIR__ . '/../declaration-review-lib.php';
require_once __DIR__ . '/../inspect-lib.php';
require_once __DIR__ . '/../inspect-page.php';

use PHPUnit\Framework\TestCase;

final class InspectTaDriftTest extends TestCase
{
    private function car(): array {
        return ['id' => 1, 'owner_user_id' => 10, 'car_number' => '86', 'car_number_norm' => '86', 'year' => '2015',
                'make' => 'Subaru', 'model' => 'BRZ', 'owner_name' => 'Pat', 'tagged' => 1];
    }

    private function sheet(int $id, array $o = []): array {
        return array_merge(['id' => $id, 'car_id' => 1, 'user_id' => 10, 'event_id' => 3, 'season' => 2026, 'discipline' => 'summer',
            'sheet_type' => 'ta_drift', 'club' => 'WSCC', 'status' => 'submitted', 'photo_status' => null, 'accepted_via' => null,
            'driver_name' => 'Pat'], $o);
    }

    public function testRosterRowUsesTheTaDriftStanding(): void
    {
        $tad = $this->sheet(5);
        $rows = inspectRosterRows([$this->car()], [$tad], [$tad], [], [], [], [], 2026);
        $this->assertSame('none', $rows[0]['status']['state']);
        $this->assertSame('ta_drift', $rows[0]['status']['tier']);
        $this->assertSame('TA/Drift (WSCC)', $rows[0]['ice_class']);
        $this->assertSame([], inspectRosterFilter($rows, 'class_not_accepted'));

        $race = $this->sheet(2, ['sheet_type' => 'standard', 'club' => null, 'status' => 'teched', 'accepted_via' => 'in_person', 'event_id' => 1]);
        $rows = inspectRosterRows([$this->car()], [$tad], [$race, $tad], [], [], [], [], 2026);
        $this->assertSame(['state' => 'accepted', 'via' => 'in_person', 'sheet_id' => 2, 'tier' => 'race'], $rows[0]['status']);
    }

    public function testRosterCardShowsTheChipAndLabel(): void
    {
        $tad = $this->sheet(5, ['status' => 'teched', 'accepted_via' => 'photos']);
        $rows = inspectRosterRows([$this->car()], [$tad], [$tad], [], [], [], [], 2026);
        $html = renderInspectRosterHtml(['events' => [['id' => 3, 'name' => 'WSCC TA', 'event_date' => '2026-07-12']], 'eventId' => 3,
            'filter' => 'all', 'rows' => $rows, 'counts' => inspectRosterCounts($rows), 'season' => 2026, 'csrf' => 'tok', 'discipline' => 'summer']);
        $this->assertStringContainsString('<span class="admin-chip admin-chip--info">TA/Drift</span>', $html);
        $this->assertStringContainsString('Pre-teched TA/Drift WSCC 2026', $html);
        $this->assertStringContainsString('TA/Drift (WSCC)', $html);
    }

    public function testQueueMarksTaDriftSheetsAndGear(): void
    {
        $sheet = $this->sheet(9, ['car_number' => '86', 'car_make' => 'Subaru', 'car_model' => 'BRZ', 'entrant_name' => 'Pat',
            'event_name' => 'WSCC TA', 'updated_at' => '2026-06-01 10:00:00']);
        $tadGear = ['id' => 4, 'driver_name' => 'Pat', 'owner_name' => 'Pat', 'season' => 2026, 'updated_at' => '2026-06-02 10:00:00',
                    'discipline' => 'summer', 'photo_tier' => 'ta_drift', 'status' => 'open', 'level' => null, 'photo_status' => 'submitted'];
        $upgrade = ['id' => 5, 'photo_tier' => null, 'status' => 'accepted', 'level' => 'ta_drift', 'updated_at' => '2026-06-03 10:00:00'] + $tadGear;
        $items = inspectReviewQueue([], [$sheet], [$tadGear, $upgrade]);
        $this->assertSame('Pat · WSCC TA · TA/Drift · WSCC', $items[0]['detail']);
        $this->assertSame('Entered by Pat · 2026 · TA/Drift', $items[1]['detail']);
        $this->assertSame('Entered by Pat · 2026 · Upgrade to race', $items[2]['detail']);
    }

    public function testSheetPageSource(): void
    {
        $src = file_get_contents(__DIR__ . '/../admin-tech-sheets.php');
        $this->assertStringContainsString('taDriftSheetCarStatus($sheet, db_get_user_tech_sheets($pdo, (int)$sheet[\'user_id\']))', $src);
        $this->assertStringContainsString('taDriftCarTechStatusLabel(', $src);
        $this->assertStringContainsString('<span class="admin-chip admin-chip--info">TA/Drift</span>', $src);
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `php phpunit.phar --filter InspectTaDriftTest`
Expected: FAIL. The status has no `tier`, and `ice_class` is `''`.

- [ ] **Step 3: Implement the roster and queue**

In `inspect-lib.php`, after `require_once __DIR__ . '/ice-sheet-lib.php';`, no new require is needed: `ice-sheet-lib.php` loads `ta-drift-sheet-lib.php` (Task 1).

In `inspectRosterRows()`, replace:

```php
        $rows[] = [
            'car' => $car,
            'sheet' => $sheet,
            'class' => garageClassLine($declarations[$cid] ?? []),
            'status' => techCarStatus($groups[techCarKey(['car_id' => $cid, 'season' => $season, 'discipline' => $key['discipline'], 'club' => $key['club']])] ?? []),
            'gear_links' => $links,
            'ice_class' => ($isIce && $sheet !== null) ? techSheetClassLine($sheet) : '',
        ];
```

with:

```php
        $status = techCarStatus($groups[techCarKey(['car_id' => $cid, 'season' => $season, 'discipline' => $key['discipline'], 'club' => $key['club']])] ?? []);
        $isTaDrift = !$isIce && $sheet !== null && techSheetIsTaDrift($sheet);
        if ($isTaDrift) {
            // A TA/Drift sheet: race tech (any club) counts, otherwise its TA/Drift sheets at this club (TA/Drift spec §2).
            $status = taDriftCarTechStatus($status, techCarStatus($groups[techCarKey($sheet)] ?? []));
        }
        $rows[] = [
            'car' => $car,
            'sheet' => $sheet,
            'class' => garageClassLine($declarations[$cid] ?? []),
            'status' => $status,
            'gear_links' => $links,
            // The class line from the sheet (ice class, or "TA/Drift (CLUB)"); '' when the class comes from a declaration.
            'ice_class' => (($isIce || $isTaDrift) && $sheet !== null) ? techSheetClassLine($sheet) : '',
        ];
```

In `inspectReviewQueue()`, replace:

```php
                . (techSheetIsIce($s) ? ' · Ice · ' . techSheetClassLine($s) : ''),
```

with:

```php
                . (techSheetIsIce($s) ? ' · Ice · ' . techSheetClassLine($s) : '')
                . (techSheetIsTaDrift($s) ? ' · TA/Drift · ' . (string)($s['club'] ?? '') : ''),
```

and replace:

```php
                . ((($g['discipline'] ?? 'summer') === 'ice') ? iceSeasonLabel((int)$g['season']) : (string)(int)$g['season']),
```

with:

```php
                . ((($g['discipline'] ?? 'summer') === 'ice') ? iceSeasonLabel((int)$g['season']) : (string)(int)$g['season'])
                . (gearIsRaceUpgrade($g) ? ' · Upgrade to race' : ((($g['photo_tier'] ?? null) === GEAR_LEVEL_TA_DRIFT) ? ' · TA/Drift' : '')),
```

- [ ] **Step 4: Implement the roster card**

In `inspect-page.php` `inspectRosterRowHtml()`, replace:

```php
            . ' <a href="inspect.php?action=tech-sheet&amp;id=' . (int)$sheet['id'] . '">' . ($sheet['status'] === 'teched' ? 'View' : 'Review') . '</a>';
```

with:

```php
            . ' <a href="inspect.php?action=tech-sheet&amp;id=' . (int)$sheet['id'] . '">' . ($sheet['status'] === 'teched' ? 'View' : 'Review') . '</a>'
            . (techSheetIsTaDrift($sheet) ? ' <span class="admin-chip admin-chip--info">TA/Drift</span>' : '');
    $statusLabel = ($sheet !== null && techSheetIsTaDrift($sheet))
        ? taDriftCarTechStatusLabel($row['status'], $vm['season'], (string)$sheet['club'])
        : techCarStatusLabel($row['status'], $vm['season'], (string)($vm['discipline'] ?? 'summer'));
```

and replace:

```php
        . h(techCarStatusLabel($row['status'], $vm['season'], (string)($vm['discipline'] ?? 'summer'))) . '</span></dd></div>'
```

with:

```php
        . h($statusLabel) . '</span></dd></div>'
```

- [ ] **Step 5: Implement the sheet page**

In `admin-tech-sheets.php` `handleTechSheetView()`, replace:

```php
    $carStatus = techCarStatus(db_get_sheet_identity_sheets($pdo, $sheet));
```

with:

```php
    $carStatus = techSheetIsTaDrift($sheet)
        ? taDriftSheetCarStatus($sheet, db_get_user_tech_sheets($pdo, (int)$sheet['user_id']))
        : techCarStatus(db_get_sheet_identity_sheets($pdo, $sheet));
```

In `renderTechSheetViewPage()`, replace:

```php
    $statusLabel = techCarStatusLabel($carStatus, (int)$sheet['season'], (string)($sheet['discipline'] ?? 'summer'));
```

with:

```php
    $statusLabel = techSheetIsTaDrift($sheet)
        ? taDriftCarTechStatusLabel($carStatus, (int)$sheet['season'], (string)$sheet['club'])
        : techCarStatusLabel($carStatus, (int)$sheet['season'], (string)($sheet['discipline'] ?? 'summer'));
```

and replace:

```php
    <p>Car #<?= h($sheet['car_number']) ?> — <?= h(trim($sheet['car_make'] . ' ' . $sheet['car_model'])) ?> (<?= h($sheet['entrant_name']) ?>)</p>
```

with:

```php
    <p>Car #<?= h($sheet['car_number']) ?> — <?= h(trim($sheet['car_make'] . ' ' . $sheet['car_model'])) ?> (<?= h($sheet['entrant_name']) ?>)</p>
    <?php if (techSheetIsTaDrift($sheet)): ?><p><span class="admin-chip admin-chip--info">TA/Drift</span> <?= h((string)$sheet['club']) ?> supplementary regulations<?= !empty($sheet['caged']) ? ' · roll bar or cage' : '' ?></p><?php endif; ?>
```

- [ ] **Step 6: Run the tests**

Run: `php phpunit.phar --filter "InspectTaDriftTest|InspectLibTest|InspectPageTest"` → PASS. Then `php phpunit.phar` → all PASS.

- [ ] **Step 7: Commit**

```bash
git add inspect-lib.php inspect-page.php admin-tech-sheets.php tests/InspectTaDriftTest.php
git commit -m "feat(ta-drift): Inspect shows TA/Drift sheets, standing and photo sets

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 9: Seed a Time Attack event and add the form to the phone audit

**Files:**
- Modify: `hub-db-tools.php` (`hubSeed`)
- Modify: `tests/HubDbToolsTest.php` (the event count goes from 4 to 5)
- Modify: `tests/ux/audit.mjs` (the TA/Drift form at phone width)

**Interfaces:**
- Consumes: the `tech-sheets.php?action=new-ta-drift&car_id=N` route (Task 4). With no `event_id`, it opens the first open TA/Drift event.
- Produces: the seeded summer event "WSCC Time Attack" (host club WSCC, +24 days). Plan 3's audit additions (the format picker) can reuse it.

- [ ] **Step 1: Update the seed test (it fails first)**

In `tests/HubDbToolsTest.php`, replace `$this->assertCount(4, db_get_active_events($pdo));` with:

```php
        $this->assertCount(5, db_get_active_events($pdo));
        $ta = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE name = 'WSCC Time Attack' AND discipline = 'summer' AND host_club = 'WSCC'")->fetchColumn();
        $this->assertSame(1, $ta);
```

Run: `php phpunit.phar --filter HubDbToolsTest` → FAIL (4 events).

- [ ] **Step 2: Seed the event**

In `hub-db-tools.php` `hubSeed()`, directly after the `'Season Finale'` event line, add:

```php
    db_create_event($pdo, 'WSCC Time Attack', date('Y-m-d', strtotime('+24 days')), 'Gimli Motorsport Park', 'summer', 'WSCC');
```

Run: `php phpunit.phar --filter HubDbToolsTest` → PASS. Then `php phpunit.phar` → all PASS.

- [ ] **Step 3: Audit the form at phone width**

In `tests/ux/audit.mjs`, directly after:

```js
  await page.click('.garage-edit summary'); await audit(page, 'car page (edit details open)');
```

add:

```js
  // TA/Drift tech sheet (TA/Drift spec §3): the form, then with the roll bar or cage checks open.
  const carId = new URL(BASE + '/' + carUrl).searchParams.get('car');
  await page.goto(BASE + '/tech-sheets.php?action=new-ta-drift&car_id=' + carId);
  await audit(page, 'TA/Drift tech sheet');
  report('the TA/Drift sheet opens with the safety line',
    (await page.locator("text=Check each item on the car itself before you tick it.").count()) > 0 ? [] : ['the opening line is missing']);
  await page.check('#ta_drift_caged');
  await page.waitForTimeout(200);
  await audit(page, 'TA/Drift tech sheet (caged)');
  report('ticking roll bar or cage shows the cage checks',
    (await page.locator('text=Roll bar or cage built to WCMA spec').count()) > 0 ? [] : ['the cage checks did not appear']);
```

- [ ] **Step 4: Run the phone audit**

Run from the repo root: `bash wcma-calculator/tests/ux/run-audit.sh`
Expected: every page passes, including "TA/Drift tech sheet" and "TA/Drift tech sheet (caged)". If a rule fails on the new form (for example, the roll bar or cage checkbox is under 24px), fix the markup in `ta-drift-sheet-page.php` using the same classes the ice form uses. Don't add `no-audit`.

- [ ] **Step 5: Commit**

```bash
git add hub-db-tools.php tests/HubDbToolsTest.php tests/ux/audit.mjs
git commit -m "test(ta-drift): seed a Time Attack event; phone audit covers the TA/Drift sheet

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

## What plan 3 is assumed to do (not in this plan)

- **Entry points to the TA/Drift sheet:**
  - The readiness items and Home/Garage buttons link to `tech-sheets.php?action=new-ta-drift&car_id=N&event_id=M` for TA/Drift-tier entries.
  - Plan 2 only adds the route, the redirect from `action=new` for `ta_drift` cars, and the form's "Other events" links.
- **The entry format picker UI** (Garage and Home), which calls phase 1's `eventsTagCar`/`eventsSetFormats` and shows `ENTRY_NO_HOST_CLUB`.
- **Readiness:**
  - The per-event TA/Drift sheet item (`todo`, or `suggested` once approved) and the `suggested` state.
  - Car tech via `taDriftSheetCarStatus()`-style logic for (car, club, year).
  - Gear via `gearCoversTier()`.
  - The regulations item, satisfied by `supps_ack_at` (which `eventsTagForSheet()` also sets).
- **Garage and Drivers:**
  - Garage chips: "TA/Drift {club} {year}" (reuse `taDriftCarTechStatusLabel()`).
  - The Drivers page gear level ("Gear 2026: TA/Drift", via `gearSummerLevelLabel()`).
  - A Drivers-page way to start TA/Drift gear photos without a sheet, if wanted.
  - The Garage "Summer TA/Drift only" car option and `garageAfterAdd`.
- **The Inspect roster for cars with no sheet yet:**
  - Plan 2 only switches to the TA/Drift standing when the car's event sheet is a TA/Drift sheet.
  - A TA/Drift-tier entry with no sheet still shows race standing until plan 3 reads the entry's formats (`db_get_event_roster_cars` doesn't return them yet).
- **Admin gear filter by level, and the MotorsportReg TA/Drift types.**

## Produces for plan 3

**Files**
- `ta-drift-sheet-lib.php` (pure). Loaded by `ice-sheet-lib.php`, so every page that loads the sheet dispatchers has it.
- `ta-drift-sheet-page.php`
- `revoke-lib.php`

**Constants**
- `TA_DRIFT_SHEET_INTRO`
- `REVOKE_NOTE_MAX = 500`
- `REVOKE_NOTE_REQUIRED`
- `GEAR_SUMMER_LEVEL_LABELS = ['race' => 'Race', 'ta_drift' => 'TA/Drift']`
- `DB_GEAR_PHOTOS_OPEN_SQL`

**Columns**
- `gear_records.photo_tier TEXT`: `NULL` for the race list, `'ta_drift'` for the TA/Drift list.
- `gear_records.caged INTEGER NOT NULL DEFAULT 0`

**Pure functions**
- `taDriftOpenEvents(array $events): array`: summer events with a host club.
- `taDriftSheetParsePost(array $post): array`
- `taDriftSheetValidate(array $parsed, string $club): array{error: ?string, drivers: array}`
- `taDriftSheetRow(array $parsed, array $car): array`
- `taDriftSheetCarStatus(array $sheet, array $ownerSheets): array{state, via, sheet_id, tier}`
- `taDriftCarTechStatusLabel(array $status, int $season, string $club): string`. It returns "Teched 2026 (race)", "Pre-teched TA/Drift WSCC 2026", or the photo state.
- `techSheetChecklistSections()`, `techSheetEquipmentItems()` and `techSheetClassLine()`: TA/Drift aware. The class line is `"TA/Drift (CLUB)"`.
- `validateAdditionalDrivers(array $driversInput, array $items = TECH_DRIVER_EQUIPMENT_ITEMS): ?array`
- `taDriftSheetFormVm(...)`, `renderTaDriftTechSheetFormHtml(array $vm): string`
- `revokeNoteClean(mixed $note): ?string`, `revokeNoticeHtml(?string $note, string $what): string`
- `gearSummerLevelStored(?string $choice): string|null|false`, `gearSummerLevelLabel(?string $level): string`
- `gearSummerAcceptLevel(array $gear, ?string $choice, bool $byPhotos): array{ok, error, level}`
- `gearIsRaceUpgrade(array $gear): bool`
- `gearLinksForSheet()`: links also carry `tier` (`'race'` or `'ta_drift'`) and `sheet_caged`. A TA/Drift sheet's `default_level` is `'ta_drift'`.
- `gearChipCreateForm(string $csrf, int $sheetId, int $driverNumber, array $hidden, ?string $defaultLevel = null, array $levels = [])`. Its last parameter is now a level list, not `bool $isIce`.

**Database and workflow functions**
- `eventsTagForSheet(PDO $pdo, int $userId, array $event, array $car, string $tier): void`. Every sheet submit uses it:
  - A new entry matches the sheet's tier.
  - A TA/Drift sheet sets `supps_ack_at` on a TA/Drift entry.
- `techReviewRevoke(PDO $pdo, string $baseDir, int $sheetId, mixed $note)` and `gearRevoke(PDO $pdo, int $id, mixed $note)`: the note is required.
- `gearAcceptInPerson($pdo, $id, $reviewer, ?string $level)` and `gearAcceptByPhotos($pdo, $id, $reviewer, ?string $level)`: summer takes `'race'`, `''` or null (race), or `'ta_drift'`.
- `gearStartTaDriftForSheet(PDO $pdo, array $sheet, int $ownerId, int $driverNumber = 1): array{ok, error, id}`
- `gearStartRaceUpgrade(PDO $pdo, int $id)`, `gearUpgradeToRaceInPerson(PDO $pdo, int $id, int $reviewerUserId)`
- `db_set_gear_photo_tier(PDO $pdo, int $id, ?string $tier, bool $caged): void`
- `db_start_gear_race_upgrade(PDO $pdo, int $id): bool`, `db_upgrade_gear_to_race_in_person(PDO $pdo, int $id, int $reviewerUserId): bool`
- `db_get_gear_awaiting_photo_review()`: also returns race upgrades.

**Routes**
- `tech-sheets.php`:
  - `?action=new-ta-drift&car_id=N[&event_id=M]`
  - `?action=submit-ta-drift` (POST)
  - `edit` and `update` also handle TA/Drift sheets.
  - `?action=new&car_id=N` for a `ta_drift` car with no declaration redirects to `new-ta-drift`.
- `gear.php`:
  - `?action=start-ta-drift&sheet_id=N[&driver=M]`
  - `?action=upgrade-race` (POST `id`)
- `inspect.php?action=gear-record-upgrade-race` (POST `id`; in `INSPECT_POST_ACTIONS`)
- The revoke forms post `revoke_note`.

**Seed data**
- The summer event "WSCC Time Attack" (host club WSCC, +24 days) in `hubSeed()`.
