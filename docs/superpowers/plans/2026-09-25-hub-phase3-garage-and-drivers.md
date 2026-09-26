# WCMA Hub Phase 3: Garage and Drivers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the old "My Cars" and "My Drivers" pages with the hub's **Garage** and **Drivers**, and make the tech sheet form start from a car and pick drivers from driver profiles:
- `garage.php`: a card per car, Add a car, an Archived group, and a car page (details, class and history, car tech, events, archive/restore)
- class declarations (view, resend, delete, files) move from `account.php` to `garage.php`; `account.php` only redirects and keeps the calculator's draft endpoints
- `drivers.php`: you first, then co-drivers you manage, each with a licence number and this season's gear status; the manual **Renew** step is gone
- the tech sheet form picks a car (non-archived only), shows its details read-only from the car record, and picks each driver from your driver profiles, with **+ Add a co-driver** inline.

**Architecture:**
- Plain PHP + SQLite, as in Phases 1–2. Every new page renders inside `layout.php` (`renderPageStart()` / `renderPageEnd()`).
- Each page is split the same way as Home: a **pure view-model builder** (`garage-lib.php`, `drivers-lib.php`), a **pure renderer** that returns an HTML string (`garage-page.php`, `drivers-page.php`), and a **thin controller** (`garage.php`, `drivers.php`). The pure parts are unit tested. The controllers need `config.php`, so they get source-level guards like `tests/GearLinksSourceTest.php`.
- `gear.php` keeps the gear pre-tech routes (`start`, `pretech`, `pretech-submit`), because emails already link to them. Its list, add and renew routes redirect to `drivers.php`.

**Tech Stack:** PHP 8.3, SQLite (PDO), PHPUnit 10 (`php phpunit.phar`), vanilla JS, `node --test` for JS unit tests.

**Spec:** `docs/superpowers/specs/2026-09-24-wcma-hub-design.md`. Read §1 (URL map), §2 (identity rules: a car's class, archiving, the self driver profile), §4 (Garage, Drivers, Tech sheet form) and §6 (visual system). The chosen visual identity is `scratch/hub-mockups/b-garage-desktop.png`: its car cards (round plate, year/make/model, colour · engine, class badge, a Class / Car tech / next-event strip, and a dashed "+ Add a car" card) are the model for the Garage list.

## Global Constraints

- All paths below are relative to `wcma-calculator/` unless they start with `docs/`. Run PHPUnit from `wcma-calculator/`: `php phpunit.phar`. Run the JS tests with `node --test tests/js/*.test.js`; the bare directory form fails to resolve on Windows.
- The checkout uses CRLF line endings (`core.autocrlf=true`). Any test that slices PHP source by searching for `"\n}\n"` or `"\nfunction "` must first normalise: `str_replace("\r\n", "\n", ...)`.
- No new dependencies and no build step.
- **Terminology:** use *reviewed*, *accepted* and *pre-teched*. Never use *approved*, *passed* or *safe* in UI or email copy.
- **Not blocking:** a tech sheet can always be submitted, whatever the car tech, gear or declaration review status. (A car still needs *a* declaration, because `tech_sheets.submission_id` is `NOT NULL`; that rule is unchanged.)
- **Archived cars** are hidden from Home and from every picker (tech sheet car picker, event tagging). Their car page still opens and offers **Restore**.
- **Ownership:** every car, driver, declaration and sheet id that arrives in a URL or POST is looked up **scoped to the signed-in user** (`db_get_user_car`, `db_get_user_submission`, `db_get_user_tech_sheet`, or an `owner_user_id` check). A miss is a "not found" flash, never another user's data.
- **Accessibility floor:** 18px base text, 44px tap targets (`.hub-btn`), visible focus rings, and status always shown as a word, never colour alone (`.hub-status` + text).
- **Escape everything** from the DB or the request with `h()` (`view_helpers.php`), including URLs in `href`.
- Tagging an event **does not register** anyone. Wherever tagging appears, show `EVENTS_NOT_REGISTERING` ("This doesn't register you. Register with the host club.").
- POST handlers check `validateCsrfToken($_POST['csrf_token'] ?? '')` and answer a bad token with `http_response_code(403); die('Invalid CSRF token');`. They always redirect (PRG), except when re-rendering a form with a validation error.
- The app isn't live, so schema changes need no migration. **After Task 1, reset your local DB:** `php reset-hub-db.php --confirm && php seed-hub-db.php`.
- Nothing is pushed to GitHub. Work on a branch (e.g. `hub-phase3`) and commit at the end of every task. Every commit message ends with:
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`

## Review Focus

1. **Another user's id** in `garage.php?car=`, `garage.php?declaration=`, `?action=file`, a Drivers POST or a tech sheet's `car_id` → a "not found" flash and a redirect, never their data. Pinned in Task 4 (`GarageSourceTest`), Task 5 (`GarageSourceTest`), Task 6 (`DriversLibTest::testLicenceCannotBeSetOnSomeoneElsesDriver`) and Task 7 (`TechSheetsHandlersTest`).
2. **An archived car** must not appear as a Garage card, in the tech sheet car picker, or in tag forms. Its car page still opens, with **Restore**. Pinned in Task 3 (`GaragePageTest::testArchivedCarsAreListedSeparatelyWithRestore`), Task 4 (`GaragePageTest::testArchivedCarPageOffersRestoreAndNoEventActions`) and Task 7 (`TechSheetCarPickerTest`).
3. **A car with no colour on file** (cars made by the calculator's "New car" step have none) must still be able to submit a tech sheet. The form asks for the colour, puts it on the sheet and saves it to the car. Pinned in Task 7 (`CarSheetSnapshotTest`).
4. **Editing an older sheet whose driver name matches no profile** must keep that name, pre-filled as a new driver, not drop it. Pinned in Task 8 (`TechSheetDriverChoiceTest::testEditingASheetWithAnUnknownNameKeepsItAsANewDriver`).
5. **The calculator's saved drafts** still work after `account.php` stops rendering a page. `js/ui-controller.js` fetches `account.php` to scrape `<meta name="csrf-token">` and calls `account.php?action=draft-*`. `account.php` keeps the draft endpoints, and its redirect target `garage.php` emits the meta tag. Pinned in Task 3 (`GarageSourceTest::testListEmitsTheCsrfMetaTheDraftCodeScrapes`) and Task 5 (`GarageSourceTest::testAccountKeepsDraftEndpointsAndRedirectsEverythingElse`).

---

## File map

| File | Status | Responsibility |
|---|---|---|
| `db.php` | modify | `submissions.accepted_at`; `db_restore_car()` |
| `cars-lib.php` | modify | `carsValidateDetails()` (Add a car / Edit details), `carsSheetSnapshot()` (a sheet's car details); `carsApplySheetDetails()` removed |
| `view_helpers.php` | modify | `seasonLinkMatching()`; `buildCarGroups()` removed |
| `garage-lib.php` | create | Pure: `garageClassLine()`, `garageCarEvents()`, `garageCard()`, `garageTechPhotosAction()` |
| `garage-page.php` | create | Pure HTML: Garage list, Add a car, car page, declaration page, tech sheet car picker |
| `garage.php` | create | Garage controller: GET list / add / car / declaration / file; POST add, update-car, archive, restore, tag, untag, resend-declaration, delete-declaration |
| `account.php` | rewrite | Redirects to the Garage; keeps the draft JSON endpoints |
| `drivers-lib.php` | create | `driversGearLabel()`, `driversGearAction()`, `driversRows()`, `driversAdd()`, `driversSetLicence()` |
| `drivers-page.php` | create | Pure HTML: `renderDriversHtml()` |
| `drivers.php` | create | Drivers controller: GET list; POST add, licence |
| `gear.php`, `gear-page.php`, `gear-lib.php` | modify | List/add/renew redirect to Drivers; `renderGearListPage()` and `gearRenew()` removed; pre-tech page links back to Drivers |
| `gear-chips.php` | modify | "Go to Drivers" link to `drivers.php` |
| `tech-sheets.php` | modify | Car picker; car details read-only from the car; drivers picked from profiles; links point at the Garage |
| `tech-sheet-data.php` | modify | `techSheetDriverName()`, `techSheetApplyDriverChoices()`, `techSheetDriverChoiceFor()` |
| `js/driver-choice.js` | create | Pure option/validation helpers plus the select + "new name" widget |
| `js/tech-sheet-form.js` | modify | Driver rows use `js/driver-choice.js` |
| `layout.php`, `home-page.php`, `profile.php`, `auth.php` | modify | Nav and links point at `garage.php` / `drivers.php`; Home's no-cars state offers **Add a car** |
| `css/hub.css` | modify | Garage grid and card, car page sections, Drivers rows |

---

### Task 1: Car data helpers

**Files:**
- Modify: `db.php` (the `submissions` table in `db_init`; new `db_restore_car()` after `db_archive_car()`)
- Modify: `cars-lib.php` (add `CARS_FIELD_MAX`, `CARS_FIELD_LABELS`, `carsValidateDetails()`)
- Modify: `view_helpers.php` (add `seasonLinkMatching()`)
- Test: `tests/CarDetailsTest.php` (create), `tests/DbCarRestoreTest.php` (create)

**Interfaces:**
- Produces:
  - `db_restore_car(PDO $pdo, int $ownerId, int $id): bool`
  - `submissions.accepted_at DATETIME` (nullable). Phase 4's Accept action sets it. Superseding a declaration overwrites `review_status`, so `accepted_at` is the only lasting record that a declaration was accepted. It is what lets the Garage show "Accepted: GT2" under a newer, unreviewed declaration (spec §2).
  - `CARS_FIELD_MAX` (`array<string,int>`: `car_number` 10, `year` 4, `make` 40, `model` 60, `colour` 30, `engine_cc` 10)
  - `carsValidateDetails(array $post): array{ok: bool, error: ?string, data: array{car_number: string, year: ?string, make: string, model: string, colour: string, engine_cc: ?string}}`
  - `seasonLinkMatching(array $links, string $needle): ?array`: the first link whose label contains `$needle` (case-insensitive).

- [ ] **Step 1: Write the failing tests**

`tests/CarDetailsTest.php`:

```php
<?php
// wcma-calculator/tests/CarDetailsTest.php
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../view_helpers.php';

use PHPUnit\Framework\TestCase;

final class CarDetailsTest extends TestCase
{
    private function form(array $o = []): array {
        return array_merge(['car_number' => ' 42 ', 'year' => '2004', 'make' => 'Honda', 'model' => ' S2000 ', 'colour' => 'Silver', 'engine_cc' => ''], $o);
    }

    public function testValidDetailsAreTrimmedAndBlankOptionalsBecomeNull(): void
    {
        $r = carsValidateDetails($this->form());
        $this->assertTrue($r['ok']);
        $this->assertSame(['car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000', 'colour' => 'Silver', 'engine_cc' => null], $r['data']);
        $this->assertNull(carsValidateDetails($this->form(['year' => '']))['data']['year']);
    }

    public function testRequiredFieldsAreNamedInTheError(): void
    {
        $this->assertSame("Enter the car's number.", carsValidateDetails($this->form(['car_number' => '  ']))['error']);
        $this->assertSame("Enter the car's make.", carsValidateDetails($this->form(['make' => '']))['error']);
        $this->assertSame("Enter the car's model.", carsValidateDetails($this->form(['model' => '']))['error']);
        $this->assertSame("Enter the car's colour.", carsValidateDetails($this->form(['colour' => '']))['error']);
    }

    public function testLengthAndYearRules(): void
    {
        $this->assertSame('That number is too long (10 characters at most).', carsValidateDetails($this->form(['car_number' => '12345678901']))['error']);
        $this->assertSame('Enter the year as four digits, like 2004.', carsValidateDetails($this->form(['year' => '04']))['error']);
        $this->assertFalse(carsValidateDetails($this->form(['year' => 'abcd']))['ok']);
        $this->assertTrue(carsValidateDetails($this->form(['engine_cc' => '1997']))['ok']);
    }

    public function testAFailedValidationKeepsWhatWasTyped(): void
    {
        $r = carsValidateDetails($this->form(['colour' => '']));
        $this->assertSame('Honda', $r['data']['make']);
    }

    public function testSeasonLinkMatchingIsCaseInsensitiveAndNullWhenAbsent(): void
    {
        $links = [['label' => '2026 Race Licences', 'url' => 'a'], ['label' => 'Car Classing & Number Reservation', 'url' => 'b']];
        $this->assertSame('b', seasonLinkMatching($links, 'classing')['url']);
        $this->assertSame('a', seasonLinkMatching($links, 'Licen')['url']);
        $this->assertNull(seasonLinkMatching($links, 'Waiver'));
    }
}
```

`tests/DbCarRestoreTest.php`:

```php
<?php
// wcma-calculator/tests/DbCarRestoreTest.php
use PHPUnit\Framework\TestCase;

final class DbCarRestoreTest extends TestCase
{
    private function user(PDO $pdo, string $email): int {
        return db_create_user($pdo, ['email' => $email, 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
    }

    public function testRestoreBringsBackOnlyTheOwnersArchivedCar(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'a@example.com');
        $other = $this->user($pdo, 'b@example.com');
        $id = db_create_car($pdo, $u, ['car_number' => '42', 'make' => 'Honda', 'model' => 'S2000']);

        $this->assertFalse(db_restore_car($pdo, $u, $id));          // not archived
        $this->assertTrue(db_archive_car($pdo, $u, $id));
        $this->assertFalse(db_restore_car($pdo, $other, $id));      // not theirs
        $this->assertTrue(db_restore_car($pdo, $u, $id));
        $this->assertNull(db_get_car($pdo, $id)['archived_at']);
        $this->assertCount(1, db_get_user_cars($pdo, $u));
    }

    public function testSubmissionsHaveAnAcceptedAtColumn(): void
    {
        $pdo = make_temp_pdo();
        $cols = array_column($pdo->query("PRAGMA table_info(submissions)")->fetchAll(), 'name');
        $this->assertContains('accepted_at', $cols);
    }
}
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php phpunit.phar --filter "CarDetailsTest|DbCarRestoreTest"`
Expected: errors such as `Call to undefined function carsValidateDetails()` and `db_restore_car()`, and the missing `accepted_at` column.

- [ ] **Step 3: Implement**

`db.php`: in the `submissions` `CREATE TABLE`, add a line after `reviewed_at             DATETIME,`:

```sql
            accepted_at             DATETIME,
```

`db.php`: after `db_archive_car()`:

```php
/** Restores one of the owner's archived cars. False if it is not theirs or is not archived. */
function db_restore_car(PDO $pdo, int $ownerId, int $id): bool {
    $stmt = $pdo->prepare("UPDATE cars SET archived_at = NULL, updated_at = :now WHERE id = :id AND owner_user_id = :o AND archived_at IS NOT NULL");
    $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id, ':o' => $ownerId]);
    return $stmt->rowCount() === 1;
}
```

`cars-lib.php`: after `carsResolveForDeclaration()`:

```php
const CARS_FIELD_MAX = ['car_number' => 10, 'year' => 4, 'make' => 40, 'model' => 60, 'colour' => 30, 'engine_cc' => 10];
const CARS_FIELD_LABELS = ['car_number' => 'number', 'year' => 'year', 'make' => 'make', 'model' => 'model', 'colour' => 'colour', 'engine_cc' => 'engine size'];

/**
 * The Add a car / Edit details form. Number, make, model and colour are required; year (four
 * digits) and engine size are optional and become null when blank. On failure, data still holds
 * what was typed so the form can be shown again.
 *
 * @return array{ok: bool, error: ?string, data: array<string, ?string>}
 */
