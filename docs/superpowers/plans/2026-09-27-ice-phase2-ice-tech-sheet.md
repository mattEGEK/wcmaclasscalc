# Ice Racing Phase 2: Ice Tech Sheet Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Competitors can submit an **ice tech sheet** for an ice event. It has:
- a class picked from the host club's list;
- a checklist that follows the class group;
- class-aware driver gear.

Inspectors can review and accept it in person. When they accept a driver's ice gear, they record its **level** (street-safe or caged).

**Architecture:** The summer tech sheet page (`tech-sheets.php`) gains ice routes. Ice logic lives in two new focused files:
- `ice-sheet-lib.php`: pure helpers for parsing, validation and choosing which checklist applies.
- `ice-sheet-page.php`: the form's view model and HTML.

Rendering, email and print use the same renderer as summer, choosing the checklist per sheet. The form reuses `js/tech-sheet-form.js`: a small pure helper (`js/ice-class-picker.js`) re-renders the checklist when the class changes. Summer behaviour does not change.

**Tech Stack:** PHP 8.3, SQLite (PDO), PHPUnit 10 (`php phpunit.phar`), vanilla JS with `node --test` for pure JS helpers.

**Spec:** `docs/superpowers/specs/2026-09-27-ice-racing-design.md` §3 (the ice tech sheet flow), with §1 for the rules data. Phase 1 (merged, commit e8428fc) gave you:
- `ice-rules.php`
- `seasonForEvent()`
- `discipline`/`club` on `tech_sheets`
- `discipline`/`level` on `gear_records`
- `db_get_active_events($pdo, ?string $discipline)`
- `db_get_sheet_identity_sheets()`
- `db_set_gear_level()`
- test helper `test_make_ice_sheet()`

### Roadmap

| Phase | Delivers |
|---|---|
| 1 — Foundations | done |
| **2 — Ice tech sheet (this plan)** | Ice form, validation, render/email, the Garage entry point, gear level on in-person acceptance, the inspector roster for ice events |
| 3 — Ice photo pre-tech | `ICE_PHOTO_REQUIREMENTS`; the helmet photo sets the gear level; turns on pre-tech for ice sheets |
| 4 — Readiness and both seasons | Ice readiness/Home/reminders, event tagging for ice, carrying summer gear over to ice, media |

## Global Constraints

- All paths are relative to `wcma-calculator/` unless they start with `docs/`.
- Run PHPUnit from `wcma-calculator/`: `php phpunit.phar`. Run JS tests: `node --test tests/js/`.
- The full suite must pass at the end of every task. Baseline: 749 PHP tests.
- No new dependencies and no build step.
- **Terminology:** use *reviewed*, *accepted* and *pre-teched*. Never use *approved*, *passed* or *safe* in UI copy. The *Street Safe* class name is exempt.
- **Summer is unchanged:**
  - The summer form, validation, labels and emails behave exactly as today.
  - Summer car tech status never counts an ice sheet.
  - Summer gear never counts an ice gear record.
- **Discipline, club and group values:**
  - Disciplines: `summer` | `ice` (`DISCIPLINE_SUMMER` / `DISCIPLINE_ICE`).
  - Clubs: `NASCC` | `WSCC`.
  - Class groups: `drift` | `street_safe` | `caged`.
  - Gear levels: `street_safe` | `caged`.
- **Ice sheets:**
  - An ice tech sheet has `sheet_type = 'ice'`, `discipline = 'ice'`, `club` = the event's host club, `class` = a class code from that club, and **no** `submission_id`.
  - It is single-driver.
  - It belongs to exactly one ice event, and editing never moves it to another event.
