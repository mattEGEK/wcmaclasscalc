# Ice Racing Phase 1: Foundations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lay the data foundations for ice racing:
- the ice rules data (clubs, classes, groups, checklists)
- ice seasons with a July rollover
- ice events with a host club
- a schema that keeps summer and ice tech, gear and at-track choices apart

Summer behaviour stays exactly as it is today.

**Architecture:** Plain PHP + SQLite, no framework.
- New pure rules live in `ice-rules.php`.
- Season and status-key helpers stay in `tech-status.php`.
- DB access stays in `db.php`.
- Three tables need constraint changes that SQLite can't do in place. One guarded helper rebuilds each table in a single transaction, and it can be re-run safely because the Hub auto-pulls on deploy.
- Every existing function keeps its current signature, with new trailing parameters that default to summer. Summer callers and summer tests therefore don't change.

**Tech Stack:** PHP 8.3, SQLite (PDO), PHPUnit 10 (`php phpunit.phar`).

**Spec:** `docs/superpowers/specs/2026-09-27-ice-racing-design.md`. Read §1 (rules data), §2 (data model), §4a (running both seasons) and §5 (admin).

### Roadmap (one plan per phase, each written when the previous one lands)

| Phase | Delivers | Spec |
|---|---|---|
| **1 — Foundations (this plan)** | Rules data, ice seasons, ice events in admin, schema + DB API for discipline/club/level | §1, §2, §5 |
| 2 — Ice tech sheet | Class dropdown, group checklists, in-person review and acceptance, gear level on acceptance, email/print | §3 |
| 3 — Ice photo pre-tech | `ICE_PHOTO_REQUIREMENTS`, helmet → gear level, review screen | §1 photos, §3 |
| 4 — Readiness and both seasons | Readiness/Home/Garage/Drivers/media/inspector, summer gear carry-over | §4, §4a |

## Global Constraints

- **Paths and tests:**
  - All paths are relative to `wcma-calculator/` unless they start with `docs/`.
  - Run PHPUnit from `wcma-calculator/` with `php phpunit.phar`.
  - The full suite must pass at the end of every task.
- **No new dependencies and no build step.**
- **Terminology:** use *reviewed*, *accepted* and *pre-teched*. Never use *approved*, *passed* or *safe* in UI copy. The *Street Safe* class name is exempt: it is the clubs' own name.
- **Summer is unchanged.** These must keep their current output for summer data:
  - `techCarKey()` returns `car_id|season` for summer sheets.
  - At-track keys stay `car:ID@2026` and `driver:ID@2026` for summer.
  - `techCarStatusLabel()` still returns `Teched 2026` and `Pre-teched 2026`.
- **Disciplines:** exactly `summer` and `ice` (constants `DISCIPLINE_SUMMER` and `DISCIPLINE_ICE`).
- **Club codes:** exactly `NASCC` and `WSCC`.
- **Class groups:** exactly `drift`, `street_safe` and `caged`.
- **Gear levels:** exactly `street_safe` and `caged`.
- **Ice season:** an event dated on or after 1 July belongs to the next year's ice season.
- **Declarations:**
  - A summer tech sheet still requires a class declaration; inserting one without a declaration throws.
  - An ice tech sheet must not carry a declaration; it takes `car_id` directly.
- **Migrations are safe to run twice.** A second `db_init()` on a migrated database does nothing.
- **Branch and commits:**
  - Work on a branch named `ice-phase1`, not `main`.
  - Commit at the end of every task.
  - Every commit message ends with:
    ```
    Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
    Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5
    ```

## Review Focus

1. **Summer and ice sheets for the same car in the same calendar year.** Their statuses must never merge, in both the identity lookup and the season roster. Test: Task 4, `testSummerAndIceSheetsSameYearDoNotMerge`.
2. **Two deploy requests running the migration at once.** The second request must not fail or copy rows twice. The guard re-checks the marker inside `BEGIN IMMEDIATE`. Test: Task 3, `testRebuildIsIdempotentAndKeepsRows` runs `db_init` twice and counts rows.
3. **Choosing "at the track" for summer, then clicking it again.** It must not create a duplicate row. SQLite treats NULLs as distinct in UNIQUE, so `club` is `NOT NULL DEFAULT ''`. Test: Task 6, `testSummerAtTrackStillDedupes`.
4. **Editing a tech sheet to move it from a summer event to an ice event, or back.** This must be refused, not silently re-keyed. Test: Task 4, `testSheetCannotMoveBetweenDisciplines`.
5. **Admin saves an ice event with no club, or a summer event with a club.** The first is rejected. The second has its club dropped. Test: Task 2, `testIceEventRequiresKnownClub` and `testSummerEventDropsClub`.

---

### Task 1: Ice rules data (`ice-rules.php`)

**Files:**
- Create: `ice-rules.php`
- Test: `tests/IceRulesTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - constants `ICE_RULES_VERSION`, `ICE_CLUBS`, `ICE_CLASS_GROUPS`, `ICE_CHECKLIST_SECTIONS`, `ICE_CLUB_OVERRIDES`
  - `iceClubCodes(): string[]`
  - `iceClubLabel(string $club): ?string`
  - `iceClass(string $club, string $code): ?array` — returns `['code','label','group','note','fhr']`
  - `iceClassOptions(string $club): array<string,string>` — code ⇒ `"CODE — Label"`
  - `iceChecklistSections(string $club, string $group): array` — the same shape as `TECH_CHECKLIST_SECTIONS`, with overrides applied and omitted items removed
  - `iceGearSatisfies(?string $level, string $group): bool`

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/IceRulesTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../ice-rules.php';

final class IceRulesTest extends TestCase
{
    public function testClubsAreNasccAndWscc(): void
    {
        $this->assertSame(['NASCC', 'WSCC'], iceClubCodes());
        $this->assertSame('Winnipeg Sports Car Club', iceClubLabel('WSCC'));
        $this->assertNull(iceClubLabel('XYZ'));
    }

    public function testEveryClassHasAKnownGroupLabelAndNote(): void
    {
        foreach (ICE_CLUBS as $club => $def) {
            $this->assertNotEmpty($def['classes'], $club);
            foreach ($def['classes'] as $code => $class) {
                $this->assertContains($class['group'], ICE_CLASS_GROUPS, "$club $code");
                $this->assertNotSame('', $class['label'], "$club $code");
                $this->assertNotSame('', $class['note'], "$club $code");
            }
        }
    }

    public function testEveryGroupHasChecklistSections(): void
    {
        foreach (ICE_CLASS_GROUPS as $group) {
            $this->assertArrayHasKey($group, ICE_CHECKLIST_SECTIONS);
            $this->assertNotEmpty(ICE_CHECKLIST_SECTIONS[$group]);
        }
    }

    public function testOverridesOnlyNameExistingItems(): void
    {
        $known = [];
        foreach (ICE_CHECKLIST_SECTIONS as $sections) {
            foreach ($sections as $section) $known += $section['items'];
        }
        foreach (ICE_CLUB_OVERRIDES as $club => $overrides) {
            $this->assertContains($club, iceClubCodes());
            foreach (array_keys($overrides) as $key) {
                $this->assertArrayHasKey($key, $known, "$club override '$key'");
            }
        }
    }

    public function testIceClassLookup(): void
    {
        $ls = iceClass('NASCC', 'LS');
        $this->assertSame('LS', $ls['code']);
        $this->assertSame('caged', $ls['group']);
        $this->assertTrue($ls['fhr']);
        $this->assertFalse(iceClass('NASCC', 'NS')['fhr']);
        $this->assertSame('street_safe', iceClass('WSCC', 'FOI-SS')['group']);
        $this->assertSame('drift', iceClass('WSCC', 'DRIFT')['group']);
        $this->assertNull(iceClass('WSCC', 'LS'));
        $this->assertNull(iceClass('XYZ', 'LS'));
    }

    public function testChsIsTheSameAsCh(): void
    {
        $this->assertSame(iceClass('NASCC', 'CH')['group'], iceClass('NASCC', 'CHSS')['group']);
    }

    public function testClassOptionsAreCodeDashLabel(): void
    {
        $opts = iceClassOptions('WSCC');
        $this->assertSame(['DRIFT', 'FOI-SS', 'FOI-STD'], array_keys($opts));
        $this->assertSame('FOI-STD — Fire on Ice – Studded', $opts['FOI-STD']);
        $this->assertSame([], iceClassOptions('XYZ'));
    }

    public function testChecklistAppliesClubWording(): void
    {
        $flat = fn(array $sections): array => array_merge(...array_map(fn($s) => $s['items'], array_values($sections)));
        $n = $flat(iceChecklistSections('NASCC', 'street_safe'));
        $w = $flat(iceChecklistSections('WSCC', 'street_safe'));
        $this->assertSame('Airbags removed (disabling is not enough)', $n['airbags']);
        $this->assertSame('Airbags disabled or removed', $w['airbags']);
        $this->assertStringContainsString('4 rear brake lights', $n['brake_lights']);
        $this->assertStringContainsString('3 rear brake lights', $w['brake_lights']);
    }

    public function testNullOverrideOmitsItemAndEmptySectionsDrop(): void
    {
        $flat = fn(array $sections): array => array_merge(...array_map(fn($s) => $s['items'], array_values($sections)));
        $this->assertArrayHasKey('catch_tanks', $flat(iceChecklistSections('NASCC', 'caged')));
        $this->assertArrayNotHasKey('catch_tanks', $flat(iceChecklistSections('WSCC', 'caged')));
        $this->assertArrayNotHasKey('abs_disabled', $flat(iceChecklistSections('WSCC', 'caged')));
        foreach (iceChecklistSections('WSCC', 'caged') as $section) {
            $this->assertNotEmpty($section['items']);
        }
    }

    public function testUnknownGroupHasNoChecklist(): void
    {
        $this->assertSame([], iceChecklistSections('NASCC', 'bogus'));
    }

    public function testGearLevelSatisfiesGroup(): void
    {
        $this->assertTrue(iceGearSatisfies('caged', 'caged'));
        $this->assertTrue(iceGearSatisfies('caged', 'street_safe'));
        $this->assertTrue(iceGearSatisfies('caged', 'drift'));
        $this->assertTrue(iceGearSatisfies('street_safe', 'street_safe'));
        $this->assertTrue(iceGearSatisfies('street_safe', 'drift'));
        $this->assertFalse(iceGearSatisfies('street_safe', 'caged'));
        $this->assertFalse(iceGearSatisfies(null, 'drift'));
        $this->assertFalse(iceGearSatisfies('bogus', 'drift'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php phpunit.phar --filter IceRulesTest`
Expected: FAIL, because `ice-rules.php` does not exist ("Failed opening required").

- [ ] **Step 3: Write the implementation**