function carsValidateDetails(array $post): array {
    $data = [];
    foreach (array_keys(CARS_FIELD_MAX) as $field) {
        $data[$field] = trim((string)preg_replace('/\s+/', ' ', (string)($post[$field] ?? '')));
    }
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'data' => $data];
    foreach (['car_number', 'make', 'model', 'colour'] as $field) {
        if ($data[$field] === '') return $fail("Enter the car's " . CARS_FIELD_LABELS[$field] . '.');
    }
    foreach (CARS_FIELD_MAX as $field => $max) {
        if (mb_strlen($data[$field], 'UTF-8') > $max) return $fail('That ' . CARS_FIELD_LABELS[$field] . " is too long ($max characters at most).");
    }
    if ($data['year'] !== '' && !preg_match('/^(19|20)\d{2}$/', $data['year'])) return $fail('Enter the year as four digits, like 2004.');
    $data['year'] = $data['year'] === '' ? null : $data['year'];
    $data['engine_cc'] = $data['engine_cc'] === '' ? null : $data['engine_cc'];
    return ['ok' => true, 'error' => null, 'data' => $data];
}
```

`view_helpers.php`: after `h()`:

```php
/** The first season link whose label contains $needle (case-insensitive), or null. Links are admin data, not code. */
function seasonLinkMatching(array $links, string $needle): ?array {
    foreach ($links as $link) {
        if (stripos((string)$link['label'], $needle) !== false) return $link;
    }
    return null;
}
```

- [ ] **Step 4: Run the tests**

Run: `php phpunit.phar` → OK. Then reset your local DB (see Global Constraints).

- [ ] **Step 5: Commit**

```bash
git add db.php cars-lib.php view_helpers.php tests/CarDetailsTest.php tests/DbCarRestoreTest.php
git commit -m "feat(hub): car detail validation, restore, and accepted_at on declarations"
```

---

### Task 2: Garage view models

**Files:**
- Create: `garage-lib.php`
- Test: `tests/GarageLibTest.php`

**Interfaces:**
- Consumes: `techCarStatus()`, `techCarStatusLabel()` (`tech-status.php`).
- Produces (all pure; `$today` is `'Y-m-d'`; events are `events` rows with `id`, `name`, `event_date`):
  - `garageClassLine(array $declarations): array{current: ?array, earlierAccepted: ?array}`. `$declarations` are one car's `submissions` rows, newest first (the `db_get_car_declarations()` order).
  - `garageCarEvents(array $carSheets, array $taggedEventIds, array $activeEvents, array $eventNames, string $today): array{tagged: list<array{event: array, sheet: ?array}>, untagged: list<array>, earlierSheets: list<array{sheet: array, event_name: string}>}`
  - `garageCard(array $car, array $declarations, array $carSheets, array $taggedEventIds, array $activeEvents, int $season, string $today): array{car: array, class: array, techState: string, techLabel: string, next: ?array{event: array, sheet: ?array}}`
  - `garageTechPhotosAction(array $seasonSheets, array $status): ?array{label: string, url: string}`. `$status` is `techCarStatus()` output.

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/GarageLibTest.php
require_once __DIR__ . '/../tech-status.php';
require_once __DIR__ . '/../garage-lib.php';

use PHPUnit\Framework\TestCase;

final class GarageLibTest extends TestCase
{
    private function decl(int $id, string $status, string $class, ?string $acceptedAt = null): array {
        return ['id' => $id, 'review_status' => $status, 'calculated_class' => $class, 'accepted_at' => $acceptedAt,
                'submitted_at' => sprintf('2026-%02d-01 10:00:00', $id)];
    }

    private function sheet(int $id, int $eventId, int $season = 2026, array $o = []): array {
        return array_merge(['id' => $id, 'car_id' => 3, 'event_id' => $eventId, 'season' => $season, 'status' => 'submitted',
                            'photo_status' => null, 'accepted_via' => null], $o);
    }

    private function event(int $id, string $name, string $date): array {
        return ['id' => $id, 'name' => $name, 'event_date' => $date, 'active' => 1];
    }

    public function testClassLineIsTheNewestNonSupersededDeclaration(): void
    {
        $line = garageClassLine([$this->decl(3, 'accepted', 'GT3', '2026-03-02 10:00:00'), $this->decl(2, 'superseded', 'GT2', '2026-02-02 10:00:00')]);
        $this->assertSame(3, $line['current']['id']);
        $this->assertNull($line['earlierAccepted']);
    }

    public function testClassLineShowsTheNewestEarlierAcceptedWhenTheCurrentIsNotAccepted(): void
    {
        $line = garageClassLine([
            $this->decl(4, 'submitted', 'GT3'),
            $this->decl(3, 'superseded', 'GT4'),
            $this->decl(2, 'superseded', 'GT2', '2026-02-02 10:00:00'),
            $this->decl(1, 'superseded', 'GT1', '2026-01-02 10:00:00'),
        ]);
        $this->assertSame(4, $line['current']['id']);
        $this->assertSame(2, $line['earlierAccepted']['id']);
    }

    public function testClassLineWithNoDeclarations(): void
    {
        $this->assertSame(['current' => null, 'earlierAccepted' => null], garageClassLine([]));
    }

    public function testCarEventsSplitTaggedUntaggedAndEarlierSheets(): void
    {
        $events = [$this->event(11, 'Season Finale', '2026-10-25'), $this->event(10, 'Fall Sprint', '2026-10-11'), $this->event(12, 'Test Day', '2026-09-01')];
        $sheets = [$this->sheet(7, 10), $this->sheet(4, 99)];
        $ev = garageCarEvents($sheets, [10, 12], $events, [99 => 'Spring Opener', 10 => 'Fall Sprint'], '2026-09-25');

        $this->assertCount(1, $ev['tagged']);                          // 12 is tagged but already past
        $this->assertSame(10, $ev['tagged'][0]['event']['id']);
        $this->assertSame(7, $ev['tagged'][0]['sheet']['id']);
        $this->assertSame([11], array_map(fn($e) => (int)$e['id'], $ev['untagged']));
        $this->assertSame(4, $ev['earlierSheets'][0]['sheet']['id']);
        $this->assertSame('Spring Opener', $ev['earlierSheets'][0]['event_name']);
    }

    public function testTaggedEventsAreSoonestFirst(): void
    {
        $events = [$this->event(11, 'Season Finale', '2026-10-25'), $this->event(10, 'Fall Sprint', '2026-10-11')];
        $ev = garageCarEvents([], [11, 10], $events, [], '2026-09-25');
        $this->assertSame([10, 11], array_map(fn($r) => (int)$r['event']['id'], $ev['tagged']));
        $this->assertNull($ev['tagged'][0]['sheet']);
        $this->assertSame([], $ev['untagged']);
    }

    public function testCardHasThisSeasonsTechStatusAndTheNearestTaggedEvent(): void
    {
        $events = [$this->event(11, 'Season Finale', '2026-10-25'), $this->event(10, 'Fall Sprint', '2026-10-11')];
        $sheets = [$this->sheet(7, 11, 2026, ['status' => 'teched', 'accepted_via' => 'photos']), $this->sheet(5, 99, 2025, ['status' => 'teched'])];
        $card = garageCard(['id' => 3, 'car_number' => '42'], [$this->decl(2, 'submitted', 'GT3')], $sheets, [10, 11], $events, 2026, '2026-09-25');

        $this->assertSame('accepted', $card['techState']);
        $this->assertSame('Pre-teched 2026', $card['techLabel']);
        $this->assertSame(10, $card['next']['event']['id']);
        $this->assertNull($card['next']['sheet']);
        $this->assertSame(2, $card['class']['current']['id']);
    }

    public function testCardWithoutTaggedEventsOrSheetsNeedsTechAtTheTrack(): void
    {
        $card = garageCard(['id' => 3, 'car_number' => '42'], [], [], [], [], 2026, '2026-09-25');
        $this->assertNull($card['next']);
        $this->assertSame('none', $card['techState']);
        $this->assertSame('Needs tech at the track', $card['techLabel']);
    }

    public function testTechPhotosAction(): void
    {
        $none = ['state' => 'none', 'via' => null, 'sheet_id' => null];
        $this->assertNull(garageTechPhotosAction([], $none));
        $this->assertSame(['label' => 'Add photos', 'url' => 'tech-sheets.php?action=pretech&id=9'],
            garageTechPhotosAction([$this->sheet(9, 10), $this->sheet(4, 11)], $none));
        $this->assertSame('Continue photos', garageTechPhotosAction([$this->sheet(9, 10)], ['state' => 'photos_draft', 'via' => null, 'sheet_id' => 9])['label']);
        $this->assertSame(['label' => 'Retake photos', 'url' => 'tech-sheets.php?action=pretech&id=4'],
            garageTechPhotosAction([$this->sheet(9, 10)], ['state' => 'needs_changes', 'via' => null, 'sheet_id' => 4]));
        $this->assertSame('View photos', garageTechPhotosAction([$this->sheet(9, 10)], ['state' => 'pending_review', 'via' => null, 'sheet_id' => 9])['label']);
        $this->assertNull(garageTechPhotosAction([$this->sheet(9, 10)], ['state' => 'accepted', 'via' => 'in_person', 'sheet_id' => 9]));
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php phpunit.phar --filter GarageLibTest`
Expected: fails to open `garage-lib.php`.

- [ ] **Step 3: Implement `garage-lib.php`**

```php
<?php
// wcma-calculator/garage-lib.php
//
// Pure view-model builders for the Garage (spec §4): no DB, no HTML. Callers must have loaded
// tech-status.php (techCarStatus(), techCarStatusLabel()).

/**
 * A car's class (spec §2): its newest non-superseded declaration. When that one isn't accepted,
 * also the newest earlier declaration that was (accepted_at survives superseding).
 *
 * @param array $declarations one car's submissions rows, newest first
 * @return array{current: ?array, earlierAccepted: ?array}
 */
function garageClassLine(array $declarations): array {
    $current = null;
    foreach ($declarations as $d) {
        if ($d['review_status'] !== 'superseded') { $current = $d; break; }
    }
    $earlier = null;
    if ($current === null || $current['review_status'] !== 'accepted') {
        foreach ($declarations as $d) {
            if ($current !== null && (int)$d['id'] === (int)$current['id']) continue;
            if (!empty($d['accepted_at'])) { $earlier = $d; break; }
        }
    }
    return ['current' => $current, 'earlierAccepted' => $earlier];
}

/**
 * The car page's Events section.
 * - tagged: active events on or after $today that this car is tagged for, soonest first, each with
 *   this car's sheet for it (or null).
 * - untagged: active events on or after $today it is not tagged for ("Bring this car to another event").
 * - earlierSheets: this car's sheets for any event not in `tagged`, newest first.
 *
 * @param array $eventNames event id => name, for every event (db_get_all_events())
 */
function garageCarEvents(array $carSheets, array $taggedEventIds, array $activeEvents, array $eventNames, string $today): array {
    $isTagged = array_flip(array_map('intval', $taggedEventIds));
    $upcoming = array_values(array_filter($activeEvents, fn(array $e): bool => (string)$e['event_date'] >= $today));
    usort($upcoming, fn(array $a, array $b): int => strcmp((string)$a['event_date'], (string)$b['event_date']) ?: ((int)$a['id'] <=> (int)$b['id']));

    $sheetByEvent = [];
    foreach ($carSheets as $s) {
        $eid = (int)$s['event_id'];
        if (!isset($sheetByEvent[$eid]) || (int)$s['id'] > (int)$sheetByEvent[$eid]['id']) $sheetByEvent[$eid] = $s;
    }

    $tagged = [];
    $untagged = [];
    foreach ($upcoming as $e) {
        if (isset($isTagged[(int)$e['id']])) {
            $tagged[] = ['event' => $e, 'sheet' => $sheetByEvent[(int)$e['id']] ?? null];
        } else {
            $untagged[] = $e;
        }
    }

    $shown = [];
    foreach ($tagged as $row) {
        if ($row['sheet'] !== null) $shown[(int)$row['sheet']['id']] = true;
    }
    $earlier = [];
    foreach ($carSheets as $s) {
        if (isset($shown[(int)$s['id']])) continue;
        $earlier[] = ['sheet' => $s, 'event_name' => (string)($eventNames[(int)$s['event_id']] ?? 'Event')];
    }
    usort($earlier, fn(array $a, array $b): int => (int)$b['sheet']['id'] <=> (int)$a['sheet']['id']);

    return ['tagged' => $tagged, 'untagged' => $untagged, 'earlierSheets' => $earlier];
}

/** One Garage list card: the car, its class line, this season's car tech, and its nearest tagged event. */
function garageCard(array $car, array $declarations, array $carSheets, array $taggedEventIds, array $activeEvents, int $season, string $today): array {
    $seasonSheets = array_values(array_filter($carSheets, fn(array $s): bool => (int)$s['season'] === $season));
    $tech = techCarStatus($seasonSheets);
    $events = garageCarEvents($carSheets, $taggedEventIds, $activeEvents, [], $today);
    return [
        'car' => $car,
        'class' => garageClassLine($declarations),
        'techState' => $tech['state'],
        'techLabel' => techCarStatusLabel($tech, $season),
        'next' => $events['tagged'][0] ?? null,
    ];
}

/**
 * The car tech photo button. Photos hang off a tech sheet (the existing pre-tech flow), so there
 * is nothing to open until the car has a sheet this season.
 *
 * @param array $seasonSheets this car's sheets for the season
 * @param array $status       techCarStatus() of those sheets
 */
function garageTechPhotosAction(array $seasonSheets, array $status): ?array {
    $url = fn(int $id): string => 'tech-sheets.php?action=pretech&id=' . $id;
    switch ($status['state']) {
        case 'accepted':       return null;
        case 'needs_changes':  return ['label' => 'Retake photos', 'url' => $url((int)$status['sheet_id'])];
        case 'pending_review': return ['label' => 'View photos', 'url' => $url((int)$status['sheet_id'])];
    }
    if (!$seasonSheets) return null;
    $latest = max(array_map(fn(array $s): int => (int)$s['id'], $seasonSheets));
    return ['label' => $status['state'] === 'photos_draft' ? 'Continue photos' : 'Add photos', 'url' => $url($latest)];
}
```

- [ ] **Step 4: Run the tests**

Run: `php phpunit.phar --filter GarageLibTest` → OK, then `php phpunit.phar` → OK.

- [ ] **Step 5: Commit**

```bash
git add garage-lib.php tests/GarageLibTest.php
git commit -m "feat(hub): garage view models for class line, events and car tech"
```

---
### Task 3: Garage list and Add a car

**Files:**
- Create: `garage-page.php` (list, Add a car, shared helpers)
- Create: `garage.php` (controller: list, add; the car page and declarations come in Tasks 4–5)
- Modify: `layout.php` (nav: Garage → `garage.php`)
- Modify: `home-page.php` (Garage links and the no-cars state)
- Modify: `css/hub.css` (Garage styles)
- Test: `tests/GaragePageTest.php` (create), `tests/GarageSourceTest.php` (create), `tests/LayoutTest.php`, `tests/HomePageTest.php`

**Interfaces:**
- Consumes: `garageCard()`, `garageClassLine()` (Task 2); `carsValidateDetails()`, `CARS_FIELD_MAX`, `seasonLinkMatching()` (Task 1); `carDisplayName()`, `declarationReviewLabel()` (`cars-lib.php`); `homeStatusClass()` (`home-page.php`).
- Produces (in `garage-page.php`, all pure and returning strings):
  - `garageCsrfField(string $csrf): string`
  - `garagePostForm(string $csrf, string $action, int $carId, string $button, string $btnClass = 'hub-btn hub-btn--link', string $confirm = '', array $extra = []): string`: a POST form to `garage.php` with hidden `action`, `car_id` and `$extra` fields.
  - `garageCarTitle(array $car): string` ("2004 Honda S2000") and `garageCarSub(array $car): string` ("Silver · 1997 cc")
  - `garageClassHtml(array $classLine): string`
  - `garageDetailsFields(array $values): string`: the six car inputs, ids `car-{field}`.
  - `renderGarageListHtml(array $vm): string`, where `$vm = ['cards' => garageCard()[], 'archived' => cars rows, 'csrf' => string]`
  - `renderAddCarHtml(array $vm): string`, where `$vm = ['csrf' => string, 'values' => array, 'error' => ?string, 'msrLink' => ?season_links row]`
- Produces (in `garage.php`): the POST field `action` values `add`, `archive`, `restore` (Task 4 adds more), and the functions `garageShowList(PDO, int $uid)`, `garageRenderAdd(PDO, array $values, ?string $error)`, `handleGaragePost(PDO, int $uid, string $action)`.

- [ ] **Step 1: Write the failing tests**

`tests/GaragePageTest.php`:

```php
<?php
// wcma-calculator/tests/GaragePageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../events-lib.php';
require_once __DIR__ . '/../tech-status.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../gear-chips.php';
require_once __DIR__ . '/../home-page.php';
require_once __DIR__ . '/../garage-lib.php';
require_once __DIR__ . '/../garage-page.php';

use PHPUnit\Framework\TestCase;

final class GaragePageTest extends TestCase
{
    private function car(array $o = []): array {
        return array_merge(['id' => 3, 'car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000',
                            'colour' => 'Silver', 'engine_cc' => '1997', 'archived_at' => null], $o);
    }

    private function decl(array $o = []): array {
        return array_merge(['id' => 8, 'review_status' => 'submitted', 'calculated_class' => 'GT3', 'accepted_at' => null,
                            'submitted_at' => '2026-04-02 10:00:00', 'competition_weight' => 2800, 'declared_hp' => 240, 'reviewer_note' => null], $o);
    }

    private function card(array $o = []): array {
        return array_merge(['car' => $this->car(), 'class' => ['current' => $this->decl(), 'earlierAccepted' => null],
                            'techState' => 'none', 'techLabel' => 'Needs tech at the track',
                            'next' => ['event' => ['id' => 10, 'name' => 'Fall Sprint', 'event_date' => '2026-10-11'], 'sheet' => null]], $o);
    }

    public function testListShowsACardPerCarWithPlateClassTechAndNextEvent(): void
    {
        $html = renderGarageListHtml(['cards' => [$this->card()], 'archived' => [], 'csrf' => 'tok']);
        $this->assertStringContainsString('<h1>Garage</h1>', $html);
        $this->assertStringContainsString('<span class="hub-plate hub-plate--lg">42</span>', $html);
        $this->assertStringContainsString('href="garage.php?car=3">2004 Honda S2000</a>', $html);
        $this->assertStringContainsString('Silver · 1997 cc', $html);
        $this->assertStringContainsString('<span class="hub-class">GT3</span>', $html);
        $this->assertStringContainsString('With an inspector', $html);
        $this->assertStringContainsString('Needs tech at the track', $html);
        $this->assertStringContainsString('<dt>Fall Sprint</dt>', $html);
        $this->assertStringContainsString('No tech sheet', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=new&amp;car_id=3&amp;event_id=10"', $html);
        $this->assertStringContainsString('href="garage.php?action=add">+ Add a car</a>', $html);
    }

    public function testCardShowsAnEarlierAcceptedClassAndAnUndeclaredCarOffersDeclare(): void
    {
        $html = renderGarageListHtml(['cards' => [$this->card(['class' => ['current' => $this->decl(), 'earlierAccepted' => $this->decl(['id' => 5, 'calculated_class' => 'GT2'])]])], 'archived' => [], 'csrf' => 't']);
        $this->assertStringContainsString('Accepted: GT2', $html);

        $html = renderGarageListHtml(['cards' => [$this->card(['class' => ['current' => null, 'earlierAccepted' => null], 'next' => null])], 'archived' => [], 'csrf' => 't']);
        $this->assertStringContainsString('No class declared yet', $html);
        $this->assertStringContainsString('href="calculator.php?car=3">Declare class</a>', $html);
        $this->assertStringContainsString('Not going to any events yet', $html);
    }

    public function testArchivedCarsAreListedSeparatelyWithRestore(): void
    {
        $archived = $this->car(['id' => 4, 'car_number' => '17', 'make' => 'Mazda', 'model' => 'Miata', 'archived_at' => '2026-05-01 10:00:00']);
        $html = renderGarageListHtml(['cards' => [], 'archived' => [$archived], 'csrf' => 'tok']);
        $this->assertStringContainsString('<summary>Archived cars (1)</summary>', $html);
        $this->assertStringNotContainsString('hub-plate--lg">17', $html);
        $this->assertStringContainsString('name="action" value="restore"', $html);
        $this->assertStringContainsString('name="car_id" value="4"', $html);
        $this->assertStringContainsString('Start by adding your car and declaring its class.', $html);
    }

    public function testListEscapesCarFields(): void
    {
        $html = renderGarageListHtml(['cards' => [$this->card(['car' => $this->car(['make' => '<b>Hon"da'])])], 'archived' => [], 'csrf' => 'tok']);
        $this->assertStringContainsString('&lt;b&gt;Hon&quot;da', $html);
        $this->assertStringNotContainsString('<b>Hon', $html);
    }

    public function testAddCarFormHasTheFieldsTheErrorAndTheMsrLink(): void
    {
        $html = renderAddCarHtml(['csrf' => 'tok', 'values' => ['make' => 'Hon<da'], 'error' => "Enter the car's colour.",
                                  'msrLink' => ['label' => 'Car Classing & Number Reservation', 'url' => 'https://msr.test/x?a=1&b=2']]);
        foreach (['car_number', 'year', 'make', 'model', 'colour', 'engine_cc'] as $f) {
            $this->assertStringContainsString('name="' . $f . '"', $html, $f);
        }
        $this->assertStringContainsString('value="Hon&lt;da"', $html);
        $this->assertStringContainsString('Enter the car&#039;s colour.', $html);
        $this->assertStringContainsString('href="https://msr.test/x?a=1&amp;b=2"', $html);
        $this->assertStringContainsString('name="action" value="add"', $html);
        $this->assertStringNotContainsString('target="_blank"', renderAddCarHtml(['csrf' => 't', 'values' => [], 'error' => null, 'msrLink' => null]));
    }
}
```

`tests/GarageSourceTest.php`:

```php
<?php
// wcma-calculator/tests/GarageSourceTest.php
//
// Source-level guards for garage.php and account.php (they need config.php, so they cannot run
// under PHPUnit).
use PHPUnit\Framework\TestCase;

final class GarageSourceTest extends TestCase
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

    public function testGarageNeedsASignedInUserAndChecksCsrfOnEveryPost(): void
    {
        $src = $this->src('garage.php');
        $this->assertStringContainsString("require_role('user')", $src);
        $this->assertMatchesRegularExpression("/REQUEST_METHOD'\] === 'POST'\) \{\s*if \(!validateCsrfToken\(/", $src);
    }

    public function testListEmitsTheCsrfMetaTheDraftCodeScrapes(): void
    {
        $this->assertStringContainsString('<meta name="csrf-token" content="', $this->body('garage.php', 'garageShowList'));
    }

    public function testAddValidatesAndArchiveRestoreAreOwnerScoped(): void
    {
        $post = $this->body('garage.php', 'handleGaragePost');
        $this->assertStringContainsString('carsValidateDetails($_POST)', $post);
        $this->assertStringContainsString('db_archive_car($pdo, $uid, $carId)', $post);
        $this->assertStringContainsString('db_restore_car($pdo, $uid, $carId)', $post);
    }
}
```

In `tests/LayoutTest.php` (`testCurrentSectionIsInertText`), change `'href="account.php"'` to `'href="garage.php"'`.

In `tests/HomePageTest.php` (`testEmptyStates`), replace the two no-cars assertions with:

