# Ice Racing Phase 3: Ice Photo Pre-tech Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Competitors can pre-tech an **ice car** and **ice gear** by photo, the same way summer works:

- The car photo list follows the sheet's class group (drift / street-safe / caged), with each club's wording.
- The gear photo list is for ice gear.
- The helmet standard picked on the helmet photo suggests the gear level. The inspector confirms that level when accepting the photos.

**Architecture:** Ice photo requirements sit beside the summer list in `photo-requirements.php`, keyed with an `ice_` prefix. The prefix means a key alone still identifies a requirement for labels, emails and review cards. A new subject-aware lookup, `photoRequirementsFor($subject, $scope)`, returns the list that applies to a given tech sheet or gear record. Every place that validates or lists photos switches to it:

- upload, "applies" and typed-detail edits (`inspection-lib.php`)
- the snapshots
- the two pre-tech pages

The Phase 2 "not yet available for ice" refusals are removed. Photo acceptance of ice gear takes a level.

**Tech Stack:** PHP 8.3, SQLite (PDO), PHPUnit 10 (`php phpunit.phar`), vanilla JS.

**Spec:** `docs/superpowers/specs/2026-09-27-ice-racing-design.md`. Read §1 "Photo requirements" (the shot table, gear shots, helmet → level) and §3 "Photo pre-tech".

What Phases 1–2 left in place (merged, d4f41de):

- **Rules and sheet helpers:**
  - `ice-rules.php`: `iceClass()`, `ICE_GEAR_LEVEL_LABELS`
  - `ice-sheet-lib.php`: `techSheetIsIce()`
  - `tech-status.php`: `DISCIPLINE_*`, `gearSeasonNow('ice')`
- **Tech sheets:** have `discipline`, `club` and `class`.
- **Gear records:** have `discipline` and `level`.
- **`gearAcceptInPerson(..., $level)`** already requires a level for ice records.
- **Refusals this plan removes:**
  - tech-sheets `handlePretech` and `handlePretechSubmit`, plus the hidden "Get pre-teched" button in `handleView`
  - gear.php `handleGearPretech` and `handleGearPretechSubmit`
  - admin-gear `handleGearAdminPhotosAccept`
  - the owner ice chips in gear-chips.php, which have no pre-tech link

### Roadmap

| Phase | Delivers |
|---|---|
| 1 — Foundations | done |
| 2 — Ice tech sheet | done |
| **3 — Ice photo pre-tech (this plan)** | Ice car and gear photo lists, ice pre-tech pages, gear level on photo acceptance, ice badges in the review queue |
| 4 — Readiness and both seasons | Ice readiness/Home/reminders/tagging, summer gear carry-over, media, Garage card chip, admin Gear tab for ice |

## Global Constraints

- All paths are relative to `wcma-calculator/` unless they start with `docs/`.
- PHP tests: run `php phpunit.phar` from `wcma-calculator/`. JS tests: `node --test tests/js/*.test.js` (the directory form fails on Windows).
- Baseline: 811 PHP tests and 42 JS tests. All must pass at the end of every task.
- No new dependencies and no build step.
- **Includes:** always use `require_once` for app files. `tests/RequireOnceGuardTest.php` enforces this.
- **Pages that can't run under PHPUnit** (`tech-sheets.php`, `gear.php`, `inspect.php`, `admin-gear.php`, `admin-tech-sheets.php` need config.php): cover them with source-level tests (the `body()` helper pattern in `tests/TechSheetsHandlersTest.php`), and run `php -l` on them.
- **Terminology:** use *reviewed*, *accepted* and *pre-teched*. Never use *approved*, *passed* or *safe* in UI copy. The "Street Safe" class name and the "street-safe" level label are exempt. `tests/AdminGearCopyTest.php` bans `\bsafe\b` in `admin-gear.php` source, so write "uncaged" there.
- **Summer is unchanged:**
  - The summer photo lists, keys and `PHOTO_REQUIREMENTS_VERSION` stay the same.
  - Summer pre-tech pages, review and emails behave exactly as today.
  - `photoRequirementByKey()` still returns summer requirements unchanged.
- **Ice photo keys:**
  - All start with `ice_`.
  - They are stored with `requirement_version = ICE_PHOTO_REQUIREMENTS_VERSION`.
  - An ice subject only accepts ice keys for its own list, and a summer subject only accepts summer keys.
- **Ice gear levels are exactly `street_safe` | `caged`.** Photo acceptance of an ice gear record requires one, the same as in-person acceptance.
- **Branch and commits:**
  - Work on a branch named `ice-phase3`, not `main`.
  - Commit at the end of every task.
  - Every commit message ends with:
    ```
    Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
    Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5
    ```

## Review Focus

1. **Uploads with the wrong key for the subject.**
   - An ice sheet posting a summer key (`front_34`) or another group's key (`ice_cage` on an SS sheet) is rejected.
   - A summer sheet posting `ice_front_34` is rejected.
   - Test: Task 2, `testSaveRejectsKeysOutsideTheSubjectsList`.
2. **A class changes after photos are uploaded** (SS → LS).
   - Photos for shots no longer on the list stay in the database.
   - They don't count toward "missing", and they don't block submit.
   - Test: Task 2, `testIceSnapshotUsesTheSheetsGroupAndIgnoresOffListPhotos`.
3. **An inspector accepts ice gear photos with no level chosen.**
   - The acceptance is refused and the record stays submitted.
   - The level the inspector confirms is stored even if it differs from the helmet suggestion.
   - Test: Task 5, `testAcceptingIceGearPhotosNeedsALevel`.
4. **An owner with no ice gear record opens ice gear photos from an ice sheet.**
   - The record is created for the sheet's driver and ice season, under the sheet owner.
   - Only the owner can do this, and only for their own ice sheet.
   - Test: Task 4, `testStartIceForSheetCreatesOrReusesTheOwnersRecord`.
5. **The summer pre-tech flow is unaffected.** The existing PretechLibTest, GearLibTest, GearPageTest, PretechPageTest, InspectionLibTest and PhotoRequirementsTest pass unchanged. Every task runs the full suite.

---

### Task 1: Ice photo requirements and the subject-aware lookup

**Files:**
- Modify: `photo-requirements.php`
- Test: `tests/IcePhotoRequirementsTest.php` (create)

**Interfaces:**
- Consumes: `iceClass()` and `ICE_CLUBS` (ice-rules.php); `DISCIPLINE_ICE` (tech-status.php).
- Produces:
  - `ICE_PHOTO_REQUIREMENTS_VERSION = 1`
  - `ICE_HELMET_STANDARDS`
  - `ICE_PHOTO_REQUIREMENTS`: key ⇒ `['scope','tier','groups'(car only),'typed','label','guidance','club_guidance'?(club ⇒ text),'applies_label'?]`
  - `photoRequirementByKey(string $key): ?array` — also finds `ice_` keys; summer entries unchanged
  - `photoRequirementsFor(array $subject, string $scope): array` — key ⇒ def, with `guidance` resolved for the club and `version` added
    - summer (or an empty subject) ⇒ the summer list
    - ice tech sheet ⇒ the car shots for its class group
    - ice gear record ⇒ the ice gear shots
  - `photoRequirementForSubject(array $subject, string $scope, string $key): ?array` — the def with `key` added, or null if the key is not on that subject's list
  - `photoSetMissingFrom(array $requirements, array $presentKeys, array $applicableConditionalKeys): array`
  - `photoSetMissingRequired(string $scope, …)` keeps its signature and delegates to `photoSetMissingFrom(photoRequirements($scope), …)`
  - `iceGearLevelForHelmet(string $standard): ?string`

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/IcePhotoRequirementsTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../photo-requirements.php';

