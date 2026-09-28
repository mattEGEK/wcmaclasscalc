# Ice Racing Phase 4b: Remaining Screens Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every remaining competitor, inspector and media screen understands ice:

- **Garage cards** show an ice chip, and an ice-only car isn't asked to declare a class.
- **The Garage car page** tags ice events and handles them in its Events section. This replaces the separate "Ice racing" section.
- **Home "At a glance"** shows ice car tech and ice gear.
- **The Drivers page** shows each driver's ice gear, with the summer carry-over.
- **The admin Gear tab** filters by summer or ice.
- **Media pages** use each event's own sheets and classes.
- **The ice form** shows when a frontal head restraint is required.

**Architecture:** Pure helpers do the decisions, and pages only wire data:

- `garage-lib.php`: `garageIceSummary()`, `garageCarUsesSummer()`
- `gear-lib.php`: `gearIceSummary()`

Media lookups get discipline-aware DB queries. No schema change.

**Tech Stack:** PHP 8.3, SQLite (PDO), PHPUnit 10, vanilla JS with `node --test`.

**Spec:** `docs/superpowers/specs/2026-09-27-ice-racing-design.md` §4 ("Home", "Garage") and §4a (the whole "Running both seasons" section, especially its table of places).

What Phases 1–4a left in place (merged, c92fcfa):

- `seasonForEvent()`, `techCarStatus()`, `techCarStatusLabel($s, $season, $discipline)` (tech-status.php)
- `techSheetIsIce()`, `techSheetClassLine()` (ice-sheet-lib.php)
- `gearStatus()`, `gearStatusLabel($s, $season, $discipline)`, `gearSeasonNow($discipline)` (gear-lib.php)
- `ICE_GEAR_LEVEL_LABELS`
- `db_get_active_events($pdo, ?discipline)`, `db_get_gear_record_for_driver(..., $discipline)`, `db_get_gear_records_for_season(..., $discipline)`
- `garageSummerSheets()` and `garageIceRows()` (garage-lib.php)
- the Garage car page's "Ice racing" section (garage-page.php)
- `gear.php?action=start-ice&sheet_id=`
- `tech-sheets.php?action=new-ice&car_id=&event_id=`
- Home readiness for ice (Phase 4a)

### Roadmap

| Phase | Status |
|---|---|
| 1–4a | Done |
| **4b (this plan)** | Remaining screens. This is the last ice phase. |

## Global Constraints

- **Paths.** All paths are relative to `wcma-calculator/` unless they start with `docs/`.
- **Tests.**
  - PHP: `php phpunit.phar`. JS: `node --test tests/js/*.test.js`.
  - Baseline: 865 PHP, 42 JS. Everything must pass at the end of every task.
- **Includes.** Always use `require_once` for app includes (`tests/RequireOnceGuardTest.php`).
- **Pages that need config.php** can't run under PHPUnit: `index.php`, `garage.php`, `drivers.php`, `inspect.php`, `admin-gear.php`, `media.php`, `drivers-public.php`, `driver.php`. Cover them with source-level tests and `php -l`.
- **Terminology.**
  - Use *reviewed*, *accepted* and *pre-teched*.
  - Never use *approved*, *passed* or *safe* in UI copy. The "Street Safe" class name and the "street-safe" level label are exempt.
  - `tests/AdminGearCopyTest.php` bans `\bsafe\b` in `admin-gear.php`.
- **Summer is unchanged.**
  - A summer-only car and a summer-only driver render exactly as today everywhere. Every existing test passes unmodified, except the Phase 2 Garage "Ice racing" section tests. Task 2 replaces those.
- **What "ice car" and "ice activity" mean.**
  - A car is an **ice car** if it has any ice tech sheet or is tagged to an active ice event.
  - A car **uses summer** if it has any declaration or summer tech sheet, is tagged to an active summer event, or has no ice activity at all. New cars default to summer.
  - A user has **ice activity** if any of their cars is an ice car, or any of their drivers has an ice gear record.
- **Ice gear status:** accepted ice record, then accepted summer record from the previous season (carry-over), then any other ice record state, then "Needs ice gear check". This mirrors Phase 4a readiness.
- **Branch.** Work on branch `ice-phase4b`. Before every commit, confirm `git branch --show-current` prints `ice-phase4b`. Never work in `C:/dev/wcmaclasscalc` itself.
- **Commits.** Commit at the end of every task. End every commit message with:
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5
  ```

## Review Focus

1. **An ice-only car** (one ice sheet, no declaration) never shows "Declare class" or "Not declared". This applies on the Garage card and in Home At a glance. Test: Task 1, `testIceOnlyCarDoesNotUseSummer`; Task 2 and Task 3 render tests.
2. **A car raced in both seasons** shows both the summer class/tech and the ice chip. Test: Task 2, `testCardShowsBothChipsForACarRacedInBothSeasons`.
3. **The Garage car page after tagging an ice event** shows an ice tech sheet link. It must not show the summer "Submit tech sheet" link, and must not say "Declare a class first". Test: Task 2, `testTaggedIceEventRowLinksToTheIceForm`.
4. **A driver whose summer 2026 gear was accepted** shows "Ice 2027: from summer 2026" on the Drivers page and in Home At a glance, and no "Needs ice gear" prompt. Test: Task 1, `testIceSummaryPrefersAcceptedThenCarryOver`.
5. **The media announcer at an ice event.**
   - The class shown is the ice class line (e.g. `LS — Limited Stud (NASCC)`).
   - A car with no sheet for that event shows no class. It must not fall back to the summer declaration.
   - Test: Task 5, `testAnnouncerClassForIceEvents`.

---

### Task 1: Pure summaries for ice cars and ice gear

**Files:**
- Modify: `garage-lib.php` (add `garageIceSummary()`, `garageCarUsesSummer()`)
- Modify: `gear-lib.php` (add `gearIceSummary()`)
- Test: `tests/GarageLibTest.php` (add), `tests/GearLibTest.php` (add)

**Interfaces:**
- **Consumes:** `techSheetIsIce`, `techCarStatus`, `techCarStatusLabel`, `gearStatus`, `gearStatusLabel`, `ICE_GEAR_LEVEL_LABELS`.
- **Produces:**
  - `garageIceSummary(array $carSheets, bool $taggedToIce, int $iceSeason): ?array` returns `array{state: string, label: string}`, or null when the car isn't an ice car.
    - It looks at the car's ice sheets in `$iceSeason`, and uses the newest sheet's club and class for the label.
    - When there is a sheet: `techCarStatusLabel($status, $iceSeason, 'ice') . ' · ' . CLUB . ' · ' . CLASS`, for example `Teched Ice 2027 · NASCC · LS`.
    - When there is no sheet in the season but the car is tagged to an ice event or has older ice sheets: `['state' => 'none', 'label' => 'Needs ice tech']`.
  - `garageCarUsesSummer(array $declarations, array $carSheets, bool $taggedToSummer): bool`.
  - `gearIceSummary(?array $iceGear, ?array $summerPrev, int $iceSeason): array{state: string, label: string, gearId: ?int}`. It returns, in order:
    1. an accepted ice record: `['accepted', gearStatusLabel(..., 'ice') . ' · ' . level label, id]`;
    2. an accepted `$summerPrev`: `['accepted', "Ice $iceSeason: from summer " . ($iceSeason - 1), null]`;
    3. any other ice record: `[state, gearStatusLabel(state, $iceSeason, 'ice'), id]`;
    4. otherwise `['none', "Needs ice gear check $iceSeason", null]`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/GarageLibTest.php` (inside the class):

