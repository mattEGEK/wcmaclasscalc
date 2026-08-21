# Admin Submission Store Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist every submitted tech sheet (form data + uploaded files) in a server-side SQLite database and expose a PIN-protected admin page where a tech inspector can browse, sort, view, re-email, and delete submissions.

**Architecture:** A new `db.php` include centralises all SQLite operations and is required by both the modified `car-classing.php` (which now persists submissions instead of discarding files) and a new `admin.php` (which provides the admin UI). Files are stored under `uploads/{submission_id}/` on disk; the admin page serves them through PHP so the directory can be blocked from direct HTTP access.

**Tech Stack:** PHP 7.4+ (PDO/SQLite), PHPMailer (already deployed), vanilla HTML/CSS for admin UI, ES6 modules (existing JS stack).

## Global Constraints

- No composer, no npm, no build step — plain PHP files and vanilla JS only
- All DB queries via PDO prepared statements — no string interpolation of user data into SQL
- All admin HTML output via `htmlspecialchars()` — no raw user data in HTML
- CSRF token on every mutating POST action (delete, re-email)
- Uploaded files served through PHP only — `uploads/` blocked from direct HTTP
- SQLite DB stored in `data/submissions.db` — `data/` blocked from direct HTTP
- Individual modifier values tracked in `results` object in `calculator.js` and posted as hidden fields
- File paths stored in DB as relative strings (`uploads/{id}/{filename}`) resolved with `__DIR__ . '/'`
- SMTP config in `admin.php` mirrors the constants in `car-classing.php` — keep them in sync manually
- PIN stored as bcrypt hash — never plain text
- Rate limit: 5 failed login attempts per IP within 15 minutes triggers 15-minute lockout
- All files in `wcma-calculator/` subdirectory

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `wcma-calculator/db.php` | **Create** | All SQLite operations — connect, init, CRUD, rate limiting |
| `wcma-calculator/data/.htaccess` | **Create** | Block direct HTTP access to SQLite DB |
| `wcma-calculator/uploads/.htaccess` | **Create** | Block direct HTTP access to uploads directory |
| `wcma-calculator/admin.php` | **Create** | PIN-protected admin UI — login, list, detail, file serve, resend, delete |
| `wcma-calculator/js/calculator.js` | **Modify** | Add individual modifier values to `results` return object |
| `wcma-calculator/js/ui-controller.js` | **Modify** | Post individual modifier values as hidden fields on form submit |
| `wcma-calculator/car-classing.php` | **Modify** | Require db.php, persist submission to SQLite, store files, remove cleanup loop |

---

## Task 1: Database layer (`db.php`) + directory protection

**Files:**
- Create: `wcma-calculator/db.php`
- Create: `wcma-calculator/data/.htaccess`
- Create: `wcma-calculator/uploads/.htaccess`
- Create (temp, delete after): `wcma-calculator/test_db.php`

**Interfaces:**
- Produces: `db_connect()`, `db_init()`, `db_insert_submission()`, `db_update_submission_files()`, `db_update_email_sent()`, `db_get_submissions()`, `db_get_submission()`, `db_delete_submission()`, `db_is_locked_out()`, `db_record_failed_attempt()`, `db_clear_login_attempts()` — all consumed by Tasks 3 and 4+

- [ ] **Step 1: Create `wcma-calculator/data/.htaccess`**

```apache
Deny from all
```

- [ ] **Step 2: Create `wcma-calculator/uploads/.htaccess`**

```apache
Deny from all
```

- [ ] **Step 3: Create `wcma-calculator/db.php`**

