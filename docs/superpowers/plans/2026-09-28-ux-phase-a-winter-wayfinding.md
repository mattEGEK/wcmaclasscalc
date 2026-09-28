# UX Phase A — Winter Wayfinding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A winter (ice) driver can sign up, add a car, pick an ice event and reach the ice tech sheet without being pushed down the summer "declare class" path, and knows what to do after submitting.

**Architecture:** Cars store their season (`cars.disciplines`: `ice` / `summer` / `both`, null for legacy cars). One pure helper, `garageCarSeasons()`, combines the stored value with activity (declarations, sheets, event tags), and every existing summer/ice check goes through it. The page changes are pure view functions (`garage-page.php`, `home-page.php`, new `tech-sheet-next.php`) plus small controller wiring. Redirect decisions are pure helpers in `garage-lib.php` so they can be tested.

**Tech Stack:** PHP 8.3, SQLite via PDO, PHPUnit (`php phpunit.phar`), plain CSS in `css/hub.css`.

**Spec:** `docs/superpowers/specs/2026-09-28-mobile-ux-older-users-design.md` (Phase A)

## Global Constraints

- Run tests from `wcma-calculator/`: `php phpunit.phar` (baseline: 898 tests, all green).
- Controllers (`garage.php`, `index.php`, `tech-sheets.php`, `drivers.php`) require `config.php` and cannot run under PHPUnit. Put logic in pure functions and test those; guard controllers with source-level tests like `tests/GarageSourceTest.php`.
- Season values are exactly `ice`, `summer`, `both`; null means unknown (legacy car).
- Activity always wins: a stored season never hides a declaration, sheet or tag that exists.
- Copy is sentence case, plain words, no jargon. Buttons are `hub-btn` (primary) or `hub-btn hub-btn--secondary`.
- Every POST form keeps its `csrf_token` field. Escape all output with `h()`.
- Commit after each task on branch `ux-phase-a` (create it from `main` before Task 1). End commit messages with:
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5
  ```

## Review Focus

1. Legacy cars (`disciplines` null) must behave exactly as today on Garage, the car page, Home and Drivers. Pinned in Task 2.
2. A car stored as `ice` that already has a summer declaration still shows its class. Pinned in Task 2.
3. `garage.php?action=add&event_id=…` with a bogus, inactive or past event must still add the car and just ignore the event. Pinned in Task 3.
4. "Pick your ice event" (`then=sheet`) on a summer event must go to the car page, not the ice sheet. Pinned in Task 4.
5. An archived ice car must not show the next-step card or the event buttons. Pinned in Task 4.
6. On Home, an ice-only car must not be offered for a summer event; the card offers "Add a car for this event" instead. Pinned in Task 5.

---

### Task 1: Store a car's season

**Files:**
- Modify: `wcma-calculator/db.php` (cars `CREATE TABLE` ~line 278, `DB_CAR_FIELDS` line 1025, `db_create_car()` line 1027)
- Modify: `wcma-calculator/cars-lib.php` (`carsResolveForDeclaration()`)
- Test: `wcma-calculator/tests/DbCarsTest.php`, `wcma-calculator/tests/CarsLibTest.php`

**Interfaces:**
- Produces: `cars.disciplines` column (TEXT, nullable). `db_create_car($pdo, $owner, ['disciplines' => 'ice', …])` and `db_update_car($pdo, $id, ['disciplines' => …])` read and write it. Cars created from the Class Calculator get `'summer'`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/DbCarsTest.php`:

```php
    public function testCarStoresItsSeasonAndLegacyCarsHaveNone(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $legacy = db_create_car($pdo, $u, ['car_number' => '1', 'make' => 'Honda', 'model' => 'Civic']);
        $ice = db_create_car($pdo, $u, ['car_number' => '2', 'make' => 'Honda', 'model' => 'Civic', 'disciplines' => 'ice']);

        $this->assertNull(db_get_car($pdo, $legacy)['disciplines']);
        $this->assertSame('ice', db_get_car($pdo, $ice)['disciplines']);

        db_update_car($pdo, $legacy, ['disciplines' => 'both']);
        $this->assertSame('both', db_get_car($pdo, $legacy)['disciplines']);
    }

    public function testSeasonColumnIsAddedToAnExistingDatabase(): void
    {
        $pdo = make_temp_pdo();
        $pdo->exec('ALTER TABLE cars DROP COLUMN disciplines');
        db_init($pdo);
        $cols = array_column($pdo->query('PRAGMA table_info(cars)')->fetchAll(), 'name');
        $this->assertContains('disciplines', $cols);
    }
```

Add to `tests/CarsLibTest.php`:

```php
    public function testANewCarFromTheCalculatorIsASummerCar(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $r = carsResolveForDeclaration($pdo, $u, ['car_id' => 'new', 'car_number' => '7', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000']);
        $this->assertTrue($r['ok']);
        $this->assertSame('summer', db_get_car($pdo, (int)$r['car_id'])['disciplines']);
    }
```

- [ ] **Step 2: Run them and check they fail**

Run: `php phpunit.phar --filter "testCarStoresItsSeason|testSeasonColumnIsAdded|testANewCarFromTheCalculator"`
Expected: FAIL (no `disciplines` column / undefined index).

- [ ] **Step 3: Implement**

In `db.php`, add the column to the cars `CREATE TABLE` (after `engine_cc TEXT,`):

```php
            engine_cc       TEXT,
            disciplines     TEXT,
```

Directly after `$pdo->exec("CREATE INDEX IF NOT EXISTS idx_cars_owner ON cars (owner_user_id)");` add:

```php
    // Mobile UX spec 2026-09-28 §A1: the season a car races (ice/summer/both); null for older cars.
    db_add_column_if_missing($pdo, 'cars', 'disciplines', 'TEXT');
```

Change `DB_CAR_FIELDS`:

```php
const DB_CAR_FIELDS = ['car_number', 'year', 'make', 'model', 'colour', 'engine_cc', 'disciplines'];
```

In `db_create_car()`, add the column to the INSERT:

```php
        INSERT INTO cars (owner_user_id, car_number, car_number_norm, year, make, model, colour, engine_cc, disciplines, created_at, updated_at)
        VALUES (:o, :n, :norm, :y, :make, :model, :colour, :cc, :disc, :now, :now)
```

and to the params: `':disc' => $d['disciplines'] ?? null,`.

In `cars-lib.php` `carsResolveForDeclaration()`, the new-car line becomes:

```php
    $id = db_create_car($pdo, $userId, ['car_number' => $number, 'year' => $details['year'] ?: null, 'disciplines' => 'summer'] + $details);
```

- [ ] **Step 4: Run the tests**

Run: `php phpunit.phar --filter "DbCarsTest|CarsLibTest"`
Expected: PASS. If `ALTER TABLE … DROP COLUMN` is unsupported by the local SQLite, replace that test's first two lines with building the legacy `cars` table by hand (copy the `CREATE TABLE` without `disciplines`) in a fresh PDO before calling `db_init()`.

- [ ] **Step 5: Run the full suite and commit**

Run: `php phpunit.phar` — expected all green.

```bash
git add wcma-calculator/db.php wcma-calculator/cars-lib.php wcma-calculator/tests/DbCarsTest.php wcma-calculator/tests/CarsLibTest.php
git commit -m "feat(ux): cars store the season they race (ice/summer/both)"
```

---

### Task 2: One rule for a car's seasons

**Files:**
- Modify: `wcma-calculator/garage-lib.php` (`garageCard()`, `garageIceSummary()`, `garageCarUsesSummer()`, `userUsesSummer()`, `userHasIceActivity()`)
- Modify: `wcma-calculator/index.php` (lines 68–85), `wcma-calculator/garage.php` (`garageShowCar()`), `wcma-calculator/drivers.php` (lines 70–73)
- Test: `wcma-calculator/tests/GarageLibTest.php`

