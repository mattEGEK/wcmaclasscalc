# WCMA Hub Phase 5: Reminders Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Competitors can opt in to reminder emails. Once a day, each one who opted in gets one email per tagged event that is about 2 weeks, 1 week or 2 days away and still has something to do, and every email has a working unsubscribe link. This phase also clears the Phase 4 leftovers: phone-friendly Classing tables and three cosmetic tidy-ups.

**Architecture:**
- Plain PHP + SQLite, as in Phases 1–4.
- **Pure logic** lives in `reminders-lib.php`: which reminder is due, the digest for each event, the one-time opt-in offer, and signed unsubscribe links.
- **The email renderer** is in `reminder-email.php`.
- **The daily run** is `remindersRun()` in `reminder-run.php`. It is session-free and takes an injectable send function (the same pattern as `pretechNotify()`), so it is unit-testable against a temporary database.
- **The cron entry point** is `reminders.php`, which is CLI only. IONOS cron commands can only be a path (letters, digits and `- _ . /`, no spaces; see `scratch/crontaberrors.png`), so the cron job runs the shell wrapper `reminders-cron.sh`, which finds the PHP CLI and runs `reminders.php`.
- **Unsubscribe links** carry the account id and an HMAC token (`unsubscribe.php?u=5&t=…`).
  - The signing secret is created on first use and kept in the `settings` table, so the server's `config.php` needs no new constant.
  - A GET shows a confirm button, so link scanners that open every URL in an email can't unsubscribe anyone.
  - A POST with a valid token turns reminders off. Mail apps' own one-click unsubscribe (`List-Unsubscribe` and `List-Unsubscribe-Post` headers) POSTs to the same URL.
- **Reminder windows:** 8–14 days out is the "14-day" reminder, 3–7 days the "7-day" one, and 1–2 days the "2-day" one. `reminder_log (user_id, event_id, days_out)` keeps each window to one email. A cron day that is missed still gets its email the next day, and a run that repeats sends nothing twice.
- **The opt-in:** the offer checkbox shows on tag forms until the user has made a choice. `users.reminder_prompted_at` records that a choice was made, from either the checkbox or Profile.

**Tech Stack:** PHP 8.3, SQLite (PDO), PHPUnit 10 (`php phpunit.phar`), PHPMailer (already vendored), POSIX `sh` for the cron wrapper, Playwright for the end-to-end pass (the scratch harness pattern).

**Spec:** `docs/superpowers/specs/2026-09-24-wcma-hub-design.md`.
- §7 (Reminders) is the binding part.
- §3 defines readiness items, including what counts as a `todo`.
- §9 says a failed email never blocks, and requires unit tests for `reminderDue`.
- Phase 4 leftovers come from the hub-rollout notes: the Classing and declaration-history tables scroll sideways at 390px, and three cosmetic minors.

## Global Constraints

- All paths are relative to `wcma-calculator/` unless they start with `docs/`, `.gitattributes` or `scratch/`. Run PHPUnit from `wcma-calculator/`: `php phpunit.phar`. Run the JS tests with `node --test tests/js/*.test.js`.
- The checkout uses CRLF line endings (`core.autocrlf=true`). Any test that slices PHP source by searching for `"\n}\n"` or `"\nfunction "` must first normalise with `str_replace("\r\n", "\n", ...)`. Shell scripts must be checked out with LF endings (Task 4 adds `*.sh text eol=lf`).
- No new dependencies and no build step.
- **Terminology:** use *reviewed*, *accepted* and *pre-teched*. Never use *approved*, *approval*, *passed* or *safe* in UI or email copy.
- **Binding copy** (spec §7) lives in `email-copy.php`: `COPY_REMINDER_OPT_IN = 'Email me reminders for events I\'m going to.'`
- `users.reminder_emails` defaults to **off** for new and existing users. Nothing turns it on except the user's own choice.
- **Tagging doesn't register anyone.** Reminder emails also carry `EVENTS_NOT_REGISTERING`.
- **No email when there's nothing to do.** A digest only goes out when the event has at least one `todo` item from `buildReadiness()`.
- **A failed email never blocks.** A failed send is logged to the PHP error log and counted as failed. It is not written to `reminder_log`, so the next run retries it.
- **Escape everything** from the DB or the request with `h()` (`view_helpers.php`), including URLs in `href`.
- POST handlers that use a session check `validateCsrfToken()`. `unsubscribe.php` is the one deliberate exception, because mail-app one-click POSTs carry no session. Its HMAC token is the proof instead.
- The app isn't live. This phase adds a column and a table with no migration. **After Task 1, reset your local DB:** `php reset-hub-db.php --confirm && php seed-hub-db.php`.
- **Nothing is pushed to GitHub.** Work on branch `hub-phase5` (`git checkout -b hub-phase5` from `main`) and commit at the end of every task. Every commit message ends with:
  ```
  Co-Authored-By: <the Claude model that wrote the commit> <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5
  ```

## Review Focus

1. **Link scanners and email previews** open every URL in an email with a GET. A GET on the unsubscribe link must never turn reminders off; only a POST with a valid token does. Pinned in Task 5 (`UnsubscribeSourceTest::testOnlyAPostWithAValidTokenTurnsRemindersOff`) and Task 8 (e2e item 7).
2. **A missed or repeated cron run.** When a day is skipped, that window's reminder goes out the next day, and a second run on the same day sends nothing. Pinned in Task 2 (`RemindersLibTest::testReminderWindows`) and Task 4 (`RemindersRunTest::testRunningAgainTheSameDaySendsNothing`, `testAMissedDayCatchesUpWithinTheWindow`).
3. **A tampered or borrowed unsubscribe link.** Another account's id with your token, a truncated token, or a garbled id is refused with "This link isn't valid", and nothing changes. Pinned in Task 2 (`RemindersLibTest::testUnsubscribeTokens`) and Task 8 (e2e item 7).
4. **An opted-in competitor who is all set** gets no email. A deactivated account gets none either, even with reminders on. Pinned in Task 2 (`RemindersLibTest::testDigestsKeepOnlyDueEventsWithSomethingToDo`) and Task 4 (`RemindersRunTest::testNothingForOptedOutInactiveOrUntaggedUsers`).
5. **A server without `SITE_BASE_URL`.** Cron runs with no request host, so links built without a base would point nowhere. `reminders.php` must refuse to run with a clear message instead of sending broken links. Pinned in Task 4 (`ReminderCronSourceTest::testCliGuardOptionsAndBaseUrlCheck`).

---

## File map

| File | Status | Responsibility |
|---|---|---|
| `db.php` | modify | `users.reminder_prompted_at`; `reminder_log` table; `db_set_user_reminders()`, `db_get_reminder_users()`, `db_reminder_logged()`, `db_log_reminder()` |
| `reminders-lib.php` | create | `reminderDue()`, `reminderDaysUntil()`, `reminderDigests()`, `remindersShouldOffer()`, `remindersRecordTagChoice()`, `reminderOptInFieldsHtml()`, unsubscribe tokens and URL, `reminderSecret()`, `renderUnsubscribeHtml()` |
| `reminder-email.php` | create | `reminderEmail()`, `reminderAbsoluteUrl()` |
| `email-helpers.php` | modify | `emailSmtpSend()` sends optional custom headers and logs them in dry-run mode |
| `email-copy.php` | modify | `COPY_REMINDER_OPT_IN`; `COPY_DECLARATION_ACCEPTED` moved next to the other "received" lines |
| `reminder-run.php` | create | `remindersRun()` |
| `reminders.php` | create | CLI entry point: options, base URL check, summary line, exit code |
| `reminders-cron.sh` | create | IONOS cron wrapper: finds the PHP CLI and appends to `data/reminders.log` |
| `unsubscribe.php` | create | Token-checked unsubscribe page (GET confirm, POST turn off) |
| `profile.php` | modify | Reminder emails on/off |
| `home-page.php`, `index.php` | modify | One-time opt-in checkbox on Home tag forms; record the choice |
| `garage-page.php`, `garage.php` | modify | The same on the car page's "Bring this car to another event" form |
| `inspect-page.php`, `inspect-lib.php`, `css/hub.css` | modify | Classing and history tables stack on phones; tidy-ups |
| `.htaccess`, `../.gitattributes`, `.gitignore` | modify | Deny web access to `reminders.php` and the wrapper; LF for `*.sh`; ignore `data/*.log` |
| `README.md` | modify | "Reminder emails (daily cron)" setup section |

---

### Task 1: Reminder data

**Files:**
- Modify: `db.php`. Change the `users` table in `db_init()`, add a `reminder_log` table, and append the functions below.
- Test: `tests/DbRemindersTest.php` (create)

**Interfaces:**
- Produces:
  - `users.reminder_prompted_at DATETIME`, which is NULL until the user has chosen, from either the checkbox or Profile.
  - `reminder_log (id, user_id, event_id, days_out, sent_at)` with `UNIQUE (user_id, event_id, days_out)`.
  - `db_set_user_reminders(PDO $pdo, int $userId, bool $on): void`. Sets `reminder_emails`, and sets `reminder_prompted_at` if it is still NULL. A later change keeps the first timestamp.
  - `db_get_reminder_users(PDO $pdo): array`. Users with `reminder_emails = 1 AND active = 1`, by id.
  - `db_reminder_logged(PDO $pdo, int $userId, int $eventId, int $daysOut): bool`
  - `db_log_reminder(PDO $pdo, int $userId, int $eventId, int $daysOut): bool`. `INSERT OR IGNORE`; true only when a row was added.

- [ ] **Step 1: Write the failing tests**

`tests/DbRemindersTest.php`:

```php
<?php
// wcma-calculator/tests/DbRemindersTest.php
use PHPUnit\Framework\TestCase;

final class DbRemindersTest extends TestCase
{
    private function user(PDO $pdo, string $email, string $name = 'Jordan Lee'): int {
        return db_create_user($pdo, ['email' => $email, 'name' => $name, 'password_hash' => 'x', 'google_id' => null]);
    }

    public function testNewAccountsHaveRemindersOffAndHaveNotBeenAsked(): void
    {
        $pdo = make_temp_pdo();
        $row = db_find_user_by_id($pdo, $this->user($pdo, 'j@example.com'));
        $this->assertSame(0, (int)$row['reminder_emails']);
        $this->assertNull($row['reminder_prompted_at']);
    }

    public function testSettingRemindersKeepsTheFirstChoiceTime(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        db_set_user_reminders($pdo, $u, true);
        $row = db_find_user_by_id($pdo, $u);
        $this->assertSame(1, (int)$row['reminder_emails']);
        $this->assertNotNull($row['reminder_prompted_at']);

        $pdo->exec("UPDATE users SET reminder_prompted_at = '2026-01-01 00:00:00' WHERE id = $u");
        db_set_user_reminders($pdo, $u, false);
        $row = db_find_user_by_id($pdo, $u);
        $this->assertSame(0, (int)$row['reminder_emails']);
        $this->assertSame('2026-01-01 00:00:00', $row['reminder_prompted_at']);
    }

    public function testReminderUsersAreOptedInAndActive(): void
    {
        $pdo = make_temp_pdo();
        $on = $this->user($pdo, 'on@example.com');
        $off = $this->user($pdo, 'off@example.com');
        $gone = $this->user($pdo, 'gone@example.com');
        db_set_user_reminders($pdo, $on, true);
        db_set_user_reminders($pdo, $off, false);
        db_set_user_reminders($pdo, $gone, true);
        db_set_user_active($pdo, $gone, false);
        $this->assertSame([$on], array_map(fn(array $r): int => (int)$r['id'], db_get_reminder_users($pdo)));
    }

    public function testReminderLogIsOncePerUserEventAndWindow(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'j@example.com');
        $e = db_create_event($pdo, 'Fall Sprint', '2026-10-11', null);
        $this->assertFalse(db_reminder_logged($pdo, $u, $e, 7));
        $this->assertTrue(db_log_reminder($pdo, $u, $e, 7));
        $this->assertTrue(db_reminder_logged($pdo, $u, $e, 7));
        $this->assertFalse(db_log_reminder($pdo, $u, $e, 7));
        $this->assertTrue(db_log_reminder($pdo, $u, $e, 2));
        $this->assertFalse(db_reminder_logged($pdo, $u, $e, 14));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter DbRemindersTest`