```php
<?php
/**
 * Database layer — SQLite via PDO.
 * Define DB_PATH before requiring this file to override (e.g. in tests).
 */
if (!defined('DB_PATH')) {
    define('DB_PATH', __DIR__ . '/data/submissions.db');
}

function db_connect(): PDO {
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    return $pdo;
}

function db_init(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS submissions (
            id                      INTEGER PRIMARY KEY AUTOINCREMENT,
            submitted_at            DATETIME NOT NULL,
            name                    TEXT NOT NULL,
            email                   TEXT NOT NULL,
            year                    TEXT NOT NULL,
            make                    TEXT NOT NULL,
            model                   TEXT NOT NULL,
            comments                TEXT,
            competition_weight      INTEGER NOT NULL,
            declared_hp             INTEGER NOT NULL,
            dyno_hp                 INTEGER,
            chassis_display         TEXT,
            body_mods_display       TEXT,
            transmission_display    TEXT,
            drivetrain_display      TEXT,
            tires_display           TEXT,
            brake_suspension        TEXT,
            chassis_value           REAL DEFAULT 0,
            body_mods_value         REAL DEFAULT 0,
            transmission_value      REAL DEFAULT 0,
            drivetrain_value        REAL DEFAULT 0,
            tires_value             REAL DEFAULT 0,
            brake_suspension_value  REAL DEFAULT 0,
            weight_factor           REAL DEFAULT 0,
            modification_factor     REAL DEFAULT 0,
            base_ratio              REAL DEFAULT 0,
            modified_ratio          REAL DEFAULT 0,
            calculated_class        TEXT,
            dyno_chart_path         TEXT,
            dyno_table_path         TEXT,
            car_image_path          TEXT,
            email_sent              INTEGER DEFAULT 0
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            ip              TEXT PRIMARY KEY,
            attempts        INTEGER NOT NULL DEFAULT 0,
            last_attempt_at DATETIME NOT NULL
        )
    ");
}

function db_insert_submission(PDO $pdo, array $data): int {
    $stmt = $pdo->prepare("
        INSERT INTO submissions (
            submitted_at, name, email, year, make, model, comments,
            competition_weight, declared_hp, dyno_hp,
            chassis_display, body_mods_display, transmission_display,
            drivetrain_display, tires_display, brake_suspension,
            chassis_value, body_mods_value, transmission_value,
            drivetrain_value, tires_value, brake_suspension_value,
            weight_factor, modification_factor, base_ratio, modified_ratio,
            calculated_class, email_sent
        ) VALUES (
            :submitted_at, :name, :email, :year, :make, :model, :comments,
            :competition_weight, :declared_hp, :dyno_hp,
            :chassis_display, :body_mods_display, :transmission_display,
            :drivetrain_display, :tires_display, :brake_suspension,
            :chassis_value, :body_mods_value, :transmission_value,
            :drivetrain_value, :tires_value, :brake_suspension_value,
            :weight_factor, :modification_factor, :base_ratio, :modified_ratio,
            :calculated_class, 0
        )
    ");
    $stmt->execute($data);
    return (int)$pdo->lastInsertId();
}

function db_update_submission_files(PDO $pdo, int $id, ?string $dyno_chart, ?string $dyno_table, ?string $car_image): void {
    $pdo->prepare("
        UPDATE submissions
        SET dyno_chart_path = :dyno_chart, dyno_table_path = :dyno_table, car_image_path = :car_image
        WHERE id = :id
    ")->execute([':dyno_chart' => $dyno_chart, ':dyno_table' => $dyno_table, ':car_image' => $car_image, ':id' => $id]);
}

function db_update_email_sent(PDO $pdo, int $id, int $sent): void {
    $pdo->prepare("UPDATE submissions SET email_sent = :sent WHERE id = :id")
        ->execute([':sent' => $sent, ':id' => $id]);
}

function db_get_submissions(PDO $pdo, string $sort = 'submitted_at', string $dir = 'desc'): array {
    $allowed_sorts = ['submitted_at', 'name', 'year', 'make', 'model',
                      'competition_weight', 'declared_hp', 'calculated_class', 'email_sent'];
    $allowed_dirs  = ['asc', 'desc'];
    $sort = in_array($sort, $allowed_sorts, true) ? $sort : 'submitted_at';
    $dir  = in_array($dir,  $allowed_dirs,  true) ? $dir  : 'desc';
    return $pdo->query("SELECT * FROM submissions ORDER BY {$sort} {$dir}")->fetchAll();
}

function db_get_submission(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM submissions WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function db_delete_submission(PDO $pdo, int $id): void {
    $pdo->prepare("DELETE FROM submissions WHERE id = :id")->execute([':id' => $id]);
}

// ── Rate limiting ─────────────────────────────────────────────────────────────

define('RL_MAX_ATTEMPTS', 5);
define('RL_WINDOW_SECONDS', 15 * 60); // 15 minutes

function db_is_locked_out(PDO $pdo, string $ip): array {
    $stmt = $pdo->prepare("SELECT * FROM login_attempts WHERE ip = :ip");
    $stmt->execute([':ip' => $ip]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['locked' => false, 'remaining' => 0];
    }

    $elapsed = time() - strtotime($row['last_attempt_at']);
    if ($row['attempts'] >= RL_MAX_ATTEMPTS && $elapsed < RL_WINDOW_SECONDS) {
        return ['locked' => true, 'remaining' => (int)ceil((RL_WINDOW_SECONDS - $elapsed) / 60)];
    }

    return ['locked' => false, 'remaining' => 0];
}

function db_record_failed_attempt(PDO $pdo, string $ip): void {
    $stmt = $pdo->prepare("SELECT * FROM login_attempts WHERE ip = :ip");
    $stmt->execute([':ip' => $ip]);
    $row = $stmt->fetch();
    $now = date('Y-m-d H:i:s');

    if (!$row) {
        $pdo->prepare("INSERT INTO login_attempts (ip, attempts, last_attempt_at) VALUES (:ip, 1, :now)")
            ->execute([':ip' => $ip, ':now' => $now]);
    } else {
        $elapsed      = time() - strtotime($row['last_attempt_at']);
        $new_attempts = ($elapsed >= RL_WINDOW_SECONDS) ? 1 : $row['attempts'] + 1;
        $pdo->prepare("UPDATE login_attempts SET attempts = :attempts, last_attempt_at = :now WHERE ip = :ip")
            ->execute([':attempts' => $new_attempts, ':now' => $now, ':ip' => $ip]);
    }
}

function db_clear_login_attempts(PDO $pdo, string $ip): void {
    $pdo->prepare("DELETE FROM login_attempts WHERE ip = :ip")->execute([':ip' => $ip]);
}
```

- [ ] **Step 4: Create `wcma-calculator/test_db.php`**

```php
<?php
// Temporary test script — delete after Task 1 is verified.
// Run with: php wcma-calculator/test_db.php

define('DB_PATH', __DIR__ . '/data/test_submissions.db');
require __DIR__ . '/db.php';

$pdo = db_connect();
db_init($pdo);

// ── submissions ───────────────────────────────────────────────────────────────
$id = db_insert_submission($pdo, [
    ':submitted_at'           => date('Y-m-d H:i:s'),
    ':name'                   => 'Test User',
    ':email'                  => 'test@example.com',
    ':year'                   => '2020',
    ':make'                   => 'Honda',
    ':model'                  => 'Civic',
    ':comments'               => null,
    ':competition_weight'     => 2850,
    ':declared_hp'            => 210,
    ':dyno_hp'                => null,
    ':chassis_display'        => 'Stock',
    ':body_mods_display'      => 'None',
    ':transmission_display'   => 'Standard',
    ':drivetrain_display'     => 'FWD',
    ':tires_display'          => 'DOT',
    ':brake_suspension'       => '[]',
    ':chassis_value'          => 0.0,
    ':body_mods_value'        => 0.0,
    ':transmission_value'     => 0.0,
    ':drivetrain_value'       => 0.0,
    ':tires_value'            => 0.0,
    ':brake_suspension_value' => 0.0,
    ':weight_factor'          => 0.0,
    ':modification_factor'    => 0.0,
    ':base_ratio'             => 13.57,
    ':modified_ratio'         => 13.57,
    ':calculated_class'       => 'GT4',
]);
assert($id > 0, "FAIL: insert returned $id");
echo "PASS: insert returned ID $id\n";

$sub = db_get_submission($pdo, $id);
assert($sub !== null && $sub['name'] === 'Test User', "FAIL: get submission");
echo "PASS: get submission\n";

db_update_submission_files($pdo, $id, 'uploads/1/dyno.pdf', null, 'uploads/1/car.jpg');
$sub = db_get_submission($pdo, $id);
assert($sub['dyno_chart_path'] === 'uploads/1/dyno.pdf', "FAIL: file paths");
echo "PASS: update files\n";

db_update_email_sent($pdo, $id, 1);
$sub = db_get_submission($pdo, $id);
assert((int)$sub['email_sent'] === 1, "FAIL: email_sent");
echo "PASS: update email_sent\n";

$list = db_get_submissions($pdo);
assert(count($list) >= 1, "FAIL: list empty");
echo "PASS: get submissions list (" . count($list) . " rows)\n";

db_delete_submission($pdo, $id);
assert(db_get_submission($pdo, $id) === null, "FAIL: delete");
echo "PASS: delete\n";

// ── rate limiting ─────────────────────────────────────────────────────────────
$ip = '127.0.0.1';
db_clear_login_attempts($pdo, $ip);
assert(!db_is_locked_out($pdo, $ip)['locked'], "FAIL: should not be locked initially");
echo "PASS: not locked initially\n";

for ($i = 0; $i < 5; $i++) {
    db_record_failed_attempt($pdo, $ip);
}
$lockout = db_is_locked_out($pdo, $ip);
assert($lockout['locked'] === true, "FAIL: should be locked after 5 attempts");
assert($lockout['remaining'] > 0, "FAIL: remaining should be > 0");
echo "PASS: locked after 5 attempts, {$lockout['remaining']} min remaining\n";

db_clear_login_attempts($pdo, $ip);
assert(!db_is_locked_out($pdo, $ip)['locked'], "FAIL: should be unlocked after clear");
echo "PASS: unlocked after clear\n";

// cleanup
unlink(__DIR__ . '/data/test_submissions.db');
echo "\nAll tests passed. test_submissions.db removed.\n";
```

