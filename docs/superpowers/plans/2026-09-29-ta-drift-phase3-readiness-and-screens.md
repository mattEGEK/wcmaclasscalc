# TA/Drift Phase 3: Readiness and Screens Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the TA/Drift tier visible and usable. This covers:
- **Readiness:** TA/Drift items on the per-event to-do list, including a new `suggested` state.
- **Picking formats:** Race, Time Attack and Drift checkboxes when a driver says "I'm going" (Home and Garage), which they can change later.
- **Garage:** TA/Drift-only cars, TA/Drift chips and links to the TA/Drift sheet.
- **Revoke notes and gear levels:** shown to owners.
- **Inspect:** TA/Drift rows on the roster and in the review queue, and a gear level filter.
- **MotorsportReg:** Time Trial and Drift events come in from the import.
- **Seed and phone audit:** a TA/Drift event in the seed data, and phone-audit coverage.

**Architecture:**
- **Readiness:** `buildReadiness()` (pure) reads each entry's formats. The one rule `entryTierAtEvent()` turns those into a tier. For a summer entry the tier picks one of two branches:
  - race: today's logic, plus a check that accepted gear is race level
  - TA/Drift: the new `readinessTaDriftCarItems()`
- **Suggested state:** a third readiness state, `suggested`, next to `done`, `todo` and `info`. Home shows it in its own "Recommended" list. Every existing count filters on `todo`, so headlines, badges and reminders leave it out without changes.
- **Picking formats:** the picker is one view function, `homeFormatsFieldsHtml()`, shared by Home and the Garage car page. Handlers call plan 1's `eventsTagCar()` and `eventsSetFormats()`.
- **Garage:** race car tech stops counting TA/Drift sheets (`garageRaceSheets()`). TA/Drift tech gets one summary per host club (`garageTaDriftSummaries()`).

**Tech Stack:** PHP 8.3, SQLite (PDO), PHPUnit 10 (`php phpunit.phar`), Playwright phone audit (`tests/ux`).

**Spec:** `docs/superpowers/specs/2026-09-29-ta-drift-tech-design.md`. Read the Decisions table, §3 Entry, §4 (Readiness, Home/Garage/Drivers, Reminders) and §5.

### What plans 1 and 2 leave in place (this plan builds on both; merge them first)

From plan 1 (`docs/superpowers/plans/2026-09-29-ta-drift-phase1-foundations.md`, "Produces for plans 2 and 3"):

| Where | What this plan uses |
|---|---|
| `tech-status.php` | `TECH_TIER_RACE`, `TECH_TIER_TA_DRIFT`, `GEAR_LEVEL_TA_DRIFT`; `techCarKey()` (`car\|ta_drift\|CLUB\|season`); `atTrackKey($type, $id, $season, TECH_TIER_TA_DRIFT, $club)` → `car:ID@tad:CLUB:season` |
| `ta-drift-lib.php` | `ENTRY_FORMATS`, `ENTRY_FORMAT_ERROR`, `ENTRY_NO_HOST_CLUB`, `entryFormatsParse()`, `entryFormatsLabel()`, `entryTechTier()`, `techSheetIsTaDrift()`, `taDriftCarTechStatus()` (adds `tier`), `gearCoversTier()` |
| `db.php` | `db_get_user_event_plans()` rows carry `formats` and `supps_ack_at`; `db_get_at_track_keys()` returns `car:ID@tad:CLUB:season` for TA/Drift choices; `tech_sheets.revoke_note`; `gear_records.revoke_note`; `gear_records.level` = `NULL` (race) or `ta_drift` on summer rows |
| `events-lib.php` | `eventsTagCar($pdo, $uid, $eid, $cid, ?array $formats = null, bool $suppsAck = false)`; `eventsSetFormats($pdo, $uid, $eid, $cid, $formats, bool $suppsAck)`; `eventsDefaultFormats($pdo, $car, $event)`; `eventsSetAtTrack(..., TECH_TIER_TA_DRIFT, $club)` |
| `garage-lib.php` | `CAR_DISCIPLINES` includes `ta_drift`; `carSeasons('ta_drift', …)` is summer-only |
| `cars-lib.php` | `carsValidateDetails()` accepts `disciplines = ta_drift` |
| `readiness-lib.php`, `gear-lib.php` | already `require_once __DIR__ . '/ta-drift-lib.php'` (plan 1 Task 9) |
| `tests/bootstrap.php` | `test_make_ta_drift_sheet()`, `test_ta_drift_sheet_data()` |

**Assumptions about plan 2** (written in parallel; check each before starting, and if it differs, change only the one line named):

1. **The TA/Drift sheet form** opens at `tech-sheets.php?action=new-ta-drift&car_id=<id>&event_id=<id>`. The query names are the same as the existing `new-ice` route (`tech-sheets.php?action=new-ice&car_id=&event_id=`). This plan builds the URL in three places:
   - `readinessTaDriftCarItems()`: the `$newSheetUrl` line
   - `garageAfterAdd()`
   - `garageTaDriftSheetUrl()`
2. **TA/Drift gear photos** open at `gear.php?action=start-ta-drift&sheet_id=<id>`, following ice's `gear.php?action=start-ice&sheet_id=`. Only `readinessTaDriftGearPhotosUrl()` builds this URL.
3. **Revoking** fills `revoke_note` through plan 1's `db_revoke_tech_sheet_acceptance($pdo, $id, $note)` and `db_revoke_gear_acceptance($pdo, $id, $note)`. This plan only reads the column.
4. **Submitting a TA/Drift sheet** tags the event through `eventsTagCar()`, so a submitted sheet always has an entry.
5. **Plan 2 owns the Inspect sheet page and the gear review page** (the TA/Drift chip on the sheet, and the level select when accepting). This plan owns:
   - the event roster rows
   - the review queue line
   - the Gear tab list and its level filter

### Roadmap

| Phase | Delivers |
|---|---|
| 1 — Foundations | done (merge first) |
| 2 — TA/Drift sheet and gear | done (merge first) |
| **3 — Readiness and screens (this plan)** | Readiness with the `suggested` state; the formats picker on Home and Garage; TA/Drift-only cars in Garage; TA/Drift chips; revoke notes; the Drivers gear level; Inspect roster, queue and Gear filter; MotorsportReg Time Trial and Drift types; the seed event; the phone audit |

## Global Constraints

- **Paths and tests:**
  - All paths are relative to `wcma-calculator/` unless they start with `docs/`.
  - Run PHPUnit from `wcma-calculator/` with Git Bash: `cd /c/dev/wcmaclasscalc/wcma-calculator && php phpunit.phar`. The JS suite is `node --test tests/js/*.test.js`.
  - Both suites must pass at the end of every task.
- **No new dependencies and no build step.** Always use `require_once` for app includes (`tests/RequireOnceGuardTest.php` checks this).
- **Naming:** people see "TA/Drift". Code uses `ta_drift`. Never write "light" in code, copy or comments.
- **Copy, verbatim from the spec:**
  - The suggested item's label is `Check your car for {event} (recommended)`.
  - Its detail is `You're teched for {club} {year}. Going through the tech sheet before each event is how you catch a loose lug nut or a leak before it matters.`
  - The Garage car form option is `Summer TA/Drift only`.
  - Revoke notes read `Tech revoked: {note}`.
  - The no-club note is `ENTRY_NO_HOST_CLUB` (`This event has no host club yet. Ask an admin.`).
- **Race and ice must not change.** Every existing test must keep passing without edits, except where a task says otherwise. For a summer entry whose formats include Race, readiness produces exactly what it produces today; the only exception is that TA/Drift-level gear no longer counts as race gear.
- **`suggested` is never counted as outstanding.** It must not appear in:
  - `homeHeadline()`
  - the event card's "N things to do" badge
  - `reminderDigests()`
- **One tier rule:** `entryTierAtEvent()` (Task 1) is the only place an entry's stored formats become a tier at a given event. Readiness, Garage and the Inspect roster all call it.
- **Branch and commits:**
  - Branch `ta-drift-phase3` from `main` after plans 1 and 2 are merged.
  - Commit at the end of every task.
  - Every commit message ends with:
    ```
    Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
    Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5
    ```

## Review Focus

1. **TA/Drift gear on a race entry.** A driver whose summer gear was accepted at TA/Drift level must get a to-do for a race entry, not a tick. Test: Task 1, `testRaceEntryNeedsRaceLevelGear`.
2. **A TA event before a race event in the same season.** Season-wide items are listed only once (`$once`). The race event later in the season must still get race car tech and race gear, even though the TA event came first. Test: Task 2, `testRaceEventAfterATaEventStillGetsRaceItems`.
3. **A TA/Drift sheet counted as race car tech in Garage.** `garageSummerSheets()` includes TA/Drift sheets. Without the fix, an accepted TA/Drift sheet would show "Teched" on the race Car tech chip. Test: Task 6, `testTaDriftSheetDoesNotCountAsRaceCarTech`.
4. **A TA/Drift-only car offered for an ice event on Home.** `homeCarsForEvent()` only excludes `summer` cars from ice events. Test: Task 4, `testTaDriftOnlyCarsAreNotOfferedIceEvents`.
5. **"I'm going" posted with every format unticked.** This must be refused ("Choose at least one…"). It must not tag the car, and it must not quietly fall back to the defaults. Test: Task 4, `testTaggingWithNothingTickedIsRefused`.

---

### Task 1: Entries in readiness, the tier rule, and race-level gear

**Files:**
- Modify: `ta-drift-lib.php` (add `entryTierAtEvent`, `gearLevelSuffix`)
- Modify: `readiness-lib.php` (`buildReadiness`, `loadReadinessInputs`, new `readinessEntry`)
- Test: `tests/ReadinessTaDriftTest.php` (new)

**Interfaces:**
- Consumes (plan 1): `entryFormatsParse`, `entryTechTier`, `gearCoversTier`, `techSheetIsTaDrift`, `TECH_TIER_*`, `GEAR_LEVEL_TA_DRIFT`.
- Produces:
  - `entryTierAtEvent(array $event, ?string $stored): string`:
    - ice events return `TECH_TIER_RACE`
    - a summer event with no host club returns `TECH_TIER_RACE`
    - otherwise it returns `entryTechTier(entryFormatsParse($stored))`
  - `gearLevelSuffix(?array $gear): string`: `' · TA/Drift'` for accepted gear at level `ta_drift`, otherwise `''`.
  - `readinessEntry(array $event, ?array $plan): array{formats: string[], tier: string, supps_ack_at: ?string}`
  - Each row in `buildReadiness()['events']` gains `'entries' => array<int carId, readinessEntry()>`.
  - `loadReadinessInputs()` `plans` rows carry `formats` and `supps_ack_at`.
  - Race entries: accepted summer gear at level `ta_drift` becomes a `todo` gear item.

- [ ] **Step 1: Create the branch**

```bash
cd /c/dev/wcmaclasscalc && git checkout main && git pull --ff-only && git checkout -b ta-drift-phase3
```

- [ ] **Step 2: Write the failing test**

```php
<?php
// wcma-calculator/tests/ReadinessTaDriftTest.php
require_once __DIR__ . '/../tech-status.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../readiness-lib.php';
require_once __DIR__ . '/../events-lib.php';

use PHPUnit\Framework\TestCase;

final class ReadinessTaDriftTest extends TestCase
{
    private function world(array $o = []): array {
        return array_merge([
            'today' => '2026-06-01',
            'cars' => [3 => ['id' => 3, 'car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'disciplines' => 'ta_drift']],
            'events' => [
                ['id' => 20, 'name' => 'WSCC TA #1', 'event_date' => '2026-07-12', 'discipline' => 'summer', 'host_club' => 'WSCC'],
                ['id' => 21, 'name' => 'WSCC TA #2', 'event_date' => '2026-08-16', 'discipline' => 'summer', 'host_club' => 'WSCC'],
                ['id' => 22, 'name' => 'Open Day', 'event_date' => '2026-08-20', 'discipline' => 'summer', 'host_club' => null],
            ],
            'plans' => [['event_id' => 20, 'car_id' => 3, 'formats' => 'ta', 'supps_ack_at' => null]],
            'declarations' => [], 'sheets' => [], 'sheetDrivers' => [],
            'drivers' => [5 => ['id' => 5, 'name' => 'Jordan Lee'], 6 => ['id' => 6, 'name' => 'Sam Patel']],
            'selfDriverId' => 5, 'gear' => [], 'atTrack' => [],
        ], $o);
    }

    private function items(array $r, int $eventIndex = 0): array {
        $out = [];
        foreach ($r['events'][$eventIndex]['items'] as $i) $out[$i['kind'] . ':' . $i['subject_id']] = $i;
        return $out;
    }

    public function testTierAtEvent(): void
    {
        $summer = ['discipline' => 'summer', 'host_club' => 'WSCC'];
        $this->assertSame(TECH_TIER_TA_DRIFT, entryTierAtEvent($summer, 'ta,drift'));
        $this->assertSame(TECH_TIER_RACE, entryTierAtEvent($summer, 'race,ta'));
        $this->assertSame(TECH_TIER_RACE, entryTierAtEvent($summer, null));                                   // legacy entry
        $this->assertSame(TECH_TIER_RACE, entryTierAtEvent(['discipline' => 'summer', 'host_club' => ''], 'ta'));   // club removed since
        $this->assertSame(TECH_TIER_RACE, entryTierAtEvent(['discipline' => 'ice', 'host_club' => 'WSCC'], 'drift'));
    }

    public function testGearLevelSuffix(): void
    {
        $this->assertSame(' · TA/Drift', gearLevelSuffix(['status' => 'accepted', 'level' => 'ta_drift']));
        $this->assertSame('', gearLevelSuffix(['status' => 'accepted', 'level' => null]));
        $this->assertSame('', gearLevelSuffix(['status' => 'open', 'level' => 'ta_drift']));
        $this->assertSame('', gearLevelSuffix(null));
    }

    public function testEntriesCarryFormatsAndTier(): void
    {
        $r = buildReadiness($this->world());
        $this->assertSame(['formats' => ['ta'], 'tier' => TECH_TIER_TA_DRIFT, 'supps_ack_at' => null], $r['events'][0]['entries'][3]);
    }

    public function testLegacyPlanWithoutFormatsIsRace(): void
    {
        $r = buildReadiness($this->world(['plans' => [['event_id' => 20, 'car_id' => 3]]]));
        $this->assertSame(TECH_TIER_RACE, $r['events'][0]['entries'][3]['tier']);
        $this->assertArrayHasKey('declaration:3', $this->items($r));
    }

    public function testTaDriftEntryAtEventWithoutHostClubFallsBackToRace(): void
    {
        $r = buildReadiness($this->world(['plans' => [['event_id' => 22, 'car_id' => 3, 'formats' => 'ta', 'supps_ack_at' => null]]]));
        $this->assertSame(TECH_TIER_RACE, $r['events'][0]['entries'][3]['tier']);
        $this->assertSame('Submit a tech sheet for #86', $this->items($r)['tech_sheet:3']['label']);
    }

    public function testRaceEntryNeedsRaceLevelGear(): void
    {
        $tadGear = ['id' => 40, 'season' => 2026, 'discipline' => 'summer', 'level' => 'ta_drift', 'status' => 'accepted', 'accepted_via' => 'in_person', 'photo_status' => null];
        $r = buildReadiness($this->world([
            'plans' => [['event_id' => 20, 'car_id' => 3, 'formats' => 'race', 'supps_ack_at' => null]],
            'gear' => ['5:2026' => $tadGear],
        ]));
        $gear = $this->items($r)['gear:5'];
        $this->assertSame('todo', $gear['state']);
        $this->assertSame("Jordan Lee's gear is checked for TA/Drift; racing needs race-level gear", $gear['label']);
        $this->assertSame('Bring race-level gear to tech at the track.', $gear['detail']);

        $race = ['level' => null] + $tadGear;
        $r = buildReadiness($this->world(['plans' => [['event_id' => 20, 'car_id' => 3, 'formats' => 'race', 'supps_ack_at' => null]], 'gear' => ['5:2026' => $race]]));
        $this->assertSame('done', $this->items($r)['gear:5']['state']);
    }

    public function testLoaderCarriesFormatsAndTheRegulationsTick(): void
    {
        $pdo = make_temp_pdo();
        $uid = db_create_user($pdo, ['email' => 'tad' . uniqid() . '@example.com', 'name' => 'TA Driver', 'password_hash' => 'x', 'google_id' => null]);
        $car = test_make_car($pdo, $uid, '86');
        $event = db_create_event($pdo, 'WSCC TA', date('Y-m-d', strtotime('+10 days')), null, 'summer', 'WSCC');
        $this->assertTrue(eventsTagCar($pdo, $uid, $event, $car, ['ta'], true)['ok']);
        $plan = loadReadinessInputs($pdo, $uid, date('Y-m-d'))['plans'][0];
        $this->assertSame('ta', $plan['formats']);
        $this->assertNotNull($plan['supps_ack_at']);
    }
}
```

- [ ] **Step 3: Run the test to confirm it fails**

Run: `php phpunit.phar --filter ReadinessTaDriftTest`
Expected: FAIL. `entryTierAtEvent` is undefined.

- [ ] **Step 4: Add the two helpers to `ta-drift-lib.php`**

Append to the end of `ta-drift-lib.php`:

```php

/**
 * The tech an entry needs at $event (TA/Drift spec §4): ice events are race; a summer TA/Drift entry
 * needs the event's host club (TA/Drift tech is per club), so an event without one is race. The
 * only place an entry's stored formats become a tier.
 */
function entryTierAtEvent(array $event, ?string $stored): string {
    if (($event['discipline'] ?? DISCIPLINE_SUMMER) === DISCIPLINE_ICE) return TECH_TIER_RACE;
    if (trim((string)($event['host_club'] ?? '')) === '') return TECH_TIER_RACE;
    return entryTechTier(entryFormatsParse($stored));
}

/** ' · TA/Drift' after an accepted summer gear status that is TA/Drift level, else ''. */
function gearLevelSuffix(?array $gear): string {
    return $gear !== null && ($gear['status'] ?? '') === 'accepted' && ($gear['level'] ?? null) === GEAR_LEVEL_TA_DRIFT ? ' · TA/Drift' : '';
}
```

- [ ] **Step 5: Add `readinessEntry` and use it in `buildReadiness`**

In `readiness-lib.php`, directly above `function buildReadiness(array $in): array {`, add:

```php
/**
 * One car's entry at one event: its formats, the tech tier they need there (entryTierAtEvent()),
 * and when the supplementary-regulations box was ticked. $plan is its event_plans row, or null.
 * @return array{formats: string[], tier: string, supps_ack_at: ?string}
 */
function readinessEntry(array $event, ?array $plan): array {
    $stored = isset($plan['formats']) ? (string)$plan['formats'] : null;
    return ['formats' => entryFormatsParse($stored), 'tier' => entryTierAtEvent($event, $stored),
            'supps_ack_at' => $plan['supps_ack_at'] ?? null];
}

```

