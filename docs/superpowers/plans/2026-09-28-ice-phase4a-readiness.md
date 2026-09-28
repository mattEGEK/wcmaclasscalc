# Ice Racing Phase 4a: Ice Readiness on Home Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ice events join Home, "I'm going", reminders and "I'll do it at the track", with ice to-dos:

- no class declaration;
- an **ice tech sheet** per event;
- **ice car tech per club**;
- **ice gear** per driver, where accepted **summer gear from the season before counts as caged ice gear**;
- a to-do when the accepted gear level (or a missing frontal head restraint) doesn't cover the class on the car's ice sheet.

**Architecture:**
- `buildReadiness()` (pure) branches per event on `seasonForEvent()`. Summer events keep today's logic, except that summer car tech now ignores ice sheets inside the builder. Ice events get a new item builder.
- `loadReadinessInputs()` stops filtering to summer. It returns every active event and every sheet, plus ice gear records, a flag saying whether each ice gear record's frontal head restraint was seen, and at-track keys for both disciplines.
- Home adds an "Ice · CLUB" badge and sends the at-track form's discipline and club.
- Reminders need no code change: they are built from readiness items.

**Tech Stack:** PHP 8.3, SQLite (PDO), PHPUnit 10 (`php phpunit.phar`).

**Spec:** `docs/superpowers/specs/2026-09-27-ice-racing-design.md`. Read §4 ("Readiness", "Home", "Reminders") and §4a ("Summer gear counts for ice").

What Phases 1–3 left in place (all merged, d00f950):

| Where | What |
|---|---|
| `tech-status.php` | `seasonForEvent()`, `atTrackKey()`, `techCarStatus()` |
| `ice-rules.php` | `iceClass()`, `iceGearSatisfies(level, group)`, `ICE_GEAR_LEVEL_LABELS` |
| `ice-sheet-lib.php` | `techSheetIsIce()` |
| `gear-lib.php` | `gearStatus()` |
| `db.php` | `db_get_active_events($pdo, ?discipline)`, `db_get_gear_record_for_driver($pdo, $did, $season, $discipline)`, `db_get_at_track_keys(..., $season, $discipline)`, `db_get_inspection_photos($pdo, 'gear_record', $id)` |
| `events-lib.php` | `eventsSetAtTrack(..., $season, $discipline, $club)` |

Ice tech sheets live at `tech-sheets.php?action=new-ice&car_id=&event_id=`. Ice gear photos open from `gear.php?action=start-ice&sheet_id=`.

### Roadmap

| Phase | Delivers |
|---|---|
| 1–3 | done |
| **4a — Ice readiness (this plan)** | Ice events on Home, "I'm going", ice to-dos, reminders, at-track, summer gear carry-over, gear level / FHR check |
| 4b — Remaining screens | Garage card ice chip and ice class line, Garage event tagging for ice, Drivers page ice gear, admin Gear tab ice filter, media/public pages by discipline |

## Global Constraints

- **Paths and tests:**
  - All paths are relative to `wcma-calculator/` unless they start with `docs/`.
  - PHP: `php phpunit.phar`. JS: `node --test tests/js/*.test.js`.
  - Baseline: 841 PHP and 42 JS. All must pass at the end of every task.
- **No new dependencies.** Always use `require_once` for app includes (`tests/RequireOnceGuardTest.php`).
- **`index.php` can't run under PHPUnit** (it needs config.php). Use source-level tests plus `php -l`.
- **Terminology:** use *reviewed*, *accepted* and *pre-teched*. Never use *approved*, *passed* or *safe* in UI copy. The "Street Safe" class name and the "street-safe" level label are exempt.
- **Summer readiness is unchanged.** Every existing `ReadinessTest` assertion keeps passing, including the exact `at_track` array shape `['subject_type', 'subject_id', 'season']` for summer items.
- **Ice to-dos:**
  - Ice items never include a class declaration.
  - Ice car tech is per car, club and ice season.
  - Ice gear is per driver and ice season, and covers both clubs.
- **Summer gear carry-over:** an **accepted** summer gear record for season Y satisfies ice gear for ice season **Y+1** only, and counts as `caged` (FHR included). Ice gear never satisfies summer.
- **Gear shortfall is a to-do:** accepted ice gear whose level doesn't satisfy the class group of the car's ice sheet for that event, or an FHR class whose gear was photo-accepted without an FHR photo. With no ice sheet (class unknown), any accepted level counts.
- **Branch and commits:**
  - Work on branch `ice-phase4a`.
  - Before every commit, confirm `git branch --show-current` prints `ice-phase4a`.
  - Commit at the end of every task.
  - Every commit message ends with:
    ```
    Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
    Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5
    ```

## Review Focus

1. **A car with an accepted ice sheet in 2027 and a summer event in 2027.** Summer car tech must not show as teched. Test: Task 1, `testSummerCarTechIgnoresIceSheets`.
2. **Summer gear accepted in 2026.**
   - It satisfies ice 2027.
   - It doesn't satisfy ice 2026 or 2028.
   - A *submitted*, not accepted, summer record doesn't count.
   - Test: Task 1, `testSummerGearCarriesOverToTheNextIceSeasonOnly`.
