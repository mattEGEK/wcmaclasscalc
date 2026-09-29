# Clubs List and UX Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admins keep a list of clubs (name + MotorsportReg link) that events use as their host club, so the tech sheet's "Register" step names the club and links to it; plus seven small UX fixes.

**Architecture:** A `clubs` table with pure validation in a new `clubs-lib.php`, an admin tab built like Season links, and the existing `events.host_club` code reused for summer events. The UX fixes are local edits in the tech sheet, garage, calculator CSS and admin markup, each pinned by PHPUnit, node tests or the phone audit.

**Tech Stack:** PHP 8.3, SQLite, vanilla JS, CSS; PHPUnit (`php phpunit.phar`), node tests (`node --test tests/js/*.test.js`, unquoted glob), phone audit (`bash wcma-calculator/tests/ux/run-audit.sh` from the repo root).

**Spec:** `docs/superpowers/specs/2026-09-29-clubs-and-ux-polish-design.md`

## Global Constraints

- Club code: 2–12 characters of A–Z, 0–9 and `-` (upper-cased on save); fixed once created. Name 1–120 characters. Link blank or `https://…` (`http://` refused). No delete: inactive instead.
- Ice events may only use `ICE_CLUBS` codes; summer events any active club or none.
- Register step copy: "The hub doesn't register you. Register for <event> with the <club name>." + button "Register on MotorsportReg ↗" (`target="_blank" rel="noopener"`) when a link exists; no club → "with the host club".
- Co-driver messages: "Enter Driver N's name." / "Enter the co-driver's name."
- Buttons follow Phase B: primary red, secondary outlined, sentence case, ≥ 44px.
- Branch `ux-polish`; commit messages end with the two attribution lines used on this project.
- Baseline: PHPUnit 947, node 51, audit all pass.

## Review Focus

1. Existing events (no clubs table yet) keep working after deploy: the table is created and NASCC/WSCC seeded on first page load. Pinned in Task 1.
2. An ice event can't be given a non-ice club through the new row picker. Pinned in Task 2.
3. A club link is only ever shown as an `https://` link, escaped. Pinned in Task 1/3.
4. Deactivating a club doesn't break events that already use it (register step still names it). Pinned in Task 3.
5. The calculator's guest flows (save nudge, dismiss) still work after its restyle. Checked by screenshot in Task 5.

---

### Task 1: Clubs data

**Files:** Create `wcma-calculator/clubs-lib.php`, `wcma-calculator/tests/ClubsTest.php`. Modify `wcma-calculator/db.php`.

**Interfaces (produces):**
- `const DB_ICE_CLUB_SEED = ['NASCC' => 'Northern Alberta Sports Car Club', 'WSCC' => 'Winnipeg Sports Car Club'];` (db.php)
- `db_get_clubs(PDO $pdo, bool $activeOnly = false): array` (ordered by name), `db_get_club(PDO $pdo, string $code): ?array`, `db_create_club(PDO $pdo, string $code, string $name, string $url): void`, `db_update_club(PDO $pdo, string $code, string $name, string $url, bool $active): void`
- `clubValidate(string $code, string $name, string $url): array{ok: bool, error: ?string, code: string, name: string, url: string}` (clubs-lib.php)
- `clubForEvent(?array $clubRow, ?array $event): ?array{name: string, url: string}` (clubs-lib.php)

- [ ] **Step 1: Failing tests** — `tests/ClubsTest.php`:

