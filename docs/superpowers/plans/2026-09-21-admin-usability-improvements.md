# Admin Usability Improvements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Address the full list of usability gaps identified in the admin.php review: list pagination/filters/bulk-delete/export, resend confirmation + email history, inline contact editing, an image lightbox, a print view, linked user accounts, user-page role filtering + submission counts, and account deactivation.

**Architecture:** All changes are additive to the existing PHP-per-page architecture (`admin.php` router + handler functions + inline HTML renderers, `db.php` as the only data-access layer, `js/table-tools.js` as the shared vanilla-JS table helper). No framework, no build step, no new dependencies. New DB columns are added via the existing "check `PRAGMA table_info`, `ALTER TABLE` if missing" migration pattern already used for `submissions.user_id`.

**Tech Stack:** PHP 8 (no framework), SQLite via PDO, vanilla JS (ES6 modules for the calculator, plain global scripts for admin tooling), PHPUnit for `db.php` logic, manual browser verification for UI/JS (this codebase has no JS test runner or HTTP-level PHP tests — `db.php` is the only unit-tested layer; that pattern continues here).

**Spec:** No separate spec doc — the requirements are the usability findings from the prior review turn in this conversation (list: pagination, class/status filters, submission count, bulk actions, CSV export; detail: resend confirmation, email history, inline edit, image lightbox, print view, linked user account; users: role filter, submission counts, deactivation; cross-cutting: aria-labels on status icons). Responsive table scrolling was checked and is **already implemented** (`css/calculator.css:1565-1582`) — no task needed for it.

## Global Constraints

- No new Composer/npm dependencies. Stay vanilla PHP/JS, matching the rest of the app.
- Every new DB column must be added through the existing nullable-column-then-backfill migration pattern in `db_init()` (see `db.php:105-115` for the `user_id` precedent) — never a destructive `ALTER TABLE` and never assume a fresh DB.
- All new POST actions must validate CSRF via `validateCsrfToken($_POST['csrf_token'] ?? '')`, matching every existing POST handler in `admin.php`.
- All new admin routes must call `requireAuth()` first, matching the existing router in `admin.php:39-99`.
- Escape all dynamic output with `h()` (`view_helpers.php:24`) — never raw-echo user-controlled or DB-sourced strings into HTML.
- Preserve the existing confirm-before-destructive-action UX: any new irreversible/impactful action (bulk delete, deactivate, resend) gets a `data-confirm="..."` attribute so `js/confirm-modal.js` intercepts it — don't add native `confirm()` calls.
- Follow the file's existing code style: 4-space indent, functions defined inline in `admin.php` below the router, `snake_case` DB functions in `db.php`, `camelCase` PHP variables.

---

## File Structure

- **`db.php`** — modify: add `last_emailed_at`/`email_send_count` columns to `submissions`, `active` column to `users`; add pagination/count/bulk-delete/per-user-count/contact-update/active-toggle functions.
- **`auth.php`** — modify: reject login (password and Google) for deactivated users.
- **`admin.php`** — modify: list page (pagination, filters, bulk delete, CSV export), detail page (resend confirm, email history, inline edit, lightbox markup, print button, linked account), users page (role filter, submission counts, deactivate/reactivate, promote confirm).
- **`js/table-tools.js`** — modify: add a generic `enableFilter()` helper (dropdown-based row filtering that composes with existing search) and a `enableBulkSelect()` helper (checkbox-driven bulk-action toolbar).
- **`js/lightbox.js`** — new: minimal click-to-enlarge overlay for `img[data-lightbox]`.
- **`css/calculator.css`** — modify: filter-toolbar controls, bulk-action toolbar, pagination links, lightbox overlay, print rules for the detail page, edit-form styling.
- **`tests/DbSubmissionsAdminTest.php`** — new: pagination, count, bulk delete, email-resend tracking, contact update.
- **`tests/DbUsersActiveTest.php`** — new: active/inactive toggle and the "can't deactivate the last active admin" guard.

---

### Task 1: Schema migrations + core DB functions

**Files:**
- Modify: `db.php` (inside `db_init()`, after the existing `user_id` migration block, and in the functions section below it)
- Modify: `db.php:148-151` (`db_update_email_sent`)
- Test: `tests/DbSubmissionsAdminTest.php` (new)
- Test: `tests/DbUsersActiveTest.php` (new)

**Interfaces:**
- Produces:
  - `db_count_submissions(PDO $pdo): int`
  - `db_get_submissions(PDO $pdo, string $sort = 'submitted_at', string $dir = 'desc', ?int $limit = null, int $offset = 0): array` (extends existing signature — new params default to "no pagination" so every existing call site keeps working unchanged)
  - `db_delete_submissions(PDO $pdo, array $ids): int`
  - `db_count_submissions_by_user(PDO $pdo): array` (returns `[user_id => count]`)
  - `db_update_submission_contact(PDO $pdo, int $id, array $data): void` (`$data` keys: `name`, `email`, `year`, `make`, `model`, `comments`)
  - `db_set_user_active(PDO $pdo, int $id, bool $active): void`
  - `db_count_active_admins(PDO $pdo): int`
  - `db_update_email_sent(PDO $pdo, int $id, int $sent): void` — unchanged signature, now also bumps `last_emailed_at`/`email_send_count` when `$sent === 1`

- [ ] **Step 1: Write failing tests for the new submission functions**

