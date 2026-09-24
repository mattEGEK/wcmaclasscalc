# Pre-Tech Phase 1: Foundations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the foundations for optional photo-based pre-tech: the versioned photo requirements list, protected photo upload/storage/serving, client-side resize-and-upload, and the wording rename that removes per-item "Tech Approved".

**Architecture:** A pure data file (`photo-requirements.php`) defines the photos. A session-free library (`inspection-lib.php`, same pattern as `feedback-lib.php`) holds validation, storage and authorisation logic so it is unit-testable. A thin endpoint (`inspection.php`) wires the library to the session, CSRF and HTTP. Two small browser modules resize photos to ~1600px JPEG and upload them one at a time. No competitor-facing UI ships in this phase; phase 2 builds the sheet-form section on top.

**Tech Stack:** PHP 8.3, SQLite via PDO, PHPUnit (`phpunit.phar`), vanilla JS (classic scripts, no build step), Node `node:test` for JS unit tests, Playwright (already installed in `scratch/tech-sheet-mockups`) for the end-to-end check.

**Spec:** `docs/superpowers/specs/2026-09-23-digital-tech-inspection-design.md` (this plan implements *Photo Requirements*, *Photo Handling*, the `inspection_photos` table, and the terminology rename from *Impact on Existing Code*).

## Global Constraints

- **Terminology:** UI and email copy uses "reviewed", "accepted", "pre-teched"; never "approved", "passed" or "safe" (spec, *Terminology (binding)*).
- **Tiers:** every photo requirement is `required`, `conditional` or `recommended`. `recommended` never blocks. The helmet back label (`helmet_back`) is `recommended` even though the regulations (App. 4.B.5) require it, because it is not currently enforced.
- **Versioning:** `PHOTO_REQUIREMENTS_VERSION` is an integer in the requirements file; each stored photo records the version it was taken under.
- **One photo per requirement**; a retake replaces the file. Table uniqueness: `UNIQUE (subject_type, subject_id, requirement_key)`.
- **Storage path:** `uploads/inspection/{subject_type}/{subject_id}/{requirement_key}.{ext}`. `uploads/` is already Deny-from-all via `uploads/.htaccess`.
- **Serving:** photos are served only through `inspection.php?action=photo&id=` to the owner and admins. Never public, never embedded in email.
- **Client resize:** long edge 1600px, JPEG, quality 0.8 (strips EXIF/GPS). Photos upload one per request.
- **Server limits:** JPEG/PNG/WebP only, at most 2 MB (`2 * 1024 * 1024` bytes, matching the existing upload cap), at most 4000px on either edge.
- **A teched (accepted) sheet locks for the competitor**: non-admin writes to its photos are refused. Admins may always write.
- **Season = calendar year; car identity = owner + normalised car number + season.** (Used in phase 2; nothing in this phase depends on it.)
- **Repo conventions:** LF-authored PHP with `<?php` header comment naming the file; tests in `wcma-calculator/tests/*Test.php`; migrations idempotent inside `db_init()`; commit after each task.

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `wcma-calculator/tech-sheet-render.php` | modify | Drop the per-item "Tech Rep Approved" column |
| `wcma-calculator/tech-sheet-data.php` | modify | `emptyDriverEquipment()` loses `tech_approved` |
| `wcma-calculator/js/tech-sheet-form.js` | modify | Stop carrying `tech_approved` in form state |
| `wcma-calculator/photo-requirements.php` | create | Requirements data, lookup helpers, typed-value validation, set-completeness |
| `wcma-calculator/db.php` | modify | `inspection_photos` table + 4 DB functions |
| `wcma-calculator/inspection-lib.php` | create | Image validation, path building, save/delete, authorisation, public shape |
| `wcma-calculator/inspection.php` | create | JSON endpoint: upload, delete, photo streaming |
| `wcma-calculator/.gitignore` | modify | Keep real uploads out of git |
| `wcma-calculator/js/photo-resize.js` | create | Pure size maths + canvas resize to JPEG |
| `wcma-calculator/js/photo-upload.js` | create | Upload/delete client with injectable fetch/resize |
| `wcma-calculator/tests/TechSheetRenderTest.php` | modify | Assert no approval wording |
| `wcma-calculator/tests/TechSheetDataTest.php` | modify | Assert equipment shape |
| `wcma-calculator/tests/PhotoRequirementsTest.php` | create | Requirements integrity and helpers |
| `wcma-calculator/tests/DbInspectionPhotosTest.php` | create | DB functions |
| `wcma-calculator/tests/InspectionLibTest.php` | create | Library logic |
| `wcma-calculator/tests/js/photo-resize.test.js` | create | Size maths |
| `wcma-calculator/tests/js/photo-upload.test.js` | create | Client request shape |

Run all PHP tests from `wcma-calculator/` with `php phpunit.phar`; JS tests with `node --test "tests/js/*.test.js"`. (`tests/` is blocked from the web by the root `.htaccess`, so JS tests do not become reachable.)

---

### Task 1: Remove per-item tech approval

**Files:**
- Modify: `wcma-calculator/tech-sheet-render.php:25-48,61,92,98`
- Modify: `wcma-calculator/tech-sheet-data.php:104-110`
- Modify: `wcma-calculator/js/tech-sheet-form.js:14-18`
- Test: `wcma-calculator/tests/TechSheetRenderTest.php`, `wcma-calculator/tests/TechSheetDataTest.php`

**Interfaces:**
- Consumes: none.
- Produces: `techSheetEquipmentTable(array $equipment): string` (one parameter, no tech column); `emptyDriverEquipment(): array` items shaped `['competitor_confirmed' => bool, 'value' => ?string]`.

- [ ] **Step 1: Write the failing tests**

Add to `wcma-calculator/tests/TechSheetRenderTest.php`, inside the class (after `testRenderIncludesHeaderFields`):

```php
    public function testReviewedSheetHasNoApprovalWording(): void
    {
        require_once __DIR__ . '/../tech-sheet-render.php';
        $sheet = $this->sampleSheet();
        $sheet['status'] = 'teched';
        $html = renderTechSheetHtml($sheet, [], ['name' => 'Spring Sprint', 'event_date' => '2026-05-10']);
        $this->assertStringNotContainsString('Approved', $html);
        $this->assertStringContainsString('Reviewed', $html);
    }
```

Add to `wcma-calculator/tests/TechSheetDataTest.php`, inside the class:

```php
    public function testEmptyDriverEquipmentHasNoTechApprovalField(): void
    {
        require_once __DIR__ . '/../tech-sheet-data.php';
        foreach (emptyDriverEquipment() as $item) {
            $this->assertSame(['competitor_confirmed', 'value'], array_keys($item));
        }
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run (from `wcma-calculator/`): `php phpunit.phar --filter "testReviewedSheetHasNoApprovalWording|testEmptyDriverEquipmentHasNoTechApprovalField"`
Expected: 2 failures ("Failed asserting that '...Tech Rep Approved...' does not contain 'Approved'", and the array-keys mismatch).

- [ ] **Step 3: Implement**

In `wcma-calculator/tech-sheet-render.php`, replace the whole `techSheetEquipmentTable` function (lines 25-48) with:

```php
function techSheetEquipmentTable(array $equipment): string {
    $out = '<table cellpadding="4" style="border-collapse:collapse;width:100%;font-size:0.85rem">';
    $out .= '<tr style="background:#f0f1f2"><th style="text-align:left;border:1px solid #ccc;padding:4px">Item</th>';
    $out .= '<th style="border:1px solid #ccc;padding:4px">Confirmed</th>';
    $out .= '</tr>';
    foreach (TECH_DRIVER_EQUIPMENT_ITEMS as $key => $def) {
        $item = $equipment[$key] ?? ['competitor_confirmed' => false, 'value' => null];
        $label = h($def['label']);
        if ($def['has_rating'] && !empty($item['value'])) {
            $label .= ' — <strong>' . h((string)$item['value']) . '</strong>';
        }
        $confirmed = !empty($item['competitor_confirmed']) ? '✓' : '—';
        $out .= '<tr><td style="border:1px solid #ccc;padding:4px">' . $label . '</td>';
        $out .= '<td style="text-align:center;border:1px solid #ccc;padding:4px">' . $confirmed . '</td>';
        $out .= '</tr>';
    }
    $out .= '</table>';
    return $out;
}
```

Delete this line in `renderTechSheetHtml` (line 61):

```php
    $showTechColumn = ($sheet['status'] ?? 'submitted') === 'teched';
```

Change the two call sites:

```php
    $out .= techSheetEquipmentTable($equipment, $showTechColumn);
```
becomes
```php
    $out .= techSheetEquipmentTable($equipment);
```
and
```php
            $out .= techSheetEquipmentTable($driverEquipment, $showTechColumn);
```
becomes
```php
            $out .= techSheetEquipmentTable($driverEquipment);
```

In `wcma-calculator/tech-sheet-data.php`, change `emptyDriverEquipment()` line 107 to:

```php
        $out[$key] = ['competitor_confirmed' => false, 'value' => null];
```

In `wcma-calculator/js/tech-sheet-form.js`, delete line 17 (`tech_approved: existing.tech_approved != null ? existing.tech_approved : null,`) so the `state[key] = {...}` object has only `competitor_confirmed` and `value`.

- [ ] **Step 4: Run all PHP tests and a JS syntax check**

Run: `php phpunit.phar` then `node --check js/tech-sheet-form.js`
Expected: `OK (...)` from PHPUnit, no output from `node --check`.

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/tech-sheet-render.php wcma-calculator/tech-sheet-data.php wcma-calculator/js/tech-sheet-form.js wcma-calculator/tests/TechSheetRenderTest.php wcma-calculator/tests/TechSheetDataTest.php
git commit -m "refactor(tech-sheets): drop per-item tech approval in favour of reviewed/accepted wording"
```

---

### Task 2: Photo requirements file