```php
        $this->assertStringContainsString('Start by adding your car and declaring its class', $noCars);
        $this->assertStringContainsString('href="garage.php?action=add"', $noCars);
```

and add to `testHomeRendersNumberedTodosSecondaryActionAndSections`:

```php
        $this->assertStringContainsString('href="garage.php">Open garage', $html);
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php phpunit.phar --filter "GaragePageTest|GarageSourceTest|LayoutTest|HomePageTest"`
Expected: `garage-page.php` / `garage.php` are missing, and the Layout/Home assertions fail.

- [ ] **Step 3: Implement**

`garage-page.php`:

```php
<?php
// wcma-calculator/garage-page.php
//
// Markup for the Garage (spec §4): the car list, Add a car, the car page and a class declaration.
// Pure view functions: no DB, no session, no echo. Callers must have loaded view_helpers.php (h()),
// cars-lib.php, events-lib.php (EVENTS_NOT_REGISTERING), home-page.php (homeStatusClass()) and
// gear-chips.php (renderGearChips()).

function garageCsrfField(string $csrf): string {
    return '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">';
}

/** A one-button POST form to garage.php. $confirm adds a data-confirm prompt (js/confirm-modal.js). */
function garagePostForm(string $csrf, string $action, int $carId, string $button, string $btnClass = 'hub-btn hub-btn--link', string $confirm = '', array $extra = []): string {
    $out = '<form method="post" action="garage.php" class="garage-inline-form"' . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>'
        . garageCsrfField($csrf)
        . '<input type="hidden" name="action" value="' . h($action) . '">'
        . '<input type="hidden" name="car_id" value="' . $carId . '">';
    foreach ($extra as $name => $value) {
        $out .= '<input type="hidden" name="' . h((string)$name) . '" value="' . h((string)$value) . '">';
    }
    return $out . '<button type="submit" class="' . h($btnClass) . '">' . h($button) . '</button></form>';
}

function garageCarTitle(array $car): string {
    return trim(($car['year'] ?? '') . ' ' . $car['make'] . ' ' . $car['model']);
}

function garageCarSub(array $car): string {
    $parts = [];
    if (trim((string)($car['colour'] ?? '')) !== '') $parts[] = (string)$car['colour'];
    if (trim((string)($car['engine_cc'] ?? '')) !== '') $parts[] = $car['engine_cc'] . ' cc';
    return implode(' · ', $parts);
}

/** Class badge + review status word; "Accepted: X" when an earlier declaration was accepted (spec §2). */
function garageClassHtml(array $line): string {
    $cur = $line['current'];
    if ($cur === null) {
        return '<p class="garage-class"><span class="hub-status hub-status--todo">No class declared yet</span></p>';
    }
    $out = '<p class="garage-class"><span class="hub-class">' . h((string)$cur['calculated_class']) . '</span> '
        . '<span class="hub-status ' . h(homeStatusClass((string)$cur['review_status'])) . '">' . h(declarationReviewLabel((string)$cur['review_status'])) . '</span>';
    if ($line['earlierAccepted'] !== null) {
        $out .= ' <span class="garage-class-earlier">Accepted: ' . h((string)$line['earlierAccepted']['calculated_class']) . '</span>';
    }
    return $out . '</p>';
}

/** The six car inputs shared by Add a car and Edit details. */
function garageDetailsFields(array $values): string {
    $fields = [
        'car_number' => ['Car number', true],
        'year' => ['Year', false],
        'make' => ['Make', true],
        'model' => ['Model', true],
        'colour' => ['Colour', true],
        'engine_cc' => ['Engine size in cc (optional)', false],
    ];
    $out = '<div class="garage-fields">';
    foreach ($fields as $name => [$label, $required]) {
        $out .= '<div><label for="car-' . $name . '">' . h($label) . '</label>'
            . '<input type="text" id="car-' . $name . '" name="' . $name . '" maxlength="' . CARS_FIELD_MAX[$name] . '"'
            . ($required ? ' required' : '') . ($name === 'year' || $name === 'engine_cc' ? ' inputmode="numeric"' : '')
            . ' value="' . h((string)($values[$name] ?? '')) . '"></div>';
    }
    return $out . '</div>';
}

function garageRenderCard(array $card): string {
    $car = $card['car'];
    $id = (int)$car['id'];
    $out = '<article class="hub-card garage-card"><div class="garage-card-head">'
        . '<span class="hub-plate hub-plate--lg">' . h((string)$car['car_number']) . '</span><div>'
        . '<h2><a href="garage.php?car=' . $id . '">' . h(garageCarTitle($car)) . '</a></h2>';
    if (garageCarSub($car) !== '') $out .= '<p class="garage-card-sub">' . h(garageCarSub($car)) . '</p>';
    $out .= garageClassHtml($card['class']) . '</div></div>';

    $out .= '<dl class="garage-card-facts"><div><dt>Car tech</dt><dd><span class="hub-status ' . h(homeStatusClass($card['techState'])) . '">'
        . h($card['techLabel']) . '</span></dd></div>';
    $next = $card['next'];
    if ($next === null) {
        $out .= '<div><dt>Next event</dt><dd>Not going to any events yet</dd></div>';
    } else {
        $out .= '<div><dt>' . h((string)$next['event']['name']) . '</dt><dd>'
            . ($next['sheet'] !== null ? '<span class="hub-status hub-status--ok">Tech sheet in</span>' : '<span class="hub-status hub-status--todo">No tech sheet</span>')
            . '</dd></div>';
    }
    $out .= '</dl><div class="garage-card-actions">';
    if ($card['class']['current'] === null) {
        $out .= '<a class="hub-btn" href="calculator.php?car=' . $id . '">Declare class</a>';
    } elseif ($next !== null && $next['sheet'] === null) {
        $out .= '<a class="hub-btn" href="tech-sheets.php?action=new&amp;car_id=' . $id . '&amp;event_id=' . (int)$next['event']['id'] . '">Submit tech sheet</a>';
    }
    $out .= '<a class="hub-btn hub-btn--secondary" href="garage.php?car=' . $id . '">Open</a></div></article>';
    return $out;
}

function renderGarageListHtml(array $vm): string {
    $out = '<h1>Garage</h1>';
    if (!$vm['cards']) $out .= '<p class="hub-intro">Start by adding your car and declaring its class.</p>';
    $out .= '<div class="garage-grid">';
    foreach ($vm['cards'] as $card) $out .= garageRenderCard($card);
    $out .= '<a class="garage-add" href="garage.php?action=add">+ Add a car</a></div>';

    if ($vm['archived']) {
        $out .= '<details class="hub-card garage-archived"><summary>Archived cars (' . count($vm['archived']) . ')</summary>';
        foreach ($vm['archived'] as $car) {
            $out .= '<div class="hub-line"><span>' . h(carDisplayName($car)) . '</span><span>'
                . '<a href="garage.php?car=' . (int)$car['id'] . '">View</a> '
                . garagePostForm((string)$vm['csrf'], 'restore', (int)$car['id'], 'Restore') . '</span></div>';
        }
        $out .= '</details>';
    }
    return $out;
}

function renderAddCarHtml(array $vm): string {
    $out = '<h1>Add a car</h1>';
    if ($vm['error'] !== null) $out .= '<div class="form-messages show error" role="alert">' . h((string)$vm['error']) . '</div>';
    $out .= '<form method="post" action="garage.php" class="hub-card">' . garageCsrfField((string)$vm['csrf'])
        . '<input type="hidden" name="action" value="add">' . garageDetailsFields($vm['values'])
        . '<p class="form-hint">Car numbers are reserved on MotorsportReg. The hub records the number you enter.';
    if ($vm['msrLink'] !== null) {
        $out .= ' <a href="' . h((string)$vm['msrLink']['url']) . '" target="_blank" rel="noopener">' . h((string)$vm['msrLink']['label']) . ' &#8599;</a>';
    }
    $out .= '</p><button type="submit" class="hub-btn">Add car</button></form>'
        . '<p>Next, you will declare its class with the Class Calculator.</p>';
    return $out;
}
```

`garage.php`:

```php
<?php
// wcma-calculator/garage.php — the competitor's Garage (spec §4): the car list, Add a car, the car
// page, and class declarations. Views live in garage-page.php; view models in garage-lib.php.
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/cars-lib.php';
require __DIR__ . '/events-lib.php';
require __DIR__ . '/gear-lib.php';
require __DIR__ . '/gear-chips.php';
require __DIR__ . '/garage-lib.php';
require __DIR__ . '/home-page.php';
require __DIR__ . '/garage-page.php';

date_default_timezone_set('America/Denver');
$pdo = db_connect();
db_init($pdo);
$user = require_role('user');
$uid = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
    handleGaragePost($pdo, $uid, (string)($_POST['action'] ?? ''));
    exit;
}

if (($_GET['action'] ?? '') === 'add') {
    garageRenderAdd($pdo, [], null);
    exit;
}
garageShowList($pdo, $uid);

function garageShowList(PDO $pdo, int $uid): void {
    $all = db_get_user_cars($pdo, $uid, true);
    $active = array_values(array_filter($all, fn(array $c): bool => $c['archived_at'] === null));
    $archived = array_values(array_filter($all, fn(array $c): bool => $c['archived_at'] !== null));
    $sheets = db_get_user_tech_sheets($pdo, $uid);
    $tagged = [];
    foreach (db_get_user_event_plans($pdo, $uid) as $p) $tagged[(int)$p['car_id']][] = (int)$p['event_id'];
    $events = db_get_active_events($pdo);

    $cards = [];
    foreach ($active as $car) {
        $cid = (int)$car['id'];
        $carSheets = array_values(array_filter($sheets, fn(array $s): bool => (int)$s['car_id'] === $cid));
        $cards[] = garageCard($car, db_get_car_declarations($pdo, $cid), $carSheets, $tagged[$cid] ?? [], $events, gearSeasonNow(), date('Y-m-d'));
    }

    // js/ui-controller.js fetches account.php (which redirects here) and scrapes this meta tag for
    // the calculator's draft actions, so it must be on the list page for every signed-in user.
    $csrf = generateCsrfToken();
    renderPageStart('Garage', 'garage', ['flash' => getFlash(), 'extraHead' => '<meta name="csrf-token" content="' . h($csrf) . '">']);
    echo renderGarageListHtml(['cards' => $cards, 'archived' => $archived, 'csrf' => $csrf]);
    renderPageEnd(['scripts' => '<script src="js/confirm-modal.js"></script>']);
}

function garageRenderAdd(PDO $pdo, array $values, ?string $error): void {
    renderPageStart('Add a car', 'garage', ['subnav' => '<a href="garage.php">&larr; Back to Garage</a>']);
    echo renderAddCarHtml([
        'csrf' => generateCsrfToken(), 'values' => $values, 'error' => $error,
        'msrLink' => seasonLinkMatching(db_get_season_links($pdo, true), 'Classing'),
    ]);
    renderPageEnd();
}

function handleGaragePost(PDO $pdo, int $uid, string $action): void {
    $carId = (int)($_POST['car_id'] ?? 0);
    switch ($action) {
        case 'add':
            $v = carsValidateDetails($_POST);
            if (!$v['ok']) { garageRenderAdd($pdo, $v['data'], $v['error']); return; }
            $id = db_create_car($pdo, $uid, $v['data']);
            setFlash('Car added. Next, declare its class.', 'success');
            header('Location: garage.php?car=' . $id);
            return;
        case 'archive':
            $ok = db_archive_car($pdo, $uid, $carId);
            setFlash($ok ? 'Car archived. Its history is kept.' : 'Car not found.', $ok ? 'success' : 'error');
            header('Location: garage.php');
            return;
        case 'restore':
            $ok = db_restore_car($pdo, $uid, $carId);
            setFlash($ok ? 'Car restored.' : 'Car not found.', $ok ? 'success' : 'error');
            header('Location: garage.php' . ($ok ? '?car=' . $carId : ''));
            return;
    }
    setFlash('Car not found.', 'error');
    header('Location: garage.php');
}
```

`layout.php` (`hubNavItems()`): replace the Garage item with `['key' => 'garage', 'label' => 'Garage', 'href' => 'garage.php'],` and drop its `// garage.php in Phase 3` comment.

`home-page.php` (`renderHomeHtml()`):
- Replace `$out .= '<p>Start by declaring your class</p><a class="hub-btn" href="calculator.php">Declare your class</a>';` with
  `$out .= '<p>Start by adding your car and declaring its class</p><a class="hub-btn" href="garage.php?action=add">Add a car</a>';`
- Replace `href="account.php">Open garage` with `href="garage.php">Open garage`.

`css/hub.css`: add before the `/* Phone */` block:

```css
/* Garage */
.garage-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 18px; margin-bottom: 24px; }
.garage-card { display: flex; flex-direction: column; padding: 0; margin: 0; overflow: hidden; }
.garage-card-head { display: flex; gap: 16px; align-items: flex-start; padding: 18px 20px; }
.garage-card-head h1, .garage-card-head h2 { margin: 0; font-size: 24px; }
.garage-card-head h2 a { color: var(--hub-ink); text-decoration: none; }
.garage-card-sub { margin: 2px 0 8px; color: var(--hub-ink-2); }
.garage-class { margin: 0; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.garage-class-earlier { color: var(--hub-ink-2); font-size: 16px; }
.garage-card-facts { display: grid; grid-template-columns: 1fr 1fr; margin: 0; border-top: 1px solid var(--hub-line); }
.garage-card-facts > div { padding: 10px 20px; border-left: 1px solid var(--hub-line); }
.garage-card-facts > div:first-child { border-left: 0; }
.garage-card-facts dt { font-size: 14px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--hub-ink-2); }
.garage-card-facts dd { margin: 4px 0 0; }
.garage-card-actions { margin-top: auto; display: flex; gap: 10px; flex-wrap: wrap; padding: 14px 20px; border-top: 1px solid var(--hub-line); }
.garage-add { display: grid; place-items: center; min-height: 200px; border: 2px dashed #c9ccd2; border-radius: var(--hub-radius); color: var(--hub-ink-2); font-weight: 700; font-size: 20px; }
.garage-fields { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px 18px; margin-bottom: 12px; }
.garage-fields input { width: 100%; min-height: var(--hub-tap); box-sizing: border-box; }
.garage-inline-form { display: inline; }
.garage-event { display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: center; padding: 12px 0; border-top: 1px solid var(--hub-line); }
.garage-event:first-of-type { border-top: 0; }
.garage-note { background: var(--hub-warn-bg); padding: 10px 14px; border-radius: 8px; }
```

and inside `@media screen and (max-width: 700px)`:

```css
  .garage-grid { grid-template-columns: 1fr; }
  .garage-card-facts { grid-template-columns: 1fr; }
  .garage-card-facts > div { border-left: 0; border-top: 1px solid var(--hub-line); }
  .garage-card-facts > div:first-child { border-top: 0; }
```

- [ ] **Step 4: Run the tests**

Run: `php phpunit.phar` → OK. `php -l garage.php` → no syntax errors.

- [ ] **Step 5: Commit**

```bash
git add garage-page.php garage.php layout.php home-page.php css/hub.css tests/GaragePageTest.php tests/GarageSourceTest.php tests/LayoutTest.php tests/HomePageTest.php
git commit -m "feat(hub): garage list with car cards, add a car, and archived cars"
```

---

### Task 4: The car page

**Files:**
- Modify: `garage-page.php` (add `renderGarageCarHtml()`)
- Modify: `garage.php` (GET `?car=`; POST `update-car`, `tag`, `untag`)
- Test: `tests/GaragePageTest.php`, `tests/GarageSourceTest.php`, `tests/GearCreateAcceptSourceTest.php`

