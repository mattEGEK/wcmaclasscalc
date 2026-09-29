# TA/Drift Phase 1: Foundations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lay the data and logic foundations for the summer TA/Drift tech tier. This covers:
- the TA/Drift rules data (checklist, gear and photos)
- entry formats (Race, Time Attack, Drift) and the tech tier they need
- the `ta_drift` tech sheet type and its per-club season key
- the gear level
- TA/Drift-only cars
- per-club "at the track" choices

Nothing user-visible changes in this phase. Race and ice behaviour stays exactly as it is.

**Architecture:** Plain PHP + SQLite, no framework.
- **Data:** the new rules are pure data in `ta-drift-rules.php`.
- **Logic:** the pure logic is in `ta-drift-lib.php`: formats, tier, the approval ladder and gear coverage.
- **Constants:** the shared constants (`TECH_TIER_*`, `SHEET_TYPE_TA_DRIFT`, `GEAR_LEVEL_TA_DRIFT`) sit in `tech-status.php`, next to `DISCIPLINE_*`, because `db.php` already loads that file.
- **Schema:** every change is an additive `db_add_column_if_missing`, so no tables are rebuilt.
- **Signatures:** existing functions keep their signatures. Any new parameters are trailing and optional.

**Tech Stack:** PHP 8.3, SQLite (PDO), PHPUnit 10 (`php phpunit.phar`).

**Spec:** `docs/superpowers/specs/2026-09-29-ta-drift-tech-design.md`. Read the Decisions table, §1 (rules data), §2 (data model) and §3 Entry.

### Roadmap (one plan per phase)

| Phase | Delivers | Spec |
|---|---|---|
| **1 — Foundations (this plan)** | Rules data, photo lists, schema, entry formats and defaults, `ta_drift` sheet insert and update, status keys, approval ladder, gear coverage, per-club at-track, carry-over fix | §1, §2, §3 Entry |
| 2 — TA/Drift sheet and gear | The form, review, photo pre-tech, gear level on accept, revoke with a required note | §3 |
| 3 — Readiness and screens | Readiness (including the `suggested` state), Home, Garage, Drivers, reminders, Inspect chips, MotorsportReg types | §4, §5 |

## Global Constraints

- **Paths and tests:**
  - All paths are relative to `wcma-calculator/` unless they start with `docs/`.
  - Run PHPUnit from `wcma-calculator/` with `php phpunit.phar`. On this Windows machine, use Git Bash: `cd /c/dev/wcmaclasscalc/wcma-calculator && php phpunit.phar ...`.
  - The full suite must pass at the end of every task.
- **No new dependencies and no build step.**
- **Naming:** people see "TA/Drift". Code uses `ta_drift`. Never write "light" in code, copy or comments.
- **Entry formats:**
  - Exactly `race`, `ta` and `drift`, stored as a comma list in `ENTRY_FORMATS` order (`race,ta,drift`).
  - Blank or unknown values read as `race`.
- **Tiers:** exactly `race` and `ta_drift`. `entryTechTier()` is the only place the "strictest format wins" rule lives.
- **Sheet type:** `ta_drift`, with `discipline` = `summer`, `club` = the event's `host_club` (required) and `submission_id` = NULL.
- **Gear level on summer records:** `NULL` is race (every existing accepted summer record) and `ta_drift` is TA/Drift. The ice levels `street_safe` and `caged` are unchanged.
- **Car disciplines:** exactly `ice`, `summer`, `both` and `ta_drift`. A `ta_drift` car races summer only.
- **Race and ice are unchanged.** These must keep their current output:
  - `techCarKey()` gives `car|season` for summer race sheets and `car|ice|CLUB|season` for ice sheets.
  - `atTrackKey()` gives `car:ID@2026` for summer and `car:ID@ice:CLUB:2027` for ice.
  - `db_get_identity_sheets($pdo, $car, $season)` (summer, club NULL) never returns a `ta_drift` sheet.
- **Checklist wording:**
  - Tow points are required ("factory ones are fine").
  - The fire extinguisher is "(recommended)".
  - The regulations item names the host club.
- **Migrations are safe to run twice.** A second `db_init()` does nothing.
- **Branch and commits:**
  - Work on a branch named `ta-drift-phase1`, not `main`.
  - Commit at the end of every task.
  - Every commit message ends with:
    ```
    Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
    Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5
    ```

## Review Focus

1. **Entries made before this change.** An `event_plans` row with no `formats` column must read as Race after migration, so every existing competitor's to-do list is unchanged. Test: Task 5, `testLegacyEntriesReadAsRace`.
2. **The same car tagged twice.** The second tag comes from the auto-tag when a sheet is submitted, a double tap, or Home and Garage both tagging. It must not reset the formats the driver chose. Test: Task 7, `testRetagKeepsChosenFormats`.
3. **A TA/Drift sheet must never count as race tech,** and a WSCC TA/Drift sheet must not count at NASCC. Test: Task 6, `testTaDriftSheetsDoNotMergeWithRaceOrOtherClub`.
4. **Editing a sheet across tiers is refused, not silently re-keyed.** This covers turning a TA/Drift sheet into a race sheet (or back), and moving it to an event with no host club. Test: Task 6, `testCannotChangeBetweenTaDriftAndRace` and `testUpdateToEventWithoutClubIsRefused`.
5. **TA/Drift-level summer gear must not carry over to ice as caged gear** (helmet and head and neck restraint standards differ). Test: Task 9, `testTaDriftSummerGearDoesNotCarryOverToIce`.

---

### Task 1: TA/Drift rules data (`ta-drift-rules.php`)

**Files:**
- Create: `ta-drift-rules.php`
- Modify: `tech-sheet-data.php` (`emptyDriverEquipment` takes an optional items list)
- Test: `tests/TaDriftRulesTest.php`

**Interfaces:**
- Consumes: `TECH_DRIVER_EQUIPMENT_ITEMS`, `emptyChecklist()`, `validateChecklist()` and `validateDriverEquipment()` from `tech-sheet-data.php`.
- Produces:
  - constants `TA_DRIFT_RULES_VERSION`, `TA_DRIFT_CHECKLIST_SECTIONS`, `TA_DRIFT_CAGED_SECTION`, `TA_DRIFT_EQUIPMENT_ITEMS`
  - `taDriftChecklistSections(bool $caged, string $club): array`: the same shape as `TECH_CHECKLIST_SECTIONS`, with `{club}` filled in and the `cage` section only when `$caged`.
  - `taDriftEquipmentItems(bool $caged): array`: the same shape as `TECH_DRIVER_EQUIPMENT_ITEMS`. The head and neck restraint is required only when `$caged`.
  - `taDriftHelmetNote(bool $caged): string`
  - `emptyDriverEquipment(array $items = TECH_DRIVER_EQUIPMENT_ITEMS): array`

- [ ] **Step 1: Create the branch**

```bash
cd /c/dev/wcmaclasscalc && git checkout -b ta-drift-phase1
```

- [ ] **Step 2: Write the failing test**

```php
<?php
// wcma-calculator/tests/TaDriftRulesTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../ta-drift-rules.php';

final class TaDriftRulesTest extends TestCase
{
    private function items(array $sections): array {
        $out = [];
        foreach ($sections as $section) $out += $section['items'];
        return $out;
    }

    public function testCageSectionOnlyWhenCaged(): void
    {
        $this->assertArrayNotHasKey(TA_DRIFT_CAGED_SECTION, taDriftChecklistSections(false, 'WSCC'));
        $caged = taDriftChecklistSections(true, 'WSCC');
        $this->assertSame(['cage_spec', 'harness'], array_keys($caged[TA_DRIFT_CAGED_SECTION]['items']));
    }

    public function testRegulationsItemNamesTheHostClub(): void
    {
        $items = $this->items(taDriftChecklistSections(false, 'NASCC'));
        $this->assertSame('I have read the NASCC supplementary regulations and my car complies', $items['supps_read']);
        foreach ($items as $label) $this->assertStringNotContainsString('{club}', $label);
    }

    public function testTowPointsRequiredAndExtinguisherRecommended(): void
    {
        $items = $this->items(taDriftChecklistSections(false, 'WSCC'));
        $this->assertStringContainsString('factory ones are fine', $items['tow_points']);
        $this->assertStringNotContainsString('(recommended)', $items['tow_points']);
        $this->assertStringEndsWith('(recommended)', $items['fire_extinguisher']);
    }

    public function testItemKeysAreUniqueAcrossSections(): void
    {
        $count = 0;
        foreach (TA_DRIFT_CHECKLIST_SECTIONS as $section) $count += count($section['items']);
        $this->assertSame($count, count($this->items(TA_DRIFT_CHECKLIST_SECTIONS)));
    }

    public function testHeadAndNeckRestraintOnlyRequiredWhenCaged(): void
    {
        $this->assertTrue(taDriftEquipmentItems(false)['head_neck_restraints']['optional']);
        $this->assertFalse(taDriftEquipmentItems(true)['head_neck_restraints']['optional']);
        $this->assertTrue(taDriftEquipmentItems(false)['helmet']['has_rating']);
        $this->assertSame(['helmet', 'clothing', 'head_neck_restraints'], array_keys(taDriftEquipmentItems(false)));
    }

    public function testExistingValidatorsAcceptTheShape(): void
    {
        $sections = taDriftChecklistSections(true, 'WSCC');
        $checklist = array_map(fn($v): array => ['status' => 'ok'], emptyChecklist($sections));
        $this->assertTrue(validateChecklist($checklist, $sections));
        unset($checklist['harness']);
        $this->assertFalse(validateChecklist($checklist, $sections));

        $items = taDriftEquipmentItems(false);
        $equipment = emptyDriverEquipment($items);
        $this->assertSame(['helmet', 'clothing', 'head_neck_restraints'], array_keys($equipment));
        $this->assertFalse(validateDriverEquipment($equipment, $items));
        $equipment['helmet'] = ['competitor_confirmed' => true, 'value' => 'Snell SA2020'];
        $equipment['clothing'] = ['competitor_confirmed' => true, 'value' => null];
        $this->assertTrue(validateDriverEquipment($equipment, $items));
        $this->assertFalse(validateDriverEquipment($equipment, taDriftEquipmentItems(true)));   // caged: restraint required
    }

    public function testSummerEquipmentDefaultIsUnchanged(): void
    {
        $this->assertSame(array_keys(TECH_DRIVER_EQUIPMENT_ITEMS), array_keys(emptyDriverEquipment()));
    }

    public function testHelmetNote(): void
    {
        $this->assertStringContainsString('ECE 22.05', taDriftHelmetNote(false));
        $this->assertStringContainsString('Snell SA', taDriftHelmetNote(true));
        $this->assertStringContainsString('head and neck restraint', taDriftHelmetNote(true));
    }
}
```

- [ ] **Step 3: Run the test to confirm it fails**

Run: `cd /c/dev/wcmaclasscalc/wcma-calculator && php phpunit.phar --filter TaDriftRulesTest`
Expected: FAIL. `ta-drift-rules.php` doesn't exist, so the `require_once` fails.

- [ ] **Step 4: Create `ta-drift-rules.php`**

