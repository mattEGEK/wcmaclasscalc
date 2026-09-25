# Pre-Tech Phase 3c: Gear Follow-ups Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the three follow-ups from the phase 3b final review: make the sheet form's first driver field unambiguous, let an inspector fix a "No gear record" chip with one tap at the track, and stop offering "Add gear record" where it cannot link.

**Architecture:** (1) Copy-only: the sheet form label becomes "Driver name (Driver 1)" with a hint that team names belong in Entrant, and the rendered sheet header says "Driver 1". (2) A session-free helper `gearCreateAndAcceptInPerson()` creates the gear record under the *sheet owner's* account for the sheet's season and accepts it in person in one transaction; a new admin POST route `gear-create-accept` calls it; `renderGearChips()` gains an optional options array so the admin roster and review page show a "Create and accept gear in person" button for drivers with no record. (3) The same options array carries the sheet's season so the competitor "Add gear record" link (and the inspector button) only appear for the current season. No schema change, no email.

**Tech Stack:** PHP 8.3, SQLite via PDO, PHPUnit (`phpunit.phar`), vanilla JS none, Playwright for the end-to-end check.

**Spec:** `docs/superpowers/specs/2026-09-23-digital-tech-inspection-design.md` (Gear record, In-person acceptance for gear, Event roster). This phase implements the user-approved follow-ups from the phase 3b review; there is no separate spec.

## Global Constraints

- **Terminology (binding):** copy uses "reviewed", "accepted", "teched", "pre-teched"; never "approved", "passed" or "safe" as words.
- **No schema change:** every change is copy, a helper, a route, or markup. `gear_records`, `tech_sheets` and `tech_sheet_drivers` are untouched.
- **Ownership rule:** the one-tap action creates the gear record under the **sheet owner's** `user_id` (the competitor or captain who filed the sheet), never under the acting admin. The acting admin is recorded only as the reviewer (`reviewed_by_user_id`).
- **One-tap safety:** the driver is identified by `sheet_id` + `driver_number` and the name is read from the sheet itself (driver 1 = `tech_sheets.driver_name`, others from `tech_sheet_drivers`), so an admin can never create a record for an arbitrary name. Blank names and numbers not on the sheet are refused. The route is admin-only (`requireAuth()`), POST-only, CSRF-checked.
- **Current season only:** the one-tap action, the inspector button and the competitor "Add gear record" link are only offered when the sheet's season (falling back to the current calendar year when the sheet has none) equals `gearSeasonNow()`. The helper enforces it server-side with the message "Gear can only be added for the current season."
- **No duplicates, clear refusals:** if the owner already has a record for that driver and season it is accepted instead of duplicating; if it is already accepted the action is refused with "This driver's gear has already been teched." In-person acceptance sends **no email** (matches the existing in-person actions).
- **Additive markup:** `renderGearChips($links, $audience)` keeps working unchanged (new third parameter is optional and defaults to today's behaviour); competitor chips still never show admin links; admin chips never show `gear.php` links. Existing renderer, page and roster signatures only gain optional trailing parameters.
- **Repo conventions:** LF-authored new PHP with a `// wcma-calculator/<file>` header comment; helpers are session-free; tests live in `wcma-calculator/tests/*Test.php`; existing files are changed with the Edit tool, never rewritten whole (the working copies of most existing files are CRLF: if an Edit anchor fails on CRLF, splice the change in preserving line endings and confirm the diff shows no whole-file line-ending churn); commit after each task with a subject line, a blank line, then the trailer; run PHP tests from `wcma-calculator/` with `php phpunit.phar` and JS tests with `node --test "tests/js/*.test.js"` (quoted glob).

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `wcma-calculator/tech-sheets.php` | modify | Form label "Driver name (Driver 1)" + team-name hint; owner chips get the sheet season |
| `wcma-calculator/tech-sheet-render.php` | modify | Header label "Driver 1:" |
| `wcma-calculator/gear-lib.php` | modify | `gearCreateAndAcceptInPerson()` |
| `wcma-calculator/gear-chips.php` | modify | Optional `$opts`: season gating, inspector one-tap form |
| `wcma-calculator/css/calculator.css` | modify | `.gear-inline-form` styles |
| `wcma-calculator/account.php` | modify | Pass each sheet's season to owner chips |
| `wcma-calculator/admin-tech-sheets.php` | modify | CSRF token + options on roster and review chips |
| `wcma-calculator/admin-gear.php` | modify | `handleGearCreateAccept()` |
| `wcma-calculator/admin.php` | modify | `gear-create-accept` route |
| `wcma-calculator/tests/DriverLabelTest.php` | create | Form label/hint source guard |
| `wcma-calculator/tests/TechSheetRenderTest.php` | modify | Header label test |
| `wcma-calculator/tests/GearCreateAcceptTest.php` | create | Helper behaviour |
| `wcma-calculator/tests/GearChipsActionsTest.php` | create | Renderer options behaviour |
| `wcma-calculator/tests/GearCreateAcceptSourceTest.php` | create | Route, handler and wiring guards |
| `wcma-calculator/tests/GearLinksSourceTest.php` | modify | Update three exact-string assertions for the new call shapes |

---

### Task 1: Driver 1 label, team-name hint, and sheet header wording

**Files:**
- Modify: `wcma-calculator/tech-sheets.php` (the `driver_name` label at ~line 345; a hint line before the existing gear-names hint at ~line 351)
- Modify: `wcma-calculator/tech-sheet-render.php` (~line 65)
- Test: `wcma-calculator/tests/DriverLabelTest.php` (create); `wcma-calculator/tests/TechSheetRenderTest.php` (add one test)

**Interfaces:**
- Consumes: the existing form (`renderTechSheetForm`) and `renderTechSheetHtml()`.
- Produces: label text `Driver name (Driver 1)` on the form; the hint `Driver 1 is the person driving. If you race as a team, put the team name in Entrant.` (always shown); header cell `<strong>Driver 1:</strong>` in the rendered sheet (view page, email and admin review all reuse it). The input keeps `id="driver_name" name="driver_name" required list="gear-names"`.

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/DriverLabelTest.php`:

```php
<?php
// wcma-calculator/tests/DriverLabelTest.php
//
// Source-level guard for the sheet form (tech-sheets.php needs config.php, so it cannot run under
// PHPUnit): the first driver field is labelled as a person and team names are pointed at Entrant.
use PHPUnit\Framework\TestCase;

final class DriverLabelTest extends TestCase
{
    private function src(string $file): string {
        return str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../' . $file));
    }

    public function testFormLabelsDriverOneAsAPerson(): void
    {
        $src = $this->src('tech-sheets.php');
        $this->assertStringContainsString('<label for="driver_name">Driver name (Driver 1)</label>', $src);
        $this->assertStringNotContainsString('Driver/Team Name', $src);
        $this->assertStringContainsString('name="driver_name" required list="gear-names"', $src);
    }

    public function testFormHintSendsTeamNamesToEntrant(): void
    {
        $src = $this->src('tech-sheets.php');
        $this->assertStringContainsString('<label for="entrant_name">Entrant</label>', $src);
        $this->assertStringContainsString('Driver 1 is the person driving. If you race as a team, put the team name in Entrant.', $src);
    }

    public function testNoBannedWordingInTheNewCopy(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed)\b/i', $this->src('tech-sheets.php'));
    }
}
```

In `wcma-calculator/tests/TechSheetRenderTest.php`, insert this test immediately before the line `    public function testReviewedSheetHasNoApprovalWording(): void`:

```php
    public function testHeaderLabelsTheFirstDriverAsDriverOne(): void
    {
        require_once __DIR__ . '/../tech-sheet-render.php';
        $html = renderTechSheetHtml($this->sampleSheet(), [], ['name' => 'Spring Sprint', 'event_date' => '2026-05-10']);
        $this->assertStringContainsString('<strong>Driver 1:</strong> Jane Racer', $html);
        $this->assertStringNotContainsString('Driver/Team', $html);
    }