```php
<?php
// wcma-calculator/tests/ClubsTest.php
require_once __DIR__ . '/../clubs-lib.php';
require_once __DIR__ . '/../ice-rules.php';

use PHPUnit\Framework\TestCase;

final class ClubsTest extends TestCase
{
    public function testTableIsCreatedAndIceClubsSeededOnce(): void
    {
        $pdo = make_temp_pdo();
        $codes = array_column(db_get_clubs($pdo), 'code');
        $this->assertSame(['NASCC', 'WSCC'], $codes);
        $this->assertSame('', db_get_club($pdo, 'NASCC')['msr_url']);
        db_update_club($pdo, 'NASCC', 'NASCC Ice', 'https://msr.example/nascc', true);
        db_init($pdo);   // runs again on every request
        $this->assertSame('NASCC Ice', db_get_club($pdo, 'NASCC')['name']);
        $this->assertCount(2, db_get_clubs($pdo));
    }

    public function testSeedMatchesTheIceRules(): void
    {
        foreach (DB_ICE_CLUB_SEED as $code => $name) $this->assertSame(iceClubLabel($code), $name);
        $this->assertSame(iceClubCodes(), array_keys(DB_ICE_CLUB_SEED));
    }

    public function testCreateUpdateAndActiveFilter(): void
    {
        $pdo = make_temp_pdo();
        db_create_club($pdo, 'ESCC', 'Edmonton Sports Car Club', 'https://msr.example/escc');
        db_update_club($pdo, 'WSCC', 'Winnipeg Sports Car Club', '', false);
        $this->assertSame(['ESCC', 'NASCC'], array_column(db_get_clubs($pdo, true), 'code'));
        $this->assertNull(db_get_club($pdo, 'NOPE'));
    }

    public function testValidation(): void
    {
        $ok = clubValidate(' escc ', ' Edmonton  Sports Car Club ', ' https://msr.example/escc ');
        $this->assertSame(['ok' => true, 'error' => null, 'code' => 'ESCC', 'name' => 'Edmonton Sports Car Club', 'url' => 'https://msr.example/escc'], $ok);
        $this->assertTrue(clubValidate('CAS', 'Calgary Auto Sports', '')['ok']);
        $this->assertSame('Enter a short code of 2 to 12 letters, numbers or dashes.', clubValidate('E', 'x', '')['error']);
        $this->assertSame('Enter a short code of 2 to 12 letters, numbers or dashes.', clubValidate('ES CC', 'x', '')['error']);
        $this->assertSame('Enter the club name (120 characters at most).', clubValidate('ESCC', '  ', '')['error']);
        $this->assertSame('Enter the MotorsportReg link as a full address starting with https://, or leave it blank.', clubValidate('ESCC', 'x', 'http://msr.example')['error']);
        $this->assertSame('Enter the MotorsportReg link as a full address starting with https://, or leave it blank.', clubValidate('ESCC', 'x', 'javascript:alert(1)')['error']);
    }

    public function testClubForEvent(): void
    {
        $this->assertNull(clubForEvent(null, ['discipline' => 'summer', 'host_club' => null]));
        $this->assertSame(['name' => 'Edmonton Sports Car Club', 'url' => 'https://msr.example/escc'],
            clubForEvent(['code' => 'ESCC', 'name' => 'Edmonton Sports Car Club', 'msr_url' => 'https://msr.example/escc', 'active' => 0], ['discipline' => 'summer', 'host_club' => 'ESCC']));
        // Ice event whose club row is missing: the ice rules name, no link.
        $this->assertSame(['name' => 'Northern Alberta Sports Car Club', 'url' => ''], clubForEvent(null, ['discipline' => 'ice', 'host_club' => 'NASCC']));
        // A stored link that isn't https is never shown.
        $this->assertSame('', clubForEvent(['code' => 'X', 'name' => 'X', 'msr_url' => 'http://x', 'active' => 1], ['host_club' => 'X'])['url']);
    }
}
```

Run `php phpunit.phar --filter ClubsTest` — expected FAIL (missing file/functions).

- [ ] **Step 2: `db.php`** — in `db_init`, after the events/host_club migrations:

```php
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS clubs (
            code       TEXT PRIMARY KEY,
            name       TEXT NOT NULL,
            msr_url    TEXT NOT NULL DEFAULT '',
            active     INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL
        )
    ");
    // Clubs spec 2026-09-29 §1: the ice clubs exist from the start; admin edits are never overwritten.
    $seed = $pdo->prepare("INSERT OR IGNORE INTO clubs (code, name, msr_url, active, created_at) VALUES (:c, :n, '', 1, :t)");
    foreach (DB_ICE_CLUB_SEED as $code => $name) $seed->execute([':c' => $code, ':n' => $name, ':t' => date('Y-m-d H:i:s')]);
```

and the constant plus the four functions (near the season links functions):