3. **Street-safe ice gear on a car whose ice sheet is LS (caged)** is a to-do naming the class. The same gear with no ice sheet yet is done. Test: Task 1, `testIceGearLevelMustCoverTheSheetsClass`.
4. **"I'll do it at the track" on an ice item.** The choice stores discipline `ice` and the club, and afterwards the item shows as done. It doesn't leak to summer or to the other club. Tests: Task 1, `testIceAtTrackKeysUseTheClub`; Task 3 source test.
5. **The first Home load after deploy for a user who tagged an ice event under Phase 1** (the tag exists, but nothing was shown for it). It now shows ice to-dos without errors. Test: Task 2, `testTaggedIceEventNowProducesIceItems`.

---

### Task 1: Ice items in `buildReadiness()`

**Files:**
- Modify: `readiness-lib.php`
- Test: `tests/ReadinessTest.php` (add; existing summer tests unchanged)

**Interfaces:**
- **Consumes:** `seasonForEvent`, `atTrackKey`, `techCarStatus`, `techSheetIsIce`, `iceClass`, `iceGearSatisfies`, `ICE_GEAR_LEVEL_LABELS`, `gearStatus`.
- **Produces:**
  - `buildReadiness(array $in)` also accepts two optional input keys:
    - `iceGear`: `"did:season" => gear_records row` (ice)
    - `iceGearFhr`: `gearId => bool`, true when that ice gear record has an uploaded `ice_fhr_label` photo

    Both default to `[]`.
  - `readinessTech(..., ?string $retakeUrl, array $atTrackExtra = [])`: `$atTrackExtra` is merged into the `at_track` array. Summer passes nothing.
  - `readinessIceGear(int $did, string $name, int $season, ?array $iceGear, ?array $summerGear, ?array $class, bool $fhrSeen, bool $atTrack, ?string $photosUrl): array`, one gear item.
  - Ice event items, in order for each car:
    1. `tech_sheet`: "Submit an ice tech sheet for #n" / "Ice tech sheet for {event} submitted"
    2. `car_tech` (once per car and club): "Ice car tech for #n at CLUB"
    3. `gear` (once per driver): "Ice gear for NAME"

- [ ] **Step 1: Write the failing tests**

Add to `tests/ReadinessTest.php` (inside the class; `world()` and `items()` exist):