**Files:**
- Create: `wcma-calculator/photo-requirements.php`
- Test: `wcma-calculator/tests/PhotoRequirementsTest.php`

**Interfaces:**
- Consumes: none.
- Produces (all in the global namespace):
  - `const PHOTO_REQUIREMENTS_VERSION = 1;`
  - `const PHOTO_REQUIREMENTS`: `array<string key, array{scope: 'car'|'gear', tier: 'required'|'conditional'|'recommended', label: string, guidance: string, typed: list<array{name: string, label: string, type: 'month_year'|'select'|'text', options?: list<string>}>, reg: string}>`
  - `photoRequirements(?string $scope = null): array` — the entries for a scope (or all), keyed by requirement key, in file order.
  - `photoRequirementByKey(string $key): ?array` — the entry plus a `key` field, or `null`.
  - `photoValidateTypedValue(array $requirement, array $input): ?array` — normalised `[name => value]` (blank values dropped), or `null` if any provided value is invalid or unknown.
  - `photoSetMissingRequired(string $scope, array $presentKeys, array $applicableConditionalKeys): array` — list of requirement keys still missing.

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/PhotoRequirementsTest.php`:

```php
<?php
// wcma-calculator/tests/PhotoRequirementsTest.php
require_once __DIR__ . '/../photo-requirements.php';

use PHPUnit\Framework\TestCase;

final class PhotoRequirementsTest extends TestCase
{
    private function keysByTier(string $scope, string $tier): array {
        return array_keys(array_filter(photoRequirements($scope), fn($r) => $r['tier'] === $tier));
    }

    public function testCarHasFifteenRequiredAndSixConditional(): void
    {
        $this->assertCount(15, $this->keysByTier('car', 'required'));
        $this->assertSame(
            ['seat_label', 'fuel_cell', 'windshield_clips', 'scattershield', 'ballast', 'aero'],
            $this->keysByTier('car', 'conditional')
        );
        $this->assertSame([], $this->keysByTier('car', 'recommended'));
    }

    public function testGearTiers(): void
    {
        $this->assertSame(
            ['helmet_label', 'suit_label', 'fhr_label', 'gear_flatlay'],
            $this->keysByTier('gear', 'required')
        );
        $this->assertSame(['helmet_back'], $this->keysByTier('gear', 'recommended'));
        $this->assertSame(['underwear_label'], $this->keysByTier('gear', 'conditional'));
    }

    public function testEveryEntryIsWellFormed(): void
    {
        $this->assertSame(1, PHOTO_REQUIREMENTS_VERSION);
        foreach (PHOTO_REQUIREMENTS as $key => $def) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $key);
            $this->assertContains($def['scope'], ['car', 'gear'], $key);
            $this->assertContains($def['tier'], ['required', 'conditional', 'recommended'], $key);
            $this->assertNotSame('', $def['label'], $key);
            $this->assertNotSame('', $def['guidance'], $key);
            $this->assertNotSame('', $def['reg'], $key);
            $names = [];
            foreach ($def['typed'] as $field) {
                $this->assertContains($field['type'], ['month_year', 'select', 'text'], $key);
                $this->assertNotContains($field['name'], $names, "$key duplicate field name");
                $names[] = $field['name'];
                if ($field['type'] === 'select') {
                    $this->assertNotEmpty($field['options'], $key);
                }
            }
        }
    }

    public function testNoApprovalWordingInCopy(): void
    {
        foreach (PHOTO_REQUIREMENTS as $key => $def) {
            foreach (['label', 'guidance'] as $field) {
                $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|safe)\b/i', $def[$field], "$key $field");
            }
        }
    }

    public function testLookupByKey(): void
    {
        $r = photoRequirementByKey('harness_date');
        $this->assertSame('harness_date', $r['key']);
        $this->assertSame('car', $r['scope']);
        $this->assertNull(photoRequirementByKey('nope'));
    }

    public function testTypedValueValidation(): void
    {
        $helmet = photoRequirementByKey('helmet_label');
        $this->assertSame(
            ['standard' => 'SA2020', 'date' => '03/2024'],
            photoValidateTypedValue($helmet, ['standard' => ' SA2020 ', 'date' => '03/2024'])
        );
        $this->assertSame(['standard' => 'SA2020'], photoValidateTypedValue($helmet, ['standard' => 'SA2020', 'date' => '']));
        $this->assertSame([], photoValidateTypedValue($helmet, []));
        $this->assertNull(photoValidateTypedValue($helmet, ['standard' => 'Snell 1995']));
        $this->assertNull(photoValidateTypedValue($helmet, ['date' => '13/2024']));
        $this->assertNull(photoValidateTypedValue($helmet, ['date' => '3/2024']));
        $this->assertNull(photoValidateTypedValue($helmet, ['colour' => 'red']));
        $this->assertNull(photoValidateTypedValue($helmet, ['standard' => ['SA2020']]));

        $suit = photoRequirementByKey('suit_label');
        $this->assertNull(photoValidateTypedValue($suit, ['rating' => str_repeat('x', 101)]));
        $this->assertSame(['rating' => 'SFI 3.2A/5'], photoValidateTypedValue($suit, ['rating' => 'SFI 3.2A/5']));
    }

    public function testMissingRequiredCountsConditionalOnlyWhenApplicable(): void
    {
        $required = array_keys(array_filter(photoRequirements('gear'), fn($r) => $r['tier'] === 'required'));

        $this->assertSame($required, photoSetMissingRequired('gear', [], []));

        $present = ['helmet_label', 'suit_label'];
        $this->assertSame(['fhr_label', 'gear_flatlay'], photoSetMissingRequired('gear', $present, []));

        $allRequired = $required;
        $this->assertSame([], photoSetMissingRequired('gear', $allRequired, []));
        $this->assertSame(['underwear_label'], photoSetMissingRequired('gear', $allRequired, ['underwear_label']));
        $this->assertSame([], photoSetMissingRequired('gear', array_merge($allRequired, ['underwear_label']), ['underwear_label']));
        $this->assertSame([], photoSetMissingRequired('gear', $allRequired, ['helmet_back']));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter PhotoRequirementsTest`
Expected: fatal error or failures: `photo-requirements.php` does not exist ("Failed opening required").

- [ ] **Step 3: Write the requirements file**

Create `wcma-calculator/photo-requirements.php`:

```php
<?php
// wcma-calculator/photo-requirements.php
//
// Versioned list of the photos a competitor can submit to be "pre-teched".
// Edit tiers or add photos here when the regulations change; bump the version
// when the list changes. Sources are the WCMA 2026 Technical Regulations
// (Appendix references in each 'reg' field).

const PHOTO_REQUIREMENTS_VERSION = 1;

const PHOTO_HELMET_STANDARDS = ['SA2020', 'SA2025', 'FIA 8860-2010', 'FIA 8859-2015', 'FIA 8860-2018'];
const PHOTO_FHR_STANDARDS    = ['FIA 8858-2002', 'FIA 8858-2010', 'SFI 38.1'];

const PHOTO_REQUIREMENTS = [
    // ── Car: required ────────────────────────────────────────────────────────
    'front_34' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 3.D, 2.U', 'typed' => [],
        'label' => 'Front three-quarter view',
        'guidance' => 'Whole front of the car with the car number and the front tow point visible.',
    ],
    'rear_34' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 2.S, 2.U', 'typed' => [],
        'label' => 'Rear three-quarter view',
        'guidance' => 'Whole rear of the car with the exhaust exit and the rear tow point visible.',
    ],
    'side_driver' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 3', 'typed' => [],
        'label' => "Driver's side",
        'guidance' => 'Full side view showing the number, class designation, minimum weight on the door, and WCMA decals.',
    ],
    'side_passenger' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 3', 'typed' => [],
        'label' => "Passenger's side",
        'guidance' => 'Full side view showing the number, class designation and decals.',
    ],
    'cage_overall' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 1', 'typed' => [],
        'label' => 'Roll cage, overall',
        'guidance' => 'The whole cage as seen from inside the car.',
    ],
    'cage_mounts' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 1', 'typed' => [],
        'label' => 'Main hoop and mounting points',
        'guidance' => 'Close view of the main hoop and where the cage attaches to the chassis.',
    ],
    'cage_padding' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 2.H.3', 'typed' => [],
        'label' => 'Cage padding',
        'guidance' => 'Padding on every cage member the driver or helmet could contact.',
    ],
    'seat_harness' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 2.A', 'typed' => [],
        'label' => 'Seat and harness, installed',
        'guidance' => 'Driver seat with the harness installed and its mounting points visible.',
    ],
    'harness_date' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 2.A.1.c',
        'typed' => [['name' => 'date', 'label' => 'Date stamp (MM/YYYY)', 'type' => 'month_year']],
        'label' => 'Harness date stamp',
        'guidance' => 'Close-up of the harness label so the date stamp and the standard (SFI 16.1 or FIA 8853) are readable.',
    ],
    'window_net' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 2.E', 'typed' => [],
        'label' => 'Window net',
        'guidance' => 'The driver-side window net installed, showing how it attaches to the car.',
    ],
    'fire_system' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 2.B',
        'typed' => [['name' => 'date', 'label' => 'Service or expiry date (MM/YYYY)', 'type' => 'month_year']],
        'label' => 'Fire suppression system',
        'guidance' => 'The bottle with its gauge and date label readable.',
    ],
    'kill_switch' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 2.C', 'typed' => [],
        'label' => 'Kill switch and its marking',
        'guidance' => 'The switch and the red-spark-on-blue-triangle marking that identifies it.',
    ],
    'battery' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 2.T', 'typed' => [],
        'label' => 'Battery',
        'guidance' => 'The battery hold-down and the insulated terminals.',
    ],
    'engine_bay' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 2.F, 2.K, 2.M', 'typed' => [],
        'label' => 'Engine bay',
        'guidance' => 'The engine bay showing the oil and coolant catch tanks and the firewall.',
    ],
    'interior' => [
        'scope' => 'car', 'tier' => 'required', 'reg' => 'App. 2.H', 'typed' => [],
        'label' => 'Interior, overall',
        'guidance' => 'The whole cockpit with no loose objects and no flammable trim.',
    ],

    // ── Car: conditional (only if it applies to the car) ─────────────────────
    'seat_label' => [
        'scope' => 'car', 'tier' => 'conditional', 'reg' => 'App. 2.A.2', 'typed' => [],
        'label' => 'Seat label',
        'guidance' => 'For plastic or composite seats: the SFI or FIA certification label.',
    ],
    'fuel_cell' => [
        'scope' => 'car', 'tier' => 'conditional', 'reg' => 'App. 2.J.8', 'typed' => [],
        'label' => 'Fuel cell installation',
        'guidance' => 'The fuel cell in its container with the FIA FT3 or SFI 28.3 label readable.',
    ],
    'windshield_clips' => [
        'scope' => 'car', 'tier' => 'conditional', 'reg' => 'App. 2.D.1', 'typed' => [],
        'label' => 'Polycarbonate windshield clips',
        'guidance' => 'The retaining clips on a polycarbonate windshield.',
    ],
    'scattershield' => [
        'scope' => 'car', 'tier' => 'conditional', 'reg' => 'App. 2.O', 'typed' => [],
        'label' => 'Scattershield and driveshaft hoops',
        'guidance' => 'Scattershield and driveshaft safety hoops (tube-frame cars).',
    ],
    'ballast' => [
        'scope' => 'car', 'tier' => 'conditional', 'reg' => 'App. 2.P', 'typed' => [],
        'label' => 'Ballast mounting',
        'guidance' => 'How any ballast is bolted in.',
    ],
    'aero' => [
        'scope' => 'car', 'tier' => 'conditional', 'reg' => 'Sections 3.2.D, 3.3.D', 'typed' => [],
        'label' => 'Splitter or wing',
        'guidance' => 'Any front splitter or rear wing fitted to the car.',
    ],

    // ── Gear: per driver ─────────────────────────────────────────────────────
    'helmet_label' => [
        'scope' => 'gear', 'tier' => 'required', 'reg' => 'App. 4.B',
        'typed' => [
            ['name' => 'standard', 'label' => 'Standard', 'type' => 'select', 'options' => PHOTO_HELMET_STANDARDS],
            ['name' => 'date', 'label' => 'Date (MM/YYYY)', 'type' => 'month_year'],
        ],
        'label' => 'Helmet certification label',
        'guidance' => 'The inside label showing the certification standard and date.',
    ],
    'suit_label' => [
        'scope' => 'gear', 'tier' => 'required', 'reg' => 'App. 4.D',
        'typed' => [['name' => 'rating', 'label' => 'Rating (e.g. SFI 3.2A/5)', 'type' => 'text']],
        'label' => 'Race suit label',
        'guidance' => 'The suit label showing the rating.',
    ],
    'fhr_label' => [
        'scope' => 'gear', 'tier' => 'required', 'reg' => 'App. 4.C',
        'typed' => [
            ['name' => 'standard', 'label' => 'Standard', 'type' => 'select', 'options' => PHOTO_FHR_STANDARDS],
            ['name' => 'date', 'label' => 'Date (MM/YYYY)', 'type' => 'month_year'],
        ],
        'label' => 'Frontal head restraint label',
        'guidance' => 'The label on the frontal head restraint showing the standard and date.',
    ],
    'gear_flatlay' => [
        'scope' => 'gear', 'tier' => 'required', 'reg' => 'App. 4.E', 'typed' => [],
        'label' => 'Gloves, shoes, socks and balaclava',
        'guidance' => 'All four items laid out together in one photo.',
    ],
    // Required by App. 4.B.5 but not currently enforced, so recommended only.
    // Flip 'tier' to 'required' (and bump the version) if enforcement starts.
    'helmet_back' => [
        'scope' => 'gear', 'tier' => 'recommended', 'reg' => 'App. 4.B.5', 'typed' => [],
        'label' => 'Helmet back label',
        'guidance' => 'The back of the helmet showing name, date of birth and allergies.',
    ],
    'underwear_label' => [
        'scope' => 'gear', 'tier' => 'conditional', 'reg' => 'App. 4.D', 'typed' => [],
        'label' => 'Fire-resistant underwear label',
        'guidance' => 'For suits that require it: the underwear label.',
    ],
];