Expected: ERROR. `reminder_prompted_at` is an undefined index, or `db_set_user_reminders()` is undefined.

- [ ] **Step 3: Change the schema**

In `db.php`, `db_init()`, in the `users` table replace:

```php
            reminder_emails INTEGER NOT NULL DEFAULT 0
```

with:

```php
            reminder_emails INTEGER NOT NULL DEFAULT 0,
            reminder_prompted_at DATETIME
```

After the `season_links` `CREATE TABLE` block, add:

```php
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reminder_log (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id   INTEGER NOT NULL,
            event_id  INTEGER NOT NULL,
            days_out  INTEGER NOT NULL,
            sent_at   DATETIME NOT NULL,
            UNIQUE (user_id, event_id, days_out)
        )
    ");
```

- [ ] **Step 4: Add the functions**

Append to `db.php`:

```php
// ── Reminders (spec §7) ───────────────────────────────────────────────────────

/** Turns reminder emails on or off. Either way the user has now chosen, so the one-time offer stops. */
function db_set_user_reminders(PDO $pdo, int $userId, bool $on): void {
    $pdo->prepare("UPDATE users SET reminder_emails = :on, reminder_prompted_at = COALESCE(reminder_prompted_at, :now) WHERE id = :id")
        ->execute([':on' => $on ? 1 : 0, ':now' => date('Y-m-d H:i:s'), ':id' => $userId]);
}

/** Active accounts that turned reminder emails on, by id. */
function db_get_reminder_users(PDO $pdo): array {
    return $pdo->query("SELECT * FROM users WHERE reminder_emails = 1 AND active = 1 ORDER BY id ASC")->fetchAll();
}

function db_reminder_logged(PDO $pdo, int $userId, int $eventId, int $daysOut): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM reminder_log WHERE user_id = :u AND event_id = :e AND days_out = :d");
    $stmt->execute([':u' => $userId, ':e' => $eventId, ':d' => $daysOut]);
    return $stmt->fetchColumn() !== false;
}

/** Records a sent reminder. False when it was already recorded. */
function db_log_reminder(PDO $pdo, int $userId, int $eventId, int $daysOut): bool {
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO reminder_log (user_id, event_id, days_out, sent_at) VALUES (:u, :e, :d, :now)");
    $stmt->execute([':u' => $userId, ':e' => $eventId, ':d' => $daysOut, ':now' => date('Y-m-d H:i:s')]);
    return $stmt->rowCount() === 1;
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php phpunit.phar --filter DbRemindersTest`, then `php phpunit.phar`.
Expected: both OK. Then reset your local DB: `php reset-hub-db.php --confirm && php seed-hub-db.php`.

- [ ] **Step 6: Commit**

```bash
git add db.php tests/DbRemindersTest.php
git commit -m "feat(hub): reminder opt-in flag, prompted time and reminder log"
```

---

### Task 2: Reminder rules and unsubscribe tokens

**Files:**
- Create: `reminders-lib.php`
- Modify: `email-copy.php` (add `COPY_REMINDER_OPT_IN`)
- Test: `tests/RemindersLibTest.php` (create)

**Interfaces:**
- Consumes: `db_get_setting()` and `db_set_setting()` (db.php), `h()` (view_helpers.php), and the `buildReadiness()` output shape:
  - `['events' => [['event' => [...], 'items' => [...]], ...], 'untagged' => [...]]`
  - each item is `{kind, subject_type, subject_id, state: 'todo'|'info'|'done', label, detail, action: ?{label, url}, at_track}`.
- Produces:
  - `const REMINDER_DAYS = [2, 7, 14]`
  - `reminderDaysUntil(string $eventDate, string $today): int`
  - `reminderDue(string $eventDate, string $today): ?int`, which returns 14, 7, 2 or null
  - `reminderDigests(array $readiness, string $today): array<int, array{event: array, daysOut: int, daysUntil: int, items: array}>`
  - `remindersShouldOffer(?array $user): bool`
  - `reminderUnsubscribeToken(int $userId, string $secret): string`, 64 hex characters
  - `reminderTokenValid(int $userId, string $token, string $secret): bool`
  - `reminderUnsubscribeUrl(string $baseUrl, int $userId, string $secret): string`
  - `reminderSecret(PDO $pdo): string`
  - `const COPY_REMINDER_OPT_IN`

- [ ] **Step 1: Write the failing tests**

`tests/RemindersLibTest.php`:

```php
<?php
// wcma-calculator/tests/RemindersLibTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../reminders-lib.php';

use PHPUnit\Framework\TestCase;

final class RemindersLibTest extends TestCase
{
    public function testDaysUntilCountsCalendarDays(): void
    {
        $this->assertSame(7, reminderDaysUntil('2026-10-11', '2026-10-04'));
        $this->assertSame(0, reminderDaysUntil('2026-10-04', '2026-10-04'));
        $this->assertSame(-3, reminderDaysUntil('2026-10-01', '2026-10-04'));
        $this->assertSame(14, reminderDaysUntil('2026-11-08', '2026-10-25'));      // across the DST change
        $this->assertSame(7, reminderDaysUntil('2026-10-11 09:00:00', '2026-10-04'));
    }

    public function testReminderWindows(): void
    {
        $cases = [
            '2026-10-16' => null,   // 15 days
            '2026-10-15' => 14,     // 14
            '2026-10-09' => 14,     // 8: a missed 14-day run still catches up
            '2026-10-08' => 7,      // 7
            '2026-10-04' => 7,      // 3
            '2026-10-03' => 2,      // 2
            '2026-10-02' => 2,      // 1
            '2026-10-01' => null,   // the day of the event
            '2026-09-30' => null,   // over
        ];
        foreach ($cases as $eventDate => $expected) {
            $this->assertSame($expected, reminderDue($eventDate, '2026-10-01'), $eventDate);
        }
    }

    private function item(string $state, string $label): array {
        return ['kind' => 'tech_sheet', 'subject_type' => 'car', 'subject_id' => 3, 'state' => $state, 'label' => $label,
                'detail' => '', 'action' => null, 'at_track' => null];
    }

    public function testDigestsKeepOnlyDueEventsWithSomethingToDo(): void
    {
        $readiness = ['events' => [
            ['event' => ['id' => 1, 'name' => 'Soon', 'event_date' => '2026-10-08'],
             'items' => [$this->item('todo', 'Submit a tech sheet for #42'), $this->item('done', 'Class declared'), $this->item('info', 'With an inspector')]],
            ['event' => ['id' => 2, 'name' => 'All set', 'event_date' => '2026-10-11'],
             'items' => [$this->item('done', 'Tech sheet submitted'), $this->item('info', 'Photos with an inspector')]],
            ['event' => ['id' => 3, 'name' => 'Far away', 'event_date' => '2026-10-30'],
             'items' => [$this->item('todo', 'Submit a tech sheet for #42')]],
            ['event' => ['id' => 4, 'name' => 'Very soon', 'event_date' => '2026-10-03'],
             'items' => [$this->item('todo', 'Gear for Jordan Lee')]],
        ], 'untagged' => []];

        $digests = reminderDigests($readiness, '2026-10-01');
        $this->assertSame([1, 4], array_map(fn(array $d): int => (int)$d['event']['id'], $digests));
        $this->assertSame(7, $digests[0]['daysOut']);
        $this->assertSame(7, $digests[0]['daysUntil']);
        $this->assertSame(['Submit a tech sheet for #42'], array_column($digests[0]['items'], 'label'));
        $this->assertSame(2, $digests[1]['daysOut']);
        $this->assertSame(2, $digests[1]['daysUntil']);
        $this->assertSame([], reminderDigests(['events' => [], 'untagged' => []], '2026-10-01'));
    }

    public function testTheOfferShowsUntilTheUserHasChosen(): void
    {
        $this->assertFalse(remindersShouldOffer(null));
        $this->assertTrue(remindersShouldOffer(['reminder_emails' => 0, 'reminder_prompted_at' => null]));
        $this->assertFalse(remindersShouldOffer(['reminder_emails' => 1, 'reminder_prompted_at' => null]));
        $this->assertFalse(remindersShouldOffer(['reminder_emails' => 0, 'reminder_prompted_at' => '2026-09-01 10:00:00']));
    }

    public function testUnsubscribeTokens(): void
    {
        $secret = str_repeat('ab', 32);
        $token = reminderUnsubscribeToken(5, $secret);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertTrue(reminderTokenValid(5, $token, $secret));
        $this->assertFalse(reminderTokenValid(6, $token, $secret));                  // someone else's id with your token
        $this->assertFalse(reminderTokenValid(5, substr($token, 0, 40), $secret));   // copied incompletely
        $this->assertFalse(reminderTokenValid(5, $token, 'other-secret'));
        $this->assertFalse(reminderTokenValid(5, $token, ''));
        $this->assertFalse(reminderTokenValid(0, reminderUnsubscribeToken(0, $secret), $secret));
        $this->assertSame('https://x.test/classing/unsubscribe.php?u=5&t=' . $token, reminderUnsubscribeUrl('https://x.test/classing/', 5, $secret));
    }

    public function testSecretIsCreatedOnceAndKept(): void
    {
        $pdo = make_temp_pdo();
        $first = reminderSecret($pdo);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        $this->assertSame($first, reminderSecret($pdo));
    }

    public function testOptInCopy(): void
    {
        $this->assertSame('Email me reminders for events I\'m going to.', COPY_REMINDER_OPT_IN);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter RemindersLibTest`
Expected: ERROR, because `reminders-lib.php` does not exist yet.

- [ ] **Step 3: Add the copy**

In `email-copy.php`, after `COPY_GEAR_ACCEPTED`:

```php
const COPY_REMINDER_OPT_IN = 'Email me reminders for events I\'m going to.';
```

- [ ] **Step 4: Create `reminders-lib.php`**

