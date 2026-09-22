# Tech Sheets Foundation & Competitor Flow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an admin create race events and a competitor generate, submit (with captured signatures), view, print, edit, and resend a Race Tech Sheet (standard or endurance) from an already-classed submission, with a confirmation email sent to the competitor and `classing@wcma.ca`.

**Architecture:** New `events`, `tech_sheets`, `tech_sheet_drivers` SQLite tables alongside the existing `submissions`/`users`/`drafts` tables in `data/submissions.db`. A shared PHP definitions file (`tech-sheet-data.php`) is the single source of truth for the ~43-item vehicle checklist and 9-item driver equipment list, consumed by both server-side validation and a shared HTML renderer (`tech-sheet-render.php`) that produces the same markup for the online view, the print view, and the confirmation email. A new `tech-sheets.php` action-router page (mirroring the existing `account.php`/`admin.php` procedural router pattern) handles the competitor-facing new/submit/view/edit/resend flow. Two small vanilla-JS modules — a canvas signature pad and an accordion checklist widget — are added under `js/`, following the existing no-build-step ES6 pattern. Admin gets a new "Events" management screen inside the existing `admin.php` router (full tech-sheet review/admin list is a separate follow-up plan).

**Tech Stack:** PHP 8 (procedural, PDO/SQLite), vanilla ES6 JS (no bundler), PHPUnit (DB-layer tests only, matching existing test coverage — this codebase has no controller/integration test layer, so page-flow verification is manual/browser-based, consistent with how `car-classing.php`/`account.php`/`admin.php` are tested today), PHPMailer (already vendored under `phpmailer/`).

**Spec:** `docs/superpowers/specs/2026-09-22-tech-sheets-design.md`

## Global Constraints

- Only `submissions` (classed cars) are eligible tech-sheet sources — never `drafts`.
- Every checklist item must be explicitly OK or N/A before submit; blank is invalid.
- Helmet rating and Suit rating are required text fields; Underwear is the only optional driver-equipment item; all other equipment items are required confirm-toggles.
- Signatures are captured as drawn images (canvas → PNG), never typed names.
- `tech_sheets.status` is only `submitted` or `teched` — no pass/fail field exists anywhere in this schema.
- A sheet locks from competitor edits once `status = 'teched'` (owned by the follow-up admin/review plan, but the column and the edit-guard belong here).
- All new admin pages/actions reuse the existing `requireAuth()` (from `admin.php`) admin gate — no new role.
- CSRF protection (`generateCsrfToken()`/`validateCsrfToken()`) on every POST, matching every existing state-changing action in this codebase.

---

### Task 1: Checklist & driver-equipment definitions + validation

**Files:**
- Create: `wcma-calculator/tech-sheet-data.php`
- Test: `wcma-calculator/tests/TechSheetDataTest.php`

**Interfaces:**
- Produces: `TECH_CHECKLIST_SECTIONS` (assoc array: `section_key => ['label' => string, 'items' => [item_key => item_label]]`), `TECH_DRIVER_EQUIPMENT_ITEMS` (assoc array: `item_key => ['label' => string, 'has_rating' => bool, 'optional' => bool]`), `validateChecklist(array $checklist): bool`, `validateDriverEquipment(array $equipment): bool`, `emptyChecklist(): array`, `emptyDriverEquipment(): array` — all consumed by Tasks 3, 8, 9, 10 and by the follow-up admin/review plan.

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/TechSheetDataTest.php
use PHPUnit\Framework\TestCase;