/** Requirements for one scope ('car' or 'gear'), or all of them, keyed by requirement key. */
function photoRequirements(?string $scope = null): array {
    if ($scope === null) return PHOTO_REQUIREMENTS;
    return array_filter(PHOTO_REQUIREMENTS, fn(array $r): bool => $r['scope'] === $scope);
}

/** One requirement with its 'key' added, or null if the key is unknown. */
function photoRequirementByKey(string $key): ?array {
    if (!isset(PHOTO_REQUIREMENTS[$key])) return null;
    return PHOTO_REQUIREMENTS[$key] + ['key' => $key];
}

/**
 * Validates the typed values posted with a photo against the requirement's
 * typed field definitions. Blank values are dropped. Returns the normalised
 * [name => value] map, or null if any provided value is unknown or invalid.
 */
function photoValidateTypedValue(array $requirement, array $input): ?array {
    $defs = [];
    foreach ($requirement['typed'] as $def) {
        $defs[$def['name']] = $def;
    }

    $out = [];
    foreach ($input as $name => $value) {
        if (!isset($defs[$name]) || !is_string($value)) return null;
        $value = trim($value);
        if ($value === '') continue;

        switch ($defs[$name]['type']) {
            case 'month_year':
                if (!preg_match('/^(0[1-9]|1[0-2])\/\d{4}$/', $value)) return null;
                break;
            case 'select':
                if (!in_array($value, $defs[$name]['options'], true)) return null;
                break;
            case 'text':
                if (strlen($value) > 100) return null;
                break;
            default:
                return null;
        }
        $out[$name] = $value;
    }
    return $out;
}

/**
 * Requirement keys still missing for a complete pre-tech set: every
 * 'required' photo, plus any 'conditional' photo the competitor said applies.
 * 'recommended' photos never count.
 *
 * @param string[] $presentKeys              keys that already have a photo
 * @param string[] $applicableConditionalKeys conditional keys marked "applies to my car"
 * @return string[]
 */