```php
<?php
// wcma-calculator/reminders-lib.php
//
// Opt-in reminder emails (spec §7): which reminder is due, the digest for each tagged event, the
// one-time opt-in offer on tag forms, and signed unsubscribe links. Pure functions, except
// reminderSecret() and remindersRecordTagChoice(), which use the database. Callers must have loaded
// db.php and view_helpers.php (h()).
require_once __DIR__ . '/email-copy.php';

/**
 * Reminder windows, smallest first. 1–2 days out is the 2-day reminder, 3–7 the 7-day one and 8–14
 * the 14-day one. reminder_log keeps each window to one email, so a missed cron day still sends it
 * and a repeated run doesn't send it twice.
 */
const REMINDER_DAYS = [2, 7, 14];

/** Whole calendar days from $today to $eventDate ('YYYY-MM-DD…'); negative once the event has passed. */
function reminderDaysUntil(string $eventDate, string $today): int {
    $utc = new DateTimeZone('UTC');
    $diff = (new DateTimeImmutable(substr($today, 0, 10), $utc))->diff(new DateTimeImmutable(substr($eventDate, 0, 10), $utc));
    return $diff->invert ? -$diff->days : $diff->days;
}

/** The reminder window (14, 7 or 2) an event is in today, or null (too far away, today, or over). */
function reminderDue(string $eventDate, string $today): ?int {
    $days = reminderDaysUntil($eventDate, $today);
    if ($days < 1) return null;
    foreach (REMINDER_DAYS as $window) {
        if ($days <= $window) return $window;
    }
    return null;
}

/**
 * One digest per tagged event whose reminder window is open and which still has something to do.
 *
 * @param array $readiness buildReadiness() output
 * @return array<int, array{event: array, daysOut: int, daysUntil: int, items: array}> items are the todo items only
 */
function reminderDigests(array $readiness, string $today): array {
    $digests = [];
    foreach ($readiness['events'] as $row) {
        $date = (string)$row['event']['event_date'];
        $due = reminderDue($date, $today);
        if ($due === null) continue;
        $todo = array_values(array_filter($row['items'], fn(array $i): bool => $i['state'] === 'todo'));
        if (!$todo) continue;
        $digests[] = ['event' => $row['event'], 'daysOut' => $due, 'daysUntil' => reminderDaysUntil($date, $today), 'items' => $todo];
    }
    return $digests;
}

/** Offer the reminder checkbox on tag forms until the user has chosen, here or in Profile (spec §7). */
function remindersShouldOffer(?array $user): bool {
    return $user !== null && (int)($user['reminder_emails'] ?? 0) === 0 && empty($user['reminder_prompted_at']);
}

function reminderUnsubscribeToken(int $userId, string $secret): string {
    return hash_hmac('sha256', 'unsubscribe:' . $userId, $secret);
}

function reminderTokenValid(int $userId, string $token, string $secret): bool {
    return $userId > 0 && $secret !== '' && hash_equals(reminderUnsubscribeToken($userId, $secret), $token);
}

function reminderUnsubscribeUrl(string $baseUrl, int $userId, string $secret): string {
    return rtrim($baseUrl, '/') . '/unsubscribe.php?u=' . $userId . '&t=' . reminderUnsubscribeToken($userId, $secret);
}

/** The unsubscribe-link signing secret, created on first use. Resetting the database makes old links invalid. */
function reminderSecret(PDO $pdo): string {
    $secret = (string)db_get_setting($pdo, 'reminder_secret', '');
    if ($secret === '') {
        $secret = bin2hex(random_bytes(32));
        db_set_setting($pdo, 'reminder_secret', $secret);
    }
    return $secret;
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php phpunit.phar --filter RemindersLibTest`, then `php phpunit.phar`.
Expected: both OK.

- [ ] **Step 6: Commit**

```bash
git add reminders-lib.php email-copy.php tests/RemindersLibTest.php
git commit -m "feat(hub): reminder windows, digests, opt-in offer and signed unsubscribe tokens"
```

---
### Task 3: The reminder email

**Files:**
- Create: `reminder-email.php`
- Modify: `email-helpers.php`. `emailSmtpSend()` sends `$message['headers']` and logs them in dry-run mode.
- Test: `tests/ReminderEmailTest.php` (create). Also modify `tests/PretechEmailTest.php::testMailDryRunLogsInsteadOfSending`.

**Interfaces:**
- Consumes: a `reminderDigests()` entry (Task 2), `pretechEmailWrap()`, `pretechEmailPara()` and `pretechEmailLink()` (pretech-email.php), and `EVENTS_NOT_REGISTERING` (events-lib.php).
- Produces:
  - `reminderAbsoluteUrl(string $baseUrl, string $url): string`
  - `reminderEmail(array $user, array $digest, string $baseUrl, string $unsubscribeUrl): array{subject: string, html: string, text: string, headers: array<string,string>}`
    - The headers are `List-Unsubscribe: <url>` and `List-Unsubscribe-Post: List-Unsubscribe=One-Click`.
  - `emailSmtpSend()` accepts an optional `headers` key and passes each entry to `PHPMailer::addCustomHeader()`. The dry-run JSON line gains a `headers` key.

- [ ] **Step 1: Write the failing tests**

`tests/ReminderEmailTest.php`:

```php
<?php
// wcma-calculator/tests/ReminderEmailTest.php
require_once __DIR__ . '/../reminder-email.php';

use PHPUnit\Framework\TestCase;

final class ReminderEmailTest extends TestCase
{
    private const BASE = 'https://x.test/classing/';
    private const UNSUB = 'https://x.test/classing/unsubscribe.php?u=5&t=abc';

    private function digest(array $o = []): array {
        return array_merge([
            'event' => ['id' => 10, 'name' => 'Fall <Sprint>', 'event_date' => '2026-10-11'],
            'daysOut' => 7, 'daysUntil' => 7,
            'items' => [
                ['kind' => 'tech_sheet', 'state' => 'todo', 'label' => 'Submit a tech sheet for #42', 'detail' => 'Every car needs a tech sheet for every event.',
                 'action' => ['label' => 'Submit tech sheet', 'url' => 'tech-sheets.php?action=new&car_id=3&event_id=10']],
                ['kind' => 'car_tech', 'state' => 'todo', 'label' => 'Car tech for #42', 'detail' => '', 'action' => null],
            ],
        ], $o);
    }

    private function mail(array $o = []): array {
        return reminderEmail(['name' => 'Jordan Lee'], $this->digest($o), self::BASE, self::UNSUB);
    }

    public function testSubjectCountsTheThingsToDoAndNamesTheEvent(): void
    {
        $this->assertSame('WCMA reminder: 2 things to do before Fall <Sprint> (Oct 11)', $this->mail()['subject']);
        $one = $this->mail(['items' => [$this->digest()['items'][1]]]);
        $this->assertSame('WCMA reminder: 1 thing to do before Fall <Sprint> (Oct 11)', $one['subject']);
    }

    public function testBodyListsEachTodoWithAnAbsoluteLink(): void
    {
        $m = $this->mail();
        $this->assertStringContainsString("Hi Jordan Lee,\n\nFall <Sprint> is in 7 days (Sunday, October 11). You still have 2 things to do:", $m['text']);
        $this->assertStringContainsString("- Submit a tech sheet for #42\n  Every car needs a tech sheet for every event.\n  Submit tech sheet: https://x.test/classing/tech-sheets.php?action=new&car_id=3&event_id=10\n", $m['text']);
        $this->assertStringContainsString("- Car tech for #42\n", $m['text']);
        $this->assertStringContainsString('https://x.test/classing/index.php', $m['text']);
        $this->assertStringContainsString(EVENTS_NOT_REGISTERING, $m['text']);

        $this->assertStringContainsString('Fall &lt;Sprint&gt; is in 7 days', $m['html']);
        $this->assertStringContainsString('href="https://x.test/classing/tech-sheets.php?action=new&amp;car_id=3&amp;event_id=10">Submit tech sheet</a>', $m['html']);
        $this->assertStringContainsString('href="https://x.test/classing/index.php"', $m['html']);
        $this->assertStringContainsString('cid:wcma-logo', $m['html']);
    }

    public function testTheDayBeforeSaysTomorrow(): void
    {
        $this->assertStringContainsString('Fall <Sprint> is tomorrow (Sunday, October 11).', $this->mail(['daysOut' => 2, 'daysUntil' => 1])['text']);
    }

    public function testEveryEmailCarriesAnUnsubscribeLinkAndOneClickHeaders(): void
    {
        $m = $this->mail();
        $this->assertSame(['List-Unsubscribe' => '<' . self::UNSUB . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'], $m['headers']);
        $this->assertStringContainsString('Unsubscribe: ' . self::UNSUB, $m['text']);
        $this->assertStringContainsString('href="https://x.test/classing/unsubscribe.php?u=5&amp;t=abc">Unsubscribe from reminder emails</a>', $m['html']);
    }

    public function testNoBannedWording(): void
    {
        $m = $this->mail();
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', $m['subject'] . $m['html'] . $m['text']);
    }

    public function testAbsoluteUrls(): void
    {
        $this->assertSame('https://x.test/classing/garage.php?car=3', reminderAbsoluteUrl('https://x.test/classing/', '/garage.php?car=3'));
        $this->assertSame('https://other.test/a', reminderAbsoluteUrl('https://x.test/classing', 'https://other.test/a'));
    }

    public function testTheMailerSendsCustomHeaders(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../email-helpers.php'));
        $this->assertStringContainsString("foreach (\$message['headers'] ?? [] as \$name => \$value) {\n            \$mail->addCustomHeader((string)\$name, (string)\$value);", $src);
    }
}
```

In `tests/PretechEmailTest.php`, `testMailDryRunLogsInsteadOfSending()`, replace the lines from `$this->assertTrue($ok);` through `unlink($file);` with:

```php
        $this->assertTrue($ok);
        $this->assertTrue(emailSmtpSend([['jane@example.com', 'Jane']], ['subject' => 'Hi', 'html' => '', 'text' => 't', 'headers' => ['List-Unsubscribe' => '<https://x.test/u>']]));
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $this->assertCount(2, $lines);
        $entry = json_decode($lines[0], true);
        $this->assertSame([['jane@example.com', 'Jane']], $entry['to']);
        $this->assertSame('Hello', $entry['subject']);
        $this->assertSame('plain body', $entry['text']);
        $this->assertSame([], $entry['headers']);
        $this->assertSame(['List-Unsubscribe' => '<https://x.test/u>'], json_decode($lines[1], true)['headers']);
        unlink($file);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter 'ReminderEmailTest|PretechEmailTest'`
Expected: ERROR. `reminder-email.php` doesn't exist, and PretechEmailTest reports an undefined `headers` key.

- [ ] **Step 3: Send and log custom headers**

In `email-helpers.php`, change the docblock line of `emailSmtpSend()` to:

```php
 * @param array{subject: string, html: string, text: string, headers?: array<string,string>} $message
```

Replace the dry-run line:

```php
        return file_put_contents(WCMA_MAIL_LOG, json_encode(['to' => $to, 'subject' => $message['subject'], 'text' => $message['text']]) . "\n", FILE_APPEND) !== false;
```

with:

```php
        return file_put_contents(WCMA_MAIL_LOG, json_encode(['to' => $to, 'subject' => $message['subject'], 'text' => $message['text'], 'headers' => $message['headers'] ?? []]) . "\n", FILE_APPEND) !== false;
```

After `$mail->AltBody = $message['text'];`, add:

```php
        foreach ($message['headers'] ?? [] as $name => $value) {
            $mail->addCustomHeader((string)$name, (string)$value);
        }
```

- [ ] **Step 4: Create `reminder-email.php`**