Create `tests/DbSubmissionsAdminTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

final class DbSubmissionsAdminTest extends TestCase
{
    private function minimalSubmissionData(): array {
        return [
            ':submitted_at' => date('Y-m-d H:i:s'),
            ':name' => 'Test Driver', ':email' => 't@example.com',
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
            ':user_id' => null,
        ];
    }

    public function testCountSubmissions(): void {
        $pdo = make_temp_pdo();
        db_insert_submission($pdo, $this->minimalSubmissionData());
        db_insert_submission($pdo, $this->minimalSubmissionData());
        $this->assertSame(2, db_count_submissions($pdo));
    }

    public function testGetSubmissionsRespectsLimitAndOffset(): void {
        $pdo = make_temp_pdo();
        for ($i = 0; $i < 5; $i++) {
            $data = $this->minimalSubmissionData();
            $data[':name'] = "Driver {$i}";
            db_insert_submission($pdo, $data);
        }
        $page1 = db_get_submissions($pdo, 'id', 'asc', 2, 0);
        $page2 = db_get_submissions($pdo, 'id', 'asc', 2, 2);
        $this->assertCount(2, $page1);
        $this->assertCount(2, $page2);
        $this->assertSame('Driver 0', $page1[0]['name']);
        $this->assertSame('Driver 2', $page2[0]['name']);
    }

    public function testGetSubmissionsWithoutLimitReturnsAll(): void {
        $pdo = make_temp_pdo();
        db_insert_submission($pdo, $this->minimalSubmissionData());
        db_insert_submission($pdo, $this->minimalSubmissionData());
        $this->assertCount(2, db_get_submissions($pdo));
    }

    public function testDeleteSubmissionsBulk(): void {
        $pdo = make_temp_pdo();
        $id1 = db_insert_submission($pdo, $this->minimalSubmissionData());
        $id2 = db_insert_submission($pdo, $this->minimalSubmissionData());
        $id3 = db_insert_submission($pdo, $this->minimalSubmissionData());

        $deleted = db_delete_submissions($pdo, [$id1, $id3]);

        $this->assertSame(2, $deleted);
        $this->assertSame(1, db_count_submissions($pdo));
        $this->assertNotNull(db_get_submission($pdo, $id2));
    }

    public function testDeleteSubmissionsBulkWithEmptyArrayIsNoop(): void {
        $pdo = make_temp_pdo();
        db_insert_submission($pdo, $this->minimalSubmissionData());
        $this->assertSame(0, db_delete_submissions($pdo, []));
        $this->assertSame(1, db_count_submissions($pdo));
    }

    public function testCountSubmissionsByUser(): void {
        $pdo = make_temp_pdo();
        $userId = db_create_user($pdo, ['email' => 'u@example.com', 'name' => 'U', 'password_hash' => 'x', 'google_id' => null]);

        $data = $this->minimalSubmissionData();
        $data[':user_id'] = $userId;
        db_insert_submission($pdo, $data);
        db_insert_submission($pdo, $data);
        db_insert_submission($pdo, $this->minimalSubmissionData()); // no user_id

        $counts = db_count_submissions_by_user($pdo);
        $this->assertSame(2, $counts[$userId]);
    }

    public function testUpdateSubmissionContact(): void {
        $pdo = make_temp_pdo();
        $id = db_insert_submission($pdo, $this->minimalSubmissionData());

        db_update_submission_contact($pdo, $id, [
            'name' => 'Corrected Name', 'email' => 'fixed@example.com',
            'year' => '2021', 'make' => 'Toyota', 'model' => 'MR2', 'comments' => 'typo fixed',
        ]);

        $sub = db_get_submission($pdo, $id);
        $this->assertSame('Corrected Name', $sub['name']);
        $this->assertSame('fixed@example.com', $sub['email']);
        $this->assertSame('2021', $sub['year']);
        $this->assertSame('Toyota', $sub['make']);
        $this->assertSame('MR2', $sub['model']);
        $this->assertSame('typo fixed', $sub['comments']);
    }

    public function testUpdateEmailSentTracksHistory(): void {
        $pdo = make_temp_pdo();
        $id = db_insert_submission($pdo, $this->minimalSubmissionData());

        db_update_email_sent($pdo, $id, 1);
        $sub = db_get_submission($pdo, $id);
        $this->assertSame(1, (int)$sub['email_sent']);
        $this->assertSame(1, (int)$sub['email_send_count']);
        $this->assertNotNull($sub['last_emailed_at']);

        db_update_email_sent($pdo, $id, 1);
        $sub = db_get_submission($pdo, $id);
        $this->assertSame(2, (int)$sub['email_send_count']);
    }

    public function testUpdateEmailSentFailureDoesNotBumpHistory(): void {
        $pdo = make_temp_pdo();
        $id = db_insert_submission($pdo, $this->minimalSubmissionData());

        db_update_email_sent($pdo, $id, 0);
        $sub = db_get_submission($pdo, $id);
        $this->assertSame(0, (int)$sub['email_sent']);
        $this->assertSame(0, (int)$sub['email_send_count']);
        $this->assertNull($sub['last_emailed_at']);
    }
}
```

- [ ] **Step 2: Run the new tests and confirm they fail**

Run: `cd wcma-calculator && ./vendor/bin/phpunit tests/DbSubmissionsAdminTest.php`
Expected: FAIL — undefined functions (`db_count_submissions`, etc.) and missing columns (`email_send_count`, `last_emailed_at`).

- [ ] **Step 3: Write failing tests for user activation**

Create `tests/DbUsersActiveTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

final class DbUsersActiveTest extends TestCase
{
    public function testNewUserIsActiveByDefault(): void {
        $pdo = make_temp_pdo();
        $id = db_create_user($pdo, ['email' => 'a@example.com', 'name' => 'A', 'password_hash' => 'x', 'google_id' => null]);
        $user = db_find_user_by_id($pdo, $id);
        $this->assertSame(1, (int)$user['active']);
    }

    public function testSetUserActiveTogglesFlag(): void {
        $pdo = make_temp_pdo();
        $id = db_create_user($pdo, ['email' => 'a@example.com', 'name' => 'A', 'password_hash' => 'x', 'google_id' => null]);

        db_set_user_active($pdo, $id, false);
        $this->assertSame(0, (int)db_find_user_by_id($pdo, $id)['active']);

        db_set_user_active($pdo, $id, true);
        $this->assertSame(1, (int)db_find_user_by_id($pdo, $id)['active']);
    }

    public function testCountActiveAdminsExcludesDeactivated(): void {
        $pdo = make_temp_pdo();
        $id1 = db_create_user($pdo, ['email' => 'a1@example.com', 'name' => 'A1', 'password_hash' => 'x', 'google_id' => null]);
        $id2 = db_create_user($pdo, ['email' => 'a2@example.com', 'name' => 'A2', 'password_hash' => 'x', 'google_id' => null]);
        db_set_user_role($pdo, $id1, 'admin');
        db_set_user_role($pdo, $id2, 'admin');
        $this->assertSame(2, db_count_active_admins($pdo));

        db_set_user_active($pdo, $id2, false);
        $this->assertSame(1, db_count_active_admins($pdo));
    }
}
```

- [ ] **Step 4: Run the new tests and confirm they fail**

Run: `cd wcma-calculator && ./vendor/bin/phpunit tests/DbUsersActiveTest.php`
Expected: FAIL — undefined function `db_set_user_active`/`db_count_active_admins`, missing `active` column.

- [ ] **Step 5: Add the migrations to `db_init()`**

In `db.php`, immediately after the existing `user_id` migration block (right after the closing `}` of the `if (!$hasUserId)` block, still inside `db_init()`):

```php
    // Add email-history tracking columns if migrating an existing DB
    $hasLastEmailedAt = false;
    $hasEmailSendCount = false;
    foreach ($columns as $col) {
        if ($col['name'] === 'last_emailed_at') { $hasLastEmailedAt = true; }
        if ($col['name'] === 'email_send_count') { $hasEmailSendCount = true; }
    }
    if (!$hasLastEmailedAt) {
        $pdo->exec("ALTER TABLE submissions ADD COLUMN last_emailed_at DATETIME");
    }
    if (!$hasEmailSendCount) {
        $pdo->exec("ALTER TABLE submissions ADD COLUMN email_send_count INTEGER NOT NULL DEFAULT 0");
    }

    // Add active flag to users if migrating an existing DB
    $userColumns = $pdo->query("PRAGMA table_info(users)")->fetchAll();
    $hasActive = false;
    foreach ($userColumns as $col) {
        if ($col['name'] === 'active') { $hasActive = true; break; }
    }
    if (!$hasActive) {
        $pdo->exec("ALTER TABLE users ADD COLUMN active INTEGER NOT NULL DEFAULT 1");
    }
```

Also add `active INTEGER NOT NULL DEFAULT 1` as a column in the `CREATE TABLE IF NOT EXISTS users (...)` statement (so fresh DBs get it without relying on the migration branch), and add `last_emailed_at DATETIME` / `email_send_count INTEGER DEFAULT 0` to the `CREATE TABLE IF NOT EXISTS submissions (...)` statement, right after `email_sent INTEGER DEFAULT 0`.

- [ ] **Step 6: Implement the new/modified functions**