**Interfaces:**
- Consumes: `garageClassLine()`, `garageCarEvents()`, `garageTechPhotosAction()` (Task 2); `garagePostForm()`, `garageDetailsFields()`, `garageClassHtml()`, `garageCarTitle()`, `garageCarSub()` (Task 3); `eventsTagCar()`, `eventsUntagCar()`, `EVENTS_NOT_REGISTERING` (`events-lib.php`); `gearLinksForSheet()` (`gear-lib.php`); `renderGearChips()` (`gear-chips.php`).
- Produces:
  - `renderGarageCarHtml(array $vm): string`, where `$vm` has these keys:
    - `car`: a cars row
    - `class`: `garageClassLine()` output
    - `declarations`: all of the car's declarations, newest first
    - `season`: int
    - `techState`, `techLabel`: strings
    - `techAction`: `?{label, url}`
    - `events`: `garageCarEvents()` output, with `gearLinks` added to each `tagged` row
    - `csrf`: string
    - `detailsForm`: `?{values: array, error: string}`, set only when an edit failed validation
  - `garageShowCar(PDO $pdo, int $uid, int $carId, ?array $detailsForm = null): void` in `garage.php`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/GaragePageTest.php`:

```php
    private function carVm(array $o = []): array {
        return array_merge([
            'car' => $this->car(),
            'class' => ['current' => $this->decl(['reviewer_note' => 'Show the <dyno> sheet']), 'earlierAccepted' => null],
            'declarations' => [$this->decl(['reviewer_note' => 'Show the <dyno> sheet']), $this->decl(['id' => 5, 'review_status' => 'superseded', 'calculated_class' => 'GT2', 'submitted_at' => '2026-02-01 10:00:00'])],
            'season' => 2026, 'techState' => 'none', 'techLabel' => 'Needs tech at the track',
            'techAction' => ['label' => 'Add photos', 'url' => 'tech-sheets.php?action=pretech&id=9'],
            'events' => [
                'tagged' => [
                    ['event' => ['id' => 10, 'name' => 'Fall Sprint', 'event_date' => '2026-10-11'], 'sheet' => null, 'gearLinks' => []],
                    ['event' => ['id' => 11, 'name' => 'Season Finale', 'event_date' => '2026-10-25'], 'sheet' => ['id' => 9, 'season' => 2026], 'gearLinks' => []],
                ],
                'untagged' => [['id' => 12, 'name' => 'Test <Day>', 'event_date' => '2026-11-01']],
                'earlierSheets' => [['sheet' => ['id' => 2], 'event_name' => 'Spring Opener']],
            ],
            'csrf' => 'tok', 'detailsForm' => null,
        ], $o);
    }

    public function testCarPageShowsDetailsFormClassAndHistory(): void
    {
        $html = renderGarageCarHtml($this->carVm());
        $this->assertStringContainsString('<h1>2004 Honda S2000</h1>', $html);
        $this->assertStringContainsString('name="action" value="update-car"', $html);
        $this->assertStringContainsString('id="car-colour" name="colour"', $html);
        $this->assertStringContainsString('value="Silver"', $html);
        $this->assertStringContainsString('Show the &lt;dyno&gt; sheet', $html);
        $this->assertStringContainsString('href="calculator.php?car=3">Re-declare class</a>', $html);
        $this->assertStringContainsString('href="garage.php?declaration=8">View</a>', $html);
        $this->assertStringContainsString('<h3>History</h3>', $html);
        $this->assertStringContainsString('href="garage.php?declaration=5"', $html);
        $this->assertStringContainsString('Replaced by a newer declaration', $html);
    }

    public function testCarPageShowsCarTechWithThePhotoAction(): void
    {
        $html = renderGarageCarHtml($this->carVm());
        $this->assertStringContainsString('<h2>Car tech 2026</h2>', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=pretech&amp;id=9">Add photos</a>', $html);
        $noSheet = renderGarageCarHtml($this->carVm(['techAction' => null]));
        $this->assertStringContainsString('after you submit a tech sheet', $noSheet);
    }

    public function testCarPageEventsHaveSheetStatusUntagAndBringToAnotherEvent(): void
    {
        $html = renderGarageCarHtml($this->carVm());
        $this->assertStringContainsString('href="tech-sheets.php?action=new&amp;car_id=3&amp;event_id=10">Submit tech sheet</a>', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=view&amp;id=9">View</a>', $html);
        $this->assertStringContainsString('name="action" value="untag"', $html);
        $this->assertStringContainsString('name="event_id" value="10"', $html);
        $this->assertStringContainsString('Bring this car to another event', $html);
        $this->assertStringContainsString('<option value="12">Test &lt;Day&gt;', $html);
        $this->assertStringContainsString(EVENTS_NOT_REGISTERING, $html);
        $this->assertStringContainsString('Spring Opener', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=view&amp;id=2"', $html);
    }

    public function testUndeclaredCarPointsToTheCalculatorInsteadOfTheSheetForm(): void
    {
        $html = renderGarageCarHtml($this->carVm(['class' => ['current' => null, 'earlierAccepted' => null], 'declarations' => []]));
        $this->assertStringContainsString('href="calculator.php?car=3">Declare class</a>', $html);
        $this->assertStringContainsString('Declare a class first', $html);
        $this->assertStringNotContainsString('action=new&amp;car_id=3', $html);
    }

    public function testArchiveFormAsksForConfirmation(): void
    {
        $html = renderGarageCarHtml($this->carVm());
        $this->assertStringContainsString('name="action" value="archive"', $html);
        $this->assertStringContainsString('data-confirm="Archive #42 2004 Honda S2000? It will be hidden, and its history is kept."', $html);
    }

    public function testArchivedCarPageOffersRestoreAndNoEventActions(): void
    {
        $html = renderGarageCarHtml($this->carVm(['car' => $this->car(['archived_at' => '2026-05-01 10:00:00'])]));
        $this->assertStringContainsString('This car is archived', $html);
        $this->assertStringContainsString('name="action" value="restore"', $html);
        $this->assertStringNotContainsString('value="tag"', $html);
        $this->assertStringNotContainsString('value="untag"', $html);
        $this->assertStringNotContainsString('value="archive"', $html);
        $this->assertStringNotContainsString('Re-declare class', $html);
    }

    public function testFailedDetailsEditReopensTheFormWithTheErrorAndTypedValues(): void
    {
        $html = renderGarageCarHtml($this->carVm(['detailsForm' => ['values' => ['make' => 'Typed'], 'error' => "Enter the car's colour."]]));
        $this->assertStringContainsString('<details class="garage-edit" open>', $html);
        $this->assertStringContainsString('value="Typed"', $html);
        $this->assertStringContainsString('Enter the car&#039;s colour.', $html);
    }
```

Add to `tests/GarageSourceTest.php`:

```php
    public function testCarPageLoadsOnlyTheUsersOwnCar(): void
    {
        $show = $this->body('garage.php', 'garageShowCar');
        $this->assertStringContainsString('db_get_user_car($pdo, $uid, $carId)', $show);
        $this->assertMatchesRegularExpression("/=== null\) \{ setFlash\('Car not found\.', 'error'\); header\('Location: garage\.php'\); exit; \}/", $show);
        $this->assertStringContainsString('gearLinksForSheet(', $show);
    }

    public function testCarPostsAreOwnerScoped(): void
    {
        $post = $this->body('garage.php', 'handleGaragePost');
        $this->assertStringContainsString("case 'update-car':\n            if (db_get_user_car(\$pdo, \$uid, \$carId) === null) break;", $post);
        $this->assertStringContainsString('eventsTagCar($pdo, $uid,', $post);
        $this->assertStringContainsString('eventsUntagCar($pdo, $uid,', $post);
    }
```

In `tests/GearCreateAcceptSourceTest.php` (`testOwnerChipsGetTheSheetSeason`), replace the two `account.php` lines with:

```php
        $garage = $this->src('garage-page.php');
        $this->assertStringContainsString("renderGearChips(\$row['gearLinks'], 'owner', ['sheet_season' => (int)(\$sheet['season'] ?? 0)])", $garage);
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php phpunit.phar --filter "GaragePageTest|GarageSourceTest|GearCreateAcceptSourceTest"`
Expected: `Call to undefined function renderGarageCarHtml()`, a missing `garageShowCar`, and the chips assertion fails.

- [ ] **Step 3: Implement**

`garage-page.php`: add

```php
function renderGarageCarHtml(array $vm): string {
    $car = $vm['car'];
    $id = (int)$car['id'];
    $csrf = (string)$vm['csrf'];
    $archived = $car['archived_at'] !== null;
    $cur = $vm['class']['current'];

    $out = '<div class="garage-card-head"><span class="hub-plate hub-plate--lg">' . h((string)$car['car_number']) . '</span><div>'
        . '<h1>' . h(garageCarTitle($car)) . '</h1>';
    if (garageCarSub($car) !== '') $out .= '<p class="garage-card-sub">' . h(garageCarSub($car)) . '</p>';
    $out .= '</div></div>';
    if ($archived) {
        $out .= '<div class="form-messages show info">This car is archived. It is hidden from Home and from the tech sheet form. '
            . garagePostForm($csrf, 'restore', $id, 'Restore this car', 'hub-btn') . '</div>';
    }

    // Details
    $form = $vm['detailsForm'];
    $out .= '<section class="hub-card"><h2>Details</h2><details class="garage-edit"' . ($form !== null ? ' open' : '') . '><summary>Edit details</summary>';
    if ($form !== null) $out .= '<div class="form-messages show error" role="alert">' . h((string)$form['error']) . '</div>';
    $out .= '<form method="post" action="garage.php">' . garageCsrfField($csrf)
        . '<input type="hidden" name="action" value="update-car"><input type="hidden" name="car_id" value="' . $id . '">'
        . garageDetailsFields($form['values'] ?? $car) . '<button type="submit" class="hub-btn">Save details</button></form></details></section>';

    // Class
    $out .= '<section class="hub-card"><h2>Class</h2>' . garageClassHtml($vm['class']);
    if ($cur !== null) {
        $out .= '<p>Declared ' . h(date('M j, Y', strtotime((string)$cur['submitted_at']))) . ' · '
            . h((string)$cur['competition_weight']) . ' lbs · ' . h((string)$cur['declared_hp']) . ' HP</p>';
        if (trim((string)($cur['reviewer_note'] ?? '')) !== '') {
            $out .= '<p class="garage-note"><strong>Inspector\'s note:</strong> ' . h((string)$cur['reviewer_note']) . '</p>';
        }
    }
    $out .= '<p class="garage-card-actions">';
    if (!$archived) $out .= '<a class="hub-btn" href="calculator.php?car=' . $id . '">' . ($cur !== null ? 'Re-declare class' : 'Declare class') . '</a>';
    if ($cur !== null) $out .= '<a class="hub-btn hub-btn--secondary" href="garage.php?declaration=' . (int)$cur['id'] . '">View</a>';
    $out .= '</p>';
    $history = array_values(array_filter($vm['declarations'], fn(array $d): bool => $cur === null || (int)$d['id'] !== (int)$cur['id']));
    if ($history) {
        $out .= '<h3>History</h3><table class="data-table"><thead><tr><th>Declared</th><th>Class</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($history as $d) {
            $out .= '<tr><td>' . h(date('M j, Y', strtotime((string)$d['submitted_at']))) . '</td><td>' . h((string)$d['calculated_class'])
                . '</td><td>' . h(declarationReviewLabel((string)$d['review_status'])) . '</td>'
                . '<td><a href="garage.php?declaration=' . (int)$d['id'] . '">View</a></td></tr>';
        }
        $out .= '</tbody></table>';
    }
    $out .= '</section>';

    // Car tech
    $out .= '<section class="hub-card"><h2>Car tech ' . (int)$vm['season'] . '</h2>'
        . '<p><span class="hub-status ' . h(homeStatusClass((string)$vm['techState'])) . '">' . h((string)$vm['techLabel']) . '</span></p>';
    if ($vm['techAction'] !== null) {
        $out .= '<a class="hub-btn hub-btn--secondary" href="' . h($vm['techAction']['url']) . '">' . h($vm['techAction']['label']) . '</a>';
    } elseif ($vm['techState'] !== 'accepted') {
        $out .= '<p class="form-hint">Pre-tech with photos after you submit a tech sheet for an event, or bring the car to tech at the track.</p>';
    }
    $out .= '</section>';

    // Events
    $ev = $vm['events'];
    $out .= '<section class="hub-card"><h2>Events</h2>';
    if (!$ev['tagged']) $out .= '<p>This car isn\'t going to any events yet.</p>';
    foreach ($ev['tagged'] as $row) {
        $e = $row['event'];
        $eid = (int)$e['id'];
        $sheet = $row['sheet'];
        $out .= '<div class="garage-event"><div><strong>' . h((string)$e['name']) . '</strong> ' . h(date('M j', strtotime((string)$e['event_date']))) . '</div>';
        if ($sheet !== null) {
            $out .= '<span class="hub-status hub-status--ok">Tech sheet submitted</span> <a href="tech-sheets.php?action=view&amp;id=' . (int)$sheet['id'] . '">View</a>'
                . renderGearChips($row['gearLinks'], 'owner', ['sheet_season' => (int)($sheet['season'] ?? 0)]);
        } else {
            $out .= '<span class="hub-status hub-status--todo">No tech sheet yet</span> ';
            $out .= $cur !== null
                ? '<a class="hub-btn" href="tech-sheets.php?action=new&amp;car_id=' . $id . '&amp;event_id=' . $eid . '">Submit tech sheet</a>'
                : '<a href="calculator.php?car=' . $id . '">Declare a class first</a>';
        }
        if (!$archived) $out .= garagePostForm($csrf, 'untag', $id, 'Not going anymore', 'hub-btn hub-btn--link', '', ['event_id' => $eid]);
        $out .= '</div>';
    }
    if (!$archived && $ev['untagged']) {
        $out .= '<form method="post" action="garage.php" class="hub-line">' . garageCsrfField($csrf)
            . '<input type="hidden" name="action" value="tag"><input type="hidden" name="car_id" value="' . $id . '">'
            . '<label for="garage-tag-event">Bring this car to another event</label><select id="garage-tag-event" name="event_id">';
        foreach ($ev['untagged'] as $e) {
            $out .= '<option value="' . (int)$e['id'] . '">' . h((string)$e['name']) . ' — ' . h(date('M j', strtotime((string)$e['event_date']))) . '</option>';
        }
        $out .= '</select><button type="submit" class="hub-btn">I\'m going</button></form><p class="form-hint">' . EVENTS_NOT_REGISTERING . '</p>';
    }
    if ($ev['earlierSheets']) {
        $out .= '<h3>Earlier tech sheets</h3><ul>';
        foreach ($ev['earlierSheets'] as $row) {
            $out .= '<li>' . h($row['event_name']) . ' — <a href="tech-sheets.php?action=view&amp;id=' . (int)$row['sheet']['id'] . '">View</a></li>';
        }
        $out .= '</ul>';
    }
    $out .= '</section>';

    if (!$archived) {
        $out .= '<section class="hub-card"><h2>Archive</h2><p>Archiving hides the car from Home and the tech sheet form. Its history is kept.</p>'
            . garagePostForm($csrf, 'archive', $id, 'Archive car', 'hub-btn hub-btn--secondary', 'Archive ' . carDisplayName($car) . '? It will be hidden, and its history is kept.')
            . '</section>';
    }
    return $out;
}
```

`garage.php`: after `garageRenderAdd(...); exit; }` and before `garageShowList($pdo, $uid);`, add:

```php
if (isset($_GET['car'])) {
    garageShowCar($pdo, $uid, (int)$_GET['car']);
    exit;
}
```

Add the function:

```php
function garageShowCar(PDO $pdo, int $uid, int $carId, ?array $detailsForm = null): void {
    $car = db_get_user_car($pdo, $uid, $carId);
    if ($car === null) { setFlash('Car not found.', 'error'); header('Location: garage.php'); exit; }

    $season = gearSeasonNow();
    $sheets = array_values(array_filter(db_get_user_tech_sheets($pdo, $uid), fn(array $s): bool => (int)$s['car_id'] === $carId));
    $tagged = [];
    foreach (db_get_user_event_plans($pdo, $uid) as $p) {
        if ((int)$p['car_id'] === $carId) $tagged[] = (int)$p['event_id'];
    }
    $eventNames = [];
    foreach (db_get_all_events($pdo) as $e) $eventNames[(int)$e['id']] = (string)$e['name'];
    $events = garageCarEvents($sheets, $tagged, db_get_active_events($pdo), $eventNames, date('Y-m-d'));

    $ownerGear = db_get_user_gear_records($pdo, $uid);
    $driversBySheet = db_get_drivers_for_sheets($pdo, array_map(fn(array $s): int => (int)$s['id'], $sheets));
    foreach ($events['tagged'] as $i => $row) {
        $events['tagged'][$i]['gearLinks'] = $row['sheet'] !== null
            ? gearLinksForSheet($row['sheet'], $driversBySheet[(int)$row['sheet']['id']] ?? [], $ownerGear)
            : [];
    }

    $seasonSheets = array_values(array_filter($sheets, fn(array $s): bool => (int)$s['season'] === $season));
    $status = techCarStatus($seasonSheets);
    $declarations = db_get_car_declarations($pdo, $carId);

    renderPageStart(carDisplayName($car), 'garage', ['flash' => getFlash(), 'subnav' => '<a href="garage.php">&larr; Back to Garage</a>']);
    echo renderGarageCarHtml([
        'car' => $car, 'class' => garageClassLine($declarations), 'declarations' => $declarations,
        'season' => $season, 'techState' => $status['state'], 'techLabel' => techCarStatusLabel($status, $season),
        'techAction' => garageTechPhotosAction($seasonSheets, $status),
        'events' => $events, 'csrf' => generateCsrfToken(), 'detailsForm' => $detailsForm,
    ]);
    renderPageEnd(['scripts' => '<script src="js/confirm-modal.js"></script>']);
}
```

`garage.php` (`handleGaragePost()`): add these cases before the closing `}` of the `switch`:

```php
        case 'update-car':
            if (db_get_user_car($pdo, $uid, $carId) === null) break;
            $v = carsValidateDetails($_POST);
            if (!$v['ok']) { garageShowCar($pdo, $uid, $carId, ['values' => $v['data'], 'error' => $v['error']]); return; }
            db_update_car($pdo, $carId, $v['data']);
            setFlash('Car details saved.', 'success');
            header('Location: garage.php?car=' . $carId);
            return;
        case 'tag':
            $r = eventsTagCar($pdo, $uid, (int)($_POST['event_id'] ?? 0), $carId);
            setFlash($r['ok'] ? 'Added to your events. ' . EVENTS_NOT_REGISTERING : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            header('Location: garage.php?car=' . $carId);
            return;
        case 'untag':
            $r = eventsUntagCar($pdo, $uid, (int)($_POST['event_id'] ?? 0), $carId);
            setFlash($r['ok'] ? 'Removed from your events.' : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            header('Location: garage.php?car=' . $carId);
            return;
```

- [ ] **Step 4: Run the tests**

Run: `php phpunit.phar` → OK. `php -l garage.php garage-page.php` → no syntax errors.

- [ ] **Step 5: Commit**

```bash
git add garage-page.php garage.php tests/GaragePageTest.php tests/GarageSourceTest.php tests/GearCreateAcceptSourceTest.php
git commit -m "feat(hub): car page with details, class history, car tech and events"
```

---

### Task 5: Declarations move to the Garage; account.php redirects

**Files:**
- Modify: `garage-page.php` (add `renderDeclarationHtml()`)
- Modify: `garage.php` (GET `?declaration=`, `?action=file`; POST `resend-declaration`, `delete-declaration`; PHPMailer)
- Rewrite: `account.php`
- Modify: `tech-sheets.php` (every `account.php` → `garage.php`; "My Cars" → "Garage")
- Modify: `auth.php` (redirect whitelist)
- Modify: `view_helpers.php` (remove `buildCarGroups()`)
- Test: `tests/GaragePageTest.php`, `tests/GarageSourceTest.php`, `tests/CalculatorPageTest.php`, `tests/GearLinksSourceTest.php`; delete `tests/AccountCarGroupingTest.php`

**Interfaces:**
- Consumes: `db_get_user_submission()`, `db_count_tech_sheets_for_submission()`, `db_delete_submission()`, `db_update_email_sent()` (`db.php`).
- Produces:
  - `renderDeclarationHtml(array $s, string $csrf): string`, where `$s` is a `submissions` row.
  - URLs: `garage.php?declaration={id}` and `garage.php?action=file&id={id}&field={car_image|dyno_chart|dyno_table}`.
  - POST `action` values `resend-declaration` and `delete-declaration`, each with an `id`.
  - `account.php`: `draft-save`, `draft-list`, `draft-load` and `draft-delete` behave exactly as before; `view&id=N` → `garage.php?declaration=N`; everything else → `garage.php`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/GaragePageTest.php`:

```php
    public function testDeclarationPageShowsTheReviewAndFilesAndGuardsDelete(): void
    {
        $s = $this->decl(['id' => 8, 'car_id' => 3, 'year' => '2004', 'make' => 'Honda', 'model' => 'S2<000', 'review_status' => 'needs_changes',
                          'reviewer_note' => 'Add the dyno table', 'car_image_path' => 'uploads/8/car.jpg', 'dyno_chart_path' => null, 'dyno_table_path' => 'uploads/8/t.pdf']);
        $html = renderDeclarationHtml($s, 'tok');
        $this->assertStringContainsString('2004 Honda S2&lt;000', $html);
        $this->assertStringContainsString('Needs changes', $html);
        $this->assertStringContainsString('Add the dyno table', $html);
        $this->assertStringContainsString('src="garage.php?action=file&amp;id=8&amp;field=car_image"', $html);
        $this->assertStringContainsString('href="garage.php?action=file&amp;id=8&amp;field=dyno_table"', $html);
        $this->assertStringNotContainsString('field=dyno_chart', $html);
        $this->assertStringContainsString('name="action" value="resend-declaration"', $html);
        $this->assertStringContainsString('data-confirm="Permanently delete this class declaration and its files?"', $html);
        $this->assertStringContainsString('href="garage.php?car=3"', $html);
    }
```

Add to `tests/GarageSourceTest.php`:

```php
    public function testDeclarationRoutesAreOwnerScoped(): void
    {
        foreach (['garageShowDeclaration', 'garageDeclarationFile', 'garageResendDeclaration', 'garageDeleteDeclaration'] as $fn) {
            $this->assertStringContainsString('db_get_user_submission($pdo, $uid,', $this->body('garage.php', $fn), $fn);
        }
        $this->assertStringContainsString('db_count_tech_sheets_for_submission(', $this->body('garage.php', 'garageDeleteDeclaration'));
    }

    public function testAccountKeepsDraftEndpointsAndRedirectsEverythingElse(): void
    {
        $src = $this->src('account.php');
        foreach (['draft-save', 'draft-list', 'draft-load', 'draft-delete'] as $action) {
            $this->assertStringContainsString("case '$action':", $src, $action);
        }
        $this->assertStringContainsString("header('Location: garage.php?declaration=' . (int)(\$_GET['id'] ?? 0));", $src);
        $this->assertStringContainsString("header('Location: garage.php');", $src);
        $this->assertStringNotContainsString('<!DOCTYPE html>', $src);
    }

    public function testTechSheetsNoLongerSendPeopleToAccountPhp(): void
    {
        $this->assertStringNotContainsString('account.php', $this->src('tech-sheets.php'));
        $this->assertStringNotContainsString('My Cars', $this->src('tech-sheets.php'));
    }
```

In `tests/CalculatorPageTest.php` (`testRedirectWhitelist`), add `'garage.php'`, `'garage.php?car=3'` and `'drivers.php'` to the `$ok` list, and `'garage.php?car=x'` to the `$bad` list.

In `tests/GearLinksSourceTest.php`:
- `testCompetitorPagesRequireTheGearHelpers`: change `['tech-sheets.php', 'account.php']` to `['tech-sheets.php', 'garage.php']`.
- Replace `testMyCarsShowsGearChipsPerSheetLine` with:

```php
    public function testGarageCarPageShowsGearChipsPerTaggedSheet(): void
    {
        $show = $this->body('garage.php', 'garageShowCar');
        $this->assertStringContainsString('db_get_drivers_for_sheets(', $show);
        $this->assertStringContainsString('gearLinksForSheet(', $show);
        $this->assertStringContainsString("renderGearChips(\$row['gearLinks']", $this->src('garage-page.php'));
    }
```

- `testNewCopyAvoidsBannedWording`: change `['tech-sheets.php', 'account.php']` to `['tech-sheets.php', 'garage.php', 'garage-page.php']`.

Delete `tests/AccountCarGroupingTest.php`.

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php phpunit.phar --filter "GaragePageTest|GarageSourceTest|CalculatorPageTest|GearLinksSourceTest"`
Expected: failures for the missing declaration functions, the old `account.php`, the whitelist and `tech-sheets.php` links.

- [ ] **Step 3: Implement**

`garage-page.php`: add

```php
function renderDeclarationHtml(array $s, string $csrf): string {
    $id = (int)$s['id'];
    $vehicle = trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model']);
    $out = '<h1>Class declaration: ' . h($vehicle) . '</h1><div class="hub-grid-2"><section class="hub-card"><h2>Vehicle and class</h2>'
        . '<table class="detail-table">'
        . '<tr><td>Vehicle</td><td>' . h($vehicle) . '</td></tr>'
        . '<tr><td>Weight</td><td>' . h((string)$s['competition_weight']) . ' lbs</td></tr>'
        . '<tr><td>Declared HP</td><td>' . h((string)$s['declared_hp']) . '</td></tr>'
        . '<tr><td>Calculated class</td><td><strong>' . h((string)($s['calculated_class'] ?? '—')) . '</strong></td></tr>'
        . '<tr><td>Submitted</td><td>' . h(date('F j, Y \a\t g:i A', strtotime((string)$s['submitted_at']))) . '</td></tr>'
        . '<tr><td>Review</td><td><span class="hub-status ' . h(homeStatusClass((string)$s['review_status'])) . '">' . h(declarationReviewLabel((string)$s['review_status'])) . '</span></td></tr>'
        . '</table>';
    if (trim((string)($s['reviewer_note'] ?? '')) !== '') {
        $out .= '<p class="garage-note"><strong>Inspector\'s note:</strong> ' . h((string)$s['reviewer_note']) . '</p>';
    }
    $out .= '</section><section class="hub-card"><h2>Actions</h2>'
        . '<form method="post" action="garage.php">' . garageCsrfField($csrf) . '<input type="hidden" name="action" value="resend-declaration">'
        . '<input type="hidden" name="id" value="' . $id . '"><button type="submit" class="hub-btn">Resend confirmation to my email</button></form>'
        . '<form method="post" action="garage.php" data-confirm="Permanently delete this class declaration and its files?">' . garageCsrfField($csrf)
        . '<input type="hidden" name="action" value="delete-declaration"><input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="hub-btn hub-btn--link">Delete this declaration</button></form>'
        . '<h2>Uploaded files</h2>';
    $files = ['car_image' => ['Car image', $s['car_image_path'] ?? null], 'dyno_chart' => ['Dyno chart', $s['dyno_chart_path'] ?? null], 'dyno_table' => ['Dyno table', $s['dyno_table_path'] ?? null]];
    $any = false;
    foreach ($files as $field => [$label, $path]) {
        if (!$path) continue;
        $any = true;
        $url = 'garage.php?action=file&id=' . $id . '&field=' . $field;
        $isImage = in_array(strtolower(pathinfo((string)$path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true);
        $out .= '<p><strong>' . h($label) . '</strong></p>' . ($isImage
            ? '<img src="' . h($url) . '" class="file-thumb" alt="' . h($label) . '">'
            : '<a href="' . h($url) . '" target="_blank">' . h(basename((string)$path)) . '</a>');
    }
    if (!$any) $out .= '<p>No files uploaded.</p>';
    return $out . '</section></div><p><a href="garage.php?car=' . (int)$s['car_id'] . '">&larr; Back to the car</a></p>';
}
```

`garage.php`: under the existing `require` lines, add:

```php
require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
```

In the GET routing, before `if (isset($_GET['car']))`:

```php
if (isset($_GET['declaration'])) {
    garageShowDeclaration($pdo, $uid, (int)$_GET['declaration']);
    exit;
}
if (($_GET['action'] ?? '') === 'file') {
    garageDeclarationFile($pdo, $uid, (int)($_GET['id'] ?? 0), (string)($_GET['field'] ?? ''));
    exit;
}
```

In `handleGaragePost()`, add these cases to the switch:

```php
        case 'resend-declaration':
            garageResendDeclaration($pdo, $uid, (int)($_POST['id'] ?? 0));
            return;
        case 'delete-declaration':
            garageDeleteDeclaration($pdo, $uid, (int)($_POST['id'] ?? 0));
            return;
```

Then move these four functions from `account.php` into `garage.php` and rename them. They keep their logic; only the lookups, redirects and rendering change as shown.

```php
function garageShowDeclaration(PDO $pdo, int $uid, int $id): void {
    $sub = db_get_user_submission($pdo, $uid, $id);
    if (!$sub) { setFlash('Class declaration not found.', 'error'); header('Location: garage.php'); exit; }
    renderPageStart('Class declaration', 'garage', ['flash' => getFlash(), 'subnav' => '<a href="garage.php?car=' . (int)$sub['car_id'] . '">&larr; Back to the car</a>']);
    echo renderDeclarationHtml($sub, generateCsrfToken());
    renderPageEnd(['scripts' => '<script src="js/confirm-modal.js"></script>']);
}

function buildGarageMailer(): PHPMailer {
    // body identical to account.php's buildAccountMailer()
}

function garageResendDeclaration(PDO $pdo, int $uid, int $id): void {
    $sub = db_get_user_submission($pdo, $uid, $id);
    if (!$sub) { setFlash('Class declaration not found.', 'error'); header('Location: garage.php'); exit; }
    // Remaining body identical to account.php's handleAccountResend() from `$attachments = [];` onwards,
    // with buildAccountMailer() → buildGarageMailer() and the final redirect changed to:
    // header('Location: garage.php?declaration=' . $id);
}

function garageDeleteDeclaration(PDO $pdo, int $uid, int $id): void {
    $sub = db_get_user_submission($pdo, $uid, $id);
    if (!$sub) { setFlash('Class declaration not found.', 'error'); header('Location: garage.php'); exit; }
    if (db_count_tech_sheets_for_submission($pdo, $id) > 0) {
        setFlash('This declaration is on a submitted tech sheet, so it cannot be deleted.', 'error');
        header('Location: garage.php?declaration=' . $id);
        exit;
    }
    $upload_dir = __DIR__ . '/uploads/' . $id;
    if (is_dir($upload_dir)) {
        foreach (glob($upload_dir . '/*') as $file) unlink($file);
        rmdir($upload_dir);
    }
    db_delete_submission($pdo, $id);
    setFlash('Class declaration deleted.', 'success');
    header('Location: garage.php?car=' . (int)$sub['car_id']);
    exit;
}

function garageDeclarationFile(PDO $pdo, int $uid, int $id, string $field): void {
    $field_map = ['dyno_chart' => 'dyno_chart_path', 'dyno_table' => 'dyno_table_path', 'car_image' => 'car_image_path'];
    if (!isset($field_map[$field])) { http_response_code(404); exit; }
    $sub = db_get_user_submission($pdo, $uid, $id);
    // Remaining body identical to account.php's handleAccountFile() from `$db_field = $field_map[$field];` onwards.
}
```

Copy the elided bodies **verbatim** from the current `account.php` (read it before you rewrite it). Each function does its own `db_get_user_submission($pdo, $uid, …)` lookup, as shown; the source test checks for it in each one.

Rewrite `account.php`:

```php
<?php
// wcma-calculator/account.php — the old "My Cars" URL. The Garage replaced it (spec §1). This file
// only redirects, and keeps the calculator's draft JSON endpoints: js/ui-controller.js calls
// account.php?action=draft-*, and fetches account.php (→ garage.php) for its csrf-token meta tag.
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/view_helpers.php';

$pdo = db_connect();
db_init($pdo);

$user = current_user();
if ($user === null) {
    header('Location: auth.php?action=login');
    exit;
}

switch ($_GET['action'] ?? 'list') {
    case 'draft-save':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); exit; }
        handleDraftSave($pdo, $user);
        break;

    case 'draft-list':
        handleDraftList($pdo, $user);
        break;

    case 'draft-load':
        handleDraftLoad($pdo, $user, (int)($_GET['id'] ?? 0));
        break;

    case 'draft-delete':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); exit; }
        handleDraftDelete($pdo, $user, (int)($_POST['id'] ?? 0));
        break;

    case 'view':
        header('Location: garage.php?declaration=' . (int)($_GET['id'] ?? 0));
        exit;

    default:
        header('Location: garage.php');
        exit;
}
```

followed by `handleDraftSave()`, `handleDraftList()`, `handleDraftLoad()` and `handleDraftDelete()`, copied verbatim from the old file. Delete everything else in the old file: the page renderers, the mailer, the declaration handlers, `MY_CARS_SOFT_CAP` and the gear/cars requires.

`tech-sheets.php`: replace every `account.php` with `garage.php`, and every `← Back to My Cars` with `← Back to Garage`. In `handleNew()`, `handleSubmit()` and `handleView()`, where the car id is known, prefer `garage.php?car=' . $carId` over the bare `garage.php`. (Task 7 rewrites `handleNew`'s not-found branch anyway.)