In `buildReadiness`, replace:

```php
    $carsByEvent = [];
    foreach ($in['plans'] as $p) {
        if (isset($in['cars'][(int)$p['car_id']])) $carsByEvent[(int)$p['event_id']][(int)$p['car_id']] = true;
    }
```

with:

```php
    $carsByEvent = [];
    $plansByEvent = [];
    foreach ($in['plans'] as $p) {
        if (!isset($in['cars'][(int)$p['car_id']])) continue;
        $carsByEvent[(int)$p['event_id']][(int)$p['car_id']] = true;
        $plansByEvent[(int)$p['event_id']][(int)$p['car_id']] = $p;
    }
```

Replace:

```php
        $key = seasonForEvent($event);
        $season = $key['season'];
        $items = [];
```

with:

```php
        $key = seasonForEvent($event);
        $season = $key['season'];
        $items = [];
        $entries = [];
```

Replace:

```php
            $car = $in['cars'][$carId];
            $n = '#' . $car['car_number'];

            if ($key['discipline'] === DISCIPLINE_ICE) {
```

with:

```php
            $car = $in['cars'][$carId];
            $n = '#' . $car['car_number'];
            $entries[$carId] = readinessEntry($event, $plansByEvent[$eid][$carId] ?? null);

            if ($key['discipline'] === DISCIPLINE_ICE) {
```

Replace:

```php
        $events[] = ['event' => $event, 'items' => $items];
```

with:

```php
        $events[] = ['event' => $event, 'items' => $items, 'entries' => $entries];
```

- [ ] **Step 6: Race entries need race-level gear**

In `buildReadiness`, in the summer gear loop, replace:

```php
                $gear = $in['gear']["$did:$season"] ?? null;
                $status = $gear !== null ? gearStatus($gear) : ['state' => 'none', 'via' => null];
```

with:

```php
                $gear = $in['gear']["$did:$season"] ?? null;
                if ($gear !== null && gearStatus($gear)['state'] === 'accepted' && !gearCoversTier($gear, TECH_TIER_RACE)) {
                    // Accepted at TA/Drift level only (TA/Drift spec §2): racing needs race-level gear.
                    $items[] = readinessItem('gear', 'driver', $did, 'todo', "$name's gear is checked for TA/Drift; racing needs race-level gear",
                        'Bring race-level gear to tech at the track.');
                    continue;
                }
                $status = $gear !== null ? gearStatus($gear) : ['state' => 'none', 'via' => null];
```

- [ ] **Step 7: The loader carries the formats**

In `loadReadinessInputs`, replace:

```php
        'plans' => array_map(fn(array $p): array => ['event_id' => (int)$p['event_id'], 'car_id' => (int)$p['car_id']], db_get_user_event_plans($pdo, $userId)),
```

with:

```php
        'plans' => array_map(fn(array $p): array => ['event_id' => (int)$p['event_id'], 'car_id' => (int)$p['car_id'],
            'formats' => (string)($p['formats'] ?? 'race'), 'supps_ack_at' => $p['supps_ack_at'] ?? null], db_get_user_event_plans($pdo, $userId)),
```

- [ ] **Step 8: Run the test to confirm it passes, then run the full suite**

Run: `php phpunit.phar --filter ReadinessTaDriftTest` → PASS. Then `php phpunit.phar` → all PASS.

`ReadinessTest` and `ReadinessLoaderTest` still pass, for two reasons:
- Their plans have no `formats`, so every entry reads as race.
- Their gear rows have no `level`, and `gearCoversTier` treats a missing level as race.

- [ ] **Step 9: Commit**