```php
<?php
// wcma-calculator/reminder-email.php
//
// The reminder digest email (spec §7): one per user per tagged event, listing what is still to do.
// A pure renderer, branded like the other hub emails (logo via cid:wcma-logo). Every message carries
// an unsubscribe link and the List-Unsubscribe headers mail apps use for one-click unsubscribe.
require_once __DIR__ . '/pretech-email.php';   // pretechEmailWrap/Para/Link, h()
require_once __DIR__ . '/events-lib.php';      // EVENTS_NOT_REGISTERING

/** A readiness action URL (relative to the hub) made absolute for an email. Full URLs are left alone. */
function reminderAbsoluteUrl(string $baseUrl, string $url): string {
    return preg_match('#^https?://#i', $url) ? $url : rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
}

/**
 * @param array $user   a users row (name)
 * @param array $digest one reminderDigests() entry
 * @return array{subject: string, html: string, text: string, headers: array<string,string>}
 */
function reminderEmail(array $user, array $digest, string $baseUrl, string $unsubscribeUrl): array {
    $event = $digest['event'];
    $date = strtotime((string)$event['event_date']);
    $n = count($digest['items']);
    $things = $n . ' ' . ($n === 1 ? 'thing' : 'things');
    $days = (int)$digest['daysUntil'];
    $intro = $event['name'] . ' is ' . ($days === 1 ? 'tomorrow' : 'in ' . $days . ' days') . ' (' . date('l, F j', $date) . ').'
        . ' You still have ' . $things . ' to do:';
    $home = rtrim($baseUrl, '/') . '/index.php';
    $why = 'You get these emails because you turned on reminder emails in the WCMA Hub.';

    $text = 'Hi ' . $user['name'] . ",\n\n" . $intro . "\n\n";
    $html = pretechEmailPara('Hi ' . $user['name'] . ',') . pretechEmailPara($intro) . '<ol>';
    foreach ($digest['items'] as $item) {
        $text .= '- ' . $item['label'] . "\n";
        $html .= '<li><strong>' . h((string)$item['label']) . '</strong>';
        if (trim((string)($item['detail'] ?? '')) !== '') {
            $text .= '  ' . $item['detail'] . "\n";
            $html .= '<br>' . h((string)$item['detail']);
        }
        if (!empty($item['action']['url'])) {
            $url = reminderAbsoluteUrl($baseUrl, (string)$item['action']['url']);
            $text .= '  ' . $item['action']['label'] . ': ' . $url . "\n";
            $html .= '<br><a href="' . h($url) . '">' . h((string)$item['action']['label']) . '</a>';
        }
        $html .= '</li>';
    }
    $html .= '</ol>' . pretechEmailLink($home, 'Open your Home page') . pretechEmailPara(EVENTS_NOT_REGISTERING)
        . '<p style="color:#666;font-size:0.9em">' . h($why) . ' <a href="' . h($unsubscribeUrl) . '">Unsubscribe from reminder emails</a></p>';
    $text .= "\nSee everything on your Home page: " . $home . "\n\n" . EVENTS_NOT_REGISTERING . "\n\n" . $why . "\nUnsubscribe: " . $unsubscribeUrl . "\n";

    return [
        'subject' => 'WCMA reminder: ' . $things . ' to do before ' . $event['name'] . ' (' . date('M j', $date) . ')',
        'html' => pretechEmailWrap('EVENT REMINDER', $html),
        'text' => $text,
        'headers' => ['List-Unsubscribe' => '<' . $unsubscribeUrl . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'],
    ];
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php phpunit.phar --filter 'ReminderEmailTest|PretechEmailTest'`, then `php phpunit.phar`.
Expected: both OK.

- [ ] **Step 6: Commit**

```bash
git add reminder-email.php email-helpers.php tests/ReminderEmailTest.php tests/PretechEmailTest.php
git commit -m "feat(hub): reminder digest email with unsubscribe link and one-click headers"
```

---

### Task 4: The daily run, the CLI and the cron wrapper

**Files:**
- Create: `reminder-run.php`, `reminders.php`, `reminders-cron.sh`
- Modify: `.htaccess` (deny web access), `../.gitattributes` (LF for `*.sh`), `.gitignore` (`data/*.log`), `README.md` (setup section)
- Test: `tests/RemindersRunTest.php` and `tests/ReminderCronSourceTest.php` (create)

**Interfaces:**
- Consumes:
  - Task 1: `db_get_reminder_users()`, `db_reminder_logged()`, `db_log_reminder()`
  - Task 2: `reminderDigests()`, `reminderUnsubscribeUrl()`, `reminderSecret()`
  - Task 3: `reminderEmail()`
  - `buildReadiness()` and `loadReadinessInputs(PDO $pdo, int $userId, string $today)` (readiness-lib.php)
  - `emailSmtpSend()`
- Produces:
  - `remindersRun(PDO $pdo, string $today, string $baseUrl, callable $sendFn): array{users: int, sent: int, skipped: int, failed: int}`
  - `php reminders.php [--today=YYYY-MM-DD] [--mail-log=FILE] [--base-url=URL]`. It exits 0 on success, 1 when a send failed or no base URL is set, and 2 on bad options.
  - `reminders-cron.sh`: executable, LF line endings, and it appends to `data/reminders.log`.

- [ ] **Step 1: Write the failing tests**

`tests/RemindersRunTest.php`:

```php
<?php
// wcma-calculator/tests/RemindersRunTest.php
require_once __DIR__ . '/../reminder-run.php';

use PHPUnit\Framework\TestCase;

final class RemindersRunTest extends TestCase
{
    private PDO $pdo;
    private array $sent = [];

    protected function setUp(): void
    {
        $this->pdo = make_temp_pdo();
        $this->sent = [];
    }

    private function user(string $email, string $name, bool $on = true): int {
        $u = db_create_user($this->pdo, ['email' => $email, 'name' => $name, 'password_hash' => 'x', 'google_id' => null]);
        if ($on) db_set_user_reminders($this->pdo, $u, true);
        return $u;
    }

    /** Tags the user's car $number for a new event on $date; returns the event id. */
    private function going(int $u, string $date, string $number = '42'): int {
        $e = db_create_event($this->pdo, 'Event ' . $date, $date, null);
        db_tag_event($this->pdo, $u, $e, test_make_car($this->pdo, $u, $number));
        return $e;
    }

    private function run(string $today = '2026-10-01', ?callable $send = null): array {
        return remindersRun($this->pdo, $today, 'https://x.test/classing/', $send ?? function (array $to, array $m): bool {
            $this->sent[] = [$to, $m];
            return true;
        });
    }

    public function testEmailsOneDigestPerDueEventAndRecordsIt(): void
    {
        $u = $this->user('jordan@example.com', 'Jordan Lee');
        $e = $this->going($u, '2026-10-08');
        $this->going($u, '2026-10-30', '17');   // 29 days away: not due

        $this->assertSame(['users' => 1, 'sent' => 1, 'skipped' => 0, 'failed' => 0], $this->run());
        $this->assertCount(1, $this->sent);
        [$to, $m] = $this->sent[0];
        $this->assertSame([['jordan@example.com', 'Jordan Lee']], $to);
        $this->assertStringContainsString('before Event 2026-10-08', $m['subject']);
        $this->assertStringContainsString('Declare class for #42', $m['text']);
        $this->assertStringContainsString('Submit a tech sheet for #42', $m['text']);
        $this->assertStringStartsWith('<https://x.test/classing/unsubscribe.php?u=' . $u . '&t=', $m['headers']['List-Unsubscribe']);
        $this->assertTrue(db_reminder_logged($this->pdo, $u, $e, 7));
    }

    public function testRunningAgainTheSameDaySendsNothing(): void
    {
        $u = $this->user('jordan@example.com', 'Jordan Lee');
        $this->going($u, '2026-10-08');
        $this->run();
        $this->assertSame(['users' => 1, 'sent' => 0, 'skipped' => 1, 'failed' => 0], $this->run());
        $this->assertCount(1, $this->sent);
    }

    public function testTheNextWindowSendsAgain(): void
    {
        $u = $this->user('jordan@example.com', 'Jordan Lee');
        $e = $this->going($u, '2026-10-08');
        $this->run('2026-10-01');                 // 7 days out
        $this->run('2026-10-06');                 // 2 days out
        $this->assertCount(2, $this->sent);
        $this->assertTrue(db_reminder_logged($this->pdo, $u, $e, 2));
    }

    public function testAMissedDayCatchesUpWithinTheWindow(): void
    {
        $u = $this->user('jordan@example.com', 'Jordan Lee');
        $e = $this->going($u, '2026-10-11');      // 10 days out: the 14-day reminder is still owed
        $this->run();
        $this->assertCount(1, $this->sent);
        $this->assertTrue(db_reminder_logged($this->pdo, $u, $e, 14));
    }

    public function testNothingForOptedOutInactiveOrUntaggedUsers(): void
    {
        $off = $this->user('off@example.com', 'Off Person', false);
        $this->going($off, '2026-10-08');
        $gone = $this->user('gone@example.com', 'Gone Person');
        $this->going($gone, '2026-10-08');
        db_set_user_active($this->pdo, $gone, false);
        $this->user('idle@example.com', 'Idle Person');   // opted in, nothing tagged

        $this->assertSame(['users' => 1, 'sent' => 0, 'skipped' => 0, 'failed' => 0], $this->run());
        $this->assertSame([], $this->sent);
    }

    public function testNothingOnTheDayOrAfter(): void
    {
        $u = $this->user('jordan@example.com', 'Jordan Lee');
        $this->going($u, '2026-10-01');
        $this->going($u, '2026-09-30', '17');
        $this->run();
        $this->assertSame([], $this->sent);
    }

    public function testAFailedSendIsNotRecordedSoTheNextRunRetries(): void
    {
        $u = $this->user('jordan@example.com', 'Jordan Lee');
        $e = $this->going($u, '2026-10-08');
        $boom = function (array $to, array $m): bool { throw new RuntimeException('smtp down'); };
        $this->assertSame(['users' => 1, 'sent' => 0, 'skipped' => 0, 'failed' => 1], $this->run('2026-10-01', $boom));
        $this->assertSame(['users' => 1, 'sent' => 0, 'skipped' => 0, 'failed' => 1], $this->run('2026-10-01', fn(array $to, array $m): bool => false));
        $this->assertFalse(db_reminder_logged($this->pdo, $u, $e, 7));
        $this->assertSame(1, $this->run()['sent']);
    }

    public function testTheUnsubscribeLinkIsSignedForThatUser(): void
    {
        $u = $this->user('jordan@example.com', 'Jordan Lee');
        $this->going($u, '2026-10-08');
        $this->run();
        $this->assertSame(1, preg_match('/unsubscribe\.php\?u=(\d+)&t=([0-9a-f]{64})>$/', $this->sent[0][1]['headers']['List-Unsubscribe'], $m));
        $this->assertSame($u, (int)$m[1]);
        $this->assertTrue(reminderTokenValid($u, $m[2], reminderSecret($this->pdo)));
    }
}
```

`tests/ReminderCronSourceTest.php`:

```php
<?php
// wcma-calculator/tests/ReminderCronSourceTest.php
//
// reminders.php needs config.php and the real database, and reminders-cron.sh is a shell script, so
// these are source-level and repository checks, plus one run of the CLI's option parsing (which exits
// before loading anything).
use PHPUnit\Framework\TestCase;

final class ReminderCronSourceTest extends TestCase
{
    private function src(string $file): string {
        return str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../' . $file));
    }

    public function testCliGuardOptionsAndBaseUrlCheck(): void
    {
        $src = $this->src('reminders.php');
        $guard = strpos($src, "if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }");
        $this->assertNotFalse($guard);
        $this->assertLessThan(strpos($src, 'require '), $guard);
        $this->assertStringContainsString("config_default('SITE_BASE_URL', '')", $src);
        $this->assertMatchesRegularExpression("/if \\(\\\$baseUrl === ''\\) \\{\\s*fwrite\\(STDERR, \"Set SITE_BASE_URL/", $src);
        $this->assertStringContainsString("remindersRun(\$pdo, \$today, \$baseUrl, 'emailSmtpSend')", $src);
        $this->assertStringContainsString("date_default_timezone_set('America/Denver');", $src);
    }

    public function testUnknownOptionsAreRefusedBeforeAnythingLoads(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../reminders.php') . ' --bogus 2>&1', $out, $code);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('Unknown option: --bogus', implode("\n", $out));
    }

    public function testCronWrapperRunsTheCliAndLogs(): void
    {
        $sh = $this->src('reminders-cron.sh');
        $this->assertStringStartsWith("#!/bin/sh\n", $sh);
        $this->assertStringContainsString('cd "$(dirname "$0")" || exit 1', $sh);
        $this->assertStringContainsString('reminders.php >> data/reminders.log 2>&1', $sh);
        $this->assertStringContainsString("*.sh text eol=lf", file_get_contents(__DIR__ . '/../../.gitattributes'));
        $out = shell_exec('git -C ' . escapeshellarg(__DIR__ . '/..') . ' ls-files -s reminders-cron.sh');
        if (!is_string($out) || $out === '') $this->markTestSkipped('git is not available');
        $this->assertStringStartsWith('100755', $out);
    }

    public function testCliFilesAreNotServedOverTheWeb(): void
    {
        $htaccess = $this->src('.htaccess');
        $this->assertStringContainsString('reminders\.php', $htaccess);
        $this->assertStringContainsString('reminders-cron\.sh', $htaccess);
        $this->assertStringContainsString('data/*.log', $this->src('.gitignore'));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter 'RemindersRunTest|ReminderCronSourceTest'`
Expected: ERROR or FAIL, because `reminder-run.php`, `reminders.php` and `reminders-cron.sh` don't exist yet.

- [ ] **Step 3: Create `reminder-run.php`**

```php
<?php
// wcma-calculator/reminder-run.php
//
// One daily reminder run (spec §7): for each opted-in, active account, build its readiness, find the
// reminders due today, and email one digest per event, once per window (reminder_log). Session-free,
// with an injectable send function, so it is unit-testable. Callers must have loaded db.php;
// reminders.php (the cron entry point) passes emailSmtpSend.
require_once __DIR__ . '/tech-status.php';
require_once __DIR__ . '/gear-lib.php';
require_once __DIR__ . '/readiness-lib.php';
require_once __DIR__ . '/view_helpers.php';
require_once __DIR__ . '/reminders-lib.php';
require_once __DIR__ . '/reminder-email.php';

/**
 * A failed send is logged and counted, not recorded, so the next run tries again.
 *
 * @param callable $sendFn function(array $to, array $message): bool; $to is a list of [email, name]
 * @return array{users: int, sent: int, skipped: int, failed: int}
 */
function remindersRun(PDO $pdo, string $today, string $baseUrl, callable $sendFn): array {
    $summary = ['users' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];
    $secret = reminderSecret($pdo);
    foreach (db_get_reminder_users($pdo) as $user) {
        $summary['users']++;
        $uid = (int)$user['id'];
        foreach (reminderDigests(buildReadiness(loadReadinessInputs($pdo, $uid, $today)), $today) as $digest) {
            $eventId = (int)$digest['event']['id'];
            if (db_reminder_logged($pdo, $uid, $eventId, $digest['daysOut'])) {
                $summary['skipped']++;
                continue;
            }
            $message = reminderEmail($user, $digest, $baseUrl, reminderUnsubscribeUrl($baseUrl, $uid, $secret));
            try {
                $ok = (bool)$sendFn([[(string)$user['email'], (string)$user['name']]], $message);
            } catch (Throwable $e) {
                error_log('Reminder email error: ' . $e->getMessage());
                $ok = false;
            }
            if ($ok) {
                db_log_reminder($pdo, $uid, $eventId, $digest['daysOut']);
                $summary['sent']++;
            } else {
                $summary['failed']++;
            }
        }
    }
    return $summary;
}
```

- [ ] **Step 4: Create `reminders.php`**

```php
<?php
// wcma-calculator/reminders.php — CLI only. The daily reminder run (spec §7). The IONOS cron job runs
// it through reminders-cron.sh, because IONOS cron commands can only be a path.
//
//   php reminders.php                      send today's reminders
//   php reminders.php --today=2026-10-04   pretend it is that day (testing)
//   php reminders.php --mail-log=FILE      write the emails to FILE as JSON lines instead of sending
//   php reminders.php --base-url=URL       link base for the emails (default: SITE_BASE_URL in config.php)
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$opts = ['today' => null, 'mail-log' => null, 'base-url' => null];
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--(today|mail-log|base-url)=(.+)$/', $arg, $m)) {
        fwrite(STDERR, "Unknown option: $arg\n");
        exit(2);
    }
    $opts[$m[1]] = $m[2];
}
if ($opts['mail-log'] !== null) define('WCMA_MAIL_LOG', $opts['mail-log']);

date_default_timezone_set('America/Denver');
require __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';
require __DIR__ . '/email-helpers.php';
require __DIR__ . '/reminder-run.php';

$today = $opts['today'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
    fwrite(STDERR, "--today must be YYYY-MM-DD\n");
    exit(2);
}
$baseUrl = rtrim(trim((string)($opts['base-url'] ?? config_default('SITE_BASE_URL', ''))), '/');
if ($baseUrl === '') {
    fwrite(STDERR, "Set SITE_BASE_URL in config.php (the public URL of this folder) or pass --base-url=URL. Reminder emails need full links.\n");
    exit(1);
}

$pdo = db_connect();
db_init($pdo);
$s = remindersRun($pdo, $today, $baseUrl, 'emailSmtpSend');
echo date('Y-m-d H:i:s') . " reminders for {$today}: {$s['users']} opted in, {$s['sent']} sent, {$s['skipped']} already sent, {$s['failed']} failed\n";
exit($s['failed'] > 0 ? 1 : 0);
```

- [ ] **Step 5: Create `reminders-cron.sh`**

```sh
#!/bin/sh
# wcma-calculator/reminders-cron.sh — the daily reminder cron job. IONOS cron commands can only be a
# path (no spaces or options), so the cron job runs this file, which finds the PHP CLI and runs
# reminders.php. Output is appended to data/reminders.log (data/ is not served over the web).
# Set PHP_BIN to override the PHP binary.
cd "$(dirname "$0")" || exit 1
if [ -z "$PHP_BIN" ]; then
    for candidate in /usr/bin/php8.3-cli /usr/bin/php8.3 /usr/bin/php-cli /usr/bin/php php; do
        if command -v "$candidate" >/dev/null 2>&1; then PHP_BIN="$candidate"; break; fi
    done
fi
if [ -z "$PHP_BIN" ]; then echo "$(date) reminders: no PHP CLI found" >> data/reminders.log; exit 1; fi
exec "$PHP_BIN" reminders.php >> data/reminders.log 2>&1
```

Then make sure it has LF endings and is executable in git:

1. In the repository root `.gitattributes`, add the line `*.sh text eol=lf`.
2. Run `git add ../.gitattributes reminders-cron.sh && git update-index --chmod=+x reminders-cron.sh`.
3. Run `git ls-files --eol reminders-cron.sh`. Expected: `i/lf w/lf`. If the working copy says `w/crlf`, run `git rm --cached reminders-cron.sh && git checkout -- reminders-cron.sh`, then `git add reminders-cron.sh && git update-index --chmod=+x reminders-cron.sh` again.

- [ ] **Step 6: Keep the CLI files off the web, ignore the log, and document the setup**

In `.htaccess` (in `wcma-calculator/`), extend the `FilesMatch` list. Replace:

```
<FilesMatch "^(autopull\.sh|git-autopull\.log|CLAUDE\.md|README\.md|phpunit\.xml|phpunit\.phar|reset-hub-db\.php|seed-hub-db\.php|hub-db-tools\.php)$">
```

with:

```
<FilesMatch "^(autopull\.sh|git-autopull\.log|CLAUDE\.md|README\.md|phpunit\.xml|phpunit\.phar|reset-hub-db\.php|seed-hub-db\.php|hub-db-tools\.php|reminders\.php|reminders-cron\.sh|reminder-run\.php)$">
```

In `.gitignore` (in `wcma-calculator/`), add the line `data/*.log`.

Append to `README.md`:

```markdown
## Reminder emails (daily cron)

Competitors can turn on reminder emails in their Profile, or with the checkbox shown the first time
they tag an event. Once a day, `reminders.php` emails each of them one digest per tagged event that is
about 2 weeks, 1 week or 2 days away and still has something to do. Nobody gets an email when they are
all set. Every email has an unsubscribe link.

Setup on IONOS Web Hosting Plus:

1. In `config.php`, set `SITE_BASE_URL` to the public URL of this folder, e.g. `https://221racing.com/classing`.
   Without it, `reminders.php` refuses to run (emails need full links).
2. Over SSH, check which PHP CLI exists: `ls /usr/bin/php*`. `reminders-cron.sh` tries `php8.3-cli`,
   `php8.3`, `php-cli`, then `php`. Run it once by hand (`sh reminders-cron.sh`) and read `data/reminders.log`.
   If the file is not executable after a deploy, run `chmod +x reminders-cron.sh`.
3. Hosting → Cron jobs → Create cron job: Type **UnixCron**, Command = the full server path of
   `reminders-cron.sh` (only letters, digits and `- _ . /` are allowed), Interval **Simple**, **daily**,
   in the morning.

Testing without sending anything:
`php reminders.php --today=2026-10-04 --mail-log=/tmp/reminders-mail.log --base-url=https://example.test`
writes the emails to the file as JSON lines. Each reminder is sent once per window, so run it against a
copy of the database or reset it afterwards.
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php phpunit.phar --filter 'RemindersRunTest|ReminderCronSourceTest'`, then `php phpunit.phar`, then `php -l reminders.php && php -l reminder-run.php`.
Expected: all OK. `testCronWrapperRunsTheCliAndLogs` needs the index entry from Step 5 to report `100755`.

- [ ] **Step 8: Commit**

```bash
git add reminder-run.php reminders.php reminders-cron.sh .htaccess .gitignore README.md ../.gitattributes tests/RemindersRunTest.php tests/ReminderCronSourceTest.php
git update-index --chmod=+x reminders-cron.sh
git commit -m "feat(hub): daily reminder run, reminders.php CLI and IONOS cron wrapper"
```

---

### Task 5: The unsubscribe page

**Files:**
- Create: `unsubscribe.php`
- Modify: `reminders-lib.php`. Add `renderUnsubscribeHtml()`.
- Test: `tests/UnsubscribePageTest.php` and `tests/UnsubscribeSourceTest.php` (create)

**Interfaces:**
- Consumes: Task 2's `reminderTokenValid()` and `reminderSecret()`, Task 1's `db_set_user_reminders()`, and `renderPageStart()` / `renderPageEnd()`.
- Produces:
  - `renderUnsubscribeHtml(string $state, int $userId, string $token): string`, where `$state` is `'confirm'`, `'done'` or `'invalid'`
  - `unsubscribe.php?u={id}&t={token}`. A GET shows a confirm button. A POST, with `u` and `t` in the query string or the body, turns reminders off. An invalid link gets a 400 status and "This link isn't valid".

- [ ] **Step 1: Write the failing tests**

`tests/UnsubscribePageTest.php`:

```php
<?php
// wcma-calculator/tests/UnsubscribePageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../reminders-lib.php';

use PHPUnit\Framework\TestCase;

