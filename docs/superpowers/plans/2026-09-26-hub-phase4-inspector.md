# WCMA Hub Phase 4: Inspector Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give inspectors their own section, `inspect.php`, and trim `admin.php` to the back office.
- `inspect.php` has four tabs: **Event roster** (the default), **Review queue**, **Classing** and **Gear**.
- Class declarations get a review workflow. An inspector can **Accept** a declaration, or **Send it back** with a required note. Either action emails the competitor, and the email names the reviewer.
- `admin.php` keeps only the back office: **Users & roles**, **Events** (now with tagged-car counts), **Season links**, **Settings** and **Feedback**.
- Old `admin.php` links (bookmarks, and emails already sent) redirect to the same record in `inspect.php`.

**Architecture:**
- Plain PHP + SQLite, as in Phases 1–3. New pages render inside `layout.php` (`renderPageStart()` / `renderPageEnd()`).
- Code is split the same way as the Garage:
  - pure view models go in `inspect-lib.php`
  - pure HTML renderers that return strings go in `inspect-page.php`
  - a thin controller, `inspect.php`, does the rest.
- The review logic and its emails follow the existing `tech-review-lib.php` / `gear-email.php` pattern. `declaration-review-lib.php` is session-free and DB-backed, with the reviewer id passed in. `declaration-email.php` holds pure renderers plus a notifier that takes an injectable send function.
- The tech sheet and gear review pages **keep their files and handler names** (`admin-tech-sheets.php`, `admin-gear.php`). Only their URLs move from `admin.php` to `inspect.php`, and their page shells move to `renderPageStart()`. Renaming the files would also mean rewriting six source-level test files. The files' header comments say they belong to the Inspector section.
- Routes move one area per task: tech sheets (Task 5), gear (Task 6), classing (Task 7).
  - Each moved GET action gets an `adminMovedActionUrl()` entry, so `admin.php?action=tech-sheet&id=12` redirects to `inspect.php?action=tech-sheet&id=12`.
  - Until Task 9, `admin.php` keeps serving the areas that haven't moved yet.
- Authorisation in `inspect.php` is in one place, before the router:
  - `require_role(inspectActionMinRole($action))`, then
  - every action in `INSPECT_POST_ACTIONS` is refused unless it is a POST with a valid CSRF token.

**Tech Stack:** PHP 8.3, SQLite (PDO), PHPUnit 10 (`php phpunit.phar`), vanilla JS, Playwright (the scratch e2e harness pattern).