```php
    private function iceWorld(array $o = []): array {
        return $this->world(array_merge([
            'events' => [['id' => 20, 'name' => 'NASCC Ice #1', 'event_date' => '2027-01-10', 'discipline' => 'ice', 'host_club' => 'NASCC']],
            'plans' => [['event_id' => 20, 'car_id' => 3]],
            'drivers' => [5 => ['id' => 5, 'name' => 'Jordan Lee']],
            'iceGear' => [],
            'iceGearFhr' => [],
        ], $o));
    }

    private function iceSheet(array $o = []): array {
        return array_merge(['id' => 70, 'car_id' => 3, 'event_id' => 20, 'season' => 2027, 'discipline' => 'ice', 'club' => 'NASCC',
                            'class' => 'SS', 'status' => 'submitted', 'accepted_via' => null, 'photo_status' => null, 'driver_id' => 5], $o);
    }

    public function testIceEventHasNoDeclarationAndAnIceTechSheetTodo(): void
    {
        $items = $this->items(buildReadiness($this->iceWorld()));
        $this->assertEqualsCanonicalizing(['tech_sheet:3', 'car_tech:3', 'gear:5'], array_keys($items));
        $this->assertSame('Submit an ice tech sheet for #42', $items['tech_sheet:3']['label']);
        $this->assertSame('tech-sheets.php?action=new-ice&car_id=3&event_id=20', $items['tech_sheet:3']['action']['url']);
        $this->assertSame('Ice car tech for #42 at NASCC', $items['car_tech:3']['label']);
        $this->assertSame(['subject_type' => 'car', 'subject_id' => 3, 'season' => 2027, 'discipline' => 'ice', 'club' => 'NASCC'],
            $items['car_tech:3']['at_track']);
        $this->assertSame(['subject_type' => 'driver', 'subject_id' => 5, 'season' => 2027, 'discipline' => 'ice', 'club' => ''],
            $items['gear:5']['at_track']);
    }

    public function testIceCarTechIsPerClubAndSeason(): void
    {
        $teched = $this->iceSheet(['status' => 'teched', 'accepted_via' => 'in_person']);
        $items = $this->items(buildReadiness($this->iceWorld(['sheets' => [$teched]])));
        $this->assertSame('done', $items['car_tech:3']['state']);
        $this->assertSame('Ice car tech 2027 for #42 at NASCC: teched', $items['car_tech:3']['label']);
        $this->assertSame('Ice tech sheet for NASCC Ice #1 submitted', $items['tech_sheet:3']['label']);

        $wscc = $this->iceSheet(['club' => 'WSCC', 'event_id' => 99, 'class' => 'FOI-STD', 'status' => 'teched', 'accepted_via' => 'in_person']);
        $this->assertSame('todo', $this->items(buildReadiness($this->iceWorld(['sheets' => [$wscc]])))['car_tech:3']['state']);
    }

    public function testSummerCarTechIgnoresIceSheets(): void
    {
        $iceTeched = $this->iceSheet(['season' => 2026, 'status' => 'teched', 'accepted_via' => 'in_person']);
        $items = $this->items(buildReadiness($this->world(['sheets' => [$iceTeched]])));
        $this->assertSame('todo', $items['car_tech:3']['state']);
    }

    public function testIceAtTrackKeysUseTheClub(): void
    {
        $done = $this->items(buildReadiness($this->iceWorld(['atTrack' => ['car:3@ice:NASCC:2027', 'driver:5@ice:2027']])));
        $this->assertSame('done', $done['car_tech:3']['state']);
        $this->assertSame('done', $done['gear:5']['state']);
        $other = $this->items(buildReadiness($this->iceWorld(['atTrack' => ['car:3@ice:WSCC:2027', 'car:3@2027', 'driver:5@2027']])));
        $this->assertSame('todo', $other['car_tech:3']['state']);
        $this->assertSame('todo', $other['gear:5']['state']);
    }

    public function testSummerGearCarriesOverToTheNextIceSeasonOnly(): void
    {
        $accepted = fn(int $season): array => ['id' => 40, 'season' => $season, 'status' => 'accepted', 'accepted_via' => 'in_person', 'photo_status' => null];
        $label = fn(array $gear): string => $this->items(buildReadiness($this->iceWorld(['gear' => $gear])))['gear:5']['label'];
        $this->assertSame('Ice gear for Jordan Lee: from summer 2026', $label(['5:2026' => $accepted(2026)]));
        $this->assertSame('todo', $this->items(buildReadiness($this->iceWorld(['gear' => ['5:2027' => $accepted(2027)]])))['gear:5']['state']);
        $this->assertSame('todo', $this->items(buildReadiness($this->iceWorld(['gear' => ['5:2025' => $accepted(2025)]])))['gear:5']['state']);
        $submitted = ['id' => 41, 'season' => 2026, 'status' => 'open', 'accepted_via' => null, 'photo_status' => 'submitted'];
        $this->assertSame('todo', $this->items(buildReadiness($this->iceWorld(['gear' => ['5:2026' => $submitted]])))['gear:5']['state']);
    }

    public function testIceGearLevelMustCoverTheSheetsClass(): void
    {
        $gear = ['5:2027' => ['id' => 50, 'season' => 2027, 'discipline' => 'ice', 'level' => 'street_safe', 'status' => 'accepted', 'accepted_via' => 'in_person', 'photo_status' => null]];
        $noSheet = $this->items(buildReadiness($this->iceWorld(['iceGear' => $gear])));
        $this->assertSame('done', $noSheet['gear:5']['state']);
        $this->assertSame('Ice gear for Jordan Lee: teched Ice 2027 · street-safe', $noSheet['gear:5']['label']);

        $ls = $this->items(buildReadiness($this->iceWorld(['iceGear' => $gear, 'sheets' => [$this->iceSheet(['class' => 'LS'])]])));
        $this->assertSame('todo', $ls['gear:5']['state']);
        $this->assertSame("Jordan Lee's gear is checked for street-safe; LS needs caged-level gear", $ls['gear:5']['label']);
    }

    public function testFhrClassNeedsAnFhrSeenForPhotoAcceptedGear(): void
    {
        $caged = fn(string $via): array => ['5:2027' => ['id' => 51, 'season' => 2027, 'discipline' => 'ice', 'level' => 'caged', 'status' => 'accepted', 'accepted_via' => $via, 'photo_status' => $via === 'photos' ? 'accepted' : null]];
        $ls = [$this->iceSheet(['class' => 'LS'])];
        $photos = $this->items(buildReadiness($this->iceWorld(['iceGear' => $caged('photos'), 'sheets' => $ls])));
        $this->assertSame('todo', $photos['gear:5']['state']);
        $this->assertSame("Jordan Lee's gear needs a frontal head restraint checked for LS", $photos['gear:5']['label']);
        $seen = $this->items(buildReadiness($this->iceWorld(['iceGear' => $caged('photos'), 'iceGearFhr' => [51 => true], 'sheets' => $ls])));
        $this->assertSame('done', $seen['gear:5']['state']);
        $inPerson = $this->items(buildReadiness($this->iceWorld(['iceGear' => $caged('in_person'), 'sheets' => $ls])));
        $this->assertSame('done', $inPerson['gear:5']['state']);
    }

    public function testIceGearPhotosLinkFromTheIceSheet(): void
    {
        $items = $this->items(buildReadiness($this->iceWorld(['sheets' => [$this->iceSheet()]])));
        $this->assertSame('gear.php?action=start-ice&sheet_id=70', $items['gear:5']['action']['url']);
        $this->assertNull($this->items(buildReadiness($this->iceWorld()))['gear:5']['action']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter ReadinessTest` → the new tests FAIL.