```php
<?php
// wcma-calculator/ice-rules.php
//
// Ice racing rules as data: the host clubs, their classes, and the tech checklist for each class
// group. Sources: NASCC 2026 Ice Race Supp Regs + Street Safe Addendum; WSCC 2026 Ice Race
// Supplementary Regulations. Update here when the regs change and bump ICE_RULES_VERSION.
// Pure: no DB, no HTML.

const ICE_RULES_VERSION = 1;

const ICE_CLASS_GROUPS = ['drift', 'street_safe', 'caged'];

const ICE_CLUBS = [
    'NASCC' => ['label' => 'Northern Alberta Sports Car Club', 'classes' => [
        'SS'    => ['label' => 'Street Safe (FWD/RWD)', 'group' => 'street_safe', 'fhr' => false,
                    'note' => 'Uncaged, FWD/RWD, studless DOT winter tire ≤ $160, ≤ 3,150 lb, ≤ 110" wheelbase, built after 1972'],
        'SSAWD' => ['label' => 'Street Safe (AWD)', 'group' => 'street_safe', 'fhr' => false,
                    'note' => 'As SS; AWD cars are classed separately'],
        'NS'    => ['label' => 'No-Stud', 'group' => 'caged', 'fhr' => false,
                    'note' => '2WD, caged, studless DOT winter tire ≤ $160, min 165 mm'],
        'LS'    => ['label' => 'Limited Stud', 'group' => 'caged', 'fhr' => true,
                    'note' => '2WD, caged, spec bolted tires: 9 bolts/ft, 12 mm max protrusion'],
        'CH'    => ['label' => 'Chevette', 'group' => 'caged', 'fhr' => false,
                    'note' => 'Chevette/Acadian/Scooter/T1000 1976–87, stock 1.4/1.6, 155/80R13 bolted tires'],
        'CHSS'  => ['label' => 'Chevette Street Stud', 'group' => 'caged', 'fhr' => false,
                    'note' => 'Same as CH: Chevette/Acadian/Scooter/T1000 1976–87, stock 1.4/1.6, same bolted tire'],
        'AWD'   => ['label' => 'AWD', 'group' => 'caged', 'fhr' => true,
                    'note' => 'AWD, caged, ≤ 3,150 lb; tire type set with the organizers in advance'],
    ]],
    'WSCC' => ['label' => 'Winnipeg Sports Car Club', 'classes' => [
        'DRIFT'   => ['label' => 'Ice Drift', 'group' => 'drift', 'fhr' => false,
                      'note' => 'Non-competitive lapping, any drivetrain, DOT winter or street-studded tires'],
        'FOI-SS'  => ['label' => 'Fire on Ice – Street Safe', 'group' => 'street_safe', 'fhr' => false,
                      'note' => 'Uncaged, FWD/RWD only, studless DOT winter tire, ≤ 3,150 lb published curb weight, ≤ 110" wheelbase, built after 1972'],
        'FOI-STD' => ['label' => 'Fire on Ice – Studded', 'group' => 'caged', 'fhr' => false,
                      'note' => 'Caged, 4-cyl ≤ 140 hp, ≥ 1,700 lb, spec bolted tires: 9 bolts/ft, 12 mm max protrusion'],
    ]],
];

const ICE_CHECKLIST_SECTIONS = [
    'drift' => [
        'vehicle_exterior' => ['label' => 'Vehicle Exterior', 'items' => [
            'tow_hooks'              => 'Front and rear tow hooks, installed and easy to reach',
            'lights'                 => 'Headlights, tail lights and 2 red brake lights working',
            'rear_light_recommended' => 'Rear-facing roof light (recommended)',
            'body_secure'            => 'Body panels, bumpers and exhaust secure',
        ]],
        'wheels_tires' => ['label' => 'Wheels & Tires', 'items' => [
            'tires_class'     => 'Tires meet class rules',
            'wheel_condition' => 'Wheel and tire condition',
        ]],
        'mechanical' => ['label' => 'Mechanical & Interior', 'items' => [
            'brakes_steering' => 'Brakes, steering and suspension safe',
            'battery'         => 'Battery securely mounted',
            'no_leaks'        => 'No fluid leaks',
            'no_loose_items'  => 'Interior clean, no loose items or sharp edges',
        ]],
    ],
    'street_safe' => [
        'under_vehicle' => ['label' => 'Under Vehicle', 'items' => [
            'brakes_steering'  => 'Brakes at every wheel; steering and suspension stock type and safe',
            'exhaust_exit'     => 'Exhaust secure and exits behind the driver',
            'fuel_system'      => 'Fuel system stock',
            'no_leaks'         => 'No fluid leaks',
        ]],
        'wheels_tires' => ['label' => 'Wheels & Tires', 'items' => [
            'tires_class'     => 'Studless DOT winter tires (snowflake), min 165 mm, meet class rules',
            'wheel_condition' => 'Wheels and tires inside the fenders; no space-savers',
        ]],
        'engine_compartment' => ['label' => 'Engine Compartment', 'items' => [
            'battery' => 'Battery in stock location, secured at 2 points, positive terminal insulated',
        ]],
        'vehicle_interior' => ['label' => 'Vehicle Interior', 'items' => [
            'seats'          => 'Seats secure; factory seats have headrests; aftermarket seats on 4 points',
            'seat_belts'     => 'Factory 3-point belts working (5-point only in caged cars)',
            'airbags'        => 'Airbags',
            'no_loose_items' => 'No loose items or sharp edges',
            'mirrors'        => 'Both side mirrors and interior mirror',
        ]],
        'vehicle_exterior' => ['label' => 'Vehicle Exterior', 'items' => [
            'tow_hooks'       => 'Front and rear tow hooks, clearly marked',
            'crash_structure' => 'Factory crash structures and door beams intact; no structural rust',
            'windshield'      => 'Windshield: no crack through more than one layer; wiper works',
            'windows'         => 'Driver and passenger windows in place and closed; no aftermarket tint',
            'sunroof'         => 'Sunroof secured, or replaced with metal',
            'headlights'      => 'Two or more headlights (clear or blue), tail lights working',
            'brake_lights'    => 'Rear brake lights as per club rules',
            'rear_light'      => 'Rear-facing roof light at eye level',
            'appearance'      => 'Neat appearance; car number and class decals',
        ]],
    ],
    'caged' => [
        'under_vehicle' => ['label' => 'Under Vehicle', 'items' => [
            'brakes_steering' => 'Brakes at every wheel; steering and suspension safe',
            'exhaust_exit'    => 'Exhaust secure and exits behind the driver',
            'no_leaks'        => 'No fluid leaks',
        ]],
        'wheels_tires' => ['label' => 'Wheels & Tires', 'items' => [
            'tires_class'     => 'Tires meet class rules (spec studs: count and protrusion)',
            'wheel_condition' => 'Wheels and tires inside the fenders; no space-savers',
            'mud_flaps'       => 'Mud flaps behind the driven wheels',
        ]],
        'engine_compartment' => ['label' => 'Engine Compartment', 'items' => [
            'battery'     => 'Battery secured, positive terminal insulated (marine box if inside)',
            'catch_tanks' => 'Engine and radiator catch tanks (min. 1 L)',
            'fuel_system' => 'Fuel system and firewall; fuel pump outside the cockpit',
        ]],
        'vehicle_interior' => ['label' => 'Vehicle Interior', 'items' => [
            'roll_cage'      => 'Roll cage to WCMA spec, with ice roof reinforcement',
            'seat_mounted'   => "Driver's seat securely mounted",
            'harness'        => '5-point SFI/FIA harness, in date',
            'window_net'     => 'Window net and release',
            'kill_switch'    => 'Kill switch, clearly marked',
            'abs_disabled'   => 'ABS disabled',
            'no_loose_items' => 'No loose items or sharp edges',
            'mirrors'        => 'Mirrors (at least one outside and one inside)',
        ]],
        'vehicle_exterior' => ['label' => 'Vehicle Exterior', 'items' => [
            'tow_hooks'    => 'Front and rear tow hooks, clearly marked',
            'hood_pins'    => 'Hood (and trunk or hatch) pinned or double-latched',
            'windshield'   => 'Windshield glass or polycarbonate; wiper works',
            'headlights'   => 'Two or more headlights (clear or blue), tail lights working',
            'brake_lights' => 'Rear brake lights as per club rules',
            'rear_light'   => 'Rear-facing roof light at eye level',
            'bumpers'      => 'Bumpers stock or per the wood-bumper rule; no sharp ends',
            'appearance'   => 'Car numbers (10"+) and decals',
        ]],
    ],
];

/** Per-club wording, keyed by checklist item. A null value removes the item for that club. */
const ICE_CLUB_OVERRIDES = [
    'NASCC' => [
        'airbags'      => 'Airbags removed (disabling is not enough)',
        'brake_lights' => '4 rear brake lights, 2 on or above the trunk lid (red lenses)',
        'rear_light'   => 'Rear-facing 20 W+ roof light at eye level',
    ],
    'WSCC' => [
        'airbags'      => 'Airbags disabled or removed',
        'brake_lights' => '3 rear brake lights, 1 on or above the trunk lid (red lenses)',
        'rear_light'   => 'Rear-facing 55 W+ (1000 lm LED) amber or blue roof light at eye level',
        'catch_tanks'  => null,
        'abs_disabled' => null,
    ],
];

/** Gear levels that satisfy each group. */
const ICE_GEAR_ACCEPTS = [
    'drift'       => ['street_safe', 'caged'],
    'street_safe' => ['street_safe', 'caged'],
    'caged'       => ['caged'],
];

/** @return string[] */
function iceClubCodes(): array {
    return array_keys(ICE_CLUBS);
}

function iceClubLabel(string $club): ?string {
    return ICE_CLUBS[$club]['label'] ?? null;
}

/** @return ?array{code: string, label: string, group: string, note: string, fhr: bool} */
function iceClass(string $club, string $code): ?array {
    $class = ICE_CLUBS[$club]['classes'][$code] ?? null;
    return $class === null ? null : ['code' => $code] + $class;
}

/** @return array<string, string> code => "CODE — Label", in rule order */
function iceClassOptions(string $club): array {
    $out = [];
    foreach (ICE_CLUBS[$club]['classes'] ?? [] as $code => $class) {
        $out[$code] = $code . ' — ' . $class['label'];
    }
    return $out;
}

/** The checklist for one club and class group, shaped like TECH_CHECKLIST_SECTIONS. */
function iceChecklistSections(string $club, string $group): array {
    $overrides = ICE_CLUB_OVERRIDES[$club] ?? [];
    $out = [];
    foreach (ICE_CHECKLIST_SECTIONS[$group] ?? [] as $sectionKey => $section) {
        $items = [];
        foreach ($section['items'] as $key => $label) {
            if (array_key_exists($key, $overrides)) {
                if ($overrides[$key] === null) continue;
                $label = $overrides[$key];
            }
            $items[$key] = $label;
        }
        if ($items) $out[$sectionKey] = ['label' => $section['label'], 'items' => $items];
    }
    return $out;
}

/** True if gear checked at $level is good enough for a class in $group. */
function iceGearSatisfies(?string $level, string $group): bool {
    return $level !== null && in_array($level, ICE_GEAR_ACCEPTS[$group] ?? [], true);
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php phpunit.phar --filter IceRulesTest`
Expected: PASS (12 tests).

- [ ] **Step 5: Commit**

```bash
git add ice-rules.php tests/IceRulesTest.php
git commit -m "feat(ice): ice rules data — clubs, classes, groups, checklists"
```

---

### Task 2: Ice seasons and ice events (schema, DB, admin)

**Files:**
- Modify: `tech-status.php` (add the constants and season helpers after `techSeasonFromDate()`; change `techCarKey()` and `techCarStatusLabel()`)
- Modify: `ice-rules.php` (add `iceEventFields()`)
- Modify: `db.php`:
  - add two `db_add_column_if_missing` calls after the `events` CREATE, in the migration block before the driver media profiles block (~line 329)
  - `db_create_event()` ~line 650
  - `db_update_event()` ~line 675