`auth.php` (the `safeRedirectTarget` regex): change

```php
    if (preg_match('/^(index\.php|calculator\.php(\?(car|draft)=\d+|\?restore=1)?|profile\.php|account\.php|admin\.php)$/', $raw)) {
```

to

```php
    if (preg_match('/^(index\.php|calculator\.php(\?(car|draft)=\d+|\?restore=1)?|garage\.php(\?car=\d+)?|drivers\.php|profile\.php|account\.php|admin\.php)$/', $raw)) {
```

`view_helpers.php`: delete `buildCarGroups()` and its doc comment (`grep -rn buildCarGroups` must return nothing afterwards).

- [ ] **Step 4: Run the tests**

Run: `php phpunit.phar` → OK. `for f in *.php; do php -l "$f" >/dev/null || echo "LINT FAIL $f"; done` → no output.

- [ ] **Step 5: Commit**

```bash
git add garage-page.php garage.php account.php tech-sheets.php auth.php view_helpers.php tests/
git rm tests/AccountCarGroupingTest.php
git commit -m "feat(hub): class declarations move to the garage; account.php redirects"
```

---
### Task 6: Drivers page

**Files:**
- Create: `drivers-lib.php`, `drivers-page.php`, `drivers.php`
- Modify: `gear.php` (list/add/renew redirect to Drivers; not-found redirects go to Drivers)
- Modify: `gear-page.php` (remove `renderGearListPage()`; pre-tech back link → Drivers)
- Modify: `gear-lib.php` (remove `gearRenew()`)
- Modify: `gear-chips.php` (owner link → `drivers.php`)
- Modify: `layout.php` (nav: Drivers → `drivers.php`), `home-page.php` ("Manage drivers" link), `profile.php` ("Go to drivers" link)
- Modify: `css/hub.css` (Drivers rows)
- Test: `tests/DriversLibTest.php`, `tests/DriversPageTest.php`, `tests/DriversSourceTest.php` (create); `tests/GearPageTest.php`, `tests/GearLibTest.php`, `tests/GearChipsTest.php`, `tests/GearChipsActionsTest.php`, `tests/HomePageTest.php`

**Interfaces:**
- Consumes: `gearStatus()`, `gearStatusLabel()` (`gear-lib.php`); `db_get_user_drivers()`, `db_get_self_driver()`, `db_find_driver()`, `db_create_driver()`, `db_get_driver()`, `db_update_driver_licence()`, `db_get_gear_record_for_driver()` (`db.php`); `seasonLinkMatching()` (Task 1); `homeStatusClass()` (`home-page.php`).
- Produces (`drivers-lib.php`):
  - `driversGearLabel(array $status, int $season): string`: "Needs gear tech {season}" when there is no gear activity, otherwise `gearStatusLabel()`.
  - `driversGearAction(int $driverId, array $status): array{label: string, url: string}`: always `gear.php?action=start&driver_id={id}`, which creates this season's record on first use.
  - `driversRows(array $drivers, array $gear, int $selfId, int $season): list<array{driver: array, isSelf: bool, state: string, label: string, action: array}>`. `$gear` maps driver id → this season's `gear_records` row.
  - `driversAdd(PDO $pdo, int $ownerId, string $name, string $licence): array{ok: bool, error: ?string, id: ?int}`
  - `driversSetLicence(PDO $pdo, int $ownerId, int $driverId, string $licence): array{ok: bool, error: ?string}`
- Produces (`drivers-page.php`): `renderDriversHtml(array $vm): string`, where `$vm = ['rows' => driversRows(), 'season' => int, 'csrf' => string, 'licenceLink' => ?season_links row]`.

- [ ] **Step 1: Write the failing tests**

`tests/DriversLibTest.php`:

```php
<?php
// wcma-calculator/tests/DriversLibTest.php
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../drivers-lib.php';

use PHPUnit\Framework\TestCase;

final class DriversLibTest extends TestCase
{
    private function user(PDO $pdo, string $email = 'j@example.com', string $name = 'Jordan Lee'): int {
        return db_create_user($pdo, ['email' => $email, 'name' => $name, 'password_hash' => 'x', 'google_id' => null]);
    }

    public function testGearLabelSaysNeedsGearTechUntilThereIsActivity(): void
    {
        $this->assertSame('Needs gear tech 2027', driversGearLabel(['state' => 'none', 'via' => null], 2027));
        $this->assertSame('Gear pre-teched 2027', driversGearLabel(['state' => 'accepted', 'via' => 'photos'], 2027));
        $this->assertSame('Photos need changes', driversGearLabel(['state' => 'needs_changes', 'via' => null], 2027));
    }

    public function testGearActionAlwaysOpensThisSeasonsPhotos(): void
    {
        $this->assertSame(['label' => 'Add photos', 'url' => 'gear.php?action=start&driver_id=5'], driversGearAction(5, ['state' => 'none', 'via' => null]));
        $this->assertSame('Continue photos', driversGearAction(5, ['state' => 'photos_draft', 'via' => null])['label']);
        $this->assertSame('Retake photos', driversGearAction(5, ['state' => 'needs_changes', 'via' => null])['label']);
        $this->assertSame('View photos', driversGearAction(5, ['state' => 'pending_review', 'via' => null])['label']);
        $this->assertSame('View gear', driversGearAction(5, ['state' => 'accepted', 'via' => 'in_person'])['label']);
    }

    public function testRowsMarkSelfAndUseTheGivenSeasonsRecord(): void
    {
        $drivers = [['id' => 1, 'name' => 'Jordan Lee'], ['id' => 2, 'name' => 'Sam Patel']];
        $rows = driversRows($drivers, [2 => ['status' => 'accepted', 'accepted_via' => 'photos', 'photo_status' => 'accepted']], 1, 2026);
        $this->assertTrue($rows[0]['isSelf']);
        $this->assertFalse($rows[1]['isSelf']);
        $this->assertSame('Needs gear tech 2026', $rows[0]['label']);
        $this->assertSame('accepted', $rows[1]['state']);
        $this->assertSame('Gear pre-teched 2026', $rows[1]['label']);
    }

    public function testAddCreatesACoDriverAndRejectsBlankLongAndDuplicateNames(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $r = driversAdd($pdo, $u, '  Sam   Patel ', ' L-12 ');
        $this->assertTrue($r['ok']);
        $d = db_get_driver($pdo, $r['id']);
        $this->assertSame(['Sam Patel', 'L-12', null], [$d['name'], $d['licence_no'], $d['user_id']]);

        $this->assertSame('Sam Patel is already on your Drivers page.', driversAdd($pdo, $u, 'sam  patel', '')['error']);
        $this->assertSame("Enter the driver's name.", driversAdd($pdo, $u, '  ', '')['error']);
        $this->assertFalse(driversAdd($pdo, $u, str_repeat('a', 101), '')['ok']);
        $this->assertFalse(driversAdd($pdo, $u, 'Alex Kim', str_repeat('1', 41))['ok']);
        $this->assertFalse(driversAdd($pdo, $u, 'Jordan Lee', '')['ok']);            // the self profile made with the account
    }

    public function testLicenceCanBeSetAndCleared(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $self = db_get_self_driver($pdo, $u);
        $this->assertTrue(driversSetLicence($pdo, $u, (int)$self['id'], ' 2026-0412 ')['ok']);
        $this->assertSame('2026-0412', db_get_driver($pdo, (int)$self['id'])['licence_no']);
        $this->assertTrue(driversSetLicence($pdo, $u, (int)$self['id'], '')['ok']);
        $this->assertNull(db_get_driver($pdo, (int)$self['id'])['licence_no']);
        $this->assertFalse(driversSetLicence($pdo, $u, (int)$self['id'], str_repeat('9', 41))['ok']);
    }

    public function testLicenceCannotBeSetOnSomeoneElsesDriver(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo);
        $other = db_get_self_driver($pdo, $this->user($pdo, 'o@example.com', 'Olly Other'));
        $this->assertSame(['ok' => false, 'error' => 'Driver not found.'], driversSetLicence($pdo, $u, (int)$other['id'], 'X'));
        $this->assertNull(db_get_driver($pdo, (int)$other['id'])['licence_no']);
    }
}
```

`tests/DriversPageTest.php`:

```php
<?php
// wcma-calculator/tests/DriversPageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../events-lib.php';
require_once __DIR__ . '/../home-page.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../drivers-lib.php';
require_once __DIR__ . '/../drivers-page.php';

use PHPUnit\Framework\TestCase;

final class DriversPageTest extends TestCase
{
    private function vm(array $drivers, array $o = []): array {
        return array_merge([
            'rows' => driversRows($drivers, [], 1, 2026), 'season' => 2026, 'csrf' => 'tok',
            'licenceLink' => ['label' => '2026 Race Licences', 'url' => 'https://msr.test/l?a=1&b=2'],
        ], $o);
    }

    public function testYouComeFirstThenCoDriversEachWithGearAndLicence(): void
    {
        $html = renderDriversHtml($this->vm([
            ['id' => 1, 'name' => 'Jordan Lee', 'licence_no' => '2026-0412'],
            ['id' => 2, 'name' => 'Sam <Patel>', 'licence_no' => null],
        ]));
        $this->assertLessThan(strpos($html, 'Co-drivers you manage'), strpos($html, '<h2>You</h2>'));
        $this->assertStringContainsString('Jordan Lee (you)', $html);
        $this->assertStringContainsString('Sam &lt;Patel&gt;', $html);
        $this->assertSame(2, substr_count($html, 'Needs gear tech 2026'));
        $this->assertStringContainsString('href="gear.php?action=start&amp;driver_id=2">Add photos</a>', $html);
        $this->assertStringContainsString('id="licence-1" name="licence_no" maxlength="40" value="2026-0412"', $html);
        $this->assertStringContainsString('name="action" value="licence"', $html);
        $this->assertStringContainsString('name="driver_id" value="2"', $html);
    }

    public function testLicencesPointAtMotorsportRegAndTheAddFormIsThere(): void
    {
        $html = renderDriversHtml($this->vm([['id' => 1, 'name' => 'Jordan Lee', 'licence_no' => null]]));
        $this->assertStringContainsString('Licences are managed on MotorsportReg.', $html);
        $this->assertStringContainsString('href="https://msr.test/l?a=1&amp;b=2"', $html);
        $this->assertStringContainsString('No co-drivers yet.', $html);
        $this->assertStringContainsString('name="action" value="add"', $html);
        $this->assertStringContainsString('Add a co-driver', $html);
        $this->assertStringContainsString('href="profile.php"', $html);
        $this->assertStringNotContainsString('Renew', $html);
        $this->assertStringNotContainsString('target="_blank"', renderDriversHtml($this->vm([['id' => 1, 'name' => 'J', 'licence_no' => null]], ['licenceLink' => null])));
    }

    public function testNoBannedWording(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i',
            file_get_contents(__DIR__ . '/../drivers-page.php') . file_get_contents(__DIR__ . '/../drivers-lib.php'));
    }
}
```