function photoSetMissingRequired(string $scope, array $presentKeys, array $applicableConditionalKeys): array {
    $missing = [];
    foreach (photoRequirements($scope) as $key => $def) {
        $needed = $def['tier'] === 'required'
            || ($def['tier'] === 'conditional' && in_array($key, $applicableConditionalKeys, true));
        if ($needed && !in_array($key, $presentKeys, true)) {
            $missing[] = $key;
        }
    }
    return $missing;
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar --filter PhotoRequirementsTest`
Expected: `OK (8 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/photo-requirements.php wcma-calculator/tests/PhotoRequirementsTest.php
git commit -m "feat(pre-tech): add versioned photo requirements list with typed-value validation"
```

---

### Task 3: Inspection photos table and DB functions

**Files:**
- Modify: `wcma-calculator/db.php` (add table inside `db_init()` after the `tech_sheet_drivers` block at line ~195; append functions at end of file)
- Test: `wcma-calculator/tests/DbInspectionPhotosTest.php`

**Interfaces:**
- Consumes: `make_temp_pdo()` from `tests/bootstrap.php`.
- Produces:
  - `db_upsert_inspection_photo(PDO $pdo, array $d): ?string` — `$d` keys: `subject_type`, `subject_id`, `requirement_key`, `requirement_version`, `file_path`, `typed_value` (JSON string or null). Inserts or replaces the row for that (subject_type, subject_id, requirement_key), resets `review_status` to `pending`, clears `reviewer_note`, sets `applies = 1`. Returns the **previous** `file_path` if a row existed, else `null`.
  - `db_get_inspection_photo(PDO $pdo, int $id): ?array`
  - `db_get_inspection_photos(PDO $pdo, string $subjectType, int $subjectId): array` — rows keyed by `requirement_key`.
  - `db_delete_inspection_photo(PDO $pdo, int $id): void`

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/DbInspectionPhotosTest.php`:

```php
<?php
// wcma-calculator/tests/DbInspectionPhotosTest.php
use PHPUnit\Framework\TestCase;

final class DbInspectionPhotosTest extends TestCase
{
    private function photo(array $overrides = []): array {
        return array_merge([
            'subject_type' => 'tech_sheet', 'subject_id' => 7, 'requirement_key' => 'front_34',
            'requirement_version' => 1, 'file_path' => 'uploads/inspection/tech_sheet/7/front_34.jpg',
            'typed_value' => null,
        ], $overrides);
    }

    public function testInsertThenGet(): void
    {
        $pdo = make_temp_pdo();
        $this->assertNull(db_upsert_inspection_photo($pdo, $this->photo()));

        $photos = db_get_inspection_photos($pdo, 'tech_sheet', 7);
        $this->assertSame(['front_34'], array_keys($photos));
        $row = $photos['front_34'];
        $this->assertSame('pending', $row['review_status']);
        $this->assertSame(1, (int)$row['applies']);
        $this->assertSame(1, (int)$row['requirement_version']);

        $byId = db_get_inspection_photo($pdo, (int)$row['id']);
        $this->assertSame('front_34', $byId['requirement_key']);
        $this->assertNull(db_get_inspection_photo($pdo, 99999));
    }

    public function testReplacingReturnsPreviousPathAndResetsReview(): void
    {
        $pdo = make_temp_pdo();
        db_upsert_inspection_photo($pdo, $this->photo(['file_path' => 'a.jpg', 'typed_value' => '{"date":"01/2025"}']));
        $id = (int)db_get_inspection_photos($pdo, 'tech_sheet', 7)['front_34']['id'];
        $pdo->prepare("UPDATE inspection_photos SET review_status = 'retake', reviewer_note = 'blurry' WHERE id = :id")->execute([':id' => $id]);

        $previous = db_upsert_inspection_photo($pdo, $this->photo(['file_path' => 'b.png', 'typed_value' => null]));
        $this->assertSame('a.jpg', $previous);

        $rows = db_get_inspection_photos($pdo, 'tech_sheet', 7);
        $this->assertCount(1, $rows);
        $this->assertSame($id, (int)$rows['front_34']['id']);
        $this->assertSame('b.png', $rows['front_34']['file_path']);
        $this->assertSame('pending', $rows['front_34']['review_status']);
        $this->assertNull($rows['front_34']['reviewer_note']);
        $this->assertNull($rows['front_34']['typed_value']);
    }

    public function testPhotosAreScopedBySubject(): void
    {
        $pdo = make_temp_pdo();
        db_upsert_inspection_photo($pdo, $this->photo(['subject_id' => 7]));
        db_upsert_inspection_photo($pdo, $this->photo(['subject_id' => 8]));
        db_upsert_inspection_photo($pdo, $this->photo(['subject_id' => 7, 'requirement_key' => 'rear_34']));

        $this->assertCount(2, db_get_inspection_photos($pdo, 'tech_sheet', 7));
        $this->assertCount(1, db_get_inspection_photos($pdo, 'tech_sheet', 8));
        $this->assertSame([], db_get_inspection_photos($pdo, 'gear_record', 7));
    }

    public function testDelete(): void
    {
        $pdo = make_temp_pdo();
        db_upsert_inspection_photo($pdo, $this->photo());
        $id = (int)db_get_inspection_photos($pdo, 'tech_sheet', 7)['front_34']['id'];

        db_delete_inspection_photo($pdo, $id);
        $this->assertNull(db_get_inspection_photo($pdo, $id));
        $this->assertSame([], db_get_inspection_photos($pdo, 'tech_sheet', 7));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter DbInspectionPhotosTest`
Expected: 4 errors, `Call to undefined function db_upsert_inspection_photo()`.

- [ ] **Step 3: Add the table**

In `wcma-calculator/db.php`, immediately after the `tech_sheet_drivers` CREATE TABLE block (the `");` that closes it, before the `// Add user_id to submissions if migrating an existing DB` comment), insert:

```php
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS inspection_photos (
            id                   INTEGER PRIMARY KEY AUTOINCREMENT,
            subject_type         TEXT NOT NULL,
            subject_id           INTEGER NOT NULL,
            requirement_key      TEXT NOT NULL,
            requirement_version  INTEGER NOT NULL,
            file_path            TEXT NOT NULL DEFAULT '',
            typed_value          TEXT,
            review_status        TEXT NOT NULL DEFAULT 'pending',
            reviewer_note        TEXT,
            applies              INTEGER NOT NULL DEFAULT 1,
            created_at           DATETIME NOT NULL,
            updated_at           DATETIME NOT NULL,
            UNIQUE (subject_type, subject_id, requirement_key)
        )
    ");
```

- [ ] **Step 4: Add the functions**

Append to the end of `wcma-calculator/db.php`:

```php

/**
 * Inserts the photo for (subject_type, subject_id, requirement_key), or
 * replaces it if one exists (a retake): the review state resets to pending.
 * Returns the previous file_path when replacing, so the caller can delete the
 * stale file, or null for a first upload.
 */
function db_upsert_inspection_photo(PDO $pdo, array $d): ?string {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("SELECT id, file_path FROM inspection_photos WHERE subject_type = :t AND subject_id = :s AND requirement_key = :k");
    $stmt->execute([':t' => $d['subject_type'], ':s' => $d['subject_id'], ':k' => $d['requirement_key']]);
    $existing = $stmt->fetch();

    if ($existing) {
        $pdo->prepare("
            UPDATE inspection_photos SET
                requirement_version = :v, file_path = :p, typed_value = :tv,
                review_status = 'pending', reviewer_note = NULL, applies = 1, updated_at = :now
            WHERE id = :id
        ")->execute([
            ':v' => $d['requirement_version'], ':p' => $d['file_path'], ':tv' => $d['typed_value'],
            ':now' => $now, ':id' => $existing['id'],
        ]);
        return $existing['file_path'];
    }

    $pdo->prepare("
        INSERT INTO inspection_photos
            (subject_type, subject_id, requirement_key, requirement_version, file_path, typed_value, created_at, updated_at)
        VALUES (:t, :s, :k, :v, :p, :tv, :now, :now)
    ")->execute([
        ':t' => $d['subject_type'], ':s' => $d['subject_id'], ':k' => $d['requirement_key'],
        ':v' => $d['requirement_version'], ':p' => $d['file_path'], ':tv' => $d['typed_value'], ':now' => $now,
    ]);
    return null;
}

function db_get_inspection_photo(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM inspection_photos WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

/** All photos for a subject, keyed by requirement_key. */
function db_get_inspection_photos(PDO $pdo, string $subjectType, int $subjectId): array {
    $stmt = $pdo->prepare("SELECT * FROM inspection_photos WHERE subject_type = :t AND subject_id = :s ORDER BY id ASC");
    $stmt->execute([':t' => $subjectType, ':s' => $subjectId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[$row['requirement_key']] = $row;
    }
    return $out;
}

function db_delete_inspection_photo(PDO $pdo, int $id): void {
    $pdo->prepare("DELETE FROM inspection_photos WHERE id = :id")->execute([':id' => $id]);
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php phpunit.phar`
Expected: `OK` for the full suite, including the 4 new tests.

- [ ] **Step 6: Commit**

```bash
git add wcma-calculator/db.php wcma-calculator/tests/DbInspectionPhotosTest.php
git commit -m "feat(pre-tech): add inspection_photos table and DB functions"
```

---

### Task 4: Inspection library (validation, storage, authorisation)

**Files:**
- Create: `wcma-calculator/inspection-lib.php`
- Test: `wcma-calculator/tests/InspectionLibTest.php`

**Interfaces:**
- Consumes: `photoRequirementByKey()`, `photoValidateTypedValue()`, `PHOTO_REQUIREMENTS_VERSION` (Task 2); `db_upsert_inspection_photo()`, `db_get_inspection_photo()`, `db_delete_inspection_photo()` (Task 3).
- Produces:
  - `const INSPECTION_MAX_BYTES = 2097152; const INSPECTION_MAX_EDGE = 4000; const INSPECTION_SUBJECT_SCOPE = ['tech_sheet' => 'car'];`
  - `inspectionValidateImage(string $path): array{ok: bool, error: ?string, mime: ?string, ext: ?string}`
  - `inspectionPhotoRelativePath(string $subjectType, int $subjectId, string $requirementKey, string $ext): string`
  - `inspectionCanAccess(array $user, array $sheet, bool $forWrite): bool` — `$user` has `id` and `role`; `$sheet` is a `tech_sheets` row.
  - `inspectionSavePhoto(PDO $pdo, string $baseDir, string $subjectType, int $subjectId, string $requirementKey, string $tmpPath, array $typedInput, ?callable $mover = null): array{ok: bool, error: ?string, photo: ?array}` — `$mover(string $from, string $to): bool` defaults to `move_uploaded_file`.
  - `inspectionDeletePhoto(PDO $pdo, string $baseDir, int $photoId): bool`
  - `inspectionPublicPhoto(array $row): array{id: int, requirement_key: string, typed: array, review_status: string, reviewer_note: ?string, url: string}`

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/InspectionLibTest.php`:

```php
<?php
// wcma-calculator/tests/InspectionLibTest.php
require_once __DIR__ . '/../photo-requirements.php';
require_once __DIR__ . '/../inspection-lib.php';

use PHPUnit\Framework\TestCase;

final class InspectionLibTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wcma_insp_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
        rmdir($this->dir);
    }

    private function tmpFile(string $bytes): string {
        $p = $this->dir . '/' . uniqid('up_') . '.bin';
        file_put_contents($p, $bytes);
        return $p;
    }

    private function jpeg(): string {
        // Minimal JPEG (SOI + SOF0 declaring 1x1 + EOI); getimagesize() reads the header.
        return hex2bin('ffd8ffc00011080001000103011100021100031100ffd9');
    }

    private function png(): string {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    public function testValidateImageAcceptsJpegAndPng(): void
    {
        $r = inspectionValidateImage($this->tmpFile($this->jpeg()));
        $this->assertTrue($r['ok']);
        $this->assertSame('image/jpeg', $r['mime']);
        $this->assertSame('jpg', $r['ext']);

        $r = inspectionValidateImage($this->tmpFile($this->png()));
        $this->assertTrue($r['ok']);
        $this->assertSame('png', $r['ext']);
    }

    public function testValidateImageRejectsNonImagesAndOversize(): void
    {
        $this->assertFalse(inspectionValidateImage($this->tmpFile('not an image'))['ok']);
        $this->assertFalse(inspectionValidateImage($this->tmpFile(''))['ok']);
        $this->assertFalse(inspectionValidateImage($this->dir . '/missing.bin')['ok']);

        $big = $this->jpeg() . str_repeat('x', INSPECTION_MAX_BYTES);
        $this->assertFalse(inspectionValidateImage($this->tmpFile($big))['ok']);
    }

    public function testValidateImageRejectsHugeDimensions(): void
    {
        // Same minimal JPEG but declaring 5000x5000.
        $huge = hex2bin('ffd8ffc000110800' . '1388' . '1388' . '03011100021100031100ffd9');
        $r = inspectionValidateImage($this->tmpFile($huge));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('too large', $r['error']);
    }

    public function testRelativePath(): void
    {
        $this->assertSame('uploads/inspection/tech_sheet/7/front_34.jpg', inspectionPhotoRelativePath('tech_sheet', 7, 'front_34', 'jpg'));
    }

    public function testCanAccess(): void
    {
        $owner = ['id' => 5, 'role' => 'user'];
        $other = ['id' => 6, 'role' => 'user'];
        $admin = ['id' => 1, 'role' => 'admin'];
        $open = ['user_id' => 5, 'status' => 'submitted'];
        $teched = ['user_id' => 5, 'status' => 'teched'];

        $this->assertTrue(inspectionCanAccess($owner, $open, true));
        $this->assertTrue(inspectionCanAccess($owner, $open, false));
        $this->assertFalse(inspectionCanAccess($other, $open, false));
        $this->assertFalse(inspectionCanAccess($other, $open, true));
        $this->assertTrue(inspectionCanAccess($admin, $open, true));

        $this->assertTrue(inspectionCanAccess($owner, $teched, false));
        $this->assertFalse(inspectionCanAccess($owner, $teched, true));
        $this->assertTrue(inspectionCanAccess($admin, $teched, true));
    }

    public function testSavePhotoStoresFileAndRow(): void
    {
        $pdo = make_temp_pdo();
        $tmp = $this->tmpFile($this->jpeg());

        $r = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 7, 'harness_date', $tmp, ['date' => '05/2025'], 'rename');

        $this->assertTrue($r['ok'], (string)$r['error']);
        $this->assertSame('uploads/inspection/tech_sheet/7/harness_date.jpg', $r['photo']['file_path']);
        $this->assertFileExists($this->dir . '/' . $r['photo']['file_path']);
        $this->assertSame(['date' => '05/2025'], json_decode($r['photo']['typed_value'], true));
        $this->assertSame(PHOTO_REQUIREMENTS_VERSION, (int)$r['photo']['requirement_version']);
    }

    public function testSavePhotoRejectsBadInput(): void
    {
        $pdo = make_temp_pdo();
        $good = fn() => $this->tmpFile($this->jpeg());

        $r = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 7, 'not_a_key', $good(), [], 'rename');
        $this->assertFalse($r['ok']);

        $r = inspectionSavePhoto($pdo, $this->dir, 'gear_record', 7, 'front_34', $good(), [], 'rename');
        $this->assertFalse($r['ok']);

        // Gear requirement on a car subject: wrong scope.
        $r = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 7, 'helmet_label', $good(), [], 'rename');
        $this->assertFalse($r['ok']);

        $r = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 7, 'harness_date', $good(), ['date' => '2025-05'], 'rename');
        $this->assertFalse($r['ok']);

        $r = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 7, 'front_34', $this->tmpFile('junk'), [], 'rename');
        $this->assertFalse($r['ok']);

        $this->assertSame([], db_get_inspection_photos($pdo, 'tech_sheet', 7));
    }

    public function testRetakeWithDifferentFormatRemovesOldFile(): void
    {
        $pdo = make_temp_pdo();
        $first = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 7, 'front_34', $this->tmpFile($this->jpeg()), [], 'rename');
        $oldAbs = $this->dir . '/' . $first['photo']['file_path'];
        $this->assertFileExists($oldAbs);

        $second = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 7, 'front_34', $this->tmpFile($this->png()), [], 'rename');
        $this->assertTrue($second['ok']);
        $this->assertSame('uploads/inspection/tech_sheet/7/front_34.png', $second['photo']['file_path']);
        $this->assertFileDoesNotExist($oldAbs);
        $this->assertFileExists($this->dir . '/' . $second['photo']['file_path']);
        $this->assertCount(1, db_get_inspection_photos($pdo, 'tech_sheet', 7));
    }

    public function testDeletePhotoRemovesFileAndRow(): void
    {
        $pdo = make_temp_pdo();
        $saved = inspectionSavePhoto($pdo, $this->dir, 'tech_sheet', 7, 'front_34', $this->tmpFile($this->jpeg()), [], 'rename');
        $abs = $this->dir . '/' . $saved['photo']['file_path'];

        $this->assertTrue(inspectionDeletePhoto($pdo, $this->dir, (int)$saved['photo']['id']));
        $this->assertFileDoesNotExist($abs);
        $this->assertNull(db_get_inspection_photo($pdo, (int)$saved['photo']['id']));
        $this->assertFalse(inspectionDeletePhoto($pdo, $this->dir, 99999));
    }

    public function testPublicPhotoHidesFilePath(): void
    {
        $row = [
            'id' => '12', 'requirement_key' => 'helmet_label', 'typed_value' => '{"standard":"SA2020"}',
            'review_status' => 'retake', 'reviewer_note' => 'Too dark', 'file_path' => 'uploads/x.jpg',
        ];
        $public = inspectionPublicPhoto($row);
        $this->assertSame(12, $public['id']);
        $this->assertSame(['standard' => 'SA2020'], $public['typed']);
        $this->assertSame('inspection.php?action=photo&id=12', $public['url']);
        $this->assertArrayNotHasKey('file_path', $public);

        $row['typed_value'] = null;
        $this->assertSame([], inspectionPublicPhoto($row)['typed']);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php phpunit.phar --filter InspectionLibTest`
Expected: fatal error, `inspection-lib.php` not found.

- [ ] **Step 3: Write the library**

Create `wcma-calculator/inspection-lib.php`:

```php
<?php
// wcma-calculator/inspection-lib.php
//
// Pure(ish) logic for inspection photos: validation, storage, authorisation.
// No session, headers or HTTP here — inspection.php injects those — so it is
// unit-testable (same pattern as feedback-lib.php). Callers must have loaded
// db.php and photo-requirements.php.

const INSPECTION_MAX_BYTES = 2 * 1024 * 1024;
const INSPECTION_MAX_EDGE = 4000;

/** Which requirement scope each subject type stores photos for. */
const INSPECTION_SUBJECT_SCOPE = ['tech_sheet' => 'car'];

/**
 * Checks an uploaded file really is a JPEG/PNG/WebP of acceptable size and
 * dimensions. Sniffs the bytes (never trusts the client's MIME or filename).
 *
 * @return array{ok: bool, error: ?string, mime: ?string, ext: ?string}
 */
function inspectionValidateImage(string $path): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'mime' => null, 'ext' => null];

    if (!is_file($path)) return $fail('No photo received.');
    $size = filesize($path);
    if ($size === false || $size === 0) return $fail('The photo is empty.');
    if ($size > INSPECTION_MAX_BYTES) return $fail('The photo is too large (2 MB maximum).');

    $info = @getimagesize($path);
    if ($info === false) return $fail('That file is not a photo we can read.');

    $extByMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = $info['mime'] ?? '';
    if (!isset($extByMime[$mime])) return $fail('Photos must be JPEG, PNG or WebP.');

    if ($info[0] > INSPECTION_MAX_EDGE || $info[1] > INSPECTION_MAX_EDGE) {
        return $fail('The photo dimensions are too large.');
    }

    return ['ok' => true, 'error' => null, 'mime' => $mime, 'ext' => $extByMime[$mime]];
}