```bash
git add ta-drift-lib.php readiness-lib.php tests/ReadinessTaDriftTest.php
git commit -m "feat(ta-drift): readiness reads each entry's formats; race entries need race-level gear

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 2: TA/Drift to-dos and the `suggested` state

**Files:**
- Modify: `readiness-lib.php` (new `readinessTaDriftCarItems`, `readinessTaDriftGearPhotosUrl`; the summer branch in `buildReadiness`)
- Test: `tests/ReadinessTaDriftTest.php` (add tests)

**Interfaces:**
- Consumes: Task 1's `readinessEntry`, and plan 1's `taDriftCarTechStatus`, `gearCoversTier` and `atTrackKey(..., TECH_TIER_TA_DRIFT, $club)`.
- Produces:
  - `readinessTaDriftCarItems(array $in, array $event, int $season, int $carId, array $entry, array $sheetsByCar, array $atTrack, callable $once): array`
  - `readinessTaDriftGearPhotosUrl(?int $sheetId): ?string`
  - The new item kind `supps` (subject `car`).
  - The new item state `suggested`.
  - The `$once` keys `tad_car_tech:CLUB:carId` and `tad_gear:driverId`. These are separate from race's `car_tech:carId` and `gear:driverId`.
  - The race branch no longer counts TA/Drift sheets, either as the event's sheet or as season car tech.

- [ ] **Step 1: Write the failing tests**

Add these methods to `tests/ReadinessTaDriftTest.php`, inside the class:

```php
    private function sheet(int $id, int $eventId, array $o = []): array {
        return array_merge(['id' => $id, 'car_id' => 3, 'event_id' => $eventId, 'season' => 2026, 'discipline' => 'summer',
            'sheet_type' => 'ta_drift', 'club' => 'WSCC', 'status' => 'submitted', 'accepted_via' => null,
            'photo_status' => null, 'driver_id' => 5], $o);
    }

    public function testTaOnlyEntryAsksForTheTaDriftSheetAndNoDeclaration(): void
    {
        $items = $this->items(buildReadiness($this->world()));
        $this->assertEqualsCanonicalizing(['tech_sheet:3', 'car_tech:3', 'gear:5', 'supps:3'], array_keys($items));   // self driver only

        $sheet = $items['tech_sheet:3'];
        $this->assertSame(['todo', 'Submit a TA/Drift tech sheet for #86'], [$sheet['state'], $sheet['label']]);
        $this->assertSame('Check each item on the car before you tick it. One accepted sheet covers WSCC for 2026.', $sheet['detail']);
        $this->assertSame(['label' => 'Submit TA/Drift tech sheet', 'url' => 'tech-sheets.php?action=new-ta-drift&car_id=3&event_id=20'], $sheet['action']);

        $tech = $items['car_tech:3'];
        $this->assertSame(['todo', 'TA/Drift car tech for #86 at WSCC'], [$tech['state'], $tech['label']]);
        $this->assertSame(['subject_type' => 'car', 'subject_id' => 3, 'season' => 2026, 'discipline' => TECH_TIER_TA_DRIFT, 'club' => 'WSCC'], $tech['at_track']);

        $this->assertSame(['todo', 'TA/Drift gear for Jordan Lee'], [$items['gear:5']['state'], $items['gear:5']['label']]);
        $supps = $items['supps:3'];
        $this->assertSame(['todo', "Confirm you've read the WSCC supplementary regulations for #86"], [$supps['state'], $supps['label']]);
        $this->assertSame('index.php?event=20#event-20', $supps['action']['url']);
    }

    public function testSheetIsSuggestedOnceTheCarIsApprovedForTheClub(): void
    {
        $r = buildReadiness($this->world([
            'plans' => [['event_id' => 21, 'car_id' => 3, 'formats' => 'ta', 'supps_ack_at' => '2026-06-01 10:00:00']],
            'sheets' => [$this->sheet(9, 20, ['status' => 'teched', 'accepted_via' => 'in_person'])],
        ]));
        $items = $this->items($r);
        $sheet = $items['tech_sheet:3'];
        $this->assertSame('suggested', $sheet['state']);
        $this->assertSame('Check your car for WSCC TA #2 (recommended)', $sheet['label']);
        $this->assertSame("You're teched for WSCC 2026. Going through the tech sheet before each event is how you catch a loose lug nut or a leak before it matters.", $sheet['detail']);
        $this->assertSame('tech-sheets.php?action=new-ta-drift&car_id=3&event_id=21', $sheet['action']['url']);
        $this->assertSame(['done', 'TA/Drift car tech 2026 for #86 at WSCC: teched'], [$items['car_tech:3']['state'], $items['car_tech:3']['label']]);
        $this->assertSame('done', $items['supps:3']['state']);
    }

    public function testRaceTechCoversTaDriftCarTech(): void
    {
        $race = $this->sheet(8, 22, ['sheet_type' => 'standard', 'club' => null, 'status' => 'teched', 'accepted_via' => 'photos']);
        $items = $this->items(buildReadiness($this->world(['sheets' => [$race]])));
        $this->assertSame('suggested', $items['tech_sheet:3']['state']);
        $this->assertSame(['done', 'TA/Drift car tech 2026 for #86 at WSCC: covered by race tech'], [$items['car_tech:3']['state'], $items['car_tech:3']['label']]);
    }

    public function testATaDriftSheetAtAnotherClubDoesNotCount(): void
    {
        $nascc = $this->sheet(9, 99, ['club' => 'NASCC', 'status' => 'teched', 'accepted_via' => 'in_person']);
        $items = $this->items(buildReadiness($this->world(['sheets' => [$nascc]])));
        $this->assertSame('todo', $items['tech_sheet:3']['state']);
        $this->assertSame('todo', $items['car_tech:3']['state']);
    }

    public function testSubmittingThisEventsSheetDoesTheSheetAndTheRegulations(): void
    {
        $items = $this->items(buildReadiness($this->world(['sheets' => [$this->sheet(9, 20)]])));
        $this->assertSame(['done', 'TA/Drift tech sheet for WSCC TA #1 submitted'], [$items['tech_sheet:3']['state'], $items['tech_sheet:3']['label']]);
        $this->assertSame(['done', '#86: WSCC supplementary regulations read'], [$items['supps:3']['state'], $items['supps:3']['label']]);
        $this->assertSame('todo', $items['car_tech:3']['state']);
        $this->assertSame('tech-sheets.php?action=pretech&id=9', $items['car_tech:3']['action']['url']);
    }

    public function testTheRegulationsTickCounts(): void
    {
        $items = $this->items(buildReadiness($this->world(['plans' => [['event_id' => 20, 'car_id' => 3, 'formats' => 'drift', 'supps_ack_at' => '2026-06-01 10:00:00']]])));
        $this->assertSame('done', $items['supps:3']['state']);
    }

    public function testGearDriversComeFromTheEventSheetThenTheLatestAcceptedSheetThenSelf(): void
    {
        $withSheet = $this->items(buildReadiness($this->world(['sheets' => [$this->sheet(9, 20, ['driver_id' => 6])]])));
        $this->assertArrayHasKey('gear:6', $withSheet);
        $this->assertArrayNotHasKey('gear:5', $withSheet);

        $accepted = $this->sheet(9, 19, ['driver_id' => 6, 'status' => 'teched', 'accepted_via' => 'in_person']);
        $fromSeason = $this->items(buildReadiness($this->world(['sheets' => [$accepted], 'sheetDrivers' => [9 => [5]]])));
        $this->assertArrayHasKey('gear:6', $fromSeason);
        $this->assertArrayHasKey('gear:5', $fromSeason);
        $this->assertSame('gear.php?action=start-ta-drift&sheet_id=9', $fromSeason['gear:6']['action']['url']);
    }

    public function testTaDriftGearAndRaceGearBothCoverATaEntry(): void
    {
        $gear = fn(?string $level): array => ['5:2026' => ['id' => 40, 'season' => 2026, 'discipline' => 'summer', 'level' => $level,
            'status' => 'accepted', 'accepted_via' => 'photos', 'photo_status' => 'accepted']];
        $tad = $this->items(buildReadiness($this->world(['gear' => $gear('ta_drift')])))['gear:5'];
        $this->assertSame(['done', 'Gear for Jordan Lee: pre-teched 2026 · TA/Drift'], [$tad['state'], $tad['label']]);
        $race = $this->items(buildReadiness($this->world(['gear' => $gear(null)])))['gear:5'];
        $this->assertSame(['done', 'Gear for Jordan Lee: pre-teched 2026 · race level'], [$race['state'], $race['label']]);
    }

    public function testAtTheTrackChoiceIsPerClub(): void
    {
        $items = $this->items(buildReadiness($this->world(['atTrack' => ['car:3@tad:WSCC:2026']])));
        $this->assertSame(['done', "TA/Drift car tech for #86: you'll bring it to tech at the track"], [$items['car_tech:3']['state'], $items['car_tech:3']['label']]);
        $race = $this->items(buildReadiness($this->world(['atTrack' => ['car:3@2026']])));
        $this->assertSame('todo', $race['car_tech:3']['state']);   // the race choice doesn't cover TA/Drift
    }

    public function testRaceEventAfterATaEventStillGetsRaceItems(): void
    {
        $r = buildReadiness($this->world([
            'cars' => [3 => ['id' => 3, 'car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'disciplines' => 'summer']],
            'plans' => [['event_id' => 20, 'car_id' => 3, 'formats' => 'ta', 'supps_ack_at' => null],
                        ['event_id' => 21, 'car_id' => 3, 'formats' => 'race,ta', 'supps_ack_at' => null]],
        ]));
        $race = $this->items($r, 1);
        $this->assertSame('Submit a tech sheet for #86', $race['tech_sheet:3']['label']);
        $this->assertArrayHasKey('declaration:3', $race);
        $this->assertSame('Car tech for #86', $race['car_tech:3']['label']);
        $this->assertArrayHasKey('gear:5', $race);
        $this->assertArrayHasKey('gear:6', $race);
        $this->assertArrayNotHasKey('supps:3', $race);
    }

    public function testRaceSheetsIgnoreTaDriftSheets(): void
    {
        $r = buildReadiness($this->world([
            'cars' => [3 => ['id' => 3, 'car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'disciplines' => 'summer']],
            'plans' => [['event_id' => 20, 'car_id' => 3, 'formats' => 'race', 'supps_ack_at' => null]],
            'sheets' => [$this->sheet(9, 20, ['status' => 'teched', 'accepted_via' => 'in_person'])],
        ]));
        $items = $this->items($r);
        $this->assertSame('todo', $items['tech_sheet:3']['state']);   // a TA/Drift sheet is not this event's race sheet
        $this->assertSame('todo', $items['car_tech:3']['state']);     // and doesn't accept race car tech
    }
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php phpunit.phar --filter ReadinessTaDriftTest`
Expected: FAIL. TA entries still get the race items (`declaration:3` is present, and there's no `supps:3`).

- [ ] **Step 3: Implement the TA/Drift items**

In `readiness-lib.php`, directly above the `readinessEntry` doc comment added in Task 1, add:

```php
/**
 * TA/Drift gear photos open from a TA/Drift tech sheet (plan 2's gear.php route, shaped like ice's
 * start-ice). Null when the driver isn't on one yet. The only place this URL is built.
 */
function readinessTaDriftGearPhotosUrl(?int $sheetId): ?string {
    return $sheetId !== null ? 'gear.php?action=start-ta-drift&sheet_id=' . $sheetId : null;
}

/**
 * TA/Drift to-dos for one car at one summer event (TA/Drift spec §4). No class declaration.
 * 1. The sheet for this event: required until the car is approved for the club and year, then
 *    'suggested' (the driver's own safety check, not counted as outstanding).
 * 2. TA/Drift car tech for the host club and year; accepted race tech for the year covers it.
 * 3. Gear for each driver: this event's sheet, else the newest accepted TA/Drift sheet for the club
 *    and year, else the account's own driver. TA/Drift or race-level gear both cover it.
 * 4. The supplementary-regulations tick, or this event's TA/Drift sheet.
 * $once de-duplicates the season-wide items (2 and 3) across events, with keys of their own so a
 * race entry later in the season still gets race car tech and race gear.
 */
function readinessTaDriftCarItems(array $in, array $event, int $season, int $carId, array $entry, array $sheetsByCar, array $atTrack, callable $once): array {
    $eid = (int)$event['id'];
    $club = trim((string)$event['host_club']);
    $n = '#' . $in['cars'][$carId]['car_number'];
    $safeN = str_replace('%', '%%', $n);
    $newSheetUrl = "tech-sheets.php?action=new-ta-drift&car_id=$carId&event_id=$eid";
    $items = [];

    $carSheets = $sheetsByCar[$carId] ?? [];
    $raceSheets = array_values(array_filter($carSheets, fn(array $s): bool =>
        (int)$s['season'] === $season && !techSheetIsIce($s) && !techSheetIsTaDrift($s)));
    $clubSheets = array_values(array_filter($carSheets, fn(array $s): bool =>
        techSheetIsTaDrift($s) && (string)($s['club'] ?? '') === $club && (int)$s['season'] === $season));
    $status = taDriftCarTechStatus(techCarStatus($raceSheets), techCarStatus($clubSheets));
    $clubIds = array_map(fn(array $s): int => (int)$s['id'], $clubSheets);
    $latestClubSheet = $clubIds ? max($clubIds) : null;

    $eventSheet = null;
    foreach ($carSheets as $s) {
        if ((int)$s['event_id'] === $eid && !techSheetIsIce($s)) { $eventSheet = $s; break; }
    }

    if ($eventSheet !== null) {
        $items[] = readinessItem('tech_sheet', 'car', $carId, 'done',
            (techSheetIsTaDrift($eventSheet) ? 'TA/Drift tech sheet' : 'Tech sheet') . ' for ' . $event['name'] . ' submitted');
    } elseif ($status['state'] === 'accepted') {
        $items[] = readinessItem('tech_sheet', 'car', $carId, 'suggested', 'Check your car for ' . $event['name'] . ' (recommended)',
            "You're teched for $club $season. Going through the tech sheet before each event is how you catch a loose lug nut or a leak before it matters.",
            ['label' => 'Go through the tech sheet', 'url' => $newSheetUrl]);
    } else {
        $items[] = readinessItem('tech_sheet', 'car', $carId, 'todo', "Submit a TA/Drift tech sheet for $n",
            "Check each item on the car before you tick it. One accepted sheet covers $club for $season.",
            ['label' => 'Submit TA/Drift tech sheet', 'url' => $newSheetUrl]);
    }

    if ($once("tad_car_tech:$club:$carId")) {
        if ($status['state'] === 'accepted' && $status['tier'] === TECH_TIER_RACE) {
            $items[] = readinessItem('car_tech', 'car', $carId, 'done', "TA/Drift car tech $season for $n at $club: covered by race tech");
        } else {
            $items[] = readinessTech('car_tech', 'car', $carId, $status, $season,
                isset($atTrack[atTrackKey('car', $carId, $season, TECH_TIER_TA_DRIFT, $club)]), [
                'label' => "TA/Drift car tech for $n at $club", 'doneLabel' => "TA/Drift car tech %2\$d for $safeN at $club: %1\$s",
                'pendingLabel' => "TA/Drift car tech photos for $n are with an inspector", 'retakeLabel' => "Retake TA/Drift car photos for $n",
                'atTrackLabel' => "TA/Drift car tech for $n: you'll bring it to tech at the track",
            ], $latestClubSheet !== null ? 'tech-sheets.php?action=pretech&id=' . $latestClubSheet : null,
               $status['sheet_id'] !== null ? 'tech-sheets.php?action=pretech&id=' . $status['sheet_id'] : null,
               ['discipline' => TECH_TIER_TA_DRIFT, 'club' => $club],
               ['with' => 'Pre-tech with photos from your TA/Drift tech sheet, or bring it to tech at the track.',
                'without' => 'Pre-tech with photos after you submit the TA/Drift tech sheet, or bring it to tech at the track.']);
        }
    }

    $source = $eventSheet;
    if ($source === null) {
        foreach ($clubSheets as $s) {
            if (($s['status'] ?? '') === 'teched' && ($source === null || (int)$s['id'] > (int)$source['id'])) $source = $s;
        }
    }
    $driverIds = $source !== null
        ? array_merge([(int)($source['driver_id'] ?? 0)], array_map('intval', $in['sheetDrivers'][(int)$source['id']] ?? []))
        : [(int)$in['selfDriverId']];
    foreach (array_values(array_unique(array_filter($driverIds))) as $did) {
        if (!isset($in['drivers'][$did]) || !$once("tad_gear:$did")) continue;
        $name = (string)$in['drivers'][$did]['name'];
        $safeName = str_replace('%', '%%', $name);
        $gear = $in['gear']["$did:$season"] ?? null;
        if (gearCoversTier($gear, TECH_TIER_TA_DRIFT)) {
            $via = ($gear['accepted_via'] ?? 'in_person') === 'photos' ? 'pre-teched' : 'teched';
            $level = gearCoversTier($gear, TECH_TIER_RACE) ? 'race level' : 'TA/Drift';
            $items[] = readinessItem('gear', 'driver', $did, 'done', "Gear for $name: $via $season · $level");
            continue;
        }
        $items[] = readinessTech('gear', 'driver', $did, $gear !== null ? gearStatus($gear) : ['state' => 'none', 'via' => null], $season,
            isset($atTrack["driver:$did@$season"]), [
            'label' => "TA/Drift gear for $name", 'doneLabel' => "Gear for $safeName: %s $season",
            'pendingLabel' => "Gear photos for $name are with an inspector", 'retakeLabel' => "Retake gear photos for $name",
            'atTrackLabel' => "Gear for $name: checked at the track",
        ], readinessTaDriftGearPhotosUrl($latestClubSheet), $gear !== null ? 'gear.php?action=pretech&id=' . (int)$gear['id'] : null, [],
           ['with' => 'Pre-tech your helmet with photos, or bring your gear to tech at the track.',
            'without' => 'Bring it to tech at the track, or add photos once this driver is on a TA/Drift tech sheet.']);
    }

    $ticked = ($entry['supps_ack_at'] ?? null) !== null || ($eventSheet !== null && techSheetIsTaDrift($eventSheet));
    $items[] = $ticked
        ? readinessItem('supps', 'car', $carId, 'done', "$n: $club supplementary regulations read")
        : readinessItem('supps', 'car', $carId, 'todo', "Confirm you've read the $club supplementary regulations for $n",
            "Tick the box on this event's card, or submit this event's TA/Drift tech sheet.",
            ['label' => 'Confirm', 'url' => 'index.php?event=' . $eid . '#event-' . $eid]);
    return $items;
}

```

The `testGearDriversComeFrom…` test expects `sheet_id=9` for driver 6. That's the newest TA/Drift sheet for the club and year (`$latestClubSheet`), which here is the accepted sheet itself.

- [ ] **Step 4: Branch the summer entries on the tier, and keep race sheets race-only**

In `buildReadiness`, replace:

```php
                continue;
            }
            $safeN = str_replace('%', '%%', $n);

            $seasonSheets = array_values(array_filter($sheetsByCar[$carId] ?? [], fn(array $s): bool => (int)$s['season'] === $season && !techSheetIsIce($s)));
            $eventSheet = null;
            foreach ($sheetsByCar[$carId] ?? [] as $s) {
                if ((int)$s['event_id'] === $eid && !techSheetIsIce($s)) { $eventSheet = $s; break; }
            }
```

with:

```php
                continue;
            }
            if ($entries[$carId]['tier'] === TECH_TIER_TA_DRIFT) {
                $items = array_merge($items, readinessTaDriftCarItems($in, $event, $season, $carId, $entries[$carId], $sheetsByCar, $atTrack, $once));
                continue;
            }
            $safeN = str_replace('%', '%%', $n);

            // Race tech: TA/Drift sheets are neither this event's race sheet nor race car tech (TA/Drift spec §2).
            $isRaceSheet = fn(array $s): bool => !techSheetIsIce($s) && !techSheetIsTaDrift($s);
            $seasonSheets = array_values(array_filter($sheetsByCar[$carId] ?? [], fn(array $s): bool => (int)$s['season'] === $season && $isRaceSheet($s)));
            $eventSheet = null;
            foreach ($sheetsByCar[$carId] ?? [] as $s) {
                if ((int)$s['event_id'] === $eid && $isRaceSheet($s)) { $eventSheet = $s; break; }
            }
```

Update the file's header comment. Replace `// What a competitor still has to do for the events they tagged (spec §3), for both summer and ice` with `// What a competitor still has to do for the events they tagged (spec §3), for summer, TA/Drift and ice`. Also add this sentence: `// Item states: done, todo, info (with an inspector) and suggested (TA/Drift spec §4: shown, never counted).`

- [ ] **Step 5: Run the tests to confirm they pass, then run the full suite**

Run: `php phpunit.phar --filter ReadinessTaDriftTest` → PASS. Then `php phpunit.phar` → all PASS.

- [ ] **Step 6: Commit**

```bash
git add readiness-lib.php tests/ReadinessTaDriftTest.php
git commit -m "feat(ta-drift): TA/Drift to-dos per entry, with the per-event sheet suggested once approved

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 3: Home shows suggested items, and each event card has an anchor

**Files:**
- Modify: `home-page.php` (`homeRenderTodoItem`, `renderHomeHtml`, `homeEventCardHtml`)
- Modify: `css/hub.css` (the suggested list)
- Test: `tests/HomeTaDriftTest.php` (new)

**Interfaces:**
- Consumes: readiness items with `state = 'suggested'` (Task 2).
- Produces:
  - `homeRenderTodoItem(int $n, array $item, string $csrf, bool $suggested = false): string`
  - Home renders a `<h2>Recommended</h2><ul class="hub-todo hub-todo--suggested">` list after the numbered to-dos.
  - Every event card is `<section class="hub-card hub-event" id="event-{id}">`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/HomeTaDriftTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../events-lib.php';
require_once __DIR__ . '/../clubs-lib.php';
require_once __DIR__ . '/../reminders-lib.php';
require_once __DIR__ . '/../home-page.php';

use PHPUnit\Framework\TestCase;

final class HomeTaDriftTest extends TestCase
{
    private function item(string $kind, string $state, string $label, ?array $action = null, ?array $atTrack = null): array {
        return ['kind' => $kind, 'subject_type' => $kind === 'gear' ? 'driver' : 'car', 'subject_id' => 3, 'state' => $state,
                'label' => $label, 'detail' => '', 'action' => $action, 'at_track' => $atTrack];
    }

    private function vm(array $items, array $o = []): array {
        return array_merge([
            'name' => 'Jordan Lee',
            'readiness' => ['events' => [[
                'event' => ['id' => 20, 'name' => 'WSCC TA #2', 'event_date' => '2099-08-16', 'discipline' => 'summer', 'host_club' => 'WSCC'],
                'items' => $items,
                'entries' => [3 => ['formats' => ['ta'], 'tier' => 'ta_drift', 'supps_ack_at' => null]],
            ]], 'untagged' => []],
            'cars' => [3 => ['id' => 3, 'car_number' => '86', 'year' => '2017', 'make' => 'Subaru', 'model' => 'BRZ', 'disciplines' => 'ta_drift']],
            'garage' => [], 'drivers' => [], 'seasonLinks' => [], 'csrf' => 'tok',
        ], $o);
    }

    public function testSuggestedItemsAreListedApartAndNeverCounted(): void
    {
        $suggested = $this->item('tech_sheet', 'suggested', 'Check your car for WSCC TA #2 (recommended)',
            ['label' => 'Go through the tech sheet', 'url' => 'tech-sheets.php?action=new-ta-drift&car_id=3&event_id=20']);
        $html = renderHomeHtml($this->vm([$suggested, $this->item('car_tech', 'done', 'TA/Drift car tech 2099 for #86 at WSCC: teched')]));

        $this->assertStringContainsString(h("You're all set for WSCC TA #2"), $html);
        $this->assertStringContainsString('<h2>Recommended</h2><ul class="hub-todo hub-todo--suggested">', $html);
        $this->assertStringContainsString('<li class="hub-todo-item hub-todo-item--optional">', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=new-ta-drift&amp;car_id=3&amp;event_id=20">Go through the tech sheet</a>', $html);
        $this->assertStringContainsString('<span class="hub-status hub-status--ok">All set</span>', $html);
        $this->assertStringNotContainsString('<ol class="hub-todo">', $html);
    }

    public function testNoRecommendedHeadingWithoutSuggestedItems(): void
    {
        $html = renderHomeHtml($this->vm([$this->item('supps', 'todo', "Confirm you've read the WSCC supplementary regulations for #86")]));
        $this->assertStringNotContainsString('Recommended', $html);
        $this->assertStringContainsString('1 thing to do before WSCC TA #2', $html);
    }

    public function testEventCardsHaveAnAnchor(): void
    {
        $html = renderHomeHtml($this->vm([]));
        $this->assertStringContainsString('<section class="hub-card hub-event" id="event-20">', $html);
    }

    public function testTaDriftAtTrackFormSendsTheTierAndClub(): void
    {
        $item = $this->item('car_tech', 'todo', 'TA/Drift car tech for #86 at WSCC', null,
            ['subject_type' => 'car', 'subject_id' => 3, 'season' => 2099, 'discipline' => 'ta_drift', 'club' => 'WSCC']);
        $html = renderHomeHtml($this->vm([$item]));
        $this->assertStringContainsString('<input type="hidden" name="discipline" value="ta_drift">', $html);
        $this->assertStringContainsString('<input type="hidden" name="club" value="WSCC">', $html);
    }

    public function testRemindersLeaveSuggestedItemsOut(): void
    {
        $readiness = ['events' => [[
            'event' => ['id' => 20, 'name' => 'WSCC TA #2', 'event_date' => '2026-07-12'],
            'items' => [$this->item('tech_sheet', 'suggested', 'Check your car'), $this->item('supps', 'todo', 'Confirm')],
        ]], 'untagged' => []];
        $digests = reminderDigests($readiness, '2026-07-05');
        $this->assertSame(['Confirm'], array_column($digests[0]['items'], 'label'));
        $readiness['events'][0]['items'] = [$this->item('tech_sheet', 'suggested', 'Check your car')];
        $this->assertSame([], reminderDigests($readiness, '2026-07-05'));
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `php phpunit.phar --filter HomeTaDriftTest`
Expected: FAIL. There's no "Recommended" list, and the card has no `id`. `testRemindersLeaveSuggestedItemsOut` and `testTaDriftAtTrackFormSendsTheTierAndClub` already pass, and are there to guard the behaviour.

- [ ] **Step 3: Implement**

In `home-page.php`, replace:

```php
/** One numbered todo item, with its primary action and optional "at the track" secondary form. */
function homeRenderTodoItem(int $n, array $item, string $csrf): string {
    $out = '<li class="hub-todo-item"><span class="hub-todo-n">' . h((string)$n) . '</span>';
```

with:

```php
/**
 * One numbered todo item, with its primary action and optional "at the track" secondary form. A
 * suggested item (TA/Drift spec §4) gets the quieter optional style and a "+" instead of a number.
 */
function homeRenderTodoItem(int $n, array $item, string $csrf, bool $suggested = false): string {
    $out = $suggested
        ? '<li class="hub-todo-item hub-todo-item--optional"><span class="hub-todo-n" aria-hidden="true">+</span>'
        : '<li class="hub-todo-item"><span class="hub-todo-n">' . h((string)$n) . '</span>';
```

In `homeEventCardHtml`, replace:

```php
    $out = '<section class="hub-card hub-event"><div class="hub-event-head"><h3>' . h((string)$event['name']) . '</h3>';
```

with:

```php
    $out = '<section class="hub-card hub-event" id="event-' . (int)$event['id'] . '"><div class="hub-event-head"><h3>' . h((string)$event['name']) . '</h3>';
```

In `renderHomeHtml`, replace:

```php
        $todoItems = array_values(array_filter($items, fn(array $i): bool => $i['state'] === 'todo'));
```

with:

```php
        $todoItems = array_values(array_filter($items, fn(array $i): bool => $i['state'] === 'todo'));
        $suggestedItems = array_values(array_filter($items, fn(array $i): bool => $i['state'] === 'suggested'));
```

Replace:

```php
            $out .= '</ol>';
        }

        if ($infoItems || $doneItems) {
```

with:

```php
            $out .= '</ol>';
        }

        // Recommended, never counted (TA/Drift spec §4): e.g. going through the TA/Drift sheet again.
        if ($suggestedItems) {
            $out .= '<h2>Recommended</h2><ul class="hub-todo hub-todo--suggested">';
            foreach ($suggestedItems as $item) {
                $out .= homeRenderTodoItem(0, $item, $csrf, true);
            }
            $out .= '</ul>';
        }

        if ($infoItems || $doneItems) {
```

- [ ] **Step 4: Style the suggested list**

In `css/hub.css`, directly after the `.hub-todo-item--optional .hub-todo-n { … }` line, add:

```css
.hub-todo--suggested { border-color: var(--hub-line); list-style: none; padding: 0; margin: 0 0 24px; }
```

- [ ] **Step 5: Run the test to confirm it passes, then run the full suite**

Run: `php phpunit.phar --filter HomeTaDriftTest` → PASS. Then `php phpunit.phar` → all PASS. `HomePageTest` asserts on inner markup, not the `<section` opening tag. If one of its assertions pinned `<section class="hub-card hub-event">`, update it to include the new `id`, and note that in the commit message.

- [ ] **Step 6: Commit**

```bash
git add home-page.php css/hub.css tests/HomeTaDriftTest.php
git commit -m "feat(ta-drift): Home lists recommended items apart and never counts them; event card anchors

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 4: The formats picker on Home ("I'm going" and "Change")

**Files:**
- Modify: `home-page.php` (new `homeFormatsFieldsHtml`, `homeRenderEntryFormatsHtml`; `homeRenderTagForm`, `homeEventCardHtml`, `homeCarsForEvent`, `renderHomeHtml`)
- Modify: `index.php` (`tag` passes formats; new `formats` action; `tagDefaults` in the view model)
- Modify: `css/hub.css`
- Test: `tests/HomeTaDriftTest.php` (add tests), `tests/HomeFormatsSourceTest.php` (new), `tests/EventsTagFormatsTest.php` (new)

**Interfaces:**
- Consumes (plan 1): `ENTRY_FORMATS`, `ENTRY_NO_HOST_CLUB`, `entryFormatsLabel`, `eventsTagCar`, `eventsSetFormats`, `eventsDefaultFormats`. From Task 1: `buildReadiness()` rows carry `entries`.
- Produces:
  - `homeFormatsFieldsHtml(?array $event, array $checked, bool $suppsTicked = false): string`. A null `$event` means the event isn't known yet (the Garage car page's event select): every box is enabled and the regulations wording is generic.
  - `homeRenderEntryFormatsHtml(array $event, array $car, array $entry, string $csrf): string`
  - `homeRenderTagForm(array $event, array $cars, string $csrf, bool $offerReminders = false, array $defaults = ['race']): string`
  - `homeEventCardHtml(..., bool $isFocus = false, array $tagDefaults = []): string`, where `$tagDefaults` maps car id to formats for this event.
  - The posted fields are `formats_shown=1`, `formats[]` and `supps_ack=1`.
  - `index.php` gets a POST action `formats`.

- [ ] **Step 1: Write the failing tests**

Add these methods to `tests/HomeTaDriftTest.php`, inside the class:

```php
    public function testTagFormOffersFormatsForASummerEventWithTheDefaultsTicked(): void
    {
        $event = ['id' => 21, 'name' => 'WSCC TA #3', 'event_date' => '2099-09-01', 'discipline' => 'summer', 'host_club' => 'WSCC'];
        $cars = [3 => ['id' => 3, 'car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ']];
        $html = homeRenderTagForm($event, $cars, 'tok', false, ['ta', 'drift']);
        $this->assertStringContainsString('<input type="hidden" name="formats_shown" value="1">', $html);
        $this->assertStringContainsString('<input type="checkbox" name="formats[]" value="race"> Race</label>', $html);
        $this->assertStringContainsString('<input type="checkbox" name="formats[]" value="ta" checked> Time Attack</label>', $html);
        $this->assertStringContainsString('<input type="checkbox" name="formats[]" value="drift" checked> Drift</label>', $html);
        $this->assertStringContainsString('<input type="checkbox" name="supps_ack" value="1"> For Time Attack and Drift: I have read the WSCC supplementary regulations and my car complies</label>', $html);
    }

    public function testNoHostClubDisablesTimeAttackAndDrift(): void
    {
        $event = ['id' => 22, 'name' => 'Open Day', 'event_date' => '2099-09-01', 'discipline' => 'summer', 'host_club' => null];
        $html = homeFormatsFieldsHtml($event, ['race']);
        $this->assertStringContainsString('value="race" checked>', $html);
        $this->assertStringContainsString('value="ta" disabled>', $html);
        $this->assertStringContainsString('value="drift" disabled>', $html);
        $this->assertStringContainsString(h(ENTRY_NO_HOST_CLUB), $html);
        $this->assertStringNotContainsString('supps_ack', $html);
    }

    public function testUnknownEventKeepsEveryBoxAndGenericWording(): void
    {
        $html = homeFormatsFieldsHtml(null, ['race']);
        $this->assertStringNotContainsString('disabled', $html);
        $this->assertStringContainsString(h("For Time Attack and Drift: I have read the host club's supplementary regulations and my car complies"), $html);
    }

    public function testIceEventHasNoFormatPicker(): void
    {
        $event = ['id' => 30, 'name' => 'Ice #1', 'event_date' => '2099-01-10', 'discipline' => 'ice', 'host_club' => 'WSCC'];
        $html = homeRenderTagForm($event, [3 => ['id' => 3, 'car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ']], 'tok');
        $this->assertStringNotContainsString('formats', $html);
    }

    public function testGoingCarShowsItsFormatsAndAChangeForm(): void
    {
        $html = renderHomeHtml($this->vm([$this->item('tech_sheet', 'todo', 'Submit a TA/Drift tech sheet for #86')]));
        $this->assertStringContainsString('<details class="hub-entry-formats"><summary>Time Attack · Change</summary>', $html);
        $this->assertStringContainsString('<input type="hidden" name="action" value="formats">', $html);
        $this->assertStringContainsString('<input type="checkbox" name="formats[]" value="ta" checked> Time Attack</label>', $html);
    }

    public function testTaDriftOnlyCarsAreNotOfferedIceEvents(): void
    {
        $cars = [1 => ['id' => 1, 'disciplines' => 'ta_drift'], 2 => ['id' => 2, 'disciplines' => 'ice'], 3 => ['id' => 3, 'disciplines' => 'summer'], 4 => ['id' => 4]];
        $this->assertSame([2, 4], array_keys(homeCarsForEvent($cars, ['discipline' => 'ice'])));
        $this->assertSame([1, 3, 4], array_keys(homeCarsForEvent($cars, ['discipline' => 'summer'])));
    }
```

Create `tests/EventsTagFormatsTest.php`:

```php
<?php
// wcma-calculator/tests/EventsTagFormatsTest.php
require_once __DIR__ . '/../events-lib.php';

use PHPUnit\Framework\TestCase;

final class EventsTagFormatsTest extends TestCase
{
    public function testTaggingWithNothingTickedIsRefused(): void
    {
        $pdo = make_temp_pdo();
        $uid = db_create_user($pdo, ['email' => 'tad' . uniqid() . '@example.com', 'name' => 'TA Driver', 'password_hash' => 'x', 'google_id' => null]);
        $car = test_make_car($pdo, $uid, '86');
        $event = db_create_event($pdo, 'WSCC TA', '2099-07-12', null, 'summer', 'WSCC');

        // What index.php and garage.php pass when the picker was shown but every box was unticked.
        $this->assertSame(['ok' => false, 'error' => ENTRY_FORMAT_ERROR], eventsTagCar($pdo, $uid, $event, $car, [], false));
        $this->assertNull(db_get_entry($pdo, $uid, $event, $car));
    }
}
```

Create `tests/HomeFormatsSourceTest.php`:

```php
<?php
// wcma-calculator/tests/HomeFormatsSourceTest.php — the Home and Garage handlers post the picker to plan 1's functions.
use PHPUnit\Framework\TestCase;

final class HomeFormatsSourceTest extends TestCase
{
    public function testHandlersPassTheFormatsThrough(): void
    {
        foreach (['index.php', 'garage.php'] as $file) {
            $src = (string)file_get_contents(__DIR__ . '/../' . $file);
            $this->assertStringContainsString("case 'formats':", $src, $file);
            $this->assertStringContainsString('eventsSetFormats(', $src, $file);
            $this->assertStringContainsString('entryFormatsFromPost($_POST)', $src, $file);
            $this->assertStringContainsString("!empty(\$_POST['supps_ack'])", $src, $file);
        }
    }
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php phpunit.phar --filter 'HomeTaDriftTest|EventsTagFormatsTest|HomeFormatsSourceTest'`
Expected: FAIL. `homeFormatsFieldsHtml` is undefined and `index.php` has no `formats` case. `EventsTagFormatsTest` already passes (plan 1), and it guards Review Focus 5. `garage.php` gets its handler in Task 7, so leave `HomeFormatsSourceTest` failing for `garage.php` until then. To keep this task green, have the loop check only `index.php` for now; Task 7 Step 1 widens it back to both files.

In other words, write the `foreach` above as `foreach (['index.php'] as $file) {` now.

- [ ] **Step 3: Add the post helper to `ta-drift-lib.php`**

Append:

```php

/**
 * The formats a form posted. Null when the form had no picker (formats_shown missing), so the
 * entry keeps or gets its defaults; [] when the picker was shown and nothing was ticked, which
 * entryFormatsValidate() refuses.
 * @return ?string[]
 */
function entryFormatsFromPost(array $post): ?array {
    if (!isset($post['formats_shown'])) return null;
    return is_array($post['formats'] ?? null) ? array_values($post['formats']) : [];
}
```

Add this to `tests/HomeTaDriftTest.php`:

```php
    public function testFormatsFromPost(): void
    {
        $this->assertNull(entryFormatsFromPost(['action' => 'tag']));
        $this->assertSame([], entryFormatsFromPost(['formats_shown' => '1']));
        $this->assertSame([], entryFormatsFromPost(['formats_shown' => '1', 'formats' => 'ta']));
        $this->assertSame(['ta', 'drift'], entryFormatsFromPost(['formats_shown' => '1', 'formats' => ['x' => 'ta', 'y' => 'drift']]));
    }
```

- [ ] **Step 4: Add the picker to `home-page.php`**

Update the file-header list of callers: after `reminders-lib.php (reminderOptInFieldsHtml())`, add ` and ta-drift-lib.php (ENTRY_FORMATS)`. Then add `require_once __DIR__ . '/ta-drift-lib.php';` directly under the header comment.

Directly above `/** The tag ("I'm going") form for one event, …`, add:

```php
/**
 * The Race / Time Attack / Drift checkboxes and the supplementary-regulations tick (TA/Drift spec §3
 * Entry). $event null: the event isn't chosen yet (Garage's event select), so every box is enabled
 * and the wording names no club; the server still checks the host club. Without a host club, Time
 * Attack and Drift are disabled with ENTRY_NO_HOST_CLUB.
 */
function homeFormatsFieldsHtml(?array $event, array $checked, bool $suppsTicked = false): string {
    $club = $event === null ? null : trim((string)($event['host_club'] ?? ''));
    $out = '<fieldset class="hub-formats"><legend>Running</legend><input type="hidden" name="formats_shown" value="1"><div class="hub-formats-options">';
    foreach (ENTRY_FORMATS as $value => $label) {
        $disabled = $value !== 'race' && $club === '';
        $out .= '<label><input type="checkbox" name="formats[]" value="' . h($value) . '"'
            . (in_array($value, $checked, true) && !$disabled ? ' checked' : '') . ($disabled ? ' disabled' : '') . '> ' . h($label) . '</label>';
    }
    $out .= '</div>';
    if ($club === '') {
        return $out . '<p class="form-hint">' . h(ENTRY_NO_HOST_CLUB) . '</p></fieldset>';
    }
    $who = $club === null ? "the host club's" : "the $club";
    return $out . '<label class="hub-formats-supps"><input type="checkbox" name="supps_ack" value="1"' . ($suppsTicked ? ' checked' : '') . '> '
        . h("For Time Attack and Drift: I have read $who supplementary regulations and my car complies") . '</label></fieldset>';
}

/** A going car's formats on its event card, with a "Change" form (summer events only). */
function homeRenderEntryFormatsHtml(array $event, array $car, array $entry, string $csrf): string {
    if (($event['discipline'] ?? 'summer') === 'ice') return '';
    return '<details class="hub-entry-formats"><summary>' . h(entryFormatsLabel($entry['formats'])) . ' · Change</summary>'
        . '<form method="post" action="index.php" class="hub-line hub-tag-form">' . homeCsrfField($csrf)
        . '<input type="hidden" name="action" value="formats">'
        . '<input type="hidden" name="event_id" value="' . (int)$event['id'] . '">'
        . '<input type="hidden" name="car_id" value="' . (int)$car['id'] . '">'
        . homeFormatsFieldsHtml($event, $entry['formats'], ($entry['supps_ack_at'] ?? null) !== null)
        . '<button type="submit" class="hub-btn hub-btn--secondary">Save</button></form></details>';
}

```

In `homeRenderTagForm`, change the signature and add the picker. Replace:

```php
function homeRenderTagForm(array $event, array $cars, string $csrf, bool $offerReminders = false): string {
```

with:

```php
function homeRenderTagForm(array $event, array $cars, string $csrf, bool $offerReminders = false, array $defaults = ['race']): string {
```

and replace:

```php
    if ($offerReminders) $out .= reminderOptInFieldsHtml();
    $out .= '<button type="submit" class="hub-btn">I\'m going</button></form>';
```

with:

```php
    if (($event['discipline'] ?? 'summer') !== 'ice') $out .= homeFormatsFieldsHtml($event, $defaults);
    if ($offerReminders) $out .= reminderOptInFieldsHtml();
    $out .= '<button type="submit" class="hub-btn">I\'m going</button></form>';
```

Update the doc comment above it to: `/** The tag ("I'm going") form for one event: pick the car (or name it) and, for summer, what it runs ($defaults: the first car's eventsDefaultFormats()). */`

Replace `homeCarsForEvent` with:

```php
/**
 * The cars (keyed by id) that can go to $event: a car stored for the other season can't (mobile UX
 * spec §A4), and a summer TA/Drift-only car never goes to an ice event.
 */
function homeCarsForEvent(array $cars, array $event): array {
    $exclude = ($event['discipline'] ?? 'summer') === 'ice' ? ['summer', 'ta_drift'] : ['ice'];
    return array_filter($cars, fn(array $c): bool => !in_array($c['disciplines'] ?? null, $exclude, true));
}
```

In `homeEventCardHtml`, replace the signature line:

```php
function homeEventCardHtml(array $event, ?array $readinessEvent, array $cars, string $csrf, bool $offerReminders, bool $isFocus = false): string {
```

with:

```php
function homeEventCardHtml(array $event, ?array $readinessEvent, array $cars, string $csrf, bool $offerReminders, bool $isFocus = false, array $tagDefaults = []): string {
```

Replace:

```php
    foreach (array_keys($goingCarIds) as $carId) {
        if (isset($cars[$carId])) $out .= homeRenderUntagForm($event, $cars[$carId], $csrf);
    }
    $notGoing = homeCarsForEvent(array_diff_key($cars, $goingCarIds), $event);
    if ($notGoing) {
        $out .= homeRenderTagForm($event, $notGoing, $csrf, $offerReminders);
```

with:

```php
    foreach (array_keys($goingCarIds) as $carId) {
        if (!isset($cars[$carId])) continue;
        $out .= homeRenderUntagForm($event, $cars[$carId], $csrf)
            . homeRenderEntryFormatsHtml($event, $cars[$carId], $readinessEvent['entries'][$carId] ?? ['formats' => ['race'], 'supps_ack_at' => null], $csrf);
    }
    $notGoing = homeCarsForEvent(array_diff_key($cars, $goingCarIds), $event);
    if ($notGoing) {
        $out .= homeRenderTagForm($event, $notGoing, $csrf, $offerReminders, $tagDefaults[array_key_first($notGoing)] ?? ['race']);
```

In `renderHomeHtml`, replace:

```php
            $out .= homeEventCardHtml($event, $readinessEvent, $cars, $csrf, $offerReminders,
                $readinessEvent !== null && (int)$event['id'] === $focusId);
```

with:

```php
            $out .= homeEventCardHtml($event, $readinessEvent, $cars, $csrf, $offerReminders,
                $readinessEvent !== null && (int)$event['id'] === $focusId, $vm['tagDefaults'][(int)$event['id']] ?? []);
```

- [ ] **Step 5: Wire `index.php`**

Replace:

```php
        case 'tag':
            $r = eventsTagCar($pdo, $uid, (int)($_POST['event_id'] ?? 0), (int)($_POST['car_id'] ?? 0));
```

with:

```php
        case 'tag':
            $r = eventsTagCar($pdo, $uid, (int)($_POST['event_id'] ?? 0), (int)($_POST['car_id'] ?? 0),
                entryFormatsFromPost($_POST), !empty($_POST['supps_ack']));
```

Directly after the `tag` case's `break;`, add:

```php
        case 'formats':
            $r = eventsSetFormats($pdo, $uid, (int)($_POST['event_id'] ?? 0), (int)($_POST['car_id'] ?? 0),
                entryFormatsFromPost($_POST) ?? [], !empty($_POST['supps_ack']));
            setFlash($r['ok'] ? 'Saved what this car is running.' : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            break;
```

Directly after `$today = (string)$in['today'];`, add:

```php

// What "I'm going" starts ticked, per upcoming summer event and car (TA/Drift spec §3 Entry).
$tagDefaults = [];
foreach ($in['events'] as $e) {
    if (($e['discipline'] ?? 'summer') === 'ice' || (string)$e['event_date'] < $today) continue;
    foreach ($in['cars'] as $cid => $car) $tagDefaults[(int)$e['id']][(int)$cid] = eventsDefaultFormats($pdo, $car, $e);
}
```

In the `renderHomeHtml([...])` call, add `'tagDefaults' => $tagDefaults,` after `'mediaPrompt' => $mediaPrompt,`.

- [ ] **Step 6: Style the picker**

In `css/hub.css`, directly after `.hub-tag-form { flex-wrap: wrap; gap: 8px 12px; }`, add:

```css
.hub-formats { border: 0; padding: 0; margin: 0; flex-basis: 100%; }
.hub-formats legend { font-weight: 700; padding: 0; margin-bottom: 4px; }
.hub-formats-options { display: flex; flex-wrap: wrap; gap: 4px 16px; }
.hub-formats-options label, .hub-formats-supps { display: inline-flex; gap: 10px; align-items: center; min-height: var(--hub-tap); font-size: 16px; margin: 0; }
.hub-formats-options label:has(input:disabled) { color: var(--hub-ink-2); }
.hub-entry-formats { margin: 0 0 8px; }
.hub-entry-formats summary { min-height: var(--hub-tap); display: flex; align-items: center; cursor: pointer; font-size: 16px; }
```

- [ ] **Step 7: Run the tests to confirm they pass, then run the full suite**

Run: `php phpunit.phar --filter 'HomeTaDriftTest|EventsTagFormatsTest|HomeFormatsSourceTest'` → PASS. Then `php phpunit.phar` → all PASS.

Some existing tests may assert the exact tag form markup (`HomePageTest`, `HomeSourceTest`). Those tests' events have no `discipline`, so they now also get the picker, with Race ticked. If an assertion pinned the button straight after the car select, update it to allow the fieldset in between, and say so in the commit message.

- [ ] **Step 8: Commit**

```bash
git add ta-drift-lib.php home-page.php index.php css/hub.css tests/HomeTaDriftTest.php tests/EventsTagFormatsTest.php tests/HomeFormatsSourceTest.php
git commit -m "feat(ta-drift): pick Race, Time Attack or Drift when going to an event, and change it on the card

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 5: TA/Drift-only cars in the car form, after adding, and without a class

**Files:**
- Modify: `garage-lib.php` (`garageAfterAdd`, new `garageRaceSheets`, `garageEntryTiers`, `garageCarRaces`; `garageCard`)
- Modify: `garage-page.php` (`garageSeasonFieldHtml`, `garageRenderCard`, `renderGarageCarHtml`)
- Modify: `garage.php` (`garageShowCar`, `garageShowList`)
- Modify: `index.php` and `home-page.php` (Home "At a glance" hides class and race tech for a TA/Drift-only car)
- Modify: `css/hub.css` (`.garage-season-options` becomes 2 × 2)
- Test: `tests/GarageTaDriftTest.php` (new)

**Interfaces:**
- Consumes: Task 1's `entryTierAtEvent`, and plan 1's `techSheetIsTaDrift`.
- Produces:
  - `garageRaceSheets(array $sheets): array` (not ice and not TA/Drift)
  - `garageEntryTiers(array $formatsByEvent, array $activeEvents, string $today): array{race: bool, taDriftClubs: string[]}`
  - `garageCarRaces(array $car, array $declarations, array $carSheets, bool $taggedRace): bool`
  - `garageCard(…, int $iceSeason, array $formatsByEvent = [])` adds `usesRace`
  - `garageTaDriftSheetUrl(int $carId, int $eventId): string`
  - The view models carry `usesRace` (card, car page and Home glance).

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/GarageTaDriftTest.php
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
require_once __DIR__ . '/../ice-sheet-lib.php';

use PHPUnit\Framework\TestCase;

final class GarageTaDriftTest extends TestCase
{
    private const TA = ['id' => 20, 'name' => 'WSCC TA', 'event_date' => '2026-07-12', 'discipline' => 'summer', 'host_club' => 'WSCC', 'active' => 1];
    private const OPEN = ['id' => 22, 'name' => 'Open Day', 'event_date' => '2026-08-20', 'discipline' => 'summer', 'host_club' => null, 'active' => 1];

    public function testCarFormOffersSummerTaDriftOnly(): void
    {
        $html = renderAddCarHtml(['csrf' => 'tok', 'values' => ['disciplines' => 'ta_drift'], 'error' => null, 'msrLink' => null, 'event' => null]);
        $this->assertStringContainsString('<input type="radio" name="disciplines" value="ta_drift" required checked><span>Summer TA/Drift only</span>', $html);
    }

    public function testAfterAddingATaDriftOnlyCar(): void
    {
        $this->assertSame(['url' => 'tech-sheets.php?action=new-ta-drift&car_id=3&event_id=20',
                           'flash' => 'Car added and going to WSCC TA. Next, the TA/Drift tech sheet.'], garageAfterAdd(3, 'ta_drift', self::TA));
        $this->assertSame(['url' => 'garage.php?car=3', 'flash' => 'Car added and going to Open Day.'], garageAfterAdd(3, 'ta_drift', self::OPEN));
        $this->assertSame(['url' => 'garage.php?car=3#events', 'flash' => 'Car added. Which event is it going to first?'], garageAfterAdd(3, 'ta_drift', null));
    }

    public function testRaceSheetsLeaveOutIceAndTaDrift(): void
    {
        $sheets = [['id' => 1, 'discipline' => 'summer', 'sheet_type' => 'standard'], ['id' => 2, 'discipline' => 'ice'],
                   ['id' => 3, 'discipline' => 'summer', 'sheet_type' => 'ta_drift'], ['id' => 4]];
        $this->assertSame([1, 4], array_column(garageRaceSheets($sheets), 'id'));
    }

    public function testEntryTiers(): void
    {
        $events = [self::TA, self::OPEN, ['id' => 9, 'name' => 'Past', 'event_date' => '2026-01-01', 'discipline' => 'summer', 'host_club' => 'NASCC']];
        $this->assertSame(['race' => false, 'taDriftClubs' => ['WSCC']], garageEntryTiers([20 => 'ta'], $events, '2026-06-01'));
        $this->assertSame(['race' => true, 'taDriftClubs' => []], garageEntryTiers([22 => 'ta'], $events, '2026-06-01'));      // no club: race
        $this->assertSame(['race' => true, 'taDriftClubs' => []], garageEntryTiers([20 => 'race,ta'], $events, '2026-06-01'));
        $this->assertSame(['race' => false, 'taDriftClubs' => []], garageEntryTiers([9 => 'race'], $events, '2026-06-01'));    // past
    }

    public function testATaDriftOnlyCarRacesOnlyWithRaceActivity(): void
    {
        $car = ['id' => 3, 'disciplines' => 'ta_drift'];
        $this->assertFalse(garageCarRaces($car, [], [['discipline' => 'summer', 'sheet_type' => 'ta_drift']], false));
        $this->assertTrue(garageCarRaces($car, [], [], true));
        $this->assertTrue(garageCarRaces($car, [['id' => 8]], [], false));
        $this->assertTrue(garageCarRaces($car, [], [['discipline' => 'summer', 'sheet_type' => 'standard']], false));
        $this->assertTrue(garageCarRaces(['id' => 3, 'disciplines' => 'summer'], [], [], false));
    }

    public function testCardForATaDriftOnlyCarHasNoClassOrRaceTech(): void
    {
        $card = garageCard(['id' => 3, 'car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'disciplines' => 'ta_drift', 'archived_at' => null],
            [], [], [20], [self::TA], 2026, '2026-06-01', 2027, [20 => 'ta']);
        $this->assertFalse($card['usesRace']);
        $html = garageRenderCard($card);
        $this->assertStringNotContainsString('No class declared yet', $html);
        $this->assertStringNotContainsString('<dt>Car tech</dt>', $html);
        $this->assertStringNotContainsString('Declare class', $html);

        $legacy = garageCard(['id' => 3, 'car_number' => '42', 'make' => 'Honda', 'model' => 'S2000', 'archived_at' => null],
            [], [], [20], [self::TA], 2026, '2026-06-01', 2027);   // no formats passed: tagged events read as race
        $this->assertTrue($legacy['usesRace']);
    }

    public function testCarPageHidesClassAndRaceTechWhenTheCarDoesNotRace(): void
    {
        $vm = ['car' => ['id' => 3, 'car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'archived_at' => null, 'disciplines' => 'ta_drift'],
               'class' => ['current' => null, 'earlierAccepted' => null], 'declarations' => [], 'season' => 2026,
               'techState' => 'none', 'techLabel' => 'Needs tech at the track', 'techAction' => null,
               'events' => ['tagged' => [], 'untagged' => [], 'earlierSheets' => []], 'seasons' => ['summer' => true, 'ice' => false],
               'csrf' => 'tok', 'detailsForm' => null, 'usesSummer' => true, 'usesRace' => false, 'ice' => null];
        $html = renderGarageCarHtml($vm);
        $this->assertStringNotContainsString('<h2>Class</h2>', $html);
        $this->assertStringNotContainsString('<h2>Car tech 2026</h2>', $html);
        $this->assertStringContainsString('<section class="hub-card" id="events"><h2>Events</h2>', $html);
    }

    public function testHomeGlanceHidesClassForATaDriftOnlyCar(): void
    {
        $car = ['id' => 3, 'car_number' => '86', 'year' => '', 'make' => 'Subaru', 'model' => 'BRZ'];
        $html = renderHomeHtml(['name' => 'J', 'readiness' => ['events' => [], 'untagged' => []], 'cars' => [3 => $car],
            'garage' => [['car' => $car, 'declaration' => null, 'techLabel' => 'Needs tech at the track', 'techState' => 'none',
                          'usesSummer' => true, 'usesRace' => false, 'ice' => null]],
            'drivers' => [], 'seasonLinks' => [], 'csrf' => 'tok']);
        $this->assertStringNotContainsString('Not declared', $html);
        $this->assertStringNotContainsString('Car tech:', $html);
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `php phpunit.phar --filter GarageTaDriftTest`
Expected: FAIL. There's no `ta_drift` radio, and `garageRaceSheets` is undefined.

- [ ] **Step 3: Implement `garage-lib.php`**

Add `require_once __DIR__ . '/ta-drift-lib.php';` after `require_once __DIR__ . '/tech-status.php';`.

In `garageAfterAdd`, replace:

```php
    $car = 'garage.php?car=' . $carId;
    if ($event !== null && ($event['discipline'] ?? 'summer') === 'ice') {
```

with:

```php
    $car = 'garage.php?car=' . $carId;
    if ($disciplines === 'ta_drift') {
        // A TA/Drift-only car (TA/Drift spec §4): no class to declare. Its entry defaulted to Time
        // Attack when the event has a host club, so the TA/Drift sheet is next.
        if ($event !== null && entryTierAtEvent($event, 'ta') === TECH_TIER_TA_DRIFT) {
            return ['url' => garageTaDriftSheetUrl($carId, (int)$event['id']),
                    'flash' => 'Car added and going to ' . $event['name'] . '. Next, the TA/Drift tech sheet.'];
        }
        if ($event !== null) return ['url' => $car, 'flash' => 'Car added and going to ' . $event['name'] . '.'];
        return ['url' => $car . '#events', 'flash' => 'Car added. Which event is it going to first?'];
    }
    if ($event !== null && ($event['discipline'] ?? 'summer') === 'ice') {
```

Directly after `garageAfterAdd`, add:

```php

/** The TA/Drift tech sheet form for a car at an event (plan 2's route). */
function garageTaDriftSheetUrl(int $carId, int $eventId): string {
    return 'tech-sheets.php?action=new-ta-drift&car_id=' . $carId . '&event_id=' . $eventId;
}

/** The race sheets among $sheets: summer sheets that are neither ice nor TA/Drift. Race car tech counts only these. */
function garageRaceSheets(array $sheets): array {
    return array_values(array_filter($sheets, fn(array $s): bool => !techSheetIsIce($s) && !techSheetIsTaDrift($s)));
}

/**
 * What a car's upcoming summer entries need (entryTierAtEvent()): whether any is race, and the host
 * clubs of those that are TA/Drift. $formatsByEvent: event id => the entry's stored formats.
 * @return array{race: bool, taDriftClubs: string[]}
 */
function garageEntryTiers(array $formatsByEvent, array $activeEvents, string $today): array {
    $race = false;
    $clubs = [];
    foreach ($activeEvents as $e) {
        $eid = (int)$e['id'];
        if (!array_key_exists($eid, $formatsByEvent) || (string)$e['event_date'] < $today || ($e['discipline'] ?? 'summer') === 'ice') continue;
        if (entryTierAtEvent($e, $formatsByEvent[$eid]) === TECH_TIER_RACE) { $race = true; continue; }
        $clubs[trim((string)$e['host_club'])] = true;
    }
    $clubs = array_keys($clubs);
    sort($clubs);
    return ['race' => $race, 'taDriftClubs' => $clubs];
}

/**
 * Whether the car needs a class and race tech: every summer car, except a TA/Drift-only car with no
 * declaration, no race sheet and no upcoming race entry (TA/Drift spec §2 cars).
 */
function garageCarRaces(array $car, array $declarations, array $carSheets, bool $taggedRace): bool {
    if (($car['disciplines'] ?? null) !== 'ta_drift' || $declarations || $taggedRace) return true;
    return garageRaceSheets($carSheets) !== [];
}
```

Replace `garageCard` with:

```php
/**
 * One Garage list card: the car, its summer class and race tech (if it races), its ice chip (if it
 * races ice), and its nearest tagged event. $formatsByEvent: event id => the entry's stored formats;
 * tagged events missing from it read as race.
 */
function garageCard(array $car, array $declarations, array $carSheets, array $taggedEventIds, array $activeEvents, int $season, string $today, int $iceSeason, array $formatsByEvent = []): array {
    $raceSheets = garageRaceSheets($carSheets);
    $seasonSheets = array_values(array_filter($raceSheets, fn(array $s): bool => (int)$s['season'] === $season));
    $tech = techCarStatus($seasonSheets);
    $tagged = array_flip(array_map('intval', $taggedEventIds));
    $taggedIce = $taggedSummer = false;
    foreach ($activeEvents as $e) {
        if (!isset($tagged[(int)$e['id']]) || (string)$e['event_date'] < $today) continue;
        if (($e['discipline'] ?? 'summer') === 'ice') $taggedIce = true; else $taggedSummer = true;
    }
    $formatsByEvent += array_fill_keys(array_map('intval', $taggedEventIds), 'race');
    $tiers = garageEntryTiers($formatsByEvent, $activeEvents, $today);
    $events = garageCarEvents($carSheets, $taggedEventIds, $activeEvents, [], $today);
    $stored = isset($car['disciplines']) ? (string)$car['disciplines'] : null;
    $seasons = garageCarSeasons($car, $declarations, $carSheets, $taggedSummer, $taggedIce);
    return [
        'car' => $car,
        'class' => garageClassLine($declarations),
        'seasons' => $seasons,
        'usesSummer' => $seasons['summer'],
        'usesRace' => $seasons['summer'] && garageCarRaces($car, $declarations, $carSheets, $tiers['race']),
        'ice' => garageIceSummary($carSheets, $taggedIce, $iceSeason, $stored),
        'techState' => $tech['state'],
        'techLabel' => techCarStatusLabel($tech, $season),
        'next' => $events['tagged'][0] ?? null,
    ];
}
```

- [ ] **Step 4: Implement `garage-page.php`**

In `garageSeasonFieldHtml`, replace:

```php
    foreach (['ice' => 'Ice', 'summer' => 'Summer', 'both' => 'Both'] as $v => $label) {
```

with:

```php
    foreach (['ice' => 'Ice', 'summer' => 'Summer', 'both' => 'Both', 'ta_drift' => 'Summer TA/Drift only'] as $v => $label) {
```

Update its doc comment to `/** "Where will this car race?" as four large radio cards (mobile UX spec 2026-09-28 §A2; TA/Drift spec §2). */`

In `garageRenderCard`, replace:

```php
    if ($card['usesSummer'] ?? true) $out .= garageClassHtml($card['class']);
```

with:

```php
    $usesRace = $card['usesRace'] ?? ($card['usesSummer'] ?? true);
    if ($usesRace) $out .= garageClassHtml($card['class']);
```

Replace:

```php
    if ($card['usesSummer'] ?? true) {
        $out .= '<div><dt>Car tech</dt><dd><span class="hub-status ' . h(homeStatusClass($card['techState'])) . '">'
```

with:

```php
    if ($usesRace) {
        $out .= '<div><dt>Car tech</dt><dd><span class="hub-status ' . h(homeStatusClass($card['techState'])) . '">'
```

Replace:

```php
    if (($card['usesSummer'] ?? true) && $card['class']['current'] === null && !$nextIsIce) {
```

with:

```php
    if ($usesRace && $card['class']['current'] === null && !$nextIsIce) {
```

In `renderGarageCarHtml`, replace:

```php
    $usesSummer = $vm['usesSummer'] ?? true;

    // Class
    if ($usesSummer) {
```

with:

```php
    $usesSummer = $vm['usesSummer'] ?? true;
    $usesRace = $vm['usesRace'] ?? $usesSummer;   // a TA/Drift-only car has no class or race tech (TA/Drift spec §2)

    // Class
    if ($usesRace) {
```

Replace:

```php
    // Car tech
    if ($usesSummer) {
```

with:

```php
    // Car tech
    if ($usesRace) {
```

Replace:

```php
    $out .= '<section class="hub-card"><h2>Events</h2>';
```

with:

```php
    $out .= '<section class="hub-card" id="events"><h2>Events</h2>';
```

- [ ] **Step 5: Wire `garage.php`**

In `garageShowList`, replace:

```php
    $tagged = [];
    foreach (db_get_user_event_plans($pdo, $uid) as $p) $tagged[(int)$p['car_id']][] = (int)$p['event_id'];
```

with:

```php
    $tagged = [];
    $formats = [];
    foreach (db_get_user_event_plans($pdo, $uid) as $p) {
        $tagged[(int)$p['car_id']][] = (int)$p['event_id'];
        $formats[(int)$p['car_id']][(int)$p['event_id']] = (string)$p['formats'];
    }
```

and replace:

```php
        $cards[] = garageCard($car, db_get_car_declarations($pdo, $cid), $carSheets, $tagged[$cid] ?? [], $events, gearSeasonNow(), date('Y-m-d'), gearSeasonNow(DISCIPLINE_ICE));
```

with:

```php
        $cards[] = garageCard($car, db_get_car_declarations($pdo, $cid), $carSheets, $tagged[$cid] ?? [], $events, gearSeasonNow(), date('Y-m-d'), gearSeasonNow(DISCIPLINE_ICE), $formats[$cid] ?? []);
```

In `garageShowCar`, replace:

```php
    $tagged = [];
    foreach (db_get_user_event_plans($pdo, $uid) as $p) {
        if ((int)$p['car_id'] === $carId) $tagged[] = (int)$p['event_id'];
    }
```

with:

```php
    $tagged = [];
    $formatsByEvent = [];
    foreach (db_get_user_event_plans($pdo, $uid) as $p) {
        if ((int)$p['car_id'] !== $carId) continue;
        $tagged[] = (int)$p['event_id'];
        $formatsByEvent[(int)$p['event_id']] = (string)$p['formats'];
    }
```

Replace:

```php
    $seasonSheets = array_values(array_filter($sheets, fn(array $s): bool => (int)$s['season'] === $season));
```

with:

```php
    $seasonSheets = array_values(array_filter(garageRaceSheets($sheets), fn(array $s): bool => (int)$s['season'] === $season));
```

In the `renderGarageCarHtml([...])` call, after `'usesSummer' => $seasons['summer'],`, add:

```php
        'usesRace' => $seasons['summer'] && garageCarRaces($car, $declarations, $allSheets, garageEntryTiers($formatsByEvent, db_get_active_events($pdo), $today)['race']),
```

- [ ] **Step 6: Home "At a glance"**

In `index.php`, in the `$garage[] = [...]` builder, replace:

```php
                 'usesSummer' => garageCarUsesSummer($decl !== null ? [$decl] : [], $carSheets, $taggedSummer, $taggedIce, $stored),
```

with:

```php
                 'usesSummer' => garageCarUsesSummer($decl !== null ? [$decl] : [], $carSheets, $taggedSummer, $taggedIce, $stored),
                 'usesRace' => garageCarRaces($car, $decl !== null ? [$decl] : [], $carSheets, garageEntryTiers($formatsByCar[$carId] ?? [], $in['events'], $today)['race']),
```

Directly above `$garage = [];`, add:

```php
$formatsByCar = [];
foreach ($in['plans'] as $p) $formatsByCar[(int)$p['car_id']][(int)$p['event_id']] = (string)$p['formats'];
```

In `home-page.php`, in the "At a glance" loop, replace:

```php
            $usesSummer = $g['usesSummer'] ?? true;
```

with:

```php
            $usesSummer = ($g['usesRace'] ?? $g['usesSummer'] ?? true);   // class and race tech: not for a TA/Drift-only car
```

- [ ] **Step 7: Lay out four season cards**

In `css/hub.css`, replace `.garage-season-options { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }` with `.garage-season-options { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }`.

- [ ] **Step 8: Run the test to confirm it passes, then run the full suite**

Run: `php phpunit.phar --filter GarageTaDriftTest` → PASS. Then `php phpunit.phar` → all PASS.

`GaragePageTest::testAddCarAsksWhereTheCarRacesFirst` loops over the three original options, so it still passes. `GarageLibTest` calls `garageCard` without formats, and those cards get `usesRace` = `usesSummer`.

- [ ] **Step 9: Commit**

```bash
git add garage-lib.php garage-page.php garage.php index.php home-page.php css/hub.css tests/GarageTaDriftTest.php
git commit -m "feat(ta-drift): Summer TA/Drift only cars skip class and race tech; race tech ignores TA/Drift sheets

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 6: TA/Drift tech chips (Garage card, car page, Home glance)

**Files:**
- Modify: `garage-lib.php` (new `garageTaDriftSummaries`; `garageCard` adds `taDrift`)
- Modify: `garage-page.php` (card facts and a car page section)
- Modify: `garage.php` (car page view model)
- Modify: `index.php` and `home-page.php` (a glance pill per club)
- Test: `tests/GarageTaDriftTest.php` (add tests)

**Interfaces:**
- Consumes: Task 5's `garageRaceSheets` and `garageEntryTiers`, and plan 1's `taDriftCarTechStatus`.
- Produces:
  - `garageTaDriftSummaries(array $carSheets, array $entryClubs, int $season): array<int, array{club: string, state: string, label: string}>`. The label is `TA/Drift {club} {year}: {status word}`.
  - Cards, the car page and the Home glance carry `taDrift` (that list).

- [ ] **Step 1: Write the failing tests**

Add these methods to `tests/GarageTaDriftTest.php`, inside the class:

```php
    private function sheet(int $id, array $o = []): array {
        return array_merge(['id' => $id, 'car_id' => 3, 'event_id' => 20, 'season' => 2026, 'discipline' => 'summer', 'sheet_type' => 'ta_drift',
                            'club' => 'WSCC', 'status' => 'submitted', 'accepted_via' => null, 'photo_status' => null], $o);
    }

    public function testTaDriftSummaryPerClub(): void
    {
        $this->assertSame([], garageTaDriftSummaries([], [], 2026));
        $this->assertSame([['club' => 'WSCC', 'state' => 'none', 'label' => 'TA/Drift WSCC 2026: needs tech']], garageTaDriftSummaries([], ['WSCC'], 2026));

        $sheets = [$this->sheet(9, ['status' => 'teched', 'accepted_via' => 'photos']), $this->sheet(10, ['club' => 'NASCC', 'photo_status' => 'submitted'])];
        $this->assertSame([
            ['club' => 'NASCC', 'state' => 'pending_review', 'label' => 'TA/Drift NASCC 2026: photos with an inspector'],
            ['club' => 'WSCC', 'state' => 'accepted', 'label' => 'TA/Drift WSCC 2026: pre-teched'],
        ], garageTaDriftSummaries($sheets, [], 2026));
        $this->assertSame([], garageTaDriftSummaries($sheets, [], 2027));   // another year
    }

    public function testRaceTechCoversEveryClub(): void
    {
        $race = $this->sheet(8, ['sheet_type' => 'standard', 'club' => null, 'status' => 'teched', 'accepted_via' => 'in_person']);
        $this->assertSame([['club' => 'WSCC', 'state' => 'accepted', 'label' => 'TA/Drift WSCC 2026: covered by race tech']],
            garageTaDriftSummaries([$race], ['WSCC'], 2026));
    }

    public function testTaDriftSheetDoesNotCountAsRaceCarTech(): void
    {
        $card = garageCard(['id' => 3, 'car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'disciplines' => 'summer', 'archived_at' => null],
            [], [$this->sheet(9, ['status' => 'teched', 'accepted_via' => 'in_person'])], [20], [self::TA], 2026, '2026-06-01', 2027, [20 => 'ta']);
        $this->assertSame('none', $card['techState']);
        $this->assertSame([['club' => 'WSCC', 'state' => 'accepted', 'label' => 'TA/Drift WSCC 2026: teched']], $card['taDrift']);
        $html = garageRenderCard($card);
        $this->assertStringContainsString('<div><dt>TA/Drift tech</dt><dd><span class="hub-status hub-status--ok">TA/Drift WSCC 2026: teched</span></dd></div>', $html);
    }

    public function testCarPageListsTaDriftTech(): void
    {
        $vm = ['car' => ['id' => 3, 'car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'archived_at' => null],
               'class' => ['current' => null, 'earlierAccepted' => null], 'declarations' => [], 'season' => 2026,
               'techState' => 'none', 'techLabel' => 'Needs tech at the track', 'techAction' => null,
               'events' => ['tagged' => [], 'untagged' => [], 'earlierSheets' => []], 'seasons' => ['summer' => true, 'ice' => false],
               'csrf' => 'tok', 'detailsForm' => null, 'usesSummer' => true, 'usesRace' => false, 'ice' => null,
               'taDrift' => [['club' => 'WSCC', 'state' => 'none', 'label' => 'TA/Drift WSCC 2026: needs tech']]];
        $this->assertStringContainsString('<section class="hub-card"><h2>TA/Drift tech</h2><p><span class="hub-status hub-status--warn">TA/Drift WSCC 2026: needs tech</span></p></section>',
            renderGarageCarHtml($vm));
    }
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php phpunit.phar --filter GarageTaDriftTest`
Expected: FAIL. `garageTaDriftSummaries` is undefined.

- [ ] **Step 3: Implement**

In `garage-lib.php`, directly after `garageCarRaces`, add:

```php

/**
 * A car's TA/Drift tech for $season, one row per host club, sorted by club (TA/Drift spec §4 "TA/Drift
 * {club} {year}"): the clubs it has TA/Drift sheets for that season plus $entryClubs (the host clubs
 * of its upcoming TA/Drift entries, garageEntryTiers()). Accepted race tech covers every club.
 * @return array<int, array{club: string, state: string, label: string}>
 */
function garageTaDriftSummaries(array $carSheets, array $entryClubs, int $season): array {
    $race = techCarStatus(array_values(array_filter(garageRaceSheets($carSheets), fn(array $s): bool => (int)$s['season'] === $season)));
    $byClub = array_fill_keys(array_map('strval', $entryClubs), []);
    foreach ($carSheets as $s) {
        if (techSheetIsTaDrift($s) && (int)$s['season'] === $season) $byClub[(string)$s['club']][] = $s;
    }
    ksort($byClub);
    $words = ['needs_changes' => 'photos need changes', 'pending_review' => 'photos with an inspector', 'photos_draft' => 'photos in progress'];
    $out = [];
    foreach ($byClub as $club => $sheets) {
        $st = taDriftCarTechStatus($race, techCarStatus($sheets));
        $word = $st['state'] === 'accepted'
            ? ($st['tier'] === TECH_TIER_RACE ? 'covered by race tech' : (($st['via'] ?? 'in_person') === 'photos' ? 'pre-teched' : 'teched'))
            : ($words[$st['state']] ?? 'needs tech');
        $out[] = ['club' => (string)$club, 'state' => $st['state'], 'label' => "TA/Drift $club $season: $word"];
    }
    return $out;
}
```

In `garageCard`'s return array, after `'ice' => …,`, add:

```php
        'taDrift' => garageTaDriftSummaries($carSheets, $tiers['taDriftClubs'], $season),
```

In `garage-page.php` `garageRenderCard`, directly after the `if (!empty($card['ice'])) { … }` block in the facts list, add:

```php
    foreach ($card['taDrift'] ?? [] as $t) {
        $out .= '<div><dt>TA/Drift tech</dt><dd><span class="hub-status ' . h(homeStatusClass($t['state'])) . '">' . h($t['label']) . '</span></dd></div>';
    }
```

In `renderGarageCarHtml`, directly before `// Ice tech`, add:

```php
    // TA/Drift tech, per host club (TA/Drift spec §4)
    if (!empty($vm['taDrift'])) {
        $out .= '<section class="hub-card"><h2>TA/Drift tech</h2>';
        foreach ($vm['taDrift'] as $t) {
            $out .= '<p><span class="hub-status ' . h(homeStatusClass($t['state'])) . '">' . h($t['label']) . '</span></p>';
        }
        $out .= '</section>';
    }

```

In `garage.php` `garageShowCar`, in the `renderGarageCarHtml([...])` call, after the `'usesRace' => …` line from Task 5, add:

```php
        'taDrift' => garageTaDriftSummaries($allSheets, garageEntryTiers($formatsByEvent, db_get_active_events($pdo), $today)['taDriftClubs'], $season),
```

In `index.php`, in the `$garage[] = [...]` builder, after the `'usesRace' => …` line, add:

```php
                 'taDrift' => garageTaDriftSummaries($carSheets, garageEntryTiers($formatsByCar[$carId] ?? [], $in['events'], $today)['taDriftClubs'], $season),
```

In `home-page.php`, in the glance loop, directly after the `if (!empty($g['ice'])) { … }` block, add:

```php
            foreach ($g['taDrift'] ?? [] as $t) {
                $out .= '<span class="hub-pill hub-status ' . h(homeStatusClass($t['state'])) . '">' . h($t['label']) . '</span>';
            }
```

The label already names the tier, club and year (for example `● TA/Drift WSCC 2026: teched`), so it's shown as-is, without `homePillHtml()`'s "Name:" prefix.

- [ ] **Step 4: Run the tests to confirm they pass, then run the full suite**

Run: `php phpunit.phar --filter GarageTaDriftTest` → PASS. Then `php phpunit.phar` → all PASS.

- [ ] **Step 5: Commit**

```bash
git add garage-lib.php garage-page.php garage.php index.php home-page.php tests/GarageTaDriftTest.php
git commit -m "feat(ta-drift): TA/Drift tech chip per host club on Garage, the car page and Home

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 7: The car page Events section: formats, the TA/Drift sheet link and the picker

**Files:**
- Modify: `garage-lib.php` (`garageCarEvents` takes formats; rows carry `formats` and `tier`)
- Modify: `garage-page.php` (event rows, the "I'm going" picker, the card's next action)
- Modify: `garage.php` (`tag` passes formats; new `formats` action; `garageShowCar` passes formats)
- Test: `tests/GarageTaDriftTest.php` (add tests), `tests/HomeFormatsSourceTest.php` (widen to `garage.php`)

**Interfaces:**
- Consumes: Task 4's `homeFormatsFieldsHtml`, `entryFormatsFromPost` and `homeRenderEntryFormatsHtml`, and plan 1's `eventsSetFormats` and `eventsDefaultFormats`.
- Produces:
  - `garageCarEvents(array $carSheets, array $taggedEventIds, array $activeEvents, array $eventNames, string $today, array $formatsByEvent = []): array`. Each tagged row gains `formats` (`string[]`) and `tier`.
  - `garageRenderEntryFormatsHtml(array $event, int $carId, array $formats, string $csrf): string` posts `action=formats` to `garage.php`.
  - The car page view model gains `tagDefaults` (`string[]`, the car's defaults for a new entry).

- [ ] **Step 1: Write the failing tests**

In `tests/HomeFormatsSourceTest.php`, change `foreach (['index.php'] as $file) {` back to `foreach (['index.php', 'garage.php'] as $file) {`.

Add these methods to `tests/GarageTaDriftTest.php`, inside the class:

```php
    public function testEventRowsCarryFormatsAndTier(): void
    {
        $rows = garageCarEvents([], [20, 22], [self::TA, self::OPEN], [], '2026-06-01', [20 => 'ta,drift', 22 => 'ta']);
        $this->assertSame(['ta', 'drift'], $rows['tagged'][0]['formats']);
        $this->assertSame('ta_drift', $rows['tagged'][0]['tier']);
        $this->assertSame('race', $rows['tagged'][1]['tier']);   // Open Day has no host club
        $legacy = garageCarEvents([], [20], [self::TA], [], '2026-06-01');
        $this->assertSame(['race'], $legacy['tagged'][0]['formats']);
    }

    private function carVm(array $tagged, array $o = []): array {
        return array_merge(['car' => ['id' => 3, 'car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'archived_at' => null, 'disciplines' => 'ta_drift'],
               'class' => ['current' => null, 'earlierAccepted' => null], 'declarations' => [], 'season' => 2026,
               'techState' => 'none', 'techLabel' => 'Needs tech at the track', 'techAction' => null,
               'events' => ['tagged' => $tagged, 'untagged' => [self::OPEN], 'earlierSheets' => []], 'seasons' => ['summer' => true, 'ice' => false],
               'csrf' => 'tok', 'detailsForm' => null, 'usesSummer' => true, 'usesRace' => false, 'ice' => null, 'taDrift' => [],
               'tagDefaults' => ['ta']], $o);
    }

    public function testTaDriftEventRowLinksToTheTaDriftSheetWithoutADeclaration(): void
    {
        $html = renderGarageCarHtml($this->carVm([['event' => self::TA, 'sheet' => null, 'gearLinks' => [], 'formats' => ['ta'], 'tier' => 'ta_drift']]));
        $this->assertStringContainsString('<span class="hub-status hub-status--todo">No TA/Drift tech sheet yet</span>', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=new-ta-drift&amp;car_id=3&amp;event_id=20">Submit TA/Drift tech sheet</a>', $html);
        $this->assertStringNotContainsString('Declare a class first', $html);
        $this->assertStringContainsString('<details class="hub-entry-formats"><summary>Time Attack · Change</summary>', $html);
        $this->assertStringContainsString('<form method="post" action="garage.php" class="hub-line hub-tag-form">', $html);
    }

    public function testSubmittedTaDriftSheetShowsOnItsRow(): void
    {
        $sheet = ['id' => 9, 'season' => 2026, 'sheet_type' => 'ta_drift', 'discipline' => 'summer', 'club' => 'WSCC'];
        $html = renderGarageCarHtml($this->carVm([['event' => self::TA, 'sheet' => $sheet, 'gearLinks' => [], 'formats' => ['ta'], 'tier' => 'ta_drift']]));
        $this->assertStringContainsString('<span class="hub-status hub-status--ok">TA/Drift tech sheet submitted</span> <a href="tech-sheets.php?action=view&amp;id=9">View</a>', $html);
    }

    public function testBringThisCarFormHasThePickerWithTheCarsDefaults(): void
    {
        $html = renderGarageCarHtml($this->carVm([]));
        $this->assertStringContainsString('<input type="checkbox" name="formats[]" value="ta" checked> Time Attack</label>', $html);
        $this->assertStringContainsString(h("the host club's supplementary regulations"), $html);
    }

    public function testIceOnlyCarsGetNoPicker(): void
    {
        $html = renderGarageCarHtml($this->carVm([], ['seasons' => ['summer' => false, 'ice' => true], 'usesSummer' => false]));
        $this->assertStringNotContainsString('formats_shown', $html);
    }

    public function testCardNextTaDriftEventOffersTheTaDriftSheet(): void
    {
        $card = garageCard(['id' => 3, 'car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'disciplines' => 'ta_drift', 'archived_at' => null],
            [], [], [20], [self::TA], 2026, '2026-06-01', 2027, [20 => 'ta']);
        $this->assertStringContainsString('href="tech-sheets.php?action=new-ta-drift&amp;car_id=3&amp;event_id=20">Submit TA/Drift tech sheet</a>', garageRenderCard($card));
    }
```

- [ ] **Step 2: Run the tests to confirm they fail**

Run: `php phpunit.phar --filter 'GarageTaDriftTest|HomeFormatsSourceTest'`
Expected: FAIL. The rows have no `formats` and `garage.php` has no `formats` case.

- [ ] **Step 3: Implement `garageCarEvents`**

In `garage-lib.php`, replace the signature and the tagged-row line of `garageCarEvents`:

```php
function garageCarEvents(array $carSheets, array $taggedEventIds, array $activeEvents, array $eventNames, string $today): array {
```

with:

```php
function garageCarEvents(array $carSheets, array $taggedEventIds, array $activeEvents, array $eventNames, string $today, array $formatsByEvent = []): array {
```

and:

```php
            $tagged[] = ['event' => $e, 'sheet' => $sheetByEvent[(int)$e['id']] ?? null];
```

with:

```php
            $stored = $formatsByEvent[(int)$e['id']] ?? null;
            $tagged[] = ['event' => $e, 'sheet' => $sheetByEvent[(int)$e['id']] ?? null,
                         'formats' => entryFormatsParse($stored), 'tier' => entryTierAtEvent($e, $stored)];
```

Add this line to its doc comment: ` * $formatsByEvent: event id => the entry's stored formats; each tagged row carries formats and tier (entryTierAtEvent()).`

In `garageCard`, replace `$events = garageCarEvents($carSheets, $taggedEventIds, $activeEvents, [], $today);` with `$events = garageCarEvents($carSheets, $taggedEventIds, $activeEvents, [], $today, $formatsByEvent);`.

- [ ] **Step 4: Implement `garage-page.php`**

Directly above `function renderGarageCarHtml`, add:

```php
/** A tagged summer event's formats with a "Change" form (TA/Drift spec §3 Entry); '' for ice. */
function garageRenderEntryFormatsHtml(array $event, int $carId, array $formats, string $csrf, ?string $suppsAckAt = null): string {
    if (($event['discipline'] ?? 'summer') === 'ice') return '';
    return '<details class="hub-entry-formats"><summary>' . h(entryFormatsLabel($formats)) . ' · Change</summary>'
        . '<form method="post" action="garage.php" class="hub-line hub-tag-form">' . garageCsrfField($csrf)
        . '<input type="hidden" name="action" value="formats"><input type="hidden" name="car_id" value="' . $carId . '">'
        . '<input type="hidden" name="event_id" value="' . (int)$event['id'] . '">'
        . homeFormatsFieldsHtml($event, $formats, $suppsAckAt !== null)
        . '<button type="submit" class="hub-btn hub-btn--secondary">Save</button></form></details>';
}

```

In `renderGarageCarHtml`'s Events loop, replace:

```php
        } elseif ($sheet !== null) {
            $out .= '<span class="hub-status hub-status--ok">Tech sheet submitted</span> <a href="tech-sheets.php?action=view&amp;id=' . (int)$sheet['id'] . '">View</a>'
                . renderGearChips($row['gearLinks'], 'owner', ['sheet_season' => (int)($sheet['season'] ?? 0)]);
        } elseif ($isIce) {
```

with:

```php
        } elseif ($sheet !== null) {
            $out .= '<span class="hub-status hub-status--ok">' . (techSheetIsTaDrift($sheet) ? 'TA/Drift tech sheet submitted' : 'Tech sheet submitted') . '</span>'
                . ' <a href="tech-sheets.php?action=view&amp;id=' . (int)$sheet['id'] . '">View</a>'
                . renderGearChips($row['gearLinks'], 'owner', ['sheet_season' => (int)($sheet['season'] ?? 0)]);
        } elseif (($row['tier'] ?? 'race') === 'ta_drift') {
            $out .= '<span class="hub-status hub-status--todo">No TA/Drift tech sheet yet</span> ';
            $out .= $archived
                ? 'Restore the car to submit a tech sheet'
                : '<a class="hub-btn" href="' . h(garageTaDriftSheetUrl($id, $eid)) . '">Submit TA/Drift tech sheet</a>';
        } elseif ($isIce) {
```

In the same loop, replace:

```php
        if (!$archived) $out .= garagePostForm($csrf, 'untag', $id, 'Not going anymore', 'hub-btn hub-btn--link', '', ['event_id' => $eid]);
```

with:

```php
        if (!$archived) {
            $out .= garageRenderEntryFormatsHtml($e, $id, $row['formats'] ?? ['race'], $csrf, $row['suppsAckAt'] ?? null)
                . garagePostForm($csrf, 'untag', $id, 'Not going anymore', 'hub-btn hub-btn--link', '', ['event_id' => $eid]);
        }
```

In the "I'm going" form, replace:

```php
        $out .= '</select>' . (!empty($vm['offerReminders']) ? reminderOptInFieldsHtml() : '')
```

with:

```php
        $out .= '</select>' . (!empty($vm['seasons']['summer']) ? homeFormatsFieldsHtml(null, $vm['tagDefaults'] ?? ['race']) : '')
            . (!empty($vm['offerReminders']) ? reminderOptInFieldsHtml() : '')
```

In `garageRenderCard`, replace:

```php
    $nextIsIce = $next !== null && (($next['event']['discipline'] ?? 'summer') === 'ice');
    if ($usesRace && $card['class']['current'] === null && !$nextIsIce) {
```

with:

```php
    $nextIsIce = $next !== null && (($next['event']['discipline'] ?? 'summer') === 'ice');
    $nextIsTaDrift = $next !== null && ($next['tier'] ?? 'race') === 'ta_drift';
    if ($nextIsTaDrift && $next['sheet'] === null) {
        $out .= '<a class="hub-btn" href="' . h(garageTaDriftSheetUrl($id, (int)$next['event']['id'])) . '">Submit TA/Drift tech sheet</a>';
    } elseif ($usesRace && $card['class']['current'] === null && !$nextIsIce && !$nextIsTaDrift) {
```

`garage-page.php` needs `techSheetIsTaDrift`, `entryFormatsLabel` and `homeFormatsFieldsHtml`. Add `require_once __DIR__ . '/ta-drift-lib.php';` under its existing `require_once`. Every caller already loads `home-page.php`.

- [ ] **Step 5: Wire `garage.php`**

In `garageShowCar`, replace:

```php
    $events = garageCarEvents($allSheets, $tagged, db_get_active_events($pdo), $eventNames, $today);
```

with:

```php
    $events = garageCarEvents($allSheets, $tagged, db_get_active_events($pdo), $eventNames, $today, $formatsByEvent);
    foreach ($events['tagged'] as $i => $row) {
        $events['tagged'][$i]['suppsAckAt'] = db_get_entry($pdo, $uid, (int)$row['event']['id'], $carId)['supps_ack_at'] ?? null;
    }
```

In the `renderGarageCarHtml([...])` call, add:

```php
        'tagDefaults' => eventsDefaultFormats($pdo, $car, ['id' => 0, 'discipline' => 'summer', 'host_club' => 'any']),
```

This works because `eventsDefaultFormats` only looks at the event's discipline and host club. The placeholder `'any'` club asks for the car's own defaults: its last summer entry, else Time Attack for a TA/Drift-only car, else Race.

In `handleGaragePost`, replace:

```php
        case 'tag':
            $eventId = (int)($_POST['event_id'] ?? 0);
            $r = eventsTagCar($pdo, $uid, $eventId, $carId);
```

with:

```php
        case 'tag':
            $eventId = (int)($_POST['event_id'] ?? 0);
            $r = eventsTagCar($pdo, $uid, $eventId, $carId, entryFormatsFromPost($_POST), !empty($_POST['supps_ack']));
```

Directly before `case 'untag':`, add:

```php
        case 'formats':
            $r = eventsSetFormats($pdo, $uid, (int)($_POST['event_id'] ?? 0), $carId, entryFormatsFromPost($_POST) ?? [], !empty($_POST['supps_ack']));
            setFlash($r['ok'] ? 'Saved what this car is running.' : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            header('Location: garage.php?car=' . $carId . '#events');
            return;
```

- [ ] **Step 6: Run the tests to confirm they pass, then run the full suite**

Run: `php phpunit.phar --filter 'GarageTaDriftTest|HomeFormatsSourceTest'` → PASS. Then `php phpunit.phar` → all PASS.

In `GaragePageTest`, the car page view model has `seasons.summer = true`, so its "Bring this car" form now also contains the picker. The assertions there look for `name="action" value="tag"` and the event options, which the picker doesn't change. If one pins the exact markup between `</select>` and the button, update it and say so in the commit message.

- [ ] **Step 7: Commit**

```bash
git add garage-lib.php garage-page.php garage.php tests/GarageTaDriftTest.php tests/HomeFormatsSourceTest.php
git commit -m "feat(ta-drift): car page shows each event's formats, links the TA/Drift sheet and picks formats

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 8: Revoke notes for owners, and the gear level on Drivers and Home

**Files:**
- Modify: `garage-lib.php` (new `garageRevokeNotes`)
- Modify: `garage-page.php` (`renderGarageCarHtml` shows the notes)
- Modify: `garage.php` (passes `revokeNotes`)
- Modify: `drivers-lib.php` (`driversRows`: level suffix and revoke note)
- Modify: `drivers-page.php` (shows the note)
- Modify: `drivers.php` (ice revoke note)
- Modify: `index.php` (Home glance gear label suffix)
- Test: `tests/RevokeNotesTest.php` (new)

**Interfaces:**
- Consumes: Task 1's `gearLevelSuffix`, and the plan 1 columns `tech_sheets.revoke_note` and `gear_records.revoke_note`.
- Produces:
  - `garageRevokeNotes(array $carSheets): string[]`. For every car tech identity (`techCarKey`) that isn't accepted, it returns the newest non-empty `revoke_note`.
  - `driversRows()` rows gain `revokeNote` (`?string`, for the season's summer gear when it isn't accepted). The summer label gets `gearLevelSuffix()`.
  - The `ice` entry in `driversRows()`'s `$ice` input may carry `revokeNote`, which the row passes through.

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/RevokeNotesTest.php
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
require_once __DIR__ . '/../ice-sheet-lib.php';
require_once __DIR__ . '/../media-lib.php';
require_once __DIR__ . '/../drivers-lib.php';
require_once __DIR__ . '/../drivers-page.php';

use PHPUnit\Framework\TestCase;

final class RevokeNotesTest extends TestCase
{
    private function sheet(int $id, array $o = []): array {
        return array_merge(['id' => $id, 'car_id' => 3, 'event_id' => 20, 'season' => 2026, 'discipline' => 'summer', 'sheet_type' => 'standard',
                            'club' => null, 'status' => 'submitted', 'accepted_via' => null, 'photo_status' => null, 'revoke_note' => null], $o);
    }

    public function testNotesForIdentitiesThatAreNotAccepted(): void
    {
        $this->assertSame([], garageRevokeNotes([$this->sheet(1)]));
        $this->assertSame(['New engine'], garageRevokeNotes([$this->sheet(1, ['revoke_note' => 'Old note']), $this->sheet(2, ['revoke_note' => 'New engine'])]));
        // Accepted again on another sheet of the same identity: nothing to show.
        $this->assertSame([], garageRevokeNotes([$this->sheet(1, ['revoke_note' => 'New engine']), $this->sheet(2, ['status' => 'teched'])]));
        // A TA/Drift identity is separate from race.
        $this->assertSame(['Cage added'], garageRevokeNotes([$this->sheet(1, ['status' => 'teched']),
            $this->sheet(2, ['sheet_type' => 'ta_drift', 'club' => 'WSCC', 'revoke_note' => 'Cage added'])]));
    }

    public function testCarPageShowsTheNote(): void
    {
        $vm = ['car' => ['id' => 3, 'car_number' => '86', 'make' => 'Subaru', 'model' => 'BRZ', 'archived_at' => null],
               'class' => ['current' => null, 'earlierAccepted' => null], 'declarations' => [], 'season' => 2026,
               'techState' => 'none', 'techLabel' => 'Needs tech at the track', 'techAction' => null,
               'events' => ['tagged' => [], 'untagged' => [], 'earlierSheets' => []], 'seasons' => ['summer' => true, 'ice' => false],
               'csrf' => 'tok', 'detailsForm' => null, 'usesSummer' => true, 'ice' => null, 'revokeNotes' => ['New <engine>']];
        $this->assertStringContainsString('<p class="garage-note" role="status"><strong>Tech revoked:</strong> New &lt;engine&gt;</p>', renderGarageCarHtml($vm));
    }

    public function testDriversShowTheLevelAndTheNote(): void
    {
        $drivers = [['id' => 5, 'name' => 'Jordan Lee', 'licence_no' => ''], ['id' => 6, 'name' => 'Sam Patel', 'licence_no' => '']];
        $gear = [5 => ['id' => 40, 'status' => 'accepted', 'accepted_via' => 'in_person', 'level' => 'ta_drift', 'photo_status' => null, 'revoke_note' => null],
                 6 => ['id' => 41, 'status' => 'open', 'accepted_via' => null, 'level' => null, 'photo_status' => null, 'revoke_note' => 'Helmet expired']];
        $rows = driversRows($drivers, $gear, 5, 2026);
        $this->assertSame('Gear teched 2026 · TA/Drift', $rows[0]['label']);
        $this->assertNull($rows[0]['revokeNote']);
        $this->assertSame('Helmet expired', $rows[1]['revokeNote']);
        $html = renderDriversHtml(['rows' => $rows, 'season' => 2026, 'csrf' => 'tok', 'licenceLink' => null]);
        $this->assertStringContainsString('<p class="garage-note" role="status"><strong>Gear check revoked:</strong> Helmet expired</p>', $html);
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `php phpunit.phar --filter RevokeNotesTest`
Expected: FAIL. `garageRevokeNotes` is undefined.

- [ ] **Step 3: Implement the Garage notes**

In `garage-lib.php`, directly after `garageTaDriftSummaries`, add:

```php

/**
 * Why tech was taken back (TA/Drift spec §3 "Tech revoked: {note}"): for each of the car's tech
 * identities (race, TA/Drift per club, ice per club; techCarKey()) that isn't accepted now, the newest
 * sheet's non-empty revoke_note. Accepting again clears it, so accepted identities show nothing.
 * @return string[]
 */
function garageRevokeNotes(array $carSheets): array {
    $notes = [];
    foreach (techGroupSheetsByCar($carSheets) as $sheets) {
        if (techCarStatus($sheets)['state'] === 'accepted') continue;
        usort($sheets, fn(array $a, array $b): int => (int)$b['id'] <=> (int)$a['id']);
        foreach ($sheets as $s) {
            if (trim((string)($s['revoke_note'] ?? '')) !== '') { $notes[] = trim((string)$s['revoke_note']); break; }
        }
    }
    return $notes;
}
```

In `garage-page.php` `renderGarageCarHtml`, directly before `$out .= garageNextStepHtml($vm);`, add:

```php
    foreach ($vm['revokeNotes'] ?? [] as $note) {
        $out .= '<p class="garage-note" role="status"><strong>Tech revoked:</strong> ' . h($note) . '</p>';
    }
```

In `garage.php` `garageShowCar`, in the `renderGarageCarHtml([...])` call, add `'revokeNotes' => garageRevokeNotes($allSheets),`.

- [ ] **Step 4: Implement the Drivers level and note**

In `drivers-lib.php`, add `require_once __DIR__ . '/ta-drift-lib.php';` under its existing `require_once`. In `driversRows`, replace:

```php
        $row = ['driver' => $d, 'isSelf' => $id === $selfId, 'state' => $status['state'],
                'label' => driversGearLabel($status, $season), 'action' => driversGearAction($id, $status),
```

with:

```php
        $note = trim((string)($gear[$id]['revoke_note'] ?? ''));
        $row = ['driver' => $d, 'isSelf' => $id === $selfId, 'state' => $status['state'],
                'label' => driversGearLabel($status, $season) . gearLevelSuffix($gear[$id] ?? null), 'action' => driversGearAction($id, $status),
                'revokeNote' => $status['state'] !== 'accepted' && $note !== '' ? $note : null,
```

In the same function, replace:

```php
            $row['ice'] = ['state' => $i['state'], 'label' => $i['label'], 'action' => $action];
```

with:

```php
            $row['ice'] = ['state' => $i['state'], 'label' => $i['label'], 'action' => $action, 'revokeNote' => $i['revokeNote'] ?? null];
```

In `drivers-page.php` `driversRenderRow`, directly after the `</form>` of the licence form (`. '<button type="submit" class="hub-btn hub-btn--link">Save</button></form>';`), add:

```php
    if (($row['summer'] ?? true) && !empty($row['revokeNote'])) {
        $out .= '<p class="garage-note" role="status"><strong>Gear check revoked:</strong> ' . h((string)$row['revokeNote']) . '</p>';
    }
```

Inside `if (!empty($row['ice'])) { … }`, after the ice `</p>`, add:

```php
        if (!empty($i['revokeNote'])) $out .= '<p class="garage-note" role="status"><strong>Ice gear check revoked:</strong> ' . h((string)$i['revokeNote']) . '</p>';
```

In `drivers.php`, replace:

```php
        $ice[$did] = gearIceSummary($iceGear, $summerPrev, $iceSeason) + ($iceSheetByDriver[$did] ?? ['sheetId' => null, 'driverNumber' => 1]);
```

with:

```php
        $iceNote = $iceGear !== null && ($iceGear['status'] ?? '') !== 'accepted' ? trim((string)($iceGear['revoke_note'] ?? '')) : '';
        $ice[$did] = gearIceSummary($iceGear, $summerPrev, $iceSeason) + ($iceSheetByDriver[$did] ?? ['sheetId' => null, 'driverNumber' => 1])
            + ['revokeNote' => $iceNote !== '' ? $iceNote : null];
```

In `index.php`, in the `$drivers[] = [...]` builder, replace `'gearLabel' => gearStatusLabel($st, $season),` with `'gearLabel' => gearStatusLabel($st, $season) . gearLevelSuffix($g),`.

- [ ] **Step 5: Run the test to confirm it passes, then run the full suite**

Run: `php phpunit.phar --filter RevokeNotesTest` → PASS. Then `php phpunit.phar` → all PASS. `DriversLibTest` rows have no `level` or `revoke_note`, so their labels are unchanged.

- [ ] **Step 6: Commit**

```bash
git add garage-lib.php garage-page.php garage.php drivers-lib.php drivers-page.php drivers.php index.php tests/RevokeNotesTest.php
git commit -m "feat(ta-drift): owners see why tech was revoked, and TA/Drift-level gear is labelled

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 9: Inspect (roster rows, review queue, Gear tab level filter)

**Files:**
- Modify: `db.php` (`db_get_event_roster_cars` returns the entry's `formats`)
- Modify: `inspect-lib.php` (`inspectRosterRows` takes the host club; TA/Drift rows; the `class_not_accepted` filter; the queue detail)
- Modify: `inspect-page.php` (the class cell for TA/Drift rows)
- Modify: `inspect.php` (passes the host club)
- Modify: `gear-lib.php` (new `GEAR_ADMIN_LEVELS`, `gearRosterLevelFilter`)
- Modify: `admin-gear.php` (the level select and the TA/Drift suffix)
- Test: `tests/InspectTaDriftTest.php` (new)

**Interfaces:**
- Consumes: Task 1's `entryTierAtEvent`, and plan 1's `taDriftCarTechStatus`, `techSheetIsTaDrift`, `entryFormatsLabel`, `entryFormatsParse` and `gearLevelSuffix`.
- Produces:
  - `inspectRosterRows(…, array $key = [...], ?string $hostClub = null)`. Rows gain `tier` (`race` or `ta_drift`) and `formats` (a label).
  - A TA/Drift row's `status` is `taDriftCarTechStatus(...)`.
  - `GEAR_ADMIN_LEVELS = ['all' => 'Any level', 'race' => 'Accepted at race level', 'ta_drift' => 'Accepted at TA/Drift level']`
  - `gearRosterLevelFilter(array $records, string $level): array`

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/InspectTaDriftTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../tech-status.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../gear-chips.php';
require_once __DIR__ . '/../home-page.php';
require_once __DIR__ . '/../garage-lib.php';
require_once __DIR__ . '/../garage-page.php';
require_once __DIR__ . '/../inspect-lib.php';
require_once __DIR__ . '/../inspect-page.php';

use PHPUnit\Framework\TestCase;

final class InspectTaDriftTest extends TestCase
{
    private function car(array $o = []): array {
        return array_merge(['id' => 3, 'owner_user_id' => 1, 'car_number' => '86', 'year' => '', 'make' => 'Subaru', 'model' => 'BRZ',
                            'owner_name' => 'Jordan Lee', 'tagged' => 1, 'formats' => 'ta'], $o);
    }

    private function sheet(int $id, array $o = []): array {
        return array_merge(['id' => $id, 'car_id' => 3, 'user_id' => 1, 'event_id' => 20, 'season' => 2026, 'discipline' => 'summer',
                            'sheet_type' => 'ta_drift', 'club' => 'WSCC', 'status' => 'teched', 'accepted_via' => 'in_person',
                            'photo_status' => null, 'driver_name' => 'Jordan Lee'], $o);
    }

    private function rows(array $cars, array $seasonSheets, array $eventSheets = []): array {
        return inspectRosterRows($cars, $eventSheets, $seasonSheets, [], [], [], [], 2026, ['discipline' => 'summer', 'club' => null], 'WSCC');
    }

    public function testTaDriftRowsUseTheClubsTaDriftTech(): void
    {
        $row = $this->rows([$this->car()], [$this->sheet(9)])[0];
        $this->assertSame('ta_drift', $row['tier']);
        $this->assertSame('Time Attack', $row['formats']);
        $this->assertSame('accepted', $row['status']['state']);

        $race = $this->rows([$this->car(['formats' => 'race,ta'])], [$this->sheet(9)])[0];
        $this->assertSame('race', $race['tier']);
        $this->assertSame('none', $race['status']['state']);   // a TA/Drift sheet doesn't accept race tech

        $otherClub = $this->rows([$this->car()], [$this->sheet(9, ['club' => 'NASCC'])])[0];
        $this->assertSame('none', $otherClub['status']['state']);
    }

    public function testTaDriftRowsAreNeverClassNotAccepted(): void
    {
        $rows = $this->rows([$this->car()], []);
        $this->assertSame([], inspectRosterFilter($rows, 'class_not_accepted'));
        $this->assertCount(1, inspectRosterFilter($rows, 'needs_tech'));
    }

    public function testRosterRowShowsTaDriftInsteadOfTheClass(): void
    {
        $row = $this->rows([$this->car(['formats' => 'ta,drift'])], [])[0];
        $html = inspectRosterRowHtml($row, ['season' => 2026, 'csrf' => 'tok', 'filter' => 'all', 'discipline' => 'summer']);
        $this->assertStringContainsString('<p><span class="hub-status hub-status--info">TA/Drift</span> Time Attack · Drift</p>', $html);
        $this->assertStringNotContainsString('No class declared yet', $html);
    }

    public function testQueueLineNamesTaDriftAndTheClub(): void
    {
        $items = inspectReviewQueue([], [$this->sheet(9, ['entrant_name' => 'Jordan Lee', 'event_name' => 'WSCC TA', 'car_make' => 'Subaru',
            'car_model' => 'BRZ', 'car_number' => '86', 'updated_at' => '2026-07-01 10:00:00'])], []);
        $this->assertSame('Jordan Lee · WSCC TA · TA/Drift · WSCC', $items[0]['detail']);
    }

    public function testGearLevelFilter(): void
    {
        $race = ['status' => 'accepted', 'level' => null];
        $tad = ['status' => 'accepted', 'level' => 'ta_drift'];
        $open = ['status' => 'open', 'level' => null];
        $this->assertSame([$race, $tad, $open], gearRosterLevelFilter([$race, $tad, $open], 'all'));
        $this->assertSame([$race], gearRosterLevelFilter([$race, $tad, $open], 'race'));
        $this->assertSame([$tad], gearRosterLevelFilter([$race, $tad, $open], 'ta_drift'));
        $this->assertSame([$race, $tad, $open], gearRosterLevelFilter([$race, $tad, $open], 'nonsense'));
    }

    public function testGearTabHasTheLevelSelectForSummer(): void
    {
        $src = (string)file_get_contents(__DIR__ . '/../admin-gear.php');
        $this->assertStringContainsString('id="gear-level-filter" name="level"', $src);
        $this->assertStringContainsString('gearRosterLevelFilter(', $src);
        $this->assertStringContainsString('gearLevelSuffix($g)', $src);
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

Run: `php phpunit.phar --filter InspectTaDriftTest`
Expected: FAIL. The rows have no `tier`, and `gearRosterLevelFilter` is undefined.

- [ ] **Step 3: The roster query returns the formats**

In `db.php` `db_get_event_roster_cars`, replace:

```php
               EXISTS (SELECT 1 FROM event_plans p WHERE p.event_id = :e AND p.car_id = c.id) AS tagged
```

with:

```php
               EXISTS (SELECT 1 FROM event_plans p WHERE p.event_id = :e AND p.car_id = c.id) AS tagged,
               (SELECT p.formats FROM event_plans p WHERE p.event_id = :e AND p.car_id = c.id) AS formats
```

- [ ] **Step 4: Roster rows and the queue**

In `inspect-lib.php`, add `require_once __DIR__ . '/ta-drift-lib.php';` under its existing `require_once`.

Replace the signature of `inspectRosterRows`:

```php
function inspectRosterRows(array $cars, array $eventSheets, array $seasonSheets, array $declarations,
                           array $sheetDrivers, array $selfDrivers, array $seasonGear, int $season,
                           array $key = ['discipline' => 'summer', 'club' => null]): array {
```

with:

```php
function inspectRosterRows(array $cars, array $eventSheets, array $seasonSheets, array $declarations,
                           array $sheetDrivers, array $selfDrivers, array $seasonGear, int $season,
                           array $key = ['discipline' => 'summer', 'club' => null], ?string $hostClub = null): array {
```

Add to its doc comment: ` * @param ?string $hostClub the event's host club: a summer entry whose formats need TA/Drift (entryTierAtEvent()), or whose sheet here is TA/Drift, shows that club's TA/Drift tech (race tech covers it).`

Replace:

```php
        $rows[] = [
            'car' => $car,
            'sheet' => $sheet,
            'class' => garageClassLine($declarations[$cid] ?? []),
            'status' => techCarStatus($groups[techCarKey(['car_id' => $cid, 'season' => $season, 'discipline' => $key['discipline'], 'club' => $key['club']])] ?? []),
```

with:

```php
        $status = techCarStatus($groups[techCarKey(['car_id' => $cid, 'season' => $season, 'discipline' => $key['discipline'], 'club' => $key['club']])] ?? []);
        $tier = $isIce ? TECH_TIER_RACE
            : entryTierAtEvent(['discipline' => 'summer', 'host_club' => $hostClub], isset($car['formats']) ? (string)$car['formats'] : null);
        if (!$isIce && $sheet !== null && techSheetIsTaDrift($sheet)) $tier = TECH_TIER_TA_DRIFT;
        if ($tier === TECH_TIER_TA_DRIFT) {
            $tadKey = techCarKey(['car_id' => $cid, 'season' => $season, 'sheet_type' => SHEET_TYPE_TA_DRIFT, 'club' => (string)$hostClub]);
            $status = taDriftCarTechStatus($status, techCarStatus($groups[$tadKey] ?? []));
        }
        $rows[] = [
            'car' => $car,
            'sheet' => $sheet,
            'class' => garageClassLine($declarations[$cid] ?? []),
            'status' => $status,
            'tier' => $tier,
            'formats' => entryFormatsLabel(entryFormatsParse(isset($car['formats']) ? (string)$car['formats'] : null)),
```

In `inspectRosterFilter`, replace:

```php
        // class_not_accepted: an ice row's class comes from its sheet, not the summer declaration.
        if (($r['ice_class'] ?? '') !== '') return false;
```

with:

```php
        // class_not_accepted: an ice row's class comes from its sheet, and a TA/Drift row has none.
        if (($r['ice_class'] ?? '') !== '' || ($r['tier'] ?? TECH_TIER_RACE) === TECH_TIER_TA_DRIFT) return false;
```

In `inspectReviewQueue`, replace:

```php
                . (techSheetIsIce($s) ? ' · Ice · ' . techSheetClassLine($s) : ''),
```

with:

```php
                . (techSheetIsIce($s) ? ' · Ice · ' . techSheetClassLine($s) : '')
                . (techSheetIsTaDrift($s) ? ' · TA/Drift · ' . $s['club'] : ''),
```

In `inspect-page.php` `inspectRosterRowHtml`, replace:

```php
    if (($row['ice_class'] ?? '') !== '') $classCell = '<p>' . h($row['ice_class']) . '</p>';
```

with:

```php
    if (($row['ice_class'] ?? '') !== '') $classCell = '<p>' . h($row['ice_class']) . '</p>';
    if (($row['tier'] ?? 'race') === 'ta_drift') $classCell = '<p><span class="hub-status hub-status--info">TA/Drift</span> ' . h((string)$row['formats']) . '</p>';
```

In `inspect.php`, replace:

```php
            $season, $key
        );
```

with:

```php
            $season, $key, $event['host_club'] ?? null
        );
```

- [ ] **Step 5: The Gear tab level filter**

In `gear-lib.php`, directly after `gearRosterFilter`, add:

```php

/** The Gear tab's summer level filter (TA/Drift spec §5). */
const GEAR_ADMIN_LEVELS = ['all' => 'Any level', 'race' => 'Accepted at race level', 'ta_drift' => 'Accepted at TA/Drift level'];

/** $level is a GEAR_ADMIN_LEVELS key; unknown means 'all'. Race is accepted with no level (TA/Drift spec §2). */
function gearRosterLevelFilter(array $records, string $level): array {
    if (!in_array($level, ['race', 'ta_drift'], true)) return $records;
    return array_values(array_filter($records, fn(array $g): bool => ($g['status'] ?? '') === 'accepted'
        && ($level === 'race' ? ($g['level'] ?? null) === null : ($g['level'] ?? null) === GEAR_LEVEL_TA_DRIFT)));
}
```

In `admin-gear.php` `handleGearAdminList`, replace:

```php
    renderGearAdminListPage(gearRosterFilter($records, $filter), $season, $discipline, $filter, $counts, getFlash());
```

with:

```php
    $level = $discipline === DISCIPLINE_SUMMER && is_string($_GET['level'] ?? null) && isset(GEAR_ADMIN_LEVELS[$_GET['level']]) ? $_GET['level'] : 'all';
    renderGearAdminListPage(gearRosterLevelFilter(gearRosterFilter($records, $filter), $level), $season, $discipline, $filter, $counts, getFlash(), $level);
```

Change the `renderGearAdminListPage` signature to add `, string $level = 'all'` at the end.

In its filter form, directly after the `</select>` that closes `#gear-filter`, add:

```php
    <?php if ($discipline === DISCIPLINE_SUMMER): ?>
    <label for="gear-level-filter">Level</label>
    <select id="gear-level-filter" name="level">
      <?php foreach (GEAR_ADMIN_LEVELS as $value => $label): ?>
      <option value="<?= h($value) ?>"<?= $value === $level ? ' selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
```

In the table row, replace:

```php
if ($discipline === DISCIPLINE_ICE && $st['state'] === 'accepted' && !empty($g['level'])) { $statusLabel .= ' · ' . (ICE_GEAR_LEVEL_LABELS[$g['level']] ?? $g['level']); } ?>
```

with:

```php
if ($discipline === DISCIPLINE_ICE && $st['state'] === 'accepted' && !empty($g['level'])) { $statusLabel .= ' · ' . (ICE_GEAR_LEVEL_LABELS[$g['level']] ?? $g['level']); } elseif ($discipline === DISCIPLINE_SUMMER) { $statusLabel .= gearLevelSuffix($g); } ?>
```

`admin-gear.php` loads `gear-lib.php`, which requires `ta-drift-lib.php` (plan 1 Task 9), so `gearLevelSuffix` is available.

- [ ] **Step 6: Run the test to confirm it passes, then run the full suite**

Run: `php phpunit.phar --filter InspectTaDriftTest` → PASS. Then `php phpunit.phar` → all PASS. `InspectLibTest` rows have no `formats`, so they read as race and their statuses are unchanged.

- [ ] **Step 7: Commit**

```bash
git add db.php inspect-lib.php inspect-page.php inspect.php gear-lib.php admin-gear.php tests/InspectTaDriftTest.php
git commit -m "feat(ta-drift): Inspect roster and queue show TA/Drift; Gear tab filters by level

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 10: MotorsportReg brings in Time Trial and Drift events

**Files:**
- Modify: `msr-lib.php` (`MSR_RACE_TYPES`, new `MSR_TYPE_CHIPS`, `msrTypeChip`)
- Modify: `admin-msr.php` (the chip and the intro)
- Test: `tests/MsrTaDriftTest.php` (new)

**Interfaces:**
- Consumes: nothing new.
- Produces:
  - `MSR_RACE_TYPES = ['Ice Racing', 'Club Race', 'Time Trial', 'Drift']`
  - `msrTypeChip(string $type): string` returns `Ice`, `Race`, `TA` or `Drift`.
  - "Add to hub" prefills every type except `Ice Racing` as a summer event with the club's host club. It already does this (`admin-msr.php` `'discipline' => $r['type'] === 'Ice Racing' ? 'ice' : 'summer'`); this task only pins it with a test.

- [ ] **Step 1: Confirm MotorsportReg's type names**

MotorsportReg's public calendar lists its event types as JSON-ish data inside the page. On 2026-09-29 it showed `"Time Trial"` (slug `time-trial`, "Time Trial, Time Attack and Hillclimb Events") and `"Drift"` (slug `drifting`). Re-check before coding:

```bash
curl -s --max-time 30 "https://www.motorsportreg.com/calendar/" | grep -oE '"[A-Za-z/ ]+","(time-trial|drifting|club-race|ice-racing)"'
```

Expected: `"Time Trial","time-trial"`, `"Drift","drifting"`, `"Club Race","club-race"` and `"Ice Racing","ice-racing"` (the `type` values the club feeds carry are the first string). If the names differ, use the printed ones in `MSR_RACE_TYPES`, `MSR_TYPE_CHIPS` and the test below.

The clubs' own live feeds don't have a Time Trial or Drift event right now. Check them too, so you know when one appears:

```bash
for o in 2386B6E3-96BC-AE58-0812CF4B556BCBC2 4D45EE74-0A85-F011-ACBD5982F016139D; do curl -s --max-time 20 -H "Accept: application/json" "https://api.motorsportreg.com/rest/calendars/organization/$o.json" | python -c "import json,sys; [print(repr(e.get('type')), '|', e.get('name')) for e in json.load(sys.stdin)['response']['events']]"; done
```

- [ ] **Step 2: Write the failing test**

```php
<?php
// wcma-calculator/tests/MsrTaDriftTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../clubs-lib.php';
require_once __DIR__ . '/../ice-rules.php';
require_once __DIR__ . '/../msr-lib.php';
require_once __DIR__ . '/../admin-ui.php';
require_once __DIR__ . '/../admin-events.php';
require_once __DIR__ . '/../admin-msr.php';

use PHPUnit\Framework\TestCase;

final class MsrTaDriftTest extends TestCase
{
    private function feed(array $types): string {
        $events = [];
        foreach ($types as $i => $type) {
            $events[] = ['id' => sprintf('2386B6E3-96BC-AE58-0812CF4B556BCB%02d', $i), 'name' => "Event $i", 'start' => '2026-07-12', 'type' => $type];
        }
        return json_encode(['response' => ['events' => $events]]);
    }

    public function testTimeTrialAndDriftAreKept(): void
    {
        $parsed = msrParseFeed($this->feed(['Time Trial', 'Drift', 'HPDE', 'Autocross/Solo', 'Club Race']));
        $this->assertSame(['Time Trial', 'Drift', 'Club Race'], array_column($parsed['events'], 'type'));
    }

    public function testChips(): void
    {
        $this->assertSame(['Ice', 'Race', 'TA', 'Drift', 'Race'],
            [msrTypeChip('Ice Racing'), msrTypeChip('Club Race'), msrTypeChip('Time Trial'), msrTypeChip('Drift'), msrTypeChip('Something new')]);
    }

    public function testATimeTrialIsAddedAsASummerEventForItsClub(): void
    {
        $row = ['msr_id' => 'AAAAAAAA-BBBB-CCCC-DDDDDDDDDDDDDDDD', 'club_code' => 'WSCC', 'name' => 'WSCC Time Attack #1',
            'start_date' => '2026-07-12', 'end_date' => '2026-07-12', 'type' => 'Time Trial', 'venue' => 'Gimli',
            'detail_url' => '', 'cancelled' => 0, 'status' => 'new', 'hub_event_id' => null,
            'is_primary' => 0, 'snap_name' => null, 'snap_start' => null, 'snap_venue' => null, 'snap_cancelled' => null];
        $clubs = [['code' => 'WSCC', 'name' => 'Winnipeg Sports Car Club', 'msr_url' => '', 'msr_org_id' => 'X', 'active' => 1]];
        $html = renderMsrPageHtml([$row], [], $clubs, [], 'tok', null, null);
        $this->assertStringContainsString('<span class="admin-chip admin-chip--info">TA</span>', $html);
        $this->assertStringContainsString('Race, time attack and drift events', $html);
        $add = substr($html, strpos($html, 'id="msr-add-aaaaaaaa-bbbb-cccc-dddddddddddddddd"'));
        $add = substr($add, 0, strpos($add, '</dialog>'));
        $this->assertMatchesRegularExpression('/name="discipline" value="summer" checked/', $add);
    }
}
```

- [ ] **Step 3: Run the test to confirm it fails**

Run: `php phpunit.phar --filter MsrTaDriftTest`
Expected: FAIL. Time Trial and Drift are dropped, and `msrTypeChip` is undefined.

If `testATimeTrialIsAddedAsASummerEventForItsClub` fails only on the regex, `adminEventFieldsHtml()` writes its radios in a different attribute order. Open `admin-events.php` around line 106, and match the radio markup exactly as it is written there, for example `value="summer" checked>`.

- [ ] **Step 4: Implement**

In `msr-lib.php`, replace:

```php
const MSR_RACE_TYPES = ['Ice Racing', 'Club Race'];
```

with:

```php
// MotorsportReg event types the review page shows (TA/Drift spec §5): races, ice, and stand-alone
// Time Attack ("Time Trial" on MotorsportReg) and Drift events. Each type's chip on the review page.
const MSR_RACE_TYPES = ['Ice Racing', 'Club Race', 'Time Trial', 'Drift'];
const MSR_TYPE_CHIPS = ['Ice Racing' => 'Ice', 'Club Race' => 'Race', 'Time Trial' => 'TA', 'Drift' => 'Drift'];

/** The review page's chip for an MSR event type: Ice, Race, TA or Drift. */
function msrTypeChip(string $type): string {
    return MSR_TYPE_CHIPS[$type] ?? 'Race';
}
```

In `admin-msr.php` `adminMsrEventCells`, replace:

```php
    $chip = $r['type'] === 'Ice Racing' ? adminChip('Ice', 'info') : adminChip('Race', 'info');
```

with:

```php
    $chip = adminChip(msrTypeChip((string)$r['type']), 'info');
```

Replace:

```php
        . '<p class="admin-intro">Race events from the clubs\' MotorsportReg calendars. Add them to the hub, attach extra events to a '
```

with:

```php
        . '<p class="admin-intro">Race, time attack and drift events from the clubs\' MotorsportReg calendars. Add them to the hub, attach extra events to a '
```

- [ ] **Step 5: Run the test to confirm it passes, then run the full suite**

Run: `php phpunit.phar --filter MsrTaDriftTest` → PASS. Then `php phpunit.phar` → all PASS. `MsrLibTest` fixtures have no Time Trial or Drift events, so they're unchanged.

`AdminMsrPageTest` may assert the old intro sentence. If it does, update it to the new wording, and say so in the commit message.

- [ ] **Step 6: Commit**

```bash
git add msr-lib.php admin-msr.php tests/MsrTaDriftTest.php
git commit -m "feat(ta-drift): MotorsportReg import keeps Time Trial and Drift events, labelled TA and Drift

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

### Task 11: The seed TA/Drift event and the phone audit

**Files:**
- Modify: `hub-db-tools.php` (`hubSeed` adds a summer WSCC Time Attack event)
- Modify: `tests/HubDbToolsTest.php` (the event count goes from 4 to 5)
- Modify: `tests/ux/audit.mjs` (the TA/Drift flow)

**Interfaces:**
- Consumes: plan 2's TA/Drift sheet form at `tech-sheets.php?action=new-ta-drift`, and Tasks 4, 5 and 7.
- Produces: the seed event `WSCC Time Attack` (summer, host club WSCC, today + 24 days, Gimli Motorsports Park). The phone audit gains three pages:
  - `TA/Drift tech sheet`
  - `home with a TA/Drift entry`
  - `home (change what you are running)`

- [ ] **Step 1: Update the seed test**

In `tests/HubDbToolsTest.php`, replace `$this->assertCount(4, db_get_active_events($pdo));` with `$this->assertCount(5, db_get_active_events($pdo));`. Then add this at the end of the same test method:

```php
        $ta = array_values(array_filter(db_get_all_events($pdo), fn(array $e): bool => $e['name'] === 'WSCC Time Attack'));
        $this->assertSame(['summer', 'WSCC'], [$ta[0]['discipline'], $ta[0]['host_club']]);
```

Run: `php phpunit.phar --filter HubDbToolsTest`
Expected: FAIL (4 events).

- [ ] **Step 2: Seed the event**

In `hub-db-tools.php` `hubSeed`, directly after the `Season Finale` line, add:

```php
    db_create_event($pdo, 'WSCC Time Attack', date('Y-m-d', strtotime('+24 days')), 'Gimli Motorsports Park', 'summer', 'WSCC');
```

In its `return [...]`, replace `'events' => 4` with `'events' => 5`.

Run: `php phpunit.phar --filter HubDbToolsTest` → PASS. Then `php phpunit.phar` → all PASS. Any other test that asserts the seed summary array needs `'events' => 5`.

- [ ] **Step 3: Add the TA/Drift flow to the phone audit**

In `tests/ux/audit.mjs`, directly after:

```js
  report('no draft left after submitting', leftover === 0 ? [] : ['the submitted sheet\'s draft was offered again']);
```

add:

```js
  // TA/Drift (2026-09-29 spec): a Summer TA/Drift only car for a summer event, its sheet, and the
  // Race / Time Attack / Drift picker (the Season Finale card has no host club, so it shows the
  // picker with Time Attack and Drift disabled).
  await page.goto(BASE + '/index.php');
  await go('section.hub-event:has-text("WSCC Time Attack") a:has-text("Add a car for this event")');
  await page.check('input[name=disciplines][value=ta_drift]');
  await page.fill('#car-car_number', '86');
  await page.fill('#car-make', 'Subaru');
  await page.fill('#car-model', 'BRZ');
  await page.fill('#car-colour', 'White');
  await go('button:has-text("Add car")');
  report('a TA/Drift-only car goes straight to the TA/Drift tech sheet', page.url().includes('action=new-ta-drift')
    ? [] : [`expected the TA/Drift sheet, got ${page.url()}`]);
  await audit(page, 'TA/Drift tech sheet');
  await page.goto(BASE + '/index.php');
  await audit(page, 'home with a TA/Drift entry');
  const picker = await page.locator('section.hub-event:has-text("Season Finale") input[name="formats[]"][value=ta]').isDisabled();
  report('no host club: Time Attack is disabled', picker ? [] : ['Time Attack was enabled for an event with no host club']);
  await page.click('section.hub-event:has-text("WSCC Time Attack") .hub-entry-formats summary');
  await audit(page, 'home (change what you are running)');
```

- [ ] **Step 4: Run the phone audit**

Run from the repo root: `bash wcma-calculator/tests/ux/run-audit.sh`
Expected: every page passes, including the three new ones, and both new `report(...)` checks print no problems.

The audit fails on tap targets under 44px, checkboxes under 24px, text under 16px and low contrast. If a new element fails one of these, fix it in `css/hub.css`:
- The picker already has `min-height: var(--hub-tap)` and 16px text.
- A disabled label uses `--hub-ink-2`, which meets 4.5:1 on the card background (it's used for the `.hub-status--info` text).

- [ ] **Step 5: Commit**

```bash
git add hub-db-tools.php tests/HubDbToolsTest.php tests/ux/audit.mjs
git commit -m "test(ta-drift): seed a WSCC Time Attack event and audit the TA/Drift flow on a phone

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5"
```

---

## Spec coverage (self-review)

| Spec | Where |
|---|---|
| §4 Readiness: race unchanged, TA/Drift items 1–4, and the driver-source rule | Tasks 1 and 2 |
| §4 `suggested` state: softer on Home, never counted, not reminded | Tasks 2 and 3 |
| §3 Entry: checkboxes and defaults, regulations box, no host club disabled, ice has no picker, changing formats later, cards show formats | Tasks 4 and 7 |
| §4 Garage: TA/Drift chips, `garageAfterAdd`, no class for a TA/Drift-only car | Tasks 5, 6 and 7 |
| §2 cars: the `Summer TA/Drift only` option | Task 5 |
| §3 revoke note shown to the owner ("Tech revoked: {note}") | Task 8 |
| §4 Drivers: gear level label | Task 8 |
| §4 Reminders: TA/Drift items with no code of their own, and suggested left out | Task 3 (test) |
| §5 MotorsportReg: Time Trial and Drift types, labels, added as summer with host club | Task 10 |
| §5 admin: Gear level filter, and TA/Drift on the tech sheet lists | Task 9 |
| §6 Testing: readiness for TA-only, Race+TA and ice (unchanged); suggested counting; the regulations tick; MotorsportReg; phone layout | Tasks 1–4, 10 and 11 |

`ReadinessTest` covers ice entries staying unchanged. It keeps running unmodified in every task.