```php
    private function iceSheet(array $o = []): array {
        return array_merge(['id' => 9, 'car_id' => 3, 'event_id' => 20, 'season' => 2027, 'discipline' => 'ice', 'club' => 'NASCC',
                            'class' => 'LS', 'status' => 'teched', 'accepted_via' => 'in_person', 'photo_status' => null], $o);
    }

    public function testIceSummaryUsesTheNewestIceSheetOfTheSeason(): void
    {
        $s = garageIceSummary([$this->iceSheet(), ['id' => 4, 'discipline' => 'summer', 'season' => 2027, 'status' => 'teched']], false, 2027);
        $this->assertSame(['state' => 'accepted', 'label' => 'Teched Ice 2027 · NASCC · LS'], $s);
        $open = garageIceSummary([$this->iceSheet(['status' => 'submitted', 'accepted_via' => null, 'class' => 'SS'])], false, 2027);
        $this->assertSame(['state' => 'none', 'label' => 'Needs tech at the track · NASCC · SS'], $open);
    }

    public function testIceSummaryWithoutAThisSeasonSheet(): void
    {
        $this->assertSame(['state' => 'none', 'label' => 'Needs ice tech'], garageIceSummary([], true, 2027));
        $this->assertSame(['state' => 'none', 'label' => 'Needs ice tech'], garageIceSummary([$this->iceSheet(['season' => 2026])], false, 2027));
        $this->assertNull(garageIceSummary([['id' => 4, 'discipline' => 'summer', 'season' => 2027]], false, 2027));
    }

    public function testIceOnlyCarDoesNotUseSummer(): void
    {
        $this->assertFalse(garageCarUsesSummer([], [$this->iceSheet()], false));
        $this->assertTrue(garageCarUsesSummer([], [], false));                                   // new car
        $this->assertTrue(garageCarUsesSummer([['id' => 1]], [$this->iceSheet()], false));      // has a declaration
        $this->assertTrue(garageCarUsesSummer([], [$this->iceSheet(), ['id' => 5, 'discipline' => 'summer']], false));
        $this->assertTrue(garageCarUsesSummer([], [$this->iceSheet()], true));                  // tagged to a summer event
    }
```

The expected label `Needs tech at the track` comes from `techCarStatusLabel()` for state `none`. If the real label text differs, use the real one in the assertion and note it in your report.

`garageCarUsesSummer` needs "has ice activity" for the new-car rule. Implement it as: true when there's a declaration, a summer sheet or a summer tag; otherwise true only when the car has **no** ice sheet. A car tagged only to ice events and with no sheets is treated as summer-using until its first ice sheet. That's acceptable: it can still declare, and the ice chip shows.

Add to `tests/GearLibTest.php` (inside the class):

```php
    public function testIceSummaryPrefersAcceptedThenCarryOver(): void
    {
        $ice = fn(string $status, ?string $level = null, string $via = 'in_person'): array =>
            ['id' => 7, 'season' => 2027, 'discipline' => 'ice', 'level' => $level, 'status' => $status,
             'accepted_via' => $status === 'accepted' ? $via : null, 'photo_status' => null];
        $summer = ['id' => 3, 'season' => 2026, 'discipline' => 'summer', 'status' => 'accepted', 'accepted_via' => 'in_person', 'photo_status' => null];

        $this->assertSame(['state' => 'accepted', 'label' => 'Gear teched Ice 2027 · caged', 'gearId' => 7],
            gearIceSummary($ice('accepted', 'caged'), $summer, 2027));
        $this->assertSame(['state' => 'accepted', 'label' => 'Ice 2027: from summer 2026', 'gearId' => null],
            gearIceSummary($ice('open'), $summer, 2027));
        $this->assertSame(['state' => 'accepted', 'label' => 'Ice 2027: from summer 2026', 'gearId' => null],
            gearIceSummary(null, $summer, 2027));
        $this->assertSame('none', gearIceSummary($ice('open'), null, 2027)['state']);
        $this->assertSame(7, gearIceSummary($ice('open'), null, 2027)['gearId']);
        $this->assertSame(['state' => 'none', 'label' => 'Needs ice gear check 2027', 'gearId' => null], gearIceSummary(null, null, 2027));
        $notAccepted = ['status' => 'open'] + $summer;
        $this->assertSame('none', gearIceSummary(null, $notAccepted, 2027)['state']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter "GarageLibTest|GearLibTest"` → FAIL (undefined functions).

- [ ] **Step 3: Implement it**

Append to `garage-lib.php`:

```php
/**
 * The car's ice chip for $iceSeason, or null when it isn't an ice car (no ice sheets, not tagged
 * to an ice event). The label carries the newest sheet's club and class.
 * @return ?array{state: string, label: string}
 */
function garageIceSummary(array $carSheets, bool $taggedToIce, int $iceSeason): ?array {
    $ice = array_values(array_filter($carSheets, fn(array $s): bool => techSheetIsIce($s)));
    if (!$ice && !$taggedToIce) return null;
    $season = array_values(array_filter($ice, fn(array $s): bool => (int)$s['season'] === $iceSeason));
    if (!$season) return ['state' => 'none', 'label' => 'Needs ice tech'];
    usort($season, fn(array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);
    $newest = end($season);
    $status = techCarStatus($season);
    return ['state' => $status['state'],
            'label' => techCarStatusLabel($status, $iceSeason, DISCIPLINE_ICE) . ' · ' . $newest['club'] . ' · ' . $newest['class']];
}

/** Whether the car takes part in summer: declared, summer sheets, tagged to summer, or no ice sheets at all. */
function garageCarUsesSummer(array $declarations, array $carSheets, bool $taggedToSummer): bool {
    if ($declarations || $taggedToSummer) return true;
    $hasIce = false;
    foreach ($carSheets as $s) {
        if (!techSheetIsIce($s)) return true;
        $hasIce = true;
    }
    return !$hasIce;
}
```