- [ ] **Step 5: Run the test script**

```bash
php wcma-calculator/test_db.php
```

Expected output:
```
PASS: insert returned ID 1
PASS: get submission
PASS: update files
PASS: update email_sent
PASS: get submissions list (1 rows)
PASS: delete
PASS: not locked initially
PASS: locked after 5 attempts, 15 min remaining
PASS: unlocked after clear

All tests passed. test_submissions.db removed.
```

- [ ] **Step 6: Delete `test_db.php`**

```bash
rm wcma-calculator/test_db.php
```

- [ ] **Step 7: Commit**

```bash
git add wcma-calculator/db.php wcma-calculator/data/.htaccess wcma-calculator/uploads/.htaccess
git commit -m "feat: add SQLite database layer and directory protection"
```

---

## Task 2: Expose individual modifier values from calculator + post as hidden fields

**Files:**
- Modify: `wcma-calculator/js/calculator.js` — add individual modifier values to results object
- Modify: `wcma-calculator/js/ui-controller.js` — post individual modifier values as hidden fields on submit

**Interfaces:**
- Consumes: `updateCalculations(formData)` from `calculator.js` — currently returns `{ weightFactor, baseRatio, modificationFactor, modifiedRatio, calculatedClass }`
- Produces: extended results object adding `chassisValue`, `bodyModsValue`, `transmissionValue`, `drivetrainValue`, `tiresValue`, `brakeSuspensionValue` — consumed by Task 3 (PHP reads them from POST)

- [ ] **Step 1: Extend the results object in `calculator.js`**

In `updateCalculations()`, find the `results` declaration (around line 197) and add the six new fields:

```javascript
const results = {
    weightFactor: 0,
    baseRatio: 0,
    modificationFactor: 0,
    modifiedRatio: 0,
    calculatedClass: '',
    chassisValue: 0,
    bodyModsValue: 0,
    transmissionValue: 0,
    drivetrainValue: 0,
    tiresValue: 0,
    brakeSuspensionValue: 0,
};
```

- [ ] **Step 2: Capture individual values during modifier lookup in `calculator.js`**

Replace the modifier lookup block (lines ~225–278) with the version below, which captures each value into a local variable before summing:

```javascript
let modifierSum = 0;
let chassisVal = 0, bodyVal = 0, transVal = 0, dtVal = 0, tireVal = 0, brakeVal = 0;

if (chassis && classForModifiers) {
    const v = getModifierValue(chassisModifierTable, chassis, classForModifiers);
    if (v !== null && !isNaN(v)) { modifierSum += v; chassisVal = v; }
}

if (bodyMods && classForModifiers) {
    const v = getModifierValue(bodyModifierTable, bodyMods, classForModifiers);
    if (v !== null && !isNaN(v)) { modifierSum += v; bodyVal = v; }
}

if (transmission && classForModifiers) {
    const v = getModifierValue(transModifierTable, transmission, classForModifiers);
    if (v !== null && !isNaN(v)) { modifierSum += v; transVal = v; }
}

if (drivetrain && classForModifiers) {
    const v = getModifierValue(dtModifierTable, drivetrain, classForModifiers);
    if (v !== null && !isNaN(v)) { modifierSum += v; dtVal = v; }
}

if (tires && classForModifiers) {
    const v = getModifierValue(tireModifierTable, tires, classForModifiers);
    if (v !== null && !isNaN(v)) { modifierSum += v; tireVal = v; }
}

if (brakeSuspension && Array.isArray(brakeSuspension) && brakeSuspension.length > 0 && classForModifiers) {
    brakeSuspension.forEach(optionId => {
        const v = getModifierValue(brakeModifierTable, optionId, classForModifiers);
        if (v !== null && !isNaN(v)) { modifierSum += v; brakeVal += v; }
    });
} else if (brakeSuspension && typeof brakeSuspension === 'string' && brakeSuspension && classForModifiers) {
    const v = getModifierValue(brakeModifierTable, brakeSuspension, classForModifiers);
    if (v !== null && !isNaN(v)) { modifierSum += v; brakeVal = v; }
}

results.modificationFactor = modifierSum;
results.chassisValue         = chassisVal;
results.bodyModsValue        = bodyVal;
results.transmissionValue    = transVal;
results.drivetrainValue      = dtVal;
results.tiresValue           = tireVal;
results.brakeSuspensionValue = brakeVal;
```

- [ ] **Step 3: Post individual modifier values as hidden fields in `ui-controller.js`**

In the form `submit` handler (around line 1268), after the existing `fieldsToAdd` block that builds hidden fields, add:

```javascript
// Individual modifier values for DB persistence
const modifierValueFields = {
    'chassis_value':          results.chassisValue.toFixed(2),
    'body_mods_value':        results.bodyModsValue.toFixed(2),
    'transmission_value':     results.transmissionValue.toFixed(2),
    'drivetrain_value':       results.drivetrainValue.toFixed(2),
    'tires_value':            results.tiresValue.toFixed(2),
    'brake_suspension_value': results.brakeSuspensionValue.toFixed(2),
};

Object.keys(modifierValueFields).forEach(name => {
    const existing = form.querySelector(`input[name="${name}"]`);
    if (existing) existing.remove();
});

Object.keys(modifierValueFields).forEach(name => {
    const hiddenField = document.createElement('input');
    hiddenField.type = 'hidden';
    hiddenField.name = name;
    hiddenField.value = modifierValueFields[name];
    form.appendChild(hiddenField);
});
```

- [ ] **Step 4: Verify in browser**

Open `car-classing.html` via a local server (e.g. `npx serve wcma-calculator`). Enter weight and HP, select at least one modifier. Open DevTools → Network tab. Submit the form. In the request payload, confirm these fields appear with numeric values:

