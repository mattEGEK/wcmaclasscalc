# User Accounts & Admin Auth Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Google OAuth + email/password user accounts shared between the public calculator and the admin panel, replacing the admin PIN with role-based access, plus a capped "My Submissions" page for logged-in competitors.

**Architecture:** New `auth.php` action-router (login/register/OAuth/reset) and `account.php` (My Submissions) alongside the existing `admin.php` and `car-classing.php`, all sharing one PHP session. `db.php` gains `users`/`password_resets` tables and a `user_id` column on `submissions`. No framework, no Composer — Google OAuth via plain cURL, PHPUnit via a vendored `.phar`.

**Tech Stack:** PHP 8.x (PDO/SQLite), plain HTML/CSS in existing style, PHPMailer (already vendored), PHPUnit (standalone phar, new).

**Spec:** `docs/superpowers/specs/2026-09-15-user-accounts-and-admin-auth-design.md`

## Global Constraints

- No Composer, no frameworks — plain PHP functions/files, consistent with the existing codebase.
- All new libraries are manually vendored (like `phpmailer/src/`), never `composer install`.
- Passwords hashed with `password_hash()` (bcrypt); reset tokens stored only as `hash('sha256', $token)`.
- All state-changing POST actions validate the existing CSRF pattern (`generateCsrfToken()`/`validateCsrfToken()`).
- All output escaped with `htmlspecialchars()` (project's `h()` helper where available).
- All DB access via PDO prepared statements.
- Bootstrap admin email: `matt.sinfield@gmail.com` (case-insensitive match) gets `role = admin` at account-creation time only.
- Soft cap of 20 submissions per user in "My Submissions" — no hard block, banner only past that count.
- Tests run via `php phpunit.phar tests/` — PHP 8.x CLI must be available on the machine executing this plan.

---

### Task 1: `users` / `password_resets` schema + user CRUD in `db.php`

**Files:**
- Modify: `wcma-calculator/db.php`
- Create: `wcma-calculator/tests/bootstrap.php`
- Create: `wcma-calculator/tests/DbUsersTest.php`
- Create: `wcma-calculator/phpunit.phar` (vendored binary — see Step 1)
- Create: `wcma-calculator/phpunit.xml`

**Interfaces:**
- Produces: `db_create_user(PDO $pdo, array $data): int` — `$data` keys: `email`, `name`, `password_hash` (nullable), `google_id` (nullable)
- Produces: `db_find_user_by_email(PDO $pdo, string $email): ?array`
- Produces: `db_find_user_by_google_id(PDO $pdo, string $google_id): ?array`
- Produces: `db_find_user_by_id(PDO $pdo, int $id): ?array`
- Produces: `db_get_all_users(PDO $pdo): array` — ordered by `created_at ASC`
- Produces: `db_set_user_role(PDO $pdo, int $id, string $role): void`
- Produces: `db_count_admins(PDO $pdo): int`
- Produces: `db_link_google_id(PDO $pdo, int $user_id, string $google_id): void`
- Produces: constant `BOOTSTRAP_ADMIN_EMAIL` (default `'matt.sinfield@gmail.com'`, overridable like `DB_PATH`)

- [ ] **Step 1: Vendor PHPUnit and write the test bootstrap**

Download the standalone PHPUnit phar (PHP 8-compatible build) into the project, matching how PHPMailer's `src/` was manually vendored:

```bash
cd wcma-calculator
curl -L -o phpunit.phar https://phar.phpunit.de/phpunit-10.5.phar
php phpunit.phar --version
```

If `curl`/network access isn't available in this environment, download `phpunit-10.5.phar` from https://phar.phpunit.de/phpunit-10.5.phar on a machine that has access and place it at `wcma-calculator/phpunit.phar`.

Create `wcma-calculator/phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="tests/bootstrap.php" colors="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

Create `wcma-calculator/tests/bootstrap.php`:

```php
<?php
require __DIR__ . '/../db.php';

function make_temp_pdo(): PDO {
    $path = sys_get_temp_dir() . '/wcma_test_' . uniqid() . '.db';
    if (!defined('DB_PATH')) {
        define('DB_PATH', $path);
    }
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    db_init($pdo);
    return $pdo;
}
```

- [ ] **Step 2: Write the failing test for user creation and lookup**

Create `wcma-calculator/tests/DbUsersTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

final class DbUsersTest extends TestCase
{
    public function testCreateAndFindUserByEmail(): void
    {
        $pdo = make_temp_pdo();
        $id = db_create_user($pdo, [
            'email' => 'racer@example.com',
            'name' => 'Racer McRace',
            'password_hash' => password_hash('secret123', PASSWORD_BCRYPT),
            'google_id' => null,
        ]);

        $this->assertIsInt($id);
        $user = db_find_user_by_email($pdo, 'racer@example.com');
        $this->assertNotNull($user);
        $this->assertSame('Racer McRace', $user['name']);
        $this->assertSame('user', $user['role']);
        $this->assertNull($user['google_id']);
    }

    public function testBootstrapAdminEmailGetsAdminRole(): void
    {
        $pdo = make_temp_pdo();
        $id = db_create_user($pdo, [
            'email' => 'Matt.Sinfield@gmail.com', // case-insensitive match
            'name' => 'Matt Sinfield',
            'password_hash' => password_hash('secret123', PASSWORD_BCRYPT),
            'google_id' => null,
        ]);

        $user = db_find_user_by_id($pdo, $id);
        $this->assertSame('admin', $user['role']);
    }

    public function testFindUserByGoogleId(): void
    {
        $pdo = make_temp_pdo();
        db_create_user($pdo, [
            'email' => 'g@example.com',
            'name' => 'Google User',
            'password_hash' => null,
            'google_id' => 'g-12345',
        ]);

        $user = db_find_user_by_google_id($pdo, 'g-12345');
        $this->assertNotNull($user);
        $this->assertSame('g@example.com', $user['email']);
    }

    public function testSetUserRoleAndCountAdmins(): void
    {
        $pdo = make_temp_pdo();
        $id1 = db_create_user($pdo, ['email' => 'a@example.com', 'name' => 'A', 'password_hash' => 'x', 'google_id' => null]);
        db_create_user($pdo, ['email' => 'b@example.com', 'name' => 'B', 'password_hash' => 'x', 'google_id' => null]);

        $this->assertSame(0, db_count_admins($pdo));
        db_set_user_role($pdo, $id1, 'admin');
        $this->assertSame(1, db_count_admins($pdo));
    }

    public function testLinkGoogleIdToExistingUser(): void
    {
        $pdo = make_temp_pdo();
        $id = db_create_user($pdo, ['email' => 'link@example.com', 'name' => 'Link', 'password_hash' => 'x', 'google_id' => null]);
        db_link_google_id($pdo, $id, 'g-99999');

        $user = db_find_user_by_google_id($pdo, 'g-99999');
        $this->assertSame($id, $user['id']);
    }

    public function testGetAllUsersOrderedByCreatedAt(): void
    {
        $pdo = make_temp_pdo();
        db_create_user($pdo, ['email' => 'first@example.com', 'name' => 'First', 'password_hash' => 'x', 'google_id' => null]);
        db_create_user($pdo, ['email' => 'second@example.com', 'name' => 'Second', 'password_hash' => 'x', 'google_id' => null]);

        $users = db_get_all_users($pdo);
        $this->assertCount(2, $users);
        $this->assertSame('first@example.com', $users[0]['email']);
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

```bash
cd wcma-calculator
php phpunit.phar
```

Expected: FAIL — `db_create_user()` etc. not defined, and `users` table doesn't exist yet.

- [ ] **Step 4: Add the `users` table and CRUD functions to `db.php`**

Add near the top of `wcma-calculator/db.php`, after the `DB_PATH` constant block:

```php
if (!defined('BOOTSTRAP_ADMIN_EMAIL')) {
    define('BOOTSTRAP_ADMIN_EMAIL', 'matt.sinfield@gmail.com');
}
```

Inside `db_init()`, after the `submissions` table's `CREATE TABLE IF NOT EXISTS` block, add:

```php
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            email         TEXT NOT NULL UNIQUE,
            password_hash TEXT,
            google_id     TEXT UNIQUE,
            name          TEXT NOT NULL,
            role          TEXT NOT NULL DEFAULT 'user',
            created_at    DATETIME NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_resets (
            token_hash TEXT PRIMARY KEY,
            user_id    INTEGER NOT NULL,
            expires_at DATETIME NOT NULL
        )
    ");
```

Add the CRUD functions after `db_delete_submission()`:

```php
// ── Users ─────────────────────────────────────────────────────────────────────

function db_create_user(PDO $pdo, array $data): int {
    $role = (strtolower($data['email']) === strtolower(BOOTSTRAP_ADMIN_EMAIL)) ? 'admin' : 'user';
    $stmt = $pdo->prepare("
        INSERT INTO users (email, password_hash, google_id, name, role, created_at)
        VALUES (:email, :password_hash, :google_id, :name, :role, :created_at)
    ");
    $stmt->execute([
        ':email'         => $data['email'],
        ':password_hash' => $data['password_hash'] ?? null,
        ':google_id'     => $data['google_id'] ?? null,
        ':name'          => $data['name'],
        ':role'          => $role,
        ':created_at'    => date('Y-m-d H:i:s'),
    ]);
    return (int)$pdo->lastInsertId();
}

function db_find_user_by_email(PDO $pdo, string $email): ?array {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email COLLATE NOCASE");
    $stmt->execute([':email' => $email]);
    return $stmt->fetch() ?: null;
}

function db_find_user_by_google_id(PDO $pdo, string $google_id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE google_id = :google_id");
    $stmt->execute([':google_id' => $google_id]);
    return $stmt->fetch() ?: null;
}

function db_find_user_by_id(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function db_get_all_users(PDO $pdo): array {
    return $pdo->query("SELECT * FROM users ORDER BY created_at ASC")->fetchAll();
}

function db_set_user_role(PDO $pdo, int $id, string $role): void {
    $pdo->prepare("UPDATE users SET role = :role WHERE id = :id")
        ->execute([':role' => $role, ':id' => $id]);
}

function db_count_admins(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
}

function db_link_google_id(PDO $pdo, int $user_id, string $google_id): void {
    $pdo->prepare("UPDATE users SET google_id = :google_id WHERE id = :id")
        ->execute([':google_id' => $google_id, ':id' => $user_id]);
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
cd wcma-calculator
php phpunit.phar
```

Expected: PASS (6 tests, `DbUsersTest`)

- [ ] **Step 6: Commit**

```bash
git add wcma-calculator/db.php wcma-calculator/phpunit.phar wcma-calculator/phpunit.xml wcma-calculator/tests/bootstrap.php wcma-calculator/tests/DbUsersTest.php
git commit -m "feat: add users table and user CRUD functions with bootstrap admin"
```

---

### Task 2: Password reset tokens + `submissions.user_id` column

**Files:**
- Modify: `wcma-calculator/db.php`
- Create: `wcma-calculator/tests/DbPasswordResetsTest.php`
- Create: `wcma-calculator/tests/DbSubmissionsUserIdTest.php`

**Interfaces:**
- Consumes: `db_create_user`, `make_temp_pdo()` (Task 1)
- Produces: `db_create_password_reset(PDO $pdo, int $user_id, string $token_hash, string $expires_at): void`
- Produces: `db_get_password_reset(PDO $pdo, string $token_hash): ?array`
- Produces: `db_delete_password_reset(PDO $pdo, string $token_hash): void`
- Produces: `db_delete_password_resets_for_user(PDO $pdo, int $user_id): void`
- Produces: `submissions.user_id` column (nullable INTEGER), added via guarded `ALTER TABLE`
- Modifies: `db_insert_submission(PDO $pdo, array $data): int` — now accepts an optional `:user_id` key (defaults to `null` if absent)
- Produces: `db_get_user_submissions(PDO $pdo, int $user_id): array` — ordered `submitted_at DESC`
- Produces: `db_count_user_submissions(PDO $pdo, int $user_id): int`
- Produces: `db_get_user_submission(PDO $pdo, int $user_id, int $id): ?array` — ownership-scoped fetch

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/DbPasswordResetsTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

final class DbPasswordResetsTest extends TestCase
{
    public function testCreateAndGetPasswordReset(): void
    {
        $pdo = make_temp_pdo();
        $userId = db_create_user($pdo, ['email' => 'r@example.com', 'name' => 'R', 'password_hash' => 'x', 'google_id' => null]);
        $tokenHash = hash('sha256', 'plaintoken');
        $expires = date('Y-m-d H:i:s', time() + 3600);

        db_create_password_reset($pdo, $userId, $tokenHash, $expires);

        $row = db_get_password_reset($pdo, $tokenHash);
        $this->assertNotNull($row);
        $this->assertSame($userId, $row['user_id']);
    }

    public function testDeletePasswordReset(): void
    {
        $pdo = make_temp_pdo();
        $userId = db_create_user($pdo, ['email' => 'r2@example.com', 'name' => 'R2', 'password_hash' => 'x', 'google_id' => null]);
        $tokenHash = hash('sha256', 'anothertoken');
        db_create_password_reset($pdo, $userId, $tokenHash, date('Y-m-d H:i:s', time() + 3600));

        db_delete_password_reset($pdo, $tokenHash);

        $this->assertNull(db_get_password_reset($pdo, $tokenHash));
    }

    public function testDeletePasswordResetsForUser(): void
    {
        $pdo = make_temp_pdo();
        $userId = db_create_user($pdo, ['email' => 'r3@example.com', 'name' => 'R3', 'password_hash' => 'x', 'google_id' => null]);
        db_create_password_reset($pdo, $userId, hash('sha256', 't1'), date('Y-m-d H:i:s', time() + 3600));
        db_create_password_reset($pdo, $userId, hash('sha256', 't2'), date('Y-m-d H:i:s', time() + 3600));

        db_delete_password_resets_for_user($pdo, $userId);

        $this->assertNull(db_get_password_reset($pdo, hash('sha256', 't1')));
        $this->assertNull(db_get_password_reset($pdo, hash('sha256', 't2')));
    }
}
```

Create `wcma-calculator/tests/DbSubmissionsUserIdTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

final class DbSubmissionsUserIdTest extends TestCase
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
        ];
    }

    public function testInsertSubmissionWithUserId(): void
    {
        $pdo = make_temp_pdo();
        $userId = db_create_user($pdo, ['email' => 'u@example.com', 'name' => 'U', 'password_hash' => 'x', 'google_id' => null]);

        $data = $this->minimalSubmissionData();
        $data[':user_id'] = $userId;
        $id = db_insert_submission($pdo, $data);

        $sub = db_get_submission($pdo, $id);
        $this->assertSame($userId, $sub['user_id']);
    }

    public function testInsertSubmissionWithoutUserIdIsNull(): void
    {
        $pdo = make_temp_pdo();
        $data = $this->minimalSubmissionData();
        $id = db_insert_submission($pdo, $data);

        $sub = db_get_submission($pdo, $id);
        $this->assertNull($sub['user_id']);
    }

    public function testGetUserSubmissionsAndCount(): void
    {
        $pdo = make_temp_pdo();
        $userId = db_create_user($pdo, ['email' => 'u2@example.com', 'name' => 'U2', 'password_hash' => 'x', 'google_id' => null]);

        $data = $this->minimalSubmissionData();
        $data[':user_id'] = $userId;
        db_insert_submission($pdo, $data);
        db_insert_submission($pdo, $data);

        $this->assertSame(2, db_count_user_submissions($pdo, $userId));
        $this->assertCount(2, db_get_user_submissions($pdo, $userId));
    }

    public function testGetUserSubmissionOwnershipScoped(): void
    {
        $pdo = make_temp_pdo();
        $ownerId = db_create_user($pdo, ['email' => 'owner@example.com', 'name' => 'Owner', 'password_hash' => 'x', 'google_id' => null]);
        $otherId = db_create_user($pdo, ['email' => 'other@example.com', 'name' => 'Other', 'password_hash' => 'x', 'google_id' => null]);

        $data = $this->minimalSubmissionData();
        $data[':user_id'] = $ownerId;
        $subId = db_insert_submission($pdo, $data);

        $this->assertNotNull(db_get_user_submission($pdo, $ownerId, $subId));
        $this->assertNull(db_get_user_submission($pdo, $otherId, $subId));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
cd wcma-calculator
php phpunit.phar
```

Expected: FAIL — password_resets functions undefined, `user_id` column/functions missing.

- [ ] **Step 3: Implement in `db.php`**

Add to `db_init()`, after the `password_resets` table creation (from Task 1):

```php
    // Add user_id to submissions if migrating an existing DB
    $columns = $pdo->query("PRAGMA table_info(submissions)")->fetchAll();
    $hasUserId = false;
    foreach ($columns as $col) {
        if ($col['name'] === 'user_id') { $hasUserId = true; break; }
    }
    if (!$hasUserId) {
        $pdo->exec("ALTER TABLE submissions ADD COLUMN user_id INTEGER");
    }
```

Replace the `db_insert_submission()` function entirely with:

```php
function db_insert_submission(PDO $pdo, array $data): int {
    $data[':user_id'] = $data[':user_id'] ?? null;
    $stmt = $pdo->prepare("
        INSERT INTO submissions (
            submitted_at, name, email, year, make, model, comments,
            competition_weight, declared_hp, dyno_hp,
            chassis_display, body_mods_display, transmission_display,
            drivetrain_display, tires_display, brake_suspension,
            chassis_value, body_mods_value, transmission_value,
            drivetrain_value, tires_value, brake_suspension_value,
            weight_factor, modification_factor, base_ratio, modified_ratio,
            calculated_class, email_sent, user_id
        ) VALUES (
            :submitted_at, :name, :email, :year, :make, :model, :comments,
            :competition_weight, :declared_hp, :dyno_hp,
            :chassis_display, :body_mods_display, :transmission_display,
            :drivetrain_display, :tires_display, :brake_suspension,
            :chassis_value, :body_mods_value, :transmission_value,
            :drivetrain_value, :tires_value, :brake_suspension_value,
            :weight_factor, :modification_factor, :base_ratio, :modified_ratio,
            :calculated_class, 0, :user_id
        )
    ");
    $stmt->execute($data);
    return (int)$pdo->lastInsertId();
}
```

Add after `db_get_submission()`:

```php
function db_get_user_submissions(PDO $pdo, int $user_id): array {
    $stmt = $pdo->prepare("SELECT * FROM submissions WHERE user_id = :user_id ORDER BY submitted_at DESC");
    $stmt->execute([':user_id' => $user_id]);
    return $stmt->fetchAll();
}

function db_count_user_submissions(PDO $pdo, int $user_id): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $user_id]);
    return (int)$stmt->fetchColumn();
}