Append to `gear-lib.php`:

```php
/**
 * A driver's ice gear for $iceSeason (spec §4a): an accepted ice record, else accepted summer gear
 * from the season before (it counts as caged ice gear), else the ice record's state.
 * @return array{state: string, label: string, gearId: ?int}
 */
function gearIceSummary(?array $iceGear, ?array $summerPrev, int $iceSeason): array {
    $iceStatus = $iceGear !== null ? gearStatus($iceGear) : null;
    if ($iceStatus !== null && $iceStatus['state'] === 'accepted') {
        $level = (string)($iceGear['level'] ?? '');
        return ['state' => 'accepted',
                'label' => gearStatusLabel($iceStatus, $iceSeason, DISCIPLINE_ICE) . ($level !== '' ? ' · ' . (ICE_GEAR_LEVEL_LABELS[$level] ?? $level) : ''),
                'gearId' => (int)$iceGear['id']];
    }
    if ($summerPrev !== null && gearStatus($summerPrev)['state'] === 'accepted') {
        return ['state' => 'accepted', 'label' => "Ice $iceSeason: from summer " . ($iceSeason - 1), 'gearId' => null];
    }
    if ($iceStatus !== null && $iceStatus['state'] !== 'none') {
        return ['state' => $iceStatus['state'], 'label' => gearStatusLabel($iceStatus, $iceSeason, DISCIPLINE_ICE), 'gearId' => (int)$iceGear['id']];
    }
    return ['state' => 'none', 'label' => "Needs ice gear check $iceSeason", 'gearId' => $iceGear !== null ? (int)$iceGear['id'] : null];
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar --filter "GarageLibTest|GearLibTest"` → PASS.
Run: `php phpunit.phar` → PASS.

- [ ] **Step 5: Commit**

```bash
git add garage-lib.php gear-lib.php tests/GarageLibTest.php tests/GearLibTest.php
git commit -m "feat(ice): pure summaries for a car's ice tech and a driver's ice gear"
```

---

### Task 2: Garage list and car page handle ice

**Files:**
- Modify: `garage-lib.php` (`garageCard`; remove `garageIceRows`)
- Modify: `garage-page.php` (`garageRenderCard`, `renderGarageCarHtml` Events section; remove the "Ice racing" section)
- Modify: `garage.php` (`garageShowList`, `garageShowCar`)
- Test: `tests/GarageLibTest.php`, `tests/GaragePageTest.php` (replace the three Phase 2 "Ice racing" tests; add new ones), `tests/GarageSourceTest.php` (add)

**Interfaces:**
- **Consumes:** Task 1's `garageIceSummary`, `garageCarUsesSummer`; `techSheetClassLine`; `db_get_active_events($pdo)` (all disciplines); `gearSeasonNow('ice')`.
- **Produces:**
  - `garageCard(array $car, array $declarations, array $carSheets, array $taggedEventIds, array $activeEvents, int $season, string $today, int $iceSeason = 0): array`. `$carSheets` and `$activeEvents` now contain every discipline. The card gains:
    - `'usesSummer' => bool`
    - `'ice' => ?array{state, label}`
    - summer `techState`/`techLabel` computed from summer sheets only
    - `next` = the first tagged upcoming event of either discipline
  - Card rendering:
    - The summer class line and "Car tech" fact are shown only when `usesSummer`.
    - An "Ice tech" fact is shown when `ice` is set.
    - "Declare class" appears only when `usesSummer` and the car has no declaration.
    - When the next event is ice with no sheet, the action is "Submit ice tech sheet" (`new-ice`).
  - Car page:
    - Tagging lists every active event, with ice events labelled ` · Ice CLUB`.
    - Tagged ice rows show "Ice tech sheet submitted" plus the class line and View, or "No ice tech sheet yet" plus "Submit ice tech sheet".
    - The owner gear chips on ice rows pass `sheet_id`.
    - The summer class card is hidden for a car that doesn't use summer.
    - Earlier sheets include ice sheets. They are labelled with the event name and ` (Ice)`.

- [ ] **Step 1: Write the failing tests**

In `tests/GaragePageTest.php`:
- Delete `testCarPageShowsTheIceRacingSection`, `testNoIceSectionWithoutIceRows` and `testArchivedCarShowsIceSheetsButNoSubmitLink`.
- Read the file's `card()` and `carVm()` helpers. Then add:

```php
    public function testIceOnlyCardShowsTheIceChipAndNoDeclare(): void
    {
        $html = garageRenderCard($this->card(['class' => ['current' => null, 'earlierAccepted' => null], 'usesSummer' => false,
            'ice' => ['state' => 'accepted', 'label' => 'Teched Ice 2027 · NASCC · LS'], 'next' => null]));
        $this->assertStringContainsString('Teched Ice 2027 · NASCC · LS', $html);
        $this->assertStringNotContainsString('Declare class', $html);
        $this->assertStringNotContainsString('No class declared yet', $html);
    }

    public function testCardShowsBothChipsForACarRacedInBothSeasons(): void
    {
        $html = garageRenderCard($this->card(['usesSummer' => true, 'ice' => ['state' => 'none', 'label' => 'Needs ice tech']]));
        $this->assertStringContainsString('Needs ice tech', $html);
        $this->assertStringContainsString('Needs tech at the track', $html);   // the summer chip from card()
    }

    public function testCardNextIceEventOffersTheIceForm(): void
    {
        $html = garageRenderCard($this->card(['usesSummer' => false, 'class' => ['current' => null, 'earlierAccepted' => null],
            'ice' => ['state' => 'none', 'label' => 'Needs ice tech'],
            'next' => ['event' => ['id' => 20, 'name' => 'NASCC Ice #1', 'event_date' => '2027-01-10', 'discipline' => 'ice', 'host_club' => 'NASCC'], 'sheet' => null]]));
        $this->assertStringContainsString('href="tech-sheets.php?action=new-ice&amp;car_id=3&amp;event_id=20">Submit ice tech sheet</a>', $html);
    }

    public function testTaggedIceEventRowLinksToTheIceForm(): void
    {
        $html = renderGarageCarHtml($this->carVm(['usesSummer' => false, 'class' => ['current' => null, 'earlierAccepted' => null],
            'events' => ['tagged' => [
                ['event' => ['id' => 20, 'name' => 'NASCC Ice #1', 'event_date' => '2027-01-10', 'discipline' => 'ice', 'host_club' => 'NASCC'], 'sheet' => null, 'gearLinks' => []],
                ['event' => ['id' => 21, 'name' => 'WSCC Ice', 'event_date' => '2027-01-18', 'discipline' => 'ice', 'host_club' => 'WSCC'],
                 'sheet' => ['id' => 9, 'season' => 2027, 'discipline' => 'ice', 'club' => 'WSCC', 'class' => 'FOI-STD'], 'gearLinks' => []],
            ], 'untagged' => [['id' => 22, 'name' => 'Spring <Ice>', 'event_date' => '2027-02-01', 'discipline' => 'ice', 'host_club' => 'NASCC']], 'earlierSheets' => []]]));
        $this->assertStringContainsString('href="tech-sheets.php?action=new-ice&amp;car_id=3&amp;event_id=20">Submit ice tech sheet</a>', $html);
        $this->assertStringNotContainsString('Declare a class first', $html);
        $this->assertStringContainsString('FOI-STD — Fire on Ice – Studded (WSCC)', $html);
        $this->assertStringContainsString('Spring &lt;Ice&gt; — ', $html);
        $this->assertStringContainsString(' · Ice NASCC</option>', $html);
        $this->assertStringNotContainsString('<h2>Ice racing</h2>', $html);
        $this->assertStringNotContainsString('<h2>Class</h2>', $html);   // ice-only car: no summer class card
    }
```