- Modify: `admin.php`:
  - require `ice-rules.php`
  - `handleEventCreate()` / `handleEventUpdate()` ~lines 306–337
  - `renderEventsPage()` ~lines 347–420
- Test: `tests/TechStatusTest.php` (add tests), `tests/DbEventsTest.php` (add tests), `tests/IceEventFieldsTest.php` (create), `tests/AdminSourceTest.php` (add a test)

**Interfaces:**
- Consumes: `iceClubCodes()` from Task 1.
- Produces:
  - `const DISCIPLINE_SUMMER = 'summer'; const DISCIPLINE_ICE = 'ice'; const ICE_SEASON_ROLLOVER_MONTH = 7;`
  - `iceSeasonFromDate(?string $date): int`
  - `seasonForEvent(?array $event): array{discipline: string, season: int, club: ?string}`
  - `techCarKey(array $sheet): string` — summer: `"car|season"`; ice: `"car|ice|CLUB|season"`
  - `techCarStatusLabel(array $status, int $season, string $discipline = 'summer'): string`
  - `db_create_event(PDO, string $name, string $date, ?string $location, string $discipline = 'summer', ?string $hostClub = null): int`
  - `db_update_event(PDO, int $id, string $name, string $date, ?string $location, string $discipline, ?string $hostClub): void` — the last two are required, so an update can never silently turn an ice event back into a summer one
  - `iceEventFields(array $post): array{ok: bool, discipline: string, club: ?string, error: ?string}`
  - `events.discipline` (TEXT NOT NULL DEFAULT 'summer') and `events.host_club` (TEXT NULL) columns

- [ ] **Step 1: Write the failing tests**

Add to `tests/TechStatusTest.php` (inside the class):

```php
    public function testIceSeasonRollsOverInJuly(): void
    {
        $this->assertSame(2027, iceSeasonFromDate('2026-12-12'));
        $this->assertSame(2027, iceSeasonFromDate('2027-01-04'));
        $this->assertSame(2027, iceSeasonFromDate('2027-03-08'));
        $this->assertSame(2027, iceSeasonFromDate('2027-06-30'));
        $this->assertSame(2028, iceSeasonFromDate('2027-07-01'));
    }

    public function testSeasonForEvent(): void
    {
        $this->assertSame(['discipline' => 'summer', 'season' => 2026, 'club' => null],
            seasonForEvent(['event_date' => '2026-12-12', 'discipline' => 'summer', 'host_club' => null]));
        $this->assertSame(['discipline' => 'ice', 'season' => 2027, 'club' => 'NASCC'],
            seasonForEvent(['event_date' => '2026-12-12', 'discipline' => 'ice', 'host_club' => 'NASCC']));
        // Rows from before the migration have no discipline column: they are summer.
        $this->assertSame('summer', seasonForEvent(['event_date' => '2026-05-10'])['discipline']);
        $this->assertSame('summer', seasonForEvent(null)['discipline']);
    }

    public function testIceCarKeyIncludesClub(): void
    {
        $this->assertSame('500|2026', techCarKey($this->sheet(1, ['discipline' => 'summer', 'club' => null])));
        $this->assertSame('500|ice|NASCC|2026', techCarKey($this->sheet(1, ['discipline' => 'ice', 'club' => 'NASCC'])));
        $this->assertNotSame(
            techCarKey($this->sheet(1, ['discipline' => 'ice', 'club' => 'NASCC'])),
            techCarKey($this->sheet(2, ['discipline' => 'ice', 'club' => 'WSCC'])));
    }

    public function testIceStatusLabel(): void
    {
        $this->assertSame('Teched Ice 2027', techCarStatusLabel(['state' => 'accepted', 'via' => 'in_person', 'sheet_id' => 1], 2027, 'ice'));
        $this->assertSame('Pre-teched Ice 2027', techCarStatusLabel(['state' => 'accepted', 'via' => 'photos', 'sheet_id' => 1], 2027, 'ice'));
        $this->assertSame('Needs tech at the track', techCarStatusLabel(['state' => 'none', 'via' => null, 'sheet_id' => null], 2027, 'ice'));
    }
```

Add to `tests/DbEventsTest.php` (inside the class):

```php
    public function testEventsDefaultToSummer(): void
    {
        $pdo = make_temp_pdo();
        $event = db_get_event($pdo, db_create_event($pdo, 'Spring Sprint', '2026-05-10', null));
        $this->assertSame('summer', $event['discipline']);
        $this->assertNull($event['host_club']);
    }

    public function testCreateAndUpdateIceEvent(): void
    {
        $pdo = make_temp_pdo();
        $id = db_create_event($pdo, 'Ice #1', '2027-01-04', 'Lake Shirley', 'ice', 'WSCC');
        $event = db_get_event($pdo, $id);
        $this->assertSame('ice', $event['discipline']);
        $this->assertSame('WSCC', $event['host_club']);

        db_update_event($pdo, $id, 'Ice #1', '2027-01-04', 'Lake Shirley', 'ice', 'NASCC');
        $this->assertSame('NASCC', db_get_event($pdo, $id)['host_club']);
    }
```

Also fix the existing `testUpdateEvent` in `DbEventsTest.php`, because `db_update_event` now requires discipline and club. Change its update call to:

```php
        db_update_event($pdo, $id, 'New Name', '2026-02-02', 'New Location', 'summer', null);
```

Create `tests/IceEventFieldsTest.php`:

```php
<?php
// wcma-calculator/tests/IceEventFieldsTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../ice-rules.php';

final class IceEventFieldsTest extends TestCase
{
    public function testMissingDisciplineIsSummer(): void
    {
        $this->assertSame(['ok' => true, 'discipline' => 'summer', 'club' => null, 'error' => null], iceEventFields([]));
    }

    public function testSummerEventDropsClub(): void
    {
        $this->assertSame(['ok' => true, 'discipline' => 'summer', 'club' => null, 'error' => null],
            iceEventFields(['discipline' => 'summer', 'host_club' => 'NASCC']));
    }

    public function testIceEventWithClub(): void
    {
        $this->assertSame(['ok' => true, 'discipline' => 'ice', 'club' => 'WSCC', 'error' => null],
            iceEventFields(['discipline' => 'ice', 'host_club' => 'WSCC']));
    }

    public function testIceEventRequiresKnownClub(): void
    {
        $this->assertFalse(iceEventFields(['discipline' => 'ice'])['ok']);
        $this->assertFalse(iceEventFields(['discipline' => 'ice', 'host_club' => ''])['ok']);
        $this->assertFalse(iceEventFields(['discipline' => 'ice', 'host_club' => 'XYZ'])['ok']);
        $this->assertSame('Choose the host club for an ice event.', iceEventFields(['discipline' => 'ice'])['error']);
    }

    public function testUnknownDisciplineIsRejected(): void
    {
        $this->assertFalse(iceEventFields(['discipline' => 'rally'])['ok']);
        $this->assertFalse(iceEventFields(['discipline' => ['ice']])['ok']);
    }
}
```

Add to `tests/AdminSourceTest.php` (inside the class; it uses its `src()` helper):

```php
    public function testEventFormHasDisciplineAndHostClub(): void
    {
        $src = $this->src('admin.php');
        $this->assertStringContainsString('name="discipline"', $src);
        $this->assertStringContainsString('name="host_club"', $src);
        $this->assertStringContainsString('iceEventFields($_POST)', $src);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter "TechStatusTest|DbEventsTest|IceEventFieldsTest|AdminSourceTest"`
Expected: FAIL. You should see undefined functions `iceSeasonFromDate` / `seasonForEvent` / `iceEventFields`, an "Undefined array key 'discipline'", and the new admin test failing.

- [ ] **Step 3: Implement the season helpers in `tech-status.php`**

Add after `techSeasonFromDate()`:

```php
const DISCIPLINE_SUMMER = 'summer';
const DISCIPLINE_ICE = 'ice';
/** Ice seasons are named for the year they end in: an event in or after this month counts toward next year. */
const ICE_SEASON_ROLLOVER_MONTH = 7;

/** Ice season of a date ('YYYY-MM-DD...'): its year, plus one from July on. The current date if unreadable. */
function iceSeasonFromDate(?string $date): int {
    if ($date === null || !preg_match('/^(\d{4})-(\d{2})-\d{2}/', $date, $m)) {
        $date = date('Y-m-d');
        preg_match('/^(\d{4})-(\d{2})/', $date, $m);
    }
    return (int)$m[1] + ((int)$m[2] >= ICE_SEASON_ROLLOVER_MONTH ? 1 : 0);
}

/**
 * The single place an event's season key is derived. Events without a discipline (rows from before
 * ice racing, or no event at all) are summer.
 *
 * @return array{discipline: string, season: int, club: ?string}
 */
function seasonForEvent(?array $event): array {
    $date = $event['event_date'] ?? null;
    if (($event['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_ICE) {
        return ['discipline' => DISCIPLINE_ICE, 'season' => iceSeasonFromDate($date), 'club' => $event['host_club'] ?? null];
    }
    return ['discipline' => DISCIPLINE_SUMMER, 'season' => techSeasonFromDate($date), 'club' => null];
}
```

Replace `techCarKey()`:

```php
/** Groups sheets that belong to the same car in the same season (and, for ice, the same club). */
function techCarKey(array $sheet): string {
    $car = (int)($sheet['car_id'] ?? 0);
    $season = (int)($sheet['season'] ?? 0);
    if (($sheet['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_ICE) {
        return $car . '|ice|' . ($sheet['club'] ?? '') . '|' . $season;
    }
    return $car . '|' . $season;
}
```

Replace `techCarStatusLabel()`:

```php
function techCarStatusLabel(array $status, int $season, string $discipline = DISCIPLINE_SUMMER): string {
    $when = $discipline === DISCIPLINE_ICE ? 'Ice ' . $season : (string)$season;
    switch ($status['state']) {
        case 'accepted':       return ($status['via'] === 'photos' ? 'Pre-teched ' : 'Teched ') . $when;
        case 'needs_changes':  return 'Photos need changes';
        case 'pending_review': return 'Photos pending review';
        case 'photos_draft':   return 'Photos in progress';
        default:               return 'Needs tech at the track';
    }
}
```

Constants must be defined before use: `tech-status.php` is required at the top of `db.php`, so this order is fine.

- [ ] **Step 4: Implement `iceEventFields()` in `ice-rules.php`**

Append:

```php
/**
 * Discipline and host club from the admin event form. Summer events never keep a club.
 * @return array{ok: bool, discipline: string, club: ?string, error: ?string}
 */
function iceEventFields(array $post): array {
    $discipline = $post['discipline'] ?? 'summer';
    if (!is_string($discipline) || !in_array($discipline, ['summer', 'ice'], true)) {
        return ['ok' => false, 'discipline' => 'summer', 'club' => null, 'error' => 'Choose summer or ice.'];
    }
    if ($discipline === 'summer') {
        return ['ok' => true, 'discipline' => 'summer', 'club' => null, 'error' => null];
    }
    $club = $post['host_club'] ?? '';
    if (!is_string($club) || !in_array($club, iceClubCodes(), true)) {
        return ['ok' => false, 'discipline' => 'ice', 'club' => null, 'error' => 'Choose the host club for an ice event.'];
    }
    return ['ok' => true, 'discipline' => 'ice', 'club' => $club, 'error' => null];
}
```

- [ ] **Step 5: Implement the events schema and DB functions in `db.php`**

In `db_init()`, next to the other `db_add_column_if_missing` calls (just before the `// ── Driver media profiles` block), add:

```php
    // ── Ice racing (2026-09-27 spec). Added in place: no reset. ──
    db_add_column_if_missing($pdo, 'events', 'discipline', "TEXT NOT NULL DEFAULT 'summer'");
    db_add_column_if_missing($pdo, 'events', 'host_club', 'TEXT');
```

Replace `db_create_event()` and `db_update_event()`:

```php
function db_create_event(PDO $pdo, string $name, string $event_date, ?string $location,
                         string $discipline = 'summer', ?string $hostClub = null): int {
    $pdo->prepare("
        INSERT INTO events (name, event_date, location, active, created_at, discipline, host_club)
        VALUES (:name, :event_date, :location, 1, :created_at, :discipline, :host_club)
    ")->execute([
        ':name' => $name, ':event_date' => $event_date, ':location' => $location,
        ':created_at' => date('Y-m-d H:i:s'), ':discipline' => $discipline, ':host_club' => $hostClub,
    ]);
    return (int)$pdo->lastInsertId();
}
```

```php
function db_update_event(PDO $pdo, int $id, string $name, string $event_date, ?string $location,
                         string $discipline, ?string $hostClub): void {
    $pdo->prepare("
        UPDATE events SET name = :name, event_date = :event_date, location = :location,
            discipline = :discipline, host_club = :host_club
        WHERE id = :id
    ")->execute([':name' => $name, ':event_date' => $event_date, ':location' => $location,
                 ':discipline' => $discipline, ':host_club' => $hostClub, ':id' => $id]);
}
```

- [ ] **Step 6: Wire up the admin events page (`admin.php`)**

Add `require __DIR__ . '/ice-rules.php';` after the `season-links-lib.php` require.

In **both** `handleEventCreate()` and `handleEventUpdate()`, after the name/date check and before the DB call, add:

```php
    $fields = iceEventFields($_POST);
    if (!$fields['ok']) {
        setFlash((string)$fields['error'], 'error');
        header('Location: admin.php?action=events');
        exit;
    }
```

Then change the DB calls to:

```php
    db_create_event($pdo, $name, $date, $location !== '' ? $location : null, $fields['discipline'], $fields['club']);
```

```php
    db_update_event($pdo, $id, $name, $date, $location !== '' ? $location : null, $fields['discipline'], $fields['club']);
```

In `renderEventsPage()`, inside the Add Event form, after the Location input, add:

```php
      <fieldset class="radio-row">
        <legend>Discipline</legend>
        <label><input type="radio" name="discipline" value="summer" checked> Summer</label>
        <label><input type="radio" name="discipline" value="ice"> Ice</label>
      </fieldset>
      <label for="new-event-club">Host club (ice events)</label>
      <select id="new-event-club" name="host_club">
        <option value="">—</option>
        <?php foreach (iceClubCodes() as $code): ?>
        <option value="<?= h($code) ?>"><?= h($code . ' — ' . iceClubLabel($code)) ?></option>
        <?php endforeach; ?>
      </select>
```

In the events table, change the Name cell to show a badge for ice events:

```php
        <td><?= h($e['name']) ?><?php if (($e['discipline'] ?? 'summer') === 'ice'): ?> <span class="badge-pending">Ice · <?= h((string)$e['host_club']) ?></span><?php endif; ?></td>
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php phpunit.phar --filter "TechStatusTest|DbEventsTest|IceEventFieldsTest|AdminSourceTest"`
Expected: PASS.

Run: `php phpunit.phar`
Expected: the full suite PASSES. Summer keys and labels are unchanged.

- [ ] **Step 8: Commit**

```bash
git add tech-status.php ice-rules.php db.php admin.php tests/TechStatusTest.php tests/DbEventsTest.php tests/IceEventFieldsTest.php tests/AdminSourceTest.php
git commit -m "feat(ice): ice seasons and ice events with a host club"
```

---

### Task 3: Table rebuild helper and legacy-schema fixture

**Files:**
- Modify: `db.php` (add `db_has_column()` and `db_rebuild_table()` next to `db_add_column_if_missing()`, ~line 1760)
- Create: `tests/support/legacy_schema.php`
- Test: `tests/DbRebuildTableTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces:
  - `db_has_column(PDO $pdo, string $table, string $column): bool`
  - `db_rebuild_table(PDO $pdo, string $table, string $markerColumn, string $createSql): bool` — `$createSql` is a `CREATE TABLE IF NOT EXISTS {table} (...)` template. Returns true if it rebuilt.
  - `test_make_legacy_pdo(): PDO` — a temp database holding the **pre-ice** `events`, `tech_sheets`, `gear_records` and `at_track_choices` tables, before `db_init` has run. Tasks 4–6 use it.

- [ ] **Step 1: Create the legacy fixture**

```php
<?php
// wcma-calculator/tests/support/legacy_schema.php
//
// The four tables exactly as they were before ice racing (2026-09-27), so migration tests can
// start from a real pre-ice database. Do not edit to match the current schema.

function test_make_legacy_pdo(): PDO {
    $path = sys_get_temp_dir() . '/wcma_legacy_' . uniqid() . '.db';
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("CREATE TABLE events (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, event_date DATE NOT NULL,
        location TEXT, active INTEGER NOT NULL DEFAULT 1, created_at DATETIME NOT NULL)");
    $pdo->exec("CREATE TABLE tech_sheets (
        id INTEGER PRIMARY KEY AUTOINCREMENT, submission_id INTEGER NOT NULL, car_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL, event_id INTEGER NOT NULL, sheet_type TEXT NOT NULL,
        entrant_name TEXT NOT NULL, driver_name TEXT NOT NULL, driver_id INTEGER, car_make TEXT NOT NULL,
        car_model TEXT NOT NULL, car_colour TEXT NOT NULL, car_number TEXT NOT NULL, class TEXT NOT NULL,
        engine_cc TEXT, engine_hp TEXT, car_weight INTEGER NOT NULL,
        checklist_json TEXT NOT NULL, driver1_equipment_json TEXT NOT NULL, log_book_turned_in INTEGER,
        entrant_signature_path TEXT, entrant_signed_at DATETIME, driver_signature_path TEXT, driver_signed_at DATETIME,
        tech_signature_path TEXT, tech_signed_at DATETIME,
        status TEXT NOT NULL DEFAULT 'submitted', reviewed_by_user_id INTEGER, reviewed_at DATETIME,
        email_sent INTEGER DEFAULT 0, email_send_count INTEGER NOT NULL DEFAULT 0, last_emailed_at DATETIME,
        accepted_via TEXT, photo_status TEXT, car_number_norm TEXT, season INTEGER,
        created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)");
    $pdo->exec("CREATE TABLE gear_records (
        id INTEGER PRIMARY KEY AUTOINCREMENT, driver_id INTEGER NOT NULL, season INTEGER NOT NULL,
        photo_status TEXT, status TEXT NOT NULL DEFAULT 'open', accepted_via TEXT,
        reviewed_by_user_id INTEGER, reviewed_at DATETIME, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
        UNIQUE (driver_id, season))");
    $pdo->exec("CREATE TABLE at_track_choices (
        id INTEGER PRIMARY KEY AUTOINCREMENT, subject_type TEXT NOT NULL, subject_id INTEGER NOT NULL,
        season INTEGER NOT NULL, created_at DATETIME NOT NULL, UNIQUE (subject_type, subject_id, season))");
    return $pdo;
}
```

Add `require_once __DIR__ . '/support/legacy_schema.php';` to `tests/bootstrap.php`, after the `roles.php` require.

- [ ] **Step 2: Write the failing test**

```php
<?php
// wcma-calculator/tests/DbRebuildTableTest.php
use PHPUnit\Framework\TestCase;

final class DbRebuildTableTest extends TestCase
{
    private const SQL = "CREATE TABLE IF NOT EXISTS {table} (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, flavour TEXT NOT NULL DEFAULT 'plain',
        UNIQUE (name, flavour))";

    private function pdo(): PDO {
        $pdo = new PDO('sqlite:' . sys_get_temp_dir() . '/wcma_rebuild_' . uniqid() . '.db');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("CREATE TABLE things (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE)");
        $pdo->exec("INSERT INTO things (name) VALUES ('a'), ('b')");
        return $pdo;
    }

    public function testHasColumn(): void
    {
        $pdo = $this->pdo();
        $this->assertTrue(db_has_column($pdo, 'things', 'name'));
        $this->assertFalse(db_has_column($pdo, 'things', 'flavour'));
    }

    public function testRebuildAddsColumnsKeepsRowsAndIdsAndNewConstraint(): void
    {
        $pdo = $this->pdo();
        $this->assertTrue(db_rebuild_table($pdo, 'things', 'flavour', self::SQL));
        $rows = $pdo->query("SELECT id, name, flavour FROM things ORDER BY id")->fetchAll();
        $this->assertSame([['id' => 1, 'name' => 'a', 'flavour' => 'plain'], ['id' => 2, 'name' => 'b', 'flavour' => 'plain']], $rows);
        // New UNIQUE (name, flavour): same name, different flavour is now allowed.
        $pdo->exec("INSERT INTO things (name, flavour) VALUES ('a', 'ice')");
        $this->assertSame(3, (int)$pdo->query("SELECT COUNT(*) FROM things")->fetchColumn());
        // New rows keep counting on from the old ids.
        $this->assertSame(3, (int)$pdo->query("SELECT MAX(id) FROM things")->fetchColumn());
    }

    public function testRebuildIsIdempotentAndKeepsRows(): void
    {
        $pdo = $this->pdo();
        $this->assertTrue(db_rebuild_table($pdo, 'things', 'flavour', self::SQL));
        $this->assertFalse(db_rebuild_table($pdo, 'things', 'flavour', self::SQL));
        $this->assertSame(2, (int)$pdo->query("SELECT COUNT(*) FROM things")->fetchColumn());
        $this->assertFalse(db_has_column($pdo, 'things__rebuild', 'id'));
    }

    public function testRejectsUnsafeIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        db_rebuild_table($this->pdo(), 'things; DROP', 'flavour', self::SQL);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php phpunit.phar --filter DbRebuildTableTest`
Expected: FAIL with "Call to undefined function db_has_column()".

- [ ] **Step 4: Implement the helpers in `db.php`**

Add directly after `db_add_column_if_missing()`:

```php
function db_has_column(PDO $pdo, string $table, string $column): bool {
    if (!preg_match('/^[a-z_][a-z0-9_]*$/', $table)) throw new InvalidArgumentException('Unsafe identifier: ' . $table);
    foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll() as $col) {
        if ($col['name'] === $column) return true;
    }
    return false;
}

/**
 * Rebuilds $table from the `CREATE TABLE IF NOT EXISTS {table} (...)` template in $createSql, for
 * changes SQLite can't make in place (dropping NOT NULL, changing UNIQUE). Every row and id is kept.
 * Columns the old table lacks take their defaults. Guarded by $markerColumn (a column only the new
 * schema has): does nothing if it is already there, including when a concurrent deploy migrated
 * first, because it re-checks inside BEGIN IMMEDIATE. Recreate indexes after calling this.
 * @return bool true if the table was rebuilt
 */