```

- [ ] **Step 2: Run the tests to verify they fail**

Run (from `wcma-calculator/`): `php phpunit.phar --filter "DriverLabelTest|TechSheetRenderTest"`
Expected: `testFormLabelsDriverOneAsAPerson`, `testFormHintSendsTeamNamesToEntrant` and `testHeaderLabelsTheFirstDriverAsDriverOne` fail (the old label and header are still in place).

- [ ] **Step 3: Change the form label and add the hint**

In `wcma-calculator/tech-sheets.php` replace the label

```php
<div><label for="driver_name">Driver/Team Name</label><input type="text"
```
with
```php
<div><label for="driver_name">Driver name (Driver 1)</label><input type="text"
```
(keep the rest of that line unchanged). Then, directly above the existing line that starts

```php
      <?php if ($gearNames): ?><p class="form-hint">Pick a driver from your My Drivers list
```
insert:
```php
      <p class="form-hint">Driver 1 is the person driving. If you race as a team, put the team name in Entrant.</p>
```

- [ ] **Step 4: Change the rendered sheet header**

In `wcma-calculator/tech-sheet-render.php` replace `<strong>Driver/Team:</strong>` with `<strong>Driver 1:</strong>` (one occurrence, in the header table row that also holds the Entrant cell).

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php -l tech-sheets.php && php -l tech-sheet-render.php && php phpunit.phar`
Expected: two `No syntax errors detected`, then `OK` for the whole suite.

- [ ] **Step 6: Commit**

```bash
git add wcma-calculator/tech-sheets.php wcma-calculator/tech-sheet-render.php wcma-calculator/tests/DriverLabelTest.php wcma-calculator/tests/TechSheetRenderTest.php
git commit -m "feat(gear): label the first driver field as Driver 1 and point team names at Entrant"
```

---

### Task 2: `gearCreateAndAcceptInPerson()`

**Files:**
- Modify: `wcma-calculator/gear-lib.php` (append)
- Test: `wcma-calculator/tests/GearCreateAcceptTest.php` (create)

**Interfaces:**
- Consumes: `gearCreate()`, `gearAcceptInPerson()`, `gearNameNorm()`, `gearSeasonNow()` (this file); `db_find_gear_record()`, `db_get_gear_record()`, `db_get_user_gear_records()` (`db.php`).
- Produces: `gearCreateAndAcceptInPerson(PDO $pdo, array $sheet, array $drivers, int $driverNumber, int $reviewerUserId): array{ok: bool, error: ?string, id: ?int}`. `$sheet` is a `tech_sheets` row (needs `user_id`, `season`, `driver_name`); `$drivers` is `db_get_tech_sheet_drivers()` output (rows with `driver_number`, `driver_name`). Behaviour: name from the sheet (driver 1 or the matching additional driver, whitespace collapsed); blank or missing → `That driver is not on this sheet.`; sheet season (0/missing → current year) other than `gearSeasonNow()` → `Gear can only be added for the current season.`; existing record for (sheet owner, name, season) is accepted instead of duplicated; already accepted → `This driver's gear has already been teched.`; otherwise `gearCreate()` under the owner then accept, all in one transaction (safe when the caller already holds one). No email.

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/GearCreateAcceptTest.php`:

```php
<?php
// wcma-calculator/tests/GearCreateAcceptTest.php
require_once __DIR__ . '/../gear-lib.php';

use PHPUnit\Framework\TestCase;

final class GearCreateAcceptTest extends TestCase
{
    private function users(PDO $pdo): array {
        $owner = db_create_user($pdo, ['email' => 'captain@example.com', 'name' => 'Captain', 'password_hash' => 'x', 'google_id' => null]);
        $admin = db_create_user($pdo, ['email' => 'tech@example.com', 'name' => 'Tech', 'password_hash' => 'x', 'google_id' => null]);
        return [$owner, $admin];
    }

    private function sheet(int $owner, array $o = []): array {
        return array_merge(['id' => 1, 'user_id' => $owner, 'season' => gearSeasonNow(), 'driver_name' => 'Jane Racer'], $o);
    }

    public function testCreatesUnderTheSheetOwnerAndAcceptsInPersonForDriverOne(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);

        $r = gearCreateAndAcceptInPerson($pdo, $this->sheet($owner), [], 1, $admin);