```
chassis_value: 0.00
body_mods_value: 0.50
transmission_value: 0.00
drivetrain_value: 0.00
tires_value: 0.00
brake_suspension_value: 0.00
```

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/js/calculator.js wcma-calculator/js/ui-controller.js
git commit -m "feat: expose individual modifier values in results and post as hidden fields"
```

---

## Task 3: Persist submissions in `car-classing.php`

**Files:**
- Modify: `wcma-calculator/car-classing.php`

**Interfaces:**
- Consumes: all functions from `db.php` (Task 1); individual modifier POST fields (Task 2)
- Produces: each submission persisted to SQLite with files in `uploads/{id}/`

- [ ] **Step 1: Add `db.php` require and collect individual modifier values**

After the existing PHPMailer requires (around line 19), add:

```php
require __DIR__ . '/db.php';
```

After the existing `$weight_factor` line (around line 69), add:

```php
$chassis_value          = isset($_POST['chassis_value'])          ? (float)$_POST['chassis_value']          : 0.0;
$body_mods_value        = isset($_POST['body_mods_value'])        ? (float)$_POST['body_mods_value']        : 0.0;
$transmission_value     = isset($_POST['transmission_value'])     ? (float)$_POST['transmission_value']     : 0.0;
$drivetrain_value       = isset($_POST['drivetrain_value'])       ? (float)$_POST['drivetrain_value']       : 0.0;
$tires_value            = isset($_POST['tires_value'])            ? (float)$_POST['tires_value']            : 0.0;
$brake_suspension_value = isset($_POST['brake_suspension_value']) ? (float)$_POST['brake_suspension_value'] : 0.0;
```

- [ ] **Step 2: Change file handling to use a temp destination, then move to `uploads/{id}/` after DB insert**

Find the file loop (around line 121) where files are moved using `uniqid()`. Replace the destination line:

Old:
```php
$destination = $upload_dir . uniqid() . '-' . $file_name;
```

New (use a per-upload temp name — the final move to `uploads/{id}/` happens after DB insert):
```php
$temp_name   = uniqid() . '-' . $file_name;
$destination = $upload_dir . $temp_name;
```

Also store the original `$file_name` in the attachments entry so we know what to rename it to:

```php
$attachments[] = [
    'path'      => $destination,
    'name'      => $file_name,
    'input'     => $input_name,  // 'dyno_chart', 'dyno_table', or 'car_image'
];
```

- [ ] **Step 3: Add DB persistence block after the validation error check**

After the `if (!empty($errors))` block (after line ~170), add:

```php
// ── Persist to database ───────────────────────────────────────────────────────
$pdo = db_connect();
db_init($pdo);

$submission_id = db_insert_submission($pdo, [
    ':submitted_at'           => date('Y-m-d H:i:s'),
    ':name'                   => $name,
    ':email'                  => $email,
    ':year'                   => $year,
    ':make'                   => $make,
    ':model'                  => $model,
    ':comments'               => $comments ?: null,
    ':competition_weight'     => (int)$competition_weight,
    ':declared_hp'            => (int)$declared_hp,
    ':dyno_hp'                => $dyno_hp !== '' ? (int)$dyno_hp : null,
    ':chassis_display'        => $chassis_display ?: null,
    ':body_mods_display'      => $body_mods_display ?: null,
    ':transmission_display'   => $transmission_display ?: null,
    ':drivetrain_display'     => $drivetrain_display ?: null,
    ':tires_display'          => $tires_display ?: null,
    ':brake_suspension'       => json_encode($brake_suspension),
    ':chassis_value'          => $chassis_value,
    ':body_mods_value'        => $body_mods_value,
    ':transmission_value'     => $transmission_value,
    ':drivetrain_value'       => $drivetrain_value,
    ':tires_value'            => $tires_value,
    ':brake_suspension_value' => $brake_suspension_value,
    ':weight_factor'          => (float)$weight_factor,
    ':modification_factor'    => (float)$modification_factor,
    ':base_ratio'             => (float)$base_ratio,
    ':modified_ratio'         => (float)$modified_ratio,
    ':calculated_class'       => $calculated_class ?: null,
]);

// Move uploaded files to uploads/{submission_id}/
$sub_upload_dir = $upload_dir . $submission_id . '/';
if (!is_dir($sub_upload_dir)) {
    mkdir($sub_upload_dir, 0755, true);
}

$file_paths = ['dyno_chart' => null, 'dyno_table' => null, 'car_image' => null];

foreach ($attachments as &$att) {
    $new_path = $sub_upload_dir . $att['name'];
    if (rename($att['path'], $new_path)) {
        $att['path'] = $new_path;
        $file_paths[$att['input']] = 'uploads/' . $submission_id . '/' . $att['name'];
    } else {
        error_log("Failed to move file to: $new_path");
    }
}
unset($att);