```php
// ── Clubs ─────────────────────────────────────────────────────────────────────
/** The ice clubs seeded into `clubs` (their names match ice-rules.php ICE_CLUBS; ClubsTest checks). */
const DB_ICE_CLUB_SEED = ['NASCC' => 'Northern Alberta Sports Car Club', 'WSCC' => 'Winnipeg Sports Car Club'];

function db_get_clubs(PDO $pdo, bool $activeOnly = false): array {
    return $pdo->query("SELECT * FROM clubs" . ($activeOnly ? " WHERE active = 1" : "") . " ORDER BY name COLLATE NOCASE ASC")->fetchAll();
}

function db_get_club(PDO $pdo, string $code): ?array {
    $stmt = $pdo->prepare("SELECT * FROM clubs WHERE code = :c");
    $stmt->execute([':c' => $code]);
    return $stmt->fetch() ?: null;
}

function db_create_club(PDO $pdo, string $code, string $name, string $url): void {
    $pdo->prepare("INSERT INTO clubs (code, name, msr_url, active, created_at) VALUES (:c, :n, :u, 1, :t)")
        ->execute([':c' => $code, ':n' => $name, ':u' => $url, ':t' => date('Y-m-d H:i:s')]);
}

function db_update_club(PDO $pdo, string $code, string $name, string $url, bool $active): void {
    $pdo->prepare("UPDATE clubs SET name = :n, msr_url = :u, active = :a WHERE code = :c")
        ->execute([':c' => $code, ':n' => $name, ':u' => $url, ':a' => $active ? 1 : 0]);
}
```

Note: `testCreateUpdateAndActiveFilter` expects ESCC before NASCC by name ("Edmonton…" < "Northern…"): order is by name.

- [ ] **Step 3: `clubs-lib.php`**

```php
<?php
// wcma-calculator/clubs-lib.php
//
// Host clubs (clubs spec 2026-09-29 §1): validation and how an event's club is shown. Pure.
// Callers must have loaded ice-rules.php (iceClubLabel()) for clubForEvent().

const CLUB_URL_ERROR = 'Enter the MotorsportReg link as a full address starting with https://, or leave it blank.';

/** @return array{ok: bool, error: ?string, code: string, name: string, url: string} */
function clubValidate(string $code, string $name, string $url): array {
    $code = strtoupper(trim($code));
    $name = trim((string)preg_replace('/\s+/', ' ', $name));
    $url = trim($url);
    $out = ['ok' => false, 'error' => null, 'code' => $code, 'name' => $name, 'url' => $url];
    if (!preg_match('/^[A-Z0-9-]{2,12}$/', $code)) return ['error' => 'Enter a short code of 2 to 12 letters, numbers or dashes.'] + $out;
    if ($name === '' || mb_strlen($name, 'UTF-8') > 120) return ['error' => 'Enter the club name (120 characters at most).'] + $out;
    if ($url !== '' && !clubUrlOk($url)) return ['error' => CLUB_URL_ERROR] + $out;
    return ['ok' => true] + $out;
}

function clubUrlOk(string $url): bool {
    return filter_var($url, FILTER_VALIDATE_URL) !== false && strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https';
}

/**
 * The club to name on an event's register step: its row (active or not — the event already uses it),
 * or for an ice event without a row, the ice rules name. Null when the event has no club.
 * @return ?array{name: string, url: string}
 */
function clubForEvent(?array $clubRow, ?array $event): ?array {
    if ($clubRow !== null) {
        $url = (string)($clubRow['msr_url'] ?? '');
        return ['name' => (string)$clubRow['name'], 'url' => clubUrlOk($url) ? $url : ''];
    }
    $code = (string)($event['host_club'] ?? '');
    if ($code === '' || ($event['discipline'] ?? 'summer') !== 'ice') return null;
    $label = iceClubLabel($code);
    return $label !== null ? ['name' => $label, 'url' => ''] : null;
}
```

Adjust the `ok` array order to match the test (`['ok' => true] + $out` yields keys ok, error, code, name, url — matches).

- [ ] **Step 4:** Run `php phpunit.phar --filter ClubsTest` — PASS; then `php phpunit.phar` — all green (check `RequireOnceGuardTest`: load `clubs-lib.php` only with `require_once`).

- [ ] **Step 5: Commit** — `feat(clubs): clubs table with ice clubs seeded, validation and event club lookup`.

---

### Task 2: Admin Clubs tab and event host clubs