function db_rebuild_table(PDO $pdo, string $table, string $markerColumn, string $createSql): bool {
    foreach ([$table, $markerColumn] as $ident) {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $ident)) throw new InvalidArgumentException('Unsafe identifier: ' . $ident);
    }
    if (db_has_column($pdo, $table, $markerColumn)) return false;
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        if (db_has_column($pdo, $table, $markerColumn)) {
            $pdo->exec('ROLLBACK');
            return false;
        }
        $tmp = $table . '__rebuild';
        $old = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        $pdo->exec("DROP TABLE IF EXISTS $tmp");
        $pdo->exec(str_replace('{table}', $tmp, $createSql));
        $new = array_column($pdo->query("PRAGMA table_info($tmp)")->fetchAll(), 'name');
        $cols = implode(', ', array_values(array_intersect($old, $new)));
        $pdo->exec("INSERT INTO $tmp ($cols) SELECT $cols FROM $table");
        $pdo->exec("DROP TABLE $table");
        $pdo->exec("ALTER TABLE $tmp RENAME TO $table");
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    return true;
}
```

`ALTER TABLE ... RENAME` carries the AUTOINCREMENT counter over, because `sqlite_sequence` is keyed by table name and SQLite renames that entry too. The id assertion in Step 2 checks this.

- [ ] **Step 5: Run the test to verify it passes**

Run: `php phpunit.phar --filter DbRebuildTableTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add db.php tests/DbRebuildTableTest.php tests/support/legacy_schema.php tests/bootstrap.php
git commit -m "feat(db): guarded table rebuild helper and pre-ice schema fixture"
```

---

### Task 4: Tech sheets — nullable declaration, discipline and club

**Files:**
- Modify: `db.php`:
  - the `tech_sheets` CREATE in `db_init()` (~lines 129–177) becomes a constant plus a rebuild
  - `db_insert_tech_sheet()` ~line 733
  - `db_update_tech_sheet()` ~line 795
  - `db_tech_sheet_identity()` ~line 1311
  - `db_get_identity_sheets()` ~line 1352
  - `db_get_season_sheets()` ~line 1359
  - add `db_get_sheet_identity_sheets()`
- Modify: `admin-tech-sheets.php:18,72`, `tech-sheets.php:216,236`, `pretech-lib.php:49` (read the sheet's own discipline and club)
- Modify: `tests/bootstrap.php` (add `test_make_ice_sheet()`)
- Test: `tests/DbTechSheetsIceTest.php` (create)

**Interfaces:**
- Consumes: `seasonForEvent()` and `DISCIPLINE_*` (Task 2); `db_rebuild_table()` and `test_make_legacy_pdo()` (Task 3).
- Produces:
  - `tech_sheets.submission_id` is nullable; new columns `discipline` (TEXT NOT NULL DEFAULT 'summer') and `club` (TEXT)
  - `db_insert_tech_sheet(PDO, array $data): int` — for an ice event, `$data['car_id']` is required and `submission_id` must be empty
  - `db_tech_sheet_identity(PDO, string $carNumber, int $eventId): array{car_number_norm, season, discipline, club}`
  - `db_get_identity_sheets(PDO, int $carId, int $season, string $discipline = 'summer', ?string $club = null): array`
  - `db_get_sheet_identity_sheets(PDO, array $sheet): array` — all sheets sharing this sheet's car, season, discipline and club
  - `db_get_season_sheets(PDO, int $season, string $discipline = 'summer'): array`
  - test helper `test_make_ice_sheet(PDO, int $userId, int $carId, int $eventId, string $class = 'LS'): int`

- [ ] **Step 1: Add the test helper to `tests/bootstrap.php`**

```php
/** A submitted ice tech sheet (no declaration) for car $carId at ice event $eventId. */
function test_make_ice_sheet(PDO $pdo, int $userId, int $carId, int $eventId, string $class = 'LS'): int {
    return db_insert_tech_sheet($pdo, [
        'car_id' => $carId, 'user_id' => $userId, 'event_id' => $eventId, 'sheet_type' => 'ice',
        'entrant_name' => 'Test Driver', 'driver_name' => 'Test Driver', 'car_make' => 'Honda', 'car_model' => 'Civic',
        'car_colour' => 'Blue', 'car_number' => '7', 'class' => $class, 'engine_cc' => '1600', 'engine_hp' => '110',
        'car_weight' => 2300, 'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1,
    ]);
}
```

- [ ] **Step 2: Write the failing test**

```php
<?php
// wcma-calculator/tests/DbTechSheetsIceTest.php
use PHPUnit\Framework\TestCase;

final class DbTechSheetsIceTest extends TestCase
{
    private function user(PDO $pdo): int {
        return db_create_user($pdo, ['email' => 'ice' . uniqid() . '@example.com', 'name' => 'Ice Racer', 'password_hash' => 'x', 'google_id' => null]);
    }

    public function testIceSheetNeedsNoDeclarationAndGetsIceIdentity(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $car = test_make_car($pdo, $uid, '7');
        $event = db_create_event($pdo, 'NASCC Ice #1', '2026-12-12', null, 'ice', 'NASCC');

        $sheet = db_get_tech_sheet($pdo, test_make_ice_sheet($pdo, $uid, $car, $event));
        $this->assertNull($sheet['submission_id']);
        $this->assertSame($car, (int)$sheet['car_id']);
        $this->assertSame('ice', $sheet['discipline']);
        $this->assertSame('NASCC', $sheet['club']);
        $this->assertSame(2027, (int)$sheet['season']);
        $this->assertSame('LS', $sheet['class']);
    }

    public function testSummerSheetWithoutDeclarationIsStillRejected(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $car = test_make_car($pdo, $uid, '7');
        $event = db_create_event($pdo, 'Spring Sprint', '2026-05-10', null);
        $this->expectException(InvalidArgumentException::class);
        test_make_ice_sheet($pdo, $uid, $car, $event);   // summer event, no submission_id
    }