Adapt `card()` and `carVm()` so they default `'usesSummer' => true` and `'ice' => null`, keeping existing tests unchanged. Replace the Phase 2 garage-lib tests for `garageIceRows` in `tests/GarageLibTest.php` (it will be removed) with:

```php
    public function testCardSplitsSummerTechFromIceAndPicksTheNextEventOfEitherKind(): void
    {
        $ice = ['id' => 9, 'car_id' => 3, 'event_id' => 20, 'season' => 2027, 'discipline' => 'ice', 'club' => 'NASCC', 'class' => 'LS',
                'status' => 'teched', 'accepted_via' => 'in_person', 'photo_status' => null];
        $events = [['id' => 20, 'name' => 'NASCC Ice #1', 'event_date' => '2027-01-10', 'discipline' => 'ice', 'host_club' => 'NASCC']];
        $card = garageCard(['id' => 3, 'car_number' => '7'], [], [$ice], [20], $events, 2027, '2026-12-01', 2027);
        $this->assertFalse($card['usesSummer']);
        $this->assertSame('Teched Ice 2027 · NASCC · LS', $card['ice']['label']);
        $this->assertSame('none', $card['techState']);      // summer tech ignores the ice sheet
        $this->assertSame(20, (int)$card['next']['event']['id']);
    }
```

Add to `tests/GarageSourceTest.php`, following its pattern for reading `garage.php`:

```php
    public function testGarageLoadsAllEventsAndIceSeason(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../garage.php'));
        $this->assertStringNotContainsString('db_get_active_events($pdo, DISCIPLINE_SUMMER)', $src);
        $this->assertStringContainsString("gearSeasonNow(DISCIPLINE_ICE)", $src);
        $this->assertStringNotContainsString('garageIceRows(', $src);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter "GarageLibTest|GaragePageTest|GarageSourceTest"` → FAIL.

- [ ] **Step 3: Implement it**

**`garage-lib.php`:**

- Replace `garageCard()`:

```php
/** One Garage list card: the car, its summer class and tech (if it races summer), its ice chip (if it races ice), and its nearest tagged event. */
function garageCard(array $car, array $declarations, array $carSheets, array $taggedEventIds, array $activeEvents, int $season, string $today, int $iceSeason = 0): array {
    $summerSheets = garageSummerSheets($carSheets);
    $seasonSheets = array_values(array_filter($summerSheets, fn(array $s): bool => (int)$s['season'] === $season));
    $tech = techCarStatus($seasonSheets);
    $tagged = array_flip(array_map('intval', $taggedEventIds));
    $taggedIce = $taggedSummer = false;
    foreach ($activeEvents as $e) {
        if (!isset($tagged[(int)$e['id']]) || (string)$e['event_date'] < $today) continue;
        if (($e['discipline'] ?? 'summer') === 'ice') $taggedIce = true; else $taggedSummer = true;
    }
    $events = garageCarEvents($carSheets, $taggedEventIds, $activeEvents, [], $today);
    return [
        'car' => $car,
        'class' => garageClassLine($declarations),
        'usesSummer' => garageCarUsesSummer($declarations, $carSheets, $taggedSummer),
        'ice' => garageIceSummary($carSheets, $taggedIce, $iceSeason ?: $season),
        'techState' => $tech['state'],
        'techLabel' => techCarStatusLabel($tech, $season),
        'next' => $events['tagged'][0] ?? null,
    ];
}
```

- Delete `garageIceRows()` and its docblock.

**`garage-page.php`:**

In `garageRenderCard()`:

- Show `garageClassHtml($card['class'])` only when `($card['usesSummer'] ?? true)`.
- Show the "Car tech" fact only when `($card['usesSummer'] ?? true)`.
- After it, when `!empty($card['ice'])`, add:

```php
        $out .= '<div><dt>Ice tech</dt><dd><span class="hub-status ' . h(homeStatusClass($card['ice']['state'])) . '">' . h($card['ice']['label']) . '</span></dd></div>';
```

- Replace the actions block with:

```php
    $nextIsIce = $next !== null && (($next['event']['discipline'] ?? 'summer') === 'ice');
    if (($card['usesSummer'] ?? true) && $card['class']['current'] === null && !$nextIsIce) {
        $out .= '<a class="hub-btn" href="calculator.php?car=' . $id . '">Declare class</a>';
    } elseif ($next !== null && $next['sheet'] === null) {
        $out .= $nextIsIce
            ? '<a class="hub-btn" href="tech-sheets.php?action=new-ice&amp;car_id=' . $id . '&amp;event_id=' . (int)$next['event']['id'] . '">Submit ice tech sheet</a>'
            : '<a class="hub-btn" href="tech-sheets.php?action=new&amp;car_id=' . $id . '&amp;event_id=' . (int)$next['event']['id'] . '">Submit tech sheet</a>';
    }
```

In `renderGarageCarHtml()`:

- Wrap the summer "Class" section (`<section class="hub-card"><h2>Class</h2>...`) and the summer "Car tech" section in `if ($vm['usesSummer'] ?? true)`. Leave any "Edit details" section outside the condition. When `$vm['ice']` is set, add a small section: `<section class="hub-card"><h2>Ice tech</h2><p><span class="hub-status …">label</span></p></section>`.
- In the Events loop, compute `$isIce = (($e['discipline'] ?? 'summer') === 'ice');`, then:
  - Sheet present:
    - ice: `<span class="hub-status hub-status--ok">Ice tech sheet submitted</span> ` + `h(techSheetClassLine($sheet))` + ` <a href="tech-sheets.php?action=view&amp;id=…">View</a>`;
    - summer: unchanged.
    - Gear chips are rendered for both; add `'sheet_id' => (int)$sheet['id']` to the `renderGearChips` options when `$isIce`.
  - No sheet, ice: `<span class="hub-status hub-status--todo">No ice tech sheet yet</span> `, then:
    - archived car: `Restore the car to submit a tech sheet`;
    - otherwise: `<a class="hub-btn" href="tech-sheets.php?action=new-ice&amp;car_id=…&amp;event_id=…">Submit ice tech sheet</a>`.

    Summer no-sheet rows are unchanged.
- In the untagged `<option>` text, append `' · Ice ' . h((string)$e['host_club'])` for ice events.
- In the earlier-sheets list, append ` (Ice)` for ice sheets.
- Delete the whole `// Ice racing` section.

**`garage.php`:**

In `garageShowList()`:
- Use `$sheets = db_get_user_tech_sheets($pdo, $uid);` without the summer filter.
- Use `$events = db_get_active_events($pdo);`.
- Pass `gearSeasonNow(DISCIPLINE_ICE)` as the new last argument of `garageCard(...)`.

In `garageShowCar()`:
- Pass `$allSheets` (not `$sheets`) and `db_get_active_events($pdo)` to `garageCarEvents()`.
- Keep `$sheets` (summer) for the summer status and photos action.
- Add to the view model:
  - `'usesSummer' => garageCarUsesSummer($declarations, $allSheets, <tagged to an upcoming active summer event>)`. Compute that from `$events['tagged']` rows whose event discipline isn't ice.
  - `'ice' => garageIceSummary($allSheets, <any tagged row is ice>, gearSeasonNow(DISCIPLINE_ICE))`.
- Remove the `'ice' => garageIceRows(...)` entry and the `$eventsById` code, if nothing else uses it.
- The gear links loop already runs for every tagged row. Keep it, because `gearLinksForSheet` handles ice sheets.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar --filter "GarageLibTest|GaragePageTest|GarageSourceTest|GearChipsTest"` → PASS.
Run: `php -l garage.php` → no errors.
Run: `php phpunit.phar` → PASS.

- [ ] **Step 5: Commit**

```bash
git add garage-lib.php garage-page.php garage.php tests/GarageLibTest.php tests/GaragePageTest.php tests/GarageSourceTest.php
git commit -m "feat(ice): Garage cards and the car page handle ice events, chips and classes"
```

---

### Task 3: Home "At a glance" and the Drivers page show ice

**Files:**
- Modify: `index.php` (the garage and drivers view models)
- Modify: `home-page.php` (the At a glance cards)
- Modify: `drivers-lib.php` (`driversRows` gets ice data)
- Modify: `drivers-page.php` (`driversRenderRow` shows an ice gear line)
- Modify: `drivers.php` (loads ice gear, the previous-season summer gear, and sheet links)
- Test: `tests/HomePageTest.php`, `tests/DriversLibTest.php`, `tests/DriversPageTest.php`, `tests/HomeSourceTest.php`, `tests/DriversSourceTest.php` (add)

**Interfaces:**
- **Consumes:** Task 1's summaries; `readiness` inputs (`$in['sheets']`, `$in['plans']`, `$in['events']`, `$in['gear']`, `$in['iceGear']`); `gearSeasonNow('ice')`.
- **Produces:**
  - **Home Garage glance** items gain `'usesSummer'` and `'ice'`:
    - Summer pills show only when `usesSummer`.
    - An "Ice tech" pill shows when `ice` is set.
    - "Not declared / Declare class" never shows for a car that doesn't use summer.
  - **Home Drivers glance** items gain `'ice' => ?array{state, label}`. It's shown as an "Ice gear" pill when the user has ice activity.
  - **`driversRows($drivers, $gear, $selfId, $season, $media = [], array $ice = [])`**:
    - `$ice` is `driver id ⇒ array{state, label, gearId, sheetId}`. When it's set for a driver, the row gains `'ice' => ['state', 'label', 'action' => ?array{label, url}]`.
    - The ice action is:
      - `gearId` set → `['label' => 'View ice gear', 'url' => 'gear.php?action=pretech&id=' . gearId]`;
      - no `gearId` but a `sheetId` → `['label' => 'Add ice gear photos', 'url' => 'gear.php?action=start-ice&sheet_id=' . sheetId]`;
      - otherwise null.
    - The ice action is also null when the state is accepted via carry-over (`gearId` null).
  - **`driversRenderRow`** adds a line `<p class="hub-line">Ice gear: <span class="hub-status …">label</span> [action link]</p>` when `$row['ice']` is set.

- [ ] **Step 1: Write the failing tests**

Add to `tests/HomePageTest.php`. First read how the At a glance tests build `garage` and `drivers` view models. Then build one garage item with `'usesSummer' => false`, `'declaration' => null` and `'ice' => ['state' => 'accepted', 'label' => 'Teched Ice 2027 · NASCC · LS']`, and one driver item with `'ice' => ['state' => 'accepted', 'label' => 'Ice 2027: from summer 2026']`. Assert:
- the HTML contains `Teched Ice 2027 · NASCC · LS` and `Ice 2027: from summer 2026`;
- for that car it contains neither `Not declared` nor `Declare class`;
- a summer item without `usesSummer` / `ice` keys renders exactly as before (use an existing assertion).

Add to `tests/DriversLibTest.php`:

```php
    public function testRowsCarryIceGearWithTheRightAction(): void
    {
        $drivers = [['id' => 5, 'name' => 'Jordan'], ['id' => 6, 'name' => 'Sam'], ['id' => 7, 'name' => 'Alex']];
        $rows = driversRows($drivers, [], 5, 2026, [], [
            5 => ['state' => 'accepted', 'label' => 'Ice 2027: from summer 2026', 'gearId' => null, 'sheetId' => 9],
            6 => ['state' => 'none', 'label' => 'Needs ice gear check 2027', 'gearId' => null, 'sheetId' => 9],
            7 => ['state' => 'photos_draft', 'label' => 'Photos in progress', 'gearId' => 12, 'sheetId' => null],
        ]);
        $this->assertNull($rows[0]['ice']['action']);
        $this->assertSame(['label' => 'Add ice gear photos', 'url' => 'gear.php?action=start-ice&sheet_id=9'], $rows[1]['ice']['action']);
        $this->assertSame(['label' => 'View ice gear', 'url' => 'gear.php?action=pretech&id=12'], $rows[2]['ice']['action']);
        $this->assertArrayNotHasKey('ice', driversRows($drivers, [], 5, 2026)[0]);
    }