final class IcePhotoRequirementsTest extends TestCase
{
    private function iceSheet(string $club, string $class): array {
        return ['discipline' => 'ice', 'club' => $club, 'class' => $class];
    }

    public function testEveryIceKeyIsPrefixedAndWellFormed(): void
    {
        foreach (ICE_PHOTO_REQUIREMENTS as $key => $def) {
            $this->assertStringStartsWith('ice_', $key);
            $this->assertContains($def['scope'], ['car', 'gear'], $key);
            $this->assertContains($def['tier'], ['required', 'conditional', 'recommended'], $key);
            $this->assertNotSame('', $def['label'], $key);
            $this->assertNotSame('', $def['guidance'], $key);
            if ($def['scope'] === 'car') {
                $this->assertNotEmpty($def['groups'], $key);
                foreach ($def['groups'] as $g) $this->assertContains($g, ICE_CLASS_GROUPS, $key);
            }
            foreach (array_keys($def['club_guidance'] ?? []) as $club) $this->assertContains($club, iceClubCodes(), $key);
        }
        $this->assertSame([], array_intersect_key(ICE_PHOTO_REQUIREMENTS, PHOTO_REQUIREMENTS));
    }

    public function testByKeyFindsSummerAndIceKeys(): void
    {
        $this->assertSame(PHOTO_REQUIREMENTS['front_34'] + ['key' => 'front_34'], photoRequirementByKey('front_34'));
        $this->assertSame('ice_cage', photoRequirementByKey('ice_cage')['key']);
        $this->assertNull(photoRequirementByKey('nope'));
    }

    public function testSummerAndEmptySubjectsGetTheSummerList(): void
    {
        $this->assertSame(array_keys(photoRequirements('car')), array_keys(photoRequirementsFor([], 'car')));
        $this->assertSame(array_keys(photoRequirements('gear')), array_keys(photoRequirementsFor(['discipline' => 'summer'], 'gear')));
        $this->assertSame(PHOTO_REQUIREMENTS_VERSION, photoRequirementsFor([], 'car')['front_34']['version']);
    }

    public function testIceCarListFollowsTheClassGroup(): void
    {
        $drift = array_keys(photoRequirementsFor($this->iceSheet('WSCC', 'DRIFT'), 'car'));
        $ss = array_keys(photoRequirementsFor($this->iceSheet('NASCC', 'SS'), 'car'));
        $caged = array_keys(photoRequirementsFor($this->iceSheet('NASCC', 'LS'), 'car'));
        $this->assertSame(['ice_front_34', 'ice_rear_34', 'ice_tires'], $drift);
        $this->assertContains('ice_airbags', $ss);
        $this->assertNotContains('ice_cage', $ss);
        $this->assertContains('ice_cage', $caged);
        $this->assertContains('ice_mud_flaps', $caged);
        $this->assertNotContains('ice_airbags', $caged);
        $this->assertSame([], photoRequirementsFor($this->iceSheet('NASCC', 'XX'), 'car'));
        $this->assertSame(ICE_PHOTO_REQUIREMENTS_VERSION, photoRequirementsFor($this->iceSheet('NASCC', 'LS'), 'car')['ice_cage']['version']);
    }

    public function testClubGuidanceIsApplied(): void
    {
        $n = photoRequirementsFor($this->iceSheet('NASCC', 'SS'), 'car');
        $w = photoRequirementsFor($this->iceSheet('WSCC', 'FOI-SS'), 'car');
        $this->assertStringContainsString('removed, not just disabled', $n['ice_airbags']['guidance']);
        $this->assertStringContainsString('removed or disabled', $w['ice_airbags']['guidance']);
        $this->assertStringContainsString('all 4', $n['ice_brake_lights']['guidance']);
        $this->assertStringContainsString('all 3', $w['ice_brake_lights']['guidance']);
        $this->assertArrayNotHasKey('club_guidance', $n['ice_airbags']);
    }

    public function testIceGearList(): void
    {
        $gear = photoRequirementsFor(['discipline' => 'ice'], 'gear');
        $this->assertSame(['ice_helmet_label', 'ice_suit_label', 'ice_gloves_shoes', 'ice_fhr_label'], array_keys($gear));
        $this->assertSame('conditional', $gear['ice_fhr_label']['tier']);
        $this->assertSame(ICE_HELMET_STANDARDS, $gear['ice_helmet_label']['typed'][0]['options']);
    }

    public function testForSubjectOnlyReturnsKeysOnThatList(): void
    {
        $ss = $this->iceSheet('NASCC', 'SS');
        $this->assertSame('ice_airbags', photoRequirementForSubject($ss, 'car', 'ice_airbags')['key']);
        $this->assertNull(photoRequirementForSubject($ss, 'car', 'ice_cage'));
        $this->assertNull(photoRequirementForSubject($ss, 'car', 'front_34'));
        $this->assertNull(photoRequirementForSubject([], 'car', 'ice_front_34'));
        $this->assertNull(photoRequirementForSubject(['discipline' => 'ice'], 'car', 'ice_helmet_label'));
    }

    public function testMissingFromAList(): void
    {
        $reqs = photoRequirementsFor(['discipline' => 'ice'], 'gear');
        $this->assertSame(['ice_helmet_label', 'ice_suit_label', 'ice_gloves_shoes'], photoSetMissingFrom($reqs, [], []));
        $this->assertSame(['ice_fhr_label'], photoSetMissingFrom($reqs, ['ice_helmet_label', 'ice_suit_label', 'ice_gloves_shoes'], ['ice_fhr_label']));
        // The summer helper still behaves as before.
        $this->assertSame(photoSetMissingFrom(photoRequirements('gear'), [], []), photoSetMissingRequired('gear', [], []));
    }