**Spec:** `docs/superpowers/specs/2026-09-24-wcma-hub-design.md`. Read:
- §1 (URL map: Inspector → `inspect.php`, Admin → `admin.php`)
- §2 (a car's class; `accepted_at`)
- §5 in full (roles, the declaration review workflow, the binding competitor email copy, the Inspector tabs, the Admin section)
- §6 (page layer)
- §9 (errors: a failed email never blocks the action; a signed-in authorisation failure shows a 403 page inside the layout).

## Global Constraints

- All paths are relative to `wcma-calculator/` unless they start with `docs/`. Run PHPUnit from `wcma-calculator/`: `php phpunit.phar`. Run the JS tests with `node --test tests/js/*.test.js`.
- The checkout uses CRLF line endings (`core.autocrlf=true`). Any test that slices PHP source by searching for `"\n}\n"` or `"\nfunction "` must first normalise: `str_replace("\r\n", "\n", ...)`.
- No new dependencies and no build step.
- **Terminology:** use *reviewed*, *accepted* and *pre-teched*. Never use *approved*, *approval*, *passed* or *safe* in UI or email copy.
- **Binding email copy** (spec §5) lives only in `email-copy.php`. This phase adds `COPY_DECLARATION_ACCEPTED = 'The scrutineer has reviewed & accepted your class declaration.'`.
  - Every review email, accepted or sent back, carries `reviewedByLine($reviewer)` ("Reviewed by: First Last").
  - A sent-back email carries the inspector's note.
- **Not blocking:** a declaration's review status never stops a tech sheet being submitted. The roster only *shows* the status.
- **A failed email never blocks the action it follows.** The review is saved first, then the email is sent. The flash message says whether the email could not be sent.
- **Admin-only inside the Inspector section:** deleting a declaration, bulk delete, and editing a declaration's contact details (`INSPECT_ADMIN_ONLY_ACTIONS`). Everything else in `inspect.php` is open to inspectors and admins.
- **Escape everything** that comes from the DB or the request with `h()` (`view_helpers.php`), including URLs in `href`.
- POST handlers always redirect (PRG). A bad CSRF token gets `http_response_code(403); die('Invalid CSRF token');`.
- **Accessibility floor:** 18px base text, 44px tap targets (`.hub-btn`), visible focus rings, and status always shown as a word (`.hub-status` + text). The Inspector pages must work at 390px wide with no sideways scroll, because inspectors use phones at the track.
- The app isn't live. No schema change is needed this phase. After Task 10's seed change, reset your local DB: `php reset-hub-db.php --confirm && php seed-hub-db.php`.
- **Nothing is pushed to GitHub.** Work on branch `hub-phase4` (`git checkout -b hub-phase4` from `main`), commit at the end of every task, and merge into local `main` only after Task 10 passes. Every commit message ends with:
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5
  ```

## Review Focus

1. **A declaration superseded while an inspector has it open.** The competitor re-declares, and a moment later the inspector clicks Accept or Send back on the old one. The action must be refused with "A newer declaration has replaced this one. Review the newer one instead.", and nothing is emailed. Pinned in Task 1 (`DeclarationReviewTest::testSupersededDeclarationCannotBeReviewed`, `testDbUpdatesAreAtomic`) and Task 7 (`InspectSourceTest::testReviewEmailsGoOutOnlyAfterTheReviewSucceeded`).
2. **Send back with a blank or whitespace-only note** must be refused, leave the status unchanged, and send no email. The `required` attribute on the textarea is not enough, because the server check is the rule. Pinned in Task 1 (`DeclarationReviewTest::testSendBackNeedsANote`) and Task 10 (e2e item 5, which removes the `required` attribute before submitting).
3. **Deleting a car's current declaration from Classing** must leave the car with its previous declaration as current, as the Garage already does (`db_restore_current_declaration()`). The old `admin.php` delete didn't do this. Pinned in Task 7 (`DeclarationDeleteGuardTest::testDeletingRestoresThePreviousDeclaration`, `testBulkDeleteRestoresEachCarsPreviousDeclaration`).
4. **Old admin links and the wrong role.**
   - Emails already sent link to `admin.php?action=tech-sheet&id=…` and `admin.php?action=gear-record&id=…`. Those must land on the same record in `inspect.php`.
   - An inspector (not an admin) who opens `admin.php` gets the in-layout 403 page, not a bare text line.
   - A competitor who opens `inspect.php` gets the same 403 page.
   - Pinned in Task 4 (`LayoutTest::testForbiddenPageRendersInsideTheLayout`), Tasks 5–7 (`RolesTest::testMovedAdminActionsRedirectToTheInspectorSection`) and Task 10 (e2e items 1 and 3).
5. **A roster car that is tagged but has no tech sheet yet.** Its row must still show the owner's gear, from the owner's self driver profile. It must never offer the one-tap "Create and accept gear in person", because that needs a sheet. Pinned in Task 3 (`InspectLibTest::testTaggedCarWithoutASheetShowsTheOwnersGear`) and Task 5 (`InspectPageTest::testACarWithoutASheetSaysSoAndHasNoOneTapButton`).

---

## File map

| File | Status | Responsibility |
|---|---|---|
| `db.php` | modify | `db_accept_declaration()`, `db_send_back_declaration()`; Inspector queries (roster cars, declarations by car, self drivers, review-queue lists, declaration search, event tag counts) |
| `declaration-review-lib.php` | create | `declarationReviewAllowed()`, `declarationReviewAccept()`, `declarationReviewSendBack()` |
| `declaration-email.php` | create | `declarationEmailAccepted()`, `declarationEmailSentBack()`, `declarationNotify()` |
| `email-copy.php` | modify | `COPY_DECLARATION_ACCEPTED` |
| `inspect-lib.php` | create | Pure: `inspectRosterRows()`, `inspectRosterFilter()`, `inspectRosterCounts()`, `inspectReviewQueue()`, `inspectClassingFilters()`, `inspectClassingQuery()` |
| `inspect-page.php` | create | Pure HTML: roster, review queue, Classing list, declaration page |
| `inspect.php` | create | Inspector controller: gate, router, roster/queue/classing/declaration handlers |
| `admin-tech-sheets.php` | modify | Tech sheet review page on `inspect.php` URLs and `renderPageStart()`; the old roster list is removed |
| `admin-gear.php` | modify | Gear list and review pages on `inspect.php` URLs and `renderPageStart()` |
| `gear-chips.php`, `pretech-email.php`, `gear-email.php` | modify | Links and the one-tap form point at `inspect.php` |
| `admin.php` | modify | Back office only: moved-action redirects, `require_role('admin')`, Events "Going" counts, admin tabs |
| `admin-feedback.php`, `admin-season-links.php` | modify | Admin tabs instead of `renderAdminNav()` |
| `roles.php` | modify | `INSPECT_ADMIN_ONLY_ACTIONS`, `INSPECT_POST_ACTIONS`, `inspectActionMinRole()`, `ADMIN_MOVED_ACTIONS`, `adminMovedActionUrl()`; `ADMIN_INSPECTOR_ACTIONS`/`adminActionMinRole()` removed |
| `layout.php` | modify | Inspector and Admin nav items; `hubSubnavHtml()`, `inspectSubnavHtml()`, `adminSubnavHtml()`; `hubRenderForbidden()` |
| `session_bootstrap.php` | modify | `require_role()` renders the 403 page inside the layout |
| `view_helpers.php` | modify | `renderAdminNav()` and `navItem()` removed (Task 9) |
| `css/hub.css` | modify | Staff nav divider, section tabs, roster rows, filters, queue |
| `hub-db-tools.php` | modify | Seed tags #42 for Fall Sprint so the roster shows a car without a sheet |
| `js/admin-tech-review.js` | modify | Header comment only |

---

### Task 1: Declaration review actions and emails

**Files:**
- Modify: `db.php`. Add two functions directly after `db_restore_current_declaration()`.
- Create: `declaration-review-lib.php`
- Modify: `email-copy.php`
- Create: `declaration-email.php`
- Test: `tests/DeclarationReviewTest.php` (create), `tests/DeclarationEmailTest.php` (create)

**Interfaces:**
- Consumes:
  - `db_get_submission()`, `db_get_car()`, `db_find_user_by_id()` (db.php)
  - `reviewedByLine(?array $reviewer): string` (email-copy.php)
  - `pretechEmailWrap()`, `pretechEmailPara()`, `pretechEmailLink()` (pretech-email.php)
  - `h()`
- Produces:
  - `db_accept_declaration(PDO $pdo, int $id, int $reviewerUserId): bool`. Atomic, and only from `submitted` or `needs_changes`. Sets `accepted_at`, `reviewed_by_user_id` and `reviewed_at`, and clears `reviewer_note`.
  - `db_send_back_declaration(PDO $pdo, int $id, int $reviewerUserId, string $note): bool`. Atomic, and only from `submitted` or `accepted`. Sets `reviewer_note` and the reviewer fields, and clears `accepted_at`.
  - `const DECLARATION_NOTE_MAX = 1000`
  - `declarationReviewAllowed(string $status, string $action): bool`, where `$action` is `'accept'` or `'send_back'`
  - `declarationReviewAccept(PDO $pdo, int $id, int $reviewerUserId): array{ok: bool, error: ?string}`
  - `declarationReviewSendBack(PDO $pdo, int $id, int $reviewerUserId, string $note): array{ok: bool, error: ?string}`
  - `const COPY_DECLARATION_ACCEPTED`
  - `declarationEmailAccepted(array $sub, array $car, string $garageUrl, ?array $reviewer): array{subject, html, text}`
  - `declarationEmailSentBack(array $sub, array $car, string $note, string $redeclareUrl, ?array $reviewer): array{subject, html, text}`
  - `declarationNotify(PDO $pdo, string $kind, array $sub, string $baseUrl, callable $sendFn): bool`, where `$kind` is `'accepted'` or `'sent_back'`. The message goes to the declaration's account holder, and `$sendFn` has the same signature as `emailSmtpSend(array $to, array $message): bool`.

**Why the transitions are allowed:**
- An inspector may Accept a declaration they sent back, for example after the competitor explained it at the track.
- An inspector may Send back one they accepted by mistake. That withdraws the acceptance, so `accepted_at` is cleared. `accepted_at` is what the Garage uses for "Accepted: GT2" (spec §2).
- A `superseded` declaration can never be reviewed.

- [ ] **Step 1: Write the failing tests**

`tests/DeclarationReviewTest.php`:

```php
<?php
// wcma-calculator/tests/DeclarationReviewTest.php
require_once __DIR__ . '/../declaration-review-lib.php';

use PHPUnit\Framework\TestCase;

final class DeclarationReviewTest extends TestCase
{
    private PDO $pdo;
    private int $uid;
    private int $inspector;

    protected function setUp(): void
    {
        $this->pdo = make_temp_pdo();
        $this->uid = db_create_user($this->pdo, ['email' => 'jordan@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $this->inspector = db_create_user($this->pdo, ['email' => 'ivy@example.com', 'name' => 'Ivy Inspector', 'password_hash' => 'x', 'google_id' => null]);
    }

    private function declare(string $number = '42'): int {
        return db_insert_submission($this->pdo, test_declaration_data($this->pdo, $this->uid, $number));
    }

    private function status(int $id): string {
        return (string)db_get_submission($this->pdo, $id)['review_status'];
    }

    public function testWhichActionsEachStatusAllows(): void
    {
        $this->assertTrue(declarationReviewAllowed('submitted', 'accept'));
        $this->assertTrue(declarationReviewAllowed('needs_changes', 'accept'));
        $this->assertFalse(declarationReviewAllowed('accepted', 'accept'));
        $this->assertTrue(declarationReviewAllowed('submitted', 'send_back'));
        $this->assertTrue(declarationReviewAllowed('accepted', 'send_back'));
        $this->assertFalse(declarationReviewAllowed('needs_changes', 'send_back'));
        $this->assertFalse(declarationReviewAllowed('superseded', 'accept'));
        $this->assertFalse(declarationReviewAllowed('superseded', 'send_back'));
        $this->assertFalse(declarationReviewAllowed('submitted', 'delete'));
    }

    public function testAcceptRecordsTheReviewerAndWhen(): void
    {
        $id = $this->declare();
        $this->assertSame(['ok' => true, 'error' => null], declarationReviewAccept($this->pdo, $id, $this->inspector));
        $s = db_get_submission($this->pdo, $id);
        $this->assertSame('accepted', $s['review_status']);
        $this->assertSame($this->inspector, (int)$s['reviewed_by_user_id']);
        $this->assertNotNull($s['reviewed_at']);
        $this->assertNotNull($s['accepted_at']);
        $this->assertNull($s['reviewer_note']);
    }

    public function testAcceptingTwiceIsRefused(): void
    {
        $id = $this->declare();
        declarationReviewAccept($this->pdo, $id, $this->inspector);
        $this->assertSame(['ok' => false, 'error' => 'This declaration is already accepted.'], declarationReviewAccept($this->pdo, $id, $this->inspector));
    }

    public function testSendBackNeedsANote(): void
    {
        $id = $this->declare();
        $r = declarationReviewSendBack($this->pdo, $id, $this->inspector, "  \r\n  ");
        $this->assertFalse($r['ok']);
        $this->assertSame('Write a note saying what needs to change. The competitor sees it in the email and in their Garage.', $r['error']);
        $this->assertSame('submitted', $this->status($id));
    }

    public function testSendBackStoresTheTrimmedNoteAndReviewer(): void
    {
        $id = $this->declare();
        $this->assertTrue(declarationReviewSendBack($this->pdo, $id, $this->inspector, "  Dyno sheet missing.\r\nPlease attach it. ")['ok']);
        $s = db_get_submission($this->pdo, $id);
        $this->assertSame('needs_changes', $s['review_status']);
        $this->assertSame("Dyno sheet missing.\nPlease attach it.", $s['reviewer_note']);
        $this->assertSame($this->inspector, (int)$s['reviewed_by_user_id']);
        $this->assertSame(['ok' => false, 'error' => 'This declaration has already been sent back.'], declarationReviewSendBack($this->pdo, $id, $this->inspector, 'Again'));
    }

    public function testNotesOverTheLimitAreRefused(): void
    {
        $id = $this->declare();
        $r = declarationReviewSendBack($this->pdo, $id, $this->inspector, str_repeat('é', DECLARATION_NOTE_MAX + 1));
        $this->assertSame('Keep the note to 1,000 characters or fewer.', $r['error']);
        $this->assertTrue(declarationReviewSendBack($this->pdo, $id, $this->inspector, str_repeat('é', DECLARATION_NOTE_MAX))['ok']);
    }

    public function testSendingBackAnAcceptedDeclarationWithdrawsTheAcceptance(): void
    {
        $id = $this->declare();
        declarationReviewAccept($this->pdo, $id, $this->inspector);
        $this->assertTrue(declarationReviewSendBack($this->pdo, $id, $this->inspector, 'Wrong car, sorry.')['ok']);
        $s = db_get_submission($this->pdo, $id);
        $this->assertSame('needs_changes', $s['review_status']);
        $this->assertNull($s['accepted_at']);
    }

    public function testAcceptingASentBackDeclarationClearsTheNote(): void
    {
        $id = $this->declare();
        declarationReviewSendBack($this->pdo, $id, $this->inspector, 'Bring the dyno sheet.');
        $this->assertTrue(declarationReviewAccept($this->pdo, $id, $this->inspector)['ok']);
        $s = db_get_submission($this->pdo, $id);
        $this->assertSame('accepted', $s['review_status']);
        $this->assertNull($s['reviewer_note']);
        $this->assertNotNull($s['accepted_at']);
    }

    public function testSupersededDeclarationCannotBeReviewed(): void
    {
        $old = $this->declare();
        $this->declare();   // the competitor re-declares the same car, superseding $old
        foreach ([declarationReviewAccept($this->pdo, $old, $this->inspector), declarationReviewSendBack($this->pdo, $old, $this->inspector, 'Fix it')] as $r) {
            $this->assertFalse($r['ok']);
            $this->assertSame('A newer declaration has replaced this one. Review the newer one instead.', $r['error']);
        }
        $this->assertSame('superseded', $this->status($old));
    }

    public function testUnknownDeclaration(): void
    {
        $this->assertSame(['ok' => false, 'error' => 'Class declaration not found.'], declarationReviewAccept($this->pdo, 999, $this->inspector));
        $this->assertSame(['ok' => false, 'error' => 'Class declaration not found.'], declarationReviewSendBack($this->pdo, 999, $this->inspector, 'x'));
    }

    public function testDbUpdatesAreAtomic(): void
    {
        $id = $this->declare();
        $this->pdo->exec("UPDATE submissions SET review_status = 'superseded' WHERE id = $id");
        $this->assertFalse(db_accept_declaration($this->pdo, $id, $this->inspector));
        $this->assertFalse(db_send_back_declaration($this->pdo, $id, $this->inspector, 'x'));
        $this->assertSame('superseded', $this->status($id));
    }
}
```

`tests/DeclarationEmailTest.php`:

```php
<?php
// wcma-calculator/tests/DeclarationEmailTest.php
require_once __DIR__ . '/../declaration-email.php';

use PHPUnit\Framework\TestCase;

final class DeclarationEmailTest extends TestCase
{
    private function sub(array $o = []): array {
        return array_merge([
            'id' => 7, 'car_id' => 3, 'user_id' => 1, 'name' => 'Jordan Lee', 'email' => 'jordan@example.com',
            'year' => '2004', 'make' => 'Honda', 'model' => 'S2000', 'calculated_class' => 'GT3',
            'submitted_at' => '2026-03-03 10:00:00', 'reviewer_note' => null, 'reviewed_by_user_id' => null,
        ], $o);
    }

    private function car(): array {
        return ['id' => 3, 'car_number' => '42'];
    }

    public function testAcceptedLeadsWithTheBindingHeadlineAndNamesTheReviewer(): void
    {
        $this->assertSame('The scrutineer has reviewed & accepted your class declaration.', COPY_DECLARATION_ACCEPTED);
        $m = declarationEmailAccepted($this->sub(), $this->car(), 'https://x.test/garage.php?car=3', ['name' => 'Ivy  Inspector']);
        $this->assertSame('WCMA Class Declaration Accepted — Car #42 — GT3', $m['subject']);
        $this->assertStringStartsWith(COPY_DECLARATION_ACCEPTED, $m['text']);
        $this->assertStringContainsString('Reviewed by: Ivy Inspector', $m['text']);
        $this->assertStringContainsString('Reviewed by: Ivy Inspector', $m['html']);
        $this->assertStringContainsString('Car #42 — 2004 Honda S2000 (GT3), submitted March 3, 2026, is accepted.', $m['text']);
        $this->assertStringContainsString('https://x.test/garage.php?car=3', $m['text']);
        $this->assertStringContainsString('cid:wcma-logo', $m['html']);
    }

    public function testSentBackCarriesTheEscapedNoteAndTheRedeclareLink(): void
    {
        $m = declarationEmailSentBack($this->sub(), $this->car(), "Dyno sheet <missing>.\nAttach it.", 'https://x.test/calculator.php?car=3', ['name' => 'Ivy Inspector']);
        $this->assertSame('WCMA Class Declaration — changes needed — Car #42', $m['subject']);
        $this->assertStringContainsString("Inspector's note: Dyno sheet <missing>.\nAttach it.", $m['text']);
        $this->assertStringContainsString('Dyno sheet &lt;missing&gt;.<br />', $m['html']);
        $this->assertStringContainsString('Reviewed by: Ivy Inspector', $m['text']);
        $this->assertStringContainsString('https://x.test/calculator.php?car=3', $m['text']);
        $this->assertStringContainsString('href="https://x.test/calculator.php?car=3"', $m['html']);
    }

    public function testWithoutAKnownReviewerThereIsNoByLine(): void
    {
        $m = declarationEmailAccepted($this->sub(), $this->car(), 'https://x.test/garage.php?car=3', null);
        $this->assertStringNotContainsString('Reviewed by', $m['text'] . $m['html']);
    }

    public function testNoBannedWording(): void
    {
        $a = declarationEmailAccepted($this->sub(), $this->car(), 'u', ['name' => 'Ivy Inspector']);
        $b = declarationEmailSentBack($this->sub(), $this->car(), 'n', 'u', ['name' => 'Ivy Inspector']);
        foreach ([$a, $b] as $m) {
            $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', $m['subject'] . $m['html'] . $m['text']);
        }
    }

    public function testNotifySendsToTheAccountHolderWithTheStoredNote(): void
    {
        $pdo = make_temp_pdo();
        $uid = db_create_user($pdo, ['email' => 'jordan@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $insp = db_create_user($pdo, ['email' => 'ivy@example.com', 'name' => 'Ivy Inspector', 'password_hash' => 'x', 'google_id' => null]);
        $id = db_insert_submission($pdo, test_declaration_data($pdo, $uid));
        db_send_back_declaration($pdo, $id, $insp, 'Attach the dyno sheet.');
        $sub = db_get_submission($pdo, $id);

        $sent = [];
        $ok = declarationNotify($pdo, 'sent_back', $sub, 'https://x.test/', function (array $to, array $m) use (&$sent): bool {
            $sent[] = [$to, $m];
            return true;
        });
        $this->assertTrue($ok);
        $this->assertCount(1, $sent);
        $this->assertSame([['jordan@example.com', 'Jordan Lee']], $sent[0][0]);
        $this->assertStringContainsString("Inspector's note: Attach the dyno sheet.", $sent[0][1]['text']);
        $this->assertStringContainsString('Reviewed by: Ivy Inspector', $sent[0][1]['text']);
        $this->assertStringContainsString('https://x.test/calculator.php?car=' . (int)$sub['car_id'], $sent[0][1]['text']);
    }

    public function testNotifyReportsFailureWithoutThrowing(): void
    {
        $pdo = make_temp_pdo();
        $uid = db_create_user($pdo, ['email' => 'jordan@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $id = db_insert_submission($pdo, test_declaration_data($pdo, $uid));
        db_accept_declaration($pdo, $id, $uid);
        $sub = db_get_submission($pdo, $id);

        $this->assertFalse(declarationNotify($pdo, 'accepted', $sub, 'https://x.test', fn(array $to, array $m): bool => throw new RuntimeException('smtp down')));
        $this->assertFalse(declarationNotify($pdo, 'accepted', $sub, 'https://x.test', fn(array $to, array $m): bool => false));
        $this->assertFalse(declarationNotify($pdo, 'bogus', $sub, 'https://x.test', fn(array $to, array $m): bool => true));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter 'DeclarationReviewTest|DeclarationEmailTest'`
Expected: an error, because `declaration-review-lib.php` does not exist yet ("Failed opening required").

- [ ] **Step 3: Add the DB updates**

In `db.php`, directly after `db_restore_current_declaration()`:

```php
/**
 * An inspector accepts a declaration (spec §5). Atomic: only from 'submitted' or 'needs_changes', so
 * a declaration a re-declaration superseded in the meantime is refused. accepted_at is the lasting
 * record of the acceptance: it survives superseding (see db_restore_current_declaration()).
 */
function db_accept_declaration(PDO $pdo, int $id, int $reviewerUserId): bool {
    $stmt = $pdo->prepare("
        UPDATE submissions SET review_status = 'accepted', accepted_at = :now, reviewer_note = NULL,
            reviewed_by_user_id = :reviewer, reviewed_at = :now
        WHERE id = :id AND review_status IN ('submitted', 'needs_changes')
    ");
    $stmt->execute([':now' => date('Y-m-d H:i:s'), ':reviewer' => $reviewerUserId, ':id' => $id]);
    return $stmt->rowCount() === 1;
}

/**
 * An inspector sends a declaration back with a note (-> 'needs_changes'). Atomic: only from 'submitted'
 * or 'accepted'. Sending back an accepted declaration withdraws the acceptance, so accepted_at is cleared.
 */
function db_send_back_declaration(PDO $pdo, int $id, int $reviewerUserId, string $note): bool {
    $stmt = $pdo->prepare("
        UPDATE submissions SET review_status = 'needs_changes', reviewer_note = :note, accepted_at = NULL,
            reviewed_by_user_id = :reviewer, reviewed_at = :now
        WHERE id = :id AND review_status IN ('submitted', 'accepted')
    ");
    $stmt->execute([':note' => $note, ':now' => date('Y-m-d H:i:s'), ':reviewer' => $reviewerUserId, ':id' => $id]);
    return $stmt->rowCount() === 1;
}
```

- [ ] **Step 4: Create `declaration-review-lib.php`**

```php
<?php
// wcma-calculator/declaration-review-lib.php
//
// An inspector's review of a class declaration (spec §5): Accept, or Send back with a required note.
// Session-free (the caller passes the reviewer id), so it is unit-testable. Callers must have loaded
// db.php.

const DECLARATION_NOTE_MAX = 1000;
const DECLARATION_REVIEW_CHANGED = 'This declaration changed while you were reviewing it. Reload the page and try again.';

/** Whether $action ('accept' | 'send_back') may be taken on a declaration whose review_status is $status. */
function declarationReviewAllowed(string $status, string $action): bool {
    $from = [
        'accept' => ['submitted', 'needs_changes'],
        'send_back' => ['submitted', 'accepted'],
    ];
    return in_array($status, $from[$action] ?? [], true);
}

/** Why $action is refused for $status. Only meaningful when declarationReviewAllowed() is false. */
function declarationReviewRefusal(string $status, string $action): string {
    if ($status === 'superseded') return 'A newer declaration has replaced this one. Review the newer one instead.';
    return $action === 'accept' ? 'This declaration is already accepted.' : 'This declaration has already been sent back.';
}

/** The refusal message for $action on declaration $id, or null when the action may go ahead. */
function declarationReviewCheck(PDO $pdo, int $id, string $action): ?string {
    $sub = db_get_submission($pdo, $id);
    if ($sub === null) return 'Class declaration not found.';
    $status = (string)$sub['review_status'];
    return declarationReviewAllowed($status, $action) ? null : declarationReviewRefusal($status, $action);
}

/** @return array{ok: bool, error: ?string} */
function declarationReviewAccept(PDO $pdo, int $id, int $reviewerUserId): array {
    $refused = declarationReviewCheck($pdo, $id, 'accept');
    if ($refused !== null) return ['ok' => false, 'error' => $refused];
    if (!db_accept_declaration($pdo, $id, $reviewerUserId)) return ['ok' => false, 'error' => DECLARATION_REVIEW_CHANGED];
    return ['ok' => true, 'error' => null];
}

/** @return array{ok: bool, error: ?string} */
function declarationReviewSendBack(PDO $pdo, int $id, int $reviewerUserId, string $note): array {
    $refused = declarationReviewCheck($pdo, $id, 'send_back');
    if ($refused !== null) return ['ok' => false, 'error' => $refused];

    $note = trim(str_replace("\r\n", "\n", $note));
    if ($note === '') {
        return ['ok' => false, 'error' => 'Write a note saying what needs to change. The competitor sees it in the email and in their Garage.'];
    }
    if (mb_strlen($note, 'UTF-8') > DECLARATION_NOTE_MAX) {
        return ['ok' => false, 'error' => 'Keep the note to 1,000 characters or fewer.'];
    }
    if (!db_send_back_declaration($pdo, $id, $reviewerUserId, $note)) return ['ok' => false, 'error' => DECLARATION_REVIEW_CHANGED];
    return ['ok' => true, 'error' => null];
}
```

- [ ] **Step 5: Add the headline and create `declaration-email.php`**

In `email-copy.php`, after `COPY_TECH_SHEET_RECEIVED`:

```php
const COPY_DECLARATION_ACCEPTED = 'The scrutineer has reviewed & accepted your class declaration.';
```

`declaration-email.php`:

```php
<?php
// wcma-calculator/declaration-email.php
//
// Emails to the competitor after an inspector reviews a class declaration (spec §5, "Competitor
// emails"): pure renderers, branded like the pre-tech emails (logo via cid:wcma-logo), and a notifier
// with an injectable send function. Callers must have loaded db.php.
require_once __DIR__ . '/pretech-email.php';   // pretechEmailWrap/Para/Link, h()
require_once __DIR__ . '/email-copy.php';

function declarationEmailCarLine(array $sub, array $car): string {
    return 'Car #' . $car['car_number'] . ' — ' . trim($sub['year'] . ' ' . $sub['make'] . ' ' . $sub['model'])
        . ' (' . ($sub['calculated_class'] ?? '—') . ')';
}

/** @return array{subject: string, html: string, text: string} */
function declarationEmailAccepted(array $sub, array $car, string $garageUrl, ?array $reviewer): array {
    $what = 'Your class declaration for ' . declarationEmailCarLine($sub, $car) . ', submitted '
        . date('F j, Y', strtotime((string)$sub['submitted_at'])) . ', is accepted.';
    $byLine = reviewedByLine($reviewer);
    $lines = array_values(array_filter([COPY_DECLARATION_ACCEPTED, $byLine, $what, 'Your car:', $garageUrl]));
    $html = pretechEmailPara(COPY_DECLARATION_ACCEPTED) . pretechEmailPara($what)
        . ($byLine !== '' ? pretechEmailPara($byLine) : '')
        . pretechEmailLink($garageUrl, 'Open your car in the Garage');
    return [
        'subject' => 'WCMA Class Declaration Accepted — Car #' . $car['car_number'] . ' — ' . ($sub['calculated_class'] ?? ''),
        'html' => pretechEmailWrap('CLASS DECLARATION ACCEPTED', $html),
        'text' => implode("\n\n", $lines) . "\n",
    ];
}

/** @return array{subject: string, html: string, text: string} */
function declarationEmailSentBack(array $sub, array $car, string $note, string $redeclareUrl, ?array $reviewer): array {
    $intro = 'An inspector reviewed your class declaration for ' . declarationEmailCarLine($sub, $car)
        . ' and needs changes before it can be accepted.';
    $next = 'Re-declare the car\'s class. The calculator opens with this declaration filled in, so you only change what the note asks for.';
    $byLine = reviewedByLine($reviewer);
    $lines = array_values(array_filter([$intro, 'Inspector\'s note: ' . $note, $byLine, $next, $redeclareUrl]));
    $html = pretechEmailPara($intro)
        . '<p><strong>Inspector\'s note:</strong> ' . nl2br(h($note)) . '</p>'
        . ($byLine !== '' ? pretechEmailPara($byLine) : '')
        . pretechEmailPara($next)
        . pretechEmailLink($redeclareUrl, 'Re-declare class');
    return [
        'subject' => 'WCMA Class Declaration — changes needed — Car #' . $car['car_number'],
        'html' => pretechEmailWrap('CLASS DECLARATION: CHANGES NEEDED', $html),
        'text' => implode("\n\n", $lines) . "\n",
    ];
}

/**
 * Emails the declaration's account holder after a review. Failures never propagate: the review has
 * already been saved, so this only reports whether the message was sent.
 *
 * @param string $kind 'accepted' | 'sent_back'
 * @param callable $sendFn function(array $to, array $message): bool; $to is a list of [email, name]
 */
function declarationNotify(PDO $pdo, string $kind, array $sub, string $baseUrl, callable $sendFn): bool {
    try {
        $car = db_get_car($pdo, (int)$sub['car_id']);
        if ($car === null) return false;
        $owner = db_find_user_by_id($pdo, (int)$sub['user_id']);
        $to = $owner !== null ? [[$owner['email'], $owner['name']]] : [[$sub['email'], $sub['name']]];
        $reviewer = !empty($sub['reviewed_by_user_id']) ? db_find_user_by_id($pdo, (int)$sub['reviewed_by_user_id']) : null;
        $base = rtrim($baseUrl, '/');

        if ($kind === 'accepted') {
            $message = declarationEmailAccepted($sub, $car, $base . '/garage.php?car=' . (int)$car['id'], $reviewer);
        } elseif ($kind === 'sent_back') {
            $message = declarationEmailSentBack($sub, $car, (string)$sub['reviewer_note'], $base . '/calculator.php?car=' . (int)$car['id'], $reviewer);
        } else {
            return false;
        }
        return (bool)$sendFn($to, $message);
    } catch (Throwable $e) {
        error_log('Declaration notification error: ' . $e->getMessage());
        return false;
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php phpunit.phar --filter 'DeclarationReviewTest|DeclarationEmailTest'`
Expected: PASS. Then run the whole suite with `php phpunit.phar` and expect OK.

- [ ] **Step 7: Commit**

```bash
git add db.php declaration-review-lib.php declaration-email.php email-copy.php tests/DeclarationReviewTest.php tests/DeclarationEmailTest.php
git commit -m "feat(hub): accept or send back a class declaration, with review emails"
```

---

### Task 2: Inspector queries

**Files:**
- Modify: `db.php`. Append a `// ── Inspector (spec §5)` section at the end of the file.
- Modify: `tests/bootstrap.php`. Add `test_make_sheet()`.
- Test: `tests/DbInspectTest.php` (create)

**Interfaces:**
- Produces:
  - `db_get_event_roster_cars(PDO $pdo, int $eventId): array`. Returns `cars.*` plus `owner_name`, `owner_email` and `tagged` (0/1) for every car tagged for the event or with a sheet for it. Ordered by car number: numeric first, then text, then id.
  - `db_get_declarations_for_cars(PDO $pdo, array $carIds): array<int, array[]>`. Maps car id to that car's declarations, newest first.
  - `db_get_self_drivers_for_users(PDO $pdo, array $userIds): array<int, array>`. Maps user id to that account's own driver profile.
  - `db_get_declarations_awaiting_review(PDO $pdo): array`. Declarations with `review_status = 'submitted'`, plus `car_number`, oldest first.
  - `db_get_sheets_awaiting_photo_review(PDO $pdo): array`. Sheets with `photo_status = 'submitted'` and `status = 'submitted'`, plus `event_name`, oldest `updated_at` first.
  - `db_get_gear_awaiting_photo_review(PDO $pdo): array`. Gear rows with `photo_status = 'submitted'` and `status = 'open'`, plus `driver_name`, `owner_user_id` and `owner_name`, oldest `updated_at` first.
  - `db_search_declarations(PDO $pdo, array $f, int $limit, int $offset): array{rows: array, total: int}`.
    - `$f` keys (all optional): `q` (string), `class` (string), `season` (int), `status` (string), `car` (int).
    - Rows are `submissions.*` plus `car_number`, newest first.
    - `%` and `_` in `q` match literally.
  - `db_count_event_plans(PDO $pdo): array<int,int>`. Maps event id to the number of cars tagged for it.
  - `test_make_sheet(PDO $pdo, int $userId, int $subId, int $eventId, string $number = '42', string $driver = 'Test Driver'): int` (tests only)

- [ ] **Step 1: Add the test helper**

At the end of `tests/bootstrap.php`:

```php
/** A submitted tech sheet for declaration $subId at event $eventId. */
function test_make_sheet(PDO $pdo, int $userId, int $subId, int $eventId, string $number = '42', string $driver = 'Test Driver'): int {
    return db_insert_tech_sheet($pdo, [
        'submission_id' => $subId, 'user_id' => $userId, 'event_id' => $eventId, 'sheet_type' => 'standard',
        'entrant_name' => $driver, 'driver_name' => $driver, 'car_make' => 'Mazda', 'car_model' => 'MX-5',
        'car_colour' => 'Red', 'car_number' => $number, 'class' => 'IT1', 'engine_cc' => '1800', 'engine_hp' => '150',
        'car_weight' => 2200, 'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1,
    ]);
}
```

- [ ] **Step 2: Write the failing tests**

`tests/DbInspectTest.php`:

```php
<?php
// wcma-calculator/tests/DbInspectTest.php
use PHPUnit\Framework\TestCase;

final class DbInspectTest extends TestCase
{
    private function user(PDO $pdo, string $email, string $name): int {
        return db_create_user($pdo, ['email' => $email, 'name' => $name, 'password_hash' => 'x', 'google_id' => null]);
    }

    private function ids(array $rows): array {
        return array_map(fn(array $r): int => (int)$r['id'], $rows);
    }

    public function testRosterCarsAreTaggedOrHaveASheetOrderedByNumber(): void
    {
        $pdo = make_temp_pdo();
        $jordan = $this->user($pdo, 'jordan@example.com', 'Jordan Lee');
        $casey = $this->user($pdo, 'casey@example.com', 'Casey Moss');
        $fall = db_create_event($pdo, 'Fall Sprint', '2026-10-04', null);
        $other = db_create_event($pdo, 'Finale', '2026-10-18', null);
        $c42 = test_make_car($pdo, $jordan, '42');
        $c7 = test_make_car($pdo, $casey, '7');
        $c99 = test_make_car($pdo, $casey, '99');
        test_make_car($pdo, $jordan, '5');                          // not going anywhere
        db_tag_event($pdo, $jordan, $fall, $c42);
        $sub7 = db_insert_submission($pdo, test_declaration_data($pdo, $casey, '7'));
        test_make_sheet($pdo, $casey, $sub7, $fall, '7');          // a sheet, but not tagged
        db_tag_event($pdo, $casey, $other, $c99);                  // a different event

        $rows = db_get_event_roster_cars($pdo, $fall);
        $this->assertSame([$c7, $c42], $this->ids($rows));
        $this->assertSame('Casey Moss', $rows[0]['owner_name']);
        $this->assertSame('casey@example.com', $rows[0]['owner_email']);
        $this->assertSame(0, (int)$rows[0]['tagged']);
        $this->assertSame(1, (int)$rows[1]['tagged']);
        $this->assertSame([], db_get_event_roster_cars($pdo, 999));
    }

    public function testDeclarationsAndSelfDriversAreFetchedForManyAtOnce(): void
    {
        $pdo = make_temp_pdo();
        $jordan = $this->user($pdo, 'jordan@example.com', 'Jordan Lee');
        $casey = $this->user($pdo, 'casey@example.com', 'Casey Moss');
        $old = db_insert_submission($pdo, test_declaration_data($pdo, $jordan, '42'));
        $new = db_insert_submission($pdo, test_declaration_data($pdo, $jordan, '42'));
        db_insert_submission($pdo, test_declaration_data($pdo, $casey, '7'));
        $c42 = test_make_car($pdo, $jordan, '42');
        $c7 = test_make_car($pdo, $casey, '7');

        $map = db_get_declarations_for_cars($pdo, [$c42, $c7, 999]);
        $this->assertSame([$new, $old], $this->ids($map[$c42]));
        $this->assertCount(1, $map[$c7]);
        $this->assertArrayNotHasKey(999, $map);
        $this->assertSame([], db_get_declarations_for_cars($pdo, []));

        $self = db_get_self_drivers_for_users($pdo, [$jordan, $casey, 999]);
        $this->assertSame('Jordan Lee', $self[$jordan]['name']);
        $this->assertSame('Casey Moss', $self[$casey]['name']);
        $this->assertArrayNotHasKey(999, $self);
        $this->assertSame([], db_get_self_drivers_for_users($pdo, []));
    }

    public function testReviewQueueQueriesReturnOnlyWorkWaitingOnAnInspectorOldestFirst(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'jordan@example.com', 'Jordan Lee');
        $a = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42', [':submitted_at' => '2026-09-01 10:00:00']));
        $b = db_insert_submission($pdo, test_declaration_data($pdo, $u, '7', [':submitted_at' => '2026-08-01 10:00:00']));
        $c = db_insert_submission($pdo, test_declaration_data($pdo, $u, '9'));
        db_accept_declaration($pdo, $c, $u);

        $decls = db_get_declarations_awaiting_review($pdo);
        $this->assertSame([$b, $a], $this->ids($decls));
        $this->assertSame('7', $decls[0]['car_number']);

        $fall = db_create_event($pdo, 'Fall Sprint', '2026-10-04', null);
        $s1 = test_make_sheet($pdo, $u, $a, $fall, '42');
        $s2 = test_make_sheet($pdo, $u, $b, $fall, '7');
        $pdo->exec("UPDATE tech_sheets SET photo_status = 'submitted', updated_at = '2026-09-10 00:00:00' WHERE id = $s1");
        $pdo->exec("UPDATE tech_sheets SET photo_status = 'needs_changes' WHERE id = $s2");
        $sheets = db_get_sheets_awaiting_photo_review($pdo);
        $this->assertSame([$s1], $this->ids($sheets));
        $this->assertSame('Fall Sprint', $sheets[0]['event_name']);

        $sam = db_create_driver($pdo, $u, 'Sam Patel');
        $g1 = db_insert_gear_record($pdo, $sam, 2026);
        $g2 = db_insert_gear_record($pdo, (int)db_get_self_driver($pdo, $u)['id'], 2026);
        $pdo->exec("UPDATE gear_records SET photo_status = 'submitted' WHERE id IN ($g1, $g2)");
        $pdo->exec("UPDATE gear_records SET status = 'accepted' WHERE id = $g2");
        $gear = db_get_gear_awaiting_photo_review($pdo);
        $this->assertSame([$g1], $this->ids($gear));
        $this->assertSame('Sam Patel', $gear[0]['driver_name']);
        $this->assertSame('Jordan Lee', $gear[0]['owner_name']);
        $this->assertSame($u, (int)$gear[0]['owner_user_id']);
    }

    public function testSearchDeclarationsFiltersAndPaginates(): void
    {
        $pdo = make_temp_pdo();
        $jordan = $this->user($pdo, 'jordan@example.com', 'Jordan Lee');
        $casey = $this->user($pdo, 'casey@example.com', 'Casey Moss');
        $a = db_insert_submission($pdo, test_declaration_data($pdo, $jordan, '42', [':submitted_at' => '2025-06-01 10:00:00', ':calculated_class' => 'GT3', ':make' => 'Honda', ':model' => 'S2000']));
        $b = db_insert_submission($pdo, test_declaration_data($pdo, $jordan, '17', [':submitted_at' => '2026-05-01 10:00:00']));
        $c = db_insert_submission($pdo, test_declaration_data($pdo, $casey, '8', [':submitted_at' => '2026-06-01 10:00:00', ':name' => 'Casey 50% Moss']));
        db_accept_declaration($pdo, $b, $jordan);
        $ids = fn(array $f, int $limit = 50, int $offset = 0): array => $this->ids(db_search_declarations($pdo, $f, $limit, $offset)['rows']);

        $this->assertSame([$c, $b, $a], $ids([]));
        $this->assertSame([$a], $ids(['q' => 's2000']));
        $this->assertSame([$a], $ids(['q' => '42']));                 // car number
        $this->assertSame([$c], $ids(['q' => '50%']));                // % is literal, not a wildcard
        $this->assertSame([], $ids(['q' => '_']));                    // so is _
        $this->assertSame([$c, $b], $ids(['class' => 'IT1']));
        $this->assertSame([$a], $ids(['season' => 2025]));
        $this->assertSame([$b], $ids(['status' => 'accepted']));
        $this->assertSame([$b], $ids(['car' => (int)db_get_submission($pdo, $b)['car_id']]));
        $this->assertSame([], $ids(['class' => 'IT1', 'season' => 2025]));

        $page = db_search_declarations($pdo, [], 2, 2);
        $this->assertSame(3, $page['total']);
        $this->assertSame([$a], $this->ids($page['rows']));
        $this->assertSame('42', db_search_declarations($pdo, ['q' => 's2000'], 50, 0)['rows'][0]['car_number']);
    }

    public function testEventPlanCounts(): void
    {
        $pdo = make_temp_pdo();
        $u = $this->user($pdo, 'jordan@example.com', 'Jordan Lee');
        $fall = db_create_event($pdo, 'Fall Sprint', '2026-10-04', null);
        $finale = db_create_event($pdo, 'Finale', '2026-10-18', null);
        $empty = db_create_event($pdo, 'Empty', '2026-11-01', null);
        db_tag_event($pdo, $u, $fall, test_make_car($pdo, $u, '42'));
        db_tag_event($pdo, $u, $fall, test_make_car($pdo, $u, '17'));
        db_tag_event($pdo, $u, $finale, test_make_car($pdo, $u, '42'));

        $counts = db_count_event_plans($pdo);
        $this->assertSame(2, $counts[$fall]);
        $this->assertSame(1, $counts[$finale]);
        $this->assertArrayNotHasKey($empty, $counts);
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php phpunit.phar --filter DbInspectTest`
Expected: FAIL with "Call to undefined function db_get_event_roster_cars()".

- [ ] **Step 4: Write the queries**

Append to `db.php`:

```php
// ── Inspector (spec §5) ───────────────────────────────────────────────────────

/** Cars on an event's roster: tagged for the event or with a tech sheet for it, with the owner and `tagged` (0/1). */
function db_get_event_roster_cars(PDO $pdo, int $eventId): array {
    $stmt = $pdo->prepare("
        SELECT c.*, u.name AS owner_name, u.email AS owner_email,
               EXISTS (SELECT 1 FROM event_plans p WHERE p.event_id = :e AND p.car_id = c.id) AS tagged
        FROM cars c JOIN users u ON u.id = c.owner_user_id
        WHERE c.id IN (SELECT car_id FROM event_plans WHERE event_id = :e
                       UNION SELECT car_id FROM tech_sheets WHERE event_id = :e)
        ORDER BY CAST(c.car_number_norm AS INTEGER) ASC, c.car_number_norm ASC, c.id ASC
    ");
    $stmt->execute([':e' => $eventId]);
    return $stmt->fetchAll();
}

/** car id => that car's declarations, newest first. Cars with none are absent. */
function db_get_declarations_for_cars(PDO $pdo, array $carIds): array {
    $ids = array_values(array_unique(array_map('intval', $carIds)));
    if (!$ids) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM submissions WHERE car_id IN ($marks) ORDER BY submitted_at DESC, id DESC");
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int)$row['car_id']][] = $row;
    }
    return $map;
}

/** user id => that account's own driver profile (drivers.user_id). Accounts without one are absent. */
function db_get_self_drivers_for_users(PDO $pdo, array $userIds): array {
    $ids = array_values(array_unique(array_map('intval', $userIds)));
    if (!$ids) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM drivers WHERE user_id IN ($marks)");
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int)$row['user_id']] = $row;
    }
    return $map;
}

/** Declarations waiting on an inspector, oldest first, with the car's number. */
function db_get_declarations_awaiting_review(PDO $pdo): array {
    return $pdo->query("
        SELECT s.*, c.car_number AS car_number
        FROM submissions s JOIN cars c ON c.id = s.car_id
        WHERE s.review_status = 'submitted'
        ORDER BY s.submitted_at ASC, s.id ASC
    ")->fetchAll();
}

/** Tech sheets whose car pre-tech photos wait on an inspector, oldest first, with the event name. */
function db_get_sheets_awaiting_photo_review(PDO $pdo): array {
    return $pdo->query("
        SELECT ts.*, e.name AS event_name
        FROM tech_sheets ts LEFT JOIN events e ON e.id = ts.event_id
        WHERE ts.photo_status = 'submitted' AND ts.status = 'submitted'
        ORDER BY ts.updated_at ASC, ts.id ASC
    ")->fetchAll();
}

/** Gear records whose photos wait on an inspector, oldest first, with the driver and the owning account. */
function db_get_gear_awaiting_photo_review(PDO $pdo): array {
    return $pdo->query("
        SELECT g.*, d.owner_user_id AS owner_user_id, d.name AS driver_name, d.name_norm AS driver_name_norm,
               d.licence_no AS licence_no, u.name AS owner_name
        FROM gear_records g JOIN drivers d ON d.id = g.driver_id LEFT JOIN users u ON u.id = d.owner_user_id
        WHERE g.photo_status = 'submitted' AND g.status = 'open'
        ORDER BY g.updated_at ASC, g.id ASC
    ")->fetchAll();
}

/**
 * The Classing tab's search. $f keys (all optional): q (name, email, make, model or car number;
 * % and _ match literally), class, season (year submitted), status (review_status), car (car id).
 *
 * @return array{rows: array, total: int} rows are submissions.* plus car_number, newest first
 */
function db_search_declarations(PDO $pdo, array $f, int $limit, int $offset): array {
    $where = [];
    $params = [];
    if ((string)($f['q'] ?? '') !== '') {
        $where[] = "(s.name LIKE :q ESCAPE '\\' OR s.email LIKE :q ESCAPE '\\' OR s.make LIKE :q ESCAPE '\\'
                     OR s.model LIKE :q ESCAPE '\\' OR c.car_number LIKE :q ESCAPE '\\')";
        $params[':q'] = '%' . addcslashes((string)$f['q'], '%_\\') . '%';
    }
    if ((string)($f['class'] ?? '') !== '') {
        $where[] = 's.calculated_class = :class';
        $params[':class'] = (string)$f['class'];
    }
    if ((int)($f['season'] ?? 0) > 0) {
        $where[] = 'substr(s.submitted_at, 1, 4) = :season';
        $params[':season'] = (string)(int)$f['season'];
    }
    if ((string)($f['status'] ?? '') !== '') {
        $where[] = 's.review_status = :status';
        $params[':status'] = (string)$f['status'];
    }
    if ((int)($f['car'] ?? 0) > 0) {
        $where[] = 's.car_id = :car';
        $params[':car'] = (int)$f['car'];
    }
    $from = ' FROM submissions s LEFT JOIN cars c ON c.id = s.car_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

    $count = $pdo->prepare('SELECT COUNT(*)' . $from);
    $count->execute($params);

    $stmt = $pdo->prepare('SELECT s.*, c.car_number AS car_number' . $from . ' ORDER BY s.submitted_at DESC, s.id DESC LIMIT :limit OFFSET :offset');
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return ['rows' => $stmt->fetchAll(), 'total' => (int)$count->fetchColumn()];
}

/** event id => how many cars are tagged for it. Events nobody tagged are absent. */
function db_count_event_plans(PDO $pdo): array {
    $counts = [];
    foreach ($pdo->query("SELECT event_id, COUNT(*) AS n FROM event_plans GROUP BY event_id")->fetchAll() as $row) {
        $counts[(int)$row['event_id']] = (int)$row['n'];
    }
    return $counts;
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php phpunit.phar --filter DbInspectTest`, then `php phpunit.phar`.
Expected: both OK.

- [ ] **Step 6: Commit**

```bash
git add db.php tests/bootstrap.php tests/DbInspectTest.php
git commit -m "feat(hub): inspector queries for the roster, review queue and classing search"
```

---
### Task 3: Inspector view models

**Files:**
- Create: `inspect-lib.php`
- Test: `tests/InspectLibTest.php` (create)

**Interfaces:**
- Consumes:
  - `techGroupSheetsByCar()`, `techCarKey()`, `techCarStatus()`, `techGearLinksNeedGear()` (tech-status.php)
  - `gearLinksForSheet(array $sheet, array $drivers, array $ownerGear): array` (gear-lib.php)
  - `garageClassLine(array $declarations): array{current: ?array, earlierAccepted: ?array}` (garage-lib.php)
- Produces:
  - `const INSPECT_ROSTER_FILTERS`, with keys `all`, `needs_decals`, `needs_tech`, `no_sheet`, `class_not_accepted` mapped to labels
  - `const INSPECT_CLASSES = ['GTU', 'GT1', 'GT2', 'GT3', 'GT4', 'IT1', 'IT2']`
  - `const INSPECT_DECLARATION_STATUSES = ['submitted', 'needs_changes', 'accepted', 'superseded']`
  - `inspectRosterRows(array $cars, array $eventSheets, array $seasonSheets, array $declarations, array $sheetDrivers, array $selfDrivers, array $seasonGear, int $season): array`. Each row has the shape `{car, sheet: ?array, class: garageClassLine(), status: techCarStatus(), gear_links}`.
  - `inspectRosterFilter(array $rows, string $filter): array`
  - `inspectRosterCounts(array $rows): array<string,int>`
  - `inspectReviewQueue(array $declarations, array $sheets, array $gear): array`. Each item has the shape `{kind: 'declaration'|'car_photos'|'gear_photos', id, title, detail, since, url}`.
  - `inspectClassingFilters(array $get): array{q: string, class: string, season: int, status: string, car: int, page: int}`
  - `inspectClassingQuery(array $f, array $over = []): string`

**What each roster filter means** (spec §5 names the filters; this plan pins what they do):
- **Needs decals**: the car tech for the season is accepted, whether pre-teched by photos or teched in person. Both acceptance emails tell the competitor to collect decals at the event, so this is the decal table's list.
- **Needs tech at track**: the car tech is not accepted, or any listed driver's gear is not accepted.
- **No sheet yet**: the car is tagged but has no tech sheet for this event.
- **Class not accepted**: the car has no current declaration, or its current declaration isn't accepted.

- [ ] **Step 1: Write the failing tests**

`tests/InspectLibTest.php`:

```php
<?php
// wcma-calculator/tests/InspectLibTest.php
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../garage-lib.php';
require_once __DIR__ . '/../inspect-lib.php';

use PHPUnit\Framework\TestCase;

final class InspectLibTest extends TestCase
{
    private function car(int $id, int $owner, string $n): array {
        return ['id' => $id, 'owner_user_id' => $owner, 'car_number' => $n, 'car_number_norm' => $n,
                'year' => '1999', 'make' => 'Mazda', 'model' => 'Miata', 'owner_name' => 'Owner ' . $owner, 'tagged' => 1];
    }

    private function sheet(int $id, int $carId, int $owner, array $o = []): array {
        return array_merge(['id' => $id, 'car_id' => $carId, 'user_id' => $owner, 'event_id' => 3, 'season' => 2026,
            'status' => 'submitted', 'photo_status' => null, 'accepted_via' => null, 'driver_name' => 'Jordan Lee'], $o);
    }

    private function gear(int $id, int $owner, string $name, string $status = 'open'): array {
        return ['id' => $id, 'owner_user_id' => $owner, 'season' => 2026, 'driver_name' => $name,
                'driver_name_norm' => db_driver_name_norm($name), 'status' => $status, 'photo_status' => null,
                'accepted_via' => $status === 'accepted' ? 'in_person' : null];
    }

    private function decl(int $id, string $status, ?string $acceptedAt = null): array {
        return ['id' => $id, 'review_status' => $status, 'accepted_at' => $acceptedAt, 'calculated_class' => 'IT1'];
    }

    public function testRowsCarryTheEventSheetClassCarTechAndGear(): void
    {
        $eventSheet = $this->sheet(5, 1, 10);
        $earlier = $this->sheet(3, 1, 10, ['event_id' => 2, 'status' => 'teched', 'accepted_via' => 'in_person']);
        $rows = inspectRosterRows(
            [$this->car(1, 10, '17')], [$eventSheet], [$earlier, $eventSheet],
            [1 => [$this->decl(8, 'accepted', '2026-05-01 10:00:00')]],
            [5 => [['driver_number' => 2, 'driver_name' => 'Sam Patel']]],
            [10 => ['name' => 'Jordan Lee']],
            [$this->gear(20, 10, 'Jordan Lee', 'accepted')],
            2026
        );
        $this->assertCount(1, $rows);
        $this->assertSame(5, (int)$rows[0]['sheet']['id']);
        $this->assertSame('accepted', $rows[0]['status']['state']);            // teched at an earlier event this season
        $this->assertSame(8, (int)$rows[0]['class']['current']['id']);
        $this->assertSame(['Jordan Lee', 'Sam Patel'], array_column($rows[0]['gear_links'], 'name'));
        $this->assertSame(['accepted', 'none'], array_map(fn(array $l): string => $l['status']['state'], $rows[0]['gear_links']));
    }

    public function testTheNewestEventSheetWinsAndOtherCarsSheetsAreIgnored(): void
    {
        $rows = inspectRosterRows([$this->car(1, 10, '17')],
            [$this->sheet(5, 1, 10), $this->sheet(9, 1, 10), $this->sheet(12, 2, 11)], [], [], [], [], [], 2026);
        $this->assertSame(9, (int)$rows[0]['sheet']['id']);
    }

    public function testTaggedCarWithoutASheetShowsTheOwnersGear(): void
    {
        $rows = inspectRosterRows(
            [$this->car(2, 11, '42'), $this->car(3, 12, '7')], [], [], [], [],
            [11 => ['name' => 'Casey Moss']],
            [$this->gear(30, 11, 'Casey Moss'), $this->gear(31, 12, 'Casey Moss')],
            2026
        );
        $this->assertNull($rows[0]['sheet']);
        $this->assertSame('none', $rows[0]['status']['state']);
        $this->assertNull($rows[0]['class']['current']);
        $this->assertCount(1, $rows[0]['gear_links']);
        $this->assertSame(30, (int)$rows[0]['gear_links'][0]['gear']['id']);   // the owner's record, not another account's
        $this->assertSame([], $rows[1]['gear_links']);                           // owner 12 has no self profile
    }

    private function filterRow(string $carState, bool $sheet, ?string $declStatus, array $gearStates): array {
        return [
            'car' => [], 'sheet' => $sheet ? ['id' => 1] : null,
            'class' => ['current' => $declStatus === null ? null : $this->decl(1, $declStatus), 'earlierAccepted' => null],
            'status' => ['state' => $carState, 'via' => null, 'sheet_id' => null],
            'gear_links' => array_map(fn(string $s): array => ['status' => ['state' => $s, 'via' => null]], $gearStates),
        ];
    }

    public function testRosterFiltersAndCounts(): void
    {
        $done = $this->filterRow('accepted', true, 'accepted', ['accepted']);
        $gearDue = $this->filterRow('accepted', true, 'submitted', ['none']);
        $noSheet = $this->filterRow('none', false, null, []);
        $pending = $this->filterRow('pending_review', true, 'needs_changes', ['accepted']);
        $rows = [$done, $gearDue, $noSheet, $pending];

        $this->assertSame([$done, $gearDue], inspectRosterFilter($rows, 'needs_decals'));
        $this->assertSame([$gearDue, $noSheet, $pending], inspectRosterFilter($rows, 'needs_tech'));
        $this->assertSame([$noSheet], inspectRosterFilter($rows, 'no_sheet'));
        $this->assertSame([$gearDue, $noSheet, $pending], inspectRosterFilter($rows, 'class_not_accepted'));
        $this->assertSame($rows, inspectRosterFilter($rows, 'all'));
        $this->assertSame($rows, inspectRosterFilter($rows, 'bogus'));
        $this->assertSame(['all' => 4, 'needs_decals' => 2, 'needs_tech' => 3, 'no_sheet' => 1, 'class_not_accepted' => 3], inspectRosterCounts($rows));
    }

    public function testQueueMergesTheThreeKindsOldestFirst(): void
    {
        $items = inspectReviewQueue(
            [['id' => 4, 'car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000', 'name' => 'Jordan Lee', 'calculated_class' => 'GT3', 'submitted_at' => '2026-09-03 09:00:00']],
            [['id' => 12, 'car_number' => '17', 'car_make' => 'Mazda', 'car_model' => 'Miata', 'entrant_name' => 'Jordan Lee', 'event_name' => 'Fall Sprint', 'updated_at' => '2026-09-01 08:00:00']],
            [['id' => 6, 'driver_name' => 'Sam Patel', 'owner_name' => 'Jordan Lee', 'season' => 2026, 'updated_at' => '2026-09-02 12:00:00']]
        );
        $this->assertSame(['car_photos', 'gear_photos', 'declaration'], array_column($items, 'kind'));
        $this->assertSame('Car pre-tech photos: #17 Mazda Miata', $items[0]['title']);
        $this->assertSame('Jordan Lee · Fall Sprint', $items[0]['detail']);
        $this->assertSame('inspect.php?action=tech-sheet&id=12#pretech-review', $items[0]['url']);
        $this->assertSame('Gear pre-tech photos: Sam Patel', $items[1]['title']);
        $this->assertSame('Entered by Jordan Lee · 2026', $items[1]['detail']);
        $this->assertSame('inspect.php?action=gear-record&id=6#gear-review', $items[1]['url']);
        $this->assertSame('Class declaration: #42 2004 Honda S2000', $items[2]['title']);
        $this->assertSame('Jordan Lee · GT3', $items[2]['detail']);
        $this->assertSame('inspect.php?action=declaration&id=4', $items[2]['url']);
        $this->assertSame('2026-09-03 09:00:00', $items[2]['since']);
        $this->assertSame([], inspectReviewQueue([], [], []));
    }

    public function testClassingFiltersAreValidated(): void
    {
        $this->assertSame(['q' => 's2000', 'class' => 'GT3', 'season' => 2026, 'status' => 'accepted', 'car' => 4, 'page' => 2],
            inspectClassingFilters(['q' => '  s2000 ', 'class' => 'gt3', 'season' => '2026', 'status' => 'accepted', 'car' => '4', 'page' => '2']));
        $this->assertSame(['q' => '', 'class' => '', 'season' => 0, 'status' => '', 'car' => 0, 'page' => 1],
            inspectClassingFilters(['q' => ['x'], 'class' => 'GT9', 'season' => '1999', 'status' => 'bogus', 'car' => '-3', 'page' => '0']));
        $this->assertSame(100, mb_strlen(inspectClassingFilters(['q' => str_repeat('é', 150)])['q'], 'UTF-8'));
    }

    public function testClassingQueryKeepsOnlySetFilters(): void
    {
        $f = inspectClassingFilters(['q' => 'honda civic', 'class' => 'GT3']);
        $this->assertSame('inspect.php?action=classing&q=honda+civic&class=GT3', inspectClassingQuery($f));
        $this->assertSame('inspect.php?action=classing&q=honda+civic&class=GT3&page=3', inspectClassingQuery($f, ['page' => 3]));
        $this->assertSame('inspect.php?action=classing', inspectClassingQuery(inspectClassingFilters([])));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter InspectLibTest`
Expected: an error, because `inspect-lib.php` does not exist yet.

- [ ] **Step 3: Create `inspect-lib.php`**

```php
<?php
// wcma-calculator/inspect-lib.php
//
// Pure view models for the Inspector section (spec §5): the event roster, the review queue and the
// Classing filters. No DB, no HTML. Callers must have loaded tech-status.php, gear-lib.php
// (gearLinksForSheet()) and garage-lib.php (garageClassLine()).

const INSPECT_ROSTER_FILTERS = [
    'all' => 'All cars',
    'needs_decals' => 'Needs decals (car tech accepted)',
    'needs_tech' => 'Needs tech at the track (car or gear)',
    'no_sheet' => 'No tech sheet yet',
    'class_not_accepted' => 'Class not accepted',
];

const INSPECT_CLASSES = ['GTU', 'GT1', 'GT2', 'GT3', 'GT4', 'IT1', 'IT2'];
const INSPECT_DECLARATION_STATUSES = ['submitted', 'needs_changes', 'accepted', 'superseded'];

/**
 * One roster row per car on an event: tagged for it, or with a sheet for it (spec §5, "Event roster").
 * Drivers come from the car's sheet for the event, or from the owner's self profile when there is
 * no sheet yet (the same rule as Home, spec §3).
 *
 * @param array $cars          db_get_event_roster_cars() rows
 * @param array $eventSheets   the event's sheets (db_get_event_tech_sheets())
 * @param array $seasonSheets  every sheet in the event's season: a car's other sheets decide its car tech
 * @param array $declarations  car id => that car's declarations, newest first (db_get_declarations_for_cars())
 * @param array $sheetDrivers  sheet id => additional driver rows (db_get_drivers_for_sheets())
 * @param array $selfDrivers   owner user id => self driver row (db_get_self_drivers_for_users())
 * @param array $seasonGear    every gear record in the season (db_get_gear_records_for_season())
 * @return array<int, array{car: array, sheet: ?array, class: array, status: array, gear_links: array}>
 */
function inspectRosterRows(array $cars, array $eventSheets, array $seasonSheets, array $declarations,
                           array $sheetDrivers, array $selfDrivers, array $seasonGear, int $season): array {
    $sheetByCar = [];
    foreach ($eventSheets as $s) {
        $cid = (int)$s['car_id'];
        if (!isset($sheetByCar[$cid]) || (int)$s['id'] > (int)$sheetByCar[$cid]['id']) $sheetByCar[$cid] = $s;
    }
    $groups = techGroupSheetsByCar($seasonSheets);
    $gearByOwner = [];
    foreach ($seasonGear as $g) {
        $gearByOwner[(int)$g['owner_user_id']][] = $g;
    }

    $rows = [];
    foreach ($cars as $car) {
        $cid = (int)$car['id'];
        $owner = (int)$car['owner_user_id'];
        $sheet = $sheetByCar[$cid] ?? null;
        $ownerGear = $gearByOwner[$owner] ?? [];
        if ($sheet !== null) {
            $links = gearLinksForSheet($sheet, $sheetDrivers[(int)$sheet['id']] ?? [], $ownerGear);
        } elseif (isset($selfDrivers[$owner])) {
            $links = gearLinksForSheet(['user_id' => $owner, 'season' => $season, 'driver_name' => (string)$selfDrivers[$owner]['name']], [], $ownerGear);
        } else {
            $links = [];
        }
        $rows[] = [
            'car' => $car,
            'sheet' => $sheet,
            'class' => garageClassLine($declarations[$cid] ?? []),
            'status' => techCarStatus($groups[techCarKey(['car_id' => $cid, 'season' => $season])] ?? []),
            'gear_links' => $links,
        ];
    }
    return $rows;
}

/** $filter is an INSPECT_ROSTER_FILTERS key. Unknown values mean 'all'. */
function inspectRosterFilter(array $rows, string $filter): array {
    if ($filter === 'all' || !isset(INSPECT_ROSTER_FILTERS[$filter])) return $rows;
    return array_values(array_filter($rows, function (array $r) use ($filter): bool {
        $carAccepted = $r['status']['state'] === 'accepted';
        switch ($filter) {
            case 'needs_decals': return $carAccepted;
            case 'needs_tech':   return !$carAccepted || techGearLinksNeedGear($r['gear_links']);
            case 'no_sheet':     return $r['sheet'] === null;
        }
        $current = $r['class']['current'];   // class_not_accepted
        return $current === null || $current['review_status'] !== 'accepted';
    }));
}

/** @return array<string,int> INSPECT_ROSTER_FILTERS key => how many rows it keeps */
function inspectRosterCounts(array $rows): array {
    $counts = [];
    foreach (array_keys(INSPECT_ROSTER_FILTERS) as $key) {
        $counts[$key] = count(inspectRosterFilter($rows, $key));
    }
    return $counts;
}

/**
 * The review queue (spec §5): declarations with an inspector, then car and gear photo sets awaiting
 * review, merged oldest first. Each item links to the page that holds its review card.
 *
 * @param array $declarations db_get_declarations_awaiting_review()
 * @param array $sheets       db_get_sheets_awaiting_photo_review()
 * @param array $gear         db_get_gear_awaiting_photo_review()
 * @return array<int, array{kind: string, id: int, title: string, detail: string, since: string, url: string}>
 */
function inspectReviewQueue(array $declarations, array $sheets, array $gear): array {
    $items = [];
    foreach ($declarations as $d) {
        $items[] = [
            'kind' => 'declaration', 'id' => (int)$d['id'],
            'title' => 'Class declaration: #' . $d['car_number'] . ' ' . trim($d['year'] . ' ' . $d['make'] . ' ' . $d['model']),
            'detail' => $d['name'] . ' · ' . ($d['calculated_class'] ?? '—'),
            'since' => (string)$d['submitted_at'],
            'url' => 'inspect.php?action=declaration&id=' . (int)$d['id'],
        ];
    }
    foreach ($sheets as $s) {
        $items[] = [
            'kind' => 'car_photos', 'id' => (int)$s['id'],
            'title' => 'Car pre-tech photos: #' . $s['car_number'] . ' ' . trim($s['car_make'] . ' ' . $s['car_model']),
            'detail' => $s['entrant_name'] . ' · ' . ($s['event_name'] ?? ''),
            'since' => (string)$s['updated_at'],
            'url' => 'inspect.php?action=tech-sheet&id=' . (int)$s['id'] . '#pretech-review',
        ];
    }
    foreach ($gear as $g) {
        $items[] = [
            'kind' => 'gear_photos', 'id' => (int)$g['id'],
            'title' => 'Gear pre-tech photos: ' . $g['driver_name'],
            'detail' => 'Entered by ' . ($g['owner_name'] ?? '') . ' · ' . (int)$g['season'],
            'since' => (string)$g['updated_at'],
            'url' => 'inspect.php?action=gear-record&id=' . (int)$g['id'] . '#gear-review',
        ];
    }
    usort($items, fn(array $a, array $b): int => strcmp($a['since'], $b['since']) ?: strcmp($a['kind'], $b['kind']) ?: ($a['id'] <=> $b['id']));
    return $items;
}

/**
 * The Classing tab's filters from the query string, validated. Anything unknown or malformed means "any".
 *
 * @return array{q: string, class: string, season: int, status: string, car: int, page: int}
 */
function inspectClassingFilters(array $get): array {
    $str = fn(string $k): string => is_string($get[$k] ?? null) ? trim($get[$k]) : '';
    $int = fn(string $k): int => is_scalar($get[$k] ?? null) && ctype_digit((string)$get[$k]) ? (int)$get[$k] : 0;
    $class = strtoupper($str('class'));
    $status = $str('status');
    $season = $int('season');
    return [
        'q' => mb_substr($str('q'), 0, 100, 'UTF-8'),
        'class' => in_array($class, INSPECT_CLASSES, true) ? $class : '',
        'season' => $season >= 2000 && $season <= 2100 ? $season : 0,
        'status' => in_array($status, INSPECT_DECLARATION_STATUSES, true) ? $status : '',
        'car' => $int('car'),
        'page' => max(1, $int('page')),
    ];
}

/** A Classing URL for filters $f with $over applied (e.g. ['page' => 2]). Unset filters and page 1 are left out. */
function inspectClassingQuery(array $f, array $over = []): string {
    $params = ['action' => 'classing'];
    foreach (array_merge($f, $over) as $key => $value) {
        if ($value === '' || $value === 0 || ($key === 'page' && (int)$value <= 1)) continue;
        $params[$key] = $value;
    }
    return 'inspect.php?' . http_build_query($params);
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar --filter InspectLibTest`, then `php phpunit.phar`.
Expected: both OK.

- [ ] **Step 5: Commit**

```bash
git add inspect-lib.php tests/InspectLibTest.php
git commit -m "feat(hub): inspector view models for the roster, review queue and classing filters"
```

---

### Task 4: Staff navigation, section tabs, action gates and the 403 page

**Files:**
- Modify: `layout.php`, `roles.php`, `session_bootstrap.php`, `css/hub.css`
- Test: `tests/LayoutTest.php` (modify), `tests/RolesTest.php` (modify)

**Interfaces:**
- Produces:
  - `hubNavItems()` loses the single `staff` item. A signed-in inspector or admin gets `['key' => 'inspect', 'label' => 'Inspector', 'href' => 'inspect.php', 'staff' => true]`, and an admin also gets `['key' => 'admin', 'label' => 'Admin', 'href' => 'admin.php', 'staff' => true]`.
  - Pages use the section keys `'inspect'` and `'admin'` with `renderPageStart()` / `renderSiteHeader()`.
  - `hubSubnavHtml(string $label, array $tabs, string $current): string`, where `$tabs` maps a key to `[href, label]`
  - `const INSPECT_TABS` (`roster`, `queue`, `classing`, `gear`) and `const ADMIN_TABS` (`users`, `events`, `season-links`, `settings`, `feedback`)
  - `inspectSubnavHtml(string $current): string` and `adminSubnavHtml(string $current): string`
  - `hubRenderForbidden(): void`. It echoes the "no access" page inside the layout; the caller sets the 403 status.
  - `const INSPECT_ADMIN_ONLY_ACTIONS`, `const INSPECT_POST_ACTIONS`, `inspectActionMinRole(string $action): string` (roles.php)

The **Inspector** nav item points at `inspect.php`, which Task 5 creates. The branch is not deployed between tasks, so that's fine.

- [ ] **Step 1: Write the failing tests**

In `tests/LayoutTest.php`, replace `testNavItemsByAudience()` with the following, and add the three new methods after it:

```php
    public function testNavItemsByAudience(): void
    {
        $this->assertSame(['home', 'calculator', 'signin'], $this->keys(null));
        $this->assertSame(['home', 'garage', 'drivers', 'calculator'], $this->keys(['id' => 1, 'name' => 'Jordan Lee', 'role' => 'user']));
        $this->assertSame(['home', 'garage', 'drivers', 'calculator', 'inspect'], $this->keys(['id' => 1, 'name' => 'Ivy Inspector', 'role' => 'inspector']));
        $this->assertSame(['home', 'garage', 'drivers', 'calculator', 'inspect', 'admin'], $this->keys(['id' => 1, 'name' => 'Site Admin', 'role' => 'admin']));

        $admin = array_column(hubNavItems(['id' => 1, 'name' => 'Site Admin', 'role' => 'admin']), 'href', 'label');
        $this->assertSame('inspect.php', $admin['Inspector']);
        $this->assertSame('admin.php', $admin['Admin']);
        $this->assertSame('calculator.php', $admin['Class Calculator']);
    }

    public function testStaffSectionsSitAfterADivider(): void
    {
        $html = hubNavHtml(['id' => 1, 'name' => 'Site Admin', 'role' => 'admin'], 'inspect');
        $this->assertSame(2, substr_count($html, '<li class="hub-nav-staff">'));
        $this->assertStringContainsString('<li class="hub-nav-staff"><span class="hub-nav-current" aria-current="page">Inspector</span></li>', $html);
        $this->assertStringContainsString('<li class="hub-nav-staff"><a href="admin.php">Admin</a></li>', $html);
        $this->assertStringContainsString('<li><a href="garage.php">Garage</a></li>', $html);
    }

    public function testSectionTabsMarkTheCurrentTab(): void
    {
        $html = inspectSubnavHtml('queue');
        $this->assertStringContainsString('<nav class="hub-tabs" aria-label="Inspector">', $html);
        $this->assertStringContainsString('<span class="hub-tab-current" aria-current="page">Review queue</span>', $html);
        foreach (['href="inspect.php">Event roster<', 'href="inspect.php?action=classing">Classing<', 'href="inspect.php?action=gear">Gear<'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringNotContainsString('hub-tab-current', inspectSubnavHtml('nope'));

        $admin = adminSubnavHtml('events');
        $this->assertStringContainsString('<nav class="hub-tabs" aria-label="Admin">', $admin);
        $this->assertStringContainsString('<span class="hub-tab-current" aria-current="page">Events</span>', $admin);
        foreach (['Users &amp; roles', 'Season links', 'Settings', 'Feedback'] as $label) {
            $this->assertStringContainsString('>' . $label . '</a>', $admin);
        }
    }

    public function testForbiddenPageRendersInsideTheLayout(): void
    {
        $GLOBALS['TEST_CURRENT_USER'] = ['id' => 3, 'name' => 'Jordan Lee', 'role' => 'user'];
        ob_start();
        hubRenderForbidden();
        $html = ob_get_clean();
        unset($GLOBALS['TEST_CURRENT_USER']);

        $this->assertStringContainsString('class="hub-header"', $html);
        $this->assertStringContainsString('You don&#039;t have access to this page', $html);
        $this->assertStringContainsString('<a class="hub-btn" href="index.php">Go to Home</a>', $html);

        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../session_bootstrap.php'));
        $this->assertMatchesRegularExpression("/case 'forbidden':\\s*http_response_code\\(403\\);\\s*require_once __DIR__ \\. '\\/view_helpers\\.php';[^\\n]*\\n\\s*hubRenderForbidden\\(\\);\\s*exit;/", $src);
    }
```

In `tests/RolesTest.php`, add:

```php
    public function testInspectorSectionIsOpenToInspectorsExceptDeclarationHousekeeping(): void
    {
        foreach (['roster', 'queue', 'classing', 'declaration', 'declaration-accept', 'declaration-send-back',
                  'declaration-resend', 'gear-create-accept', 'tech-sheet-accept', 'anything-new'] as $action) {
            $this->assertSame('inspector', inspectActionMinRole($action), $action);
        }
        foreach (['declaration-delete', 'declarations-bulk-delete', 'declaration-update-contact'] as $action) {
            $this->assertSame('admin', inspectActionMinRole($action), $action);
            $this->assertContains($action, INSPECT_POST_ACTIONS, $action);
        }
        foreach (['roster', 'queue', 'classing', 'declaration', 'declaration-file', 'declarations-export',
                  'tech-sheet', 'tech-sheet-sig', 'gear', 'gear-record'] as $action) {
            $this->assertNotContains($action, INSPECT_POST_ACTIONS, $action);
        }
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter 'LayoutTest|RolesTest'`
Expected: FAIL. The nav still has the `staff` key, and `inspectSubnavHtml()` / `inspectActionMinRole()` are undefined.

- [ ] **Step 3: Add the gates to `roles.php`**

After `adminActionMinRole()`:

```php
/** inspect.php actions only admins may use. Everything else in the Inspector section is open to inspectors. */
const INSPECT_ADMIN_ONLY_ACTIONS = ['declaration-delete', 'declarations-bulk-delete', 'declaration-update-contact'];

/** inspect.php actions that change data: POST-only and CSRF-checked in inspect.php before its router runs. */
const INSPECT_POST_ACTIONS = [
    'tech-sheet-accept', 'tech-sheet-revoke', 'tech-sheet-photos-accept', 'tech-sheet-photos-send-back',
    'gear-record-accept', 'gear-record-revoke', 'gear-photos-accept', 'gear-photos-send-back', 'gear-create-accept',
    'declaration-accept', 'declaration-send-back', 'declaration-resend',
    'declaration-delete', 'declarations-bulk-delete', 'declaration-update-contact',
];

function inspectActionMinRole(string $action): string {
    return in_array($action, INSPECT_ADMIN_ONLY_ACTIONS, true) ? 'admin' : 'inspector';
}
```

- [ ] **Step 4: Update `layout.php`**

Replace the staff block at the end of `hubNavItems()`:

```php
    if (user_has_role($user, 'inspector')) {
        $items[] = ['key' => 'staff', 'label' => user_has_role($user, 'admin') ? 'Admin' : 'Inspector', 'href' => 'admin.php'];
    }
    return $items;
```

with:

```php
    if (user_has_role($user, 'inspector')) {
        $items[] = ['key' => 'inspect', 'label' => 'Inspector', 'href' => 'inspect.php', 'staff' => true];
    }
    if (user_has_role($user, 'admin')) {
        $items[] = ['key' => 'admin', 'label' => 'Admin', 'href' => 'admin.php', 'staff' => true];
    }
    return $items;
```

In `hubNavHtml()`, replace:

```php
        $cls = $item['key'] === 'staff' ? ' class="hub-nav-staff"' : '';
```

with:

```php
        $cls = !empty($item['staff']) ? ' class="hub-nav-staff"' : '';
```

Add after `hubFooterHtml()`:

```php
/** Section tabs (spec §1: "second-level tabs built from the same nav component"). $tabs: key => [href, label]. */
function hubSubnavHtml(string $label, array $tabs, string $current): string {
    $out = '<nav class="hub-tabs" aria-label="' . h($label) . '"><ul>';
    foreach ($tabs as $key => [$href, $text]) {
        $out .= '<li>' . ($key === $current
            ? '<span class="hub-tab-current" aria-current="page">' . h($text) . '</span>'
            : '<a href="' . h($href) . '">' . h($text) . '</a>') . '</li>';
    }
    return $out . '</ul></nav>';
}

const INSPECT_TABS = [
    'roster' => ['inspect.php', 'Event roster'],
    'queue' => ['inspect.php?action=queue', 'Review queue'],
    'classing' => ['inspect.php?action=classing', 'Classing'],
    'gear' => ['inspect.php?action=gear', 'Gear'],
];

const ADMIN_TABS = [
    'users' => ['admin.php?action=users', 'Users & roles'],
    'events' => ['admin.php?action=events', 'Events'],
    'season-links' => ['admin.php?action=season-links', 'Season links'],
    'settings' => ['admin.php?action=settings', 'Settings'],
    'feedback' => ['admin.php?action=feedback', 'Feedback'],
];

function inspectSubnavHtml(string $current): string {
    return hubSubnavHtml('Inspector', INSPECT_TABS, $current);
}

function adminSubnavHtml(string $current): string {
    return hubSubnavHtml('Admin', ADMIN_TABS, $current);
}

/** The signed-in "no access" page (spec §9): a 403 inside the layout. The caller sets the status code. */
function hubRenderForbidden(): void {
    renderPageStart('No access', '');
    echo '<h1 class="hub-page-title">You don&#039;t have access to this page</h1>'
        . '<p>This page is for WCMA inspectors and admins. If you think you should have access, ask a WCMA admin.</p>'
        . '<p><a class="hub-btn" href="index.php">Go to Home</a></p>';
    renderPageEnd();
}
```

`const` declarations with array values at the top level of `layout.php` are fine. Nothing reads them before the file is included.

- [ ] **Step 5: Render the 403 inside the layout**

In `session_bootstrap.php`, in `require_role()`, replace:

```php
        case 'forbidden':
            http_response_code(403);
            echo 'You do not have access to this page.';
            exit;
```

with:

```php
        case 'forbidden':
            http_response_code(403);
            require_once __DIR__ . '/view_helpers.php';   // h(), and layout.php for renderPageStart()
            hubRenderForbidden();
            exit;
```

Every page loads `view_helpers.php` with `require` before it calls `require_role()`. `require_once` recognises files already loaded by `require`, so this never loads the file twice.

- [ ] **Step 6: Styles**

In `css/hub.css`, after the `.hub-nav-staff a, …` rule:

```css
.hub-nav-staff + .hub-nav-staff { border-left: 0; margin-left: 0; padding-left: 0; }
```

After the `/* Sub-navigation */` rules:

```css
/* Section tabs (Inspector, Admin) */
.hub-tabs ul { display: flex; flex-wrap: wrap; gap: 4px; list-style: none; margin: 0; padding: 0; }
.hub-tabs a, .hub-tab-current {
  min-height: var(--hub-tap);
  display: inline-flex;
  align-items: center;
  padding: 0 12px;
  font-weight: 600;
  text-decoration: none;
  border-bottom: 3px solid transparent;
}
.hub-tabs a { color: var(--hub-ink-2); }
.hub-tab-current { color: var(--hub-ink); border-bottom-color: var(--hub-red); }
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php phpunit.phar`
Expected: OK. `AdminNavTest` still passes, because `renderAdminNav()` is untouched until Task 9.

- [ ] **Step 8: Commit**

```bash
git add layout.php roles.php session_bootstrap.php css/hub.css tests/LayoutTest.php tests/RolesTest.php
git commit -m "feat(hub): Inspector and Admin nav sections, section tabs, and a 403 page inside the layout"
```

---

### Task 5: `inspect.php` and the Event roster; tech sheet review moves

**Files:**
- Create: `inspect.php`, `inspect-page.php`
- Modify: `admin-tech-sheets.php`:
  - remove the old list (`handleTechSheetsList()`, `renderTechSheetsListPage()`)
  - point every URL at `inspect.php`
  - move the review page to `renderPageStart()`
- Modify: `pretech-email.php`. The review link goes to `inspect.php`.
- Modify: `admin.php`. Redirect moved actions and drop the tech sheet routes.
- Modify: `roles.php`. Add `ADMIN_MOVED_ACTIONS` and `adminMovedActionUrl()`.
- Modify: `js/admin-tech-review.js` (header comment), `css/hub.css`
- Test:
  - create `tests/InspectPageTest.php` and `tests/InspectSourceTest.php`
  - modify `tests/RolesTest.php`, `tests/PretechEmailTest.php`, `tests/GearCreateAcceptSourceTest.php` and `tests/GearLinksSourceTest.php`

**Interfaces:**
- Consumes:
  - Task 2's roster queries
  - Task 3's `inspectRosterRows()`, `inspectRosterFilter()` and `inspectRosterCounts()`
  - Task 4's `inspectSubnavHtml()`, `inspectActionMinRole()` and `INSPECT_POST_ACTIONS`
  - `techDefaultEventId()` and `techSeasonFromDate()` (tech-status.php)
  - `garageClassHtml()` and `garageCarTitle()` (garage-page.php)
  - `homeStatusClass()` (home-page.php)
  - `renderGearChips()` (gear-chips.php)
- Produces:
  - `inspect.php` actions: `roster` (the default; `event` and `filter` query params), `tech-sheet`, `tech-sheet-sig`, `tech-sheet-accept`, `tech-sheet-revoke`, `tech-sheet-photos-accept`, `tech-sheet-photos-send-back`
  - `inspectCsrfField(string $csrf): string`
  - `renderInspectRosterHtml(array $vm): string`, with `$vm` = `{events, eventId: int, filter: string, rows, counts, season: int, csrf: string}`
  - `const ADMIN_MOVED_ACTIONS` and `adminMovedActionUrl(string $action, array $query): ?string` (roles.php)

- [ ] **Step 1: Write the failing tests**

`tests/InspectPageTest.php`:

```php
<?php
// wcma-calculator/tests/InspectPageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../events-lib.php';
require_once __DIR__ . '/../tech-status.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../gear-chips.php';
require_once __DIR__ . '/../home-page.php';
require_once __DIR__ . '/../garage-lib.php';
require_once __DIR__ . '/../garage-page.php';
require_once __DIR__ . '/../declaration-review-lib.php';
require_once __DIR__ . '/../inspect-lib.php';
require_once __DIR__ . '/../inspect-page.php';

use PHPUnit\Framework\TestCase;

final class InspectPageTest extends TestCase
{
    private function link(int $n, string $name): array {
        return ['driver_number' => $n, 'name' => $name, 'name_norm' => db_driver_name_norm($name), 'gear' => null, 'status' => ['state' => 'none', 'via' => null]];
    }

    private function row(?array $sheet, array $links, ?array $decl = null): array {
        return [
            'car' => ['id' => 1, 'owner_user_id' => 10, 'car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000', 'owner_name' => 'Jordan <Lee>', 'tagged' => 1],
            'sheet' => $sheet, 'class' => ['current' => $decl, 'earlierAccepted' => null],
            'status' => ['state' => 'none', 'via' => null, 'sheet_id' => null], 'gear_links' => $links,
        ];
    }

    private function vm(array $rows, array $o = []): array {
        return array_merge([
            'events' => [['id' => 3, 'name' => 'Fall Sprint', 'event_date' => date('Y') . '-10-04']], 'eventId' => 3,
            'filter' => 'all', 'rows' => $rows, 'counts' => inspectRosterCounts($rows), 'season' => (int)date('Y'), 'csrf' => 'tok',
        ], $o);
    }

    public function testRowShowsTheCarOwnerClassSheetAndCarTech(): void
    {
        $decl = ['id' => 8, 'review_status' => 'submitted', 'accepted_at' => null, 'calculated_class' => 'IT1'];
        $html = renderInspectRosterHtml($this->vm([$this->row(['id' => 5, 'status' => 'submitted'], [], $decl)]));
        foreach (['<span class="hub-plate">42</span>', '2004 Honda S2000', 'Jordan &lt;Lee&gt;', 'IT1', 'With an inspector',
                  'href="inspect.php?action=declaration&amp;id=8">Review</a>', 'href="inspect.php?action=tech-sheet&amp;id=5">Review</a>',
                  'Needs tech at the track', '<option value="3" selected>Fall Sprint', 'All cars (1)'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function testADriverWithoutGearOnASheetGetsTheOneTapButton(): void
    {
        $html = renderInspectRosterHtml($this->vm([$this->row(['id' => 5, 'status' => 'submitted'], [$this->link(1, 'Jordan Lee')])], ['filter' => 'needs_tech']));
        $this->assertStringContainsString('action=gear-create-accept', $html);
        $this->assertStringContainsString('name="sheet_id" value="5"', $html);
        $this->assertStringContainsString('name="back" value="roster"', $html);
        $this->assertStringContainsString('name="filter" value="needs_tech"', $html);
    }

    public function testACarWithoutASheetSaysSoAndHasNoOneTapButton(): void
    {
        $html = renderInspectRosterHtml($this->vm([$this->row(null, [$this->link(1, 'Casey Moss')])]));
        $this->assertStringContainsString('No sheet yet', $html);
        $this->assertStringContainsString('Casey Moss', $html);
        $this->assertStringNotContainsString('gear-create-accept', $html);
    }

    public function testEmptyStates(): void
    {
        $this->assertStringContainsString('No events yet.', renderInspectRosterHtml($this->vm([], ['events' => []])));
        $this->assertStringContainsString('No cars are tagged for this event and no tech sheets are in yet.', renderInspectRosterHtml($this->vm([])));
        $one = [$this->row(null, [])];
        $this->assertStringContainsString('No cars match this filter.', renderInspectRosterHtml($this->vm([], ['counts' => inspectRosterCounts($one)])));
        $this->assertStringContainsString('No drivers', renderInspectRosterHtml($this->vm($one)));
    }
}
```

`tests/InspectSourceTest.php`:

```php
<?php
// wcma-calculator/tests/InspectSourceTest.php
//
// Source-level guards for inspect.php and admin.php (they need config.php, so they cannot run under PHPUnit).
use PHPUnit\Framework\TestCase;

final class InspectSourceTest extends TestCase
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

    /** @return string[] the case labels of inspect.php's router */
    private function routes(): array {
        preg_match_all("/^    case '([a-z-]+)':/m", $this->src('inspect.php'), $m);
        return $m[1];
    }

    private function assertRoutesMoved(array $routes, array $postRoutes): void {
        $admin = $this->src('admin.php');
        foreach ($routes as $r) {
            $this->assertContains($r, $this->routes(), $r);
            $this->assertStringNotContainsString("case '$r':", $admin, $r);
        }
        foreach ($postRoutes as $r) {
            $this->assertContains($r, INSPECT_POST_ACTIONS, $r);
        }
    }

    public function testEveryRequestIsRoleGatedAndPostActionsAreGatedBeforeTheRouter(): void
    {
        $src = $this->src('inspect.php');
        $gate = strpos($src, 'require_role(inspectActionMinRole($action));');
        $post = strpos($src, "if (in_array(\$action, INSPECT_POST_ACTIONS, true)) {\n    if (\$_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: inspect.php'); exit; }\n    if (!validateCsrfToken(\$_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }\n}");
        $router = strpos($src, 'switch ($action) {');
        $this->assertNotFalse($gate);
        $this->assertNotFalse($post);
        $this->assertNotFalse($router);
        $this->assertLessThan($post, $gate);
        $this->assertLessThan($router, $post);
    }

    public function testTechSheetRoutesMovedFromAdmin(): void
    {
        $this->assertRoutesMoved(
            ['tech-sheet', 'tech-sheet-sig', 'tech-sheet-accept', 'tech-sheet-revoke', 'tech-sheet-photos-accept', 'tech-sheet-photos-send-back'],
            ['tech-sheet-accept', 'tech-sheet-revoke', 'tech-sheet-photos-accept', 'tech-sheet-photos-send-back']
        );
        $this->assertStringNotContainsString("case 'tech-sheets':", $this->src('admin.php'));
    }

    public function testAdminRedirectsMovedActionsBeforeRouting(): void
    {
        $admin = $this->src('admin.php');
        $this->assertStringContainsString("\$movedTo = adminMovedActionUrl(is_string(\$action) ? \$action : '', \$_GET);\nif (\$movedTo !== null) { header('Location: ' . \$movedTo); exit; }", $admin);
        $this->assertLessThan(strpos($admin, 'switch ($action)'), strpos($admin, '$movedTo = adminMovedActionUrl('));
    }

    public function testTechSheetReviewPageLivesInTheInspectorSection(): void
    {
        $src = $this->src('admin-tech-sheets.php');
        $this->assertStringNotContainsString('admin.php', $src);
        $this->assertStringNotContainsString('renderSiteHeader(', $src);
        $this->assertStringNotContainsString('function handleTechSheetsList(', $src);
        $this->assertStringContainsString("renderPageStart('Tech Sheet #' . \$id, 'inspect'", $src);
        $this->assertStringContainsString("inspectSubnavHtml('roster')", $this->body('inspect.php', 'inspectShowRoster'));
    }
}
```

In `tests/RolesTest.php`, add:

```php
    public function testMovedAdminActionsRedirectToTheInspectorSection(): void
    {
        $this->assertSame('inspect.php?action=tech-sheet&id=12', adminMovedActionUrl('tech-sheet', ['action' => 'tech-sheet', 'id' => '12', 'x' => 'y']));
        $this->assertSame('inspect.php?action=roster&event=3', adminMovedActionUrl('tech-sheets', ['event' => '3', 'filter' => 'needs_tech']));
        $this->assertSame('inspect.php?action=roster', adminMovedActionUrl('tech-sheets', ['event' => ['x']]));
        $this->assertSame('inspect.php?action=tech-sheet-sig&id=4&which=tech', adminMovedActionUrl('tech-sheet-sig', ['id' => '4', 'which' => 'tech']));
        $this->assertNull(adminMovedActionUrl('users', []));
        $this->assertNull(adminMovedActionUrl('tech-sheet-accept', ['id' => '4']));   // POSTs are not redirected
    }
```

Update tests that pointed at the old roster and old URLs:
- `tests/PretechEmailTest.php`: the club review link now points at `inspect.php`. Run:
  `sed -i "s/admin\.php?action=tech-sheet/inspect.php?action=tech-sheet/g; s/'admin\.php'/'inspect.php'/g" tests/PretechEmailTest.php`
- `tests/GearCreateAcceptSourceTest.php`: delete the method `testRosterPassesATokenAndOptionsToTheAdminChips()`. `InspectPageTest::testADriverWithoutGearOnASheetGetsTheOneTapButton` now covers that behaviour.
- `tests/GearLinksSourceTest.php`: delete the method `testAdminRosterAttachesGearAndRendersAGearColumn()`. `InspectLibTest::testRowsCarryTheEventSheetClassCarTechAndGear` and `InspectPageTest` now cover it.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter 'InspectPageTest|InspectSourceTest|RolesTest|PretechEmailTest'`
Expected: FAIL or ERROR. `inspect-page.php` and `inspect.php` are missing, `adminMovedActionUrl()` is undefined, and PretechEmailTest expects `inspect.php` links.

- [ ] **Step 3: Add the moved-action map to `roles.php`**

After `inspectActionMinRole()`:

```php
/**
 * admin.php GET actions that moved to inspect.php in Phase 4: action => [inspect.php action, query keys
 * carried over]. Emails already sent and bookmarks use the old URLs, so admin.php redirects them.
 */
const ADMIN_MOVED_ACTIONS = [
    'tech-sheets' => ['roster', ['event']],
    'tech-sheet' => ['tech-sheet', ['id']],
    'tech-sheet-sig' => ['tech-sheet-sig', ['id', 'which']],
];

/** The inspect.php URL for a moved admin.php action, or null when $action has not moved. */
function adminMovedActionUrl(string $action, array $query): ?string {
    if (!isset(ADMIN_MOVED_ACTIONS[$action])) return null;
    [$to, $keys] = ADMIN_MOVED_ACTIONS[$action];
    $params = ['action' => $to];
    foreach ($keys as $key) {
        if (isset($query[$key]) && is_scalar($query[$key]) && (string)$query[$key] !== '') $params[$key] = (string)$query[$key];
    }
    return 'inspect.php?' . http_build_query($params);
}
```

- [ ] **Step 4: Create `inspect-page.php` (roster only for now)**

```php
<?php
// wcma-calculator/inspect-page.php
//
// Markup for the Inspector section (spec §5): the event roster, the review queue, the Classing list
// and the declaration page. Pure view functions: no DB, no session, no echo. Callers must have
// loaded view_helpers.php (h()), cars-lib.php (declarationReviewLabel()), home-page.php
// (homeStatusClass()), garage-page.php (garageClassHtml(), garageCarTitle()), gear-chips.php
// (renderGearChips()), tech-status.php, declaration-review-lib.php and inspect-lib.php.

function inspectCsrfField(string $csrf): string {
    return '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">';
}

/**
 * The Event roster tab: an event picker and filter, then one card per car.
 *
 * @param array{events: array, eventId: int, filter: string, rows: array, counts: array<string,int>, season: int, csrf: string} $vm
 */
function renderInspectRosterHtml(array $vm): string {
    $out = '<h1 class="hub-page-title">Event roster</h1>';
    if (!$vm['events']) {
        return $out . '<p class="hub-card">No events yet. An admin adds events under Admin, Events.</p>';
    }
    $out .= '<form method="get" action="inspect.php" class="hub-card inspect-filters">'
        . '<label for="roster-event">Event</label><select id="roster-event" name="event">';
    foreach ($vm['events'] as $e) {
        $out .= '<option value="' . (int)$e['id'] . '"' . ((int)$e['id'] === $vm['eventId'] ? ' selected' : '') . '>'
            . h((string)$e['name']) . ' (' . h(date('M j, Y', strtotime((string)$e['event_date']))) . ')</option>';
    }
    $out .= '</select><label for="roster-filter">Show</label><select id="roster-filter" name="filter">';
    foreach (INSPECT_ROSTER_FILTERS as $key => $label) {
        $out .= '<option value="' . h($key) . '"' . ($key === $vm['filter'] ? ' selected' : '') . '>'
            . h($label) . ' (' . (int)($vm['counts'][$key] ?? 0) . ')</option>';
    }
    $out .= '</select><button type="submit" class="hub-btn">Show</button></form>';

    if (!$vm['rows']) {
        $empty = (int)($vm['counts']['all'] ?? 0) === 0
            ? 'No cars are tagged for this event and no tech sheets are in yet.'
            : 'No cars match this filter.';
        return $out . '<p class="hub-card">' . $empty . '</p>';
    }
    foreach ($vm['rows'] as $row) {
        $out .= inspectRosterRowHtml($row, $vm);
    }
    return $out;
}

/** One car on the roster: plate, car and owner, then class, tech sheet, car tech and gear. */
function inspectRosterRowHtml(array $row, array $vm): string {
    $car = $row['car'];
    $sheet = $row['sheet'];
    $current = $row['class']['current'];
    $classLink = $current === null ? '' : ' <a href="inspect.php?action=declaration&amp;id=' . (int)$current['id'] . '">'
        . ($current['review_status'] === 'submitted' ? 'Review' : 'View') . '</a>';
    $sheetCell = $sheet === null
        ? '<span class="hub-status hub-status--todo">No sheet yet</span>'
        : '<span class="hub-status ' . ($sheet['status'] === 'teched' ? 'hub-status--ok">Accepted' : 'hub-status--info">Submitted') . '</span>'
            . ' <a href="inspect.php?action=tech-sheet&amp;id=' . (int)$sheet['id'] . '">' . ($sheet['status'] === 'teched' ? 'View' : 'Review') . '</a>';
    $chips = renderGearChips($row['gear_links'], 'admin', [
        'sheet_season' => $vm['season'], 'csrf' => $vm['csrf'], 'sheet_id' => $sheet !== null ? (int)$sheet['id'] : 0,
        'hidden' => ['back' => 'roster', 'filter' => $vm['filter']],
    ]);

    return '<article class="hub-card inspect-row">'
        . '<div class="inspect-row-head"><span class="hub-plate">' . h((string)$car['car_number']) . '</span>'
        . '<div><h2>' . h(garageCarTitle($car)) . '</h2><p class="inspect-row-sub">' . h((string)$car['owner_name'])
        . (empty($car['tagged']) ? ' · has a sheet, not tagged' : '') . '</p></div></div>'
        . '<dl class="inspect-facts">'
        . '<div><dt>Class</dt><dd>' . garageClassHtml($row['class']) . $classLink . '</dd></div>'
        . '<div><dt>Tech sheet</dt><dd>' . $sheetCell . '</dd></div>'
        . '<div><dt>Car tech</dt><dd><span class="hub-status ' . h(homeStatusClass($row['status']['state'])) . '">'
        . h(techCarStatusLabel($row['status'], $vm['season'])) . '</span></dd></div>'
        . '<div><dt>Gear</dt><dd>' . ($chips !== '' ? $chips : 'No drivers') . '</dd></div>'
        . '</dl></article>';
}
```

- [ ] **Step 5: Create `inspect.php`**

```php
<?php
// wcma-calculator/inspect.php — the Inspector section (spec §5): Event roster, Review queue, Classing
// and Gear, for inspectors and admins. Every request is gated here: require_role(inspectActionMinRole())
// (roles.php names the few admin-only actions), and INSPECT_POST_ACTIONS are POST-only and
// CSRF-checked before the router. View models live in inspect-lib.php and markup in inspect-page.php;
// the tech sheet and gear review pages keep their own files (admin-tech-sheets.php, admin-gear.php).
require __DIR__ . '/session_bootstrap.php';
date_default_timezone_set('America/Denver');

require __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/feedback-lib.php';          // feedbackBaseUrl()
require __DIR__ . '/cars-lib.php';
require __DIR__ . '/events-lib.php';
require __DIR__ . '/tech-sheet-files.php';
require __DIR__ . '/tech-review-lib.php';
require __DIR__ . '/photo-requirements.php';
require __DIR__ . '/inspection-lib.php';
require __DIR__ . '/pretech-lib.php';
require __DIR__ . '/pretech-email.php';
require __DIR__ . '/gear-lib.php';
require __DIR__ . '/gear-email.php';
require __DIR__ . '/gear-chips.php';
require __DIR__ . '/tech-sheet-render.php';
require __DIR__ . '/garage-lib.php';
require __DIR__ . '/home-page.php';
require __DIR__ . '/garage-page.php';
require __DIR__ . '/declaration-review-lib.php';
require __DIR__ . '/inspect-lib.php';
require __DIR__ . '/inspect-page.php';
require __DIR__ . '/admin-tech-sheets.php';
require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';
require __DIR__ . '/email-helpers.php';

$pdo = db_connect();
db_init($pdo);

define('TECH_EMAIL', db_get_setting($pdo, 'tech_sheet_recipient_email', config_default('TECH_SHEET_RECIPIENT_EMAIL', 'classing@wcma.ca')));
define('TECH_NAME',  db_get_setting($pdo, 'tech_sheet_recipient_name', config_default('TECH_SHEET_RECIPIENT_NAME', 'WCMA Classing')));

$action = is_string($_GET['action'] ?? null) && $_GET['action'] !== '' ? $_GET['action'] : 'roster';
require_role(inspectActionMinRole($action));
if (in_array($action, INSPECT_POST_ACTIONS, true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: inspect.php'); exit; }
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
}
$getId = is_scalar($_GET['id'] ?? null) ? (int)$_GET['id'] : 0;
$postId = is_scalar($_POST['id'] ?? null) ? (int)$_POST['id'] : 0;

switch ($action) {
    case 'tech-sheet':
        handleTechSheetView($pdo, $getId);
        break;
    case 'tech-sheet-sig':
        handleTechSheetSig($pdo, $getId, is_string($_GET['which'] ?? null) ? $_GET['which'] : '');
        break;
    case 'tech-sheet-accept':
        handleTechSheetAccept($pdo, $postId);
        break;
    case 'tech-sheet-revoke':
        handleTechSheetRevoke($pdo, $postId);
        break;
    case 'tech-sheet-photos-accept':
        handleTechSheetPhotosAccept($pdo, $postId);
        break;
    case 'tech-sheet-photos-send-back':
        handleTechSheetPhotosSendBack($pdo, $postId);
        break;
    default:
        inspectShowRoster($pdo);
}

function inspectShowRoster(PDO $pdo): void {
    $events = db_get_all_events($pdo);
    $eventId = is_scalar($_GET['event'] ?? null) ? (int)$_GET['event'] : 0;
    if (!in_array($eventId, array_map(fn(array $e): int => (int)$e['id'], $events), true)) {
        $eventId = techDefaultEventId($events, date('Y-m-d'));
    }
    $filter = is_string($_GET['filter'] ?? null) && isset(INSPECT_ROSTER_FILTERS[$_GET['filter']]) ? $_GET['filter'] : 'all';
    $event = $eventId > 0 ? db_get_event($pdo, $eventId) : null;
    $season = techSeasonFromDate($event['event_date'] ?? null);

    $rows = [];
    if ($event !== null) {
        $cars = db_get_event_roster_cars($pdo, $eventId);
        $eventSheets = db_get_event_tech_sheets($pdo, $eventId);
        $rows = inspectRosterRows(
            $cars, $eventSheets, db_get_season_sheets($pdo, $season),
            db_get_declarations_for_cars($pdo, array_map(fn(array $c): int => (int)$c['id'], $cars)),
            db_get_drivers_for_sheets($pdo, array_map(fn(array $s): int => (int)$s['id'], $eventSheets)),
            db_get_self_drivers_for_users($pdo, array_map(fn(array $c): int => (int)$c['owner_user_id'], $cars)),
            db_get_gear_records_for_season($pdo, $season),
            $season
        );
    }

    renderPageStart('Event roster', 'inspect', ['flash' => getFlash(), 'subnav' => inspectSubnavHtml('roster')]);
    echo renderInspectRosterHtml([
        'events' => $events, 'eventId' => $eventId, 'filter' => $filter,
        'rows' => inspectRosterFilter($rows, $filter), 'counts' => inspectRosterCounts($rows),
        'season' => $season, 'csrf' => generateCsrfToken(),
    ]);
    renderPageEnd(['scripts' => '<script src="js/form-feedback.js"></script>']);
}
```

Functions declared at the top level of the file are hoisted, the same as in `admin.php`, so the router can call functions declared below it.

- [ ] **Step 6: Move the tech sheet review page to `inspect.php`**

In `admin-tech-sheets.php`:

1. Replace the header comment with:
   ```php
   // wcma-calculator/admin-tech-sheets.php
   //
   // Inspector section: the tech sheet review page (accept in person, photo pre-tech review) and its
   // POST handlers. Included by inspect.php, which provides the role gate, the POST/CSRF checks and
   // the router. (The file keeps its old name; the event roster that used to live here is in
   // inspect-page.php.)
   ```
2. Delete `handleTechSheetsList()` and `renderTechSheetsListPage()` completely. Keep `TECH_SHEET_FILTERS` for now: `handleGearCreateAccept()` still reads it until Task 6.
3. Point every link, form and redirect at `inspect.php`:
   `sed -i 's/admin\.php?action=/inspect.php?action=/g' admin-tech-sheets.php`
4. In `handleTechSheetView()`, change the not-found redirect `header('Location: inspect.php?action=tech-sheets');` to `header('Location: inspect.php');`.
5. In `renderTechSheetViewPage()`, replace everything from `    ?><!DOCTYPE html>` down to and including the flash line
   ```php
     <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
   ```
   with:
   ```php
       renderPageStart('Tech Sheet #' . $id, 'inspect', ['flash' => $flash, 'subnav' => inspectSubnavHtml('roster')]);
       ?>
   <p><a href="inspect.php?event=<?= (int)$sheet['event_id'] ?>">&larr; Back to the roster</a></p>
   <h1 class="hub-page-title">Tech Sheet #<?= $id ?></h1>
   ```
6. Replace the end of the same function:
   ```php
     <?= renderTechSheetHtml($sheet, $drivers, $event, adminTechSheetSigResolver($id), 'assets/wcma-logo.png') ?>
   </div>
   <script src="js/confirm-modal.js"></script>
   <script src="js/form-feedback.js"></script>
   <script src="js/signature-pad.js"></script>
   <script src="js/admin-tech-review.js"></script>
   <?php renderSiteFooter(); ?>
   </body>
   </html><?php
   }
   ```
   with:
   ```php
     <?= renderTechSheetHtml($sheet, $drivers, $event, adminTechSheetSigResolver($id), 'assets/wcma-logo.png') ?>
   <?php
       renderPageEnd(['scripts' => '<script src="js/confirm-modal.js"></script><script src="js/form-feedback.js"></script>'
           . '<script src="js/signature-pad.js"></script><script src="js/admin-tech-review.js"></script>']);
   }
   ```

In `js/admin-tech-review.js`, change the second comment line to `// Signature pad for the in-person tech review form (inspect.php?action=tech-sheet).`

In `pretech-email.php`, `pretechNotify()`, change:
```php
        $adminUrl = $base . '/admin.php?action=tech-sheet&id=' . $id;
```
to:
```php
        $adminUrl = $base . '/inspect.php?action=tech-sheet&id=' . $id;
```

- [ ] **Step 7: Redirect the moved actions in `admin.php`**

In `admin.php`, directly after `$action = $_GET['action'] ?? 'list';`, insert:

```php
$movedTo = adminMovedActionUrl(is_string($action) ? $action : '', $_GET);
if ($movedTo !== null) { header('Location: ' . $movedTo); exit; }
```

Then delete these `case` blocks from the router: `tech-sheets`, `tech-sheet`, `tech-sheet-accept`, `tech-sheet-revoke`, `tech-sheet-sig`, `tech-sheet-photos-accept` and `tech-sheet-photos-send-back`. Leave the `require __DIR__ . '/admin-tech-sheets.php';` line alone until Task 9, because `admin-gear.php` still reads `TECH_SHEET_FILTERS` from it until Task 6.

- [ ] **Step 8: Roster styles**

In `css/hub.css`, before the `/* Phone */` block:

```css
/* Inspector */
.inspect-filters { display: flex; flex-wrap: wrap; gap: 8px 14px; align-items: center; padding: 14px 20px; }
.inspect-filters select, .inspect-filters input { min-height: var(--hub-tap); font-size: 18px; }
.inspect-row { padding: 16px 20px; margin-bottom: 14px; }
.inspect-row-head { display: flex; gap: 14px; align-items: center; }
.inspect-row-head h2 { margin: 0; font-size: 22px; }
.inspect-row-sub { margin: 2px 0 0; color: var(--hub-ink-2); }
.inspect-facts { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px 18px; margin: 12px 0 0; }
.inspect-facts dt { font-size: 16px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--hub-ink-2); }
.inspect-facts dd { margin: 4px 0 0; }
.inspect-muted { color: var(--hub-ink-2); font-size: 16px; }
.inspect-num { text-align: right; font-variant-numeric: tabular-nums; }
```

Inside the `@media screen and (max-width: 700px)` block:

```css
  .inspect-facts { grid-template-columns: 1fr; }
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `php phpunit.phar`, then `php -l inspect.php && php -l inspect-page.php && php -l admin-tech-sheets.php && php -l admin.php`.
Expected: OK, and "No syntax errors detected" four times.

- [ ] **Step 10: Commit**

```bash
git add inspect.php inspect-page.php admin-tech-sheets.php admin.php roles.php pretech-email.php js/admin-tech-review.js css/hub.css tests/InspectPageTest.php tests/InspectSourceTest.php tests/RolesTest.php tests/PretechEmailTest.php tests/GearCreateAcceptSourceTest.php tests/GearLinksSourceTest.php
git commit -m "feat(hub): inspect.php with the event roster; tech sheet review moves from admin.php"
```

---
### Task 6: Gear moves to the Inspector section

**Files:**
- Modify: `admin-gear.php`. Its URLs point at `inspect.php`, the list and review pages move to `renderPageStart()`, and the one-tap handler redirects to the new roster.
- Modify: `admin-tech-sheets.php`. Delete `TECH_SHEET_FILTERS`.
- Modify: `gear-chips.php`, `gear-email.php`, `inspect.php`, `admin.php`, `roles.php`
- Test: modify `tests/InspectSourceTest.php`, `tests/RolesTest.php`, `tests/GearChipsTest.php`, `tests/GearChipsActionsTest.php`, `tests/GearEmailTest.php`, `tests/AdminGearCopyTest.php` and `tests/GearCreateAcceptSourceTest.php`

**Interfaces:**
- Consumes: `INSPECT_ROSTER_FILTERS` (Task 3), `inspectSubnavHtml()` (Task 4), the router and gate in `inspect.php` (Task 5)
- Produces:
  - `inspect.php` actions: `gear` (`season` and `filter` query params), `gear-record`, `gear-record-accept`, `gear-record-revoke`, `gear-photos-accept`, `gear-photos-send-back`, `gear-create-accept`
  - `ADMIN_MOVED_ACTIONS` gains `gear` and `gear-record`

- [ ] **Step 1: Write the failing tests**

In `tests/InspectSourceTest.php`, add:

```php
    public function testGearRoutesMovedFromAdmin(): void
    {
        $this->assertRoutesMoved(
            ['gear', 'gear-record', 'gear-record-accept', 'gear-record-revoke', 'gear-photos-accept', 'gear-photos-send-back', 'gear-create-accept'],
            ['gear-record-accept', 'gear-record-revoke', 'gear-photos-accept', 'gear-photos-send-back', 'gear-create-accept']
        );
        $src = $this->src('admin-gear.php');
        $this->assertStringNotContainsString('admin.php', $src);
        $this->assertStringNotContainsString('renderSiteHeader(', $src);
        $this->assertStringContainsString("renderPageStart('Gear', 'inspect'", $src);
        $this->assertStringContainsString("renderPageStart('Gear #' . \$id, 'inspect'", $src);
        $this->assertStringNotContainsString('admin.php', $this->src('gear-chips.php'));
        $this->assertStringNotContainsString('TECH_SHEET_FILTERS', $this->src('admin-tech-sheets.php'));
    }
```

In `tests/RolesTest.php`, add:

```php
    public function testMovedGearActionsRedirect(): void
    {
        $this->assertSame('inspect.php?action=gear&season=2026&filter=accepted', adminMovedActionUrl('gear', ['season' => '2026', 'filter' => 'accepted']));
        $this->assertSame('inspect.php?action=gear-record&id=9', adminMovedActionUrl('gear-record', ['id' => '9']));
        $this->assertNull(adminMovedActionUrl('gear-create-accept', []));
    }
```

In `tests/AdminGearCopyTest.php`, replace `testEveryGearRouteIsAdminOnlyAndPostRoutesCheckCsrf()` with:

```php
    public function testGearRoutesLiveInTheInspectorSectionAndPostRoutesAreGated(): void
    {
        $inspect = $this->src('inspect.php');
        $admin = $this->src('admin.php');
        foreach (['gear', 'gear-record', 'gear-record-accept', 'gear-record-revoke', 'gear-photos-accept', 'gear-photos-send-back'] as $route) {
            $this->assertStringContainsString("case '$route':", $inspect, $route);
            $this->assertStringNotContainsString("case '$route':", $admin, $route);
        }
        foreach (['gear-record-accept', 'gear-record-revoke', 'gear-photos-accept', 'gear-photos-send-back'] as $route) {
            $this->assertContains($route, INSPECT_POST_ACTIONS, $route);
        }
    }
```

In `tests/GearCreateAcceptSourceTest.php`:
1. Replace `testRouteIsAdminOnlyPostOnlyAndCsrfChecked()` with:
   ```php
       public function testRouteIsPostOnlyAndCsrfCheckedInTheInspectorSection(): void
       {
           $this->assertContains('gear-create-accept', INSPECT_POST_ACTIONS);
           $this->assertSame('inspector', inspectActionMinRole('gear-create-accept'));
           $this->assertStringContainsString("case 'gear-create-accept':", $this->src('inspect.php'));
           $this->assertStringNotContainsString("case 'gear-create-accept':", $this->src('admin.php'));
       }
   ```
2. In `testHandlerReadsTheNameFromTheSheetNotFromTheRequest()`, change the last assertion to:
   ```php
           $this->assertStringContainsString('INSPECT_ROSTER_FILTERS[$_POST[\'filter\']]', $handler);
   ```

Point the chip and email tests at the new URLs:

```bash
sed -i 's/admin\.php?action=gear/inspect.php?action=gear/g' tests/GearChipsTest.php tests/GearChipsActionsTest.php
sed -i "s/admin\.php?action=gear-record/inspect.php?action=gear-record/g; s/'admin\.php'/'inspect.php'/g" tests/GearEmailTest.php
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter 'InspectSourceTest|RolesTest|AdminGearCopyTest|GearCreateAcceptSourceTest|GearChips|GearEmailTest'`
Expected: FAIL. The gear routes are still in `admin.php`, and the chips and emails still link to `admin.php`.

- [ ] **Step 3: Move the gear pages**

In `admin-gear.php`:

1. Replace the header comment with:
   ```php
   // wcma-calculator/admin-gear.php
   //
   // Inspector section, Gear tab: driver gear records for a season, and the review page (accept in
   // person, accept photos remotely, send photos back), plus the roster's one-tap "create and accept".
   // Included by inspect.php, which provides the role gate, the POST/CSRF checks and the router.
   ```
2. Point every URL at `inspect.php`: `sed -i 's/admin\.php?action=/inspect.php?action=/g' admin-gear.php`
3. In `renderGearAdminListPage()`, replace everything from `    ?><!DOCTYPE html>` down to and including
   ```php
     <form method="get" action="admin.php" class="detail-card" style="margin-bottom:1rem">
   ```
   with:
   ```php
       renderPageStart('Gear', 'inspect', ['flash' => $flash, 'subnav' => inspectSubnavHtml('gear')]);
       ?>
   <h1 class="hub-page-title">Gear</h1>

     <form method="get" action="inspect.php" class="hub-card inspect-filters">
   ```
   In the same form, change `<p class="form-hint" style="margin-top:.5rem">` to `<p class="form-hint">`. Then replace the end of the function:
   ```php
     </table>
   </div>
   <?php renderSiteFooter(); ?>
   </body>
   </html><?php
   }
   ```
   with:
   ```php
     </table>
   <?php
       renderPageEnd();
   }
   ```
4. In `renderGearAdminViewPage()`, replace everything from `    ?><!DOCTYPE html>` down to and including the flash line
   ```php
     <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
   ```
   with:
   ```php
       renderPageStart('Gear #' . $id, 'inspect', ['flash' => $flash, 'subnav' => inspectSubnavHtml('gear')]);
       ?>
   <p><a href="inspect.php?action=gear&amp;season=<?= (int)$gear['season'] ?>">&larr; Back to the gear list</a></p>
   <h1 class="hub-page-title">Gear #<?= $id ?></h1>
   ```
   Then replace its end:
   ```php
     <?php renderGearReviewCard($gear, $snapshot, $csrf); ?>
   </div>
   <script src="js/confirm-modal.js"></script>
   <script src="js/form-feedback.js"></script>
   <?php renderSiteFooter(); ?>
   </body>
   </html><?php
   }
   ```
   with:
   ```php
     <?php renderGearReviewCard($gear, $snapshot, $csrf); ?>
   <?php
       renderPageEnd(['scripts' => '<script src="js/confirm-modal.js"></script><script src="js/form-feedback.js"></script>']);
   }
   ```
5. In `handleGearCreateAccept()`, after the sed the not-found redirect reads `header('Location: inspect.php?action=tech-sheets');`. Change it to `header('Location: inspect.php');`. Then replace the final redirect block:
   ```php
       if (($_POST['back'] ?? '') === 'sheet') {
           header('Location: inspect.php?action=tech-sheet&id=' . $sheetId);
       } else {
           $filter = is_string($_POST['filter'] ?? null) && isset(TECH_SHEET_FILTERS[$_POST['filter']]) ? $_POST['filter'] : 'all';
           header('Location: inspect.php?action=tech-sheets&event=' . (int)$sheet['event_id'] . '&filter=' . rawurlencode($filter));
       }
       exit;
   ```
   with:
   ```php
       if (($_POST['back'] ?? '') === 'sheet') {
           header('Location: inspect.php?action=tech-sheet&id=' . $sheetId);
       } else {
           $filter = is_string($_POST['filter'] ?? null) && isset(INSPECT_ROSTER_FILTERS[$_POST['filter']]) ? $_POST['filter'] : 'all';
           header('Location: inspect.php?event=' . (int)$sheet['event_id'] . '&filter=' . rawurlencode($filter));
       }
       exit;
   ```
6. Run `grep -n "admin\.php\|tech-sheets" admin-gear.php`. Expected: no output.

In `admin-tech-sheets.php`, delete the `TECH_SHEET_FILTERS` constant. Nothing reads it any more.

In `gear-chips.php`, run `sed -i 's/admin\.php/inspect.php/g' gear-chips.php`. That updates the one-tap form action, the inspector chip link and the comment.

In `gear-email.php`, `gearNotify()`, change:
```php
        $adminUrl = $base . '/admin.php?action=gear-record&id=' . $id;
```
to:
```php
        $adminUrl = $base . '/inspect.php?action=gear-record&id=' . $id;
```

- [ ] **Step 4: Route gear through `inspect.php`**

In `inspect.php`, add `require __DIR__ . '/admin-gear.php';` directly after `require __DIR__ . '/admin-tech-sheets.php';`. Then add these cases to the router, before `default:`:

```php
    case 'gear':
        handleGearAdminList($pdo);
        break;
    case 'gear-record':
        handleGearAdminView($pdo, $getId);
        break;
    case 'gear-record-accept':
        handleGearAdminAcceptInPerson($pdo, $postId);
        break;
    case 'gear-record-revoke':
        handleGearAdminRevoke($pdo, $postId);
        break;
    case 'gear-photos-accept':
        handleGearAdminPhotosAccept($pdo, $postId);
        break;
    case 'gear-photos-send-back':
        handleGearAdminPhotosSendBack($pdo, $postId);
        break;
    case 'gear-create-accept':
        handleGearCreateAccept($pdo);
        break;
```

In `admin.php`, delete the `case` blocks `gear`, `gear-record`, `gear-record-accept`, `gear-record-revoke`, `gear-photos-accept`, `gear-photos-send-back` and `gear-create-accept`.

In `roles.php`, add two entries to `ADMIN_MOVED_ACTIONS`:

```php
    'gear' => ['gear', ['season', 'filter']],
    'gear-record' => ['gear-record', ['id']],
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php phpunit.phar`, then `php -l admin-gear.php && php -l inspect.php && php -l admin.php`.
Expected: OK, with no syntax errors.

- [ ] **Step 6: Commit**

```bash
git add admin-gear.php admin-tech-sheets.php gear-chips.php gear-email.php inspect.php admin.php roles.php tests/InspectSourceTest.php tests/RolesTest.php tests/AdminGearCopyTest.php tests/GearCreateAcceptSourceTest.php tests/GearChipsTest.php tests/GearChipsActionsTest.php tests/GearEmailTest.php
git commit -m "feat(hub): gear review moves to the Inspector section"
```

---

### Task 7: The Classing tab and the declaration review page

**Files:**
- Modify: `inspect-page.php`. Add the Classing list and the declaration page.
- Modify: `inspect.php`. Add the Classing, declaration, file, export, accept, send-back, resend, contact and delete handlers.
- Modify: `admin.php`. Remove the submissions list, the detail page and their handlers. The default action becomes `users`.
- Modify: `roles.php`. Add `ADMIN_MOVED_ACTIONS` entries for `list`, `view`, `file` and `export`.
- Test: modify `tests/InspectPageTest.php`, `tests/InspectSourceTest.php` and `tests/RolesTest.php`. Rename `tests/AdminDeleteGuardTest.php` to `tests/DeclarationDeleteGuardTest.php` and rewrite it.

**Interfaces:**
- Consumes:
  - Task 1: `declarationReviewAllowed()`, `declarationReviewAccept()`, `declarationReviewSendBack()`, `declarationNotify()`, `DECLARATION_NOTE_MAX`
  - Task 2: `db_search_declarations()`
  - Task 3: `inspectClassingFilters()`, `inspectClassingQuery()`, `INSPECT_CLASSES`, `INSPECT_DECLARATION_STATUSES`
  - existing: `db_get_car_declarations()`, `db_restore_current_declaration()`, `renderSubmissionEmailHtml()` / `renderSubmissionEmailText()` (submission-email-render.php), `emailSmtpSend()`, `emailLogoSrc()`
- Produces:
  - `inspect.php` actions:
    - GET: `classing` (`q`, `class`, `season`, `status`, `car`, `page`), `declaration` (`id`), `declaration-file` (`id`, `field`), `declarations-export`
    - POST, open to inspectors: `declaration-accept`, `declaration-send-back`, `declaration-resend`
    - POST, admin only: `declaration-update-contact`, `declaration-delete`, `declarations-bulk-delete`
  - `renderInspectClassingHtml(array $vm): string`, with `$vm` = `{filters, rows, total: int, pages: int, isAdmin: bool, csrf: string}`
  - `renderInspectDeclarationHtml(array $vm): string`, with `$vm` = `{sub, car: ?array, owner: ?array, reviewer: ?array, history: array, isAdmin: bool, csrf: string}`

The resend now sends the classing copy to the **classing** recipient (Admin → Settings, "Class Calculator"). The old `admin.php` resend used the tech sheet recipient, which was a bug.

- [ ] **Step 1: Write the failing tests**

In `tests/InspectPageTest.php`, add:

```php
    private function decl(array $o = []): array {
        return array_merge([
            'id' => 7, 'car_id' => 3, 'user_id' => 1, 'name' => 'Jordan <Lee>', 'email' => 'jordan@example.com',
            'year' => '2004', 'make' => 'Honda', 'model' => 'S2000', 'comments' => null, 'calculated_class' => 'GT3',
            'competition_weight' => 2860, 'declared_hp' => 240, 'dyno_hp' => null, 'base_ratio' => 11.92, 'modified_ratio' => 12.42,
            'weight_factor' => 0, 'chassis_display' => null, 'chassis_value' => 0, 'body_mods_display' => null, 'body_mods_value' => 0,
            'transmission_display' => null, 'transmission_value' => 0, 'drivetrain_display' => null, 'drivetrain_value' => 0,
            'tires_display' => 'R-compound', 'tires_value' => 0.5, 'brake_suspension' => '[]', 'brake_suspension_value' => 0,
            'submitted_at' => '2026-03-03 10:00:00', 'review_status' => 'submitted', 'reviewer_note' => null,
            'reviewed_by_user_id' => null, 'reviewed_at' => null, 'accepted_at' => null,
            'car_image_path' => 'uploads/7/car.jpg', 'dyno_chart_path' => null, 'dyno_table_path' => 'uploads/7/dyno.pdf',
            'email_sent' => 1, 'email_send_count' => 1, 'last_emailed_at' => '2026-03-03 10:01:00', 'car_number' => '42',
        ], $o);
    }

    private function declVm(array $o = [], array $vm = []): array {
        $s = $this->decl($o);
        return array_merge([
            'sub' => $s,
            'car' => ['id' => 3, 'car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000', 'colour' => 'Silver'],
            'owner' => ['name' => 'Jordan Lee', 'email' => 'jordan@example.com'], 'reviewer' => null,
            'history' => [$s, $this->decl(['id' => 5, 'review_status' => 'superseded', 'calculated_class' => 'GT2'])],
            'isAdmin' => false, 'csrf' => 'tok',
        ], $vm);
    }

    public function testASubmittedDeclarationOffersAcceptAndSendBack(): void
    {
        $html = renderInspectDeclarationHtml($this->declVm());
        foreach (['action="inspect.php?action=declaration-accept"', 'action="inspect.php?action=declaration-send-back"',
                  '<textarea id="declaration-note" name="note" rows="4" maxlength="1000" required>', 'With an inspector',
                  'Jordan &lt;Lee&gt;', '#42 2004 Honda S2000', 'GT3 (10.00 – 11.99)', 'R-compound', '+0.50', '12.42',
                  'inspect.php?action=declaration-file&amp;id=7&amp;field=car_image', 'data-lightbox', 'Open dyno.pdf',
                  'href="inspect.php?action=declaration&amp;id=5">View</a>', 'This one', 'href="inspect.php?action=classing&amp;car=3"',
                  'action="inspect.php?action=declaration-resend"', 'sent 1 time'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringNotContainsString('declaration-delete', $html);
        $this->assertStringNotContainsString('declaration-update-contact', $html);
    }

    public function testAnAcceptedDeclarationCanOnlyBeSentBackAndNamesTheReviewer(): void
    {
        $html = renderInspectDeclarationHtml($this->declVm(
            ['review_status' => 'accepted', 'reviewed_at' => '2026-03-04 09:30:00', 'accepted_at' => '2026-03-04 09:30:00'],
            ['reviewer' => ['name' => 'Ivy Inspector']]
        ));
        $this->assertStringContainsString('Reviewed by Ivy Inspector on Mar 4, 2026 9:30 AM.', $html);
        $this->assertStringContainsString('action=declaration-send-back"', $html);
        $this->assertStringNotContainsString('action=declaration-accept"', $html);
    }

    public function testASentBackDeclarationShowsTheNoteAndCanBeAccepted(): void
    {
        $html = renderInspectDeclarationHtml($this->declVm(['review_status' => 'needs_changes', 'reviewer_note' => "Attach <dyno>.\nThanks", 'reviewed_at' => '2026-03-04 09:30:00']));
        $this->assertStringContainsString('Attach &lt;dyno&gt;.<br />', $html);
        $this->assertStringContainsString('action=declaration-accept"', $html);
        $this->assertStringNotContainsString('action=declaration-send-back"', $html);
    }

    public function testASupersededDeclarationCannotBeReviewed(): void
    {
        $html = renderInspectDeclarationHtml($this->declVm(['review_status' => 'superseded']));
        $this->assertStringContainsString('no longer the current one', $html);
        $this->assertStringNotContainsString('action=declaration-accept"', $html);
        $this->assertStringNotContainsString('action=declaration-send-back"', $html);
    }

    public function testAdminsAlsoGetEditAndDelete(): void
    {
        $html = renderInspectDeclarationHtml($this->declVm([], ['isAdmin' => true]));
        $this->assertStringContainsString('action="inspect.php?action=declaration-update-contact"', $html);
        $this->assertStringContainsString('action="inspect.php?action=declaration-delete"', $html);
        $this->assertStringContainsString('value="Jordan &lt;Lee&gt;"', $html);
    }

    private function classingVm(array $rows, array $filters = [], array $o = []): array {
        return array_merge(['filters' => inspectClassingFilters($filters), 'rows' => $rows, 'total' => count($rows), 'pages' => 1, 'isAdmin' => false, 'csrf' => 'tok'], $o);
    }

    public function testClassingListForAnInspector(): void
    {
        $html = renderInspectClassingHtml($this->classingVm([$this->decl()], ['class' => 'GT3', 'q' => 'hon"da']));
        foreach (['<h1 class="hub-page-title">Classing</h1>', '#42 2004 Honda S2000', 'Jordan &lt;Lee&gt;',
                  'href="inspect.php?action=declaration&amp;id=7">Review</a>', '1 declaration', '<option value="GT3" selected>GT3</option>',
                  'value="hon&quot;da"', 'href="inspect.php?action=declarations-export"'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringNotContainsString('bulk-delete-form', $html);
        $this->assertStringNotContainsString('submission-select', $html);
    }

    public function testClassingListForAnAdminHasBulkDelete(): void
    {
        $html = renderInspectClassingHtml($this->classingVm([$this->decl()], [], ['isAdmin' => true]));
        $this->assertStringContainsString('id="bulk-delete-form"', $html);
        $this->assertStringContainsString('class="submission-select" form="bulk-delete-form" name="ids[]" value="7"', $html);
        $this->assertStringContainsString('id="classing-select-all"', $html);
    }

    public function testClassingPaginationKeepsTheFilters(): void
    {
        $html = renderInspectClassingHtml($this->classingVm([$this->decl()], ['q' => 'honda', 'page' => '2'], ['pages' => 3, 'total' => 120]));
        $this->assertStringContainsString('href="inspect.php?action=classing&amp;q=honda">&larr; Previous</a>', $html);
        $this->assertStringContainsString('href="inspect.php?action=classing&amp;q=honda&amp;page=3">Next &rarr;</a>', $html);
        $this->assertStringContainsString('Page 2 of 3', $html);
    }

    public function testClassingEmptyAndOneCar(): void
    {
        $html = renderInspectClassingHtml($this->classingVm([], ['car' => '3']));
        $this->assertStringContainsString('No declarations match.', $html);
        $this->assertStringContainsString('0 declarations', $html);
        $this->assertStringContainsString("Showing one car's declarations.", $html);
        $this->assertStringContainsString('<input type="hidden" name="car" value="3">', $html);
    }
```

In `tests/InspectSourceTest.php`, add:

```php
    public function testClassingRoutesMovedFromAdmin(): void
    {
        $this->assertRoutesMoved(
            ['classing', 'declaration', 'declaration-file', 'declarations-export', 'declaration-accept', 'declaration-send-back',
             'declaration-resend', 'declaration-update-contact', 'declaration-delete', 'declarations-bulk-delete'],
            ['declaration-accept', 'declaration-send-back', 'declaration-resend', 'declaration-update-contact', 'declaration-delete', 'declarations-bulk-delete']
        );
        $admin = $this->src('admin.php');
        foreach (['list', 'view', 'file', 'resend', 'update-contact', 'delete', 'bulk-delete', 'export'] as $old) {
            $this->assertStringNotContainsString("case '$old':", $admin, $old);
        }
        $this->assertStringContainsString("\$action = \$_GET['action'] ?? 'users';", $admin);
    }

    public function testReviewEmailsGoOutOnlyAfterTheReviewSucceeded(): void
    {
        foreach (['inspectAcceptDeclaration' => 'accepted', 'inspectSendBackDeclaration' => 'sent_back'] as $fn => $kind) {
            $b = $this->body('inspect.php', $fn);
            $this->assertMatchesRegularExpression("/if \\(\\\$r\\['ok'\\]\\) \\{\\s*\\\$sent = declarationNotify\\(\\\$pdo, '$kind', /", $b, $fn);
            $this->assertStringContainsString("(int)current_user()['id']", $b, $fn);
            $this->assertStringContainsString("'emailSmtpSend'", $b, $fn);
        }
    }

    public function testDeclarationFilesAreServedOnlyForKnownFields(): void
    {
        $b = $this->body('inspect.php', 'inspectDeclarationFile');
        $this->assertStringContainsString("\$columns = ['dyno_chart' => 'dyno_chart_path', 'dyno_table' => 'dyno_table_path', 'car_image' => 'car_image_path'];", $b);
        $this->assertStringContainsString('http_response_code(404)', $b);
        $this->assertStringContainsString("header('X-Content-Type-Options: nosniff');", $b);
    }

    public function testBulkDeleteIdsAreScalarOnly(): void
    {
        $this->assertStringContainsString("fn(\$v): int => is_scalar(\$v) ? (int)\$v : 0", $this->src('inspect.php'));
    }
```

In `tests/RolesTest.php`, add:

```php
    public function testMovedClassingActionsRedirect(): void
    {
        $this->assertSame('inspect.php?action=classing', adminMovedActionUrl('list', ['sort' => 'name', 'dir' => 'asc']));
        $this->assertSame('inspect.php?action=declaration&id=7', adminMovedActionUrl('view', ['id' => '7']));
        $this->assertSame('inspect.php?action=declaration-file&id=7&field=car_image', adminMovedActionUrl('file', ['id' => '7', 'field' => 'car_image']));
        $this->assertSame('inspect.php?action=declarations-export', adminMovedActionUrl('export', ['sort' => 'name']));
        $this->assertNull(adminMovedActionUrl('delete', ['id' => '7']));
    }
```

Rename the delete guard and rewrite it for `inspect.php`: `git mv tests/AdminDeleteGuardTest.php tests/DeclarationDeleteGuardTest.php`, then replace its contents with:

```php
<?php
// wcma-calculator/tests/DeclarationDeleteGuardTest.php
//
// Source-level guard: inspect.php needs config.php, so it cannot run under PHPUnit. Deleting a class
// declaration from Classing (admins only) refuses one that a submitted tech sheet uses, reports how many a
// bulk delete skipped, and leaves the car with its previous declaration as current (as the Garage does).
use PHPUnit\Framework\TestCase;

final class DeclarationDeleteGuardTest extends TestCase
{
    private function body(string $name): string {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../inspect.php'));
        $start = strpos($src, 'function ' . $name . '(');
        $this->assertNotFalse($start, $name . ' must exist');
        $next = strpos($src, "\nfunction ", $start + 1);
        return $next === false ? substr($src, $start) : substr($src, $start, $next - $start);
    }

    public function testDeleteRefusesWhenReferencedByASubmittedTechSheet(): void
    {
        $body = $this->body('inspectDeleteDeclaration');
        $this->assertStringContainsString('db_count_tech_sheets_for_submission(', $body);
        $this->assertStringContainsString('This declaration is on a submitted tech sheet, so it cannot be deleted.', $body);
    }

    public function testBulkDeleteSkipsReferencedIdsAndReportsHowMany(): void
    {
        $body = $this->body('inspectBulkDeleteDeclarations');
        $this->assertStringContainsString('db_count_tech_sheets_for_submission(', $body);
        $this->assertStringContainsString('skipped', $body);
    }

    public function testDeletingRestoresThePreviousDeclaration(): void
    {
        $this->assertMatchesRegularExpression(
            "/db_delete_submission\\(\\\$pdo, \\\$id\\);\\s*db_restore_current_declaration\\(\\\$pdo, \\(int\\)\\\$sub\\['car_id'\\]\\);/",
            $this->body('inspectDeleteDeclaration')
        );
    }

    public function testBulkDeleteRestoresEachCarsPreviousDeclaration(): void
    {
        $this->assertMatchesRegularExpression(
            "/db_delete_submission\\(\\\$pdo, \\\$id\\);.*?db_restore_current_declaration\\(\\\$pdo, \\(int\\)\\\$sub\\['car_id'\\]\\);/s",
            $this->body('inspectBulkDeleteDeclarations')
        );
    }

    public function testDeletingAndEditingAreAdminOnly(): void
    {
        foreach (['declaration-delete', 'declarations-bulk-delete', 'declaration-update-contact'] as $action) {
            $this->assertSame('admin', inspectActionMinRole($action), $action);
        }
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter 'InspectPageTest|InspectSourceTest|RolesTest|DeclarationDeleteGuardTest'`
Expected: FAIL or ERROR. `renderInspectDeclarationHtml()` is undefined, the routes are missing, and the moved-action entries are missing.

- [ ] **Step 3: Add the renderers to `inspect-page.php`**

Append:

```php
/**
 * The Classing tab: every declaration, searchable and filterable (spec §5). Admins also get bulk delete.
 *
 * @param array{filters: array, rows: array, total: int, pages: int, isAdmin: bool, csrf: string} $vm
 */
function renderInspectClassingHtml(array $vm): string {
    $f = $vm['filters'];
    $admin = $vm['isAdmin'];
    $out = '<h1 class="hub-page-title">Classing</h1>'
        . '<form method="get" action="inspect.php" class="hub-card inspect-filters">'
        . '<input type="hidden" name="action" value="classing">'
        . ($f['car'] > 0 ? '<input type="hidden" name="car" value="' . (int)$f['car'] . '">' : '')
        . '<label for="classing-q">Search</label>'
        . '<input type="search" id="classing-q" name="q" value="' . h($f['q']) . '" placeholder="Name, email, car number, make or model">'
        . '<label for="classing-class">Class</label><select id="classing-class" name="class"><option value="">All classes</option>';
    foreach (INSPECT_CLASSES as $c) {
        $out .= '<option value="' . h($c) . '"' . ($c === $f['class'] ? ' selected' : '') . '>' . h($c) . '</option>';
    }
    $out .= '</select><label for="classing-season">Season</label>'
        . '<input type="number" id="classing-season" name="season" min="2000" max="2100" value="' . ($f['season'] > 0 ? (int)$f['season'] : '') . '">'
        . '<label for="classing-status">Review</label><select id="classing-status" name="status"><option value="">Any status</option>';
    foreach (INSPECT_DECLARATION_STATUSES as $st) {
        $out .= '<option value="' . h($st) . '"' . ($st === $f['status'] ? ' selected' : '') . '>' . h(declarationReviewLabel($st)) . '</option>';
    }
    $out .= '</select><button type="submit" class="hub-btn">Search</button> <a href="inspect.php?action=classing">Clear</a></form>';
    if ($f['car'] > 0) {
        $out .= '<p>Showing one car\'s declarations. <a href="inspect.php?action=classing">Show every car</a></p>';
    }

    $total = (int)$vm['total'];
    $out .= '<p class="list-summary">' . $total . ' ' . ($total === 1 ? 'declaration' : 'declarations')
        . ($vm['pages'] > 1 ? ' · page ' . (int)$f['page'] . ' of ' . (int)$vm['pages'] : '')
        . ' · <a href="inspect.php?action=declarations-export">Export all as CSV</a></p>';
    if ($admin && $vm['rows']) {
        $out .= '<form method="post" action="inspect.php?action=declarations-bulk-delete" id="bulk-delete-form">' . inspectCsrfField($vm['csrf'])
            . '<button type="submit" id="bulk-delete-btn" class="hub-btn hub-btn--secondary" disabled data-confirm-template="Permanently delete {n} selected declaration(s) and their files?">Delete selected</button></form>';
    }

    $out .= '<table class="data-table" id="classing-table"><thead><tr>'
        . ($admin ? '<th><input type="checkbox" id="classing-select-all" aria-label="Select all declarations"></th>' : '')
        . '<th>Submitted</th><th>Car</th><th>Entrant</th><th>Class</th><th>Review</th><th>Actions</th></tr></thead><tbody>';
    if (!$vm['rows']) {
        $out .= '<tr><td colspan="' . ($admin ? 7 : 6) . '" class="empty-row">No declarations match.</td></tr>';
    }
    foreach ($vm['rows'] as $s) {
        $id = (int)$s['id'];
        $status = (string)$s['review_status'];
        $out .= '<tr>'
            . ($admin ? '<td><input type="checkbox" class="submission-select" form="bulk-delete-form" name="ids[]" value="' . $id . '" aria-label="Select declaration ' . $id . '"></td>' : '')
            . '<td>' . h(date('M j, Y', strtotime((string)$s['submitted_at']))) . '</td>'
            . '<td>#' . h((string)($s['car_number'] ?? '?')) . ' ' . h(trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])) . '</td>'
            . '<td>' . h((string)$s['name']) . '</td>'
            . '<td><strong>' . h((string)($s['calculated_class'] ?? '—')) . '</strong></td>'
            . '<td><span class="hub-status ' . h(homeStatusClass($status)) . '">' . h(declarationReviewLabel($status)) . '</span></td>'
            . '<td><a href="inspect.php?action=declaration&amp;id=' . $id . '">' . ($status === 'submitted' ? 'Review' : 'View') . '</a></td></tr>';
    }
    $out .= '</tbody></table>';

    if ($vm['pages'] > 1) {
        $out .= '<nav class="pagination" aria-label="Declaration pages">';
        if ($f['page'] > 1) $out .= '<a href="' . h(inspectClassingQuery($f, ['page' => $f['page'] - 1])) . '">&larr; Previous</a> ';
        $out .= '<span>Page ' . (int)$f['page'] . ' of ' . (int)$vm['pages'] . '</span>';
        if ($f['page'] < $vm['pages']) $out .= ' <a href="' . h(inspectClassingQuery($f, ['page' => $f['page'] + 1])) . '">Next &rarr;</a>';
        $out .= '</nav>';
    }
    return $out;
}

/**
 * One class declaration for an inspector: the review card, the calculation, the car and entrant, files
 * and this car's declaration history. Admins also get Edit contact details and Delete.
 *
 * @param array{sub: array, car: ?array, owner: ?array, reviewer: ?array, history: array, isAdmin: bool, csrf: string} $vm
 */
function renderInspectDeclarationHtml(array $vm): string {
    $s = $vm['sub'];
    $id = (int)$s['id'];
    $car = $vm['car'];
    $vehicle = trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model']);
    $out = '<p><a href="inspect.php?action=classing">&larr; Back to Classing</a></p>'
        . '<h1 class="hub-page-title">Class declaration: ' . h(($car !== null ? '#' . $car['car_number'] . ' ' : '') . $vehicle) . '</h1>'
        . inspectDeclarationReviewHtml($s, $vm['reviewer'], $vm['csrf'])
        . '<div class="hub-grid-2">'
        . '<section class="hub-card"><h2>Calculation</h2>' . inspectBreakdownHtml($s) . '</section>'
        . '<section class="hub-card"><h2>Car and entrant</h2>' . inspectDeclarationFactsHtml($s, $car, $vm['owner']) . inspectResendFormHtml($s, $vm['csrf']) . '</section>'
        . '</div>'
        . '<section class="hub-card"><h2>Uploaded files</h2>' . inspectDeclarationFilesHtml($s) . '</section>'
        . inspectDeclarationHistoryHtml($id, $car, $vm['history']);
    return $vm['isAdmin'] ? $out . inspectDeclarationAdminHtml($s, $vm['csrf']) : $out;
}

/** Status, who reviewed it, the note, and the Accept / Send back forms the status allows. */
function inspectDeclarationReviewHtml(array $s, ?array $reviewer, string $csrf): string {
    $id = (int)$s['id'];
    $status = (string)$s['review_status'];
    $out = '<section class="hub-card" id="declaration-review"><h2>Review</h2>'
        . '<p><span class="hub-status ' . h(homeStatusClass($status)) . '">' . h(declarationReviewLabel($status)) . '</span></p>';
    if (!empty($s['reviewed_at'])) {
        $out .= '<p>Reviewed' . ($reviewer !== null ? ' by ' . h((string)$reviewer['name']) : '')
            . ' on ' . h(date('M j, Y g:i A', strtotime((string)$s['reviewed_at']))) . '.</p>';
    }
    if (trim((string)($s['reviewer_note'] ?? '')) !== '') {
        $out .= '<p class="garage-note"><strong>Note sent to the competitor:</strong> ' . nl2br(h((string)$s['reviewer_note'])) . '</p>';
    }
    if ($status === 'superseded') {
        return $out . '<p>The competitor has re-declared this car, so this declaration is no longer the current one. Review the newest one in the history below.</p></section>';
    }
    if (declarationReviewAllowed($status, 'accept')) {
        $out .= '<p class="form-hint">Accept when the declared weight, power and modifications match what you know of the car.</p>'
            . '<form method="post" action="inspect.php?action=declaration-accept">' . inspectCsrfField($csrf)
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<button type="submit" class="hub-btn" id="declaration-accept-btn">Accept declaration</button></form>';
    }
    if (declarationReviewAllowed($status, 'send_back')) {
        $out .= '<form method="post" action="inspect.php?action=declaration-send-back" id="declaration-sendback-form">' . inspectCsrfField($csrf)
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<label for="declaration-note">What needs to change?</label>'
            . '<textarea id="declaration-note" name="note" rows="4" maxlength="' . DECLARATION_NOTE_MAX . '" required></textarea>'
            . '<p class="form-hint">The competitor gets this note by email and sees it in their Garage. They respond by re-declaring the car.</p>'
            . '<button type="submit" class="hub-btn hub-btn--secondary" id="declaration-sendback-btn">Send back</button></form>';
    }
    return $out . '</section>';
}

/** Class ranges mirrored from js/calculator.js determineClass(), used only to annotate the breakdown. */
function inspectClassForRatio(float $ratio): ?array {
    $ranges = [['GTU', -INF, 6.00], ['GT1', 6.00, 8.00], ['GT2', 8.00, 10.00], ['GT3', 10.00, 12.00],
               ['GT4', 12.00, 14.00], ['IT1', 14.00, 18.00], ['IT2', 18.00, INF]];
    if ($ratio <= 0) return null;
    foreach ($ranges as $range) {
        if ($ratio >= $range[1] && $ratio < $range[2]) return $range;
    }
    return null;
}

function inspectFormatClassRange(array $range): string {
    [$name, $min, $max] = $range;
    if ($min === -INF) return $name . ' (< ' . number_format($max, 2) . ')';
    return $name . ' (' . number_format($min, 2) . ($max === INF ? '+' : ' – ' . number_format($max - 0.01, 2)) . ')';
}

function inspectModRow(string $label, ?string $display, float $value): string {
    if (!$display && $value == 0) return '';
    return '<tr><td>' . h($label) . '</td><td class="inspect-num">' . ($value >= 0 ? '+' : '') . number_format($value, 2) . '</td>'
        . '<td class="inspect-muted">' . ($display ? h($display) : '—') . '</td></tr>';
}

function inspectBreakdownHtml(array $s): string {
    $weight = (float)$s['competition_weight'];
    $base = (float)$s['base_ratio'];
    $range = inspectClassForRatio($base);
    $brakes = json_decode((string)($s['brake_suspension'] ?? '[]'), true);
    $brakeText = is_array($brakes) ? implode(', ', array_map('strval', $brakes)) : '';
    $wf = (float)$s['weight_factor'];
    return '<table class="calc-table">'
        . '<tr><td>Base ratio</td><td class="inspect-num">' . number_format($base, 2) . '</td><td class="inspect-muted">'
        . number_format($weight, 0) . ' lbs ÷ ' . number_format((float)$s['declared_hp'], 0) . ' hp'
        . ($range !== null ? ' → ' . h(inspectFormatClassRange($range)) : '') . '</td></tr>'
        . '<tr><td>Weight factor</td><td class="inspect-num">' . ($wf >= 0 ? '+' : '') . number_format($wf, 2) . '</td><td class="inspect-muted">at ' . number_format($weight, 0) . ' lbs</td></tr>'
        . inspectModRow('Chassis', $s['chassis_display'], (float)$s['chassis_value'])
        . inspectModRow('Body mods', $s['body_mods_display'], (float)$s['body_mods_value'])
        . inspectModRow('Transmission', $s['transmission_display'], (float)$s['transmission_value'])
        . inspectModRow('Drivetrain', $s['drivetrain_display'], (float)$s['drivetrain_value'])
        . inspectModRow('Tires', $s['tires_display'], (float)$s['tires_value'])
        . ((float)$s['brake_suspension_value'] != 0 ? inspectModRow('Brake & susp.', $brakeText, (float)$s['brake_suspension_value']) : '')
        . '<tr class="total"><td>Modified ratio</td><td class="inspect-num">' . number_format((float)$s['modified_ratio'], 2) . '</td>'
        . '<td class="class-badge">' . h((string)($s['calculated_class'] ?? '—')) . '</td></tr></table>';
}

function inspectDeclarationFactsHtml(array $s, ?array $car, ?array $owner): string {
    $rows = [];
    if ($car !== null) {
        $colour = trim((string)($car['colour'] ?? ''));
        $rows[] = ['Car', '#' . $car['car_number'] . ' ' . garageCarTitle($car) . ($colour !== '' ? ' · ' . $colour : '')];
    }
    $rows[] = ['Entrant', $s['name'] . ' (' . $s['email'] . ')'];
    if ($owner !== null) $rows[] = ['Account', $owner['name'] . ' (' . $owner['email'] . ')'];
    $rows[] = ['Declared vehicle', trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])];
    $rows[] = ['Weight', $s['competition_weight'] . ' lbs'];
    $rows[] = ['Declared HP', (string)$s['declared_hp']];
    if (!empty($s['dyno_hp'])) $rows[] = ['Dyno HP', (string)$s['dyno_hp']];
    $rows[] = ['Submitted', date('F j, Y \a\t g:i A', strtotime((string)$s['submitted_at']))];
    $out = '<table class="detail-table">';
    foreach ($rows as [$label, $value]) {
        $out .= '<tr><td>' . h($label) . '</td><td>' . h((string)$value) . '</td></tr>';
    }
    if (trim((string)($s['comments'] ?? '')) !== '') {
        $out .= '<tr><td>Comments</td><td>' . nl2br(h((string)$s['comments'])) . '</td></tr>';
    }
    return $out . '</table>';
}

function inspectResendFormHtml(array $s, string $csrf): string {
    $count = (int)$s['email_send_count'];
    $history = $count > 0 && !empty($s['last_emailed_at'])
        ? 'Last emailed ' . date('M j, Y \a\t g:i A', strtotime((string)$s['last_emailed_at'])) . ' · sent ' . $count . ' ' . ($count === 1 ? 'time' : 'times')
        : 'Never emailed.';
    return '<p class="form-hint">' . h($history) . '</p>'
        . '<form method="post" action="inspect.php?action=declaration-resend" data-confirm="'
        . h('Re-send the declaration email to ' . $s['name'] . ' (' . $s['email'] . ') and the classing address?') . '">'
        . inspectCsrfField($csrf) . '<input type="hidden" name="id" value="' . (int)$s['id'] . '">'
        . '<button type="submit" class="hub-btn hub-btn--secondary">Re-send declaration email</button></form>';
}

function inspectDeclarationFilesHtml(array $s): string {
    $files = ['car_image' => ['Car image', $s['car_image_path'] ?? null], 'dyno_chart' => ['Dyno chart', $s['dyno_chart_path'] ?? null],
              'dyno_table' => ['Dyno table', $s['dyno_table_path'] ?? null]];
    $out = '';
    foreach ($files as $field => [$label, $path]) {
        if (!$path) continue;
        $url = 'inspect.php?action=declaration-file&id=' . (int)$s['id'] . '&field=' . $field;
        $isImage = in_array(strtolower(pathinfo((string)$path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true);
        $out .= '<p><strong>' . h($label) . '</strong></p>' . ($isImage
            ? '<img src="' . h($url) . '" class="file-thumb" data-lightbox alt="' . h($label) . '">'
            : '<a href="' . h($url) . '" target="_blank" rel="noopener">Open ' . h(basename((string)$path)) . '</a>');
    }
    return $out !== '' ? $out : '<p>No files uploaded.</p>';
}

function inspectDeclarationHistoryHtml(int $currentId, ?array $car, array $history): string {
    if ($car === null || !$history) return '';
    $out = '<section class="hub-card"><h2>This car\'s declarations</h2><table class="data-table">'
        . '<thead><tr><th>Submitted</th><th>Class</th><th>Review</th><th></th></tr></thead><tbody>';
    foreach ($history as $d) {
        $did = (int)$d['id'];
        $out .= '<tr><td>' . h(date('M j, Y', strtotime((string)$d['submitted_at']))) . '</td>'
            . '<td>' . h((string)($d['calculated_class'] ?? '—')) . '</td>'
            . '<td>' . h(declarationReviewLabel((string)$d['review_status'])) . '</td>'
            . '<td>' . ($did === $currentId ? 'This one' : '<a href="inspect.php?action=declaration&amp;id=' . $did . '">View</a>') . '</td></tr>';
    }
    return $out . '</tbody></table><p><a href="inspect.php?action=classing&amp;car=' . (int)$car['id'] . '">Open this car in Classing</a></p></section>';
}

/** Admin only: edit the entrant's contact details, and delete the declaration. */
function inspectDeclarationAdminHtml(array $s, string $csrf): string {
    $id = (int)$s['id'];
    $field = fn(string $name, string $label, string $type = 'text', bool $required = false): string =>
        '<label for="edit-' . $name . '">' . $label . '</label><input type="' . $type . '" id="edit-' . $name . '" name="' . $name . '" value="'
        . h((string)($s[$name] ?? '')) . '"' . ($required ? ' required' : '') . '>';
    return '<details class="hub-card"><summary>Edit contact details (admin)</summary>'
        . '<form method="post" action="inspect.php?action=declaration-update-contact" class="edit-form">' . inspectCsrfField($csrf)
        . '<input type="hidden" name="id" value="' . $id . '">'
        . $field('name', 'Name', 'text', true) . $field('email', 'Email', 'email', true)
        . $field('year', 'Year') . $field('make', 'Make') . $field('model', 'Model')
        . '<label for="edit-comments">Comments</label><textarea id="edit-comments" name="comments" rows="3">' . h((string)($s['comments'] ?? '')) . '</textarea>'
        . '<button type="submit" class="hub-btn">Save</button></form></details>'
        . '<form method="post" action="inspect.php?action=declaration-delete" data-confirm="Permanently delete this declaration and its files?">'
        . inspectCsrfField($csrf) . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="hub-btn hub-btn--link">Delete this declaration (admin)</button></form>';
}
```

- [ ] **Step 4: Add the handlers to `inspect.php`**

After `require __DIR__ . '/inspect-page.php';`, add:

```php
require __DIR__ . '/declaration-email.php';
require __DIR__ . '/submission-email-render.php';
```

After `require __DIR__ . '/email-helpers.php';`, add:

```php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
```

Add these cases to the router, before `default:`:

```php
    case 'classing':
        inspectShowClassing($pdo);
        break;
    case 'declaration':
        inspectShowDeclaration($pdo, $getId);
        break;
    case 'declaration-file':
        inspectDeclarationFile($pdo, $getId, is_string($_GET['field'] ?? null) ? $_GET['field'] : '');
        break;
    case 'declarations-export':
        inspectExportDeclarations($pdo);
        break;
    case 'declaration-accept':
        inspectAcceptDeclaration($pdo, $postId);
        break;
    case 'declaration-send-back':
        inspectSendBackDeclaration($pdo, $postId);
        break;
    case 'declaration-resend':
        inspectResendDeclaration($pdo, $postId);
        break;
    case 'declaration-update-contact':
        inspectUpdateDeclarationContact($pdo, $postId);
        break;
    case 'declaration-delete':
        inspectDeleteDeclaration($pdo, $postId);
        break;
    case 'declarations-bulk-delete':
        $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
        inspectBulkDeleteDeclarations($pdo, array_map(fn($v): int => is_scalar($v) ? (int)$v : 0, $ids));
        break;
```

(`array_map('intval', …)` would turn a nested array into `1`. Declaration 1 could then be deleted by accident, so the ids are checked with `is_scalar()`.)

Append these functions to the end of `inspect.php`:

```php
function inspectShowClassing(PDO $pdo): void {
    $perPage = 50;
    $f = inspectClassingFilters($_GET);
    $result = db_search_declarations($pdo, $f, $perPage, ($f['page'] - 1) * $perPage);
    $pages = max(1, (int)ceil($result['total'] / $perPage));
    if ($f['page'] > $pages) { header('Location: ' . inspectClassingQuery($f, ['page' => $pages])); exit; }

    $isAdmin = is_admin();
    renderPageStart('Classing', 'inspect', ['flash' => getFlash(), 'subnav' => inspectSubnavHtml('classing')]);
    echo renderInspectClassingHtml(['filters' => $f, 'rows' => $result['rows'], 'total' => $result['total'], 'pages' => $pages,
        'isAdmin' => $isAdmin, 'csrf' => generateCsrfToken()]);
    renderPageEnd(['scripts' => '<script src="js/table-tools.js"></script><script src="js/confirm-modal.js"></script><script src="js/form-feedback.js"></script>'
        . ($isAdmin ? '<script>WcmaTableTools.enableBulkSelect(document.getElementById(\'classing-select-all\'), document.getElementById(\'classing-table\'), document.getElementById(\'bulk-delete-btn\'));</script>' : '')]);
}

function inspectShowDeclaration(PDO $pdo, int $id): void {
    $sub = db_get_submission($pdo, $id);
    if ($sub === null) { setFlash('Class declaration not found.', 'error'); header('Location: inspect.php?action=classing'); exit; }
    $car = db_get_car($pdo, (int)$sub['car_id']);
    renderPageStart('Class declaration #' . $id, 'inspect', ['flash' => getFlash(), 'subnav' => inspectSubnavHtml('classing')]);
    echo renderInspectDeclarationHtml([
        'sub' => $sub,
        'car' => $car,
        'owner' => db_find_user_by_id($pdo, (int)$sub['user_id']),
        'reviewer' => !empty($sub['reviewed_by_user_id']) ? db_find_user_by_id($pdo, (int)$sub['reviewed_by_user_id']) : null,
        'history' => $car !== null ? db_get_car_declarations($pdo, (int)$car['id']) : [],
        'isAdmin' => is_admin(),
        'csrf' => generateCsrfToken(),
    ]);
    renderPageEnd(['scripts' => '<script src="js/confirm-modal.js"></script><script src="js/form-feedback.js"></script><script src="js/lightbox.js"></script>']);
}

function inspectBaseUrl(): string {
    return feedbackBaseUrl($_SERVER, (string)config_default('SITE_BASE_URL', ''));
}

function inspectAcceptDeclaration(PDO $pdo, int $id): void {
    $r = declarationReviewAccept($pdo, $id, (int)current_user()['id']);
    if ($r['ok']) {
        $sent = declarationNotify($pdo, 'accepted', db_get_submission($pdo, $id), inspectBaseUrl(), 'emailSmtpSend');
        setFlash('Declaration accepted.' . ($sent ? ' The competitor was emailed.' : ' The email could not be sent.'), $sent ? 'success' : 'error');
    } else {
        setFlash($r['error'], 'error');
    }
    header('Location: inspect.php?action=declaration&id=' . $id);
    exit;
}

function inspectSendBackDeclaration(PDO $pdo, int $id): void {
    $note = is_string($_POST['note'] ?? null) ? $_POST['note'] : '';
    $r = declarationReviewSendBack($pdo, $id, (int)current_user()['id'], $note);
    if ($r['ok']) {
        $sent = declarationNotify($pdo, 'sent_back', db_get_submission($pdo, $id), inspectBaseUrl(), 'emailSmtpSend');
        setFlash('Declaration sent back with your note.' . ($sent ? ' The competitor was emailed.' : ' The email could not be sent.'), $sent ? 'success' : 'error');
    } else {
        setFlash($r['error'], 'error');
    }
    header('Location: inspect.php?action=declaration&id=' . $id);
    exit;
}

/** Serves a declaration's uploaded file to an inspector (uploads/ is Deny-from-all). */
function inspectDeclarationFile(PDO $pdo, int $id, string $field): void {
    $columns = ['dyno_chart' => 'dyno_chart_path', 'dyno_table' => 'dyno_table_path', 'car_image' => 'car_image_path'];
    $sub = isset($columns[$field]) ? db_get_submission($pdo, $id) : null;
    $path = $sub[$columns[$field] ?? ''] ?? null;
    $full = $path ? __DIR__ . '/' . $path : null;
    if ($full === null || !is_file($full)) { http_response_code(404); exit; }

    $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
              'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
              'txt' => 'text/plain'];
    header('Content-Type: ' . ($types[strtolower(pathinfo($full, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($full));
    header('X-Content-Type-Options: nosniff');
    readfile($full);
    exit;
}

function inspectCsvSafe($value): string {
    $value = (string)$value;
    return ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) ? "'" . $value : $value;
}

function inspectExportDeclarations(PDO $pdo): void {
    $rows = db_search_declarations($pdo, [], -1, 0)['rows'];   // SQLite: a negative LIMIT means no limit
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="wcma-declarations-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Submitted', 'Car #', 'Name', 'Email', 'Year', 'Make', 'Model', 'Weight', 'Declared HP', 'Dyno HP',
        'Base Ratio', 'Weight Factor', 'Modification Factor', 'Modified Ratio', 'Class', 'Review', 'Email Sent']);
    foreach ($rows as $s) {
        fputcsv($out, array_map('inspectCsvSafe', [
            $s['id'], $s['submitted_at'], $s['car_number'] ?? '', $s['name'], $s['email'], $s['year'], $s['make'], $s['model'],
            $s['competition_weight'], $s['declared_hp'], $s['dyno_hp'], $s['base_ratio'], $s['weight_factor'],
            $s['modification_factor'], $s['modified_ratio'], $s['calculated_class'],
            declarationReviewLabel((string)$s['review_status']), $s['email_sent'] ? 'Yes' : 'No',
        ]));
    }
    fclose($out);
    exit;
}

function inspectBuildMailer(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = (SMTP_PORT === 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom(FROM_EMAIL, FROM_NAME);
    return $mail;
}

/** Re-sends the declaration email (with its files) to the classing address and to the entrant. */
function inspectResendDeclaration(PDO $pdo, int $id): void {
    $sub = db_get_submission($pdo, $id);
    if ($sub === null) { setFlash('Class declaration not found.', 'error'); header('Location: inspect.php?action=classing'); exit; }

    $attachments = [];
    foreach (['dyno_chart_path', 'dyno_table_path', 'car_image_path'] as $col) {
        if ($sub[$col] && is_file(__DIR__ . '/' . $sub[$col])) $attachments[] = __DIR__ . '/' . $sub[$col];
    }
    $recipients = [
        [(string)db_get_setting($pdo, 'classing_recipient_email', config_default('CLASSING_RECIPIENT_EMAIL', 'classing@wcma.ca')),
         (string)db_get_setting($pdo, 'classing_recipient_name', config_default('CLASSING_RECIPIENT_NAME', 'WCMA Classing')),
         'WCMA Classing Calculator Submission — ' . $sub['name'] . ' — ' . date('M j, Y', strtotime((string)$sub['submitted_at']))],
        [(string)$sub['email'], (string)$sub['name'], 'Your WCMA Classing Calculator Submission'],
    ];
    $sent = false;
    try {
        foreach ($recipients as $i => [$address, $name, $subject]) {
            $mail = inspectBuildMailer();
            $mail->addAddress($address, $name);
            if ($i === 0) $mail->addReplyTo((string)$sub['email'], (string)$sub['name']);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = renderSubmissionEmailHtml($sub, emailLogoSrc($mail), true);
            $mail->AltBody = renderSubmissionEmailText($sub, true);
            foreach ($attachments as $path) $mail->addAttachment($path, basename($path));
            $mail->send();
        }
        $sent = true;
    } catch (Exception $e) {
        error_log('Declaration resend error: ' . $e->getMessage());
    }
    db_update_email_sent($pdo, $id, $sent ? 1 : 0);
    setFlash($sent ? 'Declaration email re-sent.' : 'The declaration email could not be sent. Check the server log.', $sent ? 'success' : 'error');
    header('Location: inspect.php?action=declaration&id=' . $id);
    exit;
}

function inspectUpdateDeclarationContact(PDO $pdo, int $id): void {
    if (db_get_submission($pdo, $id) === null) { setFlash('Class declaration not found.', 'error'); header('Location: inspect.php?action=classing'); exit; }
    $field = fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
    $name = $field('name');
    $email = $field('email');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        setFlash('Name and a valid email are required.', 'error');
    } else {
        $comments = $field('comments');
        db_update_submission_contact($pdo, $id, ['name' => $name, 'email' => $email, 'year' => $field('year'), 'make' => $field('make'),
            'model' => $field('model'), 'comments' => $comments !== '' ? $comments : null]);
        setFlash('Contact details updated.', 'success');
    }
    header('Location: inspect.php?action=declaration&id=' . $id);
    exit;
}

function inspectDeleteDeclarationFiles(int $id): void {
    $dir = __DIR__ . '/uploads/' . $id;
    if (!is_dir($dir)) return;
    foreach (glob($dir . '/*') ?: [] as $file) unlink($file);
    rmdir($dir);
}

function inspectDeleteDeclaration(PDO $pdo, int $id): void {
    $sub = db_get_submission($pdo, $id);
    if ($sub === null) { setFlash('Class declaration not found.', 'error'); header('Location: inspect.php?action=classing'); exit; }
    if (db_count_tech_sheets_for_submission($pdo, $id) > 0) {
        setFlash('This declaration is on a submitted tech sheet, so it cannot be deleted.', 'error');
        header('Location: inspect.php?action=declaration&id=' . $id);
        exit;
    }
    inspectDeleteDeclarationFiles($id);
    db_delete_submission($pdo, $id);
    db_restore_current_declaration($pdo, (int)$sub['car_id']);
    setFlash('Declaration deleted.', 'success');
    header('Location: inspect.php?action=classing');
    exit;
}

function inspectBulkDeleteDeclarations(PDO $pdo, array $ids): void {
    $ids = array_values(array_unique(array_filter($ids, fn(int $id): bool => $id > 0)));
    if (!$ids) { setFlash('No declarations selected.', 'error'); header('Location: inspect.php?action=classing'); exit; }
    $deleted = 0;
    $skipped = 0;
    foreach ($ids as $id) {
        $sub = db_get_submission($pdo, $id);
        if ($sub === null) continue;
        if (db_count_tech_sheets_for_submission($pdo, $id) > 0) { $skipped++; continue; }
        inspectDeleteDeclarationFiles($id);
        db_delete_submission($pdo, $id);
        db_restore_current_declaration($pdo, (int)$sub['car_id']);
        $deleted++;
    }
    setFlash("Deleted {$deleted} declaration(s)." . ($skipped > 0 ? " {$skipped} skipped (on a submitted tech sheet)." : ''), 'success');
    header('Location: inspect.php?action=classing');
    exit;
}
```

- [ ] **Step 5: Remove the submissions pages from `admin.php`**

In `admin.php`:
1. Change `$action = $_GET['action'] ?? 'list';` to `$action = $_GET['action'] ?? 'users';`.
2. Delete the `case` blocks `list`, `view`, `file`, `resend`, `update-contact`, `delete`, `bulk-delete` and `export`.
3. Change the `default:` case to:
   ```php
       default:
           requireAuth($minRole);
           handleUsersList($pdo);
   ```
4. Delete these functions completely: `handleList`, `renderListPage` (with its nested `sortLink`), `handleView`, `classForRatio`, `formatClassRange`, `renderDetailPage` (with its nested `modRow`), `handleFile`, `handleUpdateContact`, `handleResend`, `handleDelete`, `handleBulkDelete`, `csvSafe`, `handleExport` and `buildMailer`.
5. Delete the line `define('ADMIN_PAGE_SIZE', 50);`.

In `roles.php`, add to `ADMIN_MOVED_ACTIONS`:

```php
    'list' => ['classing', []],
    'view' => ['declaration', ['id']],
    'file' => ['declaration-file', ['id', 'field']],
    'export' => ['declarations-export', []],
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php phpunit.phar`, then `php -l inspect.php && php -l inspect-page.php && php -l admin.php`.
Expected: OK, with no syntax errors.

- [ ] **Step 7: Commit**

```bash
git add inspect.php inspect-page.php admin.php roles.php tests/InspectPageTest.php tests/InspectSourceTest.php tests/RolesTest.php tests/DeclarationDeleteGuardTest.php
git commit -m "feat(hub): Classing tab and declaration review (accept / send back) in the Inspector section"
```

---

### Task 8: The Review queue

**Files:**
- Modify: `inspect-page.php`, `inspect.php`, `css/hub.css`
- Test: modify `tests/InspectPageTest.php` and `tests/InspectSourceTest.php`

**Interfaces:**
- Consumes: `inspectReviewQueue()` (Task 3), and the three `db_get_*_awaiting_*()` queries (Task 2)
- Produces: `inspect.php?action=queue` and `renderInspectQueueHtml(array $items): string`

- [ ] **Step 1: Write the failing tests**

In `tests/InspectPageTest.php`, add:

```php
    public function testQueueListsItemsOldestFirstWithReviewLinks(): void
    {
        $car = ['kind' => 'car_photos', 'id' => 12, 'title' => 'Car pre-tech photos: #17 <Miata>', 'detail' => 'Jordan Lee · Fall Sprint',
                'since' => '2026-09-01 08:00:00', 'url' => 'inspect.php?action=tech-sheet&id=12#pretech-review'];
        $decl = ['kind' => 'declaration', 'id' => 4, 'title' => 'Class declaration: #42 2004 Honda S2000', 'detail' => 'Jordan Lee · GT3',
                 'since' => '2026-09-03 09:00:00', 'url' => 'inspect.php?action=declaration&id=4'];
        $html = renderInspectQueueHtml([$car, $decl]);
        $this->assertStringContainsString('2 items are waiting, oldest first.', $html);
        $this->assertStringContainsString('#17 &lt;Miata&gt;', $html);
        $this->assertStringContainsString('href="inspect.php?action=tech-sheet&amp;id=12#pretech-review">Review</a>', $html);
        $this->assertStringContainsString('waiting since Sep 1, 8:00 AM', $html);
        $this->assertLessThan(strpos($html, 'Class declaration'), strpos($html, 'Car pre-tech photos'));
        $this->assertStringContainsString('1 item is waiting, oldest first.', renderInspectQueueHtml([$decl]));
        $this->assertStringContainsString('Nothing is waiting for review.', renderInspectQueueHtml([]));
    }
```

In `tests/InspectSourceTest.php`, add:

```php
    public function testQueueRouteBuildsFromTheThreeQueries(): void
    {
        $this->assertContains('queue', $this->routes());
        $show = $this->body('inspect.php', 'inspectShowQueue');
        foreach (['db_get_declarations_awaiting_review($pdo)', 'db_get_sheets_awaiting_photo_review($pdo)', 'db_get_gear_awaiting_photo_review($pdo)', "inspectSubnavHtml('queue')"] as $needle) {
            $this->assertStringContainsString($needle, $show);
        }
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter 'InspectPageTest|InspectSourceTest'`
Expected: FAIL. `renderInspectQueueHtml()` is undefined, and there is no `queue` route.

- [ ] **Step 3: Implement**

Append to `inspect-page.php`:

```php
/** The Review queue tab: everything waiting on an inspector, oldest first, each linking to its review card. */
function renderInspectQueueHtml(array $items): string {
    $out = '<h1 class="hub-page-title">Review queue</h1>';
    if (!$items) return $out . '<p class="hub-card">Nothing is waiting for review.</p>';
    $n = count($items);
    $out .= '<p>' . $n . ' ' . ($n === 1 ? 'item is' : 'items are') . ' waiting, oldest first.</p><ol class="hub-card inspect-queue">';
    foreach ($items as $item) {
        $out .= '<li class="hub-line"><span><strong>' . h($item['title']) . '</strong><br>'
            . '<span class="inspect-muted">' . h($item['detail']) . ' · waiting since ' . h(date('M j, g:i A', strtotime($item['since']))) . '</span></span>'
            . '<a class="hub-btn" href="' . h($item['url']) . '">Review</a></li>';
    }
    return $out . '</ol>';
}
```

In `inspect.php`, add to the router before `default:`:

```php
    case 'queue':
        inspectShowQueue($pdo);
        break;
```

and append:

```php
function inspectShowQueue(PDO $pdo): void {
    $items = inspectReviewQueue(
        db_get_declarations_awaiting_review($pdo),
        db_get_sheets_awaiting_photo_review($pdo),
        db_get_gear_awaiting_photo_review($pdo)
    );
    renderPageStart('Review queue', 'inspect', ['flash' => getFlash(), 'subnav' => inspectSubnavHtml('queue')]);
    echo renderInspectQueueHtml($items);
    renderPageEnd();
}
```

In `css/hub.css`, after the `/* Inspector */` rules:

```css
.inspect-queue { list-style: none; padding: 6px 20px; }
.inspect-queue .hub-line { gap: 12px; }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar`
Expected: OK.

- [ ] **Step 5: Commit**

```bash
git add inspect-page.php inspect.php css/hub.css tests/InspectPageTest.php tests/InspectSourceTest.php
git commit -m "feat(hub): review queue of declarations and car and gear photo sets, oldest first"
```

---
### Task 9: Admin is the back office

**Files:**
- Modify: `admin.php`. Replace the header, requires and router. Add event "Going" counts and the admin tabs.
- Modify: `admin-feedback.php` and `admin-season-links.php`. Switch to the admin tabs and the `'admin'` section.
- Modify: `roles.php`. Delete `ADMIN_INSPECTOR_ACTIONS` and `adminActionMinRole()`.
- Modify: `view_helpers.php`. Delete `navItem()` and `renderAdminNav()`.
- Test: create `tests/AdminSourceTest.php`. Delete `tests/AdminNavTest.php`. Modify `tests/RolesTest.php`.

**Interfaces:**
- Consumes: `adminSubnavHtml()` (Task 4), `adminMovedActionUrl()` (Tasks 5–7), `db_count_event_plans()` (Task 2)
- Produces:
  - `admin.php` is admin-only after the moved-action redirects. Its default action is `users`.
  - `renderEventsPage(array $events, array $going, string $csrf, ?array $flash)`

- [ ] **Step 1: Write the failing tests**

`tests/AdminSourceTest.php`:

```php
<?php
// wcma-calculator/tests/AdminSourceTest.php
//
// Source-level guards for the Admin back office (admin.php needs config.php, so it cannot run under PHPUnit).
use PHPUnit\Framework\TestCase;

final class AdminSourceTest extends TestCase
{
    private function src(string $file): string {
        return str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../' . $file));
    }

    public function testAdminIsAdminOnlyAfterTheMovedRedirects(): void
    {
        $admin = $this->src('admin.php');
        $moved = strpos($admin, '$movedTo = adminMovedActionUrl(');
        $gate = strpos($admin, "require_role('admin');");
        $router = strpos($admin, 'switch ($action) {');
        $this->assertNotFalse($moved);
        $this->assertNotFalse($gate);
        $this->assertNotFalse($router);
        $this->assertLessThan($gate, $moved);
        $this->assertLessThan($router, $gate);
        foreach (['requireAuth(', 'adminActionMinRole(', 'renderAdminNav(', 'TECH_EMAIL', "/admin-tech-sheets.php'", "/admin-gear.php'"] as $gone) {
            $this->assertStringNotContainsString($gone, $admin, $gone);
        }
    }

    public function testBackOfficePagesUseTheAdminTabs(): void
    {
        $expect = [
            'admin.php' => ["adminSubnavHtml('users')", "adminSubnavHtml('events')", "adminSubnavHtml('settings')"],
            'admin-feedback.php' => ["adminSubnavHtml('feedback')"],
            'admin-season-links.php' => ["adminSubnavHtml('season-links')"],
        ];
        foreach ($expect as $file => $needles) {
            $src = $this->src($file);
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $src, $file);
            }
            $this->assertStringNotContainsString("'staff')", $src, $file);
        }
    }

    public function testEventsListShowsHowManyCarsAreGoing(): void
    {
        $admin = $this->src('admin.php');
        $this->assertStringContainsString('db_count_event_plans($pdo)', $admin);
        $this->assertStringContainsString('<th>Going</th>', $admin);
    }

    public function testNoPageStillUsesTheOldAdminNav(): void
    {
        foreach (glob(__DIR__ . '/../*.php') as $f) {
            $src = file_get_contents($f);
            $this->assertStringNotContainsString('renderAdminNav(', $src, basename($f));
            $this->assertStringNotContainsString('adminActionMinRole(', $src, basename($f));
        }
    }
}
```

In `tests/RolesTest.php`, delete the method `testInspectorsGetEventDayWorkAndAdminsKeepTheBackOffice()`. Its rules now live in `testInspectorSectionIsOpenToInspectorsExceptDeclarationHousekeeping()`.

Delete `tests/AdminNavTest.php`. `LayoutTest::testSectionTabsMarkTheCurrentTab` now covers the admin tabs: `git rm tests/AdminNavTest.php`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter AdminSourceTest`
Expected: FAIL. `admin.php` still uses `requireAuth(`, `renderAdminNav(` and `TECH_EMAIL`.

- [ ] **Step 3: Rewrite the top of `admin.php`**

Replace everything from the first line of `admin.php` down to and including the router's closing `}` with the code below. The router is the `switch ($action) { … }` that ends just before `function handleUsersList`.

```php
<?php
// wcma-calculator/admin.php — the Admin back office (spec §5): Users & roles, Events, Season links,
// Settings and Feedback. Admins only. Inspector work (classing, tech sheets, gear) lives in inspect.php;
// old admin.php links to it are redirected by adminMovedActionUrl() (roles.php).
require __DIR__ . '/session_bootstrap.php';
date_default_timezone_set('America/Denver');

require __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/feedback-lib.php';
require __DIR__ . '/admin-feedback.php';
require __DIR__ . '/season-links-lib.php';
require __DIR__ . '/admin-season-links.php';

$pdo = db_connect();
db_init($pdo);

$action = $_GET['action'] ?? 'users';
$movedTo = adminMovedActionUrl(is_string($action) ? $action : '', $_GET);
if ($movedTo !== null) { header('Location: ' . $movedTo); exit; }
require_role('admin');

/** POST-only and CSRF-checked; otherwise back to $back. */
function adminRequirePost(string $back): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $back); exit; }
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
}

$postId = is_scalar($_POST['id'] ?? null) ? (int)$_POST['id'] : 0;

switch ($action) {
    case 'set-role':
        adminRequirePost('admin.php?action=users');
        handleSetRole($pdo, $postId, (string)($_POST['role'] ?? ''));
        break;

    case 'set-name':
        adminRequirePost('admin.php?action=users');
        handleSetName($pdo, $postId, (string)($_POST['name'] ?? ''));
        break;

    case 'deactivate':
        adminRequirePost('admin.php?action=users');
        handleSetActive($pdo, $postId, false);
        break;

    case 'activate':
        adminRequirePost('admin.php?action=users');
        handleSetActive($pdo, $postId, true);
        break;

    case 'events':
        handleEventsList($pdo);
        break;

    case 'event-create':
        adminRequirePost('admin.php?action=events');
        handleEventCreate($pdo);
        break;

    case 'event-update':
        adminRequirePost('admin.php?action=events');
        handleEventUpdate($pdo, $postId);
        break;

    case 'event-deactivate':
        adminRequirePost('admin.php?action=events');
        handleEventSetActive($pdo, $postId, false);
        break;

    case 'event-activate':
        adminRequirePost('admin.php?action=events');
        handleEventSetActive($pdo, $postId, true);
        break;

    case 'settings':
        handleSettings($pdo);
        break;

    case 'settings-update':
        adminRequirePost('admin.php?action=settings');
        handleSettingsUpdate($pdo);
        break;

    case 'feedback':
        handleFeedbackList($pdo);
        break;

    case 'feedback-view':
        handleFeedbackView($pdo, is_scalar($_GET['id'] ?? null) ? (int)$_GET['id'] : 0);
        break;

    case 'feedback-status':
        adminRequirePost('admin.php?action=feedback');
        handleFeedbackStatus($pdo, $postId);
        break;

    case 'feedback-retry':
        adminRequirePost('admin.php?action=feedback');
        handleFeedbackRetry($pdo, $postId);
        break;

    case 'season-links':
        handleSeasonLinksList($pdo);
        break;

    case 'season-link-save':
        adminRequirePost('admin.php?action=season-links');
        handleSeasonLinkSave($pdo, $postId);
        break;

    case 'season-link-delete':
        adminRequirePost('admin.php?action=season-links');
        handleSeasonLinkDelete($pdo, $postId);
        break;

    default:   // 'users'
        handleUsersList($pdo);
}
```

`adminRequirePost()` is declared at the top level before the switch, so it exists when the switch runs.

Before you delete the old header, check the feedback and season-links handlers:
- `grep -n "function handle" admin-feedback.php admin-season-links.php` must list only functions called above.
- `grep -n "TECH_EMAIL\|TECH_NAME\|PHPMailer" admin.php admin-feedback.php admin-season-links.php` must print nothing once you are done.

- [ ] **Step 4: Tabs, section and Events counts**

In `admin.php`:
- Change `renderSiteHeader('Manage Users', renderAdminNav('users', (string)(current_user()['role'] ?? 'user')), 'staff');` to `renderSiteHeader('Users & roles', adminSubnavHtml('users'), 'admin');`.
- Change `renderSiteHeader('Events', renderAdminNav('events', (string)(current_user()['role'] ?? 'user')), 'staff');` to `renderSiteHeader('Events', adminSubnavHtml('events'), 'admin');`.
- Change `renderSiteHeader('Settings', renderAdminNav('settings', (string)(current_user()['role'] ?? 'user')), 'staff');` to `renderSiteHeader('Settings', adminSubnavHtml('settings'), 'admin');`.
- Change the `<title>Manage Users — WCMA Admin</title>` line to `<title>Users &amp; roles — WCMA Admin</title>`.
- Replace `handleEventsList()`:
  ```php
  function handleEventsList(PDO $pdo): void {
      renderEventsPage(db_get_all_events($pdo), db_count_event_plans($pdo), generateCsrfToken(), getFlash());
  }
  ```
- Change the signature `function renderEventsPage(array $events, string $csrf, ?array $flash): void {` to `function renderEventsPage(array $events, array $going, string $csrf, ?array $flash): void {`.
- In its table, change the header row to `<thead><tr><th>Date</th><th>Name</th><th>Location</th><th>Going</th><th>Status</th><th>Actions</th></tr></thead>`, and the empty row's `colspan="5"` to `colspan="6"`.
- After the Location cell `<td><?= h($e['location'] ?? '—') ?></td>`, add:
  ```php
          <?php $n = (int)($going[(int)$e['id']] ?? 0); ?>
          <td><?= $n ?> <?= $n === 1 ? 'car' : 'cars' ?></td>
  ```
- Change the deactivate confirm text to `data-confirm="Deactivate <?= h($e['name']) ?>? Competitors won't be able to tag it or pick it for new tech sheets."`.

In `admin-feedback.php`:
- Change `renderSiteHeader('Feedback', renderAdminNav('feedback', (string)(current_user()['role'] ?? 'user')), 'staff');` to `renderSiteHeader('Feedback', adminSubnavHtml('feedback'), 'admin');`.
- In the feedback detail page, change the section argument of `renderSiteHeader('Feedback #' . (int)$f['id'], '<a href="admin.php?action=feedback">← Back to list</a>', 'staff');` from `'staff'` to `'admin'`.
- Change the header comment's "which provides requireAuth() and the routing" to "which provides the admin gate and the routing".

In `admin-season-links.php`, change `renderSiteHeader('Season Links', renderAdminNav('season-links', (string)(current_user()['role'] ?? 'user')), 'staff');` to `renderSiteHeader('Season links', adminSubnavHtml('season-links'), 'admin');`.

- [ ] **Step 5: Remove the old helpers**

- In `roles.php`, delete `ADMIN_INSPECTOR_ACTIONS` (with its doc comment) and `adminActionMinRole()`. Update the file's header comment to: `// Pure role helpers (no session, no DB). user < inspector < admin. Inspectors work in inspect.php (classing, car tech, gear); admins also run the admin.php back office.`
- In `view_helpers.php`, delete `navItem()` and `renderAdminNav()` together with their doc comments.
- Run `grep -rn "'staff'" --include=*.php .`. Expected: no output.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php phpunit.phar`, then `for f in *.php; do php -l "$f" >/dev/null || echo "LINT FAIL $f"; done`.
Expected: OK, and no lint failures.

- [ ] **Step 7: Commit**

```bash
git add admin.php admin-feedback.php admin-season-links.php roles.php view_helpers.php tests/AdminSourceTest.php tests/RolesTest.php
git commit -m "feat(hub): admin.php is the back office: admin-only, admin tabs, events show who is going"
```

---

### Task 10: Seed a tagged car, then end-to-end verification

**Files:**
- Modify: `hub-db-tools.php`. The seed tags #42 for Fall Sprint.
- Test: `tests/HubDbToolsTest.php` (modify)
- Create (untracked): `scratch/hub4-e2e.js`, `scratch/hub4-prepend.php`, and the run logs

**Interfaces:**
- Consumes: everything above.

- [ ] **Step 1: Seed a car that is tagged but has no sheet**

In `tests/HubDbToolsTest.php`, add at the end of `testSeedCreatesAUsableHub()`:

```php
        $fall = (int)$pdo->query("SELECT id FROM events WHERE name = 'Fall Sprint'")->fetchColumn();
        $this->assertSame(['17', '42'], array_column(db_get_event_roster_cars($pdo, $fall), 'car_number'));
        $this->assertSame(1, $summary['event_plans']);
```

Run `php phpunit.phar --filter HubDbToolsTest`. Expected: FAIL, because #42 is not on the roster.

In `hub-db-tools.php`, `hubSeed()`:
- directly after the two `$declare(...)` calls, add:
  ```php
      db_tag_event($pdo, $jordan, $fall, $s2000);   // on the roster with no sheet yet
  ```
- change the return line to:
  ```php
      return ['users' => 3, 'cars' => 2, 'events' => 2, 'tech_sheets' => 1, 'season_links' => 3, 'event_plans' => 1];
  ```

Run `php phpunit.phar`. Expected: OK. Commit:

```bash
git add hub-db-tools.php tests/HubDbToolsTest.php
git commit -m "chore(hub): seed tags #42 for Fall Sprint so the roster has a car without a sheet"
```

- [ ] **Step 2: Run the automated suites**

- `php phpunit.phar` → OK
- `node --test tests/js/*.test.js` → all pass
- `for f in *.php; do php -l "$f" >/dev/null || echo "LINT FAIL $f"; done` → no output

- [ ] **Step 3: Reset, seed and serve**

Run these in order:
1. `php reset-hub-db.php --confirm`
2. `php seed-hub-db.php`
3. `php -d auto_prepend_file=../scratch/hub4-prepend.php -S localhost:8140 > ../scratch/hub4-server.log 2>&1`

`scratch/hub4-prepend.php` defines `WCMA_MAIL_LOG` as `C:/dev/wcmaclasscalc/scratch/hub4-mail.log`, the same way `scratch/hub3-prepend.php` does.

- [ ] **Step 4: Browser checks**

Use Playwright, following `scratch/hub3-e2e.js`: the same `require` path, and the `signIn()` and `fillTechSheetCommon()` helpers. Check at desktop 1280px and phone 390px. The accounts are from the seed: `inspector@example.com` (Ivy Inspector), `jordan@example.com`, and the bootstrap admin. The password is `password123`.

1. **Nav and gates:**
   - Ivy sees **Inspector** after a divider, and no **Admin**. The admin sees both. Jordan sees neither.
   - Ivy at `admin.php` gets a 403 page inside the layout: hub header, "You don't have access to this page", and a **Go to Home** button.
   - Jordan at `inspect.php` gets the same page.
   - Signed out, `inspect.php` goes to the sign-in page.
2. **Roster** (Ivy, `inspect.php`):
   - The page opens on Fall Sprint with rows #17 (sheet submitted) and #42 ("No sheet yet").
   - "No tech sheet yet (1)" shows only #42.
   - #42's gear chip is Jordan Lee's record, and #42 has no "Create and accept gear in person" button.
   - At 390px, the rows stack and the page does not scroll sideways (`document.documentElement.scrollWidth <= 390`).
3. **Old links:** each of these redirects to the matching `inspect.php` page:
   - `admin.php?action=tech-sheet&id=1`
   - `admin.php?action=gear-record&id=1`
   - `admin.php?action=view&id=1`
   - `admin.php?action=tech-sheets`, which lands on the roster.
4. **Tech sheet review** (Ivy):
   - Open #17's sheet from the roster and accept it in person with a drawn signature.
   - The flash says it was accepted.
   - `scratch/hub4-mail.log` has a competitor message with "The scrutineer has reviewed & accepted your tech sheet." and "Reviewed by: Ivy Inspector".
   - The club copy links to `inspect.php?action=tech-sheet&id=1`.
5. **Send back a declaration:**
   - In Classing, search `s2000`. Only #42 is listed. Open it and check the history table.
   - Remove the `required` attribute from the note field (`page.$eval('#declaration-note', el => el.removeAttribute('required'))`) and submit **Send back** with only spaces. The flash says "Write a note saying what needs to change…". The status is still "With an inspector", and no new line appears in the mail log.
   - Send back with "Attach the dyno sheet." The mail log has a message to jordan@example.com with "Inspector's note: Attach the dyno sheet.", "Reviewed by: Ivy Inspector" and `calculator.php?car=`.
6. **The competitor responds:**
   - Jordan's Garage shows #42 as "Needs changes" with the note, and Home lists "Your class declaration for #42 needs changes".
   - **Re-declare class** opens the calculator pre-filled. After submitting, the Garage shows "With an inspector".
7. **Superseded while open:**
   - Ivy opens the new #42 declaration in one tab.
   - Jordan re-declares again.
   - Ivy clicks **Accept** in the stale tab. The flash says "A newer declaration has replaced this one…", and nothing is emailed.
   - Ivy opens the newest declaration from the history and accepts it. The mail log has "The scrutineer has reviewed & accepted your class declaration." and "Reviewed by: Ivy Inspector". Jordan's Garage shows #42 as "Accepted".
8. **Review queue:**
   - Before step 7's accept, the queue lists the #42 declaration with a **Review** link. Afterwards it says "Nothing is waiting for review." (unless other seed data is waiting).
   - Submit gear photos for Jordan Lee as Jordan (Drivers → Add photos, the `scratch/hub3-e2e.js` photo helper). The queue then lists "Gear pre-tech photos: Jordan Lee", which links to `inspect.php?action=gear-record&id=…#gear-review`.
9. **Admin-only housekeeping:**
   - Ivy's declaration page has no **Edit contact details** and no **Delete**.
   - Posting to `inspect.php?action=declaration-delete` as Ivy returns the 403 page. Use `page.evaluate` with a form carrying her CSRF token.
   - As admin, delete an older #42 declaration that no sheet uses. The car's current declaration is unchanged.
   - Deleting #17's declaration is refused, because it is on a sheet.
   - Bulk delete reports how many it skipped.
10. **Delete the current declaration:**
    - As admin, for a car with two declarations and no sheet, delete the newest one.
    - Jordan's Garage then shows the previous declaration as current. Its status is "Accepted" if it had been accepted, otherwise "With an inspector".
11. **Admin back office:**
    - `admin.php` opens on **Users & roles**, with tabs for Events, Season links, Settings and Feedback.
    - Events shows Fall Sprint as "1 car" going.
    - Changing a role, saving a season link and saving settings still work.
12. **Gear tab:** `inspect.php?action=gear` lists this season's records, and accepting one in person emails "The scrutineer has reviewed & accepted your gear.".
13. **The server log** has no PHP warnings, notices or fatal errors: `grep -iE "warning|notice|fatal|deprecated" ../scratch/hub4-server.log` prints nothing.

- [ ] **Step 5: Report**

Record pass or fail for each item, with any error text, in the hand-off. Phase 4 is not complete while any item fails. A defect gets a fix commit with a test before the phase is merged.

- [ ] **Step 6: Merge locally (do not push)**

Only once every item passes: `git checkout main && git merge --no-ff hub-phase4`. **Do not push.** Pushing deploys to IONOS, and the user tests all five phases together first.

Then update the hub-rollout memory to say Phase 4 is merged and Phase 5 (Reminders) is next.

---

## Self-review notes

- **Spec coverage (§5):**
  - roles and `require_role()`: Tasks 4, 5 and 9
  - Accept, and Send back with a required note, each emailing through the existing helpers: Tasks 1 and 7
  - the binding headline and "Reviewed by: First Last": Task 1
  - the competitor responds by re-declaring, with the calculator pre-filled from the queried declaration and the previous one superseded: existing behaviour, checked in Task 10 items 6 and 7
  - not blocking: nothing in this plan checks declaration status when a sheet is submitted
  - Event roster with its four filters, and the existing in-person accept and one-tap gear reused: Task 5
  - Review queue, oldest first, reusing the existing review cards: Task 8
  - Classing with search, filters, detail, and admin-only delete, bulk delete and edit contact: Task 7
  - Gear: Task 6
  - Admin section: Users & roles, Events with tagged-car counts, Season links, Settings and Feedback: Task 9
  - §9's in-layout 403: Task 4
- **Already done in earlier phases (not repeated here):**
  - the role dropdown, the last-admin guard and the first-and-last-name rule for staff roles (Phase 1)
  - the "received" headlines
  - `TECH_ACCEPTANCE_DISCLAIMER` is already gone (`AdminTechCopyTest` guards it)
  - the tech sheet and gear "accepted" headlines and reviewer lines
- **Left as they are (not in this phase's scope):**
  - the Users, Events, Settings, Feedback and Season links pages keep their `renderSiteHeader()` shells, with the new admin tabs
  - the tech sheet and gear review files keep their `admin-*` names.