- [ ] **Step 3: Implement it in `readiness-lib.php`**

1. At the top, next to the existing require, add `require_once __DIR__ . '/ice-rules.php';`.

2. Add a trailing parameter to `readinessTech()`: `array $atTrackExtra = []`. In its final `return`, change the at-track array to `['subject_type' => $subjectType, 'subject_id' => $subjectId, 'season' => $season] + $atTrackExtra`.

3. Add these functions before `buildReadiness()`:

```php
/**
 * One ice gear item (spec §4, §4a). Accepted ice gear must cover the class on the car's ice sheet
 * (level, and a frontal head restraint when the class needs one); accepted summer gear from the
 * season before counts as caged gear.
 */
function readinessIceGear(int $did, string $name, int $season, ?array $iceGear, ?array $summerGear, ?array $class,
                          bool $fhrSeen, bool $atTrack, ?string $photosUrl): array {
    $iceStatus = $iceGear !== null ? gearStatus($iceGear) : ['state' => 'none', 'via' => null];
    if ($iceStatus['state'] === 'accepted') {
        $level = (string)($iceGear['level'] ?? '');
        $levelLabel = ICE_GEAR_LEVEL_LABELS[$level] ?? $level;
        $pretech = ['label' => 'Add photos', 'url' => 'gear.php?action=pretech&id=' . (int)$iceGear['id']];
        if ($class !== null && !iceGearSatisfies($level !== '' ? $level : null, $class['group'])) {
            return readinessItem('gear', 'driver', $did, 'todo',
                "$name's gear is checked for $levelLabel; {$class['code']} needs caged-level gear",
                'Bring caged-level gear to tech at the track.', null);
        }
        if ($class !== null && $class['fhr'] && ($iceGear['accepted_via'] ?? '') === 'photos' && !$fhrSeen) {
            return readinessItem('gear', 'driver', $did, 'todo',
                "$name's gear needs a frontal head restraint checked for {$class['code']}",
                'Bring the frontal head restraint to tech at the track.', null);
        }
        $via = ($iceStatus['via'] ?? 'in_person') === 'photos' ? 'pre-teched' : 'teched';
        return readinessItem('gear', 'driver', $did, 'done', "Ice gear for $name: $via Ice $season" . ($levelLabel !== '' ? " · $levelLabel" : ''));
    }
    if ($summerGear !== null && gearStatus($summerGear)['state'] === 'accepted') {
        return readinessItem('gear', 'driver', $did, 'done', "Ice gear for $name: from summer " . ($season - 1));
    }
    return readinessTech('gear', 'driver', $did, $iceStatus, $season, $atTrack, [
        'label' => "Ice gear for $name", 'doneLabel' => "Ice gear for $name: %s Ice %d", 'pendingLabel' => "Ice gear photos for $name are with an inspector",
        'retakeLabel' => "Retake ice gear photos for $name", 'atTrackLabel' => "Ice gear for $name: checked at the track",
    ], $photosUrl, $iceGear !== null ? 'gear.php?action=pretech&id=' . (int)$iceGear['id'] : null,
       ['discipline' => DISCIPLINE_ICE, 'club' => '']);
}

/**
 * Ice to-dos for one car at one ice event: the ice tech sheet, ice car tech for the event's club
 * and season, and ice gear for each driver. $once de-duplicates season-wide items across events.
 */
function readinessIceCarItems(array $in, array $event, array $key, int $carId, array $sheetsByCar, array $atTrack, callable $once): array {
    $eid = (int)$event['id'];
    $season = $key['season'];
    $club = (string)$key['club'];
    $car = $in['cars'][$carId];
    $n = '#' . $car['car_number'];
    $items = [];

    $eventSheet = null;
    foreach ($sheetsByCar[$carId] ?? [] as $s) {
        if ((int)$s['event_id'] === $eid && techSheetIsIce($s)) { $eventSheet = $s; break; }
    }
    $items[] = $eventSheet !== null
        ? readinessItem('tech_sheet', 'car', $carId, 'done', 'Ice tech sheet for ' . $event['name'] . ' submitted')
        : readinessItem('tech_sheet', 'car', $carId, 'todo', "Submit an ice tech sheet for $n", 'Pick your class on the ice tech sheet.',
            ['label' => 'Submit ice tech sheet', 'url' => "tech-sheets.php?action=new-ice&car_id=$carId&event_id=$eid"]);

    if ($once("ice_car_tech:$club:$carId")) {
        $clubSheets = array_values(array_filter($sheetsByCar[$carId] ?? [], fn(array $s): bool =>
            techSheetIsIce($s) && ($s['club'] ?? '') === $club && (int)$s['season'] === $season));
        $status = techCarStatus($clubSheets);
        $ids = array_map(fn(array $s): int => (int)$s['id'], $clubSheets);
        $latest = $ids ? max($ids) : null;
        $items[] = readinessTech('car_tech', 'car', $carId, $status, $season, isset($atTrack[atTrackKey('car', $carId, $season, DISCIPLINE_ICE, $club)]), [
            'label' => "Ice car tech for $n at $club", 'doneLabel' => "Ice car tech %2\$d for $n at $club: %1\$s",
            'pendingLabel' => "Ice car tech photos for $n are with an inspector", 'retakeLabel' => "Retake ice car photos for $n",
            'atTrackLabel' => "Ice car tech for $n: you'll bring it to tech at the track",
        ], $latest !== null ? 'tech-sheets.php?action=pretech&id=' . $latest : null,
           $status['sheet_id'] !== null ? 'tech-sheets.php?action=pretech&id=' . $status['sheet_id'] : null,
           ['discipline' => DISCIPLINE_ICE, 'club' => $club]);
    }

    $class = $eventSheet !== null ? iceClass($club, (string)$eventSheet['class']) : null;
    $driverIds = array_values(array_unique(array_filter(array_merge(
        $eventSheet !== null ? [(int)($eventSheet['driver_id'] ?? 0)] : [], [(int)$in['selfDriverId']], array_map('intval', array_keys($in['drivers']))
    ))));
    foreach ($driverIds as $did) {
        if (!isset($in['drivers'][$did]) || !$once("ice_gear:$did")) continue;
        $iceGear = $in['iceGear']["$did:$season"] ?? null;
        $items[] = readinessIceGear($did, (string)$in['drivers'][$did]['name'], $season, $iceGear,
            $in['gear']["$did:" . ($season - 1)] ?? null, $class,
            $iceGear !== null && !empty($in['iceGearFhr'][(int)$iceGear['id']]),
            isset($atTrack[atTrackKey('driver', $did, $season, DISCIPLINE_ICE)]),
            $eventSheet !== null && (int)($eventSheet['driver_id'] ?? 0) === $did ? 'gear.php?action=start-ice&sheet_id=' . (int)$eventSheet['id'] : null);
    }
    return $items;
}
```