**Files:** Create `wcma-calculator/admin-clubs.php`. Modify `wcma-calculator/layout.php` (`ADMIN_TABS`), `wcma-calculator/admin.php` (requires, routes, event create/update, events page select + per-row picker), `wcma-calculator/ice-rules.php` (`iceEventFields`). Tests: `tests/IceEventFieldsTest.php`, `tests/AdminSourceTest.php`, new `tests/AdminClubsPageTest.php`.

**Interfaces:**
- `iceEventFields(array $post, array $summerClubCodes = []): array` — summer: `club` = posted `host_club` if in `$summerClubCodes`, else null (never an error); ice: unchanged.
- `renderClubsPageHtml(array $clubs, string $csrf): string` (pure body markup, in admin-clubs.php).
- Routes: `admin.php?action=clubs` (GET), `club-save` (POST: `code`, `name`, `msr_url`, `active`, `is_new`), `event-club` (POST: `id`, `host_club`).

- [ ] **Step 1: Failing tests**

`tests/IceEventFieldsTest.php`, add:

```php
    public function testSummerEventsTakeAListedClubOrNone(): void
    {
        $this->assertSame('ESCC', iceEventFields(['discipline' => 'summer', 'host_club' => 'ESCC'], ['ESCC', 'NASCC'])['club']);
        $this->assertNull(iceEventFields(['discipline' => 'summer', 'host_club' => 'NOPE'], ['ESCC'])['club']);
        $this->assertNull(iceEventFields(['discipline' => 'summer', 'host_club' => ''], ['ESCC'])['club']);
        // Ice still only takes clubs with ice rules, whatever the list says.
        $this->assertFalse(iceEventFields(['discipline' => 'ice', 'host_club' => 'ESCC'], ['ESCC'])['ok']);
    }
```

`tests/AdminClubsPageTest.php`:

```php
<?php
// wcma-calculator/tests/AdminClubsPageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../admin-clubs.php';

use PHPUnit\Framework\TestCase;

final class AdminClubsPageTest extends TestCase
{
    public function testListsClubsWithEditableNameLinkAndActiveAndAnAddForm(): void
    {
        $html = renderClubsPageHtml([
            ['code' => 'NASCC', 'name' => 'Northern <Alberta>', 'msr_url' => 'https://msr.example/n', 'active' => 1],
            ['code' => 'WSCC', 'name' => 'Winnipeg', 'msr_url' => '', 'active' => 0],
        ], 'tok');
        $this->assertStringContainsString('Northern &lt;Alberta&gt;', $html);
        $this->assertStringContainsString('value="https://msr.example/n"', $html);
        $this->assertStringContainsString('<input type="hidden" name="code" value="NASCC">', $html);
        $this->assertStringContainsString('<input type="hidden" name="is_new" value="1">', $html);
        $this->assertStringContainsString('name="csrf_token" value="tok"', $html);
        $this->assertSame(3, substr_count($html, 'action="admin.php?action=club-save"'));
        $this->assertMatchesRegularExpression('/name="active" value="1"(?![^>]*checked)[^>]*>\s*Shown/', $html);   // WSCC unticked
    }
}
```

`tests/AdminSourceTest.php`, add:

```php
    public function testClubsTabIsRoutedAndEventsTakeClubsFromTheList(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../admin.php'));
        foreach (["case 'clubs':", "case 'club-save':", "case 'event-club':", "adminRequirePost('admin.php?action=clubs')",
                  "adminRequirePost('admin.php?action=events')", 'array_column(db_get_clubs($pdo, true), \'code\')'] as $needle) {
            $this->assertStringContainsString($needle, $src);
        }
        $layout = file_get_contents(__DIR__ . '/../layout.php');
        $this->assertStringContainsString("'clubs' => ['admin.php?action=clubs', 'Clubs']", $layout);
    }
```

Run them — FAIL.

- [ ] **Step 2: `iceEventFields`** (ice-rules.php): signature `function iceEventFields(array $post, array $summerClubCodes = []): array` and the summer branch:

```php
    if ($discipline === 'summer') {
        $club = $post['host_club'] ?? '';
        $club = is_string($club) && in_array($club, $summerClubCodes, true) ? $club : null;
        return ['ok' => true, 'discipline' => 'summer', 'club' => $club, 'error' => null];
    }
```

(The existing test that posts a summer `host_club` without a list still gets null.)

- [ ] **Step 3: `admin-clubs.php`** — follow `admin-season-links.php`'s page shell (same `<head>`, `renderSiteHeader('Clubs', adminSubnavHtml('clubs'), 'admin')`, flash, footer, `js/form-feedback.js`). Handlers:

```php
function handleClubsList(PDO $pdo): void {
    renderClubsPage(db_get_clubs($pdo), generateCsrfToken(), getFlash());
}

function handleClubSave(PDO $pdo): void {
    $isNew = ($_POST['is_new'] ?? '') === '1';
    $v = clubValidate((string)($_POST['code'] ?? ''), (string)($_POST['name'] ?? ''), (string)($_POST['msr_url'] ?? ''));
    if (!$v['ok']) {
        setFlash((string)$v['error'], 'error');
    } elseif ($isNew && db_get_club($pdo, $v['code']) !== null) {
        setFlash('A club with the code ' . $v['code'] . ' already exists.', 'error');
    } elseif ($isNew) {
        db_create_club($pdo, $v['code'], $v['name'], $v['url']);
        setFlash('Club added.', 'success');
    } elseif (db_get_club($pdo, $v['code']) === null) {
        setFlash('Club not found.', 'error');
    } else {
        db_update_club($pdo, $v['code'], $v['name'], $v['url'], !empty($_POST['active']));
        setFlash('Club saved.', 'success');
    }
    header('Location: admin.php?action=clubs');
    exit;
}
```

`renderClubsPage()` echoes the shell around `renderClubsPageHtml($clubs, $csrf)`, which returns:
- an intro card: "Clubs host events. Their MotorsportReg link is shown to competitors after they submit a tech sheet, so they can register. NASCC and WSCC run the ice races; their codes can't change."
- one `<form method="post" action="admin.php?action=club-save" class="detail-card">` per club: csrf, `<input type="hidden" name="code" value="…">`, the code shown as text, `<label>Name</label><input name="name" value="…" required maxlength="120">`, `<label>MotorsportReg link (optional)</label><input type="url" name="msr_url" value="…" placeholder="https://…">`, `<label class="checkbox-label"><input type="checkbox" name="active" value="1"[ checked]> Shown</label>`, `<button type="submit" class="btn btn-primary">Save</button>`.
- an "Add a club" form (same action) with `is_new=1`, inputs `code` (maxlength 12, `required`), `name`, `msr_url`, and `Add club`.
All values through `h()`.

- [ ] **Step 4: Routes and tab**

`layout.php` `ADMIN_TABS`: insert `'clubs' => ['admin.php?action=clubs', 'Clubs'],` after `'events'`.

`admin.php`: `require_once __DIR__ . '/clubs-lib.php'; require __DIR__ . '/admin-clubs.php';` next to the season-links requires, and routes:

```php
    case 'clubs':
        handleClubsList($pdo);
        break;
    case 'club-save':
        adminRequirePost('admin.php?action=clubs');
        handleClubSave($pdo);
        break;
    case 'event-club':
        adminRequirePost('admin.php?action=events');
        handleEventClub($pdo, (int)($_POST['id'] ?? 0));
        break;
```