db_update_submission_files($pdo, $submission_id, $file_paths['dyno_chart'], $file_paths['dyno_table'], $file_paths['car_image']);
```

- [ ] **Step 4: Update the `email_sent` flag after sending**

Replace the `$mail_sent = true;` and `$mail_sent = false;` assignments in the try/catch with DB updates:

```php
    $mail_sent = true;
    db_update_email_sent($pdo, $submission_id, 1);

} catch (Exception $e) {
    $last_error = $e->getMessage();
    error_log('PHPMailer error: ' . $last_error);
    $mail_sent = false;
    db_update_email_sent($pdo, $submission_id, 0);
}
```

- [ ] **Step 5: Remove the file cleanup loop**

Delete these lines (currently around lines 317–322):

```php
// Clean up uploaded files
foreach ($attachments as $attachment) {
    if (file_exists($attachment['path'])) {
        unlink($attachment['path']);
    }
}
```

Files now persist under `uploads/{submission_id}/`.

- [ ] **Step 6: Test end-to-end submission**

Deploy to 221racing.com (or run locally with `npx serve wcma-calculator` + a local PHP server). Submit the form with at least one file upload. Verify:

1. A new row appears in `data/submissions.db` (use a SQLite browser or run `php -r "require 'wcma-calculator/db.php'; $p=db_connect(); db_init($p); print_r(db_get_submissions($p));"`)
2. Files exist under `uploads/{id}/`
3. Confirmation email arrives in both inboxes
4. `email_sent` column is `1`

- [ ] **Step 7: Commit**

```bash
git add wcma-calculator/car-classing.php
git commit -m "feat: persist submissions to SQLite and retain uploaded files"
```

---

## Task 4: Admin authentication (`admin.php` scaffolding + login)

**Files:**
- Create: `wcma-calculator/admin.php`

**Interfaces:**
- Consumes: `db_connect()`, `db_init()`, `db_is_locked_out()`, `db_record_failed_attempt()`, `db_clear_login_attempts()` from `db.php`
- Produces: `requireAuth()`, `generateCsrfToken()`, `validateCsrfToken()`, `setFlash()`, `getFlash()` — consumed by Tasks 5, 6, 7

- [ ] **Step 1: Generate a bcrypt hash for your chosen PIN**

Run this once locally and copy the output:

```bash
php -r "echo password_hash('YOUR_CHOSEN_PIN', PASSWORD_BCRYPT) . PHP_EOL;"
```

- [ ] **Step 2: Create `wcma-calculator/admin.php`**

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
define('ADMIN_PIN_HASH', 'REPLACE_WITH_BCRYPT_HASH');  // output of password_hash()
define('SMTP_HOST',      'smtp.ionos.com');
define('SMTP_PORT',      587);
define('SMTP_USER',      'noreply@yourdomain.com');     // ← match car-classing.php
define('SMTP_PASS',      'YOUR_SMTP_PASSWORD');          // ← match car-classing.php
define('FROM_EMAIL',     'noreply@yourdomain.com');      // ← match car-classing.php
define('FROM_NAME',      'WCMA Calculator');
define('TECH_EMAIL',     'matt.sinfield@gmail.com');
define('TECH_NAME',      'Matt Sinfield');

$pdo = db_connect();
db_init($pdo);

// ── Auth helpers ──────────────────────────────────────────────────────────────
function requireAuth(): void {
    if (!isset($_SESSION['admin_authenticated'])) {
        header('Location: admin.php?action=login');
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

// ── Router ────────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? 'list';
$ip     = $_SERVER['REMOTE_ADDR'];

switch ($action) {
    case 'login':
        handleLogin($pdo, $ip);
        break;

    case 'logout':
        session_destroy();
        header('Location: admin.php?action=login');
        exit;

    case 'list':
        requireAuth();
        handleList($pdo);
        break;

    case 'view':
        requireAuth();
        handleView($pdo, (int)($_GET['id'] ?? 0));
        break;

    case 'file':
        requireAuth();
        handleFile($pdo, (int)($_GET['id'] ?? 0), $_GET['field'] ?? '');
        break;

    case 'resend':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleResend($pdo, (int)($_POST['id'] ?? 0));
        break;

    case 'delete':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleDelete($pdo, (int)($_POST['id'] ?? 0));
        break;

    default:
        requireAuth();
        handleList($pdo);
}

// ── Login ─────────────────────────────────────────────────────────────────────
function handleLogin(PDO $pdo, string $ip): void {
    if (isset($_SESSION['admin_authenticated'])) {
        header('Location: admin.php');
        exit;
    }

    $error = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $lockout = db_is_locked_out($pdo, $ip);
        if ($lockout['locked']) {
            $error = "Too many failed attempts. Try again in {$lockout['remaining']} minute(s).";
        } else {
            $pin = $_POST['pin'] ?? '';
            if (password_verify($pin, ADMIN_PIN_HASH)) {
                db_clear_login_attempts($pdo, $ip);
                $_SESSION['admin_authenticated'] = true;
                header('Location: admin.php');
                exit;
            } else {
                db_record_failed_attempt($pdo, $ip);
                $lockout = db_is_locked_out($pdo, $ip);
                $error = $lockout['locked']
                    ? "Too many failed attempts. Try again in {$lockout['remaining']} minute(s)."
                    : 'Incorrect PIN.';
            }
        }
    }

    renderLoginPage($error);
}

function renderLoginPage(string $error = ''): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Login — WCMA Calculator</title>
<style>
  body { font-family: Arial, sans-serif; background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
  .login-box { background: #fff; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,.15); padding: 2rem; width: 100%; max-width: 340px; }
  h1 { margin: 0 0 1.5rem; font-size: 1.3rem; color: #1a5490; text-align: center; }
  label { display: block; font-size: .9rem; font-weight: bold; margin-bottom: .3rem; }
  input[type=password] { width: 100%; padding: .6rem .8rem; border: 1px solid #ccc; border-radius: 4px; font-size: 1rem; box-sizing: border-box; }
  button { margin-top: 1rem; width: 100%; padding: .7rem; background: #1a5490; color: #fff; border: none; border-radius: 4px; font-size: 1rem; cursor: pointer; }
  button:hover { background: #154070; }
  .error { background: #fde; border: 1px solid #e88; border-radius: 4px; padding: .6rem .8rem; margin-bottom: 1rem; font-size: .9rem; color: #900; }
</style>
</head>
<body>
<div class="login-box">
  <h1>WCMA Admin</h1>
  <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
  <form method="post">
    <label for="pin">PIN</label>
    <input type="password" id="pin" name="pin" autofocus autocomplete="current-password">
    <button type="submit">Sign in</button>
  </form>
</div>
</body>
</html><?php
}

// ── Placeholder stubs (replaced in Tasks 5–7) ─────────────────────────────────
function handleList(PDO $pdo): void {
    echo '<p style="font-family:sans-serif;padding:2rem;">List view coming in Task 5.</p>';
    echo '<p style="font-family:sans-serif;padding:0 2rem;"><a href="admin.php?action=logout">Logout</a></p>';
}
function handleView(PDO $pdo, int $id): void { echo 'View coming in Task 6.'; }
function handleFile(PDO $pdo, int $id, string $field): void { http_response_code(404); echo 'Not yet implemented.'; }
function handleResend(PDO $pdo, int $id): void { header('Location: admin.php'); }
function handleDelete(PDO $pdo, int $id): void { header('Location: admin.php'); }
```

- [ ] **Step 3: Verify login flow in browser**

Navigate to `https://221racing.com/admin.php` (or local server equivalent):
1. Confirm redirect to `admin.php?action=login`
2. Enter wrong PIN 5 times — confirm lockout message with minutes remaining
3. Wait (or clear `login_attempts` row in DB) — confirm lockout lifts
4. Enter correct PIN — confirm redirect to stub list page saying "List view coming in Task 5"
5. Navigate to `admin.php?action=logout` — confirm redirect back to login

- [ ] **Step 4: Commit**

```bash
git add wcma-calculator/admin.php
git commit -m "feat: add admin.php scaffolding with PIN login and rate limiting"
```

---

## Task 5: Admin list view + delete

**Files:**
- Modify: `wcma-calculator/admin.php` — replace `handleList()` and `handleDelete()` stubs