/** Path (relative to the app directory) where a photo is stored. */
function inspectionPhotoRelativePath(string $subjectType, int $subjectId, string $requirementKey, string $ext): string {
    return 'uploads/inspection/' . $subjectType . '/' . $subjectId . '/' . $requirementKey . '.' . $ext;
}

/**
 * Owners may read their own sheet's photos and write until the sheet is
 * accepted ('teched'), when it locks. Admins may always read and write.
 */
function inspectionCanAccess(array $user, array $sheet, bool $forWrite): bool {
    if (($user['role'] ?? '') === 'admin') return true;
    if ((int)$user['id'] !== (int)$sheet['user_id']) return false;
    return !$forWrite || ($sheet['status'] ?? '') !== 'teched';
}

/**
 * Validates and stores one photo, replacing any previous photo for the same
 * requirement (a retake). $mover defaults to move_uploaded_file; tests pass
 * 'rename'.
 *
 * @return array{ok: bool, error: ?string, photo: ?array}
 */
function inspectionSavePhoto(
    PDO $pdo, string $baseDir, string $subjectType, int $subjectId, string $requirementKey,
    string $tmpPath, array $typedInput, ?callable $mover = null
): array {
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg, 'photo' => null];

    if (!isset(INSPECTION_SUBJECT_SCOPE[$subjectType])) return $fail('Unknown photo subject.');
    $requirement = photoRequirementByKey($requirementKey);
    if ($requirement === null || $requirement['scope'] !== INSPECTION_SUBJECT_SCOPE[$subjectType]) {
        return $fail('Unknown photo type.');
    }

    $typed = photoValidateTypedValue($requirement, $typedInput);
    if ($typed === null) return $fail('One of the details entered for this photo is not valid.');

    $image = inspectionValidateImage($tmpPath);
    if (!$image['ok']) return $fail($image['error']);

    $relative = inspectionPhotoRelativePath($subjectType, $subjectId, $requirementKey, $image['ext']);
    $absolute = $baseDir . '/' . $relative;
    $dir = dirname($absolute);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) return $fail('Could not store the photo.');

    $mover = $mover ?? 'move_uploaded_file';
    if (!$mover($tmpPath, $absolute)) return $fail('Could not store the photo.');

    $previous = db_upsert_inspection_photo($pdo, [
        'subject_type' => $subjectType, 'subject_id' => $subjectId, 'requirement_key' => $requirementKey,
        'requirement_version' => PHOTO_REQUIREMENTS_VERSION, 'file_path' => $relative,
        'typed_value' => $typed ? json_encode($typed) : null,
    ]);
    if ($previous !== null && $previous !== '' && $previous !== $relative && is_file($baseDir . '/' . $previous)) {
        unlink($baseDir . '/' . $previous);
    }

    $stored = db_get_inspection_photos($pdo, $subjectType, $subjectId)[$requirementKey];
    return ['ok' => true, 'error' => null, 'photo' => $stored];
}