final class UnsubscribePageTest extends TestCase
{
    public function testConfirmPostsTheIdAndTokenBack(): void
    {
        $html = renderUnsubscribeHtml('confirm', 5, 'ab"cd');
        $this->assertStringContainsString('Stop reminder emails?', $html);
        $this->assertStringContainsString('<form method="post" action="unsubscribe.php">', $html);
        $this->assertStringContainsString('<input type="hidden" name="u" value="5">', $html);
        $this->assertStringContainsString('<input type="hidden" name="t" value="ab&quot;cd">', $html);
        $this->assertStringContainsString('<button type="submit" class="hub-btn">Unsubscribe</button>', $html);
    }

    public function testDoneAndInvalid(): void
    {
        $done = renderUnsubscribeHtml('done', 5, 'x');
        $this->assertStringContainsString('You won&#039;t get reminder emails any more.', $done);
        $this->assertStringContainsString('href="profile.php"', $done);
        $this->assertStringNotContainsString('<form', $done);

        $bad = renderUnsubscribeHtml('invalid', 0, 'x');
        $this->assertStringContainsString('This link isn&#039;t valid', $bad);
        $this->assertStringNotContainsString('<form', $bad);
        $this->assertSame($bad, renderUnsubscribeHtml('anything-else', 0, 'x'));
    }
}
```

`tests/UnsubscribeSourceTest.php`:

```php
<?php
// wcma-calculator/tests/UnsubscribeSourceTest.php — unsubscribe.php needs a request, so these are source-level checks.
use PHPUnit\Framework\TestCase;

final class UnsubscribeSourceTest extends TestCase
{
    private function src(): string {
        return str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../unsubscribe.php'));
    }

    public function testOnlyAPostWithAValidTokenTurnsRemindersOff(): void
    {
        $src = $this->src();
        $valid = strpos($src, '$valid = reminderTokenValid($uid, $token, reminderSecret($pdo)) && db_find_user_by_id($pdo, $uid) !== null;');
        $post = strpos($src, "if (\$valid && \$_SERVER['REQUEST_METHOD'] === 'POST') {\n    db_set_user_reminders(\$pdo, \$uid, false);");
        $this->assertNotFalse($valid);
        $this->assertNotFalse($post);
        $this->assertLessThan($post, $valid);
        $this->assertSame(1, substr_count($src, 'db_set_user_reminders('));
    }