function db_get_user_submission(PDO $pdo, int $user_id, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM submissions WHERE id = :id AND user_id = :user_id");
    $stmt->execute([':id' => $id, ':user_id' => $user_id]);
    return $stmt->fetch() ?: null;
}

// ── Password resets ──────────────────────────────────────────────────────────

function db_create_password_reset(PDO $pdo, int $user_id, string $token_hash, string $expires_at): void {
    $pdo->prepare("INSERT INTO password_resets (token_hash, user_id, expires_at) VALUES (:token_hash, :user_id, :expires_at)")
        ->execute([':token_hash' => $token_hash, ':user_id' => $user_id, ':expires_at' => $expires_at]);
}

function db_get_password_reset(PDO $pdo, string $token_hash): ?array {
    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token_hash = :token_hash");
    $stmt->execute([':token_hash' => $token_hash]);
    return $stmt->fetch() ?: null;
}

function db_delete_password_reset(PDO $pdo, string $token_hash): void {
    $pdo->prepare("DELETE FROM password_resets WHERE token_hash = :token_hash")->execute([':token_hash' => $token_hash]);
}

function db_delete_password_resets_for_user(PDO $pdo, int $user_id): void {
    $pdo->prepare("DELETE FROM password_resets WHERE user_id = :user_id")->execute([':user_id' => $user_id]);
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
cd wcma-calculator
php phpunit.phar
```

Expected: PASS (all tests, including Task 1's)

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/db.php wcma-calculator/tests/DbPasswordResetsTest.php wcma-calculator/tests/DbSubmissionsUserIdTest.php
git commit -m "feat: add password reset tokens and user_id on submissions"
```

---

### Task 3: Shared session bootstrap + rate-limiting reuse

**Files:**
- Create: `wcma-calculator/session_bootstrap.php`

**Interfaces:**
- Produces: `require`-able file that calls `session_set_cookie_params()` then `session_start()` — every entry point (`auth.php`, `account.php`, `admin.php`, `car-classing.php`, `session-status.php`) requires this instead of calling `session_start()` directly.
- Produces: `current_user(): ?array` — reads `$_SESSION['user_id']`, returns `null` if not logged in (does not hit the DB; returns `['id' => ..., 'role' => ...]` from session data)
- Produces: `is_admin(): bool`
- Produces: `login_user(array $user): void` — sets `$_SESSION['user_id']`, `$_SESSION['user_name']`, `$_SESSION['user_role']`, calls `session_regenerate_id(true)`

No automated test for this file — it deals in `$_SESSION`/`header()` globals that PHPUnit can't exercise without a full HTTP request (documented as manual-only in the spec). Verified in Task 5 (login) manually.

- [ ] **Step 1: Create `session_bootstrap.php`**

```php
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function current_user(): ?array {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    return [
        'id'   => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'],
        'role' => $_SESSION['user_role'],
    ];
}

function is_admin(): bool {
    $user = current_user();
    return $user !== null && $user['role'] === 'admin';
}

function login_user(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];
}
```

- [ ] **Step 2: Commit**

```bash
git add wcma-calculator/session_bootstrap.php
git commit -m "feat: add shared session bootstrap with hardened cookie params"
```

---

### Task 4: `auth.php` — register, login, logout (email/password)

**Files:**
- Create: `wcma-calculator/auth.php`

**Interfaces:**
- Consumes: `session_bootstrap.php` (`login_user`, `current_user`), `db.php` (`db_create_user`, `db_find_user_by_email`, `db_is_locked_out`, `db_record_failed_attempt`, `db_clear_login_attempts`), `admin.php`'s existing `generateCsrfToken()`/`validateCsrfToken()`/`setFlash()`/`getFlash()`/`h()` pattern (re-declared locally since `admin.php` isn't shared as a library)
- Produces: routes `?action=register`, `?action=login` (GET/POST), `?action=logout` — later tasks (5, 6, 7) add `google-login`, `google-callback`, `forgot-password`, `reset-password` to the same switch statement in this file.