`tests/DriversSourceTest.php`:

```php
<?php
// wcma-calculator/tests/DriversSourceTest.php
//
// Source-level guards for drivers.php and gear.php (they need config.php or the session).
use PHPUnit\Framework\TestCase;

final class DriversSourceTest extends TestCase
{
    private function src(string $file): string {
        return str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../' . $file));
    }

    public function testDriversNeedsASignedInUserChecksCsrfAndScopesToTheUser(): void
    {
        $src = $this->src('drivers.php');
        $this->assertStringContainsString("require_role('user')", $src);
        $this->assertMatchesRegularExpression("/REQUEST_METHOD'\] === 'POST'\) \{\s*if \(!validateCsrfToken\(/", $src);
        $this->assertStringContainsString('driversAdd($pdo, $uid,', $src);
        $this->assertStringContainsString('driversSetLicence($pdo, $uid,', $src);
        $this->assertStringContainsString("header('Location: drivers.php');", $src);
    }

    public function testOldGearListRoutesRedirectToDriversAndRenewIsGone(): void
    {
        $gear = $this->src('gear.php');
        $this->assertMatchesRegularExpression("/case 'list':\s*case 'add':\s*case 'renew':\s*header\('Location: drivers\.php'\);\s*exit;/", $gear);
        $this->assertStringNotContainsString('handleGearList', $gear);
        $this->assertStringNotContainsString('renderGearListPage', $this->src('gear-page.php'));
        $this->assertStringNotContainsString('function gearRenew', $this->src('gear-lib.php'));
        $this->assertStringContainsString('<a href="drivers.php">← Back to Drivers</a>', $this->src('gear-page.php'));
    }

    public function testNavAndLinksPointAtDrivers(): void
    {
        $this->assertStringContainsString("'href' => 'drivers.php'", $this->src('layout.php'));
        $this->assertStringContainsString('href="drivers.php">Manage drivers', $this->src('home-page.php'));
        $this->assertStringContainsString('<a href="drivers.php">Go to drivers</a>', $this->src('profile.php'));
    }
}
```

Update existing tests:
- `tests/GearPageTest.php`: delete `testAddFormCanBePrefilledFromTheQueryAndEscapesIt`, `testListShowsAddFormRecordsAndEscapesNames`, `testListShowsStatusChipsPerRecord`, `testEmptyListSaysSo`, `testPreviousSeasonRecordsOfferRenewOnlyWhenNoCurrentRecordExists`, and any private helper only they use (the one that calls `renderGearListPage`, around line 45).
- `tests/GearLibTest.php`: delete `testRenewCopiesNameAndLicenceForALaterSeason`.
- `tests/GearChipsTest.php` line 40 and `tests/GearChipsActionsTest.php` line 42: expect `<a href="drivers.php">Go to Drivers</a>` instead of `<a href="gear.php?name=Sam%20Coach">Add gear record</a>`. Everywhere they assert `'Add gear record'` is **absent** or present, use `'Go to Drivers'`. In `GearChipsTest.php` line 73, the escaping assertion on `gear.php?name=%3Cb…`: replace it with `$this->assertStringContainsString('&lt;b&gt;&quot;Al&quot; &amp; Co', $html);` (the name is still escaped in the chip text).

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php phpunit.phar --filter "Drivers|GearPageTest|GearLibTest|GearChips"`
Expected: the new files are missing, and the gear chip assertions fail.

- [ ] **Step 3: Implement**

`drivers-lib.php`:

```php
<?php
// wcma-calculator/drivers-lib.php
//
// Driver profiles for the Drivers page (spec §4): co-drivers, licence numbers, and each driver's
// gear status for the season. The profile carries over year to year; the gear check does not.
// Callers must have loaded db.php and gear-lib.php.

/** From January 1 every driver shows "Needs gear tech {season}" until there is gear activity. */
function driversGearLabel(array $status, int $season): string {
    return $status['state'] === 'none' ? 'Needs gear tech ' . $season : gearStatusLabel($status, $season);
}

/** Every state opens this season's gear photos; gear.php?action=start creates the record on first use. */
function driversGearAction(int $driverId, array $status): array {
    $labels = ['accepted' => 'View gear', 'pending_review' => 'View photos', 'needs_changes' => 'Retake photos', 'photos_draft' => 'Continue photos'];
    return ['label' => $labels[$status['state']] ?? 'Add photos', 'url' => 'gear.php?action=start&driver_id=' . $driverId];
}

/** @param array $gear driver id => that driver's gear_records row for $season */
function driversRows(array $drivers, array $gear, int $selfId, int $season): array {
    $rows = [];
    foreach ($drivers as $d) {
        $id = (int)$d['id'];
        $status = isset($gear[$id]) ? gearStatus($gear[$id]) : ['state' => 'none', 'via' => null];
        $rows[] = ['driver' => $d, 'isSelf' => $id === $selfId, 'state' => $status['state'],
                   'label' => driversGearLabel($status, $season), 'action' => driversGearAction($id, $status)];
    }
    return $rows;
}

/** Adds a co-driver the owner manages. @return array{ok: bool, error: ?string, id: ?int} */
function driversAdd(PDO $pdo, int $ownerId, string $name, string $licence): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'id' => null];
    $name = trim((string)preg_replace('/\s+/', ' ', $name));
    $licence = trim($licence);
    if ($name === '') return $fail("Enter the driver's name.");
    if (mb_strlen($name, 'UTF-8') > 100) return $fail('That name is too long (100 characters at most).');
    if (mb_strlen($licence, 'UTF-8') > 40) return $fail('That licence number is too long (40 characters at most).');
    $existing = db_find_driver($pdo, $ownerId, $name);
    if ($existing !== null) return $fail($existing['name'] . ' is already on your Drivers page.');
    try {
        $id = db_create_driver($pdo, $ownerId, $name, $licence === '' ? null : $licence);
    } catch (PDOException $e) {
        return $fail($name . ' is already on your Drivers page.');   // lost a race with a duplicate request
    }
    return ['ok' => true, 'error' => null, 'id' => $id];
}

/** Sets or clears the licence number on one of the owner's drivers. @return array{ok: bool, error: ?string} */
function driversSetLicence(PDO $pdo, int $ownerId, int $driverId, string $licence): array {
    $driver = db_get_driver($pdo, $driverId);
    if ($driver === null || (int)$driver['owner_user_id'] !== $ownerId) return ['ok' => false, 'error' => 'Driver not found.'];
    $licence = trim($licence);
    if (mb_strlen($licence, 'UTF-8') > 40) return ['ok' => false, 'error' => 'That licence number is too long (40 characters at most).'];
    db_update_driver_licence($pdo, $driverId, $licence === '' ? null : $licence);
    return ['ok' => true, 'error' => null];
}
```

`drivers-page.php`:

```php
<?php
// wcma-calculator/drivers-page.php
//
// Markup for the Drivers page (spec §4). Pure: no DB, no session, no echo. Callers must have loaded
// view_helpers.php (h()) and home-page.php (homeStatusClass()).

function driversRenderRow(array $row, string $csrf): string {
    $d = $row['driver'];
    $id = (int)$d['id'];
    $out = '<div class="hub-card drivers-row"><div class="drivers-row-head"><h3>' . h((string)$d['name']) . ($row['isSelf'] ? ' (you)' : '') . '</h3>'
        . '<span class="hub-status ' . h(homeStatusClass($row['state'])) . '">' . h($row['label']) . '</span>'
        . '<a class="hub-btn hub-btn--secondary" href="' . h($row['action']['url']) . '">' . h($row['action']['label']) . '</a></div>'
        . '<form method="post" action="drivers.php" class="hub-line">'
        . '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">'
        . '<input type="hidden" name="action" value="licence"><input type="hidden" name="driver_id" value="' . $id . '">'
        . '<label for="licence-' . $id . '">Licence number</label>'
        . '<input type="text" id="licence-' . $id . '" name="licence_no" maxlength="40" value="' . h((string)($d['licence_no'] ?? '')) . '">'
        . '<button type="submit" class="hub-btn hub-btn--link">Save</button></form>';
    if ($row['isSelf']) {
        $out .= '<p class="form-hint">Your name comes from your account. <a href="profile.php">Change it in Profile</a>.</p>';
    }
    return $out . '</div>';
}

function renderDriversHtml(array $vm): string {
    $csrf = (string)$vm['csrf'];
    $self = array_values(array_filter($vm['rows'], fn(array $r): bool => $r['isSelf']));
    $others = array_values(array_filter($vm['rows'], fn(array $r): bool => !$r['isSelf']));

    $out = '<h1>Drivers</h1><p class="hub-intro">Gear is checked every season. From January 1, each driver needs gear tech for '
        . (int)$vm['season'] . ': pre-tech it with photos, or have it checked at the track. Names and licence numbers carry over from year to year.</p>'
        . '<p class="form-hint">Licences are managed on MotorsportReg.';
    if ($vm['licenceLink'] !== null) {
        $out .= ' <a href="' . h((string)$vm['licenceLink']['url']) . '" target="_blank" rel="noopener">' . h((string)$vm['licenceLink']['label']) . ' &#8599;</a>';
    }
    $out .= '</p><h2>You</h2>';
    foreach ($self as $row) $out .= driversRenderRow($row, $csrf);

    $out .= '<h2>Co-drivers you manage</h2>';
    if (!$others) $out .= '<p>No co-drivers yet.</p>';
    foreach ($others as $row) $out .= driversRenderRow($row, $csrf);

    $out .= '<form method="post" action="drivers.php" class="hub-card" id="drivers-add-form">'
        . '<input type="hidden" name="csrf_token" value="' . h($csrf) . '"><input type="hidden" name="action" value="add">'
        . '<h3>Add a co-driver</h3>'
        . '<label for="driver-name">Name</label><input type="text" id="driver-name" name="name" maxlength="100" required>'
        . '<label for="driver-licence">Licence number (optional)</label><input type="text" id="driver-licence" name="licence_no" maxlength="40">'
        . '<button type="submit" class="hub-btn">Add co-driver</button></form>';
    return $out;
}
```

`drivers.php`:

```php
<?php
// wcma-calculator/drivers.php — the competitor's Drivers page (spec §4): their own profile, the
// co-drivers they manage, licence numbers and this season's gear status. Gear photos stay on gear.php.
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/cars-lib.php';
require __DIR__ . '/events-lib.php';
require __DIR__ . '/gear-lib.php';
require __DIR__ . '/drivers-lib.php';
require __DIR__ . '/home-page.php';
require __DIR__ . '/drivers-page.php';

date_default_timezone_set('America/Denver');
$pdo = db_connect();
db_init($pdo);
$user = require_role('user');
$uid = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
    switch ($_POST['action'] ?? '') {
        case 'add':
            $r = driversAdd($pdo, $uid, (string)($_POST['name'] ?? ''), (string)($_POST['licence_no'] ?? ''));
            setFlash($r['ok'] ? 'Co-driver added. Add photos of their gear, or have it checked at the track.' : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            break;
        case 'licence':
            $r = driversSetLicence($pdo, $uid, (int)($_POST['driver_id'] ?? 0), (string)($_POST['licence_no'] ?? ''));
            setFlash($r['ok'] ? 'Licence number saved.' : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            break;
    }
    header('Location: drivers.php');
    exit;
}

$season = gearSeasonNow();
$drivers = db_get_user_drivers($pdo, $uid);
$gear = [];
foreach ($drivers as $d) {
    $g = db_get_gear_record_for_driver($pdo, (int)$d['id'], $season);
    if ($g !== null) $gear[(int)$d['id']] = $g;
}
$self = db_get_self_driver($pdo, $uid);

renderPageStart('Drivers', 'drivers', ['flash' => getFlash()]);
echo renderDriversHtml([
    'rows' => driversRows($drivers, $gear, $self !== null ? (int)$self['id'] : 0, $season),
    'season' => $season, 'csrf' => generateCsrfToken(),
    'licenceLink' => seasonLinkMatching(db_get_season_links($pdo, true), 'Licen'),
]);
renderPageEnd();
```

`gear.php`:
- Replace the `case 'list':`, `case 'add':` and `case 'renew':` blocks with:

```php
    case 'list':
    case 'add':
    case 'renew':
        header('Location: drivers.php');
        exit;
```

- Delete `handleGearList()`, `handleGearAdd()` and `handleGearRenew()`.
- In `loadOwnGearRecord()`, `requireGearPost()`, `handleGearStart()` and the `default:` case, change `header('Location: gear.php')` to `header('Location: drivers.php')`. Do **not** change the `gear.php?action=pretech&id=` redirects.
- Change the header comment to `// wcma-calculator/gear.php — a driver's gear photo pre-tech (the Drivers page links here).`

`gear-page.php`:
- Delete `renderGearListPage()` and fix the file comment: it now holds only the pre-tech page.
- In `renderGearPretechPage()`, change `'<a href="gear.php">← Back to My Drivers</a>'` to `'<a href="drivers.php">← Back to Drivers</a>'`.

`gear-lib.php`: delete `gearRenew()`.

`gear-chips.php` line 45: change the owner link to

```php
                $html .= ' <a href="drivers.php">Go to Drivers</a>';
```

and in the doc comment at line 25, change `"Add gear record" link` to `"Go to Drivers" link`.

`layout.php` (`hubNavItems()`): `['key' => 'drivers', 'label' => 'Drivers', 'href' => 'drivers.php'],` (drop its comment).
`home-page.php`: `href="gear.php">Manage drivers` → `href="drivers.php">Manage drivers`.
`profile.php` line 178: `<a href="gear.php">Go to drivers</a>` → `<a href="drivers.php">Go to drivers</a>`.

`css/hub.css`, before `/* Phone */`:

```css
/* Drivers */
.drivers-row-head { display: flex; flex-wrap: wrap; gap: 10px 18px; align-items: center; }
.drivers-row-head h3 { margin: 0; margin-right: auto; font-size: 22px; }
.drivers-row .hub-line { justify-content: flex-start; gap: 12px; flex-wrap: wrap; }
.drivers-row input[type="text"], #drivers-add-form input[type="text"] { min-height: var(--hub-tap); }
```

- [ ] **Step 4: Run the tests**

Run: `php phpunit.phar` → OK. `grep -rn "gearRenew\|renderGearListPage\|gear.php?name=" --include=*.php --include=*.js .` returns nothing outside `phpmailer/`.

- [ ] **Step 5: Commit**

```bash
git add drivers-lib.php drivers-page.php drivers.php gear.php gear-page.php gear-lib.php gear-chips.php layout.php home-page.php profile.php css/hub.css tests/
git commit -m "feat(hub): drivers page with licences and seasonal gear status; renew removed"
```

---

### Task 7: The tech sheet form starts from a car

**Files:**
- Modify: `cars-lib.php` (add `carsSheetSnapshot()`; remove `carsApplySheetDetails()`)
- Modify: `garage-page.php` (add `renderTechSheetCarPickerHtml()`)
- Modify: `tech-sheets.php` (`handleNew`, `handleEdit`, `renderTechSheetForm`, `renderTechSheetEditForm`, `handleSubmit`, `handleUpdate`)
- Test: `tests/CarSheetSnapshotTest.php`, `tests/TechSheetCarPickerTest.php` (create); `tests/TechSheetsHandlersTest.php`, `tests/CarsLibTest.php`

**Interfaces:**
- Consumes: `garageCarTitle()`, `garageCarSub()` (Task 3); `db_get_user_cars()`, `db_get_user_car()`, `db_update_car()`.
- Produces:
  - `carsSheetSnapshot(array $car, array $post): array{ok: bool, error: ?string, car_number: string, car_colour: string, engine_cc: ?string, colour_for_car: ?string}`
  - `renderTechSheetCarPickerHtml(array $cars, int $eventId): string`
  - `renderTechSheetForm(array $submission, array $events, string $csrf, ?array $existingSheet, array $existingDrivers, array $gearNames, array $car, int $preselectEventId = 0): void`. `$car` is now required, and for an edit it is the sheet's car. (Task 8 replaces `$gearNames`.)

- [ ] **Step 1: Write the failing tests**

`tests/CarSheetSnapshotTest.php`:

```php
<?php
// wcma-calculator/tests/CarSheetSnapshotTest.php
require_once __DIR__ . '/../cars-lib.php';

use PHPUnit\Framework\TestCase;

final class CarSheetSnapshotTest extends TestCase
{
    private function car(array $o = []): array {
        return array_merge(['id' => 3, 'car_number' => '042', 'make' => 'Honda', 'model' => 'S2000', 'colour' => 'Silver', 'engine_cc' => '1997'], $o);
    }

    public function testTheSheetCopiesTheCarAndIgnoresPostedCarFields(): void
    {
        $r = carsSheetSnapshot($this->car(), ['car_number' => '99', 'car_colour' => 'Pink', 'engine_cc' => '1']);
        $this->assertSame(['ok' => true, 'error' => null, 'car_number' => '042', 'car_colour' => 'Silver', 'engine_cc' => '1997', 'colour_for_car' => null], $r);
        $this->assertNull(carsSheetSnapshot($this->car(['engine_cc' => '']), [])['engine_cc']);
    }

    public function testACarWithoutAColourTakesItFromTheFormAndSavesItBack(): void
    {
        $r = carsSheetSnapshot($this->car(['colour' => null]), ['car_colour' => '  Rally   Blue ']);
        $this->assertTrue($r['ok']);
        $this->assertSame('Rally Blue', $r['car_colour']);
        $this->assertSame('Rally Blue', $r['colour_for_car']);
    }

    public function testAMissingColourIsAnError(): void
    {
        $this->assertSame("Enter the car's colour.", carsSheetSnapshot($this->car(['colour' => '']), ['car_colour' => ' '])['error']);
        $this->assertFalse(carsSheetSnapshot($this->car(['colour' => '']), ['car_colour' => str_repeat('x', 31)])['ok']);
    }
}
```

`tests/TechSheetCarPickerTest.php`:

```php
<?php
// wcma-calculator/tests/TechSheetCarPickerTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../events-lib.php';
require_once __DIR__ . '/../home-page.php';
require_once __DIR__ . '/../garage-page.php';

use PHPUnit\Framework\TestCase;

final class TechSheetCarPickerTest extends TestCase
{
    private function src(): string {
        return str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../tech-sheets.php'));
    }

    public function testPickerListsTheGivenCarsAndCarriesTheEvent(): void
    {
        $cars = [['id' => 3, 'car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S<2000'],
                 ['id' => 4, 'car_number' => '17', 'year' => null, 'make' => 'Mazda', 'model' => 'Miata']];
        $html = renderTechSheetCarPickerHtml($cars, 10);
        $this->assertStringContainsString('Which car is this tech sheet for?', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=new&amp;car_id=3&amp;event_id=10"', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=new&amp;car_id=4&amp;event_id=10"', $html);
        $this->assertStringContainsString('Honda S&lt;2000', $html);
        $this->assertStringNotContainsString('event_id=0', renderTechSheetCarPickerHtml($cars, 0));
    }

    public function testNewSheetOffersOnlyActiveCarsWhenNoValidCarIsGiven(): void
    {
        $src = $this->src();
        $start = strpos($src, 'function handleNew(');
        $body = substr($src, $start, strpos($src, "\nfunction ", $start + 1) - $start);
        $this->assertStringContainsString("if (!\$car || \$car['archived_at'] !== null) {", $body);
        $this->assertStringContainsString("\$cars = db_get_user_cars(\$pdo, (int)\$user['id']);", $body);   // active cars only
        $this->assertStringContainsString('renderTechSheetCarPickerHtml($cars, $eventId)', $body);
    }

    public function testFormShowsTheCarReadOnlyWithAnEditLinkAndAColourFallback(): void
    {
        $src = $this->src();
        $this->assertStringNotContainsString('id="car_number" name="car_number"', $src);
        $this->assertStringNotContainsString('id="engine_cc" name="engine_cc"', $src);
        $this->assertStringContainsString('>Edit car details</a>', $src);
        $this->assertStringContainsString('<input type="text" id="car_colour" name="car_colour" maxlength="30" required>', $src);
    }
}
```

`tests/TechSheetsHandlersTest.php`: replace `testSubmitAndUpdateWriteDetailsBackToTheCar` and `testUpdateOnlyWritesBackWhenEditingTheCarsNewestSheet` with:

```php
    public function testSubmitAndUpdateSnapshotTheCarRecord(): void
    {
        foreach (['handleSubmit', 'handleUpdate'] as $fn) {
            $body = $this->body($fn);
            $this->assertStringContainsString('carsSheetSnapshot($car, $_POST)', $body, $fn);
            $this->assertStringContainsString("'car_make' => \$car['make'], 'car_model' => \$car['model']", $body, $fn);
            $this->assertStringContainsString("if (\$snap['colour_for_car'] !== null) db_update_car(", $body, $fn);
            $this->assertStringNotContainsString('carsApplySheetDetails(', $body, $fn);
        }
        $this->assertStringContainsString('db_get_user_car($pdo, (int)$user[\'id\'], (int)$sheet[\'car_id\'])', $this->body('handleUpdate'));
    }
```

`tests/CarsLibTest.php`: delete `testApplySheetDetailsWritesBackToTheCar`.

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php phpunit.phar --filter "CarSheetSnapshotTest|TechSheetCarPickerTest|TechSheetsHandlersTest"`
Expected: the new functions are undefined, and the source assertions fail.

- [ ] **Step 3: Implement**

`cars-lib.php`: replace `carsApplySheetDetails()` with

```php
/**
 * A tech sheet's car details are a snapshot of the car record (spec §4). Posted car fields are
 * ignored, except the colour when the car has none on file: the form asks for it, and it goes on
 * the sheet and back onto the car (colour_for_car).
 *
 * @return array{ok: bool, error: ?string, car_number: string, car_colour: string, engine_cc: ?string, colour_for_car: ?string}
 */
function carsSheetSnapshot(array $car, array $post): array {
    $colour = trim((string)($car['colour'] ?? ''));
    $forCar = null;
    if ($colour === '') {
        $colour = trim((string)preg_replace('/\s+/', ' ', (string)($post['car_colour'] ?? '')));
        $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'car_number' => (string)$car['car_number'], 'car_colour' => '', 'engine_cc' => null, 'colour_for_car' => null];
        if ($colour === '') return $fail("Enter the car's colour.");
        if (mb_strlen($colour, 'UTF-8') > CARS_FIELD_MAX['colour']) return $fail('That colour is too long (30 characters at most).');
        $forCar = $colour;
    }
    $cc = trim((string)($car['engine_cc'] ?? ''));
    return ['ok' => true, 'error' => null, 'car_number' => (string)$car['car_number'], 'car_colour' => $colour,
            'engine_cc' => $cc === '' ? null : $cc, 'colour_for_car' => $forCar];
}
```

`garage-page.php`: add

```php
/** "Which car is this tech sheet for?": the given (active) cars, each opening the form for that car. */
function renderTechSheetCarPickerHtml(array $cars, int $eventId): string {
    $out = '<h1>Submit a tech sheet</h1><p class="hub-intro">Which car is this tech sheet for?</p><div class="hub-card">';
    foreach ($cars as $car) {
        $url = 'tech-sheets.php?action=new&car_id=' . (int)$car['id'] . ($eventId > 0 ? '&event_id=' . $eventId : '');
        $out .= '<div class="hub-line"><span><span class="hub-plate">' . h((string)$car['car_number']) . '</span> ' . h(garageCarTitle($car)) . '</span>'
            . '<a class="hub-btn" href="' . h($url) . '">Choose</a></div>';
    }
    return $out . '</div><p><a href="garage.php?action=add">+ Add a car</a></p>';
}
```

`tech-sheets.php`:

1. Add `require __DIR__ . '/garage-page.php';` after the `cars-lib.php` require. (`garage-page.php` only defines functions, so it can load before `home-page.php`. The picker and the form use only `garageCarTitle()`/`garageCarSub()`.)

2. `handleNew()` becomes:

```php
function handleNew(PDO $pdo, array $user, int $carId, int $eventId = 0): void {
    $car = db_get_user_car($pdo, (int)$user['id'], $carId);
    if (!$car || $car['archived_at'] !== null) {
        $cars = db_get_user_cars($pdo, (int)$user['id']);
        if (!$cars) {
            setFlash('Add your car before submitting a tech sheet.', 'error');
            header('Location: garage.php?action=add');
            exit;
        }
        renderPageStart('Submit a tech sheet', 'garage', ['flash' => getFlash()]);
        echo renderTechSheetCarPickerHtml($cars, $eventId);
        renderPageEnd();
        return;
    }
    $declaration = db_get_car_current_declaration($pdo, $carId);
    if (!$declaration) {
        setFlash('Declare a class for this car before submitting a tech sheet.', 'error');
        header('Location: calculator.php?car=' . $carId);
        exit;
    }

    $events = db_get_active_events($pdo);
    if (empty($events)) {
        setFlash('There are no upcoming events open for tech sheet submission yet.', 'error');
        header('Location: garage.php?car=' . $carId);
        exit;
    }

    $gearNames = gearNameSuggestions(db_get_user_gear_records($pdo, (int)$user['id']), gearSeasonNow());
    renderTechSheetForm($declaration, $events, generateCsrfToken(), null, [], $gearNames, $car, $eventId);
}
```

3. `handleEdit()`: after the `pretechSheetEditable` guard, load the car and pass it on:

```php
    $car = db_get_user_car($pdo, (int)$user['id'], (int)$sheet['car_id']);
    if ($car === null) {
        setFlash('Car not found.', 'error');
        header('Location: garage.php');
        exit;
    }