    public function testIceSheetRejectsADeclaration(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $uid, '7'));
        $event = db_create_event($pdo, 'NASCC Ice #1', '2026-12-12', null, 'ice', 'NASCC');
        $this->expectException(InvalidArgumentException::class);
        test_make_sheet($pdo, $uid, $sub, $event, '7');
    }

    public function testIceSheetRejectsSomeoneElsesCar(): void
    {
        $pdo = make_temp_pdo();
        $owner = $this->user($pdo);
        $other = $this->user($pdo);
        $car = test_make_car($pdo, $owner, '7');
        $event = db_create_event($pdo, 'NASCC Ice #1', '2026-12-12', null, 'ice', 'NASCC');
        $this->expectException(InvalidArgumentException::class);
        test_make_ice_sheet($pdo, $other, $car, $event);
    }

    public function testSummerAndIceSheetsSameYearDoNotMerge(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $uid, '7'));
        $car = test_make_car($pdo, $uid, '7');
        $summer = db_create_event($pdo, 'Fall Sprint', '2027-09-12', null);
        $ice = db_create_event($pdo, 'NASCC Ice #3', '2027-02-01', null, 'ice', 'NASCC');
        $s = test_make_sheet($pdo, $uid, $sub, $summer, '7');
        $i = test_make_ice_sheet($pdo, $uid, $car, $ice);

        $ids = fn(array $rows): array => array_map(fn($r) => (int)$r['id'], $rows);
        $this->assertSame([$s], $ids(db_get_identity_sheets($pdo, $car, 2027)));
        $this->assertSame([$i], $ids(db_get_identity_sheets($pdo, $car, 2027, 'ice', 'NASCC')));
        $this->assertSame([], db_get_identity_sheets($pdo, $car, 2027, 'ice', 'WSCC'));
        $this->assertSame([$s], $ids(db_get_season_sheets($pdo, 2027)));
        $this->assertSame([$i], $ids(db_get_season_sheets($pdo, 2027, 'ice')));
        $this->assertSame([$i], $ids(db_get_sheet_identity_sheets($pdo, db_get_tech_sheet($pdo, $i))));
        $this->assertSame([$s], $ids(db_get_sheet_identity_sheets($pdo, db_get_tech_sheet($pdo, $s))));
    }

    public function testNasccAndWsccSheetsDoNotMerge(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $car = test_make_car($pdo, $uid, '7');
        $n = test_make_ice_sheet($pdo, $uid, $car, db_create_event($pdo, 'NASCC', '2027-01-10', null, 'ice', 'NASCC'));
        $w = test_make_ice_sheet($pdo, $uid, $car, db_create_event($pdo, 'WSCC', '2027-01-18', null, 'ice', 'WSCC'), 'FOI-STD');
        $this->assertNotSame(techCarKey(db_get_tech_sheet($pdo, $n)), techCarKey(db_get_tech_sheet($pdo, $w)));
    }

    public function testSheetCannotMoveBetweenDisciplines(): void
    {
        $pdo = make_temp_pdo();
        $uid = $this->user($pdo);
        $car = test_make_car($pdo, $uid, '7');
        $ice = db_create_event($pdo, 'NASCC Ice #1', '2026-12-12', null, 'ice', 'NASCC');
        $summer = db_create_event($pdo, 'Spring Sprint', '2027-05-10', null);
        $id = test_make_ice_sheet($pdo, $uid, $car, $ice);
        $data = db_get_tech_sheet($pdo, $id);
        $data['event_id'] = $summer;
        $this->expectException(InvalidArgumentException::class);
        db_update_tech_sheet($pdo, $id, $data);
    }

    public function testLegacyDatabaseMigratesTechSheetsAsSummer(): void
    {
        $pdo = test_make_legacy_pdo();
        $pdo->exec("INSERT INTO events (name, event_date, created_at) VALUES ('Old', '2026-05-10', '2026-01-01')");
        $pdo->exec("INSERT INTO tech_sheets (submission_id, car_id, user_id, event_id, sheet_type, entrant_name, driver_name,
            car_make, car_model, car_colour, car_number, class, car_weight, checklist_json, driver1_equipment_json,
            status, accepted_via, season, created_at, updated_at)
            VALUES (9, 3, 1, 1, 'standard', 'A', 'A', 'Mazda', 'MX-5', 'Red', '42', 'IT1', 2200, '{}', '{}',
            'teched', 'in_person', 2026, '2026-05-10', '2026-05-10')");

        db_init($pdo);
        db_init($pdo);   // second run does nothing

        $rows = $pdo->query("SELECT * FROM tech_sheets")->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame(9, (int)$rows[0]['submission_id']);
        $this->assertSame('summer', $rows[0]['discipline']);
        $this->assertNull($rows[0]['club']);
        $this->assertSame('accepted', techCarStatus(db_get_identity_sheets($pdo, 3, 2026))['state']);
        $this->assertSame('summer', db_get_event($pdo, 1)['discipline']);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php phpunit.phar --filter DbTechSheetsIceTest`
Expected: FAIL. You should see a NOT NULL constraint failure on `submission_id`, or the "needs an existing class declaration" exception, or an undefined `db_get_sheet_identity_sheets`.

- [ ] **Step 4: Replace the `tech_sheets` CREATE with a constant and a rebuild**

Above `db_init()` in `db.php`, add the constant. It is the current column list, with `submission_id` made nullable and the two new columns added:

```php
/** tech_sheets schema. {table} is filled in by db_init() and db_rebuild_table(). */
const DB_TECH_SHEETS_SQL = "
    CREATE TABLE IF NOT EXISTS {table} (
        id                      INTEGER PRIMARY KEY AUTOINCREMENT,
        submission_id           INTEGER,
        car_id                  INTEGER NOT NULL,
        user_id                 INTEGER NOT NULL,
        event_id                INTEGER NOT NULL,
        sheet_type              TEXT NOT NULL,

        entrant_name            TEXT NOT NULL,
        driver_name             TEXT NOT NULL,
        driver_id               INTEGER,
        car_make                TEXT NOT NULL,
        car_model               TEXT NOT NULL,
        car_colour              TEXT NOT NULL,
        car_number              TEXT NOT NULL,
        class                   TEXT NOT NULL,
        engine_cc               TEXT,
        engine_hp               TEXT,
        car_weight              INTEGER NOT NULL,

        checklist_json          TEXT NOT NULL,
        driver1_equipment_json  TEXT NOT NULL,
        log_book_turned_in      INTEGER,

        entrant_signature_path  TEXT,
        entrant_signed_at       DATETIME,
        driver_signature_path   TEXT,
        driver_signed_at        DATETIME,
        tech_signature_path     TEXT,
        tech_signed_at          DATETIME,

        status                  TEXT NOT NULL DEFAULT 'submitted',
        reviewed_by_user_id     INTEGER,
        reviewed_at             DATETIME,

        email_sent              INTEGER DEFAULT 0,
        email_send_count        INTEGER NOT NULL DEFAULT 0,
        last_emailed_at         DATETIME,

        accepted_via            TEXT,
        photo_status            TEXT,
        car_number_norm         TEXT,
        season                  INTEGER,
        discipline              TEXT NOT NULL DEFAULT 'summer',
        club                    TEXT,

        created_at              DATETIME NOT NULL,
        updated_at              DATETIME NOT NULL
    )";
```

In `db_init()`, replace the whole `$pdo->exec(" CREATE TABLE IF NOT EXISTS tech_sheets ( ... ) ");` block with:

```php
    $pdo->exec(str_replace('{table}', 'tech_sheets', DB_TECH_SHEETS_SQL));
    // Pre-ice databases: submission_id was NOT NULL and there was no discipline/club (2026-09-27 spec).
    db_rebuild_table($pdo, 'tech_sheets', 'discipline', DB_TECH_SHEETS_SQL);
```

The existing `CREATE INDEX IF NOT EXISTS idx_tech_sheets_car ...` line further down recreates the index after a rebuild. Leave it where it is.

- [ ] **Step 5: Update the identity and insert/update functions**

Replace `db_tech_sheet_identity()`:

```php
/** Normalised car number and season key (season, discipline, club) of the sheet's event. */
function db_tech_sheet_identity(PDO $pdo, string $carNumber, int $eventId): array {
    $key = seasonForEvent(db_get_event($pdo, $eventId));
    return [
        'car_number_norm' => techCarNumberNorm($carNumber),
        'season' => $key['season'], 'discipline' => $key['discipline'], 'club' => $key['club'],
    ];
}
```

In `db_insert_tech_sheet()`, replace the lines from `$identity = ...` through the `throw` block with:

```php
    $identity = db_tech_sheet_identity($pdo, (string)$data['car_number'], (int)$data['event_id']);
    if ($identity['discipline'] === DISCIPLINE_ICE) {
        if (!empty($data['submission_id'])) {
            throw new InvalidArgumentException('An ice tech sheet does not take a class declaration.');
        }
        $car = db_get_user_car($pdo, (int)$data['user_id'], (int)($data['car_id'] ?? 0));
        if ($car === null) {
            throw new InvalidArgumentException('An ice tech sheet needs one of your cars.');
        }
        $submissionId = null;
        $carId = (int)$car['id'];
    } else {
        $submission = db_get_submission($pdo, (int)($data['submission_id'] ?? 0));
        if ($submission === null) {
            throw new InvalidArgumentException('A tech sheet needs an existing class declaration.');
        }
        $submissionId = (int)$submission['id'];
        $carId = (int)$submission['car_id'];
    }
```

In the same function's INSERT:
- Add `discipline, club,` after `car_number_norm, season,` in the column list.
- Add `:discipline, :club,` after `:car_number_norm, :season,` in VALUES.
- In the execute array, replace `':submission_id' => $data['submission_id'], ':car_id' => (int)$submission['car_id'],` with `':submission_id' => $submissionId, ':car_id' => $carId,`.
- Add `':discipline' => $identity['discipline'], ':club' => $identity['club'],` after the `':season' => $identity['season'],` entry.

In `db_update_tech_sheet()`, replace the first two lines (`$identity = ...; $owner = ...;`) with:

```php
    $current = db_get_tech_sheet($pdo, $id);
    $identity = db_tech_sheet_identity($pdo, (string)$data['car_number'], (int)$data['event_id']);
    if ($current !== null && ($current['discipline'] ?? DISCIPLINE_SUMMER) !== $identity['discipline']) {
        throw new InvalidArgumentException('A tech sheet cannot move between summer and ice events.');
    }
    $owner = (int)($current['user_id'] ?? 0);
```

In its UPDATE:
- Add `club = :club,` after `season = :season,`.
- Add `':club' => $identity['club'],` to the execute array.
- Discipline cannot change, so it is not updated.

- [ ] **Step 6: Update the lookups**

Replace `db_get_identity_sheets()` and `db_get_season_sheets()`, and add `db_get_sheet_identity_sheets()`:

```php
/** All sheets for one car in one season (and discipline, and club for ice). */
function db_get_identity_sheets(PDO $pdo, int $carId, int $season, string $discipline = DISCIPLINE_SUMMER, ?string $club = null): array {
    $stmt = $pdo->prepare("
        SELECT * FROM tech_sheets
        WHERE car_id = :c AND season = :s AND discipline = :d AND club IS :club
        ORDER BY id ASC
    ");
    $stmt->execute([':c' => $carId, ':s' => $season, ':d' => $discipline, ':club' => $club]);
    return $stmt->fetchAll();
}

/** Every sheet that shares $sheet's car and season key: the sheets that decide its car's status. */
function db_get_sheet_identity_sheets(PDO $pdo, array $sheet): array {
    return db_get_identity_sheets($pdo, (int)$sheet['car_id'], (int)$sheet['season'],
        (string)($sheet['discipline'] ?? DISCIPLINE_SUMMER), $sheet['club'] ?? null);
}

/** Every tech sheet in a season of one discipline (used to derive each car's status on a roster). */
function db_get_season_sheets(PDO $pdo, int $season, string $discipline = DISCIPLINE_SUMMER): array {
    $stmt = $pdo->prepare("SELECT * FROM tech_sheets WHERE season = :s AND discipline = :d ORDER BY id ASC");
    $stmt->execute([':s' => $season, ':d' => $discipline]);
    return $stmt->fetchAll();
}
```

Now switch the three sheet-based callers to `db_get_sheet_identity_sheets($pdo, $sheet)`:
- `admin-tech-sheets.php:18` → `$carStatus = techCarStatus(db_get_sheet_identity_sheets($pdo, $sheet));`
- `tech-sheets.php:236` → `$identity = db_get_sheet_identity_sheets($pdo, $sheet);`
- `pretech-lib.php:49` → `$identity = db_get_sheet_identity_sheets($pdo, $sheet);`

Then pass the discipline to the two sheet-based labels:
- `admin-tech-sheets.php:72` → `$statusLabel = techCarStatusLabel($carStatus, (int)$sheet['season'], (string)($sheet['discipline'] ?? 'summer'));`
- `tech-sheets.php:216` → change the call to `techCarStatusLabel($carStatus, (int)($sheet['season'] ?? date('Y')), (string)($sheet['discipline'] ?? 'summer'))`.

Also check whether `tech-sheets.php` builds `$carStatus` for line 216 with `db_get_identity_sheets(...)`. If it does, switch that call to `db_get_sheet_identity_sheets($pdo, $sheet)` too. Run `grep -n "db_get_identity_sheets" *.php`: afterwards the only remaining hits should be `db.php` and `index.php`. `index.php` is summer-only until Phase 4.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php phpunit.phar --filter DbTechSheetsIceTest`
Expected: PASS (8 tests).

Run: `php phpunit.phar`
Expected: the full suite PASSES.

- [ ] **Step 8: Commit**

```bash
git add db.php admin-tech-sheets.php tech-sheets.php pretech-lib.php tests/bootstrap.php tests/DbTechSheetsIceTest.php
git commit -m "feat(ice): tech sheets carry discipline and club; ice sheets need no declaration"
```

---

### Task 5: Gear records — discipline and level

**Files:**
- Modify: `db.php`:
  - the `gear_records` CREATE in `db_init()` (~lines 236–250) becomes a constant plus a rebuild
  - `db_get_gear_record_for_driver()` ~line 724
  - `db_insert_gear_record()`, `db_find_gear_record()`, `db_get_gear_records_for_season()` ~lines 1467–1504
  - add `db_set_gear_level()`
- Test: `tests/DbGearIceTest.php` (create)

**Interfaces:**
- Consumes: `db_rebuild_table()` and `test_make_legacy_pdo()` (Task 3); `DISCIPLINE_*` (Task 2).
- Produces:
  - `gear_records.discipline` (TEXT NOT NULL DEFAULT 'summer') and `gear_records.level` (TEXT NULL); UNIQUE `(driver_id, discipline, season)`
  - `db_insert_gear_record(PDO, int $driverId, int $season, string $discipline = 'summer'): int`
  - `db_get_gear_record_for_driver(PDO, int $driverId, int $season, string $discipline = 'summer'): ?array`
  - `db_find_gear_record(PDO, int $ownerId, string $driverNameNorm, int $season, string $discipline = 'summer'): ?array`
  - `db_get_gear_records_for_season(PDO, int $season, string $discipline = 'summer'): array`
  - `db_set_gear_level(PDO, int $id, ?string $level): void` — throws `InvalidArgumentException` for anything other than `street_safe`, `caged` or null

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/DbGearIceTest.php
use PHPUnit\Framework\TestCase;

final class DbGearIceTest extends TestCase
{
    private function owner(PDO $pdo): int {
        return db_create_user($pdo, ['email' => 'g' . uniqid() . '@example.com', 'name' => 'Owner', 'password_hash' => 'x', 'google_id' => null]);
    }

    public function testSummerAndIceGearCoexistForTheSameSeason(): void
    {
        $pdo = make_temp_pdo();
        $d = db_create_driver($pdo, $this->owner($pdo), 'Sam');
        $summer = db_insert_gear_record($pdo, $d, 2027);
        $ice = db_insert_gear_record($pdo, $d, 2027, 'ice');
        $this->assertNotSame($summer, $ice);
        $this->assertSame($summer, (int)db_get_gear_record_for_driver($pdo, $d, 2027)['id']);
        $this->assertSame($ice, (int)db_get_gear_record_for_driver($pdo, $d, 2027, 'ice')['id']);
        $this->assertSame('ice', db_get_gear_record($pdo, $ice)['discipline']);
    }

    public function testGearIsUniquePerDiscipline(): void
    {
        $pdo = make_temp_pdo();
        $d = db_create_driver($pdo, $this->owner($pdo), 'Sam');
        db_insert_gear_record($pdo, $d, 2027, 'ice');
        $this->expectException(PDOException::class);
        db_insert_gear_record($pdo, $d, 2027, 'ice');
    }

    public function testFindAndSeasonListingFilterByDiscipline(): void
    {
        $pdo = make_temp_pdo();
        $owner = $this->owner($pdo);
        $d = db_create_driver($pdo, $owner, 'Sam');
        $ice = db_insert_gear_record($pdo, $d, 2027, 'ice');
        $this->assertNull(db_find_gear_record($pdo, $owner, db_driver_name_norm('Sam'), 2027));
        $this->assertSame($ice, (int)db_find_gear_record($pdo, $owner, db_driver_name_norm('Sam'), 2027, 'ice')['id']);
        $this->assertSame([], db_get_gear_records_for_season($pdo, 2027));
        $this->assertCount(1, db_get_gear_records_for_season($pdo, 2027, 'ice'));
    }

    public function testSetGearLevel(): void
    {
        $pdo = make_temp_pdo();
        $id = db_insert_gear_record($pdo, db_create_driver($pdo, $this->owner($pdo), 'Sam'), 2027, 'ice');
        $this->assertNull(db_get_gear_record($pdo, $id)['level']);
        db_set_gear_level($pdo, $id, 'street_safe');
        $this->assertSame('street_safe', db_get_gear_record($pdo, $id)['level']);
        db_set_gear_level($pdo, $id, null);
        $this->assertNull(db_get_gear_record($pdo, $id)['level']);
        $this->expectException(InvalidArgumentException::class);
        db_set_gear_level($pdo, $id, 'bogus');
    }

    public function testLegacyGearMigratesAsSummer(): void
    {
        $pdo = test_make_legacy_pdo();
        $pdo->exec("INSERT INTO gear_records (driver_id, season, status, accepted_via, created_at, updated_at)
                    VALUES (5, 2026, 'accepted', 'in_person', '2026-05-10', '2026-05-10')");
        db_init($pdo);
        db_init($pdo);
        $rows = $pdo->query("SELECT * FROM gear_records")->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame('summer', $rows[0]['discipline']);
        $this->assertSame('accepted', $rows[0]['status']);
        $this->assertNull($rows[0]['level']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php phpunit.phar --filter DbGearIceTest`
Expected: FAIL. The UNIQUE constraint rejects the ice record for the same season, or the `discipline` column is missing.

- [ ] **Step 3: Replace the `gear_records` CREATE with a constant and a rebuild**

Add above `db_init()`:

```php
/** gear_records schema. {table} is filled in by db_init() and db_rebuild_table(). */
const DB_GEAR_RECORDS_SQL = "
    CREATE TABLE IF NOT EXISTS {table} (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        driver_id           INTEGER NOT NULL,
        season              INTEGER NOT NULL,
        discipline          TEXT NOT NULL DEFAULT 'summer',
        level               TEXT,
        photo_status        TEXT,
        status              TEXT NOT NULL DEFAULT 'open',
        accepted_via        TEXT,
        reviewed_by_user_id INTEGER,
        reviewed_at         DATETIME,
        created_at          DATETIME NOT NULL,
        updated_at          DATETIME NOT NULL,
        UNIQUE (driver_id, discipline, season)
    )";
```

In `db_init()`, replace the `gear_records` CREATE block with:

```php
    $pdo->exec(str_replace('{table}', 'gear_records', DB_GEAR_RECORDS_SQL));
    // Pre-ice databases: UNIQUE (driver_id, season), no discipline/level (2026-09-27 spec).
    db_rebuild_table($pdo, 'gear_records', 'discipline', DB_GEAR_RECORDS_SQL);
```

- [ ] **Step 4: Update the gear functions**

```php
function db_get_gear_record_for_driver(PDO $pdo, int $driverId, int $season, string $discipline = DISCIPLINE_SUMMER): ?array {
    $stmt = $pdo->prepare(DB_GEAR_SELECT . " WHERE g.driver_id = :d AND g.season = :s AND g.discipline = :disc");
    $stmt->execute([':d' => $driverId, ':s' => $season, ':disc' => $discipline]);
    return $stmt->fetch() ?: null;
}
```

```php
function db_insert_gear_record(PDO $pdo, int $driverId, int $season, string $discipline = DISCIPLINE_SUMMER): int {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO gear_records (driver_id, season, discipline, created_at, updated_at) VALUES (:d, :s, :disc, :now, :now)")
        ->execute([':d' => $driverId, ':s' => $season, ':disc' => $discipline, ':now' => $now]);
    return (int)$pdo->lastInsertId();
}
```

```php
function db_find_gear_record(PDO $pdo, int $ownerId, string $driverNameNorm, int $season, string $discipline = DISCIPLINE_SUMMER): ?array {
    $stmt = $pdo->prepare(DB_GEAR_SELECT . " WHERE d.owner_user_id = :o AND d.name_norm = :n AND g.season = :s AND g.discipline = :disc");
    $stmt->execute([':o' => $ownerId, ':n' => $driverNameNorm, ':s' => $season, ':disc' => $discipline]);
    return $stmt->fetch() ?: null;
}
```

In `db_get_gear_records_for_season()`:
- Change the signature to `(PDO $pdo, int $season, string $discipline = DISCIPLINE_SUMMER)`.
- Change `WHERE g.season = :s` to `WHERE g.season = :s AND g.discipline = :disc`.
- Change the execute to `[':s' => $season, ':disc' => $discipline]`.

Add after `db_revoke_gear_acceptance()`:

```php
/** The ice gear level an inspector confirmed: 'street_safe', 'caged', or null to clear it. */
function db_set_gear_level(PDO $pdo, int $id, ?string $level): void {
    if ($level !== null && !in_array($level, ['street_safe', 'caged'], true)) {
        throw new InvalidArgumentException('Unknown gear level: ' . $level);
    }
    $pdo->prepare("UPDATE gear_records SET level = :l, updated_at = :now WHERE id = :id")
        ->execute([':l' => $level, ':now' => date('Y-m-d H:i:s'), ':id' => $id]);
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php phpunit.phar --filter DbGearIceTest`
Expected: PASS (5 tests).

Run: `php phpunit.phar`
Expected: the full suite PASSES. `gearCreate()` and the other summer callers use the defaults.

- [ ] **Step 6: Commit**

```bash
git add db.php tests/DbGearIceTest.php
git commit -m "feat(ice): gear records carry discipline and an ice gear level"
```

---

### Task 6: At-track choices — discipline and club

**Files:**
- Modify: `tech-status.php` (add `atTrackKey()`)
- Modify: `db.php`:
  - the `at_track_choices` CREATE (~line 295) becomes a constant plus a rebuild
  - `db_set_at_track()` and `db_get_at_track_keys()` ~lines 703–722
- Modify: `events-lib.php` (`eventsSetAtTrack()` passes discipline and club through)
- Test: `tests/AtTrackIceTest.php` (create)

**Interfaces:**
- Consumes: `db_rebuild_table()` and `test_make_legacy_pdo()` (Task 3); `iceClubCodes()` (Task 1); `DISCIPLINE_*` (Task 2).
- Produces:
  - `at_track_choices.discipline` (TEXT NOT NULL DEFAULT 'summer') and `at_track_choices.club` (TEXT NOT NULL DEFAULT ''); UNIQUE `(subject_type, subject_id, discipline, club, season)`
  - `atTrackKey(string $type, int $id, int $season, string $discipline = 'summer', string $club = ''): string`:
    - summer: `"car:5@2026"`
    - ice car: `"car:5@ice:NASCC:2027"`
    - ice driver: `"driver:9@ice:2027"`
  - `db_set_at_track(PDO, string $type, int $id, int $season, string $discipline = 'summer', string $club = ''): void`
  - `db_get_at_track_keys(PDO, array $carIds, array $driverIds, int $season, string $discipline = 'summer'): string[]` — keys for every club in that discipline
  - `eventsSetAtTrack(PDO, int $userId, string $subjectType, int $subjectId, int $season, string $discipline = 'summer', string $club = ''): array{ok, error}`

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/AtTrackIceTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../events-lib.php';
require_once __DIR__ . '/../ice-rules.php';

final class AtTrackIceTest extends TestCase
{
    /** @return array{0: PDO, 1: int, 2: int, 3: int} pdo, user, car, driver */
    private function setUpSubjects(): array {
        $pdo = make_temp_pdo();
        $uid = db_create_user($pdo, ['email' => 'a' . uniqid() . '@example.com', 'name' => 'Owner', 'password_hash' => 'x', 'google_id' => null]);
        $car = test_make_car($pdo, $uid, '7');
        $driver = db_create_driver($pdo, $uid, 'Sam');
        return [$pdo, $uid, $car, $driver];
    }

    public function testKeyFormats(): void
    {
        $this->assertSame('car:5@2026', atTrackKey('car', 5, 2026));
        $this->assertSame('car:5@ice:NASCC:2027', atTrackKey('car', 5, 2027, 'ice', 'NASCC'));
        $this->assertSame('driver:9@ice:2027', atTrackKey('driver', 9, 2027, 'ice'));
    }

    public function testSummerAtTrackStillDedupes(): void
    {
        [$pdo, , $car] = $this->setUpSubjects();
        db_set_at_track($pdo, 'car', $car, 2026);
        db_set_at_track($pdo, 'car', $car, 2026);
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM at_track_choices")->fetchColumn());
        $this->assertSame(["car:$car@2026"], db_get_at_track_keys($pdo, [$car], [], 2026));
    }

    public function testIceChoicesAreKeptApartFromSummerAndBetweenClubs(): void
    {
        [$pdo, , $car, $driver] = $this->setUpSubjects();
        db_set_at_track($pdo, 'car', $car, 2027, 'ice', 'NASCC');
        db_set_at_track($pdo, 'driver', $driver, 2027, 'ice');
        $this->assertSame([], db_get_at_track_keys($pdo, [$car], [$driver], 2027));
        $this->assertEqualsCanonicalizing(["car:$car@ice:NASCC:2027", "driver:$driver@ice:2027"],
            db_get_at_track_keys($pdo, [$car], [$driver], 2027, 'ice'));
        $this->assertNotContains(atTrackKey('car', $car, 2027, 'ice', 'WSCC'), db_get_at_track_keys($pdo, [$car], [], 2027, 'ice'));
    }

    public function testEventsSetAtTrackValidatesDisciplineAndClub(): void
    {
        [$pdo, $uid, $car] = $this->setUpSubjects();
        $this->assertTrue(eventsSetAtTrack($pdo, $uid, 'car', $car, 2027, 'ice', 'WSCC')['ok']);
        $this->assertFalse(eventsSetAtTrack($pdo, $uid, 'car', $car, 2027, 'ice', 'XYZ')['ok']);
        $this->assertFalse(eventsSetAtTrack($pdo, $uid, 'car', $car, 2027, 'ice', '')['ok']);
        $this->assertFalse(eventsSetAtTrack($pdo, $uid, 'car', $car, 2027, 'rally', '')['ok']);
        $this->assertTrue(eventsSetAtTrack($pdo, $uid, 'car', $car, 2026)['ok']);
    }

    public function testLegacyAtTrackMigratesAsSummer(): void
    {
        $pdo = test_make_legacy_pdo();
        $pdo->exec("INSERT INTO at_track_choices (subject_type, subject_id, season, created_at) VALUES ('car', 3, 2026, '2026-05-01')");
        db_init($pdo);
        db_init($pdo);
        $this->assertSame(['car:3@2026'], db_get_at_track_keys($pdo, [3], [], 2026));
        db_set_at_track($pdo, 'car', 3, 2026);   // still de-duplicated after the migration
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM at_track_choices")->fetchColumn());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php phpunit.phar --filter AtTrackIceTest`
Expected: FAIL with "Call to undefined function atTrackKey()".

- [ ] **Step 3: Implement it**

Add to `tech-status.php`:

```php
/** Key for an "I'll do it at the track" choice. Summer keys keep their original "type:id@season" form. */
function atTrackKey(string $type, int $id, int $season, string $discipline = DISCIPLINE_SUMMER, string $club = ''): string {
    if ($discipline !== DISCIPLINE_ICE) return "$type:$id@$season";
    return $club === '' ? "$type:$id@ice:$season" : "$type:$id@ice:$club:$season";
}
```

In `db.php`, add above `db_init()`:

```php
/** at_track_choices schema. club is '' (not NULL) so UNIQUE still de-duplicates summer and gear rows. */
const DB_AT_TRACK_SQL = "
    CREATE TABLE IF NOT EXISTS {table} (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        subject_type TEXT NOT NULL,
        subject_id   INTEGER NOT NULL,
        season       INTEGER NOT NULL,
        discipline   TEXT NOT NULL DEFAULT 'summer',
        club         TEXT NOT NULL DEFAULT '',
        created_at   DATETIME NOT NULL,
        UNIQUE (subject_type, subject_id, discipline, club, season)
    )";
```

Replace the `at_track_choices` CREATE block in `db_init()` with:

```php
    $pdo->exec(str_replace('{table}', 'at_track_choices', DB_AT_TRACK_SQL));
    db_rebuild_table($pdo, 'at_track_choices', 'discipline', DB_AT_TRACK_SQL);
```

Replace `db_set_at_track()` and `db_get_at_track_keys()`:

```php
function db_set_at_track(PDO $pdo, string $subjectType, int $subjectId, int $season,
                         string $discipline = DISCIPLINE_SUMMER, string $club = ''): void {
    $pdo->prepare("
        INSERT OR IGNORE INTO at_track_choices (subject_type, subject_id, season, discipline, club, created_at)
        VALUES (:t, :s, :y, :d, :c, :now)
    ")->execute([':t' => $subjectType, ':s' => $subjectId, ':y' => $season, ':d' => $discipline, ':c' => $club,
                 ':now' => date('Y-m-d H:i:s')]);
}

/** atTrackKey() keys for the given subjects that chose "I'll do it at the track" in this season and discipline. */
function db_get_at_track_keys(PDO $pdo, array $carIds, array $driverIds, int $season, string $discipline = DISCIPLINE_SUMMER): array {
    $keys = [];
    $stmt = $pdo->prepare("SELECT subject_type, subject_id, club FROM at_track_choices WHERE season = :y AND discipline = :d");
    $stmt->execute([':y' => $season, ':d' => $discipline]);
    $cars = array_flip(array_map('intval', $carIds));
    $drivers = array_flip(array_map('intval', $driverIds));
    foreach ($stmt->fetchAll() as $r) {
        $id = (int)$r['subject_id'];
        if (($r['subject_type'] === 'car' && isset($cars[$id])) || ($r['subject_type'] === 'driver' && isset($drivers[$id]))) {
            $keys[] = atTrackKey($r['subject_type'], $id, $season, $discipline, (string)$r['club']);
        }
    }
    return $keys;
}
```

In `events-lib.php`:
- Add `require_once __DIR__ . '/ice-rules.php';` after the header comment.
- Replace `eventsSetAtTrack()`:

```php
/** "I'll do it at the track": planning only, it never accepts anything. @return array{ok: bool, error: ?string} */
function eventsSetAtTrack(PDO $pdo, int $userId, string $subjectType, int $subjectId, int $season,
                          string $discipline = DISCIPLINE_SUMMER, string $club = ''): array {
    if ($discipline === DISCIPLINE_SUMMER) {
        $club = '';
    } elseif ($discipline !== DISCIPLINE_ICE) {
        return ['ok' => false, 'error' => 'Unknown item.'];
    } elseif ($subjectType === 'car' && !in_array($club, iceClubCodes(), true)) {
        return ['ok' => false, 'error' => 'Unknown item.'];
    } elseif ($subjectType === 'driver') {
        $club = '';   // ice gear covers both clubs
    }
    if ($subjectType === 'car') {
        $owned = db_get_user_car($pdo, $userId, $subjectId) !== null;
    } elseif ($subjectType === 'driver') {
        $driver = db_get_driver($pdo, $subjectId);
        $owned = $driver !== null && (int)$driver['owner_user_id'] === $userId;
    } else {
        return ['ok' => false, 'error' => 'Unknown item.'];
    }
    if (!$owned) return ['ok' => false, 'error' => 'Unknown item.'];
    db_set_at_track($pdo, $subjectType, $subjectId, $season, $discipline, $club);
    return ['ok' => true, 'error' => null];
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar --filter "AtTrackIceTest|EventsLibTest|ReadinessLoaderTest|ReadinessTest"`
Expected: PASS. Summer at-track keys are unchanged, so the readiness tests still pass.

Run: `php phpunit.phar`
Expected: the full suite PASSES.

- [ ] **Step 5: Commit**

```bash
git add tech-status.php db.php events-lib.php tests/AtTrackIceTest.php
git commit -m "feat(ice): at-track choices keyed by discipline and club"
```

---

### Task 7: Whole-database migration check and seed data

**Files:**
- Modify: `hub-db-tools.php` (seed one NASCC ice event and one WSCC ice event next to the existing `db_create_event` calls ~line 49)
- Test: `tests/IceMigrationTest.php` (create); `tests/HubDbToolsTest.php` (add a test)

**Interfaces:**
- Consumes: everything from Tasks 2–6.
- Produces: seed data with ice events for manual testing in Phases 2–4.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// wcma-calculator/tests/IceMigrationTest.php
use PHPUnit\Framework\TestCase;

final class IceMigrationTest extends TestCase
{
    public function testFullLegacyDatabaseUpgradesAndSummerStatusIsUnchanged(): void
    {
        $pdo = test_make_legacy_pdo();
        $pdo->exec("INSERT INTO events (name, event_date, created_at) VALUES ('Old', '2026-05-10', '2026-01-01')");
        $pdo->exec("INSERT INTO tech_sheets (submission_id, car_id, user_id, event_id, sheet_type, entrant_name, driver_name,
            car_make, car_model, car_colour, car_number, class, car_weight, checklist_json, driver1_equipment_json,
            status, photo_status, season, created_at, updated_at)
            VALUES (9, 3, 1, 1, 'standard', 'A', 'A', 'Mazda', 'MX-5', 'Red', '42', 'IT1', 2200, '{}', '{}',
            'submitted', 'submitted', 2026, '2026-05-10', '2026-05-10')");
        $pdo->exec("INSERT INTO gear_records (driver_id, season, created_at, updated_at) VALUES (5, 2026, '2026-05-10', '2026-05-10')");
        $pdo->exec("INSERT INTO at_track_choices (subject_type, subject_id, season, created_at) VALUES ('driver', 5, 2026, '2026-05-01')");

        $before = techCarStatus($pdo->query("SELECT * FROM tech_sheets")->fetchAll());
        db_init($pdo);
        db_init($pdo);

        foreach (['tech_sheets', 'gear_records', 'at_track_choices', 'events'] as $table) {
            $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn(), $table);
            $this->assertSame('summer', $pdo->query("SELECT discipline FROM $table")->fetchColumn(), $table);
        }
        $this->assertSame($before, techCarStatus(db_get_identity_sheets($pdo, 3, 2026)));
        $this->assertSame(['driver:5@2026'], db_get_at_track_keys($pdo, [], [5], 2026));
        $this->assertNotNull(db_get_gear_record_for_driver($pdo, 5, 2026));
        foreach (['tech_sheets__rebuild', 'gear_records__rebuild', 'at_track_choices__rebuild'] as $tmp) {
            $this->assertFalse(db_has_column($pdo, $tmp, 'id'), "$tmp left behind");
        }
    }

    public function testFreshDatabaseHasNewSchemaWithoutRebuilding(): void
    {
        $pdo = make_temp_pdo();
        foreach (['tech_sheets', 'gear_records', 'at_track_choices', 'events'] as $table) {
            $this->assertTrue(db_has_column($pdo, $table, 'discipline'), $table);
        }
        $this->assertFalse(db_rebuild_table($pdo, 'tech_sheets', 'discipline', DB_TECH_SHEETS_SQL));
    }
}
```

Add to `tests/HubDbToolsTest.php`:

```php
    public function testSeedIncludesOneIceEventPerClub(): void
    {
        $pdo = make_temp_pdo();
        hubSeed($pdo, 'password123');
        $ice = array_values(array_filter(db_get_all_events($pdo), fn(array $e): bool => $e['discipline'] === 'ice'));
        $this->assertEqualsCanonicalizing(['NASCC', 'WSCC'], array_column($ice, 'host_club'));
    }
```

- [ ] **Step 2: Run the tests to verify which fail**

Run: `php phpunit.phar --filter "IceMigrationTest|HubDbToolsTest"`
Expected: `IceMigrationTest` PASSES, because Tasks 2–6 already did the work and this test locks it in. `testSeedIncludesOneIceEventPerClub` FAILS because no ice events are seeded yet.

- [ ] **Step 3: Seed the ice events**

In `hub-db-tools.php`, after the `db_create_event($pdo, 'Season Finale', ...)` line, add:

```php
    db_create_event($pdo, 'NASCC Ice Race #1', date('Y-m-d', strtotime('+45 days')), 'Lake Wabamun', 'ice', 'NASCC');
    db_create_event($pdo, 'WSCC Fire on Ice #1', date('Y-m-d', strtotime('+52 days')), 'Lake Shirley', 'ice', 'WSCC');
```

If any existing `HubDbToolsTest` assertion counts seeded events exactly, update that count by +2 in the same commit.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar`
Expected: the full suite PASSES.

- [ ] **Step 5: Manual smoke check**

1. Copy the live-shaped database, or use a local one with summer data: `cp data/submissions.db /tmp/pre-ice.db` (skip this if there is no local database).
2. Load `index.php` in a browser through the usual local server. `db_init` runs and migrates.
3. Open **Admin → Events**. Add "Test Ice" with discipline Ice and club NASCC. It should list with an "Ice · NASCC" badge.
4. Try adding an Ice event with no club. You should see the flash message "Choose the host club for an ice event."
5. Open an existing summer tech sheet and a Garage card. Their status text should be unchanged.

- [ ] **Step 6: Commit**

```bash
git add hub-db-tools.php tests/IceMigrationTest.php tests/HubDbToolsTest.php
git commit -m "test(ice): whole-database upgrade check; seed ice events"
```

---

## Self-review notes

- **Spec coverage (Phase 1 scope):**
  - §1 rules data (classes, groups, checklists, overrides, FHR flag): Task 1. The photo requirements in §1 are Phase 3.
  - §2 seasons: Task 2. Events: Task 2. tech_sheets: Task 4. gear_records: Task 5. at_track_choices: Task 6. Status keys: Tasks 2 and 4.
  - §5 admin: Task 2.
  - §6 migration tests: Tasks 4–7.
  - §3, §4 and §4a UI and readiness are Phases 2–4.
- **Deliberate deviations from the spec text:**
  - Summer `techCarKey` keeps `car|season` rather than `car|summer||season`. It is equivalent, and existing keys and tests stay stable.
  - `at_track_choices.club` is `''` not NULL, so UNIQUE still de-duplicates.
  - A club override of `null` omits a checklist item (WSCC has no catch-tank or ABS rule).
- **The admin form shows the Host club select for every event** instead of revealing it only for Ice. The server ignores the club for summer events. This avoids adding JS for one field.