    public function testHelmetStandardSuggestsALevel(): void
    {
        $this->assertSame('caged', iceGearLevelForHelmet('Snell SA2020'));
        $this->assertSame('caged', iceGearLevelForHelmet('FIA 8860-2018'));
        $this->assertSame('street_safe', iceGearLevelForHelmet('Snell M2015'));
        $this->assertSame('street_safe', iceGearLevelForHelmet('ECE 22.06'));
        $this->assertNull(iceGearLevelForHelmet(''));
        $this->assertNull(iceGearLevelForHelmet('Bell Bike'));
        foreach (ICE_HELMET_STANDARDS as $s) $this->assertNotNull(iceGearLevelForHelmet($s), $s);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php phpunit.phar --filter IcePhotoRequirementsTest`
Expected: FAIL (undefined constant `ICE_PHOTO_REQUIREMENTS`).

- [ ] **Step 3: Implement it in `photo-requirements.php`**

1. After the header comment, add:

```php
require_once __DIR__ . '/tech-status.php';   // DISCIPLINE_ICE
require_once __DIR__ . '/ice-rules.php';     // iceClass(), ICE_CLASS_GROUPS, iceClubCodes()
```

2. After `PHOTO_REQUIREMENTS`, add:

```php
// ── Ice (2026-09-27 spec §1). Keys are prefixed ice_ so a key alone identifies its requirement. ──
const ICE_PHOTO_REQUIREMENTS_VERSION = 1;

const ICE_HELMET_STANDARDS = [
    'Snell SA2015', 'Snell SA2020', 'Snell SA2025', 'FIA 8860-2010', 'FIA 8859-2015', 'FIA 8860-2018',
    'Snell M2015', 'Snell M2020', 'ECE 22.05', 'ECE 22.06',
];

const ICE_PHOTO_REQUIREMENTS = [
    // ── Car ──
    'ice_front_34' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['drift', 'street_safe', 'caged'], 'typed' => [],
        'label' => 'Front three-quarter view',
        'guidance' => 'Whole front of the car with the car number and the front tow hook visible.',
    ],
    'ice_rear_34' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['drift', 'street_safe', 'caged'], 'typed' => [],
        'label' => 'Rear three-quarter view',
        'guidance' => 'Whole rear of the car with the rear tow hook, the exhaust exit and the rear roof light visible.',
    ],
    'ice_side_driver' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['street_safe', 'caged'], 'typed' => [],
        'label' => "Driver's side",
        'guidance' => 'Full side view showing the car number and class decal.',
    ],
    'ice_side_passenger' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['street_safe', 'caged'], 'typed' => [],
        'label' => "Passenger's side",
        'guidance' => 'Full side view showing the car number and class decal.',
    ],
    'ice_tires' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['drift', 'street_safe', 'caged'], 'typed' => [],
        'label' => 'Tire close-up',
        'guidance' => 'One tire showing tread and sidewall: the snowflake mark and size for studless tires, or the stud pattern and how far the studs stick out for studded tires.',
    ],
    'ice_windshield' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['street_safe', 'caged'], 'typed' => [],
        'label' => 'Windshield',
        'guidance' => 'The whole windshield, so any cracks are visible.',
    ],
    'ice_interior' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['street_safe', 'caged'], 'typed' => [],
        'label' => 'Interior, overall',
        'guidance' => 'Seats with headrests, the belts, and no loose items.',
    ],
    'ice_airbags' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['street_safe'], 'typed' => [],
        'label' => 'Airbags',
        'guidance' => 'The steering wheel and dash where the airbags are.',
        'club_guidance' => [
            'NASCC' => 'The steering wheel and dash showing the airbags removed, not just disabled.',
            'WSCC' => 'The steering wheel and dash showing the airbags removed or disabled.',
        ],
    ],
    'ice_battery' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['street_safe', 'caged'], 'typed' => [],
        'label' => 'Battery',
        'guidance' => 'The battery hold-down and the insulated positive terminal.',
    ],
    'ice_brake_lights' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['street_safe', 'caged'], 'typed' => [],
        'label' => 'Brake lights',
        'guidance' => 'The rear of the car with the brake lights on.',
        'club_guidance' => [
            'NASCC' => 'The rear of the car with all 4 brake lights on (2 on or above the trunk lid).',
            'WSCC' => 'The rear of the car with all 3 brake lights on (1 on or above the trunk lid).',
        ],
    ],
    'ice_cage' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['caged'], 'typed' => [],
        'label' => 'Roll cage, overall',
        'guidance' => 'The whole cage from inside the car, including the roof reinforcement bar.',
    ],
    'ice_seat_harness' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['caged'], 'typed' => [],
        'label' => 'Seat and harness, installed',
        'guidance' => "The driver's seat with the harness installed and its mounting points visible.",
    ],
    'ice_harness_date' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['caged'],
        'typed' => [['name' => 'date', 'label' => 'Date stamp (MM/YYYY)', 'type' => 'month_year']],
        'label' => 'Harness date stamp',
        'guidance' => 'Close-up of the harness label so the date stamp and the standard are readable.',
    ],
    'ice_window_net' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['caged'], 'typed' => [],
        'label' => 'Window net',
        'guidance' => "The driver-side window net installed, showing how it attaches.",
    ],
    'ice_kill_switch' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['caged'], 'typed' => [],
        'label' => 'Kill switch and its marking',
        'guidance' => 'The switch and the marking that identifies it.',
    ],
    'ice_engine_bay' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['caged'], 'typed' => [],
        'label' => 'Engine bay',
        'guidance' => 'The engine bay showing the firewall.',
        'club_guidance' => [
            'NASCC' => 'The engine bay showing the engine and radiator catch tanks and the firewall.',
        ],
    ],
    'ice_mud_flaps' => [
        'scope' => 'car', 'tier' => 'required', 'groups' => ['caged'], 'typed' => [],
        'label' => 'Mud flaps',
        'guidance' => 'The mud flaps behind the driven wheels.',
    ],

    // ── Gear ──
    'ice_helmet_label' => [
        'scope' => 'gear', 'tier' => 'required',
        'typed' => [
            ['name' => 'standard', 'label' => 'Standard', 'type' => 'select', 'options' => ICE_HELMET_STANDARDS],
            ['name' => 'date', 'label' => 'Date (MM/YYYY)', 'type' => 'month_year'],
        ],
        'label' => 'Helmet certification label',
        'guidance' => 'The inside label showing the certification standard and date.',
    ],
    'ice_suit_label' => [
        'scope' => 'gear', 'tier' => 'required',
        'typed' => [['name' => 'rating', 'label' => 'Rating (e.g. SFI 3.2A/1, or FR coveralls)', 'type' => 'text']],
        'label' => 'Suit or coverall label',
        'guidance' => 'The label showing the suit rating, or that the coveralls are fire resistant.',
    ],
    'ice_gloves_shoes' => [
        'scope' => 'gear', 'tier' => 'required', 'typed' => [],
        'label' => 'Gloves and shoes',
        'guidance' => 'The gloves and shoes laid out together in one photo.',
    ],
    'ice_fhr_label' => [
        'scope' => 'gear', 'tier' => 'conditional',
        'typed' => [
            ['name' => 'standard', 'label' => 'Standard', 'type' => 'select', 'options' => PHOTO_FHR_STANDARDS],
            ['name' => 'date', 'label' => 'Date (MM/YYYY)', 'type' => 'month_year'],
        ],
        'label' => 'Frontal head restraint label',
        'guidance' => 'The label on the frontal head restraint showing the standard and date.',
        'applies_label' => 'This driver races a class that needs one (NASCC LS or AWD)',
    ],
];
```

3. Replace `photoRequirementByKey()` and add the new functions after it:

```php
/** One requirement (summer or ice) with its 'key' added, or null if the key is unknown. */
function photoRequirementByKey(string $key): ?array {
    if (isset(PHOTO_REQUIREMENTS[$key])) return PHOTO_REQUIREMENTS[$key] + ['key' => $key];
    if (isset(ICE_PHOTO_REQUIREMENTS[$key])) return ICE_PHOTO_REQUIREMENTS[$key] + ['key' => $key];
    return null;
}

/**
 * The photos that apply to one subject: a tech_sheets row (scope 'car') or a gear_records row
 * (scope 'gear'). Summer subjects (and an empty array) get the summer list. An ice tech sheet gets
 * the car shots for its class group; an ice gear record gets the ice gear shots. Each def gets its
 * club's guidance and the list 'version' it is stored under.
 *
 * @return array<string, array> key => requirement
 */
function photoRequirementsFor(array $subject, string $scope): array {
    if (($subject['discipline'] ?? DISCIPLINE_SUMMER) !== DISCIPLINE_ICE) {
        return array_map(fn(array $r): array => $r + ['version' => PHOTO_REQUIREMENTS_VERSION], photoRequirements($scope));
    }
    $club = (string)($subject['club'] ?? '');
    $group = null;
    if ($scope === 'car') {
        $class = iceClass($club, (string)($subject['class'] ?? ''));
        if ($class === null) return [];
        $group = $class['group'];
    }
    $out = [];
    foreach (ICE_PHOTO_REQUIREMENTS as $key => $def) {
        if ($def['scope'] !== $scope) continue;
        if ($group !== null && !in_array($group, $def['groups'], true)) continue;
        $def['guidance'] = $def['club_guidance'][$club] ?? $def['guidance'];
        unset($def['club_guidance']);
        $out[$key] = $def + ['version' => ICE_PHOTO_REQUIREMENTS_VERSION];
    }
    return $out;
}