        $this->assertTrue($r['ok'], (string)$r['error']);
        $row = db_get_gear_record($pdo, $r['id']);
        $this->assertSame($owner, (int)$row['owner_user_id'], 'owned by the sheet owner, not the acting admin');
        $this->assertSame('Jane Racer', $row['driver_name']);
        $this->assertSame(gearSeasonNow(), (int)$row['season']);
        $this->assertSame('accepted', $row['status']);
        $this->assertSame('in_person', $row['accepted_via']);
        $this->assertSame($admin, (int)$row['reviewed_by_user_id']);
        $this->assertCount(0, db_get_user_gear_records($pdo, $admin));
    }

    public function testAdditionalDriverIsFoundByNumberAndNormalised(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $drivers = [['driver_number' => 2, 'driver_name' => '  Sam   Coach ']];

        $r = gearCreateAndAcceptInPerson($pdo, $this->sheet($owner), $drivers, 2, $admin);

        $this->assertTrue($r['ok'], (string)$r['error']);
        $this->assertSame('Sam Coach', db_get_gear_record($pdo, $r['id'])['driver_name']);
    }

    public function testDriverNotOnTheSheetOrBlankIsRefusedAndCreatesNothing(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);

        $missing = gearCreateAndAcceptInPerson($pdo, $this->sheet($owner), [], 3, $admin);
        $blank = gearCreateAndAcceptInPerson($pdo, $this->sheet($owner, ['driver_name' => '   ']), [], 1, $admin);

        foreach ([$missing, $blank] as $r) {
            $this->assertFalse($r['ok']);
            $this->assertNull($r['id']);
            $this->assertStringContainsString('not on this sheet', $r['error']);
        }
        $this->assertCount(0, db_get_user_gear_records($pdo, $owner));
    }

    public function testExistingOpenRecordIsAcceptedInsteadOfDuplicated(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $existing = gearCreate($pdo, $owner, 'JANE  racer', 'WCMA-1', gearSeasonNow())['id'];

        $r = gearCreateAndAcceptInPerson($pdo, $this->sheet($owner), [], 1, $admin);

        $this->assertTrue($r['ok'], (string)$r['error']);
        $this->assertSame($existing, $r['id']);
        $this->assertCount(1, db_get_user_gear_records($pdo, $owner));
        $row = db_get_gear_record($pdo, $existing);
        $this->assertSame('accepted', $row['status']);
        $this->assertSame('WCMA-1', $row['licence_no']);
    }

    public function testAlreadyAcceptedIsRefusedCleanly(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        $this->assertTrue(gearCreateAndAcceptInPerson($pdo, $this->sheet($owner), [], 1, $admin)['ok']);

        $again = gearCreateAndAcceptInPerson($pdo, $this->sheet($owner), [], 1, $admin);

        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('already been teched', $again['error']);
        $this->assertCount(1, db_get_user_gear_records($pdo, $owner));
    }

    public function testPastSeasonSheetsAreRefused(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);

        $r = gearCreateAndAcceptInPerson($pdo, $this->sheet($owner, ['season' => gearSeasonNow() - 1]), [], 1, $admin);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('current season', $r['error']);
        $this->assertCount(0, db_get_user_gear_records($pdo, $owner));
    }

    public function testMissingSeasonFallsBackToTheCurrentYear(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);

        $r = gearCreateAndAcceptInPerson($pdo, $this->sheet($owner, ['season' => 0]), [], 1, $admin);

        $this->assertTrue($r['ok'], (string)$r['error']);
        $this->assertSame(gearSeasonNow(), (int)db_get_gear_record($pdo, $r['id'])['season']);
    }

    public function testTooLongNameIsRejectedAndLeavesNothingBehind(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);

        $r = gearCreateAndAcceptInPerson($pdo, $this->sheet($owner, ['driver_name' => str_repeat('x', 101)]), [], 1, $admin);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('too long', $r['error']);
        $this->assertCount(0, db_get_user_gear_records($pdo, $owner));
    }

    public function testDoesNotCommitInsideACallersTransaction(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);

        $pdo->beginTransaction();
        $r = gearCreateAndAcceptInPerson($pdo, $this->sheet($owner), [], 1, $admin);
        $this->assertTrue($r['ok'], (string)$r['error']);
        $this->assertTrue($pdo->inTransaction());
        $pdo->rollBack();

        $this->assertCount(0, db_get_user_gear_records($pdo, $owner));
    }

    public function testMessagesAvoidBannedWording(): void
    {
        $pdo = make_temp_pdo();
        [$owner, $admin] = $this->users($pdo);
        gearCreateAndAcceptInPerson($pdo, $this->sheet($owner), [], 1, $admin);
        $messages = [
            gearCreateAndAcceptInPerson($pdo, $this->sheet($owner), [], 1, $admin)['error'],
            gearCreateAndAcceptInPerson($pdo, $this->sheet($owner), [], 9, $admin)['error'],
            gearCreateAndAcceptInPerson($pdo, $this->sheet($owner, ['season' => 1999]), [], 1, $admin)['error'],
        ];
        foreach ($messages as $m) {
            $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', (string)$m);
        }
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter GearCreateAcceptTest`
Expected: `Call to undefined function gearCreateAndAcceptInPerson()` in every test.

- [ ] **Step 3: Add the helper**

Append to the end of `wcma-calculator/gear-lib.php`:

```php

/**
 * Inspector shortcut for a driver on a sheet who has no gear record yet: creates the record under
 * the SHEET OWNER's account for the sheet's season and accepts it in person, in one transaction.
 * An existing record for that owner/name/season is accepted instead of duplicated. Current season
 * only. The name always comes from the sheet, so no arbitrary name can be created.
 *
 * @param array $sheet    a tech_sheets row (user_id, season, driver_name)
 * @param array $drivers  db_get_tech_sheet_drivers() rows (driver_number, driver_name)
 * @return array{ok: bool, error: ?string, id: ?int}
 */
function gearCreateAndAcceptInPerson(PDO $pdo, array $sheet, array $drivers, int $driverNumber, int $reviewerUserId): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'id' => null];

    $name = null;
    if ($driverNumber === 1) {
        $name = (string)($sheet['driver_name'] ?? '');
    } else {
        foreach ($drivers as $d) {
            if ((int)$d['driver_number'] === $driverNumber) {
                $name = (string)$d['driver_name'];
                break;
            }
        }
    }
    $name = trim((string)preg_replace('/\s+/', ' ', (string)$name));
    if ($name === '') return $fail('That driver is not on this sheet.');

    $season = (int)($sheet['season'] ?? 0) ?: gearSeasonNow();
    if ($season !== gearSeasonNow()) return $fail('Gear can only be added for the current season.');

    $ownerId = (int)($sheet['user_id'] ?? 0);

    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $existing = db_find_gear_record($pdo, $ownerId, gearNameNorm($name), $season);
        if ($existing !== null) {
            $id = (int)$existing['id'];
        } else {
            $created = gearCreate($pdo, $ownerId, $name, '', $season);
            if (!$created['ok']) {
                if ($own) $pdo->rollBack();
                return $fail((string)$created['error']);
            }
            $id = (int)$created['id'];
        }
        $accepted = gearAcceptInPerson($pdo, $id, $reviewerUserId);
        if (!$accepted['ok']) {
            if ($own) $pdo->rollBack();
            return $fail((string)$accepted['error']);
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'error' => null, 'id' => $id];
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar`
Expected: `OK` for the whole suite, including `GearCreateAcceptTest` (10 tests).

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/gear-lib.php wcma-calculator/tests/GearCreateAcceptTest.php
git commit -m "feat(gear): add create-and-accept-in-person helper for inspectors"
```

---

### Task 3: Chip renderer options: season gating and the inspector one-tap form

**Files:**
- Modify: `wcma-calculator/gear-chips.php`
- Modify: `wcma-calculator/css/calculator.css` (append)
- Test: `wcma-calculator/tests/GearChipsActionsTest.php` (create)

**Interfaces:**
- Consumes: `gearSeasonNow()`, `gearStatusLabel()`, `gearStatusBadgeClass()` (`gear-lib.php`); `h()` (`view_helpers.php`).
- Produces: `renderGearChips(array $links, string $audience, array $opts = []): string`. `$opts` keys (all optional): `sheet_season` (int; `0`/absent = unrestricted), `csrf` (string), `sheet_id` (int), `hidden` (array name => value of extra hidden inputs). Rules: for a link with no gear record, the owner audience gets the "Add gear record" link only when the sheet season is unrestricted or current; the admin audience gets the one-tap form only when `csrf !== ''` and `sheet_id > 0` and the season is unrestricted or current. The form is `POST admin.php?action=gear-create-accept` with hidden `csrf_token`, `sheet_id`, `driver_number` (from the link) plus each `hidden` pair, and a button labelled `Create and accept gear in person`. Linked records render exactly as before. Unknown audiences still render admin-style with no competitor links and no button.

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/GearChipsActionsTest.php`:

```php
<?php
// wcma-calculator/tests/GearChipsActionsTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../gear-chips.php';

use PHPUnit\Framework\TestCase;

final class GearChipsActionsTest extends TestCase
{
    private const BUTTON = 'Create and accept gear in person';

    private function link(string $name, ?array $gear, int $number = 1): array {
        return [
            'driver_number' => $number, 'name' => $name, 'name_norm' => gearNameNorm($name), 'gear' => $gear,
            'status' => $gear !== null ? gearStatus($gear) : ['state' => 'none', 'via' => null],
        ];
    }

    private function gear(int $id): array {
        return ['id' => $id, 'season' => gearSeasonNow(), 'status' => 'open', 'photo_status' => null, 'accepted_via' => null];
    }

    private function adminOpts(array $o = []): array {
        return array_merge(['sheet_season' => gearSeasonNow(), 'csrf' => 'tok-1', 'sheet_id' => 12], $o);
    }

    public function testOwnerAddLinkIsHiddenOnPastSeasonSheets(): void
    {
        $html = renderGearChips([$this->link('Sam Coach', null)], 'owner', ['sheet_season' => gearSeasonNow() - 1]);
        $this->assertStringContainsString('Sam Coach: <span class="badge-pending">No gear record</span>', $html);
        $this->assertStringNotContainsString('Add gear record', $html);
        $this->assertStringNotContainsString('gear.php', $html);
    }

    public function testOwnerAddLinkStillShownForCurrentSeasonUnknownSeasonAndNoOptions(): void
    {
        $links = [$this->link('Sam Coach', null)];
        foreach ([['sheet_season' => gearSeasonNow()], ['sheet_season' => 0], []] as $opts) {
            $this->assertStringContainsString('<a href="gear.php?name=Sam%20Coach">Add gear record</a>', renderGearChips($links, 'owner', $opts));
        }
        $this->assertStringContainsString('Add gear record', renderGearChips($links, 'owner'));
    }