**Interfaces:**
- Consumes: `db_get_submissions()`, `db_delete_submission()`, `generateCsrfToken()`, `setFlash()`, `getFlash()`, `h()`
- Produces: sortable submissions table; working delete action

- [ ] **Step 1: Replace `handleList()` stub**

```php
function handleList(PDO $pdo): void {
    $sort = $_GET['sort'] ?? 'submitted_at';
    $dir  = $_GET['dir']  ?? 'desc';
    $submissions = db_get_submissions($pdo, $sort, $dir);
    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderListPage($submissions, $sort, $dir, $csrf, $flash);
}

function renderListPage(array $submissions, string $sort, string $dir, string $csrf, ?array $flash): void {
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
  <a href="admin.php?action=logout">Logout</a>
</header>
<main>
  <?php if ($flash): ?>
  <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>
  <table>
    <thead>
      <tr>
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
      <tr><td colspan="8" class="empty">No submissions yet.</td></tr>
    <?php else: foreach ($submissions as $s): ?>
      <tr>
        <td><?= h(date('M j, Y H:i', strtotime($s['submitted_at']))) ?></td>
        <td><?= h($s['name']) ?></td>
        <td><?= h(trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])) ?></td>
        <td><?= h((string)$s['competition_weight']) ?></td>
        <td><?= h((string)$s['declared_hp']) ?></td>
        <td><strong><?= h($s['calculated_class'] ?? '—') ?></strong></td>
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
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</main>
</body>
</html><?php
}
```

- [ ] **Step 2: Replace `handleDelete()` stub**

```php
function handleDelete(PDO $pdo, int $id): void {
    $sub = db_get_submission($pdo, $id);
    if (!$sub) {
        setFlash('Submission not found.', 'error');
        header('Location: admin.php');
        exit;
    }

    // Delete uploaded files
    $upload_dir = __DIR__ . '/uploads/' . $id;
    if (is_dir($upload_dir)) {
        foreach (glob($upload_dir . '/*') as $file) {
            unlink($file);
        }
        rmdir($upload_dir);
    }

    db_delete_submission($pdo, $id);
    setFlash('Submission deleted.', 'success');
    header('Location: admin.php');
    exit;
}
```

- [ ] **Step 3: Verify in browser**

1. Submit a test form to create at least one submission
2. Navigate to `admin.php` — confirm table renders with correct columns
3. Click a column header — confirm sort direction changes and URL updates
4. Click Delete on a row — confirm JS confirm dialog appears; confirm deletes row and files

- [ ] **Step 4: Commit**

```bash
git add wcma-calculator/admin.php
git commit -m "feat: add admin list view with sortable columns and delete action"
```

---

## Task 6: Admin detail view + file serving

**Files:**
- Modify: `wcma-calculator/admin.php` — replace `handleView()` and `handleFile()` stubs

**Interfaces:**
- Consumes: `db_get_submission()`, `generateCsrfToken()`, `getFlash()`, `h()`
- Produces: detail view with calculation breakdown; file serving endpoint

- [ ] **Step 1: Replace `handleView()` stub**

```php
function handleView(PDO $pdo, int $id): void {
    $sub = db_get_submission($pdo, $id);
    if (!$sub) {
        setFlash('Submission not found.', 'error');
        header('Location: admin.php');
        exit;
    }
    $csrf  = generateCsrfToken();
    $flash = getFlash();
    renderDetailPage($sub, $csrf, $flash);
}

function renderDetailPage(array $s, string $csrf, ?array $flash): void {
    $brake_list = [];
    $brake_raw = json_decode($s['brake_suspension'] ?? '[]', true);
    if (is_array($brake_raw)) $brake_list = $brake_raw;

    function modRow(string $label, ?string $display, float $value): string {
        if (!$display && $value == 0) return '';
        $sign = $value >= 0 ? '+' : '';
        $disp = $display ? h($display) : '—';
        return "<tr><td>{$label}</td><td style='text-align:right;font-family:monospace'>{$sign}" . number_format($value, 2) . "</td><td style='color:#666;font-size:.85rem'>{$disp}</td></tr>";
    }
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
        <tr><td>Base Ratio</td>
            <td style="text-align:right"><?= number_format((float)$s['base_ratio'], 2) ?></td><td></td></tr>
        <tr><td>Weight Factor</td>
            <td style="text-align:right"><?= ($s['weight_factor'] >= 0 ? '+' : '') . number_format((float)$s['weight_factor'], 2) ?></td><td></td></tr>
        <?= modRow('Chassis', $s['chassis_display'], (float)$s['chassis_value']) ?>
        <?= modRow('Body Mods', $s['body_mods_display'], (float)$s['body_mods_value']) ?>
        <?= modRow('Transmission', $s['transmission_display'], (float)$s['transmission_value']) ?>
        <?= modRow('Drivetrain', $s['drivetrain_display'], (float)$s['drivetrain_value']) ?>
        <?= modRow('Tires', $s['tires_display'], (float)$s['tires_value']) ?>
        <?php if ((float)$s['brake_suspension_value'] != 0): ?>
        <tr><td>Brake &amp; Susp.</td>
            <td style="text-align:right;font-family:monospace"><?= ($s['brake_suspension_value'] >= 0 ? '+' : '') . number_format((float)$s['brake_suspension_value'], 2) ?></td>
            <td style="color:#666;font-size:.85rem"><?= h(implode(', ', $brake_list)) ?></td></tr>
        <?php endif; ?>
        <tr class="total">
          <td>Modified Ratio</td>
          <td style="text-align:right"><?= number_format((float)$s['modified_ratio'], 2) ?></td>
          <td class="class-badge"><?= h($s['calculated_class'] ?? '—') ?></td>
        </tr>
      </table>
    </div>

    <div class="card">
      <h2>Contact &amp; Vehicle</h2>
      <table class="data">
        <tr><td>Name</td><td><?= h($s['name']) ?></td></tr>
        <tr><td>Email</td><td><?= h($s['email']) ?></td></tr>
        <tr><td>Vehicle</td><td><?= h(trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])) ?></td></tr>
        <?php if ($s['comments']): ?><tr><td>Comments</td><td><?= nl2br(h($s['comments'])) ?></td></tr><?php endif; ?>
        <tr><td>Weight</td><td><?= h((string)$s['competition_weight']) ?> lbs</td></tr>
        <tr><td>Declared HP</td><td><?= h((string)$s['declared_hp']) ?></td></tr>
        <?php if ($s['dyno_hp']): ?><tr><td>Dyno HP</td><td><?= h((string)$s['dyno_hp']) ?></td></tr><?php endif; ?>
        <tr><td>Submitted</td><td><?= h(date('F j, Y \a\t g:i A', strtotime($s['submitted_at']))) ?></td></tr>
        <tr><td>Email Sent</td><td><?= $s['email_sent'] ? '✓ Yes' : '⚠ Failed' ?></td></tr>
      </table>
    </div>
  </div>

  <!-- RIGHT: Files + actions -->
  <div>
    <div class="card" style="margin-bottom:1.5rem">
      <h2>Actions</h2>
      <div class="actions">
        <form method="post" action="admin.php?action=resend" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <button type="submit" class="btn btn-primary">Re-email Tech Sheet</button>
        </form>
      </div>
    </div>

    <div class="card">
      <h2>Uploaded Files</h2>
      <?php
      $files = [
          'car_image'   => ['label' => 'Car Image',   'field' => 'car_image',   'path' => $s['car_image_path']],
          'dyno_chart'  => ['label' => 'Dyno Chart',  'field' => 'dyno_chart',  'path' => $s['dyno_chart_path']],
          'dyno_table'  => ['label' => 'Dyno Table',  'field' => 'dyno_table',  'path' => $s['dyno_table_path']],
      ];
      $any = false;
      foreach ($files as $f):
          if (!$f['path']) continue;
          $any = true;
          $ext = strtolower(pathinfo($f['path'], PATHINFO_EXTENSION));
          $is_image = in_array($ext, ['jpg', 'jpeg', 'png']);
          $url = h('admin.php?action=file&id=' . (int)$s['id'] . '&field=' . $f['field']);
      ?>
      <p style="font-weight:bold;margin:.8rem 0 .2rem"><?= h($f['label']) ?></p>
      <?php if ($is_image): ?>
        <img src="<?= $url ?>" class="file-thumb" alt="<?= h($f['label']) ?>">
      <?php else: ?>
        <a href="<?= $url ?>" target="_blank" class="file-link">Open <?= h(basename($f['path'])) ?></a>
      <?php endif; ?>
      <?php endforeach; ?>
      <?php if (!$any): ?><p style="color:#888;font-size:.9rem">No files uploaded.</p><?php endif; ?>
    </div>
  </div>
</main>
</body>
</html><?php
}
```