```php
<?php
// wcma-calculator/ta-drift-rules.php
//
// Summer TA/Drift tech as data (spec 2026-09-29-ta-drift-tech-design.md §1): one shared checklist
// and gear list built from what the clubs' regulations have in common. Sources: Canadian National
// SoloSport Regulations – Time Attack (2020, adopted by WCMA); NASCC 2025 Time Attack Regulations v6;
// WSCC 2024 Time Attack Supplementary Regulations v4.0; WSCC 2026 Ice Race Supp Regs §3 (Ice Drift).
// Update here when the regs change and bump TA_DRIFT_RULES_VERSION. Pure: no DB, no HTML.

require_once __DIR__ . '/tech-sheet-data.php';

const TA_DRIFT_RULES_VERSION = 1;

/** The section shown only when the sheet says a roll bar or cage is fitted. */
const TA_DRIFT_CAGED_SECTION = 'cage';

const TA_DRIFT_CHECKLIST_SECTIONS = [
    'brakes_wheels' => ['label' => 'Brakes, Wheels & Tires', 'items' => [
        'brakes'    => 'Brakes work at all four wheels, no leaks',
        'lug_nuts'  => 'All lug nuts present and tight',
        'hubcaps'   => 'Hubcaps and trim rings removed',
        'tires'     => 'DOT tires in good condition',
    ]],
    'engine' => ['label' => 'Engine Compartment', 'items' => [
        'battery_mount'    => 'Battery securely held down (no bungee cords)',
        'battery_terminal' => 'Positive battery terminal insulated',
        'no_leaks'         => 'No fluid leaks',
        'catch_cans'       => 'Coolant overflow and crankcase breather drain to catch cans',
    ]],
    'interior' => ['label' => 'Interior', 'items' => [
        'no_loose_items'    => 'Nothing loose in the cabin or trunk; nothing hanging from the mirror',
        'seats'             => 'Seats securely mounted (an aftermarket seat at four points or more)',
        'belts'             => 'Seat belts in good condition (factory 3-point is fine; a harness only with a roll bar or cage)',
        'fire_extinguisher' => "Fire extinguisher within the driver's reach, on a quick-release mount (recommended)",
    ]],
    'exterior' => ['label' => 'Exterior', 'items' => [
        'windows'      => 'Windows up unless window nets are fitted; sunroof or convertible top closed and locked',
        'mirrors'      => 'At least one rear-view mirror',
        'brake_lights' => 'Brake lights working',
        'tow_points'   => 'Front and rear tow points (factory ones are fine)',
        'numbers'      => 'Car number on both sides',
    ]],
    TA_DRIFT_CAGED_SECTION => ['label' => 'Roll Bar or Cage', 'items' => [
        'cage_spec' => 'Roll bar or cage built to WCMA spec',
        'harness'   => '5- or 6-point harness fitted',
    ]],
    'regulations' => ['label' => 'Supplementary Regulations', 'items' => [
        'supps_read' => 'I have read the {club} supplementary regulations and my car complies',
    ]],
];

const TA_DRIFT_EQUIPMENT_ITEMS = [
    'helmet'               => ['label' => 'Helmet', 'has_rating' => true, 'optional' => false],
    'clothing'             => ['label' => 'Long pants, closed-toe shoes and a sleeved natural-fibre shirt', 'has_rating' => false, 'optional' => false],
    'head_neck_restraints' => ['label' => 'Head & Neck Restraint (SFI 38.1 or FIA 8858)', 'has_rating' => false, 'optional' => true],
];

/** The TA/Drift checklist for one sheet, shaped like TECH_CHECKLIST_SECTIONS. */
function taDriftChecklistSections(bool $caged, string $club): array {
    $out = [];
    foreach (TA_DRIFT_CHECKLIST_SECTIONS as $key => $section) {
        if ($key === TA_DRIFT_CAGED_SECTION && !$caged) continue;
        $section['items'] = array_map(fn(string $label): string => str_replace('{club}', $club, $label), $section['items']);
        $out[$key] = $section;
    }
    return $out;
}

/** Driver gear for a TA/Drift sheet. A head and neck restraint is required only in a caged car. */
function taDriftEquipmentItems(bool $caged): array {
    $items = TA_DRIFT_EQUIPMENT_ITEMS;
    $items['head_neck_restraints']['optional'] = !$caged;
    return $items;
}

/** The helmet standard, for the form's equipment card. */
function taDriftHelmetNote(bool $caged): string {
    return $caged
        ? 'Helmet: Snell SA2015 or newer. A head and neck restraint (SFI 38.1 or FIA 8858) is required in a caged car.'
        : 'Helmet: Snell SA or M 2015 or newer, or ECE 22.05 made in the last 10 years.';
}
```

- [ ] **Step 5: Let `emptyDriverEquipment` take an items list**

In `tech-sheet-data.php`, replace:

```php
/** Every driver-equipment item key, mapped to its unanswered shape. */
function emptyDriverEquipment(): array {
    $out = [];
    foreach (TECH_DRIVER_EQUIPMENT_ITEMS as $key => $def) {
```

with:

```php
/** Every driver-equipment item key (summer by default), mapped to its unanswered shape. */
function emptyDriverEquipment(array $items = TECH_DRIVER_EQUIPMENT_ITEMS): array {
    $out = [];
    foreach ($items as $key => $def) {
```

- [ ] **Step 6: Run the test to confirm it passes, then run the full suite**

Run: `php phpunit.phar --filter TaDriftRulesTest` → PASS. Then `php phpunit.phar` → all PASS.

- [ ] **Step 7: Commit**

```bash
git add ta-drift-rules.php tech-sheet-data.php tests/TaDriftRulesTest.php
git commit -m "feat(ta-drift): rules data for the TA/Drift checklist and gear

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 2: Shared constants, status key and at-track key (`tech-status.php`)

**Files:**
- Modify: `tech-status.php`
- Test: `tests/TechStatusTest.php` (add tests)

**Interfaces:**
- Consumes: nothing new.
- Produces:
  - constants `TECH_TIER_RACE = 'race'`, `TECH_TIER_TA_DRIFT = 'ta_drift'`, `SHEET_TYPE_TA_DRIFT = 'ta_drift'` and `GEAR_LEVEL_TA_DRIFT = 'ta_drift'`
  - `techCarKey()`: a `ta_drift` sheet keys as `car|ta_drift|CLUB|season`
  - `atTrackKey($type, $id, $season, TECH_TIER_TA_DRIFT, $club)` returns `"$type:$id@tad:$club:$season"`

- [ ] **Step 1: Write the failing tests**

Add these methods to `tests/TechStatusTest.php`, inside the class:

```php
    public function testTaDriftCarKeyIncludesClubAndNeverCollides(): void
    {
        $tad = fn(int $id, string $club): array => $this->sheet($id, ['discipline' => 'summer', 'sheet_type' => 'ta_drift', 'club' => $club]);
        $this->assertSame('500|ta_drift|WSCC|2026', techCarKey($tad(1, 'WSCC')));
        $this->assertNotSame(techCarKey($tad(1, 'WSCC')), techCarKey($tad(2, 'NASCC')));
        $this->assertNotSame(techCarKey($tad(1, 'NASCC')), techCarKey($this->sheet(3, ['discipline' => 'ice', 'club' => 'NASCC'])));
        $this->assertSame('500|2026', techCarKey($this->sheet(4, ['discipline' => 'summer', 'sheet_type' => 'standard', 'club' => null])));
    }

    public function testTaDriftAtTrackKey(): void
    {
        $this->assertSame('car:5@tad:WSCC:2026', atTrackKey('car', 5, 2026, TECH_TIER_TA_DRIFT, 'WSCC'));
        $this->assertSame('car:5@2026', atTrackKey('car', 5, 2026));
        $this->assertSame('car:5@ice:NASCC:2027', atTrackKey('car', 5, 2027, 'ice', 'NASCC'));
    }
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php phpunit.phar --filter TechStatusTest`
Expected: FAIL. `TECH_TIER_TA_DRIFT` is undefined, and `techCarKey` returns `500|2026` for the TA/Drift sheet.

- [ ] **Step 3: Implement**

In `tech-status.php`, directly after `const DISCIPLINE_ICE = 'ice';`, add:

```php

/**
 * TA/Drift (2026-09-29 spec): the tech an entry needs (race or TA/Drift), the sheet type that
 * carries the TA/Drift tier, and the summer gear level for it (NULL is race level).
 */