/** One requirement on $subject's list, with 'key' added, or null if the key is not on that list. */
function photoRequirementForSubject(array $subject, string $scope, string $key): ?array {
    $list = photoRequirementsFor($subject, $scope);
    return isset($list[$key]) ? $list[$key] + ['key' => $key] : null;
}

/** The ice gear level a helmet standard suggests: SA/FIA → caged, M/ECE → street-safe. Null if unknown. */
function iceGearLevelForHelmet(string $standard): ?string {
    if (preg_match('/^(Snell SA|FIA )/', $standard)) return 'caged';
    if (preg_match('/^(Snell M|ECE )/', $standard)) return 'street_safe';
    return null;
}
```

4. Replace `photoSetMissingRequired()` with the pair:

```php
/**
 * Requirement keys still missing from $requirements for a complete pre-tech set: every 'required'
 * photo, plus any 'conditional' photo marked as applying. 'recommended' photos never count.
 *
 * @return string[]
 */
function photoSetMissingFrom(array $requirements, array $presentKeys, array $applicableConditionalKeys): array {
    $missing = [];
    foreach ($requirements as $key => $def) {
        $needed = $def['tier'] === 'required'
            || ($def['tier'] === 'conditional' && in_array($key, $applicableConditionalKeys, true));
        if ($needed && !in_array($key, $presentKeys, true)) {
            $missing[] = $key;
        }
    }
    return $missing;
}

/** The summer list's missing keys for $scope. Kept for callers that have no subject. */
function photoSetMissingRequired(string $scope, array $presentKeys, array $applicableConditionalKeys): array {
    return photoSetMissingFrom(photoRequirements($scope), $presentKeys, $applicableConditionalKeys);
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar --filter "IcePhotoRequirementsTest|PhotoRequirementsTest"` → PASS.
Run: `php phpunit.phar` → the full suite PASSES.

- [ ] **Step 5: Commit**

```bash
git add photo-requirements.php tests/IcePhotoRequirementsTest.php
git commit -m "feat(ice): ice car and gear photo requirements with a subject-aware lookup"
```

---

### Task 2: Uploads, "applies", typed details and snapshots follow the subject's list

**Files:**
- Modify: `inspection-lib.php` (`inspectionSavePhoto`, `inspectionSetApplies`, `inspectionUpdateTyped`; add `inspectionSubjectRow`)
- Modify: `pretech-lib.php` (`pretechSnapshot`)
- Modify: `gear-lib.php` (`gearSnapshot`)
- Test: `tests/InspectionLibTest.php` (add), `tests/PretechLibTest.php` (add), `tests/GearLibTest.php` (add)

**Interfaces:**
- Consumes: Task 1's `photoRequirementsFor`, `photoRequirementForSubject`, `photoSetMissingFrom`.
- Produces:
  - `inspectionSubjectRow(PDO $pdo, string $subjectType, int $subjectId): array` — the tech sheet or gear row, or `[]` when it doesn't exist (so existing tests that use made-up ids keep the summer list)
  - uploads, "applies" and typed edits reject a key that isn't on the subject's list with `Unknown photo type.`
  - photos are stored with the requirement's `version`
  - `pretechSnapshot()` / `gearSnapshot()` compute `missing` and `applicable` from the subject's list only

- [ ] **Step 1: Write the failing tests**

Add to `tests/InspectionLibTest.php` (inside the class; it has `tmpFile()`, `jpeg()` and `$this->dir`):

```php
    private function iceSheet(PDO $pdo, string $class = 'SS'): int {
        $u = db_create_user($pdo, ['email' => 'i' . uniqid() . '@example.com', 'name' => 'Ice', 'password_hash' => 'x', 'google_id' => null]);
        $car = test_make_car($pdo, $u, '7');
        $event = db_create_event($pdo, 'NASCC Ice', '2026-12-12', null, 'ice', 'NASCC');
        return test_make_ice_sheet($pdo, $u, $car, $event, $class);
    }

    public function testSaveRejectsKeysOutsideTheSubjectsList(): void
    {
        $pdo = make_temp_pdo();
        $ss = $this->iceSheet($pdo, 'SS');
        foreach (['front_34', 'ice_cage', 'ice_helmet_label'] as $key) {
            $r = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', $ss, $key, $this->tmpFile($this->jpeg()), [], 'rename');
            $this->assertFalse($r['ok'], $key);
            $this->assertSame('Unknown photo type.', $r['error'], $key);
        }
        $ok = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', $ss, 'ice_airbags', $this->tmpFile($this->jpeg()), [], 'rename');
        $this->assertTrue($ok['ok'], (string)$ok['error']);
        $this->assertSame(ICE_PHOTO_REQUIREMENTS_VERSION, (int)$ok['photo']['requirement_version']);

        // A summer (or unknown) subject still refuses ice keys.
        $this->assertFalse(inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 999, 'ice_front_34', $this->tmpFile($this->jpeg()), [], 'rename')['ok']);
    }

    public function testIceGearAppliesAndTypedUseTheIceList(): void
    {
        $pdo = make_temp_pdo();
        $owner = db_create_user($pdo, ['email' => 'g' . uniqid() . '@example.com', 'name' => 'O', 'password_hash' => 'x', 'google_id' => null]);
        $gearId = (int)gearCreate($pdo, $owner, 'Sam', '', 2027, 'ice')['id'];
        $this->assertTrue(inspectionSetApplies($pdo, $this->dir, 'gear_record', $gearId, 'ice_fhr_label', true)['ok']);
        $this->assertFalse(inspectionSetApplies($pdo, $this->dir, 'gear_record', $gearId, 'fhr_label', true)['ok']);

        $r = inspectionSavePhoto($pdo, $this->dir, 'gear_record', $gearId, 'ice_helmet_label', $this->tmpFile($this->jpeg()),
            ['standard' => 'Snell M2015', 'date' => '03/2020'], 'rename');
        $this->assertTrue($r['ok'], (string)$r['error']);
        $bad = inspectionSavePhoto($pdo, $this->dir, 'gear_record', $gearId, 'ice_helmet_label', $this->tmpFile($this->jpeg()),
            ['standard' => 'Bell Bike'], 'rename');
        $this->assertFalse($bad['ok']);
        $this->assertTrue(inspectionUpdateTyped($pdo, (int)$r['photo']['id'], ['standard' => 'Snell SA2020'])['ok']);
    }
```

Add `require_once __DIR__ . '/../gear-lib.php';` to the top of `InspectionLibTest.php` if it's missing.

Add to `tests/PretechLibTest.php` (inside the class):

```php
    public function testIceSnapshotUsesTheSheetsGroupAndIgnoresOffListPhotos(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'p' . uniqid() . '@example.com', 'name' => 'Ice', 'password_hash' => 'x', 'google_id' => null]);
        $car = test_make_car($pdo, $u, '7');
        $event = db_create_event($pdo, 'NASCC Ice', '2026-12-12', null, 'ice', 'NASCC');
        $sheetId = test_make_ice_sheet($pdo, $u, $car, $event, 'LS');
        $this->addPhoto($pdo, $sheetId, 'ice_cage');

        $snap = pretechSnapshot($pdo, $sheetId);
        $this->assertNotContains('ice_cage', $snap['missing']);
        $this->assertContains('ice_mud_flaps', $snap['missing']);
        $this->assertNotContains('front_34', $snap['missing']);

        // The class changes to SS: the cage photo stays but is no longer on the list or missing.
        $pdo->prepare("UPDATE tech_sheets SET class = 'SS' WHERE id = :id")->execute([':id' => $sheetId]);
        $snap = pretechSnapshot($pdo, $sheetId);
        $this->assertArrayHasKey('ice_cage', $snap['photos']);
        $this->assertNotContains('ice_cage', $snap['missing']);
        $this->assertContains('ice_airbags', $snap['missing']);
    }