    public function testAdminGetsAOneTapFormForDriversWithNoRecord(): void
    {
        $html = renderGearChips([$this->link('Jane Racer', $this->gear(4)), $this->link('Sam Coach', null, 2)], 'admin', $this->adminOpts());

        $this->assertSame(1, substr_count($html, '<form '));
        $this->assertStringContainsString('<form method="post" action="admin.php?action=gear-create-accept" class="gear-inline-form">', $html);
        $this->assertStringContainsString('<input type="hidden" name="csrf_token" value="tok-1">', $html);
        $this->assertStringContainsString('<input type="hidden" name="sheet_id" value="12">', $html);
        $this->assertStringContainsString('<input type="hidden" name="driver_number" value="2">', $html);
        $this->assertStringContainsString('>' . self::BUTTON . '</button>', $html);
        $this->assertStringContainsString('Sam Coach: <span class="badge-pending">No gear record</span>', $html);
        $this->assertStringContainsString('href="admin.php?action=gear-record&amp;id=4"', $html);
    }

    public function testEveryDriverWithoutARecordGetsItsOwnForm(): void
    {
        $html = renderGearChips([$this->link('A', null, 1), $this->link('B', null, 2), $this->link('C', null, 3)], 'admin', $this->adminOpts());
        $this->assertSame(3, substr_count($html, '<form '));
        foreach ([1, 2, 3] as $n) {
            $this->assertStringContainsString('name="driver_number" value="' . $n . '"', $html);
        }
    }

    public function testExtraHiddenFieldsAndTokenAreEscaped(): void
    {
        $html = renderGearChips([$this->link('Sam Coach', null)], 'admin', $this->adminOpts([
            'csrf' => '"><x',
            'hidden' => ['back' => 'roster', 'a"b' => '<v>'],
        ]));

        $this->assertStringContainsString('name="back" value="roster"', $html);
        $this->assertStringContainsString('&lt;v&gt;', $html);
        $this->assertStringNotContainsString('"><x', $html);
        $this->assertStringNotContainsString('<v>', $html);
    }

    public function testAdminButtonNeedsBothTokenAndSheet(): void
    {
        $links = [$this->link('Sam Coach', null)];
        $withoutEither = [
            ['sheet_season' => gearSeasonNow()],
            $this->adminOpts(['csrf' => '']),
            $this->adminOpts(['sheet_id' => 0]),
        ];
        foreach ($withoutEither as $opts) {
            $html = renderGearChips($links, 'admin', $opts);
            $this->assertStringNotContainsString('<form', $html);
            $this->assertStringNotContainsString(self::BUTTON, $html);
        }
        $this->assertStringNotContainsString('<form', renderGearChips($links, 'admin'));
    }

    public function testNoButtonForPastSeasonOwnersOrDriversWithARecord(): void
    {
        $noRecord = [$this->link('Sam Coach', null)];

        $this->assertStringNotContainsString('<form', renderGearChips($noRecord, 'admin', $this->adminOpts(['sheet_season' => gearSeasonNow() - 1])));
        $this->assertStringNotContainsString('<form', renderGearChips($noRecord, 'owner', $this->adminOpts()));
        $this->assertStringNotContainsString('<form', renderGearChips([$this->link('Jane Racer', $this->gear(4))], 'admin', $this->adminOpts()));
    }

    public function testAdminOutputStillHasNoCompetitorLinks(): void
    {
        $html = renderGearChips([$this->link('Sam Coach', null)], 'admin', $this->adminOpts());
        $this->assertStringNotContainsString('gear.php', $html);
        $this->assertStringNotContainsString('Add gear record', $html);
    }

    public function testUnknownAudienceNeverGetsTheButton(): void
    {
        $this->assertStringNotContainsString('<form', renderGearChips([$this->link('Sam Coach', null)], 'bogus', $this->adminOpts()));
    }