- [ ] **Step 2: Replace `handleFile()` stub**

```php
function handleFile(PDO $pdo, int $id, string $field): void {
    $field_map = [
        'dyno_chart' => 'dyno_chart_path',
        'dyno_table' => 'dyno_table_path',
        'car_image'  => 'car_image_path',
    ];

    if (!isset($field_map[$field])) { http_response_code(404); exit; }

    $sub = db_get_submission($pdo, $id);
    $db_field = $field_map[$field];

    if (!$sub || !$sub[$db_field]) { http_response_code(404); exit; }

    $path = __DIR__ . '/' . $sub[$db_field];
    if (!file_exists($path)) { http_response_code(404); exit; }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $types = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt'  => 'text/plain',
    ];

    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
```

- [ ] **Step 3: Verify in browser**

1. Navigate to a submission's detail view
2. Confirm the calculation breakdown table shows all modifier rows and the final class
3. If a car image was uploaded, confirm it renders inline
4. Click a dyno chart/table link — confirm it opens correctly in a new tab
5. Confirm direct URL to `uploads/{id}/filename` returns a 403 (blocked by .htaccess)

- [ ] **Step 4: Commit**

```bash
git add wcma-calculator/admin.php
git commit -m "feat: add submission detail view and file serving endpoint"
```

---

## Task 7: Admin re-email

**Files:**
- Modify: `wcma-calculator/admin.php` — replace `handleResend()` stub

**Interfaces:**
- Consumes: `db_get_submission()`, `db_update_email_sent()`, `setFlash()`, PHPMailer, SMTP constants
- Produces: re-sends original tech sheet email to both admin and submitter; updates `email_sent` flag

- [ ] **Step 1: Replace `handleResend()` stub**