- [ ] **Step 1: Create `auth.php` with CSRF/flash helpers and the register/login/logout routes**

```php
<?php
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';

date_default_timezone_set('America/Denver');

$pdo = db_connect();
db_init($pdo);

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

function renderAuthPage(string $title, string $bodyHtml): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> — WCMA Calculator</title>
<style>
  body { font-family: Arial, sans-serif; background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
  .box { background: #fff; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,.15); padding: 2rem; width: 100%; max-width: 380px; }
  h1 { margin: 0 0 1.5rem; font-size: 1.3rem; color: #1a5490; text-align: center; }
  label { display: block; font-size: .9rem; font-weight: bold; margin-bottom: .3rem; margin-top: .8rem; }
  input[type=text], input[type=email], input[type=password] { width: 100%; padding: .6rem .8rem; border: 1px solid #ccc; border-radius: 4px; font-size: 1rem; box-sizing: border-box; }
  button.primary { margin-top: 1.2rem; width: 100%; padding: .7rem; background: #1a5490; color: #fff; border: none; border-radius: 4px; font-size: 1rem; cursor: pointer; }
  button.primary:hover { background: #154070; }
  .google-btn { margin-top: .8rem; width: 100%; padding: .7rem; background: #fff; color: #444; border: 1px solid #ccc; border-radius: 4px; font-size: 1rem; cursor: pointer; text-align: center; text-decoration: none; display: block; }
  .google-btn:hover { background: #f5f5f5; }
  .error { background: #fde; border: 1px solid #e88; border-radius: 4px; padding: .6rem .8rem; margin-bottom: 1rem; font-size: .9rem; color: #900; }
  .success { background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; padding: .6rem .8rem; margin-bottom: 1rem; font-size: .9rem; color: #155724; }
  .links { margin-top: 1rem; font-size: .85rem; text-align: center; }
  .links a { color: #1a5490; }
</style>
</head>
<body>
<div class="box">
  <h1><?= h($title) ?></h1>
  <?= $bodyHtml ?>
</div>
</body>
</html><?php
}

$action = $_GET['action'] ?? 'login';

switch ($action) {
    case 'register':
        handleRegister($pdo);
        break;

    case 'login':
        handleLogin($pdo, $_SERVER['REMOTE_ADDR']);
        break;

    case 'logout':
        $_SESSION = [];
        session_destroy();
        header('Location: auth.php?action=login');
        exit;

    default:
        header('Location: auth.php?action=login');
        exit;
}

function handleRegister(PDO $pdo): void {
    if (current_user() !== null) {
        header('Location: car-classing.html');
        exit;
    }

    $error = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';

        if ($name === '' || $email === '' || $password === '') {
            $error = 'All fields are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } elseif (db_find_user_by_email($pdo, $email) !== null) {
            $error = 'An account with that email already exists.';
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
    }

    $body = '';
    if ($error) $body .= '<div class="error">' . h($error) . '</div>';
    $body .= '<form method="post" action="auth.php?action=register">';
    $body .= '<label for="name">Name</label><input type="text" id="name" name="name" required value="' . h($_POST['name'] ?? '') . '">';
    $body .= '<label for="email">Email</label><input type="email" id="email" name="email" required value="' . h($_POST['email'] ?? '') . '">';
    $body .= '<label for="password">Password</label><input type="password" id="password" name="password" required autocomplete="new-password">';
    $body .= '<label for="password_confirm">Confirm Password</label><input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password">';
    $body .= '<button type="submit" class="primary">Create Account</button>';
    $body .= '</form>';
    $body .= '<div class="links">Already have an account? <a href="auth.php?action=login">Sign in</a></div>';

    renderAuthPage('Create Account', $body);
}

function handleLogin(PDO $pdo, string $ip): void {
    if (current_user() !== null) {
        header('Location: car-classing.html');
        exit;
    }

    $error = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $lockout = db_is_locked_out($pdo, $ip);
        if ($lockout['locked']) {
            $error = "Too many failed attempts. Try again in {$lockout['remaining']} minute(s).";
        } else {
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $user = db_find_user_by_email($pdo, $email);

            if ($user && $user['password_hash'] && password_verify($password, $user['password_hash'])) {
                db_clear_login_attempts($pdo, $ip);
                login_user($user);
                header('Location: car-classing.html');
                exit;
            }

            db_record_failed_attempt($pdo, $ip);
            $lockout = db_is_locked_out($pdo, $ip);
            $error = $lockout['locked']
                ? "Too many failed attempts. Try again in {$lockout['remaining']} minute(s)."
                : 'Incorrect email or password.';
        }
    }

    $body = '';
    if ($error) $body .= '<div class="error">' . h($error) . '</div>';
    $body .= '<form method="post" action="auth.php?action=login">';
    $body .= '<label for="email">Email</label><input type="email" id="email" name="email" required autofocus value="' . h($_POST['email'] ?? '') . '">';
    $body .= '<label for="password">Password</label><input type="password" id="password" name="password" required autocomplete="current-password">';
    $body .= '<button type="submit" class="primary">Sign In</button>';
    $body .= '</form>';
    $body .= '<a href="auth.php?action=google-login" class="google-btn">Sign in with Google</a>';
    $body .= '<div class="links"><a href="auth.php?action=forgot-password">Forgot password?</a> &middot; <a href="auth.php?action=register">Create an account</a></div>';

    renderAuthPage('Sign In', $body);
}
```