- **Photo pre-tech is off for ice sheets in this phase:** hide the "Get pre-teched" action, and the pretech routes refuse ice sheets. Phase 3 turns it on.
- **Event tagging and readiness stay summer-only in this phase** (Phase 1's filter). The only way into an ice sheet is the Garage car page's new "Ice racing" section.
- Work on branch `ice-phase2`, not `main`. Commit at the end of every task. Every commit message ends with:
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5
  ```

## Review Focus

1. **The class is changed after ticking checklist items, or a hand-crafted POST sends a checklist for a different group.**
   - The server must validate against the **posted class's** group.
   - A caged checklist posted with an SS class is rejected.
   - Test: Task 2, `testChecklistIsCheckedAgainstThePostedClassGroup`.
2. **A class code from the other club is posted** (e.g. `FOI-STD` on a NASCC event). It is rejected with the club named in the message. Test: Task 2, `testClassFromAnotherClubIsRejected`.
3. **An accepted ice sheet in 2027 for a car that also has summer 2027 events.** Summer car tech on the Garage and in readiness must not show it as teched. Test: Task 6, `testSummerSheetsExcludeIce` and `testLoaderKeepsOnlySummerSheets`.
4. **An inspector accepts ice gear in person without choosing a level, or revokes it.**
   - Accepting with no level is refused.
   - Revoking clears the level.
   - Test: Task 7, `testIceGearAcceptNeedsALevelAndRevokeClearsIt`.
5. **An ice sheet is edited.**
   - The event and club can't change: the update always uses the sheet's own event.
   - The email goes to the account holder's address, because there is no declaration to take it from.
   - Test: Task 5, `testIceUpdateKeepsTheSheetsEventAndEmailsTheAccountHolder` (source test) plus `testRecipientFallsBackToTheAccountEmail` (Task 2).

---

### Task 1: Rules additions and checklist/equipment validators that take their item lists

**Files:**
- Modify: `ice-rules.php`
- Modify: `tech-sheet-data.php` (`emptyChecklist`, `validateChecklist`, `validateDriverEquipment`)
- Test: `tests/IceRulesTest.php` (add), `tests/TechSheetDataTest.php` (add)

**Interfaces:**
- Consumes: Phase 1 `ICE_CHECKLIST_SECTIONS`, `iceClass()`, `iceChecklistSections()`.
- Produces:
  - `ICE_RULES_VERSION = 2`
  - drift item `windshield`; drift `battery` wording mentions terminals; caged item `ballast`
  - `iceHelmetNote(string $club, ?array $class): string`
  - `iceEquipmentItems(?array $class): array` — the same shape as `TECH_DRIVER_EQUIPMENT_ITEMS`
  - `iceGearLevelForClass(string $club, string $code): ?string` — `'caged'` for caged classes, `'street_safe'` for drift/street_safe, null if unknown
  - `ICE_GEAR_LEVEL_LABELS = ['street_safe' => 'street-safe', 'caged' => 'caged']`
  - `emptyChecklist(array $sections = TECH_CHECKLIST_SECTIONS): array`
  - `validateChecklist(array $checklist, array $sections = TECH_CHECKLIST_SECTIONS): bool`
  - `validateDriverEquipment(array $equipment, array $items = TECH_DRIVER_EQUIPMENT_ITEMS): bool`

- [ ] **Step 1: Write the failing tests**

Add to `tests/IceRulesTest.php` (inside the class):

```php
    public function testRulesVersionIsTwo(): void
    {
        $this->assertSame(2, ICE_RULES_VERSION);
    }

    public function testChecklistGapsFromPhaseOneAreFilled(): void
    {
        $flat = fn(array $sections): array => array_merge(...array_map(fn($s) => $s['items'], array_values($sections)));
        $drift = $flat(iceChecklistSections('WSCC', 'drift'));
        $this->assertArrayHasKey('windshield', $drift);
        $this->assertStringContainsString('terminal', $drift['battery']);
        $this->assertArrayHasKey('ballast', $flat(iceChecklistSections('NASCC', 'caged')));
        $this->assertArrayHasKey('ballast', $flat(iceChecklistSections('WSCC', 'caged')));
    }

    public function testHelmetNoteFollowsGroupClubAndFhr(): void
    {
        $this->assertSame('Helmet: Snell SA2020 or newer. A frontal head restraint is required for this class.',
            iceHelmetNote('NASCC', iceClass('NASCC', 'LS')));
        $this->assertSame('Helmet: Snell SA2020 or newer.', iceHelmetNote('NASCC', iceClass('NASCC', 'NS')));
        $this->assertSame('Helmet: Snell SA2015 or newer, or ECE 22.05 made 2015 or later.', iceHelmetNote('WSCC', iceClass('WSCC', 'FOI-STD')));
        $this->assertSame('Helmet: Snell M2015 or newer, or ECE 22.05/22.06.', iceHelmetNote('NASCC', iceClass('NASCC', 'SS')));
        $this->assertSame('Helmet: Snell M2015 or newer, or ECE 22.05/22.06.', iceHelmetNote('WSCC', iceClass('WSCC', 'DRIFT')));
        $this->assertSame('', iceHelmetNote('NASCC', null));
    }

    public function testEquipmentItemsRelaxRecommendedItemsAndFollowFhr(): void
    {
        $ss = iceEquipmentItems(iceClass('NASCC', 'SS'));
        $this->assertSame(array_keys(TECH_DRIVER_EQUIPMENT_ITEMS), array_keys($ss));
        foreach (['goggles_visor', 'socks', 'balaclava', 'underwear', 'head_neck_restraints'] as $k) {
            $this->assertTrue($ss[$k]['optional'], $k);
        }
        foreach (['helmet', 'suit', 'shoes', 'gloves'] as $k) {
            $this->assertFalse($ss[$k]['optional'], $k);
        }
        $this->assertSame('Suit or FR coveralls', $ss['suit']['label']);
        $this->assertFalse(iceEquipmentItems(iceClass('NASCC', 'LS'))['head_neck_restraints']['optional']);
        $this->assertTrue(iceEquipmentItems(null)['head_neck_restraints']['optional']);
    }

    public function testGearLevelForClass(): void
    {
        $this->assertSame('caged', iceGearLevelForClass('NASCC', 'LS'));
        $this->assertSame('street_safe', iceGearLevelForClass('NASCC', 'SS'));
        $this->assertSame('street_safe', iceGearLevelForClass('WSCC', 'DRIFT'));
        $this->assertNull(iceGearLevelForClass('WSCC', 'LS'));
        $this->assertSame('street-safe', ICE_GEAR_LEVEL_LABELS['street_safe']);
    }
```

Add to `tests/TechSheetDataTest.php` (inside the class):

```php
    public function testChecklistValidatorsTakeASectionList(): void
    {
        $sections = ['s' => ['label' => 'S', 'items' => ['a' => 'A', 'b' => 'B']]];
        $this->assertSame(['a' => null, 'b' => null], emptyChecklist($sections));
        $this->assertTrue(validateChecklist(['a' => ['status' => 'ok'], 'b' => ['status' => 'na']], $sections));
        $this->assertFalse(validateChecklist(['a' => ['status' => 'ok']], $sections));
        // Default is still the summer list.
        $this->assertFalse(validateChecklist(['a' => ['status' => 'ok'], 'b' => ['status' => 'ok']]));
    }

    public function testEquipmentValidatorTakesAnItemList(): void
    {
        $items = ['helmet' => ['label' => 'Helmet', 'has_rating' => true, 'optional' => false],
                  'socks' => ['label' => 'Socks', 'has_rating' => false, 'optional' => true]];
        $this->assertTrue(validateDriverEquipment(['helmet' => ['competitor_confirmed' => true, 'value' => 'SA2020'],
                                                   'socks' => ['competitor_confirmed' => false, 'value' => null]], $items));
        $this->assertFalse(validateDriverEquipment(['helmet' => ['competitor_confirmed' => true, 'value' => ''],
                                                    'socks' => ['competitor_confirmed' => false, 'value' => null]], $items));
    }
```

If `tests/TechSheetDataTest.php` doesn't already require `tech-sheet-data.php`, add `require_once __DIR__ . '/../tech-sheet-data.php';` at the top.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter "IceRulesTest|TechSheetDataTest"`
Expected: FAIL. `ICE_RULES_VERSION` is still 1, and `iceHelmetNote` / `iceEquipmentItems` / `iceGearLevelForClass` are undefined.

- [ ] **Step 3: Update `ice-rules.php`**

1. Change `const ICE_RULES_VERSION = 1;` to `const ICE_RULES_VERSION = 2;`.
2. Add `require_once __DIR__ . '/tech-sheet-data.php';` directly under the header comment. `TECH_DRIVER_EQUIPMENT_ITEMS` lives there.
3. In `ICE_CHECKLIST_SECTIONS['drift']`:
   - add `'windshield' => 'Windshield: clear view; wiper works',` to `vehicle_exterior` after `'tow_hooks'`;
   - change the `mechanical` section's `'battery'` label to `'Battery securely mounted, positive terminal insulated'`.
4. In `ICE_CHECKLIST_SECTIONS['caged']['vehicle_interior']['items']`, add `'ballast' => 'No ballast, or only ballast the class allows, bolted in',` after `'abs_disabled'`.
5. Append:

```php
const ICE_GEAR_LEVEL_LABELS = ['street_safe' => 'street-safe', 'caged' => 'caged'];

/**
 * The helmet standard a class needs, for the form's equipment card. Uses the stricter NASCC
 * street-safe floor for both clubs so one ice gear record is valid at either (spec §1).
 */
function iceHelmetNote(string $club, ?array $class): string {
    if ($class === null) return '';
    if ($class['group'] === 'caged') {
        $note = $club === 'WSCC'
            ? 'Helmet: Snell SA2015 or newer, or ECE 22.05 made 2015 or later.'
            : 'Helmet: Snell SA2020 or newer.';
    } else {
        $note = 'Helmet: Snell M2015 or newer, or ECE 22.05/22.06.';
    }
    return $class['fhr'] ? $note . ' A frontal head restraint is required for this class.' : $note;
}

/**
 * Driver equipment for an ice class. Same items as summer; goggles/visor, socks and balaclava are
 * recommended (not required) on ice, the suit may be FR coveralls, and a head & neck restraint is
 * required only for classes with an FHR rule.
 */
function iceEquipmentItems(?array $class): array {
    $items = TECH_DRIVER_EQUIPMENT_ITEMS;
    foreach (['goggles_visor', 'socks', 'balaclava'] as $key) {
        $items[$key]['optional'] = true;
    }
    $items['suit']['label'] = 'Suit or FR coveralls';
    $items['head_neck_restraints']['optional'] = !($class['fhr'] ?? false);
    return $items;
}

/** The gear level an ice class needs: 'caged' for caged classes, 'street_safe' otherwise. Null if unknown. */
function iceGearLevelForClass(string $club, string $code): ?string {
    $class = iceClass($club, $code);
    if ($class === null) return null;
    return $class['group'] === 'caged' ? 'caged' : 'street_safe';
}
```

- [ ] **Step 4: Update `tech-sheet-data.php`**

Replace the three functions' signatures and loops so they use their parameter:

```php
function emptyChecklist(array $sections = TECH_CHECKLIST_SECTIONS): array {
    $out = [];
    foreach ($sections as $section) {
        foreach ($section['items'] as $key => $label) {
            $out[$key] = null;
        }
    }
    return $out;
}
```

```php
function validateChecklist(array $checklist, array $sections = TECH_CHECKLIST_SECTIONS): bool {
    foreach ($sections as $section) {
        foreach ($section['items'] as $key => $label) {
            if (!isset($checklist[$key]) || !is_array($checklist[$key])) return false;
            $status = $checklist[$key]['status'] ?? null;
            if (!in_array($status, ['ok', 'na'], true)) return false;
        }
    }
    return true;
}
```

```php
function validateDriverEquipment(array $equipment, array $items = TECH_DRIVER_EQUIPMENT_ITEMS): bool {
    foreach ($items as $key => $def) {
        if (!isset($equipment[$key]) || !is_array($equipment[$key])) return false;
        $confirmed = $equipment[$key]['competitor_confirmed'] ?? false;
        if (!$def['optional'] && $confirmed !== true) return false;
        if ($def['has_rating'] && trim((string)($equipment[$key]['value'] ?? '')) === '') return false;
    }
    return true;
}
```

Keep each function's existing docblock. Update the docblocks that say "every checklist item key from TECH_CHECKLIST_SECTIONS" to "every item in `$sections` (summer by default)".

- [ ] **Step 5: Make every include of these files `require_once`**

`ice-rules.php` now pulls in `tech-sheet-data.php`, and later tasks load `ice-rules.php` from `gear-lib.php`. A plain `require` of either file after a `require_once` of it would redeclare its functions and crash the page.

1. Run: `grep -rn "require __DIR__ . '/\(tech-sheet-data\|ice-rules\|ice-sheet-lib\|ice-sheet-page\).php'" --include=*.php .`
2. Change every hit to `require_once`. Today these are at least `tech-sheets.php` (`tech-sheet-data.php`) and `admin.php` (`ice-rules.php`).
3. Run `php -l` on each file you changed.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php phpunit.phar --filter "IceRulesTest|TechSheetDataTest"` → PASS.
Run: `php phpunit.phar` → the full suite PASSES.

- [ ] **Step 7: Commit**

```bash
git add -A ice-rules.php tech-sheet-data.php tech-sheets.php admin.php tests/IceRulesTest.php tests/TechSheetDataTest.php
git commit -m "feat(ice): helmet notes, ice equipment items, gear level per class; validators take item lists"
```

---

### Task 2: Pure ice-sheet helpers (`ice-sheet-lib.php`)

**Files:**
- Create: `ice-sheet-lib.php`
- Test: `tests/IceSheetLibTest.php`

**Interfaces:**
- Consumes: Task 1's functions; Phase 1's `iceClass`, `iceClubLabel`, `iceChecklistSections`.
- Produces:
  - `techSheetChecklistSections(array $sheet): array` — summer sheets get `TECH_CHECKLIST_SECTIONS`; ice sheets get the sections for their club and class group (`[]` if the class is unknown)
  - `techSheetEquipmentItems(array $sheet): array` — summer gets `TECH_DRIVER_EQUIPMENT_ITEMS`; ice gets `iceEquipmentItems(class)`
  - `techSheetClassLine(array $sheet): string` — summer returns `class`; ice returns `"LS — Limited Stud (NASCC)"`
  - `iceSheetParsePost(array $post): array` — keys: `class, car_weight, checklist, equipment, entrant_name, driver_name, car_number, car_colour, engine_cc, engine_hp, log_book`
  - `iceSheetValidate(array $parsed, string $club): ?string` — null when valid, otherwise the first error message
  - `techSheetRecipientEmail(PDO $pdo, array $sheet): ?string` — the declaration's email for summer sheets that have one; otherwise the account holder's email

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/IceSheetLibTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../ice-sheet-lib.php';

final class IceSheetLibTest extends TestCase
{
    /** A checklist with every item in $sections marked OK. */
    private function allOk(array $sections): array {
        $out = [];
        foreach ($sections as $s) foreach ($s['items'] as $k => $label) $out[$k] = ['status' => 'ok'];
        return $out;
    }

    private function allEquipment(array $items): array {
        $out = [];
        foreach ($items as $k => $def) $out[$k] = ['competitor_confirmed' => true, 'value' => $def['has_rating'] ? 'SA2020' : null];
        return $out;
    }

    private function parsed(string $club, string $class, array $o = []): array {
        $def = iceClass($club, $class);
        return array_merge([
            'class' => $class, 'car_weight' => '2300',
            'checklist' => $def ? $this->allOk(iceChecklistSections($club, $def['group'])) : [],
            'equipment' => $this->allEquipment(iceEquipmentItems($def)),
            'entrant_name' => 'Sam', 'driver_name' => 'Sam', 'car_number' => '7', 'car_colour' => 'Blue',
            'engine_cc' => null, 'engine_hp' => null, 'log_book' => '1',
        ], $o);
    }

    public function testSheetSectionsAndItemsFollowDiscipline(): void
    {
        $this->assertSame(TECH_CHECKLIST_SECTIONS, techSheetChecklistSections(['discipline' => 'summer', 'class' => 'IT1']));
        $this->assertSame(TECH_CHECKLIST_SECTIONS, techSheetChecklistSections(['class' => 'IT1']));
        $this->assertSame(iceChecklistSections('WSCC', 'street_safe'),
            techSheetChecklistSections(['discipline' => 'ice', 'club' => 'WSCC', 'class' => 'FOI-SS']));
        $this->assertSame([], techSheetChecklistSections(['discipline' => 'ice', 'club' => 'WSCC', 'class' => 'LS']));
        $this->assertSame(TECH_DRIVER_EQUIPMENT_ITEMS, techSheetEquipmentItems(['discipline' => 'summer']));
        $this->assertFalse(techSheetEquipmentItems(['discipline' => 'ice', 'club' => 'NASCC', 'class' => 'LS'])['head_neck_restraints']['optional']);
    }

    public function testClassLine(): void
    {
        $this->assertSame('IT1', techSheetClassLine(['class' => 'IT1']));
        $this->assertSame('LS — Limited Stud (NASCC)', techSheetClassLine(['discipline' => 'ice', 'club' => 'NASCC', 'class' => 'LS']));
        $this->assertSame('XX (NASCC)', techSheetClassLine(['discipline' => 'ice', 'club' => 'NASCC', 'class' => 'XX']));
    }

    public function testParsePostTrimsAndDecodes(): void
    {
        $p = iceSheetParsePost(['class' => ' LS ', 'car_weight' => ' 2300 ', 'checklist_json' => '{"a":{"status":"ok"}}',
            'driver1_equipment_json' => '{}', 'entrant_name' => ' Sam ', 'driver_name' => 'Sam', 'car_number' => '7',
            'car_colour' => 'Blue', 'engine_cc' => '', 'engine_hp' => '110', 'log_book_turned_in' => '1']);
        $this->assertSame('LS', $p['class']);
        $this->assertSame('2300', $p['car_weight']);
        $this->assertSame(['a' => ['status' => 'ok']], $p['checklist']);
        $this->assertSame('Sam', $p['entrant_name']);
        $this->assertNull($p['engine_cc']);
        $this->assertSame('110', $p['engine_hp']);
        $this->assertSame([], iceSheetParsePost([])['checklist']);
        $this->assertSame('', iceSheetParsePost(['class' => ['LS']])['class']);
    }

    public function testValidSheetPasses(): void
    {
        $this->assertNull(iceSheetValidate($this->parsed('NASCC', 'LS'), 'NASCC'));
        $this->assertNull(iceSheetValidate($this->parsed('WSCC', 'DRIFT'), 'WSCC'));
    }

    public function testClassFromAnotherClubIsRejected(): void
    {
        $this->assertSame('Choose a class from the NASCC list.', iceSheetValidate($this->parsed('WSCC', 'FOI-STD'), 'NASCC'));
        $this->assertSame('Choose a class from the NASCC list.', iceSheetValidate($this->parsed('NASCC', 'LS', ['class' => '']), 'NASCC'));
    }

    public function testChecklistIsCheckedAgainstThePostedClassGroup(): void
    {
        $cagedChecklist = $this->allOk(iceChecklistSections('NASCC', 'caged'));
        $p = $this->parsed('NASCC', 'SS', ['checklist' => $cagedChecklist]);
        $this->assertSame('Please mark every checklist item OK or N/A.', iceSheetValidate($p, 'NASCC'));
    }

    public function testFhrClassNeedsHeadAndNeckConfirmed(): void
    {
        $p = $this->parsed('NASCC', 'LS');
        $p['equipment']['head_neck_restraints']['competitor_confirmed'] = false;
        $this->assertSame("Please confirm Driver 1's safety equipment, including the helmet and suit ratings.", iceSheetValidate($p, 'NASCC'));
        $ns = $this->parsed('NASCC', 'NS');
        $ns['equipment']['head_neck_restraints']['competitor_confirmed'] = false;
        $this->assertNull(iceSheetValidate($ns, 'NASCC'));
    }

    public function testWeightMustBeAWholePositiveNumber(): void
    {
        foreach (['', '0', 'abc', '23.5', '99999'] as $w) {
            $this->assertSame("Enter the car's race weight in pounds.", iceSheetValidate($this->parsed('NASCC', 'LS', ['car_weight' => $w]), 'NASCC'), $w);
        }
    }

    public function testRequiredTextAndLogBook(): void
    {
        $this->assertSame('Please complete every required field.', iceSheetValidate($this->parsed('NASCC', 'LS', ['entrant_name' => '']), 'NASCC'));
        $this->assertSame('Please complete every required field.', iceSheetValidate($this->parsed('NASCC', 'LS', ['log_book' => null]), 'NASCC'));
    }

    public function testRecipientFallsBackToTheAccountEmail(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'acct@example.com', 'name' => 'Sam', 'password_hash' => 'x', 'google_id' => null]);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $u, '7', [':email' => 'decl@example.com']));
        $this->assertSame('decl@example.com', techSheetRecipientEmail($pdo, ['submission_id' => $sub, 'user_id' => $u]));
        $this->assertSame('acct@example.com', techSheetRecipientEmail($pdo, ['submission_id' => null, 'user_id' => $u]));
        $this->assertNull(techSheetRecipientEmail($pdo, ['submission_id' => null, 'user_id' => 999]));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php phpunit.phar --filter IceSheetLibTest`
Expected: FAIL ("Failed opening required ... ice-sheet-lib.php").

- [ ] **Step 3: Write `ice-sheet-lib.php`**

```php
<?php
// wcma-calculator/ice-sheet-lib.php
//
// Ice tech sheets: which checklist and equipment a sheet is checked against, parsing and validating
// the ice form, and who gets the sheet's emails. Pure except techSheetRecipientEmail(), which needs
// db.php loaded by the caller.
require_once __DIR__ . '/ice-rules.php';
require_once __DIR__ . '/tech-sheet-data.php';

function techSheetIsIce(array $sheet): bool {
    return ($sheet['discipline'] ?? 'summer') === 'ice';
}

/** The checklist sections a sheet is filled in and shown against. */
function techSheetChecklistSections(array $sheet): array {
    if (!techSheetIsIce($sheet)) return TECH_CHECKLIST_SECTIONS;
    $club = (string)($sheet['club'] ?? '');
    $class = iceClass($club, (string)($sheet['class'] ?? ''));
    return $class === null ? [] : iceChecklistSections($club, $class['group']);
}

/** The driver equipment items a sheet is filled in and shown against. */
function techSheetEquipmentItems(array $sheet): array {
    if (!techSheetIsIce($sheet)) return TECH_DRIVER_EQUIPMENT_ITEMS;
    return iceEquipmentItems(iceClass((string)($sheet['club'] ?? ''), (string)($sheet['class'] ?? '')));
}

/** "LS — Limited Stud (NASCC)" for an ice sheet; the stored class for summer. */
function techSheetClassLine(array $sheet): string {
    $code = (string)($sheet['class'] ?? '');
    if (!techSheetIsIce($sheet)) return $code;
    $club = (string)($sheet['club'] ?? '');
    $class = iceClass($club, $code);
    return ($class === null ? $code : $code . ' — ' . $class['label']) . ' (' . $club . ')';
}

/** Decodes and trims the ice form's POST. No validation here (see iceSheetValidate()). */
function iceSheetParsePost(array $post): array {
    $str = fn(string $k): string => is_string($post[$k] ?? null) ? trim($post[$k]) : '';
    $json = fn(string $k): array => is_string($post[$k] ?? null) ? (json_decode($post[$k], true) ?: []) : [];
    return [
        'class'        => $str('class'),
        'car_weight'   => $str('car_weight'),
        'checklist'    => $json('checklist_json'),
        'equipment'    => $json('driver1_equipment_json'),
        'entrant_name' => $str('entrant_name'),
        'driver_name'  => $str('driver_name'),
        'car_number'   => $str('car_number'),
        'car_colour'   => $str('car_colour'),
        'engine_cc'    => $str('engine_cc') ?: null,
        'engine_hp'    => $str('engine_hp') ?: null,
        'log_book'     => $post['log_book_turned_in'] ?? null,
    ];
}

/** Null when the parsed ice sheet is complete for $club, otherwise the first problem to show. */
function iceSheetValidate(array $parsed, string $club): ?string {
    $class = iceClass($club, (string)$parsed['class']);
    if ($class === null) return 'Choose a class from the ' . $club . ' list.';
    $w = (string)$parsed['car_weight'];
    if (!ctype_digit($w) || (int)$w < 1 || (int)$w > 9999) return "Enter the car's race weight in pounds.";
    if ($parsed['entrant_name'] === '' || $parsed['driver_name'] === ''
        || $parsed['car_number'] === '' || $parsed['car_colour'] === ''
        || !in_array($parsed['log_book'], ['0', '1'], true)) {
        return 'Please complete every required field.';
    }
    if (!validateChecklist($parsed['checklist'], iceChecklistSections($club, $class['group']))) {
        return 'Please mark every checklist item OK or N/A.';
    }
    if (!validateDriverEquipment($parsed['equipment'], iceEquipmentItems($class))) {
        return "Please confirm Driver 1's safety equipment, including the helmet and suit ratings.";
    }
    return null;
}

/** Where a sheet's emails go: its declaration's email when it has one, otherwise the account holder's. */
function techSheetRecipientEmail(PDO $pdo, array $sheet): ?string {
    if (!empty($sheet['submission_id'])) {
        $email = db_get_submission($pdo, (int)$sheet['submission_id'])['email'] ?? null;
        if ($email) return (string)$email;
    }
    $user = db_find_user_by_id($pdo, (int)($sheet['user_id'] ?? 0));
    return $user['email'] ?? null;
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar --filter IceSheetLibTest` → PASS (10 tests).
Run: `php phpunit.phar` → the full suite PASSES.

- [ ] **Step 5: Commit**

```bash
git add ice-sheet-lib.php tests/IceSheetLibTest.php
git commit -m "feat(ice): ice sheet parsing, validation, per-sheet checklist and recipient helpers"
```

---

### Task 3: Render ice sheets (view, print, email)

**Files:**
- Modify: `tech-sheet-render.php`
- Test: `tests/TechSheetRenderTest.php` (add)

**Interfaces:**
- Consumes: `techSheetChecklistSections()`, `techSheetEquipmentItems()`, `techSheetClassLine()`, `techSheetIsIce()` (Task 2); `iceClubLabel()`.
- Produces:
  - `techSheetEquipmentTable(array $equipment, array $items = TECH_DRIVER_EQUIPMENT_ITEMS): string`
  - `renderTechSheetHtml()` renders ice sheets with:
    - an "ICE RACE VEHICLE INSPECTION FORM" title
    - a subtitle line `{event} — {date} · {club label} · Ice {season}`
    - the class line
    - the ice checklist and ice equipment

- [ ] **Step 1: Write the failing tests**

Add to `tests/TechSheetRenderTest.php` (inside the class). The file already has a sheet fixture: look for the helper method that builds a sheet array (e.g. `sheet()` / `baseSheet()`) and use it with overrides. If there is none, build the array inline with the same keys the existing tests use.

```php
    public function testIceSheetRendersItsClubClassSeasonAndIceChecklist(): void
    {
        $sheet = array_merge($this->sheet(), [
            'discipline' => 'ice', 'club' => 'NASCC', 'class' => 'SS', 'season' => 2027, 'sheet_type' => 'ice',
            'checklist_json' => json_encode(['airbags' => ['status' => 'ok']]),
        ]);
        $html = renderTechSheetHtml($sheet, [], ['name' => 'NASCC Ice #1', 'event_date' => '2026-12-12']);
        $this->assertStringContainsString('ICE RACE VEHICLE INSPECTION FORM', $html);
        $this->assertStringContainsString('Northern Alberta Sports Car Club · Ice 2027', $html);
        $this->assertStringContainsString('SS — Street Safe (FWD/RWD) (NASCC)', $html);
        $this->assertStringContainsString('Airbags removed (disabling is not enough)', $html);
        $this->assertStringNotContainsString('Fuel Tank Compartment', $html);
        $this->assertStringContainsString('Suit or FR coveralls', $html);
    }

    public function testSummerSheetStillRendersTheSummerForm(): void
    {
        $html = renderTechSheetHtml($this->sheet(), [], ['name' => 'Fall Sprint', 'event_date' => '2026-10-11']);
        $this->assertStringContainsString('>VEHICLE INSPECTION FORM<', $html);
        $this->assertStringContainsString('Fuel Tank Compartment', $html);
        $this->assertStringNotContainsString('Ice ', $html);
    }
```

If the fixture method has a different name, rename `$this->sheet()` to match it.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter TechSheetRenderTest`
Expected: the new ice test FAILS (summer title, summer checklist).

- [ ] **Step 3: Update `tech-sheet-render.php`**

1. Replace `require_once __DIR__ . '/tech-sheet-data.php';` with:

```php
require_once __DIR__ . '/tech-sheet-data.php';
require_once __DIR__ . '/ice-sheet-lib.php';
```

2. Change `techSheetEquipmentTable(array $equipment): string` to `techSheetEquipmentTable(array $equipment, array $items = TECH_DRIVER_EQUIPMENT_ITEMS): string`, and loop `foreach ($items as $key => $def)` instead of over `TECH_DRIVER_EQUIPMENT_ITEMS`.

3. In `renderTechSheetHtml()`:

- After `$equipment = ...`, add:

```php
    $isIce = techSheetIsIce($sheet);
    $sections = techSheetChecklistSections($sheet);
    $items = techSheetEquipmentItems($sheet);
```

- Replace the title line with:

```php
    $out .= '<h1 style="text-align:center;margin-bottom:0.2rem">' . ($isIce ? 'ICE RACE VEHICLE INSPECTION FORM' : 'VEHICLE INSPECTION FORM') . '</h1>';
```

- Replace the event subtitle line with:

```php
    $subtitle = h($event['name'] ?? '') . ' — ' . h(date('F j, Y', strtotime($event['event_date'] ?? 'now')));
    if ($isIce) {
        $subtitle .= ' · ' . h((string)(iceClubLabel((string)($sheet['club'] ?? '')) ?? ($sheet['club'] ?? ''))) . ' · Ice ' . (int)($sheet['season'] ?? 0);
    }
    $out .= '<p style="text-align:center;color:#555;font-size:0.85rem">' . $subtitle . '</p>';
```

- In the header table, replace `h($sheet['class'])` with `h(techSheetClassLine($sheet))`.
- Replace `foreach (TECH_CHECKLIST_SECTIONS as $section)` with `foreach ($sections as $section)`.
- Change both `techSheetEquipmentTable($equipment)` and `techSheetEquipmentTable($driverEquipment)` calls to pass `$items` as the second argument.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar --filter TechSheetRenderTest` → PASS.
Run: `php phpunit.phar` → the full suite PASSES.

- [ ] **Step 5: Commit**

```bash
git add tech-sheet-render.php tests/TechSheetRenderTest.php
git commit -m "feat(ice): render ice sheets with their club, class, season and checklist"
```

---

### Task 4: Class-aware checklist on the form (JS)

**Files:**
- Create: `js/ice-class-picker.js`
- Modify: `js/tech-sheet-form.js`
- Test: `tests/js/ice-class-picker.test.js`

**Interfaces:**
- Consumes: the page globals that Task 5 emits:
  - `window.ICE_SECTIONS_BY_CLASS` (code → sections)
  - `window.ICE_FHR_BY_CLASS` (code → bool)
  - `window.ICE_CLASS_NOTES` (code → note)
  - `window.ICE_HELMET_NOTES` (code → note)
  - elements `#ice_class`, `#ice-class-note` and `#ice-helmet-note`
- Produces:
  - `WcmaIceClass.sectionsFor(map, code)`
  - `WcmaIceClass.carryChecklistState(oldState, sections)`
  - `WcmaIceClass.fhrRequired(map, code)`
  - `tech-sheet-form.js` works on a page with no `#sheet_type` / `#add-driver-btn` (the ice form), and re-renders the checklist when `#ice_class` changes

- [ ] **Step 1: Write the failing test**

```js
// wcma-calculator/tests/js/ice-class-picker.test.js
const test = require('node:test');
const assert = require('node:assert');
const { sectionsFor, carryChecklistState, fhrRequired } = require('../../js/ice-class-picker.js');

const map = {
    SS: { a: { label: 'A', items: { airbags: 'Airbags', tow: 'Tow hooks' } } },
    LS: { b: { label: 'B', items: { cage: 'Cage', tow: 'Tow hooks' } } },
};

test('sectionsFor returns the class sections, or an empty object', () => {
    assert.deepStrictEqual(sectionsFor(map, 'LS'), map.LS);
    assert.deepStrictEqual(sectionsFor(map, ''), {});
    assert.deepStrictEqual(sectionsFor(null, 'LS'), {});
});

test('carryChecklistState keeps answers for items still on the list and starts new ones blank', () => {
    const old = { airbags: { status: 'ok' }, tow: { status: 'na' } };
    assert.deepStrictEqual(carryChecklistState(old, map.LS), { cage: { status: null }, tow: { status: 'na' } });
    assert.deepStrictEqual(carryChecklistState(null, map.SS), { airbags: { status: null }, tow: { status: null } });
});

test('fhrRequired reads the class flag', () => {
    assert.strictEqual(fhrRequired({ LS: true, NS: false }, 'LS'), true);
    assert.strictEqual(fhrRequired({ LS: true }, 'NS'), false);
    assert.strictEqual(fhrRequired(undefined, 'LS'), false);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `node --test tests/js/ice-class-picker.test.js`
Expected: FAIL ("Cannot find module '../../js/ice-class-picker.js'").

- [ ] **Step 3: Write `js/ice-class-picker.js`**

```js
// wcma-calculator/js/ice-class-picker.js
/**
 * Ice tech sheet: the checklist follows the chosen class's group. Pure helpers, exported for
 * node --test; js/tech-sheet-form.js does the DOM work.
 */
(function () {
    function sectionsFor(sectionsByClass, code) {
        return (sectionsByClass && sectionsByClass[code]) || {};
    }

    function carryChecklistState(oldState, sections) {
        const out = {};
        Object.keys(sections).forEach(function (s) {
            Object.keys(sections[s].items).forEach(function (key) {
                const prev = oldState && oldState[key];
                out[key] = { status: prev && prev.status ? prev.status : null };
            });
        });
        return out;
    }

    function fhrRequired(fhrByClass, code) {
        return !!(fhrByClass && fhrByClass[code]);
    }

    const api = { sectionsFor, carryChecklistState, fhrRequired };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else window.WcmaIceClass = api;
})();
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `node --test tests/js/` → all JS tests PASS.

- [ ] **Step 5: Make `js/tech-sheet-form.js` work on the ice form**

Make these five edits:

1. Line 3: change `const checklistWidget = WcmaTechChecklist.render(` to `let checklistWidget = WcmaTechChecklist.render(`.

2. Directly after `const driver1State = driver1Equipment.state;`, add:

```js
    // Ice form: the checklist and the head & neck rule follow the chosen class.
    const iceClassSelect = document.getElementById('ice_class');
    if (iceClassSelect && window.ICE_SECTIONS_BY_CLASS && window.WcmaIceClass) {
        iceClassSelect.addEventListener('change', function () {
            const code = iceClassSelect.value;
            const sections = WcmaIceClass.sectionsFor(window.ICE_SECTIONS_BY_CLASS, code);
            const container = document.getElementById('checklist-container');
            const carried = WcmaIceClass.carryChecklistState(checklistWidget.getState(), sections);
            container.innerHTML = '';
            checklistWidget = WcmaTechChecklist.render(container, sections, carried);
            TECH_DRIVER_EQUIPMENT_ITEMS.head_neck_restraints.optional = !WcmaIceClass.fhrRequired(window.ICE_FHR_BY_CLASS, code);
            document.getElementById('ice-class-note').textContent = (window.ICE_CLASS_NOTES || {})[code] || '';
            document.getElementById('ice-helmet-note').textContent = (window.ICE_HELMET_NOTES || {})[code] || '';
        });
    }
```

3. Replace the `sheetTypeSelect.addEventListener('change', function () { ... });` block with the same block wrapped in `if (sheetTypeSelect) { ... }`.

4. Replace:

```js
    document.getElementById('add-driver-btn').addEventListener('click', function () {
        addDriverRow(null);
    });
```

with:

```js
    const addDriverBtn = document.getElementById('add-driver-btn');
    if (addDriverBtn) {
        addDriverBtn.addEventListener('click', function () {
            addDriverRow(null);
        });
    }
```

5. In the submit handler, change `if (sheetTypeSelect.value === 'endurance') {` to `if (sheetTypeSelect && sheetTypeSelect.value === 'endurance') {`.

`TECH_DRIVER_EQUIPMENT_ITEMS` is a `const` binding to an object, so changing a property on it is allowed. The equipment rows read `optional` when validating, so the next submit uses the new rule.

- [ ] **Step 6: Verify summer is unaffected**

Run: `node --test tests/js/` and `php phpunit.phar` → both PASS.

Read the diff of `js/tech-sheet-form.js` once more to confirm three things:
- Every other use of `sheetTypeSelect` still works with the element present.
- The only other `document.getElementById(...)` calls are for elements the ice form also has. Task 5 keeps the same ids: `checklist-container`, `equipment-container`, `driver1_choice`, `driver1_new_name`, `entrant-sig-canvas`, `driver-sig-canvas`, `tech-sheet-form`, `tech-sheet-error`, `checklist_json`, `driver1_equipment_json`, `drivers_json`, `entrant_signature`, `driver_signature`.
- `enduranceCard` and `additionalDriversContainer` are only used inside the endurance code paths, which are now guarded.

- [ ] **Step 7: Commit**

```bash
git add js/ice-class-picker.js js/tech-sheet-form.js tests/js/ice-class-picker.test.js
git commit -m "feat(ice): tech sheet form re-renders the checklist for the chosen ice class"
```

---

### Task 5: The ice tech sheet form and its handlers

**Files:**
- Create: `ice-sheet-page.php`
- Modify: `tech-sheet-data.php` (add `techSheetDriver1FormState()`)
- Modify: `tech-sheets.php`:
  - requires
  - router cases `new-ice` and `submit-ice`
  - `renderTechSheetForm()` uses the new helper
  - `handleEdit`, `handleUpdate`, `handleView`, `handlePretech`, `handlePretechSubmit`, `handleResendTechSheet`
  - new `handleNewIce`, `handleSubmitIce`, `handleUpdateIce`
- Test: `tests/IceSheetPageTest.php` (create), `tests/TechSheetsHandlersTest.php` (add), `tests/TechSheetDriverChoiceTest.php` (add)

**Interfaces:**
- Consumes:
  - Task 2's `iceSheetParsePost`, `iceSheetValidate`, `techSheetRecipientEmail`, `techSheetIsIce`
  - Task 1's `iceHelmetNote`, `iceEquipmentItems`
  - Phase 1's `iceClassOptions`, `iceClass`, `iceClubLabel`, `iceChecklistSections`, `db_get_active_events($pdo, DISCIPLINE_ICE)`
  - `carsSheetSnapshot()`, `techSheetApplyDriverChoices()`, `sendTechSheetConfirmationEmail()`
- Produces:
  - `techSheetDriver1FormState(array $ownerDrivers, ?array $sheet): array{ownedById: array, selfId: ?int, choice: string, newName: string, driversForJs: array}`
  - `iceSheetFormVm(array $car, array $event, array $iceEvents, array $ownerDrivers, ?array $sheet, string $csrf): array`
  - `renderIceTechSheetFormHtml(array $vm): string`
  - routes `tech-sheets.php?action=new-ice&car_id=&event_id=` (GET) and `action=submit-ice` (POST)
  - editing an ice sheet via the existing `action=edit` / `action=update` routes

- [ ] **Step 1: Write the failing tests**

Add to `tests/TechSheetDriverChoiceTest.php` (inside the class; add `require_once __DIR__ . '/../tech-sheet-data.php';` at the top if missing). The test assumes creating a user also creates their own ("self") driver profile. If `db_get_self_driver()` returns null right after `db_create_user()`, create the self driver the way `tests/DriversLibTest.php` does, and keep the rest of the test unchanged:

```php
    public function testDriver1FormStatePicksYouForANewSheetAndTheSheetsDriverForAnEdit(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'd@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $self = (int)db_get_self_driver($pdo, $u)['id'];
        $sam = db_create_driver($pdo, $u, 'Sam Patel');
        $drivers = db_get_user_drivers($pdo, $u);

        $new = techSheetDriver1FormState($drivers, null);
        $this->assertSame($self, $new['selfId']);
        $this->assertSame((string)$self, $new['choice']);
        $this->assertSame('', $new['newName']);
        $this->assertCount(count($drivers), $new['driversForJs']);

        $this->assertSame((string)$sam, techSheetDriver1FormState($drivers, ['driver_name' => 'Sam Patel'])['choice']);
        $gone = techSheetDriver1FormState($drivers, ['driver_name' => 'Alex Rivera']);
        $this->assertSame('new', $gone['choice']);
        $this->assertSame('Alex Rivera', $gone['newName']);
    }
```

Create `tests/IceSheetPageTest.php`:

```php
<?php
// wcma-calculator/tests/IceSheetPageTest.php
use PHPUnit\Framework\TestCase;

// Same page-level requires as tests/GaragePageTest.php, because garage-page.php needs them.
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
require_once __DIR__ . '/../ice-sheet-page.php';

final class IceSheetPageTest extends TestCase
{
    private function vm(?array $sheet = null): array {
        $car = ['id' => 3, 'car_number' => '7', 'year' => '1985', 'make' => 'Chevrolet', 'model' => 'Chevette',
                'colour' => '', 'engine_cc' => '1600', 'archived_at' => null];
        $event = ['id' => 20, 'name' => 'NASCC Ice #1', 'event_date' => '2026-12-12', 'discipline' => 'ice', 'host_club' => 'NASCC'];
        $other = ['id' => 21, 'name' => 'WSCC Fire on Ice', 'event_date' => '2027-01-04', 'discipline' => 'ice', 'host_club' => 'WSCC'];
        $drivers = [['id' => 5, 'name' => 'Jordan Lee', 'name_norm' => 'jordan lee', 'user_id' => 1, 'owner_user_id' => 1]];
        return iceSheetFormVm($car, $event, [$event, $other], $drivers, $sheet, 'tok');
    }

    public function testVmHoldsTheClubsClassesAndPerClassData(): void
    {
        $vm = $this->vm();
        $this->assertSame('NASCC', $vm['club']);
        $this->assertSame(iceClassOptions('NASCC'), $vm['classOptions']);
        $this->assertSame('', $vm['selectedClass']);
        $this->assertSame(iceChecklistSections('NASCC', 'caged'), $vm['sectionsByClass']['LS']);
        $this->assertTrue($vm['fhrByClass']['LS']);
        $this->assertFalse($vm['fhrByClass']['SS']);
        $this->assertSame(iceHelmetNote('NASCC', iceClass('NASCC', 'CH')), $vm['helmetNotes']['CH']);
        $this->assertSame([21], array_map(fn($e) => (int)$e['id'], $vm['otherEvents']));
        $this->assertSame('tech-sheets.php?action=submit-ice', $vm['action']);
    }

    public function testNewFormHasTheClassPickerWeightEventAndScripts(): void
    {
        $html = renderIceTechSheetFormHtml($this->vm());
        $this->assertStringContainsString('<select id="ice_class" name="class" required>', $html);
        $this->assertStringContainsString('<option value="LS">LS — Limited Stud</option>', $html);
        $this->assertStringContainsString('name="car_weight"', $html);
        $this->assertStringContainsString('<input type="hidden" name="event_id" value="20">', $html);
        $this->assertStringContainsString('<input type="hidden" name="car_id" value="3">', $html);
        $this->assertStringContainsString('NASCC Ice #1', $html);
        $this->assertStringContainsString('Northern Alberta Sports Car Club', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=new-ice&amp;car_id=3&amp;event_id=21"', $html);
        $this->assertStringContainsString('id="car_colour"', $html);   // car has no colour on file
        $this->assertStringContainsString('id="ice-class-note"', $html);
        $this->assertStringContainsString('id="ice-helmet-note"', $html);
        $this->assertStringContainsString('window.ICE_SECTIONS_BY_CLASS', $html);
        $this->assertStringContainsString('<script src="js/ice-class-picker.js"></script>', $html);
        $this->assertLessThan(strpos($html, 'js/tech-sheet-form.js'), strpos($html, 'js/ice-class-picker.js'));
        $this->assertStringNotContainsString('id="sheet_type"', $html);
        $this->assertStringNotContainsString('id="add-driver-btn"', $html);
    }

    public function testEditFormPreselectsTheClassAndPostsToUpdate(): void
    {
        $sheet = ['id' => 9, 'event_id' => 20, 'class' => 'LS', 'car_weight' => 2300, 'entrant_name' => 'Jordan Lee',
                  'driver_name' => 'Jordan Lee', 'engine_hp' => '90', 'checklist_json' => '{"cage":{"status":"ok"}}',
                  'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1, 'entrant_signature_path' => 'x.png',
                  'driver_signature_path' => null];
        $vm = $this->vm($sheet);
        $this->assertSame('tech-sheets.php?action=update', $vm['action']);
        $html = renderIceTechSheetFormHtml($vm);
        $this->assertStringContainsString('<option value="LS" selected>LS — Limited Stud</option>', $html);
        $this->assertStringContainsString('<input type="hidden" name="tech_sheet_id" value="9">', $html);
        $this->assertStringContainsString('value="2300"', $html);
        $this->assertStringContainsString('Leave the pads blank to keep the signatures already on file.', $html);
        $this->assertStringNotContainsString('action=new-ice', $html);   // the event can't change on an edit
    }

    public function testTextIsEscaped(): void
    {
        $vm = $this->vm();
        $vm['event']['name'] = 'Ice <Day>';
        $this->assertStringContainsString('Ice &lt;Day&gt;', renderIceTechSheetFormHtml($vm));
    }
}
```

Add to `tests/TechSheetsHandlersTest.php` (inside the class; it has the `body()` helper):

```php
    public function testIceRoutesExist(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../tech-sheets.php'));
        $this->assertStringContainsString("case 'new-ice':", $src);
        $this->assertStringContainsString("case 'submit-ice':", $src);
        $this->assertStringContainsString("require __DIR__ . '/ice-sheet-page.php';", $src);
    }

    public function testNewIceUsesIceEventsAndNoDeclaration(): void
    {
        foreach (['handleNewIce', 'handleSubmitIce'] as $fn) {
            $body = $this->body($fn);
            $this->assertStringContainsString('db_get_user_car(', $body, $fn);
            $this->assertStringNotContainsString('db_get_car_current_declaration(', $body, $fn);
        }
        $this->assertStringContainsString('db_get_active_events($pdo, DISCIPLINE_ICE)', $this->body('handleNewIce'));
        $submit = $this->body('handleSubmitIce');
        $this->assertStringContainsString('iceSheetValidate(', $submit);
        $this->assertStringContainsString("'car_id' => \$carId", $submit);
        $this->assertStringContainsString("'sheet_type' => 'ice'", $submit);
        $this->assertStringContainsString('catch (InvalidArgumentException $e)', $submit);
        $this->assertStringContainsString('techSheetRecipientEmail(', $submit);
    }

    public function testIceUpdateKeepsTheSheetsEventAndEmailsTheAccountHolder(): void
    {
        $body = $this->body('handleUpdateIce');
        $this->assertStringContainsString("'event_id' => (int)\$sheet['event_id']", $body);
        $this->assertStringNotContainsString("\$_POST['event_id']", $body);
        $this->assertStringContainsString('iceSheetValidate(', $body);
        $this->assertStringContainsString('techSheetRecipientEmail(', $body);
        $this->assertStringContainsString('handleUpdateIce(', $this->body('handleUpdate'));
        $this->assertStringContainsString('techSheetIsIce(', $this->body('handleEdit'));
    }

    public function testPretechIsOffForIceSheets(): void
    {
        foreach (['handlePretech', 'handlePretechSubmit'] as $fn) {
            $this->assertStringContainsString('techSheetIsIce(', $this->body($fn), $fn);
        }
        $this->assertStringContainsString('!techSheetIsIce($sheet)', $this->body('handleView'));
    }

    public function testResendUsesTheRecipientHelper(): void
    {
        $this->assertStringContainsString('techSheetRecipientEmail(', $this->body('handleResendTechSheet'));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter "IceSheetPageTest|TechSheetsHandlersTest|TechSheetDriverChoiceTest"`
Expected: FAIL (missing file, functions and routes).

- [ ] **Step 3: Add `techSheetDriver1FormState()` to `tech-sheet-data.php`**

Append:

```php
/**
 * Driver 1 picker state for the tech sheet forms: the owner's drivers by id, which one is "you",
 * the selected choice (you for a new sheet; the sheet's driver, or 'new' with their name, for an
 * edit), and the list the driver-choice JS needs.
 */
function techSheetDriver1FormState(array $ownerDrivers, ?array $sheet): array {
    $ownedById = [];
    $selfId = null;
    foreach ($ownerDrivers as $d) {
        $ownedById[(int)$d['id']] = $d;
        if ($selfId === null && (int)($d['user_id'] ?? 0) === (int)$d['owner_user_id']) $selfId = (int)$d['id'];
    }
    $choice = $sheet !== null
        ? techSheetDriverChoiceFor($ownedById, (string)$sheet['driver_name'])
        : ($selfId !== null ? (string)$selfId : 'new');
    return [
        'ownedById' => $ownedById,
        'selfId' => $selfId,
        'choice' => $choice,
        'newName' => ($sheet !== null && $choice === 'new') ? (string)$sheet['driver_name'] : '',
        'driversForJs' => array_map(fn(array $d): array => ['id' => (int)$d['id'], 'name' => (string)$d['name'], 'self' => (int)$d['id'] === $selfId], $ownerDrivers),
    ];
}
```

In `tech-sheets.php` `renderTechSheetForm()`, replace the block from `$ownedById = [];` through the `$driversForJs = array_map(...)` line with:

```php
    $d1 = techSheetDriver1FormState($ownerDrivers, $isEdit ? $existingSheet : null);
    $ownedById = $d1['ownedById'];
    $selfId = $d1['selfId'];
    $driver1Choice = $d1['choice'];
    $driver1NewName = $d1['newName'];
    $driversForJs = $d1['driversForJs'];
```

The summer form's output stays identical; the existing summer tests prove it.

- [ ] **Step 4: Write `ice-sheet-page.php`**

```php
<?php
// wcma-calculator/ice-sheet-page.php
//
// The ice tech sheet form: its view model and HTML. It keeps the summer form's element ids so
// js/tech-sheet-form.js drives both; js/ice-class-picker.js swaps the checklist when the class
// changes. Callers must have loaded view_helpers.php and garage-page.php (garageCarTitle/Sub).
require_once __DIR__ . '/ice-sheet-lib.php';

/** Everything the ice form needs. $sheet is null for a new sheet, the tech_sheets row for an edit. */
function iceSheetFormVm(array $car, array $event, array $iceEvents, array $ownerDrivers, ?array $sheet, string $csrf): array {
    $club = (string)$event['host_club'];
    $sectionsByClass = $fhrByClass = $classNotes = $helmetNotes = [];
    foreach (ICE_CLUBS[$club]['classes'] ?? [] as $code => $def) {
        $class = iceClass($club, (string)$code);
        $sectionsByClass[$code] = iceChecklistSections($club, $class['group']);
        $fhrByClass[$code] = $class['fhr'];
        $classNotes[$code] = $class['note'];
        $helmetNotes[$code] = iceHelmetNote($club, $class);
    }
    $selected = $sheet !== null ? (string)$sheet['class'] : '';
    return [
        'car' => $car, 'event' => $event, 'club' => $club, 'clubLabel' => (string)iceClubLabel($club),
        'otherEvents' => $sheet !== null ? [] : array_values(array_filter($iceEvents, fn(array $e): bool => (int)$e['id'] !== (int)$event['id'])),
        'classOptions' => iceClassOptions($club), 'selectedClass' => $selected,
        'sectionsByClass' => $sectionsByClass, 'fhrByClass' => $fhrByClass,
        'classNotes' => $classNotes, 'helmetNotes' => $helmetNotes,
        'equipmentItems' => iceEquipmentItems(iceClass($club, $selected)),
        'driver1' => techSheetDriver1FormState($ownerDrivers, $sheet), 'ownerDrivers' => $ownerDrivers,
        'sheet' => $sheet, 'csrf' => $csrf,
        'action' => $sheet !== null ? 'tech-sheets.php?action=update' : 'tech-sheets.php?action=submit-ice',
    ];
}

function renderIceTechSheetFormHtml(array $vm): string {
    $car = $vm['car'];
    $event = $vm['event'];
    $sheet = $vm['sheet'];
    $isEdit = $sheet !== null;
    $d1 = $vm['driver1'];
    $sel = $vm['selectedClass'];
    $json = fn($v): string => json_encode($v ?: new stdClass(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $out = '<form id="tech-sheet-form" method="post" action="' . h($vm['action']) . '">'
        . '<input type="hidden" name="csrf_token" value="' . h($vm['csrf']) . '">'
        . ($isEdit ? '<input type="hidden" name="tech_sheet_id" value="' . (int)$sheet['id'] . '">'
                   : '<input type="hidden" name="car_id" value="' . (int)$car['id'] . '">')
        . '<input type="hidden" name="event_id" value="' . (int)$event['id'] . '">'
        . '<input type="hidden" name="checklist_json" id="checklist_json">'
        . '<input type="hidden" name="driver1_equipment_json" id="driver1_equipment_json">'
        . '<input type="hidden" name="drivers_json" id="drivers_json">'
        . '<input type="hidden" name="entrant_signature" id="entrant_signature">'
        . '<input type="hidden" name="driver_signature" id="driver_signature">';

    // Event
    $out .= '<div class="detail-card"><h2>Ice event</h2><p><strong>' . h((string)$event['name']) . '</strong> — '
        . h(date('M j, Y', strtotime((string)$event['event_date']))) . ' · ' . h($vm['clubLabel']) . '</p>';
    if ($vm['otherEvents']) {
        $out .= '<p class="form-hint">Other ice events: ';
        $links = [];
        foreach ($vm['otherEvents'] as $e) {
            $links[] = '<a href="tech-sheets.php?action=new-ice&amp;car_id=' . (int)$car['id'] . '&amp;event_id=' . (int)$e['id'] . '">'
                . h((string)$e['name']) . '</a>';
        }
        $out .= implode(' · ', $links) . '</p>';
    }
    $out .= '</div>';

    // Car
    $out .= '<div class="detail-card"><h2>Car</h2><p class="tech-sheet-car"><span class="hub-plate">' . h((string)$car['car_number']) . '</span> '
        . h(garageCarTitle($car)) . (garageCarSub($car) !== '' ? ' · ' . h(garageCarSub($car)) : '') . '</p>'
        . '<p class="form-hint">Car details come from your Garage. <a href="garage.php?car=' . (int)$car['id'] . '">Edit car details</a></p>';
    if (trim((string)($car['colour'] ?? '')) === '') {
        $out .= '<label for="car_colour">Car colour</label><input type="text" id="car_colour" name="car_colour" maxlength="30" required>'
            . '<p class="form-hint">Your car has no colour on file yet. It will be saved to the car.</p>';
    }
    $out .= '<label for="car_weight">Race weight (lbs, without driver)</label>'
        . '<input type="number" id="car_weight" name="car_weight" min="1" max="9999" step="1" required value="'
        . ($isEdit ? (int)$sheet['car_weight'] : '') . '"></div>';

    // Class
    $out .= '<div class="detail-card"><h2>Class</h2><label for="ice_class">' . h($vm['club']) . ' class</label>'
        . '<select id="ice_class" name="class" required><option value="">Choose a class</option>';
    foreach ($vm['classOptions'] as $code => $label) {
        $out .= '<option value="' . h($code) . '"' . ($code === $sel ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    $out .= '</select><p class="form-hint" id="ice-class-note">' . h($vm['classNotes'][$sel] ?? '') . '</p>'
        . '<p class="form-hint">Pick the class from the ' . h($vm['club']) . ' supplementary regulations.</p></div>';

    // Entrant & driver
    $out .= '<div class="detail-card"><h2>Entrant &amp; Driver</h2><div class="tech-sheet-header-grid">'
        . '<div><label for="entrant_name">Entrant</label><input type="text" id="entrant_name" name="entrant_name" required value="'
        . h($isEdit ? (string)$sheet['entrant_name'] : (string)($d1['ownedById'][$d1['selfId'] ?? 0]['name'] ?? '')) . '"></div>'
        . '<div><label for="driver1_choice">Driver</label><select id="driver1_choice" name="driver1_choice" required>';
    foreach ($vm['ownerDrivers'] as $d) {
        $out .= '<option value="' . (int)$d['id'] . '"' . ((string)(int)$d['id'] === $d1['choice'] ? ' selected' : '') . '>'
            . h((string)$d['name']) . ((int)$d['id'] === $d1['selfId'] ? ' (you)' : '') . '</option>';
    }
    $out .= '<option value="new"' . ($d1['choice'] === 'new' ? ' selected' : '') . '>+ Add a co-driver</option></select>'
        . '<input type="text" id="driver1_new_name" name="driver1_new_name" maxlength="100" placeholder="Driver\'s name" aria-label="Driver name" value="' . h($d1['newName']) . '"></div>'
        . '<div><label for="engine_hp">Engine HP</label><input type="text" id="engine_hp" name="engine_hp" value="' . h($isEdit ? (string)($sheet['engine_hp'] ?? '') : '') . '"></div>'
        . '</div></div>';

    // Checklist and equipment
    $out .= '<div class="detail-card"><h2>Vehicle Checklist</h2><div id="checklist-container"></div></div>'
        . '<div class="detail-card"><h2>Driver Safety Equipment</h2><p class="form-hint" id="ice-helmet-note">' . h($vm['helmetNotes'][$sel] ?? '') . '</p>'
        . '<div id="equipment-container"></div></div>';

    // Log book
    $log = $isEdit ? (string)$sheet['log_book_turned_in'] : null;
    $out .= '<div class="detail-card"><h2>Log Book</h2>'
        . '<label class="checkbox-label"><input type="radio" name="log_book_turned_in" value="1"' . ($log === '1' ? ' checked' : '') . ' required> Yes</label>'
        . '<label class="checkbox-label"><input type="radio" name="log_book_turned_in" value="0"' . ($log === '0' ? ' checked' : '') . '> No</label></div>';

    // Signatures
    $out .= '<div class="detail-card"><h2>Declaration &amp; Signatures</h2>'
        . '<p><em>I hereby stipulate that the above vehicle meets the regulations for the event.</em></p>'
        . ($isEdit ? '<p class="form-hint">Leave the pads blank to keep the signatures already on file.</p>' : '')
        . '<label>Entrant\'s Signature</label><div class="sig-pad-wrap"><canvas id="entrant-sig-canvas"></canvas></div>'
        . '<div class="sig-pad-actions"><button type="button" class="link-button" data-clear-sig="entrant">Clear</button></div>'
        . '<label>Driver\'s Signature</label><div class="sig-pad-wrap"><canvas id="driver-sig-canvas"></canvas></div>'
        . '<div class="sig-pad-actions"><button type="button" class="link-button" data-clear-sig="driver">Clear</button></div></div>';

    $out .= '<div class="form-actions"><button type="submit" class="btn btn-primary" id="tech-sheet-submit-btn">'
        . ($isEdit ? 'Save Changes' : 'Submit Ice Tech Sheet') . '</button></div>'
        . '<div id="tech-sheet-error" class="form-messages error" hidden></div></form>';

    $existingChecklist = $isEdit ? (json_decode((string)($sheet['checklist_json'] ?? '{}'), true) ?: []) : [];
    $existingEquipment = $isEdit ? (json_decode((string)($sheet['driver1_equipment_json'] ?? '{}'), true) ?: []) : [];
    $out .= '<script>'
        . 'const TECH_CHECKLIST_SECTIONS = ' . $json($vm['sectionsByClass'][$sel] ?? []) . ';'
        . 'const TECH_DRIVER_EQUIPMENT_ITEMS = ' . $json($vm['equipmentItems']) . ';'
        . 'window.ICE_SECTIONS_BY_CLASS = ' . $json($vm['sectionsByClass']) . ';'
        . 'window.ICE_FHR_BY_CLASS = ' . $json($vm['fhrByClass']) . ';'
        . 'window.ICE_CLASS_NOTES = ' . $json($vm['classNotes']) . ';'
        . 'window.ICE_HELMET_NOTES = ' . $json($vm['helmetNotes']) . ';'
        . 'window.TECH_SHEET_EXISTING_CHECKLIST = ' . $json($existingChecklist) . ';'
        . 'window.TECH_SHEET_EXISTING_EQUIPMENT = ' . $json($existingEquipment) . ';'
        . 'window.TECH_SHEET_EXISTING_DRIVERS = [];'
        . 'window.TECH_SHEET_DRIVERS = ' . json_encode($d1['driversForJs'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';'
        . 'window.TECH_SHEET_HAS_ENTRANT_SIGNATURE = ' . ($isEdit && !empty($sheet['entrant_signature_path']) ? 'true' : 'false') . ';'
        . 'window.TECH_SHEET_HAS_DRIVER_SIGNATURE = ' . ($isEdit && !empty($sheet['driver_signature_path']) ? 'true' : 'false') . ';'
        . '</script>'
        . '<script src="js/tech-sheet-checklist.js"></script><script src="js/signature-pad.js"></script>'
        . '<script src="js/driver-choice.js"></script><script src="js/ice-class-picker.js"></script>'
        . '<script src="js/tech-sheet-form.js"></script>';
    return $out;
}
```

- [ ] **Step 5: Wire the handlers into `tech-sheets.php`**

1. Requires, after `require __DIR__ . '/tech-sheet-render.php';`:

```php
require __DIR__ . '/ice-sheet-page.php';
```

(`ice-sheet-page.php` pulls in `ice-sheet-lib.php`, `ice-rules.php` and `tech-sheet-data.php` with `require_once`. Task 1 already made those includes `require_once` everywhere.)

2. Router, after the `'submit'` case:

```php
    case 'new-ice':
        $user = requireTechSheetLogin();
        handleNewIce($pdo, $user, (int)($_GET['car_id'] ?? 0), (int)($_GET['event_id'] ?? 0));
        break;

    case 'submit-ice':
        $user = requireTechSheetLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: garage.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleSubmitIce($pdo, $user);
        break;
```

3. New handlers (place them after `handleUpdate()`):

```php
function handleNewIce(PDO $pdo, array $user, int $carId, int $eventId): void {
    $car = db_get_user_car($pdo, (int)$user['id'], $carId);
    if (!$car || $car['archived_at'] !== null) {
        setFlash('Choose one of your cars for the ice tech sheet.', 'error');
        header('Location: garage.php');
        exit;
    }
    $iceEvents = db_get_active_events($pdo, DISCIPLINE_ICE);
    if (!$iceEvents) {
        setFlash('There are no ice events open for tech sheets yet.', 'error');
        header('Location: garage.php?car=' . $carId);
        exit;
    }
    $event = $iceEvents[0];
    foreach ($iceEvents as $e) {
        if ((int)$e['id'] === $eventId) $event = $e;
    }
    renderPageStart('Ice tech sheet', 'garage', ['flash' => getFlash(), 'subnav' => '<a href="garage.php?car=' . $carId . '">&larr; Back to the car</a>']);
    echo renderIceTechSheetFormHtml(iceSheetFormVm($car, $event, $iceEvents, db_get_user_drivers($pdo, (int)$user['id']), null, generateCsrfToken()));
    renderPageEnd();
}

/** Parses and validates an ice form POST for $club. @return array{ok: bool, error: ?string, parsed: ?array, snap: ?array} */
function iceSheetReadPost(PDO $pdo, array $user, array $car, string $club): array {
    $fail = fn(string $m): array => ['ok' => false, 'error' => $m, 'parsed' => null, 'snap' => null];
    $snap = carsSheetSnapshot($car, $_POST);
    if (!$snap['ok']) return $fail((string)$snap['error']);
    $owned = [];
    foreach (db_get_user_drivers($pdo, (int)$user['id']) as $d) $owned[(int)$d['id']] = $d;
    $choices = techSheetApplyDriverChoices(array_merge($_POST, ['sheet_type' => 'standard']), $owned);
    if (!$choices['ok']) return $fail((string)$choices['error']);
    $parsed = iceSheetParsePost(array_merge($choices['post'], [
        'car_number' => $snap['car_number'], 'car_colour' => $snap['car_colour'], 'engine_cc' => (string)($snap['engine_cc'] ?? ''),
    ]));
    $error = iceSheetValidate($parsed, $club);
    return $error !== null ? $fail($error) : ['ok' => true, 'error' => null, 'parsed' => $parsed, 'snap' => $snap];
}

/** Saves the signatures posted with an ice form. */
function iceSheetSaveSignatures(PDO $pdo, int $id): void {
    foreach (['entrant', 'driver'] as $which) {
        if (!empty($_POST[$which . '_signature'])) {
            $path = techSheetSaveSignature(__DIR__, $id, $which, $_POST[$which . '_signature']);
            if ($path) db_update_tech_sheet_signatures($pdo, $id, [$which . '_signature_path' => $path]);
        }
    }
}

function handleSubmitIce(PDO $pdo, array $user): void {
    $carId = (int)($_POST['car_id'] ?? 0);
    $eventId = (int)($_POST['event_id'] ?? 0);
    $back = 'tech-sheets.php?action=new-ice&car_id=' . $carId . '&event_id=' . $eventId;
    $car = db_get_user_car($pdo, (int)$user['id'], $carId);
    if (!$car || $car['archived_at'] !== null) {
        setFlash('Choose one of your cars for the ice tech sheet.', 'error');
        header('Location: garage.php');
        exit;
    }
    $event = db_get_event($pdo, $eventId);
    if (!$event || (int)$event['active'] !== 1 || ($event['discipline'] ?? 'summer') !== DISCIPLINE_ICE) {
        setFlash('Please choose an open ice event.', 'error');
        header('Location: garage.php?car=' . $carId);
        exit;
    }

    $read = iceSheetReadPost($pdo, $user, $car, (string)$event['host_club']);
    if (!$read['ok']) {
        setFlash((string)$read['error'], 'error');
        header('Location: ' . $back);
        exit;
    }
    $p = $read['parsed'];
    try {
        $id = db_insert_tech_sheet($pdo, [
            'car_id' => $carId, 'user_id' => $user['id'], 'event_id' => $eventId, 'sheet_type' => 'ice',
            'entrant_name' => $p['entrant_name'], 'driver_name' => $p['driver_name'],
            'car_make' => $car['make'], 'car_model' => $car['model'], 'car_colour' => $p['car_colour'],
            'car_number' => $p['car_number'], 'class' => $p['class'],
            'engine_cc' => $p['engine_cc'], 'engine_hp' => $p['engine_hp'], 'car_weight' => (int)$p['car_weight'],
            'checklist_json' => json_encode($p['checklist']), 'driver1_equipment_json' => json_encode($p['equipment']),
            'log_book_turned_in' => (int)$p['log_book'],
        ]);
    } catch (InvalidArgumentException $e) {
        setFlash($e->getMessage(), 'error');
        header('Location: ' . $back);
        exit;
    }

    if ($read['snap']['colour_for_car'] !== null) db_update_car($pdo, $carId, ['colour' => $read['snap']['colour_for_car']]);
    db_tag_event($pdo, (int)$user['id'], $eventId, $carId);
    iceSheetSaveSignatures($pdo, $id);

    $sheet = db_get_tech_sheet($pdo, $id);
    $recipient = techSheetRecipientEmail($pdo, $sheet);
    $sent = $recipient !== null && sendTechSheetConfirmationEmail($sheet, [], $event, $recipient, $p['entrant_name']);
    db_update_email_sent_tech_sheet($pdo, $id, $sent ? 1 : 0);
    setFlash('Ice tech sheet submitted' . ($sent ? ' and emailed to you and the club.' : ', but the confirmation email failed to send.'), $sent ? 'success' : 'error');
    header('Location: tech-sheets.php?action=view&id=' . $id);
    exit;
}

/** Update for an ice sheet. The caller has already checked ownership, status and the photo edit lock. */
function handleUpdateIce(PDO $pdo, array $user, array $sheet): void {
    $id = (int)$sheet['id'];
    $car = db_get_user_car($pdo, (int)$user['id'], (int)$sheet['car_id']);
    if ($car === null) {
        setFlash('Car not found.', 'error');
        header('Location: garage.php');
        exit;
    }
    $read = iceSheetReadPost($pdo, $user, $car, (string)$sheet['club']);
    if (!$read['ok']) {
        setFlash((string)$read['error'], 'error');
        header('Location: tech-sheets.php?action=edit&id=' . $id);
        exit;
    }
    $p = $read['parsed'];
    try {
        db_update_tech_sheet($pdo, $id, [
            'event_id' => (int)$sheet['event_id'], 'sheet_type' => 'ice',
            'entrant_name' => $p['entrant_name'], 'driver_name' => $p['driver_name'],
            'car_make' => $car['make'], 'car_model' => $car['model'], 'car_colour' => $p['car_colour'],
            'car_number' => $p['car_number'], 'class' => $p['class'],
            'engine_cc' => $p['engine_cc'], 'engine_hp' => $p['engine_hp'], 'car_weight' => (int)$p['car_weight'],
            'checklist_json' => json_encode($p['checklist']), 'driver1_equipment_json' => json_encode($p['equipment']),
            'log_book_turned_in' => (int)$p['log_book'],
        ]);
    } catch (InvalidArgumentException $e) {
        setFlash($e->getMessage(), 'error');
        header('Location: tech-sheets.php?action=edit&id=' . $id);
        exit;
    }
    if ($read['snap']['colour_for_car'] !== null) db_update_car($pdo, (int)$car['id'], ['colour' => $read['snap']['colour_for_car']]);
    iceSheetSaveSignatures($pdo, $id);

    $updated = db_get_tech_sheet($pdo, $id);
    $event = db_get_event($pdo, (int)$sheet['event_id']) ?? [];
    $recipient = techSheetRecipientEmail($pdo, $updated);
    $sent = $recipient !== null && sendTechSheetConfirmationEmail($updated, [], $event, $recipient, $p['entrant_name']);
    db_update_email_sent_tech_sheet($pdo, $id, $sent ? 1 : 0);
    setFlash('Ice tech sheet updated' . ($sent ? ' and re-emailed to you and the club.' : ', but the confirmation email failed to send.'), $sent ? 'success' : 'error');
    header('Location: tech-sheets.php?action=view&id=' . $id);
    exit;
}
```

4. `handleEdit()`: directly after the `$car = db_get_user_car(...)` null check, add:

```php
    if (techSheetIsIce($sheet)) {
        $event = db_get_event($pdo, (int)$sheet['event_id']) ?? ['id' => (int)$sheet['event_id'], 'name' => '', 'event_date' => date('Y-m-d'), 'host_club' => (string)$sheet['club']];
        $event['host_club'] = (string)$sheet['club'];
        renderPageStart('Edit Ice Tech Sheet', 'garage', ['flash' => getFlash(), 'subnav' => '<a href="tech-sheets.php?action=view&amp;id=' . $id . '">&larr; Back to the sheet</a>']);
        echo renderIceTechSheetFormHtml(iceSheetFormVm($car, $event, [], db_get_user_drivers($pdo, (int)$user['id']), $sheet, generateCsrfToken()));
        renderPageEnd();
        return;
    }
```

5. `handleUpdate()`: directly after the `pretechSheetEditable` check (before reading `$_POST['event_id']`), add:

```php
    if (techSheetIsIce($sheet)) {
        handleUpdateIce($pdo, $user, $sheet);
        return;
    }
```

6. `handleView()`: change the pretech button condition to

```php
    <?php if ($sheet['status'] === 'submitted' && $carStatus['state'] !== 'accepted' && !techSheetIsIce($sheet)): ?>
```

7. `handlePretech()` and `handlePretechSubmit()`: after the `if (!$sheet) {...}` block in each, add:

```php
    if (techSheetIsIce($sheet)) {
        setFlash('Photo pre-tech for ice tech sheets isn\'t available yet. Bring the car to tech at the event.', 'error');
        header('Location: tech-sheets.php?action=view&id=' . $id);
        exit;
    }
```

8. `handleResendTechSheet()`: replace

```php
    $submission = db_get_submission($pdo, (int)$sheet['submission_id']);
    $recipientEmail = $submission['email'] ?? null;
```

with

```php
    $recipientEmail = techSheetRecipientEmail($pdo, $sheet);
```

Also update the comment above it: the recipient is the declaration's email, or the account holder's for ice sheets.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php phpunit.phar --filter "IceSheetPageTest|TechSheetsHandlersTest|TechSheetDriverChoiceTest"` → PASS.
Run: `php -l tech-sheets.php && php -l ice-sheet-page.php` → no syntax errors.
Run: `php phpunit.phar` → the full suite PASSES.

- [ ] **Step 7: Commit**

```bash
git add ice-sheet-page.php tech-sheet-data.php tech-sheets.php tests/IceSheetPageTest.php tests/TechSheetsHandlersTest.php tests/TechSheetDriverChoiceTest.php
git commit -m "feat(ice): ice tech sheet form, submit and edit; pre-tech off for ice sheets"
```

---

### Task 6: Keep summer status summer-only; add the Garage "Ice racing" section

**Files:**
- Modify: `garage-lib.php` (add `garageSummerSheets()`, `garageIceRows()`)
- Modify: `garage.php` (`garageShowList`, `garageShowCar`)
- Modify: `garage-page.php` (`renderGarageCarHtml` renders `$vm['ice']`)
- Modify: `readiness-lib.php` (`loadReadinessInputs` keeps summer sheets only)
- Test: `tests/GarageLibTest.php` (add), `tests/GaragePageTest.php` (add), `tests/ReadinessLoaderTest.php` (add)

**Interfaces:**
- Consumes: `techSheetClassLine()` (Task 2); `db_get_active_events($pdo, DISCIPLINE_ICE)`.
- Produces:
  - `garageSummerSheets(array $sheets): array`
  - `garageIceRows(array $carSheets, array $iceEvents): array` — one row per active ice event: `['event' => $e, 'sheet' => ?array]` (the newest ice sheet for that event), plus rows for this car's ice sheets whose event is no longer active, flagged `'past' => true`
  - `renderGarageCarHtml($vm)` renders an "Ice racing" section when `$vm['ice']` is a non-empty array

- [ ] **Step 1: Write the failing tests**

Add to `tests/GarageLibTest.php` (inside the class; it already requires `garage-lib.php`):

```php
    public function testSummerSheetsExcludeIce(): void
    {
        $sheets = [['id' => 1, 'discipline' => 'summer'], ['id' => 2, 'discipline' => 'ice'], ['id' => 3]];
        $this->assertSame([1, 3], array_map(fn($s) => $s['id'], garageSummerSheets($sheets)));
    }

    public function testIceRowsPairEachActiveIceEventWithItsNewestSheet(): void
    {
        $events = [['id' => 20, 'name' => 'NASCC Ice #1', 'event_date' => '2026-12-12', 'host_club' => 'NASCC'],
                   ['id' => 21, 'name' => 'WSCC Fire on Ice', 'event_date' => '2027-01-04', 'host_club' => 'WSCC']];
        $sheets = [['id' => 5, 'event_id' => 20, 'discipline' => 'ice'], ['id' => 7, 'event_id' => 20, 'discipline' => 'ice'],
                   ['id' => 8, 'event_id' => 30, 'discipline' => 'ice', 'event_name' => 'Old Ice'],
                   ['id' => 9, 'event_id' => 21, 'discipline' => 'summer']];
        $rows = garageIceRows($sheets, $events);
        $this->assertSame(7, $rows[0]['sheet']['id']);
        $this->assertNull($rows[1]['sheet']);                 // summer sheet on 21 is ignored
        $this->assertSame(8, $rows[2]['sheet']['id']);
        $this->assertTrue($rows[2]['past']);
        $this->assertFalse($rows[0]['past']);
        $this->assertSame([], garageIceRows([], []));
    }
```

Add to `tests/GaragePageTest.php` (inside the class):

```php
    public function testCarPageShowsTheIceRacingSection(): void
    {
        $html = renderGarageCarHtml($this->carVm(['ice' => [
            ['event' => ['id' => 20, 'name' => 'NASCC Ice #1', 'event_date' => '2026-12-12', 'host_club' => 'NASCC'], 'sheet' => null, 'past' => false],
            ['event' => ['id' => 21, 'name' => 'WSCC <Ice>', 'event_date' => '2027-01-04', 'host_club' => 'WSCC'],
             'sheet' => ['id' => 9, 'discipline' => 'ice', 'club' => 'WSCC', 'class' => 'FOI-STD', 'status' => 'teched'], 'past' => false],
        ]]));
        $this->assertStringContainsString('<h2>Ice racing</h2>', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=new-ice&amp;car_id=3&amp;event_id=20">Submit ice tech sheet</a>', $html);
        $this->assertStringContainsString('WSCC &lt;Ice&gt;', $html);
        $this->assertStringContainsString('FOI-STD — Fire on Ice – Studded (WSCC)', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=view&amp;id=9">View</a>', $html);
        $this->assertStringContainsString('Accepted', $html);
    }

    public function testNoIceSectionWithoutIceRows(): void
    {
        $this->assertStringNotContainsString('Ice racing', renderGarageCarHtml($this->carVm()));
        $this->assertStringNotContainsString('Ice racing', renderGarageCarHtml($this->carVm(['ice' => []])));
    }

    public function testArchivedCarShowsIceSheetsButNoSubmitLink(): void
    {
        $html = renderGarageCarHtml($this->carVm(['car' => $this->car(['archived_at' => '2026-09-01 00:00:00']), 'ice' => [
            ['event' => ['id' => 20, 'name' => 'NASCC Ice #1', 'event_date' => '2026-12-12', 'host_club' => 'NASCC'], 'sheet' => null, 'past' => false],
        ]]));
        $this->assertStringNotContainsString('action=new-ice', $html);
    }
```

`carVm()` must accept an override array. It already does, via `array_merge(..., $o)`. Add `require_once __DIR__ . '/../ice-sheet-lib.php';` to the top of `GaragePageTest.php` if `garage-page.php` doesn't pull it in.

Add to `tests/ReadinessLoaderTest.php` (inside the class):

```php
    public function testLoaderKeepsOnlySummerSheets(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'both@example.com', 'name' => 'Both Seasons', 'password_hash' => 'x', 'google_id' => null]);
        $car = test_make_car($pdo, $u, '7');
        $ice = db_create_event($pdo, 'NASCC Ice #1', '2027-01-10', null, 'ice', 'NASCC');
        $sheet = test_make_ice_sheet($pdo, $u, $car, $ice);
        db_accept_tech_sheet_in_person($pdo, $sheet, $u, 'uploads/sig.png');

        $in = loadReadinessInputs($pdo, $u, '2026-09-26');
        $this->assertSame([], $in['sheets']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter "GarageLibTest|GaragePageTest|ReadinessLoaderTest"` → FAIL.

- [ ] **Step 3: Implement it**

Append to `garage-lib.php`:

```php
/** The summer sheets among $sheets: summer car tech, events and history never count ice sheets. */
function garageSummerSheets(array $sheets): array {
    return array_values(array_filter($sheets, fn(array $s): bool => ($s['discipline'] ?? 'summer') !== 'ice'));
}

/**
 * The car's "Ice racing" rows: each active ice event with the car's newest ice sheet for it, then
 * the car's ice sheets for events no longer open ('past' => true).
 *
 * @return array<int, array{event: array, sheet: ?array, past: bool}>
 */
function garageIceRows(array $carSheets, array $iceEvents): array {
    $newest = [];
    foreach ($carSheets as $s) {
        if (($s['discipline'] ?? 'summer') !== 'ice') continue;
        $eid = (int)$s['event_id'];
        if (!isset($newest[$eid]) || (int)$s['id'] > (int)$newest[$eid]['id']) $newest[$eid] = $s;
    }
    $rows = [];
    foreach ($iceEvents as $e) {
        $rows[] = ['event' => $e, 'sheet' => $newest[(int)$e['id']] ?? null, 'past' => false];
        unset($newest[(int)$e['id']]);
    }
    foreach ($newest as $eid => $s) {
        $rows[] = ['event' => ['id' => $eid, 'name' => (string)($s['event_name'] ?? 'Earlier ice event'), 'event_date' => (string)($s['event_date'] ?? ''), 'host_club' => (string)($s['club'] ?? '')],
                   'sheet' => $s, 'past' => true];
    }
    return $rows;
}
```

In `garage.php` `garageShowList()`, change `$sheets = db_get_user_tech_sheets($pdo, $uid);` to `$sheets = garageSummerSheets(db_get_user_tech_sheets($pdo, $uid));`.

In `garage.php` `garageShowCar()`:
- Rename the loaded list to `$allSheets = array_values(array_filter(db_get_user_tech_sheets($pdo, $uid), fn(array $s): bool => (int)$s['car_id'] === $carId));`, then `$sheets = garageSummerSheets($allSheets);`. Keep every existing use of `$sheets`.
- Add `'ice' => garageIceRows($allSheets, db_get_active_events($pdo, DISCIPLINE_ICE)),` to the `renderGarageCarHtml([...])` array.
- If `garage.php` doesn't already load `ice-sheet-lib.php`, add `require_once __DIR__ . '/ice-sheet-lib.php';` beside its other requires.

In `garage-page.php`:
- Add `require_once __DIR__ . '/ice-sheet-lib.php';` near the top.
- In `renderGarageCarHtml()`, directly before the `if (!$archived) { ... Archive ... }` block, add:

```php
    // Ice racing
    if (!empty($vm['ice'])) {
        $out .= '<section class="hub-card"><h2>Ice racing</h2>';
        foreach ($vm['ice'] as $row) {
            $e = $row['event'];
            $sheet = $row['sheet'];
            $when = $e['event_date'] !== '' ? ' ' . h(date('M j', strtotime((string)$e['event_date']))) : '';
            $out .= '<div class="garage-event"><div><strong>' . h((string)$e['name']) . '</strong>' . $when
                . ' · ' . h((string)$e['host_club']) . '</div>';
            if ($sheet !== null) {
                $accepted = ($sheet['status'] ?? '') === 'teched';
                $out .= '<span class="hub-status ' . ($accepted ? 'hub-status--ok">Accepted' : 'hub-status--info">Submitted') . '</span> '
                    . h(techSheetClassLine($sheet)) . ' <a href="tech-sheets.php?action=view&amp;id=' . (int)$sheet['id'] . '">View</a>';
            } elseif (!$archived && empty($row['past'])) {
                $out .= '<span class="hub-status hub-status--todo">No ice tech sheet yet</span> '
                    . '<a class="hub-btn" href="tech-sheets.php?action=new-ice&amp;car_id=' . $id . '&amp;event_id=' . (int)$e['id'] . '">Submit ice tech sheet</a>';
            }
            $out .= '</div>';
        }
        $out .= '</section>';
    }
```

In `readiness-lib.php` `loadReadinessInputs()`, change `$sheets = db_get_user_tech_sheets($pdo, $userId);` to

```php
    // Summer readiness only (ice readiness is Phase 4): an ice sheet must never count as summer car tech.
    $sheets = array_values(array_filter(db_get_user_tech_sheets($pdo, $userId), fn(array $s): bool => ($s['discipline'] ?? 'summer') !== 'ice'));
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar --filter "GarageLibTest|GaragePageTest|ReadinessLoaderTest"` → PASS.
Run: `php phpunit.phar` → the full suite PASSES.

- [ ] **Step 5: Commit**

```bash
git add garage-lib.php garage.php garage-page.php readiness-lib.php tests/GarageLibTest.php tests/GaragePageTest.php tests/ReadinessLoaderTest.php
git commit -m "feat(ice): Garage ice racing section; summer status ignores ice sheets"
```

---

### Task 7: Ice gear — discipline-aware gear, level on in-person acceptance

**Files:**
- Modify: `gear-lib.php`:
  - `gearSeasonNow`, `gearStatusLabel`, `gearCreate`, `gearAcceptInPerson`, `gearRevoke`, `gearLinksForSheet`, `gearCreateAndAcceptInPerson`
  - add `require_once __DIR__ . '/ice-rules.php';`
- Modify: `gear-chips.php` (`gearChipCreateForm`, `renderGearChips`)
- Modify: `admin-gear.php` (`handleGearAdminAcceptInPerson`, `handleGearCreateAccept`, `renderGearAdminViewPage`)
- Test: `tests/GearLibTest.php` (add), `tests/GearChipsTest.php` (add), `tests/GearCreateAcceptTest.php` (add)

**Interfaces:**
- Consumes:
  - Task 1's `iceGearLevelForClass()`, `ICE_GEAR_LEVEL_LABELS`
  - `db_set_gear_level()`
  - `db_find_gear_record(..., $discipline)`, `db_insert_gear_record(..., $discipline)`
  - `iceSeasonFromDate()`
- Produces:
  - `gearSeasonNow(string $discipline = 'summer'): int`
  - `gearStatusLabel(array $status, int $season, string $discipline = 'summer'): string` — ice returns `'Gear teched Ice 2027'` and similar
  - `gearCreate(PDO, int $ownerId, string $name, string $licence, int $season, string $discipline = 'summer'): array`
  - `gearAcceptInPerson(PDO, int $id, int $reviewerUserId, ?string $level = null): array` — ice records need `'street_safe'|'caged'`
  - `gearRevoke()` clears an ice record's level
  - `gearLinksForSheet()` matches gear of the sheet's discipline only; each link gains `'discipline'` and `'default_level'` (ice: the class's level; summer: null)
  - `gearCreateAndAcceptInPerson(PDO, array $sheet, array $drivers, int $driverNumber, int $reviewerUserId, ?string $level = null): array`
  - chips:
    - the ice create-accept form has `<select name="level">` preselected with `default_level`
    - accepted ice chips show `· caged` / `· street-safe`

- [ ] **Step 1: Write the failing tests**

Add to `tests/GearLibTest.php` (inside the class; the file requires `gear-lib.php`):

```php
    private function owner(PDO $pdo): int {
        return db_create_user($pdo, ['email' => 'g' . uniqid() . '@example.com', 'name' => 'Owner', 'password_hash' => 'x', 'google_id' => null]);
    }

    public function testIceSeasonNowAndIceLabels(): void
    {
        $this->assertSame(iceSeasonFromDate(date('Y-m-d')), gearSeasonNow('ice'));
        $this->assertSame((int)date('Y'), gearSeasonNow());
        $this->assertSame('Gear teched Ice 2027', gearStatusLabel(['state' => 'accepted', 'via' => 'in_person'], 2027, 'ice'));
        $this->assertSame('Gear teched 2026', gearStatusLabel(['state' => 'accepted', 'via' => 'in_person'], 2026));
    }

    public function testIceGearAcceptNeedsALevelAndRevokeClearsIt(): void
    {
        $pdo = make_temp_pdo();
        $owner = $this->owner($pdo);
        $r = gearCreate($pdo, $owner, 'Sam', '', 2027, 'ice');
        $this->assertTrue($r['ok']);
        $id = (int)$r['id'];
        $this->assertSame('ice', db_get_gear_record($pdo, $id)['discipline']);

        $this->assertFalse(gearAcceptInPerson($pdo, $id, $owner)['ok']);
        $this->assertFalse(gearAcceptInPerson($pdo, $id, $owner, 'bogus')['ok']);
        $this->assertSame('open', db_get_gear_record($pdo, $id)['status']);

        $this->assertTrue(gearAcceptInPerson($pdo, $id, $owner, 'caged')['ok']);
        $this->assertSame('caged', db_get_gear_record($pdo, $id)['level']);

        $this->assertTrue(gearRevoke($pdo, $id)['ok']);
        $this->assertNull(db_get_gear_record($pdo, $id)['level']);
    }

    public function testSummerGearAcceptIgnoresLevel(): void
    {
        $pdo = make_temp_pdo();
        $owner = $this->owner($pdo);
        $id = (int)gearCreate($pdo, $owner, 'Sam', '', 2026)['id'];
        $this->assertTrue(gearAcceptInPerson($pdo, $id, $owner)['ok']);
        $this->assertNull(db_get_gear_record($pdo, $id)['level']);
    }

    public function testSheetLinksOnlyMatchGearOfTheSheetsDiscipline(): void
    {
        $gear = [
            ['id' => 1, 'owner_user_id' => 4, 'season' => 2027, 'discipline' => 'summer', 'driver_name_norm' => 'sam', 'status' => 'accepted', 'accepted_via' => 'in_person', 'photo_status' => null],
            ['id' => 2, 'owner_user_id' => 4, 'season' => 2027, 'discipline' => 'ice', 'driver_name_norm' => 'sam', 'status' => 'open', 'accepted_via' => null, 'photo_status' => null],
        ];
        $ice = gearLinksForSheet(['user_id' => 4, 'season' => 2027, 'driver_name' => 'Sam', 'discipline' => 'ice', 'club' => 'NASCC', 'class' => 'LS'], [], $gear);
        $this->assertSame(2, $ice[0]['gear']['id']);
        $this->assertSame('ice', $ice[0]['discipline']);
        $this->assertSame('caged', $ice[0]['default_level']);
        $summer = gearLinksForSheet(['user_id' => 4, 'season' => 2027, 'driver_name' => 'Sam'], [], $gear);
        $this->assertSame(1, $summer[0]['gear']['id']);
        $this->assertNull($summer[0]['default_level']);
    }
```

If `GearLibTest` already defines an `owner()` helper, reuse it instead of adding a second one.

Add to `tests/GearCreateAcceptTest.php` (inside the class; copy its user, sheet and helper setup):

```php
    public function testIceSheetCreatesAnIceRecordWithTheChosenLevel(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'ica@example.com', 'name' => 'Owner', 'password_hash' => 'x', 'google_id' => null]);
        $car = test_make_car($pdo, $u, '7');
        $event = db_create_event($pdo, 'NASCC Ice', date('Y-m-d', strtotime('+10 days')), null, 'ice', 'NASCC');
        $sheet = db_get_tech_sheet($pdo, test_make_ice_sheet($pdo, $u, $car, $event, 'SS'));

        $this->assertFalse(gearCreateAndAcceptInPerson($pdo, $sheet, [], 1, $u)['ok']);   // no level
        $r = gearCreateAndAcceptInPerson($pdo, $sheet, [], 1, $u, 'street_safe');
        $this->assertTrue($r['ok'], (string)$r['error']);
        $g = db_get_gear_record($pdo, (int)$r['id']);
        $this->assertSame('ice', $g['discipline']);
        $this->assertSame((int)$sheet['season'], (int)$g['season']);
        $this->assertSame('street_safe', $g['level']);
        $this->assertSame('accepted', $g['status']);
    }
```

Note: the ice season is the event's year, +1 from July. A sheet whose event is 10 days away is in `gearSeasonNow('ice')` for every date except the ten days before 1 July. That's acceptable for a test; if the suite runs in that window, add a comment rather than special-casing it.

Add to `tests/GearChipsTest.php` (inside the class):

```php
    public function testIceChipsShowLevelAndTheCreateFormHasALevelPicker(): void
    {
        $accepted = ['driver_number' => 1, 'name' => 'Sam', 'name_norm' => 'sam', 'discipline' => 'ice', 'default_level' => 'caged',
                     'gear' => ['id' => 3, 'season' => 2027, 'discipline' => 'ice', 'level' => 'street_safe'],
                     'status' => ['state' => 'accepted', 'via' => 'in_person']];
        $this->assertStringContainsString('Gear teched Ice 2027 · street-safe', renderGearChips([$accepted], 'owner'));

        $none = ['driver_number' => 1, 'name' => 'Sam', 'name_norm' => 'sam', 'discipline' => 'ice', 'default_level' => 'caged',
                 'gear' => null, 'status' => ['state' => 'none', 'via' => null]];
        $html = renderGearChips([$none], 'admin', ['csrf' => 'tok', 'sheet_id' => 9, 'sheet_season' => gearSeasonNow('ice')]);
        $this->assertStringContainsString('<select name="level"', $html);
        $this->assertStringContainsString('<option value="caged" selected>caged</option>', $html);
        $this->assertStringContainsString('<option value="street_safe">street-safe</option>', $html);
    }

    public function testSummerCreateFormHasNoLevelPicker(): void
    {
        $none = ['driver_number' => 1, 'name' => 'Sam', 'name_norm' => 'sam', 'gear' => null, 'status' => ['state' => 'none', 'via' => null]];
        $this->assertStringNotContainsString('name="level"', renderGearChips([$none], 'admin', ['csrf' => 'tok', 'sheet_id' => 9]));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter "GearLibTest|GearCreateAcceptTest|GearChipsTest"` → FAIL.

- [ ] **Step 3: Implement it in `gear-lib.php`**

Add `require_once __DIR__ . '/ice-rules.php';` with the other requires.

```php
function gearSeasonNow(string $discipline = DISCIPLINE_SUMMER): int {
    return $discipline === DISCIPLINE_ICE ? iceSeasonFromDate(date('Y-m-d')) : (int)date('Y');
}
```

In `gearStatusLabel()`, change the signature to `(array $status, int $season, string $discipline = DISCIPLINE_SUMMER)` and the accepted case to:

```php
        case 'accepted':       return ($status['via'] === 'photos' ? 'Gear pre-teched ' : 'Gear teched ') . ($discipline === DISCIPLINE_ICE ? 'Ice ' : '') . $season;
```

In `gearCreate()`:
- Add a trailing `string $discipline = DISCIPLINE_SUMMER` parameter.
- Pass it to `db_find_gear_record($pdo, $ownerId, $norm, $season, $discipline)` and `db_insert_gear_record($pdo, (int)$driverId, $season, $discipline)`.

Replace `gearAcceptInPerson()`:

```php
/** In-person acceptance. Ice records also record the gear level the inspector confirmed. @return array{ok: bool, error: ?string} */
function gearAcceptInPerson(PDO $pdo, int $id, int $reviewerUserId, ?string $level = null): array {
    $gear = db_get_gear_record($pdo, $id);
    if ($gear === null) return ['ok' => false, 'error' => 'Gear record not found.'];
    $isIce = ($gear['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_ICE;
    if ($isIce && !isset(ICE_GEAR_LEVEL_LABELS[(string)$level])) {
        return ['ok' => false, 'error' => 'Choose the gear level: street-safe or caged.'];
    }
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        if (!db_accept_gear_in_person($pdo, $id, $reviewerUserId)) {
            if ($own) $pdo->rollBack();
            return ['ok' => false, 'error' => 'This driver\'s gear has already been teched.'];
        }
        if ($isIce) db_set_gear_level($pdo, $id, $level);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'error' => null];
}
```

In `gearRevoke()`, inside the transaction after the `if ($viaPhotos) ...` line, add:

```php
        if (($gear['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_ICE) db_set_gear_level($pdo, $id, null);
```

In `gearLinksForSheet()`:
- Replace the first lines up to the `$byName` loop with:

```php
    $discipline = (string)($sheet['discipline'] ?? DISCIPLINE_SUMMER);
    $season = (int)($sheet['season'] ?? 0) ?: gearSeasonNow($discipline);
    $ownerId = (int)($sheet['user_id'] ?? 0);
    $defaultLevel = $discipline === DISCIPLINE_ICE ? iceGearLevelForClass((string)($sheet['club'] ?? ''), (string)($sheet['class'] ?? '')) : null;

    $byName = [];
    foreach ($ownerGear as $g) {
        if ((int)$g['owner_user_id'] === $ownerId && (int)$g['season'] === $season
            && ($g['discipline'] ?? DISCIPLINE_SUMMER) === $discipline) {
            $byName[$g['driver_name_norm']] = $g;
        }
    }
```

- Add `'discipline' => $discipline, 'default_level' => $defaultLevel,` to each `$links[] = [...]` entry.
- Update its docblock: the links match the owner's gear for the sheet's season **and discipline**.

In `gearCreateAndAcceptInPerson()`:
- Add a trailing `?string $level = null` parameter.
- Replace the season lines with:

```php
    $discipline = (string)($sheet['discipline'] ?? DISCIPLINE_SUMMER);
    $season = (int)($sheet['season'] ?? 0) ?: gearSeasonNow($discipline);
    if ($season !== gearSeasonNow($discipline)) return $fail('Gear can only be added for the current season.');
    if ($discipline === DISCIPLINE_ICE && !isset(ICE_GEAR_LEVEL_LABELS[(string)$level])) {
        return $fail('Choose the gear level: street-safe or caged.');
    }
```

- Pass `$discipline` to `db_find_gear_record(..., $season, $discipline)` and `gearCreate(..., $season, $discipline)`.
- Pass `$level` to `gearAcceptInPerson($pdo, $id, $reviewerUserId, $level)`.

- [ ] **Step 4: Implement it in `gear-chips.php`**

Change `gearChipCreateForm()` to take a trailing `?string $defaultLevel = null` argument. When it is not null, add a level picker before the button:

```php
    if ($defaultLevel !== null) {
        $out .= '<label class="gear-level-label">Gear level <select name="level" required>';
        foreach (ICE_GEAR_LEVEL_LABELS as $value => $label) {
            $out .= '<option value="' . h($value) . '"' . ($value === $defaultLevel ? ' selected' : '') . '>' . h($label) . '</option>';
        }
        $out .= '</select></label> ';
    }
```

In `renderGearChips()`:
- Compute the season check per link: `$linkDiscipline = (string)($l['discipline'] ?? DISCIPLINE_SUMMER);` and `$seasonOk = $sheetSeason === 0 || $sheetSeason === gearSeasonNow($linkDiscipline);`. Move it inside the loop and remove the outer `$seasonOk`.
- Pass `$l['default_level'] ?? null` as the new last argument of `gearChipCreateForm(...)`.
- Build the accepted label as:

```php
        $label = gearStatusLabel($l['status'], (int)$gear['season'], (string)($gear['discipline'] ?? DISCIPLINE_SUMMER));
        if (($gear['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_ICE && !empty($gear['level']) && $l['status']['state'] === 'accepted') {
            $label .= ' · ' . (ICE_GEAR_LEVEL_LABELS[$gear['level']] ?? $gear['level']);
        }
```

- [ ] **Step 5: Implement it in `admin-gear.php`**

In `handleGearAdminAcceptInPerson()`, pass the posted level:

```php
    $level = is_string($_POST['level'] ?? null) && $_POST['level'] !== '' ? $_POST['level'] : null;
    $r = gearAcceptInPerson($pdo, $id, (int)$user['id'], $level);
```

In `handleGearCreateAccept()`, pass the same `$level` as the last argument of `gearCreateAndAcceptInPerson(...)`.

In `renderGearAdminViewPage()`:
- Change the status label call to `gearStatusLabel($st, (int)$gear['season'], (string)($gear['discipline'] ?? 'summer'))`.
- After the driver/season line, add `<?php if (($gear['discipline'] ?? 'summer') === 'ice'): ?><p>Ice gear<?= !empty($gear['level']) ? ' — level: ' . h(ICE_GEAR_LEVEL_LABELS[$gear['level']] ?? $gear['level']) : '' ?></p><?php endif; ?>`.
- Inside the accept-in-person form, before the button, add:

```php
      <?php if (($gear['discipline'] ?? 'summer') === 'ice'): ?>
      <label for="gear-level">Gear level</label>
      <select id="gear-level" name="level" required>
        <option value="">Choose</option>
        <?php foreach (ICE_GEAR_LEVEL_LABELS as $value => $label): ?>
        <option value="<?= h($value) ?>"><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="form-hint">Caged: SA/FIA helmet (and a frontal head restraint where the class needs one). Street-safe: Snell M2015+ or ECE 22.05/22.06.</p>
      <?php endif; ?>
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php phpunit.phar --filter "GearLibTest|GearCreateAcceptTest|GearChipsTest"` → PASS.
Run: `php -l admin-gear.php` → clean.
Run: `php phpunit.phar` → the full suite PASSES. Existing summer gear tests must not change.

- [ ] **Step 7: Commit**

```bash
git add gear-lib.php gear-chips.php admin-gear.php tests/GearLibTest.php tests/GearCreateAcceptTest.php tests/GearChipsTest.php
git commit -m "feat(ice): ice gear records with a level chosen on in-person acceptance"
```

---

### Task 8: Inspector roster for ice events

**Files:**
- Modify: `inspect-lib.php` (`inspectRosterRows`)
- Modify: `inspect.php` (`inspectShowRoster`)
- Modify: `inspect-page.php` (`inspectRosterRowHtml`)
- Test: `tests/InspectLibTest.php` (add), `tests/InspectPageTest.php` (add)

**Interfaces:**
- Consumes:
  - `seasonForEvent()`
  - `db_get_season_sheets($pdo, $season, $discipline)`
  - `db_get_gear_records_for_season($pdo, $season, $discipline)`
  - `techSheetClassLine()` (Task 2)
  - `gearLinksForSheet()` (Task 7)
- Produces:
  - `inspectRosterRows(..., int $season, array $key = ['discipline' => 'summer', 'club' => null])` — for an ice key, `status` uses the car's ice sheets for that club, and rows gain `'ice_class'` (the class line of the row's sheet, or `''`)
  - `renderInspectRosterHtml` view model gains `'discipline'`
  - the row shows "Ice 2027" status labels and the ice class instead of the declaration class

- [ ] **Step 1: Write the failing tests**

Add to `tests/InspectLibTest.php` (inside the class). Look at an existing `inspectRosterRows` test in the file and copy how it builds `$cars` (keys: `id`, `owner_user_id`, `car_number`, …). Then:

```php
    public function testIceRosterUsesTheClubsIceSheetsAndTheSheetClass(): void
    {
        $car = ['id' => 3, 'owner_user_id' => 4, 'car_number' => '7', 'year' => '1985', 'make' => 'Chevrolet', 'model' => 'Chevette', 'owner_name' => 'Sam', 'tagged' => 1];
        $iceSheet = ['id' => 9, 'car_id' => 3, 'user_id' => 4, 'season' => 2027, 'discipline' => 'ice', 'club' => 'NASCC', 'class' => 'CH',
                     'status' => 'teched', 'accepted_via' => 'in_person', 'photo_status' => null, 'driver_name' => 'Sam', 'event_id' => 20];
        $otherClub = ['id' => 10] + ['club' => 'WSCC'] + $iceSheet;
        $rows = inspectRosterRows([$car], [$iceSheet], [$iceSheet, $otherClub], [], [], [], [], 2027, ['discipline' => 'ice', 'club' => 'NASCC']);
        $this->assertSame('accepted', $rows[0]['status']['state']);
        $this->assertSame('CH — Chevette (NASCC)', $rows[0]['ice_class']);

        $wscc = inspectRosterRows([$car], [], [$otherClub], [], [], [], [], 2027, ['discipline' => 'ice', 'club' => 'NASCC']);
        $this->assertSame('none', $wscc[0]['status']['state']);   // a WSCC sheet doesn't tech the car for NASCC
    }
```

Add to `tests/InspectPageTest.php` (inside the class). Copy an existing roster-row fixture and set on it `'ice_class' => 'CH — Chevette (NASCC)'`, `'class' => ['current' => null, 'earlierAccepted' => null]`, and a status of `accepted`. Then:

```php
    public function testIceRosterRowShowsTheIceClassAndIceSeason(): void
    {
        // $row / $vm built from this file's existing fixtures, with 'ice_class' set and $vm['discipline'] = 'ice'
        $html = inspectRosterRowHtml($row, $vm);
        $this->assertStringContainsString('CH — Chevette (NASCC)', $html);
        $this->assertStringContainsString('Teched Ice 2027', $html);
    }
```

Fill in `$row` and `$vm` from the existing fixture pattern in that file: `$vm` needs `season` 2027, `csrf`, `filter` and `'discipline' => 'ice'`. Don't leave the comment in place.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter "InspectLibTest|InspectPageTest"` → FAIL.

- [ ] **Step 3: Implement it**

`inspect-lib.php` `inspectRosterRows()`:
- Add the trailing parameter `array $key = ['discipline' => 'summer', 'club' => null]`.
- Compute `$isIce = ($key['discipline'] ?? 'summer') === 'ice';`.
- For ice, use only the sheets of the key's club when grouping: `$groups = techGroupSheetsByCar($isIce ? array_values(array_filter($seasonSheets, fn(array $s): bool => ($s['club'] ?? null) === $key['club'])) : $seasonSheets);`.
- The status lookup becomes `techCarStatus($groups[techCarKey(['car_id' => $cid, 'season' => $season, 'discipline' => $key['discipline'], 'club' => $key['club']])] ?? [])`.
- In the self-driver fallback `gearLinksForSheet([...])` array, add `'discipline' => $key['discipline'], 'club' => $key['club']`.
- Add `'ice_class' => ($isIce && $sheet !== null) ? techSheetClassLine($sheet) : ''` to each row.
- Add `require_once __DIR__ . '/ice-sheet-lib.php';` at the top if `techSheetClassLine` isn't already loaded there.

`inspect.php` `inspectShowRoster()`:
- Replace `$season = techSeasonFromDate($event['event_date'] ?? null);` with:

```php
    $key = seasonForEvent($event);
    $season = $key['season'];
```

- Pass `db_get_season_sheets($pdo, $season, $key['discipline'])` and `db_get_gear_records_for_season($pdo, $season, $key['discipline'])`.
- Append `$key` as the last argument of `inspectRosterRows(...)`.
- Add `'discipline' => $key['discipline']` to the `renderInspectRosterHtml([...])` array.

`inspect-page.php` `inspectRosterRowHtml()`:
- Right after `$classCell` is computed, add:

```php
    if (($row['ice_class'] ?? '') !== '') $classCell = '<p>' . h($row['ice_class']) . '</p>';
```

- Change the car tech label to `techCarStatusLabel($row['status'], $vm['season'], (string)($vm['discipline'] ?? 'summer'))`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar --filter "InspectLibTest|InspectPageTest"` → PASS.
Run: `php -l inspect.php` → clean.
Run: `php phpunit.phar` → the full suite PASSES.

- [ ] **Step 5: Manual check (for the human partner; the implementer only runs `php -l`)**

1. Create an ice event (Admin, Events), then open a car's Garage page. The "Ice racing" section lists it with "Submit ice tech sheet".
2. Fill in the ice form. Switch the class between SS and LS: the checklist changes and the helmet note updates. Submit.
3. As an inspector, open the ice event's roster:
   - the car shows the ice class;
   - accept the sheet;
   - use "Create and accept gear in person" and pick a level;
   - the chip reads "Gear teched Ice 2027 · caged".

- [ ] **Step 6: Commit**

```bash
git add inspect-lib.php inspect.php inspect-page.php tests/InspectLibTest.php tests/InspectPageTest.php
git commit -m "feat(ice): inspector roster for ice events uses ice sheets, classes and gear"
```

---

## Self-review notes

**Spec coverage (§3):**

| Spec item | Task |
|---|---|
| Entry from the Garage (Home entry is Phase 4) | T6 |
| Class dropdown per club with the note | T5 |
| Checklist from group plus club wording, re-rendered on change | T4, T5 |
| Validation against the posted class | T2 |
| Equipment helmet note | T1, T5 |
| Single driver | T5 |
| Passengers out of scope | not built |
| Review, acceptance, email and print showing club, class and "Ice {season}" | T3, T5 (acceptance reuses the existing in-person flow) |
| Gear level on acceptance | T7 |
| Status key per club | T8 (roster); Phase 1 (DB) |
| Photo pre-tech | Phase 3; turned off for ice here (T5) |

**Carried from Phase 1:**

| Item | Task |
|---|---|
| Checklist gaps | T1 |
| Discipline-aware user listings (`gearLinksForSheet`, readiness sheets, Garage status) | T6, T7 |
| Inspect roster | T8 |
| Host club select visibility, badge class | still deferred (cosmetic) |

**Deliberate deviations from the spec text:**
- **Ice equipment relaxes items.** Goggles/visor, socks and balaclava are recommended rather than required on ice, and a head & neck restraint is required only for FHR classes. That follows both clubs' regs. The spec said "same items".
- **The event is fixed per form.** Other ice events are links, not a dropdown, because the class list depends on the event's club.
- **Ice sheet emails go to the account holder's email.** An ice sheet has no declaration to take the address from.