```

and end with `renderTechSheetEditForm($sheet, $drivers, $events, $csrf, $gearNames, $car);`. Change `renderTechSheetEditForm` to:

```php
function renderTechSheetEditForm(array $sheet, array $drivers, array $events, string $csrf, array $gearNames, array $car): void {
    renderTechSheetForm([], $events, $csrf, $sheet, $drivers, $gearNames, $car);
}
```

4. `renderTechSheetForm()`:
   - Change its signature to `function renderTechSheetForm(array $submission, array $events, string $csrf, ?array $existingSheet, array $existingDrivers, array $gearNames, array $car, int $preselectEventId = 0): void`.
   - Delete the `$carNumber`, `$carColour`, `$engineCc`, `$carMake` and `$carModel` variables, and add `$carNeedsColour = trim((string)($car['colour'] ?? '')) === '';`.
   - In the "Vehicle & Entrant" card, delete the `car_number`, `car_colour` and `engine_cc` input `<div>`s, the hidden `car_make` / `car_model` inputs, and rename the card heading to `Entrant &amp; Driver`.
   - Insert this card before it:

```php
    <div class="detail-card">
      <h2>Car</h2>
      <p class="tech-sheet-car"><span class="hub-plate"><?= h((string)$car['car_number']) ?></span> <?= h(garageCarTitle($car)) ?><?= garageCarSub($car) !== '' ? ' · ' . h(garageCarSub($car)) : '' ?></p>
      <p class="form-hint">Car details come from your Garage and are copied onto the sheet when you submit. <a href="garage.php?car=<?= (int)$car['id'] ?>">Edit car details</a></p>
      <?php if ($carNeedsColour): ?>
      <label for="car_colour">Car colour</label>
      <input type="text" id="car_colour" name="car_colour" maxlength="30" required>
      <p class="form-hint">Your car has no colour on file yet. It will be saved to the car.</p>
      <?php endif; ?>
    </div>
```

   - The hidden `car_id` input is still emitted only for a new sheet (`(int)$car['id']`).
   - Change `'<a href="garage.php">← Back to Garage</a>'` in this form's `renderSiteHeader()` call to `'<a href="garage.php?car=' . (int)$car['id'] . '">← Back to the car</a>'`.

5. `handleSubmit()`: right after the `$event` validity check, add

```php
    $snap = carsSheetSnapshot($car, $_POST);
    if (!$snap['ok']) {
        setFlash((string)$snap['error'], 'error');
        header('Location: tech-sheets.php?action=new&car_id=' . $carId . ($eventId > 0 ? '&event_id=' . $eventId : ''));
        exit;
    }
```

   - change `$parsed = parseTechSheetPost($_POST);` to

```php
    $parsed = parseTechSheetPost(array_merge($_POST, ['car_number' => $snap['car_number'], 'car_colour' => $snap['car_colour'], 'engine_cc' => (string)($snap['engine_cc'] ?? '')]));
```

   - In the `db_insert_tech_sheet()` data, make it `'car_make' => $car['make'], 'car_model' => $car['model'], 'car_colour' => $parsed['car_colour'],` (on one line, as the test expects).
   - Replace `carsApplySheetDetails($pdo, $carId, $parsed['car_number'], $parsed['car_colour'], $parsed['engine_cc']);` with

```php
    if ($snap['colour_for_car'] !== null) db_update_car($pdo, $carId, ['colour' => $snap['colour_for_car']]);
```

6. `handleUpdate()`: after the `$event` validity check, add

```php
    $car = db_get_user_car($pdo, (int)$user['id'], (int)$sheet['car_id']);
    $snap = $car !== null ? carsSheetSnapshot($car, $_POST) : ['ok' => false, 'error' => 'Car not found.'];
    if (!$snap['ok']) {
        setFlash((string)$snap['error'], 'error');
        header('Location: tech-sheets.php?action=edit&id=' . $id);
        exit;
    }
```

   - Use the same `parseTechSheetPost(array_merge(...))` line as in `handleSubmit()`.
   - In `db_update_tech_sheet()`, use `'car_make' => $car['make'], 'car_model' => $car['model'], 'car_colour' => $parsed['car_colour'],`.
   - Replace the "Only the car's newest sheet writes its details back" comment and its `if (db_get_car_latest_tech_sheet_id(...)) { carsApplySheetDetails(...); }` block with

```php
    if ($snap['colour_for_car'] !== null) db_update_car($pdo, (int)$car['id'], ['colour' => $snap['colour_for_car']]);
```

After this, `grep -rn carsApplySheetDetails` must return nothing. Leave `db_get_car_latest_tech_sheet_id()` in `db.php`; its DB test still covers it.

- [ ] **Step 4: Run the tests**

Run: `php phpunit.phar` → OK. `php -l tech-sheets.php` → no syntax errors.

- [ ] **Step 5: Commit**

```bash
git add cars-lib.php garage-page.php tech-sheets.php tests/
git commit -m "feat(hub): tech sheets start from a car picker and copy the car's details"
```

---

### Task 8: Tech sheet drivers come from driver profiles

**Files:**
- Modify: `tech-sheet-data.php` (add `techSheetDriverName()`, `techSheetApplyDriverChoices()`, `techSheetDriverChoiceFor()`)
- Create: `js/driver-choice.js`
- Modify: `js/tech-sheet-form.js`
- Modify: `tech-sheets.php` (`handleNew`, `handleEdit`, `renderTechSheetForm`, `renderTechSheetEditForm`, `handleSubmit`, `handleUpdate`)
- Modify: `gear-lib.php` (remove `gearNameSuggestions()`, which is now unused)
- Test: `tests/TechSheetDriverChoiceTest.php`, `tests/js/driver-choice.test.js` (create); `tests/DriverLabelTest.php`, `tests/GearLinksSourceTest.php`, `tests/GearLinksTest.php`

**Interfaces:**
- Consumes: `db_get_user_drivers()` (self first), `db_driver_name_norm()` (`db.php`).
- Produces:
  - `techSheetDriverName(array $ownedById, string $choice, string $newName): ?string`. `$ownedById` maps id → `drivers` row, and `$choice` is a driver id or `'new'`.
  - `techSheetApplyDriverChoices(array $post, array $ownedById): array{ok: bool, error: ?string, post: ?array}`. It reads `driver1_choice`, `driver1_new_name`, `sheet_type` and `drivers_json` rows of `{driver_number, driver_choice, new_name, equipment}`. It writes `driver_name`, and sets `drivers_json` to rows of `{driver_number, driver_name, equipment}`, or `'[]'` for a standard sheet.
  - `techSheetDriverChoiceFor(array $ownedById, string $name): string`: the matching profile's id as a string, or `'new'`.
  - POST fields from the form: `driver1_choice`, `driver1_new_name`, plus the `drivers_json` row shape above.
  - JS `window.WcmaDriverChoice = { NEW, driverChoiceOptions(drivers, selected), driverChoiceComplete(choice, newName), wire(select, nameInput), build(doc, drivers, selected, newName, number) }`. Here `drivers` is `[{id, name, self}]` from `window.TECH_SHEET_DRIVERS`.
  - `renderTechSheetForm(array $submission, array $events, string $csrf, ?array $existingSheet, array $existingDrivers, array $ownerDrivers, array $car, int $preselectEventId = 0): void`. `$ownerDrivers` replaces `$gearNames`.

- [ ] **Step 1: Write the failing tests**

`tests/TechSheetDriverChoiceTest.php`:

```php
<?php
// wcma-calculator/tests/TechSheetDriverChoiceTest.php
require_once __DIR__ . '/../tech-sheet-data.php';

use PHPUnit\Framework\TestCase;

final class TechSheetDriverChoiceTest extends TestCase
{
    private function owned(): array {
        return [
            5 => ['id' => 5, 'name' => 'Jordan Lee', 'name_norm' => 'jordan lee'],
            6 => ['id' => 6, 'name' => 'Sam Patel', 'name_norm' => 'sam patel'],
        ];
    }

    public function testAChoiceIsAnOwnedProfileOrANewName(): void
    {
        $this->assertSame('Sam Patel', techSheetDriverName($this->owned(), '6', ''));
        $this->assertSame('Alex Kim', techSheetDriverName($this->owned(), 'new', '  Alex   Kim '));
        $this->assertNull(techSheetDriverName($this->owned(), '99', ''));          // someone else's driver
        $this->assertNull(techSheetDriverName($this->owned(), 'new', '  '));
        $this->assertNull(techSheetDriverName($this->owned(), 'new', str_repeat('a', 101)));
        $this->assertNull(techSheetDriverName($this->owned(), '', ''));
    }

    public function testChoicesBecomeNamesForAnEnduranceSheet(): void
    {
        $post = ['sheet_type' => 'endurance', 'driver1_choice' => '5', 'driver1_new_name' => '',
                 'drivers_json' => json_encode([['driver_number' => 2, 'driver_choice' => 'new', 'new_name' => 'Alex Kim', 'equipment' => ['helmet' => 'ok']],
                                                ['driver_number' => 3, 'driver_choice' => '6', 'new_name' => 'ignored', 'equipment' => []]])];
        $r = techSheetApplyDriverChoices($post, $this->owned());
        $this->assertTrue($r['ok']);
        $this->assertSame('Jordan Lee', $r['post']['driver_name']);
        $this->assertSame([
            ['driver_number' => 2, 'driver_name' => 'Alex Kim', 'equipment' => ['helmet' => 'ok']],
            ['driver_number' => 3, 'driver_name' => 'Sam Patel', 'equipment' => []],
        ], json_decode($r['post']['drivers_json'], true));
    }

    public function testAStandardSheetDropsAdditionalDrivers(): void
    {
        $post = ['sheet_type' => 'standard', 'driver1_choice' => 'new', 'driver1_new_name' => 'Alex Kim', 'drivers_json' => '[{"driver_choice":"99"}]'];
        $r = techSheetApplyDriverChoices($post, $this->owned());
        $this->assertTrue($r['ok']);
        $this->assertSame('Alex Kim', $r['post']['driver_name']);
        $this->assertSame('[]', $r['post']['drivers_json']);
    }

    public function testBadChoicesAndDuplicatesAreRefusedWithAMessage(): void
    {
        $this->assertSame('Choose Driver 1 from your drivers, or add a co-driver with their name.',
            techSheetApplyDriverChoices(['driver1_choice' => '99'], $this->owned())['error']);
        $dup = ['sheet_type' => 'endurance', 'driver1_choice' => '6', 'drivers_json' => json_encode([['driver_number' => 2, 'driver_choice' => 'new', 'new_name' => 'sam  PATEL', 'equipment' => []]])];
        $this->assertSame('sam PATEL is on this sheet twice.', techSheetApplyDriverChoices($dup, $this->owned())['error']);
        $bad = ['sheet_type' => 'endurance', 'driver1_choice' => '5', 'drivers_json' => json_encode([['driver_number' => 2, 'driver_choice' => '99']])];
        $this->assertSame('Choose a driver for every added driver.', techSheetApplyDriverChoices($bad, $this->owned())['error']);
        $this->assertFalse(techSheetApplyDriverChoices(['sheet_type' => 'endurance', 'driver1_choice' => '5', 'drivers_json' => 'nope'], $this->owned())['ok']);
    }

    public function testEditingASheetWithAnUnknownNameKeepsItAsANewDriver(): void
    {
        $this->assertSame('6', techSheetDriverChoiceFor($this->owned(), ' Sam  PATEL '));
        $this->assertSame('new', techSheetDriverChoiceFor($this->owned(), 'Pat Old-Name'));
    }
}
```

`tests/js/driver-choice.test.js`:

```js
const test = require('node:test');
const assert = require('node:assert');
const { NEW, driverChoiceOptions, driverChoiceComplete } = require('../../js/driver-choice.js');

const drivers = [{ id: 5, name: 'Jordan Lee', self: true }, { id: 6, name: 'Sam Patel', self: false }];

test('options list profiles, mark you, select the chosen one and end with add', () => {
    const opts = driverChoiceOptions(drivers, 6);
    assert.deepStrictEqual(opts.map(o => o.value), ['5', '6', NEW]);
    assert.strictEqual(opts[0].label, 'Jordan Lee (you)');
    assert.strictEqual(opts[2].label, '+ Add a co-driver');
    assert.deepStrictEqual(opts.map(o => o.selected), [false, true, false]);
    assert.strictEqual(driverChoiceOptions(drivers, NEW)[2].selected, true);
});