    public function testCopyAvoidsBannedWording(): void
    {
        $html = renderGearChips([$this->link('Sam Coach', null)], 'admin', $this->adminOpts());
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', strip_tags($html));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter GearChipsActionsTest`
Expected: failures (the renderer takes no third parameter, so the past-season and form assertions fail; PHP silently ignores the extra argument).

- [ ] **Step 3: Replace the renderer**

In `wcma-calculator/gear-chips.php` replace the whole function (the docblock through the closing brace of `renderGearChips`) with the following, leaving the file header and the two `require_once` lines as they are. Add the small form helper above it.

```php
/** The inspector's one-tap form for a driver with no gear record (posts to admin.php). */
function gearChipCreateForm(string $csrf, int $sheetId, int $driverNumber, array $hidden): string {
    $out = '<form method="post" action="admin.php?action=gear-create-accept" class="gear-inline-form">'
        . '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">'
        . '<input type="hidden" name="sheet_id" value="' . $sheetId . '">'
        . '<input type="hidden" name="driver_number" value="' . $driverNumber . '">';
    foreach ($hidden as $name => $value) {
        $out .= '<input type="hidden" name="' . h((string)$name) . '" value="' . h((string)$value) . '">';
    }
    return $out . '<button type="submit" class="btn btn-secondary">Create and accept gear in person</button></form>';
}

/**
 * @param array $links    gearLinksForSheet() result
 * @param string $audience 'owner' (competitor pages) or 'admin' (inspector pages)
 * @param array{sheet_season?: int, csrf?: string, sheet_id?: int, hidden?: array<string,string>} $opts
 *   sheet_season: the sheet's season; the competitor "Add gear record" link and the inspector button
 *     are only offered when it is the current season (0 or absent = not restricted).
 *   csrf + sheet_id: admin only; when both are given a driver with no record gets a
 *     "Create and accept gear in person" form. hidden: extra hidden inputs for that form.
 */
function renderGearChips(array $links, string $audience, array $opts = []): string {
    if (!$links) return '';
    $sheetSeason = (int)($opts['sheet_season'] ?? 0);
    $seasonOk = $sheetSeason === 0 || $sheetSeason === gearSeasonNow();
    $csrf = (string)($opts['csrf'] ?? '');
    $sheetId = (int)($opts['sheet_id'] ?? 0);
    $hidden = is_array($opts['hidden'] ?? null) ? $opts['hidden'] : [];

    $html = '<ul class="gear-chips">';
    foreach ($links as $l) {
        $name = h($l['name']);
        $gear = $l['gear'];
        if ($gear === null) {
            $html .= '<li class="gear-chip">' . $name . ': <span class="badge-pending">No gear record</span>';
            if ($audience === 'owner' && $seasonOk) {
                $html .= ' <a href="gear.php?name=' . h(rawurlencode($l['name'])) . '">Add gear record</a>';
            } elseif ($audience === 'admin' && $seasonOk && $csrf !== '' && $sheetId > 0) {
                $html .= ' ' . gearChipCreateForm($csrf, $sheetId, (int)$l['driver_number'], $hidden);
            }
            $html .= '</li>';
            continue;
        }
        $label = gearStatusLabel($l['status'], (int)$gear['season']);
        $class = gearStatusBadgeClass($l['status']['state']);
        $href = $audience === 'owner'
            ? 'gear.php?action=pretech&amp;id=' . (int)$gear['id']
            : 'admin.php?action=gear-record&amp;id=' . (int)$gear['id'];
        $html .= '<li class="gear-chip">' . $name . ': <a class="' . h($class) . '" href="' . $href . '">' . h($label) . '</a></li>';
    }
    return $html . '</ul>';
}
```

- [ ] **Step 4: Append the styles**

Append to the end of `wcma-calculator/css/calculator.css` (preserving that file's line endings):

```css

.gear-inline-form { display: inline; margin-left: 0.4rem; }
.gear-inline-form .btn { padding: 0.15rem 0.5rem; font-size: 0.8rem; }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php -l gear-chips.php && php phpunit.phar`
Expected: `No syntax errors detected`, then `OK` for the whole suite, including `GearChipsActionsTest` (9 tests) and the unchanged `GearChipsTest`.

- [ ] **Step 6: Commit**

```bash
git add wcma-calculator/gear-chips.php wcma-calculator/css/calculator.css wcma-calculator/tests/GearChipsActionsTest.php
git commit -m "feat(gear): gate the add link by season and add the inspector one-tap form to gear chips"
```

---

### Task 4: Wire the route, roster, review page and competitor pages

**Files:**
- Modify: `wcma-calculator/admin-gear.php` (append `handleGearCreateAccept`)
- Modify: `wcma-calculator/admin.php` (route)
- Modify: `wcma-calculator/admin-tech-sheets.php` (roster CSRF + chip options; review page chip options)
- Modify: `wcma-calculator/tech-sheets.php` (~line 200) and `wcma-calculator/account.php` (~lines 166 and 188): pass the sheet season to owner chips
- Modify: `wcma-calculator/tests/GearLinksSourceTest.php` (three assertions)
- Test: `wcma-calculator/tests/GearCreateAcceptSourceTest.php` (create)

**Interfaces:**
- Consumes: Task 2 `gearCreateAndAcceptInPerson()`; Task 3 `renderGearChips(..., $opts)`; `db_get_tech_sheet()`, `db_get_tech_sheet_drivers()`, `current_user()`, `setFlash()`, `generateCsrfToken()`, `validateCsrfToken()`, `TECH_SHEET_FILTERS`.
- Produces: `handleGearCreateAccept(PDO $pdo): void` (reads POST `sheet_id`, `driver_number`, `back`, `filter`); route `admin.php?action=gear-create-accept` (admin only, POST, CSRF); `renderTechSheetsListPage(..., ?array $flash, string $csrf = '')`. Redirects: `back=sheet` returns to `admin.php?action=tech-sheet&id=<sheet_id>`; anything else returns to `admin.php?action=tech-sheets&event=<sheet event_id>&filter=<validated filter, default all>`. Flash: success `Gear accepted (teched in person).`, otherwise the helper's error; an unknown sheet flashes `Tech sheet not found.` and returns to the roster.

- [ ] **Step 1: Write the failing tests and update the three old assertions**

Create `wcma-calculator/tests/GearCreateAcceptSourceTest.php`:

```php
<?php
// wcma-calculator/tests/GearCreateAcceptSourceTest.php
//
// Source-level guards for the pages and route that need config.php and so cannot run under
// PHPUnit: the one-tap route is admin-only, POST-only and CSRF-checked, and the chips are wired
// with the options that make the button and the season gating work.
use PHPUnit\Framework\TestCase;

final class GearCreateAcceptSourceTest extends TestCase
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

    public function testRouteIsAdminOnlyPostOnlyAndCsrfChecked(): void
    {
        $admin = $this->src('admin.php');
        $this->assertMatchesRegularExpression("/case 'gear-create-accept':\\s+requireAuth\\(\\);/", $admin);
        $this->assertMatchesRegularExpression("/case 'gear-create-accept':[^}]*?REQUEST_METHOD[^}]*?validateCsrfToken[^}]*?handleGearCreateAccept\\(\\\$pdo\\);/s", $admin);
    }

    public function testHandlerReadsTheNameFromTheSheetNotFromTheRequest(): void
    {
        $handler = $this->body('admin-gear.php', 'handleGearCreateAccept');
        $this->assertStringContainsString('db_get_tech_sheet($pdo, $sheetId)', $handler);
        $this->assertStringContainsString('db_get_tech_sheet_drivers($pdo, $sheetId)', $handler);
        $this->assertStringContainsString('gearCreateAndAcceptInPerson($pdo, $sheet,', $handler);
        $this->assertStringContainsString('(int)$user[\'id\']', $handler);
        $this->assertStringNotContainsString("\$_POST['driver_name']", $handler);
        $this->assertStringContainsString('TECH_SHEET_FILTERS[$_POST[\'filter\']]', $handler);
    }

    public function testRosterPassesATokenAndOptionsToTheAdminChips(): void
    {
        $list = $this->body('admin-tech-sheets.php', 'handleTechSheetsList');
        $this->assertStringContainsString('getFlash(), generateCsrfToken()', $list);

        $page = $this->body('admin-tech-sheets.php', 'renderTechSheetsListPage');
        $this->assertStringContainsString('?array $flash, string $csrf = \'\'): void', $page);
        $this->assertStringContainsString("'csrf' => \$csrf, 'sheet_id' => (int)\$s['id']", $page);
        $this->assertStringContainsString("'back' => 'roster'", $page);
        $this->assertStringContainsString("'sheet_season' => (int)\$s['season']", $page);
    }

    public function testReviewPagePassesATokenAndOptionsToTheAdminChips(): void
    {
        $page = $this->body('admin-tech-sheets.php', 'renderTechSheetViewPage');
        $this->assertStringContainsString("'csrf' => \$csrf, 'sheet_id' => \$id", $page);
        $this->assertStringContainsString("'back' => 'sheet'", $page);
    }

    public function testOwnerChipsGetTheSheetSeason(): void
    {
        $this->assertStringContainsString("renderGearChips(\$gearLinks, 'owner', ['sheet_season' => (int)(\$sheet['season'] ?? 0)])", $this->src('tech-sheets.php'));
        $account = $this->src('account.php');
        $this->assertStringContainsString("renderGearChips(\$gearLinks[(int)\$sheet['id']] ?? [], 'owner', ['sheet_season' => (int)(\$sheet['season'] ?? 0)])", $account);
        $this->assertStringContainsString("renderGearChips(\$gearLinks[(int)\$ts['id']] ?? [], 'owner', ['sheet_season' => (int)(\$ts['season'] ?? 0)])", $account);
    }

    public function testNewCopyAvoidsBannedWording(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed)\b/i', $this->body('admin-gear.php', 'handleGearCreateAccept'));
    }
}
```

In `wcma-calculator/tests/GearLinksSourceTest.php` update three exact-string assertions so they match the new call shapes (prefix match, so the options array may follow):
1. In `testSheetViewShowsOwnerGearChipsFromTheUsersOwnRecords`: change `"renderGearChips(\$gearLinks, 'owner')"` to `"renderGearChips(\$gearLinks, 'owner', ["`.
2. In `testAdminRosterAttachesGearAndRendersAGearColumn`: change `"renderGearChips(\$row['gear_links'] ?? [], 'admin')"` to `"renderGearChips(\$row['gear_links'] ?? [], 'admin', ["`.
3. In `testAdminSheetReviewShowsTheOwnersGearChips`: change `"renderGearChips(\$gearLinks, 'admin')"` to `"renderGearChips(\$gearLinks, 'admin', ["`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter "GearCreateAcceptSourceTest|GearLinksSourceTest"`
Expected: `GearCreateAcceptSourceTest` fails (no route, no handler; `body()` reports `handleGearCreateAccept must exist`), and the three updated `GearLinksSourceTest` assertions fail until the call sites change.

- [ ] **Step 3: Add the handler**

Append to `wcma-calculator/admin-gear.php`:

```php

/** Inspector one-tap: a driver on a sheet has no gear record, so create it under the sheet owner's account and accept it in person. */
function handleGearCreateAccept(PDO $pdo): void {
    $sheetId = is_scalar($_POST['sheet_id'] ?? null) ? (int)$_POST['sheet_id'] : 0;
    $driverNumber = is_scalar($_POST['driver_number'] ?? null) ? (int)$_POST['driver_number'] : 0;
    $sheet = $sheetId > 0 ? db_get_tech_sheet($pdo, $sheetId) : null;
    if ($sheet === null) {
        setFlash('Tech sheet not found.', 'error');
        header('Location: admin.php?action=tech-sheets');
        exit;
    }

    $user = current_user();
    $result = gearCreateAndAcceptInPerson($pdo, $sheet, db_get_tech_sheet_drivers($pdo, $sheetId), $driverNumber, (int)$user['id']);
    setFlash($result['ok'] ? 'Gear accepted (teched in person).' : $result['error'], $result['ok'] ? 'success' : 'error');

    if (($_POST['back'] ?? '') === 'sheet') {
        header('Location: admin.php?action=tech-sheet&id=' . $sheetId);
    } else {
        $filter = is_string($_POST['filter'] ?? null) && isset(TECH_SHEET_FILTERS[$_POST['filter']]) ? $_POST['filter'] : 'all';
        header('Location: admin.php?action=tech-sheets&event=' . (int)$sheet['event_id'] . '&filter=' . rawurlencode($filter));
    }
    exit;
}
```

- [ ] **Step 4: Add the route**

In `wcma-calculator/admin.php`, directly after the `case 'gear-photos-send-back':` block (which ends with `handleGearAdminPhotosSendBack($pdo, (int)($_POST['id'] ?? 0));` and `break;`), add:

```php
    case 'gear-create-accept':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=tech-sheets'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleGearCreateAccept($pdo);
        break;

```

- [ ] **Step 5: Wire the roster and review page**

In `wcma-calculator/admin-tech-sheets.php` make four edits:

1. In `handleTechSheetsList`, replace `renderTechSheetsListPage($events, $eventId, $filter, techRosterFilter($rows, $filter), $counts, getFlash());` with
   `renderTechSheetsListPage($events, $eventId, $filter, techRosterFilter($rows, $filter), $counts, getFlash(), generateCsrfToken());`
2. Replace the signature `function renderTechSheetsListPage(array $events, int $eventId, string $filter, array $rows, array $counts, ?array $flash): void {` with
   `function renderTechSheetsListPage(array $events, int $eventId, string $filter, array $rows, array $counts, ?array $flash, string $csrf = ''): void {`
3. Replace `<td><?= renderGearChips($row['gear_links'] ?? [], 'admin') ?></td>` with
   `<td><?= renderGearChips($row['gear_links'] ?? [], 'admin', ['sheet_season' => (int)$s['season'], 'csrf' => $csrf, 'sheet_id' => (int)$s['id'], 'hidden' => ['back' => 'roster', 'filter' => $filter]]) ?></td>`
4. In `renderTechSheetViewPage`, replace `<?= renderGearChips($gearLinks, 'admin') ?>` with
   `<?= renderGearChips($gearLinks, 'admin', ['sheet_season' => (int)($sheet['season'] ?? 0), 'csrf' => $csrf, 'sheet_id' => $id, 'hidden' => ['back' => 'sheet']]) ?>`

- [ ] **Step 6: Wire the competitor pages**

1. `wcma-calculator/tech-sheets.php`: replace `renderGearChips($gearLinks, 'owner')` (the only occurrence, in the "Driver gear" block of the sheet view) with `renderGearChips($gearLinks, 'owner', ['sheet_season' => (int)($sheet['season'] ?? 0)])`.
2. `wcma-calculator/account.php`: replace `renderGearChips($gearLinks[(int)$sheet['id']] ?? [], 'owner')` with `renderGearChips($gearLinks[(int)$sheet['id']] ?? [], 'owner', ['sheet_season' => (int)($sheet['season'] ?? 0)])`, and `renderGearChips($gearLinks[(int)$ts['id']] ?? [], 'owner')` with `renderGearChips($gearLinks[(int)$ts['id']] ?? [], 'owner', ['sheet_season' => (int)($ts['season'] ?? 0)])`.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php -l admin-gear.php && php -l admin.php && php -l admin-tech-sheets.php && php -l tech-sheets.php && php -l account.php && php phpunit.phar`
Expected: five `No syntax errors detected`, then `OK` for the whole suite. The pages themselves need `config.php`; they are exercised in Task 5.

- [ ] **Step 8: Commit**

```bash
git add wcma-calculator/admin-gear.php wcma-calculator/admin.php wcma-calculator/admin-tech-sheets.php wcma-calculator/tech-sheets.php wcma-calculator/account.php wcma-calculator/tests/GearCreateAcceptSourceTest.php wcma-calculator/tests/GearLinksSourceTest.php
git commit -m "feat(gear): let inspectors create and accept a missing gear record from the roster and review page"
```

---

### Task 5: End-to-end verification

No product code changes; no commits. This proves the follow-ups in a real browser: the form label and hint, the inspector one-tap on the roster and on the review page (creating the record under the sheet owner, not the admin), the roster filters after acceptance, clean refusals (repeat tap, driver not on the sheet, past season, unknown sheet, bad CSRF, non-admin), no email, and season gating of the competitor "Add gear record" link.

**Database and email safety (critical, learned in earlier phases):** use ONLY the scratch DB `scratch/tech3c-e2e.db`, never `wcma-calculator/data/submissions.db` (real local data: users=0, submissions=1, tech_sheets=0, events=0). Every entry point loads `scratch/tech3c-prepend.php` first; start the server with the ABSOLUTE prepend path; the harness `require_once`s the prepend itself (`php -S` skips `auto_prepend_file` for router-served requests). The prepend defines `WCMA_MAIL_LOG`, so no email is ever sent over SMTP. Verify the default DB counts before and after. The scripts follow the phase 3b ones (`scratch/tech3b-*`, port 8127); this run uses port 8128.

**Files:**
- Create (scratch, untracked): `scratch/tech3c-prepend.php`, `scratch/tech3c-router.php`, `scratch/tech3c-harness.php`, `scratch/tech3c-e2e.js`

- [ ] **Step 1: Record the default database state**

Run (repo root): `php -r '$p=new PDO("sqlite:wcma-calculator/data/submissions.db"); foreach(["users","submissions","tech_sheets","events"] as $t) echo $t,"=",$p->query("SELECT COUNT(*) FROM $t")->fetchColumn(),"\n";'`
Note the four counts for Step 6.

- [ ] **Step 2: Create the harness files**

`scratch/tech3c-prepend.php`:

```php
<?php
if (!defined('DB_PATH')) {
    define('DB_PATH', 'C:/dev/wcmaclasscalc/scratch/tech3c-e2e.db');
}
if (!defined('WCMA_MAIL_LOG')) {
    define('WCMA_MAIL_LOG', 'C:/dev/wcmaclasscalc/scratch/tech3c-mail.log');
}
```

`scratch/tech3c-router.php` (copy of `tech3b-router.php` with the harness file name changed):

```php
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/harness') {
    require __DIR__ . '/tech3c-harness.php';
    return true;
}
return false;
```

`scratch/tech3c-harness.php` (copy of `tech3b-harness.php` with the prepend/title names changed and this seeding block; the user, submission and sheet helper functions are identical). The seed creates no gear records at all:

```php
if (!$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn()) {
    $year = (int)date('Y');
    $eventId = db_create_event($pdo, 'Season Finale', $year . '-11-15', null);          // event 1
    $oldEventId = db_create_event($pdo, 'Last Year Finale', ($year - 1) . '-11-15', null); // event 2

    // Sheet 1: owner's endurance sheet, car already accepted in person, drivers Jane Racer and Sam Coach.
    $ownerSub = harnessSubmission($pdo, $ownerId, 'owner@example.com', 'Mazda');
    $sheetA = harnessSheet($pdo, $ownerSub, $ownerId, $eventId, 'endurance', 'Jane Racer', '42');
    db_add_tech_sheet_driver($pdo, $sheetA, 2, 'Sam Coach', '{}');
    $pdo->prepare("UPDATE tech_sheets SET status = 'teched', accepted_via = 'in_person' WHERE id = :id")->execute([':id' => $sheetA]);

    // Sheet 2: another user's standard sheet, nothing accepted.
    $otherSub = harnessSubmission($pdo, $otherId, 'other@example.com', 'Honda');
    harnessSheet($pdo, $otherSub, $otherId, $eventId, 'standard', 'Solo Driver', '7');

    // Sheet 3: owner's sheet from LAST season (forced), for the current-season-only rules.
    $oldSub = harnessSubmission($pdo, $ownerId, 'owner@example.com', 'Subaru');
    $sheetC = harnessSheet($pdo, $oldSub, $ownerId, $oldEventId, 'standard', 'Old Timer', '99');
    $pdo->prepare('UPDATE tech_sheets SET season = :s WHERE id = :id')->execute([':s' => $year - 1, ':id' => $sheetC]);
}
```

`scratch/tech3c-e2e.js`:

```js
const { chromium } = require('C:/dev/wcmaclasscalc/scratch/tech-sheet-mockups/node_modules/playwright');
const assert = require('node:assert');
const fs = require('node:fs');

const BASE = 'http://localhost:8128';
const MAIL_LOG = 'C:/dev/wcmaclasscalc/scratch/tech3c-mail.log';
const BTN = 'button:has-text("Create and accept gear in person")';
const ROWS = '#tech-sheets-table tbody tr:not(:has(td.empty-row))';

async function signIn(browser, who) {
    const ctx = await browser.newContext({ viewport: { width: 1000, height: 900 } });
    const page = await ctx.newPage();
    await page.goto(BASE + '/harness?as=' + who);
    await page.waitForSelector('text=harness ready');
    return page;
}

// POSTs to the one-tap route from an already-loaded admin page, reusing its CSRF token.
function post(page, fields, token) {
    return page.evaluate(async ([fields, token]) => {
        const f = new FormData();
        f.append('csrf_token', token ?? document.querySelector('input[name="csrf_token"]').value);
        for (const [k, v] of Object.entries(fields)) f.append(k, v);
        const r = await fetch('admin.php?action=gear-create-accept', { method: 'POST', body: f });
        return { status: r.status, url: r.url, text: await r.text() };
    }, [fields, token]);
}

(async () => {
    if (fs.existsSync(MAIL_LOG)) fs.unlinkSync(MAIL_LOG);
    const browser = await chromium.launch();
    const owner = await signIn(browser, 'owner');
    const admin = await signIn(browser, 'admin');
    const other = await signIn(browser, 'other');
    const year = new Date().getFullYear();

    // ── Form label and hint; sheet header wording ────────────────────────────
    await owner.goto(BASE + '/tech-sheets.php?action=new&submission_id=1');
    await owner.waitForSelector('#driver_name');
    assert.strictEqual((await owner.textContent('label[for="driver_name"]')).trim(), 'Driver name (Driver 1)');
    assert.match(await owner.textContent('body'), /put the team name in Entrant/i);
    await owner.goto(BASE + '/tech-sheets.php?action=view&id=1');
    const viewText = await owner.textContent('body');
    assert.match(viewText, /Driver 1:/);
    assert.doesNotMatch(viewText, /Driver\/Team/);
    console.log('form label "Driver name (Driver 1)", team-name hint, sheet header "Driver 1:"');

    // ── Competitor "Add gear record" only for current-season sheets ──────────
    await owner.goto(BASE + '/account.php');
    assert.strictEqual(await owner.locator('a:has-text("Add gear record")').count(), 2, 'Jane and Sam on the current-season sheet');
    await owner.goto(BASE + '/tech-sheets.php?action=view&id=3');
    await owner.waitForSelector('.gear-chips');
    assert.match(await owner.textContent('.gear-chips'), /Old Timer: No gear record/);
    assert.strictEqual(await owner.locator('.gear-chips a:has-text("Add gear record")').count(), 0, 'past-season sheet offers no add link');
    await other.goto(BASE + '/account.php');
    assert.strictEqual(await other.locator('a:has-text("Add gear record")').count(), 1, 'Solo Driver, current season');
    console.log('competitor add link: shown for current season, hidden for last season');

    // ── Roster: buttons for current-season drivers only ──────────────────────
    await admin.goto(BASE + '/admin.php?action=tech-sheets&event=1&filter=all');
    assert.strictEqual(await admin.locator(ROWS).count(), 2);
    const rowA = admin.locator('#tech-sheets-table tbody tr', { hasText: 'Jane Racer' });
    const rowSolo = admin.locator('#tech-sheets-table tbody tr', { hasText: 'Solo Driver' });
    assert.strictEqual(await rowA.locator(BTN).count(), 2);
    assert.strictEqual(await rowSolo.locator(BTN).count(), 1);
    await admin.goto(BASE + '/admin.php?action=tech-sheets&event=0');
    assert.strictEqual(await admin.locator(ROWS).count(), 3);
    const rowC = admin.locator('#tech-sheets-table tbody tr', { hasText: 'Old Timer' });
    assert.match(await rowC.textContent(), /Old Timer: No gear record/);
    assert.strictEqual(await rowC.locator(BTN).count(), 0, 'no button on a past-season sheet');
    console.log('roster: 2 buttons on the 2-driver sheet, 1 on the solo sheet, none on last season');

    // ── One tap on the roster: Jane ──────────────────────────────────────────
    await admin.goto(BASE + '/admin.php?action=tech-sheets&event=1&filter=all');
    await Promise.all([admin.waitForNavigation(), rowA.locator(BTN).first().click()]);
    assert.match(admin.url(), /event=1/);
    assert.match(admin.url(), /filter=all/);
    assert.match(await admin.textContent('body'), /Gear accepted \(teched in person\)/);
    let text = await rowA.textContent();
    assert.match(text, new RegExp('Jane Racer: Gear teched ' + year));
    assert.match(text, /Sam Coach: No gear record/);
    assert.strictEqual(await rowA.locator(BTN).count(), 1);
    await owner.goto(BASE + '/gear.php');
    assert.match(await owner.textContent('#gear-table'), /Jane Racer/);
    assert.match(await owner.textContent('#gear-table'), /Gear teched/);
    await admin.goto(BASE + '/gear.php');
    assert.match(await admin.textContent('body'), /No drivers yet/, 'the record belongs to the sheet owner, not the admin');
    console.log('roster tap: Jane created under the owner and teched in person');

    // ── One tap on the review page: Sam ──────────────────────────────────────
    await admin.goto(BASE + '/admin.php?action=tech-sheets&event=1&filter=all');
    await admin.click('#tech-sheets-table tbody tr:has-text("Jane Racer") td.actions a');
    await admin.waitForSelector('.gear-chips');
    assert.strictEqual(await admin.locator('.gear-chips ' + BTN).count(), 1);
    await Promise.all([admin.waitForNavigation(), admin.click('.gear-chips ' + BTN)]);
    assert.match(admin.url(), /action=tech-sheet&id=1/);
    assert.match(await admin.textContent('body'), /Gear accepted \(teched in person\)/);
    const chips = await admin.textContent('.gear-chips');
    assert.match(chips, /Jane Racer: Gear teched/);
    assert.match(chips, /Sam Coach: Gear teched/);
    assert.strictEqual(await admin.locator('.gear-chips ' + BTN).count(), 0);
    await owner.goto(BASE + '/account.php');
    assert.match(await owner.textContent('body'), new RegExp('Sam Coach: Gear teched ' + year));
    console.log('review-page tap: Sam created under the owner and teched in person');

    // ── Roster filters after both drivers are teched ─────────────────────────
    await admin.goto(BASE + '/admin.php?action=tech-sheets&event=1&filter=accepted');
    assert.strictEqual(await admin.locator(ROWS).count(), 1, 'sheet 1: car and both drivers accepted');
    await admin.goto(BASE + '/admin.php?action=tech-sheets&event=1&filter=needs_tech');
    assert.strictEqual(await admin.locator(ROWS).count(), 1, 'only the solo sheet still needs tech');

    // ── Clean refusals (replayed POSTs from the accepted review page) ────────
    await admin.goto(BASE + '/admin.php?action=tech-sheet&id=1');
    let r = await post(admin, { sheet_id: '1', driver_number: '2', back: 'sheet' });
    assert.match(r.text, /already been teched/);
    r = await post(admin, { sheet_id: '1', driver_number: '5', back: 'sheet' });
    assert.match(r.text, /not on this sheet/);
    r = await post(admin, { sheet_id: '3', driver_number: '1', back: 'sheet' });
    assert.match(r.text, /current season/);
    r = await post(admin, { sheet_id: '999', driver_number: '1' });
    assert.match(r.text, /Tech sheet not found/);
    r = await post(admin, { sheet_id: '1', driver_number: '2' }, 'bad-token');
    assert.strictEqual(r.status, 403);
    await owner.goto(BASE + '/gear.php');
    const ownerTry = await owner.evaluate(async () => {
        const f = new FormData();
        f.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
        f.append('sheet_id', '2'); f.append('driver_number', '1');
        const res = await fetch('admin.php?action=gear-create-accept', { method: 'POST', body: f });
        return res.url;
    });
    assert.match(ownerTry, /car-classing/, 'a non-admin is turned away');
    await admin.goto(BASE + '/admin.php?action=tech-sheets&event=1&filter=all');
    assert.strictEqual(await rowSolo.locator(BTN).count(), 1, 'nothing was created for the solo sheet');
    console.log('refusals: repeat tap, driver not on sheet, past season, unknown sheet, bad CSRF, non-admin');

    // ── Solo Driver: created under the other user's account ──────────────────
    await Promise.all([admin.waitForNavigation(), rowSolo.locator(BTN).click()]);
    assert.match(await admin.textContent('body'), /Gear accepted \(teched in person\)/);
    await other.goto(BASE + '/account.php');
    assert.match(await other.textContent('body'), new RegExp('Solo Driver: Gear teched ' + year));
    assert.strictEqual(await other.locator('a:has-text("Add gear record")').count(), 0);
    await admin.goto(BASE + '/admin.php?action=gear');
    assert.strictEqual(await admin.locator('#gear-admin-table tbody tr:not(:has(td.empty-row))').count(), 3, 'Jane, Sam and Solo');
    await admin.goto(BASE + '/admin.php?action=tech-sheets&event=1&filter=accepted');
    assert.strictEqual(await admin.locator(ROWS).count(), 1, 'solo car is still not accepted');

    assert.ok(!fs.existsSync(MAIL_LOG) || fs.readFileSync(MAIL_LOG, 'utf8').trim() === '', 'in-person acceptance sends no email');

    await browser.close();
    console.log('E2E OK');
})().catch(e => { console.error(e); process.exit(1); });
```

- [ ] **Step 3: Start the server (port 8128, scratch DB and mail log only)**

Run (repo root, in the background): `php -d auto_prepend_file=C:/dev/wcmaclasscalc/scratch/tech3c-prepend.php -S localhost:8128 -t wcma-calculator scratch/tech3c-router.php`
Verify: `curl -s -o /dev/null -w "%{http_code}" "http://localhost:8128/harness?as=admin"` returns `200`; `scratch/tech3c-e2e.db` now exists; the default DB counts still match Step 1.

- [ ] **Step 4: Run the end-to-end script**

Run: `node scratch/tech3c-e2e.js`
Expected: output ending with `E2E OK`. If a step fails, decide whether the scratch script or the product is wrong: fix scratch-script mistakes (selectors, timing, sheet ids if the fresh DB numbers them differently) in the scratch scripts only, and report genuine product defects with evidence (file, line, expected vs actual) instead of editing product code in this task.

- [ ] **Step 5: Stop the server and clean up**

Stop the PHP server (find the listener on 8128 with `netstat -ano | grep 8128 | grep LISTENING`, kill that process id, confirm the port is free). Remove `scratch/tech3c-e2e.db*` and `scratch/tech3c-mail.log`. This run creates no photo uploads; confirm no new folders appeared under `wcma-calculator/uploads/`. `git status --short` must show only the pre-existing `?? .htaccess.server` and `?? scratch/`.

- [ ] **Step 6: Verify the default database and run the full regression**

Re-run the Step 1 command: the four counts must match (and the default DB has no gear records). Then from `wcma-calculator/` run `php phpunit.phar` (all tests OK) and `node --test "tests/js/*.test.js"` (`# fail 0`). Nothing to commit for this task.

---

## Self-Review Notes

- **Scope coverage (user-approved suggestions):** (1) relabel to "Driver name (Driver 1)" with the team-name hint and the matching sheet header wording (Task 1); the Entrant field was confirmed to exist (`entrant_name`, form line "Entrant"), so no schema change is needed and the name-suggestion datalist is untouched. (2) Inspector one-tap on the roster Gear column and the review page, creating under the sheet owner's account and accepting in person, with clean refusals (Tasks 2-4). (3) The competitor "Add gear record" link is hidden for sheets whose season is not the current one (Tasks 3-4).
- **Rulings recorded here for the reviewer:** (a) the inspector one-tap and its button are also current-season-only, not just the competitor link, because creating and accepting a retroactive record for a past season would produce data that no current process reads; cost if wrong: an inspector cannot retro-fix an old season from the roster (existing records can still be reviewed on the Gear tab). (b) The action reads the driver name from the sheet by `driver_number`, never from the request, so an admin cannot create arbitrary names. (c) No confirmation dialog on the one-tap: it is deliberately a single tap at the track and is reversible from the existing "Revoke acceptance" on the gear record page. (d) The redirect keeps the roster's event and filter, and the flash text mirrors the existing "Sheet accepted (teched in person)".
- **Type consistency:** `renderGearChips($links, $audience, $opts)` keeps every existing 2-argument call working; the option keys (`sheet_season`, `csrf`, `sheet_id`, `hidden`) are produced by Task 4 exactly as Task 3 consumes them; the link shape's existing `driver_number` supplies the form's `driver_number`; `gearCreateAndAcceptInPerson()`'s `$sheet` and `$drivers` arguments are the `db_get_tech_sheet()` and `db_get_tech_sheet_drivers()` results the handler loads.
- **Not addressed here (deferred minors from the phase 3b review):** SQLite bound-variable limit in `db_get_drivers_for_sheets()` for very large "All events" rosters (chunk in batches of ~500 if rosters grow); the brittle `body()` function slicers in the source-level tests (they cut at the next line starting `function `); name suggestions on a brand-new sheet use the current year rather than the chosen event's season; the `gearLinksForSheet()` `?: gearSeasonNow()` season fallback comment/consistency with the roster's per-sheet season query; the broad whole-file banned-word regexes in the older source tests.
- **Known limits:** the admin and competitor pages and handlers load `config.php`, so they are verified by source-level tests and the browser run (Task 5) rather than PHPUnit page renders; all decisions live in the tested helper and renderer.