```

Add to `tests/DriversPageTest.php`, reusing its row fixture:
- a row with `'ice' => ['state' => 'none', 'label' => 'Needs ice gear check 2027', 'action' => ['label' => 'Add ice gear photos', 'url' => 'gear.php?action=start-ice&sheet_id=9']]` renders `Ice gear:`, the label, and `href="gear.php?action=start-ice&amp;sheet_id=9"`;
- a row without `ice` has no `Ice gear:`.

Create `tests/DriversSourceTest.php`. It checks that `drivers.php` loads ice gear and passes it on: assert the source contains `DISCIPLINE_ICE`, `gearIceSummary(` and `driversRows(` with six arguments (for example, assert it contains `, $ice)`). Also add a HomeSourceTest assertion that `index.php` contains `garageIceSummary(` and `gearIceSummary(`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter "HomePageTest|DriversLibTest|DriversPageTest|HomeSourceTest|DriversSourceTest"` → FAIL.

- [ ] **Step 3: Implement it**

**`drivers-lib.php` `driversRows()`:** add the parameter `array $ice = []`. After building each row, when `isset($ice[$id])`:

```php
            $i = $ice[$id];
            $action = null;
            if ($i['gearId'] !== null) $action = ['label' => 'View ice gear', 'url' => 'gear.php?action=pretech&id=' . (int)$i['gearId']];
            elseif ($i['sheetId'] !== null && $i['state'] !== 'accepted') $action = ['label' => 'Add ice gear photos', 'url' => 'gear.php?action=start-ice&sheet_id=' . (int)$i['sheetId']];
            $row['ice'] = ['state' => $i['state'], 'label' => $i['label'], 'action' => $action];
```

Restructure the row build so `$row` is a variable that is appended after this step.

**`drivers-page.php` `driversRenderRow()`:** before the media line, add:

```php
    if (!empty($row['ice'])) {
        $i = $row['ice'];
        $out .= '<p class="hub-line">Ice gear: <span class="hub-status ' . h(homeStatusClass($i['state'])) . '">' . h($i['label']) . '</span>'
            . ($i['action'] !== null ? ' <a href="' . h($i['action']['url']) . '">' . h($i['action']['label']) . '</a>' : '') . '</p>';
    }
```

**`drivers.php`:** after building `$gear`, add:

```php
$iceSeason = gearSeasonNow(DISCIPLINE_ICE);
$userSheets = db_get_user_tech_sheets($pdo, $uid);
$iceSheetByDriver = [];
foreach ($userSheets as $s) {
    if (!techSheetIsIce($s) || (int)$s['season'] !== $iceSeason) continue;
    $did = (int)($s['driver_id'] ?? 0);
    if (!isset($iceSheetByDriver[$did]) || (int)$s['id'] > $iceSheetByDriver[$did]) $iceSheetByDriver[$did] = (int)$s['id'];
}
$ice = [];
$iceActivity = (bool)array_filter($userSheets, fn(array $s): bool => techSheetIsIce($s));
foreach ($drivers as $d) {
    $did = (int)$d['id'];
    $iceGear = db_get_gear_record_for_driver($pdo, $did, $iceSeason, DISCIPLINE_ICE);
    if ($iceGear !== null) $iceActivity = true;
    $ice[$did] = gearIceSummary($iceGear, db_get_gear_record_for_driver($pdo, $did, $iceSeason - 1), $iceSeason)
        + ['sheetId' => $iceSheetByDriver[$did] ?? null];
}
if (!$iceActivity) $ice = [];
```

Pass `$ice` as the new last argument of `driversRows(...)`. Make sure `drivers.php` `require_once`s `ice-sheet-lib.php`.

**`index.php`:**
- For each car in `$garage[]`, add:
  - `'usesSummer' => garageCarUsesSummer(...)`
  - `'ice' => garageIceSummary(...)`

  Use `$in['sheets']` filtered to the car, and "tagged to an upcoming active ice/summer event" from `$in['plans']` + `$in['events']`, with `gearSeasonNow(DISCIPLINE_ICE)`.
- For each driver in `$drivers[]`:
  - when the user has ice activity (any ice sheet in `$in['sheets']`, any `$in['iceGear']` entry, or any plan on an ice event), add `'ice' => gearIceSummary($in['iceGear']["$did:$iceSeason"] ?? null, db_get_gear_record_for_driver($pdo, $did, $iceSeason - 1), $iceSeason)`;
  - otherwise add `'ice' => null`.
- `index.php` must `require_once` `garage-lib.php` if it doesn't already.

**`home-page.php`**, in the At a glance Garage loop:
- Render the summer class badge, the Class pill (or Not declared + Declare class) and the Car tech pill only when `($g['usesSummer'] ?? true)`.
- Then, when `!empty($g['ice'])`, add `homePillHtml($g['ice']['state'], 'Ice tech', $g['ice']['label'])`.

In the Drivers loop, when `!empty($d['ice'])`, add `homePillHtml($d['ice']['state'], 'Ice gear', $d['ice']['label'])` after the Gear tech pill.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar --filter "HomePageTest|DriversLibTest|DriversPageTest|HomeSourceTest|DriversSourceTest"` → PASS.
Run: `php -l index.php && php -l drivers.php` → no errors.
Run: `php phpunit.phar` → PASS.

- [ ] **Step 5: Commit**

```bash
git add index.php home-page.php drivers-lib.php drivers-page.php drivers.php tests/HomePageTest.php tests/DriversLibTest.php tests/DriversPageTest.php tests/HomeSourceTest.php tests/DriversSourceTest.php
git commit -m "feat(ice): Home At a glance and the Drivers page show ice tech and ice gear"
```

---

### Task 4: Admin Gear tab filters by summer or ice

**Files:**
- Modify: `admin-gear.php` (`handleGearAdminList`, `renderGearAdminListPage`, and the ice record's back link in `renderGearAdminViewPage`)
- Test: `tests/AdminGearCopyTest.php` (add)

**Interfaces:**
- **Consumes:** `db_get_gear_records_for_season($pdo, $season, $discipline)`; `gearSeasonNow($discipline)`.
- **Produces:**
  - `inspect.php?action=gear&discipline=ice&season=2027&filter=…` lists ice gear records.
  - The discipline defaults to summer. The season defaults to `gearSeasonNow($discipline)`.
  - The list form has a Summer/Ice select (`name="discipline"`).
  - Ice rows show the level after the status, for example `Gear teched Ice 2027 · caged`.
  - An ice record's "Back" link goes to `inspect.php?action=gear&discipline=ice&season=N`. This replaces the Phase 2 link to the roster.

- [ ] **Step 1: Write the failing test**

Add to `tests/AdminGearCopyTest.php` (it reads `admin-gear.php` source):

```php
    public function testGearListCanShowIceRecords(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../admin-gear.php'));
        $this->assertStringContainsString('name="discipline"', $src);
        $this->assertStringContainsString('db_get_gear_records_for_season($pdo, $season, $discipline)', $src);
        $this->assertStringContainsString('gearSeasonNow($discipline)', $src);
        $this->assertStringContainsString('action=gear&amp;discipline=ice&amp;season=', $src);
    }
```

If an existing test in that file asserts the Phase 2 roster back link for ice records, update it to the new link.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php phpunit.phar --filter AdminGearCopyTest` → FAIL.

- [ ] **Step 3: Implement it**

**`handleGearAdminList()`:**

- Read the discipline first:

```php
    $discipline = ($_GET['discipline'] ?? '') === 'ice' ? DISCIPLINE_ICE : DISCIPLINE_SUMMER;
    $season = isset($_GET['season']) && is_scalar($_GET['season']) ? (int)$_GET['season'] : gearSeasonNow($discipline);
    if ($season < 2000 || $season > 2100) $season = gearSeasonNow($discipline);
```

- Use `db_get_gear_records_for_season($pdo, $season, $discipline)`.
- Pass `$discipline` to the renderer (a new parameter after `$season`).

**`renderGearAdminListPage()`:**

- Add, before the Season field:

```php
    <label for="gear-discipline">Racing</label>
    <select id="gear-discipline" name="discipline">
      <option value="summer"<?= $discipline === 'summer' ? ' selected' : '' ?>>Summer</option>
      <option value="ice"<?= $discipline === 'ice' ? ' selected' : '' ?>>Ice</option>
    </select>
```

- In the status cell, append the level for accepted ice rows. Use the same pattern as `gear-chips.php`: `' · ' . (ICE_GEAR_LEVEL_LABELS[$g['level']] ?? $g['level'])`.

**`renderGearAdminViewPage()`:** change the ice back link to:

```php
<a href="inspect.php?action=gear&amp;discipline=ice&amp;season=<?= (int)$gear['season'] ?>">&larr; Back to the ice gear list</a>
```

Summer keeps its current link.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar --filter "AdminGearCopyTest|AdminSourceTest"` → PASS.
Run: `php -l admin-gear.php` → no errors.
Run: `php phpunit.phar` → PASS.

- [ ] **Step 5: Commit**

```bash
git add admin-gear.php tests/AdminGearCopyTest.php
git commit -m "feat(ice): inspector Gear tab lists summer or ice records"
```

---

### Task 5: Media pages use each event's own sheets and classes

**Files:**
- Modify: `db.php` (`db_get_driver_latest_sheet`)
- Modify: `media-service.php` (`mediaAnnouncerRoster`, `mediaEntriesForDrivers`, `mediaReviewQueue`)
- Modify: `media.php`, `drivers-public.php`, `driver.php` (season arguments)
- Test: `tests/MediaServiceTest.php` (add), `tests/DbMediaTest.php` (add), `tests/MediaSourceTest.php` (add)

**Interfaces:**
- **Consumes:** `techSheetIsIce`, `techSheetClassLine`, `db_get_event`.
- **Produces:**
  - `db_get_driver_latest_sheet(PDO $pdo, int $driverId, ?int $season = null): ?array`. The driver's newest sheet by id, of either discipline. With `$season` it's limited to that season, as before.
  - The announcer roster for an ice event shows `techSheetClassLine($sheet)` as the class. A car with no sheet for that event gets class `''` (no summer declaration fallback).
  - For summer events it's unchanged.
  - `mediaEntriesForDrivers(PDO $pdo, array $driverIds, ?int $season)` and `mediaReviewQueue($pdo, ?int $season)`: `null` means the newest sheet of either discipline. `media.php`, `drivers-public.php` and `driver.php` pass `null`, per spec §4a: "most recent sheet of either discipline".
  - Entries built from an ice sheet show the ice class line.

- [ ] **Step 1: Write the failing tests**

Add to `tests/DbMediaTest.php`. Create a driver, a summer sheet in 2026, then an ice sheet in 2027 (`test_make_ice_sheet`) for the same driver. Assert:
- `db_get_driver_latest_sheet($pdo, $did)` returns the ice sheet (newest);
- `db_get_driver_latest_sheet($pdo, $did, 2026)` returns the summer one.

Add to `tests/MediaServiceTest.php`, reusing its fixtures for a consented driver with a club-usable profile:

```php
    public function testAnnouncerClassForIceEvents(): void
    {
        // Fixture: an ice event (NASCC) with car A tagged and holding an LS ice sheet for it, and car B
        // tagged with an accepted summer declaration (GT3) but no ice sheet for this event.
        // Then:
        //   $roster = mediaAnnouncerRoster($pdo, $iceEventId);
        //   car A's 'class' === 'LS — Limited Stud (NASCC)'
        //   car B's 'class' === ''   (no summer declaration fallback at an ice event)
        // And for a summer event, the existing declaration fallback is unchanged (existing test).
    }
```

Write this test fully, using the file's existing fixture helpers, rather than leaving the comments. The expected values are exactly the two strings in the comments.

Add to `tests/MediaSourceTest.php`: `media.php`, `drivers-public.php` and `driver.php` no longer contain `(int)date('Y')` as the season passed to the media lookups. Assert `driver.php` contains `db_get_driver_latest_sheet($pdo, $driverId)`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter "DbMediaTest|MediaServiceTest|MediaSourceTest"` → FAIL.

- [ ] **Step 3: Implement it**

**`db.php`:**

```php
/** The driver's newest tech sheet (as driver 1 or an added driver), of either discipline; limited to $season when given. */
function db_get_driver_latest_sheet(PDO $pdo, int $driverId, ?int $season = null): ?array {
    $where = '(driver_id = :d OR id IN (SELECT tech_sheet_id FROM tech_sheet_drivers WHERE driver_id = :d))';
    $params = [':d' => $driverId];
    if ($season !== null) { $where .= ' AND season = :s'; $params[':s'] = $season; }
    $stmt = $pdo->prepare("SELECT * FROM tech_sheets WHERE $where ORDER BY id DESC LIMIT 1");
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}
```

**`media-service.php`:**

In `mediaAnnouncerRoster()`:
- Load the event with `$event = db_get_event($pdo, $eventId); $isIce = ($event['discipline'] ?? 'summer') === 'ice';`.
- Change the class line to:

```php
        $class = $eventSheets ? techSheetClassLine(end($eventSheets)) : ($isIce ? '' : mediaAcceptedClass($decls[$cid] ?? []));
```

  `techSheetClassLine` returns the bare class for summer sheets, so summer is unchanged.
- For ice events, don't pull the car's latest sheet from another event to pick drivers when it's a summer sheet. Only use `$latest[$cid]` when `!$isIce || techSheetIsIce($latest[$cid])`.

In `mediaEntriesForDrivers()`:
- Change the `$season` parameter to `?int`.
- Use `techSheetClassLine($sheet)` instead of `(string)($sheet['class'] ?? '')` when `$sheet !== null`.

In `mediaReviewQueue()`: change the parameter to `?int $season`. Nothing else changes.

Make sure `media-service.php` `require_once`s `ice-sheet-lib.php`.

**Callers:**
- `media.php`: replace `$season = (int)date('Y');` with `$season = null;`. The kit, review and extra lookups then use the newest sheet.
- `drivers-public.php`: replace `$season = (int)date('Y');` with `$season = null;`.
- `driver.php`: use `db_get_driver_latest_sheet($pdo, $driverId)`.
- If `mediaKitEntries` / `mediaPublicDirectory` declare `int $season`, change them to `?int`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar --filter "DbMediaTest|MediaServiceTest|MediaSourceTest|MediaPageTest"` → PASS.
Run: `php -l media.php && php -l drivers-public.php && php -l driver.php` → no errors.
Run: `php phpunit.phar` → PASS.

- [ ] **Step 5: Commit**

```bash
git add db.php media-service.php media.php drivers-public.php driver.php tests/DbMediaTest.php tests/MediaServiceTest.php tests/MediaSourceTest.php
git commit -m "feat(ice): media pages use each event's own sheets and ice class lines"
```

---

### Task 6: The ice form shows when a head & neck restraint is required

**Files:**
- Modify: `js/ice-class-picker.js` (add `equipmentLabel`)
- Modify: `js/tech-sheet-form.js` (tag equipment rows; update the label on class change)
- Test: `tests/js/ice-class-picker.test.js` (add)

**Interfaces:**
- **Consumes:** `TECH_DRIVER_EQUIPMENT_ITEMS`, `WcmaIceClass.fhrRequired`.
- **Produces:**
  - `WcmaIceClass.equipmentLabel(baseLabel, required)` returns `baseLabel + ' (required for this class)'` when `required`, otherwise `baseLabel`.
  - Equipment rows get `data-equipment-key="<key>"`.
  - On an ice class change, the head & neck row's label is updated.

- [ ] **Step 1: Write the failing test**

Append to `tests/js/ice-class-picker.test.js` (and add `equipmentLabel` to the destructured require):

```js
test('equipmentLabel marks a required item', () => {
    assert.strictEqual(equipmentLabel('Head & Neck Restraints', true), 'Head & Neck Restraints (required for this class)');
    assert.strictEqual(equipmentLabel('Head & Neck Restraints', false), 'Head & Neck Restraints');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `node --test tests/js/ice-class-picker.test.js` → FAIL.

- [ ] **Step 3: Implement it**

**`js/ice-class-picker.js`:**
- Add:

```js
    function equipmentLabel(baseLabel, required) {
        return required ? baseLabel + ' (required for this class)' : baseLabel;
    }
```

- Add `equipmentLabel` to `api`.

**`js/tech-sheet-form.js`:**
- In `renderEquipmentInto`, after `row.className = 'checklist-item-row';`, add `row.setAttribute('data-equipment-key', key);`.
- In `onIceClassChange()`, after the `TECH_DRIVER_EQUIPMENT_ITEMS.head_neck_restraints.optional = ...` line, add:

```js
            const hnRequired = WcmaIceClass.fhrRequired(window.ICE_FHR_BY_CLASS, code);
            document.querySelectorAll('[data-equipment-key="head_neck_restraints"] .checklist-item-label').forEach(function (el) {
                el.textContent = WcmaIceClass.equipmentLabel(TECH_DRIVER_EQUIPMENT_ITEMS.head_neck_restraints.label, hnRequired);
            });
```

- Also call the same update once when the page first renders an ice form with a preselected class. The existing on-load `onIceClassChange()` covers restored values; for a server-rendered selected class, run it once after wiring if `iceClassSelect.value !== ''`. Make sure that doesn't wipe checklist state: `carryChecklistState` keeps answers.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `node --test tests/js/*.test.js` → all pass.
Run: `php phpunit.phar` → PASS.

- [ ] **Step 5: Commit**

```bash
git add js/ice-class-picker.js js/tech-sheet-form.js tests/js/ice-class-picker.test.js
git commit -m "feat(ice): ice form marks the head & neck restraint when the class requires it"
```

- [ ] **Step 6: Manual check (for the human partner)**

1. An ice-only car in the Garage shows an "Ice tech" chip and no "Declare class". On its car page, tag an ice event: the row offers "Submit ice tech sheet".
2. Home At a glance shows "Ice tech" for that car and "Ice gear" for the driver.
3. On the Drivers page, a driver with summer gear accepted last season reads "Ice gear: Ice 2027: from summer 2026".
4. Inspector → Gear tab: switch "Racing" to Ice and see ice records with their level.
5. Media → Announcer at an ice event shows the ice class line.
6. On the ice tech sheet, choose LS: the head & neck row says "(required for this class)".

---

## Self-review notes

**Spec coverage (§4 and §4a):**

| Spec item | Task |
|---|---|
| Garage chip | T1, T2 |
| Ice-only car class line and no declare prompt | T1, T2, T3 |
| Garage tagging for ice | T2 |
| Home At a glance rows | T3 |
| `gearSeasonNow(discipline)` | done in Phase 2; used in T2 and T3 |
| Drivers page ice chip and carry-over | T1, T3 |
| `gear.php` start carries discipline | Ice gear records are created via `start-ice` from a sheet (Phase 3); the Drivers page links to it (T3) |
| `inspect.php` roster | done in Phase 2 |
| Media/public/driver pages | T5 |
| `techCarStatusLabel` | done in Phase 2 |
| Admin Gear tab | T4 |
| Head & neck on the ice equipment row | T6 |

**Deliberate choices:**
- **The Garage car page's separate "Ice racing" section is replaced** by ice events in the Events section, with tagging. One place per event, matching summer.
- **A car tagged only to ice events with no sheets yet still counts as "uses summer".** This avoids hiding the class line on a brand-new car. It flips once the car has an ice sheet.
- **Media lookups without an event use the driver's newest sheet of either discipline.**