`readinessTech`'s done label uses `sprintf($names['doneLabel'], $via, $season)`. The ice labels use positional arguments (`%1$s` = via, `%2$d` = season, and `Ice %d` in the gear label), so they format as tested. Check that `"Ice car tech %2\$d for $n at $club: %1\$s"` yields `Ice car tech 2027 for #42 at NASCC: teched`.

4. In `buildReadiness()`:

- Default the new inputs at the top: `$in += ['iceGear' => [], 'iceGearFhr' => []];`.
- Inside `foreach ($upcoming as $event)`, after the `untagged` check, replace `$season = techSeasonFromDate((string)$event['event_date']);` with:

```php
        $key = seasonForEvent($event);
        $season = $key['season'];
```

- Move the `$once` closure so it is defined **before** the per-car loop, not inside it. It stays the same closure, with the same `@$season` suffix. Ice keys are prefixed `ice_`, so they never collide with summer keys.
- Inside the per-car loop, directly after `$car = ...; $n = ...;`, add:

```php
            if ($key['discipline'] === DISCIPLINE_ICE) {
                $items = array_merge($items, readinessIceCarItems($in, $event, $key, $carId, $sheetsByCar, $atTrack, $once));
                continue;
            }
```

- In the summer path, change the `$seasonSheets` filter to also drop ice sheets:

```php
            $seasonSheets = array_values(array_filter($sheetsByCar[$carId] ?? [], fn(array $s): bool => (int)$s['season'] === $season && !techSheetIsIce($s)));
```

  Also make the summer `$eventSheet` search skip ice sheets: `if ((int)$s['event_id'] === $eid && !techSheetIsIce($s))`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar --filter ReadinessTest` → PASS. Every existing summer test must pass unchanged.
Run: `php phpunit.phar` → the full suite PASSES.

- [ ] **Step 5: Commit**

```bash
git add readiness-lib.php tests/ReadinessTest.php
git commit -m "feat(ice): ice to-dos in readiness — ice tech sheet, car tech per club, ice gear with summer carry-over"
```

---

### Task 2: Load ice readiness inputs

**Files:**
- Modify: `readiness-lib.php` (`loadReadinessInputs`)
- Test: `tests/ReadinessLoaderTest.php` (replace the two Phase 1–2 summer-only tests; add new ones)

**Interfaces:**
- **Consumes:** `db_get_active_events($pdo)` (no discipline filter); `seasonForEvent`; `db_get_gear_record_for_driver(..., $discipline)`; `db_get_at_track_keys(..., $discipline)`; `db_get_inspection_photos`.
- **Produces:** the loader returns:
  - every active event and every sheet;
  - `gear`: `"did:season"` summer records, for each summer event season and for each ice season minus one (the carry-over);
  - `iceGear`: `"did:season"` ice records for each ice event season;
  - `iceGearFhr`: `gearId => bool` for photo-accepted ice records;
  - `atTrack`: keys for both disciplines.

- [ ] **Step 1: Write the failing tests**

In `tests/ReadinessLoaderTest.php`:

- Delete `testTaggedIceEventProducesNoReadinessItems` and `testLoaderKeepsOnlySummerSheets`.
- Add:

```php
    public function testTaggedIceEventNowProducesIceItems(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'ice@example.com', 'name' => 'Ice Racer', 'password_hash' => 'x', 'google_id' => null]);
        $car = test_make_car($pdo, $u, '7');
        $iceEvent = db_create_event($pdo, 'NASCC Ice #1', '2026-12-12', null, 'ice', 'NASCC');
        db_tag_event($pdo, $u, $iceEvent, $car);

        $in = loadReadinessInputs($pdo, $u, '2026-09-26');
        $this->assertSame([$iceEvent], array_map(fn($e) => (int)$e['id'], $in['events']));
        $kinds = array_map(fn($i) => $i['kind'], buildReadiness($in)['events'][0]['items']);
        $this->assertNotContains('declaration', $kinds);
        $this->assertContains('tech_sheet', $kinds);
    }

    public function testLoaderGathersIceGearCarryOverFhrAndIceAtTrack(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'both@example.com', 'name' => 'Both Seasons', 'password_hash' => 'x', 'google_id' => null]);
        $car = test_make_car($pdo, $u, '7');
        db_create_event($pdo, 'NASCC Ice #1', '2027-01-10', null, 'ice', 'NASCC');
        $self = (int)db_get_self_driver($pdo, $u)['id'];
        $summer = (int)gearCreate($pdo, $u, (string)db_get_driver($pdo, $self)['name'], '', 2026)['id'];
        $ice = (int)gearCreate($pdo, $u, (string)db_get_driver($pdo, $self)['name'], '', 2027, 'ice')['id'];
        db_upsert_inspection_photo($pdo, ['subject_type' => 'gear_record', 'subject_id' => $ice, 'requirement_key' => 'ice_fhr_label',
            'requirement_version' => 1, 'file_path' => 'uploads/x.jpg', 'typed_value' => null]);
        db_mark_gear_photos_draft($pdo, $ice);
        db_transition_gear_photo_status($pdo, $ice, ['draft'], 'submitted');
        gearAcceptByPhotos($pdo, $ice, $u, 'caged');
        db_set_at_track($pdo, 'car', $car, 2027, 'ice', 'NASCC');

        $in = loadReadinessInputs($pdo, $u, '2026-09-26');
        $this->assertSame($summer, (int)$in['gear']["$self:2026"]['id']);
        $this->assertSame($ice, (int)$in['iceGear']["$self:2027"]['id']);
        $this->assertTrue($in['iceGearFhr'][$ice]);
        $this->assertContains("car:$car@ice:NASCC:2027", $in['atTrack']);
    }
```

If `db_create_user` doesn't create the self driver, follow how `testLoaderBuildsTheInputShapeFromTheDatabase` gets `$self`. If `db_upsert_inspection_photo`'s parameter keys differ, copy them from `tests/PretechLibTest.php`'s `addPhoto()` helper.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter ReadinessLoaderTest` → the new tests FAIL.

- [ ] **Step 3: Implement it in `loadReadinessInputs()`**

Replace the body from the `$sheets = ...` line through the `$atTrack` loop with:

```php
    $sheets = db_get_user_tech_sheets($pdo, $userId);
    $sheetDrivers = [];
    foreach (db_get_drivers_for_sheets($pdo, array_map(fn(array $s): int => (int)$s['id'], $sheets)) as $sheetId => $rows) {
        $sheetDrivers[(int)$sheetId] = array_values(array_filter(array_map(fn(array $r): int => (int)($r['driver_id'] ?? 0), $rows)));
    }

    $drivers = [];
    foreach (db_get_user_drivers($pdo, $userId) as $d) $drivers[(int)$d['id']] = $d;
    $self = db_get_self_driver($pdo, $userId);

    $events = db_get_active_events($pdo);
    $summerSeasons = [];
    $iceSeasons = [];
    foreach ($events as $e) {
        $key = seasonForEvent($e);
        if ($key['discipline'] === DISCIPLINE_ICE) {
            $iceSeasons[$key['season']] = true;
            $summerSeasons[$key['season'] - 1] = true;   // summer gear carries over to the next ice season
        } else {
            $summerSeasons[$key['season']] = true;
        }
    }
    $gear = [];
    $iceGear = [];
    $iceGearFhr = [];
    foreach (array_keys($drivers) as $did) {
        foreach (array_keys($summerSeasons) as $season) {
            $g = db_get_gear_record_for_driver($pdo, $did, $season);
            if ($g !== null) $gear["$did:$season"] = $g;
        }
        foreach (array_keys($iceSeasons) as $season) {
            $g = db_get_gear_record_for_driver($pdo, $did, $season, DISCIPLINE_ICE);
            if ($g === null) continue;
            $iceGear["$did:$season"] = $g;
            if (($g['accepted_via'] ?? null) === 'photos') {
                $fhr = db_get_inspection_photos($pdo, 'gear_record', (int)$g['id'])['ice_fhr_label'] ?? null;
                $iceGearFhr[(int)$g['id']] = $fhr !== null && ($fhr['file_path'] ?? '') !== '';
            }
        }
    }

    $atTrack = [];
    foreach (array_keys($summerSeasons) as $season) {
        $atTrack = array_merge($atTrack, db_get_at_track_keys($pdo, array_keys($cars), array_keys($drivers), $season));
    }
    foreach (array_keys($iceSeasons) as $season) {
        $atTrack = array_merge($atTrack, db_get_at_track_keys($pdo, array_keys($cars), array_keys($drivers), $season, DISCIPLINE_ICE));
    }
```

