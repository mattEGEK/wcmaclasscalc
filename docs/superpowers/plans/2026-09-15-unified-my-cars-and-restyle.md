# Unified My Cars & Site Restyle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Retire the client-side `localStorage` saved-configs feature in favor of a server-side `drafts` table merged with submission history into one "My Cars" page, add account-linking for past anonymous submissions, add an anonymous-submission account-creation nudge, and restyle `auth.php`/`account.php`/`admin.php` to match `car-classing.html`'s existing design language.

**Architecture:** Extend the existing `db.php` with a `drafts` table and an email-based submission-linking function. Consolidate the three PHP entry points' triplicated `h()`/CSRF/flash helper functions and inline `<style>` blocks into one shared `view_helpers.php` and the existing `calculator.css`, so every page (calculator, login, My Cars, admin) shares one visual system. Rebuild `account.php`'s UI once, in the target style, rather than building it plain then re-skinning.

**Tech Stack:** PHP 8.x (PDO/SQLite), vanilla JS (ES6 modules, matching `js/ui-controller.js`'s existing style), the existing `calculator.css` design system (CSS custom properties, `.btn`/`.form-messages`/`.modal-*` classes).

**Spec:** `docs/superpowers/specs/2026-09-15-unified-my-cars-design.md` (architectural: drafts, linking, incentive banner). The restyle is bounded work approved in chat (no separate spec file) — reuse `calculator.css`'s existing classes and variables, add new shared components to it, keep the mobile breakpoints it already has (768px/480px).

## Global Constraints

- No Composer, no frameworks — plain PHP functions/files, consistent with the existing codebase.
- All DB access via PDO prepared statements.
- All output escaped with `htmlspecialchars()` / the shared `h()` helper — never `innerHTML` with unescaped user data (the final review of the prior feature caught exactly this bug once already).
- CSRF validation on every state-changing POST (draft-save, draft-delete, plus the existing resend/delete/promote/demote).
- Draft/submission ownership scoped at the SQL layer (`WHERE user_id = :user_id`), not just in PHP after fetch.
- Account-linking on registration is automatic and unverified — this is a deliberate, already-accepted risk (see spec's Security section), not something to add verification friction to.
- Tests run via `php phpunit.phar` from `wcma-calculator/` — PHP 8.x CLI (already installed in this environment).
- Restyle must reuse `calculator.css`'s existing CSS custom properties (`--primary-color`, `--secondary-color`, `--border-radius`, `--spacing-unit`, `--success-color`, `--error-color`, `--warning-color`) rather than introducing new colors, and must not remove or break any existing calculator-page styling.

---

### Task 1: `drafts` table + CRUD functions in `db.php`

**Files:**
- Modify: `wcma-calculator/db.php`
- Test: `wcma-calculator/tests/DbDraftsTest.php`

**Interfaces:**
- Consumes: `make_temp_pdo()`, `db_create_user()` (existing test helpers/functions)
- Produces: `db_upsert_draft(PDO $pdo, int $user_id, string $label, string $form_data_json): int`
- Produces: `db_get_user_drafts(PDO $pdo, int $user_id): array` — ordered `updated_at DESC`
- Produces: `db_get_user_draft(PDO $pdo, int $user_id, int $id): ?array` — ownership-scoped
- Produces: `db_count_user_drafts(PDO $pdo, int $user_id): int`
- Produces: `db_delete_draft(PDO $pdo, int $id, int $user_id): void` — ownership-scoped (no-op if `id` doesn't belong to `user_id`)

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/DbDraftsTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

final class DbDraftsTest extends TestCase
{
    private function makeUser(PDO $pdo, string $email): int {
        return db_create_user($pdo, ['email' => $email, 'name' => 'Test', 'password_hash' => 'x', 'google_id' => null]);
    }

    public function testUpsertDraftCreatesNew(): void
    {
        $pdo = make_temp_pdo();
        $userId = $this->makeUser($pdo, 'a@example.com');

        $id = db_upsert_draft($pdo, $userId, '2020 Mazda MX-5', '{"year":"2020"}');

        $draft = db_get_user_draft($pdo, $userId, $id);
        $this->assertNotNull($draft);
        $this->assertSame('2020 Mazda MX-5', $draft['label']);
        $this->assertSame('{"year":"2020"}', $draft['form_data']);
    }

    public function testUpsertDraftUpdatesExistingSameLabel(): void
    {
        $pdo = make_temp_pdo();
        $userId = $this->makeUser($pdo, 'b@example.com');

        $id1 = db_upsert_draft($pdo, $userId, 'My Miata', '{"hp":"100"}');
        $id2 = db_upsert_draft($pdo, $userId, 'My Miata', '{"hp":"150"}');

        $this->assertSame($id1, $id2);
        $draft = db_get_user_draft($pdo, $userId, $id1);
        $this->assertSame('{"hp":"150"}', $draft['form_data']);
        $this->assertSame(1, db_count_user_drafts($pdo, $userId));
    }

    public function testUpsertDraftDifferentUsersSameLabelStaySeparate(): void
    {
        $pdo = make_temp_pdo();
        $userA = $this->makeUser($pdo, 'c@example.com');
        $userB = $this->makeUser($pdo, 'd@example.com');

        $idA = db_upsert_draft($pdo, $userA, 'My Car', '{"owner":"A"}');
        $idB = db_upsert_draft($pdo, $userB, 'My Car', '{"owner":"B"}');

        $this->assertNotSame($idA, $idB);
        $this->assertSame('{"owner":"A"}', db_get_user_draft($pdo, $userA, $idA)['form_data']);
        $this->assertSame('{"owner":"B"}', db_get_user_draft($pdo, $userB, $idB)['form_data']);
    }

    public function testGetUserDraftsOrderedByUpdatedAtDesc(): void
    {
        $pdo = make_temp_pdo();
        $userId = $this->makeUser($pdo, 'e@example.com');

        db_upsert_draft($pdo, $userId, 'First', '{}');
        db_upsert_draft($pdo, $userId, 'Second', '{}');

        $drafts = db_get_user_drafts($pdo, $userId);
        $this->assertCount(2, $drafts);
        $this->assertSame('Second', $drafts[0]['label']);
    }

    public function testGetUserDraftOwnershipScoped(): void
    {
        $pdo = make_temp_pdo();
        $owner = $this->makeUser($pdo, 'f@example.com');
        $other = $this->makeUser($pdo, 'g@example.com');

        $id = db_upsert_draft($pdo, $owner, 'Owned', '{}');

        $this->assertNotNull(db_get_user_draft($pdo, $owner, $id));
        $this->assertNull(db_get_user_draft($pdo, $other, $id));
    }

    public function testDeleteDraftOwnershipScoped(): void
    {
        $pdo = make_temp_pdo();
        $owner = $this->makeUser($pdo, 'h@example.com');
        $other = $this->makeUser($pdo, 'i@example.com');

        $id = db_upsert_draft($pdo, $owner, 'Delete Me', '{}');

        db_delete_draft($pdo, $id, $other);
        $this->assertNotNull(db_get_user_draft($pdo, $owner, $id), 'Should not delete another user\'s draft');

        db_delete_draft($pdo, $id, $owner);
        $this->assertNull(db_get_user_draft($pdo, $owner, $id));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
cd wcma-calculator
php phpunit.phar
```

Expected: FAIL — `db_upsert_draft()` etc. undefined, `drafts` table doesn't exist.

- [ ] **Step 3: Add the `drafts` table and CRUD functions to `db.php`**

Inside `db_init()`, after the `password_resets` table creation block (and before the `user_id` migration guard), add:

```php
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS drafts (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER NOT NULL,
            label       TEXT,
            form_data   TEXT NOT NULL,
            updated_at  DATETIME NOT NULL
        )
    ");
```

Add the CRUD functions after `db_delete_submission()` (before the `// ── Users ──` section):

```php
// ── Drafts ────────────────────────────────────────────────────────────────────

function db_upsert_draft(PDO $pdo, int $user_id, string $label, string $form_data_json): int {
    $stmt = $pdo->prepare("SELECT id FROM drafts WHERE user_id = :user_id AND label = :label");
    $stmt->execute([':user_id' => $user_id, ':label' => $label]);
    $existing = $stmt->fetch();
    $now = date('Y-m-d H:i:s');

    if ($existing) {
        $pdo->prepare("UPDATE drafts SET form_data = :form_data, updated_at = :updated_at WHERE id = :id")
            ->execute([':form_data' => $form_data_json, ':updated_at' => $now, ':id' => $existing['id']]);
        return (int)$existing['id'];
    }

    $pdo->prepare("INSERT INTO drafts (user_id, label, form_data, updated_at) VALUES (:user_id, :label, :form_data, :updated_at)")
        ->execute([':user_id' => $user_id, ':label' => $label, ':form_data' => $form_data_json, ':updated_at' => $now]);
    return (int)$pdo->lastInsertId();
}

function db_get_user_drafts(PDO $pdo, int $user_id): array {
    $stmt = $pdo->prepare("SELECT * FROM drafts WHERE user_id = :user_id ORDER BY updated_at DESC");
    $stmt->execute([':user_id' => $user_id]);
    return $stmt->fetchAll();
}

function db_get_user_draft(PDO $pdo, int $user_id, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM drafts WHERE id = :id AND user_id = :user_id");
    $stmt->execute([':id' => $id, ':user_id' => $user_id]);
    return $stmt->fetch() ?: null;
}

function db_count_user_drafts(PDO $pdo, int $user_id): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM drafts WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $user_id]);
    return (int)$stmt->fetchColumn();
}

function db_delete_draft(PDO $pdo, int $id, int $user_id): void {
    $pdo->prepare("DELETE FROM drafts WHERE id = :id AND user_id = :user_id")
        ->execute([':id' => $id, ':user_id' => $user_id]);
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
cd wcma-calculator
php phpunit.phar
```

Expected: PASS (all tests, including the ones from earlier features)

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/db.php wcma-calculator/tests/DbDraftsTest.php
git commit -m "feat: add drafts table and CRUD functions"
```

---

### Task 2: `db_link_submissions_by_email` in `db.php`

**Files:**
- Modify: `wcma-calculator/db.php`
- Test: `wcma-calculator/tests/DbLinkSubmissionsTest.php`

**Interfaces:**
- Consumes: `make_temp_pdo()`, `db_create_user()`, `db_insert_submission()` (existing)
- Produces: `db_link_submissions_by_email(PDO $pdo, int $user_id, string $email): int` — returns count of rows linked

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/DbLinkSubmissionsTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

final class DbLinkSubmissionsTest extends TestCase
{
    private function minimalSubmissionData(string $email, ?int $user_id = null): array {
        return [
            ':submitted_at' => date('Y-m-d H:i:s'),
            ':name' => 'Test Driver', ':email' => $email,
            ':year' => '2020', ':make' => 'Mazda', ':model' => 'MX-5',
            ':comments' => null,
            ':competition_weight' => 2200, ':declared_hp' => 150, ':dyno_hp' => null,
            ':chassis_display' => null, ':body_mods_display' => null,
            ':transmission_display' => null, ':drivetrain_display' => null, ':tires_display' => null,
            ':brake_suspension' => '[]',
            ':chassis_value' => 0, ':body_mods_value' => 0, ':transmission_value' => 0,
            ':drivetrain_value' => 0, ':tires_value' => 0, ':brake_suspension_value' => 0,
            ':weight_factor' => 0, ':modification_factor' => 0,
            ':base_ratio' => 14.67, ':modified_ratio' => 14.67, ':calculated_class' => 'IT1',
            ':user_id' => $user_id,
        ];
    }

    public function testLinksAnonymousSubmissionsMatchingEmail(): void
    {
        $pdo = make_temp_pdo();
        $subId = db_insert_submission($pdo, $this->minimalSubmissionData('driver@example.com'));

        $userId = db_create_user($pdo, ['email' => 'driver@example.com', 'name' => 'Driver', 'password_hash' => 'x', 'google_id' => null]);
        $linked = db_link_submissions_by_email($pdo, $userId, 'driver@example.com');

        $this->assertSame(1, $linked);
        $sub = db_get_submission($pdo, $subId);
        $this->assertSame($userId, $sub['user_id']);
    }

    public function testDoesNotLinkSubmissionsAlreadyOwned(): void
    {
        $pdo = make_temp_pdo();
        $existingOwner = db_create_user($pdo, ['email' => 'owner@example.com', 'name' => 'Owner', 'password_hash' => 'x', 'google_id' => null]);
        $subId = db_insert_submission($pdo, $this->minimalSubmissionData('shared@example.com', $existingOwner));

        $newUser = db_create_user($pdo, ['email' => 'shared@example.com', 'name' => 'New', 'password_hash' => 'x', 'google_id' => null]);
        $linked = db_link_submissions_by_email($pdo, $newUser, 'shared@example.com');

        $this->assertSame(0, $linked);
        $sub = db_get_submission($pdo, $subId);
        $this->assertSame($existingOwner, $sub['user_id']);
    }

    public function testCaseInsensitiveMatch(): void
    {
        $pdo = make_temp_pdo();
        db_insert_submission($pdo, $this->minimalSubmissionData('Driver@Example.com'));

        $userId = db_create_user($pdo, ['email' => 'driver@example.com', 'name' => 'Driver', 'password_hash' => 'x', 'google_id' => null]);
        $linked = db_link_submissions_by_email($pdo, $userId, 'driver@example.com');

        $this->assertSame(1, $linked);
    }

    public function testReturnsZeroWhenNoneMatch(): void
    {
        $pdo = make_temp_pdo();
        $userId = db_create_user($pdo, ['email' => 'nomatch@example.com', 'name' => 'Nobody', 'password_hash' => 'x', 'google_id' => null]);

        $linked = db_link_submissions_by_email($pdo, $userId, 'nomatch@example.com');

        $this->assertSame(0, $linked);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
cd wcma-calculator
php phpunit.phar
```

Expected: FAIL — `db_link_submissions_by_email()` undefined.

- [ ] **Step 3: Implement in `db.php`**

Add after `db_get_user_submission()`:

```php
function db_link_submissions_by_email(PDO $pdo, int $user_id, string $email): int {
    $stmt = $pdo->prepare("UPDATE submissions SET user_id = :user_id WHERE user_id IS NULL AND email = :email COLLATE NOCASE");
    $stmt->execute([':user_id' => $user_id, ':email' => $email]);
    return $stmt->rowCount();
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
cd wcma-calculator
php phpunit.phar
```

Expected: PASS (all tests)

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/db.php wcma-calculator/tests/DbLinkSubmissionsTest.php
git commit -m "feat: add db_link_submissions_by_email for account-linking"
```

---

### Task 3: Shared `view_helpers.php` + restyle CSS additions

**Files:**
- Create: `wcma-calculator/view_helpers.php`
- Modify: `wcma-calculator/css/calculator.css` (append at end of file, after line 1075)

**Interfaces:**
- Produces: `generateCsrfToken(): string`, `validateCsrfToken(string $token): bool`, `setFlash(string $message, string $type): void`, `getFlash(): ?array`, `h(string $s): string` — identical behavior to the (currently triplicated) versions in `auth.php`/`account.php`/`admin.php`
- Produces: `renderSiteHeader(string $title, string $navHtml = ''): void` — echoes a `<header class="page-header">` block with the WCMA logo, matching `car-classing.html`'s header visually but sized for sub-pages
- Produces CSS classes (all in `calculator.css`): `.page-header`, `.page-header .header-content`, `.wcma-logo-sm`, `.account-nav` + `.account-nav a`, `.auth-page`, `.auth-box`, `.auth-links`, `.btn-block`, `.btn-google`, `.data-table` (+ `th`/`td`/`.empty-row`/`.actions` under it), `.badge-ok`, `.badge-fail`, `.badge-draft`, `.badge-admin`, `.detail-layout`, `.detail-card`, `.detail-table`, `.calc-table`, `.class-badge`, `.file-thumb`, `.file-link`

This task only creates new, unused-so-far code — no existing page is modified yet, so there's nothing to manually verify beyond a PHP lint and a visual sanity check isn't possible until Task 4 consumes it. Later tasks (4, 6, 7, 8) are where this gets exercised.

- [ ] **Step 1: Create `wcma-calculator/view_helpers.php`**

```php
<?php
function generateCsrfToken(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function setFlash(string $message, string $type): void {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function getFlash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function renderSiteHeader(string $title, string $navHtml = ''): void {
    ?>
<header class="page-header">
  <div class="header-content">
    <img src="https://www.wcma.ca/wp-content/uploads/WCMA-Logo.png" alt="WCMA Logo" class="wcma-logo-sm">
    <h1><?= h($title) ?></h1>
  </div>
  <nav><?= $navHtml ?></nav>
</header>
<?php
}
```

- [ ] **Step 2: Verify it lints cleanly**

```bash
cd wcma-calculator
php -l view_helpers.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Append restyle CSS to `wcma-calculator/css/calculator.css`**

Append this block at the end of the file (after the existing final `@media (prefers-reduced-motion: reduce)` block):

```css
/* ── Shared sub-page header (auth/account/admin pages) ──────────────────────── */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: calc(var(--spacing-unit) * 0.5);
    margin-bottom: var(--spacing-unit);
    padding-bottom: calc(var(--spacing-unit) * 0.5);
    border-bottom: 2px solid var(--border-color);
}

.page-header .header-content {
    display: flex;
    align-items: center;
    gap: calc(var(--spacing-unit) * 0.75);
}

.wcma-logo-sm {
    height: 40px;
    width: auto;
    max-width: 120px;
    object-fit: contain;
}

.page-header h1 {
    color: var(--primary-color);
    font-size: 1.4rem;
    margin: 0;
}

.page-header nav a {
    color: var(--secondary-color);
    text-decoration: none;
    margin-left: var(--spacing-unit);
    font-size: 0.9rem;
    font-weight: 600;
}

.page-header nav a:hover {
    text-decoration: underline;
}

/* ── Account nav (login state links in the calculator page's own header) ────── */
.account-nav {
    margin-top: calc(var(--spacing-unit) * 0.5);
    font-size: 0.85rem;
}

.account-nav a {
    color: var(--secondary-color);
    text-decoration: none;
    font-weight: 600;
}

.account-nav a:hover {
    text-decoration: underline;
}

/* ── Auth pages (login/register/forgot/reset password) ──────────────────────── */
.auth-page {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
}

.auth-box {
    max-width: 400px;
    width: 100%;
}

.auth-box h1 {
    text-align: center;
    margin-bottom: calc(var(--spacing-unit) * 1.5);
}

.auth-links {
    margin-top: var(--spacing-unit);
    font-size: 0.85rem;
    text-align: center;
}

.auth-links a {
    color: var(--secondary-color);
}

.btn-block {
    width: 100%;
    display: block;
    text-align: center;
    text-decoration: none;
    box-sizing: border-box;
}

.btn-google {
    background: white;
    color: var(--text-color);
    border: 1px solid var(--border-color);
}

.btn-google:hover {
    background: #f5f5f5;
}

/* ── Data tables (admin submissions/users, My Cars list) ─────────────────────── */
.data-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    border-radius: var(--border-radius);
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
    margin-bottom: var(--spacing-unit);
}

.data-table th {
    background: var(--primary-color);
    color: white;
    padding: calc(var(--spacing-unit) * 0.7) var(--spacing-unit);
    text-align: left;
    font-size: 0.85rem;
    white-space: nowrap;
}

.data-table th a {
    color: inherit;
    text-decoration: none;
}

.data-table td {
    padding: calc(var(--spacing-unit) * 0.65) var(--spacing-unit);
    border-bottom: 1px solid var(--border-color);
    font-size: 0.9rem;
    vertical-align: middle;
}

.data-table tr:last-child td {
    border-bottom: none;
}

.data-table tr:hover td {
    background: #f7f9fc;
}

.data-table .empty-row {
    text-align: center;
    color: #888;
    padding: 2rem;
}

.data-table .actions a {
    color: var(--secondary-color);
    margin-right: 0.6rem;
    font-size: 0.85rem;
    text-decoration: none;
}

.data-table .actions a:hover {
    text-decoration: underline;
}

.data-table .actions form {
    display: inline;
}

.data-table .actions .link-button {
    background: none;
    border: none;
    color: var(--error-color);
    cursor: pointer;
    font-size: 0.85rem;
    padding: 0;
    margin-left: 0.6rem;
    font-family: inherit;
}

.data-table .actions .link-button:hover {
    text-decoration: underline;
}

/* ── Status badges ────────────────────────────────────────────────────────────── */
.badge-ok { color: var(--success-color); font-weight: bold; }
.badge-fail { color: var(--error-color); font-weight: bold; }
.badge-draft { color: var(--warning-color); font-weight: bold; }
.badge-admin { color: var(--secondary-color); font-weight: bold; }

/* ── Detail/card layout (submission view, My Cars view) ──────────────────────── */
.detail-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: calc(var(--spacing-unit) * 1.5);
}

@media (max-width: 700px) {
    .detail-layout {
        grid-template-columns: 1fr;
    }
}

.detail-card {
    background: white;
    border-radius: var(--border-radius);
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
    padding: calc(var(--spacing-unit) * 1.2);
    margin-bottom: calc(var(--spacing-unit) * 1.5);
}

.detail-card h2 {
    margin: 0 0 var(--spacing-unit) 0;
    font-size: 1rem;
    color: var(--primary-color);
    border-bottom: 2px solid var(--primary-color);
    padding-bottom: calc(var(--spacing-unit) * 0.4);
}

.detail-table td {
    padding: 0.35rem 0.5rem;
    font-size: 0.9rem;
    vertical-align: top;
}

.detail-table td:first-child {
    font-weight: bold;
    width: 160px;
    color: #444;
}

.calc-table {
    width: 100%;
    border-collapse: collapse;
    font-family: monospace;
    font-size: 0.95rem;
}

.calc-table td {
    padding: 0.3rem 0.4rem;
}

.calc-table tr.total td {
    border-top: 2px solid #333;
    font-weight: bold;
    font-size: 1.05rem;
    padding-top: 0.5rem;
}

.class-badge {
    font-size: 1.4rem;
    font-weight: bold;
    color: var(--primary-color);
}

.file-thumb {
    max-width: 100%;
    max-height: 200px;
    border-radius: var(--border-radius);
    margin-top: 0.5rem;
    display: block;
}

.file-link {
    display: inline-block;
    margin-top: 0.4rem;
    color: var(--secondary-color);
}

/* ── Mobile adjustments for account/admin pages ──────────────────────────────── */
@media (max-width: 768px) {
    .data-table {
        display: block;
        overflow-x: auto;
        white-space: nowrap;
    }

    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .page-header nav a {
        margin-left: 0;
        margin-right: var(--spacing-unit);
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add wcma-calculator/view_helpers.php wcma-calculator/css/calculator.css
git commit -m "feat: add shared view helpers and restyle CSS components"
```

---

### Task 4: Restyle `auth.php` + register email prefill

**Files:**
- Modify: `wcma-calculator/auth.php`

**Interfaces:**
- Consumes: `view_helpers.php`'s `h()`, `generateCsrfToken()`, `validateCsrfToken()`, `setFlash()`, `getFlash()` (Task 3) — replaces the locally-declared duplicates
- Produces: no new interfaces for other tasks — this is presentation-only plus one small functional addition (email prefill)

- [ ] **Step 1: Replace the top-of-file helper declarations with a `view_helpers.php` require**

Replace (currently `auth.php:2-42`, everything from the first `require` through the `h()` function):

```php
<?php
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/config.php';

date_default_timezone_set('America/Denver');

$pdo = db_connect();
db_init($pdo);

require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function generateCsrfToken(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function setFlash(string $message, string $type): void {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function getFlash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
```

with:

```php
<?php
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require __DIR__ . '/view_helpers.php';

date_default_timezone_set('America/Denver');

$pdo = db_connect();
db_init($pdo);

require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
```

- [ ] **Step 2: Restyle `renderAuthPage()`**

Replace the entire function (currently `auth.php:44-74` in the pre-Task-4 file):

```php
function renderAuthPage(string $title, string $bodyHtml): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> — WCMA Calculator</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
<style>
  body.auth-body { display: flex; }
  .container.auth-container { display: flex; align-items: center; justify-content: center; min-height: calc(100vh - 2rem); }
</style>
</head>
<body class="auth-body">
<div class="container auth-container">
  <div class="auth-box">
    <h1><?= h($title) ?></h1>
    <?= $bodyHtml ?>
  </div>
</div>
</body>
</html><?php
}
```

This keeps the calculator page's `.container` (white card, shadow, rounded corners, max-width) as the outer frame, centers it vertically/horizontally via a small page-specific override (two rules only — everything else comes from `calculator.css`), and drops the old inline palette entirely.

- [ ] **Step 3: Update every `.error`/`.success`/`.links`/`button.primary`/`.google-btn` reference to the new shared classes**

`calculator.css` already has `.form-messages.error`/`.form-messages.success` (not `.error`/`.success` alone) and `.btn`/`.btn-primary`. Update each `$body .=` line across `handleRegister()`, `handleLogin()`, `handleForgotPassword()`, `handleResetPassword()` to use them. Replace every occurrence of:

- `'<div class="error">' . h($error) . '</div>'` → `'<div class="form-messages show error">' . h($error) . '</div>'`
- `'<div class="' . h($flash['type']) . '">' . h($flash['message']) . '</div>'` (in `handleLogin()`) → `'<div class="form-messages show ' . h($flash['type']) . '">' . h($flash['message']) . '</div>'`
- `'<div class="success">If that email is registered, a reset link has been sent.</div>'` → `'<div class="form-messages show success">If that email is registered, a reset link has been sent.</div>'`
- `'<button type="submit" class="primary">'` → `'<button type="submit" class="btn btn-primary btn-block">'` (all four occurrences: Create Account, Sign In, Send Reset Link, Reset Password)
- `'<a href="auth.php?action=google-login" class="google-btn">Sign in with Google</a>'` → `'<a href="auth.php?action=google-login" class="btn btn-google btn-block">Sign in with Google</a>'`
- `'<div class="links">'` → `'<div class="auth-links">'` (all three occurrences)

The `<label>`/`<input>` markup is unchanged — `calculator.css` already styles `input[type="text"]`, `input[type="email"]`, `input[type="password"]` generically, so no class changes are needed there.

- [ ] **Step 4: Add email prefill support to `handleRegister()`**

In `handleRegister()`, find the line building the email input:

```php
    $body .= '<label for="email">Email</label><input type="email" id="email" name="email" required value="' . h($_POST['email'] ?? '') . '">';
```

Replace with:

```php
    $body .= '<label for="email">Email</label><input type="email" id="email" name="email" required value="' . h($_POST['email'] ?? $_GET['email'] ?? '') . '">';
```

This lets a link like `auth.php?action=register&email=driver@example.com` prefill the field (used by the anonymous-submission incentive banner in Task 10), while POST data still takes priority on a validation-error re-render.

- [ ] **Step 5: Manually verify**

```bash
cd wcma-calculator
php -l auth.php
php -S localhost:8000
```

- Visit `http://localhost:8000/auth.php?action=login` → confirm it renders inside the calculator's white card style (same font, same blue `--secondary-color` accents, same button style as `car-classing.html`), not the old `#1a5490`/Arial look.
- Visit `http://localhost:8000/auth.php?action=register&email=test@example.com` → confirm the email field is prefilled.
- Trigger a login error (wrong password) → confirm it renders via `.form-messages.error`, visually consistent with the calculator page's own error styling.
- Resize the browser to a narrow (mobile) width → confirm the auth box stays readable and centered (no horizontal scroll, no cut-off content) — this exercises `calculator.css`'s existing mobile breakpoints since the box has no separate mobile CSS of its own now.

- [ ] **Step 6: Commit**

```bash
git add wcma-calculator/auth.php
git commit -m "style: restyle auth.php to match the calculator's design system"
```

---

### Task 5: Account-linking on registration

**Files:**
- Modify: `wcma-calculator/auth.php`

**Interfaces:**
- Consumes: `db_link_submissions_by_email(PDO, int, string): int` (Task 2)

- [ ] **Step 1: Call the linking function right after user creation in `handleRegister()`**

Find this block inside `handleRegister()`:

```php
        } else {
            $userId = db_create_user($pdo, [
                'email' => $email,
                'name' => $name,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'google_id' => null,
            ]);
            $user = db_find_user_by_id($pdo, $userId);
            login_user($user);
            header('Location: car-classing.html');
            exit;
        }
```

Replace with:

```php
        } else {
            $userId = db_create_user($pdo, [
                'email' => $email,
                'name' => $name,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'google_id' => null,
            ]);
            $linked = db_link_submissions_by_email($pdo, $userId, $email);
            $user = db_find_user_by_id($pdo, $userId);
            login_user($user);
            if ($linked > 0) {
                $plural = $linked === 1 ? 'submission' : 'submissions';
                setFlash("Welcome — we found {$linked} past {$plural} under this email and added them to My Cars.", 'success');
            }
            header('Location: car-classing.html');
            exit;
        }
```

Note: this flash won't currently render anywhere (car-classing.html is static and doesn't read flash messages — this is a known, already-accepted gap from the prior feature's final review, not something this task introduces or is responsible for fixing). It will render correctly once the user next visits a page that does read flash (e.g. `account.php`'s My Cars list, built in Task 7) — acceptable since the message ("we found your past submissions") is exactly what they'll see reflected in the My Cars list itself moments later anyway.

- [ ] **Step 2: Manually verify**

```bash
cd wcma-calculator
php -S localhost:8000
```

- Submit the calculator anonymously (or insert a row directly via a throwaway PHP snippet — do not commit it) with `email = 'linktest@example.com'`.
- Register a new account with that same email at `auth.php?action=register`.
- Query the SQLite DB directly (`sqlite3 data/submissions.db "SELECT id, user_id, email FROM submissions WHERE email = 'linktest@example.com';"`) → confirm `user_id` is now set to the new account's id.
- Register a second, unrelated account with a fresh email → confirm no submissions get linked (count stays 0, no crash).

- [ ] **Step 3: Commit**

```bash
git add wcma-calculator/auth.php
git commit -m "feat: link past anonymous submissions to a new account by email on registration"
```

---

### Task 6: `account.php` — draft save/list/load/delete actions

**Files:**
- Modify: `wcma-calculator/account.php`

**Interfaces:**
- Consumes: `db_upsert_draft`, `db_get_user_drafts`, `db_get_user_draft`, `db_delete_draft`, `db_count_user_drafts` (Task 1)
- Produces: routes `?action=draft-save` (POST), `?action=draft-list` (GET, JSON), `?action=draft-load&id=` (GET, JSON), `?action=draft-delete` (POST) — added to the existing switch statement, consumed by `car-classing.html`'s JS in Task 9

This task adds functionality only — the visual restyle and the "My Cars" list merge happen in Task 7, which touches the same file next. Keep the existing plain markup for now; don't restyle yet (avoids conflicting edits with Task 7 in the same pass).

- [ ] **Step 1: Add the four new switch cases**

In `account.php`'s `switch ($action)` block, add before `case 'list':`:

```php
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
```

- [ ] **Step 2: Add the four handler functions**

Add after `handleAccountFile()` at the end of the file:

```php
function handleDraftSave(PDO $pdo, array $user): void {
    $formDataJson = $_POST['form_data'] ?? '';
    $label = trim($_POST['label'] ?? '');

    $decoded = json_decode($formDataJson, true);
    if (!is_array($decoded) || $label === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid draft data.']);
        exit;
    }

    $id = db_upsert_draft($pdo, $user['id'], $label, $formDataJson);

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}

function handleDraftList(PDO $pdo, array $user): void {
    $drafts = db_get_user_drafts($pdo, $user['id']);
    $out = array_map(function (array $d): array {
        return [
            'id' => (int)$d['id'],
            'label' => $d['label'],
            'updated_at' => $d['updated_at'],
        ];
    }, $drafts);

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'drafts' => $out]);
    exit;
}

function handleDraftLoad(PDO $pdo, array $user, int $id): void {
    $draft = db_get_user_draft($pdo, $user['id'], $id);

    header('Content-Type: application/json');
    if (!$draft) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Draft not found.']);
        exit;
    }

    echo json_encode(['success' => true, 'form_data' => json_decode($draft['form_data'], true)]);
    exit;
}

function handleDraftDelete(PDO $pdo, array $user, int $id): void {
    db_delete_draft($pdo, $id, $user['id']);

    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}
```

- [ ] **Step 3: Manually verify**

```bash
cd wcma-calculator
php -S localhost:8000
```

Register/log in as a test user, then (with the session cookie carried via curl `-b`/`-c`, and a CSRF token scraped from any rendered page in the same session — `account.php` still renders `generateCsrfToken()`'s value into any of its pages):

- POST to `account.php?action=draft-save` with `label=Test+Car&form_data={"year":"2020"}&csrf_token=...` → confirm `{"success":true,"id":N}`.
- GET `account.php?action=draft-list` → confirm the draft appears.
- GET `account.php?action=draft-load&id=N` → confirm the original `form_data` comes back.
- POST `account.php?action=draft-save` again with the same `label` but different `form_data` → confirm the same `id` is returned (upsert, not a duplicate) and `draft-list` still shows one entry.
- POST `account.php?action=draft-delete` with that `id` → confirm `draft-list` now shows zero entries.
- Repeat `draft-load` as a *different* logged-in user for the first user's draft id → confirm 404 (ownership check).

- [ ] **Step 4: Commit**

```bash
git add wcma-calculator/account.php
git commit -m "feat: add draft save/list/load/delete actions to account.php"
```

---

### Task 7: `account.php` — merge into "My Cars", restyle

**Files:**
- Modify: `wcma-calculator/account.php`

**Interfaces:**
- Consumes: `view_helpers.php` (Task 3), `db_get_user_drafts`/`db_count_user_drafts` (Task 1), `db_get_user_submissions`/`db_count_user_submissions` (existing)

- [ ] **Step 1: Replace the top-of-file helper declarations with a `view_helpers.php` require**

Replace (currently `account.php:2-53`, everything from the first `require` through the `h()` function):

```php
<?php
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/config.php';

require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('America/Denver');

$pdo = db_connect();
db_init($pdo);

const SUBMISSION_SOFT_CAP = 20;

function requireLogin(): array {
    $user = current_user();
    if ($user === null) {
        header('Location: auth.php?action=login');
        exit;
    }
    return $user;
}

function generateCsrfToken(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function setFlash(string $message, string $type): void {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function getFlash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
```

with:

```php
<?php
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require __DIR__ . '/view_helpers.php';

require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('America/Denver');

$pdo = db_connect();
db_init($pdo);

const MY_CARS_SOFT_CAP = 20;

function requireLogin(): array {
    $user = current_user();
    if ($user === null) {
        header('Location: auth.php?action=login');
        exit;
    }
    return $user;
}
```

Note the constant rename `SUBMISSION_SOFT_CAP` → `MY_CARS_SOFT_CAP` (used in Step 2 below) — reflects that the cap now covers drafts + submissions combined, per the spec.

- [ ] **Step 2: Replace `handleAccountList()` and `renderAccountListPage()`**

Replace the entire `handleAccountList()` function:

```php
function handleAccountList(PDO $pdo, array $user): void {
    $submissions = db_get_user_submissions($pdo, $user['id']);
    $count = db_count_user_submissions($pdo, $user['id']);
    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderAccountListPage($submissions, $count, $csrf, $flash);
}
```

with:

```php
function handleAccountList(PDO $pdo, array $user): void {
    $drafts = db_get_user_drafts($pdo, $user['id']);
    $submissions = db_get_user_submissions($pdo, $user['id']);
    $totalCount = db_count_user_drafts($pdo, $user['id']) + db_count_user_submissions($pdo, $user['id']);
    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderAccountListPage($drafts, $submissions, $totalCount, $csrf, $flash);
}
```

Replace the entire `renderAccountListPage()` function:

```php
function renderAccountListPage(array $submissions, int $count, string $csrf, ?array $flash): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Submissions — WCMA Calculator</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, sans-serif; margin: 0; background: #f0f2f5; }
  header { background: #1a5490; color: #fff; padding: .8rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
  header h1 { margin: 0; font-size: 1.2rem; }
  header a { color: #cde; font-size: .9rem; }
  main { padding: 1.5rem; }
  .flash, .banner { padding: .7rem 1rem; border-radius: 4px; margin-bottom: 1rem; font-size: .9rem; }
  .flash.success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
  .flash.error   { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
  .banner { background: #fff3cd; border: 1px solid #ffeeba; color: #856404; }
  table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 6px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.1); }
  th { background: #1a5490; color: #fff; padding: .7rem 1rem; text-align: left; font-size: .85rem; }
  td { padding: .65rem 1rem; border-bottom: 1px solid #eee; font-size: .9rem; }
  tr:last-child td { border-bottom: none; }
  .actions a { color: #1a5490; }
  .empty { text-align: center; color: #888; padding: 2rem; }
</style>
</head>
<body>
<header>
  <h1>My Submissions</h1>
  <a href="car-classing.html">← Back to calculator</a>
</header>
<main>
  <?php if ($flash): ?>
  <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>
  <?php if ($count > SUBMISSION_SOFT_CAP): ?>
  <div class="banner">You have <?= (int)$count ?> saved submissions — consider deleting some older ones.</div>
  <?php endif; ?>
  <table>
    <thead>
      <tr><th>Submitted</th><th>Vehicle</th><th>Class</th><th>Email</th><th>Actions</th></tr>
    </thead>
    <tbody>
    <?php if (empty($submissions)): ?>
      <tr><td colspan="5" class="empty">No submissions yet.</td></tr>
    <?php else: foreach ($submissions as $s): ?>
      <tr>
        <td><?= h(date('M j, Y H:i', strtotime($s['submitted_at']))) ?></td>
        <td><?= h(trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])) ?></td>
        <td><strong><?= h($s['calculated_class'] ?? '—') ?></strong></td>
        <td><?= $s['email_sent'] ? '✓' : '⚠ Failed' ?></td>
        <td class="actions">
          <a href="account.php?action=view&id=<?= (int)$s['id'] ?>">View</a>
          <form method="post" action="account.php?action=delete" style="display:inline"
                onsubmit="return confirm('Permanently delete this submission and its files?')">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button type="submit" style="background:none;border:none;color:#c00;cursor:pointer;font-size:.85rem;padding:0;margin-left:.6rem">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</main>
</body>
</html><?php
}
```

with:

```php
function renderAccountListPage(array $drafts, array $submissions, int $count, string $csrf, ?array $flash): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Cars — WCMA Calculator</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
<meta name="csrf-token" content="<?= h($csrf) ?>">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('My Cars', '<a href="car-classing.html">← Back to calculator</a>'); ?>
  <?php if ($flash): ?>
  <div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>
  <?php if ($count > MY_CARS_SOFT_CAP): ?>
  <div class="form-messages show info">You have <?= (int)$count ?> saved cars — consider deleting some older ones.</div>
  <?php endif; ?>
  <table class="data-table">
    <thead>
      <tr><th>Type</th><th>Updated</th><th>Vehicle</th><th>Class</th><th>Actions</th></tr>
    </thead>
    <tbody>
    <?php if (empty($drafts) && empty($submissions)): ?>
      <tr><td colspan="5" class="empty-row">No cars yet — save a draft or submit the calculator to get started.</td></tr>
    <?php else: ?>
      <?php foreach ($drafts as $d): ?>
      <tr>
        <td><span class="badge-draft">Draft</span></td>
        <td><?= h(date('M j, Y H:i', strtotime($d['updated_at']))) ?></td>
        <td><?= h($d['label'] ?: 'Untitled') ?></td>
        <td>—</td>
        <td class="actions">
          <a href="car-classing.html?draft=<?= (int)$d['id'] ?>">Edit</a>
          <form method="post" action="account.php?action=draft-delete" style="display:inline"
                onsubmit="return confirm('Delete this draft?')">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <button type="submit" class="link-button">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php foreach ($submissions as $s): ?>
      <tr>
        <td>Submitted</td>
        <td><?= h(date('M j, Y H:i', strtotime($s['submitted_at']))) ?></td>
        <td><?= h(trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])) ?></td>
        <td><strong><?= h($s['calculated_class'] ?? '—') ?></strong></td>
        <td class="actions">
          <a href="account.php?action=view&id=<?= (int)$s['id'] ?>">View</a>
          <form method="post" action="account.php?action=delete" style="display:inline"
                onsubmit="return confirm('Permanently delete this submission and its files?')">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button type="submit" class="link-button">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
</div>
</body>
</html><?php
}
```

The "Edit" link for a draft points at `car-classing.html?draft=<id>`, which Task 9's JS reads on page load to fetch and populate that draft via `account.php?action=draft-load`.

- [ ] **Step 3: Restyle `renderAccountViewPage()`**

Replace the entire function's `<style>` block and body markup (the calc/logic inside stays the same — only the wrapping HTML/CSS changes). Replace:

```php
function renderAccountViewPage(array $s, string $csrf, ?array $flash): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Submission #<?= (int)$s['id'] ?> — My Submissions</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, sans-serif; margin: 0; background: #f0f2f5; }
  header { background: #1a5490; color: #fff; padding: .8rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
  header a { color: #cde; font-size: .9rem; }
  main { padding: 1.5rem; display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
  @media (max-width: 700px) { main { grid-template-columns: 1fr; } }
  .card { background: #fff; border-radius: 6px; box-shadow: 0 1px 4px rgba(0,0,0,.1); padding: 1.2rem; }
  .card h2 { margin: 0 0 1rem; font-size: 1rem; color: #1a5490; border-bottom: 2px solid #1a5490; padding-bottom: .4rem; }
  table.data td { padding: .35rem .5rem; font-size: .9rem; }
  table.data td:first-child { font-weight: bold; width: 160px; }
  .flash { padding: .7rem 1rem; border-radius: 4px; margin-bottom: 1rem; font-size: .9rem; grid-column: 1/-1; }
  .flash.success { background: #d4edda; color: #155724; }
  .flash.error { background: #f8d7da; color: #721c24; }
  .btn { display: inline-block; padding: .5rem 1.1rem; border-radius: 4px; font-size: .9rem; cursor: pointer; border: none; }
  .btn-primary { background: #1a5490; color: #fff; }
  .btn-delete { background: #c00; color: #fff; }
  .file-thumb { max-width: 100%; max-height: 200px; border-radius: 4px; margin-top: .5rem; display: block; }
</style>
</head>
<body>
<header>
  <h1>Submission #<?= (int)$s['id'] ?></h1>
  <a href="account.php">← Back to My Submissions</a>
</header>
<main>
  <?php if ($flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
  <div class="card">
    <h2>Vehicle &amp; Class</h2>
    <table class="data">
```

with:

```php
function renderAccountViewPage(array $s, string $csrf, ?array $flash): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Submission #<?= (int)$s['id'] ?> — My Cars</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Submission #' . $s['id'], '<a href="account.php">← Back to My Cars</a>'); ?>
  <div class="detail-layout">
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>" style="grid-column:1/-1"><?= h($flash['message']) ?></div><?php endif; ?>
  <div class="detail-card">
    <h2>Vehicle &amp; Class</h2>
    <table class="detail-table">
```

Then further down, replace:

```php
  </div>
  <div class="card">
    <h2>Actions</h2>
    <form method="post" action="account.php?action=resend">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
      <button type="submit" class="btn btn-primary">Resend Confirmation to My Email</button>
    </form>
    <h2 style="margin-top:1.5rem">Uploaded Files</h2>
```

with:

```php
  </div>
  <div class="detail-card">
    <h2>Actions</h2>
    <form method="post" action="account.php?action=resend">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
      <button type="submit" class="btn btn-primary">Resend Confirmation to My Email</button>
    </form>
    <h2 style="margin-top:1.5rem">Uploaded Files</h2>
```

And the closing tags:

```php
    <?php if (!$any): ?><p style="color:#888">No files uploaded.</p><?php endif; ?>
  </div>
</main>
</body>
</html><?php
}
```

with:

```php
    <?php if (!$any): ?><p style="color:#888">No files uploaded.</p><?php endif; ?>
  </div>
  </div>
</div>
</body>
</html><?php
}
```

(the extra closing `</div>` matches the new `.detail-layout` wrapper opened in this step, and the outermost `</div>` closes `.container`).

- [ ] **Step 4: Manually verify**

```bash
cd wcma-calculator
php -l account.php
php -S localhost:8000
```

- Log in, visit `account.php` → confirm it renders in the calculator's visual style (white card, same header pattern as auth.php now has), title says "My Cars".
- With at least one draft (from Task 6's testing) and one submission present → confirm both show in the same table, distinguishable by the "Type" column badge (Draft in amber/warning color, Submitted plain text).
- Click "View" on a submission → confirm the detail page also matches the new style, two-column layout on desktop, single column on mobile widths.
- Confirm deleting a draft and deleting a submission both still work (the underlying delete logic is unchanged, only the markup/classes around it).

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/account.php
git commit -m "feat: merge drafts and submissions into My Cars, restyle to match calculator"
```

---

### Task 8: Restyle `admin.php`

**Files:**
- Modify: `wcma-calculator/admin.php`

**Interfaces:**
- Consumes: `view_helpers.php` (Task 3)

No functional changes in this task — presentation only. `handleList`, `handleView`, `handleFile`, `handleResend`, `handleDelete`, `handleUsersList`, `handleSetRole`, `buildMailer`, `buildResendEmailHtml`, `buildResendEmailText` are untouched.

- [ ] **Step 1: Replace the top-of-file helper declarations with a `view_helpers.php` require**

Replace (currently `admin.php:1-58`):

```php
<?php
require __DIR__ . '/session_bootstrap.php';
date_default_timezone_set('America/Denver');

require __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ── Configuration ─────────────────────────────────────────────────────────────
define('TECH_EMAIL',     'matt.sinfield@gmail.com');
define('TECH_NAME',      'Matt Sinfield');

$pdo = db_connect();
db_init($pdo);

// ── Auth helpers ──────────────────────────────────────────────────────────────
function requireAuth(): void {
    if (current_user() === null) {
        header('Location: auth.php?action=login');
        exit;
    }
    if (!is_admin()) {
        setFlash('You are not authorized to view the admin panel.', 'error');
        header('Location: car-classing.html');
        exit;
    }
}

function generateCsrfToken(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function setFlash(string $message, string $type): void {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function getFlash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
```

with:

```php
<?php
require __DIR__ . '/session_bootstrap.php';
date_default_timezone_set('America/Denver');

require __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ── Configuration ─────────────────────────────────────────────────────────────
define('TECH_EMAIL',     'matt.sinfield@gmail.com');
define('TECH_NAME',      'Matt Sinfield');

$pdo = db_connect();
db_init($pdo);

// ── Auth helpers ──────────────────────────────────────────────────────────────
function requireAuth(): void {
    if (current_user() === null) {
        header('Location: auth.php?action=login');
        exit;
    }
    if (!is_admin()) {
        setFlash('You are not authorized to view the admin panel.', 'error');
        header('Location: car-classing.html');
        exit;
    }
}
```

- [ ] **Step 2: Restyle `renderListPage()`**

Replace the `<head>`/`<body>` open through the header/flash block:

```php
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Submissions — WCMA Admin</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, sans-serif; margin: 0; background: #f0f2f5; }
  header { background: #1a5490; color: #fff; padding: .8rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
  header h1 { margin: 0; font-size: 1.2rem; }
  header a { color: #cde; font-size: .9rem; }
  main { padding: 1.5rem; }
  .flash { padding: .7rem 1rem; border-radius: 4px; margin-bottom: 1rem; font-size: .9rem; }
  .flash.success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
  .flash.error   { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
  table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 6px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.1); }
  th { background: #1a5490; color: #fff; padding: .7rem 1rem; text-align: left; font-size: .85rem; white-space: nowrap; }
  td { padding: .65rem 1rem; border-bottom: 1px solid #eee; font-size: .9rem; vertical-align: middle; }
  tr:last-child td { border-bottom: none; }
  tr:hover td { background: #f7f9fc; }
  .badge-ok   { color: #155724; font-weight: bold; }
  .badge-fail { color: #721c24; font-weight: bold; }
  .actions a  { color: #1a5490; margin-right: .6rem; font-size: .85rem; }
  .btn-delete { background: none; border: none; color: #c00; cursor: pointer; font-size: .85rem; padding: 0; }
  .btn-delete:hover { text-decoration: underline; }
  .empty { text-align: center; color: #888; padding: 2rem; }
</style>
</head>
<body>
<header>
  <h1>WCMA Submissions</h1>
  <div><a href="admin.php?action=users" style="margin-right:1rem">Manage Users</a><a href="auth.php?action=logout">Logout</a></div>
</header>
<main>
  <?php if ($flash): ?>
  <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>
  <table>
```

with:

```php
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Submissions — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('WCMA Submissions', '<a href="admin.php?action=users">Manage Users</a><a href="auth.php?action=logout">Logout</a>'); ?>
  <?php if ($flash): ?>
  <div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>
  <table class="data-table">
```

And the email badge cells — replace:

```php
        <td class="<?= $s['email_sent'] ? 'badge-ok' : 'badge-fail' ?>">
          <?= $s['email_sent'] ? '✓' : '⚠ Failed' ?>
        </td>
        <td class="actions">
          <a href="admin.php?action=view&id=<?= (int)$s['id'] ?>">View</a>
          <form method="post" action="admin.php?action=delete" style="display:inline"
                onsubmit="return confirm('Permanently delete this submission and its files?')">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button type="submit" class="btn-delete">Delete</button>
          </form>
        </td>
```

with:

```php
        <td class="<?= $s['email_sent'] ? 'badge-ok' : 'badge-fail' ?>">
          <?= $s['email_sent'] ? '✓' : '⚠ Failed' ?>
        </td>
        <td class="actions">
          <a href="admin.php?action=view&id=<?= (int)$s['id'] ?>">View</a>
          <form method="post" action="admin.php?action=delete" style="display:inline"
                onsubmit="return confirm('Permanently delete this submission and its files?')">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button type="submit" class="link-button">Delete</button>
          </form>
        </td>
```

Then the closing tags — replace:

```php
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</main>
</body>
</html><?php
}
```

with:

```php
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
</body>
</html><?php
}
```

(same pattern throughout: `<main>` becomes part of `.container`, no separate `<main>` wrapper needed since `calculator.css`'s `.container` already provides the padding/card look).

- [ ] **Step 3: Restyle `renderDetailPage()`**

Replace the `<head>`/`<body>` open through the calculation card's opening `<table class="calc-table">`:

```php
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Submission #<?= (int)$s['id'] ?> — WCMA Admin</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, sans-serif; margin: 0; background: #f0f2f5; }
  header { background: #1a5490; color: #fff; padding: .8rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
  header h1 { margin: 0; font-size: 1.1rem; }
  header a { color: #cde; font-size: .9rem; }
  main { padding: 1.5rem; display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
  @media (max-width: 700px) { main { grid-template-columns: 1fr; } }
  .card { background: #fff; border-radius: 6px; box-shadow: 0 1px 4px rgba(0,0,0,.1); padding: 1.2rem; }
  .card h2 { margin: 0 0 1rem; font-size: 1rem; color: #1a5490; border-bottom: 2px solid #1a5490; padding-bottom: .4rem; }
  table.data td { padding: .35rem .5rem; font-size: .9rem; vertical-align: top; }
  table.data td:first-child { font-weight: bold; width: 160px; color: #444; }
  .calc-table { width: 100%; border-collapse: collapse; font-family: monospace; font-size: .95rem; }
  .calc-table td { padding: .3rem .4rem; }
  .calc-table tr.total td { border-top: 2px solid #333; font-weight: bold; font-size: 1.05rem; padding-top: .5rem; }
  .class-badge { font-size: 1.4rem; font-weight: bold; color: #1a5490; }
  .flash { padding: .7rem 1rem; border-radius: 4px; margin-bottom: 1rem; font-size: .9rem; }
  .flash.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
  .flash.error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
  .actions { margin-bottom: 1rem; }
  .btn { display: inline-block; padding: .5rem 1.1rem; border-radius: 4px; font-size: .9rem; cursor: pointer; border: none; text-decoration: none; }
  .btn-primary { background: #1a5490; color: #fff; }
  .btn-secondary { background: #6c757d; color: #fff; }
  .btn-primary:hover { background: #154070; }
  .file-thumb { max-width: 100%; max-height: 200px; border-radius: 4px; margin-top: .5rem; display: block; }
  .file-link { display: inline-block; margin-top: .4rem; color: #1a5490; }
</style>
</head>
<body>
<header>
  <h1>Submission #<?= (int)$s['id'] ?> — <?= h($s['name']) ?></h1>
  <a href="admin.php">← Back to list</a>
</header>
<main>
  <?php if ($flash): ?>
  <div class="flash <?= h($flash['type']) ?>" style="grid-column:1/-1"><?= h($flash['message']) ?></div>
  <?php endif; ?>

  <!-- LEFT: Calculation + details -->
  <div>
    <div class="card" style="margin-bottom:1.5rem">
      <h2>Calculation Breakdown</h2>
      <table class="calc-table">
```

with:

```php
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Submission #<?= (int)$s['id'] ?> — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Submission #' . $s['id'] . ' — ' . $s['name'], '<a href="admin.php">← Back to list</a>'); ?>
  <div class="detail-layout">
  <?php if ($flash): ?>
  <div class="form-messages show <?= h($flash['type']) ?>" style="grid-column:1/-1"><?= h($flash['message']) ?></div>
  <?php endif; ?>

  <!-- LEFT: Calculation + details -->
  <div>
    <div class="detail-card">
      <h2>Calculation Breakdown</h2>
      <table class="calc-table">
```

Then replace every remaining `class="card"` in this function with `class="detail-card"`, and `class="data"` (on `<table class="data">`) with `class="detail-table"`. There are two more `class="card"` occurrences (Contact & Vehicle, Actions, Uploaded Files) and one more `class="data"` occurrence in this function — find and replace all of them the same way.

Replace the closing tags:

```php
  </div>
</main>
</body>
</html><?php
}
```

with:

```php
  </div>
  </div>
</div>
</body>
</html><?php
}
```

- [ ] **Step 4: Restyle `renderUsersPage()`**

Replace the `<head>`/`<body>` open through the role-color CSS:

```php
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage Users — WCMA Admin</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, sans-serif; margin: 0; background: #f0f2f5; }
  header { background: #1a5490; color: #fff; padding: .8rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
  header a { color: #cde; font-size: .9rem; margin-left: 1rem; }
  main { padding: 1.5rem; }
  .flash { padding: .7rem 1rem; border-radius: 4px; margin-bottom: 1rem; font-size: .9rem; }
  .flash.success { background: #d4edda; color: #155724; }
  .flash.error { background: #f8d7da; color: #721c24; }
  table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 6px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.1); }
  th { background: #1a5490; color: #fff; padding: .7rem 1rem; text-align: left; font-size: .85rem; }
  td { padding: .65rem 1rem; border-bottom: 1px solid #eee; font-size: .9rem; }
  .role-admin { color: #1a5490; font-weight: bold; }
  .btn-role { background: none; border: 1px solid #1a5490; color: #1a5490; border-radius: 4px; padding: .3rem .7rem; cursor: pointer; font-size: .8rem; }
</style>
</head>
<body>
<header>
  <h1>Manage Users</h1>
  <div><a href="admin.php">Submissions</a><a href="auth.php?action=logout">Logout</a></div>
</header>
<main>
  <?php if ($flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
  <table>
```

with:

```php
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage Users — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
<style>
  .btn-role { background: none; border: 1px solid var(--secondary-color); color: var(--secondary-color); border-radius: var(--border-radius); padding: .3rem .7rem; cursor: pointer; font-size: .8rem; font-family: inherit; }
  .btn-role:hover { background: #f0f7ff; }
</style>
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Manage Users', '<a href="admin.php">Submissions</a><a href="auth.php?action=logout">Logout</a>'); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
  <table class="data-table">
```

`.btn-role` stays as a small page-local override (it's a distinct small "pill" button style not shared elsewhere) but now references the shared CSS variables instead of hardcoded colors.

Replace `class="role-admin"` with `class="badge-admin"`:

```php
        <td class="<?= $u['role'] === 'admin' ? 'role-admin' : '' ?>"><?= h($u['role']) ?></td>
```

with:

```php
        <td class="<?= $u['role'] === 'admin' ? 'badge-admin' : '' ?>"><?= h($u['role']) ?></td>
```

Replace the closing tags:

```php
    <?php endforeach; ?>
    </tbody>
  </table>
</main>
</body>
</html><?php
}
```

with:

```php
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
</body>
</html><?php
}
```

- [ ] **Step 5: Manually verify**

```bash
cd wcma-calculator
php -l admin.php
php -S localhost:8000
```

- Log in as the bootstrap admin, visit `admin.php` → confirm the submissions list matches the calculator's visual style.
- Visit a submission's detail view → confirm the two-column card layout matches Task 7's My Cars detail page (same `.detail-card`/`.detail-table`/`.calc-table` classes, same visual weight).
- Visit `admin.php?action=users` → confirm the table and role badge match too.
- Confirm every existing action (sort links, delete, resend, promote/demote) still functions — this task changed no PHP logic, only markup/CSS, so behavior must be identical to before.

- [ ] **Step 6: Commit**

```bash
git add wcma-calculator/admin.php
git commit -m "style: restyle admin.php to match the calculator's design system"
```

---

### Task 9: Wire the calculator page to server-backed drafts, remove `localStorage`

**Files:**
- Modify: `wcma-calculator/car-classing.html`
- Modify: `wcma-calculator/js/ui-controller.js`

**Interfaces:**
- Consumes: `account.php?action=draft-save`, `?action=draft-list`, `?action=draft-load`, `?action=draft-delete` (Task 6)

- [ ] **Step 1: Relabel the Save Configuration button**

In `car-classing.html`, change:

```html
                <button type="button" id="save-config-button" class="btn btn-secondary">Save Configuration</button>
                <button type="button" id="load-config-button" class="btn btn-secondary">Load Saved</button>
```

to:

```html
                <button type="button" id="save-config-button" class="btn btn-secondary">Save to My Cars</button>
                <button type="button" id="load-config-button" class="btn btn-secondary">Load Saved</button>
```

- [ ] **Step 2: Delete the old `localStorage`-based functions in `ui-controller.js`**

Delete these functions entirely from `wcma-calculator/js/ui-controller.js`: `saveConfiguration()`, `getSavedConfigurations()`, `loadConfiguration()`, `deleteConfiguration()` (they currently span roughly lines 978–1125 — the block from the `/** Save current configuration... */` comment through the end of `deleteConfiguration()`).

- [ ] **Step 3: Replace them with server-backed equivalents**

Add in their place (same location in the file):

```js
/**
 * Get a CSRF token for account.php POST actions (draft-save, draft-delete).
 * account.php's list page always emits a `<meta name="csrf-token">` tag in
 * its <head>, regardless of whether the user has any drafts/submissions yet
 * (an earlier version of this scraped the token out of a per-row delete
 * form instead, which silently broke for a first-time user with zero rows —
 * the meta tag is unconditional, so it works even on an empty My Cars page).
 * Also doubles as the "is the user logged in" check: a logged-out request
 * to account.php redirects to auth.php?action=login, whose rendered page has
 * no such meta tag, so a missing match means "not logged in."
 */
async function getAccountCsrfToken() {
    const res = await fetch('account.php', { credentials: 'same-origin' });
    const html = await res.text();
    const match = html.match(/name="csrf-token" content="([^"]+)"/);
    return match ? match[1] : null;
}

/**
 * Save the current form as a draft to the logged-in user's My Cars.
 * Redirects to login if the user isn't authenticated.
 */
async function saveConfiguration() {
    const configData = getAllFormDataForSave();
    const label = `${configData.year} ${configData.make} ${configData.model}`.trim() || 'Untitled Draft';

    const token = await getAccountCsrfToken();
    if (!token) {
        window.location.href = 'auth.php?action=login';
        return;
    }

    const body = new URLSearchParams();
    body.set('csrf_token', token);
    body.set('label', label);
    body.set('form_data', JSON.stringify(configData));

    try {
        const res = await fetch('account.php?action=draft-save', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        });
        const result = await res.json();
        if (result.success) {
            showMessage('Saved to My Cars!', 'success');
        } else {
            showMessage(result.error || 'Failed to save.', 'error');
        }
    } catch (error) {
        console.error('Error saving draft:', error);
        showMessage('An error occurred while saving.', 'error');
    }
}

/**
 * Fetch the current user's drafts from the server.
 */
async function getSavedConfigurations() {
    try {
        const res = await fetch('account.php?action=draft-list', { credentials: 'same-origin' });
        const result = await res.json();
        return result.drafts || [];
    } catch (e) {
        console.error('Error loading drafts:', e);
        return [];
    }
}

/**
 * Load a saved draft into the form.
 */
async function loadConfiguration(draftId) {
    let data;
    try {
        const res = await fetch(`account.php?action=draft-load&id=${encodeURIComponent(draftId)}`, { credentials: 'same-origin' });
        const result = await res.json();
        if (!result.success) {
            showMessage('Draft not found', 'error');
            return;
        }
        data = result.form_data;
    } catch (e) {
        console.error('Error loading draft:', e);
        showMessage('Failed to load draft', 'error');
        return;
    }

    // Populate all form fields (basic fields first)
    if (document.getElementById('name')) document.getElementById('name').value = data.name || '';
    if (document.getElementById('email')) document.getElementById('email').value = data.email || '';
    if (document.getElementById('year')) document.getElementById('year').value = data.year || '';
    if (document.getElementById('make')) document.getElementById('make').value = data.make || '';
    if (document.getElementById('model')) document.getElementById('model').value = data.model || '';
    if (document.getElementById('comments')) document.getElementById('comments').value = data.comments || '';
    if (document.getElementById('competition-weight')) document.getElementById('competition-weight').value = data.competitionWeight || '';
    if (document.getElementById('declared-hp')) document.getElementById('declared-hp').value = data.declaredHp || '';
    if (document.getElementById('dyno-hp')) document.getElementById('dyno-hp').value = data.dynoHp || '';

    // Update form data first to populate modifier options
    updateFormData();
    updateModificationFieldsState();

    // Wait a moment for modifier options to populate, then set values
    setTimeout(() => {
        if (document.getElementById('chassis')) document.getElementById('chassis').value = data.chassis || '';
        if (document.getElementById('body-mods')) document.getElementById('body-mods').value = data.bodyMods || '';
        if (document.getElementById('transmission')) document.getElementById('transmission').value = data.transmission || '';
        if (document.getElementById('drivetrain')) document.getElementById('drivetrain').value = data.drivetrain || '';
        if (document.getElementById('tires')) document.getElementById('tires').value = data.tires || '';

        // Handle brake/suspension checkboxes - clear all first, then check saved ones
        const brakeContainer = document.getElementById('brake-suspension-options');
        if (brakeContainer) {
            const allCheckboxes = brakeContainer.querySelectorAll('input[type="checkbox"]');
            allCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
            });

            if (data.brakeSuspension && Array.isArray(data.brakeSuspension)) {
                data.brakeSuspension.forEach(optionId => {
                    const checkbox = document.getElementById(`brake-${optionId}`);
                    if (checkbox) {
                        checkbox.checked = true;
                    }
                });
            }
        }

        updateFormData();
        handleCalculationUpdate();
        closeLoadModal();
        showMessage('Configuration loaded successfully!', 'success');
    }, 100);
}

/**
 * Delete a saved draft.
 */
async function deleteConfiguration(draftId) {
    const token = await getAccountCsrfToken();
    if (!token) return;

    const body = new URLSearchParams();
    body.set('csrf_token', token);
    body.set('id', draftId);

    try {
        await fetch('account.php?action=draft-delete', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        });
    } catch (e) {
        console.error('Error deleting draft:', e);
    }
    showLoadModal(); // Refresh the modal
    showMessage('Configuration deleted', 'success');
}
```

- [ ] **Step 4: Update `showLoadModal()` to await the now-async `getSavedConfigurations()`**

Find (currently around line 1163-1165):

```js
    // Populate with saved configurations
    const configs = getSavedConfigurations();
    const listContainer = modal.querySelector('#saved-configs-list');
```

Replace with:

```js
    // Populate with saved configurations
    const configs = await getSavedConfigurations();
    const listContainer = modal.querySelector('#saved-configs-list');
```

`showLoadModal()` itself needs to become `async function showLoadModal() {` (change its declaration line from `function showLoadModal() {` to `async function showLoadModal() {`).

Since `configs` (from the server) uses the field name `label` rather than the old localStorage entries' `name`, and `updated_at` rather than `timestamp`, update the rendering block a few lines below:

```js
        listContainer.innerHTML = configs.reverse().map(config => {
            const date = new Date(config.timestamp);
            const dateStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            return `
                <div class="saved-config-item">
                    <div class="saved-config-info">
                        <div class="saved-config-name">${escapeHtml(config.name)}</div>
                        <div class="saved-config-date">Saved: ${dateStr}</div>
                    </div>
```

with:

```js
        listContainer.innerHTML = configs.map(config => {
            const date = new Date(config.updated_at);
            const dateStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            return `
                <div class="saved-config-item">
                    <div class="saved-config-info">
                        <div class="saved-config-name">${escapeHtml(config.label || 'Untitled Draft')}</div>
                        <div class="saved-config-date">Saved: ${dateStr}</div>
                    </div>
```

(dropped `.reverse()` since the server already returns drafts ordered `updated_at DESC`, newest first — reversing client-side would put them in the wrong order now.)

Also update the load/delete button wiring just below, since the server returns numeric `id` rather than the old string timestamp id — the existing code already treats it generically via `getAttribute`, so no change needed there beyond the field being `config.id` instead of `config.id` (same name, no change required — confirm this by reading the existing template literal, which already uses `config.id` for `data-load-id`/`data-delete-id`).

- [ ] **Step 5: Handle loading a draft from a `?draft=<id>` query param on page load**

`account.php`'s My Cars list page (Task 7) links a draft's "Edit" action to `car-classing.html?draft=<id>`. Add support for that in `ui-controller.js`'s initialization function — find where `initializeEventListeners()` (or the DOMContentLoaded-bound init function, look for the `document.addEventListener('DOMContentLoaded', initFunction);` line near the end of the file) is called, and add right after the event listeners are wired:

```js
    // If arriving from a "My Cars" draft Edit link, load that draft
    const draftIdParam = new URLSearchParams(window.location.search).get('draft');
    if (draftIdParam) {
        loadConfiguration(draftIdParam);
    }
```

Place this as the last statement inside the function that runs on `DOMContentLoaded` (the one wired via `document.addEventListener('DOMContentLoaded', initFunction);`), after `initializeEventListeners()` has already run, so `loadConfiguration()`'s DOM lookups succeed.

- [ ] **Step 6: Manually verify**

```bash
cd wcma-calculator
php -S localhost:8000
```

- While logged out, click "Save to My Cars" → confirm it redirects to the login page instead of silently failing.
- Log in, fill out some fields, click "Save to My Cars" → confirm a success message appears and the draft shows up in `account.php`'s My Cars list.
- Click "Load Saved" → confirm the modal shows the draft (not `localStorage`-sourced) with correct name/date, and clicking "Load" repopulates the form.
- Delete a draft from the modal → confirm it's gone from both the modal and the My Cars page.
- From My Cars, click "Edit" on a draft → confirm `car-classing.html?draft=<id>` loads and the form auto-populates with that draft's data.
- Confirm `localStorage.getItem('wcma-saved-configs')` is never read or written anymore (check DevTools Application tab, or just confirm the feature works fully with `localStorage` cleared).

- [ ] **Step 7: Commit**

```bash
git add wcma-calculator/car-classing.html wcma-calculator/js/ui-controller.js
git commit -m "feat: replace localStorage saved configs with server-backed drafts"
```

---

### Task 10: Anonymous submission account-creation incentive

**Files:**
- Modify: `wcma-calculator/js/form-handler.js`

**Interfaces:**
- Consumes: `session-status.php` (existing, already fetched by `car-classing.html`'s inline script — this task adds its own lightweight check rather than plumbing that result through module boundaries, since the inline script and the ES module files don't currently share state)

- [ ] **Step 1: Add a DOM-safe helper to append the incentive link after a successful submission**

In `wcma-calculator/js/form-handler.js`, find `showFormMessage()`:

```js
export function showFormMessage(message, type = 'success') {
    const messageElement = document.getElementById('form-messages');
    if (messageElement) {
        messageElement.textContent = message;
        messageElement.className = `form-messages show ${type}`;
        messageElement.setAttribute('role', 'alert');
        messageElement.style.display = 'block'; // Force display

        console.log('Showing form message:', type, message);

        // Scroll to message
        messageElement.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        // Auto-hide after 5 seconds for success messages only
        if (type === 'success') {
            setTimeout(() => {
                messageElement.style.display = 'none';
                messageElement.classList.remove('show');
            }, 5000);
        }
    } else {
        console.error('form-messages element not found in DOM!');
        // Fallback to alert if element doesn't exist
        alert(message);
    }
}
```

Add a new function after it:

```js
/**
 * After a successful anonymous submission, append an account-creation nudge
 * below the success message. Checks session-status.php itself rather than
 * relying on state from car-classing.html's separate inline script, since
 * this module has no access to that script's scope.
 *
 * Builds the link via DOM methods (never innerHTML with interpolated data)
 * since the submitted email is user-controlled input.
 */
export async function maybeShowAccountNudge(submittedEmail) {
    try {
        const res = await fetch('session-status.php', { credentials: 'same-origin' });
        const status = await res.json();
        if (status.loggedIn) return; // already have an account, no nudge needed
    } catch (e) {
        // If the status check fails, still show the nudge — worst case a
        // logged-in user sees a redundant link, which is harmless.
    }

    const messageElement = document.getElementById('form-messages');
    if (!messageElement) return;

    const nudge = document.createElement('p');
    nudge.style.marginTop = '0.5rem';
    nudge.appendChild(document.createTextNode('Want to track this car’s history? '));

    const link = document.createElement('a');
    const params = new URLSearchParams({ action: 'register', email: submittedEmail || '' });
    link.href = `auth.php?${params.toString()}`;
    link.textContent = 'Create a free account';
    nudge.appendChild(link);

    messageElement.appendChild(nudge);
}
```

- [ ] **Step 2: Call it from the success path in `handleFormSubmit()`**

Find (currently around `form-handler.js:453-454`):

```js
            const successMsg = result.message || 'Form submitted successfully! Thank you for your submission.';
            showFormMessage(successMsg, 'success');
```

Replace with:

```js
            const successMsg = result.message || 'Form submitted successfully! Thank you for your submission.';
            showFormMessage(successMsg, 'success');
            const submittedEmail = formData.get('email');
            maybeShowAccountNudge(submittedEmail);
```

(`formData` is already in scope at this point in `handleFormSubmit()` — confirm by checking the surrounding function; it's the `FormData` object built from the form a few lines earlier in the same function.)

- [ ] **Step 3: Manually verify**

```bash
cd wcma-calculator
php -S localhost:8000
```

- While logged out, submit the calculator form successfully → confirm the success message shows, followed by "Want to track this car's history? Create a free account" with a working link.
- Click that link → confirm it lands on `auth.php?action=register&email=<the submitted email>` with the email field prefilled (from Task 4's Step 4).
- Log in, submit the form again → confirm the nudge does NOT appear this time (already logged in).
- Try submitting a name/email containing `<script>` or similar — confirm the nudge itself renders safely (it never touches the submitted email in its own text; the email only ever goes into a URL parameter, which `URLSearchParams` percent-encodes automatically).

- [ ] **Step 4: Commit**

```bash
git add wcma-calculator/js/form-handler.js
git commit -m "feat: add account-creation nudge after anonymous submission"
```

---

## Post-Implementation Checklist

- [ ] Run the full test suite once more: `cd wcma-calculator && php phpunit.phar`
- [ ] Manually walk through the full flow end-to-end: anonymous submit → account-creation nudge → register (prefilled email) → confirm past submission auto-linked with flash → save a draft → edit it from My Cars → submit it → confirm both the draft and the new submission appear in My Cars → admin promote/demote still works → every restyled page (login, register, forgot/reset password, My Cars list/detail, admin list/detail/users) visually matches the calculator page's look on both desktop and a narrow (mobile) width
- [ ] Confirm `localStorage.getItem('wcma-saved-configs')` is never referenced anywhere in the codebase anymore (`grep -r "wcma-saved-configs" wcma-calculator/`)
- [ ] Confirm no leftover references to the old `.flash`/`.card`/`.role-admin`/`table.data` class names remain in `admin.php`/`account.php` (`grep -rE 'class="(flash|card|role-admin)"' wcma-calculator/*.php`)