/** Removes a photo's file and row. False if the photo does not exist. */
function inspectionDeletePhoto(PDO $pdo, string $baseDir, int $photoId): bool {
    $photo = db_get_inspection_photo($pdo, $photoId);
    if ($photo === null) return false;
    $abs = $baseDir . '/' . $photo['file_path'];
    if ($photo['file_path'] !== '' && is_file($abs)) unlink($abs);
    db_delete_inspection_photo($pdo, $photoId);
    return true;
}

/** The shape sent to the browser: no server file path, plus the authenticated URL. */
function inspectionPublicPhoto(array $row): array {
    return [
        'id' => (int)$row['id'],
        'requirement_key' => $row['requirement_key'],
        'typed' => $row['typed_value'] !== null && $row['typed_value'] !== '' ? (json_decode($row['typed_value'], true) ?: []) : [],
        'review_status' => $row['review_status'],
        'reviewer_note' => $row['reviewer_note'] ?? null,
        'url' => 'inspection.php?action=photo&id=' . (int)$row['id'],
    ];
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php phpunit.phar`
Expected: `OK` for the full suite, including `InspectionLibTest` (10 tests).

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/inspection-lib.php wcma-calculator/tests/InspectionLibTest.php
git commit -m "feat(pre-tech): add inspection photo library (validation, storage, authorisation)"
```

---

### Task 5: Inspection endpoint

**Files:**
- Create: `wcma-calculator/inspection.php`
- Modify: `wcma-calculator/.gitignore`

**Interfaces:**
- Consumes: `current_user()`, `validateCsrfToken()`, `db_get_tech_sheet()`, `db_get_inspection_photo()`, and everything from Tasks 2-4.
- Produces (HTTP, all require a signed-in session):
  - `POST inspection.php?action=upload` — multipart fields `csrf_token`, `subject_type`, `subject_id`, `requirement_key`, `photo` (file), optional `typed[<name>]`. Response `{"ok": true, "photo": {id, requirement_key, typed, review_status, reviewer_note, url}}`; errors `{"ok": false, "error": "..."}` with status 400/401/403/404/405.
  - `POST inspection.php?action=delete` — fields `csrf_token`, `id`. Response `{"ok": true}`.
  - `GET inspection.php?action=photo&id=N` — streams the image (`Content-Type` from the stored extension, `Cache-Control: private`), or 404.

- [ ] **Step 1: Write the endpoint**

Create `wcma-calculator/inspection.php`:

```php
<?php
// wcma-calculator/inspection.php — JSON/image endpoints for inspection photos.
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/photo-requirements.php';
require __DIR__ . '/inspection-lib.php';

$pdo = db_connect();
db_init($pdo);

function inspectionJson(int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
}

/** The subject row if it exists and the user may access it, else null (never reveals which). */
function inspectionLoadSubject(PDO $pdo, array $user, string $type, int $id, bool $forWrite): ?array {
    if (!isset(INSPECTION_SUBJECT_SCOPE[$type])) return null;
    $sheet = db_get_tech_sheet($pdo, $id);
    if (!$sheet || !inspectionCanAccess($user, $sheet, $forWrite)) return null;
    return $sheet;
}

$user = current_user();
if ($user === null) {
    inspectionJson(401, ['ok' => false, 'error' => 'Please sign in.']);
}

$action = $_GET['action'] ?? '';

if ($action === 'upload') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') inspectionJson(405, ['ok' => false, 'error' => 'POST required.']);
    if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) inspectionJson(403, ['ok' => false, 'error' => 'Invalid CSRF token.']);

    $type = (string)($_POST['subject_type'] ?? '');
    $subjectId = (int)($_POST['subject_id'] ?? 0);
    if (inspectionLoadSubject($pdo, $user, $type, $subjectId, true) === null) {
        inspectionJson(404, ['ok' => false, 'error' => 'Not found.']);
    }

    $file = $_FILES['photo'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        inspectionJson(400, ['ok' => false, 'error' => 'No photo received.']);
    }

    $typed = isset($_POST['typed']) && is_array($_POST['typed']) ? $_POST['typed'] : [];
    $result = inspectionSavePhoto($pdo, __DIR__, $type, $subjectId, (string)($_POST['requirement_key'] ?? ''), $file['tmp_name'], $typed);
    if (!$result['ok']) inspectionJson(400, ['ok' => false, 'error' => $result['error']]);

    inspectionJson(200, ['ok' => true, 'photo' => inspectionPublicPhoto($result['photo'])]);
}

if ($action === 'delete') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') inspectionJson(405, ['ok' => false, 'error' => 'POST required.']);
    if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) inspectionJson(403, ['ok' => false, 'error' => 'Invalid CSRF token.']);

    $photo = db_get_inspection_photo($pdo, (int)($_POST['id'] ?? 0));
    if ($photo === null || inspectionLoadSubject($pdo, $user, $photo['subject_type'], (int)$photo['subject_id'], true) === null) {
        inspectionJson(404, ['ok' => false, 'error' => 'Not found.']);
    }
    inspectionDeletePhoto($pdo, __DIR__, (int)$photo['id']);
    inspectionJson(200, ['ok' => true]);
}

if ($action === 'photo') {
    $photo = db_get_inspection_photo($pdo, (int)($_GET['id'] ?? 0));
    if ($photo === null || $photo['file_path'] === ''
        || inspectionLoadSubject($pdo, $user, $photo['subject_type'], (int)$photo['subject_id'], false) === null) {
        http_response_code(404);
        exit;
    }
    $abs = __DIR__ . '/' . $photo['file_path'];
    if (!is_file($abs)) { http_response_code(404); exit; }

    $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($abs));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300');
    readfile($abs);
    exit;
}

inspectionJson(400, ['ok' => false, 'error' => 'Unknown action.']);
```

- [ ] **Step 2: Keep real uploads out of git**

Append to `wcma-calculator/.gitignore`:

```
uploads/*
!uploads/.htaccess
```

- [ ] **Step 3: Lint and confirm the tests still pass**

Run: `php -l inspection.php && php phpunit.phar`
Expected: `No syntax errors detected in inspection.php` and `OK` for the suite. (The endpoint itself is exercised end to end in Task 7.)

- [ ] **Step 4: Confirm uploads stay untracked**

Run (from repo root): `git status --short wcma-calculator/uploads`
Expected: no output (existing upload folders are ignored; `uploads/.htaccess` stays tracked).

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/inspection.php wcma-calculator/.gitignore
git commit -m "feat(pre-tech): add inspection photo upload/delete/serve endpoint"
```

---

### Task 6: Browser resize and upload modules

**Files:**
- Create: `wcma-calculator/js/photo-resize.js`
- Create: `wcma-calculator/js/photo-upload.js`
- Test: `wcma-calculator/tests/js/photo-resize.test.js`, `wcma-calculator/tests/js/photo-upload.test.js`

**Interfaces:**
- Consumes: the HTTP contract from Task 5.
- Produces (dual-mode: `module.exports` under Node, globals in the browser):
  - `WcmaPhotoResize.computeTargetSize(width: number, height: number, maxEdge: number): {width: number, height: number}` — scales down so the long edge is `maxEdge`; never scales up.
  - `WcmaPhotoResize.resizeToJpeg(file: File|Blob, options?: {maxEdge?: number, quality?: number}): Promise<Blob>` — defaults 1600 and 0.8; browser only.
  - `WcmaPhotoUpload.createClient({fetchFn, resizeFn, csrfToken, endpoint?}): {upload, remove}`
    - `upload({file, subjectType, subjectId, requirementKey, typed?}): Promise<photo>` — resizes, POSTs, resolves the `photo` object, rejects with `Error(serverMessage)`.
    - `remove({id}): Promise<void>`
  - `WcmaPhotoUpload.browserClient(csrfToken): {upload, remove}` — wires the real `fetch` and `WcmaPhotoResize.resizeToJpeg` (browser only).

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/js/photo-resize.test.js`:

```js
const test = require('node:test');
const assert = require('node:assert');
const { computeTargetSize } = require('../../js/photo-resize.js');

test('scales a landscape photo so the long edge is maxEdge', () => {
    assert.deepStrictEqual(computeTargetSize(4000, 3000, 1600), { width: 1600, height: 1200 });
});

test('scales a portrait photo so the long edge is maxEdge', () => {
    assert.deepStrictEqual(computeTargetSize(3000, 4000, 1600), { width: 1200, height: 1600 });
});

test('never scales up', () => {
    assert.deepStrictEqual(computeTargetSize(800, 600, 1600), { width: 800, height: 600 });
    assert.deepStrictEqual(computeTargetSize(1600, 900, 1600), { width: 1600, height: 900 });
});

test('rounds to whole pixels', () => {
    const r = computeTargetSize(4032, 3024, 1600);
    assert.deepStrictEqual(r, { width: 1600, height: 1200 });
    const odd = computeTargetSize(3001, 2000, 1600);
    assert.ok(Number.isInteger(odd.width) && Number.isInteger(odd.height));
});
```

Create `wcma-calculator/tests/js/photo-upload.test.js`:

```js
const test = require('node:test');
const assert = require('node:assert');
const { createClient } = require('../../js/photo-upload.js');

function makeClient(response) {
    const calls = [];
    const fetchFn = async (url, init) => { calls.push({ url, init }); return response; };
    const resizeFn = async (file) => new Blob(['resized:' + file.name]);
    return { calls, client: createClient({ fetchFn, resizeFn, csrfToken: 'tok123' }) };
}

const okResponse = (body) => ({ ok: true, json: async () => body });

test('upload resizes, posts the expected fields, and resolves the photo', async () => {
    const photo = { id: 9, requirement_key: 'harness_date', typed: { date: '05/2025' } };
    const { calls, client } = makeClient(okResponse({ ok: true, photo }));

    const result = await client.upload({
        file: { name: 'big.heic' }, subjectType: 'tech_sheet', subjectId: 7,
        requirementKey: 'harness_date', typed: { date: '05/2025' },
    });

    assert.deepStrictEqual(result, photo);
    assert.strictEqual(calls.length, 1);
    assert.strictEqual(calls[0].url, 'inspection.php?action=upload');
    assert.strictEqual(calls[0].init.method, 'POST');
    const body = calls[0].init.body;
    assert.strictEqual(body.get('csrf_token'), 'tok123');
    assert.strictEqual(body.get('subject_type'), 'tech_sheet');
    assert.strictEqual(body.get('subject_id'), '7');
    assert.strictEqual(body.get('requirement_key'), 'harness_date');
    assert.strictEqual(body.get('typed[date]'), '05/2025');
    const sent = body.get('photo');
    assert.strictEqual(sent.name, 'harness_date.jpg');
    assert.strictEqual(await sent.text(), 'resized:big.heic');
});

test('upload rejects with the server message', async () => {
    const { client } = makeClient({ ok: false, json: async () => ({ ok: false, error: 'The photo is too large (2 MB maximum).' }) });
    await assert.rejects(
        client.upload({ file: { name: 'x.jpg' }, subjectType: 'tech_sheet', subjectId: 1, requirementKey: 'front_34' }),
        { message: 'The photo is too large (2 MB maximum).' }
    );
});

test('upload rejects with a generic message on an unreadable response', async () => {
    const { client } = makeClient({ ok: false, json: async () => { throw new Error('bad json'); } });
    await assert.rejects(
        client.upload({ file: { name: 'x.jpg' }, subjectType: 'tech_sheet', subjectId: 1, requirementKey: 'front_34' }),
        { message: 'Upload failed. Please try again.' }
    );
});

test('remove posts the id and csrf token', async () => {
    const { calls, client } = makeClient(okResponse({ ok: true }));
    await client.remove({ id: 9 });
    assert.strictEqual(calls[0].url, 'inspection.php?action=delete');
    assert.strictEqual(calls[0].init.body.get('id'), '9');
    assert.strictEqual(calls[0].init.body.get('csrf_token'), 'tok123');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run (from `wcma-calculator/`): `node --test "tests/js/*.test.js"`
Expected: failures, `Cannot find module '../../js/photo-resize.js'`.

- [ ] **Step 3: Write `photo-resize.js`**

Create `wcma-calculator/js/photo-resize.js`:

```js
// wcma-calculator/js/photo-resize.js
// Shrinks a phone photo to a ~1600px JPEG in the browser before upload. Re-encoding
// through a canvas also strips EXIF/GPS data. Loadable as a classic script
// (window.WcmaPhotoResize) or via require() for tests.
(function (root) {
    'use strict';

    function computeTargetSize(width, height, maxEdge) {
        const longEdge = Math.max(width, height);
        if (longEdge <= maxEdge) return { width: width, height: height };
        const scale = maxEdge / longEdge;
        return { width: Math.round(width * scale), height: Math.round(height * scale) };
    }

    // Decode honouring EXIF orientation. createImageBitmap's option is unsupported
    // on older Safari, so fall back to an <img> (which applies orientation itself).
    async function decode(file) {
        try {
            const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
            return { source: bitmap, width: bitmap.width, height: bitmap.height, close: function () { bitmap.close(); } };
        } catch (e) {
            const url = URL.createObjectURL(file);
            try {
                const img = await new Promise(function (resolve, reject) {
                    const el = new Image();
                    el.onload = function () { resolve(el); };
                    el.onerror = function () { reject(new Error('Could not read that photo')); };
                    el.src = url;
                });
                return { source: img, width: img.naturalWidth, height: img.naturalHeight, close: function () {} };
            } finally {
                URL.revokeObjectURL(url);
            }
        }
    }

    async function resizeToJpeg(file, options) {
        const maxEdge = (options && options.maxEdge) || 1600;
        const quality = (options && options.quality) || 0.8;

        const decoded = await decode(file);
        const size = computeTargetSize(decoded.width, decoded.height, maxEdge);
        const canvas = document.createElement('canvas');
        canvas.width = size.width;
        canvas.height = size.height;
        canvas.getContext('2d').drawImage(decoded.source, 0, 0, size.width, size.height);
        decoded.close();

        return new Promise(function (resolve, reject) {
            canvas.toBlob(function (blob) {
                if (blob) resolve(blob); else reject(new Error('Could not prepare that photo'));
            }, 'image/jpeg', quality);
        });
    }

    const api = { computeTargetSize: computeTargetSize, resizeToJpeg: resizeToJpeg };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        root.WcmaPhotoResize = api;
    }
})(typeof window !== 'undefined' ? window : globalThis);
```

- [ ] **Step 4: Write `photo-upload.js`**

Create `wcma-calculator/js/photo-upload.js`:

```js
// wcma-calculator/js/photo-upload.js
// Uploads/deletes inspection photos via inspection.php. One photo per request so a
// weak connection loses at most one photo. fetch and resize are injectable for tests.
(function (root) {
    'use strict';

    const GENERIC_ERROR = 'Upload failed. Please try again.';

    function createClient(deps) {
        const fetchFn = deps.fetchFn;
        const resizeFn = deps.resizeFn;
        const csrfToken = deps.csrfToken;
        const endpoint = deps.endpoint || 'inspection.php';

        async function post(action, formData) {
            const res = await fetchFn(endpoint + '?action=' + action, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
            });
            let data;
            try {
                data = await res.json();
            } catch (e) {
                throw new Error(GENERIC_ERROR);
            }
            if (!res.ok || !data.ok) throw new Error(data.error || GENERIC_ERROR);
            return data;
        }

        async function upload(opts) {
            const blob = await resizeFn(opts.file);
            const form = new FormData();
            form.append('csrf_token', csrfToken);
            form.append('subject_type', opts.subjectType);
            form.append('subject_id', String(opts.subjectId));
            form.append('requirement_key', opts.requirementKey);
            const typed = opts.typed || {};
            Object.keys(typed).forEach(function (name) {
                form.append('typed[' + name + ']', typed[name]);
            });
            form.append('photo', blob, opts.requirementKey + '.jpg');
            return (await post('upload', form)).photo;
        }

        async function remove(opts) {
            const form = new FormData();
            form.append('csrf_token', csrfToken);
            form.append('id', String(opts.id));
            await post('delete', form);
        }

        return { upload: upload, remove: remove };
    }

    const api = {
        createClient: createClient,
        // Browser convenience: real fetch and the canvas resizer.
        browserClient: function (csrfToken) {
            return createClient({
                fetchFn: root.fetch.bind(root),
                resizeFn: root.WcmaPhotoResize.resizeToJpeg,
                csrfToken: csrfToken,
            });
        },
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        root.WcmaPhotoUpload = api;
    }
})(typeof window !== 'undefined' ? window : globalThis);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run (from `wcma-calculator/`): `node --test "tests/js/*.test.js"`
Expected: `# pass 8`, `# fail 0`.