- [ ] **Step 2: Manual verification**

Run a local PHP server from the project root and exercise the flow by hand (no automated test — this file is request/`header()`-driven per the spec's testing scope):

```bash
cd wcma-calculator
php -S localhost:8000
```

- Visit `http://localhost:8000/auth.php?action=register`, create an account with a password under 8 characters → confirm the length error shows.
- Register successfully → confirm redirect to `car-classing.html` and `$_SESSION['user_id']` is set (add a temporary `var_dump($_SESSION)` in `car-classing.html`'s PHP-served twin if needed, or check via `session-status.php` once Task 8 lands).
- Log out via `auth.php?action=logout`, log back in with the same credentials → confirm success.
- Attempt 6 failed logins in a row → confirm the lockout message appears.

- [ ] **Step 3: Commit**

```bash
git add wcma-calculator/auth.php
git commit -m "feat: add auth.php with email/password register, login, logout"
```

---

### Task 5: `auth.php` — Google OAuth login

**Files:**
- Modify: `wcma-calculator/auth.php`

**Interfaces:**
- Consumes: `db_find_user_by_google_id`, `db_find_user_by_email`, `db_link_google_id`, `db_create_user` (Task 1), `login_user` (Task 3)
- Produces: routes `?action=google-login`, `?action=google-callback` added to the existing switch statement

- [ ] **Step 1: Add Google config constants and the two routes**

Add near the top of `auth.php`, after `db_init($pdo);`:

```php
// Generate a client at https://console.cloud.google.com/apis/credentials
// (OAuth client ID → Web application). Add this file's callback URL as an
// "Authorized redirect URI", e.g. https://yourdomain.com/auth.php?action=google-callback
define('GOOGLE_CLIENT_ID',     'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'YOUR_GOOGLE_CLIENT_SECRET');
define('GOOGLE_REDIRECT_URI',  'https://yourdomain.com/auth.php?action=google-callback');
```

Add two cases to the `switch ($action)` block, before `default:`:

```php
    case 'google-login':
        handleGoogleLogin();
        break;

    case 'google-callback':
        handleGoogleCallback($pdo);
        break;
```

Add the handler functions at the end of the file:

```php
function handleGoogleLogin(): void {
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;

    $params = http_build_query([
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'prompt'        => 'select_account',
    ]);

    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    exit;
}

function googleCurlPost(string $url, array $fields): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?? [];
}

function googleCurlGet(string $url, string $bearerToken): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $bearerToken]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?? [];
}

function handleGoogleCallback(PDO $pdo): void {
    $state = $_GET['state'] ?? '';
    if (!isset($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $state)) {
        setFlash('Google sign-in failed (invalid state). Please try again.', 'error');
        header('Location: auth.php?action=login');
        exit;
    }
    unset($_SESSION['oauth_state']);

    $code = $_GET['code'] ?? '';
    if ($code === '') {
        setFlash('Google sign-in was cancelled.', 'error');
        header('Location: auth.php?action=login');
        exit;
    }

    $token = googleCurlPost('https://oauth2.googleapis.com/token', [
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]);

    if (!isset($token['access_token'])) {
        setFlash('Google sign-in failed while exchanging the code. Please try again.', 'error');
        header('Location: auth.php?action=login');
        exit;
    }

    $profile = googleCurlGet('https://www.googleapis.com/oauth2/v3/userinfo', $token['access_token']);
    $googleId = $profile['sub'] ?? null;
    $email = $profile['email'] ?? null;
    $name = $profile['name'] ?? $email;

    if (!$googleId || !$email) {
        setFlash('Google did not return the expected profile data.', 'error');
        header('Location: auth.php?action=login');
        exit;
    }

    $user = db_find_user_by_google_id($pdo, $googleId);

    if (!$user) {
        $existingByEmail = db_find_user_by_email($pdo, $email);
        if ($existingByEmail) {
            db_link_google_id($pdo, $existingByEmail['id'], $googleId);
            $user = db_find_user_by_id($pdo, $existingByEmail['id']);
        } else {
            $userId = db_create_user($pdo, [
                'email' => $email,
                'name' => $name,
                'password_hash' => null,
                'google_id' => $googleId,
            ]);
            $user = db_find_user_by_id($pdo, $userId);
        }
    }

    login_user($user);
    header('Location: car-classing.html');
    exit;
}
```

- [ ] **Step 2: Manual verification**

Requires a real Google Cloud OAuth client (created outside this plan, per the spec). With `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET`/`GOOGLE_REDIRECT_URI` filled in and the app reachable at that redirect URI:

- Click "Sign in with Google" from `auth.php?action=login` → complete Google's consent screen → confirm redirect back to `car-classing.html` logged in.
- Log out, sign in with Google again with the same account → confirm it logs in via `db_find_user_by_google_id` (no duplicate user row created).
- Register a password account with email `X`, then sign in with Google using an account with the same email `X` → confirm `db_link_google_id` links them (check `google_id` is now set on that user's row) rather than erroring.

- [ ] **Step 3: Commit**

```bash
git add wcma-calculator/auth.php
git commit -m "feat: add Google OAuth login to auth.php"
```

---

### Task 6: `auth.php` — forgot password / reset password

**Files:**
- Modify: `wcma-calculator/auth.php`

**Interfaces:**
- Consumes: `db_find_user_by_email`, `db_create_password_reset`, `db_get_password_reset`, `db_delete_password_reset`, `db_delete_password_resets_for_user` (Task 2), PHPMailer (`phpmailer/src/*`, already vendored), SMTP constants (mirror `car-classing.php`'s `$smtp_host` etc., but as local constants here matching `admin.php`'s `SMTP_HOST` style)
- Produces: routes `?action=forgot-password`, `?action=reset-password`

- [ ] **Step 1: Add SMTP requires/constants and the two routes**

Add near the top of `auth.php`, alongside the other requires:

```php
require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Match the SMTP credentials used in car-classing.php / admin.php
define('SMTP_HOST',  'smtp.ionos.com');
define('SMTP_PORT',  587);
define('SMTP_USER',  'noreply@yourdomain.com');
define('SMTP_PASS',  'YOUR_SMTP_PASSWORD');
define('FROM_EMAIL', 'noreply@yourdomain.com');
define('FROM_NAME',  'WCMA Calculator');
```

Add two cases to the `switch ($action)` block:

```php
    case 'forgot-password':
        handleForgotPassword($pdo);
        break;

    case 'reset-password':
        handleResetPassword($pdo);
        break;
```

Add the handler functions:

```php
function buildAuthMailer(): PHPMailer {
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

function handleForgotPassword(PDO $pdo): void {
    $sent = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = trim($_POST['email'] ?? '');
        $user = db_find_user_by_email($pdo, $email);

        if ($user && $user['password_hash']) {
            db_delete_password_resets_for_user($pdo, $user['id']);
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', time() + 3600);
            db_create_password_reset($pdo, $user['id'], $tokenHash, $expiresAt);

            $resetUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/auth.php?action=reset-password&token=' . $token;

            try {
                $mail = buildAuthMailer();
                $mail->addAddress($user['email'], $user['name']);
                $mail->Subject = 'Reset your WCMA Calculator password';
                $mail->isHTML(true);
                $mail->Body = '<p>Click the link below to reset your password. This link expires in 1 hour.</p><p><a href="' . htmlspecialchars($resetUrl) . '">' . htmlspecialchars($resetUrl) . '</a></p>';
                $mail->AltBody = "Reset your password: $resetUrl (expires in 1 hour)";
                $mail->send();
            } catch (Exception $e) {
                error_log('Password reset email error: ' . $e->getMessage());
            }
        }

        $sent = true; // Always show the same message, whether or not the email matched
    }

    $body = '';
    if ($sent) {
        $body .= '<div class="success">If that email is registered, a reset link has been sent.</div>';
    }
    $body .= '<form method="post" action="auth.php?action=forgot-password">';
    $body .= '<label for="email">Email</label><input type="email" id="email" name="email" required>';
    $body .= '<button type="submit" class="primary">Send Reset Link</button>';
    $body .= '</form>';
    $body .= '<div class="links"><a href="auth.php?action=login">Back to sign in</a></div>';

    renderAuthPage('Forgot Password', $body);
}

function handleResetPassword(PDO $pdo): void {
    $token = $_GET['token'] ?? $_POST['token'] ?? '';
    $tokenHash = hash('sha256', $token);
    $reset = $token !== '' ? db_get_password_reset($pdo, $tokenHash) : null;

    if (!$reset || strtotime($reset['expires_at']) < time()) {
        renderAuthPage('Reset Password', '<div class="error">This reset link is invalid or has expired.</div><div class="links"><a href="auth.php?action=forgot-password">Request a new link</a></div>');
        return;
    }

    $error = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';

        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id")
                ->execute([':hash' => password_hash($password, PASSWORD_BCRYPT), ':id' => $reset['user_id']]);
            db_delete_password_reset($pdo, $tokenHash);

            $user = db_find_user_by_id($pdo, $reset['user_id']);
            login_user($user);
            header('Location: car-classing.html');
            exit;
        }
    }

    $body = '';
    if ($error) $body .= '<div class="error">' . h($error) . '</div>';
    $body .= '<form method="post" action="auth.php?action=reset-password">';
    $body .= '<input type="hidden" name="token" value="' . h($token) . '">';
    $body .= '<label for="password">New Password</label><input type="password" id="password" name="password" required autocomplete="new-password">';
    $body .= '<label for="password_confirm">Confirm Password</label><input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password">';
    $body .= '<button type="submit" class="primary">Reset Password</button>';
    $body .= '</form>';

    renderAuthPage('Reset Password', $body);
}
```

- [ ] **Step 2: Manual verification**

```bash
cd wcma-calculator
php -S localhost:8000
```

- Submit `auth.php?action=forgot-password` with a registered email → confirm the email arrives (via the same IONOS SMTP already configured) with a working reset link, and that submitting a non-existent email shows the identical "check your email" message.
- Follow the link, set a new password → confirm login works with the new password and the old one no longer works.
- Manually expire a token (edit `expires_at` in the SQLite DB to the past) → confirm the "invalid or expired" message shows.

- [ ] **Step 3: Commit**

```bash
git add wcma-calculator/auth.php
git commit -m "feat: add forgot-password and reset-password flow to auth.php"
```

---

### Task 7: `session-status.php` + `car-classing.html` nav integration

**Files:**
- Create: `wcma-calculator/session-status.php`
- Modify: `wcma-calculator/car-classing.html:13-21`

**Interfaces:**
- Consumes: `session_bootstrap.php`'s `current_user()` (Task 3)
- Produces: `GET session-status.php` → `{"loggedIn": true, "name": "...", "role": "user"}` or `{"loggedIn": false}`

- [ ] **Step 1: Create `session-status.php`**

```php
<?php
require __DIR__ . '/session_bootstrap.php';

header('Content-Type: application/json');

$user = current_user();
if ($user === null) {
    echo json_encode(['loggedIn' => false]);
} else {
    echo json_encode([
        'loggedIn' => true,
        'name'     => $user['name'],
        'role'     => $user['role'],
    ]);
}
```

- [ ] **Step 2: Add a nav placeholder and JS to `car-classing.html`**

In `wcma-calculator/car-classing.html`, modify the header block (currently lines 13-21):

```html
        <header>
            <div class="header-content">
                <img src="https://www.wcma.ca/wp-content/uploads/WCMA-Logo.png" alt="WCMA Logo" class="wcma-logo">
                <div class="header-text">
                    <h1>WCMA Classing Calculator - 2026</h1>
                    <p class="version">Version 1.0</p>
                </div>
            </div>
            <nav id="account-nav" class="account-nav"></nav>
        </header>
```

Add before the closing `</body>` tag:

```html
    <script>
      fetch('session-status.php')
        .then(function (res) { return res.json(); })
        .then(function (data) {
          var nav = document.getElementById('account-nav');
          if (!nav) return;
          if (data.loggedIn) {
            nav.innerHTML =
              '<a href="account.php">My Submissions (' + data.name + ')</a> · ' +
              '<a href="auth.php?action=logout">Logout</a>';
          } else {
            nav.innerHTML =
              '<a href="auth.php?action=login">Sign In</a> · ' +
              '<a href="auth.php?action=register">Register</a>';
          }
        })
        .catch(function () { /* nav stays empty if the endpoint is unreachable */ });
    </script>
</body>
```

- [ ] **Step 3: Manual verification**

```bash
cd wcma-calculator
php -S localhost:8000
```

Open `http://localhost:8000/car-classing.html` in a browser:
- Logged out → nav shows "Sign In · Register".
- Log in via `auth.php?action=login`, revisit the calculator page → nav shows "My Submissions ({name}) · Logout".

- [ ] **Step 4: Commit**

```bash
git add wcma-calculator/session-status.php wcma-calculator/car-classing.html
git commit -m "feat: add session-status endpoint and account nav to calculator page"
```

---

### Task 8: Tag submissions with `user_id` in `car-classing.php`

**Files:**
- Modify: `wcma-calculator/car-classing.php:1-25` (requires), `wcma-calculator/car-classing.php:186-214` (insert call)

**Interfaces:**
- Consumes: `session_bootstrap.php`'s `current_user()` (Task 3), modified `db_insert_submission()` (Task 2)

- [ ] **Step 1: Require the session bootstrap and read the current user**

Near the top of `wcma-calculator/car-classing.php`, after `require __DIR__ . '/db.php';`, add:

```php
require __DIR__ . '/session_bootstrap.php';

$current_user = current_user();
```

- [ ] **Step 2: Pass `user_id` into the insert call**

In the `db_insert_submission($pdo, [...])` call (car-classing.php:186-214), add one line inside the array, alongside the other `:` keys:

```php
    ':calculated_class'       => $calculated_class ?: null,
    ':user_id'                => $current_user['id'] ?? null,
]);
```

- [ ] **Step 3: Manual verification**

```bash
cd wcma-calculator
php -S localhost:8000
```

- While logged out, submit the calculator form → confirm it saves with `user_id = NULL` (check via `sqlite3 data/submissions.db "SELECT id, user_id FROM submissions ORDER BY id DESC LIMIT 1;"`).
- Log in, submit again → confirm the new row has `user_id` set to your logged-in user's id.

- [ ] **Step 4: Commit**

```bash
git add wcma-calculator/car-classing.php
git commit -m "feat: tag submissions with the logged-in user's id when present"
```

---

### Task 9: `account.php` — My Submissions list + over-limit banner

**Files:**
- Create: `wcma-calculator/account.php`

**Interfaces:**
- Consumes: `session_bootstrap.php` (`current_user()`), `db.php` (`db_get_user_submissions`, `db_count_user_submissions`)
- Produces: route `?action=list` (default) — later tasks (10) add `view`, `delete`, `resend` to the same switch statement.

- [ ] **Step 1: Create `account.php` with auth guard, CSRF/flash helpers, and the list view**

```php
<?php
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';

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

$user = requireLogin();
$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'list':
    default:
        handleAccountList($pdo, $user);
}

function handleAccountList(PDO $pdo, array $user): void {
    $submissions = db_get_user_submissions($pdo, $user['id']);
    $count = db_count_user_submissions($pdo, $user['id']);
    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderAccountListPage($submissions, $count, $csrf, $flash);
}

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

- [ ] **Step 2: Manual verification**

```bash
cd wcma-calculator
php -S localhost:8000
```

- Log in, submit the calculator 21 times → confirm the over-limit banner appears on `account.php` and no submission was blocked.
- Log in as a user with 0 submissions → confirm the "No submissions yet." empty state.

- [ ] **Step 3: Commit**

```bash
git add wcma-calculator/account.php
git commit -m "feat: add My Submissions list page with over-limit banner"
```

---

### Task 10: `account.php` — view, resend, delete (ownership-scoped)

**Files:**
- Modify: `wcma-calculator/account.php`

**Interfaces:**
- Consumes: `db_get_user_submission` (Task 2, ownership-scoped), `db_delete_submission` (existing), `db_update_email_sent` (existing), PHPMailer via the same `buildAuthMailer()`-style function

- [ ] **Step 1: Add `view`, `resend`, `delete` routes and their handlers**

Update the switch statement in `account.php`:

```php
switch ($action) {
    case 'view':
        handleAccountView($pdo, $user, (int)($_GET['id'] ?? 0));
        break;

    case 'resend':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: account.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleAccountResend($pdo, $user, (int)($_POST['id'] ?? 0));
        break;

    case 'delete':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: account.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleAccountDelete($pdo, $user, (int)($_POST['id'] ?? 0));
        break;

    case 'list':
    default:
        handleAccountList($pdo, $user);
}
```

Also update the list view's Actions cell (from Task 9) to add a Delete button, replacing:

```php
        <td class="actions">
          <a href="account.php?action=view&id=<?= (int)$s['id'] ?>">View</a>
        </td>
```

with:

```php
        <td class="actions">
          <a href="account.php?action=view&id=<?= (int)$s['id'] ?>">View</a>
          <form method="post" action="account.php?action=delete" style="display:inline"
                onsubmit="return confirm('Permanently delete this submission and its files?')">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button type="submit" style="background:none;border:none;color:#c00;cursor:pointer;font-size:.85rem;padding:0;margin-left:.6rem">Delete</button>
          </form>
        </td>
```

Add the handler functions and required PHPMailer bits at the top of `account.php` (after `require __DIR__ . '/db.php';`):

```php
require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

define('SMTP_HOST',  'smtp.ionos.com');
define('SMTP_PORT',  587);
define('SMTP_USER',  'noreply@yourdomain.com');
define('SMTP_PASS',  'YOUR_SMTP_PASSWORD');
define('FROM_EMAIL', 'noreply@yourdomain.com');
define('FROM_NAME',  'WCMA Calculator');
```

```php
function buildAccountMailer(): PHPMailer {
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

function handleAccountView(PDO $pdo, array $user, int $id): void {
    $sub = db_get_user_submission($pdo, $user['id'], $id);
    if (!$sub) {
        setFlash('Submission not found.', 'error');
        header('Location: account.php');
        exit;
    }
    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderAccountViewPage($sub, $csrf, $flash);
}

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
      <tr><td>Vehicle</td><td><?= h(trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])) ?></td></tr>
      <tr><td>Weight</td><td><?= h((string)$s['competition_weight']) ?> lbs</td></tr>
      <tr><td>Declared HP</td><td><?= h((string)$s['declared_hp']) ?></td></tr>
      <tr><td>Calculated Class</td><td><strong><?= h($s['calculated_class'] ?? '—') ?></strong></td></tr>
      <tr><td>Submitted</td><td><?= h(date('F j, Y \a\t g:i A', strtotime($s['submitted_at']))) ?></td></tr>
    </table>
  </div>
  <div class="card">
    <h2>Actions</h2>
    <form method="post" action="account.php?action=resend">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
      <button type="submit" class="btn btn-primary">Resend Confirmation to My Email</button>
    </form>
    <h2 style="margin-top:1.5rem">Uploaded Files</h2>
    <?php
    $files = [
        'car_image'  => ['label' => 'Car Image',  'path' => $s['car_image_path']],
        'dyno_chart' => ['label' => 'Dyno Chart', 'path' => $s['dyno_chart_path']],
        'dyno_table' => ['label' => 'Dyno Table', 'path' => $s['dyno_table_path']],
    ];
    $any = false;
    foreach ($files as $field => $f):
        if (!$f['path']) continue;
        $any = true;
        $ext = strtolower(pathinfo($f['path'], PATHINFO_EXTENSION));
        $is_image = in_array($ext, ['jpg', 'jpeg', 'png']);
        $url = h('account.php?action=file&id=' . (int)$s['id'] . '&field=' . $field);
    ?>
    <p style="font-weight:bold;margin:.8rem 0 .2rem"><?= h($f['label']) ?></p>
    <?php if ($is_image): ?>
      <img src="<?= $url ?>" class="file-thumb" alt="<?= h($f['label']) ?>">
    <?php else: ?>
      <a href="<?= $url ?>" target="_blank"><?= h(basename($f['path'])) ?></a>
    <?php endif; ?>
    <?php endforeach; ?>
    <?php if (!$any): ?><p style="color:#888">No files uploaded.</p><?php endif; ?>
  </div>
</main>
</body>
</html><?php
}

function handleAccountResend(PDO $pdo, array $user, int $id): void {
    $sub = db_get_user_submission($pdo, $user['id'], $id);
    if (!$sub) {
        setFlash('Submission not found.', 'error');
        header('Location: account.php');
        exit;
    }

    $attachments = [];
    foreach (['dyno_chart_path', 'dyno_table_path', 'car_image_path'] as $col) {
        if ($sub[$col]) {
            $path = __DIR__ . '/' . $sub[$col];
            if (file_exists($path)) $attachments[] = ['path' => $path, 'name' => basename($path)];
        }
    }

    $sent = false;
    try {
        $mail = buildAccountMailer();
        $mail->addAddress($sub['email'], $sub['name']);
        $mail->Subject = 'Your WCMA Classing Calculator Submission';
        $mail->isHTML(true);
        $mail->Body = '<p>Class: <strong>' . htmlspecialchars($sub['calculated_class'] ?? '') . '</strong></p><p>Vehicle: ' . htmlspecialchars(trim($sub['year'] . ' ' . $sub['make'] . ' ' . $sub['model'])) . '</p>';
        $mail->AltBody = 'Class: ' . ($sub['calculated_class'] ?? '') . "\nVehicle: " . trim($sub['year'] . ' ' . $sub['make'] . ' ' . $sub['model']);
        foreach ($attachments as $att) $mail->addAttachment($att['path'], $att['name']);
        $mail->send();
        $sent = true;
    } catch (Exception $e) {
        error_log('Account resend error: ' . $e->getMessage());
    }

    db_update_email_sent($pdo, $id, $sent ? 1 : 0);
    setFlash($sent ? 'Confirmation re-sent to your email.' : 'Failed to send email. Please try again later.', $sent ? 'success' : 'error');
    header('Location: account.php?action=view&id=' . $id);
    exit;
}

function handleAccountDelete(PDO $pdo, array $user, int $id): void {
    $sub = db_get_user_submission($pdo, $user['id'], $id);
    if (!$sub) {
        setFlash('Submission not found.', 'error');
        header('Location: account.php');
        exit;
    }

    $upload_dir = __DIR__ . '/uploads/' . $id;
    if (is_dir($upload_dir)) {
        foreach (glob($upload_dir . '/*') as $file) unlink($file);
        rmdir($upload_dir);
    }

    db_delete_submission($pdo, $id);
    setFlash('Submission deleted.', 'success');
    header('Location: account.php');
    exit;
}
```

Add a `file` route for serving the account owner's own files (mirrors `admin.php`'s `handleFile`, ownership-scoped):

```php
    case 'file':
        handleAccountFile($pdo, $user, (int)($_GET['id'] ?? 0), $_GET['field'] ?? '');
        break;
```

```php
function handleAccountFile(PDO $pdo, array $user, int $id, string $field): void {
    $field_map = ['dyno_chart' => 'dyno_chart_path', 'dyno_table' => 'dyno_table_path', 'car_image' => 'car_image_path'];
    if (!isset($field_map[$field])) { http_response_code(404); exit; }

    $sub = db_get_user_submission($pdo, $user['id'], $id);
    $db_field = $field_map[$field];
    if (!$sub || !$sub[$db_field]) { http_response_code(404); exit; }

    $path = __DIR__ . '/' . $sub[$db_field];
    if (!file_exists($path)) { http_response_code(404); exit; }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'txt' => 'text/plain'];

    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
```

- [ ] **Step 2: Manual verification**

```bash
cd wcma-calculator
php -S localhost:8000
```

- As user A, submit and view it in "My Submissions" → confirm the detail page renders, files display, and "Resend Confirmation" emails only the submitter.
- As user A, delete it → confirm the row and `uploads/{id}/` directory are gone.
- As user B, try `account.php?action=view&id=<user A's submission id>` directly → confirm "Submission not found" (ownership check blocks it), not user A's data.

- [ ] **Step 3: Commit**

```bash
git add wcma-calculator/account.php
git commit -m "feat: add view, resend, and delete actions to My Submissions"
```

---

### Task 11: `admin.php` — replace PIN auth with shared session + role check

**Files:**
- Modify: `wcma-calculator/admin.php:1-176` (config, requireAuth, router, login handler/page)

**Interfaces:**
- Consumes: `session_bootstrap.php` (`current_user()`, `is_admin()`)

- [ ] **Step 1: Remove PIN config and session_start, require the shared bootstrap**

Replace lines 1-15 of `admin.php`:

```php
<?php
session_start();
date_default_timezone_set('America/Denver');

require __DIR__ . '/db.php';
require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ── Configuration ─────────────────────────────────────────────────────────────
// Generate with: php -r "echo password_hash('YOUR_PIN', PASSWORD_BCRYPT) . PHP_EOL;"
define('ADMIN_PIN_HASH', '$2y$10$PLACEHOLDER_REPLACE_ON_SERVER');
```

with:

```php
<?php
require __DIR__ . '/session_bootstrap.php';
date_default_timezone_set('America/Denver');

require __DIR__ . '/db.php';
require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ── Configuration ─────────────────────────────────────────────────────────────
```

- [ ] **Step 2: Replace `requireAuth()`**

Replace (admin.php, in the "Auth helpers" section):

```php
function requireAuth(): void {
    if (!isset($_SESSION['admin_authenticated'])) {
        header('Location: admin.php?action=login');
        exit;
    }
}
```

with:

```php
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

- [ ] **Step 3: Replace the router's `login`/`logout` cases and delete `handleLogin`/`renderLoginPage`**

Replace:

```php
    case 'login':
        handleLogin($pdo, $ip);
        break;

    case 'logout':
        $_SESSION = [];
        session_destroy();
        header('Location: admin.php?action=login');
        exit;
```

with:

```php
    case 'login':
        header('Location: auth.php?action=login');
        exit;

    case 'logout':
        header('Location: auth.php?action=logout');
        exit;
```

Delete the entire `handleLogin(PDO $pdo, string $ip): void { ... }` and `renderLoginPage(string $error = ''): void { ... }` functions (the current lines 111-176) — they're no longer called.

Update the "Logout" link in `renderListPage()` from `admin.php?action=logout` to `auth.php?action=logout`.

- [ ] **Step 4: Manual verification**

```bash
cd wcma-calculator
php -S localhost:8000
```

- Visit `admin.php` while logged out → confirm redirect to `auth.php?action=login`.
- Log in as `matt.sinfield@gmail.com` (the bootstrap admin) → visit `admin.php` → confirm the submissions list renders.
- Log in as a non-admin user → visit `admin.php` → confirm redirect to `car-classing.html` with the "not authorized" flash.

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/admin.php
git commit -m "feat: replace admin PIN auth with shared session and role check"
```

---

### Task 12: `admin.php` — Manage Users screen

**Files:**
- Modify: `wcma-calculator/admin.php` (router + new handler/render functions)

**Interfaces:**
- Consumes: `db_get_all_users`, `db_set_user_role`, `db_count_admins` (Task 1)

- [ ] **Step 1: Add the `users` route and handlers**

Add to the router's switch statement:

```php
    case 'users':
        requireAuth();
        handleUsersList($pdo);
        break;

    case 'promote':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=users'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleSetRole($pdo, (int)($_POST['id'] ?? 0), 'admin');
        break;

    case 'demote':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=users'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleSetRole($pdo, (int)($_POST['id'] ?? 0), 'user');
        break;
```

Add the handler and render functions:

```php
function handleUsersList(PDO $pdo): void {
    $users = db_get_all_users($pdo);
    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderUsersPage($users, $csrf, $flash);
}

function handleSetRole(PDO $pdo, int $id, string $role): void {
    if ($role === 'user' && db_count_admins($pdo) <= 1) {
        $target = db_find_user_by_id($pdo, $id);
        if ($target && $target['role'] === 'admin') {
            setFlash('Cannot demote the last remaining admin.', 'error');
            header('Location: admin.php?action=users');
            exit;
        }
    }

    db_set_user_role($pdo, $id, $role);
    setFlash('User role updated.', 'success');
    header('Location: admin.php?action=users');
    exit;
}

function renderUsersPage(array $users, string $csrf, ?array $flash): void {
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
    <thead><tr><th>Email</th><th>Name</th><th>Role</th><th>Login Method</th><th>Created</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= h($u['email']) ?></td>
        <td><?= h($u['name']) ?></td>
        <td class="<?= $u['role'] === 'admin' ? 'role-admin' : '' ?>"><?= h($u['role']) ?></td>
        <td><?= h(trim(($u['password_hash'] ? 'Password ' : '') . ($u['google_id'] ? 'Google' : ''))) ?></td>
        <td><?= h(date('M j, Y', strtotime($u['created_at']))) ?></td>
        <td>
          <?php if ($u['role'] === 'admin'): ?>
          <form method="post" action="admin.php?action=demote" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button type="submit" class="btn-role">Demote</button>
          </form>
          <?php else: ?>
          <form method="post" action="admin.php?action=promote" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button type="submit" class="btn-role">Promote</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</main>
</body>
</html><?php
}
```

Add a "Manage Users" link in `renderListPage()`'s header, next to the Logout link:

```php
<header>
  <h1>WCMA Submissions</h1>
  <div><a href="admin.php?action=users" style="margin-right:1rem">Manage Users</a><a href="auth.php?action=logout">Logout</a></div>
</header>
```

- [ ] **Step 2: Manual verification**

```bash
cd wcma-calculator
php -S localhost:8000
```

- As the bootstrap admin, visit `admin.php?action=users` → confirm the list shows all registered users with correct login-method labels.
- Promote a regular user → confirm they can now access `admin.php`.
- With only one admin, attempt to demote them → confirm the "Cannot demote the last remaining admin" error and the role is unchanged.
- Promote a second admin, then demote the first → confirm it succeeds now that two admins exist.

- [ ] **Step 3: Commit**

```bash
git add wcma-calculator/admin.php
git commit -m "feat: add Manage Users screen with promote/demote to admin panel"
```

---

## Post-Implementation Checklist

- [ ] Run the full test suite once more: `cd wcma-calculator && php phpunit.phar`
- [ ] Manually run through every scenario in the spec's Testing section end-to-end on a single browser session (register → submit → view in My Submissions → admin promote/demote → PIN-free admin access)
- [ ] Fill in real values for `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` (auth.php) and SMTP constants (auth.php, account.php) on the deployed server — never commit real secrets
- [ ] Add `data/.htaccess` / confirm it still blocks direct access now that `users`/`password_resets` live in the same SQLite file (no change needed, but worth confirming the existing `.htaccess` from the prior feature still applies)