Replace `db_update_email_sent` (`db.php:148-151`) with:

```php
function db_update_email_sent(PDO $pdo, int $id, int $sent): void {
    if ($sent === 1) {
        $pdo->prepare("
            UPDATE submissions
            SET email_sent = 1, last_emailed_at = :now, email_send_count = email_send_count + 1
            WHERE id = :id
        ")->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id]);
    } else {
        $pdo->prepare("UPDATE submissions SET email_sent = 0 WHERE id = :id")
            ->execute([':id' => $id]);
    }
}
```

Replace `db_get_submissions` (`db.php:153-160`) with:

```php
function db_get_submissions(PDO $pdo, string $sort = 'submitted_at', string $dir = 'desc', ?int $limit = null, int $offset = 0): array {
    $allowed_sorts = ['submitted_at', 'name', 'year', 'make', 'model',
                      'competition_weight', 'declared_hp', 'calculated_class', 'email_sent'];
    $allowed_dirs  = ['asc', 'desc'];
    $sort = in_array($sort, $allowed_sorts, true) ? $sort : 'submitted_at';
    $dir  = in_array($dir,  $allowed_dirs,  true) ? $dir  : 'desc';
    $sql = "SELECT * FROM submissions ORDER BY {$sort} {$dir}";
    if ($limit !== null) {
        $sql .= " LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    return $pdo->query($sql)->fetchAll();
}

function db_count_submissions(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM submissions")->fetchColumn();
}
```

Add near `db_delete_submission` (`db.php:192-194`):

```php
function db_delete_submissions(PDO $pdo, array $ids): int {
    if (empty($ids)) return 0;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("DELETE FROM submissions WHERE id IN ({$placeholders})");
    $stmt->execute(array_map('intval', $ids));
    return $stmt->rowCount();
}

function db_count_submissions_by_user(PDO $pdo): array {
    $rows = $pdo->query("SELECT user_id, COUNT(*) AS cnt FROM submissions WHERE user_id IS NOT NULL GROUP BY user_id")->fetchAll();
    $counts = [];
    foreach ($rows as $row) {
        $counts[(int)$row['user_id']] = (int)$row['cnt'];
    }
    return $counts;
}

function db_update_submission_contact(PDO $pdo, int $id, array $data): void {
    $pdo->prepare("
        UPDATE submissions
        SET name = :name, email = :email, year = :year, make = :make, model = :model, comments = :comments
        WHERE id = :id
    ")->execute([
        ':name' => $data['name'], ':email' => $data['email'],
        ':year' => $data['year'], ':make' => $data['make'], ':model' => $data['model'],
        ':comments' => $data['comments'], ':id' => $id,
    ]);
}
```

Add near `db_count_admins` (`db.php:284-286`):

```php
function db_set_user_active(PDO $pdo, int $id, bool $active): void {
    $pdo->prepare("UPDATE users SET active = :active WHERE id = :id")
        ->execute([':active' => $active ? 1 : 0, ':id' => $id]);
}

function db_count_active_admins(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")->fetchColumn();
}
```

- [ ] **Step 7: Run both new test files and confirm they pass**

Run: `cd wcma-calculator && ./vendor/bin/phpunit tests/DbSubmissionsAdminTest.php tests/DbUsersActiveTest.php`
Expected: PASS (all assertions green)

- [ ] **Step 8: Run the full existing test suite to confirm no regressions**

Run: `cd wcma-calculator && ./vendor/bin/phpunit`
Expected: PASS — all previously-passing tests (`DbLinkSubmissionsTest`, `DbSubmissionsUserIdTest`, `DbUsersTest`, `DbDraftsTest`, `DbPasswordResetsTest`) still pass unchanged.

- [ ] **Step 9: Commit**

```bash
git add wcma-calculator/db.php wcma-calculator/tests/DbSubmissionsAdminTest.php wcma-calculator/tests/DbUsersActiveTest.php
git commit -m "feat: add pagination, bulk delete, email history, contact edit, and user activation to db layer"
```

---

### Task 2: Block login for deactivated users

**Files:**
- Modify: `auth.php:173-224` (`handleLogin`)
- Modify: `auth.php:269-338` (`handleGoogleCallback`)