- [ ] **Step 6: Commit**

```bash
git add wcma-calculator/js/photo-resize.js wcma-calculator/js/photo-upload.js wcma-calculator/tests/js/
git commit -m "feat(pre-tech): add browser photo resize and upload modules"
```

---

### Task 7: End-to-end verification

No product code changes; this proves the pieces work together in a real browser and against a real PHP server. The harness lives in `scratch/` (untracked) and is not committed.

**Files:**
- Create (scratch, untracked): `scratch/inspection-prepend.php`, `scratch/inspection-router.php`, `scratch/inspection-harness.php`, `scratch/inspection-e2e.js`

**Interfaces:**
- Consumes: everything above. Playwright is already installed at `scratch/tech-sheet-mockups/node_modules`.

- [ ] **Step 1: Create the harness files**

Create `scratch/inspection-prepend.php` (points every request at a throwaway database):

```php
<?php
if (!defined('DB_PATH')) {
    define('DB_PATH', 'C:/dev/wcmaclasscalc/scratch/inspection-e2e.db');
}
```

Create `scratch/inspection-router.php`:

```php
<?php
// Router for `php -S`: serves the harness page, everything else falls through to the app.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/harness') {
    require __DIR__ . '/inspection-harness.php';
    return true;
}
return false;
```

Create `scratch/inspection-harness.php`:

```php
<?php
// Seeds a user + sheet in the scratch DB (DB_PATH comes from inspection-prepend.php, so this
// page and inspection.php share it), signs in as that user, and renders a bare page that
// loads the photo modules. `?as=intruder` creates a second user to check isolation.
require 'C:/dev/wcmaclasscalc/wcma-calculator/session_bootstrap.php';
require 'C:/dev/wcmaclasscalc/wcma-calculator/db.php';
require 'C:/dev/wcmaclasscalc/wcma-calculator/view_helpers.php';

$pdo = db_connect();
db_init($pdo);

$who = $_GET['as'] ?? 'owner';
$email = $who . '@example.com';
$user = db_find_user_by_email($pdo, $email);
$userId = $user ? (int)$user['id'] : db_create_user($pdo, ['email' => $email, 'name' => ucfirst($who), 'password_hash' => 'x', 'google_id' => null]);

$sheetId = 0;
if ($who === 'owner') {
    $existing = $pdo->query("SELECT id FROM tech_sheets WHERE user_id = $userId LIMIT 1")->fetchColumn();
    if ($existing) {
        $sheetId = (int)$existing;
    } else {
        $subId = db_insert_submission($pdo, [
            ':submitted_at' => date('Y-m-d H:i:s'), ':name' => 'Owner', ':email' => $email, ':year' => '2020', ':make' => 'Mazda',
            ':model' => 'MX-5', ':comments' => null, ':competition_weight' => 2200, ':declared_hp' => 150, ':dyno_hp' => null,
            ':chassis_display' => null, ':body_mods_display' => null, ':transmission_display' => null, ':drivetrain_display' => null,
            ':tires_display' => null, ':brake_suspension' => null, ':chassis_value' => 0, ':body_mods_value' => 0,
            ':transmission_value' => 0, ':drivetrain_value' => 0, ':tires_value' => 0, ':brake_suspension_value' => 0,
            ':weight_factor' => 0, ':modification_factor' => 0, ':base_ratio' => 14.67, ':modified_ratio' => 14.67,
            ':calculated_class' => 'IT1', ':user_id' => $userId,
        ]);
        $eventId = db_create_event($pdo, 'E2E Event', '2026-05-10', null);
        $sheetId = db_insert_tech_sheet($pdo, [
            'submission_id' => $subId, 'user_id' => $userId, 'event_id' => $eventId, 'sheet_type' => 'standard',
            'entrant_name' => 'Owner', 'driver_name' => 'Owner', 'car_make' => 'Mazda', 'car_model' => 'MX-5',
            'car_colour' => 'Red', 'car_number' => '42', 'class' => 'IT1', 'engine_cc' => '1800', 'engine_hp' => '150',
            'car_weight' => 2200, 'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1,
        ]);
    }
}

$_SESSION['user_id'] = $userId;
$_SESSION['user_name'] = ucfirst($who);
$_SESSION['user_role'] = 'user';
$csrf = generateCsrfToken();
?>
<!doctype html>
<meta charset="utf-8">
<title>inspection harness</title>
<script src="js/photo-resize.js"></script>
<script src="js/photo-upload.js"></script>
<script>
    window.HARNESS = { sheetId: <?= (int)$sheetId ?>, csrf: <?= json_encode($csrf) ?> };
</script>
<p>harness ready: <?= h($who) ?></p>
```