Then add these to the returned array:

```php
        'iceGear' => $iceGear,
        'iceGearFhr' => $iceGearFhr,
```

Update the header comment of `readiness-lib.php`: summer and ice readiness are both built here.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar --filter "ReadinessLoaderTest|ReadinessTest|RemindersRunTest"` → PASS.
Run: `php phpunit.phar` → the full suite PASSES.

- [ ] **Step 5: Commit**

```bash
git add readiness-lib.php tests/ReadinessLoaderTest.php
git commit -m "feat(ice): readiness loads ice events, ice gear, FHR evidence and ice at-track choices"
```

---

### Task 3: Home shows ice events and records ice "at the track" choices

**Files:**
- Modify: `home-page.php` (`homeRenderTodoItem`, `homeEventCardHtml`)
- Modify: `index.php` (the `at-track` POST case)
- Test: `tests/HomePageTest.php` (add), `tests/HomeSourceTest.php` (create)

**Interfaces:**
- **Consumes:** item `at_track` arrays that may carry `discipline` and `club` (Task 1); `eventsSetAtTrack(..., $discipline, $club)`.
- **Produces:**
  - the at-track form posts `discipline` and `club` when the item has them;
  - ice event cards show `<span class="hub-status hub-status--info">Ice · NASCC</span>` in the header;
  - `index.php` passes discipline and club through to `eventsSetAtTrack`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/HomePageTest.php` (inside the class; read its helpers first, including the item builder at line ~15):

```php
    public function testIceAtTrackFormCarriesDisciplineAndClub(): void
    {
        $item = ['kind' => 'car_tech', 'subject_type' => 'car', 'subject_id' => 3, 'state' => 'todo', 'label' => 'Ice car tech for #42 at NASCC',
                 'detail' => '', 'action' => null, 'at_track' => ['subject_type' => 'car', 'subject_id' => 3, 'season' => 2027, 'discipline' => 'ice', 'club' => 'NASCC']];
        $html = homeRenderTodoItem(1, $item, 'tok');
        $this->assertStringContainsString('<input type="hidden" name="discipline" value="ice">', $html);
        $this->assertStringContainsString('<input type="hidden" name="club" value="NASCC">', $html);

        $summer = ['at_track' => ['subject_type' => 'car', 'subject_id' => 3, 'season' => 2026]] + $item;
        $this->assertStringNotContainsString('name="discipline"', homeRenderTodoItem(1, $summer, 'tok'));
    }

    public function testIceEventCardShowsTheClubBadge(): void
    {
        $event = ['id' => 20, 'name' => 'NASCC Ice #1', 'event_date' => '2027-01-10', 'discipline' => 'ice', 'host_club' => 'NASCC'];
        $this->assertStringContainsString('<span class="hub-status hub-status--info">Ice · NASCC</span>', homeEventCardHtml($event, null, [], 'tok', false));
        $summer = ['id' => 10, 'name' => 'Fall Sprint', 'event_date' => '2026-10-11'];
        $this->assertStringNotContainsString('Ice ·', homeEventCardHtml($summer, null, [], 'tok', false));
    }
```

Create `tests/HomeSourceTest.php`:

```php
<?php
// wcma-calculator/tests/HomeSourceTest.php
//
// Source-level guard: index.php needs config.php, so it cannot run under PHPUnit.
use PHPUnit\Framework\TestCase;

final class HomeSourceTest extends TestCase
{
    public function testAtTrackPassesDisciplineAndClub(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../index.php'));
        $this->assertStringContainsString("(string)(\$_POST['discipline'] ?? 'summer')", $src);
        $this->assertStringContainsString("(string)(\$_POST['club'] ?? '')", $src);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter "HomePageTest|HomeSourceTest"` → FAIL.

- [ ] **Step 3: Implement it**

In `home-page.php` `homeRenderTodoItem()`, directly after the `season` hidden input line, add:

```php
            . (isset($at['discipline']) ? '<input type="hidden" name="discipline" value="' . h((string)$at['discipline']) . '">' : '')
            . (isset($at['club']) ? '<input type="hidden" name="club" value="' . h((string)$at['club']) . '">' : '')
```

This goes inside the string concatenation, before the `<button>` line.

In `homeEventCardHtml()`, directly after the date span (the `if ($date !== '') ...` line), add:

```php
    if (($event['discipline'] ?? 'summer') === 'ice') {
        $out .= '<span class="hub-status hub-status--info">' . h('Ice · ' . (string)($event['host_club'] ?? '')) . '</span>';
    }
```

In `index.php`, change the `at-track` case's call to:

```php
            $r = eventsSetAtTrack($pdo, $uid, (string)($_POST['subject_type'] ?? ''), (int)($_POST['subject_id'] ?? 0), (int)($_POST['season'] ?? 0),
                (string)($_POST['discipline'] ?? 'summer'), (string)($_POST['club'] ?? ''));
```

`eventsSetAtTrack` already validates the discipline and club: for ice it requires a known club on car items, and forces `''` for drivers.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar --filter "HomePageTest|HomeSourceTest"` → PASS.
Run: `php -l index.php` → no syntax errors.
Run: `php phpunit.phar` → the full suite PASSES.

- [ ] **Step 5: Commit**

```bash
git add home-page.php index.php tests/HomePageTest.php tests/HomeSourceTest.php
git commit -m "feat(ice): Home shows ice events and records ice at-the-track choices"
```

---

### Task 4: Reminders include ice to-dos

**Files:**
- Test: `tests/RemindersLibTest.php` (add), `tests/ReminderEmailTest.php` (add)
- Modify: none expected. If a test fails, fix the smallest thing in `reminders-lib.php` / `reminder-email.php`.

**Interfaces:**
- **Consumes:** `reminderDigests()`, the reminder email renderer in `reminder-email.php`, and ice readiness items (Task 1).
- **Produces:** a guarantee that ice to-dos reach the reminder digest and email, with absolute links.

- [ ] **Step 1: Write the tests**

Add to `tests/RemindersLibTest.php`, using its fixtures:
- build a `buildReadiness()` output from an ice world, as in Task 1's `iceWorld`, with the event 7 days after `$today`;
- assert that `reminderDigests($readiness, $today)` returns one digest whose items include the label `Submit an ice tech sheet for #42`.

Add to `tests/ReminderEmailTest.php`, calling the renderer the way the existing tests do:
- render that digest with a base URL;
- assert the HTML contains `https://example.test/tech-sheets.php?action=new-ice&amp;car_id=3&amp;event_id=20` (use the same base URL string the file's other tests use);
- assert the HTML contains `Ice car tech for #42 at NASCC`.

- [ ] **Step 2: Run the tests**

Run: `php phpunit.phar --filter "RemindersLibTest|ReminderEmailTest"`.
Expected: PASS without code changes. If one fails, fix the cause in `reminders-lib.php` / `reminder-email.php` and explain it in your report.

- [ ] **Step 3: Full suite and commit**

Run: `php phpunit.phar` → PASS.

```bash
git add tests/RemindersLibTest.php tests/ReminderEmailTest.php
git commit -m "test(ice): reminders carry ice to-dos with absolute links"
```

- [ ] **Step 4: Manual check (for the human partner)**

1. Tag an ice event from Home. The card shows "Ice · NASCC", and the to-dos are "Submit an ice tech sheet", "Ice car tech … at NASCC" and "Ice gear for …". There is no "Declare class".
2. Click "I'll do it at the track" on the ice car tech item. It moves to done, and a summer event's car tech is unaffected.
3. For a driver whose summer gear was accepted this year, the ice gear item reads "from summer {year}".

---

## Self-review notes

**Spec coverage:**

| Spec section | What it asks | Where |
|---|---|---|
| §4 readiness | no declaration for ice | T1 |
| §4 readiness | ice tech per club, with at-track keyed by club | T1, T3 |
| §4 readiness | ice gear with level and FHR check | T1 |
| §4 readiness | loader uses full season keys | T2 |
| §4 Home | "Ice · CLUB" card badge | T3 |
| §4 Reminders | ice to-dos reach reminders | T4 |
| §4a | summer gear carry-over | T1, T2 |

Not in this plan:
- §4 Home "At a glance" ice rows, §4 Garage and §4a places table (Garage, Drivers, gear start, media, inspector, `driver.php`): these are Phase 4b.
- `inspect.php` roster: done in Phase 2.

**Deliberate choices:**
- **FHR evidence for photo-accepted gear is the `ice_fhr_label` photo.** In-person acceptance is trusted, because the inspector saw the gear. Summer carry-over counts as FHR-included, because summer gear requires the FHR photo.
- **Ice gear photo links from Home go through the car's ice sheet** (`start-ice`), because Phase 3 creates ice gear records from a sheet. With no ice sheet yet, the gear item offers only "at the track".