**Interfaces:**
- Produces:
  - `const CAR_DISCIPLINES = ['ice', 'summer', 'both'];`
  - `carSeasons(?string $stored, bool $summerActivity, bool $iceActivity): array{summer: bool, ice: bool}`
  - `garageCarSeasons(array $car, array $declarations, array $carSheets, bool $taggedSummer, bool $taggedIce): array{summer: bool, ice: bool}`
  - `garageCarUsesSummer(array $declarations, array $carSheets, bool $taggedToSummer, bool $taggedToIce = false, ?string $stored = null): bool`
  - `garageIceSummary(array $carSheets, bool $taggedToIce, int $iceSeason, ?string $stored = null): ?array`
  - `userHasIceActivity(array $sheets, bool $hasIceGear, array $plans, array $activeEvents, array $cars = []): bool`
  - `garageCard()` output gains `'seasons' => array{summer, ice}` (keeps `usesSummer`).

- [ ] **Step 1: Write the failing tests**

Add to `tests/GarageLibTest.php`:

```php
    public function testCarSeasonsLegacyCarsKeepTodaysInference(): void
    {
        $this->assertSame(['summer' => true, 'ice' => false], carSeasons(null, false, false));
        $this->assertSame(['summer' => false, 'ice' => true], carSeasons(null, false, true));
        $this->assertSame(['summer' => true, 'ice' => true], carSeasons(null, true, true));
        $this->assertSame(['summer' => true, 'ice' => false], carSeasons('nonsense', false, false));
    }

    public function testCarSeasonsStoredValueWinsButActivityIsNeverHidden(): void
    {
        $this->assertSame(['summer' => false, 'ice' => true], carSeasons('ice', false, false));
        $this->assertSame(['summer' => true, 'ice' => false], carSeasons('summer', false, false));
        $this->assertSame(['summer' => true, 'ice' => true], carSeasons('both', false, false));
        // An ice car that was declared for summer still shows its class.
        $this->assertSame(['summer' => true, 'ice' => true], carSeasons('ice', true, false));
        // A summer car tagged to an ice event still shows ice tech.
        $this->assertSame(['summer' => true, 'ice' => true], carSeasons('summer', false, true));
    }

    public function testGarageCarSeasonsReadsActivityFromDeclarationsSheetsAndTags(): void
    {
        $ice = ['disciplines' => 'ice'];
        $this->assertSame(['summer' => false, 'ice' => true], garageCarSeasons($ice, [], [], false, false));
        $this->assertTrue(garageCarSeasons($ice, [$this->decl(1, 'submitted', 'GT3')], [], false, false)['summer']);
        $this->assertTrue(garageCarSeasons($ice, [], [$this->sheet(5, 10)], false, false)['summer']);
        $this->assertTrue(garageCarSeasons($ice, [], [], true, false)['summer']);
        $this->assertTrue(garageCarSeasons(['disciplines' => 'summer'], [], [], false, true)['ice']);
        $this->assertSame(['summer' => true, 'ice' => false], garageCarSeasons([], [], [], false, false));
    }

    public function testIceSummaryShowsForAnIceCarWithNoActivityYet(): void
    {
        $this->assertNull(garageIceSummary([], false, 2027));
        $this->assertNull(garageIceSummary([], false, 2027, 'summer'));
        $this->assertSame('Needs ice tech', garageIceSummary([], false, 2027, 'ice')['label']);
        $this->assertSame('Needs ice tech', garageIceSummary([], false, 2027, 'both')['label']);
    }

    public function testUserHasIceActivityWhenACarIsStoredAsIce(): void
    {
        $this->assertFalse(userHasIceActivity([], false, [], [], [3 => ['id' => 3, 'disciplines' => null]]));
        $this->assertTrue(userHasIceActivity([], false, [], [], [3 => ['id' => 3, 'disciplines' => 'ice']]));
        $this->assertTrue(userHasIceActivity([], false, [], [], [3 => ['id' => 3, 'disciplines' => 'both']]));
    }

    public function testUserUsesSummerFalseWhenEveryCarIsStoredAsIce(): void
    {
        $cars = [3 => ['id' => 3, 'disciplines' => 'ice']];
        $this->assertFalse(userUsesSummer($cars, [], [], [], [], '2026-09-28'));
        $cars[4] = ['id' => 4, 'disciplines' => null];
        $this->assertTrue(userUsesSummer($cars, [], [], [], [], '2026-09-28'));
    }
```

(`$this->sheet()` already exists in this test class and builds a summer sheet; check its default `discipline` is summer before relying on it.)

- [ ] **Step 2: Run them and check they fail**

Run: `php phpunit.phar --filter GarageLibTest`
Expected: FAIL with "Call to undefined function carSeasons()".

- [ ] **Step 3: Implement in `garage-lib.php`**

Add near the top (after the `require_once` lines):

```php
/** A car's stored season (mobile UX spec 2026-09-28 §A1). Null on cars added before it existed. */
const CAR_DISCIPLINES = ['ice', 'summer', 'both'];

/**
 * Which seasons a car races. A stored season wins, but activity is never hidden: a declaration,
 * summer sheet or summer tag keeps summer on; an ice sheet or ice tag keeps ice on. With nothing
 * stored, today's inference: summer unless the car only has ice activity.
 * @return array{summer: bool, ice: bool}
 */
function carSeasons(?string $stored, bool $summerActivity, bool $iceActivity): array {
    $s = in_array($stored, CAR_DISCIPLINES, true) ? $stored : null;
    if ($s === null) return ['summer' => $summerActivity || !$iceActivity, 'ice' => $iceActivity];
    return ['summer' => $s !== 'ice' || $summerActivity, 'ice' => $s !== 'summer' || $iceActivity];
}

/** carSeasons() for one car, from its declarations, sheets and upcoming event tags. @return array{summer: bool, ice: bool} */
function garageCarSeasons(array $car, array $declarations, array $carSheets, bool $taggedSummer, bool $taggedIce): array {
    $summerSheet = $iceSheet = false;
    foreach ($carSheets as $s) {
        if (techSheetIsIce($s)) $iceSheet = true; else $summerSheet = true;
    }
    return carSeasons(isset($car['disciplines']) ? (string)$car['disciplines'] : null,
        (bool)$declarations || $summerSheet || $taggedSummer, $iceSheet || $taggedIce);
}
```

Replace the body of `garageCarUsesSummer()` (keep its docblock, add the new param):

```php
function garageCarUsesSummer(array $declarations, array $carSheets, bool $taggedToSummer, bool $taggedToIce = false, ?string $stored = null): bool {
    return garageCarSeasons(['disciplines' => $stored], $declarations, $carSheets, $taggedToSummer, $taggedToIce)['summer'];
}
```

In `garageIceSummary()`, add the param and change the early return:

```php
function garageIceSummary(array $carSheets, bool $taggedToIce, int $iceSeason, ?string $stored = null): ?array {
    $ice = array_values(array_filter($carSheets, fn(array $s): bool => techSheetIsIce($s)));
    if (!$ice && !$taggedToIce && !in_array($stored, ['ice', 'both'], true)) return null;
```

In `garageCard()`, pass the stored value and add `seasons`:

```php
    $stored = isset($car['disciplines']) ? (string)$car['disciplines'] : null;
    $seasons = garageCarSeasons($car, $declarations, $carSheets, $taggedSummer, $taggedIce);
    return [
        'car' => $car,
        'class' => garageClassLine($declarations),
        'seasons' => $seasons,
        'usesSummer' => $seasons['summer'],
        'ice' => garageIceSummary($carSheets, $taggedIce, $iceSeason, $stored),
        'techState' => $tech['state'],
        'techLabel' => techCarStatusLabel($tech, $season),
        'next' => $events['tagged'][0] ?? null,
    ];
```

In `userUsesSummer()`, pass each car's stored season into `garageCarUsesSummer()`:

```php
        if (garageCarUsesSummer($decl !== null ? [$decl] : [], $carSheets, isset($tags[$carId]['summer']), isset($tags[$carId]['ice']),
                isset($cars[$carId]['disciplines']) ? (string)$cars[$carId]['disciplines'] : null)) return true;
```

`userHasIceActivity()` gains `array $cars = []` and, before the final `return false;`:

```php
    foreach ($cars as $car) {
        if (in_array($car['disciplines'] ?? null, ['ice', 'both'], true)) return true;
    }
```

Update its docblock to say "…or a car stored as an ice car".

- [ ] **Step 4: Wire the callers**

`index.php`: pass `$in['cars']` as the new last argument to `userHasIceActivity(...)`, and in the `$garage[]` loop use:

```php
    $stored = isset($car['disciplines']) ? (string)$car['disciplines'] : null;
    $garage[] = ['car' => $car, 'declaration' => $decl,
                 'techLabel' => techCarStatusLabel($status, $season), 'techState' => $status['state'],
                 'usesSummer' => garageCarUsesSummer($decl !== null ? [$decl] : [], $carSheets, $taggedSummer, $taggedIce, $stored),
                 'ice' => garageIceSummary($carSheets, $taggedIce, $iceSeason, $stored)];
```

`drivers.php`: move the `$cars = []; foreach (...)` lines above the `$iceActivity =` line and pass `$cars` as the last argument to `userHasIceActivity(...)`.

`garage.php` `garageShowCar()`: replace the `'usesSummer' => …` and `'ice' => …` lines with:

```php
        'seasons' => $seasons,
        'usesSummer' => $seasons['summer'],
        'ice' => garageIceSummary($allSheets, $taggedIce, gearSeasonNow(DISCIPLINE_ICE), isset($car['disciplines']) ? (string)$car['disciplines'] : null),
```

with `$seasons = garageCarSeasons($car, $declarations, $allSheets, $taggedSummer, $taggedIce);` computed just after the `$taggedSummer/$taggedIce` loop.

- [ ] **Step 5: Run the tests**

Run: `php phpunit.phar` — expected all green (existing Garage/Home/Drivers tests prove legacy behaviour is unchanged).

- [ ] **Step 6: Commit**

```bash
git add wcma-calculator/garage-lib.php wcma-calculator/index.php wcma-calculator/garage.php wcma-calculator/drivers.php wcma-calculator/tests/GarageLibTest.php
git commit -m "feat(ux): one rule for which seasons a car races, honouring its stored season"
```

---

### Task 3: Add a car asks where it races

**Files:**
- Modify: `wcma-calculator/cars-lib.php` (`carsValidateDetails()`)
- Modify: `wcma-calculator/garage-page.php` (`garageDetailsFields()`, `renderAddCarHtml()`, `renderGarageCarHtml()` edit form, `renderGarageListHtml()` intro)
- Modify: `wcma-calculator/garage-lib.php` (new `garageAddEvent()`, `garageAfterAdd()`)
- Modify: `wcma-calculator/garage.php` (`garageRenderAdd()`, `add` GET and POST)
- Modify: `wcma-calculator/css/hub.css`
- Test: `tests/CarDetailsTest.php`, `tests/GaragePageTest.php`, `tests/GarageLibTest.php`, `tests/GarageSourceTest.php`

**Interfaces:**
- Consumes: `CAR_DISCIPLINES` (Task 2).
- Produces:
  - `carsValidateDetails(array $post, bool $requireSeason = false): array{ok, error, data}`; `data` gains `'disciplines' => ?string`.
  - `garageSeasonFieldHtml(?string $value, bool $required): string`
  - `renderAddCarHtml(array $vm)`; `$vm` gains `'event' => ?array` (the event the car is being added for).
  - `garageAddEvent(?array $event, string $today): ?array` (the event if it is active and not in the past, else null).
  - `garageAfterAdd(int $carId, string $disciplines, ?array $event): array{url: string, flash: string}`

- [ ] **Step 1: Write the failing tests**

`tests/CarDetailsTest.php`: the existing exact-array assertion on line 18 must include the new key. Change it to:

```php
        $this->assertSame(['car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000', 'colour' => 'Silver', 'engine_cc' => null, 'disciplines' => null], $r['data']);
```

and add:

```php
    public function testSeasonIsRequiredWhenAddingAndOptionalWhenEditing(): void
    {
        $this->assertSame('Choose where this car will race: ice, summer or both.', carsValidateDetails($this->form(), true)['error']);
        $this->assertSame('ice', carsValidateDetails($this->form(['disciplines' => 'ice']), true)['data']['disciplines']);
        $this->assertTrue(carsValidateDetails($this->form())['ok']);
        $this->assertNull(carsValidateDetails($this->form(['disciplines' => '']))['data']['disciplines']);
        $this->assertSame('Choose where this car will race: ice, summer or both.', carsValidateDetails($this->form(['disciplines' => 'rally']))['error']);
    }
```

`tests/GaragePageTest.php` — change the assertion on line 71 to `'Start by adding your car.'`, and add:

```php
    private function addVm(array $o = []): array {
        return array_merge(['csrf' => 'tok', 'values' => [], 'error' => null, 'msrLink' => null, 'event' => null], $o);
    }

    public function testAddCarAsksWhereTheCarRacesFirst(): void
    {
        $html = renderAddCarHtml($this->addVm());
        $this->assertStringContainsString('<legend>Where will this car race? (required)</legend>', $html);
        foreach (['ice' => 'Ice', 'summer' => 'Summer', 'both' => 'Both'] as $v => $label) {
            $this->assertStringContainsString('<input type="radio" name="disciplines" value="' . $v . '" required>', $html);
            $this->assertStringContainsString('<span>' . $label . '</span>', $html);
        }
        $this->assertStringNotContainsString('declare its class with the Class Calculator', $html);
        $this->assertLessThan(strpos($html, 'id="car-car_number"'), strpos($html, 'Where will this car race?'));
    }

    public function testAddCarLabelsYearOptionalAndUsesANumberKeypadForTheCarNumber(): void
    {
        $html = renderAddCarHtml($this->addVm());
        $this->assertStringContainsString('<label for="car-year">Year (optional)</label>', $html);
        $this->assertMatchesRegularExpression('/id="car-car_number"[^>]*inputmode="numeric"/', $html);
    }

    public function testAddCarForAnEventPreselectsItsSeasonAndCarriesTheEvent(): void
    {
        $html = renderAddCarHtml($this->addVm(['values' => ['disciplines' => 'ice'],
            'event' => ['id' => 12, 'name' => 'NASCC Ice Race #1', 'event_date' => '2026-11-12', 'discipline' => 'ice']]));
        $this->assertStringContainsString('<input type="radio" name="disciplines" value="ice" required checked>', $html);
        $this->assertStringContainsString('<input type="hidden" name="event_id" value="12">', $html);
        $this->assertStringContainsString('for NASCC Ice Race #1', $html);
    }

    public function testEditDetailsShowsTheSeasonButDoesNotRequireIt(): void
    {
        $html = renderGarageCarHtml($this->carVm(['car' => $this->car(['disciplines' => 'both'])]));
        $this->assertStringContainsString('<legend>Where will this car race?</legend>', $html);
        $this->assertStringContainsString('<input type="radio" name="disciplines" value="both" checked>', $html);
    }
```

If `GaragePageTest` has no `carVm()` helper, add one next to `card()` that returns every key `renderGarageCarHtml()` reads (copy the keys from an existing car-page test in the file, adding `'seasons' => ['summer' => true, 'ice' => false]`).

`tests/GarageLibTest.php`:

```php
    public function testAddEventIgnoresMissingInactiveAndPastEvents(): void
    {
        $e = ['id' => 12, 'name' => 'NASCC Ice Race #1', 'event_date' => '2026-11-12', 'active' => 1, 'discipline' => 'ice'];
        $this->assertSame($e, garageAddEvent($e, '2026-09-28'));
        $this->assertNull(garageAddEvent(null, '2026-09-28'));
        $this->assertNull(garageAddEvent(['active' => 0] + $e, '2026-09-28'));
        $this->assertNull(garageAddEvent($e, '2026-11-13'));
    }

    public function testAfterAddSendsIceCarsTowardsTheirIceEvent(): void
    {
        $ice = ['id' => 12, 'name' => 'NASCC Ice Race #1', 'discipline' => 'ice'];
        $summer = ['id' => 10, 'name' => 'Fall Sprint', 'discipline' => 'summer'];
        $this->assertSame(['url' => 'tech-sheets.php?action=new-ice&car_id=3&event_id=12',
                           'flash' => 'Car added and going to NASCC Ice Race #1. Next, the ice tech sheet.'], garageAfterAdd(3, 'ice', $ice));
        $this->assertSame(['url' => 'garage.php?car=3', 'flash' => 'Car added. Which ice event is it going to first?'], garageAfterAdd(3, 'ice', null));
        $this->assertSame(['url' => 'garage.php?car=3', 'flash' => 'Car added and going to Fall Sprint. Next, declare its class.'], garageAfterAdd(3, 'summer', $summer));
        $this->assertSame(['url' => 'garage.php?car=3', 'flash' => 'Car added. Declare its class for summer, and pick an ice event below.'], garageAfterAdd(3, 'both', null));
        $this->assertSame(['url' => 'garage.php?car=3', 'flash' => 'Car added. Next, declare its class.'], garageAfterAdd(3, 'summer', null));
    }
```