```php
function handleResend(PDO $pdo, int $id): void {
    $sub = db_get_submission($pdo, $id);
    if (!$sub) {
        setFlash('Submission not found.', 'error');
        header('Location: admin.php');
        exit;
    }

    $brake_list = json_decode($sub['brake_suspension'] ?? '[]', true) ?? [];
    $body_html  = buildResendEmailHtml($sub, $brake_list);
    $body_text  = buildResendEmailText($sub, $brake_list);
    $subject    = 'WCMA Classing Calculator Submission — ' . $sub['name'] . ' — ' . date('M j, Y', strtotime($sub['submitted_at']));

    // Collect file attachments that still exist on disk
    $attachments = [];
    foreach (['dyno_chart_path' => 'dyno_chart', 'dyno_table_path' => 'dyno_table', 'car_image_path' => 'car_image'] as $col => $label) {
        if ($sub[$col]) {
            $path = __DIR__ . '/' . $sub[$col];
            if (file_exists($path)) {
                $attachments[] = ['path' => $path, 'name' => basename($path)];
            }
        }
    }

    $sent = false;
    try {
        // Email to admin
        $mail = buildMailer();
        $mail->addAddress(TECH_EMAIL, TECH_NAME);
        $mail->addReplyTo($sub['email'], $sub['name']);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $body_html;
        $mail->AltBody = $body_text;
        foreach ($attachments as $att) { $mail->addAttachment($att['path'], $att['name']); }
        $mail->send();

        // Confirmation to submitter
        $mail2 = buildMailer();
        $mail2->addAddress($sub['email'], $sub['name']);
        $mail2->Subject = 'Your WCMA Classing Calculator Submission';
        $mail2->isHTML(true);
        $mail2->Body    = $body_html;
        $mail2->AltBody = $body_text;
        foreach ($attachments as $att) { $mail2->addAttachment($att['path'], $att['name']); }
        $mail2->send();

        $sent = true;
    } catch (Exception $e) {
        error_log('Admin resend PHPMailer error: ' . $e->getMessage());
    }

    db_update_email_sent($pdo, $id, $sent ? 1 : 0);
    setFlash($sent ? 'Email re-sent successfully.' : 'Failed to re-send email. Check server logs.', $sent ? 'success' : 'error');
    header('Location: admin.php?action=view&id=' . $id);
    exit;
}

function buildMailer(): PHPMailer {
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

function buildResendEmailHtml(array $s, array $brake_list): string {
    $b = '<html><body style="font-family:Arial,sans-serif;line-height:1.6;color:#333">';
    $b .= '<h2 style="color:#1a5490">WCMA Classing Calculator Submission</h2>';
    $b .= '<p><strong>Originally submitted:</strong> ' . htmlspecialchars(date('F j, Y \a\t g:i A', strtotime($s['submitted_at']))) . '</p>';
    $b .= '<h3 style="color:#1a5490;border-bottom:2px solid #1a5490;padding-bottom:5px">Contact Information</h3>';
    $b .= '<table cellpadding="5"><tr><td width="200"><strong>Name:</strong></td><td>' . htmlspecialchars($s['name']) . '</td></tr>';
    $b .= '<tr><td><strong>Email:</strong></td><td>' . htmlspecialchars($s['email']) . '</td></tr>';
    $b .= '<tr><td><strong>Vehicle:</strong></td><td>' . htmlspecialchars(trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])) . '</td></tr>';
    if ($s['comments']) $b .= '<tr><td><strong>Comments:</strong></td><td>' . nl2br(htmlspecialchars($s['comments'])) . '</td></tr>';
    $b .= '</table>';
    $b .= '<h3 style="color:#1a5490;border-bottom:2px solid #1a5490;padding-bottom:5px">Vehicle Factors</h3>';
    $b .= '<table cellpadding="5">';
    $b .= '<tr><td width="200"><strong>Competition Weight:</strong></td><td>' . htmlspecialchars((string)$s['competition_weight']) . ' lbs</td></tr>';
    $b .= '<tr><td><strong>Declared HP:</strong></td><td>' . htmlspecialchars((string)$s['declared_hp']) . '</td></tr>';
    if ($s['dyno_hp'])             $b .= '<tr><td><strong>Dyno HP:</strong></td><td>' . htmlspecialchars((string)$s['dyno_hp']) . '</td></tr>';
    if ($s['chassis_display'])     $b .= '<tr><td><strong>Chassis:</strong></td><td>' . htmlspecialchars($s['chassis_display']) . '</td></tr>';
    if ($s['body_mods_display'])   $b .= '<tr><td><strong>Body Mods:</strong></td><td>' . htmlspecialchars($s['body_mods_display']) . '</td></tr>';
    if ($s['transmission_display'])$b .= '<tr><td><strong>Transmission:</strong></td><td>' . htmlspecialchars($s['transmission_display']) . '</td></tr>';
    if ($s['drivetrain_display'])  $b .= '<tr><td><strong>Drivetrain:</strong></td><td>' . htmlspecialchars($s['drivetrain_display']) . '</td></tr>';
    if ($s['tires_display'])       $b .= '<tr><td><strong>Tires:</strong></td><td>' . htmlspecialchars($s['tires_display']) . '</td></tr>';
    if ($brake_list)               $b .= '<tr><td><strong>Brake &amp; Susp:</strong></td><td>' . htmlspecialchars(implode(', ', $brake_list)) . '</td></tr>';
    $b .= '</table>';
    $b .= '<h3 style="color:#1a5490;border-bottom:2px solid #1a5490;padding-bottom:5px">Calculation Results</h3>';
    $b .= '<table cellpadding="5" style="background:#f9f9f9;border:1px solid #ddd">';
    $b .= '<tr><td width="200"><strong>Weight Factor:</strong></td><td>' . htmlspecialchars(number_format((float)$s['weight_factor'], 2)) . '</td></tr>';
    $b .= '<tr><td><strong>Base Ratio:</strong></td><td>' . htmlspecialchars(number_format((float)$s['base_ratio'], 2)) . '</td></tr>';
    $b .= '<tr><td><strong>Additional Mod Factors:</strong></td><td>' . htmlspecialchars(number_format((float)$s['modification_factor'], 2)) . '</td></tr>';
    $b .= '<tr><td><strong>Modified Ratio:</strong></td><td>' . htmlspecialchars(number_format((float)$s['modified_ratio'], 2)) . '</td></tr>';
    $b .= '<tr style="font-size:1.2em"><td><strong>Calculated Class:</strong></td><td style="font-weight:bold;color:#1a5490">' . htmlspecialchars($s['calculated_class'] ?? '') . '</td></tr>';
    $b .= '</table></body></html>';
    return $b;
}

function buildResendEmailText(array $s, array $brake_list): string {
    $t  = "WCMA Classing Calculator Submission\n";
    $t .= "Originally submitted: " . date('F j, Y \a\t g:i A', strtotime($s['submitted_at'])) . "\n\n";
    $t .= "CONTACT\nName: {$s['name']}\nEmail: {$s['email']}\n";
    $t .= "Vehicle: " . trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model']) . "\n";
    if ($s['comments']) $t .= "Comments: {$s['comments']}\n";
    $t .= "\nVEHICLE FACTORS\nWeight: {$s['competition_weight']} lbs\nDeclared HP: {$s['declared_hp']}\n";
    if ($s['dyno_hp'])              $t .= "Dyno HP: {$s['dyno_hp']}\n";
    if ($s['chassis_display'])      $t .= "Chassis: {$s['chassis_display']}\n";
    if ($s['body_mods_display'])    $t .= "Body Mods: {$s['body_mods_display']}\n";
    if ($s['transmission_display']) $t .= "Transmission: {$s['transmission_display']}\n";
    if ($s['drivetrain_display'])   $t .= "Drivetrain: {$s['drivetrain_display']}\n";
    if ($s['tires_display'])        $t .= "Tires: {$s['tires_display']}\n";
    if ($brake_list)                $t .= "Brake & Susp: " . implode(', ', $brake_list) . "\n";
    $t .= "\nCALCULATION RESULTS\n";
    $t .= "Weight Factor: " . number_format((float)$s['weight_factor'], 2) . "\n";
    $t .= "Base Ratio: " . number_format((float)$s['base_ratio'], 2) . "\n";
    $t .= "Mod Factors: " . number_format((float)$s['modification_factor'], 2) . "\n";
    $t .= "Modified Ratio: " . number_format((float)$s['modified_ratio'], 2) . "\n";
    $t .= "Calculated Class: " . ($s['calculated_class'] ?? '') . "\n";
    return $t;
}
```

- [ ] **Step 2: Verify re-email in browser**

1. Open a submission's detail view
2. Click **Re-email Tech Sheet**
3. Confirm a success flash message appears on redirect back to the detail view
4. Confirm both the admin inbox and the submitter's inbox received the email with attachments
5. Confirm `email_sent` shows ✓ in the list view

- [ ] **Step 3: Commit**

```bash
git add wcma-calculator/admin.php
git commit -m "feat: add re-email action to admin detail view"
```