const TECH_TIER_RACE = 'race';
const TECH_TIER_TA_DRIFT = 'ta_drift';
const SHEET_TYPE_TA_DRIFT = 'ta_drift';
const GEAR_LEVEL_TA_DRIFT = 'ta_drift';
```

In `techCarKey()`, replace:

```php
    $season = (int)($sheet['season'] ?? 0);
    if (($sheet['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_ICE) {
```

with:

```php
    $season = (int)($sheet['season'] ?? 0);
    if (($sheet['sheet_type'] ?? '') === SHEET_TYPE_TA_DRIFT) {
        return $car . '|ta_drift|' . ($sheet['club'] ?? '') . '|' . $season;
    }
    if (($sheet['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_ICE) {
```

Update the doc comment above `techCarKey` to read: `/** Groups sheets that belong to the same car in the same season (and, for ice and TA/Drift, the same club). */`

Replace `atTrackKey()` with:

```php
/**
 * Key for an "I'll do it at the track" choice. Summer keys keep their original "type:id@season" form.
 * TA/Drift car tech (stored as a summer choice with the host club) passes TECH_TIER_TA_DRIFT.
 */
function atTrackKey(string $type, int $id, int $season, string $discipline = DISCIPLINE_SUMMER, string $club = ''): string {
    if ($discipline === TECH_TIER_TA_DRIFT) return "$type:$id@tad:$club:$season";
    if ($discipline !== DISCIPLINE_ICE) return "$type:$id@$season";
    return $club === '' ? "$type:$id@ice:$season" : "$type:$id@ice:$club:$season";
}
```

- [ ] **Step 4: Run the tests to confirm they pass, then run the full suite**

Run: `php phpunit.phar --filter TechStatusTest` → PASS. Then `php phpunit.phar` → all PASS.

- [ ] **Step 5: Commit**

```bash
git add tech-status.php tests/TechStatusTest.php
git commit -m "feat(ta-drift): tier constants, per-club TA/Drift status and at-track keys

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 3: Formats, tier, approval ladder and gear coverage (`ta-drift-lib.php`)

**Files:**
- Create: `ta-drift-lib.php`
- Test: `tests/TaDriftLibTest.php`

**Interfaces:**
- Consumes: the Task 2 constants.
- Produces:
  - constants:
    - `ENTRY_FORMATS` (`['race' => 'Race', 'ta' => 'Time Attack', 'drift' => 'Drift']`)
    - `ENTRY_FORMAT_ERROR`
    - `ENTRY_NO_HOST_CLUB`
  - `entryFormatsParse(?string $stored): string[]`
  - `entryFormatsStore(array $formats): string`
  - `entryFormatsLabel(array $formats): string` (for example `"Time Attack · Drift"`)
  - `entryTechTier(array $formats): string` (returns `TECH_TIER_RACE` or `TECH_TIER_TA_DRIFT`)
  - `entryFormatsValidate(array $event, mixed $picked): array{ok: bool, formats: string[], error: ?string}`
  - `techSheetIsTaDrift(array $sheet): bool`
  - `taDriftCarTechStatus(array $race, array $taDrift): array{state: string, via: ?string, sheet_id: ?int, tier: string}`
  - `gearCoversTier(?array $gear, string $tier): bool`

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/TaDriftLibTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../ta-drift-lib.php';

final class TaDriftLibTest extends TestCase
{
    public function testParseAndStoreUseCanonicalOrder(): void
    {
        $this->assertSame(['race'], entryFormatsParse(null));
        $this->assertSame(['race'], entryFormatsParse(''));
        $this->assertSame(['race'], entryFormatsParse('rally'));
        $this->assertSame(['ta', 'drift'], entryFormatsParse('drift, ta'));
        $this->assertSame('race,ta,drift', entryFormatsStore(['drift', 'race', 'ta']));
        $this->assertSame('Time Attack · Drift', entryFormatsLabel(['drift', 'ta']));
    }

    public function testTierIsTheStrictestFormat(): void
    {
        $cases = [
            'race' => TECH_TIER_RACE, 'ta' => TECH_TIER_TA_DRIFT, 'drift' => TECH_TIER_TA_DRIFT,
            'race,ta' => TECH_TIER_RACE, 'race,drift' => TECH_TIER_RACE, 'ta,drift' => TECH_TIER_TA_DRIFT,
            'race,ta,drift' => TECH_TIER_RACE,
        ];
        foreach ($cases as $stored => $tier) $this->assertSame($tier, entryTechTier(entryFormatsParse($stored)), $stored);
    }

    public function testValidate(): void
    {
        $summer = ['discipline' => 'summer', 'host_club' => 'WSCC'];
        $noClub = ['discipline' => 'summer', 'host_club' => null];
        $ice = ['discipline' => 'ice', 'host_club' => 'NASCC'];

        $this->assertSame(['ok' => true, 'formats' => ['ta', 'drift'], 'error' => null], entryFormatsValidate($summer, ['drift', 'ta']));
        $this->assertSame(ENTRY_FORMAT_ERROR, entryFormatsValidate($summer, [])['error']);
        $this->assertSame(ENTRY_FORMAT_ERROR, entryFormatsValidate($summer, ['ta', 'rally'])['error']);
        $this->assertSame(ENTRY_FORMAT_ERROR, entryFormatsValidate($summer, 'ta')['error']);
        $this->assertSame(ENTRY_NO_HOST_CLUB, entryFormatsValidate($noClub, ['race', 'ta'])['error']);
        $this->assertTrue(entryFormatsValidate($noClub, ['race'])['ok']);
        $this->assertSame(['ok' => true, 'formats' => ['race'], 'error' => null], entryFormatsValidate($ice, ['drift']));
    }

    public function testSheetIsTaDrift(): void
    {
        $this->assertTrue(techSheetIsTaDrift(['sheet_type' => 'ta_drift']));
        $this->assertFalse(techSheetIsTaDrift(['sheet_type' => 'standard']));
        $this->assertFalse(techSheetIsTaDrift([]));
    }

    public function testRaceAcceptanceCoversTaDrift(): void
    {
        $accepted = ['state' => 'accepted', 'via' => 'in_person', 'sheet_id' => 3];
        $pending = ['state' => 'pending_review', 'via' => null, 'sheet_id' => 9];
        $none = ['state' => 'none', 'via' => null, 'sheet_id' => null];

        $this->assertSame($accepted + ['tier' => TECH_TIER_RACE], taDriftCarTechStatus($accepted, $pending));
        $this->assertSame(['state' => 'accepted', 'via' => 'photos', 'sheet_id' => 9, 'tier' => TECH_TIER_TA_DRIFT],
            taDriftCarTechStatus($none, ['state' => 'accepted', 'via' => 'photos', 'sheet_id' => 9]));
        $this->assertSame($pending + ['tier' => TECH_TIER_TA_DRIFT], taDriftCarTechStatus($none, $pending));
        $this->assertSame($none + ['tier' => TECH_TIER_TA_DRIFT], taDriftCarTechStatus($pending, $none));
    }

    public function testGearCoversTier(): void
    {
        $race = ['status' => 'accepted', 'level' => null];
        $tad = ['status' => 'accepted', 'level' => GEAR_LEVEL_TA_DRIFT];
        $open = ['status' => 'open', 'level' => null];

        $this->assertTrue(gearCoversTier($race, TECH_TIER_RACE));
        $this->assertTrue(gearCoversTier($race, TECH_TIER_TA_DRIFT));
        $this->assertFalse(gearCoversTier($tad, TECH_TIER_RACE));
        $this->assertTrue(gearCoversTier($tad, TECH_TIER_TA_DRIFT));
        $this->assertFalse(gearCoversTier($open, TECH_TIER_TA_DRIFT));
        $this->assertFalse(gearCoversTier(null, TECH_TIER_TA_DRIFT));
        $this->assertTrue(gearCoversTier(['status' => 'accepted'], TECH_TIER_RACE));   // rows without a level column
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `php phpunit.phar --filter TaDriftLibTest`
Expected: FAIL. `ta-drift-lib.php` doesn't exist.

- [ ] **Step 3: Create `ta-drift-lib.php`**

```php
<?php
// wcma-calculator/ta-drift-lib.php
//
// TA/Drift (spec 2026-09-29-ta-drift-tech-design.md): the formats an entry runs, the tech tier they
// need, and the approval ladder (race tech and race gear also cover TA/Drift). Pure: no DB, no HTML.
require_once __DIR__ . '/tech-status.php';

const ENTRY_FORMATS = ['race' => 'Race', 'ta' => 'Time Attack', 'drift' => 'Drift'];
const ENTRY_FORMAT_ERROR = 'Choose at least one: Race, Time Attack or Drift.';
const ENTRY_NO_HOST_CLUB = 'This event has no host club yet. Ask an admin.';

/** Stored formats ('race,ta') as known formats in ENTRY_FORMATS order. Blank or unknown-only reads as race. */
function entryFormatsParse(?string $stored): array {
    $picked = array_map('trim', explode(',', (string)$stored));
    $out = array_values(array_filter(array_keys(ENTRY_FORMATS), fn(string $f): bool => in_array($f, $picked, true)));
    return $out ?: ['race'];
}

/** Formats as stored in event_plans.formats: known ones, comma-separated, in ENTRY_FORMATS order. */
function entryFormatsStore(array $formats): string {
    return implode(',', array_values(array_filter(array_keys(ENTRY_FORMATS), fn(string $f): bool => in_array($f, $formats, true))));
}

/** How an entry's formats read to people: "Time Attack · Drift". */
function entryFormatsLabel(array $formats): string {
    return implode(' · ', array_map(fn(string $f): string => ENTRY_FORMATS[$f], entryFormatsParse(implode(',', $formats))));
}

/** The tech an entry needs: race tech if it races, otherwise TA/Drift. The only home of this rule. */
function entryTechTier(array $formats): string {
    return in_array('race', $formats, true) ? TECH_TIER_RACE : TECH_TIER_TA_DRIFT;
}

/**
 * The formats a driver picked for one event. Ice events are always race (ice keeps its class-based
 * flow). Time Attack and Drift need the event's host club, because TA/Drift tech is per club.
 *
 * @param mixed $picked the posted list, e.g. $_POST['formats']
 * @return array{ok: bool, formats: string[], error: ?string}
 */
function entryFormatsValidate(array $event, $picked): array {
    if (($event['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_ICE) {
        return ['ok' => true, 'formats' => ['race'], 'error' => null];
    }
    $fail = fn(string $msg): array => ['ok' => false, 'formats' => [], 'error' => $msg];
    if (!is_array($picked) || $picked === []) return $fail(ENTRY_FORMAT_ERROR);
    foreach ($picked as $f) {
        if (!is_string($f) || !isset(ENTRY_FORMATS[$f])) return $fail(ENTRY_FORMAT_ERROR);
    }
    $formats = entryFormatsParse(implode(',', $picked));
    if (array_diff($formats, ['race']) !== [] && trim((string)($event['host_club'] ?? '')) === '') {
        return $fail(ENTRY_NO_HOST_CLUB);
    }
    return ['ok' => true, 'formats' => $formats, 'error' => null];
}

function techSheetIsTaDrift(array $sheet): bool {
    return ($sheet['sheet_type'] ?? '') === SHEET_TYPE_TA_DRIFT;
}

/**
 * A car's TA/Drift standing at one club for one year (spec §2 approval ladder): accepted race tech
 * (any club) wins; otherwise the state of its TA/Drift sheets for that club and year.
 *
 * @param array{state: string, via: ?string, sheet_id: ?int} $race    techCarStatus() of the car's summer race sheets
 * @param array{state: string, via: ?string, sheet_id: ?int} $taDrift techCarStatus() of its TA/Drift sheets (club, year)
 * @return array{state: string, via: ?string, sheet_id: ?int, tier: string}
 */
function taDriftCarTechStatus(array $race, array $taDrift): array {
    if ($race['state'] === 'accepted') return $race + ['tier' => TECH_TIER_RACE];
    return $taDrift + ['tier' => TECH_TIER_TA_DRIFT];
}

/** True if a summer gear record is accepted at a level that covers $tier. Race needs race level (NULL). */
function gearCoversTier(?array $gear, string $tier): bool {
    if ($gear === null || ($gear['status'] ?? '') !== 'accepted') return false;
    return $tier === TECH_TIER_TA_DRIFT || ($gear['level'] ?? null) === null;
}
```

- [ ] **Step 4: Run the test to confirm it passes, then run the full suite**

Run: `php phpunit.phar --filter TaDriftLibTest` → PASS. Then `php phpunit.phar` → all PASS.

- [ ] **Step 5: Commit**

```bash
git add ta-drift-lib.php tests/TaDriftLibTest.php
git commit -m "feat(ta-drift): entry formats, tech tier, approval ladder and gear coverage

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 4: TA/Drift photo lists (`photo-requirements.php`)

**Files:**
- Modify: `photo-requirements.php`
- Test: `tests/TaDriftPhotoRequirementsTest.php`

**Interfaces:**
- Consumes: the Task 2 constants, and `ICE_HELMET_STANDARDS` and `PHOTO_FHR_STANDARDS` (already in this file).
- Produces:
  - constants `TA_DRIFT_PHOTO_REQUIREMENTS_VERSION` and `TA_DRIFT_PHOTO_REQUIREMENTS` (keys prefixed `tad_`; a def may carry `'caged_only' => true`)
  - `photoRequirementsFor($subject, $scope)`:
    - for a car subject with `sheet_type` = `ta_drift`, returns the `tad_` car shots;
    - for a gear subject whose `photo_tier` is `ta_drift`, returns the `tad_` gear shots;
    - the `caged_only` shots appear only when `!empty($subject['caged'])`.
  - `photoRequirementByKey()` also finds `tad_` keys.
  - **Contract for plan 2:** a summer gear record gets the TA/Drift list only when the caller passes `photo_tier => 'ta_drift'` (and `caged`) in the subject array. Without them, it keeps the race list.

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/TaDriftPhotoRequirementsTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../photo-requirements.php';

final class TaDriftPhotoRequirementsTest extends TestCase
{
    public function testEveryKeyIsPrefixedAndWellFormed(): void
    {
        foreach (TA_DRIFT_PHOTO_REQUIREMENTS as $key => $def) {
            $this->assertStringStartsWith('tad_', $key);
            $this->assertContains($def['scope'], ['car', 'gear'], $key);
            $this->assertSame('required', $def['tier'], $key);
            $this->assertNotSame('', $def['label'], $key);
            $this->assertNotSame('', $def['guidance'], $key);
        }
    }

    public function testCarShotsForATaDriftSheet(): void
    {
        $sheet = ['discipline' => 'summer', 'sheet_type' => 'ta_drift', 'club' => 'WSCC', 'caged' => 0];
        $base = ['tad_front_34', 'tad_rear_34', 'tad_interior', 'tad_battery', 'tad_tow_front', 'tad_tow_rear'];
        $this->assertSame($base, array_keys(photoRequirementsFor($sheet, 'car')));
        $this->assertSame([...$base, 'tad_cage'], array_keys(photoRequirementsFor(['caged' => 1] + $sheet, 'car')));
        $this->assertSame(TA_DRIFT_PHOTO_REQUIREMENTS_VERSION, photoRequirementsFor($sheet, 'car')['tad_front_34']['version']);
    }

    public function testGearShotsOnlyWhenTheCallerAsksForTheTaDriftList(): void
    {
        $gear = ['discipline' => 'summer', 'season' => 2026];
        $this->assertSame(array_keys(photoRequirements('gear')), array_keys(photoRequirementsFor($gear, 'gear')));
        $this->assertSame(['tad_helmet_label'], array_keys(photoRequirementsFor($gear + ['photo_tier' => 'ta_drift'], 'gear')));
        $this->assertSame(['tad_helmet_label', 'tad_fhr_label'],
            array_keys(photoRequirementsFor($gear + ['photo_tier' => 'ta_drift', 'caged' => true], 'gear')));
    }

    public function testRaceAndIceListsAreUnchanged(): void
    {
        $this->assertSame(array_keys(photoRequirements('car')), array_keys(photoRequirementsFor(['discipline' => 'summer', 'sheet_type' => 'standard'], 'car')));
        $this->assertSame([], array_filter(array_keys(photoRequirementsFor(['discipline' => 'ice', 'club' => 'WSCC', 'class' => 'DRIFT', 'sheet_type' => 'ta_drift'], 'car')),
            fn(string $k): bool => str_starts_with($k, 'tad_')));
    }

    public function testLookupByKey(): void
    {
        $this->assertSame('tad_helmet_label', photoRequirementByKey('tad_helmet_label')['key']);
        $this->assertSame('front_34', photoRequirementByKey('front_34')['key']);
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `php phpunit.phar --filter TaDriftPhotoRequirementsTest`
Expected: FAIL. `TA_DRIFT_PHOTO_REQUIREMENTS` is undefined.

- [ ] **Step 3: Add the list**

In `photo-requirements.php`, directly before `/** Requirements for one scope ('car' or 'gear'), or all of them, keyed by requirement key. */`, add:

```php
// ── TA/Drift (2026-09-29 spec §1). Keys are prefixed tad_; 'caged_only' shots need a roll bar or cage. ──
const TA_DRIFT_PHOTO_REQUIREMENTS_VERSION = 1;

const TA_DRIFT_PHOTO_REQUIREMENTS = [
    // ── Car ──
    'tad_front_34' => [
        'scope' => 'car', 'tier' => 'required', 'typed' => [],
        'label' => 'Front three-quarter view',
        'guidance' => 'Whole front of the car with the car number visible.',
    ],
    'tad_rear_34' => [
        'scope' => 'car', 'tier' => 'required', 'typed' => [],
        'label' => 'Rear three-quarter view',
        'guidance' => 'Whole rear of the car with the car number visible.',
    ],
    'tad_interior' => [
        'scope' => 'car', 'tier' => 'required', 'typed' => [],
        'label' => "Interior from the driver's door",
        'guidance' => 'The seats, belts and floor, showing nothing is loose in the cabin.',
    ],
    'tad_battery' => [
        'scope' => 'car', 'tier' => 'required', 'typed' => [],
        'label' => 'Battery hold-down',
        'guidance' => 'The battery tie-down and the covered positive terminal.',
    ],
    'tad_tow_front' => [
        'scope' => 'car', 'tier' => 'required', 'typed' => [],
        'label' => 'Front tow point',
        'guidance' => 'The front tow hook, eye or strap. Factory ones are fine.',
    ],
    'tad_tow_rear' => [
        'scope' => 'car', 'tier' => 'required', 'typed' => [],
        'label' => 'Rear tow point',
        'guidance' => 'The rear tow hook, eye or strap. Factory ones are fine.',
    ],
    'tad_cage' => [
        'scope' => 'car', 'tier' => 'required', 'typed' => [], 'caged_only' => true,
        'label' => 'Roll bar or cage and harness',
        'guidance' => 'The roll bar or cage and the harness, from the open driver door.',
    ],
    // ── Gear ──
    'tad_helmet_label' => [
        'scope' => 'gear', 'tier' => 'required',
        'typed' => [
            ['name' => 'standard', 'label' => 'Standard', 'type' => 'select', 'options' => ICE_HELMET_STANDARDS],
            ['name' => 'date', 'label' => 'Date (MM/YYYY)', 'type' => 'month_year'],
        ],
        'label' => 'Helmet certification label',
        'guidance' => 'The inside label showing the certification standard and date.',
    ],
    'tad_fhr_label' => [
        'scope' => 'gear', 'tier' => 'required', 'caged_only' => true,
        'typed' => [
            ['name' => 'standard', 'label' => 'Standard', 'type' => 'select', 'options' => PHOTO_FHR_STANDARDS],
            ['name' => 'date', 'label' => 'Date (MM/YYYY)', 'type' => 'month_year'],
        ],
        'label' => 'Head and neck restraint label',
        'guidance' => 'The label on the head and neck restraint showing the standard and date.',
    ],
];

/** True if $subject takes the TA/Drift list for $scope: a ta_drift sheet (car), or a gear subject the caller marked photo_tier ta_drift. */
function photoSubjectIsTaDrift(array $subject, string $scope): bool {
    if (($subject['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_ICE) return false;
    return $scope === 'car'
        ? ($subject['sheet_type'] ?? '') === SHEET_TYPE_TA_DRIFT
        : ($subject['photo_tier'] ?? '') === TECH_TIER_TA_DRIFT;
}

```

In `photoRequirementByKey()`, replace:

```php
    if (isset(ICE_PHOTO_REQUIREMENTS[$key])) return ICE_PHOTO_REQUIREMENTS[$key] + ['key' => $key];
    return null;
```

with:

```php
    if (isset(ICE_PHOTO_REQUIREMENTS[$key])) return ICE_PHOTO_REQUIREMENTS[$key] + ['key' => $key];
    if (isset(TA_DRIFT_PHOTO_REQUIREMENTS[$key])) return TA_DRIFT_PHOTO_REQUIREMENTS[$key] + ['key' => $key];
    return null;
```

In `photoRequirementsFor()`, replace its first line:

```php
function photoRequirementsFor(array $subject, string $scope): array {
    if (($subject['discipline'] ?? DISCIPLINE_SUMMER) !== DISCIPLINE_ICE) {
```

with:

```php
function photoRequirementsFor(array $subject, string $scope): array {
    if (photoSubjectIsTaDrift($subject, $scope)) {
        $out = [];
        foreach (TA_DRIFT_PHOTO_REQUIREMENTS as $key => $def) {
            if ($def['scope'] !== $scope) continue;
            if (!empty($def['caged_only']) && empty($subject['caged'])) continue;
            $out[$key] = $def + ['version' => TA_DRIFT_PHOTO_REQUIREMENTS_VERSION];
        }
        return $out;
    }
    if (($subject['discipline'] ?? DISCIPLINE_SUMMER) !== DISCIPLINE_ICE) {
```

Add this sentence to the end of the doc comment above `photoRequirementsFor`: `A ta_drift sheet, or a gear subject with photo_tier 'ta_drift', gets the TA/Drift list (cage shots only when 'caged').`

- [ ] **Step 4: Run the test to confirm it passes, then run the full suite**

Run: `php phpunit.phar --filter TaDriftPhotoRequirementsTest` → PASS. Then `php phpunit.phar` → all PASS.

- [ ] **Step 5: Commit**

```bash
git add photo-requirements.php tests/TaDriftPhotoRequirementsTest.php
git commit -m "feat(ta-drift): TA/Drift car and gear photo lists

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 5: Schema, revoke notes, gear level and the tag result (`db.php`)

**Files:**
- Modify: `db.php` (the end of `db_init()`, `db_tag_event`, `db_get_user_event_plans`, the four accept functions, the two revoke functions, `db_set_gear_level`)
- Test: `tests/DbTaDriftSchemaTest.php`

**Interfaces:**
- Consumes: `GEAR_LEVEL_TA_DRIFT` (Task 2).
- Produces:
  - columns:
    - `event_plans.formats TEXT NOT NULL DEFAULT 'race'`
    - `event_plans.supps_ack_at DATETIME`
    - `tech_sheets.caged INTEGER NOT NULL DEFAULT 0`
    - `tech_sheets.revoke_note TEXT`
    - `gear_records.revoke_note TEXT`
  - `db_tag_event(...): bool`: true if the car was newly tagged.
  - `db_get_user_event_plans()` rows also carry `formats` and `supps_ack_at`.
  - `db_revoke_tech_sheet_acceptance(PDO $pdo, int $id, ?string $note = null): bool` and `db_revoke_gear_acceptance(PDO $pdo, int $id, ?string $note = null): bool` store the note.
  - Every accept (tech in person, tech by photos, gear in person, gear by photos) clears `revoke_note`.
  - `db_set_gear_level()` also accepts `GEAR_LEVEL_TA_DRIFT`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/DbTaDriftSchemaTest.php
use PHPUnit\Framework\TestCase;

final class DbTaDriftSchemaTest extends TestCase
{
    private function user(PDO $pdo): int {
        return db_create_user($pdo, ['email' => 'tad' . uniqid() . '@example.com', 'name' => 'TA Driver', 'password_hash' => 'x', 'google_id' => null]);
    }

    public function testFreshDatabaseHasTheColumns(): void
    {
        $pdo = make_temp_pdo();
        foreach ([['event_plans', 'formats'], ['event_plans', 'supps_ack_at'], ['tech_sheets', 'caged'],
                  ['tech_sheets', 'revoke_note'], ['gear_records', 'revoke_note']] as [$table, $column]) {
            $this->assertTrue(db_has_column($pdo, $table, $column), "$table.$column");
        }
    }

    public function testLegacyEntriesReadAsRace(): void
    {
        $pdo = test_make_legacy_pdo();
        $pdo->exec("CREATE TABLE event_plans (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
            event_id INTEGER NOT NULL, car_id INTEGER NOT NULL, created_at DATETIME NOT NULL, UNIQUE (event_id, car_id))");
        $pdo->exec("INSERT INTO event_plans (user_id, event_id, car_id, created_at) VALUES (1, 2, 3, '2026-05-01')");
        db_init($pdo);
        db_init($pdo);
        $row = $pdo->query("SELECT formats, supps_ack_at FROM event_plans")->fetch();
        $this->assertSame(['formats' => 'race', 'supps_ack_at' => null], $row);
    }

    public function testTagReportsWhetherItAdded(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $car = test_make_car($pdo, $uid, '86');
        $event = db_create_event($pdo, 'WSCC TA', '2026-07-12', null, 'summer', 'WSCC');
        $this->assertTrue(db_tag_event($pdo, $uid, $event, $car));
        $this->assertFalse(db_tag_event($pdo, $uid, $event, $car));
        $plan = db_get_user_event_plans($pdo, $uid)[0];
        $this->assertSame('race', $plan['formats']);
        $this->assertArrayHasKey('supps_ack_at', $plan);
    }

    public function testTechRevokeStoresTheNoteAndAcceptClearsIt(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $uid, '42'));
        $event = db_create_event($pdo, 'Spring Sprint', '2026-05-10', null);
        $id = test_make_sheet($pdo, $uid, $sub, $event);

        $this->assertTrue(db_accept_tech_sheet_in_person($pdo, $id, 1, 'sig.png'));
        $this->assertTrue(db_revoke_tech_sheet_acceptance($pdo, $id, 'Car changed: new engine'));
        $this->assertSame('Car changed: new engine', db_get_tech_sheet($pdo, $id)['revoke_note']);
        $this->assertTrue(db_accept_tech_sheet_in_person($pdo, $id, 1, 'sig.png'));
        $this->assertNull(db_get_tech_sheet($pdo, $id)['revoke_note']);

        $this->assertTrue(db_revoke_tech_sheet_acceptance($pdo, $id));   // callers without a note still work
        $this->assertNull(db_get_tech_sheet($pdo, $id)['revoke_note']);
    }

    public function testGearRevokeStoresTheNoteAndAcceptClearsIt(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $gear = db_insert_gear_record($pdo, db_find_or_create_driver($pdo, $uid, 'TA Driver'), 2026);

        $this->assertTrue(db_accept_gear_in_person($pdo, $gear, 1));
        $this->assertTrue(db_revoke_gear_acceptance($pdo, $gear, 'Helmet expired'));
        $this->assertSame('Helmet expired', db_get_gear_record($pdo, $gear)['revoke_note']);
        $this->assertTrue(db_accept_gear_in_person($pdo, $gear, 1));
        $this->assertNull(db_get_gear_record($pdo, $gear)['revoke_note']);
    }

    public function testGearLevelTakesTaDrift(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $gear = db_insert_gear_record($pdo, db_find_or_create_driver($pdo, $uid, 'TA Driver'), 2026);
        db_set_gear_level($pdo, $gear, GEAR_LEVEL_TA_DRIFT);
        $this->assertSame('ta_drift', db_get_gear_record($pdo, $gear)['level']);
        $this->expectException(InvalidArgumentException::class);
        db_set_gear_level($pdo, $gear, 'nonsense');
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `php phpunit.phar --filter DbTaDriftSchemaTest`
Expected: FAIL. The columns are missing, and `db_tag_event` returns null.

- [ ] **Step 3: Add the migration**

In `db.php`, at the very end of `db_init()`, directly after:

```php
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_media_consents_driver ON media_consents (driver_id, id)");
```

add:

```php

    // ── TA/Drift (2026-09-29 spec §2). Added in place: no reset. Existing entries read as race. ──
    db_add_column_if_missing($pdo, 'event_plans', 'formats', "TEXT NOT NULL DEFAULT 'race'");
    db_add_column_if_missing($pdo, 'event_plans', 'supps_ack_at', 'DATETIME');
    db_add_column_if_missing($pdo, 'tech_sheets', 'caged', 'INTEGER NOT NULL DEFAULT 0');
    db_add_column_if_missing($pdo, 'tech_sheets', 'revoke_note', 'TEXT');
    db_add_column_if_missing($pdo, 'gear_records', 'revoke_note', 'TEXT');
```

- [ ] **Step 4: Update the tag functions**

Replace `db_tag_event` and `db_get_user_event_plans` with:

```php
/** Tags the car for the event. True if it was not tagged yet (a new entry starts as race). */
function db_tag_event(PDO $pdo, int $userId, int $eventId, int $carId): bool {
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO event_plans (user_id, event_id, car_id, created_at) VALUES (:u, :e, :c, :now)");
    $stmt->execute([':u' => $userId, ':e' => $eventId, ':c' => $carId, ':now' => date('Y-m-d H:i:s')]);
    return $stmt->rowCount() === 1;
}
```

and:

```php
function db_get_user_event_plans(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT event_id, car_id, formats, supps_ack_at FROM event_plans WHERE user_id = :u ORDER BY event_id ASC, car_id ASC");
    $stmt->execute([':u' => $userId]);
    return $stmt->fetchAll();
}
```

- [ ] **Step 5: Store the revoke note and clear it on every accept**

In `db_accept_tech_sheet_in_person`, replace `tech_signature_path = :sig, tech_signed_at = :now, updated_at = :now` with `tech_signature_path = :sig, tech_signed_at = :now, revoke_note = NULL, updated_at = :now`.

In `db_accept_tech_sheet_by_photos`, replace:

```php
            status = 'teched', accepted_via = 'photos', photo_status = 'accepted',
            reviewed_by_user_id = :reviewer, reviewed_at = :now, updated_at = :now
        WHERE id = :id AND status = 'submitted' AND photo_status = 'submitted'
```

with:

```php
            status = 'teched', accepted_via = 'photos', photo_status = 'accepted', revoke_note = NULL,
            reviewed_by_user_id = :reviewer, reviewed_at = :now, updated_at = :now
        WHERE id = :id AND status = 'submitted' AND photo_status = 'submitted'
```

In `db_accept_gear_by_photos`, replace:

```php
            status = 'accepted', accepted_via = 'photos', photo_status = 'accepted',
            reviewed_by_user_id = :reviewer, reviewed_at = :now, updated_at = :now
        WHERE id = :id AND status = 'open' AND photo_status = 'submitted'
```

with:

```php
            status = 'accepted', accepted_via = 'photos', photo_status = 'accepted', revoke_note = NULL,
            reviewed_by_user_id = :reviewer, reviewed_at = :now, updated_at = :now
        WHERE id = :id AND status = 'open' AND photo_status = 'submitted'
```

In `db_accept_gear_in_person`, replace:

```php
            status = 'accepted', accepted_via = 'in_person',
            reviewed_by_user_id = :reviewer, reviewed_at = :now, updated_at = :now
        WHERE id = :id AND status = 'open'
```

with:

```php
            status = 'accepted', accepted_via = 'in_person', revoke_note = NULL,
            reviewed_by_user_id = :reviewer, reviewed_at = :now, updated_at = :now
        WHERE id = :id AND status = 'open'
```

Replace `db_revoke_tech_sheet_acceptance` with:

```php
/**
 * Returns an accepted sheet to 'submitted' and clears its review fields. $note says why (for example
 * the car changed substantially) and is shown to the owner until the sheet is accepted again.
 * False if it was not accepted.
 */
function db_revoke_tech_sheet_acceptance(PDO $pdo, int $id, ?string $note = null): bool {
    $stmt = $pdo->prepare("
        UPDATE tech_sheets SET
            status = 'submitted', accepted_via = NULL,
            photo_status = CASE WHEN photo_status = 'accepted' THEN 'submitted' ELSE photo_status END,
            reviewed_by_user_id = NULL, reviewed_at = NULL,
            tech_signature_path = NULL, tech_signed_at = NULL, revoke_note = :note, updated_at = :now
        WHERE id = :id AND status = 'teched'
    ");
    $stmt->execute([':note' => $note, ':now' => date('Y-m-d H:i:s'), ':id' => $id]);
    return $stmt->rowCount() === 1;
}
```

Replace `db_revoke_gear_acceptance` with:

```php
/** Undo an acceptance: back to open; a photo-accepted set returns to the review queue. $note says why. False if not accepted. */
function db_revoke_gear_acceptance(PDO $pdo, int $id, ?string $note = null): bool {
    $stmt = $pdo->prepare("
        UPDATE gear_records SET
            status = 'open', accepted_via = NULL,
            photo_status = CASE WHEN photo_status = 'accepted' THEN 'submitted' ELSE photo_status END,
            reviewed_by_user_id = NULL, reviewed_at = NULL, revoke_note = :note, updated_at = :now
        WHERE id = :id AND status = 'accepted'
    ");
    $stmt->execute([':note' => $note, ':now' => date('Y-m-d H:i:s'), ':id' => $id]);
    return $stmt->rowCount() === 1;
}
```

- [ ] **Step 6: Allow the TA/Drift gear level**

Replace the start of `db_set_gear_level`:

```php
/** The ice gear level an inspector confirmed: 'street_safe', 'caged', or null to clear it. */
function db_set_gear_level(PDO $pdo, int $id, ?string $level): void {
    if ($level !== null && !in_array($level, ['street_safe', 'caged'], true)) {
```

with:

```php
/**
 * The gear level an inspector confirmed: ice 'street_safe' or 'caged'; summer GEAR_LEVEL_TA_DRIFT;
 * or null to clear it (on a summer record, null is race level).
 */
function db_set_gear_level(PDO $pdo, int $id, ?string $level): void {
    if ($level !== null && !in_array($level, ['street_safe', 'caged', GEAR_LEVEL_TA_DRIFT], true)) {
```

- [ ] **Step 7: Run the test to confirm it passes, then run the full suite**

Run: `php phpunit.phar --filter DbTaDriftSchemaTest` → PASS. Then `php phpunit.phar` → all PASS. `EventsLibTest` maps only `event_id` and `car_id`, so the extra columns don't affect it.

- [ ] **Step 8: Commit**

```bash
git add db.php tests/DbTaDriftSchemaTest.php
git commit -m "feat(ta-drift): entry formats and revoke-note columns; TA/Drift gear level

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 6: `ta_drift` tech sheets in `db_insert_tech_sheet` and `db_update_tech_sheet`

**Files:**
- Modify: `db.php` (`db_insert_tech_sheet`, `db_update_tech_sheet`, and a new `db_ta_drift_club`)
- Modify: `tests/bootstrap.php` (a new helper, `test_make_ta_drift_sheet`)
- Test: `tests/DbTechSheetsTaDriftTest.php`

**Interfaces:**
- Consumes: `SHEET_TYPE_TA_DRIFT` (Task 2) and the `tech_sheets.caged` column (Task 5).
- Produces:
  - `db_insert_tech_sheet($pdo, $data)` with `$data['sheet_type'] === 'ta_drift'`:
    - takes `car_id` (one of the user's cars), with no `submission_id`
    - the event must be summer and have a `host_club`
    - stores `discipline` = `summer`, `club` = the host club, and `caged` = `(int)!empty($data['caged'])`
  - For all sheet types, `caged` is taken from `$data` (default 0).
  - `db_update_tech_sheet()`:
    - refuses to change `sheet_type` between `ta_drift` and anything else
    - for `ta_drift`, re-reads the club from the new event's `host_club` (refusing an event without one)
    - stores `caged`
  - `db_ta_drift_club(PDO $pdo, int $eventId): string`: the host club, or throws `InvalidArgumentException('A TA/Drift tech sheet needs an event with a host club.')`
  - test helper `test_make_ta_drift_sheet(PDO $pdo, int $userId, int $carId, int $eventId, bool $caged = false): int`

- [ ] **Step 1: Add the test helper**

Append to `tests/bootstrap.php`:

```php

/** A submitted TA/Drift tech sheet (no declaration) for car $carId at summer event $eventId, which needs a host club. */
function test_make_ta_drift_sheet(PDO $pdo, int $userId, int $carId, int $eventId, bool $caged = false): int {
    return db_insert_tech_sheet($pdo, test_ta_drift_sheet_data($userId, $carId, $eventId, $caged));
}

/** The db_insert_tech_sheet()/db_update_tech_sheet() array for a TA/Drift sheet. */
function test_ta_drift_sheet_data(int $userId, int $carId, int $eventId, bool $caged = false): array {
    return [
        'car_id' => $carId, 'user_id' => $userId, 'event_id' => $eventId, 'sheet_type' => 'ta_drift', 'caged' => $caged,
        'entrant_name' => 'Test Driver', 'driver_name' => 'Test Driver', 'car_make' => 'Subaru', 'car_model' => 'BRZ',
        'car_colour' => 'White', 'car_number' => '86', 'class' => '', 'engine_cc' => null, 'engine_hp' => null,
        'car_weight' => 0, 'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'log_book_turned_in' => null,
    ];
}
```

- [ ] **Step 2: Write the failing test**

```php
<?php
// wcma-calculator/tests/DbTechSheetsTaDriftTest.php
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
```

- [ ] **Step 3: Run the test to confirm it fails**

Run: `php phpunit.phar --filter DbTechSheetsTaDriftTest`
Expected: FAIL. The `ta_drift` sheet falls into the summer branch and throws "A tech sheet needs an existing class declaration."

- [ ] **Step 4: Implement the insert branch**

In `db.php`, directly above `function db_insert_tech_sheet`, add:

```php
/** The host club a TA/Drift sheet is keyed to: its event's host_club. Throws if the event has none. */
function db_ta_drift_club(PDO $pdo, int $eventId): string {
    $club = trim((string)(db_get_event($pdo, $eventId)['host_club'] ?? ''));
    if ($club === '') throw new InvalidArgumentException('A TA/Drift tech sheet needs an event with a host club.');
    return $club;
}

```

In `db_insert_tech_sheet`, replace:

```php
    $identity = db_tech_sheet_identity($pdo, (string)$data['car_number'], (int)$data['event_id']);
    if ($identity['discipline'] === DISCIPLINE_ICE) {
        if (!empty($data['submission_id'])) {
            throw new InvalidArgumentException('An ice tech sheet does not take a class declaration.');
```

with:

```php
    $identity = db_tech_sheet_identity($pdo, (string)$data['car_number'], (int)$data['event_id']);
    if (($data['sheet_type'] ?? '') === SHEET_TYPE_TA_DRIFT) {
        if ($identity['discipline'] !== DISCIPLINE_SUMMER) {
            throw new InvalidArgumentException('A TA/Drift tech sheet is for summer events.');
        }
        if (!empty($data['submission_id'])) {
            throw new InvalidArgumentException('A TA/Drift tech sheet does not take a class declaration.');
        }
        $car = db_get_user_car($pdo, (int)$data['user_id'], (int)($data['car_id'] ?? 0));
        if ($car === null) {
            throw new InvalidArgumentException('A TA/Drift tech sheet needs one of your cars.');
        }
        $identity['club'] = db_ta_drift_club($pdo, (int)$data['event_id']);
        $submissionId = null;
        $carId = (int)$car['id'];
    } elseif ($identity['discipline'] === DISCIPLINE_ICE) {
        if (!empty($data['submission_id'])) {
            throw new InvalidArgumentException('An ice tech sheet does not take a class declaration.');
```

In the INSERT statement, replace:

```php
            car_number_norm, season, discipline, club,
            status, created_at, updated_at
```

with:

```php
            car_number_norm, season, discipline, club, caged,
            status, created_at, updated_at
```

Replace:

```php
            :car_number_norm, :season, :discipline, :club,
            'submitted', :created_at, :updated_at
```

with:

```php
            :car_number_norm, :season, :discipline, :club, :caged,
            'submitted', :created_at, :updated_at
```

Replace:

```php
        ':discipline' => $identity['discipline'], ':club' => $identity['club'],
        ':created_at' => $now, ':updated_at' => $now,
```

with:

```php
        ':discipline' => $identity['discipline'], ':club' => $identity['club'], ':caged' => empty($data['caged']) ? 0 : 1,
        ':created_at' => $now, ':updated_at' => $now,
```

- [ ] **Step 5: Implement the update guard**

In `db_update_tech_sheet`, replace:

```php
        throw new InvalidArgumentException('A tech sheet cannot move between summer and ice events.');
    }
    $owner = (int)($current['user_id'] ?? 0);
```

with:

```php
        throw new InvalidArgumentException('A tech sheet cannot move between summer and ice events.');
    }
    $isTaDrift = ($data['sheet_type'] ?? '') === SHEET_TYPE_TA_DRIFT;
    if ($current !== null && (($current['sheet_type'] ?? '') === SHEET_TYPE_TA_DRIFT) !== $isTaDrift) {
        throw new InvalidArgumentException('A tech sheet cannot change between TA/Drift and race.');
    }
    if ($isTaDrift) $identity['club'] = db_ta_drift_club($pdo, (int)$data['event_id']);
    $owner = (int)($current['user_id'] ?? 0);
```

In the UPDATE statement, replace:

```php
            car_number_norm = :car_number_norm, season = :season, club = :club, updated_at = :updated_at
```

with:

```php
            car_number_norm = :car_number_norm, season = :season, club = :club, caged = :caged, updated_at = :updated_at
```

Replace:

```php
        ':club' => $identity['club'],
        ':updated_at' => date('Y-m-d H:i:s'), ':id' => $id,
```

with:

```php
        ':club' => $identity['club'], ':caged' => empty($data['caged']) ? 0 : 1,
        ':updated_at' => date('Y-m-d H:i:s'), ':id' => $id,
```

- [ ] **Step 6: Run the test to confirm it passes, then run the full suite**

Run: `php phpunit.phar --filter DbTechSheetsTaDriftTest` → PASS. Then `php phpunit.phar` → all PASS. Race and ice updates don't pass `caged`, so it stays 0.

- [ ] **Step 7: Commit**

```bash
git add db.php tests/bootstrap.php tests/DbTechSheetsTaDriftTest.php
git commit -m "feat(ta-drift): TA/Drift tech sheets keyed to the host club, no declaration

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 7: Entries: formats, defaults and the regulations tick (`events-lib.php`, `db.php`)

**Files:**
- Modify: `db.php` (new functions `db_get_entry`, `db_set_entry_formats` and `db_get_car_last_summer_formats`, added after `db_get_user_event_plans`)
- Modify: `events-lib.php` (`eventsTagCar` gains optional formats; new functions `eventsSetFormats`, `eventsDefaultFormats` and `eventsStoreFormats`)
- Test: `tests/EventsTaDriftTest.php`

**Interfaces:**
- Consumes:
  - from Task 3: `entryFormatsParse`, `entryFormatsStore`, `entryFormatsValidate`, `entryTechTier` and `ENTRY_NO_HOST_CLUB`
  - from Task 5: `db_tag_event(): bool`
- Produces:
  - `db_get_entry(PDO $pdo, int $userId, int $eventId, int $carId): ?array`
  - `db_set_entry_formats(PDO $pdo, int $userId, int $eventId, int $carId, string $formats, ?string $suppsAckAt): void`
  - `db_get_car_last_summer_formats(PDO $pdo, int $carId, int $exceptEventId): ?string`
  - `eventsTagCar(PDO $pdo, int $userId, int $eventId, int $carId, ?array $formats = null, bool $suppsAck = false): array{ok: bool, error: ?string}`. Null formats leave an existing entry alone and give a new one its defaults.
  - `eventsSetFormats(PDO $pdo, int $userId, int $eventId, int $carId, mixed $formats, bool $suppsAck): array{ok: bool, error: ?string}`
  - `eventsDefaultFormats(PDO $pdo, array $car, array $event): string[]`
  - `supps_ack_at`:
    - is set only when the tier is TA/Drift and the box is ticked;
    - keeps its first time while it stays ticked;
    - is cleared otherwise.

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/EventsTaDriftTest.php
require_once __DIR__ . '/../events-lib.php';

use PHPUnit\Framework\TestCase;

final class EventsTaDriftTest extends TestCase
{
    /** @return array{0: PDO, 1: int, 2: int, 3: int, 4: array<string, int>} */
    private function world(): array {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'tad' . uniqid() . '@example.com', 'name' => 'TA Driver', 'password_hash' => 'x', 'google_id' => null]);
        $car = db_create_car($pdo, $u, ['car_number' => '42', 'make' => 'Honda', 'model' => 'S2000', 'disciplines' => 'summer']);
        $taCar = db_create_car($pdo, $u, ['car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'disciplines' => 'ta_drift']);
        $events = [
            'wscc' => db_create_event($pdo, 'WSCC TA', '2026-07-12', null, 'summer', 'WSCC'),
            'wscc2' => db_create_event($pdo, 'WSCC TA 2', '2026-08-16', null, 'summer', 'WSCC'),
            'noclub' => db_create_event($pdo, 'Open Day', '2026-08-01', null),
            'ice' => db_create_event($pdo, 'Ice Drift', '2027-01-16', null, 'ice', 'WSCC'),
        ];
        return [$pdo, $u, $car, $taCar, $events];
    }

    private function formats(PDO $pdo, int $u, int $event, int $car): string {
        return (string)db_get_entry($pdo, $u, $event, $car)['formats'];
    }

    public function testNewEntryDefaultsToRace(): void
    {
        [$pdo, $u, $car, , $e] = $this->world();
        $this->assertTrue(eventsTagCar($pdo, $u, $e['wscc'], $car)['ok']);
        $this->assertSame('race', $this->formats($pdo, $u, $e['wscc'], $car));
    }

    public function testTaDriftOnlyCarDefaultsToTimeAttack(): void
    {
        [$pdo, $u, , $taCar, $e] = $this->world();
        eventsTagCar($pdo, $u, $e['wscc'], $taCar);
        $this->assertSame('ta', $this->formats($pdo, $u, $e['wscc'], $taCar));
    }

    public function testDefaultsFollowTheCarsLastSummerEntry(): void
    {
        [$pdo, $u, $car, , $e] = $this->world();
        $this->assertTrue(eventsTagCar($pdo, $u, $e['wscc'], $car, ['drift', 'ta'])['ok']);
        eventsTagCar($pdo, $u, $e['wscc2'], $car);
        $this->assertSame('ta,drift', $this->formats($pdo, $u, $e['wscc2'], $car));
    }

    public function testNoHostClubMeansRaceOnly(): void
    {
        [$pdo, $u, $car, $taCar, $e] = $this->world();
        eventsTagCar($pdo, $u, $e['noclub'], $taCar);
        $this->assertSame('race', $this->formats($pdo, $u, $e['noclub'], $taCar));

        $r = eventsTagCar($pdo, $u, $e['noclub'], $car, ['ta']);
        $this->assertSame(['ok' => false, 'error' => ENTRY_NO_HOST_CLUB], $r);
        $this->assertNull(db_get_entry($pdo, $u, $e['noclub'], $car));   // refused before tagging
    }

    public function testIceEntryIsAlwaysRace(): void
    {
        [$pdo, $u, $car, , $e] = $this->world();
        $this->assertTrue(eventsTagCar($pdo, $u, $e['ice'], $car, ['drift'], true)['ok']);
        $entry = db_get_entry($pdo, $u, $e['ice'], $car);
        $this->assertSame('race', $entry['formats']);
        $this->assertNull($entry['supps_ack_at']);
    }

    public function testRetagKeepsChosenFormats(): void
    {
        [$pdo, $u, $car, , $e] = $this->world();
        eventsTagCar($pdo, $u, $e['wscc'], $car, ['ta'], true);
        $ack = db_get_entry($pdo, $u, $e['wscc'], $car)['supps_ack_at'];
        eventsTagCar($pdo, $u, $e['wscc'], $car);   // e.g. the auto-tag when a sheet is submitted
        $this->assertSame('ta', $this->formats($pdo, $u, $e['wscc'], $car));
        $this->assertSame($ack, db_get_entry($pdo, $u, $e['wscc'], $car)['supps_ack_at']);
    }

    public function testRegulationsTickOnlyForTaDriftAndKeptWhileTicked(): void
    {
        [$pdo, $u, $car, , $e] = $this->world();
        eventsTagCar($pdo, $u, $e['wscc'], $car, ['ta'], true);
        $ack = db_get_entry($pdo, $u, $e['wscc'], $car)['supps_ack_at'];
        $this->assertNotNull($ack);

        $this->assertTrue(eventsSetFormats($pdo, $u, $e['wscc'], $car, ['ta', 'drift'], true)['ok']);
        $this->assertSame($ack, db_get_entry($pdo, $u, $e['wscc'], $car)['supps_ack_at']);

        eventsSetFormats($pdo, $u, $e['wscc'], $car, ['race', 'ta'], true);
        $this->assertNull(db_get_entry($pdo, $u, $e['wscc'], $car)['supps_ack_at']);

        eventsSetFormats($pdo, $u, $e['wscc'], $car, ['ta'], false);
        $this->assertNull(db_get_entry($pdo, $u, $e['wscc'], $car)['supps_ack_at']);
    }

    public function testSetFormatsNeedsAnEntryAndValidFormats(): void
    {
        [$pdo, $u, $car, , $e] = $this->world();
        $this->assertSame('Add this car to the event first.', eventsSetFormats($pdo, $u, $e['wscc'], $car, ['ta'], false)['error']);
        eventsTagCar($pdo, $u, $e['wscc'], $car);
        $this->assertSame(ENTRY_FORMAT_ERROR, eventsSetFormats($pdo, $u, $e['wscc'], $car, [], false)['error']);
        $this->assertSame(ENTRY_FORMAT_ERROR, eventsSetFormats($pdo, $u, $e['wscc'], $car, ['rally'], false)['error']);
        $this->assertSame('race', $this->formats($pdo, $u, $e['wscc'], $car));

        $other = db_create_user($pdo, ['email' => 'o' . uniqid() . '@example.com', 'name' => 'Other', 'password_hash' => 'x', 'google_id' => null]);
        $this->assertFalse(eventsSetFormats($pdo, $other, $e['wscc'], $car, ['ta'], false)['ok']);
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `php phpunit.phar --filter EventsTaDriftTest`
Expected: FAIL. `db_get_entry` is undefined.

- [ ] **Step 3: Add the DB functions**

In `db.php`, directly after `db_get_user_event_plans`, add:

```php
/** One entry (event_plans row) of the user's, or null. */
function db_get_entry(PDO $pdo, int $userId, int $eventId, int $carId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM event_plans WHERE user_id = :u AND event_id = :e AND car_id = :c");
    $stmt->execute([':u' => $userId, ':e' => $eventId, ':c' => $carId]);
    return $stmt->fetch() ?: null;
}

/** An entry's formats (as entryFormatsStore() writes them) and when the regulations box was ticked (null = not ticked). */
function db_set_entry_formats(PDO $pdo, int $userId, int $eventId, int $carId, string $formats, ?string $suppsAckAt): void {
    $pdo->prepare("UPDATE event_plans SET formats = :f, supps_ack_at = :a WHERE user_id = :u AND event_id = :e AND car_id = :c")
        ->execute([':f' => $formats, ':a' => $suppsAckAt, ':u' => $userId, ':e' => $eventId, ':c' => $carId]);
}

/** The formats of the car's most recently made summer entry, other than $exceptEventId. Null if none. */
function db_get_car_last_summer_formats(PDO $pdo, int $carId, int $exceptEventId): ?string {
    $stmt = $pdo->prepare("
        SELECT p.formats FROM event_plans p JOIN events e ON e.id = p.event_id
        WHERE p.car_id = :c AND p.event_id != :x AND e.discipline = 'summer'
        ORDER BY p.created_at DESC, p.id DESC LIMIT 1
    ");
    $stmt->execute([':c' => $carId, ':x' => $exceptEventId]);
    $f = $stmt->fetchColumn();
    return $f === false ? null : (string)$f;
}
```

- [ ] **Step 4: Update `events-lib.php`**

Replace the `require_once` line at the top with:

```php
require_once __DIR__ . '/ice-rules.php';
require_once __DIR__ . '/ta-drift-lib.php';
```

Replace `eventsTagCar` with:

```php
/**
 * Tags the car for the event (TA/Drift spec §3 Entry). With $formats, they are validated first and
 * stored, with the regulations tick. Without, an entry that already exists is left alone (so the
 * auto-tag on sheet submit never resets a choice) and a new one gets eventsDefaultFormats().
 *
 * @param ?string[] $formats
 * @return array{ok: bool, error: ?string}
 */
function eventsTagCar(PDO $pdo, int $userId, int $eventId, int $carId, ?array $formats = null, bool $suppsAck = false): array {
    $car = db_get_user_car($pdo, $userId, $carId);
    if ($car === null || $car['archived_at'] !== null) return ['ok' => false, 'error' => 'Choose one of your cars.'];
    $event = db_get_event($pdo, $eventId);
    if ($event === null || (int)$event['active'] !== 1) return ['ok' => false, 'error' => 'That event is not open.'];
    if ($formats !== null) {
        $v = entryFormatsValidate($event, $formats);
        if (!$v['ok']) return ['ok' => false, 'error' => $v['error']];
    }
    $added = db_tag_event($pdo, $userId, $eventId, $carId);
    if ($formats !== null) {
        eventsStoreFormats($pdo, $userId, $eventId, $carId, $v['formats'], $suppsAck);
    } elseif ($added) {
        db_set_entry_formats($pdo, $userId, $eventId, $carId, entryFormatsStore(eventsDefaultFormats($pdo, $car, $event)), null);
    }
    return ['ok' => true, 'error' => null];
}

/**
 * Change the formats of an entry the user already has (the event card).
 * @param mixed $formats the posted list
 * @return array{ok: bool, error: ?string}
 */
function eventsSetFormats(PDO $pdo, int $userId, int $eventId, int $carId, $formats, bool $suppsAck): array {
    if (db_get_entry($pdo, $userId, $eventId, $carId) === null) return ['ok' => false, 'error' => 'Add this car to the event first.'];
    $event = db_get_event($pdo, $eventId);
    if ($event === null) return ['ok' => false, 'error' => 'That event is not open.'];
    $v = entryFormatsValidate($event, $formats);
    if (!$v['ok']) return ['ok' => false, 'error' => $v['error']];
    eventsStoreFormats($pdo, $userId, $eventId, $carId, $v['formats'], $suppsAck);
    return ['ok' => true, 'error' => null];
}

/**
 * Stores validated formats. The regulations tick counts only for a TA/Drift entry; it keeps the time
 * it was first ticked while it stays ticked, and is cleared otherwise.
 */
function eventsStoreFormats(PDO $pdo, int $userId, int $eventId, int $carId, array $formats, bool $suppsAck): void {
    $ack = null;
    if ($suppsAck && entryTechTier($formats) === TECH_TIER_TA_DRIFT) {
        $ack = (db_get_entry($pdo, $userId, $eventId, $carId)['supps_ack_at'] ?? null) ?: date('Y-m-d H:i:s');
    }
    db_set_entry_formats($pdo, $userId, $eventId, $carId, entryFormatsStore($formats), $ack);
}

/**
 * The formats a new entry starts with: the car's last summer entry, else Time Attack for a
 * TA/Drift-only car, else Race. Ice events and summer events with no host club are always Race.
 * @return string[]
 */
function eventsDefaultFormats(PDO $pdo, array $car, array $event): array {
    if (($event['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_ICE || trim((string)($event['host_club'] ?? '')) === '') {
        return ['race'];
    }
    $last = db_get_car_last_summer_formats($pdo, (int)$car['id'], (int)$event['id']);
    if ($last !== null) return entryFormatsParse($last);
    return ($car['disciplines'] ?? null) === 'ta_drift' ? ['ta'] : ['race'];
}
```

- [ ] **Step 5: Run the test to confirm it passes, then run the full suite**

Run: `php phpunit.phar --filter EventsTaDriftTest` → PASS. Then `php phpunit.phar` → all PASS (`EventsLibTest` included).

- [ ] **Step 6: Commit**

```bash
git add db.php events-lib.php tests/EventsTaDriftTest.php
git commit -m "feat(ta-drift): entry formats with defaults and the regulations tick

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 8: Per-club "at the track" for TA/Drift car tech

**Files:**
- Modify: `events-lib.php` (`eventsSetAtTrack`)
- Modify: `db.php` (`db_get_at_track_keys`)
- Test: `tests/AtTrackTaDriftTest.php`

**Interfaces:**
- Consumes: `atTrackKey(..., TECH_TIER_TA_DRIFT, $club)` (Task 2).
- Produces:
  - `eventsSetAtTrack($pdo, $userId, 'car', $carId, $season, TECH_TIER_TA_DRIFT, $club)` stores a summer choice with `club` set. It works for cars only, and the club must look like a club code (`^[A-Z0-9-]{2,12}$`).
  - `db_get_at_track_keys($pdo, $cars, $drivers, $season)` (summer) returns `car:ID@tad:CLUB:season` for those rows. Race summer keys are unchanged.

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/AtTrackTaDriftTest.php
require_once __DIR__ . '/../events-lib.php';

use PHPUnit\Framework\TestCase;

final class AtTrackTaDriftTest extends TestCase
{
    public function testTaDriftChoiceIsPerClubAndSeparateFromRace(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'tad' . uniqid() . '@example.com', 'name' => 'TA Driver', 'password_hash' => 'x', 'google_id' => null]);
        $car = test_make_car($pdo, $u, '86');

        $this->assertTrue(eventsSetAtTrack($pdo, $u, 'car', $car, 2026, TECH_TIER_TA_DRIFT, 'WSCC')['ok']);
        $this->assertTrue(eventsSetAtTrack($pdo, $u, 'car', $car, 2026, TECH_TIER_TA_DRIFT, 'WSCC')['ok']);   // no duplicate
        $this->assertSame(["car:$car@tad:WSCC:2026"], db_get_at_track_keys($pdo, [$car], [], 2026));

        eventsSetAtTrack($pdo, $u, 'car', $car, 2026);
        $this->assertEqualsCanonicalizing(["car:$car@tad:WSCC:2026", "car:$car@2026"], db_get_at_track_keys($pdo, [$car], [], 2026));
        $this->assertSame([], db_get_at_track_keys($pdo, [$car], [], 2026, DISCIPLINE_ICE));
    }

    public function testRejectsDriversBadClubsAndOtherPeoplesCars(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'tad' . uniqid() . '@example.com', 'name' => 'TA Driver', 'password_hash' => 'x', 'google_id' => null]);
        $other = db_create_user($pdo, ['email' => 'o' . uniqid() . '@example.com', 'name' => 'Other', 'password_hash' => 'x', 'google_id' => null]);
        $driver = db_find_or_create_driver($pdo, $u, 'TA Driver');

        $this->assertFalse(eventsSetAtTrack($pdo, $u, 'driver', $driver, 2026, TECH_TIER_TA_DRIFT, 'WSCC')['ok']);
        $this->assertFalse(eventsSetAtTrack($pdo, $u, 'car', test_make_car($pdo, $u, '86'), 2026, TECH_TIER_TA_DRIFT, '')['ok']);
        $this->assertFalse(eventsSetAtTrack($pdo, $u, 'car', test_make_car($pdo, $other, '7'), 2026, TECH_TIER_TA_DRIFT, 'WSCC')['ok']);
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `php phpunit.phar --filter AtTrackTaDriftTest`
Expected: FAIL. `eventsSetAtTrack` returns "Unknown item." for `ta_drift`.

- [ ] **Step 3: Implement**

In `events-lib.php`, replace the body of `eventsSetAtTrack` from its first line down to and including `db_set_at_track(...)` with:

```php
function eventsSetAtTrack(PDO $pdo, int $userId, string $subjectType, int $subjectId, int $season,
                          string $discipline = DISCIPLINE_SUMMER, string $club = ''): array {
    $unknown = ['ok' => false, 'error' => 'Unknown item.'];
    $stored = $discipline;
    if ($discipline === DISCIPLINE_SUMMER) {
        $club = '';
    } elseif ($discipline === TECH_TIER_TA_DRIFT) {
        // TA/Drift car tech is per host club: stored as a summer choice that carries the club.
        if ($subjectType !== 'car' || !preg_match('/^[A-Z0-9-]{2,12}$/', $club)) return $unknown;
        $stored = DISCIPLINE_SUMMER;
    } elseif ($discipline !== DISCIPLINE_ICE) {
        return $unknown;
    } elseif ($subjectType === 'car' && !in_array($club, iceClubCodes(), true)) {
        return $unknown;
    } elseif ($subjectType === 'driver') {
        $club = '';   // ice gear covers both clubs
    }
    if ($subjectType === 'car') {
        $owned = db_get_user_car($pdo, $userId, $subjectId) !== null;
    } elseif ($subjectType === 'driver') {
        $driver = db_get_driver($pdo, $subjectId);
        $owned = $driver !== null && (int)$driver['owner_user_id'] === $userId;
    } else {
        return $unknown;
    }
    if (!$owned) return $unknown;
    db_set_at_track($pdo, $subjectType, $subjectId, $season, $stored, $club);
    return ['ok' => true, 'error' => null];
}
```

Update its doc comment to: `/** "I'll do it at the track": planning only, it never accepts anything. $discipline is summer, ice, or TECH_TIER_TA_DRIFT (car tech at one host club). @return array{ok: bool, error: ?string} */`

In `db.php`, `db_get_at_track_keys`, replace:

```php
            $keys[] = atTrackKey($r['subject_type'], $id, $season, $discipline, (string)$r['club']);
```

with:

```php
            // A summer row with a club is TA/Drift car tech at that club (TA/Drift spec §2).
            $keyDiscipline = $discipline === DISCIPLINE_SUMMER && (string)$r['club'] !== '' ? TECH_TIER_TA_DRIFT : $discipline;
            $keys[] = atTrackKey($r['subject_type'], $id, $season, $keyDiscipline, (string)$r['club']);
```

- [ ] **Step 4: Run the test to confirm it passes, then run the full suite**

Run: `php phpunit.phar --filter AtTrackTaDriftTest` → PASS. Then `php phpunit.phar` → all PASS (`AtTrackIceTest` and `IceMigrationTest` included).

- [ ] **Step 5: Commit**

```bash
git add events-lib.php db.php tests/AtTrackTaDriftTest.php
git commit -m "feat(ta-drift): per-club at-the-track choice for TA/Drift car tech

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 9: TA/Drift-only cars and the ice carry-over rule

**Files:**
- Modify: `garage-lib.php` (`CAR_DISCIPLINES`, `carSeasons`)
- Modify: `cars-lib.php` (`carsValidateDetails`)
- Modify: `gear-lib.php` (`gearIceSummary`)
- Modify: `readiness-lib.php` (`readinessIceGear`)
- Modify: `tests/CarDetailsTest.php` (the error message changes)
- Test: `tests/TaDriftCarsAndCarryOverTest.php`

**Interfaces:**
- Consumes: `gearCoversTier` and `TECH_TIER_RACE` (Task 3).
- Produces:
  - `CAR_DISCIPLINES = ['ice', 'summer', 'both', 'ta_drift']`
  - `carSeasons('ta_drift', false, false) === ['summer' => true, 'ice' => false]`
  - `carsValidateDetails` accepts `disciplines` = `ta_drift`. Its error message is now `Choose where this car will race: ice, summer, both, or summer TA/Drift only.`
  - Summer gear carries over to ice only when `gearCoversTier($summer, TECH_TIER_RACE)` is true.

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/TaDriftCarsAndCarryOverTest.php
require_once __DIR__ . '/../tech-status.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../garage-lib.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../readiness-lib.php';

use PHPUnit\Framework\TestCase;

final class TaDriftCarsAndCarryOverTest extends TestCase
{
    public function testTaDriftOnlyCarsRaceSummerOnly(): void
    {
        $this->assertContains('ta_drift', CAR_DISCIPLINES);
        $this->assertSame(['summer' => true, 'ice' => false], carSeasons('ta_drift', false, false));
        $this->assertSame(['summer' => true, 'ice' => true], carSeasons('ta_drift', false, true));   // activity is never hidden
        $this->assertSame(['summer' => true, 'ice' => true], carSeasons('both', false, false));
    }

    public function testCarFormAcceptsTaDriftOnly(): void
    {
        $form = ['car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'colour' => 'White', 'disciplines' => 'ta_drift'];
        $r = carsValidateDetails($form, true);
        $this->assertTrue($r['ok']);
        $this->assertSame('ta_drift', $r['data']['disciplines']);
    }

    public function testTaDriftSummerGearDoesNotCarryOverToIce(): void
    {
        $summer = fn(?string $level): array => ['id' => 3, 'season' => 2026, 'discipline' => 'summer', 'level' => $level,
            'status' => 'accepted', 'accepted_via' => 'in_person', 'photo_status' => null];

        $this->assertSame('accepted', gearIceSummary(null, $summer(null), 2027)['state']);
        $this->assertSame('none', gearIceSummary(null, $summer(GEAR_LEVEL_TA_DRIFT), 2027)['state']);

        $race = readinessIceGear(5, 'Sam', 2027, null, $summer(null), null, false, false, null);
        $this->assertSame('done', $race['state']);
        $tad = readinessIceGear(5, 'Sam', 2027, null, $summer(GEAR_LEVEL_TA_DRIFT), null, false, false, null);
        $this->assertSame('todo', $tad['state']);
        $this->assertStringNotContainsString('from summer', $tad['label']);
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `php phpunit.phar --filter TaDriftCarsAndCarryOverTest`
Expected: FAIL. `ta_drift` isn't in `CAR_DISCIPLINES`, and the carry-over accepts TA/Drift-level gear.

- [ ] **Step 3: Implement the car changes**

In `garage-lib.php`, replace:

```php
/** A car's stored season (mobile UX spec 2026-09-28 §A1). Null on cars added before it existed. */
const CAR_DISCIPLINES = ['ice', 'summer', 'both'];
```

with:

```php
/**
 * A car's stored season (mobile UX spec 2026-09-28 §A1). Null on cars added before it existed.
 * 'ta_drift' is a summer car that only runs Time Attack and Drift (TA/Drift spec §2): no class declaration.
 */
const CAR_DISCIPLINES = ['ice', 'summer', 'both', 'ta_drift'];
```

In `carSeasons`, replace:

```php
    return ['summer' => $s !== 'ice' || $summerActivity, 'ice' => $s !== 'summer' || $iceActivity];
```

with:

```php
    return ['summer' => $s !== 'ice' || $summerActivity, 'ice' => in_array($s, ['ice', 'both'], true) || $iceActivity];
```

In `cars-lib.php`, replace:

```php
    if (($season === '' && $requireSeason) || ($season !== '' && !in_array($season, ['ice', 'summer', 'both'], true))) {
        return $fail('Choose where this car will race: ice, summer or both.');
```

with:

```php
    if (($season === '' && $requireSeason) || ($season !== '' && !in_array($season, ['ice', 'summer', 'both', 'ta_drift'], true))) {
        return $fail('Choose where this car will race: ice, summer, both, or summer TA/Drift only.');
```

In `tests/CarDetailsTest.php`, replace both occurrences of `'Choose where this car will race: ice, summer or both.'` with `'Choose where this car will race: ice, summer, both, or summer TA/Drift only.'`

- [ ] **Step 4: Restrict the carry-over to race-level gear**

In `gear-lib.php`, add `require_once __DIR__ . '/ta-drift-lib.php';` after `require_once __DIR__ . '/ice-rules.php';`. In `gearIceSummary`, replace:

```php
    if ($summerPrev !== null && gearStatus($summerPrev)['state'] === 'accepted') {
```

with:

```php
    // Only race-level summer gear counts on ice; TA/Drift-level gear doesn't meet caged standards.
    if (gearCoversTier($summerPrev, TECH_TIER_RACE)) {
```

In `readiness-lib.php`, add `require_once __DIR__ . '/ta-drift-lib.php';` after `require_once __DIR__ . '/ice-rules.php';`. In `readinessIceGear`, replace:

```php
    if ($summerGear !== null && gearStatus($summerGear)['state'] === 'accepted') {
```

with:

```php
    if (gearCoversTier($summerGear, TECH_TIER_RACE)) {   // race-level summer gear only (TA/Drift spec §2)
```

Also update both doc comments ("accepted summer gear from the season before") to read "accepted race-level summer gear from the season before".

- [ ] **Step 5: Run the test to confirm it passes, then run the full suite**

Run: `php phpunit.phar --filter TaDriftCarsAndCarryOverTest` → PASS. Then `php phpunit.phar` → all PASS. `GearLibTest`, `ReadinessTest`, `DriversLibTest` and `HomePageTest` still see "from summer 2026", because their summer rows have no `level` (race).

- [ ] **Step 6: Commit**

```bash
git add garage-lib.php cars-lib.php gear-lib.php readiness-lib.php tests/CarDetailsTest.php tests/TaDriftCarsAndCarryOverTest.php
git commit -m "feat(ta-drift): TA/Drift-only cars; only race-level summer gear carries over to ice

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

## Known gaps left for plans 2 and 3 (not bugs in this phase)

- **Garage and the car form:**
  - `garageAfterAdd()` has no `ta_drift` branch, so a TA/Drift-only car still gets the summer "declare its class" next step.
  - The Garage car form (`garage-page.php:53-61`) doesn't offer the option yet.
  - Both are plan 3.
- **The race sheet's auto-tag:** `tech-sheets.php:677` and `:896` call `db_tag_event` directly. That's fine: new rows default to `race`, and existing rows keep their formats. Plan 2's TA/Drift submit route should call `eventsTagCar($pdo, $uid, $eventId, $carId, $formatsOrNull)` instead.
- **Revoke notes:** `techReviewRevoke`, `gearRevoke` and the Inspect forms don't pass or require a note yet. That's plan 2.

## Produces for plans 2 and 3

**Constants**
- `tech-status.php`:
  - `TECH_TIER_RACE = 'race'`
  - `TECH_TIER_TA_DRIFT = 'ta_drift'`
  - `SHEET_TYPE_TA_DRIFT = 'ta_drift'`
  - `GEAR_LEVEL_TA_DRIFT = 'ta_drift'`
- `ta-drift-rules.php`: `TA_DRIFT_RULES_VERSION`, `TA_DRIFT_CHECKLIST_SECTIONS`, `TA_DRIFT_CAGED_SECTION`, `TA_DRIFT_EQUIPMENT_ITEMS`
- `ta-drift-lib.php`:
  - `ENTRY_FORMATS` (`['race' => 'Race', 'ta' => 'Time Attack', 'drift' => 'Drift']`)
  - `ENTRY_FORMAT_ERROR`
  - `ENTRY_NO_HOST_CLUB`
- `photo-requirements.php`: `TA_DRIFT_PHOTO_REQUIREMENTS_VERSION` and `TA_DRIFT_PHOTO_REQUIREMENTS`
  - Car keys: `tad_front_34`, `tad_rear_34`, `tad_interior`, `tad_battery`, `tad_tow_front`, `tad_tow_rear`, `tad_cage` (caged only).
  - Gear keys: `tad_helmet_label`, `tad_fhr_label` (caged only).
- `garage-lib.php`: `CAR_DISCIPLINES = ['ice', 'summer', 'both', 'ta_drift']`

**Columns**
- `event_plans.formats TEXT NOT NULL DEFAULT 'race'`
- `event_plans.supps_ack_at DATETIME`
- `tech_sheets.caged INTEGER NOT NULL DEFAULT 0`
- `tech_sheets.revoke_note TEXT`
- `gear_records.revoke_note TEXT`
- `tech_sheets.sheet_type` gains the value `'ta_drift'`, stored with `discipline` = `'summer'` and `club` = the event's `host_club`.
- `gear_records.level` on summer rows is `NULL` (race) or `'ta_drift'`.
- `cars.disciplines` gains the value `'ta_drift'`.

**Pure functions**
- `taDriftChecklistSections(bool $caged, string $club): array`
- `taDriftEquipmentItems(bool $caged): array`
- `taDriftHelmetNote(bool $caged): string`
- `emptyDriverEquipment(array $items = TECH_DRIVER_EQUIPMENT_ITEMS): array`
- `entryFormatsParse(?string $stored): string[]`
- `entryFormatsStore(array $formats): string`
- `entryFormatsLabel(array $formats): string`
- `entryTechTier(array $formats): string`
- `entryFormatsValidate(array $event, mixed $picked): array{ok: bool, formats: string[], error: ?string}`
- `techSheetIsTaDrift(array $sheet): bool`
- `taDriftCarTechStatus(array $race, array $taDrift): array{state: string, via: ?string, sheet_id: ?int, tier: string}`
- `gearCoversTier(?array $gear, string $tier): bool`
- `techCarKey(array $sheet): string`: `car|ta_drift|CLUB|season` for `ta_drift` sheets.
- `atTrackKey(string $type, int $id, int $season, string $discipline = 'summer', string $club = ''): string`: `"$type:$id@tad:$club:$season"` when `$discipline === TECH_TIER_TA_DRIFT`.
- `photoSubjectIsTaDrift(array $subject, string $scope): bool`
- `photoRequirementsFor(array $subject, string $scope): array`:
  - A car subject with `sheet_type` = `'ta_drift'`, or a gear subject with `photo_tier` = `'ta_drift'`, gets the `tad_` list.
  - The caged-only shots need a truthy `caged`.

**Database functions (`db.php`)**
- `db_tag_event(PDO $pdo, int $userId, int $eventId, int $carId): bool`
- `db_get_user_event_plans(PDO $pdo, int $userId): array`. Rows carry `event_id`, `car_id`, `formats` and `supps_ack_at`.
- `db_get_entry(PDO $pdo, int $userId, int $eventId, int $carId): ?array`
- `db_set_entry_formats(PDO $pdo, int $userId, int $eventId, int $carId, string $formats, ?string $suppsAckAt): void`
- `db_get_car_last_summer_formats(PDO $pdo, int $carId, int $exceptEventId): ?string`
- `db_ta_drift_club(PDO $pdo, int $eventId): string`. Throws if the event has no host club.
- `db_insert_tech_sheet(PDO $pdo, array $data): int`:
  - `sheet_type` = `'ta_drift'` needs `car_id`, no `submission_id`, and a summer event with a host club.
  - `caged` is optional (a bool).
- `db_update_tech_sheet(PDO $pdo, int $id, array $data): void`:
  - refuses to switch between TA/Drift and race
  - a TA/Drift sheet takes the new event's host club
  - stores `caged`
- `db_revoke_tech_sheet_acceptance(PDO $pdo, int $id, ?string $note = null): bool`
- `db_revoke_gear_acceptance(PDO $pdo, int $id, ?string $note = null): bool`
- Accepting a sheet or gear record clears `revoke_note`.
- `db_set_gear_level(PDO $pdo, int $id, ?string $level): void`. Also accepts `'ta_drift'`.
- `db_get_at_track_keys(...)`: summer rows with a club come back as `car:ID@tad:CLUB:season`.

**Events (`events-lib.php`)**
- `eventsTagCar(PDO $pdo, int $userId, int $eventId, int $carId, ?array $formats = null, bool $suppsAck = false): array{ok: bool, error: ?string}`
- `eventsSetFormats(PDO $pdo, int $userId, int $eventId, int $carId, mixed $formats, bool $suppsAck): array{ok: bool, error: ?string}`
- `eventsStoreFormats(PDO $pdo, int $userId, int $eventId, int $carId, array $formats, bool $suppsAck): void`
- `eventsDefaultFormats(PDO $pdo, array $car, array $event): string[]`
- `eventsSetAtTrack(..., string $discipline = 'summer', string $club = '')`: also takes `TECH_TIER_TA_DRIFT` with a club code, for cars only.

**Test helpers (`tests/bootstrap.php`)**
- `test_make_ta_drift_sheet(PDO $pdo, int $userId, int $carId, int $eventId, bool $caged = false): int`
- `test_ta_drift_sheet_data(int $userId, int $carId, int $eventId, bool $caged = false): array`

**Behaviour**
- Summer gear carries over to the next ice season only at race level (`gearIceSummary` and `readinessIceGear`).