```

Add to `tests/GearLibTest.php` (inside the class; reuse its `owner()` helper):

```php
    public function testIceGearSnapshotUsesTheIceList(): void
    {
        $pdo = make_temp_pdo();
        $id = (int)gearCreate($pdo, $this->owner($pdo), 'Sam', '', 2027, 'ice')['id'];
        $this->assertSame(['ice_helmet_label', 'ice_suit_label', 'ice_gloves_shoes'], gearSnapshot($pdo, $id)['missing']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter "InspectionLibTest|PretechLibTest|GearLibTest"` → the new tests FAIL.

- [ ] **Step 3: Implement it**

`inspection-lib.php`:
- Add `require_once __DIR__ . '/photo-requirements.php';` if it's not already there.
- Add:

```php
/** The row a photo belongs to (a tech sheet or gear record), or [] if it doesn't exist (treated as summer). */
function inspectionSubjectRow(PDO $pdo, string $subjectType, int $subjectId): array {
    $row = match ($subjectType) {
        'tech_sheet' => db_get_tech_sheet($pdo, $subjectId),
        'gear_record' => db_get_gear_record($pdo, $subjectId),
        default => null,
    };
    return $row ?? [];
}
```

In `inspectionSavePhoto()`, replace:

```php
    $requirement = photoRequirementByKey($requirementKey);
    if ($requirement === null || $requirement['scope'] !== INSPECTION_SUBJECT_SCOPE[$subjectType]) {
        return $fail('Unknown photo type.');
    }
```

with:

```php
    $scope = INSPECTION_SUBJECT_SCOPE[$subjectType];
    $requirement = photoRequirementForSubject(inspectionSubjectRow($pdo, $subjectType, $subjectId), $scope, $requirementKey);
    if ($requirement === null) return $fail('Unknown photo type.');
```

and change `'requirement_version' => PHOTO_REQUIREMENTS_VERSION,` to `'requirement_version' => (int)$requirement['version'],`.

In `inspectionSetApplies()`:
- Make the same replacement for the requirement lookup.
- Pass `(int)$requirement['version']` instead of `PHOTO_REQUIREMENTS_VERSION` to `db_set_conditional_photo_applies(...)`.

In `inspectionUpdateTyped()`, replace `$requirement = photoRequirementByKey($photo['requirement_key']);` with:

```php
    $requirement = photoRequirementForSubject(
        inspectionSubjectRow($pdo, (string)$photo['subject_type'], (int)$photo['subject_id']),
        INSPECTION_SUBJECT_SCOPE[$photo['subject_type']] ?? '', (string)$photo['requirement_key']
    );
```

`pretech-lib.php` `pretechSnapshot()`:
- Before the loop, add `$requirements = photoRequirementsFor(db_get_tech_sheet($pdo, $sheetId) ?? [], 'car');`.
- In the loop, replace `$req = photoRequirementByKey($key);` with `$req = $requirements[$key] ?? null;`.
- Replace `'missing' => photoSetMissingRequired('car', $present, $applicable),` with `'missing' => photoSetMissingFrom($requirements, $present, $applicable),`.

`gear-lib.php` `gearSnapshot()`: make the same three changes, using `photoRequirementsFor(db_get_gear_record($pdo, $id) ?? [], 'gear')`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar --filter "InspectionLibTest|PretechLibTest|GearLibTest|InspectionEndpointTest"` → PASS.
Run: `php phpunit.phar` → the full suite PASSES. The existing summer tests must pass unchanged.

- [ ] **Step 5: Commit**

```bash
git add inspection-lib.php pretech-lib.php gear-lib.php tests/InspectionLibTest.php tests/PretechLibTest.php tests/GearLibTest.php
git commit -m "feat(ice): photo uploads and snapshots follow each sheet's and gear record's own list"
```

---

### Task 3: Turn on car photo pre-tech for ice sheets

**Files:**
- Modify: `pretech-page.php` (`renderPretechPage`, `pretechRenderCard`)
- Modify: `tech-sheets.php` (`handleView`, `handlePretech`, `handlePretechSubmit`)
- Test: `tests/PretechPageTest.php` (add), `tests/TechSheetsHandlersTest.php` (update)

**Interfaces:**
- Consumes: `photoRequirementsFor()`; `techCarStatusLabel()`-style season text.
- Produces:
  - the car pre-tech page lists the sheet's own requirements
  - a requirement's `applies_label` overrides the card's default toggle label
  - the "already teched" line says `Ice {season}` for ice
  - ice sheets can open and submit pre-tech

- [ ] **Step 1: Write the failing tests**

Add to `tests/PretechPageTest.php` (inside the class). First read how its existing tests call `renderPretechPage` (captured with output buffering) and build `$sheet`, `$mode` and `$snapshot`. Then add a test that does two things:
1. Renders the page for an ice sheet (`discipline` `ice`, `club` `NASCC`, `class` `LS`, `season` 2027, mode `this_sheet`, empty snapshot).
2. Renders it again with mode `car_accepted`.

Assert:

```php
        $this->assertStringContainsString('data-key="ice_cage"', $html);
        $this->assertStringNotContainsString('data-key="front_34"', $html);
        $this->assertStringNotContainsString('data-key="ice_airbags"', $html);
        // ... and for mode car_accepted:
        $this->assertStringContainsString('already teched for Ice 2027', $accepted);
```

Add a card test:

```php
    public function testCardUsesTheRequirementsOwnAppliesLabel(): void
    {
        $req = photoRequirementByKey('ice_fhr_label');
        $this->assertStringContainsString('NASCC LS or AWD', pretechRenderCard('ice_fhr_label', $req, null, false, false, 'This applies to this driver'));
    }
```

In `tests/TechSheetsHandlersTest.php`, replace `testPretechIsOffForIceSheets` with:

```php
    public function testPretechIsOnForIceSheets(): void
    {
        foreach (['handlePretech', 'handlePretechSubmit'] as $fn) {
            $this->assertStringNotContainsString("isn't available yet", $this->body($fn), $fn);
        }
        $this->assertStringNotContainsString('!techSheetIsIce($sheet)', $this->body('handleView'));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter "PretechPageTest|TechSheetsHandlersTest"` → FAIL.

- [ ] **Step 3: Implement it**

`pretech-page.php`:
- In `renderPretechPage()`, replace `$requirements = photoRequirements('car');` with `$requirements = photoRequirementsFor($sheet, 'car');`.
- In the `car_accepted` paragraph, replace `teched for <?= (int)($sheet['season'] ?? date('Y')) ?>` with `teched for <?= h((($sheet['discipline'] ?? 'summer') === 'ice' ? 'Ice ' : '') . (int)($sheet['season'] ?? date('Y'))) ?>`.
- In `pretechRenderCard()`, directly after the `$isRetake = ...` line, add `$appliesLabel = (string)($req['applies_label'] ?? $appliesLabel);`.
- Make sure `pretech-page.php` `require_once`s `photo-requirements.php` if its callers don't guarantee it.

`tech-sheets.php`:
- Delete the `if (techSheetIsIce($sheet)) { ... isn't available yet ... }` blocks in `handlePretech` and `handlePretechSubmit`.
- In `handleView`, remove `&& !techSheetIsIce($sheet)` from the pretech button condition.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar --filter "PretechPageTest|TechSheetsHandlersTest"` → PASS.
Run: `php -l tech-sheets.php` → no syntax errors.
Run: `php phpunit.phar` → the full suite PASSES.

- [ ] **Step 5: Commit**

```bash
git add pretech-page.php tech-sheets.php tests/PretechPageTest.php tests/TechSheetsHandlersTest.php
git commit -m "feat(ice): car photo pre-tech for ice sheets"
```

---

### Task 4: Turn on gear photo pre-tech for ice gear

**Files:**
- Modify: `gear-lib.php` (add `gearStartIceForSheet`)
- Modify: `gear.php` (route `start-ice`; remove the ice refusals in `handleGearPretech` / `handleGearPretechSubmit`)
- Modify: `gear-page.php` (`renderGearPretechPage`)
- Modify: `gear-chips.php` (owner ice chips)
- Modify: `tech-sheets.php` (`handleView` passes `sheet_id` to the owner chips)
- Test: `tests/GearLibTest.php` (add), `tests/GearPageTest.php` (add), `tests/GearChipsTest.php` (update), `tests/GearStartSourceTest.php` (add)

**Interfaces:**
- Consumes: `photoRequirementsFor()`, `gearCreate(..., 'ice')`, `db_find_gear_record(..., 'ice')`, `gearSeasonNow('ice')`.
- Produces:
  - `gearStartIceForSheet(PDO $pdo, array $sheet, int $ownerId): array{ok: bool, error: ?string, id: ?int}` — finds or creates the ice gear record for the sheet's driver in the sheet's season, under the owner. Refuses a sheet that isn't the owner's, isn't ice, or isn't in the current ice season.
  - route `gear.php?action=start-ice&sheet_id=N`, which redirects to `gear.php?action=pretech&id=…`
  - the gear pre-tech page lists the record's own requirements and shows `Ice {season}`
  - owner ice chips:
    - no record: `No gear record` + `<a href="gear.php?action=start-ice&amp;sheet_id=N">Add gear photos</a> or have it checked at the track.`
    - a record: a link to `gear.php?action=pretech&id=…`, like summer

- [ ] **Step 1: Write the failing tests**

Add to `tests/GearLibTest.php`:

```php
    public function testStartIceForSheetCreatesOrReusesTheOwnersRecord(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->owner($pdo);
        $car = test_make_car($pdo, $u, '7');
        $event = db_create_event($pdo, 'NASCC Ice', date('Y-m-d', strtotime('+10 days')), null, 'ice', 'NASCC');
        $sheet = db_get_tech_sheet($pdo, test_make_ice_sheet($pdo, $u, $car, $event, 'SS'));

        $r = gearStartIceForSheet($pdo, $sheet, $u);
        $this->assertTrue($r['ok'], (string)$r['error']);
        $g = db_get_gear_record($pdo, (int)$r['id']);
        $this->assertSame('ice', $g['discipline']);
        $this->assertSame((int)$sheet['season'], (int)$g['season']);
        $this->assertSame('Test Driver', $g['driver_name']);
        $this->assertSame($r['id'], gearStartIceForSheet($pdo, $sheet, $u)['id']);   // reused, not duplicated

        $this->assertFalse(gearStartIceForSheet($pdo, $sheet, $this->owner($pdo))['ok']);   // not the owner
        $summer = ['discipline' => 'summer'] + $sheet;
        $this->assertFalse(gearStartIceForSheet($pdo, $summer, $u)['ok']);
        $old = ['season' => gearSeasonNow('ice') - 1] + $sheet;
        $this->assertFalse(gearStartIceForSheet($pdo, $old, $u)['ok']);
    }
```

The date note from Phase 2 applies here too: this test fails in the ten days before 1 July, when the event rolls into the next ice season. Add a one-line comment saying so.

Add to `tests/GearPageTest.php` (read its existing page test first and reuse how it renders with output buffering). Render the page for an ice gear record (`['id' => 3, 'discipline' => 'ice', 'season' => 2027, 'driver_name' => 'Sam', 'status' => 'open', 'photo_status' => null]`) and an empty snapshot. Assert:
- it contains `data-key="ice_helmet_label"`;
- it doesn't contain `data-key="helmet_label"`;
- it contains `Sam — Ice 2027`.

In `tests/GearChipsTest.php`, update the Phase 2 owner ice tests to match the new behaviour:

```php
    public function testOwnerIceChipsOfferIceGearPhotos(): void
    {
        $none = ['driver_number' => 1, 'name' => 'Sam', 'name_norm' => 'sam', 'discipline' => 'ice', 'default_level' => 'caged',
                 'gear' => null, 'status' => ['state' => 'none', 'via' => null]];
        $html = renderGearChips([$none], 'owner', ['sheet_id' => 9, 'sheet_season' => gearSeasonNow('ice')]);
        $this->assertStringContainsString('href="gear.php?action=start-ice&amp;sheet_id=9">Add gear photos</a> or have it checked at the track.', $html);
        $this->assertStringNotContainsString('drivers.php', $html);

        $has = ['gear' => ['id' => 4, 'season' => 2027, 'discipline' => 'ice', 'level' => null],
                'status' => ['state' => 'photos_draft', 'via' => null]] + $none;
        $this->assertStringContainsString('href="gear.php?action=pretech&amp;id=4"', renderGearChips([$has], 'owner'));
    }
```

Delete the Phase 2 tests this replaces: the ones asserting `Ice gear is checked at the track.` and no `gear.php?action=pretech` link for owner ice chips.

Add to `tests/GearStartSourceTest.php` (it reads `gear.php`; follow its pattern):

```php
    public function testStartIceRouteUsesTheSheetOwnerHelper(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../gear.php'));
        $this->assertStringContainsString("case 'start-ice':", $src);
        $this->assertStringContainsString('db_get_user_tech_sheet(', $src);
        $this->assertStringContainsString('gearStartIceForSheet(', $src);
        $this->assertStringNotContainsString("isn't available yet", $src);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter "GearLibTest|GearPageTest|GearChipsTest|GearStartSourceTest"` → FAIL.

- [ ] **Step 3: Implement it**

Append to `gear-lib.php`:

```php
/**
 * Opens ice gear photos from an ice tech sheet: finds or creates the ice gear record for the sheet's
 * driver, in the sheet's (current) ice season, under the sheet owner.
 *
 * @return array{ok: bool, error: ?string, id: ?int}
 */
function gearStartIceForSheet(PDO $pdo, array $sheet, int $ownerId): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'id' => null];
    if ((int)($sheet['user_id'] ?? 0) !== $ownerId) return $fail('Tech sheet not found.');
    if (($sheet['discipline'] ?? DISCIPLINE_SUMMER) !== DISCIPLINE_ICE) return $fail('That is not an ice tech sheet.');
    $season = (int)($sheet['season'] ?? 0);
    if ($season !== gearSeasonNow(DISCIPLINE_ICE)) return $fail('Gear photos can only be added for the current ice season.');
    $name = trim((string)preg_replace('/\s+/', ' ', (string)($sheet['driver_name'] ?? '')));
    if ($name === '') return $fail('This tech sheet has no driver.');

    $existing = db_find_gear_record($pdo, $ownerId, gearNameNorm($name), $season, DISCIPLINE_ICE);
    if ($existing !== null) return ['ok' => true, 'error' => null, 'id' => (int)$existing['id']];
    $created = gearCreate($pdo, $ownerId, $name, '', $season, DISCIPLINE_ICE);
    return $created['ok'] ? ['ok' => true, 'error' => null, 'id' => (int)$created['id']] : $fail((string)$created['error']);
}
```

`gear.php`:
- Delete the two `if (... === DISCIPLINE_ICE) { ... isn't available yet ... }` blocks.
- Add a route after `'start'`:

```php
    case 'start-ice':
        $user = requireGearLogin();
        handleGearStartIce($pdo, $user, (int)($_GET['sheet_id'] ?? 0));
        break;
```

- Add the handler:

```php
/** Opens ice gear photos for the driver on one of the user's ice tech sheets. */
function handleGearStartIce(PDO $pdo, array $user, int $sheetId): void {
    $sheet = db_get_user_tech_sheet($pdo, (int)$user['id'], $sheetId);
    $r = $sheet === null ? ['ok' => false, 'error' => 'Tech sheet not found.'] : gearStartIceForSheet($pdo, $sheet, (int)$user['id']);
    if (!$r['ok']) {
        setFlash((string)$r['error'], 'error');
        header('Location: ' . ($sheet === null ? 'garage.php' : 'tech-sheets.php?action=view&id=' . $sheetId));
        exit;
    }
    header('Location: gear.php?action=pretech&id=' . (int)$r['id']);
    exit;
}
```

`gear-page.php` `renderGearPretechPage()`:
- Replace `$requirements = photoRequirements('gear');` with `$requirements = photoRequirementsFor($gear, 'gear');`.
- Replace `$driverLine = $gear['driver_name'] . ' — ' . (int)$gear['season'];` with `$driverLine = $gear['driver_name'] . ' — ' . ((($gear['discipline'] ?? 'summer') === 'ice') ? 'Ice ' : '') . (int)$gear['season'];`.
- Change the accepted paragraph to `teched for <?= h((($gear['discipline'] ?? 'summer') === 'ice' ? 'Ice ' : '') . (int)$gear['season']) ?>`.

`gear-chips.php` `renderGearChips()`, owner audience, ice links:
- Replace the Phase 2 no-record branch (`Ice gear is checked at the track.`) with:

```php
            if ($isIce && $audience === 'owner') {
                $sheetIdOpt = (int)($opts['sheet_id'] ?? 0);
                $html .= '<li class="gear-chip">' . $name . ': <span class="badge-pending">No gear record</span>'
                    . ($seasonOk && $sheetIdOpt > 0
                        ? ' <a href="gear.php?action=start-ice&amp;sheet_id=' . $sheetIdOpt . '">Add gear photos</a> or have it checked at the track.'
                        : ' Gear is checked at the track.')
                    . '</li>';
                continue;
            }
```

- Delete the Phase 2 branch that renders owner ice records as plain text (`if ($isIce && $audience === 'owner') { ... <span ... continue; }` after the label). Owner ice records then fall through to the normal `gear.php?action=pretech` link.
- Update the docblock: `sheet_id` is now also used for the owner audience.

In `tech-sheets.php` `handleView`, add `'sheet_id' => (int)$sheet['id']` to the `renderGearChips($gearLinks, 'owner', [...])` options.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar --filter "GearLibTest|GearPageTest|GearChipsTest|GearStartSourceTest|TechSheetsHandlersTest"` → PASS.
Run: `php -l gear.php && php -l tech-sheets.php` → no syntax errors.
Run: `php phpunit.phar` → the full suite PASSES.

- [ ] **Step 5: Commit**

```bash
git add gear-lib.php gear.php gear-page.php gear-chips.php tech-sheets.php tests/GearLibTest.php tests/GearPageTest.php tests/GearChipsTest.php tests/GearStartSourceTest.php
git commit -m "feat(ice): gear photo pre-tech for ice gear, opened from the ice tech sheet"
```

---

### Task 5: Accepting ice gear photos records the level

**Files:**
- Modify: `gear-lib.php` (`gearAcceptByPhotos`; add `gearSuggestedLevel`)
- Modify: `admin-gear.php` (`handleGearAdminPhotosAccept`, `renderGearReviewCard`)
- Test: `tests/GearLibTest.php` (add), `tests/AdminGearCopyTest.php` (add)

**Interfaces:**
- Consumes: `iceGearLevelForHelmet()` (Task 1); `db_set_gear_level()`; `ICE_GEAR_LEVEL_LABELS`.
- Produces:
  - `gearAcceptByPhotos(PDO $pdo, int $id, int $reviewerUserId, ?string $level = null): array` — ice records need `street_safe|caged`, and the level is written in the same transaction; summer records ignore `$level`
  - `gearSuggestedLevel(array $snapshot): ?string` — from the `ice_helmet_label` photo's typed `standard`
  - the inspector's accept-photos form on an ice record has a level `<select name="level" required>`, pre-selected with the suggestion

- [ ] **Step 1: Write the failing tests**

Add to `tests/GearLibTest.php`:

```php
    public function testAcceptingIceGearPhotosNeedsALevel(): void
    {
        $pdo = make_temp_pdo();
        $owner = $this->owner($pdo);
        $id = (int)gearCreate($pdo, $owner, 'Sam', '', 2027, 'ice')['id'];
        db_mark_gear_photos_draft($pdo, $id);
        db_transition_gear_photo_status($pdo, $id, ['draft'], 'submitted');

        $this->assertFalse(gearAcceptByPhotos($pdo, $id, $owner)['ok']);
        $this->assertFalse(gearAcceptByPhotos($pdo, $id, $owner, 'bogus')['ok']);
        $this->assertSame('submitted', db_get_gear_record($pdo, $id)['photo_status']);

        $this->assertTrue(gearAcceptByPhotos($pdo, $id, $owner, 'street_safe')['ok']);
        $g = db_get_gear_record($pdo, $id);
        $this->assertSame('accepted', $g['status']);
        $this->assertSame('photos', $g['accepted_via']);
        $this->assertSame('street_safe', $g['level']);
    }

    public function testSuggestedLevelComesFromTheHelmetPhoto(): void
    {
        $snap = fn(?string $std): array => ['photos' => $std === null ? [] : ['ice_helmet_label' => [
            'file_path' => 'x.jpg', 'typed_value' => json_encode(['standard' => $std]),
        ]]];
        $this->assertSame('caged', gearSuggestedLevel($snap('Snell SA2020')));
        $this->assertSame('street_safe', gearSuggestedLevel($snap('ECE 22.06')));
        $this->assertNull(gearSuggestedLevel($snap(null)));
    }
```

Add to `tests/AdminGearCopyTest.php` (it reads `admin-gear.php` source):

```php
    public function testPhotoAcceptPassesALevelAndIceCardHasAPicker(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../admin-gear.php'));
        $this->assertStringContainsString("gearAcceptByPhotos(\$pdo, \$id, (int)\$user['id'], \$level)", $src);
        $this->assertStringContainsString('gearSuggestedLevel(', $src);
        $this->assertStringNotContainsString("Photo review isn't available for ice gear yet.", $src);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter "GearLibTest|AdminGearCopyTest"` → FAIL.

- [ ] **Step 3: Implement it**

Replace `gearAcceptByPhotos()` in `gear-lib.php`:

```php
/** Inspector accepts a submitted photo set remotely. Ice records also record the confirmed gear level. @return array{ok: bool, error: ?string} */
function gearAcceptByPhotos(PDO $pdo, int $id, int $reviewerUserId, ?string $level = null): array {
    $gear = db_get_gear_record($pdo, $id);
    $isIce = $gear !== null && ($gear['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_ICE;
    if ($isIce && !isset(ICE_GEAR_LEVEL_LABELS[(string)$level])) {
        return ['ok' => false, 'error' => 'Choose the gear level: street-safe or caged.'];
    }
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        if (!db_accept_gear_by_photos($pdo, $id, $reviewerUserId)) {
            if ($own) $pdo->rollBack();
            return ['ok' => false, 'error' => 'These photos are not awaiting review.'];
        }
        db_set_all_photos_review_status($pdo, 'gear_record', $id, 'accepted');
        if ($isIce) db_set_gear_level($pdo, $id, $level);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'error' => null];
}

/** The level the helmet photo's standard suggests (ice gear), or null. */
function gearSuggestedLevel(array $snapshot): ?string {
    $row = $snapshot['photos']['ice_helmet_label'] ?? null;
    if ($row === null || ($row['file_path'] ?? '') === '') return null;
    $typed = json_decode((string)($row['typed_value'] ?? ''), true);
    return is_array($typed) && is_string($typed['standard'] ?? null) ? iceGearLevelForHelmet($typed['standard']) : null;
}
```

Make sure `gear-lib.php` `require_once`s `photo-requirements.php` (it already does per its header; verify).

`admin-gear.php` `handleGearAdminPhotosAccept()`:
- Delete the Phase 2 ice refusal block.
- Before calling `gearAcceptByPhotos`, add `$level = is_string($_POST['level'] ?? null) && $_POST['level'] !== '' ? $_POST['level'] : null;`.
- Call it as `gearAcceptByPhotos($pdo, $id, (int)$user['id'], $level)`.

In `renderGearReviewCard()`'s accept form (the `gear-photos-accept` form), before the button, add:

```php
      <?php if (($gear['discipline'] ?? 'summer') === 'ice'): $suggested = gearSuggestedLevel($snapshot); ?>
      <label for="gear-photos-level">Gear level</label>
      <select id="gear-photos-level" name="level" required>
        <option value="">Choose</option>
        <?php foreach (ICE_GEAR_LEVEL_LABELS as $value => $label): ?>
        <option value="<?= h($value) ?>"<?= $value === $suggested ? ' selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="form-hint">Suggested from the helmet standard in the photo. Caged: SA/FIA helmet. Uncaged classes: Snell M2015+ or ECE 22.05/22.06. WSCC Studded also accepts ECE 22.05 made 2015 or later, so choose caged there if the helmet qualifies.</p>
      <?php endif; ?>
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar --filter "GearLibTest|AdminGearCopyTest"` → PASS.
Run: `php -l admin-gear.php` → no syntax errors.
Run: `php phpunit.phar` → the full suite PASSES.

- [ ] **Step 5: Commit**

```bash
git add gear-lib.php admin-gear.php tests/GearLibTest.php tests/AdminGearCopyTest.php
git commit -m "feat(ice): accepting ice gear photos records the confirmed level"
```

---

### Task 6: Ice badges in the inspector review queue

**Files:**
- Modify: `inspect-lib.php` (`inspectReviewQueue`)
- Test: `tests/InspectLibTest.php` (add)

**Interfaces:**
- Consumes: `techSheetClassLine()` (ice-sheet-lib.php).
- Produces: queue items for ice car photo sets have `'detail'` = `{entrant} · {event name} · Ice · {class line}`. Ice gear photo sets have `'detail'` = `Entered by {owner} · Ice {season}`. Summer items are unchanged.

- [ ] **Step 1: Write the failing test**

Add to `tests/InspectLibTest.php`:

```php
    public function testReviewQueueMarksIcePhotoSets(): void
    {
        $sheet = ['id' => 9, 'car_number' => '7', 'car_make' => 'Honda', 'car_model' => 'Civic', 'entrant_name' => 'Sam',
                  'event_name' => 'NASCC Ice #1', 'updated_at' => '2026-12-01 10:00:00',
                  'discipline' => 'ice', 'club' => 'NASCC', 'class' => 'LS'];
        $gear = ['id' => 4, 'driver_name' => 'Sam', 'owner_name' => 'Jordan', 'season' => 2027, 'updated_at' => '2026-12-02 10:00:00', 'discipline' => 'ice'];
        $items = inspectReviewQueue([], [$sheet], [$gear]);
        $this->assertSame('Sam · NASCC Ice #1 · Ice · LS — Limited Stud (NASCC)', $items[0]['detail']);
        $this->assertSame('Entered by Jordan · Ice 2027', $items[1]['detail']);

        $summer = inspectReviewQueue([], [['discipline' => 'summer', 'club' => null, 'class' => 'IT1'] + $sheet], []);
        $this->assertSame('Sam · NASCC Ice #1', $summer[0]['detail']);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php phpunit.phar --filter InspectLibTest` → FAIL.

- [ ] **Step 3: Implement it**

In `inspectReviewQueue()`:
- In the sheets loop, build the detail as:

```php
            'detail' => $s['entrant_name'] . ' · ' . ($s['event_name'] ?? '')
                . (techSheetIsIce($s) ? ' · Ice · ' . techSheetClassLine($s) : ''),
```

- In the gear loop:

```php
            'detail' => 'Entered by ' . ($g['owner_name'] ?? '') . ' · '
                . ((($g['discipline'] ?? 'summer') === 'ice') ? 'Ice ' : '') . (int)$g['season'],
```

Make sure `inspect-lib.php` `require_once`s `ice-sheet-lib.php`. Phase 2 already added it; verify.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar --filter InspectLibTest` → PASS.
Run: `php phpunit.phar` → the full suite PASSES.

- [ ] **Step 5: Manual check (for the human partner)**

1. On an ice tech sheet (NASCC LS), use "Get pre-teched":
   - the cards are the caged shot list;
   - upload a few photos, switch the sheet's class to SS on the edit page, and check that the page now lists the street-safe shots.
2. From the ice sheet's gear chip, choose "Add gear photos":
   - upload a helmet label with "Snell M2015";
   - add the other required shots and submit.
3. As an inspector, open the review queue:
   - the ice items say "Ice";
   - open the gear set: the level picker is pre-selected with "street-safe";
   - accept, and check that the chip reads "Gear pre-teched Ice 2027 · street-safe".

- [ ] **Step 6: Commit**

```bash
git add inspect-lib.php tests/InspectLibTest.php
git commit -m "feat(ice): review queue marks ice photo sets"
```

---

## Self-review notes

**Spec coverage:**

| Spec | Task |
|---|---|
| §1 photo requirements (shot table with groups and club guidance; gear shots; FHR conditional; helmet standards in both levels) | T1 |
| Helmet → level | T1, T5 |
| §3 photo pre-tech: same pages and flow, shot list filtered by group | T2, T3, T4 |
| Class change keeps off-list photos | T2 test |
| Review screen reads the ice list | T2 snapshot; the review cards label photos via `photoRequirementByKey`, which now knows ice keys |
| Inspector confirms the level | T5 |
| Admin review queue badge | T6 |

**Phase 2 carry-overs closed:**
- `gearAcceptByPhotos` level (T5);
- gear.php and admin photo-accept refusals lifted (T4, T5);
- tech-sheet pretech refusal lifted (T3).

**Deliberate choices:**
- Ice keys are prefixed `ice_` instead of storing a list id. A bare key stays unambiguous for emails and review cards, and no schema change is needed.
- The street-safe helmet floor stays NASCC's (M2015). This matches Phase 2's helmet note.
- For WSCC Studded, an ECE 22.05 helmet is suggested as street-safe. The inspector can choose caged, and the hint says so.