`tests/GarageSourceTest.php`:

```php
    public function testAddRequiresASeasonAndTagsTheEventItWasAddedFor(): void
    {
        $body = $this->body('garage.php', 'handleGaragePost');
        $this->assertStringContainsString('carsValidateDetails($_POST, true)', $body);
        $this->assertStringContainsString('garageAddEvent(', $body);
        $this->assertStringContainsString('garageAfterAdd(', $body);
    }
```

- [ ] **Step 2: Run them and check they fail**

Run: `php phpunit.phar --filter "CarDetailsTest|GaragePageTest|GarageLibTest|GarageSourceTest"`
Expected: FAIL.

- [ ] **Step 3: Implement validation (`cars-lib.php`)**

Change the signature to `function carsValidateDetails(array $post, bool $requireSeason = false): array {`, update its docblock ("…and where it races: ice, summer or both, required when $requireSeason"), and just before the final `return ['ok' => true, …]` add:

```php
    $season = trim((string)($post['disciplines'] ?? ''));
    $data['disciplines'] = $season === '' ? null : $season;
    if (($season === '' && $requireSeason) || ($season !== '' && !in_array($season, ['ice', 'summer', 'both'], true))) {
        return $fail('Choose where this car will race: ice, summer or both.');
    }
```

Also set `$data['disciplines'] = trim((string)($post['disciplines'] ?? '')) ?: null;` right after the first `foreach` so failed forms keep the choice. (cars-lib.php cannot use `CAR_DISCIPLINES` because it doesn't load garage-lib.php; the literal list is intentional.)

- [ ] **Step 4: Implement the form (`garage-page.php`)**

```php
/** "Where will this car race?" as three large radio cards (mobile UX spec §A2). */
function garageSeasonFieldHtml(?string $value, bool $required): string {
    $out = '<fieldset class="garage-season"><legend>Where will this car race?' . ($required ? ' (required)' : '') . '</legend><div class="garage-season-options">';
    foreach (['ice' => 'Ice', 'summer' => 'Summer', 'both' => 'Both'] as $v => $label) {
        $out .= '<label><input type="radio" name="disciplines" value="' . $v . '"' . ($required ? ' required' : '')
            . ($value === $v ? ' checked' : '') . '><span>' . $label . '</span></label>';
    }
    return $out . '</div></fieldset>';
}
```

In `garageDetailsFields()`: change `'year' => ['Year', false]` to `'year' => ['Year (optional)', false]`, and the inputmode test to `in_array($name, ['car_number', 'year', 'engine_cc'], true)`.

`renderAddCarHtml()` becomes:

```php
function renderAddCarHtml(array $vm): string {
    $event = $vm['event'] ?? null;
    $out = '<h1>Add a car' . ($event !== null ? ' for ' . h((string)$event['name']) : '') . '</h1>';
    if ($vm['error'] !== null) $out .= '<div class="form-messages show error" role="alert">' . h((string)$vm['error']) . '</div>';
    $out .= '<form method="post" action="garage.php" class="hub-card">' . garageCsrfField((string)$vm['csrf'])
        . '<input type="hidden" name="action" value="add">'
        . ($event !== null ? '<input type="hidden" name="event_id" value="' . (int)$event['id'] . '">' : '')
        . garageSeasonFieldHtml($vm['values']['disciplines'] ?? null, true)
        . garageDetailsFields($vm['values'])
        . '<p class="form-hint">Car numbers are reserved on MotorsportReg. The hub records the number you enter.';
    if ($vm['msrLink'] !== null) {
        $out .= ' <a href="' . h((string)$vm['msrLink']['url']) . '" target="_blank" rel="noopener">' . h((string)$vm['msrLink']['label']) . ' &#8599;</a>';
    }
    return $out . '</p><button type="submit" class="hub-btn">Add car</button></form>';
}
```

In `renderGarageCarHtml()`, the edit form: insert `garageSeasonFieldHtml(($form['values'] ?? $car)['disciplines'] ?? null, false)` before `garageDetailsFields(...)`.

In `renderGarageListHtml()`: `'Start by adding your car and declaring its class.'` → `'Start by adding your car.'`.

- [ ] **Step 5: Implement the redirect helpers (`garage-lib.php`)**

```php
/** The event a car is being added for (Home's "Add a car for this event"), or null if it can't be tagged. */
function garageAddEvent(?array $event, string $today): ?array {
    if ($event === null || (int)($event['active'] ?? 0) !== 1 || (string)($event['event_date'] ?? '') < $today) return null;
    return $event;
}

/**
 * Where Add a car goes next (mobile UX spec §A2). $event is the event it was added for and has
 * already been tagged, or null.
 * @return array{url: string, flash: string}
 */
function garageAfterAdd(int $carId, string $disciplines, ?array $event): array {
    $car = 'garage.php?car=' . $carId;
    if ($event !== null && ($event['discipline'] ?? 'summer') === 'ice') {
        return ['url' => 'tech-sheets.php?action=new-ice&car_id=' . $carId . '&event_id=' . (int)$event['id'],
                'flash' => 'Car added and going to ' . $event['name'] . '. Next, the ice tech sheet.'];
    }
    if ($event !== null) return ['url' => $car, 'flash' => 'Car added and going to ' . $event['name'] . '. Next, declare its class.'];
    if ($disciplines === 'ice') return ['url' => $car, 'flash' => 'Car added. Which ice event is it going to first?'];
    if ($disciplines === 'both') return ['url' => $car, 'flash' => 'Car added. Declare its class for summer, and pick an ice event below.'];
    return ['url' => $car, 'flash' => 'Car added. Next, declare its class.'];
}
```

- [ ] **Step 6: Wire `garage.php`**

GET: replace the `action=add` block with:

```php
if (($_GET['action'] ?? '') === 'add') {
    $event = garageAddEvent(isset($_GET['event_id']) ? db_get_event($pdo, (int)$_GET['event_id']) : null, date('Y-m-d'));
    $values = $event !== null ? ['disciplines' => ($event['discipline'] ?? 'summer') === 'ice' ? 'ice' : 'summer'] : [];
    garageRenderAdd($pdo, $values, null, $event);
    exit;
}
```

`garageRenderAdd(PDO $pdo, array $values, ?string $error, ?array $event = null)` passes `'event' => $event` into `renderAddCarHtml()`.

POST `case 'add':`

```php
        case 'add':
            $event = garageAddEvent(isset($_POST['event_id']) ? db_get_event($pdo, (int)$_POST['event_id']) : null, date('Y-m-d'));
            $v = carsValidateDetails($_POST, true);
            if (!$v['ok']) { garageRenderAdd($pdo, $v['data'], $v['error'], $event); return; }
            $id = db_create_car($pdo, $uid, $v['data']);
            if ($event !== null && !eventsTagCar($pdo, $uid, (int)$event['id'], $id)['ok']) $event = null;
            $next = garageAfterAdd($id, (string)$v['data']['disciplines'], $event);
            setFlash($next['flash'], 'success');
            header('Location: ' . $next['url']);
            return;
```

`case 'update-car':` keeps `carsValidateDetails($_POST)` (season optional).

- [ ] **Step 7: CSS (`css/hub.css`)** — append:

```css
/* Add a car: where will this car race? (mobile UX spec §A2) */
.garage-season { border: 0; padding: 0; margin: 0 0 16px; }
.garage-season legend { font-weight: 700; margin-bottom: 8px; padding: 0; }
.garage-season-options { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.garage-season-options label { display: flex; align-items: center; justify-content: center; gap: 8px; min-height: 56px; margin: 0;
  border: 2px solid var(--hub-line); border-radius: var(--hub-radius); background: var(--hub-card); font-weight: 700; cursor: pointer; }
.garage-season-options input { width: 24px; height: 24px; margin: 0; accent-color: var(--hub-red); }
.garage-season-options label:has(input:checked) { border-color: var(--hub-ink); background: var(--hub-paper); }
```

- [ ] **Step 8: Run the tests**

Run: `php phpunit.phar` — expected all green.

- [ ] **Step 9: Commit**

```bash
git add wcma-calculator/cars-lib.php wcma-calculator/garage-page.php wcma-calculator/garage-lib.php wcma-calculator/garage.php wcma-calculator/css/hub.css wcma-calculator/tests/CarDetailsTest.php wcma-calculator/tests/GaragePageTest.php wcma-calculator/tests/GarageLibTest.php wcma-calculator/tests/GarageSourceTest.php
git commit -m "feat(ux): Add a car asks where it races and can add it for an event"
```

---

### Task 4: The car page leads with the next step

**Files:**
- Modify: `wcma-calculator/garage-lib.php` (new `garageEventsForSeasons()`, `garageAfterTagUrl()`)
- Modify: `wcma-calculator/garage-page.php` (`renderGarageCarHtml()`, new `garageNextStepHtml()`)
- Modify: `wcma-calculator/garage.php` (`garageShowCar()`, `case 'tag':`)
- Modify: `wcma-calculator/css/hub.css`
- Test: `tests/GarageLibTest.php`, `tests/GaragePageTest.php`, `tests/GarageSourceTest.php`

**Interfaces:**
- Consumes: `garageCarSeasons()`, vm key `seasons` (Task 2).
- Produces:
  - `garageEventsForSeasons(array $events, array $seasons): array` — keeps events whose discipline the car races.
  - `garageAfterTagUrl(int $carId, array $event, bool $wantsSheet): string`
  - `garageNextStepHtml(array $vm): string` — '' when there is no next step.

- [ ] **Step 1: Write the failing tests**

`tests/GarageLibTest.php`:

```php
    public function testEventsForSeasonsKeepsOnlyTheSeasonsTheCarRaces(): void
    {
        $events = [['id' => 1, 'discipline' => 'summer'], ['id' => 2, 'discipline' => 'ice'], ['id' => 3]];
        $ids = fn(array $s): array => array_map(fn(array $e): int => $e['id'], garageEventsForSeasons($events, $s));
        $this->assertSame([2], $ids(['summer' => false, 'ice' => true]));
        $this->assertSame([1, 3], $ids(['summer' => true, 'ice' => false]));
        $this->assertSame([1, 2, 3], $ids(['summer' => true, 'ice' => true]));
    }

    public function testAfterTagGoesToTheIceSheetOnlyWhenAskedAndTheEventIsIce(): void
    {
        $ice = ['id' => 12, 'discipline' => 'ice'];
        $this->assertSame('tech-sheets.php?action=new-ice&car_id=3&event_id=12', garageAfterTagUrl(3, $ice, true));
        $this->assertSame('garage.php?car=3', garageAfterTagUrl(3, $ice, false));
        $this->assertSame('garage.php?car=3', garageAfterTagUrl(3, ['id' => 10, 'discipline' => 'summer'], true));
    }
```

`tests/GaragePageTest.php` (uses the `carVm()` helper from Task 3):

```php
    private function iceEvent(int $id = 12): array {
        return ['id' => $id, 'name' => 'NASCC Ice Race #1', 'event_date' => '2026-11-12', 'discipline' => 'ice', 'host_club' => 'NASCC'];
    }

    public function testIceCarWithNoEventShowsIceEventButtonsAndNoClassCard(): void
    {
        $html = renderGarageCarHtml($this->carVm([
            'seasons' => ['summer' => false, 'ice' => true], 'usesSummer' => false,
            'class' => ['current' => null, 'earlierAccepted' => null],
            'ice' => ['state' => 'none', 'label' => 'Needs ice tech'],
            'events' => ['tagged' => [], 'untagged' => [$this->iceEvent()], 'earlierSheets' => []],
        ]));
        $this->assertStringContainsString('<h2>Which ice event is this car going to?</h2>', $html);
        $this->assertStringContainsString('<input type="hidden" name="then" value="sheet">', $html);
        $this->assertStringContainsString('NASCC Ice Race #1 · Thu, Nov 12 · NASCC', $html);
        $this->assertStringNotContainsString('No class declared yet', $html);
        $this->assertStringNotContainsString('<h2>Class</h2>', $html);
        $this->assertLessThan(strpos($html, '<h2>Details</h2>'), strpos($html, 'Which ice event is this car going to?'));
    }

    public function testIceCarTaggedWithoutASheetLeadsWithSubmitIceTechSheet(): void
    {
        $html = renderGarageCarHtml($this->carVm([
            'seasons' => ['summer' => false, 'ice' => true], 'usesSummer' => false,
            'events' => ['tagged' => [['event' => $this->iceEvent(), 'sheet' => null, 'gearLinks' => []]], 'untagged' => [], 'earlierSheets' => []],
        ]));
        $this->assertStringContainsString('<h2>Next: your ice tech sheet</h2>', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=new-ice&amp;car_id=3&amp;event_id=12">Submit ice tech sheet</a>', $html);
    }

    public function testArchivedIceCarHasNoNextStep(): void
    {
        $html = renderGarageCarHtml($this->carVm([
            'car' => $this->car(['archived_at' => '2026-09-01 10:00:00']),
            'seasons' => ['summer' => false, 'ice' => true], 'usesSummer' => false,
            'events' => ['tagged' => [], 'untagged' => [$this->iceEvent()], 'earlierSheets' => []],
        ]));
        $this->assertStringNotContainsString('Which ice event is this car going to?', $html);
    }

    public function testTagFormSaysAddThisCarToAnEventUntilItHasOne(): void
    {
        $vm = $this->carVm(['events' => ['tagged' => [], 'untagged' => [['id' => 10, 'name' => 'Fall Sprint', 'event_date' => '2026-10-15']], 'earlierSheets' => []]]);
        $this->assertStringContainsString('>Add this car to an event</label>', renderGarageCarHtml($vm));
        $vm['events']['tagged'] = [['event' => ['id' => 9, 'name' => 'Spring', 'event_date' => '2026-10-01'], 'sheet' => null, 'gearLinks' => []]];
        $this->assertStringContainsString('>Add this car to another event</label>', renderGarageCarHtml($vm));
    }
```

`tests/GarageSourceTest.php`:

```php
    public function testTagCanContinueStraightToTheIceSheet(): void
    {
        $this->assertStringContainsString("garageAfterTagUrl(\$carId, \$event, (\$_POST['then'] ?? '') === 'sheet')", $this->body('garage.php', 'handleGaragePost'));
        $this->assertStringContainsString('garageEventsForSeasons(', $this->body('garage.php', 'garageShowCar'));
    }
```

- [ ] **Step 2: Run them and check they fail**

Run: `php phpunit.phar --filter "GarageLibTest|GaragePageTest|GarageSourceTest"` — expected FAIL.

- [ ] **Step 3: Implement the helpers (`garage-lib.php`)**

```php
/** The events a car can be added to: only the seasons it races (garageCarSeasons()). */
function garageEventsForSeasons(array $events, array $seasons): array {
    return array_values(array_filter($events, fn(array $e): bool =>
        (($e['discipline'] ?? 'summer') === 'ice') ? $seasons['ice'] : $seasons['summer']));
}

/** Where tagging a car goes: straight to the ice sheet when the ice next-step card asked for it. */
function garageAfterTagUrl(int $carId, array $event, bool $wantsSheet): string {
    if ($wantsSheet && ($event['discipline'] ?? 'summer') === 'ice') {
        return 'tech-sheets.php?action=new-ice&car_id=' . $carId . '&event_id=' . (int)$event['id'];
    }
    return 'garage.php?car=' . $carId;
}
```

- [ ] **Step 4: Implement the view (`garage-page.php`)**

```php
/**
 * The ice next-step card under the car header (mobile UX spec §A3): pick an ice event, or submit
 * the ice sheet for the soonest tagged ice event that has none. '' when neither applies.
 */
function garageNextStepHtml(array $vm): string {
    $car = $vm['car'];
    if ($car['archived_at'] !== null || empty($vm['seasons']['ice'])) return '';
    $id = (int)$car['id'];
    $isIce = fn(array $e): bool => ($e['discipline'] ?? 'summer') === 'ice';
    foreach ($vm['events']['tagged'] as $row) {
        if (!$isIce($row['event'])) continue;
        if ($row['sheet'] !== null) return '';
        $e = $row['event'];
        return '<section class="hub-card garage-next"><h2>Next: your ice tech sheet</h2><p>For ' . h((string)$e['name']) . ', '
            . h(date('D, M j', strtotime((string)$e['event_date']))) . '.</p>'
            . '<a class="hub-btn" href="tech-sheets.php?action=new-ice&amp;car_id=' . $id . '&amp;event_id=' . (int)$e['id'] . '">Submit ice tech sheet</a></section>';
    }
    $ice = array_values(array_filter($vm['events']['untagged'], $isIce));
    if (!$ice) return '';
    $out = '<section class="hub-card garage-next"><h2>Which ice event is this car going to?</h2>'
        . '<p>Pick one and we\'ll open its ice tech sheet. ' . h(EVENTS_NOT_REGISTERING) . '</p><div class="garage-choice-list">';
    foreach ($ice as $e) {
        $label = $e['name'] . ' · ' . date('D, M j', strtotime((string)$e['event_date'])) . ' · ' . ($e['host_club'] ?? '');
        $out .= garagePostForm((string)$vm['csrf'], 'tag', $id, $label, 'hub-btn hub-btn--secondary hub-btn--choice', '',
            ['event_id' => (int)$e['id'], 'then' => 'sheet']);
    }
    return $out . '</div></section>';
}
```

In `renderGarageCarHtml()`, after the archived notice (before `// Details`): `$out .= garageNextStepHtml($vm);`

Change the tag-form label to:

```php
            . '<label for="garage-tag-event">' . ($ev['tagged'] ? 'Add this car to another event' : 'Add this car to an event') . '</label><select id="garage-tag-event" name="event_id">';
```

- [ ] **Step 5: Wire `garage.php`**

In `garageShowCar()`, after `$seasons` is computed (Task 2): `$events['untagged'] = garageEventsForSeasons($events['untagged'], $seasons);`

`case 'tag':` becomes:

```php
        case 'tag':
            $eventId = (int)($_POST['event_id'] ?? 0);
            $r = eventsTagCar($pdo, $uid, $eventId, $carId);
            $extra = $r['ok'] ? remindersRecordTagChoice($pdo, $uid, $_POST) : '';
            setFlash($r['ok'] ? 'Added to your events. ' . EVENTS_NOT_REGISTERING . $extra : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            $event = $r['ok'] ? db_get_event($pdo, $eventId) : null;
            header('Location: ' . ($event !== null ? garageAfterTagUrl($carId, $event, ($_POST['then'] ?? '') === 'sheet') : 'garage.php?car=' . $carId));
            return;
```

- [ ] **Step 6: CSS** — append to `css/hub.css`:

```css
/* Car page next step (mobile UX spec §A3) */
.garage-next { border: 2px solid var(--hub-ink); }
.garage-choice-list { display: grid; gap: 10px; }
.garage-choice-list .garage-inline-form { display: block; }
.hub-btn--choice { display: block; width: 100%; min-height: 56px; text-align: left; white-space: normal; }
```

- [ ] **Step 7: Run the tests** — `php phpunit.phar`, expected all green.

- [ ] **Step 8: Commit**

```bash
git add wcma-calculator/garage-lib.php wcma-calculator/garage-page.php wcma-calculator/garage.php wcma-calculator/css/hub.css wcma-calculator/tests/GarageLibTest.php wcma-calculator/tests/GaragePageTest.php wcma-calculator/tests/GarageSourceTest.php
git commit -m "feat(ux): car page leads ice cars to their event and ice tech sheet"
```

---

### Task 5: Home and landing point winter drivers the right way

**Files:**
- Modify: `wcma-calculator/home-page.php` (`homeEventCardHtml()`, `renderHomeHtml()` garage glance text, `renderLandingHtml()`, new `homeCarsForEvent()`, `landingNextIsIce()`)
- Modify: `wcma-calculator/index.php` (landing call)
- Modify: `wcma-calculator/css/hub.css`
- Test: `tests/HomePageTest.php`, `tests/HomeSourceTest.php`

**Interfaces:**
- Produces:
  - `homeCarsForEvent(array $cars, array $event): array` — cars (keyed by id) whose stored season allows the event; null/`both` allow all.
  - `landingNextIsIce(array $activeEvents, string $today): bool`
  - `renderLandingHtml(array $seasonLinks, bool $iceNext = false): string`

- [ ] **Step 1: Write the failing tests**

`tests/HomePageTest.php` — change the assertion on line 124 to `'Start by adding your car.'`, then add:

```php
    public function testCarsForEventRespectStoredSeasons(): void
    {
        $cars = [1 => ['id' => 1, 'disciplines' => 'ice'], 2 => ['id' => 2, 'disciplines' => 'summer'], 3 => ['id' => 3, 'disciplines' => null], 4 => ['id' => 4, 'disciplines' => 'both']];
        $this->assertSame([1, 3, 4], array_keys(homeCarsForEvent($cars, ['discipline' => 'ice'])));
        $this->assertSame([2, 3, 4], array_keys(homeCarsForEvent($cars, ['discipline' => 'summer'])));
        $this->assertSame([2, 3, 4], array_keys(homeCarsForEvent($cars, [])));
    }

    public function testEventCardLinksToAddACarWhenNoCarFits(): void
    {
        $summer = ['id' => 10, 'name' => 'Fall Sprint', 'event_date' => '2026-10-15', 'discipline' => 'summer'];
        $none = homeEventCardHtml($summer, null, [], 'tok', false);
        $this->assertStringContainsString('<a class="hub-btn hub-btn--secondary" href="garage.php?action=add&amp;event_id=10">Add a car for this event</a>', $none);
        $iceOnly = homeEventCardHtml($summer, null, [1 => ['id' => 1, 'car_number' => '42', 'make' => 'Honda', 'model' => 'Civic', 'disciplines' => 'ice']], 'tok', false);
        $this->assertStringContainsString('href="garage.php?action=add&amp;event_id=10"', $iceOnly);
        $this->assertStringNotContainsString('name="car_id"', $iceOnly);
    }

    public function testLandingLeadsWithIceWhenTheNextEventIsIce(): void
    {
        $html = renderLandingHtml([], true);
        $this->assertStringContainsString('<p class="hub-hero-tagline">Submit your ice tech sheet and track car and gear tech for the season.</p>', $html);
        $this->assertStringContainsString('<a class="hub-btn" href="auth.php?action=register&amp;redirect=index.php">Create account</a>', $html);
        $this->assertStringNotContainsString('<a class="hub-btn" href="calculator.php">', $html);
        $this->assertStringContainsString('href="calculator.php">Summer class calculator</a>', $html);
    }

    public function testLandingNextIsIceLooksAtTheSoonestUpcomingEvent(): void
    {
        $events = [['event_date' => '2026-09-01', 'discipline' => 'summer'], ['event_date' => '2026-11-12', 'discipline' => 'ice'], ['event_date' => '2026-10-15', 'discipline' => 'summer']];
        $this->assertFalse(landingNextIsIce($events, '2026-09-28'));
        $this->assertTrue(landingNextIsIce($events, '2026-10-16'));
        $this->assertFalse(landingNextIsIce([], '2026-09-28'));
    }
```

`tests/HomeSourceTest.php` (follow the file's existing `src()`/`body()` style):

```php
    public function testLandingIsToldWhetherTheNextEventIsIce(): void
    {
        $this->assertStringContainsString("renderLandingHtml(db_get_season_links(\$pdo, true), landingNextIsIce(db_get_active_events(\$pdo), date('Y-m-d')))", $this->src('index.php'));
    }
```

- [ ] **Step 2: Run them and check they fail** — `php phpunit.phar --filter "HomePageTest|HomeSourceTest"`, expected FAIL.

- [ ] **Step 3: Implement (`home-page.php`)**

```php
/** The cars (keyed by id) that can go to $event: a car stored for the other season can't. */
function homeCarsForEvent(array $cars, array $event): array {
    $ice = ($event['discipline'] ?? 'summer') === 'ice';
    return array_filter($cars, fn(array $c): bool => !in_array($c['disciplines'] ?? null, [$ice ? 'summer' : 'ice'], true));
}

/** Whether the soonest active event on or after $today is an ice event (the landing hero leads with ice). */
function landingNextIsIce(array $activeEvents, string $today): bool {
    $upcoming = array_values(array_filter($activeEvents, fn(array $e): bool => (string)$e['event_date'] >= $today));
    usort($upcoming, fn(array $a, array $b): int => strcmp((string)$a['event_date'], (string)$b['event_date']));
    return $upcoming !== [] && ($upcoming[0]['discipline'] ?? 'summer') === 'ice';
}
```

In `homeEventCardHtml()`, replace the `$notGoing` block with:

```php
    $notGoing = homeCarsForEvent(array_diff_key($cars, $goingCarIds), $event);
    if ($notGoing) {
        $out .= homeRenderTagForm($event, $notGoing, $csrf, $offerReminders);
    } elseif (!$goingCarIds) {
        $out .= '<p><a class="hub-btn hub-btn--secondary" href="garage.php?action=add&amp;event_id=' . (int)$event['id'] . '">Add a car for this event</a></p>';
    }
```

In `renderHomeHtml()`: `'Start by adding your car and declaring its class'` → `'Start by adding your car.'`.

`renderLandingHtml()` becomes:

```php
function renderLandingHtml(array $seasonLinks, bool $iceNext = false): string
{
    $signIn = 'auth.php?action=login&amp;redirect=index.php';
    $register = 'auth.php?action=register&amp;redirect=index.php';
    $out = '<section class="hub-hero"><h1>WCMA Hub</h1>';
    if ($iceNext) {
        $out .= '<p class="hub-hero-tagline">Submit your ice tech sheet and track car and gear tech for the season.</p>'
            . '<div class="hub-hero-actions"><a class="hub-btn" href="' . $register . '">Create account</a>'
            . '<a class="hub-btn hub-btn--secondary" href="' . $signIn . '">Sign in</a></div>'
            . '<p><a href="calculator.php">Summer class calculator</a> · <a href="drivers-public.php">Meet the drivers</a></p></section>';
    } else {
        $out .= '<p class="hub-hero-tagline">Declare your class, submit tech sheets and track car and gear tech for the season.</p>'
            . '<div class="hub-hero-actions"><a class="hub-btn" href="calculator.php">Class Calculator</a>'
            . '<a class="hub-btn hub-btn--secondary" href="' . $signIn . '">Sign in</a>'
            . '<a class="hub-btn hub-btn--secondary" href="' . $register . '">Create account</a>'
            . '</div><p><a href="drivers-public.php">Meet the drivers</a></p></section>';
    }
    if ($seasonLinks) {
        $out .= '<div class="hub-card"><h3>This season on MotorsportReg</h3>';
        foreach ($seasonLinks as $link) {
            $out .= '<div class="hub-line"><span>' . h((string)$link['label']) . '</span>'
                . '<a href="' . h((string)$link['url']) . '" target="_blank" rel="noopener">Open &#8599;</a></div>';
        }
        $out .= '</div>';
    }
    return $out;
}
```

- [ ] **Step 4: Wire `index.php`** — the landing call becomes:

```php
    echo renderLandingHtml(db_get_season_links($pdo, true), landingNextIsIce(db_get_active_events($pdo), date('Y-m-d')));
```

- [ ] **Step 5: CSS** — append to `css/hub.css` (contrast fix for links on the dark hero):

```css
.hub-hero a:not(.hub-btn) { color: #fff; text-decoration: underline; }
```

- [ ] **Step 6: Run the tests** — `php phpunit.phar`, expected all green.

- [ ] **Step 7: Commit**

```bash
git add wcma-calculator/home-page.php wcma-calculator/index.php wcma-calculator/css/hub.css wcma-calculator/tests/HomePageTest.php wcma-calculator/tests/HomeSourceTest.php
git commit -m "feat(ux): Home event cards link to adding a car; landing leads with ice in winter"
```

---

### Task 6: After submitting, say what's next

**Files:**
- Create: `wcma-calculator/tech-sheet-next.php`
- Modify: `wcma-calculator/tech-sheets.php` (`handleView()`, and its `require` list at the top)
- Modify: `wcma-calculator/css/hub.css`
- Test: create `wcma-calculator/tests/TechSheetNextTest.php`; add a source guard to a new `wcma-calculator/tests/TechSheetViewSourceTest.php`

**Interfaces:**
- Consumes: `techCarStatusForSheet()` output (`['state' => …]`), `renderGearChips()` output, `iceClubLabel()`.
- Produces:
  - `techSheetViewTitle(array $sheet, ?array $event): string`
  - `renderTechSheetNextStepsHtml(array $sheet, ?array $event, array $carStatus, string $gearChipsHtml): string`

- [ ] **Step 1: Write the failing tests** — `tests/TechSheetNextTest.php`:

```php
<?php
// wcma-calculator/tests/TechSheetNextTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../ice-rules.php';
require_once __DIR__ . '/../tech-sheet-next.php';

use PHPUnit\Framework\TestCase;

final class TechSheetNextTest extends TestCase
{
    private function sheet(array $o = []): array {
        return array_merge(['id' => 2, 'status' => 'submitted', 'discipline' => 'ice', 'club' => 'NASCC',
                            'car_number' => '42', 'car_make' => 'Honda', 'car_model' => 'Civic'], $o);
    }

    private function event(): array {
        return ['id' => 12, 'name' => 'NASCC Ice Race #1', 'event_date' => '2026-11-12'];
    }

    public function testTitleNamesTheSheetCarAndEvent(): void
    {
        $this->assertSame('Ice tech sheet — #42 Honda Civic — NASCC Ice Race #1', techSheetViewTitle($this->sheet(), $this->event()));
        $this->assertSame('Tech sheet — #42 Honda Civic', techSheetViewTitle($this->sheet(['discipline' => 'summer']), null));
    }

    public function testNextStepsOfferPreTechGearAndRegistration(): void
    {
        $html = renderTechSheetNextStepsHtml($this->sheet(), $this->event(), ['state' => 'none'], '<ul class="gear-chips"></ul>');
        $this->assertStringContainsString('<h2>What\'s next</h2>', $html);
        $this->assertStringContainsString('<a class="hub-btn" href="tech-sheets.php?action=pretech&amp;id=2">Pre-tech with photos</a>', $html);
        $this->assertStringContainsString('<ul class="gear-chips"></ul>', $html);
        $this->assertStringContainsString('Register for NASCC Ice Race #1 with the Northern Alberta Sports Car Club.', $html);
    }

    public function testNoPreTechStepOnceTheCarIsAcceptedOrPhotosAreWithAnInspector(): void
    {
        $this->assertStringNotContainsString('action=pretech', renderTechSheetNextStepsHtml($this->sheet(), $this->event(), ['state' => 'accepted'], ''));
        $pending = renderTechSheetNextStepsHtml($this->sheet(), $this->event(), ['state' => 'pending_review'], '');
        $this->assertStringContainsString('Your photos are with an inspector.', $pending);
        $this->assertStringNotContainsString('Pre-tech with photos</a>', $pending);
        $this->assertStringContainsString('>Retake photos</a>', renderTechSheetNextStepsHtml($this->sheet(), $this->event(), ['state' => 'needs_changes'], ''));
    }

    public function testNoGearStepWithoutGearChipsAndSummerSaysHostClub(): void
    {
        $html = renderTechSheetNextStepsHtml($this->sheet(['discipline' => 'summer', 'club' => null]), $this->event(), ['state' => 'none'], '');
        $this->assertStringNotContainsString('Driver gear', $html);
        $this->assertStringContainsString('Register for NASCC Ice Race #1 with the host club.', $html);
    }
}
```

`tests/TechSheetViewSourceTest.php`:

```php
<?php
// wcma-calculator/tests/TechSheetViewSourceTest.php — tech-sheets.php needs config.php, so guard its source.
use PHPUnit\Framework\TestCase;

final class TechSheetViewSourceTest extends TestCase
{
    private function viewBody(): string {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../tech-sheets.php'));
        $start = strpos($src, 'function handleView(');
        $next = strpos($src, "\nfunction ", $start + 1);
        return substr($src, $start, $next - $start);
    }

    public function testViewUsesTheDescriptiveTitleAndWhatsNext(): void
    {
        $body = $this->viewBody();
        $this->assertStringContainsString('techSheetViewTitle($sheet, $event)', $body);
        $this->assertStringContainsString('renderTechSheetNextStepsHtml(', $body);
        $this->assertStringNotContainsString('Tech Sheet #', $body);
        $this->assertStringNotContainsString('btn-primary">Resend', $body);
    }
}
```

- [ ] **Step 2: Run them and check they fail** — `php phpunit.phar --filter "TechSheetNextTest|TechSheetViewSourceTest"`, expected FAIL.

- [ ] **Step 3: Create `tech-sheet-next.php`**

```php
<?php
// wcma-calculator/tech-sheet-next.php
//
// The submitted tech sheet page's title and "What's next" list (mobile UX spec 2026-09-28 §A5).
// Pure: no DB, no session, no echo. Callers must have loaded view_helpers.php (h()) and
// ice-rules.php (iceClubLabel()).

function techSheetViewTitle(array $sheet, ?array $event): string {
    $ice = ($sheet['discipline'] ?? 'summer') === 'ice';
    $parts = [$ice ? 'Ice tech sheet' : 'Tech sheet',
              '#' . $sheet['car_number'] . ' ' . trim($sheet['car_make'] . ' ' . $sheet['car_model'])];
    if ($event !== null && trim((string)($event['name'] ?? '')) !== '') $parts[] = (string)$event['name'];
    return implode(' — ', $parts);
}

/**
 * @param array  $carStatus     techCarStatusForSheet() output
 * @param string $gearChipsHtml renderGearChips() for the sheet's drivers, '' when there are none
 */
function renderTechSheetNextStepsHtml(array $sheet, ?array $event, array $carStatus, string $gearChipsHtml): string {
    $id = (int)$sheet['id'];
    $steps = [];
    $state = (string)($carStatus['state'] ?? 'none');
    if ($state === 'pending_review') {
        $steps[] = '<strong>Car pre-tech</strong><p>Your photos are with an inspector.</p>';
    } elseif ($state === 'needs_changes') {
        $steps[] = '<strong>Car pre-tech</strong><p>An inspector asked for some photos to be retaken.</p>'
            . '<a class="hub-btn" href="tech-sheets.php?action=pretech&amp;id=' . $id . '">Retake photos</a>';
    } elseif ($state !== 'accepted' && ($sheet['status'] ?? '') === 'submitted') {
        $steps[] = '<strong>Pre-tech with photos (optional)</strong><p>Send photos of the car so an inspector can check it before the event, and skip inspection at the track.</p>'
            . '<a class="hub-btn" href="tech-sheets.php?action=pretech&amp;id=' . $id . '">Pre-tech with photos</a>';
    }
    if ($gearChipsHtml !== '') {
        $steps[] = '<strong>Driver gear</strong><p>Add gear photos, or have it checked at the track.</p>' . $gearChipsHtml;
    }
    $club = ($sheet['discipline'] ?? 'summer') === 'ice' ? iceClubLabel((string)($sheet['club'] ?? '')) : null;
    $eventName = $event !== null ? (string)$event['name'] : 'the event';
    $steps[] = '<strong>Register with the club</strong><p>The hub doesn\'t register you. Register for ' . h($eventName)
        . ' with ' . ($club !== null ? 'the ' . h($club) : 'the host club') . '.</p>';

    $out = '<section class="hub-card sheet-next no-print"><h2>What\'s next</h2><ol class="hub-todo">';
    foreach ($steps as $n => $step) {
        $out .= '<li class="hub-todo-item"><span class="hub-todo-n">' . ($n + 1) . '</span><div class="hub-todo-txt">' . $step . '</div></li>';
    }
    return $out . '</ol></section>';
}
```

- [ ] **Step 4: Update `handleView()` in `tech-sheets.php`**

Add `require_once __DIR__ . '/tech-sheet-next.php';` next to the other requires at the top (and `require_once __DIR__ . '/ice-rules.php';` if not already loaded). In `handleView()`:

- Before the `?>`, add `$title = techSheetViewTitle($sheet, $event);` and `$chips = $gearLinks ? renderGearChips($gearLinks, 'owner', ['sheet_season' => (int)($sheet['season'] ?? 0), 'sheet_id' => (int)$sheet['id']]) : '';`
- `<title>` becomes `<title><?= h($title) ?> — WCMA Hub</title>`; `renderSiteHeader($title, …)`.
- Replace everything from `<div class="detail-card actions no-print">` down to the `<?php endif; ?>` closing the gear block with:

```php
  <p class="no-print">Car status: <span class="hub-status <?= h(homeStatusClass($carStatus['state'])) ?>"><?= h(techCarStatusLabel($carStatus, (int)($sheet['season'] ?? date('Y')), (string)($sheet['discipline'] ?? 'summer'))) ?></span></p>
  <?= renderTechSheetNextStepsHtml($sheet, $event, $carStatus, $chips) ?>
  <div class="sheet-actions no-print">
    <?php if (pretechSheetEditable($sheet)): ?>
    <a href="tech-sheets.php?action=edit&id=<?= (int)$sheet['id'] ?>" class="hub-btn hub-btn--secondary">Edit</a>
    <?php endif; ?>
    <button type="button" class="hub-btn hub-btn--secondary" onclick="window.print()">Print</button>
    <form method="post" action="tech-sheets.php?action=resend" class="garage-inline-form">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= (int)$sheet['id'] ?>">
      <button type="submit" class="hub-btn hub-btn--secondary">Resend email</button>
    </form>
  </div>
```

`homeStatusClass()` lives in `home-page.php`; if `tech-sheets.php` doesn't load it, `require_once __DIR__ . '/home-page.php';` (it is pure, safe to load).

- [ ] **Step 5: CSS** — append to `css/hub.css`:

```css
/* Submitted tech sheet: what's next (mobile UX spec §A5) */
.sheet-next { margin-bottom: 16px; }
.sheet-next .hub-todo-txt p { margin: 4px 0 10px; }
.sheet-actions { display: flex; flex-wrap: wrap; gap: 10px; margin: 0 0 24px; }
```

- [ ] **Step 6: Run the tests** — `php phpunit.phar`, expected all green.

- [ ] **Step 7: Commit**

```bash
git add wcma-calculator/tech-sheet-next.php wcma-calculator/tech-sheets.php wcma-calculator/css/hub.css wcma-calculator/tests/TechSheetNextTest.php wcma-calculator/tests/TechSheetViewSourceTest.php
git commit -m "feat(ux): submitted tech sheet says what's next, with a descriptive title"
```

---

### Task 7: Walk the flow on a phone-sized browser

**Files:** none in the repo (uses `scratch/ux/`, which is git-ignored).

- [ ] **Step 1: Fresh throwaway database and server**

```bash
cd /c/dev/wcmaclasscalc/scratch && rm -f ux/ux.db ux/state.json ux/lasturl.txt
php -d auto_prepend_file=ux/prepend.php ../wcma-calculator/seed-hub-db.php
cd ../wcma-calculator && php -d auto_prepend_file=C:/dev/wcmaclasscalc/scratch/ux/prepend.php -S localhost:8160
```

(Run the server in the background; stop any earlier one on port 8160 first.)

- [ ] **Step 2: Walk the winter flow with `scratch/ux/step.js` at 375px**

Signed out landing (expect ice tagline, Create account primary) → register → Home (event cards show "Add a car for this event") → tap it on the NASCC ice event (expect Add a car for NASCC Ice Race #1 with Ice pre-selected) → add the car (expect to land on the ice tech sheet) → back to the car page (expect no "No class declared yet") → fill and submit the sheet with `scratch/ux/fillice.js` (expect the descriptive title and What's next with 3 steps). Then add a second car with **Ice** and no event and check the "Which ice event is this car going to?" buttons open the ice sheet. Check `overflow` is 0 on every page.

- [ ] **Step 3: Record the results** in the PR description (screenshots from `scratch/ux/`), and fix anything that fails before merging.

---

## Self-review notes

- Spec A1 → Tasks 1–2; A2 → Task 3; A3 → Task 4; A4 → Task 5; A5 → Task 6.
- Drivers page gets the stored season through `userHasIceActivity($cars)` / `userUsesSummer($cars)` (Task 2).
- The Garage list card "Declare class" button already keys off `usesSummer`, which now honours the stored season (Task 2), so ice-only cards stop showing it with no extra change.