final class TechSheetDataTest extends TestCase
{
    public function testEmptyChecklistHasEveryItemKeyAsNull(): void
    {
        require_once __DIR__ . '/../tech-sheet-data.php';
        $checklist = emptyChecklist();
        $expectedKeys = [];
        foreach (TECH_CHECKLIST_SECTIONS as $section) {
            foreach ($section['items'] as $key => $label) {
                $expectedKeys[] = $key;
            }
        }
        $this->assertCount(count($expectedKeys), $checklist);
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $checklist);
            $this->assertNull($checklist[$key]);
        }
    }

    public function testValidateChecklistRejectsMissingItem(): void
    {
        require_once __DIR__ . '/../tech-sheet-data.php';
        $checklist = emptyChecklist();
        unset($checklist['steering_linkage']);
        $this->assertFalse(validateChecklist($checklist));
    }

    public function testValidateChecklistRejectsNullValue(): void
    {
        require_once __DIR__ . '/../tech-sheet-data.php';
        $checklist = emptyChecklist();
        $this->assertFalse(validateChecklist($checklist)); // all still null
    }

    public function testValidateChecklistAcceptsAllOk(): void
    {
        require_once __DIR__ . '/../tech-sheet-data.php';
        $checklist = emptyChecklist();
        foreach ($checklist as $key => $v) {
            $checklist[$key] = ['status' => 'ok'];
        }
        $this->assertTrue(validateChecklist($checklist));
    }

    public function testValidateChecklistRejectsInvalidStatus(): void
    {
        require_once __DIR__ . '/../tech-sheet-data.php';
        $checklist = emptyChecklist();
        foreach ($checklist as $key => $v) {
            $checklist[$key] = ['status' => 'ok'];
        }
        $checklist['steering_linkage'] = ['status' => 'maybe'];
        $this->assertFalse(validateChecklist($checklist));
    }

    public function testValidateDriverEquipmentRequiresHelmetAndSuitRating(): void
    {
        require_once __DIR__ . '/../tech-sheet-data.php';
        $equipment = emptyDriverEquipment();
        foreach ($equipment as $key => $v) {
            $equipment[$key]['competitor_confirmed'] = true;
        }
        $this->assertFalse(validateDriverEquipment($equipment)); // helmet/suit rating still blank
        $equipment['helmet']['value'] = 'SA2020';
        $equipment['suit']['value'] = 'SFI 3.2A/5';
        $this->assertTrue(validateDriverEquipment($equipment));
    }

    public function testValidateDriverEquipmentAllowsUnderwearUnconfirmed(): void
    {
        require_once __DIR__ . '/../tech-sheet-data.php';
        $equipment = emptyDriverEquipment();
        foreach ($equipment as $key => $v) {
            $equipment[$key]['competitor_confirmed'] = true;
        }
        $equipment['helmet']['value'] = 'SA2020';
        $equipment['suit']['value'] = 'SFI 3.2A/5';
        $equipment['underwear']['competitor_confirmed'] = false;
        $this->assertTrue(validateDriverEquipment($equipment));
    }

    public function testValidateDriverEquipmentRejectsUnconfirmedRequiredItem(): void
    {
        require_once __DIR__ . '/../tech-sheet-data.php';
        $equipment = emptyDriverEquipment();
        foreach ($equipment as $key => $v) {
            $equipment[$key]['competitor_confirmed'] = true;
        }
        $equipment['helmet']['value'] = 'SA2020';
        $equipment['suit']['value'] = 'SFI 3.2A/5';
        $equipment['gloves']['competitor_confirmed'] = false;
        $this->assertFalse(validateDriverEquipment($equipment));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `phpunit.phar --filter TechSheetDataTest`
Expected: FAIL — `tech-sheet-data.php` does not exist yet (fatal error / require failure).

- [ ] **Step 3: Write the implementation**

```php
<?php
// wcma-calculator/tech-sheet-data.php

const TECH_CHECKLIST_SECTIONS = [
    'under_vehicle' => [
        'label' => 'Under Vehicle',
        'items' => [
            'steering_linkage'  => 'Steering linkage',
            'suspension_shocks' => 'Suspension & shocks',
            'wheel_bearing'     => 'Wheel bearing condition',
            'brakes_hoses'      => 'Brakes & hoses',
            'ball_joints'       => 'Ball joints, rod ends, bushings',
        ],
    ],
    'wheels_tires' => [
        'label' => 'Wheels & Tires',
        'items' => [
            'wheel_tire_condition' => 'Wheel and tire condition',
            'meets_class_criteria' => 'Meets class criteria',
        ],
    ],
    'engine_compartment' => [
        'label' => 'Engine Compartment',
        'items' => [
            'fuel_pump_lines'      => 'Fuel pump, lines & fittings — zero leaks',
            'oil_supply_lines'     => 'Oil supply tank, oil lines — security',
            'oil_catch_tank'       => 'Oil catch tank (min. 1L)',
            'coolant_hose'         => 'Coolant hose condition',
            'coolant_catch_tank'   => 'Coolant catch tank (min. 1L)',
            'battery_terminals'    => 'Battery terminal posts insulated',
            'battery_mount'        => 'Battery mount',
            'wiring_mounting'      => 'Wiring mounting and integrity',
            'carburetion_security' => 'Carburetion / fuel injection security',
        ],
    ],
    'vehicle_interior' => [
        'label' => 'Vehicle Interior',
        'items' => [
            'roll_cage_integrity'   => 'Roll bar padding / roll cage integrity',
            'accessories_mounted'   => 'Accessories properly mounted',
            'seat_mounted'          => "Driver's seat securely mounted",
            'rearview_mirror'       => 'Rearview mirror',
            'firewall_floor'        => 'Firewall and floor have no holes',
            'window_net_restraints' => 'Window net / arm restraints',
            'window_net_release'    => 'Window net release mechanism',
            'fire_extinguisher'     => 'Fire extinguisher (type & age)',
            'seat_belts'            => 'Seat belts (5 or 6 point, expiry date)',
        ],
    ],
    'vehicle_exterior' => [
        'label' => 'Vehicle Exterior',
        'items' => [
            'tow_points'          => 'Front and rear tow points',
            'appearance_markings' => 'Appearance and markings',
            'body_panels'         => 'Body panels secure',
            'windshield_windows'  => 'Windshield & windows',
            'headlights'          => 'Headlights (night and ice events)',
            'brake_tail_lights'   => 'Brake & tail lights as per class rules',
            'exhaust_system'      => 'Exhaust system meets regulations',
            'window_clips'        => 'Window clips or urethane',
            'bumper_condition'    => 'Bumper condition/attachment',
            'exterior_mirrors'    => 'Exterior mirrors (2)',
            'master_switch'       => 'Master switch — kills engine',
            'aero_mud_flaps'      => 'Aero and mud flaps secure',
            'rain_lights'         => 'Rain lights/rear facing light',
            'hood_trunk'          => 'Hood and trunk fastened properly',
        ],
    ],
    'fuel_tank_compartment' => [
        'label' => 'Fuel Tank Compartment',
        'items' => [
            'ventilation_check_valves' => 'Proper ventilation and check valves',
            'surge_tank_mounted'       => 'Surge tank safely mounted',
            'firewall_bulkhead'        => 'Firewall/bulkhead',
            'fuel_tank_mounted'        => 'Fuel tank/fuel cell securely mounted',
        ],
    ],
];

const TECH_DRIVER_EQUIPMENT_ITEMS = [
    'helmet'               => ['label' => 'Helmet', 'has_rating' => true,  'optional' => false],
    'goggles_visor'        => ['label' => 'Goggles or visor', 'has_rating' => false, 'optional' => false],
    'suit'                 => ['label' => 'Suit', 'has_rating' => true,  'optional' => false],
    'underwear'            => ['label' => 'Underwear (if required)', 'has_rating' => false, 'optional' => true],
    'shoes'                => ['label' => 'Shoes', 'has_rating' => false, 'optional' => false],
    'socks'                => ['label' => 'Socks', 'has_rating' => false, 'optional' => false],
    'gloves'               => ['label' => 'Gloves', 'has_rating' => false, 'optional' => false],
    'balaclava'            => ['label' => 'Balaclava', 'has_rating' => false, 'optional' => false],
    'head_neck_restraints' => ['label' => 'Head & Neck Restraints', 'has_rating' => false, 'optional' => false],
];

/** Every checklist item key, mapped to null (unanswered) — the shape a fresh form starts from. */
function emptyChecklist(): array {
    $out = [];
    foreach (TECH_CHECKLIST_SECTIONS as $section) {
        foreach ($section['items'] as $key => $label) {
            $out[$key] = null;
        }
    }
    return $out;
}

/** Every driver-equipment item key, mapped to its unanswered shape. */
function emptyDriverEquipment(): array {
    $out = [];
    foreach (TECH_DRIVER_EQUIPMENT_ITEMS as $key => $def) {
        $out[$key] = ['competitor_confirmed' => false, 'value' => null, 'tech_approved' => null];
    }
    return $out;
}

/**
 * True only if every checklist item key from TECH_CHECKLIST_SECTIONS is
 * present with status 'ok' or 'na'. fire_extinguisher/seat_belts may carry
 * extra free-text fields (type/age, expiry_date) but status still governs
 * completeness.
 */
function validateChecklist(array $checklist): bool {
    foreach (TECH_CHECKLIST_SECTIONS as $section) {
        foreach ($section['items'] as $key => $label) {
            if (!isset($checklist[$key]) || !is_array($checklist[$key])) return false;
            $status = $checklist[$key]['status'] ?? null;
            if (!in_array($status, ['ok', 'na'], true)) return false;
        }
    }
    return true;
}

/**
 * True only if: every non-optional item is either competitor_confirmed, and
 * helmet/suit additionally carry a non-blank rating value. The optional
 * "underwear" item may be left unconfirmed.
 */
function validateDriverEquipment(array $equipment): bool {
    foreach (TECH_DRIVER_EQUIPMENT_ITEMS as $key => $def) {
        if (!isset($equipment[$key]) || !is_array($equipment[$key])) return false;
        $confirmed = $equipment[$key]['competitor_confirmed'] ?? false;
        if (!$def['optional'] && $confirmed !== true) return false;
        if ($def['has_rating'] && trim((string)($equipment[$key]['value'] ?? '')) === '') return false;
    }
    return true;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `phpunit.phar --filter TechSheetDataTest`
Expected: PASS (8 tests)

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/tech-sheet-data.php wcma-calculator/tests/TechSheetDataTest.php
git commit -m "feat: add tech sheet checklist/equipment definitions and validation"
```

---

### Task 2: `events` table and CRUD

**Files:**
- Modify: `wcma-calculator/db.php`
- Test: `wcma-calculator/tests/DbEventsTest.php`

**Interfaces:**
- Consumes: nothing new (uses existing `db_connect()`/`db_init()` pattern)
- Produces: `db_create_event(PDO $pdo, string $name, string $event_date, ?string $location): int`, `db_get_active_events(PDO $pdo): array`, `db_get_all_events(PDO $pdo): array`, `db_get_event(PDO $pdo, int $id): ?array`, `db_update_event(PDO $pdo, int $id, string $name, string $event_date, ?string $location): void`, `db_set_event_active(PDO $pdo, int $id, bool $active): void` — consumed by Task 4 (admin UI) and Task 9 (competitor event picker).

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/DbEventsTest.php
use PHPUnit\Framework\TestCase;

final class DbEventsTest extends TestCase
{
    public function testCreateAndGetEvent(): void
    {
        $pdo = make_temp_pdo();
        $id = db_create_event($pdo, '2026 Spring Sprint', '2026-05-10', 'Race City Speedway');

        $event = db_get_event($pdo, $id);
        $this->assertSame('2026 Spring Sprint', $event['name']);
        $this->assertSame('2026-05-10', $event['event_date']);
        $this->assertSame('Race City Speedway', $event['location']);
        $this->assertSame(1, (int)$event['active']);
    }

    public function testActiveEventsOnlyReturnsActive(): void
    {
        $pdo = make_temp_pdo();
        $activeId = db_create_event($pdo, 'Active Event', '2026-06-01', null);
        $inactiveId = db_create_event($pdo, 'Inactive Event', '2026-07-01', null);
        db_set_event_active($pdo, $inactiveId, false);

        $active = db_get_active_events($pdo);
        $ids = array_column($active, 'id');
        $this->assertContains($activeId, $ids);
        $this->assertNotContains($inactiveId, $ids);
    }

    public function testGetAllEventsIncludesInactive(): void
    {
        $pdo = make_temp_pdo();
        $activeId = db_create_event($pdo, 'Active Event', '2026-06-01', null);
        $inactiveId = db_create_event($pdo, 'Inactive Event', '2026-07-01', null);
        db_set_event_active($pdo, $inactiveId, false);

        $all = db_get_all_events($pdo);
        $ids = array_column($all, 'id');
        $this->assertContains($activeId, $ids);
        $this->assertContains($inactiveId, $ids);
    }

    public function testUpdateEvent(): void
    {
        $pdo = make_temp_pdo();
        $id = db_create_event($pdo, 'Old Name', '2026-01-01', null);
        db_update_event($pdo, $id, 'New Name', '2026-02-02', 'New Location');

        $event = db_get_event($pdo, $id);
        $this->assertSame('New Name', $event['name']);
        $this->assertSame('2026-02-02', $event['event_date']);
        $this->assertSame('New Location', $event['location']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `phpunit.phar --filter DbEventsTest`
Expected: FAIL with "Call to undefined function db_create_event()"

- [ ] **Step 3: Add the `events` table to `db_init()` and the CRUD functions**

In `wcma-calculator/db.php`, add inside `db_init(PDO $pdo): void`, right after the existing `drafts` table creation (before the migration `ALTER TABLE` blocks):

```php
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS events (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT NOT NULL,
            event_date  DATE NOT NULL,
            location    TEXT,
            active      INTEGER NOT NULL DEFAULT 1,
            created_at  DATETIME NOT NULL
        )
    ");
```

Then, near the end of `db.php` (after the `// ── Rate limiting` section, or right after the `// ── Drafts` section — either is fine, this codebase doesn't enforce a strict section order beyond the comment banners), add:

```php
// ── Events ────────────────────────────────────────────────────────────────────

function db_create_event(PDO $pdo, string $name, string $event_date, ?string $location): int {
    $pdo->prepare("
        INSERT INTO events (name, event_date, location, active, created_at)
        VALUES (:name, :event_date, :location, 1, :created_at)
    ")->execute([
        ':name' => $name, ':event_date' => $event_date, ':location' => $location,
        ':created_at' => date('Y-m-d H:i:s'),
    ]);
    return (int)$pdo->lastInsertId();
}

function db_get_active_events(PDO $pdo): array {
    return $pdo->query("SELECT * FROM events WHERE active = 1 ORDER BY event_date ASC")->fetchAll();
}

function db_get_all_events(PDO $pdo): array {
    return $pdo->query("SELECT * FROM events ORDER BY event_date DESC")->fetchAll();
}

function db_get_event(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function db_update_event(PDO $pdo, int $id, string $name, string $event_date, ?string $location): void {
    $pdo->prepare("UPDATE events SET name = :name, event_date = :event_date, location = :location WHERE id = :id")
        ->execute([':name' => $name, ':event_date' => $event_date, ':location' => $location, ':id' => $id]);
}

function db_set_event_active(PDO $pdo, int $id, bool $active): void {
    $pdo->prepare("UPDATE events SET active = :active WHERE id = :id")
        ->execute([':active' => $active ? 1 : 0, ':id' => $id]);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `phpunit.phar --filter DbEventsTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/db.php wcma-calculator/tests/DbEventsTest.php
git commit -m "feat: add events table and CRUD"
```

---

### Task 3: `tech_sheets` and `tech_sheet_drivers` tables and CRUD

**Files:**
- Modify: `wcma-calculator/db.php`
- Test: `wcma-calculator/tests/DbTechSheetsTest.php`

**Interfaces:**
- Consumes: `emptyChecklist()`/`emptyDriverEquipment()` from Task 1 (test fixtures only — `db.php` itself stores checklist/equipment as opaque JSON strings and never imports `tech-sheet-data.php`, keeping the DB layer decoupled from the item-list shape)
- Produces: `db_insert_tech_sheet(PDO $pdo, array $data): int`, `db_get_tech_sheet(PDO $pdo, int $id): ?array`, `db_get_user_tech_sheets(PDO $pdo, int $user_id): array`, `db_update_tech_sheet(PDO $pdo, int $id, array $data): void`, `db_update_tech_sheet_signatures(PDO $pdo, int $id, array $paths): void`, `db_add_tech_sheet_driver(PDO $pdo, int $tech_sheet_id, int $driver_number, string $driver_name, string $equipment_json): int`, `db_get_tech_sheet_drivers(PDO $pdo, int $tech_sheet_id): array`, `db_replace_tech_sheet_drivers(PDO $pdo, int $tech_sheet_id, array $drivers): void`, `db_update_email_sent_tech_sheet(PDO $pdo, int $id, int $sent): void` — consumed by Tasks 9 and 10, and by the follow-up admin/review plan.

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/DbTechSheetsTest.php
use PHPUnit\Framework\TestCase;

final class DbTechSheetsTest extends TestCase
{
    private function makeUserAndSubmission(PDO $pdo): array {
        $userId = db_create_user($pdo, ['email' => 'racer@example.com', 'name' => 'Racer', 'password_hash' => 'x', 'google_id' => null]);
        $subId = db_insert_submission($pdo, [
            ':submitted_at' => date('Y-m-d H:i:s'), ':name' => 'Racer', ':email' => 'racer@example.com',
            ':year' => '2020', ':make' => 'Mazda', ':model' => 'MX-5', ':comments' => null,
            ':competition_weight' => 2200, ':declared_hp' => 150, ':dyno_hp' => null,
            ':chassis_display' => null, ':body_mods_display' => null, ':transmission_display' => null,
            ':drivetrain_display' => null, ':tires_display' => null, ':brake_suspension' => null,
            ':chassis_value' => 0, ':body_mods_value' => 0, ':transmission_value' => 0,
            ':drivetrain_value' => 0, ':tires_value' => 0, ':brake_suspension_value' => 0,
            ':weight_factor' => 0, ':modification_factor' => 0, ':base_ratio' => 14.67, ':modified_ratio' => 14.67,
            ':calculated_class' => 'IT1', ':user_id' => $userId,
        ]);
        $eventId = db_create_event($pdo, 'Spring Sprint', '2026-05-10', null);
        return [$userId, $subId, $eventId];
    }

    private function baseTechSheetData(int $userId, int $subId, int $eventId): array {
        return [
            'submission_id' => $subId, 'user_id' => $userId, 'event_id' => $eventId, 'sheet_type' => 'standard',
            'entrant_name' => 'Racer', 'driver_name' => 'Racer', 'car_make' => 'Mazda', 'car_model' => 'MX-5',
            'car_colour' => 'Red', 'car_number' => '42', 'class' => 'IT1', 'engine_cc' => '1800', 'engine_hp' => '150',
            'car_weight' => 2200, 'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1,
        ];
    }

    public function testInsertAndGetTechSheet(): void
    {
        $pdo = make_temp_pdo();
        [$userId, $subId, $eventId] = $this->makeUserAndSubmission($pdo);

        $id = db_insert_tech_sheet($pdo, $this->baseTechSheetData($userId, $subId, $eventId));

        $sheet = db_get_tech_sheet($pdo, $id);
        $this->assertSame('standard', $sheet['sheet_type']);
        $this->assertSame('submitted', $sheet['status']);
        $this->assertSame($subId, (int)$sheet['submission_id']);
    }

    public function testGetUserTechSheetsOrderedNewestFirst(): void
    {
        $pdo = make_temp_pdo();
        [$userId, $subId, $eventId] = $this->makeUserAndSubmission($pdo);

        $id1 = db_insert_tech_sheet($pdo, $this->baseTechSheetData($userId, $subId, $eventId));
        sleep(1);
        $id2 = db_insert_tech_sheet($pdo, $this->baseTechSheetData($userId, $subId, $eventId));

        $sheets = db_get_user_tech_sheets($pdo, $userId);
        $this->assertCount(2, $sheets);
        $this->assertSame($id2, (int)$sheets[0]['id']);
    }

    public function testUpdateTechSheetChecklist(): void
    {
        $pdo = make_temp_pdo();
        [$userId, $subId, $eventId] = $this->makeUserAndSubmission($pdo);
        $id = db_insert_tech_sheet($pdo, $this->baseTechSheetData($userId, $subId, $eventId));

        db_update_tech_sheet($pdo, $id, array_merge($this->baseTechSheetData($userId, $subId, $eventId), [
            'checklist_json' => '{"steering_linkage":{"status":"ok"}}',
            'log_book_turned_in' => 0,
        ]));

        $sheet = db_get_tech_sheet($pdo, $id);
        $this->assertSame('{"steering_linkage":{"status":"ok"}}', $sheet['checklist_json']);
        $this->assertSame(0, (int)$sheet['log_book_turned_in']);
    }

    public function testUpdateTechSheetSignatures(): void
    {
        $pdo = make_temp_pdo();
        [$userId, $subId, $eventId] = $this->makeUserAndSubmission($pdo);
        $id = db_insert_tech_sheet($pdo, $this->baseTechSheetData($userId, $subId, $eventId));

        db_update_tech_sheet_signatures($pdo, $id, [
            'entrant_signature_path' => 'uploads/tech-sheets/1/entrant.png',
            'driver_signature_path' => 'uploads/tech-sheets/1/driver.png',
        ]);

        $sheet = db_get_tech_sheet($pdo, $id);
        $this->assertSame('uploads/tech-sheets/1/entrant.png', $sheet['entrant_signature_path']);
        $this->assertNotNull($sheet['entrant_signed_at']);
    }

    public function testAddAndGetTechSheetDrivers(): void
    {
        $pdo = make_temp_pdo();
        [$userId, $subId, $eventId] = $this->makeUserAndSubmission($pdo);
        $id = db_insert_tech_sheet($pdo, array_merge($this->baseTechSheetData($userId, $subId, $eventId), ['sheet_type' => 'endurance']));

        db_add_tech_sheet_driver($pdo, $id, 2, 'Co-Driver A', '{"helmet":{"competitor_confirmed":true}}');
        db_add_tech_sheet_driver($pdo, $id, 3, 'Co-Driver B', '{}');

        $drivers = db_get_tech_sheet_drivers($pdo, $id);
        $this->assertCount(2, $drivers);
        $this->assertSame('Co-Driver A', $drivers[0]['driver_name']);
        $this->assertSame(2, (int)$drivers[0]['driver_number']);
    }

    public function testReplaceTechSheetDriversClearsOldRows(): void
    {
        $pdo = make_temp_pdo();
        [$userId, $subId, $eventId] = $this->makeUserAndSubmission($pdo);
        $id = db_insert_tech_sheet($pdo, array_merge($this->baseTechSheetData($userId, $subId, $eventId), ['sheet_type' => 'endurance']));
        db_add_tech_sheet_driver($pdo, $id, 2, 'Stale Driver', '{}');

        db_replace_tech_sheet_drivers($pdo, $id, [
            ['driver_number' => 2, 'driver_name' => 'Fresh Driver', 'equipment_json' => '{}'],
        ]);

        $drivers = db_get_tech_sheet_drivers($pdo, $id);
        $this->assertCount(1, $drivers);
        $this->assertSame('Fresh Driver', $drivers[0]['driver_name']);
    }

    public function testUpdateEmailSentTechSheet(): void
    {
        $pdo = make_temp_pdo();
        [$userId, $subId, $eventId] = $this->makeUserAndSubmission($pdo);
        $id = db_insert_tech_sheet($pdo, $this->baseTechSheetData($userId, $subId, $eventId));

        db_update_email_sent_tech_sheet($pdo, $id, 1);

        $sheet = db_get_tech_sheet($pdo, $id);
        $this->assertSame(1, (int)$sheet['email_sent']);
        $this->assertSame(1, (int)$sheet['email_send_count']);
        $this->assertNotNull($sheet['last_emailed_at']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `phpunit.phar --filter DbTechSheetsTest`
Expected: FAIL with "Call to undefined function db_insert_tech_sheet()"

- [ ] **Step 3: Add tables to `db_init()` and the CRUD functions**

In `db_init(PDO $pdo): void`, right after the `events` table block added in Task 2:

```php
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tech_sheets (
            id                      INTEGER PRIMARY KEY AUTOINCREMENT,
            submission_id           INTEGER NOT NULL,
            user_id                 INTEGER NOT NULL,
            event_id                INTEGER NOT NULL,
            sheet_type              TEXT NOT NULL,

            entrant_name            TEXT NOT NULL,
            driver_name             TEXT NOT NULL,
            car_make                TEXT NOT NULL,
            car_model               TEXT NOT NULL,
            car_colour              TEXT NOT NULL,
            car_number              TEXT NOT NULL,
            class                   TEXT NOT NULL,
            engine_cc               TEXT,
            engine_hp               TEXT,
            car_weight              INTEGER NOT NULL,

            checklist_json          TEXT NOT NULL,
            driver1_equipment_json  TEXT NOT NULL,
            log_book_turned_in      INTEGER,

            entrant_signature_path  TEXT,
            entrant_signed_at       DATETIME,
            driver_signature_path   TEXT,
            driver_signed_at        DATETIME,
            tech_signature_path     TEXT,
            tech_signed_at          DATETIME,

            status                  TEXT NOT NULL DEFAULT 'submitted',
            reviewed_by_user_id     INTEGER,
            reviewed_at             DATETIME,

            email_sent              INTEGER DEFAULT 0,
            email_send_count        INTEGER NOT NULL DEFAULT 0,
            last_emailed_at         DATETIME,

            created_at              DATETIME NOT NULL,
            updated_at              DATETIME NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tech_sheet_drivers (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            tech_sheet_id    INTEGER NOT NULL,
            driver_number    INTEGER NOT NULL,
            driver_name      TEXT NOT NULL,
            equipment_json   TEXT NOT NULL
        )
    ");
```

Then add, in a new `// ── Tech Sheets ──` section near the Events functions:

```php
// ── Tech Sheets ───────────────────────────────────────────────────────────────

function db_insert_tech_sheet(PDO $pdo, array $data): int {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        INSERT INTO tech_sheets (
            submission_id, user_id, event_id, sheet_type,
            entrant_name, driver_name, car_make, car_model, car_colour, car_number,
            class, engine_cc, engine_hp, car_weight,
            checklist_json, driver1_equipment_json, log_book_turned_in,
            status, created_at, updated_at
        ) VALUES (
            :submission_id, :user_id, :event_id, :sheet_type,
            :entrant_name, :driver_name, :car_make, :car_model, :car_colour, :car_number,
            :class, :engine_cc, :engine_hp, :car_weight,
            :checklist_json, :driver1_equipment_json, :log_book_turned_in,
            'submitted', :created_at, :updated_at
        )
    ");
    $stmt->execute([
        ':submission_id' => $data['submission_id'], ':user_id' => $data['user_id'], ':event_id' => $data['event_id'],
        ':sheet_type' => $data['sheet_type'], ':entrant_name' => $data['entrant_name'], ':driver_name' => $data['driver_name'],
        ':car_make' => $data['car_make'], ':car_model' => $data['car_model'], ':car_colour' => $data['car_colour'],
        ':car_number' => $data['car_number'], ':class' => $data['class'], ':engine_cc' => $data['engine_cc'] ?? null,
        ':engine_hp' => $data['engine_hp'] ?? null, ':car_weight' => $data['car_weight'],
        ':checklist_json' => $data['checklist_json'], ':driver1_equipment_json' => $data['driver1_equipment_json'],
        ':log_book_turned_in' => $data['log_book_turned_in'] ?? null,
        ':created_at' => $now, ':updated_at' => $now,
    ]);
    return (int)$pdo->lastInsertId();
}

function db_get_tech_sheet(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM tech_sheets WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function db_get_user_tech_sheet(PDO $pdo, int $user_id, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM tech_sheets WHERE id = :id AND user_id = :user_id");
    $stmt->execute([':id' => $id, ':user_id' => $user_id]);
    return $stmt->fetch() ?: null;
}

function db_get_user_tech_sheets(PDO $pdo, int $user_id): array {
    $stmt = $pdo->prepare("SELECT * FROM tech_sheets WHERE user_id = :user_id ORDER BY created_at DESC, id DESC");
    $stmt->execute([':user_id' => $user_id]);
    return $stmt->fetchAll();
}

function db_update_tech_sheet(PDO $pdo, int $id, array $data): void {
    $pdo->prepare("
        UPDATE tech_sheets SET
            event_id = :event_id, sheet_type = :sheet_type,
            entrant_name = :entrant_name, driver_name = :driver_name,
            car_make = :car_make, car_model = :car_model, car_colour = :car_colour, car_number = :car_number,
            class = :class, engine_cc = :engine_cc, engine_hp = :engine_hp, car_weight = :car_weight,
            checklist_json = :checklist_json, driver1_equipment_json = :driver1_equipment_json,
            log_book_turned_in = :log_book_turned_in, updated_at = :updated_at
        WHERE id = :id
    ")->execute([
        ':event_id' => $data['event_id'], ':sheet_type' => $data['sheet_type'],
        ':entrant_name' => $data['entrant_name'], ':driver_name' => $data['driver_name'],
        ':car_make' => $data['car_make'], ':car_model' => $data['car_model'], ':car_colour' => $data['car_colour'],
        ':car_number' => $data['car_number'], ':class' => $data['class'], ':engine_cc' => $data['engine_cc'] ?? null,
        ':engine_hp' => $data['engine_hp'] ?? null, ':car_weight' => $data['car_weight'],
        ':checklist_json' => $data['checklist_json'], ':driver1_equipment_json' => $data['driver1_equipment_json'],
        ':log_book_turned_in' => $data['log_book_turned_in'] ?? null,
        ':updated_at' => date('Y-m-d H:i:s'), ':id' => $id,
    ]);
}

function db_update_tech_sheet_signatures(PDO $pdo, int $id, array $paths): void {
    $now = date('Y-m-d H:i:s');
    $sets = [];
    $params = [':id' => $id];
    foreach (['entrant_signature_path', 'driver_signature_path', 'tech_signature_path'] as $col) {
        if (array_key_exists($col, $paths)) {
            $sets[] = "{$col} = :{$col}";
            $params[":{$col}"] = $paths[$col];
            $signedAtCol = str_replace('_signature_path', '_signed_at', $col);
            $sets[] = "{$signedAtCol} = :{$signedAtCol}";
            $params[":{$signedAtCol}"] = $now;
        }
    }
    if (empty($sets)) return;
    $pdo->prepare("UPDATE tech_sheets SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
}

function db_update_email_sent_tech_sheet(PDO $pdo, int $id, int $sent): void {
    if ($sent === 1) {
        $pdo->prepare("
            UPDATE tech_sheets
            SET email_sent = 1, last_emailed_at = :now, email_send_count = email_send_count + 1
            WHERE id = :id
        ")->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id]);
    } else {
        $pdo->prepare("UPDATE tech_sheets SET email_sent = 0 WHERE id = :id")->execute([':id' => $id]);
    }
}

function db_add_tech_sheet_driver(PDO $pdo, int $tech_sheet_id, int $driver_number, string $driver_name, string $equipment_json): int {
    $pdo->prepare("
        INSERT INTO tech_sheet_drivers (tech_sheet_id, driver_number, driver_name, equipment_json)
        VALUES (:tech_sheet_id, :driver_number, :driver_name, :equipment_json)
    ")->execute([
        ':tech_sheet_id' => $tech_sheet_id, ':driver_number' => $driver_number,
        ':driver_name' => $driver_name, ':equipment_json' => $equipment_json,
    ]);
    return (int)$pdo->lastInsertId();
}

function db_get_tech_sheet_drivers(PDO $pdo, int $tech_sheet_id): array {
    $stmt = $pdo->prepare("SELECT * FROM tech_sheet_drivers WHERE tech_sheet_id = :tsid ORDER BY driver_number ASC");
    $stmt->execute([':tsid' => $tech_sheet_id]);
    return $stmt->fetchAll();
}

/** Replaces all additional-driver rows for a sheet — used on submit/edit since the whole set is resent each save. */
function db_replace_tech_sheet_drivers(PDO $pdo, int $tech_sheet_id, array $drivers): void {
    $pdo->prepare("DELETE FROM tech_sheet_drivers WHERE tech_sheet_id = :tsid")->execute([':tsid' => $tech_sheet_id]);
    foreach ($drivers as $d) {
        db_add_tech_sheet_driver($pdo, $tech_sheet_id, (int)$d['driver_number'], $d['driver_name'], $d['equipment_json']);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `phpunit.phar --filter DbTechSheetsTest`
Expected: PASS (7 tests)

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/db.php wcma-calculator/tests/DbTechSheetsTest.php
git commit -m "feat: add tech_sheets and tech_sheet_drivers tables and CRUD"
```

---

### Task 4: Admin Events management page

**Files:**
- Modify: `wcma-calculator/admin.php`

**Interfaces:**
- Consumes: `db_create_event`, `db_get_all_events`, `db_update_event`, `db_set_event_active` (Task 2); `requireAuth()`, `generateCsrfToken()`, `validateCsrfToken()`, `setFlash()`, `getFlash()`, `h()`, `renderSiteHeader()`, `renderCommonNav()` (all pre-existing in this codebase)
- Produces: `admin.php?action=events` page, used by competitors indirectly (they see events created here in Task 9's dropdown) — no other task consumes this page's internals directly.

- [ ] **Step 1: Add routes to the existing `switch ($action)` block**

In `wcma-calculator/admin.php`, add these cases (placed after the existing `activate` case, before `default`):

```php
    case 'events':
        requireAuth();
        handleEventsList($pdo);
        break;

    case 'event-create':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=events'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleEventCreate($pdo);
        break;

    case 'event-update':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=events'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleEventUpdate($pdo, (int)($_POST['id'] ?? 0));
        break;

    case 'event-deactivate':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=events'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleEventSetActive($pdo, (int)($_POST['id'] ?? 0), false);
        break;

    case 'event-activate':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin.php?action=events'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleEventSetActive($pdo, (int)($_POST['id'] ?? 0), true);
        break;
```

- [ ] **Step 2: Add the handler and render functions**

Append to `wcma-calculator/admin.php`:

```php
function handleEventsList(PDO $pdo): void {
    $events = db_get_all_events($pdo);
    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderEventsPage($events, $csrf, $flash);
}

function handleEventCreate(PDO $pdo): void {
    $name = trim($_POST['name'] ?? '');
    $date = trim($_POST['event_date'] ?? '');
    $location = trim($_POST['location'] ?? '');

    if ($name === '' || $date === '') {
        setFlash('Event name and date are required.', 'error');
        header('Location: admin.php?action=events');
        exit;
    }

    db_create_event($pdo, $name, $date, $location !== '' ? $location : null);
    setFlash('Event created.', 'success');
    header('Location: admin.php?action=events');
    exit;
}

function handleEventUpdate(PDO $pdo, int $id): void {
    $name = trim($_POST['name'] ?? '');
    $date = trim($_POST['event_date'] ?? '');
    $location = trim($_POST['location'] ?? '');

    if ($name === '' || $date === '') {
        setFlash('Event name and date are required.', 'error');
        header('Location: admin.php?action=events');
        exit;
    }

    db_update_event($pdo, $id, $name, $date, $location !== '' ? $location : null);
    setFlash('Event updated.', 'success');
    header('Location: admin.php?action=events');
    exit;
}

function handleEventSetActive(PDO $pdo, int $id, bool $active): void {
    db_set_event_active($pdo, $id, $active);
    setFlash($active ? 'Event reactivated.' : 'Event deactivated.', 'success');
    header('Location: admin.php?action=events');
    exit;
}

function renderEventsPage(array $events, string $csrf, ?array $flash): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Events — WCMA Admin</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Events', '<a href="admin.php">Submissions</a>' . renderCommonNav('admin')); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

  <div class="detail-card" style="margin-bottom:1.5rem">
    <h2>Add Event</h2>
    <form method="post" action="admin.php?action=event-create" class="edit-form">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <label for="new-event-name">Name</label>
      <input type="text" id="new-event-name" name="name" required>
      <label for="new-event-date">Date</label>
      <input type="date" id="new-event-date" name="event_date" required>
      <label for="new-event-location">Location</label>
      <input type="text" id="new-event-location" name="location">
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Add Event</button>
      </div>
    </form>
  </div>

  <table class="data-table" id="events-table">
    <thead><tr><th>Date</th><th>Name</th><th>Location</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (empty($events)): ?>
      <tr><td colspan="5" class="empty-row">No events yet.</td></tr>
    <?php else: foreach ($events as $e): ?>
      <tr>
        <td><?= h(date('M j, Y', strtotime($e['event_date']))) ?></td>
        <td><?= h($e['name']) ?></td>
        <td><?= h($e['location'] ?? '—') ?></td>
        <td class="<?= $e['active'] ? 'badge-ok' : 'badge-fail' ?>"><?= $e['active'] ? 'Active' : 'Inactive' ?></td>
        <td class="actions">
          <?php if ($e['active']): ?>
          <form method="post" action="admin.php?action=event-deactivate" style="display:inline" data-confirm="Deactivate <?= h($e['name']) ?>? Competitors won't be able to pick it for new tech sheets.">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
            <button type="submit" class="link-button">Deactivate</button>
          </form>
          <?php else: ?>
          <form method="post" action="admin.php?action=event-activate" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
            <button type="submit" class="link-button">Reactivate</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<script src="js/confirm-modal.js"></script>
<script src="js/form-feedback.js"></script>
</body>
</html><?php
}
```

- [ ] **Step 3: Add an "Events" link to the submissions list nav**

In `renderListPage()` (existing function in `admin.php`), change the `renderSiteHeader` call:

```php
  <?php renderSiteHeader('WCMA Submissions', '<a href="admin.php?action=users">Manage Users</a> <a href="admin.php?action=events">Events</a>' . renderCommonNav('admin')); ?>
```

- [ ] **Step 4: Manually verify**

Run the app (`php -S localhost:8000` from `wcma-calculator/`), log in as the bootstrap admin, visit `admin.php?action=events`, create an event, confirm it appears in the table, deactivate/reactivate it, confirm the flash messages and status badge update. Confirm the "Events" link appears on `admin.php` and `admin.php?action=users`.

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/admin.php
git commit -m "feat: add admin events management page"
```

---

### Task 5: Signature pad JS component

**Files:**
- Create: `wcma-calculator/js/signature-pad.js`

**Interfaces:**
- Produces: `window.WcmaSignaturePad.attach(canvasEl)` → returns `{ clear(): void, isEmpty(): boolean, toPNGDataURL(): string }`. Consumed by Task 9 (competitor submit form) and by the follow-up admin/review plan (tech signature).

- [ ] **Step 1: Write the implementation**

```javascript
// wcma-calculator/js/signature-pad.js
// Minimal canvas-based signature capture — no external library.
// Pointer Events cover mouse, touch, and pen in one code path.
window.WcmaSignaturePad = (function () {
    function attach(canvas) {
        const ctx = canvas.getContext('2d');
        let drawing = false;
        let hasInk = false;
        let lastX = 0, lastY = 0;

        function resizeForDPR() {
            const dpr = window.devicePixelRatio || 1;
            const rect = canvas.getBoundingClientRect();
            canvas.width = rect.width * dpr;
            canvas.height = rect.height * dpr;
            ctx.scale(dpr, dpr);
            ctx.lineWidth = 2;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#2c3e50';
        }
        resizeForDPR();

        function pos(e) {
            const rect = canvas.getBoundingClientRect();
            return { x: e.clientX - rect.left, y: e.clientY - rect.top };
        }

        canvas.addEventListener('pointerdown', function (e) {
            drawing = true;
            hasInk = true;
            const p = pos(e);
            lastX = p.x; lastY = p.y;
            canvas.setPointerCapture(e.pointerId);
        });

        canvas.addEventListener('pointermove', function (e) {
            if (!drawing) return;
            const p = pos(e);
            ctx.beginPath();
            ctx.moveTo(lastX, lastY);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            lastX = p.x; lastY = p.y;
        });

        function stop() { drawing = false; }
        canvas.addEventListener('pointerup', stop);
        canvas.addEventListener('pointercancel', stop);
        canvas.addEventListener('pointerleave', stop);

        return {
            clear: function () {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                hasInk = false;
            },
            isEmpty: function () { return !hasInk; },
            toPNGDataURL: function () { return canvas.toDataURL('image/png'); },
        };
    }

    return { attach: attach };
})();
```

- [ ] **Step 2: Manually verify**

Create a throwaway HTML file that includes `<canvas id="sig" width="300" height="120" style="border:1px solid #ccc"></canvas>` and:

```html
<script src="js/signature-pad.js"></script>
<script>
  const pad = WcmaSignaturePad.attach(document.getElementById('sig'));
  document.write('<button onclick="pad.clear()">Clear</button>');
</script>
```

Open it in a browser, draw on the canvas with a mouse, confirm a line is drawn, confirm `pad.isEmpty()` returns `false` after drawing (check via devtools console), confirm `pad.clear()` erases it and `pad.isEmpty()` returns `true` again. Test on a phone (or a browser's mobile device emulation) to confirm touch drawing works. Delete the throwaway file when done.

- [ ] **Step 3: Commit**

```bash
git add wcma-calculator/js/signature-pad.js
git commit -m "feat: add canvas-based signature pad component"
```

---

### Task 6: Checklist accordion JS component

**Files:**
- Create: `wcma-calculator/js/tech-sheet-checklist.js`

**Interfaces:**
- Consumes: a `sections` config array shaped like `TECH_CHECKLIST_SECTIONS` from Task 1, serialized to JSON and embedded in the page by Task 9 (e.g. `<script>const TECH_CHECKLIST_SECTIONS = <?= json_encode(TECH_CHECKLIST_SECTIONS) ?>;</script>`)
- Produces: `window.WcmaTechChecklist.render(container, sections, initialState)` → returns `{ getState(): object, isComplete(): boolean }`, where `initialState` and the returned state are both shaped `{ [itemKey]: { status: 'ok'|'na'|null, ...extraFields } }`. Consumed by Task 9.

- [ ] **Step 1: Write the implementation**

```javascript
// wcma-calculator/js/tech-sheet-checklist.js
// Renders the Mockup-A accordion: collapsible sections, sticky progress bar,
// "Mark all OK" per section, two-button OK/N/A chips per item.
window.WcmaTechChecklist = (function () {
    function countComplete(sections, state) {
        let total = 0, done = 0;
        Object.keys(sections).forEach(function (sectionKey) {
            Object.keys(sections[sectionKey].items).forEach(function (itemKey) {
                total++;
                if (state[itemKey] && state[itemKey].status) done++;
            });
        });
        return { total: total, done: done };
    }

    function sectionCount(items, state) {
        let total = 0, done = 0;
        Object.keys(items).forEach(function (itemKey) {
            total++;
            if (state[itemKey] && state[itemKey].status) done++;
        });
        return { total: total, done: done };
    }

    function render(container, sections, initialState) {
        const state = JSON.parse(JSON.stringify(initialState || {}));
        Object.keys(sections).forEach(function (sectionKey) {
            Object.keys(sections[sectionKey].items).forEach(function (itemKey) {
                if (!state[itemKey]) state[itemKey] = { status: null };
            });
        });

        const progressWrap = document.createElement('div');
        progressWrap.className = 'checklist-progress-wrap';
        const progressLabel = document.createElement('div');
        progressLabel.className = 'checklist-progress-label';
        const progressBarOuter = document.createElement('div');
        progressBarOuter.className = 'checklist-progress-bar';
        const progressFill = document.createElement('div');
        progressFill.className = 'checklist-progress-fill';
        progressBarOuter.appendChild(progressFill);
        progressWrap.appendChild(progressLabel);
        progressWrap.appendChild(progressBarOuter);
        container.appendChild(progressWrap);

        function updateProgress() {
            const c = countComplete(sections, state);
            const pct = c.total === 0 ? 0 : Math.round((c.done / c.total) * 100);
            progressLabel.textContent = c.done + ' of ' + c.total + ' items checked (' + pct + '%)';
            progressFill.style.width = pct + '%';
        }

        Object.keys(sections).forEach(function (sectionKey) {
            const section = sections[sectionKey];
            const sectionEl = document.createElement('div');
            sectionEl.className = 'checklist-section';

            const header = document.createElement('div');
            header.className = 'checklist-section-header';
            const nameWrap = document.createElement('div');
            const nameEl = document.createElement('div');
            nameEl.className = 'checklist-section-name';
            nameEl.textContent = section.label;
            const countEl = document.createElement('div');
            countEl.className = 'checklist-section-count';
            nameWrap.appendChild(nameEl);
            nameWrap.appendChild(countEl);
            const chevron = document.createElement('div');
            chevron.className = 'checklist-chevron';
            chevron.textContent = '▾';
            header.appendChild(nameWrap);
            header.appendChild(chevron);

            const body = document.createElement('div');
            body.className = 'checklist-section-body';

            const markAll = document.createElement('div');
            markAll.className = 'checklist-mark-all';
            markAll.textContent = 'Mark all OK';
            markAll.addEventListener('click', function () {
                Object.keys(section.items).forEach(function (itemKey) {
                    state[itemKey].status = 'ok';
                });
                refreshAllChips();
                updateSectionCount();
                updateProgress();
            });
            body.appendChild(markAll);

            const chipRefs = {};

            function refreshAllChips() {
                Object.keys(chipRefs).forEach(function (itemKey) {
                    setChipVisual(itemKey);
                });
            }

            function setChipVisual(itemKey) {
                const refs = chipRefs[itemKey];
                refs.ok.classList.toggle('checklist-chip-selected-ok', state[itemKey].status === 'ok');
                refs.na.classList.toggle('checklist-chip-selected-na', state[itemKey].status === 'na');
            }

            function updateSectionCount() {
                const c = sectionCount(section.items, state);
                countEl.textContent = c.done + ' of ' + c.total + ' complete';
            }

            Object.keys(section.items).forEach(function (itemKey) {
                const row = document.createElement('div');
                row.className = 'checklist-item-row';
                const label = document.createElement('div');
                label.className = 'checklist-item-label';
                label.textContent = section.items[itemKey];
                const group = document.createElement('div');
                group.className = 'checklist-toggle-group';
                const okBtn = document.createElement('button');
                okBtn.type = 'button';
                okBtn.className = 'checklist-chip';
                okBtn.textContent = 'OK';
                const naBtn = document.createElement('button');
                naBtn.type = 'button';
                naBtn.className = 'checklist-chip';
                naBtn.textContent = 'N/A';

                okBtn.addEventListener('click', function () {
                    state[itemKey].status = 'ok';
                    setChipVisual(itemKey);
                    updateSectionCount();
                    updateProgress();
                });
                naBtn.addEventListener('click', function () {
                    state[itemKey].status = 'na';
                    setChipVisual(itemKey);
                    updateSectionCount();
                    updateProgress();
                });

                chipRefs[itemKey] = { ok: okBtn, na: naBtn };
                group.appendChild(okBtn);
                group.appendChild(naBtn);
                row.appendChild(label);
                row.appendChild(group);
                body.appendChild(row);
            });

            header.addEventListener('click', function () {
                sectionEl.classList.toggle('checklist-section-open');
            });

            sectionEl.appendChild(header);
            sectionEl.appendChild(body);
            container.appendChild(sectionEl);

            refreshAllChips();
            updateSectionCount();
        });

        updateProgress();

        return {
            getState: function () { return JSON.parse(JSON.stringify(state)); },
            isComplete: function () { return countComplete(sections, state).done === countComplete(sections, state).total; },
        };
    }

    return { render: render };
})();
```

- [ ] **Step 2: Manually verify**

Create a throwaway HTML file:

```html
<script>
const sections = {
  under_vehicle: { label: 'Under Vehicle', items: { steering_linkage: 'Steering linkage', brakes_hoses: 'Brakes & hoses' } },
  wheels_tires: { label: 'Wheels & Tires', items: { wheel_tire_condition: 'Wheel and tire condition' } },
};
</script>
<div id="checklist"></div>
<script src="js/tech-sheet-checklist.js"></script>
<script>
  const widget = WcmaTechChecklist.render(document.getElementById('checklist'), sections, {});
  window.checklistWidget = widget;
</script>
```

Open in a browser, expand a section by clicking its header, tap OK/N/A on an item and confirm the chip highlights and the section/overall progress counts update, click "Mark all OK" and confirm every item in that section highlights and counts update. In devtools console, run `checklistWidget.getState()` and confirm it reflects the clicks; run `checklistWidget.isComplete()` before and after answering every item. Test on a narrow (390px) viewport via devtools device toolbar to confirm tap targets are usable. Delete the throwaway file when done.

- [ ] **Step 3: Commit**

```bash
git add wcma-calculator/js/tech-sheet-checklist.js
git commit -m "feat: add checklist accordion widget"
```

---

### Task 7: CSS for accordion, signature pad, and tech sheet form

**Files:**
- Modify: `wcma-calculator/css/calculator.css`

**Interfaces:**
- Consumes: existing `:root` custom properties (`--primary-color`, `--secondary-color`, `--success-color`, `--border-color`, `--border-radius`)
- Produces: classes used by Task 6's generated DOM (`checklist-*`) and Task 9's form markup (`.sig-pad-wrap`, `.tech-sheet-form`, `.tech-sheet-header-grid`)

- [ ] **Step 1: Append styles**

Add to the end of `wcma-calculator/css/calculator.css`:

```css
/* Tech Sheet checklist accordion */
.checklist-progress-wrap {
    position: sticky;
    top: 0;
    background: white;
    border-bottom: 1px solid var(--border-color);
    padding: 0.6rem 0 0.8rem;
    z-index: 5;
}
.checklist-progress-label {
    font-size: 0.8rem;
    font-weight: 600;
    color: #666;
    margin-bottom: 0.4rem;
}
.checklist-progress-bar {
    height: 8px;
    background: #e9ecef;
    border-radius: 20px;
    overflow: hidden;
}
.checklist-progress-fill {
    height: 100%;
    width: 0%;
    background: var(--success-color);
    border-radius: 20px;
    transition: width 0.2s ease;
}

.checklist-section {
    border-bottom: 1px solid var(--border-color);
    background: white;
}
.checklist-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.9rem 0.2rem;
    cursor: pointer;
}
.checklist-section-name { font-weight: 700; font-size: 0.95rem; color: var(--primary-color); }
.checklist-section-count { font-size: 0.78rem; color: #888; margin-top: 0.15rem; }
.checklist-chevron { font-size: 1.1rem; color: #999; transition: transform 0.15s ease; }
.checklist-section-open .checklist-chevron { transform: rotate(180deg); }

.checklist-section-body {
    display: none;
    padding-bottom: 0.5rem;
}
.checklist-section-open .checklist-section-body { display: block; }

.checklist-mark-all {
    font-size: 0.78rem;
    color: var(--secondary-color);
    font-weight: 600;
    cursor: pointer;
    padding: 0.2rem;
    margin-bottom: 0.3rem;
}

.checklist-item-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.7rem 0.2rem;
    border-top: 1px solid #f0f1f2;
    gap: 0.75rem;
}
.checklist-item-label { font-size: 0.88rem; flex: 1; }
.checklist-toggle-group { display: flex; gap: 0.4rem; flex-shrink: 0; }
.checklist-chip {
    min-width: 44px;
    min-height: 40px;
    border-radius: var(--border-radius);
    border: 1.5px solid var(--border-color);
    font-size: 0.8rem;
    font-weight: 700;
    color: #999;
    background: #fafafa;
    cursor: pointer;
}
.checklist-chip-selected-ok { background: var(--success-color); border-color: var(--success-color); color: white; }
.checklist-chip-selected-na { background: #eee; border-color: #bbb; color: #666; }

/* Signature pad */
.sig-pad-wrap { border: 1.5px solid var(--border-color); border-radius: var(--border-radius); background: #fff; }
.sig-pad-wrap canvas { width: 100%; height: 140px; touch-action: none; display: block; }
.sig-pad-actions { margin-top: 0.4rem; }

/* Tech sheet form/header grid */
.tech-sheet-header-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.5rem 1.5rem;
    margin-bottom: 1rem;
}
@media (max-width: 600px) {
    .tech-sheet-header-grid { grid-template-columns: 1fr; }
}
</style>
```

(Note: the final `</style>` line above is only a marker for where this block ends if `calculator.css` is ever inlined elsewhere — since this is a real `.css` file, do not include a `</style>` tag; just append the CSS rules above it as plain CSS.)

- [ ] **Step 2: Manually verify**

Re-open the throwaway checklist test page from Task 6 with `<link rel="stylesheet" href="css/calculator.css">` added to its `<head>`, confirm the accordion now matches the approved Mockup A screenshot (sticky progress bar, section headers, green OK chips, grey N/A chips).

- [ ] **Step 3: Commit**

```bash
git add wcma-calculator/css/calculator.css
git commit -m "feat: add tech sheet checklist and signature pad styles"
```

---

### Task 8: Shared tech sheet HTML renderer

**Files:**
- Create: `wcma-calculator/tech-sheet-render.php`
- Test: `wcma-calculator/tests/TechSheetRenderTest.php`

**Interfaces:**
- Consumes: `TECH_CHECKLIST_SECTIONS`, `TECH_DRIVER_EQUIPMENT_ITEMS` (Task 1); `h()` (existing `view_helpers.php`)
- Produces: `renderTechSheetHtml(array $sheet, array $drivers, array $event): string` — full standalone HTML fragment (not a full `<html>` document; callers wrap it), consumed by Task 9 (view/print/email) and by the follow-up admin/review plan.

- [ ] **Step 1: Write the failing test**

```php
<?php
// wcma-calculator/tests/TechSheetRenderTest.php
use PHPUnit\Framework\TestCase;

final class TechSheetRenderTest extends TestCase
{
    private function sampleSheet(): array {
        require_once __DIR__ . '/../tech-sheet-data.php';
        $checklist = emptyChecklist();
        foreach ($checklist as $key => $v) { $checklist[$key] = ['status' => 'ok']; }
        $equipment = emptyDriverEquipment();
        foreach ($equipment as $key => $v) { $equipment[$key]['competitor_confirmed'] = true; }
        $equipment['helmet']['value'] = 'SA2020';
        $equipment['suit']['value'] = 'SFI 3.2A/5';

        return [
            'id' => 1, 'sheet_type' => 'standard', 'entrant_name' => 'Jane Racer', 'driver_name' => 'Jane Racer',
            'car_make' => 'Mazda', 'car_model' => 'MX-5', 'car_colour' => 'Red', 'car_number' => '42',
            'class' => 'IT1', 'engine_cc' => '1800', 'engine_hp' => '150', 'car_weight' => 2200,
            'checklist_json' => json_encode($checklist), 'driver1_equipment_json' => json_encode($equipment),
            'log_book_turned_in' => 1, 'entrant_signature_path' => null, 'driver_signature_path' => null,
            'tech_signature_path' => null, 'status' => 'submitted',
        ];
    }

    public function testRenderIncludesHeaderFields(): void
    {
        require_once __DIR__ . '/../tech-sheet-render.php';
        $html = renderTechSheetHtml($this->sampleSheet(), [], ['name' => 'Spring Sprint', 'event_date' => '2026-05-10']);
        $this->assertStringContainsString('Jane Racer', $html);
        $this->assertStringContainsString('Mazda', $html);
        $this->assertStringContainsString('MX-5', $html);
        $this->assertStringContainsString('IT1', $html);
        $this->assertStringContainsString('Spring Sprint', $html);
    }

    public function testRenderIncludesEveryChecklistItemLabel(): void
    {
        require_once __DIR__ . '/../tech-sheet-render.php';
        $html = renderTechSheetHtml($this->sampleSheet(), [], ['name' => 'Spring Sprint', 'event_date' => '2026-05-10']);
        foreach (TECH_CHECKLIST_SECTIONS as $section) {
            foreach ($section['items'] as $key => $label) {
                $this->assertStringContainsString(htmlspecialchars($label), $html);
            }
        }
    }

    public function testRenderIncludesDriverEquipmentRatings(): void
    {
        require_once __DIR__ . '/../tech-sheet-render.php';
        $html = renderTechSheetHtml($this->sampleSheet(), [], ['name' => 'Spring Sprint', 'event_date' => '2026-05-10']);
        $this->assertStringContainsString('SA2020', $html);
        $this->assertStringContainsString('SFI 3.2A/5', $html);
    }

    public function testRenderIncludesAdditionalDriversForEndurance(): void
    {
        require_once __DIR__ . '/../tech-sheet-render.php';
        $sheet = $this->sampleSheet();
        $sheet['sheet_type'] = 'endurance';
        $drivers = [
            ['driver_number' => 2, 'driver_name' => 'Co-Driver A', 'equipment_json' => json_encode(emptyDriverEquipment())],
        ];
        $html = renderTechSheetHtml($sheet, $drivers, ['name' => 'Spring Sprint', 'event_date' => '2026-05-10']);
        $this->assertStringContainsString('Co-Driver A', $html);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `phpunit.phar --filter TechSheetRenderTest`
Expected: FAIL — `tech-sheet-render.php` does not exist yet.

- [ ] **Step 3: Write the implementation**

```php
<?php
// wcma-calculator/tech-sheet-render.php
require_once __DIR__ . '/tech-sheet-data.php';

function techSheetSignatureImg(?string $path): string {
    if (!$path) return '<span style="color:#999">Not signed</span>';
    return '<img src="' . h($path) . '" alt="Signature" style="max-height:60px;border-bottom:1px solid #333">';
}

function techSheetEquipmentTable(array $equipment, bool $showTechColumn): string {
    $out = '<table cellpadding="4" style="border-collapse:collapse;width:100%;font-size:0.85rem">';
    $out .= '<tr style="background:#f0f1f2"><th style="text-align:left;border:1px solid #ccc;padding:4px">Item</th>';
    $out .= '<th style="border:1px solid #ccc;padding:4px">Confirmed</th>';
    if ($showTechColumn) $out .= '<th style="border:1px solid #ccc;padding:4px">Tech Rep Approved</th>';
    $out .= '</tr>';
    foreach (TECH_DRIVER_EQUIPMENT_ITEMS as $key => $def) {
        $item = $equipment[$key] ?? ['competitor_confirmed' => false, 'value' => null, 'tech_approved' => null];
        $label = h($def['label']);
        if ($def['has_rating'] && !empty($item['value'])) {
            $label .= ' — <strong>' . h((string)$item['value']) . '</strong>';
        }
        $confirmed = !empty($item['competitor_confirmed']) ? '✓' : '—';
        $out .= '<tr><td style="border:1px solid #ccc;padding:4px">' . $label . '</td>';
        $out .= '<td style="text-align:center;border:1px solid #ccc;padding:4px">' . $confirmed . '</td>';
        if ($showTechColumn) {
            $techVal = $item['tech_approved'] === null ? '—' : ($item['tech_approved'] ? '✓' : '✗');
            $out .= '<td style="text-align:center;border:1px solid #ccc;padding:4px">' . $techVal . '</td>';
        }
        $out .= '</tr>';
    }
    $out .= '</table>';
    return $out;
}

function renderTechSheetHtml(array $sheet, array $drivers, array $event): string {
    $checklist = json_decode($sheet['checklist_json'] ?? '{}', true) ?: [];
    $equipment = json_decode($sheet['driver1_equipment_json'] ?? '{}', true) ?: [];
    $showTechColumn = ($sheet['status'] ?? 'submitted') === 'teched';

    $out = '<div style="font-family:Arial,sans-serif;color:#222;max-width:800px">';
    $out .= '<h1 style="text-align:center;margin-bottom:0.2rem">VEHICLE INSPECTION FORM</h1>';
    $out .= '<p style="text-align:center;color:#555;font-size:0.85rem">' . h($event['name'] ?? '') . ' — ' . h(date('F j, Y', strtotime($event['event_date'] ?? 'now'))) . '</p>';

    $out .= '<table cellpadding="4" style="width:100%;border-collapse:collapse;margin:1rem 0">';
    $out .= '<tr><td style="width:50%"><strong>Entrant:</strong> ' . h($sheet['entrant_name']) . '</td><td><strong>Driver/Team:</strong> ' . h($sheet['driver_name']) . '</td></tr>';
    $out .= '<tr><td><strong>Car Make:</strong> ' . h($sheet['car_make']) . '</td><td><strong>Car Number:</strong> ' . h($sheet['car_number']) . '</td></tr>';
    $out .= '<tr><td><strong>Car Model:</strong> ' . h($sheet['car_model']) . '</td><td><strong>Class:</strong> ' . h($sheet['class']) . '</td></tr>';
    $out .= '<tr><td><strong>Car Colour:</strong> ' . h($sheet['car_colour']) . '</td><td><strong>Engine:</strong> ' . h((string)($sheet['engine_cc'] ?? '')) . ' CC / ' . h((string)($sheet['engine_hp'] ?? '')) . ' HP</td></tr>';
    $out .= '<tr><td><strong>Car Weight:</strong> ' . h((string)$sheet['car_weight']) . ' lbs</td><td></td></tr>';
    $out .= '</table>';

    $out .= '<h2 style="border-bottom:2px solid #2c3e50;padding-bottom:4px">Vehicle Checklist</h2>';
    foreach (TECH_CHECKLIST_SECTIONS as $section) {
        $out .= '<h3 style="margin-bottom:2px">' . h($section['label']) . '</h3>';
        $out .= '<table cellpadding="4" style="border-collapse:collapse;width:100%;font-size:0.85rem;margin-bottom:0.8rem">';
        foreach ($section['items'] as $key => $label) {
            $status = $checklist[$key]['status'] ?? null;
            $display = $status === 'ok' ? 'OK' : ($status === 'na' ? 'N/A' : '—');
            $out .= '<tr><td style="border:1px solid #ccc;padding:4px">' . h($label) . '</td>';
            $out .= '<td style="text-align:center;border:1px solid #ccc;padding:4px;width:60px">' . $display . '</td></tr>';
        }
        $out .= '</table>';
    }

    $out .= '<h2 style="border-bottom:2px solid #2c3e50;padding-bottom:4px">Driver Safety Equipment — ' . h($sheet['driver_name']) . '</h2>';
    $out .= techSheetEquipmentTable($equipment, $showTechColumn);

    if (($sheet['sheet_type'] ?? 'standard') === 'endurance' && !empty($drivers)) {
        foreach ($drivers as $d) {
            $driverEquipment = json_decode($d['equipment_json'] ?? '{}', true) ?: [];
            $out .= '<h2 style="border-bottom:2px solid #2c3e50;padding-bottom:4px">Driver ' . (int)$d['driver_number'] . ' — ' . h($d['driver_name']) . '</h2>';
            $out .= techSheetEquipmentTable($driverEquipment, $showTechColumn);
        }
    }

    $out .= '<h2 style="border-bottom:2px solid #2c3e50;padding-bottom:4px">Declaration</h2>';
    $out .= '<p><em>I hereby stipulate that the above vehicle meets the regulations for the event.</em></p>';
    $out .= '<table cellpadding="8" style="width:100%"><tr>';
    $out .= '<td style="width:33%"><div>' . techSheetSignatureImg($sheet['entrant_signature_path'] ?? null) . '</div><p style="font-size:0.8rem">Entrant\'s Signature</p></td>';
    $out .= '<td style="width:33%"><div>' . techSheetSignatureImg($sheet['driver_signature_path'] ?? null) . '</div><p style="font-size:0.8rem">Driver\'s Signature</p></td>';
    $out .= '<td style="width:33%"><div>' . techSheetSignatureImg($sheet['tech_signature_path'] ?? null) . '</div><p style="font-size:0.8rem">Tech Representative\'s Signature</p></td>';
    $out .= '</tr></table>';
    $out .= '<p>Vehicle Log Book Turned In: <strong>' . (($sheet['log_book_turned_in'] ?? null) === null ? '—' : ((int)$sheet['log_book_turned_in'] === 1 ? 'Yes' : 'No')) . '</strong></p>';
    $out .= '<p style="font-weight:bold;color:' . (($sheet['status'] ?? 'submitted') === 'teched' ? '#27ae60' : '#f39c12') . '">Status: ' . h(($sheet['status'] ?? 'submitted') === 'teched' ? 'Reviewed' : 'Submitted — awaiting review') . '</p>';
    $out .= '</div>';

    return $out;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `phpunit.phar --filter TechSheetRenderTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/tech-sheet-render.php wcma-calculator/tests/TechSheetRenderTest.php
git commit -m "feat: add shared tech sheet HTML renderer"
```

---

### Task 9: Competitor "Submit Tech Sheet" flow — new/submit

**Files:**
- Create: `wcma-calculator/tech-sheets.php`

**Interfaces:**
- Consumes: `db_get_active_events`, `db_get_event` (Task 2); `db_get_user_submission` (existing); `db_insert_tech_sheet`, `db_update_tech_sheet_signatures`, `db_replace_tech_sheet_drivers`, `db_update_email_sent_tech_sheet` (Task 3); `validateChecklist`, `validateDriverEquipment`, `TECH_CHECKLIST_SECTIONS`, `TECH_DRIVER_EQUIPMENT_ITEMS`, `emptyChecklist`, `emptyDriverEquipment` (Task 1); `renderTechSheetHtml` (Task 8); `js/tech-sheet-checklist.js` (Task 6), `js/signature-pad.js` (Task 5); `requireLogin()`-equivalent gate (mirrors `account.php`'s `requireLogin()`); `buildAccountMailer()`-equivalent PHPMailer setup
- Produces: `tech-sheets.php?action=new&submission_id=N` (GET form), `tech-sheets.php?action=submit` (POST handler) — consumed by Task 11 (the "Submit Tech Sheet" link on My Cars)

- [ ] **Step 1: Scaffold the router and shared setup**

```php
<?php
// wcma-calculator/tech-sheets.php
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/tech-sheet-data.php';
require __DIR__ . '/tech-sheet-render.php';

require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('America/Denver');

const TECH_SHEET_EMAIL = 'classing@wcma.ca';
const TECH_SHEET_EMAIL_NAME = 'WCMA Classing';

$pdo = db_connect();
db_init($pdo);

function requireTechSheetLogin(): array {
    $user = current_user();
    if ($user === null) {
        header('Location: auth.php?action=login');
        exit;
    }
    return $user;
}

$action = $_GET['action'] ?? 'new';

switch ($action) {
    case 'new':
        $user = requireTechSheetLogin();
        handleNew($pdo, $user, (int)($_GET['submission_id'] ?? 0));
        break;

    case 'submit':
        $user = requireTechSheetLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: tech-sheets.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleSubmit($pdo, $user);
        break;

    default:
        header('Location: account.php');
        exit;
}
```

- [ ] **Step 2: Write `handleNew()` and the form renderer**

Append to `wcma-calculator/tech-sheets.php`:

```php
function handleNew(PDO $pdo, array $user, int $submissionId): void {
    $submission = db_get_user_submission($pdo, $user['id'], $submissionId);
    if (!$submission) {
        setFlash('Car not found.', 'error');
        header('Location: account.php');
        exit;
    }

    $events = db_get_active_events($pdo);
    if (empty($events)) {
        setFlash('There are no upcoming events open for tech sheet submission yet.', 'error');
        header('Location: account.php');
        exit;
    }

    $csrf = generateCsrfToken();
    renderTechSheetForm($submission, $events, $csrf);
}

function renderTechSheetForm(array $submission, array $events, string $csrf): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Submit Tech Sheet — WCMA Calculator</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
<meta name="csrf-token" content="<?= h($csrf) ?>">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Submit Tech Sheet', '<a href="account.php">← Back to My Cars</a>' . renderCommonNav('account')); ?>

  <form id="tech-sheet-form" method="post" action="tech-sheets.php?action=submit">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="submission_id" value="<?= (int)$submission['id'] ?>">
    <input type="hidden" name="checklist_json" id="checklist_json">
    <input type="hidden" name="driver1_equipment_json" id="driver1_equipment_json">
    <input type="hidden" name="drivers_json" id="drivers_json">
    <input type="hidden" name="entrant_signature" id="entrant_signature">
    <input type="hidden" name="driver_signature" id="driver_signature">

    <div class="detail-card">
      <h2>Event &amp; Sheet Type</h2>
      <label for="event_id">Event</label>
      <select id="event_id" name="event_id" required>
        <?php foreach ($events as $e): ?>
        <option value="<?= (int)$e['id'] ?>"><?= h($e['name']) ?> — <?= h(date('M j, Y', strtotime($e['event_date']))) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="sheet_type">Sheet Type</label>
      <select id="sheet_type" name="sheet_type">
        <option value="standard">Standard</option>
        <option value="endurance">Endurance (multiple drivers)</option>
      </select>
    </div>

    <div class="detail-card">
      <h2>Vehicle &amp; Entrant</h2>
      <div class="tech-sheet-header-grid">
        <div><label for="entrant_name">Entrant</label><input type="text" id="entrant_name" name="entrant_name" required value="<?= h($submission['name']) ?>"></div>
        <div><label for="driver_name">Driver/Team Name</label><input type="text" id="driver_name" name="driver_name" required value="<?= h($submission['name']) ?>"></div>
        <div><label for="car_number">Car Number</label><input type="text" id="car_number" name="car_number" required></div>
        <div><label for="car_colour">Car Colour</label><input type="text" id="car_colour" name="car_colour" required></div>
        <div><label for="engine_cc">Engine CC</label><input type="text" id="engine_cc" name="engine_cc"></div>
        <div><label for="engine_hp">Engine HP</label><input type="text" id="engine_hp" name="engine_hp" value="<?= h((string)($submission['dyno_hp'] ?: $submission['declared_hp'])) ?>"></div>
      </div>
      <input type="hidden" name="car_make" value="<?= h($submission['make']) ?>">
      <input type="hidden" name="car_model" value="<?= h($submission['model']) ?>">
      <input type="hidden" name="class" value="<?= h($submission['calculated_class'] ?? '') ?>">
      <input type="hidden" name="car_weight" value="<?= (int)$submission['competition_weight'] ?>">
    </div>

    <div class="detail-card">
      <h2>Vehicle Checklist</h2>
      <div id="checklist-container"></div>
    </div>

    <div class="detail-card">
      <h2>Driver Safety Equipment — Driver 1</h2>
      <div id="equipment-container"></div>
    </div>

    <div class="detail-card" id="endurance-drivers-card" hidden>
      <h2>Additional Drivers</h2>
      <div id="additional-drivers-container"></div>
      <button type="button" class="btn btn-secondary" id="add-driver-btn">+ Add Driver</button>
    </div>

    <div class="detail-card">
      <h2>Log Book</h2>
      <label class="checkbox-label"><input type="radio" name="log_book_turned_in" value="1" required> Yes</label>
      <label class="checkbox-label"><input type="radio" name="log_book_turned_in" value="0"> No</label>
    </div>

    <div class="detail-card">
      <h2>Declaration &amp; Signatures</h2>
      <p><em>I hereby stipulate that the above vehicle meets the regulations for the event.</em></p>
      <label>Entrant's Signature</label>
      <div class="sig-pad-wrap"><canvas id="entrant-sig-canvas"></canvas></div>
      <div class="sig-pad-actions"><button type="button" class="link-button" data-clear-sig="entrant">Clear</button></div>
      <label>Driver's Signature</label>
      <div class="sig-pad-wrap"><canvas id="driver-sig-canvas"></canvas></div>
      <div class="sig-pad-actions"><button type="button" class="link-button" data-clear-sig="driver">Clear</button></div>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary" id="tech-sheet-submit-btn">Submit Tech Sheet</button>
    </div>
    <div id="tech-sheet-error" class="form-messages error" hidden></div>
  </form>
</div>
<script>
  const TECH_CHECKLIST_SECTIONS = <?= json_encode(TECH_CHECKLIST_SECTIONS) ?>;
  const TECH_DRIVER_EQUIPMENT_ITEMS = <?= json_encode(TECH_DRIVER_EQUIPMENT_ITEMS) ?>;
</script>
<script src="js/tech-sheet-checklist.js"></script>
<script src="js/signature-pad.js"></script>
<script src="js/tech-sheet-form.js"></script>
</body>
</html><?php
}
```

- [ ] **Step 3: Write `js/tech-sheet-form.js`** (new file — glue between the accordion/signature widgets and the form's hidden inputs, plus the equipment/additional-driver rows and client-side completeness gate)

```javascript
// wcma-calculator/js/tech-sheet-form.js
(function () {
    const checklistWidget = WcmaTechChecklist.render(
        document.getElementById('checklist-container'), TECH_CHECKLIST_SECTIONS, {}
    );

    function renderEquipmentInto(container, prefix) {
        const state = {};
        Object.keys(TECH_DRIVER_EQUIPMENT_ITEMS).forEach(function (key) {
            state[key] = { competitor_confirmed: false, value: null, tech_approved: null };
            const def = TECH_DRIVER_EQUIPMENT_ITEMS[key];
            const row = document.createElement('div');
            row.className = 'checklist-item-row';
            const label = document.createElement('div');
            label.className = 'checklist-item-label';
            label.textContent = def.label;
            row.appendChild(label);

            if (def.has_rating) {
                const input = document.createElement('input');
                input.type = 'text';
                input.placeholder = 'Rating (e.g. SA2020)';
                input.style.marginRight = '0.5rem';
                input.addEventListener('input', function () {
                    state[key].value = input.value;
                    state[key].competitor_confirmed = input.value.trim() !== '';
                });
                row.appendChild(input);
            } else {
                const confirmBtn = document.createElement('button');
                confirmBtn.type = 'button';
                confirmBtn.className = 'checklist-chip';
                confirmBtn.textContent = 'Confirm';
                confirmBtn.addEventListener('click', function () {
                    state[key].competitor_confirmed = !state[key].competitor_confirmed;
                    confirmBtn.classList.toggle('checklist-chip-selected-ok', state[key].competitor_confirmed);
                });
                row.appendChild(confirmBtn);
            }
            container.appendChild(row);
        });
        return state;
    }

    const driver1State = renderEquipmentInto(document.getElementById('equipment-container'), 'driver1');

    const entrantPad = WcmaSignaturePad.attach(document.getElementById('entrant-sig-canvas'));
    const driverPad = WcmaSignaturePad.attach(document.getElementById('driver-sig-canvas'));
    document.querySelectorAll('[data-clear-sig]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            (btn.getAttribute('data-clear-sig') === 'entrant' ? entrantPad : driverPad).clear();
        });
    });

    const sheetTypeSelect = document.getElementById('sheet_type');
    const enduranceCard = document.getElementById('endurance-drivers-card');
    const additionalDriversContainer = document.getElementById('additional-drivers-container');
    const additionalDrivers = []; // [{number, nameInput, state}]

    sheetTypeSelect.addEventListener('change', function () {
        enduranceCard.hidden = sheetTypeSelect.value !== 'endurance';
    });

    document.getElementById('add-driver-btn').addEventListener('click', function () {
        if (additionalDrivers.length >= 6) return; // drivers 2-7
        const number = additionalDrivers.length + 2;
        const wrap = document.createElement('div');
        wrap.style.marginBottom = '1rem';
        const nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.placeholder = 'Driver ' + number + ' Name';
        nameInput.required = true;
        wrap.appendChild(nameInput);
        const equipContainer = document.createElement('div');
        wrap.appendChild(equipContainer);
        additionalDriversContainer.appendChild(wrap);
        const state = renderEquipmentInto(equipContainer, 'driver' + number);
        additionalDrivers.push({ number: number, nameInput: nameInput, state: state });
    });

    document.getElementById('tech-sheet-form').addEventListener('submit', function (e) {
        const errorEl = document.getElementById('tech-sheet-error');
        errorEl.hidden = true;

        if (!checklistWidget.isComplete()) {
            e.preventDefault();
            errorEl.textContent = 'Please mark every checklist item OK or N/A before submitting.';
            errorEl.hidden = false;
            errorEl.classList.add('show');
            return;
        }
        if (entrantPad.isEmpty() || driverPad.isEmpty()) {
            e.preventDefault();
            errorEl.textContent = 'Both the entrant and driver signatures are required.';
            errorEl.hidden = false;
            errorEl.classList.add('show');
            return;
        }

        document.getElementById('checklist_json').value = JSON.stringify(checklistWidget.getState());
        document.getElementById('driver1_equipment_json').value = JSON.stringify(driver1State);
        document.getElementById('entrant_signature').value = entrantPad.toPNGDataURL();
        document.getElementById('driver_signature').value = driverPad.toPNGDataURL();
        document.getElementById('drivers_json').value = JSON.stringify(additionalDrivers.map(function (d) {
            return { driver_number: d.number, driver_name: d.nameInput.value, equipment: d.state };
        }));
    });
})();
```

- [ ] **Step 4: Write `handleSubmit()`**

Append to `wcma-calculator/tech-sheets.php`:

```php
function saveSignatureFile(int $techSheetId, string $field, string $dataUrl): ?string {
    if (strpos($dataUrl, 'data:image/png;base64,') !== 0) return null;
    $binary = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')));
    if ($binary === false) return null;

    $dir = __DIR__ . '/uploads/tech-sheets/' . $techSheetId;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $relative = 'uploads/tech-sheets/' . $techSheetId . '/' . $field . '.png';
    file_put_contents(__DIR__ . '/' . $relative, $binary);
    return $relative;
}

function buildTechSheetMailer(): PHPMailer {
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

function handleSubmit(PDO $pdo, array $user): void {
    $submissionId = (int)($_POST['submission_id'] ?? 0);
    $submission = db_get_user_submission($pdo, $user['id'], $submissionId);
    if (!$submission) {
        setFlash('Car not found.', 'error');
        header('Location: account.php');
        exit;
    }

    $eventId = (int)($_POST['event_id'] ?? 0);
    $event = db_get_event($pdo, $eventId);
    if (!$event || (int)$event['active'] !== 1) {
        setFlash('Please choose a valid event.', 'error');
        header('Location: tech-sheets.php?action=new&submission_id=' . $submissionId);
        exit;
    }

    $sheetType = ($_POST['sheet_type'] ?? 'standard') === 'endurance' ? 'endurance' : 'standard';
    $checklist = json_decode($_POST['checklist_json'] ?? '{}', true) ?: [];
    $equipment = json_decode($_POST['driver1_equipment_json'] ?? '{}', true) ?: [];
    $driversInput = json_decode($_POST['drivers_json'] ?? '[]', true) ?: [];
    $entrantName = trim($_POST['entrant_name'] ?? '');
    $driverName = trim($_POST['driver_name'] ?? '');
    $carNumber = trim($_POST['car_number'] ?? '');
    $carColour = trim($_POST['car_colour'] ?? '');
    $logBook = $_POST['log_book_turned_in'] ?? null;

    if (!validateChecklist($checklist) || !validateDriverEquipment($equipment)
        || $entrantName === '' || $driverName === '' || $carNumber === '' || $carColour === ''
        || !in_array($logBook, ['0', '1'], true)) {
        setFlash('Please complete every required field before submitting.', 'error');
        header('Location: tech-sheets.php?action=new&submission_id=' . $submissionId);
        exit;
    }

    $id = db_insert_tech_sheet($pdo, [
        'submission_id' => $submission['id'], 'user_id' => $user['id'], 'event_id' => $eventId, 'sheet_type' => $sheetType,
        'entrant_name' => $entrantName, 'driver_name' => $driverName,
        'car_make' => $submission['make'], 'car_model' => $submission['model'], 'car_colour' => $carColour,
        'car_number' => $carNumber, 'class' => $submission['calculated_class'] ?? '',
        'engine_cc' => trim($_POST['engine_cc'] ?? '') ?: null, 'engine_hp' => trim($_POST['engine_hp'] ?? '') ?: null,
        'car_weight' => (int)$submission['competition_weight'],
        'checklist_json' => json_encode($checklist), 'driver1_equipment_json' => json_encode($equipment),
        'log_book_turned_in' => (int)$logBook,
    ]);

    $sigPaths = [];
    if (!empty($_POST['entrant_signature'])) {
        $sigPaths['entrant_signature_path'] = saveSignatureFile($id, 'entrant', $_POST['entrant_signature']);
    }
    if (!empty($_POST['driver_signature'])) {
        $sigPaths['driver_signature_path'] = saveSignatureFile($id, 'driver', $_POST['driver_signature']);
    }
    if (!empty($sigPaths)) {
        db_update_tech_sheet_signatures($pdo, $id, $sigPaths);
    }

    if ($sheetType === 'endurance' && !empty($driversInput)) {
        $driverRows = [];
        foreach ($driversInput as $d) {
            $driverRows[] = [
                'driver_number' => (int)($d['driver_number'] ?? 0),
                'driver_name' => trim($d['driver_name'] ?? ''),
                'equipment_json' => json_encode($d['equipment'] ?? []),
            ];
        }
        db_replace_tech_sheet_drivers($pdo, $id, $driverRows);
    }

    $sheet = db_get_tech_sheet($pdo, $id);
    $drivers = db_get_tech_sheet_drivers($pdo, $id);
    $bodyHtml = '<html><body>' . renderTechSheetHtml($sheet, $drivers, $event) . '</body></html>';
    $subject = 'WCMA Tech Sheet — ' . $entrantName . ' — ' . $event['name'];

    $sent = false;
    try {
        $mail = buildTechSheetMailer();
        $mail->addAddress($user['email'] ?? $submission['email'], $entrantName);
        $mail->addAddress(TECH_SHEET_EMAIL, TECH_SHEET_EMAIL_NAME);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $bodyHtml;
        $mail->AltBody = 'Your tech sheet for ' . $event['name'] . ' has been submitted. View it online at tech-sheets.php?action=view&id=' . $id;
        $mail->send();
        $sent = true;
    } catch (Exception $e) {
        error_log('Tech sheet submit email error: ' . $e->getMessage());
    }
    db_update_email_sent_tech_sheet($pdo, $id, $sent ? 1 : 0);

    setFlash('Tech sheet submitted' . ($sent ? ' and emailed to you and the club.' : ', but the confirmation email failed to send.'), $sent ? 'success' : 'error');
    header('Location: tech-sheets.php?action=view&id=' . $id);
    exit;
}
```

- [ ] **Step 5: Manually verify**

Start the app locally, create a test user with at least one classed submission, create an active event via `admin.php?action=events`. Visit `tech-sheets.php?action=new&submission_id=<id>`, confirm the form pre-fills entrant/driver name and hidden make/model/class/weight fields. Try submitting with checklist items left blank — confirm the client-side error shows and no request is sent. Complete the checklist via "Mark all OK" per section, fill helmet/suit ratings, confirm all other equipment items, draw both signatures, submit. Confirm redirect to `tech-sheets.php?action=view` works once Task 10 exists (if run before Task 10, confirm instead that a 404/undefined-action response is the only failure, and that the `tech_sheets` row and `uploads/tech-sheets/<id>/entrant.png` / `driver.png` files were created correctly by inspecting the SQLite DB and filesystem directly). Confirm an email arrives (or check `error_log` for the PHPMailer error if SMTP isn't configured locally).

- [ ] **Step 6: Commit**

```bash
git add wcma-calculator/tech-sheets.php wcma-calculator/js/tech-sheet-form.js
git commit -m "feat: add competitor tech sheet submit flow"
```

---

### Task 10: Competitor tech sheet view/print, edit, and resend

**Files:**
- Modify: `wcma-calculator/tech-sheets.php`

**Interfaces:**
- Consumes: `db_get_user_tech_sheet`, `db_get_tech_sheet_drivers`, `db_update_tech_sheet`, `db_replace_tech_sheet_drivers`, `db_update_tech_sheet_signatures`, `db_update_email_sent_tech_sheet` (Task 3); `renderTechSheetHtml` (Task 8); `db_get_event` (Task 2)

- [ ] **Step 1: Add routes**

In `tech-sheets.php`'s `switch ($action)`, add before `default`:

```php
    case 'view':
        $user = requireTechSheetLogin();
        handleView($pdo, $user, (int)($_GET['id'] ?? 0));
        break;

    case 'edit':
        $user = requireTechSheetLogin();
        handleEdit($pdo, $user, (int)($_GET['id'] ?? 0));
        break;

    case 'resend':
        $user = requireTechSheetLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: account.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleResendTechSheet($pdo, $user, (int)($_POST['id'] ?? 0));
        break;
```

- [ ] **Step 2: Write `handleView()`**

```php
function handleView(PDO $pdo, array $user, int $id): void {
    $sheet = db_get_user_tech_sheet($pdo, $user['id'], $id);
    if (!$sheet) {
        setFlash('Tech sheet not found.', 'error');
        header('Location: account.php');
        exit;
    }
    $event = db_get_event($pdo, (int)$sheet['event_id']);
    $drivers = db_get_tech_sheet_drivers($pdo, $id);
    $csrf = generateCsrfToken();
    $flash = getFlash();
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tech Sheet #<?= (int)$sheet['id'] ?> — WCMA Calculator</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Tech Sheet #' . $sheet['id'], '<a href="account.php">← Back to My Cars</a>' . renderCommonNav('account')); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
  <div class="detail-card actions no-print">
    <?php if ($sheet['status'] === 'submitted'): ?>
    <a href="tech-sheets.php?action=edit&id=<?= (int)$sheet['id'] ?>" class="btn btn-secondary">Edit</a>
    <?php endif; ?>
    <form method="post" action="tech-sheets.php?action=resend" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= (int)$sheet['id'] ?>">
      <button type="submit" class="btn btn-primary">Resend Email</button>
    </form>
    <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
  </div>
  <?= renderTechSheetHtml($sheet, $drivers, $event ?? []) ?>
</div>
<script src="js/form-feedback.js"></script>
</body>
</html><?php
}
```

- [ ] **Step 3: Write `handleEdit()`** (re-renders the same form as Task 9 but pre-filled, and posts to a new `update` action)

```php
function handleEdit(PDO $pdo, array $user, int $id): void {
    $sheet = db_get_user_tech_sheet($pdo, $user['id'], $id);
    if (!$sheet) {
        setFlash('Tech sheet not found.', 'error');
        header('Location: account.php');
        exit;
    }
    if ($sheet['status'] !== 'submitted') {
        setFlash('This tech sheet has already been reviewed and can no longer be edited.', 'error');
        header('Location: tech-sheets.php?action=view&id=' . $id);
        exit;
    }

    $events = db_get_active_events($pdo);
    $drivers = db_get_tech_sheet_drivers($pdo, $id);
    $csrf = generateCsrfToken();
    renderTechSheetEditForm($sheet, $drivers, $events, $csrf);
}
```

Since the edit form is the same shape as the new-sheet form but pre-filled and posting to an `update` action, factor the shared markup: rename Task 9's `renderTechSheetForm(array $submission, array $events, string $csrf)` to accept an optional `?array $existingSheet = null, array $existingDrivers = []` and branch the `<form action>` and pre-filled values accordingly, rather than duplicating the whole template. Concretely, change its signature and the top of its body to:

```php
function renderTechSheetForm(array $submission, array $events, string $csrf, ?array $existingSheet = null, array $existingDrivers = []): void {
    $isEdit = $existingSheet !== null;
    $formAction = $isEdit ? 'tech-sheets.php?action=update' : 'tech-sheets.php?action=submit';
    $entrantName = $isEdit ? $existingSheet['entrant_name'] : $submission['name'];
    $driverName = $isEdit ? $existingSheet['driver_name'] : $submission['name'];
    $carNumber = $isEdit ? $existingSheet['car_number'] : '';
    $carColour = $isEdit ? $existingSheet['car_colour'] : '';
    $engineCc = $isEdit ? $existingSheet['engine_cc'] : '';
    $engineHp = $isEdit ? $existingSheet['engine_hp'] : ($submission['dyno_hp'] ?: $submission['declared_hp']);
    $selectedEventId = $isEdit ? (int)$existingSheet['event_id'] : null;
    $selectedSheetType = $isEdit ? $existingSheet['sheet_type'] : 'standard';
    $existingChecklist = $isEdit ? json_decode($existingSheet['checklist_json'], true) : [];
    $existingEquipment = $isEdit ? json_decode($existingSheet['driver1_equipment_json'], true) : [];
    ?><!DOCTYPE html>
...
```

Then update the `<form>` tag to `action="<?= h($formAction) ?>"`, the value attributes for `entrant_name`/`driver_name`/`car_number`/`car_colour`/`engine_cc`/`engine_hp` to use the variables above, the `<option>` loop to mark `selected` when `$e['id'] == $selectedEventId`, the `sheet_type` `<option>`s similarly, add `<input type="hidden" name="tech_sheet_id" value="<?= $isEdit ? (int)$existingSheet['id'] : '' ?>">` when editing, and pass `$existingChecklist`/`$existingEquipment`/`$existingDrivers` into the embedded `<script>` JSON blobs (`TECH_SHEET_EXISTING_CHECKLIST`, `TECH_SHEET_EXISTING_EQUIPMENT`, `TECH_SHEET_EXISTING_DRIVERS`) so `js/tech-sheet-form.js` seeds `WcmaTechChecklist.render(...)`'s `initialState` argument and pre-fills equipment/driver rows instead of starting blank. Update `js/tech-sheet-form.js`'s `WcmaTechChecklist.render(...)` call to `WcmaTechChecklist.render(container, TECH_CHECKLIST_SECTIONS, window.TECH_SHEET_EXISTING_CHECKLIST || {})`, and update `renderEquipmentInto` to accept an `existingState` parameter it merges into its local `state` before building the DOM, defaulting values from it.

Add `renderTechSheetEditForm()` as a thin wrapper:

```php
function renderTechSheetEditForm(array $sheet, array $drivers, array $events, string $csrf): void {
    renderTechSheetForm([], $events, $csrf, $sheet, $drivers);
}
```

- [ ] **Step 4: Write `handleUpdate()` and route it**

Add to the `switch`:

```php
    case 'update':
        $user = requireTechSheetLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: account.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleUpdate($pdo, $user);
        break;
```

```php
function handleUpdate(PDO $pdo, array $user): void {
    $id = (int)($_POST['tech_sheet_id'] ?? 0);
    $sheet = db_get_user_tech_sheet($pdo, $user['id'], $id);
    if (!$sheet || $sheet['status'] !== 'submitted') {
        setFlash('Tech sheet not found or no longer editable.', 'error');
        header('Location: account.php');
        exit;
    }

    $eventId = (int)($_POST['event_id'] ?? 0);
    $event = db_get_event($pdo, $eventId);
    $sheetType = ($_POST['sheet_type'] ?? 'standard') === 'endurance' ? 'endurance' : 'standard';
    $checklist = json_decode($_POST['checklist_json'] ?? '{}', true) ?: [];
    $equipment = json_decode($_POST['driver1_equipment_json'] ?? '{}', true) ?: [];
    $driversInput = json_decode($_POST['drivers_json'] ?? '[]', true) ?: [];
    $entrantName = trim($_POST['entrant_name'] ?? '');
    $driverName = trim($_POST['driver_name'] ?? '');
    $carNumber = trim($_POST['car_number'] ?? '');
    $carColour = trim($_POST['car_colour'] ?? '');
    $logBook = $_POST['log_book_turned_in'] ?? null;

    if (!$event || !validateChecklist($checklist) || !validateDriverEquipment($equipment)
        || $entrantName === '' || $driverName === '' || $carNumber === '' || $carColour === ''
        || !in_array($logBook, ['0', '1'], true)) {
        setFlash('Please complete every required field.', 'error');
        header('Location: tech-sheets.php?action=edit&id=' . $id);
        exit;
    }

    db_update_tech_sheet($pdo, $id, [
        'event_id' => $eventId, 'sheet_type' => $sheetType,
        'entrant_name' => $entrantName, 'driver_name' => $driverName,
        'car_make' => $sheet['car_make'], 'car_model' => $sheet['car_model'], 'car_colour' => $carColour,
        'car_number' => $carNumber, 'class' => $sheet['class'],
        'engine_cc' => trim($_POST['engine_cc'] ?? '') ?: null, 'engine_hp' => trim($_POST['engine_hp'] ?? '') ?: null,
        'car_weight' => (int)$sheet['car_weight'],
        'checklist_json' => json_encode($checklist), 'driver1_equipment_json' => json_encode($equipment),
        'log_book_turned_in' => (int)$logBook,
    ]);

    if (!empty($_POST['entrant_signature'])) {
        $path = saveSignatureFile($id, 'entrant', $_POST['entrant_signature']);
        if ($path) db_update_tech_sheet_signatures($pdo, $id, ['entrant_signature_path' => $path]);
    }
    if (!empty($_POST['driver_signature'])) {
        $path = saveSignatureFile($id, 'driver', $_POST['driver_signature']);
        if ($path) db_update_tech_sheet_signatures($pdo, $id, ['driver_signature_path' => $path]);
    }

    if ($sheetType === 'endurance') {
        $driverRows = [];
        foreach ($driversInput as $d) {
            $driverRows[] = [
                'driver_number' => (int)($d['driver_number'] ?? 0),
                'driver_name' => trim($d['driver_name'] ?? ''),
                'equipment_json' => json_encode($d['equipment'] ?? []),
            ];
        }
        db_replace_tech_sheet_drivers($pdo, $id, $driverRows);
    } else {
        db_replace_tech_sheet_drivers($pdo, $id, []);
    }

    setFlash('Tech sheet updated.', 'success');
    header('Location: tech-sheets.php?action=view&id=' . $id);
    exit;
}
```

Note: the edit flow does not require re-drawing signatures (the `if (!empty($_POST['entrant_signature']))` guards allow submitting the edit form with blank signature hidden inputs, keeping the previously-saved signature files); update `js/tech-sheet-form.js`'s submit handler so the "signatures required" check only applies when `entrantPad`/`driverPad` are both empty **and** there is no existing `entrant_signature_path`/`driver_signature_path` already on the sheet (pass that as two more `window.TECH_SHEET_EXISTING_*` booleans alongside the JSON blobs from Step 3).

- [ ] **Step 5: Write `handleResendTechSheet()`**

```php
function handleResendTechSheet(PDO $pdo, array $user, int $id): void {
    $sheet = db_get_user_tech_sheet($pdo, $user['id'], $id);
    if (!$sheet) {
        setFlash('Tech sheet not found.', 'error');
        header('Location: account.php');
        exit;
    }
    $event = db_get_event($pdo, (int)$sheet['event_id']);
    $drivers = db_get_tech_sheet_drivers($pdo, $id);
    $bodyHtml = '<html><body>' . renderTechSheetHtml($sheet, $drivers, $event ?? []) . '</body></html>';

    $sent = false;
    try {
        $mail = buildTechSheetMailer();
        $mail->addAddress($user['email'], $sheet['entrant_name']);
        $mail->addAddress(TECH_SHEET_EMAIL, TECH_SHEET_EMAIL_NAME);
        $mail->Subject = 'WCMA Tech Sheet — ' . $sheet['entrant_name'] . ' — ' . ($event['name'] ?? '');
        $mail->isHTML(true);
        $mail->Body = $bodyHtml;
        $mail->send();
        $sent = true;
    } catch (Exception $e) {
        error_log('Tech sheet resend error: ' . $e->getMessage());
    }
    db_update_email_sent_tech_sheet($pdo, $id, $sent ? 1 : 0);
    setFlash($sent ? 'Tech sheet email re-sent.' : 'Failed to re-send email.', $sent ? 'success' : 'error');
    header('Location: tech-sheets.php?action=view&id=' . $id);
    exit;
}
```

- [ ] **Step 6: Manually verify**

Submit a fresh tech sheet (Task 9's flow), confirm `tech-sheets.php?action=view&id=<id>` renders the full form-replica layout with both signatures visible, "Edit" and "Resend Email" buttons present, and Print produces a clean page (`window.print()` — confirm the `no-print` class hides the action buttons, matching the existing submission print-view pattern). Click Edit, change a checklist item and the car colour, save without re-signing, confirm the update persists and the previous signature images are still shown on the view page. Click Resend, confirm a second email is sent and `email_send_count` increments (check the DB directly). Manually set a sheet's `status` to `teched` in the DB and confirm the "Edit" button disappears from its view page and `tech-sheets.php?action=edit&id=<id>` redirects with the "already reviewed" flash message.

- [ ] **Step 7: Commit**

```bash
git add wcma-calculator/tech-sheets.php wcma-calculator/js/tech-sheet-form.js
git commit -m "feat: add tech sheet view/print, edit, and resend"
```

---

### Task 11: "Submit Tech Sheet" link and "My Tech Sheets" list on the account page

**Files:**
- Modify: `wcma-calculator/account.php`
- Modify: `wcma-calculator/db.php` (one more read function)

**Interfaces:**
- Consumes: `db_get_user_tech_sheets` (Task 3, already produced) plus a new `db_get_event` lookup per row (Task 2, already produced)

- [ ] **Step 1: Add a "Submit Tech Sheet" action link to each submission row**

In `account.php`'s `renderAccountListPage()`, inside the `<?php else: ?>` branch's submission `<tr>` (the block starting `<td>Submitted</td>`), change the actions `<td>`:

```php
        <td class="actions">
          <a href="account.php?action=view&id=<?= (int)$s['id'] ?>">View</a>
          <a href="tech-sheets.php?action=new&submission_id=<?= (int)$s['id'] ?>">Submit Tech Sheet</a>
          <form method="post" action="account.php?action=delete" style="display:inline"
                data-confirm="Permanently delete this submission and its files?">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button type="submit" class="link-button">Delete</button>
          </form>
        </td>
```

- [ ] **Step 2: Add a "My Tech Sheets" section below the My Cars table**

In `handleAccountList()`, fetch the user's tech sheets alongside drafts/submissions:

```php
function handleAccountList(PDO $pdo, array $user): void {
    $drafts = db_get_user_drafts($pdo, $user['id']);
    $submissions = db_get_user_submissions($pdo, $user['id']);
    $techSheets = db_get_user_tech_sheets($pdo, $user['id']);
    $totalCount = db_count_user_drafts($pdo, $user['id']) + db_count_user_submissions($pdo, $user['id']);

    $rows = [];
    foreach ($drafts as $d) {
        $rows[] = ['type' => 'draft', 'sort_key' => $d['updated_at'], 'data' => $d];
    }
    foreach ($submissions as $s) {
        $rows[] = ['type' => 'submission', 'sort_key' => $s['submitted_at'], 'data' => $s];
    }
    usort($rows, fn($a, $b) => strcmp($b['sort_key'], $a['sort_key']));

    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderAccountListPage($rows, $totalCount, $csrf, $flash, $techSheets, $pdo);
}
```

Update `renderAccountListPage()`'s signature and append a second table after the existing My Cars `</table>` and its `no-results-message` paragraph, before the closing `</div>`:

```php
function renderAccountListPage(array $rows, int $count, string $csrf, ?array $flash, array $techSheets = [], ?PDO $pdo = null): void {
```

```php
  <h2 style="margin-top:2rem">My Tech Sheets</h2>
  <?php if (empty($techSheets)): ?>
  <p class="empty-row">No tech sheets submitted yet.</p>
  <?php else: ?>
  <table class="data-table" id="tech-sheets-table">
    <thead><tr><th>Event</th><th>Vehicle</th><th>Type</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($techSheets as $ts): $event = $pdo ? db_get_event($pdo, (int)$ts['event_id']) : null; ?>
      <tr>
        <td><?= h($event['name'] ?? 'Unknown event') ?></td>
        <td><?= h(trim($ts['car_make'] . ' ' . $ts['car_model'] . ' #' . $ts['car_number'])) ?></td>
        <td><?= h(ucfirst($ts['sheet_type'])) ?></td>
        <td class="<?= $ts['status'] === 'teched' ? 'badge-ok' : 'badge-fail' ?>"><?= $ts['status'] === 'teched' ? 'Reviewed' : 'Submitted' ?></td>
        <td class="actions"><a href="tech-sheets.php?action=view&id=<?= (int)$ts['id'] ?>">View</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
```

- [ ] **Step 3: Manually verify**

Visit `account.php` as a logged-in user with at least one submission, confirm "Submit Tech Sheet" appears next to "View"/"Delete" on submission rows and links to `tech-sheets.php?action=new&submission_id=<id>`. After submitting a tech sheet (Task 9), reload `account.php`, confirm it appears in the new "My Tech Sheets" table with the correct event name, vehicle, type, and "Submitted" status badge, and that "View" opens it.

- [ ] **Step 4: Commit**

```bash
git add wcma-calculator/account.php
git commit -m "feat: link tech sheet submission and list from My Cars"
```

---

## What's Next

This plan does not include: the admin Tech Sheets list/detail/edit screens, the mobile "Review" action (per-item Driver Safety Equipment approval + tech signature + `status = 'teched'`), or the reviewed-notification email. Those belong to a follow-up plan (`docs/superpowers/plans/<date>-tech-sheets-admin-review.md`) that consumes the `db_get_tech_sheet`/`db_get_tech_sheet_drivers`/`renderTechSheetHtml` interfaces already built here, plus new `db_mark_tech_sheet_reviewed(PDO $pdo, int $id, int $reviewedByUserId, array $driver1Equipment, array $driverEquipmentBySheetDriverId, string $techSignaturePath): void`-style functions.