**Interfaces:**
- Consumes: `db_find_user_by_email`, `db_find_user_by_google_id`, `db_find_user_by_id` (all already return the `active` column per Task 1's schema change — no signature change needed, since they `SELECT *`).

- [ ] **Step 1: Add the active check to password login**

In `auth.php`, inside `handleLogin`, the existing check at line 191:

```php
            if ($user && $user['password_hash'] && password_verify($password, $user['password_hash'])) {
```

Change to:

```php
            if ($user && (int)$user['active'] === 0) {
                $error = 'This account has been deactivated. Contact an administrator.';
            } elseif ($user && $user['password_hash'] && password_verify($password, $user['password_hash'])) {
```

(This keeps the existing `else` branch — the generic "Incorrect email or password" — for all other failure paths, so deactivation status isn't leaked for wrong-password attempts against active accounts, only surfaced once credentials would otherwise have succeeded.)

- [ ] **Step 2: Add the active check to Google login**

In `auth.php`, inside `handleGoogleCallback`, right after the `$user = db_find_user_by_google_id($pdo, $googleId);` block resolves `$user` (after the `if (!$user) { ... }` block, before `login_user($user);` at line 333):

```php
    if ((int)$user['active'] === 0) {
        setFlash('This account has been deactivated. Contact an administrator.', 'error');
        header('Location: auth.php?action=login');
        exit;
    }

    login_user($user);
```

- [ ] **Step 3: Manually verify**

Run: `cd wcma-calculator && php -S localhost:8765`
Then: create a test user via the register flow, deactivate them directly in SQLite (`sqlite3 data/submissions.db "UPDATE users SET active = 0 WHERE email = 'test@example.com'"`), and confirm a login attempt with correct credentials shows "This account has been deactivated." instead of logging in.
Expected: Login blocked with the deactivation message; an active user still logs in normally.

- [ ] **Step 4: Commit**

```bash
git add wcma-calculator/auth.php
git commit -m "feat: block login for deactivated user accounts"
```

---

### Task 3: List page — pagination, total count, class/status filters

**Files:**
- Modify: `admin.php:101-108` (`handleList`)
- Modify: `admin.php:110-187` (`renderListPage`)
- Modify: `js/table-tools.js` (add `enableFilter`)
- Modify: `css/calculator.css` (filter toolbar + pagination controls)

**Interfaces:**
- Consumes: `db_count_submissions(PDO $pdo): int`, `db_get_submissions(..., ?int $limit, int $offset)` from Task 1.
- Produces: `WcmaTableTools.enableFilter(selectEl, table, columnIndex)` — reusable by both the submissions list (class filter, status filter) and the users page (Task 6, role filter).

- [ ] **Step 1: Add pagination + page-size constant to `handleList`**

Replace `admin.php:101-108`:

```php
define('ADMIN_PAGE_SIZE', 50);

function handleList(PDO $pdo): void {
    $sort = $_GET['sort'] ?? 'submitted_at';
    $dir  = $_GET['dir']  ?? 'desc';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $total = db_count_submissions($pdo);
    $totalPages = max(1, (int)ceil($total / ADMIN_PAGE_SIZE));
    $page = min($page, $totalPages);
    $submissions = db_get_submissions($pdo, $sort, $dir, ADMIN_PAGE_SIZE, ($page - 1) * ADMIN_PAGE_SIZE);
    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderListPage($submissions, $sort, $dir, $csrf, $flash, $page, $totalPages, $total);
}
```

(Move the `define('ADMIN_PAGE_SIZE', 50);` line up near the other `define()` calls at the top of the file if you prefer — functionally identical either way, just keep it above its first use.)

- [ ] **Step 2: Update `renderListPage` signature, toolbar, and pagination footer**

Replace `admin.php:110-138` (function signature through the opening of the table):

```php
function renderListPage(array $submissions, string $sort, string $dir, string $csrf, ?array $flash, int $page, int $totalPages, int $total): void {
    $flip = $dir === 'asc' ? 'desc' : 'asc';

    function sortLink(string $col, string $label, string $currentSort, string $currentDir, string $flip): string {
        $arrow = ($currentSort === $col) ? ($currentDir === 'asc' ? ' ▲' : ' ▼') : '';
        $nextDir = ($currentSort === $col) ? $flip : 'asc';
        $url = h('admin.php?sort=' . $col . '&dir=' . $nextDir);
        return "<a href=\"{$url}\" style=\"color:inherit;text-decoration:none;\">" . h($label) . $arrow . "</a>";
    }
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
  <?php renderSiteHeader('WCMA Submissions', '<a href="admin.php?action=users">Manage Users</a>' . renderCommonNav('admin')); ?>
  <?php if ($flash): ?>
  <div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>
  <p class="list-summary"><?= (int)$total ?> submission<?= $total === 1 ? '' : 's' ?> total<?= $totalPages > 1 ? ' — page ' . $page . ' of ' . $totalPages : '' ?></p>
  <?php if (!empty($submissions)): ?>
  <div class="list-toolbar">
    <input type="search" id="submissions-search" class="table-search" placeholder="Search submissions…" aria-label="Search submissions">
    <select id="submissions-class-filter" class="table-filter" aria-label="Filter by class">
      <option value="">All classes</option>
      <?php foreach (['GTU','GT1','GT2','GT3','GT4','IT1','IT2'] as $cls): ?>
      <option value="<?= h($cls) ?>"><?= h($cls) ?></option>
      <?php endforeach; ?>
    </select>
    <select id="submissions-status-filter" class="table-filter" aria-label="Filter by email status">
      <option value="">All statuses</option>
      <option value="sent">Email sent</option>
      <option value="failed">Email failed</option>
    </select>
    <form method="post" action="admin.php?action=bulk-delete" id="bulk-delete-form" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <button type="submit" id="bulk-delete-btn" class="btn btn-danger" disabled data-confirm-template="Permanently delete {n} selected submission(s) and their files?">Delete Selected</button>
    </form>
    <a href="admin.php?action=export&sort=<?= h($sort) ?>&dir=<?= h($dir) ?>" class="btn btn-secondary">Export CSV</a>
  </div>
  <?php endif; ?>
```

- [ ] **Step 3: Add the select-all checkbox column, per-row checkboxes, aria-labels on status badges, and a pagination footer**

Replace the `<thead>`/`<tbody>` block (`admin.php:139-178` in the original file) with:

```php
  <table class="data-table" id="submissions-table">
    <thead>
      <tr>
        <th><input type="checkbox" id="submissions-select-all" aria-label="Select all submissions"></th>
        <th><?= sortLink('submitted_at', 'Submitted', $sort, $dir, $flip) ?></th>
        <th><?= sortLink('name', 'Name', $sort, $dir, $flip) ?></th>
        <th>Vehicle</th>
        <th><?= sortLink('competition_weight', 'Weight', $sort, $dir, $flip) ?></th>
        <th><?= sortLink('declared_hp', 'HP', $sort, $dir, $flip) ?></th>
        <th><?= sortLink('calculated_class', 'Class', $sort, $dir, $flip) ?></th>
        <th>Email</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($submissions)): ?>
      <tr><td colspan="9" class="empty">No submissions yet.</td></tr>
    <?php else: foreach ($submissions as $s): ?>
      <tr data-class="<?= h($s['calculated_class'] ?? '') ?>" data-status="<?= $s['email_sent'] ? 'sent' : 'failed' ?>">
        <td><input type="checkbox" class="submission-select" form="bulk-delete-form" name="ids[]" value="<?= (int)$s['id'] ?>" aria-label="Select submission from <?= h($s['name']) ?>"></td>
        <td><?= h(date('M j, Y H:i', strtotime($s['submitted_at']))) ?></td>
        <td><?= h($s['name']) ?></td>
        <td><?= h(trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])) ?></td>
        <td><?= h((string)$s['competition_weight']) ?></td>
        <td><?= h((string)$s['declared_hp']) ?></td>
        <td><strong><?= h($s['calculated_class'] ?? '—') ?></strong></td>
        <td class="<?= $s['email_sent'] ? 'badge-ok' : 'badge-fail' ?>" title="<?= $s['email_sent'] ? 'Email sent' : 'Email failed to send' ?>">
          <span aria-hidden="true"><?= $s['email_sent'] ? '✓' : '⚠' ?></span>
          <span class="sr-only"><?= $s['email_sent'] ? 'Sent' : 'Failed' ?></span>
        </td>
        <td class="actions">
          <a href="admin.php?action=view&id=<?= (int)$s['id'] ?>">View</a>
          <form method="post" action="admin.php?action=delete" style="display:inline"
                data-confirm="Permanently delete this submission and its files?">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button type="submit" class="link-button">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <p class="no-results-message" hidden>No submissions match your search.</p>
  <?php if ($totalPages > 1): ?>
  <nav class="pagination" aria-label="Submissions pages">
    <?php if ($page > 1): ?><a href="<?= h('admin.php?sort=' . $sort . '&dir=' . $dir . '&page=' . ($page - 1)) ?>">← Prev</a><?php endif; ?>
    <span>Page <?= (int)$page ?> of <?= (int)$totalPages ?></span>
    <?php if ($page < $totalPages): ?><a href="<?= h('admin.php?sort=' . $sort . '&dir=' . $dir . '&page=' . ($page + 1)) ?>">Next →</a><?php endif; ?>
  </nav>
  <?php endif; ?>
</div>
<script src="js/table-tools.js"></script>
<script src="js/confirm-modal.js"></script>
<script src="js/form-feedback.js"></script>
<script>
  WcmaTableTools.enableSearch(document.getElementById('submissions-search'), document.getElementById('submissions-table'));
  WcmaTableTools.enableFilter(document.getElementById('submissions-class-filter'), document.getElementById('submissions-table'), 'class');
  WcmaTableTools.enableFilter(document.getElementById('submissions-status-filter'), document.getElementById('submissions-table'), 'status');
  WcmaTableTools.enableBulkSelect(document.getElementById('submissions-select-all'), document.getElementById('submissions-table'), document.getElementById('bulk-delete-btn'));
</script>
</body>
</html><?php
}
```

Note the class/status filters key off `data-class`/`data-status` attributes on each `<tr>` (set above) rather than cell text, so filtering doesn't depend on column order or text formatting.

- [ ] **Step 4: Implement `enableFilter` and `enableBulkSelect` in `js/table-tools.js`**

Add to `js/table-tools.js`, before the final `window.WcmaTableTools = ...` line:

```javascript
    function enableFilter(select, table, datasetKey) {
        if (!select || !table) return;
        const rows = dataRows(table);
        const noResults = table.parentElement.querySelector('.no-results-message');
        const searchInput = table.parentElement.querySelector('.table-search');

        function apply() {
            const q = searchInput ? searchInput.value.trim().toLowerCase() : '';
            const filterValues = Array.from(table.parentElement.querySelectorAll('.table-filter'))
                .filter(function (s) { return s.value; })
                .map(function (s) { return s; });

            let visibleCount = 0;
            rows.forEach(function (row) {
                const matchesSearch = !q || row.textContent.toLowerCase().indexOf(q) !== -1;
                const matchesFilters = filterValues.every(function (s) {
                    return row.dataset[s.dataset.filterKey] === s.value;
                });
                const visible = matchesSearch && matchesFilters;
                row.hidden = !visible;
                if (visible) visibleCount++;
            });
            if (noResults) noResults.hidden = visibleCount !== 0;
        }

        select.dataset.filterKey = datasetKey;
        select.addEventListener('change', apply);
        if (searchInput) searchInput.addEventListener('input', apply);
    }

    function enableBulkSelect(selectAll, table, actionBtn) {
        if (!selectAll || !table || !actionBtn) return;
        const template = actionBtn.dataset.confirmTemplate;

        function checkboxes() {
            return Array.from(table.querySelectorAll('.submission-select'));
        }

        function refresh() {
            const checked = checkboxes().filter(function (cb) { return cb.checked; });
            actionBtn.disabled = checked.length === 0;
            if (template) {
                actionBtn.closest('form').dataset.confirm = template.replace('{n}', checked.length);
            }
        }

        selectAll.addEventListener('change', function () {
            checkboxes().forEach(function (cb) { cb.checked = selectAll.checked; });
            refresh();
        });

        table.addEventListener('change', function (e) {
            if (e.target.classList.contains('submission-select')) refresh();
        });

        refresh();
    }
```

Update the export line at the bottom of the file to:

```javascript
    window.WcmaTableTools = { enableSearch: enableSearch, enableSort: enableSort, enableFilter: enableFilter, enableBulkSelect: enableBulkSelect };
```

- [ ] **Step 5: Add CSS for the filter/pagination/bulk-select controls**

Add to `css/calculator.css`, after the existing `.table-search:focus` rule (around line 1436):

```css
.table-filter {
    padding: calc(var(--spacing-unit) * 0.5);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    font-size: 0.9rem;
    font-family: inherit;
}

.list-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: calc(var(--spacing-unit) * 0.5);
    align-items: center;
}

.list-summary {
    color: #666;
    font-size: 0.85rem;
    margin: 0 0 calc(var(--spacing-unit) * 0.5);
}

.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: var(--spacing-unit);
    margin: var(--spacing-unit) 0;
    font-size: 0.9rem;
}

.pagination a {
    color: var(--secondary-color);
    text-decoration: none;
}

.pagination a:hover {
    text-decoration: underline;
}

.sr-only {
    position: absolute;
    width: 1px; height: 1px;
    padding: 0; margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
```

- [ ] **Step 6: Manually verify**

Run: `cd wcma-calculator && php -S localhost:8765`
Then browse to `admin.php`, and confirm: the total-count line renders, class/status dropdowns filter the visible rows (and compose correctly with the search box), selecting rows enables "Delete Selected" and shows the right count in the confirm dialog, and — with fewer than 50 submissions — no pagination footer appears (add more test rows via `sqlite3` or just trust `LIMIT`/`OFFSET` from the passing Task 1 tests for the >50 case).
Expected: All controls work without a page reload except the pagination links and CSV export link.

- [ ] **Step 7: Commit**

```bash
git add wcma-calculator/admin.php wcma-calculator/js/table-tools.js wcma-calculator/css/calculator.css
git commit -m "feat: add pagination, class/status filters, and bulk-select to admin submissions list"
```

---

### Task 4: List page — bulk delete handler + CSV export

**Files:**
- Modify: `admin.php:39-99` (router)
- Modify: `admin.php` (add `handleBulkDelete`, `handleExport` near `handleDelete`, `admin.php:501-522`)

**Interfaces:**
- Consumes: `db_delete_submissions(PDO $pdo, array $ids): int` and `db_get_submissions(PDO $pdo, ...)` from Task 1, the `bulk-delete-form` markup from Task 3.

- [ ] **Step 1: Add routes**

In `admin.php`, in the `switch ($action)` block, add after the existing `case 'delete':` block (`admin.php:70-75`):

```php
    case 'bulk-delete':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleBulkDelete($pdo, array_map('intval', $_POST['ids'] ?? []));
        break;

    case 'export':
        requireAuth();
        handleExport($pdo, $_GET['sort'] ?? 'submitted_at', $_GET['dir'] ?? 'desc');
        break;
```

- [ ] **Step 2: Implement `handleBulkDelete`**

Add near `handleDelete` (`admin.php:501-522`), reusing its per-submission file cleanup:

```php
function handleBulkDelete(PDO $pdo, array $ids): void {
    $ids = array_filter($ids, fn($id) => $id > 0);
    if (empty($ids)) {
        setFlash('No submissions selected.', 'error');
        header('Location: admin.php');
        exit;
    }

    foreach ($ids as $id) {
        $upload_dir = __DIR__ . '/uploads/' . $id;
        if (is_dir($upload_dir)) {
            foreach (glob($upload_dir . '/*') as $file) {
                unlink($file);
            }
            rmdir($upload_dir);
        }
    }

    $deleted = db_delete_submissions($pdo, $ids);
    setFlash("Deleted {$deleted} submission(s).", 'success');
    header('Location: admin.php');
    exit;
}
```

- [ ] **Step 3: Implement `handleExport`**

Add in the same area:

```php
function handleExport(PDO $pdo, string $sort, string $dir): void {
    $submissions = db_get_submissions($pdo, $sort, $dir);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="wcma-submissions-' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'ID', 'Submitted', 'Name', 'Email', 'Year', 'Make', 'Model',
        'Weight', 'Declared HP', 'Dyno HP', 'Base Ratio', 'Weight Factor',
        'Modification Factor', 'Modified Ratio', 'Class', 'Email Sent',
    ]);
    foreach ($submissions as $s) {
        fputcsv($out, [
            $s['id'], $s['submitted_at'], $s['name'], $s['email'], $s['year'], $s['make'], $s['model'],
            $s['competition_weight'], $s['declared_hp'], $s['dyno_hp'], $s['base_ratio'], $s['weight_factor'],
            $s['modification_factor'], $s['modified_ratio'], $s['calculated_class'], $s['email_sent'] ? 'Yes' : 'No',
        ]);
    }
    fclose($out);
    exit;
}
```

- [ ] **Step 4: Manually verify**

Run: `cd wcma-calculator && php -S localhost:8765`
Then: select a couple of submissions on the list page, click "Delete Selected", confirm in the modal, and verify they're gone and their `uploads/<id>` directories are removed. Separately click "Export CSV" and confirm a valid CSV downloads with the expected columns and one row per submission.
Expected: Bulk delete removes exactly the selected rows and their files; export downloads a well-formed CSV matching the current sort.

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/admin.php
git commit -m "feat: add bulk delete and CSV export actions to admin submissions list"
```

---

### Task 5: Detail page — resend confirmation, email history, linked user account

**Files:**
- Modify: `admin.php:271-282` (Actions card in `renderDetailPage`)
- Modify: `admin.php:255-268` (Contact & Vehicle card)
- Modify: `admin.php:188-198` (`handleView`)

**Interfaces:**
- Consumes: `$s['last_emailed_at']`, `$s['email_send_count']` (Task 1 columns, already present on every `db_get_submission` row since it's `SELECT *`), `db_find_user_by_id(PDO $pdo, int $id): ?array` (existing function).

- [ ] **Step 1: Add resend confirmation and email history to the Actions card**

Replace `admin.php:273-282`:

```php
    <div class="detail-card" style="margin-bottom:1.5rem">
      <h2>Actions</h2>
      <div class="actions">
        <form method="post" action="admin.php?action=resend" style="display:inline"
              data-confirm="Re-send the tech sheet email to <?= h($s['name']) ?> (<?= h($s['email']) ?>) and the admin address?">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <button type="submit" class="btn btn-primary">Re-email Tech Sheet</button>
        </form>
        <button type="button" class="btn btn-secondary no-print" onclick="window.print()">Print</button>
      </div>
      <?php if ($s['email_send_count'] > 0): ?>
      <p class="email-history">Last emailed <?= h(date('M j, Y \a\t g:i A', strtotime($s['last_emailed_at']))) ?> · sent <?= (int)$s['email_send_count'] ?> time<?= $s['email_send_count'] === 1 ? '' : 's' ?></p>
      <?php else: ?>
      <p class="email-history">Never emailed.</p>
      <?php endif; ?>
    </div>
```

- [ ] **Step 2: Fetch the linked user account in `handleView`**

Replace `admin.php:188-198`:

```php
function handleView(PDO $pdo, int $id): void {
    $sub = db_get_submission($pdo, $id);
    if (!$sub) {
        setFlash('Submission not found.', 'error');
        header('Location: admin.php');
        exit;
    }
    $linkedUser = $sub['user_id'] ? db_find_user_by_id($pdo, (int)$sub['user_id']) : null;
    $csrf  = generateCsrfToken();
    $flash = getFlash();
    renderDetailPage($sub, $linkedUser, $csrf, $flash);
}
```

- [ ] **Step 3: Thread `$linkedUser` into `renderDetailPage` and show it in the Contact & Vehicle card**

Update the function signature (`admin.php:200`) from `renderDetailPage(array $s, string $csrf, ?array $flash): void` to:

```php
function renderDetailPage(array $s, ?array $linkedUser, string $csrf, ?array $flash): void {
```

In the Contact & Vehicle table (`admin.php:257-267`), add a row right after the `Email` row:

```php
        <tr><td>Email</td><td><?= h($s['email']) ?></td></tr>
        <?php if ($linkedUser): ?>
        <tr><td>Account</td><td><a href="admin.php?action=users#user-<?= (int)$linkedUser['id'] ?>"><?= h($linkedUser['name']) ?> (<?= h($linkedUser['email']) ?>)</a></td></tr>
        <?php endif; ?>
```

- [ ] **Step 4: Add print rules and history-line CSS**

Add to `css/calculator.css`, in the existing `@media print` block that already hides `.no-print` (the one around line 1033 that hides `.form-actions, .btn`), or as a new block right after it if that block is calculator-page-specific — check its selectors first; if it's scoped to the calculator form, add a **new** block instead:

```css
.email-history {
    color: #666;
    font-size: 0.85rem;
    margin-top: 0.6rem;
}

@media print {
    .detail-layout {
        display: block;
    }
    .page-header nav,
    .no-print {
        display: none !important;
    }
}
```

- [ ] **Step 5: Manually verify**

Run: `cd wcma-calculator && php -S localhost:8765`
Then: open a submission tied to a registered account and confirm the "Account" row links to `admin.php?action=users#user-<id>` (the anchor target is added in Task 6). Click "Re-email Tech Sheet" and confirm the styled confirm modal now appears before sending. After a resend, reload the page and confirm the "Last emailed … sent N time(s)" line updates. Click "Print" and confirm the browser print preview hides the nav/actions and shows a single-column layout.
Expected: All three behaviors work as described.

- [ ] **Step 6: Commit**

```bash
git add wcma-calculator/admin.php wcma-calculator/css/calculator.css
git commit -m "feat: add resend confirmation, email history, linked account, and print view to submission detail"
```

---

### Task 6: Detail page — inline contact editing

**Files:**
- Modify: `admin.php:39-99` (router)
- Modify: `admin.php` (Contact & Vehicle card in `renderDetailPage`, plus a new `handleUpdateContact`)
- Modify: `css/calculator.css` (edit-form styling)

**Interfaces:**
- Consumes: `db_update_submission_contact(PDO $pdo, int $id, array $data): void` from Task 1.

- [ ] **Step 1: Add the route**

In `admin.php`'s router, add after `case 'resend':` (or anywhere in the POST-action group):

```php
    case 'update-contact':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleUpdateContact($pdo, (int)($_POST['id'] ?? 0));
        break;
```

- [ ] **Step 2: Implement the handler**

Add near `handleResend`:

```php
function handleUpdateContact(PDO $pdo, int $id): void {
    $sub = db_get_submission($pdo, $id);
    if (!$sub) {
        setFlash('Submission not found.', 'error');
        header('Location: admin.php');
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $year = trim($_POST['year'] ?? '');
    $make = trim($_POST['make'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $comments = trim($_POST['comments'] ?? '');

    if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        setFlash('Name and a valid email are required.', 'error');
        header('Location: admin.php?action=view&id=' . $id);
        exit;
    }

    db_update_submission_contact($pdo, $id, [
        'name' => $name, 'email' => $email, 'year' => $year, 'make' => $make, 'model' => $model,
        'comments' => $comments !== '' ? $comments : null,
    ]);
    setFlash('Contact details updated.', 'success');
    header('Location: admin.php?action=view&id=' . $id);
    exit;
}
```

- [ ] **Step 3: Replace the static Contact & Vehicle card with a toggleable view/edit pair**

Replace the Contact & Vehicle `<div class="detail-card">` block (`admin.php:255-268` in the original, now shifted by Task 5's edits — locate it by its `<h2>Contact &amp; Vehicle</h2>` heading):

```php
    <div class="detail-card">
      <h2>Contact &amp; Vehicle <button type="button" class="link-button no-print" id="edit-contact-toggle">Edit</button></h2>
      <table class="detail-table" id="contact-view">
        <tr><td>Name</td><td><?= h($s['name']) ?></td></tr>
        <tr><td>Email</td><td><?= h($s['email']) ?></td></tr>
        <?php if ($linkedUser): ?>
        <tr><td>Account</td><td><a href="admin.php?action=users#user-<?= (int)$linkedUser['id'] ?>"><?= h($linkedUser['name']) ?> (<?= h($linkedUser['email']) ?>)</a></td></tr>
        <?php endif; ?>
        <tr><td>Vehicle</td><td><?= h(trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])) ?></td></tr>
        <?php if ($s['comments']): ?><tr><td>Comments</td><td><?= nl2br(h($s['comments'])) ?></td></tr><?php endif; ?>
        <tr><td>Weight</td><td><?= h((string)$s['competition_weight']) ?> lbs</td></tr>
        <tr><td>Declared HP</td><td><?= h((string)$s['declared_hp']) ?></td></tr>
        <?php if ($s['dyno_hp']): ?><tr><td>Dyno HP</td><td><?= h((string)$s['dyno_hp']) ?></td></tr><?php endif; ?>
        <tr><td>Submitted</td><td><?= h(date('F j, Y \a\t g:i A', strtotime($s['submitted_at']))) ?></td></tr>
        <tr><td>Email Sent</td><td><?= $s['email_sent'] ? '✓ Yes' : '⚠ Failed' ?></td></tr>
      </table>
      <form method="post" action="admin.php?action=update-contact" id="contact-edit" class="edit-form" hidden>
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
        <label for="edit-name">Name</label>
        <input type="text" id="edit-name" name="name" value="<?= h($s['name']) ?>" required>
        <label for="edit-email">Email</label>
        <input type="email" id="edit-email" name="email" value="<?= h($s['email']) ?>" required>
        <label for="edit-year">Year</label>
        <input type="text" id="edit-year" name="year" value="<?= h($s['year']) ?>">
        <label for="edit-make">Make</label>
        <input type="text" id="edit-make" name="make" value="<?= h($s['make']) ?>">
        <label for="edit-model">Model</label>
        <input type="text" id="edit-model" name="model" value="<?= h($s['model']) ?>">
        <label for="edit-comments">Comments</label>
        <textarea id="edit-comments" name="comments" rows="3"><?= h($s['comments'] ?? '') ?></textarea>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Save</button>
          <button type="button" class="btn btn-secondary" id="edit-contact-cancel">Cancel</button>
        </div>
      </form>
    </div>
```

(Note: this supersedes the "Account" row added inline in Task 5, Step 3 — since Task 6 touches the same card, apply Task 5 first, then this replacement carries the Account row forward. If executing Task 6 without Task 5 already applied, drop the `$linkedUser` block.)

- [ ] **Step 4: Add the toggle script**

Add right before `</body>` in `renderDetailPage`, after the existing `<script src="js/form-feedback.js"></script>` line:

```php
<script>
(function () {
  var toggle = document.getElementById('edit-contact-toggle');
  var cancel = document.getElementById('edit-contact-cancel');
  var view = document.getElementById('contact-view');
  var edit = document.getElementById('contact-edit');
  if (!toggle) return;
  toggle.addEventListener('click', function () { view.hidden = true; edit.hidden = false; });
  cancel.addEventListener('click', function () { view.hidden = false; edit.hidden = true; });
})();
</script>
```

- [ ] **Step 5: Add edit-form CSS**

Add to `css/calculator.css`, after `.detail-table` rules:

```css
.edit-form {
    display: flex;
    flex-direction: column;
    gap: calc(var(--spacing-unit) * 0.4);
    margin-top: var(--spacing-unit);
    padding-top: var(--spacing-unit);
    border-top: 1px solid var(--border-color);
}

.edit-form label {
    font-size: 0.85rem;
    font-weight: bold;
    color: #444;
}

.edit-form input,
.edit-form textarea {
    padding: 0.4rem;
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    font-family: inherit;
    font-size: 0.9rem;
}

.edit-form .form-actions {
    display: flex;
    gap: calc(var(--spacing-unit) * 0.5);
    margin-top: 0.4rem;
}
```

- [ ] **Step 6: Manually verify**

Run: `cd wcma-calculator && php -S localhost:8765`
Then: open a submission, click "Edit", change the name and vehicle model, save, and confirm the view reflects the change and a success flash appears. Try submitting with an empty name and confirm the validation error flash appears and nothing is saved.
Expected: Edits persist correctly; invalid input is rejected with a clear message.

- [ ] **Step 7: Commit**

```bash
git add wcma-calculator/admin.php wcma-calculator/css/calculator.css
git commit -m "feat: allow inline editing of submission contact/vehicle details"
```

---

### Task 7: Detail page — image lightbox

**Files:**
- Create: `js/lightbox.js`
- Modify: `admin.php` (Uploaded Files section, `admin.php:284-308` in the original)
- Modify: `css/calculator.css`

**Interfaces:**
- Produces: a global click handler keyed off `img[data-lightbox]` — no exported API needed, matches the self-contained IIFE style of `js/confirm-modal.js`.

- [ ] **Step 1: Create `js/lightbox.js`**

```javascript
/**
 * Click-to-enlarge overlay for any <img data-lightbox> on the page.
 */
(function () {
    let overlay = null;

    function buildOverlay() {
        const el = document.createElement('div');
        el.className = 'lightbox-overlay';
        el.hidden = true;
        el.innerHTML = '<img class="lightbox-image" alt="">';
        document.body.appendChild(el);
        el.addEventListener('click', close);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') close();
        });
        return el;
    }

    function open(src, alt) {
        if (!overlay) overlay = buildOverlay();
        const img = overlay.querySelector('.lightbox-image');
        img.src = src;
        img.alt = alt || '';
        overlay.hidden = false;
    }

    function close() {
        if (overlay) overlay.hidden = true;
    }

    document.addEventListener('click', function (e) {
        const target = e.target.closest('img[data-lightbox]');
        if (!target) return;
        open(target.src, target.alt);
    });
})();
```

- [ ] **Step 2: Mark the file thumbnail as lightbox-enabled and load the script**

In `admin.php`, in the Uploaded Files loop (locate the `<img src="<?= $url ?>" class="file-thumb" alt="<?= h($f['label']) ?>">` line), change to:

```php
        <img src="<?= $url ?>" class="file-thumb" data-lightbox alt="<?= h($f['label']) ?>">
```

Add `<script src="js/lightbox.js"></script>` next to the other `<script>` tags at the bottom of `renderDetailPage` (alongside `js/form-feedback.js`).

- [ ] **Step 3: Add lightbox CSS**

Add to `css/calculator.css`, after `.file-thumb`:

```css
.file-thumb[data-lightbox] {
    cursor: zoom-in;
}

.lightbox-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.85);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1100;
    padding: calc(var(--spacing-unit) * 2);
    cursor: zoom-out;
}

.lightbox-image {
    max-width: 100%;
    max-height: 100%;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
}
```

- [ ] **Step 4: Manually verify**

Run: `cd wcma-calculator && php -S localhost:8765`
Then: open a submission with a car image, click the thumbnail, confirm a full-size overlay appears, and confirm clicking the overlay or pressing Escape closes it.
Expected: Lightbox opens/closes correctly and doesn't interfere with the non-image file links.

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/js/lightbox.js wcma-calculator/admin.php wcma-calculator/css/calculator.css
git commit -m "feat: add click-to-enlarge lightbox for submission car images"
```

---

### Task 8: Users page — role filter, submission counts, deactivate/reactivate, promote confirmation

**Files:**
- Modify: `admin.php:39-99` (router)
- Modify: `admin.php:317-322` (`handleUsersList`)
- Modify: `admin.php:324-338` (`handleSetRole` — reused pattern for the new `handleSetActive`)
- Modify: `admin.php:340-410` (`renderUsersPage`)

**Interfaces:**
- Consumes: `db_count_submissions_by_user(PDO $pdo): array`, `db_set_user_active(PDO $pdo, int $id, bool $active): void`, `db_count_active_admins(PDO $pdo): int` from Task 1.

- [ ] **Step 1: Add routes for deactivate/activate**

In the router, add after the existing `case 'demote':` block (`admin.php:89-94`):

```php
    case 'deactivate':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=users'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleSetActive($pdo, (int)($_POST['id'] ?? 0), false);
        break;

    case 'activate':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=users'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleSetActive($pdo, (int)($_POST['id'] ?? 0), true);
        break;
```

- [ ] **Step 2: Pass submission counts into the users page**

Replace `admin.php:317-322`:

```php
function handleUsersList(PDO $pdo): void {
    $users = db_get_all_users($pdo);
    $submissionCounts = db_count_submissions_by_user($pdo);
    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderUsersPage($users, $submissionCounts, $csrf, $flash);
}
```

- [ ] **Step 3: Implement `handleSetActive` next to `handleSetRole`**

Add after `handleSetRole` (`admin.php:324-338`):

```php
function handleSetActive(PDO $pdo, int $id, bool $active): void {
    if (!$active && db_count_active_admins($pdo) <= 1) {
        $target = db_find_user_by_id($pdo, $id);
        if ($target && $target['role'] === 'admin') {
            setFlash('Cannot deactivate the last remaining active admin.', 'error');
            header('Location: admin.php?action=users');
            exit;
        }
    }

    db_set_user_active($pdo, $id, $active);
    setFlash($active ? 'User reactivated.' : 'User deactivated.', 'success');
    header('Location: admin.php?action=users');
    exit;
}
```

- [ ] **Step 4: Update `renderUsersPage`: signature, role filter, submission-count column, status column/actions, promote confirm**

Replace `admin.php:340-410` in full:

```php
function renderUsersPage(array $users, array $submissionCounts, string $csrf, ?array $flash): void {
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
  <?php renderSiteHeader('Manage Users', '<a href="admin.php">Submissions</a>' . renderCommonNav('admin')); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
  <?php if (!empty($users)): ?>
  <div class="list-toolbar">
    <input type="search" id="users-search" class="table-search" placeholder="Search users…" aria-label="Search users">
    <select id="users-role-filter" class="table-filter" aria-label="Filter by role">
      <option value="">All roles</option>
      <option value="admin">Admin</option>
      <option value="user">User</option>
    </select>
  </div>
  <?php endif; ?>
  <table class="data-table" id="users-table">
    <thead><tr>
      <th data-sort data-sort-type="text">Email</th>
      <th data-sort data-sort-type="text">Name</th>
      <th data-sort data-sort-type="text">Role</th>
      <th>Login Method</th>
      <th data-sort data-sort-type="number">Submissions</th>
      <th>Status</th>
      <th data-sort data-sort-type="date">Created</th>
      <th>Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr id="user-<?= (int)$u['id'] ?>" data-role="<?= h($u['role']) ?>">
        <td><?= h($u['email']) ?></td>
        <td><?= h($u['name']) ?></td>
        <td class="<?= $u['role'] === 'admin' ? 'badge-admin' : '' ?>"><?= h($u['role']) ?></td>
        <td><?= h(trim(($u['password_hash'] ? 'Password ' : '') . ($u['google_id'] ? 'Google' : ''))) ?></td>
        <td><?= (int)($submissionCounts[(int)$u['id']] ?? 0) ?></td>
        <td class="<?= $u['active'] ? 'badge-ok' : 'badge-fail' ?>"><?= $u['active'] ? 'Active' : 'Inactive' ?></td>
        <td data-sort-value="<?= h($u['created_at']) ?>"><?= h(date('M j, Y', strtotime($u['created_at']))) ?></td>
        <td>
          <?php if ($u['role'] === 'admin'): ?>
          <form method="post" action="admin.php?action=demote" style="display:inline" data-confirm="Remove admin access for <?= h($u['email']) ?>?">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button type="submit" class="btn-role">Demote</button>
          </form>
          <?php else: ?>
          <form method="post" action="admin.php?action=promote" style="display:inline" data-confirm="Grant admin access to <?= h($u['email']) ?>?">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button type="submit" class="btn-role">Promote</button>
          </form>
          <?php endif; ?>
          <?php if ($u['active']): ?>
          <form method="post" action="admin.php?action=deactivate" style="display:inline" data-confirm="Deactivate <?= h($u['email']) ?>? They won't be able to sign in until reactivated.">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button type="submit" class="btn-role">Deactivate</button>
          </form>
          <?php else: ?>
          <form method="post" action="admin.php?action=activate" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button type="submit" class="btn-role">Reactivate</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="no-results-message" hidden>No users match your search.</p>
</div>
<script src="js/table-tools.js"></script>
<script src="js/confirm-modal.js"></script>
<script src="js/form-feedback.js"></script>
<script>
  WcmaTableTools.enableSearch(document.getElementById('users-search'), document.getElementById('users-table'));
  WcmaTableTools.enableSort(document.getElementById('users-table'));
  WcmaTableTools.enableFilter(document.getElementById('users-role-filter'), document.getElementById('users-table'), 'role');
</script>
</body>
</html><?php
}
```

Note: this table now sets `id="user-<id>"` on each row, which is what the detail page's "Account" link (Task 5/6) anchors to.

- [ ] **Step 5: Manually verify**

Run: `cd wcma-calculator && php -S localhost:8765`
Then: on `admin.php?action=users`, confirm the role filter narrows rows, the submission-count column shows correct numbers (cross-check against a user with known submissions), deactivating a non-admin user works and flips their Status to "Inactive", and attempting to deactivate the sole remaining active admin shows the "Cannot deactivate the last remaining active admin" error. Confirm promote now also shows a confirmation dialog. Finally, follow an "Account" link from a submission detail page and confirm the browser scrolls to that user's row.
Expected: All of the above behave as described; the last-admin guard mirrors the existing demote guard exactly.

- [ ] **Step 6: Commit**

```bash
git add wcma-calculator/admin.php
git commit -m "feat: add role filter, submission counts, and account deactivation to admin users page"
```

---

## Self-Review Notes

- **Coverage:** pagination ✅ (Task 3), class/status filters ✅ (Task 3), submission count ✅ (Task 3), bulk delete ✅ (Task 3/4), CSV export ✅ (Task 4), resend confirmation ✅ (Task 5), email history ✅ (Task 1/5), inline edit ✅ (Task 6), image lightbox ✅ (Task 7), print view ✅ (Task 5), linked user account ✅ (Task 5/6), users role filter ✅ (Task 8), submissions-per-user ✅ (Task 1/8), user deactivation ✅ (Task 1/2/8), promote confirmation ✅ (Task 8), aria-labels on status icons ✅ (Task 3). Responsive tables: already implemented, no task needed.
- **Ordering dependency:** Task 6 edits the same Contact & Vehicle card Task 5 touches — Task 6's step 3 explicitly carries forward Task 5's "Account" row addition. Execute Task 5 before Task 6 (both are already ordered that way in this plan).
- **Known limitation to flag to the user after execution:** deactivating a user only blocks future logins; it does not invalidate an already-active session (sessions cache role/id at login time in `session_bootstrap.php`, with no per-request DB re-check — consistent with how promote/demote already behaves for `is_admin()`). Worth a follow-up if session invalidation ever becomes a real requirement, but out of scope here.