    public function testInvalidLinksGetA400AndTheIdMustBeDigits(): void
    {
        $src = $this->src();
        $this->assertStringContainsString('http_response_code(400);', $src);
        $this->assertStringContainsString("\$uid = ctype_digit(\$param('u')) ? (int)\$param('u') : 0;", $src);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter 'UnsubscribePageTest|UnsubscribeSourceTest'`
Expected: ERROR, because `renderUnsubscribeHtml()` is undefined and `unsubscribe.php` is missing.

- [ ] **Step 3: Add the renderer**

Append to `reminders-lib.php`:

```php
/**
 * The unsubscribe page body. $state: 'confirm' (a valid link: show the button), 'done' (reminders
 * are now off), or anything else for an invalid link.
 */
function renderUnsubscribeHtml(string $state, int $userId, string $token): string {
    if ($state === 'done') {
        return '<h1 class="hub-page-title">You&#039;re unsubscribed</h1>'
            . '<p>' . h('You won\'t get reminder emails any more.') . ' You can turn them back on in your <a href="profile.php">Profile</a>.</p>';
    }
    if ($state === 'confirm') {
        return '<h1 class="hub-page-title">Stop reminder emails?</h1>'
            . '<p>' . h('You\'ll stop getting emails before the events you\'re going to. Your Home page still lists what\'s left to do.') . '</p>'
            . '<form method="post" action="unsubscribe.php">'
            . '<input type="hidden" name="u" value="' . $userId . '">'
            . '<input type="hidden" name="t" value="' . h($token) . '">'
            . '<button type="submit" class="hub-btn">Unsubscribe</button></form>';
    }
    return '<h1 class="hub-page-title">' . h('This link isn\'t valid') . '</h1>'
        . '<p>It may have been copied incompletely. You can turn reminder emails off in your <a href="profile.php">Profile</a>.</p>';
}
```

- [ ] **Step 4: Create `unsubscribe.php`**

```php
<?php
// wcma-calculator/unsubscribe.php — turns reminder emails off from the link in a reminder email
// (spec §7). No sign-in needed: the link carries the account id and an HMAC token
// (reminderUnsubscribeUrl()), which stands in for a CSRF token. A GET only shows a confirm button, so
// link scanners that open every URL in an email can't unsubscribe anyone. A POST with a valid token
// turns reminders off; mail apps' one-click unsubscribe (List-Unsubscribe-Post) POSTs to the same URL.
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/reminders-lib.php';

date_default_timezone_set('America/Denver');
$pdo = db_connect();
db_init($pdo);

$param = fn(string $k): string => is_string($_GET[$k] ?? null) ? $_GET[$k] : (is_string($_POST[$k] ?? null) ? $_POST[$k] : '');
$uid = ctype_digit($param('u')) ? (int)$param('u') : 0;
$token = $param('t');
$valid = reminderTokenValid($uid, $token, reminderSecret($pdo)) && db_find_user_by_id($pdo, $uid) !== null;

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    db_set_user_reminders($pdo, $uid, false);
    $state = 'done';
} elseif ($valid) {
    $state = 'confirm';
} else {
    http_response_code(400);
    $state = 'invalid';
}

renderPageStart('Reminder emails', '');
echo renderUnsubscribeHtml($state, $uid, $token);
renderPageEnd();
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php phpunit.phar --filter 'UnsubscribePageTest|UnsubscribeSourceTest'`, then `php phpunit.phar`, then `php -l unsubscribe.php`.
Expected: all OK.

- [ ] **Step 6: Commit**

```bash
git add reminders-lib.php unsubscribe.php tests/UnsubscribePageTest.php tests/UnsubscribeSourceTest.php
git commit -m "feat(hub): unsubscribe page for reminder emails (confirm on GET, turn off on POST)"
```

---
### Task 6: Opting in (Profile and the first tag)

**Files:**
- Modify: `reminders-lib.php`. Add `reminderOptInFieldsHtml()` and `remindersRecordTagChoice()`.
- Modify: `home-page.php` and `index.php` (the Home tag forms), and `garage-page.php` and `garage.php` (the car page's "Bring this car to another event" form).
- Modify: `profile.php` (reminder emails on/off) and `css/hub.css`.
- Test: create `tests/RemindersChoiceTest.php` and `tests/RemindersUiSourceTest.php`. Modify `tests/HomePageTest.php` and `tests/GaragePageTest.php`.

**Interfaces:**
- Consumes: Task 1's `db_set_user_reminders()`, and Task 2's `remindersShouldOffer()` and `COPY_REMINDER_OPT_IN`.
- Produces:
  - `reminderOptInFieldsHtml(): string` renders a hidden `offer_reminders=1` field plus an unchecked `reminders` checkbox labelled `COPY_REMINDER_OPT_IN`.
  - `remindersRecordTagChoice(PDO $pdo, int $userId, array $post): string`. It only acts when the form offered the checkbox **and** the user still hasn't chosen. It records the choice and returns `' We\'ll email you reminders before your events.'` when turned on, and `''` otherwise.
  - `homeRenderTagForm(array $event, array $cars, string $csrf, bool $offerReminders = false)`
  - `renderHomeHtml($vm)` reads `$vm['offerReminders'] ?? false`.
  - `renderGarageCarHtml($vm)` reads `$vm['offerReminders'] ?? false`.
  - `profile.php` handles a POST with `action=reminders` and field `reminder_emails` (checkbox).

**Why the "still hasn't chosen" guard:** say a user turns reminders on in Profile in one tab, then submits a tag form in a stale tab where the box was offered and left unticked. That form must not turn reminders back off.

- [ ] **Step 1: Write the failing tests**

`tests/RemindersChoiceTest.php`:

```php
<?php
// wcma-calculator/tests/RemindersChoiceTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../reminders-lib.php';

use PHPUnit\Framework\TestCase;

final class RemindersChoiceTest extends TestCase
{
    private function setUpUser(): array {
        $pdo = make_temp_pdo();
        return [$pdo, db_create_user($pdo, ['email' => 'j@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null])];
    }

    private function row(PDO $pdo, int $u): array {
        return db_find_user_by_id($pdo, $u);
    }

    public function testNothingHappensWhenTheFormDidNotOfferTheBox(): void
    {
        [$pdo, $u] = $this->setUpUser();
        $this->assertSame('', remindersRecordTagChoice($pdo, $u, ['reminders' => '1']));
        $this->assertTrue(remindersShouldOffer($this->row($pdo, $u)));
    }

    public function testTickingTheBoxTurnsRemindersOn(): void
    {
        [$pdo, $u] = $this->setUpUser();
        $this->assertSame(' We\'ll email you reminders before your events.', remindersRecordTagChoice($pdo, $u, ['offer_reminders' => '1', 'reminders' => '1']));
        $this->assertSame(1, (int)$this->row($pdo, $u)['reminder_emails']);
        $this->assertFalse(remindersShouldOffer($this->row($pdo, $u)));
    }

    public function testLeavingItUntickedIsAChoiceToo(): void
    {
        [$pdo, $u] = $this->setUpUser();
        $this->assertSame('', remindersRecordTagChoice($pdo, $u, ['offer_reminders' => '1']));
        $this->assertSame(0, (int)$this->row($pdo, $u)['reminder_emails']);
        $this->assertFalse(remindersShouldOffer($this->row($pdo, $u)));
    }

    public function testAStaleFormCannotUndoAChoiceMadeElsewhere(): void
    {
        [$pdo, $u] = $this->setUpUser();
        db_set_user_reminders($pdo, $u, true);   // turned on in Profile
        $this->assertSame('', remindersRecordTagChoice($pdo, $u, ['offer_reminders' => '1']));
        $this->assertSame(1, (int)$this->row($pdo, $u)['reminder_emails']);
    }

    public function testTheOptInFields(): void
    {
        $html = reminderOptInFieldsHtml();
        $this->assertStringContainsString('<input type="hidden" name="offer_reminders" value="1">', $html);
        $this->assertStringContainsString('<input type="checkbox" name="reminders" value="1">', $html);
        $this->assertStringContainsString('Email me reminders for events I&#039;m going to.', $html);
        $this->assertStringNotContainsString('checked', $html);
    }
}
```

In `tests/HomePageTest.php`, add `require_once __DIR__ . '/../reminders-lib.php';` after the existing `require_once` lines, then add:

```php
    public function testTagFormsOfferRemindersOnlyWhenAsked(): void
    {
        $offered = renderHomeHtml($this->vm(['offerReminders' => true]));
        $this->assertStringContainsString('name="offer_reminders" value="1"', $offered);
        $this->assertStringContainsString('Email me reminders for events I&#039;m going to.', $offered);
        $this->assertStringNotContainsString('offer_reminders', renderHomeHtml($this->vm()));
        $this->assertStringNotContainsString('offer_reminders', renderHomeHtml($this->vm(['offerReminders' => false])));
    }
```

In `tests/GaragePageTest.php`, add `require_once __DIR__ . '/../reminders-lib.php';` after the existing `require_once` lines, then add:

```php
    public function testBringThisCarFormOffersRemindersOnlyWhenAsked(): void
    {
        $this->assertStringContainsString('name="offer_reminders" value="1"', renderGarageCarHtml($this->carVm(['offerReminders' => true])));
        $this->assertStringNotContainsString('offer_reminders', renderGarageCarHtml($this->carVm()));
    }
```

`tests/RemindersUiSourceTest.php`:

```php
<?php
// wcma-calculator/tests/RemindersUiSourceTest.php — index.php, garage.php and profile.php need a request, so these are source-level checks.
use PHPUnit\Framework\TestCase;

final class RemindersUiSourceTest extends TestCase
{
    private function src(string $file): string {
        return str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../' . $file));
    }

    public function testTagHandlersRecordTheChoiceOnlyAfterASuccessfulTag(): void
    {
        foreach (['index.php', 'garage.php'] as $file) {
            $src = $this->src($file);
            $this->assertStringContainsString("\$extra = \$r['ok'] ? remindersRecordTagChoice(\$pdo, \$uid, \$_POST) : '';", $src, $file);
            $this->assertStringContainsString("'Added to your events. ' . EVENTS_NOT_REGISTERING . \$extra", $src, $file);
            $this->assertStringContainsString("'offerReminders' => remindersShouldOffer(db_find_user_by_id(\$pdo, \$uid))", $src, $file);
            $this->assertStringContainsString("/reminders-lib.php'", $src, $file);
        }
    }

    public function testProfileTurnsRemindersOnAndOff(): void
    {
        $src = $this->src('profile.php');
        $this->assertStringContainsString("if (\$action === 'reminders') {\n        \$on = !empty(\$_POST['reminder_emails']);\n        db_set_user_reminders(\$pdo, (int)\$user['id'], \$on);", $src);
        $this->assertStringContainsString('name="reminder_emails" value="1"', $src);
        $this->assertStringContainsString('h(COPY_REMINDER_OPT_IN)', $src);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter 'RemindersChoiceTest|HomePageTest|GaragePageTest|RemindersUiSourceTest'`
Expected: FAIL or ERROR, because `remindersRecordTagChoice()` is undefined and nothing offers the box yet.

- [ ] **Step 3: Add the choice helpers**

Append to `reminders-lib.php`:

```php
/** The one-time "email me reminders" checkbox for tag forms, unticked. */
function reminderOptInFieldsHtml(): string {
    return '<input type="hidden" name="offer_reminders" value="1">'
        . '<label class="hub-reminder-opt"><input type="checkbox" name="reminders" value="1"> ' . h(COPY_REMINDER_OPT_IN) . '</label>';
}

/**
 * After a successful tag: when the form offered the checkbox and the user still hasn't chosen, record
 * the choice (ticked = on, unticked = off). Returns a sentence to add to the flash message, or ''.
 */
function remindersRecordTagChoice(PDO $pdo, int $userId, array $post): string {
    if (empty($post['offer_reminders']) || !remindersShouldOffer(db_find_user_by_id($pdo, $userId))) return '';
    $on = !empty($post['reminders']);
    db_set_user_reminders($pdo, $userId, $on);
    return $on ? ' We\'ll email you reminders before your events.' : '';
}
```

- [ ] **Step 4: Offer it on Home**

In `home-page.php`:
- Add `reminders-lib.php (reminderOptInFieldsHtml())` to the list of files callers must have loaded, in the header comment.
- Change `function homeRenderTagForm(array $event, array $cars, string $csrf): string {` to `function homeRenderTagForm(array $event, array $cars, string $csrf, bool $offerReminders = false): string {`.
- In that function, replace
  ```php
      $out .= '<button type="submit" class="hub-btn">I\'m going</button></form>';
  ```
  with
  ```php
      if ($offerReminders) $out .= reminderOptInFieldsHtml();
      $out .= '<button type="submit" class="hub-btn">I\'m going</button></form>';
  ```
- In `renderHomeHtml()`, after `$csrf = (string)$vm['csrf'];`, add `$offerReminders = !empty($vm['offerReminders']);`. Then change both calls: `homeRenderTagForm($ev['event'], $untaggedCars, $csrf)` becomes `homeRenderTagForm($ev['event'], $untaggedCars, $csrf, $offerReminders)`, and `homeRenderTagForm($event, $cars, $csrf)` becomes `homeRenderTagForm($event, $cars, $csrf, $offerReminders)`.

In `index.php`:
- Add `require __DIR__ . '/reminders-lib.php';` after `require __DIR__ . '/events-lib.php';`.
- Replace the `tag` case:
  ```php
          case 'tag':
              $r = eventsTagCar($pdo, $uid, (int)($_POST['event_id'] ?? 0), (int)($_POST['car_id'] ?? 0));
              setFlash($r['ok'] ? 'Added to your events. ' . EVENTS_NOT_REGISTERING : (string)$r['error'], $r['ok'] ? 'success' : 'error');
              break;
  ```
  with
  ```php
          case 'tag':
              $r = eventsTagCar($pdo, $uid, (int)($_POST['event_id'] ?? 0), (int)($_POST['car_id'] ?? 0));
              $extra = $r['ok'] ? remindersRecordTagChoice($pdo, $uid, $_POST) : '';
              setFlash($r['ok'] ? 'Added to your events. ' . EVENTS_NOT_REGISTERING . $extra : (string)$r['error'], $r['ok'] ? 'success' : 'error');
              break;
  ```
- In the `renderHomeHtml([...])` call, change `'csrf' => generateCsrfToken(),` to `'csrf' => generateCsrfToken(), 'offerReminders' => remindersShouldOffer(db_find_user_by_id($pdo, $uid)),`.

- [ ] **Step 5: Offer it on the car page**

In `garage-page.php`, in `renderGarageCarHtml()`, replace:

```php
        $out .= '</select><button type="submit" class="hub-btn">I\'m going</button></form><p class="form-hint">' . EVENTS_NOT_REGISTERING . '</p>';
```

with:

```php
        $out .= '</select>' . (!empty($vm['offerReminders']) ? reminderOptInFieldsHtml() : '')
            . '<button type="submit" class="hub-btn">I\'m going</button></form><p class="form-hint">' . EVENTS_NOT_REGISTERING . '</p>';
```

Also add `reminders-lib.php (reminderOptInFieldsHtml())` to that file's header comment list.

In `garage.php`:
- Add `require __DIR__ . '/reminders-lib.php';` after `require __DIR__ . '/events-lib.php';`.
- Replace the `tag` case:
  ```php
          case 'tag':
              $r = eventsTagCar($pdo, $uid, (int)($_POST['event_id'] ?? 0), $carId);
              setFlash($r['ok'] ? 'Added to your events. ' . EVENTS_NOT_REGISTERING : (string)$r['error'], $r['ok'] ? 'success' : 'error');
  ```
  with
  ```php
          case 'tag':
              $r = eventsTagCar($pdo, $uid, (int)($_POST['event_id'] ?? 0), $carId);
              $extra = $r['ok'] ? remindersRecordTagChoice($pdo, $uid, $_POST) : '';
              setFlash($r['ok'] ? 'Added to your events. ' . EVENTS_NOT_REGISTERING . $extra : (string)$r['error'], $r['ok'] ? 'success' : 'error');
  ```
- In `garageShowCar()`, in the `renderGarageCarHtml([...])` call, change `'events' => $events, 'csrf' => generateCsrfToken(), 'detailsForm' => $detailsForm,` to `'events' => $events, 'csrf' => generateCsrfToken(), 'detailsForm' => $detailsForm, 'offerReminders' => remindersShouldOffer(db_find_user_by_id($pdo, $uid)),`.

- [ ] **Step 6: Profile**

In `profile.php`:
- Add `require __DIR__ . '/email-copy.php';` after `require __DIR__ . '/view_helpers.php';`.
- Directly before the `// Unknown action:` comment, add:
  ```php
      if ($action === 'reminders') {
          $on = !empty($_POST['reminder_emails']);
          db_set_user_reminders($pdo, (int)$user['id'], $on);
          setFlash($on ? 'Reminder emails are on.' : 'Reminder emails are off.', 'success');
          header('Location: profile.php');
          exit;
      }
  ```
- After the "Email" card (the `<div class="hub-card">` holding `<h2>Email</h2>`), add:
  ```php
  <div class="hub-card" id="reminders">
      <h2>Reminder emails</h2>
      <form method="post" action="profile.php">
          <input type="hidden" name="action" value="reminders">
          <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
          <label class="hub-reminder-opt"><input type="checkbox" name="reminder_emails" value="1"<?= (int)$userRow['reminder_emails'] === 1 ? ' checked' : '' ?>> <?= h(COPY_REMINDER_OPT_IN) ?></label>
          <p class="form-hint">We email you about 2 weeks, 1 week and 2 days before each event you&#039;re going to, listing anything still to do. No email when you&#039;re all set.</p>
          <button type="submit" class="hub-btn">Save</button>
      </form>
  </div>
  ```

In `css/hub.css`, after the `/* Drivers */` rules:

```css
/* Reminder opt-in */
.hub-reminder-opt { display: inline-flex; gap: 10px; align-items: center; min-height: var(--hub-tap); font-size: 16px; }
.hub-reminder-opt input[type="checkbox"] { width: 22px; height: 22px; }
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php phpunit.phar`, then `php -l index.php && php -l garage.php && php -l profile.php && php -l home-page.php && php -l garage-page.php`.
Expected: OK, with no syntax errors.

- [ ] **Step 8: Commit**

```bash
git add reminders-lib.php home-page.php index.php garage-page.php garage.php profile.php css/hub.css tests/RemindersChoiceTest.php tests/RemindersUiSourceTest.php tests/HomePageTest.php tests/GaragePageTest.php
git commit -m "feat(hub): opt in to reminder emails from Profile or the first event you tag"
```

---

### Task 7: Phase 4 leftovers

**Files:**
- Modify: `inspect-page.php` (the Classing table and declaration history stack on phones), `css/hub.css` (the stacked-table rules; the tab rule shares the nav rule), `email-copy.php` (constant order) and `inspect-lib.php` (a comment)
- Test: `tests/InspectPageTest.php` (modify)

**Interfaces:**
- Produces: the tables `#classing-table` and the declaration history table get the class `inspect-stack`, and each data cell gets a `data-label`. At 700px and below they render as stacked rows instead of scrolling sideways.

- [ ] **Step 1: Write the failing test**

In `tests/InspectPageTest.php`, add:

```php
    public function testClassingAndHistoryTablesStackOnPhones(): void
    {
        $list = renderInspectClassingHtml($this->classingVm([$this->decl()], [], ['isAdmin' => true]));
        $this->assertStringContainsString('<table class="data-table inspect-stack" id="classing-table">', $list);
        foreach (['<td data-label="Select">', '<td data-label="Submitted">', '<td data-label="Car">#42', '<td data-label="Entrant">', '<td data-label="Class">', '<td data-label="Review">'] as $needle) {
            $this->assertStringContainsString($needle, $list);
        }
        $page = renderInspectDeclarationHtml($this->declVm());
        $this->assertStringContainsString('<table class="data-table inspect-stack">', $page);
        $this->assertStringContainsString('<td data-label="Class">GT3</td>', $page);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php phpunit.phar --filter testClassingAndHistoryTablesStackOnPhones`
Expected: FAIL, because the tables have no `inspect-stack` class.

- [ ] **Step 3: Label the cells**

In `inspect-page.php`, `renderInspectClassingHtml()`:
- Change `'<table class="data-table" id="classing-table"><thead><tr>'` to `'<table class="data-table inspect-stack" id="classing-table"><thead><tr>'`.
- In the row loop, change the cells:
  - `'<td><input type="checkbox" class="submission-select"` becomes `'<td data-label="Select"><input type="checkbox" class="submission-select"`
  - `'<td>' . h(date('M j, Y', strtotime((string)$s['submitted_at']))) . '</td>'` becomes `'<td data-label="Submitted">' . h(date('M j, Y', strtotime((string)$s['submitted_at']))) . '</td>'`
  - `'<td>#' . h((string)($s['car_number'] ?? '?'))` becomes `'<td data-label="Car">#' . h((string)($s['car_number'] ?? '?'))`
  - `'<td>' . h((string)$s['name']) . '</td>'` becomes `'<td data-label="Entrant">' . h((string)$s['name']) . '</td>'`
  - `'<td><strong>' . h((string)($s['calculated_class'] ?? '—')) . '</strong></td>'` becomes `'<td data-label="Class"><strong>' . h((string)($s['calculated_class'] ?? '—')) . '</strong></td>'`
  - `'<td><span class="hub-status '` becomes `'<td data-label="Review"><span class="hub-status '`
  - The Actions cell (`'<td><a href="inspect.php?action=declaration&amp;id='`) keeps no label.

In `inspectDeclarationHistoryHtml()`:
- Change `'<table class="data-table">'` to `'<table class="data-table inspect-stack">'`.
- Change the row cells:
  - `'<tr><td>' . h(date('M j, Y', …` becomes `'<tr><td data-label="Submitted">' . h(date('M j, Y', …`
  - `'<td>' . h((string)($d['calculated_class'] ?? '—')) . '</td>'` becomes `'<td data-label="Class">' . h((string)($d['calculated_class'] ?? '—')) . '</td>'`
  - `'<td>' . h(declarationReviewLabel((string)$d['review_status'])) . '</td>'` becomes `'<td data-label="Review">' . h(declarationReviewLabel((string)$d['review_status'])) . '</td>'`
  - The last cell keeps no label.

- [ ] **Step 4: Stack them on phones, and share the tab rule**

In `css/hub.css`, inside the `@media screen and (max-width: 700px)` block, after the `body.hub table { … }` rule, add:

```css
  /* Classing and declaration history: one stacked block per row instead of a sideways-scrolling table. */
  body.hub table.inspect-stack { display: block; overflow: visible; contain: none; }
  .inspect-stack thead { display: none; }
  .inspect-stack tbody, .inspect-stack tr, .inspect-stack td { display: block; }
  .inspect-stack tr { border-top: 1px solid var(--hub-line); padding: 10px 0; }
  .inspect-stack td { border: 0; padding: 2px 0; }
  .inspect-stack td[data-label]::before { content: attr(data-label) ": "; font-weight: 700; color: var(--hub-ink-2); }
```

Replace the shared-looking nav and tab rules:

```css
.hub-nav a, .hub-nav-current {
```

becomes

```css
.hub-nav a, .hub-nav-current, .hub-tabs a, .hub-tab-current {
```

and the block

```css
.hub-tabs a, .hub-tab-current {
  min-height: var(--hub-tap);
  display: inline-flex;
  align-items: center;
  padding: 0 12px;
  font-weight: 600;
  text-decoration: none;
  border-bottom: 3px solid transparent;
}
```

becomes

```css
.hub-tabs a, .hub-tab-current { padding: 0 12px; }
```

(The `.hub-tabs a { color: … }` and `.hub-tab-current { … }` lines after it stay. They come later, so they still win.)

- [ ] **Step 5: The two cosmetic tidy-ups**

- In `email-copy.php`, move the line `const COPY_DECLARATION_ACCEPTED = 'The scrutineer has reviewed & accepted your class declaration.';` up so it directly follows `COPY_TECH_SHEET_RECEIVED`, next to the other declaration and tech sheet lines.
- In `inspect-lib.php`, `inspectClassingQuery()`, add this comment directly above the `foreach`: `// 0 and '' mean "not set" for every Classing filter (season, car, class, status, q); page 1 is the default.`

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php phpunit.phar`
Expected: OK.

- [ ] **Step 7: Commit**

```bash
git add inspect-page.php inspect-lib.php email-copy.php css/hub.css tests/InspectPageTest.php
git commit -m "fix(hub): Classing and declaration history stack on phones; phase 4 tidy-ups"
```

---

### Task 8: End-to-end verification

**Files:** nothing changes unless a defect is found. A defect gets a fix commit with a failing test first.
- Create (untracked): `scratch/hub5-e2e.js`, `scratch/hub5-prepend.php` and the run logs.

- [ ] **Step 1: Run the automated suites**

- `php phpunit.phar` → OK
- `node --test tests/js/*.test.js` → all pass
- `for f in *.php; do php -l "$f" >/dev/null || echo "LINT FAIL $f"; done` → no output

- [ ] **Step 2: Reset, seed and serve**

Run these in order:
1. `php reset-hub-db.php --confirm`
2. `php seed-hub-db.php`
3. `php -d auto_prepend_file=../scratch/hub5-prepend.php -S localhost:8150 > ../scratch/hub5-server.log 2>&1`

`scratch/hub5-prepend.php` defines `WCMA_MAIL_LOG` as `C:/dev/wcmaclasscalc/scratch/hub5-mail.log`, following `scratch/hub4-prepend.php`.

The reminder runs use the CLI against the same database:
`php reminders.php --today=YYYY-MM-DD --mail-log=../scratch/hub5-reminders.log --base-url=http://localhost:8150`

Seed events are Fall Sprint at +17 days and Season Finale at +31 days. So `--today` = Fall Sprint minus 7 days is inside the 7-day window.

- [ ] **Step 3: Browser and CLI checks**

Use Playwright, following `scratch/hub4-e2e.js` (same `require` path, `signIn()`). Check at desktop 1280px, plus phone 390px where noted. Accounts are `jordan@example.com` and the other seed accounts, password `password123`.

1. **First-tag offer (Home):**
   - Jordan's Home shows the "Email me reminders for events I'm going to." checkbox, unticked, on the Season Finale tag form.
   - Tag Season Finale with it ticked. The flash says "…We'll email you reminders before your events."
   - Home no longer shows the checkbox.
   - Profile shows Reminder emails ticked.
2. **Profile toggle:** untick and Save. The flash says "Reminder emails are off." Tick and Save. The flash says "Reminder emails are on." Tag forms still don't offer the checkbox, because the choice was made.
3. **First-tag offer (Garage), unticked:**
   - Register a new account (the `auth.php` register flow, as `scratch/hub3-e2e.js` item 11 does) and add a car.
   - Its car page's "Bring this car to another event" form shows the checkbox. Tag an event with it unticked.
   - The checkbox is gone afterwards, and Profile shows reminders off.
4. **Reminder run:**
   - With `--today` = Fall Sprint minus 7 days, the CLI prints "1 sent" (Jordan). The mail log has one message to jordan@example.com with:
     - subject "WCMA reminder: N things to do before Fall Sprint (…)"
     - Jordan's todo items, with `http://localhost:8150/…` links
     - `EVENTS_NOT_REGISTERING`
     - an `Unsubscribe:` line
     - `headers` with `List-Unsubscribe` and `List-Unsubscribe-Post`
   - The new account from item 3 (reminders off) gets nothing.
5. **Once per window:** the same `--today` again prints "0 sent, 1 already sent". With `--today` = Fall Sprint minus 2 days, it prints "1 sent" again.
6. **Nothing to do, nothing sent:** make Season Finale "all set" for a test account, or leave it untagged. With `--today` = Season Finale minus 7 days, no email goes out for an event with no todo items. Confirm from the mail log, and write down how the scenario was built.
7. **Unsubscribe link:**
   - Open the `Unsubscribe:` URL from item 4 in a fresh, signed-out browser context. It shows "Stop reminder emails?" with a button, and reminders are still on.
   - The same URL with the last character of `t` changed, and the URL with `u` set to another account's id, each show "This link isn't valid" with status 400, and change nothing.
   - Click **Unsubscribe**. "You're unsubscribed" shows, and Jordan's Profile shows reminders off.
   - A later `--today` run in a new window sends Jordan nothing.
8. **One-click unsubscribe:**
   - Turn Jordan's reminders back on in Profile.
   - With no cookies, `POST` the unsubscribe URL with body `List-Unsubscribe=One-Click` (content type `application/x-www-form-urlencoded`). It responds 200 and Jordan's reminders are off.
9. **Not on the web:** `GET /reminders.php` and `GET /reminders-cron.sh` both return 403. In the plain PHP dev server, which ignores `.htaccess`, `reminders.php` still returns 403 from its CLI guard. Record the `.sh` result, and note that Apache's `.htaccess` covers it in production.
10. **CLI safety:**
    - `php reminders.php --bogus` exits 2.
    - `php reminders.php --today=2026-13-45x` exits 2.
    - With no `--base-url` and an empty `SITE_BASE_URL`, it exits 1 with the "Set SITE_BASE_URL" message.
11. **Cron wrapper:** `PHP_BIN=php sh reminders-cron.sh` runs from any working directory and appends a summary line to `data/reminders.log`. Remove that log afterwards.
12. **Phone layout (390px), as an inspector:**
    - On `inspect.php?action=classing` and a declaration page, `document.documentElement.scrollWidth <= 390`.
    - Each Classing row's **Review** or **View** link is visible without scrolling sideways, and the stacked rows show their labels (Car, Class, Review…).
13. **The server log** has no PHP warnings, notices or fatal errors: `grep -iE "warning|notice|fatal|deprecated" ../scratch/hub5-server.log` prints nothing.

- [ ] **Step 4: Report**

Record pass or fail for each item, with any error text. Phase 5 is not complete while any item fails.

- [ ] **Step 5: Merge locally (do not push)**

Only once every item passes: `git checkout main && git merge --no-ff hub-phase5`. **Do not push.** All five phases are then on local `main`, ready for the user to test the whole hub together.

Then update the hub-rollout memory to say all five phases are merged locally. The deploy checklist is:
- run `php reset-hub-db.php --confirm` on the server
- set `SITE_BASE_URL` in the server's `config.php`
- create the IONOS UnixCron job for `reminders-cron.sh`, as described in `README.md`.

---

## Self-review notes

- **Spec §7 coverage:**
  - `users.reminder_emails` defaults to off: already in the schema, and Task 1 tests it.
  - The first-time tag checkbox, with the binding copy: Tasks 2 and 6.
  - The same toggle in Profile: Task 6.
  - The daily CLI-only `reminders.php`: Task 4.
  - Opted-in users with events 14, 7 or 2 days out and at least one `todo` get one digest per event: Tasks 2 and 4. The windows are a deliberate reading of "14, 7 or 2 days out" that survives a missed cron day; see Architecture.
  - No todos, no email: Task 2.
  - `reminder_log` with `UNIQUE(user_id, event_id, days_out)`: Task 1.
  - A one-click unsubscribe link with a signed token that clears `reminder_emails`: Tasks 2, 3 and 5. A browser GET shows a confirm button (a deliberate guard against link scanners), and mail apps get true one-click through `List-Unsubscribe-Post`.
- **Spec §9:** `reminderDue` has unit tests (Task 2), and a failed email never blocks (Task 4).
- **Beyond the spec, and why:**
  - `users.reminder_prompted_at` makes "the first time" durable.
  - `--today`, `--mail-log` and `--base-url` make the CLI testable.
  - `reminders-cron.sh` exists because of the IONOS cron constraint.
- **Phase 4 leftovers:**
  - The sideways-scrolling Classing and history tables: Task 7.
  - Cosmetic minors: the copy constant order and the `inspectClassingQuery` comment (Task 7), and the `.hub-tabs` CSS duplication (Task 7).
  - The `db_accept_declaration` doc-comment wording was checked in the current file and needs no change.