Create `scratch/inspection-e2e.js`:

```js
const { chromium } = require('C:/dev/wcmaclasscalc/scratch/tech-sheet-mockups/node_modules/playwright');
const assert = require('node:assert');

const BASE = 'http://localhost:8123';

(async () => {
    const browser = await chromium.launch();

    // ── Owner: upload a big photo ────────────────────────────────────────────
    const owner = await (await browser.newContext()).newPage();
    await owner.goto(BASE + '/harness?as=owner');
    await owner.waitForSelector('text=harness ready');

    const upload = await owner.evaluate(async () => {
        const canvas = document.createElement('canvas');
        canvas.width = 4000; canvas.height = 3000;
        const ctx = canvas.getContext('2d');
        for (let i = 0; i < 400; i++) { ctx.fillStyle = 'hsl(' + (i * 7 % 360) + ',70%,50%)'; ctx.fillRect(i * 10, (i * 37) % 3000, 120, 90); }
        const original = await new Promise(r => canvas.toBlob(r, 'image/jpeg', 0.95));
        const file = new File([original], 'IMG_0001.jpg', { type: 'image/jpeg' });

        const client = WcmaPhotoUpload.browserClient(HARNESS.csrf);
        const photo = await client.upload({
            file, subjectType: 'tech_sheet', subjectId: HARNESS.sheetId,
            requirementKey: 'harness_date', typed: { date: '05/2025' },
        });
        const res = await fetch(photo.url, { credentials: 'same-origin' });
        const blob = await res.blob();
        const bitmap = await createImageBitmap(blob);
        return { originalBytes: original.size, photo, status: res.status, type: res.headers.get('content-type'), servedBytes: blob.size, w: bitmap.width, h: bitmap.height };
    });

    console.log('upload result:', JSON.stringify(upload));
    assert.strictEqual(upload.status, 200);
    assert.strictEqual(upload.type, 'image/jpeg');
    assert.ok(Math.max(upload.w, upload.h) <= 1600, 'served photo is at most 1600px');
    assert.ok(upload.servedBytes < upload.originalBytes, 'served photo is smaller than the original');
    assert.ok(upload.servedBytes < 2 * 1024 * 1024, 'served photo is under the server cap');
    assert.deepStrictEqual(upload.photo.typed, { date: '05/2025' });
    assert.ok(!('file_path' in upload.photo), 'file path is not exposed');

    // ── Bad input is rejected with a readable message ────────────────────────
    const bad = await owner.evaluate(async () => {
        const client = WcmaPhotoUpload.createClient({
            fetchFn: window.fetch.bind(window),
            resizeFn: async (f) => f,   // skip resizing so the server sees the raw text
            csrfToken: HARNESS.csrf,
        });
        const out = {};
        try { await client.upload({ file: new Blob(['not an image']), subjectType: 'tech_sheet', subjectId: HARNESS.sheetId, requirementKey: 'front_34' }); out.notImage = 'accepted (BAD)'; }
        catch (e) { out.notImage = e.message; }
        try { await client.upload({ file: new Blob(['x']), subjectType: 'tech_sheet', subjectId: HARNESS.sheetId, requirementKey: 'nope' }); out.badKey = 'accepted (BAD)'; }
        catch (e) { out.badKey = e.message; }
        const noCsrf = WcmaPhotoUpload.createClient({ fetchFn: window.fetch.bind(window), resizeFn: async (f) => f, csrfToken: 'wrong' });
        try { await noCsrf.upload({ file: new Blob(['x']), subjectType: 'tech_sheet', subjectId: HARNESS.sheetId, requirementKey: 'front_34' }); out.csrf = 'accepted (BAD)'; }
        catch (e) { out.csrf = e.message; }
        return out;
    });
    console.log('bad input:', JSON.stringify(bad));
    assert.match(bad.notImage, /not a photo/i);
    assert.match(bad.badKey, /unknown photo type/i);
    assert.match(bad.csrf, /csrf/i);

    // ── Another signed-in user cannot read or delete the owner's photo ──────
    const other = await (await browser.newContext()).newPage();
    await other.goto(BASE + '/harness?as=intruder');
    await other.waitForSelector('text=harness ready');
    const isolation = await other.evaluate(async ({ photoUrl, photoId }) => {
        const read = await fetch(photoUrl, { credentials: 'same-origin' });
        const form = new FormData();
        form.append('csrf_token', HARNESS.csrf); form.append('id', String(photoId));
        const del = await fetch('inspection.php?action=delete', { method: 'POST', body: form, credentials: 'same-origin' });
        return { read: read.status, del: del.status };
    }, { photoUrl: upload.photo.url, photoId: upload.photo.id });
    console.log('isolation:', JSON.stringify(isolation));
    assert.strictEqual(isolation.read, 404);
    assert.strictEqual(isolation.del, 404);

    // ── Signed-out browser cannot read the photo ─────────────────────────────
    const anon = await (await browser.newContext()).newPage();
    const anonRes = await anon.request.get(BASE + '/' + upload.photo.url);
    console.log('anonymous:', anonRes.status());
    assert.strictEqual(anonRes.status(), 401);

    // ── Owner can delete their photo ─────────────────────────────────────────
    const removed = await owner.evaluate(async (photo) => {
        await WcmaPhotoUpload.browserClient(HARNESS.csrf).remove({ id: photo.id });
        return (await fetch(photo.url, { credentials: 'same-origin' })).status;
    }, upload.photo);
    console.log('after delete:', removed);
    assert.strictEqual(removed, 404);

    await browser.close();
    console.log('E2E OK');
})().catch(e => { console.error(e); process.exit(1); });
```

- [ ] **Step 2: Start the PHP server**

Run (from repo root, in the background): `php -d auto_prepend_file=scratch/inspection-prepend.php -S localhost:8123 -t wcma-calculator scratch/inspection-router.php`
Expected: `Development Server (http://localhost:8123) started`.

- [ ] **Step 3: Run the end-to-end script**

Run: `node scratch/inspection-e2e.js`
Expected output ends with `E2E OK`, with the earlier lines showing `status: 200`, a served photo width/height at or under 1600, `isolation: {"read":404,"del":404}`, `anonymous: 401`, and `after delete: 404`.

- [ ] **Step 4: Check the stored file and clean up**

Run: `ls wcma-calculator/uploads/inspection/tech_sheet/` then stop the PHP server. Delete the scratch data with `rm -rf wcma-calculator/uploads/inspection scratch/inspection-e2e.db*`.
Expected: `ls` shows one numbered folder before cleanup; `git status --short` shows no new tracked or untracked files under `wcma-calculator/` (uploads are ignored; scratch is untracked as before).

- [ ] **Step 5: Full regression**

Run (from `wcma-calculator/`): `php phpunit.phar && node --test "tests/js/*.test.js"`
Expected: PHPUnit `OK`, Node `# fail 0`. Nothing to commit for this task.

**Post-deploy manual check (cannot be done locally, because `php -S` ignores `.htaccess`):** after deploying, confirm that requesting `/uploads/inspection/tech_sheet/<id>/<key>.jpg` directly returns 403, and that a real phone photo (several MB) uploads through the harness-style call. Note IONOS's `upload_max_filesize` and `post_max_size`; the client resize keeps requests around 300-600 KB, well under any default.

---

## Self-Review Notes

- **Spec coverage (phase 1 scope):** requirements list with tiers, typed fields, versioning, helmet-back-recommended (Task 2); `inspection_photos` table and one-photo-per-requirement replace semantics (Task 3); validation, protected storage path, owner/admin-only serving, teched lock (Tasks 4-5); browser resize to ~1600px JPEG and one-photo-per-request upload (Task 6); wording rename and removal of per-item tech approval (Task 1). Deliberately deferred to later phases per the spec's phasing: the sheet-form UI, `photo_status`/`accepted_via` columns, conditional `applies = 0` rows and toggle, car identity, review queue, gear records, emails.
- **Spec refinement:** `typed_value` is stored as a JSON object (e.g. `{"standard":"SA2020","date":"03/2024"}`) so a photo can carry several typed fields; the spec's TEXT column holds it unchanged.
- **Type consistency:** `photoRequirementByKey()` returns the entry plus `key`; `inspectionSavePhoto()` returns the row from `db_get_inspection_photos()[$key]`, which `inspectionPublicPhoto()` consumes; the JS client's `photo` object is exactly `inspectionPublicPhoto()`'s output.