(Match the surrounding cases: if they `exit`/`break` differently, follow them. Check `adminRequirePost`'s signature first.)

- [ ] **Step 5: Events use the list** (admin.php)

- `handleEventCreate`/`handleEventUpdate`: call `iceEventFields(<input>, array_column(db_get_clubs($pdo, true), 'code'))`.
- New handler:

```php
function handleEventClub(PDO $pdo, int $id): void {
    $event = db_get_event($pdo, $id);
    if ($event === null) { setFlash('Event not found.', 'error'); header('Location: admin.php?action=events'); exit; }
    $fields = iceEventFields(['discipline' => $event['discipline'] ?? 'summer', 'host_club' => (string)($_POST['host_club'] ?? '')],
        array_column(db_get_clubs($pdo, true), 'code'));
    if (!$fields['ok']) { setFlash((string)$fields['error'], 'error'); header('Location: admin.php?action=events'); exit; }
    db_update_event($pdo, $id, (string)$event['name'], (string)$event['event_date'], $event['location'] ?? null, $fields['discipline'], $fields['club']);
    setFlash('Host club saved.', 'success');
    header('Location: admin.php?action=events');
    exit;
}
```

- Add event form: label `Host club`, options: `—` then every active club as `CODE — Name`, plus a hint "Ice events need NASCC or WSCC."
- Each event row, in the Actions cell before Deactivate: a form to `event-club` with csrf, `id`, a `<select name="host_club" aria-label="Host club for <event name>">` (for ice events only `iceClubCodes()` clubs, for summer `—` + all active clubs; current one selected) and `<button type="submit" class="link-button">Save club</button>`. Pass `db_get_clubs($pdo, true)` into the events page render.

- [ ] **Step 6:** Run the new tests and the full suite — green. Commit `feat(clubs): admin Clubs tab; events take their host club from the list`.

---

### Task 3: Register step names the club and links to it; title with year

**Files:** Modify `wcma-calculator/tech-sheet-next.php`, `wcma-calculator/tech-sheets.php` (`handleView`, requires). Tests: `tests/TechSheetNextTest.php`, `tests/TechSheetViewSourceTest.php`.

**Interfaces:** `techSheetViewTitle(array $sheet, ?array $event, ?array $car = null): string`; `renderTechSheetNextStepsHtml(array $sheet, ?array $event, array $carStatus, string $gearChipsHtml, ?array $club = null): string` where `$club` is `clubForEvent()` output.

- [ ] **Step 1: Failing tests** — `tests/TechSheetNextTest.php`, add:

```php
    public function testTitleIncludesTheCarYearWhenKnown(): void
    {
        $this->assertSame('Ice tech sheet — #42 2008 Honda Civic — NASCC Ice Race #1', techSheetViewTitle($this->sheet(), $this->event(), ['year' => '2008']));
        $this->assertSame('Ice tech sheet — #42 Honda Civic — NASCC Ice Race #1', techSheetViewTitle($this->sheet(), $this->event(), ['year' => null]));
    }

    public function testRegisterStepNamesTheClubAndLinksToMotorsportReg(): void
    {
        $html = renderTechSheetNextStepsHtml($this->sheet(['discipline' => 'summer', 'club' => null]), ['id' => 10, 'name' => 'Fall Sprint'],
            ['state' => 'none'], '', ['name' => 'Edmonton Sports Car Club', 'url' => 'https://msr.example/escc?a=1&b=2']);
        $this->assertStringContainsString('Register for Fall Sprint with the Edmonton Sports Car Club.', $html);
        $this->assertStringContainsString('<a class="hub-btn hub-btn--secondary" href="https://msr.example/escc?a=1&amp;b=2" target="_blank" rel="noopener">Register on MotorsportReg &#8599;</a>', $html);
        $noLink = renderTechSheetNextStepsHtml($this->sheet(), $this->event(), ['state' => 'none'], '', ['name' => 'Northern Alberta Sports Car Club', 'url' => '']);
        $this->assertStringContainsString('with the Northern Alberta Sports Car Club.', $noLink);
        $this->assertStringNotContainsString('Register on MotorsportReg', $noLink);
    }
```

Update the existing `testNoGearStepWithoutGearChipsAndSummerSaysHostClub` / ice register assertions to pass the club explicitly where they expected the ice label (no club argument → "with the host club").

`tests/TechSheetViewSourceTest.php`, add:

```php
    public function testViewLooksUpTheCarAndTheEventsClub(): void
    {
        $body = $this->viewBody();
        $this->assertStringContainsString('techSheetViewTitle($sheet, $event, db_get_car($pdo, (int)$sheet[\'car_id\']))', $body);
        $this->assertStringContainsString('clubForEvent(', $body);
    }
```

Run — FAIL.

- [ ] **Step 2: Implement** (`tech-sheet-next.php`)

Title: `'#' . $sheet['car_number'] . ' ' . trim(trim((string)($car['year'] ?? '')) . ' ' . $sheet['car_make'] . ' ' . $sheet['car_model'])`.

Register step, replacing the `$club`/`$eventName` lines and the step:

```php
    $eventName = $event !== null ? (string)$event['name'] : 'the event';
    $step = '<strong>Register with the club</strong><p>The hub doesn\'t register you. Register for ' . h($eventName)
        . ' with ' . ($club !== null ? 'the ' . h($club['name']) : 'the host club') . '.</p>';
    if ($club !== null && $club['url'] !== '') {
        $step .= '<a class="hub-btn hub-btn--secondary" href="' . h($club['url']) . '" target="_blank" rel="noopener">Register on MotorsportReg &#8599;</a>';
    }
    $steps[] = $step;
```

(`iceClubLabel` is no longer used here; drop the ice-rules dependency note from the file header.)

`tech-sheets.php` `handleView`: `require_once __DIR__ . '/clubs-lib.php';` at the top with the others; `$title = techSheetViewTitle($sheet, $event, db_get_car($pdo, (int)$sheet['car_id']));`; `$club = clubForEvent($event !== null && !empty($event['host_club']) ? db_get_club($pdo, (string)$event['host_club']) : null, $event);` and pass `$club` as the fifth argument.

- [ ] **Step 3:** Tests green; full suite green. Commit `feat(ux): register step names the host club and links to MotorsportReg; sheet title has the car year`.

---

### Task 4: Small fixes (chip, co-driver wording, signature error, admin buttons)

**Files:** `wcma-calculator/garage-lib.php`, `wcma-calculator/js/driver-choice.js`, `wcma-calculator/ice-sheet-page.php`, `wcma-calculator/tech-sheets.php`, `wcma-calculator/js/tech-sheet-form.js`, `wcma-calculator/admin.php`, `wcma-calculator/css/hub.css`. Tests: `tests/GarageLibTest.php`, `tests/js/driver-choice.test.js`, `tests/IceSheetPageTest.php`, `tests/AdminSourceTest.php`, `tests/ux/audit.mjs`.

- [ ] **Step 1: Failing tests**

`GarageLibTest` — change `testIceSummaryShowsForAnIceCarWithNoActivityYet` so `'both'` expects `null`, and `testUserHasIceActivityWhenACarIsStoredAsIce` so `'both'` expects `false` (an ice car is `'ice'`; a both car shows ice once it has ice activity).

`tests/js/driver-choice.test.js` — add `coDriverNameMessage` to the require and:

```js
test('co-driver name messages name the driver row', () => {
    assert.strictEqual(coDriverNameMessage(1), "Enter the co-driver's name.");
    assert.strictEqual(coDriverNameMessage(3), "Enter Driver 3's name.");
});
```

`IceSheetPageTest` — assert `id="driver1_new_name"` carries `data-message="Enter the co-driver&#039;s name."` (or `\'` escaped the way the file writes it — match the output), and that `<p id="sig-error"` does **not** contain `role="alert"`.

`AdminSourceTest`:

```php
    public function testDeactivateAndReactivateAreSecondaryButtons(): void
    {
        $src = file_get_contents(__DIR__ . '/../admin.php');
        $this->assertStringContainsString('<button type="submit" class="btn-role btn-role--secondary">Deactivate</button>', $src);
        $this->assertStringContainsString('<button type="submit" class="btn-role btn-role--secondary">Reactivate</button>', $src);
    }
```

`audit.mjs` — in the "a signature wiped by changing the driver" block, after the `sigMsg` report and before signing again, add a pointerdown on the driver canvas and:

```js
  await page.evaluate(() => {
    const cv = document.getElementById('driver-sig-canvas'); const r = cv.getBoundingClientRect();
    cv.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, clientX: r.left + 10, clientY: r.top + 10, pointerId: 1, pointerType: 'touch', isPrimary: true, buttons: 1 }));
    cv.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, clientX: r.left + 10, clientY: r.top + 10, pointerId: 1, pointerType: 'touch', isPrimary: true }));
  });
  report('the signature message goes when you start signing', await page.evaluate(() => document.getElementById('sig-error').hidden) ? [] : ['the signature message is still showing after starting to sign']);
```

Run PHPUnit / node / audit — the new checks FAIL.

- [ ] **Step 2: Implement**

- `garage-lib.php` `garageIceSummary`: `in_array($stored, ['ice'], true)` → only `'ice'`. `userHasIceActivity`: count only `'ice'` cars. Update both docblocks.
- `driver-choice.js`: add and export

```js
    /** The words for an empty co-driver name box (spec 2026-09-29 §2.5). */
    function coDriverNameMessage(number) {
        return number > 1 ? 'Enter Driver ' + number + '\'s name.' : 'Enter the co-driver\'s name.';
    }
```

  and in `build(...)`: `nameInput.setAttribute('data-message', coDriverNameMessage(number));`.
- Both forms: `driver1_new_name` input gains `data-message="Enter the co-driver's name."` (escape for the context: `\'` inside the ice PHP single-quoted string).
- Both forms: remove `role="alert"` from `<p id="sig-error" …>`.
- `tech-sheet-form.js`, after `syncSigners();`:

```js
    // The signature message goes as soon as the member starts signing (spec 2026-09-29 §2.6).
    [['entrant-sig-canvas', entrantSigWrap], ['driver-sig-canvas', driverSigWrap]].forEach(function (pair) {
        document.getElementById(pair[0]).addEventListener('pointerdown', function () {
            sigError.hidden = true;
            pair[1].classList.remove('field-error');
        });
    });
```

  (`entrantSigWrap`/`driverSigWrap` are declared further down; move their two `const` lines up to just before this block.)
- `admin.php`: the Deactivate and Reactivate buttons get `class="btn-role btn-role--secondary"`.
- `hub.css` (Phase B section end):

```css
/* Admin user actions (spec 2026-09-29 §2.7): Save is primary, Deactivate/Reactivate secondary. */
body.hub .btn-role { min-height: 44px; padding: 0 14px; border-radius: 8px; border: 2px solid var(--hub-red);
  background: var(--hub-red); color: #fff; font-size: 16px; font-weight: 700; text-transform: none; }
body.hub .btn-role:hover { background: var(--hub-red-ink); border-color: var(--hub-red-ink); }
body.hub .btn-role--secondary { background: #fff; color: var(--hub-ink); border-color: var(--hub-ink); }
body.hub .btn-role--secondary:hover { background: var(--hub-paper); color: var(--hub-ink); }
```

- [ ] **Step 3:** All suites green. Commit `fix(ux): ice chip only for ice cars, named co-driver messages, signature message clears, admin action weights`.

---

### Task 5: Calculator meets the phone rules

**Files:** `wcma-calculator/tests/ux/audit.mjs`, `wcma-calculator/css/hub.css`.

- [ ] **Step 1: Failing audit** — at the start of the flow (signed out), add:

```js
  await page.goto(BASE + '/calculator.php'); await audit(page, 'calculator');
```

and to `styleFixturesInPage`'s markup add `<button type="button" class="nudge-dismiss" id="fx-nudge">Not now</button>` with the check:

```js
  const nudge = document.getElementById('fx-nudge').getBoundingClientRect().height;
  if (nudge < 44) problems.push(`save-nudge dismiss button is ${Math.round(nudge)}px tall`);
```

Run the audit — expected FAIL on `calculator` (list the problems in the ledger) and on the nudge.

- [ ] **Step 2: Fix in `hub.css`** — a new section `/* ── Calculator (clubs & polish spec 2026-09-29 §2.3) ── */`. Start with:

```css
body.hub .nudge-dismiss { min-height: 44px; min-width: 44px; padding: 0 12px; font-size: 16px; color: var(--hub-red-ink); }
body.hub .password-toggle, body.hub .password-toggle.is-showing { color: var(--hub-ink); }
body.hub summary, body.hub .container a:not(.hub-btn):not(.btn) { color: var(--hub-red-ink); }
```

Then, for each remaining `calculator` audit line, add the smallest rule using hub tokens (text under 16px → 16px; blue `#3498db` text → `--hub-red-ink` or `--hub-ink`; grey text → `--hub-ink-2`; small targets → `min-height: 44px`). Ledger each group as a ruling. Re-run until `ok calculator`, `@150%` and `@250px` pass.

- [ ] **Step 3: Screenshot check** — on a throwaway server (seed as in `run-audit.sh`, or the `scratch/ux` harness), screenshot `calculator.php` at 375px signed out: fill weight and HP so modifiers enable, trigger the save nudge (see `js/ui-controller.js` nudge timer), dismiss it, and confirm the calculated class still shows and the page looks like the rest of the hub (red/ink, no blue). Review Focus 5.

- [ ] **Step 4:** All suites green. Commit `fix(ux): the Class Calculator meets the phone rules and hub colours`.

---

### Task 6: Walk

- [ ] Audit all pass; PHPUnit and node green.
- [ ] Screenshots at 375px signed in as admin: Clubs tab (add ESCC with a link), Events (set Fall Sprint's host club to ESCC, try giving an ice event ESCC — refused), then a summer tech sheet view for Fall Sprint shows "with the Edmonton Sports Car Club." and the MotorsportReg button. Record in the ledger.