test('a choice is complete with a profile id, or new with a name', () => {
    assert.strictEqual(driverChoiceComplete('6', ''), true);
    assert.strictEqual(driverChoiceComplete(NEW, '  Alex '), true);
    assert.strictEqual(driverChoiceComplete(NEW, '   '), false);
    assert.strictEqual(driverChoiceComplete('', 'x'), false);
});
```

`tests/DriverLabelTest.php` (`testFormLabelsDriverOneAsAPerson`): replace the first and last assertions with

```php
        $this->assertStringContainsString('<label for="driver1_choice">Driver name (Driver 1)</label>', $src);
        $this->assertStringContainsString('<select id="driver1_choice" name="driver1_choice" required>', $src);
```

(keep the `Driver/Team Name` absence check).

`tests/GearLinksSourceTest.php`: replace `testSheetFormGetsNameSuggestionsFromTheUsersOwnRecords` and `testAddedDriverRowsUseTheSuggestionList` with

```php
    public function testSheetFormPicksDriversFromTheUsersProfiles(): void
    {
        foreach (['handleNew', 'handleEdit', 'handleSubmit', 'handleUpdate'] as $fn) {
            $this->assertStringContainsString("db_get_user_drivers(\$pdo, (int)\$user['id'])", $this->body('tech-sheets.php', $fn), $fn);
        }
        foreach (['handleSubmit', 'handleUpdate'] as $fn) {
            $this->assertStringContainsString('techSheetApplyDriverChoices($_POST, $owned)', $this->body('tech-sheets.php', $fn), $fn);
        }
        $form = $this->body('tech-sheets.php', 'renderTechSheetForm');
        $this->assertStringContainsString('window.TECH_SHEET_DRIVERS = ', $form);
        $this->assertStringContainsString('<script src="js/driver-choice.js"></script>', $form);
        $this->assertStringNotContainsString('gear-names', $this->src('tech-sheets.php') . $this->src('js/tech-sheet-form.js'));
        $this->assertStringContainsString('WcmaDriverChoice.build(', $this->src('js/tech-sheet-form.js'));
    }
```

`tests/GearLinksTest.php`: delete the test that calls `gearNameSuggestions()` (around line 76).

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php phpunit.phar --filter "TechSheetDriverChoiceTest|DriverLabelTest|GearLinksSourceTest"` and `node --test tests/js/driver-choice.test.js`
Expected: undefined functions, and the missing `js/driver-choice.js`.

- [ ] **Step 3: Implement**

`tech-sheet-data.php`: after `validateAdditionalDrivers()`:

```php
/**
 * The driver a form choice stands for: one of the owner's driver profiles by id, or 'new' with a
 * typed name (db_insert_tech_sheet() / db_add_tech_sheet_driver() create that profile when the
 * sheet is saved). Null when the id is not one of the owner's profiles, or the new name is blank
 * or over 100 characters.
 *
 * @param array<int, array> $ownedById the owner's drivers rows keyed by id
 */
function techSheetDriverName(array $ownedById, string $choice, string $newName): ?string {
    if ($choice === 'new') {
        $name = trim((string)preg_replace('/\s+/', ' ', $newName));
        return ($name === '' || mb_strlen($name, 'UTF-8') > 100) ? null : $name;
    }
    if (!ctype_digit($choice) || !isset($ownedById[(int)$choice])) return null;
    return (string)$ownedById[(int)$choice]['name'];
}

/**
 * Turns the form's driver choices into what parseTechSheetPost() expects: driver_name for Driver 1
 * and, on an endurance sheet, driver_name on each drivers_json row. A standard sheet's drivers_json
 * becomes '[]'. The same person may not appear twice.
 *
 * @return array{ok: bool, error: ?string, post: ?array}
 */
function techSheetApplyDriverChoices(array $post, array $ownedById): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'post' => null];
    $first = techSheetDriverName($ownedById, (string)($post['driver1_choice'] ?? ''), (string)($post['driver1_new_name'] ?? ''));
    if ($first === null) return $fail('Choose Driver 1 from your drivers, or add a co-driver with their name.');

    $seen = [db_driver_name_norm($first) => true];
    $rows = [];
    if (($post['sheet_type'] ?? 'standard') === 'endurance') {
        $input = json_decode((string)($post['drivers_json'] ?? '[]'), true);
        if (!is_array($input)) return $fail('Choose a driver for every added driver.');
        foreach ($input as $row) {
            $name = is_array($row) ? techSheetDriverName($ownedById, (string)($row['driver_choice'] ?? ''), (string)($row['new_name'] ?? '')) : null;
            if ($name === null) return $fail('Choose a driver for every added driver.');
            $norm = db_driver_name_norm($name);
            if (isset($seen[$norm])) return $fail($name . ' is on this sheet twice.');
            $seen[$norm] = true;
            $rows[] = ['driver_number' => $row['driver_number'] ?? null, 'driver_name' => $name, 'equipment' => $row['equipment'] ?? []];
        }
    }
    $post['driver_name'] = $first;
    $post['drivers_json'] = json_encode($rows);
    return ['ok' => true, 'error' => null, 'post' => $post];
}

/** For editing a sheet: the profile id matching a name already on it, or 'new' so the name is kept. */
function techSheetDriverChoiceFor(array $ownedById, string $name): string {
    $norm = db_driver_name_norm($name);
    foreach ($ownedById as $id => $d) {
        if ($d['name_norm'] === $norm) return (string)$id;
    }
    return 'new';
}
```

(`techSheetDriverName('new', 'sam  PATEL')` returns `sam PATEL` after whitespace collapsing, which is why the duplicate message in the test reads `sam PATEL`.)

`js/driver-choice.js`:

```js
/**
 * Driver pickers on the tech sheet form: choose one of your driver profiles, or "+ Add a co-driver"
 * and type a name (the profile is created when the sheet is saved). The pure helpers are exported
 * for node --test; wire() and build() touch the DOM.
 */
(function () {
    const NEW = 'new';

    function driverChoiceOptions(drivers, selected) {
        const sel = selected == null ? '' : String(selected);
        const opts = drivers.map(function (d) {
            return { value: String(d.id), label: d.name + (d.self ? ' (you)' : ''), selected: String(d.id) === sel };
        });
        opts.push({ value: NEW, label: '+ Add a co-driver', selected: sel === NEW });
        return opts;
    }

    function driverChoiceComplete(choice, newName) {
        if (choice === NEW) return String(newName || '').trim() !== '';
        return /^\d+$/.test(String(choice || ''));
    }

    /** Shows (and requires) the name input only while "+ Add a co-driver" is chosen. Returns the sync function. */
    function wire(select, nameInput) {
        function sync() {
            const isNew = select.value === NEW;
            nameInput.hidden = !isNew;
            nameInput.required = isNew && !select.disabled;
            nameInput.disabled = select.disabled;
        }
        select.addEventListener('change', sync);
        sync();
        return sync;
    }

    function build(doc, drivers, selected, newName, number) {
        const select = doc.createElement('select');
        select.setAttribute('aria-label', 'Driver ' + number);
        driverChoiceOptions(drivers, selected).forEach(function (o) {
            const opt = doc.createElement('option');
            opt.value = o.value;
            opt.textContent = o.label;
            opt.selected = o.selected;
            select.appendChild(opt);
        });
        const nameInput = doc.createElement('input');
        nameInput.type = 'text';
        nameInput.maxLength = 100;
        nameInput.placeholder = "Co-driver's name";
        nameInput.setAttribute('aria-label', 'Driver ' + number + ' name');
        nameInput.value = newName || '';
        return { select: select, nameInput: nameInput, sync: wire(select, nameInput) };
    }

    const api = { NEW, driverChoiceOptions, driverChoiceComplete, wire, build };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else window.WcmaDriverChoice = api;
})();
```

`js/tech-sheet-form.js`: the rows keep their shape, but `nameInput` is replaced by `picker` (`{select, nameInput, sync}`):
- Sheet-type toggle: replace `d.nameInput.disabled = !isEndurance;` with `d.picker.select.disabled = !isEndurance; d.picker.sync();`.
- `renumberDriverRows()`: replace the `placeholder` line with `d.picker.select.setAttribute('aria-label', 'Driver ' + number);`.
- `addDriverRow(existingDriver)`: replace the whole `nameInput` block (from `const nameInput = document.createElement('input');` to `fieldsWrap.appendChild(nameInput);`) with

```js
        const drivers = window.TECH_SHEET_DRIVERS || [];
        const firstCoDriver = drivers.find(function (d) { return !d.self; });
        const picker = WcmaDriverChoice.build(document, drivers,
            existingDriver ? existingDriver.driver_choice : (firstCoDriver ? firstCoDriver.id : WcmaDriverChoice.NEW),
            existingDriver ? existingDriver.new_name : '', number);
        fieldsWrap.appendChild(picker.select);
        fieldsWrap.appendChild(picker.nameInput);
```

  In the `additionalDrivers.push({...})` call, replace `nameInput: nameInput` with `picker: picker`. Replace the trailing `nameInput.addEventListener('input', …)` with the same listener on `picker.nameInput`, plus a `change` listener on `picker.select` that removes the `error` class from both.
- After `const driverPad = …`, add Driver 1's toggle:

```js
    const driver1Choice = document.getElementById('driver1_choice');
    const driver1NewName = document.getElementById('driver1_new_name');
    WcmaDriverChoice.wire(driver1Choice, driver1NewName);
```

- `clearAllHighlights()`: replace `d.nameInput.classList.remove('error');` with `d.picker.select.classList.remove('error'); d.picker.nameInput.classList.remove('error');`, and add `driver1Choice.classList.remove('error'); driver1NewName.classList.remove('error');`.
- In the submit handler, before the Driver 1 equipment check:

```js
        if (!WcmaDriverChoice.driverChoiceComplete(driver1Choice.value, driver1NewName.value)) {
            e.preventDefault();
            (driver1Choice.value === WcmaDriverChoice.NEW ? driver1NewName : driver1Choice).classList.add('error');
            errorEl.textContent = 'Choose Driver 1, or pick "+ Add a co-driver" and type their name.';
            errorEl.hidden = false;
            errorEl.classList.add('show');
            return;
        }
```

- In the endurance check, replace `d.nameInput.value.trim() === ''` (in `find`) and the `incompleteDriver.nameInput` highlight with `!WcmaDriverChoice.driverChoiceComplete(d.picker.select.value, d.picker.nameInput.value)`. Highlight `incompleteDriver.picker.nameInput` when the choice is `NEW`, otherwise `incompleteDriver.picker.select`. Change the message's "enter a name" to "choose a driver".
- `drivers_json` payload:

```js
            return { driver_number: d.number, driver_choice: d.picker.select.value, new_name: d.picker.nameInput.value, equipment: d.state };
```

`tech-sheets.php`:

1. `handleNew()`: replace the `$gearNames = …` line and the `renderTechSheetForm(…)` call with

```php
    renderTechSheetForm($declaration, $events, generateCsrfToken(), null, [], db_get_user_drivers($pdo, (int)$user['id']), $car, $eventId);
```

2. `handleEdit()`: replace the `$gearNames = …` line, and end with `renderTechSheetEditForm($sheet, $drivers, $events, $csrf, db_get_user_drivers($pdo, (int)$user['id']), $car);`. Rename `renderTechSheetEditForm`'s `$gearNames` parameter to `array $ownerDrivers` and pass it through.

3. `renderTechSheetForm()`: rename the `$gearNames` parameter to `array $ownerDrivers`, and add after the existing `$existingDriversForJs` block:

```php
    $ownedById = [];
    $selfId = null;
    foreach ($ownerDrivers as $d) {
        $ownedById[(int)$d['id']] = $d;
        if ($selfId === null && (int)($d['user_id'] ?? 0) === (int)$d['owner_user_id']) $selfId = (int)$d['id'];
    }
    $driver1Choice = $isEdit ? techSheetDriverChoiceFor($ownedById, (string)$existingSheet['driver_name']) : ($selfId !== null ? (string)$selfId : 'new');
    $driver1NewName = ($isEdit && $driver1Choice === 'new') ? (string)$existingSheet['driver_name'] : '';
    $driversForJs = array_map(fn(array $d): array => ['id' => (int)$d['id'], 'name' => (string)$d['name'], 'self' => (int)$d['id'] === $selfId], $ownerDrivers);
```

   and change the `$existingDriversForJs` map to carry the choice:

```php
    $existingDriversForJs = array_map(function (array $d) use ($ownedById): array {
        $choice = techSheetDriverChoiceFor($ownedById, (string)$d['driver_name']);
        return [
            'driver_number' => (int)$d['driver_number'],
            'driver_choice' => $choice,
            'new_name' => $choice === 'new' ? (string)$d['driver_name'] : '',
            'equipment' => json_decode($d['equipment_json'] ?? '{}', true) ?: [],
        ];
    }, $existingDrivers);
```

   (Move the `$ownedById` loop above this map.) Then in the markup:
   - Delete the `<datalist id="gear-names">…</datalist>` line.
   - Replace the `driver_name` input `<div>` with:

```php
        <div><label for="driver1_choice">Driver name (Driver 1)</label>
          <select id="driver1_choice" name="driver1_choice" required>
            <?php foreach ($ownerDrivers as $d): ?>
            <option value="<?= (int)$d['id'] ?>"<?= (string)(int)$d['id'] === $driver1Choice ? ' selected' : '' ?>><?= h((string)$d['name']) ?><?= (int)$d['id'] === $selfId ? ' (you)' : '' ?></option>
            <?php endforeach; ?>
            <option value="new"<?= $driver1Choice === 'new' ? ' selected' : '' ?>>+ Add a co-driver</option>
          </select>
          <input type="text" id="driver1_new_name" name="driver1_new_name" maxlength="100" placeholder="Co-driver's name" aria-label="Driver 1 name" value="<?= h($driver1NewName) ?>">
        </div>
```

   - Replace the `<?php if ($gearNames): ?><p class="form-hint">Pick a driver from your My Drivers list …</p><?php endif; ?>` line with

```php
      <p class="form-hint">Drivers come from your <a href="drivers.php">Drivers</a> page. Choose "+ Add a co-driver" to add someone new: they are added to your Drivers when you submit.</p>
```

   - In the `<script>` block, add `window.TECH_SHEET_DRIVERS = <?= json_encode($driversForJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;`, and give `TECH_SHEET_EXISTING_DRIVERS` the same `JSON_HEX_*` flags (names are user text).
   - Load `<script src="js/driver-choice.js"></script>` immediately before `<script src="js/tech-sheet-form.js"></script>`.

4. `handleSubmit()` and `handleUpdate()`: right before the `$parsed = parseTechSheetPost(…)` line, add

```php
    $owned = [];
    foreach (db_get_user_drivers($pdo, (int)$user['id']) as $d) $owned[(int)$d['id']] = $d;
    $choices = techSheetApplyDriverChoices($_POST, $owned);
    if (!$choices['ok']) {
        setFlash((string)$choices['error'], 'error');
        header('Location: ' . <same redirect as this handler's snapshot failure>);
        exit;
    }
```

   (In `handleSubmit`, the redirect is `'tech-sheets.php?action=new&car_id=' . $carId . ($eventId > 0 ? '&event_id=' . $eventId : '')`. In `handleUpdate`, it is `'tech-sheets.php?action=edit&id=' . $id`.) Then change the parse line to start from `$choices['post']` instead of `$_POST`:

```php
    $parsed = parseTechSheetPost(array_merge($choices['post'], ['car_number' => $snap['car_number'], 'car_colour' => $snap['car_colour'], 'engine_cc' => (string)($snap['engine_cc'] ?? '')]));
```

`gear-lib.php`: delete `gearNameSuggestions()`. `grep -rn "gearNameSuggestions\|gear-names" --include=*.php --include=*.js .` must return nothing.

- [ ] **Step 4: Run the tests**

Run: `php phpunit.phar` → OK. `node --test tests/js/*.test.js` → all pass. `php -l tech-sheets.php tech-sheet-data.php` → no errors.

- [ ] **Step 5: Commit**

```bash
git add tech-sheet-data.php js/driver-choice.js js/tech-sheet-form.js tech-sheets.php gear-lib.php tests/
git commit -m "feat(hub): tech sheet drivers are picked from driver profiles"
```

---

### Task 9: End-to-end verification

**Files:** none change unless a defect is found. A defect gets a fix commit with a test.

- [ ] **Step 1: Run the automated suites**

- `php phpunit.phar` → OK
- `node --test tests/js/*.test.js` → all pass
- `for f in *.php; do php -l "$f" >/dev/null || echo "LINT FAIL $f"; done` → no output

- [ ] **Step 2: Reset, seed and serve**

Run `php reset-hub-db.php --confirm`, then `php seed-hub-db.php`, then `php -S localhost:8080`.

- [ ] **Step 3: Browser checks** (Playwright, following `scratch/tech3c-e2e.js`; desktop 1280px and phone 390px; signed in as jordan unless stated)

1. **Nav:** Garage → `garage.php` and Drivers → `drivers.php`. `account.php`, `account.php?action=view&id=1` and `gear.php` land on the Garage, the declaration and Drivers respectively.
2. **Garage list:** cards for #42 S2000 and #17 Miata, each with a round plate, class badge + status word, car tech status, and a next-event line. There is a dashed "+ Add a car" card. At 390px the cards stack in one column and nothing scrolls sideways.
3. **Add a car:** submitting without a colour shows "Enter the car's colour." and keeps the typed make. A valid car lands on its car page with "Car added. Next, declare its class." and a **Declare class** button.
4. **Car page (#42):**
   - Edit details: change the colour; the header shows the new colour.
   - Tag Fall Sprint with "Bring this car to another event"; the not-registering copy shows.
   - **Submit tech sheet** opens the form with the event pre-selected and the car read-only, with an **Edit car details** link.
   - **Not going anymore** removes the event.
5. **Archive / restore:** archive #17 (confirmation modal). It moves to "Archived cars (1)" and disappears from Home, from the Home tag forms, and from `tech-sheets.php?action=new` (the picker). **Restore** brings it back.
6. **Declarations:**
   - Submit a declaration via `calculator.php?car={id}`. The car page shows it with its status, and older ones under History.
   - **View** shows the declaration page with its files.
   - Delete is refused for a declaration used by a sheet, and allowed otherwise.
7. **Drafts still work:** in the calculator, save a draft, list drafts, load it and delete it (network: `account.php?action=draft-*` return JSON).
8. **Drivers:**
   - Jordan (you) first, then Sam Patel. Both show "Needs gear tech {year}" unless the seed says otherwise.
   - Save a licence number.
   - Add co-driver "Alex Kim"; adding "alex  kim" again is refused.
   - **Add photos** opens `gear.php?action=pretech&id=…`, whose back link returns to Drivers.
9. **Tech sheet drivers:**
   - The new sheet's Driver 1 defaults to "Jordan Lee (you)".
   - Choose Endurance and add a driver: the row defaults to the first co-driver.
   - Choose "+ Add a co-driver" and type "Pat New"; submit.
   - Drivers now lists Pat New, and the sheet view's gear chips show every driver.
   - Picking the same person twice is refused with "… is on this sheet twice."
10. **A car without a colour:**
    - Make one through the calculator's which-car step (New car). Its tech sheet form asks for "Car colour".
    - After submitting, the car page shows that colour.
11. **Other users' ids:** as a second seeded user, `garage.php?car={jordan's car id}`, `garage.php?declaration={jordan's}` and `garage.php?action=file&id={jordan's}&field=car_image` each give "not found" (or a 404 for the file). None shows Jordan's data.
12. **The server log** has no PHP warnings, notices or fatal errors.

- [ ] **Step 4: Report**

Record pass/fail per item, with any error text, in the hand-off. Phase 3 is not complete while any item fails.
